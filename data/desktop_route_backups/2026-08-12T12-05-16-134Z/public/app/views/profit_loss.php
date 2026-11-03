<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
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

function money($amount): string
{
    return 'PKR ' . number_format((float)$amount, 2);
}

// ================= FETCH CLIENTS =================
$clients = [];
if ($logged_role_id === 1) {
    $res = $conn->query("SELECT id, business_name FROM clients ORDER BY business_name ASC");
    while ($row = $res->fetch_assoc()) {
        $clients[] = $row;
    }
} else {
    $stmt = $conn->prepare("SELECT id, business_name FROM clients WHERE id=?");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $clients[] = $row;
    }
    $stmt->close();
}

// ================= FILTERS =================
$filter_client = $_POST['client_id'] ?? '';
$filter_branch = $_POST['branch_id'] ?? '';
$filter_from   = $_POST['from_date'] ?? date('Y-m-01');
$filter_to     = $_POST['to_date'] ?? date('Y-m-d');

// ================= FETCH BRANCHES =================
$branches = [];
$branch_client_id = ($logged_role_id === 1)
    ? (!empty($filter_client) ? (int)$filter_client : 0)
    : $logged_client_id;

if (!empty($branch_client_id)) {
    $stmt = $conn->prepare("SELECT id, name AS branch_name FROM branches WHERE client_id=? ORDER BY name ASC");
    $stmt->bind_param("i", $branch_client_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $branches[] = $row;
    }
    $stmt->close();
}

// ================= REPORT CONDITIONS =================
$where_sales = " WHERE 1=1 ";
$where_exp   = " WHERE 1=1 ";
$params      = [];
$types       = "";

if ($logged_role_id !== 1) {
    $where_sales .= " AND client_id=? ";
    $where_exp   .= " AND client_id=? ";
    $params[] = $logged_client_id;
    $types .= "i";
} elseif (!empty($filter_client)) {
    $where_sales .= " AND client_id=? ";
    $where_exp   .= " AND client_id=? ";
    $params[] = (int)$filter_client;
    $types .= "i";
}

if (!empty($filter_branch)) {
    $where_sales .= " AND branch_id=? ";
    $where_exp   .= " AND branch_id=? ";
    $params[] = (int)$filter_branch;
    $types .= "i";
}

if (!empty($filter_from)) {
    $where_sales .= " AND DATE(created_at) >= ? ";
    $where_exp   .= " AND DATE(created_at) >= ? ";
    $params[] = $filter_from;
    $types .= "s";
}

if (!empty($filter_to)) {
    $where_sales .= " AND DATE(created_at) <= ? ";
    $where_exp   .= " AND DATE(created_at) <= ? ";
    $params[] = $filter_to;
    $types .= "s";
}

// ================= SALES DATA =================
$sqlSales = "
SELECT
    COALESCE(SUM(total),0) AS total_sales,
    COALESCE(SUM(discount),0) AS total_discount,
    COALESCE(SUM(tax),0) AS total_tax
FROM sales
$where_sales
";

$stmt = $conn->prepare($sqlSales);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$sales = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ================= EXPENSE DATA =================
$sqlExpense = "
SELECT COALESCE(SUM(amount),0) AS total_expenses
FROM expenses
$where_exp
";

$stmt = $conn->prepare($sqlExpense);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$expense = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalSales    = (float)($sales['total_sales'] ?? 0);
$totalDiscount = (float)($sales['total_discount'] ?? 0);
$totalTax      = (float)($sales['total_tax'] ?? 0);
$totalExpenses = (float)($expense['total_expenses'] ?? 0);

$grossProfit = $totalSales - $totalDiscount;
$netProfit   = $grossProfit - $totalTax - $totalExpenses;

$profitMargin = $totalSales > 0 ? ($netProfit / $totalSales) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Report</title>
    
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

    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
html, body{height:100%;}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-panel-2:#111827;
    --oms-card:#101827;
    --oms-card-soft:#121c31;
    --oms-border:rgba(148,163,184,.14);
    --oms-border-2:rgba(148,163,184,.22);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-muted-2:#64748b;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-purple-soft:rgba(109,76,255,.14);
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-orange:#f97316;
    --oms-red:#ef4444;
    --oms-cyan:#06b6d4;
    --oms-yellow:#f59e0b;
}

*{box-sizing:border-box;}

body{
    --current-sidebar-width:var(--sidebar-open-width);
    margin:0;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
    color:var(--oms-text);
    overflow-x:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%);
}

body.dashboard-sidebar-collapsed{--current-sidebar-width:var(--sidebar-close-width);}

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

body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #dashboardSidebar .search-box{display:none !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow{display:none !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{display:none !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link{justify-content:center !important;padding:11px 0 !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main{justify-content:center !important;gap:0 !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon{margin:0 !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-inner{padding-left:8px !important;padding-right:8px !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .bottom-area{padding-left:0 !important;padding-right:0 !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .new-customer-btn{width:52px !important;height:42px !important;margin:0 auto !important;padding:0 !important;border-radius:12px !important;gap:0 !important;}
body.dashboard-sidebar-collapsed #dashboardSidebar .new-customer-btn span{display:none !important;}

