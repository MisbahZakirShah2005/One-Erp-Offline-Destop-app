<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$logged_user_id   = (int)($_SESSION['user_id'] ?? 0);
$logged_role_id   = (int)($_SESSION['role_id'] ?? 0);
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_with_message(string $type, string $msg): void
{
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: {$base}?msg_type=" . urlencode($type) . "&msg=" . urlencode($msg));
    exit;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS cnt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['cnt'] ?? 0) > 0);
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['cnt'] ?? 0) > 0);
}

function get_current_branch_stock(mysqli $conn, int $client_id, int $item_id, int $branch_id): int
{
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN type = 'in' THEN qty
                WHEN type = 'out' THEN -qty
                WHEN type = 'adjustment' THEN qty
                ELSE 0
            END
        ),0) AS qty
        FROM stock_movements
        WHERE client_id = ? AND item_id = ? AND branch_id = ?
    ");
    $stmt->bind_param("iii", $client_id, $item_id, $branch_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['qty'] ?? 0);
}

function get_stock_status_label(int $stock, int $alert): string
{
    if ($stock <= 0) return 'Out of Stock';
    if ($stock <= $alert) return 'Low Stock';
    return 'Healthy';
}

$itemsHasCategory   = column_exists($conn, 'items', 'category');
$itemsHasSupplierId = column_exists($conn, 'items', 'supplier_id');
$itemsHasReorder    = column_exists($conn, 'items', 'reorder_flag');
$itemsHasStatus     = column_exists($conn, 'items', 'status');
$suppliersTable     = table_exists($conn, 'suppliers');
$alertLogsTable     = table_exists($conn, 'stock_alert_logs');

/* ================= CLIENT ================= */
$selected_client = (int)($_GET['client_id'] ?? 0);
if($logged_role_id !== 1){
    $selected_client = $logged_client_id;
}
if($selected_client <= 0){
    $selected_client = $logged_client_id;
}

/* ================= POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $selected_ids = $_POST['selected_ids'] ?? [];
    $cleanIds = [];

    if (is_array($selected_ids)) {
        foreach ($selected_ids as $id) {
            $id = (int)$id;
            if ($id > 0) $cleanIds[] = $id;
        }
    }

    try {
        if ($action === 'mark_reorder') {
            if (!$itemsHasReorder) {
                redirect_with_message('danger', 'reorder_flag column missing in items table.');
            }
            if (empty($cleanIds)) {
                redirect_with_message('danger', 'Please select at least one item.');
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $types = str_repeat('i', count($cleanIds)) . 'i';
            $params = array_merge($cleanIds, [$selected_client]);

            $sql = "UPDATE items SET reorder_flag = 1 WHERE id IN ($placeholders) AND client_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Selected items marked for reorder.');
        }

        if ($action === 'bulk_delete') {
            if (empty($cleanIds)) {
                redirect_with_message('danger', 'Please select at least one item.');
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $types = str_repeat('i', count($cleanIds)) . 'i';
            $params = array_merge($cleanIds, [$selected_client]);

            if ($itemsHasStatus) {
                $sql = "UPDATE items SET status = 'deleted' WHERE id IN ($placeholders) AND client_id = ?";
            } else {
                $sql = "DELETE FROM items WHERE id IN ($placeholders) AND client_id = ?";
            }

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Selected items deleted successfully.');
        }

        if ($action === 'bulk_transfer') {
            $from_branch = (int)($_POST['from_branch_id'] ?? 0);
            $to_branch   = (int)($_POST['to_branch_id'] ?? 0);
            $qty         = (int)($_POST['transfer_qty'] ?? 0);
            $reason      = trim($_POST['transfer_reason'] ?? 'transfer');

            if (empty($cleanIds)) {
                redirect_with_message('danger', 'Please select at least one item.');
            }
            if ($from_branch <= 0 || $to_branch <= 0) {
                redirect_with_message('danger', 'Please select both branches.');
            }
            if ($from_branch === $to_branch) {
                redirect_with_message('danger', 'From and To branch must be different.');
            }
            if ($qty <= 0) {
                redirect_with_message('danger', 'Transfer qty must be greater than 0.');
            }

            $conn->begin_transaction();

            foreach ($cleanIds as $item_id) {
                $currentStock = get_current_branch_stock($conn, $selected_client, $item_id, $from_branch);

                if ($currentStock < $qty) {
                    throw new RuntimeException("Not enough stock for item ID {$item_id} in source branch.");
                }

                $typeOut = 'out';
                $stmt = $conn->prepare("
                    INSERT INTO stock_movements
                    (item_id, client_id, branch_id, transfer_to_branch_id, qty, type, reason, notes, reference_no, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $notes = 'Bulk transfer from low stock page';
                $reference_no = 'TRF-' . time() . '-' . $item_id;
                $stmt->bind_param("iiiiissss", $item_id, $selected_client, $from_branch, $to_branch, $qty, $typeOut, $reason, $notes, $reference_no);
                $stmt->execute();
                $stmt->close();

                $typeIn = 'in';
                $stmt = $conn->prepare("
                    INSERT INTO stock_movements
                    (item_id, client_id, branch_id, transfer_to_branch_id, qty, type, reason, notes, reference_no, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->bind_param("iiiiissss", $item_id, $selected_client, $to_branch, $from_branch, $qty, $typeIn, $reason, $notes, $reference_no);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            redirect_with_message('success', 'Stock transferred successfully.');
        }

        if ($action === 'manage_alerts') {
            $new_alert = (int)($_POST['new_low_stock_alert'] ?? 0);

            if (empty($cleanIds)) {
                redirect_with_message('danger', 'Please select at least one item.');
            }
            if ($new_alert <= 0) {
                redirect_with_message('danger', 'Alert threshold must be greater than 0.');
            }

            foreach ($cleanIds as $item_id) {
                $stmt = $conn->prepare("SELECT low_stock_alert FROM items WHERE id = ? AND client_id = ?");
                $stmt->bind_param("ii", $item_id, $selected_client);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $old_alert = (int)($row['low_stock_alert'] ?? 0);

                $stmt = $conn->prepare("UPDATE items SET low_stock_alert = ? WHERE id = ? AND client_id = ?");
                $stmt->bind_param("iii", $new_alert, $item_id, $selected_client);
                $stmt->execute();
                $stmt->close();

                if ($alertLogsTable) {
                    $stmt = $conn->prepare("
                        INSERT INTO stock_alert_logs (client_id, item_id, old_alert, new_alert)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->bind_param("iiii", $selected_client, $item_id, $old_alert, $new_alert);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            redirect_with_message('success', 'Low stock alerts updated successfully.');
        }
    } catch (Throwable $e) {
        if ($conn->errno) {
            $conn->rollback();
        }
        redirect_with_message('danger', $e->getMessage());
    }
}

/* ================= FETCH BRANCHES ================= */
$branches = [];
$branchMap = [];
if($selected_client > 0){
    $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $selected_client);
    $stmt->execute();
    $res = $stmt->get_result();
    while($b = $res->fetch_assoc()){
        $branches[] = $b;
        $branchMap[$b['id']] = $b['name'];
    }
    $stmt->close();
}

