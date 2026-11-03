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
    die('Database connection not found.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_superadmin = ($role_id === 1);

if (!$is_superadmin && $client_id <= 0) {
    die('Client not found in session.');
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function json_out(array $arr): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }
function qnum($v): float { return is_numeric($v) ? (float)$v : 0.0; }

function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function ensure_batches_schema(mysqli $conn): array {
    $warnings = [];
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS production_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            branch_id INT NULL DEFAULT NULL,
            work_order_id INT NOT NULL,
            work_order_output_id INT NULL DEFAULT NULL,
            item_id INT NOT NULL,
            batch_no VARCHAR(100) NOT NULL,
            mfg_date DATE NULL,
            expiry_date DATE NULL,
            qty DECIMAL(15,4) NOT NULL DEFAULT 0,
            unit VARCHAR(50) NULL,
            output_type ENUM('main','by_product','scrap','waste') NOT NULL DEFAULT 'main',
            status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pb_client_batch (client_id, batch_no),
            INDEX idx_pb_client (client_id),
            INDEX idx_pb_branch (branch_id),
            INDEX idx_pb_order (work_order_id),
            INDEX idx_pb_output (work_order_output_id),
            INDEX idx_pb_item (item_id),
            INDEX idx_pb_status (status),
            INDEX idx_pb_expiry (expiry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $adds = [
            'branch_id' => "ALTER TABLE production_batches ADD COLUMN branch_id INT NULL DEFAULT NULL AFTER client_id",
            'work_order_output_id' => "ALTER TABLE production_batches ADD COLUMN work_order_output_id INT NULL DEFAULT NULL AFTER work_order_id",
            'output_type' => "ALTER TABLE production_batches ADD COLUMN output_type ENUM('main','by_product','scrap','waste') NOT NULL DEFAULT 'main' AFTER unit",
            'status' => "ALTER TABLE production_batches ADD COLUMN status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active' AFTER output_type",
            'notes' => "ALTER TABLE production_batches ADD COLUMN notes TEXT NULL AFTER status",
            'created_by' => "ALTER TABLE production_batches ADD COLUMN created_by INT NULL AFTER notes",
            'updated_at' => "ALTER TABLE production_batches ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
        ];
        foreach ($adds as $col => $sql) {
            if (!column_exists($conn, 'production_batches', $col)) {
                try { $conn->query($sql); } catch (Throwable $e) { $warnings[] = $e->getMessage(); }
            }
        }
    } catch (Throwable $e) {
        $warnings[] = 'Batch schema error: ' . $e->getMessage();
    }
    return $warnings;
}

$schemaWarnings = ensure_batches_schema($conn);

function scope_sql(string $alias = ''): string {
    global $is_superadmin, $client_id;
    $p = $alias ? $alias . '.' : '';
    return $is_superadmin ? '1=1' : $p . 'client_id=' . (int)$client_id;
}

function next_batch_no(mysqli $conn, int $client_id): string {
    $prefix = 'BATCH-' . date('Ymd') . '-';
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT batch_no FROM production_batches WHERE client_id=? AND batch_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('is', $client_id, $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc()['batch_no'] ?? '';
    $stmt->close();
    $n = 1;
    if ($last && preg_match('/-(\d+)$/', $last, $m)) {
        $n = ((int)$m[1]) + 1;
    }
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function get_output_row(mysqli $conn, int $output_id, int $order_id = 0): ?array {
    $sql = "SELECT woo.*, wo.client_id, wo.branch_id, wo.work_order_no, wo.status order_status, i.name item_name
            FROM production_work_order_outputs woo
            INNER JOIN production_work_orders wo ON wo.id = woo.work_order_id
            LEFT JOIN items i ON i.id = woo.item_id
            WHERE woo.id=? AND " . scope_sql('wo');
    if ($order_id > 0) $sql .= " AND woo.work_order_id=" . (int)$order_id;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $output_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function already_batched_qty(mysqli $conn, int $output_id, int $exclude_batch_id = 0): float {
    $sql = "SELECT COALESCE(SUM(qty),0) qty FROM production_batches WHERE work_order_output_id=? AND status <> 'cancelled'";
    if ($exclude_batch_id > 0) $sql .= " AND id <> " . (int)$exclude_batch_id;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $output_id);
    $stmt->execute();
    $qty = (float)($stmt->get_result()->fetch_assoc()['qty'] ?? 0);
    $stmt->close();
    return $qty;
}

/* ================= AJAX ================= */
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];

    if ($ajax === 'next_batch_no') {
        json_out(['ok' => true, 'batch_no' => next_batch_no($conn, $client_id)]);
    }

    if ($ajax === 'order_outputs') {
        $order_id = (int)($_GET['order_id'] ?? 0);
        if ($order_id <= 0) json_out(['ok' => false, 'message' => 'Invalid work order.']);

        $stmt = $conn->prepare("SELECT id, work_order_no, status FROM production_work_orders WHERE id=? AND " . scope_sql() . " LIMIT 1");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) json_out(['ok' => false, 'message' => 'Work order not found.']);

        $stmt = $conn->prepare("SELECT woo.*, i.name item_name, i.stock_qty
                                FROM production_work_order_outputs woo
                                LEFT JOIN items i ON i.id = woo.item_id
                                WHERE woo.work_order_id=?
                                ORDER BY FIELD(woo.output_type,'main','by_product','scrap','waste'), woo.id ASC");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $outputs = [];
        while ($r = $res->fetch_assoc()) {
            $batched = already_batched_qty($conn, (int)$r['id']);
            $r['batched_qty'] = $batched;
            $r['remaining_qty'] = max(0, (float)$r['actual_qty'] - $batched);
            $outputs[] = $r;
        }
        $stmt->close();
        json_out(['ok' => true, 'order' => $order, 'outputs' => $outputs]);
    }

    if ($ajax === 'get_batch') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT pb.*, wo.work_order_no, wo.status order_status, i.name item_name
                                FROM production_batches pb
                                LEFT JOIN production_work_orders wo ON wo.id = pb.work_order_id
                                LEFT JOIN items i ON i.id = pb.item_id
                                WHERE pb.id=? AND " . scope_sql('pb') . " LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $batch = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$batch) json_out(['ok' => false, 'message' => 'Batch not found.']);
        json_out(['ok' => true, 'batch' => $batch]);
    }

    if ($ajax === 'view_batch') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT pb.*, wo.work_order_no, wo.status order_status, wo.production_date, wo.total_cost, wo.cost_per_unit,
                                       pf.formula_name, i.name item_name, i.sku, i.stock_qty
                                FROM production_batches pb
                                LEFT JOIN production_work_orders wo ON wo.id = pb.work_order_id
                                LEFT JOIN production_formulas pf ON pf.id = wo.formula_id
                                LEFT JOIN items i ON i.id = pb.item_id
                                WHERE pb.id=? AND " . scope_sql('pb') . " LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $batch = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$batch) json_out(['ok' => false, 'message' => 'Batch not found.']);
        json_out(['ok' => true, 'batch' => $batch]);
    }

    json_out(['ok' => false, 'message' => 'Invalid ajax request.']);
}

