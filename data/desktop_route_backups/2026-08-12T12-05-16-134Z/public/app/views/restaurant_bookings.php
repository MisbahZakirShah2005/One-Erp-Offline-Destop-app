<?php
session_start();

/* Dynamic page: prevent old cached booking/table output */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

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
    die('Database connection not found. Please check config/db.php and make sure it creates mysqli variable named $conn.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ================= AUTH CHECK + STRICT USER SESSION SYNC ================= */
if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$auth_user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $stmt = $conn->prepare('SELECT id, client_id, role_id, name, email, status FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $auth_user_id);
    $stmt->execute();
    $authUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $authError) {
    $_SESSION['error'] = 'Authentication check failed: ' . $authError->getMessage();
    header('Location: /public/app/views/login_view.php');
    exit;
}

if (!$authUser || strtolower((string)($authUser['status'] ?? '')) !== 'active') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /public/app/views/login_view.php');
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
    header('Location: /public/app/views/login_view.php');
    exit;
}

/* ================= HELPERS ================= */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_self()
{
    header('Location: restaurant_bookings.php?_=' . time());
    exit;
}

function table_exists(mysqli $conn, $table)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function column_exists(mysqli $conn, $table, $column)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function index_exists(mysqli $conn, $table, $index)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function normalize_booking_status($status)
{
    $s = strtolower(trim((string)$status));
    $allowed = ['pending','confirmed','seated','cancelled','no_show','completed'];
    return in_array($s, $allowed, true) ? $s : 'confirmed';
}

function booking_badge_class($status)
{
    $s = normalize_booking_status($status);
    if ($s === 'confirmed') return 'badge-confirmed';
    if ($s === 'pending') return 'badge-pending';
    if ($s === 'seated') return 'badge-seated';
    if ($s === 'completed') return 'badge-completed';
    if ($s === 'no_show') return 'badge-noshow';
    return 'badge-cancelled';
}

function format_table_label($tableNo)
{
    $no = trim((string)$tableNo);
    if ($no === '') return '-';
    if (preg_match('/^t/i', $no)) return strtoupper($no);
    return 'Table: ' . ltrim($no, '0');
}

function display_table_short($tableNo)
{
    $no = trim((string)$tableNo);
    if ($no === '') return '-';
    if (preg_match('/^t/i', $no)) return strtoupper($no);
    return ltrim($no, '0');
}

function pretty_time($time)
{
    if (!$time) return '-';
    $ts = strtotime((string)$time);
    return $ts ? date('g:i A', $ts) : e($time);
}

function pretty_date($date)
{
    if (!$date) return '-';
    $ts = strtotime((string)$date);
    return $ts ? date('d/m/Y', $ts) : e($date);
}

function reservation_table_can_be_freed(mysqli $conn, $clientId, $tableId)
{
    if ($tableId <= 0 || !table_exists($conn, 'restaurant_reservations')) return true;

    // Table sirf tab Available hoga jab us table ki koi pending/confirmed/seated
    // current ya upcoming booking baqi na ho.
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM restaurant_reservations
        WHERE client_id = ?
          AND table_id = ?
          AND status IN ('pending','confirmed','seated')
          AND (reservation_date > ? OR (reservation_date = ? AND reservation_time >= ?))
    " );
    $stmt->bind_param('iisss', $clientId, $tableId, $today, $today, $nowTime);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['c'] ?? 0)) <= 0;
}

