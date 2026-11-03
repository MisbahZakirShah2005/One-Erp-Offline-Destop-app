<?php
session_start();

/*
 | Dynamic page fix:
 | restaurant_tables.php must always show fresh DB rows.
 | Do NOT include partials/performance.php here, because page/output caching
 | can keep old table cards visible even after new rows are inserted.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================= DB CONNECTION FIX ================= */
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check config/db.php and make sure it creates mysqli variable named \$conn.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ================= AUTH CHECK + STRICT USER SESSION SYNC ================= */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$auth_user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $stmt = $conn->prepare("SELECT id, client_id, role_id, name, email, status FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $auth_user_id);
    $stmt->execute();
    $authUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $authError) {
    $_SESSION['error'] = 'Authentication check failed: ' . $authError->getMessage();
    header("Location: /public/app/views/login_view.php");
    exit;
}

if (!$authUser || strtolower((string)($authUser['status'] ?? '')) !== 'active') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header("Location: /public/app/views/login_view.php");
    exit;
}

$old_session_client_id = (int)($_SESSION['client_id'] ?? 0);
$new_session_client_id = (int)($authUser['client_id'] ?? 0);

$_SESSION['user_id']    = (int)$authUser['id'];
$_SESSION['client_id']  = $new_session_client_id;
$_SESSION['role_id']    = (int)($authUser['role_id'] ?? 0);
$_SESSION['name']       = (string)($authUser['name'] ?? '');
$_SESSION['user_name']  = (string)($authUser['name'] ?? '');
$_SESSION['email']      = (string)($authUser['email'] ?? '');
$_SESSION['user_email'] = (string)($authUser['email'] ?? '');

if ($old_session_client_id !== $new_session_client_id) {
    unset($_SESSION['branch_id'], $_SESSION['selected_branch_id']);
}

$user_id   = (int)$_SESSION['user_id'];
$client_id = (int)$_SESSION['client_id'];
$role_id   = (int)$_SESSION['role_id'];
$branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['selected_branch_id'] ?? 0));

if ($client_id <= 0 && $role_id !== 1) {
    $_SESSION = [];
    session_destroy();
    header("Location: /public/app/views/login_view.php");
    exit;
}

/* ================= HELPERS ================= */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_self(): void
{
    header('Location: restaurant_tables.php?_=' . time());
    exit;
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function enum_values(mysqli $conn, string $table, string $column): array
{
    try {
        $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $type = (string)($row['COLUMN_TYPE'] ?? '');
        if (stripos($type, 'enum(') !== 0) return [];
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $m);
        return array_map(static fn($v) => str_replace("\\'", "'", $v), $m[1] ?? []);
    } catch (Throwable $e) {
        return [];
    }
}

function storage_status_for_ui(string $uiStatus): string
{
    $s = strtolower(trim(str_replace(['_', '-'], ' ', $uiStatus)));
    $s = preg_replace('/\s+/', ' ', $s);

    return match ($s) {
        'available', 'free', 'complete', 'completed' => 'Available',
        'occupied', 'active', 'running', 'open', 'bill printed', 'billprinted' => 'Occupied',
        'reserved', 'reserve' => 'Reserved',
        'cleaning', 'dirty', 'send to cleaning' => 'Cleaning',
        'out of service', 'out of order', 'inactive' => 'Out of Service',
        default => 'Available',
    };
}

function display_status_from_row(array $row): string
{
    $isActive = (int)($row['is_active'] ?? 1) === 1;
    if (!$isActive) return 'Out of Service';

    $raw = strtolower(trim(str_replace(['_', '-'], ' ', (string)($row['status'] ?? 'Available'))));
    $raw = preg_replace('/\s+/', ' ', $raw);

    $sessionStatus = strtolower(trim((string)($row['active_session_status'] ?? '')));
    $hasLiveSession = !empty($row['active_session_id']) && in_array($sessionStatus, ['open', 'kot_sent', 'bill_printed'], true);

    if ($hasLiveSession) return 'Occupied';

    return match ($raw) {
        'available' => 'Available',
        'occupied', 'bill printed' => 'Occupied',
        'reserved' => 'Reserved',
        'dirty', 'cleaning' => 'Cleaning',
        'out of service', 'out of order' => 'Out of Service',
        default => 'Available',
    };
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'Available' => 'status-available',
        'Occupied' => 'status-occupied',
        'Reserved' => 'status-reserved',
        'Cleaning' => 'status-cleaning',
        'Out of Service' => 'status-out',
        default => 'status-muted',
    };
}

