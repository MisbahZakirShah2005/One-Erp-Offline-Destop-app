<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);

/* =========================================================
   EMPTY BOTTLES RETURN - SINGLE FILE
   - Same connection/session/partials flow as orders.php
   - Backend AJAX is inside this same file
   - Main DB table: empty_bottle_returns
   ========================================================= */

function e($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function tableExists(mysqli $conn, string $table): bool {
    // MariaDB/XAMPP does not support prepared placeholders in SHOW TABLES LIKE ?
    // So table name is sanitized and then checked safely.
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return false;

    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");

    return $result && $result->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    // MariaDB/XAMPP does not support prepared placeholders in SHOW COLUMNS ... LIKE ?
    // So table name is sanitized and column name is escaped.
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return false;

    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

    return $result && $result->num_rows > 0;
}

function firstExistingColumn(mysqli $conn, string $table, array $columns, string $fallback = ''): string {
    foreach ($columns as $col) {
        if (columnExists($conn, $table, $col)) return $col;
    }
    return $fallback;
}

function getBottleItemCondition(mysqli $conn): string {
    $nameCol = firstExistingColumn($conn, 'items', ['name','item_name','title','product_name'], 'name');
    return "(i.`$nameCol` LIKE '%bottle%' OR i.`$nameCol` LIKE '%19%' OR i.`$nameCol` LIKE '%liter%' OR i.`$nameCol` LIKE '%litre%')";
}

function getSaleItemsQtyExpr(mysqli $conn): string {
    $qtyCol = firstExistingColumn($conn, 'sale_items', ['qty','quantity','quantity_ordered'], 'qty');
    return "COALESCE(si.`$qtyCol`,0)";
}

function getEmptyBottleRows(mysqli $conn, int $client_id, int $branch_id = 0): array {
    $branchFilter = '';
    $types = 'i';
    $params = [$client_id];

    if ($branch_id > 0 && columnExists($conn, 'empty_bottle_returns', 'branch_id')) {
        $branchFilter = ' AND ebr.branch_id = ? ';
        $types .= 'i';
        $params[] = $branch_id;
    }

    $customerNameExpr = "''";
    $phoneExpr = "''";
    if (tableExists($conn, 'customers')) {
        $custNameCol = firstExistingColumn($conn, 'customers', ['name','customer_name','full_name'], 'name');
        $phoneCol = firstExistingColumn($conn, 'customers', ['phone','mobile','contact_no','phone_number'], '');
        $customerNameExpr = "COALESCE(c.`$custNameCol`, '')";
        $phoneExpr = $phoneCol ? "COALESCE(c.`$phoneCol`, '')" : "''";
    }

    $invoiceExpr = "CONCAT('ORD-', ebr.sale_id)";
    if (tableExists($conn, 'sales')) {
        $invoiceCol = firstExistingColumn($conn, 'sales', ['invoice_no','order_no','sale_no','reference_no'], '');
        if ($invoiceCol) $invoiceExpr = "COALESCE(s.`$invoiceCol`, CONCAT('ORD-', ebr.sale_id))";
    }

    $sql = "
        SELECT 
            ebr.*,
            $customerNameExpr AS customer_name,
            $phoneExpr AS customer_phone,
            $invoiceExpr AS order_no
        FROM empty_bottle_returns ebr
        LEFT JOIN sales s ON s.id = ebr.sale_id
        LEFT JOIN customers c ON c.id = ebr.customer_id
        WHERE ebr.client_id = ? $branchFilter
        ORDER BY ebr.id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getStats(array $rows): array {
    $totalDelivered = 0;
    $totalReturned = 0;
    $totalDamaged = 0;
    $totalPending = 0;
    $customersPending = [];

    foreach ($rows as $r) {
        $delivered = (int)($r['delivered_qty'] ?? 0);
        $returned  = (int)($r['returned_qty'] ?? 0);
        $damaged   = (int)($r['damaged_qty'] ?? 0);
        $lost      = (int)($r['lost_qty'] ?? 0);
        $pending   = max(0, $delivered - ($returned + $damaged + $lost));

        $totalDelivered += $delivered;
        $totalReturned  += $returned;
        $totalDamaged   += $damaged;
        $totalPending   += $pending;
        if ($pending > 0 && !empty($r['customer_id'])) $customersPending[(int)$r['customer_id']] = true;
    }

    return [
        'pending' => $totalPending,
        'returned' => $totalReturned,
        'damaged' => $totalDamaged,
        'customers_pending' => count($customersPending),
    ];
}

function getOrderBottleData(mysqli $conn, string $order_key, int $client_id, int $branch_id): array {
    $order_key = trim($order_key);
    if ($order_key === '') return ['success'=>false,'message'=>'Invoice ID / Sale ID enter karo.'];

    if (!tableExists($conn, 'sales')) {
        return ['success'=>false,'message'=>'sales table not found.'];
    }

    // Invoice number ya sale id dono support hain.
    // Example: 18, INV-WAT-18, INV-001, -001
    $possibleSaleId = 0;
    if (preg_match('/^-?\d+$/', $order_key)) {
        $possibleSaleId = abs((int)$order_key);
    } elseif (preg_match('/(\d+)$/', $order_key, $m)) {
        $possibleSaleId = (int)$m[1];
    }

    $customerNameExpr = "COALESCE(NULLIF(s.customer_name,''), 'Walk-in')";
    $phoneExpr = "''";
    $routeExpr = "''";

    if (tableExists($conn, 'customers')) {
        $custNameCol = firstExistingColumn($conn, 'customers', ['name','customer_name','full_name'], 'name');
        $phoneCol = firstExistingColumn($conn, 'customers', ['phone','mobile','contact_no','phone_number'], '');
        $routeCol = firstExistingColumn($conn, 'customers', ['route_name','route','area'], '');
        $customerNameExpr = "COALESCE(NULLIF(c.`$custNameCol`,''), NULLIF(s.customer_name,''), 'Walk-in')";
        $phoneExpr = $phoneCol ? "COALESCE(c.`$phoneCol`, '')" : "''";
        $routeExpr = $routeCol ? "COALESCE(c.`$routeCol`, '')" : "''";
    }

    $invoiceCol = firstExistingColumn($conn, 'sales', ['invoice_no'], '');
    $refCol     = firstExistingColumn($conn, 'sales', ['reference_no'], '');
    $invoiceExpr = $invoiceCol ? "COALESCE(NULLIF(s.`$invoiceCol`,''), CONCAT('SALE-', s.id))" : "CONCAT('SALE-', s.id)";

    $saleRouteCol = firstExistingColumn($conn, 'sales', ['route_name','route','area'], '');
    if ($saleRouteCol) $routeExpr = "COALESCE(NULLIF(s.`$saleRouteCol`,''), $routeExpr)";

    $driverExpr = "''";
    $driverCol = firstExistingColumn($conn, 'sales', ['driver_name','driver'], '');
    if ($driverCol) $driverExpr = "COALESCE(s.`$driverCol`, '')";

    $depositExpr = "0";
    $depositCol = firstExistingColumn($conn, 'sales', ['deposit_amount','security_deposit','bottle_deposit'], '');
    if ($depositCol) $depositExpr = "COALESCE(s.`$depositCol`,0)";

    $branchSql = '';
    $types = 'i';
    $params = [$client_id];
    $whereParts = [];

    if ($invoiceCol) { $whereParts[] = "s.`$invoiceCol` = ?"; $types .= 's'; $params[] = $order_key; }
    if ($refCol)     { $whereParts[] = "s.`$refCol` = ?";     $types .= 's'; $params[] = $order_key; }
    if ($possibleSaleId > 0) { $whereParts[] = "s.id = ?"; $types .= 'i'; $params[] = $possibleSaleId; }

    if (empty($whereParts)) return ['success'=>false,'message'=>'Invoice / Sale ID search condition nahi bani.'];

    if ($branch_id > 0 && columnExists($conn, 'sales', 'branch_id')) {
        $branchSql = " AND (s.branch_id = ? OR s.branch_id IS NULL OR s.branch_id = 0) ";
        $types .= 'i';
        $params[] = $branch_id;
    }

    $sql = "
        SELECT 
            s.id AS sale_id,
            s.customer_id,
            $invoiceExpr AS order_no,
            $customerNameExpr AS customer_name,
            $phoneExpr AS phone,
            $routeExpr AS route_name,
            $driverExpr AS driver_name,
            $depositExpr AS deposit_amount
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id AND c.client_id = s.client_id
        WHERE s.client_id = ?
          AND (" . implode(' OR ', $whereParts) . ")
          $branchSql
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['success'=>false,'message'=>$conn->error];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if (!$order) {
        return ['success'=>false,'message'=>'Invoice / Sale ID is client ke andar nahi mila. Agar invoice receipt par show ho raha hai lekin DB me save nahi ho raha, sales.invoice_no column me invoice_no save karna zaroori hai.'];
    }

    $sale_id = (int)$order['sale_id'];
    $delivered = 0;
    $bottleType = '19 Liter Bottle';
    $itemId = null;

    if (tableExists($conn, 'sale_items')) {
        $qtyExpr = getSaleItemsQtyExpr($conn);
        $itemNameSql = columnExists($conn, 'sale_items', 'item_name') ? "COALESCE(NULLIF(si.item_name,''), i.name, '19 Liter Bottle')" : "COALESCE(i.name, '19 Liter Bottle')";
        $conditionParts = [];
        if (columnExists($conn, 'sale_items', 'item_name')) {
            $conditionParts[] = "LOWER(si.item_name) LIKE '%bottle%'";
            $conditionParts[] = "LOWER(si.item_name) LIKE '%galon%'";
            $conditionParts[] = "LOWER(si.item_name) LIKE '%gallon%'";
            $conditionParts[] = "LOWER(si.item_name) LIKE '%liter%'";
            $conditionParts[] = "LOWER(si.item_name) LIKE '%litre%'";
        }
        if (tableExists($conn, 'items')) {
            $nameCol = firstExistingColumn($conn, 'items', ['name','item_name','title','product_name'], 'name');
            $conditionParts[] = "LOWER(i.`$nameCol`) LIKE '%bottle%'";
            $conditionParts[] = "LOWER(i.`$nameCol`) LIKE '%galon%'";
            $conditionParts[] = "LOWER(i.`$nameCol`) LIKE '%gallon%'";
            $conditionParts[] = "LOWER(i.`$nameCol`) LIKE '%liter%'";
            $conditionParts[] = "LOWER(i.`$nameCol`) LIKE '%litre%'";
        }
        $condition = !empty($conditionParts) ? '(' . implode(' OR ', $conditionParts) . ')' : '1=1';

        $itemSql = "
            SELECT 
                COALESCE(SUM($qtyExpr),0) AS delivered_qty,
                MAX(si.item_id) AS item_id,
                COALESCE(MAX($itemNameSql),'19 Liter Bottle') AS bottle_type
            FROM sale_items si
            LEFT JOIN items i ON i.id = si.item_id
            WHERE si.sale_id = ? AND $condition
        ";
        $itemStmt = $conn->prepare($itemSql);
        if ($itemStmt) {
            $itemStmt->bind_param('i', $sale_id);
            $itemStmt->execute();
            $item = $itemStmt->get_result()->fetch_assoc();
            $delivered = (int)($item['delivered_qty'] ?? 0);
            $bottleType = !empty($item['bottle_type']) ? $item['bottle_type'] : '19 Liter Bottle';
            $itemId = !empty($item['item_id']) ? (int)$item['item_id'] : null;
        }
    }

    $retSql = "
        SELECT 
            COALESCE(SUM(returned_qty),0) AS already_returned,
            COALESCE(SUM(damaged_qty),0) AS already_damaged,
            COALESCE(SUM(lost_qty),0) AS already_lost
        FROM empty_bottle_returns
        WHERE sale_id = ? AND client_id = ?
    ";
    $retStmt = $conn->prepare($retSql);
    $retStmt->bind_param('ii', $sale_id, $client_id);
    $retStmt->execute();
    $ret = $retStmt->get_result()->fetch_assoc();

    $alreadyReturned = (int)($ret['already_returned'] ?? 0);
    $alreadyDamaged  = (int)($ret['already_damaged'] ?? 0);
    $alreadyLost     = (int)($ret['already_lost'] ?? 0);
    $pending = max(0, $delivered - ($alreadyReturned + $alreadyDamaged + $alreadyLost));

    $order['item_id'] = $itemId;
    $order['bottle_type'] = $bottleType;
    $order['delivered_qty'] = $delivered;
    $order['already_returned'] = $alreadyReturned;
    $order['already_damaged'] = $alreadyDamaged;
    $order['already_lost'] = $alreadyLost;
    $order['pending_qty'] = $pending;

    return ['success'=>true, 'order'=>$order];
}

/* ================= AJAX BACKEND INSIDE SAME FILE ================= */
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'fetch_order') {
        echo json_encode(getOrderBottleData($conn, (string)($_GET['order_key'] ?? $_GET['order_id'] ?? ''), $client_id, $branch_id));
        exit;
    }

    if ($action === 'save_return') {
        $sale_id       = (int)($_POST['sale_id'] ?? 0);
        $customer_id   = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $item_id       = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
        $bottle_type   = trim($_POST['bottle_type'] ?? '19 Liter Bottle');
        $delivered_qty = (int)($_POST['delivered_qty'] ?? 0);
        $returned_qty  = (int)($_POST['returned_qty'] ?? 0);
        $damaged_qty   = (int)($_POST['damaged_qty'] ?? 0);
        $lost_qty      = (int)($_POST['lost_qty'] ?? 0);
        $deposit_amt   = (float)($_POST['deposit_amount'] ?? 0);
        $deposit_adj   = (float)($_POST['deposit_adjustment'] ?? 0);
        $driver_name   = trim($_POST['driver_name'] ?? '');
        $route_name    = trim($_POST['route_name'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');
        $return_date   = trim($_POST['return_date'] ?? date('Y-m-d'));

        if ($sale_id <= 0) {
            echo json_encode(['success'=>false,'message'=>'Order ID fetch pehle karo.']); exit;
        }
        if (($returned_qty + $damaged_qty + $lost_qty) <= 0) {
            echo json_encode(['success'=>false,'message'=>'Returned, damaged ya lost quantity enter karo.']); exit;
        }

        $old = getOrderBottleData($conn, (string)$sale_id, $client_id, $branch_id);
        if (!$old['success']) { echo json_encode($old); exit; }
        $pending = (int)$old['order']['pending_qty'];
        if (($returned_qty + $damaged_qty + $lost_qty) > $pending && $pending > 0) {
            echo json_encode(['success'=>false,'message'=>'Return quantity pending bottles se zyada nahi ho sakti. Pending: '.$pending]); exit;
        }

        $newClosed = $returned_qty + $damaged_qty + $lost_qty;
        $remainingAfter = max(0, $pending - $newClosed);
        $status = $remainingAfter <= 0 ? 'completed' : 'partial';
        if ($newClosed <= 0) $status = 'pending';

        $sql = "
            INSERT INTO empty_bottle_returns
            (client_id, branch_id, sale_id, customer_id, item_id, bottle_type, delivered_qty, returned_qty, damaged_qty, lost_qty, deposit_amount, deposit_adjustment, driver_name, route_name, return_date, status, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) { echo json_encode(['success'=>false,'message'=>$conn->error]); exit; }
        $stmt->bind_param(
            'iiiiisiiiiddsssssi',
            $client_id, $branch_id, $sale_id, $customer_id, $item_id, $bottle_type,
            $delivered_qty, $returned_qty, $damaged_qty, $lost_qty,
            $deposit_amt, $deposit_adj, $driver_name, $route_name, $return_date, $status, $notes, $user_id
        );

        if (!$stmt->execute()) { echo json_encode(['success'=>false,'message'=>$stmt->error]); exit; }
        echo json_encode(['success'=>true,'message'=>'Bottle return saved successfully.']);
        exit;
    }

    if ($action === 'delete_return') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid return ID']); exit; }
        $stmt = $conn->prepare("DELETE FROM empty_bottle_returns WHERE id=? AND client_id=?");
        $stmt->bind_param('ii', $id, $client_id);
        if (!$stmt->execute()) { echo json_encode(['success'=>false,'message'=>$stmt->error]); exit; }
        echo json_encode(['success'=>true,'message'=>'Record deleted successfully.']);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Invalid AJAX action.']);
    exit;
}

/* ================= NORMAL PAGE BACKEND ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $selected = $_POST['selected_returns'] ?? [];
    if (!empty($selected) && is_array($selected)) {
        $conn->begin_transaction();
        try {
            foreach ($selected as $id) {
                $id = (int)$id;
                $stmt = $conn->prepare("DELETE FROM empty_bottle_returns WHERE id=? AND client_id=?");
                $stmt->bind_param('ii', $id, $client_id);
                $stmt->execute();
            }
            $conn->commit();
            $_SESSION['success_message'] = 'Selected bottle return records deleted successfully.';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error_message'] = 'Failed to delete selected records.';
        }
    } else {
        $_SESSION['error_message'] = 'Please select at least one record.';
    }
    header('Location: empty_bottles.php');
    exit;
}

$rows = getEmptyBottleRows($conn, $client_id, $branch_id);
$stats = getStats($rows);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Empty Bottles Return</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<?php include('partials/head.php'); ?>
<style>
body{margin:0;background:#f3f4f8;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif}.container-scroll{display:flex;min-height:100vh}.sidebar{width:250px;flex-shrink:0}.main-panel{flex-grow:1;display:flex;flex-direction:column;min-width:0}.topbar{height:60px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;padding:0 20px;background:#fff}.content-wrapper{padding:20px;flex-grow:1;overflow-y:auto}.page-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:18px}.page-title{font-size:28px;font-weight:700;color:#1f2937;margin:0}.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.stat-card{color:#fff;border-radius:10px;padding:16px 18px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 8px 18px rgba(0,0,0,.08)}.stat-card .title{font-size:15px;font-weight:600}.stat-card .count{font-size:32px;font-weight:800;line-height:1}.card-blue{background:linear-gradient(135deg,#314a93,#405da8)}.card-green{background:linear-gradient(135deg,#5a9b42,#73b552)}.card-cyan{background:linear-gradient(135deg,#62b1bf,#7bc8d5)}.card-yellow{background:linear-gradient(135deg,#d9a615,#f3be2d)}.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px}.search-wrap{flex:1.3;min-width:220px;position:relative}.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#6b7280}.search-wrap input{width:100%;height:44px;border:1px solid #e5e7eb;border-radius:10px;padding:0 14px 0 38px;outline:none}.filter-control{min-width:170px;height:44px;border:1px solid #e5e7eb;border-radius:10px;padding:0 12px;outline:none;background:#fff}.filter-btn,.add-btn{height:44px;border:none;border-radius:10px;padding:0 18px;background:#1f2937;color:#fff;font-weight:700}.add-btn{background:#198754}.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}.table-responsive{overflow:auto}.orders-table{width:100%;min-width:1250px;border-collapse:collapse}.orders-table thead th{background:#28344d;color:#fff;font-weight:600;font-size:14px;padding:14px 12px;border-right:1px solid rgba(255,255,255,.08);white-space:nowrap}.orders-table tbody td{padding:14px 12px;border-top:1px solid #edf0f5;vertical-align:middle;font-size:14px;color:#374151}.orders-table tbody tr:hover{background:#fafbfc}.invoice-link{color:#2f5ca8;font-weight:700;text-decoration:none}.badge-type{padding:6px 12px;border-radius:7px;font-size:12px;font-weight:700;display:inline-block}.badge-pending{background:#fee2e2;color:#991b1b}.badge-partial{background:#ffedd5;color:#9a3412}.badge-completed{background:#dcfce7;color:#166534}.actions{display:flex;gap:10px;align-items:center;justify-content:center}.actions button{width:38px;height:38px;border:none;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:16px;cursor:pointer;transition:all .18s ease;box-shadow:0 4px 10px rgba(15,23,42,.06)}.actions button:hover{transform:translateY(-2px)}.action-view{background:#efeaff;color:#7c61f3}.action-return{background:#e9f9ef;color:#16a34a}.action-delete{background:#fdecec;color:#dc2626}.bottom-actions{padding:14px;border-top:1px solid #e5e7eb;background:#fff}.delete-btn{background:#f2b90f;color:#111827;border:none;border-radius:10px;padding:12px 18px;font-weight:700}.alert-custom{padding:12px 14px;border-radius:10px;font-size:14px;font-weight:600;margin-bottom:14px}.alert-success-custom{background:#e8f7ec;color:#17663c;border:1px solid #b8e2c2}.alert-danger-custom{background:#fdecec;color:#a32929;border:1px solid #f4c1c1}.modal-header{background:#17243d;color:#fff}.modal-header .btn-close{filter:invert(1)}.readonly-box{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px}.readonly-box label{font-size:12px;color:#6b7280;font-weight:700;margin-bottom:3px}.readonly-box div{font-size:15px;color:#111827;font-weight:700}.pending-box{background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;border-radius:12px;padding:14px}.pending-box .num{font-size:34px;font-weight:900;color:#1d4ed8}@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr)}}@media(max-width:768px){.stats-row{grid-template-columns:1fr}.page-head{align-items:flex-start;flex-direction:column}}

/* =====================================================
   EMPTY_BOTTLES.PHP DASHBOARD DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.14);
    --oms-border-2:rgba(148,163,184,.22);
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
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* Layout + Sidebar */
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
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
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
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
    transition:margin-left .25s ease;
}

/* Sidebar collapsed */
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

/* Topbar */
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

/* Content background */
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
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
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

/* Header */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

/* Stats cards */
.stats-row{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
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
    justify-content:space-between !important;
    transition:.25s ease !important;
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
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card .title{
    font-size:15px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
}

.stat-card .count{
    font-size:30px !important;
    font-weight:900 !important;
    line-height:1 !important;
    color:#fff !important;
    position:relative;
    z-index:2;
}

.card-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.card-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.card-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.card-yellow{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

/* Glass panels */
.filter-bar,
.table-card{
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
}

.filter-bar:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-bar{
    padding:14px !important;
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    align-items:center !important;
    margin-bottom:16px !important;
}

/* Inputs */
.search-wrap input,
.filter-control{
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

.search-wrap input{
    padding:0 14px 0 40px !important;
}

.search-wrap i{
    color:#94a3b8 !important;
}

.search-wrap input::placeholder,
.filter-control::placeholder{
    color:#94a3b8 !important;
}

.search-wrap input:focus,
.filter-control:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-control option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Buttons */
.add-btn,
.filter-btn{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    transition:.22s ease !important;
}

.add-btn{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
}

.filter-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.add-btn:hover,
.filter-btn:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* Table */
.table-card{
    overflow:hidden !important;
}

.orders-table{
    width:100% !important;
    min-width:1250px !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
}

.orders-table thead th{
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

.orders-table tbody td{
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

.orders-table tbody tr:hover td{
    background:rgba(124,58,237,.12) !important;
    color:#fff !important;
}

.invoice-link{
    color:#a78bfa !important;
    font-weight:900 !important;
}

.orders-table small{
    color:#94a3b8 !important;
}

.bottom-actions{
    padding:14px !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    background:rgba(7,11,20,.28) !important;
}

.delete-btn{
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#fff !important;
    border:none !important;
    border-radius:12px !important;
    padding:12px 18px !important;
    font-weight:900 !important;
}

/* Badges */
.badge-type{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.badge-pending{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

.badge-partial{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.badge-completed{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.badge-type.retail{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
}

/* Action buttons */
.actions button{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:12px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.action-view{
    background:rgba(109,76,255,.18) !important;
    color:#c4b5fd !important;
}

.action-return{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
}

.action-delete{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
}

/* Alerts */
.alert-custom{
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.58)) !important;
    color:#e5e7eb !important;
    box-shadow:0 16px 36px rgba(0,0,0,.26);
}

.alert-success-custom{
    border-color:rgba(34,197,94,.25) !important;
    color:#4ade80 !important;
}

.alert-danger-custom{
    border-color:rgba(239,68,68,.25) !important;
    color:#f87171 !important;
}

/* =====================================================
   EMPTY BOTTLES POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

#returnModal .modal-dialog{
    max-width:min(820px,94vw) !important;
}

#viewModal .modal-dialog{
    max-width:min(620px,94vw) !important;
}

#returnModal .modal-content,
#viewModal .modal-content{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#returnModal .modal-header,
#viewModal .modal-header{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
}

#returnModal .modal-title,
#viewModal .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#returnModal .modal-title::before,
#viewModal .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#returnModal .btn-close,
#viewModal .btn-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#returnModal .btn-close::before,
#returnModal .btn-close::after,
#viewModal .btn-close::before,
#viewModal .btn-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:18px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#returnModal .btn-close::before,
#viewModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#returnModal .btn-close::after,
#viewModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#returnModal .btn-close:hover,
#viewModal .btn-close:hover{
    background:#ef4444 !important;
}

#returnModal .modal-body,
#viewModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#returnModal .row.g-3{
    --bs-gutter-x:12px !important;
    --bs-gutter-y:10px !important;
}

#returnModal .form-label,
#returnModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#returnModal .form-control,
#returnModal input[type="text"],
#returnModal input[type="number"],
#returnModal input[type="date"],
#returnModal select{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    min-height:40px !important;
    height:40px !important;
    padding:7px 12px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#returnModal input[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

#returnModal .form-control:focus,
#returnModal input:focus,
#returnModal select:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#returnModal .btn-dark{
    height:40px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#returnModal .pending-box{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:14px !important;
    padding:14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#returnModal .pending-box .text-muted{
    color:#94a3b8 !important;
}

#returnModal .pending-box .num{
    color:#a78bfa !important;
    font-size:30px !important;
    font-weight:900 !important;
}

#returnModal .readonly-box{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#returnModal .readonly-box label{
    color:#94a3b8 !important;
    font-size:11px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    margin-bottom:4px !important;
}

#returnModal .readonly-box div{
    color:#f8fafc !important;
    font-size:14px !important;
    font-weight:900 !important;
}

#returnModal .modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#returnModal .btn-secondary{
    min-height:38px !important;
    height:38px !important;
    min-width:105px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
}

#returnModal .btn-success{
    min-height:38px !important;
    height:38px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

/* View detail modal boxes */
#viewModal #viewBody .col-6,
#viewModal #viewBody .col-12{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    margin-bottom:10px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#viewModal #viewBody b{
    color:#94a3b8 !important;
    font-size:12px !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}


/* =====================================================
   FIX TABLE WHITE HOVER / WHITE EMPTY ROW
   Empty Bottles Table Transparent Glass
===================================================== */

.table-card,
.table-responsive,
.orders-table,
.orders-table tbody,
.orders-table tbody tr{
    background:transparent !important;
}

.orders-table tbody td{
    background:rgba(15,23,42,.20) !important;
    color:#e5e7eb !important;
    border-top:none !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

/* Hover white remove */
.orders-table tbody tr:hover,
.orders-table tbody tr:hover td,
.orders-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

/* Click / focus / active white remove */
.orders-table tbody tr:focus,
.orders-table tbody tr:active,
.orders-table tbody td:focus,
.orders-table tbody td:active,
.orders-table tbody td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

/* Empty record row bhi white na ho */
.orders-table tbody tr td[colspan],
.orders-table tbody tr td.text-center,
.orders-table tbody tr td.py-4{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

/* Browser text selection ka white/blue effect control */
.orders-table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

/* Checkbox column white remove */
.orders-table th:first-child,
.orders-table td:first-child{
    background:rgba(15,23,42,.22) !important;
}

/* Horizontal scrollbar area ka white look reduce */
.table-responsive::-webkit-scrollbar{
    height:10px;
}

.table-responsive::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-responsive::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-responsive::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}
</style>
</head>
<body>
<div class="container-scroll">
    <div class="sidebar" id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">
            <div class="page-head">
                <h4 class="page-title"><i class="fa-solid fa-arrow-rotate-left me-2"></i>Empty Bottles Return</h4>
                <button class="add-btn" type="button" data-bs-toggle="modal" data-bs-target="#returnModal" onclick="resetReturnForm()">
                    <i class="fa-solid fa-plus me-1"></i> New Bottle Return
                </button>
            </div>

            <?php if(!empty($_SESSION['success_message'])): ?>
                <div class="alert-custom alert-success-custom"><?= e($_SESSION['success_message']) ?></div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if(!empty($_SESSION['error_message'])): ?>
                <div class="alert-custom alert-danger-custom"><?= e($_SESSION['error_message']) ?></div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <div class="stats-row mb-3">
                <div class="stat-card card-blue"><div><i class="fa-solid fa-bottle-water me-2"></i><span class="title">Total Pending Bottles</span></div><span class="count"><?= (int)$stats['pending'] ?></span></div>
                <div class="stat-card card-green"><div><i class="fa-solid fa-check me-2"></i><span class="title">Returned Bottles</span></div><span class="count"><?= (int)$stats['returned'] ?></span></div>
                <div class="stat-card card-cyan"><div><i class="fa-solid fa-triangle-exclamation me-2"></i><span class="title">Damaged Bottles</span></div><span class="count"><?= (int)$stats['damaged'] ?></span></div>
                <div class="stat-card card-yellow"><div><i class="fa-solid fa-users me-2"></i><span class="title">Customers Pending</span></div><span class="count"><?= (int)$stats['customers_pending'] ?></span></div>
            </div>

            <div class="filter-bar">
                <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="searchRows" placeholder="Search customer, phone, order no"></div>
                <input type="text" id="filterOrderNo" class="filter-control" placeholder="Filter by Order #">
                <input type="text" id="filterCustomer" class="filter-control" placeholder="Filter by Customer">
                <input type="date" id="filterDate" class="filter-control">
                <select id="filterStatus" class="filter-control">
                    <option value="">All Status</option><option value="pending">Pending</option><option value="partial">Partial</option><option value="completed">Completed</option>
                </select>
                <button class="filter-btn" type="button" onclick="applyFilters()"><i class="fa-solid fa-filter me-1"></i> Filter</button>
            </div>

            <div class="table-card">
                <form method="POST" id="returnsForm">
                    <div class="table-responsive">
                        <table class="orders-table" id="returnsTable">
                            <thead><tr><th style="width:50px"><input type="checkbox" id="selectAll"></th><th>Order</th><th>Date</th><th>Customer</th><th>Phone</th><th>Route</th><th>Bottle Type</th><th>Delivered</th><th>Returned</th><th>Pending</th><th>Damaged</th><th>Lost</th><th>Deposit</th><th>Driver</th><th>Status</th><th width="160" class="text-center">Actions</th></tr></thead>
                            <tbody>
                            <?php if(!empty($rows)): foreach($rows as $r):
                                $delivered=(int)($r['delivered_qty']??0); $returned=(int)($r['returned_qty']??0); $damaged=(int)($r['damaged_qty']??0); $lost=(int)($r['lost_qty']??0);
                                $pending=max(0,$delivered-($returned+$damaged+$lost));
                                $status=strtolower($r['status']??'pending');
                                $badge='badge-pending'; if($status==='partial') $badge='badge-partial'; if($status==='completed') $badge='badge-completed';
                                $date=!empty($r['return_date'])?$r['return_date']:date('Y-m-d', strtotime($r['created_at']??date('Y-m-d')));
                            ?>
                            <tr class="return-row" data-order="<?= e(strtolower($r['order_no'] ?? '')) ?>" data-customer="<?= e(strtolower($r['customer_name'] ?? '')) ?>" data-date="<?= e($date) ?>" data-status="<?= e($status) ?>">
                                <td><input type="checkbox" name="selected_returns[]" value="<?= (int)$r['id'] ?>" class="row-checkbox"></td>
                                <td><span class="invoice-link"><?= e($r['order_no'] ?? ('ORD-'.$r['sale_id'])) ?></span><br><small>Sale ID: <?= (int)$r['sale_id'] ?></small></td>
                                <td><?= e(date('d-m-Y', strtotime($date))) ?></td>
                                <td><span class="badge-type retail"><?= e($r['customer_name'] ?: 'Walk-In') ?></span></td>
                                <td><?= e($r['customer_phone'] ?? '') ?></td>
                                <td><?= e($r['route_name'] ?? '') ?></td>
                                <td><?= e($r['bottle_type'] ?? '19 Liter Bottle') ?></td>
                                <td><b><?= $delivered ?></b></td><td><b><?= $returned ?></b></td><td><b><?= $pending ?></b></td><td><?= $damaged ?></td><td><?= $lost ?></td>
                                <td>Rs<?= number_format((float)($r['deposit_amount'] ?? 0),2) ?></td>
                                <td><?= e($r['driver_name'] ?? '') ?></td>
                                <td><span class="badge-type <?= $badge ?>"><?= e(ucfirst($status)) ?></span></td>
                                <td><div class="actions"><button type="button" class="action-view" onclick='viewRow(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' title="View"><i class="fa-solid fa-eye"></i></button><button type="button" class="action-return" onclick="openReturnForOrder(<?= (int)$r['sale_id'] ?>)" title="Add Return"><i class="fa-solid fa-rotate-left"></i></button><button type="button" class="action-delete" onclick="deleteReturn(<?= (int)$r['id'] ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button></div></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="16" class="text-center py-4">No empty bottle return records found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="bottom-actions"><button type="submit" name="delete_selected" class="delete-btn">Delete Selected</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="returnModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-bottle-water me-2"></i>Add Bottle Return By Invoice ID</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="bottleReturnForm">
          <input type="hidden" name="sale_id" id="sale_id"><input type="hidden" name="customer_id" id="customer_id"><input type="hidden" name="item_id" id="item_id">
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label fw-bold">Invoice ID / Sale ID</label><input type="text" id="order_id_search" class="form-control" placeholder="Invoice No ya Sale ID likho e.g. INV-WAT-001 / 18"></div>
            <div class="col-md-4 d-flex align-items-end"><button type="button" class="btn btn-dark w-100" onclick="fetchOrderBottleData()"><i class="fa-solid fa-search me-1"></i> Fetch Order</button></div>
          </div>
          <div id="orderFetchBox" style="display:none" class="mt-3">
            <div class="pending-box mb-3"><div class="text-muted fw-bold">Remaining Pending Bottles</div><div class="num" id="pending_qty_text">0</div></div>
            <div class="row g-3">
              <div class="col-md-4"><div class="readonly-box"><label>Customer</label><div id="customer_name_text">-</div></div></div>
              <div class="col-md-4"><div class="readonly-box"><label>Phone</label><div id="phone_text">-</div></div></div>
              <div class="col-md-4"><div class="readonly-box"><label>Order No</label><div id="order_no_text">-</div></div></div>
              <div class="col-md-6"><label class="form-label fw-semibold">Route</label><input type="text" name="route_name" id="route_name" class="form-control"></div>
              <div class="col-md-6"><label class="form-label fw-semibold">Bottle Type</label><input type="text" name="bottle_type" id="bottle_type" class="form-control"></div>
              <div class="col-md-3"><label class="form-label fw-semibold">Delivered</label><input type="number" name="delivered_qty" id="delivered_qty" class="form-control" readonly></div>
              <div class="col-md-3"><label class="form-label fw-semibold">Already Returned</label><input type="number" id="already_returned" class="form-control" readonly></div>
              <div class="col-md-3"><label class="form-label fw-semibold">Returned Now</label><input type="number" name="returned_qty" id="returned_qty" class="form-control" value="0" min="0"></div>
              <div class="col-md-3"><label class="form-label fw-semibold">Damaged</label><input type="number" name="damaged_qty" id="damaged_qty" class="form-control" value="0" min="0"></div>
              <div class="col-md-3"><label class="form-label fw-semibold">Lost</label><input type="number" name="lost_qty" id="lost_qty" class="form-control" value="0" min="0"></div>
              <div class="col-md-3"><label class="form-label fw-semibold">Deposit Amount</label><input type="number" step="0.01" name="deposit_amount" id="deposit_amount" class="form-control" value="0"></div>
              <div class="col-md-3"><label class="form-label fw-semibold">Deposit Adjustment</label><input type="number" step="0.01" name="deposit_adjustment" id="deposit_adjustment" class="form-control" value="0"></div>
              <div class="col-md-3"><label class="form-label fw-semibold">Return Date</label><input type="date" name="return_date" id="return_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
              <div class="col-md-6"><label class="form-label fw-semibold">Driver</label><input type="text" name="driver_name" id="driver_name" class="form-control" placeholder="Driver name"></div>
              <div class="col-md-6"><label class="form-label fw-semibold">Notes</label><input type="text" name="notes" id="notes" class="form-control" placeholder="Optional notes"></div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" onclick="saveBottleReturn()"><i class="fa-solid fa-check me-1"></i> Save Return</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Bottle Return Detail</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="viewBody"></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const pageUrl = '<?= basename(__FILE__) ?>';
function resetReturnForm(){document.getElementById('bottleReturnForm').reset();document.getElementById('orderFetchBox').style.display='none';document.getElementById('return_date').value='<?= date('Y-m-d') ?>';}
function openReturnForOrder(id){resetReturnForm();document.getElementById('order_id_search').value=id;new bootstrap.Modal(document.getElementById('returnModal')).show();fetchOrderBottleData();}
function fetchOrderBottleData(){let orderId=document.getElementById('order_id_search').value.trim();if(!orderId){alert('Invoice ID / Sale ID enter karo');return;}fetch(pageUrl+'?ajax=1&action=fetch_order&order_key='+encodeURIComponent(orderId)).then(r=>r.json()).then(data=>{if(!data.success){alert(data.message);return;}let o=data.order;document.getElementById('sale_id').value=o.sale_id||'';document.getElementById('customer_id').value=o.customer_id||'';document.getElementById('item_id').value=o.item_id||'';document.getElementById('customer_name_text').innerText=o.customer_name||'Walk-In';document.getElementById('phone_text').innerText=o.phone||'-';document.getElementById('order_no_text').innerText=o.order_no||'-';document.getElementById('pending_qty_text').innerText=o.pending_qty||0;document.getElementById('route_name').value=o.route_name||'';document.getElementById('bottle_type').value=o.bottle_type||'19 Liter Bottle';document.getElementById('delivered_qty').value=o.delivered_qty||0;document.getElementById('already_returned').value=o.already_returned||0;document.getElementById('deposit_amount').value=o.deposit_amount||0;document.getElementById('driver_name').value=o.driver_name||'';document.getElementById('orderFetchBox').style.display='block';}).catch(()=>alert('Server error while fetching order'));}
function saveBottleReturn(){let fd=new FormData(document.getElementById('bottleReturnForm'));fd.append('ajax','1');fd.append('action','save_return');fetch(pageUrl,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{alert(data.message);if(data.success) location.reload();}).catch(()=>alert('Server error while saving return'));}
function deleteReturn(id){if(!confirm('Delete this return record?'))return;let fd=new FormData();fd.append('ajax','1');fd.append('action','delete_return');fd.append('id',id);fetch(pageUrl,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{alert(data.message);if(data.success) location.reload();});}
function viewRow(r){let html=`<div class="row g-2"><div class="col-6"><b>Order:</b> ${r.order_no||'ORD-'+r.sale_id}</div><div class="col-6"><b>Customer:</b> ${r.customer_name||'Walk-In'}</div><div class="col-6"><b>Bottle:</b> ${r.bottle_type||''}</div><div class="col-6"><b>Route:</b> ${r.route_name||''}</div><div class="col-6"><b>Delivered:</b> ${r.delivered_qty||0}</div><div class="col-6"><b>Returned:</b> ${r.returned_qty||0}</div><div class="col-6"><b>Damaged:</b> ${r.damaged_qty||0}</div><div class="col-6"><b>Lost:</b> ${r.lost_qty||0}</div><div class="col-6"><b>Driver:</b> ${r.driver_name||''}</div><div class="col-6"><b>Status:</b> ${r.status||''}</div><div class="col-12"><b>Notes:</b> ${r.notes||''}</div></div>`;document.getElementById('viewBody').innerHTML=html;new bootstrap.Modal(document.getElementById('viewModal')).show();}
function applyFilters(){let s=document.getElementById('searchRows').value.toLowerCase().trim(),o=document.getElementById('filterOrderNo').value.toLowerCase().trim(),c=document.getElementById('filterCustomer').value.toLowerCase().trim(),d=document.getElementById('filterDate').value,st=document.getElementById('filterStatus').value;document.querySelectorAll('.return-row').forEach(row=>{let ro=row.dataset.order||'',rc=row.dataset.customer||'',rd=row.dataset.date||'',rs=row.dataset.status||'';let show=true;if(s&&!(ro.includes(s)||rc.includes(s)))show=false;if(o&&!ro.includes(o))show=false;if(c&&!rc.includes(c))show=false;if(d&&rd!==d)show=false;if(st&&rs!==st)show=false;row.style.display=show?'table-row':'none';});}
['searchRows','filterOrderNo','filterCustomer'].forEach(id=>document.getElementById(id).addEventListener('keyup',applyFilters));['filterDate','filterStatus'].forEach(id=>document.getElementById(id).addEventListener('change',applyFilters));document.getElementById('selectAll').addEventListener('change',function(){document.querySelectorAll('.row-checkbox').forEach(chk=>chk.checked=this.checked);});document.getElementById('returnsForm').addEventListener('submit',function(e){let checked=document.querySelectorAll('.row-checkbox:checked');if(checked.length===0){e.preventDefault();alert('Please select at least one record.');return false;}if(!confirm('Are you sure you want to delete selected records?')){e.preventDefault();return false;}});
</script>
</body>
</html>
