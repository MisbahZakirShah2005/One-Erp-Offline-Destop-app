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

$client_id = (int) $_SESSION['client_id'];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_to($url) {
    header("Location: " . $url);
    exit;
}

function has_column($conn, $table, $column) {
    $sql = "SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return !empty($row['total']);
}

function first_existing_column($conn, $table, $columns, $fallback = null) {
    foreach ($columns as $column) {
        if (has_column($conn, $table, $column)) {
            return $column;
        }
    }
    return $fallback;
}

function membership_theme($name) {
    $name = strtolower(trim((string)$name));
    if (strpos($name, 'silver') !== false) {
        return ['banner' => 'banner-silver', 'button' => 'btn-blue', 'badge' => 'SILVER'];
    }
    if (strpos($name, 'gold') !== false) {
        return ['banner' => 'banner-gold', 'button' => 'btn-gold', 'badge' => 'GOLD'];
    }
    if (strpos($name, 'platinum') !== false) {
        return ['banner' => 'banner-platinum', 'button' => 'btn-purple', 'badge' => 'PLATINUM'];
    }
    return ['banner' => 'banner-green', 'button' => 'btn-green', 'badge' => strtoupper(substr((string)$name, 0, 10))];
}

function membership_description($membership) {
    $name = strtolower(trim((string)($membership['name'] ?? '')));
    if (strpos($name, 'silver') !== false) return 'Basic benefits and rewards.';
    if (strpos($name, 'gold') !== false) return 'Premium benefits and rewards.';
    if (strpos($name, 'platinum') !== false) return 'Exclusive benefits and rewards.';
    return 'Membership benefits and rewards.';
}

function membership_benefits_from_name($name) {
    $name = strtolower(trim((string)$name));
    if (strpos($name, 'silver') !== false) {
        return ['Basic Benefits', 'Access to Discounts', 'Monthly Newsletter'];
    }
    if (strpos($name, 'gold') !== false) {
        return ['Priority Access', 'Extra Discounts', 'Premium Support'];
    }
    if (strpos($name, 'platinum') !== false) {
        return ['VIP Benefits', 'Highest Discounts', 'Dedicated Support'];
    }
    return ['Benefit 1', 'Benefit 2', 'Benefit 3'];
}

function calculate_end_date($startDate, $durationDays) {
    $durationDays = max(1, (int)$durationDays);
    $date = new DateTime($startDate);
    $date->modify('+' . ($durationDays - 1) . ' days');
    return $date->format('Y-m-d');
}

$customerNameCol  = first_existing_column($conn, 'customers', ['name', 'customer_name', 'full_name'], 'name');
$customerPhoneCol = first_existing_column($conn, 'customers', ['phone', 'mobile', 'contact_no', 'phone_number'], null);

/* =========================
   ASSIGN MEMBERSHIP
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id   = (int)($_POST['customer_id'] ?? 0);
    $membership_id = (int)($_POST['membership_id'] ?? 0);
    $start_date    = trim($_POST['start_date'] ?? date('Y-m-d'));

    if ($customer_id <= 0 || $membership_id <= 0 || $start_date === '') {
        $_SESSION['error'] = "Please select customer membership and start date.";
        redirect_to("customer-membership-assign.php");
    }

    $stmt = $conn->prepare("SELECT id, duration_days FROM memberships WHERE id = ? AND client_id = ?");
    $stmt->bind_param("ii", $membership_id, $client_id);
    $stmt->execute();
    $membershipRes = $stmt->get_result();
    $membershipRow = $membershipRes->fetch_assoc();
    $stmt->close();

    if (!$membershipRow) {
        $_SESSION['error'] = "Selected membership not found.";
        redirect_to("customer-membership-assign.php");
    }

    $end_date = calculate_end_date($start_date, (int)$membershipRow['duration_days']);

    $stmt = $conn->prepare("UPDATE customer_memberships 
                            SET status = 'replaced'
                            WHERE client_id = ? AND customer_id = ? AND status = 'active'");
    $stmt->bind_param("ii", $client_id, $customer_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO customer_memberships
        (client_id, customer_id, membership_id, start_date, end_date, status)
        VALUES (?, ?, ?, ?, ?, 'active')
    ");
    $stmt->bind_param("iiiss", $client_id, $customer_id, $membership_id, $start_date, $end_date);
    $stmt->execute();
    $stmt->close();

    $_SESSION['success'] = "Membership assigned successfully!";
    redirect_to("customer-membership-view.php?customer_id=" . $customer_id);
}

/* =========================
   FETCH CUSTOMERS
========================= */
$customers = [];
$customerSql = "SELECT id, `$customerNameCol` AS customer_name" .
    ($customerPhoneCol ? ", `$customerPhoneCol` AS customer_phone" : ", '' AS customer_phone") .
    " FROM customers WHERE client_id = ? ORDER BY `$customerNameCol` ASC";
