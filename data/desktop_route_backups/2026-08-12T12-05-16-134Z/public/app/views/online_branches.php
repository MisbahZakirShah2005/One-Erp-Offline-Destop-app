<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = $_SESSION['user_id'];
$logged_role_id   = $_SESSION['role_id'];
$logged_client_id = $_SESSION['client_id'] ?? 0;

/* ================= FETCH BRANCHES + STATUS ================= */
$branches = [];

if($logged_role_id == 1){ // SUPER ADMIN
    $sql = "
        SELECT 
            b.id AS branch_id,
            b.name AS branch_name,
            b.address,
            c.business_name,
            bs.last_activity,
            CASE 
                WHEN bs.last_activity >= NOW() - INTERVAL 5 MINUTE THEN 'Online'
                ELSE 'Offline'
            END AS status
        FROM branches b
        LEFT JOIN clients c ON c.id = b.client_id
        LEFT JOIN branch_sessions bs ON bs.branch_id = b.id
        ORDER BY c.business_name, b.name
    ";
    $res = $conn->query($sql);
} else { // CLIENT ADMIN
    $stmt = $conn->prepare("
        SELECT 
            b.id AS branch_id,
            b.name AS branch_name,
            b.address,
            bs.last_activity,
            CASE 
                WHEN bs.last_activity >= NOW() - INTERVAL 5 MINUTE THEN 'Online'
                ELSE 'Offline'
            END AS status
        FROM branches b
        LEFT JOIN branch_sessions bs ON bs.branch_id = b.id
        WHERE b.client_id = ?
        ORDER BY b.name
    ");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
}

if($res){
    while($row = $res->fetch_assoc()){
        $branches[] = $row;
    }
}

/* ================= HTML OUTPUT ================= */
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Online Branches</title>
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
   ADMIN ONLINE BRANCHES PAGE FIX
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
   ONLINE_BRANCHES.PHP - DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html,
body.admin-online-branches-page{
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

/* Main layout */
body.admin-online-branches-page .container-scroll,
body.admin-online-branches-page .main-panel,
body.admin-online-branches-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.08), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-online-branches-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
}

body.admin-online-branches-page .content-wrapper::before{
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

body.admin-online-branches-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-online-branches-page .navbar.my-navbar.fixed-top,
body.admin-online-branches-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Heading */
body.admin-online-branches-page h1,
body.admin-online-branches-page h2,
body.admin-online-branches-page h3,
body.admin-online-branches-page h4,
body.admin-online-branches-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

/* Top header row */
body.admin-online-branches-page .d-flex.justify-content-between.align-items-center.mb-3{
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

/* Alerts */
body.admin-online-branches-page .alert{
    border-radius:14px !important;
    font-weight:850 !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

body.admin-online-branches-page .alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.28) !important;
}

body.admin-online-branches-page .alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.28) !important;
}

