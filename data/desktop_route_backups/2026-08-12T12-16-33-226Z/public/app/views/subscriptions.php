<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (file_exists(__DIR__ . '/partials/performance.php')) {
    include(__DIR__ . '/partials/performance.php');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================== DB CONNECTION CHECK ================== */
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found. Please check /config/db.php and make sure $conn is mysqli.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ================== HELPERS ================== */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_has_column($conn, $table, $column) {
    $sql = "SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return !empty($row['total']);
}

function clean_status($status) {
    $status = strtolower(trim((string)$status));
    $allowed = ['active', 'expired', 'trial', 'suspended'];
    return in_array($status, $allowed, true) ? $status : 'active';
}

function status_badge_class($status) {
    $status = strtolower((string)$status);
    if ($status === 'active') return 'bg-success';
    if ($status === 'expired') return 'bg-danger';
    if ($status === 'suspended') return 'bg-dark';
    return 'bg-warning text-dark';
}

function client_label($c) {
    $parts = [];
    $parts[] = 'ID ' . (int)$c['id'];
    $parts[] = trim((string)($c['business_name'] ?? '')) !== '' ? $c['business_name'] : ('Client #' . (int)$c['id']);
    if (!empty($c['contact_number'])) {
        $parts[] = $c['contact_number'];
    }
    if (!empty($c['status'])) {
        $parts[] = ucfirst($c['status']);
    }
    return implode(' - ', $parts);
}

function plan_label($p) {
    $parts = [];
    $parts[] = 'ID ' . (int)$p['id'];
    $parts[] = trim((string)($p['name'] ?? '')) !== '' ? $p['name'] : ('Plan #' . (int)$p['id']);
    if (isset($p['price']) && $p['price'] !== '') {
        $parts[] = 'PKR ' . number_format((float)$p['price'], 0);
    }
    if (!empty($p['billing_cycle'])) {
        $parts[] = ucfirst((string)$p['billing_cycle']);
    }
    if (!empty($p['industry'])) {
        $parts[] = ucfirst((string)$p['industry']);
    }
    if (!empty($p['status'])) {
        $parts[] = ucfirst((string)$p['status']);
    }
    return implode(' - ', $parts);
}

/* ================== AUTH CHECK ================== */
$logged_user_id = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0);
$logged_role_id = (int)($_SESSION['role_id'] ?? 0);

if ($logged_user_id > 0 && $logged_role_id <= 0 && table_has_column($conn, 'users', 'role_id')) {
    $stmt = $conn->prepare('SELECT role_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $logged_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $logged_role_id = (int)$row['role_id'];
        $_SESSION['role_id'] = $logged_role_id;
    }
    $stmt->close();
}

if ($logged_user_id <= 0) {
    $login_url = '/onerp/app/onerp/app/public/app/views/login_view.php';
    if (!headers_sent()) {
        header('Location: ' . $login_url);
        exit;
    }
    die('User not logged in!');
}

if ($logged_role_id !== 1) {
    die('Access denied! Only Super Admin can manage subscriptions.');
}

/* ================== DEBUG DB INFO ================== */
$current_db = '';
try {
    $dbRes = $conn->query('SELECT DATABASE() AS db_name');
    $dbRow = $dbRes->fetch_assoc();
    $current_db = $dbRow['db_name'] ?? '';
} catch (Throwable $e) {
    $current_db = 'unknown';
}

/* ================== AJAX GET CLIENTS ================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_clients') {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)($_GET['q'] ?? ''));
    $clients_ajax = [];

    try {
        $has_contact = table_has_column($conn, 'clients', 'contact_number');
        $has_status = table_has_column($conn, 'clients', 'status');
        $select_contact = $has_contact ? 'contact_number' : "'' AS contact_number";
        $select_status = $has_status ? 'status' : "'' AS status";

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql = "SELECT id, business_name, $select_contact, $select_status
                    FROM clients
                    WHERE CAST(id AS CHAR) LIKE ?
                       OR business_name LIKE ?";
            $types = 'ss';
            $params = [$like, $like];

            if ($has_contact) {
                $sql .= " OR contact_number LIKE ?";
                $types .= 's';
                $params[] = $like;
            }

            $sql .= " ORDER BY id DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query("SELECT id, business_name, $select_contact, $select_status FROM clients ORDER BY id DESC");
        }

        while ($r = $res->fetch_assoc()) {
            $clients_ajax[] = [
                'id' => (int)$r['id'],
                'label' => client_label($r),
                'business_name' => (string)($r['business_name'] ?? ''),
                'contact_number' => (string)($r['contact_number'] ?? ''),
                'status' => (string)($r['status'] ?? '')
            ];
        }

        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }

        echo json_encode(['ok' => true, 'total' => count($clients_ajax), 'db' => $current_db, 'clients' => $clients_ajax]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'clients' => []]);
    }
    exit;
}

/* ================== AJAX GET PLANS ================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_plans') {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)($_GET['q'] ?? ''));
    $plans_ajax = [];

    try {
        $has_price = table_has_column($conn, 'plans', 'price');
        $has_cycle = table_has_column($conn, 'plans', 'billing_cycle');
        $has_industry = table_has_column($conn, 'plans', 'industry');
        $has_status = table_has_column($conn, 'plans', 'status');

        $select_price = $has_price ? 'price' : "0 AS price";
        $select_cycle = $has_cycle ? 'billing_cycle' : "'' AS billing_cycle";
        $select_industry = $has_industry ? 'industry' : "'' AS industry";
        $select_status = $has_status ? 'status' : "'' AS status";

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql = "SELECT id, name, $select_price, $select_cycle, $select_industry, $select_status
                    FROM plans
                    WHERE CAST(id AS CHAR) LIKE ?
                       OR name LIKE ?";
            $types = 'ss';
            $params = [$like, $like];

            if ($has_industry) {
                $sql .= " OR industry LIKE ?";
                $types .= 's';
                $params[] = $like;
            }

            $sql .= " ORDER BY id DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query("SELECT id, name, $select_price, $select_cycle, $select_industry, $select_status FROM plans ORDER BY id DESC");
        }

        while ($r = $res->fetch_assoc()) {
            $plans_ajax[] = [
                'id' => (int)$r['id'],
                'label' => plan_label($r),
                'name' => (string)($r['name'] ?? ''),
                'price' => (string)($r['price'] ?? ''),
                'billing_cycle' => (string)($r['billing_cycle'] ?? ''),
                'industry' => (string)($r['industry'] ?? ''),
                'status' => (string)($r['status'] ?? '')
            ];
        }

        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }

        echo json_encode(['ok' => true, 'total' => count($plans_ajax), 'db' => $current_db, 'plans' => $plans_ajax]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'plans' => []]);
    }
    exit;
}

/* ================== FETCH ALL PLANS - NO LIMIT, NO STATUS FILTER ================== */
$plans = [];
try {
    $has_price = table_has_column($conn, 'plans', 'price');
    $has_cycle = table_has_column($conn, 'plans', 'billing_cycle');
    $has_industry = table_has_column($conn, 'plans', 'industry');
    $has_status = table_has_column($conn, 'plans', 'status');

    $select_price = $has_price ? 'price' : "0 AS price";
    $select_cycle = $has_cycle ? 'billing_cycle' : "'' AS billing_cycle";
    $select_industry = $has_industry ? 'industry' : "'' AS industry";
    $select_status = $has_status ? 'status' : "'' AS status";

    $res = $conn->query("SELECT id, name, $select_price, $select_cycle, $select_industry, $select_status FROM plans ORDER BY id DESC");
    while ($r = $res->fetch_assoc()) {
        $plans[] = $r;
    }
} catch (Throwable $e) {
    die('Plans fetch error: ' . $e->getMessage());
}

