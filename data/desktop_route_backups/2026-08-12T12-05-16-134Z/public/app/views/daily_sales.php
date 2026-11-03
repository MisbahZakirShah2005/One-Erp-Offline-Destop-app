<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$logged_role_id   = $_SESSION['role_id'] ?? 0;
$logged_client_id = $_SESSION['client_id'] ?? 0;


/* ================= ACTIONS: EDIT / DELETE DAILY SALE GROUP ================= */

$action_msg = '';
$action_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['daily_action'])) {

    $daily_action = $_POST['daily_action'];
    $action_date  = $_POST['action_date'] ?? '';
    $action_branch_id = $_POST['action_branch_id'] ?? '';

    if ($action_date === '') {
        $action_err = "Invalid date selected.";
    } else {

        $actionWhere  = " WHERE DATE(created_at) = ? ";
        $actionParams = [$action_date];
        $actionTypes  = "s";

        if ($action_branch_id !== '') {
            $actionWhere .= " AND branch_id = ? ";
            $actionParams[] = $action_branch_id;
            $actionTypes .= "i";
        } else {
            $actionWhere .= " AND (branch_id IS NULL OR branch_id = 0) ";
        }

        if ($logged_role_id != 1) {
            $actionWhere .= " AND client_id = ? ";
            $actionParams[] = $logged_client_id;
            $actionTypes .= "i";
        }

        /* DELETE DAILY SALES */
        if ($daily_action === 'delete_day') {
            try {
                $conn->begin_transaction();

                $saleIds = [];
                $saleSql = "SELECT id FROM sales " . $actionWhere;
                $saleStmt = $conn->prepare($saleSql);
                $saleStmt->bind_param($actionTypes, ...$actionParams);
                $saleStmt->execute();
                $saleResult = $saleStmt->get_result();

                while ($sale = $saleResult->fetch_assoc()) {
                    $saleIds[] = (int)$sale['id'];
                }

                $saleStmt->close();

                if (!empty($saleIds)) {
                    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
                    $idTypes = str_repeat('i', count($saleIds));

                    $delItems = $conn->prepare("DELETE FROM sale_items WHERE sale_id IN ($placeholders)");
                    $delItems->bind_param($idTypes, ...$saleIds);
                    $delItems->execute();
                    $delItems->close();

                    $delSales = $conn->prepare("DELETE FROM sales WHERE id IN ($placeholders)");
                    $delSales->bind_param($idTypes, ...$saleIds);
                    $delSales->execute();
                    $delSales->close();

                    $conn->commit();
                    $action_msg = "Daily sales deleted successfully.";
                } else {
                    $conn->rollback();
                    $action_err = "No sales found for selected day.";
                }

            } catch (Throwable $e) {
                $conn->rollback();
                $action_err = "Delete failed: " . $e->getMessage();
            }
        }

        /* EDIT DAILY SALES DATE / BRANCH */
        if ($daily_action === 'edit_day') {
            $new_date      = $_POST['new_date'] ?? '';
            $new_branch_id = $_POST['new_branch_id'] ?? '';

            if ($new_date === '') {
                $action_err = "New date is required.";
            } else {
                try {
                    if ($new_branch_id !== '') {
                        $editSql = "
                            UPDATE sales 
                            SET 
                                created_at = CONCAT(?, ' ', TIME(created_at)),
                                branch_id = ?
                            $actionWhere
                        ";

                        $editTypes  = "si" . $actionTypes;
                        $editParams = [$new_date, $new_branch_id, ...$actionParams];
                    } else {
                        $editSql = "
                            UPDATE sales 
                            SET 
                                created_at = CONCAT(?, ' ', TIME(created_at))
                            $actionWhere
                        ";

                        $editTypes  = "s" . $actionTypes;
                        $editParams = [$new_date, ...$actionParams];
                    }

                    $editStmt = $conn->prepare($editSql);
                    $editStmt->bind_param($editTypes, ...$editParams);
                    $editStmt->execute();

                    if ($editStmt->affected_rows >= 0) {
                        $action_msg = "Daily sales updated successfully.";
                    }

                    $editStmt->close();

                } catch (Throwable $e) {
                    $action_err = "Update failed: " . $e->getMessage();
                }
            }
        }
    }
}