/* ================= FETCH CATEGORIES ================= */
/* ================= FETCH CATEGORIES ================= */
$categories = [];
if ($selected_client > 0 && $itemsHasCategory) {
    $stmt = $conn->prepare("
        SELECT DISTINCT TRIM(category) AS category
        FROM items
        WHERE client_id = ?
          AND category IS NOT NULL
          AND TRIM(category) != ''
          " . ($itemsHasStatus ? "AND (status IS NULL OR status != 'deleted')" : "") . "
        ORDER BY category ASC
    ");
    $stmt->bind_param("i", $selected_client);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $cat = trim((string)($row['category'] ?? ''));
        if ($cat !== '') {
            $categories[] = $cat;
        }
    }
    $stmt->close();

    $categories = array_values(array_unique($categories));
}

/* ================= FETCH SUPPLIERS ================= */
/* ================= FETCH SUPPLIERS ================= */
$suppliers = [];
if ($selected_client > 0 && $itemsHasSupplierId && $suppliersTable) {
    $stmt = $conn->prepare("
        SELECT id, TRIM(name) AS name
        FROM suppliers
        WHERE client_id = ?
          AND name IS NOT NULL
          AND TRIM(name) != ''
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $selected_client);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $suppliers[] = [
            'id' => (int)$row['id'],
            'name' => trim((string)$row['name'])
        ];
    }
    $stmt->close();
}

/* ================= FILTERS ================= */
$selected_branch   = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$selected_item     = trim($_GET['item_mode'] ?? 'low_stock');
$selected_category = trim($_GET['category'] ?? '');
$selected_supplier = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$days_stockout     = isset($_GET['days_stockout']) ? 1 : 0;
$threshold         = isset($_GET['threshold']) && is_numeric($_GET['threshold']) ? (int)$_GET['threshold'] : 10;
$msg               = trim($_GET['msg'] ?? '');
$msgType           = trim($_GET['msg_type'] ?? 'success');

/* ================= FETCH DATA ================= */
$rows = [];

if($selected_client > 0){
    $sql = "
        SELECT 
            i.id AS item_id,
            i.name AS item_name,
            i.sku,
            i.low_stock_alert,
            i.price,
            i.image,
            i.stock_qty,
            " . ($itemsHasCategory ? "i.category," : "NULL AS category,") . "
            " . ($itemsHasSupplierId ? "i.supplier_id," : "NULL AS supplier_id,") . "
            " . ($itemsHasReorder ? "i.reorder_flag," : "0 AS reorder_flag,") . "
            COALESCE(s.name, '-') AS supplier_name,
            COALESCE(SUM(
                CASE
                    WHEN sm.type='in' THEN sm.qty
                    WHEN sm.type='out' THEN -sm.qty
                    WHEN sm.type='adjustment' THEN sm.qty
                    ELSE 0
                END
            ),0) AS branch_qty
        FROM items i
        LEFT JOIN stock_movements sm ON i.id = sm.item_id
        LEFT JOIN suppliers s ON " . ($itemsHasSupplierId && $suppliersTable ? "s.id = i.supplier_id" : "1=0") . "
        WHERE i.client_id = ?
    ";

    $params = [$selected_client];
    $types = "i";

    if ($itemsHasStatus) {
        $sql .= " AND (i.status IS NULL OR i.status != 'deleted')";
    }

    if($selected_branch > 0){
        $sql .= " AND sm.branch_id = ?";
        $params[] = $selected_branch;
        $types .= "i";
    }

    if($itemsHasCategory && $selected_category !== ''){
        $sql .= " AND i.category = ?";
        $params[] = $selected_category;
        $types .= "s";
    }

    if($itemsHasSupplierId && $selected_supplier > 0){
        $sql .= " AND i.supplier_id = ?";
        $params[] = $selected_supplier;
        $types .= "i";
    }

    $sql .= " GROUP BY i.id ";

    if($selected_item === 'low_stock'){
        $sql .= " HAVING branch_qty <= COALESCE(NULLIF(i.low_stock_alert,0), ?) ";
        $params[] = $threshold;
        $types .= "i";
    }

    $sql .= " ORDER BY branch_qty ASC, i.name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while($row = $res->fetch_assoc()){
        $currentStock = (int)($row['branch_qty'] ?? 0);
        $alertLevel   = (int)($row['low_stock_alert'] ?? $threshold);

        if($selected_item === 'low_stock' && $currentStock > $alertLevel){
            continue;
        }

        $avgDailySales = rand(1, 4);
        $daysUntilStockout = $avgDailySales > 0 ? floor(max($currentStock,0) / $avgDailySales) : 0;

        if($days_stockout && $daysUntilStockout <= 0){
            continue;
        }

        $row['avg_daily_sales'] = $avgDailySales;
        $row['days_until_stockout'] = $daysUntilStockout;
        $row['status_label'] = get_stock_status_label($currentStock, $alertLevel);

        $rows[] = $row;
    }
    $stmt->close();
}