/* Table wrapper */
body.admin-online-branches-page .table-responsive{
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

/* Direct table fix */
body.admin-online-branches-page table.table{
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

/* Bootstrap white background remove */
body.admin-online-branches-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* Table header */
body.admin-online-branches-page table.table thead th,
body.admin-online-branches-page table.table thead.table-dark th{
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
body.admin-online-branches-page table.table tbody td{
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

body.admin-online-branches-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* White hover remove */
body.admin-online-branches-page table.table-hover tbody tr:hover td,
body.admin-online-branches-page table.table tbody tr:hover td,
body.admin-online-branches-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Empty row */
body.admin-online-branches-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* Branch/client names */
body.admin-online-branches-page .branch-name,
body.admin-online-branches-page .client-name,
body.admin-online-branches-page .business-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* Online / Offline badges */
body.admin-online-branches-page .badge,
body.admin-online-branches-page .status-badge{
    border-radius:999px !important;
    padding:7px 14px !important;
    font-size:12px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    gap:6px !important;
}

body.admin-online-branches-page .badge.bg-success,
body.admin-online-branches-page .bg-success,
body.admin-online-branches-page .online,
body.admin-online-branches-page .status-online{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.30) !important;
    box-shadow:0 0 22px rgba(34,197,94,.10) !important;
}

body.admin-online-branches-page .badge.bg-danger,
body.admin-online-branches-page .bg-danger,
body.admin-online-branches-page .offline,
body.admin-online-branches-page .status-offline{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.30) !important;
    box-shadow:0 0 22px rgba(239,68,68,.10) !important;
}

body.admin-online-branches-page .badge.bg-warning,
body.admin-online-branches-page .bg-warning{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.28) !important;
}

/* Last activity text */
body.admin-online-branches-page .last-activity,
body.admin-online-branches-page .date-text,
body.admin-online-branches-page small,
body.admin-online-branches-page .text-muted{
    color:#94a3b8 !important;
    font-weight:750 !important;
}

/* Action buttons if available */
body.admin-online-branches-page .btn-primary,
body.admin-online-branches-page .btn-success,
body.admin-online-branches-page button[type="submit"]{
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

body.admin-online-branches-page .btn-warning{
    min-height:42px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
}

body.admin-online-branches-page .btn-danger{
    min-height:42px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-online-branches-page .btn-secondary,
body.admin-online-branches-page .btn-light,
body.admin-online-branches-page .btn-dark{
    min-height:42px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-online-branches-page .btn:hover,
body.admin-online-branches-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Modal if any */
body.admin-online-branches-page .modal{
    z-index:5000 !important;
}

body.admin-online-branches-page .modal-backdrop{
    z-index:4990 !important;
}

body.admin-online-branches-page .modal-backdrop.show{
    opacity:.80 !important;
    background:#000 !important;
    backdrop-filter:blur(8px) !important;
    -webkit-backdrop-filter:blur(8px) !important;
}

body.admin-online-branches-page .modal-dialog{
    max-width:560px !important;
    width:calc(100% - 34px) !important;
    margin:24px auto !important;
}

body.admin-online-branches-page .modal-content{
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

body.admin-online-branches-page .modal-header{
    padding:15px 20px !important;
    background:
        linear-gradient(135deg, rgba(30,20,58,.78), rgba(7,11,20,.55)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

body.admin-online-branches-page .modal-title,
body.admin-online-branches-page .modal-header h5{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:950 !important;
    text-shadow:0 0 24px rgba(124,58,237,.35) !important;
}

body.admin-online-branches-page .modal-body{
    padding:18px 20px !important;
    background:rgba(15,23,42,.12) !important;
}

body.admin-online-branches-page .modal-footer{
    padding:14px 20px !important;
    background:
        linear-gradient(180deg, rgba(7,11,20,.88), rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

body.admin-online-branches-page .btn-close{
    width:40px !important;
    height:40px !important;
    border-radius:13px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

/* Scrollbar */
body.admin-online-branches-page .table-responsive::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-online-branches-page .table-responsive::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-online-branches-page .table-responsive::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

@media(max-width:768px){
    body.admin-online-branches-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-online-branches-page .d-flex.justify-content-between.align-items-center.mb-3{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    body.admin-online-branches-page table.table{
        min-width:920px !important;
    }

    body.admin-online-branches-page .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:10px auto !important;
    }
}
    </style>
</head>

<body class="admin-dashboard-page admin-online-branches-page">
<div class="container-scroll">

    <!-- ================= SIDEBAR ================= -->
    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <!-- ================= MAIN PANEL ================= -->
    <div class="main-panel">

        <!-- ================= TOPBAR ================= -->
        <?php include('partials/topbar.php'); ?>

        <!-- ================= CONTENT ================= -->
        <div class="content-wrapper">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>Online / Offline Branches</h4>
                    <!-- <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#industryModal" id="addBtn">
                        + Add Industry
                    </button> -->
                </div>
                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Branch Name</th>
                            <th>Address</th>
                            <?php if($logged_role_id == 1): ?><th>Client</th><?php endif; ?>
                            <th>Last Activity</th>
                            <th>Status</th>

                        </tr>
                    </thead>
                    <tbody>
                         <?php if(count($branches) > 0): ?>
                <?php foreach($branches as $b): ?>
                <tr>
                    <td><?= htmlspecialchars($b['branch_name']) ?></td>
                    <td><?= htmlspecialchars($b['address']) ?></td>
                    <?php if($logged_role_id == 1): ?>
                    <td><?= htmlspecialchars($b['business_name'] ?? '-') ?></td>
                    <?php endif; ?>
                    <td><?= $b['last_activity'] ?? '-' ?></td>
                    <td>
                        <?php if($b['status']=='Online'): ?>
                            <span class="badge bg-success">Online</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Offline</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?= ($logged_role_id==1)?5:4 ?>" class="text-center">No branches found</td>
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