body:not(.dashboard-sidebar-collapsed) #dashboardSidebar .search-box{display:block !important;}
body:not(.dashboard-sidebar-collapsed) #dashboardSidebar .menu-title{display:inline-block !important;}
body:not(.dashboard-sidebar-collapsed) #dashboardSidebar .menu-arrow{display:inline-block !important;}
body:not(.dashboard-sidebar-collapsed) #dashboardSidebar .menu-link{justify-content:space-between !important;}
body:not(.dashboard-sidebar-collapsed) #dashboardSidebar .menu-main{justify-content:flex-start !important;gap:9px !important;}

.topbar-wrap,
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
    overflow:visible !important;
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

.content-wrapper > *{position:relative;z-index:1;}

.page-header{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.24), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.78), rgba(17,24,39,.58));
    border:1px solid rgba(148,163,184,.16);
    border-radius:22px;
    padding:22px 24px;
    margin-bottom:18px;
    box-shadow:0 20px 55px rgba(0,0,0,.32);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
    backdrop-filter:blur(18px) saturate(130%);
    -webkit-backdrop-filter:blur(18px) saturate(130%);
}

.page-title{
    font-size:27px;
    font-weight:900;
    margin:0;
    color:#fff;
    letter-spacing:-.03em;
    text-shadow:0 10px 30px rgba(0,0,0,.25);
}

.page-subtitle{
    margin:7px 0 0;
    color:#94a3b8;
    font-size:14px;
    font-weight:650;
}

.btn-print{
    min-height:48px;
    border:none;
    border-radius:13px;
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    color:#fff;
    font-weight:900;
    padding:12px 18px;
    box-shadow:0 14px 28px rgba(109,76,255,.24);
    border:1px solid rgba(139,92,246,.45);
    transition:.22s ease;
}

.btn-print:hover{
    color:#fff;
    transform:translateY(-1px);
    background:linear-gradient(135deg,#7c3aed,#6d4cff);
    box-shadow:0 18px 36px rgba(109,76,255,.30);
}

.stats-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
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
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22);
    filter:saturate(1.08);
    box-shadow:0 26px 65px rgba(0,0,0,.46), 0 0 48px rgba(124,58,237,.18);
}

.stat-title{
    font-size:15px;
    font-weight:800;
    opacity:.95;
    margin-bottom:12px;
    position:relative;
    z-index:2;
}

.stat-value{
    font-size:28px;
    font-weight:900;
    line-height:1.12;
    position:relative;
    z-index:2;
    letter-spacing:-.02em;
}

.stat-note{
    font-size:13px;
    margin-top:12px;
    opacity:.9;
    font-weight:700;
    position:relative;
    z-index:2;
}

