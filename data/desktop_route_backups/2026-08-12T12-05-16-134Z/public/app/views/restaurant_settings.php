<?php
session_start();

if (file_exists(__DIR__ . '/partials/performance.php')) {
    include(__DIR__ . '/partials/performance.php');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================= DB CONNECTION FIX ================= */
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check config/db.php and make sure it creates mysqli variable named \$conn.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$session_branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['selected_branch_id'] ?? 0));

if ($client_id <= 0 && $role_id !== 1) {
    die('Client not found in session.');
}

/* ================= HELPERS ================= */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function add_column_if_missing(mysqli $conn, string $table, string $column, string $definition): void
{
    if (!table_exists($conn, $table)) {
        return;
    }
    if (!column_exists($conn, $table, $column)) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function bind_params_ref(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $refs = [$types];
    foreach ($params as $k => &$v) {
        $refs[] = &$v;
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fetch_all_prepared(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        bind_params_ref($stmt, $types, $params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function ensure_restaurant_settings_schema(mysqli $conn): void
{
    if (!table_exists($conn, 'restaurant_settings')) {
        $conn->query("CREATE TABLE restaurant_settings (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_restaurant_setting (client_id, branch_id, setting_key),
            KEY idx_rs_client_branch (client_id, branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        return;
    }

    add_column_if_missing($conn, 'restaurant_settings', 'client_id', 'INT(11) NOT NULL DEFAULT 0 AFTER id');
    add_column_if_missing($conn, 'restaurant_settings', 'branch_id', 'INT(11) NOT NULL DEFAULT 0 AFTER client_id');
    add_column_if_missing($conn, 'restaurant_settings', 'setting_key', 'VARCHAR(100) NOT NULL AFTER branch_id');
    add_column_if_missing($conn, 'restaurant_settings', 'setting_value', 'TEXT DEFAULT NULL AFTER setting_key');
    add_column_if_missing($conn, 'restaurant_settings', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    add_column_if_missing($conn, 'restaurant_settings', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
}

function log_restaurant_activity(mysqli $conn, int $client_id, int $branch_id, ?int $session_id, ?int $table_id, string $type, string $details, int $user_id): void
{
    if (!table_exists($conn, 'restaurant_activity_logs')) {
        return;
    }
    $stmt = $conn->prepare("INSERT INTO restaurant_activity_logs (client_id, branch_id, session_id, table_id, action_type, details, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param('iiiissi', $client_id, $branch_id, $session_id, $table_id, $type, $details, $user_id);
    $stmt->execute();
    $stmt->close();
}

function client_default_tax(mysqli $conn, int $client_id): float
{
    if ($client_id <= 0) return 0.0;

    if (table_exists($conn, 'client_settings') && column_exists($conn, 'client_settings', 'tax_percentage')) {
        $stmt = $conn->prepare("SELECT tax_percentage FROM client_settings WHERE client_id=? LIMIT 1");
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && is_numeric($row['tax_percentage'])) {
            return (float)$row['tax_percentage'];
        }
    }

    if (table_exists($conn, 'taxes')) {
        $statusSql = column_exists($conn, 'taxes', 'status') ? " AND status='active'" : '';
        $rateCol = column_exists($conn, 'taxes', 'rate') ? 'rate' : (column_exists($conn, 'taxes', 'percentage') ? 'percentage' : '0');
        $stmt = $conn->prepare("SELECT COALESCE(MAX($rateCol),0) AS rate FROM taxes WHERE client_id=? $statusSql");
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (float)($row['rate'] ?? 0);
    }

    return 0.0;
}

function setting_definitions(float $clientTax = 0.0): array
{
    return [
        'restaurant_name' => [
            'label' => 'Restaurant Name', 'type' => 'text', 'default' => '', 'section' => 'general',
            'help' => 'Restaurant name shown on the bill and KOT header.'
        ],
        'currency_symbol' => [
            'label' => 'Currency Symbol', 'type' => 'text', 'default' => 'Rs.', 'section' => 'general',
            'help' => 'Currency label shown on bills and totals.'
        ],
        'default_order_type' => [
            'label' => 'Default Order Type', 'type' => 'select', 'default' => 'dine_in', 'section' => 'general',
            'options' => ['dine_in' => 'Dine In', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery'],
            'help' => 'Default type used when starting a new order or session.'
        ],
        'default_guest_count' => [
            'label' => 'Default Guest Count', 'type' => 'number', 'default' => '1', 'section' => 'general', 'min' => 1, 'max' => 999,
            'help' => 'Default guest count for the dine-in table start popup.'
        ],
        'tax_percentage' => [
            'label' => 'Default Tax %', 'type' => 'decimal', 'default' => (string)$clientTax, 'section' => 'charges', 'min' => 0, 'max' => 100,
            'aliases' => ['default_tax_percent', 'default_tax_percentage'],
            'help' => 'Used for restaurant cart, bill, and payment calculations.'
        ],
        'service_charge_percentage' => [
            'label' => 'Service Charge %', 'type' => 'decimal', 'default' => '0', 'section' => 'charges', 'min' => 0, 'max' => 100,
            'aliases' => ['default_service_charge_percent', 'service_percentage'],
            'help' => 'Service charge is calculated on subtotal after discount.'
        ],
        'allow_discount' => [
            'label' => 'Allow Discount On Bill', 'type' => 'toggle', 'default' => '1', 'section' => 'charges',
            'help' => 'Allow discount on the cashier and payment pages.'
        ],
        'rounding_mode' => [
            'label' => 'Rounding Mode', 'type' => 'select', 'default' => 'none', 'section' => 'charges',
            'options' => ['none' => 'No Rounding', 'nearest' => 'Nearest Rupee', 'up' => 'Round Up', 'down' => 'Round Down'],
            'help' => 'Saved for future round-off calculation logic.'
        ],
        'kot_no_prefix' => [
            'label' => 'KOT No Prefix', 'type' => 'text', 'default' => 'KOT', 'section' => 'printing',
            'help' => 'Kitchen ticket number prefix.'
        ],
        'kot_print_mode' => [
            'label' => 'KOT Print Mode', 'type' => 'select', 'default' => 'browser', 'section' => 'printing',
            'options' => ['browser' => 'Browser Print', 'thermal' => 'Thermal Printer', 'display' => 'Kitchen Display Only', 'both' => 'Print + Kitchen Display'],
            'help' => 'Print or display behavior after KOT is sent.'
        ],
        'auto_print_kot' => [
            'label' => 'Auto Print KOT', 'type' => 'toggle', 'default' => '0', 'section' => 'printing',
            'help' => 'Open print view automatically after KOT is sent.'
        ],
        'kot_copies' => [
            'label' => 'KOT Copies', 'type' => 'number', 'default' => '1', 'section' => 'printing', 'min' => 1, 'max' => 10,
            'help' => 'Kitchen KOT copies count.'
        ],
        'bill_no_prefix' => [
            'label' => 'Bill / Invoice Prefix', 'type' => 'text', 'default' => 'INV', 'section' => 'printing',
            'help' => 'Restaurant invoice prefix for future invoice numbering.'
        ],
        'bill_print_format' => [
            'label' => 'Bill Print Format', 'type' => 'select', 'default' => 'thermal', 'section' => 'printing',
            'options' => ['thermal' => 'Thermal 80mm', 'a4' => 'A4 Invoice', 'compact' => 'Compact Bill'],
            'help' => 'Customer bill print layout.'
        ],
        'bill_copies' => [
            'label' => 'Bill Copies', 'type' => 'number', 'default' => '1', 'section' => 'printing', 'min' => 1, 'max' => 10,
            'help' => 'Customer bill copies count.'
        ],
        'show_kitchen_notes' => [
            'label' => 'Show Notes On KOT', 'type' => 'toggle', 'default' => '1', 'section' => 'printing',
            'help' => 'Show item special notes on the kitchen ticket.'
        ],
        'table_after_payment_status' => [
            'label' => 'Table Status After Payment', 'type' => 'select', 'default' => 'Available', 'section' => 'rules',
            'options' => ['Available' => 'Available', 'Dirty' => 'Dirty / Cleaning'],
            'help' => 'Choose whether the table becomes Available or Dirty after bill payment.'
        ],
        'enable_table_cleaning' => [
            'label' => 'Enable Dirty/Cleaning Flow', 'type' => 'toggle', 'default' => '1', 'section' => 'rules',
            'help' => 'Enable the flow to mark tables as Dirty after payment.'
        ],
        'allow_item_cancel_after_kot' => [
            'label' => 'Allow Cancel After KOT', 'type' => 'toggle', 'default' => '1', 'section' => 'rules',
            'help' => 'Allow KOT-sent items to be cancelled with a reason.'
        ],
        'require_cancel_reason' => [
            'label' => 'Require Cancel Reason', 'type' => 'toggle', 'default' => '1', 'section' => 'rules',
            'help' => 'Require a reason when cancelling an item or session.'
        ],
        'enable_table_transfer' => [
            'label' => 'Enable Table Transfer', 'type' => 'toggle', 'default' => '1', 'section' => 'rules',
            'help' => 'Allow running orders to be transferred from one table to another.'
        ],
        'enable_table_merge' => [
            'label' => 'Enable Table Merge', 'type' => 'toggle', 'default' => '1', 'section' => 'rules',
            'help' => 'Allow multiple tables to be merged into the same bill or session.'
        ],
        'enable_split_bill' => [
            'label' => 'Enable Split Bill', 'type' => 'toggle', 'default' => '1', 'section' => 'rules',
            'help' => 'Allow bills to be split by items or amount.'
        ],
        'enable_takeaway_without_table' => [
            'label' => 'Takeaway Without Table', 'type' => 'toggle', 'default' => '1', 'section' => 'rules',
            'help' => 'Allow takeaway orders without requiring a physical table.'
        ],
        'receipt_footer_note' => [
            'label' => 'Receipt Footer Note', 'type' => 'textarea', 'default' => 'Thank you for dining with us!', 'section' => 'receipt',
            'help' => 'Footer message printed at the end of the customer bill.'
        ],
        'kot_footer_note' => [
            'label' => 'KOT Footer Note', 'type' => 'textarea', 'default' => '', 'section' => 'receipt',
            'help' => 'Optional note printed at the end of the kitchen ticket.'
        ],
        'bill_terms' => [
            'label' => 'Bill Terms / Policy', 'type' => 'textarea', 'default' => '', 'section' => 'receipt',
            'help' => 'Return, refund, or service policy printed on the bill.'
        ],
    ];
}

function get_setting_row(mysqli $conn, int $client_id, int $branch_id, string $key): ?array
{
    $stmt = $conn->prepare("SELECT id, setting_value FROM restaurant_settings WHERE client_id=? AND branch_id=? AND setting_key=? LIMIT 1");
    $stmt->bind_param('iis', $client_id, $branch_id, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function upsert_setting(mysqli $conn, int $client_id, int $branch_id, string $key, string $value): void
{
    $existing = get_setting_row($conn, $client_id, $branch_id, $key);
    if ($existing) {
        $id = (int)$existing['id'];
        $stmt = $conn->prepare("UPDATE restaurant_settings SET setting_value=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param('si', $value, $id);
        $stmt->execute();
        $stmt->close();
        return;
    }
    $stmt = $conn->prepare("INSERT INTO restaurant_settings (client_id, branch_id, setting_key, setting_value, created_at) VALUES (?,?,?,?,NOW())");
    $stmt->bind_param('iiss', $client_id, $branch_id, $key, $value);
    $stmt->execute();
    $stmt->close();
}

function delete_settings_scope(mysqli $conn, int $client_id, int $branch_id): void
{
    $stmt = $conn->prepare("DELETE FROM restaurant_settings WHERE client_id=? AND branch_id=?");
    $stmt->bind_param('ii', $client_id, $branch_id);
    $stmt->execute();
    $stmt->close();
}

function clean_setting_value(array $def, $raw): string
{
    $type = (string)($def['type'] ?? 'text');

    if ($type === 'toggle') {
        return !empty($raw) ? '1' : '0';
    }

    if ($type === 'number') {
        $value = (int)$raw;
        if (isset($def['min'])) $value = max((int)$def['min'], $value);
        if (isset($def['max'])) $value = min((int)$def['max'], $value);
        return (string)$value;
    }

    if ($type === 'decimal') {
        $value = round((float)$raw, 2);
        if (isset($def['min'])) $value = max((float)$def['min'], $value);
        if (isset($def['max'])) $value = min((float)$def['max'], $value);
        return number_format($value, 2, '.', '');
    }

    if ($type === 'select') {
        $value = trim((string)$raw);
        $options = $def['options'] ?? [];
        if (!array_key_exists($value, $options)) {
            return (string)($def['default'] ?? '');
        }
        return $value;
    }

    $value = trim((string)$raw);
    if (strlen($value) > 5000) {
        $value = substr($value, 0, 5000);
    }
    return $value;
}

function load_effective_settings(mysqli $conn, int $client_id, int $branch_id, array $defs): array
{
    $values = [];
    $sources = [];
    foreach ($defs as $key => $def) {
        $values[$key] = (string)($def['default'] ?? '');
        $sources[$key] = 'default';
    }

    if (!table_exists($conn, 'restaurant_settings')) {
        return [$values, $sources];
    }

    $allowedBranches = $branch_id > 0 ? [0, $branch_id] : [0];
    $placeholders = implode(',', array_fill(0, count($allowedBranches), '?'));
    $types = 'i' . str_repeat('i', count($allowedBranches));
    $params = array_merge([$client_id], $allowedBranches);

    $rows = fetch_all_prepared(
        $conn,
        "SELECT branch_id, setting_key, setting_value FROM restaurant_settings WHERE client_id=? AND branch_id IN ($placeholders) ORDER BY branch_id ASC, id ASC",
        $types,
        $params
    );

    foreach ($rows as $row) {
        $key = (string)($row['setting_key'] ?? '');
        $rowBranch = (int)($row['branch_id'] ?? 0);

        foreach ($defs as $mainKey => $def) {
            $keys = array_merge([$mainKey], $def['aliases'] ?? []);
            if (in_array($key, $keys, true)) {
                $values[$mainKey] = (string)($row['setting_value'] ?? '');
                $sources[$mainKey] = $rowBranch > 0 ? 'branch' : 'global';
                break;
            }
        }
    }

    return [$values, $sources];
}

function initialize_global_defaults(mysqli $conn, int $client_id, array $defs): void
{
    if ($client_id <= 0) return;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM restaurant_settings WHERE client_id=? AND branch_id=0");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($count > 0) return;

    foreach ($defs as $key => $def) {
        $value = clean_setting_value($def, $def['default'] ?? '');
        upsert_setting($conn, $client_id, 0, $key, $value);
        foreach (($def['aliases'] ?? []) as $alias) {
            upsert_setting($conn, $client_id, 0, $alias, $value);
        }
    }
}

ensure_restaurant_settings_schema($conn);
$clientTax = client_default_tax($conn, $client_id);
$settingsDefs = setting_definitions($clientTax);
initialize_global_defaults($conn, $client_id, $settingsDefs);

/* ================= FETCH BRANCHES ================= */
$branches = [];
if (table_exists($conn, 'branches')) {
    if (column_exists($conn, 'branches', 'name')) {
        $branches = fetch_all_prepared($conn, "SELECT id, name FROM branches WHERE client_id=? ORDER BY name ASC", 'i', [$client_id]);
    } elseif (column_exists($conn, 'branches', 'branch_name')) {
        $branches = fetch_all_prepared($conn, "SELECT id, branch_name AS name FROM branches WHERE client_id=? ORDER BY branch_name ASC", 'i', [$client_id]);
    }
}

$selected_branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : $session_branch_id;
if (isset($_POST['branch_id'])) {
    $selected_branch_id = (int)$_POST['branch_id'];
}
if ($selected_branch_id < 0) $selected_branch_id = 0;

$validBranchIds = [0];
foreach ($branches as $b) $validBranchIds[] = (int)$b['id'];
if (!in_array($selected_branch_id, $validBranchIds, true)) {
    $selected_branch_id = 0;
}

/* ================= SAVE / RESET / COPY ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_settings'])) {
            $postSettings = $_POST['settings'] ?? [];
            if (!is_array($postSettings)) $postSettings = [];

            $conn->begin_transaction();
            foreach ($settingsDefs as $key => $def) {
                $raw = $def['type'] === 'toggle' ? (isset($postSettings[$key]) ? '1' : '0') : ($postSettings[$key] ?? ($def['default'] ?? ''));
                $value = clean_setting_value($def, $raw);
                upsert_setting($conn, $client_id, $selected_branch_id, $key, $value);
                foreach (($def['aliases'] ?? []) as $alias) {
                    upsert_setting($conn, $client_id, $selected_branch_id, $alias, $value);
                }
            }
            log_restaurant_activity($conn, $client_id, $selected_branch_id, null, null, 'settings_updated', 'Restaurant settings updated for branch scope ' . $selected_branch_id, $user_id);
            $conn->commit();
            $_SESSION['success'] = 'Restaurant settings saved successfully.';
        }

        if (isset($_POST['reset_settings'])) {
            $conn->begin_transaction();
            delete_settings_scope($conn, $client_id, $selected_branch_id);
            if ($selected_branch_id === 0) {
                foreach ($settingsDefs as $key => $def) {
                    $value = clean_setting_value($def, $def['default'] ?? '');
                    upsert_setting($conn, $client_id, 0, $key, $value);
                    foreach (($def['aliases'] ?? []) as $alias) {
                        upsert_setting($conn, $client_id, 0, $alias, $value);
                    }
                }
            }
            log_restaurant_activity($conn, $client_id, $selected_branch_id, null, null, 'settings_reset', 'Restaurant settings reset for branch scope ' . $selected_branch_id, $user_id);
            $conn->commit();
            $_SESSION['success'] = $selected_branch_id === 0 ? 'Global settings reset to defaults.' : 'Branch overrides removed. Global settings will apply now.';
        }

        if (isset($_POST['copy_global']) && $selected_branch_id > 0) {
            $globalRows = fetch_all_prepared($conn, "SELECT setting_key, setting_value FROM restaurant_settings WHERE client_id=? AND branch_id=0", 'i', [$client_id]);
            $conn->begin_transaction();
            foreach ($globalRows as $row) {
                upsert_setting($conn, $client_id, $selected_branch_id, (string)$row['setting_key'], (string)($row['setting_value'] ?? ''));
            }
            log_restaurant_activity($conn, $client_id, $selected_branch_id, null, null, 'settings_copied', 'Global restaurant settings copied to branch ' . $selected_branch_id, $user_id);
            $conn->commit();
            $_SESSION['success'] = 'Global settings copied to selected branch.';
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $rollbackError) {}
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: restaurant_settings.php?branch_id=' . $selected_branch_id);
    exit;
}

[$settings, $sources] = load_effective_settings($conn, $client_id, $selected_branch_id, $settingsDefs);

$sectionLabels = [
    'general' => ['title' => 'General Settings', 'icon' => 'bi-sliders', 'desc' => 'Restaurant identity, default order type and base behavior.'],
    'charges' => ['title' => 'Tax & Service Charges', 'icon' => 'bi-percent', 'desc' => 'Bill calculation, default tax and service charge rules.'],
    'printing' => ['title' => 'KOT / Bill Printing', 'icon' => 'bi-printer', 'desc' => 'Kitchen ticket, bill format and print behavior.'],
    'rules' => ['title' => 'Table & Order Rules', 'icon' => 'bi-shield-check', 'desc' => 'Table cleaning, transfer, merge and split bill controls.'],
    'receipt' => ['title' => 'Receipt Text', 'icon' => 'bi-receipt', 'desc' => 'Footer notes and terms printed on customer bill or KOT.'],
];

$settingsBySection = [];
foreach ($settingsDefs as $key => $def) {
    $settingsBySection[$def['section'] ?? 'general'][$key] = $def;
}

$scopeLabel = 'Global Defaults';
foreach ($branches as $b) {
    if ((int)$b['id'] === $selected_branch_id) {
        $scopeLabel = (string)$b['name'];
        break;
    }
}

function render_setting_input(string $key, array $def, array $settings, array $sources, int $selected_branch_id): string
{
    $value = $settings[$key] ?? (string)($def['default'] ?? '');
    $type = (string)($def['type'] ?? 'text');
    $source = $sources[$key] ?? 'default';
    $originBadge = '';
    if ($selected_branch_id > 0) {
        $originText = $source === 'branch' ? 'Branch Override' : ($source === 'global' ? 'Using Global' : 'Default');
        $originClass = $source === 'branch' ? 'source-branch' : 'source-global';
        $originBadge = '<span class="source-badge '.$originClass.'">'.e($originText).'</span>';
    }

    $html = '<div class="setting-item">';
    $html .= '<div class="setting-label-row"><label for="set_'.e($key).'">'.e($def['label'] ?? $key).'</label>'.$originBadge.'</div>';

    if ($type === 'select') {
        $html .= '<select id="set_'.e($key).'" name="settings['.e($key).']" class="form-select">';
        foreach (($def['options'] ?? []) as $optValue => $optLabel) {
            $selected = ((string)$value === (string)$optValue) ? 'selected' : '';
            $html .= '<option value="'.e($optValue).'" '.$selected.'>'.e($optLabel).'</option>';
        }
        $html .= '</select>';
    } elseif ($type === 'textarea') {
        $html .= '<textarea id="set_'.e($key).'" name="settings['.e($key).']" class="form-control" rows="3">'.e($value).'</textarea>';
    } elseif ($type === 'toggle') {
        $checked = ((string)$value === '1' || strtolower((string)$value) === 'yes' || strtolower((string)$value) === 'true') ? 'checked' : '';
        $html .= '<div class="toggle-wrap"><input type="checkbox" id="set_'.e($key).'" name="settings['.e($key).']" value="1" '.$checked.'><span></span><strong>'.($checked ? 'Enabled' : 'Disabled').'</strong></div>';
    } elseif ($type === 'number' || $type === 'decimal') {
        $step = $type === 'decimal' ? '0.01' : '1';
        $min = isset($def['min']) ? ' min="'.e($def['min']).'"' : '';
        $max = isset($def['max']) ? ' max="'.e($def['max']).'"' : '';
        $html .= '<input type="number" step="'.$step.'"'.$min.$max.' id="set_'.e($key).'" name="settings['.e($key).']" class="form-control" value="'.e($value).'">';
    } else {
        $html .= '<input type="text" id="set_'.e($key).'" name="settings['.e($key).']" class="form-control" value="'.e($value).'">';
    }

    if (!empty($def['help'])) {
        $html .= '<div class="help-text">'.e($def['help']).'</div>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Restaurant Settings - OneERP</title>
    <?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{
            --rs-bg:#050711;
            --rs-panel:#0a0f22;
            --rs-card:rgba(15,23,42,.76);
            --rs-border:rgba(167,139,250,.30);
            --rs-text:#f8fafc;
            --rs-muted:#aeb8d5;
            --rs-purple:#7c3aed;
            --rs-purple2:#6d4cff;
            --rs-green:#10b981;
            --rs-orange:#f59e0b;
            --rs-blue:#2563eb;
            --rs-red:#ef4444;
            --pos-sidebar-width:270px;
        }
        *{box-sizing:border-box}
        html,body{margin:0!important;min-height:100%!important;background:#050711!important;color:#f8fafc!important;overflow-x:hidden!important;font-family:Inter,"Segoe UI",Arial,sans-serif!important}
        body.restaurant-settings-theme{background:radial-gradient(circle at 12% 5%, rgba(124,58,237,.42), transparent 30%),radial-gradient(circle at 88% 4%, rgba(14,165,233,.16), transparent 28%),linear-gradient(135deg,#050711 0%,#080b18 38%,#140b2f 70%,#050711 100%)!important}
        body.restaurant-settings-theme a{text-decoration:none!important}
        body.restaurant-settings-theme .container-scroll{display:flex!important;width:100%!important;min-height:100vh!important;background:radial-gradient(circle at 0% 0%,rgba(124,58,237,.34),transparent 28%),radial-gradient(circle at 88% 6%,rgba(14,165,233,.16),transparent 30%),radial-gradient(circle at 45% 105%,rgba(109,76,255,.16),transparent 35%),linear-gradient(135deg,#050711 0%,#080b18 38%,#150d33 70%,#050711 100%)!important}
        body.restaurant-settings-theme .main-panel{flex:1 1 auto!important;width:100%!important;min-width:0!important;margin-left:0!important;padding-top:76px!important;background:transparent!important}
        body.restaurant-settings-theme .topbar-wrap{position:fixed!important;top:0!important;left:0!important;right:0!important;width:100%!important;height:76px!important;z-index:100050!important;background:rgba(5,8,18,.96)!important;border-bottom:1px solid rgba(148,163,184,.16)!important;box-shadow:0 18px 45px rgba(0,0,0,.36)!important;backdrop-filter:blur(18px) saturate(150%)!important;-webkit-backdrop-filter:blur(18px) saturate(150%)!important}
        body.restaurant-settings-theme .content-wrapper{position:relative!important;isolation:isolate!important;min-height:calc(100vh - 76px)!important;padding:28px 26px 42px!important;background:linear-gradient(90deg,rgba(255,255,255,.024) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.020) 1px,transparent 1px),radial-gradient(circle at 4% 0%,rgba(124,58,237,.32),transparent 31%),radial-gradient(circle at 82% 0%,rgba(14,165,233,.11),transparent 32%),linear-gradient(135deg,#070a17 0%,#0a0e21 38%,#160d33 74%,#060912 100%)!important;background-size:46px 46px,46px 46px,auto,auto,auto!important}
        body.restaurant-settings-theme .content-wrapper:before{content:"";position:absolute;inset:0;z-index:-1;pointer-events:none;background:linear-gradient(180deg,rgba(255,255,255,.065),transparent 18%),radial-gradient(circle at 18% 6%,rgba(139,92,246,.22),transparent 24%),radial-gradient(circle at 95% 22%,rgba(45,212,191,.10),transparent 28%)}
        .page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:22px}.page-title{margin:0;color:#fff;font-size:30px;line-height:1.12;font-weight:950;letter-spacing:-.045em;text-shadow:0 0 28px rgba(124,58,237,.68),0 8px 26px rgba(0,0,0,.35)}.page-subtitle{margin-top:6px;color:#b9c2da;font-weight:780}.head-actions{display:flex;gap:10px;flex-wrap:wrap}.btn-main,.btn-soft,.btn-danger-soft{min-height:45px;border-radius:14px;padding:0 18px;font-weight:950;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:.18s ease!important}.btn-main{border:0;color:#fff!important;background:linear-gradient(135deg,#6d4cff,#7c3aed)!important;box-shadow:0 17px 34px rgba(109,76,255,.35),inset 0 1px 0 rgba(255,255,255,.18)!important}.btn-main:hover,.btn-soft:hover{filter:brightness(1.08);transform:translateY(-1px);color:#fff!important}.btn-soft{color:#eaf0ff!important;background:rgba(10,16,33,.82)!important;border:1px solid rgba(148,163,184,.28)!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 12px 28px rgba(0,0,0,.16)!important}.btn-danger-soft{color:#fecaca!important;background:rgba(239,68,68,.17)!important;border:1px solid rgba(239,68,68,.40)!important}.btn-danger-soft:hover{color:#fff!important;background:rgba(239,68,68,.28)!important}
        .glass-card{position:relative;overflow:hidden;border-radius:22px;border:1px solid rgba(167,139,250,.30);background:radial-gradient(circle at 16% 0%,rgba(124,58,237,.24),transparent 34%),linear-gradient(135deg,rgba(18,24,50,.82),rgba(8,13,31,.68));box-shadow:0 22px 58px rgba(0,0,0,.34),inset 0 1px 0 rgba(255,255,255,.08);backdrop-filter:blur(18px) saturate(145%);-webkit-backdrop-filter:blur(18px) saturate(145%)}.glass-card:after{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(120deg,rgba(255,255,255,.085),transparent 38%,rgba(255,255,255,.035));opacity:.76}.glass-card>*{position:relative;z-index:1}.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.stat-card{min-height:96px;padding:18px;border:0}.stat-grid .stat-card:nth-child(1){background:linear-gradient(135deg,#6d4cff,#7c3aed);box-shadow:0 18px 36px rgba(109,76,255,.34)}.stat-grid .stat-card:nth-child(2){background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 18px 36px rgba(16,185,129,.26)}.stat-grid .stat-card:nth-child(3){background:linear-gradient(135deg,#f97316,#f59e0b);box-shadow:0 18px 36px rgba(245,158,11,.24)}.stat-grid .stat-card:nth-child(4){background:linear-gradient(135deg,#0ea5e9,#2563eb);box-shadow:0 18px 36px rgba(14,165,233,.25)}.stat-card:before{content:"";position:absolute;top:14px;right:14px;width:78px;height:64px;border-radius:22px;background:rgba(255,255,255,.15);z-index:0}.stat-label{position:relative;z-index:1;color:rgba(255,255,255,.88);font-size:12px;font-weight:950;text-transform:uppercase;letter-spacing:.075em}.stat-value{position:relative;z-index:1;margin-top:9px;color:#fff;font-size:28px;line-height:1.1;font-weight:950;text-shadow:0 8px 20px rgba(0,0,0,.22)}
        .scope-card{padding:16px;margin-bottom:18px}.scope-row{display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:end}.scope-note{color:#b9c2da;font-weight:760;font-size:13px;margin-top:8px}.section-card{padding:18px;margin-bottom:16px}.section-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}.section-title{display:flex;gap:12px;align-items:center}.section-title i{width:42px;height:42px;border-radius:15px;display:grid;place-items:center;background:linear-gradient(135deg,rgba(109,76,255,.9),rgba(124,58,237,.9));box-shadow:0 14px 28px rgba(109,76,255,.25)}.section-title h3{margin:0;color:#fff;font-size:18px;font-weight:950}.section-title p{margin:2px 0 0;color:#aeb8d5;font-weight:760;font-size:13px}.settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.setting-item{min-height:122px;padding:14px;border-radius:18px;background:linear-gradient(135deg,rgba(15,23,42,.66),rgba(24,13,54,.42));border:1px solid rgba(148,163,184,.16);box-shadow:inset 0 1px 0 rgba(255,255,255,.04)}.setting-label-row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}.setting-item label{color:#e0e7ff;font-size:13px;font-weight:950;margin:0}.help-text{color:#9ca8c7;font-size:12px;font-weight:720;line-height:1.35;margin-top:8px}.source-badge{padding:5px 8px;border-radius:999px;font-size:10px;font-weight:950;white-space:nowrap;border:1px solid rgba(255,255,255,.14)}.source-branch{background:rgba(16,185,129,.15);color:#a7f3d0}.source-global{background:rgba(124,58,237,.16);color:#ddd6fe}
        .form-control,.form-select{min-height:45px;border-radius:14px;background:rgba(8,13,31,.88)!important;color:#f8fafc!important;border:1px solid rgba(191,219,254,.34)!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.05),0 12px 28px rgba(0,0,0,.16)!important;font-weight:850}.form-control::placeholder{color:rgba(203,213,225,.58)!important}.form-control:focus,.form-select:focus{background:rgba(10,16,35,.94)!important;border-color:rgba(139,92,246,.95)!important;box-shadow:0 0 0 3px rgba(124,58,237,.24),inset 0 1px 0 rgba(255,255,255,.08)!important}.form-select option{background:#0f172a!important;color:#f8fafc!important}textarea.form-control{min-height:92px;resize:vertical}.toggle-wrap{height:45px;display:flex;align-items:center;gap:10px}.toggle-wrap input{display:none}.toggle-wrap span{position:relative;width:54px;height:30px;border-radius:999px;background:rgba(148,163,184,.26);border:1px solid rgba(148,163,184,.26);transition:.2s ease}.toggle-wrap span:before{content:"";position:absolute;width:22px;height:22px;left:4px;top:3px;border-radius:50%;background:#e5e7eb;transition:.2s ease;box-shadow:0 6px 14px rgba(0,0,0,.28)}.toggle-wrap input:checked+span{background:linear-gradient(135deg,#10b981,#059669);border-color:rgba(16,185,129,.55)}.toggle-wrap input:checked+span:before{left:26px;background:#fff}.toggle-wrap strong{font-size:13px;color:#cbd5e1;font-weight:950}.alert{border-radius:18px;font-weight:900;border:1px solid rgba(255,255,255,.14);box-shadow:0 18px 45px rgba(0,0,0,.24);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}.alert-success{background:linear-gradient(135deg,rgba(16,185,129,.18),rgba(5,150,105,.10));color:#bbf7d0;border-color:rgba(16,185,129,.38)}.alert-danger{background:linear-gradient(135deg,rgba(239,68,68,.18),rgba(127,29,29,.12));color:#fecaca;border-color:rgba(239,68,68,.38)}.sticky-actions{position:sticky;bottom:16px;z-index:50;margin-top:18px;padding:12px;border-radius:20px;background:rgba(5,8,18,.82);border:1px solid rgba(148,163,184,.20);box-shadow:0 18px 50px rgba(0,0,0,.42);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);display:flex;justify-content:space-between;gap:12px;align-items:center}.sticky-actions .text{color:#aeb8d5;font-weight:800}.preview-card{padding:18px}.receipt-preview{max-width:360px;margin:0 auto;border-radius:18px;padding:18px;background:#f8fafc;color:#111827;font-family:"Courier New",monospace;box-shadow:0 18px 45px rgba(0,0,0,.28)}.receipt-preview h4{font-size:18px;font-weight:900;text-align:center;margin:0 0 8px}.receipt-preview .line{border-top:1px dashed #111827;margin:10px 0}.receipt-preview .r-row{display:flex;justify-content:space-between;gap:8px;font-size:13px}.receipt-preview .grand{font-size:16px;font-weight:900}.receipt-preview small{display:block;text-align:center;color:#374151;margin-top:8px}
        @media(max-width:1200px){.settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.scope-row{grid-template-columns:1fr 1fr}}
        @media(max-width:991px){body.restaurant-settings-theme .topbar-wrap{height:72px!important}body.restaurant-settings-theme .main-panel{padding-top:72px!important}.content-wrapper{padding:22px 16px 34px!important;min-height:calc(100vh - 72px)!important}.page-title{font-size:26px}.scope-row{grid-template-columns:1fr}.settings-grid{grid-template-columns:1fr}.sticky-actions{position:static;display:block}.sticky-actions .d-flex{margin-top:10px}.head-actions .btn-main,.head-actions .btn-soft{width:100%}.page-head{display:block}.head-actions{margin-top:12px}.stat-grid{grid-template-columns:1fr}}
    </style>
</head>
<body class="restaurant-settings-theme">
<div class="container-scroll">
    <div class="sidebar"><?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?></div>
    <style>
/* FINAL RESTAURANT SETTINGS SIDEBAR FIX */

body.restaurant-settings-theme .container-scroll{
    display:flex !important;
}

@media (min-width: 992px){

    body.restaurant-settings-theme .container-scroll > .sidebar{
        position:sticky !important;
        top:0 !important;
        left:0 !important;
        width:242px !important;
        min-width:242px !important;
        max-width:242px !important;
        height:100vh !important;
        z-index:100060 !important;
        transform:none !important;
        background:#0b1220 !important;
        overflow:hidden !important;
    }

    body.restaurant-settings-theme.sidebar-collapsed .container-scroll > .sidebar{
        width:0 !important;
        min-width:0 !important;
        max-width:0 !important;
        overflow:hidden !important;
    }

    body.restaurant-settings-theme .main-panel{
        margin-left:0 !important;
        width:auto !important;
        max-width:none !important;
        flex:1 1 auto !important;
        min-width:0 !important;
    }

    body.restaurant-settings-theme .topbar-wrap{
        left:242px !important;
        right:0 !important;
        width:auto !important;
    }

    body.restaurant-settings-theme.sidebar-collapsed .topbar-wrap{
        left:0 !important;
        right:0 !important;
        width:auto !important;
    }

    body.restaurant-settings-theme .content-wrapper{
        margin-left:0 !important;
        width:100% !important;
        max-width:100% !important;
        padding-left:24px !important;
        padding-right:24px !important;
    }
}

@media (max-width: 991px){

    body.restaurant-settings-theme .container-scroll > .sidebar{
        position:fixed !important;
        top:0 !important;
        left:0 !important;
        width:270px !important;
        min-width:270px !important;
        max-width:270px !important;
        height:100vh !important;
        z-index:100060 !important;
        background:#0b1220 !important;
        transform:translateX(-100%) !important;
        transition:transform .25s ease !important;
    }

    body.restaurant-settings-theme.sidebar-open .container-scroll > .sidebar{
        transform:translateX(0) !important;
    }

    body.restaurant-settings-theme .main-panel,
    body.restaurant-settings-theme .content-wrapper{
        margin-left:0 !important;
        width:100% !important;
        max-width:100% !important;
    }

    body.restaurant-settings-theme .topbar-wrap{
        left:0 !important;
        right:0 !important;
        width:auto !important;
    }
}  </style>
    <div class="main-panel">
        <div class="topbar-wrap"><?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?></div>
        <div class="content-wrapper">
            <div class="page-head">
                <div>
                    <h1 class="page-title">Restaurant Settings</h1>
                    <div class="page-subtitle">Service charges, tax, KOT print, bill format and table rules management.</div>
                </div>
                <div class="head-actions">
                    <a href="dashboard.php" class="btn-soft"><i class="bi bi-grid"></i> Table Dashboard</a>
                    <a href="restaurant_tables.php" class="btn-main"><i class="bi bi-layout-text-window"></i> Tables</a>
                </div>
            </div>

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= e($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= e($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="stat-grid">
                <div class="glass-card stat-card"><div class="stat-label">Scope</div><div class="stat-value"><?= e($scopeLabel) ?></div></div>
                <div class="glass-card stat-card"><div class="stat-label">Tax %</div><div class="stat-value"><?= e(number_format((float)$settings['tax_percentage'], 2)) ?>%</div></div>
                <div class="glass-card stat-card"><div class="stat-label">Service %</div><div class="stat-value"><?= e(number_format((float)$settings['service_charge_percentage'], 2)) ?>%</div></div>
                <div class="glass-card stat-card"><div class="stat-label">Bill Format</div><div class="stat-value"><?= e(ucwords(str_replace('_',' ', $settings['bill_print_format']))) ?></div></div>
            </div>

            <div class="glass-card scope-card">
                <form method="get" class="scope-row">
                    <div>
                        <label class="mb-2 fw-bold text-white">Settings Scope</label>
                        <select name="branch_id" class="form-select" onchange="this.form.submit()">
                            <option value="0" <?= $selected_branch_id === 0 ? 'selected' : '' ?>>Global Defaults - All Branches</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $selected_branch_id === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="scope-note">Global settings are used as the fallback. Select a branch to save branch-specific overrides.</div>
                    </div>
                    <a href="restaurant_settings.php?branch_id=0" class="btn-soft"><i class="bi bi-globe"></i> Global</a>
                    <a href="restaurant_settings.php?branch_id=<?= (int)$session_branch_id ?>" class="btn-soft"><i class="bi bi-pin-map"></i> My Branch</a>
                </form>
            </div>

            <form method="post" id="settingsForm">
                <input type="hidden" name="branch_id" value="<?= (int)$selected_branch_id ?>">

                <?php foreach($sectionLabels as $sectionKey => $meta): if(empty($settingsBySection[$sectionKey])) continue; ?>
                    <div class="glass-card section-card">
                        <div class="section-title-row">
                            <div class="section-title">
                                <i class="bi <?= e($meta['icon']) ?>"></i>
                                <div>
                                    <h3><?= e($meta['title']) ?></h3>
                                    <p><?= e($meta['desc']) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="settings-grid">
                            <?php foreach($settingsBySection[$sectionKey] as $key => $def): ?>
                                <?= render_setting_input($key, $def, $settings, $sources, $selected_branch_id) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="glass-card preview-card mb-3">
                    <div class="section-title-row">
                        <div class="section-title">
                            <i class="bi bi-eye"></i>
                            <div>
                                <h3>Bill Preview</h3>
                                <p>Sample receipt preview based on current settings.</p>
                            </div>
                        </div>
                    </div>
                    <div class="receipt-preview">
                        <h4><?= e($settings['restaurant_name'] ?: 'Restaurant') ?></h4>
                        <div class="r-row"><span>Order Type</span><strong><?= e(ucwords(str_replace('_',' ', $settings['default_order_type']))) ?></strong></div>
                        <div class="r-row"><span>KOT Mode</span><strong><?= e(ucwords($settings['kot_print_mode'])) ?></strong></div>
                        <div class="line"></div>
                        <div class="r-row"><span>Sample Item</span><span><?= e($settings['currency_symbol']) ?> 1,000.00</span></div>
                        <div class="r-row"><span>Tax <?= e(number_format((float)$settings['tax_percentage'],2)) ?>%</span><span><?= e($settings['currency_symbol']) ?> <?= e(number_format(1000*((float)$settings['tax_percentage']/100),2)) ?></span></div>
                        <div class="r-row"><span>Service <?= e(number_format((float)$settings['service_charge_percentage'],2)) ?>%</span><span><?= e($settings['currency_symbol']) ?> <?= e(number_format(1000*((float)$settings['service_charge_percentage']/100),2)) ?></span></div>
                        <div class="line"></div>
                        <div class="r-row grand"><span>Total</span><span><?= e($settings['currency_symbol']) ?> <?= e(number_format(1000 + 1000*((float)$settings['tax_percentage']/100) + 1000*((float)$settings['service_charge_percentage']/100), 2)) ?></span></div>
                        <?php if(trim((string)$settings['receipt_footer_note']) !== ''): ?><small><?= e($settings['receipt_footer_note']) ?></small><?php endif; ?>
                    </div>
                </div>

                <div class="sticky-actions">
                    <div class="text">
                        <i class="bi bi-info-circle me-1"></i>
                        Saving scope: <strong class="text-white"><?= e($scopeLabel) ?></strong>
                    </div>
                    <div class="d-flex gap-2 flex-wrap justify-content-end">
                        <?php if($selected_branch_id > 0): ?>
                            <button type="submit" name="copy_global" value="1" class="btn-soft" onclick="return confirm('Copy global settings to this branch? Existing branch overrides will be overwritten for matching keys.')"><i class="bi bi-copy"></i> Copy Global</button>
                        <?php endif; ?>
                        <button type="submit" name="reset_settings" value="1" class="btn-danger-soft" onclick="return confirm('Reset settings for selected scope?')"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
                        <button type="submit" name="save_settings" value="1" class="btn-main"><i class="bi bi-check2-circle"></i> Save Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){

    document.querySelectorAll('.toggle-wrap input').forEach(function(input){
        function sync(){
            const label = input.closest('.toggle-wrap').querySelector('strong');
            if(label) label.textContent = input.checked ? 'Enabled' : 'Disabled';
        }
        input.addEventListener('change', sync);
        sync();
    });

    document.addEventListener('click', function(e){
        const toggleBtn = e.target.closest(
            '.sidebar-toggle, .pos-sidebar-toggle, .navbar-toggler, #sidebarToggle, #sidebar-toggle, #menuToggle, .menu-toggle, button'
        );

        if(!toggleBtn) return;

        const hasMenuIcon =
            toggleBtn.querySelector('.bi-list, .fa-bars, .hamburger') ||
            toggleBtn.innerHTML.includes('bi-list') ||
            toggleBtn.innerHTML.includes('fa-bars');

        if(!hasMenuIcon && !toggleBtn.matches('.sidebar-toggle, .pos-sidebar-toggle, .navbar-toggler, #sidebarToggle, #sidebar-toggle, #menuToggle, .menu-toggle')){
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        if(window.innerWidth <= 991){
            document.body.classList.toggle('sidebar-open');
            document.body.classList.remove('sidebar-collapsed');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
            document.body.classList.remove('sidebar-open');
        }
    });

    document.addEventListener('click', function(e){
        if(window.innerWidth > 991) return;

        const sidebar = document.querySelector('.container-scroll > .sidebar');
        const toggleBtn = e.target.closest('.sidebar-toggle, .pos-sidebar-toggle, .navbar-toggler, #sidebarToggle, #sidebar-toggle, #menuToggle, .menu-toggle, button');

        if(!sidebar || toggleBtn) return;

        if(document.body.classList.contains('sidebar-open') && !sidebar.contains(e.target)){
            document.body.classList.remove('sidebar-open');
        }
    });
});
  
</script>



</body>
</html>