/* ================== AJAX SAVE SUBSCRIPTION ================== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'save_subscription') {
    header('Content-Type: text/plain; charset=utf-8');

    $sub_id = !empty($_POST['sub_id']) ? (int)$_POST['sub_id'] : 0;
    $client_id = (int)($_POST['client_id'] ?? 0);
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $start_date = trim((string)($_POST['start_date'] ?? ''));
    $end_date = trim((string)($_POST['end_date'] ?? ''));
    $status = clean_status($_POST['status'] ?? 'active');

    if ($client_id <= 0) {
        echo 'Error: Please select client.';
        exit;
    }

    if ($plan_id <= 0) {
        echo 'Error: Please select plan.';
        exit;
    }

    if ($start_date === '' || $end_date === '') {
        echo 'Error: Start date and end date are required.';
        exit;
    }

    try {
        $check = $conn->prepare('SELECT id FROM clients WHERE id = ? LIMIT 1');
        $check->bind_param('i', $client_id);
        $check->execute();
        $check_res = $check->get_result();
        if (!$check_res->fetch_assoc()) {
            echo 'Error: Selected client does not exist in current database (' . $current_db . ').';
            exit;
        }
        $check->close();

        if ($sub_id > 0) {
            $stmt = $conn->prepare('UPDATE subscriptions SET client_id = ?, plan_id = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?');
            $stmt->bind_param('iisssi', $client_id, $plan_id, $start_date, $end_date, $status, $sub_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare('INSERT INTO subscriptions (client_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('iisss', $client_id, $plan_id, $start_date, $end_date, $status);
            $stmt->execute();
            $stmt->close();
        }

        echo 'ok';
    } catch (Throwable $e) {
        echo 'Error: ' . $e->getMessage();
    }

    exit;
}

/* ================== AJAX DELETE SUBSCRIPTION ================== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete_subscription') {
    header('Content-Type: text/plain; charset=utf-8');

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo 'Error: Invalid subscription id.';
        exit;
    }

    try {
        $stmt = $conn->prepare('DELETE FROM subscriptions WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        echo 'deleted';
    } catch (Throwable $e) {
        echo 'Error: ' . $e->getMessage();
    }

    exit;
}

/* ================== FETCH SUBSCRIPTIONS ================== */
$subs = [];
try {
    $sql = "SELECT s.id, s.client_id, s.plan_id, s.start_date, s.end_date, s.status,
                   COALESCE(c.business_name, CONCAT('Client #', s.client_id)) AS business_name,
                   COALESCE(p.name, CONCAT('Plan #', s.plan_id)) AS plan_name
            FROM subscriptions s
            LEFT JOIN clients c ON c.id = s.client_id
            LEFT JOIN plans p ON p.id = s.plan_id
            ORDER BY s.id DESC";
    $res = $conn->query($sql);
    while ($r = $res->fetch_assoc()) {
        $r['status'] = clean_status($r['status'] ?? 'active');
        $subs[] = $r;
    }
} catch (Throwable $e) {
    die('Subscriptions fetch error: ' . $e->getMessage());
}

