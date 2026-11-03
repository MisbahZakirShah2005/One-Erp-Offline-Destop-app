<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

include('partials/performance.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

// ================= AUTH CHECK =================
if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
} 

$logged_role_id = $_SESSION['role_id']; // 1 = super admin
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);
$is_super_admin   = ((int)$logged_role_id === 1);

if (!$is_super_admin && $logged_client_id <= 0) {
    die("Client context missing. Please login again.");
}
// ================= AJAX UPDATE / SUPER ADMIN ONLY =================
if (isset($_POST['ajax']) && $_POST['ajax'] === 'update_wholesale') {

    if (!$is_super_admin) {
        die("Unauthorized");
    }

    $client_id = (int)($_POST['client_id'] ?? 0);
    $enabled   = (int)($_POST['enabled'] ?? 0);

    if ($client_id <= 0) {
        die("Invalid client ID");
    }

    $stmt = $conn->prepare("SELECT id FROM client_feature_overrides WHERE client_id=? AND feature_key='wholesale'");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE client_feature_overrides SET enabled=? WHERE client_id=? AND feature_key='wholesale'");
        $stmt->bind_param("ii", $enabled, $client_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO client_feature_overrides (client_id, feature_key, enabled) VALUES (?, 'wholesale', ?)");
        $stmt->bind_param("ii", $client_id, $enabled);
    }

    echo $stmt->execute() ? "ok" : "Database error";
    exit;
}