function sync_reserved_tables_for_active_bookings(mysqli $conn, $clientId, $branchId = 0)
{
    if (!table_exists($conn, 'restaurant_reservations') || !table_exists($conn, 'restaurant_tables')) return;

    // Existing aur new active bookings ke selected tables ko Reserved show karwao.
    // Occupied / Bill Printed tables ko disturb nahi karega.
    if ($branchId > 0) {
        $stmt = $conn->prepare("
            UPDATE restaurant_tables rt
            INNER JOIN restaurant_reservations rr
                ON rr.table_id = rt.id
               AND rr.client_id = rt.client_id
            SET rt.status = 'Reserved', rt.updated_at = NOW()
            WHERE rr.client_id = ?
              AND (rr.branch_id = 0 OR rr.branch_id = ?)
              AND rr.table_id IS NOT NULL
              AND rr.status IN ('pending','confirmed','seated')
              AND (rr.reservation_date > CURDATE() OR (rr.reservation_date = CURDATE() AND rr.reservation_time >= CURTIME()))
              AND rt.is_active = 1
              AND rt.status NOT IN ('Occupied','Bill Printed','Out of Service')
        " );
        $stmt->bind_param('ii', $clientId, $branchId);
    } else {
        $stmt = $conn->prepare("
            UPDATE restaurant_tables rt
            INNER JOIN restaurant_reservations rr
                ON rr.table_id = rt.id
               AND rr.client_id = rt.client_id
            SET rt.status = 'Reserved', rt.updated_at = NOW()
            WHERE rr.client_id = ?
              AND rr.table_id IS NOT NULL
              AND rr.status IN ('pending','confirmed','seated')
              AND (rr.reservation_date > CURDATE() OR (rr.reservation_date = CURDATE() AND rr.reservation_time >= CURTIME()))
              AND rt.is_active = 1
              AND rt.status NOT IN ('Occupied','Bill Printed','Out of Service')
        " );
        $stmt->bind_param('i', $clientId);
    }

    $stmt->execute();
    $stmt->close();
}

/* ================= ENSURE BOOKING SCHEMA ================= */
try {
    if (!table_exists($conn, 'restaurant_reservations')) {
        $conn->query("CREATE TABLE restaurant_reservations (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            table_id INT(11) DEFAULT NULL,
            customer_id INT(11) DEFAULT NULL,
            customer_name VARCHAR(150) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            guest_count INT(11) NOT NULL DEFAULT 1,
            reservation_date DATE NOT NULL,
            reservation_time TIME NOT NULL,
            status ENUM('pending','confirmed','seated','cancelled','no_show','completed') NOT NULL DEFAULT 'pending',
            notes TEXT DEFAULT NULL,
            created_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rr_client_branch_date (client_id, branch_id, reservation_date),
            KEY idx_rr_table_date_time (table_id, reservation_date, reservation_time),
            KEY idx_rr_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    if (!column_exists($conn, 'restaurant_reservations', 'email')) {
        try { $conn->query("ALTER TABLE restaurant_reservations ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER phone"); } catch (Throwable $ignore) { }
    }
    if (!index_exists($conn, 'restaurant_reservations', 'idx_rr_client_branch_date')) {
        try { $conn->query('ALTER TABLE restaurant_reservations ADD INDEX idx_rr_client_branch_date (client_id, branch_id, reservation_date)'); } catch (Throwable $ignore) { }
    }
    if (!index_exists($conn, 'restaurant_reservations', 'idx_rr_table_date_time')) {
        try { $conn->query('ALTER TABLE restaurant_reservations ADD INDEX idx_rr_table_date_time (table_id, reservation_date, reservation_time)'); } catch (Throwable $ignore) { }
    }
    if (!index_exists($conn, 'restaurant_reservations', 'idx_rr_status')) {
        try { $conn->query('ALTER TABLE restaurant_reservations ADD INDEX idx_rr_status (status)'); } catch (Throwable $ignore) { }
    }
} catch (Throwable $schemaError) {
    $_SESSION['error'] = 'Booking schema check failed: ' . $schemaError->getMessage();
}

$hasEmailColumn = column_exists($conn, 'restaurant_reservations', 'email');

/* ================= BRANCH RESOLUTION ================= */
$branches = [];
try {
    if (table_exists($conn, 'branches')) {
        $stmt = $conn->prepare('SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC');
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

/* ================= FETCH TABLES FOR DROPDOWN ================= */
$restaurantTables = [];
try {
    if (table_exists($conn, 'restaurant_tables')) {
        if ($branch_id > 0) {
            $stmt = $conn->prepare("SELECT id, table_no, table_name, capacity, status, is_active, branch_id FROM restaurant_tables WHERE client_id = ? AND is_active = 1 AND (branch_id = 0 OR branch_id = ?) ORDER BY CAST(REPLACE(UPPER(table_no), 'T', '') AS UNSIGNED), table_no ASC");
            $stmt->bind_param('ii', $client_id, $branch_id);
        } else {
            $stmt = $conn->prepare("SELECT id, table_no, table_name, capacity, status, is_active, branch_id FROM restaurant_tables WHERE client_id = ? AND is_active = 1 ORDER BY CAST(REPLACE(UPPER(table_no), 'T', '') AS UNSIGNED), table_no ASC");
            $stmt->bind_param('i', $client_id);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $restaurantTables[(int)$row['id']] = $row;
        }
        $stmt->close();
    }
} catch (Throwable $tableFetchError) {
    $restaurantTables = [];
}

/* ================= POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_booking') {
            $customer_name = trim((string)($_POST['customer_name'] ?? ''));
            $phone         = trim((string)($_POST['phone'] ?? ''));
            $email         = trim((string)($_POST['email'] ?? ''));
            $guest_count   = max(1, (int)($_POST['guest_count'] ?? 1));
            $reservation_date = trim((string)($_POST['reservation_date'] ?? ''));
            $reservation_time = trim((string)($_POST['reservation_time'] ?? ''));
            $table_id      = (int)($_POST['table_id'] ?? 0);
            $notes         = trim((string)($_POST['notes'] ?? ''));

            if ($customer_name === '') throw new Exception('Customer name is required.');
            if ($phone === '') throw new Exception('Phone number is required.');
            if ($reservation_date === '') throw new Exception('Booking date is required.');
            if ($reservation_time === '') throw new Exception('Booking time is required.');

            $dateTs = strtotime($reservation_date);
            $timeTs = strtotime($reservation_time);
            if (!$dateTs) throw new Exception('Invalid booking date.');
            if (!$timeTs) throw new Exception('Invalid booking time.');

            $reservation_date = date('Y-m-d', $dateTs);
            $reservation_time = date('H:i:s', $timeTs);

            $bookingBranchId = $branch_id;
            if ($table_id > 0) {
                if (!isset($restaurantTables[$table_id])) {
                    throw new Exception('Selected table is not valid for this restaurant.');
                }
                $bookingBranchId = (int)($restaurantTables[$table_id]['branch_id'] ?? $branch_id);

                $checkTime = $reservation_time;
                $stmt = $conn->prepare("SELECT id FROM restaurant_reservations WHERE client_id = ? AND table_id = ? AND reservation_date = ? AND status IN ('pending','confirmed','seated') AND ABS(TIME_TO_SEC(TIMEDIFF(reservation_time, ?))) < 7200 LIMIT 1");
                $stmt->bind_param('iiss', $client_id, $table_id, $reservation_date, $checkTime);
                $stmt->execute();
                $duplicate = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($duplicate) {
                    throw new Exception('This table already has a booking near the selected time. Please choose another time/table.');
                }
            }

            $conn->begin_transaction();

            $status = 'confirmed';
            if ($hasEmailColumn) {
                $stmt = $conn->prepare("INSERT INTO restaurant_reservations (client_id, branch_id, table_id, customer_id, customer_name, phone, email, guest_count, reservation_date, reservation_time, status, notes, created_by, created_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $tableDb = $table_id > 0 ? $table_id : null;
                $stmt->bind_param('iiisssissssi', $client_id, $bookingBranchId, $tableDb, $customer_name, $phone, $email, $guest_count, $reservation_date, $reservation_time, $status, $notes, $user_id);
            } else {
                $notesDb = $notes;
                if ($email !== '') {
                    $notesDb = trim($notesDb . "\nEmail: " . $email);
                }
                $stmt = $conn->prepare("INSERT INTO restaurant_reservations (client_id, branch_id, table_id, customer_id, customer_name, phone, guest_count, reservation_date, reservation_time, status, notes, created_by, created_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $tableDb = $table_id > 0 ? $table_id : null;
                $stmt->bind_param('iiississssi', $client_id, $bookingBranchId, $tableDb, $customer_name, $phone, $guest_count, $reservation_date, $reservation_time, $status, $notesDb, $user_id);
            }
            $stmt->execute();
            $stmt->close();

            /* Booking create hote hi selected table Reserved show hoga.
               Occupied / Bill Printed / Out of Service table ko disturb nahi karega. */
            if ($table_id > 0 && table_exists($conn, 'restaurant_tables')) {
                $stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'Reserved', updated_at = NOW() WHERE id = ? AND client_id = ? AND is_active = 1 AND status NOT IN ('Occupied','Bill Printed','Out of Service')");
                $stmt->bind_param('ii', $table_id, $client_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $_SESSION['success'] = 'Booking created successfully.';
            redirect_self();
        }

        if ($action === 'complete_booking') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid booking id.');

            $stmt = $conn->prepare('SELECT id, table_id FROM restaurant_reservations WHERE id = ? AND client_id = ? LIMIT 1');
            $stmt->bind_param('ii', $id, $client_id);
            $stmt->execute();
            $booking = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$booking) throw new Exception('Booking not found.');

            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE restaurant_reservations SET status = 'completed', updated_at = NOW() WHERE id = ? AND client_id = ?");
            $stmt->bind_param('ii', $id, $client_id);
            $stmt->execute();
            $stmt->close();

            $tableId = (int)($booking['table_id'] ?? 0);
            if ($tableId > 0 && reservation_table_can_be_freed($conn, $client_id, $tableId) && table_exists($conn, 'restaurant_tables')) {
                $stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'Available', updated_at = NOW() WHERE id = ? AND client_id = ? AND status = 'Reserved'");
                $stmt->bind_param('ii', $tableId, $client_id);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();

            $_SESSION['success'] = 'Booking completed successfully.';
            redirect_self();
        }

        if ($action === 'delete_booking') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid booking id.');

            $stmt = $conn->prepare('SELECT id, table_id FROM restaurant_reservations WHERE id = ? AND client_id = ? LIMIT 1');
            $stmt->bind_param('ii', $id, $client_id);
            $stmt->execute();
            $booking = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$booking) throw new Exception('Booking not found.');

            $conn->begin_transaction();
            $stmt = $conn->prepare('DELETE FROM restaurant_reservations WHERE id = ? AND client_id = ?');
            $stmt->bind_param('ii', $id, $client_id);
            $stmt->execute();
            $stmt->close();

            $tableId = (int)($booking['table_id'] ?? 0);
            if ($tableId > 0 && reservation_table_can_be_freed($conn, $client_id, $tableId) && table_exists($conn, 'restaurant_tables')) {
                $stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'Available', updated_at = NOW() WHERE id = ? AND client_id = ? AND status = 'Reserved'");
                $stmt->bind_param('ii', $tableId, $client_id);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();

            $_SESSION['success'] = 'Booking deleted successfully.';
            redirect_self();
        }
    } catch (Throwable $postError) {
        try { $conn->rollback(); } catch (Throwable $ignore) { }
        $_SESSION['error'] = $postError->getMessage();
        redirect_self();
    }
}

/* ================= AUTO SYNC TABLE STATUS FROM ACTIVE BOOKINGS ================= */
try {
    sync_reserved_tables_for_active_bookings($conn, $client_id, $branch_id);
} catch (Throwable $syncError) {
    // Status sync fail ho bhi jaye to booking page ko block nahi karna.
}

/* ================= FILTERS + FETCH BOOKINGS ================= */
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFilter   = trim((string)($_GET['date'] ?? ''));
$validStatuses = ['pending','confirmed','seated','completed','cancelled','no_show'];
if ($statusFilter !== '' && !in_array($statusFilter, $validStatuses, true)) $statusFilter = '';
if ($dateFilter !== '' && !strtotime($dateFilter)) $dateFilter = '';
if ($dateFilter !== '') $dateFilter = date('Y-m-d', strtotime($dateFilter));

$stats = ['total' => 0, 'today' => 0, 'upcoming' => 0];
$bookings = [];

try {
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $statsWhere = 'client_id = ? AND status <> \'cancelled\'';
    $statsTypes = 'i';
    $statsParams = [$client_id];
    if ($branch_id > 0) {
        $statsWhere .= ' AND (branch_id = 0 OR branch_id = ?)';
        $statsTypes .= 'i';
        $statsParams[] = $branch_id;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM restaurant_reservations WHERE $statsWhere");
    $stmt->bind_param($statsTypes, ...$statsParams);
    $stmt->execute();
    $stats['total'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $tw = $statsWhere . ' AND reservation_date = ?';
    $tt = $statsTypes . 's';
    $tp = $statsParams;
    $tp[] = $today;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM restaurant_reservations WHERE $tw");
    $stmt->bind_param($tt, ...$tp);
    $stmt->execute();
    $stats['today'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $uw = $statsWhere . " AND status IN ('pending','confirmed','seated') AND (reservation_date > ? OR (reservation_date = ? AND reservation_time >= ?))";
    $ut = $statsTypes . 'sss';
    $up = $statsParams;
    $up[] = $today;
    $up[] = $today;
    $up[] = $nowTime;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM restaurant_reservations WHERE $uw");
    $stmt->bind_param($ut, ...$up);
    $stmt->execute();
    $stats['upcoming'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $where = ["rr.client_id = ?"];
    $types = 'i';
    $params = [$client_id];

    if ($branch_id > 0) {
        $where[] = '(rr.branch_id = 0 OR rr.branch_id = ?)';
        $types .= 'i';
        $params[] = $branch_id;
    }
    if ($statusFilter !== '') {
        $where[] = 'rr.status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    } else {
        $where[] = "rr.status IN ('pending','confirmed','seated')";
        $where[] = '(rr.reservation_date > ? OR (rr.reservation_date = ? AND rr.reservation_time >= ?))';
        $types .= 'sss';
        $params[] = $today;
        $params[] = $today;
        $params[] = $nowTime;
    }
    if ($dateFilter !== '') {
        $where[] = 'rr.reservation_date = ?';
        $types .= 's';
        $params[] = $dateFilter;
    }

    $whereSql = implode(' AND ', $where);
    $emailSelect = $hasEmailColumn ? 'rr.email,' : "NULL AS email,";

    $sql = "
        SELECT
            rr.*,
            $emailSelect
            rt.table_no,
            rt.table_name,
            rt.capacity AS table_capacity
        FROM restaurant_reservations rr
        LEFT JOIN restaurant_tables rt
            ON rt.id = rr.table_id
            AND rt.client_id = rr.client_id
        WHERE $whereSql
        ORDER BY rr.reservation_date ASC, rr.reservation_time ASC, rr.id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
} catch (Throwable $fetchError) {
    $_SESSION['error'] = 'Bookings fetch error: ' . $fetchError->getMessage();
}

if (isset($_GET['debug_bookings'])) {
    echo '<pre style="background:#06101f;color:#b6ffcb;padding:18px;font-size:13px;white-space:pre-wrap;">';
    echo "DATABASE: " . e($conn->query('SELECT DATABASE()')->fetch_row()[0]) . "\n";
    echo "USER ID: " . $user_id . "\n";
    echo "CLIENT ID: " . $client_id . "\n";
    echo "BRANCH ID: " . $branch_id . "\n";
    echo "EMAIL COLUMN: " . ($hasEmailColumn ? 'yes' : 'no') . "\n\n";
    $stmt = $conn->prepare('SELECT * FROM restaurant_reservations WHERE client_id = ? ORDER BY id DESC LIMIT 50');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) print_r($r);
    $stmt->close();
    echo '</pre>';
    exit;
}

$todayInput = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Table Bookings - One ERP</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
html,body.restaurant-bookings-page{
    margin:0!important;padding:0!important;min-height:100%!important;overflow-x:hidden!important;
    background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.34),transparent 28%),radial-gradient(circle at 78% 2%,rgba(59,130,246,.16),transparent 30%),linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%)!important;
    color:#f8fafc!important;font-family:Inter,"Segoe UI",Arial,sans-serif!important;
}
.restaurant-bookings-page{--sidebar-width:270px;--topbar-height:70px;--purple:#7c3aed;--muted:#94a3b8;}
.container-scroll{display:flex!important;min-height:100vh!important;width:100%!important;}
.container-scroll>.sidebar,#dashboardSidebar{position:fixed!important;top:0!important;left:0!important;width:var(--sidebar-width)!important;height:100vh!important;z-index:1040!important;}
.main-panel{margin-left:var(--sidebar-width)!important;width:calc(100% - var(--sidebar-width))!important;min-height:100vh!important;padding-top:var(--topbar-height)!important;background:transparent!important;transition:.25s ease!important;}
.navbar.fixed-top,.my-navbar.fixed-top{left:var(--sidebar-width)!important;width:calc(100% - var(--sidebar-width))!important;z-index:1030!important;}
body.sidebar-collapsed .main-panel{margin-left:0!important;width:100%!important;}
body.sidebar-collapsed .navbar.fixed-top,body.sidebar-collapsed .my-navbar.fixed-top{left:0!important;width:100%!important;}
.content-wrapper{position:relative!important;min-height:calc(100vh - var(--topbar-height))!important;padding:26px 18px 70px!important;background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.26),transparent 28%),radial-gradient(circle at 78% 2%,rgba(59,130,246,.13),transparent 30%),radial-gradient(circle at 92% 86%,rgba(34,197,94,.07),transparent 26%),linear-gradient(135deg,#050914 0%,#0b1024 40%,#160f2d 70%,#090d18 100%)!important;overflow:visible!important;}
.content-wrapper:before{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:44px 44px;opacity:.18;z-index:0;}
.content-wrapper>*{position:relative;z-index:1;}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;flex-wrap:wrap;margin-bottom:18px;}
.page-title{font-size:22px;font-weight:950;letter-spacing:-.04em;color:#fff;text-shadow:0 0 28px rgba(124,58,237,.45);margin:0 0 4px;}
.page-subtitle{color:#aab4cf;font-weight:750;font-size:13px;}
.header-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.form-control,.form-select,.booking-filter{min-height:42px!important;border-radius:13px!important;background:rgba(15,23,42,.82)!important;color:#e5e7eb!important;border:1px solid rgba(148,163,184,.20)!important;font-weight:850!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.04)!important;}
.form-control::placeholder{color:#94a3b8!important;}
.form-control:focus,.form-select:focus,.booking-filter:focus{background:rgba(17,24,39,.96)!important;color:#fff!important;border-color:rgba(124,58,237,.62)!important;box-shadow:0 0 0 3px rgba(124,58,237,.16)!important;}
select option{background:#0f172a;color:#e5e7eb;}
.action-btn{min-height:42px;border:none;border-radius:13px;padding:0 18px;font-weight:950;color:#fff;display:inline-flex;gap:8px;align-items:center;justify-content:center;text-decoration:none;transition:.22s ease;white-space:nowrap;}
.action-btn:hover{transform:translateY(-2px);color:#fff;}
.btn-primary-dark{background:linear-gradient(135deg,#08071a,#17102d);box-shadow:0 14px 30px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.08);border:1px solid rgba(139,92,246,.25);}
.btn-red{background:linear-gradient(135deg,#dc2626,#ef4444);}
.alert{border-radius:14px;border:none;font-weight:850;margin-bottom:14px;}
.alert-success{background:rgba(34,197,94,.14);color:#bbf7d0;}.alert-danger{background:rgba(239,68,68,.14);color:#fecaca;}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-bottom:18px;}
.stat-card{min-height:90px;border-radius:18px;background:linear-gradient(135deg,rgba(15,23,42,.72),rgba(30,20,58,.42));border:1px solid rgba(148,163,184,.16);box-shadow:0 14px 34px rgba(0,0,0,.20),inset 0 1px 0 rgba(255,255,255,.05);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;color:#e5e7eb;}
.stat-title{font-size:12px;color:#cbd5e1;font-weight:800;}.stat-count{font-size:20px;color:#fff;font-weight:950;line-height:1;}
.glass-card{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.16),transparent 35%),linear-gradient(135deg,rgba(15,23,42,.76),rgba(17,24,39,.56))!important;border:1px solid rgba(148,163,184,.18)!important;border-radius:18px!important;color:#f8fafc!important;box-shadow:0 18px 48px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05)!important;backdrop-filter:blur(18px) saturate(135%)!important;}
.list-title{font-size:15px;font-weight:950;color:#fff;margin:0 0 16px;}
.booking-card{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center;border:1px solid rgba(148,163,184,.18);border-radius:14px;background:rgba(15,23,42,.54);padding:15px 14px;margin-bottom:12px;}
.booking-card:last-child{margin-bottom:0;}
.booking-main{display:grid;grid-template-columns:1.2fr 1fr;gap:10px 24px;align-items:center;}
.customer-name{font-weight:950;color:#fff;font-size:14px;display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;}
.booking-line{color:#cbd5e1;font-size:12px;font-weight:800;margin-top:7px;display:flex;gap:6px;align-items:center;}
.booking-note{font-style:italic;color:#aab4cf;margin-top:9px;font-size:12px;font-weight:750;}
.booking-actions{display:flex;align-items:center;gap:7px;}
.small-btn{min-height:34px;border-radius:10px;border:1px solid rgba(148,163,184,.20);background:rgba(15,23,42,.75);color:#fff;font-weight:900;font-size:12px;padding:0 13px;transition:.2s ease;}
.small-btn:hover{transform:translateY(-1px);}
.trash-btn{width:35px;height:35px;border:none;border-radius:10px;background:linear-gradient(135deg,#dc2626,#e11d48);color:#fff;display:inline-flex;align-items:center;justify-content:center;}
.status-badge{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:950;text-transform:lowercase;border:1px solid rgba(255,255,255,.14);}
.badge-confirmed{background:rgba(124,58,237,.22);color:#ddd6fe;border-color:rgba(124,58,237,.32);}.badge-pending{background:rgba(245,158,11,.18);color:#fde68a;border-color:rgba(245,158,11,.32);}.badge-seated{background:rgba(59,130,246,.18);color:#bfdbfe;border-color:rgba(59,130,246,.32);}.badge-completed{background:rgba(34,197,94,.16);color:#bbf7d0;border-color:rgba(34,197,94,.30);}.badge-cancelled,.badge-noshow{background:rgba(239,68,68,.16);color:#fecaca;border-color:rgba(239,68,68,.30);}
.empty-state{border:1px dashed rgba(148,163,184,.28);border-radius:18px;padding:35px;text-align:center;color:#cbd5e1;background:rgba(15,23,42,.44);font-weight:850;}
.modal{z-index:5000!important;}.modal-backdrop{z-index:4990!important;}.modal-backdrop.show{opacity:.76!important;background:#000!important;backdrop-filter:blur(8px);}
.modal-dialog{max-width:560px;}.modal-content{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.28),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.98),rgba(17,24,39,.92))!important;color:#f8fafc!important;border:1px solid rgba(139,92,246,.36)!important;border-radius:18px!important;box-shadow:0 28px 80px rgba(0,0,0,.62),0 0 52px rgba(124,58,237,.18)!important;overflow:hidden;}
.modal-header{border:none;padding:18px 20px 9px;background:rgba(7,11,20,.20);}.modal-title{font-size:16px;font-weight:950;color:#fff;}.btn-close{filter:invert(1) grayscale(1);opacity:1;box-shadow:none!important;}.modal-body{padding:10px 20px 18px;}.form-label{color:#dbe4ff;font-size:12px;font-weight:900;margin-bottom:5px;}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px;}.btn-modal{min-height:39px;border-radius:10px;border:1px solid rgba(148,163,184,.20);font-weight:950;font-size:12px;padding:0 16px;}.btn-cancel{background:rgba(15,23,42,.76);color:#fff;}.btn-submit{background:linear-gradient(135deg,#08071a,#17102d);color:#fff;border-color:rgba(139,92,246,.28);}
@media(max-width:991px){.main-panel{margin-left:0!important;width:100%!important;}.navbar.fixed-top,.my-navbar.fixed-top{left:0!important;width:100%!important;}.content-wrapper{padding:18px 12px 60px!important;}.stats-grid{grid-template-columns:1fr;}.booking-card{grid-template-columns:1fr;}.booking-main{grid-template-columns:1fr;}.booking-actions{justify-content:flex-start;}}
@media(max-width:576px){.page-header{display:block;}.header-actions{margin-top:12px;display:grid;grid-template-columns:1fr;}.booking-filter,.action-btn{width:100%;}.modal-actions{display:grid;grid-template-columns:1fr;}.btn-modal{width:100%;}}

/* ================= BOOKING PAGE PREMIUM UI FIX ================= */

.page-header{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:20px !important;
    margin-bottom:22px !important;
}

.header-actions{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:12px !important;
    flex-wrap:nowrap !important;
}

.header-actions form{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

.booking-filter{
    min-width:180px !important;
    height:44px !important;
}

.action-btn{
    height:44px !important;
    min-width:150px !important;
    padding:0 20px !important;
    flex-shrink:0 !important;
}

/* ===== Stats Cards ===== */

.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(3,1fr) !important;
    gap:18px !important;
    margin-bottom:22px !important;
}

.stat-card{
    position:relative;
    overflow:hidden;
    min-height:110px !important;
    border-radius:20px !important;

    background:
        radial-gradient(circle at top left,
        rgba(124,58,237,.35),
        transparent 40%),

        linear-gradient(
        135deg,
        rgba(17,24,39,.95),
        rgba(30,41,59,.85)
        ) !important;

    border:1px solid rgba(139,92,246,.25) !important;

    box-shadow:
        0 15px 35px rgba(0,0,0,.35),
        0 0 20px rgba(124,58,237,.10),
        inset 0 1px 0 rgba(255,255,255,.06) !important;

    transition:.25s ease;
}

.stat-card:hover{
    transform:translateY(-3px);
    box-shadow:
        0 22px 40px rgba(0,0,0,.45),
        0 0 30px rgba(124,58,237,.25);
}

.stat-card::before{
    content:"";
    position:absolute;
    top:0;
    left:0;
    right:0;
    height:3px;

    background:linear-gradient(
        90deg,
        #7c3aed,
        #a855f7,
        #3b82f6
    );
}

.stat-title{
    font-size:13px !important;
    color:#cbd5e1 !important;
    font-weight:800 !important;
}

.stat-count{
    font-size:32px !important;
    font-weight:900 !important;
    color:#ffffff !important;
    text-shadow:0 0 20px rgba(124,58,237,.35);
}

/* ===== Booking Container ===== */

.glass-card{
    padding:22px !important;
}

.list-title{
    font-size:18px !important;
    margin-bottom:18px !important;
}

/* ===== Responsive ===== */

@media(max-width:991px){

    .page-header{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .header-actions{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .header-actions form{
        width:100% !important;
    }

    .booking-filter{
        flex:1 !important;
        min-width:unset !important;
    }

    .action-btn{
        width:100% !important;
    }

    .stats-grid{
        grid-template-columns:1fr !important;
    }
}

.header-actions{
    display:flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:10px!important;
}

.header-actions form{
    display:flex!important;
    align-items:center!important;
    gap:10px!important;
    flex-wrap:nowrap!important;
}

.header-actions .booking-filter{
    width:150px!important;
    min-width:150px!important;
    height:38px!important;
    min-height:38px!important;
    padding:0 12px!important;
}

.header-actions .action-btn{
    height:38px!important;
    min-height:38px!important;
    min-width:140px!important;
    padding:0 16px!important;
    border-radius:11px!important;
}

@media(max-width:768px){
    .header-actions,
    .header-actions form{
        width:100%!important;
        flex-direction:column!important;
        align-items:stretch!important;
    }

    .header-actions .booking-filter,
    .header-actions .action-btn{
        width:100%!important;
        min-width:100%!important;
    }
}

</style>
</head>
<body class="restaurant-bookings-page">
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?>

        <div class="content-wrapper">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Table Bookings</h1>
                    <div class="page-subtitle">Manage reservations and bookings</div>
                </div>
                <div class="header-actions">
                    <form method="get" class="d-flex gap-2 flex-wrap m-0">
                        <select name="status" class="booking-filter" onchange="this.form.submit();">
                            <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>Upcoming Bookings</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="seated" <?= $statusFilter === 'seated' ? 'selected' : '' ?>>Seated</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                      <input type="text" name="date" value="<?= e($dateFilter) ?>" class="booking-filter booking-date-picker" placeholder="dd/mm/yyyy">
                    </form>
                    <button type="button" class="action-btn btn-primary-dark" data-bs-toggle="modal" data-bs-target="#bookingModal">
                        <i class="bi bi-plus-lg"></i> New Booking
                    </button>
                </div>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= e($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= e($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-title">Total Bookings</div><div class="stat-count"><?= (int)$stats['total'] ?></div></div>
                <div class="stat-card"><div class="stat-title">Today's Bookings</div><div class="stat-count"><?= (int)$stats['today'] ?></div></div>
                <div class="stat-card"><div class="stat-title">Upcoming</div><div class="stat-count"><?= (int)$stats['upcoming'] ?></div></div>
            </div>

            <div class="glass-card p-3 p-md-4">
                <h3 class="list-title"><?= $statusFilter === '' ? 'Upcoming Bookings' : e(ucwords(str_replace('_',' ', $statusFilter))) . ' Bookings' ?></h3>

                <?php if (empty($bookings)): ?>
                    <div class="empty-state">No booking found. Click New Booking to create one.</div>
                <?php endif; ?>

                <?php foreach ($bookings as $row): ?>
                    <?php
                        $bookingId = (int)$row['id'];
                        $status = normalize_booking_status($row['status'] ?? 'confirmed');
                        $tableText = '-';
                        if (!empty($row['table_id'])) {
                            $tableText = 'Table: ' . display_table_short($row['table_no'] ?? $row['table_id']);
                        }
                    ?>
                    <div class="booking-card">
                        <div class="booking-main">
                            <div>
                                <div class="customer-name">
                                    <?= e($row['customer_name'] ?: 'Guest') ?>
                                    <span class="status-badge <?= e(booking_badge_class($status)) ?>"><?= e($status) ?></span>
                                </div>
                                <div class="booking-line"><i class="bi bi-calendar-event"></i> <?= e(pretty_date($row['reservation_date'] ?? '')) ?></div>
                                <div class="booking-line"><i class="bi bi-people"></i> <?= (int)($row['guest_count'] ?? 1) ?> guests</div>
                                <div class="booking-line"><?= e($tableText) ?></div>
                                <?php if (!empty($row['notes'])): ?>
                                    <div class="booking-note"><?= e($row['notes']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="booking-line"><i class="bi bi-clock"></i> <?= e(pretty_time($row['reservation_time'] ?? '')) ?></div>
                                <div class="booking-line"><i class="bi bi-telephone"></i> <?= e($row['phone'] ?: '-') ?></div>
                                <?php if (!empty($row['email'])): ?>
                                    <div class="booking-line"><i class="bi bi-envelope"></i> <?= e($row['email']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="booking-actions">
                            <?php if (!in_array($status, ['completed','cancelled','no_show'], true)): ?>
                                <form method="post" class="m-0" onsubmit="return confirm('Complete this booking?');">
                                    <input type="hidden" name="action" value="complete_booking">
                                    <input type="hidden" name="id" value="<?= (int)$bookingId ?>">
                                    <button type="submit" class="small-btn">Complete</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" class="m-0" onsubmit="return confirm('Delete this booking?');">
                                <input type="hidden" name="action" value="delete_booking">
                                <input type="hidden" name="id" value="<?= (int)$bookingId ?>">
                                <button type="submit" class="trash-btn" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- CREATE BOOKING MODAL -->
<div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create_booking">
            <div class="modal-header">
                <h5 class="modal-title">Create New Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" name="customer_name" class="form-control" placeholder="Enter customer name" required autofocus>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone Number *</label>
                        <input type="text" name="phone" class="form-control" placeholder="Enter phone number" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="Enter email address">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Number of Guests *</label>
                        <input type="number" name="guest_count" class="form-control" min="1" value="2" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Date *</label>
                        <input type="date" name="reservation_date" class="form-control" value="<?= e($todayInput) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Time *</label>
                        <input type="time" name="reservation_time" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Preferred Table (Optional)</label>
                        <select name="table_id" class="form-select">
                            <option value="0">Select table</option>
                            <?php foreach ($restaurantTables as $tid => $tbl): ?>
                                <?php
                                    $label = 'Table ' . display_table_short($tbl['table_no'] ?? $tid) . ' - ' . (int)($tbl['capacity'] ?? 0) . ' guests';
                                    if (!empty($tbl['status'])) $label .= ' (' . $tbl['status'] . ')';
                                ?>
                                <option value="<?= (int)$tid ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Special Requests</label>
                        <input type="text" name="notes" class="form-control" placeholder="Any special requirements or notes">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-modal btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal btn-submit">Create Booking</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
flatpickr(".booking-date-picker", {
    dateFormat: "Y-m-d",
    altInput: true,
    altFormat: "d/m/Y",
    allowInput: true,
    onChange: function(){
        this.input.form.submit();
    }
});
</script>
</body>
</html>