/* ================== FETCH ALL CLIENTS - NO LIMIT, NO STATUS FILTER ================== */
$clients = [];
try {
    $has_contact = table_has_column($conn, 'clients', 'contact_number');
    $has_status = table_has_column($conn, 'clients', 'status');
    $select_contact = $has_contact ? 'contact_number' : "'' AS contact_number";
    $select_status = $has_status ? 'status' : "'' AS status";
    $res = $conn->query("SELECT id, business_name, $select_contact, $select_status FROM clients ORDER BY id DESC");
    while ($r = $res->fetch_assoc()) {
        $clients[] = $r;
    }
} catch (Throwable $e) {
    die('Clients fetch error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Subscriptions - SaaS POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
html,
body.admin-dashboard-page {
    margin: 0 !important;
    padding: 0 !important;
    width: 100%;
    min-height: 100%;
    overflow-x: hidden !important;
    background: #f4f7fb !important;
}

/* =====================================================
   ADMIN SUBSCRIPTIONS PAGE FIX
   Same fixed sidebar/topbar as admin dashboard
===================================================== */

body.admin-dashboard-page {
    --admin-sidebar-open-width: 280px;
    --admin-sidebar-close-width: 88px;
    --admin-current-sidebar-width: var(--admin-sidebar-open-width);
}

body.admin-dashboard-page.sidebar-collapsed,
body.admin-dashboard-page.dashboard-sidebar-collapsed {
    --admin-current-sidebar-width: var(--admin-sidebar-close-width);
}

body.admin-dashboard-page .container-scroll {
    display: flex !important;
    width: 100% !important;
    min-height: 100vh !important;
    align-items: stretch !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #f4f7fb !important;
}

body.admin-dashboard-page .sidebar,
body.admin-dashboard-page #dashboardSidebar {
    width: var(--admin-current-sidebar-width) !important;
    min-width: var(--admin-current-sidebar-width) !important;
    max-width: var(--admin-current-sidebar-width) !important;
    flex-shrink: 0 !important;
    display: flex !important;
    align-self: stretch !important;
    background: transparent !important;
    overflow: visible !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    height: 100vh !important;
    min-height: 100vh !important;
    z-index: 1000 !important;
    transition: width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page .sidebar > div,
body.admin-dashboard-page #dashboardSidebar > div {
    width: 100% !important;
    min-height: 100vh !important;
    height: 100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-wrap {
    width: var(--admin-current-sidebar-width) !important;
    min-width: var(--admin-current-sidebar-width) !important;
    max-width: var(--admin-current-sidebar-width) !important;
    height: 100vh !important;
    min-height: 100vh !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    z-index: 1000 !important;
    overflow: hidden !important;
    border-radius: 0 !important;
    transition: width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-inner {
    width: 100% !important;
    height: 100vh !important;
    min-height: 100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-menu {
    overflow-y: auto !important;
    overflow-x: hidden !important;
}

body.admin-dashboard-page .main-panel {
    margin-left: var(--admin-current-sidebar-width) !important;
    width: calc(100% - var(--admin-current-sidebar-width)) !important;
    min-width: 0 !important;
    min-height: 100vh !important;
    padding: 70px 0 0 0 !important;
    display: block !important;
    background: #f4f7fb !important;
    transition: margin-left .25s ease, width .25s ease !important;
}

body.admin-dashboard-page .navbar.my-navbar.fixed-top {
    left: var(--admin-current-sidebar-width) !important;
    width: calc(100% - var(--admin-current-sidebar-width)) !important;
    height: 70px !important;
    min-height: 70px !important;
    margin: 0 !important;
    z-index: 999 !important;
    transition: left .25s ease, width .25s ease !important;
}

body.admin-dashboard-page .content-wrapper {
    width: 100% !important;
    margin: 0 !important;
    padding: 24px !important;
    min-height: calc(100vh - 70px) !important;
    overflow: visible !important;
    position: relative !important;
    background: #f4f7fb !important;
}
.page-card{ background:#fff; border-radius:14px; box-shadow:0 8px 24px rgba(15,23,42,.07); overflow:hidden; }
.page-card-head{ padding:16px 18px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.modal-header{ background:linear-gradient(135deg, #000, #e4ac00); color:#fff; }
.uniform-field { width:100%; height:42px; padding:8px 12px; font-size:14px; border-radius:8px; box-sizing:border-box; margin-bottom:12px; border:1px solid #e4ac00; outline:none; background:#fff; }
.uniform-field:focus { border-color:#e4ac00; box-shadow:0 0 0 3px rgba(228,172,0,.16); }
.table th{ white-space:nowrap; }
.action-icon{ cursor:pointer; font-size:18px; }
.client-search-box{ position:relative; }
.client-results{ max-height:260px; overflow-y:auto; border:1px solid #e4ac00; border-radius:10px; background:#fff; margin-top:8px; }
.client-option{ padding:10px 12px; border-bottom:1px solid #f1f1f1; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:10px; }
.client-option:hover, .client-option.active{ background:#fff7db; }
.client-option:last-child{ border-bottom:none; }
.client-option small{ color:#6b7280; }
.client-selected{ background:#111827; color:#fff; border-radius:10px; padding:10px 12px; margin-top:8px; display:none; justify-content:space-between; align-items:center; gap:10px; }
.client-selected.show{ display:flex; }
.debug-pill{ display:inline-flex; align-items:center; gap:6px; background:#fff7db; border:1px solid #e4ac00; color:#1f2937; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:700; }
body.admin-dashboard-page .table-responsive {
    width: 100%;
    overflow-x: auto;
    background: #fff;
    border-radius: 12px;
}

body.admin-dashboard-page .table th,
body.admin-dashboard-page .table td {
    vertical-align: middle;
    white-space: nowrap;
}

body.admin-dashboard-page .modal {
    z-index: 5000 !important;
}

body.admin-dashboard-page .modal-backdrop {
    z-index: 4990 !important;
}

/* Collapsed sidebar text hide */
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-arrow,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow {
    display: none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .submenu-collapse,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse {
    display: none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-link,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-link {
    justify-content: center !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-main,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-main {
    justify-content: center !important;
    gap: 0 !important;
}

/* Mobile */
@media (max-width: 991.98px) {
    body.admin-dashboard-page {
        --admin-current-sidebar-width: 0px !important;
    }

    body.admin-dashboard-page .container-scroll {
        display: block !important;
        width: 100% !important;
        min-height: 100vh !important;
    }

    body.admin-dashboard-page .sidebar,
    body.admin-dashboard-page #dashboardSidebar {
        position: fixed !important;
        top: 0 !important;
        left: -280px !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        margin: 0 !important;
        padding: 0 !important;
        z-index: 3000 !important;
        overflow: hidden !important;
        transition: left .25s ease !important;
    }

    body.admin-dashboard-page .sidebar > div,
    body.admin-dashboard-page #dashboardSidebar > div,
    body.admin-dashboard-page #dashboardSidebar .sidebar-wrap,
    body.admin-dashboard-page #dashboardSidebar .sidebar-inner {
        position: relative !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        max-height: 100dvh !important;
        margin: 0 !important;
        overflow: hidden !important;
        border-radius: 0 !important;
    }

    body.admin-dashboard-page #dashboardSidebar .sidebar-menu {
        height: calc(100dvh - 120px) !important;
        max-height: calc(100dvh - 120px) !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
    }

    body.admin-dashboard-page.sidebar-open #dashboardSidebar,
    body.admin-dashboard-page.dashboard-mobile-sidebar-open #dashboardSidebar {
        left: 0 !important;
    }

    body.admin-dashboard-page .main-panel {
        margin-left: 0 !important;
        width: 100% !important;
        min-width: 0 !important;
        padding-top: 70px !important;
    }

    body.admin-dashboard-page .navbar.my-navbar.fixed-top {
        left: 0 !important;
        width: 100% !important;
        height: 70px !important;
        min-height: 70px !important;
    }

    body.admin-dashboard-page .content-wrapper {
        padding: 16px !important;
        min-height: calc(100vh - 70px) !important;
    }

    body.admin-dashboard-page .page-card-head {
        align-items: flex-start;
    }
}


/* =====================================================
   SUBSCRIPTIONS.PHP - DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html,
body.admin-subscriptions-page{
    margin:0 !important;
    padding:0 !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:#f8fafc !important;
    font-family:Inter, "Segoe UI", Arial, sans-serif !important;
}

/* Layout */
body.admin-subscriptions-page .container-scroll{
    display:flex !important;
    min-height:100vh !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.admin-subscriptions-page .main-panel{
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        radial-gradient(circle at 78% 6%, rgba(59,130,246,.12), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
}

body.admin-subscriptions-page .topbar,
body.admin-subscriptions-page .navbar,
body.admin-subscriptions-page .navbar.my-navbar.fixed-top{
    background:#070b14 !important;
    color:#fff !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

body.admin-subscriptions-page .content-wrapper{
    position:relative !important;
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.08), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:#f8fafc !important;
}

body.admin-subscriptions-page .content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.28;
    z-index:0;
}

body.admin-subscriptions-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Page Header */
body.admin-subscriptions-page .page-header{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-bottom:18px !important;
}

body.admin-subscriptions-page h1,
body.admin-subscriptions-page h2,
body.admin-subscriptions-page h3,
body.admin-subscriptions-page h4,
body.admin-subscriptions-page .page-title{
    color:#f8fafc !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.35) !important;
}

/* Stat Cards */
body.admin-subscriptions-page .stats-grid,
body.admin-subscriptions-page .stats-row{
    display:grid !important;
    grid-template-columns:repeat(4, minmax(0, 1fr)) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

body.admin-subscriptions-page .stat-card{
    min-height:120px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    transition:.25s ease !important;
}

body.admin-subscriptions-page .stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:0;
}

body.admin-subscriptions-page .stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:78px;
    height:78px;
    border-radius:22px;
    background:rgba(255,255,255,.10);
}

body.admin-subscriptions-page .stat-card > *{
    position:relative;
    z-index:1;
}

body.admin-subscriptions-page .stat-card:hover{
    transform:translateY(-4px) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

body.admin-subscriptions-page .stat-blue,
body.admin-subscriptions-page .card-blue{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
}

body.admin-subscriptions-page .stat-green,
body.admin-subscriptions-page .card-green{
    background:linear-gradient(135deg,#047857,#16a34a) !important;
}

body.admin-subscriptions-page .stat-cyan,
body.admin-subscriptions-page .card-cyan{
    background:linear-gradient(135deg,#0891b2,#06b6d4) !important;
}

body.admin-subscriptions-page .stat-gold,
body.admin-subscriptions-page .stat-orange,
body.admin-subscriptions-page .card-yellow{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
}

body.admin-subscriptions-page .stat-red{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
}

body.admin-subscriptions-page .stat-title{
    color:rgba(255,255,255,.88) !important;
    font-size:14px !important;
    font-weight:800 !important;
}

body.admin-subscriptions-page .stat-value{
    color:#fff !important;
    font-size:30px !important;
    font-weight:950 !important;
    margin-top:10px !important;
}

/* Filter Card */
body.admin-subscriptions-page .filter-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    padding:16px !important;
    margin-bottom:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
}

body.admin-subscriptions-page .filter-row,
body.admin-subscriptions-page .filter-toolbar-row{
    display:flex !important;
    gap:10px !important;
    align-items:center !important;
    flex-wrap:wrap !important;
}

body.admin-subscriptions-page .filter-row > *,
body.admin-subscriptions-page .filter-toolbar-row > *{
    flex:1 1 180px !important;
}

body.admin-subscriptions-page .filter-btn,
body.admin-subscriptions-page .reset-btn{
    flex:0 0 auto !important;
}

/* Inputs */
body.admin-subscriptions-page .form-control,
body.admin-subscriptions-page .form-select,
body.admin-subscriptions-page .erp-select,
body.admin-subscriptions-page input,
body.admin-subscriptions-page select,
body.admin-subscriptions-page textarea{
    min-height:46px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    outline:none !important;
    font-weight:800 !important;
}

body.admin-subscriptions-page input::placeholder,
body.admin-subscriptions-page textarea::placeholder{
    color:#94a3b8 !important;
}

body.admin-subscriptions-page .form-control:focus,
body.admin-subscriptions-page .form-select:focus,
body.admin-subscriptions-page .erp-select:focus,
body.admin-subscriptions-page input:focus,
body.admin-subscriptions-page select:focus,
body.admin-subscriptions-page textarea:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

body.admin-subscriptions-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Buttons */
body.admin-subscriptions-page .btn-primary,
body.admin-subscriptions-page .btn-success,
body.admin-subscriptions-page .btn-warning,
body.admin-subscriptions-page .btn-green,
body.admin-subscriptions-page .filter-btn,
body.admin-subscriptions-page button[type="submit"]{
    min-height:44px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.18) !important;
    transition:.22s ease !important;
}

body.admin-subscriptions-page .reset-btn,
body.admin-subscriptions-page .btn-secondary,
body.admin-subscriptions-page .btn-dark,
body.admin-subscriptions-page .btn-light{
    min-height:44px !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-subscriptions-page .btn-danger,
body.admin-subscriptions-page .bulk-delete-btn{
    min-height:42px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 16px !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-subscriptions-page .btn:hover,
body.admin-subscriptions-page button:hover,
body.admin-subscriptions-page .reset-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Table Card */
body.admin-subscriptions-page .table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
}

body.admin-subscriptions-page .table-wrap,
body.admin-subscriptions-page .table-responsive,
body.admin-subscriptions-page .table-responsive-custom{
    overflow:auto !important;
    background:transparent !important;
}

/* Table */
body.admin-subscriptions-page table,
body.admin-subscriptions-page .table,
body.admin-subscriptions-page .erp-table,
body.admin-subscriptions-page .custom-erp-table{
    width:100% !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.06) !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

body.admin-subscriptions-page table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

body.admin-subscriptions-page table thead th,
body.admin-subscriptions-page .erp-table thead th,
body.admin-subscriptions-page .custom-erp-table thead th{
    background:rgba(6,10,20,.95) !important;
    color:#f8fafc !important;
    border:none !important;
    border-bottom:1px solid rgba(139,92,246,.22) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

body.admin-subscriptions-page table tbody td,
body.admin-subscriptions-page .erp-table tbody td,
body.admin-subscriptions-page .custom-erp-table tbody td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.42), rgba(20,12,40,.28)) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:15px 18px !important;
    font-size:14px !important;
    font-weight:750 !important;
    vertical-align:middle !important;
}

/* Remove white hover */
body.admin-subscriptions-page table tbody tr:hover td,
body.admin-subscriptions-page table tbody tr:focus-within td,
body.admin-subscriptions-page .erp-table tbody tr:hover td,
body.admin-subscriptions-page .custom-erp-table tbody tr:hover td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(30,20,58,.58), rgba(15,23,42,.42)) !important;
    color:#ffffff !important;
}

body.admin-subscriptions-page table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    font-weight:850 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.34), rgba(20,12,40,.22)) !important;
}

/* Table Footer */
body.admin-subscriptions-page .table-footer{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
    flex-wrap:wrap !important;
    padding:14px 16px !important;
    background:rgba(7,11,20,.46) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#cbd5e1 !important;
    font-weight:800 !important;
}

/* Badges */
body.admin-subscriptions-page .badge,
body.admin-subscriptions-page .status-badge{
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

body.admin-subscriptions-page .bg-success,
body.admin-subscriptions-page .badge-success{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

body.admin-subscriptions-page .bg-danger,
body.admin-subscriptions-page .badge-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

body.admin-subscriptions-page .bg-warning,
body.admin-subscriptions-page .badge-warning{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}

body.admin-subscriptions-page .bg-dark{
    background:rgba(148,163,184,.16) !important;
    color:#cbd5e1 !important;
    border:1px solid rgba(148,163,184,.25) !important;
}

/* Action Buttons */
body.admin-subscriptions-page .action-group{
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
}

body.admin-subscriptions-page .action-btn,
body.admin-subscriptions-page .btn-edit,
body.admin-subscriptions-page .btn-delete,
body.admin-subscriptions-page .editSubscription,
body.admin-subscriptions-page .deleteSubscription{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.64) !important;
    color:#e5e7eb !important;
    transition:.22s ease !important;
    box-shadow:0 10px 20px rgba(0,0,0,.20) !important;
}

body.admin-subscriptions-page .btn-edit,
body.admin-subscriptions-page .editSubscription{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.26) !important;
}

body.admin-subscriptions-page .btn-delete,
body.admin-subscriptions-page .deleteSubscription{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.26) !important;
}

/* Modal */
body.admin-subscriptions-page .modal{
    z-index:5000 !important;
}

body.admin-subscriptions-page .modal-backdrop.show{
    opacity:.78 !important;
    background:#000 !important;
    backdrop-filter:blur(8px) !important;
}

body.admin-subscriptions-page .modal-dialog{
    max-width:860px !important;
    width:calc(100% - 32px) !important;
    margin:22px auto !important;
}

body.admin-subscriptions-page .modal-content{
    max-height:calc(100vh - 44px) !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.26), transparent 38%),
        radial-gradient(circle at 88% 0%, rgba(34,197,94,.08), transparent 30%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.84)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.38) !important;
    border-radius:20px !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.64),
        0 0 52px rgba(124,58,237,.20),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(24px) saturate(150%) !important;
}

body.admin-subscriptions-page .modal-header{
    flex:0 0 auto !important;
    padding:16px 20px !important;
    background:linear-gradient(135deg, rgba(30,20,58,.72), rgba(7,11,20,.42)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

body.admin-subscriptions-page .modal-title{
    color:#f8fafc !important;
    font-size:22px !important;
    font-weight:900 !important;
}

body.admin-subscriptions-page .modal-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:18px 20px 14px !important;
    background:rgba(15,23,42,.16) !important;
}

body.admin-subscriptions-page .modal-footer{
    flex:0 0 auto !important;
    position:sticky !important;
    bottom:0 !important;
    z-index:20 !important;
    padding:14px 20px !important;
    background:linear-gradient(180deg, rgba(7,11,20,.90), rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    box-shadow:0 -18px 38px rgba(0,0,0,.34) !important;
}

body.admin-subscriptions-page .btn-close{
    width:40px !important;
    height:40px !important;
    border-radius:13px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

/* Scrollbar */
body.admin-subscriptions-page .modal-body::-webkit-scrollbar,
body.admin-subscriptions-page .table-wrap::-webkit-scrollbar,
body.admin-subscriptions-page .table-responsive::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-subscriptions-page .modal-body::-webkit-scrollbar-track,
body.admin-subscriptions-page .table-wrap::-webkit-scrollbar-track,
body.admin-subscriptions-page .table-responsive::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-subscriptions-page .modal-body::-webkit-scrollbar-thumb,
body.admin-subscriptions-page .table-wrap::-webkit-scrollbar-thumb,
body.admin-subscriptions-page .table-responsive::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* Responsive */
@media(max-width:1200px){
    body.admin-subscriptions-page .stats-grid,
    body.admin-subscriptions-page .stats-row{
        grid-template-columns:repeat(2, minmax(0,1fr)) !important;
    }
}

@media(max-width:768px){
    body.admin-subscriptions-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-subscriptions-page .stats-grid,
    body.admin-subscriptions-page .stats-row{
        grid-template-columns:1fr !important;
    }

    body.admin-subscriptions-page .filter-row,
    body.admin-subscriptions-page .filter-toolbar-row{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    body.admin-subscriptions-page .filter-row > *,
    body.admin-subscriptions-page .filter-toolbar-row > *,
    body.admin-subscriptions-page .filter-btn,
    body.admin-subscriptions-page .reset-btn{
        width:100% !important;
        flex:1 1 auto !important;
    }

    body.admin-subscriptions-page table{
        min-width:1050px !important;
    }

    body.admin-subscriptions-page .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:9px auto !important;
    }
}

/* =========================================================
   SUBSCRIPTIONS.PHP - DARK PURPLE GLASS FIX
   Paste at END of style tag
========================================================= */

body.subscriptions-page{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.28), transparent 28%),
        radial-gradient(circle at 86% 6%, rgba(59,130,246,.14), transparent 28%),
        linear-gradient(135deg,#050814 0%, #0a1020 38%, #160f2d 72%, #090c17 100%) !important;
    color:#f8fafc !important;
}

/* main area */
body.subscriptions-page .main-panel,
body.subscriptions-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.24), transparent 30%),
        radial-gradient(circle at 88% 8%, rgba(59,130,246,.10), transparent 26%),
        linear-gradient(135deg,#050814 0%, #0a1020 38%, #160f2d 72%, #090c17 100%) !important;
    color:#f8fafc !important;
}

/* white page card ko glass dark banao */
body.subscriptions-page .card,
body.subscriptions-page .card-box,
body.subscriptions-page .table-card,
body.subscriptions-page .toolbar,
body.subscriptions-page .content-wrapper > .bg-white,
body.subscriptions-page .content-wrapper > .container,
body.subscriptions-page .content-wrapper > .container-fluid,
body.subscriptions-page .content-wrapper .subscription-card,
body.subscriptions-page .content-wrapper .subscriptions-card,
body.subscriptions-page .content-wrapper .page-card{
    background:
        linear-gradient(135deg, rgba(15,23,42,.78), rgba(22,15,45,.58)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:20px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(140%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(140%) !important;
    color:#f8fafc !important;
}

/* title / subtitle */
body.subscriptions-page h1,
body.subscriptions-page h2,
body.subscriptions-page h3,
body.subscriptions-page h4,
body.subscriptions-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
}

body.subscriptions-page p,
body.subscriptions-page small,
body.subscriptions-page .text-muted{
    color:#cbd5e1 !important;
}

/* top info pills like DB / Clients fetched / Plans fetched */
body.subscriptions-page .badge,
body.subscriptions-page .pill,
body.subscriptions-page .chip,
body.subscriptions-page .info-pill,
body.subscriptions-page .debug-pill{
    background:rgba(245,158,11,.12) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.32) !important;
    border-radius:999px !important;
    padding:8px 14px !important;
    font-weight:800 !important;
    box-shadow:none !important;
}

/* add subscription button */
body.subscriptions-page .btn-primary,
body.subscriptions-page .btn-success,
body.subscriptions-page .add-subscription-btn,
body.subscriptions-page a[href*="subscription"],
body.subscriptions-page button[type="button"],
body.subscriptions-page button[type="submit"]{
    border:none !important;
    border-radius:14px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.28),
        0 0 24px rgba(124,58,237,.16) !important;
}

body.subscriptions-page .btn-primary:hover,
body.subscriptions-page .btn-success:hover,
body.subscriptions-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* table wrapper */
body.subscriptions-page .table-responsive,
body.subscriptions-page .table-wrap{
    background:transparent !important;
    overflow:auto !important;
    border-radius:18px !important;
}

/* full table */
body.subscriptions-page table,
body.subscriptions-page .table,
body.subscriptions-page .erp-table{
    width:100% !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg: transparent !important;
    --bs-table-color: #e5e7eb !important;
    --bs-table-striped-bg: rgba(124,58,237,.05) !important;
    --bs-table-hover-bg: rgba(124,58,237,.12) !important;
    --bs-table-border-color: rgba(148,163,184,.10) !important;
}

/* remove bootstrap white */
body.subscriptions-page table > :not(caption) > * > *,
body.subscriptions-page .table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* head */
body.subscriptions-page table thead th,
body.subscriptions-page .erp-table thead th{
    background:rgba(6,10,20,.96) !important;
    color:#f8fafc !important;
    border:none !important;
    border-bottom:1px solid rgba(139,92,246,.24) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

/* body rows */
body.subscriptions-page table tbody td,
body.subscriptions-page .erp-table tbody td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.44), rgba(24,15,45,.26)) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:15px 18px !important;
    font-size:14px !important;
    font-weight:750 !important;
    white-space:nowrap !important;
    vertical-align:middle !important;
}

/* hover white remove */
body.subscriptions-page table tbody tr:hover td,
body.subscriptions-page .erp-table tbody tr:hover td,
body.subscriptions-page table tbody td:hover{
    background:
        radial-gradient(circle at 12% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(32,19,60,.58), rgba(15,23,42,.40)) !important;
    color:#ffffff !important;
}

/* empty row */
body.subscriptions-page table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* status pill */
body.subscriptions-page .status-badge,
body.subscriptions-page .badge.bg-success,
body.subscriptions-page .badge-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.22) !important;
    border-radius:999px !important;
    padding:6px 14px !important;
    font-weight:900 !important;
}