// ================= ADD BUSINESS / WHOLESALER =================
if (isset($_POST['add_business'])) {

    $business_name   = trim($_POST['business_name'] ?? '');
    $tenant_code     = trim($_POST['tenant_code'] ?? '');
    $subscription    = trim($_POST['subscription_plan'] ?? 'Standard');
    $enableWholesale = isset($_POST['enable_wholesale']) ? 1 : 0;
    $effective_from  = trim($_POST['effective_from'] ?? '');
    $expiry_date     = trim($_POST['expiry_date'] ?? '');
    $min_order_qty   = (int)($_POST['min_order_qty'] ?? 10);
    $min_order_value = (float)($_POST['min_order_value'] ?? 0);
    $assigned_price  = trim($_POST['assigned_price_list'] ?? 'Default Price List');

    if ($business_name === '') {
        $_SESSION['flash_error'] = "Business Name is required.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($is_super_admin) {
        if ($tenant_code === '') {
            $_SESSION['flash_error'] = "Tenant Code is required for admin business.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $checkStmt = $conn->prepare("SELECT id FROM clients WHERE tenant_code = ?");
        $checkStmt->bind_param("s", $tenant_code);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();

        if ($checkRes && $checkRes->num_rows > 0) {
            $_SESSION['flash_error'] = "Tenant / Store Code already exists.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $insertStmt = $conn->prepare("
            INSERT INTO clients (business_name, tenant_code)
            VALUES (?, ?)
        ");
        $insertStmt->bind_param("ss", $business_name, $tenant_code);

        if ($insertStmt->execute()) {
            $newClientId = $insertStmt->insert_id;

            $featureStmt = $conn->prepare("
                INSERT INTO client_feature_overrides (client_id, feature_key, enabled)
                VALUES (?, 'wholesale', ?)
            ");
            $featureStmt->bind_param("ii", $newClientId, $enableWholesale);
            $featureStmt->execute();

            $_SESSION['flash_success'] = "Business added successfully.";
        } else {
            $_SESSION['flash_error'] = "Failed to add business.";
        }
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO wholesale_customers
            (
                client_id,
                business_name,
                tenant_code,
                subscription_plan,
                status,
                effective_from,
                expiry_date,
                min_order_qty,
                min_order_value,
                assigned_price_list,
                created_by
            )
            VALUES (?, ?, ?, ?, 'active', NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?)
        ");

        $insertStmt->bind_param(
            "isssssidsi",
            $logged_client_id,
            $business_name,
            $tenant_code,
            $subscription,
            $effective_from,
            $expiry_date,
            $min_order_qty,
            $min_order_value,
            $assigned_price,
            $_SESSION['user_id']
        );

        if ($insertStmt->execute()) {
            $_SESSION['flash_success'] = "Wholesaler added successfully.";
        } else {
            $_SESSION['flash_error'] = "Failed to add wholesaler: " . $conn->error;
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ================= SEARCH / FILTERS =================
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$plan   = trim($_GET['plan'] ?? '');
$branch = trim($_GET['branch'] ?? '');

// ================= FETCH DATA =================
$clients = false;

$totalBusinesses = 0;
$totalEnabled = 0;
$totalDisabled = 0;
$thisMonthActivations = 0;

if ($is_super_admin) {

    $sql = "
        SELECT 
            c.id,
            c.business_name,
            c.tenant_code,
            'Standard' AS subscription_plan,
            COALESCE(cf.enabled, 0) AS wholesale_enabled,
            NULL AS effective_from,
            10 AS min_order_qty,
            'Default Price List' AS assigned_price_list
        FROM clients c
        LEFT JOIN client_feature_overrides cf 
            ON cf.client_id = c.id 
            AND cf.feature_key = 'wholesale'
        WHERE 1=1
    ";

    $params = [];
    $types  = '';

    if ($search !== '') {
        $sql .= " AND c.business_name LIKE ?";
        $params[] = "%{$search}%";
        $types .= 's';
    }

    if ($status === 'active') {
        $sql .= " AND COALESCE(cf.enabled, 0) = 1";
    }

    if ($status === 'inactive') {
        $sql .= " AND COALESCE(cf.enabled, 0) = 0";
    }

    $sql .= " ORDER BY c.id DESC";

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $clients = $stmt->get_result();

    $statsSql = "
        SELECT 
            COUNT(c.id) AS total_businesses,
            SUM(CASE WHEN COALESCE(cf.enabled, 0) = 1 THEN 1 ELSE 0 END) AS total_enabled,
            SUM(CASE WHEN COALESCE(cf.enabled, 0) = 0 THEN 1 ELSE 0 END) AS total_disabled
        FROM clients c
        LEFT JOIN client_feature_overrides cf 
            ON cf.client_id = c.id 
            AND cf.feature_key = 'wholesale'
        WHERE 1=1
    ";

    $statsStmt = $conn->prepare($statsSql);
    $statsStmt->execute();
    $statsQuery = $statsStmt->get_result();

    if ($statsQuery && $statsRow = $statsQuery->fetch_assoc()) {
        $totalBusinesses = (int)($statsRow['total_businesses'] ?? 0);
        $totalEnabled    = (int)($statsRow['total_enabled'] ?? 0);
        $totalDisabled   = (int)($statsRow['total_disabled'] ?? 0);
    }

    $thisMonthActivations = $totalEnabled;

} else {

    $sql = "
        SELECT
            wc.id,
            wc.business_name,
            wc.tenant_code,
            wc.subscription_plan,
            CASE WHEN wc.status = 'active' THEN 1 ELSE 0 END AS wholesale_enabled,
            wc.effective_from,
            wc.min_order_qty,
            wc.assigned_price_list
        FROM wholesale_customers wc
        WHERE wc.client_id = ?
    ";

    $params = [$logged_client_id];
    $types  = 'i';

    if ($search !== '') {
        $sql .= " AND wc.business_name LIKE ?";
        $params[] = "%{$search}%";
        $types .= 's';
    }

    if ($status === 'active') {
        $sql .= " AND wc.status = 'active'";
    }

    if ($status === 'inactive') {
        $sql .= " AND wc.status = 'inactive'";
    }

    $sql .= " ORDER BY wc.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $clients = $stmt->get_result();

    $statsStmt = $conn->prepare("
        SELECT
            COUNT(id) AS total_businesses,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS total_enabled,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS total_disabled
        FROM wholesale_customers
        WHERE client_id = ?
    ");
    $statsStmt->bind_param("i", $logged_client_id);
    $statsStmt->execute();
    $statsQuery = $statsStmt->get_result();

    if ($statsQuery && $statsRow = $statsQuery->fetch_assoc()) {
        $totalBusinesses = (int)($statsRow['total_businesses'] ?? 0);
        $totalEnabled    = (int)($statsRow['total_enabled'] ?? 0);
        $totalDisabled   = (int)($statsRow['total_disabled'] ?? 0);
    }

    $thisMonthActivations = $totalEnabled;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Wholesale Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php include('partials/head.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-panel-2:#111827;
    --oms-card:#101827;
    --oms-card-soft:#121c31;
    --oms-border:rgba(148,163,184,.14);
    --oms-border-2:rgba(148,163,184,.22);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;

    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-orange:#f59e0b;
    --oms-red:#ef4444;
    --oms-yellow:#f59e0b;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
    color:var(--oms-text);
    overflow-x:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%);
}

/* ================= SIDEBAR / LAYOUT SAME DASHBOARD ================= */
body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

.container-scroll{
    display:flex;
    min-height:100vh;
    align-items:stretch;
}

.sidebar{
    width:var(--current-sidebar-width);
    min-width:var(--current-sidebar-width);
    max-width:var(--current-sidebar-width);
    flex-shrink:0;
    display:flex;
    align-self:stretch;
    background:transparent;
    overflow:visible;
    position:fixed;
    left:0;
    top:0;
    height:100vh;
    z-index:1000;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
}

.sidebar > div{
    width:100%;
    min-height:100vh;
    height:100vh;
}

#dashboardSidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
}

#dashboardSidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

#dashboardSidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.main-panel{
    margin-left:var(--current-sidebar-width);
    flex:1;
    min-width:0;
    min-height:100vh;
    display:flex;
    flex-direction:column;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%);
    transition:margin-left .25s ease;
}

/* Collapsed sidebar support */
body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow,
body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon{
    margin:0 !important;
}

/* ================= TOPBAR ================= */
.topbar{
    height:70px;
    background:#070b14;
    border-bottom:1px solid var(--oms-border);
    display:flex;
    align-items:center;
    padding:0;
    position:sticky;
    top:0;
    z-index:1030;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}

/* ================= CONTENT BACKGROUND ================= */
.content-wrapper{
    position:relative;
    padding:24px 26px;
    flex:1;
    min-height:calc(100vh - 70px);
    overflow:visible;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%);
}

.content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}

.content-wrapper::after{
    content:"";
    position:absolute;
    width:520px;
    height:520px;
    right:-190px;
    top:-210px;
    pointer-events:none;
    z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);
    opacity:.85;
}

.content-wrapper > *{
    position:relative;
    z-index:1;
}

/* ================= HEADER ================= */
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:18px;
    flex-wrap:wrap;
}