function status_card_class(string $status): string
{
    return match ($status) {
        'Available' => 'table-available',
        'Occupied' => 'table-occupied',
        'Reserved' => 'table-reserved',
        'Cleaning' => 'table-cleaning',
        'Out of Service' => 'table-out',
        default => 'table-available',
    };
}

function format_table_no($tableNo): string
{
    $no = trim((string)$tableNo);
    if ($no === '') return 'T-';
    if (preg_match('/^t/i', $no)) return strtoupper($no);
    return 'T' . ltrim($no, '0');
}

function minutes_between(?string $from): int
{
    if (!$from || trim($from) === '') return 0;
    $ts = strtotime($from);
    if (!$ts || $ts > time()) return 0;
    return max(0, (int)floor((time() - $ts) / 60));
}

function human_duration(int $minutes): string
{
    if ($minutes <= 0) return '0 minutes';
    if ($minutes < 60) return $minutes . ' minutes';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h . ' hr' . ($h > 1 ? 's' : '') . ($m > 0 ? ' ' . $m . ' min' : '');
}

function close_live_table_session(mysqli $conn, int $clientId, int $tableId, int $sessionId, int $userId): void
{
    if ($sessionId <= 0 || !table_exists($conn, 'restaurant_table_sessions')) return;

    $sets = [];
    $types = '';
    $params = [];

    if (column_exists($conn, 'restaurant_table_sessions', 'status')) {
        $enum = enum_values($conn, 'restaurant_table_sessions', 'status');
        $statusValue = in_array('paid', $enum, true) ? 'paid' : (in_array('closed', $enum, true) ? 'closed' : null);
        if ($statusValue !== null) {
            $sets[] = 'status = ?';
            $types .= 's';
            $params[] = $statusValue;
        }
    }

    if (column_exists($conn, 'restaurant_table_sessions', 'paid_amount') && column_exists($conn, 'restaurant_table_sessions', 'grand_total')) {
        $sets[] = 'paid_amount = grand_total';
    }
    if (column_exists($conn, 'restaurant_table_sessions', 'balance_amount')) {
        $sets[] = 'balance_amount = 0';
    }
    if (column_exists($conn, 'restaurant_table_sessions', 'closed_by')) {
        $sets[] = 'closed_by = ?';
        $types .= 'i';
        $params[] = $userId;
    }
    if (column_exists($conn, 'restaurant_table_sessions', 'closed_at')) {
        $sets[] = 'closed_at = NOW()';
    }
    if (column_exists($conn, 'restaurant_table_sessions', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }

    if (empty($sets)) return;

    $sql = "UPDATE restaurant_table_sessions SET " . implode(', ', $sets) . " WHERE client_id = ? AND table_id = ? AND id = ? LIMIT 1";
    $types .= 'iii';
    $params[] = $clientId;
    $params[] = $tableId;
    $params[] = $sessionId;

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Do not block table status changes if a tenant has a slightly different session schema.
    }
}

/* ================= SCHEMA SAFETY CHECK ================= */
if (!table_exists($conn, 'restaurant_tables')) {
    die('restaurant_tables table not found in database.');
}

$requiredColumns = ['id','client_id','branch_id','table_no','capacity','status','current_session_id','is_active','created_at','updated_at'];
$missingColumns = [];
foreach ($requiredColumns as $col) {
    if (!column_exists($conn, 'restaurant_tables', $col)) {
        $missingColumns[] = $col;
    }
}
if (!empty($missingColumns)) {
    die('Missing restaurant_tables columns: ' . e(implode(', ', $missingColumns)));
}