if (isset($_POST['ajax'])) {
    $ajax = $_POST['ajax'];

    if ($ajax === 'save_batch') {
        $id = (int)($_POST['id'] ?? 0);
        $work_order_id = (int)($_POST['work_order_id'] ?? 0);
        $output_id = (int)($_POST['work_order_output_id'] ?? 0);
        $batch_no = trim((string)($_POST['batch_no'] ?? ''));
        $mfg_date = trim((string)($_POST['mfg_date'] ?? '')) ?: null;
        $expiry_date = trim((string)($_POST['expiry_date'] ?? '')) ?: null;
        $qty = qnum($_POST['qty'] ?? 0);
        $unit = trim((string)($_POST['unit'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'active'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if (!in_array($status, ['active', 'expired', 'cancelled'], true)) $status = 'active';
        if ($work_order_id <= 0) json_out(['ok' => false, 'message' => 'Work order select karo.']);
        if ($output_id <= 0) json_out(['ok' => false, 'message' => 'Output item select karo.']);
        if ($qty <= 0) json_out(['ok' => false, 'message' => 'Batch quantity 0 se zyada honi chahiye.']);
        if ($batch_no === '') $batch_no = next_batch_no($conn, $client_id);

        $output = get_output_row($conn, $output_id, $work_order_id);
        if (!$output) json_out(['ok' => false, 'message' => 'Selected output item valid nahi hai.']);
        if (($output['order_status'] ?? '') !== 'completed') json_out(['ok' => false, 'message' => 'Batch sirf completed work order se ban sakta hai.']);

        $allowedTotal = (float)$output['actual_qty'];
        $already = already_batched_qty($conn, $output_id, $id);
        if (($already + $qty) > ($allowedTotal + 0.0001)) {
            json_out(['ok' => false, 'message' => 'Batch qty output actual qty se zyada hai. Remaining: ' . number_format(max(0, $allowedTotal - $already), 4)]);
        }

        try {
            $conn->begin_transaction();
            if ($id > 0) {
                $stmt = $conn->prepare("SELECT id FROM production_batches WHERE id=? AND " . scope_sql() . " LIMIT 1");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$exists) throw new Exception('Batch not found.');

                $stmt = $conn->prepare("UPDATE production_batches
                                        SET work_order_id=?, work_order_output_id=?, item_id=?, batch_no=?, mfg_date=?, expiry_date=?, qty=?, unit=?, output_type=?, status=?, notes=?
                                        WHERE id=? AND " . scope_sql());
                $item_id = (int)$output['item_id'];
                $output_type = (string)$output['output_type'];
                $stmt->bind_param('iiisssdssssi', $work_order_id, $output_id, $item_id, $batch_no, $mfg_date, $expiry_date, $qty, $unit, $output_type, $status, $notes, $id);
                $stmt->execute();
                $stmt->close();
            } else {
                $batch_client_id = (int)$output['client_id'];
                $batch_branch_id = (int)($output['branch_id'] ?? 0);
                if (!$is_superadmin) {
                    $batch_client_id = $client_id;
                    $batch_branch_id = $branch_id ?: $batch_branch_id;
                }
                $item_id = (int)$output['item_id'];
                $output_type = (string)$output['output_type'];
                $stmt = $conn->prepare("INSERT INTO production_batches
                    (client_id, branch_id, work_order_id, work_order_output_id, item_id, batch_no, mfg_date, expiry_date, qty, unit, output_type, status, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iiiiisssdssssi', $batch_client_id, $batch_branch_id, $work_order_id, $output_id, $item_id, $batch_no, $mfg_date, $expiry_date, $qty, $unit, $output_type, $status, $notes, $user_id);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
            json_out(['ok' => true, 'message' => 'Batch saved successfully.']);
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $x) {}
            if (stripos($e->getMessage(), 'Duplicate') !== false) {
                json_out(['ok' => false, 'message' => 'Batch No already exists. Unique batch no use karo.']);
            }
            json_out(['ok' => false, 'message' => 'Save error: ' . $e->getMessage()]);
        }
    }

    if ($ajax === 'delete_batch') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'message' => 'Invalid batch id.']);
        try {
            $stmt = $conn->prepare("UPDATE production_batches SET status='cancelled' WHERE id=? AND " . scope_sql());
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            json_out(['ok' => true, 'message' => 'Batch cancelled.']);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'message' => 'Cancel error: ' . $e->getMessage()]);
        }
    }

    if ($ajax === 'mark_expired') {
        try {
            $conn->query("UPDATE production_batches SET status='expired' WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status='active' AND " . scope_sql());
            json_out(['ok' => true, 'message' => 'Expired batches updated.']);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    json_out(['ok' => false, 'message' => 'Invalid request.']);
}

/* ================= PAGE DATA ================= */
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$expiryFilter = trim((string)($_GET['expiry'] ?? ''));

$where = [scope_sql('pb')];
$types = '';
$params = [];
if ($search !== '') {
    $where[] = "(pb.batch_no LIKE ? OR wo.work_order_no LIKE ? OR i.name LIKE ? OR pf.formula_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if (in_array($statusFilter, ['active','expired','cancelled'], true)) {
    $where[] = "pb.status=?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($expiryFilter === 'expired') {
    $where[] = "pb.expiry_date IS NOT NULL AND pb.expiry_date < CURDATE()";
} elseif ($expiryFilter === 'next30') {
    $where[] = "pb.expiry_date IS NOT NULL AND pb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($expiryFilter === 'no_expiry') {
    $where[] = "pb.expiry_date IS NULL";
}

$listSql = "SELECT pb.*, wo.work_order_no, wo.status order_status, wo.production_date, pf.formula_name, i.name item_name, i.sku
            FROM production_batches pb
            LEFT JOIN production_work_orders wo ON wo.id = pb.work_order_id
            LEFT JOIN production_formulas pf ON pf.id = wo.formula_id
            LEFT JOIN items i ON i.id = pb.item_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pb.id DESC
            LIMIT 250";
$stmt = $conn->prepare($listSql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$ordersSql = "SELECT wo.id, wo.work_order_no, wo.production_date, pf.formula_name
              FROM production_work_orders wo
              LEFT JOIN production_formulas pf ON pf.id = wo.formula_id
              WHERE wo.status='completed' AND " . scope_sql('wo') . "
              ORDER BY wo.id DESC LIMIT 300";
$completedOrders = $conn->query($ordersSql)->fetch_all(MYSQLI_ASSOC);

$stats = ['total'=>0,'active'=>0,'expired'=>0,'next30'=>0,'qty'=>0];
$statsSql = "SELECT
    COUNT(*) total,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) active,
    SUM(CASE WHEN (status='expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE())) THEN 1 ELSE 0 END) expired,
    SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status <> 'cancelled' THEN 1 ELSE 0 END) next30,
    COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN qty ELSE 0 END),0) qty
    FROM production_batches pb WHERE " . scope_sql('pb');
$row = $conn->query($statsSql)->fetch_assoc();
if ($row) {
    $stats = [
        'total' => (int)$row['total'],
        'active' => (int)$row['active'],
        'expired' => (int)$row['expired'],
        'next30' => (int)$row['next30'],
        'qty' => (float)$row['qty'],
    ];
}

$nextBatchNo = next_batch_no($conn, $client_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Production Batches - One ERP</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{--bg:#070b14;--panel:#0b1220;--glass:rgba(15,23,42,.74);--line:rgba(148,163,184,.16);--text:#f8fafc;--muted:#94a3b8;--purple:#7c3aed;--purple2:#6d4cff;--green:#10b981;--red:#ef4444;--orange:#f59e0b;--cyan:#06b6d4;}
html,body{margin:0!important;min-height:100%;overflow-x:hidden;background:radial-gradient(circle at 14% 6%,rgba(124,58,237,.34),transparent 26%),radial-gradient(circle at 80% 4%,rgba(6,182,212,.14),transparent 28%),linear-gradient(135deg,#050914 0%,#0b1024 48%,#160f2d 100%)!important;color:var(--text)!important;font-family:Inter,"Segoe UI",Arial,sans-serif;}
.container-scroll{display:flex;min-height:100vh;background:transparent!important}.sidebar{flex:0 0 270px}.main-panel{flex:1;min-width:0;margin-left:270px;padding-top:70px;transition:.25s ease}.content-wrapper{padding:28px;min-height:calc(100vh - 70px);position:relative}.content-wrapper:before{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:42px 42px;opacity:.25;pointer-events:none}.content-wrapper>*{position:relative;z-index:1}
body.sidebar-collapsed .main-panel{margin-left:0!important}.page-title{font-size:32px;font-weight:950;letter-spacing:-.05em;text-shadow:0 0 28px rgba(124,58,237,.35)}
.btn-main{border:0;border-radius:14px;padding:13px 20px;background:linear-gradient(135deg,#16a34a,#10b981);color:#fff;font-weight:900;box-shadow:0 14px 30px rgba(16,185,129,.24)}.btn-purple{border:0;border-radius:14px;padding:13px 20px;background:linear-gradient(135deg,var(--purple2),var(--purple));color:#fff;font-weight:900;box-shadow:0 14px 30px rgba(124,58,237,.26)}.btn-glass{border:1px solid var(--line);border-radius:14px;padding:12px 18px;background:rgba(15,23,42,.72);color:#e5e7eb;font-weight:900}.btn-main:hover,.btn-purple:hover,.btn-glass:hover{transform:translateY(-2px);color:#fff!important}
.stat-card{min-height:128px;border-radius:22px;padding:26px;background:linear-gradient(135deg,rgba(15,23,42,.76),rgba(30,20,58,.58));border:1px solid rgba(148,163,184,.16);box-shadow:0 20px 55px rgba(0,0,0,.32),inset 0 1px 0 rgba(255,255,255,.06);overflow:hidden;position:relative}.stat-card:after{content:"";position:absolute;right:18px;top:18px;width:86px;height:86px;border-radius:20px;background:rgba(255,255,255,.11)}.stat-label{font-size:15px;color:#e5e7eb;font-weight:900}.stat-value{position:absolute;right:36px;top:34px;font-size:36px;font-weight:950;z-index:2}.stat-blue{background:linear-gradient(135deg,#2563eb,#7c3aed)}.stat-green{background:linear-gradient(135deg,#059669,#16a34a)}.stat-orange{background:linear-gradient(135deg,#f59e0b,#fb923c)}.stat-red{background:linear-gradient(135deg,#be123c,#ef4444)}
.glass{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.14),transparent 36%),linear-gradient(135deg,rgba(15,23,42,.78),rgba(17,24,39,.58));border:1px solid rgba(148,163,184,.16);border-radius:20px;box-shadow:0 18px 48px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.05);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
.filter-card{padding:16px;margin:18px 0}.form-control,.form-select,textarea{background:rgba(15,23,42,.78)!important;color:#f8fafc!important;border:1px solid rgba(148,163,184,.2)!important;border-radius:14px!important;min-height:48px!important;font-weight:800!important}.form-control:focus,.form-select:focus,textarea:focus{border-color:rgba(124,58,237,.62)!important;box-shadow:0 0 0 3px rgba(124,58,237,.15)!important}.form-control::placeholder{color:#94a3b8!important}select option{background:#0f172a;color:#e5e7eb}.form-label{color:#dbe4ff;font-weight:900;font-size:13px}
.table-card{overflow:hidden}.table-responsive{border-radius:20px;background:rgba(10,15,30,.82);border:1px solid rgba(148,163,184,.14)}table.table{margin:0;color:#e5e7eb;--bs-table-bg:transparent;--bs-table-color:#e5e7eb;--bs-table-border-color:rgba(148,163,184,.1);--bs-table-hover-bg:rgba(124,58,237,.12)}table.table thead th{background:rgba(5,9,20,.92)!important;color:#cbd5e1!important;border-bottom:1px solid rgba(148,163,184,.14);font-size:12px;text-transform:uppercase;letter-spacing:.06em;padding:17px 18px;white-space:nowrap}table.table tbody td{padding:17px 18px;vertical-align:middle;border-color:rgba(148,163,184,.09);font-weight:800}table.table tbody tr:hover td{background:rgba(124,58,237,.09)!important;color:#fff}.badge-soft{border-radius:999px;padding:8px 12px;font-weight:900;font-size:12px}.badge-active{background:rgba(16,185,129,.16);color:#86efac;border:1px solid rgba(16,185,129,.25)}.badge-expired{background:rgba(239,68,68,.16);color:#fecaca;border:1px solid rgba(239,68,68,.25)}.badge-cancelled{background:rgba(148,163,184,.13);color:#cbd5e1;border:1px solid rgba(148,163,184,.2)}.badge-type{background:rgba(124,58,237,.16);color:#ddd6fe;border:1px solid rgba(124,58,237,.3)}.action-btn{width:42px;height:42px;border:0;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;color:#fff;margin-right:6px}.btn-view{background:rgba(6,182,212,.2);border:1px solid rgba(6,182,212,.35)}.btn-edit{background:rgba(245,158,11,.2);border:1px solid rgba(245,158,11,.35)}.btn-del{background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.35)}
.modal{z-index:5000!important}.modal-backdrop{z-index:4990!important}.modal-backdrop.show{opacity:.74;background:#000}.modal-content{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.28),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.97),rgba(17,24,39,.88))!important;color:#f8fafc;border:1px solid rgba(139,92,246,.35);border-radius:22px;box-shadow:0 28px 85px rgba(0,0,0,.62);backdrop-filter:blur(22px);overflow:hidden}.modal-header,.modal-footer{background:rgba(7,11,20,.45);border-color:rgba(148,163,184,.14)}.btn-close{filter:invert(1);opacity:1}.modal-title{font-weight:950}.warning-box{padding:13px 15px;border-radius:14px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.24);color:#fde68a;font-weight:800}
.output-option{padding:12px 14px;border-radius:14px;background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.14);margin-bottom:10px;cursor:pointer}.output-option:hover{border-color:rgba(124,58,237,.45);background:rgba(124,58,237,.12)}.output-option.active{border-color:#7c3aed;background:rgba(124,58,237,.18)}
@media(max-width:991px){.main-panel{margin-left:0!important;padding-top:70px}.content-wrapper{padding:16px}.page-title{font-size:26px}.stat-card{min-height:104px;padding:20px}.stat-value{font-size:28px;right:28px}.table{min-width:1100px}.d-flex.top-actions{align-items:stretch!important}.top-actions .btn-main,.top-actions .btn-purple,.top-actions .btn-glass{width:100%}}

/* =====================================================
   PRODUCTION_BATCHES.PHP - THEME + TOPBAR + POPUP FIX
   Paste at END before 
===================================================== */

html,
body{
    background:
        radial-gradient(circle at 10% 5%, rgba(124,58,237,.30), transparent 30%),
        radial-gradient(circle at 80% 8%, rgba(59,130,246,.12), transparent 28%),
        linear-gradient(135deg,#050914 0%,#090f1f 42%,#160f2d 76%,#070b14 100%) !important;
    color:#f8fafc !important;
    overflow-x:hidden !important;
}

/* main layout */
.container-scroll,
.main-panel,
.content-wrapper{
    background:
        radial-gradient(circle at 10% 5%, rgba(124,58,237,.30), transparent 30%),
        radial-gradient(circle at 80% 8%, rgba(59,130,246,.12), transparent 28%),
        linear-gradient(135deg,#050914 0%,#090f1f 42%,#160f2d 76%,#070b14 100%) !important;
    color:#f8fafc !important;
}

/* TOPBAR OVERLAY FIX */
.main-panel{
    position:relative !important;
    min-height:100vh !important;
    padding-top:70px !important;
}

.content-wrapper{
    padding-top:34px !important;
    padding-left:28px !important;
    padding-right:28px !important;
    padding-bottom:34px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
    overflow:visible !important;
}

/* topbar should stay above, but not cover content */
.navbar,
.navbar.fixed-top,
.my-navbar,
.topbar{
    z-index:1040 !important;
}

.navbar.my-navbar.fixed-top{
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* header title/buttons */
.top-actions{
    margin-top:0 !important;
    margin-bottom:26px !important;
    position:relative !important;
    z-index:3 !important;
}

.page-title{
    color:#ffffff !important;
    font-size:32px !important;
    font-weight:950 !important;
    line-height:1.15 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

.text-secondary,
.small.text-secondary{
    color:#aab4c8 !important;
    font-weight:800 !important;
    opacity:1 !important;
}

/* top buttons */
.btn-main,
.btn-purple,
.btn-primary,
button[type="submit"]{
    min-height:44px !important;
    border:0 !important;
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

.btn-main{
    background:linear-gradient(135deg,#059669,#16a34a) !important;
    box-shadow:0 14px 28px rgba(16,185,129,.24) !important;
}

.btn-main:hover,
.btn-purple:hover,
.btn-primary:hover,
button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* stats cards */
.stat-card{
    min-height:128px !important;
    border-radius:22px !important;
    padding:24px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:
        0 22px 50px rgba(0,0,0,.32),
        inset 0 1px 0 rgba(255,255,255,.08) !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.12), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:0;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:18px;
    top:18px;
    width:86px;
    height:86px;
    border-radius:22px;
    background:rgba(255,255,255,.13);
}

.stat-card > *{
    position:relative !important;
    z-index:1 !important;
}

.stat-label{
    font-size:16px !important;
    font-weight:900 !important;
    color:#fff !important;
}

.stat-value{
    font-size:42px !important;
    font-weight:950 !important;
    color:#fff !important;
}

.stat-blue{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
}

.stat-green{
    background:linear-gradient(135deg,#047857,#16a34a) !important;
}

.stat-orange{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
}

.stat-red{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
}

/* filter card */
.glass,
.filter-card,
.table-card{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.16), transparent 35%),
        linear-gradient(135deg, rgba(15,23,42,.78), rgba(24,15,45,.52)) !important;
    border:1px solid rgba(139,92,246,.22) !important;
    border-radius:18px !important;
    box-shadow:
        0 18px 45px rgba(0,0,0,.25),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(16px) saturate(140%) !important;
    -webkit-backdrop-filter:blur(16px) saturate(140%) !important;
}

.filter-card{
    padding:16px !important;
    margin-bottom:18px !important;
}

/* labels and fields */
.form-label{
    color:#dbeafe !important;
    font-weight:900 !important;
}

.form-control,
.form-select,
input,
select,
textarea{
    min-height:46px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.22) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    font-weight:800 !important;
    outline:none !important;
}

input::placeholder,
textarea::placeholder{
    color:#94a3b8 !important;
}

.form-control:focus,
.form-select:focus,
input:focus,
select:focus,
textarea:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* table */
.table-card{
    overflow:auto !important;
}

.table-responsive{
    border-radius:18px !important;
    overflow:auto !important;
}

table.table{
    width:100% !important;
    min-width:1100px !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-striped-bg:rgba(124,58,237,.06) !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

table.table thead th{
    background:rgba(6,10,20,.96) !important;
    color:#cbd5e1 !important;
    border:none !important;
    border-bottom:1px solid rgba(139,92,246,.24) !important;
    padding:15px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

table.table tbody td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.52), rgba(30,20,58,.30)) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:16px 18px !important;
    font-size:14px !important;
    font-weight:800 !important;
    vertical-align:middle !important;
    white-space:nowrap !important;
}

table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.34)) !important;
}

table.table tbody tr:hover td,
table.table-hover tbody tr:hover td,
table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.44), rgba(24,15,45,.28)) !important;
    padding:45px 18px !important;
}

/* badges */
.badge-soft{
    border-radius:999px !important;
    padding:7px 13px !important;
    font-size:12px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.badge-type{
    background:rgba(59,130,246,.16) !important;
    color:#93c5fd !important;
    border:1px solid rgba(59,130,246,.28) !important;
}

.st-active,
.badge-active{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

.st-expired,
.badge-expired{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.25) !important;
}

.st-cancelled,
.badge-cancelled{
    background:rgba(148,163,184,.16) !important;
    color:#cbd5e1 !important;
    border:1px solid rgba(148,163,184,.25) !important;
}

/* action buttons */
.action-btn{
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
    margin:0 3px !important;
}

.btn-view{
    background:rgba(6,182,212,.16) !important;
    color:#67e8f9 !important;
    border-color:rgba(6,182,212,.26) !important;
}

.btn-edit{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.26) !important;
}

.btn-del{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.26) !important;
}

.action-btn:hover{
    transform:translateY(-2px) scale(1.04) !important;
    color:#fff !important;
}

/* =====================================================
   POPUP / MODAL FIX
===================================================== */

.modal{
    z-index:5000 !important;
}

.modal-backdrop{
    z-index:4990 !important;
}

.modal-backdrop.show{
    opacity:.82 !important;
    background:#000 !important;
    backdrop-filter:blur(8px) !important;
    -webkit-backdrop-filter:blur(8px) !important;
}

/* Add/Edit Batch popup small */
#batchModal .modal-dialog{
    max-width:760px !important;
    width:calc(100% - 34px) !important;
    margin:18px auto !important;
}

#batchModal .modal-xl{
    max-width:760px !important;
}

#batchModal .modal-content{
    max-height:calc(100vh - 36px) !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
}

#batchModal .modal-content > form{
    display:flex !important;
    flex-direction:column !important;
    min-height:0 !important;
    max-height:calc(100vh - 36px) !important;
}

