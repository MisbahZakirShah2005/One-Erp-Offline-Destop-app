<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$client_id = (int) $_SESSION['client_id'];
$customer_id = (int)($_GET['customer_id'] ?? 0);

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
        return ['Basic Benefits', 'Points Program', 'Member Discount'];
    }
    if (strpos($name, 'gold') !== false) {
        return ['Priority Access', 'Extra Discounts', 'Premium Support'];
    }
    if (strpos($name, 'platinum') !== false) {
        return ['VIP Benefits', 'Highest Discounts', 'Dedicated Support'];
    }
    return ['Benefit 1', 'Benefit 2', 'Benefit 3'];
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

function format_discount($type, $value) {
    if ($type === 'percent') {
        return rtrim(rtrim(number_format((float)$value, 2), '0'), '.') . '%';
    }
    return 'Rs ' . number_format((float)$value, 2);
}

$customerNameCol  = first_existing_column($conn, 'customers', ['name', 'customer_name', 'full_name'], 'name');
$customerPhoneCol = first_existing_column($conn, 'customers', ['phone', 'mobile', 'contact_no', 'phone_number'], null);

$customerSql = "SELECT id, `$customerNameCol` AS customer_name" .
    ($customerPhoneCol ? ", `$customerPhoneCol` AS customer_phone" : ", '' AS customer_phone") .
    " FROM customers WHERE client_id = ? AND id = ?";
$stmt = $conn->prepare($customerSql);
$stmt->bind_param("ii", $client_id, $customer_id);
$stmt->execute();
$customerRes = $stmt->get_result();
$customer = $customerRes->fetch_assoc();
$stmt->close();

if (!$customer) {
    die("Customer not found");
}