/* action icons */
body.subscriptions-page .action-btn,
body.subscriptions-page .btn-edit,
body.subscriptions-page .btn-delete,
body.subscriptions-page .edit-btn,
body.subscriptions-page .delete-btn,
body.subscriptions-page td .bi-pencil,
body.subscriptions-page td .bi-trash,
body.subscriptions-page td a,
body.subscriptions-page td button{
    border-radius:12px !important;
}

body.subscriptions-page .btn-edit,
body.subscriptions-page .edit-btn{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

body.subscriptions-page .btn-delete,
body.subscriptions-page .delete-btn{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* links / text */
body.subscriptions-page a{
    color:#e5e7eb !important;
}
body.subscriptions-page a:hover{
    color:#fff !important;
}

/* scroll bar */
body.subscriptions-page .table-responsive::-webkit-scrollbar,
body.subscriptions-page .table-wrap::-webkit-scrollbar{
    width:10px;
    height:10px;
}
body.subscriptions-page .table-responsive::-webkit-scrollbar-track,
body.subscriptions-page .table-wrap::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75);
    border-radius:999px;
}
body.subscriptions-page .table-responsive::-webkit-scrollbar-thumb,
body.subscriptions-page .table-wrap::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    border-radius:999px;
}

/* =====================================================
   SUBSCRIPTIONS.PHP FINAL DARK PURPLE GLASS FIX
   Paste at END befor
===================================================== */

