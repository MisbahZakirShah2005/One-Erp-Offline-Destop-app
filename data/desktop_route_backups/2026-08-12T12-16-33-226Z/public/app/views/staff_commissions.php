<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
if ($client_id <= 0) {
    die("Invalid client session");
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $tableEsc = $conn->real_escape_string($table);
    $colEsc   = $conn->real_escape_string($column);

    $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'";
    $res = $conn->query($sql);

    $cache[$key] = ($res && $res->num_rows > 0);
    return $cache[$key];
}

$today = date('Y-m-d');
$thisMonth = date('Y-m-01');

/* ================= FETCH STAFF LIST ================= */
$staffList = [];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE client_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $staffList[] = $row;
}
$stmt->close();

/* ================= FETCH SERVICES ================= */
$services = [];
$stmt = $conn->prepare("SELECT id, name FROM services WHERE client_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $services[] = $row;
}
$stmt->close();

/* ================= FETCH BRANCHES ================= */
$branches = [];
$stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $branches[] = $row;
}
$stmt->close();

/* ================= SUMMARY CARDS ================= */
$totalRates = 0;
$activeRates = 0;
$inactiveRates = 0;
$pendingRates = 0;

$stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_rates,
        SUM(CASE WHEN scr.status = 'active' THEN 1 ELSE 0 END) AS active_rates,
        SUM(CASE WHEN scr.status = 'inactive' THEN 1 ELSE 0 END) AS inactive_rates,
        SUM(CASE WHEN scr.status = 'pending' THEN 1 ELSE 0 END) AS pending_rates
    FROM staff_commission_rates scr
    JOIN users u ON scr.user_id = u.id
    WHERE u.client_id = ?
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

$totalRates    = (int)($row['total_rates'] ?? 0);
$activeRates   = (int)($row['active_rates'] ?? 0);
$inactiveRates = (int)($row['inactive_rates'] ?? 0);
$pendingRates  = (int)($row['pending_rates'] ?? 0);

/* ================= FILTERS ================= */
$staffFilter   = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
$serviceFilter = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
$statusFilter  = trim($_GET['status'] ?? '');
$startDate     = trim($_GET['start_date'] ?? '');
$endDate       = trim($_GET['end_date'] ?? '');

/* ================= FETCH COMMISSION RATES ================= */
$whereClauses = [];
$params = [$client_id];
$bindTypes = 'i';

$sql = "
    SELECT 
        scr.*,
        u.name AS staff_name,
        s.name AS service_name,
        b.name AS branch_name
    FROM staff_commission_rates scr
    JOIN users u ON scr.user_id = u.id
    JOIN services s ON scr.service_id = s.id
    LEFT JOIN branches b ON scr.branch_id = b.id
    WHERE u.client_id = ?
";

if ($staffFilter > 0) {
    $whereClauses[] = "u.id = ?";
    $params[] = $staffFilter;
    $bindTypes .= 'i';
}

if ($serviceFilter > 0) {
    $whereClauses[] = "s.id = ?";
    $params[] = $serviceFilter;
    $bindTypes .= 'i';
}

if ($statusFilter !== '') {
    $whereClauses[] = "scr.status = ?";
    $params[] = $statusFilter;
    $bindTypes .= 's';
}

$hasCreatedAt = hasColumn($conn, 'staff_commission_rates', 'created_at');

if ($hasCreatedAt && $startDate !== '') {
    $whereClauses[] = "DATE(scr.created_at) >= ?";
    $params[] = $startDate;
    $bindTypes .= 's';
}

if ($hasCreatedAt && $endDate !== '') {
    $whereClauses[] = "DATE(scr.created_at) <= ?";
    $params[] = $endDate;
    $bindTypes .= 's';
}

if (!empty($whereClauses)) {
    $sql .= " AND " . implode(' AND ', $whereClauses);
}