.stat-blue{background:linear-gradient(135deg,#2563eb,#6d4cff);}
.stat-cyan{background:linear-gradient(135deg,#0f766e,#06b6d4);}
.stat-gold{background:linear-gradient(135deg,#f59e0b,#fb923c);}
.stat-green{background:linear-gradient(135deg,#047857,#16a34a);}
.stat-red{background:linear-gradient(135deg,#be123c,#ef4444);}

.panel-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46));
    border:1px solid rgba(148,163,184,.16);
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    border-radius:18px;
    box-shadow:0 18px 48px rgba(0,0,0,.26), inset 0 1px 0 rgba(255,255,255,.045);
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, background .25s ease;
}

.panel-card:hover{
    transform:translateY(-3px);
    border-color:rgba(139,92,246,.45);
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.74), rgba(31,20,60,.58));
    box-shadow:0 24px 65px rgba(0,0,0,.45), 0 0 0 1px rgba(124,58,237,.16), 0 0 42px rgba(124,58,237,.18), inset 0 1px 0 rgba(255,255,255,.06);
}

.filter-card{padding:18px;margin-bottom:18px;}

.filter-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:16px;
    flex-wrap:wrap;
    padding-bottom:12px;
    border-bottom:1px solid rgba(148,163,184,.13);
}

.filter-title,
.table-title{
    font-size:18px;
    font-weight:900;
    color:#f8fafc;
    margin:0;
    letter-spacing:-.01em;
}

.filter-subtitle,
.table-subtitle{
    margin:5px 0 0;
    color:#94a3b8;
    font-size:13px;
    font-weight:650;
}

.filter-row{
    display:grid;
    grid-template-columns:repeat(5, minmax(0,1fr));
    gap:14px;
    align-items:end;
}

.field-group{display:flex;flex-direction:column;gap:7px;}
.field-label{font-size:13px;font-weight:800;color:#cbd5e1;}

.erp-select,
.erp-input{
    width:100%;
    height:48px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:12px;
    padding:0 14px;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-size:14px;
    font-weight:650;
    outline:none;
    box-shadow:0 12px 24px rgba(0,0,0,.16);
    transition:.2s ease;
}

.erp-select option{background:#0f172a;color:#e5e7eb;}
.erp-select:focus,
.erp-input:focus{
    background:#111827;
    color:#fff;
    border-color:rgba(109,76,255,.55);
    box-shadow:0 0 0 3px rgba(109,76,255,.14), 0 12px 24px rgba(0,0,0,.18);
}

.erp-input::-webkit-calendar-picker-indicator{filter:invert(1);opacity:.75;}

.filter-actions{display:flex;gap:10px;align-items:end;flex-wrap:wrap;}

.btn-apply,
.btn-reset{
    height:48px;
    border:none;
    border-radius:12px;
    padding:0 18px;
    font-weight:900;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    transition:.22s ease;
}

.btn-apply{
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    color:#fff;
    border:1px solid rgba(139,92,246,.45);
    box-shadow:0 14px 28px rgba(109,76,255,.22);
}

.btn-reset{
    background:rgba(15,23,42,.72);
    color:#cbd5e1;
    border:1px solid rgba(148,163,184,.18);
}

.btn-apply:hover,
.btn-reset:hover{transform:translateY(-1px);color:#fff;}
.btn-reset:hover{background:rgba(124,58,237,.14);border-color:rgba(139,92,246,.42);}

.table-card{padding:0;overflow:hidden;}
.table-head{
    background:rgba(7,11,20,.36);
    border-bottom:1px solid rgba(148,163,184,.14);
    padding:17px 20px 14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.summary-badge{
    background:rgba(109,76,255,.16);
    color:#c4b5fd;
    border:1px solid rgba(109,76,255,.24);
    border-radius:999px;
    padding:8px 12px;
    font-size:12px;
    font-weight:900;
}

.table-responsive{border-radius:0;overflow:auto;background:transparent;}

.report-table{
    width:100%;
    min-width:760px;
    border-collapse:separate;
    border-spacing:0;
    margin:0;
    background:transparent !important;
    color:#e5e7eb;
}

.report-table thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    font-size:13px;
    font-weight:900;
    padding:14px 16px;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    white-space:nowrap;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.report-table tbody td{
    padding:15px 16px;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    color:#e5e7eb !important;
    font-size:14px;
    font-weight:650;
    vertical-align:middle;
    background:rgba(15,23,42,.22) !important;
}

.report-table tbody tr{transition:.18s ease;}
.report-table tbody tr:hover td{background:rgba(124,58,237,.12) !important;color:#fff !important;}

.amount-negative{color:#f87171 !important;font-weight:900;}
.amount-positive{color:#4ade80 !important;font-weight:900;}
.amount-muted{color:#f8fafc !important;font-weight:850;}

.total-row td{
    background:rgba(124,58,237,.12) !important;
    font-weight:900;
    border-top:1px solid rgba(139,92,246,.35) !important;
}

.pill-profit{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:108px;
    padding:7px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    text-transform:capitalize;
}

.pill-profit.good{
    background:rgba(34,197,94,.14);
    color:#4ade80;
    border:1px solid rgba(34,197,94,.24);
}

.pill-profit.bad{
    background:rgba(239,68,68,.14);
    color:#f87171;
    border:1px solid rgba(239,68,68,.24);
}

.print-note{
    padding:14px 20px 18px;
    color:#94a3b8;
    font-size:12px;
    font-weight:650;
    background:rgba(7,11,20,.18);
}

.text-muted, small.text-muted{color:#94a3b8 !important;}

@media (max-width:1200px){
    .stats-grid{grid-template-columns:repeat(2,1fr);}
    .filter-row{grid-template-columns:repeat(2,minmax(0,1fr));}
}

@media (max-width:991px){
    .container-scroll{flex-direction:column;}
    .sidebar{
        width:100%;
        min-width:100%;
        max-width:100%;
        position:relative;
        height:auto;
    }
    #dashboardSidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }
    #dashboardSidebar .sidebar-inner{height:auto !important;min-height:auto !important;}
    .main-panel{margin-left:0;}
    .topbar-wrap,.topbar{position:relative;}
    .content-wrapper{padding:18px;}
}

@media (max-width:768px){
    .stats-grid{grid-template-columns:1fr;}
    .filter-row{grid-template-columns:1fr;}
    .page-header{flex-direction:column;align-items:stretch;}
    .table-head{flex-direction:column;align-items:flex-start;}
    .btn-print,.btn-apply,.btn-reset{width:100%;}
}

@media print{
    body{background:#fff !important;color:#111827 !important;}
    .sidebar,.topbar-wrap,.topbar,.filter-card,.btn-print{display:none !important;}
    .main-panel{margin-left:0 !important;width:100%;background:#fff !important;}
    .content-wrapper{padding:0;background:#fff !important;min-height:auto;}
    .content-wrapper::before,.content-wrapper::after{display:none !important;}
    .page-header,.panel-card,.table-card{box-shadow:none !important;border:1px solid #e5e7eb !important;background:#fff !important;color:#111827 !important;}
    .page-title,.filter-title,.table-title,.report-table tbody td,.report-table thead th{color:#111827 !important;}
    .page-subtitle,.filter-subtitle,.table-subtitle,.print-note{color:#4b5563 !important;}
    .report-table tbody td,.report-table thead th{background:#fff !important;border-color:#e5e7eb !important;}
}

/* ===== PROFIT LOSS FILTER SMALL + ABOVE CARDS FIX ===== */

.filter-card{
    padding:12px 14px !important;
    margin-bottom:14px !important;
    border-radius:16px !important;
}

/* filter heading remove/small */
.filter-head{
    display:none !important;
}

/* filter fields compact */
.filter-row{
    grid-template-columns:1.1fr 1fr 1fr auto !important;
    gap:10px !important;
    align-items:end !important;
}

/* input/select height small */
.erp-select,
.erp-input{
    height:42px !important;
    min-height:42px !important;
    border-radius:11px !important;
    font-size:13px !important;
    padding:0 12px !important;
}

/* labels small */
.field-label{
    font-size:12px !important;
    margin-bottom:2px !important;
}

/* buttons small */
.filter-actions{
    display:flex !important;
    gap:8px !important;
    align-items:end !important;
    flex-wrap:nowrap !important;
}

.btn-apply,
.btn-reset{
    height:42px !important;
    min-height:42px !important;
    border-radius:11px !important;
    padding:0 14px !important;
    font-size:13px !important;
    white-space:nowrap !important;
}

/* cards thora neeche gap */
.stats-grid{
    margin-top:0 !important;
    margin-bottom:18px !important;
}

/* desktop par compact one-line */
@media (min-width:1201px){
    .filter-row{
        grid-template-columns:1.1fr 1fr 1fr auto !important;
    }
}

/* superadmin client dropdown ho to 5 columns */
@media (min-width:1201px){
    .filter-row:has(#clientSelect){
        grid-template-columns:1fr 1fr 1fr 1fr auto !important;
    }
}

/* mobile/tablet */
@media (max-width:1200px){
    .filter-row{
        grid-template-columns:repeat(2, minmax(0,1fr)) !important;
    }

    .filter-actions{
        grid-column:1 / -1 !important;
    }

    .btn-apply,
    .btn-reset{
        flex:1 !important;
    }
}

@media (max-width:768px){
    .filter-row{
        grid-template-columns:1fr !important;
    }

    .filter-actions{
        flex-direction:column !important;
    }

    .btn-apply,
    .btn-reset{
        width:100% !important;
    }
}

/* ===== PROFIT LOSS FILTER ICON NEAR TITLE + SIDEBAR ===== */

.title-filter-wrap{
    display:flex !important;
    align-items:flex-start !important;
    gap:14px !important;
}

.btn-filter-icon{
    width:46px !important;
    height:46px !important;
    min-width:46px !important;
    border:none !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    color:#fff !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border:1px solid rgba(139,92,246,.45) !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    font-size:16px !important;
    margin-top:2px !important;
}

.btn-filter-icon:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

/* backdrop */
.pl-filter-backdrop{
    position:fixed !important;
    inset:0 !important;
    z-index:5000 !important;
    background:rgba(2,6,23,.48) !important;
    backdrop-filter:blur(8px) !important;
    opacity:0 !important;
    visibility:hidden !important;
    pointer-events:none !important;
    transition:.25s ease !important;
}

body.pl-filter-open .pl-filter-backdrop{
    opacity:1 !important;
    visibility:visible !important;
    pointer-events:auto !important;
}

/* right filter sidebar */
.pl-filter-sidebar{
    position:fixed !important;
    top:0 !important;
    right:0 !important;
    width:380px !important;
    max-width:calc(100vw - 20px) !important;
    height:100vh !important;
    z-index:5010 !important;
    padding:18px !important;
    color:#f8fafc !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 36%),
        linear-gradient(135deg, rgba(8,13,31,.98), rgba(15,23,42,.96)) !important;
    border-left:1px solid rgba(139,92,246,.35) !important;
    box-shadow:-24px 0 70px rgba(0,0,0,.62) !important;
    transform:translateX(105%) !important;
    transition:transform .28s ease !important;
    overflow-y:auto !important;
}

body.pl-filter-open .pl-filter-sidebar{
    transform:translateX(0) !important;
}

.pl-filter-sidebar-head{
    display:flex !important;
    align-items:flex-start !important;
    justify-content:space-between !important;
    gap:12px !important;
    padding-bottom:16px !important;
    margin-bottom:16px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

.pl-filter-sidebar-head h3{
    margin:0 !important;
    color:#fff !important;
    font-size:22px !important;
    font-weight:950 !important;
}

.pl-filter-sidebar-head p{
    margin:5px 0 0 !important;
    color:#94a3b8 !important;
    font-size:13px !important;
    font-weight:700 !important;
}

.pl-filter-close{
    width:42px !important;
    height:42px !important;
    border:none !important;
    border-radius:13px !important;
    color:#e5e7eb !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.pl-filter-form{
    display:grid !important;
    gap:14px !important;
}

.pl-field{
    display:flex !important;
    flex-direction:column !important;
    gap:7px !important;
}

.pl-field label{
    color:#cbd5e1 !important;
    font-size:13px !important;
    font-weight:900 !important;
}

.pl-filter-sidebar .erp-select,
.pl-filter-sidebar .erp-input{
    width:100% !important;
    height:46px !important;
    border-radius:13px !important;
    border:1px solid rgba(167,139,250,.28) !important;
    background:rgba(8,13,31,.88) !important;
    color:#f8fafc !important;
    font-size:14px !important;
    font-weight:800 !important;
    padding:0 14px !important;
}

.pl-filter-sidebar .erp-select option{
    background:#0f172a !important;
    color:#f8fafc !important;
}

.pl-filter-actions{
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:10px !important;
    margin-top:8px !important;
    padding-top:16px !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

.pl-reset-btn,
.pl-apply-btn{
    height:48px !important;
    border:none !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    font-size:14px !important;
    font-weight:950 !important;
}

.pl-reset-btn{
    color:#e5e7eb !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.pl-apply-btn{
    color:#fff !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border:1px solid rgba(139,92,246,.45) !important;
}

@media(max-width:576px){
    .page-header{
        align-items:stretch !important;
    }

    .title-filter-wrap{
        justify-content:space-between !important;
    }

    .pl-filter-sidebar{
        width:100% !important;
        max-width:100% !important;
    }
}


/* ===== PROFIT LOSS HEADER ICON BUTTONS FINAL ===== */

/* right side icons wrapper */
.page-actions{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:10px !important;
    margin-left:auto !important;
}

/* filter + print same size icon buttons */
.btn-filter-icon,
.btn-print-icon{
    width:48px !important;
    height:48px !important;
    min-width:48px !important;
    padding:0 !important;
    border:none !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    color:#fff !important;
    font-size:16px !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(255,255,255,.18), transparent 36%),
        linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border:1px solid rgba(139,92,246,.45) !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.btn-filter-icon:hover,
.btn-print-icon:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
    box-shadow:0 18px 38px rgba(109,76,255,.34) !important;
}

/* old print button style text wala effect remove */
.btn-print-icon i,
.btn-filter-icon i{
    margin:0 !important;
    line-height:1 !important;
}

/* agar purana .btn-print button kahin effect de raha ho */
.page-actions .btn-print{
    width:48px !important;
    height:48px !important;
    padding:0 !important;
    font-size:0 !important;
}

/* mobile par title upar, icons right side */
@media(max-width:768px){
    .page-header{
        display:flex !important;
        flex-direction:row !important;
        align-items:flex-start !important;
        justify-content:space-between !important;
        gap:12px !important;
    }

    .page-header > div:first-child{
        flex:1 !important;
        min-width:0 !important;
    }

    .page-actions{
        width:auto !important;
        flex:0 0 auto !important;
        display:flex !important;
        gap:8px !important;
    }

    .btn-filter-icon,
    .btn-print-icon{
        width:44px !important;
        height:44px !important;
        min-width:44px !important;
        border-radius:13px !important;
    }
}

/* print ke waqt icons hide */
@media print{
    .page-actions,
    .btn-filter-icon,
    .btn-print-icon{
        display:none !important;
    }
}




/* =========================================================
   PROFIT LOSS PAGE - FINAL LIGHT MODE
   Topbar aur sidebar dark navy-purple hi rahenge.
   Is block ko final 
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;

    --pl-light-bg:#f3f6fb;
    --pl-light-card:#ffffff;
    --pl-light-soft:#f8fafc;
    --pl-light-border:#dce4ef;
    --pl-light-border-strong:#cbd5e1;
    --pl-light-title:#0f172a;
    --pl-light-text:#334155;
    --pl-light-muted:#64748b;
    --pl-light-purple:#7c3aed;
    --pl-light-purple-dark:#6d28d9;
}

/* =========================================================
   MAIN PAGE BACKGROUND
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

/* Sidebar ko target nahi kiya ja raha */
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

/* Main content */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 7%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 86% 4%,
            rgba(59,130,246,.05),
            transparent 28%
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

/* Dark grid ko light karein */
html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.022) 1px,
            transparent 1px
        ) !important;

    opacity:.42 !important;
    mask-image:none !important;
}

/* Dark purple large glow soft karein */
html[data-pos-theme="light"] body .content-wrapper::after{
    background:
        radial-gradient(
            circle,
            rgba(124,58,237,.08),
            transparent 68%
        ) !important;

    filter:blur(16px) !important;
    opacity:.45 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-header{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body .page-subtitle{
    color:#64748b !important;
}

/* Filter and print header buttons */
html[data-pos-theme="light"] body .btn-filter-icon,
html[data-pos-theme="light"] body .btn-print-icon,
html[data-pos-theme="light"] body .btn-print{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border:1px solid #8b5cf6 !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.22) !important;
}

html[data-pos-theme="light"] body .btn-filter-icon:hover,
html[data-pos-theme="light"] body .btn-print-icon:hover,
html[data-pos-theme="light"] body .btn-print:hover{
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

html[data-pos-theme="light"] body .stat-title,
html[data-pos-theme="light"] body .stat-value,
html[data-pos-theme="light"] body .stat-note{
    color:#ffffff !important;
    opacity:1 !important;
}

/* Colored cards preserve */
html[data-pos-theme="light"] body .stat-blue{
    background:
        linear-gradient(
            135deg,
            #2563eb,
            #6d4cff
        ) !important;
}

html[data-pos-theme="light"] body .stat-cyan{
    background:
        linear-gradient(
            135deg,
            #0f766e,
            #06b6d4
        ) !important;
}

html[data-pos-theme="light"] body .stat-gold{
    background:
        linear-gradient(
            135deg,
            #f59e0b,
            #fb923c
        ) !important;
}

html[data-pos-theme="light"] body .stat-green{
    background:
        linear-gradient(
            135deg,
            #047857,
            #16a34a
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
   COMMON LIGHT PANEL CARD
========================================================= */

html[data-pos-theme="light"] body .panel-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .panel-card:hover{
    background:#ffffff !important;
    background-image:none !important;

    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 36px rgba(15,23,42,.09) !important;

    transform:translateY(-2px) !important;
}

/* =========================================================
   OLD FILTER CARD SUPPORT
========================================================= */

html[data-pos-theme="light"] body .filter-head{
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .filter-title,
html[data-pos-theme="light"] body .table-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .filter-subtitle,
html[data-pos-theme="light"] body .table-subtitle{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .field-label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body .erp-select,
html[data-pos-theme="light"] body .erp-input{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .erp-input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .erp-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .erp-select:focus,
html[data-pos-theme="light"] body .erp-input:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"]
body .erp-input::-webkit-calendar-picker-indicator{
    filter:none !important;
    opacity:.75 !important;
}

html[data-pos-theme="light"] body input[type="date"]{
    color-scheme:light !important;
}

html[data-pos-theme="light"] body .btn-apply{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
}

html[data-pos-theme="light"] body .btn-reset{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .btn-reset:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   PROFIT LOSS TABLE
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .table-head{
    background:#f8fafc !important;
    background-image:none !important;

    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .table-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .table-subtitle{
    color:#64748b !important;
}

/* Date badge */
html[data-pos-theme="light"] body .summary-badge{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border:1px solid #ddd6fe !important;
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

/* Table header */
html[data-pos-theme="light"] body .report-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-bottom:1px solid #cbd5e1 !important;
}

/* Normal table rows */
html[data-pos-theme="light"] body .report-table tbody tr,
html[data-pos-theme="light"] body .report-table tbody td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-bottom-color:#e8edf4 !important;

    box-shadow:none !important;
}

/* Hover */
html[data-pos-theme="light"]
body .report-table tbody tr:hover td{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;

    color:#0f172a !important;
}

/* Amount colors */
html[data-pos-theme="light"] body .amount-muted{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .amount-negative{
    color:#e11d48 !important;
}

html[data-pos-theme="light"] body .amount-positive{
    color:#047857 !important;
}

/* Net total row */
html[data-pos-theme="light"]
body .report-table tbody tr.total-row td{
    background:#f3efff !important;
    background-color:#f3efff !important;

    color:#0f172a !important;

    border-top:1px solid #c4b5fd !important;

    font-weight:900 !important;
}

/* Status pills */
html[data-pos-theme="light"] body .pill-profit.good{
    background:#ecfdf5 !important;
    color:#047857 !important;

    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .pill-profit.bad{
    background:#fff1f2 !important;
    color:#be123c !important;

    border:1px solid #fecdd3 !important;
}

/* Print note */
html[data-pos-theme="light"] body .print-note{
    background:#f8fafc !important;
    color:#64748b !important;

    border-top:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .text-muted,
html[data-pos-theme="light"] body small.text-muted{
    color:#64748b !important;
}

/* =========================================================
   RIGHT SIDE FILTER DRAWER
========================================================= */

html[data-pos-theme="light"] body .pl-filter-backdrop{
    background:rgba(15,23,42,.38) !important;
    backdrop-filter:blur(5px) !important;
}

/* Filter drawer */
html[data-pos-theme="light"] body .pl-filter-sidebar{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border-left:1px solid #dce4ef !important;

    box-shadow:
        -24px 0 70px rgba(15,23,42,.20) !important;
}

/* Drawer heading */
html[data-pos-theme="light"]
body .pl-filter-sidebar-head{
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"]
body .pl-filter-sidebar-head h3{
    color:#0f172a !important;
}

html[data-pos-theme="light"]
body .pl-filter-sidebar-head p{
    color:#64748b !important;
}

/* Close button */
html[data-pos-theme="light"] body .pl-filter-close{
    background:#f8fafc !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body .pl-filter-close:hover{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

/* Drawer labels */
html[data-pos-theme="light"] body .pl-field label{
    color:#334155 !important;
}

/* Drawer fields */
html[data-pos-theme="light"]
body .pl-filter-sidebar .erp-select,

html[data-pos-theme="light"]
body .pl-filter-sidebar .erp-input{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"]
body .pl-filter-sidebar .erp-select:focus,

html[data-pos-theme="light"]
body .pl-filter-sidebar .erp-input:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"]
body .pl-filter-sidebar .erp-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Drawer action area */
html[data-pos-theme="light"] body .pl-filter-actions{
    border-top:1px solid #e2e8f0 !important;
}

/* Reset */
html[data-pos-theme="light"] body .pl-reset-btn{
    background:#f1f5f9 !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body .pl-reset-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Apply */
html[data-pos-theme="light"] body .pl-apply-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    border:1px solid #8b5cf6 !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

/* =========================================================
   SCROLLBARS
========================================================= */

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-track,

html[data-pos-theme="light"]
body .table-responsive::-webkit-scrollbar-track,

html[data-pos-theme="light"]
body .pl-filter-sidebar::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-thumb,

html[data-pos-theme="light"]
body .table-responsive::-webkit-scrollbar-thumb,

html[data-pos-theme="light"]
body .pl-filter-sidebar::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
    border-radius:999px !important;
}

/* =========================================================
   PRINT MODE FINAL SAFETY
========================================================= */

@media print{

    html[data-pos-theme="light"] body{
        background:#ffffff !important;
        color:#111827 !important;
    }

    html[data-pos-theme="light"] body .sidebar,
    html[data-pos-theme="light"] body .topbar-wrap,
    html[data-pos-theme="light"] body .topbar,
    html[data-pos-theme="light"] body .pl-filter-sidebar,
    html[data-pos-theme="light"] body .pl-filter-backdrop,
    html[data-pos-theme="light"] body .page-actions{
        display:none !important;
    }

    html[data-pos-theme="light"] body .main-panel{
        margin-left:0 !important;
        width:100% !important;
        background:#ffffff !important;
    }

    html[data-pos-theme="light"] body .content-wrapper{
        padding:0 !important;
        background:#ffffff !important;
    }

    html[data-pos-theme="light"] body .content-wrapper::before,
    html[data-pos-theme="light"] body .content-wrapper::after{
        display:none !important;
    }

    html[data-pos-theme="light"] body .page-header,
    html[data-pos-theme="light"] body .panel-card,
    html[data-pos-theme="light"] body .table-card{
        background:#ffffff !important;
        box-shadow:none !important;
        border:1px solid #e5e7eb !important;
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
<body>
<div class="container-scroll">
 <div class="sidebar" id="dashboardSidebar">
         <div id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>
    </div>

    <div class="main-panel">
        <div class="topbar-wrap">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">
<div class="page-header">
    <div>
        <h1 class="page-title">Profit &amp; Loss Report</h1>
        <p class="page-subtitle">Overview of sales, discounts, tax, expenses, and final profitability for the selected period.</p>
    </div>

    <div class="page-actions">
        <button type="button" class="btn-filter-icon" id="openProfitFilter" title="Filters">
            <i class="fas fa-filter"></i>
        </button>

        <button type="button" class="btn-print-icon" onclick="window.print()" title="Print Report">
            <i class="fas fa-print"></i>
        </button>
    </div>
</div>
<div class="pl-filter-backdrop" id="profitFilterBackdrop"></div>

<aside class="pl-filter-sidebar" id="profitFilterSidebar">
    <div class="pl-filter-sidebar-head">
        <div>
            <h3>Filters</h3>
            <p>Refine profit & loss report.</p>
        </div>

        <button type="button" class="pl-filter-close" id="closeProfitFilter">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <form method="POST" class="pl-filter-form">
        <?php if ($logged_role_id == 1): ?>
            <div class="pl-field">
                <label>Client</label>
                <select name="client_id" id="clientSelect" class="erp-select">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ($filter_client == $c['id']) ? 'selected' : '' ?>>
                            <?= e($c['business_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="pl-field">
            <label>Branch</label>
            <select name="branch_id" id="branchSelect" class="erp-select">
                <option value="">All Branches</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= ($filter_branch == $b['id']) ? 'selected' : '' ?>>
                        <?= e($b['branch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="pl-field">
            <label>From Date</label>
            <input type="date" name="from_date" value="<?= e($filter_from) ?>" class="erp-input">
        </div>

        <div class="pl-field">
            <label>To Date</label>
            <input type="date" name="to_date" value="<?= e($filter_to) ?>" class="erp-input">
        </div>

        <div class="pl-filter-actions">
            <a href="profit_loss.php" class="pl-reset-btn">
                <i class="fas fa-rotate-right"></i> Reset
            </a>

            <button type="submit" class="pl-apply-btn">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>
    </form>
</aside>

      <!--<div class="panel-card filter-card">-->
      <!--          <div class="filter-head">-->
      <!--              <div>-->
      <!--                  <h3 class="filter-title">Filters</h3>-->
      <!--                  <p class="filter-subtitle">Refine the report by client, branch, and date range.</p>-->
      <!--              </div>-->
      <!--          </div>-->

      <!--          <form method="POST">-->
      <!--              <div class="filter-row">-->
      <!--                  <?php if ($logged_role_id == 1): ?>-->
      <!--                      <div class="field-group">-->
      <!--                          <label class="field-label">Client</label>-->
      <!--                          <select name="client_id" id="clientSelect" class="erp-select">-->
      <!--                              <option value="">All Clients</option>-->
      <!--                              <?php foreach ($clients as $c): ?>-->
      <!--                                  <option value="<?= (int)$c['id'] ?>" <?= ($filter_client == $c['id']) ? 'selected' : '' ?>>-->
      <!--                                      <?= e($c['business_name']) ?>-->
      <!--                                  </option>-->
      <!--                              <?php endforeach; ?>-->
      <!--                          </select>-->
      <!--                      </div>-->
      <!--                  <?php endif; ?>-->

      <!--                  <div class="field-group">-->
      <!--                      <label class="field-label">Branch</label>-->
      <!--                      <select name="branch_id" id="branchSelect" class="erp-select">-->
      <!--                          <option value="">All Branches</option>-->
      <!--                          <?php foreach ($branches as $b): ?>-->
      <!--                              <option value="<?= (int)$b['id'] ?>" <?= ($filter_branch == $b['id']) ? 'selected' : '' ?>>-->
      <!--                                  <?= e($b['branch_name']) ?>-->
      <!--                              </option>-->
      <!--                          <?php endforeach; ?>-->
      <!--                      </select>-->
      <!--                  </div>-->

      <!--                  <div class="field-group">-->
      <!--                      <label class="field-label">From Date</label>-->
      <!--                      <input type="date" name="from_date" value="<?= e($filter_from) ?>" class="erp-input">-->
      <!--                  </div>-->

      <!--                  <div class="field-group">-->
      <!--                      <label class="field-label">To Date</label>-->
      <!--                      <input type="date" name="to_date" value="<?= e($filter_to) ?>" class="erp-input">-->
      <!--                  </div>-->

      <!--                  <div class="filter-actions">-->
      <!--                      <button type="submit" class="btn-apply">-->
      <!--                          <i class="fas fa-filter me-1"></i> Apply Filter-->
      <!--                      </button>-->

      <!--                      <a href="" class="btn-reset">-->
      <!--                          <i class="fas fa-rotate-right me-1"></i> Reset-->
      <!--                      </a>-->
      <!--                  </div>-->
      <!--              </div>-->
      <!--          </form>-->
      <!--      </div>-->

            <div class="stats-grid">
                <div class="stat-card stat-blue">
                    <div class="stat-title">Total Sales</div>
                    <div class="stat-value"><?= money($totalSales) ?></div>
                    <div class="stat-note">Revenue before deductions</div>
                </div>

                <div class="stat-card stat-cyan">
                    <div class="stat-title">Total Discount</div>
                    <div class="stat-value"><?= money($totalDiscount) ?></div>
                    <div class="stat-note">Discounts given to customers</div>
                </div>

                <div class="stat-card stat-gold">
                    <div class="stat-title">Total Expenses</div>
                    <div class="stat-value"><?= money($totalExpenses) ?></div>
                    <div class="stat-note">Operating expenses in range</div>
                </div>

                <div class="stat-card <?= $netProfit >= 0 ? 'stat-green' : 'stat-red' ?>">
                    <div class="stat-title"><?= $netProfit >= 0 ? 'Net Profit' : 'Net Loss' ?></div>
                    <div class="stat-value"><?= money(abs($netProfit)) ?></div>
                    <div class="stat-note">Margin: <?= number_format($profitMargin, 2) ?>%</div>
                </div>
            </div>

      

            <div class="panel-card table-card">
                <div class="table-head">
                    <div>
                        <h3 class="table-title">Profit &amp; Loss Summary</h3>
                        <p class="table-subtitle">Complete financial summary for the selected reporting window.</p>
                    </div>

                    <div class="summary-badge">
                        <?= e($filter_from) ?> to <?= e($filter_to) ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Total Sales</td>
                                <td class="text-end amount-muted"><?= money($totalSales) ?></td>
                                <td class="text-end"><span class="pill-profit good">Income</span></td>
                            </tr>
                            <tr>
                                <td>Total Discount</td>
                                <td class="text-end amount-negative">- <?= money($totalDiscount) ?></td>
                                <td class="text-end"><span class="pill-profit bad">Deduction</span></td>
                            </tr>
                            <tr>
                                <td>Gross Profit</td>
                                <td class="text-end amount-muted"><?= money($grossProfit) ?></td>
                                <td class="text-end"><span class="pill-profit good">Intermediate</span></td>
                            </tr>
                            <tr>
                                <td>Total Tax</td>
                                <td class="text-end amount-negative">- <?= money($totalTax) ?></td>
                                <td class="text-end"><span class="pill-profit bad">Deduction</span></td>
                            </tr>
                            <tr>
                                <td>Total Expenses</td>
                                <td class="text-end amount-negative">- <?= money($totalExpenses) ?></td>
                                <td class="text-end"><span class="pill-profit bad">Expense</span></td>
                            </tr>
                            <tr class="total-row">
                                <td><?= $netProfit >= 0 ? 'Net Profit' : 'Net Loss' ?></td>
                                <td class="text-end <?= $netProfit >= 0 ? 'amount-positive' : 'amount-negative' ?>">
                                    <?= money(abs($netProfit)) ?>
                                </td>
                                <td class="text-end">
                                    <span class="pill-profit <?= $netProfit >= 0 ? 'good' : 'bad' ?>">
                                        <?= $netProfit >= 0 ? 'Profitable' : 'Loss' ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="print-note">
                    Generated from the current filter selection. Use Print Report for a clean printable version.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($logged_role_id == 1): ?>
<script>
document.getElementById('clientSelect')?.addEventListener('change', function () {
    const clientId = this.value;
    const branchSelect = document.getElementById('branchSelect');

    branchSelect.innerHTML = '<option value="">Loading...</option>';

    fetch('fetch_branches.php?client_id=' + encodeURIComponent(clientId))
        .then(response => response.json())
        .then(data => {
            branchSelect.innerHTML = '<option value="">All Branches</option>';

            data.forEach(branch => {
                const option = document.createElement('option');
                option.value = branch.id;
                option.textContent = branch.branch_name;
                branchSelect.appendChild(option);
            });
        })
        .catch(() => {
            branchSelect.innerHTML = '<option value="">Unable to load branches</option>';
        });
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const openBtn = document.getElementById('openProfitFilter');
    const closeBtn = document.getElementById('closeProfitFilter');
    const backdrop = document.getElementById('profitFilterBackdrop');

    openBtn?.addEventListener('click', function (e) {
        e.preventDefault();
        body.classList.add('pl-filter-open');
    });

    closeBtn?.addEventListener('click', function (e) {
        e.preventDefault();
        body.classList.remove('pl-filter-open');
    });

    backdrop?.addEventListener('click', function () {
        body.classList.remove('pl-filter-open');
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            body.classList.remove('pl-filter-open');
        }
    });
});
</script>

</body>
</html>