<?php
session_start();

// Kitchen screen must never show browser-cached completed KOT cards.
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

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
$is_superadmin = ($role_id === 1);

// Always open Kitchen Display with a fresh URL so old KOT cards/status do not appear from browser cache.
if (!isset($_GET['_live'])) {
    $liveParams = $_GET;
    $liveParams['_live'] = time() . mt_rand(1000, 9999);
    header('Location: restaurant_kitchen.php?' . http_build_query($liveParams));
    exit;
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fmt_qty($qty): string
{
    return rtrim(rtrim(number_format((float)$qty, 2, '.', ''), '0'), '.');
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    if (!table_exists($conn, $table)) return false;

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
    if (table_exists($conn, $table) && !column_exists($conn, $table, $column)) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function enum_values(mysqli $conn, string $table, string $column): array
{
    if (!column_exists($conn, $table, $column)) return [];

    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $type = (string)($row['COLUMN_TYPE'] ?? '');
    if (stripos($type, 'enum(') !== 0) return [];

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $m);
    return array_map(static fn($v) => stripcslashes($v), $m[1] ?? []);
}

function ensure_kot_enum(mysqli $conn, string $table): void
{
    if (!table_exists($conn, $table) || !column_exists($conn, $table, 'status')) return;

    $allowed = enum_values($conn, $table, 'status');
    if (!$allowed) return;

    $needed = ['pending','preparing','processing','process','ready','served','completed','complete','cancelled','canceled'];
    foreach ($needed as $v) {
        if (!in_array($v, $allowed, true)) {
            $conn->query("ALTER TABLE `$table` MODIFY `status` ENUM('pending','preparing','processing','process','ready','served','completed','complete','cancelled','canceled') NOT NULL DEFAULT 'pending'");
            return;
        }
    }
}

function ensure_kitchen_schema(mysqli $conn): void
{
    if (!table_exists($conn, 'restaurant_kots')) {
        $conn->query("CREATE TABLE restaurant_kots (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            session_id INT(11) NOT NULL,
            table_id INT(11) DEFAULT NULL,
            waiter_id INT(11) DEFAULT NULL,
            kot_no VARCHAR(50) NOT NULL,
            status ENUM('pending','preparing','ready','served','cancelled') NOT NULL DEFAULT 'pending',
            notes TEXT DEFAULT NULL,
            printed_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_kots_client_branch (client_id, branch_id),
            KEY idx_kots_session (session_id),
            KEY idx_kots_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } else {
        ensure_kot_enum($conn, 'restaurant_kots');
        ensure_column($conn, 'restaurant_kots', 'notes', 'TEXT DEFAULT NULL');
        ensure_column($conn, 'restaurant_kots', 'printed_at', 'DATETIME DEFAULT NULL');
        ensure_column($conn, 'restaurant_kots', 'completed_at', 'DATETIME DEFAULT NULL');
        ensure_column($conn, 'restaurant_kots', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    }

    if (!table_exists($conn, 'restaurant_kot_items')) {
        $conn->query("CREATE TABLE restaurant_kot_items (
            id INT(11) NOT NULL AUTO_INCREMENT,
            kot_id INT(11) NOT NULL,
            order_item_id INT(11) NOT NULL,
            item_id INT(11) DEFAULT NULL,
            item_name VARCHAR(255) NOT NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            note TEXT DEFAULT NULL,
            status ENUM('pending','preparing','ready','served','cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ki_kot (kot_id),
            KEY idx_ki_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } else {
        ensure_kot_enum($conn, 'restaurant_kot_items');
        ensure_column($conn, 'restaurant_kot_items', 'note', 'TEXT DEFAULT NULL');
        ensure_column($conn, 'restaurant_kot_items', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    }

    if (!table_exists($conn, 'restaurant_kitchen_status_logs')) {
        $conn->query("CREATE TABLE restaurant_kitchen_status_logs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            client_id INT(11) NOT NULL,
            branch_id INT(11) NOT NULL DEFAULT 0,
            kot_id INT(11) DEFAULT NULL,
            kot_item_id INT(11) DEFAULT NULL,
            session_id INT(11) DEFAULT NULL,
            old_status VARCHAR(50) DEFAULT NULL,
            new_status VARCHAR(50) NOT NULL,
            notes TEXT DEFAULT NULL,
            changed_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ksl_client_branch (client_id, branch_id),
            KEY idx_ksl_kot (kot_id),
            KEY idx_ksl_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    if (table_exists($conn, 'restaurant_table_sessions')) {
        ensure_column($conn, 'restaurant_table_sessions', 'token_no', 'VARCHAR(50) DEFAULT NULL');
        ensure_column($conn, 'restaurant_table_sessions', 'customer_name', 'VARCHAR(150) DEFAULT NULL');
        ensure_column($conn, 'restaurant_table_sessions', 'customer_phone', 'VARCHAR(50) DEFAULT NULL');
        ensure_column($conn, 'restaurant_table_sessions', 'delivery_address', 'TEXT DEFAULT NULL');
        ensure_column($conn, 'restaurant_table_sessions', 'delivery_status', "ENUM('pending','preparing','ready','dispatched','delivered','cancelled') DEFAULT NULL");
        ensure_column($conn, 'restaurant_table_sessions', 'rider_id', 'INT(11) DEFAULT NULL');
        ensure_column($conn, 'restaurant_table_sessions', 'kitchen_status', "VARCHAR(50) DEFAULT 'pending'");
        ensure_column($conn, 'restaurant_table_sessions', 'kitchen_completed_at', 'DATETIME DEFAULT NULL');
    }
}

function canonical_kot_status(?string $s): string
{
    $s = strtolower(trim((string)$s));
    if ($s === 'processing' || $s === 'process') return 'preparing';
    if ($s === 'completed' || $s === 'complete') return 'served';
    if ($s === 'canceled') return 'cancelled';
    if (in_array($s, ['pending','preparing','ready','served','cancelled'], true)) return $s;
    return 'pending';
}

function status_label(string $s): string
{
    $s = canonical_kot_status($s);
    if ($s === 'preparing') return 'Preparing';
    if ($s === 'served') return 'Completed';
    return ucwords(str_replace('_', ' ', $s));
}

function status_class(string $s): string
{
    $s = canonical_kot_status($s);
    if ($s === 'pending') return 'st-pending';
    if ($s === 'preparing') return 'st-preparing';
    if ($s === 'ready') return 'st-ready';
    if ($s === 'served') return 'st-served';
    if ($s === 'cancelled') return 'st-cancelled';
    return 'st-muted';
}

function time_ago($time): string
{
    if (!$time) return '-';
    $ts = strtotime((string)$time);
    if (!$ts) return '-';
    $diff = time() - $ts;
    if ($diff < 60) return $diff . ' sec ago';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    return date('d M h:i A', $ts);
}

function build_status_url(string $status, int $branchFilter, string $dateFilter, string $search): string
{
    $qs = [
        'kot_status' => $status,
        'branch_id' => $branchFilter,
        'date' => $dateFilter,
        '_live' => time() . mt_rand(1000, 9999)
    ];
    if ($search !== '') $qs['q'] = $search;
    return '?' . http_build_query($qs);
}

$errorMessage = '';
try {
    ensure_kitchen_schema($conn);
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$statusFilter = strtolower(trim((string)($_GET['kot_status'] ?? ($_GET['status'] ?? 'active'))));
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$search = trim((string)($_GET['q'] ?? ''));
$dateFilter = trim((string)($_GET['date'] ?? date('Y-m-d')));

$validStatus = ['active', 'pending', 'preparing', 'ready'];
if ($statusFilter === 'processing' || $statusFilter === 'process') $statusFilter = 'preparing';
if (!in_array($statusFilter, $validStatus, true)) $statusFilter = 'active';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) $dateFilter = date('Y-m-d');

$liveStatusWhere = "LOWER(status) IN ('pending','preparing','processing','process','ready')";
$liveStatusWhereK = "LOWER(k.status) IN ('pending','preparing','processing','process','ready')";
$liveCompletedWhere = "(completed_at IS NULL OR completed_at = '0000-00-00 00:00:00')";
$liveCompletedWhereK = "(k.completed_at IS NULL OR k.completed_at = '0000-00-00 00:00:00')";

$branches = [];
try {
    if (table_exists($conn, 'branches')) {
        if ($is_superadmin) {
            $res = $conn->query("SELECT id, name FROM branches ORDER BY name ASC");
            while ($row = $res->fetch_assoc()) $branches[] = $row;
        } else {
            $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id=? ORDER BY name ASC");
            $stmt->bind_param('i', $client_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $branches[] = $row;
            $stmt->close();
        }
    }
} catch (Throwable $e) {}

$stats = ['pending' => 0, 'preparing' => 0, 'ready' => 0];
$kots = [];
$kotItems = [];

if (!$errorMessage) {
    try {
        $statWhere = [$liveStatusWhere, $liveCompletedWhere];
        $statTypes = '';
        $statParams = [];

        if (!$is_superadmin) {
            $statWhere[] = 'client_id=?';
            $statTypes .= 'i';
            $statParams[] = $client_id;
        }
        if ($branchFilter > 0) {
            $statWhere[] = 'branch_id=?';
            $statTypes .= 'i';
            $statParams[] = $branchFilter;
        }
        $statWhere[] = '(DATE(created_at)=? OR DATE(COALESCE(updated_at, created_at))=?)';
        $statTypes .= 'ss';
        $statParams[] = $dateFilter;
        $statParams[] = $dateFilter;

        $statSql = "SELECT
                        CASE
                            WHEN LOWER(status) IN ('preparing','processing','process') THEN 'preparing'
                            WHEN LOWER(status) = 'ready' THEN 'ready'
                            ELSE 'pending'
                        END AS status,
                        COUNT(*) total
                    FROM restaurant_kots
                    WHERE " . implode(' AND ', $statWhere) . "
                    GROUP BY
                        CASE
                            WHEN LOWER(status) IN ('preparing','processing','process') THEN 'preparing'
                            WHEN LOWER(status) = 'ready' THEN 'ready'
                            ELSE 'pending'
                        END";
        $stmt = $conn->prepare($statSql);
        if ($statTypes) $stmt->bind_param($statTypes, ...$statParams);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $key = canonical_kot_status((string)$row['status']);
            if (isset($stats[$key])) $stats[$key] += (int)$row['total'];
        }
        $stmt->close();

        $where = [];
        $types = '';
        $params = [];

        if (!$is_superadmin) {
            $where[] = 'k.client_id=?';
            $types .= 'i';
            $params[] = $client_id;
        }
        if ($branchFilter > 0) {
            $where[] = 'k.branch_id=?';
            $types .= 'i';
            $params[] = $branchFilter;
        }

        $where[] = $liveCompletedWhereK;
        if ($statusFilter === 'active') {
            $where[] = $liveStatusWhereK;
        } elseif ($statusFilter === 'preparing') {
            $where[] = "LOWER(k.status) IN ('preparing','processing','process')";
        } else {
            $where[] = 'LOWER(k.status)=?';
            $types .= 's';
            $params[] = $statusFilter;
        }

        $where[] = '(DATE(k.created_at)=? OR DATE(COALESCE(k.updated_at, k.created_at))=?)';
        $types .= 'ss';
        $params[] = $dateFilter;
        $params[] = $dateFilter;

        if ($search !== '') {
            $where[] = "(k.kot_no LIKE ?
                         OR t.table_no LIKE ?
                         OR t.table_name LIKE ?
                         OR w.name LIKE ?
                         OR ki.item_name LIKE ?
                         OR s.token_no LIKE ?
                         OR s.customer_name LIKE ?
                         OR s.customer_phone LIKE ?
                         OR s.delivery_address LIKE ?
                         OR u.name LIKE ?)";
            $types .= 'ssssssssss';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $sql = "SELECT k.*,
                       s.status AS session_status,
                       s.guest_count,
                       s.order_type,
                       s.token_no,
                       s.customer_name,
                       s.customer_phone,
                       s.delivery_address,
                       s.delivery_status,
                       t.table_no,
                       t.table_name,
                       t.floor_name,
                       w.name AS waiter_name,
                       u.name AS rider_name,
                       COUNT(ki.id) AS item_lines,
                       COALESCE(SUM(ki.qty),0) AS item_qty
                FROM restaurant_kots k
                LEFT JOIN restaurant_table_sessions s ON s.id = k.session_id
                LEFT JOIN restaurant_tables t ON t.id = k.table_id
                LEFT JOIN restaurant_waiters w ON w.id = k.waiter_id
                LEFT JOIN users u ON u.id = s.rider_id
                LEFT JOIN restaurant_kot_items ki ON ki.kot_id = k.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY k.id
                ORDER BY
                    CASE
                        WHEN LOWER(k.status) = 'ready' THEN 1
                        WHEN LOWER(k.status) IN ('preparing','processing','process') THEN 2
                        WHEN LOWER(k.status) = 'pending' THEN 3
                        ELSE 4
                    END,
                    k.id DESC
                LIMIT 150";

        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $kots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($kots) {
            $ids = array_map(static fn($x) => (int)$x['id'], $kots);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $types2 = str_repeat('i', count($ids));
            $stmt = $conn->prepare("SELECT * FROM restaurant_kot_items WHERE kot_id IN ($in) ORDER BY id ASC");
            $stmt->bind_param($types2, ...$ids);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $kotItems[(int)$row['kot_id']][] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$activeCount = $stats['pending'] + $stats['preparing'] + $stats['ready'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kitchen Display - One ERP</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
html,body{margin:0!important;padding:0!important;min-height:100%;overflow-x:hidden;background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.34),transparent 28%),radial-gradient(circle at 78% 2%,rgba(59,130,246,.16),transparent 30%),linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%)!important;color:#f8fafc!important;font-family:Inter,"Segoe UI",Arial,sans-serif}.container-scroll{display:flex!important;width:100%!important;min-height:100vh!important}.sidebar{width:270px;flex:0 0 270px}.main-panel{flex:1;min-width:0;min-height:100vh;padding-top:70px}.topbar,.navbar.my-navbar.fixed-top{background:#070b14!important;color:#fff!important;border-bottom:1px solid rgba(148,163,184,.14)!important;box-shadow:0 10px 30px rgba(0,0,0,.28)!important}.content-wrapper{position:relative;padding:26px 28px;min-height:calc(100vh - 70px);background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.34),transparent 28%),radial-gradient(circle at 78% 2%,rgba(59,130,246,.16),transparent 30%),linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%);overflow:visible}.content-wrapper:before{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:44px 44px;opacity:.24}.content-wrapper>*{position:relative;z-index:1}.page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:18px}.page-title h3{font-size:26px;font-weight:950;letter-spacing:-.04em;margin:0;text-shadow:0 0 28px rgba(124,58,237,.35)}.page-title p{color:#aab4cf;margin:5px 0 0;font-weight:750}.btn-glow{min-height:42px;border:0;border-radius:13px;padding:0 16px;font-weight:950;color:#fff!important;text-decoration:none;display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#6d4cff,#7c3aed);box-shadow:0 14px 30px rgba(109,76,255,.28)}.btn-soft{background:rgba(15,23,42,.72);border:1px solid rgba(148,163,184,.18);box-shadow:none}.stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:16px}.stat-card{border-radius:20px;padding:18px;border:1px solid rgba(148,163,184,.18);box-shadow:0 18px 48px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.05);overflow:hidden;position:relative}.stat-card:after{content:"";position:absolute;right:14px;top:14px;width:64px;height:64px;border-radius:18px;background:rgba(255,255,255,.11)}.stat-card span{font-weight:850;color:#fff;font-size:13px}.stat-card strong{display:block;margin-top:12px;font-size:31px;font-weight:950;color:#fff}.stat-pending{background:linear-gradient(135deg,#f59e0b,#f97316)}.stat-preparing{background:linear-gradient(135deg,#2563eb,#7c3aed)}.stat-ready{background:linear-gradient(135deg,#059669,#10b981)}.filter-card{background:rgba(15,23,42,.60);border:1px solid rgba(148,163,184,.18);border-radius:18px;padding:12px;margin-bottom:18px;box-shadow:0 16px 42px rgba(0,0,0,.26)}.filter-grid{display:grid;grid-template-columns:1fr 170px 170px 160px auto;gap:10px}.form-control,.form-select{min-height:44px;border-radius:13px!important;background:rgba(15,23,42,.74)!important;color:#f8fafc!important;border:1px solid rgba(148,163,184,.18)!important;font-weight:850!important}.form-control::placeholder{color:#94a3b8}.form-select option{background:#0f172a;color:#fff}.kds-pills{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}.kds-pill{min-height:38px;padding:0 18px;border-radius:999px;border:1px solid rgba(148,163,184,.18);background:rgba(15,23,42,.66);color:#e5e7eb;text-decoration:none;font-weight:950;display:inline-flex;align-items:center;gap:7px}.kds-pill.active{background:#fff;color:#0f172a;border-color:#fff;box-shadow:0 14px 34px rgba(0,0,0,.24)}.kitchen-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.kot-card{background:radial-gradient(circle at 20% 0%,rgba(124,58,237,.16),transparent 34%),linear-gradient(135deg,rgba(15,23,42,.82),rgba(17,24,39,.58));border:1px solid rgba(148,163,184,.18);border-radius:20px;box-shadow:0 18px 48px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05);overflow:hidden;transition:.2s ease}.kot-card.removing{opacity:0;transform:scale(.96) translateY(10px);pointer-events:none}.kot-card:hover{transform:translateY(-2px);border-color:rgba(124,58,237,.42);box-shadow:0 24px 65px rgba(0,0,0,.42),0 0 44px rgba(124,58,237,.14)}.kot-head{padding:15px 16px;border-bottom:1px solid rgba(148,163,184,.14);display:flex;justify-content:space-between;gap:10px}.kot-no{font-size:18px;font-weight:950;color:#fff}.kot-meta{color:#aab4cf;font-size:12px;font-weight:800;margin-top:3px}.st-pending,.st-preparing,.st-ready,.st-muted{border-radius:999px;padding:7px 11px;font-size:12px;font-weight:950;white-space:nowrap}.st-pending{background:rgba(245,158,11,.18);color:#fde68a}.st-preparing{background:rgba(59,130,246,.18);color:#bfdbfe}.st-ready{background:rgba(16,185,129,.18);color:#bbf7d0}.st-muted{background:rgba(148,163,184,.13);color:#cbd5e1}.kot-info{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:13px 16px;border-bottom:1px solid rgba(148,163,184,.10)}.info-chip{background:rgba(15,23,42,.58);border:1px solid rgba(148,163,184,.12);border-radius:14px;padding:10px}.info-chip span{display:block;color:#94a3b8;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em}.info-chip strong{display:block;margin-top:3px;color:#fff;font-weight:950;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.items-list{padding:14px 16px;min-height:152px}.kot-item{display:grid;grid-template-columns:46px 1fr auto;gap:8px;align-items:start;background:rgba(15,23,42,.54);border:1px solid rgba(148,163,184,.10);border-radius:14px;padding:10px;margin-bottom:8px}.kot-item .qty{font-size:15px;color:#fff;font-weight:950}.kot-item .name{color:#fff;font-size:14px;font-weight:950;line-height:1.25}.kot-item .note{color:#aab4cf;font-size:12px;font-weight:750;margin-top:3px}.item-status{font-size:11px;font-weight:950;border-radius:999px;padding:4px 7px;background:rgba(148,163,184,.13);color:#cbd5e1}.kot-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px;padding:0 16px 16px}.act-btn{border:0;min-height:40px;border-radius:12px;color:#fff;font-weight:950;background:linear-gradient(135deg,#6d4cff,#7c3aed)}.act-blue{background:linear-gradient(135deg,#2563eb,#3b82f6)}.act-green{background:linear-gradient(135deg,#059669,#10b981)}.act-soft{background:rgba(15,23,42,.72);border:1px solid rgba(148,163,184,.16)}.empty-state{grid-column:1/-1;min-height:280px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#94a3b8;background:rgba(15,23,42,.50);border:1px dashed rgba(148,163,184,.22);border-radius:20px}.empty-state i{font-size:48px;color:#7c3aed}.toast-box{position:fixed;right:22px;bottom:22px;z-index:9999;display:grid;gap:10px}.toast-msg{background:rgba(15,23,42,.95);border:1px solid rgba(148,163,184,.18);border-radius:14px;color:#fff;padding:12px 14px;box-shadow:0 16px 45px rgba(0,0,0,.35);font-weight:850}.toast-msg.ok{border-color:rgba(16,185,129,.35)}.toast-msg.err{border-color:rgba(239,68,68,.35)}.alert-glass{background:rgba(220,38,38,.15);border:1px solid rgba(248,113,113,.25);color:#fecaca;border-radius:16px;padding:16px;font-weight:850}.order-badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:950;background:rgba(124,58,237,.18);color:#ddd6fe}.order-badge.takeaway{background:rgba(16,185,129,.16);color:#bbf7d0}.order-badge.delivery{background:rgba(249,115,22,.16);color:#fed7aa}.customer-line{font-size:12px;color:#cbd5e1;font-weight:800;margin-top:4px;line-height:1.4}.kot-card.status-pending{border-color:rgba(245,158,11,.45)}.kot-card.status-preparing{border-color:rgba(59,130,246,.45)}.kot-card.status-ready{border-color:rgba(16,185,129,.55)}.kot-card.urgency-green{border-color:rgba(16,185,129,.62)!important;box-shadow:0 18px 48px rgba(0,0,0,.30),0 0 0 1px rgba(16,185,129,.18),0 0 34px rgba(16,185,129,.10)!important}.kot-card.urgency-yellow{border-color:rgba(245,158,11,.78)!important;box-shadow:0 18px 48px rgba(0,0,0,.30),0 0 0 1px rgba(245,158,11,.22),0 0 38px rgba(245,158,11,.15)!important}.kot-card.urgency-red{border-color:rgba(239,68,68,.92)!important;box-shadow:0 18px 48px rgba(0,0,0,.34),0 0 0 1px rgba(239,68,68,.28),0 0 44px rgba(239,68,68,.24)!important;animation:kdsDelayPulse 1.2s ease-in-out infinite}.live-kot-timer{font-weight:950;color:#fff}.urgency-pill{display:inline-flex;align-items:center;margin-left:7px;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:950;text-transform:uppercase;letter-spacing:.04em}.urgency-pill-green{background:rgba(16,185,129,.18);color:#bbf7d0}.urgency-pill-yellow{background:rgba(245,158,11,.20);color:#fde68a}.urgency-pill-red{background:rgba(239,68,68,.22);color:#fecaca}.delay-alert{display:none;margin:10px 16px 0;border:1px solid rgba(239,68,68,.38);background:rgba(239,68,68,.16);color:#fecaca;border-radius:13px;padding:9px 11px;font-size:12px;font-weight:950}.kot-card.urgency-red .delay-alert{display:flex;align-items:center;gap:7px}@keyframes kdsDelayPulse{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}@media(max-width:1200px){.kitchen-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-grid{grid-template-columns:1fr 1fr}}@media(max-width:991px){.sidebar{display:block}.main-panel{padding-top:70px}.content-wrapper{padding:16px}.kitchen-grid{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-grid{grid-template-columns:1fr}.kot-info{grid-template-columns:1fr}}@media(max-width:576px){.stats-grid{grid-template-columns:1fr}.kot-actions{grid-template-columns:1fr}.page-head{display:block}.page-head .d-flex{margin-top:12px}}
</style>
</head>
<body>
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?>

        <div class="content-wrapper">
            <div class="page-head">
                <div class="page-title">
                    <h3>Kitchen Display / KOT</h3>
                    <p>Flow: KOT appears from POS → Start = Preparing → Mark as Ready = Ready → Complete Order = removed from Kitchen.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn-glow btn-soft" href="restaurant_tables.php"><i class="bi bi-grid"></i> Tables</a>
                    <a class="btn-glow btn-soft" href="restaurant_orders.php?tab=completed"><i class="bi bi-list-check"></i> Order Management</a>
                    <button class="btn-glow" type="button" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                </div>
            </div>

            <?php if ($errorMessage): ?>
                <div class="alert-glass mb-3"><i class="bi bi-exclamation-triangle"></i> <?= e($errorMessage) ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card stat-pending"><span><i class="bi bi-hourglass-split"></i> Pending</span><strong><?= (int)$stats['pending'] ?></strong></div>
                <div class="stat-card stat-preparing"><span><i class="bi bi-fire"></i> Preparing</span><strong><?= (int)$stats['preparing'] ?></strong></div>
                <div class="stat-card stat-ready"><span><i class="bi bi-check-circle"></i> Ready</span><strong><?= (int)$stats['ready'] ?></strong></div>
            </div>

            <div class="kds-pills">
                <a class="kds-pill <?= $statusFilter === 'active' ? 'active' : '' ?>" href="<?= e(build_status_url('active', $branchFilter, $dateFilter, $search)) ?>"><i class="bi bi-grid"></i> All Live (<?= (int)$activeCount ?>)</a>
                <a class="kds-pill <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="<?= e(build_status_url('pending', $branchFilter, $dateFilter, $search)) ?>">Pending (<?= (int)$stats['pending'] ?>)</a>
                <a class="kds-pill <?= $statusFilter === 'preparing' ? 'active' : '' ?>" href="<?= e(build_status_url('preparing', $branchFilter, $dateFilter, $search)) ?>">Preparing (<?= (int)$stats['preparing'] ?>)</a>
                <a class="kds-pill <?= $statusFilter === 'ready' ? 'active' : '' ?>" href="<?= e(build_status_url('ready', $branchFilter, $dateFilter, $search)) ?>">Ready (<?= (int)$stats['ready'] ?>)</a>
            </div>

            <form class="filter-card" method="get">
                <input type="hidden" name="_live" value="<?= e((string)(time() . mt_rand(1000, 9999))) ?>">
                <div class="filter-grid">
                    <input type="text" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Search KOT no, table, waiter, item...">
                    <select name="kot_status" class="form-select">
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>All Live KOTs</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="preparing" <?= $statusFilter === 'preparing' ? 'selected' : '' ?>>Preparing</option>
                        <option value="ready" <?= $statusFilter === 'ready' ? 'selected' : '' ?>>Ready</option>
                    </select>
                    <select name="branch_id" class="form-select">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $branchFilter === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date" class="form-control" value="<?= e($dateFilter) ?>">
                    <button class="btn-glow" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                </div>
            </form>

            <div class="kitchen-grid" id="kitchenGrid">
                <?php if (!$kots): ?>
                    <div class="empty-state" id="emptyState">
                        <i class="bi bi-inbox"></i>
                        <h5 class="mt-2 mb-1">No live KOT found</h5>
                    </div>
                <?php else: foreach ($kots as $kot):
                    $orderType = strtolower((string)($kot['order_type'] ?? 'dine_in')) ?: 'dine_in';
                    $typeLabel = ucwords(str_replace('_', ' ', $orderType));

                    if ($orderType === 'takeaway') {
                        $tableTitle = !empty($kot['token_no']) ? ('Token ' . $kot['token_no']) : 'Takeaway';
                    } elseif ($orderType === 'delivery') {
                        $tableTitle = !empty($kot['token_no']) ? ('Delivery ' . $kot['token_no']) : 'Delivery';
                    } else {
                        $tableTitle = 'Table / Dine In';
                        if (!empty($kot['table_no'])) {
                            $tableTitle = 'Table ' . $kot['table_no'];
                            if (!empty($kot['table_name'])) $tableTitle .= ' - ' . $kot['table_name'];
                        }
                    }

                    $customerLine = trim((string)($kot['customer_name'] ?? ''));
                    if (!empty($kot['customer_phone'])) $customerLine .= ($customerLine ? ' · ' : '') . $kot['customer_phone'];

                    $status = canonical_kot_status((string)$kot['status']);
                    $items = $kotItems[(int)$kot['id']] ?? [];
                ?>
                    <div class="kot-card status-<?= e($status) ?>" id="kot-card-<?= (int)$kot['id'] ?>" data-created-ts="<?= (int)strtotime((string)$kot['created_at']) ?>" data-kot-no="<?= e($kot['kot_no']) ?>">
                        <div class="kot-head">
                            <div>
                                <div class="kot-no">
                                    <?= e($kot['kot_no']) ?>
                                    <span class="order-badge <?= e($orderType) ?>"><?= e($typeLabel) ?></span>
                                </div>
                                <div class="kot-meta"><i class="bi bi-clock"></i> <span class="live-kot-timer" data-created-ts="<?= (int)strtotime((string)$kot['created_at']) ?>"><?= e(time_ago($kot['created_at'])) ?></span> · <?= e(date('h:i A', strtotime($kot['created_at']))) ?> <span class="urgency-pill urgency-pill-green">On Time</span></div>
                                <div class="delay-alert"><i class="bi bi-exclamation-triangle-fill"></i> Delayed order: over 20 minutes</div>
                                <?php if ($customerLine): ?><div class="customer-line"><i class="bi bi-person"></i> <?= e($customerLine) ?></div><?php endif; ?>
                            </div>
                            <span class="<?= e(status_class($status)) ?>"><?= e(status_label($status)) ?></span>
                        </div>

                        <div class="kot-info">
                            <div class="info-chip"><span><?= $orderType === 'dine_in' ? 'Table' : 'Order' ?></span><strong><?= e($tableTitle) ?></strong></div>
                            <div class="info-chip"><span><?= $orderType === 'delivery' ? 'Rider' : 'Waiter' ?></span><strong><?= e($orderType === 'delivery' ? ($kot['rider_name'] ?: 'Not Assigned') : ($kot['waiter_name'] ?: 'N/A')) ?></strong></div>
                            <div class="info-chip"><span>Items</span><strong><?= (int)$kot['item_lines'] ?> lines / <?= e(fmt_qty($kot['item_qty'])) ?> qty</strong></div>
                            <div class="info-chip"><span>KOT Status</span><strong><?= e(status_label($status)) ?></strong></div>
                            <?php if ($orderType === 'delivery' && !empty($kot['delivery_address'])): ?>
                                <div class="info-chip" style="grid-column:1/-1"><span>Address</span><strong><?= e($kot['delivery_address']) ?></strong></div>
                            <?php endif; ?>
                        </div>

                        <div class="items-list">
                            <?php if (!$items): ?>
                                <div class="text-center text-secondary fw-bold py-4">No KOT items</div>
                            <?php else: foreach ($items as $it): ?>
                                <div class="kot-item">
                                    <div class="qty"><?= e(fmt_qty($it['qty'])) ?>x</div>
                                    <div>
                                        <div class="name"><?= e($it['item_name']) ?></div>
                                        <?php if (!empty($it['note'])): ?><div class="note"><i class="bi bi-chat-left-text"></i> <?= e($it['note']) ?></div><?php endif; ?>
                                    </div>
                                    <span class="item-status"><?= e(status_label((string)$it['status'])) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>

                        <div class="kot-actions">
                            <?php if ($status === 'pending'): ?>
                                <button class="act-btn act-blue" type="button" onclick="startKot(<?= (int)$kot['id'] ?>)"><i class="bi bi-fire"></i> Start</button>
                                <a class="act-btn act-soft d-flex align-items-center justify-content-center text-decoration-none" href="restaurant_kot_print.php?kot_id=<?= (int)$kot['id'] ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
                            <?php elseif ($status === 'preparing'): ?>
                                <button class="act-btn act-green" type="button" onclick="markReady(<?= (int)$kot['id'] ?>)"><i class="bi bi-check2-circle"></i> Mark as Ready</button>
                                <a class="act-btn act-soft d-flex align-items-center justify-content-center text-decoration-none" href="restaurant_kot_print.php?kot_id=<?= (int)$kot['id'] ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
                            <?php elseif ($status === 'ready'): ?>
                                <button class="act-btn act-green" type="button" onclick="completeKot(<?= (int)$kot['id'] ?>)"><i class="bi bi-check2-all"></i> Complete</button>
                                <a class="act-btn act-soft d-flex align-items-center justify-content-center text-decoration-none" href="restaurant_kot_print.php?kot_id=<?= (int)$kot['id'] ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="toast-box" id="toastBox"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const currentBranch = <?= (int)$branchFilter ?>;
const currentDate = <?= json_encode($dateFilter) ?>;
const currentSearch = <?= json_encode($search) ?>;

function toast(msg, type = 'ok') {
    const box = document.getElementById('toastBox');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + (type === 'err' ? 'err' : 'ok');
    el.innerHTML = msg;
    box.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(8px)';
        setTimeout(() => el.remove(), 300);
    }, 3000);
}

function formatKitchenElapsed(seconds) {
    seconds = Math.max(0, parseInt(seconds || 0, 10));
    const mins = Math.floor(seconds / 60);
    const hrs = Math.floor(mins / 60);

    if (seconds < 60) return seconds + ' sec ago';
    if (mins < 60) return mins + ' min ago';

    const rem = mins % 60;
    return hrs + ' hr' + (hrs > 1 ? 's' : '') + (rem ? ' ' + rem + ' min' : '') + ' ago';
}

function updateKdsTimers() {
    const now = Math.floor(Date.now() / 1000);
    let delayedCount = 0;

    document.querySelectorAll('.kot-card[data-created-ts]').forEach(card => {
        const createdTs = parseInt(card.getAttribute('data-created-ts') || '0', 10);
        if (!createdTs) return;

        const diff = Math.max(0, now - createdTs);
        const minutes = Math.floor(diff / 60);
        const timer = card.querySelector('.live-kot-timer');
        const pill = card.querySelector('.urgency-pill');

        if (timer) timer.textContent = formatKitchenElapsed(diff);

        card.classList.remove('urgency-green', 'urgency-yellow', 'urgency-red');

        let cardClass = 'urgency-green';
        let pillClass = 'urgency-pill urgency-pill-green';
        let pillText = 'On Time';

        if (minutes >= 20) {
            cardClass = 'urgency-red';
            pillClass = 'urgency-pill urgency-pill-red';
            pillText = 'Delayed';
            delayedCount++;
        } else if (minutes >= 10) {
            cardClass = 'urgency-yellow';
            pillClass = 'urgency-pill urgency-pill-yellow';
            pillText = 'Warning';
        }

        card.classList.add(cardClass);
        if (pill) {
            pill.className = pillClass;
            pill.textContent = pillText;
        }
    });

    if (delayedCount > 0 && !window.__kdsDelayedAlertShown) {
        window.__kdsDelayedAlertShown = true;
        toast('Delayed KOT alert: ' + delayedCount + ' order(s) over 20 minutes.', 'err');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    updateKdsTimers();
    setInterval(updateKdsTimers, 1000);
});

window.addEventListener('pageshow', function (event) {
    const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    const isBackForward = event.persisted || (nav && nav.type === 'back_forward');
    if (isBackForward) {
        const url = new URL(window.location.href);
        url.searchParams.set('_live', String(Date.now()));
        window.location.replace(url.toString());
    }
});

function statusUrl(status) {
    const params = new URLSearchParams();
    params.set('kot_status', status);
    params.set('branch_id', String(currentBranch));
    params.set('date', currentDate);
    if (currentSearch) params.set('q', currentSearch);
    params.set('_live', String(Date.now()));
    return '?' + params.toString();
}

function postForm(url, data) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    return fetch(url, {method: 'POST', body: fd, cache: 'no-store', headers: {'Accept': 'application/json'}}).then(async r => {
        const text = await r.text();
        try { return JSON.parse(text); } catch (e) { throw new Error(text || 'Invalid server response'); }
    });
}

function removeKotCard(kotId) {
    const card = document.getElementById('kot-card-' + kotId);
    if (!card) return;
    card.classList.add('removing');
    setTimeout(() => {
        card.remove();
        const grid = document.getElementById('kitchenGrid');
        if (grid && !grid.querySelector('.kot-card')) {
            grid.innerHTML = `<div class="empty-state" id="emptyState"><i class="bi bi-inbox"></i><h5 class="mt-2 mb-1">No live KOT found</h5><p class="mb-0">Completed KOT Kitchen se remove ho chuka hai.</p></div>`;
        }
    }, 250);
}

function updateKotStatus(kotId, status) {
    const labels = {preparing: 'start this order and move it to Preparing', ready: 'mark this order as Ready', served: 'complete this order and remove it from Kitchen'};
    if (!confirm('Are you sure you want to ' + (labels[status] || status) + '?')) return;

    postForm('ajax_restaurant_update_kot_status.php', {kot_id: kotId, status: status, notes: status === 'served' ? 'Kitchen completed' : ''})
    .then(data => {
        if (!data.success) { toast(data.message || 'Update failed', 'err'); return; }
        toast(data.message || 'Updated');

        if (data.remove_from_kitchen || status === 'served') {
            removeKotCard(kotId);
            setTimeout(() => { window.location.replace(statusUrl('active')); }, 650);
            return;
        }

        // Status change ke baad tab auto change nahi hoga.
        // Pending / Preparing / Ready tabs sirf filter ki tarah manually kaam karenge.
        // Har update ke baad All Live page reload hoga taake same workflow visible rahe.
        setTimeout(() => { window.location.replace(statusUrl('active')); }, 450);
    })
    .catch(err => toast(err.message, 'err'));
}

function startKot(kotId) { updateKotStatus(kotId, 'preparing'); }
function markReady(kotId) { updateKotStatus(kotId, 'ready'); }
function completeKot(kotId) { updateKotStatus(kotId, 'served'); }
</script>
</body>
</html>
