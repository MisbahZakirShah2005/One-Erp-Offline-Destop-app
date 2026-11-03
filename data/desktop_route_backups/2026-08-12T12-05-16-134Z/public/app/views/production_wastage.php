<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) $conn = $mysqli;
    elseif (isset($db) && $db instanceof mysqli) $conn = $db;
}
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');

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
if (!$is_superadmin && $client_id <= 0) die('Client not found in session.');

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function json_out(array $arr): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }
function qnum($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function money($v): string { return 'Rs ' . number_format((float)$v, 2); }
function qty_fmt($v): string { return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); }

function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function ensure_wastage_schema(mysqli $conn): array {
    $warnings = [];
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS production_wastage_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            branch_id INT NULL DEFAULT NULL,
            work_order_id INT NOT NULL,
            item_id INT NULL,
            qty DECIMAL(15,4) NOT NULL DEFAULT 0,
            unit VARCHAR(50) NULL,
            wastage_type ENUM('recoverable','non_recoverable') NOT NULL DEFAULT 'non_recoverable',
            reason VARCHAR(255) NULL,
            cost_impact DECIMAL(15,2) NOT NULL DEFAULT 0,
            source_type VARCHAR(50) NULL DEFAULT 'manual',
            notes TEXT NULL,
            is_stock_posted TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pwl_client (client_id),
            INDEX idx_pwl_branch (branch_id),
            INDEX idx_pwl_order (work_order_id),
            INDEX idx_pwl_item (item_id),
            INDEX idx_pwl_type (wastage_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $adds = [
            'branch_id' => "ALTER TABLE production_wastage_logs ADD COLUMN branch_id INT NULL DEFAULT NULL AFTER client_id",
            'source_type' => "ALTER TABLE production_wastage_logs ADD COLUMN source_type VARCHAR(50) NULL DEFAULT 'manual' AFTER cost_impact",
            'notes' => "ALTER TABLE production_wastage_logs ADD COLUMN notes TEXT NULL AFTER source_type",
            'is_stock_posted' => "ALTER TABLE production_wastage_logs ADD COLUMN is_stock_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER notes",
            'created_by' => "ALTER TABLE production_wastage_logs ADD COLUMN created_by INT NULL AFTER is_stock_posted",
            'updated_at' => "ALTER TABLE production_wastage_logs ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
        ];
        foreach ($adds as $col => $sql) {
            if (!column_exists($conn, 'production_wastage_logs', $col)) {
                try { $conn->query($sql); } catch (Throwable $e) { $warnings[] = $e->getMessage(); }
            }
        }
    } catch (Throwable $e) { $warnings[] = 'Wastage schema error: ' . $e->getMessage(); }
    return $warnings;
}
$schemaWarnings = ensure_wastage_schema($conn);

function scope_sql(string $alias = ''): string {
    global $is_superadmin, $client_id;
    $p = $alias ? $alias . '.' : '';
    return $is_superadmin ? '1=1' : $p . 'client_id=' . (int)$client_id;
}
function insert_stock_movement(mysqli $conn, int $client_id, int $branch_id, int $item_id, float $qty, string $type, string $reason, string $ref, string $notes): void {
    if (!table_exists($conn, 'stock_movements')) return;
    $q = (int)round($qty);
    $stmt = $conn->prepare("INSERT INTO stock_movements (item_id, client_id, branch_id, qty, type, reason, reference_no, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param('iiiissss', $item_id, $client_id, $branch_id, $q, $type, $reason, $ref, $notes);
    $stmt->execute(); $stmt->close();
}
function item_stock_change(mysqli $conn, int $item_id, float $qty_delta): void {
    $stmt = $conn->prepare("UPDATE items SET stock_qty = COALESCE(stock_qty,0) + ? WHERE id=?");
    $stmt->bind_param('di', $qty_delta, $item_id); $stmt->execute(); $stmt->close();
}
function load_wastage(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT wl.*, wo.work_order_no, wo.status order_status, i.name item_name, i.stock_qty, i.cost item_cost
                            FROM production_wastage_logs wl
                            LEFT JOIN production_work_orders wo ON wo.id = wl.work_order_id
                            LEFT JOIN items i ON i.id = wl.item_id
                            WHERE wl.id=? AND " . scope_sql('wl') . " LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

/* ================= AJAX API ================= */
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];

    if ($ajax === 'get_wastage') {
        $id = (int)($_GET['id'] ?? 0);
        $row = load_wastage($conn, $id);
        if (!$row) json_out(['ok'=>false, 'message'=>'Wastage record not found.']);
        json_out(['ok'=>true, 'row'=>$row]);
    }

    if ($ajax === 'view_wastage') {
        $id = (int)($_GET['id'] ?? 0);
        $row = load_wastage($conn, $id);
        if (!$row) json_out(['ok'=>false, 'message'=>'Wastage record not found.']);
        json_out(['ok'=>true, 'row'=>$row]);
    }

    if ($ajax === 'order_items') {
        $order_id = (int)($_GET['order_id'] ?? 0);
        if ($order_id <= 0) json_out(['ok'=>false, 'message'=>'Invalid work order.']);
        $stmt = $conn->prepare("SELECT id, work_order_no, status FROM production_work_orders WHERE id=? AND " . scope_sql() . " LIMIT 1");
        $stmt->bind_param('i', $order_id); $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$order) json_out(['ok'=>false, 'message'=>'Work order not found.']);

        $items = [];
        $stmt = $conn->prepare("SELECT woo.item_id, woo.actual_qty qty, woo.unit, woo.output_type source, i.name item_name, i.cost, i.stock_qty
                                FROM production_work_order_outputs woo
                                LEFT JOIN items i ON i.id=woo.item_id
                                WHERE woo.work_order_id=? AND woo.actual_qty > 0
                                UNION ALL
                                SELECT woi.item_id, woi.actual_qty qty, woi.unit, CONCAT('input_', woi.cost_type) source, i.name item_name, i.cost, i.stock_qty
                                FROM production_work_order_inputs woi
                                LEFT JOIN items i ON i.id=woi.item_id
                                WHERE woi.work_order_id=? AND woi.actual_qty > 0
                                ORDER BY item_name ASC");
        $stmt->bind_param('ii', $order_id, $order_id); $stmt->execute();
        $res = $stmt->get_result();
        $seen = [];
        while ($r = $res->fetch_assoc()) {
            $key = (int)$r['item_id'];
            if ($key <= 0 || isset($seen[$key])) continue;
            $seen[$key] = true;
            $items[] = $r;
        }
        $stmt->close();
        json_out(['ok'=>true, 'order'=>$order, 'items'=>$items]);
    }

    json_out(['ok'=>false, 'message'=>'Invalid ajax request.']);
}