.page-title{
    font-size:28px;
    font-weight:900;
    color:#f8fafc;
    margin:0;
    letter-spacing:-.02em;
}

.manage-config-btn{
    min-height:46px;
    border:1px solid rgba(148,163,184,.16);
    border-radius:12px;
    padding:0 16px;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-weight:800;
    display:inline-flex;
    align-items:center;
    gap:8px;
    text-decoration:none;
    box-shadow:0 14px 28px rgba(0,0,0,.24);
    transition:.22s ease;
}

.manage-config-btn:hover{
    color:#fff;
    background:rgba(109,76,255,.18);
    border-color:rgba(139,92,246,.42);
    transform:translateY(-1px);
}

/* ================= STAT CARDS ================= */
.stats-row{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
    margin-bottom:18px;
}

.stat-card{
    min-height:138px;
    border-radius:18px;
    padding:20px 22px;
    color:#fff;
    position:relative;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.10);
    box-shadow:0 18px 40px rgba(0,0,0,.28);
    isolation:isolate;
    display:flex;
    align-items:center;
    justify-content:space-between;
    transition:.25s ease;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22);
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18);
}

.stat-left{
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    gap:10px;
    position:relative;
    z-index:2;
}

.stat-card .icon{
    font-size:30px;
    opacity:.96;
}

.stat-card .title{
    font-size:15px;
    font-weight:800;
    color:rgba(255,255,255,.88);
}

.stat-card .count{
    font-size:30px;
    font-weight:900;
    line-height:1;
    position:relative;
    z-index:2;
}

