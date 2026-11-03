
<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

// ===== SHOW ERRORS FOR DEVELOPMENT =====
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
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
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($res['cnt'] ?? 0) > 0);
}

function get_stock_status(float $qty, int $lowAlert = 10): array
{
    if ($qty <= 0) {
        return ['label' => 'Out of Stock', 'class' => 'status-out'];
    }
    if ($qty <= $lowAlert) {
        return ['label' => 'Low Stock', 'class' => 'status-low'];
    }
    return ['label' => 'In Stock', 'class' => 'status-in'];
}

$itemsHasClientId = column_exists($conn, 'items', 'client_id');
$branchesHasClientId = column_exists($conn, 'branches', 'client_id');
$stockMovementsHasClientId = column_exists($conn, 'stock_movements', 'client_id');
$hasCategory = column_exists($conn, 'items', 'category');

function table_exists(mysqli $conn, string $table): bool
{
    $sql = "
        SELECT COUNT(*) AS cnt
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($res['cnt'] ?? 0) > 0);
}

$thresholdRules = [];
$defaultThreshold = 10;
$hasThresholdRules = table_exists($conn, 'inventory_threshold_rules');

if ($hasThresholdRules) {
    $stmt = $conn->prepare("
        SELECT branch_id, category_name, threshold_qty, notify_enabled
        FROM inventory_threshold_rules
        WHERE client_id = ?
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $key = (int)$row['branch_id'] . '|' . trim((string)$row['category_name']);
        $thresholdRules[$key] = $row;

        if ((int)$row['branch_id'] === 0 && trim((string)$row['category_name']) === '') {
            $defaultThreshold = (int)$row['threshold_qty'];
        }
    }

    $stmt->close();
}

function resolve_threshold_for_item(array $item, int $branchId, array $rules, int $defaultThreshold = 10): int
{
    $category = trim((string)($item['category'] ?? ''));

    $branchKeys = $branchId > 0 ? [$branchId, 0] : [0];
    $catKeys = $category !== '' ? [$category, ''] : [''];

    foreach ($branchKeys as $b) {
        foreach ($catKeys as $c) {
            $key = (int)$b . '|' . $c;
            if (isset($rules[$key])) {
                $v = (int)$rules[$key]['threshold_qty'];
                return $v >= 0 ? $v : $defaultThreshold;
            }
        }
    }

    $itemAlert = (int)($item['low_stock_alert'] ?? $defaultThreshold);
    return $itemAlert >= 0 ? $itemAlert : $defaultThreshold;
}

$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ================= FETCH BRANCHES ================= */
$branches = [];
$branchMap = [];

if ($branchesHasClientId) {
    $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $client_id);
} else {
    $stmt = $conn->prepare("SELECT id, name FROM branches ORDER BY name ASC");
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $branches[] = $row;
    $branchMap[$row['id']] = $row['name'];
}
$stmt->close();

