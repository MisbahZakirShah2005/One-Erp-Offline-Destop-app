<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
}
$client_id = (int)$_SESSION['client_id'];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$staffFilter = (int)($_GET['staff_id'] ?? 0);

/* ================= FETCH STAFF ================= */
$staff = [];
$sql = "SELECT id, name, email, phone FROM users WHERE client_id=?";
$params = [$client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($staffFilter > 0) {
    $sql .= " AND id=?";
    $params[] = $staffFilter;
    $types .= "i";
}

$sql .= " ORDER BY name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $staff[] = $row;
}
$stmt->close();

/* ================= TODAY ATTENDANCE ================= */
$attendance = [];
$stmt = $conn->prepare("SELECT * FROM staff_attendance WHERE client_id=? AND attendance_date=?");
$stmt->bind_param("is", $client_id, $today);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $attendance[$row['staff_id']] = $row;
}
$stmt->close();

/* ================= APPLY STATUS FILTER IN PHP ================= */
if ($statusFilter !== '') {
    $staff = array_values(array_filter($staff, function ($s) use ($attendance, $statusFilter) {
        $att = $attendance[$s['id']] ?? null;
        return (($att['status'] ?? '') === $statusFilter);
    }));
}

/* ================= STATS ================= */
$totalStaff = count($staff);
$presentCount = 0;
$absentCount = 0;
$leaveCount = 0;

