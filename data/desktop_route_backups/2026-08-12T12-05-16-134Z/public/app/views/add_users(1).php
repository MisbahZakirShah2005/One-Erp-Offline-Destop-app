<?php
// ================== SESSION & DB ==================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
// ================== AUTH CHECK ==================
// if(!isset($_SESSION['user_id'])){
//     die("User not logged in!");
// }
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = $_SESSION['user_id'];
$logged_role_id   = $_SESSION['role_id'];
$logged_client_id = $_SESSION['client_id'] ?? 0;

// ================== FETCH USERS ==================
$users = [];
if ($logged_role_id == 1) { // SUPER ADMIN
    $res = $conn->query("
        SELECT u.id, u.name, u.email, u.status, c.business_name, u.client_id
        FROM users u
        LEFT JOIN clients c ON c.id = u.client_id
        ORDER BY u.id DESC
    ");
    if($res) while($row = $res->fetch_assoc()) $users[] = $row;
} else { // CLIENT ADMIN
    $stmt = $conn->prepare("
        SELECT id, name, email, status
        FROM users
        WHERE client_id = ?
        ORDER BY id DESC
    ");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $stmt->bind_result($id, $name, $email, $status);
    while($stmt->fetch()){
        $users[] = ['id'=>$id,'name'=>$name,'email'=>$email,'status'=>$status];
    }
    $stmt->close();
}

// ================== FETCH CLIENTS ==================
$clients = [];
if ($logged_role_id == 1) {
    $res = $conn->query("SELECT id, business_name FROM clients ORDER BY business_name ASC");
    if($res) while($row = $res->fetch_assoc()) $clients[] = $row;
} else { // CLIENT ADMIN
    $stmt = $conn->prepare("SELECT id, business_name FROM clients WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if($res) while($row = $res->fetch_assoc()) $clients[] = $row;
    $stmt->close();
}

// ================== ADD / EDIT / DELETE USER ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ADD USER
    if(isset($_POST['add_user']) && $_POST['add_user']==1){
        $name     = trim($_POST['name']);
        $email    = trim($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $status   = $_POST['status'];
        // Client is fixed for Client Admin
        $client_id = ($logged_role_id==1) ? $_POST['client_id'] : $logged_client_id;

        $stmt = $conn->prepare("INSERT INTO users (client_id,name,email,password,status,created_at) VALUES (?,?,?,?,?,NOW())");
        $stmt->bind_param("issss", $client_id, $name, $email, $password, $status);
        if($stmt->execute()){
            $_SESSION['success']="User added successfully!";
        } else {
            $_SESSION['error']=$stmt->error;
        }
        $stmt->close();
        header("Location: add_users.php"); exit;
    }

    // EDIT USER
    if(isset($_POST['edit_user']) && $_POST['edit_user']==1){
        $id       = (int)$_POST['id'];
        $name     = trim($_POST['name']);
        $email    = trim($_POST['email']);
        $status   = $_POST['status'];
        $client_id = ($logged_role_id==1) ? $_POST['client_id'] : $logged_client_id;

        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, status=?, client_id=? WHERE id=?");
        $stmt->bind_param("sssii", $name, $email, $status, $client_id, $id);
        if($stmt->execute()){
            $_SESSION['success']="User updated successfully!";
        } else {
            $_SESSION['error']=$stmt->error;
        }
        $stmt->close();
        header("Location: add_users.php"); exit;
    }

    // DELETE USER
    if(isset($_POST['delete_user'])){
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i",$id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success']="User deleted!";
        header("Location: add_users.php"); exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Users</title>
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
   ADMIN ADD USERS PAGE FIX
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

body.admin-dashboard-page .uniform-field {
    width: 100%;
    height: 42px;
    padding: 8px 12px;
    font-size: 14px;
    border-radius: 4px;
    box-sizing: border-box;
    margin-bottom: 12px;
    border: 2px solid #e4ac00;
    outline: none;
}

body.admin-dashboard-page .uniform-field:focus {
    border-color: #e4ac00;
    box-shadow: 0 0 3px #e4ac00;
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
   ADD_USERS.PHP - DARK PURPLE GLASS THEME
   Paste at VERY END before
===================================================== */

html,
body.admin-dashboard-page{
    margin:0 !important;
    padding:0 !important;
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
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.admin-dashboard-page .sidebar,
body.admin-dashboard-page #dashboardSidebar{
    width:var(--admin-current-sidebar-width) !important;
    min-width:var(--admin-current-sidebar-width) !important;
    max-width:var(--admin-current-sidebar-width) !important;
    flex-shrink:0 !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    min-height:100vh !important;
    z-index:1000 !important;
    background:transparent !important;
    transition:.25s ease !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-wrap{
    width:var(--admin-current-sidebar-width) !important;
    min-width:var(--admin-current-sidebar-width) !important;
    max-width:var(--admin-current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-inner{
    height:100vh !important;
    min-height:100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* Main panel */
body.admin-dashboard-page .main-panel{
    margin-left:var(--admin-current-sidebar-width) !important;
    width:calc(100% - var(--admin-current-sidebar-width)) !important;
    min-width:0 !important;
    min-height:100vh !important;
    padding:70px 0 0 0 !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        radial-gradient(circle at 78% 6%, rgba(59,130,246,.12), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
}

/* Topbar */
body.admin-dashboard-page .topbar-wrap,
body.admin-dashboard-page .topbar,
body.admin-dashboard-page .navbar.my-navbar.fixed-top{
    left:var(--admin-current-sidebar-width) !important;
    width:calc(100% - var(--admin-current-sidebar-width)) !important;
    height:70px !important;
    min-height:70px !important;
    background:#070b14 !important;
    color:#fff !important;
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
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.08), transparent 26%),
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

/* Page title */
body.admin-add-users-page h1,
body.admin-add-users-page h2,
body.admin-add-users-page h3,
body.admin-add-users-page h4,
body.admin-add-users-page .page-title{
    color:#f8fafc !important;
    font-size:28px !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.35) !important;
}

/* Alerts */
body.admin-add-users-page .alert{
    border-radius:14px !important;
    font-weight:850 !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

body.admin-add-users-page .alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.28) !important;
}

body.admin-add-users-page .alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.28) !important;
}

/* Cards / panels */
body.admin-add-users-page .card,
body.admin-add-users-page .table-card,
body.admin-add-users-page .filter-bar,
body.admin-add-users-page .panel-card,
body.admin-add-users-page .form-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    color:#f8fafc !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

/* Form controls */
body.admin-add-users-page .form-control,
body.admin-add-users-page .form-select,
body.admin-add-users-page input,
body.admin-add-users-page select,
body.admin-add-users-page textarea,
body.admin-add-users-page .filter-control{
    min-height:46px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    outline:none !important;
    font-weight:800 !important;
}

body.admin-add-users-page input::placeholder,
body.admin-add-users-page textarea::placeholder{
    color:#94a3b8 !important;
}

body.admin-add-users-page .form-control:focus,
body.admin-add-users-page .form-select:focus,
body.admin-add-users-page input:focus,
body.admin-add-users-page select:focus,
body.admin-add-users-page textarea:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

body.admin-add-users-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

body.admin-add-users-page label,
body.admin-add-users-page .form-label,
body.admin-add-users-page .text-muted,
body.admin-add-users-page small{
    color:#cbd5e1 !important;
    font-weight:800 !important;
}

/* Buttons */
body.admin-add-users-page .btn-primary,
body.admin-add-users-page .btn-success,
body.admin-add-users-page .filter-btn,
body.admin-add-users-page .quick-btn,
body.admin-add-users-page button[type="submit"]{
    min-height:46px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 20px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.18) !important;
    transition:.22s ease !important;
}

body.admin-add-users-page .btn-secondary,
body.admin-add-users-page .btn-light{
    min-height:44px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-add-users-page .btn-danger,
body.admin-add-users-page .bulk-delete-btn{
    min-height:42px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-add-users-page .btn-primary:hover,
body.admin-add-users-page .btn-success:hover,
body.admin-add-users-page .btn-secondary:hover,
body.admin-add-users-page .btn-danger:hover,
body.admin-add-users-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Table wrapper */
body.admin-add-users-page .table-responsive,
body.admin-add-users-page .table-card{
    overflow:auto !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.15), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.74), rgba(17,24,39,.55)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
}

/* Tables */
body.admin-add-users-page table,
body.admin-add-users-page .table,
body.admin-add-users-page .customers-table{
    width:100% !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;
    margin:0 !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.08) !important;
    --bs-table-hover-bg:rgba(124,58,237,.14) !important;
    --bs-table-color:#e5e7eb !important;
}

body.admin-add-users-page table thead th,
body.admin-add-users-page .table thead th,
body.admin-add-users-page .customers-table thead th{
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

body.admin-add-users-page table tbody td,
body.admin-add-users-page .table tbody td,
body.admin-add-users-page .customers-table tbody td{
    background:rgba(15,23,42,.34) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    padding:16px 18px !important;
    font-size:14px !important;
    font-weight:800 !important;
    vertical-align:middle !important;
}

/* White hover remove */
body.admin-add-users-page table tbody tr:hover td,
body.admin-add-users-page .table tbody tr:hover td,
body.admin-add-users-page .customers-table tbody tr:hover td,
body.admin-add-users-page table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.36)) !important;
    color:#fff !important;
}

/* User name / avatar */
body.admin-add-users-page .customer-name,
body.admin-add-users-page .user-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

body.admin-add-users-page .customer-email-sub{
    color:#94a3b8 !important;
}

body.admin-add-users-page .avatar-badge{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.16) !important;
}

/* Status badge */
body.admin-add-users-page .badge,
body.admin-add-users-page .badge-type{
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

body.admin-add-users-page .bg-success,
body.admin-add-users-page .badge-success,
body.admin-add-users-page .active-badge{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

body.admin-add-users-page .bg-danger,
body.admin-add-users-page .badge-danger,
body.admin-add-users-page .inactive-badge{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* Action buttons */
body.admin-add-users-page .app-btn,
body.admin-add-users-page .actions button,
body.admin-add-users-page .editUser,
body.admin-add-users-page .deleteUser{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.64) !important;
    color:#e5e7eb !important;
    transition:.22s ease !important;
    box-shadow:0 10px 20px rgba(0,0,0,.20) !important;
}

body.admin-add-users-page .app-edit,
body.admin-add-users-page .editUser{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.26) !important;
}

body.admin-add-users-page .app-delete,
body.admin-add-users-page .deleteUser{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.26) !important;
}

body.admin-add-users-page .app-btn:hover,
body.admin-add-users-page .actions button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Modal */
body.admin-add-users-page .modal{
    z-index:5000 !important;
}

body.admin-add-users-page .modal-backdrop{
    z-index:4990 !important;
}

body.admin-add-users-page .modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
    backdrop-filter:blur(7px) !important;
}