body.admin-subscriptions-page,
body.subscriptions-page{
    background:
        radial-gradient(circle at 15% 10%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 85% 8%, rgba(59,130,246,.15), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 42%,#160f2d 72%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-subscriptions-page .main-panel,
body.subscriptions-page .main-panel,
body.admin-subscriptions-page .content-wrapper,
body.subscriptions-page .content-wrapper{
    background:
        radial-gradient(circle at 15% 10%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 85% 8%, rgba(59,130,246,.15), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 42%,#160f2d 72%,#070b14 100%) !important;
    color:#f8fafc !important;
}

/* white outer card remove */
body.admin-subscriptions-page .page-card,
body.subscriptions-page .page-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 35%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(22,15,45,.58)) !important;
    border:1px solid rgba(139,92,246,.28) !important;
    border-radius:20px !important;
    box-shadow:
        0 22px 60px rgba(0,0,0,.35),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(18px) saturate(140%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(140%) !important;
    overflow:hidden !important;
    color:#f8fafc !important;
}

/* card header */
body.admin-subscriptions-page .page-card-head,
body.subscriptions-page .page-card-head{
    background:
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(30,20,58,.55)) !important;
    border-bottom:1px solid rgba(139,92,246,.22) !important;
    color:#f8fafc !important;
}

body.admin-subscriptions-page .page-title,
body.subscriptions-page .page-title,
body.admin-subscriptions-page h1,
body.admin-subscriptions-page h2,
body.admin-subscriptions-page h3,
body.admin-subscriptions-page h4{
    color:#ffffff !important;
    font-weight:900 !important;
    text-shadow:0 0 24px rgba(124,58,237,.35) !important;
}

body.admin-subscriptions-page .page-card p,
body.subscriptions-page .page-card p,
body.admin-subscriptions-page .text-muted,
body.subscriptions-page .text-muted{
    color:#cbd5e1 !important;
}

/* debug pills */
body.admin-subscriptions-page .debug-pill,
body.subscriptions-page .debug-pill{
    background:rgba(245,158,11,.10) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.35) !important;
    border-radius:999px !important;
}

