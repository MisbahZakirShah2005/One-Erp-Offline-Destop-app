<?php
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
        if (has_column($conn, $table, $column)) return $column;
    }
    return $fallback;
}

function membership_theme($name) {
    $name = strtolower(trim((string)$name));
    if (strpos($name, 'silver') !== false) {
        return ['card' => 'silver-card', 'btn' => 'btn-silver', 'label' => 'SILVER'];
    }
    if (strpos($name, 'gold') !== false) {
        return ['card' => 'gold-card', 'btn' => 'btn-gold', 'label' => 'GOLD'];
    }
    if (strpos($name, 'platinum') !== false) {
        return ['card' => 'platinum-card', 'btn' => 'btn-platinum', 'label' => 'PLATINUM'];
    }
    return ['card' => 'default-card', 'btn' => 'btn-default', 'label' => strtoupper(substr((string)$name, 0, 10))];
}

function membership_description($name) {
    $name = strtolower(trim((string)$name));
    if (strpos($name, 'silver') !== false) return 'Basic benefits and rewards.';
    if (strpos($name, 'gold') !== false) return 'Premium benefits and rewards.';
    if (strpos($name, 'platinum') !== false) return 'Exclusive benefits and rewards.';
    return 'Membership benefits and rewards.';
}

function format_discount($type, $value) {
    if ($type === 'percent') {
        return rtrim(rtrim(number_format((float)$value, 2), '0'), '.') . '%';
    }
    return 'Rs ' . number_format((float)$value, 2);
}

$search = trim($_GET['search'] ?? '');

$customerNameCol = first_existing_column($conn, 'customers', ['name', 'customer_name', 'full_name'], 'name');
$customerPhoneCol = first_existing_column($conn, 'customers', ['phone', 'mobile', 'contact_no', 'phone_number'], null);

/* =========================
   MEMBERSHIP SUMMARY CARDS
========================= */
$membershipCards = [];
$sqlCards = "
    SELECT 
        m.id,
        m.name,
        m.discount_type,
        m.discount_value,
        m.duration_days,
        m.points_per_order,
        COUNT(cm.id) AS total_customers,
        SUM(CASE WHEN cm.status = 'active' THEN 1 ELSE 0 END) AS active_customers
    FROM memberships m
    LEFT JOIN customer_memberships cm 
        ON cm.membership_id = m.id 
        AND cm.client_id = m.client_id
    WHERE m.client_id = ?
    GROUP BY m.id, m.name, m.discount_type, m.discount_value, m.duration_days, m.points_per_order
    ORDER BY m.id DESC
";
$stmt = $conn->prepare($sqlCards);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$resCards = $stmt->get_result();
while ($row = $resCards->fetch_assoc()) {
    $membershipCards[] = $row;
}
$stmt->close();

/* =========================
   OVERALL COUNTS
========================= */
$overall = [
    'total_memberships' => 0,
    'total_customers' => 0,
    'active_customers' => 0,
    'expired_customers' => 0
];

$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM memberships WHERE client_id = ?) AS total_memberships,
        (SELECT COUNT(*) FROM customer_memberships WHERE client_id = ?) AS total_customers,
        (SELECT COUNT(*) FROM customer_memberships WHERE client_id = ? AND status = 'active') AS active_customers,
        (SELECT COUNT(*) FROM customer_memberships WHERE client_id = ? AND status = 'expired') AS expired_customers
");
$stmt->bind_param("iiii", $client_id, $client_id, $client_id, $client_id);
$stmt->execute();
$resOverall = $stmt->get_result();
if ($row = $resOverall->fetch_assoc()) {
    $overall = $row;
}
$stmt->close();

/* =========================
   ALL CUSTOMER MEMBERSHIP LIST
========================= */
$rows = [];

$sqlList = "
    SELECT 
        cm.id,
        cm.customer_id,
        c.`$customerNameCol` AS customer_name,
        " . ($customerPhoneCol ? "c.`$customerPhoneCol`" : "''") . " AS customer_phone,
        m.id AS membership_id,
        m.name AS membership_name,
        cm.start_date,
        cm.end_date,
        cm.status,
        cm.created_at
    FROM customer_memberships cm
    LEFT JOIN customers c ON cm.customer_id = c.id
    LEFT JOIN memberships m ON cm.membership_id = m.id
    WHERE cm.client_id = ?
";

$types = "i";
$params = [$client_id];

