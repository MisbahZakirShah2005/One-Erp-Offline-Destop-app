<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = $_SESSION['user_id'];
$logged_role_id   = $_SESSION['role_id'];
$logged_client_id = $_SESSION['client_id'] ?? 0;

/* ================= SET BUSINESS NAME ================= */
if ($logged_role_id != 1 && !isset($_SESSION['business_name'])) {
    $stmt_client = $conn->prepare("SELECT business_name FROM clients WHERE id = ?");
    $stmt_client->bind_param("i", $logged_client_id);
    $stmt_client->execute();
    $stmt_client->bind_result($business_name);
    $stmt_client->fetch();
    $_SESSION['business_name'] = $business_name ?? '';
    $stmt_client->close();
}

/* ================= FILTERS ================= */
$search = trim($_GET['search'] ?? '');

/* ================= FETCH CLIENTS (SUPER ADMIN) ================= */
$clients = [];
if ($logged_role_id == 1) {
    $clientRes = $conn->query("SELECT id, business_name FROM clients ORDER BY business_name ASC");
    if ($clientRes) {
        while ($c = $clientRes->fetch_assoc()) {
            $clients[] = $c;
        }
    }
}

/* ================= FETCH CLIENT STAFF USERS (CLIENT ADMIN) ================= */
$client_users = [];
if ($logged_role_id != 1) {
    $stmt_users = $conn->prepare("SELECT id, name FROM users WHERE client_id=? ORDER BY name ASC");
    $stmt_users->bind_param("i", $logged_client_id);
    $stmt_users->execute();
    $res_users = $stmt_users->get_result();
    while ($u = $res_users->fetch_assoc()) {
        $client_users[] = $u;
    }
    $stmt_users->close();
}

/* ================= HANDLE ADD BRANCH ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_branch'])) {
    $branch_name = trim($_POST['branch_name'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $client_id   = ($logged_role_id == 1) ? (int)($_POST['client_id'] ?? 0) : (int)$logged_client_id;
    $user_ids    = $_POST['user_ids'] ?? [];

    if (!$branch_name || !$address || !$client_id) {
        $_SESSION['error'] = "All branch fields are required!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO branches (client_id, name, address) VALUES (?,?,?)");
    $stmt->bind_param("iss", $client_id, $branch_name, $address);

    if ($stmt->execute()) {
        $branch_id = $stmt->insert_id;

        if ($logged_role_id != 1 && !empty($user_ids)) {
            foreach ($user_ids as $uid) {
                $uid = (int)$uid;
                $stmt_user = $conn->prepare("INSERT IGNORE INTO branch_users (branch_id, user_id) VALUES (?,?)");
                $stmt_user->bind_param("ii", $branch_id, $uid);
                $stmt_user->execute();
                $stmt_user->close();
            }
        }

        $_SESSION['success'] = "Branch added successfully!";
    } else {
        $_SESSION['error'] = $stmt->error;
    }

    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ================= HANDLE EDIT BRANCH ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_branch_id'])) {
    $branch_id   = (int)$_POST['edit_branch_id'];
    $branch_name = trim($_POST['branch_name'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $client_id   = ($logged_role_id == 1) ? (int)($_POST['client_id'] ?? 0) : (int)$logged_client_id;
    $user_ids    = $_POST['user_ids'] ?? [];

    $stmt = $conn->prepare("UPDATE branches SET name=?, address=?, client_id=? WHERE id=?");
    $stmt->bind_param("ssii", $branch_name, $address, $client_id, $branch_id);
    $stmt->execute();
    $stmt->close();

    $conn->query("DELETE FROM branch_users WHERE branch_id=" . (int)$branch_id);

    foreach ($user_ids as $uid) {
        $uid = (int)$uid;
        $stmt_user = $conn->prepare("INSERT IGNORE INTO branch_users (branch_id, user_id) VALUES (?,?)");
        $stmt_user->bind_param("ii", $branch_id, $uid);
        $stmt_user->execute();
        $stmt_user->close();
    }

    $_SESSION['success'] = "Branch updated successfully!";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ================= HANDLE DELETE BRANCH ================= */
