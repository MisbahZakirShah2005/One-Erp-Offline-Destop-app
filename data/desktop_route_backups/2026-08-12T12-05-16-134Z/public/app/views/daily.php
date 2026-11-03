<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = $_SESSION['user_id'];
$logged_role_id   = $_SESSION['role_id'];
$logged_client_id = $_SESSION['client_id'] ?? 0;

// ================= FETCH CLIENTS =================
$clients = [];
if($logged_role_id == 1){ // SUPER ADMIN
    $res = $conn->query("SELECT id, business_name FROM clients ORDER BY business_name ASC");
    while($row = $res->fetch_assoc()) $clients[] = $row;
} else {
    $stmt = $conn->prepare("SELECT id, business_name FROM clients WHERE id=?");
    $stmt->bind_param("i",$logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $clients[] = $row;
    $stmt->close();
}

// ================= FILTERS =================
$filter_client = $_POST['client_id'] ?? '';
$filter_branch = $_POST['branch_id'] ?? '';
$filter_from   = $_POST['from_date'] ?? '';
$filter_to     = $_POST['to_date'] ?? '';

// ================= FETCH BRANCHES BASED ON FILTER CLIENT =================
$branches = [];
if($filter_client != ''){
    $stmt = $conn->prepare("SELECT id, name AS branch_name FROM branches WHERE client_id=? ORDER BY name ASC");
    $stmt->bind_param("i", $filter_client);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $branches[] = $row;
    $stmt->close();
} elseif($logged_role_id != 1){
    $stmt = $conn->prepare("SELECT id, name AS branch_name FROM branches WHERE client_id=? ORDER BY name ASC");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $branches[] = $row;
    $stmt->close();
}

// ================= FETCH DAILY SALES =================
$params = [];
$where = "WHERE 1=1 ";

if($logged_role_id != 1){
    $where .= " AND client_id = ? ";
    $params[] = $logged_client_id;
} elseif($filter_client != ''){
    $where .= " AND client_id = ? ";
    $params[] = $filter_client;
}

if($filter_branch != ''){
    $where .= " AND branch_id = ? ";
    $params[] = $filter_branch;
}

if($filter_from != ''){
    $where .= " AND DATE(created_at) >= ? ";
    $params[] = $filter_from;
}

if($filter_to != ''){
    $where .= " AND DATE(created_at) <= ? ";
    $params[] = $filter_to;
}

// total_amount = total + tax - discount
$sql = "SELECT DATE(created_at) AS sale_date, branch_id, COUNT(id) AS invoice_count, 
               SUM(total + tax - discount) AS total_amount
        FROM sales
        $where
        GROUP BY DATE(created_at), branch_id
        ORDER BY DATE(created_at) DESC";

$stmt = $conn->prepare($sql);
if($stmt === false){
    die("SQL Prepare Error: ".$conn->error);
}
if(count($params) > 0){
    $types = '';
    foreach($params as $p){
        if(is_int($p)) $types .= 'i';
        elseif(is_double($p)) $types .= 'd';
        else $types .= 's';
    }
    $stmt->bind_param($types,...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$daily_sales = [];
while($row = $res->fetch_assoc()) $daily_sales[] = $row;
$stmt->close();
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Daily Sales</title>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
html,
body.admin-dashboard-page {
    margin: 0 !important;
    padding: 0 !important;
    width: 100%;
    min-height: 100%;
    overflow-x: hidden !important;
    background: #f4f7fb !important;
}

/* =====================================================
   ADMIN DAILY SALES PAGE FIX
   Same fixed sidebar/topbar as admin dashboard
===================================================== */

body.admin-dashboard-page {
    --admin-sidebar-open-width: 280px;
    --admin-sidebar-close-width: 88px;
    --admin-current-sidebar-width: var(--admin-sidebar-open-width);
}

body.admin-dashboard-page.sidebar-collapsed,
body.admin-dashboard-page.dashboard-sidebar-collapsed {
    --admin-current-sidebar-width: var(--admin-sidebar-close-width);
}

body.admin-dashboard-page .container-scroll {
    display: flex !important;
    width: 100% !important;
    min-height: 100vh !important;
    align-items: stretch !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #f4f7fb !important;
}

body.admin-dashboard-page .sidebar,
body.admin-dashboard-page #dashboardSidebar {
    width: var(--admin-current-sidebar-width) !important;
    min-width: var(--admin-current-sidebar-width) !important;
    max-width: var(--admin-current-sidebar-width) !important;
    flex-shrink: 0 !important;
    display: flex !important;
    align-self: stretch !important;
    background: transparent !important;
    overflow: visible !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    height: 100vh !important;
    min-height: 100vh !important;
    z-index: 1000 !important;
    transition: width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page .sidebar > div,
body.admin-dashboard-page #dashboardSidebar > div {
    width: 100% !important;
    min-height: 100vh !important;
    height: 100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-wrap {
    width: var(--admin-current-sidebar-width) !important;
    min-width: var(--admin-current-sidebar-width) !important;
    max-width: var(--admin-current-sidebar-width) !important;
    height: 100vh !important;
    min-height: 100vh !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    z-index: 1000 !important;
    overflow: hidden !important;
    border-radius: 0 !important;
    transition: width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-inner {
    width: 100% !important;
    height: 100vh !important;
    min-height: 100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-menu {
    overflow-y: auto !important;
    overflow-x: hidden !important;
}

body.admin-dashboard-page .main-panel {
    margin-left: var(--admin-current-sidebar-width) !important;
    width: calc(100% - var(--admin-current-sidebar-width)) !important;
    min-width: 0 !important;
    min-height: 100vh !important;
    padding: 70px 0 0 0 !important;
    display: block !important;
    background: #f4f7fb !important;
    transition: margin-left .25s ease, width .25s ease !important;
}

body.admin-dashboard-page .navbar.my-navbar.fixed-top {
    left: var(--admin-current-sidebar-width) !important;
    width: calc(100% - var(--admin-current-sidebar-width)) !important;
    height: 70px !important;
    min-height: 70px !important;
    margin: 0 !important;
    z-index: 999 !important;
    transition: left .25s ease, width .25s ease !important;
}

body.admin-dashboard-page .content-wrapper {
    width: 100% !important;
    margin: 0 !important;
    padding: 24px !important;
    min-height: calc(100vh - 70px) !important;
    overflow: visible !important;
    position: relative !important;
    background: #f4f7fb !important;
}

body.admin-dashboard-page form.row {
    background: #fff;
    border-radius: 12px;
    padding: 16px;
    margin-left: 0 !important;
    margin-right: 0 !important;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}

body.admin-dashboard-page .table-responsive {
    width: 100%;
    overflow-x: auto;
    background: #fff;
    border-radius: 12px;
}

body.admin-dashboard-page table.table {
    background: #fff;
    margin-bottom: 0;
}

body.admin-dashboard-page .table th,
body.admin-dashboard-page .table td {
    vertical-align: middle;
    white-space: nowrap;
}

/* Collapsed sidebar text hide */
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-arrow,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow {
    display: none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .submenu-collapse,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse {
    display: none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-link,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-link {
    justify-content: center !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-main,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-main {
    justify-content: center !important;
    gap: 0 !important;
}

/* Mobile */
@media (max-width: 991.98px) {
    body.admin-dashboard-page {
        --admin-current-sidebar-width: 0px !important;
    }

    body.admin-dashboard-page .container-scroll {
        display: block !important;
        width: 100% !important;
        min-height: 100vh !important;
    }

    body.admin-dashboard-page .sidebar,
    body.admin-dashboard-page #dashboardSidebar {
        position: fixed !important;
        top: 0 !important;
        left: -280px !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        margin: 0 !important;
        padding: 0 !important;
        z-index: 3000 !important;
        overflow: hidden !important;
        transition: left .25s ease !important;
    }

    body.admin-dashboard-page .sidebar > div,
    body.admin-dashboard-page #dashboardSidebar > div,
    body.admin-dashboard-page #dashboardSidebar .sidebar-wrap,
    body.admin-dashboard-page #dashboardSidebar .sidebar-inner {
        position: relative !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        max-height: 100dvh !important;
        margin: 0 !important;
        overflow: hidden !important;
        border-radius: 0 !important;
    }

    body.admin-dashboard-page #dashboardSidebar .sidebar-menu {
        height: calc(100dvh - 120px) !important;
        max-height: calc(100dvh - 120px) !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
    }

    body.admin-dashboard-page.sidebar-open #dashboardSidebar,
    body.admin-dashboard-page.dashboard-mobile-sidebar-open #dashboardSidebar {
        left: 0 !important;
    }

    body.admin-dashboard-page .main-panel {
        margin-left: 0 !important;
        width: 100% !important;
        min-width: 0 !important;
        padding-top: 70px !important;
    }

    body.admin-dashboard-page .navbar.my-navbar.fixed-top {
        left: 0 !important;
        width: 100% !important;
        height: 70px !important;
        min-height: 70px !important;
    }

    body.admin-dashboard-page .content-wrapper {
        padding: 16px !important;
        min-height: calc(100vh - 70px) !important;
    }

    body.admin-dashboard-page form.row {
        padding: 12px;
    }

    body.admin-dashboard-page .d-flex.justify-content-between.align-items-center.mb-3 {
        gap: 12px;
        flex-wrap: wrap;
    }
}

/* =====================================================
   DAILY.PHP / DAILY SALES - DARK PURPLE GLASS THEME
   Paste at END before
===================================================== */

html,
body.admin-daily-sales-page{
    margin:0 !important;
    padding:0 !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.32), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-daily-sales-page .container-scroll,
body.admin-daily-sales-page .main-panel,
body.admin-daily-sales-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-daily-sales-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
    overflow:visible !important;
}

body.admin-daily-sales-page .content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.25;
    z-index:0;
}

body.admin-daily-sales-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-daily-sales-page .navbar.my-navbar.fixed-top,
body.admin-daily-sales-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Page heading */
body.admin-daily-sales-page h1,
body.admin-daily-sales-page h2,
body.admin-daily-sales-page h3,
body.admin-daily-sales-page h4,
body.admin-daily-sales-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

/* Header row */
body.admin-daily-sales-page .d-flex.justify-content-between.align-items-center.mb-3{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.48)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:18px !important;
    padding:18px 20px !important;
    margin-bottom:18px !important;
    box-shadow:
        0 16px 40px rgba(0,0,0,.24),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
}

/* Filter card/form */
body.admin-daily-sales-page form,
body.admin-daily-sales-page .filter-card,
body.admin-daily-sales-page .filter-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    padding:16px !important;
    margin-bottom:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
}

