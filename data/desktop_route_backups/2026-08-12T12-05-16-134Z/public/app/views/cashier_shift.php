<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = $_SESSION['user_id'];
$logged_role_id   = (int)($_SESSION['role_id'] ?? 0);
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);


/* ================= ACTIONS: EDIT / DELETE CASHIER SHIFT GROUP ================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shift_action'])) {

    $shift_action = $_POST['shift_action'] ?? '';
    $action_date  = $_POST['action_date'] ?? '';
    $action_branch_id = (int)($_POST['action_branch_id'] ?? 0);
    $action_user_id   = (int)($_POST['action_user_id'] ?? 0);

    if ($action_date === '' || $action_branch_id <= 0 || $action_user_id <= 0) {
        $_SESSION['error'] = "Invalid cashier shift selected.";
        header("Location: cashier_shift.php");
        exit;
    }

    $actionWhere = " WHERE DATE(created_at) = ? AND branch_id = ? AND user_id = ? ";
    $actionParams = [$action_date, $action_branch_id, $action_user_id];
    $actionTypes = "sii";

    if ($logged_role_id !== 1) {
        $actionWhere .= " AND client_id = ? ";
        $actionParams[] = $logged_client_id;
        $actionTypes .= "i";
    }

    /* DELETE SHIFT GROUP */
    if ($shift_action === 'delete_shift') {
        try {
            $conn->begin_transaction();

            $saleIds = [];

            $saleSql = "SELECT id FROM sales " . $actionWhere;
            $saleStmt = $conn->prepare($saleSql);
            $saleStmt->bind_param($actionTypes, ...$actionParams);
            $saleStmt->execute();
            $saleResult = $saleStmt->get_result();

            while ($sale = $saleResult->fetch_assoc()) {
                $saleIds[] = (int)$sale['id'];
            }

            $saleStmt->close();

            if (empty($saleIds)) {
                $conn->rollback();
                $_SESSION['error'] = "No sales found for selected cashier shift.";
                header("Location: cashier_shift.php");
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $idTypes = str_repeat('i', count($saleIds));

            $delItems = $conn->prepare("DELETE FROM sale_items WHERE sale_id IN ($placeholders)");
            $delItems->bind_param($idTypes, ...$saleIds);
            $delItems->execute();
            $delItems->close();

            $delSales = $conn->prepare("DELETE FROM sales WHERE id IN ($placeholders)");
            $delSales->bind_param($idTypes, ...$saleIds);
            $delSales->execute();
            $delSales->close();

            $conn->commit();

            $_SESSION['success'] = "Cashier shift deleted successfully.";
            header("Location: cashier_shift.php");
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error'] = "Delete failed: " . $e->getMessage();
            header("Location: cashier_shift.php");
            exit;
        }
    }

    /* EDIT SHIFT GROUP */
    if ($shift_action === 'edit_shift') {

        $new_date      = $_POST['new_date'] ?? '';
        $new_branch_id = (int)($_POST['new_branch_id'] ?? 0);
        $new_user_id   = (int)($_POST['new_user_id'] ?? 0);

        if ($new_date === '' || $new_branch_id <= 0 || $new_user_id <= 0) {
            $_SESSION['error'] = "Date, branch and cashier are required.";
            header("Location: cashier_shift.php");
            exit;
        }

        try {
            $editSql = "
                UPDATE sales 
                SET 
                    created_at = CONCAT(?, ' ', TIME(created_at)),
                    branch_id = ?,
                    user_id = ?
                $actionWhere
            ";

            $editTypes = "sii" . $actionTypes;
            $editParams = [$new_date, $new_branch_id, $new_user_id, ...$actionParams];

            $editStmt = $conn->prepare($editSql);
            $editStmt->bind_param($editTypes, ...$editParams);
            $editStmt->execute();
            $editStmt->close();

            $_SESSION['success'] = "Cashier shift updated successfully.";
            header("Location: cashier_shift.php");
            exit;

        } catch (Throwable $e) {
            $_SESSION['error'] = "Update failed: " . $e->getMessage();
            header("Location: cashier_shift.php");
            exit;
        }
    }
}