/* ================= FETCH CATEGORIES ================= */
$categories = [];
if ($hasCategory) {
    if ($itemsHasClientId) {
        $stmt = $conn->prepare("SELECT DISTINCT category FROM items WHERE client_id = ? AND category IS NOT NULL AND category != '' ORDER BY category ASC");
        $stmt->bind_param("i", $client_id);
    } else {
        $stmt = $conn->prepare("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    $stmt->close();
}

/* ================= FILTERS ================= */
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$statusFilter = trim($_GET['stock_status'] ?? '');

/* ================= FETCH ITEMS ================= */
if ($itemsHasClientId) {
    $sql = "SELECT * FROM items WHERE client_id = ?";
    $params = [$client_id];
    $types = "i";
} else {
    $sql = "SELECT * FROM items WHERE 1=1";
    $params = [];
    $types = "";
}

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR sku LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if ($hasCategory && $categoryFilter !== '') {
    $sql .= " AND category = ?";
    $params[] = $categoryFilter;
    $types .= "s";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while($r = $result->fetch_assoc()) {
    $items[] = $r;
}
$stmt->close();

/* ================= FETCH STOCK ================= */
foreach($items as &$item){
    $item['stock'] = [];
    $item['total_stock'] = 0;

    if ($stockMovementsHasClientId) {
        $stmt = $conn->prepare("
            SELECT 
                branch_id,
                COALESCE(SUM(
                    CASE
                        WHEN type='in' THEN qty
                        WHEN type='out' THEN -qty
                        WHEN type='adjustment' THEN qty
                        ELSE 0
                    END
                ), 0) AS qty
            FROM stock_movements
            WHERE client_id = ? AND item_id = ?
            GROUP BY branch_id
        ");
        $stmt->bind_param("ii", $client_id, $item['id']);
    } else {
        $stmt = $conn->prepare("
            SELECT 
                branch_id,
                COALESCE(SUM(
                    CASE
                        WHEN type='in' THEN qty
                        WHEN type='out' THEN -qty
                        WHEN type='adjustment' THEN qty
                        ELSE 0
                    END
                ), 0) AS qty
            FROM stock_movements
            WHERE item_id = ?
            GROUP BY branch_id
        ");
        $stmt->bind_param("i", $item['id']);
    }

    $stmt->execute();
    $stock_res = $stmt->get_result();
    while($s = $stock_res->fetch_assoc()){
        $qty = (float)($s['qty'] ?? 0);
        $item['stock'][$s['branch_id']] = $qty;
        $item['total_stock'] += $qty;
    }
    $stmt->close();

    $lowAlert = resolve_threshold_for_item($item, 0, $thresholdRules, $defaultThreshold);
    $item['resolved_threshold'] = $lowAlert;
    $item['stock_status_meta'] = get_stock_status($item['total_stock'], $lowAlert);
}
unset($item);

/* ================= APPLY BRANCH/STOCK FILTERS ================= */
$filteredItems = [];

foreach ($items as $item) {
    $pass = true;

    if ($branchFilter > 0) {
        $branchQty = (float)($item['stock'][$branchFilter] ?? 0);
        $lowAlert = resolve_threshold_for_item($item, $branchFilter, $thresholdRules, $defaultThreshold);

        if ($statusFilter === 'in_stock' && !($branchQty > $lowAlert)) $pass = false;
        if ($statusFilter === 'low_stock' && !($branchQty > 0 && $branchQty <= $lowAlert)) $pass = false;
        if ($statusFilter === 'out_of_stock' && !($branchQty <= 0)) $pass = false;
    } else {
        $total = (float)$item['total_stock'];
        $lowAlert = resolve_threshold_for_item($item, 0, $thresholdRules, $defaultThreshold);

        if ($statusFilter === 'in_stock' && !($total > $lowAlert)) $pass = false;
        if ($statusFilter === 'low_stock' && !($total > 0 && $total <= $lowAlert)) $pass = false;
        if ($statusFilter === 'out_of_stock' && !($total <= 0)) $pass = false;
    }

    if ($pass) {
        $filteredItems[] = $item;
    }
}
$items = $filteredItems;

if (($_POST['action'] ?? '') === 'delete_item') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo "Invalid ID";
        exit;
    }

    // delete stock movements first (optional but recommended)
    if ($stockMovementsHasClientId) {
        $stmt = $conn->prepare("DELETE FROM stock_movements WHERE item_id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM stock_movements WHERE item_id = ?");
        $stmt->bind_param("i", $id);
    }
    $stmt->execute();
    $stmt->close();

    // delete item
    if ($itemsHasClientId) {
        $stmt = $conn->prepare("DELETE FROM items WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
        $stmt->bind_param("i", $id);
    }

    if ($stmt->execute()) {
        echo "ok";
    } else {
        echo "Delete failed";
    }

    $stmt->close();
    exit;
}

/* ================= STATS ================= */
$totalProducts = count($items);
$totalStockQty = 0;
$lowStockItems = 0;
$outOfStockItems = 0;

foreach ($items as $item) {
    if ($branchFilter > 0) {
        $qty = (float)($item['stock'][$branchFilter] ?? 0);
        $lowAlert = resolve_threshold_for_item($item, $branchFilter, $thresholdRules, $defaultThreshold);
    } else {
        $qty = (float)$item['total_stock'];
        $lowAlert = resolve_threshold_for_item($item, 0, $thresholdRules, $defaultThreshold);
    }

    $totalStockQty += $qty;

    if ($qty <= 0) {
        $outOfStockItems++;
    } elseif ($qty <= $lowAlert) {
        $lowStockItems++;
    }
}

/* ================= EXPORT ================= */
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=inventory_stock_' . date('Y-m-d_H-i-s') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Product Name', 'SKU', 'Price', 'Stock Qty', 'Min Threshold', 'Branch', 'Status']);

    foreach ($items as $item) {
        if (!empty($item['stock'])) {
            foreach ($item['stock'] as $branch_id => $qty) {
                $threshold = resolve_threshold_for_item($item, (int)$branch_id, $thresholdRules, $defaultThreshold);
                $meta = get_stock_status((float)$qty, $threshold);
                fputcsv($output, [
                    $item['id'],
                    $item['name'],
                    $item['sku'] ?? '',
                    $item['price'],
                    $qty,
                    $threshold,
                    $branchMap[$branch_id] ?? $branch_id,
                    $meta['label']
                ]);
            }
        } else {
            $threshold = resolve_threshold_for_item($item, 0, $thresholdRules, $defaultThreshold);
            $meta = get_stock_status(0, $threshold);
            fputcsv($output, [
                $item['id'],
                $item['name'],
                $item['sku'] ?? '',
                $item['price'],
                0,
                $threshold,
                '-',
                $meta['label']
            ]);
        }
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Inventory - SaaS POS</title>
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
body{margin:0;background:#f3f4fb;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;}

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
.topbar{min-height:60px;border-bottom:1px solid #dee2e6;display:flex;align-items:center;padding:0 20px;background:#fff;}
.content-wrapper{padding:20px;flex-grow:1;overflow-y:auto;}
.page-header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
.page-title{font-size:28px;font-weight:700;color:#1f2937;margin:0;}
.header-actions{display:flex;gap:10px;flex-wrap:wrap;}
.btn-soft{border:none;border-radius:10px;padding:10px 16px;color:#fff;font-weight:600;font-size:14px;box-shadow:0 8px 18px rgba(0,0,0,0.08);display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.btn-add{background:#2e9e59;}
.btn-export-top{background:#5c4fd6;}
.btn-reset-top{background:#fff;color:#4b5675;border:1px solid #d8ddea;box-shadow:none;}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px;}
.stat-card{color:#fff;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 8px 18px rgba(0,0,0,0.08);}
.card-blue{background:linear-gradient(135deg,#4095ff,#2f80ed);}
.card-green{background:linear-gradient(135deg,#49be88,#2ea769);}
.card-yellow{background:linear-gradient(135deg,#f7c943,#f4b400);}
.card-red{background:linear-gradient(135deg,#ef6b63,#d9534f);}
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;}
.search-wrap,.filter-control-wrap{flex:1;min-width:180px;position:relative;}
.search-wrap i,.filter-control-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#6b7280;}
.search-wrap input,.filter-control-wrap select{width:100%;height:44px;border:1px solid #e5e7eb;border-radius:10px;padding:0 14px 0 38px;outline:none;background:#fff;}
.filter-btn{height:44px;border:none;border-radius:10px;padding:0 18px;background:#1f2937;color:#fff;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;}



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


.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;}
.table-responsive{overflow:auto;}
.inventory-table{width:100%;min-width:1100px;border-collapse:collapse;}
.inventory-table thead th{background:#28344d;color:#fff;font-weight:600;font-size:14px;padding:14px 12px;white-space:nowrap;}
.inventory-table tbody td{padding:14px 12px;border-top:1px solid #edf0f5;vertical-align:middle;font-size:14px;color:#374151;}
.img-thumb{width:60px;height:60px;object-fit:cover;border-radius:50%;}
.status-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;}
.status-in{background:#dcfce7;color:#166534;}
.status-low{background:#fef3c7;color:#92400e;}
.status-out{background:#fee2e2;color:#991b1b;}
.modal-header{background:linear-gradient(135deg,#111827,#374151);color:#fff;}
.stock-modal-content{background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);color:#fff;border:none;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.35);}
.stock-modal-header{background:transparent;border-bottom:1px solid rgba(255,255,255,0.08);padding:18px 22px;}
.stock-modal-body{padding:20px 22px 10px;}
.stock-label{display:block;font-size:15px;margin-bottom:7px;color:#e4e8f8;font-weight:500;}
.stock-label span{color:#f4b21c;}
.stock-input,.stock-textarea{width:100%;border:1px solid rgba(255,255,255,0.10);background:#e9eaf3;color:#222;border-radius:8px;height:44px;padding:0 14px;outline:none;font-size:14px;}
.stock-textarea{min-height:70px;resize:none;padding:12px 14px;}
.qty-box{display:flex;align-items:center;border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,0.10);background:#e9eaf3;}
.qty-btn{width:52px;height:44px;border:none;background:#f1f2f7;font-size:22px;color:#222;}
.qty-input{text-align:center;border:none !important;background:transparent !important;font-weight:700;}
.qty-mini-bar{height:10px;border-radius:999px;background:linear-gradient(90deg,#5b7cff 0%,#f0b324 35%,#49be88 70%,#6dc4a4 100%);margin-top:10px;}
.qty-stats{display:flex;justify-content:space-between;font-size:12px;color:#d6dcf0;margin-top:8px;}
.stock-modal-footer{border-top:1px solid rgba(255,255,255,0.08);padding:16px 22px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;}
.footer-btns{display:flex;align-items:center;gap:10px;}
.cancel-btn{height:42px;min-width:110px;border:none;border-radius:8px;background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);color:#fff;font-weight:600;}
.save-btn{height:42px;min-width:130px;border:none;border-radius:8px;background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);color:#fff;font-weight:700;}
.switch{position:relative;display:inline-block;width:54px;height:28px;}
.switch input{display:none;}
.slider{position:absolute;inset:0;cursor:pointer;background:#6b728c;transition:.3s;border-radius:34px;}
.slider:before{content:"";position:absolute;height:22px;width:22px;left:3px;bottom:3px;background:white;transition:.3s;border-radius:50%;}
input:checked + .slider{background:#58c27d;}
input:checked + .slider:before{transform:translateX(26px);}
@media (max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr);}}
@media (max-width:768px){.stats-row{grid-template-columns:1fr;}.filter-bar{flex-direction:column;align-items:stretch;}.search-wrap,.filter-control-wrap,.filter-btn{width:100%;min-width:100%;}}


/* =========================================================
   FINAL INVENTORY STOCK POPUP BLACK + GOLD + WHITE THEME
   Adjust Stock Modal
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

/*body.modal-open .modal-backdrop.show{*/
/*    opacity:.74 !important;*/
/*    background:#000 !important;*/
/*}*/

/* Modal size */
/*#stockModal .modal-dialog{*/
/*    max-width:min(1240px,94vw) !important;*/
/*}*/

/* Main popup container */
/*#stockModal .modal-content,*/
/*#stockModal .stock-modal-content{*/
/*    background:#0f1117 !important;*/
/*    color:#ffffff !important;*/
/*    border:1px solid rgba(201,168,76,.62) !important;*/
/*    border-radius:18px !important;*/
/*    overflow:hidden !important;*/
/*    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;*/
/*}*/

/* Header */
/*#stockModal .modal-header,*/
/*#stockModal .stock-modal-header{*/
/*    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;*/
/*    border-bottom:2px solid #c9a84c !important;*/
/*    padding:22px 24px !important;*/
/*    min-height:76px !important;*/
/*}*/

/*#stockModal .modal-title{*/
/*    color:#ffffff !important;*/
/*    font-size:22px !important;*/
/*    font-weight:900 !important;*/
/*    letter-spacing:-.4px !important;*/
/*    display:flex !important;*/
/*    align-items:center !important;*/
/*    gap:10px !important;*/
/*    margin:0 !important;*/
/*}*/

/*#stockModal .modal-title::before{*/
/*    content:'' !important;*/
/*    width:13px !important;*/
/*    height:13px !important;*/
/*    border-radius:50% !important;*/
/*    background:#c9a84c !important;*/
/*    box-shadow:0 0 0 5px rgba(201,168,76,.15) !important;*/
/*    display:inline-block !important;*/
/*    flex-shrink:0 !important;*/
/*}*/

/* Close button - proper white X */
/*#stockModal .btn-close{*/
/*    position:relative !important;*/
/*    width:48px !important;*/
/*    height:48px !important;*/
/*    border-radius:10px !important;*/
/*    background:#252b3a !important;*/
/*    border:1px solid rgba(255,255,255,.14) !important;*/
/*    opacity:1 !important;*/
/*    background-image:none !important;*/
/*    box-shadow:none !important;*/
/*    padding:0 !important;*/
/*    filter:none !important;*/
/*}*/

/*#stockModal .btn-close::before,*/
/*#stockModal .btn-close::after{*/
/*    content:'' !important;*/
/*    position:absolute !important;*/
/*    top:50% !important;*/
/*    left:50% !important;*/
/*    width:22px !important;*/
/*    height:2px !important;*/
/*    background:#ffffff !important;*/
/*    border-radius:2px !important;*/
/*    transform-origin:center !important;*/
/*}*/

/*#stockModal .btn-close::before{*/
/*    transform:translate(-50%,-50%) rotate(45deg) !important;*/
/*}*/

/*#stockModal .btn-close::after{*/
/*    transform:translate(-50%,-50%) rotate(-45deg) !important;*/
/*}*/

/*#stockModal .btn-close:hover{*/
/*    background:#c94b57 !important;*/
/*    transform:translateY(-1px) !important;*/
/*}*/

/* Body */
/*#stockModal .modal-body,*/
/*#stockModal .stock-modal-body{*/
/*    background:#0f1117 !important;*/
/*    color:#ffffff !important;*/
/*    padding:26px 24px 22px !important;*/
/*}*/

/*#stockModal .stock-modal-body::-webkit-scrollbar{*/
/*    width:7px !important;*/
/*}*/

/*#stockModal .stock-modal-body::-webkit-scrollbar-track{*/
/*    background:#171c28 !important;*/
/*    border-radius:999px !important;*/
/*}*/

/*#stockModal .stock-modal-body::-webkit-scrollbar-thumb{*/
/*    background:#c9a84c !important;*/
/*    border-radius:999px !important;*/
/*}*/

/* Footer */
/*#stockModal .modal-footer,*/
/*#stockModal .stock-modal-footer{*/
/*    background:#0f1117 !important;*/
/*    border-top:1px solid rgba(201,168,76,.28) !important;*/
/*    padding:18px 24px 22px !important;*/
/*}*/

/* Labels */
/*#stockModal .stock-label,*/
/*#stockModal label{*/
/*    color:#ffffff !important;*/
/*    font-size:14px !important;*/
/*    font-weight:900 !important;*/
/*    margin-bottom:9px !important;*/
/*    letter-spacing:-.1px !important;*/
/*}*/

/*#stockModal .stock-label span{*/
/*    color:#f5c742 !important;*/
/*}*/

/* Inputs / selects / textarea */
/*#stockModal .stock-input,*/
/*#stockModal .stock-textarea,*/
/*#stockModal input[type="text"],*/
/*#stockModal input[type="number"],*/
/*#stockModal select,*/
/*#stockModal textarea{*/
/*    background:#ffffff !important;*/
/*    color:#111827 !important;*/
/*    border:1.5px solid #d9dfec !important;*/
/*    border-radius:10px !important;*/
/*    font-size:15px !important;*/
/*    font-weight:500 !important;*/
/*    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;*/
/*}*/

/*#stockModal .stock-input,*/
/*#stockModal input[type="text"],*/
/*#stockModal input[type="number"],*/
/*#stockModal select{*/
/*    min-height:50px !important;*/
/*    height:50px !important;*/
/*    padding:10px 16px !important;*/
/*}*/

/*#stockModal .stock-textarea,*/
/*#stockModal textarea{*/
/*    min-height:112px !important;*/
/*    height:auto !important;*/
/*    padding:14px 16px !important;*/
/*    resize:vertical !important;*/
/*}*/

/*#stockModal input::placeholder,*/
/*#stockModal textarea::placeholder{*/
/*    color:#8993a5 !important;*/
/*}*/

/*#stockModal .stock-input:focus,*/
/*#stockModal .stock-textarea:focus,*/
/*#stockModal input:focus,*/
/*#stockModal select:focus,*/
/*#stockModal textarea:focus{*/
/*    background:#ffffff !important;*/
/*    border-color:#c9a84c !important;*/
/*    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;*/
/*    outline:none !important;*/
/*}*/

/* Readonly item input */
/*#stockModal input[readonly]{*/
/*    background:#f6f7fb !important;*/
/*    color:#4b5563 !important;*/
/*    font-weight:800 !important;*/
/*}*/

/* Quantity box */
/*#stockModal .qty-box{*/
/*    background:#ffffff !important;*/
/*    border:1.5px solid #d9dfec !important;*/
/*    border-radius:10px !important;*/
/*    overflow:hidden !important;*/
/*    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;*/
/*}*/

/*#stockModal .qty-btn{*/
/*    width:56px !important;*/
/*    height:50px !important;*/
/*    border:none !important;*/
/*    background:#fff7df !important;*/
/*    color:#111827 !important;*/
/*    font-size:24px !important;*/
/*    font-weight:900 !important;*/
/*}*/

/*#stockModal .qty-btn:hover{*/
/*    background:#f5c742 !important;*/
/*    color:#111827 !important;*/
/*}*/

/*#stockModal .qty-input{*/
/*    background:#ffffff !important;*/
/*    border:none !important;*/
/*    box-shadow:none !important;*/
/*    color:#111827 !important;*/
/*    font-weight:900 !important;*/
/*}*/

/*#stockModal .qty-mini-bar{*/
/*    background:linear-gradient(90deg,#f5c742 0%,#d8a500 42%,#39b36d 100%) !important;*/
/*    box-shadow:0 5px 14px rgba(216,165,0,.18) !important;*/
/*}*/

/*#stockModal .qty-stats{*/
/*    color:#dbe0f4 !important;*/
/*    font-weight:800 !important;*/
/*}*/

/*#stockModal .qty-stats strong{*/
/*    color:#f5c742 !important;*/
/*}*/

/* Stock tracking toggle footer */
/*#stockModal .track-toggle-wrap{*/
/*    background:#151a26 !important;*/
/*    border:1px solid rgba(201,168,76,.28) !important;*/
/*    border-radius:12px !important;*/
/*    padding:12px 14px !important;*/
/*    color:#ffffff !important;*/
/*    font-weight:800 !important;*/
/*}*/

/*#stockModal .switch input:checked + .slider{*/
/*    background:linear-gradient(180deg,#f5c742,#d8a500) !important;*/
/*}*/

/*#stockModal .slider{*/
/*    background:#3a4156 !important;*/
/*}*/

/* Footer buttons */
/*#stockModal .cancel-btn{*/
/*    height:44px !important;*/
/*    min-width:110px !important;*/
/*    border:none !important;*/
/*    border-radius:10px !important;*/
/*    background:#252b3a !important;*/
/*    color:#ffffff !important;*/
/*    font-weight:900 !important;*/
/*    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;*/
/*}*/

/*#stockModal .cancel-btn:hover{*/
/*    background:#32394b !important;*/
/*    color:#ffffff !important;*/
/*}*/

/*#stockModal .save-btn{*/
/*    height:44px !important;*/
/*    min-width:130px !important;*/
/*    border:none !important;*/
/*    border-radius:10px !important;*/
/*    background:linear-gradient(180deg,#f5c742,#d8a500) !important;*/
/*    color:#111827 !important;*/
/*    font-weight:900 !important;*/
/*    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;*/
/*}*/

/*#stockModal .save-btn:hover{*/
/*    background:linear-gradient(180deg,#e8bb34,#c99700) !important;*/
/*    transform:translateY(-1px) !important;*/
/*}*/

/*@media (max-width:768px){*/
/*    #stockModal .modal-dialog{*/
/*        max-width:96vw !important;*/
/*        margin:.75rem auto !important;*/
/*    }*/

/*    #stockModal .modal-title{*/
/*        font-size:18px !important;*/
/*    }*/

/*    #stockModal .btn-close{*/
/*        width:42px !important;*/
/*        height:42px !important;*/
/*    }*/

/*    #stockModal .cancel-btn,*/
/*    #stockModal .save-btn{*/
/*        width:100% !important;*/
/*    }*/

/*    #stockModal .stock-modal-footer{*/
/*        align-items:stretch !important;*/
/*    }*/

/*    #stockModal .footer-btns{*/
/*        width:100% !important;*/
/*        flex-direction:column !important;*/
/*    }*/

/*    #stockModal .track-toggle-wrap{*/
/*        width:100% !important;*/
/*    }*/
/*}*/



/* =====================================================
   INVENTORY.PHP - DARK PURPLE BLACK GLASS THEME
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

/* ================= PAGE HEADER ================= */
.page-header{
    position:relative !important;
    z-index:50 !important;
    margin-bottom:20px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    flex-wrap:wrap !important;
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

/* ================= TOP BUTTONS ================= */
.btn-soft{
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

.btn-soft:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

.btn-add{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

.btn-export-top{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
    border:1px solid rgba(139,92,246,.32) !important;
}

.btn-reset-top{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

/* ================= STATS ================= */
.stats-row{
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

.stat-card div{
    position:relative !important;
    z-index:3 !important;
}

.stat-card div div{
    font-size:15px !important;
    font-weight:900 !important;
    color:rgba(255,255,255,.96) !important;
}

.stat-card strong{
    font-size:clamp(22px, 2vw, 30px) !important;
    font-weight:900 !important;
    color:#fff !important;
    line-height:1.1 !important;
    white-space:nowrap !important;
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
.card-green{ background:linear-gradient(135deg,#047857,#16a34a) !important; }
.card-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c) !important; }
.card-red{ background:linear-gradient(135deg,#be123c,#ef4444) !important; }

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
    grid-template-columns:1.4fr repeat(3, minmax(170px, .8fr)) auto !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:18px !important;
    flex-wrap:nowrap !important;
}

.search-wrap,
.filter-control-wrap{
    width:100% !important;
    min-width:0 !important;
    position:relative !important;
    flex:none !important;
}

.search-wrap i,
.filter-control-wrap i{
    position:absolute !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#94a3b8 !important;
    font-size:15px !important;
    z-index:2 !important;
}

.search-wrap input,
.filter-control-wrap select{
    width:100% !important;
    min-width:0 !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px 0 42px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
    box-shadow:none !important;
    transition:.2s !important;
}

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.search-wrap input:focus,
.filter-control-wrap select:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
}

.filter-control-wrap select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.filter-btn{
    height:46px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
}

.filter-btn:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

/* ================= TABLE ================= */
.table-card{
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

.table-card:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.table-responsive{
    width:100% !important;
    overflow:auto !important;
}

.inventory-table{
    width:100% !important;
    min-width:1150px !important;
    border-collapse:collapse !important;
    background:transparent !important;
    color:#e5e7eb !important;
}

.inventory-table thead th{
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

.inventory-table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
    white-space:nowrap !important;
}

/* White hover remove */
.inventory-table tbody tr:hover td,
.inventory-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
    box-shadow:none !important;
}

.inventory-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.inventory-table .text-muted{
    color:#94a3b8 !important;
}

.img-thumb{
    width:54px !important;
    height:54px !important;
    object-fit:cover !important;
    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.20) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

/* ================= STATUS PILLS ================= */
.status-pill{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    padding:7px 12px !important;
    border-radius:999px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.status-in{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.status-low{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.status-out{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
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

/* =====================================================
   STOCK MODAL - DARK PURPLE GLASS THEME
===================================================== */

body.modal-open .modal-backdrop.show{
    opacity:.76 !important;
    background:#000 !important;
}

#stockModal{
    z-index:99999 !important;
}

#stockModal .modal-dialog{
    max-width:1050px !important;
    width:calc(100% - 40px) !important;
    margin:1.75rem auto !important;
}

#stockModal .modal-content,
#stockModal .stock-modal-content{
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

/* Modal header */
#stockModal .modal-header,
#stockModal .stock-modal-header{
    min-height:64px !important;
    padding:16px 20px !important;
    background:rgba(7,11,20,.50) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#stockModal .modal-title{
    font-size:20px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
}

#stockModal .modal-title::before{
    display:none !important;
}

/* Close button */
#stockModal .btn-close{
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

#stockModal .btn-close::before,
#stockModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:18px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#stockModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#stockModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#stockModal .btn-close:hover{
    background:rgba(239,68,68,.22) !important;
    border-color:rgba(239,68,68,.35) !important;
}

/* Modal body */
#stockModal .modal-body,
#stockModal .stock-modal-body{
    padding:20px !important;
    background:rgba(15,23,42,.50) !important;
    max-height:calc(100vh - 170px) !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* Force modal grid alignment */
#stockModal .row{
    display:grid !important;
    grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
    gap:16px !important;
    margin:0 !important;
}

#stockModal .row > [class*="col-"]{
    width:100% !important;
    max-width:100% !important;
    padding:0 !important;
    min-width:0 !important;
}

#stockModal .col-12{
    grid-column:1 / -1 !important;
}

/* Labels */
#stockModal .stock-label,
#stockModal label{
    display:block !important;
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin:0 0 7px !important;
    line-height:1.25 !important;
}

#stockModal .stock-label span{
    color:#a78bfa !important;
}

/* Inputs/selects */
#stockModal .stock-input,
#stockModal input[type="text"],
#stockModal input[type="number"],
#stockModal select{
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

#stockModal input[readonly]{
    background:#f1f5f9 !important;
    color:#475569 !important;
    font-weight:900 !important;
}

/* Textarea */
#stockModal .stock-textarea,
#stockModal textarea{
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

#stockModal .stock-input:focus,
#stockModal .stock-textarea:focus,
#stockModal input:focus,
#stockModal select:focus,
#stockModal textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 4px rgba(124,58,237,.22),
        0 10px 22px rgba(0,0,0,.18) !important;
    outline:none !important;
}

/* Quantity */
#stockModal .qty-box{
    width:100% !important;
    height:44px !important;
    display:grid !important;
    grid-template-columns:54px 1fr 54px !important;
    align-items:center !important;
    overflow:hidden !important;
    border-radius:12px !important;
    background:#fff !important;
    border:1px solid rgba(203,213,225,.95) !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#stockModal .qty-btn{
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

#stockModal .qty-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
}

#stockModal .qty-input{
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

#stockModal .qty-mini-bar{
    height:9px !important;
    margin-top:9px !important;
    border-radius:999px !important;
    background:linear-gradient(90deg,#6d4cff 0%,#7c3aed 38%,#22c55e 100%) !important;
}

#stockModal .qty-stats{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:8px !important;
    margin-top:7px !important;
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:800 !important;
}

#stockModal .qty-stats strong{
    color:#a78bfa !important;
}

/* Footer */
#stockModal .modal-footer,
#stockModal .stock-modal-footer{
    padding:14px 20px !important;
    background:rgba(7,11,20,.52) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    flex-wrap:wrap !important;
}

#stockModal .track-toggle-wrap{
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

#stockModal .footer-btns{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:10px !important;
    margin-left:auto !important;
}

#stockModal .cancel-btn,
#stockModal .save-btn{
    height:42px !important;
    min-width:120px !important;
    border-radius:12px !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:0 18px !important;
}

#stockModal .cancel-btn{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

#stockModal .cancel-btn:hover{
    background:rgba(148,163,184,.28) !important;
    color:#fff !important;
}

#stockModal .save-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#stockModal .save-btn:hover{
    transform:translateY(-1px) !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
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
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
}

.slider:before{
    box-shadow:0 8px 18px rgba(0,0,0,.25) !important;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1300px){
    .filter-bar{
        grid-template-columns:repeat(2, minmax(180px, 1fr)) !important;
    }
}

@media (max-width:1200px){
    .stats-row{
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

    .btn-soft{
        width:100% !important;
    }

    .stats-row{
        grid-template-columns:1fr !important;
    }

    .filter-bar{
        grid-template-columns:1fr !important;
    }

    .search-wrap,
    .filter-control-wrap,
    .filter-btn{
        width:100% !important;
        min-width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    #stockModal .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:.75rem auto !important;
    }

    #stockModal .row{
        grid-template-columns:1fr !important;
    }

    #stockModal .modal-body,
    #stockModal .stock-modal-body{
        padding:16px !important;
        max-height:calc(100vh - 145px) !important;
    }

    #stockModal .modal-footer,
    #stockModal .stock-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #stockModal .footer-btns{
        width:100% !important;
        flex-direction:column !important;
        margin-left:0 !important;
    }

    #stockModal .track-toggle-wrap,
    #stockModal .cancel-btn,
    #stockModal .save-btn{
        width:100% !important;
    }
}

/* =====================================================
   FINAL INVENTORY POPUP SMALL + TRANSPARENT GLASS FIX
   Paste at the VERY END before
===================================================== */

#stockModal .inventory-small-modal,
#stockModal .modal-dialog{
    max-width:720px !important;
    width:calc(100% - 48px) !important;
    margin:1.2rem auto !important;
}

#stockModal .modal-content,
#stockModal .stock-modal-content{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.28), transparent 38%),
        linear-gradient(135deg, rgba(8,13,28,.74), rgba(17,24,39,.56)) !important;
    backdrop-filter:blur(24px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(145%) !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:18px !important;
    box-shadow:
        0 24px 70px rgba(0,0,0,.58),
        0 0 45px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    color:#f8fafc !important;
    overflow:hidden !important;
}

#stockModal .modal-header,
#stockModal .stock-modal-header{
    min-height:54px !important;
    padding:11px 16px !important;
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

#stockModal .modal-title{
    font-size:17px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
}

#stockModal .modal-body,
#stockModal .stock-modal-body{
    padding:14px 16px !important;
    background:rgba(15,23,42,.22) !important;
    max-height:calc(100vh - 135px) !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

#stockModal .row{
    display:grid !important;
    grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
    gap:11px 14px !important;
    margin:0 !important;
}

#stockModal .row > [class*="col-"]{
    width:100% !important;
    max-width:100% !important;
    padding:0 !important;
}

#stockModal .col-12{
    grid-column:1 / -1 !important;
}

#stockModal .stock-label,
#stockModal label{
    font-size:12px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
    margin:0 0 5px !important;
}

#stockModal .stock-input,
#stockModal input[type="text"],
#stockModal input[type="number"],
#stockModal select{
    height:36px !important;
    min-height:36px !important;
    border-radius:9px !important;
    padding:5px 10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    background:rgba(255,255,255,.88) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}

#stockModal input[readonly]{
    background:rgba(241,245,249,.88) !important;
    color:#475569 !important;
}

#stockModal .stock-textarea,
#stockModal textarea{
    min-height:56px !important;
    height:56px !important;
    border-radius:9px !important;
    padding:8px 10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    background:rgba(255,255,255,.88) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    resize:vertical !important;
}

#stockModal .qty-box{
    height:36px !important;
    min-height:36px !important;
    display:grid !important;
    grid-template-columns:42px 1fr 42px !important;
    border-radius:9px !important;
    background:rgba(255,255,255,.88) !important;
    border:1px solid rgba(203,213,225,.82) !important;
    overflow:hidden !important;
}

#stockModal .qty-btn{
    width:42px !important;
    height:36px !important;
    font-size:18px !important;
    background:rgba(124,58,237,.13) !important;
    color:#111827 !important;
}

#stockModal .qty-input{
    height:36px !important;
    min-height:36px !important;
    padding:0 !important;
    background:transparent !important;
    box-shadow:none !important;
    border:none !important;
    text-align:center !important;
}

#stockModal .qty-mini-bar{
    height:6px !important;
    margin-top:6px !important;
    background:linear-gradient(90deg,#6d4cff,#7c3aed,#22c55e) !important;
}

#stockModal .qty-stats{
    margin-top:4px !important;
    font-size:11px !important;
    color:#c4b5fd !important;
}

#stockModal .modal-footer,
#stockModal .stock-modal-footer{
    padding:10px 16px !important;
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

#stockModal .track-toggle-wrap{
    min-height:36px !important;
    padding:6px 10px !important;
    font-size:12px !important;
    border-radius:11px !important;
    background:rgba(15,23,42,.42) !important;
    border:1px solid rgba(148,163,184,.16) !important;
}

#stockModal .cancel-btn,
#stockModal .save-btn{
    height:36px !important;
    min-width:95px !important;
    border-radius:10px !important;
    font-size:12px !important;
    font-weight:900 !important;
    padding:0 14px !important;
}

#stockModal .save-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

#stockModal .cancel-btn{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
}

#stockModal .btn-close{
    width:32px !important;
    height:32px !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    background-image:none !important;
    position:relative !important;
}

#stockModal .btn-close::before,
#stockModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:15px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#stockModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#stockModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

body.modal-open .modal-backdrop.show{
    opacity:.58 !important;
    background:#000 !important;
}

@media(max-width:768px){
    #stockModal .inventory-small-modal,
    #stockModal .modal-dialog{
        width:calc(100% - 18px) !important;
        max-width:calc(100% - 18px) !important;
    }

    #stockModal .row{
        grid-template-columns:1fr !important;
    }
}