$orderBy = hasColumn($conn, 'staff_commission_rates', 'id') ? 'scr.id DESC' : 'u.name ASC, s.name ASC';
$sql .= " ORDER BY {$orderBy}";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Query prepare failed: " . $conn->error);
}
$stmt->bind_param($bindTypes, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$commissions = [];
while ($row = $res->fetch_assoc()) {
    $displayRate = '';

    if (($row['commission_type'] ?? '') === 'percentage') {
        $displayRate = number_format((float)($row['percentage'] ?? 0), 2) . '%';
    } elseif (($row['commission_type'] ?? '') === 'fixed') {
        $displayRate = 'PKR ' . number_format((float)($row['fixed_amount'] ?? 0), 2);
    } else {
        $displayRate = 'Tiered';
    }

    $commissions[] = [
        'id'                => (int)($row['id'] ?? 0),
        'user_id'           => (int)($row['user_id'] ?? 0),
        'service_id'        => (int)($row['service_id'] ?? 0),
        'branch_id'         => (int)($row['branch_id'] ?? 0),
        'staff_name'        => $row['staff_name'] ?? '',
        'service_name'      => $row['service_name'] ?? '',
        'branch_name'       => $row['branch_name'] ?? 'No Branch',
        'commission_type'   => $row['commission_type'] ?? '',
        'commission_rate'   => $displayRate,
        'percentage'        => (float)($row['percentage'] ?? 0),
        'fixed_amount'      => (float)($row['fixed_amount'] ?? 0),
        'min_sale_target'   => (float)($row['min_sale_target'] ?? 0),
        'max_cap'           => (float)($row['max_cap'] ?? 0),
        'status'            => $row['status'] ?? 'inactive',
        'tiered_config'     => $row['tiered_config'] ?? '',
    ];
}
$stmt->close();

$totalRows = count($commissions);
$currentPage = 1;
$rowsPerPage = 10;
$paginationStart = $totalRows > 0 ? 1 : 0;
$paginationEnd = min($rowsPerPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Commission</title>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#eef2f7;font-family:Inter,Arial,sans-serif;color:#1f2736}
  .container-scroll{
        display:flex;
        min-height:100vh;
           align-items:stretch;
    }

    .sidebar{
        width:250px;
        flex-shrink:0;
          display:flex;*/
  align-self:stretch;
  background:transparent;
    overflow:hidden;
    }


.sidebar > div{
    width:100%;
    min-height:100%;
    height:100%;
}

#dashboardSidebar .sidebar-wrap{
    min-height:100% !important;
    height:100% !important;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100% !important;
}
        .main-panel{flex:1;min-width:0;display:flex;flex-direction:column}
        .topbar{height:64px;background:#05070d;color:#fff;display:flex;align-items:center;padding:0 20px}
        .content-wrapper{padding:18px;flex:1;overflow:auto}

        .page-header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
        .page-title{font-size:24px;font-weight:800;margin:0;color:#243046}
        .btn-green{
            border:none;border-radius:12px;background:linear-gradient(180deg,#53ae6e,#3e8f58);
            color:#fff;font-weight:700;padding:12px 18px;min-width:190px;
            box-shadow:0 12px 24px rgba(62,143,88,.25)
        }

        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
        .stat-card{
            border-radius:14px;padding:18px;min-height:104px;color:#fff;position:relative;
            overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12)
        }
        .stat-card::after{
            content:'';position:absolute;width:110px;height:110px;border-radius:24px;
            right:16px;top:10px;background:rgba(255,255,255,.10)
        }
        .stat-blue{background:linear-gradient(135deg,#5e84ff,#7fa9ff)}
        .stat-green{background:linear-gradient(135deg,#4ea98d,#66c09f)}
        .stat-cyan{background:linear-gradient(135deg,#56b4da,#7fd4f5)}
        .stat-orange{background:linear-gradient(135deg,#f0a11b,#d98506)}
        .stat-title{font-size:14px;font-weight:600;line-height:1.3}
        .stat-value{font-size:30px;font-weight:800;line-height:1.1;margin-top:10px}

        .filter-card,.table-card{
            background:#fff;border:1px solid #e6ebf3;border-radius:16px;
            box-shadow:0 8px 22px rgba(18,38,63,.05)
        }
        .filter-card{padding:14px;margin-bottom:16px}
        .filter-row{
            display:grid;
            grid-template-columns:1fr 1fr 1fr 1fr 1fr 120px 120px;
            gap:10px;
            align-items:center;
        }
        .erp-select,.erp-input{
            height:46px;border:1px solid #dbe2ed;border-radius:10px;background:#fff;
            padding:0 14px;outline:none;width:100%;
        }
        .filter-btn{
            height:46px;border:none;border-radius:10px;background:#1f2642;color:#fff;font-weight:700
        }
        .reset-btn{
            height:46px;border:none;border-radius:10px;background:#f2f4fa;color:#4b566d;
            font-weight:700;text-decoration:none;display:flex;align-items:center;justify-content:center
        }

        .table-card{padding:0;overflow:hidden}
        .table-wrap{overflow:auto}
        .erp-table{width:100%;min-width:1280px;border-collapse:separate;border-spacing:0}
        .erp-table thead th{
            background:#1a1f2e;color:#fff;padding:14px 12px;font-size:14px;
            font-weight:600;white-space:nowrap
        }
        .erp-table tbody td{
            background:#fff;padding:13px 12px;border-bottom:1px solid #edf1f6;
            vertical-align:middle;white-space:nowrap
        }
        .erp-table tbody tr:hover td{background:#fafcff}
        .erp-table thead th:first-child{border-top-left-radius:12px}
        .erp-table thead th:last-child{border-top-right-radius:12px}

        .status-pill{
            display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;
            border-radius:8px;font-size:12px;font-weight:800;text-transform:capitalize
        }
        .status-active{background:#dcefdc;color:#2c6b38}
        .status-inactive{background:#f7e4a8;color:#916800}
        .status-pending{background:#f5e2aa;color:#7f5d00}
.staff-commission-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
}

.staff-commission-btn{
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

.staff-commission-btn:hover{
    transform:translateY(-2px);
}

.staff-commission-btn i{
    font-size:15px;
    line-height:1;
}

.staff-commission-edit{
    background:#fff4d6;
    color:#e0a100;
}

.staff-commission-edit:hover{
    background:#e0a100;
    color:#fff;
}

.staff-commission-delete{
    background:#ffe3e3;
    color:#ef4444;
}

.staff-commission-delete:hover{
    background:#ef4444;
    color:#fff;
}
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
            border:none;background:#ef4444;color:#fff;border-radius:8px;
            padding:10px 14px;font-size:14px;font-weight:600;
        }

        .modal-xl-custom{max-width:980px}
        .po-modal-content{
            background:radial-gradient(circle at top left, rgba(25,43,120,.40), transparent 32%),
            radial-gradient(circle at bottom right, rgba(255,185,0,.12), transparent 28%),
            linear-gradient(180deg,#060916,#0b1031);
            border:none;border-radius:20px;overflow:hidden;color:#fff;
            box-shadow:0 30px 80px rgba(0,0,0,.35)
        }
        .po-modal-header{
            padding:18px 24px;border-bottom:1px solid rgba(255,255,255,.08);
            display:flex;justify-content:space-between;align-items:center
        }
        .po-modal-title{font-size:18px;font-weight:700;margin:0;color:#fff}
        .po-close{background:none;border:none;color:#d8def2;font-size:28px;line-height:1}
        .po-modal-body{padding:20px 24px 0}
        .po-modal-footer{
            padding:16px 24px;border-top:1px solid rgba(255,255,255,.08);
            display:flex;justify-content:flex-end;gap:12px;margin-top:16px
        }
        .dark-label{font-size:13px;font-weight:600;color:#f4f6fd;margin-bottom:6px}
        .req{color:#ffbf43}
        .dark-input,.dark-select{
            width:100%;border:none;outline:none;border-radius:10px;background:#f3f5fa;
            color:#1d2434;padding:12px 14px;font-size:14px;height:46px
        }
        .cancel-btn{
            min-width:130px;height:44px;border-radius:10px;border:none;background:#5d617a;color:#fff;font-weight:700
        }
        .save-btn{
            min-width:140px;height:44px;border-radius:10px;border:none;
            background:linear-gradient(180deg,#f2bc24,#d99d00);color:#fff;font-weight:800
        }

        @media(max-width:1200px){
            .stats-grid{grid-template-columns:repeat(2,1fr)}
        }
        @media(max-width:991px){
            .filter-row{grid-template-columns:1fr 1fr}
        }
        @media(max-width:768px){
            .page-header{flex-direction:column;align-items:stretch}
            .stats-grid{grid-template-columns:1fr}
            .filter-row{grid-template-columns:1fr}
        }
    

/* =========================================================
   FINAL STAFF COMMISSION POPUP BLACK + GOLD + WHITE THEME
   Add/Edit Commission Rate Modal
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

/* Modal size */
#rateModal .modal-dialog{
    max-width:min(980px,94vw) !important;
}

/* Main popup container */
#rateModal .modal-content,
#rateModal .po-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.62) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

/* Header */
#rateModal .modal-header,
#rateModal .po-modal-header{
    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
    min-height:76px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#rateModal .modal-title,
#rateModal .po-modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#rateModal .modal-title::before,
#rateModal .po-modal-title::before{
    content:'' !important;
    width:13px !important;
    height:13px !important;
    border-radius:50% !important;
    background:#c9a84c !important;
    box-shadow:0 0 0 5px rgba(201,168,76,.15) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

/* Close button - proper white X */
#rateModal .po-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
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
    width:22px !important;
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
    background:#c94b57 !important;
    transform:translateY(-1px) !important;
}

/* Body */
#rateModal .modal-body,
#rateModal .po-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#rateModal .po-modal-body::-webkit-scrollbar{
    width:7px !important;
}

#rateModal .po-modal-body::-webkit-scrollbar-track{
    background:#171c28 !important;
    border-radius:999px !important;
}

#rateModal .po-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c !important;
    border-radius:999px !important;
}

/* Footer */
#rateModal .modal-footer,
#rateModal .po-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.28) !important;
    padding:18px 24px 22px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:12px !important;
}

/* Labels */
#rateModal .dark-label,
#rateModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#rateModal .req{
    color:#f5c742 !important;
}

/* Inputs / selects / textarea */
#rateModal .dark-input,
#rateModal .dark-select,
#rateModal .dark-textarea,
#rateModal input[type="text"],
#rateModal input[type="number"],
#rateModal select,
#rateModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#rateModal .dark-input,
#rateModal .dark-select,
#rateModal input[type="text"],
#rateModal input[type="number"],
#rateModal select{
    min-height:50px !important;
    height:50px !important;
    padding:10px 16px !important;
}

#rateModal .dark-textarea,
#rateModal textarea{
    min-height:112px !important;
    height:auto !important;
    padding:14px 16px !important;
    resize:vertical !important;
}

#rateModal input::placeholder,
#rateModal textarea::placeholder{
    color:#8993a5 !important;
}

#rateModal .dark-input:focus,
#rateModal .dark-select:focus,
#rateModal .dark-textarea:focus,
#rateModal input:focus,
#rateModal select:focus,
#rateModal textarea:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Dynamic commission fields */
#rateModal #percentage_wrap,
#rateModal #fixed_amount_wrap{
    transition:all .18s ease !important;
}

/* Footer buttons */
#rateModal .cancel-btn{
    height:44px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#rateModal .cancel-btn:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#rateModal .save-btn{
    height:44px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#rateModal .save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px) !important;
}

/* Mobile */
@media (max-width:768px){
    #rateModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #rateModal .modal-title,
    #rateModal .po-modal-title{
        font-size:18px !important;
    }

    #rateModal .po-close{
        width:42px !important;
        height:42px !important;
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



/* =====================================================
   STAFF_COMMISSIONS.PHP - DARK PURPLE GLASS THEME
   Page + Compact Popup Override
   Backend / PHP / JS untouched
===================================================== */
:root{
    --sc-sidebar-open:270px;
    --sc-sidebar-close:76px;
    --sc-bg:#070b14;
    --sc-panel:#0f172a;
    --sc-text:#f8fafc;
    --sc-muted:#94a3b8;
    --sc-purple:#6d4cff;
    --sc-purple2:#7c3aed;
    --sc-blue:#3b82f6;
    --sc-green:#22c55e;
    --sc-yellow:#f59e0b;
    --sc-red:#ef4444;
    --sc-cyan:#06b6d4;
    --sc-border:rgba(148,163,184,.16);
}
html,body{min-height:100% !important;}
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--sc-text) !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        radial-gradient(circle at 82% 10%, rgba(59,130,246,.12), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
    overflow-x:hidden !important;
    --current-sidebar-width:var(--sc-sidebar-open);
}
body.dashboard-sidebar-collapsed{--current-sidebar-width:var(--sc-sidebar-close);}
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    width:100% !important;
    background:transparent !important;
}
.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease !important;
}
.sidebar > div{width:100% !important;min-height:100vh !important;height:100vh !important;}
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
    transition:width .25s ease,min-width .25s ease,max-width .25s ease !important;
}
.sidebar .sidebar-inner,
#dashboardSidebar .sidebar-inner,
#sidebar .sidebar-inner{width:100% !important;height:100vh !important;min-height:100vh !important;}
.sidebar .sidebar-menu,
#dashboardSidebar .sidebar-menu,
#sidebar .sidebar-menu{overflow-y:auto !important;overflow-x:hidden !important;}
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
    transition:margin-left .25s ease !important;
}
.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
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
    content:"";position:absolute;inset:0;pointer-events:none;z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}
.content-wrapper::after{
    content:"";position:absolute;width:520px;height:520px;right:-190px;top:-210px;pointer-events:none;z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);opacity:.85;
}
.content-wrapper > *{position:relative !important;z-index:1 !important;}
.page-header{display:flex !important;justify-content:space-between !important;align-items:center !important;gap:14px !important;flex-wrap:wrap !important;margin-bottom:18px !important;}
.page-title{
    font-size:30px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}
.btn-green{
    min-height:50px !important;
    border:none !important;
    border-radius:14px !important;
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:12px 20px !important;
    box-shadow:0 16px 34px rgba(34,197,94,.22),0 0 28px rgba(34,197,94,.12) !important;
    transition:.22s ease !important;
}
.btn-green:hover{transform:translateY(-2px) !important;color:#fff !important;}
.stats-grid{display:grid !important;grid-template-columns:repeat(4,1fr) !important;gap:16px !important;margin-bottom:18px !important;}
.stat-card{
    min-height:112px !important;
    color:#fff !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate !important;
    transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease,filter .25s ease !important;
}
.stat-card::before{
    content:"";position:absolute;inset:0;
    background:linear-gradient(135deg,rgba(255,255,255,.10),transparent 42%),radial-gradient(circle at 86% 10%,rgba(255,255,255,.16),transparent 28%);
    z-index:-1;
}
.stat-card::after{
    content:"" !important;position:absolute !important;right:14px !important;top:14px !important;width:76px !important;height:76px !important;border-radius:20px !important;
    background:rgba(255,255,255,.10) !important;z-index:-1 !important;
}
.stat-card:hover{transform:translateY(-4px) !important;border-color:rgba(255,255,255,.22) !important;filter:saturate(1.08) !important;box-shadow:0 26px 65px rgba(0,0,0,.46),0 0 48px rgba(124,58,237,.18) !important;}
.stat-title{font-size:15px !important;font-weight:900 !important;color:rgba(255,255,255,.94) !important;}
.stat-value{font-size:30px !important;font-weight:900 !important;color:#fff !important;position:relative !important;right:auto !important;bottom:auto !important;margin-top:10px !important;line-height:1 !important;}
.stat-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.stat-orange{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.filter-card{
    background:radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.54)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    padding:14px 16px !important;
    margin-bottom:18px !important;
    box-shadow:0 18px 48px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}
.filter-row{display:grid !important;grid-template-columns:repeat(5,minmax(130px,1fr)) 105px 105px !important;gap:10px !important;align-items:center !important;margin:0 !important;}
.erp-select,.erp-input{
    height:46px !important;width:100% !important;border:1px solid rgba(148,163,184,.20) !important;border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;color:#e5e7eb !important;padding:0 14px !important;outline:none !important;font-weight:800 !important;
    box-shadow:0 8px 22px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.04) !important;
}
.erp-select option{background:#0f172a !important;color:#e5e7eb !important;}
.erp-select:disabled,.erp-input:disabled{background:rgba(15,23,42,.50) !important;color:#94a3b8 !important;opacity:1 !important;cursor:not-allowed !important;}
.erp-select:focus,.erp-input:focus{background:rgba(17,24,39,.92) !important;color:#fff !important;border-color:rgba(124,58,237,.62) !important;box-shadow:0 0 0 3px rgba(124,58,237,.16),0 12px 26px rgba(0,0,0,.24) !important;}
.filter-btn,.reset-btn{
    height:46px !important;width:100% !important;border-radius:13px !important;border:none !important;font-weight:900 !important;
    display:inline-flex !important;align-items:center !important;justify-content:center !important;gap:6px !important;transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.20) !important;text-decoration:none !important;
}
.filter-btn{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;}
.reset-btn{background:rgba(15,23,42,.72) !important;color:#e5e7eb !important;border:1px solid rgba(148,163,184,.18) !important;}
.filter-btn:hover,.reset-btn:hover{transform:translateY(-2px) !important;color:#fff !important;border-color:rgba(139,92,246,.45) !important;box-shadow:0 18px 40px rgba(0,0,0,.36),0 0 34px rgba(124,58,237,.18) !important;}
.table-card{
    background:radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 18px 48px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    transition:.25s ease !important;
}
.table-card:hover{border-color:rgba(139,92,246,.45) !important;box-shadow:0 24px 65px rgba(0,0,0,.45),0 0 0 1px rgba(124,58,237,.16),0 0 42px rgba(124,58,237,.18),inset 0 1px 0 rgba(255,255,255,.06) !important;}
.table-wrap{overflow:auto !important;}
.erp-table{width:100% !important;min-width:1280px !important;border-collapse:collapse !important;background:transparent !important;}
.erp-table thead th{background:rgba(7,11,20,.76) !important;color:#cbd5e1 !important;font-weight:900 !important;font-size:13px !important;padding:15px 14px !important;border-right:1px solid rgba(148,163,184,.08) !important;border-bottom:1px solid rgba(148,163,184,.16) !important;white-space:nowrap !important;text-transform:uppercase !important;letter-spacing:.04em !important;}
.erp-table tbody td{padding:15px 14px !important;border-top:1px solid rgba(148,163,184,.10) !important;vertical-align:middle !important;font-size:14px !important;color:#e5e7eb !important;font-weight:700 !important;background:rgba(15,23,42,.30) !important;white-space:nowrap !important;}
.erp-table tbody tr:hover td{background:radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;color:#fff !important;}
.erp-table input[type="checkbox"]{width:16px !important;height:16px !important;accent-color:#7c3aed !important;cursor:pointer !important;}
.status-pill{padding:7px 12px !important;border-radius:999px !important;font-size:12px !important;font-weight:900 !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;text-transform:capitalize !important;}
.status-active{background:rgba(34,197,94,.16) !important;color:#4ade80 !important;border:1px solid rgba(34,197,94,.25) !important;}
.status-inactive{background:rgba(245,158,11,.16) !important;color:#fbbf24 !important;border:1px solid rgba(245,158,11,.25) !important;}
.status-pending{background:rgba(148,163,184,.14) !important;color:#cbd5e1 !important;border:1px solid rgba(148,163,184,.22) !important;}
.staff-commission-actions{display:flex !important;gap:10px !important;align-items:center !important;justify-content:center !important;}
.staff-commission-btn{width:40px !important;height:40px !important;border:none !important;border-radius:14px !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;cursor:pointer !important;transition:all .20s ease !important;box-shadow:0 12px 24px rgba(0,0,0,.18) !important;text-decoration:none !important;}
.staff-commission-btn:hover{transform:translateY(-2px) !important;}
.staff-commission-edit{background:rgba(245,158,11,.15) !important;color:#fbbf24 !important;border:1px solid rgba(245,158,11,.24) !important;}
.staff-commission-delete{background:rgba(239,68,68,.14) !important;color:#f87171 !important;border:1px solid rgba(239,68,68,.24) !important;}
.table-footer{padding:14px 16px !important;border-top:1px solid rgba(148,163,184,.13) !important;background:rgba(7,11,20,.30) !important;display:flex !important;justify-content:space-between !important;align-items:center !important;gap:12px !important;flex-wrap:wrap !important;color:#94a3b8 !important;font-weight:800 !important;}
.footer-left{color:#94a3b8 !important;font-weight:800 !important;}
.footer-right{display:flex !important;align-items:center !important;gap:10px !important;flex-wrap:wrap !important;}
.pager-btn,.pager-num{border-radius:12px !important;border:1px solid rgba(148,163,184,.18) !important;background:rgba(15,23,42,.72) !important;color:#e5e7eb !important;font-weight:900 !important;}
.pager-btn:hover{background:rgba(124,58,237,.20) !important;color:#fff !important;}
.bulk-delete-btn{border:none !important;background:linear-gradient(135deg,#be123c,#ef4444) !important;color:#fff !important;border-radius:12px !important;padding:10px 14px !important;font-size:14px !important;font-weight:900 !important;box-shadow:0 12px 24px rgba(239,68,68,.18) !important;transition:.22s ease !important;}
.bulk-delete-btn:hover{transform:translateY(-2px) !important;}

/* =============== COMPACT DARK PURPLE GLASS POPUP =============== */
body.modal-open .modal-backdrop.show,.modal-backdrop.show{opacity:.58 !important;background:#000 !important;}
#rateModal .modal-dialog,
#rateModal .modal-dialog.modal-xl-custom{
    max-width:760px !important;
    width:760px !important;
    margin:18px auto !important;
}
#rateModal .modal-content,
#rateModal .po-modal-content{
    max-height:82vh !important;
    background:radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),linear-gradient(135deg, rgba(15,23,42,.88), rgba(17,24,39,.72)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.36) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 24px 70px rgba(0,0,0,.58),0 0 45px rgba(124,58,237,.18),inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
}
#rateModal .po-modal-header,
#rateModal .modal-header{
    min-height:54px !important;
    padding:12px 18px !important;
    background:rgba(7,11,20,.34) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    display:flex !important;align-items:center !important;justify-content:space-between !important;
}
#rateModal .po-modal-title,
#rateModal .modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
    margin:0 !important;
    letter-spacing:-.02em !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}
#rateModal .po-modal-title::before,
#rateModal .modal-title::before{content:"" !important;width:10px !important;height:10px !important;border-radius:50% !important;background:#7c3aed !important;box-shadow:0 0 0 5px rgba(124,58,237,.16),0 0 22px rgba(124,58,237,.42) !important;}
#rateModal .po-close,
#rateModal .btn-close{
    position:relative !important;width:36px !important;height:36px !important;border-radius:10px !important;background:rgba(148,163,184,.16) !important;border:1px solid rgba(148,163,184,.18) !important;color:transparent !important;opacity:1 !important;padding:0 !important;font-size:0 !important;line-height:1 !important;background-image:none !important;
}
#rateModal .po-close::before,#rateModal .po-close::after,
#rateModal .btn-close::before,#rateModal .btn-close::after{content:"" !important;position:absolute !important;left:50% !important;top:50% !important;width:16px !important;height:2px !important;background:#e5e7eb !important;border-radius:999px !important;}
#rateModal .po-close::before,#rateModal .btn-close::before{transform:translate(-50%,-50%) rotate(45deg) !important;}
#rateModal .po-close::after,#rateModal .btn-close::after{transform:translate(-50%,-50%) rotate(-45deg) !important;}
#rateModal .po-close:hover,#rateModal .btn-close:hover{background:rgba(239,68,68,.24) !important;}
#rateModal .po-modal-body,
#rateModal .modal-body{
    padding:12px 18px !important;
    max-height:62vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    background:rgba(15,23,42,.22) !important;
    color:#f8fafc !important;
}
#rateModal .po-modal-body::-webkit-scrollbar{width:8px !important;}
#rateModal .po-modal-body::-webkit-scrollbar-track{background:rgba(255,255,255,.06) !important;border-radius:999px !important;}
#rateModal .po-modal-body::-webkit-scrollbar-thumb{background:rgba(124,58,237,.70) !important;border-radius:999px !important;}
#rateModal .row{--bs-gutter-x:14px !important;--bs-gutter-y:8px !important;}
#rateModal .mb-3{margin-bottom:10px !important;}
#rateModal .dark-label,
#rateModal label{color:#e5e7eb !important;font-size:12px !important;font-weight:900 !important;margin-bottom:5px !important;letter-spacing:0 !important;}
#rateModal .req{color:#c4b5fd !important;}
#rateModal .dark-input,
#rateModal .dark-select,
#rateModal input[type="text"],
#rateModal input[type="number"],
#rateModal select{
    height:38px !important;min-height:38px !important;padding:6px 12px !important;font-size:13px !important;border-radius:9px !important;
    background:rgba(255,255,255,.94) !important;color:#111827 !important;border:1px solid rgba(203,213,225,.82) !important;font-weight:700 !important;box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}
#rateModal .dark-input:focus,
#rateModal .dark-select:focus,
#rateModal input:focus,
#rateModal select:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.20),0 8px 18px rgba(0,0,0,.18) !important;
    outline:none !important;
}
#rateModal .dark-input::placeholder{color:#64748b !important;}
#rateModal .po-modal-footer,
#rateModal .modal-footer{
    padding:10px 18px !important;
    min-height:52px !important;
    margin-top:0 !important;
    background:rgba(7,11,20,.34) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}
#rateModal .cancel-btn,
#rateModal .save-btn{height:38px !important;min-width:105px !important;font-size:13px !important;border-radius:9px !important;font-weight:900 !important;}
#rateModal .cancel-btn{background:rgba(15,23,42,.70) !important;color:#e5e7eb !important;border:1px solid rgba(148,163,184,.18) !important;box-shadow:0 10px 20px rgba(0,0,0,.18) !important;}
#rateModal .save-btn{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;border:none !important;box-shadow:0 12px 26px rgba(124,58,237,.24) !important;}
#rateModal .cancel-btn:hover,#rateModal .save-btn:hover{transform:translateY(-1px) !important;}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr) !important;}.filter-row{grid-template-columns:repeat(3,1fr) !important;}}
@media(max-width:991px){.container-scroll{flex-direction:column !important;}.sidebar{width:100% !important;min-width:100% !important;max-width:100% !important;position:relative !important;height:auto !important;}.sidebar .sidebar-wrap,#dashboardSidebar .sidebar-wrap,#sidebar .sidebar-wrap{position:relative !important;width:100% !important;min-width:100% !important;max-width:100% !important;height:auto !important;min-height:auto !important;}.main-panel{margin-left:0 !important;}.topbar{position:relative !important;}.content-wrapper{padding:18px !important;}#rateModal .modal-dialog,#rateModal .modal-dialog.modal-xl-custom{width:calc(100% - 24px) !important;max-width:calc(100% - 24px) !important;margin:12px auto !important;}}
@media(max-width:768px){.stats-grid{grid-template-columns:1fr !important;}.filter-row{grid-template-columns:1fr !important;}.page-header{flex-direction:column !important;align-items:stretch !important;}.btn-green,.filter-btn,.reset-btn,.erp-select,.erp-input{width:100% !important;}.table-footer,.footer-right{flex-direction:column !important;align-items:stretch !important;}.pager-btn,.pager-num,.bulk-delete-btn{width:100% !important;}#rateModal .po-modal-footer,#rateModal .modal-footer{flex-direction:column !important;align-items:stretch !important;}#rateModal .cancel-btn,#rateModal .save-btn{width:100% !important;}}

/* =========================================================
   STAFF COMMISSIONS PAGE - FINAL LIGHT MODE
   Is block ko final
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   MAIN PAGE
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

/* Content background */
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
            #059669,
            #16a34a
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 12px 26px rgba(16,185,129,.20) !important;
}

html[data-pos-theme="light"] body .btn-green:hover{
    color:#ffffff !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.24) !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .stat-title,
html[data-pos-theme="light"] body .stat-card .stat-value{
    color:#ffffff !important;
    opacity:1 !important;
}

/* Colored cards remain colored */
html[data-pos-theme="light"] body .stat-blue{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
}

html[data-pos-theme="light"] body .stat-orange{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
}

html[data-pos-theme="light"] body .stat-green{
    background:linear-gradient(135deg,#047857,#16a34a) !important;
}

html[data-pos-theme="light"] body .stat-cyan{
    background:linear-gradient(135deg,#0891b2,#06b6d4) !important;
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

/* Inputs and selects */
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

/* Disabled date fields */
html[data-pos-theme="light"] body .erp-select:disabled,
html[data-pos-theme="light"] body .erp-input:disabled{
    background:#f1f5f9 !important;
    color:#64748b !important;
    border-color:#d8e0eb !important;
    opacity:1 !important;
}

/* Date input */
html[data-pos-theme="light"] body input[type="date"]{
    color-scheme:light !important;
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

/* Bootstrap dark table variables reset */
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

/* Table header */
html[data-pos-theme="light"] body .erp-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-right:1px solid #e2e8f0 !important;
    border-bottom:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

/* Normal table rows */
html[data-pos-theme="light"] body .erp-table tbody,
html[data-pos-theme="light"] body .erp-table tbody tr,
html[data-pos-theme="light"] body .erp-table tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-top-color:#e8edf4 !important;
    border-bottom-color:#e8edf4 !important;

    box-shadow:none !important;
}

/* Row hover */
html[data-pos-theme="light"] body .erp-table tbody tr:hover td,
html[data-pos-theme="light"] body .erp-table tbody td:hover{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;

    color:#0f172a !important;
    box-shadow:none !important;
}

/* Selected/focused row */
html[data-pos-theme="light"] body .erp-table tbody tr:focus td,
html[data-pos-theme="light"] body .erp-table tbody tr:active td,
html[data-pos-theme="light"] body .erp-table tbody td:focus{
    background:#f3efff !important;
    color:#0f172a !important;
    box-shadow:none !important;
}

/* Empty table */
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
   STATUS PILLS
========================================================= */

html[data-pos-theme="light"] body .status-active{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .status-inactive{
    background:#fffbeb !important;
    color:#b45309 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .status-pending{
    background:#f1f5f9 !important;
    color:#475569 !important;
    border:1px solid #cbd5e1 !important;
}

/* =========================================================
   ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .staff-commission-btn{
    box-shadow:
        0 5px 12px rgba(15,23,42,.07) !important;
}

/* Edit */
html[data-pos-theme="light"] body .staff-commission-edit{
    background:#fffbeb !important;
    color:#d97706 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .staff-commission-edit:hover{
    background:#f59e0b !important;
    color:#ffffff !important;
}

/* Delete */
html[data-pos-theme="light"] body .staff-commission-delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border:1px solid #fecdd3 !important;
}

html[data-pos-theme="light"] body .staff-commission-delete:hover{
    background:#ef4444 !important;
    color:#ffffff !important;
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

/* Pagination */
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

html[data-pos-theme="light"] body .pager-num{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border-color:#7c3aed !important;
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
   ADD / EDIT COMMISSION MODAL
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
#rateModal .po-modal-header,

html[data-pos-theme="light"] body
#rateModal .modal-header{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body
#rateModal .po-modal-title,

html[data-pos-theme="light"] body
#rateModal .modal-title{
    color:#0f172a !important;
}

/* Modal title dot */
html[data-pos-theme="light"] body
#rateModal .po-modal-title::before,

html[data-pos-theme="light"] body
#rateModal .modal-title::before{
    background:#7c3aed !important;

    box-shadow:
        0 0 0 4px rgba(124,58,237,.14) !important;
}

/* Close button */
html[data-pos-theme="light"] body
#rateModal .po-close,

html[data-pos-theme="light"] body
#rateModal .btn-close{
    background:#ffffff !important;
    background-image:none !important;

    color:transparent !important;

    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body
#rateModal .po-close::before,

html[data-pos-theme="light"] body
#rateModal .po-close::after,

html[data-pos-theme="light"] body
#rateModal .btn-close::before,

html[data-pos-theme="light"] body
#rateModal .btn-close::after{
    background:#334155 !important;
}

html[data-pos-theme="light"] body
#rateModal .po-close:hover,

html[data-pos-theme="light"] body
#rateModal .btn-close:hover{
    background:#fff1f2 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body
#rateModal .po-close:hover::before,

html[data-pos-theme="light"] body
#rateModal .po-close:hover::after,

html[data-pos-theme="light"] body
#rateModal .btn-close:hover::before,

html[data-pos-theme="light"] body
#rateModal .btn-close:hover::after{
    background:#e11d48 !important;
}

/* Modal body */
html[data-pos-theme="light"] body
#rateModal .po-modal-body,

html[data-pos-theme="light"] body
#rateModal .modal-body{
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

/* Modal fields */
html[data-pos-theme="light"] body
#rateModal .dark-input,

html[data-pos-theme="light"] body
#rateModal .dark-select,

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
#rateModal .po-modal-footer,

html[data-pos-theme="light"] body
#rateModal .modal-footer{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

/* Cancel */
html[data-pos-theme="light"] body
#rateModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
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
                <h1 class="page-title">Staff Commission</h1>
                <button type="button" class="btn-green" onclick="openRateModal()">
                    <i class="bi bi-plus-lg me-1"></i> Add Rate
                </button>
            </div>

            <div class="stats-grid">
                <div class="stat-card stat-blue">
                    <div class="stat-title">Total Rates</div>
                    <div class="stat-value"><?= number_format($totalRates, 0) ?></div>
                </div>
                <div class="stat-card stat-orange">
                    <div class="stat-title">Active Rates</div>
                    <div class="stat-value"><?= number_format($activeRates, 0) ?></div>
                </div>
                <div class="stat-card stat-green">
                    <div class="stat-title">Inactive Rates</div>
                    <div class="stat-value"><?= number_format($inactiveRates, 0) ?></div>
                </div>
                <div class="stat-card stat-cyan">
                    <div class="stat-title">Pending Rates</div>
                    <div class="stat-value"><?= number_format($pendingRates, 0) ?></div>
                </div>
            </div>

            <div class="filter-card">
                <form method="GET">
                    <div class="filter-row">
                        <select class="erp-select" name="staff_id">
                            <option value="">All Staff</option>
                            <?php foreach ($staffList as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= $staffFilter === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select class="erp-select" name="service_id">
                            <option value="">All Services</option>
                            <?php foreach ($services as $srv): ?>
                                <option value="<?= (int)$srv['id'] ?>" <?= $serviceFilter === (int)$srv['id'] ? 'selected' : '' ?>>
                                    <?= e($srv['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input type="date" class="erp-input" name="start_date" value="<?= e($startDate) ?>" <?= !$hasCreatedAt ? 'disabled' : '' ?>>
                        <input type="date" class="erp-input" name="end_date" value="<?= e($endDate) ?>" <?= !$hasCreatedAt ? 'disabled' : '' ?>>

                        <select class="erp-select" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        </select>

                        <button type="submit" class="filter-btn">
                            <i class="bi bi-search me-1"></i> Filter
                        </button>

                        <a href="staff_commission.php" class="reset-btn">
                            <i class="bi bi-arrow-clockwise me-1"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <div class="table-card">
                <div class="table-wrap">
                    <table class="erp-table" id="commissionTable">
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
                            <?php if (empty($commissions)): ?>
                                <tr><td colspan="10" class="text-center py-4">No commission found</td></tr>
                            <?php else: ?>
                                <?php foreach ($commissions as $r): ?>
                                    <?php
                                        $statusClass = 'status-pending';
                                        if ($r['status'] === 'active') $statusClass = 'status-active';
                                        if ($r['status'] === 'inactive') $statusClass = 'status-inactive';
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="row-check"
                                                value='<?= e(json_encode([
                                                    "user_id" => (int)$r["user_id"],
                                                    "service_id" => (int)$r["service_id"]
                                                ])) ?>'>
                                        </td>
                                        <td><?= e($r['staff_name']) ?></td>
                                        <td><?= e($r['service_name']) ?></td>
                                        <td><?= e(ucfirst($r['commission_type'])) ?></td>
                                        <td><?= e($r['commission_rate']) ?></td>
                                        <td><?= e($r['min_sale_target']) ?></td>
                                        <td><?= e($r['max_cap']) ?></td>
                                        <td><?= e($r['branch_name']) ?></td>
                                        <td><span class="status-pill <?= $statusClass ?>"><?= e($r['status']) ?></span></td>
                                       <td>
    <div class="staff-commission-actions">
        <button type="button"
            class="staff-commission-btn staff-commission-edit editRate"
            data-user="<?= (int)$r['user_id'] ?>"
            data-service="<?= (int)$r['service_id'] ?>"
            data-percentage="<?= e($r['percentage']) ?>"
            data-fixed-amount="<?= e($r['fixed_amount']) ?>"
            data-commission-type="<?= e($r['commission_type']) ?>"
            data-min-sale-target="<?= e($r['min_sale_target']) ?>"
            data-max-cap="<?= e($r['max_cap']) ?>"
            data-branch-id="<?= (int)$r['branch_id'] ?>"
            data-status="<?= e($r['status']) ?>"
            data-tiered-config="<?= e($r['tiered_config']) ?>"
            title="Edit Rate">
            <i class="bi bi-pencil-fill"></i>
        </button>

        <button type="button"
            class="staff-commission-btn staff-commission-delete deleteRate"
            data-user="<?= (int)$r['user_id'] ?>"
            data-service="<?= (int)$r['service_id'] ?>"
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
                    <div class="footer-left">
                        Showing <?= $paginationStart ?> to <?= $paginationEnd ?> of <?= $totalRows ?> entries
                    </div>

                    <div class="footer-right">
                        <button type="button" class="bulk-delete-btn" onclick="deleteSelected()">
                            Delete Selected
                        </button>

                        <button type="button" class="pager-btn" id="prev-page"><i class="bi bi-chevron-left"></i></button>
                        <div class="pager-num" id="current-page"><?= $currentPage ?></div>
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
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Staff <span class="req">*</span></label>
                            <select name="user_id" id="user_id" class="dark-select" required>
                                <option value="">Select Staff</option>
                                <?php foreach($staffList as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Service <span class="req">*</span></label>
                            <select name="service_id" id="service_id" class="dark-select" required>
                                <option value="">Select Service</option>
                                <?php foreach($services as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Commission Type <span class="req">*</span></label>
                            <select name="commission_type" id="commission_type" class="dark-select" required>
                                <option value="percentage">Percentage</option>
                                <option value="fixed">Fixed Per Service</option>
                                <option value="tiered">Tiered Commission</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3" id="percentage_wrap">
                            <label class="dark-label">Rate / Percentage <span class="req">*</span></label>
                            <input type="number" step="0.01" min="0" name="percentage" id="percentage" class="dark-input">
                        </div>

                        <div class="col-md-6 mb-3" id="fixed_amount_wrap" style="display:none;">
                            <label class="dark-label">Fixed Amount <span class="req">*</span></label>
                            <input type="number" step="0.01" min="0" name="fixed_amount" id="fixed_amount" class="dark-input">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Min Sale Target</label>
                            <input type="number" step="0.01" min="0" name="min_sale_target" id="min_sale_target" class="dark-input">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Max Commission Cap</label>
                            <input type="number" step="0.01" min="0" name="max_cap" id="max_cap" class="dark-input">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Branch <span class="req">*</span></label>
                            <select name="branch_id" id="branch_id" class="dark-select" required>
                                <option value="">Select Branch</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Status <span class="req">*</span></label>
                            <select name="status" id="status" class="dark-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="dark-label">Tiered Configuration</label>
                            <input type="text" name="tiered_config" id="tiered_config" class="dark-input" placeholder='For tiered, enter JSON like [{"from":0,"to":10000,"rate":5}]'>
                        </div>
                    </div>
                </div>

                <div class="po-modal-footer">
                    <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="save-btn" id="saveRateBtn">Save Rate</button>
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
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
    
const rateModal = new bootstrap.Modal(document.getElementById('rateModal'));

function toggleCommissionFields() {
    const type = document.getElementById('commission_type').value;
    const percentageWrap = document.getElementById('percentage_wrap');
    const fixedWrap = document.getElementById('fixed_amount_wrap');
    const percentage = document.getElementById('percentage');
    const fixedAmount = document.getElementById('fixed_amount');

    percentageWrap.style.display = 'none';
    fixedWrap.style.display = 'none';

    percentage.required = false;
    fixedAmount.required = false;

    if (type === 'percentage') {
        percentageWrap.style.display = '';
        percentage.required = true;
        fixedAmount.value = '';
    } else if (type === 'fixed') {
        fixedWrap.style.display = '';
        fixedAmount.required = true;
        percentage.value = '';
    } else if (type === 'tiered') {
        percentageWrap.style.display = '';
        percentage.required = false;
        fixedAmount.value = '';
        percentage.value = '';
    }
}

function openRateModal() {
    document.getElementById('rateForm').reset();
    document.getElementById('edit_user').value = '';
    document.getElementById('edit_service').value = '';
    document.getElementById('rateModalTitle').innerText = 'Add Rate';
    document.getElementById('saveRateBtn').innerText = 'Save Rate';
    document.getElementById('status').value = 'active';
    document.getElementById('commission_type').value = 'percentage';
    toggleCommissionFields();
    rateModal.show();
}

document.getElementById('commission_type').addEventListener('change', toggleCommissionFields);

document.querySelectorAll('.editRate').forEach(btn => {
    btn.addEventListener('click', function () {
        const d = this.dataset;
        document.getElementById('edit_user').value = d.user || '';
        document.getElementById('edit_service').value = d.service || '';
        document.getElementById('user_id').value = d.user || '';
        document.getElementById('service_id').value = d.service || '';
        document.getElementById('commission_type').value = d.commissionType || 'percentage';
        document.getElementById('percentage').value = d.percentage || '';
        document.getElementById('fixed_amount').value = d.fixedAmount || '';
        document.getElementById('min_sale_target').value = d.minSaleTarget || '';
        document.getElementById('max_cap').value = d.maxCap || '';
        document.getElementById('branch_id').value = d.branchId || '';
        document.getElementById('status').value = d.status || 'active';
        document.getElementById('tiered_config').value = d.tieredConfig || '';

        document.getElementById('rateModalTitle').innerText = 'Edit Rate';
        document.getElementById('saveRateBtn').innerText = 'Update Rate';

        toggleCommissionFields();
        rateModal.show();
    });
});

document.querySelectorAll('.deleteRate').forEach(btn => {
    btn.addEventListener('click', function () {
        const user_id = this.dataset.user;
        const service_id = this.dataset.service;

        if (!confirm('Delete this rate?')) return;

        $.post('ajax_staff_commission_rates.php', {
            action: 'delete',
            user_id: user_id,
            service_id: service_id
        }, function(resp) {
            if (resp.trim() === 'ok') {
                location.reload();
            } else {
                alert(resp);
            }
        });
    });
});

document.getElementById('rateForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'save');

    fetch('ajax_staff_commission_rates.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(resp => {
        if (resp.trim() === 'ok') {
            location.reload();
        } else {
            alert(resp);
            console.log(resp);
        }
    })
    .catch((err) => {
        console.error(err);
        alert('Something went wrong. Please try again.');
    });
});

const checkAll = document.getElementById('checkAll');
checkAll?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(ch => ch.checked = this.checked);
});

function deleteSelected() {
    const selected = Array.from(document.querySelectorAll('.row-check:checked'))
        .map(cb => JSON.parse(cb.value));

    if (selected.length === 0) {
        alert('No items selected');
        return;
    }

    if (!confirm(`Are you sure you want to delete ${selected.length} items?`)) return;

    fetch('ajax_staff_commission_rates.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=bulk_delete&ids=' + encodeURIComponent(JSON.stringify(selected))
    })
    .then(res => res.text())
    .then(res => {
        if (res.trim() === 'ok') {
            alert('Deleted successfully');
            location.reload();
        } else {
            alert('Delete failed: ' + res);
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    toggleCommissionFields();

    let currentPage = 1;
    const rowsPerPage = 10;
    const tableRows = Array.from(document.querySelectorAll('#commissionTable tbody tr'))
        .filter(row => row.querySelectorAll('td').length > 1);
    const totalPages = Math.max(1, Math.ceil(tableRows.length / rowsPerPage));

    function showPage(page) {
        currentPage = page;
        tableRows.forEach((row, idx) => {
            row.style.display = (idx >= (page - 1) * rowsPerPage && idx < page * rowsPerPage) ? '' : 'none';
        });

        document.getElementById('current-page').textContent = page;

        const start = tableRows.length ? ((page - 1) * rowsPerPage) + 1 : 0;
        const end = Math.min(page * rowsPerPage, tableRows.length);
        const footerLeft = document.querySelector('.footer-left');
        if (footerLeft) {
            footerLeft.textContent = `Showing ${start} to ${end} of ${tableRows.length} entries`;
        }
    }

    document.getElementById('prev-page').addEventListener('click', () => {
        if (currentPage > 1) showPage(currentPage - 1);
    });

    document.getElementById('next-page').addEventListener('click', () => {
        if (currentPage < totalPages) showPage(currentPage + 1);
    });

    showPage(1);
});
</script>
</body>
</html>