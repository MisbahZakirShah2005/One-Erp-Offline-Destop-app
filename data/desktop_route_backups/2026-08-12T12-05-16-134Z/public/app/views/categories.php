<?php
session_start();

/* ================== DEBUG TEMPORARY ==================
   Live error 500 fix ke baad production me comment/remove kar dena.
====================================================== */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================= DB CONNECTION FIX ================= */
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check config/db.php and make sure it creates mysqli variable named \$conn.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);

$is_superadmin = ($role_id === 1);

/*
   Super admin ke liye client_id usually session me nahi hoti.
   Ye page clients ke liye hai. Agar superadmin direct open kare aur client_id nahi hai,
   to message show kar denge.
*/
if (!$is_superadmin && $client_id <= 0) {
    die("Invalid client session.");
}

/* ================= HELPERS ================= */
function clean_text($value) {
    return trim((string)$value);
}

function make_uuid_64() {
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return md5(uniqid((string)mt_rand(), true));
    }
}

/* ================= AJAX SAVE CATEGORY ================= */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'save_category') {
    header('Content-Type: text/plain; charset=utf-8');

    if ($client_id <= 0) {
        echo "Error: Invalid client.";
        exit;
    }

    $category_id = (int)($_POST['category_id'] ?? 0);
    $name        = clean_text($_POST['name'] ?? '');

    if ($name === '') {
        echo "Error: Category name is required.";
        exit;
    }

    try {
        /*
           Duplicate check:
           Same client ke andar same category name duplicate nahi hogi.
        */
        if ($category_id > 0) {
            $stmt = $conn->prepare("
                SELECT id
                FROM categories
                WHERE client_id = ?
                  AND LOWER(name) = LOWER(?)
                  AND id <> ?
                  AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->bind_param("isi", $client_id, $name, $category_id);
        } else {
            $stmt = $conn->prepare("
                SELECT id
                FROM categories
                WHERE client_id = ?
                  AND LOWER(name) = LOWER(?)
                  AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->bind_param("is", $client_id, $name);
        }

        $stmt->execute();
        $dupRes = $stmt->get_result();

        if ($dupRes && $dupRes->num_rows > 0) {
            $stmt->close();
            echo "Error: This category already exists.";
            exit;
        }

        $stmt->close();

        if ($category_id > 0) {
            $stmt = $conn->prepare("
                UPDATE categories
                SET name = ?,
                    sync_status = 'pending',
                    updated_at_local = NOW()
                WHERE id = ?
                  AND client_id = ?
                  AND deleted_at IS NULL
            ");
            $stmt->bind_param("sii", $name, $category_id, $client_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $uuid      = make_uuid_64();
            $device_id = 'cloud';

            $stmt = $conn->prepare("
                INSERT INTO categories
                    (client_id, name, uuid, device_id, sync_status, created_at_local, updated_at_local)
                VALUES
                    (?, ?, ?, ?, 'pending', NOW(), NOW())
            ");
            $stmt->bind_param("isss", $client_id, $name, $uuid, $device_id);
            $stmt->execute();
            $stmt->close();
        }

        echo "ok";
    } catch (Throwable $e) {
        echo "Error: " . $e->getMessage();
    }

    exit;
}

/* ================= AJAX DELETE CATEGORY ================= */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete_category') {
    header('Content-Type: text/plain; charset=utf-8');

    if ($client_id <= 0) {
        echo "Error: Invalid client.";
        exit;
    }

    $category_id = (int)($_POST['id'] ?? 0);

    if ($category_id <= 0) {
        echo "Error: Invalid category id.";
        exit;
    }

    try {
        /*
           Soft delete kar rahe hain taake old records/sync disturb na ho.
        */
        $stmt = $conn->prepare("
            UPDATE categories
            SET deleted_at = NOW(),
                sync_status = 'pending',
                updated_at_local = NOW()
            WHERE id = ?
              AND client_id = ?
              AND deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $category_id, $client_id);
        $stmt->execute();
        $stmt->close();

        echo "ok";
    } catch (Throwable $e) {
        echo "Error: " . $e->getMessage();
    }

    exit;
}

/* ================= FETCH CATEGORIES ================= */
$categories = [];

if ($client_id > 0) {
    try {
        $stmt = $conn->prepare("
            SELECT id, name, created_at_local, updated_at_local
            FROM categories
            WHERE client_id = ?
              AND deleted_at IS NULL
            ORDER BY name ASC
        ");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
        }

        $stmt->close();
    } catch (Throwable $e) {
        die("Categories fetch error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Categories - One ERP</title>
    <script>
/*
|--------------------------------------------------------------------------
| CATEGORIES PAGE THEME INITIALIZER
|--------------------------------------------------------------------------
| POS, Dashboard, Orders aur Categories same theme use karenge.
|--------------------------------------------------------------------------
*/
(function () {
    const storageKey = 'oneerp_pos_theme';
    let selectedTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

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

    <?php include('partials/head.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:
                radial-gradient(circle at 12% 8%, rgba(124,58,237,.28), transparent 28%),
                radial-gradient(circle at 78% 2%, rgba(59,130,246,.14), transparent 30%),
                linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
            color: #f8fafc !important;
            overflow-x: hidden;
        }

        .categories-page {
            padding: 90px 24px 30px;
            min-height: 100vh;
        }

        .page-card {
            background:
                radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
                linear-gradient(135deg, rgba(12,18,35,.92), rgba(20,12,40,.82)) !important;
            border: 1px solid rgba(139,92,246,.24);
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(0,0,0,.28);
            overflow: hidden;
        }

        .page-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid rgba(148,163,184,.12);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .page-title {
            font-size: 24px;
            font-weight: 900;
            margin: 0;
            color: #fff;
        }

        .page-subtitle {
            color: #94a3b8;
            font-size: 13px;
            font-weight: 700;
            margin-top: 4px;
        }

        .btn-purple {
            min-height: 44px;
            border: none;
            border-radius: 13px;
            padding: 0 18px;
            background: linear-gradient(135deg,#6d4cff,#7c3aed);
            color: #fff;
            font-weight: 900;
            box-shadow: 0 14px 28px rgba(109,76,255,.30);
        }

        .btn-purple:hover {
            color: #fff;
            transform: translateY(-1px);
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            margin: 0 !important;
            color: #e5e7eb !important;
            --bs-table-bg: transparent !important;
            --bs-table-color: #e5e7eb !important;
            --bs-table-border-color: rgba(148,163,184,.10) !important;
        }

        thead th {
            background: rgba(6,10,20,.95) !important;
            color: #f8fafc !important;
            border: none !important;
            border-bottom: 1px solid rgba(139,92,246,.22) !important;
            padding: 15px 18px !important;
            font-size: 13px;
            font-weight: 900;
            text-transform: uppercase;
            white-space: nowrap;
        }

        tbody td {
            background: linear-gradient(135deg, rgba(15,23,42,.48), rgba(20,12,40,.30)) !important;
            color: #e5e7eb !important;
            border: none !important;
            border-bottom: 1px solid rgba(148,163,184,.08) !important;
            padding: 15px 18px !important;
            font-size: 14px;
            font-weight: 700;
            vertical-align: middle;
        }

        tbody tr:hover td {
            background:
                radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
                linear-gradient(135deg, rgba(30,20,58,.58), rgba(15,23,42,.42)) !important;
            color: #fff !important;
        }

        .action-icon {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            cursor: pointer;
            margin-right: 6px;
            border: 1px solid rgba(148,163,184,.15);
            background: rgba(15,23,42,.68);
        }

        .action-icon.edit {
            color: #facc15;
        }

        .action-icon.delete {
            color: #fb7185;
        }

        .modal-content {
            background:
                radial-gradient(circle at 18% 0%, rgba(124,58,237,.30), transparent 38%),
                linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.88)) !important;
            color: #f8fafc;
            border: 1px solid rgba(139,92,246,.38);
            border-radius: 20px;
            box-shadow: 0 28px 80px rgba(0,0,0,.62);
        }

        .modal-header,
        .modal-footer {
            border-color: rgba(148,163,184,.16);
        }

        .form-label {
            color: #dbe4ff;
            font-weight: 850;
            font-size: 13px;
        }

        .form-control {
            min-height: 46px;
            border-radius: 13px;
            background: rgba(15,23,42,.78);
            color: #f8fafc;
            border: 1px solid rgba(148,163,184,.20);
            font-weight: 800;
        }

        .form-control:focus {
            background: rgba(17,24,39,.94);
            color: #fff;
            border-color: rgba(124,58,237,.62);
            box-shadow: 0 0 0 3px rgba(124,58,237,.16);
        }

        .btn-close {
            filter: invert(1);
        }

        @media (max-width: 991px) {
            .categories-page {
                padding: 86px 14px 24px;
            }
        }
    
    
    /* =====================================================
   CATEGORIES PAGE - SAME DARK PURPLE GLASS THEME AS ITEMS.PHP
   Backend / PHP / JS untouched
===================================================== */

html, body{
    min-height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.16);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-green:#22c55e;
    --oms-red:#ef4444;
    --oms-yellow:#f59e0b;
}

body{
    margin:0 !important;
    font-family:"Inter","Segoe UI", Tahoma, Geneva, Verdana, sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
    overflow-x:hidden !important;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT SAME AS ITEMS ================= */

.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    width:100% !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
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

.sidebar > div,
.sidebar .sidebar-wrap,
#dashboardSidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
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

/* topbar ko items.php jesa dark sticky look */
.topbar,
.main-panel > .topbar{
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

/* ================= PAGE BACKGROUND ================= */

.categories-page{
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

.categories-page::before{
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

.categories-page::after{
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

.categories-page > *{
    position:relative !important;
    z-index:1 !important;
}

/* ================= CARD SAME AS ITEMS TABLE CARD ================= */

.page-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    transition:.25s ease !important;
}

.page-card:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.page-card-header{
    padding:20px 22px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    background:rgba(7,11,20,.28) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    flex-wrap:wrap !important;
}

.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
}

.page-title i{
    color:#a78bfa !important;
}

.page-subtitle{
    margin-top:6px !important;
    color:#94a3b8 !important;
    font-size:13px !important;
    font-weight:800 !important;
}

/* ================= BUTTON ================= */

.btn-purple{
    border:none !important;
    border-radius:13px !important;
    padding:11px 17px !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    cursor:pointer !important;
    min-height:46px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border:1px solid rgba(139,92,246,.38) !important;
    box-shadow:0 14px 28px rgba(0,0,0,.22) !important;
    transition:.22s ease !important;
}

.btn-purple:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
}

/* ================= TABLE ================= */

.table-wrap{
    overflow:auto !important;
}

.table{
    margin:0 !important;
    color:#e5e7eb !important;
    background:transparent !important;
    min-width:900px !important;
}

.table,
.table tbody,
.table tr,
.table td,
.table th{
    --bs-table-bg:transparent !important;
    --bs-table-accent-bg:transparent !important;
    --bs-table-hover-bg:transparent !important;
    --bs-table-hover-color:#ffffff !important;
    --bs-table-striped-bg:transparent !important;
    --bs-table-active-bg:transparent !important;
    background-color:transparent !important;
}

.table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:15px 14px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
}

.table tbody tr:hover td{
    background:rgba(124,58,237,.10) !important;
    color:#ffffff !important;
}

.table tbody td strong{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* ================= ACTION ICONS SAME ITEMS STYLE ================= */

.action-icon{
    width:40px !important;
    height:40px !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:16px !important;
    cursor:pointer !important;
    transition:all .20s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    margin-right:8px !important;
}

.action-icon:hover{
    transform:translateY(-2px) !important;
}

.action-icon.edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.action-icon.edit:hover{
    background:#f59e0b !important;
    color:#07111f !important;
}

.action-icon.delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

.action-icon.delete:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

/* ================= MODAL SAME ITEMS STYLE ================= */

.modal-backdrop.show{
    opacity:.76 !important;
    background:#000 !important;
}

#categoryModal{
    z-index:99999 !important;
}

#categoryModal .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.98), rgba(17,24,39,.96)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:20px !important;
    overflow:hidden !important;
    box-shadow:
        0 30px 90px rgba(0,0,0,.72),
        0 0 55px rgba(124,58,237,.16) !important;
}

#categoryModal .modal-header{
    min-height:66px !important;
    padding:14px 18px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#categoryModal .modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
}

#categoryModal .modal-body{
    padding:16px 18px !important;
    background:rgba(15,23,42,.52) !important;
    color:#f8fafc !important;
}

#categoryModal .modal-footer{
    padding:12px 18px 14px !important;
    background:rgba(7,11,20,.48) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

#categoryModal .form-label{
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:6px !important;
}

#categoryModal .form-control{
    background:#ffffff !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    min-height:40px !important;
    height:40px !important;
    font-size:14px !important;
    font-weight:700 !important;
    padding:7px 12px !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#categoryModal .form-control:focus{
    background:#fff !important;
    color:#111827 !important;
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 4px rgba(124,58,237,.22),
        0 10px 22px rgba(0,0,0,.18) !important;
}

#categoryModal .btn-dark{
    height:38px !important;
    min-width:105px !important;
    border-radius:12px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:0 18px !important;
    box-shadow:0 10px 20px rgba(0,0,0,.18) !important;
}

#categoryModal .btn-dark:hover{
    background:rgba(148,163,184,.28) !important;
    color:#fff !important;
}

#categoryModal .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:13px !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    position:relative !important;
    background-image:none !important;
    padding:0 !important;
}

