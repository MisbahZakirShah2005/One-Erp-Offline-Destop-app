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

$client_id = $_SESSION['client_id'] ?? 0;
$user_name  = $_SESSION['name'] ?? 'User';

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, $tableName) {
    $tableName = $conn->real_escape_string($tableName);
    $result    = $conn->query("SHOW TABLES LIKE '{$tableName}'");
    return $result && $result->num_rows > 0;
}

function columnExists($conn, $table, $column) {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

function fetchIntegrationSettings($conn, $client_id) {
    $settings = [];
    if (!tableExists($conn, 'integrations')) return $settings;
    $res = $conn->query("SELECT * FROM integrations WHERE client_id = " . (int)$client_id);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $settings[$row['integration_type']] = $row;
        }
    }
    return $settings;
}

/*
|--------------------------------------------------------------------------
| MASTER GATEWAYS
|--------------------------------------------------------------------------
*/
$gatewayCatalog = [
    ['name' => 'JazzCash',      'slug' => 'JazzCash',      'icon' => 'https://vectorseek.com/wp-content/uploads/2024/12/JazzCash-2025-Logo-PNG-Vector.png',                                                                      'fees' => '1.5%', 'type' => 'wallet'],
    ['name' => 'Easypaisa',     'slug' => 'Easypaisa',     'icon' => 'https://www.vhv.rs/dpng/d/473-4736946_easypaisa-logo-png-transparent-png.png',                                                                             'fees' => '1.0%', 'type' => 'wallet'],
    ['name' => 'HBL Pay',       'slug' => 'HBL Pay',       'icon' => 'https://commercemarketplace.adobe.com/media/catalog/product/6/f/6f0b_hblpaypg.jpg',                                                                        'fees' => '1.9%', 'type' => 'gateway'],
    ['name' => 'Bank Transfer', 'slug' => 'Bank Transfer', 'icon' => 'https://cdn.prod.website-files.com/64199d190fc7afa82666d89c/648b63af41676287e601542d_regular-bank-transfer.webp', 'fees' => '0%',   'type' => 'bank'],
];