#batchModal .modal-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    max-height:calc(100vh - 160px) !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:16px 18px 12px !important;
}

#batchModal .modal-header{
    flex:0 0 auto !important;
    padding:14px 18px !important;
}

#batchModal .modal-footer{
    flex:0 0 auto !important;
    position:sticky !important;
    bottom:0 !important;
    z-index:30 !important;
    padding:12px 18px !important;
    background:
        linear-gradient(180deg, rgba(7,11,20,.88), rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    box-shadow:0 -18px 35px rgba(0,0,0,.35) !important;
}

.modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),
        radial-gradient(circle at 88% 0%, rgba(59,130,246,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.88)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:20px !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.68),
        0 0 55px rgba(124,58,237,.22),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
    overflow:hidden !important;
}

.modal-header,
.modal-footer{
    background:rgba(7,11,20,.58) !important;
    border-color:rgba(148,163,184,.16) !important;
}

.modal-title,
.modal h5,
.modal h6{
    color:#ffffff !important;
    font-weight:950 !important;
}

.btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

#batchModal textarea{
    min-height:74px !important;
}

/* View modal */
#viewModal .modal-dialog{
    max-width:720px !important;
    width:calc(100% - 34px) !important;
    margin:20px auto !important;
}

#viewModal .modal-content{
    max-height:calc(100vh - 40px) !important;
    overflow:hidden !important;
}