#categoryModal .btn-close::before,
#categoryModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:18px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#categoryModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#categoryModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#categoryModal .btn-close:hover{
    background:rgba(239,68,68,.22) !important;
    border-color:rgba(239,68,68,.35) !important;
}

/* ================= RESPONSIVE ================= */

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

    .main-panel{
        margin-left:0 !important;
    }

    .categories-page{
        padding:18px !important;
    }
}

@media (max-width:768px){
    .page-card-header{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .btn-purple{
        width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    #categoryModal .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #categoryModal .btn-dark,
    #categoryModal .btn-purple{
        width:100% !important;
    }
}
    
    
    /* =========================================================
   ONEERP CATEGORIES PAGE LIGHT MODE
   Sirf light mode affect hoga.
   Dark mode, PHP, AJAX aur functionality unchanged.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE BACKGROUND
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] .container-scroll{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] .main-panel{
    background:#f3f6fb !important;
}

/* =========================================================
   SIDEBAR AND TOPBAR
========================================================= */

/*html[data-pos-theme="light"] .sidebar,*/
/*html[data-pos-theme="light"] #dashboardSidebar,*/
/*html[data-pos-theme="light"] #dashboardSidebar .sidebar-wrap{*/
/*    background:#ffffff !important;*/
/*    border-right:1px solid #e2e8f0 !important;*/
/*}*/

/*html[data-pos-theme="light"] .topbar,*/
/*html[data-pos-theme="light"] .main-panel > .topbar{*/
/*    background:#ffffff !important;*/
/*    border-bottom-color:#e2e8f0 !important;*/
/*    box-shadow:0 4px 18px rgba(15,23,42,.06) !important;*/
/*}*/

/* =========================================================
   CATEGORIES CONTENT BACKGROUND
========================================================= */

html[data-pos-theme="light"] .categories-page{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.10),
            transparent 28%
        ),
        radial-gradient(
            circle at 78% 2%,
            rgba(59,130,246,.07),
            transparent 30%
        ),
        radial-gradient(
            circle at 92% 86%,
            rgba(245,158,11,.05),
            transparent 26%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 42%,
            #faf7ff 72%,
            #f8fafc 100%
        ) !important;
}