.card-blue{background:linear-gradient(135deg,#2563eb,#6d4cff);}
.card-green{background:linear-gradient(135deg,#047857,#16a34a);}
.card-red{background:linear-gradient(135deg,#be123c,#ef4444);}
.card-yellow{background:linear-gradient(135deg,#f59e0b,#fb923c);}

/* ================= ALERTS ================= */
.alert,
.custom-alert{
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.58)) !important;
    color:#e5e7eb !important;
    box-shadow:0 16px 36px rgba(0,0,0,.26);
}

.alert-success{
    border-color:rgba(34,197,94,.25) !important;
}

.alert-danger{
    border-color:rgba(239,68,68,.25) !important;
}

.custom-alert{
    padding:14px 16px;
    margin-bottom:16px;
    color:#fbbf24 !important;
}

/* ================= FILTER + ACTION TOOLBAR GLASS ================= */
.filter-bar,
.action-toolbar,
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045);
    border-radius:18px;
}

.filter-bar:hover,
.action-toolbar:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06);
}

.filter-bar{
    padding:14px;
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:14px;
}

.action-toolbar{
    padding:14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.action-toolbar-left,
.action-toolbar-right{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.search-wrap{
    flex:1.6;
    min-width:240px;
    position:relative;
}

.search-wrap i{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#94a3b8;
    font-size:14px;
}

.search-wrap input,
.filter-select,
.bulk-select,
.rows-select{
    width:100%;
    height:46px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:12px;
    padding:0 14px;
    outline:none;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-size:14px;
    font-weight:700;
    box-shadow:0 12px 24px rgba(0,0,0,.16);
}

.search-wrap input{
    padding-left:40px;
}

.search-wrap input::placeholder{
    color:#94a3b8;
}

.search-wrap input:focus,
.filter-select:focus,
.bulk-select:focus,
.rows-select:focus{
    border-color:rgba(109,76,255,.55);
    box-shadow:0 0 0 3px rgba(109,76,255,.14);
    background:#111827;
    color:#fff;
}

.filter-select option,
.bulk-select option,
.rows-select option{
    background:#0f172a;
    color:#e5e7eb;
}

.select-wrap{
    min-width:150px;
}

/* ================= BUTTONS ================= */
.icon-btn,
.mini-btn,
.action-btn{
    width:46px;
    height:46px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:12px;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:16px;
    transition:.22s ease;
    box-shadow:0 12px 24px rgba(0,0,0,.16);
}

.icon-btn:hover,
.mini-btn:hover,
.action-btn:hover{
    color:#fff;
    background:rgba(109,76,255,.18);
    border-color:rgba(139,92,246,.42);
    transform:translateY(-1px);
}

.export-btn,
.add-btn{
    min-height:46px;
    border:none;
    border-radius:12px;
    padding:0 18px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    transition:.22s ease;
}

.export-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    color:#fff;
    box-shadow:0 14px 28px rgba(109,76,255,.24);
}

.add-btn{
    background:linear-gradient(135deg,#f59e0b,#fb923c);
    color:#111827;
    box-shadow:0 14px 28px rgba(245,158,11,.22);
}

.export-btn:hover,
.add-btn:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* ================= TABLE ================= */
.table-card{
    overflow:hidden;
}

.table-responsive{
    overflow:auto;
}

.wholesale-table{
    width:100%;
    min-width:1300px;
    border-collapse:separate;
    border-spacing:0;
    background:transparent;
}

.wholesale-table thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16);
    border-right:1px solid rgba(148,163,184,.08);
    font-size:13px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:14px 12px;
    white-space:nowrap;
}

.wholesale-table tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10);
    padding:14px 12px;
    vertical-align:middle;
    font-size:14px;
    font-weight:600;
    white-space:nowrap;
}

.wholesale-table tbody tr:hover td{
    background:rgba(124,58,237,.12) !important;
    color:#fff !important;
}

.business-main{
    font-weight:900;
    color:#f8fafc;
}

.business-sub{
    display:block;
    font-size:12px;
    color:#94a3b8;
    margin-top:4px;
}

.muted-text,
.pagination-mini{
    color:#94a3b8;
    font-size:13px;
    font-weight:700;
}

/* ================= BADGES ================= */
.status-badge,
.plan-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border-radius:999px;
    padding:7px 12px;
    font-size:12px;
    font-weight:900;
}

.status-enabled{
    background:rgba(34,197,94,.14);
    color:#4ade80;
    border:1px solid rgba(34,197,94,.24);
}

.status-disabled{
    background:rgba(239,68,68,.14);
    color:#f87171;
    border:1px solid rgba(239,68,68,.24);
}

.plan-badge{
    background:rgba(109,76,255,.16);
    color:#c4b5fd;
    border:1px solid rgba(109,76,255,.24);
}

/* ================= DROPDOWN ================= */
.dropdown-menu{
    background:#0f172a;
    border:1px solid rgba(148,163,184,.22);
    border-radius:14px;
    box-shadow:0 24px 60px rgba(0,0,0,.45);
    padding:8px;
}