/* Inputs/selects */
body.admin-daily-sales-page .form-control,
body.admin-daily-sales-page .form-select,
body.admin-daily-sales-page input,
body.admin-daily-sales-page select{
    min-height:44px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.22) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    font-weight:800 !important;
}

body.admin-daily-sales-page .form-control:focus,
body.admin-daily-sales-page .form-select:focus,
body.admin-daily-sales-page input:focus,
body.admin-daily-sales-page select:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.16) !important;
}

body.admin-daily-sales-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

body.admin-daily-sales-page label,
body.admin-daily-sales-page .form-label{
    color:#e5e7eb !important;
    font-weight:900 !important;
}

/* Buttons */
body.admin-daily-sales-page .btn-primary,
body.admin-daily-sales-page .btn-success,
body.admin-daily-sales-page .btn-warning,
body.admin-daily-sales-page button[type="submit"]{
    min-height:44px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.18) !important;
    transition:.22s ease !important;
}

body.admin-daily-sales-page .btn-secondary,
body.admin-daily-sales-page .btn-light{
    min-height:42px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-daily-sales-page .btn:hover,
body.admin-daily-sales-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Table wrapper */
body.admin-daily-sales-page .table-responsive,
body.admin-daily-sales-page .daily-sales-table-wrap{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(12,18,35,.88), rgba(20,12,40,.78)) !important;
    border:1px solid rgba(139,92,246,.22) !important;
    border-radius:18px !important;
    overflow:auto !important;
    box-shadow:
        0 18px 45px rgba(0,0,0,.28),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(16px) saturate(140%) !important;
}

