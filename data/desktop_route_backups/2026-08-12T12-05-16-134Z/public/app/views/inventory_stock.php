<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

// ================= AUTH CHECK =================
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = $_SESSION['user_id'];
$logged_role_id   = $_SESSION['role_id'];
$logged_client_id = $_SESSION['client_id'];

// ================= FETCH CLIENTS (SUPER ADMIN) =================
$clients = [];
if($logged_role_id == 1){
    $res = $conn->query("SELECT id, business_name FROM clients ORDER BY business_name ASC");
    while($row = $res->fetch_assoc()){
        $clients[] = $row;
    }
}

// ================= FETCH BRANCHES =================
$branches = [];
$selected_client = $_GET['client_id'] ?? '';
if($selected_client){
    $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id=? ORDER BY name ASC");
    $stmt->bind_param("i", $selected_client);
    $stmt->execute();
    $res = $stmt->get_result();
    while($b = $res->fetch_assoc()){
        $branches[] = $b;
    }
}

// ================= FETCH ITEMS =================
$items = [];
$item_where = "";
$selected_branch = $_GET['branch_id'] ?? '';
$selected_client_for_items = ($logged_role_id == 1) ? $selected_client : $logged_client_id;

if($selected_client_for_items){
    $item_where = " WHERE client_id = ?";
    $stmt = $conn->prepare("SELECT * FROM items ".$item_where." ORDER BY name ASC");
    $stmt->bind_param("i", $selected_client_for_items);
    $stmt->execute();
    $res = $stmt->get_result();
    while($i = $res->fetch_assoc()){
        $items[] = $i;
    }
}

// ================= FETCH STOCK DATA =================
$stock_data = [];
if($selected_branch){
    $sql = "
        SELECT 
            i.id AS item_id,
            i.name AS item_name,
            i.sku,
            i.barcode,
            i.type,
            COALESCE(iv.qty,0) AS branch_qty,
            iv.size,
            iv.color,
            ib.batch_no,
            ib.expiry_date
        FROM items i
        LEFT JOIN items_variant iv 
            ON i.id = iv.item_id AND iv.branch_id=?
        LEFT JOIN item_batches ib 
            ON i.id = ib.item_id
        WHERE i.client_id = ?
        ORDER BY i.name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $selected_branch, $selected_client_for_items);
    $stmt->execute();
    $res = $stmt->get_result();
    while($s = $res->fetch_assoc()){
        $stock_data[] = $s;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Inventory Stock</title>
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
   ADMIN INVENTORY STOCK PAGE FIX
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
}

/* =====================================================
   INVENTORY_STOCK.PHP - DARK PURPLE GLASS THEME
   Paste at END before
===================================================== */

html,
body.admin-inventory-stock-page{
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

body.admin-inventory-stock-page .container-scroll,
body.admin-inventory-stock-page .main-panel,
body.admin-inventory-stock-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-inventory-stock-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
    overflow:visible !important;
}