/* ================= FETCH CLIENTS ================= */
$clients = [];
if ($logged_role_id === 1) {
    $res = $conn->query("SELECT id, business_name FROM clients ORDER BY business_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $clients[] = $row;
        }
    }
} else {
    $stmt = $conn->prepare("SELECT id, business_name FROM clients WHERE id = ?");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $clients[] = $row;
    }
    $stmt->close();
}

/* ================= FILTERS ================= */
$filter_client = $_POST['client_id'] ?? '';
$filter_branch = $_POST['branch_id'] ?? '';
$filter_from   = $_POST['from_date'] ?? '';
$filter_to     = $_POST['to_date'] ?? '';

/* ================= FETCH BRANCHES ================= */
$branches = [];
if ($logged_role_id === 1 && $filter_client !== '') {
    $stmt = $conn->prepare("SELECT id, name AS branch_name FROM branches WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $filter_client);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $branches[] = $row;
    }
    $stmt->close();
} elseif ($logged_role_id !== 1) {
    $stmt = $conn->prepare("SELECT id, name AS branch_name FROM branches WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $branches[] = $row;
    }
    $stmt->close();
}

/* ================= FETCH USERS ================= */
$users = [];
if ($logged_role_id === 1) {
    $res = $conn->query("SELECT id, name FROM users ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $users[(int)$row['id']] = $row['name'];
        }
    }
} else {
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $users[(int)$row['id']] = $row['name'];
    }
    $stmt->close();
}

/* ================= FETCH CASHIER SHIFT DATA ================= */
$params = [];
$types  = '';
$where  = " WHERE 1=1 ";

if ($logged_role_id !== 1) {
    $where .= " AND client_id = ? ";
    $params[] = $logged_client_id;
    $types .= 'i';
} elseif ($filter_client !== '') {
    $where .= " AND client_id = ? ";
    $params[] = (int)$filter_client;
    $types .= 'i';
}

if ($filter_branch !== '') {
    $where .= " AND branch_id = ? ";
    $params[] = (int)$filter_branch;
    $types .= 'i';
}

if ($filter_from !== '') {
    $where .= " AND DATE(created_at) >= ? ";
    $params[] = $filter_from;
    $types .= 's';
}

if ($filter_to !== '') {
    $where .= " AND DATE(created_at) <= ? ";
    $params[] = $filter_to;
    $types .= 's';
}

$sql = "SELECT 
            branch_id,
            user_id,
            DATE(created_at) AS sale_date,
            COUNT(id) AS invoice_count,
            COALESCE(SUM(total), 0) AS total_sales,
            COALESCE(SUM(discount), 0) AS total_discount,
            COALESCE(SUM(tax), 0) AS total_tax
        FROM sales
        $where
        GROUP BY branch_id, user_id, DATE(created_at)
        ORDER BY DATE(created_at) DESC, branch_id ASC, user_id ASC";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("SQL Prepare Error: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();
$shift_data = [];
while ($row = $res->fetch_assoc()) {
    $shift_data[] = $row;
}
$stmt->close();

/* ================= SUMMARY ================= */
$totalInvoices = 0;
$totalSales = 0;
$totalDiscount = 0;
$totalTax = 0;

foreach ($shift_data as $row) {
    $totalInvoices += (int)($row['invoice_count'] ?? 0);
    $totalSales += (float)($row['total_sales'] ?? 0);
    $totalDiscount += (float)($row['total_discount'] ?? 0);
    $totalTax += (float)($row['total_tax'] ?? 0);
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function findBranchName($branches, $branchId) {
    foreach ($branches as $b) {
        if ((int)$b['id'] === (int)$branchId) {
            return $b['branch_name'];
        }
    }
    return 'N/A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cashier Shift Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>html, body{
    min-height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-panel-2:#111827;
    --oms-card:#101827;
    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-muted-2:#64748b;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-orange:#f97316;
    --oms-yellow:#f59e0b;
    --oms-cyan:#06b6d4;
    --oms-red:#ef4444;
}

body{
    margin:0;
    font-family:"Inter","Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    color:var(--oms-text);
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%);
    overflow-x:hidden;
}

/* ================= LAYOUT ================= */
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

.sidebar .sidebar-wrap,
#dashboardSidebar .sidebar-wrap,
#sidebar .sidebar-wrap{
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

.sidebar .sidebar-inner,
#dashboardSidebar .sidebar-inner,
#sidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

.sidebar .sidebar-menu,
#dashboardSidebar .sidebar-menu,
#sidebar .sidebar-menu{
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

