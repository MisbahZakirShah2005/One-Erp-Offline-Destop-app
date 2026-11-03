<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
include($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

// if (!isset($_SESSION['user_id']) || !isset($_SESSION['client_id'])) {
//     die("Login required");
// }


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

function uploadFile($fileKey, $folder = 'uploads/membership_cards/') {
    if (!isset($_FILES[$fileKey]) || !is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
        return null;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;

    $baseDir = $_SERVER['DOCUMENT_ROOT'] . '/' . trim($folder, '/') . '/';
    if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

    $filename = uniqid('card_', true) . '.' . $ext;
    $target = $baseDir . $filename;

    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
        return trim($folder, '/') . '/' . $filename;
    }

    return null;
}

$tableName = 'membership_card_templates';
$orderColumn = first_existing_column($conn, $tableName, ['updated_at', 'created_at', 'id'], 'id');

$hasMembershipId   = has_column($conn, $tableName, 'membership_id');
$hasTemplateName   = has_column($conn, $tableName, 'template_name');
$hasDesignJson     = has_column($conn, $tableName, 'design_json');
$hasLogo           = has_column($conn, $tableName, 'logo');
$hasBackgroundImg  = has_column($conn, $tableName, 'background_image');

if (!$hasMembershipId) {
    die("membership_card_templates table me membership_id column missing hai. Pehle DB update karo.");
}

$defaultDesign = [
    'template_name'      => 'Premium Membership Card',
    'card_style'         => 'improved_gold',
    'font_family'        => 'Poppins',
    'background_type'    => 'gradient',
    'background_color_1' => '#d7a429',
    'background_color_2' => '#1d1a17',
    'text_color'         => '#ffffff',
    'muted_text_color'   => '#f8e5a5',
    'accent_color'       => '#ffd76a',
    'badge_text'         => 'GOLD',
    'title_text'         => 'Gold Membership',
    'subtitle_text'      => 'Premium Access Card',
    'show_chip'          => 1,
    'show_badge'         => 1,
    'show_email'         => 1,
    'show_validity'      => 1,
    'show_glow'          => 1,
    'show_top_sparkles'  => 1,
    'border_radius'      => 26,
    'card_width'         => 430,
    'card_height'        => 270,
    'title_size'         => 22,
    'text_size'          => 12,
    'name_size'          => 16,
    'number_size'        => 18,
    'logo_size'          => 52
];

$membership_id = (int)($_GET['membership_id'] ?? 0);

/* =========================
   FETCH MEMBERSHIPS
========================= */
$memberships = [];
$stmt = $conn->prepare("SELECT id, name FROM memberships WHERE client_id = ? ORDER BY id DESC");
if ($stmt) {
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $memberships[] = $row;
    }
    $stmt->close();
}

if ($membership_id <= 0 && !empty($memberships)) {
    $membership_id = (int)$memberships[0]['id'];
}