/* ================= FILTERS ================= */
$filter_client = $_POST['client_id'] ?? '';
$filter_branch = $_POST['branch_id'] ?? '';
$filter_from   = $_POST['from_date'] ?? '';
$filter_to     = $_POST['to_date'] ?? '';
$search        = trim($_POST['search'] ?? '');

$where = "WHERE 1=1 ";
$params = [];
$types  = '';

if ($logged_role_id != 1) {
    $where .= " AND s.client_id = ?";
    $params[] = $logged_client_id;
    $types .= 's';
} elseif ($filter_client !== '') {
    $where .= " AND s.client_id = ?";
    $params[] = $filter_client;
    $types .= 's';
}

if ($filter_branch !== '') {
    $where .= " AND s.branch_id = ?";
    $params[] = $filter_branch;
    $types .= 's';
}

if ($filter_from !== '') {
    $where .= " AND DATE(s.created_at) >= ?";
    $params[] = $filter_from;
    $types .= 's';
}

if ($filter_to !== '') {
    $where .= " AND DATE(s.created_at) <= ?";
    $params[] = $filter_to;
    $types .= 's';
}

if ($search !== '') {
    $where .= " AND (b.name LIKE ? OR DATE(s.created_at) LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $types .= 'ss';
}



/* ================= SUMMARY ================= */
$sql = "SELECT 
    COUNT(DISTINCT s.id) as invoices,
    COALESCE(SUM(s.total) + SUM(s.tax) - SUM(s.discount), 0) as total_sales,
    COALESCE(SUM(si.qty), 0) as products
FROM sales s
LEFT JOIN sale_items si ON si.sale_id = s.id
LEFT JOIN branches b ON b.id = s.branch_id
$where";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$sum = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_sales = (float)($sum['total_sales'] ?? 0);
$invoices    = (int)($sum['invoices'] ?? 0);
$products    = (float)($sum['products'] ?? 0);
$avg         = $invoices ? ($total_sales / $invoices) : 0;

/* ================= DAILY ================= */


$sql = "SELECT 
    DATE(s.created_at) as sale_date,
    s.branch_id,
    b.name as branch_name,
    COUNT(DISTINCT s.id) as invoices,
    COALESCE(SUM(s.total) + SUM(s.tax) - SUM(s.discount), 0) as total_amount
FROM sales s
LEFT JOIN branches b ON b.id = s.branch_id
$where
GROUP BY DATE(s.created_at), s.branch_id, b.name
ORDER BY sale_date DESC";


$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$daily = $stmt->get_result();

$dailyRows = [];
while ($row = $daily->fetch_assoc()) {
    $dailyRows[] = $row;
}
$stmt->close();

/* ================= BRANCHES FOR EDIT MODAL ================= */
$branchesList = [];

$branchWhere = "";
$branchParams = [];
$branchTypes = "";

if ($logged_role_id != 1) {
    $branchWhere = " WHERE client_id = ? ";
    $branchParams[] = $logged_client_id;
    $branchTypes = "i";
}

$branchSql = "SELECT id, name FROM branches $branchWhere ORDER BY name ASC";
$branchStmt = $conn->prepare($branchSql);

if (!empty($branchParams)) {
    $branchStmt->bind_param($branchTypes, ...$branchParams);
}

$branchStmt->execute();
$branchResult = $branchStmt->get_result();

while ($br = $branchResult->fetch_assoc()) {
    $branchesList[] = $br;
}

$branchStmt->close();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily Sales</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php include("partials/head.php"); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-orange:#f97316;
    --oms-yellow:#f59e0b;
    --oms-cyan:#06b6d4;
    --oms-red:#ef4444;
}

