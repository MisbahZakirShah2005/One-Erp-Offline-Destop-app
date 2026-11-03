<?php
session_start();
include('partials/performance.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$logged_role_id = $_SESSION['role_id'];
if($logged_role_id != 1){
    die("Access denied! Only superadmin can update global settings.");
}

/* ================= FETCH CURRENT SETTINGS ================= */
$stmt = $conn->prepare("SELECT * FROM global_settings ORDER BY id DESC LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();
$stmt->close();

/* ================= HANDLE FORM SUBMIT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST'){

    $company_name = $_POST['company_name'] ?? '';
    $head_office_address = $_POST['head_office_address'] ?? '';
    $web_url = $_POST['web_url'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $tax_number = $_POST['tax_number'] ?? '';
    $default_currency = $_POST['default_currency'] ?? 'PKR';
    $timezone = $_POST['timezone'] ?? 'Asia/Karachi';
    $enable_inventory = isset($_POST['enable_inventory']) ? 1 : 0;
    $enable_wholesale = isset($_POST['enable_wholesale']) ? 1 : 0;
    $enable_fbr = isset($_POST['enable_fbr']) ? 1 : 0;

    // Handle logo upload
    $company_logo = $settings['company_logo'] ?? '';
    if(isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0){
        $ext = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
        $new_name = 'logo_'.time().'.'.$ext;
        $upload_path = $_SERVER['DOCUMENT_ROOT'].'/SaasPos/public/uploads/'.$new_name;
        if(move_uploaded_file($_FILES['company_logo']['tmp_name'], $upload_path)){
            $company_logo = '/SaasPos/public/uploads/'.$new_name;
        }
    }

    if($settings){ // UPDATE
        $stmt = $conn->prepare("
            UPDATE global_settings SET
            company_name=?, company_logo=?, head_office_address=?, web_url=?, email=?, phone=?, tax_number=?,
            default_currency=?, timezone=?, enable_inventory=?, enable_wholesale=?, enable_fbr=?
            WHERE id=?
        ");
        $stmt->bind_param("ssssssssssiii", $company_name, $company_logo, $head_office_address, $web_url, $email, $phone, $tax_number, $default_currency, $timezone, $enable_inventory, $enable_wholesale, $enable_fbr, $settings['id']);
    } else { // INSERT
        $stmt = $conn->prepare("
            INSERT INTO global_settings
            (company_name, company_logo, head_office_address, web_url, email, phone, tax_number, default_currency, timezone, enable_inventory, enable_wholesale, enable_fbr)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssssssssii", $company_name, $company_logo, $head_office_address, $web_url, $email, $phone, $tax_number, $default_currency, $timezone, $enable_inventory, $enable_wholesale, $enable_fbr);
    }

    if($stmt->execute()){
        $_SESSION['success'] = "Global settings updated successfully!";
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    } else {
        $_SESSION['error'] = $stmt->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Global Settings</title>
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
   ADMIN SETTINGS PAGE FIX
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

body.admin-dashboard-page form {
    background: #fff;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}

body.admin-dashboard-page form .row {
    margin-left: 0 !important;
    margin-right: 0 !important;
}

body.admin-dashboard-page form label {
    font-weight: 600;
    margin-bottom: 6px;
}

body.admin-dashboard-page .form-control {
    min-height: 42px;
}

body.admin-dashboard-page textarea.form-control {
    min-height: 90px;
}

body.admin-dashboard-page .form-check {
    margin-bottom: 10px;
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

    body.admin-dashboard-page form {
        padding: 14px;
    }

    body.admin-dashboard-page .footer,
    body.admin-dashboard-page footer {
        padding: 12px 16px !important;
    }
}

/* =====================================================
   GLOBAL SETTINGS / SETTINGS PAGE - DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html,
body.admin-settings-page{
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

/* Main layout dark */
body.admin-settings-page .container-scroll,
body.admin-settings-page .main-panel,
body.admin-settings-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-settings-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
    overflow:visible !important;
}

body.admin-settings-page .content-wrapper::before{
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

body.admin-settings-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-settings-page .navbar.my-navbar.fixed-top,
body.admin-settings-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Heading */
body.admin-settings-page h1,
body.admin-settings-page h2,
body.admin-settings-page h3,
body.admin-settings-page h4,
body.admin-settings-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

/* Main settings form glass card */
body.admin-settings-page form{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:20px !important;
    padding:24px !important;
    color:#f8fafc !important;
    box-shadow:
        0 20px 55px rgba(0,0,0,.34),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

/* Labels */
body.admin-settings-page form label,
body.admin-settings-page .form-label{
    color:#e5e7eb !important;
    font-weight:900 !important;
    margin-bottom:7px !important;
}

/* Inputs */
body.admin-settings-page .form-control,
body.admin-settings-page .form-select,
body.admin-settings-page input,
body.admin-settings-page select,
body.admin-settings-page textarea{
    min-height:46px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.22) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    font-weight:800 !important;
}

body.admin-settings-page textarea{
    min-height:95px !important;
}

body.admin-settings-page .form-control::placeholder,
body.admin-settings-page textarea::placeholder{
    color:#94a3b8 !important;
}

body.admin-settings-page .form-control:focus,
body.admin-settings-page .form-select:focus,
body.admin-settings-page input:focus,
body.admin-settings-page select:focus,
body.admin-settings-page textarea:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

body.admin-settings-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* File input */
body.admin-settings-page input[type="file"]{
    padding:10px 12px !important;
}

body.admin-settings-page input[type="file"]::file-selector-button{
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:8px 12px !important;
    margin-right:12px !important;
}

/* Current logo image */
body.admin-settings-page img{
    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.22) !important;
    background:rgba(15,23,42,.65) !important;
    padding:6px !important;
    box-shadow:0 12px 28px rgba(0,0,0,.26) !important;
}

/* Switch / checkboxes */
body.admin-settings-page .form-check{
    background:
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(30,20,58,.38)) !important;
    border:1px solid rgba(148,163,184,.15) !important;
    border-radius:14px !important;
    padding:12px 14px 12px 42px !important;
    color:#e5e7eb !important;
    margin-bottom:10px !important;
}