if ($search !== '') {
    $sqlList .= " AND (
        c.`$customerNameCol` LIKE ?
        OR m.name LIKE ?
        OR cm.status LIKE ?
    )";
    $types .= "sss";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sqlList .= " ORDER BY cm.id DESC";

$stmt = $conn->prepare($sqlList);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$resList = $stmt->get_result();
while ($row = $resList->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Membership Customers</title>
    <?php include('partials/head.php'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            margin: 0;
            background: #efedf8;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
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

        .main-panel {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .topbar {
            height: 60px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            padding: 0 20px;
            background: #fff;
        }
        .content-wrapper {
            padding: 14px;
            flex-grow: 1;
            overflow-y: auto;
            background: #efedf8;
        }
        .page-shell {
            background: #efedf8;
            border: 1px solid #e5e3ee;
            padding: 12px;
            min-height: calc(100vh - 88px);
        }
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #2e3140;
            margin-bottom: 6px;
        }
        .page-subtitle {
            color: #7b7f8c;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .top-summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .top-summary-card {
            background: #fff;
            border: 1px solid #d8dbe7;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }
        .top-summary-card .label {
            font-size: 13px;
            color: #6b7280;
        }
        .top-summary-card .value {
            font-size: 25px;
            font-weight: 800;
            color: #232733;
            margin-top: 5px;
        }
        .membership-cards-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 16px;
        }
        .membership-card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #d8dbe7;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            overflow: hidden;
        }
        .membership-card-top {
            height: 72px;
            color: #fff;
            padding: 14px 16px;
            position: relative;
        }
        .membership-card-top::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 12% 18%, rgba(255,255,255,.28) 2px, transparent 3px),
                radial-gradient(circle at 22% 30%, rgba(255,255,255,.20) 3px, transparent 4px),
                radial-gradient(circle at 78% 20%, rgba(255,255,255,.18) 3px, transparent 4px);
            pointer-events: none;
        }
        .silver-card { background: linear-gradient(135deg,#cfd7e6,#9daac0); }
        .gold-card { background: linear-gradient(135deg,#e0b64b,#b58017); }
        .platinum-card { background: linear-gradient(135deg,#8f85e6,#6559d4); }
        .default-card { background: linear-gradient(135deg,#57b66e,#2d9551); }

        .membership-badge {
            position: relative;
            z-index: 1;
            display: inline-block;
            background: rgba(255,255,255,.22);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .membership-title {
            position: relative;
            z-index: 1;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.2;
        }
        .membership-card-body {
            padding: 14px 16px 16px;
        }
        .membership-desc {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 10px;
            min-height: 38px;
        }
        .mini-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }
        .mini-box {
            background: #f8f9fc;
            border: 1px solid #eaedf4;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
        }
        .mini-box .num {
            font-size: 18px;
            font-weight: 800;
            color: #232733;
        }
        .mini-box .txt {
            font-size: 11px;
            color: #6b7280;
        }
        .view-btn {
            width: 100%;
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            color: #fff;
            font-weight: 700;
        }
        .btn-silver { background: linear-gradient(135deg,#4d86e8,#2b5ad5); }
        .btn-gold { background: linear-gradient(135deg,#c8962e,#a77617); }
        .btn-platinum { background: linear-gradient(135deg,#675dd7,#4a41bc); }
        .btn-default { background: linear-gradient(135deg,#38bc68,#21954e); }

        .table-box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.05);
        }
        .table-box-head {
            padding: 14px 16px;
            border-bottom: 1px solid #ececec;
            background: #fbfbfb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .table-box-head h5 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #2f3341;
        }
        .search-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .search-form .form-control {
            min-width: 240px;
        }
        .assign-btn {
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            color: #fff;
            font-weight: 700;
            background: linear-gradient(135deg,#4d86e8,#2b5ad5);
        }
        .table-modern {
            margin: 0;
        }
        .table-modern thead th {
            background: #f5f5f8;
            font-size: 13px;
            font-weight: 700;
            color: #464b57;
            border-bottom: 1px solid #ddd;
            padding: 12px 14px;
            white-space: nowrap;
        }
        .table-modern tbody td {
            padding: 12px 14px;
            font-size: 13px;
            color: #31343f;
            vertical-align: middle;
        }
        /* NEW ICON STYLE */
.icon-btn{
    width:38px;
    height:38px;
    border:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

/* VIEW */
.icon-view{
    background:#eef2ff;
    color:#6366f1;
}
.icon-view:hover{
    background:#6366f1;
    color:#fff;
}

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }
        .status-active { background: #d9f4e5; color: #20744a; }
        .status-expired { background: #fde2e2; color: #a33030; }
        .status-other { background: #f3f4f6; color: #4b5563; }

        @media (max-width: 1200px) {
            .top-summary-row,
            .membership-cards-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 768px) {
            .top-summary-row,
            .membership-cards-grid {
                grid-template-columns: 1fr;
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
        <div class="topbar"><?php include('partials/topbar.php'); ?></div>

        <div class="content-wrapper">
            <div class="page-shell">
                <div class="page-title">Membership Customers</div>
                <div class="page-subtitle">All memberships summary and all assigned customers list.</div>

                <div class="top-summary-row">
                    <div class="top-summary-card">
                        <div class="label">Total Memberships</div>
                        <div class="value"><?= number_format((int)$overall['total_memberships']) ?></div>
                    </div>
                    <div class="top-summary-card">
                        <div class="label">Total Assigned</div>
                        <div class="value"><?= number_format((int)$overall['total_customers']) ?></div>
                    </div>
                    <div class="top-summary-card">
                        <div class="label">Active Memberships</div>
                        <div class="value"><?= number_format((int)$overall['active_customers']) ?></div>
                    </div>
                    <div class="top-summary-card">
                        <div class="label">Expired Memberships</div>
                        <div class="value"><?= number_format((int)$overall['expired_customers']) ?></div>
                    </div>
                </div>

                <div class="membership-cards-grid">
                    <?php if (!empty($membershipCards)): ?>
                        <?php foreach ($membershipCards as $m): ?>
                            <?php $theme = membership_theme($m['name']); ?>
                            <div class="membership-card">
                                <div class="membership-card-top <?= e($theme['card']) ?>">
                                    <div class="membership-badge"><?= e($theme['label']) ?></div>
                                    <div class="membership-title"><?= e($m['name']) ?></div>
                                </div>
                                <div class="membership-card-body">
                                    <div class="membership-desc"><?= e(membership_description($m['name'])) ?></div>

                                    <div class="mini-stats">
                                        <div class="mini-box">
                                            <div class="num"><?= number_format((int)$m['total_customers']) ?></div>
                                            <div class="txt">Total Customers</div>
                                        </div>
                                        <div class="mini-box">
                                            <div class="num"><?= number_format((int)$m['active_customers']) ?></div>
                                            <div class="txt">Active</div>
                                        </div>
                                    </div>

                                    <!--<button type="button" class="view-btn <?= e($theme['btn']) ?>"-->
                                    <!--    onclick="window.location.href='membership-wise-customers.php?membership_id=<?= (int)$m['id'] ?>'">-->
                                    <!--    View Customers-->
                                    <!--</button>-->
                                    
                                    
                                    
                                    
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted">No memberships found</div>
                    <?php endif; ?>
                </div>

                <div class="table-box">
                    <div class="table-box-head">
                        <h5>All Customers Membership List</h5>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <form method="get" class="search-form">
                                <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search customer or membership">
                                <button class="btn btn-light border" type="submit"><i class="bi bi-search"></i></button>
                            </form>

                            <button type="button" class="assign-btn" onclick="window.location.href='customer-membership-assign.php'">Assign Membership</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Membership</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    <th>View</th>
                                    <th>Card</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= (int)$row['id'] ?></td>
                                        <td><?= e($row['customer_name']) ?></td>
                                        <td><?= e($row['customer_phone']) ?></td>
                                        <td><?= e($row['membership_name']) ?></td>
                                        <td><?= e(date('d M Y', strtotime($row['start_date']))) ?></td>
                                        <td><?= e(date('d M Y', strtotime($row['end_date']))) ?></td>
                                        <td>
                                            <?php
                                            $statusClass = 'status-other';
                                            if ($row['status'] === 'active') $statusClass = 'status-active';
                                            elseif ($row['status'] === 'expired') $statusClass = 'status-expired';
                                            ?>
                                            <span class="status-badge <?= $statusClass ?>"><?= e(ucfirst($row['status'])) ?></span>
                                        </td>
                                        <td>
                                            <a href="customer-membership-view.php?customer_id=<?= (int)$row['customer_id'] ?>" class="icon-btn icon-view">
                                                <i class="bi bi-eye-fill"></i>
                                            </a>
                                        </td>
                                           <td>
                                            <a href="customer_membership_card.php?customer_id=<?= (int)$row['customer_id'] ?>" class="icon-btn icon-view">
                                                <i class="bi bi-eye-fill"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center">No membership records found</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
</body>
</html>