#viewModal .modal-body{
    max-height:calc(100vh - 140px) !important;
    overflow-y:auto !important;
}

/* scrollbars */
.table-responsive::-webkit-scrollbar,
.table-card::-webkit-scrollbar,
.modal-body::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

.table-responsive::-webkit-scrollbar-track,
.table-card::-webkit-scrollbar-track,
.modal-body::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

.table-responsive::-webkit-scrollbar-thumb,
.table-card::-webkit-scrollbar-thumb,
.modal-body::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* responsive */
@media(max-width:991px){
    .content-wrapper{
        padding-left:18px !important;
        padding-right:18px !important;
    }

    .top-actions{
        gap:12px !important;
    }

    .page-title{
        font-size:26px !important;
    }

    #batchModal .modal-dialog{
        max-width:680px !important;
        width:calc(100% - 22px) !important;
        margin:12px auto !important;
    }

    #batchModal .modal-body{
        max-height:calc(100vh - 155px) !important;
    }
}

@media(max-width:575px){
    .content-wrapper{
        padding-left:14px !important;
        padding-right:14px !important;
    }

    .top-actions .btn-main,
    .top-actions .btn-purple{
        width:100% !important;
    }

    table.table{
        min-width:1050px !important;
    }

    #batchModal .modal-dialog{
        width:calc(100% - 14px) !important;
        margin:7px auto !important;
    }

    #batchModal .modal-footer{
        flex-direction:column !important;
    }

    #batchModal .modal-footer .btn,
    #batchModal .modal-footer button{
        width:100% !important;
    }
}

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
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap top-actions mb-4">
                <div>
                    <h1 class="page-title mb-1">Production Batches</h1>
                    <div class="text-secondary fw-bold">Completed work orders ke output items ko batch / lot / job tracking me manage karo.</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn-main" onclick="openBatchModal()"><i class="bi bi-plus-lg me-2"></i>Add Batch</button>
                    <button class="btn-purple" onclick="markExpired()"><i class="bi bi-clock-history me-2"></i>Mark Expired</button>
                </div>
            </div>

            <?php if (!empty($schemaWarnings)): ?>
                <div class="warning-box mb-3">Schema warnings: <?= e(implode(' | ', $schemaWarnings)) ?></div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-lg-3 col-md-6"><div class="stat-card stat-blue"><div class="stat-label"><i class="bi bi-box-seam me-2"></i>Total Batches</div><div class="stat-value"><?= number_format($stats['total']) ?></div></div></div>
                <div class="col-lg-3 col-md-6"><div class="stat-card stat-green"><div class="stat-label"><i class="bi bi-check2-square me-2"></i>Active</div><div class="stat-value"><?= number_format($stats['active']) ?></div></div></div>
                <div class="col-lg-3 col-md-6"><div class="stat-card stat-orange"><div class="stat-label"><i class="bi bi-hourglass-split me-2"></i>Expiring 30 Days</div><div class="stat-value"><?= number_format($stats['next30']) ?></div></div></div>
                <div class="col-lg-3 col-md-6"><div class="stat-card stat-red"><div class="stat-label"><i class="bi bi-exclamation-octagon me-2"></i>Expired</div><div class="stat-value"><?= number_format($stats['expired']) ?></div></div></div>
            </div>

            <form class="glass filter-card" method="get">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-5"><label class="form-label">Search</label><input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="Batch no, item, work order, formula..."></div>
                    <div class="col-lg-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All</option><option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option><option value="expired" <?= $statusFilter==='expired'?'selected':'' ?>>Expired</option><option value="cancelled" <?= $statusFilter==='cancelled'?'selected':'' ?>>Cancelled</option></select></div>
                    <div class="col-lg-3"><label class="form-label">Expiry</label><select class="form-select" name="expiry"><option value="">All</option><option value="expired" <?= $expiryFilter==='expired'?'selected':'' ?>>Already Expired</option><option value="next30" <?= $expiryFilter==='next30'?'selected':'' ?>>Next 30 Days</option><option value="no_expiry" <?= $expiryFilter==='no_expiry'?'selected':'' ?>>No Expiry</option></select></div>
                    <div class="col-lg-2 d-grid"><button class="btn-purple" type="submit"><i class="bi bi-funnel me-2"></i>Filter</button></div>
                </div>
            </form>

            <div class="glass table-card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>ID</th><th>Batch No</th><th>Work Order</th><th>Formula</th><th>Item</th><th>Type</th><th>Qty</th><th>MFG</th><th>Expiry</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($batches)): ?>
                            <tr><td colspan="11" class="text-center text-secondary py-5">No batches found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($batches as $b):
                            $expiredByDate = !empty($b['expiry_date']) && $b['expiry_date'] < date('Y-m-d') && $b['status'] !== 'cancelled';
                            $statusClass = $b['status']==='cancelled' ? 'badge-cancelled' : ($expiredByDate || $b['status']==='expired' ? 'badge-expired' : 'badge-active');
                            $statusText = $b['status']==='cancelled' ? 'Cancelled' : ($expiredByDate || $b['status']==='expired' ? 'Expired' : 'Active');
                        ?>
                            <tr>
                                <td><?= (int)$b['id'] ?></td>
                                <td><strong><?= e($b['batch_no']) ?></strong></td>
                                <td><?= e($b['work_order_no'] ?? '-') ?><div class="small text-secondary"><?= e($b['production_date'] ?? '') ?></div></td>
                                <td><?= e($b['formula_name'] ?? '-') ?></td>
                                <td><strong><?= e($b['item_name'] ?? '-') ?></strong><div class="small text-secondary">SKU: <?= e($b['sku'] ?? '-') ?></div></td>
                                <td><span class="badge-soft badge-type"><?= e(str_replace('_',' ',ucwords($b['output_type'] ?? 'main'))) ?></span></td>
                                <td><?= number_format((float)$b['qty'], 4) ?> <?= e($b['unit'] ?? '') ?></td>
                                <td><?= e($b['mfg_date'] ?: '-') ?></td>
                                <td><?= e($b['expiry_date'] ?: '-') ?></td>
                                <td><span class="badge-soft <?= $statusClass ?>"><?= e($statusText) ?></span></td>
                                <td class="text-nowrap">
                                    <button class="action-btn btn-view" title="View" onclick="viewBatch(<?= (int)$b['id'] ?>)"><i class="bi bi-eye"></i></button>
                                    <button class="action-btn btn-edit" title="Edit" onclick="editBatch(<?= (int)$b['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                    <?php if ($b['status'] !== 'cancelled'): ?>
                                        <button class="action-btn btn-del" title="Cancel" onclick="deleteBatch(<?= (int)$b['id'] ?>)"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
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

