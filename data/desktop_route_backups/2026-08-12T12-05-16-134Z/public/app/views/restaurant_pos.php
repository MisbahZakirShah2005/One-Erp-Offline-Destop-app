<?php
session_start();


// POS screen must always restore fresh active sessions.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (file_exists(__DIR__ . '/partials/performance.php')) {
    include(__DIR__ . '/partials/performance.php');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) $conn = $mysqli;
    elseif (isset($db) && $db instanceof mysqli) $conn = $db;
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

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money_fmt($amount): string { return 'Rs. ' . number_format((float)$amount, 2); }
function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}
function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($res['c'] ?? 0)) > 0;
}
function status_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'open' || $s === 'occupied' || $s === 'active') return 'st-occupied';
    if ($s === 'minimized' || $s === 'hold') return 'st-minimized';
    if ($s === 'kot_sent' || $s === 'kot sent') return 'st-kot';
    if ($s === 'bill_printed' || $s === 'bill printed') return 'st-bill';
    if ($s === 'available') return 'st-available';
    if ($s === 'reserved') return 'st-reserved';
    if ($s === 'dirty') return 'st-dirty';
    return 'st-muted';
}
function status_label(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'open' || $s === 'occupied') return 'Active';
    if ($s === 'kot_sent') return 'KOT Sent';
    if ($s === 'bill_printed') return 'Bill Printed';
    if ($s === 'minimized') return 'Minimized';
    return ucwords(str_replace('_', ' ', $status ?: 'Available'));
}
function image_url(?string $image): string {
    $image = trim((string)$image);
    if ($image === '') return '';
    if (preg_match('~^https?://~i', $image) || str_starts_with($image, '/')) return $image;
    return '/uploads/items/' . $image;
}
function log_activity(mysqli $conn, int $client_id, int $branch_id, int $session_id, ?int $table_id, string $type, string $details, int $user_id): void {
    if (!table_exists($conn, 'restaurant_activity_logs')) return;
    $stmt = $conn->prepare("INSERT INTO restaurant_activity_logs (client_id, branch_id, session_id, table_id, action_type, details, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param('iiiissi', $client_id, $branch_id, $session_id, $table_id, $type, $details, $user_id);
    $stmt->execute();
    $stmt->close();
}
function fetch_open_session(mysqli $conn, int $client_id, int $session_id): ?array {
    $hasWaiters = table_exists($conn, 'restaurant_waiters');
    $waiterSelect = $hasWaiters ? "w.name AS waiter_name" : "NULL AS waiter_name";
    $waiterJoin   = $hasWaiters ? "LEFT JOIN restaurant_waiters w ON w.id = s.waiter_id" : "";
    $stmt = $conn->prepare("SELECT s.*, t.table_no, t.table_name, t.floor_name, t.capacity, t.status AS table_status, $waiterSelect
        FROM restaurant_table_sessions s
        LEFT JOIN restaurant_tables t ON t.id = s.table_id
        $waiterJoin
        WHERE s.id=? AND s.client_id=? AND COALESCE(s.status,'open') NOT IN ('paid','cancelled','void')
        LIMIT 1");
    $stmt->bind_param('ii', $session_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function open_or_create_table_session(mysqli $conn, int $client_id, int $branch_id, int $table_id, int $user_id): array {
    if ($table_id <= 0) throw new Exception('Invalid table selected.');

    $stmt = $conn->prepare("SELECT id FROM restaurant_table_sessions WHERE client_id=? AND table_id=? AND COALESCE(status,'open') NOT IN ('paid','cancelled','void') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('ii', $client_id, $table_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $session = fetch_open_session($conn, $client_id, (int)$row['id']);
        if (!$session) throw new Exception('Active session not found.');
        return $session;
    }

    if (!table_exists($conn, 'restaurant_tables')) throw new Exception('restaurant_tables table not found.');
    $stmt = $conn->prepare("SELECT * FROM restaurant_tables WHERE id=? AND client_id=? LIMIT 1");
    $stmt->bind_param('ii', $table_id, $client_id);
    $stmt->execute();
    $tableRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tableRow) throw new Exception('Restaurant table not found.');

    $sessionBranch = (int)($tableRow['branch_id'] ?? $branch_id);
    $sessionBranch = $sessionBranch > 0 ? $sessionBranch : $branch_id;
    $waiterId = 0;
    if (isset($tableRow['waiter_id'])) $waiterId = (int)$tableRow['waiter_id'];
    elseif (isset($tableRow['assigned_waiter_id'])) $waiterId = (int)$tableRow['assigned_waiter_id'];
    $guest = 1;

    $conn->begin_transaction();
    try {
        if ($waiterId > 0) {
            $stmt = $conn->prepare("INSERT INTO restaurant_table_sessions (client_id, branch_id, table_id, waiter_id, guest_count, order_type, status, opened_by, started_at) VALUES (?,?,?,?,?, 'dine_in', 'open', ?, NOW())");
            $stmt->bind_param('iiiiii', $client_id, $sessionBranch, $table_id, $waiterId, $guest, $user_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO restaurant_table_sessions (client_id, branch_id, table_id, guest_count, order_type, status, opened_by, started_at) VALUES (?,?,?,?, 'dine_in', 'open', ?, NOW())");
            $stmt->bind_param('iiiii', $client_id, $sessionBranch, $table_id, $guest, $user_id);
        }
        $stmt->execute();
        $session_id = (int)$stmt->insert_id;
        $stmt->close();

        if (table_exists($conn, 'restaurant_session_tables')) {
            $isPrimary = 1;
            $stmt = $conn->prepare("INSERT IGNORE INTO restaurant_session_tables (client_id, branch_id, session_id, table_id, is_primary, created_at) VALUES (?,?,?,?,?,NOW())");
            $stmt->bind_param('iiiii', $client_id, $sessionBranch, $session_id, $table_id, $isPrimary);
            $stmt->execute();
            $stmt->close();
        }
        if (column_exists($conn, 'restaurant_tables', 'current_session_id')) {
            $stmt = $conn->prepare("UPDATE restaurant_tables SET status='Occupied', current_session_id=? WHERE id=? AND client_id=?");
            $stmt->bind_param('iii', $session_id, $table_id, $client_id);
        } else {
            $stmt = $conn->prepare("UPDATE restaurant_tables SET status='Occupied' WHERE id=? AND client_id=?");
            $stmt->bind_param('ii', $table_id, $client_id);
        }
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $client_id, $sessionBranch, $session_id, $table_id, 'session_opened_from_pos', 'Session started from multi-table restaurant POS.', $user_id);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $session = fetch_open_session($conn, $client_id, $session_id);
    if (!$session) throw new Exception('Session created but could not be loaded.');
    return $session;
}
function session_payload(array $s): array {
    $orderType = (string)($s['order_type'] ?? 'dine_in');

    if ($orderType === 'takeaway') {
        $tableTitle = 'Takeaway ' . (!empty($s['token_no']) ? ('#' . $s['token_no']) : ('#' . ($s['id'] ?? '')));
    } elseif ($orderType === 'delivery') {
        $tableTitle = 'Delivery ' . (!empty($s['token_no']) ? ('#' . $s['token_no']) : ('#' . ($s['id'] ?? '')));
    } else {
        $tableTitle = !empty($s['table_no']) ? ('Table ' . $s['table_no']) : ('Order #' . ($s['id'] ?? ''));
        if (!empty($s['table_name'])) $tableTitle .= ' - ' . $s['table_name'];
    }

    return [
        'id' => (int)($s['id'] ?? 0),
        'table_id' => (int)($s['table_id'] ?? 0),
        'table_no' => (string)($s['table_no'] ?? ''),
        'table_name' => (string)($s['table_name'] ?? ''),
        'title' => $tableTitle,
        'waiter_name' => (string)($s['waiter_name'] ?? ''),
        'rider_id' => (int)($s['rider_id'] ?? 0),
        'rider_name' => (string)($s['rider_name'] ?? ''),
        'status' => (string)($s['status'] ?? 'open'),
        'order_type' => $orderType,
        'token_no' => (string)($s['token_no'] ?? ''),
        'customer_id' => (int)($s['customer_id'] ?? 0),
        'customer_name' => (string)($s['customer_name'] ?? ''),
        'customer_phone' => (string)($s['customer_phone'] ?? ''),
        'delivery_address' => (string)($s['delivery_address'] ?? ''),
        'delivery_charge' => (float)($s['delivery_charge'] ?? 0),
        'delivery_status' => (string)($s['delivery_status'] ?? ''),
        'guest_count' => (int)($s['guest_count'] ?? 1),
        'grand_total' => (float)($s['grand_total'] ?? 0),
        'formatted_grand_total' => money_fmt($s['grand_total'] ?? 0),
        'started_at' => (string)($s['started_at'] ?? '')
    ];
}

/* ================= MINIMUM RUNTIME SCHEMA CHECK ================= */
try {
    if (!table_exists($conn, 'restaurant_table_sessions')) {
        $conn->query("CREATE TABLE restaurant_table_sessions (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            table_id INT(11) DEFAULT NULL,
            waiter_id INT(11) DEFAULT NULL,
            rider_id INT(11) DEFAULT NULL,
            customer_id INT(11) DEFAULT NULL,
            customer_name VARCHAR(150) DEFAULT NULL,
            customer_phone VARCHAR(50) DEFAULT NULL,
            delivery_address TEXT DEFAULT NULL,
            guest_count INT(11) NOT NULL DEFAULT 1,
            order_type ENUM('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in',
            token_no VARCHAR(50) DEFAULT NULL,
            delivery_status ENUM('pending','preparing','ready','dispatched','delivered','cancelled') DEFAULT NULL,
            status ENUM('open','kot_sent','bill_printed','paid','cancelled','void') NOT NULL DEFAULT 'open',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            service_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            delivery_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
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
            KEY idx_rts_table_status (table_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
    $sessionAdds = [
        'rider_id' => "ALTER TABLE restaurant_table_sessions ADD COLUMN rider_id INT(11) DEFAULT NULL AFTER waiter_id",
        'customer_name' => "ALTER TABLE restaurant_table_sessions ADD COLUMN customer_name VARCHAR(150) DEFAULT NULL AFTER customer_id",
        'customer_phone' => "ALTER TABLE restaurant_table_sessions ADD COLUMN customer_phone VARCHAR(50) DEFAULT NULL AFTER customer_name",
        'delivery_address' => "ALTER TABLE restaurant_table_sessions ADD COLUMN delivery_address TEXT DEFAULT NULL AFTER customer_phone",
        'token_no' => "ALTER TABLE restaurant_table_sessions ADD COLUMN token_no VARCHAR(50) DEFAULT NULL AFTER order_type",
        'delivery_status' => "ALTER TABLE restaurant_table_sessions ADD COLUMN delivery_status ENUM('pending','preparing','ready','dispatched','delivered','cancelled') DEFAULT NULL AFTER status",
        'delivery_charge' => "ALTER TABLE restaurant_table_sessions ADD COLUMN delivery_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER service_charge",
    ];
    foreach ($sessionAdds as $col => $sql) {
        if (!column_exists($conn, 'restaurant_table_sessions', $col)) {
            try { $conn->query($sql); } catch (Throwable $ignore) {}
        }
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
} catch (Throwable $schemaError) {
    $schemaMessage = $schemaError->getMessage();
}

$initial_session_id = (int)($_GET['session_id'] ?? 0);
$initial_table_id   = (int)($_GET['table_id'] ?? 0);
$errorMessage = '';
$selectedSession = null;

try {
    if ($initial_session_id > 0) {
        $selectedSession = fetch_open_session($conn, $client_id, $initial_session_id);
        if (!$selectedSession) $errorMessage = 'This session is not active or not found.';
        else $branch_id = (int)($selectedSession['branch_id'] ?? $branch_id);
    } elseif ($initial_table_id > 0) {
        $selectedSession = open_or_create_table_session($conn, $client_id, $branch_id, $initial_table_id, $user_id);
        $initial_session_id = (int)$selectedSession['id'];
        $branch_id = (int)($selectedSession['branch_id'] ?? $branch_id);
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

/* ================= ACTIVE SESSIONS ================= */
/* ================= ACTIVE SESSIONS ================= */
$activeSessions = [];
$sessionPayloads = [];
$sessionsByTable = [];

try {
    $hasWaiters = table_exists($conn, 'restaurant_waiters');
    $waiterSelect = $hasWaiters ? "w.name AS waiter_name" : "NULL AS waiter_name";
    $waiterJoin   = $hasWaiters ? "LEFT JOIN restaurant_waiters w ON w.id=s.waiter_id" : "";

    $stmt = $conn->prepare("
        SELECT 
            s.id, s.client_id, s.branch_id, s.table_id, s.waiter_id, s.rider_id,
            s.customer_id, s.customer_name, s.customer_phone, s.delivery_address,
            s.delivery_charge, s.token_no, s.delivery_status, s.status,
            s.subtotal, s.discount, s.tax, s.service_charge, s.grand_total,
            s.order_type, s.guest_count, s.started_at,
            t.table_no, t.table_name,
            $waiterSelect
        FROM restaurant_table_sessions s
        LEFT JOIN restaurant_tables t ON t.id=s.table_id
        $waiterJoin
        WHERE s.client_id=?
          AND COALESCE(s.status,'open') NOT IN ('paid','cancelled','void')
        ORDER BY s.started_at DESC
        LIMIT 50
    ");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $activeSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $ignore) {}

foreach ($activeSessions as $s) {
    $payload = session_payload($s);
    $sessionPayloads[] = $payload;

    if (!empty($payload['table_id'])) {
        $sessionsByTable[(int)$payload['table_id']] = $payload;
    }
}

/* ================= TABLES LIST ================= */
$tables = [];
try {
    if (table_exists($conn, 'restaurant_tables')) {
        $hasIsActive = column_exists($conn, 'restaurant_tables', 'is_active');
        $hasCurrentSession = column_exists($conn, 'restaurant_tables', 'current_session_id');
        $waiterCol = '';
        if (column_exists($conn, 'restaurant_tables', 'waiter_id')) $waiterCol = 'waiter_id';
        elseif (column_exists($conn, 'restaurant_tables', 'assigned_waiter_id')) $waiterCol = 'assigned_waiter_id';
        $hasWaiters = table_exists($conn, 'restaurant_waiters');

        $select = "t.id, t.client_id, t.branch_id, t.table_no, t.table_name, t.floor_name, t.capacity, t.status";
        $select .= $hasCurrentSession ? ", t.current_session_id" : ", NULL AS current_session_id";
        $select .= $waiterCol ? ", t.$waiterCol AS table_waiter_id" : ", NULL AS table_waiter_id";
        $select .= ($waiterCol && $hasWaiters) ? ", tw.name AS table_waiter_name" : ", NULL AS table_waiter_name";
        $join = ($waiterCol && $hasWaiters) ? "LEFT JOIN restaurant_waiters tw ON tw.id=t.$waiterCol" : "";
        $where = "WHERE t.client_id=?";
        if ($hasIsActive) $where .= " AND t.is_active=1";
        if ($branch_id > 0) {
            $stmt = $conn->prepare("SELECT $select FROM restaurant_tables t $join $where AND (t.branch_id=? OR t.branch_id=0) ORDER BY CAST(t.table_no AS UNSIGNED), t.table_no, t.id");
            $stmt->bind_param('ii', $client_id, $branch_id);
        } else {
            $stmt = $conn->prepare("SELECT $select FROM restaurant_tables t $join $where ORDER BY CAST(t.table_no AS UNSIGNED), t.table_no, t.id");
            $stmt->bind_param('i', $client_id);
        }
        $stmt->execute();
        $rawTables = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rawTables as $t) {
            $sid = 0;
            $sessionStatus = '';
            $sessionTotal = 0;
            $sessionWaiter = '';
            if (isset($sessionsByTable[(int)$t['id']])) {
                $sid = (int)$sessionsByTable[(int)$t['id']]['id'];
                $sessionStatus = (string)$sessionsByTable[(int)$t['id']]['status'];
                $sessionTotal = (float)$sessionsByTable[(int)$t['id']]['grand_total'];
                $sessionWaiter = (string)$sessionsByTable[(int)$t['id']]['waiter_name'];
            } elseif (!empty($t['current_session_id'])) {
                $sid = (int)$t['current_session_id'];
            }
            $waiterName = $sessionWaiter ?: (string)($t['table_waiter_name'] ?? '');
            $tables[] = [
                'id' => (int)$t['id'],
                'table_no' => (string)($t['table_no'] ?? ''),
                'table_name' => (string)($t['table_name'] ?? ''),
                'floor_name' => (string)($t['floor_name'] ?? ''),
                'capacity' => (int)($t['capacity'] ?? 0),
                'status' => (string)($t['status'] ?? 'Available'),
                'session_id' => $sid,
                'session_status' => $sessionStatus,
                'session_total' => $sessionTotal,
                'formatted_session_total' => money_fmt($sessionTotal),
                'waiter_name' => $waiterName
            ];
        }
    }
} catch (Throwable $e) {
    if (!$errorMessage) $errorMessage = $e->getMessage();
}

/* ================= MENU ITEMS & CATEGORIES ================= */
$items = [];
$categories = [];

try {
    $categoryMap = [];

    /*
    |--------------------------------------------------------------------------
    | Fetch categories from categories table
    |--------------------------------------------------------------------------
    | Is se Inventory > Categories wali categories POS strip me show hongi,
    | chahe abhi kisi item me category assign na bhi ho.
    */
    if (table_exists($conn, 'categories')) {
        $stmt = $conn->prepare("
            SELECT name
            FROM categories
            WHERE client_id = ?
              AND deleted_at IS NULL
            ORDER BY name ASC
        ");

        if ($stmt) {
            $stmt->bind_param('i', $client_id);
            $stmt->execute();
            $catRes = $stmt->get_result();

            while ($catRow = $catRes->fetch_assoc()) {
                $catName = trim((string)($catRow['name'] ?? ''));

                if ($catName !== '') {
                    $categoryMap[$catName] = true;
                }
            }

            $stmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch menu/items
    |--------------------------------------------------------------------------
    | Important:
    | category ko TRIM karke category_label me normalize kar rahe hain.
    | Phir same value item['category'] me set kar rahe hain taake neeche HTML
    | aur JS dono me category properly show/filter ho.
    */
    if (table_exists($conn, 'items')) {
        $hasKitchen    = column_exists($conn, 'items', 'kitchen_section');
        $hasPrep       = column_exists($conn, 'items', 'prep_time_minutes');
        $hasRestaurant = column_exists($conn, 'items', 'is_restaurant_item');

        $select = "
            id,
            name,
            sku,
            type,
            price,
            stock_qty,
            TRIM(COALESCE(category, '')) AS category,
            image,
            branch_id
        ";

        if ($hasKitchen) {
            $select .= ", kitchen_section";
        }

        if ($hasPrep) {
            $select .= ", prep_time_minutes";
        }

        if ($hasRestaurant) {
            $select .= ", is_restaurant_item";
        }

        if ($branch_id > 0) {
            $stmt = $conn->prepare("
                SELECT $select
                FROM items
                WHERE client_id = ?
                  AND LOWER(status) = 'active'
                  AND (branch_id = 0 OR branch_id = ? OR ? = 0)
                ORDER BY 
                    CASE 
                        WHEN type = 'menu' THEN 0
                        WHEN type = 'product' THEN 1
                        ELSE 2
                    END,
                    name ASC
                LIMIT 500
            ");
            $stmt->bind_param('iii', $client_id, $branch_id, $branch_id);
        } else {
            $stmt = $conn->prepare("
                SELECT $select
                FROM items
                WHERE client_id = ?
                  AND LOWER(status) = 'active'
                ORDER BY 
                    CASE 
                        WHEN type = 'menu' THEN 0
                        WHEN type = 'product' THEN 1
                        ELSE 2
                    END,
                    name ASC
                LIMIT 500
            ");
            $stmt->bind_param('i', $client_id);
        }

        $stmt->execute();
        $itemRes = $stmt->get_result();

        while ($row = $itemRes->fetch_assoc()) {
            $catName = trim((string)($row['category'] ?? ''));

            if ($catName === '') {
                $catName = 'Uncategorized';
            }

            /*
             | Yeh line bohat zaroori hai:
             | Aage page ke HTML + JS dono item['category'] read karte hain.
            */
            $row['category'] = $catName;

            $items[] = $row;
            $categoryMap[$catName] = true;
        }

        $stmt->close();
    }

    $categories = array_keys($categoryMap);
    sort($categories);

} catch (Throwable $e) {
    $menuError = $e->getMessage();
}

/* ================= WAITERS & RIDERS LIST ================= */
$waiters = [];
try {
    if (table_exists($conn, 'restaurant_waiters')) {
        $rwHasName   = column_exists($conn, 'restaurant_waiters', 'name');
        $rwHasUserId = column_exists($conn, 'restaurant_waiters', 'user_id') && table_exists($conn, 'users');
        $rwHasStatus = column_exists($conn, 'restaurant_waiters', 'status');
        $rwHasBranch = column_exists($conn, 'restaurant_waiters', 'branch_id');

        $nameSelect = $rwHasName ? "rw.name" : ($rwHasUserId ? "u.name" : "CONCAT('Waiter #', rw.id)");
        $joinUser = $rwHasUserId ? "LEFT JOIN users u ON u.id = rw.user_id" : "";
        $where = "WHERE rw.client_id=?";
        if ($rwHasStatus) $where .= " AND LOWER(rw.status)='active'";
        if ($rwHasBranch && $branch_id > 0) $where .= " AND (rw.branch_id=0 OR rw.branch_id=?)";

        $sql = "SELECT rw.id, $nameSelect AS name FROM restaurant_waiters rw $joinUser $where ORDER BY name ASC, rw.id ASC";
        $stmt = $conn->prepare($sql);
        if ($rwHasBranch && $branch_id > 0) $stmt->bind_param('ii', $client_id, $branch_id);
        else $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $waiters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $ignore) {}

$riders = [];
try {
    if (table_exists($conn, 'users')) {
        $hasUserClient = column_exists($conn, 'users', 'client_id');
        $hasUserName   = column_exists($conn, 'users', 'name');
        $hasUserStatus = column_exists($conn, 'users', 'status');
        $hasUserPhone  = column_exists($conn, 'users', 'phone');
        $hasUserEmail  = column_exists($conn, 'users', 'email');

        $nameSelect = $hasUserName ? "name" : "CONCAT('Staff #', id)";
        $phoneSelect = $hasUserPhone ? "phone" : "'' AS phone";
        $emailSelect = $hasUserEmail ? "email" : "'' AS email";

        $where = "WHERE 1=1";
        if ($hasUserClient) $where .= " AND client_id=?";
        if ($hasUserStatus) $where .= " AND LOWER(status)='active'";

        $sql = "SELECT id, $nameSelect AS name, $phoneSelect, $emailSelect FROM users $where ORDER BY name ASC, id ASC LIMIT 300";
        $stmt = $conn->prepare($sql);
        if ($hasUserClient) $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $riders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $ignore) {}

$pageTitle = 'Restaurant POS';
$selectedPayload = $selectedSession ? session_payload($selectedSession) : null;
$initialSessionId = $selectedPayload ? (int)$selectedPayload['id'] : 0;
$tablesJson = json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sessionsJson = json_encode($sessionPayloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$selectedJson = json_encode($selectedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$categoriesJson = json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Restaurant POS - One ERP</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* ============================================================
   ONEERP RESTAURANT POS - FLOATING MULTI-WINDOW WORKSPACE
   ============================================================ */
:root {
    --pos-topbar-h: 70px;
    --pos-mini-h: 68px;
    --win-default-w: 960px;
    --win-default-h: 640px;
    --win-min-w: 780px;
    --win-min-h: 480px;
    --clr-bg: #060a18;
    --clr-surface: rgba(13,19,38,0.82);
    --clr-card: rgba(15,22,42,0.78);
    --clr-border: rgba(148,163,184,0.14);
    --clr-border-accent: rgba(124,58,237,0.45);
    --clr-purple: #7c3aed;
    --clr-purple-dim: rgba(109,76,255,0.22);
    --clr-orange: #f97316;
    --clr-green: #10b981;
    --clr-blue: #3b82f6;
    --clr-text: #f8fafc;
    --clr-muted: #94a3b8;
    --clr-subtle: #cbd5e1;
    --radius-lg: 18px;
    --radius-md: 13px;
    --radius-sm: 10px;
    --shadow-window: 0 32px 80px rgba(0,0,0,0.55), 0 0 0 1px rgba(124,58,237,0.12), inset 0 1px 0 rgba(255,255,255,0.04);
    --shadow-window-active: 0 36px 96px rgba(0,0,0,0.65), 0 0 0 1.5px rgba(124,58,237,0.55), inset 0 1px 0 rgba(255,255,255,0.06);
}

*, *::before, *::after { box-sizing: border-box; }

html, body.restaurant-pos-page {
    margin: 0 !important;
    height: 100% !important;
    min-height: 100vh;
    overflow-x: hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.28), transparent 28%),
        radial-gradient(circle at 82% 3%, rgba(59,130,246,.14), transparent 30%),
        linear-gradient(135deg, #040710 0%, #0a0e20 38%, #120b24 68%, #070b15 100%) !important;
    color: var(--clr-text) !important;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif !important;
}

/* Scrollbar styling */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(124,58,237,.35); border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: rgba(124,58,237,.55); }

/* Layout */
.restaurant-pos-page .container-scroll { display: flex !important; width: 100% !important; min-height: 100vh !important; }
.restaurant-pos-page .main-panel { flex: 1 !important; min-width: 0 !important; min-height: 100vh !important; padding-top: var(--pos-topbar-h) !important; }
.restaurant-pos-page .content-wrapper {
    padding: 16px 18px calc(var(--pos-mini-h) + 20px) !important;
    min-height: calc(100vh - var(--pos-topbar-h)) !important;
    position: relative !important;
}

/* ---- Page Header ---- */
.pos-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.pos-page-title h3 {
    font-size: 26px;
    font-weight: 950;
    letter-spacing: -.04em;
    margin: 0;
    color: #fff;
    text-shadow: 0 0 28px rgba(124,58,237,.35);
}
.pos-page-title p { margin: 4px 0 0; color: var(--clr-muted); font-size: 13px; font-weight: 600; }
.head-actions { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; }

/* ---- Utility Buttons ---- */
.btn-glow {
    min-height: 40px; height: 40px; border: 0; border-radius: var(--radius-md);
    padding: 0 16px; font-weight: 800; font-size: 13px; color: #fff !important;
    text-decoration: none !important; display: inline-flex; align-items: center;
    justify-content: center; gap: 7px; cursor: pointer; white-space: nowrap;
    background: linear-gradient(135deg, #6d4cff, #7c3aed);
    box-shadow: 0 10px 28px rgba(109,76,255,.28);
    transition: opacity .15s, transform .12s;
}
.btn-glow:hover { opacity: .88; transform: translateY(-1px); }
.btn-glow:active { transform: translateY(0); }
.btn-green  { background: linear-gradient(135deg, #059669, #10b981); box-shadow: 0 10px 28px rgba(16,185,129,.22); }
.btn-orange { background: linear-gradient(135deg, #f59e0b, #f97316); box-shadow: 0 10px 28px rgba(249,115,22,.22); }
.btn-blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); box-shadow: 0 10px 28px rgba(59,130,246,.22); }
.btn-yellow { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #111827 !important; }
.btn-soft   { background: rgba(15,23,42,.72); border: 1px solid rgba(148,163,184,.18); box-shadow: none; }

/* ---- Glass Card ---- */
.glass-card {
    background: radial-gradient(circle at 20% 0%, rgba(124,58,237,.12), transparent 40%),
                linear-gradient(135deg, rgba(13,19,38,.80), rgba(15,22,42,.60));
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-lg);
    color: var(--clr-text);
    box-shadow: 0 18px 48px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.04);
    backdrop-filter: blur(18px) saturate(130%);
}

/* ---- Alert ---- */
.alert-glass {
    background: rgba(220,38,38,.15); border: 1px solid rgba(248,113,113,.25);
    color: #fecaca; border-radius: var(--radius-md); padding: 13px 16px;
    font-weight: 800; margin-bottom: 14px;
}

/* ============================================================
   TABLES DASHBOARD GRID
   ============================================================ */
.tables-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.pos-table-card {
    background: linear-gradient(135deg, rgba(13,19,38,.82), rgba(20,28,56,.58));
    border: 1px solid rgba(148,163,184,.15);
    border-radius: 18px;
    padding: 16px;
    cursor: pointer;
    transition: all .2s ease;
    position: relative;
    overflow: hidden;
}
.pos-table-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, #7c3aed, #3b82f6);
    opacity: 0;
    transition: opacity .2s;
}
.pos-table-card:hover {
    border-color: rgba(124,58,237,.5);
    box-shadow: 0 20px 48px rgba(0,0,0,.35), 0 0 0 1px rgba(124,58,237,.18);
    transform: translateY(-2px);
}
.pos-table-card:hover::before { opacity: 1; }
.pos-table-card.is-active {
    border-color: rgba(124,58,237,.6);
    box-shadow: 0 20px 48px rgba(0,0,0,.38), 0 0 0 1.5px rgba(124,58,237,.35);
}
.pos-table-card.is-active::before { opacity: 1; }

.ptc-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
}
.ptc-num {
    font-size: 36px;
    font-weight: 950;
    line-height: 1;
    color: #fb923c;
    letter-spacing: -.06em;
}
.ptc-info { flex: 1; min-width: 0; }
.ptc-name {
    font-size: 14px;
    font-weight: 800;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ptc-floor { font-size: 11px; color: var(--clr-muted); margin-top: 2px; font-weight: 600; }

.ptc-meta {
    display: grid;
    gap: 5px;
    font-size: 12px;
    color: var(--clr-subtle);
    font-weight: 700;
    margin-bottom: 13px;
}
.ptc-meta span { display: flex; align-items: center; gap: 6px; }
.ptc-meta b { color: #fb923c; }

.ptc-bill {
    font-size: 18px;
    font-weight: 950;
    color: #fff;
    margin-bottom: 12px;
}
.ptc-bill small { font-size: 11px; color: var(--clr-muted); font-weight: 600; display: block; margin-bottom: 2px; }

.ptc-open-btn {
    width: 100%;
    height: 36px;
    border: 0;
    border-radius: 10px;
    font-weight: 800;
    font-size: 12px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: linear-gradient(135deg, rgba(124,58,237,.55), rgba(109,76,255,.45));
    border: 1px solid rgba(124,58,237,.4);
    transition: all .15s;
}
.ptc-open-btn:hover { background: linear-gradient(135deg, #6d4cff, #7c3aed); }

/* Status badges */
.st-occupied  { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 800; background: rgba(16,185,129,.16); color: #bbf7d0; white-space: nowrap; }
.st-kot       { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 800; background: rgba(59,130,246,.16); color: #bfdbfe; white-space: nowrap; }
.st-bill      { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 800; background: rgba(245,158,11,.16); color: #fde68a; white-space: nowrap; }
.st-available { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 800; background: rgba(148,163,184,.12); color: #cbd5e1; white-space: nowrap; }
.st-reserved  { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 800; background: rgba(251,146,60,.16); color: #fed7aa; white-space: nowrap; }
.st-dirty     { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 800; background: rgba(148,163,184,.14); color: #e2e8f0; white-space: nowrap; }
.st-muted     { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 800; background: rgba(148,163,184,.12); color: var(--clr-muted); white-space: nowrap; }
.st-minimized { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 800; background: rgba(59,130,246,.20); color: #bfdbfe; white-space: nowrap; }

/* ============================================================
   FLOATING WORKSPACE
   ============================================================ */
.floating-workspace {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    pointer-events: none;
    z-index: 1000;
    overflow: hidden;
}
.floating-workspace > * { pointer-events: all; }

/* ============================================================
   FLOATING POS WINDOW
   ============================================================ */
.pos-floating-window {
    position: fixed;
    width: var(--win-default-w);
    height: var(--win-default-h);
    min-width: var(--win-min-w);
    min-height: var(--win-min-h);
    background: linear-gradient(145deg, rgba(10,15,30,.97), rgba(13,20,40,.95));
    border: 1px solid rgba(124,58,237,.35);
    border-radius: 20px;
    box-shadow: var(--shadow-window);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: box-shadow .15s;
    will-change: transform;
    backdrop-filter: blur(28px) saturate(140%);
}
.pos-floating-window.is-front {
    box-shadow: var(--shadow-window-active);
    border-color: rgba(124,58,237,.6);
}
.pos-floating-window.window-maximized {
    top: calc(var(--pos-topbar-h) + 8px) !important;
    left: 8px !important;
    right: 8px !important;
    bottom: calc(var(--pos-mini-h) + 8px) !important;
    width: calc(100vw - 16px) !important;
    height: calc(100vh - var(--pos-topbar-h) - var(--pos-mini-h) - 16px) !important;
    border-radius: 16px;
}
.pos-floating-window.window-minimized { display: none !important; }

/* Titlebar */
.pos-window-titlebar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 14px;
    height: 52px;
    min-height: 52px;
    background: linear-gradient(90deg, rgba(109,76,255,.18), rgba(59,130,246,.08));
    border-bottom: 1px solid rgba(124,58,237,.22);
    cursor: grab;
    user-select: none;
    flex: 0 0 52px;
}
.pos-window-titlebar:active { cursor: grabbing; }
.pos-window-titlebar.no-drag { cursor: default; }

.win-drag-handle {
    color: rgba(148,163,184,.5);
    font-size: 15px;
    flex: 0 0 auto;
}
.win-title-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
}
.win-title-main strong {
    font-size: 15px;
    font-weight: 900;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.win-title-main small {
    font-size: 11px;
    color: var(--clr-muted);
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.win-total-badge {
    background: rgba(16,185,129,.18);
    border: 1px solid rgba(16,185,129,.3);
    color: #bbf7d0;
    font-size: 12px;
    font-weight: 800;
    padding: 4px 10px;
    border-radius: 999px;
    white-space: nowrap;
    flex: 0 0 auto;
}
.pos-window-controls {
    display: flex;
    gap: 6px;
    flex: 0 0 auto;
}
.win-ctrl-btn {
    width: 28px; height: 28px;
    border-radius: 8px;
    border: 1px solid rgba(148,163,184,.18);
    background: rgba(15,23,42,.72);
    color: #cbd5e1;
    font-size: 12px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all .12s;
}
.win-ctrl-btn:hover { background: rgba(124,58,237,.35); color: #fff; border-color: rgba(124,58,237,.4); }
.win-ctrl-btn.close:hover { background: rgba(220,38,38,.4); color: #fca5a5; border-color: rgba(220,38,38,.45); }
.win-ctrl-btn.minimize:hover { background: rgba(245,158,11,.3); color: #fde68a; border-color: rgba(245,158,11,.4); }

/* Window Body */
.pos-window-body {
    display: flex;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

/* Categories Panel */
.pos-window-categories {
    width: 150px;
    min-width: 150px;
    flex: 0 0 150px;
    background: rgba(7,11,22,.55);
    border-right: 1px solid rgba(148,163,184,.10);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    padding: 12px 8px;
    gap: 5px;
}
.win-cat-title {
    font-size: 10px;
    font-weight: 800;
    color: var(--clr-muted);
    letter-spacing: .1em;
    text-transform: uppercase;
    padding: 4px 8px 8px;
}
.win-cat-btn {
    width: 100%;
    height: 36px;
    border: 1px solid rgba(148,163,184,.12);
    background: transparent;
    color: var(--clr-subtle);
    border-radius: 9px;
    padding: 0 10px;
    font-size: 12px;
    font-weight: 700;
    text-align: left;
    cursor: pointer;
    transition: all .14s;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.win-cat-btn:hover { background: rgba(124,58,237,.18); color: #fff; border-color: rgba(124,58,237,.3); }
.win-cat-btn.active { background: linear-gradient(135deg, rgba(109,76,255,.45), rgba(124,58,237,.35)); color: #fff; border-color: rgba(124,58,237,.5); }

/* Items Panel */
.pos-window-items {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    border-right: 1px solid rgba(148,163,184,.10);
    overflow: hidden;
}
.win-items-toolbar {
    padding: 10px 10px 8px;
    flex: 0 0 auto;
    border-bottom: 1px solid rgba(148,163,184,.08);
}
.win-search {
    width: 100%;
    height: 38px;
    border-radius: var(--radius-sm);
    background: rgba(7,11,22,.72) !important;
    color: var(--clr-text) !important;
    border: 1px solid rgba(148,163,184,.18) !important;
    font-weight: 700 !important;
    padding: 0 12px;
    font-size: 13px;
}
.win-search::placeholder { color: var(--clr-muted); }
.win-search:focus { outline: none; border-color: rgba(124,58,237,.5) !important; }

.win-items-grid {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 8px;
    align-content: start;
}
.win-item-card {
    background: linear-gradient(135deg, rgba(13,19,38,.80), rgba(20,28,56,.52));
    border: 1px solid rgba(148,163,184,.12);
    border-radius: 13px;
    overflow: hidden;
    transition: all .18s;
    cursor: pointer;
    display: flex;
    flex-direction: column;
}
.win-item-card:hover {
    border-color: rgba(124,58,237,.42);
    box-shadow: 0 14px 35px rgba(0,0,0,.3), 0 0 20px rgba(124,58,237,.10);
    transform: translateY(-1px);
}
.win-item-card.hidden { display: none; }
.win-item-img {
    height: 78px;
    background: radial-gradient(circle at 30% 10%, rgba(124,58,237,.3), transparent 45%), linear-gradient(135deg, #0d1326, #0a0f1e);
    display: flex; align-items: center; justify-content: center;
    color: rgba(124,58,237,.7); font-size: 24px;
    flex: 0 0 78px;
}
.win-item-img img { width: 100%; height: 100%; object-fit: cover; }
.win-item-body { padding: 8px; flex: 1; display: flex; flex-direction: column; }
.win-item-name {
    font-size: 12px; font-weight: 800; color: #fff;
    line-height: 1.25; min-height: 30px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.win-item-cat { font-size: 10px; color: var(--clr-muted); font-weight: 700; margin-top: 3px; }
.win-item-price { font-size: 13px; font-weight: 900; color: #fff; margin-top: 5px; }
.win-item-actions { display: grid; grid-template-columns: 1fr 34px; gap: 5px; margin-top: 7px; }
.win-add-btn, .win-note-btn {
    height: 30px; border: 0; border-radius: 8px;
    color: #fff; font-weight: 800; font-size: 11px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 4px;
    transition: opacity .13s;
}
.win-add-btn { background: linear-gradient(135deg, #059669, #10b981); }
.win-add-btn:hover { opacity: .88; }
.win-note-btn { background: rgba(15,23,42,.72); border: 1px solid rgba(148,163,184,.18); }
.win-note-btn:hover { background: rgba(124,58,237,.25); }

/* Cart Panel */
.pos-window-cart {
    width: 320px;
    min-width: 300px;
    flex: 0 0 320px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: rgba(7,11,22,.38);
}
.win-cart-head {
    padding: 12px 14px;
    border-bottom: 1px solid rgba(148,163,184,.10);
    flex: 0 0 auto;
}
.win-cart-head h6 {
    font-size: 14px; font-weight: 900; margin: 0; color: #fff;
    display: flex; align-items: center; gap: 7px;
}
.win-cart-head small { font-size: 11px; color: var(--clr-muted); font-weight: 600; }

.win-cart-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 10px;
}

/* Cart lines */
.win-cart-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; color: var(--clr-muted); gap: 7px; min-height: 140px; padding: 20px 0;
}
.win-cart-empty i { font-size: 36px; color: rgba(124,58,237,.5); }
.win-cart-empty strong { color: #e5e7eb; font-size: 13px; }

.win-cart-line {
    background: rgba(12,17,32,.65);
    border: 1px solid rgba(148,163,184,.12);
    border-radius: 12px;
    padding: 9px;
    margin-bottom: 7px;
}
.win-cart-line.is-cancelled { opacity: .6; }
.wcl-top { display: flex; justify-content: space-between; gap: 8px; }
.wcl-name strong { display: block; font-size: 12px; color: #fff; font-weight: 800; line-height: 1.25; }
.wcl-name small { display: block; color: var(--clr-muted); font-size: 10px; margin-top: 2px; font-weight: 600; }
.wcl-total { font-weight: 900; color: #fff; font-size: 12px; white-space: nowrap; }
.wcl-badges { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 6px; }
.kot-badge, .item-status-badge {
    border-radius: 999px; padding: 3px 8px; font-size: 10px; font-weight: 800;
}
.kot-badge.pending { background: rgba(245,158,11,.15); color: #fde68a; }
.kot-badge.sent { background: rgba(59,130,246,.15); color: #bfdbfe; }
.kot-badge.ready { background: rgba(16,185,129,.16); color: #bbf7d0; }

/* Cart item KOT / Ready checkbox toggle */
.kot-line-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 10px;
    font-weight: 900;
    cursor: pointer;
    user-select: none;
    border: 1px solid rgba(148,163,184,.18);
    line-height: 1.2;
}
.kot-line-toggle input {
    width: 13px;
    height: 13px;
    margin: 0;
    accent-color: #10b981;
    cursor: pointer;
}
.kot-line-toggle.kot {
    background: rgba(245,158,11,.15);
    color: #fde68a;
}
.kot-line-toggle.ready {
    background: rgba(16,185,129,.16);
    color: #bbf7d0;
}
.kot-line-toggle input:disabled {
    opacity: .55;
    cursor: not-allowed;
}
.kot-line-toggle:has(input:disabled) {
    opacity: .82;
    cursor: not-allowed;
}
.item-status-badge { background: rgba(148,163,184,.12); color: var(--clr-subtle); }
.wcl-actions { display: flex; align-items: center; gap: 5px; margin-top: 7px; }
.wc-qty-btn, .wc-rm-btn {
    width: 28px; height: 28px; border-radius: 8px;
    border: 1px solid rgba(148,163,184,.18); background: rgba(15,23,42,.75);
    color: #fff; font-weight: 900; font-size: 13px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.wc-qty-btn:hover { background: rgba(124,58,237,.3); }
.wc-qty-btn:disabled { opacity: .4; cursor: default; }
.wc-qty-input {
    width: 44px; height: 28px; border-radius: 8px;
    border: 1px solid rgba(148,163,184,.18); background: rgba(2,6,23,.7);
    color: #fff; text-align: center; font-weight: 900; font-size: 12px;
}
.wc-rm-btn { margin-left: auto; background: rgba(220,38,38,.18); color: #fca5a5; }
.wc-rm-btn:hover { background: rgba(220,38,38,.38); }
.wcl-cancel-reason { margin-top: 6px; color: #fca5a5; font-size: 11px; font-weight: 700; }

/* Cart Summary */
.win-cart-summary {
    padding: 10px 12px;
    border-top: 1px solid rgba(148,163,184,.10);
    background: rgba(4,7,16,.45);
    flex: 0 0 auto;
}
.win-sum-row {
    display: flex; justify-content: space-between; gap: 8px;
    font-size: 12px; font-weight: 700; color: var(--clr-subtle); margin-bottom: 5px;
}
.win-sum-row strong { font-weight: 800; }
.win-sum-row.grand {
    color: #fff; font-size: 15px; font-weight: 900;
    border-top: 1px dashed rgba(148,163,184,.18);
    padding-top: 8px; margin-top: 6px; margin-bottom: 0;
}

/* Cart Actions */
.win-cart-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 7px;
    padding: 9px 10px 10px;
    flex: 0 0 auto;
    border-top: 1px solid rgba(148,163,184,.08);
}
.win-cart-actions .wide { grid-column: span 2; }
.win-cart-actions .btn-glow {
    height: 36px;
    min-height: 36px;
    font-size: 11.5px;
    padding: 0 10px;
    border-radius: 9px;
}

/* Resize handle */
.resize-handle {
    position: absolute;
    right: 0; bottom: 0;
    width: 22px; height: 22px;
    cursor: se-resize;
    display: flex; align-items: flex-end; justify-content: flex-end;
    padding: 4px;
    z-index: 10;
}
.resize-handle::after {
    content: '';
    width: 10px; height: 10px;
    border-right: 2px solid rgba(148,163,184,.3);
    border-bottom: 2px solid rgba(148,163,184,.3);
    border-radius: 2px;
}

/* ============================================================
   EMPTY WORKSPACE
   ============================================================ */
.empty-workspace {
    min-height: calc(100vh - var(--pos-topbar-h) - var(--pos-mini-h) - 180px);
    border: 1px dashed rgba(148,163,184,.20);
    border-radius: 20px;
    background: rgba(13,19,38,.24);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: var(--clr-muted);
    padding: 30px;
    transition: opacity .3s;
}
.empty-workspace i { font-size: 52px; color: rgba(124,58,237,.45); margin-bottom: 14px; }
.empty-workspace strong { font-size: 20px; color: #fff; font-weight: 900; display: block; margin-bottom: 8px; }
.empty-workspace span { font-size: 14px; max-width: 380px; line-height: 1.6; }

/* ============================================================
   MINIMIZED TASKBAR
   ============================================================ */
.minimized-bills-bar {
    position: fixed;
    left: var(--sidebar-width, 0);
    right: 0;
    bottom: 0;
    height: var(--pos-mini-h);
    background: linear-gradient(135deg, rgba(5,9,20,.97), rgba(10,16,32,.95));
    border-top: 1px solid rgba(124,58,237,.18);
    box-shadow: 0 -14px 40px rgba(0,0,0,.42);
    z-index: 1040;
    display: none;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    backdrop-filter: blur(22px);
    overflow: hidden;
}
.minimized-bills-bar.show { display: flex; }
.min-bar-label {
    display: flex; align-items: center; gap: 8px;
    color: #fb923c; font-weight: 900; font-size: 13px;
    min-width: max-content;
    white-space: nowrap;
}
.min-bar-list {
    display: flex; gap: 8px; overflow-x: auto;
    flex: 1; align-items: center;
}

.mini-pos-tab {
    flex: 0 0 auto;
    min-width: 200px; max-width: 230px;
    height: 50px;
    border: 1px solid rgba(148,163,184,.16);
    background: linear-gradient(135deg, rgba(25,35,65,.78), rgba(13,19,38,.68));
    border-radius: 12px;
    display: flex; align-items: center; gap: 9px;
    padding: 0 10px;
    cursor: pointer;
    transition: all .15s;
}
.mini-pos-tab:hover { border-color: rgba(124,58,237,.45); background: rgba(109,76,255,.18); }
.mini-tab-num {
    font-size: 22px; color: #60a5fa; font-weight: 950;
    line-height: 1; flex: 0 0 auto;
}
.mini-tab-info { min-width: 0; flex: 1; }
.mini-tab-info strong { display: block; color: #fff; font-size: 12px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mini-tab-info span { display: block; color: var(--clr-subtle); font-size: 11px; font-weight: 700; white-space: nowrap; }
.mini-tab-close {
    width: 24px; height: 24px; border-radius: 7px;
    border: 1px solid rgba(148,163,184,.15);
    background: rgba(15,23,42,.62); color: var(--clr-muted);
    font-size: 11px; cursor: pointer; flex: 0 0 auto;
    display: flex; align-items: center; justify-content: center;
    transition: all .12s;
}
.mini-tab-close:hover { background: rgba(220,38,38,.3); color: #fca5a5; }

/* ============================================================
   TOAST
   ============================================================ */
.toast-box {
    position: fixed;
    right: 20px;
    bottom: calc(var(--pos-mini-h) + 14px);
    z-index: 9999;
    display: grid;
    gap: 8px;
}
.toast-msg {
    background: rgba(10,15,30,.97);
    border: 1px solid rgba(148,163,184,.18);
    border-radius: 12px;
    color: #fff;
    padding: 10px 14px;
    box-shadow: 0 14px 40px rgba(0,0,0,.38);
    font-weight: 800;
    font-size: 13px;
    animation: toastIn .2s ease;
}
.toast-msg.ok { border-color: rgba(16,185,129,.35); }
.toast-msg.err { border-color: rgba(239,68,68,.35); }
@keyframes toastIn { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform:translateY(0); } }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 900px) {
    .pos-floating-window {
        position: fixed !important;
        top: calc(var(--pos-topbar-h) + 4px) !important;
        left: 4px !important;
        right: 4px !important;
        bottom: calc(var(--pos-mini-h) + 4px) !important;
        width: auto !important;
        height: auto !important;
        min-width: 0 !important;
        min-height: 0 !important;
        border-radius: 14px;
    }
    .pos-window-categories { width: 110px; min-width: 110px; flex: 0 0 110px; }
    .pos-window-cart { width: 260px; min-width: 240px; flex: 0 0 260px; }
    .win-items-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
    .tables-dashboard-grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
}

@media (max-width: 600px) {
    .pos-floating-window { border-radius: 10px; }
    .pos-window-body { flex-direction: column; }
    .pos-window-categories { width: 100%; flex: 0 0 auto; height: auto; flex-direction: row; flex-wrap: wrap; overflow-x: auto; overflow-y: visible; padding: 6px; }
    .win-cat-title { display: none; }
    .win-cat-btn { width: auto; white-space: nowrap; }
    .pos-window-items { flex: 1; min-height: 200px; }
    .pos-window-cart { width: 100%; flex: 0 0 auto; min-width: 0; max-height: 260px; }
    .tables-dashboard-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
    .minimized-bills-bar { left: 0 !important; }
}

/* ============================================================
   RESTAURANT POS UI FIX PATCH
   Paste at the END of current 
   ============================================================ */

/* Full page dark background fix */
html,
body.restaurant-pos-page,
.restaurant-pos-page .container-scroll,
.restaurant-pos-page .main-panel,
.restaurant-pos-page .content-wrapper {
    background:
        radial-gradient(circle at 15% 10%, rgba(124,58,237,.22), transparent 30%),
        radial-gradient(circle at 90% 5%, rgba(59,130,246,.12), transparent 28%),
        linear-gradient(135deg, #050816 0%, #080d1f 45%, #120a24 100%) !important;
    color: #f8fafc !important;
}

/* Main content spacing */
.restaurant-pos-page .main-panel {
    padding-top: var(--pos-topbar-h) !important;
}

.restaurant-pos-page .content-wrapper {
    padding: 18px 18px calc(var(--pos-mini-h) + 26px) !important;
    min-height: calc(100vh - var(--pos-topbar-h)) !important;
    overflow-x: hidden !important;
}

/* Header alignment */
.pos-page-header {
    margin-bottom: 16px !important;
    align-items: center !important;
}

.pos-page-title h3 {
    font-size: 24px !important;
    line-height: 1.1 !important;
    margin: 0 !important;
}

.pos-page-title p {
    font-size: 12px !important;
    margin-top: 5px !important;
}

.head-actions {
    margin-left: auto !important;
}

.head-actions .btn-glow {
    height: 40px !important;
    min-height: 40px !important;
    padding: 0 16px !important;
    border-radius: 12px !important;
    background: rgba(15,23,42,.78) !important;
    border: 1px solid rgba(148,163,184,.20) !important;
    box-shadow: 0 10px 24px rgba(0,0,0,.20) !important;
}

/* Table cards grid fixed */
.tables-dashboard-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)) !important;
    gap: 16px !important;
    margin-bottom: 18px !important;
    align-items: stretch !important;
}

/* Better table card */
.pos-table-card {
    min-height: 272px !important;
    padding: 18px 16px 16px !important;
    border-radius: 18px !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 38%),
        linear-gradient(145deg, rgba(25,32,55,.92), rgba(50,57,78,.86)) !important;
    border: 1px solid rgba(255,255,255,.12) !important;
    box-shadow:
        0 18px 36px rgba(0,0,0,.32),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    display: flex !important;
    flex-direction: column !important;
}

.pos-table-card:hover {
    transform: translateY(-3px) !important;
    border-color: rgba(124,58,237,.55) !important;
    box-shadow:
        0 22px 48px rgba(0,0,0,.42),
        0 0 0 1px rgba(124,58,237,.25) !important;
}

.ptc-top {
    margin-bottom: 13px !important;
}

.ptc-num {
    font-size: 36px !important;
    color: #fb923c !important;
    min-width: 58px !important;
}

.ptc-name {
    font-size: 14px !important;
    line-height: 1.2 !important;
}

.ptc-floor {
    font-size: 11px !important;
    color: #cbd5e1 !important;
}

.ptc-meta {
    gap: 7px !important;
    margin-bottom: 12px !important;
}

.ptc-meta span {
    font-size: 12px !important;
    line-height: 1.25 !important;
}

.pos-table-card .st-minimized,
.pos-table-card .st-kot,
.pos-table-card .st-occupied,
.pos-table-card .st-bill,
.pos-table-card .st-available,
.pos-table-card .st-reserved,
.pos-table-card .st-dirty,
.pos-table-card .st-muted {
    width: max-content !important;
    max-width: 100% !important;
    padding: 5px 11px !important;
    margin-bottom: 12px !important;
    font-size: 11px !important;
}

.ptc-bill {
    margin-top: auto !important;
    margin-bottom: 14px !important;
    font-size: 19px !important;
    line-height: 1.15 !important;
}

.ptc-bill small {
    margin-bottom: 5px !important;
    color: #aeb8c8 !important;
}

.ptc-open-btn {
    height: 36px !important;
    min-height: 36px !important;
    border-radius: 10px !important;
    margin-top: 0 !important;
    background: linear-gradient(135deg, #7c3aed, #6d4cff) !important;
    box-shadow: 0 12px 26px rgba(124,58,237,.28) !important;
}

/* Empty workspace dark + smaller */
.empty-workspace {
    min-height: 150px !important;
    height: 150px !important;
    padding: 20px !important;
    border-radius: 18px !important;
    background:
        radial-gradient(circle at 50% 0%, rgba(124,58,237,.18), transparent 45%),
        rgba(15,23,42,.46) !important;
    border: 1px dashed rgba(148,163,184,.22) !important;
    color: #94a3b8 !important;
    margin-top: 4px !important;
}

.empty-workspace i {
    font-size: 36px !important;
    margin-bottom: 8px !important;
}

.empty-workspace strong {
    font-size: 16px !important;
    margin-bottom: 4px !important;
}

.empty-workspace span {
    font-size: 12px !important;
    max-width: 520px !important;
}

/* Bottom minimized bar clean alignment */
.minimized-bills-bar {
    height: 68px !important;
    padding: 8px 16px !important;
    background: linear-gradient(135deg, rgba(8,13,29,.98), rgba(13,19,38,.97)) !important;
    border-top: 1px solid rgba(124,58,237,.24) !important;
    box-shadow: 0 -18px 45px rgba(0,0,0,.45) !important;
}

.min-bar-label {
    min-width: 112px !important;
    font-size: 14px !important;
}

.min-bar-list {
    gap: 8px !important;
    padding-bottom: 2px !important;
}

.mini-pos-tab {
    height: 50px !important;
    min-width: 200px !important;
    max-width: 210px !important;
    border-radius: 12px !important;
    padding: 0 10px !important;
    background: linear-gradient(135deg, rgba(20,31,58,.92), rgba(11,17,33,.86)) !important;
    border: 1px solid rgba(148,163,184,.18) !important;
}

.mini-tab-num {
    font-size: 24px !important;
}

.mini-tab-info strong {
    font-size: 12px !important;
}

.mini-tab-info span {
    font-size: 11px !important;
}

/* Floating window should not hide under topbar/taskbar */
.pos-floating-window.window-maximized {
    top: calc(var(--pos-topbar-h) + 10px) !important;
    left: 10px !important;
    right: 10px !important;
    bottom: calc(var(--pos-mini-h) + 10px) !important;
    width: calc(100vw - 20px) !important;
    height: calc(100vh - var(--pos-topbar-h) - var(--pos-mini-h) - 20px) !important;
}

/* Desktop 5 cards row support */
@media (min-width: 1200px) {
    .tables-dashboard-grid {
        grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
    }
}

/* Medium screens */
@media (max-width: 1199px) {
    .tables-dashboard-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) !important;
    }
}

/* Mobile/tablet cleanup */
@media (max-width: 768px) {
    .restaurant-pos-page .content-wrapper {
        padding: 14px 12px calc(var(--pos-mini-h) + 18px) !important;
    }

    .pos-page-header {
        gap: 12px !important;
    }

    .head-actions {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: 1fr 1fr 1fr !important;
        gap: 8px !important;
    }

    .head-actions .btn-glow {
        width: 100% !important;
        padding: 0 8px !important;
        font-size: 11px !important;
    }

    .tables-dashboard-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px !important;
    }

    .pos-table-card {
        min-height: 238px !important;
        padding: 14px 12px !important;
    }

    .ptc-num {
        font-size: 30px !important;
        min-width: 45px !important;
    }

    .ptc-bill {
        font-size: 16px !important;
    }

    .minimized-bills-bar {
        left: 0 !important;
        padding: 8px 10px !important;
    }

    .min-bar-label {
        min-width: 92px !important;
        font-size: 12px !important;
    }

    .mini-pos-tab {
        min-width: 170px !important;
        max-width: 180px !important;
    }
}

@media (max-width: 480px) {
    .tables-dashboard-grid {
        grid-template-columns: 1fr !important;
    }
}


/* ============================================================
   POS FLOATING WINDOW SMALL SIZE ITEM CARD BUTTON FIX
   Paste at END of 
   ============================================================ */

/* Keep the item area clean in small/normal floating windows */
.pos-floating-window:not(.window-maximized) .pos-window-body {
    overflow: hidden !important;
}

/* Show two properly sized cards instead of three cramped cards in small floating windows */
.pos-floating-window:not(.window-maximized) .win-items-grid {
    grid-template-columns: repeat(2, minmax(165px, 1fr)) !important;
    gap: 12px !important;
    padding: 10px !important;
    align-content: start !important;
    overflow-y: auto !important;
}

/* Card height fix: proper space for buttons */
.pos-floating-window:not(.window-maximized) .win-item-card {
    min-height: 218px !important;
    height: 218px !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Compact image area so content and buttons remain visible */
.pos-floating-window:not(.window-maximized) .win-item-img {
    height: 82px !important;
    flex: 0 0 82px !important;
}

/* Body fixed flex layout */
.pos-floating-window:not(.window-maximized) .win-item-body {
    padding: 9px !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Fixed item name area so long text does not push the button down */
.pos-floating-window:not(.window-maximized) .win-item-name {
    font-size: 12px !important;
    line-height: 1.2 !important;
    min-height: 30px !important;
    max-height: 30px !important;
    overflow: hidden !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
}

/* Category compact */
.pos-floating-window:not(.window-maximized) .win-item-cat {
    font-size: 10px !important;
    margin-top: 5px !important;
    min-height: 14px !important;
    max-height: 14px !important;
    overflow: hidden !important;
}

/* Price compact */
.pos-floating-window:not(.window-maximized) .win-item-price {
    font-size: 13px !important;
    line-height: 1.1 !important;
    margin-top: 6px !important;
    margin-bottom: 8px !important;
    flex: 0 0 auto !important;
}

/* Keep buttons visible at the bottom of the card */
.pos-floating-window:not(.window-maximized) .win-item-actions {
    margin-top: auto !important;
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) 34px !important;
    gap: 7px !important;
    height: 32px !important;
    min-height: 32px !important;
    flex: 0 0 32px !important;
    overflow: visible !important;
}

/* Add + note buttons full visible */
.pos-floating-window:not(.window-maximized) .win-add-btn,
.pos-floating-window:not(.window-maximized) .win-note-btn {
    height: 32px !important;
    min-height: 32px !important;
    border-radius: 9px !important;
    font-size: 11px !important;
    line-height: 1 !important;
    padding: 0 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    white-space: nowrap !important;
}

/* Note icon button width fixed */
.pos-floating-window:not(.window-maximized) .win-note-btn {
    width: 34px !important;
    min-width: 34px !important;
    padding: 0 !important;
}

/* Compact cart so the items panel gets more width */
.pos-floating-window:not(.window-maximized) .pos-window-categories {
    width: 140px !important;
    min-width: 140px !important;
    flex: 0 0 140px !important;
}

.pos-floating-window:not(.window-maximized) .pos-window-cart {
    width: 310px !important;
    min-width: 310px !important;
    flex: 0 0 310px !important;
}

/* Search spacing */
.pos-floating-window:not(.window-maximized) .win-items-toolbar {
    padding: 10px 10px 8px !important;
}

.pos-floating-window:not(.window-maximized) .win-search {
    height: 38px !important;
}

/* Keep maximized window layout unchanged */
.pos-floating-window.window-maximized .win-items-grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)) !important;
    gap: 12px !important;
}

.pos-floating-window.window-maximized .win-item-card {
    min-height: 206px !important;
    height: 206px !important;
}

.pos-floating-window.window-maximized .win-item-actions {
    margin-top: auto !important;
}

/* Extra adjustments for smaller window sizes */
@media (max-width: 1100px) {
    .pos-floating-window:not(.window-maximized) .win-items-grid {
        grid-template-columns: repeat(2, minmax(150px, 1fr)) !important;
    }

    .pos-floating-window:not(.window-maximized) .pos-window-categories {
        width: 120px !important;
        min-width: 120px !important;
        flex: 0 0 120px !important;
    }

    .pos-floating-window:not(.window-maximized) .pos-window-cart {
        width: 290px !important;
        min-width: 290px !important;
        flex: 0 0 290px !important;
    }

    .pos-floating-window:not(.window-maximized) .win-item-card {
        height: 214px !important;
        min-height: 214px !important;
    }
}


/* ================= ORDER TYPE QUICK ACTIONS ================= */
.order-type-actions{
    flex:1 1 430px !important;
    display:flex !important;
    justify-content:center !important;
    align-items:center !important;
    gap:10px !important;
    min-width:360px !important;
}
.restaurant-pos-page .head-actions{ margin-left:0 !important; }
.order-type-btn{
    height:44px !important;
    min-width:132px !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:14px !important;
    color:#fff !important;
    font-size:13px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    cursor:pointer !important;
    box-shadow:0 14px 34px rgba(0,0,0,.26), inset 0 1px 0 rgba(255,255,255,.10) !important;
    transition:.18s ease !important;
}
.order-type-btn:hover{ transform:translateY(-2px) !important; filter:brightness(1.08) !important; }
.order-type-btn.dine{ background:linear-gradient(135deg,#6d4cff,#7c3aed) !important; border-color:rgba(139,92,246,.48) !important; }
.order-type-btn.takeaway{ background:linear-gradient(135deg,#059669,#10b981) !important; border-color:rgba(16,185,129,.45) !important; }
.order-type-btn.delivery{ background:linear-gradient(135deg,#f97316,#f59e0b) !important; border-color:rgba(245,158,11,.48) !important; }
.order-type-btn i{ font-size:16px !important; }

/* Dark modal for Dine In / Takeaway / Delivery */
.restaurant-pos-page .modal-content{
    background:radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%), linear-gradient(135deg, rgba(10,15,31,.98), rgba(17,24,39,.94)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:20px !important;
    box-shadow:0 28px 80px rgba(0,0,0,.64) !important;
}
.restaurant-pos-page .modal-header,
.restaurant-pos-page .modal-footer{ border-color:rgba(148,163,184,.16) !important; }
.restaurant-pos-page .modal-title{ font-weight:950 !important; color:#fff !important; }
.restaurant-pos-page .btn-close{ filter:invert(1) !important; opacity:1 !important; }
.restaurant-pos-page .modal label{
    color:#dbeafe !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:6px !important;
}
.restaurant-pos-page .modal .form-control,
.restaurant-pos-page .modal .form-select{
    min-height:44px !important;
    border-radius:13px !important;
    background:rgba(7,11,22,.84) !important;
    color:#fff !important;
    border:1px solid rgba(148,163,184,.22) !important;
    font-weight:800 !important;
}
.restaurant-pos-page .modal .form-control::placeholder{ color:#94a3b8 !important; }
@media(max-width:991px){
    .order-type-actions{
        order:3 !important;
        flex:1 1 100% !important;
        width:100% !important;
        min-width:0 !important;
        display:grid !important;
        grid-template-columns:repeat(3,minmax(0,1fr)) !important;
        gap:8px !important;
    }
    .order-type-btn{ min-width:0 !important; width:100% !important; font-size:12px !important; padding:0 8px !important; }
}
@media(max-width:576px){ .order-type-actions{ grid-template-columns:1fr !important; } }

/* ================= POS LANDING WORKSPACE: MENU + CART ================= */
.pos-main-workspace{
    display:grid !important;
    grid-template-columns:minmax(0, 1.65fr) 390px !important;
    gap:18px !important;
    align-items:stretch !important;
    min-height:calc(100vh - var(--pos-topbar-h) - var(--pos-mini-h) - 130px) !important;
}

.pos-menu-panel,
.pos-cart-placeholder{
    border-color:rgba(124,58,237,.22) !important;
    overflow:hidden !important;
}

.pos-menu-panel{
    display:flex !important;
    flex-direction:column !important;
    min-height:520px !important;
}

.landing-panel-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    padding:16px !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    background:linear-gradient(135deg, rgba(124,58,237,.12), rgba(59,130,246,.05)) !important;
}

.landing-panel-head h5,
.cart-placeholder-top h5{
    margin:0 !important;
    color:#fff !important;
    font-weight:950 !important;
    letter-spacing:-.02em !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
}

.landing-panel-head h5 i{ color:#fb923c !important; }
.landing-panel-head p,
.cart-placeholder-top p{
    margin:4px 0 0 !important;
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:700 !important;
    line-height:1.55 !important;
}

.landing-search-wrap{
    width:min(360px, 45%) !important;
    min-width:250px !important;
    height:42px !important;
    border-radius:13px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(7,11,22,.72) !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    padding:0 12px !important;
}

.landing-search-wrap i{ color:#94a3b8 !important; }
.landing-search-wrap input{
    border:0 !important;
    outline:0 !important;
    background:transparent !important;
    color:#fff !important;
    width:100% !important;
    font-size:13px !important;
    font-weight:800 !important;
}
.landing-search-wrap input::placeholder{ color:#94a3b8 !important; }

.landing-category-strip{
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    padding:12px 16px !important;
    overflow-x:auto !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
}

.landing-cat{
    height:34px !important;
    padding:0 14px !important;
    border-radius:999px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:rgba(15,23,42,.68) !important;
    color:#cbd5e1 !important;
    font-size:12px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
    cursor:pointer !important;
}
.landing-cat:hover,
.landing-cat.active{
    color:#fff !important;
    background:linear-gradient(135deg, rgba(109,76,255,.55), rgba(124,58,237,.38)) !important;
    border-color:rgba(124,58,237,.50) !important;
}

.landing-items-grid{
    flex:1 !important;
    overflow:auto !important;
    padding:16px !important;
    display:grid !important;
    grid-template-columns:repeat(auto-fill, minmax(165px, 1fr)) !important;
    gap:13px !important;
    align-content:start !important;
}

.landing-item-card{
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.13) !important;
    background:linear-gradient(145deg, rgba(15,23,42,.82), rgba(30,41,70,.58)) !important;
    overflow:hidden !important;
    box-shadow:0 14px 34px rgba(0,0,0,.24) !important;
    display:flex !important;
    flex-direction:column !important;
    min-height:235px !important;
}
.landing-item-card.hidden{ display:none !important; }
.landing-item-img{
    height:90px !important;
    flex:0 0 90px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:radial-gradient(circle at 30% 10%, rgba(124,58,237,.35), transparent 45%), linear-gradient(135deg, #0d1326, #0a0f1e) !important;
    color:rgba(124,58,237,.72) !important;
    font-size:28px !important;
}
.landing-item-img img{ width:100% !important; height:100% !important; object-fit:cover !important; }
.landing-item-body{
    padding:11px !important;
    display:flex !important;
    flex-direction:column !important;
    gap:5px !important;
    flex:1 !important;
}
.landing-item-body strong{
    color:#fff !important;
    font-size:13px !important;
    font-weight:950 !important;
    line-height:1.25 !important;
    min-height:34px !important;
}
.landing-item-body span{
    color:#94a3b8 !important;
    font-size:11px !important;
    font-weight:800 !important;
}
.landing-item-body b{
    color:#fff !important;
    font-size:14px !important;
    font-weight:950 !important;
    margin-top:2px !important;
}
.landing-item-body button{
    margin-top:auto !important;
    height:34px !important;
    border:0 !important;
    border-radius:10px !important;
    background:linear-gradient(135deg, #059669, #10b981) !important;
    color:#fff !important;
    font-size:11px !important;
    font-weight:900 !important;
    cursor:pointer !important;
}

.landing-empty-menu{
    grid-column:1/-1 !important;
    min-height:260px !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:center !important;
    text-align:center !important;
    color:#94a3b8 !important;
    gap:8px !important;
}
.landing-empty-menu i{ font-size:42px !important; color:rgba(124,58,237,.55) !important; }
.landing-empty-menu strong{ color:#fff !important; font-weight:950 !important; }

.pos-cart-placeholder{
    min-height:520px !important;
    display:flex !important;
    flex-direction:column !important;
    padding:18px !important;
    background:radial-gradient(circle at 50% 0%, rgba(124,58,237,.18), transparent 42%), linear-gradient(135deg, rgba(13,19,38,.82), rgba(15,22,42,.62)) !important;
}

.cart-placeholder-top{
    text-align:center !important;
    padding:20px 10px 18px !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}
.cart-placeholder-icon{
    width:72px !important;
    height:72px !important;
    border-radius:22px !important;
    margin:0 auto 14px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    color:#a78bfa !important;
    font-size:34px !important;
    background:rgba(124,58,237,.16) !important;
    border:1px solid rgba(124,58,237,.32) !important;
    box-shadow:0 18px 40px rgba(124,58,237,.12) !important;
}

.quick-order-cards{
    display:grid !important;
    gap:12px !important;
    padding:18px 0 !important;
}
.quick-order-card{
    width:100% !important;
    min-height:82px !important;
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:rgba(15,23,42,.70) !important;
    color:#fff !important;
    display:grid !important;
    grid-template-columns:48px 1fr !important;
    grid-template-rows:auto auto !important;
    column-gap:12px !important;
    align-items:center !important;
    text-align:left !important;
    padding:14px !important;
    cursor:pointer !important;
    transition:.18s ease !important;
}
.quick-order-card:hover{ transform:translateY(-2px) !important; filter:brightness(1.06) !important; border-color:rgba(124,58,237,.42) !important; }
.quick-order-card i{
    grid-row:1/3 !important;
    width:48px !important;
    height:48px !important;
    border-radius:14px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:22px !important;
}
.quick-order-card strong{ font-size:15px !important; font-weight:950 !important; }
.quick-order-card span{ color:#cbd5e1 !important; font-size:12px !important; font-weight:700 !important; }
.quick-order-card.dine i{ background:rgba(124,58,237,.18) !important; color:#c4b5fd !important; }
.quick-order-card.takeaway i{ background:rgba(16,185,129,.16) !important; color:#bbf7d0 !important; }
.quick-order-card.delivery i{ background:rgba(249,115,22,.18) !important; color:#fed7aa !important; }

.running-summary-box{
    margin-top:auto !important;
    display:grid !important;
    grid-template-columns:repeat(3,1fr) !important;
    gap:8px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    padding-top:16px !important;
}
.running-summary-box div{
    border-radius:13px !important;
    padding:12px 8px !important;
    text-align:center !important;
    background:rgba(7,11,22,.62) !important;
    border:1px solid rgba(148,163,184,.12) !important;
}
.running-summary-box span{
    display:block !important;
    color:#94a3b8 !important;
    font-size:10px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}
.running-summary-box strong{
    display:block !important;
    color:#fff !important;
    font-size:22px !important;
    font-weight:950 !important;
    margin-top:4px !important;
}

@media(max-width:1100px){
    .pos-main-workspace{ grid-template-columns:1fr !important; }
    .pos-cart-placeholder{ min-height:auto !important; }
    .landing-search-wrap{ width:100% !important; min-width:0 !important; }
    .landing-panel-head{ flex-direction:column !important; align-items:stretch !important; }
}
@media(max-width:640px){
    .landing-items-grid{ grid-template-columns:repeat(2, minmax(0,1fr)) !important; gap:10px !important; padding:12px !important; }
    .running-summary-box{ grid-template-columns:1fr !important; }
}

/* ============================================================
   FIX: CATEGORY FILTER WORK IN SMALL + MAXIMIZED POS WINDOWS
   Issue: small window .win-item-card display:flex!important
   hidden class ko override kar raha tha.
============================================================ */

.pos-floating-window .win-item-card.hidden,
.pos-floating-window:not(.window-maximized) .win-item-card.hidden,
.pos-floating-window.window-maximized .win-item-card.hidden {
    display: none !important;
}

.landing-item-card.hidden {
    display: none !important;
}

</style>
</head>
<body class="restaurant-pos-page">
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?>
    </div>
    <div class="main-panel">
        <?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?>
        <div class="content-wrapper">

            <?php if($errorMessage): ?>
                <div class="alert-glass"><i class="bi bi-exclamation-triangle"></i> <?= e($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="pos-page-header">
                <div class="pos-page-title">
                    <h3><i class="bi bi-grid-3x3-gap-fill" style="color:#7c3aed;margin-right:8px;"></i>Restaurant POS</h3>
                    <p>Start a Dine In, Takeaway, or Delivery order. Each order will open in its own floating POS window.</p>
                </div>
                <div class="order-type-actions">
                    <button class="order-type-btn dine" type="button" onclick="openOrderModal('dine_in')">
                        <i class="bi bi-shop-window"></i><span>Dine In</span>
                    </button>
                    <button class="order-type-btn takeaway" type="button" onclick="openOrderModal('takeaway')">
                        <i class="bi bi-bag-check"></i><span>Takeaway</span>
                    </button>
                    <button class="order-type-btn delivery" type="button" onclick="openOrderModal('delivery')">
                        <i class="bi bi-scooter"></i><span>Delivery</span>
                    </button>
                </div>
                <div class="head-actions">
                    <a class="btn-glow btn-soft" href="dashboard.php"><i class="bi bi-house"></i> Dashboard</a>
                    <a class="btn-glow btn-soft" href="restaurant_tables.php"><i class="bi bi-plus-lg"></i> Add Table</a>
                    <button class="btn-glow btn-soft" type="button" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                </div>
            </div>

            <!-- POS Landing Workspace: Menu + Cart Placeholder -->
            <div class="pos-main-workspace" id="posMainWorkspace">
                <div class="pos-menu-panel glass-card">
                    <div class="landing-panel-head">
                        <div>
                            <h5><i class="bi bi-cup-hot"></i> Menu Items</h5>
                            <p>After starting an order, add items from the floating POS window.</p>
                        </div>
                        <div class="landing-search-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" id="landingMenuSearch" placeholder="Search menu..." oninput="filterLandingMenu()">
                        </div>
                    </div>

                    <div class="landing-category-strip" id="landingCategoryStrip">
                        <button type="button" class="landing-cat active" data-cat="all" onclick="filterLandingMenu('all', this)">All</button>
                        <?php foreach ($categories as $cat): ?>
                            <button type="button" class="landing-cat" data-cat="<?= e(strtolower($cat)) ?>" onclick="filterLandingMenu('<?= e(strtolower($cat)) ?>', this)">
                                <?= e($cat) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="landing-items-grid" id="landingItemsGrid">
                        <?php if (empty($items)): ?>
                            <div class="landing-empty-menu">
                                <i class="bi bi-cup-hot"></i>
                                <strong>No menu items found</strong>
                                <span>Add restaurant/menu items from the Items page.</span>
                            </div>
                        <?php else: ?>
                            <?php foreach ($items as $it):
                                $cat = trim((string)($it['category'] ?? '')) ?: 'Uncategorized';
                                $img = image_url($it['image'] ?? '');
                                $searchText = strtolower(trim(($it['name'] ?? '') . ' ' . ($it['sku'] ?? '') . ' ' . $cat . ' ' . ($it['type'] ?? '')));
                            ?>
                                <div class="landing-item-card"
                                     data-cat="<?= e(strtolower($cat)) ?>"
                                     data-search="<?= e($searchText) ?>">
                                    <div class="landing-item-img">
                                        <?php if ($img): ?>
                                            <img src="<?= e($img) ?>" alt="" onerror="this.parentElement.innerHTML='<i class=&quot;bi bi-cup-hot&quot;></i>';">
                                        <?php else: ?>
                                            <i class="bi bi-cup-hot"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="landing-item-body">
                                        <strong><?= e($it['name'] ?? '') ?></strong>
                                        <span><?= e($cat) ?></span>
                                        <b><?= e(money_fmt($it['price'] ?? 0)) ?></b>
                                        <button type="button" onclick="toast('Please start a Dine In, Takeaway, or Delivery order first.', 'err')">
                                            <i class="bi bi-plus-lg"></i> Add in active order
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pos-cart-placeholder glass-card">
                    <div class="cart-placeholder-top">
                        <div class="cart-placeholder-icon"><i class="bi bi-receipt-cutoff"></i></div>
                        <h5>Start New Order</h5>
                        <p>Select Dine In, Takeaway, or Delivery. Once the order starts, a floating POS window will open with an active cart.</p>
                    </div>

                    <div class="quick-order-cards">
                        <button class="quick-order-card dine" type="button" onclick="openOrderModal('dine_in')">
                            <i class="bi bi-shop-window"></i>
                            <strong>Dine In</strong>
                            <span>Table order with waiter</span>
                        </button>
                        <button class="quick-order-card takeaway" type="button" onclick="openOrderModal('takeaway')">
                            <i class="bi bi-bag-check"></i>
                            <strong>Takeaway</strong>
                            <span>Counter token order</span>
                        </button>
                        <button class="quick-order-card delivery" type="button" onclick="openOrderModal('delivery')">
                            <i class="bi bi-scooter"></i>
                            <strong>Delivery</strong>
                            <span>Address + rider assign</span>
                        </button>
                    </div>

                    <div class="running-summary-box">
                        <div>
                            <span>Active Sessions</span>
                            <strong><?= count($sessionPayloads) ?></strong>
                        </div>
                        <div>
                            <span>Tables Loaded</span>
                            <strong><?= count($tables) ?></strong>
                        </div>
                        <div>
                            <span>Menu Items</span>
                            <strong><?= count($items) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hidden table grid only for internal JS compatibility. Dine In table list uses INITIAL_TABLES. -->
            <div class="tables-dashboard-grid" id="tablesDashboardGrid" style="display:none !important;"></div>

            <!-- Empty workspace message hidden because new landing workspace is always visible. -->
            <div class="empty-workspace" id="emptyWorkspace" style="display:none !important;"></div>

        </div><!-- content-wrapper -->
    </div><!-- main-panel -->
</div><!-- container-scroll -->


<!-- ORDER TYPE MODAL -->
<div class="modal fade" id="orderTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" onsubmit="submitQuickOrder(event)">
            <input type="hidden" id="quickOrderType" value="dine_in">
            <div class="modal-header">
                <h5 class="modal-title" id="quickOrderTitle">Start Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="dineInFields">
                    <div class="mb-3">
                        <label>Select Table</label>
                        <select id="quickTableId" class="form-select"></select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label>Guests</label>
                            <input type="number" id="quickGuestCount" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-6">
                            <label>Waiter Optional</label>
                            <select id="quickWaiterId" class="form-select">
                                <option value="0">No Waiter</option>
                                <?php foreach ($waiters as $w): ?>
                                    <option value="<?= (int)$w['id'] ?>"><?= e($w['name'] ?? ('Waiter #' . $w['id'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="takeawayFields" style="display:none;">
                    <div class="mb-3">
                        <label>Customer Name</label>
                        <input type="text" id="takeawayCustomerName" class="form-control" placeholder="Walk-in customer">
                    </div>
                    <div class="mb-3">
                        <label>Phone Optional</label>
                        <input type="text" id="takeawayCustomerPhone" class="form-control" placeholder="03xxxxxxxxx">
                    </div>
                </div>

                <div id="deliveryFields" style="display:none;">
                    <div class="mb-3">
                        <label>Customer Name</label>
                        <input type="text" id="deliveryCustomerName" class="form-control" placeholder="Customer name">
                    </div>
                    <div class="mb-3">
                        <label>Phone</label>
                        <input type="text" id="deliveryCustomerPhone" class="form-control" placeholder="03xxxxxxxxx">
                    </div>
                    <div class="mb-3">
                        <label>Delivery Address</label>
                        <textarea id="deliveryAddress" class="form-control" rows="3" placeholder="House no, area, city"></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Delivery Charge</label>
                        <input type="number" step="0.01" id="deliveryCharge" class="form-control" value="0">
                    </div>
                    <div class="mb-3">
                        <label>Assign Rider / Staff</label>
                        <select id="deliveryRiderId" class="form-select">
                            <option value="0">No Rider</option>
                            <?php foreach ($riders as $r): ?>
                                <option value="<?= (int)$r['id'] ?>">
                                    <?= e($r['name'] ?? ('Staff #' . $r['id'])) ?><?= !empty($r['phone']) ? ' - ' . e($r['phone']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="small" style="color:#94a3b8;font-weight:700;line-height:1.6;">
                    Dine In orders work with a table. Takeaway and Delivery orders open a POS window without a table.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-glow btn-soft" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-glow" id="quickOrderBtn">Start Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Floating Windows Workspace -->
<div id="floatingWorkspace" class="floating-workspace"></div>

<!-- Minimized Taskbar -->
<div class="minimized-bills-bar" id="minimizedBar">
    <div class="min-bar-label">
        <i class="bi bi-receipt-cutoff"></i>
        <span id="minimizedCount">Minimized (0)</span>
    </div>
    <div class="min-bar-list" id="minimizedList"></div>
</div>

<!-- Toast Container -->
<div class="toast-box" id="toastBox"></div>

<!-- Item data for JS -->
<script>
const _ITEMS_DATA  = <?= $itemsJson ?: '[]' ?>;
const _CATS_DATA   = <?= $categoriesJson ?: '[]' ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ============================================================
   ONEERP RESTAURANT POS - FLOATING WINDOW SYSTEM
   Backend/AJAX endpoints: unchanged
   ============================================================ */

const CLIENT_ID           = <?= (int)$client_id ?>;
const BRANCH_ID           = <?= (int)$branch_id ?>;
const INITIAL_SESSION_ID  = <?= (int)$initialSessionId ?>;
const INITIAL_SELECTED    = <?= $selectedJson ?: 'null' ?>;
const INITIAL_TABLES      = <?= $tablesJson ?: '[]' ?>;
const INITIAL_SESSIONS    = <?= $sessionsJson ?: '[]' ?>;

const SK_OPEN   = `pos_open_${CLIENT_ID}`;
const SK_MIN    = `pos_min_${CLIENT_ID}`;
const SK_STATE  = `pos_state_${CLIENT_ID}`;
/* ---- State ---- */
let tablesById   = {};
let sessionsById = {};
let openSessions = new Set();
let minimizedSessions = new Set();
let zCounter = 1100;

/* ---- Utils ---- */
function ls_get(k){ try{ return JSON.parse(localStorage.getItem(k)||'null'); }catch(e){ return null; } }
function ls_set(k,v){ try{ localStorage.setItem(k,JSON.stringify(v)); }catch(e){} }

function saveWorkspace(){
    ls_set(SK_OPEN, [...openSessions]);
    ls_set(SK_MIN,  [...minimizedSessions]);
    // save window states
    let allStates = ls_get(SK_STATE) || {};
    ls_set(SK_STATE, allStates);
}

function getWindowState(sessionId){
    const all = ls_get(SK_STATE) || {};
    return all[sessionId] || null;
}
function saveWindowState(sessionId, state){
    const all = ls_get(SK_STATE) || {};
    all[sessionId] = Object.assign(all[sessionId]||{}, state);
    ls_set(SK_STATE, all);
}

function toast(msg, type='ok'){
    const box = document.getElementById('toastBox');
    const el  = document.createElement('div');
    el.className = 'toast-msg '+(type==='err'?'err':'ok');
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .4s'; }, 2500);
    setTimeout(()=>el.remove(), 3000);
}

function postForm(url, data){
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    return fetch(url,{method:'POST',body:fd,headers:{Accept:'application/json'}})
        .then(async r=>{
            const txt = await r.text();
            try{ return JSON.parse(txt); }catch(e){ throw new Error(txt||'Invalid server response'); }
        });
}


let quickOrderModal = null;

function openOrderModal(type){
    const modalEl = document.getElementById('orderTypeModal');
    quickOrderModal = quickOrderModal || new bootstrap.Modal(modalEl);

    document.getElementById('quickOrderType').value = type;
    document.getElementById('dineInFields').style.display = type === 'dine_in' ? 'block' : 'none';
    document.getElementById('takeawayFields').style.display = type === 'takeaway' ? 'block' : 'none';
    document.getElementById('deliveryFields').style.display = type === 'delivery' ? 'block' : 'none';

    const title = document.getElementById('quickOrderTitle');
    const btn = document.getElementById('quickOrderBtn');
    if(type === 'dine_in'){
        title.textContent = 'Start Dine In Order';
        btn.textContent = 'Start Dine In';
        btn.className = 'btn-glow';
        populateQuickTables();
    }else if(type === 'takeaway'){
        title.textContent = 'Start Takeaway Order';
        btn.textContent = 'Start Takeaway';
        btn.className = 'btn-glow btn-green';
    }else{
        title.textContent = 'Start Delivery Order';
        btn.textContent = 'Start Delivery';
        btn.className = 'btn-glow btn-orange';
    }
    quickOrderModal.show();
}

function populateQuickTables(){
    const select = document.getElementById('quickTableId');
    const tables = Object.values(tablesById || {});
    select.innerHTML = '';
    if(!tables.length){
        select.innerHTML = '<option value="">No tables found</option>';
        return;
    }
    tables.forEach(t => {
        const sid = parseInt(t.session_id || 0);
        const status = sid ? 'Active' : (t.status || 'Available');
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = `Table ${t.table_no || t.id} - ${t.table_name || 'Table'} (${status})`;
        select.appendChild(opt);
    });
}

function submitQuickOrder(e){
    e.preventDefault();
    const type = document.getElementById('quickOrderType').value;

    if(type === 'dine_in'){
        const tableId = parseInt(document.getElementById('quickTableId').value || 0);
        if(!tableId){ toast('Please select table.', 'err'); return; }
        const guestCount = parseInt(document.getElementById('quickGuestCount').value || 1);
        const waiterId = parseInt(document.getElementById('quickWaiterId').value || 0);
        startRestaurantSession({order_type:'dine_in', table_id:tableId, guest_count:guestCount, waiter_id:waiterId});
        return;
    }

    const payload = { order_type: type };
    if(type === 'takeaway'){
        payload.customer_name = document.getElementById('takeawayCustomerName').value.trim() || 'Walk-in';
        payload.customer_phone = document.getElementById('takeawayCustomerPhone').value.trim();
    }
    if(type === 'delivery'){
        payload.customer_name = document.getElementById('deliveryCustomerName').value.trim();
        payload.customer_phone = document.getElementById('deliveryCustomerPhone').value.trim();
        payload.delivery_address = document.getElementById('deliveryAddress').value.trim();
        payload.delivery_charge = document.getElementById('deliveryCharge').value || 0;
        payload.rider_id = parseInt(document.getElementById('deliveryRiderId')?.value || 0);
        if(!payload.customer_name){ toast('Delivery customer name required.', 'err'); return; }
        if(!payload.customer_phone){ toast('Delivery phone required.', 'err'); return; }
        if(!payload.delivery_address){ toast('Delivery address required.', 'err'); return; }
    }

    startRestaurantSession(payload);
}

function startRestaurantSession(payload){
    postForm('ajax_restaurant_start_session.php', payload)
        .then(data => {
            if(!data.success){ toast(data.message || 'Order start failed.', 'err'); return; }
            if(quickOrderModal) quickOrderModal.hide();
            registerSession(data.session);
            const sid = parseInt((data.session && data.session.id) || data.session_id || 0);
            if(!sid){ toast('Session created but response missing session id.', 'err'); return; }
            openSessions.add(sid);
            minimizedSessions.delete(sid);
            createPosWindow(sid);
            loadCart(sid);
            saveWorkspace();
            renderTableCards();
            renderMinimizedBar();
            updateEmptyState();
            toast(data.message || 'Order started.');
        })
        .catch(err => toast(err.message, 'err'));
}

function money(v){ return 'Rs. '+parseFloat(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }

function statusClass(s){
    s = String(s||'').toLowerCase().trim();
    if(['open','occupied','active'].includes(s)) return 'st-occupied';
    if(['minimized','hold'].includes(s)) return 'st-minimized';
    if(['kot_sent','kot sent'].includes(s)) return 'st-kot';
    if(['bill_printed','bill printed'].includes(s)) return 'st-bill';
    if(s==='available') return 'st-available';
    if(s==='reserved') return 'st-reserved';
    if(s==='dirty') return 'st-dirty';
    return 'st-muted';
}
function statusLabel(s){
    s = String(s||'available').toLowerCase().trim();
    if(['open','occupied','active'].includes(s)) return 'Active';
    if(s==='kot_sent') return 'KOT Sent';
    if(s==='bill_printed') return 'Bill Printed';
    if(s==='minimized') return 'Minimized';
    return s.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) || 'Available';
}

function registerSession(session){
    if(!session || !session.id) return;
    const sid = parseInt(session.id);
    sessionsById[sid] = Object.assign({}, sessionsById[sid]||{}, session, {id:sid});
    const tid = parseInt(session.table_id||0);
    if(tid && tablesById[tid]){
        tablesById[tid].session_id = sid;
        tablesById[tid].session_status = session.status||'open';
        if(session.waiter_name) tablesById[tid].waiter_name = session.waiter_name;
        if(session.grand_total !== undefined) tablesById[tid].session_total = session.grand_total;
    }
}

function tableTitle(s){
    if(!s) return 'Order';
    const type = String(s.order_type || 'dine_in');
    if(type === 'takeaway') return 'Takeaway ' + (s.token_no ? '#'+s.token_no : '#'+s.id);
    if(type === 'delivery') return 'Delivery ' + (s.token_no ? '#'+s.token_no : '#'+s.id);
    let t = s.table_no ? ('Table '+s.table_no) : ('Order #'+s.id);
    if(s.table_name) t += ' – '+s.table_name;
    return t;
}
function tableTitleShort(s){
    if(!s) return 'Order';
    const type = String(s.order_type || 'dine_in');
    if(type === 'takeaway') return 'Takeaway ' + (s.token_no || '#'+s.id);
    if(type === 'delivery') return 'Delivery ' + (s.token_no || '#'+s.id);
    return s.table_no ? ('T-'+s.table_no+(s.table_name?' '+s.table_name:'')) : ('Order #'+s.id);
}
function orderSubtitle(s){
    if(!s) return '';
    const type = String(s.order_type || 'dine_in');
    if(type === 'takeaway') return `Takeaway · ${s.customer_name || 'Walk-in'}${s.customer_phone ? ' · '+s.customer_phone : ''}`;
    if(type === 'delivery') return `Delivery · ${s.customer_name || 'Customer'}${s.customer_phone ? ' · '+s.customer_phone : ''}`;
    return `Session #${s.id} · ${s.waiter_name || 'No Waiter'}`;
}
function orderMiniNumber(s){
    if(!s) return '?';
    const type = String(s.order_type || 'dine_in');
    if(type === 'takeaway') return 'T';
    if(type === 'delivery') return 'D';
    return s.table_no || '?';
}

/* ============================================================
   TABLE CARDS RENDER
   ============================================================ */
function renderTableCards(){
    const grid = document.getElementById('tablesDashboardGrid');
    if(!grid) return;
    const tables = Object.values(tablesById);
    if(!tables.length){
        grid.innerHTML = `<div class="glass-card" style="padding:28px;text-align:center;color:var(--clr-muted);grid-column:1/-1;">
            <i class="bi bi-shop-window" style="font-size:38px;"></i>
            <p style="margin:10px 0 0;font-weight:700;">No tables found.</p>
            <p style="margin:4px 0 0;font-size:12px;">Add active tables in restaurant_tables.</p>
        </div>`;
        return;
    }

    grid.innerHTML = tables.map(t => {
        const sid = parseInt(t.session_id||0);
        const isMinimized = sid && minimizedSessions.has(sid);
        const isOpen = sid && openSessions.has(sid) && !isMinimized;

        let displayStatus = t.status || 'Available';
        if(isMinimized) displayStatus = 'minimized';
        else if(sid && sessionsById[sid]) displayStatus = sessionsById[sid].status || 'open';
        else if(!sid && String(t.status).toLowerCase()==='occupied') displayStatus = 'available';

        const billAmt = sid ? (sessionsById[sid]?.formatted_grand_total || money(t.session_total||0)) : '—';

        return `<div class="pos-table-card glass-card ${isOpen?'is-active':''}" onclick="openTable(${parseInt(t.id)})">
            <div class="ptc-top">
                <div class="ptc-num">${esc(t.table_no||t.id)}</div>
                <div class="ptc-info">
                    <div class="ptc-name">${esc(t.table_name || ('Table '+(t.table_no||t.id)))}</div>
                    <div class="ptc-floor">${esc(t.floor_name||'—')}</div>
                </div>
            </div>
            <div class="ptc-meta">
                <span><i class="bi bi-person-badge" style="color:#7c3aed;"></i> <b>${esc(t.waiter_name||'Not Assigned')}</b></span>
                <span><i class="bi bi-people" style="color:#60a5fa;"></i> Capacity: ${esc(t.capacity||'—')}</span>
            </div>
            <span class="${statusClass(displayStatus)}" style="margin-bottom:12px;"><i class="bi bi-circle-fill" style="font-size:7px;"></i>${statusLabel(displayStatus)}</span>
            <div class="ptc-bill">
                <small>Current Bill</small>
                ${esc(billAmt)}
            </div>
            <button class="ptc-open-btn" type="button">
                <i class="bi bi-${isOpen?'window':'window-plus'}"></i>
                ${isOpen?'Focus Window':(isMinimized?'Restore POS':'Open POS')}
            </button>
        </div>`;
    }).join('');
}

/* ============================================================
   OPEN TABLE → SESSION → WINDOW
   ============================================================ */
function openTable(tableId){
    tableId = parseInt(tableId||0);
    if(!tableId) return;

    // If already open (not minimized), just bring to front
    const existingTid = Object.values(sessionsById).find(s=>parseInt(s.table_id)===tableId && openSessions.has(parseInt(s.id)) && !minimizedSessions.has(parseInt(s.id)));
    if(existingTid){
        bringWindowToFront(parseInt(existingTid.id));
        return;
    }

    // If minimized, restore
    const minimizedSid = Object.values(sessionsById).find(s=>parseInt(s.table_id)===tableId && minimizedSessions.has(parseInt(s.id)));
    if(minimizedSid){
        restoreSession(parseInt(minimizedSid.id));
        return;
    }

    postForm('ajax_restaurant_start_session.php', {order_type:'dine_in', table_id: tableId})
        .then(data => {
            if(!data.success){ toast(data.message||'Table open failed','err'); return; }
            registerSession(data.session);
            const sid = parseInt(data.session.id);
            openSessions.add(sid);
            minimizedSessions.delete(sid);
            createPosWindow(sid);
            loadCart(sid);
            saveWorkspace();
            renderTableCards();
            renderMinimizedBar();
            updateEmptyState();
            toast(data.message||'POS window opened');
        })
        .catch(err => toast(err.message,'err'));
}

/* ============================================================
   CREATE FLOATING POS WINDOW
   ============================================================ */
function createPosWindow(sessionId){
    sessionId = parseInt(sessionId||0);
    const s = sessionsById[sessionId];
    if(!s) return;

    // Remove if already exists
    const existing = document.getElementById('posWindow_'+sessionId);
    if(existing) existing.remove();

    const workspace = document.getElementById('floatingWorkspace');

    // Position: cascade offset
    const savedState = getWindowState(sessionId);
    const defaultW = Math.min(960, window.innerWidth - 40);
    const defaultH = Math.min(640, window.innerHeight - 160);
    const openCount = [...openSessions].filter(id=>!minimizedSessions.has(id)).length;
    const offsetX = (openCount-1) * 30;
    const offsetY = (openCount-1) * 30;

    const left   = savedState?.left   ?? Math.max(10, Math.floor((window.innerWidth  - defaultW)/2) + offsetX);
    const top    = savedState?.top    ?? Math.max(80, Math.floor((window.innerHeight - defaultH)/2) + offsetY);
    const width  = savedState?.width  ?? defaultW;
    const height = savedState?.height ?? defaultH;
    const isMax  = savedState?.maximized ?? false;

    // Build categories HTML
    const catsHtml = ['All', ..._CATS_DATA].map((cat,i) =>
        `<button class="win-cat-btn${i===0?' active':''}" type="button" data-cat="${i===0?'all':esc(cat.toLowerCase())}"
            onclick="filterWindowItems(${sessionId},this)">${esc(cat)}</button>`
    ).join('');

    // Build items HTML
    const itemsHtml = _ITEMS_DATA.map(it => {
        const cat = (it.category||'Uncategorized').trim() || 'Uncategorized';
        const img = (() => {
            const im = (it.image||'').trim();
            if(!im) return '';
            if(/^https?:\/\//i.test(im)||im.startsWith('/')) return im;
            return '/uploads/items/'+im;
        })();
        const searchText = [it.name||'',it.sku||'',cat,it.type||''].join(' ').toLowerCase();
        return `<div class="win-item-card" data-cat="${esc(cat.toLowerCase())}" data-search="${esc(searchText)}"
                    data-price="${esc(it.price||0)}">
            <div class="win-item-img">${img?`<img src="${esc(img)}" onerror="this.parentElement.innerHTML='<i class=\\'bi bi-cup-hot\\'></i>';" alt="">`:'<i class="bi bi-cup-hot"></i>'}</div>
            <div class="win-item-body">
                <div class="win-item-name">${esc(it.name||'')}</div>
                <div class="win-item-cat">${esc(cat)}</div>
                <div class="win-item-price">${money(it.price||0)}</div>
                <div class="win-item-actions">
                    <button class="win-add-btn" type="button" onclick="addItemToSession(${sessionId},${parseInt(it.id)},'')">
                        <i class="bi bi-plus-lg"></i> Add
                    </button>
                    <button class="win-note-btn" type="button" title="Add with note"
                        onclick="addItemToSessionWithNote(${sessionId},${parseInt(it.id)})">
                        <i class="bi bi-chat-left-text"></i>
                    </button>
                </div>
            </div>
        </div>`;
    }).join('');

    const winEl = document.createElement('div');
    winEl.className = 'pos-floating-window' + (isMax?' window-maximized':'');
    winEl.id = 'posWindow_'+sessionId;
    winEl.dataset.sessionId = String(sessionId);
    winEl.style.cssText = `left:${left}px;top:${top}px;width:${width}px;height:${height}px;z-index:${++zCounter};`;

    winEl.innerHTML = `
        <div class="pos-window-titlebar" id="winTitlebar_${sessionId}">
            <i class="bi bi-grip-vertical win-drag-handle"></i>
            <div class="win-title-main">
                <strong><i class="bi bi-receipt" style="color:#fb923c;margin-right:5px;font-size:12px;"></i>${esc(tableTitle(s))}</strong>
                <small>${esc(orderSubtitle(s))}</small>
            </div>
            <span class="win-total-badge" id="winTotalBadge_${sessionId}">${esc(s.formatted_grand_total||money(s.grand_total||0))}</span>
            <div class="pos-window-controls">
                <button class="win-ctrl-btn minimize" type="button" title="Minimize" onclick="minimizeSession(${sessionId})"><i class="bi bi-dash-lg"></i></button>
                <button class="win-ctrl-btn" type="button" title="Maximize/Restore" onclick="maximizeWindow(${sessionId})"><i class="bi bi-arrows-fullscreen" id="winMaxIcon_${sessionId}"></i></button>
                <button class="win-ctrl-btn close" type="button" title="Close (session stays active)" onclick="closePosWindow(${sessionId})"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <div class="pos-window-body">
            <div class="pos-window-categories">
                <div class="win-cat-title">Categories</div>
                ${catsHtml}
            </div>

            <div class="pos-window-items">
                <div class="win-items-toolbar">
                    <input type="text" class="win-search" id="winSearch_${sessionId}" placeholder="Search items..."
                        oninput="filterWindowItems(${sessionId},null)">
                </div>
                <div class="win-items-grid" id="winItemsGrid_${sessionId}">
                    ${itemsHtml}
                </div>
            </div>

            <div class="pos-window-cart">
                <div class="win-cart-head">
                    <h6><i class="bi bi-cart3"></i> Cart</h6>
                    <small>Session #${sessionId}</small>
                </div>
                <div class="win-cart-body" id="cartBody_${sessionId}">
                    <div class="win-cart-empty"><i class="bi bi-arrow-repeat"></i><strong>Loading...</strong></div>
                </div>
                <div class="win-cart-summary">
                    <div class="win-sum-row"><span>Subtotal</span><strong id="sumSubtotal_${sessionId}">Rs. 0.00</strong></div>
                    <div class="win-sum-row"><span>Discount</span><strong id="sumDiscount_${sessionId}">Rs. 0.00</strong></div>
                    <div class="win-sum-row"><span>Tax</span><strong id="sumTax_${sessionId}">Rs. 0.00</strong></div>
                    <div class="win-sum-row"><span>Service Charge</span><strong id="sumService_${sessionId}">Rs. 0.00</strong></div>
                    <div class="win-sum-row grand"><span>Grand Total</span><strong id="sumGrand_${sessionId}">Rs. 0.00</strong></div>
                </div>
                <div class="win-cart-actions">
                    <button class="btn-glow btn-green" type="button" onclick="sendKot(${sessionId})"><i class="bi bi-send-check"></i> Send KOT</button>
                    <button class="btn-glow btn-orange" type="button" onclick="printBill(${sessionId})"><i class="bi bi-printer"></i> Print Bill</button>
                    <button class="btn-glow btn-yellow wide" type="button" onclick="minimizeSession(${sessionId})"><i class="bi bi-pause-circle"></i> Hold / Minimize</button>
                    <a class="btn-glow btn-blue wide" href="restaurant_payment.php?session_id=${sessionId}"><i class="bi bi-credit-card"></i> Payment</a>
                </div>
            </div>
        </div>

        <div class="resize-handle" id="resizeHandle_${sessionId}"></div>
    `;

    workspace.appendChild(winEl);

    // Bring to front on click
    winEl.addEventListener('pointerdown', () => bringWindowToFront(sessionId));

    // Make draggable & resizable
    makeWindowDraggable(winEl, sessionId);
    makeWindowResizable(winEl, sessionId);

    bringWindowToFront(sessionId);
}

/* ============================================================
   WINDOW MANAGEMENT
   ============================================================ */
function bringWindowToFront(sessionId){
    sessionId = parseInt(sessionId||0);
    document.querySelectorAll('.pos-floating-window').forEach(w => w.classList.remove('is-front'));
    const win = document.getElementById('posWindow_'+sessionId);
    if(win){
        win.style.zIndex = ++zCounter;
        win.classList.add('is-front');
    }
}

function makeWindowDraggable(winEl, sessionId){
    const titlebar = winEl.querySelector('.pos-window-titlebar');
    let dragging = false, startX, startY, startLeft, startTop;

    titlebar.addEventListener('pointerdown', e => {
        if(e.target.closest('.pos-window-controls, .win-ctrl-btn, button')) return;
        if(winEl.classList.contains('window-maximized')) return;
        dragging = true;
        startX = e.clientX;
        startY = e.clientY;
        startLeft = winEl.offsetLeft;
        startTop  = winEl.offsetTop;
        titlebar.setPointerCapture(e.pointerId);
        titlebar.style.cursor = 'grabbing';
        bringWindowToFront(sessionId);
        e.preventDefault();
    });

    titlebar.addEventListener('pointermove', e => {
        if(!dragging) return;
        let newLeft = startLeft + (e.clientX - startX);
        let newTop  = startTop  + (e.clientY - startY);
        // Keep in viewport
        const minTop = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--pos-topbar-h')||70);
        const minH   = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--pos-mini-h')||68);
        newLeft = Math.max(-winEl.offsetWidth+80, Math.min(window.innerWidth-80, newLeft));
        newTop  = Math.max(minTop, Math.min(window.innerHeight - minH - 52, newTop));
        winEl.style.left = newLeft+'px';
        winEl.style.top  = newTop+'px';
    });

    titlebar.addEventListener('pointerup', e => {
        if(!dragging) return;
        dragging = false;
        titlebar.style.cursor = '';
        saveWindowState(sessionId, {left: winEl.offsetLeft, top: winEl.offsetTop});
    });

    // Double click to maximize
    titlebar.addEventListener('dblclick', e => {
        if(e.target.closest('.pos-window-controls, button')) return;
        maximizeWindow(sessionId);
    });
}

function makeWindowResizable(winEl, sessionId){
    const handle = winEl.querySelector('.resize-handle');
    if(!handle) return;
    let resizing = false, startX, startY, startW, startH;

    handle.addEventListener('pointerdown', e => {
        if(winEl.classList.contains('window-maximized')) return;
        resizing = true;
        startX = e.clientX;
        startY = e.clientY;
        startW = winEl.offsetWidth;
        startH = winEl.offsetHeight;
        handle.setPointerCapture(e.pointerId);
        bringWindowToFront(sessionId);
        e.preventDefault();
        e.stopPropagation();
    });

    handle.addEventListener('pointermove', e => {
        if(!resizing) return;
        const minW = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--win-min-w')||780);
        const minH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--win-min-h')||480);
        const newW = Math.max(minW, startW + (e.clientX - startX));
        const newH = Math.max(minH, startH + (e.clientY - startY));
        winEl.style.width  = newW+'px';
        winEl.style.height = newH+'px';
    });

    handle.addEventListener('pointerup', e => {
        if(!resizing) return;
        resizing = false;
        saveWindowState(sessionId, {width: winEl.offsetWidth, height: winEl.offsetHeight});
    });
}

function maximizeWindow(sessionId){
    sessionId = parseInt(sessionId||0);
    const win = document.getElementById('posWindow_'+sessionId);
    if(!win) return;
    const isMax = win.classList.contains('window-maximized');
    const icon  = document.getElementById('winMaxIcon_'+sessionId);

    if(isMax){
        const saved = getWindowState(sessionId);
        win.classList.remove('window-maximized');
        if(saved){
            win.style.left   = (saved.restoreLeft   || 80)+'px';
            win.style.top    = (saved.restoreTop    || 100)+'px';
            win.style.width  = (saved.restoreWidth  || 960)+'px';
            win.style.height = (saved.restoreHeight || 640)+'px';
        }
        if(icon) icon.className = 'bi bi-arrows-fullscreen';
        saveWindowState(sessionId, {maximized:false});
    } else {
        saveWindowState(sessionId, {
            maximized: true,
            restoreLeft:   win.offsetLeft,
            restoreTop:    win.offsetTop,
            restoreWidth:  win.offsetWidth,
            restoreHeight: win.offsetHeight
        });
        win.classList.add('window-maximized');
        if(icon) icon.className = 'bi bi-fullscreen-exit';
    }
    bringWindowToFront(sessionId);
}

function minimizeSession(sessionId){
    sessionId = parseInt(sessionId||0);
    if(!sessionId || !sessionsById[sessionId]) return;
    const win = document.getElementById('posWindow_'+sessionId);
    if(win) win.remove();
    openSessions.delete(sessionId);
    minimizedSessions.add(sessionId);
    saveWorkspace();
    renderTableCards();
    renderMinimizedBar();
    updateEmptyState();
}

function restoreSession(sessionId){
    sessionId = parseInt(sessionId||0);
    if(!sessionId || !sessionsById[sessionId]) return;
    minimizedSessions.delete(sessionId);
    openSessions.add(sessionId);
    createPosWindow(sessionId);
    loadCart(sessionId);
    saveWorkspace();
    renderTableCards();
    renderMinimizedBar();
    updateEmptyState();
}

function closePosWindow(sessionId){
    sessionId = parseInt(sessionId||0);
    const win = document.getElementById('posWindow_'+sessionId);
    if(win) win.remove();
    openSessions.delete(sessionId);
    minimizedSessions.delete(sessionId);
    saveWorkspace();
    renderTableCards();
    renderMinimizedBar();
    updateEmptyState();
}

function forgetMinimized(sessionId){
    sessionId = parseInt(sessionId||0);
    minimizedSessions.delete(sessionId);
    saveWorkspace();
    renderTableCards();
    renderMinimizedBar();
    updateEmptyState();
}

function updateEmptyState(){
    const ew = document.getElementById('emptyWorkspace');
    if(ew) ew.style.display = 'none';
    const landing = document.getElementById('posMainWorkspace');
    if(landing) landing.style.display = 'grid';
}

/* ============================================================
   MINIMIZED BAR
   ============================================================ */
function renderMinimizedBar(){
    const bar  = document.getElementById('minimizedBar');
    const list = document.getElementById('minimizedList');
    const count = document.getElementById('minimizedCount');
    const ids = [...minimizedSessions].filter(id => sessionsById[id]);

    count.textContent = `Minimized (${ids.length})`;
    if(!ids.length){ bar.classList.remove('show'); list.innerHTML=''; return; }
    bar.classList.add('show');

    list.innerHTML = ids.map(id => {
        const s = sessionsById[id];
        return `<div class="mini-pos-tab" onclick="restoreSession(${id})">
            <div class="mini-tab-num">${esc(orderMiniNumber(s))}</div>
            <div class="mini-tab-info">
                <strong>${esc(tableTitleShort(s))}</strong>
                <span>${esc(s.formatted_grand_total||money(s.grand_total||0))}</span>
            </div>
            <button class="mini-tab-close" type="button" onclick="event.stopPropagation();forgetMinimized(${id})" title="Forget">
                <i class="bi bi-x"></i>
            </button>
        </div>`;
    }).join('');
}

/* ============================================================
   CART OPERATIONS (existing endpoints unchanged)
   ============================================================ */
function loadCart(sessionId){
    sessionId = parseInt(sessionId||0);
    if(!sessionId) return;
    const body = document.getElementById('cartBody_'+sessionId);
    if(!body) return;
    body.innerHTML = '<div class="win-cart-empty"><i class="bi bi-arrow-repeat"></i><strong>Loading...</strong></div>';

    fetch('ajax_restaurant_get_cart.php?session_id='+encodeURIComponent(sessionId), {headers:{Accept:'application/json'}})
        .then(r=>r.json())
        .then(data => {
            if(!data.success){ toast(data.message||'Cart load failed','err'); return; }
            // Restyle cart HTML for floating window
            const html = (data.html||'').replace(/class="cart-/g,'class="win-cart-').replace(/class="line-/g,'class="wcl-').replace(/class="qty-/g,'class="wc-qty-').replace(/class="remove-/g,'class="wc-rm-').replace(/class="kot-/g,'class="kot-').replace(/class="item-status"/g,'class="item-status-badge"');
            body.innerHTML = html || '<div class="win-cart-empty"><i class="bi bi-cart-x"></i><strong>Cart is empty</strong></div>';

            const f   = data.formatted || {};
            const tot = data.totals    || {};
            const setEl = (id,v) => { const el=document.getElementById(id+'_'+sessionId); if(el) el.textContent=v||'Rs. 0.00'; };
            setEl('sumSubtotal', f.subtotal);
            setEl('sumDiscount', f.discount);
            setEl('sumTax',      f.tax);
            setEl('sumService',  f.service_charge);
            setEl('sumGrand',    f.grand_total);

            // Update badge
            const badge = document.getElementById('winTotalBadge_'+sessionId);
            if(badge) badge.textContent = f.grand_total || money(0);

            if(sessionsById[sessionId]){
                sessionsById[sessionId].formatted_grand_total = f.grand_total || 'Rs. 0.00';
                if(tot.grand_total !== undefined) sessionsById[sessionId].grand_total = parseFloat(tot.grand_total||0);
            }
            renderTableCards();
            renderMinimizedBar();
        })
        .catch(err => toast(err.message,'err'));
}

/* ---- Add Item to specific session (safe, window-scoped) ---- */
function addItemToSession(sessionId, itemId, note){
    sessionId = parseInt(sessionId||0);
    if(!sessionId){ toast('Please select a table first.','err'); return; }
    postForm('ajax_restaurant_add_item.php', {session_id:sessionId, item_id:itemId, qty:1, note:note||''})
        .then(data => {
            if(data.success){ toast(data.message||'Added'); loadCart(sessionId); }
            else toast(data.message||'Add failed','err');
        })
        .catch(err => toast(err.message,'err'));
}

function addItemToSessionWithNote(sessionId, itemId){
    const note = prompt('Special note for kitchen? (e.g. less spicy, no onion)');
    if(note === null) return;
    addItemToSession(sessionId, itemId, note.trim());
}

/* Backward-compat aliases used in cart HTML from server */
function addItem(itemId, note){ addItemToSession(activeSessionProxy(), itemId, note||''); }
function addItemWithNote(itemId){ addItemToSessionWithNote(activeSessionProxy(), itemId); }
function activeSessionProxy(){
    // Return most recently brought-to-front session
    let maxZ = 0, sid = 0;
    document.querySelectorAll('.pos-floating-window').forEach(w => {
        const z = parseInt(w.style.zIndex||0);
        if(z>maxZ){ maxZ=z; sid=parseInt(w.dataset.sessionId||0); }
    });
    return sid;
}

function toggleKotRequired(orderItemId, required, sessionId){
    orderItemId = parseInt(orderItemId || 0);
    required = parseInt(required || 0) === 1 ? 1 : 0;
    sessionId = parseInt(sessionId || 0);

    if(!orderItemId){
        toast('Invalid cart item.', 'err');
        return;
    }

    postForm('ajax_restaurant_toggle_kot_required.php', {
        order_item_id: orderItemId,
        kot_required: required
    })
        .then(data => {
            const sid = parseInt((data && data.session_id) || sessionId || activeSessionProxy() || 0);

            if(data && data.success){
                toast(data.message || (required ? 'Item marked as KOT Item.' : 'Item marked as Ready Item.'));
                if(sid) loadCart(sid);
            } else {
                toast((data && data.message) || 'KOT item update failed.', 'err');
                if(sid) loadCart(sid);
            }
        })
        .catch(err => {
            toast(err.message || 'KOT item update failed.', 'err');
            const sid = sessionId || activeSessionProxy();
            if(sid) loadCart(sid);
        });
}

function updateCartQty(orderItemId, qty, sessionId){
    sessionId = parseInt(sessionId||0);
    qty = parseFloat(qty);
    if(isNaN(qty)) return toast('Invalid quantity','err');
    postForm('ajax_restaurant_update_qty.php', {order_item_id:orderItemId, qty:qty})
        .then(data => {
            if(data.success){ toast(data.message||'Updated'); loadCart(sessionId||activeSessionProxy()); }
            else { toast(data.message||'Update failed','err'); loadCart(sessionId||activeSessionProxy()); }
        })
        .catch(err => toast(err.message,'err'));
}

function removeCartItem(orderItemId, kotStatus, sessionId){
    sessionId = parseInt(sessionId||0);
    let reason = '';
    if(kotStatus==='sent'){
        reason = prompt('KOT already sent. Please enter cancel reason:');
        if(reason===null) return;
        reason = reason.trim();
        if(!reason) return toast('Cancel reason required.','err');
    } else {
        if(!confirm('Remove this pending item?')) return;
    }
    postForm('ajax_restaurant_remove_item.php', {order_item_id:orderItemId, reason:reason})
        .then(data => {
            if(data.success){ toast(data.message||'Removed'); loadCart(sessionId||activeSessionProxy()); }
            else toast(data.message||'Remove failed','err');
        })
        .catch(err => toast(err.message,'err'));
}

/* ---- KOT & Bill (existing endpoints unchanged) ---- */
function sendKot(sessionId){
    sessionId = parseInt(sessionId||0);
    if(!sessionId){ toast('Please open a table window first.','err'); return; }

    postForm('ajax_restaurant_send_kot.php', {session_id:sessionId})
        .then(data => {
            if(data.success){
                toast(data.message||'KOT sent to kitchen');

                if(sessionsById[sessionId]){
                    sessionsById[sessionId].status='kot_sent';
                }

                loadCart(sessionId);
                renderTableCards();

                /*
                  Important workflow change:
                  Previously, Send KOT opened the KOT print popup/window.
                  Now the print window will not open. The current order/cart
                  will redirect to the Kitchen / KOT page with session_id.
                */
                const kotPageUrl = 'restaurant_kitchen.php?session_id=' + encodeURIComponent(sessionId) + '&from_pos=1';
                setTimeout(function(){
                  function sendKot(sessionId){
    sessionId = parseInt(sessionId || 0);

    if(!sessionId){
        toast('Please open an order first.', 'err');
        return;
    }

    postForm('ajax_restaurant_send_kot.php', {session_id: sessionId})
        .then(data => {
            if(data.success){

                toast(data.message || 'Order sent to KOT successfully.');

                if(sessionsById[sessionId]){
                    sessionsById[sessionId].status = 'kot_sent';
                }

                loadCart(sessionId);
                renderTableCards();
                renderMinimizedBar();

           alert('Order successfully sent to the kitchen. View and manage it from the Kitchen Orders screen.');

            } else {
                toast(data.message || 'KOT send failed.', 'err');
            }
        })
        .catch(err => toast(err.message, 'err'));
}
                }, 250);
            } else {
                toast(data.message||'KOT send failed','err');
            }
        })
        .catch(err => toast(err.message,'err'));
}

function printBill(sessionId){
    sessionId = parseInt(sessionId||0);
    if(!sessionId){ toast('Please open a table window first.','err'); return; }
    window.open('restaurant_bill_print.php?session_id='+encodeURIComponent(sessionId),'_blank');
    postForm('ajax_restaurant_mark_bill_printed.php', {session_id:sessionId})
        .then(data => {
            if(data.success && sessionsById[sessionId]){
                sessionsById[sessionId].status='bill_printed';
                renderTableCards();
            }
        }).catch(()=>{});
}

/* ============================================================
   ITEM FILTER (window-scoped, isolated per window)
   ============================================================ */
function filterWindowItems(sessionId, catBtn){
    sessionId = parseInt(sessionId||0);
    const grid   = document.getElementById('winItemsGrid_'+sessionId);
    const search = document.getElementById('winSearch_'+sessionId);
    if(!grid) return;

    // Update active category button inside this window
    if(catBtn){
        const winEl = document.getElementById('posWindow_'+sessionId);
        if(winEl) winEl.querySelectorAll('.win-cat-btn').forEach(b=>b.classList.remove('active'));
        catBtn.classList.add('active');
    }

    const activeCatBtn = (() => {
        const winEl = document.getElementById('posWindow_'+sessionId);
        if(!winEl) return null;
        return winEl.querySelector('.win-cat-btn.active');
    })();

    const cat = activeCatBtn ? (activeCatBtn.dataset.cat||'all') : 'all';
    const q   = (search?.value||'').toLowerCase().trim();

    grid.querySelectorAll('.win-item-card').forEach(card => {
        const okCat    = cat==='all' || card.dataset.cat===cat;
        const okSearch = !q || (card.dataset.search||'').includes(q);
   const shouldShow = okCat && okSearch;
card.classList.toggle('hidden', !shouldShow);
card.style.display = shouldShow ? '' : 'none';
    });
}

/* ============================================================
   LANDING MENU FILTER
   ============================================================ */
function filterLandingMenu(cat, btn){
    const grid = document.getElementById('landingItemsGrid');
    if(!grid) return;

    if(btn){
        document.querySelectorAll('.landing-cat').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
    }

    const activeCat = document.querySelector('.landing-cat.active');
    const selectedCat = cat || (activeCat ? activeCat.dataset.cat : 'all');
    const q = (document.getElementById('landingMenuSearch')?.value || '').toLowerCase().trim();

    grid.querySelectorAll('.landing-item-card').forEach(card => {
        const okCat = selectedCat === 'all' || card.dataset.cat === selectedCat;
        const okSearch = !q || (card.dataset.search || '').includes(q);
        card.classList.toggle('hidden', !(okCat && okSearch));
    });
}

/* ============================================================
   INIT
   ============================================================ */
function initWorkspace(){
    // Load tables & sessions from PHP/database
    INITIAL_TABLES.forEach(t => { 
        tablesById[parseInt(t.id)] = t; 
    });

    INITIAL_SESSIONS.forEach(registerSession);

    if(INITIAL_SELECTED) {
        registerSession(INITIAL_SELECTED);
    }

    // Restore state from localStorage
    const savedMinRaw  = ls_get(SK_MIN);
    const savedOpenRaw = ls_get(SK_OPEN);

    const savedMin  = Array.isArray(savedMinRaw) ? savedMinRaw : [];
    const savedOpen = Array.isArray(savedOpenRaw) ? savedOpenRaw : [];

    // Restore minimized sessions only if still active in database
    savedMin.forEach(id => { 
        const sid = parseInt(id);
        if(sessionsById[sid]) {
            minimizedSessions.add(sid);
        }
    });

    // Restore open sessions only if still active in database
    savedOpen.forEach(id => {
        const sid = parseInt(id);
        if(sessionsById[sid] && !minimizedSessions.has(sid)) {
            openSessions.add(sid);
        }
    });

    /*
      IMPORTANT FIX:
      Jo sessions database me active hain lekin localStorage me missing hain,
      unko automatically minimized tab me show karo.
      Isse page change/reload ke baad unpaid orders gayab nahi honge.
    */
    Object.keys(sessionsById).forEach(id => {
        const sid = parseInt(id);

        if(!sid) return;

        if(!openSessions.has(sid) && !minimizedSessions.has(sid)) {
            minimizedSessions.add(sid);
        }
    });

    // Direct URL session_id ho to usko open window me lao
    if(INITIAL_SESSION_ID && sessionsById[INITIAL_SESSION_ID]){
        minimizedSessions.delete(INITIAL_SESSION_ID);
        openSessions.add(INITIAL_SESSION_ID);
    }

    // Restore open windows
    [...openSessions].forEach(id => {
        createPosWindow(id);
        loadCart(id);
    });

    renderMinimizedBar();
    updateEmptyState();
    saveWorkspace();
}

document.addEventListener('DOMContentLoaded', initWorkspace);

window.addEventListener('beforeunload', function () {
    saveWorkspace();
});

window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>
</body>
</html>
