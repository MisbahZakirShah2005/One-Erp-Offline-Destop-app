<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$selected_branch_id = (int)($_GET['branch_id'] ?? 0);
$access_type = trim($_GET['access_type'] ?? 'login_required');

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

if ($selected_branch_id <= 0 && !empty($branches)) {
    $selected_branch_id = (int)$branches[0]['id'];
}

/* ================= SELECTED BRANCH NAME ================= */
$selected_branch_name = 'Main Branch';
foreach ($branches as $branch) {
    if ((int)$branch['id'] === $selected_branch_id) {
        $selected_branch_name = $branch['name'];
        break;
    }
}

/* ================= GENERATE POS URL ================= */
$queryParams = [
    'client_id' => $client_id,
    'branch_id' => $selected_branch_id,
    'access'    => $access_type
];

$mobile_url = "https://" . $_SERVER['HTTP_HOST'] . "/onerp/app/public/app/views/dashboard.php?" . http_build_query($queryParams);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mobile / Web POS Access</title>
    
    <script>
(function () {
    let selectedTheme = 'light';

    try {
        const savedTheme =
            localStorage.getItem('oneerp_pos_theme') ||
            localStorage.getItem('oneerp_theme') ||
            localStorage.getItem('theme');

        if (savedTheme === 'light' || savedTheme === 'dark') {
            selectedTheme = savedTheme;
        }
    } catch (error) {
        selectedTheme = 'light';
    }

    document.documentElement.setAttribute(
        'data-pos-theme',
        selectedTheme
    );
})();
</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php include('partials/head.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <style>
        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            background:#f3f4f8;
            font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
            color:#1f2937;
        }
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

        .main-panel{
            flex:1;
            display:flex;
            flex-direction:column;
            min-width:0;
        }

        .topbar{
            height:60px;
            border-bottom:1px solid #e5e7eb;
            display:flex;
            align-items:center;
            padding:0 20px;
            background:#fff;
        }

        .content-wrapper{
            flex:1;
            overflow:auto;
            padding:20px;
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            margin-bottom:16px;
            flex-wrap:wrap;
        }

        .page-title{
            font-size:28px;
            font-weight:700;
            color:#1f2937;
            margin:0;
        }

        .page-subtitle{
            margin:6px 0 0;
            color:#6b7280;
            font-size:14px;
        }

        .stats-row{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:14px;
            margin-bottom:16px;
        }

        .stat-card{
            color:#fff;
            border-radius:10px;
            padding:14px 18px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
            min-height:84px;
        }

        .stat-card .title{
            font-size:15px;
            font-weight:600;
            line-height:1.3;
        }

        .stat-card .count{
            font-size:28px;
            font-weight:700;
            line-height:1;
        }

        .stat-card .icon{
            font-size:24px;
            opacity:0.95;
            margin-right:12px;
        }

        .stat-left{
            display:flex;
            align-items:center;
            gap:12px;
        }

        .card-blue{ background:linear-gradient(135deg,#314a93,#405da8); }
        .card-green{ background:linear-gradient(135deg,#5a9b42,#73b552); }
        .card-red{ background:linear-gradient(135deg,#b23a48,#d85b69); }
        .card-yellow{ background:linear-gradient(135deg,#d9a615,#f3be2d); }

        .access-layout{
            display:grid;
            grid-template-columns:1.2fr .9fr;
            gap:16px;
        }

        .access-card{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:14px;
            box-shadow:0 8px 18px rgba(0,0,0,0.05);
            overflow:hidden;
        }

        .access-card-header{
            padding:18px 20px 0;
        }

        .access-card-title{
            margin:0;
            font-size:22px;
            font-weight:700;
            color:#1f2937;
        }

        .access-card-subtitle{
            margin:8px 0 0;
            color:#6b7280;
            font-size:14px;
        }

        .access-card-body{
            padding:20px;
        }

        .left-panel-grid{
            display:grid;
            grid-template-columns:260px 1fr;
            gap:24px;
            align-items:start;
        }

        .qr-box{
            border:1px solid #e5e7eb;
            border-radius:14px;
            background:#f9fafb;
            min-height:260px;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:20px;
        }

        #qrcode{
            width:200px;
            height:200px;
            display:flex;
            align-items:center;
            justify-content:center;
        }

        .section-title{
            font-size:18px;
            font-weight:700;
            margin:0 0 12px;
            color:#1f2937;
        }

        .access-option-list{
            display:flex;
            flex-direction:column;
            gap:12px;
            margin-bottom:20px;
        }

        .access-option{
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:14px 16px;
            display:flex;
            align-items:flex-start;
            gap:12px;
            cursor:pointer;
            transition:.2s ease;
            background:#fff;
        }

        .access-option:hover{
            background:#f9fafb;
            border-color:#d1d5db;
        }

        .access-option input{
            margin-top:3px;
        }

        .access-option.active{
            border-color:#c7d2fe;
            background:#eef2ff;
        }

        .access-option-title{
            font-weight:700;
            color:#1f2937;
            line-height:1.2;
        }

        .access-option-desc{
            margin-top:3px;
            font-size:13px;
            color:#6b7280;
        }

        .recommended-badge{
            display:inline-block;
            margin-top:6px;
            background:#fff7d6;
            color:#8a6500;
            border:1px solid #f0dc92;
            border-radius:999px;
            padding:4px 10px;
            font-size:12px;
            font-weight:700;
        }

        .link-copy-wrap{
            margin-top:20px;
        }

        .copy-group{
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom:12px;
        }

        .copy-input{
            flex:1;
            height:46px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 14px;
            outline:none;
            background:#fff;
            color:#1f2937;
            font-size:14px;
        }

        .copy-icon-btn{
            width:46px;
            height:46px;
            border:none;
            border-radius:10px;
            background:#e5e7eb;
            color:#111827;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:18px;
        }

        .copy-main-btn,
        .action-main-btn,
        .action-outline-btn{
            height:46px;
            border:none;
            border-radius:10px;
            padding:0 18px;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
        }

        .copy-main-btn,
        .action-main-btn{
            background:#314a93;
            color:#fff;
            width:100%;
        }

        .copy-main-btn:hover,
        .action-main-btn:hover{
            opacity:.95;
            color:#fff;
        }

        .action-outline-btn{
            background:#f2b90f;
            color:#111827;
        }

        .device-hints{
            margin-top:24px;
            border-top:1px solid #e5e7eb;
            padding-top:18px;
            display:flex;
            flex-wrap:wrap;
            gap:14px;
            color:#374151;
            font-size:14px;
            font-weight:600;
        }

        .device-hint{
            display:inline-flex;
            align-items:center;
            gap:8px;
            background:#f9fafb;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:10px 12px;
        }

        .side-section{
            border:1px solid #e5e7eb;
            border-radius:14px;
            padding:18px;
            background:#fff;
            margin-bottom:16px;
        }

        .side-section:last-child{
            margin-bottom:0;
        }

        .form-label-title{
            font-size:14px;
            font-weight:700;
            color:#374151;
            margin-bottom:8px;
            display:block;
        }

        .custom-select{
            width:100%;
            height:46px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 14px;
            outline:none;
            background:#fff;
            color:#1f2937;
            font-size:14px;
        }

        .custom-select:focus{
            border-color:#cbd5e1;
        }

        .session-list{
            display:flex;
            flex-direction:column;
            gap:10px;
            margin-bottom:14px;
        }

        .session-item{
            display:flex;
            align-items:center;
            gap:10px;
            color:#374151;
            font-weight:600;
            font-size:14px;
        }

        .mini-note{
            color:#6b7280;
            font-size:13px;
            margin-top:8px;
        }

        .quick-actions{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:10px;
        }

        .expire-row{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:10px;
            margin-top:12px;
        }

        .small-select{
            width:100%;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 12px;
            background:#fff;
            color:#1f2937;
        }

        .mini-action{
            height:40px;
            border:none;
            border-radius:10px;
            padding:0 14px;
            font-weight:700;
            background:#e5e7eb;
            color:#111827;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
        }

        @media (max-width:1100px){
            .stats-row{
                grid-template-columns:repeat(2,1fr);
            }

            .access-layout{
                grid-template-columns:1fr;
            }

            .left-panel-grid{
                grid-template-columns:1fr;
            }
        }

        @media (max-width:768px){
            .stats-row{
                grid-template-columns:1fr;
            }

            .quick-actions,
            .expire-row{
                grid-template-columns:1fr;
            }

            .sidebar{
                width:240px;
            }

            .page-title{
                font-size:24px;
            }
        }
        /* =====================================================
   MOBILE_ACCESS.PHP DASHBOARD DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.14);
    --oms-border-2:rgba(148,163,184,.22);
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
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= SIDEBAR / LAYOUT ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
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
    transition:margin-left .25s ease;
}

/* Sidebar collapsed support */
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

/* ================= CONTENT BACKGROUND ================= */
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
    position:relative;
    z-index:1;
}

/* ================= PAGE HEADER ================= */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-subtitle{
    margin:6px 0 0 !important;
    color:#94a3b8 !important;
    font-size:14px !important;
    font-weight:600 !important;
}

/* ================= STATS CARDS ================= */
.stats-row{
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
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    transition:.25s ease !important;
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
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-left{
    display:flex !important;
    flex-direction:column !important;
    align-items:flex-start !important;
    gap:10px !important;
    position:relative;
    z-index:2;
}

.stat-card .icon{
    font-size:30px !important;
    opacity:.96;
}

.stat-card .title{
    font-size:15px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
}

.stat-card .count{
    font-size:30px !important;
    font-weight:900 !important;
    line-height:1 !important;
    position:relative;
    z-index:2;
    color:#fff !important;
}

.card-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.card-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.card-red{background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.card-yellow{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

/* ================= MAIN ACCESS GLASS CARDS ================= */
.access-card,
.side-section,
.qr-box{
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

.access-card:hover,
.side-section:hover,
.qr-box:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.access-card{
    overflow:hidden !important;
}

.access-card-header{
    padding:18px 20px 0 !important;
}

.access-card-title,
.section-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.access-card-subtitle,
.mini-note{
    color:#94a3b8 !important;
    font-weight:600 !important;
}

.access-card-body{
    padding:20px !important;
}

/* QR white box inside dark card */
.qr-box{
    min-height:260px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:20px !important;
}

#qrcode{
    background:#ffffff !important;
    padding:14px !important;
    border-radius:16px !important;
    width:auto !important;
    height:auto !important;
    box-shadow:0 18px 38px rgba(0,0,0,.25) !important;
}

/* ================= ACCESS OPTIONS ================= */
.access-option{
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    padding:14px 16px !important;
    background:rgba(15,23,42,.56) !important;
    color:#e5e7eb !important;
    transition:.22s ease !important;
}

.access-option:hover{
    background:rgba(109,76,255,.12) !important;
    border-color:rgba(139,92,246,.36) !important;
    transform:translateY(-1px);
}

.access-option.active{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.22), transparent 40%),
        rgba(15,23,42,.76) !important;
    border-color:rgba(139,92,246,.52) !important;
    box-shadow:0 0 34px rgba(124,58,237,.12) !important;
}

.access-option input{
    accent-color:#7c3aed;
}

.access-option-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.access-option-desc{
    color:#94a3b8 !important;
    font-weight:600 !important;
}

.recommended-badge{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

/* ================= INPUTS / SELECTS ================= */
.copy-input,
.custom-select,
.small-select{
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

.copy-input:focus,
.custom-select:focus,
.small-select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.custom-select option,
.small-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.form-label-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* ================= BUTTONS ================= */
.copy-icon-btn,
.mini-action{
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.copy-icon-btn:hover,
.mini-action:hover{
    color:#fff !important;
    background:rgba(109,76,255,.18) !important;
    border-color:rgba(139,92,246,.42) !important;
    transform:translateY(-1px);
}

.copy-main-btn,
.action-main-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
    border-radius:12px !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.action-outline-btn{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    border:none !important;
    border-radius:12px !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
}

.copy-main-btn:hover,
.action-main-btn:hover,
.action-outline-btn:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* ================= DEVICE HINTS / SESSIONS ================= */
.device-hints{
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#e5e7eb !important;
}

.device-hint,
.session-item{
    background:rgba(15,23,42,.56) !important;
    border:1px solid rgba(148,163,184,.14) !important;
    border-radius:12px !important;
    color:#e5e7eb !important;
}

.device-hint i,
.session-item i{
    color:#a78bfa !important;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2,1fr) !important;
    }

    .access-layout{
        grid-template-columns:1fr !important;
    }

    .left-panel-grid{
        grid-template-columns:1fr !important;
    }
}

@media (max-width:991px){
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

@media (max-width:768px){
    .stats-row{
        grid-template-columns:1fr !important;
    }

    .quick-actions,
    .expire-row{
        grid-template-columns:1fr !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .copy-group{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .copy-icon-btn{
        width:100% !important;
    }
}


/* =========================================================
   MOBILE ACCESS PAGE - FINAL LIGHT MODE
   Sidebar aur topbar dark navy-purple hi rahenge.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;

    --ma-light-bg:#f3f6fb;
    --ma-light-card:#ffffff;
    --ma-light-soft:#f8fafc;
    --ma-light-border:#dce4ef;
    --ma-light-border-strong:#cbd5e1;
    --ma-light-title:#0f172a;
    --ma-light-text:#334155;
    --ma-light-muted:#64748b;
    --ma-light-purple:#7c3aed;
    --ma-light-purple-dark:#6d28d9;
}

/* =========================================================
   PAGE BACKGROUND
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

/* Sidebar ko target nahi karega */
html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 52%,
            #faf7ff 100%
        ) !important;

    color:#0f172a !important;
}

/* Main content */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 7%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 87% 3%,
            rgba(59,130,246,.05),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 48%,
            #faf7ff 78%,
            #f8fafc 100%
        ) !important;

    color:#0f172a !important;
}

/* Dark grid ko light karein */
html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.022) 1px,
            transparent 1px
        ) !important;

    opacity:.44 !important;
    mask-image:none !important;
}

/* Dark purple glow soft */
html[data-pos-theme="light"] body .content-wrapper::after{
    background:
        radial-gradient(
            circle,
            rgba(124,58,237,.08),
            transparent 68%
        ) !important;

    opacity:.42 !important;
    filter:blur(16px) !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body .page-subtitle{
    color:#64748b !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;

    border:
        1px solid rgba(255,255,255,.28) !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .title,
html[data-pos-theme="light"] body .stat-card .count,
html[data-pos-theme="light"] body .stat-card .icon{
    color:#ffffff !important;
    opacity:1 !important;
}

/* Colored cards preserve */
html[data-pos-theme="light"] body .card-blue{
    background:
        linear-gradient(
            135deg,
            #2563eb,
            #6d4cff
        ) !important;
}

html[data-pos-theme="light"] body .card-green{
    background:
        linear-gradient(
            135deg,
            #047857,
            #16a34a
        ) !important;
}

html[data-pos-theme="light"] body .card-red{
    background:
        linear-gradient(
            135deg,
            #be123c,
            #ef4444
        ) !important;
}

html[data-pos-theme="light"] body .card-yellow{
    background:
        linear-gradient(
            135deg,
            #f59e0b,
            #fb923c
        ) !important;
}

/* =========================================================
   MAIN ACCESS CARDS
========================================================= */

html[data-pos-theme="light"] body .access-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .access-card:hover{
    background:#ffffff !important;
    border-color:#c4b5fd !important;

    box-shadow:
        0 17px 38px rgba(15,23,42,.10) !important;
}

html[data-pos-theme="light"] body .access-card-title,
html[data-pos-theme="light"] body .section-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body .access-card-subtitle,
html[data-pos-theme="light"] body .mini-note{
    color:#64748b !important;
}

/* =========================================================
   QR CODE BOX
========================================================= */

html[data-pos-theme="light"] body .qr-box{
    background:#f8fafc !important;
    background-image:none !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.8),
        0 8px 22px rgba(15,23,42,.05) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .qr-box:hover{
    background:#f8fafc !important;
    border-color:#c4b5fd !important;

    box-shadow:
        0 12px 28px rgba(15,23,42,.08) !important;
}

html[data-pos-theme="light"] body #qrcode{
    background:#ffffff !important;

    border:1px solid #e2e8f0 !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.12) !important;
}

/* =========================================================
   ACCESS TYPE OPTIONS
========================================================= */

html[data-pos-theme="light"] body .access-option{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"] body .access-option:hover{
    background:#f8fafc !important;

    border-color:#a78bfa !important;

    transform:translateY(-1px) !important;
}

html[data-pos-theme="light"] body .access-option.active{
    background:
        linear-gradient(
            135deg,
            #f5f3ff,
            #faf7ff
        ) !important;

    border-color:#8b5cf6 !important;

    box-shadow:
        0 8px 22px rgba(124,58,237,.10),
        inset 3px 0 0 #7c3aed !important;
}

html[data-pos-theme="light"] body .access-option input{
    accent-color:#7c3aed !important;
}

html[data-pos-theme="light"] body .access-option-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .access-option-desc{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .recommended-badge{
    background:#fffbeb !important;
    color:#b45309 !important;

    border:1px solid #fde68a !important;
}

/* =========================================================
   INPUTS AND SELECTS
========================================================= */

html[data-pos-theme="light"] body .copy-input,
html[data-pos-theme="light"] body .custom-select,
html[data-pos-theme="light"] body .small-select{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"] body .copy-input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .copy-input:focus,
html[data-pos-theme="light"] body .custom-select:focus,
html[data-pos-theme="light"] body .small-select:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .custom-select option,
html[data-pos-theme="light"] body .small-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .form-label-title{
    color:#334155 !important;
}

/* =========================================================
   COPY AND ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .copy-icon-btn,
html[data-pos-theme="light"] body .mini-action{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:
        0 6px 15px rgba(15,23,42,.05) !important;
}

html[data-pos-theme="light"] body .copy-icon-btn:hover,
html[data-pos-theme="light"] body .mini-action:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body .copy-main-btn,
html[data-pos-theme="light"] body .action-main-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border:none !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

html[data-pos-theme="light"] body .copy-main-btn:hover,
html[data-pos-theme="light"] body .action-main-btn:hover{
    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #5b21b6
        ) !important;

    color:#ffffff !important;
}

html[data-pos-theme="light"] body .action-outline-btn{
    background:
        linear-gradient(
            135deg,
            #f59e0b,
            #fb923c
        ) !important;

    color:#ffffff !important;
    border:none !important;

    box-shadow:
        0 10px 22px rgba(245,158,11,.18) !important;
}

/* =========================================================
   DEVICE HINTS
========================================================= */

html[data-pos-theme="light"] body .device-hints{
    border-top:1px solid #e2e8f0 !important;
    color:#334155 !important;
}

html[data-pos-theme="light"] body .device-hint{
    background:#f8fafc !important;
    color:#334155 !important;

    border:1px solid #dce4ef !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"] body .device-hint i{
    color:#7c3aed !important;
}

/* =========================================================
   RIGHT SIDE SECTIONS
========================================================= */

html[data-pos-theme="light"] body .side-section{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 8px 22px rgba(15,23,42,.05) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .side-section:hover{
    background:#ffffff !important;

    border-color:#c4b5fd !important;

    box-shadow:
        0 12px 28px rgba(15,23,42,.08) !important;
}

/* Active session rows */
html[data-pos-theme="light"] body .session-item{
    background:#f8fafc !important;
    color:#334155 !important;

    border:1px solid #dce4ef !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"] body .session-item i{
    color:#7c3aed !important;
}

/* =========================================================
   RESPONSIVE LIGHT MODE
========================================================= */

@media(max-width:991px){

    html[data-pos-theme="light"] body .main-panel{
        background:
            linear-gradient(
                135deg,
                #f8fafc,
                #f3f0ff
            ) !important;
    }

    html[data-pos-theme="light"] body .content-wrapper{
        background:
            linear-gradient(
                135deg,
                #f8fafc,
                #f3f0ff
            ) !important;
    }
}

@media(max-width:768px){

    html[data-pos-theme="light"] body .access-card,
    html[data-pos-theme="light"] body .side-section,
    html[data-pos-theme="light"] body .qr-box{
        box-shadow:
            0 8px 22px rgba(15,23,42,.06) !important;
    }
}

/* =========================================================
   SCROLLBARS
========================================================= */

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
    border-radius:999px !important;
}

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-thumb:hover{
    background:#8b5cf6 !important;
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
                <div>
                    <h4 class="page-title">Mobile / Web POS Access</h4>
                    <p class="page-subtitle">Scan the QR code or copy the secure link to open POS on mobile devices.</p>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-building"></i></div>
                        <div class="title">Selected Branch</div>
                    </div>
                    <div class="count" style="font-size:18px;"><?= htmlspecialchars($selected_branch_name) ?></div>
                </div>

                <div class="stat-card card-green">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-shield-check"></i></div>
                        <div class="title">Access Type</div>
                    </div>
                    <div class="count" style="font-size:18px;">
                        <?= $access_type === 'public' ? 'Public' : ($access_type === 'restricted' ? 'Restricted' : 'Login') ?>
                    </div>
                </div>

                <div class="stat-card card-red">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-phone"></i></div>
                        <div class="title">Active Sessions</div>
                    </div>
                    <div class="count">2</div>
                </div>

                <div class="stat-card card-yellow">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-clock-history"></i></div>
                        <div class="title">Last Activity</div>
                    </div>
                    <div class="count" style="font-size:18px;">1 min</div>
                </div>
            </div>

            <div class="access-layout">

                <div class="access-card">
                    <div class="access-card-header">
                        <h5 class="access-card-title">QR Access Panel</h5>
                        <p class="access-card-subtitle">Generate and share POS access safely with your team.</p>
                    </div>

                    <div class="access-card-body">
                        <div class="left-panel-grid">

                            <div class="qr-box">
                                <div id="qrcode"></div>
                            </div>

                            <div>
                                <h6 class="section-title">Access Type</h6>

                                <form method="GET" id="accessForm">
                                    <input type="hidden" name="branch_id" value="<?= (int)$selected_branch_id ?>">

                                    <label class="access-option <?= $access_type === 'public' ? 'active' : '' ?>">
                                        <input type="radio" name="access_type" value="public" <?= $access_type === 'public' ? 'checked' : '' ?>>
                                        <div>
                                            <div class="access-option-title">Public Link</div>
                                            <div class="access-option-desc">Anyone with the link can access POS directly.</div>
                                        </div>
                                    </label>

                                    <label class="access-option <?= $access_type === 'login_required' ? 'active' : '' ?>">
                                        <input type="radio" name="access_type" value="login_required" <?= $access_type === 'login_required' ? 'checked' : '' ?>>
                                        <div>
                                            <div class="access-option-title">Login Required</div>
                                            <div class="access-option-desc">Recommended for secure staff access.</div>
                                            <span class="recommended-badge">Recommended</span>
                                        </div>
                                    </label>

                                    <label class="access-option <?= $access_type === 'restricted' ? 'active' : '' ?>">
                                        <input type="radio" name="access_type" value="restricted" <?= $access_type === 'restricted' ? 'checked' : '' ?>>
                                        <div>
                                            <div class="access-option-title">Restricted Devices</div>
                                            <div class="access-option-desc">Allow access only on approved devices.</div>
                                        </div>
                                    </label>
                                </form>

                                <div class="link-copy-wrap">
                                    <div class="copy-group">
                                        <input type="text" id="posLink" class="copy-input" value="<?= htmlspecialchars($mobile_url) ?>" readonly>
                                        <button type="button" class="copy-icon-btn" onclick="copyLink()" title="Copy link">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                    </div>

                                    <button class="copy-main-btn" type="button" onclick="copyLink()">
                                        <i class="bi bi-clipboard-check"></i>
                                        Copy Link
                                    </button>
                                </div>

                                <div class="device-hints">
                                    <div class="device-hint">
                                        <i class="bi bi-phone"></i>
                                        Mobile Billing
                                    </div>
                                    <div class="device-hint">
                                        <i class="bi bi-tablet"></i>
                                        Tablet POS
                                    </div>
                                    <div class="device-hint">
                                        <i class="bi bi-laptop"></i>
                                        Backup POS
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="access-card">
                    <div class="access-card-body">

                        <div class="side-section">
                            <label class="form-label-title">Branch</label>
                            <form method="GET" id="branchForm">
                                <input type="hidden" name="access_type" value="<?= htmlspecialchars($access_type) ?>">
                                <select name="branch_id" class="custom-select" onchange="document.getElementById('branchForm').submit()">
                                    <?php if (!empty($branches)): ?>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?= (int)$branch['id'] ?>" <?= (int)$branch['id'] === (int)$selected_branch_id ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($branch['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="0">No Branch Found</option>
                                    <?php endif; ?>
                                </select>
                            </form>
                            <div class="mini-note">Select a branch to generate QR code.</div>
                        </div>

                        <div class="side-section">
                            <h6 class="section-title">Active Sessions</h6>
                            <div class="session-list">
                                <div class="session-item">
                                    <i class="bi bi-phone"></i>
                                    <span>2 devices connected</span>
                                </div>
                                <div class="session-item">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                    <span>Today Logins: 12</span>
                                </div>
                                <div class="session-item">
                                    <i class="bi bi-clock-history"></i>
                                    <span>Last Activity: 1 min ago</span>
                                </div>
                            </div>

                            <button type="button" class="mini-action">
                                <i class="bi bi-box-arrow-right"></i>
                                Logout All Devices
                            </button>
                        </div>

                        <div class="side-section">
                            <h6 class="section-title">Quick Actions</h6>

                            <div class="quick-actions">
                                <button type="button" class="action-main-btn" onclick="window.open('<?= htmlspecialchars($mobile_url) ?>', '_blank')">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                    Open POS
                                </button>

                                <button type="button" class="action-outline-btn" onclick="regenerateQR()">
                                    <i class="bi bi-qr-code"></i>
                                    Generate New QR
                                </button>
                            </div>

                            <div class="expire-row">
                                <select class="small-select">
                                    <option>Expire After: Never</option>
                                    <option>1 Hour</option>
                                    <option>6 Hours</option>
                                    <option>24 Hours</option>
                                </select>

                                <select class="small-select">
                                    <option>Session Limit: Never</option>
                                    <option>1 Device</option>
                                    <option>2 Devices</option>
                                    <option>5 Devices</option>
                                </select>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        </div>
    </div>

</div>

<script>
function buildQRCode() {
    const qrContainer = document.getElementById("qrcode");
    qrContainer.innerHTML = "";

    new QRCode(qrContainer, {
        text: document.getElementById("posLink").value,
        width: 200,
        height: 200,
        colorDark : "#000000",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });
}

buildQRCode();

function copyLink(){
    const copyText = document.getElementById("posLink");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    document.execCommand("copy");
    alert("Link copied to clipboard!");
}

function regenerateQR(){
    buildQRCode();
    alert("QR code refreshed successfully.");
}

document.querySelectorAll('input[name="access_type"]').forEach(function(radio){
    radio.addEventListener('change', function(){
        document.getElementById('accessForm').submit();
    });
});
</script>

</body>
</html>