if (isset($_GET['delete_branch'])) {
    $branch_id = (int)$_GET['delete_branch'];

    $stmt = $conn->prepare("DELETE FROM branches WHERE id=?");
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['success'] = "Branch deleted successfully!";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ================= FETCH BRANCHES ================= */
$branches = [];

if ($logged_role_id == 1) {
    $sql = "
        SELECT 
            b.id,
            b.name AS branch_name,
            b.address,
            b.client_id,
            c.business_name
        FROM branches b
        LEFT JOIN clients c ON c.id = b.client_id
        WHERE 1=1
    ";

    $params = [];
    $types = '';

    if ($search !== '') {
        $sql .= " AND (b.name LIKE ? OR b.address LIKE ? OR c.business_name LIKE ?)";
        $searchLike = "%{$search}%";
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= 'sss';
    }

    $sql .= " ORDER BY b.name ASC";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $branches[] = $row;
        }
        $stmt->close();
    } else {
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $branches[] = $row;
            }
        }
    }
} else {
    $sql = "
        SELECT 
            id,
            name AS branch_name,
            address,
            client_id
        FROM branches
        WHERE client_id=?
    ";

    if ($search !== '') {
        $sql .= " AND (name LIKE ? OR address LIKE ?)";
    }

    $sql .= " ORDER BY name ASC";

    if ($search !== '') {
        $stmt = $conn->prepare($sql);
        $searchLike = "%{$search}%";
        $stmt->bind_param("iss", $logged_client_id, $searchLike, $searchLike);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $logged_client_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $branches[] = $row;
    }
    $stmt->close();
}