.topbar{
    min-height:70px;
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

/* ================= SIDEBAR COLLAPSE ================= */
body.dashboard-sidebar-collapsed .sidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed .sidebar .search-box,
body.dashboard-sidebar-collapsed .sidebar .menu-title,
body.dashboard-sidebar-collapsed .sidebar .menu-arrow,
body.dashboard-sidebar-collapsed .sidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-icon{
    margin:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .sidebar-inner{
    padding-left:8px !important;
    padding-right:8px !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn{
    width:52px !important;
    height:42px !important;
    margin:0 auto !important;
    padding:0 !important;
    border-radius:12px !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn span{
    display:none !important;
}

/* ================= HEADER ================= */
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.page-title{
    font-size:28px;
    font-weight:900;
    color:#f8fafc;
    margin:0;
    letter-spacing:-.03em;
}

.header-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.btn-soft{
    border:none;
    border-radius:13px;
    padding:11px 17px;
    color:#fff;
    font-weight:900;
    font-size:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    text-decoration:none;
    cursor:pointer;
    box-shadow:0 14px 28px rgba(0,0,0,.20);
    transition:.22s ease;
    height:46px;
}

.btn-soft:hover{
    transform:translateY(-1px);
    color:#fff;
}

.btn-filter{
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    box-shadow:0 14px 28px rgba(109,76,255,.24);
}

.btn-reset{
    background:rgba(148,163,184,.18);
    border:1px solid rgba(148,163,184,.18);
    color:#e5e7eb;
}

.btn-reset:hover{
    background:rgba(148,163,184,.28);
}

/* ================= ALERTS ================= */
.alert{
    border-radius:14px;
    padding:12px 14px;
    font-weight:800;
    font-size:14px;
    backdrop-filter:blur(14px);
}

.alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.26) !important;
}

.alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

.alert-warning{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.26) !important;
    border-radius:14px;
    font-weight:800;
}

/* ================= STAT CARDS ================= */
.stats-row{
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:16px;
    margin-bottom:18px;
}

.stat-card{
    min-height:112px;
    border-radius:18px;
    padding:20px 22px;
    color:#fff;
    position:relative;
    overflow:visible;
    border:1px solid rgba(255,255,255,.10);
    box-shadow:0 18px 40px rgba(0,0,0,.28);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    isolation:isolate;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease;
    min-width:0;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    border-radius:18px;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:0;
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
    z-index:0;
    opacity:.45;
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22);
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18);
}

.stat-card > div{
    position:relative;
    z-index:3;
}

.stat-card .title{
    font-size:15px;
    font-weight:800;
    color:rgba(255,255,255,.96);
}

.stat-card .sub{
    font-size:13px;
    opacity:.88;
    margin-top:6px;
    font-weight:600;
}

.stat-card .count{
    flex:1;
    min-width:0;
    font-size:clamp(20px, 2vw, 30px);
    font-weight:900;
    line-height:1.1;
    text-align:right;
    letter-spacing:-.03em;
    word-break:break-word;
    overflow-wrap:anywhere;
    white-space:normal;
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff); }
.card-green{ background:linear-gradient(135deg,#047857,#16a34a); }
.card-cyan{ background:linear-gradient(135deg,#0891b2,#06b6d4); }
.card-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c); }

/* ================= FILTER BAR ================= */
.filter-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46));
    border:1px solid rgba(148,163,184,.16);
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045);
    border-radius:18px;
    padding:14px;
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:12px;
    align-items:end;
    margin-bottom:18px;
}

.field-group{
    display:flex;
    flex-direction:column;
    gap:7px;
    min-width:0;
    flex:1;
}

.field-group label{
    font-size:14px;
    font-weight:900;
    color:#cbd5e1;
    margin:0;
}

.filter-control{
    width:100%;
    height:46px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:13px;
    padding:0 14px;
    outline:none;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-weight:700;
    box-shadow:none;
    transition:.2s;
}

.filter-control:focus{
    background:#111827;
    color:#fff;
    border-color:rgba(109,76,255,.55);
    box-shadow:0 0 0 3px rgba(109,76,255,.14);
}

.filter-control option{
    background:#0f172a;
    color:#e5e7eb;
}