/* Background grid */
html[data-pos-theme="light"] .categories-page::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.035) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ) !important;
    opacity:.55 !important;
}

/* Purple glow ko soft karein */
html[data-pos-theme="light"] .categories-page::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.10),
        transparent 68%
    ) !important;
    opacity:.5 !important;
}

/* =========================================================
   MAIN CARD
========================================================= */

html[data-pos-theme="light"] .page-card{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.055),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.08) !important;
    backdrop-filter:none !important;
}

html[data-pos-theme="light"] .page-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:
        0 16px 38px rgba(15,23,42,.10),
        0 0 0 1px rgba(124,58,237,.07) !important;
}

/* Card header */
html[data-pos-theme="light"] .page-card-header{
    background:#ffffff !important;
    border-bottom-color:#e2e8f0 !important;
}

/* =========================================================
   TITLE AND SUBTITLE
========================================================= */

html[data-pos-theme="light"] .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] .page-title i{
    color:#7c3aed !important;
}

html[data-pos-theme="light"] .page-subtitle{
    color:#475569 !important;
}

/* =========================================================
   ADD CATEGORY BUTTON
========================================================= */

html[data-pos-theme="light"] .btn-purple{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
    box-shadow:0 10px 24px rgba(124,58,237,.20) !important;
}

html[data-pos-theme="light"] .btn-purple:hover{
    background:linear-gradient(
        135deg,
        #7c3aed,
        #5b21b6
    ) !important;
    color:#ffffff !important;
}