body.admin-inventory-stock-page .content-wrapper::before{
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

body.admin-inventory-stock-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-inventory-stock-page .navbar.my-navbar.fixed-top,
body.admin-inventory-stock-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Heading */
body.admin-inventory-stock-page h1,
body.admin-inventory-stock-page h2,
body.admin-inventory-stock-page h3,
body.admin-inventory-stock-page h4,
body.admin-inventory-stock-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

/* Header row */
body.admin-inventory-stock-page .d-flex.justify-content-between.mb-3,
body.admin-inventory-stock-page .d-flex.justify-content-between.align-items-center.mb-3{
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

/* Filter form */
body.admin-inventory-stock-page form.row,
body.admin-inventory-stock-page .filter-card,
body.admin-inventory-stock-page .filter-bar{
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

/* Inputs */
body.admin-inventory-stock-page .form-control,
body.admin-inventory-stock-page .form-select,
body.admin-inventory-stock-page input,
body.admin-inventory-stock-page select{
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

body.admin-inventory-stock-page .form-control:focus,
body.admin-inventory-stock-page .form-select:focus,
body.admin-inventory-stock-page input:focus,
body.admin-inventory-stock-page select:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.16) !important;
}

body.admin-inventory-stock-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Table wrapper */
body.admin-inventory-stock-page .table-responsive,
body.admin-inventory-stock-page .inventory-stock-table-wrap{
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
body.admin-inventory-stock-page table.table{
    width:100% !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.06) !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

/* Bootstrap white remove */
body.admin-inventory-stock-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* Header */
body.admin-inventory-stock-page table.table thead th,
body.admin-inventory-stock-page table.table thead.table-dark th{
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
body.admin-inventory-stock-page table.table tbody td{
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

body.admin-inventory-stock-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* Hover white remove */
body.admin-inventory-stock-page table.table-hover tbody tr:hover td,
body.admin-inventory-stock-page table.table tbody tr:hover td,
body.admin-inventory-stock-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Stock quantity highlight */
body.admin-inventory-stock-page .stock-qty,
body.admin-inventory-stock-page .qty-cell,
body.admin-inventory-stock-page table.table tbody td:nth-child(6){
    color:#4ade80 !important;
    font-weight:950 !important;
}

body.admin-inventory-stock-page .low-stock,
body.admin-inventory-stock-page .danger-stock{
    color:#f87171 !important;
    font-weight:950 !important;
}

/* Empty row */
body.admin-inventory-stock-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* Badges */
body.admin-inventory-stock-page .badge,
body.admin-inventory-stock-page .status-badge{
    border-radius:999px !important;
    padding:7px 14px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

body.admin-inventory-stock-page .bg-success,
body.admin-inventory-stock-page .badge-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

body.admin-inventory-stock-page .bg-warning,
body.admin-inventory-stock-page .badge-warning{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.28) !important;
}

body.admin-inventory-stock-page .bg-danger,
body.admin-inventory-stock-page .badge-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.28) !important;
}

/* Buttons */
body.admin-inventory-stock-page .btn-primary,
body.admin-inventory-stock-page .btn-success,
body.admin-inventory-stock-page .btn-warning,
body.admin-inventory-stock-page button[type="submit"]{
    min-height:42px !important;
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

body.admin-inventory-stock-page .btn-secondary,
body.admin-inventory-stock-page .btn-light{
    min-height:42px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-inventory-stock-page .btn:hover,
body.admin-inventory-stock-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Scrollbar */
body.admin-inventory-stock-page .table-responsive::-webkit-scrollbar,
body.admin-inventory-stock-page .inventory-stock-table-wrap::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-inventory-stock-page .table-responsive::-webkit-scrollbar-track,
body.admin-inventory-stock-page .inventory-stock-table-wrap::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-inventory-stock-page .table-responsive::-webkit-scrollbar-thumb,
body.admin-inventory-stock-page .inventory-stock-table-wrap::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

@media(max-width:768px){
    body.admin-inventory-stock-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-inventory-stock-page .d-flex.justify-content-between.mb-3{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    body.admin-inventory-stock-page table.table{
        min-width:1000px !important;
    }
}
</style>
</head>
<body class="admin-dashboard-page admin-inventory-stock-page">
<div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">
        <h4 class="mb-4">Inventory Stock</h4>

        <!-- FILTERS -->
        <form method="get" class="row g-3 mb-3">
            <?php if($logged_role_id == 1): ?>
            <div class="col-md-3">
                <label>Client</label>
                <select name="client_id" class="form-control" onchange="this.form.submit()">
                    <option value="">Select Client</option>
                    <?php foreach($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($selected_client==$c['id'])?'selected':'' ?>>
                            <?= htmlspecialchars($c['business_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-3">
                <label>Branch</label>
                <select name="branch_id" class="form-control" onchange="this.form.submit()">
                    <option value="">Select Branch</option>
                    <?php foreach($branches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= ($selected_branch==$b['id'])?'selected':'' ?>>
                            <?= htmlspecialchars($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <!-- STOCK TABLE -->
        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Item Name</th>
                    <th>SKU</th>
                    <th>Barcode</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Size</th>
                    <th>Color</th>
                    <th>Batch No</th>
                    <th>Expiry</th>
                </tr>
            </thead>
            <tbody>
            <?php if(count($stock_data) > 0): ?>
                <?php foreach($stock_data as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['item_name']) ?></td>
                    <td><?= htmlspecialchars($s['sku']) ?></td>
                    <td><?= htmlspecialchars($s['barcode']) ?></td>
                    <td><?= htmlspecialchars($s['type']) ?></td>
                    <td><?= $s['branch_qty'] ?></td>
                    <td><?= htmlspecialchars($s['size'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($s['color'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($s['batch_no'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($s['expiry_date'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="text-center">No stock found</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