/*
|--------------------------------------------------------------------------
| AJAX: SAVE / TOGGLE DEFAULT / DELETE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!tableExists($conn, 'integrations')) {
        echo json_encode(['success' => false, 'message' => 'integrations table not found.']);
        exit;
    }

    $action = $_POST['action'];

    /* ---------- save_gateway ---------- */
    if ($action === 'save_gateway') {
        $selected_gateway = trim($_POST['selected_gateway'] ?? '');
        $gateway_name     = trim($_POST['gateway_name']     ?? '');
        $api_key          = trim($_POST['api_key']          ?? '');
        $api_secret       = trim($_POST['api_secret']       ?? '');
        $api_token        = trim($_POST['api_token']        ?? '');
        $status           = isset($_POST['enabled'])     ? 1 : 0;
        $is_default       = isset($_POST['is_default'])  ? 1 : 0;
        $now              = date('Y-m-d H:i:s');

        if ($selected_gateway === '') {
            echo json_encode(['success' => false, 'message' => 'Select payment gateway first.']);
            exit;
        }
        if ($gateway_name === '') $gateway_name = $selected_gateway;

        $has_gateway_name = columnExists($conn, 'integrations', 'gateway_name');
        $has_is_default   = columnExists($conn, 'integrations', 'is_default');

        if ($is_default && $has_is_default) {
            $stmt = $conn->prepare("UPDATE integrations SET is_default = 0 WHERE client_id = ?");
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT id FROM integrations WHERE client_id = ? AND integration_type = ? LIMIT 1");
        $stmt->bind_param("is", $client_id, $selected_gateway);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        if ($exists) {
            if ($has_gateway_name && $has_is_default) {
                $sql  = "UPDATE integrations SET gateway_name=?,api_key=?,api_secret=?,api_token=?,status=?,is_default=?,updated_at=? WHERE client_id=? AND integration_type=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssiisis", $gateway_name, $api_key, $api_secret, $api_token, $status, $is_default, $now, $client_id, $selected_gateway);
            } elseif ($has_gateway_name) {
                $sql  = "UPDATE integrations SET gateway_name=?,api_key=?,api_secret=?,api_token=?,status=?,updated_at=? WHERE client_id=? AND integration_type=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssisis", $gateway_name, $api_key, $api_secret, $api_token, $status, $now, $client_id, $selected_gateway);
            } elseif ($has_is_default) {
                $sql  = "UPDATE integrations SET api_key=?,api_secret=?,api_token=?,status=?,is_default=?,updated_at=? WHERE client_id=? AND integration_type=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssiiis is", $api_key, $api_secret, $api_token, $status, $is_default, $now, $client_id, $selected_gateway);
            } else {
                $sql  = "UPDATE integrations SET api_key=?,api_secret=?,api_token=?,status=?,updated_at=? WHERE client_id=? AND integration_type=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssisis", $api_key, $api_secret, $api_token, $status, $now, $client_id, $selected_gateway);
            }
        } else {
            if ($has_gateway_name && $has_is_default) {
                $sql  = "INSERT INTO integrations (client_id,integration_type,gateway_name,api_key,api_secret,api_token,status,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssssiiss", $client_id, $selected_gateway, $gateway_name, $api_key, $api_secret, $api_token, $status, $is_default, $now, $now);
            } elseif ($has_gateway_name) {
                $sql  = "INSERT INTO integrations (client_id,integration_type,gateway_name,api_key,api_secret,api_token,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssssiss", $client_id, $selected_gateway, $gateway_name, $api_key, $api_secret, $api_token, $status, $now, $now);
            } elseif ($has_is_default) {
                $sql  = "INSERT INTO integrations (client_id,integration_type,api_key,api_secret,api_token,status,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issssiiss", $client_id, $selected_gateway, $api_key, $api_secret, $api_token, $status, $is_default, $now, $now);
            } else {
                $sql  = "INSERT INTO integrations (client_id,integration_type,api_key,api_secret,api_token,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issssiss", $client_id, $selected_gateway, $api_key, $api_secret, $api_token, $status, $now, $now);
            }
        }

        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => $stmt->error]);
            $stmt->close();
            exit;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Gateway saved successfully']);
        exit;
    }

    /* ---------- toggle_status ---------- */
    if ($action === 'toggle_status') {
        $gateway = trim($_POST['gateway'] ?? '');
        $status  = (int)($_POST['status'] ?? 0);
        $now     = date('Y-m-d H:i:s');
        $stmt    = $conn->prepare("UPDATE integrations SET status=?,updated_at=? WHERE client_id=? AND integration_type=?");
        $stmt->bind_param("isis", $status, $now, $client_id, $gateway);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }

    /* ---------- set_default ---------- */
    if ($action === 'set_default') {
        $gateway = trim($_POST['gateway'] ?? '');
        if (!columnExists($conn, 'integrations', 'is_default')) {
            echo json_encode(['success' => false, 'message' => 'is_default column not found']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE integrations SET is_default=0 WHERE client_id=?");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $stmt->close();

        $now  = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE integrations SET is_default=1,updated_at=? WHERE client_id=? AND integration_type=?");
        $stmt->bind_param("sis", $now, $client_id, $gateway);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD SETTINGS & BUILD GATEWAY CARDS
|--------------------------------------------------------------------------
*/
$settings = fetchIntegrationSettings($conn, $client_id);

$gatewayCards   = [];
$activeCount    = 0;
$defaultGateway = '';

foreach ($gatewayCatalog as $item) {
    $row    = $settings[$item['name']] ?? null;
    $status = isset($row['status'])     ? (int)$row['status']     : 0;
    $isDef  = isset($row['is_default']) ? (int)$row['is_default'] : 0;
    if ($status === 1) $activeCount++;
    if ($isDef  === 1) $defaultGateway = $item['name'];

    $gatewayCards[] = [
        'name'         => $item['name'],
        'slug'         => $item['slug'],
        'icon'         => $item['icon'],
        'fees'         => $item['fees'],
        'type'         => $item['type'],
        'status'       => $status,
        'is_default'   => $isDef,
        'gateway_name' => $row['gateway_name'] ?? $item['name'],
        'api_key'      => $row['api_key']      ?? '',
        'api_secret'   => $row['api_secret']   ?? '',
        'api_token'    => $row['api_token']     ?? '',
        'updated_at'   => $row['updated_at']   ?? '',
    ];
}

if ($defaultGateway === '' && !empty($gatewayCards)) {
    foreach ($gatewayCards as $g) {
        if ((int)$g['status'] === 1) { $defaultGateway = $g['name']; break; }
    }
    if ($defaultGateway === '') $defaultGateway = $gatewayCards[0]['name'];
}

/*
|--------------------------------------------------------------------------
| RECENT TRANSACTIONS
|--------------------------------------------------------------------------
*/
$recentTransactions = [];
$totalTransactions  = 94;
$totalCollection    = 246300;

if (tableExists($conn, 'payments')) {
    $q = $conn->query("SELECT payment_method,amount,created_at,status FROM payments WHERE client_id=".(int)$client_id." ORDER BY id DESC LIMIT 7");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $recentTransactions[] = [
                'payment_method' => $row['payment_method'] ?: 'Unknown',
                'amount'         => (float)$row['amount'],
                'date'           => $row['created_at'],
                'status'         => $row['status'] ?: 'Paid',
            ];
        }
    }
    $cr = $conn->query("SELECT COUNT(*) AS total FROM payments WHERE client_id=".(int)$client_id);
    if ($cr && $r = $cr->fetch_assoc()) $totalTransactions = (int)$r['total'];

    $sr = $conn->query("SELECT SUM(amount) AS t FROM payments WHERE client_id=".(int)$client_id." AND LOWER(status)='paid'");
    if ($sr && $r = $sr->fetch_assoc()) $totalCollection = (float)($r['t'] ?? 0);
}

// if (empty($recentTransactions)) {
//     $recentTransactions = [
//         ['payment_method' => 'JazzCash',      'amount' => 4000, 'date' => 'Today',      'status' => 'Paid'],
//         ['payment_method' => 'Easypaisa',     'amount' => 2200, 'date' => 'Today',      'status' => 'Pending'],
//         ['payment_method' => 'Bank Transfer', 'amount' => 6000, 'date' => 'Yesterday',  'status' => 'Paid'],
//         ['payment_method' => 'JazzCash',      'amount' => 1500, 'date' => '22/04/2024', 'status' => 'Paid'],
//         ['payment_method' => 'HBL Pay',       'amount' => 3000, 'date' => '21/04/2024', 'status' => 'Failed'],
//     ];
// }

/* Filters (GET) */
$gatewayFilter = $_GET['gateway']  ?? '';
$statusFilter  = $_GET['status']   ?? '';
$dateFilter    = $_GET['date']     ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Integrations</title>
    <script>
(function () {
    const root = document.documentElement;

    function detectTheme() {
        let savedTheme = null;

        try {
            savedTheme =
                localStorage.getItem('oneerp_pos_theme') ||
                localStorage.getItem('oneerp_theme') ||
                localStorage.getItem('theme');
        } catch (error) {
            savedTheme = null;
        }

        if (savedTheme === 'light' || savedTheme === 'dark') {
            return savedTheme;
        }

        const body = document.body;

        const currentTheme =
            root.getAttribute('data-pos-theme') ||
            root.getAttribute('data-theme') ||
            body?.getAttribute('data-pos-theme') ||
            body?.getAttribute('data-theme');

        if (currentTheme === 'light' || currentTheme === 'dark') {
            return currentTheme;
        }

        const lightClassExists =
            root.classList.contains('light') ||
            root.classList.contains('light-theme') ||
            root.classList.contains('light-mode') ||
            body?.classList.contains('light') ||
            body?.classList.contains('light-theme') ||
            body?.classList.contains('light-mode');

        return lightClassExists ? 'light' : 'light';
    }

    function applyPaymentTheme() {
        const selectedTheme = detectTheme();

        if (root.getAttribute('data-pos-theme') !== selectedTheme) {
            root.setAttribute('data-pos-theme', selectedTheme);
        }

        if (
            document.body &&
            document.body.getAttribute('data-pos-theme') !== selectedTheme
        ) {
            document.body.setAttribute(
                'data-pos-theme',
                selectedTheme
            );
        }
    }

    applyPaymentTheme();

    document.addEventListener(
        'DOMContentLoaded',
        applyPaymentTheme
    );

    window.addEventListener(
        'storage',
        applyPaymentTheme
    );

    document.addEventListener(
        'click',
        function () {
            setTimeout(applyPaymentTheme, 80);
        },
        true
    );
})();
</script>
    <?php if (file_exists('partials/head.php')) include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* ===== BASE ===== */
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f7; font-family: Inter, Arial, sans-serif; color: #1f2736; }
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
        .main-panel { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .topbar { height: 64px; background: #05070d; color: #fff; display: flex; align-items: center; padding: 0 20px; }
        .content-wrapper { padding: 18px; flex: 1; overflow: auto; }

        /* ===== PAGE HEADER ===== */
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; }
        .page-title  { font-size: 22px; font-weight: 800; margin: 0; color: #243046; }
        .btn-green   { border: none; border-radius: 12px; background: linear-gradient(180deg,#53ae6e,#3e8f58); color: #fff; font-weight: 700; padding: 12px 18px; min-width: 190px; cursor: pointer; }

        /* ===== STATS ===== */
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 16px; }
        .stat-card  { border-radius: 14px; padding: 18px; min-height: 92px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 14px 28px rgba(0,0,0,.12); }
        .stat-card::after { content:''; position:absolute; width:110px; height:110px; border-radius:50%; right:-18px; top:-18px; background:rgba(255,255,255,.12); }
        .stat-blue  { background: linear-gradient(135deg,#5e84ff,#7fa9ff); }
        .stat-green { background: linear-gradient(135deg,#4ea98d,#66c09f); }
        .stat-cyan  { background: linear-gradient(135deg,#56b4da,#7fd4f5); }
        .stat-gold  { background: linear-gradient(135deg,#8e7f73,#b9a38b); }
        .stat-title { font-size: 14px; font-weight: 600; line-height: 1.3; }
        .stat-value { position: absolute; right: 18px; bottom: 14px; font-size: 28px; font-weight: 800; line-height: 1; }
        .stat-sub   { margin-top: 6px; font-size: 13px; opacity: .9; }

        /* ===== FILTER CARD ===== */
        .filter-card { background: #fff; border: 1px solid #e6ebf3; border-radius: 16px; box-shadow: 0 8px 22px rgba(18,38,63,.05); padding: 14px; margin-bottom: 16px; }
        .filter-row  { display: grid; grid-template-columns: 1fr 1fr 1fr 130px 1fr 120px 120px; gap: 10px; margin-bottom: 10px; }
        .erp-select, .erp-input { height: 44px; border: 1px solid #dbe2ed; border-radius: 10px; background: #fff; padding: 0 14px; outline: none; width: 100%; }
        .filter-btn  { height: 44px; border: none; border-radius: 10px; background: #1f2642; color: #fff; font-weight: 700; cursor: pointer; }
        .reset-btn   { height: 44px; border: none; border-radius: 10px; background: #f2f4fa; color: #4b566d; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }

        /* ===== GATEWAY CARDS GRID ===== */
        .gateway-section-title { font-size: 17px; font-weight: 800; color: #243046; margin-bottom: 12px; }
        .gateway-grid  { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
        .gw-card       { background: #fff; border: 1px solid #e4eaf3; border-radius: 14px; padding: 16px; box-shadow: 0 6px 18px rgba(18,38,63,.06); display: flex; flex-direction: column; gap: 10px; }
        .gw-card-top   { display: flex; justify-content: space-between; align-items: flex-start; }
        .gw-logo       { max-height: 38px; max-width: 120px; object-fit: contain; }
        .gw-name       { font-size: 16px; font-weight: 800; color: #1a2030; margin: 0; }
        .gw-sub        { font-size: 13px; color: #6a7585; margin: 0; }
        .gw-info-row   { display: flex; justify-content: space-between; font-size: 12px; color: #8a95a8; }
        .gw-actions    { display: flex; gap: 8px; flex-wrap: wrap; }
        .gw-btn        { flex: 1; height: 36px; border-radius: 8px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 5px; }
        .gw-btn-dark   { background: #1a1f2e; color: #f3e3c0; }
        .gw-btn-light  { background: #f2f4fa; color: #2c2d30; border: 1px solid #dbe2ed; }
        .gw-btn-dark:hover  { background: #252d42; }
        .gw-btn-light:hover { background: #e7eaf3; }

        /* Status dot + pill */
        .dot-wrap   { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #546077; }
        .dot        { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
        .dot.green  { background: #44af47; }
        .dot.gray   { background: #9ca3af; }

        /* Toggle switch */
        .switch { position: relative; width: 50px; height: 27px; display: inline-block; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; inset: 0; cursor: pointer; background: #c4c9d8; border-radius: 40px; transition: .25s; }
        .slider:before { content:''; position:absolute; width:21px; height:21px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.25s; box-shadow:0 2px 5px rgba(0,0,0,.2); }
        .switch input:checked + .slider { background: linear-gradient(180deg,#56b357,#2c8331); }
        .switch input:checked + .slider:before { transform: translateX(23px); }

        /* Default select row */
        .default-row  { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; font-size: 15px; font-weight: 600; color: #243046; }
        .default-select { height: 44px; border: 1px solid #dbe2ed; border-radius: 10px; background: #fff; padding: 0 14px; min-width: 220px; outline: none; }

        /* ===== TABLE CARD ===== */
        .table-card  { background: #fff; border: 1px solid #e6ebf3; border-radius: 16px; box-shadow: 0 8px 22px rgba(18,38,63,.05); padding: 0; overflow: hidden; }
        .table-card-head { padding: 14px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #edf1f6; }
        .table-card-title { font-size: 16px; font-weight: 800; color: #253046; margin: 0; }
        .table-wrap  { overflow: auto; }
        .erp-table   { width: 100%; border-collapse: separate; border-spacing: 0; }
        .erp-table thead th { background: #1a1f2e; color: #fff; padding: 13px 12px; font-size: 14px; font-weight: 600; white-space: nowrap; }
        .erp-table tbody td { background: #fff; padding: 13px 12px; border-bottom: 1px solid #edf1f6; vertical-align: middle; white-space: nowrap; }
        .erp-table tbody tr:hover td { background: #fafcff; }
        .erp-table thead th:first-child { border-top-left-radius: 12px; }
        .erp-table thead th:last-child  { border-top-right-radius: 12px; }

        .method-cell { display: flex; align-items: center; gap: 10px; }
        .method-icon { width: 24px; height: 24px; object-fit: contain; border-radius: 4px; background: #f5f5f5; padding: 2px; }

        .badge-status  { font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 999px; display: inline-block; min-width: 74px; text-align: center; }
        .badge-paid    { background: #dcefdc; color: #2c6b38; }
        .badge-pending { background: #f7e4a8; color: #916800; }
        .badge-failed  { background: #fde8e8; color: #b53b3b; }

        /* table footer */
        .table-footer { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding: 14px 16px; border-top: 1px solid #edf1f6; }
        .footer-left  { color: #546077; font-weight: 500; }
        .footer-right { display: flex; align-items: center; gap: 10px; }
        .pager-btn    { width: 36px; height: 36px; border: none; border-radius: 8px; background: #f4f7fb; color: #334055; cursor: pointer; }
        .pager-num    { min-width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #1a1f2e; color: #fff; font-weight: 800; padding: 0 10px; }
        .icon-btn-sm  { width: 36px; height: 36px; border: 1px solid #dbe2ed; border-radius: 8px; background: #f4f7fb; color: #334055; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }

        /* ===== MODAL ===== */
        .modal-xl-custom { max-width: 860px; }
        .po-modal-content { background: radial-gradient(circle at top left,rgba(25,43,120,.40),transparent 32%), radial-gradient(circle at bottom right,rgba(255,185,0,.12),transparent 28%), linear-gradient(180deg,#060916,#0b1031); border: none; border-radius: 20px; overflow: hidden; color: #fff; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
        .po-modal-header  { padding: 18px 24px; border-bottom: 1px solid rgba(255,255,255,.08); display: flex; justify-content: space-between; align-items: center; }
        .po-modal-title   { font-size: 18px; font-weight: 700; margin: 0; color: #fff; }
        .po-close         { background: none; border: none; color: #d8def2; font-size: 28px; line-height: 1; cursor: pointer; }
        .po-modal-body    { padding: 20px 24px 0; }
        .dark-label       { font-size: 13px; font-weight: 600; color: #f4f6fd; margin-bottom: 6px; display: block; }
        .req              { color: #ffbf43; }
        .dark-input, .dark-select { width: 100%; border: none; outline: none; border-radius: 8px; background: #f3f5fa; color: #1d2434; padding: 12px 14px; font-size: 14px; }
        .password-wrap { position: relative; }
        .toggle-eye    { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6a7585; font-size: 18px; cursor: pointer; }
        .switch-row-modal { display: flex; align-items: center; gap: 10px; margin-top: 4px; font-size: 14px; font-weight: 600; color: #f4f6fd; }
        .po-modal-footer  { padding: 16px 24px; border-top: 1px solid rgba(255,255,255,.08); display: flex; justify-content: flex-end; gap: 12px; margin-top: 12px; }
        .cancel-btn { min-width: 120px; height: 44px; border-radius: 10px; border: none; background: #5d617a; color: #fff; font-weight: 700; cursor: pointer; }
        .save-btn   { min-width: 140px; height: 44px; border-radius: 10px; border: none; background: linear-gradient(180deg,#53ae6e,#3e8f58); color: #fff; font-weight: 800; cursor: pointer; }

        /* Responsive */
        @media(max-width:1200px){.stats-grid,.gateway-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:991px){.filter-row{grid-template-columns:1fr 1fr}.gateway-grid{grid-template-columns:1fr}}
        @media(max-width:768px){.page-header{flex-direction:column;align-items:stretch}.stats-grid{grid-template-columns:1fr}.filter-row{grid-template-columns:1fr}.default-row{flex-direction:column;align-items:flex-start}}
    

/* =========================================================
   PAYMENT INTEGRATIONS POPUP BLACK + GOLD + WHITE THEME OVERRIDE
   Add/Edit Gateway Modal
   Backend/PHP/JS same - only popup theme
========================================================= */

.modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
}

#gatewayModal .modal-dialog{
    max-width:min(900px, 94vw) !important;
}

#gatewayModal .po-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.55) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 30px 90px rgba(0,0,0,.65) !important;
}

#gatewayModal .po-modal-header{
    background:linear-gradient(180deg,#161b28 0%, #0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
}

#gatewayModal .po-modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

#gatewayModal .po-modal-title::before{
    content:'';
    width:13px;
    height:13px;
    border-radius:50%;
    background:#c9a84c;
    box-shadow:0 0 0 5px rgba(201,168,76,.15);
    display:inline-block;
    flex-shrink:0;
}

/* Proper visible close X */
#gatewayModal .po-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.12) !important;
    color:transparent !important;
    opacity:1 !important;
    box-shadow:none !important;
    padding:0 !important;
    font-size:0 !important;
}

#gatewayModal .po-close::before,
#gatewayModal .po-close::after{
    content:'';
    position:absolute;
    top:50%;
    left:50%;
    width:22px;
    height:2px;
    background:#ffffff;
    border-radius:2px;
    transform-origin:center;
}

#gatewayModal .po-close::before{
    transform:translate(-50%,-50%) rotate(45deg);
}

#gatewayModal .po-close::after{
    transform:translate(-50%,-50%) rotate(-45deg);
}

#gatewayModal .po-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px);
}

#gatewayModal .po-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 14px !important;
}

#gatewayModal .po-modal-body::-webkit-scrollbar{
    width:7px;
}

#gatewayModal .po-modal-body::-webkit-scrollbar-track{
    background:#171c28;
    border-radius:999px;
}

#gatewayModal .po-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c;
    border-radius:999px;
}

#gatewayModal .po-modal-body::-webkit-scrollbar-thumb:hover{
    background:#e8bb34;
}

#gatewayModal .po-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.25) !important;
    padding:18px 24px 22px !important;
}

#gatewayModal .dark-label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#gatewayModal .req{
    color:#f5c742 !important;
}

#gatewayModal .dark-input,
#gatewayModal .dark-select{
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

#gatewayModal .dark-input::placeholder{
    color:#8993a5 !important;
}

#gatewayModal .dark-input:focus,
#gatewayModal .dark-select:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24), 0 5px 14px rgba(0,0,0,.22) !important;
}

/* Password / key eye button */
#gatewayModal .password-wrap{
    position:relative !important;
}

#gatewayModal .toggle-eye{
    right:12px !important;
    width:34px !important;
    height:34px !important;
    border-radius:8px !important;
    background:#f4f6fb !important;
    border:1px solid #d9dfec !important;
    color:#111827 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}

#gatewayModal .toggle-eye:hover{
    background:#fff7df !important;
    border-color:#c9a84c !important;
    color:#8a6a20 !important;
}

#gatewayModal .toggle-eye i{
    color:inherit !important;
}

/* Switch row */
#gatewayModal .switch-row-modal{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.35) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    color:#ffffff !important;
    font-weight:800 !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#gatewayModal .switch input:checked + .slider{
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
}

#gatewayModal .slider{
    background:#3a4156 !important;
}

#gatewayModal label[style*="display:flex"]{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.35) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    color:#ffffff !important;
    font-weight:800 !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#gatewayModal #is_default{
    accent-color:#d8a500 !important;
}

/* Footer buttons */
#gatewayModal .cancel-btn{
    height:44px !important;
    min-width:110px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#gatewayModal .cancel-btn:hover{
    background:#32394b !important;
}

#gatewayModal .save-btn{
    height:44px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#gatewayModal .save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px);
}

@media (max-width:768px){
    #gatewayModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #gatewayModal .po-modal-title{
        font-size:18px !important;
    }

    #gatewayModal .po-close{
        width:42px !important;
        height:42px !important;
    }
}



/* =========================================================
   PAYMENTS.PHP - DARK PURPLE GLASS HOVER THEME + COMPACT POPUP
   Backend/PHP/JS untouched - final CSS override
========================================================= */
:root{
    --oms-bg:#070b14;
    --oms-panel:#0f172a;
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-yellow:#f59e0b;
    --oms-red:#ef4444;
    --oms-border:rgba(148,163,184,.16);
}

html,body{min-height:100% !important;}
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 14% 0%, rgba(124,58,237,.26), transparent 30%),
        radial-gradient(circle at 82% 10%, rgba(59,130,246,.14), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 42%,#160f2d 72%,#070b14 100%) !important;
    overflow-x:hidden !important;
}
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    width:100% !important;
}
.sidebar{background:transparent !important;overflow:visible !important;}
.main-panel{
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.15), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}
.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
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
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.38;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
}
.content-wrapper > *{position:relative !important;z-index:1 !important;}

/* Header */
.page-header{display:flex !important;justify-content:space-between !important;align-items:center !important;gap:14px !important;flex-wrap:wrap !important;margin-bottom:18px !important;}
.page-title{
    color:#f8fafc !important;
    font-size:30px !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}
.btn-green{
    min-height:50px !important;
    border:none !important;
    border-radius:14px !important;
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 16px 34px rgba(34,197,94,.22),0 0 28px rgba(34,197,94,.12) !important;
    transition:.22s ease !important;
}
.btn-green:hover{transform:translateY(-2px) !important;}

/* Stats */
.stats-grid{display:grid !important;grid-template-columns:repeat(4,1fr) !important;gap:16px !important;margin-bottom:18px !important;}
.stat-card{
    min-height:112px !important;
    color:#fff !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
    isolation:isolate !important;
}
.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(135deg,rgba(255,255,255,.10),transparent 42%),radial-gradient(circle at 86% 10%,rgba(255,255,255,.16),transparent 28%);
    z-index:-1;
}
.stat-card::after{right:14px !important;top:14px !important;width:76px !important;height:76px !important;border-radius:20px !important;background:rgba(255,255,255,.10) !important;}
.stat-card:hover{transform:translateY(-4px) !important;border-color:rgba(255,255,255,.22) !important;filter:saturate(1.08) !important;box-shadow:0 26px 65px rgba(0,0,0,.46),0 0 48px rgba(124,58,237,.18) !important;}
.stat-title{font-size:15px !important;font-weight:900 !important;color:rgba(255,255,255,.94) !important;}
.stat-value{font-size:28px !important;font-weight:900 !important;color:#fff !important;}
.stat-sub{color:rgba(255,255,255,.86) !important;font-weight:800 !important;}
.stat-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.stat-gold{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

/* Filter / default row */
.filter-card,.table-card,.gw-card{
    background:radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    box-shadow:0 18px 48px rgba(0,0,0,.30), inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}
.filter-card{padding:14px 16px !important;margin-bottom:18px !important;}
.filter-row{gap:10px !important;align-items:center !important;}
.erp-select,.erp-input,.default-select{
    height:46px !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    padding:0 14px !important;
    outline:none !important;
    width:100% !important;
    font-weight:800 !important;
    box-shadow:0 8px 22px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.04) !important;
}
.erp-select option,.default-select option{background:#0f172a !important;color:#e5e7eb !important;}
.erp-input::placeholder{color:#94a3b8 !important;}
.erp-select:focus,.erp-input:focus,.default-select:focus{background:rgba(17,24,39,.92) !important;color:#fff !important;border-color:rgba(124,58,237,.62) !important;box-shadow:0 0 0 3px rgba(124,58,237,.16),0 12px 26px rgba(0,0,0,.24) !important;}
.filter-btn,.reset-btn,.gw-btn,.pager-btn,.icon-btn-sm{
    border-radius:13px !important;
    font-weight:900 !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
}
.filter-btn,.gw-btn-dark{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;border:none !important;}
.reset-btn,.gw-btn-light,.pager-btn,.icon-btn-sm{background:rgba(15,23,42,.72) !important;color:#e5e7eb !important;border:1px solid rgba(148,163,184,.18) !important;}
.filter-btn:hover,.reset-btn:hover,.gw-btn:hover,.pager-btn:hover,.icon-btn-sm:hover{transform:translateY(-2px) !important;border-color:rgba(139,92,246,.45) !important;box-shadow:0 18px 40px rgba(0,0,0,.36),0 0 34px rgba(124,58,237,.18) !important;}
.default-row,.gateway-section-title,.table-card-title{color:#f8fafc !important;font-weight:900 !important;}

/* Gateway cards */
.gateway-grid{gap:16px !important;}
.gw-card{padding:18px !important;transition:.25s ease !important;}
.gw-card:hover{transform:translateY(-4px) !important;border-color:rgba(139,92,246,.45) !important;box-shadow:0 24px 65px rgba(0,0,0,.45),0 0 42px rgba(124,58,237,.18),inset 0 1px 0 rgba(255,255,255,.06) !important;}
.gw-name{color:#f8fafc !important;font-weight:900 !important;}
.gw-sub,.gw-info-row,.dot-wrap{color:#cbd5e1 !important;font-weight:700 !important;}
.gw-logo,.method-icon{background:rgba(255,255,255,.92) !important;border-radius:10px !important;padding:5px !important;}
.dot.green{background:#22c55e !important;box-shadow:0 0 18px rgba(34,197,94,.45) !important;}
.dot.gray{background:#64748b !important;}
.slider{background:#334155 !important;}
.switch input:checked + .slider{background:linear-gradient(135deg,#6d4cff,#22c55e) !important;}

/* Table */
.table-card{overflow:hidden !important;}
.table-card-head{background:rgba(7,11,20,.36) !important;border-bottom:1px solid rgba(148,163,184,.14) !important;}
.erp-table{background:transparent !important;}
.erp-table thead th{background:rgba(7,11,20,.76) !important;color:#cbd5e1 !important;border-bottom:1px solid rgba(148,163,184,.16) !important;font-weight:900 !important;text-transform:uppercase !important;letter-spacing:.04em !important;font-size:13px !important;}
.erp-table tbody td{background:rgba(15,23,42,.30) !important;color:#e5e7eb !important;border-bottom:1px solid rgba(148,163,184,.10) !important;font-weight:700 !important;}
.erp-table tbody tr:hover td{background:radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),linear-gradient(135deg,rgba(30,20,58,.42),rgba(15,23,42,.34)) !important;color:#fff !important;}
.table-footer{background:rgba(7,11,20,.30) !important;border-top:1px solid rgba(148,163,184,.13) !important;color:#94a3b8 !important;}
.footer-left{color:#94a3b8 !important;font-weight:800 !important;}
.pager-num{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;}
.badge-status{border-radius:999px !important;font-weight:900 !important;border:1px solid transparent !important;}
.badge-paid{background:rgba(34,197,94,.16) !important;color:#4ade80 !important;border-color:rgba(34,197,94,.25) !important;}
.badge-pending{background:rgba(245,158,11,.16) !important;color:#fbbf24 !important;border-color:rgba(245,158,11,.25) !important;}
.badge-failed{background:rgba(239,68,68,.16) !important;color:#f87171 !important;border-color:rgba(239,68,68,.25) !important;}

/* Compact dark purple glass popup */
.modal-backdrop.show{opacity:.58 !important;background:#000 !important;}
#gatewayModal .modal-dialog,.modal-xl-custom{max-width:760px !important;width:760px !important;margin:18px auto !important;}
#gatewayModal .po-modal-content{
    max-height:82vh !important;
    background:radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),linear-gradient(135deg, rgba(15,23,42,.92), rgba(17,24,39,.78)) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.34) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 24px 70px rgba(0,0,0,.58),0 0 45px rgba(124,58,237,.16),inset 0 1px 0 rgba(255,255,255,.06) !important;
}
#gatewayModal .po-modal-header{min-height:54px !important;padding:12px 18px !important;background:rgba(7,11,20,.34) !important;border-bottom:1px solid rgba(148,163,184,.14) !important;}
#gatewayModal .po-modal-title{font-size:18px !important;color:#f8fafc !important;font-weight:900 !important;}
#gatewayModal .po-modal-title::before{background:#7c3aed !important;box-shadow:0 0 0 5px rgba(124,58,237,.16) !important;width:11px !important;height:11px !important;}
#gatewayModal .po-close{width:36px !important;height:36px !important;border-radius:10px !important;background:rgba(148,163,184,.16) !important;border:1px solid rgba(148,163,184,.18) !important;}
#gatewayModal .po-close::before,#gatewayModal .po-close::after{width:16px !important;height:2px !important;background:#e5e7eb !important;}
#gatewayModal .po-close:hover{background:rgba(239,68,68,.24) !important;}
#gatewayModal .po-modal-body{padding:12px 18px !important;max-height:62vh !important;overflow-y:auto !important;overflow-x:hidden !important;background:rgba(15,23,42,.22) !important;}
#gatewayModal .row{--bs-gutter-x:14px !important;--bs-gutter-y:8px !important;}
#gatewayModal .mb-3{margin-bottom:10px !important;}
#gatewayModal .dark-label{font-size:12px !important;margin-bottom:5px !important;color:#e5e7eb !important;font-weight:900 !important;}
#gatewayModal .req{color:#a78bfa !important;}
#gatewayModal .dark-input,#gatewayModal .dark-select,#gatewayModal input,#gatewayModal select{
    height:38px !important;
    min-height:38px !important;
    padding:6px 12px !important;
    font-size:13px !important;
    border-radius:9px !important;
    background:rgba(255,255,255,.92) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    font-weight:700 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}
#gatewayModal .dark-input:focus,#gatewayModal .dark-select:focus,#gatewayModal input:focus,#gatewayModal select:focus{border-color:#7c3aed !important;box-shadow:0 0 0 3px rgba(124,58,237,.20),0 8px 18px rgba(0,0,0,.18) !important;}
#gatewayModal .toggle-eye{width:30px !important;height:30px !important;right:5px !important;background:#f3f5fa !important;color:#111827 !important;}
#gatewayModal .switch-row-modal,#gatewayModal label[style*="display:flex"]{padding:8px 12px !important;margin-top:4px !important;font-size:13px !important;border-radius:12px !important;background:rgba(15,23,42,.62) !important;border:1px solid rgba(148,163,184,.18) !important;color:#f8fafc !important;}
#gatewayModal .po-modal-footer{padding:10px 18px !important;min-height:52px !important;margin-top:0 !important;background:rgba(7,11,20,.30) !important;border-top:1px solid rgba(148,163,184,.14) !important;}
#gatewayModal .cancel-btn,#gatewayModal .save-btn{height:38px !important;min-width:105px !important;font-size:13px !important;border-radius:9px !important;font-weight:900 !important;}
#gatewayModal .cancel-btn{background:rgba(15,23,42,.72) !important;color:#e5e7eb !important;border:1px solid rgba(148,163,184,.18) !important;}
#gatewayModal .save-btn{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;border:none !important;box-shadow:0 12px 24px rgba(124,58,237,.22) !important;}

@media(max-width:1200px){.stats-grid,.gateway-grid{grid-template-columns:repeat(2,1fr) !important;}.filter-row{grid-template-columns:repeat(3,1fr) !important;}}
@media(max-width:900px){#gatewayModal .modal-dialog,.modal-xl-custom{width:calc(100% - 24px) !important;max-width:calc(100% - 24px) !important;margin:12px auto !important;}#gatewayModal .po-modal-body{max-height:66vh !important;}}
@media(max-width:768px){.stats-grid,.gateway-grid,.filter-row{grid-template-columns:1fr !important;}.page-header{flex-direction:column !important;align-items:stretch !important;}.content-wrapper{padding:18px !important;}#gatewayModal .po-modal-footer{flex-direction:column !important;align-items:stretch !important;}#gatewayModal .cancel-btn,#gatewayModal .save-btn{width:100% !important;}}

/* =========================================================
   PAYMENTS.PHP - FINAL WORKING LIGHT MODE
   Always keep this block at the END before
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;

    --pay-light-bg:#f4f7fb;
    --pay-white:#ffffff;
    --pay-soft:#f8fafc;
    --pay-border:#dce4ef;
    --pay-border-strong:#cbd5e1;
    --pay-title:#0f172a;
    --pay-text:#334155;
    --pay-muted:#64748b;
    --pay-purple:#7c3aed;
    --pay-purple-dark:#6d28d9;
}

/* =========================================================
   BODY AND PAGE BACKGROUND
========================================================= */

html[data-pos-theme="light"] body{
    background:var(--pay-light-bg) !important;
    color:var(--pay-title) !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:var(--pay-light-bg) !important;
}

html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 55%,
            #faf7ff 100%
        ) !important;
}

/* Topbar */
html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    color:#0f172a !important;
    border-bottom:1px solid #dce4ef !important;
    box-shadow:0 5px 18px rgba(15,23,42,.06) !important;
}

/* Main content */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 80% 4%,
            rgba(59,130,246,.05),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 50%,
            #faf7ff 78%,
            #f8fafc 100%
        ) !important;

    color:#0f172a !important;
}

/* Dark background grid light karein */
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

    opacity:.55 !important;
    mask-image:none !important;
}

/* =========================================================
   HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Add Gateway button */
html[data-pos-theme="light"] body .btn-green{
    background:
        linear-gradient(
            135deg,
            #059669,
            #16a34a
        ) !important;

    color:#ffffff !important;
    border:none !important;

    box-shadow:
        0 12px 26px rgba(16,185,129,.20) !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border:1px solid rgba(255,255,255,.30) !important;

    box-shadow:
        0 14px 30px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-title,
html[data-pos-theme="light"] body .stat-sub,
html[data-pos-theme="light"] body .stat-value{
    color:#ffffff !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .stat-blue{
    background:
        linear-gradient(
            135deg,
            #2563eb,
            #6d4cff
        ) !important;
}

html[data-pos-theme="light"] body .stat-green{
    background:
        linear-gradient(
            135deg,
            #047857,
            #16a34a
        ) !important;
}

html[data-pos-theme="light"] body .stat-cyan{
    background:
        linear-gradient(
            135deg,
            #0891b2,
            #06b6d4
        ) !important;
}

html[data-pos-theme="light"] body .stat-gold{
    background:
        linear-gradient(
            135deg,
            #f59e0b,
            #fb923c
        ) !important;
}

/* =========================================================
   FILTER CARD
========================================================= */

html[data-pos-theme="light"] body .filter-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 28px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .filter-card:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 34px rgba(15,23,42,.09) !important;
}

/* Inputs and selects */
html[data-pos-theme="light"] body .erp-select,
html[data-pos-theme="light"] body .erp-input,
html[data-pos-theme="light"] body .default-select{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .erp-input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .erp-select:hover,
html[data-pos-theme="light"] body .erp-input:hover,
html[data-pos-theme="light"] body .default-select:hover{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] body .erp-select:focus,
html[data-pos-theme="light"] body .erp-input:focus,
html[data-pos-theme="light"] body .default-select:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .erp-select option,
html[data-pos-theme="light"] body .default-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Default label */
html[data-pos-theme="light"] body
.filter-row > div[style*="display:flex"]{
    color:#475569 !important;
}

/* Filter button */
html[data-pos-theme="light"] body .filter-btn{
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

/* Reset button */
html[data-pos-theme="light"] body .reset-btn{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .reset-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   GATEWAY SECTION
========================================================= */

html[data-pos-theme="light"] body .gateway-section-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Gateway cards */
html[data-pos-theme="light"] body .gw-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 10px 26px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .gw-card:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 36px rgba(15,23,42,.10) !important;
}

html[data-pos-theme="light"] body .gw-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .gw-sub{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .gw-info-row,
html[data-pos-theme="light"] body .dot-wrap{
    color:#475569 !important;
}

/* Logos */
html[data-pos-theme="light"] body .gw-logo,
html[data-pos-theme="light"] body .method-icon{
    background:#ffffff !important;
    border:1px solid #e2e8f0 !important;
    border-radius:9px !important;
    padding:5px !important;
}

/* Status dots */
html[data-pos-theme="light"] body .dot.green{
    background:#22c55e !important;

    box-shadow:
        0 0 0 3px rgba(34,197,94,.13) !important;
}

html[data-pos-theme="light"] body .dot.gray{
    background:#94a3b8 !important;
    box-shadow:none !important;
}

/* Toggle */
html[data-pos-theme="light"] body .slider{
    background:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.switch input:checked + .slider{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #16a34a
        ) !important;
}

/* Configure */
html[data-pos-theme="light"] body .gw-btn-dark{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border:none !important;

    box-shadow:
        0 8px 18px rgba(124,58,237,.18) !important;
}

/* Transactions */
html[data-pos-theme="light"] body .gw-btn-light{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .gw-btn-light:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   RECENT TRANSACTIONS TABLE
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card-head{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .table-card-title{
    color:#0f172a !important;
}

/* Table variables reset */
html[data-pos-theme="light"] body .erp-table,
html[data-pos-theme="light"] body .erp-table thead,
html[data-pos-theme="light"] body .erp-table tbody,
html[data-pos-theme="light"] body .erp-table tr,
html[data-pos-theme="light"] body .erp-table th,
html[data-pos-theme="light"] body .erp-table td{
    --bs-table-bg:transparent !important;
    --bs-table-accent-bg:transparent !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
}

/* Table heading */
html[data-pos-theme="light"] body .erp-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-right:1px solid #e2e8f0 !important;
    border-bottom:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

/* Rows */
html[data-pos-theme="light"] body .erp-table tbody,
html[data-pos-theme="light"] body .erp-table tbody tr,
html[data-pos-theme="light"] body .erp-table tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-bottom-color:#e8edf4 !important;

    box-shadow:none !important;
}

/* Hover */
html[data-pos-theme="light"] body
.erp-table tbody tr:hover td,

html[data-pos-theme="light"] body
.erp-table tbody td:hover{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;

    color:#0f172a !important;
    box-shadow:none !important;
}

/* Empty state */
html[data-pos-theme="light"] body
.erp-table tbody td.text-center,

html[data-pos-theme="light"] body
.erp-table tbody td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

html[data-pos-theme="light"] body .text-muted{
    color:#64748b !important;
}

/* Checkbox */
html[data-pos-theme="light"] body
.erp-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Status */
html[data-pos-theme="light"] body .badge-paid{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .badge-pending{
    background:#fffbeb !important;
    color:#b45309 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .badge-failed{
    background:#fff1f2 !important;
    color:#be123c !important;
    border:1px solid #fecdd3 !important;
}

/* Header buttons */
html[data-pos-theme="light"] body .icon-btn-sm{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
.table-card-head .icon-btn-sm[style]{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

/* Footer */
html[data-pos-theme="light"] body .table-footer{
    background:#f8fafc !important;
    color:#64748b !important;

    border-top:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .footer-left{
    color:#64748b !important;
}

/* Pagination */
html[data-pos-theme="light"] body .pager-btn{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .pager-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body .pager-num{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
}

/* =========================================================
   GATEWAY MODAL
========================================================= */

html[data-pos-theme="light"] body
#gatewayModal .po-modal-content{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #d8e0eb !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.20) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Header */
html[data-pos-theme="light"] body
#gatewayModal .po-modal-header{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body
#gatewayModal .po-modal-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#gatewayModal .po-modal-title::before{
    background:#7c3aed !important;

    box-shadow:
        0 0 0 4px rgba(124,58,237,.14) !important;
}

/* Modal close */
html[data-pos-theme="light"] body
#gatewayModal .po-close{
    background:#ffffff !important;
    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body
#gatewayModal .po-close::before,

html[data-pos-theme="light"] body
#gatewayModal .po-close::after{
    background:#334155 !important;
}

/* Body */
html[data-pos-theme="light"] body
#gatewayModal .po-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Labels */
html[data-pos-theme="light"] body
#gatewayModal .dark-label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
#gatewayModal .req{
    color:#e11d48 !important;
}

/* Fields */
html[data-pos-theme="light"] body
#gatewayModal .dark-input,

html[data-pos-theme="light"] body
#gatewayModal .dark-select,

html[data-pos-theme="light"] body
#gatewayModal input[type="text"],

html[data-pos-theme="light"] body
#gatewayModal input[type="password"],

html[data-pos-theme="light"] body
#gatewayModal select{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#gatewayModal input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body
#gatewayModal input:focus,

html[data-pos-theme="light"] body
#gatewayModal select:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

/* Eye button */
html[data-pos-theme="light"] body
#gatewayModal .toggle-eye{
    background:#f8fafc !important;
    color:#475569 !important;

    border:1px solid #cbd5e1 !important;
}

/* Status and default rows */
html[data-pos-theme="light"] body
#gatewayModal .switch-row-modal,

html[data-pos-theme="light"] body
#gatewayModal label[style*="display:flex"]{
    background:#f8fafc !important;
    color:#334155 !important;

    border:1px solid #dce4ef !important;
    box-shadow:none !important;
}

/* Modal footer */
html[data-pos-theme="light"] body
#gatewayModal .po-modal-footer{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body
#gatewayModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#gatewayModal .save-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

/* Backdrop */
html[data-pos-theme="light"] body.modal-open
.modal-backdrop.show{
    background:#0f172a !important;
    opacity:.42 !important;
}

</style>
</head>
<body>
<div class="container-scroll">

    <!-- SIDEBAR -->
 <div class="sidebar" id="dashboardSidebar">
         <div class="sidebar" id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>
    </div>

    <!-- MAIN -->
    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">

            <!-- PAGE HEADER -->
            <div class="page-header">
                <h1 class="page-title">Payment Integrations</h1>
                <button type="button" class="btn-green" id="addGatewayBtn">
                    <i class="bi bi-plus-lg me-1"></i> Add Gateway
                </button>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card stat-blue">
                    <div class="stat-title">Active Gateways</div>
                    <div class="stat-value"><?= (int)$activeCount ?></div>
                </div>
                <div class="stat-card stat-green">
                    <div class="stat-title">Total Transactions</div>
                    <div class="stat-sub">Today</div>
                    <div class="stat-value"><?= number_format((int)$totalTransactions) ?></div>
                </div>
                <div class="stat-card stat-cyan">
                    <div class="stat-title">Total Collection</div>
                    <div class="stat-value" style="font-size:22px;">PKR <?= number_format((float)$totalCollection) ?></div>
                </div>
                <div class="stat-card stat-gold">
                    <div class="stat-title">Default Gateway</div>
                    <div class="stat-value" style="font-size:18px;"><?= e($defaultGateway ?: '—') ?></div>
                </div>
            </div>

            <!-- FILTER CARD -->
            <div class="filter-card">
                <form method="GET">
                    <div class="filter-row">
                        <select class="erp-select" name="gateway">
                            <option value="">All Gateways</option>
                            <?php foreach ($gatewayCatalog as $item): ?>
                                <option value="<?= e($item['name']) ?>" <?= $gatewayFilter === $item['name'] ? 'selected' : '' ?>>
                                    <?= e($item['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select class="erp-select" name="status">
                            <option value="">All Status</option>
                            <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                        </select>

                        <select class="erp-select" name="date">
                            <option value="">Last 7 Days</option>
                            <option value="today"   <?= $dateFilter === 'today'   ? 'selected' : '' ?>>Today</option>
                            <option value="30days"  <?= $dateFilter === '30days'  ? 'selected' : '' ?>>Last 30 Days</option>
                        </select>

                        <!-- Default Payment Method -->
                        <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#243046;white-space:nowrap;">
                            Default:
                            <select class="erp-select" id="defaultMethodSelect" style="min-width:130px;">
                                <?php foreach ($gatewayCards as $g): ?>
                                    <option value="<?= e($g['name']) ?>" <?= $defaultGateway === $g['name'] ? 'selected' : '' ?>>
                                        <?= e($g['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <input class="erp-input" type="text" placeholder="Search transactions..." name="search" value="<?= e($_GET['search'] ?? '') ?>">

                        <button type="submit" class="filter-btn"><i class="bi bi-search me-1"></i> Filter</button>
                        <a href="payments.php" class="reset-btn">
                            <i class="bi bi-arrow-clockwise me-1"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- GATEWAY CARDS -->
            <div class="gateway-section-title">Payment Gateways</div>
            <div class="gateway-grid">
                <?php foreach ($gatewayCards as $gateway): ?>
                    <?php
                        $statusLabel = (int)$gateway['status'] === 1 ? 'Active' : 'Disabled';
                        $statusDot   = (int)$gateway['status'] === 1 ? 'green' : 'gray';
                        $updatedText = !empty($gateway['updated_at']) ? date('g:i A, d M', strtotime($gateway['updated_at'])) : 'Not configured';
                    ?>
                    <div class="gw-card" data-gateway='<?= e(json_encode($gateway)) ?>'>
                        <div class="gw-card-top">
                            <img src="<?= e($gateway['icon']) ?>" alt="<?= e($gateway['name']) ?>" class="gw-logo">
                            <label class="switch">
                                <input type="checkbox" class="gateway-status-toggle" data-gateway="<?= e($gateway['name']) ?>" <?= (int)$gateway['status'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div>
                            <p class="gw-name"><?= e($gateway['name']) ?></p>
                            <p class="gw-sub"><?= e($gateway['gateway_name']) ?></p>
                        </div>

                        <div class="gw-info-row">
                            <span class="dot-wrap"><span class="dot <?= $statusDot ?>"></span><?= e($statusLabel) ?></span>
                            <span>Fee: <?= e($gateway['fees']) ?></span>
                        </div>

                        <div class="gw-info-row">
                            <span>Last used: <?= e($updatedText) ?></span>
                        </div>

                        <div class="gw-actions">
                            <button class="gw-btn gw-btn-dark configureGatewayBtn" data-name="<?= e($gateway['name']) ?>">
                                <i class="bi bi-gear-fill"></i> Configure
                            </button>
                            <button class="gw-btn gw-btn-light">
                                <i class="bi bi-view-list"></i> Transactions
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- RECENT TRANSACTIONS TABLE -->
            <div class="table-card">
                <div class="table-card-head">
                    <h3 class="table-card-title">Recent Transactions</h3>
                    <div class="d-flex gap-2">
                        <button class="icon-btn-sm"><i class="bi bi-box-arrow-up"></i></button>
                        <button class="icon-btn-sm"><i class="bi bi-download"></i></button>
                        <button class="icon-btn-sm" style="background:#285933;border-color:#326a3d;color:#fff;"><i class="bi bi-whatsapp"></i></button>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="erp-table" id="transactionsTable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="checkAll"></th>
                                <th>Payment Method</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th style="width:80px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentTransactions)): ?>
                                <?php foreach ($recentTransactions as $trx): ?>
                                    <?php
                                        $methodName = $trx['payment_method'];
                                        $icon = '';
                                        foreach ($gatewayCatalog as $cat) {
                                            if (strcasecmp($cat['name'], $methodName) === 0) { $icon = $cat['icon']; break; }
                                        }
                                        $amount     = is_numeric($trx['amount']) ? 'PKR ' . number_format((float)$trx['amount']) : e($trx['amount']);
                                        $status     = strtolower(trim($trx['status']));
                                        $badgeClass = 'badge-paid';
                                        $statusText = 'Paid';
                                        if ($status === 'pending') { $badgeClass = 'badge-pending'; $statusText = 'Pending'; }
                                        elseif ($status === 'failed') { $badgeClass = 'badge-failed'; $statusText = 'Failed'; }
                                        $dateText   = $trx['date'];
                                        if (strtotime((string)$trx['date'])) $dateText = date('d/m/Y', strtotime($trx['date']));
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="row-check"></td>
                                        <td>
                                            <div class="method-cell">
                                                <?php if ($icon): ?><img class="method-icon" src="<?= e($icon) ?>" alt="<?= e($methodName) ?>"><?php endif; ?>
                                                <span><?= e($methodName) ?></span>
                                            </div>
                                        </td>
                                        <td><?= e($amount) ?></td>
                                        <td><?= e($dateText) ?></td>
                                        <td><span class="badge-status <?= $badgeClass ?>"><?= e($statusText) ?></span></td>
                                        <td class="text-end">
                                            <i class="bi bi-pencil-square text-warning" style="cursor:pointer;margin-right:8px;"></i>
                                            <i class="bi bi-three-dots" style="cursor:pointer;"></i>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No transactions found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <div class="footer-left">Showing 1 to <?= count($recentTransactions) ?> of <?= count($recentTransactions) ?> entries</div>
                    <div class="footer-right">
                        <button class="pager-btn" id="prev-page"><i class="bi bi-chevron-left"></i></button>
                        <div class="pager-num" id="current-page">1</div>
                        <button class="pager-btn" id="next-page"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
            </div>

        </div><!-- /content-wrapper -->
    </div><!-- /main-panel -->
</div><!-- /container-scroll -->

<!-- ==================== ADD / EDIT GATEWAY MODAL ==================== -->
<div class="modal fade" id="gatewayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl-custom">
        <div class="modal-content po-modal-content">
            <form id="gatewayForm">
                <div class="po-modal-header">
                    <h5 class="po-modal-title" id="gatewayModalTitle">Add New Payment Gateway</h5>
                    <button type="button" class="po-close" data-bs-dismiss="modal">&times;</button>
                </div>

                <div class="po-modal-body">
                    <div class="row">
                        <!-- Select Gateway -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Select Gateway <span class="req">*</span></label>
                            <select class="dark-select" name="selected_gateway" id="selected_gateway" required>
                                <option value="">– Select Payment Gateway –</option>
                                <?php foreach ($gatewayCatalog as $item): ?>
                                    <option value="<?= e($item['name']) ?>"><?= e($item['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Gateway Name -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Gateway Name</label>
                            <input type="text" class="dark-input" name="gateway_name" id="gateway_name" placeholder="Enter gateway name">
                        </div>

                        <!-- API Key -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">API Key</label>
                            <div class="password-wrap">
                                <input type="password" class="dark-input" name="api_key" id="api_key" placeholder="Enter API key">
                                <button type="button" class="toggle-eye" data-target="api_key"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <!-- API Secret -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label" id="secretLabel">API Secret</label>
                            <div class="password-wrap">
                                <input type="password" class="dark-input" name="api_secret" id="api_secret" placeholder="Enter API secret">
                                <button type="button" class="toggle-eye" data-target="api_secret"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <!-- API Token -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label" id="tokenLabel">API Token</label>
                            <div class="password-wrap">
                                <input type="password" class="dark-input" name="api_token" id="api_token" placeholder="Enter API token">
                                <button type="button" class="toggle-eye" data-target="api_token"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <!-- Status toggle -->
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Active Status</label>
                            <div class="switch-row-modal">
                                <label class="switch">
                                    <input type="checkbox" name="enabled" id="enabled" checked>
                                    <span class="slider"></span>
                                </label>
                                <span>Enable this gateway</span>
                            </div>
                        </div>

                        <!-- Default checkbox -->
                        <div class="col-12 mb-2">
                            <label style="display:flex;align-items:center;gap:10px;color:#f4f6fd;font-size:14px;font-weight:600;cursor:pointer;">
                                <input type="checkbox" name="is_default" id="is_default" value="1">
                                Make this the default payment gateway
                            </label>
                        </div>
                    </div>
                </div>

                <div class="po-modal-footer">
                    <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="save-btn" id="submitBtn">Add Gateway</button>
                </div>

                <input type="hidden" name="action" value="save_gateway">
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /* ===== DATA FROM PHP ===== */
    const gatewaySettings = <?= json_encode($gatewayCards, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const gatewayModal    = new bootstrap.Modal(document.getElementById('gatewayModal'));

    /* ===== MODAL HELPERS ===== */
    function applyFieldLabels(name) {
        const secretLabel = document.getElementById('secretLabel');
        const tokenLabel  = document.getElementById('tokenLabel');
        const apiSecret   = document.getElementById('api_secret');
        const apiToken    = document.getElementById('api_token');

        if (name === 'Bank Transfer') {
            secretLabel.textContent  = 'Account Number / IBAN';
            tokenLabel.textContent   = 'Account Title';
            apiSecret.placeholder    = 'Enter account number / IBAN';
            apiToken.placeholder     = 'Enter account title';
        } else {
            secretLabel.textContent  = 'API Secret';
            tokenLabel.textContent   = 'API Token';
            apiSecret.placeholder    = 'Enter API secret';
            apiToken.placeholder     = 'Enter API token';
        }
    }

    function resetForm() {
        document.getElementById('gatewayForm').reset();
        document.getElementById('enabled').checked = true;
        document.getElementById('is_default').checked = false;
        document.getElementById('gatewayModalTitle').textContent = 'Add New Payment Gateway';
        document.getElementById('submitBtn').textContent = 'Add Gateway';
        applyFieldLabels('');
    }

    function fillForm(item) {
        document.getElementById('selected_gateway').value = item.name;
        document.getElementById('gateway_name').value     = item.gateway_name || item.name;
        document.getElementById('api_key').value          = item.api_key    || '';
        document.getElementById('api_secret').value       = item.api_secret || '';
        document.getElementById('api_token').value        = item.api_token  || '';
        document.getElementById('enabled').checked        = Number(item.status)     === 1;
        document.getElementById('is_default').checked     = Number(item.is_default) === 1;
        applyFieldLabels(item.name);
    }

    /* ===== OPEN ADD MODAL ===== */
    document.getElementById('addGatewayBtn').addEventListener('click', () => {
        resetForm();
        gatewayModal.show();
    });

    /* ===== OPEN CONFIGURE MODAL ===== */
    document.querySelectorAll('.configureGatewayBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            resetForm();
            const item = gatewaySettings.find(g => g.name === this.dataset.name);
            if (item) fillForm(item);
            document.getElementById('gatewayModalTitle').textContent = 'Configure Gateway';
            document.getElementById('submitBtn').textContent = 'Save Changes';
            gatewayModal.show();
        });
    });

    /* ===== GATEWAY SELECT CHANGE ===== */
    document.getElementById('selected_gateway').addEventListener('change', function () {
        applyFieldLabels(this.value);
        const item = gatewaySettings.find(g => g.name === this.value);
        if (item) fillForm(item);
        else {
            document.getElementById('gateway_name').value = this.value;
        }
    });

    /* ===== TOGGLE EYE ===== */
    document.querySelectorAll('.toggle-eye').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            const icon  = this.querySelector('i');
            if (input.type === 'password') {
                input.type       = 'text';
                icon.className   = 'bi bi-eye-slash';
            } else {
                input.type       = 'password';
                icon.className   = 'bi bi-eye';
            }
        });
    });

    /* ===== SAVE GATEWAY FORM ===== */
    document.getElementById('gatewayForm').addEventListener('submit', function (e) {
        e.preventDefault();
        fetch('', { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(data => {
                if (data.success) { alert(data.message || 'Saved!'); location.reload(); }
                else              { alert(data.message || 'Something went wrong'); }
            })
            .catch(() => alert('Request failed'));
    });

    /* ===== TOGGLE STATUS ===== */
    document.querySelectorAll('.gateway-status-toggle').forEach(toggle => {
        toggle.addEventListener('change', function () {
            const fd = new FormData();
            fd.append('action',  'toggle_status');
            fd.append('gateway', this.dataset.gateway);
            fd.append('status',  this.checked ? '1' : '0');
            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { alert('Status update failed'); this.checked = !this.checked; }
                    else location.reload();
                })
                .catch(() => { alert('Request failed'); this.checked = !this.checked; });
        });
    });

    /* ===== SET DEFAULT ===== */
    document.getElementById('defaultMethodSelect').addEventListener('change', function () {
        const fd = new FormData();
        fd.append('action',  'set_default');
        fd.append('gateway', this.value);
        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => { if (!data.success) alert(data.message || 'Default update failed'); })
            .catch(() => alert('Request failed'));
    });

    /* ===== SELECT ALL ===== */
    document.getElementById('checkAll')?.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(ch => ch.checked = this.checked);
    });

    /* ===== PAGINATION ===== */
    document.addEventListener('DOMContentLoaded', function () {
        let currentPage  = 1;
        const rowsPerPage = 10;
        const tableRows  = Array.from(document.querySelectorAll('#transactionsTable tbody tr'));
        const totalPages = Math.ceil(tableRows.length / rowsPerPage);

        function showPage(page) {
            currentPage = page;
            tableRows.forEach((row, idx) => {
                row.style.display = (idx >= (page-1)*rowsPerPage && idx < page*rowsPerPage) ? '' : 'none';
            });
            document.getElementById('current-page').textContent = page;
        }

        document.getElementById('prev-page').addEventListener('click', () => { if (currentPage > 1) showPage(currentPage - 1); });
        document.getElementById('next-page').addEventListener('click', () => { if (currentPage < totalPages) showPage(currentPage + 1); });
        showPage(1);
    });
</script>
</body>
</html>