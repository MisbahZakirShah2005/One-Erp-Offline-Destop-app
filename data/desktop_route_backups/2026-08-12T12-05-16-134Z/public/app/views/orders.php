<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = $_SESSION['client_id'];
$branch_id = $_SESSION['branch_id'] ?? 0;
$user_id = $_SESSION['user_id'];

/* ================= FEATURE / TABLE HELPERS FOR BOOKING ORDERS ================= */
function orders_table_has_column($conn, $table, $column) {
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row['total']);
}

function orders_table_exists($conn, $table) {
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row['total']);
}

function orders_scalar($conn, $sql, $types = '', $params = [], $default = 0) {
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


function orders_whatsapp_phone($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);

    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }

    if (strpos($digits, '0') === 0) {
        $digits = '92' . substr($digits, 1);
    } elseif (strlen($digits) === 10 && strpos($digits, '3') === 0) {
        $digits = '92' . $digits;
    }

    return $digits;
}

function orders_rows($conn, $sql, $types = '', $params = []) {
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

function orders_feature_enabled_column($conn, $table) {
    foreach (['enabled', 'is_enabled', 'status', 'can_view', 'view', 'allowed'] as $col) {
        if (orders_table_has_column($conn, $table, $col)) {
            return $col;
        }
    }

    return null;
}

function orders_client_has_any_feature($conn, int $client_id, array $featureKeys): bool {
    $featureKeys = array_values(array_unique(array_filter(array_map('trim', $featureKeys))));
    if (!$featureKeys) return false;

    if (function_exists('can_show')) {
        foreach ($featureKeys as $featureKey) {
            try {
                if (can_show($featureKey)) return true;
            } catch (Throwable $e) {}
        }
    }

    $placeholders = implode(',', array_fill(0, count($featureKeys), '?'));
    $featureTypes = str_repeat('s', count($featureKeys));

    if (
        orders_table_exists($conn, 'client_feature_overrides') &&
        orders_table_has_column($conn, 'client_feature_overrides', 'client_id') &&
        orders_table_has_column($conn, 'client_feature_overrides', 'feature_key')
    ) {
        $enabledCol = orders_feature_enabled_column($conn, 'client_feature_overrides');
        $enabledSql = '';

        if ($enabledCol) {
            if ($enabledCol === 'status') {
                $enabledSql = " AND `$enabledCol` IN ('1','active','enabled','yes')";
            } else {
                $enabledSql = " AND `$enabledCol` IN (1,'1','active','enabled','yes')";
            }
        }

        $sql = "SELECT COUNT(*) FROM client_feature_overrides WHERE client_id = ? AND feature_key IN ($placeholders) $enabledSql";
        $params = array_merge([$client_id], $featureKeys);

        if ((int)orders_scalar($conn, $sql, 'i' . $featureTypes, $params, 0) > 0) {
            return true;
        }
    }

    if (
        orders_table_exists($conn, 'subscriptions') &&
        orders_table_exists($conn, 'plan_features') &&
        orders_table_has_column($conn, 'subscriptions', 'client_id') &&
        orders_table_has_column($conn, 'subscriptions', 'plan_id') &&
        orders_table_has_column($conn, 'plan_features', 'plan_id') &&
        orders_table_has_column($conn, 'plan_features', 'feature_key')
    ) {
        $subStatusSql = orders_table_has_column($conn, 'subscriptions', 'status')
            ? " AND s.status IN ('active','trial','1','enabled')"
            : '';

        $pfEnabledCol = orders_feature_enabled_column($conn, 'plan_features');
        $pfEnabledSql = '';

        if ($pfEnabledCol) {
            if ($pfEnabledCol === 'status') {
                $pfEnabledSql = " AND pf.`$pfEnabledCol` IN ('1','active','enabled','yes')";
            } else {
                $pfEnabledSql = " AND pf.`$pfEnabledCol` IN (1,'1','active','enabled','yes')";
            }
        }

        $sql = "
            SELECT COUNT(*)
            FROM subscriptions s
            INNER JOIN plan_features pf ON pf.plan_id = s.plan_id
            WHERE s.client_id = ?
              AND pf.feature_key IN ($placeholders)
              $subStatusSql
              $pfEnabledSql
        ";

        $params = array_merge([$client_id], $featureKeys);

        if ((int)orders_scalar($conn, $sql, 'i' . $featureTypes, $params, 0) > 0) {
            return true;
        }
    }

    return false;
}

function orders_first_existing_column($conn, $table, array $columns) {
    foreach ($columns as $column) {
        if (orders_table_has_column($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

/* ================= BOOKING / APPOINTMENT MODE DETECT ================= */
$bookingFeatureKeys = [
    'appointments',
    'appointment',
    'bookings',
    'booking',
    'reservations',
    'reservation',
    'booking_calendar',
    'appointment_calendar'
];

$show_booking_orders = orders_client_has_any_feature($conn, (int)$client_id, $bookingFeatureKeys);

$booking_table = null;

foreach (['appointments', 'bookings', 'reservations'] as $possibleTable) {
    if (orders_table_exists($conn, $possibleTable) && orders_table_has_column($conn, $possibleTable, 'client_id')) {
        $booking_table = $possibleTable;
        break;
    }
}

$booking_date_col = $booking_table ? orders_first_existing_column($conn, $booking_table, [
    'appointment_date',
    'booking_date',
    'reservation_date',
    'event_date',
    'date',
    'start_date',
    'created_at'
]) : null;

$booking_time_col = $booking_table ? orders_first_existing_column($conn, $booking_table, [
    'appointment_time',
    'booking_time',
    'reservation_time',
    'event_time',
    'slot_time',
    'time',
    'start_time'
]) : null;

$booking_status_col = $booking_table ? orders_first_existing_column($conn, $booking_table, [
    'status',
    'booking_status',
    'appointment_status'
]) : null;

$booking_customer_col = $booking_table ? orders_first_existing_column($conn, $booking_table, [
    'customer_name',
    'name',
    'client_name'
]) : null;

$booking_title_col = $booking_table ? orders_first_existing_column($conn, $booking_table, [
    'title',
    'event_name',
    'service_name',
    'purpose',
    'description'
]) : null;

/* ================= DELETE SELECTED ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {

    if ($show_booking_orders && $booking_table) {
        $selected_bookings = $_POST['selected_orders'] ?? [];

        if (!empty($selected_bookings) && is_array($selected_bookings)) {
            try {
                $conn->begin_transaction();

                foreach ($selected_bookings as $booking_id) {
                    $booking_id = (int)$booking_id;

                    $stmt = $conn->prepare("DELETE FROM `$booking_table` WHERE id = ? AND client_id = ?");
                    $stmt->bind_param("ii", $booking_id, $client_id);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();
                $_SESSION['success_message'] = "Selected bookings deleted successfully.";
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Failed to delete selected bookings.";
            }
        } else {
            $_SESSION['error_message'] = "Please select at least one booking.";
        }

        header("Location: orders.php");
        exit;
    }

    $selected_orders = $_POST['selected_orders'] ?? [];

    if (!empty($selected_orders) && is_array($selected_orders)) {
        $conn->begin_transaction();
        try {
            foreach ($selected_orders as $sale_id) {
                $sale_id = (int)$sale_id;

                $stmt1 = $conn->prepare("DELETE FROM sale_items WHERE sale_id=?");
                $stmt1->bind_param("i", $sale_id);
                $stmt1->execute();

                $stmt2 = $conn->prepare("DELETE FROM sales WHERE id=? AND client_id=?");
                $stmt2->bind_param("ii", $sale_id, $client_id);
                $stmt2->execute();
            }

            $conn->commit();
            $_SESSION['success_message'] = "Selected orders deleted successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Failed to delete selected orders.";
        }
    } else {
        $_SESSION['error_message'] = "Please select at least one order.";
    }

    header("Location: orders.php");
    exit;
}
/* ================= FETCH ORDERS ================= */
function orders_line_total_amount(array $item): float {
    if (isset($item['total']) && $item['total'] !== null && $item['total'] !== '') {
        return round((float)$item['total'], 2);
    }

    if (isset($item['line_total']) && $item['line_total'] !== null && $item['line_total'] !== '') {
        return round((float)$item['line_total'], 2);
    }

    $qty = (float)($item['qty'] ?? $item['quantity'] ?? 0);
    $price = (float)($item['price'] ?? $item['unit_price'] ?? 0);

    return round($qty * $price, 2);
}

function orders_item_max_discount_percent(array $item) {
    /*
    |--------------------------------------------------------------------------
    | Maximum Discount fallback support
    |--------------------------------------------------------------------------
    | New clean columns: has_max_discount / max_discount_percent
    | Old compatibility columns: has_discount / discount_value
    */
    $hasMaxDiscount = (int)($item['item_has_max_discount'] ?? 0) === 1;
    $maxDiscountPercent = (float)($item['item_max_discount_percent'] ?? 0);

    if ($hasMaxDiscount && $maxDiscountPercent > 0) {
        return min(100, max(0, $maxDiscountPercent));
    }

    $legacyHasDiscount = (int)($item['item_has_discount'] ?? 0) === 1;
    $legacyDiscountValue = (float)($item['item_discount_value'] ?? 0);

    if ($legacyHasDiscount && $legacyDiscountValue > 0) {
        return min(100, max(0, $legacyDiscountValue));
    }

    return null;
}

function orders_calculate_capped_discount_summary(array $items, float $discountPercent, float $taxPercent): array {
    $discountPercent = min(100, max(0, $discountPercent));
    $taxPercent = max(0, $taxPercent);

    $subtotal = 0.00;
    $discountAmount = 0.00;

    foreach ($items as $item) {
        $lineTotal = orders_line_total_amount($item);
        $subtotal += $lineTotal;

        $itemMaxDiscount = orders_item_max_discount_percent($item);
        $appliedDiscountPercent = $itemMaxDiscount !== null
            ? min($discountPercent, $itemMaxDiscount)
            : $discountPercent;

        $discountAmount += ($lineTotal * $appliedDiscountPercent) / 100;
    }

    $subtotal = round($subtotal, 2);
    $discountAmount = round(min($subtotal, max(0, $discountAmount)), 2);
    $taxableAmount = max(0, $subtotal - $discountAmount);
    $taxAmount = round(($taxableAmount * $taxPercent) / 100, 2);
    $grandTotal = round($taxableAmount + $taxAmount, 2);

    return [
        'subtotal' => $subtotal,
        'discount_amount' => $discountAmount,
        'tax_amount' => $taxAmount,
        'grand_total' => $grandTotal,
    ];
}

function getOrders($conn, $client_id){
    $itemsHasMaxDiscount = orders_table_has_column($conn, 'items', 'has_max_discount');
    $itemsHasMaxDiscountPercent = orders_table_has_column($conn, 'items', 'max_discount_percent');
    $itemsHasLegacyDiscount = orders_table_has_column($conn, 'items', 'has_discount');
    $itemsHasLegacyDiscountValue = orders_table_has_column($conn, 'items', 'discount_value');

    $itemExtraCols = '';
    $itemExtraCols .= $itemsHasMaxDiscount ? ", i.has_max_discount AS item_has_max_discount" : ", 0 AS item_has_max_discount";
    $itemExtraCols .= $itemsHasMaxDiscountPercent ? ", i.max_discount_percent AS item_max_discount_percent" : ", 0 AS item_max_discount_percent";
    $itemExtraCols .= $itemsHasLegacyDiscount ? ", i.has_discount AS item_has_discount" : ", 0 AS item_has_discount";
    $itemExtraCols .= $itemsHasLegacyDiscountValue ? ", i.discount_value AS item_discount_value" : ", 0 AS item_discount_value";

    $stmt = $conn->prepare("
        SELECT s.*, c.name AS customer_name, c.phone AS customer_phone
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE s.client_id=?
        ORDER BY s.id DESC
    ");
    $stmt->bind_param("i",$client_id);
    $stmt->execute();
    $orders = $stmt->get_result();

    $data = [];
    while($order = $orders->fetch_assoc()){
        $sale_id = $order['id'];

        $stmt_items = $conn->prepare("
            SELECT
                si.*,
                COALESCE(NULLIF(si.item_name, ''), NULLIF(i.name, ''), 'Item') AS display_item_name,
                i.name AS product_table_name,
                i.price AS default_product_price,
                i.wholesaler_price_1 AS product_wholesale_1,
                i.wholesaler_price_2 AS product_wholesale_2
                $itemExtraCols
            FROM sale_items si
            LEFT JOIN items i ON i.id = si.item_id
            WHERE si.sale_id=?
        ");
        $stmt_items->bind_param("i",$sale_id);
        $stmt_items->execute();
        $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_items->close();

        $discount = isset($order['discount']) ? (float)$order['discount'] : (float)($order['discount_percent'] ?? 0);
        $tax = isset($order['tax']) ? (float)$order['tax'] : (float)($order['tax_percent'] ?? 0);

        $summary = orders_calculate_capped_discount_summary($items, $discount, $tax);

        $order['items'] = $items;
        $order['subtotal'] = $summary['subtotal'];
        $order['discount_amount'] = $summary['discount_amount'];
        $order['tax_amount'] = $summary['tax_amount'];
        $order['grand_total'] = $summary['grand_total'];

        $data[] = $order;
    }

    $stmt->close();

    return $data;
}

$all_orders = getOrders($conn, $client_id);

/* ================= FETCH BOOKING / APPOINTMENT ORDERS ================= */
$all_bookings = [];
$total_bookings = 0;
$today_bookings = 0;
$pending_bookings = 0;
$confirmed_bookings = 0;

if ($show_booking_orders && $booking_table && $booking_date_col) {
    $selectDate = "`$booking_date_col` AS booking_date";
    $selectTime = $booking_time_col ? "`$booking_time_col` AS booking_time" : "NULL AS booking_time";
    $selectStatus = $booking_status_col ? "`$booking_status_col` AS booking_status" : "'booked' AS booking_status";
    $selectCustomer = $booking_customer_col ? "`$booking_customer_col` AS customer_name" : "'Customer' AS customer_name";
    $selectTitle = $booking_title_col ? "`$booking_title_col` AS booking_title" : "'Booking / Appointment' AS booking_title";

    $all_bookings = orders_rows(
        $conn,
        "SELECT id, $selectDate, $selectTime, $selectStatus, $selectCustomer, $selectTitle
         FROM `$booking_table`
         WHERE client_id = ?
         ORDER BY DATE(`$booking_date_col`) DESC, id DESC",
        "i",
        [$client_id]
    );

    $total_bookings = count($all_bookings);

    foreach ($all_bookings as $booking) {
        $bookingDate = !empty($booking['booking_date']) ? date('Y-m-d', strtotime($booking['booking_date'])) : '';
        $status = strtolower(trim((string)($booking['booking_status'] ?? 'booked')));

        if ($bookingDate === date('Y-m-d')) {
            $today_bookings++;
        }

        if (in_array($status, ['pending', 'booked'], true)) {
            $pending_bookings++;
        }

        if (in_array($status, ['confirmed', 'approved'], true)) {
            $confirmed_bookings++;
        }
    }
}

$walkin_count = 0;
$wholesale_count = 0;
$retail_count = 0;

foreach($all_orders as $ord){
    $type = strtolower(trim($ord['customer_type'] ?? ''));
    if($type === 'walk-in' || $type === 'walkin'){
        $walkin_count++;
    } elseif($type === 'wholesaler' || $type === 'wholesale'){
        $wholesale_count++;
    } elseif($type === 'retailer' || $type === 'retail'){
        $retail_count++;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Orders Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>
/*
|--------------------------------------------------------------------------
| ORDERS PAGE THEME INITIALIZER
|--------------------------------------------------------------------------
| Dashboard, POS aur Orders same saved theme use karenge.
|--------------------------------------------------------------------------
*/
(function () {
    const storageKey = 'oneerp_pos_theme';
    let selectedTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

        if (savedTheme === 'light' || savedTheme === 'dark') {
            selectedTheme = savedTheme;
        }
    } catch (error) {
        selectedTheme = 'light';
    }

    document.documentElement.setAttribute(
        'data-pos-theme',
        selectedTheme
    );
})();
</script>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<?php include("partials/head.php");?>

<style>
html, body{
    min-height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-panel-2:#111827;
    --oms-card:#101827;
    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-muted-2:#64748b;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-purple-soft:rgba(109,76,255,.16);

    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-orange:#f97316;
    --oms-yellow:#f59e0b;
    --oms-cyan:#06b6d4;
    --oms-red:#ef4444;
}

body{
    margin:0;
    font-family:"Inter","Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    color:var(--oms-text);
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%);
    overflow-x:hidden;
}

/* ================= LAYOUT SAME DASHBOARD ================= */
body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed,
body.sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

.container-scroll{
    display:flex;
    min-height:100vh;
    align-items:stretch;
}

.sidebar{
    width:var(--current-sidebar-width);
    min-width:var(--current-sidebar-width);
    max-width:var(--current-sidebar-width);
    flex-shrink:0;
    display:flex;
    align-self:stretch;
    background:transparent;
    overflow:visible;
    position:fixed;
    left:0;
    top:0;
    height:100vh;
    z-index:1000;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
}

.sidebar > div{
    width:100%;
    min-height:100vh;
    height:100vh;
}

#dashboardSidebar .sidebar-wrap,
#sidebar .sidebar-wrap{
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
}

#dashboardSidebar .sidebar-inner,
#sidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

#dashboardSidebar .sidebar-menu,
#sidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.main-panel{
    margin-left:var(--current-sidebar-width);
    flex:1;
    min-width:0;
    min-height:100vh;
    display:flex;
    flex-direction:column;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%);
    transition:margin-left .25s ease;
}

.topbar{
    height:70px;
    background:#070b14;
    border-bottom:1px solid var(--oms-border);
    display:flex;
    align-items:center;
    padding:0;
    position:sticky;
    top:0;
    z-index:1030;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}

.content-wrapper{
    position:relative;
    padding:24px 26px;
    flex:1;
    min-height:calc(100vh - 70px);
    overflow:visible;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%);
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

/* ================= SIDEBAR COLLAPSE SUPPORT ================= */
body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #sidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.dashboard-sidebar-collapsed #sidebar .search-box{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #sidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow,
body.dashboard-sidebar-collapsed #sidebar .menu-arrow{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse,
body.dashboard-sidebar-collapsed #sidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link,
body.dashboard-sidebar-collapsed #sidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main,
body.dashboard-sidebar-collapsed #sidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon,
body.dashboard-sidebar-collapsed #sidebar .menu-icon{
    margin:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-inner,
body.dashboard-sidebar-collapsed #sidebar .sidebar-inner{
    padding-left:8px !important;
    padding-right:8px !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .new-customer-btn,
body.dashboard-sidebar-collapsed #sidebar .new-customer-btn{
    width:52px !important;
    height:42px !important;
    margin:0 auto !important;
    padding:0 !important;
    border-radius:12px !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .new-customer-btn span,
body.dashboard-sidebar-collapsed #sidebar .new-customer-btn span{
    display:none !important;
}

/* ================= PAGE HEADER ================= */
.page-title{
    font-size:28px;
    font-weight:900;
    color:#f8fafc;
    margin-bottom:18px;
    letter-spacing:-.03em;
}

/* ================= STAT CARDS ================= */
.stats-row{
    display:grid;
    grid-template-columns: repeat(4, 1fr);
    gap:16px;
    margin-bottom:18px;
}

.stat-card{
    min-height:96px;
    border-radius:18px;
    padding:18px 20px;
    color:#fff;
    position:relative;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.10);
    box-shadow:0 18px 40px rgba(0,0,0,.28);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    font-size:16px;
    font-weight:800;
    cursor:pointer;
    isolation:isolate;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease;
}

.stat-card i{
    font-size:25px;
    opacity:.95;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:70px;
    height:70px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
    z-index:-1;
}

.stat-card:hover,
.stat-card.active{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22);
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18);
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff); }
.card-green{ background:linear-gradient(135deg,#047857,#16a34a); }
.card-cyan{ background:linear-gradient(135deg,#0891b2,#06b6d4); }
.card-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c); }

/* ================= ALERTS ================= */
.alert-custom{
    padding:13px 15px;
    border-radius:14px;
    font-size:14px;
    font-weight:800;
    margin-bottom:16px;
    backdrop-filter:blur(14px);
}

.alert-success-custom{
    background:rgba(34,197,94,.14);
    color:#4ade80;
    border:1px solid rgba(34,197,94,.26);
}

.alert-danger-custom{
    background:rgba(239,68,68,.14);
    color:#f87171;
    border:1px solid rgba(239,68,68,.26);
}

/* ================= FILTER BAR ================= */
.filter-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46));
    border:1px solid rgba(148,163,184,.16);
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045);
    border-radius:18px;
    padding:14px;
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:18px;
}

