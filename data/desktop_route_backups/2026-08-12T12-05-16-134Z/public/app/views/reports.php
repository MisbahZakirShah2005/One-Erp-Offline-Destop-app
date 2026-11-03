<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
/* ============ AUTH CHECK ============ */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

/* ============ HELPERS ============ */
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return $cache[$table] = false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$table] = ((int)($row['c'] ?? 0) > 0);
}

function colExists(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return $cache[$key] = false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function firstCol(mysqli $conn, string $table, array $cols, string $fallback = ''): string {
    foreach ($cols as $c) {
        if (colExists($conn, $table, $c)) return $c;
    }
    return $fallback;
}

function scalarVal(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? null;
}

function fetchRows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function saleTotalCol(mysqli $conn): string {
    return firstCol($conn, 'sales', ['grand_total', 'total_amount', 'total', 'net_total'], 'total');
}

function saleDateCol(mysqli $conn): string {
    return firstCol($conn, 'sales', ['created_at', 'sale_date', 'order_date', 'date'], 'created_at');
}

function invoiceCol(mysqli $conn): string {
    return firstCol($conn, 'sales', ['invoice_no', 'order_no', 'reference_no'], 'id');
}

function itemNameCol(mysqli $conn): string {
    return firstCol($conn, 'items', ['name', 'item_name', 'title'], 'name');
}

function itemSkuCol(mysqli $conn): string {
    return firstCol($conn, 'items', ['sku', 'barcode', 'code'], 'sku');
}

function customerNameCol(mysqli $conn): string {
    return firstCol($conn, 'customers', ['name', 'customer_name', 'full_name'], 'name');
}

function customerPhoneCol(mysqli $conn): string {
    return firstCol($conn, 'customers', ['phone', 'mobile', 'contact_no', 'phone_number'], 'phone');
}

/* ============ DATE FILTER ============ */
$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';

$dateCol = saleDateCol($conn);
$totalCol = saleTotalCol($conn);

$where = "WHERE client_id = ?";
$params = [$client_id];
$types  = "i";

if ($from_date && $to_date) {
    $where .= " AND DATE(`$dateCol`) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $types .= "ss";
}

/* ============ SUMMARY ============ */
$sqlSummary = "
    SELECT 
        COUNT(id) AS total_orders,
        COALESCE(SUM(`$totalCol`), 0) AS total_sales
    FROM sales
    $where
";

$stmt = $conn->prepare($sqlSummary);
if (!$stmt) die($conn->error);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_orders = (int)($summary['total_orders'] ?? 0);
$total_sales  = (float)($summary['total_sales'] ?? 0);

/* ============ TODAY SALES ============ */
$sqlToday = "
    SELECT COALESCE(SUM(`$totalCol`), 0) AS today_sales
    FROM sales
    WHERE client_id = ? AND DATE(`$dateCol`) = CURDATE()
";
$stmt2 = $conn->prepare($sqlToday);
$stmt2->bind_param("i", $client_id);
$stmt2->execute();
$today_sales = (float)($stmt2->get_result()->fetch_assoc()['today_sales'] ?? 0);
$stmt2->close();

/* ============ TOP SELLING ITEMS ============ */
$top_items = [];
if (tableExists($conn, 'sale_items')) {
    $qtyCol = firstCol($conn, 'sale_items', ['qty', 'quantity', 'quantity_ordered'], 'qty');
    $itemName = tableExists($conn, 'items') ? itemNameCol($conn) : '';
    $itemSku = tableExists($conn, 'items') ? itemSkuCol($conn) : '';

    $joinItems = tableExists($conn, 'items') ? "LEFT JOIN items i ON i.id = si.item_id" : "";
    $itemSelect = tableExists($conn, 'items')
        ? "COALESCE(i.`$itemName`, CONCAT('Item #', si.item_id)) AS item_name, COALESCE(i.`$itemSku`, '') AS sku"
        : "CONCAT('Item #', si.item_id) AS item_name, '' AS sku";

    $sqlItems = "
        SELECT 
            si.item_id,
            $itemSelect,
            COALESCE(SUM(si.`$qtyCol`), 0) AS total_qty
        FROM sale_items si
        INNER JOIN sales s ON s.id = si.sale_id
        $joinItems
        WHERE s.client_id = ?
        GROUP BY si.item_id
        ORDER BY total_qty DESC
        LIMIT 10
    ";

    $stmt3 = $conn->prepare($sqlItems);
    if ($stmt3) {
        $stmt3->bind_param("i", $client_id);
        $stmt3->execute();
        $top_items = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt3->close();
    }
}

/* =====================================================
   DISTRIBUTION REPORTS
===================================================== */
$hasDistribution = tableExists($conn, 'delivery_order_assignments');
$hasDispatch = tableExists($conn, 'distribution_dispatches') && tableExists($conn, 'distribution_dispatch_items');
$hasRoutes = tableExists($conn, 'distribution_routes');
$hasDrivers = tableExists($conn, 'delivery_drivers');
$hasSettlement = tableExists($conn, 'distribution_collection_settlements');

$distWhere = "a.client_id = ?";
$distParams = [$client_id];
$distTypes = "i";
if ($from_date && $to_date) {
    $distWhere .= " AND DATE(a.delivery_date) BETWEEN ? AND ?";
    $distParams[] = $from_date;
    $distParams[] = $to_date;
    $distTypes .= "ss";
}

$dist_total_assigned = 0;
$dist_out_for_delivery = 0;
$dist_delivered = 0;
$dist_failed = 0;
$dist_collection = 0;
$dist_unsettled = 0;
$dist_settled = 0;

if ($hasDistribution) {
    $row = fetchRows($conn, "
        SELECT
            COUNT(*) AS total_assigned,
            SUM(a.status='out_for_delivery') AS out_for_delivery,
            SUM(a.status='delivered') AS delivered,
            SUM(a.status IN ('failed','returned','cancelled')) AS failed,
            COALESCE(SUM(a.collection_amount),0) AS collection_amount,
            SUM(a.status='delivered' AND COALESCE(a.settlement_status,'unsettled') != 'settled') AS unsettled_orders,
            SUM(COALESCE(a.settlement_status,'unsettled') = 'settled') AS settled_orders
        FROM delivery_order_assignments a
        WHERE $distWhere
    ", $distTypes, $distParams);

    $r = $row[0] ?? [];
    $dist_total_assigned = (int)($r['total_assigned'] ?? 0);
    $dist_out_for_delivery = (int)($r['out_for_delivery'] ?? 0);
    $dist_delivered = (int)($r['delivered'] ?? 0);
    $dist_failed = (int)($r['failed'] ?? 0);
    $dist_collection = (float)($r['collection_amount'] ?? 0);
    $dist_unsettled = (int)($r['unsettled_orders'] ?? 0);
    $dist_settled = (int)($r['settled_orders'] ?? 0);
}

/* Daily Dispatch Report */
$daily_dispatch = [];
if ($hasDispatch) {
    $whereD = "dd.client_id = ?";
    $typesD = "i";
    $paramsD = [$client_id];
    if ($from_date && $to_date) {
        $whereD .= " AND DATE(dd.dispatch_date) BETWEEN ? AND ?";
        $typesD .= "ss";
        $paramsD[] = $from_date;
        $paramsD[] = $to_date;
    }

    $daily_dispatch = fetchRows($conn, "
        SELECT
            dd.dispatch_date,
            COUNT(dd.id) AS dispatch_count,
            COALESCE(SUM(dd.total_orders),0) AS total_orders,
            COALESCE(SUM(dd.total_amount),0) AS total_amount,
            COALESCE(SUM(dd.total_collection),0) AS total_collection,
            SUM(dd.status='ready') AS ready_count,
            SUM(dd.status='dispatched') AS dispatched_count,
            SUM(dd.status='completed') AS completed_count,
            SUM(dd.status='cancelled') AS cancelled_count
        FROM distribution_dispatches dd
        WHERE $whereD
        GROUP BY dd.dispatch_date
        ORDER BY dd.dispatch_date DESC
        LIMIT 15
    ", $typesD, $paramsD);
}

/* Driver Performance Report */
$driver_performance = [];
if ($hasDistribution && $hasDrivers) {
    $driver_performance = fetchRows($conn, "
        SELECT
            d.driver_name,
            d.phone,
            d.vehicle,
            COUNT(a.id) AS total_orders,
            SUM(a.status='delivered') AS delivered_orders,
            SUM(a.status='out_for_delivery') AS out_for_delivery,
            SUM(a.status IN ('failed','returned','cancelled')) AS failed_orders,
            COALESCE(SUM(a.collection_amount),0) AS collection_amount
        FROM delivery_drivers d
        LEFT JOIN delivery_order_assignments a ON a.driver_id = d.id AND a.client_id = d.client_id
        WHERE d.client_id = ?
        " . (($from_date && $to_date) ? " AND (a.id IS NULL OR DATE(a.delivery_date) BETWEEN ? AND ?)" : "") . "
        GROUP BY d.id
        ORDER BY delivered_orders DESC, total_orders DESC
        LIMIT 10
    ", ($from_date && $to_date) ? 'iss' : 'i', ($from_date && $to_date) ? [$client_id, $from_date, $to_date] : [$client_id]);
}

/* Route Wise Sales Report */
$route_report = [];
if ($hasDistribution && $hasRoutes) {
    $route_report = fetchRows($conn, "
        SELECT
            r.route_name,
            r.area,
            COUNT(a.id) AS total_orders,
            SUM(a.status='delivered') AS delivered_orders,
            COALESCE(SUM(CASE WHEN a.status='delivered' THEN s.`$totalCol` ELSE 0 END),0) AS delivered_sales,
            COALESCE(SUM(a.collection_amount),0) AS collection_amount
        FROM distribution_routes r
        LEFT JOIN delivery_order_assignments a ON a.route_id = r.id AND a.client_id = r.client_id
        LEFT JOIN sales s ON s.id = a.sale_id AND s.client_id = a.client_id
        WHERE r.client_id = ?
        " . (($from_date && $to_date) ? " AND (a.id IS NULL OR DATE(a.delivery_date) BETWEEN ? AND ?)" : "") . "
        GROUP BY r.id
        ORDER BY delivered_sales DESC, total_orders DESC
        LIMIT 10
    ", ($from_date && $to_date) ? 'iss' : 'i', ($from_date && $to_date) ? [$client_id, $from_date, $to_date] : [$client_id]);
}

/* Pending Delivery Report */
$pending_deliveries = [];
if ($hasDistribution) {
    $invCol = invoiceCol($conn);
    $custName = tableExists($conn, 'customers') ? customerNameCol($conn) : '';
    $custPhone = tableExists($conn, 'customers') ? customerPhoneCol($conn) : '';
    $customerSelect = tableExists($conn, 'customers')
        ? "COALESCE(c.`$custName`, s.customer_name, 'Walk-in Customer') AS customer_name, COALESCE(c.`$custPhone`, '') AS phone"
        : "COALESCE(s.customer_name, 'Walk-in Customer') AS customer_name, '' AS phone";

    $pending_deliveries = fetchRows($conn, "
        SELECT
            a.delivery_date,
            COALESCE(s.`$invCol`, CONCAT('ORD-', s.id)) AS invoice_no,
            $customerSelect,
            COALESCE(r.route_name, '-') AS route_name,
            COALESCE(d.driver_name, '-') AS driver_name,
            COALESCE(s.`$totalCol`,0) AS amount,
            a.status
        FROM delivery_order_assignments a
        INNER JOIN sales s ON s.id = a.sale_id AND s.client_id = a.client_id
        LEFT JOIN customers c ON c.id = s.customer_id
        LEFT JOIN distribution_routes r ON r.id = a.route_id AND r.client_id = a.client_id
        LEFT JOIN delivery_drivers d ON d.id = a.driver_id AND d.client_id = a.client_id
        WHERE a.client_id = ?
          AND a.status IN ('assigned','out_for_delivery')
        ORDER BY a.delivery_date ASC, a.id DESC
        LIMIT 10
    ", 'i', [$client_id]);
}

/* Returned / Failed Orders Report */
$returned_orders = [];
if ($hasDistribution) {
    $invCol = invoiceCol($conn);
    $custName = tableExists($conn, 'customers') ? customerNameCol($conn) : '';
    $custPhone = tableExists($conn, 'customers') ? customerPhoneCol($conn) : '';
    $customerSelect = tableExists($conn, 'customers')
        ? "COALESCE(c.`$custName`, s.customer_name, 'Walk-in Customer') AS customer_name, COALESCE(c.`$custPhone`, '') AS phone"
        : "COALESCE(s.customer_name, 'Walk-in Customer') AS customer_name, '' AS phone";

    $returned_orders = fetchRows($conn, "
        SELECT
            a.delivery_date,
            COALESCE(s.`$invCol`, CONCAT('ORD-', s.id)) AS invoice_no,
            $customerSelect,
            COALESCE(d.driver_name, '-') AS driver_name,
            a.status,
            a.failed_reason,
            COALESCE(s.`$totalCol`,0) AS amount
        FROM delivery_order_assignments a
        INNER JOIN sales s ON s.id = a.sale_id AND s.client_id = a.client_id
        LEFT JOIN customers c ON c.id = s.customer_id
        LEFT JOIN delivery_drivers d ON d.id = a.driver_id AND d.client_id = a.client_id
        WHERE a.client_id = ?
          AND a.status IN ('failed','returned','cancelled')
        ORDER BY a.delivery_date DESC, a.id DESC
        LIMIT 10
    ", 'i', [$client_id]);
}

/* Collection Report */
$collection_report = [];
if ($hasSettlement) {
    $collection_report = fetchRows($conn, "
        SELECT
            cs.settlement_no,
            cs.settlement_date,
            COALESCE(d.driver_name, '-') AS driver_name,
            cs.total_collectable,
            cs.total_received,
            cs.shortage_amount,
            cs.extra_amount,
            cs.settlement_status
        FROM distribution_collection_settlements cs
        LEFT JOIN delivery_drivers d ON d.id = cs.driver_id AND d.client_id = cs.client_id
        WHERE cs.client_id = ?
        " . (($from_date && $to_date) ? " AND DATE(cs.settlement_date) BETWEEN ? AND ?" : "") . "
        ORDER BY cs.id DESC
        LIMIT 10
    ", ($from_date && $to_date) ? 'iss' : 'i', ($from_date && $to_date) ? [$client_id, $from_date, $to_date] : [$client_id]);
}

/* Product Loading Report */
$product_loading = [];
if ($hasDispatch && tableExists($conn, 'sale_items')) {
    $qtyCol = firstCol($conn, 'sale_items', ['qty', 'quantity', 'quantity_ordered'], 'qty');
    $itemName = tableExists($conn, 'items') ? itemNameCol($conn) : '';
    $itemSku = tableExists($conn, 'items') ? itemSkuCol($conn) : '';
    $joinItem = tableExists($conn, 'items') ? "LEFT JOIN items i ON i.id = si.item_id" : "";
    $itemSelect = tableExists($conn, 'items')
        ? "COALESCE(i.`$itemName`, CONCAT('Item #', si.item_id)) AS item_name, COALESCE(i.`$itemSku`, '') AS sku"
        : "CONCAT('Item #', si.item_id) AS item_name, '' AS sku";

    $product_loading = fetchRows($conn, "
        SELECT
            si.item_id,
            $itemSelect,
            COALESCE(SUM(si.`$qtyCol`),0) AS loading_qty,
            COUNT(DISTINCT ddi.dispatch_id) AS dispatch_count
        FROM distribution_dispatch_items ddi
        INNER JOIN sale_items si ON si.sale_id = ddi.sale_id
        INNER JOIN distribution_dispatches dd ON dd.id = ddi.dispatch_id AND dd.client_id = ddi.client_id
        $joinItem
        WHERE ddi.client_id = ?
        " . (($from_date && $to_date) ? " AND DATE(dd.dispatch_date) BETWEEN ? AND ?" : "") . "
        GROUP BY si.item_id
        ORDER BY loading_qty DESC
        LIMIT 10
    ", ($from_date && $to_date) ? 'iss' : 'i', ($from_date && $to_date) ? [$client_id, $from_date, $to_date] : [$client_id]);
}

/* Customer Delivery History */
$customer_history = [];
if ($hasDistribution) {
    $custName = tableExists($conn, 'customers') ? customerNameCol($conn) : '';
    $custPhone = tableExists($conn, 'customers') ? customerPhoneCol($conn) : '';
    $customerSelect = tableExists($conn, 'customers')
        ? "COALESCE(c.`$custName`, s.customer_name, 'Walk-in Customer') AS customer_name, COALESCE(c.`$custPhone`, '') AS phone"
        : "COALESCE(s.customer_name, 'Walk-in Customer') AS customer_name, '' AS phone";

    $customer_history = fetchRows($conn, "
        SELECT
            $customerSelect,
            COUNT(a.id) AS total_deliveries,
            SUM(a.status='delivered') AS delivered_orders,
            SUM(a.status IN ('failed','returned','cancelled')) AS failed_orders,
            COALESCE(SUM(a.collection_amount),0) AS collected_amount
        FROM delivery_order_assignments a
        INNER JOIN sales s ON s.id = a.sale_id AND s.client_id = a.client_id
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE a.client_id = ?
        GROUP BY s.customer_id, customer_name, phone
        ORDER BY total_deliveries DESC
        LIMIT 10
    ", 'i', [$client_id]);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reports / Analytics</title>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
    body {
        margin: 0;
        background: #f4f6ff;
        font-family: "Inter", "Segoe UI", system-ui, sans-serif;
        color: #13233d;
    }

    .container-scroll {
        display: flex;
        min-height: 100vh;
    }

    .sidebar {
        width: 292px;
        flex: 0 0 292px;
        min-height: 100vh;
    }

    .main-panel {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .topbar {
        height: 78px;
        background: #050505;
        position: sticky;
        top: 0;
        z-index: 999;
        display: flex;
        align-items: center;
    }

    .content-wrapper {
        padding: 24px;
        flex-grow: 1;
        overflow-y: auto;
    }

    .page-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .page-title {
        font-size: 32px;
        font-weight: 900;
        letter-spacing: -.5px;
        margin: 0;
        color: #13233d;
    }

    .page-sub {
        color: #687282;
        font-weight: 700;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }

    .summary-card {
        border: 0;
        border-radius: 12px;
        color: #fff;
        min-height: 90px;
        padding: 16px 18px;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 10px 22px rgba(18, 35, 64, .12);
    }

    .summary-card:nth-child(1) {
        background: linear-gradient(135deg, #4095ff, #2f80ed);
    }

    .summary-card:nth-child(2) {
        background: linear-gradient(135deg, #49be88, #2ea769);
    }

    .summary-card:nth-child(3) {
        background: linear-gradient(135deg, #f7c943, #f4b400);
    }

    .summary-card:nth-child(4) {
        background: linear-gradient(135deg, #8b5cf6, #6d28d9);
    }

    .summary-icon {
        font-size: 28px;
    }

    .summary-label {
        font-size: 14px;
        font-weight: 800;
        opacity: .95;
    }

    .summary-value {
        font-size: 28px;
        font-weight: 900;
        line-height: 1;
        margin-left: auto;
    }

    .filter-card,
    .report-card {
        background: #fff;
        border: 1px solid #dfe5ef;
        border-radius: 12px;
        box-shadow: 0 12px 26px rgba(18, 35, 64, .07);
        margin-bottom: 18px;
        overflow: hidden;
    }

    .filter-card {
        padding: 14px;
    }

    .report-head {
        padding: 15px 18px;
        border-bottom: 1px solid #e7ecf4;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .report-title {
        font-size: 17px;
        font-weight: 900;
        color: #13233d;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .report-title i {
        color: #17243d;
    }

    .table-wrap {
        overflow-x: auto;
    }

    .table-modern {
        margin: 0;
        min-width: 860px;
    }

    .table-modern thead th {
        background: #263650;
        color: #fff;
        font-size: 14px;
        font-weight: 900;
        padding: 14px;
        white-space: nowrap;
    }

    .table-modern tbody td {
        padding: 13px 14px;
        border-bottom: 1px solid #e7ecf4;
        vertical-align: middle;
        font-size: 14px;
    }

    .badge-soft {
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 900;
    }

    .badge-green {
        background: #dcfce7;
        color: #15803d;
    }

    .badge-blue {
        background: #eff6ff;
        color: #2563eb;
    }

    .badge-orange {
        background: #fff7ed;
        color: #c2410c;
    }

    .badge-red {
        background: #fee2e2;
        color: #b91c1c;
    }

    .empty-state {
        padding: 24px;
        text-align: center;
        color: #687282;
        font-weight: 700;
    }

    .section-title {
        font-size: 22px;
        font-weight: 900;
        margin: 25px 0 14px;
        color: #13233d;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .btn-darkx {
        height: 42px;
        border: 0;
        border-radius: 10px;
        background: #17243d;
        color: #fff;
        font-weight: 900;
        padding: 0 18px;
    }

    .btn-lightx {
        height: 42px;
        border: 0;
        border-radius: 10px;
        background: #eef2f7;
        color: #4b5563;
        font-weight: 900;
        padding: 0 18px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .form-control {
        height: 42px;
        border-radius: 10px;
        border: 1px solid #dfe5ef;
    }

    @media(max-width:1200px) {
        .summary-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .sidebar {
            width: 250px;
            flex-basis: 250px;
        }
    }

    @media(max-width:768px) {
        .sidebar {
            display: none;
        }

        .content-wrapper {
            padding: 16px;
        }

        .summary-grid {
            grid-template-columns: 1fr;
        }

        .page-title {
            font-size: 26px;
        }
    }
    
    
    /* =====================================================
   REPORTS.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
   Paste before
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-border:rgba(148,163,184,.14);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT / SIDEBAR ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    background:transparent !important;
    overflow:visible !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

#dashboardSidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
}

#dashboardSidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

#dashboardSidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.main-panel{
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow,
body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon{
    margin:0 !important;
}

/* ================= TOPBAR / CONTENT BG ================= */
.topbar{
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid var(--oms-border) !important;
    display:flex !important;
    align-items:center !important;
    padding:0 !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
}

.content-wrapper{
    position:relative !important;
    padding:24px 26px !important;
    flex:1 !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
}

.content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}

.content-wrapper::after{
    content:"";
    position:absolute;
    width:520px;
    height:520px;
    right:-190px;
    top:-210px;
    pointer-events:none;
    z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);
    opacity:.85;
}

.content-wrapper > *{
    position:relative;
    z-index:1;
}

/* ================= PAGE HEADER ================= */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-sub,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.section-title{
    color:#f8fafc !important;
    font-size:22px !important;
    font-weight:900 !important;
    margin:25px 0 14px !important;
}

.section-title i{
    color:#a78bfa !important;
}

/* ================= SUMMARY CARDS ================= */
.summary-grid{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.summary-card{
    min-height:138px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate;
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    transition:.25s ease !important;
}

.summary-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.summary-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
}

.summary-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.summary-card:nth-child(1){background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.summary-card:nth-child(2){background:linear-gradient(135deg,#047857,#16a34a) !important;}
.summary-card:nth-child(3){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.summary-card:nth-child(4){background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}

.summary-icon{
    font-size:30px !important;
    position:relative;
    z-index:2;
}

.summary-label{
    font-size:15px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
    position:relative;
    z-index:2;
}

.summary-value{
    font-size:28px !important;
    font-weight:900 !important;
    line-height:1 !important;
    color:#fff !important;
    margin-left:auto !important;
    position:relative;
    z-index:2;
}

/* ================= GLASS FILTER + REPORT CARDS ================= */
.filter-card,
.report-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    color:#e5e7eb !important;
    overflow:hidden !important;
}

.filter-card:hover,
.report-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-card{
    padding:14px !important;
    margin-bottom:18px !important;
}

.report-card{
    margin-bottom:18px !important;
}

.report-head{
    padding:16px 18px !important;
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

.report-title{
    color:#f8fafc !important;
    font-weight:900 !important;
    font-size:17px !important;
}

.report-title i{
    color:#a78bfa !important;
}

/* ================= INPUTS / BUTTONS ================= */
.form-label{
    color:#f8fafc !important;
    font-weight:900 !important;
    font-size:13px !important;
}

.form-control{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.form-control:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.form-control::placeholder{
    color:#94a3b8 !important;
}

.btn-darkx{
    min-height:46px !important;
    height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.btn-lightx{
    min-height:46px !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
    text-decoration:none !important;
}

.btn-darkx:hover,
.btn-lightx:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
    color:#fff !important;
}

/* ================= TABLES ================= */
.table-wrap,
.table-modern,
.table-modern tbody,
.table-modern tbody tr{
    background:transparent !important;
}

.table-modern{
    width:100% !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    margin:0 !important;
}

.table-modern thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:14px 12px !important;
    white-space:nowrap;
}

.table-modern tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-top:none !important;
    padding:14px 12px !important;
    vertical-align:middle;
    font-size:14px !important;
    font-weight:600;
    white-space:nowrap;
}

.table-modern tbody tr:hover,
.table-modern tbody tr:hover td,
.table-modern tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

.table-modern tbody tr:focus,
.table-modern tbody tr:active,
.table-modern tbody td:focus,
.table-modern tbody td:active,
.table-modern tbody td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

.table-modern tbody td.text-center,
.table-modern tbody td.py-4,
.table-modern tbody td[colspan],
.empty-state{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

.table-modern b{
    color:#f8fafc !important;
}

.table-modern ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

/* ================= BADGES ================= */
.badge-soft{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.badge-green{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.badge-blue{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border:1px solid rgba(59,130,246,.24) !important;
}

.badge-orange{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.badge-red{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* ================= SCROLLBAR ================= */
.table-wrap::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-wrap::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb:hover,
.content-wrapper::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

@media(max-width:1200px){
    .summary-grid{
        grid-template-columns:repeat(2,1fr) !important;
    }
}

@media(max-width:991px){
    .container-scroll{
        flex-direction:column !important;
    }

    .sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        position:relative !important;
        height:auto !important;
        display:flex !important;
    }

    #dashboardSidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    #dashboardSidebar .sidebar-inner{
        height:auto !important;
        min-height:auto !important;
    }

    .main-panel{
        margin-left:0 !important;
    }

    .topbar{
        position:relative !important;
    }

    .content-wrapper{
        padding:18px !important;
    }
}

@media(max-width:768px){
    .summary-grid{
        grid-template-columns:1fr !important;
    }

    .page-head{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .filter-card .row{
        gap:10px !important;
    }

    .filter-card .col-md-4,
    .btn-darkx,
    .btn-lightx{
        width:100% !important;
    }

    .btn-darkx,
    .btn-lightx{
        justify-content:center !important;
    }

    .summary-value{
        font-size:24px !important;
    }
}
    
    </style>
</head>

<body>

    <div class="container-scroll">
        <div class="sidebar"><?php include('partials/sidebar.php'); ?></div>

        <div class="main-panel">
            <div class="topbar"><?php include('partials/topbar.php'); ?></div>

            <div class="content-wrapper">
                <div class="page-head">
                    <div>
                        <h4 class="page-title"><i class="bi bi-graph-up-arrow me-2"></i>Reports / Analytics</h4>
                        <div class="page-sub">POS sales reports + distribution dispatch, delivery, collection and route
                            analytics</div>
                    </div>
                </div>

                <form method="get" class="filter-card">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?= e($from_date) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?= e($to_date) ?>">
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button class="btn-darkx" type="submit"><i class="bi bi-funnel me-1"></i>Generate</button>
                            <a class="btn-lightx" href="<?= e(basename(__FILE__)) ?>">Reset</a>
                        </div>
                    </div>
                </form>

                <div class="summary-grid">
                    <div class="summary-card"><i class="bi bi-cash-stack summary-icon"></i>
                        <div class="summary-label">Total Sales</div>
                        <div class="summary-value">Rs <?= number_format($total_sales, 0) ?></div>
                    </div>
                    <div class="summary-card"><i class="bi bi-receipt summary-icon"></i>
                        <div class="summary-label">Total Orders</div>
                        <div class="summary-value"><?= number_format($total_orders) ?></div>
                    </div>
                    <div class="summary-card"><i class="bi bi-calendar2-check summary-icon"></i>
                        <div class="summary-label">Today Sales</div>
                        <div class="summary-value">Rs <?= number_format($today_sales, 0) ?></div>
                    </div>
                    <div class="summary-card"><i class="bi bi-truck-front summary-icon"></i>
                        <div class="summary-label">Distribution Collection</div>
                        <div class="summary-value">Rs <?= number_format($dist_collection, 0) ?></div>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-box-seam"></i>Top Selling Items</div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Item ID</th>
                                    <th>Item</th>
                                    <th>SKU</th>
                                    <th>Qty Sold</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($top_items)): foreach ($top_items as $i): ?>
                                <tr>
                                    <td><?= (int)$i['item_id'] ?></td>
                                    <td><b><?= e($i['item_name'] ?? ('Item #' . $i['item_id'])) ?></b></td>
                                    <td><?= e($i['sku'] ?? '-') ?></td>
                                    <td><?= number_format((float)$i['total_qty']) ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="4" class="empty-state">No data found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section-title"><i class="bi bi-truck"></i>Distribution Reports</div>

                <?php if (!$hasDistribution): ?>
                <div class="report-card">
                    <div class="empty-state">Distribution tables abhi available nahi hain. Pehle Distribution DB schema
                        import karein.</div>
                </div>
                <?php else: ?>
                <div class="summary-grid">
                    <div class="summary-card"><i class="bi bi-list-check summary-icon"></i>
                        <div class="summary-label">Assigned Orders</div>
                        <div class="summary-value"><?= number_format($dist_total_assigned) ?></div>
                    </div>
                    <div class="summary-card"><i class="bi bi-truck summary-icon"></i>
                        <div class="summary-label">Out For Delivery</div>
                        <div class="summary-value"><?= number_format($dist_out_for_delivery) ?></div>
                    </div>
                    <div class="summary-card"><i class="bi bi-check-circle summary-icon"></i>
                        <div class="summary-label">Delivered</div>
                        <div class="summary-value"><?= number_format($dist_delivered) ?></div>
                    </div>
                    <div class="summary-card"><i class="bi bi-wallet2 summary-icon"></i>
                        <div class="summary-label">Unsettled</div>
                        <div class="summary-value"><?= number_format($dist_unsettled) ?></div>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-calendar-week"></i>Daily Dispatch Report</div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Dispatches</th>
                                    <th>Orders</th>
                                    <th>Amount</th>
                                    <th>Collected</th>
                                    <th>Ready</th>
                                    <th>Dispatched</th>
                                    <th>Completed</th>
                                    <th>Cancelled</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($daily_dispatch)): foreach ($daily_dispatch as $r): ?>
                                <tr>
                                    <td><?= e($r['dispatch_date']) ?></td>
                                    <td><?= number_format((int)$r['dispatch_count']) ?></td>
                                    <td><?= number_format((int)$r['total_orders']) ?></td>
                                    <td>Rs <?= number_format((float)$r['total_amount'], 0) ?></td>
                                    <td>Rs <?= number_format((float)$r['total_collection'], 0) ?></td>
                                    <td><?= number_format((int)$r['ready_count']) ?></td>
                                    <td><?= number_format((int)$r['dispatched_count']) ?></td>
                                    <td><?= number_format((int)$r['completed_count']) ?></td>
                                    <td><?= number_format((int)$r['cancelled_count']) ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="9" class="empty-state">No dispatch data found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-person-badge"></i>Driver Performance Report</div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Driver</th>
                                    <th>Phone</th>
                                    <th>Vehicle</th>
                                    <th>Total Orders</th>
                                    <th>Delivered</th>
                                    <th>Out For Delivery</th>
                                    <th>Failed/Returned</th>
                                    <th>Collection</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($driver_performance)): foreach ($driver_performance as $r): ?>
                                <tr>
                                    <td><b><?= e($r['driver_name'] ?? '-') ?></b></td>
                                    <td><?= e($r['phone'] ?? '-') ?></td>
                                    <td><?= e($r['vehicle'] ?? '-') ?></td>
                                    <td><?= number_format((int)$r['total_orders']) ?></td>
                                    <td><span
                                            class="badge-soft badge-green"><?= number_format((int)$r['delivered_orders']) ?></span>
                                    </td>
                                    <td><span
                                            class="badge-soft badge-blue"><?= number_format((int)$r['out_for_delivery']) ?></span>
                                    </td>
                                    <td><span
                                            class="badge-soft badge-red"><?= number_format((int)$r['failed_orders']) ?></span>
                                    </td>
                                    <td>Rs <?= number_format((float)$r['collection_amount'], 0) ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">No driver performance found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-signpost-split"></i>Route Wise Sales Report</div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Route</th>
                                    <th>Area</th>
                                    <th>Total Orders</th>
                                    <th>Delivered</th>
                                    <th>Delivered Sales</th>
                                    <th>Collection</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($route_report)): foreach ($route_report as $r): ?>
                                <tr>
                                    <td><b><?= e($r['route_name'] ?? '-') ?></b></td>
                                    <td><?= e($r['area'] ?? '-') ?></td>
                                    <td><?= number_format((int)$r['total_orders']) ?></td>
                                    <td><?= number_format((int)$r['delivered_orders']) ?></td>
                                    <td>Rs <?= number_format((float)$r['delivered_sales'], 0) ?></td>
                                    <td>Rs <?= number_format((float)$r['collection_amount'], 0) ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">No route sales found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-clock-history"></i>Pending Delivery Report</div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Route</th>
                                    <th>Driver</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pending_deliveries)): foreach ($pending_deliveries as $r): ?>
                                <tr>
                                    <td><?= e($r['delivery_date'] ?? '-') ?></td>
                                    <td><b><?= e($r['invoice_no'] ?? '-') ?></b></td>
                                    <td><?= e($r['customer_name'] ?? '-') ?></td>
                                    <td><?= e($r['phone'] ?? '-') ?></td>
                                    <td><?= e($r['route_name'] ?? '-') ?></td>
                                    <td><?= e($r['driver_name'] ?? '-') ?></td>
                                    <td>Rs <?= number_format((float)$r['amount'], 0) ?></td>
                                    <td><span class="badge-soft badge-orange"><?= e($r['status'] ?? '-') ?></span></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">No pending deliveries found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-arrow-return-left"></i>Returned / Failed Orders Report
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Driver</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($returned_orders)): foreach ($returned_orders as $r): ?>
                                <tr>
                                    <td><?= e($r['delivery_date'] ?? '-') ?></td>
                                    <td><b><?= e($r['invoice_no'] ?? '-') ?></b></td>
                                    <td><?= e($r['customer_name'] ?? '-') ?></td>
                                    <td><?= e($r['phone'] ?? '-') ?></td>
                                    <td><?= e($r['driver_name'] ?? '-') ?></td>
                                    <td><span class="badge-soft badge-red"><?= e($r['status'] ?? '-') ?></span></td>
                                    <td><?= e($r['failed_reason'] ?? '-') ?></td>
                                    <td>Rs <?= number_format((float)$r['amount'], 0) ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">No returned/failed orders found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-cash-coin"></i>Collection / Settlement Report</div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Settlement No</th>
                                    <th>Date</th>
                                    <th>Driver</th>
                                    <th>Collectable</th>
                                    <th>Received</th>
                                    <th>Shortage</th>
                                    <th>Extra</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($collection_report)): foreach ($collection_report as $r): ?>
                                <tr>
                                    <td><b><?= e($r['settlement_no'] ?? '-') ?></b></td>
                                    <td><?= e($r['settlement_date'] ?? '-') ?></td>
                                    <td><?= e($r['driver_name'] ?? '-') ?></td>
                                    <td>Rs <?= number_format((float)$r['total_collectable'], 0) ?></td>
                                    <td>Rs <?= number_format((float)$r['total_received'], 0) ?></td>
                                    <td>Rs <?= number_format((float)$r['shortage_amount'], 0) ?></td>
                                    <td>Rs <?= number_format((float)$r['extra_amount'], 0) ?></td>
                                    <td><span
                                            class="badge-soft badge-green"><?= e($r['settlement_status'] ?? '-') ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">No settlement data found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-boxes"></i>Product Loading Report</div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Item ID</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Loading Qty</th>
                                    <th>Dispatch Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($product_loading)): foreach ($product_loading as $r): ?>
                                <tr>
                                    <td><?= (int)$r['item_id'] ?></td>
                                    <td><b><?= e($r['item_name'] ?? '-') ?></b></td>
                                    <td><?= e($r['sku'] ?? '-') ?></td>
                                    <td><?= number_format((float)$r['loading_qty']) ?></td>
                                    <td><?= number_format((int)$r['dispatch_count']) ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="5" class="empty-state">No loading data found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-head">
                        <div class="report-title"><i class="bi bi-person-lines-fill"></i>Customer Wise Delivery History
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Total Deliveries</th>
                                    <th>Delivered</th>
                                    <th>Failed/Returned</th>
                                    <th>Collected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($customer_history)): foreach ($customer_history as $r): ?>
                                <tr>
                                    <td><b><?= e($r['customer_name'] ?? '-') ?></b></td>
                                    <td><?= e($r['phone'] ?? '-') ?></td>
                                    <td><?= number_format((int)$r['total_deliveries']) ?></td>
                                    <td><span
                                            class="badge-soft badge-green"><?= number_format((int)$r['delivered_orders']) ?></span>
                                    </td>
                                    <td><span
                                            class="badge-soft badge-red"><?= number_format((int)$r['failed_orders']) ?></span>
                                    </td>
                                    <td>Rs <?= number_format((float)$r['collected_amount'], 0) ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">No customer delivery history found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>