body.admin-add-users-page .modal-content,
body.admin-add-users-page .po-modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.30), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(34,197,94,.10), transparent 32%),
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

body.admin-add-users-page .modal-header,
body.admin-add-users-page .modal-footer,
body.admin-add-users-page .po-modal-header{
    background:rgba(7,11,20,.38) !important;
    border-color:rgba(148,163,184,.16) !important;
    color:#f8fafc !important;
}

body.admin-add-users-page .modal-title,
body.admin-add-users-page .po-modal-title{
    color:#f8fafc !important;
    font-size:21px !important;
    font-weight:900 !important;
}

body.admin-add-users-page .modal-body,
body.admin-add-users-page .po-modal-body{
    background:rgba(15,23,42,.18) !important;
    color:#e5e7eb !important;
}

body.admin-add-users-page .btn-close,
body.admin-add-users-page .po-close{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    background-color:rgba(148,163,184,.16) !important;
    color:#fff !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

/* Scrollbar */
body.admin-add-users-page .table-responsive::-webkit-scrollbar,
body.admin-add-users-page .content-wrapper::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-add-users-page .table-responsive::-webkit-scrollbar-track,
body.admin-add-users-page .content-wrapper::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-add-users-page .table-responsive::-webkit-scrollbar-thumb,
body.admin-add-users-page .content-wrapper::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* Responsive */
@media(max-width:991.98px){
    body.admin-dashboard-page{
        --admin-current-sidebar-width:0px !important;
    }

    body.admin-dashboard-page .main-panel{
        margin-left:0 !important;
        width:100% !important;
        padding-top:70px !important;
    }

    body.admin-dashboard-page .topbar-wrap,
    body.admin-dashboard-page .topbar,
    body.admin-dashboard-page .navbar.my-navbar.fixed-top{
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

    body.admin-add-users-page .filter-bar{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    body.admin-add-users-page .search-wrap,
    body.admin-add-users-page .filter-control,
    body.admin-add-users-page .filter-btn{
        width:100% !important;
    }

    body.admin-add-users-page table{
        min-width:1000px !important;
    }
}

    </style>
</head>

<body class="admin-dashboard-page admin-add-users-page">
   <div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>Users</h4>
                    <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#userModal" id="addBtn">+ Add
                        User</button>
                </div>

                <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <?php if($logged_role_id==1): ?><th>Client</th><?php endif; ?>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['name']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <?php if($logged_role_id==1): ?>
                            <td><?= htmlspecialchars($u['business_name'] ?? '-') ?></td>
                            <?php endif; ?>
                            <td><?= ucfirst($u['status']) ?></td>
                            <td class="text-center">
                                <!-- Edit Icon -->
                                <i class="bi bi-pencil-square text-warning me-2 editBtn" style="cursor:pointer"
                                    data-id="<?= $u['id'] ?>"
                                    data-name="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>"
                                    data-email="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>"
                                    data-status="<?= $u['status'] ?>" <?php if($logged_role_id==1): ?>
                                    data-client="<?= $u['client_id'] ?>" <?php endif; ?> data-bs-toggle="modal"
                                    data-bs-target="#editUserModal"></i>

                                <!-- Delete Icon -->
                                <i class="bi bi-trash text-danger deleteBtn" style="cursor:pointer"
                                    data-bs-toggle="modal" data-bs-target="#delete<?= $u['id'] ?>"></i>
                            </td>

                        </tr>

                        <div class="modal fade" id="delete<?= $u['id'] ?>">
                            <div class="modal-dialog">
                                <form method="post" class="modal-content">
                                    <input type="hidden" name="delete_user" value="1">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <div class="modal-header">
                                        <h5>Delete User</h5>
                                    </div>
                                    <div class="modal-body">Are you sure you want to delete
                                        <b><?= htmlspecialchars($u['name']) ?></b>?</div>
                                    <div class="modal-footer">
                                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button class="btn btn-danger">Delete</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SINGLE ADD / EDIT USER MODAL -->
    <div class="modal fade" id="userModal">
        <div class="modal-dialog modal-md">
            <form method="post" class="modal-content">
                <input type="hidden" name="add_user" id="addInput" value="1">
                <input type="hidden" name="edit_user" id="editInput">
                <input type="hidden" name="id" id="userId">

                <div class="modal-header">
                    <h5 id="modalTitle">Add User</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                  <div class="row mb-2">
    <div class="col-md-6">
        <?php if($logged_role_id==1): ?>
        <label>Clients / Company</label>
        <select name="client_id" id="userClient" class="uniform-field" required>
            <option value="">Select Client</option>
            <?php foreach($clients as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['business_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <label>Client</label>
        <input type="text" class="uniform-field" value="<?= htmlspecialchars($clients[0]['business_name'] ?? '') ?>" readonly>
        <?php endif; ?>
    </div>
    <div class="col-md-6">
        <label>Name</label>
        <input type="text" name="name" id="userName" class="uniform-field" placeholder="Name" required>
    </div>
</div>

<div class="row mb-2">
    <div class="col-md-6">
        <label>Email</label>
        <input type="email" name="email" id="userEmail" class="uniform-field" placeholder="Email" required>
    </div>
    <div class="col-md-6">
        <label>Password</label>
        <input type="password" name="password" id="userPassword" class="uniform-field" placeholder="Password">
    </div>
</div>

<div class="row mb-2">
    <div class="col-md-12">
        <label>Status</label>
        <select name="status" id="userStatus" class="uniform-field">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
</div>

                <div class="modal-footer">
                    <button class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-warning" id="modalBtn">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const userModal = document.getElementById('userModal');

    // ADD BUTTON
    document.getElementById('addBtn').addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = 'Add User';
        document.getElementById('modalBtn').innerText = 'Add';
        document.getElementById('userName').value = '';
        document.getElementById('userEmail').value = '';
        document.getElementById('userPassword').value = '';
        document.getElementById('userStatus').value = 'active';
        <?php if($logged_role_id==1): ?>
        document.getElementById('userClient').value = '';
        <?php endif; ?>
        document.getElementById('addInput').value = 1;
        document.getElementById('editInput').value = '';
    });

    // EDIT BUTTON
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').innerText = 'Edit User';
            document.getElementById('modalBtn').innerText = 'Save';
            document.getElementById('userId').value = this.dataset.id;
            document.getElementById('userName').value = this.dataset.name;
            document.getElementById('userEmail').value = this.dataset.email;
            document.getElementById('userPassword').value = '';
            document.getElementById('userStatus').value = this.dataset.status;
            <?php if($logged_role_id==1): ?>
            document.getElementById('userClient').value = this.dataset.client || '';
            <?php endif; ?>
            document.getElementById('addInput').value = '';
            document.getElementById('editInput').value = 1;
            new bootstrap.Modal(userModal).show();
        });
    });
    </script>
</body>

</html>