.search-wrap{
    flex:1.3;
    min-width:230px;
    position:relative;
}

.search-wrap i{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#94a3b8;
}

.search-wrap input,
.filter-control{
    width:100%;
    height:48px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:13px;
    padding:0 14px;
    outline:none;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-weight:700;
    box-shadow:none;
}

.search-wrap input{
    padding-left:42px;
}

.search-wrap input::placeholder,
.filter-control::placeholder{
    color:#94a3b8;
}

.search-wrap input:focus,
.filter-control:focus{
    background:#111827;
    color:#fff;
    border-color:rgba(109,76,255,.55);
    box-shadow:0 0 0 3px rgba(109,76,255,.14);
}

.filter-control{
    min-width:180px;
}

select.filter-control option{
    background:#0f172a;
    color:#e5e7eb;
}

.filter-btn{
    height:48px;
    border:none;
    border-radius:13px;
    padding:0 20px;
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    color:#fff;
    font-weight:900;
    box-shadow:0 14px 28px rgba(109,76,255,.24);
    transition:.22s ease;
}

.filter-btn:hover{
    transform:translateY(-1px);
    background:linear-gradient(135deg,#7c3aed,#6d4cff);
}

/* ================= TABLE CARD ================= */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46));
    border:1px solid rgba(148,163,184,.16);
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045);
    border-radius:18px;
    overflow:hidden;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, background .25s ease;
}