/* =====================================================
   LOW STOCK THRESHOLD DRAWER + TABLE COLUMN
===================================================== */
.btn-threshold{
    background:rgba(109,76,255,.18) !important;
    border:1px solid rgba(139,92,246,.55) !important;
    color:#fff !important;
}

.btn-threshold:hover{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(167,139,250,.72) !important;
}

.threshold-note{
    margin:-4px 0 16px !important;
    padding:13px 16px !important;
    border-radius:14px !important;
    background:rgba(109,76,255,.14) !important;
    border:1px solid rgba(139,92,246,.32) !important;
    color:#c4b5fd !important;
    font-weight:800 !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

.inventory-table{
    min-width:1250px !important;
}

.threshold-drawer{
    width:360px !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.22), transparent 34%),
        linear-gradient(180deg,#0b1024,#111936) !important;
    color:#fff !important;
    border-left:1px solid rgba(148,163,184,.20) !important;
    box-shadow:-24px 0 60px rgba(0,0,0,.45) !important;
    z-index:100000 !important;
}

.offcanvas-backdrop{
    z-index:99999 !important;
}

.threshold-drawer-header{
    padding:22px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

.threshold-drawer-header h5{
    font-size:20px !important;
    font-weight:900 !important;
    margin:0 0 8px !important;
    color:#fff !important;
}

.threshold-drawer-header p{
    margin:0 !important;
    color:#a5b4fc !important;
    font-size:14px !important;
    line-height:1.5 !important;
}

.threshold-drawer-body{
    padding:22px !important;
}

.threshold-label{
    font-size:14px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
    margin-bottom:8px !important;
    display:block !important;
}

.threshold-input{
    width:100% !important;
    height:48px !important;
    border-radius:12px !important;
    border:1px solid rgba(148,163,184,.22) !important;
    background:rgba(15,23,42,.72) !important;
    color:#fff !important;
    padding:0 14px !important;
    font-weight:800 !important;
    outline:none !important;
}

.threshold-input option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.threshold-input:focus{
    border-color:rgba(109,76,255,.75) !important;
    box-shadow:0 0 0 4px rgba(109,76,255,.18) !important;
}

.threshold-drawer-body small{
    display:block !important;
    margin-top:7px !important;
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.threshold-toggle-row{
    margin-top:20px !important;
    padding:14px !important;
    border-radius:14px !important;
    background:rgba(15,23,42,.55) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    justify-content:space-between !important;
    gap:14px !important;
    align-items:center !important;
}

.threshold-toggle-row strong{
    display:block !important;
    font-size:14px !important;
    font-weight:900 !important;
    color:#fff !important;
}

.threshold-toggle-row span{
    display:block !important;
    margin-top:5px !important;
    color:#94a3b8 !important;
    font-size:12px !important;
    line-height:1.45 !important;
}

.threshold-switch{
    position:relative !important;
    width:50px !important;
    height:28px !important;
    flex-shrink:0 !important;
}

.threshold-switch input{
    display:none !important;
}

.threshold-switch span{
    position:absolute !important;
    inset:0 !important;
    background:#334155 !important;
    border-radius:999px !important;
    cursor:pointer !important;
}

.threshold-switch span:before{
    content:"" !important;
    position:absolute !important;
    width:22px !important;
    height:22px !important;
    top:3px !important;
    left:3px !important;
    border-radius:50% !important;
    background:#fff !important;
    transition:.2s !important;
}

.threshold-switch input:checked + span{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
}

.threshold-switch input:checked + span:before{
    transform:translateX(22px) !important;
}

.threshold-info-box{
    margin-top:22px !important;
    padding:14px !important;
    border-radius:14px !important;
    background:rgba(109,76,255,.16) !important;
    border:1px solid rgba(139,92,246,.35) !important;
    color:#c4b5fd !important;
    font-size:13px !important;
    font-weight:800 !important;
    line-height:1.6 !important;
    display:flex !important;
    gap:10px !important;
}

.threshold-drawer-footer{
    padding:18px 22px !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    gap:12px !important;
}

.threshold-cancel,
.threshold-save{
    height:48px !important;
    border:none !important;
    border-radius:12px !important;
    font-weight:900 !important;
}

.threshold-cancel{
    flex:1 !important;
    background:rgba(15,23,42,.75) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
}

.threshold-save{
    flex:1.4 !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 30px rgba(109,76,255,.28) !important;
}

@media(max-width:768px){
    .threshold-drawer{
        width:100% !important;
    }

    .threshold-drawer-footer{
        flex-direction:column !important;
    }
}

/* =====================================================
   LOW STOCK THRESHOLD DRAWER BOTTOM FIX
===================================================== */

#thresholdDrawer.threshold-drawer{
    height:100dvh !important;
    max-height:100dvh !important;
    top:0 !important;
    bottom:auto !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
    padding-bottom:0 !important;
}

#thresholdDrawer .threshold-drawer-header{
    flex:0 0 auto !important;
}

