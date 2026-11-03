<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$sale_id = intval($_GET['sale_id'] ?? 0);
if(!$sale_id) die("Order not found");

function esc($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

// Fetch sale with customer name
$stmt = $conn->prepare("
    SELECT 
        s.*,
        c.name AS customer_name,
        c.phone AS customer_phone,
        c.address AS customer_address
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id AND c.client_id = s.client_id
    WHERE s.id = ? AND s.client_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $sale_id, $_SESSION['client_id']);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$sale) die("Order not found");

$client_id = (int)$_SESSION['client_id'];
$branch_id = (int)($sale['branch_id'] ?? ($_SESSION['branch_id'] ?? 0));
$saleCustomerType = (string)($sale['customer_type'] ?? $sale['type'] ?? 'Walk-in');
$isWholesalerOrder = in_array(strtolower(trim($saleCustomerType)), ['wholesaler', 'wholesale'], true);
$hasSaleItemId = columnExists($conn, 'sale_items', 'id');

/*
|--------------------------------------------------------------------------
| Maximum discount columns compatibility
|--------------------------------------------------------------------------
| New clean columns: has_max_discount / max_discount_percent
| Old compatible columns: has_discount / discount_type / discount_value
| Order view needs these fields for item-wise capped discount preview.
*/
$itemsHasMaxDiscount       = columnExists($conn, 'items', 'has_max_discount');
$itemsHasMaxDiscountValue  = columnExists($conn, 'items', 'max_discount_percent');
$itemsHasOldDiscount       = columnExists($conn, 'items', 'has_discount');
$itemsHasOldDiscountType   = columnExists($conn, 'items', 'discount_type');
$itemsHasOldDiscountValue  = columnExists($conn, 'items', 'discount_value');

$itemDiscountCols = "";
$itemDiscountCols .= $itemsHasMaxDiscount
    ? ", i.has_max_discount AS product_has_max_discount"
    : ", 0 AS product_has_max_discount";
$itemDiscountCols .= $itemsHasMaxDiscountValue
    ? ", i.max_discount_percent AS product_max_discount_percent"
    : ", 0 AS product_max_discount_percent";
$itemDiscountCols .= $itemsHasOldDiscount
    ? ", i.has_discount AS product_has_discount"
    : ", 0 AS product_has_discount";
$itemDiscountCols .= $itemsHasOldDiscountType
    ? ", i.discount_type AS product_discount_type"
    : ", 'percent' AS product_discount_type";
$itemDiscountCols .= $itemsHasOldDiscountValue
    ? ", i.discount_value AS product_discount_value"
    : ", 0 AS product_discount_value";

