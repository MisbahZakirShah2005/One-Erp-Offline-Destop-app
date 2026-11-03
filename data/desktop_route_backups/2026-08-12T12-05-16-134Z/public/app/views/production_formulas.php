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
    die('Database connection not found. Please check config/db.php');
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
$branch_id = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
$is_superadmin = ($role_id === 1);

if ($client_id <= 0 && !$is_superadmin) {
    die('Client not found in session.');
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function ensure_production_schema(mysqli $conn): array {
    $warnings = [];

    try {
        if (!column_exists($conn, 'items', 'item_nature')) {
            $conn->query("ALTER TABLE items ADD COLUMN item_nature ENUM('product','service','raw_material','packing_material','semi_finished','finished_good','scrap','consumable','tool') NOT NULL DEFAULT 'product' AFTER type");
        }
    } catch (Throwable $e) { $warnings[] = 'items.item_nature column add nahi ho saka: ' . $e->getMessage(); }

    try {
        if (!column_exists($conn, 'items', 'unit_name')) {
            $conn->query("ALTER TABLE items ADD COLUMN unit_name VARCHAR(50) NULL AFTER item_nature");
        }
    } catch (Throwable $e) { $warnings[] = 'items.unit_name column add nahi ho saka: ' . $e->getMessage(); }

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS production_formulas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            branch_id INT NULL DEFAULT NULL,
            formula_no VARCHAR(100) NULL,
            formula_name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_by INT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pf_client (client_id),
            INDEX idx_pf_branch (branch_id),
            INDEX idx_pf_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS production_formula_inputs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            formula_id INT NOT NULL,
            item_id INT NOT NULL,
            qty DECIMAL(15,4) NOT NULL DEFAULT 0,
            unit VARCHAR(50) NULL,
            wastage_percent DECIMAL(8,3) NOT NULL DEFAULT 0,
            cost_type ENUM('material','labor','service','overhead','packing','other') NOT NULL DEFAULT 'material',
            is_stock_deduct TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_pfi_formula (formula_id),
            INDEX idx_pfi_item (item_id),
            CONSTRAINT fk_pfi_formula FOREIGN KEY (formula_id) REFERENCES production_formulas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS production_formula_outputs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            formula_id INT NOT NULL,
            item_id INT NOT NULL,
            qty DECIMAL(15,4) NOT NULL DEFAULT 0,
            unit VARCHAR(50) NULL,
            output_type ENUM('main','by_product','scrap','waste') NOT NULL DEFAULT 'main',
            is_stock_add TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_pfo_formula (formula_id),
            INDEX idx_pfo_item (item_id),
            CONSTRAINT fk_pfo_formula FOREIGN KEY (formula_id) REFERENCES production_formulas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Throwable $e) { $warnings[] = 'Production tables create nahi ho saken: ' . $e->getMessage(); }

    return $warnings;
}

function next_formula_no(mysqli $conn, int $client_id): string {
    $stmt = $conn->prepare("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM production_formulas WHERE client_id = ?");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return 'PF-' . str_pad((string)((int)($row['next_id'] ?? 1)), 5, '0', STR_PAD_LEFT);
}