<!-- Batch Modal -->
<div class="modal fade" id="batchModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form id="batchForm">
                <input type="hidden" name="ajax" value="save_batch">
                <input type="hidden" name="id" id="batch_id">
                <input type="hidden" name="work_order_output_id" id="work_order_output_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="batchModalTitle">Add Batch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="warning-box mb-3">Batch sirf completed work order ke output se banega. Total batch qty output actual qty se zyada nahi honi chahiye.</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Batch No</label>
                            <input type="text" class="form-control" name="batch_no" id="batch_no" value="<?= e($nextBatchNo) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Completed Work Order</label>
                            <select class="form-select" name="work_order_id" id="work_order_id" onchange="loadOrderOutputs()" required>
                                <option value="">Select Work Order</option>
                                <?php foreach ($completedOrders as $o): ?>
                                    <option value="<?= (int)$o['id'] ?>"><?= e($o['work_order_no']) ?> <?= $o['formula_name'] ? ' - '.e($o['formula_name']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="batch_status">
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Output Item</label>
                            <div id="outputsBox" class="glass p-3"><div class="text-secondary fw-bold">Work order select karo.</div></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" step="0.0001" min="0.0001" class="form-control" name="qty" id="batch_qty" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit</label>
                            <input type="text" class="form-control" name="unit" id="batch_unit" placeholder="pcs / kg / gram">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">MFG Date</label>
                            <input type="date" class="form-control" name="mfg_date" id="mfg_date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" name="expiry_date" id="expiry_date">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="batch_notes" rows="3" placeholder="Batch notes, quality notes, job remarks..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-main"><i class="bi bi-save me-2"></i>Save Batch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Batch Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="viewBody"></div>
            <div class="modal-footer"><button type="button" class="btn-glass" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const batchModal = new bootstrap.Modal(document.getElementById('batchModal'));
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
let currentOutputs = [];
let selectedOutputId = 0;

function esc(s){return String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));}
function money(n){return Number(n||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4});}
function pretty(s){return String(s||'').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());}