body.admin-settings-page .form-check-label{
    color:#e5e7eb !important;
    font-weight:850 !important;
}

body.admin-settings-page .form-check-input{
    width:20px !important;
    height:20px !important;
    background-color:rgba(15,23,42,.90) !important;
    border:1px solid rgba(139,92,246,.45) !important;
    cursor:pointer !important;
}

body.admin-settings-page .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.16) !important;
}

/* Alerts */
body.admin-settings-page .alert{
    border-radius:14px !important;
    font-weight:850 !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 12px 28px rgba(0,0,0,.20) !important;
}

body.admin-settings-page .alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.28) !important;
}

body.admin-settings-page .alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.28) !important;
}

/* Submit button */
body.admin-settings-page .btn-primary,
body.admin-settings-page button[type="submit"]{
    min-height:46px !important;
    border:none !important;
    border-radius:14px !important;
    padding:0 22px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:950 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.18) !important;
    transition:.22s ease !important;
}

body.admin-settings-page .btn-primary:hover,
body.admin-settings-page button[type="submit"]:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    filter:brightness(1.08) !important;
}

/* Row spacing */
body.admin-settings-page form .row{
    row-gap:14px !important;
}

body.admin-settings-page form .mb-3{
    margin-bottom:16px !important;
}

/* Mobile */
@media(max-width:768px){
    body.admin-settings-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-settings-page form{
        padding:18px !important;
        border-radius:18px !important;
    }

    body.admin-settings-page .btn-primary,
    body.admin-settings-page button[type="submit"]{
        width:100% !important;
    }
}
</style>
</head>
<body class="admin-dashboard-page admin-settings-page">
<div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">

<h4>Global Settings</h4>

<?php if(isset($_SESSION['success'])): ?>
<div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if(isset($_SESSION['error'])): ?>
<div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label>Company Name</label>
        <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" required>
    </div>
    <div class="col-md-6">
        <label>Company Logo</label>
        <input type="file" name="company_logo" class="form-control">
        <?php if(!empty($settings['company_logo'])): ?>
        <img src="<?= $settings['company_logo'] ?>" style="height:50px;margin-top:5px;">
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label>Head Office Address</label>
        <textarea name="head_office_address" class="form-control"><?= htmlspecialchars($settings['head_office_address'] ?? '') ?></textarea>
    </div>
    <div class="col-md-6">
        <label>Website URL</label>
        <input type="text" name="web_url" class="form-control" value="<?= htmlspecialchars($settings['web_url'] ?? '') ?>">
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label>Email</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($settings['email'] ?? '') ?>">
    </div>
    <div class="col-md-6">
        <label>Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($settings['phone'] ?? '') ?>">
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label>Tax Number</label>
        <input type="text" name="tax_number" class="form-control" value="<?= htmlspecialchars($settings['tax_number'] ?? '') ?>">
    </div>
    <div class="col-md-6">
        <label>Default Currency</label>
        <input type="text" name="default_currency" class="form-control" value="<?= htmlspecialchars($settings['default_currency'] ?? 'PKR') ?>">
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label>Timezone</label>
        <input type="text" name="timezone" class="form-control" value="<?= htmlspecialchars($settings['timezone'] ?? 'Asia/Karachi') ?>">
    </div>
    <div class="col-md-6">
        <div class="form-check">
            <input type="checkbox" name="enable_inventory" class="form-check-input" <?= (!empty($settings['enable_inventory']))?'checked':'' ?>>
            <label class="form-check-label">Enable Inventory</label>
        </div>
        <div class="form-check">
            <input type="checkbox" name="enable_wholesale" class="form-check-input" <?= (!empty($settings['enable_wholesale']))?'checked':'' ?>>
            <label class="form-check-label">Enable Wholesale</label>
        </div>
        <div class="form-check">
            <input type="checkbox" name="enable_fbr" class="form-check-input" <?= (!empty($settings['enable_fbr']))?'checked':'' ?>>
            <label class="form-check-label">Enable FBR</label>
        </div>
    </div>
</div>

<button class="btn btn-primary">Save Settings</button>
</form>

</div>
<?php include('partials/footer.php'); ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
