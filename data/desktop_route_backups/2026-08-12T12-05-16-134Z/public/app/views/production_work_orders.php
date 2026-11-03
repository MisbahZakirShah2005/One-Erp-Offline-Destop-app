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

if (empty($_SESSION['user_id'])) { header('Location: /public/app/views/login_view.php'); exit; }
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_superadmin = ($role_id === 1);
if (!$is_superadmin && $client_id <= 0) die('Client not found in session.');

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function json_out($arr): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }
function qnum($v): float { return is_numeric($v) ? (float)$v : 0.0; }

function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function ensure_production_schema(mysqli $conn): array {
    $w=[];
    try { if (!column_exists($conn,'items','item_nature')) $conn->query("ALTER TABLE items ADD COLUMN item_nature ENUM('product','service','raw_material','packing_material','semi_finished','finished_good','scrap','consumable','tool') NOT NULL DEFAULT 'product' AFTER type"); } catch(Throwable $e){$w[]=$e->getMessage();}
    try { if (!column_exists($conn,'items','unit_name')) $conn->query("ALTER TABLE items ADD COLUMN unit_name VARCHAR(50) NULL AFTER item_nature"); } catch(Throwable $e){$w[]=$e->getMessage();}
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS production_formulas (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, branch_id INT NULL DEFAULT NULL, formula_no VARCHAR(100) NULL, formula_name VARCHAR(255) NOT NULL, description TEXT NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_by INT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_pf_client (client_id), INDEX idx_pf_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS production_formula_inputs (id INT AUTO_INCREMENT PRIMARY KEY, formula_id INT NOT NULL, item_id INT NOT NULL, qty DECIMAL(15,4) NOT NULL DEFAULT 0, unit VARCHAR(50) NULL, wastage_percent DECIMAL(8,3) NOT NULL DEFAULT 0, cost_type ENUM('material','labor','service','overhead','packing','other') NOT NULL DEFAULT 'material', is_stock_deduct TINYINT(1) NOT NULL DEFAULT 1, INDEX idx_pfi_formula (formula_id), INDEX idx_pfi_item (item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS production_formula_outputs (id INT AUTO_INCREMENT PRIMARY KEY, formula_id INT NOT NULL, item_id INT NOT NULL, qty DECIMAL(15,4) NOT NULL DEFAULT 0, unit VARCHAR(50) NULL, output_type ENUM('main','by_product','scrap','waste') NOT NULL DEFAULT 'main', is_stock_add TINYINT(1) NOT NULL DEFAULT 1, INDEX idx_pfo_formula (formula_id), INDEX idx_pfo_item (item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS production_work_orders (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, branch_id INT NULL DEFAULT NULL, work_order_no VARCHAR(100) NOT NULL, formula_id INT NULL, reference_type VARCHAR(100) NULL, reference_id INT NULL, production_date DATE NULL, expected_start DATETIME NULL, expected_end DATETIME NULL, actual_start DATETIME NULL, actual_end DATETIME NULL, status ENUM('draft','planned','in_process','completed','cancelled') NOT NULL DEFAULT 'draft', material_cost DECIMAL(15,2) NOT NULL DEFAULT 0, labor_cost DECIMAL(15,2) NOT NULL DEFAULT 0, overhead_cost DECIMAL(15,2) NOT NULL DEFAULT 0, wastage_cost DECIMAL(15,2) NOT NULL DEFAULT 0, other_cost DECIMAL(15,2) NOT NULL DEFAULT 0, total_cost DECIMAL(15,2) NOT NULL DEFAULT 0, cost_per_unit DECIMAL(15,4) NOT NULL DEFAULT 0, notes TEXT NULL, created_by INT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_pwo_client_no (client_id, work_order_no), INDEX idx_pwo_client (client_id), INDEX idx_pwo_formula (formula_id), INDEX idx_pwo_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS production_work_order_inputs (id INT AUTO_INCREMENT PRIMARY KEY, work_order_id INT NOT NULL, item_id INT NOT NULL, expected_qty DECIMAL(15,4) NOT NULL DEFAULT 0, actual_qty DECIMAL(15,4) NOT NULL DEFAULT 0, unit VARCHAR(50) NULL, unit_cost DECIMAL(15,4) NOT NULL DEFAULT 0, total_cost DECIMAL(15,2) NOT NULL DEFAULT 0, cost_type ENUM('material','labor','service','overhead','packing','other') NOT NULL DEFAULT 'material', is_stock_deduct TINYINT(1) NOT NULL DEFAULT 1, INDEX idx_pwoi_order (work_order_id), INDEX idx_pwoi_item (item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS production_work_order_outputs (id INT AUTO_INCREMENT PRIMARY KEY, work_order_id INT NOT NULL, item_id INT NOT NULL, expected_qty DECIMAL(15,4) NOT NULL DEFAULT 0, actual_qty DECIMAL(15,4) NOT NULL DEFAULT 0, unit VARCHAR(50) NULL, output_type ENUM('main','by_product','scrap','waste') NOT NULL DEFAULT 'main', is_stock_add TINYINT(1) NOT NULL DEFAULT 1, INDEX idx_pwoo_order (work_order_id), INDEX idx_pwoo_item (item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS production_wastage_logs (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, branch_id INT NULL DEFAULT NULL, work_order_id INT NOT NULL, item_id INT NULL, qty DECIMAL(15,4) NOT NULL DEFAULT 0, unit VARCHAR(50) NULL, wastage_type ENUM('recoverable','non_recoverable') NOT NULL DEFAULT 'non_recoverable', reason VARCHAR(255) NULL, cost_impact DECIMAL(15,2) NOT NULL DEFAULT 0, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_pwl_client (client_id), INDEX idx_pwl_order (work_order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Throwable $e){$w[]='Production schema error: '.$e->getMessage();}
    return $w;
}
$schemaWarnings = ensure_production_schema($conn);

function scope_where(string $alias=''): string {
    global $is_superadmin, $client_id;
    $p = $alias ? $alias.'.' : '';
    return $is_superadmin ? '1=1' : $p.'client_id='.(int)$client_id;
}
function next_work_order_no(mysqli $conn, int $client_id): string {
    $prefix = 'WO-' . date('Ymd') . '-';
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT work_order_no FROM production_work_orders WHERE client_id=? AND work_order_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('is', $client_id, $like); $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc()['work_order_no'] ?? ''; $stmt->close();
    $n = 1; if ($last && preg_match('/-(\d+)$/', $last, $m)) $n = ((int)$m[1]) + 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}
function recalc_order_costs(mysqli $conn, int $order_id): array {
    $costs = ['material'=>0,'labor'=>0,'service'=>0,'overhead'=>0,'packing'=>0,'other'=>0];
    $stmt = $conn->prepare("SELECT cost_type, SUM(total_cost) total FROM production_work_order_inputs WHERE work_order_id=? GROUP BY cost_type");
    $stmt->bind_param('i',$order_id); $stmt->execute(); $res=$stmt->get_result();
    while($r=$res->fetch_assoc()) $costs[$r['cost_type']] = (float)$r['total'];
    $stmt->close();
    $material = $costs['material'] + $costs['packing'];
    $labor = $costs['labor'] + $costs['service'];
    $overhead = $costs['overhead'];
    $other = $costs['other'];
    $waste = 0;
    $stmt = $conn->prepare("SELECT SUM(actual_qty * IFNULL(i.cost,0)) total FROM production_work_order_outputs o LEFT JOIN items i ON i.id=o.item_id WHERE o.work_order_id=? AND o.output_type='waste'");
    $stmt->bind_param('i',$order_id); $stmt->execute(); $waste=(float)($stmt->get_result()->fetch_assoc()['total'] ?? 0); $stmt->close();
    $total = $material + $labor + $overhead + $other + $waste;
    $mainQty = 0;
    $stmt = $conn->prepare("SELECT SUM(actual_qty) qty FROM production_work_order_outputs WHERE work_order_id=? AND output_type='main'");
    $stmt->bind_param('i',$order_id); $stmt->execute(); $mainQty=(float)($stmt->get_result()->fetch_assoc()['qty'] ?? 0); $stmt->close();
    $cpu = $mainQty > 0 ? $total / $mainQty : 0;
    $stmt = $conn->prepare("UPDATE production_work_orders SET material_cost=?, labor_cost=?, overhead_cost=?, wastage_cost=?, other_cost=?, total_cost=?, cost_per_unit=? WHERE id=?");
    $stmt->bind_param('dddddddi',$material,$labor,$overhead,$waste,$other,$total,$cpu,$order_id); $stmt->execute(); $stmt->close();
    return compact('material','labor','overhead','waste','other','total','cpu');
}
function insert_stock_movement(mysqli $conn, int $client_id, int $branch_id, int $item_id, float $qty, string $type, string $reason, string $ref, string $notes): void {
    if (!table_exists($conn,'stock_movements')) return;
    $q = (int)round($qty);
    $stmt = $conn->prepare("INSERT INTO stock_movements (item_id, client_id, branch_id, qty, type, reason, reference_no, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param('iiiissss', $item_id, $client_id, $branch_id, $q, $type, $reason, $ref, $notes);
    $stmt->execute(); $stmt->close();
}

if (isset($_POST['ajax']) || isset($_GET['ajax'])) {
    $ajax = $_POST['ajax'] ?? $_GET['ajax'];
    try {
        if ($ajax === 'formula_details') {
            $formula_id = (int)($_GET['formula_id'] ?? 0);
            $multiplier = max(0.0001, qnum($_GET['qty'] ?? 1));
            $stmt = $conn->prepare("SELECT * FROM production_formulas WHERE id=? AND ".scope_where()." AND status='active'");
            $stmt->bind_param('i',$formula_id); $stmt->execute(); $formula=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$formula) json_out(['ok'=>false,'message'=>'Formula not found.']);
            $inputs=[]; $outputs=[];
            $stmt=$conn->prepare("SELECT fi.*, i.name item_name, i.stock_qty, i.cost, i.track_inventory FROM production_formula_inputs fi JOIN items i ON i.id=fi.item_id WHERE fi.formula_id=? ORDER BY fi.id ASC");
            $stmt->bind_param('i',$formula_id); $stmt->execute(); $res=$stmt->get_result();
            while($r=$res->fetch_assoc()) { $r['expected_qty']=(float)$r['qty']*$multiplier; $r['actual_qty']=$r['expected_qty']; $r['unit_cost']=(float)$r['cost']; $r['total_cost']=$r['actual_qty']*$r['unit_cost']; $inputs[]=$r; }
            $stmt->close();
            $stmt=$conn->prepare("SELECT fo.*, i.name item_name, i.stock_qty, i.cost FROM production_formula_outputs fo JOIN items i ON i.id=fo.item_id WHERE fo.formula_id=? ORDER BY FIELD(fo.output_type,'main','by_product','scrap','waste'), fo.id ASC");
            $stmt->bind_param('i',$formula_id); $stmt->execute(); $res=$stmt->get_result();
            while($r=$res->fetch_assoc()) { $r['expected_qty']=(float)$r['qty']*$multiplier; $r['actual_qty']=$r['expected_qty']; $outputs[]=$r; }
            $stmt->close();
            json_out(['ok'=>true,'formula'=>$formula,'inputs'=>$inputs,'outputs'=>$outputs]);
        }

        if ($ajax === 'save_order') {
            $order_id = (int)($_POST['order_id'] ?? 0);
            $formula_id = (int)($_POST['formula_id'] ?? 0);
            $work_order_no = trim($_POST['work_order_no'] ?? '');
            $production_date = $_POST['production_date'] ?: date('Y-m-d');
            $status = in_array($_POST['status'] ?? 'planned', ['draft','planned','in_process']) ? $_POST['status'] : 'planned';
            $reference_type = trim($_POST['reference_type'] ?? '');
            $reference_id = ($_POST['reference_id'] ?? '') !== '' ? (int)$_POST['reference_id'] : null;
            $notes = trim($_POST['notes'] ?? '');
            $inputs = json_decode($_POST['inputs_json'] ?? '[]', true) ?: [];
            $outputs = json_decode($_POST['outputs_json'] ?? '[]', true) ?: [];
            if ($formula_id <= 0) json_out(['ok'=>false,'message'=>'Formula select karo.']);
            if (!$inputs || !$outputs) json_out(['ok'=>false,'message'=>'Inputs/Outputs load nahi hue.']);
            if ($work_order_no === '') $work_order_no = next_work_order_no($conn, $client_id);

            $conn->begin_transaction();
            if ($order_id > 0) {
                $stmt=$conn->prepare("SELECT status FROM production_work_orders WHERE id=? AND ".scope_where());
                $stmt->bind_param('i',$order_id); $stmt->execute(); $old=$stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$old) throw new Exception('Work order not found.');
                if ($old['status']==='completed') throw new Exception('Completed order edit nahi ho sakta.');
                $stmt=$conn->prepare("UPDATE production_work_orders SET work_order_no=?, formula_id=?, reference_type=?, reference_id=?, production_date=?, status=?, notes=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param('sisisssi',$work_order_no,$formula_id,$reference_type,$reference_id,$production_date,$status,$notes,$order_id); $stmt->execute(); $stmt->close();
                $stmt=$conn->prepare("DELETE FROM production_work_order_inputs WHERE work_order_id=?"); $stmt->bind_param('i',$order_id); $stmt->execute(); $stmt->close();
                $stmt=$conn->prepare("DELETE FROM production_work_order_outputs WHERE work_order_id=?"); $stmt->bind_param('i',$order_id); $stmt->execute(); $stmt->close();
            } else {
                $stmt=$conn->prepare("INSERT INTO production_work_orders (client_id, branch_id, work_order_no, formula_id, reference_type, reference_id, production_date, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iisisssssi',$client_id,$branch_id,$work_order_no,$formula_id,$reference_type,$reference_id,$production_date,$status,$notes,$user_id); $stmt->execute(); $order_id=$stmt->insert_id; $stmt->close();
            }
            $stmtIn=$conn->prepare("INSERT INTO production_work_order_inputs (work_order_id,item_id,expected_qty,actual_qty,unit,unit_cost,total_cost,cost_type,is_stock_deduct) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach($inputs as $r){
                $item_id=(int)($r['item_id']??0); if($item_id<=0) continue;
                $expected=qnum($r['expected_qty']??0); $actual=qnum($r['actual_qty']??$expected); $unit=trim($r['unit']??''); $unit_cost=qnum($r['unit_cost']??0); $total=$actual*$unit_cost; $cost_type=$r['cost_type']??'material'; $deduct=(int)($r['is_stock_deduct']??1);
                $stmtIn->bind_param('iidddsdsi',$order_id,$item_id,$expected,$actual,$unit,$unit_cost,$total,$cost_type,$deduct); $stmtIn->execute();
            }
            $stmtIn->close();
            $stmtOut=$conn->prepare("INSERT INTO production_work_order_outputs (work_order_id,item_id,expected_qty,actual_qty,unit,output_type,is_stock_add) VALUES (?,?,?,?,?,?,?)");
            foreach($outputs as $r){
                $item_id=(int)($r['item_id']??0); if($item_id<=0) continue;
                $expected=qnum($r['expected_qty']??0); $actual=qnum($r['actual_qty']??$expected); $unit=trim($r['unit']??''); $otype=$r['output_type']??'main'; $add=(int)($r['is_stock_add']??1);
                $stmtOut->bind_param('iidddsi',$order_id,$item_id,$expected,$actual,$unit,$otype,$add); $stmtOut->execute();
            }
            $stmtOut->close();
            recalc_order_costs($conn,$order_id);
            $conn->commit();
            json_out(['ok'=>true,'message'=>'Work order saved successfully.','id'=>$order_id]);
        }

        if ($ajax === 'get_order') {
            $order_id=(int)($_GET['id']??0);
            $stmt=$conn->prepare("SELECT wo.*, f.formula_name, f.formula_no FROM production_work_orders wo LEFT JOIN production_formulas f ON f.id=wo.formula_id WHERE wo.id=? AND ".scope_where('wo'));
            $stmt->bind_param('i',$order_id); $stmt->execute(); $order=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$order) json_out(['ok'=>false,'message'=>'Work order not found.']);
            $inputs=[]; $outputs=[];
            $stmt=$conn->prepare("SELECT wi.*, i.name item_name, i.stock_qty, i.track_inventory FROM production_work_order_inputs wi LEFT JOIN items i ON i.id=wi.item_id WHERE wi.work_order_id=? ORDER BY wi.id ASC");
            $stmt->bind_param('i',$order_id); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()) $inputs[]=$r; $stmt->close();
            $stmt=$conn->prepare("SELECT wo.*, i.name item_name, i.stock_qty FROM production_work_order_outputs wo LEFT JOIN items i ON i.id=wo.item_id WHERE wo.work_order_id=? ORDER BY FIELD(wo.output_type,'main','by_product','scrap','waste'), wo.id ASC");
            $stmt->bind_param('i',$order_id); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()) $outputs[]=$r; $stmt->close();
            json_out(['ok'=>true,'order'=>$order,'inputs'=>$inputs,'outputs'=>$outputs]);
        }

        if ($ajax === 'update_status') {
            $order_id=(int)($_POST['id']??0); $new=$_POST['status']??'';
            if(!in_array($new,['draft','planned','in_process','cancelled'],true)) json_out(['ok'=>false,'message'=>'Invalid status.']);
            $setStart = $new==='in_process' ? ', actual_start = IFNULL(actual_start,NOW())' : '';
            $stmt=$conn->prepare("UPDATE production_work_orders SET status=? $setStart WHERE id=? AND ".scope_where()." AND status<>'completed'");
            $stmt->bind_param('si',$new,$order_id); $stmt->execute(); $ok=$stmt->affected_rows>=0; $stmt->close();
            json_out(['ok'=>$ok,'message'=>'Status updated.']);
        }

        if ($ajax === 'complete_order') {
            $order_id=(int)($_POST['id']??0);
            $conn->begin_transaction();
            $stmt=$conn->prepare("SELECT * FROM production_work_orders WHERE id=? AND ".scope_where()." FOR UPDATE");
            $stmt->bind_param('i',$order_id); $stmt->execute(); $order=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$order) throw new Exception('Work order not found.');
            if($order['status']==='completed') throw new Exception('Already completed.');
            if($order['status']==='cancelled') throw new Exception('Cancelled order complete nahi ho sakta.');

            $short=[];
            $stmt=$conn->prepare("SELECT wi.*, i.name item_name, i.stock_qty, i.track_inventory FROM production_work_order_inputs wi JOIN items i ON i.id=wi.item_id WHERE wi.work_order_id=? AND wi.is_stock_deduct=1 FOR UPDATE");
            $stmt->bind_param('i',$order_id); $stmt->execute(); $res=$stmt->get_result(); $inputRows=[];
            while($r=$res->fetch_assoc()) { $inputRows[]=$r; if((int)$r['track_inventory']===1 && (float)$r['stock_qty'] < (float)$r['actual_qty']) $short[]=$r['item_name'].' (Need '.number_format((float)$r['actual_qty'],4).', Stock '.number_format((float)$r['stock_qty'],4).')'; }
            $stmt->close();
            if($short) throw new Exception('Stock shortage: '.implode(', ', $short));

            foreach($inputRows as $r){
                if((int)$r['track_inventory']===1){
                    $qty=(float)$r['actual_qty'];
                    $stmt=$conn->prepare("UPDATE items SET stock_qty = stock_qty - ? WHERE id=?");
                    $stmt->bind_param('di',$qty,$r['item_id']); $stmt->execute(); $stmt->close();
                    insert_stock_movement($conn,(int)$order['client_id'],(int)$order['branch_id'],(int)$r['item_id'],$qty,'out','Production Consume',$order['work_order_no'],'Production work order input consume');
                }
            }
            $stmt=$conn->prepare("SELECT wo.*, i.track_inventory FROM production_work_order_outputs wo JOIN items i ON i.id=wo.item_id WHERE wo.work_order_id=? AND wo.is_stock_add=1 FOR UPDATE");
            $stmt->bind_param('i',$order_id); $stmt->execute(); $res=$stmt->get_result(); $outputRows=[]; while($r=$res->fetch_assoc()) $outputRows[]=$r; $stmt->close();
            foreach($outputRows as $r){
                if((int)$r['track_inventory']===1 && $r['output_type'] !== 'waste'){
                    $qty=(float)$r['actual_qty'];
                    $stmt=$conn->prepare("UPDATE items SET stock_qty = stock_qty + ? WHERE id=?");
                    $stmt->bind_param('di',$qty,$r['item_id']); $stmt->execute(); $stmt->close();
                    insert_stock_movement($conn,(int)$order['client_id'],(int)$order['branch_id'],(int)$r['item_id'],$qty,'in','Production Output',$order['work_order_no'],'Production work order output');
                }
                if($r['output_type']==='waste'){
                    $stmt=$conn->prepare("INSERT INTO production_wastage_logs (client_id,branch_id,work_order_id,item_id,qty,unit,wastage_type,reason,cost_impact) VALUES (?,?,?,?,?,?, 'non_recoverable','Production waste',0)");
                    $qty=(float)$r['actual_qty']; $unit=$r['unit']; $item=(int)$r['item_id'];
                    $stmt->bind_param('iiiids',$order['client_id'],$order['branch_id'],$order_id,$item,$qty,$unit); $stmt->execute(); $stmt->close();
                }
            }
            $costs=recalc_order_costs($conn,$order_id);
            $stmt=$conn->prepare("UPDATE production_work_orders SET status='completed', actual_start=IFNULL(actual_start,NOW()), actual_end=NOW() WHERE id=?");
            $stmt->bind_param('i',$order_id); $stmt->execute(); $stmt->close();
            // update main finished item cost per unit
            if($costs['cpu'] > 0){
                $stmt=$conn->prepare("SELECT item_id FROM production_work_order_outputs WHERE work_order_id=? AND output_type='main' ORDER BY id ASC LIMIT 1");
                $stmt->bind_param('i',$order_id); $stmt->execute(); $main=$stmt->get_result()->fetch_assoc(); $stmt->close();
                if($main){ $stmt=$conn->prepare("UPDATE items SET cost=? WHERE id=?"); $stmt->bind_param('di',$costs['cpu'],$main['item_id']); $stmt->execute(); $stmt->close(); }
            }
            $conn->commit();
            json_out(['ok'=>true,'message'=>'Work order completed. Stock updated successfully.']);
        }
    } catch(Throwable $e) {
        try { $conn->rollback(); } catch(Throwable $x) {}
        json_out(['ok'=>false,'message'=>$e->getMessage()]);
    }
}

// Page data
$formulas=[]; $items=[]; $orders=[]; $stats=['total'=>0,'draft'=>0,'process'=>0,'completed'=>0];
try {
    $res=$conn->query("SELECT id, formula_no, formula_name FROM production_formulas WHERE ".scope_where()." AND status='active' ORDER BY formula_name ASC");
    while($r=$res->fetch_assoc()) $formulas[]=$r;
    $res=$conn->query("SELECT id,name,sku,type,item_nature,unit_name,cost,stock_qty,track_inventory FROM items WHERE ".scope_where()." AND COALESCE(status,'active')='active' ORDER BY name ASC");
    while($r=$res->fetch_assoc()) $items[]=$r;
    $res=$conn->query("SELECT COUNT(*) total, SUM(status='draft') draft, SUM(status='in_process') process, SUM(status='completed') completed FROM production_work_orders WHERE ".scope_where());
    $stats=$res->fetch_assoc() ?: $stats;
    $sql="SELECT wo.*, f.formula_name, (SELECT GROUP_CONCAT(i.name SEPARATOR ', ') FROM production_work_order_outputs o LEFT JOIN items i ON i.id=o.item_id WHERE o.work_order_id=wo.id AND o.output_type='main') main_outputs FROM production_work_orders wo LEFT JOIN production_formulas f ON f.id=wo.formula_id WHERE ".scope_where('wo')." ORDER BY wo.id DESC LIMIT 300";
    $res=$conn->query($sql); while($r=$res->fetch_assoc()) $orders[]=$r;
} catch(Throwable $e) { $schemaWarnings[]='Data fetch error: '.$e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Production Work Orders - One ERP</title>
<?php if (file_exists(__DIR__.'/partials/head.php')) include(__DIR__.'/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{--bg:#050914;--card:rgba(15,23,42,.74);--muted:#94a3b8;--text:#f8fafc;--border:rgba(148,163,184,.16);--purple:#7c3aed;--blue:#3b82f6;--green:#16a34a;--red:#ef4444;--orange:#f59e0b;}
body.production-page{margin:0;background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.34),transparent 28%),radial-gradient(circle at 78% 2%,rgba(59,130,246,.16),transparent 30%),linear-gradient(135deg,#050914,#0b1024 45%,#160f2d 75%,#090d18);color:var(--text);font-family:Inter,"Segoe UI",Arial,sans-serif;overflow-x:hidden;}
.content-wrapper{padding:32px 28px;min-height:100vh;background:transparent;}
.page-head{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:24px}.page-title{font-size:32px;font-weight:950;letter-spacing:-.04em;text-shadow:0 0 28px rgba(124,58,237,.35)}
.btn-glow{border:0;border-radius:14px;min-height:48px;padding:0 20px;color:#fff;font-weight:900;background:linear-gradient(135deg,#16a34a,#10b981);box-shadow:0 18px 38px rgba(16,185,129,.22)}.btn-purple{background:linear-gradient(135deg,#6d4cff,#7c3aed)!important}.btn-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4)!important}.btn-red{background:linear-gradient(135deg,#dc2626,#ef4444)!important}.btn-amber{background:linear-gradient(135deg,#d97706,#f59e0b)!important}.btn-glow:hover{transform:translateY(-2px);color:#fff}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-bottom:22px}.stat-card{min-height:128px;border-radius:22px;padding:24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 22px 50px rgba(0,0,0,.32);overflow:hidden;position:relative}.stat-card:after{content:"";position:absolute;right:18px;top:18px;width:86px;height:86px;border-radius:22px;background:rgba(255,255,255,.13)}.stat-label{font-size:16px;font-weight:900}.stat-value{font-size:42px;font-weight:950;position:relative;z-index:1}.s1{background:linear-gradient(135deg,#2563eb,#6d4cff)}.s2{background:linear-gradient(135deg,#f59e0b,#fb923c)}.s3{background:linear-gradient(135deg,#0d9488,#16a34a)}.s4{background:linear-gradient(135deg,#be123c,#ef4444)}
.glass{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.18),transparent 35%),linear-gradient(135deg,rgba(12,18,35,.88),rgba(20,12,40,.78));border:1px solid rgba(139,92,246,.22);border-radius:20px;box-shadow:0 18px 45px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.05);backdrop-filter:blur(16px)}
.toolbar{display:flex;gap:12px;align-items:center;padding:16px;margin-bottom:18px}.searchbox{flex:1;position:relative}.searchbox i{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:#94a3b8}.searchbox input,.form-control,.form-select,textarea{background:rgba(15,23,42,.78)!important;border:1px solid rgba(148,163,184,.18)!important;color:#f8fafc!important;border-radius:14px!important;min-height:48px;font-weight:800}.searchbox input{padding-left:48px}.form-control:focus,.form-select:focus{box-shadow:0 0 0 3px rgba(124,58,237,.20)!important;border-color:rgba(124,58,237,.58)!important}.form-select option{background:#0f172a;color:#e5e7eb}.table-wrap{overflow:auto}.table{--bs-table-bg:transparent;--bs-table-color:#e5e7eb;--bs-table-border-color:rgba(148,163,184,.10);margin:0;min-width:1120px}.table thead th{background:rgba(6,10,20,.92)!important;color:#cbd5e1!important;border:0!important;padding:16px 18px;font-size:13px;text-transform:uppercase;letter-spacing:.06em}.table tbody td{background:rgba(15,23,42,.42)!important;color:#e5e7eb!important;border-color:rgba(148,163,184,.10)!important;padding:16px 18px;font-weight:800;vertical-align:middle}.table tbody tr:hover td{background:rgba(124,58,237,.12)!important}.badge-soft{border-radius:999px;padding:8px 12px;font-weight:900}.st-draft{background:rgba(148,163,184,.18);color:#cbd5e1}.st-planned{background:rgba(59,130,246,.18);color:#93c5fd}.st-in_process{background:rgba(245,158,11,.18);color:#fcd34d}.st-completed{background:rgba(34,197,94,.18);color:#86efac}.st-cancelled{background:rgba(239,68,68,.18);color:#fca5a5}.icon-btn{width:42px;height:42px;border:0;border-radius:14px;color:#fff;margin:2px;display:inline-flex;align-items:center;justify-content:center}.modal{z-index:5000}.modal-backdrop{z-index:4990}.modal-content{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.26),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.96),rgba(17,24,39,.88));color:#f8fafc;border:1px solid rgba(139,92,246,.38);border-radius:22px;box-shadow:0 28px 80px rgba(0,0,0,.64);backdrop-filter:blur(24px);overflow:hidden}.modal-header,.modal-footer{background:rgba(7,11,20,.44);border-color:rgba(148,163,184,.16)}.modal-body{max-height:72vh;overflow-y:auto}.mini-table{width:100%;border-collapse:separate;border-spacing:0 8px}.mini-table th{color:#94a3b8;font-size:12px;text-transform:uppercase}.mini-table td{padding:6px}.mini-table input,.mini-table select{min-height:40px!important}.item-name{min-width:190px}.warning-box{background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.28);border-radius:14px;padding:12px;color:#fde68a;font-weight:800}@media(max-width:991px){.content-wrapper{padding:18px}.stat-grid{grid-template-columns:repeat(2,1fr)}.page-title{font-size:24px}.toolbar{flex-wrap:wrap}.searchbox{flex-basis:100%}}@media(max-width:575px){.stat-grid{grid-template-columns:1fr}.btn-glow{width:100%}}
/* =====================================================
   PRODUCTION_WORK_ORDERS.PHP - DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html,
body.production-page{
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

/* main background */
body.production-page .container-scroll,
body.production-page .main-panel,
body.production-page .content-wrapper{
    background:
        radial-gradient(circle at 10% 5%, rgba(124,58,237,.30), transparent 30%),
        radial-gradient(circle at 80% 8%, rgba(59,130,246,.12), transparent 28%),
        linear-gradient(135deg,#050914 0%,#090f1f 42%,#160f2d 76%,#070b14 100%) !important;
    color:#f8fafc !important;
}

/* topbar overlap fix */
body.production-page .main-panel{
    position:relative !important;
    min-height:100vh !important;
}

body.production-page .content-wrapper{
    padding-top:112px !important;
    padding-left:28px !important;
    padding-right:28px !important;
    padding-bottom:34px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
    overflow:visible !important;
}

/* topbar stays fixed but content will not hide */
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

/* page heading */
body.production-page .page-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-top:0 !important;
    margin-bottom:26px !important;
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
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

body.production-page .page-head .text-secondary,
body.production-page .text-secondary,
body.production-page .text-muted{
    color:#aab4c8 !important;
    font-weight:800 !important;
    opacity:1 !important;
}

/* buttons */
body.production-page .btn-glow,
body.production-page .btn-purple,
body.production-page .btn-primary,
body.production-page button[type="submit"]{
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

body.production-page .btn-glow:hover,
body.production-page .btn-purple:hover,
body.production-page .btn-primary:hover,
body.production-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

body.production-page .btn-dark,
body.production-page .btn-secondary{
    min-height:42px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

/* stats cards same items.php */
body.production-page .stat-grid{
    display:grid !important;
    grid-template-columns:repeat(4,minmax(0,1fr)) !important;
    gap:18px !important;
    margin-top:0 !important;
    margin-bottom:22px !important;
}

body.production-page .stat-card{
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

body.production-page .stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.12), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:0;
}

body.production-page .stat-card::after{
    content:"";
    position:absolute;
    right:18px;
    top:18px;
    width:86px;
    height:86px;
    border-radius:22px;
    background:rgba(255,255,255,.13);
}

body.production-page .stat-card > *{
    position:relative !important;
    z-index:1 !important;
}

body.production-page .stat-card:hover{
    transform:translateY(-4px) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

body.production-page .stat-label{
    font-size:16px !important;
    font-weight:900 !important;
    color:#fff !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
}

body.production-page .stat-value{
    font-size:42px !important;
    font-weight:950 !important;
    color:#fff !important;
    position:relative !important;
    z-index:1 !important;
}

/* card colors */
body.production-page .s1{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
}
body.production-page .s2{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
}
body.production-page .s3{
    background:linear-gradient(135deg,#0d9488,#16a34a) !important;
}
body.production-page .s4{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
}

/* toolbar */
body.production-page .toolbar,
body.production-page .glass.toolbar{
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    padding:16px !important;
    margin-bottom:18px !important;
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

body.production-page .searchbox{
    flex:1 !important;
    position:relative !important;
}

body.production-page .searchbox i{
    position:absolute !important;
    left:18px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#94a3b8 !important;
}

body.production-page .searchbox input{
    width:100% !important;
    padding-left:48px !important;
}

/* inputs */
body.production-page .form-control,
body.production-page .form-select,
body.production-page input,
body.production-page select,
body.production-page textarea{
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

body.production-page input::placeholder,
body.production-page textarea::placeholder{
    color:#94a3b8 !important;
}

body.production-page .form-control:focus,
body.production-page .form-select:focus,
body.production-page input:focus,
body.production-page select:focus,
body.production-page textarea:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

body.production-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* table wrapper */
body.production-page .glass.table-wrap,
body.production-page .table-wrap,
body.production-page .table-responsive{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.16), transparent 35%),
        linear-gradient(135deg, rgba(12,18,35,.90), rgba(20,12,40,.82)) !important;
    border:1px solid rgba(139,92,246,.22) !important;
    border-radius:18px !important;
    overflow:auto !important;
    box-shadow:
        0 18px 45px rgba(0,0,0,.28),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(16px) saturate(140%) !important;
    -webkit-backdrop-filter:blur(16px) saturate(140%) !important;
}

/* table */
body.production-page table.table{
    width:100% !important;
    min-width:1120px !important;
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

body.production-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* table header */
body.production-page table.table thead th{
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

/* table body */
body.production-page table.table tbody td{
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

body.production-page table.table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.34)) !important;
}

/* white hover remove */
body.production-page table.table tbody tr:hover td,
body.production-page table.table-hover tbody tr:hover td,
body.production-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* empty row */
body.production-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.44), rgba(24,15,45,.28)) !important;
    padding:45px 18px !important;
}

/* status badges */
body.production-page .badge-soft{
    border-radius:999px !important;
    padding:7px 13px !important;
    font-size:12px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
}

body.production-page .st-draft{
    background:rgba(148,163,184,.16) !important;
    color:#cbd5e1 !important;
    border:1px solid rgba(148,163,184,.25) !important;
}
body.production-page .st-planned{
    background:rgba(59,130,246,.16) !important;
    color:#93c5fd !important;
    border:1px solid rgba(59,130,246,.25) !important;
}
body.production-page .st-in_process{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}
body.production-page .st-completed{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}
body.production-page .st-cancelled{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.25) !important;
}

/* action buttons */
body.production-page .icon-btn{
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

body.production-page .btn-cyan{
    background:rgba(6,182,212,.16) !important;
    color:#67e8f9 !important;
    border-color:rgba(6,182,212,.26) !important;
}

body.production-page .btn-amber{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.26) !important;
}

body.production-page .icon-btn:hover{
    transform:translateY(-2px) scale(1.04) !important;
    color:#fff !important;
}

/* modal theme + small size */
body.production-page .modal{
    z-index:5000 !important;
}

body.production-page .modal-backdrop{
    z-index:4990 !important;
}

body.production-page .modal-backdrop.show{
    opacity:.82 !important;
    background:#000 !important;
    backdrop-filter:blur(8px) !important;
    -webkit-backdrop-filter:blur(8px) !important;
}

/* Add/Edit Work Order popup size small */
body.production-page #orderModal .modal-dialog{
    max-width:900px !important;
    width:calc(100% - 34px) !important;
    margin:18px auto !important;
}

body.production-page #orderModal .modal-xl{
    max-width:900px !important;
}

body.production-page #orderModal .modal-content{
    max-height:calc(100vh - 36px) !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
}

body.production-page #orderModal .modal-content > form{
    display:flex !important;
    flex-direction:column !important;
    min-height:0 !important;
    max-height:calc(100vh - 36px) !important;
}

/* modal body scroll */
body.production-page #orderModal .modal-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    max-height:calc(100vh - 165px) !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:16px 18px 12px !important;
}

/* footer always visible */
body.production-page #orderModal .modal-footer{
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

body.production-page .modal-content{
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

body.production-page .modal-header,
body.production-page .modal-footer{
    background:rgba(7,11,20,.58) !important;
    border-color:rgba(148,163,184,.16) !important;
}

body.production-page .modal-title,
body.production-page .modal h5,
body.production-page .modal h6{
    color:#ffffff !important;
    font-weight:950 !important;
}

/* mini table inside popup */
body.production-page .mini-table{
    width:100% !important;
    min-width:900px !important;
    background:transparent !important;
}

body.production-page .mini-table th{
    background:rgba(6,10,20,.96) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(139,92,246,.24) !important;
    padding:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
}

body.production-page .mini-table td{
    background:rgba(15,23,42,.46) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:8px !important;
}

body.production-page .mini-table input,
body.production-page .mini-table select{
    min-height:40px !important;
}

/* view modal */
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

/* scrollbars */
body.production-page .table-wrap::-webkit-scrollbar,
body.production-page .modal-body::-webkit-scrollbar,
body.production-page .table-responsive::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.production-page .table-wrap::-webkit-scrollbar-track,
body.production-page .modal-body::-webkit-scrollbar-track,
body.production-page .table-responsive::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.production-page .table-wrap::-webkit-scrollbar-thumb,
body.production-page .modal-body::-webkit-scrollbar-thumb,
body.production-page .table-responsive::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* responsive */
@media(max-width:991px){
    body.production-page .content-wrapper{
        padding-top:104px !important;
        padding-left:18px !important;
        padding-right:18px !important;
    }

    body.production-page .stat-grid{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    body.production-page .page-title{
        font-size:26px !important;
    }

    body.production-page .toolbar{
        flex-wrap:wrap !important;
    }

    body.production-page .searchbox{
        flex:1 1 100% !important;
    }

    body.production-page #orderModal .modal-dialog{
        max-width:720px !important;
        width:calc(100% - 22px) !important;
        margin:12px auto !important;
    }

    body.production-page #orderModal .modal-body{
        max-height:calc(100vh - 155px) !important;
    }
}

@media(max-width:575px){
    body.production-page .content-wrapper{
        padding-top:98px !important;
        padding-left:14px !important;
        padding-right:14px !important;
    }

    body.production-page .stat-grid{
        grid-template-columns:1fr !important;
    }

    body.production-page .toolbar .btn,
    body.production-page .toolbar select{
        width:100% !important;
    }

    body.production-page table.table{
        min-width:1050px !important;
    }

    body.production-page #orderModal .modal-dialog{
        width:calc(100% - 14px) !important;
        margin:7px auto !important;
    }

    body.production-page #orderModal .modal-footer{
        flex-direction:column !important;
    }

    body.production-page #orderModal .modal-footer .btn{
        width:100% !important;
    }
}

</style>
</head>
<body class="production-page">
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar"><?php if (file_exists(__DIR__.'/partials/sidebar.php')) include(__DIR__.'/partials/sidebar.php'); ?></div>
    <div class="main-panel">
        <?php if (file_exists(__DIR__.'/partials/topbar.php')) include(__DIR__.'/partials/topbar.php'); ?>
        <div class="content-wrapper">
            <div class="page-head">
                <div><h1 class="page-title mb-1">Production Work Orders</h1><div class="text-secondary fw-bold">Formula se production order banao, stock consume/add karo, costing auto calculate karo.</div></div>
                <button class="btn btn-glow" onclick="openAddOrder()"><i class="bi bi-plus-lg me-2"></i> Add Work Order</button>
            </div>
            <?php foreach($schemaWarnings as $w): ?><div class="warning-box mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= e($w) ?></div><?php endforeach; ?>
            <div class="stat-grid">
                <div class="stat-card s1"><div><div class="stat-label"><i class="bi bi-clipboard-data me-2"></i>Total Orders</div></div><div class="stat-value"><?= (int)($stats['total']??0) ?></div></div>
                <div class="stat-card s2"><div><div class="stat-label"><i class="bi bi-hourglass-split me-2"></i>Draft</div></div><div class="stat-value"><?= (int)($stats['draft']??0) ?></div></div>
                <div class="stat-card s3"><div><div class="stat-label"><i class="bi bi-gear-wide-connected me-2"></i>In Process</div></div><div class="stat-value"><?= (int)($stats['process']??0) ?></div></div>
                <div class="stat-card s4"><div><div class="stat-label"><i class="bi bi-check2-circle me-2"></i>Completed</div></div><div class="stat-value"><?= (int)($stats['completed']??0) ?></div></div>
            </div>
            <div class="toolbar glass">
                <button class="btn btn-dark border-0 fw-bold" onclick="location.reload()"><i class="bi bi-arrow-clockwise me-2"></i> Refresh</button>
                <div class="searchbox"><i class="bi bi-search"></i><input id="searchInput" class="form-control" placeholder="Search work order, formula, output, status..."></div>
                <select id="statusFilter" class="form-select" style="max-width:210px"><option value="">All Status</option><option value="draft">Draft</option><option value="planned">Planned</option><option value="in_process">In Process</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select>
            </div>
            <div class="glass table-wrap">
                <table class="table align-middle" id="ordersTable">
                    <thead><tr><th>ID</th><th>Work Order</th><th>Date</th><th>Formula</th><th>Main Output</th><th>Status</th><th>Material</th><th>Total Cost</th><th>Cost/Unit</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if(empty($orders)): ?><tr><td colspan="10" class="text-center py-5 text-secondary">No work orders found.</td></tr><?php endif; ?>
                    <?php foreach($orders as $o): $st=$o['status'] ?: 'draft'; ?>
                        <tr data-status="<?= e($st) ?>">
                            <td><?= (int)$o['id'] ?></td>
                            <td><strong><?= e($o['work_order_no']) ?></strong><div class="small text-secondary">Ref: <?= e($o['reference_type'] ?: '-') ?> <?= e($o['reference_id'] ?: '') ?></div></td>
                            <td><?= e($o['production_date'] ?: '-') ?></td>
                            <td><?= e($o['formula_name'] ?: '-') ?></td>
                            <td><?= e($o['main_outputs'] ?: '-') ?></td>
                            <td><span class="badge-soft st-<?= e($st) ?>"><?= e(ucwords(str_replace('_',' ',$st))) ?></span></td>
                            <td>Rs <?= number_format((float)$o['material_cost'],2) ?></td>
                            <td>Rs <?= number_format((float)$o['total_cost'],2) ?></td>
                            <td>Rs <?= number_format((float)$o['cost_per_unit'],4) ?></td>
                            <td>
                                <button class="icon-btn btn-cyan" onclick="viewOrder(<?= (int)$o['id'] ?>)" title="View"><i class="bi bi-eye"></i></button>
                                <?php if($st!=='completed' && $st!=='cancelled'): ?>
                                <button class="icon-btn btn-amber" onclick="editOrder(<?= (int)$o['id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                                <button class="icon-btn btn-purple" onclick="startOrder(<?= (int)$o['id'] ?>)" title="Start"><i class="bi bi-play-fill"></i></button>
                                <button class="icon-btn btn-glow" onclick="completeOrder(<?= (int)$o['id'] ?>)" title="Complete"><i class="bi bi-check2"></i></button>
                                <button class="icon-btn btn-red" onclick="cancelOrder(<?= (int)$o['id'] ?>)" title="Cancel"><i class="bi bi-x-lg"></i></button>
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

<div class="modal fade" id="orderModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
<form id="orderForm"><input type="hidden" id="order_id" name="order_id"><input type="hidden" name="ajax" value="save_order"><input type="hidden" id="inputs_json" name="inputs_json"><input type="hidden" id="outputs_json" name="outputs_json">
<div class="modal-header"><h5 class="modal-title" id="modalTitle">Add Work Order</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="row g-3 mb-3">
        <div class="col-md-3"><label class="form-label fw-bold">Work Order No</label><input class="form-control" name="work_order_no" id="work_order_no" placeholder="Auto if blank"></div>
        <div class="col-md-3"><label class="form-label fw-bold">Production Date</label><input type="date" class="form-control" name="production_date" id="production_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-4"><label class="form-label fw-bold">Formula / BOM</label><select class="form-select" name="formula_id" id="formula_id" required><option value="">Select Formula</option><?php foreach($formulas as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e(($f['formula_no']? $f['formula_no'].' - ':'').$f['formula_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label fw-bold">Multiplier / Qty</label><input type="number" step="0.0001" min="0.0001" class="form-control" id="produce_qty" value="1"></div>
        <div class="col-md-3"><label class="form-label fw-bold">Status</label><select class="form-select" name="status" id="order_status"><option value="draft">Draft</option><option value="planned" selected>Planned</option><option value="in_process">In Process</option></select></div>
        <div class="col-md-3"><label class="form-label fw-bold">Reference Type</label><input class="form-control" name="reference_type" id="reference_type" placeholder="sale, custom job, etc"></div>
        <div class="col-md-3"><label class="form-label fw-bold">Reference ID</label><input type="number" class="form-control" name="reference_id" id="reference_id" placeholder="Optional"></div>
        <div class="col-md-3"><label class="form-label fw-bold">Actions</label><button type="button" class="btn btn-glow btn-purple w-100" onclick="loadFormulaDetails()"><i class="bi bi-arrow-repeat me-2"></i>Load Formula</button></div>
        <div class="col-12"><label class="form-label fw-bold">Notes</label><textarea class="form-control" name="notes" id="notes" rows="2" placeholder="Production notes..."></textarea></div>
    </div>
    <div class="warning-box mb-3">Actual Qty aur Unit Cost yahin edit kar sakte ho. Complete par stock auto minus/plus hoga.</div>
    <h5 class="fw-black mb-2"><i class="bi bi-box-arrow-in-down me-2"></i>Inputs / Consumption</h5>
    <div class="table-responsive mb-4"><table class="mini-table"><thead><tr><th>Item</th><th>Expected</th><th>Actual</th><th>Unit</th><th>Unit Cost</th><th>Total</th><th>Cost Type</th><th>Deduct</th></tr></thead><tbody id="inputsBody"></tbody></table></div>
    <h5 class="fw-black mb-2"><i class="bi bi-box-arrow-up me-2"></i>Outputs / Produced Items</h5>
    <div class="table-responsive"><table class="mini-table"><thead><tr><th>Item</th><th>Expected</th><th>Actual</th><th>Unit</th><th>Type</th><th>Add Stock</th></tr></thead><tbody id="outputsBody"></tbody></table></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-glow"><i class="bi bi-save me-2"></i>Save Work Order</button></div>
</form></div></div></div>

<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Work Order Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="viewBody"></div><div class="modal-footer"><button class="btn btn-dark" data-bs-dismiss="modal">Close</button></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const orderModal = new bootstrap.Modal(document.getElementById('orderModal'));
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
let inputRows = [], outputRows = [];
function esc(s){return String(s ?? '').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));}
function money(n){return Number(n||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4});}
function openAddOrder(){document.getElementById('orderForm').reset();document.getElementById('order_id').value='';document.getElementById('production_date').value='<?= date('Y-m-d') ?>';document.getElementById('order_status').value='planned';inputRows=[];outputRows=[];renderRows();document.getElementById('modalTitle').innerText='Add Work Order';orderModal.show();}
function loadFormulaDetails(){const fid=document.getElementById('formula_id').value;const qty=document.getElementById('produce_qty').value||1;if(!fid){alert('Formula select karo.');return;}fetch(`?ajax=formula_details&formula_id=${encodeURIComponent(fid)}&qty=${encodeURIComponent(qty)}`).then(r=>r.json()).then(d=>{if(!d.ok){alert(d.message);return;}inputRows=d.inputs.map(x=>({...x,item_id:Number(x.item_id),expected_qty:Number(x.expected_qty),actual_qty:Number(x.actual_qty),unit_cost:Number(x.unit_cost),total_cost:Number(x.total_cost),is_stock_deduct:Number(x.is_stock_deduct)}));outputRows=d.outputs.map(x=>({...x,item_id:Number(x.item_id),expected_qty:Number(x.expected_qty),actual_qty:Number(x.actual_qty),is_stock_add:Number(x.is_stock_add)}));renderRows();});}
function renderRows(){const ib=document.getElementById('inputsBody'), ob=document.getElementById('outputsBody');ib.innerHTML='';ob.innerHTML='';if(!inputRows.length) ib.innerHTML='<tr><td colspan="8" class="text-secondary text-center py-3">Formula load karo.</td></tr>';inputRows.forEach((r,i)=>{ib.insertAdjacentHTML('beforeend',`<tr><td class="item-name"><strong>${esc(r.item_name)}</strong><div class="small text-secondary">Stock: ${money(r.stock_qty)}</div></td><td><input class="form-control" type="number" step="0.0001" value="${r.expected_qty}" onchange="inputRows[${i}].expected_qty=Number(this.value)"></td><td><input class="form-control" type="number" step="0.0001" value="${r.actual_qty}" onchange="inputRows[${i}].actual_qty=Number(this.value);calcInput(${i})"></td><td><input class="form-control" value="${esc(r.unit||'')}" onchange="inputRows[${i}].unit=this.value"></td><td><input class="form-control" type="number" step="0.0001" value="${r.unit_cost}" onchange="inputRows[${i}].unit_cost=Number(this.value);calcInput(${i})"></td><td><input class="form-control" readonly value="${money(r.total_cost)}" id="in_total_${i}"></td><td><select class="form-select" onchange="inputRows[${i}].cost_type=this.value"><option ${r.cost_type==='material'?'selected':''} value="material">Material</option><option ${r.cost_type==='packing'?'selected':''} value="packing">Packing</option><option ${r.cost_type==='labor'?'selected':''} value="labor">Labor</option><option ${r.cost_type==='service'?'selected':''} value="service">Service</option><option ${r.cost_type==='overhead'?'selected':''} value="overhead">Overhead</option><option ${r.cost_type==='other'?'selected':''} value="other">Other</option></select></td><td><select class="form-select" onchange="inputRows[${i}].is_stock_deduct=Number(this.value)"><option value="1" ${Number(r.is_stock_deduct)===1?'selected':''}>Yes</option><option value="0" ${Number(r.is_stock_deduct)===0?'selected':''}>No</option></select></td></tr>`)});if(!outputRows.length) ob.innerHTML='<tr><td colspan="6" class="text-secondary text-center py-3">Formula load karo.</td></tr>';outputRows.forEach((r,i)=>{ob.insertAdjacentHTML('beforeend',`<tr><td class="item-name"><strong>${esc(r.item_name)}</strong><div class="small text-secondary">Stock: ${money(r.stock_qty)}</div></td><td><input class="form-control" type="number" step="0.0001" value="${r.expected_qty}" onchange="outputRows[${i}].expected_qty=Number(this.value)"></td><td><input class="form-control" type="number" step="0.0001" value="${r.actual_qty}" onchange="outputRows[${i}].actual_qty=Number(this.value)"></td><td><input class="form-control" value="${esc(r.unit||'')}" onchange="outputRows[${i}].unit=this.value"></td><td><select class="form-select" onchange="outputRows[${i}].output_type=this.value"><option ${r.output_type==='main'?'selected':''} value="main">Main</option><option ${r.output_type==='by_product'?'selected':''} value="by_product">By Product</option><option ${r.output_type==='scrap'?'selected':''} value="scrap">Scrap</option><option ${r.output_type==='waste'?'selected':''} value="waste">Waste</option></select></td><td><select class="form-select" onchange="outputRows[${i}].is_stock_add=Number(this.value)"><option value="1" ${Number(r.is_stock_add)===1?'selected':''}>Yes</option><option value="0" ${Number(r.is_stock_add)===0?'selected':''}>No</option></select></td></tr>`)});}
function calcInput(i){inputRows[i].total_cost=Number(inputRows[i].actual_qty||0)*Number(inputRows[i].unit_cost||0);let el=document.getElementById('in_total_'+i);if(el)el.value=money(inputRows[i].total_cost);}
document.getElementById('orderForm').addEventListener('submit',e=>{e.preventDefault();document.getElementById('inputs_json').value=JSON.stringify(inputRows);document.getElementById('outputs_json').value=JSON.stringify(outputRows);fetch('',{method:'POST',body:new FormData(e.target)}).then(r=>r.json()).then(d=>{alert(d.message); if(d.ok) location.reload();}).catch(()=>alert('Save request failed.'));});
function fillOrderForm(d){const o=d.order;document.getElementById('order_id').value=o.id;document.getElementById('work_order_no').value=o.work_order_no||'';document.getElementById('production_date').value=o.production_date||'<?= date('Y-m-d') ?>';document.getElementById('formula_id').value=o.formula_id||'';document.getElementById('order_status').value=o.status==='completed'?'planned':(o.status||'planned');document.getElementById('reference_type').value=o.reference_type||'';document.getElementById('reference_id').value=o.reference_id||'';document.getElementById('notes').value=o.notes||'';inputRows=d.inputs.map(x=>({...x,item_id:Number(x.item_id),expected_qty:Number(x.expected_qty),actual_qty:Number(x.actual_qty),unit_cost:Number(x.unit_cost),total_cost:Number(x.total_cost),is_stock_deduct:Number(x.is_stock_deduct)}));outputRows=d.outputs.map(x=>({...x,item_id:Number(x.item_id),expected_qty:Number(x.expected_qty),actual_qty:Number(x.actual_qty),is_stock_add:Number(x.is_stock_add)}));renderRows();}
function editOrder(id){fetch(`?ajax=get_order&id=${id}`).then(r=>r.json()).then(d=>{if(!d.ok){alert(d.message);return;}fillOrderForm(d);document.getElementById('modalTitle').innerText='Edit Work Order';orderModal.show();});}
function viewOrder(id){fetch(`?ajax=get_order&id=${id}`).then(r=>r.json()).then(d=>{if(!d.ok){alert(d.message);return;}const o=d.order;let html=`<div class="row g-3 mb-3"><div class="col-md-3"><div class="glass p-3"><div class="text-secondary fw-bold">Order No</div><h5>${esc(o.work_order_no)}</h5></div></div><div class="col-md-3"><div class="glass p-3"><div class="text-secondary fw-bold">Status</div><h5>${esc(o.status)}</h5></div></div><div class="col-md-3"><div class="glass p-3"><div class="text-secondary fw-bold">Total Cost</div><h5>Rs ${money(o.total_cost)}</h5></div></div><div class="col-md-3"><div class="glass p-3"><div class="text-secondary fw-bold">Cost / Unit</div><h5>Rs ${money(o.cost_per_unit)}</h5></div></div></div>`;html+=`<h5>Inputs</h5><div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Expected</th><th>Actual</th><th>Cost</th><th>Total</th><th>Deduct</th></tr></thead><tbody>`;d.inputs.forEach(r=>html+=`<tr><td>${esc(r.item_name)}</td><td>${money(r.expected_qty)} ${esc(r.unit||'')}</td><td>${money(r.actual_qty)}</td><td>Rs ${money(r.unit_cost)}</td><td>Rs ${money(r.total_cost)}</td><td>${Number(r.is_stock_deduct)?'Yes':'No'}</td></tr>`);html+=`</tbody></table></div><h5 class="mt-4">Outputs</h5><div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Expected</th><th>Actual</th><th>Type</th><th>Add Stock</th></tr></thead><tbody>`;d.outputs.forEach(r=>html+=`<tr><td>${esc(r.item_name)}</td><td>${money(r.expected_qty)} ${esc(r.unit||'')}</td><td>${money(r.actual_qty)}</td><td>${esc(r.output_type)}</td><td>${Number(r.is_stock_add)?'Yes':'No'}</td></tr>`);html+=`</tbody></table></div>`;document.getElementById('viewBody').innerHTML=html;viewModal.show();});}
function postStatus(id,status,msg){if(!confirm(msg))return;let fd=new FormData();fd.append('ajax','update_status');fd.append('id',id);fd.append('status',status);fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message); if(d.ok)location.reload();});}
function startOrder(id){postStatus(id,'in_process','Start this work order?');}
function cancelOrder(id){postStatus(id,'cancelled','Cancel this work order?');}
function completeOrder(id){if(!confirm('Complete this work order? Stock input minus aur output plus ho jayega.'))return;let fd=new FormData();fd.append('ajax','complete_order');fd.append('id',id);fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message); if(d.ok)location.reload();});}
document.getElementById('searchInput').addEventListener('input',filterRows);document.getElementById('statusFilter').addEventListener('change',filterRows);function filterRows(){const q=document.getElementById('searchInput').value.toLowerCase();const st=document.getElementById('statusFilter').value;document.querySelectorAll('#ordersTable tbody tr').forEach(tr=>{const okQ=tr.innerText.toLowerCase().includes(q);const okS=!st||tr.dataset.status===st;tr.style.display=(okQ&&okS)?'':'none';});}
</script>
</body></html>