function resetBatchForm(){
    document.getElementById('batchForm').reset();
    document.getElementById('batch_id').value='';
    document.getElementById('work_order_output_id').value='';
    document.getElementById('batch_no').value='<?= e($nextBatchNo) ?>';
    document.getElementById('mfg_date').value='<?= date('Y-m-d') ?>';
    document.getElementById('batch_status').value='active';
    document.getElementById('outputsBox').innerHTML='<div class="text-secondary fw-bold">Work order select karo.</div>';
    currentOutputs=[]; selectedOutputId=0;
}
function openBatchModal(){
    resetBatchForm();
    document.getElementById('batchModalTitle').innerText='Add Batch';
    fetch('?ajax=next_batch_no').then(r=>r.json()).then(d=>{if(d.ok)document.getElementById('batch_no').value=d.batch_no;});
    batchModal.show();
}
function loadOrderOutputs(callback){
    const orderId=document.getElementById('work_order_id').value;
    const box=document.getElementById('outputsBox');
    selectedOutputId=0; document.getElementById('work_order_output_id').value='';
    if(!orderId){box.innerHTML='<div class="text-secondary fw-bold">Work order select karo.</div>';return;}
    box.innerHTML='<div class="text-secondary fw-bold">Loading outputs...</div>';
    fetch('?ajax=order_outputs&order_id='+encodeURIComponent(orderId)).then(r=>r.json()).then(d=>{
        if(!d.ok){box.innerHTML='<div class="text-danger fw-bold">'+esc(d.message)+'</div>';return;}
        currentOutputs=d.outputs||[];
        renderOutputs();
        if(typeof callback==='function') callback();
    });
}
function renderOutputs(){
    const box=document.getElementById('outputsBox');
    if(!currentOutputs.length){box.innerHTML='<div class="text-secondary fw-bold">Is work order me output item nahi mila.</div>';return;}
    box.innerHTML='';
    currentOutputs.forEach(o=>{
        const rem=Number(o.remaining_qty||0);
        const active=Number(o.id)===Number(selectedOutputId)?'active':'';
        box.insertAdjacentHTML('beforeend',`<div class="output-option ${active}" onclick="selectOutput(${Number(o.id)})">
            <div class="d-flex justify-content-between gap-2 flex-wrap">
                <div><strong>${esc(o.item_name)}</strong> <span class="badge-soft badge-type ms-2">${pretty(o.output_type)}</span><div class="small text-secondary">Actual: ${money(o.actual_qty)} ${esc(o.unit||'')} | Batched: ${money(o.batched_qty)} | Remaining: ${money(rem)}</div></div>
                <div class="fw-bold ${rem<=0?'text-danger':'text-success'}">${rem<=0?'No remaining':'Available'}</div>
            </div>
        </div>`);
    });
}
function selectOutput(id){
    selectedOutputId=id; document.getElementById('work_order_output_id').value=id;
    const o=currentOutputs.find(x=>Number(x.id)===Number(id));
    if(o){document.getElementById('batch_qty').value=Number(o.remaining_qty||o.actual_qty||0).toFixed(4);document.getElementById('batch_unit').value=o.unit||'';}
    renderOutputs();
}