$schemaWarnings = ensure_production_schema($conn);
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_formula') {
            $formula_id   = (int)($_POST['formula_id'] ?? 0);
            $formula_no   = trim((string)($_POST['formula_no'] ?? ''));
            $formula_name = trim((string)($_POST['formula_name'] ?? ''));
            $description  = trim((string)($_POST['description'] ?? ''));
            $status       = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

            if ($formula_name === '') {
                throw new Exception('Formula name required hai.');
            }

            $input_items = $_POST['input_item_id'] ?? [];
            $output_items = $_POST['output_item_id'] ?? [];

            if (!is_array($input_items) || count(array_filter($input_items)) < 1) {
                throw new Exception('Kam az kam 1 input item add karo.');
            }
            if (!is_array($output_items) || count(array_filter($output_items)) < 1) {
                throw new Exception('Kam az kam 1 output item add karo.');
            }

            $conn->begin_transaction();

            if ($formula_no === '') {
                $formula_no = next_formula_no($conn, $client_id);
            }

            if ($formula_id > 0) {
                $stmt = $conn->prepare("UPDATE production_formulas SET formula_no=?, formula_name=?, description=?, status=?, branch_id=? WHERE id=? AND client_id=?");
                $stmt->bind_param('ssssiii', $formula_no, $formula_name, $description, $status, $branch_id, $formula_id, $client_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM production_formula_inputs WHERE formula_id=?");
                $stmt->bind_param('i', $formula_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM production_formula_outputs WHERE formula_id=?");
                $stmt->bind_param('i', $formula_id);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO production_formulas (client_id, branch_id, formula_no, formula_name, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iissssi', $client_id, $branch_id, $formula_no, $formula_name, $description, $status, $user_id);
                $stmt->execute();
                $formula_id = (int)$stmt->insert_id;
                $stmt->close();
            }

            $stmtIn = $conn->prepare("INSERT INTO production_formula_inputs (formula_id, item_id, qty, unit, wastage_percent, cost_type, is_stock_deduct) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $input_qty   = $_POST['input_qty'] ?? [];
            $input_unit  = $_POST['input_unit'] ?? [];
            $input_waste = $_POST['input_wastage_percent'] ?? [];
            $input_cost  = $_POST['input_cost_type'] ?? [];
            $input_stock = $_POST['input_stock_deduct'] ?? [];

            foreach ($input_items as $idx => $item_id) {
                $item_id = (int)$item_id;
                $qty = (float)($input_qty[$idx] ?? 0);
                if ($item_id <= 0 || $qty <= 0) continue;
                $unit = trim((string)($input_unit[$idx] ?? ''));
                $waste = (float)($input_waste[$idx] ?? 0);
                $costType = in_array(($input_cost[$idx] ?? 'material'), ['material','labor','service','overhead','packing','other'], true) ? $input_cost[$idx] : 'material';
                $stockDeduct = (int)($input_stock[$idx] ?? 1) === 1 ? 1 : 0;
                $stmtIn->bind_param('iidsdsi', $formula_id, $item_id, $qty, $unit, $waste, $costType, $stockDeduct);
                $stmtIn->execute();
            }
            $stmtIn->close();

            $stmtOut = $conn->prepare("INSERT INTO production_formula_outputs (formula_id, item_id, qty, unit, output_type, is_stock_add) VALUES (?, ?, ?, ?, ?, ?)");
            $output_qty   = $_POST['output_qty'] ?? [];
            $output_unit  = $_POST['output_unit'] ?? [];
            $output_type  = $_POST['output_type'] ?? [];
            $output_stock = $_POST['output_stock_add'] ?? [];

            foreach ($output_items as $idx => $item_id) {
                $item_id = (int)$item_id;
                $qty = (float)($output_qty[$idx] ?? 0);
                if ($item_id <= 0 || $qty <= 0) continue;
                $unit = trim((string)($output_unit[$idx] ?? ''));
                $outType = in_array(($output_type[$idx] ?? 'main'), ['main','by_product','scrap','waste'], true) ? $output_type[$idx] : 'main';
                $stockAdd = (int)($output_stock[$idx] ?? 1) === 1 ? 1 : 0;
                $stmtOut->bind_param('iidssi', $formula_id, $item_id, $qty, $unit, $outType, $stockAdd);
                $stmtOut->execute();
            }
            $stmtOut->close();

            $conn->commit();
            $_SESSION['production_flash'] = ['type' => 'success', 'msg' => 'Formula save ho gai.'];
            header('Location: production_formulas.php');
            exit;
        }

        if ($action === 'inactive_formula') {
            $formula_id = (int)($_POST['formula_id'] ?? 0);
            if ($formula_id <= 0) throw new Exception('Invalid formula id.');
            $stmt = $conn->prepare("UPDATE production_formulas SET status='inactive' WHERE id=? AND client_id=?");
            $stmt->bind_param('ii', $formula_id, $client_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['production_flash'] = ['type' => 'success', 'msg' => 'Formula inactive kar di gai.'];
            header('Location: production_formulas.php');
            exit;
        }

        if ($action === 'active_formula') {
            $formula_id = (int)($_POST['formula_id'] ?? 0);
            if ($formula_id <= 0) throw new Exception('Invalid formula id.');
            $stmt = $conn->prepare("UPDATE production_formulas SET status='active' WHERE id=? AND client_id=?");
            $stmt->bind_param('ii', $formula_id, $client_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['production_flash'] = ['type' => 'success', 'msg' => 'Formula active kar di gai.'];
            header('Location: production_formulas.php');
            exit;
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        $flash = $e->getMessage();
        $flashType = 'danger';
    }
}

if (!empty($_SESSION['production_flash'])) {
    $flashType = $_SESSION['production_flash']['type'] ?? 'success';
    $flash = $_SESSION['production_flash']['msg'] ?? '';
    unset($_SESSION['production_flash']);
}

/* ================= FETCH ITEMS ================= */
$items = [];
$itemColumns = "id, name, sku, type, cost, stock_qty, track_inventory";
$itemColumns .= column_exists($conn, 'items', 'item_nature') ? ", item_nature" : ", NULL AS item_nature";
$itemColumns .= column_exists($conn, 'items', 'unit_name') ? ", unit_name" : ", NULL AS unit_name";

$stmt = $conn->prepare("SELECT $itemColumns FROM items WHERE client_id = ? AND status = 'active' ORDER BY name ASC");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

$itemMap = [];
foreach ($items as $it) {
    $itemMap[(int)$it['id']] = $it;
}

/* ================= FETCH FORMULAS ================= */
$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = $_GET['status'] ?? '';
$where = "pf.client_id = ?";
$params = [$client_id];
$types = 'i';

if ($q !== '') {
    $where .= " AND (pf.formula_name LIKE ? OR pf.formula_no LIKE ? OR pf.description LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if (in_array($statusFilter, ['active','inactive'], true)) {
    $where .= " AND pf.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$sql = "SELECT pf.*,
        COALESCE(inp.input_count,0) input_count,
        COALESCE(outp.output_count,0) output_count,
        main_item.name AS main_output_name,
        main_out.qty AS main_output_qty
    FROM production_formulas pf
    LEFT JOIN (
        SELECT formula_id, COUNT(*) input_count FROM production_formula_inputs GROUP BY formula_id
    ) inp ON inp.formula_id = pf.id
    LEFT JOIN (
        SELECT formula_id, COUNT(*) output_count FROM production_formula_outputs GROUP BY formula_id
    ) outp ON outp.formula_id = pf.id
    LEFT JOIN production_formula_outputs main_out ON main_out.id = (
        SELECT pfo2.id FROM production_formula_outputs pfo2
        WHERE pfo2.formula_id = pf.id
        ORDER BY FIELD(pfo2.output_type,'main','by_product','scrap','waste'), pfo2.id ASC
        LIMIT 1
    )
    LEFT JOIN items main_item ON main_item.id = main_out.item_id
    WHERE $where
    ORDER BY pf.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$formulas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$formulaIds = array_map(fn($r) => (int)$r['id'], $formulas);
$formulaPayload = [];
foreach ($formulas as $f) {
    $formulaPayload[(int)$f['id']] = $f;
    $formulaPayload[(int)$f['id']]['inputs'] = [];
    $formulaPayload[(int)$f['id']]['outputs'] = [];
}

if (!empty($formulaIds)) {
    $placeholders = implode(',', array_fill(0, count($formulaIds), '?'));
    $idTypes = str_repeat('i', count($formulaIds));

    $stmt = $conn->prepare("SELECT pfi.*, i.name item_name, i.sku, i.cost, i.stock_qty FROM production_formula_inputs pfi LEFT JOIN items i ON i.id=pfi.item_id WHERE pfi.formula_id IN ($placeholders) ORDER BY pfi.id ASC");
    $stmt->bind_param($idTypes, ...$formulaIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $formulaPayload[(int)$row['formula_id']]['inputs'][] = $row;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT pfo.*, i.name item_name, i.sku, i.cost, i.stock_qty FROM production_formula_outputs pfo LEFT JOIN items i ON i.id=pfo.item_id WHERE pfo.formula_id IN ($placeholders) ORDER BY FIELD(pfo.output_type,'main','by_product','scrap','waste'), pfo.id ASC");
    $stmt->bind_param($idTypes, ...$formulaIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $formulaPayload[(int)$row['formula_id']]['outputs'][] = $row;
    }
    $stmt->close();
}

$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'inputs' => 0,
];
$stmt = $conn->prepare("SELECT
    COUNT(*) total,
    SUM(status='active') active_count,
    SUM(status='inactive') inactive_count
    FROM production_formulas WHERE client_id=?");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$stats['total'] = (int)($row['total'] ?? 0);
$stats['active'] = (int)($row['active_count'] ?? 0);
$stats['inactive'] = (int)($row['inactive_count'] ?? 0);
$stmt = $conn->prepare("SELECT COUNT(*) c FROM production_formula_inputs pfi INNER JOIN production_formulas pf ON pf.id=pfi.formula_id WHERE pf.client_id=?");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$stats['inputs'] = (int)($row['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Production Formulas - One ERP</title>
<?php if (file_exists(__DIR__ . '/partials/head.php')) include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{--bg:#050914;--panel:#0f172a;--muted:#94a3b8;--txt:#f8fafc;--purple:#7c3aed;--purple2:#6d4cff;--line:rgba(148,163,184,.16)}
html,body{margin:0!important;min-height:100%;background:#050914!important;color:var(--txt)!important;font-family:Inter,"Segoe UI",Arial,sans-serif;overflow-x:hidden}
.container-scroll{min-height:100vh;background:radial-gradient(circle at 15% 0%,rgba(124,58,237,.32),transparent 30%),linear-gradient(135deg,#050914 0%,#0b1024 45%,#160f2d 100%)!important}
.main-panel{min-height:100vh;background:transparent!important}
.content-wrapper{position:relative;padding:28px!important;min-height:calc(100vh - 70px);background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.28),transparent 30%),radial-gradient(circle at 88% 2%,rgba(34,211,238,.13),transparent 28%),linear-gradient(135deg,#050914 0%,#0b1024 45%,#160f2d 100%)!important;color:var(--txt)!important}
.content-wrapper:before{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(180deg,rgba(255,255,255,.02) 1px,transparent 1px);background-size:44px 44px;pointer-events:none;opacity:.35}.content-wrapper>*{position:relative;z-index:1}
.page-title{font-size:30px;font-weight:950;letter-spacing:-.04em;text-shadow:0 0 28px rgba(124,58,237,.35)}.page-subtitle{color:#aab4cf;font-weight:700}
.action-bar{display:flex;gap:12px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.neo-btn{border:0;border-radius:15px;min-height:50px;padding:0 20px;font-weight:900;color:#fff!important;box-shadow:0 14px 32px rgba(0,0,0,.24);transition:.2s}.neo-btn:hover{transform:translateY(-2px)}.btn-green{background:linear-gradient(135deg,#059669,#16a34a)}.btn-purple{background:linear-gradient(135deg,#6d4cff,#7c3aed)}.btn-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4)}.btn-darkx{background:rgba(15,23,42,.72);border:1px solid var(--line)!important;color:#e5e7eb!important}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin:20px 0}.stat-card{min-height:128px;border-radius:20px;padding:24px;background:linear-gradient(135deg,rgba(79,70,229,.95),rgba(124,58,237,.85));box-shadow:0 20px 48px rgba(0,0,0,.28);display:flex;align-items:center;justify-content:space-between;overflow:hidden;position:relative}.stat-card.orange{background:linear-gradient(135deg,#f59e0b,#fb923c)}.stat-card.red{background:linear-gradient(135deg,#be123c,#ef4444)}.stat-card.green{background:linear-gradient(135deg,#047857,#16a34a)}.stat-card:after{content:"";width:86px;height:86px;border-radius:22px;background:rgba(255,255,255,.13);position:absolute;right:18px;top:18px}.stat-label{font-size:16px;font-weight:900;display:flex;gap:10px;align-items:center}.stat-value{font-size:36px;font-weight:950;position:relative;z-index:1}
.glass-card{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.17),transparent 34%),linear-gradient(135deg,rgba(15,23,42,.82),rgba(17,24,39,.58))!important;border:1px solid rgba(148,163,184,.18)!important;border-radius:22px!important;box-shadow:0 20px 52px rgba(0,0,0,.32),inset 0 1px 0 rgba(255,255,255,.05)!important;backdrop-filter:blur(18px) saturate(135%);overflow:hidden;color:#f8fafc!important}.filter-card{padding:16px;margin-bottom:18px}.filter-row{display:grid;grid-template-columns:220px 1fr 170px 120px;gap:12px}.form-control,.form-select{min-height:50px;border-radius:14px!important;background:rgba(15,23,42,.78)!important;color:#e5e7eb!important;border:1px solid rgba(148,163,184,.18)!important;font-weight:800!important;box-shadow:none!important}.form-control::placeholder{color:#94a3b8!important}.form-control:focus,.form-select:focus{border-color:rgba(124,58,237,.75)!important;box-shadow:0 0 0 3px rgba(124,58,237,.16)!important}.form-select option{background:#0f172a;color:#e5e7eb}.table-wrap{border-radius:22px;overflow:auto}.table{margin:0;min-width:1120px;--bs-table-bg:transparent;--bs-table-color:#e5e7eb;--bs-table-border-color:rgba(148,163,184,.10)}.table thead th{background:rgba(6,10,20,.95)!important;color:#cbd5e1!important;border:0!important;padding:17px 18px!important;text-transform:uppercase;font-size:12px;letter-spacing:.07em;font-weight:950;white-space:nowrap}.table tbody td{background:linear-gradient(135deg,rgba(15,23,42,.48),rgba(20,12,40,.30))!important;color:#e5e7eb!important;border:0!important;border-bottom:1px solid rgba(148,163,184,.10)!important;padding:17px 18px!important;font-weight:800;vertical-align:middle}.table tbody tr:hover td{background:linear-gradient(135deg,rgba(30,20,58,.65),rgba(15,23,42,.45))!important}.badge-soft{border-radius:999px;padding:8px 12px;font-weight:900}.badge-active{background:rgba(34,197,94,.14);color:#86efac;border:1px solid rgba(34,197,94,.28)}.badge-inactive{background:rgba(148,163,184,.13);color:#cbd5e1;border:1px solid rgba(148,163,184,.20)}.mini-btn{width:44px;height:44px;border:0;border-radius:14px;color:#fff;display:inline-flex;align-items:center;justify-content:center;margin:0 3px}.edit-btn{background:rgba(245,158,11,.22);border:1px solid rgba(245,158,11,.45);color:#fbbf24}.view-btn{background:rgba(6,182,212,.18);border:1px solid rgba(6,182,212,.40);color:#67e8f9}.del-btn{background:rgba(239,68,68,.20);border:1px solid rgba(239,68,68,.42);color:#fca5a5}.ok-btn{background:rgba(34,197,94,.20);border:1px solid rgba(34,197,94,.42);color:#86efac}
.modal{z-index:5000!important}.modal-backdrop{z-index:4990!important}.modal-content{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.26),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.98),rgba(17,24,39,.90))!important;color:#f8fafc!important;border:1px solid rgba(139,92,246,.38)!important;border-radius:22px!important;box-shadow:0 30px 90px rgba(0,0,0,.65);overflow:hidden}.modal-header,.modal-footer{background:rgba(7,11,20,.44)!important;border-color:rgba(148,163,184,.16)!important}.modal-body{background:rgba(15,23,42,.18)!important}.btn-close{filter:invert(1) grayscale(1);opacity:1}.section-title{font-size:16px;font-weight:950;margin:16px 0 10px}.row-box{display:grid;grid-template-columns:2.2fr .75fr .75fr .9fr .75fr 46px;gap:9px;margin-bottom:10px;align-items:center}.output-row-box{display:grid;grid-template-columns:2.2fr .75fr .75fr .95fr .75fr 46px;gap:9px;margin-bottom:10px;align-items:center}.remove-row{width:44px;height:44px;border-radius:13px;border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.16);color:#fca5a5}.small-muted{color:#94a3b8;font-size:12px;font-weight:700}.alert{border-radius:16px;border:1px solid rgba(255,255,255,.14)}
@media(max-width:1200px){.stat-grid{grid-template-columns:repeat(2,1fr)}.filter-row{grid-template-columns:1fr 1fr}.row-box,.output-row-box{grid-template-columns:1fr 1fr}.remove-row{width:100%}}@media(max-width:768px){.content-wrapper{padding:16px!important}.page-title{font-size:25px}.stat-grid{grid-template-columns:1fr}.action-bar{justify-content:stretch}.neo-btn{width:100%}.filter-row{grid-template-columns:1fr}.modal-dialog{margin:10px!important}.row-box,.output-row-box{grid-template-columns:1fr}.table{min-width:950px}}

/* =====================================================
   PRODUCTION_FORMULAS.PHP - TOPBAR + MODAL SIZE FIX
   Paste at END before 
===================================================== */

/* topbar overlay fix */
body.production-page .main-panel{
    position:relative !important;
    min-height:100vh !important;
}

/* yahi main fix hai: topbar ke neeche content ko push karega */
body.production-page .content-wrapper{
    padding-top:112px !important;
    padding-left:28px !important;
    padding-right:28px !important;
    padding-bottom:34px !important;
    min-height:calc(100vh - 70px) !important;
}

/* page header ko topbar ke neeche visible rakho */
body.production-page .content-wrapper > .d-flex.justify-content-between{
    margin-top:0 !important;
    margin-bottom:24px !important;
    position:relative !important;
    z-index:3 !important;
}

body.production-page .page-title{
    display:block !important;
    color:#ffffff !important;
    font-size:32px !important;
    font-weight:950 !important;
    line-height:1.15 !important;
    margin:0 0 6px 0 !important;
}

body.production-page .page-subtitle{
    color:#aab4cf !important;
    font-weight:800 !important;
    opacity:1 !important;
}

/* topbar apni jagah rahe, lekin content hide na kare */
body.production-page .navbar,
body.production-page .navbar.fixed-top,
body.production-page .my-navbar,
body.production-page .topbar{
    z-index:1040 !important;
}

body.production-page .navbar.my-navbar.fixed-top{
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* stats ko header se proper gap */
body.production-page .stat-grid{
    margin-top:18px !important;
}

/* =====================================================
   MODAL SMALL + FOOTER BUTTONS VISIBLE
===================================================== */

/* Add/Edit Production Formula popup ko small karo */
body.production-page #formulaModal .modal-dialog{
    max-width:880px !important;
    width:calc(100% - 34px) !important;
    margin:18px auto !important;
}

/* agar Bootstrap modal-xl force kar raha ho to override */
body.production-page #formulaModal .modal-xl{
    max-width:880px !important;
}

/* modal screen se bahar na jaye */
body.production-page #formulaModal .modal-content{
    max-height:calc(100vh - 36px) !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
}

/* form ko flex banao taki footer sticky rahe */
body.production-page #formulaModal .modal-content > form{
    display:flex !important;
    flex-direction:column !important;
    min-height:0 !important;
    max-height:calc(100vh - 36px) !important;
}

/* modal body scrollable */
body.production-page #formulaModal .modal-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    max-height:calc(100vh - 165px) !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:16px 18px 12px !important;
}

/* modal header small */
body.production-page #formulaModal .modal-header{
    flex:0 0 auto !important;
    padding:14px 18px !important;
}

/* footer hamesha visible */
body.production-page #formulaModal .modal-footer{
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

/* modal title and close button compact */
body.production-page #formulaModal .modal-title{
    font-size:20px !important;
    font-weight:950 !important;
}

body.production-page #formulaModal .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
}

/* form fields thore compact */
body.production-page #formulaModal .form-control,
body.production-page #formulaModal .form-select,
body.production-page #formulaModal input,
body.production-page #formulaModal select,
body.production-page #formulaModal textarea{
    min-height:42px !important;
    border-radius:12px !important;
    font-size:14px !important;
}

body.production-page #formulaModal textarea{
    min-height:58px !important;
}

/* row boxes compact */
body.production-page #formulaModal .row-box,
body.production-page #formulaModal .output-row-box{
    grid-template-columns:2fr .7fr .7fr .85fr .7fr 42px !important;
    gap:8px !important;
    margin-bottom:8px !important;
}

body.production-page #formulaModal .remove-row{
    width:40px !important;
    height:40px !important;
    border-radius:12px !important;
}

/* add input/output buttons compact */
body.production-page #formulaModal .neo-btn{
    min-height:42px !important;
    border-radius:13px !important;
    padding:0 16px !important;
}