/* =========================================================
   CATEGORY TABLE
========================================================= */

html[data-pos-theme="light"] .table{
    color:#1e293b !important;
    background:#ffffff !important;

    --bs-table-bg:#ffffff !important;
    --bs-table-color:#1e293b !important;
    --bs-table-border-color:#e2e8f0 !important;
    --bs-table-hover-bg:#f5f1ff !important;
    --bs-table-hover-color:#0f172a !important;
}

/* Table header */
html[data-pos-theme="light"] .table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom-color:#cbd5e1 !important;
    border-right-color:#e2e8f0 !important;
}

/* Table body */
html[data-pos-theme="light"] .table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-top-color:#e8edf4 !important;
    border-bottom-color:#e8edf4 !important;
}

/* Category name */
html[data-pos-theme="light"] .table tbody td strong{
    color:#0f172a !important;
}

/* Row hover */
html[data-pos-theme="light"] .table tbody tr:hover td{
    background:#f5f1ff !important;
    color:#0f172a !important;
}

/* =========================================================
   ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] .action-icon{
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}

/* Edit */
html[data-pos-theme="light"] .action-icon.edit{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] .action-icon.edit:hover{
    background:#f59e0b !important;
    color:#ffffff !important;
    border-color:#f59e0b !important;
}

/* Delete */
html[data-pos-theme="light"] .action-icon.delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] .action-icon.delete:hover{
    background:#ef4444 !important;
    color:#ffffff !important;
    border-color:#ef4444 !important;
}

