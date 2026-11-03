<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$client_id = (int)$_SESSION['client_id'];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

$search = trim($_GET['search'] ?? '');
$staffFilter = (int)($_GET['staff_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? $monthStart);
$dateTo   = trim($_GET['date_to'] ?? $monthEnd);

if ($dateFrom === '') $dateFrom = $monthStart;
if ($dateTo === '') $dateTo = $monthEnd;

$staffList = [];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE client_id=? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $staffList[] = $row;
}
$stmt->close();

/* ================= REPORT QUERY ================= */
$sql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) AS presents,
        SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) AS absents,
        SUM(CASE WHEN sa.status = 'leave' THEN 1 ELSE 0 END) AS leaves,
        SUM(CASE WHEN sa.status = 'present' AND sa.check_in IS NOT NULL AND sa.check_in <> '' AND sa.check_in > '09:15' THEN 1 ELSE 0 END) AS late_entries,
        COUNT(sa.id) AS attendance_days
    FROM users u
    LEFT JOIN staff_attendance sa
        ON sa.staff_id = u.id
        AND sa.client_id = u.client_id
        AND sa.attendance_date BETWEEN ? AND ?
    WHERE u.client_id = ?
";
$params = [$dateFrom, $dateTo, $client_id];
$types = "ssi";

if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($staffFilter > 0) {
    $sql .= " AND u.id = ?";
    $params[] = $staffFilter;
    $types .= "i";
}

$sql .= " GROUP BY u.id ORDER BY u.name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$reportRows = [];
$totalStaff = 0;
$totalPresents = 0;
$totalAbsents = 0;
$totalLeaves = 0;