/* ================= FETCH ASSIGNED USERS ================= */
$branch_users_map = [];
$resUsersMap = $conn->query("
    SELECT bu.branch_id, u.name
    FROM branch_users bu
    JOIN users u ON bu.user_id = u.id
");

if ($resUsersMap) {
    while ($row = $resUsersMap->fetch_assoc()) {
        $branch_users_map[$row['branch_id']][] = $row['name'];
    }
}

/* ================= STATS ================= */
$totalBranches = count($branches);
$activeBranches = count($branches);
$inactiveBranches = 0;
$totalAssignedUsers = 0;

foreach ($branch_users_map as $names) {
    $totalAssignedUsers += count($names);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Branches</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include('partials/head.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

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
        }

        .sidebar{
            width:250px;
            flex-shrink:0;
        }

        .main-panel{
            flex-grow:1;
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
            padding:20px;
            flex-grow:1;
            overflow-y:auto;
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

        .header-btn{
            height:44px;
            border:none;
            border-radius:10px;
            padding:0 16px;
            background:#e5e7eb;
            color:#111827;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            gap:8px;
            text-decoration:none;
        }

        .header-btn:hover{
            background:#dbe0e8;
            color:#111827;
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
            font-size:30px;
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

        .filter-bar{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:12px;
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            margin-bottom:14px;
        }

        .search-wrap{
            flex:1.6;
            min-width:240px;
            position:relative;
        }

        .search-wrap i{
            position:absolute;
            left:12px;
            top:50%;
            transform:translateY(-50%);
            color:#6b7280;
            font-size:14px;
        }

        .search-wrap input{
            width:100%;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 14px 0 38px;
            outline:none;
            background:#fff;
            color:#1f2937;
            font-size:14px;
        }

        .search-wrap input:focus,
        .filter-select:focus{
            border-color:#cbd5e1;
            box-shadow:none;
            outline:none;
        }

        .select-wrap{
            min-width:150px;
        }

        .filter-select{
            width:100%;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 36px 0 14px;
            outline:none;
            background:#fff;
            color:#1f2937;
            font-size:14px;
            font-weight:500;
            cursor:pointer;
        }

        .icon-btn{
            width:44px;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            background:#fff;
            color:#374151;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:16px;
            transition:0.2s ease;
        }

        .icon-btn:hover{
            background:#f9fafb;
            border-color:#d1d5db;
        }

        .export-btn{
            height:44px;
            border:none;
            border-radius:10px;
            padding:0 18px;
            background:#314a93;
            color:#fff;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .export-btn:hover{
            opacity:.95;
            color:#fff;
        }

        .action-toolbar{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:12px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:16px;
        }

        .action-toolbar-left{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }

        .add-btn{
            height:44px;
            border:none;
            border-radius:10px;
            padding:0 18px;
            background:#f2b90f;
            color:#111827;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }

        .bulk-select{
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 36px 0 14px;
            outline:none;
            background:#fff;
            color:#1f2937;
            font-size:14px;
            font-weight:500;
            min-width:160px;
        }

        .mini-btn{
            width:44px;
            height:44px;
            border:none;
            border-radius:10px;
            background:#6b7280;
            color:#fff;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .table-card{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            overflow:hidden;
        }

        .table-responsive{
            overflow:auto;
        }

        .branches-table{
            width:100%;
            min-width:1150px;
            border-collapse:collapse;
        }

        .branches-table thead th{
            background:#28344d;
            color:#fff;
            font-weight:600;
            font-size:14px;
            padding:14px 12px;
            border-right:1px solid rgba(255,255,255,0.08);
            white-space:nowrap;
        }

        .branches-table tbody td{
            padding:14px 12px;
            border-top:1px solid #edf0f5;
            vertical-align:middle;
            font-size:14px;
            color:#374151;
            background:#fff;
            white-space:nowrap;
        }

        .branches-table tbody tr:hover td{
            background:#fafbfc;
        }

        .branch-main{
            font-weight:700;
            color:#1f2937;
        }

        .branch-sub{
            display:block;
            font-size:12px;
            color:#667085;
            margin-top:4px;
        }

        .users-badge{
            padding:6px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            gap:6px;
            background:#eef2ff;
            color:#314a93;
        }

        .action-btn{
            border:none;
            background:#f3f4f6;
            color:#111827;
            border-radius:10px;
            width:38px;
            height:38px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .action-btn:hover{
            background:#e5e7eb;
        }

        .dropdown-menu{
            border:1px solid #e5e7eb;
            border-radius:12px;
            box-shadow:0 10px 30px rgba(0,0,0,.08);
            padding:8px;
        }

        .dropdown-item{
            border-radius:8px;
            padding:10px 12px;
            color:#1f2937;
        }

        .dropdown-item:hover{
            background:#f3f4f6;
            color:#111827;
        }

        .bottom-actions{
            padding:14px;
            border-top:1px solid #e5e7eb;
            background:#fff;
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
            color:#6b7280;
            font-weight:600;
            font-size:14px;
        }

        .rows-select{
            height:36px;
            border:1px solid #e5e7eb;
            border-radius:8px;
            padding:0 10px;
            background:#fff;
        }

        .custom-modal{
            border:none;
            border-radius:14px;
            overflow:hidden;
            box-shadow:0 20px 50px rgba(0,0,0,0.15);
        }

        .custom-modal .modal-header{
            background:#fff;
            border-bottom:1px solid #e5e7eb;
            padding:18px 20px;
        }

        .custom-modal .modal-title{
            font-size:24px;
            font-weight:700;
            color:#1f2937;
        }

        .custom-modal .modal-body{
            background:#fff;
            padding:20px;
        }

        .custom-modal .modal-footer{
            background:#fff;
            border-top:1px solid #e5e7eb;
            padding:16px 20px;
        }

        .custom-input{
            min-height:46px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            background:#fff;
            color:#1f2937;
            box-shadow:none !important;
        }

        .custom-input:focus{
            border-color:#cbd5e1;
        }

        .cancel-btn{
            background:#e5e7eb;
            color:#111827;
            font-weight:700;
            border-radius:10px;
            padding:10px 18px;
            border:none;
        }

        .save-btn{
            background:#f2b90f;
            color:#111827;
            font-weight:700;
            border-radius:10px;
            padding:10px 18px;
            border:none;
        }

        .save-btn:hover{
            background:#e2ac0d;
            color:#111827;
        }

        .cancel-btn:hover{
            background:#d7dce3;
            color:#111827;
        }

        @media (max-width:1100px){
            .stats-row{
                grid-template-columns:repeat(2,1fr);
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
            .header-btn{
                width:100%;
                min-width:100%;
            }

            .action-toolbar-left{
                width:100%;
            }

            .page-title{
                font-size:24px;
            }

            .sidebar{
                width:240px;
            }
        }
        .table-check{
    width:18px;
    height:18px;
    cursor:pointer;
}

.branches-table{
    width:100%;
    min-width:1450px;
    border-collapse:collapse;
}

.branch-cell{
    display:flex;
    flex-direction:column;
    gap:2px;
}

.manager-cell{
    display:flex;
    align-items:center;
    gap:10px;
}

.manager-avatar{
    width:32px;
    height:32px;
    border-radius:50%;
    background:#e5e7eb;
    color:#111827;
    font-size:12px;
    font-weight:700;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}

.stock-badge{
    padding:6px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    gap:6px;
}

.stock-badge .dot{
    width:8px;
    height:8px;
    border-radius:50%;
    display:inline-block;
}

.stock-in{
    background:#e8f7ec;
    color:#17663c;
}

.stock-in .dot{
    background:#22c55e;
}

.stock-low{
    background:#fff1e8;
    color:#b45309;
}

.stock-low .dot{
    background:#f97316;
}

.mode-badge{
    padding:6px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    background:#f3f4f6;
    color:#374151;
}

.branch-status-badge{
    padding:6px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    display:inline-flex;
    align-items:center;
}

.branch-status-active{
    background:#e8f7ec;
    color:#17663c;
}

.branch-status-inactive{
    background:#fdecec;
    color:#a32929;
}
    

/* =========================================================
   FINAL BRANCHES POPUP BLACK + GOLD + WHITE THEME
   Add Branch + Edit Branch Modals
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

#addBranchModal .modal-dialog,
[id^="editBranchModal"] .modal-dialog{
    max-width:min(860px,94vw) !important;
}

#addBranchModal .modal-content,
#addBranchModal .custom-modal,
[id^="editBranchModal"] .modal-content,
[id^="editBranchModal"] .custom-modal{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.62) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

#addBranchModal .modal-header,
[id^="editBranchModal"] .modal-header{
    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
    min-height:76px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#addBranchModal .modal-title,
[id^="editBranchModal"] .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#addBranchModal .modal-title::before,
[id^="editBranchModal"] .modal-title::before{
    content:'' !important;
    width:13px !important;
    height:13px !important;
    border-radius:50% !important;
    background:#c9a84c !important;
    box-shadow:0 0 0 5px rgba(201,168,76,.15) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#addBranchModal .btn-close,
[id^="editBranchModal"] .btn-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#addBranchModal .btn-close::before,
#addBranchModal .btn-close::after,
[id^="editBranchModal"] .btn-close::before,
[id^="editBranchModal"] .btn-close::after{
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

#addBranchModal .btn-close::before,
[id^="editBranchModal"] .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#addBranchModal .btn-close::after,
[id^="editBranchModal"] .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#addBranchModal .btn-close:hover,
[id^="editBranchModal"] .btn-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px) !important;
}

#addBranchModal .modal-body,
[id^="editBranchModal"] .modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#addBranchModal .modal-body::-webkit-scrollbar,
[id^="editBranchModal"] .modal-body::-webkit-scrollbar{
    width:7px !important;
}

#addBranchModal .modal-body::-webkit-scrollbar-track,
[id^="editBranchModal"] .modal-body::-webkit-scrollbar-track{
    background:#171c28 !important;
    border-radius:999px !important;
}

#addBranchModal .modal-body::-webkit-scrollbar-thumb,
[id^="editBranchModal"] .modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c !important;
    border-radius:999px !important;
}

#addBranchModal .modal-footer,
[id^="editBranchModal"] .modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.28) !important;
    padding:18px 24px 22px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:12px !important;
}

#addBranchModal .form-label,
#addBranchModal label,
[id^="editBranchModal"] .form-label,
[id^="editBranchModal"] label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#addBranchModal .custom-input,
#addBranchModal .form-control,
#addBranchModal .form-select,
#addBranchModal input[type="text"],
#addBranchModal select,
[id^="editBranchModal"] .custom-input,
[id^="editBranchModal"] .form-control,
[id^="editBranchModal"] .form-select,
[id^="editBranchModal"] input[type="text"],
[id^="editBranchModal"] select{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    min-height:50px !important;
    padding:10px 16px !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#addBranchModal select[multiple],
[id^="editBranchModal"] select[multiple]{
    height:auto !important;
    min-height:160px !important;
    padding:10px 16px !important;
}

#addBranchModal input::placeholder,
[id^="editBranchModal"] input::placeholder{
    color:#8993a5 !important;
}

#addBranchModal .custom-input:focus,
#addBranchModal .form-control:focus,
#addBranchModal .form-select:focus,
#addBranchModal input:focus,
#addBranchModal select:focus,
[id^="editBranchModal"] .custom-input:focus,
[id^="editBranchModal"] .form-control:focus,
[id^="editBranchModal"] .form-select:focus,
[id^="editBranchModal"] input:focus,
[id^="editBranchModal"] select:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#addBranchModal .text-muted,
#addBranchModal small,
[id^="editBranchModal"] .text-muted,
[id^="editBranchModal"] small{
    color:#dbe0f4 !important;
    font-weight:700 !important;
}

#addBranchModal small.text-muted,
[id^="editBranchModal"] small.text-muted{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.28) !important;
    border-radius:12px !important;
    padding:10px 12px !important;
    display:block !important;
}

#addBranchModal .cancel-btn,
[id^="editBranchModal"] .cancel-btn{
    height:44px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#addBranchModal .cancel-btn:hover,
[id^="editBranchModal"] .cancel-btn:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#addBranchModal .save-btn,
[id^="editBranchModal"] .save-btn{
    height:44px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#addBranchModal .save-btn:hover,
[id^="editBranchModal"] .save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px) !important;
}

@media (max-width:768px){
    #addBranchModal .modal-dialog,
    [id^="editBranchModal"] .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #addBranchModal .modal-title,
    [id^="editBranchModal"] .modal-title{
        font-size:18px !important;
    }

    #addBranchModal .btn-close,
    [id^="editBranchModal"] .btn-close{
        width:42px !important;
        height:42px !important;
    }

    #addBranchModal .modal-footer,
    [id^="editBranchModal"] .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #addBranchModal .cancel-btn,
    #addBranchModal .save-btn,
    [id^="editBranchModal"] .cancel-btn,
    [id^="editBranchModal"] .save-btn{
        width:100% !important;
    }
}


/* =====================================================
   BRANCHES.PHP DASHBOARD DARK PURPLE GLASS THEME
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

.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.header-btn{
    min-height:46px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:0 16px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
    display:inline-flex !important;
    align-items:center !important;
    gap:8px !important;
    text-decoration:none !important;
    box-shadow:0 14px 28px rgba(0,0,0,.24) !important;
    transition:.22s ease !important;
}

.header-btn:hover{
    color:#fff !important;
    background:rgba(109,76,255,.18) !important;
    border-color:rgba(139,92,246,.42) !important;
    transform:translateY(-1px);
}

/* Stats cards */
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
}

.card-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.card-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.card-red{background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.card-yellow{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

/* Glass panels */
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
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
}

.filter-bar:hover,
.action-toolbar:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-bar{
    padding:14px !important;
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    align-items:center !important;
    margin-bottom:14px !important;
}

.action-toolbar{
    padding:14px !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    margin-bottom:16px !important;
}

.search-wrap input,
.filter-select,
.bulk-select,
.rows-select{
    width:100% !important;
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

.search-wrap input{
    padding:0 14px 0 40px !important;
}

.search-wrap i{
    color:#94a3b8 !important;
}

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.search-wrap input:focus,
.filter-select:focus,
.bulk-select:focus,
.rows-select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-select option,
.bulk-select option,
.rows-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Buttons */
.icon-btn,
.mini-btn,
.action-btn{
    width:46px !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:16px !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.icon-btn:hover,
.mini-btn:hover,
.action-btn:hover{
    color:#fff !important;
    background:rgba(109,76,255,.18) !important;
    border-color:rgba(139,92,246,.42) !important;
    transform:translateY(-1px);
}

.export-btn,
.add-btn{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    transition:.22s ease !important;
}

.export-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.add-btn{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
}

.export-btn:hover,
.add-btn:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* Table dark glass */
.table-card{
    overflow:hidden !important;
}

.branches-table{
    width:100% !important;
    min-width:1450px !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
}

.branches-table thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:14px 12px !important;
    white-space:nowrap;
}

.branches-table tbody td{
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

.branches-table tbody tr:hover td{
    background:rgba(124,58,237,.12) !important;
    color:#fff !important;
}

.branch-main{
    font-weight:900 !important;
    color:#f8fafc !important;
}

.branch-sub,
.pagination-mini{
    color:#94a3b8 !important;
}

.manager-avatar{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 10px 22px rgba(109,76,255,.24);
}

/* Badges */
.users-badge,
.mode-badge{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
}

.stock-in,
.branch-status-active{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.stock-low{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.branch-status-inactive{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* Dropdown dark */
.dropdown-menu{
    background:#0f172a !important;
    border:1px solid rgba(148,163,184,.22) !important;
    border-radius:14px !important;
    box-shadow:0 24px 60px rgba(0,0,0,.45) !important;
    padding:8px !important;
}

.dropdown-item{
    border-radius:10px !important;
    padding:10px 12px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
}

.dropdown-item:hover{
    background:rgba(109,76,255,.18) !important;
    color:#fff !important;
}

.bottom-actions{
    padding:14px !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    background:rgba(7,11,20,.28) !important;
}

/* Alert dark */
.alert{
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

.table-check{
    accent-color:#7c3aed;
}
/* =====================================================
   FINAL FIX: BRANCH POPUP SAME AS WHOLESALE POPUP
   Compact Dark Purple Glass Theme
===================================================== */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

/* Popup width small like wholesale */
#addBranchModal .modal-dialog,
[id^="editBranchModal"] .modal-dialog{
    max-width:min(680px,92vw) !important;
}

/* Main popup glass purple theme */
#addBranchModal .modal-content,
#addBranchModal .custom-modal,
[id^="editBranchModal"] .modal-content,
[id^="editBranchModal"] .custom-modal{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

/* Header compact */
#addBranchModal .modal-header,
[id^="editBranchModal"] .modal-header{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

/* Title */
#addBranchModal .modal-title,
[id^="editBranchModal"] .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

/* Purple dot instead of gold */
#addBranchModal .modal-title::before,
[id^="editBranchModal"] .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

/* Close button compact */
#addBranchModal .btn-close,
[id^="editBranchModal"] .btn-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#addBranchModal .btn-close::before,
#addBranchModal .btn-close::after,
[id^="editBranchModal"] .btn-close::before,
[id^="editBranchModal"] .btn-close::after{
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

#addBranchModal .btn-close::before,
[id^="editBranchModal"] .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#addBranchModal .btn-close::after,
[id^="editBranchModal"] .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#addBranchModal .btn-close:hover,
[id^="editBranchModal"] .btn-close:hover{
    background:#ef4444 !important;
}

/* Body compact + scroll */
#addBranchModal .modal-body,
[id^="editBranchModal"] .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

/* Small gap between fields */
#addBranchModal .row.g-3,
[id^="editBranchModal"] .row.g-3{
    --bs-gutter-x:12px !important;
    --bs-gutter-y:10px !important;
}

/* Labels compact */
#addBranchModal .form-label,
#addBranchModal label,
[id^="editBranchModal"] .form-label,
[id^="editBranchModal"] label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

/* Inputs dark like wholesale popup */
#addBranchModal .custom-input,
#addBranchModal .form-control,
#addBranchModal .form-select,
#addBranchModal input[type="text"],
#addBranchModal select,
[id^="editBranchModal"] .custom-input,
[id^="editBranchModal"] .form-control,
[id^="editBranchModal"] .form-select,
[id^="editBranchModal"] input[type="text"],
[id^="editBranchModal"] select{
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

/* Multiple staff select small */
#addBranchModal select[multiple],
[id^="editBranchModal"] select[multiple]{
    height:auto !important;
    min-height:110px !important;
    padding:8px 12px !important;
}

#addBranchModal .custom-input:focus,
#addBranchModal .form-control:focus,
#addBranchModal .form-select:focus,
#addBranchModal input:focus,
#addBranchModal select:focus,
[id^="editBranchModal"] .custom-input:focus,
[id^="editBranchModal"] .form-control:focus,
[id^="editBranchModal"] .form-select:focus,
[id^="editBranchModal"] input:focus,
[id^="editBranchModal"] select:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#addBranchModal .form-select option,
[id^="editBranchModal"] .form-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

#addBranchModal input::placeholder,
[id^="editBranchModal"] input::placeholder{
    color:#94a3b8 !important;
}

/* Small note box */
#addBranchModal small.text-muted,
[id^="editBranchModal"] small.text-muted{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:10px !important;
    padding:8px 10px !important;
    display:block !important;
    color:#94a3b8 !important;
    font-size:11px !important;
    font-weight:700 !important;
}

/* Footer compact */
#addBranchModal .modal-footer,
[id^="editBranchModal"] .modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

/* Buttons same as wholesale */
#addBranchModal .cancel-btn,
[id^="editBranchModal"] .cancel-btn{
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

#addBranchModal .save-btn,
[id^="editBranchModal"] .save-btn{
    min-height:38px !important;
    height:38px !important;
    min-width:115px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#addBranchModal .cancel-btn:hover,
#addBranchModal .save-btn:hover,
[id^="editBranchModal"] .cancel-btn:hover,
[id^="editBranchModal"] .save-btn:hover{
    transform:translateY(-1px);
}

/* Mobile */
@media (max-width:768px){
    #addBranchModal .modal-dialog,
    [id^="editBranchModal"] .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #addBranchModal .modal-footer,
    [id^="editBranchModal"] .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #addBranchModal .cancel-btn,
    #addBranchModal .save-btn,
    [id^="editBranchModal"] .cancel-btn,
    [id^="editBranchModal"] .save-btn{
        width:100% !important;
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
                <h4 class="page-title">Branches</h4>
                <button class="header-btn" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                    <i class="bi bi-diagram-3"></i>
                    Manage Branches
                </button>
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-building"></i></div>
                        <div class="title">Total Branches</div>
                    </div>
                    <div class="count"><?= $totalBranches ?></div>
                </div>

                <div class="stat-card card-green">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-check-circle"></i></div>
                        <div class="title">Active Branches</div>
                    </div>
                    <div class="count"><?= $activeBranches ?></div>
                </div>

                <div class="stat-card card-red">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-x-circle"></i></div>
                        <div class="title">Inactive Branches</div>
                    </div>
                    <div class="count"><?= $inactiveBranches ?></div>
                </div>

                <div class="stat-card card-yellow">
                    <div class="stat-left">
                        <div class="icon"><i class="bi bi-people"></i></div>
                        <div class="title">Assigned Users</div>
                    </div>
                    <div class="count"><?= $totalAssignedUsers ?></div>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <form method="GET" class="filter-bar">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        name="search"
                        placeholder="Search branch..."
                        value="<?= htmlspecialchars($search) ?>"
                    >
                </div>

                <div class="select-wrap">
                    <select class="filter-select">
                        <option>All Cities</option>
                    </select>
                </div>

                <div class="select-wrap">
                    <select class="filter-select">
                        <option>All Statuses</option>
                    </select>
                </div>

                <div class="select-wrap">
                    <select class="filter-select">
                        <option>All Managers</option>
                    </select>
                </div>

                <button class="icon-btn" type="button" title="List View">
                    <i class="bi bi-list-ul"></i>
                </button>

                <button class="icon-btn" type="button" title="Columns">
                    <i class="bi bi-layout-three-columns"></i>
                </button>

                <button class="icon-btn" type="submit" title="Search">
                    <i class="bi bi-funnel"></i>
                </button>

                <button class="export-btn" type="button">
                    Export <i class="bi bi-chevron-down ms-2"></i>
                </button>
            </form>

            
              <div class="action-toolbar">
    <div class="action-toolbar-left">
        <button type="button" class="add-btn" data-bs-toggle="modal" data-bs-target="#addBranchModal">
            <i class="bi bi-plus-lg"></i>
            Add Branch
        </button>
    </div>
</div>
        

            <div class="table-card">
    <div class="table-responsive">
        <table class="branches-table">
            <thead>
                <tr>
                    <th style="width:50px;">
                        <input type="checkbox" id="checkAllBranches" class="table-check">
                    </th>
                    <th>ID</th>
                    <th>Branch Name</th>
                    <th>City</th>
                    <th>Manager</th>
                    <th>Contact</th>
                    <th>Assigned Users</th>
                    <th>Inventory Status</th>
                    <th>POS Mode</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($branches) > 0): ?>
                <?php foreach ($branches as $b): ?>
                    <?php
                        $assignedUsers = isset($branch_users_map[$b['id']]) ? $branch_users_map[$b['id']] : [];
                        $managerName   = !empty($assignedUsers) ? $assignedUsers[0] : 'Not Assigned';
                        $usersCount    = count($assignedUsers);

                        $branchCode = 'BR-' . str_pad($b['id'], 3, '0', STR_PAD_LEFT);

                        // fallback placeholders
                        $city             = '-';
                        $contact          = '-';
                        $inventoryStatus  = 'In Stock';
                        $posMode          = 'Retail';
                        $branchStatus     = ($usersCount > 0) ? 'Active' : 'Inactive';
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="branch-row-check table-check" value="<?= (int)$b['id'] ?>">
                        </td>

                        <td><?= (int)$b['id'] ?></td>

                        <td>
                            <div class="branch-cell">
                                <span class="branch-main"><?= htmlspecialchars($b['branch_name']) ?></span>
                                <span class="branch-sub"><?= htmlspecialchars($branchCode) ?></span>
                            </div>
                        </td>

                        <td><?= htmlspecialchars($city) ?></td>

                        <td>
                            <div class="manager-cell">
                                <div class="manager-avatar">
                                    <?= strtoupper(substr($managerName, 0, 1)) ?>
                                </div>
                                <span><?= htmlspecialchars($managerName) ?></span>
                            </div>
                        </td>

                        <td><?= htmlspecialchars($contact) ?></td>

                        <td>
                            <span class="users-badge">
                                <i class="bi bi-people"></i>
                                <?= $usersCount > 0 ? $usersCount . ' User(s)' : 'No Users' ?>
                            </span>
                        </td>

                        <td>
                            <span class="stock-badge stock-in">
                                <span class="dot"></span>
                                <?= htmlspecialchars($inventoryStatus) ?>
                            </span>
                        </td>

                        <td>
                            <span class="mode-badge">
                                <?= htmlspecialchars($posMode) ?>
                            </span>
                        </td>

                        <td>
                            <span class="branch-status-badge <?= $branchStatus === 'Active' ? 'branch-status-active' : 'branch-status-inactive' ?>">
                                <?= htmlspecialchars($branchStatus) ?>
                            </span>
                        </td>

                        <td>
                            <div class="dropdown">
                                <button class="action-btn" data-bs-toggle="dropdown" type="button">
                                    <i class="bi bi-three-dots"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#editBranchModal<?= $b['id'] ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="?delete_branch=<?= $b['id'] ?>" onclick="return confirm('Are you sure you want to delete this branch?')">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>

                    <div class="modal fade" id="editBranchModal<?= $b['id'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <form method="post" class="modal-content custom-modal">
                                <input type="hidden" name="edit_branch_id" value="<?= $b['id'] ?>">

                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Branch</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>

                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Branch Name</label>
                                            <input name="branch_name" class="form-control custom-input" value="<?= htmlspecialchars($b['branch_name']) ?>" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Address</label>
                                            <input name="address" class="form-control custom-input" value="<?= htmlspecialchars($b['address']) ?>" required>
                                        </div>

                                        <?php if ($logged_role_id == 1): ?>
                                            <div class="col-12">
                                                <label class="form-label">Client</label>
                                                <select name="client_id" class="form-select custom-input" required>
                                                    <?php foreach ($clients as $c): ?>
                                                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $b['client_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($c['business_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php else: ?>
                                            <div class="col-12">
                                                <label class="form-label">Assign Staff Users</label>
                                                <select name="user_ids[]" class="form-select custom-input" multiple style="height:auto; min-height:160px;">
                                                    <?php foreach ($client_users as $u): ?>
                                                        <option value="<?= $u['id'] ?>" <?= isset($branch_users_map[$b['id']]) && in_array($u['name'], $branch_users_map[$b['id']]) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($u['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Hold Ctrl (Cmd on Mac) to select multiple</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn cancel-btn" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn save-btn">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11" class="text-center py-4">No branches found</td>
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
            <span>Showing <?= count($branches) ?> branches</span>
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

<div class="modal fade" id="addBranchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="post" class="modal-content custom-modal">
            <input type="hidden" name="add_branch" value="1">

            <div class="modal-header">
                <h5 class="modal-title">Add Branch<?= ($logged_role_id != 1) ? " & Assign Staff" : "" ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Branch Name</label>
                        <input name="branch_name" class="form-control custom-input" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Address</label>
                        <input name="address" class="form-control custom-input" required>
                    </div>

                    <?php if ($logged_role_id == 1): ?>
                        <div class="col-12">
                            <label class="form-label">Client</label>
                            <select name="client_id" class="form-select custom-input" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['business_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="client_id" value="<?= $logged_client_id ?>">

                        <div class="col-12">
                            <small class="text-muted d-block mb-2">
                                Your business: <?= htmlspecialchars($_SESSION['business_name'] ?? '') ?>
                            </small>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Assign Staff Users</label>
                            <select name="user_ids[]" class="form-select custom-input" multiple style="height:auto; min-height:160px;">
                                <?php foreach ($client_users as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Hold Ctrl (Cmd on Mac) to select multiple</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn save-btn">Save Branch</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const checkAllBranches = document.getElementById('checkAllBranches');
const branchRowChecks = document.querySelectorAll('.branch-row-check');

if (checkAllBranches) {
    checkAllBranches.addEventListener('change', function () {
        branchRowChecks.forEach(cb => cb.checked = this.checked);
    });
}
</script>
</body>
</html>