/* =========================
   GET EXISTING TEMPLATE
========================= */
function fetchTemplate($conn, $client_id, $membership_id, $tableName, $orderColumn) {
    $stmt = $conn->prepare("SELECT * FROM `$tableName` WHERE client_id = ? AND membership_id = ? ORDER BY `$orderColumn` DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("ii", $client_id, $membership_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* =========================
   SAVE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $membership_id = (int)($_POST['membership_id'] ?? 0);
    $action = $_POST['action'] ?? 'save';

    if ($membership_id <= 0) {
        $_SESSION['error'] = "Please select membership.";
        header("Location: membership_card_template.php");
        exit;
    }

    $existingTemplate = fetchTemplate($conn, $client_id, $membership_id, $tableName, $orderColumn);

    if ($action === 'save') {
        $design = [
            'template_name'      => trim($_POST['template_name'] ?? 'Default Template'),
            'card_style'         => trim($_POST['card_style'] ?? 'improved_gold'),
            'font_family'        => trim($_POST['font_family'] ?? 'Poppins'),
            'background_type'    => trim($_POST['background_type'] ?? 'gradient'),
            'background_color_1' => trim($_POST['background_color_1'] ?? '#d7a429'),
            'background_color_2' => trim($_POST['background_color_2'] ?? '#1d1a17'),
            'text_color'         => trim($_POST['text_color'] ?? '#ffffff'),
            'muted_text_color'   => trim($_POST['muted_text_color'] ?? '#f8e5a5'),
            'accent_color'       => trim($_POST['accent_color'] ?? '#ffd76a'),
            'badge_text'         => trim($_POST['badge_text'] ?? 'GOLD'),
            'title_text'         => trim($_POST['title_text'] ?? 'Gold Membership'),
            'subtitle_text'      => trim($_POST['subtitle_text'] ?? 'Premium Access Card'),
            'show_chip'          => isset($_POST['show_chip']) ? 1 : 0,
            'show_badge'         => isset($_POST['show_badge']) ? 1 : 0,
            'show_email'         => isset($_POST['show_email']) ? 1 : 0,
            'show_validity'      => isset($_POST['show_validity']) ? 1 : 0,
            'show_glow'          => isset($_POST['show_glow']) ? 1 : 0,
            'show_top_sparkles'  => isset($_POST['show_top_sparkles']) ? 1 : 0,
            'border_radius'      => (int)($_POST['border_radius'] ?? 26),
            'card_width'         => (int)($_POST['card_width'] ?? 430),
            'card_height'        => (int)($_POST['card_height'] ?? 270),
            'title_size'         => (int)($_POST['title_size'] ?? 22),
            'text_size'          => (int)($_POST['text_size'] ?? 12),
            'name_size'          => (int)($_POST['name_size'] ?? 16),
            'number_size'        => (int)($_POST['number_size'] ?? 18),
            'logo_size'          => (int)($_POST['logo_size'] ?? 52)
        ];

        $logoPath = ($existingTemplate['logo'] ?? '');
        $bgPath   = ($existingTemplate['background_image'] ?? '');

        $uploadedLogo = uploadFile('logo_file');
        if ($uploadedLogo) $logoPath = $uploadedLogo;

        $uploadedBg = uploadFile('background_file');
        if ($uploadedBg) $bgPath = $uploadedBg;

        $json = json_encode($design, JSON_UNESCAPED_UNICODE);

        if ($existingTemplate) {
            $setParts = [];
            $params = [];
            $types = '';

            if ($hasTemplateName) {
                $setParts[] = "template_name = ?";
                $params[] = $design['template_name'];
                $types .= 's';
            }

            if ($hasDesignJson) {
                $setParts[] = "design_json = ?";
                $params[] = $json;
                $types .= 's';
            }

            if ($hasLogo) {
                $setParts[] = "logo = ?";
                $params[] = $logoPath;
                $types .= 's';
            }

            if ($hasBackgroundImg) {
                $setParts[] = "background_image = ?";
                $params[] = $bgPath;
                $types .= 's';
            }

            if (!empty($setParts)) {
                $sql = "UPDATE `$tableName` SET " . implode(', ', $setParts) . " WHERE id = ? AND client_id = ? AND membership_id = ?";
                $params[] = (int)$existingTemplate['id'];
                $params[] = $client_id;
                $params[] = $membership_id;
                $types .= 'iii';

                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $_SESSION['success'] = "Template updated successfully.";
        } else {
            $columns = ['client_id', 'membership_id'];
            $placeholders = ['?', '?'];
            $params = [$client_id, $membership_id];
            $types = 'ii';

            if ($hasTemplateName) {
                $columns[] = 'template_name';
                $placeholders[] = '?';
                $params[] = $design['template_name'];
                $types .= 's';
            }

            if ($hasDesignJson) {
                $columns[] = 'design_json';
                $placeholders[] = '?';
                $params[] = $json;
                $types .= 's';
            }

            if ($hasLogo) {
                $columns[] = 'logo';
                $placeholders[] = '?';
                $params[] = $logoPath;
                $types .= 's';
            }

            if ($hasBackgroundImg) {
                $columns[] = 'background_image';
                $placeholders[] = '?';
                $params[] = $bgPath;
                $types .= 's';
            }

            $sql = "INSERT INTO `$tableName` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            }

            $_SESSION['success'] = "Template saved successfully.";
        }

        header("Location: membership_card_template.php?membership_id=" . $membership_id);
        exit;
    }
}

/* =========================
   LOAD CURRENT TEMPLATE
========================= */
$template = null;
$currentDesign = $defaultDesign;
$currentLogo = '';
$currentBgImage = '';

if ($membership_id > 0) {
    $template = fetchTemplate($conn, $client_id, $membership_id, $tableName, $orderColumn);

    if ($template) {
        if ($hasDesignJson && !empty($template['design_json'])) {
            $saved = json_decode($template['design_json'], true);
            if (is_array($saved)) {
                $currentDesign = array_merge($defaultDesign, $saved);
            }
        }

        if ($hasLogo) {
            $currentLogo = $template['logo'] ?? '';
        }

        if ($hasBackgroundImg) {
            $currentBgImage = $template['background_image'] ?? '';
        }
    }

    $stmt = $conn->prepare("SELECT name FROM memberships WHERE id = ? AND client_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $membership_id, $client_id);
        $stmt->execute();
        $membershipRes = $stmt->get_result();
        $membership = $membershipRes->fetch_assoc();
        $stmt->close();

        if ($membership && empty($currentDesign['title_text'])) {
            $currentDesign['title_text'] = $membership['name'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Membership Card Builder</title>
    <?php include('partials/head.php'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
    body {
        margin: 0;
        background: #eef0f7;
        font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif
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


.sidebar > .sidebar-wrap{
    width:100%;
    min-height:100%;
    height:100%;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100%;
}

    .main-panel {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        min-width: 0
    }

    .topbar {
        height: 60px;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        align-items: center;
        padding: 0 20px;
        background: #fff
    }

    .content-wrapper {
        padding: 20px;
        flex-grow: 1;
        overflow-y: auto;
        background: #f3f4fb
    }

    .page-title {
        font-size: 24px;
        font-weight: 800;
        color: #212534;
        margin-bottom: 5px
    }

    .page-subtitle {
        font-size: 14px;
        color: #6b7280;
        margin-bottom: 18px
    }

    .builder-grid {
        display: grid;
        grid-template-columns: 420px 1fr;
        gap: 20px;
        align-items: start
    }

    .panel-box {
        background: #fff;
        border: 1px solid #e4e7f1;
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .05);
        overflow: hidden
    }

    .panel-head {
        padding: 16px 18px;
        font-size: 16px;
        font-weight: 700;
        color: #232838;
        border-bottom: 1px solid #eef1f7;
        background: #fbfcff
    }

    .panel-body {
        padding: 18px
    }

    .form-label {
        font-weight: 600;
        color: #31374a;
        margin-bottom: 7px
    }

    .form-control,
    .form-select {
        min-height: 42px;
        border-radius: 10px;
        border: 1px solid #d9deea;
        box-shadow: none !important
    }

    .color-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px
    }

    .check-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px
    }

    .preview-wrap {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: 500px
    }

    #cardPreview {
        position: relative;
        overflow: hidden;
        box-shadow: 0 18px 40px rgba(0, 0, 0, .18);
        transition: .2s ease
    }

    #cardPreview .bg-image {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: .35;
        z-index: 0
    }

    #cardPreview .sparkles {
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at 8% 15%, rgba(255, 255, 255, .75) 2px, transparent 4px),
            radial-gradient(circle at 18% 20%, rgba(255, 255, 255, .28) 3px, transparent 5px),
            radial-gradient(circle at 35% 12%, rgba(255, 255, 255, .35) 2px, transparent 4px),
            radial-gradient(circle at 70% 16%, rgba(255, 255, 255, .38) 2px, transparent 4px),
            radial-gradient(circle at 85% 10%, rgba(255, 255, 255, .65) 4px, transparent 6px);
        z-index: 1;
        pointer-events: none
    }

    #cardPreview .gold-curves {
        position: absolute;
        inset: 0;
        z-index: 1;
        pointer-events: none
    }

    #cardPreview .gold-curves::before,
    #cardPreview .gold-curves::after {
        content: "";
        position: absolute;
        left: -5%;
        right: -5%;
        height: 46px;
        border-radius: 50%;
        border: 2px solid rgba(255, 215, 106, .7);
        filter: blur(.2px)
    }

    #cardPreview .gold-curves::before {
        top: 58px;
        border-left: none;
        border-right: none
    }

    #cardPreview .gold-curves::after {
        bottom: 48px;
        border-left: none;
        border-right: none
    }

    #cardPreview .content-layer {
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
        box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
        letter-spacing: .5px
    }

    .card-chip {
        position: absolute;
        top: 86px;
        left: 24px;
        width: 62px;
        height: 48px;
        border-radius: 10px;
        background: linear-gradient(135deg, #f9e8a7, #dcb754);
        box-shadow: 0 4px 10px rgba(0, 0, 0, .18)
    }

    .card-chip::before,
    .card-chip::after {
        content: "";
        position: absolute;
        background: rgba(138, 103, 25, .45)
    }

    .card-chip::before {
        top: 0;
        bottom: 0;
        width: 1px;
        left: 50%
    }

    .card-chip::after {
        left: 0;
        right: 0;
        height: 1px;
        top: 50%
    }

    .card-logo {
        position: absolute;
        top: 18px;
        right: 20px;
        object-fit: contain
    }

    .card-title {
        position: absolute;
        top: 80px;
        left: 102px;
        font-weight: 800;
        line-height: 1.1
    }

    .card-subtitle {
        position: absolute;
        top: 112px;
        left: 104px;
        opacity: .85
    }

    .card-member-name {
        position: absolute;
        left: 24px;
        bottom: 58px;
        font-weight: 700;
        text-transform: uppercase
    }

    .card-member-email {
        position: absolute;
        left: 24px;
        bottom: 36px;
        font-size: 12px;
        opacity: .85
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

    .action-row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap
    }

    .save-btn {
        background: linear-gradient(135deg, #4d86e8, #2b5ad5);
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 11px 18px;
        font-weight: 700
    }

    @media (max-width:1200px) {
        .builder-grid {
            grid-template-columns: 1fr
        }
    }
    
    /* =========================================================
   MEMBERSHIP CARD BUILDER - DARK PURPLE GLASS THEME
   Paste this code just BEFORE 
========================================================= */

:root{
    --mcb-bg:#070b1a;
    --mcb-bg-2:#10142c;
    --mcb-card:rgba(22,26,54,.78);
    --mcb-card-2:rgba(28,32,68,.72);
    --mcb-border:rgba(168,85,247,.24);
    --mcb-border-soft:rgba(255,255,255,.08);
    --mcb-text:#ffffff;
    --mcb-soft:#c9c7e8;
    --mcb-muted:#9b98bd;
    --mcb-purple:#7c3aed;
    --mcb-purple-2:#a855f7;
    --mcb-blue:#3b82f6;
    --mcb-green:#10b981;
}

/* MAIN PAGE */
body{
    background:
        radial-gradient(circle at 15% 10%, rgba(124,58,237,.28), transparent 35%),
        radial-gradient(circle at 85% 12%, rgba(14,165,233,.14), transparent 34%),
        linear-gradient(135deg,#070b1a 0%,#0c1025 50%,#11162e 100%) !important;
    color:var(--mcb-text) !important;
}

.container-scroll,
.main-panel{
    background:transparent !important;
}

/* TOPBAR */
.topbar{
    height:64px !important;
    background:rgba(5,9,22,.94) !important;
    border-bottom:1px solid rgba(139,92,246,.22) !important;
    box-shadow:0 12px 35px rgba(0,0,0,.28) !important;
    backdrop-filter:blur(16px);
}

/* CONTENT AREA */
.content-wrapper{
    padding:26px !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 90% 10%, rgba(14,165,233,.14), transparent 30%),
        linear-gradient(135deg,#0b0f25 0%,#10142c 52%,#121731 100%) !important;
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
    color:var(--mcb-soft) !important;
    font-size:14px !important;
    margin-bottom:22px !important;
}

/* LAYOUT */
.builder-grid{
    grid-template-columns:420px 1fr !important;
    gap:20px !important;
    align-items:start !important;
}

/* GLASS PANELS */
.panel-box{
    background:linear-gradient(180deg, rgba(25,30,65,.82), rgba(17,21,45,.78)) !important;
    border:1px solid var(--mcb-border) !important;
    border-radius:18px !important;
    box-shadow:
        0 22px 55px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px);
    overflow:hidden !important;
}

.panel-head{
    background:rgba(9,13,30,.92) !important;
    color:#ffffff !important;
    border-bottom:1px solid rgba(255,255,255,.10) !important;
    padding:18px 20px !important;
    font-size:17px !important;
    font-weight:900 !important;
}

.panel-body{
    padding:20px !important;
    background:transparent !important;
}

/* LABELS */
.form-label{
    color:#f4f2ff !important;
    font-weight:700 !important;
    margin-bottom:8px !important;
}

.form-check-label{
    color:var(--mcb-soft) !important;
    font-weight:600 !important;
}

/* INPUTS */
.form-control,
.form-select{
    background:rgba(8,12,30,.88) !important;
    border:1px solid rgba(168,85,247,.25) !important;
    color:#ffffff !important;
    border-radius:12px !important;
    min-height:44px !important;
    box-shadow:none !important;
}

.form-control::placeholder{
    color:#8f8bb5 !important;
}

.form-control:focus,
.form-select:focus{
    background:rgba(8,12,30,.95) !important;
    border-color:#a855f7 !important;
    color:#ffffff !important;
    box-shadow:0 0 0 3px rgba(168,85,247,.18) !important;
}

.form-select option{
    background:#11152d !important;
    color:#ffffff !important;
}

/* COLOR PICKER */
.form-control-color{
    height:44px !important;
    padding:6px !important;
    cursor:pointer;
}

.color-row{
    gap:12px !important;
}

/* CHECKBOX GRID */
.check-grid{
    gap:12px !important;
}

.form-check{
    background:rgba(255,255,255,.045) !important;
    border:1px solid rgba(255,255,255,.08) !important;
    border-radius:12px !important;
    padding:11px 12px 11px 36px !important;
    min-height:44px;
    display:flex;
    align-items:center;
}

.form-check-input{
    background-color:rgba(8,12,30,.95) !important;
    border:1px solid rgba(168,85,247,.45) !important;
    box-shadow:none !important;
    cursor:pointer;
}

.form-check-input:checked{
    background-color:#8b5cf6 !important;
    border-color:#a855f7 !important;
}

.form-check-input:focus{
    box-shadow:0 0 0 3px rgba(168,85,247,.20) !important;
}

/* SAVE BUTTON */
.save-btn{
    background:linear-gradient(135deg,#7c3aed,#a855f7) !important;
    color:#ffffff !important;
    border:0 !important;
    border-radius:12px !important;
    padding:12px 22px !important;
    font-weight:900 !important;
    box-shadow:0 14px 32px rgba(124,58,237,.35) !important;
    transition:all .22s ease;
}

.save-btn:hover{
    transform:translateY(-2px);
    box-shadow:0 18px 42px rgba(168,85,247,.45) !important;
}

/* PREVIEW AREA */
.preview-wrap{
    background:
        radial-gradient(circle at center, rgba(168,85,247,.14), transparent 45%),
        rgba(8,12,30,.38) !important;
    border:1px solid rgba(255,255,255,.07);
    border-radius:18px;
    min-height:500px !important;
    padding:34px 20px !important;
}

/* CARD PREVIEW OUTER LOOK */
#cardPreview{
    border:1px solid rgba(255,255,255,.16) !important;
    box-shadow:
        0 25px 70px rgba(0,0,0,.38),
        0 0 42px rgba(168,85,247,.22) !important;
}

/* CARD ELEMENT POLISH */
.card-badge{
    border-radius:14px !important;
    color:#181426 !important;
    box-shadow:0 10px 22px rgba(0,0,0,.22) !important;
}

.card-chip{
    box-shadow:0 10px 22px rgba(0,0,0,.25) !important;
}

.card-title,
.card-member-name,
.card-valid .date{
    text-shadow:0 4px 12px rgba(0,0,0,.35);
}

.card-subtitle,
.card-member-email,
.card-valid .label{
    text-shadow:0 3px 10px rgba(0,0,0,.25);
}

/* ALERTS */
.alert{
    border-radius:14px !important;
    border:1px solid rgba(255,255,255,.12) !important;
    box-shadow:0 14px 30px rgba(0,0,0,.25) !important;
    padding:16px 18px !important;
}

.alert-success{
    background:rgba(16,185,129,.16) !important;
    color:#bbf7d0 !important;
}

.alert-danger{
    background:rgba(239,68,68,.16) !important;
    color:#fecaca !important;
}

/* FILE INPUT BUTTON */
input[type="file"]::file-selector-button{
    background:linear-gradient(135deg,#7c3aed,#a855f7);
    color:#fff;
    border:0;
    border-radius:10px;
    padding:8px 12px;
    margin-right:12px;
    font-weight:700;
    cursor:pointer;
}

/* SCROLLBAR */
.content-wrapper::-webkit-scrollbar{
    width:8px;
}

.content-wrapper::-webkit-scrollbar-thumb{
    background:rgba(168,85,247,.55);
    border-radius:999px;
}

.content-wrapper::-webkit-scrollbar-track{
    background:rgba(255,255,255,.04);
}

/* RESPONSIVE */
@media (max-width:1200px){
    .builder-grid{
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

    .color-row,
    .check-grid{
        grid-template-columns:1fr !important;
    }

    .panel-body{
        padding:16px !important;
    }

    .preview-wrap{
        padding:22px 10px !important;
        overflow:auto;
    }

    #cardPreview{
        transform:scale(.78);
        transform-origin:top center;
    }
}
    
    
    /* =========================================================
   MEMBERSHIP CARD BUILDER - FINAL LIGHT MODE
   Is block ko final  se just pehle rakhein.
   Actual #cardPreview design ko change nahi karta.
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

/* =========================================================
   PAGE TITLE
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body .page-subtitle{
    color:#64748b !important;
}

/* =========================================================
   BUILDER PANELS
========================================================= */

html[data-pos-theme="light"] body .panel-box{
    background:#ffffff !important;
    background-image:none !important;
    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .panel-box:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 36px rgba(15,23,42,.09) !important;
}

/* Panel heading */
html[data-pos-theme="light"] body .panel-head{
    background:#f8fafc !important;
    color:#0f172a !important;

    border-bottom:1px solid #e2e8f0 !important;
}

/* Panel body */
html[data-pos-theme="light"] body .panel-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* =========================================================
   FORM LABELS
========================================================= */

html[data-pos-theme="light"] body .form-label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body .form-check-label{
    color:#475569 !important;
}

/* =========================================================
   TEXT INPUTS AND SELECTS
========================================================= */

html[data-pos-theme="light"] body .form-control,
html[data-pos-theme="light"] body .form-select{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .form-control::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .form-control:hover,
html[data-pos-theme="light"] body .form-select:hover{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] body .form-control:focus,
html[data-pos-theme="light"] body .form-select:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .form-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* =========================================================
   COLOR PICKERS
========================================================= */

html[data-pos-theme="light"] body .form-control-color{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
}

/* =========================================================
   FILE UPLOAD INPUTS
========================================================= */

html[data-pos-theme="light"] body input[type="file"]{
    background:#ffffff !important;
    color:#475569 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"]
body input[type="file"]::file-selector-button{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border:1px solid #c4b5fd !important;
    border-radius:9px !important;

    padding:8px 12px !important;
    margin-right:12px !important;

    font-weight:700 !important;
}

html[data-pos-theme="light"]
body input[type="file"]::file-selector-button:hover{
    background:#7c3aed !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

/* =========================================================
   CHECKBOX CARDS
========================================================= */

html[data-pos-theme="light"] body .form-check{
    background:#f8fafc !important;
    color:#334155 !important;

    border:1px solid #dce4ef !important;
}

html[data-pos-theme="light"] body .form-check:hover{
    background:#f3efff !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body .form-check-input{
    background-color:#ffffff !important;
    border-color:#94a3b8 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
}

html[data-pos-theme="light"] body .form-check-input:focus{
    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.16) !important;
}

/* =========================================================
   SAVE BUTTON
========================================================= */

html[data-pos-theme="light"] body .save-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border:none !important;

    box-shadow:
        0 12px 26px rgba(124,58,237,.22) !important;
}

html[data-pos-theme="light"] body .save-btn:hover{
    color:#ffffff !important;

    box-shadow:
        0 16px 34px rgba(124,58,237,.30) !important;
}

/* =========================================================
   LIVE PREVIEW CONTAINER
   Actual card colors ko change nahi karega.
========================================================= */

html[data-pos-theme="light"] body .preview-wrap{
    background:
        radial-gradient(
            circle at center,
            rgba(124,58,237,.08),
            transparent 48%
        ),
        #f8fafc !important;

    border:1px solid #dce4ef !important;
    box-shadow:inset 0 1px 0 #ffffff !important;
}

/*
|--------------------------------------------------------------------------
| IMPORTANT:
| #cardPreview ka background override nahi karna.
| Iska background JavaScript aur user-selected colors se aata hai.
|--------------------------------------------------------------------------
*/

html[data-pos-theme="light"] body #cardPreview{
    border:1px solid rgba(15,23,42,.15) !important;

    box-shadow:
        0 24px 55px rgba(15,23,42,.20),
        0 0 30px rgba(124,58,237,.12) !important;
}

/* =========================================================
   ALERTS
========================================================= */

html[data-pos-theme="light"] body .alert{
    box-shadow:0 10px 24px rgba(15,23,42,.08) !important;
}

html[data-pos-theme="light"] body .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;
    border:1px solid #fecdd3 !important;
}

/* =========================================================
   SCROLLBAR
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
                <?php if (isset($_SESSION['success'])): ?><div class="alert alert-success">
                    <?= e($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger">
                    <?= e($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

                <div class="page-title">Membership Card Builder</div>
                <div class="page-subtitle">Har membership plan ka apna card design save hoga.</div>

                <div class="builder-grid">
                    <div class="panel-box">
                        <div class="panel-head">Template Controls</div>
                        <div class="panel-body">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="save">

                                <div class="mb-3">
                                    <label class="form-label">Select Membership</label>
                                    <select class="form-select" name="membership_id"
                                        onchange="window.location='membership_card_template.php?membership_id='+this.value">
                                        <?php foreach ($memberships as $m): ?>
                                        <option value="<?= (int)$m['id'] ?>"
                                            <?= $membership_id == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3"><label class="form-label">Template Name</label><input type="text"
                                        class="form-control live-control" name="template_name" id="template_name"
                                        value="<?= e($currentDesign['template_name']) ?>"></div>
                                <div class="mb-3"><label class="form-label">Badge Text</label><input type="text"
                                        class="form-control live-control" name="badge_text" id="badge_text"
                                        value="<?= e($currentDesign['badge_text']) ?>"></div>
                                <div class="mb-3"><label class="form-label">Title</label><input type="text"
                                        class="form-control live-control" name="title_text" id="title_text"
                                        value="<?= e($currentDesign['title_text']) ?>"></div>
                                <div class="mb-3"><label class="form-label">Subtitle</label><input type="text"
                                        class="form-control live-control" name="subtitle_text" id="subtitle_text"
                                        value="<?= e($currentDesign['subtitle_text']) ?>"></div>

                                <div class="mb-3 color-row">
                                    <div><label class="form-label">Primary</label><input type="color"
                                            class="form-control form-control-color live-control"
                                            name="background_color_1" id="background_color_1"
                                            value="<?= e($currentDesign['background_color_1']) ?>"></div>
                                    <div><label class="form-label">Secondary</label><input type="color"
                                            class="form-control form-control-color live-control"
                                            name="background_color_2" id="background_color_2"
                                            value="<?= e($currentDesign['background_color_2']) ?>"></div>
                                    <div><label class="form-label">Accent</label><input type="color"
                                            class="form-control form-control-color live-control" name="accent_color"
                                            id="accent_color" value="<?= e($currentDesign['accent_color']) ?>"></div>
                                </div>

                                <div class="mb-3 color-row">
                                    <div><label class="form-label">Text</label><input type="color"
                                            class="form-control form-control-color live-control" name="text_color"
                                            id="text_color" value="<?= e($currentDesign['text_color']) ?>"></div>
                                    <div><label class="form-label">Muted</label><input type="color"
                                            class="form-control form-control-color live-control" name="muted_text_color"
                                            id="muted_text_color" value="<?= e($currentDesign['muted_text_color']) ?>">
                                    </div>
                                    <div></div>
                                </div>

                                <div class="mb-3"><label class="form-label">Logo</label><input type="file"
                                        class="form-control" name="logo_file"></div>
                                <div class="mb-3"><label class="form-label">Background Image</label><input type="file"
                                        class="form-control" name="background_file"></div>

                                <div class="mb-3 check-grid">
                                    <label class="form-check"><input class="form-check-input live-toggle"
                                            type="checkbox" name="show_badge" id="show_badge"
                                            <?= !empty($currentDesign['show_badge']) ? 'checked' : '' ?>><span
                                            class="form-check-label">Show Badge</span></label>
                                    <label class="form-check"><input class="form-check-input live-toggle"
                                            type="checkbox" name="show_chip" id="show_chip"
                                            <?= !empty($currentDesign['show_chip']) ? 'checked' : '' ?>><span
                                            class="form-check-label">Show Chip</span></label>
                                    <label class="form-check"><input class="form-check-input live-toggle"
                                            type="checkbox" name="show_email" id="show_email"
                                            <?= !empty($currentDesign['show_email']) ? 'checked' : '' ?>><span
                                            class="form-check-label">Show Email</span></label>
                                    <label class="form-check"><input class="form-check-input live-toggle"
                                            type="checkbox" name="show_validity" id="show_validity"
                                            <?= !empty($currentDesign['show_validity']) ? 'checked' : '' ?>><span
                                            class="form-check-label">Show Validity</span></label>
                                    <label class="form-check"><input class="form-check-input live-toggle"
                                            type="checkbox" name="show_glow" id="show_glow"
                                            <?= !empty($currentDesign['show_glow']) ? 'checked' : '' ?>><span
                                            class="form-check-label">Glow</span></label>
                                    <label class="form-check"><input class="form-check-input live-toggle"
                                            type="checkbox" name="show_top_sparkles" id="show_top_sparkles"
                                            <?= !empty($currentDesign['show_top_sparkles']) ? 'checked' : '' ?>><span
                                            class="form-check-label">Sparkles</span></label>
                                </div>

                                <div class="action-row mt-4"><button type="submit" class="save-btn">Save
                                        Template</button></div>
                            </form>
                        </div>
                    </div>

                    <div class="panel-box">
                        <div class="panel-head">Live Preview</div>
                        <div class="panel-body">
                            <div class="preview-wrap">
                                <div id="cardPreview">
                                    <?php if (!empty($currentBgImage)): ?><img src="/<?= e($currentBgImage) ?>"
                                        class="bg-image" id="previewBgImage" alt="background"><?php endif; ?>
                                    <div class="sparkles" id="previewSparkles"></div>
                                    <div class="gold-curves" id="previewCurves"></div>
                                    <div class="content-layer">
                                        <?php if (!empty($currentLogo)): ?><img src="/<?= e($currentLogo) ?>"
                                            class="card-logo" id="previewLogo" alt="logo"><?php endif; ?>
                                        <div class="card-badge" id="previewBadge"><?= e($currentDesign['badge_text']) ?>
                                        </div>
                                        <div class="card-chip" id="previewChip"></div>
                                        <div class="card-title" id="previewTitle"><?= e($currentDesign['title_text']) ?>
                                        </div>
                                        <div class="card-subtitle" id="previewSubtitle">
                                            <?= e($currentDesign['subtitle_text']) ?></div>
                                        <div class="card-member-name" id="previewMemberName">CUSTOMER NAME</div>
                                        <div class="card-member-email" id="previewMemberEmail">customer@email.com</div>
                                        <div class="card-valid" id="previewValid"><span class="label">Valid
                                                Thru</span><span class="date">12/25</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function getValue(id) {
        const el = document.getElementById(id);
        return el ? el.value : '';
    }

    function getChecked(id) {
        const el = document.getElementById(id);
        return el ? el.checked : false;
    }

    function updatePreview() {
        const card = document.getElementById('cardPreview');
        const c1 = getValue('background_color_1'),
            c2 = getValue('background_color_2');
        const accent = getValue('accent_color'),
            textColor = getValue('text_color'),
            muted = getValue('muted_text_color');
        card.style.width = '430px';
        card.style.height = '270px';
        card.style.borderRadius = '26px';
        card.style.background = `linear-gradient(135deg, ${c1}, ${c2})`;
        card.style.boxShadow = getChecked('show_glow') ? `0 18px 40px rgba(0,0,0,.18), 0 0 25px ${accent}55` :
            '0 18px 40px rgba(0,0,0,.18)';
        const badge = document.getElementById('previewBadge');
        badge.textContent = getValue('badge_text');
        badge.style.display = getChecked('show_badge') ? 'block' : 'none';
        badge.style.background = `linear-gradient(135deg, ${accent}, #fff3b0)`;
        document.getElementById('previewChip').style.display = getChecked('show_chip') ? 'block' : 'none';
        document.getElementById('previewSparkles').style.display = getChecked('show_top_sparkles') ? 'block' : 'none';
        document.getElementById('previewTitle').textContent = getValue('title_text');
        document.getElementById('previewTitle').style.color = textColor;
        document.getElementById('previewSubtitle').textContent = getValue('subtitle_text');
        document.getElementById('previewSubtitle').style.color = muted;
        document.getElementById('previewMemberName').style.color = textColor;
        document.getElementById('previewMemberEmail').style.color = muted;
        document.getElementById('previewMemberEmail').style.display = getChecked('show_email') ? 'block' : 'none';
        document.getElementById('previewValid').style.display = getChecked('show_validity') ? 'block' : 'none';
        document.getElementById('previewValid').style.color = textColor;
    }
    document.querySelectorAll('.live-control,.live-toggle').forEach(el => {
        el.addEventListener('input', updatePreview);
        el.addEventListener('change', updatePreview);
    });
    updatePreview();
    </script>
</body>

</html>