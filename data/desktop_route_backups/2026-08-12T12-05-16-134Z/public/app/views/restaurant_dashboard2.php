<?php
session_start();

if (file_exists(__DIR__ . '/partials/performance.php')) {
    include(__DIR__ . '/partials/performance.php');
}

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

/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['selected_branch_id'] ?? 0));
$role_id   = (int)($_SESSION['role_id'] ?? 0);

if ($client_id <= 0 && $role_id !== 1) {
    die('Client not found in session.');
}

/* ================= HELPERS ================= */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function safe_status_label(array $session = null, string $tableStatus = 'Available'): string
{
    if ($session) {
        $s = strtolower((string)($session['session_status'] ?? $session['status'] ?? ''));
        if ($s === 'kot_sent') return 'KOT Sent';
        if ($s === 'bill_printed') return 'Bill Printed';
        if ($s === 'open') return 'Occupied';
    }
    return $tableStatus ?: 'Available';
}

function status_class(string $status): string
{
    $s = strtolower(trim($status));
    if ($s === 'available') return 'st-available';
    if ($s === 'occupied') return 'st-occupied';
    if ($s === 'kot sent' || $s === 'kot_sent') return 'st-kot';
    if ($s === 'bill printed' || $s === 'bill_printed') return 'st-bill';
    if ($s === 'reserved') return 'st-reserved';
    if ($s === 'dirty') return 'st-dirty';
    return 'st-muted';
}

function money_fmt($amount): string
{
    return 'Rs. ' . number_format((float)$amount, 2);
}

