<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)$_SESSION['client_id'];

// Ensure customer_id is passed and valid
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if ($customer_id <= 0) {
    die("Customer ID is required.");
}

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

$orderColumn = first_existing_column($conn, 'membership_card_templates', ['updated_at', 'created_at', 'id'], 'id');
$customerNameCol = first_existing_column($conn, 'customers', ['name', 'customer_name', 'full_name'], 'name');
$customerEmailCol = first_existing_column($conn, 'customers', ['email', 'customer_email'], null);
$customerPhoneCol = first_existing_column($conn, 'customers', ['phone', 'mobile', 'contact_no', 'phone_number'], null);

// Fetch the customer and their active membership data
$stmt = $conn->prepare("
    SELECT 
        c.id,
        c.`$customerNameCol` AS customer_name,
        " . ($customerEmailCol ? "c.`$customerEmailCol`" : "''") . " AS customer_email,
        " . ($customerPhoneCol ? "c.`$customerPhoneCol`" : "''") . " AS customer_phone,
        m.id AS membership_id,
        m.name AS membership_name,
        cm.start_date,
        cm.end_date
    FROM customer_memberships cm
    INNER JOIN customers c ON c.id = cm.customer_id
    INNER JOIN memberships m ON m.id = cm.membership_id
    WHERE cm.client_id = ? AND cm.customer_id = ? AND cm.status = 'active'
    ORDER BY cm.id DESC
    LIMIT 1
");
$stmt->bind_param("ii", $client_id, $customer_id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) die("Active membership not found for this customer.");

// Get the corresponding membership template
$stmt = $conn->prepare("SELECT * FROM membership_card_templates WHERE client_id = ? AND membership_id = ? ORDER BY `$orderColumn` DESC LIMIT 1");
$stmt->bind_param("ii", $client_id, $data['membership_id']);
$stmt->execute();
$res = $stmt->get_result();
$template = $res->fetch_assoc();
$stmt->close();

// Prepare the design settings based on the template
$design = [];
if ($template && !empty($template['design_json'])) {
    $decoded = json_decode($template['design_json'], true);
    if (is_array($decoded)) $design = $decoded;
}

$design = array_merge([
    'background_color_1' => '#d7a429',
    'background_color_2' => '#1d1a17',
    'text_color' => '#ffffff',
    'muted_text_color' => '#f8e5a5',
    'accent_color' => '#ffd76a',
    'badge_text' => strtoupper(substr($data['membership_name'], 0, 10)),
    'title_text' => $data['membership_name'],
    'subtitle_text' => 'Membership Card',
    'show_chip' => 1,
    'show_badge' => 1,
    'show_email' => 1,
    'show_validity' => 1,
    'show_glow' => 1,
    'show_top_sparkles' => 1,
    'border_radius' => 26,
    'card_width' => 430,
    'card_height' => 270,
    'title_size' => 22,
    'name_size' => 16,
], $design);

$logo = $template['logo'] ?? '';
$bg = $template['background_image'] ?? '';

$cardNo = str_pad((string)$data['customer_id'], 16, '0', STR_PAD_LEFT);
$cardNo = trim(chunk_split($cardNo, 4, ' '));
$valid = date('m/y', strtotime($data['end_date']));

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Customer Membership Card</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            background: #eef0f7;
            font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif;
            padding: 30px
        }

        .card-wrap {
            display: flex;
            justify-content: center
        }

        #cardPreview {
            position: relative;
            overflow: hidden;
            box-shadow: 0 18px 40px rgba(0, 0, 0, .18);
            width: <?=(int)$design['card_width'] ?>px;
            height: <?=(int)$design['card_height'] ?>px;
            border-radius: <?=(int)$design['border_radius'] ?>px;
            background: linear-gradient(135deg, <?=e($design['background_color_1']) ?>, <?=e($design['background_color_2']) ?>);
            color: <?=e($design['text_color']) ?>
        }

        .bg-image {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: .35;
            z-index: 0
        }

        .sparkles {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 8% 15%, rgba(255, 255, 255, .75) 2px, transparent 4px),
                radial-gradient(circle at 18% 20%, rgba(255, 255, 255, .28) 3px, transparent 5px),
                radial-gradient(circle at 35% 12%, rgba(255, 255, 255, .35) 2px, transparent 4px),
                radial-gradient(circle at 70% 16%, rgba(255, 255, 255, .38) 2px, transparent 4px),
                radial-gradient(circle at 85% 10%, rgba(255, 255, 255, .65) 4px, transparent 6px);
            z-index: 1
        }

        .gold-curves {
            position: absolute;
            inset: 0;
            z-index: 1
        }

        .gold-curves::before,
        .gold-curves::after {
            content: "";
            position: absolute;
            left: -5%;
            right: -5%;
            height: 46px;
            border-radius: 50%;
            border: 2px solid rgba(255, 215, 106, .7)
        }

        .gold-curves::before {
            top: 58px;
            border-left: none;
            border-right: none
        }

        .gold-curves::after {
            bottom: 48px;
            border-left: none;
            border-right: none
        }

        .content-layer {
            position: relative;
            z-index: 3;
            width: 100%;
            height: 100%
        }

        .card-badge {
            position: absolute;
            top: 18px;
            left: 20px;
            padding: 8px 18px;
            border-radius: 14px;
            font-weight: 800;
            background: linear-gradient(135deg, <?=e($design['accent_color']) ?>, #fff3b0);
            color: #4a3410
        }

        .card-chip {
            position: absolute;
            top: 86px;
            left: 24px;
            width: 62px;
            height: 48px;
            border-radius: 10px;
            background: linear-gradient(135deg, #f9e8a7, #dcb754)
        }

        .card-logo {
            position: absolute;
            top: 18px;
            right: 20px;
            object-fit: contain;
            width: 52px;
            height: 52px
        }

        .card-title {
            position: absolute;
            top: 80px;
            left: 102px;
            font-weight: 800;
            line-height: 1.1;
            font-size: <?=(int)$design['title_size'] ?>px
        }

        .card-subtitle {
            position: absolute;
            top: 112px;
            left: 104px;
            color: <?=e($design['muted_text_color']) ?>
        }

        .card-member-name {
            position: absolute;
            left: 24px;
            bottom: 58px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: <?=(int)$design['name_size'] ?>px
        }

        .card-member-email {
            position: absolute;
            left: 24px;
            bottom: 36px;
            font-size: 12px;
            color: <?=e($design['muted_text_color']) ?>
        }

        .card-number {
            position: absolute;
            left: 24px;
            bottom: 14px;
            font-size: 18px;
            font-weight: 700
        }

        .card-valid {
            position: absolute;
            right: 24px;
            bottom: 20px;
            text-align: right
        }

        .card-valid .label {
            opacity: .75;
            font-size: 11px;
            display: block
        }

        .card-valid .date {
            font-weight: 700
        }

        .actions {
            text-align: center;
            margin-top: 20px
        }

        .btnx {
            display: inline-block;
            padding: 10px 16px;
            background: #2b5ad5;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            margin: 0 6px
        }
    </style>
</head>

<body>
    <div class="card-wrap">
        <div id="cardPreview">
            <?php if ($bg): ?><img src="/<?= e($bg) ?>" class="bg-image" alt="bg"><?php endif; ?>
            <?php if (!empty($design['show_top_sparkles'])): ?><div class="sparkles"></div><?php endif; ?>
            <div class="gold-curves"></div>
            <div class="content-layer">
                <?php if (!empty($design['show_badge'])): ?><div class="card-badge"><?= e($design['badge_text']) ?>
                </div><?php endif; ?>
                <?php if (!empty($design['show_chip'])): ?><div class="card-chip"></div><?php endif; ?>
                <?php if ($logo): ?><img src="/<?= e($logo) ?>" class="card-logo" alt="logo"><?php endif; ?>
                <div class="card-title"><?= e($design['title_text']) ?></div>
                <div class="card-subtitle"><?= e($design['subtitle_text']) ?></div>
                <div class="card-member-name"><?= e($data['customer_name']) ?></div>
                <?php if (!empty($design['show_email'])): ?><div class="card-member-email">
                    <?= e($data['customer_email']) ?></div><?php endif; ?>
                <div class="card-number"><?= e($cardNo) ?></div>
                <?php if (!empty($design['show_validity'])): ?>
                <div class="card-valid"><span class="label">Valid Thru</span><span class="date"><?= e($valid) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="actions">
        <a href="javascript:window.print()" class="btnx">Print Card</a>
        <a href="customer-membership-view.php?customer_id=<?= (int)$customer_id ?>" class="btnx">Back</a>
    </div>
</body>

</html>