/* Table */
body.admin-daily-sales-page table.table{
    width:100% !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    overflow:hidden !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.06) !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

/* Bootstrap white background remove */
body.admin-daily-sales-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* Header */
body.admin-daily-sales-page table.table thead th,
body.admin-daily-sales-page table.table thead.table-dark th{
    background:rgba(6,10,20,.96) !important;
    color:#ffffff !important;
    border:none !important;
    border-bottom:1px solid rgba(139,92,246,.28) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

/* Body cells */
body.admin-daily-sales-page table.table tbody td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.52), rgba(30,20,58,.28)) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:16px 18px !important;
    font-size:14px !important;
    font-weight:800 !important;
    vertical-align:middle !important;
    white-space:nowrap !important;
}

body.admin-daily-sales-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* White hover remove */
body.admin-daily-sales-page table.table-hover tbody tr:hover td,
body.admin-daily-sales-page table.table tbody tr:hover td,
body.admin-daily-sales-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Amount column readable */
body.admin-daily-sales-page table.table tbody td:last-child,
body.admin-daily-sales-page .amount-cell,
body.admin-daily-sales-page .total-amount{
    color:#4ade80 !important;
    font-weight:950 !important;
    letter-spacing:.01em !important;
}

/* Empty row */
body.admin-daily-sales-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* Alerts */
body.admin-daily-sales-page .alert{
    border-radius:14px !important;
    font-weight:850 !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

body.admin-daily-sales-page .alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.28) !important;
}

