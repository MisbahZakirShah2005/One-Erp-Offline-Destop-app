<?php
session_start();

if (file_exists(__DIR__ . '/partials/performance.php')) {
    include(__DIR__ . '/partials/performance.php');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) $conn = $mysqli;
    elseif (isset($db) && $db instanceof mysqli) $conn = $db;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
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

/* ================= HELPERS ================= */
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($amount): string { return 'Rs. ' . number_format((float)$amount, 2); }
function money_short($amount): string { return 'Rs ' . number_format((float)$amount, 2); }
function qty_fmt($qty): string {
    $qty = (float)$qty;
    return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
}
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
function row_value(array $row, array $keys, $default = '') {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') return $row[$key];
    }
    return $default;
}
function bind_params_ref(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $refs = [$types];
    foreach ($params as $k => &$v) $refs[] = &$v;
    call_user_func_array([$stmt, 'bind_param'], $refs);
}
function fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') bind_params_ref($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: [];
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
function setting_value(mysqli $conn, int $client_id, int $branch_id, array $keys, $default = '') {
    if (!table_exists($conn, 'restaurant_settings') || empty($keys)) return $default;
    $in = implode(',', array_fill(0, count($keys), '?'));
    $types = 'ii' . str_repeat('s', count($keys));
    $params = array_merge([$client_id, $branch_id], $keys);
    $sql = "SELECT setting_value FROM restaurant_settings WHERE client_id=? AND branch_id IN (0,?) AND setting_key IN ($in) ORDER BY branch_id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $default;
    return $row['setting_value'];
}
function log_activity(mysqli $conn, int $client_id, int $branch_id, int $session_id, ?int $table_id, string $type, string $details, int $user_id): void {
    if (!table_exists($conn, 'restaurant_activity_logs')) return;
    $stmt = $conn->prepare('INSERT INTO restaurant_activity_logs (client_id, branch_id, session_id, table_id, action_type, details, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $stmt->bind_param('iiiissi', $client_id, $branch_id, $session_id, $table_id, $type, $details, $user_id);
    $stmt->execute();
    $stmt->close();
}
function status_label(string $status): string {
    $status = strtolower(trim($status));
    $map = [
        'open' => 'Open',
        'kot_sent' => 'KOT Sent',
        'bill_printed' => 'Bill Printed',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
        'void' => 'Void',
    ];
    return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
}
function payment_method_label($method): string {
    $method = strtolower(trim((string)$method));
    $map = [
        'cash' => 'Cash',
        'card' => 'Bank Card / POS',
        'easypaisa' => 'Easypaisa',
        'jazzcash' => 'JazzCash',
        'raast' => 'Raast QR / ID',
        'bank_transfer' => 'Bank Transfer / IBFT',
        'upi' => 'UPI / QR',
        'split' => 'Split Payment',
    ];
    return $map[$method] ?? ucwords(str_replace('_', ' ', $method));
}
function payment_split_rows(?string $json): array {
    if (!$json) return [];
    $rows = json_decode($json, true);
    return is_array($rows) ? $rows : [];
}

/* ================= DATA LOADERS ================= */
function load_session(mysqli $conn, int $session_id, int $client_id): ?array {
    $hasTables = table_exists($conn, 'restaurant_tables');
    $tableSelect = $hasTables ? "t.table_no, t.table_name, t.floor_name, t.status AS table_status" : "NULL AS table_no, NULL AS table_name, NULL AS floor_name, NULL AS table_status";
    $tableJoin = $hasTables ? "LEFT JOIN restaurant_tables t ON t.id=s.table_id" : "";

    $hasBranches = table_exists($conn, 'branches');
    $branchSelect = $hasBranches ? "b.name AS branch_name" : "NULL AS branch_name";
    $branchJoin = $hasBranches ? "LEFT JOIN branches b ON b.id=s.branch_id" : "";

    $hasWaiters = table_exists($conn, 'restaurant_waiters');
    $waiterSelect = $hasWaiters ? "w.name AS waiter_name" : "NULL AS waiter_name";
    $waiterJoin = $hasWaiters ? "LEFT JOIN restaurant_waiters w ON w.id=s.waiter_id" : "";

    $hasCustomers = table_exists($conn, 'customers');
    if ($hasCustomers) {
        $customerSelect = "COALESCE(NULLIF(s.customer_name,''), c.name, 'Walk-in') AS bill_customer_name,
                           COALESCE(NULLIF(s.customer_phone,''), c.phone, '') AS bill_customer_phone,
                           c.type AS customer_type";
        $customerJoin = "LEFT JOIN customers c ON c.id=s.customer_id";
    } else {
        $customerSelect = "COALESCE(NULLIF(s.customer_name,''), 'Walk-in') AS bill_customer_name,
                           COALESCE(NULLIF(s.customer_phone,''), '') AS bill_customer_phone,
                           NULL AS customer_type";
        $customerJoin = "";
    }

    $hasUsers = table_exists($conn, 'users');
    $riderSelect = ($hasUsers && column_exists($conn, 'users', 'name') && column_exists($conn, 'restaurant_table_sessions', 'rider_id')) ? "ru.name AS rider_name" : "NULL AS rider_name";
    $riderJoin = ($hasUsers && column_exists($conn, 'users', 'name') && column_exists($conn, 'restaurant_table_sessions', 'rider_id')) ? "LEFT JOIN users ru ON ru.id=s.rider_id" : "";

    $sql = "SELECT s.*, $tableSelect, $branchSelect, $waiterSelect, $customerSelect, $riderSelect
            FROM restaurant_table_sessions s
            $tableJoin
            $branchJoin
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
function load_payment(mysqli $conn, int $session_id, int $client_id, string $receipt_no = ''): ?array {
    if (!table_exists($conn, 'restaurant_payments')) return null;
    if ($receipt_no !== '') {
        $stmt = $conn->prepare("SELECT * FROM restaurant_payments WHERE session_id=? AND client_id=? AND receipt_no=? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('iis', $session_id, $client_id, $receipt_no);
    } else {
        $stmt = $conn->prepare("SELECT * FROM restaurant_payments WHERE session_id=? AND client_id=? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('ii', $session_id, $client_id);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
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

    if (column_exists($conn, 'restaurant_table_sessions', 'delivery_charge')) {
        $stmt = $conn->prepare('UPDATE restaurant_table_sessions SET subtotal=?, tax=?, service_charge=?, delivery_charge=?, grand_total=?, balance_amount=? WHERE id=? AND client_id=?');
        $stmt->bind_param('ddddddii', $subtotal, $tax, $service, $deliveryCharge, $grand, $balance, $session_id, $client_id);
    } else {
        $stmt = $conn->prepare('UPDATE restaurant_table_sessions SET subtotal=?, tax=?, service_charge=?, grand_total=?, balance_amount=? WHERE id=? AND client_id=?');
        $stmt->bind_param('dddddii', $subtotal, $tax, $service, $grand, $balance, $session_id, $client_id);
    }
    $stmt->execute();
    $stmt->close();

    return [
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
}

/* ================= MAIN ================= */
$session_id = (int)($_GET['session_id'] ?? 0);
$paidView = isset($_GET['paid']);
$receiptParam = trim((string)($_GET['receipt_no'] ?? ''));
$paper = (string)($_GET['paper'] ?? '80');
if (!in_array($paper, ['80','58'], true)) $paper = '80';

$error = '';
$session = null;
$items = [];
$totals = [];
$payment = null;

try {
    if ($session_id <= 0) throw new Exception('Invalid bill session.');
    if (!table_exists($conn, 'restaurant_table_sessions') || !table_exists($conn, 'restaurant_order_items')) {
        throw new Exception('Restaurant DB tables missing. Please import restaurant migration SQL first.');
    }

    $session = load_session($conn, $session_id, $client_id);
    if (!$session) throw new Exception('Restaurant session not found.');
    $totals = recalc_session($conn, $session_id, $client_id);
    $session = load_session($conn, $session_id, $client_id);
    $items = load_items($conn, $session_id, $client_id);
    if (empty($items)) throw new Exception('No active order items found for this bill.');

    if (!$paidView && !in_array((string)$session['status'], ['paid','cancelled','void'], true)) {
        $conn->begin_transaction();
        $set = "status='bill_printed'";
        if (column_exists($conn, 'restaurant_table_sessions', 'bill_printed_at')) {
            $set .= ", bill_printed_at=IFNULL(bill_printed_at,NOW())";
        }
        $stmt = $conn->prepare("UPDATE restaurant_table_sessions SET $set WHERE id=? AND client_id=? AND status IN ('open','kot_sent','bill_printed')");
        $stmt->bind_param('ii', $session_id, $client_id);
        $stmt->execute();
        $stmt->close();

        if (!empty($session['table_id']) && table_exists($conn, 'restaurant_tables')) {
            $tid = (int)$session['table_id'];
            if (column_exists($conn, 'restaurant_tables', 'current_session_id')) {
                $stmt = $conn->prepare("UPDATE restaurant_tables SET status='Bill Printed', current_session_id=? WHERE id=? AND client_id=?");
                $stmt->bind_param('iii', $session_id, $tid, $client_id);
            } else {
                $stmt = $conn->prepare("UPDATE restaurant_tables SET status='Bill Printed' WHERE id=? AND client_id=?");
                $stmt->bind_param('ii', $tid, $client_id);
            }
            $stmt->execute();
            $stmt->close();
        }

        log_activity($conn, $client_id, (int)$session['branch_id'], $session_id, isset($session['table_id']) ? (int)$session['table_id'] : null, 'bill_printed', 'Customer bill printed/previewed.', $user_id);
        $conn->commit();
        $session = load_session($conn, $session_id, $client_id);
    }

    $payment = load_payment($conn, $session_id, $client_id, $receiptParam);
} catch (Throwable $e) {
    try { if ($conn->thread_id) $conn->rollback(); } catch (Throwable $ignore) {}
    $error = $e->getMessage();
}

$billNo = 'BILL-' . date('y') . '-' . str_pad((string)$session_id, 5, '0', STR_PAD_LEFT);
$invoiceNo = (string)($session['sale_id'] ?? '') !== '' ? 'SALE #' . (int)$session['sale_id'] : $billNo;
$receiptNo = (string)($payment['receipt_no'] ?? ($session['receipt_no'] ?? ''));
$orderType = $session ? (string)($session['order_type'] ?? 'dine_in') : 'dine_in';
$orderTypeLabel = ucwords(str_replace('_', ' ', $orderType));
$isDineIn = $orderType === 'dine_in';
$isTakeaway = $orderType === 'takeaway';
$isDelivery = $orderType === 'delivery';

$tableLabel = $session ? trim((string)($session['table_name'] ?: $session['table_no'] ?: 'N/A')) : 'N/A';
$tokenNo = $session ? (string)($session['token_no'] ?? '') : '';
$customerName = $session ? trim((string)($session['bill_customer_name'] ?? $session['customer_name'] ?? '')) : '';
$customerName = $customerName !== '' ? $customerName : 'Walk-in';
$customerPhone = $session ? trim((string)($session['bill_customer_phone'] ?? $session['customer_phone'] ?? '')) : '';
$riderName = $session ? trim((string)($session['rider_name'] ?? '')) : '';
$deliveryAddress = $session ? trim((string)($session['delivery_address'] ?? '')) : '';
$deliveryStatus = $session ? trim((string)($session['delivery_status'] ?? '')) : '';
$branchIdForSettings = (int)($session['branch_id'] ?? $branch_id);

$clientBusiness = '';
if (table_exists($conn, 'clients')) {
    $clientRow = fetch_one(
        $conn,
        "SELECT business_name, business_address, contact_number FROM clients WHERE id=? LIMIT 1",
        'i',
        [$client_id]
    );

    $clientBusiness = trim((string)($clientRow['business_name'] ?? ''));
}

$receiptTitle = trim((string)setting_value(
    $conn,
    $client_id,
    $branchIdForSettings,
    ['receipt_title','restaurant_name','business_name','company_name'],
    $clientBusiness ?: 'ONE ERP RESTAURANT'
));

if ($receiptTitle === '') {
    $receiptTitle = $clientBusiness ?: 'ONE ERP RESTAURANT';
}
$receiptPhone = trim((string)setting_value($conn, $client_id, $branchIdForSettings, ['receipt_phone','restaurant_phone','business_phone','phone'], ''));
$receiptAddress = trim((string)setting_value($conn, $client_id, $branchIdForSettings, ['receipt_address','restaurant_address','business_address','address'], ''));
$receiptFooter = trim((string)setting_value($conn, $client_id, $branchIdForSettings, ['receipt_footer','bill_footer','footer_text'], 'Thank you for dining with us.'));

if ($isTakeaway) {
    $orderMainLabel = $tokenNo !== '' ? ('Token ' . $tokenNo) : ('Takeaway #' . $session_id);
    $orderSubLabel = 'Counter / Takeaway order';
} elseif ($isDelivery) {
    $orderMainLabel = $tokenNo !== '' ? ('Token ' . $tokenNo) : ('Delivery #' . $session_id);
    $orderSubLabel = 'Delivery order' . ($deliveryStatus !== '' ? ' · ' . ucwords(str_replace('_', ' ', $deliveryStatus)) : '');
} else {
    $orderMainLabel = 'Table ' . $tableLabel;
    $orderSubLabel = 'Waiter ' . (($session['waiter_name'] ?? '') ?: 'N/A') . ' · Guests ' . (int)($session['guest_count'] ?? 0);
}

$isPaid = (($session['status'] ?? '') === 'paid') || !empty($payment);
$printTitle = $isPaid ? 'PAYMENT RECEIPT' : 'CUSTOMER BILL';
$paperWidth = $paper === '58' ? '58mm' : '80mm';
$contentWidth = $paper === '58' ? '50mm' : '72mm';
$fontSize = $paper === '58' ? '10px' : '11.5px';
$smallSize = $paper === '58' ? '8.5px' : '9.5px';
$grandSize = $paper === '58' ? '13px' : '15px';
$printDate = date('d M Y h:i A');
$backUrl = $session ? 'restaurant_pos.php?session_id=' . (int)$session_id : 'restaurant_orders.php';
$paymentUrl = 'restaurant_payment.php?session_id=' . (int)$session_id;
$basePrintUrl = 'restaurant_bill_print.php?session_id=' . (int)$session_id . ($paidView ? '&paid=1' : '') . ($receiptNo !== '' ? '&receipt_no=' . urlencode($receiptNo) : '');

/* ================= RECEIPT VIEW VARIABLES ================= */
$totalItems = is_array($items) ? count($items) : 0;
$totalQty = 0.0;
foreach ($items as $it) {
    $totalQty += (float)row_value($it, ['qty','quantity'], 0);
}

$taxPercent = (float)($totals['tax_percent'] ?? 0);
$discountAmount = (float)($totals['discount'] ?? 0);
$taxAmount = (float)($totals['tax'] ?? 0);
$serviceAmount = (float)($totals['service_charge'] ?? 0);
$deliveryAmount = (float)($totals['delivery_charge'] ?? 0);
$roundOffAmount = (float)($totals['round_off'] ?? 0);
$grossAmount = (float)($totals['subtotal'] ?? 0);
$netAmount = (float)($totals['grand_total'] ?? 0);

// $operatorName = trim((string)($_SESSION['user_name'] ?? $_SESSION['username'] ?? $_SESSION['name'] ?? 'Admin'));
$counterName = 'Main Counter';
$referenceNo = $receiptNo !== '' ? $receiptNo : $billNo;

$receiptStrn = trim((string)setting_value($conn, $client_id, $branchIdForSettings, ['receipt_strn','restaurant_strn','strn_no','business_strn'], ''));
$receiptNtn  = trim((string)setting_value($conn, $client_id, $branchIdForSettings, ['receipt_ntn','restaurant_ntn','ntn_no','business_ntn'], ''));
$receiptTag  = trim((string)setting_value($conn, $client_id, $branchIdForSettings, ['receipt_tagline','tagline','business_tagline'], ''));
$receiptTerms = trim((string)setting_value(
    $conn,
    $client_id,
    $branchIdForSettings,
    ['receipt_terms','bill_terms','terms_condition'],
    "No exchange/refund after bill print.\nGST/service charges are applied where applicable."
));

$paymentMode = 'Pending';
$paymentAmount = $netAmount;
if ($isPaid && !empty($payment)) {
    $paymentMode = payment_method_label($payment['payment_method'] ?? 'cash');
    $paymentAmount = (float)($payment['amount_paid'] ?? $payment['amount_due'] ?? $netAmount);
} elseif (!empty($session['payment_method'])) {
    $paymentMode = payment_method_label($session['payment_method']);
}

$receiptDate = $printDate;
$qrText = "Restaurant: {$receiptTitle}\nBill: {$invoiceNo}\nAmount: Rs " . number_format($netAmount, 2) . "\nDate: {$receiptDate}\nCustomer: {$customerName}";
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=170x170&data=' . urlencode($qrText);

$waLines = [];
$waLines[] = "*{$receiptTitle}*";
$waLines[] = $printTitle;
$waLines[] = "";
$waLines[] = "Bill: {$invoiceNo}";
$waLines[] = "Date: {$receiptDate}";
$waLines[] = "Order: {$orderMainLabel}";
$waLines[] = "Customer: {$customerName}";
$waLines[] = "";
$waLines[] = "*Items:*";
foreach ($items as $index => $it) {
    $itemName = trim((string)row_value($it, ['item_name','name','product_name','title'], 'Item'));
    $qty = qty_fmt(row_value($it, ['qty','quantity'], 0));
    $price = number_format((float)row_value($it, ['price','rate','unit_price'], 0), 2);
    $lineTotal = number_format((float)row_value($it, ['total','line_total','amount'], ((float)row_value($it, ['qty','quantity'], 0) * (float)row_value($it, ['price','rate','unit_price'], 0))), 2);
    $waLines[] = ($index + 1) . ". {$itemName}";
    $waLines[] = "   Qty: {$qty} | Price: {$price} | Total: {$lineTotal}";
}
$waLines[] = "";
$waLines[] = "Grand Total: Rs " . number_format($netAmount, 2);
$waLines[] = "Powered By OneERP";
$whatsappUrl = 'https://wa.me/?text=' . urlencode(implode("\n", $waLines));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($printTitle) ?> - <?= e($invoiceNo) ?></title>
<style>
* {
    box-sizing: border-box;
}

*,
*::before,
*::after {
    color: #000000 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    text-shadow: none !important;
}

@page {
    size: 80mm auto;
    margin: 0;
}

html,
body {
    margin: 0 !important;
    padding: 0 !important;
    width: 80mm !important;
    max-width: 80mm !important;
    min-width: 80mm !important;
    background: #ffffff !important;
    color: #000000 !important;
    font-family: "Courier New", monospace;
    font-size: 10px;
    line-height: 1.18;
    overflow-x: hidden;
}

body,
.receipt,
.receipt * {
    color: #000000 !important;
    font-weight: 700;
}

.receipt {
    width: 80mm !important;
    max-width: 80mm !important;
    min-width: 80mm !important;
    margin: 0 !important;
    padding: 3mm 2mm !important;
    background: #ffffff;
    overflow: hidden;
}

.center { text-align: center; }
.bold { font-weight: 900 !important; }

.store-name {
    font-size: 15px;
    font-weight: 900 !important;
    text-align: center;
    line-height: 1.1;
}

.store-tag {
    text-align: center;
    font-size: 9px;
    margin-top: 1px;
}

.company-info {
    text-align: center;
    font-size: 9.5px;
    font-weight: 700 !important;
    margin-top: 4px;
    line-height: 1.25;
}

.receipt-title {
    margin-top: 6px;
    padding: 3px 0;
    border-top: 1px dashed #000000;
    border-bottom: 1px dashed #000000;
    text-align: center;
    font-weight: 900 !important;
    font-size: 12px;
}

.info-row {
    display: grid;
    grid-template-columns: 25mm 1fr;
    gap: 1mm;
    margin: 2px 0;
    font-size: 9.5px;
}

.info-label {
    font-weight: 900 !important;
    white-space: nowrap;
}

.info-value {
    text-align: left;
    word-break: break-word;
}

.line {
    border-top: 1px dashed #000000;
    margin: 6px 0;
}

.items-head,
.item-values {
    display: grid;
    grid-template-columns: 30mm 12mm 8mm 7mm 14mm;
    column-gap: 0.5mm;
    width: 100%;
}

.items-head {
    border: 1px solid #000000;
    font-weight: 900 !important;
    text-align: center;
    padding: 3px 1px;
    font-size: 8.5px;
}

.item-values {
    font-size: 8.7px;
    align-items: start;
    padding-top: 2px;
}

.items-head span,
.item-values span {
    min-width: 0;
    white-space: nowrap;
}

.item-values span:nth-child(1) { text-align: left; }
.item-values span:nth-child(2) { text-align: right; }
.item-values span:nth-child(3) { text-align: center; }
.item-values span:nth-child(4) { text-align: center; }
.item-values span:nth-child(5) {
    text-align: right;
    font-weight: 900 !important;
}

.item-block {
    border-bottom: 1px dashed #000000;
    padding: 3px 0;
    break-inside: avoid;
    page-break-inside: avoid;
}

.item-name-cell {
    text-align: left !important;
    line-height: 1.1;
    white-space: nowrap !important;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 8.7px;
    font-weight: 900 !important;
}

.item-note {
    font-size: 8.4px;
    line-height: 1.1;
    margin-top: 2px;
    padding-left: 1mm;
    white-space: normal;
}

.item-money {
    letter-spacing: -0.4px;
}

.summary-two {
    display: grid;
    grid-template-columns: 1fr 1fr;
    border-bottom: 1px dashed #000000;
    padding: 4px 0;
    font-weight: 900 !important;
    font-size: 10px;
}

.summary-two div:last-child {
    text-align: right;
}

.summary-row {
    display: grid;
    grid-template-columns: 1fr 24mm;
    gap: 1mm;
    border-bottom: 1px dashed #999999;
    padding: 3px 0;
    font-size: 10px;
}

.summary-row span:last-child {
    text-align: right;
    font-weight: 900 !important;
}

.section-heading {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 2mm;
    font-weight: 900 !important;
    margin: 6px 0 3px;
    font-size: 9px;
}

.section-heading:after {
    content: "";
    border-top: 1px dashed #000000;
}

.net-amount {
    font-size: 13px;
    font-weight: 900 !important;
    border-top: 1px solid #000000;
    border-bottom: 1px solid #000000;
    padding: 5px 0;
    margin-top: 4px;
}

.qr-box {
    text-align: center;
    margin: 7px 0 4px;
}

.qr-box img {
    width: 105px;
    height: 105px;
}

.payment-title {
    text-align: center;
    font-weight: 900 !important;
    text-decoration: underline;
    margin-top: 5px;
    font-size: 10px;
}

.payment-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 4px;
    font-size: 9px;
}

.payment-table th,
.payment-table td {
    border: 1px solid #000000;
    padding: 3px 2px;
    text-align: center;
}

.terms {
    font-size: 8.8px;
    margin-top: 7px;
}

.terms ul {
    margin: 3px 0 0 12px;
    padding: 0;
}

.footer {
    text-align: center;
    margin-top: 7px;
    font-size: 8.8px;
}

.error-box {
    width: 80mm;
    padding: 10px;
    font-size: 12px;
    font-weight: 900;
    border: 1px solid #000000;
}

.no-print {
    width: 80mm;
    max-width: 80mm;
    text-align: center;
    margin: 8px 0;
    display: block !important;
}

.action-buttons {
    display: flex !important;
    justify-content: center;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}

.print-btn,
.back-btn,
.pay-btn,
.whatsapp-btn {
    color: #ffffff !important;
    border: 0;
    padding: 8px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    text-decoration: none;
    display: inline-block;
    line-height: 1.2;
    font-weight: 700 !important;
}

.print-btn { background: #111111 !important; }
.back-btn { background: #4b5563 !important; }
.pay-btn { background: #6d28d9 !important; }
.whatsapp-btn { background: #1fae4b !important; }

@media print {
    @page {
        size: 80mm auto;
        margin: 0;
    }

    html,
    body {
        width: 80mm !important;
        max-width: 80mm !important;
        min-width: 80mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        overflow: visible !important;
    }

    body,
    .receipt,
    .receipt * {
        color: #000000 !important;
        font-weight: 700 !important;
    }

    .store-name,
    .receipt-title,
    .info-label,
    .items-head,
    .summary-two,
    .net-amount,
    .payment-title,
    .payment-table th,
    .bold {
        font-weight: 900 !important;
    }

    .receipt {
        width: 80mm !important;
        max-width: 80mm !important;
        min-width: 80mm !important;
        margin: 0 !important;
        padding: 3mm 2mm !important;
    }

    .no-print {
        display: none !important;
    }
}
</style>
</head>
<body>

<?php if ($error): ?>
    <div class="error-box"><strong>Error:</strong> <?= e($error) ?></div>
<?php else: ?>

<div class="no-print">
    <div class="action-buttons">
        <a class="back-btn" href="<?= e($backUrl) ?>">Back</a>
        <button class="print-btn" onclick="window.print()">Print</button>
        <?php if (!$isPaid): ?>
            <a class="pay-btn" href="<?= e($paymentUrl) ?>">Payment</a>
        <?php endif; ?>
        <a class="whatsapp-btn" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener">WhatsApp</a>
    </div>
</div>

<div class="receipt">

    <div class="store-name"><?= e($receiptTitle ?: 'ONE ERP RESTAURANT') ?></div>
    <?php if ($receiptTag !== ''): ?>
        <div class="store-tag"><?= e($receiptTag) ?></div>
    <?php endif; ?>

    <div class="company-info">
        <?php if ($receiptStrn !== ''): ?>STRN # <?= e($receiptStrn) ?><br><?php endif; ?>
        <?php if ($receiptNtn !== ''): ?>NTN # <?= e($receiptNtn) ?><br><?php endif; ?>
        <?php if ($receiptPhone !== ''): ?>Contact # : <?= e($receiptPhone) ?><br><?php endif; ?>
        <?php if ($receiptAddress !== ''): ?><?= nl2br(e($receiptAddress)) ?><?php endif; ?>
    </div>

    <div class="receipt-title"><?= e($printTitle) ?></div>

    <div class="info-row">
        <span class="info-label">Bill #</span>
        <span class="info-value"><?= e($invoiceNo) ?></span>
    </div>

    <?php if ($receiptNo !== ''): ?>
    <div class="info-row">
        <span class="info-label">Receipt #</span>
        <span class="info-value"><?= e($receiptNo) ?></span>
    </div>
    <?php endif; ?>

    <!--<div class="info-row">-->
    <!--    <span class="info-label">Operator Name</span>-->
    
    <!--</div>-->

    <div class="info-row">
        <span class="info-label">Bill Date</span>
        <span class="info-value"><?= e($receiptDate) ?></span>
    </div>

    <div class="info-row">
        <span class="info-label">Customer Name</span>
        <span class="info-value"><?= e($customerName) ?></span>
    </div>

    <?php if ($customerPhone !== ''): ?>
    <div class="info-row">
        <span class="info-label">Phone</span>
        <span class="info-value"><?= e($customerPhone) ?></span>
    </div>
    <?php endif; ?>

    <div class="info-row">
        <span class="info-label">Order Type</span>
        <span class="info-value"><?= e($orderTypeLabel) ?></span>
    </div>

    <div class="info-row">
        <span class="info-label">Order</span>
        <span class="info-value"><?= e($orderMainLabel) ?></span>
    </div>

    <?php if ($isDineIn): ?>
        <div class="info-row">
            <span class="info-label">Waiter</span>
            <span class="info-value"><?= e(($session['waiter_name'] ?? '') ?: 'N/A') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Guests</span>
            <span class="info-value"><?= (int)($session['guest_count'] ?? 0) ?></span>
        </div>
    <?php elseif ($isDelivery): ?>
        <div class="info-row">
            <span class="info-label">Rider</span>
            <span class="info-value"><?= e($riderName ?: 'Not Assigned') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Delivery</span>
            <span class="info-value"><?= e($deliveryStatus !== '' ? ucwords(str_replace('_',' ', $deliveryStatus)) : 'Pending') ?></span>
        </div>
        <?php if ($deliveryAddress !== ''): ?>
        <div class="info-row">
            <span class="info-label">Address</span>
            <span class="info-value"><?= e($deliveryAddress) ?></span>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (trim((string)($session['branch_name'] ?? '')) !== ''): ?>
    <div class="info-row">
        <span class="info-label">Branch</span>
        <span class="info-value"><?= e($session['branch_name']) ?></span>
    </div>
    <?php endif; ?>

    <div class="info-row">
        <span class="info-label">Counter</span>
        <span class="info-value"><?= e($counterName) ?></span>
    </div>

    <div class="info-row">
        <span class="info-label">Reference #</span>
        <span class="info-value"><?= e($referenceNo) ?></span>
    </div>

    <div class="line"></div>

    <div class="items-head">
        <span>Item Name</span>
        <span>Price</span>
        <span>Qty/Wt</span>
        <span>Tax %</span>
        <span>Amount</span>
    </div>

    <?php foreach ($items as $index => $it):
        $itemName = trim((string)row_value($it, ['item_name','name','product_name','title'], 'Item'));
        if ($itemName === '') $itemName = 'Item';
        $qty = (float)row_value($it, ['qty','quantity'], 0);
        $price = (float)row_value($it, ['price','rate','unit_price'], 0);
        $lineTotal = (float)row_value($it, ['total','line_total','amount'], ($qty * $price));
        $note = trim((string)row_value($it, ['special_note','note','remarks'], ''));
    ?>
        <div class="item-block">
            <div class="item-values">
                <span class="item-name-cell"><?= e($itemName) ?></span>
                <span class="item-money"><?= number_format($price, 2, '.', '') ?></span>
                <span><?= e(qty_fmt($qty)) ?> P</span>
                <span><?= e(qty_fmt($taxPercent)) ?>%</span>
                <span class="item-money"><?= number_format($lineTotal, 2, '.', '') ?></span>
            </div>
            <?php if ($note !== ''): ?>
                <div class="item-note">Note: <?= e($note) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="summary-two">
        <div>Total Item &nbsp; <?= (int)$totalItems ?></div>
        <div>Total Qty &nbsp; <?= e(qty_fmt($totalQty)) ?></div>
    </div>

    <div class="summary-row">
        <span>Gross Amount</span>
        <span><?= number_format($grossAmount, 2) ?></span>
    </div>

    <div class="summary-row">
        <span>Discount</span>
        <span><?= number_format($discountAmount, 2) ?></span>
    </div>

    <div class="summary-row">
        <span>G.S.T</span>
        <span><?= number_format($taxAmount, 2) ?></span>
    </div>

    <?php if ($serviceAmount > 0 || $deliveryAmount > 0 || abs($roundOffAmount) > 0): ?>
        <div class="section-heading"><span>-- Other Charges Detail</span></div>

        <?php if ($serviceAmount > 0): ?>
        <div class="summary-row">
            <span>Service Charges</span>
            <span><?= number_format($serviceAmount, 2) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($deliveryAmount > 0): ?>
        <div class="summary-row">
            <span>Delivery Charges</span>
            <span><?= number_format($deliveryAmount, 2) ?></span>
        </div>
        <?php endif; ?>

        <?php if (abs($roundOffAmount) > 0): ?>
        <div class="summary-row">
            <span>Round Off</span>
            <span><?= number_format($roundOffAmount, 2) ?></span>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="summary-row net-amount">
        <span>Net Amount</span>
        <span><?= number_format($netAmount, 2) ?></span>
    </div>

    <div class="qr-box">
        <img src="<?= e($qrUrl) ?>" alt="QR Code">
        <div>Scan to verify receipt</div>
    </div>

    <div class="payment-title">Payment Detail</div>

    <table class="payment-table">
        <thead>
            <tr>
                <th>Mode</th>
                <th>Currency</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= e($paymentMode) ?></td>
                <td>PKR</td>
                <td><?= number_format($paymentAmount, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="center bold" style="margin-top:8px;">
        *<?= e($referenceNo) ?>*
    </div>

    <?php $termsLines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $receiptTerms))); ?>
    <?php if (!empty($termsLines)): ?>
    <div class="terms">
        <div class="center bold">Terms And Condition</div>
        <ul>
            <?php foreach ($termsLines as $termLine): ?>
                <li><?= e($termLine) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="footer">
        Bill Date: <?= e($receiptDate) ?><br>
        Powered By OneERP<br>
        <?= nl2br(e($receiptFooter ?: 'Thank you for dining with us.')) ?>
    </div>

</div>

<?php if (isset($_GET['autoprint'])): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 700);
});
</script>
<?php endif; ?>

<?php endif; ?>
</body>
</html>