#thresholdDrawer #thresholdForm{
    flex:1 1 auto !important;
    min-height:0 !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
}

#thresholdDrawer .threshold-drawer-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    max-height:none !important;
    padding-bottom:22px !important;
    scrollbar-width:thin;
}

#thresholdDrawer .threshold-drawer-footer{
    flex:0 0 auto !important;
    position:sticky !important;
    bottom:0 !important;
    z-index:20 !important;
    background:linear-gradient(180deg, rgba(15,23,42,.98), rgba(7,11,20,.98)) !important;
    box-shadow:0 -18px 30px rgba(0,0,0,.28) !important;
    padding:14px 22px calc(14px + env(safe-area-inset-bottom)) !important;
}

#thresholdDrawer .threshold-cancel,
#thresholdDrawer .threshold-save{
    height:44px !important;
}

@media (max-height:760px){
    #thresholdDrawer .threshold-drawer-header{
        padding:16px 20px !important;
    }

    #thresholdDrawer .threshold-drawer-body{
        padding:16px 20px 18px !important;
    }

    #thresholdDrawer .threshold-toggle-row{
        margin-top:14px !important;
        padding:12px !important;
    }

    #thresholdDrawer .threshold-info-box{
        margin-top:14px !important;
        padding:12px !important;
    }

    #thresholdDrawer .threshold-input{
        height:44px !important;
    }

    #thresholdDrawer .threshold-drawer-footer{
        padding:12px 20px 12px !important;
    }
}
/* =========================================================
   ONEERP INVENTORY / STOCK LIGHT MODE
   Must remain at the very end of the main style.
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

html[data-pos-theme="light"] body .container-scroll,
html[data-pos-theme="light"] body .main-panel{
    background:#f3f6fb !important;
}

/* Topbar */
html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    border-bottom:1px solid #dce4ef !important;
    box-shadow:0 5px 18px rgba(15,23,42,.06) !important;
}