/* table wrapper */
body.admin-subscriptions-page .table-responsive,
body.subscriptions-page .table-responsive{
    background:
        linear-gradient(135deg, rgba(15,23,42,.55), rgba(22,15,45,.35)) !important;
    border-top:1px solid rgba(139,92,246,.20) !important;
    border-radius:0 0 20px 20px !important;
    overflow:auto !important;
}

/* table base */
body.admin-subscriptions-page table.table,
body.subscriptions-page table.table{
    margin:0 !important;
    width:100% !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-striped-bg:rgba(124,58,237,.08) !important;
    --bs-table-hover-bg:rgba(124,58,237,.14) !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

/* Bootstrap white/grey force remove */
body.admin-subscriptions-page table.table > :not(caption) > * > *,
body.subscriptions-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* table header */
body.admin-subscriptions-page table.table thead th,
body.subscriptions-page table.table thead th,
body.admin-subscriptions-page table.table thead.table-dark th,
body.subscriptions-page table.table thead.table-dark th{
    background:rgba(6,10,20,.96) !important;
    color:#ffffff !important;
    border:none !important;
    border-bottom:1px solid rgba(139,92,246,.28) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

/* table body rows dark glass */
body.admin-subscriptions-page table.table tbody tr,
body.subscriptions-page table.table tbody tr{
    background:transparent !important;
}

body.admin-subscriptions-page table.table tbody td,
body.subscriptions-page table.table tbody td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.52), rgba(30,20,58,.28)) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:16px 18px !important;
    font-size:14px !important;
    font-weight:800 !important;
    vertical-align:middle !important;
    white-space:nowrap !important;
}

/* alternate row */
body.admin-subscriptions-page table.table tbody tr:nth-child(even) td,
body.subscriptions-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* white hover remove */
body.admin-subscriptions-page table.table-hover tbody tr:hover td,
body.subscriptions-page table.table-hover tbody tr:hover td,
body.admin-subscriptions-page table.table tbody tr:hover td,
body.subscriptions-page table.table tbody tr:hover td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* active status */
body.admin-subscriptions-page .badge.bg-success,
body.subscriptions-page .badge.bg-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.28) !important;
    border-radius:999px !important;
    padding:7px 14px !important;
    font-weight:900 !important;
}

/* action icons */
body.admin-subscriptions-page .editSub,
body.subscriptions-page .editSub{
    color:#fbbf24 !important;
}

body.admin-subscriptions-page .deleteSub,
body.subscriptions-page .deleteSub{
    color:#fb7185 !important;
}

body.admin-subscriptions-page .editSub,
body.admin-subscriptions-page .deleteSub,
body.subscriptions-page .editSub,
body.subscriptions-page .deleteSub{
    font-size:18px !important;
    cursor:pointer !important;
    transition:.20s ease !important;
}

body.admin-subscriptions-page .editSub:hover,
body.admin-subscriptions-page .deleteSub:hover,
body.subscriptions-page .editSub:hover,
body.subscriptions-page .deleteSub:hover{
    transform:translateY(-2px) scale(1.08) !important;
    filter:brightness(1.2) !important;
}

/* add subscription button */
body.admin-subscriptions-page .btn-warning,
body.admin-subscriptions-page .btn-primary,
body.subscriptions-page .btn-warning,
body.subscriptions-page .btn-primary{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
    border-radius:14px !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.32),
        0 0 28px rgba(124,58,237,.20) !important;
}

body.admin-subscriptions-page .btn-warning:hover,
body.admin-subscriptions-page .btn-primary:hover,
body.subscriptions-page .btn-warning:hover,
body.subscriptions-page .btn-primary:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* modal also dark glass */
body.admin-subscriptions-page .modal-content,
body.subscriptions-page .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.26), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.84)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.38) !important;
    border-radius:20px !important;
    box-shadow:0 28px 80px rgba(0,0,0,.64) !important;
}

body.admin-subscriptions-page .modal-header,
body.admin-subscriptions-page .modal-footer,
body.subscriptions-page .modal-header,
body.subscriptions-page .modal-footer{
    background:rgba(7,11,20,.55) !important;
    border-color:rgba(148,163,184,.16) !important;
}

body.admin-subscriptions-page .modal-title,
body.subscriptions-page .modal-title,
body.admin-subscriptions-page label,
body.subscriptions-page label{
    color:#f8fafc !important;
    font-weight:900 !important;
}

body.admin-subscriptions-page .form-control,
body.admin-subscriptions-page .form-select,
body.admin-subscriptions-page input,
body.admin-subscriptions-page select,
body.subscriptions-page .form-control,
body.subscriptions-page .form-select,
body.subscriptions-page input,
body.subscriptions-page select{
    background:rgba(15,23,42,.78) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.22) !important;
    border-radius:13px !important;
    font-weight:800 !important;
}

body.admin-subscriptions-page select option,
body.subscriptions-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}


/* =====================================================
   SUBSCRIPTIONS.PHP - ADD SUBSCRIPTION POPUP FINAL FIX
   Paste at END before
===================================================== */

body.admin-subscriptions-page .modal,
body.subscriptions-page .modal{
    z-index:5000 !important;
}

body.admin-subscriptions-page .modal-backdrop.show,
body.subscriptions-page .modal-backdrop.show{
    opacity:.82 !important;
    background:#000 !important;
    backdrop-filter:blur(9px) !important;
    -webkit-backdrop-filter:blur(9px) !important;
}

/* popup size chota */
body.admin-subscriptions-page .modal-dialog,
body.subscriptions-page .modal-dialog{
    max-width:760px !important;
    width:calc(100% - 34px) !important;
    margin:18px auto !important;
}

/* main modal glass */
body.admin-subscriptions-page .modal-content,
body.subscriptions-page .modal-content{
    max-height:calc(100vh - 36px) !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;

    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(59,130,246,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.86)) !important;

    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:20px !important;
    color:#f8fafc !important;

    box-shadow:
        0 28px 80px rgba(0,0,0,.68),
        0 0 55px rgba(124,58,237,.22),
        inset 0 1px 0 rgba(255,255,255,.06) !important;

    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
}