/* ================= BASE ================= */
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

/* ================= LAYOUT SAME ORDERS/DASHBOARD ================= */
body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
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
    min-height:70px;
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

/* ================= PAGE HEADER ================= */
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.page-title{
    font-size:28px;
    font-weight:900;
    color:#f8fafc;
    margin:0;
    letter-spacing:-.03em;
}

.header-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.btn-soft{
    border:none;
    border-radius:13px;
    padding:11px 16px;
    color:#fff;
    font-weight:800;
    font-size:14px;
    display:inline-flex;
    align-items:center;
    gap:8px;
    text-decoration:none;
    box-shadow:0 14px 28px rgba(0,0,0,.20);
    transition:.22s ease;
}

.btn-soft:hover{
    transform:translateY(-1px);
    color:#fff;
}

.btn-export{ background:linear-gradient(135deg,#6d4cff,#7c3aed); }
.btn-reset{ background:rgba(148,163,184,.20); }

/* ================= ALERTS ================= */
.alert-soft{
    border-radius:14px;
    padding:12px 14px;
    margin-bottom:14px;
    font-weight:800;
    font-size:14px;
    backdrop-filter:blur(14px);
}

.alert-success-soft{
    background:rgba(34,197,94,.14);
    color:#4ade80;
    border:1px solid rgba(34,197,94,.26);
}

.alert-danger-soft{
    background:rgba(239,68,68,.14);
    color:#f87171;
    border:1px solid rgba(239,68,68,.26);
}

/* ================= STAT CARDS ================= */
.stats-row{
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:16px;
    margin-bottom:18px;
}

.stat-card{
    min-height:138px;
    border-radius:18px;
    padding:20px 22px;
    color:#fff;
    position:relative;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.10);
    box-shadow:0 18px 40px rgba(0,0,0,.28);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    isolation:isolate;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease;
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
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22);
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18);
}

.stat-left .title{
    font-size:15px;
    font-weight:800;
    margin-bottom:7px;
    color:rgba(255,255,255,.96);
}

.stat-left .sub{
    font-size:13px;
    opacity:.88;
    font-weight:600;
}