body.production-page #formulaModal .section-title{
    margin:12px 0 8px !important;
    font-size:15px !important;
}

/* scrollbar purple */
body.production-page #formulaModal .modal-body::-webkit-scrollbar{
    width:9px !important;
}

body.production-page #formulaModal .modal-body::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.production-page #formulaModal .modal-body::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* View modal bhi thora small */
body.production-page #viewModal .modal-dialog{
    max-width:760px !important;
    width:calc(100% - 34px) !important;
    margin:20px auto !important;
}

body.production-page #viewModal .modal-content{
    max-height:calc(100vh - 40px) !important;
    overflow:hidden !important;
}

body.production-page #viewModal .modal-body{
    max-height:calc(100vh - 140px) !important;
    overflow-y:auto !important;
}

/* responsive */
@media(max-width:1200px){
    body.production-page #formulaModal .row-box,
    body.production-page #formulaModal .output-row-box{
        grid-template-columns:1fr 1fr !important;
    }

    body.production-page #formulaModal .remove-row{
        width:100% !important;
    }
}

@media(max-width:991px){
    body.production-page .content-wrapper{
        padding-top:104px !important;
        padding-left:18px !important;
        padding-right:18px !important;
    }

    body.production-page #formulaModal .modal-dialog{
        max-width:720px !important;
        width:calc(100% - 22px) !important;
        margin:12px auto !important;
    }

    body.production-page #formulaModal .modal-body{
        max-height:calc(100vh - 155px) !important;
    }
}