/* Main page background */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.09),
            transparent 28%
        ),
        radial-gradient(
            circle at 78% 2%,
            rgba(59,130,246,.06),
            transparent 30%
        ),
        radial-gradient(
            circle at 92% 86%,
            rgba(245,158,11,.04),
            transparent 26%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 44%,
            #faf7ff 72%,
            #f8fafc 100%
        ) !important;
}

/* Background grid */
html[data-pos-theme="light"] body .content-wrapper::before{
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

/* Dark purple glow soft */
html[data-pos-theme="light"] body .content-wrapper::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.08),
        transparent 68%
    ) !important;
    opacity:.45 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Threshold Rules */
html[data-pos-theme="light"] body .btn-threshold{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border:1px solid #c4b5fd !important;
    box-shadow:0 8px 18px rgba(124,58,237,.10) !important;
}

html[data-pos-theme="light"] body .btn-threshold:hover{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

/* Adjust Stock */
html[data-pos-theme="light"] body .btn-add{
    background:linear-gradient(135deg,#059669,#16a34a) !important;
    color:#ffffff !important;
    border-color:#16a34a !important;
}

/* Export */
html[data-pos-theme="light"] body .btn-export-top{
    background:linear-gradient(135deg,#3b82f6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#6366f1 !important;
}

/* Reset */
html[data-pos-theme="light"] body .btn-reset-top{
    background:#ffffff !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:0 5px 12px rgba(15,23,42,.05) !important;
}

html[data-pos-theme="light"] body .btn-reset-top:hover{
    background:#f1f5f9 !important;
    color:#0f172a !important;
}

/* =========================================================
   ALERTS
========================================================= */

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

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.24) !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card div,
html[data-pos-theme="light"] body .stat-card strong{
    color:#ffffff !important;
    opacity:1 !important;
}

/* =========================================================
   FILTER BAR
========================================================= */

html[data-pos-theme="light"] body .filter-bar{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.05),
            transparent 34%
        ),
        #ffffff !important;

    border:1px solid #dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Search and select inputs */
html[data-pos-theme="light"] body .search-wrap input,
html[data-pos-theme="light"] body .filter-control-wrap select{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-wrap input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-wrap i,
html[data-pos-theme="light"] body .filter-control-wrap i{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .search-wrap input:focus,
html[data-pos-theme="light"] body .filter-control-wrap select:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body
.filter-control-wrap select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Filter button */
html[data-pos-theme="light"] body .filter-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
    box-shadow:0 10px 22px rgba(124,58,237,.18) !important;
}

/* =========================================================
   THRESHOLD INFORMATION NOTE
========================================================= */

html[data-pos-theme="light"] body .threshold-note{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border:1px solid #c4b5fd !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .threshold-note i{
    color:#7c3aed !important;
}

/* =========================================================
   INVENTORY TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:
        0 16px 36px rgba(15,23,42,.09),
        0 0 0 1px rgba(124,58,237,.07) !important;
}

/* Table variables reset */
html[data-pos-theme="light"] body .inventory-table,
html[data-pos-theme="light"] body .inventory-table tbody,
html[data-pos-theme="light"] body .inventory-table tr,
html[data-pos-theme="light"] body .inventory-table td,
html[data-pos-theme="light"] body .inventory-table th{
    --bs-table-bg:transparent !important;
    --bs-table-accent-bg:transparent !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
}

/* Table header */
html[data-pos-theme="light"] body
.inventory-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom:1px solid #cbd5e1 !important;
    border-right:1px solid #e2e8f0 !important;
    box-shadow:none !important;
}

/* Table normal rows */
html[data-pos-theme="light"] body
.inventory-table tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;
    color:#1e293b !important;
    border-top:1px solid #e8edf4 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

/* Table row hover */
html[data-pos-theme="light"] body
.inventory-table tbody tr:hover td,

html[data-pos-theme="light"] body
.inventory-table tbody tr:hover > *,

html[data-pos-theme="light"] body
.inventory-table tbody td:hover{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;
    color:#0f172a !important;
    box-shadow:none !important;
}

/* Empty table */
html[data-pos-theme="light"] body
.inventory-table td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Muted text */
html[data-pos-theme="light"] body
.inventory-table .text-muted{
    color:#64748b !important;
}

/* Checkbox */
html[data-pos-theme="light"] body
.inventory-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Image */
html[data-pos-theme="light"] body .img-thumb{
    background:#f8fafc !important;
    border-color:#dce4ef !important;
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}

/* =========================================================
   STOCK STATUS PILLS
========================================================= */

html[data-pos-theme="light"] body .status-in{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .status-low{
    background:#fffbeb !important;
    color:#b45309 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .status-out{
    background:#fff1f2 !important;
    color:#be123c !important;
    border:1px solid #fecdd3 !important;
}

/* =========================================================
   TABLE ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .batch-action-btn{
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}

/* Edit */
html[data-pos-theme="light"] body .batch-action-edit{
    background:#fffbeb !important;
    color:#d97706 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] body .batch-action-edit:hover{
    background:#f59e0b !important;
    color:#ffffff !important;
}

/* Delete */
html[data-pos-theme="light"] body .batch-action-delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body .batch-action-delete:hover{
    background:#ef4444 !important;
    color:#ffffff !important;
}

/* =========================================================
   ADJUST STOCK MODAL
========================================================= */

html[data-pos-theme="light"] body
#stockModal .modal-content,

html[data-pos-theme="light"] body
#stockModal .stock-modal-content{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #d8e0eb !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.20),
        0 0 0 1px rgba(124,58,237,.05) !important;
}

/* Modal header */
html[data-pos-theme="light"] body
#stockModal .modal-header,

html[data-pos-theme="light"] body
#stockModal .stock-modal-header{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

/* Modal title */
html[data-pos-theme="light"] body
#stockModal .modal-title{
    color:#0f172a !important;
}