document.getElementById('batchForm').addEventListener('submit',function(e){
    e.preventDefault();
    const fd=new FormData(this);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);if(d.ok)location.reload();}).catch(()=>alert('Save request failed.'));
});

function editBatch(id){
    fetch('?ajax=get_batch&id='+encodeURIComponent(id)).then(r=>r.json()).then(d=>{
        if(!d.ok){alert(d.message);return;}
        resetBatchForm();
        const b=d.batch;
        document.getElementById('batchModalTitle').innerText='Edit Batch';
        document.getElementById('batch_id').value=b.id;
        document.getElementById('batch_no').value=b.batch_no||'';
        document.getElementById('work_order_id').value=b.work_order_id||'';
        document.getElementById('batch_status').value=b.status||'active';
        document.getElementById('batch_qty').value=b.qty||'';
        document.getElementById('batch_unit').value=b.unit||'';
        document.getElementById('mfg_date').value=b.mfg_date||'';
        document.getElementById('expiry_date').value=b.expiry_date||'';
        document.getElementById('batch_notes').value=b.notes||'';
        selectedOutputId=Number(b.work_order_output_id||0);
        document.getElementById('work_order_output_id').value=selectedOutputId;
        loadOrderOutputs(()=>{selectedOutputId=Number(b.work_order_output_id||0);document.getElementById('work_order_output_id').value=selectedOutputId;renderOutputs();});
        batchModal.show();
    });
}
function viewBatch(id){
    fetch('?ajax=view_batch&id='+encodeURIComponent(id)).then(r=>r.json()).then(d=>{
        if(!d.ok){alert(d.message);return;}
        const b=d.batch;
        const exp=b.expiry_date||'-';
        const mfg=b.mfg_date||'-';
        document.getElementById('viewBody').innerHTML=`
            <div class="row g-3 mb-3">
                <div class="col-md-6"><div class="glass p-3"><div class="text-secondary fw-bold">Batch No</div><h4>${esc(b.batch_no)}</h4></div></div>
                <div class="col-md-6"><div class="glass p-3"><div class="text-secondary fw-bold">Status</div><h4>${pretty(b.status)}</h4></div></div>
                <div class="col-md-6"><div class="glass p-3"><div class="text-secondary fw-bold">Item</div><h5>${esc(b.item_name)}</h5><div>SKU: ${esc(b.sku||'-')}</div></div></div>
                <div class="col-md-6"><div class="glass p-3"><div class="text-secondary fw-bold">Quantity</div><h5>${money(b.qty)} ${esc(b.unit||'')}</h5><div>${pretty(b.output_type)}</div></div></div>
                <div class="col-md-6"><div class="glass p-3"><div class="text-secondary fw-bold">Work Order</div><h5>${esc(b.work_order_no||'-')}</h5><div>${esc(b.formula_name||'-')}</div></div></div>
                <div class="col-md-6"><div class="glass p-3"><div class="text-secondary fw-bold">Dates</div><h5>MFG: ${esc(mfg)}</h5><div>Expiry: ${esc(exp)}</div></div></div>
                <div class="col-12"><div class="glass p-3"><div class="text-secondary fw-bold">Notes</div><div>${esc(b.notes||'-')}</div></div></div>
            </div>`;
        viewModal.show();
    });
}
function deleteBatch(id){
    if(!confirm('Is batch ko cancel karna hai? Stock change nahi hoga, sirf batch tracking cancel hogi.')) return;
    const fd=new FormData(); fd.append('ajax','delete_batch'); fd.append('id',id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);if(d.ok)location.reload();});
}
function markExpired(){
    const fd=new FormData(); fd.append('ajax','mark_expired');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);if(d.ok)location.reload();});
}
</script>
</body>
</html>
