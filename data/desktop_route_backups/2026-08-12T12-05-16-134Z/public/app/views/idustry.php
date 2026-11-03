<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================== AUTH CHECK ================== */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
/* ================= ADD / EDIT / DELETE ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST'){

    // ADD INDUSTRY
    if(isset($_POST['add_industry']) && $_POST['add_industry'] == 1){
        $name = trim($_POST['name']);

        $check = $conn->prepare("SELECT id FROM industries WHERE name=?");
        $check->bind_param("s", $name);
        $check->execute();
        $check->store_result();

        if($check->num_rows > 0){
            $_SESSION['error'] = "Industry already exists!";
        } else {
            $stmt = $conn->prepare("INSERT INTO industries (name, created_at) VALUES (?, NOW())");
            $stmt->bind_param("s", $name);
            if($stmt->execute()){
                $_SESSION['success'] = "Industry added successfully!";
            } else {
                $_SESSION['error'] = $stmt->error;
            }
            $stmt->close();
        }
        $check->close();
        header("Location: industry.php");
        exit;
    }

    // EDIT INDUSTRY
    if(isset($_POST['edit_industry']) && $_POST['edit_industry'] == 1){
        $id   = (int)$_POST['id'];
        $name = trim($_POST['name']);

        // Check if name exists for another ID
        $check = $conn->prepare("SELECT id FROM industries WHERE name=? AND id!=?");
        $check->bind_param("si", $name, $id);
        $check->execute();
        $check->store_result();

        if($check->num_rows > 0){
            $_SESSION['error'] = "Another industry with this name already exists!";
        } else {
            $stmt = $conn->prepare("UPDATE industries SET name=? WHERE id=?");
            $stmt->bind_param("si", $name, $id);
            if($stmt->execute()){
                $_SESSION['success'] = "Industry updated successfully!";
            } else {
                $_SESSION['error'] = $stmt->error;
            }
            $stmt->close();
        }
        $check->close();
        header("Location: industry.php");
        exit;
    }

    // DELETE INDUSTRY
    if(isset($_POST['delete_industry']) && $_POST['delete_industry'] == 1){
        $id = (int)$_POST['id'];

        $stmt = $conn->prepare("DELETE FROM industries WHERE id=?");
        $stmt->bind_param("i", $id);
        if($stmt->execute()){
            $_SESSION['success'] = "Industry deleted!";
        } else {
            $_SESSION['error'] = $stmt->error;
        }
        $stmt->close();
        header("Location: idustry.php");
        exit;
    }
}

/* ================= FETCH INDUSTRIES ================= */
$industry_res = $conn->query("SELECT id, name FROM industries ORDER BY name ASC");
if(!$industry_res){
    die($conn->error);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Industries</title>
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
   ADMIN INDUSTRY PAGE FIX
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

body.admin-dashboard-page .modal-header {
    background: linear-gradient(135deg, #000, #e4ac00);
    color: #fff;
}

body.admin-dashboard-page .modal {
    z-index: 5000 !important;
}

body.admin-dashboard-page .modal-backdrop {
    z-index: 4990 !important;
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

    body.admin-dashboard-page .d-flex.justify-content-between.align-items-center.mb-3 {
        gap: 12px;
        flex-wrap: wrap;
    }
}
/* =====================================================
   INDUSTRY.PHP - DARK PURPLE GLASS THEME FINAL
   Paste at END before
===================================================== */

html,
body.admin-industry-page{
    margin:0 !important;
    padding:0 !important;
    width:100% !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.32), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-industry-page .container-scroll,
body.admin-industry-page .main-panel,
body.admin-industry-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-industry-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
}

body.admin-industry-page .content-wrapper::before{
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

body.admin-industry-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-industry-page .navbar.my-navbar.fixed-top,
body.admin-industry-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Heading */
body.admin-industry-page h1,
body.admin-industry-page h2,
body.admin-industry-page h3,
body.admin-industry-page h4,
body.admin-industry-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

/* Add button / action buttons */
body.admin-industry-page .btn-primary,
body.admin-industry-page .btn-success,
body.admin-industry-page button[type="submit"]{
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

body.admin-industry-page .btn-danger{
    min-height:42px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-industry-page .btn-secondary,
body.admin-industry-page .btn-light{
    min-height:42px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-industry-page .btn:hover,
body.admin-industry-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Alerts */
body.admin-industry-page .alert{
    border-radius:14px !important;
    font-weight:850 !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

body.admin-industry-page .alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.28) !important;
}

body.admin-industry-page .alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.28) !important;
}

/* Table wrapper */
body.admin-industry-page .table-responsive{
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

/* Table direct fix */
body.admin-industry-page table.table{
    width:100% !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    overflow:hidden !important;
    border-radius:18px !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.06) !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

/* Bootstrap white background remove */
body.admin-industry-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* Header */
body.admin-industry-page table.table thead th,
body.admin-industry-page table.table thead.table-dark th{
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
body.admin-industry-page table.table tbody td{
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

body.admin-industry-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* White hover remove */
body.admin-industry-page table.table-hover tbody tr:hover td,
body.admin-industry-page table.table tbody tr:hover td,
body.admin-industry-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Empty row */
body.admin-industry-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* Action buttons in table */
body.admin-industry-page .editIndustry,
body.admin-industry-page .deleteIndustry,
body.admin-industry-page .editBtn,
body.admin-industry-page .deleteBtn{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    transition:.22s ease !important;
    margin:0 3px !important;
    box-shadow:0 10px 20px rgba(0,0,0,.20) !important;
    border:none !important;
}

body.admin-industry-page .editIndustry,
body.admin-industry-page .editBtn{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.26) !important;
}

body.admin-industry-page .deleteIndustry,
body.admin-industry-page .deleteBtn{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

body.admin-industry-page .editIndustry:hover,
body.admin-industry-page .deleteIndustry:hover,
body.admin-industry-page .editBtn:hover,
body.admin-industry-page .deleteBtn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Modal */
body.admin-industry-page .modal{
    z-index:5000 !important;
}

body.admin-industry-page .modal-backdrop{
    z-index:4990 !important;
}

body.admin-industry-page .modal-backdrop.show{
    opacity:.80 !important;
    background:#000 !important;
    backdrop-filter:blur(8px) !important;
    -webkit-backdrop-filter:blur(8px) !important;
}

body.admin-industry-page .modal-dialog{
    max-width:540px !important;
    width:calc(100% - 34px) !important;
    margin:24px auto !important;
}

body.admin-industry-page .modal-content{
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

body.admin-industry-page .modal-header{
    padding:15px 20px !important;
    background:
        linear-gradient(135deg, rgba(30,20,58,.78), rgba(7,11,20,.55)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

body.admin-industry-page .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:950 !important;
    text-shadow:0 0 24px rgba(124,58,237,.35) !important;
}

body.admin-industry-page .modal-body{
    padding:18px 20px !important;
    background:rgba(15,23,42,.12) !important;
}

body.admin-industry-page .modal-footer{
    padding:14px 20px !important;
    background:
        linear-gradient(180deg, rgba(7,11,20,.88), rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

body.admin-industry-page .btn-close{
    width:40px !important;
    height:40px !important;
    border-radius:13px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

/* Modal fields */
body.admin-industry-page .modal label{
    color:#e5e7eb !important;
    font-weight:900 !important;
}

body.admin-industry-page .modal .form-control,
body.admin-industry-page .modal input{
    min-height:44px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.22) !important;
    font-weight:800 !important;
}

body.admin-industry-page .modal .form-control:focus,
body.admin-industry-page .modal input:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.16) !important;
}

/* Scrollbar */
body.admin-industry-page .table-responsive::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-industry-page .table-responsive::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-industry-page .table-responsive::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

@media(max-width:768px){
    body.admin-industry-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-industry-page table.table{
        min-width:720px !important;
    }

    body.admin-industry-page .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:10px auto !important;
    }
}

</style>
</head>
<body class="admin-dashboard-page admin-industry-page">

<div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>Industries</h4>
                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#industryModal" id="addBtn">+ Add Industry</button>
            </div>

            <?php
            if(isset($_SESSION['success'])){
                echo '<div class="alert alert-success">'.$_SESSION['success'].'</div>';
                unset($_SESSION['success']);
            }
            if(isset($_SESSION['error'])){
                echo '<div class="alert alert-danger">'.$_SESSION['error'].'</div>';
                unset($_SESSION['error']);
            }
            ?>

            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th width="80">ID</th>
                        <th>Industry Name</th>
                        <th width="150">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($i = $industry_res->fetch_assoc()): ?>
                    <tr>
                        <td><?= $i['id'] ?></td>
                        <td><?= htmlspecialchars($i['name']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning editBtn" data-id="<?= $i['id'] ?>" data-name="<?= htmlspecialchars($i['name']) ?>">Edit</button>
                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#delete<?= $i['id'] ?>">Delete</button>
                        </td>
                    </tr>

                    <!-- DELETE MODAL -->
                    <div class="modal fade" id="delete<?= $i['id'] ?>">
                        <div class="modal-dialog">
                            <form method="post" class="modal-content">
                                <input type="hidden" name="delete_industry" value="1">
                                <input type="hidden" name="id" value="<?= $i['id'] ?>">
                                <div class="modal-header">
                                    <h5>Delete Industry</h5>
                                </div>
                                <div class="modal-body">
                                    Are you sure you want to delete <b><?= htmlspecialchars($i['name']) ?></b>?
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php endwhile; ?>
                </tbody>
            </table>

        </div>
    </div>
</div>

<!-- ADD/EDIT MODAL -->
<div class="modal fade" id="industryModal">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="add_industry" id="addInput" value="1">
            <input type="hidden" name="edit_industry" id="editInput" value="">
            <input type="hidden" name="id" id="industryId">

            <div class="modal-header">
                <h5 id="modalTitle">Add Industry</h5>
            </div>

            <div class="modal-body">
                <input type="text" name="name" id="industryName" class="form-control" placeholder="Industry name" required>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning" id="modalBtn">Add</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const industryModal = document.getElementById('industryModal');

// ADD BUTTON
document.getElementById('addBtn').addEventListener('click', function() {
    document.getElementById('modalTitle').innerText = 'Add Industry';
    document.getElementById('modalBtn').innerText = 'Add';
    document.getElementById('industryName').value = '';
    document.getElementById('industryId').value = '';
    document.getElementById('addInput').value = 1;
    document.getElementById('editInput').value = '';
});

// EDIT BUTTONS
document.querySelectorAll('.editBtn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = 'Edit Industry';
        document.getElementById('modalBtn').innerText = 'Save';
        document.getElementById('industryName').value = this.dataset.name;
        document.getElementById('industryId').value = this.dataset.id;
        document.getElementById('addInput').value = '';
        document.getElementById('editInput').value = 1;
        new bootstrap.Modal(industryModal).show();
    });
});
</script>
</body>
</html>
