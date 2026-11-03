<?php
session_start();
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
if (!isset($conn) || !($conn instanceof mysqli)) { if (isset($mysqli) && $mysqli instanceof mysqli) $conn=$mysqli; elseif(isset($db)&&$db instanceof mysqli)$conn=$db; }
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); $conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) { header('Location: /public/app/views/login_view.php'); exit; }
$user_id=(int)($_SESSION['user_id']??0); $role_id=(int)($_SESSION['role_id']??0); $client_id=(int)($_SESSION['client_id']??0); $branch_id=(int)($_SESSION['branch_id']??0); $is_superadmin=($role_id===1); if(!$is_superadmin && $client_id<=0) die('Client not found in session.');
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function json_out($a){header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit;} function qnum($v){return is_numeric($v)?(float)$v:0.0;} function qty_fmt($v){return rtrim(rtrim(number_format((float)$v,4,'.',''),'0'),'.');}
function table_exists(mysqli $c,string $t):bool{$s=$c->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");$s->bind_param('s',$t);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return (int)($r['c']??0)>0;}
function column_exists(mysqli $c,string $t,string $col):bool{$s=$c->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->bind_param('ss',$t,$col);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return (int)($r['c']??0)>0;}
function ensure_costing_schema(mysqli $c):array{$w=[];try{ if(!table_exists($c,'production_work_orders'))$c->query("CREATE TABLE IF NOT EXISTS production_work_orders (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, branch_id INT NULL, work_order_no VARCHAR(100) NOT NULL, formula_id INT NULL, production_date DATE NULL, status ENUM('draft','planned','in_process','completed','cancelled') DEFAULT 'draft', material_cost DECIMAL(15,2) DEFAULT 0, labor_cost DECIMAL(15,2) DEFAULT 0, overhead_cost DECIMAL(15,2) DEFAULT 0, wastage_cost DECIMAL(15,2) DEFAULT 0, other_cost DECIMAL(15,2) DEFAULT 0, total_cost DECIMAL(15,2) DEFAULT 0, cost_per_unit DECIMAL(15,4) DEFAULT 0, notes TEXT NULL, created_by INT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); if(!table_exists($c,'production_work_order_inputs'))$c->query("CREATE TABLE IF NOT EXISTS production_work_order_inputs (id INT AUTO_INCREMENT PRIMARY KEY, work_order_id INT NOT NULL, item_id INT NOT NULL, expected_qty DECIMAL(15,4) DEFAULT 0, actual_qty DECIMAL(15,4) DEFAULT 0, unit VARCHAR(50), unit_cost DECIMAL(15,4) DEFAULT 0, total_cost DECIMAL(15,2) DEFAULT 0, cost_type ENUM('material','labor','service','overhead','packing','other') DEFAULT 'material', is_stock_deduct TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); if(!table_exists($c,'production_work_order_outputs'))$c->query("CREATE TABLE IF NOT EXISTS production_work_order_outputs (id INT AUTO_INCREMENT PRIMARY KEY, work_order_id INT NOT NULL, item_id INT NOT NULL, expected_qty DECIMAL(15,4) DEFAULT 0, actual_qty DECIMAL(15,4) DEFAULT 0, unit VARCHAR(50), output_type ENUM('main','by_product','scrap','waste') DEFAULT 'main', is_stock_add TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); if(!table_exists($c,'production_wastage_logs'))$c->query("CREATE TABLE IF NOT EXISTS production_wastage_logs (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, branch_id INT NULL, work_order_id INT NOT NULL, item_id INT NULL, qty DECIMAL(15,4) DEFAULT 0, unit VARCHAR(50), wastage_type ENUM('recoverable','non_recoverable') DEFAULT 'non_recoverable', reason VARCHAR(255), cost_impact DECIMAL(15,2) DEFAULT 0, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Throwable $e){$w[]='Schema warning: '.$e->getMessage();}return $w;}
$schemaWarnings=ensure_costing_schema($conn);
function scope_where(string $a=''):string{global $is_superadmin,$client_id;$p=$a?$a.'.':'';return $is_superadmin?'1=1':$p.'client_id='.(int)$client_id;}
function recalc_order_costs(mysqli $c,int $order_id):array{ $costs=['material'=>0,'packing'=>0,'labor'=>0,'service'=>0,'overhead'=>0,'other'=>0]; $s=$c->prepare("SELECT cost_type,SUM(total_cost) total FROM production_work_order_inputs WHERE work_order_id=? GROUP BY cost_type");$s->bind_param('i',$order_id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc()){$costs[$x['cost_type']] = (float)$x['total'];}$s->close(); $material=$costs['material']+$costs['packing']; $labor=$costs['labor']+$costs['service']; $overhead=$costs['overhead']; $other=$costs['other']; $waste1=0; if(table_exists($c,'items')){$s=$c->prepare("SELECT SUM(o.actual_qty*IFNULL(i.cost,0)) total FROM production_work_order_outputs o LEFT JOIN items i ON i.id=o.item_id WHERE o.work_order_id=? AND o.output_type='waste'");$s->bind_param('i',$order_id);$s->execute();$waste1=(float)($s->get_result()->fetch_assoc()['total']??0);$s->close();} $waste2=0; if(table_exists($c,'production_wastage_logs')){$s=$c->prepare("SELECT SUM(cost_impact) total FROM production_wastage_logs WHERE work_order_id=?");$s->bind_param('i',$order_id);$s->execute();$waste2=(float)($s->get_result()->fetch_assoc()['total']??0);$s->close();} $waste=$waste1+$waste2; $mainQty=0;$s=$c->prepare("SELECT SUM(actual_qty) qty FROM production_work_order_outputs WHERE work_order_id=? AND output_type='main'");$s->bind_param('i',$order_id);$s->execute();$mainQty=(float)($s->get_result()->fetch_assoc()['qty']??0);$s->close(); $total=$material+$labor+$overhead+$other+$waste; $cpu=$mainQty>0?$total/$mainQty:0; $s=$c->prepare("UPDATE production_work_orders SET material_cost=?,labor_cost=?,overhead_cost=?,wastage_cost=?,other_cost=?,total_cost=?,cost_per_unit=? WHERE id=?");$s->bind_param('dddddddi',$material,$labor,$overhead,$waste,$other,$total,$cpu,$order_id);$s->execute();$s->close(); return ['material'=>$material,'labor'=>$labor,'overhead'=>$overhead,'waste'=>$waste,'other'=>$other,'total'=>$total,'cpu'=>$cpu,'main_qty'=>$mainQty]; }
if(isset($_GET['ajax'])){try{ if($_GET['ajax']==='get_costing'){ $id=(int)($_GET['id']??0); $s=$conn->prepare("SELECT wo.*,f.formula_name,f.formula_no FROM production_work_orders wo LEFT JOIN production_formulas f ON f.id=wo.formula_id WHERE wo.id=? AND ".scope_where('wo'));$s->bind_param('i',$id);$s->execute();$order=$s->get_result()->fetch_assoc();$s->close(); if(!$order)json_out(['ok'=>false,'message'=>'Order not found.']); $inputs=[];$s=$conn->prepare("SELECT wi.*,i.name item_name,i.sku FROM production_work_order_inputs wi LEFT JOIN items i ON i.id=wi.item_id WHERE wi.work_order_id=? ORDER BY wi.id ASC");$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$inputs[]=$x;$s->close(); $outputs=[];$s=$conn->prepare("SELECT wo.*,i.name item_name FROM production_work_order_outputs wo LEFT JOIN items i ON i.id=wo.item_id WHERE wo.work_order_id=? ORDER BY wo.id ASC");$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$outputs[]=$x;$s->close(); $wastage=[];$s=$conn->prepare("SELECT wl.*,i.name item_name FROM production_wastage_logs wl LEFT JOIN items i ON i.id=wl.item_id WHERE wl.work_order_id=? ORDER BY wl.id DESC");$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$wastage[]=$x;$s->close(); json_out(['ok'=>true,'order'=>$order,'inputs'=>$inputs,'outputs'=>$outputs,'wastage'=>$wastage]); } }catch(Throwable $e){json_out(['ok'=>false,'message'=>$e->getMessage()]);}}
if(isset($_POST['ajax'])){try{ if($_POST['ajax']==='save_costing'){ $id=(int)($_POST['order_id']??0); if($id<=0)json_out(['ok'=>false,'message'=>'Invalid order.']); $s=$conn->prepare("SELECT id FROM production_work_orders WHERE id=? AND ".scope_where());$s->bind_param('i',$id);$s->execute();$ok=$s->get_result()->fetch_assoc();$s->close(); if(!$ok)json_out(['ok'=>false,'message'=>'Order not found.']); $rows=json_decode($_POST['inputs_json']??'[]',true); if(!is_array($rows))$rows=[]; $conn->begin_transaction(); $up=$conn->prepare("UPDATE production_work_order_inputs SET actual_qty=?, unit_cost=?, total_cost=?, cost_type=? WHERE id=? AND work_order_id=?"); foreach($rows as $r){$rid=(int)($r['id']??0);$actual=qnum($r['actual_qty']??0);$uc=qnum($r['unit_cost']??0);$total=$actual*$uc;$ct=in_array(($r['cost_type']??'material'),['material','packing','labor','service','overhead','other'],true)?$r['cost_type']:'material';$up->bind_param('dddsii',$actual,$uc,$total,$ct,$rid,$id);$up->execute();} $up->close(); $costs=recalc_order_costs($conn,$id); $conn->commit(); json_out(['ok'=>true,'message'=>'Costing updated successfully.','costs'=>$costs]); }
 if($_POST['ajax']==='recalc_one'){ $id=(int)($_POST['id']??0); $s=$conn->prepare("SELECT id FROM production_work_orders WHERE id=? AND ".scope_where());$s->bind_param('i',$id);$s->execute();$ok=$s->get_result()->fetch_assoc();$s->close(); if(!$ok)json_out(['ok'=>false,'message'=>'Order not found.']); $costs=recalc_order_costs($conn,$id); json_out(['ok'=>true,'message'=>'Cost recalculated.','costs'=>$costs]); }
 if($_POST['ajax']==='recalc_all'){ $res=$conn->query("SELECT id FROM production_work_orders WHERE ".scope_where());$n=0; while($r=$res->fetch_assoc()){recalc_order_costs($conn,(int)$r['id']);$n++;} json_out(['ok'=>true,'message'=>$n.' work orders recalculated.']); }
}catch(Throwable $e){try{$conn->rollback();}catch(Throwable $x){} json_out(['ok'=>false,'message'=>$e->getMessage()]);}}
$orders=[];$stats=['orders'=>0,'material'=>0,'labor'=>0,'overhead'=>0,'wastage'=>0,'other'=>0,'total'=>0,'avg_cpu'=>0];try{ $res=$conn->query("SELECT COUNT(*) orders, SUM(material_cost) material, SUM(labor_cost) labor, SUM(overhead_cost) overhead, SUM(wastage_cost) wastage, SUM(other_cost) other, SUM(total_cost) total, AVG(NULLIF(cost_per_unit,0)) avg_cpu FROM production_work_orders WHERE ".scope_where()." AND status<>'cancelled'");$stats=array_merge($stats,$res->fetch_assoc()?:[]); $sql="SELECT wo.*,f.formula_name,(SELECT GROUP_CONCAT(i.name SEPARATOR ', ') FROM production_work_order_outputs o LEFT JOIN items i ON i.id=o.item_id WHERE o.work_order_id=wo.id AND o.output_type='main') main_outputs,(SELECT SUM(actual_qty) FROM production_work_order_outputs o WHERE o.work_order_id=wo.id AND o.output_type='main') main_qty FROM production_work_orders wo LEFT JOIN production_formulas f ON f.id=wo.formula_id WHERE ".scope_where('wo')." ORDER BY wo.id DESC LIMIT 500"; $res=$conn->query($sql); while($r=$res->fetch_assoc())$orders[]=$r;}catch(Throwable $e){$schemaWarnings[]='Data fetch warning: '.$e->getMessage();}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Production Costing - One ERP</title><?php if(file_exists(__DIR__.'/partials/head.php'))include(__DIR__.'/partials/head.php'); ?><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"><style>
:root{--bg:#050914;--text:#f8fafc;--muted:#94a3b8;--border:rgba(148,163,184,.16);--purple:#7c3aed}body.production-page{margin:0;background:radial-gradient(circle at 12% 8%,rgba(124,58,237,.34),transparent 28%),radial-gradient(circle at 78% 2%,rgba(59,130,246,.16),transparent 30%),linear-gradient(135deg,#050914,#0b1024 45%,#160f2d 75%,#090d18);color:var(--text);font-family:Inter,"Segoe UI",Arial,sans-serif;overflow-x:hidden}.content-wrapper{padding:32px 28px;min-height:100vh}.page-head{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:24px}.page-title{font-size:32px;font-weight:950;letter-spacing:-.04em;text-shadow:0 0 28px rgba(124,58,237,.35)}.btn-glow{border:0;border-radius:14px;min-height:48px;padding:0 20px;color:#fff;font-weight:900;background:linear-gradient(135deg,#16a34a,#10b981);box-shadow:0 18px 38px rgba(16,185,129,.22)}.btn-purple{background:linear-gradient(135deg,#6d4cff,#7c3aed)!important}.btn-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4)!important}.btn-red{background:linear-gradient(135deg,#dc2626,#ef4444)!important}.btn-amber{background:linear-gradient(135deg,#d97706,#f59e0b)!important}.btn-glow:hover{transform:translateY(-2px);color:#fff}.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-bottom:22px}.stat-card{min-height:126px;border-radius:22px;padding:24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 22px 50px rgba(0,0,0,.32);overflow:hidden;position:relative}.stat-card:after{content:"";position:absolute;right:18px;top:18px;width:86px;height:86px;border-radius:22px;background:rgba(255,255,255,.13)}.stat-label{font-size:16px;font-weight:900}.stat-value{font-size:32px;font-weight:950;position:relative;z-index:1}.s1{background:linear-gradient(135deg,#2563eb,#6d4cff)}.s2{background:linear-gradient(135deg,#f59e0b,#fb923c)}.s3{background:linear-gradient(135deg,#0d9488,#16a34a)}.s4{background:linear-gradient(135deg,#be123c,#ef4444)}.glass{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.18),transparent 35%),linear-gradient(135deg,rgba(12,18,35,.88),rgba(20,12,40,.78));border:1px solid rgba(139,92,246,.22);border-radius:20px;box-shadow:0 18px 45px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.05);backdrop-filter:blur(16px)}.toolbar{display:flex;gap:12px;align-items:center;padding:16px;margin-bottom:18px}.searchbox{flex:1;position:relative}.searchbox i{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:#94a3b8}.searchbox input,.form-control,.form-select,textarea{background:rgba(15,23,42,.78)!important;border:1px solid rgba(148,163,184,.18)!important;color:#f8fafc!important;border-radius:14px!important;min-height:48px;font-weight:800}.searchbox input{padding-left:48px}.form-control:focus,.form-select:focus{box-shadow:0 0 0 3px rgba(124,58,237,.20)!important;border-color:rgba(124,58,237,.58)!important}.form-select option{background:#0f172a;color:#e5e7eb}.table-wrap{overflow:auto}.table{--bs-table-bg:transparent;--bs-table-color:#e5e7eb;--bs-table-border-color:rgba(148,163,184,.10);margin:0;min-width:1180px}.table thead th{background:rgba(6,10,20,.92)!important;color:#cbd5e1!important;border:0!important;padding:16px 18px;font-size:13px;text-transform:uppercase;letter-spacing:.06em}.table tbody td{background:rgba(15,23,42,.42)!important;color:#e5e7eb!important;border-color:rgba(148,163,184,.10)!important;padding:16px 18px;font-weight:800;vertical-align:middle}.table tbody tr:hover td{background:rgba(124,58,237,.12)!important}.badge-soft{border-radius:999px;padding:8px 12px;font-weight:900}.st-draft{background:rgba(148,163,184,.18);color:#cbd5e1}.st-planned{background:rgba(59,130,246,.18);color:#93c5fd}.st-in_process{background:rgba(245,158,11,.18);color:#fcd34d}.st-completed{background:rgba(34,197,94,.18);color:#86efac}.st-cancelled{background:rgba(239,68,68,.18);color:#fca5a5}.icon-btn{width:42px;height:42px;border:0;border-radius:14px;color:#fff;margin:2px;display:inline-flex;align-items:center;justify-content:center}.modal{z-index:5000}.modal-backdrop{z-index:4990}.modal-content{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.26),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.96),rgba(17,24,39,.88));color:#f8fafc;border:1px solid rgba(139,92,246,.38);border-radius:22px;box-shadow:0 28px 80px rgba(0,0,0,.64);backdrop-filter:blur(24px);overflow:hidden}.modal-header,.modal-footer{background:rgba(7,11,20,.44);border-color:rgba(148,163,184,.16)}.modal-body{max-height:72vh;overflow:auto}.mini-table{width:100%;min-width:900px}.mini-table th{color:#94a3b8;font-size:12px;text-transform:uppercase}.mini-table td{padding:6px}.mini-table input,.mini-table select{min-height:40px!important}.warning-box{background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.28);border-radius:14px;padding:12px;color:#fde68a;font-weight:800}@media(max-width:991px){.content-wrapper{padding:18px}.stat-grid{grid-template-columns:repeat(2,1fr)}.page-title{font-size:24px}.toolbar{flex-wrap:wrap}.searchbox{flex-basis:100%}}@media(max-width:575px){.stat-grid{grid-template-columns:1fr}.btn-glow{width:100%}}
/* =====================================================
   PRODUCTION_COSTING.PHP - ITEMS.PHP STYLE DARK THEME
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

/* Main background same as items.php */
body.production-page .container-scroll,
body.production-page .main-panel,
body.production-page .content-wrapper{
    background:
        radial-gradient(circle at 10% 5%, rgba(124,58,237,.30), transparent 30%),
        radial-gradient(circle at 80% 8%, rgba(59,130,246,.12), transparent 28%),
        linear-gradient(135deg,#050914 0%,#090f1f 42%,#160f2d 76%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.production-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
    overflow:visible !important;
}

/* remove white/light areas */
body.production-page .content-wrapper > p,
body.production-page .content-wrapper > .text-secondary,
body.production-page .small,
body.production-page .text-muted,
body.production-page .text-secondary{
    color:#aab4c8 !important;
}

/* Page title */
body.production-page .page-title,
body.production-page h1,
body.production-page h2,
body.production-page h3,
body.production-page h4{
    color:#ffffff !important;
    font-weight:950 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

/* Header row */
body.production-page .page-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-bottom:22px !important;
}

/* Purple button like items page */
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

/* Header secondary buttons */
body.production-page .btn-dark,
body.production-page .btn-secondary{
    min-height:42px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

/* Stats cards same items.php style */
body.production-page .stat-grid{
    display:grid !important;
    grid-template-columns:repeat(4, minmax(0, 1fr)) !important;
    gap:16px !important;
    margin-bottom:22px !important;
}

body.production-page .stat-card{
    min-height:112px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:
        0 18px 42px rgba(0,0,0,.28),
        inset 0 1px 0 rgba(255,255,255,.08) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
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
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:22px;
    background:rgba(255,255,255,.12);
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
    font-size:15px !important;
    font-weight:900 !important;
    color:#fff !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
}

body.production-page .stat-value{
    font-size:30px !important;
    line-height:1.15 !important;
    font-weight:950 !important;
    color:#fff !important;
    text-align:right !important;
}

/* same card colors */
body.production-page .s1{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
}
body.production-page .s2{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
}
body.production-page .s3{
    background:linear-gradient(135deg,#047857,#16a34a) !important;
}
body.production-page .s4{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
}

/* Toolbar same as items.php */
body.production-page .toolbar,
body.production-page .glass.toolbar{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.16), transparent 35%),
        linear-gradient(135deg, rgba(15,23,42,.78), rgba(24,15,45,.52)) !important;
    border:1px solid rgba(139,92,246,.22) !important;
    border-radius:18px !important;
    padding:14px !important;
    margin-bottom:18px !important;
    box-shadow:
        0 18px 45px rgba(0,0,0,.25),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(16px) saturate(140%) !important;
    -webkit-backdrop-filter:blur(16px) saturate(140%) !important;
}

body.production-page .toolbar{
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
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

/* Inputs/selects */
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

/* Refresh button */
body.production-page .toolbar .btn-dark{
    background:rgba(7,11,20,.82) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    color:#fff !important;
    border-radius:12px !important;
}

/* Table card same items.php */
body.production-page .glass.table-wrap,
body.production-page .table-wrap{
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

/* Table */
body.production-page table.table{
    width:100% !important;
    min-width:1180px !important;
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

/* Bootstrap white remove */
body.production-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

/* Table header like items.php */
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

/* Table rows */
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

/* No white hover */
body.production-page table.table tbody tr:hover td,
body.production-page table.table-hover tbody tr:hover td,
body.production-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Empty record row */
body.production-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.44), rgba(24,15,45,.28)) !important;
    padding:45px 18px !important;
}

/* Status badges */
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

/* Action buttons */
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

body.production-page .btn-purple{
    background:rgba(124,58,237,.18) !important;
    color:#c4b5fd !important;
    border-color:rgba(124,58,237,.30) !important;
}

body.production-page .icon-btn:hover{
    transform:translateY(-2px) scale(1.04) !important;
    color:#fff !important;
}

/* Modal same glass */
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

body.production-page .modal-body{
    background:rgba(15,23,42,.12) !important;
}

/* Mini table inside popup */
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

/* Scrollbars */
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

/* Responsive */
@media(max-width:991px){
    body.production-page .content-wrapper{
        padding:18px !important;
    }

    body.production-page .stat-grid{
        grid-template-columns:repeat(2, minmax(0,1fr)) !important;
    }

    body.production-page .toolbar{
        flex-wrap:wrap !important;
    }

    body.production-page .searchbox{
        flex:1 1 100% !important;
    }
}

@media(max-width:575px){
    body.production-page .stat-grid{
        grid-template-columns:1fr !important;
    }

    body.production-page .toolbar .btn,
    body.production-page .toolbar select{
        width:100% !important;
    }

    body.production-page table.table{
        min-width:1100px !important;
    }
}

/* =====================================================
   PRODUCTION_COSTING.PHP - TOPBAR OVERLAP FIX
   Paste at END before 
===================================================== */

/* fixed topbar ke neeche content ko push karo */
body.production-page .main-panel{
    position:relative !important;
    min-height:100vh !important;
}

/* yahi main fix hai */
body.production-page .content-wrapper{
    padding-top:110px !important;
    padding-left:28px !important;
    padding-right:28px !important;
    padding-bottom:32px !important;
    min-height:calc(100vh - 70px) !important;
}

/* heading row ko clear rakho */
body.production-page .page-head{
    margin-top:0 !important;
    margin-bottom:26px !important;
    position:relative !important;
    z-index:2 !important;
}

/* title visible */
body.production-page .page-title{
    display:block !important;
    color:#ffffff !important;
    font-size:32px !important;
    font-weight:950 !important;
    line-height:1.15 !important;
    margin:0 0 6px 0 !important;
}

/* subtitle visible */
body.production-page .page-head .text-secondary{
    color:#aab4c8 !important;
    font-weight:800 !important;
    opacity:1 !important;
}

/* recalculate button ko topbar ke neeche rakho */
body.production-page .page-head .btn,
body.production-page .page-head button{
    position:relative !important;
    z-index:3 !important;
}

/* topbar ko content se above rakhna hai, lekin content hide nahi karega */
body.production-page .navbar,
body.production-page .navbar.fixed-top,
body.production-page .my-navbar,
body.production-page .topbar{
    z-index:1040 !important;
}

/* agar topbar fixed hai to content ke upar shadow rahe */
body.production-page .navbar.my-navbar.fixed-top{
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* cards ko heading ke neeche proper gap */
body.production-page .stat-grid{
    margin-top:0 !important;
}

/* mobile fix */
@media(max-width:991px){
    body.production-page .content-wrapper{
        padding-top:100px !important;
        padding-left:18px !important;
        padding-right:18px !important;
    }

    body.production-page .page-head{
        gap:12px !important;
    }

    body.production-page .page-title{
        font-size:26px !important;
    }
}

@media(max-width:575px){
    body.production-page .content-wrapper{
        padding-top:96px !important;
        padding-left:14px !important;
        padding-right:14px !important;
    }

    body.production-page .page-head .btn,
    body.production-page .page-head button{
        width:100% !important;
    }
}
</style>
</head><body class="production-page"><div class="container-scroll"><div class="sidebar" id="dashboardSidebar"><?php if(file_exists(__DIR__.'/partials/sidebar.php'))include(__DIR__.'/partials/sidebar.php'); ?></div><div class="main-panel"><?php if(file_exists(__DIR__.'/partials/topbar.php'))include(__DIR__.'/partials/topbar.php'); ?><div class="content-wrapper">
<div class="page-head"><div><h1 class="page-title mb-1">Production Costing</h1><div class="text-secondary fw-bold">Production cost analyze karo, input unit cost update karo, aur cost per unit recalculate karo.</div></div><button class="btn btn-glow btn-purple" onclick="recalcAll()"><i class="bi bi-calculator me-2"></i> Recalculate All</button></div>
<?php foreach($schemaWarnings as $w): ?><div class="warning-box mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?=e($w)?></div><?php endforeach; ?>
<div class="stat-grid"><div class="stat-card s1"><div><div class="stat-label"><i class="bi bi-cash-stack me-2"></i>Total Cost</div></div><div class="stat-value">Rs <?=number_format((float)($stats['total']??0),2)?></div></div><div class="stat-card s2"><div><div class="stat-label"><i class="bi bi-box-seam me-2"></i>Material Cost</div></div><div class="stat-value">Rs <?=number_format((float)($stats['material']??0),2)?></div></div><div class="stat-card s3"><div><div class="stat-label"><i class="bi bi-person-gear me-2"></i>Labor + Overhead</div></div><div class="stat-value">Rs <?=number_format((float)($stats['labor']??0)+(float)($stats['overhead']??0),2)?></div></div><div class="stat-card s4"><div><div class="stat-label"><i class="bi bi-graph-up-arrow me-2"></i>Avg Cost / Unit</div></div><div class="stat-value">Rs <?=number_format((float)($stats['avg_cpu']??0),4)?></div></div></div>
<div class="toolbar glass"><button class="btn btn-dark border-0 fw-bold" onclick="location.reload()"><i class="bi bi-arrow-clockwise me-2"></i>Refresh</button><div class="searchbox"><i class="bi bi-search"></i><input id="searchInput" class="form-control" placeholder="Search work order, formula, output, status..."></div><select id="statusFilter" class="form-select" style="max-width:210px"><option value="">All Status</option><option value="draft">Draft</option><option value="planned">Planned</option><option value="in_process">In Process</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
<div class="glass table-wrap"><table class="table align-middle" id="costTable"><thead><tr><th>ID</th><th>Work Order</th><th>Date</th><th>Formula</th><th>Main Output</th><th>Status</th><th>Material</th><th>Labor</th><th>Overhead</th><th>Wastage</th><th>Total</th><th>Cost/Unit</th><th>Actions</th></tr></thead><tbody><?php if(empty($orders)): ?><tr><td colspan="13" class="text-center py-5 text-secondary">No costing records found.</td></tr><?php endif; ?><?php foreach($orders as $o):$st=$o['status']?:'draft';?><tr data-status="<?=e($st)?>"><td><?=(int)$o['id']?></td><td><strong><?=e($o['work_order_no'])?></strong></td><td><?=e($o['production_date']?:'-')?></td><td><?=e($o['formula_name']?:'-')?></td><td><?=e($o['main_outputs']?:'-')?><div class="small text-secondary">Qty: <?=qty_fmt($o['main_qty']??0)?></div></td><td><span class="badge-soft st-<?=e($st)?>"><?=e(ucwords(str_replace('_',' ',$st)))?></span></td><td>Rs <?=number_format((float)$o['material_cost'],2)?></td><td>Rs <?=number_format((float)$o['labor_cost'],2)?></td><td>Rs <?=number_format((float)$o['overhead_cost'],2)?></td><td>Rs <?=number_format((float)$o['wastage_cost'],2)?></td><td><strong>Rs <?=number_format((float)$o['total_cost'],2)?></strong></td><td>Rs <?=number_format((float)$o['cost_per_unit'],4)?></td><td><button class="icon-btn btn-cyan" onclick="viewCost(<?=(int)$o['id']?>)" title="View"><i class="bi bi-eye"></i></button><button class="icon-btn btn-amber" onclick="editCost(<?=(int)$o['id']?>)" title="Edit Cost"><i class="bi bi-pencil"></i></button><button class="icon-btn btn-purple" onclick="recalcOne(<?=(int)$o['id']?>)" title="Recalculate"><i class="bi bi-calculator"></i></button></td></tr><?php endforeach;?></tbody></table></div>
</div></div></div>
<div class="modal fade" id="costModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><form id="costForm"><input type="hidden" name="ajax" value="save_costing"><input type="hidden" name="order_id" id="order_id"><input type="hidden" name="inputs_json" id="inputs_json"><div class="modal-header"><h5 class="modal-title" id="modalTitle">Edit Production Costing</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div id="summaryCards" class="row g-3 mb-3"></div><h5 class="fw-black mb-2">Input Cost Lines</h5><div class="table-responsive"><table class="mini-table"><thead><tr><th>Item</th><th>Actual Qty</th><th>Unit</th><th>Unit Cost</th><th>Total Cost</th><th>Cost Type</th></tr></thead><tbody id="inputsBody"></tbody></table></div><h5 class="fw-black mt-4 mb-2">Outputs / Wastage</h5><div id="outputsBox"></div></div><div class="modal-footer"><button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-glow btn-purple">Save Costing</button></div></form></div></div></div>
<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Costing Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="viewBody"></div><div class="modal-footer"><button class="btn btn-dark" data-bs-dismiss="modal">Close</button></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script><script>
const costModal=new bootstrap.Modal(document.getElementById('costModal'));const viewModal=new bootstrap.Modal(document.getElementById('viewModal'));let inputRows=[];function esc(s){return String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));}function money(n,d=2){return Number(n||0).toLocaleString(undefined,{minimumFractionDigits:d,maximumFractionDigits:4});}function badge(st){return `<span class="badge-soft st-${esc(st)}">${esc(String(st).replace('_',' '))}</span>`;}
function renderInputs(){let b=document.getElementById('inputsBody');b.innerHTML='';if(!inputRows.length){b.innerHTML='<tr><td colspan="6" class="text-center text-secondary py-3">No input cost lines.</td></tr>';return;}inputRows.forEach((r,i)=>{b.insertAdjacentHTML('beforeend',`<tr><td><strong>${esc(r.item_name||'-')}</strong><div class="small text-secondary">SKU: ${esc(r.sku||'-')}</div></td><td><input class="form-control" type="number" step="0.0001" value="${r.actual_qty}" onchange="inputRows[${i}].actual_qty=Number(this.value);calcLine(${i})"></td><td><input class="form-control" readonly value="${esc(r.unit||'')}"></td><td><input class="form-control" type="number" step="0.0001" value="${r.unit_cost}" onchange="inputRows[${i}].unit_cost=Number(this.value);calcLine(${i})"></td><td><input class="form-control" readonly id="line_total_${i}" value="Rs ${money(r.total_cost)}"></td><td><select class="form-select" onchange="inputRows[${i}].cost_type=this.value"><option value="material" ${r.cost_type==='material'?'selected':''}>Material</option><option value="packing" ${r.cost_type==='packing'?'selected':''}>Packing</option><option value="labor" ${r.cost_type==='labor'?'selected':''}>Labor</option><option value="service" ${r.cost_type==='service'?'selected':''}>Service</option><option value="overhead" ${r.cost_type==='overhead'?'selected':''}>Overhead</option><option value="other" ${r.cost_type==='other'?'selected':''}>Other</option></select></td></tr>`)});}function calcLine(i){inputRows[i].total_cost=Number(inputRows[i].actual_qty||0)*Number(inputRows[i].unit_cost||0);let el=document.getElementById('line_total_'+i);if(el)el.value='Rs '+money(inputRows[i].total_cost);}
function summaryHtml(o){return `<div class="col-md-3"><div class="glass p-3"><div class="text-secondary fw-bold">Work Order</div><h5>${esc(o.work_order_no)}</h5></div></div><div class="col-md-3"><div class="glass p-3"><div class="text-secondary fw-bold">Total Cost</div><h5>Rs ${money(o.total_cost)}</h5></div></div><div class="col-md-3"><div class="glass p-3"><div class="text-secondary fw-bold">Cost / Unit</div><h5>Rs ${money(o.cost_per_unit,4)}</h5></div></div><div class="col-md-3"><div class="glass p-3"><div class="text-secondary fw-bold">Status</div><h5>${badge(o.status||'draft')}</h5></div></div>`;}
function outputsHtml(d){let h='<div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Actual Qty</th><th>Type</th><th>Stock Add</th></tr></thead><tbody>';d.outputs.forEach(r=>h+=`<tr><td>${esc(r.item_name||'-')}</td><td>${money(r.actual_qty,4)} ${esc(r.unit||'')}</td><td>${esc(r.output_type)}</td><td>${Number(r.is_stock_add)?'Yes':'No'}</td></tr>`);h+='</tbody></table></div>';if(d.wastage&&d.wastage.length){h+='<h6 class="mt-3">Wastage Logs</h6><div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Qty</th><th>Type</th><th>Cost Impact</th><th>Reason</th></tr></thead><tbody>';d.wastage.forEach(r=>h+=`<tr><td>${esc(r.item_name||'-')}</td><td>${money(r.qty,4)} ${esc(r.unit||'')}</td><td>${esc(r.wastage_type)}</td><td>Rs ${money(r.cost_impact)}</td><td>${esc(r.reason||'-')}</td></tr>`);h+='</tbody></table></div>';}return h;}
function editCost(id){fetch(`?ajax=get_costing&id=${id}`).then(r=>r.json()).then(d=>{if(!d.ok){alert(d.message);return;}document.getElementById('order_id').value=d.order.id;document.getElementById('summaryCards').innerHTML=summaryHtml(d.order);inputRows=d.inputs.map(x=>({...x,id:Number(x.id),actual_qty:Number(x.actual_qty),unit_cost:Number(x.unit_cost),total_cost:Number(x.total_cost)}));renderInputs();document.getElementById('outputsBox').innerHTML=outputsHtml(d);costModal.show();});}
function viewCost(id){fetch(`?ajax=get_costing&id=${id}`).then(r=>r.json()).then(d=>{if(!d.ok){alert(d.message);return;}let h='<div class="row g-3 mb-3">'+summaryHtml(d.order)+'</div><h5>Input Cost Lines</h5><div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Actual Qty</th><th>Unit Cost</th><th>Total</th><th>Type</th></tr></thead><tbody>';d.inputs.forEach(r=>h+=`<tr><td>${esc(r.item_name||'-')}</td><td>${money(r.actual_qty,4)} ${esc(r.unit||'')}</td><td>Rs ${money(r.unit_cost,4)}</td><td>Rs ${money(r.total_cost)}</td><td>${esc(r.cost_type)}</td></tr>`);h+='</tbody></table></div><h5 class="mt-4">Outputs / Wastage</h5>'+outputsHtml(d);document.getElementById('viewBody').innerHTML=h;viewModal.show();});}
document.getElementById('costForm').addEventListener('submit',e=>{e.preventDefault();document.getElementById('inputs_json').value=JSON.stringify(inputRows);fetch('',{method:'POST',body:new FormData(e.target)}).then(r=>r.json()).then(d=>{alert(d.message);if(d.ok)location.reload();}).catch(()=>alert('Save request failed.'));});function recalcOne(id){let fd=new FormData();fd.append('ajax','recalc_one');fd.append('id',id);fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);if(d.ok)location.reload();});}function recalcAll(){if(!confirm('All work orders ki costing recalculate karni hai?'))return;let fd=new FormData();fd.append('ajax','recalc_all');fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);if(d.ok)location.reload();});}
document.getElementById('searchInput').addEventListener('input',filterRows);document.getElementById('statusFilter').addEventListener('change',filterRows);function filterRows(){const q=document.getElementById('searchInput').value.toLowerCase();const st=document.getElementById('statusFilter').value;document.querySelectorAll('#costTable tbody tr').forEach(tr=>{const okQ=tr.innerText.toLowerCase().includes(q);const okS=!st||tr.dataset.status===st;tr.style.display=(okQ&&okS)?'':'none';});}
</script></body></html>