/* =========================================================
   CATEGORY MODAL
========================================================= */

html[data-pos-theme="light"] #categoryModal .modal-content{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#dce4ef !important;
    box-shadow:0 24px 70px rgba(15,23,42,.20) !important;
}

/* Modal header */
html[data-pos-theme="light"] #categoryModal .modal-header{
    background:#ffffff !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] #categoryModal .modal-title{
    color:#0f172a !important;
}

/* Modal body */
html[data-pos-theme="light"] #categoryModal .modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Modal footer */
html[data-pos-theme="light"] #categoryModal .modal-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

/* Label */
html[data-pos-theme="light"] #categoryModal .form-label{
    color:#334155 !important;
}

/* Input */
html[data-pos-theme="light"] #categoryModal .form-control{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] #categoryModal .form-control::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] #categoryModal .form-control:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

/* Cancel button */
html[data-pos-theme="light"] #categoryModal .btn-dark{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] #categoryModal .btn-dark:hover{
    background:#f1f5f9 !important;
    color:#0f172a !important;
}

/* Modal close button */
html[data-pos-theme="light"] #categoryModal .btn-close{
    background:#f1f5f9 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] #categoryModal .btn-close::before,
html[data-pos-theme="light"] #categoryModal .btn-close::after{
    background:#334155 !important;
}