$stmt = $conn->prepare("
    SELECT 
        cm.*,
        m.name,
        m.discount_type,
        m.discount_value,
        m.points_per_order,
        m.duration_days
    FROM customer_memberships cm
    LEFT JOIN memberships m ON cm.membership_id = m.id
    WHERE cm.client_id = ? AND cm.customer_id = ? AND cm.status = 'active'
    ORDER BY cm.id DESC
    LIMIT 1
");
$stmt->bind_param("ii", $client_id, $customer_id);
$stmt->execute();
$res = $stmt->get_result();
$membership = $res->fetch_assoc();
$stmt->close();

if (!$membership) {
    die("No active membership assigned");
}

$membership['description_ui'] = membership_description($membership);
$membership['benefits_ui'] = membership_benefits_from_name($membership['name']);
$theme = membership_theme($membership['name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assigned Membership</title>
    <?php include('partials/head.php'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{margin:0;background:#efedf8;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif}
        .container-scroll{display:flex;min-height:100vh}
        .sidebar{width:220px;flex-shrink:0}
        .main-panel{flex-grow:1;display:flex;flex-direction:column;min-width:0}
        .topbar{height:60px;border-bottom:1px solid #dee2e6;display:flex;align-items:center;padding:0 20px;background:#fff}
        .content-wrapper{padding:14px;flex-grow:1;overflow-y:auto;background:#efedf8}
        .page-shell{background:#efedf8;border:1px solid #e5e3ee;padding:12px;min-height:calc(100vh - 88px)}
        .page-title{font-size:22px;font-weight:700;color:#2e3140;margin-bottom:6px}
        .page-subtitle{color:#7b7f8c;font-size:14px;margin-bottom:16px}
        .view-grid{display:grid;grid-template-columns:.9fr 1.3fr;gap:14px}
        .panel-box{background:#fff;border:1px solid #d8dbe7;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden}
        .panel-body{padding:16px}
        .customer-card{display:flex;align-items:center;gap:16px}
        .customer-icon{width:78px;height:78px;border-radius:50%;background:linear-gradient(135deg,#4a90e2,#6aa8f1);color:#fff;display:flex;align-items:center;justify-content:center;font-size:40px}
        .customer-name{font-size:20px;font-weight:700;color:#2c3140}
        .customer-phone{font-size:14px;color:#6b7280;margin-top:4px}
        .section-title{font-size:18px;font-weight:700;color:#2e3140;margin-bottom:14px}
        .detail-grid{display:grid;grid-template-columns:170px 1fr;row-gap:10px;column-gap:10px}
        .detail-label{font-weight:700;color:#2f3442}
        .detail-value{color:#4b5160}
        .membership-card{border:1px solid #d9dfcf;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.06)}
        .membership-banner{min-height:92px;color:#fff;padding:22px 24px 16px;position:relative}
        .membership-banner::before{content:"";position:absolute;inset:0;background:
            radial-gradient(circle at 10% 10%, rgba(255,255,255,.35) 2px, transparent 3px),
            radial-gradient(circle at 25% 22%, rgba(255,255,255,.26) 3px, transparent 4px),
            radial-gradient(circle at 45% 15%, rgba(255,255,255,.28) 2px, transparent 3px),
            radial-gradient(circle at 80% 20%, rgba(255,255,255,.28) 2px, transparent 3px)}
        .membership-banner h3,.membership-badge{position:relative;z-index:1}
        .membership-badge{font-size:12px;font-weight:700;opacity:.92;margin-bottom:6px}
        .membership-banner h3{font-size:22px;margin:0}
        .membership-body{padding:16px 20px}
        .benefits-list{padding-left:20px;margin:0}
        .benefits-list li{margin-bottom:6px;color:#4a4f5d}
        .change-btn{border:none;border-radius:8px;padding:10px 18px;color:#fff;font-weight:700}
        .banner-silver{background:linear-gradient(135deg,#9aa8c2,#c4cfdf)}
        .banner-gold{background:linear-gradient(135deg,#b8832a,#e4be68)}
        .banner-platinum{background:linear-gradient(135deg,#6e64d5,#9c92ea)}
        .banner-green{background:linear-gradient(135deg,#4b9a48,#84c36e)}
        .btn-blue{background:linear-gradient(135deg,#4d86e8,#2b5ad5)}
        .btn-gold{background:linear-gradient(135deg,#c8962e,#a77617)}
        .btn-purple{background:linear-gradient(135deg,#5b73d6,#4059c8)}
        .btn-green{background:linear-gradient(135deg,#38bc68,#21954e)}
        @media (max-width:1200px){.view-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="container-scroll">
    <?php include('partials/sidebar.php'); ?>

    <div class="main-panel">
        <div class="topbar"><?php include('partials/topbar.php'); ?></div>

        <div class="content-wrapper">
            <div class="page-shell">
                <div class="page-title">Assigned Membership</div>
                <div class="page-subtitle">View details of the assigned membership to the customer.</div>

                <div class="view-grid">
                    <div>
                        <div class="panel-box mb-3">
                            <div class="panel-body">
                                <div class="customer-card">
                                    <div class="customer-icon"><i class="bi bi-person-fill"></i></div>
                                    <div>
                                        <div class="customer-name"><?= e($customer['customer_name']) ?></div>
                                        <div class="customer-phone"><?= e($customer['customer_phone']) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="panel-box">
                            <div class="panel-body">
                                <div class="section-title">Membership Details</div>

                                <div class="detail-grid">
                                    <div class="detail-label">Plan Name</div>
                                    <div class="detail-value"><?= e($membership['name']) ?></div>

                                    <div class="detail-label">Description</div>
                                    <div class="detail-value"><?= e($membership['description_ui']) ?></div>

                                    <div class="detail-label">Points Per Order</div>
                                    <div class="detail-value"><?= (int)$membership['points_per_order'] ?> Points</div>

                                    <div class="detail-label">Discount Type</div>
                                    <div class="detail-value"><?= e(ucfirst($membership['discount_type'])) ?></div>

                                    <div class="detail-label">Discount Value</div>
                                    <div class="detail-value"><?= e(format_discount($membership['discount_type'], $membership['discount_value'])) ?></div>

                                    <div class="detail-label">Assigned Date</div>
                                    <div class="detail-value"><?= e(date('d M Y', strtotime($membership['start_date']))) ?></div>

                                    <div class="detail-label">Duration</div>
                                    <div class="detail-value"><?= (int)$membership['duration_days'] ?> Days</div>

                                    <div class="detail-label">Expire Date</div>
                                    <div class="detail-value"><?= e(date('d M Y', strtotime($membership['end_date']))) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="membership-card mb-3">
                            <div class="membership-banner <?= e($theme['banner']) ?>">
                                <div class="membership-badge"><?= e($theme['badge']) ?></div>
                                <h3><?= e($membership['name']) ?></h3>
                            </div>

                            <div class="membership-body">
                                <ul class="benefits-list">
                                    <?php foreach ($membership['benefits_ui'] as $benefit): ?>
                                        <li><?= e($benefit) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>

                        <div class="panel-box">
                            <div class="panel-body">
                                <div class="section-title">Membership Details</div>

                                <div class="detail-grid">
                                    <div class="detail-label">Plan Name</div>
                                    <div class="detail-value"><?= e($membership['name']) ?></div>

                                    <div class="detail-label">Description</div>
                                    <div class="detail-value"><?= e($membership['description_ui']) ?></div>

                                    <div class="detail-label">Points Per Order</div>
                                    <div class="detail-value"><?= (int)$membership['points_per_order'] ?> Points</div>

                                    <div class="detail-label">Discount Type</div>
                                    <div class="detail-value"><?= e(ucfirst($membership['discount_type'])) ?></div>

                                    <div class="detail-label">Discount Value</div>
                                    <div class="detail-value"><?= e(format_discount($membership['discount_type'], $membership['discount_value'])) ?></div>

                                    <div class="detail-label">Assigned Date</div>
                                    <div class="detail-value"><?= e(date('d M Y', strtotime($membership['start_date']))) ?></div>

                                    <div class="detail-label">Expire Date</div>
                                    <div class="detail-value"><?= e(date('d M Y', strtotime($membership['end_date']))) ?></div>
                                </div>

                                <div class="text-center mt-4">
                                    <button type="button" class="change-btn <?= e($theme['button']) ?>" onclick="window.location.href='customer-membership-assign.php?customer_id=<?= (int)$customer_id ?>'">Change Membership</button>
                                </div>
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