.dropdown-item{
    border-radius:10px;
    padding:10px 12px;
    color:#e5e7eb;
    font-weight:700;
}

.dropdown-item:hover{
    background:rgba(109,76,255,.18);
    color:#fff;
}

/* ================= BOTTOM ACTIONS ================= */
.bottom-actions{
    padding:14px;
    border-top:1px solid rgba(148,163,184,.14);
    background:rgba(7,11,20,.28);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.pagination-mini{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.table-check{
    width:18px;
    height:18px;
    cursor:pointer;
    accent-color:#7c3aed;
}

/* ================= MODAL DARK GLASS ================= */
body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

#addBusinessModal .modal-dialog{
    max-width:min(860px,94vw) !important;
}

#addBusinessModal .modal-content,
#addBusinessModal .custom-modal{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#fff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    backdrop-filter:blur(18px);
}

#addBusinessModal .modal-header{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:22px 24px !important;
    min-height:76px !important;
}

#addBusinessModal .modal-title{
    color:#fff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#addBusinessModal .modal-title::before{
    content:'' !important;
    width:13px !important;
    height:13px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 5px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#addBusinessModal .btn-close{
    position:relative !important;
    width:46px !important;
    height:46px !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#addBusinessModal .btn-close::before,
#addBusinessModal .btn-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:22px !important;
    height:2px !important;
    background:#fff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#addBusinessModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#addBusinessModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#addBusinessModal .btn-close:hover{
    background:#ef4444 !important;
}

#addBusinessModal .modal-body{
    background:transparent !important;
    color:#fff !important;
    padding:26px 24px 22px !important;
}

#addBusinessModal .modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:18px 24px 22px !important;
}

#addBusinessModal .form-label,
#addBusinessModal label{
    color:#f8fafc !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
}

#addBusinessModal .custom-input,
#addBusinessModal .form-control,
#addBusinessModal .form-select,
#addBusinessModal input[type="text"],
#addBusinessModal input[type="number"],
#addBusinessModal input[type="date"],
#addBusinessModal select{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:12px !important;
    font-size:15px !important;
    font-weight:700 !important;
    min-height:50px !important;
    height:50px !important;
    padding:10px 16px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#addBusinessModal .custom-input:focus,
#addBusinessModal .form-control:focus,
#addBusinessModal .form-select:focus,
#addBusinessModal input:focus,
#addBusinessModal select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14),0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#addBusinessModal .form-select option{
    background:#0f172a;
    color:#e5e7eb;
}

#addBusinessModal input::placeholder{
    color:#94a3b8 !important;
}

#addBusinessModal .input-group-text,
#addBusinessModal .custom-addon{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
    border-radius:0 12px 12px 0 !important;
    font-weight:900 !important;
    min-height:50px !important;
}

#addBusinessModal .wholesale-switch-wrap{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    padding:12px 14px !important;
    color:#fff !important;
    font-weight:800 !important;
}

#addBusinessModal .form-check-input{
    box-shadow:none !important;
    cursor:pointer;
}

#addBusinessModal .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
}

.cancel-btn{
    min-height:44px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
}

.save-btn{
    min-height:44px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.cancel-btn:hover,
.save-btn:hover{
    transform:translateY(-1px);
}

/* ================= RESPONSIVE ================= */
@media (max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2,1fr);
    }
}

@media (max-width:991px){
    .container-scroll{
        flex-direction:column;
    }

    .sidebar{
        width:100%;
        min-width:100%;
        max-width:100%;
        position:relative;
        height:auto;
    }

    #dashboardSidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    #dashboardSidebar .sidebar-inner{
        height:auto !important;
        min-height:auto !important;
    }

    .main-panel{
        margin-left:0;
    }

    .topbar{
        position:relative;
    }

    .content-wrapper{
        padding:18px;
    }
}

@media (max-width:768px){
    .stats-row{
        grid-template-columns:1fr;
    }

    .filter-bar,
    .action-toolbar{
        flex-direction:column;
        align-items:stretch;
    }

    .search-wrap,
    .select-wrap,
    .export-btn,
    .add-btn,
    .bulk-select,
    .manage-config-btn{
        width:100%;
        min-width:100%;
    }

    .action-toolbar-left,
    .action-toolbar-right{
        width:100%;
    }

    .page-title{
        font-size:24px;
    }

    #addBusinessModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #addBusinessModal .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #addBusinessModal .cancel-btn,
    #addBusinessModal .save-btn{
        width:100% !important;
    }
}