html[data-pos-theme="light"] #categoryModal .btn-close:hover{
    background:#fff1f2 !important;
    border-color:#fecdd3 !important;
}

/* Modal backdrop */
html[data-pos-theme="light"] + body .modal-backdrop.show,
html[data-pos-theme="light"] body .modal-backdrop.show{
    background:#0f172a !important;
    opacity:.45 !important;
}
    </style>
</head>

<body>

<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

 <div class="main-panel">
    <div class="topbar">
        <?php include('partials/topbar.php'); ?>
    </div>

    <div class="categories-page">
            <div class="page-card">

                <div class="page-card-header">
                    <div>
                        <h4 class="page-title">
                            <i class="bi bi-tags"></i> Categories
                        </h4>
                        <div class="page-subtitle">
                            Create, edit and manage product/service categories.
                        </div>
                    </div>

                    <?php if ($client_id > 0): ?>
                        <button type="button" class="btn btn-purple" onclick="openAddCategory()">
                            <i class="bi bi-plus-circle"></i> Add Category
                        </button>
                    <?php endif; ?>
                </div>

                <div class="table-wrap">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th width="80">ID</th>
                                <th>Category Name</th>
                                <th width="180">Created</th>
                                <th width="180">Updated</th>
                                <th width="130">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    No categories found.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><?= (int)$cat['id'] ?></td>

                                <td>
                                    <strong><?= htmlspecialchars($cat['name'] ?? '') ?></strong>
                                </td>

                                <td>
                                    <?= !empty($cat['created_at_local']) ? htmlspecialchars($cat['created_at_local']) : '-' ?>
                                </td>

                                <td>
                                    <?= !empty($cat['updated_at_local']) ? htmlspecialchars($cat['updated_at_local']) : '-' ?>
                                </td>

                                <td>
                                    <span class="action-icon edit"
                                          title="Edit"
                                          onclick="openEditCategory(
                                              <?= (int)$cat['id'] ?>,
                                              '<?= htmlspecialchars($cat['name'] ?? '', ENT_QUOTES) ?>'
                                          )">
                                        <i class="bi bi-pencil-square"></i>
                                    </span>

                                    <span class="action-icon delete"
                                          title="Delete"
                                          onclick="deleteCategory(<?= (int)$cat['id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- CATEGORY MODAL -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <form id="categoryForm">
                <input type="hidden" name="category_id" id="category_id">

                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalTitle">Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <label class="form-label">Category Name</label>
                    <input type="text"
                           name="name"
                           id="category_name"
                           class="form-control"
                           placeholder="Enter category name"
                           required>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-purple">Save Category</button>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));

function openAddCategory() {
    document.getElementById('categoryForm').reset();
    document.getElementById('category_id').value = '';
    document.getElementById('categoryModalTitle').innerText = 'Add Category';
    categoryModal.show();

    setTimeout(() => {
        document.getElementById('category_name').focus();
    }, 300);
}

function openEditCategory(id, name) {
    document.getElementById('categoryForm').reset();
    document.getElementById('category_id').value = id;
    document.getElementById('category_name').value = name;
    document.getElementById('categoryModalTitle').innerText = 'Edit Category';
    categoryModal.show();

    setTimeout(() => {
        document.getElementById('category_name').focus();
    }, 300);
}

document.getElementById('categoryForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('ajax', 'save_category');

    fetch('', {
        method: 'POST',
        body: formData
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
        alert('Save request failed.');
    });
});

function deleteCategory(id) {
    if (!confirm('Delete this category?')) {
        return;
    }

    const body = new URLSearchParams();
    body.append('ajax', 'delete_category');
    body.append('id', id);

    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: body.toString()
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
        alert('Delete request failed.');
    });
}
</script>

</body>
</html>