/* Modal body */
html[data-pos-theme="light"] body
#stockModal .modal-body,

html[data-pos-theme="light"] body
#stockModal .stock-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Modal footer */
html[data-pos-theme="light"] body
#stockModal .modal-footer,

html[data-pos-theme="light"] body
#stockModal .stock-modal-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

/* Labels */
html[data-pos-theme="light"] body
#stockModal .stock-label,

html[data-pos-theme="light"] body
#stockModal label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
#stockModal .stock-label span{
    color:#e11d48 !important;
}

/* Inputs */
html[data-pos-theme="light"] body
#stockModal .stock-input,

html[data-pos-theme="light"] body
#stockModal .stock-textarea,

html[data-pos-theme="light"] body
#stockModal input,

html[data-pos-theme="light"] body
#stockModal select,

html[data-pos-theme="light"] body
#stockModal textarea{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#stockModal input[readonly]{
    background:#f1f5f9 !important;
    color:#475569 !important;
}

/* Focus */
html[data-pos-theme="light"] body
#stockModal .stock-input:focus,

html[data-pos-theme="light"] body
#stockModal .stock-textarea:focus,

html[data-pos-theme="light"] body
#stockModal input:focus,

html[data-pos-theme="light"] body
#stockModal select:focus,

html[data-pos-theme="light"] body
#stockModal textarea:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

/* Quantity box */
html[data-pos-theme="light"] body
#stockModal .qty-box{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#stockModal .qty-btn{
    background:#f3efff !important;
    color:#5b21b6 !important;
}

html[data-pos-theme="light"] body
#stockModal .qty-btn:hover{
    background:#7c3aed !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body
#stockModal .qty-input{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#stockModal .qty-stats{
    color:#64748b !important;
}

html[data-pos-theme="light"] body
#stockModal .qty-stats strong{
    color:#5b21b6 !important;
}