/* ================= POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_wastage') {
            $id = (int)($_POST['id'] ?? 0);
            $work_order_id = (int)($_POST['work_order_id'] ?? 0);
            $item_id = (int)($_POST['item_id'] ?? 0);
            $qty = qnum($_POST['qty'] ?? 0);
            $unit = trim((string)($_POST['unit'] ?? ''));
            $wastage_type = ($_POST['wastage_type'] ?? 'non_recoverable') === 'recoverable' ? 'recoverable' : 'non_recoverable';
            $reason = trim((string)($_POST['reason'] ?? ''));
            $cost_impact = qnum($_POST['cost_impact'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $post_stock = isset($_POST['post_stock']) ? 1 : 0;
            if ($work_order_id <= 0) throw new Exception('Work order required.');
            if ($item_id <= 0) throw new Exception('Item required.');
            if ($qty <= 0) throw new Exception('Qty must be greater than zero.');
            if ($wastage_type === 'recoverable' && !$post_stock) $post_stock = 1;

            $stmt = $conn->prepare("SELECT id, client_id, branch_id, work_order_no FROM production_work_orders WHERE id=? AND " . scope_sql() . " LIMIT 1");
            $stmt->bind_param('i', $work_order_id); $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$order) throw new Exception('Work order not found.');

            $stmt = $conn->prepare("SELECT id, cost FROM items WHERE id=? AND " . ($is_superadmin ? '1=1' : 'client_id='.(int)$client_id) . " LIMIT 1");
            $stmt->bind_param('i', $item_id); $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$item) throw new Exception('Item not found.');
            if ($cost_impact <= 0) $cost_impact = $qty * (float)($item['cost'] ?? 0);

            $conn->begin_transaction();
            $old = null;
            if ($id > 0) {
                $old = load_wastage($conn, $id);
                if (!$old) throw new Exception('Record not found for update.');
                if (($old['wastage_type'] ?? '') === 'recoverable' && (int)($old['is_stock_posted'] ?? 0) === 1 && (int)$old['item_id'] > 0) {
                    item_stock_change($conn, (int)$old['item_id'], -1 * (float)$old['qty']);
                    insert_stock_movement($conn, (int)$old['client_id'], (int)($old['branch_id'] ?? 0), (int)$old['item_id'], (float)$old['qty'], 'out', 'production_wastage_reverse', 'WASTAGE-REV-'.$id, 'Recoverable wastage edit reverse');
                }
                $stmt = $conn->prepare("UPDATE production_wastage_logs SET work_order_id=?, item_id=?, qty=?, unit=?, wastage_type=?, reason=?, cost_impact=?, notes=?, is_stock_posted=?, updated_at=NOW() WHERE id=? AND " . scope_sql());
                $stmt->bind_param('iidsssdssi', $work_order_id, $item_id, $qty, $unit, $wastage_type, $reason, $cost_impact, $notes, $post_stock, $id);
                $stmt->execute(); $stmt->close();
            } else {
                $insert_client_id = $is_superadmin ? (int)$order['client_id'] : $client_id;
                $insert_branch_id = (int)($order['branch_id'] ?? $branch_id);
                $src = 'manual';
                $stmt = $conn->prepare("INSERT INTO production_wastage_logs (client_id, branch_id, work_order_id, item_id, qty, unit, wastage_type, reason, cost_impact, source_type, notes, is_stock_posted, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iiiidsssdssii', $insert_client_id, $insert_branch_id, $work_order_id, $item_id, $qty, $unit, $wastage_type, $reason, $cost_impact, $src, $notes, $post_stock, $user_id);
                $stmt->execute(); $id = $stmt->insert_id; $stmt->close();
            }
            if ($wastage_type === 'recoverable' && $post_stock === 1) {
                $stock_client = $is_superadmin ? (int)$order['client_id'] : $client_id;
                $stock_branch = (int)($order['branch_id'] ?? $branch_id);
                item_stock_change($conn, $item_id, $qty);
                insert_stock_movement($conn, $stock_client, $stock_branch, $item_id, $qty, 'in', 'production_wastage_recoverable', 'WASTAGE-'.$id, 'Recoverable wastage/scrap stock added');
            }
            $conn->commit();
            $_SESSION['flash_success'] = 'Wastage record saved successfully.';
        }

        if ($action === 'delete_wastage') {
            $id = (int)($_POST['id'] ?? 0);
            $row = load_wastage($conn, $id);
            if (!$row) throw new Exception('Wastage record not found.');
            $conn->begin_transaction();
            if (($row['wastage_type'] ?? '') === 'recoverable' && (int)($row['is_stock_posted'] ?? 0) === 1 && (int)$row['item_id'] > 0) {
                item_stock_change($conn, (int)$row['item_id'], -1 * (float)$row['qty']);
                insert_stock_movement($conn, (int)$row['client_id'], (int)($row['branch_id'] ?? 0), (int)$row['item_id'], (float)$row['qty'], 'out', 'production_wastage_delete_reverse', 'WASTAGE-DEL-'.$id, 'Recoverable wastage delete reverse');
            }
            $stmt = $conn->prepare("DELETE FROM production_wastage_logs WHERE id=? AND " . scope_sql());
            $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
            $conn->commit();
            $_SESSION['flash_success'] = 'Wastage record deleted successfully.';
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $x) {}
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: production_wastage.php'); exit;
}

/* ================= DATA ================= */
$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$orderFilter = (int)($_GET['order_id'] ?? 0);
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));