.table-card:hover{
    border-color:rgba(139,92,246,.45);
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06);
}

.table-responsive{
    overflow:auto;
}

.orders-table{
    width:100%;
    min-width:1050px;
    border-collapse:collapse;
    background:transparent;
}

.orders-table thead th{
    background:rgba(7,11,20,.58);
    color:#cbd5e1;
    font-weight:900;
    font-size:13px;
    padding:15px 14px;
    border-bottom:1px solid rgba(148,163,184,.16);
    border-right:1px solid rgba(148,163,184,.08);
    white-space:nowrap;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.orders-table tbody td{
    padding:15px 14px;
    border-top:1px solid rgba(148,163,184,.10);
    vertical-align:middle;
    font-size:14px;
    color:#e5e7eb;
    font-weight:600;
    background:rgba(15,23,42,.20);
}

.orders-table tbody tr:hover td{
    background:rgba(124,58,237,.12);
    color:#fff;
}

.orders-table input[type="checkbox"]{
    width:16px;
    height:16px;
    accent-color:#7c3aed;
}

.invoice-link{
    color:#93c5fd;
    font-weight:900;
    text-decoration:none;
}

.invoice-link:hover{
    color:#c4b5fd;
}

.date-main{
    display:block;
    font-weight:800;
    color:#f8fafc;
}

.date-sub{
    display:block;
    color:#94a3b8;
    font-size:12px;
    margin-top:3px;
}

/* ================= BADGES ================= */
.badge-type{
    padding:7px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:86px;
    border:1px solid transparent;
}

.walkin{
    background:rgba(245,158,11,.15);
    color:#fbbf24;
    border-color:rgba(245,158,11,.24);
}

.wholesale{
    background:rgba(34,197,94,.14);
    color:#4ade80;
    border-color:rgba(34,197,94,.24);
}

.retail{
    background:rgba(59,130,246,.14);
    color:#60a5fa;
    border-color:rgba(59,130,246,.24);
}

.status-badge{
    background:rgba(34,197,94,.14);
    color:#4ade80;
    border-color:rgba(34,197,94,.24);
}

/* ================= ACTIONS ================= */
.actions{
    display:flex;
    gap:10px;
    align-items:center;
    justify-content:center;
}

.actions a,
.actions button{
    width:40px;
    height:40px;
    border:none;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:16px;
    line-height:1;
    text-decoration:none;
    cursor:pointer;
    transition:all .20s ease;
    box-shadow:0 12px 24px rgba(0,0,0,.18);
    padding:0;
}

.actions a:hover,
.actions button:hover{
    transform:translateY(-2px);
}

.action-view{
    background:rgba(109,76,255,.16) !important;
    color:#a78bfa !important;
    border:1px solid rgba(109,76,255,.25) !important;
}

.action-view:hover{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

.action-edit{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.28) !important;
}

.action-edit:hover{
    background:linear-gradient(135deg,#f59e0b,#f97316) !important;
    color:#ffffff !important;
}

.action-print{
    background:rgba(148,163,184,.12) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.action-print:hover{
    background:#e5e7eb !important;
    color:#111827 !important;
}

.action-whatsapp{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.action-whatsapp:hover{
    background:#22c55e !important;
    color:#07111f !important;
}

/* ================= DETAIL ROW ================= */
.detail-row{
    display:none;
}

.detail-row td{
    background:rgba(7,11,20,.40) !important;
}

.detail-box{
    padding:20px;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56));
    border:1px solid rgba(148,163,184,.14);
    border-radius:18px;
    margin:4px 0;
}

.detail-title{
    font-size:18px;
    font-weight:900;
    margin-bottom:14px;
    color:#f8fafc;
}

.inner-table{
    width:100%;
    border-collapse:collapse;
    margin-bottom:16px;
    overflow:hidden;
    border-radius:14px;
}

.inner-table th,
.inner-table td{
    border:1px solid rgba(148,163,184,.13);
    padding:11px 12px;
    font-size:13px;
}

.inner-table th{
    background:rgba(7,11,20,.58);
    color:#cbd5e1;
    text-transform:uppercase;
    letter-spacing:.03em;
}

.inner-table td{
    background:rgba(15,23,42,.32);
    color:#e5e7eb;
}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
}

.summary-item{
    background:rgba(15,23,42,.55);
    border:1px solid rgba(148,163,184,.16);
    border-radius:15px;
    padding:14px;
    box-shadow:0 12px 26px rgba(0,0,0,.16);
}

.summary-item span{
    display:block;
    color:#94a3b8;
    font-size:12px;
    font-weight:800;
    margin-bottom:5px;
}

.summary-item strong{
    color:#f8fafc;
    font-size:18px;
    font-weight:900;
}

.btn-primary{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.45) !important;
    border-radius:12px;
    font-weight:900;
    box-shadow:0 14px 28px rgba(109,76,255,.24);
}

