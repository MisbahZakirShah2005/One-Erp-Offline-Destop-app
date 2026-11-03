<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include('partials/performance.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================== AUTH CHECK ================== */
if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
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

/* ================== FETCH SUSPENDED CLIENTS WITH LAST PAYMENT ================== */
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
    WHERE c.status = 'suspended'
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
<title>Suspended Clients</title>
<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* =====================================================
   SUSPEND_CLIENTS.PHP - DARK PURPLE GLASS THEME
===================================================== */

html,
body.admin-dashboard-page{
    margin:0 !important;
    padding:0 !important;
    width:100% !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:#f8fafc !important;
    font-family:Inter, "Segoe UI", Arial, sans-serif !important;
}

body.admin-dashboard-page{
    --admin-sidebar-open-width:280px;
    --admin-sidebar-close-width:88px;
    --admin-current-sidebar-width:var(--admin-sidebar-open-width);
}

body.admin-dashboard-page.sidebar-collapsed,
body.admin-dashboard-page.dashboard-sidebar-collapsed{
    --admin-current-sidebar-width:var(--admin-sidebar-close-width);
}

/* Layout */
body.admin-dashboard-page .container-scroll{
    display:flex !important;
    width:100% !important;
    min-height:100vh !important;
    margin:0 !important;
    padding:0 !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.admin-dashboard-page .sidebar,
body.admin-dashboard-page #dashboardSidebar{
    width:var(--admin-current-sidebar-width) !important;
    min-width:var(--admin-current-sidebar-width) !important;
    max-width:var(--admin-current-sidebar-width) !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    background:transparent !important;
    transition:.25s ease !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-wrap{
    width:var(--admin-current-sidebar-width) !important;
    min-width:var(--admin-current-sidebar-width) !important;
    max-width:var(--admin-current-sidebar-width) !important;
    height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-inner{
    height:100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* Main */
body.admin-dashboard-page .main-panel{
    margin-left:var(--admin-current-sidebar-width) !important;
    width:calc(100% - var(--admin-current-sidebar-width)) !important;
    min-height:100vh !important;
    padding:70px 0 0 0 !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        radial-gradient(circle at 78% 6%, rgba(59,130,246,.12), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    transition:.25s ease !important;
}

/* Topbar */
body.admin-dashboard-page .navbar.my-navbar.fixed-top,
body.admin-dashboard-page .topbar{
    left:var(--admin-current-sidebar-width) !important;
    width:calc(100% - var(--admin-current-sidebar-width)) !important;
    height:70px !important;
    min-height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
    z-index:999 !important;
}

/* Content */
body.admin-dashboard-page .content-wrapper{
    position:relative !important;
    width:100% !important;
    min-height:calc(100vh - 70px) !important;
    padding:26px 28px !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(239,68,68,.08), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:#f8fafc !important;
}

body.admin-dashboard-page .content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.28;
    z-index:0;
}

body.admin-dashboard-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Header */
body.admin-suspend-clients-page h4,
body.admin-suspend-clients-page .page-title{
    color:#f8fafc !important;
    font-size:28px !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.35) !important;
}

/* Add / Buttons */
body.admin-suspend-clients-page .btn-warning,
body.admin-suspend-clients-page .btn-primary,
body.admin-suspend-clients-page .btn-success,
body.admin-suspend-clients-page button[type="submit"]{
    min-height:44px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.30) !important;
    transition:.22s ease !important;
}

body.admin-suspend-clients-page .btn-secondary,
body.admin-suspend-clients-page .btn-dark{
    min-height:42px !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-suspend-clients-page .btn-danger{
    min-height:42px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-suspend-clients-page button:hover,
body.admin-suspend-clients-page .btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Alerts */
body.admin-suspend-clients-page .alert{
    border-radius:14px !important;
    font-weight:850 !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

body.admin-suspend-clients-page .alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.28) !important;
}

body.admin-suspend-clients-page .alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.28) !important;
}

/* Table glass wrapper */
body.admin-suspend-clients-page .table-responsive,
body.admin-suspend-clients-page table.table{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.15), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.74), rgba(17,24,39,.55)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

