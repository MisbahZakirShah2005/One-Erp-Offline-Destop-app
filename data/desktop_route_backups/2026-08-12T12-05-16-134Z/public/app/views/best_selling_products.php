<?php
/* =====================================================
   ONEERP - Best Selling Products
   Location: /public/app/views/best_selling_products.php
===================================================== */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found. Please check config/db.php and make sure it creates mysqli variable named $conn.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role_id = (int)($_SESSION['role_id'] ?? 0);
$is_superadmin = ($role_id === 1);
$session_client_id = (int)($_SESSION['client_id'] ?? 0);
$client_id = $is_superadmin ? (int)($_GET['client_id'] ?? $session_client_id) : $session_client_id;

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($value) { return number_format((float)$value, 2); }
function qty_fmt($value) { $value=(float)$value; return floor($value)==$value ? number_format($value,0) : number_format($value,2); }

function table_exists(mysqli $conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}
function column_exists(mysqli $conn, $table, $column) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}
function stmt_rows(mysqli $conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) die('Prepare failed: ' . $conn->error);
    if ($types !== '' && !empty($params)) {
        $bind = [$types];
        foreach ($params as $k => $v) $bind[] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}
function stmt_one(mysqli $conn, $sql, $types = '', $params = []) {
    $rows = stmt_rows($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}
function has_feature_access(mysqli $conn, $client_id, $user_id, $role_id, $feature_key, $aliases = []) {
    if ((int)$role_id === 1) return true;
    $keys = array_values(array_unique(array_filter(array_merge([$feature_key], $aliases))));
    if ($client_id <= 0 || empty($keys)) return false;
    $escapedKeys = [];
    foreach ($keys as $k) $escapedKeys[] = "'" . $conn->real_escape_string($k) . "'";
    $inKeys = implode(',', $escapedKeys);

    $override = stmt_one($conn, "SELECT enabled FROM client_feature_overrides WHERE client_id=? AND feature_key IN ($inKeys) ORDER BY enabled ASC LIMIT 1", 'i', [$client_id]);
    if ($override) {
        if ((int)$override['enabled'] !== 1) return false;
        $planAllowed = true;
    } else {
        $plan = stmt_one($conn, "
            SELECT pf.id
            FROM subscriptions s
            INNER JOIN plan_features pf ON pf.plan_id=s.plan_id
            WHERE s.client_id=?
              AND s.status='active'
              AND (s.end_date IS NULL OR s.end_date >= CURDATE())
              AND pf.enabled=1
              AND pf.feature_key IN ($inKeys)
            LIMIT 1
        ", 'i', [$client_id]);
        $planAllowed = !empty($plan);
    }
    if (!$planAllowed) return false;

    $permCount = stmt_one($conn, "SELECT COUNT(*) AS total FROM users_permissions WHERE user_id=?", 'i', [$user_id]);
    if ((int)($permCount['total'] ?? 0) > 0) {
        $staffPerm = stmt_one($conn, "SELECT id FROM users_permissions WHERE user_id=? AND feature_key IN ($inKeys) LIMIT 1", 'i', [$user_id]);
        return !empty($staffPerm);
    }
    return true;
}

if ($client_id <= 0) {
    $pageError = 'Invalid client. Super admin ke liye URL mein ?client_id=CLIENT_ID add karein.';
} elseif (!has_feature_access($conn, $client_id, $user_id, $role_id, 'best_selling_products', ['best_selling'])) {
    http_response_code(403);
    $pageError = 'Access denied. Best Selling Products feature is not enabled for this user or plan.';
} else {
    $pageError = '';
}

$date_from = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to = trim($_GET['date_to'] ?? date('Y-m-d'));
$branch_id = (int)($_GET['branch_id'] ?? 0);
$category = trim($_GET['category'] ?? '');
$search = trim($_GET['q'] ?? '');
$limit = (int)($_GET['limit'] ?? 25);
if (!in_array($limit, [10,25,50,100], true)) $limit = 25;
$sort = trim($_GET['sort'] ?? 'sold_qty');
$dir = strtoupper(trim($_GET['dir'] ?? 'DESC'));
$dir = ($dir === 'ASC') ? 'ASC' : 'DESC';
$sortMap = ['sold_qty'=>'sold_qty','total_sales'=>'total_sales','profit'=>'profit','return_qty'=>'return_qty','stock_qty'=>'stock_qty','product'=>'product_name'];
$orderBy = $sortMap[$sort] ?? 'sold_qty';

$clients=[]; $branches=[]; $categories=[]; $rows=[];
$summary=['ranked_products'=>0,'sold_qty'=>0,'total_sales'=>0,'profit'=>0,'top_product'=>'-'];

if ($is_superadmin) {
    $clients = stmt_rows($conn, "SELECT id,business_name FROM clients WHERE deleted_at IS NULL OR deleted_at IS NULL ORDER BY business_name ASC");
}

if ($pageError === '') {
    $branches = stmt_rows($conn, "SELECT id,name FROM branches WHERE client_id=? AND deleted_at IS NULL ORDER BY name ASC", 'i', [$client_id]);
    $categories = stmt_rows($conn, "SELECT DISTINCT category AS name FROM items WHERE client_id=? AND deleted_at IS NULL AND category IS NOT NULL AND category<>'' ORDER BY category ASC", 'i', [$client_id]);

    $qtyExpr = column_exists($conn,'sale_items','base_qty') ? "COALESCE(NULLIF(si.base_qty,0), si.qty, 0)" : "COALESCE(si.qty,0)";
    $netExpr = column_exists($conn,'sale_items','line_total_after_discount') ? "COALESCE(NULLIF(si.line_total_after_discount,0), si.total, (si.qty*si.price), 0)" : "COALESCE(si.total,(si.qty*si.price),0)";
    $grossExpr = column_exists($conn,'sale_items','line_subtotal') ? "COALESCE(NULLIF(si.line_subtotal,0), (si.qty*si.price), si.total, 0)" : "COALESCE((si.qty*si.price), si.total,0)";
    $costExpr = "COALESCE(i.cost,0) * {$qtyExpr}";
    $saleDeleted = column_exists($conn,'sales','deleted_at') ? " AND s.deleted_at IS NULL " : "";
    $saleItemsDeleted = column_exists($conn,'sale_items','deleted_at') ? " AND si.deleted_at IS NULL " : "";
    $itemsDeletedJoin = column_exists($conn,'items','deleted_at') ? " AND i.deleted_at IS NULL " : "";

    $returnJoin=''; $returnTypes=''; $returnParams=[];
    if (table_exists($conn,'returns') && table_exists($conn,'return_items')) {
        $returnDeleted = column_exists($conn,'returns','deleted_at') ? " AND r.deleted_at IS NULL " : "";
        $returnItemsDeleted = column_exists($conn,'return_items','deleted_at') ? " AND ri.deleted_at IS NULL " : "";
        $returnDateExpr = column_exists($conn,'returns','return_date') ? "DATE(COALESCE(r.return_date, r.created_at))" : "DATE(r.created_at)";
        $returnBranchFilter='';
        $returnParams=[$client_id,$date_from,$date_to]; $returnTypes='iss';
        if ($branch_id > 0) { $returnBranchFilter=" AND COALESCE(r.branch_id,0)=? "; $returnParams[]=$branch_id; $returnTypes.='i'; }
        $returnJoin = "
            LEFT JOIN (
                SELECT ri.item_id, SUM(COALESCE(ri.qty,0)) AS return_qty,
                       SUM(COALESCE(ri.refund_amount,(COALESCE(ri.qty,0)*COALESCE(ri.price,0)),0)) AS return_amount
                FROM returns r
                INNER JOIN return_items ri ON ri.return_id=r.id
                WHERE r.client_id=?
                  AND r.status IN ('Refunded','Exchange')
                  AND {$returnDateExpr} BETWEEN ? AND ?
                  {$returnBranchFilter}
                  {$returnDeleted}
                  {$returnItemsDeleted}
                GROUP BY ri.item_id
            ) ret ON ret.item_id = COALESCE(i.id, si.item_id)
        ";
    }

    $where = "WHERE s.client_id=? AND DATE(s.created_at) BETWEEN ? AND ? {$saleDeleted} {$saleItemsDeleted}";
    $params=[$client_id,$date_from,$date_to]; $types='iss';
    if ($branch_id > 0) { $where .= " AND COALESCE(s.branch_id,0)=? "; $params[]=$branch_id; $types.='i'; }
    if ($category !== '') { $where .= " AND COALESCE(i.category,'')=? "; $params[]=$category; $types.='s'; }
    if ($search !== '') { $where .= " AND (COALESCE(i.name,si.item_name,'') LIKE ? OR COALESCE(i.sku,'') LIKE ? OR COALESCE(si.item_name,'') LIKE ?) "; $like='%'.$search.'%'; $params[]=$like; $params[]=$like; $params[]=$like; $types.='sss'; }

    $sql = "
        SELECT
            COALESCE(i.id, si.item_id, 0) AS item_id,
            COALESCE(NULLIF(i.name,''), NULLIF(si.item_name,''), 'Unknown Product') AS product_name,
            COALESCE(i.sku,'') AS sku,
            COALESCE(NULLIF(i.category,''),'Uncategorized') AS category,
            COALESCE(i.price, si.price, 0) AS current_price,
            COALESCE(i.stock_qty,0) AS stock_qty,
            SUM({$qtyExpr}) AS sold_qty,
            COALESCE(MAX(ret.return_qty),0) AS return_qty,
            SUM({$grossExpr}) AS gross_sales,
            SUM({$netExpr}) AS total_sales_before_return,
            COALESCE(MAX(ret.return_amount),0) AS return_amount,
            (SUM({$netExpr}) - COALESCE(MAX(ret.return_amount),0)) AS total_sales,
            SUM({$costExpr}) AS total_cost,
            ((SUM({$netExpr}) - COALESCE(MAX(ret.return_amount),0)) - SUM({$costExpr})) AS profit
        FROM sale_items si
        INNER JOIN sales s ON s.id=si.sale_id
        LEFT JOIN items i ON i.id=si.item_id AND i.client_id=s.client_id {$itemsDeletedJoin}
        {$returnJoin}
        {$where}
        GROUP BY COALESCE(i.id, si.item_id, 0), COALESCE(NULLIF(i.name,''), NULLIF(si.item_name,''), 'Unknown Product'), COALESCE(i.sku,''), COALESCE(NULLIF(i.category,''),'Uncategorized'), COALESCE(i.price, si.price, 0), COALESCE(i.stock_qty,0)
        ORDER BY {$orderBy} {$dir}, product_name ASC
        LIMIT {$limit}
    ";

    $rows = stmt_rows($conn, $sql, $returnTypes.$types, array_merge($returnParams,$params));
    foreach ($rows as $index=>$r) {
        $summary['ranked_products']++;
        $summary['sold_qty'] += (float)$r['sold_qty'];
        $summary['total_sales'] += (float)$r['total_sales'];
        $summary['profit'] += (float)$r['profit'];
        if ($index === 0) $summary['top_product'] = $r['product_name'];
    }

    if (isset($_GET['export']) && $_GET['export']==='csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=best_selling_products_' . date('Ymd_His') . '.csv');
        $out=fopen('php://output','w');
        fputcsv($out, ['Rank','Item ID','Product Name','SKU','Category','Price','Sold Qty','Return Qty','Gross Sales','Return Amount','Total Sales','Total Cost','Profit','Stock Qty']);
        $rank=1;
        foreach($rows as $r){
            fputcsv($out, [$rank++,$r['item_id'],$r['product_name'],$r['sku'],$r['category'],$r['current_price'],$r['sold_qty'],$r['return_qty'],$r['gross_sales'],$r['return_amount'],$r['total_sales'],$r['total_cost'],$r['profit'],$r['stock_qty']]);
        }
        fclose($out); exit;
    }
}
$exportParams=$_GET; $exportParams['export']='csv'; $exportUrl=basename(__FILE__) . '?' . http_build_query($exportParams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Best Selling Products - ONEERP</title>

<script>
(function () {
    let selectedTheme = 'light';

    try {
        const savedTheme =
            localStorage.getItem('oneerp_pos_theme') ||
            localStorage.getItem('oneerp_theme') ||
            localStorage.getItem('theme');

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



<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body.oneerp-report-body{background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.35),transparent 28%),linear-gradient(135deg,#050914 0%,#0b1024 45%,#190f35 100%)!important;color:#f8fafc;overflow-x:hidden}.oneerp-report-page{min-height:100vh;padding:92px 26px 32px;background:radial-gradient(circle at 15% 8%,rgba(124,58,237,.30),transparent 26%),radial-gradient(circle at 92% 0%,rgba(59,130,246,.16),transparent 30%),linear-gradient(135deg,#050914 0%,#0b1024 45%,#190f35 100%)}.report-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}.report-title h1{font-size:30px;font-weight:900;letter-spacing:-.04em;margin:0;color:#fff;text-shadow:0 0 28px rgba(124,58,237,.35)}.report-title p{margin:7px 0 0;color:#c4b5fd;font-weight:700}.report-actions{display:flex;gap:10px;flex-wrap:wrap}.btn-glass{border:1px solid rgba(167,139,250,.34)!important;background:rgba(15,23,42,.62)!important;color:#fff!important;border-radius:13px!important;font-weight:900!important;padding:11px 16px!important;box-shadow:0 12px 30px rgba(0,0,0,.22)}.btn-main{border:none!important;background:linear-gradient(135deg,#6d4cff,#7c3aed)!important;color:#fff!important;border-radius:13px!important;font-weight:900!important;padding:11px 18px!important;box-shadow:0 16px 34px rgba(109,76,255,.28)}.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:18px}.stat-card{border-radius:22px;padding:23px 22px;min-height:122px;position:relative;overflow:hidden;box-shadow:0 22px 55px rgba(0,0,0,.28);border:1px solid rgba(255,255,255,.10)}.stat-card:after{content:"";position:absolute;right:16px;top:16px;width:76px;height:76px;border-radius:20px;background:rgba(255,255,255,.14)}.stat-card .label{font-weight:900;color:rgba(255,255,255,.86);margin-bottom:16px}.stat-card .value{font-size:26px;font-weight:950;color:#fff;position:relative;z-index:2;line-height:1.15}.stat-blue{background:linear-gradient(135deg,#3b82f6,#6d4cff)}.stat-green{background:linear-gradient(135deg,#059669,#16a34a)}.stat-orange{background:linear-gradient(135deg,#f59e0b,#fb923c)}.stat-red{background:linear-gradient(135deg,#be123c,#ef4444)}.filter-card,.table-card{background:linear-gradient(135deg,rgba(15,23,42,.78),rgba(30,27,75,.62));border:1px solid rgba(148,163,184,.18);border-radius:22px;box-shadow:0 22px 56px rgba(0,0,0,.28);backdrop-filter:blur(18px)}.filter-card{padding:15px;margin-bottom:16px}.filter-grid{display:grid;grid-template-columns:1fr 1fr 1.2fr 1.3fr 1fr 1.6fr auto;gap:10px;align-items:center}.form-control,.form-select{min-height:46px!important;background:rgba(15,23,42,.82)!important;color:#e5e7eb!important;border:1px solid rgba(148,163,184,.20)!important;border-radius:13px!important;font-weight:800!important}.form-control::placeholder{color:#94a3b8!important}.form-control:focus,.form-select:focus{box-shadow:0 0 0 3px rgba(124,58,237,.18)!important;border-color:rgba(124,58,237,.65)!important}.form-select option{background:#0f172a;color:#fff}.notice{padding:14px 17px;border-radius:16px;background:rgba(124,58,237,.20);border:1px solid rgba(167,139,250,.25);color:#ddd6fe;font-weight:800;margin-bottom:16px}.table-card{overflow:hidden}.table-responsive{max-height:calc(100vh - 390px);min-height:310px}.report-table{width:100%;margin:0;color:#f8fafc;border-collapse:separate;border-spacing:0}.report-table thead th{position:sticky;top:0;z-index:2;background:#070b20;color:#cbd5e1;text-transform:uppercase;font-size:12px;letter-spacing:.03em;padding:16px;border-bottom:1px solid rgba(148,163,184,.18);white-space:nowrap}.report-table tbody td{padding:15px 16px;border-bottom:1px solid rgba(148,163,184,.10);font-weight:800;color:#e5e7eb;white-space:nowrap}.report-table tbody tr{background:rgba(30,27,75,.34)}.report-table tbody tr:hover{background:rgba(109,76,255,.18)}.rank{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border-radius:14px;background:linear-gradient(135deg,#6d4cff,#7c3aed);font-weight:950;color:#fff;box-shadow:0 12px 26px rgba(109,76,255,.25)}.product-name{font-weight:950;color:#fff}.badge-soft{display:inline-flex;align-items:center;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:900;border:1px solid rgba(167,139,250,.25);background:rgba(124,58,237,.16);color:#ddd6fe}.num{text-align:right}.profit-pos{color:#86efac!important}.profit-neg{color:#fca5a5!important}.empty-state{text-align:center;padding:44px;color:#cbd5e1;font-weight:900}.small-muted{color:#94a3b8;font-size:12px;font-weight:800}@media(max-width:1250px){.stats-grid{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:1fr 1fr}}@media(max-width:768px){.oneerp-report-page{padding:86px 15px 24px}.stats-grid,.filter-grid{grid-template-columns:1fr}.report-title h1{font-size:25px}.report-actions{width:100%}.report-actions .btn{flex:1}}@media print{.sidebar,.navbar,.report-actions,.filter-card,.notice{display:none!important}.container-scroll>.main-panel{margin-left:0!important;width:100%!important}.oneerp-report-page{padding:10px!important;background:#fff!important;color:#111!important}.table-card{box-shadow:none!important;border:1px solid #ddd!important}.report-table thead th{background:#eee!important;color:#111!important}.report-table tbody td{color:#111!important;background:#fff!important}}

/* =========================================================
   BEST SELLING PRODUCTS - FINAL LIGHT MODE
   Topbar/sidebar dark navy-purple hi rahenge.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE BACKGROUND
========================================================= */

html[data-pos-theme="light"] body.oneerp-report-body{
    background:#f3f6fb !important;
    background-image:none !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

/* Sirf main content; sidebar ko touch nahi karega */
html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 52%,
            #faf7ff 100%
        ) !important;

    color:#0f172a !important;
}

html[data-pos-theme="light"] body .oneerp-report-page{
    background:
        radial-gradient(
            circle at 12% 7%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 88% 2%,
            rgba(59,130,246,.05),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 48%,
            #faf7ff 78%,
            #f8fafc 100%
        ) !important;

    color:#0f172a !important;
}

/* =========================================================
   REPORT HEADER
========================================================= */

html[data-pos-theme="light"] body .report-title h1{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body .report-title p{
    color:#64748b !important;
}

/* Print and Reset */
html[data-pos-theme="light"] body .btn-glass{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:
        0 8px 20px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] body .btn-glass:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Export and Filter */
html[data-pos-theme="light"] body .btn-main{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border:none !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

html[data-pos-theme="light"] body .btn-main:hover{
    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #5b21b6
        ) !important;

    color:#ffffff !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;

    border:
        1px solid rgba(255,255,255,.28) !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .label,
html[data-pos-theme="light"] body .stat-card .value{
    color:#ffffff !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .stat-blue{
    background:
        linear-gradient(
            135deg,
            #2563eb,
            #6d4cff
        ) !important;
}

html[data-pos-theme="light"] body .stat-green{
    background:
        linear-gradient(
            135deg,
            #059669,
            #16a34a
        ) !important;
}

html[data-pos-theme="light"] body .stat-orange{
    background:
        linear-gradient(
            135deg,
            #f59e0b,
            #fb923c
        ) !important;
}

html[data-pos-theme="light"] body .stat-red{
    background:
        linear-gradient(
            135deg,
            #be123c,
            #ef4444
        ) !important;
}

/* =========================================================
   FILTER CARD
========================================================= */

html[data-pos-theme="light"] body .filter-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"]
body .filter-card .form-control,

html[data-pos-theme="light"]
body .filter-card .form-select{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"]
body .filter-card .form-control::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"]
body .filter-card .form-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"]
body .filter-card .form-control:hover,

html[data-pos-theme="light"]
body .filter-card .form-select:hover{
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"]
body .filter-card .form-control:focus,

html[data-pos-theme="light"]
body .filter-card .form-select:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body input[type="date"]{
    color-scheme:light !important;
}

/* =========================================================
   NOTICE
========================================================= */

html[data-pos-theme="light"] body .notice{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border:1px solid #ddd6fe !important;

    box-shadow:
        0 8px 22px rgba(124,58,237,.06) !important;
}

html[data-pos-theme="light"] body .notice i{
    color:#7c3aed !important;
}

/* =========================================================
   TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-responsive{
    background:#ffffff !important;
}

/* Bootstrap table dark variables reset */
html[data-pos-theme="light"] body .report-table,
html[data-pos-theme="light"] body .report-table thead,
html[data-pos-theme="light"] body .report-table tbody,
html[data-pos-theme="light"] body .report-table tr,
html[data-pos-theme="light"] body .report-table th,
html[data-pos-theme="light"] body .report-table td{
    --bs-table-bg:transparent !important;
    --bs-table-color:#1e293b !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
    --bs-table-accent-bg:transparent !important;
}

/* Sticky heading */
html[data-pos-theme="light"] body .report-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-bottom:1px solid #cbd5e1 !important;
    border-right:1px solid #e2e8f0 !important;

    box-shadow:none !important;
}

/* Rows */
html[data-pos-theme="light"] body .report-table tbody tr{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;
}

html[data-pos-theme="light"] body .report-table tbody td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-bottom:1px solid #e8edf4 !important;

    box-shadow:none !important;
}

/* Alternate rows */
html[data-pos-theme="light"]
body .report-table tbody tr:nth-child(even) td{
    background:#fbfcff !important;
}

/* Hover */
html[data-pos-theme="light"]
body .report-table tbody tr:hover td{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;

    color:#0f172a !important;
}

/* =========================================================
   RANK, PRODUCT AND CATEGORY
========================================================= */

html[data-pos-theme="light"] body .rank{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 8px 18px rgba(124,58,237,.22) !important;
}

/* First three ranking colors */
html[data-pos-theme="light"]
body .report-table tbody tr:nth-child(1) .rank{
    background:
        linear-gradient(
            135deg,
            #f59e0b,
            #fbbf24
        ) !important;

    color:#ffffff !important;
}

html[data-pos-theme="light"]
body .report-table tbody tr:nth-child(2) .rank{
    background:
        linear-gradient(
            135deg,
            #94a3b8,
            #64748b
        ) !important;
}

html[data-pos-theme="light"]
body .report-table tbody tr:nth-child(3) .rank{
    background:
        linear-gradient(
            135deg,
            #b45309,
            #d97706
        ) !important;
}

html[data-pos-theme="light"] body .product-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .small-muted{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .badge-soft{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border:1px solid #ddd6fe !important;
}

/* Profit */
html[data-pos-theme="light"] body .profit-pos{
    color:#047857 !important;
}

html[data-pos-theme="light"] body .profit-neg{
    color:#e11d48 !important;
}

/* Empty result */
html[data-pos-theme="light"] body .empty-state{
    background:#ffffff !important;
    color:#64748b !important;
}

/* =========================================================
   TABLE SCROLLBAR
========================================================= */

html[data-pos-theme="light"]
body .table-responsive::-webkit-scrollbar{
    width:9px !important;
    height:9px !important;
}

html[data-pos-theme="light"]
body .table-responsive::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"]
body .table-responsive::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
    border-radius:999px !important;

    border:2px solid #f1f5f9 !important;
}

html[data-pos-theme="light"]
body .table-responsive::-webkit-scrollbar-thumb:hover{
    background:#8b5cf6 !important;
}

/* =========================================================
   MOBILE
========================================================= */

@media(max-width:768px){

    html[data-pos-theme="light"] body .oneerp-report-page{
        background:
            linear-gradient(
                135deg,
                #f8fafc,
                #f3f0ff
            ) !important;
    }

    html[data-pos-theme="light"]
    body .report-actions .btn{
        box-shadow:
            0 8px 20px rgba(15,23,42,.08) !important;
    }
}

/* =========================================================
   PRINT MODE
========================================================= */

@media print{

    html[data-pos-theme="light"] body.oneerp-report-body{
        background:#ffffff !important;
        color:#111827 !important;
    }

    html[data-pos-theme="light"] body .sidebar,
    html[data-pos-theme="light"] body .navbar,
    html[data-pos-theme="light"] body .report-actions,
    html[data-pos-theme="light"] body .filter-card,
    html[data-pos-theme="light"] body .notice{
        display:none !important;
    }

    html[data-pos-theme="light"]
    body .container-scroll > .main-panel{
        margin-left:0 !important;
        width:100% !important;
        background:#ffffff !important;
    }

    html[data-pos-theme="light"] body .oneerp-report-page{
        padding:10px !important;
        background:#ffffff !important;
        color:#111827 !important;
    }

    html[data-pos-theme="light"] body .table-card{
        background:#ffffff !important;
        box-shadow:none !important;
        border:1px solid #dddddd !important;
    }

    html[data-pos-theme="light"]
    body .report-table thead th,

    html[data-pos-theme="light"]
    body .report-table tbody td{
        background:#ffffff !important;
        color:#111827 !important;
        border-color:#e5e7eb !important;
    }
}

</style>
</head>
<body class="oneerp-report-body">
<div class="container-scroll">
    <div class="sidebar"><?php if(file_exists(__DIR__.'/partials/sidebar.php')) include(__DIR__.'/partials/sidebar.php'); ?></div>
    <div class="main-panel">
        <?php if(file_exists(__DIR__.'/partials/topbar.php')) include(__DIR__.'/partials/topbar.php'); ?>
        <main class="oneerp-report-page">
            <div class="report-header">
                <div class="report-title"><h1>Best Selling Products</h1><p>Top products by quantity, sales aur profit</p></div>
                <div class="report-actions">
                    <button type="button" class="btn btn-glass" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
                    <a class="btn btn-main" href="<?= e($exportUrl) ?>"><i class="bi bi-download me-1"></i> Export</a>
                    <a class="btn btn-glass" href="best_selling_products.php<?= $is_superadmin && $client_id ? '?client_id='.(int)$client_id : '' ?>"><i class="bi bi-arrow-clockwise me-1"></i> Reset</a>
                </div>
            </div>
            <?php if($pageError !== ''): ?>
                <div class="notice"><?= e($pageError) ?></div>
            <?php else: ?>
                <section class="stats-grid">
                    <div class="stat-card stat-blue"><div class="label">Ranked Products</div><div class="value"><?= number_format($summary['ranked_products']) ?></div></div>
                    <div class="stat-card stat-green"><div class="label">Sold Quantity</div><div class="value"><?= qty_fmt($summary['sold_qty']) ?></div></div>
                    <div class="stat-card stat-orange"><div class="label">Total Sales</div><div class="value">Rs <?= money($summary['total_sales']) ?></div></div>
                    <div class="stat-card stat-red"><div class="label">Top Product</div><div class="value" style="font-size:20px"><?= e($summary['top_product']) ?></div></div>
                </section>
                <form class="filter-card" method="get">
                    <div class="filter-grid">
                        <?php if($is_superadmin): ?>
                            <select name="client_id" class="form-select"><option value="0">Select Client</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id']===$client_id)?'selected':'' ?>><?= e($c['business_name'] ?: ('Client #'.$c['id'])) ?></option><?php endforeach; ?></select>
                        <?php endif; ?>
                        <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
                        <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
                        <select name="branch_id" class="form-select"><option value="0">All Branches</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id']===$branch_id)?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
                        <select name="category" class="form-select"><option value="">All Categories</option><?php foreach($categories as $cat): ?><option value="<?= e($cat['name']) ?>" <?= ($cat['name']===$category)?'selected':'' ?>><?= e($cat['name']) ?></option><?php endforeach; ?></select>
                        <select name="sort" class="form-select">
                            <option value="sold_qty" <?= $sort==='sold_qty'?'selected':'' ?>>Sort: Quantity</option>
                            <option value="total_sales" <?= $sort==='total_sales'?'selected':'' ?>>Sort: Sales</option>
                            <option value="profit" <?= $sort==='profit'?'selected':'' ?>>Sort: Profit</option>
                            <option value="stock_qty" <?= $sort==='stock_qty'?'selected':'' ?>>Sort: Stock</option>
                        </select>
                        <select name="limit" class="form-select">
                            <?php foreach([10,25,50,100] as $l): ?><option value="<?= $l ?>" <?= $limit===$l?'selected':'' ?>>Top <?= $l ?></option><?php endforeach; ?>
                        </select>
                        <input type="text" name="q" class="form-control" placeholder="Search Product / SKU" value="<?= e($search) ?>">
                        <button class="btn btn-main" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
                    </div>
                </form>
                <div class="notice"><i class="bi bi-graph-up-arrow me-1"></i> Ranking selected date range ke andar sold quantity/sales/profit ke hisaab se hoti hai.</div>
                <section class="table-card"><div class="table-responsive"><table class="report-table">
                    <thead><tr><th>Rank</th><th>ID</th><th>Product</th><th>SKU</th><th>Category</th><th class="num">Sold Qty</th><th class="num">Return Qty</th><th class="num">Gross Sales</th><th class="num">Return</th><th class="num">Total Sales</th><th class="num">Cost</th><th class="num">Profit</th><th class="num">Stock</th></tr></thead>
                    <tbody>
                    <?php if(empty($rows)): ?><tr><td colspan="13" class="empty-state">No best selling products found for selected filters.</td></tr><?php else: ?>
                        <?php $rank=1; foreach($rows as $r): $profit=(float)$r['profit']; ?>
                            <tr>
                                <td><span class="rank"><?= $rank++ ?></span></td>
                                <td><?= (int)$r['item_id'] ?></td>
                                <td><div class="product-name"><?= e($r['product_name']) ?></div><div class="small-muted">Price: Rs <?= money($r['current_price']) ?></div></td>
                                <td><?= e($r['sku'] ?: '-') ?></td>
                                <td><span class="badge-soft"><?= e($r['category']) ?></span></td>
                                <td class="num"><?= qty_fmt($r['sold_qty']) ?></td>
                                <td class="num"><?= qty_fmt($r['return_qty']) ?></td>
                                <td class="num">Rs <?= money($r['gross_sales']) ?></td>
                                <td class="num">Rs <?= money($r['return_amount']) ?></td>
                                <td class="num">Rs <?= money($r['total_sales']) ?></td>
                                <td class="num">Rs <?= money($r['total_cost']) ?></td>
                                <td class="num <?= $profit>=0?'profit-pos':'profit-neg' ?>">Rs <?= money($profit) ?></td>
                                <td class="num"><?= qty_fmt($r['stock_qty']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table></div></section>
            <?php endif; ?>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