/* ================= BOTTOM ACTIONS ================= */
.bottom-actions{
    padding:16px;
    border-top:1px solid rgba(148,163,184,.13);
    background:rgba(7,11,20,.24);
}

.delete-btn{
    background:linear-gradient(135deg,#be123c,#ef4444);
    color:#fff;
    border:none;
    border-radius:13px;
    padding:12px 18px;
    font-weight:900;
    box-shadow:0 14px 28px rgba(239,68,68,.20);
    transition:.22s ease;
}

.delete-btn:hover{
    transform:translateY(-1px);
}

/* ================= RESPONSIVE ================= */
@media (max-width: 1200px){
    .stats-row{
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 991px){
    .container-scroll{
        flex-direction:column;
    }

    .sidebar{
        width:100%;
        min-width:100%;
        max-width:100%;
        position:relative;
        height:auto;
    }

    #dashboardSidebar .sidebar-wrap,
    #sidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    #dashboardSidebar .sidebar-inner,
    #sidebar .sidebar-inner{
        height:auto !important;
        min-height:auto !important;
    }

    .main-panel{
        margin-left:0;
    }

    .topbar{
        position:relative;
    }

    .content-wrapper{
        padding:18px;
    }
}

@media (max-width: 768px){
    .stats-row{
        grid-template-columns: 1fr;
    }

    .summary-grid{
        grid-template-columns:1fr 1fr;
    }

    /*.filter-bar{*/
    /*    display:grid;*/
    /*    grid-template-columns:1fr;*/
    /*}*/

    /*.filter-control,*/
    /*.search-wrap{*/
    /*    min-width:100%;*/
    /*}*/
}

@media (max-width: 500px){
    .summary-grid{
        grid-template-columns:1fr;
    }
}


/* =====================================================
   FINAL FIX: FILTER INPUTS ONE LINE ALIGNMENT
   Paste at the END of orders.php style
===================================================== */

.filter-bar{
    display:grid !important;
    grid-template-columns: 1.45fr 1.05fr 1.05fr .9fr 1fr auto !important;
    gap:10px !important;
    align-items:center !important;
    padding:12px !important;
    flex-wrap:nowrap !important;
}

.search-wrap{
    width:100% !important;
    min-width:0 !important;
    flex:none !important;
}

.search-wrap input,
.filter-control{
    width:100% !important;
    min-width:0 !important;
    height:44px !important;
    font-size:14px !important;
    border-radius:12px !important;
}

.filter-btn{
    width:92px !important;
    height:44px !important;
    padding:0 14px !important;
    white-space:nowrap !important;
    border-radius:12px !important;
}

/* Table thora close ho jaye filter ke sath */
.table-card{
    margin-top:0 !important;
}

/* Medium screens par bhi same line maintain */
@media (max-width: 1300px){
    .filter-bar{
        grid-template-columns: 1.35fr 1fr 1fr .85fr .95fr auto !important;
        gap:8px !important;
    }

    .search-wrap input,
    .filter-control,
    .filter-btn{
        height:42px !important;
        font-size:13px !important;
    }

    .filter-btn{
        width:86px !important;
        padding:0 10px !important;
    }
}

/* Mobile/tablet par hi wrap hona chahiye */
@media (max-width: 991px){
    .filter-bar{
        grid-template-columns:1fr 1fr !important;
    }

    .filter-btn{
        width:100% !important;
    }
}

/*@media (max-width: 576px){*/
/*    .filter-bar{*/
/*        grid-template-columns:1fr !important;*/
/*    }*/
/*}*/

/* =====================================================
   MOBILE FINAL FIX: 2 STAT CARDS + FILTER ALIGNMENT
   Paste at the END before 
===================================================== */

@media (max-width: 768px){

    body{
        overflow-x:hidden !important;
    }

    .content-wrapper{
        padding:16px 12px 22px 12px !important;
    }

    .page-title{
        font-size:26px !important;
        line-height:1.18 !important;
        margin-bottom:16px !important;
        letter-spacing:-.04em !important;
    }

    /* 2 cards in one line on mobile */
    .stats-row{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .stat-card{
        min-height:82px !important;
        border-radius:16px !important;
        padding:12px 10px !important;
        gap:8px !important;
        font-size:12px !important;
        line-height:1.15 !important;
        align-items:center !important;
    }

    .stat-card span{
        display:flex !important;
        align-items:center !important;
        gap:6px !important;
        min-width:0 !important;
        font-size:12px !important;
        line-height:1.15 !important;
        font-weight:900 !important;
    }

    .stat-card i{
        font-size:17px !important;
        margin-right:0 !important;
        flex:0 0 auto !important;
    }

    .stat-card strong{
        font-size:15px !important;
        font-weight:900 !important;
        flex:0 0 auto !important;
    }

    .stat-card::after{
        width:50px !important;
        height:50px !important;
        right:8px !important;
        top:50% !important;
        transform:translateY(-50%) !important;
        border-radius:16px !important;
    }

    /* Filter box proper aligned on mobile */
    .filter-bar{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        padding:12px !important;
        border-radius:18px !important;
        margin-bottom:14px !important;
        align-items:center !important;
    }

    .search-wrap{
        grid-column:1 / -1 !important;
        width:100% !important;
        min-width:0 !important;
    }

    .search-wrap input,
    .filter-control{
        width:100% !important;
        min-width:0 !important;
        height:44px !important;
        font-size:12px !important;
        border-radius:13px !important;
        padding-left:12px !important;
        padding-right:10px !important;
    }

    .search-wrap input{
        padding-left:40px !important;
    }

    .search-wrap i{
        left:14px !important;
        font-size:14px !important;
    }

    .filter-btn{
        grid-column:1 / -1 !important;
        width:100% !important;
        height:46px !important;
        border-radius:14px !important;
        font-size:13px !important;
        font-weight:900 !important;
        padding:0 12px !important;
    }

    .table-card{
        border-radius:18px !important;
        margin-top:0 !important;
    }
}

/* Very small screen 328px / 360px */
@media (max-width: 380px){

    .content-wrapper{
        padding-left:10px !important;
        padding-right:10px !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .stats-row{
        gap:9px !important;
    }

    .stat-card{
        min-height:78px !important;
        padding:10px 9px !important;
        font-size:11px !important;
    }

    .stat-card span{
        font-size:11px !important;
    }

    .stat-card i{
        font-size:16px !important;
    }

    .stat-card strong{
        font-size:14px !important;
    }

    .filter-bar{
        gap:9px !important;
        padding:11px !important;
    }

    .search-wrap input,
    .filter-control{
        height:42px !important;
        font-size:11px !important;
    }
}

/* =========================================================
   ONEERP ORDERS PAGE LIGHT MODE
   Sirf light mode affect hoga.
   Dark mode aur backend logic unchanged.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE AND LAYOUT
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] .container-scroll{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] .main-panel{
    background:#f3f6fb !important;
}



/* Topbar */
html[data-pos-theme="light"] .topbar{
    background:#ffffff !important;
    border-bottom-color:#e2e8f0 !important;
    box-shadow:0 4px 18px rgba(15,23,42,.06) !important;
}

/* Main content background */
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
        radial-gradient(
            circle at 92% 86%,
            rgba(245,158,11,.055),
            transparent 26%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 42%,
            #faf7ff 72%,
            #f8fafc 100%
        ) !important;
}

/* Background grid */
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
    opacity:.55 !important;
}

/* Remove strong dark/purple glow */
html[data-pos-theme="light"] .content-wrapper::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.10),
        transparent 68%
    ) !important;
    opacity:.55 !important;
}