@media(max-width:575px){
    body.production-page .content-wrapper{
        padding-top:98px !important;
        padding-left:14px !important;
        padding-right:14px !important;
    }

    body.production-page .page-title{
        font-size:26px !important;
    }

    body.production-page .action-bar .neo-btn{
        width:100% !important;
    }

    body.production-page #formulaModal .modal-dialog{
        width:calc(100% - 14px) !important;
        margin:7px auto !important;
    }

    body.production-page #formulaModal .modal-footer{
        flex-direction:column !important;
    }

    body.production-page #formulaModal .modal-footer .neo-btn{
        width:100% !important;
    }

    body.production-page #formulaModal .row-box,
    body.production-page #formulaModal .output-row-box{
        grid-template-columns:1fr !important;
    }
}
</style>
</head>
<body class="production-page">
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include(__DIR__ . '/partials/sidebar.php'); ?>
    </div>
    <div class="main-panel">
        <?php if (file_exists(__DIR__ . '/partials/topbar.php')) include(__DIR__ . '/partials/topbar.php'); ?>
        <div class="content-wrapper">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <h1 class="page-title mb-1">Production Formulas</h1>
                    <div class="page-subtitle">Formula / Recipe / BOM setup for every industry</div>
                </div>
                <div class="action-bar">
                    <button class="neo-btn btn-green" type="button" onclick="openAddModal()"><i class="bi bi-plus-lg me-2"></i>Add Formula</button>
                    <button class="neo-btn btn-purple" type="button" onclick="location.reload()"><i class="bi bi-arrow-clockwise me-2"></i>Refresh</button>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flashType) ?> mt-3 mb-0"><?= e($flash) ?></div>
            <?php endif; ?>
            <?php foreach ($schemaWarnings as $warn): ?>
                <div class="alert alert-warning mt-3 mb-0"><?= e($warn) ?></div>
            <?php endforeach; ?>

            <div class="stat-grid">
                <div class="stat-card"><div class="stat-label"><i class="bi bi-diagram-3"></i>Total Formulas</div><div class="stat-value"><?= (int)$stats['total'] ?></div></div>
                <div class="stat-card green"><div class="stat-label"><i class="bi bi-check2-circle"></i>Active</div><div class="stat-value"><?= (int)$stats['active'] ?></div></div>
                <div class="stat-card orange"><div class="stat-label"><i class="bi bi-box-arrow-in-down"></i>Total Inputs</div><div class="stat-value"><?= (int)$stats['inputs'] ?></div></div>
                <div class="stat-card red"><div class="stat-label"><i class="bi bi-pause-circle"></i>Inactive</div><div class="stat-value"><?= (int)$stats['inactive'] ?></div></div>
            </div>

            <div class="glass-card filter-card">
                <form method="GET" class="filter-row">
                    <button type="button" class="neo-btn btn-darkx"><i class="bi bi-lightning-charge-fill me-2"></i>Quick Actions</button>
                    <input type="text" name="q" class="form-control" placeholder="Search formula name, number..." value="<?= e($q) ?>">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                    <button class="neo-btn btn-purple" type="submit"><i class="bi bi-funnel me-2"></i>Filter</button>
                </form>
            </div>

            <div class="glass-card table-wrap">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th width="70">ID</th>
                            <th>Formula No</th>
                            <th>Formula Name</th>
                            <th>Main Output</th>
                            <th>Output Qty</th>
                            <th>Inputs</th>
                            <th>Outputs</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th width="180">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($formulas)): ?>
                            <tr><td colspan="10" class="text-center py-5">No formula found. Add your first Production Formula.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($formulas as $f): ?>
                            <tr>
                                <td><?= (int)$f['id'] ?></td>
                                <td><?= e($f['formula_no']) ?></td>
                                <td><strong><?= e($f['formula_name']) ?></strong><div class="small-muted"><?= e(mb_strimwidth((string)$f['description'],0,55,'...')) ?></div></td>
                                <td><?= e($f['main_output_name'] ?: 'No Output') ?></td>
                                <td><?= rtrim(rtrim(number_format((float)$f['main_output_qty'],4,'.',''), '0'), '.') ?></td>
                                <td><?= (int)$f['input_count'] ?></td>
                                <td><?= (int)$f['output_count'] ?></td>
                                <td><span class="badge-soft <?= $f['status']==='active'?'badge-active':'badge-inactive' ?>"><?= e(ucfirst($f['status'])) ?></span></td>
                                <td><?= e(date('d M Y', strtotime((string)$f['created_at']))) ?></td>
                                <td>
                                    <button type="button" class="mini-btn view-btn" title="View" onclick="openViewModal(<?= (int)$f['id'] ?>)"><i class="bi bi-eye"></i></button>
                                    <button type="button" class="mini-btn edit-btn" title="Edit" onclick="openEditModal(<?= (int)$f['id'] ?>)"><i class="bi bi-pencil-fill"></i></button>
                                    <?php if ($f['status'] === 'active'): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Formula inactive karni hai?');"><input type="hidden" name="action" value="inactive_formula"><input type="hidden" name="formula_id" value="<?= (int)$f['id'] ?>"><button class="mini-btn del-btn" title="Inactive"><i class="bi bi-pause-fill"></i></button></form>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline"><input type="hidden" name="action" value="active_formula"><input type="hidden" name="formula_id" value="<?= (int)$f['id'] ?>"><button class="mini-btn ok-btn" title="Active"><i class="bi bi-play-fill"></i></button></form>
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