while ($row = $res->fetch_assoc()) {
    $presents = (int)$row['presents'];
    $absents = (int)$row['absents'];
    $leaves = (int)$row['leaves'];
    $lateEntries = (int)$row['late_entries'];
    $attendanceDays = (int)$row['attendance_days'];

    $workingDays = max(1, $presents + $absents + $leaves);
    $attendancePercent = round(($presents / $workingDays) * 100);

    /* detail rows for modal */
    $details = [];
    $dStmt = $conn->prepare("
        SELECT attendance_date, status, check_in, check_out
        FROM staff_attendance
        WHERE client_id=? AND staff_id=? AND attendance_date BETWEEN ? AND ?
        ORDER BY attendance_date ASC
    ");
    $dStmt->bind_param("iiss", $client_id, $row['id'], $dateFrom, $dateTo);
    $dStmt->execute();
    $dRes = $dStmt->get_result();
    while ($d = $dRes->fetch_assoc()) {
        $details[] = $d;
    }
    $dStmt->close();

    $row['presents'] = $presents;
    $row['absents'] = $absents;
    $row['leaves'] = $leaves;
    $row['late_entries'] = $lateEntries;
    $row['attendance_percent'] = $attendancePercent;
    $row['branch_name'] = 'Main Branch';
    $row['details_json'] = json_encode($details, JSON_HEX_APOS | JSON_HEX_QUOT);

    $reportRows[] = $row;

    $totalStaff++;
    $totalPresents += $presents;
    $totalAbsents += $absents;
    $totalLeaves += $leaves;
}
$stmt->close();

$monthTitle = date('F Y', strtotime($dateFrom));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Attendance Report</title>
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

        .filter-card,.table-card,.detail-card{
            background:#fff;border:1px solid #e6ebf3;border-radius:16px;
            box-shadow:0 8px 22px rgba(18,38,63,.05)
        }
        .filter-card{padding:14px;margin-bottom:16px}
        .filter-row{
            display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr 170px;
            gap:10px
        }
        .erp-input,.erp-select,.filter-btn{
            height:48px;border:1px solid #dbe2ed;border-radius:10px;
            background:#fff;padding:0 14px;outline:none;width:100%
        }
        .filter-btn{
            display:flex;align-items:center;justify-content:center;
            font-weight:700;border:none;background:#2959d4;color:#fff;text-decoration:none
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
        .stat-red{background:linear-gradient(135deg,#d35e74,#b33e56)}
        .stat-gold{background:linear-gradient(135deg,#f4a11a,#d88909)}
        .stat-icon{font-size:22px;margin-bottom:8px}
        .stat-title{font-size:14px;font-weight:600;line-height:1.3}
        .stat-value{font-size:34px;font-weight:800;line-height:1;margin-top:8px}

        .detail-card{padding:0;overflow:hidden}
        .detail-head{
            padding:18px 18px 10px;
            font-size:18px;font-weight:800;color:#243046
        }

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

        .staff-cell{display:flex;align-items:center;gap:12px}
        .avatar{
            width:46px;height:46px;border-radius:50%;
            background:linear-gradient(135deg,#9bb6ff,#4b7af3);
            color:#fff;display:flex;align-items:center;justify-content:center;
            font-weight:800;font-size:18px;flex-shrink:0
        }
        .staff-name{font-weight:700;color:#243046}
        .branch-text{color:#44506a}
        .percent-main{font-weight:800;font-size:18px;color:#d18a00}
        .late-text{font-size:12px;color:#8b6a1a;margin-top:4px}
        .view-btn{
            border:none;border-radius:10px;background:#2e7be9;color:#fff;
            font-weight:800;padding:10px 22px
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
.erp-table input[type="checkbox"]{
    width:16px;
    height:16px;
    cursor:pointer;
}
        .modal-content{border-radius:18px;border:none}
        .modal-header{border-bottom:1px solid #edf1f6}
        .modal-body{padding:18px}
        .mini-table{width:100%;border-collapse:collapse}
        .mini-table th,.mini-table td{
            border-bottom:1px solid #edf1f6;
            padding:10px 8px;text-align:left
        }

        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:991px){.filter-row{grid-template-columns:1fr 1fr}}
        @media(max-width:768px){
            .stats-grid{grid-template-columns:1fr}
            .filter-row{grid-template-columns:1fr}
            .page-header{flex-direction:column;align-items:stretch}
        }
    
/* =====================================================
   COMMISSIONS_RATES.PHP - DARK PURPLE GLASS HOVER THEME
   Backend / PHP / JS logic untouched
   Paste at VERY END before 
===================================================== */
html,body{min-height:100% !important;}
:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-panel:#0f172a;
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-green:#22c55e;
    --oms-cyan:#06b6d4;
    --oms-yellow:#f59e0b;
    --oms-red:#ef4444;
    --oms-border:rgba(148,163,184,.16);
}

body{
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.18), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
    overflow-x:hidden !important;
    --current-sidebar-width:var(--sidebar-open-width);
}
body.dashboard-sidebar-collapsed{--current-sidebar-width:var(--sidebar-close-width);}

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
.sidebar .sidebar-wrap,#dashboardSidebar .sidebar-wrap,#sidebar .sidebar-wrap{
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
.sidebar .sidebar-inner,#dashboardSidebar .sidebar-inner,#sidebar .sidebar-inner{width:100% !important;height:100vh !important;min-height:100vh !important;}
.sidebar .sidebar-menu,#dashboardSidebar .sidebar-menu,#sidebar .sidebar-menu{overflow-y:auto !important;overflow-x:hidden !important;}

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
    background:linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.03) 1px,transparent 1px);
    background-size:44px 44px;mask-image:radial-gradient(circle at 40% 20%,black 0%,transparent 70%);opacity:.42;
}
.content-wrapper::after{
    content:"";position:absolute;width:520px;height:520px;right:-190px;top:-210px;pointer-events:none;z-index:0;
    background:radial-gradient(circle,rgba(109,76,255,.30),transparent 68%);filter:blur(8px);opacity:.85;
}
.content-wrapper > *{position:relative !important;z-index:1 !important;}

.page-header{display:flex !important;justify-content:space-between !important;align-items:center !important;gap:14px !important;flex-wrap:wrap !important;margin-bottom:18px !important;}
.page-title{
    font-size:30px !important;font-weight:900 !important;color:#f8fafc !important;margin:0 !important;letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

.stats-grid{display:grid !important;grid-template-columns:repeat(4,1fr) !important;gap:16px !important;margin-bottom:18px !important;}
.stat-card{
    min-height:112px !important;color:#fff !important;border-radius:18px !important;padding:20px 22px !important;position:relative !important;overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;box-shadow:0 18px 40px rgba(0,0,0,.28) !important;isolation:isolate !important;
    transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease,filter .25s ease !important;
}
.stat-card::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.10),transparent 42%),radial-gradient(circle at 86% 10%,rgba(255,255,255,.16),transparent 28%);z-index:-1;}
.stat-card::after{content:"";position:absolute;right:14px;top:14px;width:76px;height:76px;border-radius:20px;background:rgba(255,255,255,.10);z-index:-1;}
.stat-card:hover{transform:translateY(-4px) !important;border-color:rgba(255,255,255,.22) !important;filter:saturate(1.08) !important;box-shadow:0 26px 65px rgba(0,0,0,.46),0 0 48px rgba(124,58,237,.18) !important;}
.stat-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.stat-gold{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.stat-title{font-size:15px !important;font-weight:900 !important;color:rgba(255,255,255,.94) !important;}
.stat-value{font-size:28px !important;font-weight:900 !important;color:#fff !important;right:18px !important;bottom:14px !important;}
.stat-sub{color:rgba(255,255,255,.88) !important;font-weight:800 !important;}

.filter-card,.table-card,.approval-card{
    background:radial-gradient(circle at 20% 0%,rgba(124,58,237,.14),transparent 34%),linear-gradient(135deg,rgba(15,23,42,.72),rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;border-radius:18px !important;box-shadow:0 18px 48px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;-webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}
.filter-card{padding:14px 16px !important;margin-bottom:18px !important;}
.filter-card form{display:grid !important;grid-template-columns:1fr !important;gap:10px !important;}
.filter-row{
    display:grid !important;
    grid-template-columns:minmax(130px,1fr) minmax(140px,1fr) minmax(160px,1fr) minmax(130px,1fr) minmax(130px,1fr) 100px 100px !important;
    gap:10px !important;align-items:center !important;margin-bottom:0 !important;
}
.search-row{display:grid !important;grid-template-columns:1fr !important;gap:10px !important;margin-top:0 !important;}
.erp-select,.erp-input{
    height:46px !important;width:100% !important;border:1px solid rgba(148,163,184,.20) !important;border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;color:#e5e7eb !important;padding:0 14px !important;outline:none !important;font-weight:800 !important;
    box-shadow:0 8px 22px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.04) !important;
}
.erp-select option{background:#0f172a !important;color:#e5e7eb !important;}
.erp-select:focus,.erp-input:focus{background:rgba(17,24,39,.92) !important;color:#fff !important;border-color:rgba(124,58,237,.62) !important;box-shadow:0 0 0 3px rgba(124,58,237,.16),0 12px 26px rgba(0,0,0,.24) !important;}
.erp-input::placeholder{color:#94a3b8 !important;}

.btn-green,.filter-btn,.reset-btn,.bulk-delete-btn,.pager-btn,.action-btn,.save-btn,.cancel-btn{
    transition:all .22s ease !important;
}
.btn-green{
    background:linear-gradient(135deg,#047857,#22c55e) !important;border:none !important;border-radius:14px !important;min-height:50px !important;color:#fff !important;font-weight:900 !important;
    box-shadow:0 16px 34px rgba(34,197,94,.22),0 0 28px rgba(34,197,94,.12) !important;
}
.btn-green:hover,.filter-btn:hover,.reset-btn:hover,.bulk-delete-btn:hover,.pager-btn:hover,.action-btn:hover,.save-btn:hover,.cancel-btn:hover{transform:translateY(-2px) !important;}
.filter-btn{height:46px !important;border-radius:13px !important;background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;border:none !important;font-weight:900 !important;box-shadow:0 12px 24px rgba(0,0,0,.20) !important;}
.reset-btn{height:46px !important;border-radius:13px !important;background:rgba(15,23,42,.72) !important;color:#e5e7eb !important;border:1px solid rgba(148,163,184,.18) !important;font-weight:900 !important;box-shadow:0 12px 24px rgba(0,0,0,.20) !important;}
.filter-btn:hover,.reset-btn:hover{color:#fff !important;border-color:rgba(139,92,246,.45) !important;box-shadow:0 18px 40px rgba(0,0,0,.36),0 0 34px rgba(124,58,237,.18) !important;}
.reset-btn:hover{background:rgba(124,58,237,.20) !important;}

.table-card{padding:0 !important;overflow:hidden !important;}
.table-card:hover{border-color:rgba(139,92,246,.45) !important;box-shadow:0 24px 65px rgba(0,0,0,.45),0 0 0 1px rgba(124,58,237,.16),0 0 42px rgba(124,58,237,.18),inset 0 1px 0 rgba(255,255,255,.06) !important;}
.table-wrap{overflow:auto !important;}
.erp-table{width:100% !important;min-width:1180px !important;border-collapse:collapse !important;background:transparent !important;}
.erp-table thead th{background:rgba(7,11,20,.76) !important;color:#cbd5e1 !important;font-weight:900 !important;font-size:13px !important;padding:15px 14px !important;border-right:1px solid rgba(148,163,184,.08) !important;border-bottom:1px solid rgba(148,163,184,.16) !important;white-space:nowrap !important;text-transform:uppercase !important;letter-spacing:.04em !important;}
.erp-table tbody td{background:rgba(15,23,42,.30) !important;color:#e5e7eb !important;padding:15px 14px !important;border-top:1px solid rgba(148,163,184,.10) !important;vertical-align:middle !important;font-size:14px !important;font-weight:700 !important;white-space:nowrap !important;}
.erp-table tbody tr:hover td{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.18),transparent 38%),linear-gradient(135deg,rgba(30,20,58,.42),rgba(15,23,42,.34)) !important;color:#fff !important;}
.erp-table input[type="checkbox"]{width:16px !important;height:16px !important;accent-color:#7c3aed !important;cursor:pointer !important;}

.status-pill,.badge,.po-status{padding:7px 12px !important;border-radius:999px !important;font-size:12px !important;font-weight:900 !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;}
td:nth-child(9){text-transform:capitalize !important;}
.supplier-cell{color:#e5e7eb !important;}
.supplier-icon{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;}
.work-pill{background:rgba(34,197,94,.14) !important;color:#4ade80 !important;border:1px solid rgba(34,197,94,.24) !important;}

.action-group{display:flex !important;align-items:center !important;justify-content:center !important;gap:10px !important;}
.action-btn{width:40px !important;height:40px !important;border-radius:14px !important;border:none !important;box-shadow:0 12px 24px rgba(0,0,0,.18) !important;}
.btn-edit{background:rgba(245,158,11,.15) !important;color:#fbbf24 !important;border:1px solid rgba(245,158,11,.24) !important;}
.btn-edit:hover{background:#f59e0b !important;color:#111827 !important;}
.btn-delete{background:rgba(239,68,68,.14) !important;color:#f87171 !important;border:1px solid rgba(239,68,68,.24) !important;}
.btn-delete:hover{background:#ef4444 !important;color:#fff !important;}

.table-footer{padding:14px 16px !important;border-top:1px solid rgba(148,163,184,.13) !important;background:rgba(7,11,20,.30) !important;display:flex !important;justify-content:space-between !important;align-items:center !important;gap:12px !important;flex-wrap:wrap !important;color:#94a3b8 !important;font-weight:800 !important;}
.footer-left{color:#94a3b8 !important;font-weight:800 !important;}
.footer-right{display:flex !important;align-items:center !important;gap:10px !important;}
.pager-btn{background:rgba(15,23,42,.72) !important;color:#e5e7eb !important;border:1px solid rgba(148,163,184,.18) !important;border-radius:10px !important;}
.pager-num{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;border-radius:10px !important;box-shadow:0 10px 20px rgba(124,58,237,.22) !important;}
.bulk-delete-btn{background:linear-gradient(135deg,#be123c,#ef4444) !important;color:#fff !important;border:none !important;border-radius:12px !important;font-weight:900 !important;box-shadow:0 12px 24px rgba(239,68,68,.20) !important;}

/* Modal dark purple glass */
body.modal-open .modal-backdrop.show,.modal-backdrop.show{opacity:.58 !important;background:#000 !important;}
#rateModal .modal-dialog{max-width:min(920px,94vw) !important;margin:1rem auto !important;}
#rateModal .modal-content,#rateModal .po-modal-content{
    background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.24),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.88),rgba(17,24,39,.72)) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;-webkit-backdrop-filter:blur(22px) saturate(145%) !important;color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.32) !important;border-radius:18px !important;overflow:hidden !important;
    box-shadow:0 24px 70px rgba(0,0,0,.58),0 0 45px rgba(124,58,237,.16),inset 0 1px 0 rgba(255,255,255,.06) !important;
}
#rateModal .po-modal-header{min-height:56px !important;padding:12px 18px !important;background:rgba(7,11,20,.30) !important;border-bottom:1px solid rgba(148,163,184,.14) !important;display:flex !important;align-items:center !important;justify-content:space-between !important;}
#rateModal .po-modal-title{color:#f8fafc !important;font-size:18px !important;font-weight:900 !important;margin:0 !important;}
#rateModal .po-modal-title::before{content:"" !important;width:11px !important;height:11px !important;border-radius:50% !important;background:#7c3aed !important;box-shadow:0 0 0 5px rgba(124,58,237,.16) !important;display:inline-block !important;margin-right:10px !important;}
#rateModal .po-modal-body{background:rgba(15,23,42,.22) !important;color:#f8fafc !important;padding:14px 18px !important;max-height:64vh !important;overflow-y:auto !important;overflow-x:hidden !important;}
#rateModal .po-modal-footer{background:rgba(7,11,20,.30) !important;border-top:1px solid rgba(148,163,184,.14) !important;padding:10px 18px !important;min-height:54px !important;margin-top:0 !important;display:flex !important;justify-content:flex-end !important;gap:12px !important;}
#rateModal .dark-label,#rateModal label{color:#e5e7eb !important;font-size:12px !important;font-weight:900 !important;margin-bottom:5px !important;}
#rateModal .req{color:#fbbf24 !important;}
#rateModal .dark-input,#rateModal .dark-select,#rateModal .dark-textarea,#rateModal input[type="text"],#rateModal input[type="number"],#rateModal select,#rateModal textarea{
    background:rgba(255,255,255,.92) !important;color:#111827 !important;border:1px solid rgba(203,213,225,.82) !important;border-radius:10px !important;font-size:13px !important;font-weight:700 !important;box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}
#rateModal .dark-input,#rateModal .dark-select,#rateModal input[type="text"],#rateModal input[type="number"],#rateModal select{height:38px !important;min-height:38px !important;padding:5px 10px !important;}
#rateModal .dark-textarea,#rateModal textarea{min-height:60px !important;height:60px !important;padding:8px 10px !important;resize:vertical !important;}
#rateModal .dark-input:focus,#rateModal .dark-select:focus,#rateModal .dark-textarea:focus,#rateModal input:focus,#rateModal select:focus,#rateModal textarea:focus{border-color:#7c3aed !important;box-shadow:0 0 0 3px rgba(124,58,237,.20),0 8px 18px rgba(0,0,0,.18) !important;outline:none !important;}
#rateModal .po-close{width:36px !important;height:36px !important;border-radius:10px !important;background:rgba(148,163,184,.16) !important;border:1px solid rgba(148,163,184,.18) !important;color:transparent !important;opacity:1 !important;font-size:0 !important;position:relative !important;padding:0 !important;}
#rateModal .po-close::before,#rateModal .po-close::after{content:"" !important;position:absolute !important;left:50% !important;top:50% !important;width:16px !important;height:2px !important;background:#e5e7eb !important;border-radius:999px !important;}
#rateModal .po-close::before{transform:translate(-50%,-50%) rotate(45deg) !important;}
#rateModal .po-close::after{transform:translate(-50%,-50%) rotate(-45deg) !important;}
#rateModal .po-close:hover{background:rgba(239,68,68,.24) !important;}
#rateModal .cancel-btn{height:38px !important;min-width:105px !important;border-radius:9px !important;background:#5d617a !important;color:#fff !important;border:none !important;font-weight:900 !important;}
#rateModal .save-btn{height:38px !important;min-width:120px !important;border-radius:9px !important;background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;border:none !important;font-weight:900 !important;box-shadow:0 12px 26px rgba(124,58,237,.24) !important;}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr) !important;}.filter-row{grid-template-columns:repeat(3,1fr) !important;}}
@media(max-width:991px){.container-scroll{flex-direction:column !important;}.sidebar,.sidebar .sidebar-wrap,#dashboardSidebar .sidebar-wrap,#sidebar .sidebar-wrap{position:relative !important;width:100% !important;min-width:100% !important;max-width:100% !important;height:auto !important;min-height:auto !important;}.main-panel{margin-left:0 !important;}.topbar{position:relative !important;}.content-wrapper{padding:18px !important;}.filter-row{grid-template-columns:1fr 1fr !important;}}
@media(max-width:768px){.stats-grid{grid-template-columns:1fr !important;}.filter-row{grid-template-columns:1fr !important;}.page-header{flex-direction:column !important;align-items:stretch !important;}.btn-green,.filter-btn,.reset-btn,.erp-select,.erp-input{width:100% !important;}.table-footer,.footer-right{flex-direction:column !important;align-items:stretch !important;}.footer-right > *{width:100% !important;}#rateModal .modal-dialog{width:calc(100% - 24px) !important;max-width:calc(100% - 24px) !important;margin:12px auto !important;}#rateModal .po-modal-footer{flex-direction:column !important;align-items:stretch !important;}#rateModal .cancel-btn,#rateModal .save-btn{width:100% !important;}}

/* =====================================================
   FIX: ATTENDANCE REPORT FILTER ONE LINE + GLASS TABLE
   Paste at VERY END of
===================================================== */

/* Filter ko ek hi line me lao */
.filter-card form.filter-row,
.filter-row{
    display:grid !important;
    grid-template-columns:
        minmax(260px, 1.5fr)
        minmax(180px, 1fr)
        minmax(160px, .8fr)
        minmax(160px, .8fr)
        minmax(170px, .7fr) !important;
    gap:12px !important;
    align-items:center !important;
    width:100% !important;
}

/* Filter card glass */
.filter-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    padding:16px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

/* Inputs same line aur proper height */
.filter-card .erp-input,
.filter-card .erp-select,
.filter-card .filter-btn{
    height:48px !important;
    min-height:48px !important;
    width:100% !important;
    margin:0 !important;
}

/* Date input icon dark bg me visible */
.filter-card input[type="date"]{
    color-scheme:dark !important;
}

/* White table card remove karke glass transparent karo */
.detail-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

/* Detail heading white background remove */
.detail-head{
    background:rgba(7,11,20,.35) !important;
    color:#f8fafc !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:18px !important;
    font-weight:900 !important;
}

/* Table white rows remove */
.detail-card .erp-table,
.detail-card .erp-table tbody,
.detail-card .erp-table tr{
    background:transparent !important;
}

.detail-card .erp-table thead th{
    background:rgba(7,11,20,.78) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
}

.detail-card .erp-table tbody td{
    background:rgba(15,23,42,.30) !important;
    color:#e5e7eb !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    border-bottom:none !important;
}

/* Alternate row halka glass */
.detail-card .erp-table tbody tr:nth-child(even) td{
    background:rgba(15,23,42,.42) !important;
}

/* Hover glass purple */
.detail-card .erp-table tbody tr:hover td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.38)) !important;
    color:#ffffff !important;
}

/* Staff text colors fix */
.detail-card .staff-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.detail-card .branch-text{
    color:#cbd5e1 !important;
    font-weight:800 !important;
}

.detail-card .percent-main{
    color:#fbbf24 !important;
    font-weight:900 !important;
}

.detail-card .late-text{
    color:#c4b5fd !important;
    font-weight:800 !important;
}

/* Footer white remove */
.detail-card .table-footer{
    background:rgba(7,11,20,.34) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

/* Responsive: small screen par wrap */
@media(max-width:1200px){
    .filter-card form.filter-row,
    .filter-row{
        grid-template-columns:repeat(2, 1fr) !important;
    }
}

@media(max-width:768px){
    .filter-card form.filter-row,
    .filter-row{
        grid-template-columns:1fr !important;
    }
}

/* =====================================================
   FIX: STAFF ATTENDANCE REPORT POPUP DARK PURPLE GLASS
   Paste at VERY END before
===================================================== */

body.modal-open{
    overflow:hidden !important;
}

.modal-backdrop.show{
    opacity:.72 !important;
    background:
        radial-gradient(circle at 40% 20%, rgba(124,58,237,.30), transparent 36%),
        rgba(0,0,0,.82) !important;
    backdrop-filter:blur(6px) !important;
    -webkit-backdrop-filter:blur(6px) !important;
}

#reportModal{
    z-index:99999 !important;
}

#reportModal .modal-dialog{
    max-width:860px !important;
    width:calc(100% - 34px) !important;
    margin:1.5rem auto !important;
}

#reportModal .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.30), transparent 38%),
        radial-gradient(circle at 90% 10%, rgba(59,130,246,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.94), rgba(17,24,39,.82)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:20px !important;
    overflow:hidden !important;

    box-shadow:
        0 34px 90px rgba(0,0,0,.72),
        0 0 55px rgba(124,58,237,.28),
        inset 0 1px 0 rgba(255,255,255,.08) !important;

    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
}

#reportModal .modal-header{
    min-height:64px !important;
    padding:16px 20px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#reportModal .modal-title{
    color:#f8fafc !important;
    font-size:20px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
    text-shadow:0 0 24px rgba(124,58,237,.35) !important;
}

#reportModal .modal-title::before{
    content:"";
    width:12px !important;
    height:12px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    display:inline-block !important;
    margin-right:10px !important;
    box-shadow:0 0 0 6px rgba(124,58,237,.18), 0 0 22px rgba(124,58,237,.55) !important;
}

#reportModal .modal-body{
    padding:18px 20px !important;
    background:rgba(15,23,42,.22) !important;
    color:#f8fafc !important;
    max-height:65vh !important;
    overflow:auto !important;
}

/* Close button ko dark theme me clean karo */
#reportModal .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.20) !important;
    opacity:1 !important;
    filter:none !important;
    background-image:none !important;
    position:relative !important;
    padding:0 !important;
    transition:.22s ease !important;
}

#reportModal .btn-close::before,
#reportModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:17px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#reportModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#reportModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#reportModal .btn-close:hover{
    background:rgba(239,68,68,.24) !important;
    border-color:rgba(239,68,68,.38) !important;
    transform:translateY(-2px) !important;
}

/* Popup table glass */
#reportModal .table-responsive{
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    overflow:hidden !important;
    background:rgba(15,23,42,.30) !important;
}

#reportModal .mini-table{
    width:100% !important;
    border-collapse:collapse !important;
    background:transparent !important;
}

#reportModal .mini-table thead th{
    background:rgba(7,11,20,.78) !important;
    color:#cbd5e1 !important;
    padding:14px 12px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#reportModal .mini-table tbody td{
    background:rgba(15,23,42,.34) !important;
    color:#e5e7eb !important;
    padding:14px 12px !important;
    font-size:14px !important;
    font-weight:800 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

#reportModal .mini-table tbody tr:nth-child(even) td{
    background:rgba(15,23,42,.48) !important;
}

#reportModal .mini-table tbody tr:hover td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.50), rgba(15,23,42,.40)) !important;
    color:#ffffff !important;
}

#reportModal .text-center.py-3{
    color:#94a3b8 !important;
    font-weight:900 !important;
    background:rgba(15,23,42,.34) !important;
}

/* Modal scrollbar */
#reportModal .modal-body::-webkit-scrollbar{
    width:9px !important;
    height:9px !important;
}

#reportModal .modal-body::-webkit-scrollbar-track{
    background:rgba(255,255,255,.06) !important;
    border-radius:10px !important;
}

#reportModal .modal-body::-webkit-scrollbar-thumb{
    background:rgba(124,58,237,.48) !important;
    border-radius:10px !important;
}

#reportModal .modal-body::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.72) !important;
}

@media(max-width:768px){
    #reportModal .modal-dialog{
        width:calc(100% - 22px) !important;
        margin:12px auto !important;
    }

    #reportModal .modal-title{
        font-size:17px !important;
    }

    #reportModal .modal-header,
    #reportModal .modal-body{
        padding:14px !important;
    }
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
                <h1 class="page-title">Staff Attendance Report - <span><?= e($monthTitle) ?></span></h1>
            </div>

           

            <div class="stats-grid">
                <div class="stat-card stat-blue">
                    <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                    <div class="stat-value"><?= $totalStaff ?></div>
                    <div class="stat-title">Total Staff</div>
                </div>
                <div class="stat-card stat-green">
                    <div class="stat-icon"><i class="bi bi-check2-square"></i></div>
                    <div class="stat-value"><?= $totalPresents ?></div>
                    <div class="stat-title">Total Presents</div>
                </div>
                <div class="stat-card stat-red">
                    <div class="stat-icon"><i class="bi bi-dash-square"></i></div>
                    <div class="stat-value"><?= $totalAbsents ?></div>
                    <div class="stat-title">Total Absents</div>
                </div>
                <div class="stat-card stat-gold">
                    <div class="stat-icon"><i class="bi bi-flag-fill"></i></div>
                    <div class="stat-value"><?= $totalLeaves ?></div>
                    <div class="stat-title">Total Leaves</div>
                </div>
            </div>
 <div class="filter-card">
                <form method="GET" class="filter-row">
                    <input type="text" class="erp-input" name="search" placeholder="Search staff name..." value="<?= e($search) ?>">

                    <select class="erp-select" name="staff_id">
                        <option value="">All Staff</option>
                        <?php foreach ($staffList as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $staffFilter === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= e($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="date" class="erp-input" name="date_from" value="<?= e($dateFrom) ?>">
                    <input type="date" class="erp-input" name="date_to" value="<?= e($dateTo) ?>">

                    <button type="submit" class="filter-btn">Apply Filter</button>
                </form>
            </div>
            <div class="detail-card">
                <div class="detail-head">Staff Attendance Details</div>

               <div class="table-wrap">
    <table class="erp-table">
        <thead>
            <tr>
                <th style="width:50px;">
                    <input type="checkbox" id="checkAllAttendance">
                </th>
                <th>Staff</th>
                <th>Branch</th>
                <th>Presents</th>
                <th>Absents</th>
                <th>Leaves</th>
                <th>Attendance %</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($reportRows)): ?>
                <tr>
                    <td colspan="8" class="text-center py-4">No attendance report found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($reportRows as $row):
                    $nameFirst = strtoupper(substr($row['name'] ?? 'S', 0, 1));
                ?>
                    <tr>
                        <td>
                            <input
                                type="checkbox"
                                class="attendanceRowCheck"
                                value="<?= (int)$row['id'] ?>"
                            >
                        </td>

                        <td>
                            <div class="staff-cell">
                                <div class="avatar"><?= e($nameFirst) ?></div>
                                <div class="staff-name"><?= e($row['name']) ?></div>
                            </div>
                        </td>

                        <td class="branch-text"><?= e($row['branch_name']) ?></td>
                        <td><?= (int)$row['presents'] ?></td>
                        <td><?= (int)$row['absents'] ?></td>
                        <td><?= (int)$row['leaves'] ?></td>

                        <td>
                            <div class="percent-main"><?= (int)$row['attendance_percent'] ?>%</div>
                            <div class="late-text"><?= (int)$row['late_entries'] ?> late entries</div>
                        </td>

                        <td>
                            <button
                                type="button"
                                class="view-btn viewReportBtn"
                                data-name="<?= e($row['name']) ?>"
                                data-details='<?= e($row['details_json']) ?>'>
                                <i class="bi bi-eye me-1"></i> View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

                <div class="table-footer">
                    <div class="footer-left">
                        Showing 1 to <?= count($reportRows) ?> of <?= count($reportRows) ?> entries
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

<div class="modal fade" id="reportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reportModalTitle">Attendance Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                            </tr>
                        </thead>
                        <tbody id="reportModalBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const reportModal = new bootstrap.Modal(document.getElementById('reportModal'));

document.querySelectorAll('.viewReportBtn').forEach(btn => {
    btn.addEventListener('click', function () {
        const name = this.dataset.name || 'Attendance Details';
        let details = [];

        try {
            details = JSON.parse(this.dataset.details || '[]');
        } catch (e) {
            details = [];
        }

        document.getElementById('reportModalTitle').innerText = name + ' - Attendance Details';

        let html = '';
        if (!details.length) {
            html = '<tr><td colspan="4" class="text-center py-3">No details found</td></tr>';
        } else {
            details.forEach(row => {
                html += `
                    <tr>
                        <td>${row.attendance_date || ''}</td>
                        <td>${row.status || ''}</td>
                        <td>${row.check_in || '-'}</td>
                        <td>${row.check_out || '-'}</td>
                    </tr>
                `;
            });
        }

        document.getElementById('reportModalBody').innerHTML = html;
        reportModal.show();
    });
});

// Select All Attendance
document.addEventListener('DOMContentLoaded', function () {

    const checkAll = document.getElementById('checkAllAttendance');

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.attendanceRowCheck').forEach(chk => {
                chk.checked = this.checked;
            });
        });
    }

    document.querySelectorAll('.attendanceRowCheck').forEach(chk => {
        chk.addEventListener('change', function () {
            const all = document.querySelectorAll('.attendanceRowCheck');
            const checked = document.querySelectorAll('.attendanceRowCheck:checked');

            if (checkAll) {
                checkAll.checked = all.length > 0 && all.length === checked.length;
            }
        });
    });

});
</script>
</body>
</html>