body.admin-daily-sales-page .alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.28) !important;
}

/* Scrollbar */
body.admin-daily-sales-page .table-responsive::-webkit-scrollbar,
body.admin-daily-sales-page .daily-sales-table-wrap::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-daily-sales-page .table-responsive::-webkit-scrollbar-track,
body.admin-daily-sales-page .daily-sales-table-wrap::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-daily-sales-page .table-responsive::-webkit-scrollbar-thumb,
body.admin-daily-sales-page .daily-sales-table-wrap::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

@media(max-width:768px){
    body.admin-daily-sales-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-daily-sales-page .d-flex.justify-content-between.align-items-center.mb-3{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    body.admin-daily-sales-page table.table{
        min-width:850px !important;
    }
}
    </style>
</head>

<body class="admin-dashboard-page admin-daily-sales-page">
   <div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>Daily Sales Reports</h4>
                </div>
                <form method="post" class="row g-3 mb-3">
                    <?php if($logged_role_id == 1): ?>
                    <div class="col-md-3">
                        <label>Client</label>
                        <select name="client_id" id="clientSelect" class="form-control">
                            <option value="">All Clients</option>
                            <?php foreach($clients as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($filter_client==$c['id'])?'selected':'' ?>>
                                <?= htmlspecialchars($c['business_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-3">
                        <label>Branch</label>
                        <select name="branch_id" id="branchSelect" class="form-control">
                            <option value="">All Branches</option>
                            <?php foreach($branches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($filter_branch==$b['id'])?'selected':'' ?>>
                                <?= htmlspecialchars($b['branch_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control" value="<?= $filter_from ?>">
                    </div>

                    <div class="col-md-2">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control" value="<?= $filter_to ?>">
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>

                <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>

                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Invoices</th>
                            <th>Total Amount</th>

                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($daily_sales) > 0): ?>
                        <?php foreach($daily_sales as $s): ?>
                        <tr>
                            <td><?= $s['sale_date'] ?></td>
                            <td>
                                <?php
                        $branch_name = '';
                        foreach($branches as $b){
                            if($b['id']==$s['branch_id']){
                                $branch_name=$b['branch_name'];
                                break;
                            }
                        }
                        echo htmlspecialchars($branch_name);
                        ?>
                            </td>
                            <td><?= $s['invoice_count'] ?></td>
                            <td><?= number_format($s['total_amount'],2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center">No sales found</td>
                        </tr>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>
    </div>

   

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if($logged_role_id == 1): ?>
<script>
document.getElementById('clientSelect').addEventListener('change', function(){
    let clientId = this.value;
    let branchSelect = document.getElementById('branchSelect');
    branchSelect.innerHTML = '<option>Loading...</option>';

    fetch('fetch_branches.php?client_id='+clientId)
        .then(res=>res.json())
        .then(data=>{
            branchSelect.innerHTML = '<option value="">All Branches</option>';
            data.forEach(b=>{
                let opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = b.branch_name;
                branchSelect.appendChild(opt);
            });
        });
});
</script>
<?php endif; ?>

</body>

</html>