/* ================= SMALL COMPACT ADD BUSINESS POPUP ================= */

#addBusinessModal .modal-dialog{
    max-width:min(680px,92vw) !important;
}

#addBusinessModal .modal-content,
#addBusinessModal .custom-modal{
    border-radius:16px !important;
}

#addBusinessModal .modal-header{
    min-height:58px !important;
    padding:14px 18px !important;
}

#addBusinessModal .modal-title{
    font-size:18px !important;
}

#addBusinessModal .modal-title::before{
    width:10px !important;
    height:10px !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
}

#addBusinessModal .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
}

#addBusinessModal .btn-close::before,
#addBusinessModal .btn-close::after{
    width:18px !important;
}

#addBusinessModal .modal-body{
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#addBusinessModal .row.g-3{
    --bs-gutter-x:12px !important;
    --bs-gutter-y:10px !important;
}

#addBusinessModal .form-label,
#addBusinessModal label{
    font-size:12px !important;
    margin-bottom:5px !important;
}

#addBusinessModal .custom-input,
#addBusinessModal .form-control,
#addBusinessModal .form-select,
#addBusinessModal input[type="text"],
#addBusinessModal input[type="number"],
#addBusinessModal input[type="date"],
#addBusinessModal select{
    min-height:40px !important;
    height:40px !important;
    border-radius:10px !important;
    font-size:13px !important;
    padding:7px 12px !important;
}

#addBusinessModal .input-group-text,
#addBusinessModal .custom-addon{
    min-height:40px !important;
    height:40px !important;
    font-size:13px !important;
    padding:7px 12px !important;
}

#addBusinessModal .wholesale-switch-wrap{
    padding:9px 12px !important;
    border-radius:12px !important;
}

#addBusinessModal .form-check-input{
    width:38px !important;
    height:20px !important;
}

#addBusinessModal .modal-footer{
    padding:12px 18px 14px !important;
}

#addBusinessModal .cancel-btn,
#addBusinessModal .save-btn{
    min-height:38px !important;
    height:38px !important;
    min-width:105px !important;
    border-radius:10px !important;
    font-size:13px !important;
    padding:7px 14px !important;
}


</style>
</head>
<body>

