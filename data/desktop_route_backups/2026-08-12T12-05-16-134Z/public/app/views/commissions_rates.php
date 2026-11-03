<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$client_id = (int)($_SESSION['client_id'] ?? 0);
if ($client_id <= 0) die("Invalid client session");

// ================= FETCH STAFF =================
$staff = [];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE client_id=? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $staff[] = $r;
$stmt->close();

// ================= FETCH SERVICES =================
$services = [];
$stmt = $conn->prepare("SELECT id, name FROM services WHERE client_id=? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $services[] = $r;
$stmt->close();

// ================= FETCH BRANCHES =================
$branches = [];
$stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $branches[] = $row;
}
$stmt->close();

// ================= FETCH COMMISSION RATES =================
$rates = [];
$stmt = $conn->prepare("
    SELECT 
        scr.user_id, scr.service_id, scr.percentage, scr.commission_type, scr.fixed_amount, 
        scr.min_sale_target, scr.max_cap, scr.branch_id, scr.status, scr.tiered_config, 
        u.name as staff_name, s.name as service_name, b.name as branch_name
    FROM staff_commission_rates scr
    LEFT JOIN users u ON scr.user_id = u.id
    LEFT JOIN services s ON scr.service_id = s.id
    LEFT JOIN branches b ON scr.branch_id = b.id
    WHERE u.client_id = ? 
    ORDER BY scr.user_id ASC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $rates[] = $r;
$stmt->close();

// ================= CALCULATE DASHBOARD STATS =================
$totalOrders = count($staff);

// Average commission
$commissionSum = 0;
$commissionCount = 0;
$totalAmount = 0;

foreach ($rates as $rate) {
    if ($rate['commission_type'] === 'percentage') {
        $commissionSum += (float)$rate['percentage'];
        $commissionCount++;
    } elseif ($rate['commission_type'] === 'fixed') {
        $commissionSum += (float)$rate['fixed_amount'];
        $commissionCount++;
    }
    if ($rate['status'] === 'approved') {
        $totalAmount += $rate['fixed_amount'] ?? 0;
    }
}
$pendingOrders = $commissionCount ? round($commissionSum / $commissionCount, 2) : 0;

$staffCommissions = [];
foreach ($rates as $rate) {
    if ($rate['status'] === 'approved') {
        $staffCommissions[$rate['staff_name']] = ($staffCommissions[$rate['staff_name']] ?? 0) + ($rate['fixed_amount'] ?? 0);
    }
}
$completedOrders = $staffCommissions ? array_search(max($staffCommissions), $staffCommissions) : 'N/A';

$staffFilter = $_GET['staff_id'] ?? '';
$serviceFilter = $_GET['service_id'] ?? '';
$commissionFilter = $_GET['commission_type'] ?? '';
$branchFilter = trim($_GET['branch_id'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

?>



<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Staff Commission Rates</title>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
     <style>
        *{box-sizing:border-box}
        body{margin:0;background:#eef2f7;font-family:Inter,Arial,sans-serif;color:#1f2736}
        .container-scroll{display:flex;min-height:100vh}
        .sidebar{width:230px;flex-shrink:0}
        .main-panel{flex:1;min-width:0;display:flex;flex-direction:column}
        .topbar{height:64px;background:#05070d;color:#fff;display:flex;align-items:center;padding:0 20px}
        .content-wrapper{padding:18px;flex:1;overflow:auto}
        .page-header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
        .page-title{font-size:22px;font-weight:800;margin:0;color:#243046}
        .btn-green{border:none;border-radius:12px;background:linear-gradient(180deg,#53ae6e,#3e8f58);color:#fff;font-weight:700;padding:12px 18px;min-width:190px}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
        .stat-card{border-radius:14px;padding:18px;min-height:92px;color:#fff;position:relative;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12)}
        .stat-card::after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12)}
        .stat-blue{background:linear-gradient(135deg,#5e84ff,#7fa9ff)}
        .stat-green{background:linear-gradient(135deg,#4ea98d,#66c09f)}
        .stat-cyan{background:linear-gradient(135deg,#56b4da,#7fd4f5)}
        .stat-gold{background:linear-gradient(135deg,#8e7f73,#b9a38b)}
        .stat-title{font-size:14px;font-weight:600;line-height:1.3}
        .stat-value{position:absolute;right:18px;bottom:14px;font-size:30px;font-weight:800;line-height:1}
        .stat-sub{margin-top:6px;font-size:13px;opacity:.95}
        .filter-card,.table-card,.approval-card{background:#fff;border:1px solid #e6ebf3;border-radius:16px;box-shadow:0 8px 22px rgba(18,38,63,.05)}
        .filter-card{padding:14px;margin-bottom:16px}
        .filter-row{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr 120px 120px;gap:10px;margin-bottom:10px}
        .search-row{display:grid;grid-template-columns:1fr;gap:10px}
        .erp-select,.erp-input{height:44px;border:1px solid #dbe2ed;border-radius:10px;background:#fff;padding:0 14px;outline:none;width:100%; display:flex;}
        .filter-btn{height:44px;border:none;border-radius:10px;background:#1f2642;color:#fff;font-weight:700}
        .reset-btn{height:44px;border:none;border-radius:10px;background:#f2f4fa;color:#4b566d;font-weight:700}
        .table-card{padding:0;overflow:hidden}
        .table-wrap{overflow:auto}
        .erp-table{width:100%;min-width:1180px;border-collapse:separate;border-spacing:0}
        .erp-table thead th{background:#1a1f2e;color:#fff;padding:14px 12px;font-size:14px;font-weight:600;white-space:nowrap}
        .erp-table tbody td{background:#fff;padding:13px 12px;border-bottom:1px solid #edf1f6;vertical-align:middle;white-space:nowrap}
        .erp-table tbody tr:hover td{background:#fafcff}
        .erp-table thead th:first-child{border-top-left-radius:12px}
        .erp-table thead th:last-child{border-top-right-radius:12px}
        .supplier-cell{display:flex;align-items:center;gap:10px}
        .supplier-icon{width:28px;height:28px;border-radius:8px;background:#4f8cff;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px}
        .status-pill{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:800}
        .status-approval{background:#f5e2aa;color:#7f5d00}
        .status-pending{background:#f7e4a8;color:#916800}
        .status-completed{background:#dcefdc;color:#2c6b38}
        .work-pill{display:inline-flex;align-items:center;gap:6px;background:#f1f3f7;color:#4c596f;border-radius:999px;padding:7px 12px;font-size:13px;font-weight:600}
        .action-btn{width:34px;height:34px;border:none;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;margin-right:4px;cursor:pointer}
        .act-yellow{background:#fff3da;color:#d19a00}
        .act-red{background:#fff0f0;color:#e15151}
        .act-dot{background:#f3f5fa;color:#444}
        .approval-card{margin-top:16px;padding:0;overflow:hidden}
        .approval-head{padding:16px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #edf1f6}
        .approval-title{font-size:16px;font-weight:800;color:#253046;display:flex;align-items:center;gap:10px}
        .approval-body{padding:14px 18px}
        .approval-legend{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:20px;color:#4c566d;font-size:14px}
        .legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}
        .empty-state{text-align:center;color:#6a7488;font-size:18px;padding:24px 0 28px}
        .modal-xl-custom{max-width:980px}
        .po-modal-content{background:radial-gradient(circle at top left, rgba(25,43,120,.40), transparent 32%),radial-gradient(circle at bottom right, rgba(255,185,0,.12), transparent 28%),linear-gradient(180deg,#060916,#0b1031);border:none;border-radius:20px;overflow:hidden;color:#fff;box-shadow:0 30px 80px rgba(0,0,0,.35)}
        .po-modal-header{padding:18px 24px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:center}
        .po-modal-title{font-size:18px;font-weight:700;margin:0;color:#fff}
        .po-close{background:none;border:none;color:#d8def2;font-size:28px;line-height:1}
        .po-modal-body{padding:14px 24px 0}
        .tab-bar{display:flex;gap:0;border-bottom:1px solid rgba(255,255,255,.10);margin-bottom:18px}
        .tab-btn{border:none;padding:12px 18px;background:transparent;color:#d5d9ea;font-weight:600;border-radius:8px 8px 0 0}
        .tab-btn.active{background:#fff;color:#1d2434}
        .tab-pane-custom{display:none}
        .tab-pane-custom.active{display:block}
        .dark-label{font-size:13px;font-weight:600;color:#f4f6fd;margin-bottom:6px}
        .req{color:#ffbf43}
        .dark-input,.dark-select,.dark-textarea{width:100%;border:none;outline:none;border-radius:8px;background:#f3f5fa;color:#1d2434;padding:12px 14px;font-size:14px}
        .dark-textarea{min-height:84px;resize:vertical}
        .qty-wrap{background:#f3f5fa;border-radius:8px;height:54px;display:grid;grid-template-columns:56px 1fr 56px;overflow:hidden;align-items:center;border:1px solid rgba(255,255,255,.1)}
        .qty-btn{border:none;background:transparent;font-size:28px;color:#1d2434;height:100%;font-weight:700}
        .qty-input{border:none;background:transparent;text-align:center;font-size:30px;color:#111827;outline:none;width:100%;font-weight:800}
        .qty-meter{height:10px;border-radius:999px;background:linear-gradient(90deg,#6e7bff,#d3a23d,#9dcc67,#62d1c5);margin-top:10px}
        .qty-stats{display:flex;justify-content:space-between;color:#d4d9ea;font-size:13px;margin-top:8px}
        .switch-row{display:flex;align-items:center;gap:10px;margin-top:8px;font-weight:600;color:#fff}
        .form-check-input{width:44px;height:24px;cursor:pointer;border:none}
        .item-toolbar{display:grid;grid-template-columns:1fr 175px;gap:12px;align-items:end;margin-bottom:16px}
        .item-add-btn{height:44px;border:none;border-radius:8px;background:linear-gradient(180deg,#f2bc24,#d99d00);color:#fff;font-weight:700}
        .items-box{border:1px solid rgba(255,255,255,.12);border-radius:12px;overflow:hidden;margin-bottom:14px}
        .items-head,.items-row{display:grid;grid-template-columns:1.6fr .8fr .9fr .9fr 48px;align-items:center}
        .items-head{background:rgba(255,255,255,.06);color:#f2f4ff;font-size:14px;font-weight:600}
        .items-head div,.items-row div{padding:12px 14px;border-right:1px solid rgba(255,255,255,.10)}
        .items-head div:last-child,.items-row div:last-child{border-right:none}
        .items-row{border-top:1px solid rgba(255,255,255,.08)}
        .items-empty{text-align:center;color:#d0d6ea;padding:22px 10px;font-size:16px}
        .item-remove-btn{border:none;background:transparent;color:#f4f6ff;font-size:22px}
        .item-cell-input{width:100%;height:42px;border:1px solid rgba(255,255,255,.18);outline:none;border-radius:8px;background:rgba(255,255,255,.06);color:#fff;padding:0 12px;font-size:18px;font-weight:800}
        .item-cell-input::placeholder{color:rgba(255,255,255,.65)}
        .items-row .item-name-text{color:#fff;font-weight:700}
        .items-row .item-subtotal-text{color:#fff;font-weight:800;font-size:16px}
        .total-line{display:flex;justify-content:flex-end;gap:18px;align-items:center;color:#f5f6fb;border-top:1px solid rgba(255,255,255,.14);padding:14px 4px 0;margin-top:12px;font-size:16px}
        /* ===== ACTION ICONS (GLOBAL STYLE) ===== */
.action-group{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
}

.action-btn{
    width:38px;
    height:38px;
    border:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

.action-btn:hover{
    transform:translateY(-2px);
}

.action-btn i{
    font-size:15px;
}

/* EDIT */
.btn-edit{
    background:#fff4d6;
    color:#e0a100;
}
.btn-edit:hover{
    background:#e0a100;
    color:#fff;
}

/* DELETE */
.btn-delete{
    background:#ffe3e3;
    color:#ef4444;
}
.btn-delete:hover{
    background:#ef4444;
    color:#fff;
}
        .total-amount{font-size:18px;font-weight:800}
        .po-modal-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.08);display:flex;justify-content:flex-end;gap:12px;margin-top:16px}
        .cancel-btn{min-width:130px;height:44px;border-radius:10px;border:none;background:#5d617a;color:#fff;font-weight:700}
        .save-btn{min-width:140px;height:44px;border-radius:10px;border:none;background:linear-gradient(180deg,#f2bc24,#d99d00);color:#fff;font-weight:800}
         .table-footer{
            display:flex;justify-content:space-between;align-items:center;gap:10px;
            flex-wrap:wrap;padding:16px;border-top:1px solid #edf1f6
        }
        .footer-left{color:#546077;font-weight:500}
        .footer-right{display:flex;align-items:center;gap:10px}
        .pager-btn{
            width:36px;height:36px;border:none;border-radius:8px;background:#f4f7fb;color:#334055
        }
        .pager-num{
            min-width:36px;height:36px;border-radius:8px;display:flex;align-items:center;
            justify-content:center;background:#1a1f2e;color:#fff;font-weight:800;padding:0 10px
        }
             .bulk-delete-btn{
            border:none;
            background:#ef4444;
            color:#fff;
            border-radius:8px;
            padding:10px 14px;
            font-size:14px;
            font-weight:600;
        }

        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:991px){.filter-row{grid-template-columns:1fr 1fr}}
        @media(max-width:768px){.page-header{flex-direction:column;align-items:stretch}.stats-grid{grid-template-columns:1fr}.filter-row{grid-template-columns:1fr}.item-toolbar{grid-template-columns:1fr}}
    

/* =====================================================
   COMMISSIONS_RATES.PHP DASHBOARD DARK PURPLE GLASS THEME
   Paste before
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-border:rgba(148,163,184,.14);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT / SIDEBAR ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    background:transparent !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    background:transparent !important;
    overflow:visible !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
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
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

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

/* ================= TOPBAR / PAGE BACKGROUND ================= */
.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid var(--oms-border) !important;
    display:flex !important;
    align-items:center !important;
    padding:0 !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
}

.content-wrapper{
    position:relative !important;
    padding:24px 26px !important;
    flex:1 !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
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
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-header{
    align-items:center !important;
}

.btn-green{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
    transition:.22s ease !important;
}

.btn-green:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* ================= STATS CARDS ================= */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:138px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate;
    transition:.25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%) !important;
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
    background:rgba(255,255,255,.10) !important;
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.stat-gold{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

.stat-title{
    font-size:15px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
    position:relative;
    z-index:2;
}

.stat-value{
    position:absolute !important;
    right:18px !important;
    bottom:14px !important;
    font-size:30px !important;
    font-weight:900 !important;
    color:#fff !important;
    z-index:2;
}

.stat-sub{
    color:rgba(255,255,255,.82) !important;
    font-weight:800 !important;
    position:relative;
    z-index:2;
}

/* ================= GLASS PANELS ================= */
.filter-card,
.table-card,
.approval-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    color:#e5e7eb !important;
}

.filter-card:hover,
.table-card:hover,
.approval-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-card{
    padding:16px !important;
    margin-bottom:18px !important;
}

/* ================= FILTER INPUTS ================= */
.erp-select,
.erp-input{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.erp-select:focus,
.erp-input:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.erp-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.filter-btn{
    height:46px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.reset-btn{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.filter-btn:hover,
.reset-btn:hover{
    transform:translateY(-1px);
    color:#fff !important;
}

/* ================= TABLE ================= */
.table-card{
    overflow:hidden !important;
}

.table-wrap,
.erp-table,
.erp-table tbody,
.erp-table tbody tr{
    background:transparent !important;
}

.erp-table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

.erp-table thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.04em;
    padding:14px 12px !important;
    white-space:nowrap;
}

.erp-table tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-top:none !important;
    padding:14px 12px !important;
    vertical-align:middle;
    font-size:14px !important;
    font-weight:600;
    white-space:nowrap;
}

.erp-table tbody tr:hover,
.erp-table tbody tr:hover td,
.erp-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

.erp-table tbody tr:focus,
.erp-table tbody tr:active,
.erp-table tbody td:focus,
.erp-table tbody td:active,
.erp-table tbody td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

.erp-table tbody td.text-center,
.erp-table tbody td.py-4,
.erp-table tbody td[colspan]{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

.erp-table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

/* ================= STATUS / WORK PILLS ================= */
.status-pill,
.work-pill{
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:999px !important;
    font-weight:900 !important;
}

.status-approval,
.status-pending{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.status-completed{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

.work-pill{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border-color:rgba(109,76,255,.24) !important;
}

/* ================= ACTION BUTTONS ================= */
.action-group{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
}

.action-btn{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:0 !important;
    cursor:pointer !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.btn-edit,
.act-yellow{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
}

.btn-delete,
.act-red{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
}

.act-dot{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
}

.action-btn:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.24) !important;
    color:#fff !important;
}

/* ================= FOOTER / PAGINATION ================= */
.table-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#94a3b8 !important;
}

.footer-left{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.pager-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:10px !important;
}

.pager-num{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-radius:10px !important;
}

.bulk-delete-btn{
    border:none !important;
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#fff !important;
    border-radius:10px !important;
    padding:10px 14px !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(239,68,68,.18) !important;
}

/* ================= SCROLLBAR ================= */
.table-wrap::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar,
.po-modal-body::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-wrap::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track,
.po-modal-body::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb,
.po-modal-body::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb:hover,
.content-wrapper::-webkit-scrollbar-thumb:hover,
.po-modal-body::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

/* =====================================================
   COMMISSION RATE POPUP - DARK PURPLE GLASS COMPACT
===================================================== */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

#rateModal .modal-dialog{
    max-width:min(860px,94vw) !important;
}

#rateModal .modal-content,
#rateModal .po-modal-content{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    max-height:92vh !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#rateModal .po-modal-header{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#rateModal .po-modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#rateModal .po-modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#rateModal .po-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    color:transparent !important;
    opacity:1 !important;
    box-shadow:none !important;
    padding:0 !important;
    font-size:0 !important;
    line-height:1 !important;
}

#rateModal .po-close::before,
#rateModal .po-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:18px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#rateModal .po-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#rateModal .po-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#rateModal .po-close:hover{
    background:#ef4444 !important;
    transform:translateY(-1px) !important;
}

#rateModal .po-modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#rateModal .po-modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#rateModal .dark-label,
#rateModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#rateModal .req{
    color:#c4b5fd !important;
}

#rateModal .dark-input,
#rateModal .dark-select,
#rateModal .dark-textarea,
#rateModal input[type="text"],
#rateModal input[type="number"],
#rateModal select,
#rateModal textarea{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    min-height:40px !important;
    height:40px !important;
    padding:7px 12px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#rateModal .dark-textarea,
#rateModal textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#rateModal input::placeholder,
#rateModal textarea::placeholder{
    color:#94a3b8 !important;
}

#rateModal .dark-input:focus,
#rateModal .dark-select:focus,
#rateModal .dark-textarea:focus,
#rateModal input:focus,
#rateModal select:focus,
#rateModal textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#rateModal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

#rateModal .cancel-btn{
    min-height:38px !important;
    height:38px !important;
    min-width:105px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
}

#rateModal .save-btn{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#rateModal .cancel-btn:hover,
#rateModal .save-btn:hover{
    transform:translateY(-1px);
}

@media(max-width:1200px){
    .stats-grid{
        grid-template-columns:repeat(2,1fr) !important;
    }

    .filter-row{
        grid-template-columns:1fr 1fr !important;
    }
}

@media(max-width:991px){
    .container-scroll{
        flex-direction:column !important;
    }

    .sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        position:relative !important;
        height:auto !important;
        display:flex !important;
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
        margin-left:0 !important;
    }

    .topbar{
        position:relative !important;
    }

    .content-wrapper{
        padding:18px !important;
    }
}

@media(max-width:768px){
    .stats-grid,
    .filter-row{
        grid-template-columns:1fr !important;
    }

    .page-header{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .btn-green{
        width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .filter-btn,
    .reset-btn{
        width:100% !important;
    }

    .table-footer,
    .footer-right{
        flex-direction:column !important;
        align-items:stretch !important;
        width:100% !important;
    }

    .bulk-delete-btn,
    .pager-btn,
    .pager-num{
        width:100% !important;
    }

    #rateModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #rateModal .po-modal-title{
        font-size:18px !important;
    }

    #rateModal .po-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #rateModal .cancel-btn,
    #rateModal .save-btn{
        width:100% !important;
    }
}


/* =========================================================
   COMMISSION RATES PAGE - FINAL LIGHT MODE
   Keep this block at the very end before
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE LAYOUT
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 52%,
            #faf7ff 100%
        ) !important;
}

/* Topbar */
html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    color:#0f172a !important;
    border-bottom:1px solid #dce4ef !important;
    box-shadow:0 5px 18px rgba(15,23,42,.06) !important;
}

/* Main background */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 80% 4%,
            rgba(59,130,246,.05),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 48%,
            #faf7ff 76%,
            #f8fafc 100%
        ) !important;

    color:#0f172a !important;
}

/* Background grid */
html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ) !important;

    opacity:.5 !important;
}

/* Purple glow soft */
html[data-pos-theme="light"] body .content-wrapper::after{
    background:
        radial-gradient(
            circle,
            rgba(124,58,237,.08),
            transparent 68%
        ) !important;

    opacity:.42 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Add Rate button */
html[data-pos-theme="light"] body .btn-green{
    background:
        linear-gradient(
            135deg,
            #f59e0b,
            #fb923c
        ) !important;

    color:#111827 !important;

    box-shadow:
        0 12px 26px rgba(245,158,11,.20) !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.25) !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .stat-title,
html[data-pos-theme="light"] body .stat-card .stat-value,
html[data-pos-theme="light"] body .stat-card .stat-sub{
    color:#ffffff !important;
    opacity:1 !important;
}

/* =========================================================
   FILTER CARD
========================================================= */

html[data-pos-theme="light"] body .filter-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 28px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .filter-card:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 34px rgba(15,23,42,.09) !important;
}

/* Filter selects and inputs */
html[data-pos-theme="light"] body .erp-select,
html[data-pos-theme="light"] body .erp-input{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .erp-input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .erp-select:hover,
html[data-pos-theme="light"] body .erp-input:hover{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] body .erp-select:focus,
html[data-pos-theme="light"] body .erp-input:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .erp-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Filter button */
html[data-pos-theme="light"] body .filter-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border-color:#7c3aed !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

/* Reset button */
html[data-pos-theme="light"] body .reset-btn{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .reset-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 36px rgba(15,23,42,.09) !important;
}

/* Bootstrap table dark variables reset */
html[data-pos-theme="light"] body .erp-table,
html[data-pos-theme="light"] body .erp-table thead,
html[data-pos-theme="light"] body .erp-table tbody,
html[data-pos-theme="light"] body .erp-table tr,
html[data-pos-theme="light"] body .erp-table th,
html[data-pos-theme="light"] body .erp-table td{
    --bs-table-bg:transparent !important;
    --bs-table-color:#1e293b !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
    --bs-table-accent-bg:transparent !important;
}

/* Table heading */
html[data-pos-theme="light"] body .erp-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-right:1px solid #e2e8f0 !important;
    border-bottom:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

/* Table rows */
html[data-pos-theme="light"] body .erp-table tbody,
html[data-pos-theme="light"] body .erp-table tbody tr,
html[data-pos-theme="light"] body .erp-table tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-bottom-color:#e8edf4 !important;

    box-shadow:none !important;
}

/* Row hover */
html[data-pos-theme="light"] body .erp-table tbody tr:hover,
html[data-pos-theme="light"] body .erp-table tbody tr:hover td,
html[data-pos-theme="light"] body .erp-table tbody td:hover{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;

    color:#0f172a !important;
    box-shadow:none !important;
}

/* Focused table row */
html[data-pos-theme="light"] body .erp-table tbody tr:focus td,
html[data-pos-theme="light"] body .erp-table tbody tr:active td,
html[data-pos-theme="light"] body .erp-table tbody td:focus{
    background:#f3efff !important;
    color:#0f172a !important;
    box-shadow:none !important;
}

/* Empty table row */
html[data-pos-theme="light"] body .erp-table tbody td.text-center,
html[data-pos-theme="light"] body .erp-table tbody td.py-4,
html[data-pos-theme="light"] body .erp-table tbody td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Checkbox */
html[data-pos-theme="light"] body .erp-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* =========================================================
   STATUS AND WORK PILLS
========================================================= */

html[data-pos-theme="light"] body .status-approval,
html[data-pos-theme="light"] body .status-pending{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] body .status-completed{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .work-pill{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .action-btn{
    box-shadow:
        0 5px 12px rgba(15,23,42,.07) !important;
}

/* Edit */
html[data-pos-theme="light"] body .btn-edit,
html[data-pos-theme="light"] body .act-yellow{
    background:#fffbeb !important;
    color:#d97706 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .btn-edit:hover,
html[data-pos-theme="light"] body .act-yellow:hover{
    background:#f59e0b !important;
    color:#ffffff !important;
}

/* Delete */
html[data-pos-theme="light"] body .btn-delete,
html[data-pos-theme="light"] body .act-red{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border:1px solid #fecdd3 !important;
}

html[data-pos-theme="light"] body .btn-delete:hover,
html[data-pos-theme="light"] body .act-red:hover{
    background:#ef4444 !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .act-dot{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border:1px solid #c4b5fd !important;
}

/* =========================================================
   TABLE FOOTER
========================================================= */

html[data-pos-theme="light"] body .table-footer{
    background:#f8fafc !important;
    color:#64748b !important;

    border-top:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .footer-left{
    color:#64748b !important;
}

/* Previous / Next */
html[data-pos-theme="light"] body .pager-btn{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body .pager-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Current page */
html[data-pos-theme="light"] body .pager-num{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
}

/* Delete selected */
html[data-pos-theme="light"] body .bulk-delete-btn{
    background:
        linear-gradient(
            135deg,
            #e11d48,
            #ef4444
        ) !important;

    color:#ffffff !important;
}

/* =========================================================
   APPROVAL CARD
========================================================= */

html[data-pos-theme="light"] body .approval-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .approval-head{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .approval-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .approval-body,
html[data-pos-theme="light"] body .approval-legend{
    color:#475569 !important;
}

html[data-pos-theme="light"] body .empty-state{
    color:#64748b !important;
}

/* =========================================================
   ADD / EDIT COMMISSION RATE MODAL
========================================================= */

html[data-pos-theme="light"] body
#rateModal .modal-content,

html[data-pos-theme="light"] body
#rateModal .po-modal-content{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #d8e0eb !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.20) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Modal header */
html[data-pos-theme="light"] body
#rateModal .po-modal-header{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body
#rateModal .po-modal-title{
    color:#0f172a !important;
}

/* Title dot */
html[data-pos-theme="light"] body
#rateModal .po-modal-title::before{
    background:#7c3aed !important;

    box-shadow:
        0 0 0 4px rgba(124,58,237,.14) !important;
}

/* Close button */
html[data-pos-theme="light"] body
#rateModal .po-close{
    background:#ffffff !important;
    color:transparent !important;

    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body
#rateModal .po-close::before,

html[data-pos-theme="light"] body
#rateModal .po-close::after{
    background:#334155 !important;
}

html[data-pos-theme="light"] body
#rateModal .po-close:hover{
    background:#fff1f2 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body
#rateModal .po-close:hover::before,

html[data-pos-theme="light"] body
#rateModal .po-close:hover::after{
    background:#e11d48 !important;
}

/* Modal body */
html[data-pos-theme="light"] body
#rateModal .po-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Labels */
html[data-pos-theme="light"] body
#rateModal .dark-label,

html[data-pos-theme="light"] body
#rateModal label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
#rateModal .req{
    color:#e11d48 !important;
}

/* Modal inputs */
html[data-pos-theme="light"] body
#rateModal .dark-input,

html[data-pos-theme="light"] body
#rateModal .dark-select,

html[data-pos-theme="light"] body
#rateModal .dark-textarea,

html[data-pos-theme="light"] body
#rateModal input[type="text"],

html[data-pos-theme="light"] body
#rateModal input[type="number"],

html[data-pos-theme="light"] body
#rateModal select,

html[data-pos-theme="light"] body
#rateModal textarea{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#rateModal input::placeholder,

html[data-pos-theme="light"] body
#rateModal textarea::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body
#rateModal .dark-input:focus,

html[data-pos-theme="light"] body
#rateModal .dark-select:focus,

html[data-pos-theme="light"] body
#rateModal .dark-textarea:focus,

html[data-pos-theme="light"] body
#rateModal input:focus,

html[data-pos-theme="light"] body
#rateModal select:focus,

html[data-pos-theme="light"] body
#rateModal textarea:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body
#rateModal select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Modal footer */
html[data-pos-theme="light"] body
#rateModal .po-modal-footer{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

/* Cancel */
html[data-pos-theme="light"] body
#rateModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
}

/* Save */
html[data-pos-theme="light"] body
#rateModal .save-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

/* Modal backdrop */
html[data-pos-theme="light"] body.modal-open
.modal-backdrop.show{
    background:#0f172a !important;
    opacity:.45 !important;
}

/* =========================================================
   SCROLLBARS
========================================================= */

html[data-pos-theme="light"] body
.table-wrap::-webkit-scrollbar-track,

html[data-pos-theme="light"] body
.content-wrapper::-webkit-scrollbar-track,

html[data-pos-theme="light"] body
#rateModal .po-modal-body::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"] body
.table-wrap::-webkit-scrollbar-thumb,

html[data-pos-theme="light"] body
.content-wrapper::-webkit-scrollbar-thumb,

html[data-pos-theme="light"] body
#rateModal .po-modal-body::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
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
                    <h1 class="page-title">Commission Rates</h1>
                     <button type="button" class="btn-green" onclick="openRateModal()"> <i class="bi bi-plus-lg me-1"></i> Add Rate</button>
                </div>
                
              <div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-title">Total Active Staff</div>
        <div class="stat-value"><?= $totalOrders ?></div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-title">Average Commission</div>
        <div class="stat-value"><?= $pendingOrders ?>%</div>
    </div>
    <div class="stat-card stat-cyan">
        <div class="stat-title">Top Performer</div>
        <div class="stat-value"><?= htmlspecialchars($completedOrders) ?></div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-title">Total Commission Paid</div>
        <div class="stat-sub"><?= number_format($totalAmount) ?></div>
        <div class="stat-value" style="font-size:22px;">PKR <?= number_format($totalAmount) ?></div>
    </div>
</div>

<div class="filter-card">
                    <form method="GET">
                        <div class="filter-row">
                            <!-- All Staff -->
                            <select class="erp-select" name="staff_id">
                                <option value="">All Staff</option>
                                <?php foreach ($staff as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= $staffFilter == $s['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- All Services -->
                            <select class="erp-select" name="service_id">
                                <option value="">All Services</option>
                                <?php foreach ($services as $srv): ?>
                                    <option value="<?= (int)$srv['id'] ?>" <?= $serviceFilter == $srv['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($srv['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Commission Type -->
                            <select class="erp-select" name="commission_type">
                                <option value="">All Commission Types</option>
                                <option value="percentage" <?= $commissionFilter === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                                <option value="fixed" <?= $commissionFilter === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                            </select>

                            <!-- Branches -->
                            <select class="erp-select" name="branch_id">
                                <option value="">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $branchFilter == $b['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Status -->
                            <select class="erp-select" name="status">
                                <option value="">All Status</option>
                                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            </select>

                            <button type="submit" class="filter-btn"><i class="bi bi-search me-1"></i> Filter</button>
                            <a href="purchase_orders.php" class="reset-btn d-inline-flex align-items-center justify-content-center text-decoration-none">
                                <i class="bi bi-arrow-clockwise me-1"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                
                 <div class="table-card">
                <div class="table-wrap">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="checkAll"></th>
                                <th>Staff</th>
                                <th>Service</th>
                                <th>Commission Type</th>
                                <th>Rate</th>
                                <th>Min Sale Target</th>
                                <th>Max Cap</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                           <?php if(empty($rates)): ?>
                                <tr><td colspan="7" class="text-center py-4">No Commision found</td></tr>
                            <?php else: ?>
                                <?php foreach($rates as $r): ?>
                                    <tr>
                                        <td>
    <input type="checkbox" class="row-check" 
        value='<?= json_encode([
            "user_id" => (int)$r["user_id"],
            "service_id" => (int)$r["service_id"]
        ]) ?>'>
</td>
                                        <td><?= htmlspecialchars($r['staff_name']) ?></td>
                                    <td><?= htmlspecialchars($r['service_name']) ?></td>
                                    <td><?= htmlspecialchars($r['commission_type']) ?></td>
                                    <td><?= $r['percentage'] ?>%</td>
                                    <td><?= $r['min_sale_target'] ?></td>
                                    <td><?= $r['max_cap'] ?></td>
                                    <td><?= htmlspecialchars($r['branch_name'] ?? 'No Branch') ?></td>
                                    <td><?= $r['status'] ?></td>
                                  <td>
    <div class="action-group">

        <button class="action-btn btn-edit editRate"
            data-user="<?= $r['user_id'] ?>"
            data-service="<?= $r['service_id'] ?>"
            data-percentage="<?= $r['percentage'] ?>"
            data-commission-type="<?= $r['commission_type'] ?>"
            data-fixed-amount="<?= $r['fixed_amount'] ?>"
            data-min-sale-target="<?= $r['min_sale_target'] ?>"
            data-max-cap="<?= $r['max_cap'] ?>"
            data-branch-id="<?= $r['branch_id'] ?>"
            data-status="<?= $r['status'] ?>"
            data-tiered-config="<?= $r['tiered_config'] ?>"
            title="Edit Rate">
            <i class="bi bi-pencil-fill"></i>
        </button>

        <button class="action-btn btn-delete deleteRate"
            data-user="<?= $r['user_id'] ?>"
            data-service="<?= $r['service_id'] ?>"
            title="Delete Rate">
            <i class="bi bi-trash-fill"></i>
        </button>

    </div>
</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                </div>
                <div class="table-footer">
    <!-- Left side: Total entries -->
    <div class="footer-left">
        Showing 1 to <?= count($rates) ?> of <?= count($rates) ?> entries
    </div>

    <!-- Right side: Pagination + Bulk Delete -->
    <div class="footer-right" style="display: flex; align-items: center; gap: 10px;">
        <!-- Bulk Delete Button -->
        <button type="button" class="bulk-delete-btn" onclick="deleteSelected(event)">
            Delete Selected
        </button>

        <!-- Pagination Controls -->
        <button type="button" class="pager-btn" id="prev-page"><i class="bi bi-chevron-left"></i></button>
        <div class="pager-num" id="current-page">1</div>
        <button type="button" class="pager-btn" id="next-page"><i class="bi bi-chevron-right"></i></button>
    </div>
</div>
            </div>
            </div>
        </div>
    </div>

<div class="modal fade" id="rateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl-custom">
        <div class="modal-content po-modal-content">
            <form id="rateForm">
                <input type="hidden" name="edit_user" id="edit_user">
                <input type="hidden" name="edit_service" id="edit_service">

                <div class="po-modal-header">
                    <h5 class="po-modal-title" id="rateModalTitle">Add Rate</h5>
                    <button type="button" class="po-close" data-bs-dismiss="modal">&times;</button>
                </div>

                <div class="po-modal-body">
                    <div class="row">
                        <!-- Staff -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Staff <span class="req">*</span></label>
                            <select name="user_id" id="user_id" class="dark-select" required>
                                <option value="">Select Staff</option>
                                <?php foreach($staff as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Service -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Service <span class="req">*</span></label>
                            <select name="service_id" id="service_id" class="dark-select" required>
                                <option value="">Select Service</option>
                                <?php foreach($services as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Commission Type -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Commission Type <span class="req">*</span></label>
                            <select name="commission_type" id="commission_type" class="dark-select" required>
                                <option value="percentage">Percentage</option>
                                <option value="fixed">Fixed Per Service</option>
                                <option value="tiered">Tiered Commission</option>
                            </select>
                        </div>

                        <!-- Rate -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Rate (%) <span class="req">*</span></label>
                            <input type="number" step="0.01" name="percentage" id="percentage" class="dark-input" required>
                        </div>

                        <!-- Min Sale Target -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Min Sale Target</label>
                            <input type="text" name="min_sale_target" id="min_sale_target" class="dark-input">
                        </div>

                        <!-- Max Commission Cap -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Max Commission Cap</label>
                            <input type="text" name="max_cap" id="max_cap" class="dark-input">
                        </div>

                        <!-- Branch Dropdown -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Branch <span class="req">*</span></label>
                            <select name="branch_id" id="branch_id" class="dark-select" required>
                                <option value="">Select Branch</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Status -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Status <span class="req">*</span></label>
                            <select name="status" id="status" class="dark-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <!-- Tiered Config -->
                        <div class="col-12 mb-3">
                            <label class="dark-label">Tiered Configuration</label>
                            <input type="text" name="tiered_config" id="tiered_config" class="dark-input" placeholder="Enter tiered details...">
                        </div>
                    </div>
                </div>

                <div class="po-modal-footer">
                    <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="save-btn">Save Rate</button>
                </div>
            </form>
        </div>
    </div>
</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        
        
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
        // MODAL
        const modal = new bootstrap.Modal(document.getElementById('rateModal'));

        function openRateModal() {
            $('#rateForm')[0].reset();
            $('#edit_user').val('');
            $('#edit_service').val('');
            $('#modalTitle').text('Add Rate');
            modal.show();
        }

        // Edit Rate
        $('.editRate').on('click', function() {
            const d = this.dataset;
            $('#edit_user').val(d.user);
            $('#edit_service').val(d.service);
            $('#user_id').val(d.user);
            $('#service_id').val(d.service);
            $('#percentage').val(d.percentage);
            $('#commission_type').val(d.commissionType);
            $('#min_sale_target').val(d.minSaleTarget);
            $('#max_cap').val(d.maxCap);
            $('#branch_id').val(d.branchId);
            $('#status').val(d.status);
            $('#tiered_config').val(d.tieredConfig);
            $('#modalTitle').text('Edit Rate');
            modal.show();
        });

        // Delete Rate
        $('.deleteRate').on('click', function() {
            const user_id = this.dataset.user;
            const service_id = this.dataset.service;
            if (!confirm("Delete this rate?")) return;
            $.post('ajax_staff_commission_rates.php', {
                action: 'delete',
                user_id: user_id,
                service_id: service_id
            }, function(resp) {
                if (resp == 'ok') location.reload();
                else alert(resp);
            });
        });

        // Save Rate
        $('#rateForm').on('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save');
            fetch('ajax_staff_commission_rates.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.text()).then(resp => {
                if (resp == 'ok') location.reload();
                else alert(resp);
            });
        });
        
        $(document).ready(function () {
    // Handle saving commission rate via AJAX
    $('#rateForm').on('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this); // Collect form data
        formData.append('action', 'save'); // Append action for saving

        $.ajax({
            url: 'ajax_staff_commission_rates.php', // Target PHP file for processing
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response == 'ok') {
                    alert('Rate added successfully!');
                    location.reload(); // Reload the page after success
                } else {
                    alert('Error: ' + response); // Show error if any
                }
            },
            error: function (xhr, status, error) {
                // Handle AJAX errors
                console.log("AJAX Error: " + status + " " + error);
                alert("Something went wrong. Please try again.");
            }
        });
    });
});
const checkAll = document.getElementById('checkAll');
checkAll?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(ch => ch.checked = this.checked);
});
document.addEventListener("DOMContentLoaded", function () {
    const checkboxes = document.querySelectorAll(".row-check");
    const selectAll = document.getElementById("select-all");

    // ================= Select All Logic =================
    if (selectAll) {
        selectAll.addEventListener("change", function () {
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }

    checkboxes.forEach(cb => {
        cb.addEventListener("change", function () {
            const total = document.querySelectorAll(".row-check").length;
            const checked = document.querySelectorAll(".row-check:checked").length;
            if (selectAll) selectAll.checked = (total === checked);
        });
    });

    // ================= Bulk Delete =================
    window.deleteSelected = function () {
        const selected = Array.from(document.querySelectorAll(".row-check:checked"))
                              .map(cb => JSON.parse(cb.value));

        if (selected.length === 0) {
            alert("No items selected");
            return;
        }

        if (!confirm(`Are you sure you want to delete ${selected.length} items?`)) return;

        fetch('/onerp/app/app/ajax/staff_commission.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=bulk_delete&ids=' + encodeURIComponent(JSON.stringify(selected))
        })
        .then(res => res.text())
        .then(res => {
            if (res.trim() === 'ok') {
                alert("Deleted successfully");
                location.reload();
            } else {
                alert("Delete failed: " + res);
            }
        });
    };

    // ================= Pagination Logic =================
    let currentPage = 1;
    const rowsPerPage = 10;
    const tableRows = Array.from(document.querySelectorAll("#commissionTable tbody tr"));
    const totalPages = Math.ceil(tableRows.length / rowsPerPage);

    function showPage(page) {
        currentPage = page;
        tableRows.forEach((row, idx) => {
            row.style.display = (idx >= (page - 1) * rowsPerPage && idx < page * rowsPerPage) ? '' : 'none';
        });
        document.getElementById("current-page").textContent = page;
    }

    document.getElementById("prev-page").addEventListener("click", () => {
        if (currentPage > 1) showPage(currentPage - 1);
    });
    document.getElementById("next-page").addEventListener("click", () => {
        if (currentPage < totalPages) showPage(currentPage + 1);
    });

    showPage(1); // Initial display
});

    </script>
</body>

</html>