// Fetch sale items with item name + default/wholesale product price
$stmt = $conn->prepare("
    SELECT 
        si.*,
        " . ($hasSaleItemId ? "si.id AS sale_item_id," : "0 AS sale_item_id,") . "
        i.price AS default_product_price,
        i.wholesaler_price_1 AS product_wholesale_1,
        i.wholesaler_price_2 AS product_wholesale_2
        $itemDiscountCols,
        CASE
            WHEN COALESCE(si.item_id, 0) = 0 
                THEN COALESCE(NULLIF(si.item_name, ''), 'Custom Product')
            ELSE 
                COALESCE(NULLIF(si.item_name, ''), NULLIF(i.name, ''), 'Unknown Product')
        END AS product_name
    FROM sale_items si
    LEFT JOIN items i ON i.id = si.item_id AND i.client_id = ?
    WHERE si.sale_id = ?
    ORDER BY " . ($hasSaleItemId ? "si.id ASC" : "si.item_id ASC") . "
");
$stmt->bind_param("ii", $client_id, $sale_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
/*
|--------------------------------------------------------------------------
| Direct wholesaler prices for invoice dropdown
|--------------------------------------------------------------------------
| New POS flow item_price_tiers table use nahi karta. Prices direct items table
| ke columns se aati hain: wholesaler_price_1 / wholesaler_price_2.
*/

// Client info
$client = $conn->query("SELECT business_name FROM clients WHERE id=$client_id")->fetch_assoc();
$business_name = $client['business_name'] ?? "Default Business";

// Customer address
$customerAddress = !empty($sale['customer_address']) ? (string)$sale['customer_address'] : 'Walk-in Address';

// Invoice number generator
function generateInvoiceNumber($client_id, $business_name, $conn){
    $words = explode(" ", $business_name);
    $words3 = array_slice($words,0,3);
    $initials = "";
    foreach($words3 as $w){
        $initials .= strtoupper(substr($w,0,1));
    }
    $res = $conn->query("SELECT invoice_no FROM sales WHERE client_id=$client_id AND invoice_no IS NOT NULL ORDER BY id DESC LIMIT 1");
    $lastInvoice = $res->fetch_assoc()['invoice_no'] ?? null;
    if($lastInvoice){
        $parts = explode("-", $lastInvoice);
        $num = intval($parts[1] ?? 0) + 1;
    } else {
        $num = 1;
    }
    return $initials."-".str_pad($num,3,"0",STR_PAD_LEFT);
}

function percentValue($value): float
{
    $value = (float)$value;
    if ($value < 0) return 0.00;
    if ($value > 100) return 100.00;
    return $value;
}

function resolveItemMaxDiscountPercent(array $item): float
{
    /*
    |--------------------------------------------------------------------------
    | Maximum discount resolver
    |--------------------------------------------------------------------------
    | Prefer new clean columns. If they are not available, old discount columns
    | are treated as maximum discount limit for compatibility with old DB.
    */
    $hasMax = (int)($item['product_has_max_discount'] ?? 0) === 1;
    $maxPercent = percentValue($item['product_max_discount_percent'] ?? 0);

    if ($hasMax && $maxPercent > 0) {
        return $maxPercent;
    }

    $hasOldDiscount = (int)($item['product_has_discount'] ?? 0) === 1;
    $oldType = strtolower(trim((string)($item['product_discount_type'] ?? 'percent')));
    $oldValue = percentValue($item['product_discount_value'] ?? 0);

    if ($hasOldDiscount && $oldType === 'percent' && $oldValue > 0) {
        return $oldValue;
    }

    return 0.00;
}

function getSavedLineDiscountPercent(array $item): float
{
    foreach (['discount_percent', 'applied_discount_percent', 'discount'] as $col) {
        if (array_key_exists($col, $item) && $item[$col] !== null && $item[$col] !== '') {
            return percentValue($item[$col]);
        }
    }

    return 0.00;
}

function getSavedLineDiscountAmount(array $item, float $lineTotal, float $discountPercent): float
{
    foreach (['discount_amount', 'line_discount'] as $col) {
        if (array_key_exists($col, $item) && $item[$col] !== null && $item[$col] !== '') {
            return max(0, (float)$item[$col]);
        }
    }

    return round($lineTotal * ($discountPercent / 100), 2);
}

function saleItemsHaveSavedDiscountData(array $items): bool
{
    foreach ($items as $item) {
        $discountPercent = getSavedLineDiscountPercent($item);
        $discountAmount = 0;

        foreach (['discount_amount', 'line_discount'] as $col) {
            if (array_key_exists($col, $item) && $item[$col] !== null && $item[$col] !== '') {
                $discountAmount = max($discountAmount, (float)$item[$col]);
            }
        }

        if ($discountPercent > 0 || $discountAmount > 0) {
            return true;
        }
    }

    return false;
}

function resolveOrderViewLineDiscountPercent(array $item, float $orderDiscountPercent, bool $useSavedLineDiscountData): float
{
    if ($useSavedLineDiscountData) {
        return getSavedLineDiscountPercent($item);
    }

    $orderDiscountPercent = percentValue($orderDiscountPercent);
    $maxDiscount = resolveItemMaxDiscountPercent($item);

    return $maxDiscount > 0
        ? min($orderDiscountPercent, $maxDiscount)
        : $orderDiscountPercent;
}

function calculateOrderViewTotals(array $items, float $discountPercent, float $taxPercent, bool $useSavedLineDiscountData = false): array
{
    $discountPercent = percentValue($discountPercent);
    $taxPercent = max(0, (float)$taxPercent);

    $subTotal = 0.00;
    $totalQty = 0.00;
    $discountAmount = 0.00;

    foreach ($items as $item) {
        $qty = max(0, (float)($item['qty'] ?? 0));
        $price = max(0, (float)($item['price'] ?? 0));
        $lineTotal = round($qty * $price, 2);

        $appliedDiscountPercent = resolveOrderViewLineDiscountPercent(
            $item,
            $discountPercent,
            $useSavedLineDiscountData
        );

        $lineDiscount = $useSavedLineDiscountData
            ? getSavedLineDiscountAmount($item, $lineTotal, $appliedDiscountPercent)
            : round($lineTotal * ($appliedDiscountPercent / 100), 2);

        $subTotal += $lineTotal;
        $totalQty += $qty;
        $discountAmount += $lineDiscount;
    }

    $taxableAmount = max(0, $subTotal - $discountAmount);
    $taxAmount = $taxableAmount * ($taxPercent / 100);
    $grandTotal = $taxableAmount + $taxAmount;

    return [
        'subtotal' => round($subTotal, 2),
        'total_qty' => round($totalQty, 3),
        'discount_amount' => round($discountAmount, 2),
        'tax_amount' => round($taxAmount, 2),
        'grand_total' => round($grandTotal, 2),
    ];
}

function normalizeWhatsAppPhone($phone): string
{
    $phone = preg_replace('/\D+/', '', (string)$phone);

    if ($phone === '') {
        return '';
    }

    if (strpos($phone, '00') === 0) {
        $phone = substr($phone, 2);
    }

    if (strpos($phone, '92') === 0) {
        return $phone;
    }

    if (strpos($phone, '0') === 0) {
        return '92' . substr($phone, 1);
    }

    if (strlen($phone) === 10) {
        return '92' . $phone;
    }

    return $phone;
}

function currentInvoiceUrl(int $saleId): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';

    if ($host === '') {
        return 'thermal.php?sale_id=' . $saleId;
    }

    return $scheme . '://' . $host . '/onerp/app/public/app/views/thermal.php?sale_id=' . $saleId;
}

function buildOrderWhatsAppUrl(string $phone, string $message): string
{
    $phone = normalizeWhatsAppPhone($phone);
    $encodedMessage = urlencode($message);

    if ($phone !== '') {
        return 'https://wa.me/' . $phone . '?text=' . $encodedMessage;
    }

    return 'https://wa.me/?text=' . $encodedMessage;
}

function formatOrderQtyValue(float $value): string
{
    $formatted = number_format($value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

$invoice_no = $sale['invoice_no'] ?? generateInvoiceNumber($client_id, $business_name, $conn);

$initialDiscountPercent = (float)($sale['discount'] ?? $sale['discount_percent'] ?? 0);
$initialTaxPercent = (float)($sale['tax'] ?? $sale['tax_percent'] ?? 0);
$useSavedLineDiscountData = saleItemsHaveSavedDiscountData($items);
$initialTotals = calculateOrderViewTotals($items, $initialDiscountPercent, $initialTaxPercent, $useSavedLineDiscountData);
$customer_phone = trim((string)($sale['customer_phone'] ?? ''));
$invoice_url = currentInvoiceUrl($sale_id);
$whatsappMessage = "*{$business_name}*\n";
$whatsappMessage .= "Invoice: {$invoice_no}\n";
$whatsappMessage .= "Customer: " . (($sale['customer_name'] ?? '') !== '' ? $sale['customer_name'] : 'Walk-in Customer') . "\n";
$whatsappMessage .= "Total: Rs " . number_format((float)$initialTotals['grand_total'], 2) . "\n";
$whatsappMessage .= "View / Print Invoice: {$invoice_url}";
$order_whatsapp_url = buildOrderWhatsAppUrl($customer_phone, $whatsappMessage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Order View</title>
    <?php include('partials/head.php'); ?>
   <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
html,
body{
    margin:0;
    background:#f4f6f9;
    height:100%;
    overflow:hidden;
    font-size:14px;
}

.container-scroll{
    display:flex;
    height:100vh;
    overflow:hidden;
}

.sidebar{
    width:220px;
    flex-shrink:0;
    overflow:auto;
}

.main-panel{
    flex:1;
    display:flex;
    flex-direction:column;
    min-width:0;
    height:100vh;
    overflow:hidden;
}

.topbar{
    height:58px;
    min-height:58px;
    border-bottom:1px solid #ddd;
    display:flex;
    align-items:center;
    padding:0 18px;
    background:#fff;
    flex-shrink:0;
}

.content-wrapper{
    flex:1;
    overflow-y:auto;
    overflow-x:hidden;
    padding:8px 10px 18px 10px;
    min-height:0;
}

/* Header compact */
.order-header{
    background:#fff;
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:8px;
}

.order-header h4{
    font-size:22px;
    margin:0 0 6px 0;
    font-weight:700;
}

.order-header div{
    line-height:1.45;
    font-size:15px;
}

/* Table compact */
.table{
    margin-bottom:8px;
    font-size:13px;
}

.table th{
    padding:8px 10px !important;
    vertical-align:middle;
    font-size:13px;
}

.table td{
    padding:7px 10px !important;
    vertical-align:middle;
}

.table input{
    width:90px;
    height:34px;
    padding:5px 10px;
    font-size:14px;
}

.table strong{
    font-size:14px;
}

.tier-select{
    min-width:170px;
    height:34px;
    font-size:13px;
    padding:4px 12px;
}

.tier-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    background:#fff7db;
    color:#7a5200;
    border:1px solid #f3d37a;
    border-radius:999px;
    padding:3px 8px;
    font-size:11px;
    font-weight:700;
}

.unit-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    background:#eef2ff;
    color:#3730a3;
    border:1px solid #c7d2fe;
    border-radius:999px;
    padding:3px 8px;
    font-size:11px;
    font-weight:800;
    margin-top:4px;
}

.unit-help{
    font-size:11px;
    color:#4b5563;
    margin-top:2px;
    line-height:1.25;
}

.unit-base-qty{
    font-weight:800;
}

.price-help{
    font-size:11px;
    color:#6b7280;
    margin-top:2px;
    line-height:1.2;
}

.whatsapp-invoice-link{
    color:#198754;
    font-weight:800;
    text-decoration:none;
}

.whatsapp-invoice-link:hover{
    text-decoration:underline;
}

/* Discount / Tax compact */
.row.g-2.mt-2{
    margin-top:6px !important;
}

.row.g-2.mt-2 label{
    font-size:14px;
    margin-bottom:3px;
}

.row.g-2.mt-2 .form-control{
    height:34px;
    padding:5px 10px;
    font-size:14px;
}

/* Summary compact and aligned */
.summary-box{
    background:#fff;
    padding:10px 16px !important;
    border-radius:8px;
    margin-top:8px !important;
    text-align:right;
    height:auto !important;
    min-height:0 !important;
    border:1px solid #eef0f4;
}

.summary-box div{
    line-height:1.5;
    font-size:15px;
}

.summary-box b{
    display:inline-block;
    width:135px;
    font-weight:700;
}

/* Buttons normal flow - no overlay */
.order-actions{
    margin-top:8px !important;
    padding:8px 0 0 0;
    display:flex !important;
    gap:8px !important;
    align-items:center;
    position:static !important;
    bottom:auto !important;
    z-index:auto !important;
    background:transparent !important;
    border-top:none !important;
    box-shadow:none !important;
}

.order-actions .btn{
    padding:9px 18px;
    border-radius:7px;
    font-weight:600;
    min-width:145px;
    height:42px;
    font-size:14px;
}

/* Make page fit better on laptop screens */
@media (max-height: 760px){
    .topbar{
        height:54px;
        min-height:54px;
    }

    .content-wrapper{
        padding:7px 10px 12px 10px;
    }

    .order-header{
        padding:9px 14px;
        margin-bottom:7px;
    }

    .order-header h4{
        font-size:20px;
        margin-bottom:4px;
    }

    .order-header div{
        font-size:14px;
        line-height:1.35;
    }

    .table th{
        padding:7px 9px !important;
    }

    .table td{
        padding:6px 9px !important;
    }

    .table input,
    .tier-select,
    .row.g-2.mt-2 .form-control{
        height:32px;
        font-size:13px;
    }

    .summary-box{
        padding:8px 14px !important;
        margin-top:6px !important;
    }

    .summary-box div{
        font-size:14px;
        line-height:1.4;
    }

    .order-actions .btn{
        height:39px;
        padding:8px 16px;
    }
}

/* ================================
   MOBILE RESPONSIVE ORDER VIEW
   ================================ */
@media (max-width: 768px){

    html,
    body{
        height:100%;
        overflow:hidden;
        background:#f4f6f9;
        font-size:13px;
    }

    .container-scroll{
        height:100vh;
        overflow:hidden;
        display:block;
    }

    .sidebar{
        display:none !important;
        width:0 !important;
    }

    .main-panel{
        width:100%;
        height:100vh;
        display:flex;
        flex-direction:column;
        overflow:hidden;
    }

    .topbar{
        height:54px;
        min-height:54px;
        padding:0 10px;
        position:sticky;
        top:0;
        z-index:100;
        background:#fff;
    }

    .content-wrapper{
        flex:1;
        overflow-y:auto;
        overflow-x:hidden;
        padding:8px 8px 115px 8px;
        min-height:0;
    }

    /* Header mobile compact */
    .order-header{
        padding:12px;
        border-radius:12px;
        margin-bottom:10px;
        box-shadow:0 2px 8px rgba(0,0,0,0.05);
    }

    .order-header h4{
        font-size:17px;
        line-height:1.3;
        margin-bottom:6px;
    }

    .order-header div{
        font-size:12px;
        line-height:1.45;
        word-break:break-word;
    }

    /* Convert table into mobile cards */
    .table{
        border:0 !important;
        margin-bottom:10px;
        background:transparent;
    }

    .table thead{
        display:none;
    }

    .table tbody,
    .table tr,
    .table td{
        display:block;
        width:100%;
    }

    .table tr{
        background:#fff;
        margin-bottom:10px;
        border:1px solid #e5e7eb;
        border-radius:12px;
        padding:10px;
        box-shadow:0 2px 8px rgba(0,0,0,0.05);
    }

    .table td{
        border:0 !important;
        padding:7px 0 !important;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:10px;
        font-size:13px;
    }

    .table td::before{
        content:"";
        font-weight:700;
        color:#374151;
        flex:0 0 95px;
        font-size:12px;
    }

    .table td:nth-child(1)::before{
        content:"Product";
    }

    .table td:nth-child(2)::before{
        content:"Price Type";
    }

    .table td:nth-child(3)::before{
        content:"Qty";
    }

    .table td:nth-child(4)::before{
        content:"Price";
    }

    .table td:nth-child(5)::before{
        content:"Total";
    }

.table td:nth-child(5)::before{
    content:"Discount %";
}

.table td:nth-child(6)::before{
    content:"Discount Rs";
}

.table td:nth-child(7)::before{
    content:"Net Total";
}
    .table td:first-child{
        align-items:flex-start;
    }

    .table td:first-child strong{
        font-size:14px;
        line-height:1.3;
        text-align:right;
        display:block;
        max-width:190px;
        word-break:break-word;
    }

    .price-help,
    .unit-help{
        font-size:10px;
        text-align:right;
        margin-top:3px;
    }

    .unit-badge{
        margin-left:auto;
    }

    .tier-select{
        width:100%;
        min-width:0;
        max-width:185px;
        height:34px;
        font-size:12px;
        padding:4px 8px;
    }

    .table input{
        width:110px;
        height:34px;
        font-size:13px;
        text-align:right;
    }

    .lineTotal{
        font-weight:700;
        color:#111827;
    }

    /* Discount / Tax mobile */
    .row.g-2.mt-2{
        margin-top:4px !important;
        background:#fff;
        border-radius:12px;
        padding:10px;
        border:1px solid #e5e7eb;
        box-shadow:0 2px 8px rgba(0,0,0,0.05);
    }

    .row.g-2.mt-2 .col-md-3{
        width:100%;
        margin-bottom:8px;
    }

    .row.g-2.mt-2 .col-md-3:last-child{
        margin-bottom:0;
    }

    .row.g-2.mt-2 label{
        font-size:12px;
        font-weight:700;
        margin-bottom:4px;
        color:#374151;
    }

    .row.g-2.mt-2 .form-control{
        height:36px;
        font-size:13px;
    }

    /* Summary box mobile */
    .summary-box{
        text-align:left !important;
        padding:12px !important;
        border-radius:12px;
        margin-top:10px !important;
        border:1px solid #e5e7eb;
        box-shadow:0 2px 8px rgba(0,0,0,0.05);
    }

    .summary-box div{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:10px;
        font-size:13px;
        line-height:1.7;
        border-bottom:1px dashed #e5e7eb;
        padding:3px 0;
    }

    .summary-box div:last-child{
        border-bottom:0;
        font-size:15px;
        font-weight:800;
        color:#198754;
        padding-top:6px;
    }

    .summary-box b{
        width:auto !important;
        min-width:auto;
        font-weight:700;
        color:#374151;
    }

   

    /* Mobile bottom buttons - visible inside scroll area */
@media (max-width: 768px){

    .content-wrapper{
        padding-bottom:20px !important;
        overflow-y:auto !important;
        overflow-x:hidden !important;
    }

    .order-actions{
        position:sticky !important;
        bottom:0 !important;
        left:0 !important;
        right:0 !important;
        z-index:9999 !important;
        background:#f4f6f9 !important;
        border-top:1px solid #dbe1ea !important;
        box-shadow:0 -4px 14px rgba(0,0,0,0.12) !important;
        padding:10px 0 8px 0 !important;
        margin-top:12px !important;
        display:flex !important;
        gap:8px !important;
        flex-wrap:nowrap !important;
    }

    .order-actions .btn{
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        width:50% !important;
        min-width:0 !important;
        height:44px !important;
        padding:8px 6px !important;
        border-radius:10px !important;
        font-size:12px !important;
        font-weight:700 !important;
        line-height:1.1 !important;
        white-space:normal !important;
    }
}
}

/* Extra small mobile */
@media (max-width: 420px){

    .content-wrapper{
        padding-left:6px;
        padding-right:6px;
        padding-bottom:118px;
    }

    .order-header h4{
        font-size:16px;
    }

    .table tr{
        padding:9px;
    }

    .table td{
        gap:6px;
    }

    .table td::before{
        flex:0 0 82px;
        font-size:11px;
    }

    .table td:first-child strong{
        max-width:165px;
        font-size:13px;
    }

    .tier-select{
        max-width:165px;
        font-size:11px;
    }

    .table input{
        width:95px;
    }

    .order-actions .btn{
        font-size:11px;
        height:43px;
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

            <div class="order-header">
                <h4>Customer: <?= esc($sale['customer_name'] ?: "Walk-in Customer") ?></h4>
                <div>Address: <?= esc($customerAddress) ?></div>
                <div>
                    Invoice #:
                    <a class="whatsapp-invoice-link"
                       href="<?= esc($order_whatsapp_url) ?>"
                       target="_blank"
                       rel="noopener"
                       title="Send invoice on WhatsApp">
                        <?= esc($invoice_no) ?>
                    </a>
                </div>
                <div>Customer Phone: <?= $customer_phone !== '' ? esc($customer_phone) : 'Not Available' ?></div>
                <div>Date: <?= date('d-m-Y', strtotime($sale['created_at'] ?? date('Y-m-d'))) ?></div>
                <div>Customer Type: <?= esc($saleCustomerType ?: "Walk-in") ?></div>
            </div>

            <table class="table table-bordered table-striped">
                <thead class="table-dark">
                    <tr>
                        <th>Product</th>
                        <th>Selected Price</th>
                        <th>Qty</th>
                       <th>Price</th>
<th>Discount %</th>
<th>Discount Rs</th>
<th>Net Total</th>
                    </tr>
                </thead>
                <tbody id="orderItems">
                    <?php
                    $totalQty=0; $subTotal=0;
                    foreach($items as $rowIndex => $item):
                        $itemId = (int)($item['item_id'] ?? 0);
                        $saleItemId = (int)($item['sale_item_id'] ?? 0);
                        $qty = (float)($item['qty'] ?? 0);
                        $price = (float)($item['price'] ?? 0);

                        /*
                        |--------------------------------------------------------------------------
                        | Pharmacy unit metadata
                        |--------------------------------------------------------------------------
                        | sale_items me unit_name / unit_conversion_qty / base_qty save hota hai.
                        | Yahan sirf order view par display + update payload me preserve kar rahe hain.
                        */
                        $unitId = (int)($item['unit_id'] ?? 0);
                        $unitName = trim((string)($item['unit_name'] ?? ''));
                        $unitConversionQty = (float)($item['unit_conversion_qty'] ?? 1);
                        if ($unitConversionQty <= 0) {
                            $unitConversionQty = 1;
                        }
                        $baseQty = isset($item['base_qty']) && (float)$item['base_qty'] > 0
                            ? (float)$item['base_qty']
                            : round($qty * $unitConversionQty, 2);
                        $hasUnitMeta = ($unitName !== '' || $unitId > 0 || abs($unitConversionQty - 1) > 0.00001);

                       $lineTotal = round($qty * $price, 2);

$lineDiscountPercent = resolveOrderViewLineDiscountPercent(
    $item,
    $initialDiscountPercent,
    $useSavedLineDiscountData
);

$lineDiscountAmount = $useSavedLineDiscountData
    ? getSavedLineDiscountAmount($item, $lineTotal, $lineDiscountPercent)
    : round($lineTotal * ($lineDiscountPercent / 100), 2);

$lineNetTotal = round(max(0, $lineTotal - $lineDiscountAmount), 2);

$totalQty += $qty;
$subTotal += $lineTotal;

                        $tierType = strtolower(trim((string)($item['price_tier_type'] ?? 'default')));
                        $tierNo = (int)($item['price_tier_no'] ?? 0);
                        $tierLabel = (string)($item['price_tier_label'] ?? '');
                        if($tierLabel === '' && $tierType === 'wholesale' && $tierNo > 0){
                            $tierLabel = 'Wholesale ' . $tierNo;
                        }

                        $productDefaultPrice = isset($item['default_product_price']) && $item['default_product_price'] !== null
                            ? (float)$item['default_product_price']
                            : $price;

                        $wholesale1 = (float)($item['product_wholesale_1'] ?? 0);
                        $wholesale2 = (float)($item['product_wholesale_2'] ?? 0);
                        $maxDiscountPercent = resolveItemMaxDiscountPercent($item);
                        $hasMaxDiscount = $maxDiscountPercent > 0 ? 1 : 0;

                        $tiers = [];
                        if($wholesale1 > 0){
                            $tiers[] = [
                                'tier_no' => 1,
                                'label'   => 'Wholesale 1',
                                'price'   => $wholesale1,
                            ];
                        }
                        if($wholesale2 > 0){
                            $tiers[] = [
                                'tier_no' => 2,
                                'label'   => 'Wholesale 2',
                                'price'   => $wholesale2,
                            ];
                        }
                    ?>
                    <tr
                        data-id="<?= $itemId ?>"
                        data-sale-item-id="<?= $saleItemId ?>"
                        data-row-index="<?= (int)$rowIndex ?>"
                        data-tier-type="<?= esc($tierType ?: 'default') ?>"
                        data-tier-no="<?= (int)$tierNo ?>"
                        data-tier-label="<?= esc($tierLabel) ?>"
                        data-has-max-discount="<?= (int)$hasMaxDiscount ?>"
                        data-max-discount-percent="<?= esc($maxDiscountPercent) ?>"
                        data-line-discount-percent="<?= esc($lineDiscountPercent) ?>"
data-line-discount-amount="<?= esc($lineDiscountAmount) ?>"
                        data-unit-id="<?= (int)$unitId ?>"
                        data-unit-name="<?= esc($unitName) ?>"
                        data-unit-conversion-qty="<?= esc($unitConversionQty) ?>"
                        data-base-qty="<?= esc($baseQty) ?>"
                    >
                        <td>
                            <strong><?= esc($item['product_name'] ?? 'Unknown Product') ?></strong>
                            <?php if($hasUnitMeta): ?>
                                <div class="unit-badge">
                                    <i class="bi bi-capsule-pill"></i>
                                    Unit: <?= esc($unitName !== '' ? $unitName : 'Default') ?>
                                </div>
                                <div class="unit-help">
                                    1 <?= esc($unitName !== '' ? $unitName : 'Unit') ?> = <?= esc(formatOrderQtyValue($unitConversionQty)) ?> base unit
                                    / Base Qty: <span class="unit-base-qty"><?= esc(formatOrderQtyValue($baseQty)) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if($tierType === 'wholesale' && $tierNo > 0): ?>
                                <div class="price-help">Saved as: <?= esc($tierLabel ?: ('Wholesale '.$tierNo)) ?></div>
                            <?php endif; ?>
                            <?php if($hasMaxDiscount === 1): ?>
                                <div class="price-help">Max Discount: <?= esc(rtrim(rtrim(number_format($maxDiscountPercent, 2), '0'), '.')) ?>%</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if(($isWholesalerOrder || $tierType === 'wholesale') && $itemId > 0 && !empty($tiers)): ?>
                                <select class="form-select tier-select">
                                    <option
                                        value="default"
                                        data-price="<?= esc($productDefaultPrice) ?>"
                                        data-tier-type="default"
                                        data-tier-no="0"
                                        data-tier-label="Default"
                                        <?= ($tierType !== 'wholesale') ? 'selected' : '' ?>
                                    >Default - Rs <?= number_format($productDefaultPrice, 2) ?></option>

                                    <?php foreach($tiers as $tier): ?>
                                        <?php
                                            $selected = ($tierType === 'wholesale' && $tierNo === (int)$tier['tier_no']);
                                        ?>
                                        <option
                                            value="wholesale_<?= (int)$tier['tier_no'] ?>"
                                            data-price="<?= esc($tier['price']) ?>"
                                            data-tier-type="wholesale"
                                            data-tier-no="<?= (int)$tier['tier_no'] ?>"
                                            data-tier-label="<?= esc($tier['label']) ?>"
                                            <?= $selected ? 'selected' : '' ?>
                                        ><?= esc($tier['label']) ?> - Rs <?= number_format((float)$tier['price'], 2) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="price-help">Changing this will update item price and tier label.</div>
                            <?php else: ?>
                                <?php if($tierType === 'wholesale' && $tierNo > 0): ?>
                                    <span class="tier-badge"><?= esc($tierLabel ?: ('Wholesale '.$tierNo)) ?></span>
                                <?php elseif($hasUnitMeta): ?>
                                    <span class="unit-badge"><?= esc($unitName !== '' ? $unitName : 'Unit') ?> Price</span>
                                    <div class="price-help">Rs <?= number_format($price, 2) ?> per <?= esc($unitName !== '' ? $unitName : 'unit') ?></div>
                                <?php else: ?>
                                    <span class="text-muted">Default</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><input type="number" value="<?= esc($qty) ?>" min="1" class="form-control qty"></td>
                        <td><input type="number" value="<?= esc($price) ?>" min="0" step="0.01" class="form-control price"></td>
                   <td>
    <input
        type="number"
        value="<?= esc(number_format($lineDiscountPercent, 2, '.', '')) ?>"
        min="0"
        max="100"
        step="0.01"
        class="form-control line-discount"
    >
</td>
<td class="lineDiscountAmount"><?= number_format($lineDiscountAmount, 2) ?></td>
<td class="lineTotal"><?= number_format($lineNetTotal, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="row g-2 mt-2">
                <div class="col-md-3">
                    <label>Final Discount (%)</label>
                    <input type="number" id="discount" class="form-control" value="<?= esc($sale['discount'] ?? 0) ?>">
                </div>
                <div class="col-md-3">
                    <label>Tax (%)</label>
                    <input type="number" id="tax" class="form-control" value="<?= esc($sale['tax'] ?? 0) ?>">
                </div>
            </div>

            <div class="summary-box mt-3">
                <?php
                $savedDiscountPercent = $initialDiscountPercent;
                $savedTaxPercent = $initialTaxPercent;
                $savedTotals = $initialTotals;
                $savedDiscountAmount = (float)$savedTotals['discount_amount'];
                $savedTaxAmount = (float)$savedTotals['tax_amount'];
                $savedGrandTotal = (float)$savedTotals['grand_total'];
                ?>
                <div><b>Total Quantity:</b> <span id="totalQty"><?= esc($totalQty) ?></span></div>
                <div><b>Total Rs:</b> Rs <span id="subTotal"><?= number_format($subTotal,2) ?></span></div>
                <div><b>Tax:</b> <span id="showTax"><?= number_format($savedTaxPercent,2) ?>%</span></div>
                <div><b>Discount:</b> <span id="showDiscount"><?= number_format($savedDiscountPercent,2) ?>% / Rs <?= number_format($savedDiscountAmount,2) ?></span></div>
                <div><b>Grand Total:</b> Rs <span id="grandTotal"><?= number_format($savedGrandTotal,2) ?></span></div>
            </div>

            <div class="order-actions d-flex gap-2">
                <button class="btn btn-primary" onclick="updateOrder(<?= $sale_id ?>)">Update & Recalculate</button>
                <button class="btn btn-success" onclick="printInvoice(<?= $sale_id ?>)">Print Invoice</button>
                <a class="btn btn-success"
                   href="<?= esc($order_whatsapp_url) ?>"
                   target="_blank"
                   rel="noopener">
                    Send WhatsApp
                </a>
            </div>

        </div>
    </div>
</div>

<script>
    
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
function toPercent(value){
    value = parseFloat(value) || 0;
    if(value < 0) return 0;
    if(value > 100) return 100;
    return value;
}

function recalcTotals(){
    let totalQty = 0;
    let subTotal = 0;
    let discountAmount = 0;
    let hasError = false;

    const discount = toPercent(document.getElementById('discount').value);
    const tax = Math.max(0, parseFloat(document.getElementById('tax').value) || 0);

    document.querySelectorAll('#orderItems tr').forEach(tr=>{
        const qtyInput = tr.querySelector('.qty');
        const priceInput = tr.querySelector('.price');

        const qty = parseFloat(qtyInput.value);
        const price = parseFloat(priceInput.value);

        if(qty <= 0 || price < 0 || isNaN(qty) || isNaN(price)){
            qtyInput.style.border='1px solid red';
            priceInput.style.border='1px solid red';
            hasError=true;
        }else{
            qtyInput.style.border='';
            priceInput.style.border='';
        }

        const safeQty = Math.max(0, qty || 0);
        const safePrice = Math.max(0, price || 0);
        const total = safeQty * safePrice;

        const unitConversionQty = Math.max(1, parseFloat(tr.dataset.unitConversionQty || '1') || 1);
        const baseQty = safeQty * unitConversionQty;
        tr.dataset.baseQty = baseQty.toFixed(2);

        const baseQtyHolder = tr.querySelector('.unit-base-qty');
        if (baseQtyHolder) {
            baseQtyHolder.innerText = Number(baseQty.toFixed(2)).toString();
        }

       const lineDiscountInput = tr.querySelector('.line-discount');
let appliedDiscountPercent = lineDiscountInput
    ? toPercent(lineDiscountInput.value)
    : discount;

if (lineDiscountInput) {
    lineDiscountInput.value = appliedDiscountPercent.toFixed(2);
}

const lineDiscount = total * (appliedDiscountPercent / 100);
const lineNetTotal = Math.max(0, total - lineDiscount);

const lineDiscountAmountCell = tr.querySelector('.lineDiscountAmount');
if (lineDiscountAmountCell) {
    lineDiscountAmountCell.innerText = lineDiscount.toFixed(2);
}

tr.dataset.lineDiscountPercent = appliedDiscountPercent.toFixed(2);
tr.dataset.lineDiscountAmount = lineDiscount.toFixed(2);

tr.querySelector('.lineTotal').innerText = lineNetTotal.toFixed(2);

totalQty += safeQty;
subTotal += total;
discountAmount += lineDiscount;
    });

    const taxableAmount = Math.max(0, subTotal - discountAmount);
    const taxAmount = taxableAmount * (tax / 100);
    const grandTotal = taxableAmount + taxAmount;

    window.orderViewTotals = {
        subtotal: subTotal,
        discountAmount: discountAmount,
        taxAmount: taxAmount,
        grandTotal: grandTotal,
        totalQty: totalQty
    };

    document.getElementById('totalQty').innerText = totalQty;
    document.getElementById('subTotal').innerText = subTotal.toFixed(2);
    document.getElementById('grandTotal').innerText = grandTotal.toFixed(2);
document.getElementById('showDiscount').innerText = "Item-wise / Rs " + discountAmount.toFixed(2);
    document.getElementById('showTax').innerText = tax.toFixed(2) + "%";

    return !hasError;
}
function applyOrderDiscountToLines(){
    const discount = toPercent(document.getElementById('discount').value);

    document.querySelectorAll('#orderItems tr').forEach(tr=>{
        const input = tr.querySelector('.line-discount');
        if (!input) return;

        const hasMaxDiscount = parseInt(tr.dataset.hasMaxDiscount || '0', 10) === 1;
        const maxDiscountPercent = toPercent(tr.dataset.maxDiscountPercent || 0);

        const appliedDiscountPercent = (hasMaxDiscount && maxDiscountPercent > 0)
            ? Math.min(discount, maxDiscountPercent)
            : discount;

        input.value = appliedDiscountPercent.toFixed(2);
        tr.dataset.lineDiscountPercent = appliedDiscountPercent.toFixed(2);
    });

    recalcTotals();
}

function bindWholesalerTierSelects(){
    document.querySelectorAll('.tier-select').forEach(select => {
        select.addEventListener('change', function(){
            const tr = this.closest('tr');
            const option = this.options[this.selectedIndex];
            const selectedPrice = parseFloat(option.dataset.price || 0);

            tr.dataset.tierType = option.dataset.tierType || 'default';
            tr.dataset.tierNo = option.dataset.tierNo || '0';
            tr.dataset.tierLabel = option.dataset.tierLabel || 'Default';

            const priceInput = tr.querySelector('.price');
            if(priceInput && !isNaN(selectedPrice)){
                priceInput.value = selectedPrice.toFixed(2);
            }

            recalcTotals();
        });
    });
}

function updateOrder(sale_id){
    if(!recalcTotals()){
        alert('Please correct quantity and price fields highlighted in red.');
        return;
    }

    let items=[];
    document.querySelectorAll('#orderItems tr').forEach(tr=>{
        const id = tr.dataset.id;
        const sale_item_id = tr.dataset.saleItemId || '0';
        const qty = parseFloat(tr.querySelector('.qty').value);
        const price = parseFloat(tr.querySelector('.price').value);
        const unitConversionQty = Math.max(1, parseFloat(tr.dataset.unitConversionQty || '1') || 1);
        const baseQty = Math.max(0, qty || 0) * unitConversionQty;
    const lineDiscountPercent = toPercent(tr.querySelector('.line-discount')?.value || 0);
const lineSubtotal = Math.max(0, qty || 0) * Math.max(0, price || 0);
const lineDiscountAmount = lineSubtotal * (lineDiscountPercent / 100);
const lineTotalAfterDiscount = Math.max(0, lineSubtotal - lineDiscountAmount);

items.push({
    id,
    sale_item_id,
    row_index: tr.dataset.rowIndex || '0',
    qty,
    price,
    discount_percent: lineDiscountPercent,
    discount_amount: lineDiscountAmount,
    line_subtotal: lineSubtotal,
    line_total_after_discount: lineTotalAfterDiscount,
    price_tier_type: tr.dataset.tierType || 'default',
    price_tier_no: parseInt(tr.dataset.tierNo || 0, 10),
    price_tier_label: tr.dataset.tierLabel || '',
    unit_id: parseInt(tr.dataset.unitId || '0', 10),
    unit_name: tr.dataset.unitName || '',
    unit_conversion_qty: unitConversionQty,
    base_qty: baseQty,
    has_max_discount: parseInt(tr.dataset.hasMaxDiscount || '0', 10),
    max_discount_percent: parseFloat(tr.dataset.maxDiscountPercent || 0)
});
    });

    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const tax = parseFloat(document.getElementById('tax').value) || 0;

    const calculatedTotals = window.orderViewTotals || {};

    fetch('ajax_update_order.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            sale_id,
            items,
            discount,
            tax,
            subtotal: calculatedTotals.subtotal || 0,
            discount_amount: calculatedTotals.discountAmount || 0,
            tax_amount: calculatedTotals.taxAmount || 0,
            grand_total: calculatedTotals.grandTotal || 0
        })
    }).then(r=>r.json()).then(res=>{
        if(res.status=='success'){
            alert('Order updated successfully!');
            location.reload();
        }else{
            alert('Error: '+res.message);
        }
    }).catch(err=>{
        alert('Unexpected error: '+err);
    });
}

function printInvoice(sale_id){
    window.open('thermal.php?sale_id=' + sale_id, '_blank');
}

document.querySelectorAll('#orderItems .qty, #orderItems .price, #orderItems .line-discount, #tax').forEach(el=>{
    el.addEventListener('input', recalcTotals);
});

const orderDiscountInput = document.getElementById('discount');
if (orderDiscountInput) {
    orderDiscountInput.addEventListener('input', applyOrderDiscountToLines);
}

bindWholesalerTierSelects();
recalcTotals();
</script>
</body>
</html>