/* Table */
body.admin-suspend-clients-page table.table{
    width:100% !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    overflow:hidden !important;
    margin:0 !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.08) !important;
    --bs-table-hover-bg:rgba(124,58,237,.14) !important;
    --bs-table-color:#e5e7eb !important;
}

body.admin-suspend-clients-page table.table thead th,
body.admin-suspend-clients-page table.table thead.table-dark th{
    background:rgba(7,11,20,.92) !important;
    color:#cbd5e1 !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.06em !important;
    white-space:nowrap !important;
}

body.admin-suspend-clients-page table.table tbody td{
    background:rgba(15,23,42,.34) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    padding:16px 18px !important;
    font-size:14px !important;
    font-weight:800 !important;
    vertical-align:middle !important;
    white-space:nowrap !important;
}

/* White hover remove */
body.admin-suspend-clients-page table.table tbody tr:hover td,
body.admin-suspend-clients-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.36)) !important;
    color:#fff !important;
}

/* Suspended status look */
body.admin-suspend-clients-page table.table tbody td:nth-child(3),
body.admin-suspend-clients-page .status-suspended{
    color:#f87171 !important;
    font-weight:900 !important;
}

/* Action icons */
body.admin-suspend-clients-page .editClient,
body.admin-suspend-clients-page .deleteClient,
body.admin-suspend-clients-page .editBtn,
body.admin-suspend-clients-page .deleteBtn{
    width:36px !important;
    height:36px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    margin:0 4px !important;
    transition:.22s ease !important;
    box-shadow:0 10px 20px rgba(0,0,0,.20) !important;
}

body.admin-suspend-clients-page .editClient,
body.admin-suspend-clients-page .editBtn{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.26) !important;
}

body.admin-suspend-clients-page .deleteClient,
body.admin-suspend-clients-page .deleteBtn{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

body.admin-suspend-clients-page .editClient:hover,
body.admin-suspend-clients-page .deleteClient:hover,
body.admin-suspend-clients-page .editBtn:hover,
body.admin-suspend-clients-page .deleteBtn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Modal */
body.admin-suspend-clients-page .modal{
    z-index:5000 !important;
}

body.admin-suspend-clients-page .modal-backdrop{
    z-index:4990 !important;
}

body.admin-suspend-clients-page .modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
    backdrop-filter:blur(7px) !important;
}

body.admin-suspend-clients-page .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.30), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(239,68,68,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.82)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.38) !important;
    border-radius:20px !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.62),
        0 0 52px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
    overflow:hidden !important;
}

body.admin-suspend-clients-page .modal-header,
body.admin-suspend-clients-page .modal-footer{
    background:rgba(7,11,20,.38) !important;
    border-color:rgba(148,163,184,.16) !important;
    color:#f8fafc !important;
}

body.admin-suspend-clients-page .modal-title,
body.admin-suspend-clients-page .modal-header h5{
    color:#f8fafc !important;
    font-size:21px !important;
    font-weight:900 !important;
}

body.admin-suspend-clients-page .modal-body{
    background:rgba(15,23,42,.18) !important;
    color:#e5e7eb !important;
}

body.admin-suspend-clients-page .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

/* Form fields */
body.admin-suspend-clients-page .uniform-field,
body.admin-suspend-clients-page .form-control,
body.admin-suspend-clients-page .form-select,
body.admin-suspend-clients-page input,
body.admin-suspend-clients-page select{
    min-height:46px !important;
    border-radius:13px !important;
    background:rgba(255,255,255,.95) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    box-shadow:0 10px 24px rgba(0,0,0,.14) !important;
    font-weight:800 !important;
}

body.admin-suspend-clients-page label{
    color:#e5e7eb !important;
    font-weight:800 !important;
}

/* Collapsed sidebar */
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-arrow,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow{
    display:none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .submenu-collapse,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}

