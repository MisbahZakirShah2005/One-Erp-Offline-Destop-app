<?php
/**
 * ONEERP Restaurant Payment
 * Drop this file in the same folder where restaurant_orders.php / restaurant_bill_print.php exists.
 * URL: restaurant_payment.php?session_id=123
 */
session_start();

if (file_exists(__DIR__ . '/partials/performance.php')) {
    include(__DIR__ . '/partials/performance.php');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found. Please check config/db.php and make sure it creates mysqli variable named $conn.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['selected_branch_id'] ?? 0));

if ($client_id <= 0 && $role_id !== 1) {
    die('Client not found in session.');
}

/* ================= COMMON HELPERS ================= */
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($amount): string { return 'Rs. ' . number_format((float)$amount, 2); }
function qty_fmt($qty): string { $qty = (float)$qty; return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'); }

function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    if (!table_exists($conn, $table)) return false;
    $stmt = $conn->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function bind_params_ref(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $refs = [$types];
    foreach ($params as $k => &$v) $refs[] = &$v;
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function safe_alter(mysqli $conn, string $sql): void {
    try { $conn->query($sql); } catch (Throwable $ignore) {}
}

function ensure_payment_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS restaurant_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id INT NOT NULL,
        branch_id INT DEFAULT NULL,
        session_id INT NOT NULL,
        table_id INT DEFAULT NULL,
        receipt_no VARCHAR(50) NOT NULL,
        payment_method VARCHAR(30) NOT NULL,
        amount_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        amount_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        change_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        tip_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        card_last4 VARCHAR(10) DEFAULT NULL,
        transaction_ref VARCHAR(120) DEFAULT NULL,
        split_details JSON DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'paid',
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_restaurant_payments_receipt (client_id, receipt_no),
        KEY idx_restaurant_payments_session (session_id),
        KEY idx_restaurant_payments_client_date (client_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (table_exists($conn, 'restaurant_table_sessions')) {
        $adds = [
            'payment_method'    => "ALTER TABLE restaurant_table_sessions ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL",
            'payment_status'    => "ALTER TABLE restaurant_table_sessions ADD COLUMN payment_status VARCHAR(30) DEFAULT NULL",
            'payment_reference' => "ALTER TABLE restaurant_table_sessions ADD COLUMN payment_reference VARCHAR(120) DEFAULT NULL",
            'receipt_no'        => "ALTER TABLE restaurant_table_sessions ADD COLUMN receipt_no VARCHAR(50) DEFAULT NULL",
            'paid_at'           => "ALTER TABLE restaurant_table_sessions ADD COLUMN paid_at DATETIME DEFAULT NULL"
        ];
        foreach ($adds as $col => $sql) {
            if (!column_exists($conn, 'restaurant_table_sessions', $col)) safe_alter($conn, $sql);
        }
    }
}

function client_tax(mysqli $conn, int $client_id): float {
    if (!table_exists($conn, 'taxes')) return 0.0;
    $stmt = $conn->prepare("SELECT COALESCE(MAX(rate),0) rate FROM taxes WHERE client_id=? AND status='active'");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['rate'] ?? 0);
}

function setting_value(mysqli $conn, int $client_id, int $branch_id, array $keys, $default = 0) {
    if (!table_exists($conn, 'restaurant_settings') || empty($keys)) return $default;
    $in = implode(',', array_fill(0, count($keys), '?'));
    $types = 'ii' . str_repeat('s', count($keys));
    $params = array_merge([$client_id, $branch_id], $keys);
    $sql = "SELECT setting_value FROM restaurant_settings WHERE client_id=? AND branch_id IN (0,?) AND setting_key IN ($in) ORDER BY branch_id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    bind_params_ref($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $default;
    return is_numeric($row['setting_value']) ? (float)$row['setting_value'] : $row['setting_value'];
}

function log_activity(mysqli $conn, int $client_id, int $branch_id, int $session_id, ?int $table_id, string $type, string $details, int $user_id): void {
    if (!table_exists($conn, 'restaurant_activity_logs')) return;
    $stmt = $conn->prepare('INSERT INTO restaurant_activity_logs (client_id, branch_id, session_id, table_id, action_type, details, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $stmt->bind_param('iiiissi', $client_id, $branch_id, $session_id, $table_id, $type, $details, $user_id);
    $stmt->execute();
    $stmt->close();
}

function load_session(mysqli $conn, int $session_id, int $client_id): ?array {
    $hasTables = table_exists($conn, 'restaurant_tables') && column_exists($conn, 'restaurant_table_sessions', 'table_id');
    $tableSelect = $hasTables ? 't.table_no, t.table_name, t.floor_name, t.status AS table_status' : 'NULL AS table_no, NULL AS table_name, NULL AS floor_name, NULL AS table_status';
    $tableJoin = $hasTables ? 'LEFT JOIN restaurant_tables t ON t.id=s.table_id' : '';

    $hasWaiters = table_exists($conn, 'restaurant_waiters') && column_exists($conn, 'restaurant_table_sessions', 'waiter_id');
    $waiterSelect = $hasWaiters ? 'w.name AS waiter_name' : 'NULL AS waiter_name';
    $waiterJoin = $hasWaiters ? 'LEFT JOIN restaurant_waiters w ON w.id=s.waiter_id' : '';

    $hasCustomers = table_exists($conn, 'customers') && column_exists($conn, 'restaurant_table_sessions', 'customer_id');
    $sessionName = column_exists($conn, 'restaurant_table_sessions', 'customer_name') ? "NULLIF(s.customer_name,'')" : 'NULL';
    $sessionPhone = column_exists($conn, 'restaurant_table_sessions', 'customer_phone') ? "NULLIF(s.customer_phone,'')" : 'NULL';
    if ($hasCustomers) {
        $customerSelect = "COALESCE($sessionName, c.name, 'Walk-in') AS bill_customer_name, COALESCE($sessionPhone, c.phone, '') AS bill_customer_phone, c.type AS customer_type";
        $customerJoin = 'LEFT JOIN customers c ON c.id=s.customer_id';
    } else {
        $customerSelect = "COALESCE($sessionName, 'Walk-in') AS bill_customer_name, COALESCE($sessionPhone, '') AS bill_customer_phone, NULL AS customer_type";
        $customerJoin = '';
    }

    $hasRider = table_exists($conn, 'users') && column_exists($conn, 'restaurant_table_sessions', 'rider_id') && column_exists($conn, 'users', 'name');
    $riderSelect = $hasRider ? 'ru.name AS rider_name' : 'NULL AS rider_name';
    $riderJoin = $hasRider ? 'LEFT JOIN users ru ON ru.id=s.rider_id' : '';

    $sql = "SELECT s.*, $tableSelect, $waiterSelect, $customerSelect, $riderSelect
            FROM restaurant_table_sessions s
            $tableJoin
            $waiterJoin
            $customerJoin
            $riderJoin
            WHERE s.id=? AND s.client_id=? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $session_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function load_items(mysqli $conn, int $session_id, int $client_id): array {
    $stmt = $conn->prepare("SELECT * FROM restaurant_order_items WHERE session_id=? AND client_id=? AND item_status NOT IN ('cancelled','void') ORDER BY id ASC");
    $stmt->bind_param('ii', $session_id, $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt->close();
    return $items;
}

function update_session_totals(mysqli $conn, int $session_id, int $client_id, array $totals): void {
    $sets = [];
    $types = '';
    $params = [];
    $map = [
        'subtotal' => 'subtotal',
        'tax' => 'tax',
        'service_charge' => 'service_charge',
        'delivery_charge' => 'delivery_charge',
        'grand_total' => 'grand_total',
        'balance_amount' => 'balance_amount'
    ];
    foreach ($map as $col => $key) {
        if (column_exists($conn, 'restaurant_table_sessions', $col)) {
            $sets[] = "$col=?";
            $types .= 'd';
            $params[] = (float)($totals[$key] ?? 0);
        }
    }
    if (!$sets) return;
    $types .= 'ii';
    $params[] = $session_id;
    $params[] = $client_id;
    $sql = 'UPDATE restaurant_table_sessions SET ' . implode(',', $sets) . ' WHERE id=? AND client_id=?';
    $stmt = $conn->prepare($sql);
    bind_params_ref($stmt, $types, $params);
    $stmt->execute();
    $stmt->close();
}

function recalc_session(mysqli $conn, int $session_id, int $client_id): array {
    $session = load_session($conn, $session_id, $client_id);
    if (!$session) return [];

    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) subtotal FROM restaurant_order_items WHERE session_id=? AND client_id=? AND item_status NOT IN ('cancelled','void')");
    $stmt->bind_param('ii', $session_id, $client_id);
    $stmt->execute();
    $subtotal = (float)($stmt->get_result()->fetch_assoc()['subtotal'] ?? 0);
    $stmt->close();

    $orderType = (string)($session['order_type'] ?? 'dine_in');
    $discount = max(0, (float)($session['discount'] ?? 0));
    $branchId = (int)($session['branch_id'] ?? 0);
    $taxPercent = (float)setting_value($conn, $client_id, $branchId, ['tax_percentage','default_tax_percent','default_tax_percentage'], client_tax($conn, $client_id));
    $servicePercent = (float)setting_value($conn, $client_id, $branchId, ['service_charge_percentage','default_service_charge_percent','service_percentage'], 0);
    $base = max(0, $subtotal - $discount);
    $tax = round($base * $taxPercent / 100, 2);
    $service = round($base * $servicePercent / 100, 2);
    $deliveryCharge = ($orderType === 'delivery') ? max(0, (float)($session['delivery_charge'] ?? 0)) : 0.00;
    $roundOff = (float)($session['round_off'] ?? 0);
    $grand = max(0, round($base + $tax + $service + $deliveryCharge + $roundOff, 2));
    $paid = (float)($session['paid_amount'] ?? 0);
    $balance = max(0, round($grand - $paid, 2));

    $totals = [
        'subtotal'=>$subtotal,
        'discount'=>$discount,
        'tax'=>$tax,
        'service_charge'=>$service,
        'delivery_charge'=>$deliveryCharge,
        'round_off'=>$roundOff,
        'grand_total'=>$grand,
        'paid_amount'=>$paid,
        'balance_amount'=>$balance,
        'tax_percent'=>$taxPercent,
        'service_percent'=>$servicePercent,
        'order_type'=>$orderType
    ];
    update_session_totals($conn, $session_id, $client_id, $totals);
    return $totals;
}

function generate_receipt_no(int $session_id): string {
    return 'RCP-' . date('ymd') . '-' . str_pad((string)$session_id, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

function insert_payment(mysqli $conn, array $p): int {
    $stmt = $conn->prepare("INSERT INTO restaurant_payments
        (client_id, branch_id, session_id, table_id, receipt_no, payment_method, amount_due, amount_paid, amount_received, change_amount, tip_amount, card_last4, transaction_ref, split_details, status, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param(
        'iiiissdddddssssi',
        $p['client_id'],
        $p['branch_id'],
        $p['session_id'],
        $p['table_id'],
        $p['receipt_no'],
        $p['payment_method'],
        $p['amount_due'],
        $p['amount_paid'],
        $p['amount_received'],
        $p['change_amount'],
        $p['tip_amount'],
        $p['card_last4'],
        $p['transaction_ref'],
        $p['split_details'],
        $p['status'],
        $p['created_by']
    );
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function update_paid_session(mysqli $conn, int $session_id, int $client_id, array $session, float $grandTotal, float $oldPaid, float $payAmount, string $method, string $reference, string $receiptNo, int $user_id): void {
    $newPaid = min($grandTotal, round($oldPaid + $payAmount, 2));
    $balance = max(0, round($grandTotal - $newPaid, 2));
    $fullyPaid = $balance <= 0.009;

    $sets = [];
    $types = '';
    $params = [];

    if ($fullyPaid) {
        $sets[] = "status='paid'";
    }
    if (column_exists($conn, 'restaurant_table_sessions', 'paid_amount')) { $sets[]='paid_amount=?'; $types.='d'; $params[]=$newPaid; }
    if (column_exists($conn, 'restaurant_table_sessions', 'balance_amount')) { $sets[]='balance_amount=?'; $types.='d'; $params[]=$balance; }
    if (column_exists($conn, 'restaurant_table_sessions', 'payment_method')) { $sets[]='payment_method=?'; $types.='s'; $params[]=$method; }
    if (column_exists($conn, 'restaurant_table_sessions', 'payment_status')) { $sets[]='payment_status=?'; $types.='s'; $params[]=$fullyPaid ? 'paid' : 'partial'; }
    if (column_exists($conn, 'restaurant_table_sessions', 'payment_reference')) { $sets[]='payment_reference=?'; $types.='s'; $params[]=$reference; }
    if (column_exists($conn, 'restaurant_table_sessions', 'receipt_no')) { $sets[]='receipt_no=?'; $types.='s'; $params[]=$receiptNo; }
    if (column_exists($conn, 'restaurant_table_sessions', 'closed_by') && $fullyPaid) { $sets[]='closed_by=?'; $types.='i'; $params[]=$user_id; }
    if (column_exists($conn, 'restaurant_table_sessions', 'closed_at') && $fullyPaid) { $sets[]='closed_at=NOW()'; }
    if (column_exists($conn, 'restaurant_table_sessions', 'paid_at')) { $sets[]='paid_at=NOW()'; }
    if (column_exists($conn, 'restaurant_table_sessions', 'notes')) { $sets[]="notes=CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='', '', '\n'), ?)"; $types.='s'; $params[]='Payment received. Receipt: ' . $receiptNo . ' | Method: ' . strtoupper($method); }

    if (!$sets) return;
    $types .= 'ii';
    $params[] = $session_id;
    $params[] = $client_id;
    $sql = 'UPDATE restaurant_table_sessions SET ' . implode(',', $sets) . ' WHERE id=? AND client_id=?';
    $stmt = $conn->prepare($sql);
    bind_params_ref($stmt, $types, $params);
    $stmt->execute();
    $stmt->close();
}

function release_restaurant_table(mysqli $conn, int $client_id, int $session_id, int $table_id): void {
    if ($table_id <= 0 || !table_exists($conn, 'restaurant_tables')) return;
    $hasCurrent = column_exists($conn, 'restaurant_tables', 'current_session_id');

    if (table_exists($conn, 'restaurant_session_tables')) {
        $set = $hasCurrent ? "t.status='Available', t.current_session_id=NULL" : "t.status='Available'";
        $stmt = $conn->prepare("UPDATE restaurant_tables t INNER JOIN restaurant_session_tables st ON st.table_id=t.id SET $set WHERE st.session_id=? AND st.client_id=?");
        $stmt->bind_param('ii', $session_id, $client_id);
        $stmt->execute();
        $stmt->close();
        return;
    }

    if ($hasCurrent) {
        $stmt = $conn->prepare("UPDATE restaurant_tables SET status='Available', current_session_id=NULL WHERE id=? AND client_id=?");
    } else {
        $stmt = $conn->prepare("UPDATE restaurant_tables SET status='Available' WHERE id=? AND client_id=?");
    }
    $stmt->bind_param('ii', $table_id, $client_id);
    $stmt->execute();
    $stmt->close();
}

function latest_payment(mysqli $conn, int $session_id, int $client_id): ?array {
    if (!table_exists($conn, 'restaurant_payments')) return null;
    $stmt = $conn->prepare('SELECT * FROM restaurant_payments WHERE session_id=? AND client_id=? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('ii', $session_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function method_label(string $method): string {
    $map = ['cash'=>'Cash','card'=>'Bank Card / POS','easypaisa'=>'Easypaisa','jazzcash'=>'JazzCash','bank_transfer'=>'Bank Transfer / IBFT','raast'=>'Raast QR / ID','upi'=>'UPI / QR','split'=>'Split Payment'];
    return $map[$method] ?? ucwords(str_replace('_', ' ', $method));
}

ensure_payment_schema($conn);

if (empty($_SESSION['restaurant_payment_csrf'])) {
    $_SESSION['restaurant_payment_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['restaurant_payment_csrf'];

$session_id = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$error = '';
$success = '';
$session = null;
$items = [];
$totals = [];
$latestPayment = null;

try {
    if ($session_id <= 0) throw new Exception('Invalid restaurant session.');
    if (!table_exists($conn, 'restaurant_table_sessions') || !table_exists($conn, 'restaurant_order_items')) {
        throw new Exception('Restaurant DB tables missing. Please import restaurant management migration first.');
    }

    $session = load_session($conn, $session_id, $client_id);
    if (!$session) throw new Exception('Restaurant session not found.');
    if (in_array((string)($session['status'] ?? ''), ['cancelled','void'], true)) throw new Exception('This restaurant session is cancelled/void. Payment cannot be collected.');

    $totals = recalc_session($conn, $session_id, $client_id);
    $session = load_session($conn, $session_id, $client_id);
    $items = load_items($conn, $session_id, $client_id);
    if (!$items) throw new Exception('No active order items found for payment.');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'take_payment') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception('Invalid security token. Please refresh page.');

        $session = load_session($conn, $session_id, $client_id);
        if (!$session) throw new Exception('Restaurant session not found.');
        if ((string)($session['status'] ?? '') === 'paid') throw new Exception('This bill is already paid.');

        $totals = recalc_session($conn, $session_id, $client_id);
        $session = load_session($conn, $session_id, $client_id);

        $grandTotal = (float)($totals['grand_total'] ?? 0);
        $oldPaid = (float)($session['paid_amount'] ?? 0);
        $due = max(0, round($grandTotal - $oldPaid, 2));
        if ($due <= 0) throw new Exception('No payable balance found.');

        $method = strtolower(trim((string)($_POST['payment_method'] ?? 'cash')));
        if (!in_array($method, ['cash','card','easypaisa','jazzcash','bank_transfer','raast','split'], true)) $method = 'cash';

        $amountPaid = $due;
        $amountReceived = $due;
        $changeAmount = 0.00;
        $tipAmount = max(0, (float)($_POST['tip_amount'] ?? 0));
        $cardLast4 = null;
        $reference = '';
        $splitDetails = null;

        if ($method === 'cash') {
            $amountReceived = round((float)($_POST['cash_received'] ?? 0), 2);
            if ($amountReceived + 0.009 < $due) throw new Exception('Cash received amount grand total se kam hai.');
            $changeAmount = round($amountReceived - $due, 2);
            $reference = trim((string)($_POST['cash_note'] ?? ''));
        } elseif ($method === 'card') {
            $authorized = (string)($_POST['card_authorized'] ?? '') === '1';
            $reference = trim((string)($_POST['card_reference'] ?? ''));
            $cardLast4 = preg_replace('/\D+/', '', (string)($_POST['card_last4'] ?? ''));
            if (!$authorized && $reference === '') throw new Exception('Card payment ke liye terminal approval / auth code required hai.');
            if ($cardLast4 !== '' && strlen($cardLast4) !== 4) throw new Exception('Card last 4 digits exactly 4 numbers honay chahiye.');
            $cardLast4 = $cardLast4 !== '' ? $cardLast4 : null;
            $amountReceived = $due;
        } elseif (in_array($method, ['easypaisa','jazzcash','bank_transfer','raast'], true)) {
            $referenceField = $method . '_reference';
            $payerField = $method . '_payer';
            $reference = trim((string)($_POST[$referenceField] ?? ''));
            $payerInfo = trim((string)($_POST[$payerField] ?? ''));
            if ($reference === '') throw new Exception(method_label($method) . ' ke liye transaction/reference number required hai.');
            if ($payerInfo !== '') $reference .= ' | Payer: ' . $payerInfo;
            $amountReceived = $due;
        } elseif ($method === 'split') {
            $splitMethods = $_POST['split_method'] ?? [];
            $splitAmounts = $_POST['split_amount'] ?? [];
            $splitRefs    = $_POST['split_ref'] ?? [];
            $rows = [];
            $splitTotal = 0.00;
            if (!is_array($splitMethods) || !is_array($splitAmounts)) throw new Exception('Invalid split payment data.');

            foreach ($splitMethods as $i => $sm) {
                $sm = strtolower(trim((string)$sm));
                $sa = round((float)($splitAmounts[$i] ?? 0), 2);
                $sr = trim((string)($splitRefs[$i] ?? ''));
                if (!in_array($sm, ['cash','card','easypaisa','jazzcash','bank_transfer','raast'], true) || $sa <= 0) continue;
                if ($sm !== 'cash' && $sr === '') {
                    throw new Exception('Split payment me non-cash row ke liye reference/auth/txn number required hai.');
                }
                $rows[] = ['method'=>$sm, 'amount'=>$sa, 'reference'=>$sr];
                $splitTotal += $sa;
            }
            if (!$rows) throw new Exception('Split payment rows empty hain.');
            if ($splitTotal + 0.009 < $due) throw new Exception('Split total grand total se kam hai.');
            $amountReceived = round($splitTotal, 2);
            $changeAmount = round($splitTotal - $due, 2);
            $reference = 'Split: ' . count($rows) . ' method(s)';
            $splitDetails = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $receiptNo = generate_receipt_no($session_id);
        $tableId = (int)($session['table_id'] ?? 0);
        $branchId = (int)($session['branch_id'] ?? $branch_id);

        $conn->begin_transaction();

        insert_payment($conn, [
            'client_id' => $client_id,
            'branch_id' => $branchId,
            'session_id' => $session_id,
            'table_id' => $tableId ?: null,
            'receipt_no' => $receiptNo,
            'payment_method' => $method,
            'amount_due' => $due,
            'amount_paid' => $amountPaid,
            'amount_received' => $amountReceived,
            'change_amount' => $changeAmount,
            'tip_amount' => $tipAmount,
            'card_last4' => $cardLast4,
            'transaction_ref' => $reference !== '' ? $reference : null,
            'split_details' => $splitDetails,
            'status' => 'paid',
            'created_by' => $user_id
        ]);

        update_paid_session($conn, $session_id, $client_id, $session, $grandTotal, $oldPaid, $amountPaid, $method, $reference, $receiptNo, $user_id);
        release_restaurant_table($conn, $client_id, $session_id, $tableId);
        log_activity($conn, $client_id, $branchId, $session_id, $tableId ?: null, 'payment_received', 'Payment received. Receipt: ' . $receiptNo . ' | Method: ' . strtoupper($method), $user_id);

        $conn->commit();

        $_SESSION['success'] = 'Payment received successfully. Receipt #' . $receiptNo;
        header('Location: restaurant_bill_print.php?session_id=' . $session_id . '&paid=1&receipt_no=' . urlencode($receiptNo) . '&autoprint=1');
        exit;
    }

    $latestPayment = latest_payment($conn, $session_id, $client_id);
} catch (Throwable $e) {
    if ($conn->errno) { try { $conn->rollback(); } catch (Throwable $ignore) {} }
    $error = $e->getMessage();
}

$grand = (float)($totals['grand_total'] ?? 0);
$paid = (float)($session['paid_amount'] ?? 0);
$due = max(0, round($grand - $paid, 2));
$orderType = (string)($session['order_type'] ?? 'dine_in');
$tableLabel = trim((string)(($session['table_name'] ?? '') ?: ($session['table_no'] ?? '') ?: 'N/A'));
$tokenNo = trim((string)($session['token_no'] ?? ''));
$customerName = trim((string)($session['bill_customer_name'] ?? 'Walk-in')) ?: 'Walk-in';
$customerPhone = trim((string)($session['bill_customer_phone'] ?? ''));
$orderRef = $orderType === 'dine_in' ? ('Table ' . $tableLabel) : (($tokenNo !== '') ? ('Token ' . $tokenNo) : ('Order #' . $session_id));
$isPaid = $session && (string)($session['status'] ?? '') === 'paid';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Restaurant Payment - OneERP</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{
    --erp-bg:#050711;--erp-ink:#090816;--erp-muted:#667085;--erp-border:#e7e9f2;--erp-purple:#090617;--erp-accent:#6d4cff;--erp-accent2:#9b5cff;--erp-green:#10b981;--erp-red:#ef4444;--erp-orange:#f59e0b;--erp-blue:#2563eb;--erp-cyan:#06b6d4
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Inter,"Segoe UI",Arial,sans-serif;background:radial-gradient(circle at 12% 4%,rgba(124,58,237,.44),transparent 30%),radial-gradient(circle at 86% 8%,rgba(14,165,233,.20),transparent 28%),linear-gradient(135deg,#050711 0%,#090d1f 42%,#170d35 100%)!important;color:#f8fafc}
.container-scroll{display:flex;min-height:100vh}.main-panel{flex:1;min-width:0}.content-wrapper{padding:96px 24px 34px;min-height:100vh;position:relative}.content-wrapper:before{content:"";position:fixed;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:44px 44px;opacity:.17;pointer-events:none}.topbar-wrap{position:fixed;top:0;left:0;right:0;z-index:9998;background:rgba(5,8,18,.94);border-bottom:1px solid rgba(148,163,184,.14);backdrop-filter:blur(18px);min-height:74px}
.pay-page{position:relative;max-width:1260px;margin:0 auto}.page-head{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px}.page-title{font-size:34px;font-weight:950;letter-spacing:-.05em;margin:0;line-height:1;text-shadow:0 0 26px rgba(124,58,237,.45)}.page-sub{color:#b7c1df;font-weight:800;margin-top:7px}.head-actions{display:flex;gap:10px;flex-wrap:wrap}.btn-soft,.btn-purple{border-radius:14px;min-height:44px;padding:0 17px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:950;text-decoration:none;transition:.18s}.btn-soft{border:1px solid rgba(226,232,240,.16);background:linear-gradient(135deg,rgba(30,41,59,.92),rgba(15,23,42,.82));color:#f8fafc;box-shadow:inset 0 1px 0 rgba(255,255,255,.08),0 14px 32px rgba(0,0,0,.25)}.btn-soft:hover{transform:translateY(-1px);color:#fff}.btn-purple{border:0;background:linear-gradient(135deg,#6d4cff,#8b5cf6);color:#fff;box-shadow:0 14px 34px rgba(109,76,255,.35)}
.grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(470px,520px);gap:20px;align-items:start}.glass{position:relative;overflow:hidden;border:1px solid rgba(167,139,250,.22);background:radial-gradient(circle at 0% 0%,rgba(124,58,237,.28),transparent 36%),linear-gradient(135deg,rgba(15,23,42,.94),rgba(17,24,39,.82));border-radius:26px;box-shadow:0 26px 80px rgba(0,0,0,.42),inset 0 1px 0 rgba(255,255,255,.07);backdrop-filter:blur(18px)}.summary{padding:22px}.summary:after{content:"";position:absolute;right:-90px;top:-90px;width:220px;height:220px;border-radius:50%;background:rgba(109,76,255,.16);filter:blur(5px)}.summary-head{position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:16px}.summary h4{font-weight:950;font-size:24px;letter-spacing:-.04em;color:#fff;margin:0}.summary-sub{color:#c8d1ee;font-size:14px;font-weight:850;margin-top:6px}.session-pill{border-radius:999px;padding:12px 18px;background:rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.1);font-size:12px;font-weight:950;color:#fff;white-space:nowrap}.items{position:relative;z-index:1;margin-top:16px;max-height:360px;overflow:auto;border-radius:17px;border:1px solid rgba(226,232,240,.13);background:rgba(2,6,23,.23)}.items table{width:100%;border-collapse:collapse}.items th,.items td{padding:13px 12px;border-bottom:1px solid rgba(226,232,240,.10);font-size:13px;color:#eef2ff}.items th{color:#b7c1df;text-transform:uppercase;font-size:11px;letter-spacing:.08em;background:rgba(255,255,255,.025)}.items td strong{color:#fff}.totals{position:relative;z-index:1;margin-top:18px;width:min(430px,100%);margin-left:auto}.line{display:flex;justify-content:space-between;gap:14px;padding:11px 0;border-bottom:1px dashed rgba(226,232,240,.16);color:#eef2ff}.line span{color:#bbc6e3;font-weight:780}.line strong{font-weight:950;color:#fff}.grandline{font-size:22px;border-bottom:0;color:#fff}.grandline span{font-size:20px;color:#c7d2fe}.grandline strong{font-size:24px}.payable-line{border-radius:16px;padding:14px 16px;margin-top:6px;background:linear-gradient(135deg,rgba(109,76,255,.28),rgba(16,185,129,.16));border:1px solid rgba(167,139,250,.22)}
.payment-card{background:linear-gradient(180deg,#fff,#fbfcff);color:var(--erp-ink);border:1px solid rgba(255,255,255,.72);border-radius:26px;padding:24px;box-shadow:0 30px 90px rgba(0,0,0,.46),inset 0 1px 0 rgba(255,255,255,.9)}.payment-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.payment-title{font-size:19px;font-weight:950;margin:0;letter-spacing:-.03em}.payment-sub{color:#667085;font-weight:850;font-size:13px;margin-top:6px}.close-x{width:36px;height:36px;border:1px solid #eef0f6;background:#fff;border-radius:12px;font-size:22px;line-height:30px;color:#344054;text-align:center;box-shadow:0 8px 18px rgba(16,24,40,.06)}.method-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:16px}.method-card{position:relative;min-height:92px;border:1px solid #e6e8ef;background:linear-gradient(180deg,#fff,#fafbff);border-radius:16px;display:flex;flex-direction:column;align-items:flex-start;justify-content:center;gap:6px;padding:13px;cursor:pointer;transition:.18s;box-shadow:0 10px 22px rgba(16,24,40,.045);overflow:hidden}.method-card .m-icon{width:34px;height:34px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#f3f4f8;color:#111827;font-size:18px}.method-card .m-title{font-weight:950;color:#101828;font-size:14px;line-height:1}.method-card .m-sub{font-size:10.5px;color:#667085;font-weight:800;line-height:1.1}.method-card.active{background:radial-gradient(circle at 14% 4%,rgba(139,92,246,.34),transparent 38%),linear-gradient(135deg,#050113,#101028);border-color:#050113;box-shadow:0 18px 34px rgba(5,1,19,.28);transform:translateY(-1px)}.method-card.active .m-title,.method-card.active .m-sub{color:#fff}.method-card.active .m-icon{background:rgba(255,255,255,.12);color:#fff}.method-card[data-method="easypaisa"] .m-icon{color:#0a8f08}.method-card[data-method="jazzcash"] .m-icon{color:#d61f26}.method-card[data-method="raast"] .m-icon{color:#2563eb}.method-card[data-method="bank_transfer"] .m-icon{color:#7c3aed}.pay-box{margin-top:16px;background:linear-gradient(180deg,#f8f9fc,#f3f5fb);border:1px solid #edf0f6;border-radius:18px;padding:16px}.field-label{font-size:13px;font-weight:950;margin-bottom:8px;color:#111827}.field-help{color:#667085;font-size:12px;font-weight:760;margin-top:8px}.input-soft,.select-soft{width:100%;height:46px;border:1px solid #e4e7ec;background:#fff;border-radius:12px;padding:0 13px;font-weight:850;color:#111827;outline:none;box-shadow:0 1px 0 rgba(16,24,40,.02)}.input-soft::placeholder{color:#98a2b3}.input-soft:focus,.select-soft:focus{border-color:#7c3aed;background:#fff;box-shadow:0 0 0 4px rgba(124,58,237,.12)}.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.method-panel{display:none}.method-panel.active{display:block}.quick-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:11px}.quick-btn{border:1px solid #e4e7ec;background:#fff;border-radius:999px;padding:8px 12px;font-weight:950;font-size:12px;color:#101828;box-shadow:0 8px 16px rgba(16,24,40,.04)}.change-box,.status-box{display:flex;justify-content:space-between;align-items:center;margin-top:13px;padding:12px 13px;border-radius:14px;background:#ecfdf3;color:#027a48;font-weight:950}.status-box{background:#eef4ff;color:#175cd3}.divider{height:1px;background:#eaecf0;margin:18px 0}.footer-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}.btn-cancel,.btn-confirm{height:46px;border-radius:14px;font-weight:950;font-size:15px}.btn-cancel{border:1px solid #e4e7ec;background:#fff;color:#111827}.btn-confirm{border:0;background:linear-gradient(135deg,#050113,#111027);color:#fff;box-shadow:0 16px 26px rgba(5,1,19,.22)}.terminal{border-radius:18px;background:radial-gradient(circle at 0 0,rgba(45,212,191,.28),transparent 40%),linear-gradient(135deg,#0b1024,#1e1b4b);color:#fff;padding:16px;display:flex;align-items:center;gap:14px;margin-bottom:13px;box-shadow:inset 0 1px 0 rgba(255,255,255,.10)}.card-visual{width:78px;height:50px;border-radius:13px;background:linear-gradient(135deg,#2dd4bf,#7c3aed);box-shadow:inset 0 1px 0 rgba(255,255,255,.25);position:relative;flex:0 0 auto}.card-visual:before{content:"";position:absolute;left:10px;top:11px;width:22px;height:15px;border-radius:4px;background:rgba(255,255,255,.36)}.card-visual:after{content:"";position:absolute;left:10px;bottom:10px;width:38px;height:5px;border-radius:99px;background:rgba(255,255,255,.65)}.terminal-status{font-weight:950}.terminal-status small{display:block;color:#c4b5fd;font-weight:750;margin-top:3px}.approved{color:#12b76a!important}.wallet-hero{display:flex;align-items:center;gap:12px;padding:14px;border-radius:16px;background:#fff;border:1px solid #e8ebf2;margin-bottom:12px}.wallet-logo{width:42px;height:42px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:950;color:#fff}.wallet-logo.easypaisa{background:linear-gradient(135deg,#19a900,#086d0a)}.wallet-logo.jazzcash{background:linear-gradient(135deg,#ef4444,#b91c1c)}.wallet-logo.bank{background:linear-gradient(135deg,#7c3aed,#2563eb)}.wallet-logo.raast{background:linear-gradient(135deg,#2563eb,#06b6d4)}.wallet-title{font-weight:950;line-height:1}.wallet-title small{display:block;color:#667085;font-size:12px;margin-top:3px}.split-row{display:grid;grid-template-columns:135px 1fr 1fr 38px;gap:8px;margin-bottom:8px}.split-remove{border:0;border-radius:11px;background:#fee4e2;color:#b42318;font-weight:950}.split-total{display:flex;justify-content:space-between;margin-top:10px;font-weight:950;color:#101828}.alert-clean{border-radius:14px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;padding:12px 14px;font-weight:850}.paid-box{background:#ecfdf3;color:#027a48;border:1px solid #abefc6;border-radius:14px;padding:14px;font-weight:950}.receipt-mini{margin-top:12px;border:1px dashed #98a2b3;border-radius:14px;padding:12px;background:#f9fafb;color:#111827}.security-note{display:flex;gap:8px;align-items:flex-start;margin-top:12px;color:#667085;font-size:12px;font-weight:760}.security-note i{color:#12b76a;font-size:15px;margin-top:1px}
@media(max-width:1100px){.grid{grid-template-columns:1fr}.payment-card{max-width:620px;margin:0 auto;width:100%}.totals{margin-left:0}}
@media(max-width:640px){.content-wrapper{padding:88px 12px 22px}.page-title{font-size:28px}.method-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.payment-card{padding:18px;border-radius:22px}.footer-actions,.form-grid-2{grid-template-columns:1fr}.split-row{grid-template-columns:1fr}.split-remove{height:38px}.summary{padding:18px}.items{max-height:280px}}
@media print{.sidebar,.topbar-wrap,.page-head,.summary,.no-print{display:none!important}.content-wrapper{padding:0!important;background:#fff!important}.grid{display:block}.payment-card{box-shadow:none;border-radius:0}.payment-card form{display:none}.receipt-mini{display:block}}
</style>
</head>
<body>
<div class="container-scroll">
    <div class="sidebar no-print"><?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?></div>
    <div class="main-panel">
        <div class="topbar-wrap no-print"><?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?></div>
        <div class="content-wrapper">
            <div class="pay-page">
                <div class="page-head no-print">
                    <div>
                        <h1 class="page-title">Restaurant Payment</h1>
                        <div class="page-sub">Cash, card swipe/tap, UPI/QR aur split payment yahan collect karein.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn-soft" href="restaurant_orders.php"><i class="bi bi-arrow-left"></i> Orders</a>
                        <?php if ($session_id > 0): ?>
                            <a class="btn-soft" href="restaurant_bill_print.php?session_id=<?= (int)$session_id ?>" target="_blank"><i class="bi bi-printer"></i> Bill</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert-clean mb-3"><i class="bi bi-exclamation-triangle"></i> <?= e($error) ?></div>
                <?php endif; ?>

                <?php if (!$error && $session): ?>
                    <div class="grid">
                        <div class="glass summary no-print">
                            <div class="summary-head">
                                <div>
                                    <h4><?= e($orderRef) ?></h4>
                                    <div class="summary-sub"><?= e(ucwords(str_replace('_',' ', $orderType))) ?> · Customer: <?= e($customerName) ?><?= $customerPhone !== '' ? ' · ' . e($customerPhone) : '' ?></div>
                                </div>
                                <span class="session-pill">Session #<?= (int)$session_id ?></span>
                            </div>

                            <div class="items">
                                <table>
                                    <thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Rate</th><th class="text-end">Total</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($items as $it): ?>
                                        <tr>
                                            <td><strong><?= e($it['item_name'] ?? '') ?></strong><?php if (!empty($it['special_note'])): ?><div class="text-secondary small">Note: <?= e($it['special_note']) ?></div><?php endif; ?></td>
                                            <td class="text-center"><?= e(qty_fmt($it['qty'] ?? 0)) ?></td>
                                            <td class="text-end"><?= number_format((float)($it['price'] ?? 0), 2) ?></td>
                                            <td class="text-end fw-bold"><?= number_format((float)($it['total'] ?? 0), 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="totals">
                                <div class="line"><span>Subtotal</span><strong><?= money($totals['subtotal'] ?? 0) ?></strong></div>
                                <div class="line"><span>Discount</span><strong>- <?= money($totals['discount'] ?? 0) ?></strong></div>
                                <div class="line"><span>Tax <?= !empty($totals['tax_percent']) ? '(' . number_format((float)$totals['tax_percent'], 2) . '%)' : '' ?></span><strong><?= money($totals['tax'] ?? 0) ?></strong></div>
                                <div class="line"><span>Service <?= !empty($totals['service_percent']) ? '(' . number_format((float)$totals['service_percent'], 2) . '%)' : '' ?></span><strong><?= money($totals['service_charge'] ?? 0) ?></strong></div>
                                <?php if ($orderType === 'delivery' || (float)($totals['delivery_charge'] ?? 0) > 0): ?>
                                    <div class="line"><span>Delivery</span><strong><?= money($totals['delivery_charge'] ?? 0) ?></strong></div>
                                <?php endif; ?>
                                <?php if (abs((float)($totals['round_off'] ?? 0)) > 0): ?><div class="line"><span>Round Off</span><strong><?= money($totals['round_off'] ?? 0) ?></strong></div><?php endif; ?>
                                <div class="line grandline"><span>Grand Total</span><strong><?= money($grand) ?></strong></div>
                                <?php if ($paid > 0): ?><div class="line"><span>Already Paid</span><strong><?= money($paid) ?></strong></div><?php endif; ?>
                                <div class="line grandline payable-line"><span>Payable Now</span><strong><?= money($due) ?></strong></div>
                            </div>
                        </div>

                        <div class="payment-card">
                            <div class="payment-top">
                                <div>
                                    <h3 class="payment-title">Payment <?= money($due) ?></h3>
                                    <div class="text-secondary fw-bold small mt-1">Select Payment Method</div>
                                </div>
                                <a class="close-x text-decoration-none" href="restaurant_orders.php">&times;</a>
                            </div>

                            <?php if ($isPaid): ?>
                                <div class="paid-box mt-3"><i class="bi bi-check-circle"></i> Ye bill already paid hai.</div>
                                <?php if ($latestPayment): ?>
                                    <div class="receipt-mini">
                                        <div class="d-flex justify-content-between"><span>Receipt</span><strong><?= e($latestPayment['receipt_no']) ?></strong></div>
                                        <div class="d-flex justify-content-between"><span>Method</span><strong><?= e(method_label((string)$latestPayment['payment_method'])) ?></strong></div>
                                        <div class="d-flex justify-content-between"><span>Paid</span><strong><?= money($latestPayment['amount_paid']) ?></strong></div>
                                    </div>
                                <?php endif; ?>
                                <div class="footer-actions mt-3">
                                    <a class="btn-cancel d-flex align-items-center justify-content-center text-decoration-none" href="restaurant_orders.php">Back</a>
                                    <a class="btn-confirm d-flex align-items-center justify-content-center text-decoration-none" href="restaurant_bill_print.php?session_id=<?= (int)$session_id ?>&paid=1&autoprint=1">Print Receipt</a>
                                </div>
                            <?php else: ?>
                                <form method="post" id="paymentForm" autocomplete="off">
                                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="take_payment">
                                    <input type="hidden" name="session_id" value="<?= (int)$session_id ?>">
                                    <input type="hidden" name="payment_method" id="paymentMethod" value="cash">
                                    <input type="hidden" name="card_authorized" id="cardAuthorized" value="0">

                                    <div class="method-grid">
                                        <button type="button" class="method-card active" data-method="cash"><span class="m-icon"><i class="bi bi-cash-stack"></i></span><span class="m-title">Cash</span><span class="m-sub">Counter cash</span></button>
                                        <button type="button" class="method-card" data-method="card"><span class="m-icon"><i class="bi bi-credit-card-2-front"></i></span><span class="m-title">Bank Card</span><span class="m-sub">Swipe / Tap POS</span></button>
                                        <button type="button" class="method-card" data-method="easypaisa"><span class="m-icon"><i class="bi bi-phone-fill"></i></span><span class="m-title">Easypaisa</span><span class="m-sub">Wallet / QR</span></button>
                                        <button type="button" class="method-card" data-method="jazzcash"><span class="m-icon"><i class="bi bi-phone-vibrate-fill"></i></span><span class="m-title">JazzCash</span><span class="m-sub">Wallet / Till ID</span></button>
                                        <button type="button" class="method-card" data-method="raast"><span class="m-icon"><i class="bi bi-qr-code"></i></span><span class="m-title">Raast</span><span class="m-sub">QR / Raast ID</span></button>
                                        <button type="button" class="method-card" data-method="bank_transfer"><span class="m-icon"><i class="bi bi-bank2"></i></span><span class="m-title">Bank Transfer</span><span class="m-sub">IBFT / Account</span></button>
                                        <button type="button" class="method-card" data-method="split"><span class="m-icon"><i class="bi bi-shuffle"></i></span><span class="m-title">Split</span><span class="m-sub">Multiple methods</span></button>
                                    </div>

                                    <div class="pay-box">
                                        <div class="method-panel active" data-panel="cash">
                                            <div class="field-label">Amount Received</div>
                                            <input class="input-soft" type="number" step="0.01" min="0" name="cash_received" id="cashReceived" placeholder="Enter cash amount" value="<?= e(number_format($due, 2, '.', '')) ?>">
                                            <input class="input-soft mt-2" type="text" name="cash_note" placeholder="Cash note / optional remarks">
                                            <div class="quick-row">
                                                <button type="button" class="quick-btn" data-cash="<?= e(number_format($due, 2, '.', '')) ?>">Exact</button>
                                                <button type="button" class="quick-btn" data-cash="500">Rs. 500</button>
                                                <button type="button" class="quick-btn" data-cash="1000">Rs. 1000</button>
                                                <button type="button" class="quick-btn" data-cash="2000">Rs. 2000</button>
                                                <button type="button" class="quick-btn" data-cash="5000">Rs. 5000</button>
                                            </div>
                                            <div class="change-box"><span>Change Return</span><strong id="changeReturn">Rs. 0.00</strong></div>
                                        </div>

                                        <div class="method-panel" data-panel="card">
                                            <div class="terminal">
                                                <div class="card-visual"></div>
                                                <div class="terminal-status" id="terminalStatus">Waiting for card<small>Swipe / Tap / Insert on bank POS terminal</small></div>
                                            </div>
                                            <button type="button" class="btn-purple w-100 justify-content-center mb-3" id="swipeBtn"><i class="bi bi-broadcast-pin"></i> Capture Terminal Approval</button>
                                            <div class="form-grid-2">
                                                <div>
                                                    <div class="field-label">Approval / Auth Code</div>
                                                    <input class="input-soft" type="text" name="card_reference" id="cardReference" placeholder="e.g. AUTH12345">
                                                </div>
                                                <div>
                                                    <div class="field-label">Card Last 4 Digits</div>
                                                    <input class="input-soft" type="text" name="card_last4" maxlength="4" inputmode="numeric" placeholder="1234">
                                                </div>
                                            </div>
                                            <div class="field-help">Real bank terminal integration ke liye HBL/UBL/Keenu/Nayapay SDK/API attach hogi. Abhi counter approval/auth code capture hoga.</div>
                                        </div>

                                        <div class="method-panel" data-panel="easypaisa">
                                            <div class="wallet-hero"><div class="wallet-logo easypaisa">EP</div><div class="wallet-title">Easypaisa Payment<small>Wallet, QR ya Till ID transaction</small></div></div>
                                            <div class="form-grid-2">
                                                <div><div class="field-label">Transaction / TID</div><input class="input-soft" type="text" name="easypaisa_reference" placeholder="e.g. EP123456789"></div>
                                                <div><div class="field-label">Sender Mobile / Name</div><input class="input-soft" type="text" name="easypaisa_payer" placeholder="03xx / customer name"></div>
                                            </div>
                                            <div class="status-box"><span>Receivable</span><strong><?= money($due) ?></strong></div>
                                        </div>

                                        <div class="method-panel" data-panel="jazzcash">
                                            <div class="wallet-hero"><div class="wallet-logo jazzcash">JC</div><div class="wallet-title">JazzCash Payment<small>Wallet, QR ya Till ID transaction</small></div></div>
                                            <div class="form-grid-2">
                                                <div><div class="field-label">Transaction / TID</div><input class="input-soft" type="text" name="jazzcash_reference" placeholder="e.g. JC123456789"></div>
                                                <div><div class="field-label">Sender Mobile / Name</div><input class="input-soft" type="text" name="jazzcash_payer" placeholder="03xx / customer name"></div>
                                            </div>
                                            <div class="status-box"><span>Receivable</span><strong><?= money($due) ?></strong></div>
                                        </div>

                                        <div class="method-panel" data-panel="raast">
                                            <div class="wallet-hero"><div class="wallet-logo raast"><i class="bi bi-qr-code"></i></div><div class="wallet-title">Raast QR / Raast ID<small>Instant account payment reference</small></div></div>
                                            <div class="form-grid-2">
                                                <div><div class="field-label">Raast Reference</div><input class="input-soft" type="text" name="raast_reference" placeholder="Raast txn/reference no"></div>
                                                <div><div class="field-label">Raast ID / Payer</div><input class="input-soft" type="text" name="raast_payer" placeholder="Mobile / CNIC / name optional"></div>
                                            </div>
                                            <div class="status-box"><span>Receivable</span><strong><?= money($due) ?></strong></div>
                                        </div>

                                        <div class="method-panel" data-panel="bank_transfer">
                                            <div class="wallet-hero"><div class="wallet-logo bank"><i class="bi bi-bank2"></i></div><div class="wallet-title">Bank Transfer / IBFT<small>Bank app, ATM transfer ya account deposit</small></div></div>
                                            <div class="form-grid-2">
                                                <div><div class="field-label">IBFT / Deposit Ref</div><input class="input-soft" type="text" name="bank_transfer_reference" placeholder="Reference / screenshot no"></div>
                                                <div><div class="field-label">Bank / Account Title</div><input class="input-soft" type="text" name="bank_transfer_payer" placeholder="Meezan / HBL / customer name"></div>
                                            </div>
                                            <div class="status-box"><span>Receivable</span><strong><?= money($due) ?></strong></div>
                                        </div>

                                        <div class="method-panel" data-panel="split">
                                            <div class="field-label">Split Payment Methods</div>
                                            <div id="splitRows">
                                                <div class="split-row">
                                                    <select class="select-soft" name="split_method[]">
                                                        <option value="cash">Cash</option><option value="card">Bank Card</option><option value="easypaisa">Easypaisa</option><option value="jazzcash">JazzCash</option><option value="raast">Raast</option><option value="bank_transfer">Bank Transfer</option>
                                                    </select>
                                                    <input class="input-soft split-amount" type="number" step="0.01" name="split_amount[]" placeholder="Amount" value="<?= e(number_format($due, 2, '.', '')) ?>">
                                                    <input class="input-soft split-ref" type="text" name="split_ref[]" placeholder="Ref/Auth/TXN for non-cash">
                                                    <button class="split-remove" type="button">×</button>
                                                </div>
                                            </div>
                                            <button type="button" class="quick-btn" id="addSplit"><i class="bi bi-plus-lg"></i> Add Method</button>
                                            <div class="split-total"><span>Split Total</span><strong id="splitTotal">Rs. 0.00</strong></div>
                                            <div class="split-total"><span>Difference / Change</span><strong id="splitDiff">Rs. 0.00</strong></div>
                                        </div>
                                    </div>

                                    <div class="divider"></div>
                                    <div class="footer-actions">
                                        <a class="btn-cancel d-flex align-items-center justify-content-center text-decoration-none" href="restaurant_orders.php">Cancel</a>
                                        <button type="submit" class="btn-confirm"><i class="bi bi-check2-circle"></i> Confirm Payment</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    const due = <?= json_encode($due) ?>;
    const fmt = n => 'Rs. ' + (Number(n || 0)).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
    const methodInput = document.getElementById('paymentMethod');
    const cashInput = document.getElementById('cashReceived');
    const changeReturn = document.getElementById('changeReturn');
    const cardAuthorized = document.getElementById('cardAuthorized');
    const terminalStatus = document.getElementById('terminalStatus');
    const cardReference = document.getElementById('cardReference');

    function setMethod(method){
        if (!methodInput) return;
        methodInput.value = method;
        document.querySelectorAll('.method-card').forEach(btn => btn.classList.toggle('active', btn.dataset.method === method));
        document.querySelectorAll('.method-panel').forEach(panel => panel.classList.toggle('active', panel.dataset.panel === method));
    }
    document.querySelectorAll('.method-card').forEach(btn => btn.addEventListener('click', () => setMethod(btn.dataset.method)));

    function calcCash(){
        if (!cashInput || !changeReturn) return;
        const received = parseFloat(cashInput.value || '0');
        changeReturn.textContent = fmt(Math.max(0, received - due));
    }
    cashInput && cashInput.addEventListener('input', calcCash);
    document.querySelectorAll('[data-cash]').forEach(btn => btn.addEventListener('click', () => { cashInput.value = btn.dataset.cash; calcCash(); }));
    calcCash();

    const swipeBtn = document.getElementById('swipeBtn');
    swipeBtn && swipeBtn.addEventListener('click', function(){
        cardAuthorized.value = '0';
        terminalStatus.innerHTML = 'Processing card<small>Please wait...</small>';
        terminalStatus.classList.remove('approved');
        setTimeout(() => {
            cardAuthorized.value = '1';
            if (cardReference && !cardReference.value) cardReference.value = 'AUTH' + Math.floor(100000 + Math.random() * 899999);
            terminalStatus.innerHTML = 'Approved<small>Terminal approval captured</small>';
            terminalStatus.classList.add('approved');
        }, 700);
    });

    function splitTemplate(){
        const div = document.createElement('div');
        div.className = 'split-row';
        div.innerHTML = `<select class="select-soft" name="split_method[]"><option value="cash">Cash</option><option value="card">Bank Card</option><option value="easypaisa">Easypaisa</option><option value="jazzcash">JazzCash</option><option value="raast">Raast</option><option value="bank_transfer">Bank Transfer</option></select><input class="input-soft split-amount" type="number" step="0.01" name="split_amount[]" placeholder="Amount"><input class="input-soft split-ref" type="text" name="split_ref[]" placeholder="Ref/Auth/TXN for non-cash"><button class="split-remove" type="button">×</button>`;
        return div;
    }
    function calcSplit(){
        let total = 0;
        document.querySelectorAll('.split-amount').forEach(inp => total += parseFloat(inp.value || '0'));
        const totalEl = document.getElementById('splitTotal');
        const diffEl = document.getElementById('splitDiff');
        if (totalEl) totalEl.textContent = fmt(total);
        if (diffEl) diffEl.textContent = fmt(total - due);
    }
    const splitRows = document.getElementById('splitRows');
    const addSplit = document.getElementById('addSplit');
    addSplit && addSplit.addEventListener('click', () => { splitRows.appendChild(splitTemplate()); calcSplit(); });
    document.addEventListener('input', e => { if (e.target.classList.contains('split-amount')) calcSplit(); });
    document.addEventListener('click', e => {
        if (e.target.classList.contains('split-remove')) {
            const rows = document.querySelectorAll('.split-row');
            if (rows.length > 1) e.target.closest('.split-row').remove();
            calcSplit();
        }
    });
    calcSplit();

    const form = document.getElementById('paymentForm');
    form && form.addEventListener('submit', function(e){
        const method = methodInput.value;
        if (method === 'cash') {
            const received = parseFloat(cashInput.value || '0');
            if (received + 0.009 < due) { e.preventDefault(); alert('Cash received amount grand total se kam hai.'); }
        }
        if (method === 'card' && cardAuthorized.value !== '1' && (!cardReference || !cardReference.value.trim())) {
            e.preventDefault(); alert('Card approval / auth code required hai.');
        }
        if (['easypaisa','jazzcash','bank_transfer','raast'].includes(method)) {
            const ref = form.querySelector('[name="' + method + '_reference"]');
            if (!ref || !ref.value.trim()) { e.preventDefault(); alert('Transaction/reference number required hai.'); }
        }
        if (method === 'split') {
            let total = 0;
            let invalidRef = false;
            document.querySelectorAll('.split-row').forEach(row => {
                const sm = row.querySelector('[name="split_method[]"]')?.value || 'cash';
                const amt = parseFloat(row.querySelector('[name="split_amount[]"]')?.value || '0');
                const ref = row.querySelector('[name="split_ref[]"]')?.value.trim() || '';
                if (amt > 0) total += amt;
                if (amt > 0 && sm !== 'cash' && !ref) invalidRef = true;
            });
            if (total + 0.009 < due) { e.preventDefault(); alert('Split total payable amount se kam hai.'); return; }
            if (invalidRef) { e.preventDefault(); alert('Split payment me non-cash method ka Ref/Auth/TXN required hai.'); }
        }
    });
})();
</script>
</body>
</html>