$stmt = $conn->prepare($customerSql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$customerRes = $stmt->get_result();
while ($row = $customerRes->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

/* =========================
   FETCH MEMBERSHIPS
========================= */
$memberships = [];
$stmt = $conn->prepare("SELECT * FROM memberships WHERE client_id = ? ORDER BY id DESC LIMIT 3");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['description_ui'] = membership_description($row);
    $row['benefits_ui'] = membership_benefits_from_name($row['name']);
    $memberships[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Membership</title>
    <?php include('partials/head.php'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        *{box-sizing:border-box}
        :root{
            --bg-main:#050711;
            --bg-panel:rgba(16,20,42,.72);
            --bg-panel-strong:rgba(11,14,30,.88);
            --border-glass:rgba(152,115,255,.22);
            --border-soft:rgba(255,255,255,.08);
            --text-main:#f7f4ff;
            --text-soft:#aeb7d2;
            --purple:#7c3cff;
            --purple2:#a855f7;
            --green:#0aa35f;
            --gold:#f2b84b;
            --cyan:#21c6df;
            --red:#f05265;
        }

        body{
            margin:0;
            min-height:100vh;
            background:
                radial-gradient(circle at 18% 12%, rgba(124,58,237,.30), transparent 34%),
                radial-gradient(circle at 85% 18%, rgba(33,198,223,.14), transparent 26%),
                radial-gradient(circle at 65% 92%, rgba(168,85,247,.18), transparent 32%),
                linear-gradient(180deg,#060811 0%,#0b0b1c 46%,#060713 100%) !important;
            font-family:Inter,"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
            color:var(--text-main);
        }

        .container-scroll{display:flex;min-height:100vh;align-items:stretch;background:transparent}
        .sidebar{width:250px;flex-shrink:0;display:flex;align-self:stretch;background:transparent;overflow:hidden}
        .sidebar > div{width:100%;min-height:100%;height:100%}
        #dashboardSidebar .sidebar-wrap{min-height:100% !important;height:100% !important;border-radius:0}
        #dashboardSidebar .sidebar-inner{min-height:100% !important}

        .main-panel{flex-grow:1;display:flex;flex-direction:column;min-width:0;background:transparent}
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
        .content-wrapper{
            padding:22px;
            flex-grow:1;
            overflow-y:auto;
            background:
                linear-gradient(90deg, rgba(124,58,237,.12) 0%, transparent 46%),
                transparent !important;
        }
        .page-shell{
            position:relative;
            overflow:hidden;
            background:rgba(14,16,36,.50) !important;
            border:1px solid var(--border-glass) !important;
            border-radius:24px;
            padding:22px;
            min-height:calc(100vh - 108px);
            box-shadow:0 24px 70px rgba(0,0,0,.34), inset 0 1px 0 rgba(255,255,255,.06);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
        }
        .page-shell::before{
            content:'';
            position:absolute;
            inset:0;
            pointer-events:none;
            background:
                radial-gradient(circle at top left, rgba(124,58,237,.22), transparent 34%),
                radial-gradient(circle at bottom right, rgba(21,185,129,.10), transparent 35%);
        }
        .page-shell > *{position:relative;z-index:1}

        .page-title{
            font-size:30px;
            font-weight:900;
            color:#fff !important;
            margin-bottom:8px;
            letter-spacing:-.5px;
            text-shadow:0 8px 28px rgba(124,58,237,.35);
        }
        .page-subtitle{color:var(--text-soft) !important;font-size:14px;margin-bottom:20px;font-weight:600}

        .alert{border-radius:16px;border:1px solid rgba(255,255,255,.10);backdrop-filter:blur(14px)}
        .alert-success{background:rgba(22,163,74,.18);color:#d1fae5;border-color:rgba(22,163,74,.30)}
        .alert-danger{background:rgba(239,68,68,.16);color:#fee2e2;border-color:rgba(239,68,68,.32)}

        .assign-grid{display:grid;grid-template-columns:minmax(320px,.9fr) 1.35fr;gap:18px;align-items:start}
        .panel-box{
            position:relative;
            overflow:hidden;
            background:var(--bg-panel) !important;
            border:1px solid var(--border-glass) !important;
            border-radius:22px !important;
            box-shadow:0 18px 55px rgba(0,0,0,.32), inset 0 1px 0 rgba(255,255,255,.06) !important;
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
            transition:all .22s ease;
        }
        .panel-box:hover{
            transform:translateY(-2px);
            border-color:rgba(168,85,247,.48) !important;
            box-shadow:0 24px 70px rgba(0,0,0,.40),0 0 0 1px rgba(124,58,237,.16),0 0 38px rgba(124,58,237,.18) !important;
        }
        .panel-box::before{
            content:'';
            position:absolute;
            inset:0;
            pointer-events:none;
            background:radial-gradient(circle at 15% 0%, rgba(124,58,237,.20), transparent 36%);
        }
        .panel-head{
            position:relative;
            z-index:1;
            padding:18px 20px;
            border-bottom:1px solid rgba(255,255,255,.08) !important;
            font-size:17px;
            font-weight:900;
            color:#fff !important;
            background:rgba(8,11,25,.56) !important;
            letter-spacing:-.2px;
        }
        .panel-head::before{
            content:'';
            display:inline-block;
            width:10px;height:10px;border-radius:50%;
            margin-right:10px;
            background:linear-gradient(135deg,var(--purple),var(--cyan));
            box-shadow:0 0 0 5px rgba(124,58,237,.13);
            vertical-align:middle;
        }
        .panel-body{position:relative;z-index:1;padding:20px}

        .form-label{font-weight:800;color:#f5f3ff !important;margin-bottom:8px}
        .form-control,.form-select{
            min-height:48px;
            border:1px solid rgba(152,115,255,.26) !important;
            border-radius:14px !important;
            background:rgba(8,12,28,.72) !important;
            color:#fff !important;
            outline:none !important;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
            transition:all .20s ease;
        }
        .form-control:focus,.form-select:focus{
            border-color:rgba(168,85,247,.78) !important;
            box-shadow:0 0 0 4px rgba(124,58,237,.18), 0 10px 24px rgba(0,0,0,.25) !important;
        }
        .form-control::placeholder{color:#8e98b8}
        .form-select option{background:#0b1023;color:#fff}

        .assign-btn{
            width:100%;
            border:none;
            border-radius:15px;
            padding:13px 18px;
            color:#fff;
            font-weight:900;
            background:linear-gradient(135deg,#7c3cff,#9f5cff 55%,#18bfd1);
            box-shadow:0 18px 34px rgba(124,58,237,.28);
            transition:all .2s ease;
        }
        .assign-btn:hover{transform:translateY(-2px);filter:brightness(1.08);box-shadow:0 22px 42px rgba(124,58,237,.36)}

        .plan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
        .plan-card{
            position:relative;
            overflow:hidden;
            background:rgba(9,12,28,.72) !important;
            border:1px solid rgba(152,115,255,.22) !important;
            border-radius:20px !important;
            padding:12px 12px 16px;
            min-height:215px;
            box-shadow:0 14px 36px rgba(0,0,0,.24), inset 0 1px 0 rgba(255,255,255,.04) !important;
            transition:all .22s ease;
        }
        .plan-card:hover{
            transform:translateY(-4px);
            border-color:rgba(168,85,247,.58) !important;
            box-shadow:0 22px 54px rgba(0,0,0,.34),0 0 34px rgba(124,58,237,.18) !important;
        }
        .plan-card::after{
            content:'';
            position:absolute;
            width:120px;height:120px;right:-48px;bottom:-48px;border-radius:50%;
            background:rgba(124,58,237,.14);
            pointer-events:none;
        }
        .plan-top-bar{height:42px;border-radius:14px;position:relative;margin-bottom:15px;overflow:hidden;border:1px solid rgba(255,255,255,.10)}
        .plan-top-bar::before{content:"★ ★ ★";position:absolute;left:12px;top:10px;font-size:12px;color:rgba(255,255,255,.78);letter-spacing:3px}
        .plan-badge{
            position:absolute;
            top:20px;
            left:50%;
            transform:translateX(-50%);
            background:rgba(255,255,255,.14);
            color:#fff;
            border:1px solid rgba(255,255,255,.20);
            backdrop-filter:blur(10px);
            font-size:11px;
            font-weight:900;
            padding:5px 16px;
            border-radius:999px;
            letter-spacing:.6px;
        }
        .plan-title{font-size:17px;font-weight:900;color:#fff !important;margin-bottom:5px;line-height:1.2}
        .plan-subtitle{font-size:12px;color:var(--text-soft) !important;margin-bottom:12px;font-weight:600}
        .feature-list{list-style:none;margin:0;padding:0}
        .feature-list li{display:flex;gap:8px;align-items:flex-start;font-size:12px;color:#cdd5ee !important;margin-bottom:7px;line-height:1.35}
        .feature-list i{color:#39d98a;filter:drop-shadow(0 0 8px rgba(57,217,138,.25));margin-top:1px}
        .plan-btn{position:relative;z-index:1;margin-top:12px;border:none;border-radius:12px;padding:9px 16px;color:#fff;font-size:12px;font-weight:900;min-width:112px;transition:all .2s ease}
        .plan-btn:hover{transform:translateY(-2px);filter:brightness(1.08)}

        .banner-silver{background:linear-gradient(135deg,#4b5563,#cbd5e1)}
        .banner-gold{background:linear-gradient(135deg,#6d4b0b,#f2b84b)}
        .banner-platinum{background:linear-gradient(135deg,#4338ca,#a78bfa)}
        .banner-green{background:linear-gradient(135deg,#064e3b,#34d399)}
        .btn-blue{background:linear-gradient(135deg,#2563eb,#38bdf8)}
        .btn-gold{background:linear-gradient(135deg,#c8962e,#f59e0b)}
        .btn-purple{background:linear-gradient(135deg,#7c3cff,#a855f7)}
        .btn-green{background:linear-gradient(135deg,#059669,#22c55e)}
        .create-wrap{text-align:right;margin-top:16px}
        .create-btn{border:none;border-radius:14px;padding:12px 18px;color:#fff;font-weight:900;background:linear-gradient(135deg,#0aa35f,#1fcf7c);box-shadow:0 16px 30px rgba(10,163,95,.25);transition:all .2s ease}
        .create-btn:hover{transform:translateY(-2px);filter:brightness(1.05)}

        @media (max-width:1200px){.assign-grid{grid-template-columns:1fr}.plan-grid{grid-template-columns:repeat(3,1fr)}}
        @media (max-width:992px){.plan-grid{grid-template-columns:1fr 1fr}.sidebar{width:230px}}
        @media (max-width:768px){.content-wrapper{padding:14px}.page-shell{padding:16px;border-radius:18px}.plan-grid{grid-template-columns:1fr}.sidebar{width:220px}}
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
        <div class="topbar"><?php include('partials/topbar.php'); ?></div>

        <div class="content-wrapper">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= e($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= e($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="page-shell">
                <div class="page-title">Assign Membership</div>
                <div class="page-subtitle">View and assign membership to the customer.</div>

                <div class="assign-grid">
                    <div class="panel-box">
                        <div class="panel-head">Assign Form</div>
                        <div class="panel-body">
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label">Select Customer</label>
                                    <select class="form-select" name="customer_id" required>
                                        <option value="">Choose customer</option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>">
                                                <?= e($c['customer_name']) ?><?= !empty($c['customer_phone']) ? '  ' . e($c['customer_phone']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Select Membership Plan</label>
                                    <select class="form-select" name="membership_id" required>
                                        <option value="">Choose membership</option>
                                        <?php foreach ($memberships as $m): ?>
                                            <option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label">Assigned Date</label>
                                    <input type="date" class="form-control" name="start_date" value="<?= e(date('Y-m-d')) ?>" required>
                                </div>

                                <button type="submit" class="assign-btn">Assign Membership</button>
                            </form>
                        </div>
                    </div>

                    <div class="panel-box">
                        <div class="panel-head">Membership Summary</div>
                        <div class="panel-body">
                            <div class="plan-grid">
                                <?php foreach ($memberships as $m): ?>
                                    <?php $theme = membership_theme($m['name']); ?>
                                    <div class="plan-card">
                                        <div class="plan-top-bar <?= e($theme['banner']) ?>"></div>
                                        <div class="plan-badge"><?= e($theme['badge']) ?></div>
                                        <div class="plan-title"><?= e($m['name']) ?></div>
                                        <div class="plan-subtitle"><?= e($m['description_ui']) ?></div>

                                        <ul class="feature-list">
                                            <?php foreach (array_slice($m['benefits_ui'], 0, 3) as $benefit): ?>
                                                <li><i class="bi bi-check-circle-fill"></i> <?= e($benefit) ?></li>
                                            <?php endforeach; ?>
                                        </ul>

                                        <button type="button" class="plan-btn <?= e($theme['button']) ?>" onclick="window.location.href='membership-customers.php?membership_id=<?= (int)$m['id'] ?>'">View All</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="create-wrap">
                                <button type="button" class="create-btn" onclick="window.location.href='membership.php?action=create'">Create New Plan</button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
</body>
</html>
