<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_role_id = $_SESSION['role_id'];
if($logged_role_id != 1){
    die("Access denied!");
}

/* ================== AJAX ACTIONS ================== */
if(isset($_POST['ajax'])){

    // Retry FBR queue item
    if($_POST['ajax'] === 'retry_fbr'){
        $sale_id = $_POST['sale_id'];
        // Update status to pending and increment retry_count
        $conn->query("UPDATE fbr_queue SET status='pending', retry_count = retry_count+1 WHERE sale_id='$sale_id'");
        echo "ok";
        exit;
    }

    // Delete queue item
    if($_POST['ajax'] === 'delete_fbr'){
        $sale_id = $_POST['sale_id'];
        $conn->query("DELETE FROM fbr_queue WHERE sale_id='$sale_id'");
        echo "deleted";
        exit;
    }
}

/* ================== FETCH QUEUE ================== */
$queue = $conn->query("
    SELECT q.*, i.irn, i.qr, i.status AS fbr_status, i.response, i.created_at AS invoice_created
    FROM fbr_queue q
    LEFT JOIN fbr_invoices i ON i.sale_id = q.sale_id
    ORDER BY q.sale_id DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FBR Integration</title>
<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
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
   ADMIN FBR PAGE FIX
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
   FBR.PHP - DARK PURPLE GLASS THEME FINAL
   Paste at END before
===================================================== */

html,
body.admin-fbr-page{
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

/* Main layout dark */
body.admin-fbr-page .container-scroll,
body.admin-fbr-page .main-panel,
body.admin-fbr-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-fbr-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
}

body.admin-fbr-page .content-wrapper::before{
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

body.admin-fbr-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-fbr-page .navbar.my-navbar.fixed-top,
body.admin-fbr-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Heading */
body.admin-fbr-page h1,
body.admin-fbr-page h2,
body.admin-fbr-page h3,
body.admin-fbr-page h4,
body.admin-fbr-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

/* Table glass wrapper */
body.admin-fbr-page .table-responsive{
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
body.admin-fbr-page table.table{
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

body.admin-fbr-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* Header */
body.admin-fbr-page table.table thead th,
body.admin-fbr-page table.table thead.table-dark th{
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

/* Body */
body.admin-fbr-page table.table tbody td{
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

body.admin-fbr-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* White hover remove */
body.admin-fbr-page table.table-hover tbody tr:hover td,
body.admin-fbr-page table.table tbody tr:hover td,
body.admin-fbr-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Empty row */
body.admin-fbr-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* Status badges */
body.admin-fbr-page .badge{
    border-radius:999px !important;
    padding:7px 14px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

body.admin-fbr-page .bg-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

body.admin-fbr-page .bg-warning{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.28) !important;
}

body.admin-fbr-page .bg-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.28) !important;
}

body.admin-fbr-page .bg-secondary{
    background:rgba(148,163,184,.14) !important;
    color:#cbd5e1 !important;
    border:1px solid rgba(148,163,184,.24) !important;
}

/* Retry / Delete icons */
body.admin-fbr-page .retryFBR,
body.admin-fbr-page .deleteFBR{
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

body.admin-fbr-page .retryFBR{
    background:rgba(124,58,237,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(124,58,237,.28) !important;
}

body.admin-fbr-page .deleteFBR{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

body.admin-fbr-page .retryFBR:hover,
body.admin-fbr-page .deleteFBR:hover{
    transform:translateY(-2px) scale(1.04) !important;
    color:#fff !important;
}

/* Response / JSON text readable */
body.admin-fbr-page td pre,
body.admin-fbr-page .response-box{
    background:rgba(7,11,20,.65) !important;
    color:#dbeafe !important;
    border:1px solid rgba(148,163,184,.14) !important;
    border-radius:12px !important;
    padding:10px !important;
    max-width:360px !important;
    max-height:120px !important;
    overflow:auto !important;
}

/* Scrollbar */
body.admin-fbr-page .table-responsive::-webkit-scrollbar,
body.admin-fbr-page td pre::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-fbr-page .table-responsive::-webkit-scrollbar-track,
body.admin-fbr-page td pre::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-fbr-page .table-responsive::-webkit-scrollbar-thumb,
body.admin-fbr-page td pre::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* Mobile */
@media(max-width:768px){
    body.admin-fbr-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-fbr-page table.table{
        min-width:1050px !important;
    }
}

</style>
</head>

<body class="admin-dashboard-page admin-fbr-page">
<div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">

            <h4 class="mb-3">FBR Integration Queue</h4>

            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Sale ID</th>
                        <th>Retry Count</th>
                        <th>Status</th>
                        <th>IRN</th>
                        <th>QR</th>
                        <th>Response</th>
                        <th>Invoice Created</th>
                        <th width="100">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($queue->num_rows==0): ?>
                        <tr><td colspan="8" class="text-center">No FBR requests found</td></tr>
                    <?php else: ?>
                        <?php while($row=$queue->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['sale_id'] ?></td>
                            <td><?= $row['retry_count'] ?></td>
                            <td>
                                <?php 
                                    $status_class = $row['status']=='pending'?'secondary':($row['status']=='success'?'success':'danger');
                                    echo "<span class='badge bg-$status_class'>{$row['status']}</span>";
                                ?>
                            </td>
                            <td><?= $row['irn'] ?></td>
                            <td><?= $row['qr'] ?></td>
                            <td><?= htmlspecialchars($row['response']) ?></td>
                            <td><?= $row['invoice_created'] ?></td>
                            <td>
                                <i class="bi bi-arrow-clockwise text-primary retryFBR" style="cursor:pointer" data-id="<?= $row['sale_id'] ?>" title="Retry"></i>
                                <i class="bi bi-trash text-danger ms-2 deleteFBR" style="cursor:pointer" data-id="<?= $row['sale_id'] ?>" title="Delete"></i>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
document.querySelectorAll('.retryFBR').forEach(icon=>{
    icon.onclick = ()=>{
        if(!confirm("Retry this FBR request?")) return;
        fetch("",{
            method:"POST",
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`ajax=retry_fbr&sale_id=${icon.dataset.id}`
        }).then(()=>location.reload());
    };
});

document.querySelectorAll('.deleteFBR').forEach(icon=>{
    icon.onclick = ()=>{
        if(!confirm("Delete this FBR request?")) return;
        fetch("",{
            method:"POST",
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`ajax=delete_fbr&sale_id=${icon.dataset.id}`
        }).then(()=>location.reload());
    };
});
</script>
</body>
</html>