$where = [scope_sql('wl')];
if ($typeFilter !== '' && in_array($typeFilter, ['recoverable','non_recoverable'], true)) $where[] = "wl.wastage_type='".$conn->real_escape_string($typeFilter)."'";
if ($orderFilter > 0) $where[] = "wl.work_order_id=".(int)$orderFilter;
if ($dateFrom !== '') $where[] = "DATE(wl.created_at) >= '".$conn->real_escape_string($dateFrom)."'";
if ($dateTo !== '') $where[] = "DATE(wl.created_at) <= '".$conn->real_escape_string($dateTo)."'";
if ($search !== '') {
    $s = '%' . $search . '%';
    $safe = $conn->real_escape_string($search);
    $where[] = "(wo.work_order_no LIKE '%$safe%' OR i.name LIKE '%$safe%' OR wl.reason LIKE '%$safe%' OR wl.notes LIKE '%$safe%')";
}
$whereSql = implode(' AND ', $where);

$rows = [];
$res = $conn->query("SELECT wl.*, wo.work_order_no, wo.status order_status, pf.formula_name, i.name item_name, i.sku, i.stock_qty
                    FROM production_wastage_logs wl
                    LEFT JOIN production_work_orders wo ON wo.id = wl.work_order_id
                    LEFT JOIN production_formulas pf ON pf.id = wo.formula_id
                    LEFT JOIN items i ON i.id = wl.item_id
                    WHERE $whereSql
                    ORDER BY wl.id DESC LIMIT 300");
while ($r = $res->fetch_assoc()) $rows[] = $r;

$stats = ['total'=>0,'recoverable_qty'=>0,'non_recoverable_qty'=>0,'cost'=>0];
$res = $conn->query("SELECT COUNT(*) total,
       COALESCE(SUM(CASE WHEN wastage_type='recoverable' THEN qty ELSE 0 END),0) recoverable_qty,
       COALESCE(SUM(CASE WHEN wastage_type='non_recoverable' THEN qty ELSE 0 END),0) non_recoverable_qty,
       COALESCE(SUM(cost_impact),0) cost
       FROM production_wastage_logs wl WHERE " . scope_sql('wl'));
if ($r = $res->fetch_assoc()) $stats = $r;

$workOrders = [];
$res = $conn->query("SELECT id, work_order_no, status FROM production_work_orders WHERE " . scope_sql() . " ORDER BY id DESC LIMIT 500");
while ($r = $res->fetch_assoc()) $workOrders[] = $r;

$items = [];
$res = $conn->query("SELECT id, name, sku, cost, stock_qty, COALESCE(unit_name,'') unit_name FROM items WHERE " . ($is_superadmin ? '1=1' : 'client_id='.(int)$client_id) . " ORDER BY name ASC LIMIT 2000");
while ($r = $res->fetch_assoc()) $items[] = $r;

$flash_success = $_SESSION['flash_success'] ?? ''; unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? ''; unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Production Wastage / Scrap</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{--bg:#050914;--panel:rgba(15,23,42,.70);--line:rgba(148,163,184,.15);--text:#f8fafc;--muted:#94a3b8;--purple:#7c3aed;--blue:#4361ee;--green:#16a34a;--red:#dc2626;--orange:#f59e0b;}
html,body{margin:0;background:radial-gradient(circle at 10% 0%,rgba(124,58,237,.32),transparent 30%),radial-gradient(circle at 85% 8%,rgba(59,130,246,.15),transparent 28%),linear-gradient(135deg,#050914,#0b1024 48%,#160f2d);color:var(--text);font-family:Inter,"Segoe UI",Arial,sans-serif;min-height:100%;}
.content-wrapper,.main-content,.page-body{background:transparent!important;}
.production-page{padding:26px;min-height:100vh;position:relative;overflow:hidden;}
.production-page:before{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:44px 44px;opacity:.22;pointer-events:none;}
.production-page>*{position:relative;z-index:1}.page-title{font-size:30px;font-weight:950;letter-spacing:-.04em;text-shadow:0 0 28px rgba(124,58,237,.38)}.page-sub{color:#b6c0d4;font-weight:700}.btn-glow{border:0;border-radius:14px;min-height:48px;padding:0 20px;font-weight:900;color:#fff;background:linear-gradient(135deg,#16a34a,#059669);box-shadow:0 16px 34px rgba(22,163,74,.25)}.btn-purple{border:0;border-radius:14px;min-height:48px;padding:0 20px;font-weight:900;color:#fff;background:linear-gradient(135deg,#6d4cff,#7c3aed);box-shadow:0 16px 34px rgba(124,58,237,.28)}.btn-darkx{background:rgba(15,23,42,.75);border:1px solid var(--line);color:#e5e7eb;border-radius:14px;font-weight:850;min-height:44px}.stat-card{border-radius:22px;padding:24px;background:linear-gradient(135deg,rgba(67,97,238,.92),rgba(124,58,237,.92));box-shadow:0 18px 48px rgba(0,0,0,.32);overflow:hidden;position:relative;min-height:122px}.stat-card.orange{background:linear-gradient(135deg,#f59e0b,#fb923c)}.stat-card.red{background:linear-gradient(135deg,#be123c,#ef4444)}.stat-card.green{background:linear-gradient(135deg,#087f5b,#16a34a)}.stat-card .num{font-size:38px;font-weight:950}.stat-card .label{font-weight:900}.stat-card .iconbox{position:absolute;right:18px;top:18px;width:74px;height:74px;border-radius:20px;background:rgba(255,255,255,.14);display:grid;place-items:center;font-size:30px}.glass{background:radial-gradient(circle at 15% 0%,rgba(124,58,237,.14),transparent 32%),linear-gradient(135deg,rgba(15,23,42,.78),rgba(17,24,39,.55));border:1px solid rgba(148,163,184,.18);border-radius:22px;box-shadow:0 18px 50px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05);backdrop-filter:blur(18px);}.filter-bar{padding:16px}.form-control,.form-select,textarea{background:rgba(15,23,42,.78)!important;color:#f8fafc!important;border:1px solid rgba(148,163,184,.20)!important;border-radius:14px!important;min-height:46px!important;font-weight:800}.form-control:focus,.form-select:focus{box-shadow:0 0 0 3px rgba(124,58,237,.18)!important;border-color:rgba(124,58,237,.65)!important}.form-select option{background:#0f172a;color:#fff}.table-wrap{border-radius:22px;overflow:auto;border:1px solid rgba(148,163,184,.16);background:rgba(7,11,20,.55)}table{margin:0!important;color:#e5e7eb!important;--bs-table-bg:transparent!important;--bs-table-color:#e5e7eb!important;--bs-table-border-color:rgba(148,163,184,.10)!important}thead th{background:rgba(5,9,20,.95)!important;color:#cbd5e1!important;text-transform:uppercase;letter-spacing:.05em;font-size:12px;font-weight:950;border-bottom:1px solid rgba(124,58,237,.28)!important;padding:16px!important;white-space:nowrap}tbody td{background:linear-gradient(135deg,rgba(15,23,42,.48),rgba(20,12,40,.26))!important;border-bottom:1px solid rgba(148,163,184,.08)!important;color:#e5e7eb!important;padding:15px 16px!important;font-weight:750;vertical-align:middle;white-space:nowrap}tbody tr:hover td{background:rgba(124,58,237,.15)!important;color:#fff!important}.badge-soft{border-radius:999px;padding:8px 12px;font-weight:900}.badge-rec{background:rgba(22,163,74,.16);color:#86efac;border:1px solid rgba(22,163,74,.28)}.badge-non{background:rgba(239,68,68,.16);color:#fecaca;border:1px solid rgba(239,68,68,.30)}.badge-status{background:rgba(124,58,237,.16);color:#ddd6fe;border:1px solid rgba(124,58,237,.30)}.action-btn{width:42px;height:42px;border-radius:14px;border:1px solid rgba(148,163,184,.18);display:inline-grid;place-items:center;color:#fff;text-decoration:none;background:rgba(15,23,42,.65)}.action-btn.edit{background:rgba(245,158,11,.18);color:#fbbf24}.action-btn.del{background:rgba(239,68,68,.16);color:#f87171}.action-btn.view{background:rgba(59,130,246,.16);color:#93c5fd}.modal-content{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.26),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.97),rgba(17,24,39,.88))!important;border:1px solid rgba(139,92,246,.38)!important;border-radius:22px!important;color:#f8fafc!important;box-shadow:0 28px 80px rgba(0,0,0,.62)}.modal-header,.modal-footer{border-color:rgba(148,163,184,.16)!important}.btn-close{filter:invert(1);opacity:1}.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.detail-box{padding:14px;border-radius:16px;background:rgba(15,23,42,.58);border:1px solid rgba(148,163,184,.14)}.detail-box small{display:block;color:#94a3b8;font-weight:800}.detail-box b{font-size:16px}.alert{border-radius:16px;border:1px solid rgba(255,255,255,.16)}@media(max-width:991px){.production-page{padding:16px}.stat-card{min-height:100px}.detail-grid{grid-template-columns:1fr}table{min-width:1080px}.page-title{font-size:24px}}
/* =====================================================
   PRODUCTION_WASTAGE.PHP - DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html,
body{
    margin:0 !important;
    padding:0 !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 10% 5%, rgba(124,58,237,.30), transparent 30%),
        radial-gradient(circle at 80% 8%, rgba(59,130,246,.12), transparent 28%),
        linear-gradient(135deg,#050914 0%,#090f1f 42%,#160f2d 76%,#070b14 100%) !important;
    color:#f8fafc !important;
}

/* main page background */
.container-scroll,
.main-panel,
.content-wrapper{
    background:
        radial-gradient(circle at 10% 5%, rgba(124,58,237,.30), transparent 30%),
        radial-gradient(circle at 80% 8%, rgba(59,130,246,.12), transparent 28%),
        linear-gradient(135deg,#050914 0%,#090f1f 42%,#160f2d 76%,#070b14 100%) !important;
    color:#f8fafc !important;
}

/* topbar overlap fix */
.main-panel{
    position:relative !important;
    min-height:100vh !important;
    padding-top:70px !important;
}

.content-wrapper{
    padding:34px 28px 34px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
    overflow:visible !important;
}

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

/* title */
.page-title,
h1,
h2{
    color:#ffffff !important;
    font-size:32px !important;
    font-weight:950 !important;
    line-height:1.15 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

.text-secondary,
.text-muted,
.small.text-secondary{
    color:#aab4c8 !important;
    font-weight:800 !important;
    opacity:1 !important;
}

/* Add Wastage button */
.btn-main,
.btn-success,
.btn-primary,
button[type="submit"]{
    min-height:44px !important;
    border:0 !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#059669,#16a34a) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(16,185,129,.24) !important;
    transition:.22s ease !important;
}

.btn-purple,
.filter-card button[type="submit"],
.btn-filter{
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
}

.btn-main:hover,
.btn-purple:hover,
.btn-primary:hover,
.btn-success:hover,
button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* stats cards */
.stat-card{
    min-height:122px !important;
    border-radius:22px !important;
    padding:24px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:
        0 22px 50px rgba(0,0,0,.32),
        inset 0 1px 0 rgba(255,255,255,.08) !important;
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
    width:82px;
    height:82px;
    border-radius:22px;
    background:rgba(255,255,255,.13);
}

.stat-card > *{
    position:relative !important;
    z-index:1 !important;
}

.stat-label{
    color:#fff !important;
    font-size:15px !important;
    font-weight:900 !important;
}

.stat-value{
    color:#fff !important;
    font-size:38px !important;
    font-weight:950 !important;
}

.stat-blue,
.stat-purple{
    background:linear-gradient(135deg,#2563eb,#7c3aed) !important;
}

.stat-green{
    background:linear-gradient(135deg,#047857,#16a34a) !important;
}

.stat-red{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
}

.stat-orange{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
}

/* glass cards */
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
    margin:18px 0 !important;
}

/* form fields */
.form-label,
label{
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

/* date input icon readable */
input[type="date"]::-webkit-calendar-picker-indicator{
    filter:invert(1) brightness(1.4) !important;
    cursor:pointer !important;
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
    min-width:1050px !important;
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
.badge-soft,
.badge{
    border-radius:999px !important;
    padding:7px 13px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.badge-recoverable,
.st-recoverable,
.bg-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

.badge-non-recoverable,
.st-non-recoverable,
.bg-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.25) !important;
}

/* action buttons */
.action-btn,
.icon-btn{
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

.btn-del,
.btn-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.26) !important;
}

.action-btn:hover,
.icon-btn:hover{
    transform:translateY(-2px) scale(1.04) !important;
    color:#fff !important;
}

/* =====================================================
   POPUP FORM FIX
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

/* wastage popup small */
#wastageModal .modal-dialog,
#wastageFormModal .modal-dialog,
#addWastageModal .modal-dialog{
    max-width:760px !important;
    width:calc(100% - 34px) !important;
    margin:18px auto !important;
}

#wastageModal .modal-xl,
#wastageFormModal .modal-xl,
#addWastageModal .modal-xl{
    max-width:760px !important;
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

/* modal height manage */
#wastageModal .modal-content,
#wastageFormModal .modal-content,
#addWastageModal .modal-content{
    max-height:calc(100vh - 36px) !important;
    display:flex !important;
    flex-direction:column !important;
}

#wastageModal .modal-content > form,
#wastageFormModal .modal-content > form,
#addWastageModal .modal-content > form{
    display:flex !important;
    flex-direction:column !important;
    min-height:0 !important;
    max-height:calc(100vh - 36px) !important;
}

.modal-header{
    flex:0 0 auto !important;
    padding:14px 18px !important;
    background:rgba(7,11,20,.58) !important;
    border-color:rgba(148,163,184,.16) !important;
}

.modal-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    max-height:calc(100vh - 165px) !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:16px 18px 12px !important;
}

.modal-footer{
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

/* modal fields compact */
.modal .form-control,
.modal .form-select,
.modal input,
.modal select,
.modal textarea{
    min-height:42px !important;
    border-radius:12px !important;
    font-size:14px !important;
}

.modal textarea{
    min-height:78px !important;
}

/* view modal */
#viewModal .modal-dialog{
    max-width:720px !important;
    width:calc(100% - 34px) !important;
    margin:20px auto !important;
}

#viewModal .modal-content{
    max-height:calc(100vh - 40px) !important;
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

    .page-title{
        font-size:26px !important;
    }

    #wastageModal .modal-dialog,
    #wastageFormModal .modal-dialog,
    #addWastageModal .modal-dialog{
        max-width:680px !important;
        width:calc(100% - 22px) !important;
        margin:12px auto !important;
    }

    table.table{
        min-width:1050px !important;
    }
}

@media(max-width:575px){
    .content-wrapper{
        padding-left:14px !important;
        padding-right:14px !important;
    }

    .btn-main,
    .btn-purple{
        width:100% !important;
    }

    #wastageModal .modal-dialog,
    #wastageFormModal .modal-dialog,
    #addWastageModal .modal-dialog{
        width:calc(100% - 14px) !important;
        margin:7px auto !important;
    }

    .modal-footer{
        flex-direction:column !important;
    }

    .modal-footer .btn,
    .modal-footer button{
        width:100% !important;
    }
}

/* =====================================================
   PRODUCTION_WASTAGE.PHP - TABLE ALIGNMENT FIX
   Paste at END before 
  
===================================================== */

/* table wrapper ko clean horizontal scroll do */
.production-page .table-wrap{
    width:100% !important;
    max-width:100% !important;
    overflow-x:auto !important;
    overflow-y:hidden !important;
    border-radius:18px !important;
}

/* table ko proper width do taki columns overlap na hon */
.production-page .table-wrap table.table{
    width:100% !important;
    min-width:1250px !important;
    table-layout:fixed !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
}

/* cells ka text controlled rakho */
.production-page .table-wrap table.table th,
.production-page .table-wrap table.table td{
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    vertical-align:middle !important;
}

/* column widths */
.production-page .table-wrap table.table th:nth-child(1),
.production-page .table-wrap table.table td:nth-child(1){
    width:70px !important;
    min-width:70px !important;
    max-width:70px !important;
}

.production-page .table-wrap table.table th:nth-child(2),
.production-page .table-wrap table.table td:nth-child(2){
    width:170px !important;
    min-width:170px !important;
    max-width:170px !important;
}

.production-page .table-wrap table.table th:nth-child(3),
.production-page .table-wrap table.table td:nth-child(3){
    width:150px !important;
    min-width:150px !important;
    max-width:150px !important;
}

.production-page .table-wrap table.table th:nth-child(4),
.production-page .table-wrap table.table td:nth-child(4){
    width:90px !important;
    min-width:90px !important;
    max-width:90px !important;
}

.production-page .table-wrap table.table th:nth-child(5),
.production-page .table-wrap table.table td:nth-child(5){
    width:180px !important;
    min-width:180px !important;
    max-width:180px !important;
}

.production-page .table-wrap table.table th:nth-child(6),
.production-page .table-wrap table.table td:nth-child(6){
    width:140px !important;
    min-width:140px !important;
    max-width:140px !important;
}

.production-page .table-wrap table.table th:nth-child(7),
.production-page .table-wrap table.table td:nth-child(7){
    width:140px !important;
    min-width:140px !important;
    max-width:140px !important;
}

.production-page .table-wrap table.table th:nth-child(8),
.production-page .table-wrap table.table td:nth-child(8){
    width:190px !important;
    min-width:190px !important;
    max-width:190px !important;
}

.production-page .table-wrap table.table th:nth-child(9),
.production-page .table-wrap table.table td:nth-child(9){
    width:130px !important;
    min-width:130px !important;
    max-width:130px !important;
}

.production-page .table-wrap table.table th:nth-child(10),
.production-page .table-wrap table.table td:nth-child(10){
    width:155px !important;
    min-width:155px !important;
    max-width:155px !important;
    text-align:right !important;
}

/* Work order ke andar badge next line me readable rahe */
.production-page .table-wrap table.table td:nth-child(2) b,
.production-page .table-wrap table.table td:nth-child(3) b{
    display:block !important;
    max-width:100% !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
}

.production-page .table-wrap table.table td:nth-child(2) .badge-soft,
.production-page .table-wrap table.table td:nth-child(3) small{
    display:inline-flex !important;
    max-width:100% !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
    margin-top:5px !important;
}

/* row height stable */
.production-page .table-wrap table.table tbody td{
    height:74px !important;
    padding:14px 16px !important;
}

/* action buttons ek line me rahen */
.production-page .table-wrap table.table td.text-end{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:8px !important;
}

.production-page .table-wrap table.table td.text-end form{
    display:inline-flex !important;
    margin:0 !important;
}

.production-page .table-wrap table.table .action-btn{
    flex:0 0 38px !important;
    width:38px !important;
    height:38px !important;
    margin:0 !important;
}

/* horizontal scrollbar ko table ke andar clean rakho */
.production-page .table-wrap::-webkit-scrollbar{
    height:10px !important;
}

.production-page .table-wrap::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

.production-page .table-wrap::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* mobile pe aur wide table */
@media(max-width:768px){
    .production-page .table-wrap table.table{
        min-width:1280px !important;
    }
}

</style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/partials/sidebar.php')): ?>
<div class="container-scroll"><div class="sidebar"><?php include(__DIR__ . '/partials/sidebar.php'); ?></div><div class="main-panel"><?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?><div class="content-wrapper">
<?php endif; ?>

<div class="production-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div><h1 class="page-title mb-1">Wastage / Scrap</h1><div class="page-sub">Production losses, recoverable scrap aur non-recoverable wastage tracking</div></div>
        <button class="btn btn-glow" data-bs-toggle="modal" data-bs-target="#wastageModal" onclick="openAdd()"><i class="bi bi-plus-circle me-2"></i>Add Wastage</button>
    </div>

    <?php if ($flash_success): ?><div class="alert alert-success"><?= e($flash_success) ?></div><?php endif; ?>
    <?php if ($flash_error): ?><div class="alert alert-danger"><?= e($flash_error) ?></div><?php endif; ?>
    <?php if (!empty($schemaWarnings)): ?><div class="alert alert-warning">Schema warnings: <?= e(implode(' | ', $schemaWarnings)) ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6"><div class="stat-card"><div class="iconbox"><i class="bi bi-recycle"></i></div><div class="label">Total Records</div><div class="num"><?= (int)$stats['total'] ?></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="stat-card green"><div class="iconbox"><i class="bi bi-arrow-return-left"></i></div><div class="label">Recoverable Qty</div><div class="num"><?= qty_fmt($stats['recoverable_qty']) ?></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="stat-card red"><div class="iconbox"><i class="bi bi-trash3"></i></div><div class="label">Non Recoverable</div><div class="num"><?= qty_fmt($stats['non_recoverable_qty']) ?></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="stat-card orange"><div class="iconbox"><i class="bi bi-cash-stack"></i></div><div class="label">Cost Impact</div><div class="num" style="font-size:28px"><?= money($stats['cost']) ?></div></div></div>
    </div>

    <div class="glass filter-bar mb-4">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-lg-3"><label class="form-label fw-bold">Search</label><input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Order, item, reason..."></div>
            <div class="col-lg-2"><label class="form-label fw-bold">Type</label><select class="form-select" name="type"><option value="">All Types</option><option value="recoverable" <?= $typeFilter==='recoverable'?'selected':'' ?>>Recoverable</option><option value="non_recoverable" <?= $typeFilter==='non_recoverable'?'selected':'' ?>>Non Recoverable</option></select></div>
            <div class="col-lg-3"><label class="form-label fw-bold">Work Order</label><select class="form-select" name="order_id"><option value="0">All Orders</option><?php foreach($workOrders as $wo): ?><option value="<?= (int)$wo['id'] ?>" <?= $orderFilter===(int)$wo['id']?'selected':'' ?>><?= e($wo['work_order_no']) ?> (<?= e($wo['status']) ?>)</option><?php endforeach; ?></select></div>
            <div class="col-lg-1"><label class="form-label fw-bold">From</label><input type="date" class="form-control" name="from" value="<?= e($dateFrom) ?>"></div>
            <div class="col-lg-1"><label class="form-label fw-bold">To</label><input type="date" class="form-control" name="to" value="<?= e($dateTo) ?>"></div>
            <div class="col-lg-2 d-flex gap-2"><button class="btn btn-purple flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button><a href="production_wastage.php" class="btn btn-darkx"><i class="bi bi-x-lg"></i></a></div>
        </form>
    </div>

    <div class="glass p-0">
        <div class="table-wrap">
            <table class="table table-hover align-middle">
                <thead><tr><th>ID</th><th>Work Order</th><th>Item</th><th>Qty</th><th>Type</th><th>Cost Impact</th><th>Stock Posted</th><th>Reason</th><th>Date</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php if(empty($rows)): ?><tr><td colspan="10" class="text-center py-5 text-muted">No wastage records found.</td></tr><?php endif; ?>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td>#<?= (int)$r['id'] ?></td>
                        <td><b><?= e($r['work_order_no'] ?: 'N/A') ?></b><br><span class="badge-soft badge-status"><?= e($r['order_status'] ?: '-') ?></span></td>
                        <td><b><?= e($r['item_name'] ?: 'N/A') ?></b><br><small class="text-muted"><?= e($r['sku'] ?? '') ?></small></td>
                        <td><b><?= qty_fmt($r['qty']) ?></b> <?= e($r['unit']) ?></td>
                        <td><span class="badge-soft <?= $r['wastage_type']==='recoverable'?'badge-rec':'badge-non' ?>"><?= e(str_replace('_',' ',ucwords($r['wastage_type'],'_'))) ?></span></td>
                        <td><?= money($r['cost_impact']) ?></td>
                        <td><?= (int)($r['is_stock_posted'] ?? 0) ? '<span class="badge-soft badge-rec">Yes</span>' : '<span class="badge-soft badge-non">No</span>' ?></td>
                        <td><?= e($r['reason']) ?></td>
                        <td><?= e(date('d M Y', strtotime($r['created_at'] ?? 'now'))) ?></td>
                        <td class="text-end">
                            <button class="action-btn view" onclick="viewWastage(<?= (int)$r['id'] ?>)"><i class="bi bi-eye"></i></button>
                            <button class="action-btn edit" onclick="editWastage(<?= (int)$r['id'] ?>)"><i class="bi bi-pencil"></i></button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this wastage record? Recoverable stock will be reversed if posted.')"><input type="hidden" name="action" value="delete_wastage"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="action-btn del" type="submit"><i class="bi bi-trash"></i></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Save Modal -->
<div class="modal fade" id="wastageModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
<form method="post" id="wastageForm">
<input type="hidden" name="action" value="save_wastage"><input type="hidden" name="id" id="wastage_id">
<div class="modal-header"><h5 class="modal-title fw-black" id="modalTitle">Add Wastage / Scrap</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-5"><label class="form-label fw-bold">Work Order</label><select class="form-select" name="work_order_id" id="work_order_id" required onchange="loadOrderItems(this.value)"><option value="">Select Work Order</option><?php foreach($workOrders as $wo): ?><option value="<?= (int)$wo['id'] ?>"><?= e($wo['work_order_no']) ?> - <?= e($wo['status']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label fw-bold">Item</label><select class="form-select" name="item_id" id="item_id" required onchange="fillItemInfo()"><option value="">Select Item</option><?php foreach($items as $it): ?><option value="<?= (int)$it['id'] ?>" data-cost="<?= e($it['cost']) ?>" data-unit="<?= e($it['unit_name']) ?>" data-stock="<?= e($it['stock_qty']) ?>"><?= e($it['name']) ?><?= $it['sku']?' - '.e($it['sku']):'' ?></option><?php endforeach; ?></select><small class="text-muted" id="itemHint"></small></div>
        <div class="col-md-2"><label class="form-label fw-bold">Qty</label><input type="number" step="0.0001" min="0" class="form-control" name="qty" id="qty" required oninput="autoCost()"></div>
        <div class="col-md-2"><label class="form-label fw-bold">Unit</label><input class="form-control" name="unit" id="unit" placeholder="pcs/kg/g"></div>
        <div class="col-md-3"><label class="form-label fw-bold">Wastage Type</label><select class="form-select" name="wastage_type" id="wastage_type" onchange="toggleStockPost()"><option value="non_recoverable">Non Recoverable / Loss</option><option value="recoverable">Recoverable Scrap</option></select></div>
        <div class="col-md-3"><label class="form-label fw-bold">Cost Impact</label><input type="number" step="0.01" min="0" class="form-control" name="cost_impact" id="cost_impact"></div>
        <div class="col-md-4 d-flex align-items-end"><label class="d-flex align-items-center gap-2 fw-bold mb-2"><input type="checkbox" name="post_stock" id="post_stock" class="form-check-input"> Add recoverable qty to stock</label></div>
        <div class="col-md-6"><label class="form-label fw-bold">Reason</label><input class="form-control" name="reason" id="reason" placeholder="Damage, cutting loss, melting loss, expired, etc."></div>
        <div class="col-md-6"><label class="form-label fw-bold">Notes</label><textarea class="form-control" name="notes" id="notes" rows="2"></textarea></div>
    </div>
    <div class="alert alert-info mt-3 mb-0">Recoverable scrap select karne par stock automatically add ho jayega. Edit/Delete par stock reverse bhi hoga.</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-darkx" data-bs-dismiss="modal">Cancel</button><button class="btn btn-purple" type="submit"><i class="bi bi-check2-circle me-1"></i>Save Wastage</button></div>
</form>
</div></div></div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Wastage Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="viewBody"></div><div class="modal-footer"><button class="btn btn-darkx" data-bs-dismiss="modal">Close</button></div></div></div></div>

<?php if (file_exists(__DIR__ . '/partials/sidebar.php')): ?></div></div></div><?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const wastageModal = new bootstrap.Modal(document.getElementById('wastageModal'));
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
const originalItemOptions = document.getElementById('item_id').innerHTML;
function esc(s){return String(s??'').replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));}
function openAdd(){document.getElementById('wastageForm').reset();document.getElementById('wastage_id').value='';document.getElementById('modalTitle').innerText='Add Wastage / Scrap';document.getElementById('item_id').innerHTML=originalItemOptions;document.getElementById('itemHint').innerText='';toggleStockPost();}
function toggleStockPost(){const t=document.getElementById('wastage_type').value;const cb=document.getElementById('post_stock');if(t==='recoverable'){cb.checked=true;cb.disabled=false}else{cb.checked=false;cb.disabled=true}}
function fillItemInfo(){const opt=document.getElementById('item_id').selectedOptions[0];if(!opt){return}document.getElementById('itemHint').innerText='Stock: '+(opt.dataset.stock||'0')+' | Cost: Rs '+(opt.dataset.cost||'0');if(!document.getElementById('unit').value && opt.dataset.unit) document.getElementById('unit').value=opt.dataset.unit;autoCost();}
function autoCost(){const opt=document.getElementById('item_id').selectedOptions[0];const q=parseFloat(document.getElementById('qty').value||0);const cost=parseFloat(opt?.dataset?.cost||0);if(q>0 && cost>0 && !document.getElementById('wastage_id').value){document.getElementById('cost_impact').value=(q*cost).toFixed(2);}}
function loadOrderItems(orderId){if(!orderId){document.getElementById('item_id').innerHTML=originalItemOptions;return}fetch('production_wastage.php?ajax=order_items&order_id='+encodeURIComponent(orderId)).then(r=>r.json()).then(d=>{if(!d.ok){alert(d.message||'Items load failed');return}let html='<option value="">Select Item</option>';d.items.forEach(it=>{html+=`<option value="${it.item_id}" data-cost="${esc(it.cost||0)}" data-unit="${esc(it.unit||'')}" data-stock="${esc(it.stock_qty||0)}">${esc(it.item_name)} (${esc(it.source)})</option>`});if(d.items.length===0) html=originalItemOptions;document.getElementById('item_id').innerHTML=html;});}
function editWastage(id){fetch('production_wastage.php?ajax=get_wastage&id='+id).then(r=>r.json()).then(d=>{if(!d.ok){alert(d.message||'Record not found');return}const x=d.row;document.getElementById('wastageForm').reset();document.getElementById('modalTitle').innerText='Edit Wastage / Scrap';document.getElementById('wastage_id').value=x.id;document.getElementById('work_order_id').value=x.work_order_id;document.getElementById('item_id').innerHTML=originalItemOptions;document.getElementById('item_id').value=x.item_id;document.getElementById('qty').value=x.qty;document.getElementById('unit').value=x.unit||'';document.getElementById('wastage_type').value=x.wastage_type;document.getElementById('reason').value=x.reason||'';document.getElementById('cost_impact').value=x.cost_impact||0;document.getElementById('notes').value=x.notes||'';document.getElementById('post_stock').checked=String(x.is_stock_posted)==='1';toggleStockPost();if(String(x.is_stock_posted)==='1')document.getElementById('post_stock').checked=true;fillItemInfo();wastageModal.show();});}
function viewWastage(id){fetch('production_wastage.php?ajax=view_wastage&id='+id).then(r=>r.json()).then(d=>{if(!d.ok){alert(d.message||'Record not found');return}const x=d.row;document.getElementById('viewBody').innerHTML=`<div class="detail-grid"><div class="detail-box"><small>Work Order</small><b>${esc(x.work_order_no||'N/A')}</b></div><div class="detail-box"><small>Order Status</small><b>${esc(x.order_status||'-')}</b></div><div class="detail-box"><small>Item</small><b>${esc(x.item_name||'N/A')}</b></div><div class="detail-box"><small>Qty</small><b>${esc(x.qty)} ${esc(x.unit||'')}</b></div><div class="detail-box"><small>Wastage Type</small><b>${esc(String(x.wastage_type).replace('_',' '))}</b></div><div class="detail-box"><small>Cost Impact</small><b>Rs ${Number(x.cost_impact||0).toFixed(2)}</b></div><div class="detail-box"><small>Stock Posted</small><b>${String(x.is_stock_posted)==='1'?'Yes':'No'}</b></div><div class="detail-box"><small>Date</small><b>${esc(x.created_at||'')}</b></div><div class="detail-box" style="grid-column:1/-1"><small>Reason</small><b>${esc(x.reason||'-')}</b></div><div class="detail-box" style="grid-column:1/-1"><small>Notes</small><b>${esc(x.notes||'-')}</b></div></div>`;viewModal.show();});}
document.addEventListener('DOMContentLoaded',toggleStockPost);
</script>
</body>
</html>