<div class="modal fade" id="formulaModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="formulaForm">
                <input type="hidden" name="action" value="save_formula">
                <input type="hidden" name="formula_id" id="formula_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="formulaModalTitle">Add Production Formula</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label fw-bold">Formula No</label><input type="text" name="formula_no" id="formula_no" class="form-control" placeholder="Auto"></div>
                        <div class="col-md-5"><label class="form-label fw-bold">Formula Name *</label><input type="text" name="formula_name" id="formula_name" class="form-control" required placeholder="e.g. Chicken Burger / Gold Ring"></div>
                        <div class="col-md-2"><label class="form-label fw-bold">Status</label><select name="status" id="formula_status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                        <div class="col-md-12"><label class="form-label fw-bold">Description</label><textarea name="description" id="description" class="form-control" rows="2" placeholder="Formula notes..."></textarea></div>
                    </div>

                    <div class="section-title"><i class="bi bi-box-arrow-in-down me-2"></i>Input Items / Raw Material / Labor</div>
                    <div id="inputRows"></div>
                    <button type="button" class="neo-btn btn-cyan" onclick="addInputRow()"><i class="bi bi-plus-circle me-2"></i>Add Input Row</button>

                    <div class="section-title mt-4"><i class="bi bi-box-arrow-up me-2"></i>Output Items / Finished Good / Scrap</div>
                    <div id="outputRows"></div>
                    <button type="button" class="neo-btn btn-cyan" onclick="addOutputRow()"><i class="bi bi-plus-circle me-2"></i>Add Output Row</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="neo-btn btn-darkx" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="neo-btn btn-green"><i class="bi bi-check2-circle me-2"></i>Save Formula</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Formula Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="viewBody"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const items = <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const formulas = <?= json_encode($formulaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const modal = new bootstrap.Modal(document.getElementById('formulaModal'));
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));