/* Tracking row */
html[data-pos-theme="light"] body
#stockModal .track-toggle-wrap{
    background:#f8fafc !important;
    color:#334155 !important;
    border-color:#dce4ef !important;
}

/* Cancel */
html[data-pos-theme="light"] body
#stockModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

/* Save */
html[data-pos-theme="light"] body
#stockModal .save-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
}

/* Close button */
html[data-pos-theme="light"] body
#stockModal .btn-close{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
#stockModal .btn-close::before,

html[data-pos-theme="light"] body
#stockModal .btn-close::after{
    background:#334155 !important;
}

/* =========================================================
   THRESHOLD RULES DRAWER
========================================================= */

html[data-pos-theme="light"] body
#thresholdDrawer.threshold-drawer{
    background:#ffffff !important;
    color:#0f172a !important;
    border-left-color:#dce4ef !important;
    box-shadow:-24px 0 60px rgba(15,23,42,.15) !important;
}

/* Header */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-drawer-header{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body
#thresholdDrawer .threshold-drawer-header h5{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#thresholdDrawer .threshold-drawer-header p{
    color:#64748b !important;
}

/* Drawer body */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-drawer-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Labels */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-label{
    color:#334155 !important;
}

/* Inputs */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-input{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
#thresholdDrawer .threshold-input:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

html[data-pos-theme="light"] body
#thresholdDrawer .threshold-input option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#thresholdDrawer .threshold-drawer-body small{
    color:#64748b !important;
}

/* Toggle rows */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-toggle-row{
    background:#f8fafc !important;
    border-color:#dce4ef !important;
}

html[data-pos-theme="light"] body
#thresholdDrawer .threshold-toggle-row strong{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#thresholdDrawer .threshold-toggle-row > div > span{
    color:#64748b !important;
}

/* Info box */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-info-box{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Footer */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-drawer-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
    box-shadow:0 -12px 25px rgba(15,23,42,.07) !important;
}

/* Cancel button */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-cancel{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

/* Save button */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-save{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
}

/* Drawer close button */
html[data-pos-theme="light"] body
#thresholdDrawer .btn-close{
    filter:none !important;
    opacity:1 !important;
}

/* Switch */
html[data-pos-theme="light"] body
#thresholdDrawer .threshold-switch span{
    background:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