/* ================= BRANCH RESOLUTION ================= */
$branches = [];
try {
    if (table_exists($conn, 'branches')) {
        $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $branches[(int)$row['id']] = (string)($row['name'] ?? ('Branch ' . (int)$row['id']));
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    $branches = [];
}

if (!empty($branches)) {
    if ($branch_id <= 0 || !array_key_exists($branch_id, $branches)) {
        $branch_id = (int)array_key_first($branches);
        $_SESSION['branch_id'] = $branch_id;
        $_SESSION['selected_branch_id'] = $branch_id;
    }
} else {
    $branch_id = 0;
    $_SESSION['branch_id'] = 0;
    $_SESSION['selected_branch_id'] = 0;
}

$hasSessionsTable = table_exists($conn, 'restaurant_table_sessions');

/* ================= POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_table') {
            $table_no = trim((string)($_POST['table_no'] ?? ''));
            $capacity = max(1, (int)($_POST['capacity'] ?? 4));

            if ($table_no === '') {
                throw new Exception('Table number is required.');
            }

            $stmt = $conn->prepare("SELECT id FROM restaurant_tables WHERE client_id = ? AND branch_id = ? AND table_no = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('iis', $client_id, $branch_id, $table_no);
            $stmt->execute();
            $duplicate = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($duplicate) {
                throw new Exception('This table number already exists.');
            }

            $stmt = $conn->prepare("INSERT INTO restaurant_tables (client_id, branch_id, table_no, table_name, floor_name, capacity, current_session_id, sort_order, is_active, status, waiter_id, created_at) VALUES (?, ?, ?, NULL, NULL, ?, NULL, 0, 1, 'Available', NULL, NOW())");
            $stmt->bind_param('iisi', $client_id, $branch_id, $table_no, $capacity);
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Table added successfully.';
            redirect_self();
        }

        if ($action === 'change_status') {
            $id = (int)($_POST['id'] ?? 0);
            $uiStatus = trim((string)($_POST['status'] ?? 'Available'));

            if ($id <= 0) {
                throw new Exception('Invalid table id.');
            }

            $stmt = $conn->prepare("SELECT id, current_session_id, is_active, status FROM restaurant_tables WHERE id = ? AND client_id = ? LIMIT 1");
            $stmt->bind_param('ii', $id, $client_id);
            $stmt->execute();
            $tableRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$tableRow) {
                throw new Exception('Table not found.');
            }

            $currentSessionId = (int)($tableRow['current_session_id'] ?? 0);

            $conn->begin_transaction();

            $storeStatus = storage_status_for_ui($uiStatus);
            $clearSessionStatuses = ['Available', 'Reserved', 'Cleaning', 'Out of Service'];

            if (in_array($storeStatus, $clearSessionStatuses, true)) {
                close_live_table_session($conn, $client_id, $id, $currentSessionId, $user_id);
                $activeValue = ($storeStatus === 'Out of Service') ? 0 : 1;
                $stmt = $conn->prepare("UPDATE restaurant_tables SET status = ?, is_active = ?, current_session_id = NULL, updated_at = NOW() WHERE id = ? AND client_id = ?");
                $stmt->bind_param('siii', $storeStatus, $activeValue, $id, $client_id);
            } else {
                $stmt = $conn->prepare("UPDATE restaurant_tables SET status = ?, is_active = 1, updated_at = NOW() WHERE id = ? AND client_id = ?");
                $stmt->bind_param('sii', $storeStatus, $id, $client_id);
            }
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Table status updated successfully.';

            $conn->commit();
            redirect_self();
        }
    } catch (Throwable $postError) {
        try { $conn->rollback(); } catch (Throwable $ignore) { }
        $_SESSION['error'] = $postError->getMessage();
        redirect_self();
    }
}

/* ================= FETCH TABLES + STATS ================= */
$statusFilter = trim((string)($_GET['status'] ?? ''));
$validUiStatuses = ['Available', 'Occupied', 'Reserved', 'Cleaning', 'Out of Service'];
if ($statusFilter !== '' && !in_array($statusFilter, $validUiStatuses, true)) {
    $statusFilter = '';
}

$tablesAll = [];
$tables = [];
$stats = [
    'Available' => 0,
    'Occupied' => 0,
    'Reserved' => 0,
    'Cleaning' => 0,
    'Out of Service' => 0,
    'Total' => 0,
];

try {
    if ($hasSessionsTable) {
        $sql = "
            SELECT
                rt.*,
                rts.id AS active_session_id,
                rts.status AS active_session_status,
                rts.guest_count AS active_guest_count,
                rts.started_at AS active_started_at,
                rts.grand_total AS active_grand_total
            FROM restaurant_tables rt
            LEFT JOIN restaurant_table_sessions rts
                ON rts.id = rt.current_session_id
                AND rts.client_id = rt.client_id
            WHERE rt.client_id = ?
            ORDER BY
                CASE WHEN rt.sort_order > 0 THEN rt.sort_order ELSE 999999 END ASC,
                CAST(REPLACE(UPPER(rt.table_no), 'T', '') AS UNSIGNED) ASC,
                rt.table_no ASC,
                rt.id ASC
        ";
    } else {
        $sql = "
            SELECT
                rt.*,
                NULL AS active_session_id,
                NULL AS active_session_status,
                NULL AS active_guest_count,
                NULL AS active_started_at,
                NULL AS active_grand_total
            FROM restaurant_tables rt
            WHERE rt.client_id = ?
            ORDER BY
                CASE WHEN rt.sort_order > 0 THEN rt.sort_order ELSE 999999 END ASC,
                CAST(REPLACE(UPPER(rt.table_no), 'T', '') AS UNSIGNED) ASC,
                rt.table_no ASC,
                rt.id ASC
        ";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['display_status'] = display_status_from_row($row);
        $tablesAll[] = $row;

        if (isset($stats[$row['display_status']])) {
            $stats[$row['display_status']]++;
        }
        $stats['Total']++;
    }
    $stmt->close();

    foreach ($tablesAll as $row) {
        if ($statusFilter !== '' && $row['display_status'] !== $statusFilter) {
            continue;
        }
        $tables[] = $row;
    }
} catch (Throwable $fetchError) {
    $_SESSION['error'] = 'Tables fetch error: ' . $fetchError->getMessage();
}


/* ================= OPTIONAL DEBUG: /restaurant_tables.php?debug_tables=1 ================= */
if (isset($_GET['debug_tables'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Logged client_id: " . $client_id . PHP_EOL;
    echo "Logged branch_id: " . $branch_id . PHP_EOL;
    echo "UI status filter: " . ($statusFilter !== '' ? $statusFilter : 'All Tables') . PHP_EOL;
    echo "Fetched rows: " . count($tablesAll) . PHP_EOL . PHP_EOL;
    foreach ($tablesAll as $r) {
        echo 'id=' . (int)$r['id']
            . ' client_id=' . (int)$r['client_id']
            . ' branch_id=' . (int)$r['branch_id']
            . ' table_no=' . (string)$r['table_no']
            . ' capacity=' . (int)$r['capacity']
            . ' is_active=' . (int)$r['is_active']
            . ' db_status=' . (string)$r['status']
            . ' ui_status=' . (string)$r['display_status']
            . PHP_EOL;
    }
    exit;
}

$nextTableNo = 1;
foreach ($tablesAll as $r) {
    $digits = preg_replace('/\D+/', '', (string)($r['table_no'] ?? ''));
    if ($digits !== '') {
        $nextTableNo = max($nextTableNo, ((int)$digits) + 1);
    }
}

function stat_dot_class(string $status): string
{
    return match ($status) {
        'Available' => 'dot-available',
        'Occupied' => 'dot-occupied',
        'Reserved' => 'dot-reserved',
        'Cleaning' => 'dot-cleaning',
        'Out of Service' => 'dot-out',
        default => 'dot-total',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Table Management - One ERP</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
html,
body.restaurant-tables-page{
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
.restaurant-tables-page{
    --sidebar-width:270px;
    --topbar-height:70px;
    --purple:#7c3aed;
    --purple2:#6d4cff;
    --muted:#94a3b8;
}
.container-scroll{display:flex !important;min-height:100vh !important;width:100% !important;}
.container-scroll > .sidebar,#dashboardSidebar{position:fixed !important;top:0 !important;left:0 !important;width:var(--sidebar-width) !important;height:100vh !important;z-index:1040 !important;}
.main-panel{margin-left:var(--sidebar-width) !important;width:calc(100% - var(--sidebar-width)) !important;min-height:100vh !important;padding-top:var(--topbar-height) !important;background:transparent !important;transition:.25s ease !important;}
.navbar.fixed-top,.my-navbar.fixed-top{left:var(--sidebar-width) !important;width:calc(100% - var(--sidebar-width)) !important;z-index:1030 !important;}
body.sidebar-collapsed .main-panel{margin-left:0 !important;width:100% !important;}
body.sidebar-collapsed .navbar.fixed-top,body.sidebar-collapsed .my-navbar.fixed-top{left:0 !important;width:100% !important;}
.content-wrapper{position:relative !important;min-height:calc(100vh - var(--topbar-height)) !important;padding:26px 18px 70px !important;background:radial-gradient(circle at 12% 8%, rgba(124,58,237,.26), transparent 28%),radial-gradient(circle at 78% 2%, rgba(59,130,246,.13), transparent 30%),radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),linear-gradient(135deg,#050914 0%,#0b1024 40%,#160f2d 70%,#090d18 100%) !important;overflow:visible !important;}
.content-wrapper::before{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);background-size:44px 44px;opacity:.18;z-index:0;}
.content-wrapper > *{position:relative;z-index:1;}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;flex-wrap:wrap;margin-bottom:18px;}
.page-title{font-size:22px;font-weight:950;letter-spacing:-.04em;color:#fff;text-shadow:0 0 28px rgba(124,58,237,.45);margin:0 0 4px;}
.page-subtitle{color:#aab4cf;font-weight:750;font-size:13px;}
.header-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.table-filter{min-height:42px;border-radius:13px;background:rgba(15,23,42,.82);color:#e5e7eb;border:1px solid rgba(148,163,184,.20);font-weight:850;padding:0 38px 0 13px;min-width:165px;outline:none;}
.table-filter option{background:#0f172a;color:#e5e7eb;}
.action-btn{min-height:42px;border:none;border-radius:13px;padding:0 18px;font-weight:950;color:#fff;display:inline-flex;gap:8px;align-items:center;justify-content:center;text-decoration:none;transition:.22s ease;white-space:nowrap;}
.action-btn:hover{transform:translateY(-2px);color:#fff;}
.btn-primary-dark{background:linear-gradient(135deg,#08071a,#17102d);box-shadow:0 14px 30px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.08);border:1px solid rgba(139,92,246,.25);}
.alert{border-radius:14px;border:none;font-weight:850;margin-bottom:14px;}
.alert-success{background:rgba(34,197,94,.14);color:#bbf7d0;}
.alert-danger{background:rgba(239,68,68,.14);color:#fecaca;}
.tables-grid{display:grid;grid-template-columns:repeat(6,minmax(145px,1fr));gap:14px;margin-top:2px;}
.table-box{min-height:116px;border-radius:16px;padding:15px 12px;text-align:center;cursor:pointer;transition:.22s ease;position:relative;overflow:hidden;box-shadow:0 18px 42px rgba(0,0,0,.22), inset 0 1px 0 rgba(255,255,255,.06);}
.table-box::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.08),transparent 45%);pointer-events:none;}
.table-box:hover{transform:translateY(-4px);box-shadow:0 24px 55px rgba(0,0,0,.34),0 0 0 1px rgba(124,58,237,.28);}
.table-available{background:linear-gradient(135deg,rgba(22,163,74,.22),rgba(21,128,61,.08));border:1px solid rgba(34,197,94,.84);}
.table-occupied{background:linear-gradient(135deg,rgba(239,68,68,.20),rgba(127,29,29,.10));border:1px solid rgba(239,68,68,.88);}
.table-reserved{background:linear-gradient(135deg,rgba(245,158,11,.20),rgba(146,64,14,.10));border:1px solid rgba(245,158,11,.88);}
.table-cleaning{background:linear-gradient(135deg,rgba(59,130,246,.20),rgba(30,64,175,.10));border:1px solid rgba(59,130,246,.88);}
.table-out{background:linear-gradient(135deg,rgba(100,116,139,.22),rgba(30,41,59,.14));border:1px solid rgba(100,116,139,.74);}
.table-no{font-size:21px;font-weight:950;color:#fff;line-height:1;margin-top:3px;position:relative;}
.status-pill{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:950;border:1px solid rgba(255,255,255,.14);line-height:1;text-transform:lowercase;position:relative;}
.status-available{background:rgba(34,197,94,.14);color:#bbf7d0;border-color:rgba(34,197,94,.30);}
.status-occupied{background:rgba(239,68,68,.18);color:#fecaca;border-color:rgba(239,68,68,.34);}
.status-reserved{background:rgba(245,158,11,.18);color:#fde68a;border-color:rgba(245,158,11,.34);}
.status-cleaning{background:rgba(59,130,246,.18);color:#bfdbfe;border-color:rgba(59,130,246,.34);}
.status-out{background:rgba(100,116,139,.18);color:#cbd5e1;border-color:rgba(100,116,139,.34);}
.status-muted{background:rgba(148,163,184,.12);color:#cbd5e1;}
.table-info-line{font-size:12px;font-weight:800;color:#cbd5e1;margin-top:7px;display:flex;align-items:center;justify-content:center;gap:5px;position:relative;}
.table-duration{font-size:11px;font-weight:800;color:#aab4cf;margin-top:2px;position:relative;}
.empty-state{grid-column:1/-1;border:1px dashed rgba(148,163,184,.28);border-radius:18px;padding:38px;text-align:center;color:#cbd5e1;background:rgba(15,23,42,.44);font-weight:850;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-top:18px;}
.stats-grid-bottom{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin-top:16px;}
.stat-mini{min-height:80px;border-radius:16px;background:linear-gradient(135deg,rgba(15,23,42,.72),rgba(30,20,58,.42));border:1px solid rgba(148,163,184,.16);box-shadow:0 14px 34px rgba(0,0,0,.20),inset 0 1px 0 rgba(255,255,255,.05);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:#e5e7eb;}
.stat-dot{width:10px;height:10px;border-radius:999px;box-shadow:0 0 18px currentColor;}
.dot-available{background:#22c55e;color:#22c55e;}.dot-occupied{background:#ef4444;color:#ef4444;}.dot-reserved{background:#eab308;color:#eab308;}.dot-cleaning{background:#3b82f6;color:#3b82f6;}.dot-out{background:#64748b;color:#64748b;}.dot-total{background:#a855f7;color:#a855f7;}
.stat-title{font-size:12px;color:#cbd5e1;font-weight:800;}.stat-count{font-size:18px;color:#fff;font-weight:950;line-height:1;}
.modal{z-index:5000 !important;}.modal-backdrop{z-index:4990 !important;}.modal-backdrop.show{opacity:.76 !important;background:#000 !important;backdrop-filter:blur(8px);}
.modal-dialog{max-width:430px;}.modal-content{background:radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),linear-gradient(135deg, rgba(15,23,42,.98), rgba(17,24,39,.92)) !important;color:#f8fafc !important;border:1px solid rgba(139,92,246,.36) !important;border-radius:18px !important;box-shadow:0 28px 80px rgba(0,0,0,.62),0 0 52px rgba(124,58,237,.18) !important;overflow:hidden;}
.modal-header{border:none;padding:18px 20px 9px;background:rgba(7,11,20,.20);}.modal-title{font-size:16px;font-weight:950;color:#fff;}.btn-close{filter:invert(1) grayscale(1);opacity:1;box-shadow:none !important;}.modal-body{padding:10px 20px 18px;}.form-label{color:#dbe4ff;font-size:12px;font-weight:900;margin-bottom:5px;}.form-control{min-height:38px;border-radius:10px;background:rgba(15,23,42,.80);color:#fff;border:1px solid rgba(148,163,184,.20);font-weight:850;}.form-control:focus{background:rgba(17,24,39,.96);color:#fff;border-color:rgba(124,58,237,.62);box-shadow:0 0 0 3px rgba(124,58,237,.16);}.modal-actions-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;}.modal-actions-row.single{grid-template-columns:1fr;}.btn-modal{width:100%;min-height:39px;border-radius:10px;border:1px solid rgba(148,163,184,.20);font-weight:950;font-size:12px;transition:.2s ease;}.btn-modal:hover{transform:translateY(-1px);}.btn-cancel,.btn-cleaning,.btn-reserve{background:rgba(15,23,42,.76);color:#fff;}.btn-dark-submit,.btn-free,.btn-occupied,.btn-available{background:linear-gradient(135deg,#08071a,#17102d);color:#fff;border-color:rgba(139,92,246,.28);}.btn-danger-soft{background:rgba(100,116,139,.18);color:#e2e8f0;}.btn-start-order{background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;border-color:rgba(34,197,94,.38);text-decoration:none!important;display:flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;box-shadow:0 12px 26px rgba(34,197,94,.18);} .btn-start-order:hover{color:#fff!important;}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 22px;}.detail-label{font-size:12px;font-weight:850;color:#aab4cf;margin-bottom:4px;}.detail-value{font-size:14px;font-weight:950;color:#fff;}.modal-divider{height:1px;background:rgba(148,163,184,.18);margin:16px 0 13px;}.change-title{font-size:12px;font-weight:850;color:#cbd5e1;margin-bottom:8px;}
@media(max-width:1200px){.tables-grid{grid-template-columns:repeat(4,minmax(145px,1fr));}}
@media(max-width:991px){.main-panel{margin-left:0 !important;width:100% !important;}.navbar.fixed-top,.my-navbar.fixed-top{left:0 !important;width:100% !important;}.content-wrapper{padding:18px 12px 60px !important;}.tables-grid{grid-template-columns:repeat(3,minmax(130px,1fr));}.stats-grid{grid-template-columns:repeat(2,1fr);}.stats-grid-bottom{grid-template-columns:1fr;}}
@media(max-width:576px){.page-header{display:block;}.header-actions{margin-top:12px;display:grid;grid-template-columns:1fr;}.table-filter,.action-btn{width:100%;}.tables-grid{grid-template-columns:repeat(2,minmax(120px,1fr));gap:10px;}.table-box{min-height:105px;}.stats-grid{grid-template-columns:1fr;}.detail-grid{grid-template-columns:1fr;}.modal-actions-row{grid-template-columns:1fr;}}
</style>
</head>
<body class="restaurant-tables-page">
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?>

        <div class="content-wrapper">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Table Management</h1>
                    <div class="page-subtitle">Manage your restaurant tables and seating</div>
                </div>
                <div class="header-actions">
                    <form method="get" id="statusFilterForm" class="m-0">
                        <select name="status" class="table-filter" onchange="document.getElementById('statusFilterForm').submit();">
                            <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All Tables</option>
                            <?php foreach ($validUiStatuses as $st): ?>
                                <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <button type="button" class="action-btn btn-primary-dark" data-bs-toggle="modal" data-bs-target="#addTableModal">
                        <i class="bi bi-plus-lg"></i> Add Table
                    </button>
                </div>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= e($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= e($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="tables-grid">
                <?php if (empty($tables)): ?>
                    <div class="empty-state">No table found. Click Add Table to create your first table.</div>
                <?php endif; ?>

                <?php foreach ($tables as $row): ?>
                    <?php
                        $status = (string)$row['display_status'];
                        $tableLabel = format_table_no($row['table_no'] ?? '');
                        $capacity = max(1, (int)($row['capacity'] ?? 1));
                        $guestCount = (int)($row['active_guest_count'] ?? 0);
                        $shownGuests = $status === 'Occupied' ? max($guestCount, $capacity) : $capacity;
                        $durationMinutes = $status === 'Occupied' ? minutes_between((string)($row['active_started_at'] ?? ($row['updated_at'] ?? $row['created_at'] ?? ''))) : 0;
                    ?>
                    <div class="table-box <?= e(status_card_class($status)) ?> openDetailsBtn"
                         data-id="<?= (int)$row['id'] ?>"
                         data-table="<?= e($tableLabel) ?>"
                         data-capacity="<?= (int)$capacity ?>"
                         data-guests="<?= (int)$shownGuests ?>"
                         data-status="<?= e($status) ?>"
                         data-duration="<?= e(human_duration($durationMinutes)) ?>">
                        <div class="table-no"><?= e($tableLabel) ?></div>
                        <div class="mt-2"><span class="status-pill <?= e(status_badge_class($status)) ?>"><?= e(strtolower($status)) ?></span></div>
                        <div class="table-info-line"><i class="bi bi-people"></i> <?= (int)$shownGuests ?></div>
                        <?php if ($status === 'Occupied'): ?>
                            <div class="table-duration"><i class="bi bi-clock"></i> <?= e(human_duration($durationMinutes)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="stats-grid">
                <div class="stat-mini"><span class="stat-dot dot-available"></span><div class="stat-title">Available</div><div class="stat-count"><?= (int)$stats['Available'] ?></div></div>
                <div class="stat-mini"><span class="stat-dot dot-occupied"></span><div class="stat-title">Occupied</div><div class="stat-count"><?= (int)$stats['Occupied'] ?></div></div>
                <div class="stat-mini"><span class="stat-dot dot-reserved"></span><div class="stat-title">Reserved</div><div class="stat-count"><?= (int)$stats['Reserved'] ?></div></div>
                <div class="stat-mini"><span class="stat-dot dot-cleaning"></span><div class="stat-title">Cleaning</div><div class="stat-count"><?= (int)$stats['Cleaning'] ?></div></div>
            </div>

            <div class="stats-grid-bottom">
                <div class="stat-mini"><span class="stat-dot dot-out"></span><div class="stat-title">Out of Service</div><div class="stat-count"><?= (int)$stats['Out of Service'] ?></div></div>
                <div class="stat-mini"><span class="stat-dot dot-total"></span><div class="stat-title">Total Tables</div><div class="stat-count"><?= (int)$stats['Total'] ?></div></div>
            </div>
        </div>
    </div>
</div>

<!-- ADD NEW TABLE MODAL -->
<div class="modal fade" id="addTableModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="save_table">
            <div class="modal-header">
                <h5 class="modal-title">Add New Table</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">Table Number</label>
                    <input type="number" name="table_no" class="form-control" min="1" value="<?= (int)$nextTableNo ?>" required autofocus>
                </div>
                <div class="mb-1">
                    <label class="form-label">Seating Capacity</label>
                    <input type="number" name="capacity" class="form-control" min="1" value="4" required>
                </div>
                <div class="modal-actions-row">
                    <button type="button" class="btn-modal btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal btn-dark-submit">Add Table</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- TABLE DETAILS MODAL -->
<div class="modal fade" id="tableDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><span id="detailTableTitle">Table</span> Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="detail-grid">
                    <div>
                        <div class="detail-label">Table Number</div>
                        <div class="detail-value" id="detailTableNo">-</div>
                    </div>
                    <div>
                        <div class="detail-label">Capacity</div>
                        <div class="detail-value"><span id="detailCapacity">0</span> guests</div>
                    </div>
                    <div>
                        <div class="detail-label">Status</div>
                        <span class="status-pill" id="detailStatus">available</span>
                    </div>
                    <div id="detailDurationWrap">
                        <div class="detail-label">Duration</div>
                        <div class="detail-value" id="detailDuration">0 minutes</div>
                    </div>
                </div>
                <div class="modal-divider"></div>
                <div class="change-title">Change Status:</div>
                <div id="detailsActions"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    const detailsModalEl = document.getElementById('tableDetailsModal');
    const detailsModal = new bootstrap.Modal(detailsModalEl);
    const detailTitle = document.getElementById('detailTableTitle');
    const detailNo = document.getElementById('detailTableNo');
    const detailCapacity = document.getElementById('detailCapacity');
    const detailStatus = document.getElementById('detailStatus');
    const detailDuration = document.getElementById('detailDuration');
    const detailDurationWrap = document.getElementById('detailDurationWrap');
    const actionsWrap = document.getElementById('detailsActions');

    function escAttr(value){
        return String(value || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function pillClass(status){
        status = String(status || '').toLowerCase();
        if(status === 'available') return 'status-pill status-available';
        if(status === 'occupied') return 'status-pill status-occupied';
        if(status === 'reserved') return 'status-pill status-reserved';
        if(status === 'cleaning') return 'status-pill status-cleaning';
        if(status === 'out of service') return 'status-pill status-out';
        return 'status-pill status-muted';
    }

    function formBtn(id, status, label, cls){
        return `
            <form method="post" class="m-0">
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="id" value="${escAttr(id)}">
                <input type="hidden" name="status" value="${escAttr(status)}">
                <button type="submit" class="btn-modal ${cls}">${label}</button>
            </form>
        `;
    }

    function startOrderBtn(id){
        return `
            <div class="modal-actions-row single">
                <a href="restaurant_pos.php?table_id=${encodeURIComponent(id)}" class="btn-modal btn-start-order">
                    <i class="bi bi-shop"></i> Start Order
                </a>
            </div>
        `;
    }

    function renderActions(id, status){
        let html = '';
        if(status !== 'Out of Service'){
            html += startOrderBtn(id);
        }
        if(status === 'Occupied'){
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Available', 'Complete & Free Table', 'btn-free') + '</div>';
            html += '<div class="modal-actions-row">' + formBtn(id, 'Cleaning', 'Send to Cleaning', 'btn-cleaning') + formBtn(id, 'Reserved', 'Reserve', 'btn-reserve') + '</div>';
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Out of Service', 'Mark Out of Service', 'btn-danger-soft') + '</div>';
        } else if(status === 'Available'){
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Occupied', 'Mark as Occupied', 'btn-occupied') + '</div>';
            html += '<div class="modal-actions-row">' + formBtn(id, 'Cleaning', 'Send to Cleaning', 'btn-cleaning') + formBtn(id, 'Reserved', 'Reserve', 'btn-reserve') + '</div>';
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Out of Service', 'Mark Out of Service', 'btn-danger-soft') + '</div>';
        } else if(status === 'Reserved'){
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Occupied', 'Mark as Occupied', 'btn-occupied') + '</div>';
            html += '<div class="modal-actions-row">' + formBtn(id, 'Available', 'Complete & Free Table', 'btn-free') + formBtn(id, 'Cleaning', 'Send to Cleaning', 'btn-cleaning') + '</div>';
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Out of Service', 'Mark Out of Service', 'btn-danger-soft') + '</div>';
        } else if(status === 'Cleaning'){
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Available', 'Complete & Free Table', 'btn-available') + '</div>';
            html += '<div class="modal-actions-row">' + formBtn(id, 'Occupied', 'Mark as Occupied', 'btn-occupied') + formBtn(id, 'Reserved', 'Reserve', 'btn-reserve') + '</div>';
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Out of Service', 'Mark Out of Service', 'btn-danger-soft') + '</div>';
        } else {
            html += '<div class="modal-actions-row single">' + formBtn(id, 'Available', 'Complete & Free Table', 'btn-available') + '</div>';
        }
        actionsWrap.innerHTML = html;
    }

    document.querySelectorAll('.openDetailsBtn').forEach(function(card){
        card.addEventListener('click', function(){
            const id = card.dataset.id;
            const table = card.dataset.table || '-';
            const capacity = card.dataset.capacity || '0';
            const status = card.dataset.status || 'Available';
            const duration = card.dataset.duration || '0 minutes';

            detailTitle.textContent = table;
            detailNo.textContent = table;
            detailCapacity.textContent = capacity;
            detailStatus.className = pillClass(status);
            detailStatus.textContent = status.toLowerCase();

            if(status === 'Occupied'){
                detailDurationWrap.style.display = '';
                detailDuration.textContent = duration;
            } else {
                detailDurationWrap.style.display = 'none';
                detailDuration.textContent = '0 minutes';
            }

            renderActions(id, status);
            detailsModal.show();
        });
    });
})();
</script>
</body>
</html>