/* Mobile */
@media(max-width:991.98px){
    body.admin-dashboard-page{
        --admin-current-sidebar-width:0px !important;
    }

    body.admin-dashboard-page .main-panel{
        margin-left:0 !important;
        width:100% !important;
        padding-top:70px !important;
    }

    body.admin-dashboard-page .navbar.my-navbar.fixed-top,
    body.admin-dashboard-page .topbar{
        left:0 !important;
        width:100% !important;
    }

    body.admin-dashboard-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-dashboard-page .sidebar,
    body.admin-dashboard-page #dashboardSidebar{
        left:-280px !important;
        width:280px !important;
        min-width:280px !important;
        max-width:280px !important;
        z-index:3000 !important;
    }

    body.admin-dashboard-page.sidebar-open #dashboardSidebar,
    body.admin-dashboard-page.dashboard-mobile-sidebar-open #dashboardSidebar{
        left:0 !important;
    }

    body.admin-suspend-clients-page table.table{
        min-width:900px !important;
    }
}
/* =====================================================
   SUSPEND CLIENTS TABLE - DARK PURPLE GLASS FIX
===================================================== */

body.admin-suspend-clients-page .table-responsive{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(12,18,35,.88), rgba(20,12,40,.78)) !important;
    border:1px solid rgba(139,92,246,.22) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 45px rgba(0,0,0,.28),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter: blur(16px) saturate(140%) !important;
    -webkit-backdrop-filter: blur(16px) saturate(140%) !important;
    padding:0 !important;
}

body.admin-suspend-clients-page table.table{
    width:100% !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    overflow:hidden !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg: transparent !important;
    --bs-table-striped-bg: rgba(124,58,237,.06) !important;
    --bs-table-hover-bg: rgba(124,58,237,.12) !important;
    --bs-table-color: #e5e7eb !important;
    --bs-table-border-color: rgba(148,163,184,.10) !important;
}

body.admin-suspend-clients-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

body.admin-suspend-clients-page table.table thead th,
body.admin-suspend-clients-page table.table thead.table-dark th{
    background:rgba(6,10,20,.95) !important;
    color:#f8fafc !important;
    border:none !important;
    border-bottom:1px solid rgba(139,92,246,.22) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

body.admin-suspend-clients-page table.table tbody tr{
    background:transparent !important;
    transition:all .22s ease !important;
}

body.admin-suspend-clients-page table.table tbody td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.42), rgba(20,12,40,.28)) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:15px 18px !important;
    font-size:14px !important;
    font-weight:700 !important;
    vertical-align:middle !important;
    white-space:nowrap !important;
}

body.admin-suspend-clients-page table.table tbody tr:hover td,
body.admin-suspend-clients-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(30,20,58,.58), rgba(15,23,42,.42)) !important;
    color:#ffffff !important;
}

body.admin-suspend-clients-page table.table tbody tr td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    font-weight:800 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.34), rgba(20,12,40,.22)) !important;
}

/* optional: status column */
body.admin-suspend-clients-page table.table tbody td:nth-child(4){
    color:#f87171 !important;
    font-weight:900 !important;
}

/* action buttons */
body.admin-suspend-clients-page .editClient,
body.admin-suspend-clients-page .editBtn{
    width:36px !important;
    height:36px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
    margin:0 4px !important;
    transition:.22s ease !important;
}

body.admin-suspend-clients-page .deleteClient,
body.admin-suspend-clients-page .deleteBtn{
    width:36px !important;
    height:36px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.25) !important;
    margin:0 4px !important;
    transition:.22s ease !important;
}

body.admin-suspend-clients-page .editClient:hover,
body.admin-suspend-clients-page .editBtn:hover,
body.admin-suspend-clients-page .deleteClient:hover,
body.admin-suspend-clients-page .deleteBtn:hover{
    transform:translateY(-2px) !important;
    box-shadow:0 10px 20px rgba(0,0,0,.25) !important;
}
</style>
</head>
<body class="admin-dashboard-page admin-suspend-clients-page">
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

            <h4 class="mb-3 text-danger">Suspended Clients</h4>

            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Business</th>
                        <th>Industry</th>
                        <th>Status</th>
                        <th>Last Payment</th>
                        <th width="80">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($clients)): ?>
                    <tr>
                        <td colspan="6" class="text-center">No suspended clients found</td>
                    </tr>
                <?php endif; ?>

                <?php foreach($clients as $c): ?>
                    <tr>
                        <td><?= $c['id'] ?></td>
                        <td><?= htmlspecialchars($c['business_name']) ?></td>
                        <td><?= htmlspecialchars($c['industry_name']) ?></td>
                        <td>
                            <span class="badge bg-danger">Suspended</span>
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
    
    
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
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