/* header */
body.admin-subscriptions-page .modal-header,
body.subscriptions-page .modal-header{
    flex:0 0 auto !important;
    padding:15px 20px !important;
    background:
        linear-gradient(135deg, rgba(30,20,58,.78), rgba(7,11,20,.55)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

body.admin-subscriptions-page .modal-title,
body.subscriptions-page .modal-title,
body.admin-subscriptions-page .modal-header h5,
body.subscriptions-page .modal-header h5{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:950 !important;
    letter-spacing:-.02em !important;
    text-shadow:0 0 24px rgba(124,58,237,.35) !important;
}

/* close btn */
body.admin-subscriptions-page .btn-close,
body.subscriptions-page .btn-close{
    width:40px !important;
    height:40px !important;
    border-radius:13px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
    box-shadow:0 10px 24px rgba(0,0,0,.25) !important;
}

/* modal body scroll */
body.admin-subscriptions-page .modal-body,
body.subscriptions-page .modal-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:16px 20px 12px !important;
    background:rgba(15,23,42,.12) !important;
}

/* footer hamesha visible */
body.admin-subscriptions-page .modal-footer,
body.subscriptions-page .modal-footer{
    flex:0 0 auto !important;
    position:sticky !important;
    bottom:0 !important;
    z-index:20 !important;
    padding:12px 20px !important;
    background:
        linear-gradient(180deg, rgba(7,11,20,.88), rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    box-shadow:0 -18px 35px rgba(0,0,0,.35) !important;
}

/* labels */
body.admin-subscriptions-page .modal label,
body.subscriptions-page .modal label{
    color:#e5e7eb !important;
    font-weight:900 !important;
    font-size:13px !important;
    margin-bottom:7px !important;
}

/* inputs/select */
body.admin-subscriptions-page .modal .form-control,
body.admin-subscriptions-page .modal .form-select,
body.admin-subscriptions-page .modal input,
body.admin-subscriptions-page .modal select,
body.subscriptions-page .modal .form-control,
body.subscriptions-page .modal .form-select,
body.subscriptions-page .modal input,
body.subscriptions-page .modal select{
    min-height:44px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.22) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    font-weight:800 !important;
}

body.admin-subscriptions-page .modal input::placeholder,
body.subscriptions-page .modal input::placeholder{
    color:#94a3b8 !important;
}

body.admin-subscriptions-page .modal select option,
body.subscriptions-page .modal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* client white list box fix */
body.admin-subscriptions-page .modal .list-group,
body.admin-subscriptions-page .modal .client-list,
body.admin-subscriptions-page .modal #clientList,
body.admin-subscriptions-page .modal #clientsList,
body.admin-subscriptions-page .modal .client-results,
body.admin-subscriptions-page .modal .clients-results,
body.subscriptions-page .modal .list-group,
body.subscriptions-page .modal .client-list,
body.subscriptions-page .modal #clientList,
body.subscriptions-page .modal #clientsList,
body.subscriptions-page .modal .client-results,
body.subscriptions-page .modal .clients-results{
    max-height:190px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.78), rgba(30,20,58,.48)) !important;
    border:1px solid rgba(139,92,246,.28) !important;
    border-radius:14px !important;
    padding:6px !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.05) !important;
}

/* list rows */
body.admin-subscriptions-page .modal .list-group-item,
body.admin-subscriptions-page .modal .client-row,
body.admin-subscriptions-page .modal .client-item,
body.subscriptions-page .modal .list-group-item,
body.subscriptions-page .modal .client-row,
body.subscriptions-page .modal .client-item{
    background:rgba(15,23,42,.52) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.10) !important;
    border-radius:10px !important;
    margin-bottom:6px !important;
    padding:10px 12px !important;
    font-weight:750 !important;
    transition:.18s ease !important;
}

/* list hover white remove */
body.admin-subscriptions-page .modal .list-group-item:hover,
body.admin-subscriptions-page .modal .client-row:hover,
body.admin-subscriptions-page .modal .client-item:hover,
body.subscriptions-page .modal .list-group-item:hover,
body.subscriptions-page .modal .client-row:hover,
body.subscriptions-page .modal .client-item:hover{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 36%),
        linear-gradient(135deg, rgba(30,20,58,.68), rgba(15,23,42,.52)) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.42) !important;
}

/* Select link/button inside client list */
body.admin-subscriptions-page .modal .client-row button,
body.admin-subscriptions-page .modal .client-item button,
body.admin-subscriptions-page .modal .list-group-item button,
body.admin-subscriptions-page .modal .select-client,
body.subscriptions-page .modal .client-row button,
body.subscriptions-page .modal .client-item button,
body.subscriptions-page .modal .list-group-item button,
body.subscriptions-page .modal .select-client{
    background:rgba(124,58,237,.18) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(124,58,237,.35) !important;
    border-radius:10px !important;
    padding:6px 10px !important;
    font-weight:900 !important;
}

/* note text */
body.admin-subscriptions-page .modal p,
body.admin-subscriptions-page .modal small,
body.subscriptions-page .modal p,
body.subscriptions-page .modal small{
    color:#cbd5e1 !important;
    font-weight:700 !important;
}

/* buttons */
body.admin-subscriptions-page .modal-footer .btn-primary,
body.admin-subscriptions-page .modal-footer button[type="submit"],
body.subscriptions-page .modal-footer .btn-primary,
body.subscriptions-page .modal-footer button[type="submit"]{
    min-height:44px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 22px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:950 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.18) !important;
}

body.admin-subscriptions-page .modal-footer .btn-secondary,
body.subscriptions-page .modal-footer .btn-secondary{
    min-height:44px !important;
    border-radius:13px !important;
    padding:0 20px !important;
    background:rgba(15,23,42,.86) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    font-weight:900 !important;
}

/* scrollbar */
body.admin-subscriptions-page .modal-body::-webkit-scrollbar,
body.admin-subscriptions-page .modal .client-list::-webkit-scrollbar,
body.admin-subscriptions-page .modal #clientList::-webkit-scrollbar,
body.subscriptions-page .modal-body::-webkit-scrollbar,
body.subscriptions-page .modal .client-list::-webkit-scrollbar,
body.subscriptions-page .modal #clientList::-webkit-scrollbar{
    width:9px !important;
}

body.admin-subscriptions-page .modal-body::-webkit-scrollbar-track,
body.admin-subscriptions-page .modal .client-list::-webkit-scrollbar-track,
body.admin-subscriptions-page .modal #clientList::-webkit-scrollbar-track,
body.subscriptions-page .modal-body::-webkit-scrollbar-track,
body.subscriptions-page .modal .client-list::-webkit-scrollbar-track,
body.subscriptions-page .modal #clientList::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-subscriptions-page .modal-body::-webkit-scrollbar-thumb,
body.admin-subscriptions-page .modal .client-list::-webkit-scrollbar-thumb,
body.admin-subscriptions-page .modal #clientList::-webkit-scrollbar-thumb,
body.subscriptions-page .modal-body::-webkit-scrollbar-thumb,
body.subscriptions-page .modal .client-list::-webkit-scrollbar-thumb,
body.subscriptions-page .modal #clientList::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