.stat-card .count{
    font-size:30px;
    font-weight:900;
    line-height:1.1;
    text-align:right;
    letter-spacing:-.02em;
    color:#fff;
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff); }
.card-green{ background:linear-gradient(135deg,#047857,#16a34a); }
.card-cyan{ background:linear-gradient(135deg,#0891b2,#06b6d4); }
.card-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c); }

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
    display:grid;
    grid-template-columns: 1fr 180px 180px auto auto auto;
    gap:10px;
    align-items:center;
    margin-bottom:18px;
}

.search-wrap{
    width:100%;
    min-width:0;
    position:relative;
}

.search-wrap i{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#94a3b8;
    font-size:14px;
}

.search-wrap input,
.filter-control{
    width:100%;
    min-width:0;
    height:46px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:13px;
    padding:0 14px;
    outline:none;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-weight:700;
    box-shadow:none;
    transition:.2s;
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

.filter-btn{
    height:46px;
    border:none;
    border-radius:13px;
    padding:0 17px;
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    color:#fff;
    font-weight:900;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    white-space:nowrap;
    box-shadow:0 14px 28px rgba(109,76,255,.24);
    transition:.22s ease;
}

.filter-btn:hover{
    transform:translateY(-1px);
    background:linear-gradient(135deg,#7c3aed,#6d4cff);
    color:#fff;
}

.filter-btn.export{
    background:linear-gradient(135deg,#047857,#22c55e);
    box-shadow:0 14px 28px rgba(34,197,94,.18);
}

.filter-btn.export:hover{
    background:linear-gradient(135deg,#16a34a,#22c55e);
}

.filter-btn.reset{
    background:rgba(148,163,184,.18);
    color:#e5e7eb;
    border:1px solid rgba(148,163,184,.18);
    box-shadow:0 14px 28px rgba(0,0,0,.18);
}

.filter-btn.reset:hover{
    background:rgba(148,163,184,.28);
    color:#fff;
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

.sales-table{
    width:100%;
    min-width:760px;
    border-collapse:collapse;
    background:transparent;
}

.sales-table thead th{
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

.sales-table tbody td{
    padding:15px 14px;
    border-top:1px solid rgba(148,163,184,.10);
    vertical-align:middle;
    font-size:14px;
    color:#e5e7eb;
    font-weight:600;
    background:rgba(15,23,42,.20);
}

.sales-table tbody tr:hover td{
    background:rgba(124,58,237,.12);
    color:#fff;
}

.sales-table input[type="checkbox"]{
    width:16px;
    height:16px;
    accent-color:#7c3aed;
}

.amount-strong{
    font-weight:900;
    color:#f8fafc;
}

/* ================= ACTION BUTTONS ================= */
.action-wrap{
    display:flex;
    align-items:center;
    gap:10px;
}

.action-btn{
    width:40px;
    height:40px;
    border:none;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:.2s ease;
    font-size:15px;
    box-shadow:0 12px 24px rgba(0,0,0,.18);
}

.action-btn.edit{
    background:rgba(245,158,11,.15);
    color:#fbbf24;
    border:1px solid rgba(245,158,11,.24);
}

.action-btn.edit:hover{
    background:#f59e0b;
    color:#07111f;
    transform:translateY(-2px);
}

.action-btn.delete{
    background:rgba(239,68,68,.14);
    color:#f87171;
    border:1px solid rgba(239,68,68,.24);
}

.action-btn.delete:hover{
    background:#ef4444;
    color:#fff;
    transform:translateY(-2px);
}

/* ================= TABLE FOOTER ================= */
.table-footer{
    padding:16px;
    border-top:1px solid rgba(148,163,184,.13);
    background:rgba(7,11,20,.24);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.footer-note{
    color:#94a3b8;
    font-size:14px;
    font-weight:700;
}

.footer-note strong{
    color:#f8fafc;
}

.empty-state{
    text-align:center;
    padding:30px 16px !important;
    color:#94a3b8 !important;
    font-size:14px;
    font-weight:700;
    background:rgba(15,23,42,.34) !important;
}

/* ================= MODAL DARK THEME ================= */
.modal-content{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.94));
    border:1px solid rgba(148,163,184,.22);
    border-radius:18px;
    color:#f8fafc;
    box-shadow:0 24px 70px rgba(0,0,0,.55);
}

.modal-header,
.modal-footer{
    border-color:rgba(148,163,184,.14);
}

.modal-title{
    font-weight:900;
}

.btn-close{
    filter:invert(1);
    opacity:.75;
}

.form-label{
    color:#cbd5e1;
    font-weight:800;
}

.form-control{
    height:46px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:13px;
    padding:0 14px;
    outline:none;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-weight:700;
    box-shadow:none;
}

.form-control:focus{
    background:#111827;
    color:#fff;
    border-color:rgba(109,76,255,.55);
    box-shadow:0 0 0 3px rgba(109,76,255,.14);
}

.form-control option{
    background:#0f172a;
    color:#e5e7eb;
}

.btn-primary{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.45) !important;
    border-radius:12px;
    font-weight:900;
    box-shadow:0 14px 28px rgba(109,76,255,.24);
}

.btn-secondary{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px;
    font-weight:900;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1300px){
    .filter-bar{
        grid-template-columns:1fr 160px 160px auto auto auto;
        gap:8px;
    }

    .filter-btn{
        padding:0 13px;
    }
}

@media (max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2, 1fr);
    }

    .filter-bar{
        grid-template-columns:1fr 1fr 1fr;
    }

    .filter-btn{
        width:100%;
    }
}

@media (max-width:991px){
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
        margin-left:0;
    }

    .topbar{
        position:relative;
    }

    .content-wrapper{
        padding:18px;
    }
}

@media (max-width:768px){
    .stats-row{
        grid-template-columns:1fr;
    }

    .filter-bar{
        grid-template-columns:1fr;
    }

    .page-title{
        font-size:24px;
    }

    .stat-card{
        min-height:118px;
    }

    .stat-card .count{
        font-size:25px;
    }
}
/* =====================================================
   FINAL FIX: DAILY SALES STAT PRICE OVERFLOW / HIDE FIX
   Paste at END of <style>
===================================================== */

/* Stat card ke andar content cut na ho */
.stat-card{
    overflow:visible !important;
    min-width:0 !important;
}

/* Left text ko fixed chhota area do */
.stat-left{
    flex:0 0 38% !important;
    min-width:0 !important;
    position:relative;
    z-index:2;
}

/* Price/right value ko full space do */
.stat-card .count{
    flex:1 !important;
    min-width:0 !important;
    max-width:100% !important;
    text-align:right !important;
    font-size:clamp(20px, 2vw, 30px) !important;
    line-height:1.12 !important;
    word-break:break-word !important;
    overflow-wrap:anywhere !important;
    white-space:normal !important;
    position:relative;
    z-index:2;
}

/* Total Sales card ke liye special fix */
.stats-row .stat-card:first-child .count{
    font-size:clamp(18px, 1.7vw, 28px) !important;
    letter-spacing:-.04em !important;
}

/* Background glass square text ke upar na aaye */
.stat-card::after{
    z-index:0 !important;
    opacity:.45 !important;
}

.stat-card::before{
    z-index:0 !important;
}

.stat-left,
.stat-card .count{
    position:relative !important;
    z-index:3 !important;
}

/* Medium screens par price aur chhoti */
@media (max-width:1300px){
    .stat-card{
        padding:18px 16px !important;
        gap:10px !important;
    }

    .stat-left{
        flex:0 0 34% !important;
    }

    .stat-card .count{
        font-size:clamp(18px, 1.55vw, 26px) !important;
    }
}

/* Small screens par card vertical layout */
@media (max-width:768px){
    .stat-card{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .stat-left{
        flex:none !important;
        width:100% !important;
    }

    .stat-card .count{
        width:100% !important;
        text-align:left !important;
        font-size:26px !important;
    }
}
/* =====================================================
   DAILY SALES MOBILE FIX
   2 CARDS PER ROW + FILTER ALIGNMENT
   Paste at END before 
===================================================== */

@media (max-width: 768px){

    body{
        overflow-x:hidden !important;
    }

    .content-wrapper{
        padding:16px 12px 24px 12px !important;
    }

    .page-title,
    .main-title,
    .dashboard-title{
        font-size:25px !important;
        line-height:1.18 !important;
        margin-bottom:15px !important;
    }

    /* Daily sales cards: 2 per row */
    .stats-row,
    .summary-row,
    .summary-cards,
    .sales-summary,
    .cards-row{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .stat-card,
    .summary-card,
    .sales-card,
    .dashboard-card,
    .report-card{
        min-height:88px !important;
        border-radius:16px !important;
        padding:12px 10px !important;
        gap:8px !important;
        font-size:12px !important;
        line-height:1.15 !important;
        display:flex !important;
        flex-direction:column !important;
        justify-content:center !important;
        align-items:flex-start !important;
        overflow:hidden !important;
    }

    .stat-card span,
    .summary-card span,
    .sales-card span,
    .dashboard-card span,
    .report-card span{
        font-size:11px !important;
        line-height:1.2 !important;
        font-weight:800 !important;
        margin-bottom:4px !important;
    }

    .stat-card h3,
    .stat-card h4,
    .stat-card strong,
    .summary-card h3,
    .summary-card h4,
    .summary-card strong,
    .sales-card h3,
    .sales-card h4,
    .sales-card strong,
    .dashboard-card h3,
    .dashboard-card h4,
    .dashboard-card strong,
    .report-card h3,
    .report-card h4,
    .report-card strong{
        font-size:20px !important;
        line-height:1.1 !important;
        font-weight:900 !important;
        margin:0 !important;
        word-break:break-word !important;
    }

    .stat-card i,
    .summary-card i,
    .sales-card i,
    .dashboard-card i,
    .report-card i{
        font-size:16px !important;
        margin-right:5px !important;
    }

    .stat-card::after,
    .summary-card::after,
    .sales-card::after,
    .dashboard-card::after,
    .report-card::after{
        width:50px !important;
        height:50px !important;
        right:8px !important;
        top:10px !important;
        border-radius:16px !important;
    }

    /* Filter box alignment */
    .filter-bar,
    .filter-box,
    .filter-card,
    .filters-wrapper,
    .filters{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        padding:12px !important;
        border-radius:18px !important;
        margin-bottom:14px !important;
        align-items:center !important;
    }

    .search-wrap,
    .search-box,
    .filter-search{
        grid-column:1 / -1 !important;
        width:100% !important;
        min-width:0 !important;
    }

    .search-wrap input,
    .search-box input,
    .filter-search input,
    .filter-control,
    .filter-bar input,
    .filter-bar select,
    .filter-box input,
    .filter-box select,
    .filters input,
    .filters select{
        width:100% !important;
        min-width:0 !important;
        height:44px !important;
        font-size:12px !important;
        border-radius:13px !important;
        padding-left:12px !important;
        padding-right:10px !important;
    }

    .search-wrap input,
    .search-box input,
    .filter-search input{
        padding-left:40px !important;
    }

    .search-wrap i,
    .search-box i,
    .filter-search i{
        left:14px !important;
        font-size:14px !important;
    }

    .filter-btn,
    .export-btn,
    .btn-filter,
    .btn-export,
    .filter-bar button,
    .filter-box button,
    .filters button{
        width:100% !important;
        height:46px !important;
        border-radius:14px !important;
        font-size:13px !important;
        font-weight:900 !important;
        padding:0 12px !important;
    }

    /* Filter aur Export buttons full width */
    .filter-btn,
    .export-btn,
    .btn-filter,
    .btn-export{
        grid-column:1 / -1 !important;
    }

    .table-card,
    .daily-table-card,
    .report-table-card{
        border-radius:18px !important;
        margin-top:0 !important;
    }
}

/* 328px / 360px screens */
@media (max-width: 380px){

    .content-wrapper{
        padding-left:10px !important;
        padding-right:10px !important;
    }

    .stats-row,
    .summary-row,
    .summary-cards,
    .sales-summary,
    .cards-row{
        gap:9px !important;
    }

    .stat-card,
    .summary-card,
    .sales-card,
    .dashboard-card,
    .report-card{
        min-height:82px !important;
        padding:10px 9px !important;
    }

    .stat-card h3,
    .stat-card h4,
    .stat-card strong,
    .summary-card h3,
    .summary-card h4,
    .summary-card strong,
    .sales-card h3,
    .sales-card h4,
    .sales-card strong,
    .dashboard-card h3,
    .dashboard-card h4,
    .dashboard-card strong,
    .report-card h3,
    .report-card h4,
    .report-card strong{
        font-size:18px !important;
    }

    .filter-bar,
    .filter-box,
    .filter-card,
    .filters-wrapper,
    .filters{
        gap:9px !important;
        padding:11px !important;
    }

    .filter-control,
    .filter-bar input,
    .filter-bar select,
    .filter-box input,
    .filter-box select,
    .filters input,
    .filters select{
        height:42px !important;
        font-size:11px !important;
    }
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

            <div class="page-header">
                <h1 class="page-title">Daily Sales Reports</h1>
                
                <?php if ($action_msg): ?>
    <div class="alert-soft alert-success-soft">
        <i class="fa-solid fa-circle-check me-1"></i> <?= e($action_msg) ?>
    </div>
<?php endif; ?>

<?php if ($action_err): ?>
    <div class="alert-soft alert-danger-soft">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= e($action_err) ?>
    </div>
<?php endif; ?>

                <!--<div class="header-actions">-->
                <!--    <button type="button" onclick="exportDailySales()" class="btn-soft btn-export">-->
                <!--        <i class="fa-solid fa-file-arrow-down"></i> Export-->
                <!--    </button>-->

                <!--    <a href="daily_sales.php" class="btn-soft btn-reset">-->
                <!--        <i class="fa-solid fa-rotate-right"></i> Reset-->
                <!--    </a>-->
                <!--</div>-->
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div class="stat-left">
                        <div class="title"><i class="fa-solid fa-sack-dollar me-2"></i>Total Sales</div>
                        <div class="sub">Filtered amount</div>
                    </div>
                    <div class="count">Rs<?= number_format($total_sales, 2) ?></div>
                </div>

                <div class="stat-card card-green">
                    <div class="stat-left">
                        <div class="title"><i class="fa-solid fa-file-invoice me-2"></i>Invoices</div>
                        <div class="sub">Total bills</div>
                    </div>
                    <div class="count"><?= (int)$invoices ?></div>
                </div>

                <div class="stat-card card-cyan">
                    <div class="stat-left">
                        <div class="title"><i class="fa-solid fa-chart-line me-2"></i>Avg Sale</div>
                        <div class="sub">Per invoice</div>
                    </div>
                    <div class="count">Rs<?= number_format($avg, 2) ?></div>
                </div>

                <div class="stat-card card-yellow">
                    <div class="stat-left">
                        <div class="title"><i class="fa-solid fa-boxes-stacked me-2"></i>Products</div>
                        <div class="sub">Sold quantity</div>
                    </div>
                    <div class="count"><?= number_format($products, 0) ?></div>
                </div>
            </div>

            <form method="POST" class="filter-bar" id="dailySalesForm">

                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input
                        type="text"
                        name="search"
                        id="searchInput"
                        placeholder="Search by branch or date..."
                        value="<?= e($search) ?>"
                    >
                </div>

                <input
                    type="date"
                    name="from_date"
                    id="fromDate"
                    value="<?= e($filter_from) ?>"
                    class="filter-control"
                >

                <input
                    type="date"
                    name="to_date"
                    id="toDate"
                    value="<?= e($filter_to) ?>"
                    class="filter-control"
                >

                <button type="submit" class="filter-btn">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>

                <button type="button" onclick="exportDailySales()" class="filter-btn export">
                    <i class="fa-solid fa-file-arrow-down"></i> Export
                </button>

                <a href="daily_sales.php" class="filter-btn reset" title="Reset">
                    <i class="fa-solid fa-rotate-right"></i> Reset
                </a>
            </form>

            <div class="table-card">
                <div class="table-responsive">
                    <table class="sales-table">
                     <thead>
    <tr>
        <th><input type="checkbox" id="checkAllRows"></th>
        <th>Date</th>
        <th>Branch</th>
        <th>Invoices</th>
        <th>Total</th>
        <th>Action</th>
    </tr>
</thead>

<tbody>
    <?php if (empty($dailyRows)): ?>
        <tr>
            <td colspan="6" class="empty-state">
                No daily sales found for selected filters.
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($dailyRows as $row): ?>
            <?php
                $rowKey = md5(($row['sale_date'] ?? '') . '|' . ($row['branch_name'] ?? '') . '|' . ($row['invoices'] ?? 0));
            ?>

            <tr>
                <td>
                    <input
                        type="checkbox"
                        class="row-check"
                        value="<?= e($rowKey) ?>"
                        data-date="<?= e($row['sale_date'] ?? '') ?>"
                        data-branch="<?= e($row['branch_name'] ?? '') ?>"
                        data-invoices="<?= (int)($row['invoices'] ?? 0) ?>"
                        data-total="<?= (float)($row['total_amount'] ?? 0) ?>"
                    >
                </td>

                <td><?= e($row['sale_date']) ?></td>
                <td><?= e($row['branch_name'] ?? 'N/A') ?></td>
                <td><?= (int)($row['invoices'] ?? 0) ?></td>

                <td>
                    <span class="amount-strong">
                        Rs<?= number_format((float)($row['total_amount'] ?? 0), 2) ?>
                    </span>
                </td>

                <td>
                    <div class="action-wrap">

                        <button
                            type="button"
                            class="action-btn edit"
                            title="Edit"
                            onclick="openEditDailyModal(
                                '<?= e($row['sale_date'] ?? '') ?>',
                                '<?= e($row['branch_id'] ?? '') ?>'
                            )"
                        >
                            <i class="fa-solid fa-pen"></i>
                        </button>

                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this daily sale record? This will delete all invoices of this date and branch.');">
                            <input type="hidden" name="daily_action" value="delete_day">
                            <input type="hidden" name="action_date" value="<?= e($row['sale_date'] ?? '') ?>">
                            <input type="hidden" name="action_branch_id" value="<?= e($row['branch_id'] ?? '') ?>">

                            <button type="submit" class="action-btn delete" title="Delete">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </form>

                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</tbody>                   </table>
                </div>

                <div class="table-footer">
                    <div class="footer-note">
                        Total rows: <strong><?= count($dailyRows) ?></strong>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>




<div class="modal fade" id="editDailyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-pen me-2"></i>Edit Daily Sale
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="daily_action" value="edit_day">
                <input type="hidden" name="action_date" id="editOldDate">
                <input type="hidden" name="action_branch_id" id="editOldBranchId">

                <div class="mb-3">
                    <label class="form-label">New Date</label>
                    <input type="date" name="new_date" id="editNewDate" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Branch</label>
                    <select name="new_branch_id" id="editNewBranchId" class="form-control">
                        <option value="">N/A</option>
                        <?php foreach ($branchesList as $branch): ?>
                            <option value="<?= (int)$branch['id'] ?>">
                                <?= e($branch['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

           
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function exportDailySales() {
    const form = document.getElementById('dailySalesForm');
    if (!form) return;

    const originalAction = form.getAttribute('action');
    const originalTarget = form.getAttribute('target');

    form.setAttribute('action', 'daily_sales_export.php');
    form.setAttribute('target', '_blank');
    form.submit();

    if (originalAction !== null) {
        form.setAttribute('action', originalAction);
    } else {
        form.removeAttribute('action');
    }

    if (originalTarget !== null) {
        form.setAttribute('target', originalTarget);
    } else {
        form.removeAttribute('target');
    }
}
</script>
<script>
const checkAllRows = document.getElementById('checkAllRows');

checkAllRows?.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.checked = this.checked;
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function openEditDailyModal(oldDate, oldBranchId) {
    document.getElementById('editOldDate').value = oldDate;
    document.getElementById('editOldBranchId').value = oldBranchId;
    document.getElementById('editNewDate').value = oldDate;
    document.getElementById('editNewBranchId').value = oldBranchId;

    const modalEl = document.getElementById('editDailyModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;

    const savedState = localStorage.getItem('dashboard_sidebar_state');
    if (savedState === 'collapsed') {
        body.classList.add('dashboard-sidebar-collapsed');
    }

    const toggleSelectors = [
        '#sidebarToggle',
        '#toggleSidebar',
        '.sidebar-toggle',
        '.sidebar-toggler',
        '.topbar-menu-toggle',
        '.hamburger-btn',
        '[data-sidebar-toggle]',
        '.topbar .bi-list',
        '.topbar .fa-bars'
    ];

    let toggleBtn = null;

    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();

            body.classList.toggle('dashboard-sidebar-collapsed');

            if (body.classList.contains('dashboard-sidebar-collapsed')) {
                localStorage.setItem('dashboard_sidebar_state', 'collapsed');
            } else {
                localStorage.setItem('dashboard_sidebar_state', 'open');
            }
        });
    }
});
</script>


</body>
</html>