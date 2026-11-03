<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include('partials/performance.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
/* ================== AUTH CHECK ================== */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_role_id = $_SESSION['role_id'];

/* ================== ONLY SUPER ADMIN ================== */
if($logged_role_id != 1){
    die("Access denied!");
}

/* ================== AJAX EDIT CLIENT ================== */
if(isset($_POST['ajax']) && $_POST['ajax'] === 'edit_client'){
    $stmt = $conn->prepare("UPDATE clients SET business_name=?, status=? WHERE id=?");
    $stmt->bind_param("ssi", $_POST['business_name'], $_POST['status'], $_POST['client_id']);
    $stmt->execute();
    echo "ok";
    exit;
}

/* ================== AJAX DELETE CLIENT ================== */
if(isset($_POST['ajax']) && $_POST['ajax'] === 'delete_client'){
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM memberships WHERE client_id=$id");
    $conn->query("DELETE FROM clients WHERE id=$id");
    echo "deleted";
    exit;
}

/* ================== FETCH TRIAL CLIENTS ================== */
$clients = [];
$stmt = $conn->prepare("
    SELECT 
        c.id,
        c.business_name,
        c.status,
        i.name AS industry_name,
        MAX(s.end_date) AS last_payment_date
    FROM clients c
    LEFT JOIN industries i ON i.id = c.industry_id
    LEFT JOIN subscriptions s ON s.client_id = c.id
    WHERE c.status = 'Trial'
    GROUP BY c.id
    ORDER BY c.id DESC
");
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()){
    $clients[] = $r;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Trial Clients</title>
<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
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
   ADMIN TRIAL CLIENTS PAGE FIX
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

body.admin-dashboard-page .modal {
    z-index: 5000 !important;
}

body.admin-dashboard-page .modal-backdrop {
    z-index: 4990 !important;
}

body.admin-dashboard-page .footer,
body.admin-dashboard-page footer {
    margin: 0 !important;
    padding: 14px 24px !important;
    background: #f4f7fb !important;
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

    body.admin-dashboard-page .footer,
    body.admin-dashboard-page footer {
        padding: 12px 16px !important;
    }
}

/* =====================================================
   TRIAL.PHP - DARK PURPLE GLASS THEME
   Paste at END before
===================================================== */

html,
body.admin-trial-clients-page{
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

body.admin-trial-clients-page .container-scroll,
body.admin-trial-clients-page .main-panel,
body.admin-trial-clients-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-trial-clients-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
}

body.admin-trial-clients-page .content-wrapper::before{
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

body.admin-trial-clients-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-trial-clients-page .navbar.my-navbar.fixed-top,
body.admin-trial-clients-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Heading */
body.admin-trial-clients-page h1,
body.admin-trial-clients-page h2,
body.admin-trial-clients-page h3,
body.admin-trial-clients-page h4,
body.admin-trial-clients-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

body.admin-trial-clients-page .text-success{
    color:#ffffff !important;
}

/* Table wrapper */
body.admin-trial-clients-page .table-responsive{
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
    -webkit-backdrop-filter:blur(16px) saturate(140%) !important;
}

/* Agar table wrapper nahi hai to direct table bhi glass ho */
body.admin-trial-clients-page table.table{
    width:100% !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    overflow:hidden !important;
    border-radius:18px !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(12,18,35,.88), rgba(20,12,40,.78)) !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.06) !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

body.admin-trial-clients-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* Table header */
body.admin-trial-clients-page table.table thead th,
body.admin-trial-clients-page table.table thead.table-dark th{
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

/* Table body */
body.admin-trial-clients-page table.table tbody td{
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

body.admin-trial-clients-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* White hover remove */
body.admin-trial-clients-page table.table-hover tbody tr:hover td,
body.admin-trial-clients-page table.table tbody tr:hover td,
body.admin-trial-clients-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Empty row */
body.admin-trial-clients-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* Trial badge */
body.admin-trial-clients-page .badge.bg-info,
body.admin-trial-clients-page .badge-info,
body.admin-trial-clients-page .status-trial{
    background:rgba(59,130,246,.16) !important;
    color:#93c5fd !important;
    border:1px solid rgba(59,130,246,.28) !important;
    border-radius:999px !important;
    padding:7px 14px !important;
    font-weight:900 !important;
}

/* Active / expired badges */
body.admin-trial-clients-page .badge.bg-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

body.admin-trial-clients-page .badge.bg-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.28) !important;
}

/* Action icons */
body.admin-trial-clients-page .editClient,
body.admin-trial-clients-page .deleteClient{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    transition:.22s ease !important;
    margin:0 3px !important;
    box-shadow:0 10px 20px rgba(0,0,0,.20) !important;
}

body.admin-trial-clients-page .editClient{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.26) !important;
}

body.admin-trial-clients-page .deleteClient{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

body.admin-trial-clients-page .editClient:hover,
body.admin-trial-clients-page .deleteClient:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Modal dark glass */
body.admin-trial-clients-page .modal{
    z-index:5000 !important;
}

body.admin-trial-clients-page .modal-backdrop{
    z-index:4990 !important;
}

body.admin-trial-clients-page .modal-backdrop.show{
    opacity:.80 !important;
    background:#000 !important;
    backdrop-filter:blur(8px) !important;
    -webkit-backdrop-filter:blur(8px) !important;
}

body.admin-trial-clients-page .modal-dialog{
    max-width:620px !important;
    width:calc(100% - 34px) !important;
    margin:24px auto !important;
}

body.admin-trial-clients-page .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(59,130,246,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.86)) !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:20px !important;
    color:#f8fafc !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.68),
        0 0 55px rgba(124,58,237,.22),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    overflow:hidden !important;
}

