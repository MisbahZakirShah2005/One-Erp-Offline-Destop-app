<?php
session_start();

// Restaurant orders page must always show fresh DB data.
// This prevents old completed/deleted orders from appearing when tabs are changed or browser back/forward cache is used.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');


if (file_exists(__DIR__ . '/partials/performance.php')) {
    include(__DIR__ . '/partials/performance.php');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

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
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money_fmt($amount): string { return 'Rs. ' . number_format((float)$amount, 2); }

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
    if (!table_exists($conn, $table)) return false;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}

function bind_params_ref(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $refs = [$types];
    foreach ($params as $k => &$v) $refs[] = &$v;
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fetch_all_prepared(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') bind_params_ref($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function fetch_one_prepared(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = fetch_all_prepared($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function log_restaurant_activity(mysqli $conn, int $client_id, int $branch_id, int $session_id, ?int $table_id, string $type, string $details, int $user_id): void
{
    if (!table_exists($conn, 'restaurant_activity_logs')) return;
    $stmt = $conn->prepare("INSERT INTO restaurant_activity_logs (client_id, branch_id, session_id, table_id, action_type, details, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param('iiiissi', $client_id, $branch_id, $session_id, $table_id, $type, $details, $user_id);
    $stmt->execute();
    $stmt->close();
}

function status_label(string $status): string
{
$map = [
    'open' => 'Open',
    'kot_sent' => 'KOT Sent',
    'bill_printed' => 'Bill Printed',
    'kitchen_completed' => 'Kitchen Completed',
    'paid' => 'Paid',
    'cancelled' => 'Cancelled',
    'void' => 'Void',
];
return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function status_badge_class(string $status): string
{
    $s = strtolower(trim($status));
    if ($s === 'open') return 'badge-open';
    if ($s === 'kot_sent') return 'badge-kot';
if ($s === 'bill_printed') return 'badge-bill';
if ($s === 'kitchen_completed') return 'badge-paid';
if ($s === 'paid') return 'badge-paid';
    if ($s === 'cancelled' || $s === 'void') return 'badge-cancel';
    return 'badge-muted';
}

function kitchen_status_label(?string $status): string
{
    $s = strtolower(trim((string)$status));
    if ($s === '') return 'No KOT';
    if ($s === 'served') return 'Completed';
    return ucwords(str_replace('_', ' ', $s));
}

function kitchen_status_class(?string $status): string
{
    $s = strtolower(trim((string)$status));
    if ($s === 'pending') return 'kot-pending';
    if ($s === 'preparing') return 'kot-preparing';
    if ($s === 'ready') return 'kot-ready';
    if ($s === 'served') return 'kot-completed';
    if ($s === 'cancelled') return 'kot-cancelled';
    return 'kot-muted';
}

function order_type_label(string $type): string
{
    $map = ['dine_in'=>'Dine In','takeaway'=>'Takeaway','delivery'=>'Delivery'];
    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function order_type_badge_class(string $type): string
{
    $type = strtolower(trim($type));
    if ($type === 'dine_in') return 'type-dine';
    if ($type === 'takeaway') return 'type-takeaway';
    if ($type === 'delivery') return 'type-delivery';
    return 'type-muted';
}

function delivery_status_label(?string $status): string
{
    $status = strtolower(trim((string)$status));
    if ($status === '') return 'N/A';
    return ucwords(str_replace('_', ' ', $status));
}

function delivery_status_badge_class(?string $status): string
{
    $s = strtolower(trim((string)$status));
    if ($s === 'pending') return 'del-pending';
    if ($s === 'preparing') return 'del-preparing';
    if ($s === 'ready') return 'del-ready';
    if ($s === 'dispatched') return 'del-dispatched';
    if ($s === 'delivered') return 'del-delivered';
    if ($s === 'cancelled') return 'del-cancelled';
    return 'del-muted';
}

function order_reference_label(array $o): string
{
    $type = (string)($o['order_type'] ?? 'dine_in');
    if ($type === 'dine_in') {
        $label = trim((string)($o['table_no'] ?? ''));
        $label = $label === '' ? 'Table N/A' : 'Table ' . $label;
        if (!empty($o['table_name'])) $label .= ' - ' . $o['table_name'];
        return $label;
    }
    $token = trim((string)($o['token_no'] ?? ''));
    if ($type === 'takeaway') return 'Token ' . ($token !== '' ? $token : ('T-' . ($o['id'] ?? '')));
    if ($type === 'delivery') return 'Token ' . ($token !== '' ? $token : ('D-' . ($o['id'] ?? '')));
    return 'Order #' . ($o['id'] ?? '');
}

function order_customer_name(array $o): string
{
    $name = trim((string)($o['customer_name'] ?? ''));
    if ($name === '') $name = trim((string)($o['customer_db_name'] ?? ''));
    return $name !== '' ? $name : 'Walk-in';
}

function order_customer_phone(array $o): string
{
    $phone = trim((string)($o['customer_phone'] ?? ''));
    if ($phone === '') $phone = trim((string)($o['customer_db_phone'] ?? ''));
    return $phone;
}

function url_with(array $replace): string
{
    $params = $_GET;

    // Old browser/CDN cache ko avoid karne ke liye stale cache keys remove kar ke fresh key add karte hain.
    unset($params['_live'], $params['_t'], $params['_ts']);

    foreach ($replace as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = $v;
    }

    $params['_live'] = (string)time();
    return 'restaurant_orders.php' . ($params ? '?' . http_build_query($params) : '');
}

/* ================= SCHEMA ================= */
$missingTables = [];
foreach (['restaurant_table_sessions', 'restaurant_order_items', 'restaurant_kots', 'restaurant_kot_items'] as $t) {
    if (!table_exists($conn, $t)) $missingTables[] = $t;
}
$hasTables         = table_exists($conn, 'restaurant_tables');
$hasWaiters        = table_exists($conn, 'restaurant_waiters');
$hasBranches       = table_exists($conn, 'branches');
$hasCustomers      = table_exists($conn, 'customers');
$hasUsers          = table_exists($conn, 'users');
$hasSessionTables  = table_exists($conn, 'restaurant_session_tables');
$hasDeliveryStatus = empty($missingTables) && column_exists($conn, 'restaurant_table_sessions', 'delivery_status');
$hasRiderId        = empty($missingTables) && column_exists($conn, 'restaurant_table_sessions', 'rider_id');
$hasTokenNo        = empty($missingTables) && column_exists($conn, 'restaurant_table_sessions', 'token_no');
$hasCustomerName   = empty($missingTables) && column_exists($conn, 'restaurant_table_sessions', 'customer_name');
$hasCustomerPhone  = empty($missingTables) && column_exists($conn, 'restaurant_table_sessions', 'customer_phone');
$hasDeliveryCharge = empty($missingTables) && column_exists($conn, 'restaurant_table_sessions', 'delivery_charge');
$hasDeliveryAddr   = empty($missingTables) && column_exists($conn, 'restaurant_table_sessions', 'delivery_address');

/* ================= CANCEL SESSION ACTION ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_session'])) {
    try {
        if (!empty($missingTables)) throw new Exception('Restaurant tables missing. Please import restaurant migration SQL first.');

        $session_id = (int)($_POST['session_id'] ?? 0);
        $reason = trim((string)($_POST['cancel_reason'] ?? ''));
        if ($session_id <= 0) throw new Exception('Invalid session id.');
        if ($reason === '') throw new Exception('Cancel reason required.');

        $stmt = $conn->prepare("SELECT * FROM restaurant_table_sessions WHERE id=? AND client_id=? LIMIT 1");
        $stmt->bind_param('ii', $session_id, $client_id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$session) throw new Exception('Restaurant session not found.');
        if (in_array((string)$session['status'], ['paid','cancelled','void'], true)) throw new Exception('This session is already closed.');

        $conn->begin_transaction();
        $note = 'Cancelled: ' . $reason;
        if ($hasDeliveryStatus) {
            $stmt = $conn->prepare("UPDATE restaurant_table_sessions SET status='cancelled', delivery_status=IF(order_type='delivery','cancelled',delivery_status), notes=CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='', '', '\n'), ?), closed_by=?, closed_at=NOW() WHERE id=? AND client_id=?");
        } else {
            $stmt = $conn->prepare("UPDATE restaurant_table_sessions SET status='cancelled', notes=CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='', '', '\n'), ?), closed_by=?, closed_at=NOW() WHERE id=? AND client_id=?");
        }
        $stmt->bind_param('siii', $note, $user_id, $session_id, $client_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE restaurant_order_items SET item_status='cancelled', cancelled_reason=IF(cancelled_reason IS NULL OR cancelled_reason='', ?, cancelled_reason), cancelled_by=? WHERE session_id=? AND client_id=? AND item_status NOT IN ('cancelled','void')");
        $stmt->bind_param('siii', $reason, $user_id, $session_id, $client_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE restaurant_kots SET status='cancelled' WHERE session_id=? AND client_id=? AND status NOT IN ('served','cancelled')");
        $stmt->bind_param('ii', $session_id, $client_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE restaurant_kot_items ki INNER JOIN restaurant_kots k ON k.id=ki.kot_id SET ki.status='cancelled' WHERE k.session_id=? AND k.client_id=? AND ki.status NOT IN ('served','cancelled')");
        $stmt->bind_param('ii', $session_id, $client_id);
        $stmt->execute();
        $stmt->close();

        $tableId = !empty($session['table_id']) ? (int)$session['table_id'] : 0;
        if ($tableId > 0 && $hasTables) {
            if ($hasSessionTables) {
                if (column_exists($conn, 'restaurant_tables', 'current_session_id')) {
                    $stmt = $conn->prepare("UPDATE restaurant_tables t INNER JOIN restaurant_session_tables st ON st.table_id=t.id SET t.status='Available', t.current_session_id=NULL WHERE st.session_id=? AND st.client_id=?");
                } else {
                    $stmt = $conn->prepare("UPDATE restaurant_tables t INNER JOIN restaurant_session_tables st ON st.table_id=t.id SET t.status='Available' WHERE st.session_id=? AND st.client_id=?");
                }
                $stmt->bind_param('ii', $session_id, $client_id);
                $stmt->execute();
                $stmt->close();
            } else {
                if (column_exists($conn, 'restaurant_tables', 'current_session_id')) {
                    $stmt = $conn->prepare("UPDATE restaurant_tables SET status='Available', current_session_id=NULL WHERE id=? AND client_id=?");
                } else {
                    $stmt = $conn->prepare("UPDATE restaurant_tables SET status='Available' WHERE id=? AND client_id=?");
                }
                $stmt->bind_param('ii', $tableId, $client_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        log_restaurant_activity($conn, $client_id, (int)($session['branch_id'] ?? 0), $session_id, $tableId ?: null, 'order_management_cancelled', $reason, $user_id);
        $conn->commit();
        $_SESSION['success'] = 'Restaurant order cancelled successfully.';
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $rollbackError) {}
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: ' . url_with([]));
    exit;
}

/* ================= FILTERS ================= */
$today = date('Y-m-d');
$date_from = trim((string)($_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'))));
$date_to   = trim((string)($_GET['date_to'] ?? $today));
$tab       = trim((string)($_GET['tab'] ?? 'active'));
$order_type_filter = trim((string)($_GET['order_type'] ?? ''));
$kitchen_status_filter = trim((string)($_GET['kitchen_status'] ?? ''));
$delivery_status_filter = trim((string)($_GET['delivery_status'] ?? ''));
$branch_filter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$waiter_filter = isset($_GET['waiter_id']) ? (int)$_GET['waiter_id'] : 0;
$rider_filter  = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : 0;
$table_filter  = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$q = trim((string)($_GET['q'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = $today;
if (!in_array($tab, ['active','completed','all'], true)) $tab = 'active';
if (!in_array($order_type_filter, ['', 'dine_in', 'takeaway', 'delivery'], true)) $order_type_filter = '';
if (!in_array($kitchen_status_filter, ['', 'pending','preparing','ready','served','cancelled'], true)) $kitchen_status_filter = '';
if (!in_array($delivery_status_filter, ['', 'pending','preparing','ready','dispatched','delivered','cancelled'], true)) $delivery_status_filter = '';

/* ================= FILTER DATA ================= */
$branches = [];
$waiters = [];
$riders = [];
$restaurantTables = [];
$orders = [];
$counts = ['active_count'=>0,'completed_count'=>0,'all_count'=>0];
$stats = ['total_orders'=>0,'today_orders'=>0,'active_orders'=>0,'today_revenue'=>0.0];

if (empty($missingTables)) {
    if ($hasBranches) $branches = fetch_all_prepared($conn, "SELECT id, name FROM branches WHERE client_id=? ORDER BY name ASC", 'i', [$client_id]);
    if ($hasWaiters) $waiters = fetch_all_prepared($conn, "SELECT id, name FROM restaurant_waiters WHERE client_id=? ORDER BY name ASC", 'i', [$client_id]);
    if ($hasUsers) {
        $userStatusSql = column_exists($conn, 'users', 'status') ? " AND LOWER(status)='active'" : '';
        $riders = fetch_all_prepared($conn, "SELECT id, name FROM users WHERE client_id=? $userStatusSql ORDER BY name ASC", 'i', [$client_id]);
    }
    if ($hasTables) {
        $restaurantTables = fetch_all_prepared($conn, "SELECT id, table_no, table_name, floor_name FROM restaurant_tables WHERE client_id=? ORDER BY floor_name ASC, sort_order ASC, table_no ASC", 'i', [$client_id]);
    }

    $where = ["s.client_id=?", "DATE(s.started_at) BETWEEN ? AND ?"];
    $types = 'iss';
    $params = [$client_id, $date_from, $date_to];

    if ($order_type_filter !== '') { $where[] = "s.order_type=?"; $types .= 's'; $params[] = $order_type_filter; }
    if ($kitchen_status_filter !== '') { $where[] = "IFNULL(k.latest_kot_status,'')=?"; $types .= 's'; $params[] = $kitchen_status_filter; }
    if ($delivery_status_filter !== '' && $hasDeliveryStatus) { $where[] = "s.delivery_status=?"; $types .= 's'; $params[] = $delivery_status_filter; }
    if ($branch_filter > 0) { $where[] = "s.branch_id=?"; $types .= 'i'; $params[] = $branch_filter; }
    if ($waiter_filter > 0) { $where[] = "s.waiter_id=?"; $types .= 'i'; $params[] = $waiter_filter; }
    if ($rider_filter > 0 && $hasRiderId) { $where[] = "s.rider_id=?"; $types .= 'i'; $params[] = $rider_filter; }
    if ($table_filter > 0) { $where[] = "s.table_id=?"; $types .= 'i'; $params[] = $table_filter; }
    if ($q !== '') {
        $searchParts = ["CAST(s.id AS CHAR) LIKE ?"];
        if ($hasTables) { $searchParts[] = "IFNULL(t.table_no,'') LIKE ?"; $searchParts[] = "IFNULL(t.table_name,'') LIKE ?"; }
        if ($hasWaiters) $searchParts[] = "IFNULL(w.name,'') LIKE ?";
        if ($hasCustomers) $searchParts[] = "IFNULL(c.name,'') LIKE ?";
        if ($hasTokenNo) $searchParts[] = "IFNULL(s.token_no,'') LIKE ?";
        if ($hasCustomerName) $searchParts[] = "IFNULL(s.customer_name,'') LIKE ?";
        if ($hasCustomerPhone) $searchParts[] = "IFNULL(s.customer_phone,'') LIKE ?";
        if ($hasDeliveryAddr) $searchParts[] = "IFNULL(s.delivery_address,'') LIKE ?";
        if ($hasRiderId && $hasUsers) $searchParts[] = "IFNULL(ru.name,'') LIKE ?";
        if (table_exists($conn, 'restaurant_kots')) $searchParts[] = "IFNULL(k.latest_kot_no,'') LIKE ?";
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        $like = '%' . $q . '%';
        $types .= str_repeat('s', count($searchParts));
        for ($i=0; $i<count($searchParts); $i++) $params[] = $like;
    }

    $tableJoin = $hasTables ? "LEFT JOIN restaurant_tables t ON t.id=s.table_id" : "";
    $tableSelect = $hasTables ? "t.table_no, t.table_name, t.floor_name" : "NULL AS table_no, NULL AS table_name, NULL AS floor_name";
    $branchesJoin = $hasBranches ? "LEFT JOIN branches b ON b.id=s.branch_id" : "";
    $branchSelect = $hasBranches ? "b.name AS branch_name" : "NULL AS branch_name";
    $waiterJoin = $hasWaiters ? "LEFT JOIN restaurant_waiters w ON w.id=s.waiter_id" : "";
    $waiterSelect = $hasWaiters ? "w.name AS waiter_name" : "NULL AS waiter_name";
    $customerJoin = $hasCustomers ? "LEFT JOIN customers c ON c.id=s.customer_id" : "";
    $customerSelect = $hasCustomers ? "c.name AS customer_db_name, c.phone AS customer_db_phone" : "NULL AS customer_db_name, NULL AS customer_db_phone";
    $riderJoin = ($hasRiderId && $hasUsers) ? "LEFT JOIN users ru ON ru.id=s.rider_id" : "";
    $riderSelect = ($hasRiderId && $hasUsers) ? "ru.name AS rider_name" : "NULL AS rider_name";

    $kotAgg = "LEFT JOIN (
        SELECT
            session_id,
            COUNT(*) AS kot_count,
            CAST(SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY id DESC), ',', 1) AS UNSIGNED) AS latest_kot_id,
            SUBSTRING_INDEX(GROUP_CONCAT(kot_no ORDER BY id DESC), ',', 1) AS latest_kot_no,
            SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY id DESC), ',', 1) AS latest_kot_status,
            MAX(created_at) AS latest_kot_at,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_kots,
            SUM(CASE WHEN status='preparing' THEN 1 ELSE 0 END) AS preparing_kots,
            SUM(CASE WHEN status='ready' THEN 1 ELSE 0 END) AS ready_kots,
            SUM(CASE WHEN status='served' THEN 1 ELSE 0 END) AS served_kots,
            SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled_kots
        FROM restaurant_kots
        WHERE client_id={$client_id}
        GROUP BY session_id
    ) k ON k.session_id=s.id";

    $itemsAgg = "LEFT JOIN (
        SELECT
            session_id,
            COUNT(*) AS items_count,
            COALESCE(SUM(qty),0) AS item_qty
        FROM restaurant_order_items
        WHERE client_id={$client_id}
          AND item_status NOT IN ('cancelled','void')
        GROUP BY session_id
    ) oi ON oi.session_id=s.id";

    // Kitchen Progress restaurant_kot_items se aayega, taake KOT complete hone ke baad old/pending progress na dikhe.
    $kitchenProgressAgg = "LEFT JOIN (
        SELECT
            k.session_id,
            SUM(CASE
                WHEN k.status='served' THEN 0
                WHEN k.status='cancelled' THEN 0
                WHEN ki.status='pending' THEN 1
                ELSE 0
            END) AS pending_items,
            SUM(CASE
                WHEN k.status='served' THEN 0
                WHEN k.status='cancelled' THEN 0
                WHEN ki.status='preparing' THEN 1
                ELSE 0
            END) AS preparing_items,
            SUM(CASE
                WHEN k.status='served' THEN 0
                WHEN k.status='cancelled' THEN 0
                WHEN ki.status='ready' THEN 1
                ELSE 0
            END) AS ready_items,
            SUM(CASE
                WHEN k.status='served' THEN 1
                WHEN ki.status='served' THEN 1
                ELSE 0
            END) AS served_items,
            SUM(CASE
                WHEN k.status='cancelled' THEN 1
                WHEN ki.status='cancelled' THEN 1
                ELSE 0
            END) AS cancelled_items
        FROM restaurant_kots k
        INNER JOIN restaurant_kot_items ki ON ki.kot_id = k.id
        WHERE k.client_id={$client_id}
        GROUP BY k.session_id
    ) kp ON kp.session_id=s.id";

    $baseWhere = implode(' AND ', $where);
    $activeCondition = "(s.status IN ('open','kot_sent','bill_printed') AND IFNULL(k.latest_kot_status,'pending') IN ('pending','preparing','ready'))";
    $completedCondition = "(s.status='paid' OR IFNULL(k.latest_kot_status,'')='served')";

    $tabWhere = '';
    if ($tab === 'active') $tabWhere = " AND {$activeCondition}";
    elseif ($tab === 'completed') $tabWhere = " AND {$completedCondition}";

    $countSql = "
        SELECT
            COALESCE(SUM(CASE WHEN {$activeCondition} THEN 1 ELSE 0 END),0) AS active_count,
            COALESCE(SUM(CASE WHEN {$completedCondition} THEN 1 ELSE 0 END),0) AS completed_count,
            COUNT(*) AS all_count
        FROM restaurant_table_sessions s
        $tableJoin
        $branchesJoin
        $waiterJoin
        $customerJoin
        $riderJoin
        $kotAgg
        $itemsAgg
        $kitchenProgressAgg
        WHERE {$baseWhere}
    ";
    $counts = array_merge($counts, fetch_one_prepared($conn, $countSql, $types, $params));

    $sql = "
        SELECT
            s.*,
            $tableSelect,
            $branchSelect,
            $waiterSelect,
            $customerSelect,
            $riderSelect,
            COALESCE(oi.items_count,0) AS items_count,
            COALESCE(oi.item_qty,0) AS item_qty,
            COALESCE(kp.pending_items,0) AS pending_items,
            COALESCE(kp.preparing_items,0) AS preparing_items,
            COALESCE(kp.ready_items,0) AS ready_items,
            COALESCE(kp.served_items,0) AS served_items,
            COALESCE(kp.cancelled_items,0) AS cancelled_items,
            COALESCE(k.kot_count,0) AS kot_count,
            COALESCE(k.latest_kot_id,0) AS latest_kot_id,
            COALESCE(k.latest_kot_no,'') AS latest_kot_no,
            COALESCE(k.latest_kot_status,'') AS latest_kot_status,
            COALESCE(k.latest_kot_at,'') AS latest_kot_at,
            COALESCE(k.pending_kots,0) AS pending_kots,
            COALESCE(k.preparing_kots,0) AS preparing_kots,
            COALESCE(k.ready_kots,0) AS ready_kots,
            COALESCE(k.served_kots,0) AS served_kots,
            COALESCE(k.cancelled_kots,0) AS cancelled_kots
        FROM restaurant_table_sessions s
        $tableJoin
        $branchesJoin
        $waiterJoin
        $customerJoin
        $riderJoin
        $kotAgg
        $itemsAgg
        $kitchenProgressAgg
        WHERE {$baseWhere} {$tabWhere}
        ORDER BY
            CASE
                WHEN IFNULL(k.latest_kot_status,'')='ready' THEN 1
                WHEN IFNULL(k.latest_kot_status,'')='preparing' THEN 2
                WHEN IFNULL(k.latest_kot_status,'')='pending' THEN 3
                WHEN s.status='bill_printed' THEN 4
                WHEN IFNULL(k.latest_kot_status,'')='served' THEN 5
                WHEN s.status='paid' THEN 6
                ELSE 9
            END,
            s.started_at DESC
        LIMIT 300
    ";
    $orders = fetch_all_prepared($conn, $sql, $types, $params);

    $statsRow = fetch_one_prepared($conn, "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN DATE(started_at)=CURDATE() THEN 1 ELSE 0 END) AS today_orders,
            SUM(CASE WHEN status IN ('open','kot_sent','bill_printed') THEN 1 ELSE 0 END) AS active_orders,
            SUM(CASE WHEN status='paid' AND DATE(COALESCE(closed_at, started_at))=CURDATE() THEN grand_total ELSE 0 END) AS today_revenue
        FROM restaurant_table_sessions
        WHERE client_id=?
    ", 'i', [$client_id]);
    foreach ($stats as $k => $v) $stats[$k] = (float)($statsRow[$k] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Order Management - OneERP</title>
    <?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{--ro-bg:#050711;--ro-text:#f8fafc;--ro-muted:#aeb8d5;--ro-border:rgba(167,139,250,.30);--ro-purple:#7c3aed;--ro-green:#10b981;--ro-orange:#f97316;--ro-blue:#3b82f6;--ro-red:#ef4444;}
        *{box-sizing:border-box}html,body{margin:0!important;min-height:100%!important;background:#050711!important;color:#f8fafc!important;overflow-x:hidden!important;font-family:Inter,"Segoe UI",Arial,sans-serif!important}body.restaurant-orders-theme{background:radial-gradient(circle at 12% 5%,rgba(124,58,237,.42),transparent 30%),radial-gradient(circle at 88% 4%,rgba(14,165,233,.18),transparent 28%),linear-gradient(135deg,#050711 0%,#080b18 38%,#140b2f 70%,#050711 100%)!important}a{text-decoration:none!important}
        .container-scroll{display:block!important;width:100%!important;min-height:100vh!important}.main-panel{margin-left:0!important;width:100%!important;background:transparent!important}.topbar-wrap{position:fixed!important;top:0!important;left:0!important;right:0!important;height:76px!important;z-index:99980!important;background:rgba(5,8,18,.96)!important;border-bottom:1px solid rgba(148,163,184,.16)!important;box-shadow:0 18px 45px rgba(0,0,0,.36)!important;backdrop-filter:blur(18px) saturate(150%)!important}.topbar-wrap>*,.topbar-wrap .topbar,.topbar-wrap .navbar,.topbar-wrap .topbar-inner,.topbar-wrap .topbar-content,.topbar-wrap .topbar-container,.topbar-wrap .header{margin-left:0!important;padding-left:16px!important;left:0!important;width:100%!important;max-width:100%!important;transform:none!important}
        .content-wrapper{position:relative!important;min-height:100vh!important;padding:106px 26px 36px!important;background:linear-gradient(90deg,rgba(255,255,255,.024) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.020) 1px,transparent 1px),radial-gradient(circle at 4% 0%,rgba(124,58,237,.36),transparent 31%),radial-gradient(circle at 82% 0%,rgba(14,165,233,.14),transparent 32%),linear-gradient(135deg,#070a17 0%,#0a0e21 38%,#160d33 74%,#060912 100%)!important;background-size:46px 46px,46px 46px,auto,auto,auto!important;color:#f8fafc!important}
        .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 22px;padding:24px 28px;border-radius:24px;border:1px solid rgba(167,139,250,.32);background:radial-gradient(circle at 16% 0%,rgba(124,58,237,.28),transparent 36%),linear-gradient(135deg,rgba(18,24,50,.86),rgba(8,13,31,.72));box-shadow:0 24px 70px rgba(0,0,0,.36),inset 0 1px 0 rgba(255,255,255,.08);backdrop-filter:blur(18px) saturate(150%)}.page-title{margin:0;color:#fff;font-size:34px;font-weight:950;letter-spacing:-.045em;text-shadow:0 0 28px rgba(124,58,237,.68),0 8px 24px rgba(0,0,0,.35)}.page-subtitle{margin-top:7px;color:#aeb8d5;font-size:15px;font-weight:800}
        .btn-main,.btn-soft,.btn-danger-soft{min-height:44px;border-radius:14px;padding:0 17px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:950;color:#fff!important;border:1px solid rgba(148,163,184,.24)}.btn-main{background:linear-gradient(135deg,#6d4cff,#7c3aed);box-shadow:0 17px 34px rgba(109,76,255,.34),inset 0 1px 0 rgba(255,255,255,.18)}.btn-soft{background:rgba(10,16,33,.84);color:#eaf0ff!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 12px 28px rgba(0,0,0,.16)}.btn-danger-soft{background:rgba(220,38,38,.18);color:#fecaca!important;border-color:rgba(248,113,113,.32)}.btn-sm{min-height:34px!important;padding:0 10px!important;border-radius:10px!important;font-size:12px!important}.action-row{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
        .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.glass-card{position:relative;overflow:hidden;border-radius:20px;border:1px solid rgba(167,139,250,.28);color:#f8fafc;background:radial-gradient(circle at 16% 0%,rgba(124,58,237,.25),transparent 36%),linear-gradient(135deg,rgba(18,24,50,.82),rgba(8,13,31,.70));box-shadow:0 24px 70px rgba(0,0,0,.34),inset 0 1px 0 rgba(255,255,255,.08);backdrop-filter:blur(18px) saturate(150%)}.stat-card{min-height:96px;padding:20px 18px}.stat-grid .stat-card:nth-child(1){background:linear-gradient(135deg,#6d4cff,#7c3aed)}.stat-grid .stat-card:nth-child(2){background:linear-gradient(135deg,#047857,#10b981)}.stat-grid .stat-card:nth-child(3){background:linear-gradient(135deg,#be123c,#ef4444)}.stat-grid .stat-card:nth-child(4){background:linear-gradient(135deg,#f97316,#f59e0b)}.stat-label{color:rgba(255,255,255,.88);font-size:13px;font-weight:950;text-transform:uppercase;letter-spacing:.06em}.stat-value{margin-top:10px;color:#fff;font-size:28px;font-weight:950;letter-spacing:-.04em}
        .filter-card{padding:18px;margin-bottom:18px;border-radius:22px;border:1px solid rgba(167,139,250,.30);background:radial-gradient(circle at 16% 0%,rgba(124,58,237,.22),transparent 36%),linear-gradient(135deg,rgba(18,24,50,.86),rgba(8,13,31,.76))}.filter-card label{color:#e0e7ff;font-size:13px;font-weight:950;margin-bottom:7px}.form-control,.form-select,textarea{min-height:46px;border-radius:13px!important;background:rgba(8,13,31,.94)!important;color:#f8fafc!important;border:1px solid rgba(191,219,254,.30)!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.05),0 12px 28px rgba(0,0,0,.16)!important;font-weight:850!important}.form-control::placeholder{color:rgba(203,213,225,.58)!important}.form-select option{background:#0f172a;color:#f8fafc}
        .tab-strip{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px;padding:8px;border-radius:18px;background:rgba(255,255,255,.06);border:1px solid rgba(148,163,184,.16);width:max-content;max-width:100%}.tab-pill{min-height:42px;display:inline-flex;align-items:center;gap:8px;padding:0 16px;border-radius:13px;color:#dbeafe;font-weight:950;background:rgba(15,23,42,.74);border:1px solid rgba(148,163,184,.16)}.tab-pill.active{background:#fff;color:#050711;border-color:#fff;box-shadow:0 14px 35px rgba(255,255,255,.12)}.tab-pill b{font-weight:950;color:inherit}
        .order-list{display:grid;gap:14px}.order-card{border-radius:20px;border:1px solid rgba(167,139,250,.26);background:linear-gradient(135deg,rgba(15,23,42,.82),rgba(14,18,38,.72));box-shadow:0 20px 55px rgba(0,0,0,.32);padding:16px 16px}.order-card-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.order-title{display:flex;align-items:center;gap:10px;flex-wrap:wrap;color:#fff;font-size:19px;font-weight:950;letter-spacing:-.02em}.order-meta{display:flex;gap:14px;flex-wrap:wrap;color:#aeb8d5;font-size:13px;font-weight:800;margin-top:8px}.order-meta span{display:inline-flex;align-items:center;gap:6px}.order-info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.info-box{border-radius:14px;border:1px solid rgba(148,163,184,.14);background:rgba(8,13,31,.62);padding:10px 12px}.info-label{font-size:11px;color:#94a3b8;font-weight:950;text-transform:uppercase;letter-spacing:.06em}.info-value{font-size:13px;color:#fff;font-weight:850;margin-top:4px;line-height:1.35}.order-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;min-width:220px}.icon-btn{width:44px;height:36px;border-radius:11px;border:1px solid rgba(148,163,184,.2);display:inline-flex;align-items:center;justify-content:center;background:rgba(15,23,42,.72);color:#fff}.dropdown-menu{background:#0f172a;border:1px solid rgba(167,139,250,.30);border-radius:14px;padding:8px}.dropdown-item{color:#e5e7eb;font-weight:850;border-radius:10px}.dropdown-item:hover{background:rgba(124,58,237,.25);color:#fff}.dropdown-item.text-danger:hover{background:rgba(239,68,68,.18);color:#fecaca}
        .badge-status,.type-badge,.delivery-badge,.kot-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:950;white-space:nowrap;text-transform:uppercase}.badge-open{background:rgba(59,130,246,.18);color:#bfdbfe}.badge-kot{background:rgba(16,185,129,.18);color:#bbf7d0}.badge-bill{background:rgba(245,158,11,.18);color:#fde68a}.badge-paid{background:rgba(34,197,94,.18);color:#bbf7d0}.badge-cancel{background:rgba(239,68,68,.18);color:#fecaca}.badge-muted{background:rgba(148,163,184,.15);color:#cbd5e1}.type-dine{background:rgba(124,58,237,.24);color:#ddd6fe;border:1px solid rgba(167,139,250,.30)}.type-takeaway{background:rgba(16,185,129,.18);color:#bbf7d0;border:1px solid rgba(16,185,129,.30)}.type-delivery{background:rgba(249,115,22,.18);color:#fed7aa;border:1px solid rgba(249,115,22,.30)}.type-muted{background:rgba(148,163,184,.14);color:#cbd5e1}.kot-pending{background:rgba(245,158,11,.18);color:#fde68a}.kot-preparing{background:rgba(59,130,246,.18);color:#bfdbfe}.kot-ready{background:rgba(16,185,129,.18);color:#bbf7d0}.kot-completed{background:rgba(34,197,94,.22);color:#bbf7d0}.kot-cancelled{background:rgba(239,68,68,.18);color:#fecaca}.kot-muted{background:rgba(148,163,184,.14);color:#cbd5e1}.del-pending{background:rgba(245,158,11,.18);color:#fde68a}.del-preparing{background:rgba(59,130,246,.18);color:#bfdbfe}.del-ready{background:rgba(16,185,129,.18);color:#bbf7d0}.del-dispatched{background:rgba(168,85,247,.20);color:#e9d5ff}.del-delivered{background:rgba(34,197,94,.20);color:#bbf7d0}.del-cancelled{background:rgba(239,68,68,.18);color:#fecaca}.del-muted{background:rgba(148,163,184,.14);color:#cbd5e1}.tiny{color:#aeb8d5!important;font-size:12px!important;font-weight:750!important}.muted-line{color:#94a3b8!important;font-size:12px!important;font-weight:800!important;margin-top:3px!important}.alert-danger{border-radius:16px;background:rgba(239,68,68,.13);color:#fecaca;border:1px solid rgba(239,68,68,.30);font-weight:850}.alert-success{border-radius:16px;background:rgba(16,185,129,.13);color:#bbf7d0;border:1px solid rgba(16,185,129,.30);font-weight:850}.empty-state{padding:34px;text-align:center;color:#aeb8d5;font-weight:850}.empty-state i{font-size:42px;color:#7c3aed;display:block;margin-bottom:10px}.modal-content{background:linear-gradient(135deg,rgba(15,23,42,.98),rgba(17,24,39,.94))!important;color:#f8fafc!important;border:1px solid rgba(167,139,250,.35)!important;border-radius:20px!important}.modal-header,.modal-footer{border-color:rgba(148,163,184,.16)!important}.btn-close{filter:invert(1)}
        #restaurantSidebarShell{position:fixed!important;top:0!important;left:0!important;width:270px!important;height:100vh!important;z-index:1000000!important;transform:translateX(-105%)!important;transition:transform .25s ease!important;background:#070b18!important;overflow-y:auto!important;overflow-x:hidden!important;box-shadow:24px 0 70px rgba(0,0,0,.60)!important}.sidebar-open #restaurantSidebarShell{transform:translateX(0)!important}.sidebar-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:999990;backdrop-filter:blur(4px)}
        @media(max-width:1200px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.order-info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:768px){.content-wrapper{padding:98px 14px 24px!important}.topbar-wrap{height:72px!important}.page-head{padding:18px}.page-title{font-size:26px}.stat-grid{grid-template-columns:1fr}.order-card-head{display:block}.order-actions{justify-content:flex-start;margin-top:12px}.order-info-grid{grid-template-columns:1fr}.btn-main,.btn-soft{width:100%}.tab-strip{width:100%}.tab-pill{flex:1;justify-content:center}}
    </style>
</head>
<body class="restaurant-orders-theme">
<div class="container-scroll">
    <div id="restaurantSidebarShell" class="restaurant-sidebar-shell">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar-wrap"><?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?></div>
        <div class="content-wrapper">
            <div class="page-head">
                <div>
                    <h1 class="page-title">Order Management</h1>
                    <div class="page-subtitle">Manage active, completed, and all restaurant orders based on Kitchen/KOT status.</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="restaurant_pos.php" class="btn-soft"><i class="bi bi-cart-plus"></i> POS / New Order</a>
                    <a href="restaurant_kitchen.php" class="btn-soft"><i class="bi bi-display"></i> Kitchen Display</a>
                    <a href="restaurant_reports.php" class="btn-main"><i class="bi bi-bar-chart"></i> Reports</a>
                </div>
            </div>

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= e($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= e($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <?php if(!empty($missingTables)): ?>
                <div class="alert alert-danger">Missing DB tables: <?= e(implode(', ', $missingTables)) ?>. Please import restaurant management migration SQL first.</div>
            <?php else: ?>
                <div class="stat-grid">
                    <div class="glass-card stat-card"><div class="stat-label">Total Orders</div><div class="stat-value"><?= (int)$stats['total_orders'] ?></div></div>
                    <div class="glass-card stat-card"><div class="stat-label">Today's Orders</div><div class="stat-value"><?= (int)$stats['today_orders'] ?></div></div>
                    <div class="glass-card stat-card"><div class="stat-label">Active Orders</div><div class="stat-value"><?= (int)$stats['active_orders'] ?></div></div>
                    <div class="glass-card stat-card"><div class="stat-label">Today's Revenue</div><div class="stat-value"><?= money_fmt($stats['today_revenue']) ?></div></div>
                </div>

                <form class="glass-card filter-card" method="get">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <input type="hidden" name="_live" value="<?= time() ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2"><label>From</label><input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>"></div>
                        <div class="col-md-2"><label>To</label><input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>"></div>
                        <div class="col-md-2"><label>Order Type</label><select name="order_type" class="form-select"><option value="">All Types</option><?php foreach(['dine_in'=>'Dine In','takeaway'=>'Takeaway','delivery'=>'Delivery'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $order_type_filter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label>KOT Status</label><select name="kitchen_status" class="form-select"><option value="">All KOT</option><?php foreach(['pending'=>'Pending','preparing'=>'Preparing','ready'=>'Ready','served'=>'Completed','cancelled'=>'Cancelled'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $kitchen_status_filter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label>Delivery Status</label><select name="delivery_status" class="form-select"><option value="">All Delivery</option><?php foreach(['pending'=>'Pending','preparing'=>'Preparing','ready'=>'Ready','dispatched'=>'Dispatched','delivered'=>'Delivered','cancelled'=>'Cancelled'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $delivery_status_filter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label>Branch</label><select name="branch_id" class="form-select"><option value="0">All Branches</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $branch_filter===(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label>Waiter</label><select name="waiter_id" class="form-select"><option value="0">All Waiters</option><?php foreach($waiters as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $waiter_filter===(int)$w['id']?'selected':'' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label>Rider</label><select name="rider_id" class="form-select"><option value="0">All Riders</option><?php foreach($riders as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $rider_filter===(int)$r['id']?'selected':'' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label>Table</label><select name="table_id" class="form-select"><option value="0">All Tables</option><?php foreach($restaurantTables as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $table_filter===(int)$t['id']?'selected':'' ?>><?= e($t['table_no'] . (!empty($t['table_name']) ? ' - '.$t['table_name'] : '')) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label>Search</label><input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Search order ID, table, token, customer, phone..."></div>
                        <div class="col-md-1 d-grid"><button class="btn-main" type="submit"><i class="bi bi-funnel"></i></button></div>
                        <div class="col-md-1 d-grid"><a class="btn-soft" href="restaurant_orders.php?_live=<?= time() ?>"><i class="bi bi-x-circle"></i></a></div>
                    </div>
                </form>

                <div class="tab-strip">
                    <a class="tab-pill <?= $tab==='active'?'active':'' ?>" href="<?= e(url_with(['tab'=>'active'])) ?>">Active <b>(<?= (int)$counts['active_count'] ?>)</b></a>
                    <a class="tab-pill <?= $tab==='completed'?'active':'' ?>" href="<?= e(url_with(['tab'=>'completed'])) ?>">Completed <b>(<?= (int)$counts['completed_count'] ?>)</b></a>
                    <a class="tab-pill <?= $tab==='all'?'active':'' ?>" href="<?= e(url_with(['tab'=>'all'])) ?>">All Orders <b>(<?= (int)$counts['all_count'] ?>)</b></a>
                </div>

                <div class="order-list">
                    <?php if(empty($orders)): ?>
                        <div class="glass-card empty-state"><i class="bi bi-inbox"></i><strong>No orders found.</strong><div class="tiny mt-1">No orders match the selected filters.</div></div>
                    <?php endif; ?>
                    <?php foreach($orders as $o):
                      $sid = (int)$o['id'];
$status = (string)($o['status'] ?? 'open');
$orderType = (string)($o['order_type'] ?? 'dine_in');
                        $orderRef = order_reference_label($o);
                        $customerName = order_customer_name($o);
                        $customerPhone = order_customer_phone($o);
                        $latestKotId = (int)($o['latest_kot_id'] ?? 0);
                        $latestKotNo = (string)($o['latest_kot_no'] ?? '');
                        $latestKotStatus = (string)($o['latest_kot_status'] ?? '');
                        $displayStatus = $status;

if (
    strtolower((string)$latestKotStatus) === 'served'
    && !in_array($status, ['paid', 'cancelled', 'void'], true)
) {
    $displayStatus = 'kitchen_completed';
}
                        $riderName = trim((string)($o['rider_name'] ?? ''));
                        $waiterName = trim((string)($o['waiter_name'] ?? ''));
                        $deliveryStatus = (string)($o['delivery_status'] ?? '');
                    ?>
                    <div class="order-card" id="order-<?= $sid ?>">
                        <div class="order-card-head">
                            <div>
                                <div class="order-title">
                                    <span>Order #<?= $sid ?></span>
                                    <span class="type-badge <?= e(order_type_badge_class($orderType)) ?>"><?= e(order_type_label($orderType)) ?></span>
                                    <span class="kot-badge <?= e(kitchen_status_class($latestKotStatus)) ?>"><?= e(kitchen_status_label($latestKotStatus)) ?></span>
                                    <?php if($latestKotNo !== ''): ?><span class="tiny">KOT: <?= e($latestKotNo) ?></span><?php endif; ?>
                                </div>
                                <div class="order-meta">
                                    <span><i class="bi bi-clock"></i><?= e(date('h:i:s A', strtotime((string)$o['started_at']))) ?></span>
                                    <span><i class="bi bi-calendar2"></i><?= e(date('d M Y', strtotime((string)$o['started_at']))) ?></span>
                                    <span><i class="bi bi-cash"></i><?= money_fmt($o['grand_total'] ?? 0) ?></span>
                                    <span><i class="bi bi-receipt"></i><?= (int)$o['items_count'] ?> lines / <?= number_format((float)$o['item_qty'], 2) ?> qty</span>
                                </div>
                            </div>
                            <div class="order-actions">
                                <?php if($latestKotId > 0): ?>
                                    <a class="icon-btn" href="restaurant_kot_print.php?kot_id=<?= $latestKotId ?>" target="_blank" title="View KOT"><i class="bi bi-eye"></i></a>
                                <?php else: ?>
                                    <a class="icon-btn" href="restaurant_pos.php?session_id=<?= $sid ?>" title="Open POS"><i class="bi bi-eye"></i></a>
                                <?php endif; ?>
                                <?php if(!in_array($status, ['paid','cancelled','void'], true)): ?>
                                    <div class="dropdown">
                                        <button class="btn-soft btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" <?= $latestKotId<=0 ? 'disabled' : '' ?>>Update</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button class="dropdown-item kot-update" type="button" data-kot-id="<?= $latestKotId ?>" data-status="preparing">Mark Preparing</button></li>
                                            <li><button class="dropdown-item kot-update" type="button" data-kot-id="<?= $latestKotId ?>" data-status="ready">Mark Ready</button></li>
                                            <li><button class="dropdown-item kot-update" type="button" data-kot-id="<?= $latestKotId ?>" data-status="served">Mark Completed</button></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><button class="dropdown-item text-danger kot-update" type="button" data-kot-id="<?= $latestKotId ?>" data-status="cancelled">Cancel KOT</button></li>
                                        </ul>
                                    </div>
                                    <a class="btn-soft btn-sm" href="restaurant_pos.php?session_id=<?= $sid ?>"><i class="bi bi-pencil-square"></i> POS</a>
                                    <a class="btn-soft btn-sm" href="restaurant_bill_print.php?session_id=<?= $sid ?>" target="_blank"><i class="bi bi-printer"></i> Bill</a>
                                    <a class="btn-main btn-sm" href="restaurant_payment.php?session_id=<?= $sid ?>"><i class="bi bi-credit-card"></i> Pay</a>
                                    <button type="button" class="btn-danger-soft btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal" data-session="<?= $sid ?>"><i class="bi bi-x-octagon"></i></button>
                                <?php else: ?>
                                    <span class="badge-status <?= e(status_badge_class($displayStatus)) ?>"><?= e(status_label($displayStatus)) ?></span>
                                    <?php if(!empty($o['sale_id'])): ?><a class="btn-soft btn-sm" href="orders.php"><i class="bi bi-receipt"></i> Invoice</a><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="order-info-grid">
                            <div class="info-box"><div class="info-label">Order Ref</div><div class="info-value"><?= e($orderRef) ?><?php if($orderType==='dine_in' && !empty($o['floor_name'])): ?><div class="muted-line"><?= e($o['floor_name']) ?></div><?php endif; ?></div></div>
                            <div class="info-box"><div class="info-label">Customer</div><div class="info-value"><?= e($customerName) ?><?php if($customerPhone !== ''): ?><div class="muted-line"><i class="bi bi-telephone"></i> <?= e($customerPhone) ?></div><?php endif; ?></div></div>
                            <div class="info-box"><div class="info-label">Staff</div><div class="info-value">Waiter: <?= e($waiterName ?: 'N/A') ?><?php if($orderType==='delivery'): ?><div class="muted-line">Rider: <?= e($riderName ?: 'Not Assigned') ?></div><?php endif; ?></div></div>
                            <div class="info-box"><div class="info-label">Status</div><div class="info-value"><span class="badge-status <?= e(status_badge_class($displayStatus)) ?>"><?= e(status_label($displayStatus)) ?></span><?php if($orderType==='delivery'): ?><div class="mt-2"><span class="delivery-badge <?= e(delivery_status_badge_class($deliveryStatus)) ?>"><?= e(delivery_status_label($deliveryStatus)) ?></span></div><?php endif; ?></div></div>
                            <div class="info-box"><div class="info-label">Kitchen Progress</div><div class="info-value">Pending: <?= (int)$o['pending_items'] ?> | Preparing: <?= (int)$o['preparing_items'] ?> | Ready: <?= (int)$o['ready_items'] ?> | Completed: <?= (int)$o['served_items'] ?></div></div>
                            <div class="info-box"><div class="info-label">KOT</div><div class="info-value">Total KOT: <?= (int)$o['kot_count'] ?><?php if(!empty($o['latest_kot_at'])): ?><div class="muted-line">Latest: <?= e(date('d M h:i A', strtotime((string)$o['latest_kot_at']))) ?></div><?php endif; ?></div></div>
                            <div class="info-box"><div class="info-label">Amount</div><div class="info-value"><?= money_fmt($o['grand_total'] ?? 0) ?><div class="muted-line">Paid: <?= money_fmt($o['paid_amount'] ?? 0) ?></div></div></div>
                            <div class="info-box"><div class="info-label">Delivery</div><div class="info-value"><?php if($orderType==='delivery'): ?>Charge: <?= money_fmt($o['delivery_charge'] ?? 0) ?><?php if(!empty($o['delivery_address'])): ?><div class="muted-line"><i class="bi bi-geo-alt"></i> <?= e($o['delivery_address']) ?></div><?php endif; ?><?php else: ?>N/A<?php endif; ?></div></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="cancel_session" value="1">
            <input type="hidden" name="session_id" id="cancelSessionId" value="">
            <div class="modal-header">
                <h5 class="modal-title">Cancel Restaurant Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label>Cancel Reason</label>
                <textarea name="cancel_reason" class="form-control" rows="4" required placeholder="Write cancellation reason..."></textarea>
                <div class="tiny mt-2">This will cancel the session, items, and pending KOTs. For dine-in orders, the table will be marked available.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-soft" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn-danger-soft">Confirm Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastBox"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showToast(message, ok=true){
    const box = document.getElementById('toastBox');
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (ok ? 'success' : 'danger') + ' border-0';
    el.setAttribute('role','alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body">'+String(message).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]))+'</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    box.appendChild(el);
    new bootstrap.Toast(el, {delay: 2500}).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}


function addLiveParam(url){
    try {
        const u = new URL(url, window.location.href);
        u.searchParams.set('_live', String(Date.now()));
        return u.pathname.split('/').pop() + '?' + u.searchParams.toString();
    } catch(e){
        return url;
    }
}

window.addEventListener('pageshow', function(event){
    const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (event.persisted || (nav && nav.type === 'back_forward')) {
        window.location.replace(addLiveParam(window.location.href));
    }
});

document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.tab-pill, .filter-card a[href^="restaurant_orders.php"]').forEach(function(a){
        a.addEventListener('click', function(){
            this.href = addLiveParam(this.href);
        });
    });

    const filterForm = document.querySelector('form.filter-card');
    if (filterForm) {
        filterForm.addEventListener('submit', function(){
            let live = this.querySelector('input[name="_live"]');
            if (!live) {
                live = document.createElement('input');
                live.type = 'hidden';
                live.name = '_live';
                this.appendChild(live);
            }
            live.value = String(Date.now());
        });
    }

    const cancelModal = document.getElementById('cancelModal');
    if(cancelModal){
        cancelModal.addEventListener('show.bs.modal', function(event){
            const btn = event.relatedTarget;
            document.getElementById('cancelSessionId').value = btn ? btn.getAttribute('data-session') : '';
        });
    }

    document.querySelectorAll('.kot-update').forEach(btn => {
        btn.addEventListener('click', function(){
            const kotId = parseInt(this.dataset.kotId || '0');
            const status = this.dataset.status || '';
            if(!kotId || !status) return;
            let reason = '';
            if(status === 'cancelled'){
                reason = prompt('KOT cancel reason required:') || '';
                reason = reason.trim();
                if(!reason){ showToast('Cancel reason required.', false); return; }
            }
            const fd = new FormData();
            fd.append('kot_id', kotId);
            fd.append('status', status);
            fd.append('notes', reason);
            fetch('ajax_restaurant_update_kot_status.php', {method:'POST', body:fd, cache:'no-store', headers:{Accept:'application/json'}})
                .then(async r => {
                    const txt = await r.text();
                    try { return JSON.parse(txt); } catch(e){ throw new Error(txt || 'Invalid response'); }
                })
                .then(data => {
                    if(!data.success){ showToast(data.message || 'Status update failed.', false); return; }
                    showToast(data.message || 'KOT status updated.');
                    setTimeout(() => window.location.reload(), 700);
                })
                .catch(err => showToast(err.message || 'Request failed.', false));
        });
    });

    const body = document.body;
    const sidebar = document.getElementById('restaurantSidebarShell');
    function getToggleButton(target) {
        return target.closest('.pos-sidebar-toggle, .navbar-toggler, #sidebarToggle, #sidebar-toggle, .sidebar-toggle, .toggle-sidebar, [data-toggle="sidebar"], [data-sidebar-toggle]');
    }
    document.addEventListener('click', function (e) {
        const btn = getToggleButton(e.target);
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        body.classList.toggle('sidebar-open');
        body.classList.remove('sidebar-collapsed');
    }, true);
    document.addEventListener('click', function (e) {
        if (!body.classList.contains('sidebar-open')) return;
        const clickedToggle = getToggleButton(e.target);
        if (sidebar && !sidebar.contains(e.target) && !clickedToggle) body.classList.remove('sidebar-open');
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') body.classList.remove('sidebar-open'); });
});
</script>
</body>
</html>