/* =========================================================
   PAGE TITLE
========================================================= */

html[data-pos-theme="light"] .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

/*
|--------------------------------------------------------------------------
| Colored cards colored hi rahenge.
|--------------------------------------------------------------------------
*/

html[data-pos-theme="light"] .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] .stat-card span,
html[data-pos-theme="light"] .stat-card strong,
html[data-pos-theme="light"] .stat-card i{
    color:#ffffff !important;
}

html[data-pos-theme="light"] .stat-card:hover,
html[data-pos-theme="light"] .stat-card.active{
    box-shadow:
        0 18px 42px rgba(15,23,42,.18),
        0 0 0 3px rgba(124,58,237,.10) !important;
}

/* =========================================================
   ALERTS
========================================================= */

html[data-pos-theme="light"] .alert-success-custom{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] .alert-danger-custom{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

/* =========================================================
   FILTER BAR
========================================================= */

html[data-pos-theme="light"] .filter-bar{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.06),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
}

/* Search icon */
html[data-pos-theme="light"] .search-wrap i{
    color:#64748b !important;
}

/* Search and filter inputs */
html[data-pos-theme="light"] .search-wrap input,
html[data-pos-theme="light"] .filter-control{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#d5deea !important;
    box-shadow:none !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .search-wrap input::placeholder,
html[data-pos-theme="light"] .filter-control::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .search-wrap input:focus,
html[data-pos-theme="light"] .filter-control:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

/* Date input */
html[data-pos-theme="light"] input[type="date"]{
    color-scheme:light !important;
}

/* Select options */
html[data-pos-theme="light"] select.filter-control option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Filter button */
html[data-pos-theme="light"] .filter-btn{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    box-shadow:0 10px 22px rgba(124,58,237,.20) !important;
}

/* =========================================================
   TABLE CARD
========================================================= */

html[data-pos-theme="light"] .table-card{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.045),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
}

html[data-pos-theme="light"] .table-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:
        0 16px 36px rgba(15,23,42,.09),
        0 0 0 1px rgba(124,58,237,.08) !important;
}

/* =========================================================
   ORDERS TABLE
========================================================= */

/* Table header */
html[data-pos-theme="light"] .orders-table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom-color:#cbd5e1 !important;
    border-right-color:#e2e8f0 !important;
}

/* Table rows */
html[data-pos-theme="light"] .orders-table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-top-color:#e8edf4 !important;
}

/* Row hover */
html[data-pos-theme="light"] .orders-table tbody tr:hover td{
    background:#f6f2ff !important;
    color:#0f172a !important;
}

/* Checkbox */
html[data-pos-theme="light"] .orders-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Invoice link */
html[data-pos-theme="light"] .invoice-link{
    color:#2563eb !important;
}

html[data-pos-theme="light"] .invoice-link:hover{
    color:#6d28d9 !important;
}

/* Date text */
html[data-pos-theme="light"] .date-main{
    color:#0f172a !important;
}

html[data-pos-theme="light"] .date-sub{
    color:#64748b !important;
}

/* =========================================================
   CUSTOMER AND STATUS BADGES
========================================================= */

html[data-pos-theme="light"] .walkin{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] .wholesale{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] .retail{
    background:#eff6ff !important;
    color:#1d4ed8 !important;
    border-color:#bfdbfe !important;
}

html[data-pos-theme="light"] .status-badge{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

/* =========================================================
   ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] .actions a,
html[data-pos-theme="light"] .actions button{
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}
/*edit*/ 
html[data-pos-theme="light"] .action-edit{
    background:#fff7ed !important;
    color:#c2410c !important;
    border-color:#fed7aa !important;
}

html[data-pos-theme="light"] .action-edit:hover{
    background:linear-gradient(135deg,#f59e0b,#f97316) !important;
    color:#ffffff !important;
}

/* Print */
html[data-pos-theme="light"] .action-print{
    background:#f8fafc !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] .action-print:hover{
    background:#334155 !important;
    color:#ffffff !important;
}

/* WhatsApp */
html[data-pos-theme="light"] .action-whatsapp{
    background:#ecfdf5 !important;
    color:#059669 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] .action-whatsapp:hover{
    background:#16a34a !important;
    color:#ffffff !important;
}

/* =========================================================
   ORDER DETAIL ROW
========================================================= */

html[data-pos-theme="light"] .detail-row td{
    background:#f8fafc !important;
}

html[data-pos-theme="light"] .detail-box{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.05),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 10px 24px rgba(15,23,42,.05) !important;
}

html[data-pos-theme="light"] .detail-title{
    color:#0f172a !important;
}

/* Inner item table */
html[data-pos-theme="light"] .inner-table th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-color:#dce4ef !important;
}

