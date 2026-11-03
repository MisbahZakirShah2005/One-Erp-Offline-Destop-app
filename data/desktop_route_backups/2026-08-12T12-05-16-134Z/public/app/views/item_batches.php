<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
include('partials/performance.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
if ($client_id <= 0) {
    die("Invalid client session");
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_with_message(string $type, string $message): void
{
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message) . "&msg_type=" . urlencode($type));
    exit;
}

function normalize_status(string $status): string
{
    return in_array($status, ['active', 'disabled'], true) ? $status : 'active';
}

function batch_freshness_label(?string $expiryDate): array
{
    if (!$expiryDate) {
        return ['label' => 'Fresh', 'class' => 'fresh', 'days' => null];
    }

    $today = new DateTime(date('Y-m-d'));
    $exp = new DateTime($expiryDate);
    $diff = (int)$today->diff($exp)->format('%r%a');

    if ($diff < 0) {
        return ['label' => 'Expired', 'class' => 'expired', 'days' => abs($diff)];
    }

    if ($diff <= 30) {
        return ['label' => 'Near Expiry', 'class' => 'near-expiry', 'days' => $diff];
    }

    return ['label' => 'Fresh', 'class' => 'fresh', 'days' => $diff];
}

function generate_batch_no(mysqli $conn): string
{
    $stmt = $conn->prepare("SELECT MAX(id) AS max_id FROM item_batches");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = ((int)($row['max_id'] ?? 0)) + 1;
    return 'BTH-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

/* ===================== HANDLE POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'add_batch') {
            $item_id            = (int)($_POST['item_id'] ?? 0);
            $batch_no           = trim($_POST['batch_no'] ?? '');
            $qty                = (int)($_POST['qty'] ?? 0);
            $branch             = trim($_POST['branch'] ?? '');
            $supplier           = trim($_POST['supplier'] ?? '');
            $purchase_order_id  = (int)($_POST['purchase_order_id'] ?? 0);
            $mfg_date           = trim($_POST['mfg_date'] ?? '') ?: null;
            $expiry_date        = trim($_POST['expiry_date'] ?? '') ?: null;
            $reserved_qty       = (int)($_POST['reserved_qty'] ?? 0);
            $sold_qty           = (int)($_POST['sold_qty'] ?? 0);
            $cost_price         = (float)($_POST['cost_price'] ?? 0);
            $sale_price         = (float)($_POST['sale_price'] ?? 0);
            $notes              = trim($_POST['notes'] ?? '');
            $is_tracking        = isset($_POST['is_tracking_enabled']) ? 1 : 0;
            $status             = normalize_status(trim($_POST['status'] ?? 'active'));

            if ($item_id <= 0 || $batch_no === '' || $qty < 0 || $branch === '') {
                redirect_with_message('danger', 'Please fill all required fields.');
            }

            $checkStmt = $conn->prepare("SELECT id FROM item_batches WHERE client_id = ? AND batch_no = ? LIMIT 1");
            $checkStmt->bind_param("is", $client_id, $batch_no);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if ($exists) {
                redirect_with_message('danger', 'This batch number already exists.');
            }

            $stmt = $conn->prepare("
                INSERT INTO item_batches
                (client_id, item_id, batch_no, branch, supplier, purchase_order_id, mfg_date, expiry_date, qty, reserved_qty, sold_qty, cost_price, sale_price, notes, is_tracking_enabled, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iisssissiiiddsis",
                $client_id,
                $item_id,
                $batch_no,
                $branch,
                $supplier,
                $purchase_order_id,
                $mfg_date,
                $expiry_date,
                $qty,
                $reserved_qty,
                $sold_qty,
                $cost_price,
                $sale_price,
                $notes,
                $is_tracking,
                $status
            );
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Batch added successfully.');
        }

        if ($action === 'update_batch') {
            $id                 = (int)($_POST['id'] ?? 0);
            $item_id            = (int)($_POST['item_id'] ?? 0);
            $batch_no           = trim($_POST['batch_no'] ?? '');
            $qty                = (int)($_POST['qty'] ?? 0);
            $branch             = trim($_POST['branch'] ?? '');
            $supplier           = trim($_POST['supplier'] ?? '');
            $purchase_order_id  = (int)($_POST['purchase_order_id'] ?? 0);
            $mfg_date           = trim($_POST['mfg_date'] ?? '') ?: null;
            $expiry_date        = trim($_POST['expiry_date'] ?? '') ?: null;
            $reserved_qty       = (int)($_POST['reserved_qty'] ?? 0);
            $sold_qty           = (int)($_POST['sold_qty'] ?? 0);
            $cost_price         = (float)($_POST['cost_price'] ?? 0);
            $sale_price         = (float)($_POST['sale_price'] ?? 0);
            $notes              = trim($_POST['notes'] ?? '');
            $is_tracking        = isset($_POST['is_tracking_enabled']) ? 1 : 0;
            $status             = normalize_status(trim($_POST['status'] ?? 'active'));

            if ($id <= 0 || $item_id <= 0 || $batch_no === '' || $qty < 0 || $branch === '') {
                redirect_with_message('danger', 'Invalid batch data.');
            }

            $checkStmt = $conn->prepare("SELECT id FROM item_batches WHERE client_id = ? AND batch_no = ? AND id != ? LIMIT 1");
            $checkStmt->bind_param("isi", $client_id, $batch_no, $id);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if ($exists) {
                redirect_with_message('danger', 'Another batch already has this number.');
            }

            $stmt = $conn->prepare("
                UPDATE item_batches
                SET item_id = ?, batch_no = ?, branch = ?, supplier = ?, purchase_order_id = ?, mfg_date = ?, expiry_date = ?, qty = ?, reserved_qty = ?, sold_qty = ?, cost_price = ?, sale_price = ?, notes = ?, is_tracking_enabled = ?, status = ?
                WHERE id = ? AND client_id = ?
            ");
            $stmt->bind_param(
                "isssissiiiddsisii",
                $item_id,
                $batch_no,
                $branch,
                $supplier,
                $purchase_order_id,
                $mfg_date,
                $expiry_date,
                $qty,
                $reserved_qty,
                $sold_qty,
                $cost_price,
                $sale_price,
                $notes,
                $is_tracking,
                $status,
                $id,
                $client_id
            );
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Batch updated successfully.');
        }

        if ($action === 'delete_batch') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                redirect_with_message('danger', 'Invalid batch ID.');
            }

            $stmt = $conn->prepare("DELETE FROM item_batches WHERE id = ? AND client_id = ?");
            $stmt->bind_param("ii", $id, $client_id);
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Batch deleted successfully.');
        }

        if ($action === 'bulk_delete') {
            $ids = $_POST['selected_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                redirect_with_message('danger', 'Please select at least one batch.');
            }

            $cleanIds = [];
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) $cleanIds[] = $id;
            }

            if (empty($cleanIds)) {
                redirect_with_message('danger', 'No valid batches selected.');
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $types = str_repeat('i', count($cleanIds)) . 'i';
            $params = array_merge($cleanIds, [$client_id]);

            $sql = "DELETE FROM item_batches WHERE id IN ($placeholders) AND client_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $deletedRows = $stmt->affected_rows;
            $stmt->close();

            redirect_with_message('success', $deletedRows . ' batch(es) deleted successfully.');
        }

        if ($action === 'mark_expired') {
            $ids = $_POST['selected_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                redirect_with_message('danger', 'Please select at least one batch.');
            }

            $cleanIds = [];
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) $cleanIds[] = $id;
            }

            if (empty($cleanIds)) {
                redirect_with_message('danger', 'No valid batches selected.');
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $sql = "UPDATE item_batches SET status = ? WHERE id IN ($placeholders) AND client_id = ?";
            $stmt = $conn->prepare($sql);

            $reorderedTypes = 's' . str_repeat('i', count($cleanIds)) . 'i';
            $reorderedParams = array_merge(['disabled'], $cleanIds, [$client_id]);

            $stmt->bind_param($reorderedTypes, ...$reorderedParams);
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Selected batches marked expired.');
        }

        if ($action === 'enable_disable_batch') {
            $id = (int)($_POST['id'] ?? 0);
            $current_status = normalize_status($_POST['status'] ?? 'active');
            $newStatus = $current_status === 'active' ? 'disabled' : 'active';

            if ($id <= 0) {
                redirect_with_message('danger', 'Invalid batch data.');
            }

            $stmt = $conn->prepare("UPDATE item_batches SET status = ? WHERE id = ? AND client_id = ?");
            $stmt->bind_param("sii", $newStatus, $id, $client_id);
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', "Batch status updated to {$newStatus}.");
        }

    } catch (Throwable $e) {
        redirect_with_message('danger', $e->getMessage());
    }
}

/* ===================== FLASH ===================== */
$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ===================== FETCH DROPDOWNS ===================== */
$items = [];
$branches = [];
$suppliers = [];
$purchaseOrders = [];

$stmt = $conn->prepare("SELECT id, name FROM items WHERE client_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $items[] = $r;
$stmt->close();

$branchTableCheck = $conn->query("SHOW TABLES LIKE 'branches'");
if ($branchTableCheck && $branchTableCheck->num_rows > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $branches[] = $r;
    $stmt->close();
}

$supplierTableCheck = $conn->query("SHOW TABLES LIKE 'suppliers'");
if ($supplierTableCheck && $supplierTableCheck->num_rows > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM suppliers WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $suppliers[] = $r;
    $stmt->close();
}

$purchaseOrdersCheck = $conn->query("SHOW TABLES LIKE 'purchase_orders'");
if ($purchaseOrdersCheck && $purchaseOrdersCheck->num_rows > 0) {
    $supplierJoinCheck = $conn->query("SHOW TABLES LIKE 'suppliers'");

    if ($supplierJoinCheck && $supplierJoinCheck->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT 
                po.id,
                po.total_amount,
                po.created_at,
                po.supplier_id,
                COALESCE(s.name, 'Unknown Supplier') AS supplier_name
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            WHERE po.client_id = ?
            ORDER BY po.id DESC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT 
                po.id,
                po.total_amount,
                po.created_at,
                po.supplier_id,
                'Unknown Supplier' AS supplier_name
            FROM purchase_orders po
            WHERE po.client_id = ?
            ORDER BY po.id DESC
        ");
    }

    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $purchaseOrders[] = $r;
    $stmt->close();
}

/* ===================== FILTERS ===================== */
$itemFilter      = (int)($_GET['item_id'] ?? 0);
$batchNoFilter   = trim($_GET['batch_no'] ?? '');
$branchFilter    = trim($_GET['branch'] ?? '');
$statusFilter    = trim($_GET['status'] ?? '');
$expiryRange     = trim($_GET['expiry_range'] ?? '');

$sql = "
    SELECT 
        b.*, 
        i.name AS item_name,
        po.id AS po_id,
        po.total_amount AS po_total_amount,
        po.created_at AS po_created_at,
        COALESCE(ps.name, 'Unknown Supplier') AS po_supplier_name
    FROM item_batches b
    LEFT JOIN items i ON i.id = b.item_id
    LEFT JOIN purchase_orders po ON po.id = b.purchase_order_id
    LEFT JOIN suppliers ps ON ps.id = po.supplier_id
    WHERE b.client_id = ?
";
$params = [$client_id];
$types = "i";

if ($itemFilter > 0) {
    $sql .= " AND b.item_id = ?";
    $params[] = $itemFilter;
    $types .= "i";
}

if ($batchNoFilter !== '') {
    $sql .= " AND b.batch_no = ?";
    $params[] = $batchNoFilter;
    $types .= "s";
}

if ($branchFilter !== '') {
    $sql .= " AND b.branch = ?";
    $params[] = $branchFilter;
    $types .= "s";
}

if (in_array($statusFilter, ['active', 'disabled'], true)) {
    $sql .= " AND b.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($expiryRange === 'expired') {
    $sql .= " AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()";
} elseif ($expiryRange === 'near_expiry') {
    $sql .= " AND b.expiry_date IS NOT NULL AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($expiryRange === 'fresh') {
    $sql .= " AND (b.expiry_date IS NULL OR b.expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
}

$sql .= " ORDER BY b.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$batches = [];
while ($row = $res->fetch_assoc()) {
    $batches[] = $row;
}
$stmt->close();

/* ===================== UNIQUE BATCH NOS ===================== */
$batchNos = [];
$stmt = $conn->prepare("SELECT DISTINCT batch_no FROM item_batches WHERE client_id = ? ORDER BY batch_no ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $batchNos[] = $row['batch_no'];
$stmt->close();

/* ===================== STATS ===================== */
$statsSql = "
    SELECT
        COUNT(*) AS total_batches,
        SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS near_expiry,
        SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired_stock,
        SUM(qty * sale_price) AS total_batch_value
    FROM item_batches
    WHERE client_id = ?
";
$stmt = $conn->prepare($statsSql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_batches = (int)($stats['total_batches'] ?? 0);
$near_expiry = (int)($stats['near_expiry'] ?? 0);
$expired_stock = (int)($stats['expired_stock'] ?? 0);
$total_batch_value = (float)($stats['total_batch_value'] ?? 0);

/* ===================== EXPORT ===================== */
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=item_batches_export_' . date('Y-m-d_H-i-s') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Batch No', 'Product', 'Qty', 'Cost Price', 'Sale Price', 'MFG Date', 'Expiry', 'Branch', 'Supplier', 'Purchase Order', 'Status']);

    $exportSql = "
        SELECT 
            b.batch_no,
            i.name AS item_name,
            b.qty,
            b.cost_price,
            b.sale_price,
            b.mfg_date,
            b.expiry_date,
            b.branch,
            b.supplier,
            CONCAT('PO-', po.id) AS purchase_order,
            b.status
        FROM item_batches b
        LEFT JOIN items i ON i.id = b.item_id
        LEFT JOIN purchase_orders po ON po.id = b.purchase_order_id
        WHERE b.client_id = ?
        ORDER BY b.id DESC
    ";
    $stmt = $conn->prepare($exportSql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }

    fclose($output);
    $stmt->close();
    exit;
}

$autoBatchNo = generate_batch_no($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<?php include('partials/head.php'); ?>
<title>Item Batches</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#f3f4fb;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;color:#1f2937;}
/*.container-scroll{display:flex;min-height:100vh;overflow:hidden;}*/
/*.sidebar{width:240px;flex-shrink:0;}*/
/*.main-panel{flex-grow:1;display:flex;flex-direction:column;min-width:0;}*/
/*.topbar{min-height:70px;background:rgba(255,255,255,0.75);backdrop-filter:blur(10px);border-bottom:1px solid #e8eaf4;display:flex;align-items:center;padding:0;}*/
/*.content-wrapper{padding:18px;overflow-y:auto;flex-grow:1;}*/
 
  .container-scroll{
        display:flex;
        min-height:100vh;
    }

    .sidebar{
        width:250px;
        flex-shrink:0;
    }

    .main-panel{
        flex-grow:1;
        display:flex;
        flex-direction:column;
        min-width:0;
    }

    .topbar{
        height:60px;
        border-bottom:1px solid #e5e7eb;
        display:flex;
        align-items:center;
        padding:0 20px;
        background:#fff;
    }

    .content-wrapper{
        padding:20px;
        flex-grow:1;
        overflow-y:auto;
    }
 
.page-shell{background:rgba(255,255,255,0.65);border:1px solid #e8eaf4;border-radius:22px;box-shadow:0 12px 35px rgba(20,28,61,0.08);padding:18px;}
.page-header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.page-title{font-size:20px;font-weight:700;margin:0;color:#23263b;}
.header-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.action-btn{border:none;border-radius:12px;padding:11px 18px;color:#fff;font-size:15px;font-weight:600;display:inline-flex;align-items:center;gap:9px;box-shadow:0 10px 24px rgba(0,0,0,0.10);}
.btn-add{background:linear-gradient(180deg,#f7b31a 0%,#e39d05 100%);}
.btn-import{background:linear-gradient(180deg,#5f6d95 0%,#49577e 100%);}
.btn-icon-search{width:44px;height:44px;border-radius:12px;border:1px solid #e3e7f3;background:#f7f8fd;color:#667085;display:inline-flex;align-items:center;justify-content:center;font-size:18px;text-decoration:none;}

.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px;}
.stat-card{border-radius:16px;color:#fff;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;min-height:96px;box-shadow:0 14px 30px rgba(0,0,0,0.10);}
.stat-title{font-size:15px;font-weight:600;opacity:.95;margin-bottom:8px;}
.stat-sub{font-size:14px;opacity:.92;}
.stat-value{font-size:20px;font-weight:700;text-align:right;line-height:1.1;}
.stat-mini{font-size:13px;opacity:.9;font-weight:500;}
.card-blue{background:linear-gradient(135deg,#5b7cff 0%,#6f8dff 100%);}
.card-gold{background:linear-gradient(135deg,#f0ad10 0%,#f5b90b 100%);}
.card-red{background:linear-gradient(135deg,#df5a68 0%,#ea6f7a 100%);}
.card-yellow{background:linear-gradient(135deg,#f0ad10 0%,#f5b90b 100%);}

.filter-row{display:flex;gap:10px;flex-wrap:wrap;background:#f4f5fb;border:1px solid #dfe3ef;border-radius:14px;padding:10px;margin-bottom:10px;}
.filter-field{display:flex;align-items:center;gap:8px;background:#f8f9fd;border:1px solid #d8ddea;border-radius:10px;height:44px;padding:0 12px;min-width:170px;flex:1;}
.filter-field i{color:#4b5675;font-size:15px;flex-shrink:0;}
.filter-field select,.filter-field input{border:none;outline:none;background:transparent;width:100%;font-size:14px;color:#2b3144;box-shadow:none;}
.filter-btn-custom{height:44px;padding:0 20px;border:none;border-radius:10px;background:linear-gradient(180deg,#4b5b84 0%,#3f4d73 100%);color:#fff;font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px;}
.reset-btn{height:44px;padding:0 18px;border:1px solid #d8ddea;border-radius:10px;background:#fff;color:#4b5675;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;}

.quick-badges{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.quick-badge{padding:8px 14px;border-radius:10px;font-size:14px;font-weight:600;color:#fff;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 10px rgba(0,0,0,.08);}
.badge-expired{background:#c94b59;}
.badge-near{background:#d8b04d;}
.badge-active{background:#4fa06d;}
.badge-low{background:#43b06c;}
.badge-stock{background:#6e73b5;}

.table-shell{background:rgba(255,255,255,0.94);border:1px solid #e7eaf3;border-radius:16px;overflow:hidden;}
.table-responsive{overflow-x:auto;}
.batch-table{width:100%;min-width:1500px;border-collapse:separate;border-spacing:0;}
.batch-table thead th{background:linear-gradient(180deg,#1e2640 0%,#27304d 100%);color:#fff;font-weight:600;font-size:14px;padding:13px 12px;border-right:1px solid rgba(255,255,255,0.06);white-space:nowrap;}
.batch-table tbody td{padding:12px 12px;border-bottom:1px solid #edf0f7;color:#2c3245;font-size:14px;vertical-align:middle;background:#fff;}
.batch-table tbody tr:hover td{background:#fafbff;}
.batch-no{color:#9c6d19;font-weight:700;}
.item-name{font-weight:600;line-height:1.2;}
.stock-bar{display:flex;gap:4px;margin-top:4px;}
.stock-seg{height:8px;border-radius:999px;}
/*.seg-total{background:#e5b73b;}*/
.seg-reserved{background:#6da2a0;}
.seg-sold{background:#7d8cb7;}
.switch{position:relative;display:inline-block;width:48px;height:26px;}
.switch input{display:none;}
.slider{position:absolute;inset:0;cursor:pointer;background:#d1d5db;border-radius:999px;transition:.25s;}
.slider:before{content:"";position:absolute;width:20px;height:20px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 3px 10px rgba(0,0,0,.12);}
.switch input:checked + .slider{background:#39b36d;}
.switch input:checked + .slider:before{transform:translateX(22px);}

.fresh-pill,.expiry-pill{display:inline-flex;align-items:center;gap:8px;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;}
.fresh-pill{background:#dff5e7;color:#167b3f;}
.expiry-pill.near-expiry{background:#f8e2a9;color:#8b6508;}
.expiry-pill.expired{background:#f6d2d2;color:#b42318;}

/*.batch-actions-wrap{*/
/*    display:flex;*/
/*    align-items:center;*/
/*    justify-content:center;*/
/*    gap:10px;*/
/*    flex-wrap:nowrap;*/
/*}*/

/*.batch-action-btn{*/
/*    width:38px;*/
/*    height:38px;*/
/*    border:none;*/
/*    outline:none;*/
/*    border-radius:50%;*/
/*    display:inline-flex;*/
/*    align-items:center;*/
/*    justify-content:center;*/
/*    padding:0;*/
/*    cursor:pointer;*/
/*    transition:all .18s ease;*/
/*    box-shadow:0 4px 10px rgba(15,23,42,.06);*/
/*    background:transparent;*/
/*}*/

/*.batch-action-btn i{*/
/*    font-size:15px;*/
/*    line-height:1;*/
/*}*/

/*.batch-action-btn:hover{*/
/*    transform:translateY(-2px);*/
/*}*/

/*.batch-action-edit{*/
/*    background:#fff4d6;*/
/*    color:#e0a100;*/
/*}*/

/*.batch-action-edit:hover{*/
/*    background:#e0a100;*/
/*    color:#fff;*/
/*}*/

/*.batch-action-delete{*/
/*    background:#ffe3e3;*/
/*    color:#ef4444;*/
/*}*/

/*.batch-action-delete:hover{*/
/*    background:#ef4444;*/
/*    color:#fff;*/
/*}*/

/*.batch-action-btn::before,*/
/*.batch-action-btn::after{*/
/*    content: none !important;*/
/*}*/
/*.batch-action-delete::before,*/
/*.batch-action-delete::after{*/
/*    content:none !important;*/
/*    display:none !important;*/
/*}*/


/*.batch-action-btn i::before,*/
/*.batch-action-btn i::after{*/
/*    content: unset !important;*/
/*}*/

/*.batch-action-btn i{*/
/*    font-family: "bootstrap-icons" !important;*/
/*}*/


.batch-actions-wrap{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    flex-wrap:nowrap;
}

.batch-action-btn{
    width:38px;
    height:38px;
    border:none;
    outline:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

.batch-action-btn i{
    font-size:15px;
    line-height:1;
}

.batch-action-btn:hover{
    transform:translateY(-2px);
}

.batch-action-edit{
    background:#fff4d6;
    color:#e0a100;
}

.batch-action-edit:hover{
    background:#e0a100;
    color:#fff;
}

.batch-action-delete{
    background:#ffe3e3;
    color:#ef4444;
}

.batch-action-delete:hover{
    background:#ef4444;
    color:#fff;
}

.table-footer{padding:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:#fff;}
.footer-left,.footer-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.footer-select{min-width:145px;height:42px;border:1px solid #dbe1ee;border-radius:10px;background:#fff;padding:0 12px;}
.btn-mark-expired{height:42px;border:none;border-radius:10px;background:#efefef;color:#4a5568;padding:0 18px;font-weight:600;}
.btn-delete-selected{height:42px;border:none;border-radius:10px;background:linear-gradient(180deg,#ef6b63 0%,#d9534f 100%);color:#fff;padding:0 18px;font-weight:600;}
.btn-export{height:42px;border:1px solid #dbe1ee;border-radius:10px;background:#fff;color:#43506d;padding:0 16px;font-weight:600;}
.btn-report{height:42px;border:none;border-radius:10px;background:linear-gradient(90deg,#202534 0%,#40382b 100%);color:#fff;padding:0 18px;font-weight:600;}

.batch-modal-content{background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);color:#fff;border:none;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.35);}
.batch-modal-header{background:transparent;border-bottom:1px solid rgba(255,255,255,0.08);padding:18px 22px;}
.batch-modal-body{padding:20px 22px 10px;}
.batch-label{display:block;font-size:15px;margin-bottom:7px;color:#e4e8f8;font-weight:500;}
.batch-label span{color:#f4b21c;}
.batch-input,.batch-textarea{width:100%;border:1px solid rgba(255,255,255,0.10);background:#e9eaf3;color:#222;border-radius:8px;height:44px;padding:0 14px;outline:none;font-size:14px;}
.batch-textarea{min-height:66px;resize:none;padding:12px 14px;}
.qty-box{display:flex;align-items:center;border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,0.10);background:#e9eaf3;}
.qty-btn{width:52px;height:44px;border:none;background:#f1f2f7;font-size:22px;color:#222;}
.qty-input{text-align:center;border:none !important;background:transparent !important;font-weight:700;}
.qty-mini-bar{height:10px;border-radius:999px;background:linear-gradient(90deg,#5b7cff 0%,#f0b324 35%,#49be88 70%,#6dc4a4 100%);margin-top:10px;}
.qty-stats{display:flex;justify-content:space-between;font-size:12px;color:#d6dcf0;margin-top:8px;}
.batch-modal-footer{border-top:1px solid rgba(255,255,255,0.08);padding:16px 22px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;}
.footer-btns{display:flex;align-items:center;gap:10px;}
.cancel-btn{height:42px;min-width:110px;border:none;border-radius:8px;background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);color:#fff;font-weight:600;}
.save-btn{height:42px;min-width:130px;border:none;border-radius:8px;background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);color:#fff;font-weight:700;}
.track-toggle-wrap{display:flex;align-items:center;gap:10px;}

@media (max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
@media (max-width:992px){.filter-row{flex-direction:column;align-items:stretch;}.filter-field,.filter-btn-custom,.reset-btn{width:100%;min-width:100%;}}
@media (max-width:768px){.stats-grid{grid-template-columns:1fr;}.content-wrapper{padding:12px;}.page-shell{padding:12px;}.page-header{align-items:stretch;}.header-actions{width:100%;}}


/* =====================================================
   ITEM BATCHES - DARK PURPLE BLACK GLASS DASHBOARD THEME
   Paste at END before
   Backend / PHP / JS logic untouched
===================================================== */

html, body{
    min-height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-card:rgba(15,23,42,.72);
    --oms-card-2:rgba(17,24,39,.58);
    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-yellow:#f59e0b;
    --oms-orange:#f97316;
    --oms-red:#ef4444;
    --oms-cyan:#06b6d4;
}

/* ================= BASE ================= */
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI", Tahoma, Geneva, Verdana, sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
    overflow-x:hidden !important;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    width:100% !important;
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

.sidebar .sidebar-wrap,
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar .sidebar-inner,
#dashboardSidebar .sidebar-inner,
#sidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

.sidebar .sidebar-menu,
#dashboardSidebar .sidebar-menu,
#sidebar .sidebar-menu{
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
    transition:margin-left .25s ease !important;
}

.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
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
    position:relative !important;
    z-index:1 !important;
}

/* ================= SIDEBAR COLLAPSE SUPPORT ================= */
body.dashboard-sidebar-collapsed .sidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed .sidebar .search-box,
body.dashboard-sidebar-collapsed .sidebar .menu-title,
body.dashboard-sidebar-collapsed .sidebar .menu-arrow,
body.dashboard-sidebar-collapsed .sidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-icon{
    margin:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .sidebar-inner{
    padding-left:8px !important;
    padding-right:8px !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn{
    width:52px !important;
    height:42px !important;
    margin:0 auto !important;
    padding:0 !important;
    border-radius:12px !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn span{
    display:none !important;
}

/* ================= PAGE SHELL ================= */
.page-shell{
    background:transparent !important;
    border:none !important;
    border-radius:0 !important;
    box-shadow:none !important;
    padding:0 !important;
}

.page-header{
    position:relative !important;
    z-index:50 !important;
    margin-bottom:20px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
}

.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

.header-actions{
    position:relative !important;
    z-index:80 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

/* ================= ALERTS ================= */
.alert{
    border-radius:16px !important;
    font-weight:800 !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    backdrop-filter:blur(16px) !important;
}

.alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.28) !important;
}

.alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.28) !important;
}

/* ================= BUTTONS ================= */
.action-btn,
.btn-icon-search{
    border:none !important;
    border-radius:13px !important;
    padding:11px 17px !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    cursor:pointer !important;
    min-height:46px !important;
    box-shadow:0 14px 28px rgba(0,0,0,.22) !important;
    transition:.22s ease !important;
}

.action-btn:hover,
.btn-icon-search:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

.btn-add{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

.btn-import{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
    border:1px solid rgba(139,92,246,.32) !important;
}

.btn-icon-search{
    width:46px !important;
    height:46px !important;
    padding:0 !important;
    background:rgba(15,23,42,.72) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#cbd5e1 !important;
}

.btn-icon-search:hover{
    background:rgba(124,58,237,.20) !important;
}

/* ================= STATS ================= */
.stats-grid{
    position:relative !important;
    z-index:10 !important;
    display:grid !important;
    grid-template-columns:repeat(4, 1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:122px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    isolation:isolate !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
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
    z-index:-1;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-title{
    font-size:15px !important;
    font-weight:900 !important;
    color:rgba(255,255,255,.96) !important;
    line-height:1.35 !important;
    margin-bottom:8px !important;
}

.stat-sub,
.stat-mini{
    color:rgba(255,255,255,.86) !important;
    font-weight:700 !important;
}

.stat-value{
    font-size:clamp(22px, 2vw, 30px) !important;
    font-weight:900 !important;
    color:#fff !important;
    line-height:1.1 !important;
    white-space:nowrap !important;
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
.card-gold{ background:linear-gradient(135deg,#f59e0b,#fb923c) !important; }
.card-red{ background:linear-gradient(135deg,#be123c,#ef4444) !important; }
.card-yellow{ background:linear-gradient(135deg,#047857,#16a34a) !important; }

/* ================= FILTER ROW ================= */
.filter-row{
    position:relative !important;
    z-index:20 !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    padding:14px !important;
    display:grid !important;
    grid-template-columns:repeat(5, minmax(140px, 1fr)) auto auto !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:16px !important;
}

.filter-field{
    height:46px !important;
    min-width:0 !important;
    flex:none !important;
    background:rgba(15,23,42,.72) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px !important;
    box-shadow:none !important;
}

.filter-field i{
    color:#94a3b8 !important;
}

.filter-field select,
.filter-field input{
    color:#e5e7eb !important;
    background:transparent !important;
    font-weight:800 !important;
}

.filter-field select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.filter-field:focus-within{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
}

.filter-btn-custom{
    height:46px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.reset-btn{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    text-decoration:none !important;
}

.filter-btn-custom:hover,
.reset-btn:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

/* ================= QUICK BADGES ================= */
.quick-badges{
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    margin-bottom:16px !important;
}

.quick-badge{
    min-height:38px !important;
    border-radius:12px !important;
    padding:8px 14px !important;
    font-size:13px !important;
    font-weight:900 !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.badge-expired{ background:rgba(239,68,68,.18) !important; color:#f87171 !important; border-color:rgba(239,68,68,.25) !important; }
.badge-near{ background:rgba(245,158,11,.18) !important; color:#fbbf24 !important; border-color:rgba(245,158,11,.25) !important; }
.badge-active{ background:rgba(34,197,94,.16) !important; color:#4ade80 !important; border-color:rgba(34,197,94,.25) !important; }
.badge-low{ background:rgba(6,182,212,.16) !important; color:#22d3ee !important; border-color:rgba(6,182,212,.25) !important; }
.badge-stock{ background:rgba(124,58,237,.18) !important; color:#c4b5fd !important; border-color:rgba(124,58,237,.30) !important; }

/* ================= TABLE ================= */
.table-shell{
    position:relative !important;
    z-index:10 !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    transition:.25s ease !important;
}

.table-shell:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.table-responsive{
    overflow:auto !important;
}

.batch-table{
    width:100% !important;
    min-width:1500px !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;
}

.batch-table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:15px 14px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.batch-table tbody td{
    padding:15px 14px !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
}

/* White hover remove */
.batch-table tbody tr:hover td,
.batch-table tbody tr:hover > *,
.batch-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
    box-shadow:none !important;
}

.batch-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.batch-no{
    color:#93c5fd !important;
    font-weight:900 !important;
}

.item-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.batch-table small{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

/* ================= PILLS / STATUS ================= */
.fresh-pill,
.expiry-pill{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    border-radius:999px !important;
    padding:7px 11px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.fresh-pill{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.expiry-pill.near-expiry{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.expiry-pill.expired{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* Switch */
.switch{
    width:48px !important;
    height:26px !important;
}

.slider{
    background:#334155 !important;
}

.switch input:checked + .slider{
    background:linear-gradient(135deg,#22c55e,#16a34a) !important;
}

.slider:before{
    box-shadow:0 8px 18px rgba(0,0,0,.25) !important;
}

/* ================= ACTION ICONS ================= */
.batch-actions-wrap{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
    flex-wrap:nowrap !important;
}

.batch-action-btn{
    width:40px !important;
    height:40px !important;
    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:0 !important;
    cursor:pointer !important;
    transition:all .20s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.batch-action-btn:hover{
    transform:translateY(-2px) !important;
}

.batch-action-edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.batch-action-edit:hover{
    background:#f59e0b !important;
    color:#07111f !important;
}

.batch-action-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

.batch-action-delete:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

/* ================= TABLE FOOTER ================= */
.table-footer{
    padding:16px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.28) !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    flex-wrap:wrap !important;
    color:#94a3b8 !important;
    font-size:14px !important;
    font-weight:800 !important;
}

.footer-left,
.footer-right{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

.footer-select{
    min-width:145px !important;
    height:42px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    padding:0 12px !important;
    font-weight:800 !important;
}

.footer-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.btn-mark-expired,
.btn-export,
.btn-report,
.btn-delete-selected{
    height:42px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 16px !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.btn-mark-expired{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.btn-delete-selected{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
    color:#fff !important;
}

.btn-export{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.btn-report{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

.btn-mark-expired:hover,
.btn-export:hover,
.btn-report:hover,
.btn-delete-selected:hover{
    transform:translateY(-1px) !important;
}

/* =====================================================
   BATCH MODAL - DARK PURPLE GLASS THEME
===================================================== */

body.modal-open .modal-backdrop.show{
    opacity:.76 !important;
    background:#000 !important;
}

#batchModal{
    z-index:99999 !important;
}

#batchModal .modal-dialog{
    max-width:min(1000px,94vw) !important;
}

#batchModal .modal-content,
#batchModal .batch-modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.98), rgba(17,24,39,.96)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:20px !important;
    overflow:hidden !important;
    box-shadow:
        0 30px 90px rgba(0,0,0,.72),
        0 0 55px rgba(124,58,237,.16) !important;
}

#batchModal .modal-header,
#batchModal .batch-modal-header{
    min-height:66px !important;
    padding:14px 18px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#batchModal .modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
    margin:0 !important;
}

#batchModal .modal-title::before{
    display:none !important;
}

/* Close button */
#batchModal .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:13px !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    position:relative !important;
    background-image:none !important;
    padding:0 !important;
}

#batchModal .btn-close::before,
#batchModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:18px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#batchModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#batchModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#batchModal .btn-close:hover{
    background:rgba(239,68,68,.22) !important;
    border-color:rgba(239,68,68,.35) !important;
}

#batchModal .modal-body,
#batchModal .batch-modal-body{
    padding:16px 18px !important;
    background:rgba(15,23,42,.52) !important;
    color:#f8fafc !important;
    max-height:calc(100vh - 150px) !important;
    overflow-y:auto !important;
}

#batchModal .modal-footer,
#batchModal .batch-modal-footer{
    padding:12px 18px 14px !important;
    background:rgba(7,11,20,.48) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

#batchModal .batch-label,
#batchModal label{
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:6px !important;
}

#batchModal .batch-label span{
    color:#a78bfa !important;
}

/* Modal inputs */
#batchModal .batch-input,
#batchModal .batch-textarea,
#batchModal input[type="text"],
#batchModal input[type="number"],
#batchModal input[type="date"],
#batchModal select,
#batchModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    min-height:40px !important;
    height:40px !important;
    font-size:14px !important;
    font-weight:700 !important;
    padding:7px 12px !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#batchModal .batch-textarea,
#batchModal textarea{
    min-height:86px !important;
    height:auto !important;
    padding:12px 14px !important;
    resize:vertical !important;
}

#batchModal .batch-input:focus,
#batchModal .batch-textarea:focus,
#batchModal input:focus,
#batchModal select:focus,
#batchModal textarea:focus{
    background:#fff !important;
    color:#111827 !important;
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 4px rgba(124,58,237,.22),
        0 10px 22px rgba(0,0,0,.18) !important;
    outline:none !important;
}

#batchModal small,
#batchModal .text-light,
#batchModal .opacity-75{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

/* Quantity box */
#batchModal .qty-box{
    background:#ffffff !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    min-height:40px !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#batchModal .qty-btn{
    width:50px !important;
    height:40px !important;
    border:none !important;
    background:rgba(124,58,237,.12) !important;
    color:#111827 !important;
    font-size:22px !important;
    font-weight:900 !important;
}

#batchModal .qty-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
}

#batchModal .qty-input{
    background:#fff !important;
    box-shadow:none !important;
    color:#111827 !important;
    font-weight:900 !important;
}

#batchModal .qty-mini-bar{
    height:10px !important;
    border-radius:999px !important;
    background:linear-gradient(90deg,#6d4cff 0%,#7c3aed 38%,#22c55e 100%) !important;
    box-shadow:0 5px 14px rgba(124,58,237,.20) !important;
}

#batchModal .qty-stats{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

#batchModal .qty-stats strong{
    color:#a78bfa !important;
}

/* Tracking row */
#batchModal .track-toggle-wrap{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.14), transparent 34%),
        rgba(15,23,42,.68) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:14px !important;
    padding:10px 12px !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
}

#batchModal .switch input:checked + .slider{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
}

#batchModal .slider{
    background:#334155 !important;
}

/* Modal footer buttons */
#batchModal .cancel-btn{
    height:38px !important;
    min-width:105px !important;
    border-radius:12px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:0 18px !important;
    box-shadow:0 10px 20px rgba(0,0,0,.18) !important;
}

#batchModal .cancel-btn:hover{
    background:rgba(148,163,184,.28) !important;
    color:#fff !important;
}

#batchModal .save-btn{
    height:38px !important;
    min-width:115px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:0 18px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#batchModal .save-btn:hover{
    transform:translateY(-1px) !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1300px){
    .filter-row{
        grid-template-columns:repeat(3, minmax(180px, 1fr)) !important;
    }
}

@media (max-width:1200px){
    .stats-grid{
        grid-template-columns:repeat(2, minmax(0,1fr)) !important;
    }
}

@media (max-width:991px){
    .container-scroll{
        flex-direction:column !important;
    }

    .sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        position:relative !important;
        height:auto !important;
    }

    .sidebar .sidebar-wrap,
    #dashboardSidebar .sidebar-wrap,
    #sidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    .sidebar .sidebar-inner,
    #dashboardSidebar .sidebar-inner,
    #sidebar .sidebar-inner{
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

    .filter-row{
        grid-template-columns:1fr 1fr !important;
    }
}

@media (max-width:768px){
    .page-header{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .header-actions{
        width:100% !important;
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .action-btn,
    .btn-icon-search{
        width:100% !important;
    }

    .stats-grid{
        grid-template-columns:1fr !important;
    }

    .filter-row{
        grid-template-columns:1fr !important;
    }

    .filter-field,
    .filter-btn-custom,
    .reset-btn{
        width:100% !important;
        min-width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .table-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .footer-left,
    .footer-right{
        width:100% !important;
        justify-content:flex-start !important;
    }

    #batchModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #batchModal .batch-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #batchModal .footer-btns{
        width:100% !important;
        flex-direction:column !important;
    }

    #batchModal .cancel-btn,
    #batchModal .save-btn,
    #batchModal .track-toggle-wrap{
        width:100% !important;
    }
}

/* =====================================================
   FINAL FIX - ITEM BATCHES ALIGNMENT + MODAL THEME
   Paste at END before
   Logic/backend untouched
===================================================== */

/* Page width/alignment fix */
.content-wrapper{
    overflow-x:hidden !important;
}

.page-shell{
    width:100% !important;
    max-width:100% !important;
}

/* Header buttons properly align */
.page-header{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:16px !important;
    flex-wrap:wrap !important;
}

.header-actions{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

/* Stats card value overflow fix */
.stat-card{
    min-width:0 !important;
}

.stat-card > div:first-child{
    min-width:0 !important;
    max-width:70% !important;
}

.stat-title,
.stat-sub{
    word-break:normal !important;
    overflow-wrap:normal !important;
}

.stat-value{
    font-size:clamp(22px, 2.2vw, 30px) !important;
    white-space:nowrap !important;
    text-align:right !important;
}

/* Filter row alignment fix */
.filter-row{
    display:grid !important;
    grid-template-columns:repeat(5, minmax(145px, 1fr)) 110px 110px !important;
    align-items:center !important;
    gap:10px !important;
}

.filter-field{
    width:100% !important;
    min-width:0 !important;
    height:46px !important;
    display:flex !important;
    align-items:center !important;
}

.filter-field select,
.filter-field input{
    width:100% !important;
    min-width:0 !important;
    height:100% !important;
    line-height:46px !important;
}

.filter-btn-custom,
.reset-btn{
    width:100% !important;
    height:46px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
}

/* Table alignment + white hover remove */
.table-shell{
    width:100% !important;
    max-width:100% !important;
    overflow:hidden !important;
}

.table-responsive{
    width:100% !important;
    overflow-x:auto !important;
    overflow-y:hidden !important;
}

.batch-table{
    min-width:1250px !important;
    width:100% !important;
    table-layout:auto !important;
}

.batch-table thead th,
.batch-table tbody td{
    white-space:nowrap !important;
    vertical-align:middle !important;
}

.batch-table tbody tr:hover td,
.batch-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

/* Footer alignment */
.table-footer{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
    flex-wrap:wrap !important;
}

.footer-left,
.footer-right{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

/* =====================================================
   MODAL FINAL ALIGNMENT FIX
===================================================== */

/* Modal size center */
#batchModal .modal-dialog{
    max-width:1050px !important;
    width:calc(100% - 40px) !important;
    margin:1.75rem auto !important;
}

/* Modal container */
#batchModal .modal-content,
#batchModal .batch-modal-content{
    border-radius:20px !important;
    overflow:hidden !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.98), rgba(17,24,39,.96)) !important;
    border:1px solid rgba(139,92,246,.35) !important;
    box-shadow:
        0 30px 90px rgba(0,0,0,.72),
        0 0 55px rgba(124,58,237,.16) !important;
}

/* Header compact */
#batchModal .modal-header,
#batchModal .batch-modal-header{
    min-height:64px !important;
    padding:16px 20px !important;
    background:rgba(7,11,20,.50) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#batchModal .modal-title{
    font-size:20px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
}

#batchModal .modal-title::before{
    display:none !important;
}

/* Body grid alignment */
#batchModal .modal-body,
#batchModal .batch-modal-body{
    padding:20px !important;
    background:rgba(15,23,42,.50) !important;
    max-height:calc(100vh - 170px) !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* Bootstrap row force proper */
#batchModal .row{
    display:grid !important;
    grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
    gap:16px !important;
    margin:0 !important;
}

#batchModal .row > [class*="col-"]{
    width:100% !important;
    max-width:100% !important;
    padding:0 !important;
    min-width:0 !important;
}

#batchModal .col-12{
    grid-column:1 / -1 !important;
}

/* Labels */
#batchModal .batch-label,
#batchModal label{
    display:block !important;
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin:0 0 7px !important;
    line-height:1.25 !important;
}

#batchModal .batch-label span{
    color:#a78bfa !important;
}

/* Inputs/selects same height */
#batchModal .batch-input,
#batchModal input[type="text"],
#batchModal input[type="number"],
#batchModal input[type="date"],
#batchModal select{
    width:100% !important;
    height:44px !important;
    min-height:44px !important;
    padding:8px 13px !important;
    border-radius:12px !important;
    background:#ffffff !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.95) !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

/* Textarea */
#batchModal .batch-textarea,
#batchModal textarea{
    width:100% !important;
    min-height:84px !important;
    height:84px !important;
    padding:12px 14px !important;
    border-radius:12px !important;
    background:#ffffff !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.95) !important;
    font-size:14px !important;
    font-weight:700 !important;
    resize:vertical !important;
}

/* Focus */
#batchModal .batch-input:focus,
#batchModal .batch-textarea:focus,
#batchModal input:focus,
#batchModal select:focus,
#batchModal textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 4px rgba(124,58,237,.22),
        0 10px 22px rgba(0,0,0,.18) !important;
    outline:none !important;
}

/* Small helper text */
#batchModal small,
#batchModal .text-light,
#batchModal .opacity-75{
    display:block !important;
    margin-top:6px !important;
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:800 !important;
}

/* Quantity input alignment */
#batchModal .qty-box{
    width:100% !important;
    height:44px !important;
    min-height:44px !important;
    display:grid !important;
    grid-template-columns:54px 1fr 54px !important;
    align-items:center !important;
    overflow:hidden !important;
    border-radius:12px !important;
    background:#fff !important;
    border:1px solid rgba(203,213,225,.95) !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#batchModal .qty-btn{
    width:54px !important;
    height:44px !important;
    border:none !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(124,58,237,.12) !important;
    color:#111827 !important;
    font-size:22px !important;
    font-weight:900 !important;
}

#batchModal .qty-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
}

#batchModal .qty-input{
    height:44px !important;
    min-height:44px !important;
    border:none !important;
    box-shadow:none !important;
    text-align:center !important;
    background:#fff !important;
    color:#111827 !important;
    font-weight:900 !important;
    padding:0 !important;
}

/* Qty bar */
#batchModal .qty-mini-bar{
    height:9px !important;
    margin-top:9px !important;
    border-radius:999px !important;
    background:linear-gradient(90deg,#6d4cff 0%,#7c3aed 38%,#22c55e 100%) !important;
}

#batchModal .qty-stats{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:8px !important;
    margin-top:7px !important;
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:800 !important;
}

#batchModal .qty-stats strong{
    color:#a78bfa !important;
}

/* Footer aligned in one line */
#batchModal .modal-footer,
#batchModal .batch-modal-footer{
    padding:14px 20px !important;
    background:rgba(7,11,20,.52) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    flex-wrap:wrap !important;
}

#batchModal .track-toggle-wrap{
    min-height:44px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    padding:8px 12px !important;
    border-radius:14px !important;
    background:rgba(15,23,42,.68) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
}

#batchModal .footer-btns{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:10px !important;
    margin-left:auto !important;
}

#batchModal .cancel-btn,
#batchModal .save-btn{
    height:42px !important;
    min-width:120px !important;
    border-radius:12px !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:0 18px !important;
}

#batchModal .cancel-btn{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

#batchModal .save-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
}

/* Close button fix */
#batchModal .btn-close{
    width:40px !important;
    height:40px !important;
    border-radius:13px !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    position:relative !important;
    background-image:none !important;
    padding:0 !important;
    margin:0 !important;
}

#batchModal .btn-close::before,
#batchModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:18px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#batchModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#batchModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

/* Responsive */
@media (max-width:1300px){
    .filter-row{
        grid-template-columns:repeat(3, minmax(170px, 1fr)) !important;
    }
}

@media (max-width:992px){
    .filter-row{
        grid-template-columns:1fr 1fr !important;
    }

    #batchModal .row{
        grid-template-columns:1fr !important;
    }
}

@media (max-width:768px){
    .filter-row{
        grid-template-columns:1fr !important;
    }

    #batchModal .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:.75rem auto !important;
    }

    #batchModal .modal-body,
    #batchModal .batch-modal-body{
        padding:16px !important;
        max-height:calc(100vh - 145px) !important;
    }

    #batchModal .modal-footer,
    #batchModal .batch-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #batchModal .footer-btns{
        width:100% !important;
        flex-direction:column !important;
        margin-left:0 !important;
    }

    #batchModal .track-toggle-wrap,
    #batchModal .cancel-btn,
    #batchModal .save-btn{
        width:100% !important;
    }
}
/* =====================================================
   EXTRA SMALL FIX - ITEM BATCH MODAL
   Paste at VERY END before
===================================================== */

/* Backdrop kam dark karo taake page zyada hide na lage */
body.modal-open .modal-backdrop.show{
    opacity:.38 !important;
    background:#000 !important;
}

/* Modal ko aur chota karo */
#batchModal .item-batch-small-modal,
#batchModal .modal-dialog{
    max-width:560px !important;
    width:560px !important;
    margin:1rem auto !important;
}

/* Modal height control */
#batchModal .modal-content,
#batchModal .batch-modal-content{
    max-height:82vh !important;
    border-radius:16px !important;
}

/* Header chota */
#batchModal .modal-header,
#batchModal .batch-modal-header{
    min-height:46px !important;
    padding:9px 14px !important;
}

#batchModal .modal-title{
    font-size:16px !important;
}

/* Body ko compact aur scrollable karo */
#batchModal .modal-body,
#batchModal .batch-modal-body{
    padding:12px 14px !important;
    max-height:58vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* 2 columns ke bajaye compact single-column form */
#batchModal .row{
    display:grid !important;
    grid-template-columns:1fr !important;
    gap:9px !important;
    margin:0 !important;
}

#batchModal .row > [class*="col-"],
#batchModal .col-md-6,
#batchModal .col-12{
    width:100% !important;
    max-width:100% !important;
    padding:0 !important;
    grid-column:auto !important;
}

/* Labels aur inputs chotay */
#batchModal .batch-label,
#batchModal label{
    font-size:11px !important;
    margin-bottom:4px !important;
}

#batchModal .batch-input,
#batchModal input[type="text"],
#batchModal input[type="number"],
#batchModal input[type="date"],
#batchModal select{
    height:32px !important;
    min-height:32px !important;
    border-radius:8px !important;
    padding:4px 9px !important;
    font-size:12px !important;
}

/* Quantity box compact */
#batchModal .qty-box{
    height:32px !important;
    min-height:32px !important;
    grid-template-columns:38px 1fr 38px !important;
    border-radius:8px !important;
}

#batchModal .qty-btn{
    width:38px !important;
    height:32px !important;
    font-size:16px !important;
}

#batchModal .qty-input{
    height:32px !important;
    min-height:32px !important;
}

/* Quantity bar aur text compact */
#batchModal .qty-mini-bar{
    height:5px !important;
    margin-top:5px !important;
}

#batchModal .qty-stats{
    font-size:10px !important;
    margin-top:3px !important;
}

/* Helper text hide karo taake height kam ho */
#batchModal small,
#batchModal .text-light,
#batchModal .opacity-75{
    display:none !important;
}

/* Notes textarea chota */
#batchModal .batch-textarea,
#batchModal textarea{
    height:46px !important;
    min-height:46px !important;
    padding:7px 9px !important;
    font-size:12px !important;
    resize:vertical !important;
}

/* Footer compact */
#batchModal .modal-footer,
#batchModal .batch-modal-footer{
    padding:9px 14px !important;
    min-height:52px !important;
    gap:8px !important;
}

/* Tracking button compact */
#batchModal .track-toggle-wrap{
    min-height:32px !important;
    padding:5px 8px !important;
    font-size:11px !important;
    border-radius:9px !important;
}

/* Footer buttons small */
#batchModal .footer-btns{
    gap:8px !important;
}

#batchModal .cancel-btn,
#batchModal .save-btn{
    height:32px !important;
    min-width:82px !important;
    border-radius:8px !important;
    font-size:11px !important;
    padding:0 12px !important;
}

/* Close button small */
#batchModal .btn-close{
    width:30px !important;
    height:30px !important;
    border-radius:9px !important;
}

/* Responsive */
@media(max-width:650px){
    #batchModal .item-batch-small-modal,
    #batchModal .modal-dialog{
        width:calc(100% - 18px) !important;
        max-width:calc(100% - 18px) !important;
        margin:.65rem auto !important;
    }

    #batchModal .modal-body,
    #batchModal .batch-modal-body{
        max-height:62vh !important;
    }

    #batchModal .modal-footer,
    #batchModal .batch-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #batchModal .footer-btns{
        width:100% !important;
        flex-direction:row !important;
    }

    #batchModal .cancel-btn,
    #batchModal .save-btn{
        width:100% !important;
    }
}


/* =========================================================
   ITEM BATCHES - LIGHT THEME
   Is block ko HAMESHA  se just pehle rakhna
========================================================= */

html[data-pos-theme="light"]{
    color-scheme: light;
}

/* PAGE */
html[data-pos-theme="light"] body{
    background:#f4f7fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .main-panel{
    background:#f4f7fb !important;
}

html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    border-bottom:1px solid #dbe3ef !important;
    box-shadow:0 6px 20px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.08), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.05), transparent 30%),
        linear-gradient(135deg,#f8fafc 0%,#f1f5f9 45%,#f8fafc 100%) !important;
}

html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(90deg, rgba(15,23,42,.03) 1px, transparent 1px),
        linear-gradient(180deg, rgba(15,23,42,.025) 1px, transparent 1px) !important;
    opacity:.45 !important;
}

html[data-pos-theme="light"] body .content-wrapper::after{
    background:radial-gradient(circle, rgba(124,58,237,.08), transparent 68%) !important;
    opacity:.55 !important;
}

/* HEADING */
html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* TOP ACTION BUTTONS */
html[data-pos-theme="light"] body .btn-add{
    background:linear-gradient(135deg,#16a34a,#22c55e) !important;
    color:#fff !important;
}

html[data-pos-theme="light"] body .btn-import{
    background:linear-gradient(135deg,#3b82f6,#6366f1) !important;
    color:#fff !important;
}

html[data-pos-theme="light"] body .btn-icon-search{
    background:#ffffff !important;
    border:1px solid #d6deea !important;
    color:#475569 !important;
}

/* STAT CARDS */
html[data-pos-theme="light"] body .stat-card{
    color:#fff !important;
    box-shadow:0 14px 30px rgba(15,23,42,.12) !important;
}

html[data-pos-theme="light"] body .card-blue{
    background:linear-gradient(135deg,#4f7cff 0%,#7c5cff 100%) !important;
}

html[data-pos-theme="light"] body .card-gold{
    background:linear-gradient(135deg,#f59e0b 0%,#fbbf24 100%) !important;
}

html[data-pos-theme="light"] body .card-red{
    background:linear-gradient(135deg,#ef4444 0%,#fb7185 100%) !important;
}

html[data-pos-theme="light"] body .card-yellow{
    background:linear-gradient(135deg,#10b981 0%,#22c55e 100%) !important;
}

/* FILTER BAR */
html[data-pos-theme="light"] body .filter-row{
    background:#ffffff !important;
    border:1px solid #dbe3ef !important;
    box-shadow:0 10px 25px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] body .filter-field{
    background:#f8fafc !important;
    border:1px solid #d6deea !important;
}

html[data-pos-theme="light"] body .filter-field i{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .filter-field select,
html[data-pos-theme="light"] body .filter-field input{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .filter-field select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .filter-btn-custom{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#fff !important;
}

html[data-pos-theme="light"] body .reset-btn{
    background:#ffffff !important;
    border:1px solid #d6deea !important;
    color:#475569 !important;
}

/* QUICK BADGES */
html[data-pos-theme="light"] body .quick-badge{
    box-shadow:none !important;
    border:1px solid transparent !important;
}

html[data-pos-theme="light"] body .badge-expired{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body .badge-near{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] body .badge-active{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .badge-low{
    background:#ecfeff !important;
    color:#0f766e !important;
    border-color:#99f6e4 !important;
}

html[data-pos-theme="light"] body .badge-stock{
    background:#f3f4f6 !important;
    color:#4f46e5 !important;
    border-color:#c7d2fe !important;
}

/* TABLE */
html[data-pos-theme="light"] body .table-shell{
    background:#ffffff !important;
    border:1px solid #dbe3ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;
}

html[data-pos-theme="light"] body .batch-table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-right:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .batch-table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-bottom:1px solid #e8edf4 !important;
}

html[data-pos-theme="light"] body .batch-table tbody tr:hover td{
    background:#f8fafc !important;
}

html[data-pos-theme="light"] body .batch-no{
    color:#7c3aed !important;
}

html[data-pos-theme="light"] body .item-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .seg-reserved{
    background:#60a5fa !important;
}

html[data-pos-theme="light"] body .seg-sold{
    background:#a78bfa !important;
}

html[data-pos-theme="light"] body .slider{
    background:#cbd5e1 !important;
}

html[data-pos-theme="light"] body .fresh-pill{
    background:#dcfce7 !important;
    color:#166534 !important;
}

html[data-pos-theme="light"] body .expiry-pill.near-expiry{
    background:#fef3c7 !important;
    color:#92400e !important;
}

html[data-pos-theme="light"] body .expiry-pill.expired{
    background:#fee2e2 !important;
    color:#b91c1c !important;
}

/* ACTION BUTTONS */
html[data-pos-theme="light"] body .batch-action-btn{
    box-shadow:0 4px 10px rgba(15,23,42,.08) !important;
}

html[data-pos-theme="light"] body .batch-action-edit{
    background:#fffbeb !important;
    color:#d97706 !important;
}

html[data-pos-theme="light"] body .batch-action-edit:hover{
    background:#f59e0b !important;
    color:#fff !important;
}

html[data-pos-theme="light"] body .batch-action-delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
}

html[data-pos-theme="light"] body .batch-action-delete:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

/* TABLE FOOTER */
html[data-pos-theme="light"] body .table-footer{
    background:#ffffff !important;
    border-top:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .footer-left,
html[data-pos-theme="light"] body .footer-right,
html[data-pos-theme="light"] body .table-footer{
    color:#475569 !important;
}

html[data-pos-theme="light"] body .footer-select{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #d6deea !important;
}

html[data-pos-theme="light"] body .btn-mark-expired{
    background:#fff7ed !important;
    color:#c2410c !important;
    border:1px solid #fdba74 !important;
}

html[data-pos-theme="light"] body .btn-delete-selected{
    background:linear-gradient(135deg,#ef4444,#dc2626) !important;
    color:#fff !important;
}

html[data-pos-theme="light"] body .btn-export{
    background:#ffffff !important;
    color:#334155 !important;
    border:1px solid #d6deea !important;
}

html[data-pos-theme="light"] body .btn-report{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#fff !important;
}

/* MODAL */
html[data-pos-theme="light"] body .batch-modal-content{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #dbe3ef !important;
    box-shadow:0 22px 60px rgba(15,23,42,.18) !important;
}

html[data-pos-theme="light"] body .batch-modal-header{
    border-bottom:1px solid #e2e8f0 !important;
    background:#f8fafc !important;
}

html[data-pos-theme="light"] body .batch-modal-body{
    background:#ffffff !important;
}

html[data-pos-theme="light"] body .batch-modal-footer{
    border-top:1px solid #e2e8f0 !important;
    background:#f8fafc !important;
}

html[data-pos-theme="light"] body .batch-label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body .batch-label span{
    color:#dc2626 !important;
}

html[data-pos-theme="light"] body .batch-input,
html[data-pos-theme="light"] body .batch-textarea{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body .batch-input:focus,
html[data-pos-theme="light"] body .batch-textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.12) !important;
}

html[data-pos-theme="light"] body .qty-box{
    background:#ffffff !important;
    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body .qty-btn{
    background:#f8fafc !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .qty-input{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .qty-stats{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;
}

html[data-pos-theme="light"] body .save-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#fff !important;
}

html[data-pos-theme="light"] body .track-toggle-wrap{
    color:#334155 !important;
}

/* PLACEHOLDER */
html[data-pos-theme="light"] body input::placeholder{
    color:#94a3b8 !important;
}


</style>
</head>
<body>
<div class="container-scroll">
    <div class="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">
            <div class="page-shell">

                <?php if ($msg !== ''): ?>
                    <div class="alert alert-<?= e($msgType) ?> alert-dismissible fade show" role="alert">
                        <?= e($msg) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="page-header">
                    <h1 class="page-title">Item Batches</h1>

                    <div class="header-actions">
                        <button type="button" class="action-btn btn-add" onclick="openCreateBatchModal()">
                            <i class="bi bi-plus-lg"></i> Add Batch
                        </button>

                        <button type="button" class="action-btn btn-import">
                            <i class="bi bi-download"></i> Import
                        </button>

                        <a href="javascript:void(0)" class="btn-icon-search">
                            <i class="bi bi-search"></i>
                        </a>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card card-blue">
                        <div>
                            <div class="stat-title"><i class="bi bi-card-list me-2"></i>Total Batches</div>
                            <div class="stat-sub">Total batches</div>
                        </div>
                        <div class="stat-value"><?= $total_batches ?></div>
                    </div>

                    <div class="stat-card card-gold">
                        <div>
                            <div class="stat-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Near Expiry Batches</div>
                            <div class="stat-sub">Near Expiry</div>
                        </div>
                        <div class="stat-value"><?= $near_expiry ?></div>
                    </div>

                    <div class="stat-card card-red">
                        <div>
                            <div class="stat-title"><i class="bi bi-person-badge-fill me-2"></i>Expired Stock</div>
                            <div class="stat-sub">Expired</div>
                        </div>
                        <div class="stat-value"><?= $expired_stock ?></div>
                    </div>

                    <div class="stat-card card-yellow">
                        <div>
                            <div class="stat-title"><i class="bi bi-cash-coin me-2"></i>Total Batch Value</div>
                            <div class="stat-sub">Batch value</div>
                        </div>
                        <div class="stat-value">
                            <?= number_format($total_batch_value, 0) ?>
                            <div class="stat-mini">PKR</div>
                        </div>
                    </div>
                </div>

                <form class="filter-row" method="GET">
                    <div class="filter-field">
                        <i class="bi bi-box-seam"></i>
                        <select name="item_id">
                            <option value="">All Items</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= $itemFilter === (int)$item['id'] ? 'selected' : '' ?>>
                                    <?= e($item['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <i class="bi bi-upc-scan"></i>
                        <select name="batch_no">
                            <option value="">All Batch Nos</option>
                            <?php foreach ($batchNos as $batchNo): ?>
                                <option value="<?= e($batchNo) ?>" <?= $batchNoFilter === $batchNo ? 'selected' : '' ?>>
                                    <?= e($batchNo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <i class="bi bi-geo-alt-fill"></i>
                        <select name="branch">
                            <option value="">All Branches</option>
                            <?php if (!empty($branches)): ?>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= e($branch['name']) ?>" <?= $branchFilter === $branch['name'] ? 'selected' : '' ?>>
                                        <?= e($branch['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="AZ Distribution MAIN" <?= $branchFilter === 'AZ Distribution MAIN' ? 'selected' : '' ?>>AZ Distribution MAIN</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <i class="bi bi-calendar-event"></i>
                        <select name="expiry_range">
                            <option value="">Any Expiry Range</option>
                            <option value="fresh" <?= $expiryRange === 'fresh' ? 'selected' : '' ?>>Fresh</option>
                            <option value="near_expiry" <?= $expiryRange === 'near_expiry' ? 'selected' : '' ?>>Near Expiry</option>
                            <option value="expired" <?= $expiryRange === 'expired' ? 'selected' : '' ?>>Expired</option>
                        </select>
                    </div>

                    <div class="filter-field">
                        <i class="bi bi-toggle-on"></i>
                        <select name="status">
                            <option value="">All Statuses</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>

                    <button type="submit" class="filter-btn-custom">
                        <i class="bi bi-funnel"></i> Filter
                    </button>

                    <a href="<?= e($_SERVER['PHP_SELF']) ?>" class="reset-btn">
                        <i class="bi bi-search"></i> Reset
                    </a>
                </form>

                <div class="quick-badges">
                    <span class="quick-badge badge-expired"><i class="bi bi-bag-x-fill"></i> Expired</span>
                    <span class="quick-badge badge-near"><i class="bi bi-hourglass-split"></i> Near Expiry</span>
                    <span class="quick-badge badge-active"><i class="bi bi-droplet-fill"></i> Active</span>
                    <span class="quick-badge badge-low"><i class="bi bi-circle-fill"></i> Low Stock</span>
                    <span class="quick-badge badge-stock"><i class="bi bi-box2-heart-fill"></i> Batch Stock</span>
                </div>

                <div class="table-shell">
                    <div class="table-responsive">
                        <table class="batch-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="mainCheckbox" onclick="toggleAllRows(this)"></th>
                                    <th>Batch No.</th>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Cost</th>
                                    <th>Sale Price</th>
                                    <th>MFG Date</th>
                                    <th>Expiry</th>
                                    <th>Purchase Order</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($batches)): ?>
                                <tr>
                                    <td colspan="12" class="text-center py-4">No batches found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($batches as $batch): ?>
                                    <?php
                                        $reserved = (int)$batch['reserved_qty'];
                                        $sold = (int)$batch['sold_qty'];
                                        $available = max((int)$batch['qty'] - $reserved - $sold, 0);
                                        $expiryInfo = batch_freshness_label($batch['expiry_date']);
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="rowCheck" value="<?= (int)$batch['id'] ?>"></td>
                                        <td class="batch-no"><?= e($batch['batch_no']) ?></td>
                                        <td>
                                            <div class="item-name"><?= e($batch['item_name'] ?? 'N/A') ?></div>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?= (int)$batch['qty'] ?></strong>
                                                <!--<small class="ms-2"><?= $reserved ?></small>-->
                                                <!--<small class="ms-2"><?= $sold ?></small>-->
                                            </div>
                                            <!--<div class="stock-bar">-->
                                                <!--<div class="stock-seg seg-total" style="width:40%;"></div>-->
                                            <!--    <div class="stock-seg seg-reserved" style="width:25%;"></div>-->
                                            <!--    <div class="stock-seg seg-sold" style="width:35%;"></div>-->
                                            <!--</div>-->
                                        </td>
                                        <td>$<?= number_format((float)$batch['cost_price'], 2) ?></td>
                                        <td>₨<?= number_format((float)$batch['sale_price'], 2) ?> PKR</td>
                                        <td><?= e($batch['mfg_date'] ?: '-') ?></td>
                                        <td>
                                            <?php if ($expiryInfo['class'] === 'fresh'): ?>
                                                <span class="fresh-pill">Fresh</span>
                                            <?php elseif ($expiryInfo['class'] === 'near-expiry'): ?>
                                                <span class="expiry-pill near-expiry">
                                                    Near Expiry
                                                    <span><?= (int)$expiryInfo['days'] ?> Days</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="expiry-pill expired">
                                                    Expired
                                                    <span><?= e($batch['expiry_date']) ?></span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($batch['po_id'])): ?>
                                                <?= 'PO-' . (int)$batch['po_id'] ?>
                                                <br>
                                                <small><?= e($batch['po_supplier_name']) ?></small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($batch['branch']) ?></td>
                                        <td>
                                            <label class="switch">
                                                <input type="checkbox" <?= $batch['status'] === 'active' ? 'checked' : '' ?> onchange="toggleBatchStatus(<?= (int)$batch['id'] ?>, '<?= e($batch['status']) ?>')">
                                                <span class="slider"></span>
                                            </label>
                                        </td>
<td>
    <div class="batch-actions-wrap">
        <button
            type="button"
            class="batch-action-btn batch-action-edit"
            onclick='editBatch(<?= json_encode($batch, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
            title="Edit"
        >
            <i class="bi bi-pencil-fill"></i>
        </button>

        <button
            type="button"
            class="batch-action-btn batch-action-delete"
            onclick="deleteBatch(<?= (int)$batch['id'] ?>)"
            title="Delete"
        >
            <i class="bi bi-trash-fill"></i>
        </button>
    </div>
</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-footer">
                        <div class="footer-left">
                            <span>Total Rows: <strong><?= count($batches) ?></strong></span>

                            <select class="footer-select" id="bulkAction">
                                <option value="">Bulk Actions</option>
                                <option value="mark_expired">Mark Expired</option>
                                <option value="delete">Delete Selected</option>
                            </select>

                            <button type="button" class="btn-mark-expired" onclick="applyBulkAction('mark_expired')">
                                <i class="bi bi-funnel"></i> Mark Expired
                            </button>

                            <button type="button" class="btn-delete-selected" onclick="applyBulkAction('delete')">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>

                        <div class="footer-right">
                            <button type="button" class="btn-export" onclick="exportCSV()">
                                <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                            </button>

                            <button type="button" class="btn-report">
                                <i class="bi bi-bar-chart-fill"></i> Batch Report
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="batchModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered item-batch-small-modal"> 
        <div class="modal-content batch-modal-content">
            <form method="POST" id="batchForm">
                <input type="hidden" name="action" id="batchFormAction" value="add_batch">
                <input type="hidden" name="id" id="batch_id">
                <input type="hidden" name="reserved_qty" id="reserved_qty" value="0">
                <input type="hidden" name="sold_qty" id="sold_qty" value="0">
                <input type="hidden" name="cost_price" id="cost_price" value="0">
                <input type="hidden" name="sale_price" id="sale_price" value="0">
                <input type="hidden" name="status" id="batch_status" value="active">

                <div class="modal-header batch-modal-header">
                    <h5 class="modal-title" id="batchModalTitle">Add Batch</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body batch-modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="batch-label">Product <span>*</span></label>
                            <select class="batch-input" name="item_id" id="item_id" required>
                                <option value="">Select Product...</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?= (int)$item['id'] ?>"><?= e($item['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="batch-label">Batch No. <span>*</span></label>
                            <input type="text" class="batch-input" name="batch_no" id="batch_no" value="<?= e($autoBatchNo) ?>" required>
                            <small class="text-light opacity-75">Auto generated Batch Number</small>
                        </div>

                        <div class="col-md-6">
                            <label class="batch-label">Quantity</label>
                            <div class="qty-box">
                                <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
                                <input type="number" class="batch-input qty-input" name="qty" id="qty" value="0" min="0" required>
                                <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
                            </div>
                            <div class="qty-mini-bar"></div>
                            <div class="qty-stats">
                                <span><strong id="reservedQtyText">0</strong> Reserved</span>
                                <span><strong id="soldQtyText">0</strong> Sold</span>
                                <span><strong id="availableQtyText">0</strong> Available</span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="batch-label">Branch <span>*</span></label>
                            <select class="batch-input" name="branch" id="branch" required>
                                <option value="">Select Branch...</option>
                                <?php if (!empty($branches)): ?>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= e($branch['name']) ?>"><?= e($branch['name']) ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="AZ Distribution MAIN">AZ Distribution MAIN</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="batch-label">MFG Date</label>
                            <input type="date" class="batch-input" name="mfg_date" id="mfg_date">
                        </div>

                        <div class="col-md-6">
                            <label class="batch-label">Expiry Date</label>
                            <input type="date" class="batch-input" name="expiry_date" id="expiry_date">
                        </div>

                        <div class="col-md-6">
                            <label class="batch-label">Supplier <span>*</span></label>
                            <select class="batch-input" name="supplier" id="supplier">
                                <option value="">Select Supplier...</option>
                                <?php if (!empty($suppliers)): ?>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= e($supplier['name']) ?>"><?= e($supplier['name']) ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="Default Supplier">Default Supplier</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="batch-label">Purchase Order</label>
                            <select class="batch-input" name="purchase_order_id" id="purchase_order_id">
                                <option value="">Select Purchase Order...</option>
                                <?php if (!empty($purchaseOrders)): ?>
                                    <?php foreach ($purchaseOrders as $po): ?>
                                        <option value="<?= (int)$po['id'] ?>">
                                            <?= 'PO-' . (int)$po['id'] . ' | ' . e($po['supplier_name']) . ' | PKR ' . number_format((float)$po['total_amount'], 0) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">No Purchase Orders Found</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="batch-label">Batch Notes</label>
                            <textarea class="batch-textarea" name="notes" id="notes" placeholder="Enter internal notes..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer batch-modal-footer">
                    <div class="track-toggle-wrap">
                        <label class="switch">
                            <input type="checkbox" name="is_tracking_enabled" id="is_tracking_enabled" checked>
                            <span class="slider"></span>
                        </label>
                        <span>Enable Batch Tracking</span>
                    </div>

                    <div class="footer-btns">
                        <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn" id="batchSubmitBtn">Add Batch</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete_batch">
    <input type="hidden" name="id" id="delete_id">
</form>

<form method="POST" id="toggleStatusForm" style="display:none;">
    <input type="hidden" name="action" value="enable_disable_batch">
    <input type="hidden" name="id" id="toggle_id">
    <input type="hidden" name="status" id="toggle_status">
</form>

<form method="POST" id="bulkDeleteForm" style="display:none;">
    <input type="hidden" name="action" id="bulkActionInput" value="bulk_delete">
    <div id="bulkActionInputs"></div>
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const batchModal = new bootstrap.Modal(document.getElementById('batchModal'));

function syncQtyStats() {
    const qty = parseInt(document.getElementById('qty').value || 0);
    const reserved = parseInt(document.getElementById('reserved_qty').value || 0);
    const sold = parseInt(document.getElementById('sold_qty').value || 0);
    const available = Math.max(qty - reserved - sold, 0);

    document.getElementById('reservedQtyText').innerText = reserved;
    document.getElementById('soldQtyText').innerText = sold;
    document.getElementById('availableQtyText').innerText = available;
}

function changeQty(delta) {
    const qtyEl = document.getElementById('qty');
    let val = parseInt(qtyEl.value || 0);
    val += delta;
    if (val < 0) val = 0;
    qtyEl.value = val;
    syncQtyStats();
}

document.getElementById('qty').addEventListener('input', syncQtyStats);

function resetBatchForm() {
    document.getElementById('batchForm').reset();
    document.getElementById('batch_id').value = '';
    document.getElementById('batchFormAction').value = 'add_batch';
    document.getElementById('batchModalTitle').textContent = 'Add Batch';
    document.getElementById('batchSubmitBtn').textContent = 'Add Batch';
    document.getElementById('batch_no').value = '<?= e($autoBatchNo) ?>';
    document.getElementById('reserved_qty').value = '0';
    document.getElementById('sold_qty').value = '0';
    document.getElementById('cost_price').value = '0';
    document.getElementById('sale_price').value = '0';
    document.getElementById('batch_status').value = 'active';
    document.getElementById('is_tracking_enabled').checked = true;
    syncQtyStats();
}

function openCreateBatchModal() {
    resetBatchForm();
    batchModal.show();
}

function editBatch(batch) {
    resetBatchForm();

    document.getElementById('batchFormAction').value = 'update_batch';
    document.getElementById('batchModalTitle').textContent = 'Edit Batch';
    document.getElementById('batchSubmitBtn').textContent = 'Update Batch';

    document.getElementById('batch_id').value = batch.id ?? '';
    document.getElementById('item_id').value = batch.item_id ?? '';
    document.getElementById('batch_no').value = batch.batch_no ?? '';
    document.getElementById('qty').value = batch.qty ?? 0;
    document.getElementById('branch').value = batch.branch ?? '';
    document.getElementById('supplier').value = batch.supplier ?? '';
    document.getElementById('purchase_order_id').value = batch.purchase_order_id ?? '';
    document.getElementById('mfg_date').value = batch.mfg_date ?? '';
    document.getElementById('expiry_date').value = batch.expiry_date ?? '';
    document.getElementById('notes').value = batch.notes ?? '';
    document.getElementById('reserved_qty').value = batch.reserved_qty ?? 0;
    document.getElementById('sold_qty').value = batch.sold_qty ?? 0;
    document.getElementById('cost_price').value = batch.cost_price ?? 0;
    document.getElementById('sale_price').value = batch.sale_price ?? 0;
    document.getElementById('batch_status').value = batch.status ?? 'active';
    document.getElementById('is_tracking_enabled').checked = Number(batch.is_tracking_enabled) === 1;

    syncQtyStats();
    batchModal.show();
}

function deleteBatch(id) {
    if (!confirm('Delete this batch?')) return;
    document.getElementById('delete_id').value = id;
    document.getElementById('deleteForm').submit();
}

function toggleBatchStatus(id, status) {
    document.getElementById('toggle_id').value = id;
    document.getElementById('toggle_status').value = status;
    document.getElementById('toggleStatusForm').submit();
}

function toggleAllRows(main) {
    document.querySelectorAll('.rowCheck').forEach(el => el.checked = main.checked);
}

function applyBulkAction(forceAction = '') {
    const dropdownAction = document.getElementById('bulkAction').value;
    const action = forceAction || dropdownAction;
    const ids = Array.from(document.querySelectorAll('.rowCheck:checked')).map(el => el.value);

    if (!action) {
        alert('Please select a bulk action.');
        return;
    }
    if (ids.length === 0) {
        alert('Please select at least one row.');
        return;
    }

    const confirmed = confirm(action === 'delete' ? 'Delete selected batches?' : 'Mark selected batches expired?');
    if (!confirmed) return;

    const container = document.getElementById('bulkActionInputs');
    container.innerHTML = '';
    document.getElementById('bulkActionInput').value = action === 'mark_expired' ? 'mark_expired' : 'bulk_delete';

    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        container.appendChild(input);
    });

    document.getElementById('bulkDeleteForm').submit();
}

function exportCSV() {
    window.location.href = '<?= e($_SERVER['PHP_SELF']) ?>?export=1';
}

syncQtyStats();
</script>
</body>
</html>