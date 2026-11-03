<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)$_SESSION['client_id'];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_to($url) {
    header("Location: " . $url);
    exit;
}

function plan_theme($name, $previewColor = 'green') {
    $nameLower = strtolower(trim((string)$name));

    if (strpos($nameLower, 'silver') !== false) {
        return [
            'badge' => 'SILVER',
            'topbar' => 'linear-gradient(135deg,#dfe7f3,#aab6ca)',
            'button' => 'linear-gradient(135deg,#4e89e8,#2d63cc)',
            'check' => '#9aa7bc',
            'preview' => '#5b88d8'
        ];
    }

    if (strpos($nameLower, 'gold') !== false) {
        return [
            'badge' => 'GOLD',
            'topbar' => 'linear-gradient(135deg,#f3d698,#c89224)',
            'button' => 'linear-gradient(135deg,#c8962e,#a77617)',
            'check' => '#c8962e',
            'preview' => '#d69b2d'
        ];
    }

    if (strpos($nameLower, 'platinum') !== false) {
        return [
            'badge' => 'PLATINUM',
            'topbar' => 'linear-gradient(135deg,#edf0f7,#98a7c2)',
            'button' => 'linear-gradient(135deg,#6d7fb0,#4d5f8f)',
            'check' => '#8f9cb7',
            'preview' => '#7d86ad'
        ];
    }

    $previewMap = [
        'green' => '#5ca55a',
        'blue' => '#4c87df',
        'purple' => '#7a67d9',
        'gold' => '#d29a2f'
    ];

    return [
        'badge' => strtoupper(substr($name ?: 'PLAN', 0, 12)),
        'topbar' => 'linear-gradient(135deg,#dff0e0,#79c48e)',
        'button' => 'linear-gradient(135deg,#34b765,#1f944e)',
        'check' => '#3aa760',
        'preview' => $previewMap[$previewColor] ?? '#5ca55a'
    ];
}

function months_to_days($monthsText) {
    $monthsText = trim($monthsText);
    if (preg_match('/(\d+)/', $monthsText, $m)) {
        return ((int)$m[1]) * 30;
    }
    return 30;
}

function days_to_month_label($days) {
    $days = (int)$days;
    if ($days <= 30) return '1 Month';
    if ($days <= 90) return round($days / 30) . ' Months';
    if ($days <= 365) return round($days / 30) . ' Months';
    return round($days / 30) . ' Months';
}

function fetch_membership_benefits($conn, $membershipId) {
    $benefits = [];
    $stmt = $conn->prepare("SELECT benefit_text FROM membership_benefits WHERE membership_id = ? ORDER BY sort_order ASC, id ASC");
    if ($stmt) {
        $stmt->bind_param("i", $membershipId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $benefits[] = $row['benefit_text'];
        }
        $stmt->close();
    }
    return $benefits;
}

function delete_membership_benefits($conn, $membershipId) {
    $stmt = $conn->prepare("DELETE FROM membership_benefits WHERE membership_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $membershipId);
        $stmt->execute();
        $stmt->close();
    }
}

function insert_membership_benefits($conn, $membershipId, $benefits) {
    $stmt = $conn->prepare("INSERT INTO membership_benefits (membership_id, benefit_text, sort_order) VALUES (?, ?, ?)");
    if (!$stmt) return;

    $sort = 0;
    foreach ($benefits as $benefit) {
        $benefit = trim((string)$benefit);
        if ($benefit === '') continue;
        $stmt->bind_param("isi", $membershipId, $benefit, $sort);
        $stmt->execute();
        $sort++;
    }
    $stmt->close();
}

/* =========================
   DELETE
========================= */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    delete_membership_benefits($conn, $id);

    $stmt = $conn->prepare("DELETE FROM memberships WHERE id = ? AND client_id = ?");
    $stmt->bind_param("ii", $id, $client_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['success'] = "Membership deleted successfully!";
    redirect_to("membership.php");
}

/* =========================
   SAVE / UPDATE / RENEW / UPGRADE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'save';
    $membership_id = (int)($_POST['membership_id'] ?? 0);

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $duration_days = (int)($_POST['duration_days'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $is_free = isset($_POST['is_free']) ? 1 : 0;
    $discount_type = trim($_POST['discount_type'] ?? 'percent');
    $discount_value = (float)($_POST['discount_value'] ?? 0);
    $points_per_order = (int)($_POST['points_per_order'] ?? 0);
    $preview_color = trim($_POST['preview_color'] ?? 'green');
    $benefits = $_POST['benefits'] ?? [];

    if ($mode === 'renew') {
        $renew_days = (int)($_POST['renew_days'] ?? 0);

        if ($membership_id <= 0 || $renew_days <= 0) {
            $_SESSION['error'] = "Please select membership and valid renewal duration.";
            redirect_to("membership.php");
        }

        $stmt = $conn->prepare("UPDATE memberships SET duration_days = duration_days + ? WHERE id = ? AND client_id = ?");
        $stmt->bind_param("iii", $renew_days, $membership_id, $client_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = "Membership renewed successfully!";
        redirect_to("membership.php");
    }

    if ($name === '') {
        $_SESSION['error'] = "Plan name is required.";
        redirect_to($membership_id > 0 ? "membership.php?action=create&edit_id=" . $membership_id : "membership.php?action=create");
    }

    if ($duration_days <= 0) {
        $_SESSION['error'] = "Duration is required.";
        redirect_to($membership_id > 0 ? "membership.php?action=create&edit_id=" . $membership_id : "membership.php?action=create");
    }

    if (!$is_free && $price < 0) {
        $_SESSION['error'] = "Price is invalid.";
        redirect_to($membership_id > 0 ? "membership.php?action=create&edit_id=" . $membership_id : "membership.php?action=create");
    }

    if ($is_free) {
        $price = 0;
    }

    if ($membership_id > 0) {
        $stmt = $conn->prepare("
            UPDATE memberships
            SET name = ?, description = ?, discount_type = ?, discount_value = ?, points_per_order = ?, duration_days = ?, price = ?, is_free = ?, preview_color = ?
            WHERE id = ? AND client_id = ?
        ");
        $stmt->bind_param(
            "sssdiidisii",
            $name,
            $description,
            $discount_type,
            $discount_value,
            $points_per_order,
            $duration_days,
            $price,
            $is_free,
            $preview_color,
            $membership_id,
            $client_id
        );
        $stmt->execute();
        $stmt->close();

        delete_membership_benefits($conn, $membership_id);
        insert_membership_benefits($conn, $membership_id, $benefits);

        $_SESSION['success'] = $mode === 'upgrade'
            ? "Membership upgraded successfully!"
            : "Membership updated successfully!";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO memberships
            (client_id, name, description, discount_type, discount_value, points_per_order, duration_days, price, is_free, preview_color)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "isssdiidis",
            $client_id,
            $name,
            $description,
            $discount_type,
            $discount_value,
            $points_per_order,
            $duration_days,
            $price,
            $is_free,
            $preview_color
        );
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        insert_membership_benefits($conn, $newId, $benefits);

        $_SESSION['success'] = "Membership created successfully!";
    }

    redirect_to("membership.php");
}

/* =========================
   FETCH ALL MEMBERSHIPS
========================= */
$memberships = [];
$result = $conn->query("SELECT * FROM memberships WHERE client_id = {$client_id} ORDER BY id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['benefits'] = fetch_membership_benefits($conn, (int)$row['id']);
        $memberships[] = $row;
    }
}

/* =========================
   TOP DISPLAY CARDS
========================= */
$silver = null;
$gold = null;
$platinum = null;

foreach ($memberships as $m) {
    $n = strtolower(trim($m['name']));
    if (strpos($n, 'silver') !== false && !$silver) {
        $silver = $m;
    } elseif (strpos($n, 'gold') !== false && !$gold) {
        $gold = $m;
    } elseif (strpos($n, 'platinum') !== false && !$platinum) {
        $platinum = $m;
    }
}

if (!$silver) {
    $silver = [
        'id' => 0,
        'name' => 'Silver Membership',
        'description' => 'Basic benefits and rewards.',
        'discount_type' => 'percent',
        'discount_value' => 5,
        'points_per_order' => 10,
        'duration_days' => 30,
        'price' => 999,
        'is_free' => 0,
        'preview_color' => 'blue',
        'benefits' => ['Basic Benefits', 'Access to Discounts', 'Monthly Newsletter', 'Points Program']
    ];
}
if (!$gold) {
    $gold = [
        'id' => 0,
        'name' => 'Gold Membership',
        'description' => 'Premium benefits and rewards.',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'points_per_order' => 20,
        'duration_days' => 60,
        'price' => 1999,
        'is_free' => 0,
        'preview_color' => 'gold',
        'benefits' => ['All Silver Benefits', 'Additional Discounts', 'Early Access Sales', 'Priority Support']
    ];
}
if (!$platinum) {
    $platinum = [
        'id' => 0,
        'name' => 'Platinum Membership',
        'description' => 'Exclusive benefits and rewards.',
        'discount_type' => 'percent',
        'discount_value' => 15,
        'points_per_order' => 35,
        'duration_days' => 90,
        'price' => 2999,
        'is_free' => 0,
        'preview_color' => 'purple',
        'benefits' => ['All Gold Benefits', 'VIP Event Invitations', 'Personalized Offers', 'Dedicated Account Manager']
    ];
}