/* ================= TABLE ================= */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46));
    border:1px solid rgba(148,163,184,.16);
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045);
    border-radius:18px;
    overflow:hidden;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, background .25s ease;
}

.table-card:hover{
    border-color:rgba(139,92,246,.45);
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06);
}

.table-responsive{
    overflow:auto;
}

.report-table{
    width:100%;
    min-width:900px;
    border-collapse:collapse;
    background:transparent;
}

.report-table th:first-child,
.report-table td:first-child{
    width:48px;
    text-align:center;
}

.report-table thead th{
    background:rgba(7,11,20,.58);
    color:#cbd5e1;
    font-weight:900;
    font-size:13px;
    padding:15px 14px;
    border-bottom:1px solid rgba(148,163,184,.16);
    border-right:1px solid rgba(148,163,184,.08);
    white-space:nowrap;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.report-table tbody td{
    padding:15px 14px;
    border-top:1px solid rgba(148,163,184,.10);
    vertical-align:middle;
    font-size:14px;
    color:#e5e7eb;
    font-weight:600;
    background:rgba(15,23,42,.20);
}

.report-table tbody tr:hover td{
    background:rgba(124,58,237,.12);
    color:#fff;
}

.report-table input[type="checkbox"]{
    width:16px;
    height:16px;
    accent-color:#7c3aed;
}

.amount-strong{
    font-weight:900;
    color:#f8fafc;
}

/* ================= ACTION BUTTONS ================= */
.action-wrap{
    display:flex;
    align-items:center;
    gap:10px;
}

.action-btn{
    width:40px;
    height:40px;
    border:none;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:.2s ease;
    font-size:15px;
    box-shadow:0 12px 24px rgba(0,0,0,.18);
}

.action-btn.edit{
    background:rgba(245,158,11,.15);
    color:#fbbf24;
    border:1px solid rgba(245,158,11,.24);
}

.action-btn.edit:hover{
    background:#f59e0b;
    color:#07111f;
    transform:translateY(-2px);
}

.action-btn.delete{
    background:rgba(239,68,68,.14);
    color:#f87171;
    border:1px solid rgba(239,68,68,.24);
}

.action-btn.delete:hover{
    background:#ef4444;
    color:#fff;
    transform:translateY(-2px);
}

/* ================= FOOTER ================= */
.table-footer{
    padding:16px;
    border-top:1px solid rgba(148,163,184,.13);
    background:rgba(7,11,20,.24);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.footer-note{
    color:#94a3b8;
    font-size:14px;
    font-weight:700;
}

.footer-note strong{
    color:#f8fafc;
}

.empty-state{
    text-align:center;
    padding:30px 16px !important;
    color:#94a3b8 !important;
    font-size:14px;
    font-weight:700;
    background:rgba(15,23,42,.34) !important;
}

/* ================= MODAL DARK ================= */
.modal-content{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.94));
    border:1px solid rgba(148,163,184,.22);
    border-radius:18px;
    color:#f8fafc;
    box-shadow:0 24px 70px rgba(0,0,0,.55);
}

.modal-header,
.modal-footer{
    border-color:rgba(148,163,184,.14);
}

.modal-title{
    font-weight:900;
}

.btn-close{
    filter:invert(1);
    opacity:.75;
}

.form-label{
    color:#cbd5e1;
    font-weight:900;
}

.form-control{
    height:46px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:13px;
    padding:0 14px;
    outline:none;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-weight:700;
    box-shadow:none;
}

.form-control:focus{
    background:#111827;
    color:#fff;
    border-color:rgba(109,76,255,.55);
    box-shadow:0 0 0 3px rgba(109,76,255,.14);
}

.form-control option{
    background:#0f172a;
    color:#e5e7eb;
}

.btn-primary{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.45) !important;
    border-radius:12px;
    font-weight:900;
    box-shadow:0 14px 28px rgba(109,76,255,.24);
}

.btn-secondary{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px;
    font-weight:900;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1300px){
    .stat-card{
        padding:18px 16px;
        gap:10px;
    }

    .stat-card .count{
        font-size:clamp(18px, 1.55vw, 26px);
    }
}