function itemOptions(selectedId='') {
    return '<option value="">Select Item</option>' + items.map(it => {
        const sku = it.sku ? ` - ${escapeHtml(it.sku)}` : '';
        const nature = it.item_nature ? ` (${escapeHtml(it.item_nature)})` : '';
        const sel = String(it.id) === String(selectedId) ? 'selected' : '';
        return `<option value="${it.id}" data-unit="${escapeHtml(it.unit_name || '')}" data-cost="${it.cost || 0}" ${sel}>${escapeHtml(it.name || '')}${sku}${nature}</option>`;
    }).join('');
}
function escapeHtml(str){return String(str ?? '').replace(/[&<>'"]/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[t]));}
function unitFromSelect(sel){const opt=sel.options[sel.selectedIndex];return opt ? (opt.dataset.unit || '') : '';}
function addInputRow(row={}) {
    const wrap = document.getElementById('inputRows');
    const div = document.createElement('div');
    div.className = 'row-box';
    div.innerHTML = `
        <select name="input_item_id[]" class="form-select item-select" onchange="fillUnit(this,'input')">${itemOptions(row.item_id || '')}</select>
        <input type="number" step="0.0001" min="0" name="input_qty[]" class="form-control" placeholder="Qty" value="${row.qty || ''}">
        <input type="text" name="input_unit[]" class="form-control input-unit" placeholder="Unit" value="${escapeHtml(row.unit || '')}">
        <select name="input_cost_type[]" class="form-select">
            ${['material','labor','service','overhead','packing','other'].map(v=>`<option value="${v}" ${String(row.cost_type||'material')===v?'selected':''}>${v}</option>`).join('')}
        </select>
        <select name="input_stock_deduct[]" class="form-select"><option value="1" ${String(row.is_stock_deduct ?? 1)==='1'?'selected':''}>Deduct</option><option value="0" ${String(row.is_stock_deduct ?? 1)==='0'?'selected':''}>No Stock</option></select>
        <button type="button" class="remove-row" onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>
        <input type="hidden" name="input_wastage_percent[]" value="${row.wastage_percent || 0}">
    `;
    wrap.appendChild(div);
}
function addOutputRow(row={}) {
    const wrap = document.getElementById('outputRows');
    const div = document.createElement('div');
    div.className = 'output-row-box';
    div.innerHTML = `
        <select name="output_item_id[]" class="form-select item-select" onchange="fillUnit(this,'output')">${itemOptions(row.item_id || '')}</select>
        <input type="number" step="0.0001" min="0" name="output_qty[]" class="form-control" placeholder="Qty" value="${row.qty || ''}">
        <input type="text" name="output_unit[]" class="form-control output-unit" placeholder="Unit" value="${escapeHtml(row.unit || '')}">
        <select name="output_type[]" class="form-select">
            ${['main','by_product','scrap','waste'].map(v=>`<option value="${v}" ${String(row.output_type||'main')===v?'selected':''}>${v.replace('_',' ')}</option>`).join('')}
        </select>
        <select name="output_stock_add[]" class="form-select"><option value="1" ${String(row.is_stock_add ?? 1)==='1'?'selected':''}>Add</option><option value="0" ${String(row.is_stock_add ?? 1)==='0'?'selected':''}>No Stock</option></select>
        <button type="button" class="remove-row" onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>
    `;
    wrap.appendChild(div);
}
function fillUnit(sel, type){const unit=unitFromSelect(sel);const row=sel.closest(type==='input'?'.row-box':'.output-row-box');const inp=row.querySelector(type==='input'?'.input-unit':'.output-unit');if(inp && !inp.value) inp.value=unit;}
function resetModal(){document.getElementById('formulaForm').reset();document.getElementById('formula_id').value='';document.getElementById('inputRows').innerHTML='';document.getElementById('outputRows').innerHTML='';}
function openAddModal(){resetModal();document.getElementById('formulaModalTitle').innerText='Add Production Formula';addInputRow();addOutputRow({output_type:'main'});modal.show();}
function openEditModal(id){const f=formulas[id];if(!f)return;resetModal();document.getElementById('formulaModalTitle').innerText='Edit Production Formula';document.getElementById('formula_id').value=f.id;document.getElementById('formula_no').value=f.formula_no || '';document.getElementById('formula_name').value=f.formula_name || '';document.getElementById('description').value=f.description || '';document.getElementById('formula_status').value=f.status || 'active';(f.inputs||[]).forEach(addInputRow);(f.outputs||[]).forEach(addOutputRow);if(!(f.inputs||[]).length)addInputRow();if(!(f.outputs||[]).length)addOutputRow();modal.show();}
function openViewModal(id){const f=formulas[id];if(!f)return;let html=`<h4 class="fw-black">${escapeHtml(f.formula_name)}</h4><div class="small-muted mb-3">${escapeHtml(f.formula_no || '')} | ${escapeHtml(f.status || '')}</div><p>${escapeHtml(f.description || '')}</p>`;html += '<div class="section-title">Inputs</div><div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Cost Type</th><th>Stock</th></tr></thead><tbody>';(f.inputs||[]).forEach(r=>{html+=`<tr><td>${escapeHtml(r.item_name||'')}</td><td>${Number(r.qty||0)}</td><td>${escapeHtml(r.unit||'')}</td><td>${escapeHtml(r.cost_type||'')}</td><td>${String(r.is_stock_deduct)==='1'?'Deduct':'No Stock'}</td></tr>`});html+='</tbody></table></div>';html += '<div class="section-title">Outputs</div><div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Type</th><th>Stock</th></tr></thead><tbody>';(f.outputs||[]).forEach(r=>{html+=`<tr><td>${escapeHtml(r.item_name||'')}</td><td>${Number(r.qty||0)}</td><td>${escapeHtml(r.unit||'')}</td><td>${escapeHtml(r.output_type||'')}</td><td>${String(r.is_stock_add)==='1'?'Add':'No Stock'}</td></tr>`});html+='</tbody></table></div>';document.getElementById('viewBody').innerHTML=html;viewModal.show();}
</script>
</body>
</html>
