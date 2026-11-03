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

$fbr_status_list = [];

if($logged_role_id == 1){ // SUPER ADMIN
    $sql = "
        SELECT 
            fi.sale_id,
            fi.irn,
            fi.qr,
            fi.status AS invoice_status,
            fi.response,
            fi.created_at,
            fq.retry_count,
            fq.status AS queue_status,
            b.name AS branch_name,
            c.business_name
        FROM fbr_invoices fi
        LEFT JOIN fbr_queue fq ON fq.sale_id = fi.sale_id
        LEFT JOIN branches b ON b.id = fi.sale_id /* Adjust if branch_id is in invoices */
        LEFT JOIN clients c ON c.id = b.client_id
        ORDER BY fi.created_at DESC
        LIMIT 100
    ";
    $res = $conn->query($sql);
} else { // CLIENT ADMIN
    $stmt = $conn->prepare("
        SELECT 
            fi.sale_id,
            fi.irn,
            fi.qr,
            fi.status AS invoice_status,
            fi.response,
            fi.created_at,
            fq.retry_count,
            fq.status AS queue_status,
            b.name AS branch_name
        FROM fbr_invoices fi
        LEFT JOIN fbr_queue fq ON fq.sale_id = fi.sale_id
        LEFT JOIN branches b ON b.id = fi.sale_id /* Adjust if branch_id is in invoices */
        WHERE b.client_id = ?
        ORDER BY fi.created_at DESC
        LIMIT 100
    ");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
}

if($res){
    while($row = $res->fetch_assoc()){
        $fbr_status_list[] = $row;
    }
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>FBR Sync Status</title>
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
   ADMIN FBR SYNC PAGE FIX
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
}


/* =====================================================
   FBR_SYNC.PHP - DARK PURPLE GLASS THEME FINAL
   Paste at END before 
===================================================== */

html,
body.admin-fbr-sync-page{
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

/* Main background */
body.admin-fbr-sync-page .container-scroll,
body.admin-fbr-sync-page .main-panel,
body.admin-fbr-sync-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-fbr-sync-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
    overflow:visible !important;
}

body.admin-fbr-sync-page .content-wrapper::before{
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

body.admin-fbr-sync-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-fbr-sync-page .navbar.my-navbar.fixed-top,
body.admin-fbr-sync-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Heading */
body.admin-fbr-sync-page h1,
body.admin-fbr-sync-page h2,
body.admin-fbr-sync-page h3,
body.admin-fbr-sync-page h4,
body.admin-fbr-sync-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

/* Table wrapper */
body.admin-fbr-sync-page .table-responsive,
body.admin-fbr-sync-page .fbr-sync-table-wrap{
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

/* Table base */
body.admin-fbr-sync-page table.table{
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

/* Bootstrap white remove */
body.admin-fbr-sync-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* Header */
body.admin-fbr-sync-page table.table thead th,
body.admin-fbr-sync-page table.table thead.table-dark th{
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
body.admin-fbr-sync-page table.table tbody td{
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

body.admin-fbr-sync-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* Hover white remove */
body.admin-fbr-sync-page table.table-hover tbody tr:hover td,
body.admin-fbr-sync-page table.table tbody tr:hover td,
body.admin-fbr-sync-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Empty row */
body.admin-fbr-sync-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* Status badges */
body.admin-fbr-sync-page .badge,
body.admin-fbr-sync-page .status-badge{
    border-radius:999px !important;
    padding:7px 14px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

body.admin-fbr-sync-page .bg-success,
body.admin-fbr-sync-page .badge-success,
body.admin-fbr-sync-page .status-success,
body.admin-fbr-sync-page .status-synced{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

body.admin-fbr-sync-page .bg-warning,
body.admin-fbr-sync-page .badge-warning,
body.admin-fbr-sync-page .status-pending,
body.admin-fbr-sync-page .status-queued{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.28) !important;
}

body.admin-fbr-sync-page .bg-danger,
body.admin-fbr-sync-page .badge-danger,
body.admin-fbr-sync-page .status-failed,
body.admin-fbr-sync-page .status-error{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.28) !important;
}

body.admin-fbr-sync-page .bg-secondary,
body.admin-fbr-sync-page .badge-secondary{
    background:rgba(148,163,184,.14) !important;
    color:#cbd5e1 !important;
    border:1px solid rgba(148,163,184,.24) !important;
}

/* FBR response / JSON cell */
body.admin-fbr-sync-page td pre,
body.admin-fbr-sync-page .response-box,
body.admin-fbr-sync-page .json-response{
    background:rgba(7,11,20,.66) !important;
    color:#dbeafe !important;
    border:1px solid rgba(148,163,184,.14) !important;
    border-radius:12px !important;
    padding:10px !important;
    max-width:360px !important;
    max-height:130px !important;
    overflow:auto !important;
    font-size:12px !important;
    line-height:1.45 !important;
}

/* Action icons */
body.admin-fbr-sync-page .retryFBR,
body.admin-fbr-sync-page .deleteFBR,
body.admin-fbr-sync-page .syncFBR,
body.admin-fbr-sync-page .viewFBR,
body.admin-fbr-sync-page .action-btn{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    transition:.22s ease !important;
    margin:0 3px !important;
    box-shadow:0 10px 20px rgba(0,0,0,.20) !important;
    font-size:17px !important;
}

body.admin-fbr-sync-page .retryFBR,
body.admin-fbr-sync-page .syncFBR{
    background:rgba(124,58,237,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(124,58,237,.28) !important;
}

body.admin-fbr-sync-page .viewFBR{
    background:rgba(59,130,246,.16) !important;
    color:#93c5fd !important;
    border:1px solid rgba(59,130,246,.28) !important;
}

body.admin-fbr-sync-page .deleteFBR{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

body.admin-fbr-sync-page .retryFBR:hover,
body.admin-fbr-sync-page .deleteFBR:hover,
body.admin-fbr-sync-page .syncFBR:hover,
body.admin-fbr-sync-page .viewFBR:hover,
body.admin-fbr-sync-page .action-btn:hover{
    transform:translateY(-2px) scale(1.04) !important;
    color:#fff !important;
}

/* Buttons */
body.admin-fbr-sync-page .btn-primary,
body.admin-fbr-sync-page .btn-success,
body.admin-fbr-sync-page button[type="submit"]{
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

body.admin-fbr-sync-page .btn-danger{
    min-height:42px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-fbr-sync-page .btn-secondary,
body.admin-fbr-sync-page .btn-light,
body.admin-fbr-sync-page .btn-dark{
    min-height:42px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-fbr-sync-page .btn:hover,
body.admin-fbr-sync-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Scrollbar */
body.admin-fbr-sync-page .table-responsive::-webkit-scrollbar,
body.admin-fbr-sync-page td pre::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-fbr-sync-page .table-responsive::-webkit-scrollbar-track,
body.admin-fbr-sync-page td pre::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-fbr-sync-page .table-responsive::-webkit-scrollbar-thumb,
body.admin-fbr-sync-page td pre::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

@media(max-width:768px){
    body.admin-fbr-sync-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-fbr-sync-page table.table{
        min-width:1100px !important;
    }
}
    </style>
</head>

<body class="admin-dashboard-page admin-fbr-sync-page">
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

             <h4 class="mb-3 page-title">FBR Sync Status</h4>

                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Sale ID</th>
                            <th>Branch</th>
                            <?php if($logged_role_id==1): ?><th>Client</th><?php endif; ?>
                            <th>IRN</th>
                            <th>QR</th>
                            <th>Invoice Status</th>
                            <th>Queue Status</th>
                            <th>Retry Count</th>
                            <th>Response</th>

                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($fbr_status_list) > 0): ?>
                        <?php foreach($fbr_status_list as $f): ?>
                        <tr>
                            <td><?= $f['sale_id'] ?></td>
                            <td><?= htmlspecialchars($f['branch_name']) ?></td>
                            <?php if($logged_role_id==1): ?>
                            <td><?= htmlspecialchars($f['business_name'] ?? '-') ?></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($f['irn'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($f['qr'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($f['invoice_status'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($f['queue_status'] ?? '-') ?></td>
                            <td><?= $f['retry_count'] ?? 0 ?></td>
                            <td><?= htmlspecialchars($f['response'] ?? '-') ?></td>
                            <td><?= $f['created_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="<?= ($logged_role_id==1)?10:9 ?>" class="text-center">No FBR activity found
                            </td>
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