<div class="container-scroll">

 <div class="sidebar" id="dashboardSidebar">
         <div class="sidebar" id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>
    </div>
    <div class="main-panel">
        
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">

            <div class="page-header">
                <h4 class="page-title">Wholesale Enable/Disable</h4>

                <?php if ($logged_role_id == 1): ?>
                    <a href="#" class="manage-config-btn">
                        <i class="bi bi-gear"></i>
                        Manage Wholesale Configuration
                    </a>
                <?php endif; ?>
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-briefcase"></i></div>
                        <div class="title">Total Businesses</div>
                    </div>
                    <div class="count"><?= $totalBusinesses ?></div>
                </div>

                <div class="stat-card card-green">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-check-circle"></i></div>
                        <div class="title">Wholesale Enabled</div>
                    </div>
                    <div class="count"><?= $totalEnabled ?></div>
                </div>

                <div class="stat-card card-red">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-x-circle"></i></div>
                        <div class="title">Disabled</div>
                    </div>
                    <div class="count"><?= $totalDisabled ?></div>
                </div>

                <div class="stat-card card-yellow">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-lightning-charge"></i></div>
                        <div class="title">This Month Activations</div>
                    </div>
                    <div class="count"><?= $thisMonthActivations ?></div>
                </div>
            </div>

            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['flash_success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['flash_error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <?php if ($logged_role_id != 1): ?>
                <div class="custom-alert">
                    You have view-only access.
                </div>
            <?php endif; ?>

            <form method="GET" class="filter-bar">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input 
                        type="text" 
                        name="search" 
                        placeholder="Search business..." 
                        value="<?= htmlspecialchars($search) ?>"
                    >
                </div>

                <div class="select-wrap">
                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="select-wrap">
                    <select name="plan" class="filter-select">
                        <option value="">All Plans</option>
                        <option value="standard" <?= $plan === 'standard' ? 'selected' : '' ?>>Standard</option>
                        <option value="premium" <?= $plan === 'premium' ? 'selected' : '' ?>>Premium</option>
                    </select>
                </div>

                <div class="select-wrap">
                    <select name="branch" class="filter-select">
                        <option value="">Branch</option>
                        <option value="main" <?= $branch === 'main' ? 'selected' : '' ?>>Main</option>
                        <option value="secondary" <?= $branch === 'secondary' ? 'selected' : '' ?>>Secondary</option>
                    </select>
                </div>

                <button class="icon-btn" type="button" title="List View">
                    <i class="bi bi-list-ul"></i>
                </button>

                <button class="icon-btn" type="button" title="Columns">
                    <i class="bi bi-layout-three-columns"></i>
                </button>

                <button class="icon-btn" type="button" title="More Filters">
                    <i class="bi bi-funnel"></i>
                </button>

                <button class="export-btn" type="submit" name="export" value="1">
                    Export <i class="bi bi-chevron-down ms-2"></i>
                </button>
            </form>

            <div class="action-toolbar">
                <div class="action-toolbar-left">
                    
<button type="button" class="add-btn" data-bs-toggle="modal" data-bs-target="#addBusinessModal">
    <i class="bi bi-plus-lg"></i>
    <?= $is_super_admin ? 'Add Business' : 'Add Wholesaler' ?>
</button>
                    <select class="bulk-select" id="bulkAction">
                        <option value="">Bulk Actions</option>
                        <option value="enable">Enable Selected</option>
                        <option value="disable">Disable Selected</option>
                    </select>

                    <button type="button" class="mini-btn" id="applyBulkBtn" title="Apply Bulk Action">
                        <i class="bi bi-dash-lg"></i>
                    </button>
                </div>
            </div>

            <div class="table-card">
                <div class="table-responsive">
                    <table class="wholesale-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">
                                    <input type="checkbox" id="checkAll" class="table-check">
                                </th>
                                <th>ID</th>
                                <th>Business Name</th>
                                <th>Tenant Code</th>
                                <th>Subscription Plan</th>
                                <th>Status</th>
                                <th>Effective Date</th>
                                <th>Min Order Qty</th>
                                <th>Assigned Price List</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($clients && $clients->num_rows > 0): ?>
                            <?php while ($c = $clients->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="row-check table-check" value="<?= (int)$c['id'] ?>">
                                    </td>
                                    <td><?= (int)$c['id'] ?></td>
                                    <td>
                                        <span class="business-main"><?= htmlspecialchars($c['business_name']) ?></span>
                                        <span class="business-sub">Client Business Account</span>
                                    </td>
                                    <td><?= htmlspecialchars($c['tenant_code'] ?: ('AZD-' . str_pad($c['id'], 4, '0', STR_PAD_LEFT))) ?></td>
                                    <td>
                                        <span class="plan-badge">
                                            <i class="bi bi-box-seam"></i> Standard
                                        </span>
                                    </td>
                                  <td>
    <span class="status-badge <?= $c['wholesale_enabled'] ? 'status-enabled' : 'status-disabled' ?>">
        <?= $c['wholesale_enabled'] ? 'Enabled' : 'Disabled' ?>
    </span>
</td>
<td>
    <?= !empty($c['effective_from']) ? date('M d, Y', strtotime($c['effective_from'])) : date('M d, Y') ?>
</td>
<td><?= (int)($c['min_order_qty'] ?? 10) ?> Units</td>
<td><?= htmlspecialchars($c['assigned_price_list'] ?? 'Default Price List') ?></td>
                                    <td>
                                        <?php if ($logged_role_id == 1): ?>
                                            <div class="dropdown">
                                                <button class="action-btn" data-bs-toggle="dropdown" type="button">
                                                    <i class="bi bi-three-dots"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <button 
                                                            type="button"
                                                            class="dropdown-item toggleWholesale"
                                                            data-id="<?= $c['id'] ?>"
                                                            data-enabled="<?= $c['wholesale_enabled'] ? 0 : 1 ?>"
                                                        >
                                                            <i class="bi <?= $c['wholesale_enabled'] ? 'bi-x-circle' : 'bi-check-circle' ?>"></i>
                                                            <?= $c['wholesale_enabled'] ? ' Disable' : ' Enable' ?>
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="#">
                                                            <i class="bi bi-sliders"></i> Configure Rules
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="#">
                                                            <i class="bi bi-card-list"></i> Assign Price List
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="#">
                                                            <i class="bi bi-clock-history"></i> View History
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="#">
                                                            <i class="bi bi-box-arrow-up-right"></i> Open Business
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted-text">No Permission</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">No clients found</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bottom-actions">
                    <div class="pagination-mini">
                        <span>Rows per page:</span>
                        <select class="rows-select">
                            <option selected>10</option>
                            <option>25</option>
                            <option>50</option>
                            <option>100</option>
                        </select>
                        <span>
                            Showing <?= ($clients && $clients instanceof mysqli_result) ? $clients->num_rows : 0 ?> of <?= $totalBusinesses ?> items
                        </span>
                    </div>

                    <div class="pagination-mini">
                        <span>Prev</span>
                        <span>1</span>
                        <span>Next</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Add Business Modal -->
<div class="modal fade" id="addBusinessModal" tabindex="-1" aria-labelledby="addBusinessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content custom-modal">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="addBusinessModalLabel">Add Business</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <label class="form-label">Business Name</label>
                            <input type="text" name="business_name" class="form-control custom-input" placeholder="New Business" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tenant / Store Code</label>
                            <input type="text" name="tenant_code" class="form-control custom-input" placeholder="NJ-2027" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Plan Type</label>
                            <select name="plan_type" class="form-select custom-input">
                                <option value="Standard">Standard</option>
                                <option value="Premium">Premium</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Subscription Plan</label>
                            <select name="subscription_plan" class="form-select custom-input">
                                <option value="Standard">Standard</option>
                                <option value="Premium">Premium</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Select Branch</label>
                            <select name="branch_name" class="form-select custom-input">
                                <option value="Central Branch">Central Branch</option>
                                <option value="Main Branch">Main Branch</option>
                                <option value="North Branch">North Branch</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch wholesale-switch-wrap">
                                <input class="form-check-input" type="checkbox" role="switch" id="enableWholesale" name="enable_wholesale" checked>
                                <label class="form-check-label" for="enableWholesale">Enable Wholesale</label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Effective From</label>
                            <input type="date" name="effective_from" class="form-control custom-input" value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control custom-input">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Min Order Qty</label>
                            <div class="input-group">
                                <input type="number" name="min_order_qty" class="form-control custom-input" placeholder="10">
                                <span class="input-group-text custom-addon">Units</span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Min Order Value</label>
                            <input type="number" step="0.01" name="min_order_value" class="form-control custom-input" placeholder="0.00">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Assigned Price List</label>
                            <select name="assigned_price_list" class="form-select custom-input">
                                <option value="Default Price List">Default Price List</option>
                                <option value="Wholesale Price List">Wholesale Price List</option>
                                <option value="Premium Wholesale Price List">Premium Wholesale Price List</option>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_business" class="btn save-btn">Add Business</button>
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
    
document.querySelectorAll('.toggleWholesale').forEach(btn => {
    btn.addEventListener('click', function () {
        const client_id = this.dataset.id;
        const enabled = this.dataset.enabled;

        if (!confirm('Are you sure you want to update wholesale status?')) {
            return;
        }

        fetch("", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: `ajax=update_wholesale&client_id=${client_id}&enabled=${enabled}`
        })
        .then(res => res.text())
        .then(res => {
            if (res.trim() === 'ok') {
                location.reload();
            } else {
                alert(res);
            }
        })
        .catch(() => {
            alert("Request failed");
        });
    });
});

const checkAll = document.getElementById('checkAll');
const rowChecks = document.querySelectorAll('.row-check');

if (checkAll) {
    checkAll.addEventListener('change', function() {
        rowChecks.forEach(cb => cb.checked = this.checked);
    });
}

document.getElementById('applyBulkBtn')?.addEventListener('click', function () {
    const action = document.getElementById('bulkAction').value;
    const selected = Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);

    if (!action) {
        alert('Please select a bulk action');
        return;
    }

    if (selected.length === 0) {
        alert('Please select at least one business');
        return;
    }

    alert('Bulk action UI ready. Backend bulk action ab next step me connect kar denge.');
});

document.querySelectorAll('.filter-select').forEach(function(el){
    el.addEventListener('change', function(){
        this.form.submit();
    });
});
</script>

</body>
</html>