/* ================= ENSURE MINIMUM RESTAURANT SCHEMA ================= */
try {
    if (!table_exists($conn, 'restaurant_tables')) {
        $conn->query("CREATE TABLE restaurant_tables (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            table_no VARCHAR(50) NOT NULL,
            table_name VARCHAR(100) DEFAULT NULL,
            floor_name VARCHAR(100) DEFAULT NULL,
            capacity INT(11) NOT NULL DEFAULT 4,
            current_session_id INT(11) DEFAULT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            status ENUM('Available','Occupied','Reserved','Bill Printed','Dirty','Inactive') NOT NULL DEFAULT 'Available',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rt_client_branch (client_id, branch_id),
            KEY idx_rt_status (status),
            KEY idx_rt_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    $tableAdds = [
        'branch_id' => "ALTER TABLE restaurant_tables ADD COLUMN branch_id INT(11) NOT NULL DEFAULT 0 AFTER client_id",
        'table_name' => "ALTER TABLE restaurant_tables ADD COLUMN table_name VARCHAR(100) DEFAULT NULL AFTER table_no",
        'floor_name' => "ALTER TABLE restaurant_tables ADD COLUMN floor_name VARCHAR(100) DEFAULT NULL AFTER table_name",
        'capacity' => "ALTER TABLE restaurant_tables ADD COLUMN capacity INT(11) NOT NULL DEFAULT 4 AFTER floor_name",
        'current_session_id' => "ALTER TABLE restaurant_tables ADD COLUMN current_session_id INT(11) DEFAULT NULL AFTER capacity",
        'sort_order' => "ALTER TABLE restaurant_tables ADD COLUMN sort_order INT(11) NOT NULL DEFAULT 0 AFTER current_session_id",
        'is_active' => "ALTER TABLE restaurant_tables ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status",
        'updated_at' => "ALTER TABLE restaurant_tables ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($tableAdds as $col => $sql) {
        if (!column_exists($conn, 'restaurant_tables', $col)) {
            try { $conn->query($sql); } catch (Throwable $ignore) {}
        }
    }

    if (!table_exists($conn, 'restaurant_table_sessions')) {
        $conn->query("CREATE TABLE restaurant_table_sessions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            table_id INT(11) DEFAULT NULL,
            waiter_id INT(11) DEFAULT NULL,
            customer_id INT(11) DEFAULT NULL,
            guest_count INT(11) NOT NULL DEFAULT 1,
            order_type ENUM('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in',
            status ENUM('open','kot_sent','bill_printed','paid','cancelled','void') NOT NULL DEFAULT 'open',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            service_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            round_off DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            sale_id INT(11) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            opened_by INT(11) DEFAULT NULL,
            closed_by INT(11) DEFAULT NULL,
            started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            bill_printed_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rts_client_branch (client_id, branch_id),
            KEY idx_rts_table_status (table_id, status),
            KEY idx_rts_waiter (waiter_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    if (!table_exists($conn, 'restaurant_order_items')) {
        $conn->query("CREATE TABLE restaurant_order_items (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            session_id INT(11) NOT NULL,
            item_id INT(11) DEFAULT NULL,
            item_name VARCHAR(255) NOT NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            kot_status ENUM('pending','sent') NOT NULL DEFAULT 'pending',
            item_status ENUM('active','preparing','ready','served','cancelled','void') NOT NULL DEFAULT 'active',
            special_note TEXT DEFAULT NULL,
            cancelled_reason TEXT DEFAULT NULL,
            created_by INT(11) DEFAULT NULL,
            cancelled_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_roi_session (session_id),
            KEY idx_roi_client_branch (client_id, branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    if (!table_exists($conn, 'restaurant_waiters')) {
        $conn->query("CREATE TABLE restaurant_waiters (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            user_id INT(11) DEFAULT NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_waiters_client_branch (client_id, branch_id),
            KEY idx_waiters_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
} catch (Throwable $schemaError) {
    $schemaMessage = $schemaError->getMessage();
}

/* ================= FETCH BRANCHES ================= */
$branches = [];
try {
    if (table_exists($conn, 'branches')) {
        $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $branches[(int)$row['id']] = $row['name'];
        }
        $stmt->close();
    }
} catch (Throwable $e) {}
if (empty($branches)) {
    $fallbackBranch = $branch_id > 0 ? $branch_id : 0;
    $branches[$fallbackBranch] = $fallbackBranch > 0 ? ('Branch ' . $fallbackBranch) : 'Main Branch';
}
if ($branch_id <= 0) {
    $keys = array_keys($branches);
    $branch_id = (int)$keys[0];
}

$hasBranchParam = isset($_GET['branch_id']);
$selectedBranch = $hasBranchParam ? (int)$_GET['branch_id'] : $branch_id;
if (!array_key_exists($selectedBranch, $branches)) {
    $selectedBranch = $branch_id;
}

/* ================= AUTO RESTAURANT BRANCH FIX =================
   If dashboard is opened without ?branch_id=, automatically pick the branch
   where restaurant tables exist. This prevents empty dashboard on main URL.
*/
if (!$hasBranchParam && table_exists($conn, 'restaurant_tables')) {
    try {
        $autoBranchId = null;
        $stmt = $conn->prepare("SELECT branch_id, COUNT(*) AS total_tables
            FROM restaurant_tables
            WHERE client_id = ? AND COALESCE(is_active, 1) = 1
            GROUP BY branch_id
            ORDER BY
                CASE WHEN branch_id = ? THEN 0 ELSE 1 END ASC,
                CASE WHEN branch_id > 0 THEN 0 ELSE 1 END ASC,
                total_tables DESC,
                branch_id ASC
            LIMIT 1");
        $stmt->bind_param('ii', $client_id, $branch_id);
        $stmt->execute();
        $autoRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($autoRow) {
            $autoBranchId = (int)$autoRow['branch_id'];
            if (!array_key_exists($autoBranchId, $branches)) {
                $branches[$autoBranchId] = $autoBranchId > 0 ? ('Branch ' . $autoBranchId) : 'Main Branch';
            }
            $selectedBranch = $autoBranchId;
            $_SESSION['selected_branch_id'] = $selectedBranch;
            $_SESSION['branch_id'] = $selectedBranch;

            header('Location: restaurant_dashboard.php?branch_id=' . $selectedBranch);
            exit;
        }
    } catch (Throwable $e) {
        // Keep normal branch selection if auto branch detection fails.
    }
}

/* ================= FETCH WAITERS ================= */
$waiters = [];
try {
    $stmt = $conn->prepare("SELECT id, name, branch_id FROM restaurant_waiters WHERE client_id = ? AND status = 'active' AND (branch_id = ? OR branch_id = 0) ORDER BY name ASC");
    $stmt->bind_param('ii', $client_id, $selectedBranch);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $waiters[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {}

/* ================= FETCH CUSTOMERS OPTIONAL ================= */
$customers = [];
try {
    if (table_exists($conn, 'customers')) {
        $customerNameCol = column_exists($conn, 'customers', 'name') ? 'name' : (column_exists($conn, 'customers', 'customer_name') ? 'customer_name' : 'id');
        $phoneCol = column_exists($conn, 'customers', 'phone') ? 'phone' : (column_exists($conn, 'customers', 'mobile') ? 'mobile' : 'NULL');
        $sql = "SELECT id, {$customerNameCol} AS customer_name, {$phoneCol} AS phone FROM customers WHERE client_id = ? ORDER BY {$customerNameCol} ASC LIMIT 250";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $customers[] = $row;
        }
        $stmt->close();
    }
} catch (Throwable $e) {}

/* ================= FILTERS ================= */
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$floorFilter = trim((string)($_GET['floor'] ?? ''));

/* ================= FETCH TABLES + ACTIVE SESSIONS ================= */
$tables = [];
$floors = [];
$activeStatuses = "'open','kot_sent','bill_printed'";
try {
    $sql = "
        SELECT
            t.*,
            s.id AS session_id,
            s.status AS session_status,
            s.waiter_id,
            s.guest_count,
            s.started_at,
            s.grand_total,
            COALESCE(oi.cart_total, 0) AS cart_total,
            COALESCE(oi.item_count, 0) AS item_count,
            w.name AS waiter_name
        FROM restaurant_tables t
        LEFT JOIN restaurant_table_sessions s
            ON s.id = (
                SELECT s2.id
                FROM restaurant_table_sessions s2
                WHERE s2.client_id = t.client_id
                  AND s2.table_id = t.id
                  AND s2.status IN ($activeStatuses)
                ORDER BY s2.id DESC
                LIMIT 1
            )
        LEFT JOIN (
            SELECT session_id, SUM(total) AS cart_total, COUNT(*) AS item_count
            FROM restaurant_order_items
            WHERE client_id = ? AND item_status NOT IN ('cancelled','void')
            GROUP BY session_id
        ) oi ON oi.session_id = s.id
        LEFT JOIN restaurant_waiters w ON w.id = s.waiter_id
        WHERE t.client_id = ? AND (t.branch_id = ? OR t.branch_id = 0) AND COALESCE(t.is_active, 1) = 1
    ";

    $types = 'iii';
    $params = [$client_id, $client_id, $selectedBranch];

    if ($search !== '') {
        $sql .= " AND (t.table_no LIKE ? OR t.table_name LIKE ? OR t.floor_name LIKE ?)";
        $like = '%' . $search . '%';
        $types .= 'sss';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    if ($floorFilter !== '') {
        $sql .= " AND t.floor_name = ?";
        $types .= 's';
        $params[] = $floorFilter;
    }

    $sql .= " ORDER BY t.sort_order ASC, t.floor_name ASC, CAST(t.table_no AS UNSIGNED) ASC, t.table_no ASC";

    $stmt = $conn->prepare($sql);
    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $displayStatus = safe_status_label(!empty($row['session_id']) ? $row : null, (string)$row['status']);
        if ($statusFilter !== '' && strtolower($displayStatus) !== strtolower($statusFilter)) {
            continue;
        }
        $row['display_status'] = $displayStatus;
        $row['running_total'] = max((float)$row['grand_total'], (float)$row['cart_total']);
        $tables[] = $row;
        $floor = trim((string)($row['floor_name'] ?? ''));
        if ($floor !== '') $floors[$floor] = true;
    }
    $stmt->close();
} catch (Throwable $e) {
    $fetchError = $e->getMessage();
}

/* Fetch all floors for filter even when search applied */
try {
    $stmt = $conn->prepare("SELECT DISTINCT floor_name FROM restaurant_tables WHERE client_id = ? AND (branch_id = ? OR branch_id = 0) AND is_active = 1 AND floor_name IS NOT NULL AND floor_name <> '' ORDER BY floor_name ASC");
    $stmt->bind_param('ii', $client_id, $selectedBranch);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $floors[$row['floor_name']] = true;
    $stmt->close();
} catch (Throwable $e) {}
$floorList = array_keys($floors);
sort($floorList);

/* ================= STATS ================= */
$stats = [
    'total' => count($tables),
    'available' => 0,
    'occupied' => 0,
    'reserved' => 0,
    'bill' => 0,
    'dirty' => 0,
    'running_total' => 0,
];
foreach ($tables as $t) {
    $st = strtolower((string)$t['display_status']);
    if ($st === 'available') $stats['available']++;
    elseif ($st === 'reserved') $stats['reserved']++;
    elseif ($st === 'bill printed') $stats['bill']++;
    elseif ($st === 'dirty') $stats['dirty']++;
    else $stats['occupied']++;
    $stats['running_total'] += (float)$t['running_total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Restaurant Dashboard - One ERP</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
html, body{
    margin:0!important;
    padding:0!important;
    min-height:100%;
    background:#070b14!important;
    color:#f8fafc!important;
    overflow-x:hidden!important;
    font-family:Inter,"Segoe UI",Arial,sans-serif!important;
}
body.restaurant-dashboard-page{
    --sidebar-w:270px;
    --topbar-h:70px;
    background:
        radial-gradient(circle at 12% 8%,rgba(124,58,237,.30),transparent 28%),
        radial-gradient(circle at 78% 2%,rgba(59,130,246,.14),transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 42%,#160f2d 70%,#090d18 100%)!important;
}
.container-scroll{
    display:flex!important;
    min-height:100vh!important;
    width:100%!important;
    background:transparent!important;
}
.container-scroll>.sidebar{
    position:fixed!important;
    left:0!important;
    top:0!important;
    width:var(--sidebar-w)!important;
    height:100vh!important;
    z-index:1040!important;
    overflow:hidden!important;
}
.main-panel{
    margin-left:var(--sidebar-w)!important;
    width:calc(100% - var(--sidebar-w))!important;
    min-height:100vh!important;
    background:transparent!important;
    transition:.25s ease!important;
}
.navbar.fixed-top,.my-navbar.fixed-top{
    left:var(--sidebar-w)!important;
    width:calc(100% - var(--sidebar-w))!important;
    z-index:1030!important;
    background:#070b14!important;
    border-bottom:1px solid rgba(148,163,184,.14)!important;
    box-shadow:0 10px 30px rgba(0,0,0,.28)!important;
}
body.sidebar-collapsed .container-scroll>.sidebar{transform:translateX(-100%)!important;}
body.sidebar-collapsed .main-panel{margin-left:0!important;width:100%!important;}
body.sidebar-collapsed .navbar.fixed-top,
body.sidebar-collapsed .my-navbar.fixed-top{left:0!important;width:100%!important;}

.content-wrapper{
    position:relative;
    min-height:100vh;
    padding:94px 22px 30px;
    background:
        radial-gradient(circle at 12% 8%,rgba(124,58,237,.34),transparent 28%),
        radial-gradient(circle at 78% 2%,rgba(59,130,246,.16),transparent 30%),
        radial-gradient(circle at 92% 86%,rgba(34,197,94,.08),transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%)!important;
}
.content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px),
        linear-gradient(180deg,rgba(255,255,255,.03) 1px,transparent 1px);
    background-size:44px 44px;
    opacity:.20;
    z-index:0;
}
.content-wrapper>*{position:relative;z-index:1;}

.page-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:18px;
}
.page-title{
    font-size:28px;
    font-weight:950;
    letter-spacing:-.04em;
    margin:0;
    color:#fff;
    text-shadow:0 0 30px rgba(124,58,237,.35);
}
.page-sub{
    color:#aab4cf;
    font-weight:750;
    margin-top:4px;
}
.manage-link{
    height:42px;
    border-radius:14px;
    background:rgba(15,23,42,.70)!important;
    color:#e5e7eb!important;
    border:1px solid rgba(148,163,184,.18)!important;
    font-weight:900!important;
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:0 14px;
    text-decoration:none;
}

/* Simple top action buttons */
.quick-actions{
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:12px;
    margin-bottom:20px;
}
.quick-action{
    min-height:72px;
    border-radius:22px;
    text-decoration:none;
    color:#fff!important;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    text-align:center;
    font-size:17px;
    font-weight:950;
    letter-spacing:-.02em;
    border:1px solid rgba(255,255,255,.13);
    box-shadow:0 18px 45px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.08);
    transition:.18s ease;
    background:linear-gradient(135deg,rgba(15,23,42,.85),rgba(30,41,59,.58));
}
.quick-action:hover{
    transform:translateY(-2px);
    border-color:rgba(139,92,246,.52);
    box-shadow:0 22px 60px rgba(0,0,0,.40),0 0 36px rgba(124,58,237,.16);
}
.quick-action i{font-size:24px;}
.quick-action.active{background:linear-gradient(135deg,#5b3bdc,#7c3aed);}
.quick-action.takeaway{background:linear-gradient(135deg,#0f766e,#06b6d4);}
.quick-action.delivery{background:linear-gradient(135deg,#0b63ce,#2563eb);}
.quick-action.bills{background:linear-gradient(135deg,#b45309,#f59e0b);}
.quick-action.kitchen{background:linear-gradient(135deg,#be123c,#ef4444);}
.quick-action.items{background:linear-gradient(135deg,#15803d,#22c55e);}

/* Tiny optional filters - kept simple */
.simple-filter{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:18px;
    padding:10px;
    border-radius:18px;
    background:rgba(15,23,42,.48);
    border:1px solid rgba(148,163,184,.14);
    box-shadow:0 16px 40px rgba(0,0,0,.18);
}
.simple-filter .form-select,
.simple-filter .form-control{
    min-height:44px;
    border-radius:14px;
    background:rgba(15,23,42,.78)!important;
    color:#f8fafc!important;
    border:1px solid rgba(148,163,184,.18)!important;
    font-weight:850;
}
.simple-filter .form-control::placeholder{color:#94a3b8;}
.simple-filter .form-select option{background:#0f172a;color:#e5e7eb;}
.btn-simple{
    min-height:44px;
    border:none;
    border-radius:14px;
    padding:0 16px;
    background:linear-gradient(135deg,#6d4cff,#7c3aed)!important;
    color:#fff!important;
    font-weight:950!important;
    box-shadow:0 14px 30px rgba(109,76,255,.24);
    white-space:nowrap;
}
.btn-soft{
    min-height:44px;
    border-radius:14px;
    background:rgba(15,23,42,.78)!important;
    color:#e5e7eb!important;
    border:1px solid rgba(148,163,184,.20)!important;
    font-weight:900!important;
    white-space:nowrap;
}


.dashboard-help-strip{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
    margin:-8px 0 18px;
}
.help-step{
    min-height:54px;
    border-radius:18px;
    background:rgba(15,23,42,.54);
    border:1px solid rgba(148,163,184,.14);
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 14px;
    color:#dbe4ff!important;
    font-weight:900;
    text-decoration:none!important;
}
.help-step:hover{border-color:rgba(34,197,94,.52);transform:translateY(-1px);}
.help-step.add-items{background:linear-gradient(135deg,rgba(21,128,61,.35),rgba(15,23,42,.58));border-color:rgba(34,197,94,.28);}
.help-step.add-items b{background:linear-gradient(135deg,#15803d,#22c55e);}

.help-step b{
    width:30px;
    height:30px;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    color:#fff;
    flex:0 0 auto;
}
.help-step span{font-size:13px;line-height:1.2;}

/* Simple table cards */
.tables-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(210px,1fr));
    gap:16px;
}
.table-card{
    position:relative;
    min-height:190px;
    border-radius:24px;
    padding:18px;
    background:
        radial-gradient(circle at 20% 0%,rgba(124,58,237,.16),transparent 34%),
        linear-gradient(135deg,rgba(15,23,42,.86),rgba(17,24,39,.62));
    border:1px solid rgba(148,163,184,.18);
    box-shadow:0 18px 48px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05);
    backdrop-filter:blur(18px);
    transition:.18s ease;
    cursor:pointer;
    overflow:hidden;
}
.table-card:hover{
    transform:translateY(-4px);
    border-color:rgba(139,92,246,.48);
    box-shadow:0 26px 70px rgba(0,0,0,.42),0 0 42px rgba(124,58,237,.17);
}
.table-card.loading{pointer-events:none;opacity:.72;}
.table-card::before{
    content:"";
    position:absolute;
    right:-28px;
    top:-28px;
    width:115px;
    height:115px;
    border-radius:50%;
    opacity:.23;
}
.table-card.st-available::before{background:#22c55e;}
.table-card.st-occupied::before{background:#ef4444;}
.table-card.st-kot::before{background:#38bdf8;}
.table-card.st-bill::before{background:#f59e0b;}
.table-card.st-reserved::before{background:#fb923c;}
.table-card.st-dirty::before{background:#94a3b8;}

.table-number{
    font-size:30px;
    line-height:1;
    font-weight:950;
    color:#fff;
    letter-spacing:-.05em;
    margin-bottom:10px;
    padding-right:82px;
}
.table-small-name{
    color:#cbd5e1;
    font-size:13px;
    font-weight:850;
    min-height:18px;
    margin-bottom:16px;
}
.status-pill{
    position:absolute;
    top:16px;
    right:16px;
    padding:8px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:950;
    border:1px solid rgba(255,255,255,.18);
    max-width:112px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.status-pill.st-available{background:rgba(34,197,94,.16);color:#86efac;}
.status-pill.st-occupied{background:rgba(239,68,68,.16);color:#fca5a5;}
.status-pill.st-kot{background:rgba(14,165,233,.16);color:#7dd3fc;}
.status-pill.st-bill{background:rgba(245,158,11,.16);color:#fcd34d;}
.status-pill.st-reserved{background:rgba(249,115,22,.16);color:#fdba74;}
.status-pill.st-dirty{background:rgba(148,163,184,.18);color:#cbd5e1;}
.status-pill.st-muted{background:rgba(148,163,184,.14);color:#cbd5e1;}

.big-status{
    border-radius:18px;
    padding:14px;
    background:rgba(15,23,42,.46);
    border:1px solid rgba(148,163,184,.10);
    margin-top:8px;
}
.big-status .label{
    color:#94a3b8;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.04em;
}
.big-status .value{
    color:#fff;
    font-size:20px;
    font-weight:950;
    margin-top:2px;
    word-break:break-word;
}
.card-bottom{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-top:14px;
}
.waiter-line{
    color:#cbd5e1;
    font-size:12px;
    font-weight:850;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.card-btn{
    border:0;
    border-radius:14px;
    min-height:40px;
    padding:0 14px;
    color:#fff!important;
    font-weight:950!important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed)!important;
    box-shadow:0 12px 28px rgba(109,76,255,.25);
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    white-space:nowrap;
}
.card-btn.green{background:linear-gradient(135deg,#059669,#16a34a)!important;}
.card-btn.soft{
    background:rgba(15,23,42,.78)!important;
    border:1px solid rgba(148,163,184,.20)!important;
    box-shadow:none;
}

.table-card-title{
    font-size:28px;
    line-height:1;
    font-weight:950;
    color:#fff;
    letter-spacing:-.04em;
    padding-right:104px;
    min-height:32px;
    margin-top:4px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.table-card-sub{
    color:#cbd5e1;
    font-size:13px;
    font-weight:950;
    margin-top:10px;
}
.table-lines{
    margin-top:12px;
    display:grid;
    gap:8px;
}
.table-line{
    color:#cbd5e1;
    font-size:12px;
    font-weight:850;
    display:flex;
    align-items:center;
    gap:7px;
    min-height:18px;
}
.table-line i{color:#aab4cf;}
.table-running{
    margin-top:14px;
    color:#fff;
    font-size:16px;
    font-weight:950;
}
.table-status-big{
    margin-top:14px;
    color:#fff;
    font-size:16px;
    font-weight:950;
}
.tap-hint{
    margin-top:8px;
    color:#94a3b8;
    font-size:11px;
    font-weight:850;
}

.empty-state{
    padding:34px;
    border-radius:22px;
    text-align:center;
    background:rgba(15,23,42,.56);
    border:1px solid rgba(148,163,184,.16);
    color:#cbd5e1;
    font-weight:850;
}
.alert{
    border-radius:16px;
    border:1px solid rgba(148,163,184,.18);
    font-weight:850;
}
.toast-wrap{
    position:fixed;
    right:20px;
    bottom:20px;
    z-index:7000;
    max-width:360px;
}
.app-toast{
    display:none;
    padding:14px 16px;
    border-radius:16px;
    color:#fff;
    font-weight:900;
    box-shadow:0 20px 60px rgba(0,0,0,.45);
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
}
.app-toast.error{background:linear-gradient(135deg,#dc2626,#ef4444);}

@media(max-width:1199px){
    .quick-actions{grid-template-columns:repeat(3,1fr);}
}
@media(max-width:991px){
    .container-scroll>.sidebar{transform:translateX(-100%);}
    .main-panel{margin-left:0!important;width:100%!important;}
    .navbar.fixed-top,.my-navbar.fixed-top{left:0!important;width:100%!important;}
    .content-wrapper{padding:86px 14px 24px;}
    .quick-actions{grid-template-columns:repeat(2,1fr);}
    .simple-filter{display:grid;grid-template-columns:1fr;}
    .tables-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
    .table-card{min-height:175px;padding:15px;}
    .table-number{font-size:25px;}
}
@media(max-width:575px){
    .page-title{font-size:22px;}
    .page-sub{font-size:13px;}
    .quick-actions{grid-template-columns:repeat(2,1fr);gap:10px;}
    .quick-action{min-height:62px;font-size:14px;border-radius:18px;}
    .quick-action i{font-size:20px;}
    .tables-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
    .table-card{min-height:160px;border-radius:19px;padding:13px;}
    .table-number{font-size:22px;padding-right:0;margin-top:30px;}
    .table-card-title{font-size:22px;padding-right:0;margin-top:30px;}
    .table-card-sub{font-size:11px;}
    .table-running,.table-status-big{font-size:13px;}
    .table-lines{gap:4px;margin-top:8px;}
    .dashboard-help-strip{grid-template-columns:repeat(2,1fr);}
    .help-step{min-height:48px;padding:8px 10px;}
    .help-step span{font-size:11px;}
    .status-pill{top:12px;right:12px;font-size:10px;max-width:96px;padding:6px 8px;}
    .big-status{padding:10px;border-radius:14px;}
    .big-status .value{font-size:16px;}
    .card-bottom{display:block;}
    .waiter-line{margin-bottom:8px;}
    .card-btn{width:100%;min-height:36px;}
}
</style>
</head>
<body class="restaurant-dashboard-page">
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?>
        <div class="content-wrapper">
            <div class="page-head">
                <div>
                    <h1 class="page-title"><i class="bi bi-cup-hot me-2"></i>Restaurant Dashboard</h1>
                    <div class="page-sub">Simple table screen for dine-in, takeaway, delivery, open bills and kitchen.</div>
                </div>
                <div>
                    <a href="restaurant_tables.php" class="manage-link"><i class="bi bi-grid-3x3-gap"></i> Manage Tables</a>
                    <a href="restaurant_waiters.php" class="manage-link ms-1"><i class="bi bi-person-badge"></i> Waiters</a>
                </div>
            </div>

            <?php if (!empty($schemaMessage ?? '')): ?>
                <div class="alert alert-danger">Schema issue: <?= e($schemaMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($fetchError ?? '')): ?>
                <div class="alert alert-danger">Dashboard data load issue: <?= e($fetchError) ?></div>
            <?php endif; ?>

            <div class="quick-actions">
                <a class="quick-action active" href="restaurant_dashboard.php?branch_id=<?= (int)$selectedBranch ?>">
                    <i class="bi bi-grid-3x3-gap"></i>
                    <span>Dine In</span>
                </a>
                <a class="quick-action items" href="items.php?restaurant=1&branch_id=<?= (int)$selectedBranch ?>">
                    <i class="bi bi-plus-square"></i>
                    <span>Add Items</span>
                </a>
                <a class="quick-action takeaway" href="restaurant_pos.php?order_type=takeaway&branch_id=<?= (int)$selectedBranch ?>">
                    <i class="bi bi-bag-check"></i>
                    <span>Parcel / Takeaway</span>
                </a>
                <a class="quick-action delivery" href="restaurant_pos.php?order_type=delivery&branch_id=<?= (int)$selectedBranch ?>">
                    <i class="bi bi-scooter"></i>
                    <span>Delivery</span>
                </a>
                <a class="quick-action bills" href="restaurant_orders.php?status=open&branch_id=<?= (int)$selectedBranch ?>">
                    <i class="bi bi-receipt"></i>
                    <span>Open Bills</span>
                </a>
                <a class="quick-action kitchen" href="restaurant_kitchen.php?branch_id=<?= (int)$selectedBranch ?>">
                    <i class="bi bi-fire"></i>
                    <span>Kitchen</span>
                </a>
            </div>

            <div class="dashboard-help-strip">
                <div class="help-step"><b>1</b><span>Add New Tables</span></div>
                <a class="help-step add-items" href="items.php?restaurant=1&branch_id=<?= (int)$selectedBranch ?>"><b>2</b><span>Add New Items </span></a>
                <div class="help-step"><b>3</b><span>Send KOT</span></div>
                <div class="help-step"><b>4</b><span>Bill / Payment complete</span></div>
            </div>

            <form class="simple-filter" method="get">
                <select name="branch_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($branches as $bid => $bname): ?>
                        <option value="<?= (int)$bid ?>" <?= (int)$bid === (int)$selectedBranch ? 'selected' : '' ?>><?= e($bname) ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="q" class="form-control" placeholder="Search table..." value="<?= e($search) ?>">

                <select name="floor" class="form-select">
                    <option value="">All Floors</option>
                    <?php foreach ($floorList as $floor): ?>
                        <option value="<?= e($floor) ?>" <?= $floorFilter === $floor ? 'selected' : '' ?>><?= e($floor) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <?php foreach (['Available','Occupied','KOT Sent','Bill Printed','Reserved','Dirty'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= strtolower($statusFilter) === strtolower($st) ? 'selected' : '' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>

                <button class="btn btn-simple" type="submit"><i class="bi bi-search me-1"></i>Search</button>
                <a class="btn btn-soft" href="restaurant_dashboard.php?branch_id=<?= (int)$selectedBranch ?>"><i class="bi bi-arrow-clockwise me-1"></i>Reset</a>
            </form>

            <?php if (empty($tables)): ?>
                <div class="empty-state">
                    <i class="bi bi-grid-3x3-gap fs-1 d-block mb-2"></i>
                    No restaurant tables found. Add tables first from <a href="restaurant_tables.php" class="text-white fw-bold">Restaurant Tables</a>.
                </div>
            <?php else: ?>
                <div class="tables-grid">
                    <?php foreach ($tables as $t):
                        $displayStatus = (string)$t['display_status'];
                        $cls = status_class($displayStatus);
                        $sessionId = (int)($t['session_id'] ?? 0);
                        $isStartable = ($sessionId <= 0 && in_array($displayStatus, ['Available','Reserved'], true));
                        $isDirty = ($displayStatus === 'Dirty');
                        $openUrl = $sessionId > 0 ? ('restaurant_pos.php?session_id=' . $sessionId) : ('restaurant_pos.php?table_id=' . (int)$t['id']);
                        $runningTotal = (float)($t['running_total'] ?? 0);
                        $tableTitle = trim((string)($t['table_name'] ?? ''));
                        if ($tableTitle === '') $tableTitle = 'Table ' . (string)$t['table_no'];
                        $tableBranchId = (int)($t['branch_id'] ?? 0);
                        $tableBranchLabel = $branches[$tableBranchId] ?? ('Branch ' . $tableBranchId);
                        $floorText = trim((string)($t['floor_name'] ?? ''));
                        if ($floorText === '') $floorText = '-';
                    ?>
                    <div class="table-card <?= e($cls) ?>"
                         data-table-id="<?= (int)$t['id'] ?>"
                         data-table-branch-id="<?= (int)$tableBranchId ?>"
                         data-table-no="<?= e($t['table_no']) ?>"
                         data-table-name="<?= e($t['table_name'] ?? '') ?>"
                         data-table-status="<?= e($displayStatus) ?>"
                         data-session-id="<?= (int)$sessionId ?>"
                         data-open-url="<?= e($openUrl) ?>"
                         data-startable="<?= $isStartable ? '1' : '0' ?>">
                        <span class="status-pill <?= e($cls) ?>"><?= e($displayStatus) ?></span>

                        <div class="table-card-title"><?= e($tableTitle) ?></div>
                        <div class="table-card-sub">No: <?= e($t['table_no']) ?> • <?= (int)($t['capacity'] ?? 0) ?> Seats</div>

                        <div class="table-lines">
                            <div class="table-line"><i class="bi bi-building"></i><span><?= e($tableBranchLabel) ?></span></div>
                            <div class="table-line"><i class="bi bi-layers"></i><span><?= e($floorText) ?></span></div>
                        </div>

                        <?php if ($sessionId > 0): ?>
                            <div class="table-running">Running: <?= e(money_fmt($runningTotal)) ?></div>
                            <div class="tap-hint">Tap card to open</div>
                        <?php else: ?>
                            <div class="table-status-big"><?= e($displayStatus) ?></div>
                            <div class="tap-hint"><?= $isStartable ? 'Tap card to start' : 'Action not available' ?></div>
                        <?php endif; ?>

                        <div class="card-bottom">
                            <div class="waiter-line">
                                <i class="bi bi-person-badge me-1"></i>
                                <?= e($sessionId > 0 ? ($t['waiter_name'] ?: '-') : 'Ready') ?>
                            </div>

                            <?php if ($sessionId > 0): ?>
                                <a href="<?= e($openUrl) ?>" class="card-btn"><i class="bi bi-box-arrow-in-right"></i>Open</a>
                            <?php elseif ($isStartable): ?>
                                <button type="button" class="card-btn green start-order-btn"><i class="bi bi-plus-circle"></i>Start</button>
                            <?php elseif ($isDirty): ?>
                                <a href="restaurant_tables.php" class="card-btn soft"><i class="bi bi-brush"></i>Clean</a>
                            <?php else: ?>
                                <button type="button" class="card-btn soft" disabled>Unavailable</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="toast-wrap"><div class="app-toast" id="appToast"></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const selectedBranch = <?= (int)$selectedBranch ?>;
const toastEl = document.getElementById('appToast');
let isStartingOrder = false;

function showToast(message, isError=false){
    toastEl.textContent = message;
    toastEl.className = 'app-toast' + (isError ? ' error' : '');
    toastEl.style.display = 'block';
    setTimeout(() => { toastEl.style.display = 'none'; }, 3200);
}

function startTableOrder(card){
    if (isStartingOrder) return;

    const tableId = card.dataset.tableId || '0';
    if (parseInt(tableId, 10) <= 0) {
        showToast('Invalid table selected.', true);
        return;
    }

    isStartingOrder = true;
    card.classList.add('loading');

    const btn = card.querySelector('.start-order-btn');
    const oldBtnHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Start';
    }

    const formData = new FormData();
    formData.append('table_id', tableId);
    const tableBranchId = parseInt(card.dataset.tableBranchId || selectedBranch, 10);
    formData.append('branch_id', tableBranchId);
    formData.append('guest_count', '1');
    formData.append('order_type', 'dine_in');
    formData.append('waiter_id', '0');
    formData.append('customer_id', '0');
    formData.append('notes', '');

    fetch('ajax_restaurant_start_session.php', {
        method: 'POST',
        body: formData,
        headers: {'Accept': 'application/json'}
    })
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); } catch(err) { throw new Error(text || 'Invalid server response'); }
    })
    .then(data => {
        if (!data.success) {
            showToast(data.message || 'Order start failed.', true);
            return;
        }
        window.location.href = data.redirect || ('restaurant_pos.php?session_id=' + data.session_id);
    })
    .catch(err => showToast(err.message || 'Request failed.', true))
    .finally(() => {
        isStartingOrder = false;
        card.classList.remove('loading');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = oldBtnHtml;
        }
    });
}

document.querySelectorAll('.table-card').forEach(card => {
    card.addEventListener('click', function(e){
        if (e.target.closest('a') || e.target.closest('button')) return;

        const sessionId = parseInt(this.dataset.sessionId || '0', 10);
        const status = this.dataset.tableStatus || '';

        if (sessionId > 0) {
            window.location.href = this.dataset.openUrl;
            return;
        }

        if (this.dataset.startable === '1') {
            startTableOrder(this);
            return;
        }

        if (status === 'Dirty') {
            showToast('Table is dirty. Mark it clean from Restaurant Tables page first.', true);
            return;
        }

        showToast('This table is not available right now.', true);
    });
});

document.querySelectorAll('.start-order-btn').forEach(btn => {
    btn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        startTableOrder(this.closest('.table-card'));
    });
});
</script>
</body>
</html>