html[data-pos-theme="light"] .inner-table td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-color:#e2e8f0 !important;
}

/* =========================================================
   ORDER SUMMARY CARDS
========================================================= */

html[data-pos-theme="light"] .summary-item{
    background:#f8fafc !important;
    border-color:#dce4ef !important;
    box-shadow:0 6px 16px rgba(15,23,42,.05) !important;
}

html[data-pos-theme="light"] .summary-item span{
    color:#64748b !important;
}

html[data-pos-theme="light"] .summary-item strong{
    color:#0f172a !important;
}

/* View Order button */
html[data-pos-theme="light"] .btn-primary{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
}

/* =========================================================
   BOTTOM ACTIONS
========================================================= */

html[data-pos-theme="light"] .bottom-actions{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] .delete-btn{
    background:linear-gradient(
        135deg,
        #e11d48,
        #ef4444
    ) !important;
    color:#ffffff !important;
}

/* =========================================================
   GENERAL TEXT VISIBILITY
========================================================= */

html[data-pos-theme="light"] .orders-table,
html[data-pos-theme="light"] .detail-box,
html[data-pos-theme="light"] .filter-bar{
    color:#0f172a !important;
}

html[data-pos-theme="light"] input,
html[data-pos-theme="light"] select,
html[data-pos-theme="light"] button{
    opacity:1 !important;
}

/* Mobile sidebar overlay light page par dark hi rahe */
@media (max-width:991px){
    html[data-pos-theme="light"] body.sidebar-open::before{
        background:rgba(15,23,42,.45) !important;
    }
}

</style>
</head>

<body>

<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">
 <h4 class="page-title">
    <?= $show_booking_orders ? 'Booking / Appointment Management' : 'Invoice Management' ?>
</h4>

            <?php if(!empty($_SESSION['success_message'])): ?>
                <div class="alert-custom alert-success-custom">
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if(!empty($_SESSION['error_message'])): ?>
                <div class="alert-custom alert-danger-custom">
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

<?php if ($show_booking_orders): ?>

<div class="stats-row mb-3">
    <div class="stat-card card-blue" data-booking-status="all">
        <span><i class="fa-solid fa-calendar-check me-2"></i>All Bookings</span>
        <strong><?= $total_bookings ?></strong>
    </div>

    <div class="stat-card card-green" data-booking-status="today">
        <span><i class="fa-solid fa-clock me-2"></i>Today Bookings</span>
        <strong><?= $today_bookings ?></strong>
    </div>

    <div class="stat-card card-cyan" data-booking-status="pending">
        <span><i class="fa-solid fa-hourglass-half me-2"></i>Pending</span>
        <strong><?= $pending_bookings ?></strong>
    </div>

    <div class="stat-card card-yellow" data-booking-status="confirmed">
        <span><i class="fa-solid fa-circle-check me-2"></i>Confirmed</span>
        <strong><?= $confirmed_bookings ?></strong>
    </div>
</div>

<?php else: ?>

            <div class="stats-row mb-3">
                <div class="stat-card card-blue" data-type="all">
                    <span><i class="fa-solid fa-file-invoice me-2"></i>All Orders</span>
                    <strong><?= count($all_orders) ?></strong>
                </div>

                <div class="stat-card card-green" data-type="Walk-in">
                    <span><i class="fa-solid fa-cart-shopping me-2"></i>Walk-In Orders</span>
                    <strong><?= $walkin_count ?></strong>
                </div>

                <div class="stat-card card-cyan" data-type="Wholesale">
                    <span><i class="fa-solid fa-briefcase me-2"></i>Wholesale Orders</span>
                    <strong><?= $wholesale_count ?></strong>
                </div>

                <div class="stat-card card-yellow" data-type="Retail">
                    <span><i class="fa-solid fa-store me-2"></i>Retail Orders</span>
                    <strong><?= $retail_count ?></strong>
                </div>
            </div>
            <?php endif; ?>




<?php if ($show_booking_orders): ?>

<div class="filter-bar">
    <div class="search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="searchBookings" placeholder="Search Booking / Customer">
    </div>

    <input type="text" id="filterBookingTitle" class="filter-control" placeholder="Filter by Booking">
    <input type="text" id="filterBookingCustomer" class="filter-control" placeholder="Filter by Customer">
    <input type="date" id="filterBookingDate" class="filter-control">

    <select id="filterBookingStatus" class="filter-control">
        <option value="">All Status</option>
        <option value="booked">Booked</option>
        <option value="pending">Pending</option>
        <option value="confirmed">Confirmed</option>
        <option value="approved">Approved</option>
        <option value="cancelled">Cancelled</option>
    </select>

    <button class="filter-btn" type="button" onclick="applyBookingFilters()">
        <i class="fa-solid fa-filter me-1"></i> Filter
    </button>
</div>

<?php else: ?>

            <div class="filter-bar">
                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="searchOrders" placeholder="Search Orders">
                </div>

                <input type="text" id="filterOrderNo" class="filter-control" placeholder="Filter by Order #">
                <input type="text" id="filterCustomer" class="filter-control" placeholder="Filter by Customer">
                <input type="date" id="filterDate" class="filter-control">

                <select id="filterType" class="filter-control">
                    <option value="">All Customers</option>
                    <option value="Walk-in">Walk-in</option>
                    <option value="Wholesaler">Wholesaler</option>
                    <option value="Retailer">Retailer</option>
                </select>

                <button class="filter-btn" type="button" onclick="applyFilters()">
                    <i class="fa-solid fa-filter me-1"></i> Filter
                </button>
            </div>
            <?php endif; ?>
            
            <?php if ($show_booking_orders): ?>
            
            <div class="table-card">
    <form method="POST" id="ordersForm">
        <div class="table-responsive">
            <table class="orders-table" id="bookingsTable">
                <thead>
                    <tr>
                        <th style="width:50px;">
                            <input type="checkbox" id="selectAll">
                        </th>
                        <th>Booking ID</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Customer</th>
                        <th>Booking / Service</th>
                        <th>Status</th>
                        <th width="180" class="text-center">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($all_bookings)): ?>
                        <?php foreach ($all_bookings as $booking): ?>
                            <?php
                                $bookingId = (int)$booking['id'];
                                $bookingDateRaw = $booking['booking_date'] ?? '';
                                $bookingDate = $bookingDateRaw ? date('Y-m-d', strtotime($bookingDateRaw)) : '';
                                $bookingDateShow = $bookingDateRaw ? date('d-m-Y', strtotime($bookingDateRaw)) : 'N/A';

                                $bookingTime = !empty($booking['booking_time'])
                                    ? date('h:i A', strtotime($booking['booking_time']))
                                    : 'N/A';

                                $bookingCustomer = $booking['customer_name'] ?? 'Customer';
                                $bookingTitle = $booking['booking_title'] ?? 'Booking / Appointment';
                                $bookingStatus = strtolower(trim((string)($booking['booking_status'] ?? 'booked')));
                            ?>

                            <tr class="booking-main-row"
                                data-booking="<?= strtolower(htmlspecialchars($bookingTitle)) ?>"
                                data-customer="<?= strtolower(htmlspecialchars($bookingCustomer)) ?>"
                                data-date="<?= htmlspecialchars($bookingDate) ?>"
                                data-status="<?= htmlspecialchars($bookingStatus) ?>"
                                data-booking-id="<?= $bookingId ?>">
                                <td>
                                    <input type="checkbox" name="selected_orders[]" value="<?= $bookingId ?>" class="row-checkbox">
                                </td>

                                <td>
                                    <a href="appointments.php?id=<?= $bookingId ?>" class="invoice-link">
                                        #BK-<?= $bookingId ?>
                                    </a>
                                </td>

                                <td>
                                    <span class="date-main"><?= htmlspecialchars($bookingDateShow) ?></span>
                                    <span class="date-sub"><?= htmlspecialchars(date('l', strtotime($bookingDateRaw ?: date('Y-m-d')))) ?></span>
                                </td>

                                <td><?= htmlspecialchars($bookingTime) ?></td>

                                <td>
                                    <span class="badge-type retail">
                                        <?= htmlspecialchars($bookingCustomer) ?>
                                    </span>
                                </td>

                                <td><?= htmlspecialchars($bookingTitle) ?></td>

                                <td>
                                    <span class="badge-type status-badge">
                                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $bookingStatus))) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="actions">