@media (max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2,1fr);
    }

    .filter-bar{
        grid-template-columns:1fr 1fr;
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

    .sidebar .sidebar-wrap,
    #dashboardSidebar .sidebar-wrap,
    #sidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    .sidebar .sidebar-inner,
    #dashboardSidebar .sidebar-inner,
    #sidebar .sidebar-inner{
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

    .filter-bar{
        grid-template-columns:1fr;
    }

    .field-group,
    .btn-soft{
        width:100%;
        min-width:100%;
    }

    .page-title{
        font-size:24px;
    }

    .stat-card{
        min-height:112px;
        align-items:flex-start;
        flex-direction:column;
    }

    .stat-card .count{
        width:100%;
        text-align:left;
        font-size:26px;
    }
}
/* =====================================================
   CASHIER SHIFT MOBILE FINAL FIX
   Header buttons one line + 2 cards + filter alignment
   Paste at END before 
===================================================== */

@media (max-width:768px){

    body{
        overflow-x:hidden !important;
    }

    .content-wrapper{
        padding:16px 12px 24px 12px !important;
    }

    .page-header{
        display:flex !important;
        align-items:flex-start !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .page-title{
        width:100% !important;
        font-size:24px !important;
        line-height:1.15 !important;
        margin-bottom:0 !important;
    }

    /* Filter + Reset buttons one line */
    .header-actions{
        width:100% !important;
        display:grid !important;
        grid-template-columns:1fr 1fr !important;
        gap:8px !important;
        flex-wrap:nowrap !important;
    }

    .header-actions .btn-soft,
    .btn-soft{
        width:100% !important;
        min-width:0 !important;
        height:44px !important;
        padding:0 10px !important;
        border-radius:14px !important;
        font-size:13px !important;
        justify-content:center !important;
        white-space:nowrap !important;
    }

    .header-actions .btn-soft i{
        font-size:14px !important;
        margin-right:2px !important;
    }

    /* Cards 2 in one line */
    .stats-row{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .stat-card{
        min-height:86px !important;
        border-radius:16px !important;
        padding:12px 10px !important;
        gap:6px !important;
        display:flex !important;
        flex-direction:column !important;
        align-items:flex-start !important;
        justify-content:center !important;
        overflow:hidden !important;
    }

    .stat-card .title{
        font-size:12px !important;
        line-height:1.15 !important;
        font-weight:900 !important;
        margin:0 !important;
    }

    .stat-card .sub{
        font-size:10px !important;
        line-height:1.15 !important;
        margin-top:4px !important;
    }

    .stat-card .count{
        width:100% !important;
        text-align:left !important;
        font-size:18px !important;
        line-height:1.08 !important;
        margin-top:6px !important;
        word-break:break-word !important;
        overflow-wrap:anywhere !important;
    }

    .stat-card::after{
        width:48px !important;
        height:48px !important;
        right:8px !important;
        top:10px !important;
        border-radius:16px !important;
        opacity:.35 !important;
    }

    /* Filter form box alignment */
    .filter-bar{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        padding:12px !important;
        border-radius:18px !important;
        margin-bottom:14px !important;
        align-items:end !important;
    }

    .field-group{
        width:100% !important;
        min-width:0 !important;
        gap:5px !important;
    }

    .field-group label{
        font-size:12px !important;
        line-height:1.2 !important;
        margin-bottom:2px !important;
    }

    .filter-control{
        width:100% !important;
        min-width:0 !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:12px !important;
        padding:0 10px !important;
    }

    .table-card{
        border-radius:18px !important;
        margin-top:0 !important;
    }
}

/* Extra small 328px / 360px */
@media (max-width:380px){

    .content-wrapper{
        padding-left:10px !important;
        padding-right:10px !important;
    }

    .page-title{
        font-size:23px !important;
    }

    .header-actions{
        gap:7px !important;
    }

    .header-actions .btn-soft{
        height:42px !important;
        font-size:12px !important;
        border-radius:13px !important;
    }

    .stats-row{
        gap:9px !important;
    }

    .stat-card{
        min-height:82px !important;
        padding:10px 9px !important;
    }

    .stat-card .title{
        font-size:11px !important;
    }

    .stat-card .sub{
        font-size:9px !important;
    }

    .stat-card .count{
        font-size:16px !important;
    }

    .filter-bar{
        gap:9px !important;
        padding:11px !important;
    }

    .filter-control{
        height:40px !important;
        font-size:11px !important;
    }
}
</style>
</head>
<body>
<div class="container-scroll">

    <div class="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title">Cashier Shift Report</h1>

                <div class="header-actions">
                    <button type="submit" form="cashierFilterForm" class="btn-soft btn-filter">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                    <a href="cashier_shift.php" class="btn-soft btn-reset">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div>
                        <div class="title">Total Invoices</div>
                        <div class="sub">Filtered result</div>
                    </div>
                    <div class="count"><?= (int)$totalInvoices ?></div>
                </div>

                <div class="stat-card card-green">
                    <div>
                        <div class="title">Total Sales</div>
                        <div class="sub">Overall amount</div>
                    </div>
                    <div class="count"><?= number_format((float)$totalSales, 2) ?></div>
                </div>

                <div class="stat-card card-cyan">
                    <div>
                        <div class="title">Total Discount</div>
                        <div class="sub">Applied discounts</div>
                    </div>
                    <div class="count"><?= number_format((float)$totalDiscount, 2) ?></div>
                </div>

                <div class="stat-card card-yellow">
                    <div>
                        <div class="title">Total Tax</div>
                        <div class="sub">Collected tax</div>
                    </div>
                    <div class="count"><?= number_format((float)$totalTax, 2) ?></div>
                </div>
            </div>

            <form method="post" id="cashierFilterForm" class="filter-bar">

                <?php if ($logged_role_id === 1): ?>
                <div class="field-group">
                    <label>Client</label>
                    <select name="client_id" id="clientSelect" class="filter-control">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ((string)$filter_client === (string)$c['id']) ? 'selected' : '' ?>>
                                <?= e($c['business_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="field-group">
                    <label>Branch</label>
                    <select name="branch_id" id="branchSelect" class="filter-control">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= ((string)$filter_branch === (string)$b['id']) ? 'selected' : '' ?>>
                                <?= e($b['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group">
                    <label>From Date</label>
                    <input type="date" name="from_date" class="filter-control" value="<?= e($filter_from) ?>">
                </div>

                <div class="field-group">
                    <label>To Date</label>
                    <input type="date" name="to_date" class="filter-control" value="<?= e($filter_to) ?>">
                </div>
            </form>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success mb-3">
                    <?= e($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger mb-3">
                    <?= e($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="table-card">
                <div class="table-responsive">
                    <table class="report-table">
                       
                       <thead>
    <tr>
        <th><input type="checkbox" id="checkAll"></th>
        <th>Date</th>
        <th>Branch</th>
        <th>Cashier</th>
        <th>Invoices</th>
        <th>Total Sales</th>
        <th>Discount</th>
        <th>Tax</th>
        <th>Action</th>
    </tr>
</thead>

<tbody>
    <?php if (!empty($shift_data)): ?>
        <?php foreach ($shift_data as $d): ?>
            <?php
                $rowKey = md5(
                    ($d['sale_date'] ?? '') . '|' .
                    ($d['branch_id'] ?? '') . '|' .
                    ($d['user_id'] ?? '')
                );
            ?>

            <tr>
                <td>
                    <input 
                        type="checkbox" 
                        class="row-check"
                        value="<?= e($rowKey) ?>"
                        data-date="<?= e($d['sale_date']) ?>"
                        data-branch="<?= e($d['branch_id']) ?>"
                        data-user="<?= e($d['user_id']) ?>"
                    >
                </td>

                <td><?= e($d['sale_date']) ?></td>

                <td><?= e(findBranchName($branches, $d['branch_id'])) ?></td>

                <td><?= e($users[(int)$d['user_id']] ?? 'Unknown') ?></td>

                <td><?= (int)($d['invoice_count'] ?? 0) ?></td>

                <td>
                    <span class="amount-strong">
                        <?= number_format((float)($d['total_sales'] ?? 0), 2) ?>
                    </span>
                </td>

                <td><?= number_format((float)($d['total_discount'] ?? 0), 2) ?></td>

                <td><?= number_format((float)($d['total_tax'] ?? 0), 2) ?></td>

                <td>
                    <div class="action-wrap">

                        <button
                            type="button"
                            class="action-btn edit"
                            title="Edit"
                            onclick="openEditShiftModal(
                                '<?= e($d['sale_date'] ?? '') ?>',
                                '<?= e($d['branch_id'] ?? '') ?>',
                                '<?= e($d['user_id'] ?? '') ?>'
                            )"
                        >
                            <i class="bi bi-pencil-fill"></i>
                        </button>

                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this cashier shift? This will delete all invoices of this cashier/date/branch.');">
                            <input type="hidden" name="shift_action" value="delete_shift">
                            <input type="hidden" name="action_date" value="<?= e($d['sale_date'] ?? '') ?>">
                            <input type="hidden" name="action_branch_id" value="<?= e($d['branch_id'] ?? '') ?>">
                            <input type="hidden" name="action_user_id" value="<?= e($d['user_id'] ?? '') ?>">

                            <button type="submit" class="action-btn delete" title="Delete">
                                <i class="bi bi-trash-fill"></i>
                            </button>
                        </form>

                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="9" class="empty-state">No records found</td>
        </tr>
    <?php endif; ?>
</tbody>
                                    </table>
                </div>

                <div class="table-footer">
                    <div class="footer-note">Total Rows: <strong><?= count($shift_data) ?></strong></div>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="editShiftModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-fill me-2"></i>Edit Cashier Shift
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <input type="hidden" name="shift_action" value="edit_shift">
                <input type="hidden" name="action_date" id="editOldDate">
                <input type="hidden" name="action_branch_id" id="editOldBranchId">
                <input type="hidden" name="action_user_id" id="editOldUserId">

                <div class="mb-3">
                    <label class="form-label">New Date</label>
                    <input type="date" name="new_date" id="editNewDate" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Branch</label>
                    <select name="new_branch_id" id="editNewBranchId" class="form-control" required>
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">
                                <?= e($b['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Cashier</label>
                    <select name="new_user_id" id="editNewUserId" class="form-control" required>
                        <option value="">Select Cashier</option>
                        <?php foreach ($users as $uid => $uname): ?>
                            <option value="<?= (int)$uid ?>">
                                <?= e($uname) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="alert alert-warning mb-0">
                    Ye update selected date + branch + cashier ki sari invoices par apply hoga.
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2-circle me-1"></i> Save Changes
                </button>
            </div>

        </form>
    </div>
</div>

<script>
function openEditShiftModal(oldDate, oldBranchId, oldUserId) {
    document.getElementById('editOldDate').value = oldDate;
    document.getElementById('editOldBranchId').value = oldBranchId;
    document.getElementById('editOldUserId').value = oldUserId;

    document.getElementById('editNewDate').value = oldDate;
    document.getElementById('editNewBranchId').value = oldBranchId;
    document.getElementById('editNewUserId').value = oldUserId;

    const modalEl = document.getElementById('editShiftModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($logged_role_id === 1): ?>
<script>
document.getElementById('clientSelect')?.addEventListener('change', function () {
    let clientId = this.value;
    let branchSelect = document.getElementById('branchSelect');
    branchSelect.innerHTML = '<option value="">Loading...</option>';

    fetch('fetch_branches.php?client_id=' + encodeURIComponent(clientId))
        .then(res => res.json())
        .then(data => {
            branchSelect.innerHTML = '<option value="">All Branches</option>';
            data.forEach(function (b) {
                let opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = b.branch_name;
                branchSelect.appendChild(opt);
            });
        })
        .catch(() => {
            branchSelect.innerHTML = '<option value="">All Branches</option>';
        });
});
</script>
<?php endif; ?>
<script>
const checkAll = document.getElementById('checkAll');

checkAll?.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.checked = this.checked;
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;

    const savedState = localStorage.getItem('dashboard_sidebar_state');
    if (savedState === 'collapsed') {
        body.classList.add('dashboard-sidebar-collapsed');
    }

    const toggleSelectors = [
        '#sidebarToggle',
        '#toggleSidebar',
        '.sidebar-toggle',
        '.sidebar-toggler',
        '.topbar-menu-toggle',
        '.hamburger-btn',
        '[data-sidebar-toggle]',
        '.topbar .bi-list',
        '.topbar .fa-bars'
    ];

    let toggleBtn = null;

    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();

            body.classList.toggle('dashboard-sidebar-collapsed');

            if (body.classList.contains('dashboard-sidebar-collapsed')) {
                localStorage.setItem('dashboard_sidebar_state', 'collapsed');
            } else {
                localStorage.setItem('dashboard_sidebar_state', 'open');
            }
        });
    }
});
</script>
</body>
</html>