foreach ($staff as $s) {
    $status = strtolower(trim($attendance[$s['id']]['status'] ?? ''));
    if ($status === 'present') $presentCount++;
    elseif ($status === 'absent') $absentCount++;
    elseif ($status === 'leave') $leaveCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Attendance</title>
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

        .page-header{
            display:flex;justify-content:space-between;align-items:center;gap:12px;
            margin-bottom:16px;flex-wrap:wrap
        }
        .page-title{
            font-size:22px;font-weight:800;margin:0;color:#243046
        }
        .page-title span{font-weight:500;color:#6c7589}

        .top-actions{display:flex;gap:10px;flex-wrap:wrap}
        .btn-gold,.btn-lightx,.btn-darkx{
            border:none;border-radius:12px;padding:12px 18px;font-weight:800
        }
        .btn-gold{background:linear-gradient(180deg,#ffd25f,#f0aa00);color:#2a2100}
        .btn-lightx{background:#f5f7fb;color:#334055}
        .btn-darkx{background:#1f2642;color:#fff}

        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
        .stat-card{
            border-radius:14px;padding:18px;min-height:92px;color:#fff;position:relative;
            overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12)
        }
        .stat-card::after{
            content:'';position:absolute;width:110px;height:110px;border-radius:50%;
            right:-18px;top:-18px;background:rgba(255,255,255,.12)
        }
        .stat-blue{background:linear-gradient(135deg,#5e84ff,#7fa9ff)}
        .stat-green{background:linear-gradient(135deg,#4ea98d,#66c09f)}
        .stat-red{background:linear-gradient(135deg,#d35e74,#b33e56)}
        .stat-gold{background:linear-gradient(135deg,#cc9d1d,#a67b0d)}
        .stat-title{font-size:14px;font-weight:600;line-height:1.3}
        .stat-value{position:absolute;right:18px;bottom:14px;font-size:30px;font-weight:800;line-height:1}

        .filter-card,.table-card{
            background:#fff;border:1px solid #e6ebf3;border-radius:16px;
            box-shadow:0 8px 22px rgba(18,38,63,.05)
        }
        .filter-card{padding:14px;margin-bottom:16px}
        .filter-row{
            display:grid;grid-template-columns:1.2fr 1fr 1fr 170px 170px;
            gap:10px
        }
        .erp-input,.erp-select,.filter-btn{
            height:48px;border:1px solid #dbe2ed;border-radius:10px;
            background:#fff;padding:0 14px;outline:none;width:100%
        }
        .filter-btn{
            display:flex;align-items:center;justify-content:center;
            font-weight:700;border:none;background:#2959d4;color:#fff
        }

        .table-card{overflow:hidden}
        .table-wrap{overflow:auto}
        .erp-table{
            width:100%;min-width:1180px;border-collapse:separate;border-spacing:0
        }
        .erp-table thead th{
            background:#1a1f2e;color:#fff;padding:14px 12px;font-size:14px;font-weight:600;
            white-space:nowrap
        }
        .erp-table tbody td{
            background:#fff;padding:14px 12px;border-bottom:1px solid #edf1f6;
            vertical-align:middle;white-space:nowrap
        }
        .erp-table tbody tr:hover td{background:#fafcff}
        .erp-table thead th:first-child{border-top-left-radius:12px}
        .erp-table thead th:last-child{border-top-right-radius:12px}

        .staff-cell{
            display:flex;align-items:center;gap:12px
        }
        .avatar{
            width:46px;height:46px;border-radius:50%;
            background:linear-gradient(135deg,#9bb6ff,#4b7af3);
            color:#fff;display:flex;align-items:center;justify-content:center;
            font-weight:800;font-size:18px;flex-shrink:0
        }
        .staff-name{font-weight:700;color:#243046}
        .staff-sub{font-size:12px;color:#7a8598;margin-top:3px}

        .status-select,.time-input{
            height:46px;border:1px solid #dbe2ed;border-radius:10px;
            padding:0 14px;background:#fff;outline:none;width:100%
        }
        .time-wrap{position:relative}
        .time-wrap i{
            position:absolute;right:14px;top:50%;transform:translateY(-50%);
            color:#5f6980
        }

        .save-btn{
            border:none;border-radius:10px;background:linear-gradient(180deg,#ffd25f,#f0aa00);
            color:#2a2100;font-weight:800;padding:10px 18px
        }

        .bulk-menu-wrap{position:relative}
        .bulk-menu{
            position:absolute;top:54px;left:0;width:220px;background:#fff;border:1px solid #e6ebf3;
            border-radius:14px;box-shadow:0 20px 40px rgba(0,0,0,.14);padding:8px;display:none;z-index:50
        }
        .bulk-item{
            width:100%;border:none;background:#fff;padding:12px 14px;border-radius:10px;
            text-align:left;font-weight:700;color:#334055
        }
        .bulk-item:hover{background:#f5f7fb}

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

        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:991px){.filter-row{grid-template-columns:1fr 1fr}}
        @media(max-width:768px){
            .stats-grid{grid-template-columns:1fr}
            .filter-row{grid-template-columns:1fr}
            .page-header{flex-direction:column;align-items:stretch}
        }
    
/* =====================================================
   STAFF ATTENDANCE - DARK PURPLE GLASS HOVER THEME
   Backend / PHP / JS untouched
===================================================== */

html, body{
    min-height:100% !important;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-panel:#0f172a;
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-yellow:#f59e0b;
    --oms-red:#ef4444;
    --oms-cyan:#06b6d4;

    --oms-border:rgba(148,163,184,.16);
}

/* ================= BASE ================= */
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.18), transparent 30%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.12), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
    overflow-x:hidden !important;
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT ================= */
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
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
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
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
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
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
    position:relative !important;
    z-index:1 !important;
}

/* ================= PAGE HEADER ================= */
.page-header{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-bottom:18px !important;
}

.page-title{
    font-size:30px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

.page-title span{
    color:#c4b5fd !important;
    font-weight:800 !important;
}

.top-actions{
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

/* ================= BUTTONS ================= */
.btn-gold,
.btn-lightx,
.btn-darkx,
.save-btn,
.filter-btn,
.bulk-item,
.pager-btn{
    min-height:42px !important;
    border-radius:13px !important;
    font-weight:900 !important;
    border:none !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
}

.btn-gold,
.save-btn{
    background:linear-gradient(135deg,#f59e0b,#fbbf24) !important;
    color:#1f1600 !important;
}

.btn-darkx,
.filter-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

.btn-lightx{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.btn-gold:hover,
.btn-lightx:hover,
.btn-darkx:hover,
.save-btn:hover,
.filter-btn:hover,
.pager-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    box-shadow:
        0 18px 40px rgba(0,0,0,.36),
        0 0 34px rgba(124,58,237,.18) !important;
}

/* ================= STAT CARDS ================= */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    color:#fff !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
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
    z-index:-1;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
.stat-green{ background:linear-gradient(135deg,#047857,#16a34a) !important; }
.stat-red{ background:linear-gradient(135deg,#be123c,#ef4444) !important; }
.stat-gold{ background:linear-gradient(135deg,#f59e0b,#fb923c) !important; }

.stat-title{
    font-size:15px !important;
    font-weight:900 !important;
    color:rgba(255,255,255,.94) !important;
}

.stat-value{
    position:absolute !important;
    right:20px !important;
    bottom:16px !important;
    font-size:30px !important;
    font-weight:900 !important;
    color:#fff !important;
}

/* ================= FILTER GLASS CARD ================= */
.filter-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.54)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    padding:14px 16px !important;
    margin-bottom:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.filter-row{
    display:grid !important;
    grid-template-columns:1.2fr 1fr 1fr 150px 150px !important;
    gap:10px !important;
    align-items:center !important;
}

.erp-input,
.erp-select,
.status-select,
.time-input{
    height:46px !important;
    width:100% !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    padding:0 14px !important;
    outline:none !important;
    font-weight:800 !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

.erp-input::placeholder{
    color:#94a3b8 !important;
}

.erp-select option,
.status-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.erp-input:focus,
.erp-select:focus,
.status-select:focus,
.time-input:focus{
    background:rgba(17,24,39,.92) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

.filter-row a.filter-btn{
    text-decoration:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

/* ================= TABLE GLASS ================= */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    transition:.25s ease !important;
}

.table-card:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.table-wrap{
    overflow:auto !important;
}

.erp-table{
    width:100% !important;
    min-width:1180px !important;
    border-collapse:collapse !important;
    background:transparent !important;
}

.erp-table thead th{
    background:rgba(7,11,20,.76) !important;
    color:#cbd5e1 !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:15px 14px !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.erp-table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.30) !important;
    white-space:nowrap !important;
}

.erp-table tbody tr:hover td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color:#fff !important;
    box-shadow:none !important;
}

.erp-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

/* ================= STAFF CELL ================= */
.staff-cell{
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
}

.avatar{
    width:46px !important;
    height:46px !important;
    border-radius:16px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-weight:900 !important;
    font-size:18px !important;
    flex-shrink:0 !important;
    box-shadow:0 12px 26px rgba(124,58,237,.24) !important;
    border:1px solid rgba(255,255,255,.12) !important;
}

.staff-name{
    font-weight:900 !important;
    color:#f8fafc !important;
}

.staff-sub{
    font-size:12px !important;
    color:#94a3b8 !important;
    margin-top:3px !important;
    font-weight:700 !important;
}

.time-wrap{
    position:relative !important;
}

.time-wrap i{
    position:absolute !important;
    right:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#a78bfa !important;
    pointer-events:none !important;
}

/* ================= BULK MENU ================= */
.bulk-menu{
    position:absolute !important;
    top:54px !important;
    left:0 !important;
    width:230px !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.92), rgba(17,24,39,.86)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:16px !important;
    box-shadow:
        0 20px 50px rgba(0,0,0,.45),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    padding:8px !important;
    display:none;
    z-index:50 !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.bulk-item{
    width:100% !important;
    background:transparent !important;
    padding:12px 14px !important;
    border-radius:12px !important;
    text-align:left !important;
    color:#e5e7eb !important;
}

.bulk-item:hover{
    background:rgba(124,58,237,.20) !important;
    color:#fff !important;
}

/* ================= FOOTER ================= */
.table-footer{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    padding:16px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.30) !important;
}

.footer-left{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.footer-right{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

.pager-btn{
    width:38px !important;
    height:38px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.pager-num{
    min-width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:0 12px !important;
    box-shadow:0 12px 24px rgba(124,58,237,.20) !important;
}

/* White empty row fix */
.text-center.py-4{
    color:#94a3b8 !important;
    font-weight:900 !important;
    background:transparent !important;
}

/* ================= RESPONSIVE ================= */
@media(max-width:1200px){
    .stats-grid{
        grid-template-columns:repeat(2,1fr) !important;
    }

    .filter-row{
        grid-template-columns:repeat(3,1fr) !important;
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

    .main-panel{
        margin-left:0 !important;
    }

    .topbar{
        position:relative !important;
    }

    .content-wrapper{
        padding:18px !important;
    }

    .filter-row{
        grid-template-columns:1fr 1fr !important;
    }
}

@media(max-width:768px){
    .stats-grid{
        grid-template-columns:1fr !important;
    }

    .filter-row{
        grid-template-columns:1fr !important;
    }

    .page-header{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .top-actions{
        width:100% !important;
    }

    .btn-gold,
    .btn-lightx,
    .btn-darkx,
    .filter-btn,
    .save-btn{
        width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .table-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }
}



/* =====================================================
   FIX: BULK STATUS DROPDOWN OVERLAY ISSUE
   Paste at END of 
===================================================== */

.page-header{
    position:relative !important;
    z-index:9999 !important;
    overflow:visible !important;
}

.top-actions{
    position:relative !important;
    z-index:10000 !important;
    overflow:visible !important;
}

.bulk-menu-wrap{
    position:relative !important;
    z-index:10001 !important;
    overflow:visible !important;
}

.bulk-menu{
    position:absolute !important;
    top:54px !important;
    right:0 !important;
    left:auto !important;
    width:240px !important;

    display:none;
    z-index:10002 !important;

    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.88)) !important;

    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:16px !important;
    padding:10px !important;

    box-shadow:
        0 26px 70px rgba(0,0,0,.65),
        0 0 45px rgba(124,58,237,.25),
        inset 0 1px 0 rgba(255,255,255,.08) !important;

    backdrop-filter:blur(18px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(145%) !important;
}

.bulk-item{
    width:100% !important;
    border:none !important;
    background:transparent !important;
    color:#f8fafc !important;
    padding:12px 14px !important;
    border-radius:12px !important;
    text-align:left !important;
    font-weight:900 !important;
    transition:.2s ease !important;
}

.bulk-item:hover{
    background:rgba(124,58,237,.22) !important;
    color:#ffffff !important;
    transform:translateX(3px) !important;
}

/* Stats ko dropdown ke neeche rakhne ke liye */
.stats-grid{
    position:relative !important;
    z-index:2 !important;
}

.filter-card,
.table-card{
    position:relative !important;
    z-index:1 !important;
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
                <h1 class="page-title">Staff Attendance - <span><?= date('d M Y', strtotime($today)) ?></span></h1>

                <div class="top-actions">
                    <!--<a href="staff.php" class="btn-gold"><i class="bi bi-plus-lg me-1"></i> Add Staff</a>-->

                    <!--<button type="button" class="btn-lightx" id="removeAttendanceBtn">-->
                    <!--    <i class="bi bi-dash-lg me-1"></i> Remove Staff-->
                    <!--</button>-->

                    <div class="bulk-menu-wrap">
                        <button type="button" class="btn-darkx" id="bulkStatusBtn">
                            <i class="bi bi-funnel me-1"></i> Bulk Status
                        </button>

                        <div class="bulk-menu" id="bulkMenu">
                            <button type="button" class="bulk-item" data-bulk="present">✅ Mark Present</button>
                            <button type="button" class="bulk-item" data-bulk="absent">🟥 Mark Absent</button>
                            <button type="button" class="bulk-item" data-bulk="leave">🟩 Mark Leave</button>
                        </div>
                    </div>

                    <button type="button" class="btn-gold" id="saveAllBtn">
                        <i class="bi bi-bookmark-check me-1"></i> Save All
                    </button>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card stat-blue">
                    <div class="stat-title">Total Staff</div>
                    <div class="stat-value"><?= $totalStaff ?></div>
                </div>
                <div class="stat-card stat-green">
                    <div class="stat-title">Present</div>
                    <div class="stat-value"><?= $presentCount ?></div>
                </div>
                <div class="stat-card stat-red">
                    <div class="stat-title">Absent</div>
                    <div class="stat-value"><?= $absentCount ?></div>
                </div>
                <div class="stat-card stat-gold">
                    <div class="stat-title">Leave</div>
                    <div class="stat-value"><?= $leaveCount ?></div>
                </div>
            </div>

            <div class="filter-card">
                <form method="GET" class="filter-row">
                    <input type="text" class="erp-input" name="search" placeholder="Search staff name..." value="<?= e($search) ?>">

                    <select class="erp-select" name="staff_id">
                        <option value="">All Staff</option>
                        <?php
                        $allStaffStmt = $conn->prepare("SELECT id, name FROM users WHERE client_id=? ORDER BY name ASC");
                        $allStaffStmt->bind_param("i", $client_id);
                        $allStaffStmt->execute();
                        $allStaffRes = $allStaffStmt->get_result();
                        while ($u = $allStaffRes->fetch_assoc()):
                        ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $staffFilter === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= e($u['name']) ?>
                            </option>
                        <?php endwhile; $allStaffStmt->close(); ?>
                    </select>

                    <select class="erp-select" name="status">
                        <option value="">All Status</option>
                        <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
                        <option value="absent" <?= $statusFilter === 'absent' ? 'selected' : '' ?>>Absent</option>
                        <option value="leave" <?= $statusFilter === 'leave' ? 'selected' : '' ?>>Leave</option>
                    </select>

                    <button type="submit" class="filter-btn">Apply Filter</button>
                    <a href="attendance.php" class="filter-btn" style="background:#f2f4fa;color:#334055;text-decoration:none;">Reset</a>
                </form>
            </div>

            <div class="table-card">
                <div class="table-wrap">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th style="width:60px;"><input type="checkbox" id="checkAll"></th>
                                <th>Staff</th>
                                <th>Status</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staff)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4">No staff found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($staff as $s):
                                    $att = $attendance[$s['id']] ?? null;
                                    $nameFirst = strtoupper(substr($s['name'] ?? 'S', 0, 1));
                                ?>
                                    <tr data-staff-id="<?= (int)$s['id'] ?>">
                                        <td><input type="checkbox" class="row-check" value="<?= (int)$s['id'] ?>"></td>

                                        <td>
                                            <div class="staff-cell">
                                                <div class="avatar"><?= e($nameFirst) ?></div>
                                                <div>
                                                    <div class="staff-name"><?= e($s['name']) ?></div>
                                                    <div class="staff-sub"><?= e($s['email'] ?? '') ?></div>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <select class="status-select statusSelect" data-staff="<?= (int)$s['id'] ?>">
                                                <option value="present" <?= (($att['status'] ?? '') === 'present') ? 'selected' : '' ?>>Present</option>
                                                <option value="absent" <?= (($att['status'] ?? '') === 'absent') ? 'selected' : '' ?>>Absent</option>
                                                <option value="leave" <?= (($att['status'] ?? '') === 'leave') ? 'selected' : '' ?>>Leave</option>
                                            </select>
                                        </td>

                                        <td>
                                            <div class="time-wrap">
                                                <input type="time" class="time-input checkIn" data-staff="<?= (int)$s['id'] ?>" value="<?= e($att['check_in'] ?? '') ?>">
                                                <i class="bi bi-clock-history"></i>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="time-wrap">
                                                <input type="time" class="time-input checkOut" data-staff="<?= (int)$s['id'] ?>" value="<?= e($att['check_out'] ?? '') ?>">
                                                <i class="bi bi-clock-history"></i>
                                            </div>
                                        </td>

                                        <td>
                                            <button class="save-btn saveAttendance" data-staff="<?= (int)$s['id'] ?>">Save</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <div class="footer-left">
                        Showing 1 to <?= count($staff) ?> of <?= count($staff) ?> entries
                    </div>
                    <div class="footer-right">
                        <button type="button" class="pager-btn"><i class="bi bi-chevron-left"></i></button>
                        <div class="pager-num">1</div>
                        <button type="button" class="pager-btn"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
            </div>
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
function getRowData(staff_id) {
    return {
        staff_id: staff_id,
        status: $('.statusSelect[data-staff="' + staff_id + '"]').val(),
        check_in: $('.checkIn[data-staff="' + staff_id + '"]').val(),
        check_out: $('.checkOut[data-staff="' + staff_id + '"]').val()
    };
}

function saveAttendanceRow(staff_id) {
    const data = getRowData(staff_id);

    return $.post('ajax_attendance.php', {
        action: 'save',
        staff_id: data.staff_id,
        status: data.status,
        check_in: data.check_in,
        check_out: data.check_out
    });
}

$('.saveAttendance').on('click', function() {
    const staff_id = $(this).data('staff');
    const btn = $(this);
    const oldText = btn.text();

    btn.prop('disabled', true).text('Saving...');

    saveAttendanceRow(staff_id)
        .done(function(resp) {
            if ($.trim(resp) === 'ok') {
                alert('Saved!');
            } else {
                alert('Error: ' + resp);
            }
        })
        .fail(function() {
            alert('Request failed');
        })
        .always(function() {
            btn.prop('disabled', false).text(oldText);
        });
});

$('#saveAllBtn').on('click', async function() {
    const rows = $('.row-check:checked');
    if (!rows.length) {
        alert('Select at least one staff row');
        return;
    }

    const btn = $(this);
    const oldText = btn.text();
    btn.prop('disabled', true).text('Saving...');

    let allOk = true;

    for (let i = 0; i < rows.length; i++) {
        const staff_id = $(rows[i]).val();
        try {
            const resp = await saveAttendanceRow(staff_id);
            if ($.trim(resp) !== 'ok') {
                allOk = false;
                alert('Error on staff ID ' + staff_id + ': ' + resp);
                break;
            }
        } catch (e) {
            allOk = false;
            alert('Request failed for staff ID ' + staff_id);
            break;
        }
    }

    if (allOk) alert('Selected attendance saved successfully');

    btn.prop('disabled', false).text(oldText);
});

$('#removeAttendanceBtn').on('click', async function() {
    const rows = $('.row-check:checked');
    if (!rows.length) {
        alert('Select at least one staff row');
        return;
    }

    if (!confirm('Remove attendance for selected staff?')) return;

    const btn = $(this);
    const oldText = btn.text();
    btn.prop('disabled', true).text('Removing...');

    let allOk = true;

    for (let i = 0; i < rows.length; i++) {
        const staff_id = $(rows[i]).val();

        try {
            const resp = await $.post('ajax_attendance.php', {
                action: 'delete',
                staff_id: staff_id
            });

            if ($.trim(resp) !== 'ok') {
                allOk = false;
                alert('Error removing staff ID ' + staff_id + ': ' + resp);
                break;
            }

            $('.statusSelect[data-staff="' + staff_id + '"]').val('present');
            $('.checkIn[data-staff="' + staff_id + '"]').val('');
            $('.checkOut[data-staff="' + staff_id + '"]').val('');
        } catch (e) {
            allOk = false;
            alert('Delete request failed for staff ID ' + staff_id);
            break;
        }
    }

    if (allOk) alert('Selected attendance removed successfully');

    btn.prop('disabled', false).text(oldText);
});

$('#checkAll').on('change', function() {
    $('.row-check').prop('checked', $(this).is(':checked'));
});

$('#bulkStatusBtn').on('click', function() {
    $('#bulkMenu').toggle();
});

$(document).on('click', function(e) {
    if (!$(e.target).closest('.bulk-menu-wrap').length) {
        $('#bulkMenu').hide();
    }
});

$('.bulk-item').on('click', function() {
    const bulkStatus = $(this).data('bulk');
    const rows = $('.row-check:checked');

    if (!rows.length) {
        alert('Select at least one staff row');
        return;
    }

    rows.each(function() {
        const staff_id = $(this).val();
        $('.statusSelect[data-staff="' + staff_id + '"]').val(bulkStatus);
    });

    $('#bulkMenu').hide();
});
</script>
</body>
</html>