<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found. Please check config/db.php and make sure mysqli variable $conn exists.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$logged_user_id   = (int)($_SESSION['user_id'] ?? 0);
$logged_role_id   = (int)($_SESSION['role_id'] ?? 0);
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);
$session_branch_id = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
$is_superadmin = ($logged_role_id === 1);

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0) > 0);
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0) > 0);
}

function ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
    if (!column_exists($conn, $table, $column)) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function flash_set(string $type, string $message): void
{
    $_SESSION['restaurant_waiter_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function flash_get(): ?array
{
    if (empty($_SESSION['restaurant_waiter_flash'])) {
        return null;
    }
    $flash = $_SESSION['restaurant_waiter_flash'];
    unset($_SESSION['restaurant_waiter_flash']);
    return $flash;
}

function redirect_self(array $params = []): void
{
    $query = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $url = basename($_SERVER['PHP_SELF']);
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    header('Location: ' . $url);
    exit;
}

/* ================= RESTAURANT WAITERS TABLE SAFETY =================
   Migration file already creates this table. This small safety block keeps
   page working if the migration was not imported yet.
===================================================================== */
$conn->query("CREATE TABLE IF NOT EXISTS `restaurant_waiters` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `client_id` INT(11) NOT NULL,
    `branch_id` INT(11) NOT NULL DEFAULT 0,
    `user_id` INT(11) DEFAULT NULL,
    `name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_waiters_client_branch` (`client_id`, `branch_id`),
    KEY `idx_waiters_user` (`user_id`),
    KEY `idx_waiters_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

ensure_column($conn, 'restaurant_waiters', 'client_id', 'INT(11) NOT NULL DEFAULT 0 AFTER `id`');
ensure_column($conn, 'restaurant_waiters', 'branch_id', 'INT(11) NOT NULL DEFAULT 0 AFTER `client_id`');
ensure_column($conn, 'restaurant_waiters', 'user_id', 'INT(11) DEFAULT NULL AFTER `branch_id`');
ensure_column($conn, 'restaurant_waiters', 'name', 'VARCHAR(150) NOT NULL AFTER `user_id`');
ensure_column($conn, 'restaurant_waiters', 'phone', 'VARCHAR(50) DEFAULT NULL AFTER `name`');
ensure_column($conn, 'restaurant_waiters', 'email', 'VARCHAR(150) DEFAULT NULL AFTER `phone`');
ensure_column($conn, 'restaurant_waiters', 'status', "ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `email`");
ensure_column($conn, 'restaurant_waiters', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `status`');
ensure_column($conn, 'restaurant_waiters', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

/* ================= CLIENT CONTEXT ================= */
$selected_client_id = $logged_client_id;
$clients = [];

if ($is_superadmin) {
    if (table_exists($conn, 'clients')) {
        $res = $conn->query("SELECT id, COALESCE(NULLIF(business_name,''), CONCAT('Client #', id)) AS business_name FROM clients ORDER BY business_name ASC");
        while ($row = $res->fetch_assoc()) {
            $clients[] = $row;
        }
    }

    if (isset($_GET['client_id']) && (int)$_GET['client_id'] > 0) {
        $selected_client_id = (int)$_GET['client_id'];
    } elseif (!empty($clients)) {
        $selected_client_id = (int)$clients[0]['id'];
    }
}

if ($selected_client_id <= 0 && !$is_superadmin) {
    die('Client ID not found in session. Please login again.');
}

/* ================= BRANCHES / USERS ================= */
$branches = [];
if (table_exists($conn, 'branches')) {
    $stmt = $conn->prepare('SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC');
    $stmt->bind_param('i', $selected_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $branches[] = $row;
    }
    $stmt->close();
}

$default_branch_id = $session_branch_id;
if ($default_branch_id <= 0 && !empty($branches)) {
    $default_branch_id = (int)$branches[0]['id'];
}

$staff_users = [];
if (table_exists($conn, 'users')) {
    $stmt = $conn->prepare("SELECT id, name, email, phone, status FROM users WHERE client_id = ? ORDER BY name ASC, id ASC");
    $stmt->bind_param('i', $selected_client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $staff_users[] = $row;
    }
    $stmt->close();
}

/* ================= POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'save_waiter') {
            $waiter_id = (int)($_POST['waiter_id'] ?? 0);
            $user_id   = (int)($_POST['user_id'] ?? 0);
            $branch_id = (int)($_POST['branch_id'] ?? 0);
            $name      = trim((string)($_POST['name'] ?? ''));
            $phone     = trim((string)($_POST['phone'] ?? ''));
            $email     = trim((string)($_POST['email'] ?? ''));
            $status    = strtolower(trim((string)($_POST['status'] ?? 'active')));

            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            if ($branch_id <= 0) {
                $branch_id = $default_branch_id;
            }

            if ($user_id > 0 && table_exists($conn, 'users')) {
                $stmt = $conn->prepare('SELECT name, email, phone FROM users WHERE id = ? AND client_id = ? LIMIT 1');
                $stmt->bind_param('ii', $user_id, $selected_client_id);
                $stmt->execute();
                $userRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$userRow) {
                    throw new Exception('Selected staff/user is invalid for this client.');
                }

                if ($name === '') {
                    $name = trim((string)($userRow['name'] ?? ''));
                }
                if ($phone === '') {
                    $phone = trim((string)($userRow['phone'] ?? ''));
                }
                if ($email === '') {
                    $email = trim((string)($userRow['email'] ?? ''));
                }

                $stmt = $conn->prepare('SELECT id FROM restaurant_waiters WHERE client_id = ? AND user_id = ? AND id <> ? LIMIT 1');
                $stmt->bind_param('iii', $selected_client_id, $user_id, $waiter_id);
                $stmt->execute();
                $duplicate = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($duplicate) {
                    throw new Exception('This staff/user is already linked with another waiter.');
                }
            }

            if ($name === '') {
                throw new Exception('Waiter name is required.');
            }

            if ($waiter_id > 0) {
                $stmt = $conn->prepare('UPDATE restaurant_waiters SET branch_id = ?, user_id = ?, name = ?, phone = ?, email = ?, status = ? WHERE id = ? AND client_id = ?');
                $nullableUserId = ($user_id > 0) ? $user_id : null;
                $stmt->bind_param('iissssii', $branch_id, $nullableUserId, $name, $phone, $email, $status, $waiter_id, $selected_client_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();

                flash_set('success', 'Waiter updated successfully.');
            } else {
                $stmt = $conn->prepare('INSERT INTO restaurant_waiters (client_id, branch_id, user_id, name, phone, email, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $nullableUserId = ($user_id > 0) ? $user_id : null;
                $stmt->bind_param('iiissss', $selected_client_id, $branch_id, $nullableUserId, $name, $phone, $email, $status);
                $stmt->execute();
                $stmt->close();

                flash_set('success', 'Waiter added successfully.');
            }

            redirect_self();
        }

        if ($action === 'toggle_status') {
            $waiter_id = (int)($_POST['waiter_id'] ?? 0);
            if ($waiter_id <= 0) {
                throw new Exception('Invalid waiter ID.');
            }

            $stmt = $conn->prepare("UPDATE restaurant_waiters SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ? AND client_id = ?");
            $stmt->bind_param('ii', $waiter_id, $selected_client_id);
            $stmt->execute();
            $stmt->close();

            flash_set('success', 'Waiter status updated.');
            redirect_self();
        }

        if ($action === 'delete_waiter') {
            $waiter_id = (int)($_POST['waiter_id'] ?? 0);
            if ($waiter_id <= 0) {
                throw new Exception('Invalid waiter ID.');
            }

            $sessionCount = 0;
            if (table_exists($conn, 'restaurant_table_sessions')) {
                $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM restaurant_table_sessions WHERE waiter_id = ? AND client_id = ?');
                $stmt->bind_param('ii', $waiter_id, $selected_client_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $sessionCount = (int)($row['c'] ?? 0);
                $stmt->close();
            }

            if ($sessionCount > 0) {
                $stmt = $conn->prepare("UPDATE restaurant_waiters SET status = 'inactive' WHERE id = ? AND client_id = ?");
                $stmt->bind_param('ii', $waiter_id, $selected_client_id);
                $stmt->execute();
                $stmt->close();
                flash_set('warning', 'This waiter has order history, so it was marked inactive instead of deleted.');
            } else {
                $stmt = $conn->prepare('DELETE FROM restaurant_waiters WHERE id = ? AND client_id = ?');
                $stmt->bind_param('ii', $waiter_id, $selected_client_id);
                $stmt->execute();
                $stmt->close();
                flash_set('success', 'Waiter deleted successfully.');
            }

            redirect_self();
        }
    } catch (Throwable $e) {
        flash_set('danger', $e->getMessage());
        redirect_self();
    }
}

/* ================= FILTERS ================= */
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

$where = ['w.client_id = ?'];
$params = [$selected_client_id];
$types = 'i';

if ($statusFilter !== 'all') {
    $where[] = 'w.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($search !== '') {
    $where[] = '(w.name LIKE ? OR w.phone LIKE ? OR w.email LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR b.name LIKE ?)';
    $like = '%' . $search . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

$hasSessions = table_exists($conn, 'restaurant_table_sessions');
$runningSelect = $hasSessions
    ? "(SELECT COUNT(*) FROM restaurant_table_sessions rts WHERE rts.waiter_id = w.id AND rts.client_id = w.client_id AND rts.status IN ('open','kot_sent','bill_printed')) AS running_tables"
    : "0 AS running_tables";

$sql = "
    SELECT
        w.*,
        u.name AS user_name,
        u.email AS user_email,
        u.phone AS user_phone,
        b.name AS branch_name,
        $runningSelect
    FROM restaurant_waiters w
    LEFT JOIN users u ON u.id = w.user_id
    LEFT JOIN branches b ON b.id = w.branch_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(w.status, 'active', 'inactive'), w.id DESC
";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$waiters = [];
while ($row = $res->fetch_assoc()) {
    $waiters[] = $row;
}
$stmt->close();

/* ================= STATS ================= */
$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'linked' => 0,
    'running' => 0,
];

$stmt = $conn->prepare("SELECT COUNT(*) total, SUM(status='active') active_count, SUM(status='inactive') inactive_count, SUM(user_id IS NOT NULL AND user_id > 0) linked_count FROM restaurant_waiters WHERE client_id = ?");
$stmt->bind_param('i', $selected_client_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$stats['total'] = (int)($row['total'] ?? 0);
$stats['active'] = (int)($row['active_count'] ?? 0);
$stats['inactive'] = (int)($row['inactive_count'] ?? 0);
$stats['linked'] = (int)($row['linked_count'] ?? 0);

if ($hasSessions) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM restaurant_table_sessions WHERE client_id = ? AND status IN ('open','kot_sent','bill_printed') AND waiter_id IS NOT NULL");
    $stmt->bind_param('i', $selected_client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stats['running'] = (int)($row['c'] ?? 0);
}

$flash = flash_get();
$userJson = [];
foreach ($staff_users as $u) {
    $userJson[(int)$u['id']] = [
        'name' => (string)($u['name'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
        'phone' => (string)($u['phone'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Restaurant Waiters - One ERP</title>

<?php if (file_exists(__DIR__ . '/partials/head.php')) { include(__DIR__ . '/partials/head.php'); } ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
:root{
    --rw-sidebar-width:270px;
    --rw-topbar-height:70px;
    --rw-bg:#070b14;
    --rw-card:rgba(15,23,42,.68);
    --rw-card-2:rgba(17,24,39,.48);
    --rw-border:rgba(148,163,184,.16);
    --rw-text:#f8fafc;
    --rw-muted:#94a3b8;
    --rw-purple:#6d4cff;
    --rw-purple-2:#7c3aed;
    --rw-green:#10b981;
    --rw-orange:#f59e0b;
    --rw-red:#ef4444;
    --rw-blue:#3b82f6;
}
html, body.restaurant-waiters-page{
    margin:0 !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.32), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.14), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:var(--rw-text) !important;
    font-family:Inter,"Segoe UI",Arial,sans-serif !important;
}
body.restaurant-waiters-page .container-scroll{
    min-height:100vh !important;
    width:100% !important;
    background:transparent !important;
}
body.restaurant-waiters-page .sidebar,
body.restaurant-waiters-page #dashboardSidebar{
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    width:var(--rw-sidebar-width) !important;
    height:100vh !important;
    z-index:1040 !important;
}
body.restaurant-waiters-page .main-panel{
    min-height:100vh !important;
    margin-left:var(--rw-sidebar-width) !important;
    width:calc(100% - var(--rw-sidebar-width)) !important;
    padding-top:var(--rw-topbar-height) !important;
    background:transparent !important;
    transition:.25s ease !important;
}
body.sidebar-collapsed.restaurant-waiters-page .main-panel{
    margin-left:0 !important;
    width:100% !important;
}
body.restaurant-waiters-page .navbar.fixed-top,
body.restaurant-waiters-page .my-navbar.fixed-top{
    left:var(--rw-sidebar-width) !important;
    width:calc(100% - var(--rw-sidebar-width)) !important;
    z-index:1030 !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}
body.sidebar-collapsed.restaurant-waiters-page .navbar.fixed-top,
body.sidebar-collapsed.restaurant-waiters-page .my-navbar.fixed-top{
    left:0 !important;
    width:100% !important;
}
.rw-content{
    position:relative;
    min-height:calc(100vh - var(--rw-topbar-height));
    padding:34px 22px 44px;
    background:
        radial-gradient(circle at 10% 2%, rgba(109,76,255,.18), transparent 26%),
        radial-gradient(circle at 88% 12%, rgba(59,130,246,.10), transparent 32%),
        radial-gradient(circle at 95% 90%, rgba(16,185,129,.06), transparent 24%),
        linear-gradient(135deg,#070b14 0%,#0b1024 48%,#160f2d 100%);
    overflow:hidden;
}
.rw-content::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.028) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.24;
}
.rw-content > *{ position:relative; z-index:1; }
.rw-page-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    margin-bottom:18px;
}
.rw-title h1{
    margin:0;
    color:#fff;
    font-size:26px;
    line-height:1.15;
    font-weight:950;
    letter-spacing:-.05em;
    text-shadow:0 0 28px rgba(124,58,237,.38);
}
.rw-title p{
    margin:6px 0 0;
    color:#cbd5e1;
    font-size:13px;
    font-weight:800;
}
.rw-actions{ display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
.rw-btn{
    min-height:44px;
    border:0;
    border-radius:13px;
    padding:0 18px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    color:#fff !important;
    font-weight:900;
    text-decoration:none;
    transition:.22s ease;
    box-shadow:0 14px 28px rgba(0,0,0,.24);
}
.rw-btn:hover{ transform:translateY(-2px); color:#fff !important; }
.rw-btn-primary{ background:linear-gradient(135deg,#10b981,#059669); }
.rw-btn-purple{ background:linear-gradient(135deg,#6d4cff,#7c3aed); }
.rw-btn-red{ background:linear-gradient(135deg,#ef4444,#dc2626); }
.rw-btn-dark{ background:rgba(15,23,42,.84); border:1px solid rgba(148,163,184,.20); }
.rw-stat-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:13px;
    margin-bottom:16px;
}
.rw-stat-card{
    position:relative;
    min-height:96px;
    border-radius:16px;
    overflow:hidden;
    padding:22px 18px;
    color:#fff;
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 18px 42px rgba(0,0,0,.26);
}
.rw-stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:64px;
    height:64px;
    border-radius:17px;
    background:rgba(255,255,255,.14);
}
.rw-stat-card .stat-label{
    display:flex;
    align-items:center;
    gap:8px;
    position:relative;
    z-index:2;
    font-size:13px;
    font-weight:950;
    color:#fff;
    margin-bottom:12px;
}
.rw-stat-card .stat-value{
    position:absolute;
    right:22px;
    top:31px;
    z-index:3;
    font-size:28px;
    font-weight:950;
    letter-spacing:-.04em;
}
.stat-purple{ background:linear-gradient(135deg,#5b38e8,#7c3aed); }
.stat-green{ background:linear-gradient(135deg,#058f63,#10a447); }
.stat-orange{ background:linear-gradient(135deg,#f59e0b,#ff8a1d); }
.stat-red{ background:linear-gradient(135deg,#dc1f4b,#ff3b3b); }
.stat-blue{ background:linear-gradient(135deg,#2563eb,#38bdf8); }
.rw-filter-card,
.rw-table-card{
    background:linear-gradient(135deg, rgba(15,23,42,.78), rgba(17,24,39,.48));
    border:1px solid rgba(148,163,184,.16);
    border-radius:18px;
    box-shadow:0 18px 45px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05);
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
}
.rw-filter-card{
    padding:12px;
    margin-bottom:15px;
}
.rw-filter-grid{
    display:grid;
    grid-template-columns:170px 1fr 130px 112px;
    gap:9px;
    align-items:center;
}
.rw-quick{
    min-height:40px;
    border-radius:12px;
    border:1px solid rgba(148,163,184,.18);
    background:rgba(15,23,42,.58);
    color:#f8fafc;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    font-weight:900;
    font-size:13px;
}
.rw-input,
.rw-select{
    width:100%;
    min-height:40px;
    border-radius:12px;
    border:1px solid rgba(148,163,184,.20);
    background:rgba(15,23,42,.74);
    color:#fff;
    padding:0 13px;
    outline:0;
    font-weight:800;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
}
.rw-input::placeholder{ color:#94a3b8; }
.rw-input:focus,
.rw-select:focus{
    border-color:rgba(124,58,237,.62);
    box-shadow:0 0 0 3px rgba(124,58,237,.16);
}
.rw-select option{ background:#0f172a; color:#e5e7eb; }
.rw-table-card{ overflow:hidden; }
.rw-table-wrap{ width:100%; overflow:auto; }
.rw-table{
    width:100%;
    min-width:1060px;
    margin:0;
    border-collapse:separate;
    border-spacing:0;
    color:#e5e7eb;
}
.rw-table thead th{
    background:rgba(5,8,18,.92);
    color:#dbe4ff;
    border:0;
    border-bottom:1px solid rgba(148,163,184,.16);
    padding:14px 14px;
    font-size:11px;
    font-weight:950;
    letter-spacing:.06em;
    text-transform:uppercase;
    white-space:nowrap;
}
.rw-table tbody td{
    background:linear-gradient(135deg, rgba(15,23,42,.38), rgba(20,12,40,.26));
    border:0;
    border-bottom:1px solid rgba(148,163,184,.08);
    color:#e5e7eb;
    padding:14px;
    vertical-align:middle;
    font-size:13px;
    font-weight:750;
}
.rw-table tbody tr:hover td{
    background:linear-gradient(135deg, rgba(30,20,58,.58), rgba(15,23,42,.42));
    color:#fff;
}
.rw-name-cell{ display:flex; align-items:center; gap:10px; min-width:220px; }
.rw-avatar{
    width:38px;
    height:38px;
    min-width:38px;
    border-radius:14px;
    background:linear-gradient(135deg,#6d4cff,#10b981);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:950;
    box-shadow:0 10px 24px rgba(109,76,255,.18);
}
.rw-name-text strong{ display:block; color:#fff; font-weight:950; line-height:1.2; }
.rw-name-text span{ display:block; color:#94a3b8; font-size:12px; margin-top:2px; }
.rw-badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    min-height:28px;
    padding:0 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:950;
    border:1px solid transparent;
    white-space:nowrap;
}
.rw-badge.active{ background:rgba(16,185,129,.14); color:#86efac; border-color:rgba(16,185,129,.25); }
.rw-badge.inactive{ background:rgba(239,68,68,.14); color:#fecaca; border-color:rgba(239,68,68,.25); }
.rw-badge.linked{ background:rgba(109,76,255,.16); color:#ddd6fe; border-color:rgba(109,76,255,.25); }
.rw-badge.branch{ background:rgba(59,130,246,.14); color:#bfdbfe; border-color:rgba(59,130,246,.22); }
.rw-icon-btn{
    width:36px;
    height:36px;
    border:0;
    border-radius:12px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    margin:0 2px;
    transition:.2s ease;
}
.rw-icon-btn:hover{ transform:translateY(-2px); color:#fff; }
.rw-edit{ background:rgba(245,158,11,.18); color:#fde68a; }
.rw-toggle{ background:rgba(109,76,255,.20); color:#ddd6fe; }
.rw-delete{ background:rgba(239,68,68,.20); color:#fecaca; }
.rw-empty{
    padding:34px 14px !important;
    text-align:center;
    color:#dbe4ff !important;
    font-weight:950 !important;
}
.rw-alert{
    border-radius:14px;
    border:1px solid rgba(255,255,255,.12);
    box-shadow:0 16px 34px rgba(0,0,0,.22);
    font-weight:850;
}
.modal{ z-index:5000 !important; }
.modal-backdrop{ z-index:4990 !important; }
.modal-backdrop.show{ opacity:.78; background:#000; backdrop-filter:blur(8px); }
.rw-modal .modal-dialog{ max-width:820px; }
.rw-modal .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.97), rgba(17,24,39,.86));
    color:#f8fafc;
    border:1px solid rgba(139,92,246,.36);
    border-radius:20px;
    box-shadow:0 28px 80px rgba(0,0,0,.62), 0 0 52px rgba(124,58,237,.20), inset 0 1px 0 rgba(255,255,255,.06);
    overflow:hidden;
    backdrop-filter:blur(24px) saturate(150%);
}
.rw-modal .modal-header,
.rw-modal .modal-footer{
    background:rgba(7,11,20,.42);
    border-color:rgba(148,163,184,.16);
}
.rw-modal .modal-title{ font-weight:950; letter-spacing:-.03em; }
.rw-modal .form-label{ color:#dbe4ff; font-size:13px; font-weight:900; }
.rw-modal .form-control,
.rw-modal .form-select{
    min-height:44px;
    border-radius:13px;
    background:rgba(15,23,42,.78);
    color:#f8fafc;
    border:1px solid rgba(148,163,184,.20);
    font-weight:800;
}
.rw-modal .form-control:focus,
.rw-modal .form-select:focus{
    background:rgba(17,24,39,.94);
    color:#fff;
    border-color:rgba(124,58,237,.62);
    box-shadow:0 0 0 3px rgba(124,58,237,.16);
}
.rw-modal .form-control::placeholder{ color:#94a3b8; }
.rw-modal .form-select option{ background:#0f172a; color:#e5e7eb; }
.rw-modal .btn-close{ filter:invert(1) grayscale(1); opacity:1; background-color:rgba(148,163,184,.16); border-radius:12px; }
.client-filter-box{ min-width:260px; }
@media(max-width:1200px){ .rw-stat-grid{ grid-template-columns:repeat(3,minmax(0,1fr)); } .rw-filter-grid{ grid-template-columns:1fr 1fr; } }
@media(max-width:991px){
    body.restaurant-waiters-page .sidebar,
    body.restaurant-waiters-page #dashboardSidebar{ transform:translateX(-100%); }
    body.restaurant-waiters-page.sidebar-open .sidebar,
    body.restaurant-waiters-page.sidebar-open #dashboardSidebar{ transform:translateX(0); }
    body.restaurant-waiters-page .main-panel{ margin-left:0 !important; width:100% !important; }
    body.restaurant-waiters-page .navbar.fixed-top,
    body.restaurant-waiters-page .my-navbar.fixed-top{ left:0 !important; width:100% !important; }
    .rw-content{ padding:22px 14px 34px; }
    .rw-page-head{ flex-direction:column; }
    .rw-actions{ width:100%; justify-content:flex-start; }
    .rw-stat-grid{ grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@media(max-width:576px){
    .rw-title h1{ font-size:22px; }
    .rw-stat-grid{ grid-template-columns:1fr; }
    .rw-filter-grid{ grid-template-columns:1fr; }
    .rw-btn{ width:100%; }
    .rw-modal .modal-dialog{ margin:10px; }
}
</style>
</head>
<body class="restaurant-waiters-page">

<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) { include(__DIR__ . '/partials/sidebar.php'); } ?>
    </div>

    <div class="main-panel">
        <?php if (file_exists(__DIR__ . '/partials/topbar.php')) { include(__DIR__ . '/partials/topbar.php'); } ?>

        <main class="rw-content">
            <div class="rw-page-head">
                <div class="rw-title">
                    <h1>Restaurant Waiters</h1>
                    <p>Assign staff/users as waiters for table sessions, KOT, and waiter-wise sales reports.</p>
                </div>
                <div class="rw-actions">
                    <?php if ($is_superadmin && !empty($clients)): ?>
                        <form method="GET" class="client-filter-box">
                            <select name="client_id" class="rw-select" onchange="this.form.submit()">
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= (int)$client['id'] ?>" <?= ((int)$client['id'] === $selected_client_id) ? 'selected' : '' ?>>
                                        <?= esc($client['business_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="rw-btn rw-btn-primary" onclick="openWaiterModal()">
                        <i class="bi bi-plus-lg"></i> Add Waiter
                    </button>
                    <a href="restaurant_waiters.php<?= $is_superadmin ? '?client_id=' . (int)$selected_client_id : '' ?>" class="rw-btn rw-btn-purple">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </a>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= esc($flash['type']) ?> rw-alert alert-dismissible fade show" role="alert">
                    <?= esc($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <section class="rw-stat-grid">
                <div class="rw-stat-card stat-purple">
                    <div class="stat-label"><i class="bi bi-people"></i> Total Waiters</div>
                    <div class="stat-value"><?= (int)$stats['total'] ?></div>
                </div>
                <div class="rw-stat-card stat-green">
                    <div class="stat-label"><i class="bi bi-check2-circle"></i> Active</div>
                    <div class="stat-value"><?= (int)$stats['active'] ?></div>
                </div>
                <div class="rw-stat-card stat-orange">
                    <div class="stat-label"><i class="bi bi-person-check"></i> Linked Users</div>
                    <div class="stat-value"><?= (int)$stats['linked'] ?></div>
                </div>
                <div class="rw-stat-card stat-blue">
                    <div class="stat-label"><i class="bi bi-table"></i> Running Tables</div>
                    <div class="stat-value"><?= (int)$stats['running'] ?></div>
                </div>
                <div class="rw-stat-card stat-red">
                    <div class="stat-label"><i class="bi bi-x-circle"></i> Inactive</div>
                    <div class="stat-value"><?= (int)$stats['inactive'] ?></div>
                </div>
            </section>

            <section class="rw-filter-card">
                <form method="GET" class="rw-filter-grid">
                    <?php if ($is_superadmin): ?>
                        <input type="hidden" name="client_id" value="<?= (int)$selected_client_id ?>">
                    <?php endif; ?>
                    <div class="rw-quick"><i class="bi bi-lightning-charge-fill"></i> Quick Actions</div>
                    <input type="text" name="q" value="<?= esc($search) ?>" class="rw-input" placeholder="Search waiter name, phone, email, branch...">
                    <select name="status" class="rw-select">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <button class="rw-btn rw-btn-purple" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                </form>
            </section>

            <section class="rw-table-card">
                <div class="rw-table-wrap">
                    <table class="rw-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Waiter</th>
                                <th>Linked User</th>
                                <th>Branch</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Running Tables</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th style="width:130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($waiters)): ?>
                            <tr>
                                <td colspan="10" class="rw-empty">No waiter found. Add your first restaurant waiter.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($waiters as $waiter): ?>
                            <?php
                                $initial = strtoupper(substr(trim((string)$waiter['name']), 0, 1));
                                if ($initial === '') { $initial = 'W'; }
                                $linkedName = trim((string)($waiter['user_name'] ?? ''));
                                $branchName = trim((string)($waiter['branch_name'] ?? ''));
                            ?>
                            <tr>
                                <td>#<?= (int)$waiter['id'] ?></td>
                                <td>
                                    <div class="rw-name-cell">
                                        <div class="rw-avatar"><?= esc($initial) ?></div>
                                        <div class="rw-name-text">
                                            <strong><?= esc($waiter['name']) ?></strong>
                                            <span><?= $waiter['user_id'] ? 'User ID: ' . (int)$waiter['user_id'] : 'Standalone waiter' ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($linkedName !== ''): ?>
                                        <span class="rw-badge linked"><i class="bi bi-link-45deg"></i> <?= esc($linkedName) ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary fw-bold">Not linked</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($branchName !== ''): ?>
                                        <span class="rw-badge branch"><i class="bi bi-building"></i> <?= esc($branchName) ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary fw-bold">All / Default</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc($waiter['phone'] ?: '-') ?></td>
                                <td><?= esc($waiter['email'] ?: '-') ?></td>
                                <td><strong><?= (int)$waiter['running_tables'] ?></strong></td>
                                <td>
                                    <span class="rw-badge <?= esc($waiter['status']) ?>">
                                        <i class="bi <?= $waiter['status'] === 'active' ? 'bi-check-circle' : 'bi-x-circle' ?>"></i>
                                        <?= esc(ucfirst($waiter['status'])) ?>
                                    </span>
                                </td>
                                <td><?= esc(date('d M Y', strtotime((string)$waiter['created_at']))) ?></td>
                                <td>
                                    <button type="button"
                                            class="rw-icon-btn rw-edit"
                                            title="Edit"
                                            onclick='editWaiter(<?= json_encode([
                                                'id' => (int)$waiter['id'],
                                                'user_id' => (int)($waiter['user_id'] ?? 0),
                                                'branch_id' => (int)($waiter['branch_id'] ?? 0),
                                                'name' => (string)$waiter['name'],
                                                'phone' => (string)($waiter['phone'] ?? ''),
                                                'email' => (string)($waiter['email'] ?? ''),
                                                'status' => (string)$waiter['status'],
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        <i class="bi bi-pencil-square"></i>
                                    </button>

                                    <form method="POST" class="d-inline" onsubmit="return confirm('Change this waiter status?');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="waiter_id" value="<?= (int)$waiter['id'] ?>">
                                        <button type="submit" class="rw-icon-btn rw-toggle" title="Toggle Status">
                                            <i class="bi bi-toggle2-on"></i>
                                        </button>
                                    </form>

                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this waiter? If waiter has order history, it will be marked inactive.');">
                                        <input type="hidden" name="action" value="delete_waiter">
                                        <input type="hidden" name="waiter_id" value="<?= (int)$waiter['id'] ?>">
                                        <button type="submit" class="rw-icon-btn rw-delete" title="Delete / Inactive">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- WAITER MODAL -->
<div class="modal fade rw-modal" id="waiterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="waiterForm">
                <input type="hidden" name="action" value="save_waiter">
                <input type="hidden" name="waiter_id" id="waiter_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="waiterModalTitle">Add Waiter</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Link Existing Staff/User</label>
                            <select name="user_id" id="user_id" class="form-select">
                                <option value="0">Standalone Waiter / No Login</option>
                                <?php foreach ($staff_users as $staff): ?>
                                    <option value="<?= (int)$staff['id'] ?>">
                                        <?= esc(($staff['name'] ?: 'User #' . $staff['id']) . ' — ' . ($staff['email'] ?: 'no email')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Branch</label>
                            <select name="branch_id" id="branch_id" class="form-select">
                                <option value="0">All / Default Branch</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= (int)$branch['id'] ?>" <?= ((int)$branch['id'] === $default_branch_id) ? 'selected' : '' ?>>
                                        <?= esc($branch['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Waiter Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="waiter_name" class="form-control" placeholder="e.g. Ali Khan" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="waiter_phone" class="form-control" placeholder="e.g. 03xxxxxxxxx">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="waiter_email" class="form-control" placeholder="waiter@example.com">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="waiter_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="rw-btn rw-btn-dark" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="rw-btn rw-btn-primary"><i class="bi bi-check2-circle"></i> Save Waiter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const staffUsers = <?= json_encode($userJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let waiterModal;

document.addEventListener('DOMContentLoaded', function(){
    waiterModal = new bootstrap.Modal(document.getElementById('waiterModal'));

    const userSelect = document.getElementById('user_id');
    if (userSelect) {
        userSelect.addEventListener('change', function(){
            const id = parseInt(this.value || '0', 10);
            if (!id || !staffUsers[id]) return;

            const user = staffUsers[id];
            document.getElementById('waiter_name').value = user.name || '';
            document.getElementById('waiter_phone').value = user.phone || '';
            document.getElementById('waiter_email').value = user.email || '';
        });
    }
});

function openWaiterModal(){
    document.getElementById('waiterForm').reset();
    document.getElementById('waiter_id').value = '';
    document.getElementById('waiterModalTitle').innerText = 'Add Waiter';
    document.getElementById('branch_id').value = '<?= (int)$default_branch_id ?>';
    document.getElementById('waiter_status').value = 'active';
    waiterModal.show();
}

function editWaiter(data){
    document.getElementById('waiterForm').reset();
    document.getElementById('waiterModalTitle').innerText = 'Edit Waiter';
    document.getElementById('waiter_id').value = data.id || '';
    document.getElementById('user_id').value = data.user_id || '0';
    document.getElementById('branch_id').value = data.branch_id || '0';
    document.getElementById('waiter_name').value = data.name || '';
    document.getElementById('waiter_phone').value = data.phone || '';
    document.getElementById('waiter_email').value = data.email || '';
    document.getElementById('waiter_status').value = data.status || 'active';
    waiterModal.show();
}
</script>
</body>
</html>