body.admin-trial-clients-page .modal-header{
    padding:15px 20px !important;
    background:
        linear-gradient(135deg, rgba(30,20,58,.78), rgba(7,11,20,.55)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

body.admin-trial-clients-page .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:950 !important;
    text-shadow:0 0 24px rgba(124,58,237,.35) !important;
}

body.admin-trial-clients-page .modal-body{
    padding:18px 20px !important;
    background:rgba(15,23,42,.12) !important;
}

body.admin-trial-clients-page .modal-footer{
    padding:14px 20px !important;
    background:
        linear-gradient(180deg, rgba(7,11,20,.88), rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

body.admin-trial-clients-page .btn-close{
    width:40px !important;
    height:40px !important;
    border-radius:13px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

/* Modal fields */
body.admin-trial-clients-page .modal label{
    color:#e5e7eb !important;
    font-weight:900 !important;
}

body.admin-trial-clients-page .modal .form-control,
body.admin-trial-clients-page .modal .form-select,
body.admin-trial-clients-page .modal input,
body.admin-trial-clients-page .modal select{
    min-height:44px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.22) !important;
    font-weight:800 !important;
}

body.admin-trial-clients-page .modal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Buttons */
body.admin-trial-clients-page .btn-primary,
body.admin-trial-clients-page button[type="submit"]{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border:none !important;
    border-radius:13px !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-trial-clients-page .btn-secondary{
    background:rgba(15,23,42,.86) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:13px !important;
    font-weight:900 !important;
}

/* Mobile */
@media(max-width:768px){
    body.admin-trial-clients-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-trial-clients-page table.table{
        min-width:900px !important;
    }

    body.admin-trial-clients-page .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:10px auto !important;
    }
}
</style>
</head>

<body class="admin-dashboard-page admin-trial-clients-page">
<div class="container-scroll">

    <!-- SIDEBAR -->
    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <!-- MAIN PANEL -->
    <div class="main-panel">

        <!-- TOPBAR -->
        <?php include('partials/topbar.php'); ?>

        <!-- CONTENT -->
        <div class="content-wrapper">
            <h4 class="mb-3 text-success">Trial Clients</h4>

            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Business</th>
                        <th>Industry</th>
                        <th>Status</th>
                        <th>Last Payment</th>
                        <th width="100">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($clients)): ?>
                    <tr>
                        <td colspan="6" class="text-center">No trial clients found</td>
                    </tr>
                <?php endif; ?>

                <?php foreach($clients as $c): ?>
                    <tr>
                        <td><?= $c['id'] ?></td>
                        <td><?= htmlspecialchars($c['business_name']) ?></td>
                        <td><?= htmlspecialchars($c['industry_name']) ?></td>
                        <td>
                            <span class="badge bg-info">Trial</span>
                        </td>
                        <td><?= $c['last_payment_date'] ? date("d M Y", strtotime($c['last_payment_date'])) : 'N/A' ?></td>
                        <td class="text-center">
                            <!-- Edit Icon -->
                            <i class="bi bi-pencil-square text-warning me-2 editClient" style="cursor:pointer"
                               data-id="<?= $c['id'] ?>"
                               data-name="<?= htmlspecialchars($c['business_name'], ENT_QUOTES) ?>"
                               data-status="<?= $c['status'] ?>"
                               data-bs-toggle="modal" data-bs-target="#editClientModal"></i>

                            <!-- Delete Icon -->
                            <i class="bi bi-trash text-danger deleteClient" style="cursor:pointer"
                               data-id="<?= $c['id'] ?>"></i>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        </div>

        <?php include('partials/footer.php'); ?>

    </div>
</div>

<!-- EDIT CLIENT MODAL -->
<div class="modal fade" id="editClientModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editClientForm">
                <input type="hidden" name="client_id" id="edit_client_id">
                <div class="modal-header">
                    <h5>Edit Client</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label>Business Name</label>
                        <input type="text" name="business_name" id="edit_business_name" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="Trial">Trial</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
// EDIT CLIENT
document.querySelectorAll('.editClient').forEach(icon=>{
    icon.addEventListener('click', ()=>{
        document.getElementById('edit_client_id').value = icon.dataset.id;
        document.getElementById('edit_business_name').value = icon.dataset.name;
        document.getElementById('edit_status').value = icon.dataset.status;
    });
});

// DELETE CLIENT
document.querySelectorAll('.deleteClient').forEach(icon=>{
    icon.addEventListener('click', ()=>{
        if(!confirm("Delete this client?")) return;
        fetch("",{
            method:"POST",
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`ajax=delete_client&id=${icon.dataset.id}`
        }).then(()=>location.reload());
    });
});

// SUBMIT EDIT FORM
document.getElementById('editClientForm').addEventListener('submit', e=>{
    e.preventDefault();
    fetch("",{
        method:"POST",
        body: new URLSearchParams(new FormData(e.target)) + "&ajax=edit_client"
    }).then(()=>location.reload());
});
</script>

</body>
</html>
