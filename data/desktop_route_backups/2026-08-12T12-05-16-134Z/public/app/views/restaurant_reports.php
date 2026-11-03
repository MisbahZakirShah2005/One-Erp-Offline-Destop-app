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
$branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['selected_branch_id'] ?? 0));
$role_id   = (int)($_SESSION['role_id'] ?? 0);

if ($client_id <= 0 && $role_id !== 1) {
    die('Client not found in session.');
}

/* ================= HELPERS ================= */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_fmt($amount): string
{
    return 'Rs. ' . number_format((float)$amount, 2);
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function bind_params_ref(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $refs = [];
    $refs[] = $types;
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

function csv_download(string $filename, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    } else {
        fputcsv($out, ['No data']);
    }
    fclose($out);
    exit;
}

function export_link(string $reportKey): string
{
    $params = $_GET;
    $params['export'] = $reportKey;
    return 'restaurant_reports.php?' . http_build_query($params);
}

function percent_value($part, $total): string
{
    $total = (float)$total;
    if ($total <= 0) return '0%';
    return number_format(((float)$part / $total) * 100, 1) . '%';
}

/* ================= FILTERS ================= */
$today = date('Y-m-d');
$date_from = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$date_to   = trim((string)($_GET['date_to'] ?? $today));
$branch_filter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$waiter_filter = isset($_GET['waiter_id']) ? (int)$_GET['waiter_id'] : 0;
$rider_filter = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : 0;
$order_type_filter = trim((string)($_GET['order_type'] ?? ''));
$delivery_status_filter = trim((string)($_GET['delivery_status'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = $today;

/* ================= SCHEMA CHECK ================= */
$missingTables = [];
foreach (['restaurant_tables', 'restaurant_table_sessions', 'restaurant_order_items', 'restaurant_kots', 'restaurant_kot_items'] as $t) {
    if (!table_exists($conn, $t)) {
        $missingTables[] = $t;
    }
}

/* ================= FILTER DATA ================= */
$branches = [];
$waiters = [];
$riders = [];
if (empty($missingTables)) {
    if (table_exists($conn, 'branches')) {
        $branches = fetch_all_prepared($conn, "SELECT id, name FROM branches WHERE client_id=? ORDER BY name ASC", 'i', [$client_id]);
    }
    if (table_exists($conn, 'restaurant_waiters')) {
        $waiters = fetch_all_prepared($conn, "SELECT id, name FROM restaurant_waiters WHERE client_id=? ORDER BY name ASC", 'i', [$client_id]);
    }
    if (table_exists($conn, 'users')) {
        $riderWhere = "client_id=?";
        $riderTypes = 'i';
        $riderParams = [$client_id];
        if (column_exists($conn, 'users', 'status')) {
            $riderWhere .= " AND LOWER(status)='active'";
        }
        $riders = fetch_all_prepared($conn, "SELECT id, name FROM users WHERE {$riderWhere} ORDER BY name ASC", $riderTypes, $riderParams);
    }
}

/* ================= QUERY CONDITIONS ================= */
$paidWhere = ["s.client_id=?", "s.status='paid'", "DATE(COALESCE(s.closed_at, s.updated_at, s.started_at)) BETWEEN ? AND ?"];
$paidTypes = 'iss';
$paidParams = [$client_id, $date_from, $date_to];

if ($branch_filter > 0) {
    $paidWhere[] = "s.branch_id=?";
    $paidTypes .= 'i';
    $paidParams[] = $branch_filter;
}
if ($waiter_filter > 0) {
    $paidWhere[] = "s.waiter_id=?";
    $paidTypes .= 'i';
    $paidParams[] = $waiter_filter;
}
if ($order_type_filter !== '') {
    $paidWhere[] = "s.order_type=?";
    $paidTypes .= 's';
    $paidParams[] = $order_type_filter;
}
if ($rider_filter > 0) {
    $paidWhere[] = "s.rider_id=?";
    $paidTypes .= 'i';
    $paidParams[] = $rider_filter;
}
if ($delivery_status_filter !== '') {
    $paidWhere[] = "s.delivery_status=?";
    $paidTypes .= 's';
    $paidParams[] = $delivery_status_filter;
}
$paidSqlWhere = implode(' AND ', $paidWhere);

/* ================= REPORT DATA ================= */
$summary = [
    'paid_orders' => 0,
    'gross_sales' => 0,
    'subtotal' => 0,
    'discount' => 0,
    'tax' => 0,
    'service_charge' => 0,
    'delivery_charge' => 0,
    'delivery_orders' => 0,
    'guests' => 0,
    'avg_bill' => 0,
];
$tableSales = [];
$waiterSales = [];
$kotSummary = [];
$kotDetails = [];
$cancelledItems = [];
$hourlySales = [];
$topItems = [];
$orderTypeSales = [];
$orderTypeItemSales = [];
$riderDeliverySales = [];
$deliveryStatusCounts = [];
$deliveryChargeRows = [];
$openTables = [];
$paymentMethods = [];

if (empty($missingTables)) {
    $summaryRows = fetch_all_prepared($conn, "
        SELECT
            COUNT(*) AS paid_orders,
            COALESCE(SUM(s.grand_total),0) AS gross_sales,
            COALESCE(SUM(s.subtotal),0) AS subtotal,
            COALESCE(SUM(s.discount),0) AS discount,
            COALESCE(SUM(s.tax),0) AS tax,
            COALESCE(SUM(s.service_charge),0) AS service_charge,
            COALESCE(SUM(s.delivery_charge),0) AS delivery_charge,
            SUM(CASE WHEN s.order_type='delivery' THEN 1 ELSE 0 END) AS delivery_orders,
            COALESCE(SUM(s.guest_count),0) AS guests,
            COALESCE(AVG(NULLIF(s.grand_total,0)),0) AS avg_bill
        FROM restaurant_table_sessions s
        WHERE {$paidSqlWhere}
    ", $paidTypes, $paidParams);
    if (!empty($summaryRows)) {
        foreach ($summary as $k => $v) {
            $summary[$k] = (float)($summaryRows[0][$k] ?? 0);
        }
    }

    $tableSales = fetch_all_prepared($conn, "
        SELECT
            COALESCE(NULLIF(t.table_no,''), 'No Table') AS table_no,
            COALESCE(NULLIF(t.table_name,''), '') AS table_name,
            COALESCE(NULLIF(t.floor_name,''), 'Default') AS floor_name,
            COUNT(*) AS orders,
            COALESCE(SUM(s.guest_count),0) AS guests,
            COALESCE(SUM(s.subtotal),0) AS subtotal,
            COALESCE(SUM(s.discount),0) AS discount,
            COALESCE(SUM(s.tax),0) AS tax,
            COALESCE(SUM(s.service_charge),0) AS service_charge,
            COALESCE(SUM(s.delivery_charge),0) AS delivery_charge,
            COALESCE(SUM(s.grand_total),0) AS grand_total,
            COALESCE(AVG(NULLIF(s.grand_total,0)),0) AS avg_bill
        FROM restaurant_table_sessions s
        LEFT JOIN restaurant_tables t ON t.id=s.table_id
        WHERE {$paidSqlWhere}
        GROUP BY s.table_id, t.table_no, t.table_name, t.floor_name
        ORDER BY grand_total DESC, orders DESC
    ", $paidTypes, $paidParams);

    $waiterSales = fetch_all_prepared($conn, "
        SELECT
            COALESCE(NULLIF(w.name,''), 'No Waiter') AS waiter_name,
            COUNT(*) AS orders,
            COALESCE(SUM(s.guest_count),0) AS guests,
            COALESCE(SUM(s.subtotal),0) AS subtotal,
            COALESCE(SUM(s.discount),0) AS discount,
            COALESCE(SUM(s.tax),0) AS tax,
            COALESCE(SUM(s.service_charge),0) AS service_charge,
            COALESCE(SUM(s.delivery_charge),0) AS delivery_charge,
            COALESCE(SUM(s.grand_total),0) AS grand_total,
            COALESCE(AVG(NULLIF(s.grand_total,0)),0) AS avg_bill
        FROM restaurant_table_sessions s
        LEFT JOIN restaurant_waiters w ON w.id=s.waiter_id
        WHERE {$paidSqlWhere}
        GROUP BY s.waiter_id, w.name
        ORDER BY grand_total DESC, orders DESC
    ", $paidTypes, $paidParams);

    $kotWhere = ["k.client_id=?", "DATE(k.created_at) BETWEEN ? AND ?"];
    $kotTypes = 'iss';
    $kotParams = [$client_id, $date_from, $date_to];
    if ($branch_filter > 0) {
        $kotWhere[] = "k.branch_id=?";
        $kotTypes .= 'i';
        $kotParams[] = $branch_filter;
    }
    if ($waiter_filter > 0) {
        $kotWhere[] = "k.waiter_id=?";
        $kotTypes .= 'i';
        $kotParams[] = $waiter_filter;
    }
    $kotSqlWhere = implode(' AND ', $kotWhere);

    $kotSummary = fetch_all_prepared($conn, "
        SELECT k.status, COUNT(*) AS total_kots, COALESCE(SUM(ki.item_lines),0) AS item_lines, COALESCE(SUM(ki.total_qty),0) AS total_qty
        FROM restaurant_kots k
        LEFT JOIN (
            SELECT kot_id, COUNT(*) AS item_lines, SUM(qty) AS total_qty
            FROM restaurant_kot_items
            GROUP BY kot_id
        ) ki ON ki.kot_id=k.id
        WHERE {$kotSqlWhere}
        GROUP BY k.status
        ORDER BY FIELD(k.status,'pending','preparing','ready','served','cancelled'), k.status
    ", $kotTypes, $kotParams);

    $kotDetails = fetch_all_prepared($conn, "
        SELECT k.kot_no, k.status, k.created_at, k.printed_at, t.table_no, t.table_name, w.name AS waiter_name, COALESCE(ki.item_lines,0) AS item_lines, COALESCE(ki.total_qty,0) AS total_qty
        FROM restaurant_kots k
        LEFT JOIN restaurant_tables t ON t.id=k.table_id
        LEFT JOIN restaurant_waiters w ON w.id=k.waiter_id
        LEFT JOIN (
            SELECT kot_id, COUNT(*) AS item_lines, SUM(qty) AS total_qty
            FROM restaurant_kot_items
            GROUP BY kot_id
        ) ki ON ki.kot_id=k.id
        WHERE {$kotSqlWhere}
        ORDER BY k.created_at DESC
        LIMIT 200
    ", $kotTypes, $kotParams);

    $cancelWhere = ["oi.client_id=?", "oi.item_status='cancelled'", "DATE(COALESCE(oi.updated_at, oi.created_at)) BETWEEN ? AND ?"];
    $cancelTypes = 'iss';
    $cancelParams = [$client_id, $date_from, $date_to];
    if ($branch_filter > 0) {
        $cancelWhere[] = "oi.branch_id=?";
        $cancelTypes .= 'i';
        $cancelParams[] = $branch_filter;
    }
    if ($waiter_filter > 0) {
        $cancelWhere[] = "s.waiter_id=?";
        $cancelTypes .= 'i';
        $cancelParams[] = $waiter_filter;
    }
    $cancelSqlWhere = implode(' AND ', $cancelWhere);

    $cancelledItems = fetch_all_prepared($conn, "
        SELECT oi.id, oi.session_id, oi.item_name, oi.qty, oi.price, oi.total, oi.cancelled_reason, oi.created_at, oi.updated_at,
               t.table_no, t.table_name, w.name AS waiter_name
        FROM restaurant_order_items oi
        LEFT JOIN restaurant_table_sessions s ON s.id=oi.session_id
        LEFT JOIN restaurant_tables t ON t.id=s.table_id
        LEFT JOIN restaurant_waiters w ON w.id=s.waiter_id
        WHERE {$cancelSqlWhere}
        ORDER BY COALESCE(oi.updated_at, oi.created_at) DESC
        LIMIT 300
    ", $cancelTypes, $cancelParams);

    $hourlySales = fetch_all_prepared($conn, "
        SELECT HOUR(COALESCE(s.closed_at, s.updated_at, s.started_at)) AS sale_hour,
               COUNT(*) AS orders,
               COALESCE(SUM(s.grand_total),0) AS grand_total,
               COALESCE(AVG(NULLIF(s.grand_total,0)),0) AS avg_bill
        FROM restaurant_table_sessions s
        WHERE {$paidSqlWhere}
        GROUP BY sale_hour
        ORDER BY sale_hour ASC
    ", $paidTypes, $paidParams);

    $topItems = fetch_all_prepared($conn, "
        SELECT oi.item_name,
               COALESCE(SUM(oi.qty),0) AS qty_sold,
               COALESCE(SUM(oi.total),0) AS sales_total,
               COUNT(DISTINCT oi.session_id) AS order_count
        FROM restaurant_order_items oi
        INNER JOIN restaurant_table_sessions s ON s.id=oi.session_id
        WHERE {$paidSqlWhere}
          AND oi.item_status NOT IN ('cancelled','void')
        GROUP BY oi.item_id, oi.item_name
        ORDER BY sales_total DESC, qty_sold DESC
        LIMIT 50
    ", $paidTypes, $paidParams);

    $orderTypeSales = fetch_all_prepared($conn, "
        SELECT s.order_type,
               COUNT(*) AS orders,
               COALESCE(SUM(s.guest_count),0) AS guests,
               COALESCE(SUM(s.subtotal),0) AS subtotal,
               COALESCE(SUM(s.discount),0) AS discount,
               COALESCE(SUM(s.tax),0) AS tax,
               COALESCE(SUM(s.service_charge),0) AS service_charge,
               COALESCE(SUM(s.delivery_charge),0) AS delivery_charge,
               COALESCE(SUM(s.grand_total),0) AS grand_total,
               COALESCE(AVG(NULLIF(s.grand_total,0)),0) AS avg_bill
        FROM restaurant_table_sessions s
        WHERE {$paidSqlWhere}
        GROUP BY s.order_type
        ORDER BY grand_total DESC
    ", $paidTypes, $paidParams);

    $orderTypeItemSales = fetch_all_prepared($conn, "
        SELECT s.order_type,
               oi.item_name,
               COALESCE(SUM(oi.qty),0) AS qty_sold,
               COALESCE(SUM(oi.total),0) AS sales_total,
               COUNT(DISTINCT oi.session_id) AS order_count
        FROM restaurant_order_items oi
        INNER JOIN restaurant_table_sessions s ON s.id=oi.session_id
        WHERE {$paidSqlWhere}
          AND oi.item_status NOT IN ('cancelled','void')
        GROUP BY s.order_type, oi.item_id, oi.item_name
        ORDER BY s.order_type ASC, sales_total DESC, qty_sold DESC
        LIMIT 150
    ", $paidTypes, $paidParams);

    $deliveryWhere = ["s.client_id=?", "s.order_type='delivery'", "DATE(COALESCE(s.closed_at, s.updated_at, s.started_at)) BETWEEN ? AND ?"];
    $deliveryTypes = 'iss';
    $deliveryParams = [$client_id, $date_from, $date_to];
    if ($branch_filter > 0) {
        $deliveryWhere[] = "s.branch_id=?";
        $deliveryTypes .= 'i';
        $deliveryParams[] = $branch_filter;
    }
    if ($rider_filter > 0) {
        $deliveryWhere[] = "s.rider_id=?";
        $deliveryTypes .= 'i';
        $deliveryParams[] = $rider_filter;
    }
    if ($delivery_status_filter !== '') {
        $deliveryWhere[] = "s.delivery_status=?";
        $deliveryTypes .= 's';
        $deliveryParams[] = $delivery_status_filter;
    }
    $deliverySqlWhere = implode(' AND ', $deliveryWhere);

    $riderDeliverySales = fetch_all_prepared($conn, "
        SELECT COALESCE(NULLIF(u.name,''), 'No Rider') AS rider_name,
               COUNT(*) AS orders,
               SUM(CASE WHEN s.delivery_status='pending' THEN 1 ELSE 0 END) AS pending_orders,
               SUM(CASE WHEN s.delivery_status='preparing' THEN 1 ELSE 0 END) AS preparing_orders,
               SUM(CASE WHEN s.delivery_status='ready' THEN 1 ELSE 0 END) AS ready_orders,
               SUM(CASE WHEN s.delivery_status='dispatched' THEN 1 ELSE 0 END) AS dispatched_orders,
               SUM(CASE WHEN s.delivery_status='delivered' THEN 1 ELSE 0 END) AS delivered_orders,
               SUM(CASE WHEN s.delivery_status='cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
               COALESCE(SUM(s.delivery_charge),0) AS delivery_charge,
               COALESCE(SUM(s.grand_total),0) AS grand_total,
               COALESCE(AVG(NULLIF(s.grand_total,0)),0) AS avg_bill
        FROM restaurant_table_sessions s
        LEFT JOIN users u ON u.id=s.rider_id
        WHERE {$deliverySqlWhere}
        GROUP BY s.rider_id, u.name
        ORDER BY grand_total DESC, orders DESC
    ", $deliveryTypes, $deliveryParams);

    $deliveryStatusCounts = fetch_all_prepared($conn, "
        SELECT COALESCE(NULLIF(s.delivery_status,''), 'not_set') AS delivery_status,
               COUNT(*) AS orders,
               COALESCE(SUM(s.delivery_charge),0) AS delivery_charge,
               COALESCE(SUM(s.grand_total),0) AS grand_total
        FROM restaurant_table_sessions s
        WHERE {$deliverySqlWhere}
        GROUP BY s.delivery_status
        ORDER BY FIELD(s.delivery_status,'pending','preparing','ready','dispatched','delivered','cancelled'), s.delivery_status
    ", $deliveryTypes, $deliveryParams);

    $deliveryChargeRows = fetch_all_prepared($conn, "
        SELECT DATE(COALESCE(s.closed_at, s.updated_at, s.started_at)) AS report_date,
               COUNT(*) AS delivery_orders,
               COALESCE(SUM(s.delivery_charge),0) AS delivery_charge,
               COALESCE(SUM(s.grand_total),0) AS grand_total
        FROM restaurant_table_sessions s
        WHERE {$deliverySqlWhere}
        GROUP BY report_date
        ORDER BY report_date DESC
    ", $deliveryTypes, $deliveryParams);

    $openWhere = ["s.client_id=?", "s.status IN ('open','kot_sent','bill_printed')"];
    $openTypes = 'i';
    $openParams = [$client_id];
    if ($branch_filter > 0) {
        $openWhere[] = "s.branch_id=?";
        $openTypes .= 'i';
        $openParams[] = $branch_filter;
    }
    if ($waiter_filter > 0) {
        $openWhere[] = "s.waiter_id=?";
        $openTypes .= 'i';
        $openParams[] = $waiter_filter;
    }
    if ($order_type_filter !== '') {
        $openWhere[] = "s.order_type=?";
        $openTypes .= 's';
        $openParams[] = $order_type_filter;
    }
    if ($rider_filter > 0) {
        $openWhere[] = "s.rider_id=?";
        $openTypes .= 'i';
        $openParams[] = $rider_filter;
    }
    if ($delivery_status_filter !== '') {
        $openWhere[] = "s.delivery_status=?";
        $openTypes .= 's';
        $openParams[] = $delivery_status_filter;
    }
    $openSqlWhere = implode(' AND ', $openWhere);

    $openTables = fetch_all_prepared($conn, "
        SELECT s.id AS session_id, s.status, s.started_at, s.grand_total, s.guest_count, s.order_type,
               s.token_no, s.customer_name, s.customer_phone, s.delivery_status, s.delivery_charge, s.delivery_address,
               t.table_no, t.table_name, t.floor_name, w.name AS waiter_name, u.name AS rider_name,
               TIMESTAMPDIFF(MINUTE, s.started_at, NOW()) AS open_minutes
        FROM restaurant_table_sessions s
        LEFT JOIN restaurant_tables t ON t.id=s.table_id
        LEFT JOIN restaurant_waiters w ON w.id=s.waiter_id
        LEFT JOIN users u ON u.id=s.rider_id
        WHERE {$openSqlWhere}
        ORDER BY s.started_at ASC
    ", $openTypes, $openParams);

    if (table_exists($conn, 'payments') && column_exists($conn, 'payments', 'restaurant_session_id')) {
        $paymentMethods = fetch_all_prepared($conn, "
            SELECT COALESCE(NULLIF(p.payment_method,''),'Unknown') AS payment_method,
                   COUNT(*) AS payment_count,
                   COALESCE(SUM(p.amount),0) AS amount
            FROM payments p
            INNER JOIN restaurant_table_sessions s ON s.id=p.restaurant_session_id
            WHERE {$paidSqlWhere}
            GROUP BY p.payment_method
            ORDER BY amount DESC
        ", $paidTypes, $paidParams);
    }
}

/* ================= CSV EXPORT ================= */
if ($export !== '' && empty($missingTables)) {
    $exportMap = [
        'table_sales' => $tableSales,
        'waiter_sales' => $waiterSales,
        'kot_summary' => $kotSummary,
        'kot_details' => $kotDetails,
        'cancelled_items' => $cancelledItems,
        'hourly_sales' => $hourlySales,
        'top_items' => $topItems,
        'order_types' => $orderTypeSales,
        'order_type_items' => $orderTypeItemSales,
        'rider_delivery_sales' => $riderDeliverySales,
        'delivery_status' => $deliveryStatusCounts,
        'delivery_charges' => $deliveryChargeRows,
        'open_tables' => $openTables,
        'payments' => $paymentMethods,
    ];
    if (isset($exportMap[$export])) {
        csv_download('restaurant_' . $export . '_' . date('Ymd_His') . '.csv', $exportMap[$export]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Restaurant Reports - OneERP</title>
    <?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        html, body { margin:0; min-height:100%; background:#050914 !important; color:#f8fafc; overflow-x:hidden; }
        body { font-family: Inter, "Segoe UI", Arial, sans-serif; }
        .container-scroll { display:flex; min-height:100vh; background:radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%), radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%), linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important; }
        .sidebar { width:270px; flex:0 0 270px; }
        .main-panel { flex:1; min-width:0; padding-top:70px; }
        .topbar-wrap { position:fixed; top:0; left:270px; right:0; height:70px; z-index:1030; background:#070b14; border-bottom:1px solid rgba(148,163,184,.14); }
        .content-wrapper { padding:26px; min-height:calc(100vh - 70px); position:relative; }
        .content-wrapper::before { content:""; position:absolute; inset:0; pointer-events:none; background:linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px); background-size:44px 44px; opacity:.22; }
        .content-wrapper > * { position:relative; z-index:1; }
        .page-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:20px; }
        .page-title { font-size:28px; font-weight:950; letter-spacing:-.04em; margin:0; color:#fff; text-shadow:0 0 28px rgba(124,58,237,.35); }
        .page-subtitle { color:#aab4cf; font-weight:700; margin-top:4px; }
        .glass-card { background:radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%), linear-gradient(135deg, rgba(15,23,42,.78), rgba(17,24,39,.56)); border:1px solid rgba(148,163,184,.18); border-radius:20px; box-shadow:0 18px 48px rgba(0,0,0,.30), inset 0 1px 0 rgba(255,255,255,.05); backdrop-filter:blur(18px) saturate(135%); overflow:hidden; }
        .stat-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px; margin-bottom:18px; }
        .stat-card { padding:18px; }
        .stat-label { color:#94a3b8; font-weight:800; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
        .stat-value { color:#fff; font-size:27px; font-weight:950; line-height:1.1; margin-top:8px; }
        .filter-card { padding:18px; margin-bottom:18px; }
        label { color:#dbe4ff; font-weight:850; font-size:13px; margin-bottom:7px; }
        .form-control, .form-select { min-height:44px; border-radius:13px; background:rgba(15,23,42,.76) !important; color:#f8fafc !important; border:1px solid rgba(148,163,184,.20) !important; font-weight:750; box-shadow:inset 0 1px 0 rgba(255,255,255,.04); }
        .form-control:focus, .form-select:focus { border-color:rgba(124,58,237,.65) !important; box-shadow:0 0 0 3px rgba(124,58,237,.16) !important; }
        .form-select option { background:#0f172a; color:#e5e7eb; }
        .btn-main { min-height:44px; border-radius:13px; border:0; padding:0 18px; color:#fff !important; font-weight:900; background:linear-gradient(135deg,#6d4cff,#7c3aed); box-shadow:0 14px 28px rgba(109,76,255,.30); display:inline-flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; }
        .btn-soft { min-height:42px; border-radius:12px; background:rgba(15,23,42,.75); color:#e5e7eb !important; border:1px solid rgba(148,163,184,.18); font-weight:850; display:inline-flex; align-items:center; justify-content:center; gap:7px; text-decoration:none; }
        .section { margin-bottom:20px; }
        .section-head { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:16px 18px; border-bottom:1px solid rgba(148,163,184,.14); }
        .section-title { margin:0; color:#fff; font-size:18px; font-weight:950; letter-spacing:-.02em; }
        .table-wrap { overflow:auto; }
        table { width:100%; margin:0; color:#e5e7eb !important; border-collapse:separate; border-spacing:0; }
        thead th { background:rgba(6,10,20,.95); color:#f8fafc; padding:14px 15px; font-size:12px; text-transform:uppercase; letter-spacing:.06em; border-bottom:1px solid rgba(139,92,246,.24); white-space:nowrap; }
        tbody td { padding:14px 15px; border-bottom:1px solid rgba(148,163,184,.10); background:linear-gradient(135deg, rgba(15,23,42,.42), rgba(20,12,40,.28)); vertical-align:middle; font-weight:700; }
        tbody tr:hover td { background:linear-gradient(135deg, rgba(30,20,58,.58), rgba(15,23,42,.42)); }
        .tiny { color:#94a3b8; font-size:12px; font-weight:700; }
        .badge-status { padding:7px 10px; border-radius:999px; font-size:12px; font-weight:950; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
        .badge-pending { background:rgba(59,130,246,.14); color:#bfdbfe; border:1px solid rgba(59,130,246,.28); }
        .badge-preparing { background:rgba(168,85,247,.14); color:#e9d5ff; border:1px solid rgba(168,85,247,.28); }
        .badge-ready { background:rgba(245,158,11,.14); color:#fde68a; border:1px solid rgba(245,158,11,.30); }
        .badge-served { background:rgba(34,197,94,.14); color:#bbf7d0; border:1px solid rgba(34,197,94,.28); }
        .badge-cancelled { background:rgba(239,68,68,.14); color:#fecaca; border:1px solid rgba(239,68,68,.28); }
        .alert-danger { border-radius:14px; background:rgba(239,68,68,.14); color:#fecaca; border:1px solid rgba(239,68,68,.28); font-weight:800; }
        .progress-line { height:8px; background:rgba(15,23,42,.90); border-radius:99px; overflow:hidden; min-width:120px; }
        .progress-line span { display:block; height:100%; background:linear-gradient(90deg,#6d4cff,#7c3aed); border-radius:99px; }
        @media(max-width:991px){ .sidebar{flex-basis:0;width:0}.topbar-wrap{left:0}.main-panel{padding-top:70px}.content-wrapper{padding:16px}.stat-grid{grid-template-columns:repeat(2,1fr)} table{min-width:980px} }
        @media(max-width:576px){ .stat-grid{grid-template-columns:1fr}.page-head{display:block}.btn-main,.btn-soft{width:100%;margin-top:8px} }
   
   /* ================= RESTAURANT REPORTS UI FIX PATCH ================= */

html,
body {
    background: #050914 !important;
    color: #f8fafc !important;
}

.container-scroll {
    background:
        radial-gradient(circle at 15% 5%, rgba(124,58,237,.22), transparent 28%),
        linear-gradient(135deg,#050914 0%,#0b1024 45%,#120a24 100%) !important;
}

.main-panel {
    padding-top: 70px !important;
    min-height: 100vh !important;
    background: transparent !important;
}

.content-wrapper {
    padding: 28px 24px 40px !important;
    background: transparent !important;
}

.page-head {
    align-items: center !important;
    margin-bottom: 22px !important;
}

.page-title {
    font-size: 26px !important;
    line-height: 1.15 !important;
}

.page-subtitle {
    font-size: 14px !important;
    color: #aeb8d0 !important;
}

.glass-card {
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.92), rgba(30,41,59,.78)) !important;
    border: 1px solid rgba(148,163,184,.18) !important;
    border-radius: 18px !important;
    box-shadow: 0 18px 44px rgba(0,0,0,.35) !important;
}

.stat-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 14px !important;
}

.stat-card {
    min-height: 92px !important;
    padding: 18px !important;
}

.stat-label {
    font-size: 11px !important;
    color: #aab4cf !important;
}

.stat-value {
    font-size: 25px !important;
    word-break: break-word !important;
}

.filter-card {
    padding: 18px !important;
}

.filter-card .row {
    align-items: end !important;
}

.form-control,
.form-select {
    height: 44px !important;
    min-height: 44px !important;
    border-radius: 10px !important;
    background: rgba(15,23,42,.92) !important;
}

.btn-main,
.btn-soft {
    height: 44px !important;
    min-height: 44px !important;
    border-radius: 11px !important;
}

.section {
    margin-bottom: 18px !important;
}

.section-head {
    padding: 14px 16px !important;
    align-items: center !important;
}

.section-title {
    font-size: 16px !important;
}

.table-wrap {
    width: 100% !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
}

table {
    min-width: 1050px !important;
}

thead th {
    padding: 13px 14px !important;
    background: #070b18 !important;
    color: #ffffff !important;
    font-size: 11px !important;
}

tbody td {
    padding: 13px 14px !important;
    color: #e5e7eb !important;
    background: rgba(15,23,42,.56) !important;
}

tbody tr:hover td {
    background: rgba(30,41,59,.78) !important;
}

@media (max-width: 991px) {
    .sidebar {
        width: 0 !important;
        flex: 0 0 0 !important;
    }

    .topbar-wrap {
        left: 0 !important;
    }

    .content-wrapper {
        padding: 18px 14px 35px !important;
    }

    .page-head {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 576px) {
    .stat-grid {
        grid-template-columns: 1fr !important;
    }

    .stat-value {
        font-size: 22px !important;
    }

    .page-title {
        font-size: 23px !important;
    }
}

/* ===== RESTAURANT REPORTS FINAL UI ALIGNMENT FIX ===== */

.page-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:18px !important;
}

.page-head > .d-flex{
    align-items:center !important;
    justify-content:flex-end !important;
    flex-wrap:nowrap !important;
}

.page-head .btn-soft,
.page-head .btn-main{
    min-width:105px !important;
    height:42px !important;
    padding:0 14px !important;
    white-space:nowrap !important;
}

.section-head{
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
}

.section-head .section-title{
    margin:0 !important;
    line-height:1.2 !important;
}

.section-head .btn-soft{
    width:auto !important;
    min-width:76px !important;
    height:36px !important;
    min-height:36px !important;
    padding:0 12px !important;
    font-size:12px !important;
    white-space:nowrap !important;
    flex:0 0 auto !important;
}

.filter-card .row{
    align-items:end !important;
}

.filter-card .btn-main,
.filter-card .btn-soft{
    width:100% !important;
    height:44px !important;
}

.table-wrap{
    width:100% !important;
    overflow-x:auto !important;
}

.table-wrap table{
    width:100% !important;
    min-width:1100px !important;
}

tbody td[colspan]{
    text-align:center !important;
}

@media(max-width:991px){
    .page-head{
        align-items:stretch !important;
        flex-direction:column !important;
    }

    .page-head > .d-flex{
        width:100% !important;
        justify-content:flex-start !important;
    }

    .page-head .btn-soft,
    .page-head .btn-main{
        flex:1 !important;
    }

    .section-head{
        padding:12px 14px !important;
    }
}

@media(max-width:576px){
    .page-head > .d-flex{
        flex-direction:column !important;
    }

    .page-head .btn-soft,
    .page-head .btn-main{
        width:100% !important;
    }
}
    </style>
</head>
<body>
<div class="container-scroll">
    <div class="sidebar"><?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?></div>
    <div class="main-panel">
        <div class="topbar-wrap"><?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?></div>
        <div class="content-wrapper">
            <div class="page-head">
                <div>
                    <h1 class="page-title">Restaurant Reports</h1>
                    <div class="page-subtitle">Dine In, Takeaway, Delivery, rider-wise sales, KOT, delivery charges, cancelled items and open order reports.</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="restaurant_orders.php" class="btn-soft"><i class="bi bi-card-checklist"></i> Orders</a>
                    <a href="restaurant_dashboard.php" class="btn-main"><i class="bi bi-grid"></i> Dashboard</a>
                </div>
            </div>

            <?php if(!empty($missingTables)): ?>
                <div class="alert alert-danger">Missing DB tables: <?= e(implode(', ', $missingTables)) ?>. Please import restaurant management migration SQL first.</div>
            <?php else: ?>
                <div class="stat-grid">
                    <div class="glass-card stat-card"><div class="stat-label">Paid Orders</div><div class="stat-value"><?= (int)$summary['paid_orders'] ?></div></div>
                    <div class="glass-card stat-card"><div class="stat-label">Gross Sales</div><div class="stat-value"><?= money_fmt($summary['gross_sales']) ?></div></div>
                    <div class="glass-card stat-card"><div class="stat-label">Delivery Orders</div><div class="stat-value"><?= (int)$summary['delivery_orders'] ?></div></div>
                    <div class="glass-card stat-card"><div class="stat-label">Delivery Charges</div><div class="stat-value"><?= money_fmt($summary['delivery_charge']) ?></div></div>
                </div>

                <form class="glass-card filter-card" method="get">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2"><label>From</label><input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>"></div>
                        <div class="col-md-2"><label>To</label><input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>"></div>
                        <div class="col-md-2"><label>Branch</label><select name="branch_id" class="form-select"><option value="0">All Branches</option>
                            <?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $branch_filter===(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-md-2"><label>Waiter</label><select name="waiter_id" class="form-select"><option value="0">All Waiters</option>
                            <?php foreach($waiters as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $waiter_filter===(int)$w['id']?'selected':'' ?>><?= e($w['name']) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-md-2"><label>Order Type</label><select name="order_type" class="form-select"><option value="">All</option>
                            <?php foreach(['dine_in'=>'Dine In','takeaway'=>'Takeaway','delivery'=>'Delivery'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $order_type_filter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-md-1 d-grid"><button class="btn-main" type="submit"><i class="bi bi-funnel"></i></button></div>
                        <div class="col-md-1 d-grid"><a class="btn-soft" href="restaurant_reports.php"><i class="bi bi-x-circle"></i></a></div>
                    </div>
                </form>

                <div class="glass-card stat-card mb-3">
                    <div class="row g-3">
                        <div class="col-md-3"><div class="stat-label">Subtotal</div><div class="stat-value fs-4"><?= money_fmt($summary['subtotal']) ?></div></div>
                        <div class="col-md-3"><div class="stat-label">Discount</div><div class="stat-value fs-4"><?= money_fmt($summary['discount']) ?></div></div>
                        <div class="col-md-3"><div class="stat-label">Tax</div><div class="stat-value fs-4"><?= money_fmt($summary['tax']) ?></div></div>
                        <div class="col-md-3"><div class="stat-label">Service + Delivery</div><div class="stat-value fs-4"><?= money_fmt((float)$summary['service_charge'] + (float)$summary['delivery_charge']) ?></div><div class="tiny">Service: <?= money_fmt($summary['service_charge']) ?> · Delivery: <?= money_fmt($summary['delivery_charge']) ?></div></div>
                    </div>
                </div>

                <div class="glass-card section">
                    <div class="section-head"><h2 class="section-title">Table-wise Sales</h2><a class="btn-soft" href="<?= e(export_link('table_sales')) ?>"><i class="bi bi-download"></i> CSV</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Table</th><th>Floor</th><th>Orders</th><th>Guests</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Service</th><th>Delivery</th><th>Total</th><th>Avg Bill</th></tr></thead><tbody>
                    <?php if(empty($tableSales)): ?><tr><td colspan="10" class="text-center py-4 tiny">No table-wise sales found.</td></tr><?php endif; ?>
                    <?php foreach($tableSales as $r): ?><tr><td><strong><?= e($r['table_no']) ?></strong><div class="tiny"><?= e($r['table_name']) ?></div></td><td><?= e($r['floor_name']) ?></td><td><?= (int)$r['orders'] ?></td><td><?= (int)$r['guests'] ?></td><td><?= money_fmt($r['subtotal']) ?></td><td><?= money_fmt($r['discount']) ?></td><td><?= money_fmt($r['tax']) ?></td><td><?= money_fmt($r['service_charge']) ?></td><td><?= money_fmt($r['delivery_charge'] ?? 0) ?></td><td><strong><?= money_fmt($r['grand_total']) ?></strong></td><td><?= money_fmt($r['avg_bill']) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>

                <div class="glass-card section">
                    <div class="section-head"><h2 class="section-title">Waiter-wise Sales</h2><a class="btn-soft" href="<?= e(export_link('waiter_sales')) ?>"><i class="bi bi-download"></i> CSV</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Waiter</th><th>Orders</th><th>Guests</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Service</th><th>Delivery</th><th>Total</th><th>Avg Bill</th><th>Contribution</th></tr></thead><tbody>
                    <?php if(empty($waiterSales)): ?><tr><td colspan="10" class="text-center py-4 tiny">No waiter-wise sales found.</td></tr><?php endif; ?>
                    <?php foreach($waiterSales as $r): $pct=percent_value($r['grand_total'],$summary['gross_sales']); ?><tr><td><strong><?= e($r['waiter_name']) ?></strong></td><td><?= (int)$r['orders'] ?></td><td><?= (int)$r['guests'] ?></td><td><?= money_fmt($r['subtotal']) ?></td><td><?= money_fmt($r['discount']) ?></td><td><?= money_fmt($r['tax']) ?></td><td><?= money_fmt($r['service_charge']) ?></td><td><?= money_fmt($r['delivery_charge'] ?? 0) ?></td><td><strong><?= money_fmt($r['grand_total']) ?></strong></td><td><?= money_fmt($r['avg_bill']) ?></td><td><div class="d-flex align-items-center gap-2"><div class="progress-line"><span style="width:<?= e($pct) ?>"></span></div><span class="tiny"><?= e($pct) ?></span></div></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>

                <div class="glass-card section">
                    <div class="section-head"><h2 class="section-title">Most Selling Menu Items</h2><a class="btn-soft" href="<?= e(export_link('top_items')) ?>"><i class="bi bi-download"></i> CSV</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Item</th><th>Qty Sold</th><th>Orders</th><th>Sales Total</th><th>Contribution</th></tr></thead><tbody>
                    <?php if(empty($topItems)): ?><tr><td colspan="5" class="text-center py-4 tiny">No item sales found.</td></tr><?php endif; ?>
                    <?php foreach($topItems as $r): $pct=percent_value($r['sales_total'],$summary['gross_sales']); ?><tr><td><strong><?= e($r['item_name']) ?></strong></td><td><?= number_format((float)$r['qty_sold'], 2) ?></td><td><?= (int)$r['order_count'] ?></td><td><strong><?= money_fmt($r['sales_total']) ?></strong></td><td><div class="d-flex align-items-center gap-2"><div class="progress-line"><span style="width:<?= e($pct) ?>"></span></div><span class="tiny"><?= e($pct) ?></span></div></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="glass-card section h-100">
                            <div class="section-head"><h2 class="section-title">Hourly Sales</h2><a class="btn-soft" href="<?= e(export_link('hourly_sales')) ?>"><i class="bi bi-download"></i></a></div>
                            <div class="table-wrap"><table><thead><tr><th>Hour</th><th>Orders</th><th>Total</th><th>Avg Bill</th></tr></thead><tbody>
                            <?php if(empty($hourlySales)): ?><tr><td colspan="4" class="text-center py-4 tiny">No hourly data.</td></tr><?php endif; ?>
                            <?php foreach($hourlySales as $r): $hour=(int)$r['sale_hour']; ?><tr><td><?= e(date('h:00 A', strtotime(sprintf('%02d:00:00',$hour)))) ?></td><td><?= (int)$r['orders'] ?></td><td><strong><?= money_fmt($r['grand_total']) ?></strong></td><td><?= money_fmt($r['avg_bill']) ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="glass-card section h-100">
                            <div class="section-head"><h2 class="section-title">Dine-in / Takeaway / Delivery</h2><a class="btn-soft" href="<?= e(export_link('order_types')) ?>"><i class="bi bi-download"></i></a></div>
                            <div class="table-wrap"><table><thead><tr><th>Type</th><th>Orders</th><th>Guests</th><th>Subtotal</th><th>Service</th><th>Delivery</th><th>Total</th><th>Avg Bill</th></tr></thead><tbody>
                            <?php if(empty($orderTypeSales)): ?><tr><td colspan="8" class="text-center py-4 tiny">No order type data.</td></tr><?php endif; ?>
                            <?php foreach($orderTypeSales as $r): ?><tr><td><?= e(ucwords(str_replace('_',' ',(string)$r['order_type']))) ?></td><td><?= (int)$r['orders'] ?></td><td><?= (int)$r['guests'] ?></td><td><?= money_fmt($r['subtotal'] ?? 0) ?></td><td><?= money_fmt($r['service_charge'] ?? 0) ?></td><td><?= money_fmt($r['delivery_charge'] ?? 0) ?></td><td><strong><?= money_fmt($r['grand_total']) ?></strong></td><td><?= money_fmt($r['avg_bill']) ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="glass-card section h-100">
                            <div class="section-head"><h2 class="section-title">KOT Summary</h2><a class="btn-soft" href="<?= e(export_link('kot_summary')) ?>"><i class="bi bi-download"></i></a></div>
                            <div class="table-wrap"><table><thead><tr><th>Status</th><th>KOTs</th><th>Item Lines</th><th>Total Qty</th></tr></thead><tbody>
                            <?php if(empty($kotSummary)): ?><tr><td colspan="4" class="text-center py-4 tiny">No KOT summary.</td></tr><?php endif; ?>
                            <?php foreach($kotSummary as $r): ?><tr><td><span class="badge-status badge-<?= e($r['status']) ?>"><?= e(ucwords($r['status'])) ?></span></td><td><?= (int)$r['total_kots'] ?></td><td><?= (int)$r['item_lines'] ?></td><td><?= number_format((float)$r['total_qty'], 2) ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="glass-card section h-100">
                            <div class="section-head"><h2 class="section-title">Payment Methods</h2><a class="btn-soft" href="<?= e(export_link('payments')) ?>"><i class="bi bi-download"></i></a></div>
                            <div class="table-wrap"><table><thead><tr><th>Method</th><th>Count</th><th>Amount</th><th>Share</th></tr></thead><tbody>
                            <?php if(empty($paymentMethods)): ?><tr><td colspan="4" class="text-center py-4 tiny">No payment method data.</td></tr><?php endif; ?>
                            <?php foreach($paymentMethods as $r): ?><tr><td><?= e(ucwords(str_replace('_',' ',(string)$r['payment_method']))) ?></td><td><?= (int)$r['payment_count'] ?></td><td><strong><?= money_fmt($r['amount']) ?></strong></td><td><?= e(percent_value($r['amount'],$summary['gross_sales'])) ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="glass-card section h-100">
                            <div class="section-head"><h2 class="section-title">Rider-wise Delivery Sales</h2><a class="btn-soft" href="<?= e(export_link('rider_delivery_sales')) ?>"><i class="bi bi-download"></i></a></div>
                            <div class="table-wrap"><table><thead><tr><th>Rider</th><th>Orders</th><th>Ready</th><th>Dispatched</th><th>Delivered</th><th>Delivery Charge</th><th>Total</th><th>Avg</th></tr></thead><tbody>
                            <?php if(empty($riderDeliverySales)): ?><tr><td colspan="8" class="text-center py-4 tiny">No rider delivery data.</td></tr><?php endif; ?>
                            <?php foreach($riderDeliverySales as $r): ?><tr><td><strong><?= e($r['rider_name']) ?></strong><div class="tiny">Pending: <?= (int)$r['pending_orders'] ?> · Preparing: <?= (int)$r['preparing_orders'] ?></div></td><td><?= (int)$r['orders'] ?></td><td><?= (int)$r['ready_orders'] ?></td><td><?= (int)$r['dispatched_orders'] ?></td><td><?= (int)$r['delivered_orders'] ?></td><td><?= money_fmt($r['delivery_charge']) ?></td><td><strong><?= money_fmt($r['grand_total']) ?></strong></td><td><?= money_fmt($r['avg_bill']) ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="glass-card section h-100">
                            <div class="section-head"><h2 class="section-title">Delivery Status Summary</h2><a class="btn-soft" href="<?= e(export_link('delivery_status')) ?>"><i class="bi bi-download"></i></a></div>
                            <div class="table-wrap"><table><thead><tr><th>Status</th><th>Orders</th><th>Delivery Charge</th><th>Total</th></tr></thead><tbody>
                            <?php if(empty($deliveryStatusCounts)): ?><tr><td colspan="4" class="text-center py-4 tiny">No delivery status data.</td></tr><?php endif; ?>
                            <?php foreach($deliveryStatusCounts as $r): ?><tr><td><span class="badge-status badge-<?= e(str_replace('_','-',(string)$r['delivery_status'])) ?>"><?= e(ucwords(str_replace('_',' ',(string)$r['delivery_status']))) ?></span></td><td><?= (int)$r['orders'] ?></td><td><?= money_fmt($r['delivery_charge']) ?></td><td><strong><?= money_fmt($r['grand_total']) ?></strong></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </div>
                </div>

                <div class="glass-card section">
                    <div class="section-head"><h2 class="section-title">Order Type Wise Item Sales</h2><a class="btn-soft" href="<?= e(export_link('order_type_items')) ?>"><i class="bi bi-download"></i> CSV</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Type</th><th>Item</th><th>Qty Sold</th><th>Orders</th><th>Sales Total</th><th>Contribution</th></tr></thead><tbody>
                    <?php if(empty($orderTypeItemSales)): ?><tr><td colspan="6" class="text-center py-4 tiny">No order type item sales found.</td></tr><?php endif; ?>
                    <?php foreach($orderTypeItemSales as $r): $pct=percent_value($r['sales_total'],$summary['gross_sales']); ?><tr><td><?= e(ucwords(str_replace('_',' ',(string)$r['order_type']))) ?></td><td><strong><?= e($r['item_name']) ?></strong></td><td><?= number_format((float)$r['qty_sold'], 2) ?></td><td><?= (int)$r['order_count'] ?></td><td><strong><?= money_fmt($r['sales_total']) ?></strong></td><td><div class="d-flex align-items-center gap-2"><div class="progress-line"><span style="width:<?= e($pct) ?>"></span></div><span class="tiny"><?= e($pct) ?></span></div></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>

                <div class="glass-card section">
                    <div class="section-head"><h2 class="section-title">Daily Delivery Charges</h2><a class="btn-soft" href="<?= e(export_link('delivery_charges')) ?>"><i class="bi bi-download"></i> CSV</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Delivery Orders</th><th>Delivery Charge</th><th>Total Sales</th></tr></thead><tbody>
                    <?php if(empty($deliveryChargeRows)): ?><tr><td colspan="4" class="text-center py-4 tiny">No delivery charge data.</td></tr><?php endif; ?>
                    <?php foreach($deliveryChargeRows as $r): ?><tr><td><?= e(date('d M Y', strtotime((string)$r['report_date']))) ?></td><td><?= (int)$r['delivery_orders'] ?></td><td><strong><?= money_fmt($r['delivery_charge']) ?></strong></td><td><?= money_fmt($r['grand_total']) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>

                <div class="glass-card section">
                    <div class="section-head"><h2 class="section-title">Recent KOT Detail</h2><a class="btn-soft" href="<?= e(export_link('kot_details')) ?>"><i class="bi bi-download"></i> CSV</a></div>
                    <div class="table-wrap"><table><thead><tr><th>KOT No</th><th>Table</th><th>Waiter</th><th>Status</th><th>Lines</th><th>Qty</th><th>Created</th><th>Printed</th></tr></thead><tbody>
                    <?php if(empty($kotDetails)): ?><tr><td colspan="8" class="text-center py-4 tiny">No KOT details.</td></tr><?php endif; ?>
                    <?php foreach($kotDetails as $r): ?><tr><td><strong><?= e($r['kot_no']) ?></strong></td><td><?= e(($r['table_no'] ?? 'No Table') . (!empty($r['table_name']) ? ' - '.$r['table_name'] : '')) ?></td><td><?= e($r['waiter_name'] ?? 'N/A') ?></td><td><span class="badge-status badge-<?= e($r['status']) ?>"><?= e(ucwords($r['status'])) ?></span></td><td><?= (int)$r['item_lines'] ?></td><td><?= number_format((float)$r['total_qty'], 2) ?></td><td><?= e(date('d M Y h:i A', strtotime((string)$r['created_at']))) ?></td><td><?= !empty($r['printed_at']) ? e(date('d M h:i A', strtotime((string)$r['printed_at']))) : '<span class="tiny">Not printed</span>' ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>

                <div class="glass-card section">
                    <div class="section-head"><h2 class="section-title">Cancelled Items</h2><a class="btn-soft" href="<?= e(export_link('cancelled_items')) ?>"><i class="bi bi-download"></i> CSV</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Session</th><th>Table</th><th>Waiter</th><th>Item</th><th>Qty</th><th>Total</th><th>Reason</th><th>Date</th></tr></thead><tbody>
                    <?php if(empty($cancelledItems)): ?><tr><td colspan="8" class="text-center py-4 tiny">No cancelled items.</td></tr><?php endif; ?>
                    <?php foreach($cancelledItems as $r): ?><tr><td>#<?= (int)$r['session_id'] ?></td><td><?= e(($r['table_no'] ?? 'No Table') . (!empty($r['table_name']) ? ' - '.$r['table_name'] : '')) ?></td><td><?= e($r['waiter_name'] ?? 'N/A') ?></td><td><strong><?= e($r['item_name']) ?></strong></td><td><?= number_format((float)$r['qty'], 2) ?></td><td><?= money_fmt($r['total']) ?></td><td><?= e($r['cancelled_reason'] ?? '') ?></td><td><?= e(date('d M Y h:i A', strtotime((string)($r['updated_at'] ?: $r['created_at'])))) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>

                <div class="glass-card section">
                    <div class="section-head"><h2 class="section-title">Open Orders Report</h2><a class="btn-soft" href="<?= e(export_link('open_tables')) ?>"><i class="bi bi-download"></i> CSV</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Session</th><th>Order Ref</th><th>Staff</th><th>Customer / Delivery</th><th>Status</th><th>Type</th><th>Guests</th><th>Open Time</th><th>Total</th><th>Action</th></tr></thead><tbody>
                    <?php if(empty($openTables)): ?><tr><td colspan="10" class="text-center py-4 tiny">No open tables right now.</td></tr><?php endif; ?>
                    <?php foreach($openTables as $r):
                        $ref = ($r['order_type'] === 'dine_in')
                            ? (($r['table_no'] ?? 'No Table') . (!empty($r['table_name']) ? ' - '.$r['table_name'] : ''))
                            : (($r['order_type'] === 'delivery' ? 'Delivery ' : 'Takeaway ') . (!empty($r['token_no']) ? '#'.$r['token_no'] : '#'.$r['session_id']));
                    ?><tr><td>#<?= (int)$r['session_id'] ?></td><td><strong><?= e($ref) ?></strong><div class="tiny"><?= e($r['floor_name'] ?? '') ?></div></td><td>Waiter: <?= e($r['waiter_name'] ?? 'N/A') ?><div class="tiny">Rider: <?= e($r['rider_name'] ?? 'N/A') ?></div></td><td><?= e($r['customer_name'] ?: 'Walk-in') ?><div class="tiny"><?= e($r['customer_phone'] ?? '') ?> <?= !empty($r['delivery_status']) ? '· '.e(ucwords((string)$r['delivery_status'])) : '' ?></div><div class="tiny"><?= e($r['delivery_address'] ?? '') ?></div></td><td><span class="badge-status badge-<?= e(str_replace('_','-',(string)$r['status'])) ?>"><?= e(ucwords(str_replace('_',' ',(string)$r['status']))) ?></span></td><td><?= e(ucwords(str_replace('_',' ',(string)$r['order_type']))) ?></td><td><?= (int)$r['guest_count'] ?></td><td><?= (int)$r['open_minutes'] ?> min<div class="tiny"><?= e(date('d M h:i A', strtotime((string)$r['started_at']))) ?></div></td><td><strong><?= money_fmt($r['grand_total']) ?></strong><div class="tiny">Delivery: <?= money_fmt($r['delivery_charge'] ?? 0) ?></div></td><td><a class="btn-soft" href="restaurant_pos.php?session_id=<?= (int)$r['session_id'] ?>"><i class="bi bi-arrow-right"></i> Open</a></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