<a
    href="appointments.php?id=<?= $bookingId ?>"
    class="action-view"
    title="View Booking"
>
    <i class="fa-solid fa-eye"></i>
</a>

                                        <a
                                            href="appointments.php?id=<?= $bookingId ?>&print=1"
                                            class="action-print"
                                            title="Print Booking"
                                            target="_blank"
                                        >
                                            <i class="fa-solid fa-print"></i>
                                        </a>

                                        <a
                                            href="https://wa.me/?text=<?= urlencode('Booking #BK-' . $bookingId . ' - ' . $bookingTitle . ' on ' . $bookingDateShow . ' at ' . $bookingTime) ?>"
                                            class="action-whatsapp"
                                            target="_blank"
                                            title="WhatsApp"
                                        >
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                No bookings / appointments found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="bottom-actions">
            <button type="submit" name="delete_selected" class="delete-btn">
                <i class="fa-solid fa-trash me-1"></i> Delete Selected
            </button>
        </div>
    </form>
</div>
<?php else: ?>

            <div class="table-card">
                <form method="POST" id="ordersForm">
                    <div class="table-responsive">
                        <table class="orders-table" id="ordersTable">
                            <thead>
                                <tr>
                                    <th style="width:50px;">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Payment</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th width="220" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!empty($all_orders)): ?>
                                    <?php foreach($all_orders as $order): ?>
                                        <?php
                                            $invoice_no = $order['invoice_no'];
                                            $created_at = !empty($order['created_at']) ? $order['created_at'] : date('Y-m-d H:i:s');
                                            $customer_type = $order['customer_type'] ?? 'Walk-in';
                                            $customer_name = $customer_type === 'Walk-in'
                                                ? 'Walk-In'
                                                : (!empty($order['customer_name']) ? $order['customer_name'] : $customer_type);

                                            $customer_phone = (string)($order['customer_phone'] ?? '');
                                            $wa_phone = orders_whatsapp_phone($customer_phone);
                                            $payment_method = $order['payment_method'] ?? 'Cash';
                                            $status = $order['status'] ?? 'Completed';
                                            $wa_message = "Invoice: {$invoice_no}\nCustomer: {$customer_name}\nTotal: Rs" . number_format((float)$order['grand_total'], 2) . "\nPlease check your invoice.";
                                            $wa_url = $wa_phone !== ''
                                                ? ('https://wa.me/' . $wa_phone . '?text=' . urlencode($wa_message))
                                                : ('https://wa.me/?text=' . urlencode($wa_message));

                                            $type_class = 'retail';
                                            if(strtolower($customer_type) === 'walk-in' || strtolower($customer_type) === 'walkin') $type_class = 'walkin';
                                            if(strtolower($customer_type) === 'wholesaler' || strtolower($customer_type) === 'wholesale') $type_class = 'wholesale';
                                            if(strtolower($customer_type) === 'retailer' || strtolower($customer_type) === 'retail') $type_class = 'retail';
                                        ?>
                                        <tr class="order-main-row"
                                            data-order="<?= strtolower($invoice_no) ?>"
                                            data-customer="<?= strtolower($customer_name) ?>"
                                            data-date="<?= date('Y-m-d', strtotime($created_at)) ?>"
                                            data-type="<?= htmlspecialchars($customer_type) ?>"
                                            data-sale-id="<?= (int)$order['id'] ?>">
                                            <td>
                                                <input type="checkbox" name="selected_orders[]" value="<?= (int)$order['id'] ?>" class="row-checkbox">
                                            </td>
                                            <td>
                                                <a href="order_view.php?sale_id=<?= (int)$order['id'] ?>" class="invoice-link">
                                                    <?= htmlspecialchars($invoice_no) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="date-main"><?= date('d-m-Y h:i A', strtotime($created_at)) ?></span>
                                                <span class="date-sub"><?= date('d-m-Y', strtotime($created_at)) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge-type <?= $type_class ?>">
                                                    <?= htmlspecialchars($customer_name) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($payment_method) ?></td>
                                            <td>Rs<?= number_format((float)$order['grand_total'], 2) ?></td>
                                            <td>
                                                <span class="badge-type status-badge"><?= htmlspecialchars($status) ?></span>
                                            </td>
                                            <td>
                                                <div class="actions">
                                               <a
    href="order_view.php?sale_id=<?= (int)$order['id'] ?>"
    class="action-edit"
    title="Edit Invoice"
>
    <i class="fa-solid fa-pen-to-square"></i>