$topCards = [$silver, $gold, $platinum];
$totalPlans = count($memberships);

/* =========================
   CREATE / EDIT FORM STATE
========================= */
$action = $_GET['action'] ?? 'list';
$edit_id = (int)($_GET['edit_id'] ?? 0);

$formData = [
    'id' => 0,
    'name' => '',
    'description' => '',
    'duration_days' => 360,
    'price' => 99.99,
    'is_free' => 0,
    'discount_type' => 'percent',
    'discount_value' => 0,
    'points_per_order' => 0,
    'preview_color' => 'green',
    'benefits' => ['Benefit 1', 'Benefit 2', 'Benefit 3']
];

if ($edit_id > 0) {
    foreach ($memberships as $m) {
        if ((int)$m['id'] === $edit_id) {
            $formData = $m;
            if (empty($formData['benefits'])) {
                $formData['benefits'] = ['Benefit 1', 'Benefit 2', 'Benefit 3'];
            }
            $action = 'create';
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Membership Plans</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            margin:0;
            background:#eeeeee;
            font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
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
    
#dashboardSidebar .sidebar-inner{
    min-height:100%;
}


        .main-panel{
            flex-grow:1;
            display:flex;
            flex-direction:column;
            min-width:0;
        }
        .topbar{
            height:60px;
            border-bottom:1px solid #dee2e6;
            display:flex;
            align-items:center;
            padding:0 20px;
            background:#fff;
        }
        .content-wrapper{
            padding:14px;
            flex-grow:1;
            overflow-y:auto;
            background:#efedf8;
        }
        .page-shell{
            background:#efedf8;
            border:1px solid #e5e3ee;
            padding:12px;
            min-height:calc(100vh - 88px);
        }
        .page-title{
            font-size:22px;
            font-weight:700;
            color:#2e3140;
            margin-bottom:6px;
        }
        .page-subtitle{
            color:#7b7f8c;
            font-size:14px;
            margin-bottom:16px;
        }
        .top-membership-grid{
            display:grid;
            grid-template-columns:2fr 1fr;
            gap:14px;
            margin-bottom:14px;
        }
        .plans-holder{
            background:#f3f1fb;
            border:1px solid #e0deea;
            border-radius:4px;
            padding:8px;
            box-shadow:0 1px 3px rgba(0,0,0,.04);
        }
        .plans-grid{
            display:grid;
            grid-template-columns:repeat(3, 1fr);
            gap:10px;
        }
        .plan-card{
            background:#fafafe;
            border:1px solid #d8d8e3;
            border-radius:6px;
            padding:9px 10px 12px;
            position:relative;
            min-height:182px;
            box-shadow:0 2px 6px rgba(0,0,0,.06);
        }
        .plan-top-bar{
            height:30px;
            border-radius:4px;
            position:relative;
            margin-bottom:12px;
            overflow:hidden;
        }
        .plan-top-bar::before{
            content:"★ ★";
            position:absolute;
            left:9px;
            top:5px;
            font-size:11px;
            color:rgba(255,255,255,.75);
            letter-spacing:2px;
        }
        .plan-badge{
            position:absolute;
            top:8px;
            left:50%;
            transform:translateX(-50%);
            background:rgba(255,255,255,.8);
            color:#585c69;
            font-size:11px;
            font-weight:700;
            letter-spacing:.4px;
            padding:3px 14px;
            border-radius:5px;
            box-shadow:0 1px 3px rgba(0,0,0,.12);
        }
        .plan-title{
            font-size:15px;
            font-weight:700;
            color:#3b3e49;
            margin-bottom:3px;
        }
        .plan-subtitle{
            font-size:12px;
            color:#7a7d8b;
            margin-bottom:8px;
        }
        .feature-list{
            list-style:none;
            margin:0;
            padding:0;
        }
        .feature-list li{
            display:flex;
            gap:6px;
            align-items:center;
            font-size:12px;
            color:#6d7080;
            margin-bottom:4px;
            line-height:1.35;
        }
        .feature-list i{
            font-size:11px;
        }
        .plan-btn{
            margin-top:10px;
            border:none;
            border-radius:7px;
            padding:7px 14px;
            color:#fff;
            font-size:12px;
            font-weight:700;
            min-width:120px;
            box-shadow:0 3px 8px rgba(0,0,0,.14);
        }
        .create-plan-box{
            background:#fafafd;
            border:1px solid #d8d8e3;
            border-radius:6px;
            box-shadow:0 2px 6px rgba(0,0,0,.06);
            padding:24px 20px;
            min-height:202px;
            display:flex;
            flex-direction:column;
            justify-content:center;
        }
        .create-top{
            display:flex;
            align-items:center;
            gap:14px;
            margin-bottom:8px;
        }
        .create-icon{
            width:60px;
            height:60px;
            border-radius:50%;
            background:#2faa5d;
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:31px;
            box-shadow:0 2px 8px rgba(0,0,0,.12);
        }
        .create-title{
            font-size:17px;
            font-weight:700;
            color:#2d3140;
            margin:0;
        }
        .create-line{
            border-top:1px solid #e4e4e4;
            margin:10px 0 14px;
        }
        .create-text{
            font-size:13px;
            color:#646878;
            margin-bottom:18px;
        }
        .green-action-btn{
            border:none;
            border-radius:10px;
            padding:11px 18px;
            min-width:190px;
            color:#fff;
            font-size:14px;
            font-weight:700;
            background:linear-gradient(135deg,#38bc68,#21954e);
            box-shadow:0 3px 8px rgba(0,0,0,.14);
        }
        .action-grid{
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:14px;
            margin-bottom:14px;
        }
        .action-card{
            background:#fbfbfd;
            border:1px solid #d9d9e3;
            border-radius:6px;
            box-shadow:0 2px 6px rgba(0,0,0,.06);
            min-height:205px;
            padding:24px 20px 16px;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
        }
        .action-head{
            display:flex;
            gap:14px;
            align-items:flex-start;
        }
        .action-circle{
            width:58px;
            height:58px;
            min-width:58px;
            border-radius:50%;
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:28px;
            box-shadow:0 2px 6px rgba(0,0,0,.12);
        }
        .circle-blue{ background:#4a90e2; }
        .circle-orange{ background:#ef8f34; }
        .circle-purple{ background:#6a64d8; }
        .action-title{
            font-size:17px;
            font-weight:700;
            line-height:1.2;
            color:#2e313f;
            margin-top:7px;
        }
        .action-desc{
            font-size:13px;
            color:#676b79;
            margin:18px 0 18px;
            line-height:1.45;
        }
        .blue-action-btn{
            border:none;
            border-radius:10px;
            padding:10px 16px;
            min-width:168px;
            color:#fff;
            font-size:14px;
            font-weight:700;
            background:linear-gradient(135deg,#4d86e8,#2b5ad5);
            box-shadow:0 3px 8px rgba(0,0,0,.14);
        }
        .table-box{
            background:#fff;
            border:1px solid #ddd;
            border-radius:4px;
            min-height:230px;
            overflow:hidden;
            box-shadow:0 1px 4px rgba(0,0,0,.05);
        }
        .table-box-head{
            padding:14px 16px;
            border-bottom:1px solid #ececec;
            background:#fbfbfb;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }
        .table-box-head h5{
            margin:0;
            font-size:16px;
            font-weight:700;
            color:#2f3341;
        }
        .table-modern{
            margin:0;
        }
        .table-modern thead th{
            background:#f5f5f8;
            font-size:13px;
            font-weight:700;
            color:#464b57;
            border-bottom:1px solid #ddd;
            padding:12px 14px;
            white-space:nowrap;
        }
        .table-modern tbody td{
            padding:12px 14px;
            font-size:13px;
            color:#31343f;
            vertical-align:middle;
        }
        .table-actions{
    display:flex;
    align-items:center;
    gap:8px;
}

.action-btn{
    width:38px;
    height:38px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    border:none;
    cursor:pointer;
    transition:all .2s ease;
    box-shadow:0 4px 10px rgba(0,0,0,.06);
    text-decoration:none;
}

.action-btn i{
    font-size:14px;
}

/* EDIT */
.edit-btn{
    background:#fff4d6;
    color:#e0a100;
}
.edit-btn:hover{
    background:#e0a100;
    color:#fff;
}

/* RENEW */
.renew-btn{
    background:#e0edff;
    color:#3b82f6;
}
.renew-btn:hover{
    background:#3b82f6;
    color:#fff;
}

/* UPGRADE */
.upgrade-btn{
    background:#e6f9f0;
    color:#10b981;
}
.upgrade-btn:hover{
    background:#10b981;
    color:#fff;
}

/* DELETE */
.delete-btn{
    background:#ffe3e3;
    color:#ef4444;
}
.delete-btn:hover{
    background:#ef4444;
    color:#fff;
}
        .creator-wrap{
            display:grid;
            grid-template-columns:1.65fr .95fr;
            gap:20px;
            align-items:start;
        }
        .creator-card,
        .preview-card{
            background:#fff;
            border:1px solid #dcdce7;
            border-radius:8px;
            box-shadow:0 2px 8px rgba(0,0,0,.05);
            overflow:hidden;
        }
        .preview-card{
            min-height:520px;
        }
        .section-head{
            padding:14px 18px;
            border-bottom:1px solid #ececf2;
            font-size:16px;
            font-weight:700;
            color:#303446;
            background:#fcfcfd;
        }
        .creator-card-body{
            padding:18px;
        }
        .form-label{
            font-weight:600;
            color:#313544;
            margin-bottom:8px;
        }
        .form-control,
        .form-select{
            min-height:42px;
            border:1px solid #d9dbe6;
            box-shadow:none;
        }
        textarea.form-control{
            min-height:96px;
            resize:none;
        }
        .two-col{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }
        .price-input-group{
            display:flex;
            align-items:center;
            border:1px solid #d9dbe6;
            border-radius:6px;
            overflow:hidden;
            min-height:42px;
        }
        .price-symbol{
            width:48px;
            text-align:center;
            background:#f7f7fb;
            border-right:1px solid #d9dbe6;
            color:#505564;
            font-weight:700;
            line-height:42px;
        }
        .price-input{
            border:none;
            outline:none;
            width:100%;
            height:42px;
            padding:0 14px;
        }
        .free-check{
            display:flex;
            align-items:center;
            gap:10px;
            margin:14px 0 8px;
            font-size:14px;
            color:#4b5060;
        }
        .benefit-title{
            font-size:15px;
            font-weight:700;
            margin:16px 0 10px;
            color:#32374a;
        }
        .benefit-item{
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom:10px;
        }
        .benefit-icon{
            width:34px;
            height:34px;
            min-width:34px;
            border:1px solid #d8dbe6;
            border-radius:6px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#f7f8fc;
            color:#8a90a1;
        }
        .benefit-input{
            flex:1;
        }
        .add-benefit-btn{
            border:none;
            border-radius:10px;
            padding:10px 16px;
            color:#fff;
            font-weight:700;
            background:linear-gradient(135deg,#4d86e8,#2b5ad5);
            box-shadow:0 3px 8px rgba(0,0,0,.14);
        }
        .save-wrap{
            border-top:1px solid #ececf2;
            margin-top:18px;
            padding-top:18px;
            text-align:center;
        }
        .save-plan-btn{
            border:none;
            border-radius:12px;
            min-width:290px;
            padding:12px 20px;
            color:#fff;
            font-size:16px;
            font-weight:700;
            background:linear-gradient(135deg,#4d86e8,#2b5ad5);
            box-shadow:0 3px 8px rgba(0,0,0,.14);
        }
        .preview-shell{
            padding:18px;
        }
        .preview-inner{
            border:1px solid #d9dfcf;
            border-radius:8px;
            overflow:hidden;
            background:#fff;
            max-width:370px;
            margin:14px auto 0;
            box-shadow:0 2px 6px rgba(0,0,0,.06);
        }
        .preview-banner{
            min-height:116px;
            color:#fff;
            padding:26px 24px 18px;
            position:relative;
            background-size:cover;
            background-position:center;
        }
        .preview-banner::before{
            content:"";
            position:absolute;
            inset:0;
            background:
                radial-gradient(circle at 10% 10%, rgba(255,255,255,.35) 2px, transparent 3px),
                radial-gradient(circle at 25% 22%, rgba(255,255,255,.26) 3px, transparent 4px),
                radial-gradient(circle at 45% 15%, rgba(255,255,255,.28) 2px, transparent 3px),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,.28) 2px, transparent 3px),
                radial-gradient(circle at 70% 10%, rgba(255,255,255,.22) 4px, transparent 5px),
                linear-gradient(135deg, rgba(255,255,255,.12), rgba(255,255,255,0));
            pointer-events:none;
        }
        .preview-banner.green{
            background:linear-gradient(135deg,#4b9a48,#84c36e);
        }
        .preview-banner.blue{
            background:linear-gradient(135deg,#3e73cb,#7eb1f0);
        }
        .preview-banner.purple{
            background:linear-gradient(135deg,#6b59ca,#998ce8);
        }
        .preview-banner.gold{
            background:linear-gradient(135deg,#bf8730,#f0c56b);
        }
        .preview-banner h3{
            position:relative;
            z-index:1;
            font-size:22px;
            font-weight:500;
            margin:0;
        }
        .preview-body{
            padding:16px 22px 20px;
        }
        .preview-benefits{
            margin:0 0 18px;
            padding-left:22px;
            color:#4b4f5b;
            min-height:100px;
        }
        .preview-benefits li{
            margin-bottom:6px;
            font-size:14px;
        }
        .preview-price{
            text-align:center;
            font-size:20px;
            font-weight:800;
            color:#252934;
            margin-bottom:18px;
        }
        .preview-join{
            display:block;
            width:220px;
            max-width:100%;
            margin:0 auto;
            border:none;
            border-radius:12px;
            padding:12px 20px;
            color:#fff;
            font-size:18px;
            font-weight:700;
            background:linear-gradient(135deg,#5da85b,#3b8f46);
        }
        .alert{
            border-radius:8px;
        }

        @media (max-width: 1200px){
            .top-membership-grid,
            .creator-wrap{
                grid-template-columns:1fr;
            }
            .plans-grid,
            .action-grid{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 768px){
            .two-col{
                grid-template-columns:1fr;
            }
            .save-plan-btn{
                min-width:100%;
            }
        }
        
        
        /* =========================================================
   DARK PURPLE GLASS THEME OVERRIDE
   Paste this code just BEFORE 
========================================================= */

:root{
    --dark-bg:#070b1a;
    --panel-bg:#11152d;
    --glass-bg:rgba(22, 26, 54, .78);
    --glass-bg-2:rgba(28, 32, 68, .72);
    --glass-border:rgba(159, 130, 255, .22);
    --glass-border-2:rgba(255,255,255,.08);
    --text-main:#ffffff;
    --text-soft:#c9c7e8;
    --text-muted:#9b98bd;
    --purple:#7c3aed;
    --purple-2:#a855f7;
    --blue:#3b82f6;
    --green:#10b981;
    --orange:#f59e0b;
    --red:#ef4444;
}

body{
    background:
        radial-gradient(circle at 15% 10%, rgba(124,58,237,.28), transparent 35%),
        radial-gradient(circle at 85% 15%, rgba(14,165,233,.16), transparent 38%),
        linear-gradient(135deg,#070b1a 0%,#0c1025 48%,#11162e 100%) !important;
    color:var(--text-main) !important;
}

.container-scroll{
    background:transparent !important;
}

.main-panel{
    background:transparent !important;
}

.topbar{
    height:64px !important;
    background:rgba(5, 9, 22, .92) !important;
    border-bottom:1px solid rgba(139,92,246,.22) !important;
    box-shadow:0 12px 35px rgba(0,0,0,.24) !important;
    backdrop-filter:blur(16px);
}

.content-wrapper{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 92% 12%, rgba(14,165,233,.14), transparent 30%),
        linear-gradient(135deg,#0b0f25 0%,#10142c 52%,#121731 100%) !important;
    padding:26px !important;
}

.page-shell{
    background:transparent !important;
    border:0 !important;
    padding:0 !important;
    min-height:calc(100vh - 100px) !important;
}

.page-title{
    color:#ffffff !important;
    font-size:30px !important;
    font-weight:900 !important;
    margin-bottom:8px !important;
    letter-spacing:-.5px;
    text-shadow:0 5px 18px rgba(0,0,0,.35);
}

.page-subtitle{
    color:var(--text-soft) !important;
    font-size:14px !important;
    margin-bottom:22px !important;
}

/* Top Layout */
.top-membership-grid{
    grid-template-columns:2fr 1fr !important;
    gap:16px !important;
    margin-bottom:16px !important;
}

.plans-holder,
.create-plan-box,
.action-card,
.table-box,
.creator-card,
.preview-card{
    background:linear-gradient(180deg, rgba(25,30,65,.82), rgba(17,21,45,.78)) !important;
    border:1px solid var(--glass-border) !important;
    border-radius:18px !important;
    box-shadow:
        0 22px 55px rgba(0,0,0,.28),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px);
}

.plans-holder{
    padding:14px !important;
}

.plans-grid{
    gap:14px !important;
}

/* Plan Cards */
.plan-card{
    background:
        linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.035)) !important;
    border:1px solid rgba(255,255,255,.10) !important;
    border-radius:16px !important;
    padding:13px 14px 16px !important;
    min-height:215px !important;
    box-shadow:0 14px 35px rgba(0,0,0,.24) !important;
    overflow:hidden;
    transition:all .25s ease;
}

.plan-card:hover{
    transform:translateY(-3px);
    border-color:rgba(168,85,247,.45) !important;
    box-shadow:0 18px 45px rgba(124,58,237,.20) !important;
}

.plan-top-bar{
    height:34px !important;
    border-radius:12px !important;
    margin-bottom:16px !important;
    background:linear-gradient(135deg,#6d28d9,#a855f7,#f59e0b) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.25);
}

.plan-top-bar::before{
    color:rgba(255,255,255,.85) !important;
}

.plan-badge{
    top:12px !important;
    background:rgba(255,255,255,.92) !important;
    color:#15172f !important;
    border-radius:999px !important;
    padding:4px 18px !important;
    font-size:10px !important;
    box-shadow:0 8px 20px rgba(0,0,0,.25) !important;
}

.plan-title{
    color:#ffffff !important;
    font-size:16px !important;
    font-weight:800 !important;
}

.plan-subtitle{
    color:var(--text-muted) !important;
    font-size:12px !important;
    margin-bottom:12px !important;
}

.feature-list li{
    color:var(--text-soft) !important;
    font-size:12px !important;
    margin-bottom:6px !important;
}

.feature-list i{
    color:#93c5fd !important;
}

.plan-btn,
.blue-action-btn,
.green-action-btn,
.add-benefit-btn,
.save-plan-btn,
.preview-join{
    border:0 !important;
    border-radius:12px !important;
    color:#fff !important;
    font-weight:800 !important;
    background:linear-gradient(135deg,#7c3aed,#a855f7) !important;
    box-shadow:0 12px 28px rgba(124,58,237,.32) !important;
    transition:all .22s ease;
}

.plan-btn:hover,
.blue-action-btn:hover,
.green-action-btn:hover,
.add-benefit-btn:hover,
.save-plan-btn:hover,
.preview-join:hover{
    transform:translateY(-2px);
    box-shadow:0 16px 35px rgba(168,85,247,.40) !important;
}

/* Create Plan Box */
.create-plan-box{
    min-height:255px !important;
    padding:30px 26px !important;
}

.create-icon,
.action-circle{
    box-shadow:0 16px 35px rgba(0,0,0,.28) !important;
}

.create-icon{
    background:linear-gradient(135deg,#10b981,#22c55e) !important;
}

.create-title,
.action-title{
    color:#ffffff !important;
    font-weight:900 !important;
}

.create-line{
    border-top:1px solid rgba(255,255,255,.10) !important;
}

.create-text,
.action-desc{
    color:var(--text-soft) !important;
}

/* Action Cards */
.action-grid{
    gap:16px !important;
    margin-bottom:16px !important;
}

.action-card{
    min-height:205px !important;
    padding:26px 22px 18px !important;
}

.circle-blue{
    background:linear-gradient(135deg,#2563eb,#60a5fa) !important;
}

.circle-orange{
    background:linear-gradient(135deg,#f97316,#fbbf24) !important;
}

.circle-purple{
    background:linear-gradient(135deg,#7c3aed,#c084fc) !important;
}

/* Table */
.table-box{
    overflow:hidden !important;
}

.table-box-head{
    background:rgba(15,19,42,.86) !important;
    border-bottom:1px solid rgba(255,255,255,.10) !important;
    padding:18px 20px !important;
}

.table-box-head h5{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
}

.table-box-head .badge{
    background:#050914 !important;
    border:1px solid rgba(255,255,255,.12);
    color:#ffffff !important;
    padding:7px 12px;
    border-radius:8px;
}

.table-modern{
    background:transparent !important;
    color:#fff !important;
}

.table-modern thead th{
    background:#070b19 !important;
    color:#dcd9ff !important;
    border-bottom:1px solid rgba(255,255,255,.08) !important;
    padding:15px 16px !important;
    font-size:12px !important;
    letter-spacing:.4px;
    text-transform:uppercase;
}

.table-modern tbody tr{
    background:rgba(18,22,48,.75) !important;
    border-bottom:1px solid rgba(255,255,255,.06) !important;
}

.table-modern tbody tr:hover{
    background:rgba(124,58,237,.13) !important;
}

.table-modern tbody td{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.06) !important;
    padding:15px 16px !important;
}

.table-responsive{
    background:transparent !important;
}

/* Action Buttons In Table */
.action-btn{
    background:rgba(255,255,255,.08) !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 10px 22px rgba(0,0,0,.25) !important;
}

.edit-btn{
    color:#fbbf24 !important;
}

.renew-btn{
    color:#60a5fa !important;
}

.upgrade-btn{
    color:#34d399 !important;
}

.delete-btn{
    color:#fb7185 !important;
}

.edit-btn:hover{
    background:#f59e0b !important;
    color:#fff !important;
}

.renew-btn:hover{
    background:#2563eb !important;
    color:#fff !important;
}

.upgrade-btn:hover{
    background:#10b981 !important;
    color:#fff !important;
}

.delete-btn:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

/* Create/Edit Form Page */
.creator-wrap{
    gap:20px !important;
}

.creator-card,
.preview-card{
    overflow:hidden !important;
}

.creator-card-body,
.preview-shell{
    padding:22px !important;
}

.section-head{
    background:rgba(9,13,30,.90) !important;
    border-bottom:1px solid rgba(255,255,255,.10) !important;
    color:#ffffff !important;
    padding:18px 22px !important;
    font-size:18px !important;
    font-weight:900 !important;
}

.form-label,
.benefit-title,
.free-check{
    color:#f4f2ff !important;
}

.form-control,
.form-select,
.price-input,
textarea.form-control{
    background:rgba(8,12,30,.88) !important;
    border:1px solid rgba(168,85,247,.24) !important;
    color:#ffffff !important;
    border-radius:12px !important;
    box-shadow:none !important;
}

.form-control::placeholder,
.price-input::placeholder,
textarea.form-control::placeholder{
    color:#8f8bb5 !important;
}

.form-control:focus,
.form-select:focus,
.price-input:focus,
textarea.form-control:focus{
    border-color:#a855f7 !important;
    box-shadow:0 0 0 3px rgba(168,85,247,.18) !important;
}

.form-select option{
    background:#11152d !important;
    color:#ffffff !important;
}

.price-input-group{
    background:rgba(8,12,30,.88) !important;
    border:1px solid rgba(168,85,247,.24) !important;
    border-radius:12px !important;
}

.price-symbol{
    background:rgba(124,58,237,.18) !important;
    border-right:1px solid rgba(168,85,247,.24) !important;
    color:#ffffff !important;
}

.benefit-icon{
    background:rgba(124,58,237,.15) !important;
    border:1px solid rgba(168,85,247,.25) !important;
    color:#c4b5fd !important;
    border-radius:10px !important;
}

.save-wrap{
    border-top:1px solid rgba(255,255,255,.10) !important;
}

/* Preview Card */
.preview-inner{
    background:rgba(11,15,35,.88) !important;
    border:1px solid rgba(168,85,247,.24) !important;
    border-radius:18px !important;
    box-shadow:0 18px 42px rgba(0,0,0,.30) !important;
}

.preview-banner.green,
.preview-banner.blue,
.preview-banner.purple,
.preview-banner.gold{
    background:linear-gradient(135deg,#6d28d9,#a855f7,#f59e0b) !important;
}

.preview-banner h3{
    color:#ffffff !important;
    font-weight:900 !important;
}

.preview-body{
    background:rgba(9,13,31,.94) !important;
}

.preview-benefits{
    color:#ddd8ff !important;
}

.preview-price{
    color:#ffffff !important;
}

/* Modals */
.modal-content{
    background:linear-gradient(180deg, rgba(25,30,65,.96), rgba(13,17,38,.96)) !important;
    border:1px solid rgba(168,85,247,.28) !important;
    border-radius:18px !important;
    color:#ffffff !important;
    box-shadow:0 30px 80px rgba(0,0,0,.50) !important;
}

.modal-header{
    background:#080c1f !important;
    border-bottom:1px solid rgba(255,255,255,.10) !important;
}

.modal-footer{
    border-top:1px solid rgba(255,255,255,.10) !important;
}

.modal-title{
    color:#ffffff !important;
    font-weight:900 !important;
}

.btn-dark{
    background:#0b1028 !important;
    border-color:rgba(255,255,255,.12) !important;
}

.btn-warning{
    background:linear-gradient(135deg,#7c3aed,#a855f7) !important;
    border:0 !important;
    color:#ffffff !important;
    font-weight:800 !important;
}

/* Alerts */
.alert{
    border-radius:14px !important;
    border:1px solid rgba(255,255,255,.12) !important;
    box-shadow:0 14px 30px rgba(0,0,0,.25) !important;
}

.alert-success{
    background:rgba(16,185,129,.16) !important;
    color:#bbf7d0 !important;
}

.alert-danger{
    background:rgba(239,68,68,.16) !important;
    color:#fecaca !important;
}

/* Scrollbar */
.content-wrapper::-webkit-scrollbar,
.table-responsive::-webkit-scrollbar{
    width:8px;
    height:8px;
}

.content-wrapper::-webkit-scrollbar-thumb,
.table-responsive::-webkit-scrollbar-thumb{
    background:rgba(168,85,247,.55);
    border-radius:999px;
}

.content-wrapper::-webkit-scrollbar-track,
.table-responsive::-webkit-scrollbar-track{
    background:rgba(255,255,255,.04);
}

/* Responsive */
@media (max-width:1200px){
    .top-membership-grid,
    .creator-wrap{
        grid-template-columns:1fr !important;
    }

    .plans-grid,
    .action-grid{
        grid-template-columns:1fr !important;
    }
}

@media (max-width:768px){
    .content-wrapper{
        padding:14px !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .two-col{
        grid-template-columns:1fr !important;
    }

    .save-plan-btn{
        min-width:100% !important;
    }
}



/* =========================================================
   FINAL FIX: TABLE WHITE HOVER REMOVE + PLAN NAME COLORS
   Paste this at the VERY END before 
========================================================= */

/* TABLE WHITE BACKGROUND / HOVER FIX */
.table-box,
.table-responsive,
.table-modern{
    background:transparent !important;
}

.table-box .table,
.table-box .table-modern{
    --bs-table-bg:transparent !important;
    --bs-table-color:#ffffff !important;
    --bs-table-border-color:rgba(255,255,255,.07) !important;
    --bs-table-hover-bg:rgba(124,58,237,.16) !important;
    --bs-table-hover-color:#ffffff !important;
    --bs-table-striped-bg:transparent !important;
    --bs-table-accent-bg:transparent !important;
}

.table-modern > :not(caption) > * > *{
    background-color:transparent !important;
    color:#ffffff !important;
    box-shadow:none !important;
    border-color:rgba(255,255,255,.07) !important;
}

.table-modern tbody tr{
    background:rgba(18,22,48,.78) !important;
}

.table-modern tbody tr td{
    background:rgba(18,22,48,.78) !important;
    color:#ffffff !important;
}

.table-modern tbody tr:hover,
.table-modern tbody tr:hover td{
    background:rgba(124,58,237,.18) !important;
    color:#ffffff !important;
    box-shadow:none !important;
}

.table-modern tbody tr:hover .action-btn{
    background:rgba(255,255,255,.10) !important;
}

/* EMPTY / WHITE SPACE FIX INSIDE TABLE */
.table-box tbody,
.table-box tbody tr,
.table-box tbody td{
    background-color:rgba(18,22,48,.78) !important;
}

/* =========================================================
   SILVER / GOLD / PLATINUM CARD COLORS
   Cards order: 1 Silver, 2 Gold, 3 Platinum
========================================================= */

/* SILVER CARD */
.plans-grid .plan-card:nth-child(1){
    border-color:rgba(226,232,240,.35) !important;
    box-shadow:
        0 16px 40px rgba(148,163,184,.16),
        inset 0 1px 0 rgba(255,255,255,.08) !important;
}

.plans-grid .plan-card:nth-child(1) .plan-top-bar{
    background:linear-gradient(135deg,#f8fafc,#cbd5e1,#64748b) !important;
}

.plans-grid .plan-card:nth-child(1) .plan-badge{
    background:linear-gradient(135deg,#ffffff,#dbe4ef) !important;
    color:#334155 !important;
}

.plans-grid .plan-card:nth-child(1) .plan-btn{
    background:linear-gradient(135deg,#cbd5e1,#64748b) !important;
    box-shadow:0 12px 28px rgba(148,163,184,.25) !important;
}

/* GOLD CARD */
.plans-grid .plan-card:nth-child(2){
    border-color:rgba(245,158,11,.40) !important;
    box-shadow:
        0 16px 40px rgba(245,158,11,.18),
        inset 0 1px 0 rgba(255,255,255,.08) !important;
}

.plans-grid .plan-card:nth-child(2) .plan-top-bar{
    background:linear-gradient(135deg,#fff7cc,#facc15,#d97706) !important;
}

.plans-grid .plan-card:nth-child(2) .plan-badge{
    background:linear-gradient(135deg,#fff7cc,#facc15) !important;
    color:#5c3700 !important;
}

.plans-grid .plan-card:nth-child(2) .plan-btn{
    background:linear-gradient(135deg,#facc15,#d97706) !important;
    box-shadow:0 12px 28px rgba(245,158,11,.30) !important;
}

/* PLATINUM CARD */
.plans-grid .plan-card:nth-child(3){
    border-color:rgba(203,213,225,.45) !important;
    box-shadow:
        0 16px 40px rgba(148,163,184,.20),
        inset 0 1px 0 rgba(255,255,255,.08) !important;
}

.plans-grid .plan-card:nth-child(3) .plan-top-bar{
    background:linear-gradient(135deg,#f8fafc,#c4b5fd,#94a3b8) !important;
}

.plans-grid .plan-card:nth-child(3) .plan-badge{
    background:linear-gradient(135deg,#ffffff,#c4b5fd) !important;
    color:#312e81 !important;
}

.plans-grid .plan-card:nth-child(3) .plan-btn{
    background:linear-gradient(135deg,#c4b5fd,#64748b) !important;
    box-shadow:0 12px 28px rgba(196,181,253,.28) !important;
}

/* CARD HOVER COLORS NAME WISE */
.plans-grid .plan-card:nth-child(1):hover{
    border-color:rgba(226,232,240,.65) !important;
}

.plans-grid .plan-card:nth-child(2):hover{
    border-color:rgba(250,204,21,.75) !important;
}

.plans-grid .plan-card:nth-child(3):hover{
    border-color:rgba(196,181,253,.75) !important;
}



/* =========================================================
   MEMBERSHIP PAGE - FINAL LIGHT MODE
   Is code ko sab se last
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

html[data-pos-theme="light"] body .container-scroll,
html[data-pos-theme="light"] body .main-panel{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 82% 4%,
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

html[data-pos-theme="light"] body .page-shell{
    background:transparent !important;
    color:#0f172a !important;
}

/* =========================================================
   PAGE HEADINGS
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body .page-subtitle{
    color:#64748b !important;
}

/* =========================================================
   MAIN WHITE CARDS
========================================================= */

html[data-pos-theme="light"] body .plans-holder,
html[data-pos-theme="light"] body .create-plan-box,
html[data-pos-theme="light"] body .action-card,
html[data-pos-theme="light"] body .table-box,
html[data-pos-theme="light"] body .creator-card,
html[data-pos-theme="light"] body .preview-card{
    background:#ffffff !important;
    background-image:none !important;
    color:#0f172a !important;

    border:1px solid #dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .plans-holder:hover,
html[data-pos-theme="light"] body .create-plan-box:hover,
html[data-pos-theme="light"] body .action-card:hover,
html[data-pos-theme="light"] body .table-box:hover,
html[data-pos-theme="light"] body .creator-card:hover,
html[data-pos-theme="light"] body .preview-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:0 16px 36px rgba(15,23,42,.09) !important;
}

/* =========================================================
   SILVER / GOLD / PLATINUM CARDS
========================================================= */

html[data-pos-theme="light"] body .plan-card{
    background:#ffffff !important;
    background-image:none !important;
    color:#0f172a !important;

    border:1px solid #dce4ef !important;
    box-shadow:0 8px 22px rgba(15,23,42,.07) !important;
}

html[data-pos-theme="light"] body .plan-card:hover{
    transform:translateY(-3px) !important;
    box-shadow:0 14px 30px rgba(15,23,42,.11) !important;
}

/* Silver card */
html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(1){
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(1) .plan-top-bar{
    background:linear-gradient(
        135deg,
        #f8fafc,
        #cbd5e1,
        #94a3b8
    ) !important;
}

html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(1) .plan-btn{
    background:linear-gradient(
        135deg,
        #94a3b8,
        #64748b
    ) !important;
    color:#ffffff !important;
}

/* Gold card */
html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(2){
    border-color:#fbbf24 !important;
}

html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(2) .plan-top-bar{
    background:linear-gradient(
        135deg,
        #fef3c7,
        #facc15,
        #d97706
    ) !important;
}

html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(2) .plan-btn{
    background:linear-gradient(
        135deg,
        #facc15,
        #d97706
    ) !important;
    color:#ffffff !important;
}

/* Platinum card */
html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(3){
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(3) .plan-top-bar{
    background:linear-gradient(
        135deg,
        #f8fafc,
        #ddd6fe,
        #94a3b8
    ) !important;
}

html[data-pos-theme="light"] body
.plans-grid .plan-card:nth-child(3) .plan-btn{
    background:linear-gradient(
        135deg,
        #a78bfa,
        #64748b
    ) !important;
    color:#ffffff !important;
}

/* Card text */
html[data-pos-theme="light"] body .plan-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .plan-subtitle{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .feature-list li{
    color:#475569 !important;
}

html[data-pos-theme="light"] body .feature-list i{
    color:#2563eb !important;
}

html[data-pos-theme="light"] body .plan-badge{
    color:#334155 !important;
}

/* =========================================================
   CREATE NEW PLAN BOX
========================================================= */

html[data-pos-theme="light"] body .create-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .create-text{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .create-line{
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .create-icon{
    background:linear-gradient(
        135deg,
        #10b981,
        #22c55e
    ) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .green-action-btn{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
}

/* =========================================================
   ADD / RENEW / UPGRADE CARDS
========================================================= */

html[data-pos-theme="light"] body .action-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .action-desc{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .blue-action-btn{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .circle-blue{
    background:linear-gradient(
        135deg,
        #2563eb,
        #60a5fa
    ) !important;
}

html[data-pos-theme="light"] body .circle-orange{
    background:linear-gradient(
        135deg,
        #f97316,
        #f59e0b
    ) !important;
}

html[data-pos-theme="light"] body .circle-purple{
    background:linear-gradient(
        135deg,
        #7c3aed,
        #a855f7
    ) !important;
}

/* =========================================================
   MEMBERSHIP TABLE
========================================================= */

html[data-pos-theme="light"] body .table-box{
    background:#ffffff !important;
}

html[data-pos-theme="light"] body .table-box-head{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .table-box-head h5{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .table-box-head .badge{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
}

/* Bootstrap table variables reset */
html[data-pos-theme="light"] body .table-modern,
html[data-pos-theme="light"] body .table-modern thead,
html[data-pos-theme="light"] body .table-modern tbody,
html[data-pos-theme="light"] body .table-modern tr,
html[data-pos-theme="light"] body .table-modern td,
html[data-pos-theme="light"] body .table-modern th{
    --bs-table-bg:transparent !important;
    --bs-table-color:#1e293b !important;
    --bs-table-border-color:#e2e8f0 !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
    --bs-table-accent-bg:transparent !important;
}

/* Table headings */
html[data-pos-theme="light"] body .table-modern thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom:1px solid #cbd5e1 !important;
}

/* Normal table rows */
html[data-pos-theme="light"] body .table-modern tbody,
html[data-pos-theme="light"] body .table-modern tbody tr,
html[data-pos-theme="light"] body .table-modern tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    color:#1e293b !important;
    border-color:#e8edf4 !important;
    box-shadow:none !important;
}

/* Hover */
html[data-pos-theme="light"] body .table-modern tbody tr:hover,
html[data-pos-theme="light"] body .table-modern tbody tr:hover td{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    color:#0f172a !important;
    box-shadow:none !important;
}

/* Empty table area */
html[data-pos-theme="light"] body .table-box > div[style*="min-height"]{
    background:#ffffff !important;
}

/* Table action buttons */
html[data-pos-theme="light"] body .action-btn{
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}

html[data-pos-theme="light"] body .edit-btn{
    background:#fffbeb !important;
    color:#d97706 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] body .renew-btn{
    background:#eff6ff !important;
    color:#2563eb !important;
    border-color:#bfdbfe !important;
}

html[data-pos-theme="light"] body .upgrade-btn{
    background:#ecfdf5 !important;
    color:#059669 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .delete-btn{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

/* =========================================================
   CREATE / EDIT MEMBERSHIP FORM
========================================================= */

html[data-pos-theme="light"] body .section-head{
    background:#f8fafc !important;
    color:#0f172a !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .creator-card-body,
html[data-pos-theme="light"] body .preview-shell{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .form-label,
html[data-pos-theme="light"] body .benefit-title,
html[data-pos-theme="light"] body .free-check{
    color:#334155 !important;
}

/* Inputs */
html[data-pos-theme="light"] body .form-control,
html[data-pos-theme="light"] body .form-select,
html[data-pos-theme="light"] body .price-input,
html[data-pos-theme="light"] body textarea.form-control{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .form-control::placeholder,
html[data-pos-theme="light"] body .price-input::placeholder,
html[data-pos-theme="light"] body textarea.form-control::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .form-control:focus,
html[data-pos-theme="light"] body .form-select:focus,
html[data-pos-theme="light"] body .price-input:focus,
html[data-pos-theme="light"] body textarea.form-control:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .form-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Price input */
html[data-pos-theme="light"] body .price-input-group{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body .price-symbol{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-right-color:#c4b5fd !important;
}

/* Benefit rows */
html[data-pos-theme="light"] body .benefit-icon{
    background:#f3efff !important;
    color:#7c3aed !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body .add-benefit-btn,
html[data-pos-theme="light"] body .save-plan-btn{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .save-wrap{
    border-top-color:#e2e8f0 !important;
}

/* =========================================================
   MEMBERSHIP PREVIEW
========================================================= */

html[data-pos-theme="light"] body .preview-inner{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 10px 24px rgba(15,23,42,.08) !important;
}

html[data-pos-theme="light"] body .preview-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .preview-benefits{
    color:#475569 !important;
}

html[data-pos-theme="light"] body .preview-price{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .preview-join{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
}

/* Preview colors ko preserve karein */
html[data-pos-theme="light"] body .preview-banner.green{
    background:linear-gradient(
        135deg,
        #16a34a,
        #4ade80
    ) !important;
}

html[data-pos-theme="light"] body .preview-banner.blue{
    background:linear-gradient(
        135deg,
        #2563eb,
        #60a5fa
    ) !important;
}

html[data-pos-theme="light"] body .preview-banner.purple{
    background:linear-gradient(
        135deg,
        #7c3aed,
        #c084fc
    ) !important;
}

html[data-pos-theme="light"] body .preview-banner.gold{
    background:linear-gradient(
        135deg,
        #d97706,
        #fbbf24
    ) !important;
}

/* =========================================================
   RENEW / UPGRADE MODALS
========================================================= */

html[data-pos-theme="light"] body .modal-content{
    background:#ffffff !important;
    background-image:none !important;
    color:#0f172a !important;

    border:1px solid #d8e0eb !important;
    box-shadow:0 28px 80px rgba(15,23,42,.20) !important;
}

html[data-pos-theme="light"] body .modal-header{
    background:#f8fafc !important;
    color:#0f172a !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .modal-header.bg-dark{
    background:#f8fafc !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .modal-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .modal-footer{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

/* Modal Cancel */
html[data-pos-theme="light"] body .modal-footer .btn-dark{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

/* Modal action */
html[data-pos-theme="light"] body .modal-footer .btn-warning{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    border:none !important;
}

/* Modal close button */
html[data-pos-theme="light"] body .modal-header .btn-close{
    filter:none !important;
    opacity:1 !important;
}

/* =========================================================
   ALERTS
========================================================= */

html[data-pos-theme="light"] body .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

/* =========================================================
   SCROLLBARS
========================================================= */

html[data-pos-theme="light"] body
.content-wrapper::-webkit-scrollbar-track,

html[data-pos-theme="light"] body
.table-responsive::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"] body
.content-wrapper::-webkit-scrollbar-thumb,

html[data-pos-theme="light"] body
.table-responsive::-webkit-scrollbar-thumb{
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
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= e($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= e($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="page-shell">

                <?php if ($action === 'create'): ?>
                    <div class="page-title">Create New Membership Plan</div>
                    <div class="page-subtitle">
                        Design and configure a custom membership plan with benefits and more.
                    </div>

                    <div class="creator-wrap">
                        <div class="creator-card">
                            <div class="creator-card-body">
                                <form method="post" id="membershipPlanForm">
                                    <input type="hidden" name="membership_id" value="<?= (int)$formData['id'] ?>">
                                    <input type="hidden" name="mode" value="<?= (int)$formData['id'] > 0 ? 'save' : 'save' ?>">

                                    <div class="mb-4">
                                        <label class="form-label">Plan Name</label>
                                        <input type="text" class="form-control" name="name" id="plan_name" value="<?= e($formData['name']) ?>" placeholder="Enter membership plan name" required>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="description" id="plan_description" placeholder="Describe the benefits of the membership plan."><?= e($formData['description']) ?></textarea>
                                    </div>

                                    <div class="two-col">
                                        <div>
                                            <label class="form-label">Duration</label>
                                            <select class="form-select" id="duration_select">
                                                <option value="30" <?= (int)$formData['duration_days'] === 30 ? 'selected' : '' ?>>1 Month</option>
                                                <option value="90" <?= (int)$formData['duration_days'] === 90 ? 'selected' : '' ?>>3 Months</option>
                                                <option value="180" <?= (int)$formData['duration_days'] === 180 ? 'selected' : '' ?>>6 Months</option>
                                                <option value="360" <?= (int)$formData['duration_days'] === 360 ? 'selected' : '' ?>>12 Months</option>
                                            </select>
                                            <input type="hidden" name="duration_days" id="duration_days" value="<?= (int)$formData['duration_days'] ?>">
                                        </div>

                                        <div>
                                            <label class="form-label">Price</label>
                                            <div class="price-input-group">
                                                <div class="price-symbol">$</div>
                                                <input type="number" step="0.01" class="price-input" name="price" id="plan_price" value="<?= e($formData['price']) ?>" placeholder="Enter price">
                                            </div>
                                        </div>
                                    </div>

                                    <label class="free-check">
                                        <input type="checkbox" name="is_free" id="is_free" <?= !empty($formData['is_free']) ? 'checked' : '' ?>>
                                        <span>Offer free membership</span>
                                    </label>

                                    <div class="two-col mt-3">
                                        <div>
                                            <label class="form-label">Discount Type</label>
                                            <select class="form-select" name="discount_type">
                                                <option value="percent" <?= $formData['discount_type'] === 'percent' ? 'selected' : '' ?>>Percent</option>
                                                <option value="fixed" <?= $formData['discount_type'] === 'fixed' ? 'selected' : '' ?>>Fixed Amount</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="form-label">Discount Value</label>
                                            <input type="number" step="0.01" class="form-control" name="discount_value" value="<?= e($formData['discount_value']) ?>">
                                        </div>
                                    </div>

                                    <div class="two-col mt-3">
                                        <div>
                                            <label class="form-label">Points Per Order</label>
                                            <input type="number" class="form-control" name="points_per_order" value="<?= e($formData['points_per_order']) ?>">
                                        </div>

                                        <div>
                                            <label class="form-label">Preview Theme</label>
                                            <select class="form-select" name="preview_color" id="preview_color">
                                                <option value="green" <?= $formData['preview_color'] === 'green' ? 'selected' : '' ?>>Green</option>
                                                <option value="blue" <?= $formData['preview_color'] === 'blue' ? 'selected' : '' ?>>Blue</option>
                                                <option value="purple" <?= $formData['preview_color'] === 'purple' ? 'selected' : '' ?>>Purple</option>
                                                <option value="gold" <?= $formData['preview_color'] === 'gold' ? 'selected' : '' ?>>Gold</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="benefit-title">Benefits</div>

                                    <div id="benefitsWrapper">
                                        <?php
                                        $benefits = $formData['benefits'];
                                        if (count($benefits) < 3) {
                                            while (count($benefits) < 3) {
                                                $benefits[] = '';
                                            }
                                        }
                                        foreach ($benefits as $benefit):
                                        ?>
                                            <div class="benefit-item">
                                                <div class="benefit-icon"><i class="bi bi-dash-circle-fill"></i></div>
                                                <input type="text" class="form-control benefit-input benefit-field" name="benefits[]" value="<?= e($benefit) ?>" placeholder="Benefit">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <button type="button" class="add-benefit-btn" id="addBenefitBtn">
                                        <i class="bi bi-plus-lg"></i> Add Benefit
                                    </button>

                                    <div class="save-wrap">
                                        <button type="submit" class="save-plan-btn">
                                            <?= (int)$formData['id'] > 0 ? 'Update Membership Plan' : 'Save Membership Plan' ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="preview-card">
                            <div class="section-head">Preview</div>

                            <div class="preview-shell">
                                <div class="preview-inner">
                                    <div class="preview-banner <?= e($formData['preview_color']) ?>" id="previewBanner">
                                        <h3 id="previewPlanTitle"><?= e($formData['name'] ?: 'Membership Plan') ?></h3>
                                    </div>

                                    <div class="preview-body">
                                        <ul class="preview-benefits" id="previewBenefits">
                                            <?php foreach ($benefits as $benefit): ?>
                                                <?php if (trim($benefit) !== ''): ?>
                                                    <li><?= e($benefit) ?></li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </ul>

                                        <div class="preview-price" id="previewPrice">
                                            <?php if (!empty($formData['is_free'])): ?>
                                                Free
                                            <?php else: ?>
                                                $<?= number_format((float)$formData['price'], 2) ?>/<?= strtolower(days_to_month_label((int)$formData['duration_days'])) ?>
                                            <?php endif; ?>
                                        </div>

                                        <button type="button" class="preview-join">Join Now</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="top-membership-grid">
                        <div class="plans-holder">
                            <div class="plans-grid">
                                <?php foreach ($topCards as $plan): ?>
                                    <?php $theme = plan_theme($plan['name'], $plan['preview_color'] ?? 'green'); ?>
                                    <div class="plan-card">
                                        <div class="plan-top-bar" style="background:<?= e($theme['topbar']) ?>;"></div>
                                        <div class="plan-badge"><?= e($theme['badge']) ?></div>

                                        <div class="plan-title"><?= e($plan['name']) ?></div>
                                        <div class="plan-subtitle"><?= e($plan['description']) ?></div>

                                        <ul class="feature-list">
                                            <?php
                                            $benefits = !empty($plan['benefits']) ? $plan['benefits'] : ['Basic Benefits', 'Plan Discounts', 'Priority Access', 'Points Program'];
                                            foreach (array_slice($benefits, 0, 4) as $benefit):
                                            ?>
                                                <li><i class="bi bi-check-circle-fill" style="color:<?= e($theme['check']) ?>"></i> <?= e($benefit) ?></li>
                                            <?php endforeach; ?>
                                        </ul>

                                        <button
                                            type="button"
                                            class="plan-btn"
                                            style="background:<?= e($theme['button']) ?>;"
                                            onclick="window.location.href='membership.php?action=create&edit_id=<?= (int)$plan['id'] ?>'"
                                        >
                                            Select <?= e(ucfirst(strtolower($theme['badge']))) ?>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="create-plan-box">
                            <div class="create-top">
                                <div class="create-icon">
                                    <i class="bi bi-person-vcard-fill"></i>
                                </div>
                                <div>
                                    <h4 class="create-title">Create New Plan</h4>
                                </div>
                            </div>

                            <div class="create-line"></div>

                            <div class="create-text">Design a custom membership plan.</div>

                            <div>
                                <button type="button" class="green-action-btn" onclick="window.location.href='membership.php?action=create'">
                                    Create Membership Plan
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="action-grid">
                        <div class="action-card">
                            <div>
                                <div class="action-head">
                                    <div class="action-circle circle-blue">
                                        <i class="bi bi-person-plus-fill"></i>
                                    </div>
                                    <div class="action-title">Add New<br>Membership</div>
                                </div>

                                <div class="action-desc">
                                    Add a new membership for your customer.
                                </div>
                            </div>

                            <div>
                                <button type="button" class="blue-action-btn" onclick="window.location.href='membership.php?action=create'">
                                    Add Membership
                                </button>
                            </div>
                        </div>

                        <div class="action-card">
                            <div>
                                <div class="action-head">
                                    <div class="action-circle circle-orange">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </div>
                                    <div class="action-title">Renew<br>Membership</div>
                                </div>

                                <div class="action-desc">
                                    Renew or extend existing membership.
                                </div>
                            </div>

                            <div>
                                <button type="button" class="blue-action-btn" data-bs-toggle="modal" data-bs-target="#renewModal">
                                    Renew Membership
                                </button>
                            </div>
                        </div>

                        <div class="action-card">
                            <div>
                                <div class="action-head">
                                    <div class="action-circle circle-purple">
                                        <i class="bi bi-magic"></i>
                                    </div>
                                    <div class="action-title">Upgrade<br>Membership</div>
                                </div>

                                <div class="action-desc">
                                    Upgrade customer membership level.
                                </div>
                            </div>

                            <div>
                                <button type="button" class="blue-action-btn" data-bs-toggle="modal" data-bs-target="#upgradeSelectModal">
                                    Upgrade Membership
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-box">
                        <div class="table-box-head">
                            <h5>All Membership Plans</h5>
                            <span class="badge bg-dark">Total <?= (int)$totalPlans ?></span>
                        </div>

                        <?php if (!empty($memberships)): ?>
                            <div class="table-responsive">
                                <table class="table table-modern">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Plan Name</th>
                                            <th>Description</th>
                                            <th>Price</th>
                                            <th>Discount</th>
                                            <th>Points</th>
                                            <th>Duration</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($memberships as $m): ?>
                                            <tr>
                                                <td><?= (int)$m['id'] ?></td>
                                                <td><?= e($m['name']) ?></td>
                                                <td><?= e(mb_strimwidth((string)$m['description'], 0, 40, '...')) ?></td>
                                                <td><?= !empty($m['is_free']) ? 'Free' : '$' . number_format((float)$m['price'], 2) ?></td>
                                                <td><?= $m['discount_type'] === 'percent' ? rtrim(rtrim(number_format((float)$m['discount_value'], 2), '0'), '.') . '%' : '$' . number_format((float)$m['discount_value'], 2) ?></td>
                                                <td><?= (int)$m['points_per_order'] ?></td>
                                                <td><?= days_to_month_label((int)$m['duration_days']) ?></td>
                                                <td><?= e(date('d M Y', strtotime($m['created_at']))) ?></td>
                                            <td>
    <div class="table-actions">

        <!-- EDIT -->
        <a href="membership.php?action=create&edit_id=<?= (int)$m['id'] ?>"
           class="action-btn edit-btn"
           title="Edit">
            <i class="bi bi-pencil-fill"></i>
        </a>

        <!-- RENEW -->
        <button
            class="action-btn renew-btn"
            onclick="openRenew('<?= (int)$m['id'] ?>')"
            title="Renew">
            <i class="bi bi-arrow-repeat"></i>
        </button>

        <!-- UPGRADE -->
        <button
            class="action-btn upgrade-btn"
            onclick="openUpgrade(
                '<?= (int)$m['id'] ?>',
                '<?= e($m['name']) ?>',
                '<?= e($m['description']) ?>',
                '<?= e($m['discount_type']) ?>',
                '<?= e($m['discount_value']) ?>',
                '<?= e($m['points_per_order']) ?>',
                '<?= e($m['duration_days']) ?>',
                '<?= e($m['price']) ?>',
                '<?= e($m['preview_color']) ?>',
                '<?= (int)$m['is_free'] ?>'
            )"
            title="Upgrade">
            <i class="bi bi-stars"></i>
        </button>

        <!-- DELETE -->
        <a href="membership.php?delete=<?= (int)$m['id'] ?>"
           class="action-btn delete-btn"
           onclick="return confirm('Delete this membership?')"
           title="Delete">
            <i class="bi bi-trash-fill"></i>
        </a>

    </div>
</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div style="min-height:220px;"></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<!-- Renew Modal -->
<div class="modal fade" id="renewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="mode" value="renew">
                <input type="hidden" name="membership_id" id="renew_membership_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Renew Membership</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Membership</label>
                        <select class="form-select" id="renew_select" onchange="setRenewMembership(this.value)" required>
                            <option value="">Choose Membership</option>
                            <?php foreach ($memberships as $m): ?>
                                <option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Add Duration Days</label>
                        <input type="number" class="form-control" name="renew_days" value="30" min="1" required>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-dark" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-warning" type="submit">Renew</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upgrade Select Modal -->
<div class="modal fade" id="upgradeSelectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Upgrade Membership</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <label class="form-label">Select Membership</label>
                <select class="form-select" id="upgrade_select" onchange="loadUpgradeBySelect(this)">
                    <option value="">Choose Membership</option>
                    <?php foreach ($memberships as $m): ?>
                        <option
                            value="<?= (int)$m['id'] ?>"
                            data-name="<?= e($m['name']) ?>"
                            data-description="<?= e($m['description']) ?>"
                            data-type="<?= e($m['discount_type']) ?>"
                            data-value="<?= e($m['discount_value']) ?>"
                            data-points="<?= e($m['points_per_order']) ?>"
                            data-duration="<?= e($m['duration_days']) ?>"
                            data-price="<?= e($m['price']) ?>"
                            data-color="<?= e($m['preview_color']) ?>"
                            data-free="<?= (int)$m['is_free'] ?>"
                        >
                            <?= e($m['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Upgrade Modal -->
<div class="modal fade" id="upgradeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="mode" value="upgrade">
                <input type="hidden" name="membership_id" id="upgrade_membership_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Upgrade Membership</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Membership Name</label>
                        <input type="text" class="form-control" name="name" id="upgrade_name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="upgrade_description"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Discount Type</label>
                        <select class="form-select" name="discount_type" id="upgrade_discount_type">
                            <option value="percent">Percent</option>
                            <option value="fixed">Fixed Amount</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Discount Value</label>
                        <input type="number" step="0.01" class="form-control" name="discount_value" id="upgrade_discount_value">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Points Per Order</label>
                        <input type="number" class="form-control" name="points_per_order" id="upgrade_points_per_order">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Duration Days</label>
                        <input type="number" class="form-control" name="duration_days" id="upgrade_duration_days">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Price</label>
                        <input type="number" step="0.01" class="form-control" name="price" id="upgrade_price">
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Preview Theme</label>
                        <select class="form-select" name="preview_color" id="upgrade_preview_color">
                            <option value="green">Green</option>
                            <option value="blue">Blue</option>
                            <option value="purple">Purple</option>
                            <option value="gold">Gold</option>
                        </select>
                    </div>

                    <input type="hidden" name="is_free" id="upgrade_is_free_hidden" value="0">
                </div>

                <div class="modal-footer">
                    <button class="btn btn-dark" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-warning" type="submit">Upgrade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function setRenewMembership(val) {
        document.getElementById('renew_membership_id').value = val;
    }

    function openRenew(id) {
        document.getElementById('renew_membership_id').value = id;
        document.getElementById('renew_select').value = id;
        const modal = new bootstrap.Modal(document.getElementById('renewModal'));
        modal.show();
    }

    function loadUpgradeBySelect(select) {
        const option = select.options[select.selectedIndex];
        if (!option.value) return;

        bootstrap.Modal.getInstance(document.getElementById('upgradeSelectModal')).hide();

        openUpgrade(
            option.value,
            option.dataset.name || '',
            option.dataset.description || '',
            option.dataset.type || 'percent',
            option.dataset.value || '0',
            option.dataset.points || '0',
            option.dataset.duration || '30',
            option.dataset.price || '0',
            option.dataset.color || 'green',
            option.dataset.free || '0'
        );
    }

    function openUpgrade(id, name, description, type, value, points, duration, price, color, isFree) {
        document.getElementById('upgrade_membership_id').value = id;
        document.getElementById('upgrade_name').value = name;
        document.getElementById('upgrade_description').value = description;
        document.getElementById('upgrade_discount_type').value = type;
        document.getElementById('upgrade_discount_value').value = value;
        document.getElementById('upgrade_points_per_order').value = points;
        document.getElementById('upgrade_duration_days').value = duration;
        document.getElementById('upgrade_price').value = price;
        document.getElementById('upgrade_preview_color').value = color;
        document.getElementById('upgrade_is_free_hidden').value = isFree;
        const modal = new bootstrap.Modal(document.getElementById('upgradeModal'));
        modal.show();
    }

    const durationSelect = document.getElementById('duration_select');
    const durationHidden = document.getElementById('duration_days');
    const planName = document.getElementById('plan_name');
    const planPrice = document.getElementById('plan_price');
    const planFree = document.getElementById('is_free');
    const previewColor = document.getElementById('preview_color');
    const previewTitle = document.getElementById('previewPlanTitle');
    const previewPrice = document.getElementById('previewPrice');
    const previewBanner = document.getElementById('previewBanner');
    const benefitsWrapper = document.getElementById('benefitsWrapper');
    const previewBenefits = document.getElementById('previewBenefits');
    const addBenefitBtn = document.getElementById('addBenefitBtn');

    if (durationSelect) {
        durationSelect.addEventListener('change', function() {
            durationHidden.value = this.value;
            updatePreviewPrice();
        });
    }

    if (planName) {
        planName.addEventListener('input', function() {
            previewTitle.textContent = this.value.trim() || 'Membership Plan';
        });
    }

    if (planPrice) {
        planPrice.addEventListener('input', updatePreviewPrice);
    }

    if (planFree) {
        planFree.addEventListener('change', function() {
            planPrice.disabled = this.checked;
            updatePreviewPrice();
        });
        planPrice.disabled = planFree.checked;
    }

    if (previewColor) {
        previewColor.addEventListener('change', function() {
            previewBanner.className = 'preview-banner ' + this.value;
        });
    }

    function updatePreviewPrice() {
        if (!previewPrice) return;
        const free = planFree && planFree.checked;
        const price = parseFloat(planPrice.value || 0).toFixed(2);
        const monthsText = durationSelect.options[durationSelect.selectedIndex].text.toLowerCase();

        if (free) {
            previewPrice.textContent = 'Free';
        } else {
            previewPrice.textContent = '$' + price + '/' + monthsText;
        }
    }

    function rebuildPreviewBenefits() {
        if (!previewBenefits) return;
        const benefitFields = document.querySelectorAll('.benefit-field');
        let html = '';

        benefitFields.forEach(function(field) {
            const value = field.value.trim();
            if (value !== '') {
                html += '<li>' + value.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</li>';
            }
        });

        if (html === '') {
            html = '<li>Benefit 1</li><li>Benefit 2</li><li>Benefit 3</li>';
        }

        previewBenefits.innerHTML = html;
    }

    if (benefitsWrapper) {
        benefitsWrapper.addEventListener('input', function(e) {
            if (e.target.classList.contains('benefit-field')) {
                rebuildPreviewBenefits();
            }
        });
    }

    if (addBenefitBtn) {
        addBenefitBtn.addEventListener('click', function() {
            const div = document.createElement('div');
            div.className = 'benefit-item';
            div.innerHTML = `
                <div class="benefit-icon"><i class="bi bi-dash-circle-fill"></i></div>
                <input type="text" class="form-control benefit-input benefit-field" name="benefits[]" placeholder="Benefit">
            `;
            benefitsWrapper.appendChild(div);
        });
    }

    updatePreviewPrice();
    rebuildPreviewBenefits();
</script>
</body>
</html>