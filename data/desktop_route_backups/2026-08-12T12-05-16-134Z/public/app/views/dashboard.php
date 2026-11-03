<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);

if ($client_id <= 0 && $role_id !== 1) {
    die('Client not found in session.');
}

/* ================= HELPERS ================= */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $count > 0;
}

function table_has_column(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $count > 0;
}

function first_existing_column(mysqli $conn, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (table_has_column($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function first_existing_table(mysqli $conn, array $tables): ?string {
    foreach ($tables as $table) {
        if (table_exists($conn, $table) && table_has_column($conn, $table, 'client_id')) {
            return $table;
        }
    }
    return null;
}

function scalar_value(mysqli $conn, string $sql, string $types = '', array $params = [], $default = 0) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $default;
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? $default;
}

function rows_value(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function format_safe_date($value, string $format = 'd M Y'): string {
    if (!$value) return 'N/A';
    $time = strtotime((string)$value);
    return $time ? date($format, $time) : 'N/A';
}

function normalize_industry(?string $industry): string {
    $industry = strtolower(trim((string)$industry));
    $industry = str_replace(['-', ' '], '_', $industry);

    $map = [
        'resturent' => 'restaurant',
        'resturant' => 'restaurant',
        'restaurant_management' => 'restaurant',
        'manufacturing' => 'production',
        'manufacture' => 'production',
        'production_management' => 'production',
        'water_plant' => 'water',
        'water_delivery' => 'water',
        'waterplant' => 'water',
        'state_agency' => 'real_estate',
        'estate_agency' => 'real_estate',
        'realestate' => 'real_estate',
        'agency' => 'real_estate',
        'service' => 'services',
        'appointments' => 'services',
        'appointment' => 'services',
        'salon' => 'services',
        'retail_pos' => 'retail',
        'pos' => 'retail',
    ];

    return $map[$industry] ?? $industry;
}

function dashboard_get_client_industry(mysqli $conn, int $client_id): string {
    if ($client_id <= 0) return 'retail';

    if (table_exists($conn, 'subscriptions') && table_exists($conn, 'plans') && table_has_column($conn, 'plans', 'industry')) {
        $statusSql = table_has_column($conn, 'subscriptions', 'status') ? " AND s.status IN ('active','trial','1','enabled')" : '';
        $endSql = table_has_column($conn, 'subscriptions', 'end_date') ? " AND (s.end_date IS NULL OR s.end_date >= CURDATE())" : '';
        $sql = "
            SELECT p.industry
            FROM subscriptions s
            INNER JOIN plans p ON p.id = s.plan_id
            WHERE s.client_id = ?
              $statusSql
              $endSql
            ORDER BY s.id DESC
            LIMIT 1
        ";
        $industry = (string)scalar_value($conn, $sql, 'i', [$client_id], '');
        $industry = normalize_industry($industry);
        if ($industry !== '') {
            return $industry;
        }
    }

    return 'retail';
}

function placeholders(array $values): string {
    return implode(',', array_fill(0, count($values), '?'));
}

function client_has_feature(mysqli $conn, int $client_id, array $keys): bool {
    $keys = array_values(array_unique(array_filter(array_map('trim', $keys))));
    if (!$keys || $client_id <= 0) return false;

    $ph = placeholders($keys);
    $types = str_repeat('s', count($keys));

    if (table_exists($conn, 'client_feature_overrides') && table_has_column($conn, 'client_feature_overrides', 'feature_key')) {
        $enabledSql = table_has_column($conn, 'client_feature_overrides', 'enabled') ? " AND enabled IN (1,'1','active','enabled','yes')" : '';
        $params = array_merge([$client_id], $keys);
        $sql = "SELECT COUNT(*) FROM client_feature_overrides WHERE client_id = ? AND feature_key IN ($ph) $enabledSql";
        if ((int)scalar_value($conn, $sql, 'i' . $types, $params, 0) > 0) return true;
    }

    if (table_exists($conn, 'subscriptions') && table_exists($conn, 'plan_features') && table_has_column($conn, 'plan_features', 'feature_key')) {
        $subStatusSql = table_has_column($conn, 'subscriptions', 'status') ? " AND s.status IN ('active','trial','1','enabled')" : '';
        $endSql = table_has_column($conn, 'subscriptions', 'end_date') ? " AND (s.end_date IS NULL OR s.end_date >= CURDATE())" : '';
        $pfEnabledSql = table_has_column($conn, 'plan_features', 'enabled') ? " AND pf.enabled IN (1,'1','active','enabled','yes')" : '';
        $params = array_merge([$client_id], $keys);
        $sql = "
            SELECT COUNT(*)
            FROM subscriptions s
            INNER JOIN plan_features pf ON pf.plan_id = s.plan_id
            WHERE s.client_id = ?
              AND pf.feature_key IN ($ph)
              $subStatusSql
              $endSql
              $pfEnabledSql
        ";
        if ((int)scalar_value($conn, $sql, 'i' . $types, $params, 0) > 0) return true;
    }

    return false;
}

function get_filter_dates(string $period, string $from = '', string $to = '', string $month = ''): array {
    $today = date('Y-m-d');
    switch ($period) {
        case 'yesterday':
            $d = date('Y-m-d', strtotime('-1 day'));
            return [$d, $d, 'Yesterday'];
        case 'this_week':
            return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week')), 'This Week'];
        case 'this_month':
            if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
                $start = $month . '-01';
                return [$start, date('Y-m-t', strtotime($start)), date('F Y', strtotime($start))];
            }
            return [date('Y-m-01'), date('Y-m-t'), date('F Y')];
        case 'date_range':
            if ($from && $to) return [$from, $to, 'Date Range'];
            return [date('Y-m-01'), date('Y-m-t'), 'Date Range'];
        case 'today':
        default:
            return [$today, $today, 'Today'];
    }
}

function date_filter_clause(mysqli $conn, string $table, string &$types, array &$params, string $from, string $to, array $preferred = []): string {
    $preferred = $preferred ?: ['created_at', 'date', 'sale_date', 'order_date', 'started_at', 'appointment_date', 'booking_date'];
    $dateCol = first_existing_column($conn, $table, $preferred);
    if (!$dateCol || !$from || !$to) return '';
    $types .= 'ss';
    $params[] = $from;
    $params[] = $to;
    return " AND DATE(`$dateCol`) BETWEEN ? AND ?";
}

function count_rows(mysqli $conn, string $table, int $client_id, string $extraWhere = '', string $extraTypes = '', array $extraParams = []): int {
    if (!table_exists($conn, $table) || !table_has_column($conn, $table, 'client_id')) return 0;
    $sql = "SELECT COUNT(id) FROM `$table` WHERE client_id = ? $extraWhere";
    return (int)scalar_value($conn, $sql, 'i' . $extraTypes, array_merge([$client_id], $extraParams), 0);
}

function sum_column(mysqli $conn, string $table, string $column, int $client_id, string $extraWhere = '', string $extraTypes = '', array $extraParams = []): float {
    if (!table_exists($conn, $table) || !table_has_column($conn, $table, 'client_id') || !table_has_column($conn, $table, $column)) return 0.0;
    $sql = "SELECT COALESCE(SUM(`$column`),0) FROM `$table` WHERE client_id = ? $extraWhere";
    return (float)scalar_value($conn, $sql, 'i' . $extraTypes, array_merge([$client_id], $extraParams), 0);
}

function item_image_url($image): string {
    if (!$image) return '';
    return '/uploads/items/' . ltrim((string)$image, '/');
}

/* ================= FILTERS ================= */
$period = $_GET['period'] ?? 'today';
$range_from = $_GET['from_date'] ?? date('Y-m-01');
$range_to = $_GET['to_date'] ?? date('Y-m-t');
$selected_month = $_GET['month'] ?? date('Y-m');
$selected_branch = $_GET['branch_id'] ?? 'all';
[$filter_from, $filter_to, $filter_label] = get_filter_dates((string)$period, (string)$range_from, (string)$range_to, (string)$selected_month);

/* ================= INDUSTRY DETECTION ================= */
$client_industry = dashboard_get_client_industry($conn, $client_id);

if ($client_industry === 'retail') {
    if (client_has_feature($conn, $client_id, ['restaurant', 'restaurant_module', 'restaurant_dashboard'])) {
        $client_industry = 'restaurant';
    } elseif (client_has_feature($conn, $client_id, ['production', 'manufacturing', 'production_formulas'])) {
        $client_industry = 'production';
    } elseif (client_has_feature($conn, $client_id, ['real_estate', 'state_agency', 're_dashboard'])) {
        $client_industry = 'real_estate';
    } elseif (client_has_feature($conn, $client_id, ['water_delivery', 'water_plant'])) {
        $client_industry = 'water';
    } elseif (client_has_feature($conn, $client_id, ['distribution', 'dist_dashboard', 'delivery_assign'])) {
        $client_industry = 'distribution';
    } elseif (client_has_feature($conn, $client_id, ['appointments', 'bookings', 'booking_calendar', 'services'])) {
        $client_industry = 'services';
    }
}

$industry_titles = [
    'restaurant' => ['Restaurant Dashboard', 'Live table sessions, KOT, waiters and sales summary', 'bi-cup-hot'],
    'production' => ['Production Dashboard', 'Formulas, work orders, batches, wastage and costing overview', 'bi-gear-wide-connected'],
    'distribution' => ['Distribution Dashboard', 'Riders, dispatch, delivery orders and collection summary', 'bi-truck'],
    'water' => ['Water Plant Dashboard', 'Bottle inventory, delivery assignment and collection summary', 'bi-droplet-half'],
    'real_estate' => ['Real Estate Dashboard', 'Leads, follow-ups, site visits, properties and deals summary', 'bi-buildings'],
    'services' => ['Service Booking Dashboard', 'Appointments, bookings, slots and customer schedule overview', 'bi-calendar2-check'],
    'retail' => ['POS Dashboard', 'Sales, products, inventory and customer activity overview', 'bi-grid-1x2'],
];
[$pageTitle, $pageSubtitle, $pageIcon] = $industry_titles[$client_industry] ?? $industry_titles['retail'];

/* ================= GENERAL POS STATS ================= */
$salesDateCol = table_exists($conn, 'sales') ? first_existing_column($conn, 'sales', ['created_at', 'date', 'sale_date', 'order_date']) : null;
$itemsDateCol = table_exists($conn, 'items') ? first_existing_column($conn, 'items', ['created_at', 'date']) : null;
$expensesDateCol = table_exists($conn, 'expenses') ? first_existing_column($conn, 'expenses', ['created_at', 'expense_date', 'date']) : null;

$salesDateWhere = '';
$salesDateTypes = '';
$salesDateParams = [];
if ($salesDateCol) {
    $salesDateWhere = " AND DATE(`$salesDateCol`) BETWEEN ? AND ?";
    $salesDateTypes = 'ss';
    $salesDateParams = [$filter_from, $filter_to];
}

$total_orders = count_rows($conn, 'sales', $client_id);
$period_orders = count_rows($conn, 'sales', $client_id, $salesDateWhere, $salesDateTypes, $salesDateParams);
$total_sales = sum_column($conn, 'sales', 'total', $client_id);
$period_sales = sum_column($conn, 'sales', 'total', $client_id, $salesDateWhere, $salesDateTypes, $salesDateParams);
$total_customers = count_rows($conn, 'customers', $client_id);
$total_items = count_rows($conn, 'items', $client_id);

$low_stock_count = 0;
if (table_exists($conn, 'items') && table_has_column($conn, 'items', 'track_inventory') && table_has_column($conn, 'items', 'stock_qty')) {
    $low_stock_count = count_rows($conn, 'items', $client_id, " AND track_inventory = 1 AND stock_qty <= 5");
}

$pending_expense_amount = 0;
$expenses = [];
if (table_exists($conn, 'expenses') && table_has_column($conn, 'expenses', 'client_id')) {
    $where = '';
    $types = 'i';
    $params = [$client_id];
    if ($selected_branch !== 'all' && $selected_branch !== '' && table_has_column($conn, 'expenses', 'branch_id')) {
        $where .= " AND e.branch_id = ?";
        $types .= 'i';
        $params[] = (int)$selected_branch;
    }
    if ($expensesDateCol) {
        $where .= " AND DATE(e.`$expensesDateCol`) BETWEEN ? AND ?";
        $types .= 'ss';
        $params[] = $filter_from;
        $params[] = $filter_to;
    }
    $dateSelect = $expensesDateCol ? "e.`$expensesDateCol` AS created_at" : "NULL AS created_at";
    $branchJoin = table_exists($conn, 'branches') && table_has_column($conn, 'expenses', 'branch_id') ? "LEFT JOIN branches b ON b.id = e.branch_id" : "";
    $branchSelect = $branchJoin ? "b.name AS branch_name" : "'Main Branch' AS branch_name";
    $expenses = rows_value($conn, "SELECT e.description, e.amount, $dateSelect, $branchSelect FROM expenses e $branchJoin WHERE e.client_id = ? $where ORDER BY e.id DESC LIMIT 6", $types, $params);
    foreach ($expenses as $row) $pending_expense_amount += (float)($row['amount'] ?? 0);
}

$branches = [];
if (table_exists($conn, 'branches') && table_has_column($conn, 'branches', 'client_id')) {
    $branches = rows_value($conn, "SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC", 'i', [$client_id]);
}

$recent_orders = [];
if (table_exists($conn, 'sales') && table_has_column($conn, 'sales', 'client_id')) {
    $invoiceCol = table_has_column($conn, 'sales', 'invoice_no') ? 'invoice_no' : 'id';
    $customerSql = table_has_column($conn, 'sales', 'customer_name') ? 'customer_name' : "'Walk-in Customer' AS customer_name";
    $dateSql = $salesDateCol ? "`$salesDateCol` AS created_at" : "NULL AS created_at";
    $recent_orders = rows_value($conn, "SELECT id, `$invoiceCol` AS invoice_no, $customerSql, total, $dateSql FROM sales WHERE client_id = ? ORDER BY id DESC LIMIT 5", 'i', [$client_id]);
}

$top_products = [];
if (table_exists($conn, 'sale_items') && table_exists($conn, 'sales') && table_exists($conn, 'items') && table_has_column($conn, 'sale_items', 'qty')) {
    $amountCol = table_has_column($conn, 'sale_items', 'total') ? 'si.total' : '0';
    $imageCol = table_has_column($conn, 'items', 'image') ? 'i.image' : "'' AS image";
    $top_products = rows_value($conn, "
        SELECT i.name, $imageCol, COALESCE(SUM(si.qty),0) AS total_units, COALESCE(SUM($amountCol),0) AS total_amount
        FROM sale_items si
        INNER JOIN sales s ON s.id = si.sale_id
        LEFT JOIN items i ON i.id = si.item_id
        WHERE s.client_id = ?
        GROUP BY si.item_id, i.name" . (table_has_column($conn, 'items', 'image') ? ", i.image" : "") . "
        ORDER BY total_units DESC
        LIMIT 5
    ", 'i', [$client_id]);
}

$recent_items = [];
if (table_exists($conn, 'items') && table_has_column($conn, 'items', 'client_id')) {
    $skuCol = table_has_column($conn, 'items', 'sku') ? 'sku' : 'id';
    $typeCol = table_has_column($conn, 'items', 'type') ? 'type' : "'Product' AS type";
    $priceCol = table_has_column($conn, 'items', 'price') ? 'price' : '0 AS price';
    $recent_items = rows_value($conn, "SELECT id, name, `$skuCol` AS sku, $typeCol, $priceCol FROM items WHERE client_id = ? ORDER BY id DESC LIMIT 6", 'i', [$client_id]);
}

/* ================= RESTAURANT STATS ================= */
$restaurant = [
    'tables_total' => 0,
    'available' => 0,
    'occupied' => 0,
    'reserved' => 0,
    'open_bills' => 0,
    'kot_sent' => 0,
    'waiters' => 0,
    'running_total' => 0,
    'recent_sessions' => [],
    'table_rows' => [],
];

if ($client_industry === 'restaurant') {
    if (table_exists($conn, 'restaurant_tables') && table_has_column($conn, 'restaurant_tables', 'client_id')) {
        $restaurant['tables_total'] = count_rows($conn, 'restaurant_tables', $client_id, table_has_column($conn, 'restaurant_tables', 'is_active') ? " AND is_active = 1" : '');
        $restaurant['available'] = count_rows($conn, 'restaurant_tables', $client_id, table_has_column($conn, 'restaurant_tables', 'status') ? " AND status = 'Available'" : '');
        $restaurant['occupied'] = count_rows($conn, 'restaurant_tables', $client_id, table_has_column($conn, 'restaurant_tables', 'status') ? " AND status IN ('Occupied','Active')" : '');
        $restaurant['reserved'] = count_rows($conn, 'restaurant_tables', $client_id, table_has_column($conn, 'restaurant_tables', 'status') ? " AND status = 'Reserved'" : '');
        $nameCol = table_has_column($conn, 'restaurant_tables', 'table_name') ? 'table_name' : 'table_no';
        $floorCol = table_has_column($conn, 'restaurant_tables', 'floor_name') ? 'floor_name' : "'' AS floor_name";
        $statusCol = table_has_column($conn, 'restaurant_tables', 'status') ? 'status' : "'Available' AS status";
        $capCol = table_has_column($conn, 'restaurant_tables', 'capacity') ? 'capacity' : '0 AS capacity';
        $restaurant['table_rows'] = rows_value($conn, "SELECT id, table_no, `$nameCol` AS table_name, $floorCol, $statusCol, $capCol FROM restaurant_tables WHERE client_id = ? ORDER BY id DESC LIMIT 8", 'i', [$client_id]);
    }

    if (table_exists($conn, 'restaurant_table_sessions') && table_has_column($conn, 'restaurant_table_sessions', 'client_id')) {
        $statusCol = table_has_column($conn, 'restaurant_table_sessions', 'status') ? 'status' : null;
        if ($statusCol) {
            $restaurant['open_bills'] = count_rows($conn, 'restaurant_table_sessions', $client_id, " AND status IN ('open','kot_sent','bill_printed')");
            $restaurant['kot_sent'] = count_rows($conn, 'restaurant_table_sessions', $client_id, " AND status IN ('kot_sent','KOT Sent')");
        } else {
            $restaurant['open_bills'] = count_rows($conn, 'restaurant_table_sessions', $client_id);
        }
        $restaurant['running_total'] = sum_column($conn, 'restaurant_table_sessions', 'grand_total', $client_id, $statusCol ? " AND status IN ('open','kot_sent','bill_printed')" : '');

        $dateCol = first_existing_column($conn, 'restaurant_table_sessions', ['started_at', 'created_at', 'updated_at']);
        $dateSelect = $dateCol ? "rts.`$dateCol` AS started_at" : "NULL AS started_at";
        $guestSelect = table_has_column($conn, 'restaurant_table_sessions', 'guest_count') ? 'rts.guest_count' : '0 AS guest_count';
        $totalSelect = table_has_column($conn, 'restaurant_table_sessions', 'grand_total') ? 'rts.grand_total' : '0 AS grand_total';
        $sessionStatus = $statusCol ? 'rts.status' : "'open' AS status";
        $tableJoin = table_exists($conn, 'restaurant_tables') && table_has_column($conn, 'restaurant_table_sessions', 'table_id') ? "LEFT JOIN restaurant_tables rt ON rt.id = rts.table_id" : '';
        $tableName = $tableJoin ? "COALESCE(rt.table_name, CONCAT('Table ', rt.table_no)) AS table_name" : "CONCAT('Session #', rts.id) AS table_name";
        $restaurant['recent_sessions'] = rows_value($conn, "SELECT rts.id, $tableName, $sessionStatus, $guestSelect, $totalSelect, $dateSelect FROM restaurant_table_sessions rts $tableJoin WHERE rts.client_id = ? ORDER BY rts.id DESC LIMIT 8", 'i', [$client_id]);
    }

    if (table_exists($conn, 'restaurant_waiters') && table_has_column($conn, 'restaurant_waiters', 'client_id')) {
        $restaurant['waiters'] = count_rows($conn, 'restaurant_waiters', $client_id, table_has_column($conn, 'restaurant_waiters', 'status') ? " AND LOWER(status) IN ('active','available','1')" : '');
    }
}

/* ================= DISTRIBUTION / WATER STATS ================= */
$distribution = [
    'assigned' => 0,
    'out_for_delivery' => 0,
    'delivered' => 0,
    'failed' => 0,
    'collections' => 0,
    'drivers' => 0,
    'recent' => [],
];
$show_distribution = in_array($client_industry, ['distribution', 'water'], true);
if ($show_distribution) {
    if (table_exists($conn, 'delivery_drivers') && table_has_column($conn, 'delivery_drivers', 'client_id')) {
        $distribution['drivers'] = count_rows($conn, 'delivery_drivers', $client_id, table_has_column($conn, 'delivery_drivers', 'status') ? " AND LOWER(status) NOT IN ('inactive','blocked')" : '');
    }
    if (table_exists($conn, 'delivery_order_assignments') && table_has_column($conn, 'delivery_order_assignments', 'client_id')) {
        $where = '';
        $types = '';
        $params = [];
        $where .= date_filter_clause($conn, 'delivery_order_assignments', $types, $params, $filter_from, $filter_to, ['delivery_date', 'created_at', 'date']);
        $distribution['assigned'] = count_rows($conn, 'delivery_order_assignments', $client_id, " AND status = 'assigned'" . $where, $types, $params);
        $distribution['out_for_delivery'] = count_rows($conn, 'delivery_order_assignments', $client_id, " AND status = 'out_for_delivery'" . $where, $types, $params);
        $distribution['delivered'] = count_rows($conn, 'delivery_order_assignments', $client_id, " AND status = 'delivered'" . $where, $types, $params);
        $distribution['failed'] = count_rows($conn, 'delivery_order_assignments', $client_id, " AND status IN ('failed','returned','cancelled')" . $where, $types, $params);
        $distribution['collections'] = sum_column($conn, 'delivery_order_assignments', 'collection_amount', $client_id, $where, $types, $params);

        $driverJoin = table_exists($conn, 'delivery_drivers') ? "LEFT JOIN delivery_drivers d ON d.id = a.driver_id" : '';
        $driverName = $driverJoin && table_has_column($conn, 'delivery_drivers', 'driver_name') ? 'd.driver_name' : "'-' AS driver_name";
        $distribution['recent'] = rows_value($conn, "SELECT a.id, a.status, a.delivery_date, a.collection_amount, $driverName FROM delivery_order_assignments a $driverJoin WHERE a.client_id = ? ORDER BY a.id DESC LIMIT 8", 'i', [$client_id]);
    }
}

/* ================= BOOKING / SERVICES STATS ================= */
$booking = [
    'table' => null,
    'total' => 0,
    'today' => 0,
    'upcoming' => 0,
    'month' => 0,
    'calendar' => [],
    'recent' => [],
];
$bookingTable = first_existing_table($conn, ['appointments', 'bookings', 'reservations']);
if ($client_industry === 'services' && $bookingTable) {
    $booking['table'] = $bookingTable;
    $dateCol = first_existing_column($conn, $bookingTable, ['appointment_date', 'booking_date', 'reservation_date', 'date', 'start_date', 'created_at']);
    $timeCol = first_existing_column($conn, $bookingTable, ['appointment_time', 'booking_time', 'time', 'start_time']);
    $statusCol = first_existing_column($conn, $bookingTable, ['status', 'booking_status', 'appointment_status']);
    $customerCol = first_existing_column($conn, $bookingTable, ['customer_name', 'name', 'client_name']);
    $titleCol = first_existing_column($conn, $bookingTable, ['title', 'service_name', 'purpose', 'description']);

    $booking['total'] = count_rows($conn, $bookingTable, $client_id);
    if ($dateCol) {
        $booking['today'] = count_rows($conn, $bookingTable, $client_id, " AND DATE(`$dateCol`) = CURDATE()");
        $booking['upcoming'] = count_rows($conn, $bookingTable, $client_id, " AND DATE(`$dateCol`) >= CURDATE()");
        $booking['month'] = count_rows($conn, $bookingTable, $client_id, " AND DATE(`$dateCol`) BETWEEN ? AND ?", 'ss', [$selected_month . '-01', date('Y-m-t', strtotime($selected_month . '-01'))]);
        $selectTime = $timeCol ? "`$timeCol` AS booking_time" : "NULL AS booking_time";
        $selectStatus = $statusCol ? "`$statusCol` AS booking_status" : "'booked' AS booking_status";
        $selectCustomer = $customerCol ? "`$customerCol` AS customer_name" : "'Customer' AS customer_name";
        $selectTitle = $titleCol ? "`$titleCol` AS booking_title" : "'Booking' AS booking_title";
        $booking['recent'] = rows_value($conn, "SELECT id, `$dateCol` AS booking_date, $selectTime, $selectStatus, $selectCustomer, $selectTitle FROM `$bookingTable` WHERE client_id = ? ORDER BY `$dateCol` ASC, id DESC LIMIT 8", 'i', [$client_id]);
    }
}

/* ================= PRODUCTION STATS ================= */
$production = [
    'formulas' => count_rows($conn, 'production_formulas', $client_id),
    'work_orders' => count_rows($conn, 'production_work_orders', $client_id),
    'active_batches' => count_rows($conn, 'production_batches', $client_id, table_exists($conn, 'production_batches') && table_has_column($conn, 'production_batches', 'status') ? " AND LOWER(status) IN ('active','running','in_progress','open')" : ''),
    'wastage' => count_rows($conn, 'production_wastage', $client_id),
    'wastage_value' => 0,
    'recent_work_orders' => [],
];
if ($client_industry === 'production') {
    if (table_exists($conn, 'production_wastage')) {
        foreach (['amount', 'total_cost', 'cost', 'value'] as $col) {
            if (table_has_column($conn, 'production_wastage', $col)) {
                $production['wastage_value'] = sum_column($conn, 'production_wastage', $col, $client_id);
                break;
            }
        }
    }
    if (table_exists($conn, 'production_work_orders') && table_has_column($conn, 'production_work_orders', 'client_id')) {
        $nameCol = first_existing_column($conn, 'production_work_orders', ['work_order_no', 'order_no', 'title', 'name']);
        $statusCol = first_existing_column($conn, 'production_work_orders', ['status']);
        $dateCol = first_existing_column($conn, 'production_work_orders', ['created_at', 'date', 'start_date']);
        $nameSql = $nameCol ? "`$nameCol` AS title" : "CONCAT('Work Order #', id) AS title";
        $statusSql = $statusCol ? "`$statusCol` AS status" : "'Open' AS status";
        $dateSql = $dateCol ? "`$dateCol` AS created_at" : "NULL AS created_at";
        $production['recent_work_orders'] = rows_value($conn, "SELECT id, $nameSql, $statusSql, $dateSql FROM production_work_orders WHERE client_id = ? ORDER BY id DESC LIMIT 8", 'i', [$client_id]);
    }
}

/* ================= REAL ESTATE STATS ================= */
$real = [
    'leads_table' => first_existing_table($conn, ['re_agency_leads', 'agency_leads', 'leads']),
    'followups_table' => first_existing_table($conn, ['re_followups', 'follow_ups', 'followups']),
    'visits_table' => first_existing_table($conn, ['re_site_visits', 'site_visits']),
    'properties_table' => first_existing_table($conn, ['re_properties', 'properties']),
    'deals_table' => first_existing_table($conn, ['re_deals', 'deals']),
    'leads' => 0,
    'followups' => 0,
    'visits' => 0,
    'properties' => 0,
    'deals' => 0,
    'recent_leads' => [],
];
if ($client_industry === 'real_estate') {
    if ($real['leads_table']) {
        $real['leads'] = count_rows($conn, $real['leads_table'], $client_id);
        $nameCol = first_existing_column($conn, $real['leads_table'], ['customer_name', 'name', 'lead_name', 'client_name']);
        $phoneCol = first_existing_column($conn, $real['leads_table'], ['phone', 'mobile', 'contact']);
        $statusCol = first_existing_column($conn, $real['leads_table'], ['status', 'lead_status']);
        $dateCol = first_existing_column($conn, $real['leads_table'], ['created_at', 'date']);
        $nameSql = $nameCol ? "`$nameCol` AS customer_name" : "'Lead' AS customer_name";
        $phoneSql = $phoneCol ? "`$phoneCol` AS phone" : "'-' AS phone";
        $statusSql = $statusCol ? "`$statusCol` AS status" : "'New' AS status";
        $dateSql = $dateCol ? "`$dateCol` AS created_at" : "NULL AS created_at";
        $real['recent_leads'] = rows_value($conn, "SELECT id, $nameSql, $phoneSql, $statusSql, $dateSql FROM `{$real['leads_table']}` WHERE client_id = ? ORDER BY id DESC LIMIT 8", 'i', [$client_id]);
    }
    if ($real['followups_table']) $real['followups'] = count_rows($conn, $real['followups_table'], $client_id);
    if ($real['visits_table']) $real['visits'] = count_rows($conn, $real['visits_table'], $client_id);
    if ($real['properties_table']) $real['properties'] = count_rows($conn, $real['properties_table'], $client_id);
    if ($real['deals_table']) $real['deals'] = count_rows($conn, $real['deals_table'], $client_id);
}

/* ================= CHART DATA ================= */
$sales_labels = [];
$sales_values = [];
$order_values = [];
if (table_exists($conn, 'sales') && $salesDateCol) {
    $start = new DateTime($filter_from);
    $end = new DateTime($filter_to);
    $end->modify('+1 day');
    $range = new DatePeriod($start, new DateInterval('P1D'), $end);
    $salesMap = [];
    $orderMap = [];
    foreach ($range as $dt) {
        $key = $dt->format('Y-m-d');
        $sales_labels[] = $dt->format('d M');
        $salesMap[$key] = 0;
        $orderMap[$key] = 0;
    }
    $chartRows = rows_value($conn, "SELECT DATE(`$salesDateCol`) AS d, COALESCE(SUM(total),0) AS total_sales, COUNT(id) AS total_orders FROM sales WHERE client_id = ? AND DATE(`$salesDateCol`) BETWEEN ? AND ? GROUP BY DATE(`$salesDateCol`) ORDER BY d ASC", 'iss', [$client_id, $filter_from, $filter_to]);
    foreach ($chartRows as $row) {
        $salesMap[$row['d']] = (float)$row['total_sales'];
        $orderMap[$row['d']] = (int)$row['total_orders'];
    }
    foreach ($salesMap as $d => $v) {
        $sales_values[] = $v;
        $order_values[] = $orderMap[$d] ?? 0;
    }
}
if (!$sales_labels) {
    $sales_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $sales_values = [0, 0, 0, 0, 0, 0, 0];
    $order_values = [0, 0, 0, 0, 0, 0, 0];
}

$industry_chart_labels = [];
$industry_chart_values = [];
switch ($client_industry) {
    case 'restaurant':
        $industry_chart_labels = ['Available', 'Occupied', 'Reserved', 'Open Bills'];
        $industry_chart_values = [$restaurant['available'], $restaurant['occupied'], $restaurant['reserved'], $restaurant['open_bills']];
        break;
    case 'production':
        $industry_chart_labels = ['Formulas', 'Work Orders', 'Batches', 'Wastage'];
        $industry_chart_values = [$production['formulas'], $production['work_orders'], $production['active_batches'], $production['wastage']];
        break;
    case 'distribution':
    case 'water':
        $industry_chart_labels = ['Assigned', 'Out', 'Delivered', 'Failed'];
        $industry_chart_values = [$distribution['assigned'], $distribution['out_for_delivery'], $distribution['delivered'], $distribution['failed']];
        break;
    case 'real_estate':
        $industry_chart_labels = ['Leads', 'Follow Ups', 'Visits', 'Deals'];
        $industry_chart_values = [$real['leads'], $real['followups'], $real['visits'], $real['deals']];
        break;
    case 'services':
        $industry_chart_labels = ['Total', 'Today', 'Upcoming', 'This Month'];
        $industry_chart_values = [$booking['total'], $booking['today'], $booking['upcoming'], $booking['month']];
        break;
    default:
        $industry_chart_labels = ['Orders', 'Customers', 'Products', 'Low Stock'];
        $industry_chart_values = [$total_orders, $total_customers, $total_items, $low_stock_count];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($pageTitle) ?> - ONEERP</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<script>
/*
|--------------------------------------------------------------------------
| DASHBOARD THEME INITIALIZER
|--------------------------------------------------------------------------
| POS aur Dashboard dono same saved theme use karenge.
|--------------------------------------------------------------------------
*/
(function () {
    const storageKey = 'oneerp_pos_theme';
    let dashboardTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

        if (savedTheme === 'light' || savedTheme === 'dark') {
            dashboardTheme = savedTheme;
        }
    } catch (error) {
        dashboardTheme = 'light';
    }

    document.documentElement.setAttribute(
        'data-pos-theme',
        dashboardTheme
    );
})();
</script>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<style>
:root{
    --sidebar-width:270px;
    --topbar-height:70px;
    --bg:#050816;
    --panel:rgba(15,23,42,.72);
    --panel2:rgba(17,24,39,.58);
    --border:rgba(148,163,184,.16);
    --text:#f8fafc;
    --muted:#94a3b8;
    --purple:#7c3aed;
    --green:#22c55e;
    --orange:#f59e0b;
    --red:#ef4444;
    --blue:#3b82f6;
}
html,body{margin:0;min-height:100%;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",Arial,sans-serif;overflow-x:hidden;}
.container-scroll{display:flex;min-height:100vh;width:100%;}
.sidebar,#dashboardSidebar{position:fixed;top:0;left:0;width:var(--sidebar-width);height:100vh;z-index:1040;background:#070b14;}
.main-panel{margin-left:var(--sidebar-width);width:calc(100% - var(--sidebar-width));min-height:100vh;background:transparent;}
.topbar{height:var(--topbar-height);position:sticky;top:0;z-index:1030;background:#070b14;border-bottom:1px solid var(--border);}
.content-wrapper{min-height:calc(100vh - var(--topbar-height));padding:24px;background:radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%);position:relative;}
.content-wrapper:before{content:"";position:absolute;inset:0;background:linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);background-size:44px 44px;opacity:.22;pointer-events:none;}
.content-wrapper>*{position:relative;z-index:1;}
.page-hero{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px;padding:18px 20px;border-radius:20px;background:radial-gradient(circle at 20% 0%, rgba(124,58,237,.24), transparent 36%),linear-gradient(135deg, rgba(15,23,42,.78), rgba(17,24,39,.58));border:1px solid var(--border);box-shadow:0 18px 48px rgba(0,0,0,.28);}
.hero-title{font-size:26px;font-weight:950;letter-spacing:-.03em;color:#fff;margin:0;display:flex;align-items:center;gap:10px;}
.hero-sub{color:#aab4cf;font-weight:750;font-size:13px;margin-top:4px;}
.industry-pill{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:rgba(124,58,237,.18);border:1px solid rgba(124,58,237,.35);color:#ddd6fe;font-weight:900;text-transform:capitalize;}
.quick-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center;}
.quick-tab,.filter-input,.filter-select{min-height:46px;border-radius:13px;border:1px solid var(--border);background:rgba(15,23,42,.78);color:#e5e7eb;text-decoration:none;padding:0 16px;font-weight:850;display:inline-flex;align-items:center;justify-content:center;gap:8px;}
.quick-tab:hover,.quick-tab.active{color:#fff;background:linear-gradient(135deg,#6d4cff,#7c3aed);border-color:rgba(139,92,246,.6);box-shadow:0 14px 28px rgba(124,58,237,.22);}
.filter-input,.filter-select{height:46px;}
.filter-input::placeholder{color:#94a3b8;}
.stats-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px;}
.stat-card{min-height:126px;padding:18px;border-radius:18px;color:#fff;position:relative;overflow:hidden;border:1px solid rgba(255,255,255,.12);box-shadow:0 18px 42px rgba(0,0,0,.28);transition:.22s ease;}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 24px 62px rgba(0,0,0,.42),0 0 42px rgba(124,58,237,.16);}
.stat-card:after{content:"";position:absolute;right:14px;top:14px;width:62px;height:62px;border-radius:18px;background:rgba(255,255,255,.12);}
.stat-card .icon{font-size:28px;margin-bottom:10px;position:relative;z-index:1;}
.stat-card .value{font-size:28px;font-weight:950;line-height:1.05;letter-spacing:-.03em;position:relative;z-index:1;}
.stat-card .label{font-size:14px;font-weight:850;color:rgba(255,255,255,.9);margin-top:5px;position:relative;z-index:1;}
.stat-card .sub{font-size:12px;font-weight:800;color:rgba(255,255,255,.82);margin-top:8px;position:relative;z-index:1;}
.stat-purple{background:linear-gradient(135deg,#2563eb,#6d4cff);} .stat-violet{background:linear-gradient(135deg,#6d4cff,#8b5cf6);} .stat-green{background:linear-gradient(135deg,#047857,#16a34a);} .stat-orange{background:linear-gradient(135deg,#f59e0b,#fb923c);} .stat-red{background:linear-gradient(135deg,#be123c,#ef4444);} .stat-teal{background:linear-gradient(135deg,#0f766e,#06b6d4);}
.grid-2{display:grid;grid-template-columns:1.25fr 1fr;gap:14px;margin-bottom:16px;}
.grid-3{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:14px;margin-bottom:16px;}
.card-glass,.table-card{background:radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.54));border:1px solid var(--border);border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.25);backdrop-filter:blur(18px);overflow:hidden;}
.card-head,.table-title{padding:16px 18px;border-bottom:1px solid rgba(148,163,184,.13);font-size:16px;font-weight:950;color:#fff;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;}
.card-body-custom{padding:16px 18px;}
.chart-wrap{height:250px;}
.table{margin:0;color:#e5e7eb;--bs-table-bg:transparent;--bs-table-color:#e5e7eb;--bs-table-border-color:rgba(148,163,184,.10);}
.table thead th{background:rgba(7,11,20,.56)!important;color:#cbd5e1!important;border-bottom:1px solid rgba(148,163,184,.14)!important;font-size:12px!important;font-weight:950!important;text-transform:uppercase;letter-spacing:.04em;padding:13px 14px;white-space:nowrap;}
.table tbody td{background:rgba(15,23,42,.22)!important;color:#e5e7eb!important;border-bottom:1px solid rgba(148,163,184,.09)!important;font-size:13px;font-weight:700;padding:13px 14px;vertical-align:middle;}
.table tbody tr:hover td{background:rgba(124,58,237,.12)!important;color:#fff!important;}
.badge-soft{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900;background:rgba(124,58,237,.16);color:#ddd6fe;border:1px solid rgba(124,58,237,.28);text-transform:capitalize;}
.empty-state{padding:28px 16px;text-align:center;color:#94a3b8;font-weight:850;}
.product-row,.activity-row{display:grid;grid-template-columns:44px 1fr auto;gap:12px;align-items:center;padding:11px 0;border-bottom:1px solid rgba(148,163,184,.10);}
.product-row:last-child,.activity-row:last-child{border-bottom:none;}
.product-thumb{width:44px;height:44px;border-radius:12px;object-fit:cover;background:#111827;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;}
.name{font-weight:900;color:#fff;font-size:14px;}.meta{font-size:12px;color:#94a3b8;font-weight:750;margin-top:3px;}.amount{font-weight:950;color:#fff;white-space:nowrap;}
.action-link{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 14px;border-radius:11px;background:linear-gradient(135deg,#6d4cff,#7c3aed);color:#fff;text-decoration:none;font-weight:900;border:1px solid rgba(139,92,246,.45);}
@media(max-width:1200px){.stats-row{grid-template-columns:repeat(2,minmax(0,1fr));}.grid-2,.grid-3{grid-template-columns:1fr;}}
@media(max-width:991px){.sidebar,#dashboardSidebar{transform:translateX(-100%);transition:.25s ease;z-index:3000;}.main-panel{margin-left:0;width:100%;}.content-wrapper{padding:14px;}body.sidebar-open #dashboardSidebar{transform:translateX(0);}body.sidebar-open:before{content:"";position:fixed;inset:0;background:rgba(0,0,0,.58);z-index:2990;}.topbar{height:62px;}.hero-title{font-size:22px;}.quick-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));}.quick-tab,.filter-input,.filter-select{width:100%;}.chart-wrap{height:220px;}.table{min-width:680px;}.table-responsive{overflow-x:auto;}}
@media(max-width:576px){.stats-row,.quick-tabs{grid-template-columns:1fr;}.stat-card{min-height:108px;}.stat-card .value{font-size:23px;}.grid-2,.grid-3{gap:12px;}.product-row,.activity-row{grid-template-columns:40px 1fr;}.amount{grid-column:2;white-space:normal;}}


/* =========================================================
   ONEERP DASHBOARD LIGHT MODE
   Sirf light mode ke liye.
   Dark mode aur backend functionality unchanged.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* Main page */
html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] .container-scroll,
html[data-pos-theme="light"] .main-panel{
    background:#f3f6fb !important;
}

/* Sidebar and topbar */
html[data-pos-theme="light"] .sidebar,
html[data-pos-theme="light"] #dashboardSidebar{
    background:#ffffff !important;
    border-right:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] .topbar{
    background:#ffffff !important;
    border-bottom:1px solid #e2e8f0 !important;
    box-shadow:0 4px 18px rgba(15,23,42,.06) !important;
}

/* Dashboard main background */
html[data-pos-theme="light"] .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.10),
            transparent 28%
        ),
        radial-gradient(
            circle at 78% 2%,
            rgba(59,130,246,.07),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 42%,
            #faf7ff 72%,
            #f8fafc 100%
        ) !important;
}

/* Background grid lines */
html[data-pos-theme="light"] .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.035) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ) !important;
    opacity:.65 !important;
}

/* Dashboard header */
html[data-pos-theme="light"] .page-hero{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.10),
            transparent 36%
        ),
        linear-gradient(
            135deg,
            #ffffff,
            #f8fafc
        ) !important;
    border-color:#dbe3ee !important;
    box-shadow:0 14px 36px rgba(15,23,42,.08) !important;
}

html[data-pos-theme="light"] .hero-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] .hero-sub{
    color:#475569 !important;
}

html[data-pos-theme="light"] .industry-pill{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Quick filter buttons */
html[data-pos-theme="light"] .quick-tab,
html[data-pos-theme="light"] .filter-input,
html[data-pos-theme="light"] .filter-select{
    background:#ffffff !important;
    color:#1e293b !important;
    border-color:#d7dfeb !important;
    box-shadow:0 4px 12px rgba(15,23,42,.04) !important;
}

html[data-pos-theme="light"] .filter-input{
    color-scheme:light !important;
}

html[data-pos-theme="light"] .quick-tab:hover,
html[data-pos-theme="light"] .quick-tab.active{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
    box-shadow:0 12px 24px rgba(124,58,237,.20) !important;
}

/* Main dashboard cards */
html[data-pos-theme="light"] .card-glass,
html[data-pos-theme="light"] .table-card{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.055),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dde5ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
}

/* Card headings */
html[data-pos-theme="light"] .card-head,
html[data-pos-theme="light"] .table-title{
    background:#ffffff !important;
    color:#0f172a !important;
    border-bottom-color:#e2e8f0 !important;
}

/* Card body */
html[data-pos-theme="light"] .card-body-custom{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Tables */
html[data-pos-theme="light"] .table{
    color:#1e293b !important;
    --bs-table-bg:transparent;
    --bs-table-color:#1e293b;
    --bs-table-border-color:#e2e8f0;
}

html[data-pos-theme="light"] .table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom-color:#d8e0eb !important;
}

html[data-pos-theme="light"] .table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-bottom-color:#e8edf4 !important;
}

html[data-pos-theme="light"] .table tbody tr:hover td{
    background:#f5f1ff !important;
    color:#0f172a !important;
}

/* Product and activity lists */
html[data-pos-theme="light"] .product-row,
html[data-pos-theme="light"] .activity-row{
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] .product-thumb{
    background:#f1f5f9 !important;
    border-color:#dce4ef !important;
}

html[data-pos-theme="light"] .name,
html[data-pos-theme="light"] .amount{
    color:#0f172a !important;
}

html[data-pos-theme="light"] .meta{
    color:#64748b !important;
}

/* Badges */
html[data-pos-theme="light"] .badge-soft{
    background:#f1ebff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Empty state */
html[data-pos-theme="light"] .empty-state{
    color:#64748b !important;
}

/* Purple action buttons stay branded */
html[data-pos-theme="light"] .action-link{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
}

/*
|--------------------------------------------------------------------------
| Colored statistic cards
|--------------------------------------------------------------------------
| In cards ko colored hi rakhenge aur text white rahega.
|--------------------------------------------------------------------------
*/
html[data-pos-theme="light"] .stat-card{
    color:#ffffff !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] .stat-card .value,
html[data-pos-theme="light"] .stat-card .label,
html[data-pos-theme="light"] .stat-card .sub,
html[data-pos-theme="light"] .stat-card .icon{
    color:#ffffff !important;
}

/* Form text */
html[data-pos-theme="light"] input,
html[data-pos-theme="light"] select,
html[data-pos-theme="light"] button{
    opacity:1 !important;
}

html[data-pos-theme="light"] .filter-input::placeholder{
    color:#64748b !important;
}
</style>
</head>
<body>
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">
            <div class="page-hero">
                <div>
                    <h1 class="hero-title"><i class="bi <?= e($pageIcon) ?>"></i><?= e($pageTitle) ?></h1>
                    <div class="hero-sub"><?= e($pageSubtitle) ?></div>
                </div>
                <div class="industry-pill"><i class="bi bi-stars"></i><?= e(str_replace('_', ' ', $client_industry)) ?></div>
            </div>

            <div class="quick-tabs">
                <a class="quick-tab <?= $period === 'today' ? 'active' : '' ?>" href="?period=today"><i class="bi bi-calendar-day"></i>Today</a>
                <a class="quick-tab <?= $period === 'yesterday' ? 'active' : '' ?>" href="?period=yesterday"><i class="bi bi-calendar-event"></i>Yesterday</a>
                <a class="quick-tab <?= $period === 'this_week' ? 'active' : '' ?>" href="?period=this_week"><i class="bi bi-calendar-week"></i>This Week</a>
                <a class="quick-tab <?= $period === 'this_month' ? 'active' : '' ?>" href="?period=this_month&month=<?= e($selected_month) ?>"><i class="bi bi-calendar3"></i>This Month</a>
                <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
                    <input type="hidden" name="period" value="date_range">
                    <input class="filter-input" type="date" name="from_date" value="<?= e($range_from) ?>">
                    <input class="filter-input" type="date" name="to_date" value="<?= e($range_to) ?>">
                    <button class="quick-tab <?= $period === 'date_range' ? 'active' : '' ?>" type="submit"><i class="bi bi-funnel"></i>Apply</button>
                </form>
            </div>

            <?php if ($client_industry === 'restaurant'): ?>
                <div class="stats-row">
                    <div class="stat-card stat-purple"><div class="icon"><i class="bi bi-grid-3x3-gap"></i></div><div class="value"><?= number_format($restaurant['tables_total']) ?></div><div class="label">Active Tables</div><div class="sub">Restaurant floor setup</div></div>
                    <div class="stat-card stat-green"><div class="icon"><i class="bi bi-check2-circle"></i></div><div class="value"><?= number_format($restaurant['available']) ?></div><div class="label">Available Tables</div><div class="sub">Ready for guests</div></div>
                    <div class="stat-card stat-orange"><div class="icon"><i class="bi bi-receipt"></i></div><div class="value"><?= number_format($restaurant['open_bills']) ?></div><div class="label">Open Bills</div><div class="sub">Running table sessions</div></div>
                    <div class="stat-card stat-violet"><div class="icon"><i class="bi bi-cash-stack"></i></div><div class="value">PKR <?= number_format($restaurant['running_total'], 0) ?></div><div class="label">Running Amount</div><div class="sub">Current open sessions</div></div>
                </div>

                <div class="grid-2">
                    <div class="card-glass">
                        <div class="card-head">Restaurant Table Status</div>
                        <div class="card-body-custom"><div class="chart-wrap"><canvas id="industryChart"></canvas></div></div>
                    </div>
                    <div class="card-glass">
                        <div class="card-head">Today Sales Overview</div>
                        <div class="card-body-custom"><div class="chart-wrap"><canvas id="salesOverviewChart"></canvas></div></div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="table-card">
                        <div class="table-title"><span>Recent Table Sessions</span><a href="restaurant_orders.php" class="action-link">Open Orders</a></div>
                        <div class="table-responsive"><table class="table"><thead><tr><th>Session</th><th>Table</th><th>Status</th><th>Guests</th><th>Amount</th><th>Started</th></tr></thead><tbody>
                        <?php if ($restaurant['recent_sessions']): foreach ($restaurant['recent_sessions'] as $row): ?>
                            <tr><td>#<?= (int)$row['id'] ?></td><td><?= e($row['table_name'] ?? '-') ?></td><td><span class="badge-soft"><?= e($row['status'] ?? 'open') ?></span></td><td><?= number_format((int)($row['guest_count'] ?? 0)) ?></td><td>PKR <?= number_format((float)($row['grand_total'] ?? 0), 0) ?></td><td><?= e(format_safe_date($row['started_at'] ?? null, 'd M, g:i A')) ?></td></tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="empty-state">No restaurant sessions found</td></tr>
                        <?php endif; ?>
                        </tbody></table></div>
                    </div>
                    <div class="table-card">
                        <div class="table-title"><span>Tables Quick View</span><a href="restaurant_tables.php" class="action-link">Manage Tables</a></div>
                        <div class="table-responsive"><table class="table"><thead><tr><th>Table</th><th>Floor</th><th>Seats</th><th>Status</th></tr></thead><tbody>
                        <?php if ($restaurant['table_rows']): foreach ($restaurant['table_rows'] as $row): ?>
                            <tr><td><?= e($row['table_name'] ?: ('Table ' . $row['table_no'])) ?></td><td><?= e($row['floor_name'] ?: '-') ?></td><td><?= number_format((int)($row['capacity'] ?? 0)) ?></td><td><span class="badge-soft"><?= e($row['status'] ?? 'Available') ?></span></td></tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="4" class="empty-state">No restaurant tables found</td></tr>
                        <?php endif; ?>
                        </tbody></table></div>
                    </div>
                </div>

            <?php elseif ($client_industry === 'production'): ?>
                <div class="stats-row">
                    <div class="stat-card stat-purple"><div class="icon"><i class="bi bi-diagram-3"></i></div><div class="value"><?= number_format($production['formulas']) ?></div><div class="label">Formulas / BOM</div><div class="sub">Production recipes</div></div>
                    <div class="stat-card stat-green"><div class="icon"><i class="bi bi-clipboard-check"></i></div><div class="value"><?= number_format($production['work_orders']) ?></div><div class="label">Work Orders</div><div class="sub">Total production orders</div></div>
                    <div class="stat-card stat-orange"><div class="icon"><i class="bi bi-boxes"></i></div><div class="value"><?= number_format($production['active_batches']) ?></div><div class="label">Active Batches</div><div class="sub">Running jobs</div></div>
                    <div class="stat-card stat-red"><div class="icon"><i class="bi bi-trash3"></i></div><div class="value">PKR <?= number_format($production['wastage_value'], 0) ?></div><div class="label">Wastage Value</div><div class="sub"><?= number_format($production['wastage']) ?> wastage entries</div></div>
                </div>
                <div class="grid-2">
                    <div class="card-glass"><div class="card-head">Production Summary</div><div class="card-body-custom"><div class="chart-wrap"><canvas id="industryChart"></canvas></div></div></div>
                    <div class="table-card"><div class="table-title"><span>Recent Work Orders</span><a href="production_work_orders.php" class="action-link">Open Production</a></div><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Work Order</th><th>Status</th><th>Date</th></tr></thead><tbody>
                    <?php if ($production['recent_work_orders']): foreach ($production['recent_work_orders'] as $row): ?>
                        <tr><td>#<?= (int)$row['id'] ?></td><td><?= e($row['title'] ?? '-') ?></td><td><span class="badge-soft"><?= e($row['status'] ?? 'Open') ?></span></td><td><?= e(format_safe_date($row['created_at'] ?? null)) ?></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="4" class="empty-state">No work orders found</td></tr><?php endif; ?>
                    </tbody></table></div></div>
                </div>

            <?php elseif (in_array($client_industry, ['distribution', 'water'], true)): ?>
                <div class="stats-row">
                    <div class="stat-card stat-purple"><div class="icon"><i class="bi bi-person-check"></i></div><div class="value"><?= number_format($distribution['assigned']) ?></div><div class="label">Assigned Orders</div><div class="sub"><?= e($filter_label) ?></div></div>
                    <div class="stat-card stat-orange"><div class="icon"><i class="bi bi-truck-front"></i></div><div class="value"><?= number_format($distribution['out_for_delivery']) ?></div><div class="label">Out For Delivery</div><div class="sub">In progress</div></div>
                    <div class="stat-card stat-green"><div class="icon"><i class="bi bi-check2-circle"></i></div><div class="value"><?= number_format($distribution['delivered']) ?></div><div class="label">Delivered</div><div class="sub"><?= e($filter_label) ?></div></div>
                    <div class="stat-card stat-violet"><div class="icon"><i class="bi bi-cash-stack"></i></div><div class="value">PKR <?= number_format($distribution['collections'], 0) ?></div><div class="label">Collections</div><div class="sub"><?= number_format($distribution['drivers']) ?> active drivers</div></div>
                </div>
                <div class="grid-2">
                    <div class="card-glass"><div class="card-head"><?= $client_industry === 'water' ? 'Water Plant Delivery Summary' : 'Distribution Summary' ?></div><div class="card-body-custom"><div class="chart-wrap"><canvas id="industryChart"></canvas></div></div></div>
                    <div class="table-card"><div class="table-title"><span>Recent Delivery Orders</span><a href="distribution_orders.php" class="action-link">Open Delivery</a></div><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Driver</th><th>Status</th><th>Collection</th><th>Date</th></tr></thead><tbody>
                    <?php if ($distribution['recent']): foreach ($distribution['recent'] as $row): ?>
                        <tr><td>#<?= (int)$row['id'] ?></td><td><?= e($row['driver_name'] ?? '-') ?></td><td><span class="badge-soft"><?= e(str_replace('_', ' ', $row['status'] ?? 'assigned')) ?></span></td><td>PKR <?= number_format((float)($row['collection_amount'] ?? 0), 0) ?></td><td><?= e(format_safe_date($row['delivery_date'] ?? null)) ?></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="5" class="empty-state">No delivery orders found</td></tr><?php endif; ?>
                    </tbody></table></div></div>
                </div>

            <?php elseif ($client_industry === 'real_estate'): ?>
                <div class="stats-row">
                    <div class="stat-card stat-purple"><div class="icon"><i class="bi bi-person-lines-fill"></i></div><div class="value"><?= number_format($real['leads']) ?></div><div class="label">Leads</div><div class="sub">Agency pipeline</div></div>
                    <div class="stat-card stat-orange"><div class="icon"><i class="bi bi-telephone-outbound"></i></div><div class="value"><?= number_format($real['followups']) ?></div><div class="label">Follow Ups</div><div class="sub">Client communication</div></div>
                    <div class="stat-card stat-green"><div class="icon"><i class="bi bi-geo-alt"></i></div><div class="value"><?= number_format($real['visits']) ?></div><div class="label">Site Visits</div><div class="sub">Scheduled visits</div></div>
                    <div class="stat-card stat-violet"><div class="icon"><i class="bi bi-house-check"></i></div><div class="value"><?= number_format($real['deals']) ?></div><div class="label">Deals</div><div class="sub"><?= number_format($real['properties']) ?> properties</div></div>
                </div>
                <div class="grid-2">
                    <div class="card-glass"><div class="card-head">Real Estate Pipeline</div><div class="card-body-custom"><div class="chart-wrap"><canvas id="industryChart"></canvas></div></div></div>
                    <div class="table-card"><div class="table-title">Recent Leads</div><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Lead</th><th>Phone</th><th>Status</th><th>Date</th></tr></thead><tbody>
                    <?php if ($real['recent_leads']): foreach ($real['recent_leads'] as $row): ?>
                        <tr><td>#<?= (int)$row['id'] ?></td><td><?= e($row['customer_name'] ?? 'Lead') ?></td><td><?= e($row['phone'] ?? '-') ?></td><td><span class="badge-soft"><?= e($row['status'] ?? 'New') ?></span></td><td><?= e(format_safe_date($row['created_at'] ?? null)) ?></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="5" class="empty-state">No leads found</td></tr><?php endif; ?>
                    </tbody></table></div></div>
                </div>

            <?php elseif ($client_industry === 'services'): ?>
                <div class="stats-row">
                    <div class="stat-card stat-purple"><div class="icon"><i class="bi bi-calendar-check"></i></div><div class="value"><?= number_format($booking['total']) ?></div><div class="label">Total Bookings</div><div class="sub">All appointments</div></div>
                    <div class="stat-card stat-orange"><div class="icon"><i class="bi bi-clock"></i></div><div class="value"><?= number_format($booking['today']) ?></div><div class="label">Today Bookings</div><div class="sub"><?= e(date('d M Y')) ?></div></div>
                    <div class="stat-card stat-green"><div class="icon"><i class="bi bi-calendar-event"></i></div><div class="value"><?= number_format($booking['upcoming']) ?></div><div class="label">Upcoming</div><div class="sub">From today onward</div></div>
                    <div class="stat-card stat-violet"><div class="icon"><i class="bi bi-calendar3"></i></div><div class="value"><?= number_format($booking['month']) ?></div><div class="label">This Month</div><div class="sub"><?= e(date('F Y', strtotime($selected_month . '-01'))) ?></div></div>
                </div>
                <div class="grid-2">
                    <div class="card-glass"><div class="card-head">Booking Summary</div><div class="card-body-custom"><div class="chart-wrap"><canvas id="industryChart"></canvas></div></div></div>
                    <div class="table-card"><div class="table-title"><span>Upcoming Appointments</span><a href="appointments.php" class="action-link">Open Appointments</a></div><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Time</th><th>Customer</th><th>Booking</th><th>Status</th></tr></thead><tbody>
                    <?php if ($booking['recent']): foreach ($booking['recent'] as $row): ?>
                        <tr><td><?= e(format_safe_date($row['booking_date'] ?? null)) ?></td><td><?= e(!empty($row['booking_time']) ? date('g:i A', strtotime($row['booking_time'])) : 'N/A') ?></td><td><?= e($row['customer_name'] ?? 'Customer') ?></td><td><?= e($row['booking_title'] ?? 'Booking') ?></td><td><span class="badge-soft"><?= e($row['booking_status'] ?? 'booked') ?></span></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="5" class="empty-state">No appointments found</td></tr><?php endif; ?>
                    </tbody></table></div></div>
                </div>

            <?php else: ?>
                <div class="stats-row">
                    <div class="stat-card stat-purple"><div class="icon"><i class="bi bi-person"></i></div><div class="value"><?= number_format($total_customers) ?></div><div class="label">Total Customers</div><div class="sub">Customer database</div></div>
                    <div class="stat-card stat-violet"><div class="icon"><i class="bi bi-cart"></i></div><div class="value"><?= number_format($total_orders) ?></div><div class="label">Total Orders</div><div class="sub">+<?= number_format($period_orders) ?> <?= e($filter_label) ?></div></div>
                    <div class="stat-card stat-green"><div class="icon"><i class="bi bi-cash-stack"></i></div><div class="value">PKR <?= number_format($total_sales, 0) ?></div><div class="label">Total Sales</div><div class="sub">+PKR <?= number_format($period_sales, 0) ?> <?= e($filter_label) ?></div></div>
                    <div class="stat-card stat-orange"><div class="icon"><i class="bi bi-box-seam"></i></div><div class="value"><?= number_format($total_items) ?></div><div class="label">Products</div><div class="sub"><?= number_format($low_stock_count) ?> low stock</div></div>
                </div>
                <div class="grid-3">
                    <div class="card-glass"><div class="card-head">Sales Overview</div><div class="card-body-custom"><div class="chart-wrap"><canvas id="salesOverviewChart"></canvas></div></div></div>
                    <div class="card-glass"><div class="card-head">POS Summary</div><div class="card-body-custom"><div class="chart-wrap"><canvas id="industryChart"></canvas></div></div></div>
                    <div class="card-glass"><div class="card-head">Top Selling Products</div><div class="card-body-custom">
                        <?php if ($top_products): foreach ($top_products as $p): ?>
                            <div class="product-row">
                                <?php if (!empty($p['image'])): ?><img src="<?= e(item_image_url($p['image'])) ?>" class="product-thumb" alt="product"><?php else: ?><div class="product-thumb"><i class="bi bi-box-seam text-secondary"></i></div><?php endif; ?>
                                <div><div class="name"><?= e($p['name'] ?? 'Product') ?></div><div class="meta"><?= number_format((float)($p['total_units'] ?? 0)) ?> units</div></div>
                                <div class="amount">PKR <?= number_format((float)($p['total_amount'] ?? 0), 0) ?></div>
                            </div>
                        <?php endforeach; else: ?><div class="empty-state">No top selling products found</div><?php endif; ?>
                    </div></div>
                </div>
            <?php endif; ?>

            <div class="grid-2">
                <div class="table-card">
                    <div class="table-title"><span>Recent Orders</span><a href="orders.php" class="action-link">View All</a></div>
                    <div class="table-responsive"><table class="table"><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Amount</th></tr></thead><tbody>
                        <?php if ($recent_orders): foreach ($recent_orders as $order): ?>
                            <tr><td>#<?= e($order['invoice_no'] ?: $order['id']) ?></td><td><?= e($order['customer_name'] ?: 'Walk-in Customer') ?></td><td><?= e(format_safe_date($order['created_at'] ?? null)) ?></td><td>PKR <?= number_format((float)($order['total'] ?? 0), 0) ?></td></tr>
                        <?php endforeach; else: ?><tr><td colspan="4" class="empty-state">No recent orders found</td></tr><?php endif; ?>
                    </tbody></table></div>
                </div>

                <div class="table-card">
                    <div class="table-title">
                        <span>Expenses</span>
                        <?php if ($branches): ?>
                        <form method="get" class="d-flex gap-2 align-items-center">
                            <input type="hidden" name="period" value="<?= e($period) ?>">
                            <select class="filter-select" name="branch_id" onchange="this.form.submit()">
                                <option value="all">All Branches</option>
                                <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (string)$selected_branch === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
                            </select>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Branch</th><th>Description</th><th>Amount</th><th>Date</th></tr></thead><tbody>
                        <?php if ($expenses): foreach ($expenses as $i => $row): ?>
                            <tr><td><?= $i + 1 ?></td><td><?= e($row['branch_name'] ?? 'Main Branch') ?></td><td><?= e($row['description'] ?? '-') ?></td><td>PKR <?= number_format((float)($row['amount'] ?? 0), 2) ?></td><td><?= e(format_safe_date($row['created_at'] ?? null)) ?></td></tr>
                        <?php endforeach; else: ?><tr><td colspan="5" class="empty-state">No expenses found</td></tr><?php endif; ?>
                    </tbody></table></div>
                </div>
            </div>

            <?php if ($recent_items): ?>
            <div class="table-card">
                <div class="table-title">Recent Items / Services</div>
                <div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Name</th><th>SKU</th><th>Type</th><th>Price</th></tr></thead><tbody>
                    <?php foreach ($recent_items as $i => $item): ?>
                        <tr><td><?= $i + 1 ?></td><td><?= e($item['name'] ?? '-') ?></td><td><?= e($item['sku'] ?? '-') ?></td><td><?= e($item['type'] ?? '-') ?></td><td>PKR <?= number_format((float)($item['price'] ?? 0), 2) ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const salesLabels = <?= json_encode($sales_labels) ?>;
const salesData = <?= json_encode($sales_values) ?>;
const orderData = <?= json_encode($order_values) ?>;
const industryLabels = <?= json_encode($industry_chart_labels) ?>;
const industryValues = <?= json_encode($industry_chart_values) ?>;
/*
|--------------------------------------------------------------------------
| DASHBOARD CHART THEME
|--------------------------------------------------------------------------
*/

function getDashboardChartTheme() {
    const isLight =
        document.documentElement.getAttribute('data-pos-theme') === 'light';

    return {
        text: isLight ? '#1e293b' : '#e5e7eb',
        muted: isLight ? '#64748b' : '#94a3b8',
        grid: isLight
            ? 'rgba(15,23,42,.10)'
            : 'rgba(148,163,184,.12)'
    };
}

let salesOverviewChartInstance = null;
let industryChartInstance = null;

const initialChartTheme = getDashboardChartTheme();


/* Sales overview chart */
const salesCtx = document.getElementById('salesOverviewChart');

if (salesCtx) {
    salesOverviewChartInstance = new Chart(salesCtx, {
        type: 'bar',

        data: {
            labels: salesLabels,

            datasets: [
                {
                    type: 'bar',
                    label: 'Sales',
                    data: salesData,
                    backgroundColor: 'rgba(245,158,11,.85)',
                    borderRadius: 8
                },
                {
                    type: 'line',
                    label: 'Orders',
                    data: orderData,
                    borderColor: '#60a5fa',
                    backgroundColor: '#60a5fa',
                    tension: .38,
                    fill: false,
                    pointRadius: 3
                }
            ]
        },

        options: {
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    labels: {
                        color: initialChartTheme.text
                    }
                }
            },

            scales: {
                x: {
                    ticks: {
                        color: initialChartTheme.muted
                    },

                    grid: {
                        display: false
                    }
                },

                y: {
                    ticks: {
                        color: initialChartTheme.muted
                    },

                    grid: {
                        color: initialChartTheme.grid
                    },

                    beginAtZero: true
                }
            }
        }
    });
}


/* Industry summary chart */
const industryCtx = document.getElementById('industryChart');

if (industryCtx) {
    industryChartInstance = new Chart(industryCtx, {
        type: 'doughnut',

        data: {
            labels: industryLabels,

            datasets: [
                {
                    data: industryValues,

                    backgroundColor: [
                        '#7c3aed',
                        '#22c55e',
                        '#f59e0b',
                        '#ef4444',
                        '#3b82f6'
                    ],

                    borderWidth: 0
                }
            ]
        },

        options: {
            maintainAspectRatio: false,
            cutout: '45%',

            plugins: {
                legend: {
                    position: 'bottom',

                    labels: {
                        color: initialChartTheme.text
                    }
                }
            }
        }
    });
}


/*
|--------------------------------------------------------------------------
| LIVE CHART THEME UPDATE
|--------------------------------------------------------------------------
| Moon / Sun button click par page reload ke baghair chart colors change honge.
|--------------------------------------------------------------------------
*/
function updateDashboardChartTheme() {
    const chartTheme = getDashboardChartTheme();

    if (salesOverviewChartInstance) {
        salesOverviewChartInstance.options.plugins.legend.labels.color =
            chartTheme.text;

        salesOverviewChartInstance.options.scales.x.ticks.color =
            chartTheme.muted;

        salesOverviewChartInstance.options.scales.y.ticks.color =
            chartTheme.muted;

        salesOverviewChartInstance.options.scales.y.grid.color =
            chartTheme.grid;

        salesOverviewChartInstance.update();
    }

    if (industryChartInstance) {
        industryChartInstance.options.plugins.legend.labels.color =
            chartTheme.text;

        industryChartInstance.update();
    }
}


/* data-pos-theme change observe karega */
const dashboardThemeObserver = new MutationObserver(function (mutations) {
    for (const mutation of mutations) {
        if (mutation.attributeName === 'data-pos-theme') {
            updateDashboardChartTheme();
            break;
        }
    }
});

dashboardThemeObserver.observe(
    document.documentElement,
    {
        attributes: true,
        attributeFilter: ['data-pos-theme']
    }
);

document.addEventListener('DOMContentLoaded', function () {
    const toggleSelectors = ['#sidebarToggle','#toggleSidebar','.sidebar-toggle','.sidebar-toggler','.topbar-menu-toggle','.hamburger-btn','[data-sidebar-toggle]'];
    let toggleBtn = null;
    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            if (window.innerWidth <= 991) {
                e.preventDefault();
                document.body.classList.toggle('sidebar-open');
            }
        });
    }
    document.addEventListener('click', function (e) {
        if (window.innerWidth > 991) return;
        const sidebar = document.getElementById('dashboardSidebar');
        const isToggle = e.target.closest(toggleSelectors.join(','));
        if (document.body.classList.contains('sidebar-open') && sidebar && !sidebar.contains(e.target) && !isToggle) {
            document.body.classList.remove('sidebar-open');
        }
    });
});
</script>
</body>
</html>