</a>

                                                    <a
                                                        href="thermal.php?sale_id=<?= (int)$order['id'] ?>"
                                                        class="action-print"
                                                        title="Print"
                                                        target="_blank"
                                                    >
                                                        <i class="fa-solid fa-print"></i>
                                                    </a>

                                                    <a
                                                        href="<?= htmlspecialchars($wa_url) ?>"
                                                        class="action-whatsapp"
                                                        target="_blank"
                                                        title="WhatsApp"
                                                    >
                                                        <i class="fa-brands fa-whatsapp"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>

                                        <tr class="detail-row" id="items-<?= (int)$order['id'] ?>">
                                            <td colspan="8">
                                                <div class="detail-box">
                                                    <div class="detail-title">Order Details - <?= htmlspecialchars($invoice_no) ?></div>

                                                    <table class="inner-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Item</th>
                                                                <th>Selected Price</th>
                                                                <th>Qty</th>
                                                                <th>Price</th>
                                                                <th>Total</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if(!empty($order['items'])): ?>
                                                                <?php foreach($order['items'] as $item): ?>
                                                                    <?php
                                                                        $line_total = isset($item['total']) ? (float)$item['total'] : ((float)$item['qty'] * (float)$item['price']);
                                                                        $tier_type = strtolower(trim((string)($item['price_tier_type'] ?? 'default')));
                                                                        $tier_no = (int)($item['price_tier_no'] ?? 0);
                                                                        $tier_label = trim((string)($item['price_tier_label'] ?? ''));

                                                                        if ($tier_type === 'wholesale' && $tier_no > 0) {
                                                                            $selected_price_label = $tier_label !== '' ? $tier_label : ('Wholesale ' . $tier_no);
                                                                        } else {
                                                                            $selected_price_label = 'Default';
                                                                        }
                                                                    ?>
                                                                    <tr>
                                                                        <td><?= htmlspecialchars($item['display_item_name'] ?? $item['name'] ?? 'Item') ?></td>
                                                                        <td>
                                                                            <?php if($tier_type === 'wholesale' && $tier_no > 0): ?>
                                                                                <span class="badge-type wholesale"><?= htmlspecialchars($selected_price_label) ?></span>
                                                                            <?php else: ?>
                                                                                <span class="badge-type retail">Default</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td><?= (float)$item['qty'] ?></td>
                                                                        <td>Rs<?= number_format((float)$item['price'],2) ?></td>
                                                                        <td>Rs<?= number_format($line_total,2) ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <tr>
                                                                    <td colspan="5" class="text-center">No items found</td>
                                                                </tr>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>

                                                    <div class="summary-grid">
                                                        <div class="summary-item">
                                                            <span>Subtotal</span>
                                                            <strong>Rs<?= number_format((float)$order['subtotal'],2) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Discount (<?= (float)($order['discount'] ?? 0) ?>%)</span>
                                                            <strong>Rs<?= number_format((float)$order['discount_amount'],2) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Tax (<?= (float)($order['tax'] ?? 0) ?>%)</span>
                                                            <strong>Rs<?= number_format((float)$order['tax_amount'],2) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Grand Total</span>
                                                            <strong>Rs<?= number_format((float)$order['grand_total'],2) ?></strong>
                                                        </div>
                                                    </div>

                                                    <div class="mt-3 text-end">
                                                        <a href="order_view.php?sale_id=<?= (int)$order['id'] ?>" class="btn btn-primary">
                                                            View Order
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">No orders found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="bottom-actions">
                        <button type="submit" name="delete_selected" class="delete-btn">
                            <i class="fa-solid fa-trash me-1"></i> Delete Selected
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    
    function applyBookingFilters(){
    let searchValue = document.getElementById("searchBookings")?.value.toLowerCase().trim() || "";
    let titleFilter = document.getElementById("filterBookingTitle")?.value.toLowerCase().trim() || "";
    let customerFilter = document.getElementById("filterBookingCustomer")?.value.toLowerCase().trim() || "";
    let dateFilter = document.getElementById("filterBookingDate")?.value || "";
    let statusFilter = document.getElementById("filterBookingStatus")?.value.toLowerCase().trim() || "";

    document.querySelectorAll(".booking-main-row").forEach(row => {
        let rowBooking = row.getAttribute("data-booking") || "";
        let rowCustomer = row.getAttribute("data-customer") || "";
        let rowDate = row.getAttribute("data-date") || "";
        let rowStatus = row.getAttribute("data-status") || "";

        let show = true;

        if (searchValue && !(rowBooking.includes(searchValue) || rowCustomer.includes(searchValue))) {
            show = false;
        }

        if (titleFilter && !rowBooking.includes(titleFilter)) {
            show = false;
        }

        if (customerFilter && !rowCustomer.includes(customerFilter)) {
            show = false;
        }

        if (dateFilter && rowDate !== dateFilter) {
            show = false;
        }

        if (statusFilter && rowStatus !== statusFilter) {
            show = false;
        }

        row.style.display = show ? "table-row" : "none";
    });
}
function toggleItems(id){
    const el = document.getElementById("items-" + id);
    if(!el) return;
    el.style.display = (el.style.display === "table-row") ? "none" : "table-row";
}

function applyFilters(){
    let searchValue = document.getElementById("searchOrders").value.toLowerCase().trim();
    let orderFilter = document.getElementById("filterOrderNo").value.toLowerCase().trim();
    let customerFilter = document.getElementById("filterCustomer").value.toLowerCase().trim();
    let dateFilter = document.getElementById("filterDate").value;
    let typeFilter = document.getElementById("filterType").value;

    document.querySelectorAll(".order-main-row").forEach(row => {
        let rowOrder = row.getAttribute("data-order") || "";
        let rowCustomer = row.getAttribute("data-customer") || "";
        let rowDate = row.getAttribute("data-date") || "";
        let rowType = row.getAttribute("data-type") || "";

        let show = true;

        if(searchValue && !(rowOrder.includes(searchValue) || rowCustomer.includes(searchValue))){
            show = false;
        }
        if(orderFilter && !rowOrder.includes(orderFilter)){
            show = false;
        }
        if(customerFilter && !rowCustomer.includes(customerFilter)){
            show = false;
        }
        if(dateFilter && rowDate !== dateFilter){
            show = false;
        }
        if(typeFilter && rowType !== typeFilter){
            show = false;
        }

        row.style.display = show ? "table-row" : "none";

        let checkbox = row.querySelector(".row-checkbox");
        if(checkbox){
            let detailRow = document.getElementById("items-" + checkbox.value);
            if(detailRow && !show){
                detailRow.style.display = "none";
            }
        }
    });
}
document.getElementById("searchOrders")?.addEventListener("keyup", applyFilters);
document.getElementById("filterOrderNo")?.addEventListener("keyup", applyFilters);
document.getElementById("filterCustomer")?.addEventListener("keyup", applyFilters);
document.getElementById("filterDate")?.addEventListener("change", applyFilters);
document.getElementById("filterType")?.addEventListener("change", applyFilters);

document.getElementById("searchBookings")?.addEventListener("keyup", applyBookingFilters);
document.getElementById("filterBookingTitle")?.addEventListener("keyup", applyBookingFilters);
document.getElementById("filterBookingCustomer")?.addEventListener("keyup", applyBookingFilters);
document.getElementById("filterBookingDate")?.addEventListener("change", applyBookingFilters);
document.getElementById("filterBookingStatus")?.addEventListener("change", applyBookingFilters);
document.getElementById("selectAll")?.addEventListener("change", function(){
    document.querySelectorAll(".row-checkbox").forEach(chk => {
        chk.checked = this.checked;
    });
});

document.getElementById("ordersForm")?.addEventListener("submit", function(e){
    let checked = document.querySelectorAll(".row-checkbox:checked");
    if(checked.length === 0){
        e.preventDefault();
        alert("Please select at least one order.");
        return false;
    }

    if(!confirm("Are you sure you want to delete selected orders?")){
        e.preventDefault();
        return false;
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const statCards = document.querySelectorAll('.stat-card');
    const orderRows = document.querySelectorAll('.order-main-row');
    const bookingRows = document.querySelectorAll('.booking-main-row');

    statCards.forEach(card => {
        if (card.dataset.bookingStatus) {
    card.addEventListener('click', () => {
        const status = card.dataset.bookingStatus.toLowerCase().trim();

        statCards.forEach(c => c.classList.remove('active'));
        card.classList.add('active');

        bookingRows.forEach(row => {
            const rowStatus = (row.dataset.status || '').toLowerCase().trim();
            const rowDate = row.dataset.date || '';
            const today = new Date().toISOString().slice(0, 10);

            let show = false;

            if (status === 'all') {
                show = true;
            } else if (status === 'today') {
                show = rowDate === today;
            } else if (status === 'pending') {
                show = rowStatus === 'pending' || rowStatus === 'booked';
            } else if (status === 'confirmed') {
                show = rowStatus === 'confirmed' || rowStatus === 'approved';
            }

            row.style.display = show ? '' : 'none';
        });
    });

    return;
}
        card.addEventListener('click', () => {
            const type = card.dataset.type.toLowerCase().trim();

            statCards.forEach(c => c.classList.remove('active'));
            card.classList.add('active');

            orderRows.forEach(row => {
                let rowType = row.dataset.type.toLowerCase().trim();

                if(rowType === 'wholesaler') rowType = 'wholesale';
                if(rowType === 'retailer') rowType = 'retail';
                if(rowType === 'walkin') rowType = 'walk-in';

                if(type === 'all' || rowType === type){
                    row.style.display = '';
                    const detailRow = document.getElementById('items-' + row.dataset.saleId);
                    if(detailRow) detailRow.style.display = 'none';
                } else {
                    row.style.display = 'none';
                    const detailRow = document.getElementById('items-' + row.dataset.saleId);
                    if(detailRow) detailRow.style.display = 'none';
                }
            });
        });
    });


});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>