#thresholdDrawer .threshold-switch input:checked + span{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
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

        <?php if ($msg !== ''): ?>
            <div class="alert alert-<?= e($msgType) ?> alert-dismissible fade show" role="alert">
                <?= e($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">Inventory / Stock</h1>
            <div class="header-actions">
                <button type="button" class="btn-soft btn-threshold" data-bs-toggle="offcanvas" data-bs-target="#thresholdDrawer"><i class="bi bi-shield-check"></i> Threshold Rules</button>
                <button type="button" class="btn-soft btn-add" onclick="openStockModal()"><i class="bi bi-plus-lg"></i> Adjust Stock</button>
                <a href="<?= e($_SERVER['PHP_SELF']) ?>?export=1" class="btn-soft btn-export-top"><i class="bi bi-upload"></i> Export</a>
                <a href="<?= e($_SERVER['PHP_SELF']) ?>" class="btn-soft btn-reset-top"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-card card-blue"><div><div>Total Products</div></div><div><strong><?= $totalProducts ?></strong></div></div>
            <div class="stat-card card-green"><div><div>Total Stock Qty</div></div><div><strong><?= number_format($totalStockQty) ?></strong></div></div>
            <div class="stat-card card-yellow"><div><div>Low Stock Items</div></div><div><strong><?= $lowStockItems ?></strong></div></div>
            <div class="stat-card card-red"><div><div>Out of Stock</div></div><div><strong><?= $outOfStockItems ?></strong></div></div>
        </div>

        <form class="filter-bar" method="GET">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search Product / SKU">
            </div>

            <?php if ($hasCategory): ?>
            <div class="filter-control-wrap">
                <i class="bi bi-layers"></i>
                <select name="category">
                    <option value="">Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filter-control-wrap">
                <i class="bi bi-diagram-3"></i>
                <select name="branch_id">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>>
                            <?= e($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-control-wrap">
                <i class="bi bi-stack"></i>
                <select name="stock_status">
                    <option value="">Status</option>
                    <option value="in_stock" <?= $statusFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low_stock" <?= $statusFilter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out_of_stock" <?= $statusFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>

            <button type="submit" class="filter-btn"><i class="bi bi-funnel"></i> Filter</button>
        </form>

        <div class="threshold-note">
            <i class="bi bi-info-circle"></i>
            Low stock is triggered when current quantity is less than or equal to the minimum threshold.
        </div>

        <div class="table-card">
            <div class="table-responsive">
                <table class="inventory-table">
                   <thead>
    <tr>
        <th>
            <input type="checkbox" id="checkAllRows" onclick="toggleAllRows(this)">
        </th>
        <th>ID</th>
        <th>Image</th>
        <th>Name</th>
        <th>SKU</th>
        <th>Price</th>
        <th>Type</th>
        <th>Stock Qty</th>
        <th>Min Threshold</th>
        <th>Branch</th>
        <th>Status</th>
        <th>Actions</th>
    </tr>
</thead>
                    <tbody>
<?php if(empty($items)): ?>
    <tr>
        <td colspan="12" class="text-center">No items found</td>
    </tr>
<?php endif; ?>

<?php foreach($items as $i): ?>
    <?php
        $displayBranch = '-';
        $displayQty = (float)$i['total_stock'];
        $displayThreshold = resolve_threshold_for_item($i, $branchFilter, $thresholdRules, $defaultThreshold);

        if ($branchFilter > 0) {
            $displayBranch = $branchMap[$branchFilter] ?? '-';
            $displayQty = (float)($i['stock'][$branchFilter] ?? 0);
        } else {
            $displayThreshold = resolve_threshold_for_item($i, 0, $thresholdRules, $defaultThreshold);
            if (!empty($i['stock'])) {
                $firstBranchId = array_key_first($i['stock']);
                $displayBranch = $branchMap[$firstBranchId] ?? '-';
            }
        }

        $meta = get_stock_status($displayQty, $displayThreshold);
    ?>
    <tr>
        <!-- ✅ Checkbox column -->
        <td>
            <input 
                type="checkbox" 
                class="rowCheck"
                value="<?= (int)$i['id'] ?>"
                data-name="<?= e($i['name']) ?>"
            >
        </td>

        <td><?= $i['id'] ?></td>

        <td>
            <?php if(!empty($i['image']) && file_exists($_SERVER['DOCUMENT_ROOT'].'/uploads/items/'.$i['image'])): ?>
                <img src="/uploads/items/<?= e($i['image']) ?>" class="img-thumb">
            <?php else: ?>
                <span class="text-muted">No Image</span>
            <?php endif; ?>
        </td>

        <td><?= e($i['name']) ?></td>
        <td><?= e($i['sku']) ?></td>
        <td>Rs <?= number_format((float)$i['price'],2) ?></td>
        <td><?= e($i['type']) ?></td>
        <td><?= rtrim(rtrim(number_format($displayQty, 2), '0'), '.') ?></td>
        <td><?= (int)$displayThreshold ?></td>
        <td><?= e($displayBranch) ?></td>

        <td>
            <span class="status-pill <?= e($meta['class']) ?>">
                <?= e($meta['label']) ?>
            </span>
        </td>

    <td>
    <div class="batch-actions-wrap">

        <!-- EDIT -->
        <button
            type="button"
            class="batch-action-btn batch-action-edit editStock"
            data-id="<?= (int)$i['id'] ?>"
            data-name="<?= e($i['name']) ?>"
            data-stock="<?= (float)$displayQty ?>"
            title="Edit Stock"
        >
            <i class="bi bi-pencil-fill"></i>
        </button>

        <!-- DELETE -->
        <button
            type="button"
            class="batch-action-btn batch-action-delete"
            onclick="deleteItem(<?= (int)$i['id'] ?>)"
            title="Delete Item"
        >
            <i class="bi bi-trash-fill"></i>
        </button>

    </div>
</td>
    
    </tr>
<?php endforeach; ?>
</tbody>
                </table>
            </div>
        </div>

    </div>
</div>
</div>


<div class="offcanvas offcanvas-end threshold-drawer" tabindex="-1" id="thresholdDrawer">
    <div class="offcanvas-header threshold-drawer-header">
        <div>
            <h5 class="offcanvas-title">Low Stock Threshold</h5>
            <p>Define minimum stock levels to identify low stock items and trigger alerts.</p>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>

    <form id="thresholdForm">
        <div class="offcanvas-body threshold-drawer-body">

            <label class="threshold-label">Default Threshold</label>
            <input type="number" name="default_threshold" class="threshold-input" value="<?= (int)$defaultThreshold ?>" min="0">
            <small>Applied when no specific rule exists.</small>

            <label class="threshold-label mt-3">Category Threshold</label>
            <select name="category_name" class="threshold-input">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="threshold-label mt-3">Branch</label>
            <select name="branch_id" class="threshold-input">
                <option value="0">All Branches</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>"><?= e($branch['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="threshold-toggle-row">
                <div>
                    <strong>Apply to All Products</strong>
                    <span>Apply this threshold to selected category/branch products.</span>
                </div>
                <label class="threshold-switch">
                    <input type="checkbox" name="apply_to_all_products" checked>
                    <span></span>
                </label>
            </div>

            <div class="threshold-toggle-row">
                <div>
                    <strong>Notify When Qty ≤ Threshold</strong>
                    <span>Get notified when stock is less than or equal to threshold.</span>
                </div>
                <label class="threshold-switch">
                    <input type="checkbox" name="notify_enabled" checked>
                    <span></span>
                </label>
            </div>

            <div class="threshold-info-box">
                <i class="bi bi-info-circle"></i>
                Low stock is triggered when current quantity is less than or equal to the minimum threshold.
            </div>
        </div>

        <div class="threshold-drawer-footer">
            <button type="button" class="threshold-cancel" data-bs-dismiss="offcanvas">Cancel</button>
            <button type="submit" class="threshold-save">Save Threshold</button>
        </div>
    </form>
</div>

<div class="modal fade" id="stockModal">
<div class="modal-dialog modal-dialog-centered inventory-small-modal">
<div class="modal-content stock-modal-content">
<form id="stockForm">
<input type="hidden" name="item_id" id="stock_item_id">

<div class="modal-header stock-modal-header">
    <h5 class="modal-title" id="modalStockTitle">Adjust Stock</h5>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body stock-modal-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="stock-label">Item</label>
            <input type="text" class="stock-input" id="stock_item_name" readonly>
        </div>

        <div class="col-md-6">
            <label class="stock-label">Branch <span>*</span></label>
            <select name="branch_id" id="branch_id" class="stock-input" required>
                <option value="">Select Branch</option>
                <?php foreach($branches as $branch): ?>
                <option value="<?= $branch['id'] ?>"><?= e($branch['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6" id="transferToWrap" style="display:none;">
            <label class="stock-label">Transfer To Branch <span>*</span></label>
            <select name="transfer_to_branch_id" id="transfer_to_branch_id" class="stock-input">
                <option value="">Select Branch</option>
                <?php foreach($branches as $branch): ?>
                <option value="<?= $branch['id'] ?>"><?= e($branch['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="stock-label">Type</label>
            <select name="adjustment_type" id="adjustment_type" class="stock-input">
                <option value="adjustment_add">Add</option>
                <option value="adjustment_remove">Remove</option>
                <option value="transfer">Transfer</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="stock-label">Quantity</label>
            <div class="qty-box">
                <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
                <input type="number" step="1" name="qty" id="qty" class="stock-input qty-input" value="0" required>
                <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
            </div>
            <div class="qty-mini-bar"></div>
            <div class="qty-stats">
                <span><strong id="currentQtyText">0</strong> Current</span>
                <span><strong id="changeQtyText">0</strong> Change</span>
                <span><strong id="finalQtyText">0</strong> Final</span>
            </div>
        </div>

        <div class="col-md-6">
            <label class="stock-label">Reason</label>
            <input type="text" name="reason" id="reason" class="stock-input" placeholder="Reason">
        </div>

        <div class="col-md-6">
            <label class="stock-label">Reference No.</label>
            <input type="text" name="reference_no" id="reference_no" class="stock-input" placeholder="Reference no">
        </div>

        <div class="col-12">
            <label class="stock-label">Notes</label>
            <textarea name="notes" id="notes" class="stock-textarea" placeholder="Notes"></textarea>
        </div>
    </div>
</div>

<div class="modal-footer stock-modal-footer">
    <div class="track-toggle-wrap">
        <label class="switch">
            <input type="checkbox" checked disabled>
            <span class="slider"></span>
        </label>
        <span>Enable Stock Tracking</span>
    </div>

    <div class="footer-btns">
        <button class="cancel-btn" data-bs-dismiss="modal" type="button">Cancel</button>
        <button class="save-btn" type="submit">Save</button>
    </div>
</div>
</form>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
const stockModal = new bootstrap.Modal(document.getElementById('stockModal'));
let currentQtyBase = 0;

function syncQtyPreview() {
    const qty = parseInt(document.getElementById('qty').value || 0);
    const type = document.getElementById('adjustment_type').value;
    let finalQty = currentQtyBase;

    if (type === 'adjustment_add') finalQty = currentQtyBase + qty;
    if (type === 'adjustment_remove' || type === 'transfer') finalQty = currentQtyBase - qty;
    if (finalQty < 0) finalQty = 0;

    document.getElementById('currentQtyText').innerText = currentQtyBase;
    document.getElementById('changeQtyText').innerText = qty;
    document.getElementById('finalQtyText').innerText = finalQty;
}

function changeQty(delta){
    const el = document.getElementById('qty');
    let v = parseInt(el.value || 0);
    v += delta;
    if(v < 0) v = 0;
    el.value = v;
    syncQtyPreview();
}

function openStockModal(){
    document.getElementById('stockForm').reset();
    document.getElementById('stock_item_id').value = '';
    document.getElementById('stock_item_name').value = '';
    document.getElementById('modalStockTitle').innerText = "Adjust Stock";
    document.getElementById('transferToWrap').style.display = 'none';
    currentQtyBase = 0;
    syncQtyPreview();
    stockModal.show();
}

document.getElementById('adjustment_type').addEventListener('change', function(){
    document.getElementById('transferToWrap').style.display = this.value === 'transfer' ? 'block' : 'none';
    syncQtyPreview();
});

document.getElementById('qty').addEventListener('input', syncQtyPreview);

document.querySelectorAll('.editStock').forEach(icon=>{
    icon.addEventListener('click', ()=>{
        document.getElementById('stock_item_id').value = icon.dataset.id;
        document.getElementById('stock_item_name').value = icon.dataset.name;
        document.getElementById('branch_id').value = icon.dataset.branch || '';
        document.getElementById('modalStockTitle').innerText = "Update Stock for " + icon.dataset.name;
        currentQtyBase = parseFloat(icon.dataset.stock || 0);
        syncQtyPreview();
        stockModal.show();
    });
});

function deleteItem(id){
    if(!confirm("Are you sure you want to delete this item?")) return;

    fetch('inventory.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=delete_item&id=' + encodeURIComponent(id)
    })
    .then(res => res.text())
    .then(res => {
        const response = res.trim();

        if(response === 'ok'){
            location.reload();
        }else{
            alert("Error: " + response);
        }
    })
    .catch(err => {
        alert("AJAX Error: " + err);
    });
}

document.getElementById('stockForm').addEventListener('submit', e=>{
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action','adjust_stock');

    fetch('ajax_inventory.php',{
        method:'POST',
        body: formData
    }).then(r=>r.text()).then(res=>{
        if(res.trim() === 'ok') location.reload();
        else alert("Error: " + res);
    }).catch(err=>{
        alert("AJAX Error: " + err);
    });
});

function toggleAllRows(main){
    document.querySelectorAll('.rowCheck').forEach(el => el.checked = main.checked);
}

function applyBulkAction(){
    const action = document.getElementById('bulkAction').value;
    if(action === 'export'){
        window.location.href = '<?= e($_SERVER["PHP_SELF"]) ?>?export=1';
    }
}

syncQtyPreview();

const thresholdForm = document.getElementById('thresholdForm');
if (thresholdForm) {
    thresholdForm.addEventListener('submit', function(e){
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('action', 'save_threshold');

        fetch('ajax_inventory_threshold.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.text())
        .then(res => {
            if (res.trim() === 'ok') {
                location.reload();
            } else {
                alert('Error: ' + res);
            }
        })
        .catch(err => {
            alert('AJAX Error: ' + err);
        });
    });
}

</script>
<script>
function toggleAllRows(main) {
    document.querySelectorAll('.rowCheck').forEach(el => {
        el.checked = main.checked;
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const rowChecks = document.querySelectorAll('.rowCheck');
    const checkAll = document.getElementById('checkAllRows');

    rowChecks.forEach(cb => {
        cb.addEventListener('change', function () {
            if (!this.checked && checkAll) {
                checkAll.checked = false;
            }

            if (checkAll) {
                const allChecked = document.querySelectorAll('.rowCheck:checked').length === rowChecks.length;
                checkAll.checked = rowChecks.length > 0 && allChecked;
            }
        });
    });
});
</script>
</body>
</html>