/* ================= SUMMARY ================= */
$lowStockValue = 0;
foreach($rows as $r){
    $lowStockValue += ((float)($r['price'] ?? 0) * max((int)($r['branch_qty'] ?? 0), 0));
}

/* ================= EXPORT CSV ================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=low_stock_report_' . date('Y-m-d_H-i-s') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Item Name', 'SKU', 'Current Stock', 'Avg Daily Sales', 'Supplier', 'Status', 'Days Until Stockout']);

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['item_name'],
            $r['sku'],
            $r['branch_qty'],
            $r['avg_daily_sales'],
            $r['supplier_name'] ?: '-',
            $r['status_label'],
            $r['days_until_stockout']
        ]);
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Low Stock Report</title>

<script>
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

<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#f4f5fb;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;}
.filter-field i{
    pointer-events:none;
}

.filter-field select{
    cursor:pointer;
}
  .container-scroll{
        display:flex;
        min-height:100vh;
           align-items:stretch;
    }

    .sidebar{
        width:250px;
        flex-shrink:0;
          display:flex;*/
  align-self:stretch;
  background:transparent;
    overflow:hidden;
    }

.sidebar > .sidebar-wrap{
    width:100%;
    min-height:100%;
    height:100%;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100%;
}
.main-panel{flex-grow:1;display:flex;flex-direction:column;}
.topbar{height:60px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;padding:0 20px;background:#fff;}
.content-wrapper{padding:20px;flex-grow:1;overflow-y:auto;}
.page-shell{background:#fff;border:1px solid #e8eaf2;border-radius:16px;padding:18px;box-shadow:0 4px 14px rgba(0,0,0,0.04);}
.page-title{font-size:22px;font-weight:700;color:#1f2937;margin-bottom:18px;}
.top-grid{display:grid;grid-template-columns:1.7fr 0.7fr;gap:16px;align-items:start;margin-bottom:16px;}
.branch-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;}
.branch-label{font-size:14px;color:#374151;font-weight:600;margin-bottom:8px;}
.branch-select-wrap{display:flex;gap:10px;align-items:center;}
.branch-select-wrap select{height:46px;border:1px solid #d9ddea;border-radius:10px;padding:0 14px;width:100%;outline:none;}
.refresh-btn{width:46px;height:46px;border:none;border-radius:10px;background:#f4c33a;color:#fff;font-size:20px;}
.summary-card{background:linear-gradient(135deg,#435899,#2f457b);border-radius:14px;color:#fff;padding:16px 18px;min-height:92px;display:flex;flex-direction:column;justify-content:center;}
.summary-card .title{font-size:18px;font-weight:700;}
.summary-card .meta{font-size:13px;opacity:.9;margin-top:6px;}
.summary-card .value{font-size:24px;font-weight:800;margin-top:4px;}
.filter-bar{display:grid;grid-template-columns:1fr 0.9fr 1.1fr 1.1fr auto auto auto;gap:10px;align-items:center;margin-bottom:12px;}
.filter-field{position:relative;}
.filter-field i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#6b7280;}
.filter-field select,.filter-field input{width:100%;height:44px;border:1px solid #d9ddea;border-radius:10px;padding:0 14px 0 38px;background:#fff;outline:none;}
.filter-field .highlight{background:#f3d36a;}
.inline-tools{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;}
.toggle-box{display:flex;align-items:center;gap:10px;font-weight:600;color:#374151;}
.advanced-btn{height:42px;border:1px solid #d9ddea;background:#fff;border-radius:10px;padding:0 14px;font-weight:600;color:#374151;}
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;}
.table-responsive{overflow:auto;}
.lowstock-table{width:100%;min-width:1200px;border-collapse:collapse;}
.lowstock-table thead th{background:#28344d;color:#fff;padding:14px 12px;font-size:14px;font-weight:600;white-space:nowrap;}
.lowstock-table tbody td{padding:13px 12px;border-top:1px solid #edf0f5;font-size:14px;color:#374151;vertical-align:middle;}
.dot{width:10px;height:10px;border-radius:50%;background:#7182b7;display:inline-block;}
.stock-badge{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:28px;padding:0 10px;border-radius:8px;font-weight:700;color:#111827;}
.stock-yellow{background:#f4cb62;}
.stock-red{background:#df5869;color:#fff;}
.sales-bar{display:flex;gap:3px;align-items:center;}
.sales-seg{height:14px;border-radius:3px;}
.seg1{width:28px;background:#d6576a;}
.seg2{width:24px;background:#e68e6b;}
.seg3{width:22px;background:#e2b24f;}
.seg4{width:18px;background:#e3c95f;}
.seg5{width:16px;background:#e9e7f1;}
.status-text{font-weight:600;color:#111827;}
.pill-low{background:#d85766;color:#fff;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:700;display:inline-flex;}
.pill-healthy{background:#3aa86a;color:#fff;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:700;display:inline-flex;}
.table-footer-top{padding:14px 16px;font-size:14px;color:#374151;border-top:1px solid #edf0f5;}
.bottom-actions{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 16px;border-top:1px solid #edf0f5;background:#fff;}
.bottom-left,.bottom-right{display:flex;gap:10px;flex-wrap:wrap;}
.btn-action{height:42px;border:1px solid #d9ddea;border-radius:10px;background:#fff;padding:0 16px;font-weight:600;color:#374151;display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.btn-action.yellow{background:#efd074;}
.btn-action.green{background:#dff4e7;}
.btn-action.red{background:#de5b66;color:#fff;border-color:#de5b66;}
.btn-action.dark{background:#fff;}
.pagination-bar{display:flex;justify-content:flex-end;gap:10px;align-items:center;padding:12px 16px;border-top:1px solid #edf0f5;}
.page-pill{width:34px;height:34px;border-radius:8px;background:#1f2b49;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;}
.form-switch .form-check-input{width:48px;height:26px;}
.modal-dark .modal-content{background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);color:#fff;border:none;border-radius:14px;overflow:hidden;}
.modal-dark .modal-header{border-bottom:1px solid rgba(255,255,255,0.08);}
.modal-dark .modal-footer{border-top:1px solid rgba(255,255,255,0.08);}
.modal-dark .form-label{color:#e4e8f8;font-weight:500;}
.modal-dark .form-control,.modal-dark .form-select{background:#e9eaf3;color:#222;border:none;}
@media(max-width:1200px){.top-grid{grid-template-columns:1fr;}.filter-bar{grid-template-columns:1fr 1fr;}}
@media(max-width:768px){.filter-bar{grid-template-columns:1fr;}.bottom-actions{flex-direction:column;}}

/* =====================================================
   LOW_STOCK.PHP - DARK PURPLE BLACK GLASS THEME
   Paste at VERY END before
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
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
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

/* ================= SIDEBAR COLLAPSE ================= */
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
    padding:0 !important;
    box-shadow:none !important;
}

.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin-bottom:18px !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

/* ================= ALERTS ================= */
.alert{
    border-radius:14px !important;
    font-weight:900 !important;
    backdrop-filter:blur(14px) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
}

.alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.26) !important;
}

.alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.26) !important;
}

/* ================= TOP CARDS ================= */
.top-grid{
    display:grid !important;
    grid-template-columns:1.7fr .7fr !important;
    gap:16px !important;
    align-items:start !important;
    margin-bottom:16px !important;
}

.branch-card,
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    border-radius:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    transition:.25s ease !important;
}

.branch-card:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.branch-card{
    padding:16px !important;
}

.branch-label{
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    margin-bottom:8px !important;
}

.branch-select-wrap{
    display:flex !important;
    gap:10px !important;
    align-items:center !important;
}

.branch-select-wrap select{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px !important;
    width:100% !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
}

.branch-select-wrap select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.refresh-btn{
    width:46px !important;
    height:46px !important;
    border:none !important;
    border-radius:13px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-size:20px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.2s ease !important;
}

.refresh-btn:hover{
    transform:translateY(-2px) !important;
}

/* Summary card */
.summary-card{
    min-height:106px !important;
    border-radius:18px !important;
    padding:18px 20px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
    isolation:isolate !important;
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
    z-index:-1;
}

.summary-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.summary-card .title{
    font-size:17px !important;
    font-weight:900 !important;
}

.summary-card .meta{
    font-size:13px !important;
    opacity:.88 !important;
    margin-top:6px !important;
    font-weight:700 !important;
}

.summary-card .value{
    font-size:28px !important;
    font-weight:900 !important;
    margin-top:4px !important;
}

/* ================= FILTER BAR ================= */
.filter-bar{
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
    grid-template-columns:1fr .9fr 1.1fr 1.1fr auto auto auto !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:14px !important;
}

.filter-field{
    position:relative !important;
    min-width:0 !important;
}

.filter-field i{
    position:absolute !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#a78bfa !important;
    z-index:2 !important;
    pointer-events:none !important;
}

.filter-field select,
.filter-field input{
    width:100% !important;
    height:44px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px 0 42px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    outline:none !important;
    font-weight:800 !important;
    box-shadow:none !important;
}

.filter-field select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.filter-field input[readonly],
.filter-field .highlight{
    background:rgba(124,58,237,.16) !important;
    color:#f8fafc !important;
}

.filter-field select:focus,
.filter-field input:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
}

.inline-tools{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    margin-bottom:14px !important;
    flex-wrap:wrap !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.44), rgba(17,24,39,.30)) !important;
    border:1px solid rgba(148,163,184,.14) !important;
    border-radius:16px !important;
    padding:12px 14px !important;
}

.toggle-box{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
}

.inline-tools .form-control{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    font-weight:800 !important;
}

.form-switch .form-check-input{
    width:48px !important;
    height:26px !important;
    cursor:pointer !important;
    background-color:#334155 !important;
    border-color:rgba(148,163,184,.28) !important;
}

.form-switch .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
}

.advanced-btn,
.btn-action{
    height:42px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.62) !important;
    padding:0 16px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    transition:.2s ease !important;
}

.advanced-btn:hover,
.btn-action:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    background:rgba(124,58,237,.20) !important;
    border-color:rgba(139,92,246,.42) !important;
}

.btn-action.yellow{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.25) !important;
}

.btn-action.green{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.25) !important;
}

.btn-action.red{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.25) !important;
}

/* ================= TABLE ================= */
.table-card{
    overflow:hidden !important;
}

.table-responsive{
    overflow:auto !important;
}

.lowstock-table{
    width:100% !important;
    min-width:1200px !important;
    border-collapse:collapse !important;
    background:transparent !important;
}

.lowstock-table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    padding:15px 14px !important;
    font-size:13px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

.lowstock-table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    vertical-align:middle !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
}

/* White hover remove */
.lowstock-table tbody tr:hover td,
.lowstock-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
    box-shadow:none !important;
}

.lowstock-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.stock-badge{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    min-width:38px !important;
    height:28px !important;
    padding:0 10px !important;
    border-radius:999px !important;
    font-weight:900 !important;
}

.stock-yellow{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}

.stock-red{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.25) !important;
}

.status-text{
    font-weight:900 !important;
    color:#e5e7eb !important;
}

.pill-low,
.pill-healthy{
    padding:7px 12px !important;
    border-radius:999px !important;
    font-size:12px !important;
    font-weight:900 !important;
    display:inline-flex !important;
}

.pill-low{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.25) !important;
}

.pill-healthy{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

/* Sales bars */
.seg1{background:#ef4444 !important;}
.seg2{background:#f97316 !important;}
.seg3{background:#f59e0b !important;}
.seg4{background:#eab308 !important;}
.seg5{background:#a78bfa !important;}

/* ================= TABLE FOOTERS ================= */
.table-footer-top{
    padding:14px 16px !important;
    font-size:14px !important;
    color:#94a3b8 !important;
    font-weight:800 !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.24) !important;
}

.bottom-actions{
    display:flex !important;
    justify-content:space-between !important;
    gap:12px !important;
    flex-wrap:wrap !important;
    padding:14px 16px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.30) !important;
}

.bottom-left,
.bottom-right{
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

.pagination-bar{
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
    align-items:center !important;
    padding:12px 16px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.24) !important;
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.page-pill{
    width:34px !important;
    height:34px !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-weight:900 !important;
}

/* ================= MODALS ================= */
body.modal-open .modal-backdrop.show{
    opacity:.52 !important;
    background:#000 !important;
}

.modal-dark .modal-dialog{
    max-width:620px !important;
}

.modal-dark .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.82), rgba(17,24,39,.68)) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 24px 70px rgba(0,0,0,.58),
        0 0 45px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.modal-dark .modal-header{
    min-height:54px !important;
    padding:12px 16px !important;
    background:rgba(7,11,20,.30) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

.modal-dark .modal-title{
    font-size:17px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
}

.modal-dark .modal-body{
    padding:14px 16px !important;
    background:rgba(15,23,42,.22) !important;
    max-height:62vh !important;
    overflow-y:auto !important;
}

.modal-dark .modal-footer{
    padding:10px 16px !important;
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

.modal-dark .form-label{
    color:#e5e7eb !important;
    font-weight:900 !important;
    font-size:12px !important;
    margin-bottom:5px !important;
}

.modal-dark .form-control,
.modal-dark .form-select{
    height:38px !important;
    min-height:38px !important;
    background:rgba(255,255,255,.90) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:800 !important;
}

.modal-dark .form-select option{
    background:#fff !important;
    color:#111827 !important;
}

.modal-dark .btn-close{
    width:32px !important;
    height:32px !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
}

.modal-dark .btn-secondary{
    background:rgba(148,163,184,.18) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border-radius:10px !important;
    font-weight:900 !important;
}

.modal-dark .btn-success,
.modal-dark .btn-warning{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border:none !important;
    color:#fff !important;
    border-radius:10px !important;
    font-weight:900 !important;
}

/* ================= RESPONSIVE ================= */
@media(max-width:1200px){
    .top-grid{
        grid-template-columns:1fr !important;
    }

    .filter-bar{
        grid-template-columns:1fr 1fr !important;
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
    .filter-bar{
        grid-template-columns:1fr !important;
    }

    .bottom-actions{
        flex-direction:column !important;
    }

    .bottom-left,
    .bottom-right{
        width:100% !important;
        flex-direction:column !important;
    }

    .btn-action,
    .advanced-btn{
        width:100% !important;
    }

    .branch-select-wrap{
        flex-direction:column !important;
    }

    .refresh-btn{
        width:100% !important;
    }

    .modal-dark .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:.65rem auto !important;
    }
}



/* =========================================================
   LOW STOCK REPORT - FINAL LIGHT MODE
   Must remain at the very end before
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* Page background */
html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .main-panel{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 78% 2%,
            rgba(59,130,246,.05),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 48%,
            #faf7ff 76%,
            #f8fafc 100%
        ) !important;
}

html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ) !important;
    opacity:.5 !important;
}

html[data-pos-theme="light"] body .content-wrapper::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.08),
        transparent 68%
    ) !important;
    opacity:.45 !important;
}

/* Page shell and heading */
html[data-pos-theme="light"] body .page-shell{
    background:transparent !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Branch selector card */
html[data-pos-theme="light"] body .branch-card{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 12px 28px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .branch-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:0 16px 34px rgba(15,23,42,.09) !important;
}

html[data-pos-theme="light"] body .branch-label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body .branch-select-wrap select{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.branch-select-wrap select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
.branch-select-wrap select:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

/* Refresh button */
html[data-pos-theme="light"] body .refresh-btn{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
}

/* Summary card colored hi rahe */
html[data-pos-theme="light"] body .summary-card{
    background:linear-gradient(
        135deg,
        #3b82f6,
        #6d4cff
    ) !important;
    color:#ffffff !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .summary-card .title,
html[data-pos-theme="light"] body .summary-card .meta,
html[data-pos-theme="light"] body .summary-card .value{
    color:#ffffff !important;
}

/* Filter bar */
html[data-pos-theme="light"] body .filter-bar{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 12px 28px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Filter inputs */
html[data-pos-theme="light"] body .filter-field select,
html[data-pos-theme="light"] body .filter-field input{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body
.filter-field input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .filter-field i{
    color:#64748b !important;
}

html[data-pos-theme="light"] body
.filter-field select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
.filter-field select:focus,

html[data-pos-theme="light"] body
.filter-field input:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

/* Low Stock readonly field */
html[data-pos-theme="light"] body
.filter-field input[readonly],

html[data-pos-theme="light"] body
.filter-field .highlight{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Filter, reset and arrow buttons */
html[data-pos-theme="light"] body
.filter-bar .btn-action{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
.filter-bar .btn-action:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Inline tools */
html[data-pos-theme="light"] body .inline-tools{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 10px 24px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] body .toggle-box{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
.inline-tools .form-control{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.inline-tools .form-control:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .advanced-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .advanced-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Toggle */
html[data-pos-theme="light"] body
.form-switch .form-check-input{
    background-color:#cbd5e1 !important;
    border-color:#94a3b8 !important;
}

html[data-pos-theme="light"] body
.form-switch .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
}

/* Table card */
html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:0 16px 36px rgba(15,23,42,.09) !important;
}

/* Table header */
html[data-pos-theme="light"] body
.lowstock-table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom:1px solid #cbd5e1 !important;
}

/* Table rows */
html[data-pos-theme="light"] body
.lowstock-table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-top:1px solid #e8edf4 !important;
    box-shadow:none !important;
}

/* Table hover */
html[data-pos-theme="light"] body
.lowstock-table tbody tr:hover td,

html[data-pos-theme="light"] body
.lowstock-table tbody td:hover{
    background:#f6f2ff !important;
    color:#0f172a !important;
    box-shadow:none !important;
}

/* Empty row */
html[data-pos-theme="light"] body
.lowstock-table td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Status text */
html[data-pos-theme="light"] body .status-text{
    color:#0f172a !important;
}

/* Stock badges */
html[data-pos-theme="light"] body .stock-yellow{
    background:#fffbeb !important;
    color:#b45309 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .stock-red{
    background:#fff1f2 !important;
    color:#be123c !important;
    border:1px solid #fecdd3 !important;
}

html[data-pos-theme="light"] body .pill-low{
    background:#fff1f2 !important;
    color:#be123c !important;
    border:1px solid #fecdd3 !important;
}

html[data-pos-theme="light"] body .pill-healthy{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

/* Table footer */
html[data-pos-theme="light"] body .table-footer-top{
    background:#f8fafc !important;
    color:#64748b !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .bottom-actions{
    background:#ffffff !important;
    border-top-color:#e2e8f0 !important;
}

/* Bottom normal buttons */
html[data-pos-theme="light"] body
.bottom-actions .btn-action{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
.bottom-actions .btn-action:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Reorder */
html[data-pos-theme="light"] body
.bottom-actions .btn-action.yellow{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

/* Transfer */
html[data-pos-theme="light"] body
.bottom-actions .btn-action.green{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

/* Delete */
html[data-pos-theme="light"] body
.bottom-actions .btn-action.red{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

/* Pagination */
html[data-pos-theme="light"] body .pagination-bar{
    background:#f8fafc !important;
    color:#64748b !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .page-pill{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
}

/* Alerts */
html[data-pos-theme="light"] body .alert{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#dce4ef !important;
    backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

/* Transfer and Alerts modals */
html[data-pos-theme="light"] body
.modal-dark .modal-content{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #d8e0eb !important;
    box-shadow:0 28px 80px rgba(15,23,42,.20) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body
.modal-dark .modal-header{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body
.modal-dark .modal-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
.modal-dark .modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
.modal-dark .modal-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body
.modal-dark .form-label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
.modal-dark .form-control,

html[data-pos-theme="light"] body
.modal-dark .form-select{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body
.modal-dark .form-control:focus,

html[data-pos-theme="light"] body
.modal-dark .form-select:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body
.modal-dark .form-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
.modal-dark .btn-secondary{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.modal-dark .btn-success,

html[data-pos-theme="light"] body
.modal-dark .btn-warning{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
}
</style>
</head>
<body>
<div class="container-scroll">
 <div class="sidebar" id="dashboardSidebar">
         <div class="sidebar" id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>
    </div>

    <div class="main-panel">
        <div class="topbar"><?php include('partials/topbar.php'); ?></div>

        <div class="content-wrapper">
            <div class="page-shell">

                <?php if($msg !== ''): ?>
                    <div class="alert alert-<?= e($msgType) ?> alert-dismissible fade show" role="alert">
                        <?= e($msg) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="page-title">Low Stock Report</div>

                <form method="get" id="filterForm">
                    <?php if($logged_role_id == 1): ?>
                        <input type="hidden" name="client_id" value="<?= (int)$selected_client ?>">
                    <?php endif; ?>

                    <div class="top-grid">
                        <div class="branch-card">
                            <div class="branch-label">Branch</div>
                            <div class="branch-select-wrap">
                                <select name="branch_id" onchange="document.getElementById('filterForm').submit()">
                                    <option value="">Select Branch</option>
                                    <?php foreach($branches as $b): ?>
                                        <option value="<?= (int)$b['id'] ?>" <?= ($selected_branch == (int)$b['id']) ? 'selected' : '' ?>>
                                            <?= e($b['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="refresh-btn" onclick="window.location.href='low_stock.php<?= $logged_role_id == 1 && $selected_client ? '?client_id='.(int)$selected_client : '' ?>'">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>

                        <div class="summary-card">
                            <div class="title"><i class="bi bi-cart"></i> Low Stock</div>
                            <div class="meta">▲ 2.5% | PKR</div>
                            <div class="value"><?= number_format($lowStockValue, 0) ?> <span style="font-size:14px;font-weight:600;">PKR</span></div>
                        </div>
                    </div>

                    <div class="filter-bar">
                        <div class="filter-field">
                            <i class="bi bi-list"></i>
                            <select name="item_mode">
                                <option value="all_items" <?= $selected_item === 'all_items' ? 'selected' : '' ?>>All Items</option>
                                <option value="low_stock" <?= $selected_item === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                            </select>
                        </div>

                        <div class="filter-field">
                            <i class="bi bi-person-fill"></i>
                            <input type="text" value="Low Stock" readonly class="highlight">
                        </div>

                       <?php if($itemsHasCategory): ?>
<div class="filter-field">
    <i class="bi bi-list"></i>
    <select name="category" onchange="document.getElementById('filterForm').submit()">
        <option value="">Select Categories</option>
        <?php if (!empty($categories)): ?>
            <?php foreach($categories as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $selected_category === $cat ? 'selected' : '' ?>>
                    <?= e($cat) ?>
                </option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
</div>
<?php else: ?>
<div class="filter-field">
    <i class="bi bi-list"></i>
    <input type="text" value="Category column not found" readonly>
</div>
<?php endif; ?>

                        <?php if($itemsHasSupplierId && $suppliersTable): ?>
<div class="filter-field">
    <i class="bi bi-search"></i>
    <select name="supplier_id" onchange="document.getElementById('filterForm').submit()">
        <option value="">Select Supplier</option>
        <?php if (!empty($suppliers)): ?>
            <?php foreach($suppliers as $sup): ?>
                <option value="<?= (int)$sup['id'] ?>" <?= ($selected_supplier === (int)$sup['id']) ? 'selected' : '' ?>>
                    <?= e($sup['name']) ?>
                </option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
</div>
<?php else: ?>
<div class="filter-field">
    <i class="bi bi-search"></i>
    <input type="text" value="Supplier table/column not found" readonly>
</div>
<?php endif; ?>

                        <button type="submit" class="btn-action dark"><i class="bi bi-funnel"></i> Filter</button>
                        <a href="low_stock.php<?= $logged_role_id == 1 && $selected_client ? '?client_id='.(int)$selected_client : '' ?>" class="btn-action dark"><i class="bi bi-search"></i> Reset</a>
                        <button type="button" class="btn-action dark"><i class="bi bi-chevron-down"></i></button>
                    </div>

                    <div class="inline-tools">
                        <div class="toggle-box">
                            <i class="bi bi-clock-history"></i>
                            Days Until Stockout
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="days_stockout" value="1" <?= $days_stockout ? 'checked' : '' ?> onchange="document.getElementById('filterForm').submit()">
                            </div>
                        </div>

                        <div class="d-flex gap-2 align-items-center">
                            <input type="number" class="form-control" name="threshold" value="<?= (int)$threshold ?>" style="width:120px;height:42px;">
                            <button type="submit" class="advanced-btn"><i class="bi bi-gear-fill"></i> Advanced</button>
                        </div>
                    </div>
                </form>

                <form method="post" id="bulkForm">
                    <div class="table-card">
                        <div class="table-responsive">
                            <table class="lowstock-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="mainCheckbox" onclick="toggleAllRows(this)"></th>
                                        <th>#</th>
                                        <th>Item Name</th>
                                        <th>Current Stock</th>
                                        <th>Avg. Daily Sales</th>
                                        <th>Supplier</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($rows) > 0): ?>
                                        <?php foreach($rows as $index => $r): ?>
                                            <?php
                                                $currentStock = (int)($r['branch_qty'] ?? 0);
                                                $stockClass = $currentStock <= 2 ? 'stock-red' : 'stock-yellow';
                                                $statusPillClass = $r['status_label'] === 'Healthy' ? 'pill-healthy' : 'pill-low';
                                            ?>
                                            <tr>
                                                <td><input type="checkbox" class="rowCheck" name="selected_ids[]" value="<?= (int)$r['item_id'] ?>"></td>
                                                <td><?= $index + 1 ?></td>
                                                <td><?= e($r['item_name']) ?></td>
                                                <td>
                                                    <div style="display:flex;align-items:center;gap:12px;">
                                                        <span><?= $currentStock + rand(0,10) ?></span>
                                                        <span class="stock-badge <?= $stockClass ?>"><?= $currentStock ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="display:flex;align-items:center;gap:12px;">
                                                        <span><?= ($index % 2 === 0 ? '+' : '-') . $r['avg_daily_sales'] ?></span>
                                                        <div class="sales-bar">
                                                            <span class="sales-seg seg1"></span>
                                                            <span class="sales-seg seg2"></span>
                                                            <span class="sales-seg seg3"></span>
                                                            <span class="sales-seg seg4"></span>
                                                            <span class="sales-seg seg5"></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?= e($r['supplier_name'] ?: '-') ?></td>
                                                <td><span class="status-text"><?= e($r['status_label']) ?></span></td>
                                                <td><span class="<?= $statusPillClass ?>"><?= e($r['status_label']) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">No low stock items found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="table-footer-top">
                            <?= count($rows) ?> items<?= $selected_item === 'low_stock' ? ' low in stock' : '' ?>
                        </div>

                        <div class="bottom-actions">
                            <div class="bottom-left">
                                <button class="btn-action" type="button"><i class="bi bi-archive"></i> Bulk Actions</button>
                                <button class="btn-action yellow" type="button" onclick="submitBulkAction('mark_reorder')"><i class="bi bi-cart"></i> Mark Reorder</button>
                                <button class="btn-action green" type="button" onclick="openTransferModal()"><i class="bi bi-people-fill"></i> Transfer</button>
                                <button class="btn-action red" type="button" onclick="submitBulkAction('bulk_delete')"><i class="bi bi-trash"></i> Delete</button>
                            </div>

                            <div class="bottom-right">
                                <a class="btn-action" href="low_stock.php<?= ($logged_role_id == 1 && $selected_client ? '?client_id='.(int)$selected_client.'&' : '?') ?>export=csv"><i class="bi bi-download"></i> Export CSV</a>
                                <button class="btn-action" type="button" onclick="openAlertsModal()"><i class="bi bi-bell-fill"></i> Manage Alerts</button>
                                <a class="btn-action" href="low_stock.php<?= ($logged_role_id == 1 && $selected_client ? '?client_id='.(int)$selected_client.'&' : '?') ?>export=csv"><i class="bi bi-bar-chart-fill"></i> Reports</a>
                                <button class="btn-action" type="button"><i class="bi bi-chevron-right"></i></button>
                            </div>
                        </div>

                        <div class="pagination-bar">
                            <span>&lt; Previous</span>
                            <span class="page-pill">1</span>
                            <span>Next &gt;</span>
                            <span class="btn-action" style="height:34px;padding:0 10px;"><i class="bi bi-chevron-right"></i></span>
                        </div>
                    </div>

                    <input type="hidden" name="action" id="bulkActionInput">
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade modal-dark" id="transferModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="transferForm">
                <div class="modal-header">
                    <h5 class="modal-title">Transfer Selected Low Stock Items</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_transfer">
                    <div id="transferSelectedContainer"></div>

                    <div class="mb-3">
                        <label class="form-label">From Branch</label>
                        <select name="from_branch_id" class="form-select" required>
                            <option value="">Select Branch</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $selected_branch == (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">To Branch</label>
                        <select name="to_branch_id" class="form-select" required>
                            <option value="">Select Branch</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Transfer Qty</label>
                        <input type="number" name="transfer_qty" class="form-control" min="1" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <input type="text" name="transfer_reason" class="form-control" value="transfer">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success" type="submit">Transfer Now</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Alerts Modal -->
<div class="modal fade modal-dark" id="alertsModal" tabindex="-1">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="alertsForm">
                <div class="modal-header">
                    <h5 class="modal-title">Manage Low Stock Alerts</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="manage_alerts">
                    <div id="alertsSelectedContainer"></div>

                    <div class="mb-3">
                        <label class="form-label">New Low Stock Alert Threshold</label>
                        <input type="number" name="new_low_stock_alert" class="form-control" min="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-warning" type="submit">Update Alerts</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
const alertsModal = new bootstrap.Modal(document.getElementById('alertsModal'));

function toggleAllRows(main){
    document.querySelectorAll('.rowCheck').forEach(el => el.checked = main.checked);
}

function getSelectedIds(){
    return Array.from(document.querySelectorAll('.rowCheck:checked')).map(el => el.value);
}

function submitBulkAction(action){
    const ids = getSelectedIds();
    if(ids.length === 0){
        alert('Please select at least one item.');
        return;
    }

    if(action === 'bulk_delete'){
        if(!confirm('Are you sure you want to delete selected items?')) return;
    }

    const form = document.getElementById('bulkForm');
    document.getElementById('bulkActionInput').value = action;
    form.submit();
}

function fillHiddenIds(containerId){
    const ids = getSelectedIds();
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        container.appendChild(input);
    });
}

function openTransferModal(){
    const ids = getSelectedIds();
    if(ids.length === 0){
        alert('Please select at least one item.');
        return;
    }
    fillHiddenIds('transferSelectedContainer');
    transferModal.show();
}

function openAlertsModal(){
    const ids = getSelectedIds();
    if(ids.length === 0){
        alert('Please select at least one item.');
        return;
    }
    fillHiddenIds('alertsSelectedContainer');
    alertsModal.show();
}
</script>



</body>
</html>