</style>
</head>
<body class="admin-dashboard-page admin-subscriptions-page">
<div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">
            <div class="page-card">
                <div class="page-card-head">
                    <div>
                        <h4 class="mb-0">Subscriptions</h4>
                        <small class="text-muted">Manage client plans, dates and subscription status</small><br>
                        <span class="debug-pill mt-2"><i class="bi bi-database"></i> DB: <?= e($current_db) ?></span>
                        <span class="debug-pill mt-2"><i class="bi bi-people"></i> Clients fetched: <?= number_format(count($clients)) ?></span>
                        <span class="debug-pill mt-2"><i class="bi bi-card-checklist"></i> Plans fetched: <?= number_format(count($plans)) ?></span>
                    </div>
                    <button type="button" class="btn btn-warning" onclick="openAdd()">
                        <i class="bi bi-plus-circle"></i> Add Subscription
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th width="70">ID</th>
                                <th>Client</th>
                                <th>Plan</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th width="110">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($subs)): ?>
                            <tr><td colspan="7" class="text-center py-4">No subscriptions found</td></tr>
                        <?php endif; ?>

                        <?php foreach ($subs as $s): ?>
                            <tr>
                                <td><?= (int)$s['id'] ?></td>
                                <td><?= e($s['business_name']) ?></td>
                                <td><?= e($s['plan_name']) ?></td>
                                <td><?= e($s['start_date']) ?></td>
                                <td><?= e($s['end_date']) ?></td>
                                <td><span class="badge <?= e(status_badge_class($s['status'])) ?>"><?= e(ucfirst($s['status'])) ?></span></td>
                                <td class="text-center">
                                    <i class="bi bi-pencil-square text-warning me-2 editSub action-icon"
                                       data-id="<?= (int)$s['id'] ?>"
                                       data-client="<?= (int)$s['client_id'] ?>"
                                       data-plan="<?= (int)$s['plan_id'] ?>"
                                       data-start="<?= e($s['start_date']) ?>"
                                       data-end="<?= e($s['end_date']) ?>"
                                       data-status="<?= e($s['status']) ?>"></i>
                                    <i class="bi bi-trash text-danger deleteSub action-icon" data-id="<?= (int)$s['id'] ?>"></i>
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

<!-- ADD/EDIT SUBSCRIPTION MODAL -->
<div class="modal fade" id="subModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="subForm">
                <input type="hidden" name="sub_id" id="sub_id">
                <input type="hidden" name="client_id" id="sub_client_id" required>
                <div class="modal-header">
                    <h5 id="subModalTitle" class="modal-title">Add Subscription</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Client <small class="text-muted">(<?= count($clients) ?> total, all from clients table)</small></label>
                        <div class="client-search-box">
                            <input type="text" id="clientSearch" class="uniform-field mb-0" placeholder="Search by ID, client name, or phone...">
                            <div class="client-selected" id="clientSelected">
                                <span id="clientSelectedText"></span>
                                <button type="button" class="btn btn-sm btn-light" id="clearClientBtn">Change</button>
                            </div>
                            <div class="client-results" id="clientResults"></div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Plan <small class="text-muted">(<?= count($plans) ?> total, all from plans table)</small></label>
                        <select name="plan_id" id="sub_plan" class="uniform-field" required>
                            <option value="">Select Plan</option>
                            <?php foreach ($plans as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= e(plan_label($p)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Agar new plan na aaye to test URL open karein: subscriptions.php?ajax=get_plans</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="sub_start" class="uniform-field" required>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" id="sub_end" class="uniform-field" required>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select name="status" id="sub_status" class="uniform-field" required>
                            <option value="active">Active</option>
                            <option value="expired">Expired</option>
                            <option value="trial">Trial</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const allClients = <?= json_encode(array_map(function($c){ return ['id'=>(int)$c['id'], 'label'=>client_label($c)]; }, $clients), JSON_UNESCAPED_UNICODE) ?>;
const subModal = new bootstrap.Modal(document.getElementById('subModal'));
const clientResults = document.getElementById('clientResults');
const clientSearch = document.getElementById('clientSearch');
const subClientId = document.getElementById('sub_client_id');
const clientSelected = document.getElementById('clientSelected');
const clientSelectedText = document.getElementById('clientSelectedText');
const clearClientBtn = document.getElementById('clearClientBtn');

function renderClients(keyword = '') {
    keyword = String(keyword || '').toLowerCase().trim();
    clientResults.innerHTML = '';

    const filtered = allClients.filter(c => String(c.label).toLowerCase().includes(keyword));

    if (!filtered.length) {
        clientResults.innerHTML = '<div class="client-option"><span>No client found</span></div>';
        return;
    }

    filtered.forEach(c => {
        const div = document.createElement('div');
        div.className = 'client-option';
        div.dataset.id = c.id;
        div.dataset.label = c.label;
        div.innerHTML = `<span>${escapeHtml(c.label)}</span><small>Select</small>`;
        div.addEventListener('click', () => selectClient(c.id, c.label));
        clientResults.appendChild(div);
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.innerText = String(text || '');
    return div.innerHTML;
}

function selectClient(id, label) {
    subClientId.value = id;
    clientSelectedText.innerText = label;
    clientSelected.classList.add('show');
    clientResults.style.display = 'none';
    clientSearch.value = '';
}

function clearClient() {
    subClientId.value = '';
    clientSelectedText.innerText = '';
    clientSelected.classList.remove('show');
    clientResults.style.display = 'block';
    clientSearch.value = '';
    renderClients('');
    clientSearch.focus();
}

clearClientBtn.addEventListener('click', clearClient);
clientSearch.addEventListener('input', () => {
    clientResults.style.display = 'block';
    renderClients(clientSearch.value);
});
clientSearch.addEventListener('focus', () => {
    clientResults.style.display = 'block';
    renderClients(clientSearch.value);
});

function setClientById(id) {
    const found = allClients.find(c => String(c.id) === String(id));
    if (found) selectClient(found.id, found.label);
    else clearClient();
}

function openAdd(){
    document.getElementById('subForm').reset();
    document.getElementById('sub_id').value = '';
    document.getElementById('sub_status').value = 'active';
    document.getElementById('subModalTitle').innerText = 'Add Subscription';
    clearClient();
    subModal.show();
}

document.querySelectorAll('.editSub').forEach(icon => {
    icon.addEventListener('click', () => {
        document.getElementById('sub_id').value = icon.dataset.id || '';
        document.getElementById('sub_plan').value = icon.dataset.plan || '';
        document.getElementById('sub_start').value = icon.dataset.start || '';
        document.getElementById('sub_end').value = icon.dataset.end || '';
        document.getElementById('sub_status').value = String(icon.dataset.status || 'active').toLowerCase();
        document.getElementById('subModalTitle').innerText = 'Edit Subscription';
        setClientById(icon.dataset.client || '');
        subModal.show();
    });
});

document.querySelectorAll('.deleteSub').forEach(icon => {
    icon.addEventListener('click', () => {
        if (!confirm('Delete this subscription?')) return;
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax=delete_subscription&id=${encodeURIComponent(icon.dataset.id)}`
        })
        .then(res => res.text())
        .then(res => {
            if (res.trim() === 'deleted') location.reload();
            else alert(res);
        })
        .catch(() => alert('Delete request failed.'));
    });
});

document.getElementById('subForm').addEventListener('submit', e => {
    e.preventDefault();

    if (!subClientId.value) {
        alert('Please select client.');
        clientSearch.focus();
        return;
    }

    const formData = new FormData(e.target);
    formData.append('ajax', 'save_subscription');

    fetch('', { method: 'POST', body: formData })
    .then(res => res.text())
    .then(res => {
        if (res.trim() === 'ok') location.reload();
        else alert(res);
    })
    .catch(() => alert('Save request failed.'));
});

renderClients('');
</script>
</body>
</html>
