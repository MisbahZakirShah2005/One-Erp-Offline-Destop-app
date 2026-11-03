<?php
session_start();

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) $conn = $mysqli;
    elseif (isset($db) && $db instanceof mysqli) $conn = $db;
}
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$kot_id    = (int)($_GET['kot_id'] ?? $_GET['id'] ?? 0);
$autoPrint = (int)($_GET['auto'] ?? 0) === 1;

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function fmt_qty($qty): string {
    $v = (float)$qty;
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}
function status_label(string $s): string { return ucwords(str_replace('_', ' ', $s)); }
function order_type_label(string $type): string {
    if ($type === 'dine_in') return 'Dine In';
    if ($type === 'takeaway') return 'Takeaway';
    if ($type === 'delivery') return 'Delivery';
    return ucwords(str_replace('_', ' ', $type));
}
function first_non_empty(...$values): string {
    foreach ($values as $v) {
        $v = trim((string)$v);
        if ($v !== '') return $v;
    }
    return '';
}

$error = '';
$kot = [];
$items = [];

try {
    if ($kot_id <= 0) throw new Exception('Invalid KOT id.');
    if (!table_exists($conn, 'restaurant_kots') || !table_exists($conn, 'restaurant_kot_items')) {
        throw new Exception('KOT tables not found. Please run restaurant migration first.');
    }

    $hasTables    = table_exists($conn, 'restaurant_tables');
    $hasWaiters   = table_exists($conn, 'restaurant_waiters');
    $hasCustomers = table_exists($conn, 'customers');
    $hasUsers     = table_exists($conn, 'users');

    $kOrderType = column_exists($conn, 'restaurant_kots', 'order_type') ? 'k.order_type AS kot_order_type' : 'NULL AS kot_order_type';
    $kToken     = column_exists($conn, 'restaurant_kots', 'token_no') ? 'k.token_no AS kot_token_no' : 'NULL AS kot_token_no';
    $kCustomer  = column_exists($conn, 'restaurant_kots', 'customer_name') ? 'k.customer_name AS kot_customer_name' : 'NULL AS kot_customer_name';
    $kPhone     = column_exists($conn, 'restaurant_kots', 'customer_phone') ? 'k.customer_phone AS kot_customer_phone' : 'NULL AS kot_customer_phone';
    $kAddress   = column_exists($conn, 'restaurant_kots', 'delivery_address') ? 'k.delivery_address AS kot_delivery_address' : 'NULL AS kot_delivery_address';
    $kRiderId   = column_exists($conn, 'restaurant_kots', 'rider_id') ? 'k.rider_id AS kot_rider_id' : 'NULL AS kot_rider_id';

    $sToken     = column_exists($conn, 'restaurant_table_sessions', 'token_no') ? 's.token_no AS session_token_no' : 'NULL AS session_token_no';
    $sCustomer  = column_exists($conn, 'restaurant_table_sessions', 'customer_name') ? 's.customer_name AS session_customer_name' : 'NULL AS session_customer_name';
    $sPhone     = column_exists($conn, 'restaurant_table_sessions', 'customer_phone') ? 's.customer_phone AS session_customer_phone' : 'NULL AS session_customer_phone';
    $sAddress   = column_exists($conn, 'restaurant_table_sessions', 'delivery_address') ? 's.delivery_address AS session_delivery_address' : 'NULL AS session_delivery_address';
    $sDelStatus = column_exists($conn, 'restaurant_table_sessions', 'delivery_status') ? 's.delivery_status AS delivery_status' : 'NULL AS delivery_status';
    $sRiderId   = column_exists($conn, 'restaurant_table_sessions', 'rider_id') ? 's.rider_id AS session_rider_id' : 'NULL AS session_rider_id';

    $tableSelect = $hasTables ? 't.table_no, t.table_name, t.floor_name' : 'NULL AS table_no, NULL AS table_name, NULL AS floor_name';
    $tableJoin = $hasTables ? 'LEFT JOIN restaurant_tables t ON t.id = COALESCE(k.table_id, s.table_id)' : '';

    $waiterSelect = $hasWaiters ? 'w.name AS waiter_name, w.phone AS waiter_phone' : 'NULL AS waiter_name, NULL AS waiter_phone';
    $waiterJoin = $hasWaiters ? 'LEFT JOIN restaurant_waiters w ON w.id = COALESCE(k.waiter_id, s.waiter_id)' : '';

    $customerSelect = $hasCustomers ? 'c.name AS linked_customer_name, c.phone AS linked_customer_phone' : 'NULL AS linked_customer_name, NULL AS linked_customer_phone';
    $customerJoin = $hasCustomers ? 'LEFT JOIN customers c ON c.id = s.customer_id' : '';

    $riderSelect = ($hasUsers && column_exists($conn, 'users', 'name')) ? 'ru.name AS rider_name' : 'NULL AS rider_name';
    $riderJoin = ($hasUsers && column_exists($conn, 'users', 'name')) ? 'LEFT JOIN users ru ON ru.id = COALESCE(' . (column_exists($conn, 'restaurant_kots', 'rider_id') ? 'k.rider_id' : 'NULL') . ', ' . (column_exists($conn, 'restaurant_table_sessions', 'rider_id') ? 's.rider_id' : 'NULL') . ')' : '';

    $sql = "SELECT k.*, 
                   s.guest_count, s.order_type AS session_order_type, s.status AS session_status,
                   $kOrderType, $kToken, $kCustomer, $kPhone, $kAddress, $kRiderId,
                   $sToken, $sCustomer, $sPhone, $sAddress, $sDelStatus, $sRiderId,
                   $tableSelect,
                   $waiterSelect,
                   $customerSelect,
                   $riderSelect
            FROM restaurant_kots k
            LEFT JOIN restaurant_table_sessions s ON s.id = k.session_id
            $tableJoin
            $waiterJoin
            $customerJoin
            $riderJoin
            WHERE k.id=?";
    $types = 'i';
    $params = [$kot_id];
    if ($role_id !== 1) {
        $sql .= " AND k.client_id=?";
        $types .= 'i';
        $params[] = $client_id;
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $kot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$kot) throw new Exception('KOT not found or access denied.');

    $stmt = $conn->prepare("SELECT * FROM restaurant_kot_items WHERE kot_id=? ORDER BY id ASC");
    $stmt->bind_param('i', $kot_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (column_exists($conn, 'restaurant_kots', 'printed_at')) {
        $stmt = $conn->prepare("UPDATE restaurant_kots SET printed_at = COALESCE(printed_at, NOW()) WHERE id=?");
        $stmt->bind_param('i', $kot_id);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$orderType = strtolower(first_non_empty($kot['kot_order_type'] ?? '', $kot['order_type'] ?? '', $kot['session_order_type'] ?? 'dine_in'));
if (!in_array($orderType, ['dine_in', 'takeaway', 'delivery'], true)) $orderType = 'dine_in';

$tokenNo = first_non_empty($kot['kot_token_no'] ?? '', $kot['token_no'] ?? '', $kot['session_token_no'] ?? '');
$customerName = first_non_empty($kot['kot_customer_name'] ?? '', $kot['customer_name'] ?? '', $kot['session_customer_name'] ?? '', $kot['linked_customer_name'] ?? '');
$customerPhone = first_non_empty($kot['kot_customer_phone'] ?? '', $kot['customer_phone'] ?? '', $kot['session_customer_phone'] ?? '', $kot['linked_customer_phone'] ?? '');
$deliveryAddress = first_non_empty($kot['kot_delivery_address'] ?? '', $kot['delivery_address'] ?? '', $kot['session_delivery_address'] ?? '');
$deliveryStatus = first_non_empty($kot['delivery_status'] ?? '');
$riderName = first_non_empty($kot['rider_name'] ?? '');

$tableTitle = 'Counter Order';
if ($orderType === 'dine_in') {
    $tableTitle = !empty($kot['table_no']) ? ('Table ' . $kot['table_no']) : 'Dine In';
    if (!empty($kot['table_name'])) $tableTitle .= ' - ' . $kot['table_name'];
} elseif ($orderType === 'takeaway') {
    $tableTitle = 'Takeaway' . ($tokenNo !== '' ? ' #' . $tokenNo : '');
} elseif ($orderType === 'delivery') {
    $tableTitle = 'Delivery' . ($tokenNo !== '' ? ' #' . $tokenNo : '');
}

$badgeClass = 'badge-dine';
if ($orderType === 'takeaway') $badgeClass = 'badge-takeaway';
if ($orderType === 'delivery') $badgeClass = 'badge-delivery';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KOT Print<?= $kot ? ' - ' . e($kot['kot_no'] ?? '') : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
html,body{margin:0;min-height:100%;font-family:Inter,"Segoe UI",Arial,sans-serif;background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.34),transparent 28%),linear-gradient(135deg,#050914 0%,#0b1024 45%,#160f2d 100%);color:#f8fafc}.page-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.screen-card{width:min(760px,100%);background:radial-gradient(circle at 20% 0%,rgba(124,58,237,.18),transparent 34%),linear-gradient(135deg,rgba(15,23,42,.82),rgba(17,24,39,.66));border:1px solid rgba(148,163,184,.20);border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.42),inset 0 1px 0 rgba(255,255,255,.06);backdrop-filter:blur(18px);overflow:hidden}.screen-head{display:flex;justify-content:space-between;gap:14px;align-items:center;padding:18px 20px;border-bottom:1px solid rgba(148,163,184,.14)}.screen-head h3{margin:0;font-weight:950;letter-spacing:-.04em}.screen-head small{color:#aab4cf;font-weight:800}.btn-glow{border:0;border-radius:13px;padding:10px 15px;color:#fff;font-weight:950;background:linear-gradient(135deg,#6d4cff,#7c3aed);box-shadow:0 14px 28px rgba(109,76,255,.28);text-decoration:none;display:inline-flex;gap:8px;align-items:center}.btn-soft{background:rgba(15,23,42,.72);border:1px solid rgba(148,163,184,.20);box-shadow:none}.ticket-area{padding:20px}.ticket{width:340px;max-width:100%;margin:auto;background:#fff;color:#000;border-radius:4px;padding:14px;font-family:"Courier New",monospace;box-shadow:0 18px 45px rgba(0,0,0,.30)}.ticket h2{font-size:20px;margin:0;text-align:center;font-weight:900;letter-spacing:.04em}.ticket .sub{text-align:center;font-size:12px;margin:2px 0 10px}.dash{border-top:1px dashed #000;margin:8px 0}.rowx{display:flex;justify-content:space-between;gap:8px;font-size:12px;line-height:1.45}.rowx strong{font-weight:900;text-align:right}.rowx .left{max-width:44%;}.rowx .right{max-width:56%;text-align:right;font-weight:900;word-break:break-word}.fullx{font-size:12px;line-height:1.35;margin:3px 0;word-break:break-word}.fullx strong{font-weight:900}.item{display:grid;grid-template-columns:48px 1fr;gap:8px;font-size:14px;margin:8px 0}.item .qty{font-weight:900}.item .name{font-weight:900}.item .note{grid-column:2;color:#111;font-size:12px;font-style:italic}.status-pill{display:inline-block;border:1px solid #000;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:900;text-transform:uppercase}.order-badge{display:inline-block;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:900;text-transform:uppercase;margin-top:5px;border:1px solid #000}.badge-dine{background:#e0e7ff}.badge-takeaway{background:#dcfce7}.badge-delivery{background:#ffedd5}.alert-glass{background:rgba(220,38,38,.15);border:1px solid rgba(248,113,113,.28);border-radius:16px;padding:18px;color:#fecaca;font-weight:850}.footer-note{text-align:center;font-size:11px;margin-top:8px;font-weight:900}.no-print{display:block}@media print{html,body{background:#fff!important;color:#000!important}.page-wrap{padding:0;display:block;min-height:0}.screen-card{width:100%;border:0;box-shadow:none;background:#fff}.screen-head,.no-print{display:none!important}.ticket-area{padding:0}.ticket{width:72mm;max-width:72mm;margin:0;box-shadow:none;border-radius:0;padding:3mm;font-size:12px}.ticket h2{font-size:18px}.rowx,.fullx{font-size:11px}.item{font-size:13px}@page{margin:3mm;size:72mm auto}}
</style>
</head>
<body>
<div class="page-wrap">
    <div class="screen-card">
        <div class="screen-head no-print">
            <div>
                <h3>KOT Print</h3>
                <small>Kitchen Order Ticket thermal preview</small>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if($kot): ?>
                    <button class="btn-glow" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                    <a class="btn-glow btn-soft" href="restaurant_kitchen.php"><i class="bi bi-display"></i> Kitchen</a>
                    <a class="btn-glow btn-soft" href="restaurant_pos.php?session_id=<?= (int)($kot['session_id'] ?? 0) ?>"><i class="bi bi-arrow-left"></i> POS</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="ticket-area">
            <?php if($error): ?>
                <div class="alert-glass"><i class="bi bi-exclamation-triangle"></i> <?= e($error) ?></div>
            <?php else: ?>
            <div class="ticket">
                <h2>KITCHEN ORDER</h2>
                <div class="sub">Not a customer bill</div>
                <div class="text-center"><span class="order-badge <?= e($badgeClass) ?>"><?= e(order_type_label($orderType)) ?></span></div>
                <div class="dash"></div>

                <div class="rowx"><span>KOT No:</span><strong><?= e($kot['kot_no'] ?? '') ?></strong></div>
                <div class="rowx"><span>Status:</span><strong><?= e(status_label((string)($kot['status'] ?? 'pending'))) ?></strong></div>
                <div class="rowx"><span class="left">Order:</span><span class="right"><?= e($tableTitle) ?></span></div>
                <?php if($tokenNo !== ''): ?><div class="rowx"><span>Token:</span><strong><?= e($tokenNo) ?></strong></div><?php endif; ?>
                <?php if(!empty($kot['floor_name']) && $orderType === 'dine_in'): ?><div class="rowx"><span>Floor:</span><strong><?= e($kot['floor_name']) ?></strong></div><?php endif; ?>
                <?php if($orderType === 'dine_in'): ?>
                    <div class="rowx"><span>Waiter:</span><strong><?= e($kot['waiter_name'] ?: 'N/A') ?></strong></div>
                    <div class="rowx"><span>Guests:</span><strong><?= (int)($kot['guest_count'] ?? 0) ?></strong></div>
                <?php endif; ?>
                <?php if($orderType === 'takeaway' || $orderType === 'delivery'): ?>
                    <div class="rowx"><span>Customer:</span><strong><?= e($customerName !== '' ? $customerName : 'Walk-in') ?></strong></div>
                    <?php if($customerPhone !== ''): ?><div class="rowx"><span>Phone:</span><strong><?= e($customerPhone) ?></strong></div><?php endif; ?>
                <?php endif; ?>
                <?php if($orderType === 'delivery'): ?>
                    <?php if($riderName !== ''): ?><div class="rowx"><span>Rider:</span><strong><?= e($riderName) ?></strong></div><?php endif; ?>
                    <?php if($deliveryStatus !== ''): ?><div class="rowx"><span>Delivery:</span><strong><?= e(status_label($deliveryStatus)) ?></strong></div><?php endif; ?>
                    <?php if($deliveryAddress !== ''): ?><div class="fullx"><strong>Address:</strong> <?= e($deliveryAddress) ?></div><?php endif; ?>
                <?php endif; ?>
                <div class="rowx"><span>Time:</span><strong><?= e(date('d-M-Y h:i A', strtotime((string)($kot['created_at'] ?? 'now')))) ?></strong></div>

                <div class="dash"></div>
                <?php if(!$items): ?>
                    <div class="text-center" style="font-size:12px;font-weight:900">No KOT items found</div>
                <?php else: foreach($items as $it): ?>
                    <div class="item">
                        <div class="qty"><?= e(fmt_qty($it['qty'])) ?> x</div>
                        <div>
                            <div class="name"><?= e($it['item_name']) ?></div>
                           
                        </div>
                        <?php if(!empty($it['note'])): ?><div class="note">Note: <?= e($it['note']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
                <?php if(!empty($kot['notes'])): ?>
                <div class="dash"></div>
                <div style="font-size:12px"><strong>KOT Note:</strong> <?= e($kot['notes']) ?></div>
                <?php endif; ?>
                <div class="dash"></div>
                <div class="footer-note">*** SEND TO KITCHEN ***</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if($autoPrint && !$error): ?>
<script>
window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 450); });
</script>
<?php endif; ?>
</body>
</html>
