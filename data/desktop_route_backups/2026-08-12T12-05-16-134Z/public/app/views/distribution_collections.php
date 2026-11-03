<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);

if (!function_exists('dc_h')) {
    function dc_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function dc_json($arr){ header('Content-Type: application/json'); echo json_encode($arr); exit; }
function dc_table_exists(mysqli $conn, string $table): bool {
    static $cache=[];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return $cache[$table] = false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$table] = ((int)($row['c'] ?? 0) > 0);
}
function dc_col_exists(mysqli $conn, string $table, string $col): bool {
    static $cache=[];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $key = $table.'.'.$col;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return $cache[$key] = false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}
function dc_first_col(mysqli $conn, string $table, array $cols, string $fallback=''): string {
    foreach($cols as $c){ if (dc_col_exists($conn,$table,$c)) return $c; }
    return $fallback;
}
function dc_scalar(mysqli $conn, string $sql, string $types='', array $params=[]){
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? null;
}
function dc_rows(mysqli $conn, string $sql, string $types='', array $params=[]): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}
function dc_invoice_expr(mysqli $conn): string {
    $c = dc_first_col($conn,'sales',['invoice_no','order_no','reference_no'],'');
    return $c ? "COALESCE(NULLIF(s.`$c`,''), CONCAT('ORD-',s.id))" : "CONCAT('ORD-',s.id)";
}
function dc_total_expr(mysqli $conn): string {
    foreach(['grand_total','total_amount','total','net_total'] as $c){ if(dc_col_exists($conn,'sales',$c)) return "COALESCE(s.`$c`,0)"; }
    return '0';
}
function dc_payment_expr(mysqli $conn): string {
    if(dc_col_exists($conn,'delivery_order_assignments','payment_method')) return "COALESCE(NULLIF(a.payment_method,''),'Cash')";
    if(dc_col_exists($conn,'sales','payment_method')) return "COALESCE(NULLIF(s.payment_method,''),'Cash')";
    if(dc_col_exists($conn,'sales','payment_status')) return "COALESCE(NULLIF(s.payment_status,''),'Cash')";
    return "'Cash'";
}
function dc_customer_name_expr(mysqli $conn): string {
    $saleName = dc_col_exists($conn,'sales','customer_name') ? "NULLIF(s.customer_name,'')" : "NULL";
    $custCol = dc_table_exists($conn,'customers') ? dc_first_col($conn,'customers',['name','customer_name','full_name'],'') : '';
    $custExpr = $custCol ? "NULLIF(c.`$custCol`,'')" : "NULL";
    return "COALESCE($custExpr, $saleName, 'Walk-in Customer')";
}
function dc_customer_phone_expr(mysqli $conn): string {
    $phoneCol = dc_table_exists($conn,'customers') ? dc_first_col($conn,'customers',['phone','mobile','contact_no','phone_number'],'') : '';
    return $phoneCol ? "COALESCE(c.`$phoneCol`,'')" : "''";
}
function dc_next_settlement_no(mysqli $conn, int $client_id): string {
    $prefix = 'SET-'.date('ymd').'-';
    $n = (int)dc_scalar($conn,"SELECT COUNT(*) + 1 FROM distribution_collection_settlements WHERE client_id=? AND settlement_no LIKE ?",'is',[$client_id,$prefix.'%']);
    return $prefix.str_pad((string)$n,3,'0',STR_PAD_LEFT);
}
function dc_status_from_amounts(float $collectable, float $received): array {
    $shortage = max(0, $collectable - $received);
    $extra = max(0, $received - $collectable);
    if ($received <= 0 && $collectable > 0) $status = 'pending';
    elseif ($extra > 0) $status = 'over';
    elseif ($shortage > 0) $status = 'partial';
    else $status = 'settled';
    return [$status, $shortage, $extra];
}

if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'stats') {
        $today = date('Y-m-d');
        $unsettledOrders = (int)dc_scalar($conn,"SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='delivered' AND COALESCE(settlement_status,'unsettled')!='settled'",'i',[$client_id]);
        $unsettledAmount = (float)dc_scalar($conn,"SELECT COALESCE(SUM(collection_amount),0) FROM delivery_order_assignments WHERE client_id=? AND status='delivered' AND COALESCE(settlement_status,'unsettled')!='settled'",'i',[$client_id]);
        $settledToday = (float)dc_scalar($conn,"SELECT COALESCE(SUM(total_received),0) FROM distribution_collection_settlements WHERE client_id=? AND settlement_date=? AND settlement_status!='cancelled'",'is',[$client_id,$today]);
        $shortage = (float)dc_scalar($conn,"SELECT COALESCE(SUM(shortage_amount),0) FROM distribution_collection_settlements WHERE client_id=? AND settlement_status IN ('partial','settled')",'i',[$client_id]);
        dc_json(['success'=>true,'stats'=>compact('unsettledOrders','unsettledAmount','settledToday','shortage')]);
    }

    if ($action === 'drivers') {
        $drivers = dc_rows($conn,"SELECT id, driver_code, driver_name, phone, vehicle, status FROM delivery_drivers WHERE client_id=? AND status!='Inactive' ORDER BY driver_name ASC",'i',[$client_id]);
        dc_json(['success'=>true,'drivers'=>$drivers]);
    }

    if ($action === 'unsettled_orders') {
        $page=max(1,(int)($_GET['page']??1)); $limit=max(5,min(100,(int)($_GET['limit']??10))); $offset=($page-1)*$limit;
        $search=trim($_GET['search']??''); $driverId=(int)($_GET['driver_id']??0); $dateFrom=trim($_GET['from_date']??''); $dateTo=trim($_GET['to_date']??'');
        $where=["a.client_id=?","a.status='delivered'","COALESCE(a.settlement_status,'unsettled')!='settled'"]; $types='i'; $params=[$client_id];
        if($branch_id>0 && dc_col_exists($conn,'delivery_order_assignments','branch_id')){ $where[]="(a.branch_id=? OR a.branch_id IS NULL OR a.branch_id=0)"; $types.='i'; $params[]=$branch_id; }
        if($driverId>0){ $where[]='a.driver_id=?'; $types.='i'; $params[]=$driverId; }
        if($dateFrom!==''){ $where[]='a.delivery_date>=?'; $types.='s'; $params[]=$dateFrom; }
        if($dateTo!==''){ $where[]='a.delivery_date<=?'; $types.='s'; $params[]=$dateTo; }
        $inv=dc_invoice_expr($conn); $cust=dc_customer_name_expr($conn); $phone=dc_customer_phone_expr($conn); $total=dc_total_expr($conn); $pay=dc_payment_expr($conn);
        if($search!==''){ $where[]="($inv LIKE ? OR $cust LIKE ? OR $phone LIKE ? OR d.driver_name LIKE ?)"; $like='%'.$search.'%'; $types.='ssss'; array_push($params,$like,$like,$like,$like); }
        $whereSql=implode(' AND ',$where);
        $countSql="SELECT COUNT(*) FROM delivery_order_assignments a JOIN sales s ON s.id=a.sale_id LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN delivery_drivers d ON d.id=a.driver_id AND d.client_id=a.client_id WHERE $whereSql";
        $totalRows=(int)dc_scalar($conn,$countSql,$types,$params);
        $sql="SELECT a.id assignment_id, a.sale_id, a.driver_id, a.route_id, a.delivery_date, a.collection_amount, a.settlement_status,
                     d.driver_name, d.phone driver_phone, dr.route_name, $inv invoice_no, $cust customer_name, $phone phone, $total order_amount, $pay payment_method
              FROM delivery_order_assignments a
              JOIN sales s ON s.id=a.sale_id
              LEFT JOIN customers c ON c.id=s.customer_id
              LEFT JOIN delivery_drivers d ON d.id=a.driver_id AND d.client_id=a.client_id
              LEFT JOIN distribution_routes dr ON dr.id=a.route_id AND dr.client_id=a.client_id
              WHERE $whereSql ORDER BY a.delivery_date DESC, a.id DESC LIMIT ? OFFSET ?";
        $types2=$types.'ii'; $params2=$params; $params2[]=$limit; $params2[]=$offset;
        dc_json(['success'=>true,'orders'=>dc_rows($conn,$sql,$types2,$params2),'total'=>$totalRows,'page'=>$page,'pages'=>max(1,(int)ceil($totalRows/$limit))]);
    }

    if ($action === 'settlements') {
        $page=max(1,(int)($_GET['page']??1)); $limit=max(5,min(100,(int)($_GET['limit']??10))); $offset=($page-1)*$limit;
        $search=trim($_GET['search']??''); $driverId=(int)($_GET['driver_id']??0); $status=trim($_GET['status']??''); $dateFrom=trim($_GET['from_date']??''); $dateTo=trim($_GET['to_date']??'');
        $where=['cs.client_id=?']; $types='i'; $params=[$client_id];
        if($driverId>0){ $where[]='cs.driver_id=?'; $types.='i'; $params[]=$driverId; }
        if($status!==''){ $where[]='cs.settlement_status=?'; $types.='s'; $params[]=$status; }
        if($dateFrom!==''){ $where[]='cs.settlement_date>=?'; $types.='s'; $params[]=$dateFrom; }
        if($dateTo!==''){ $where[]='cs.settlement_date<=?'; $types.='s'; $params[]=$dateTo; }
        if($search!==''){ $where[]='(cs.settlement_no LIKE ? OR d.driver_name LIKE ? OR d.phone LIKE ?)'; $like='%'.$search.'%'; $types.='sss'; array_push($params,$like,$like,$like); }
        $whereSql=implode(' AND ',$where);
        $totalRows=(int)dc_scalar($conn,"SELECT COUNT(*) FROM distribution_collection_settlements cs LEFT JOIN delivery_drivers d ON d.id=cs.driver_id AND d.client_id=cs.client_id WHERE $whereSql",$types,$params);
        $sql="SELECT cs.*, d.driver_name, d.phone driver_phone, d.vehicle
              FROM distribution_collection_settlements cs
              LEFT JOIN delivery_drivers d ON d.id=cs.driver_id AND d.client_id=cs.client_id
              WHERE $whereSql ORDER BY cs.id DESC LIMIT ? OFFSET ?";
        $types2=$types.'ii'; $params2=$params; $params2[]=$limit; $params2[]=$offset;
        dc_json(['success'=>true,'settlements'=>dc_rows($conn,$sql,$types2,$params2),'total'=>$totalRows,'page'=>$page,'pages'=>max(1,(int)ceil($totalRows/$limit))]);
    }

    if ($action === 'create_settlement') {
        $ids=$_POST['assignment_ids']??[];
        if(!is_array($ids)) $ids=explode(',',(string)$ids);
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
        $driverId=(int)($_POST['driver_id']??0);
        $settlementDate=trim($_POST['settlement_date']??date('Y-m-d'));
        $cash=(float)($_POST['cash_amount']??0);
        $online=(float)($_POST['online_amount']??0);
        $adjustment=(float)($_POST['adjustment_amount']??0);
        $notes=trim($_POST['notes']??'');
        if(empty($ids)) dc_json(['success'=>false,'message'=>'Please select delivered orders.']);
        if($driverId<=0) dc_json(['success'=>false,'message'=>'Rider required.']);
        $received=$cash+$online+$adjustment;
        $in=implode(',',array_fill(0,count($ids),'?'));
        $types=str_repeat('i',count($ids));
        $params=$ids;
        $types='i'.$types.'i';
        array_unshift($params,$client_id);
        $params[]=$driverId;
        $inv=dc_invoice_expr($conn); $total=dc_total_expr($conn); $pay=dc_payment_expr($conn);
        $rows=dc_rows($conn,"SELECT a.*, s.customer_id, $inv invoice_no, $total order_amount, $pay payment_method
            FROM delivery_order_assignments a
            JOIN sales s ON s.id=a.sale_id
            LEFT JOIN customers c ON c.id=s.customer_id
            WHERE a.client_id=? AND a.id IN ($in) AND a.driver_id=? AND a.status='delivered' AND COALESCE(a.settlement_status,'unsettled')!='settled'",$types,$params);
        if(count($rows)!==count($ids)) dc_json(['success'=>false,'message'=>'Kuch orders already settled hain ya selected rider ke nahi hain.']);
        $collectable=0.0; foreach($rows as $r){ $collectable += (float)($r['collection_amount']??0); }
        [$settlementStatus,$shortage,$extra]=dc_status_from_amounts($collectable,$received);
        $settlementNo=dc_next_settlement_no($conn,$client_id);
        $conn->begin_transaction();
        try{
            $stmt=$conn->prepare("INSERT INTO distribution_collection_settlements (client_id,branch_id,settlement_no,driver_id,settlement_date,total_orders,total_collectable,total_received,cash_amount,online_amount,adjustment_amount,shortage_amount,extra_amount,settlement_status,notes,created_by,settled_by,settled_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)");
            if(!$stmt) throw new Exception($conn->error);
            $totalOrders=count($rows);
            $stmt->bind_param('iisisidddddddssii',$client_id,$branch_id,$settlementNo,$driverId,$settlementDate,$totalOrders,$collectable,$received,$cash,$online,$adjustment,$shortage,$extra,$settlementStatus,$notes,$user_id,$user_id);
            if(!$stmt->execute()) throw new Exception($stmt->error);
            $settlementId=(int)$conn->insert_id; $stmt->close();
            $item=$conn->prepare("INSERT INTO distribution_collection_settlement_items (client_id,branch_id,settlement_id,assignment_id,sale_id,driver_id,customer_id,route_id,invoice_no,order_amount,collected_amount,payment_method,delivery_date,remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if(!$item) throw new Exception($conn->error);
            foreach($rows as $r){
                $assignmentId=(int)$r['id']; $saleId=(int)$r['sale_id']; $customerId=(int)($r['customer_id']??0); $routeId=(int)($r['route_id']??0);
                $invoice=(string)($r['invoice_no']??('ORD-'.$saleId)); $orderAmount=(float)($r['order_amount']??0); $collected=(float)($r['collection_amount']??0); $pm=(string)($r['payment_method']??'Cash'); $deliveryDate=(string)($r['delivery_date']??$settlementDate);
                $item->bind_param('iiiiiiiisddsss',$client_id,$branch_id,$settlementId,$assignmentId,$saleId,$driverId,$customerId,$routeId,$invoice,$orderAmount,$collected,$pm,$deliveryDate,$notes);
                if(!$item->execute()) throw new Exception($item->error);
                $u=$conn->prepare("UPDATE delivery_order_assignments SET settlement_id=?, settlement_status='settled', settled_at=CURRENT_TIMESTAMP, settlement_notes=? WHERE id=? AND client_id=?");
                if(!$u) throw new Exception($conn->error);
                $u->bind_param('isii',$settlementId,$notes,$assignmentId,$client_id);
                if(!$u->execute()) throw new Exception($u->error);
                $u->close();
            }
            $item->close();
            $log=$conn->prepare("INSERT INTO distribution_collection_logs (client_id,branch_id,settlement_id,action,new_status,remarks,created_by) VALUES (?,?,?,?,?,?,?)");
            if($log){ $actionName='create_settlement'; $log->bind_param('iiisssi',$client_id,$branch_id,$settlementId,$actionName,$settlementStatus,$notes,$user_id); $log->execute(); $log->close(); }
            $conn->commit();
            dc_json(['success'=>true,'message'=>'Settlement created: '.$settlementNo,'settlement_id'=>$settlementId]);
        }catch(Throwable $e){ $conn->rollback(); dc_json(['success'=>false,'message'=>$e->getMessage()]); }
    }

    if ($action === 'settlement_detail') {
        $id=(int)($_GET['id']??0);
        $sett=dc_rows($conn,"SELECT cs.*, d.driver_name, d.phone driver_phone, d.vehicle FROM distribution_collection_settlements cs LEFT JOIN delivery_drivers d ON d.id=cs.driver_id AND d.client_id=cs.client_id WHERE cs.id=? AND cs.client_id=?",'ii',[$id,$client_id]);
        if(!$sett) dc_json(['success'=>false,'message'=>'Settlement not found.']);
        $items=dc_rows($conn,"SELECT si.*, c.name customer_name, c.phone FROM distribution_collection_settlement_items si LEFT JOIN customers c ON c.id=si.customer_id WHERE si.client_id=? AND si.settlement_id=? ORDER BY si.id ASC",'ii',[$client_id,$id]);
        dc_json(['success'=>true,'settlement'=>$sett[0],'items'=>$items]);
    }

    if ($action === 'cancel_settlement') {
        $id=(int)($_POST['id']??0); $reason=trim($_POST['reason']??'Cancelled');
        $sett=dc_rows($conn,"SELECT * FROM distribution_collection_settlements WHERE id=? AND client_id=? AND settlement_status!='cancelled'",'ii',[$id,$client_id]);
        if(!$sett) dc_json(['success'=>false,'message'=>'Settlement not found or already cancelled.']);
        $conn->begin_transaction();
        try{
            $old=(string)$sett[0]['settlement_status'];
            $stmt=$conn->prepare("UPDATE distribution_collection_settlements SET settlement_status='cancelled', notes=CONCAT(COALESCE(notes,''), '\nCancelled: ', ?) WHERE id=? AND client_id=?");
            $stmt->bind_param('sii',$reason,$id,$client_id); $stmt->execute(); $stmt->close();
            $items=dc_rows($conn,"SELECT assignment_id FROM distribution_collection_settlement_items WHERE client_id=? AND settlement_id=?",'ii',[$client_id,$id]);
            foreach($items as $it){ $aid=(int)$it['assignment_id']; $u=$conn->prepare("UPDATE delivery_order_assignments SET settlement_id=NULL, settlement_status='unsettled', settled_at=NULL, settlement_notes=NULL WHERE id=? AND client_id=?"); $u->bind_param('ii',$aid,$client_id); $u->execute(); $u->close(); }
            $u=$conn->prepare("UPDATE distribution_collection_settlement_items SET item_status='cancelled', remarks=CONCAT(COALESCE(remarks,''), '\nCancelled: ', ?) WHERE client_id=? AND settlement_id=?");
            $u->bind_param('sii',$reason,$client_id,$id); $u->execute(); $u->close();
            $log=$conn->prepare("INSERT INTO distribution_collection_logs (client_id,branch_id,settlement_id,action,old_status,new_status,remarks,created_by) VALUES (?,?,?,?,?,?,?,?)");
            if($log){ $act='cancel_settlement'; $new='cancelled'; $log->bind_param('iiissssi',$client_id,$branch_id,$id,$act,$old,$new,$reason,$user_id); $log->execute(); $log->close(); }
            $conn->commit(); dc_json(['success'=>true,'message'=>'Settlement cancelled. Orders unsettled again.']);
        }catch(Throwable $e){ $conn->rollback(); dc_json(['success'=>false,'message'=>$e->getMessage()]); }
    }

    dc_json(['success'=>false,'message'=>'Invalid action.']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include('partials/head.php'); ?>
    <title>Distribution Collections</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
    :root {
        --sidebar-width: 292px;
        --topbar-height: 78px;
        --gold: #f6b900;
        --page-bg: #f4f6ff;
        --navy: #17243d;
        --head: #263650;
        --border: #dfe5ef;
        --text: #13233d;
        --muted: #687282;
        --green: #16a34a;
        --red: #dc2626;
        --orange: #f59e0b;
        --blue: #2563eb;
    }

    * {
        box-sizing: border-box
    }

    body {
        margin: 0;
        background: var(--page-bg);
        font-family: "Inter", "Segoe UI", system-ui, sans-serif;
        color: var(--text);
        overflow-x: hidden
    }

    .container-scroll {
        display: flex;
        min-height: 100vh
    }

    .sidebar {
        width: var(--sidebar-width);
        flex: 0 0 var(--sidebar-width);
        min-height: 100vh
    }

    .main-panel {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column
    }

    .topbar {
        height: var(--topbar-height);
        background: #050505;
        position: sticky;
        top: 0;
        z-index: 999;
        display: flex;
        align-items: center
    }

    .content-wrapper {
        padding: 24px 24px 60px;
        min-height: calc(100vh - var(--topbar-height))
    }

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 18px
    }

    .page-title {
        font-size: 33px;
        font-weight: 900;
        letter-spacing: -.6px;
        margin: 0
    }

    .page-sub {
        color: var(--muted);
        font-weight: 700
    }

    .btn-main {
        height: 48px;
        border: 0;
        border-radius: 10px;
        background: var(--gold);
        font-weight: 900;
        color: #111;
        padding: 0 20px
    }

    .btn-dark-main {
        height: 48px;
        border: 0;
        border-radius: 10px;
        background: var(--navy);
        font-weight: 900;
        color: #fff;
        padding: 0 20px
    }

    .btn-gray {
        height: 48px;
        border: 0;
        border-radius: 10px;
        background: #687282;
        color: #fff;
        font-weight: 900;
        padding: 0 20px
    }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 16px
    }

    .kpi-card {
        min-height: 76px;
        border-radius: 10px;
        padding: 0 18px;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 8px 18px rgba(0, 0, 0, .08)
    }

    .kpi-card:nth-child(1) {
        background: linear-gradient(135deg, #4095ff, #2f80ed)
    }

    .kpi-card:nth-child(2) {
        background: linear-gradient(135deg, #49be88, #2ea769)
    }

    .kpi-card:nth-child(3) {
        background: linear-gradient(135deg, #4ac9d6, #26b4c0)
    }

    .kpi-card:nth-child(4) {
        background: linear-gradient(135deg, #ef4444, #b91c1c)
    }

    .kpi-icon {
        font-size: 21px
    }

    .kpi-label {
        font-size: 14px;
        font-weight: 800;
        line-height: 1.1
    }

    .kpi-value {
        font-size: 27px;
        font-weight: 900;
        margin-left: auto
    }

    .tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 14px
    }

    .tab-btn {
        height: 44px;
        border: 1px solid var(--border);
        background: #fff;
        border-radius: 10px;
        padding: 0 18px;
        font-weight: 900
    }

    .tab-btn.active {
        background: var(--navy);
        color: #fff;
        border-color: var(--navy)
    }

    .filter-bar {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 12px;
        box-shadow: 0 10px 24px rgba(18, 35, 64, .06);
        margin-bottom: 18px;
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap
    }

    .filter-bar:before {
        content: "⚡ Cash Filters";
        height: 48px;
        min-width: 160px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #111827;
        color: #fff;
        font-size: 15px;
        font-weight: 900;
        padding: 0 16px
    }

    .filter-input,
    .filter-select {
        height: 48px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #fff;
        font-size: 15px;
        color: #111827;
        padding: 0 14px;
        min-width: 160px
    }

    .filter-input {
        min-width: 260px;
        flex: 1
    }

    .white-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: 0 12px 26px rgba(18, 35, 64, .07);
        overflow: hidden
    }

    .card-head {
        padding: 16px 18px;
        border-bottom: 1px solid #e7ecf4;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap
    }

    .card-title {
        font-size: 17px;
        font-weight: 900;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 8px
    }

    .table-wrap {
        overflow-x: auto
    }

    table.custom-table {
        width: 100%;
        min-width: 1120px;
        border-collapse: separate;
        border-spacing: 0
    }

    .custom-table th {
        background: var(--head);
        color: #fff;
        font-size: 14px;
        text-transform: none;
        padding: 16px 14px;
        border: 0;
        font-weight: 900;
        white-space: nowrap
    }

    .custom-table td {
        padding: 15px 14px;
        border-bottom: 1px solid #e7ecf4;
        color: #19253a;
        background: #fff;
        font-size: 14px;
        vertical-align: middle
    }

    .custom-table tr:hover td {
        background: #f8fbff
    }

    .order-no {
        font-weight: 900;
        color: var(--navy)
    }

    .small-muted {
        font-size: 12px;
        color: var(--muted);
        font-weight: 700
    }

    .badge-custom {
        border-radius: 999px;
        padding: 6px 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 5px
    }

    .badge-pending {
        background: #fff7ed;
        color: #ea580c
    }

    .badge-partial {
        background: #fef3c7;
        color: #d97706
    }

    .badge-settled {
        background: #dcfce7;
        color: #16a34a
    }

    .badge-over {
        background: #ecfeff;
        color: #0891b2
    }

    .badge-cancelled {
        background: #fee2e2;
        color: #dc2626
    }

    .action-btn {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: transparent;
        border: 0;
        color: #536174
    }

    .action-btn:hover {
        background: #eef4ff;
        color: var(--navy)
    }

    .bulk-bar {
        display: none;
        background: var(--navy);
        color: #fff;
        border-radius: 12px;
        padding: 12px 16px;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px;
        font-weight: 900
    }

    .bulk-bar.show {
        display: flex
    }

    .bulk-count {
        color: var(--gold)
    }

    .pagination-custom {
        padding: 16px 18px;
        background: #fff;
        border-top: 1px solid #e7ecf4;
        display: flex;
        align-items: center;
        gap: 5px
    }

    .page-btn {
        border-radius: 8px;
        width: 34px;
        height: 34px;
        border: 1px solid var(--border);
        background: #fff;
        font-weight: 900
    }

    .page-btn.active {
        background: var(--navy);
        border-color: var(--navy);
        color: #fff
    }

    .pagination-info {
        font-size: 12px;
        color: var(--muted);
        font-weight: 800;
        margin-left: auto
    }

    .modal-custom {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .6);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(2px)
    }

    .modal-custom.show {
        display: flex
    }

    .modal-box {
        background: #fff;
        border-radius: 14px;
        width: 92%;
        max-width: 760px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .22);
        overflow: hidden;
        max-height: 92vh;
        overflow-y: auto
    }

    .modal-head {
        background: var(--head);
        color: #fff;
        padding: 18px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between
    }

    .modal-title {
        font-size: 16px;
        font-weight: 900
    }

    .modal-close {
        width: 30px;
        height: 30px;
        border: 0;
        border-radius: 8px;
        background: rgba(255, 255, 255, .12);
        color: #fff
    }

    .modal-body {
        padding: 22px
    }

    .modal-foot {
        padding: 16px 22px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 8px
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px
    }

    .form-label-custom {
        font-size: 13px;
        font-weight: 900;
        color: #334155;
        margin-bottom: 6px;
        display: block
    }

    .form-control-custom {
        width: 100%;
        height: 46px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #fff;
        font-size: 15px;
        color: #111827;
        padding: 0 14px
    }

    .form-control-custom:focus {
        outline: none;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .08)
    }

    textarea.form-control-custom {
        height: auto;
        min-height: 86px;
        padding-top: 12px
    }

    .summary-box {
        background: #f8fbff;
        border: 1px solid #dfe5ef;
        border-radius: 12px;
        padding: 14px;
        margin-bottom: 14px;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px
    }

    .summary-item {
        background: #fff;
        border: 1px solid #e7ecf4;
        border-radius: 10px;
        padding: 10px
    }

    .summary-label {
        font-size: 12px;
        color: var(--muted);
        font-weight: 800
    }

    .summary-val {
        font-size: 20px;
        font-weight: 900;
        color: var(--navy)
    }

    #toast-container {
        position: fixed;
        right: 28px;
        bottom: 28px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 10px
    }

    .toast-item {
        background: #1e2533;
        color: #fff;
        border-radius: 10px;
        padding: 12px 18px;
        min-width: 280px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .2);
        font-size: 13.5px;
        font-weight: 800
    }

    .toast-item.success {
        border-left: 4px solid #22c55e
    }

    .toast-item.error {
        border-left: 4px solid #ef4444
    }

    .custom-check {
        width: 16px;
        height: 16px;
        accent-color: var(--blue)
    }

    @media(max-width:1200px) {
        .kpi-grid {
            grid-template-columns: repeat(2, 1fr)
        }
    }

    @media(max-width:768px) {
        .sidebar {
            display: none
        }

        .content-wrapper {
            padding: 16px
        }

        .page-title {
            font-size: 26px
        }

        .kpi-grid,
        .form-grid,
        .summary-box {
            grid-template-columns: 1fr
        }

        .filter-bar:before,
        .filter-input,
        .filter-select,
        .btn-dark-main,
        .btn-gray {
            width: 100%;
            min-width: 100%
        }
    }
    /* =====================================================
   DISTRIBUTION_COLLECTIONS.PHP
   DASHBOARD DARK PURPLE / BLACK / GLASS THEME
   Paste before 
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-border:rgba(148,163,184,.14);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT / SIDEBAR ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    background:transparent !important;
    overflow:visible !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

#dashboardSidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
}

#dashboardSidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

#dashboardSidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.main-panel{
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow,
body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon{
    margin:0 !important;
}

/* ================= TOPBAR / PAGE BG ================= */
.topbar{
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid var(--oms-border) !important;
    display:flex !important;
    align-items:center !important;
    padding:0 !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
}

.content-wrapper{
    position:relative !important;
    padding:24px 26px !important;
    flex:1 !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
}

.content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}

.content-wrapper::after{
    content:"";
    position:absolute;
    width:520px;
    height:520px;
    right:-190px;
    top:-210px;
    pointer-events:none;
    z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);
    opacity:.85;
}

.content-wrapper > *{
    position:relative;
    z-index:1;
}

/* ================= HEADER ================= */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-sub,
.small-muted,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

/* ================= BUTTONS ================= */
.btn-main{
    min-height:46px !important;
    height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
    transition:.22s ease !important;
}

.btn-dark-main{
    min-height:46px !important;
    height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.btn-gray{
    min-height:46px !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.btn-main:hover,
.btn-dark-main:hover,
.btn-gray:hover,
.btn-light:hover,
.btn-outline-light:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* ================= KPI CARDS ================= */
.kpi-grid{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.kpi-card{
    min-height:138px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate;
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    transition:.25s ease !important;
}

.kpi-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.kpi-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
}

.kpi-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.kpi-card:nth-child(1){background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.kpi-card:nth-child(2){background:linear-gradient(135deg,#047857,#16a34a) !important;}
.kpi-card:nth-child(3){background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.kpi-card:nth-child(4){background:linear-gradient(135deg,#be123c,#ef4444) !important;}

.kpi-icon{
    font-size:30px !important;
    position:relative;
    z-index:2;
}

.kpi-label{
    font-size:15px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
    position:relative;
    z-index:2;
}

.kpi-value{
    font-size:30px !important;
    font-weight:900 !important;
    line-height:1 !important;
    color:#fff !important;
    margin-left:auto !important;
    position:relative;
    z-index:2;
}

/* ================= TABS ================= */
.tabs{
    gap:10px !important;
    margin-bottom:16px !important;
}

.tab-btn{
    height:44px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    padding:0 16px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.tab-btn:hover,
.tab-btn.active{
    color:#fff !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.42) !important;
    transform:translateY(-1px);
}

/* ================= GLASS PANELS ================= */
.filter-bar,
.white-card,
.bulk-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    color:#e5e7eb !important;
}

.filter-bar:hover,
.white-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-bar{
    padding:14px !important;
    gap:10px !important;
    margin-bottom:18px !important;
}

.filter-bar::before{
    content:"⚡ Cash Filters" !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.card-head{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
}

.card-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* ================= INPUTS ================= */
.filter-input,
.filter-select,
.form-control-custom{
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-input,
.filter-select,
.form-control-custom{
    height:46px !important;
}

textarea.form-control-custom{
    height:auto !important;
    min-height:86px !important;
    padding-top:12px !important;
}

.filter-input::placeholder,
.form-control-custom::placeholder{
    color:#94a3b8 !important;
}

.filter-input:focus,
.filter-select:focus,
.form-control-custom:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-select option,
.form-control-custom option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.form-label-custom{
    color:#f8fafc !important;
    font-size:13px !important;
    font-weight:900 !important;
}

/* ================= TABLE DARK GLASS ================= */
.table-wrap,
table.custom-table,
.custom-table tbody,
.custom-table tbody tr{
    background:transparent !important;
}

table.custom-table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

.custom-table th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.04em;
    padding:14px 12px !important;
    white-space:nowrap;
}

.custom-table td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-top:none !important;
    padding:14px 12px !important;
    vertical-align:middle;
    font-size:14px !important;
    font-weight:600;
    white-space:nowrap;
}

/* white hover remove */
.custom-table tr:hover,
.custom-table tr:hover td,
.custom-table td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

.custom-table tr:focus,
.custom-table tr:active,
.custom-table td:focus,
.custom-table td:active,
.custom-table td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

.custom-table td.text-center,
.custom-table td.py-4,
.custom-table td[colspan]{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

.custom-table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

.order-no{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* ================= BADGES ================= */
.badge-custom{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.badge-pending{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.badge-partial{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.badge-settled{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.badge-over{
    background:rgba(6,182,212,.14) !important;
    color:#67e8f9 !important;
    border:1px solid rgba(6,182,212,.24) !important;
}

.badge-cancelled{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* ================= ACTION / PAGINATION / BULK ================= */
.action-btn{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(109,76,255,.18) !important;
    color:#c4b5fd !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.action-btn:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.28) !important;
    color:#fff !important;
}

.bulk-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(109,76,255,.82), rgba(15,23,42,.72)) !important;
    color:#fff !important;
}

.bulk-count{
    color:#fbbf24 !important;
}

.pagination-custom{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#94a3b8 !important;
}

.page-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:10px !important;
}

.page-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.42) !important;
}

.pagination-info{
    color:#94a3b8 !important;
}

.custom-check{
    accent-color:#7c3aed !important;
}

/* ================= TOAST ================= */
.toast-item{
    background:#0f172a !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}

.toast-item.success{
    border-left:4px solid #22c55e !important;
}

.toast-item.error{
    border-left:4px solid #ef4444 !important;
}

/* ================= SCROLLBAR ================= */
.table-wrap::-webkit-scrollbar,
.modal-body::-webkit-scrollbar,
.modal-box::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-wrap::-webkit-scrollbar-track,
.modal-body::-webkit-scrollbar-track,
.modal-box::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb,
.modal-body::-webkit-scrollbar-thumb,
.modal-box::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb:hover,
.modal-body::-webkit-scrollbar-thumb:hover,
.modal-box::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}
    
    /* =====================================================
   COLLECTION POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

#setttleModal.modal-custom,
#settleModal.modal-custom,
#detailModal.modal-custom,
#cancelModal.modal-custom{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
}

#settleModal .modal-box,
#detailModal .modal-box,
#cancelModal .modal-box{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    max-height:92vh !important;
    max-width:760px !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#detailModal .modal-box{
    max-width:860px !important;
}

#settleModal .modal-head,
#detailModal .modal-head,
#cancelModal .modal-head{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    color:#fff !important;
}

#settleModal .modal-title,
#detailModal .modal-title,
#cancelModal .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#settleModal .modal-title::before,
#detailModal .modal-title::before,
#cancelModal .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#settleModal .modal-close,
#detailModal .modal-close,
#cancelModal .modal-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    color:transparent !important;
    opacity:1 !important;
    box-shadow:none !important;
    padding:0 !important;
    font-size:0 !important;
    line-height:1 !important;
}

#settleModal .modal-close::before,
#settleModal .modal-close::after,
#detailModal .modal-close::before,
#detailModal .modal-close::after,
#cancelModal .modal-close::before,
#cancelModal .modal-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:18px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#settleModal .modal-close::before,
#detailModal .modal-close::before,
#cancelModal .modal-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#settleModal .modal-close::after,
#detailModal .modal-close::after,
#cancelModal .modal-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#settleModal .modal-close:hover,
#detailModal .modal-close:hover,
#cancelModal .modal-close:hover{
    background:#ef4444 !important;
}

#settleModal .modal-body,
#detailModal .modal-body,
#cancelModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#settleModal .modal-foot,
#detailModal .modal-foot,
#cancelModal .modal-foot{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#settleModal .form-grid{
    gap:12px !important;
}

#settleModal .form-label-custom,
#cancelModal .form-label-custom{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#settleModal .form-control-custom,
#cancelModal .form-control-custom,
#settleModal input,
#settleModal select,
#settleModal textarea,
#cancelModal textarea{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    min-height:40px !important;
    height:40px !important;
    padding:7px 12px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#settleModal textarea.form-control-custom,
#cancelModal textarea.form-control-custom{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#settleModal input[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

#settleModal input::placeholder,
#settleModal textarea::placeholder,
#cancelModal textarea::placeholder{
    color:#94a3b8 !important;
}

#settleModal .form-control-custom:focus,
#settleModal input:focus,
#settleModal select:focus,
#settleModal textarea:focus,
#cancelModal textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#settleModal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Summary boxes inside settlement/detail popup */
.summary-box{
    background:rgba(15,23,42,.45) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    padding:12px !important;
    gap:10px !important;
}

.summary-item{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

.summary-label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

.summary-val{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
}

/* Modal buttons */
#settleModal .btn-light,
#detailModal .btn-light,
#cancelModal .btn-light{
    min-height:38px !important;
    height:38px !important;
    min-width:105px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
}

#settleModal .btn-main,
#detailModal .btn-dark-main{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#cancelModal .btn-danger{
    min-height:38px !important;
    height:38px !important;
    min-width:135px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(239,68,68,.24) !important;
}

#settleModal .btn-light:hover,
#settleModal .btn-main:hover,
#detailModal .btn-light:hover,
#detailModal .btn-dark-main:hover,
#cancelModal .btn-light:hover,
#cancelModal .btn-danger:hover{
    transform:translateY(-1px);
}

@media(max-width:1200px){
    .kpi-grid{
        grid-template-columns:repeat(2,1fr) !important;
    }
}

@media(max-width:991px){
    .container-scroll{
        flex-direction:column !important;
    }

    .sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        position:relative !important;
        height:auto !important;
    }

    #dashboardSidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    #dashboardSidebar .sidebar-inner{
        height:auto !important;
        min-height:auto !important;
    }

    .main-panel{
        margin-left:0 !important;
    }

    .topbar{
        position:relative !important;
    }

    .content-wrapper{
        padding:18px !important;
    }
}

@media(max-width:768px){
    .kpi-grid,
    .form-grid,
    .summary-box{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .filter-bar{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .filter-bar::before,
    .filter-input,
    .filter-select,
    .btn-dark-main,
    .btn-gray,
    .btn-main{
        width:100% !important;
        min-width:100% !important;
    }

    .tab-btn{
        width:100% !important;
        justify-content:center !important;
    }

    #settleModal .modal-box,
    #detailModal .modal-box,
    #cancelModal .modal-box{
        width:96vw !important;
        max-height:94vh !important;
    }

    #settleModal .modal-foot,
    #detailModal .modal-foot,
    #cancelModal .modal-foot{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #settleModal .btn-light,
    #settleModal .btn-main,
    #detailModal .btn-light,
    #detailModal .btn-dark-main,
    #cancelModal .btn-light,
    #cancelModal .btn-danger{
        width:100% !important;
    }
}
    
    </style>
</head>

<body>
    <div class="container-scroll">
        <div class="sidebar"><?php include('partials/sidebar.php'); ?></div>
        <div class="main-panel">
            <div class="topbar"><?php include('partials/topbar.php'); ?></div>
            <main class="content-wrapper">
                <div class="page-header">
                    <div>
                        <h1 class="page-title"><i class="bi bi-cash-coin me-2"></i>Distribution Collections</h1>
                        <div class="page-sub">Rider cash received → shortage/extra → settlement close</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn-main" onclick="useSelectionForSettlement()"><i
                                class="bi bi-wallet2 me-1"></i>Create Settlement</button>
                        <button class="btn-dark-main" onclick="refreshAll()"><i
                                class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                    </div>
                </div>

                <div class="kpi-grid">
                    <div class="kpi-card"><i class="bi bi-receipt kpi-icon"></i>
                        <div class="kpi-label">Unsettled<br>Orders</div>
                        <div class="kpi-value" id="kOrders">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-cash-stack kpi-icon"></i>
                        <div class="kpi-label">Collectable<br>Amount</div>
                        <div class="kpi-value" id="kCollectable">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-calendar-check kpi-icon"></i>
                        <div class="kpi-label">Settled<br>Today</div>
                        <div class="kpi-value" id="kToday">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-exclamation-triangle kpi-icon"></i>
                        <div class="kpi-label">Total<br>Shortage</div>
                        <div class="kpi-value" id="kShortage">0</div>
                    </div>
                </div>

                <div class="tabs">
                    <button id="tabUnsettled" class="tab-btn active" onclick="switchTab('unsettled')">Unsettled
                        Delivered Orders</button>
                    <button id="tabSettlements" class="tab-btn" onclick="switchTab('settlements')">Settlement
                        History</button>
                </div>

                <div class="filter-bar">
                    <input id="fSearch" class="filter-input" placeholder="Search invoice, customer, phone, rider">
                    <select id="fDriver" class="filter-select">
                        <option value="">All Riders</option>
                    </select>
                    <select id="fStatus" class="filter-select" style="display:none">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="partial">Partial</option>
                        <option value="settled">Settled</option>
                        <option value="over">Over</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <input id="fFrom" type="date" class="filter-select">
                    <input id="fTo" type="date" class="filter-select">
                    <button class="btn-dark-main" onclick="applyFilters()"><i
                            class="bi bi-funnel me-1"></i>Filter</button>
                    <button class="btn-gray" onclick="resetFilters()">Reset</button>
                </div>

                <div id="bulkBar" class="bulk-bar">
                    <span><span id="bulkCount" class="bulk-count">0</span> order(s) selected</span>
                    <span>Collectable: <b id="bulkAmount">PKR 0</b></span>
                    <button class="btn btn-sm btn-light" onclick="useSelectionForSettlement()">Settle Selected</button>
                    <button class="btn btn-sm btn-outline-light ms-auto" onclick="clearSelection()">Cancel</button>
                </div>

                <section id="unsettledSection" class="white-card">
                    <div class="card-head">
                        <div class="card-title"><i class="bi bi-list-check"></i> Delivered Orders Awaiting Cash
                            Settlement</div>
                        <div class="small-muted" id="unsettledCount">0 orders</div>
                    </div>
                    <div class="table-wrap">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" class="custom-check" id="selectAll"
                                            onchange="toggleAll(this)"></th>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Rider</th>
                                    <th>Route</th>
                                    <th>Delivery Date</th>
                                    <th>Order Amount</th>
                                    <th>Collected</th>
                                    <th>Payment</th>
                                </tr>
                            </thead>
                            <tbody id="unsettledBody"></tbody>
                        </table>
                    </div>
                    <div class="pagination-custom">
                        <div id="unsettledPager"></div>
                        <div class="pagination-info" id="unsettledInfo"></div>
                    </div>
                </section>

                <section id="settlementsSection" class="white-card" style="display:none">
                    <div class="card-head">
                        <div class="card-title"><i class="bi bi-clock-history"></i> Settlement History</div>
                        <div class="small-muted" id="settlementCount">0 settlements</div>
                    </div>
                    <div class="table-wrap">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Settlement No</th>
                                    <th>Date</th>
                                    <th>Rider</th>
                                    <th>Orders</th>
                                    <th>Collectable</th>
                                    <th>Received</th>
                                    <th>Shortage</th>
                                    <th>Extra</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="settlementsBody"></tbody>
                        </table>
                    </div>
                    <div class="pagination-custom">
                        <div id="settlementPager"></div>
                        <div class="pagination-info" id="settlementInfo"></div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <div class="modal-custom" id="settleModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title"><i class="bi bi-wallet2 me-1"></i>Create Cash Settlement</div><button
                    class="modal-close" onclick="closeModal('settleModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="summary-box">
                    <div class="summary-item">
                        <div class="summary-label">Orders</div>
                        <div class="summary-val" id="sumOrders">0</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Collectable</div>
                        <div class="summary-val" id="sumCollectable">PKR 0</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Received</div>
                        <div class="summary-val" id="sumReceived">PKR 0</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Diff</div>
                        <div class="summary-val" id="sumDiff">PKR 0</div>
                    </div>
                </div><input type="hidden" id="settleIds">
                <div class="form-grid">
                    <div><label class="form-label-custom">Rider *</label><select id="settleDriver"
                            class="form-control-custom">
                            <option value="">Select Rider</option>
                        </select></div>
                    <div><label class="form-label-custom">Settlement Date</label><input type="date" id="settleDate"
                            class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
                    <div><label class="form-label-custom">Cash Amount</label><input type="number" step="0.01"
                            id="cashAmount" class="form-control-custom" value="0"></div>
                    <div><label class="form-label-custom">Online/Bank Amount</label><input type="number" step="0.01"
                            id="onlineAmount" class="form-control-custom" value="0"></div>
                    <div><label class="form-label-custom">Adjustment Amount</label><input type="number" step="0.01"
                            id="adjustmentAmount" class="form-control-custom" value="0"></div>
                    <div><label class="form-label-custom">Selected Orders</label><input id="selectedText"
                            class="form-control-custom" readonly></div>
                    <div style="grid-column:1/-1"><label class="form-label-custom">Notes</label><textarea
                            id="settleNotes" class="form-control-custom"
                            placeholder="Cash received by office / shortage reason / extra note"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button class="btn btn-light"
                    onclick="closeModal('settleModal')">Cancel</button><button class="btn-main"
                    onclick="saveSettlement()">Save Settlement</button></div>
        </div>
    </div>
    <div class="modal-custom" id="detailModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title"><i class="bi bi-receipt me-1"></i>Settlement Detail</div><button
                    class="modal-close" onclick="closeModal('detailModal')">×</button>
            </div>
            <div class="modal-body" id="detailBody"></div>
            <div class="modal-foot"><button class="btn btn-light" onclick="window.print()">Print</button><button
                    class="btn-dark-main" onclick="closeModal('detailModal')">Close</button></div>
        </div>
    </div>
    <div class="modal-custom" id="cancelModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title">Cancel Settlement</div><button class="modal-close"
                    onclick="closeModal('cancelModal')">×</button>
            </div>
            <div class="modal-body"><input type="hidden" id="cancelId"><label
                    class="form-label-custom">Reason</label><textarea id="cancelReason" class="form-control-custom"
                    placeholder="Why cancel this settlement?"></textarea></div>
            <div class="modal-foot"><button class="btn btn-light"
                    onclick="closeModal('cancelModal')">Close</button><button class="btn btn-danger"
                    onclick="confirmCancel()">Cancel Settlement</button></div>
        </div>
    </div>
    <div id="toast-container"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const api = location.pathname;
    let activeTab = 'unsettled',
        pageUn = 1,
        pageSet = 1,
        selected = new Map(),
        drivers = [];

    function esc(v) {
        return String(v ?? '').replace(/[&<>'"]/g, m => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        } [m]));
    }

    function money(v) {
        return 'PKR ' + Number(v || 0).toLocaleString(undefined, {
            maximumFractionDigits: 0
        });
    }

    function get(action, params = {}) {
        params.ajax = 1;
        params.action = action;
        return fetch(api + '?' + new URLSearchParams(params)).then(r => r.json());
    }

    function post(action, data = {}) {
        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('action', action);
        Object.keys(data).forEach(k => {
            if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k + '[]', v));
            else fd.append(k, data[k]);
        });
        return fetch(api, {
            method: 'POST',
            body: fd
        }).then(r => r.json());
    }

    function toast(msg, type = 'success') {
        let e = document.createElement('div');
        e.className = 'toast-item ' + type;
        e.textContent = msg;
        document.getElementById('toast-container').appendChild(e);
        setTimeout(() => e.remove(), 3500)
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show')
    }

    function openModal(id) {
        document.getElementById(id).classList.add('show')
    }

    function statusBadge(s) {
        s = String(s || 'pending');
        return `<span class="badge-custom badge-${s}">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`
    }

    function loadStats() {
        get('stats').then(r => {
            if (!r.success) return;
            kOrders.textContent = r.stats.unsettledOrders;
            kCollectable.textContent = money(r.stats.unsettledAmount).replace('PKR ', '');
            kToday.textContent = money(r.stats.settledToday).replace('PKR ', '');
            kShortage.textContent = money(r.stats.shortage).replace('PKR ', '')
        })
    }

    function loadDrivers() {
        get('drivers').then(r => {
            drivers = r.drivers || [];
            let opts = '<option value="">All Riders</option>',
                opts2 = '<option value="">Select Rider</option>';
            drivers.forEach(d => {
                opts += `<option value="${d.id}">${esc(d.driver_name)}</option>`;
                opts2 +=
                    `<option value="${d.id}">${esc(d.driver_name)} - ${esc(d.vehicle||'')}</option>`
            });
            fDriver.innerHTML = opts;
            settleDriver.innerHTML = opts2
        })
    }

    function switchTab(t) {
        activeTab = t;
        tabUnsettled.classList.toggle('active', t === 'unsettled');
        tabSettlements.classList.toggle('active', t === 'settlements');
        unsettledSection.style.display = t === 'unsettled' ? 'block' : 'none';
        settlementsSection.style.display = t === 'settlements' ? 'block' : 'none';
        fStatus.style.display = t === 'settlements' ? 'block' : 'none';
        clearSelection();
        if (t === 'unsettled') loadUnsettled(pageUn);
        else loadSettlements(pageSet)
    }

    function applyFilters() {
        activeTab === 'unsettled' ? loadUnsettled(1) : loadSettlements(1)
    }

    function resetFilters() {
        fSearch.value = '';
        fDriver.value = '';
        fStatus.value = '';
        fFrom.value = '';
        fTo.value = '';
        applyFilters()
    }

    function loadUnsettled(p = 1) {
        pageUn = p;
        get('unsettled_orders', {
            page: p,
            limit: 10,
            search: fSearch.value,
            driver_id: fDriver.value,
            from_date: fFrom.value,
            to_date: fTo.value
        }).then(r => {
            if (!r.success) {
                toast(r.message || 'Load failed', 'error');
                return
            }
            let h = '';
            (r.orders || []).forEach(o => {
                h += `<tr><td><input type="checkbox" class="custom-check row-check" value="${o.assignment_id}" data-driver="${o.driver_id}" data-amount="${Number(o.collection_amount||0)}" onchange='toggleOne(this, ${JSON.stringify({id:o.assignment_id,driver_id:o.driver_id,amount:Number(o.collection_amount||0)})})'></td><td><div class="order-no">#${esc(o.invoice_no)}</div><div class="small-muted">Sale #${o.sale_id}</div></td><td><b>${esc(o.customer_name)}</b><div class="small-muted">${esc(o.phone||'')}</div></td><td>${esc(o.driver_name||'-')}</td><td>${esc(o.route_name||'-')}</td><td>${esc(o.delivery_date||'-')}</td><td><b>${money(o.order_amount)}</b></td><td><b>${money(o.collection_amount)}</b></td><td>${esc(o.payment_method||'Cash')}</td></tr>`
            });
            unsettledBody.innerHTML = h ||
                '<tr><td colspan="9" class="text-center py-4 text-muted">No unsettled delivered orders found.</td></tr>';
            unsettledCount.textContent = (r.total || 0) + ' orders';
            renderPager('unsettledPager', r.page, r.pages, 'loadUnsettled');
            unsettledInfo.textContent = `Page ${r.page} of ${r.pages}`;
            loadStats();
            clearSelection(false)
        })
    }

    function loadSettlements(p = 1) {
        pageSet = p;
        get('settlements', {
            page: p,
            limit: 10,
            search: fSearch.value,
            driver_id: fDriver.value,
            status: fStatus.value,
            from_date: fFrom.value,
            to_date: fTo.value
        }).then(r => {
            if (!r.success) {
                toast(r.message || 'Load failed', 'error');
                return
            }
            let h = '';
            (r.settlements || []).forEach(s => {
                h += `<tr><td><div class="order-no">${esc(s.settlement_no)}</div></td><td>${esc(s.settlement_date)}</td><td><b>${esc(s.driver_name||'-')}</b><div class="small-muted">${esc(s.vehicle||'')}</div></td><td>${s.total_orders}</td><td><b>${money(s.total_collectable)}</b></td><td><b>${money(s.total_received)}</b></td><td>${money(s.shortage_amount)}</td><td>${money(s.extra_amount)}</td><td>${statusBadge(s.settlement_status)}</td><td><button class="action-btn" onclick="viewSettlement(${s.id})"><i class="bi bi-eye"></i></button>${s.settlement_status!=='cancelled'?`<button class="action-btn" onclick="openCancel(${s.id})"><i class="bi bi-x-circle"></i></button>`:''}</td></tr>`
            });
            settlementsBody.innerHTML = h ||
                '<tr><td colspan="10" class="text-center py-4 text-muted">No settlements found.</td></tr>';
            settlementCount.textContent = (r.total || 0) + ' settlements';
            renderPager('settlementPager', r.page, r.pages, 'loadSettlements');
            settlementInfo.textContent = `Page ${r.page} of ${r.pages}`;
            loadStats()
        })
    }

    function renderPager(id, page, pages, fn) {
        let h = `<button class="page-btn" ${page<=1?'disabled':''} onclick="${fn}(${page-1})">‹</button>`;
        for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) h +=
            `<button class="page-btn ${i===page?'active':''}" onclick="${fn}(${i})">${i}</button>`;
        h += `<button class="page-btn" ${page>=pages?'disabled':''} onclick="${fn}(${page+1})">›</button>`;
        document.getElementById(id).innerHTML = h
    }

    function toggleOne(cb, obj) {
        if (cb.checked) selected.set(String(obj.id), obj);
        else selected.delete(String(obj.id));
        updateBulk()
    }

    function toggleAll(cb) {
        document.querySelectorAll('.row-check').forEach(x => {
            x.checked = cb.checked;
            let obj = {
                id: x.value,
                driver_id: x.dataset.driver,
                amount: Number(x.dataset.amount || 0)
            };
            if (cb.checked) selected.set(String(obj.id), obj);
            else selected.delete(String(obj.id))
        });
        updateBulk()
    }

    function updateBulk() {
        let n = selected.size,
            amt = [...selected.values()].reduce((s, x) => s + Number(x.amount || 0), 0);
        bulkBar.classList.toggle('show', n > 0);
        bulkCount.textContent = n;
        bulkAmount.textContent = money(amt)
    }

    function clearSelection(clear = true) {
        selected.clear();
        document.querySelectorAll('.row-check,#selectAll').forEach(x => x.checked = false);
        updateBulk()
    }

    function useSelectionForSettlement() {
        if (!selected.size) {
            toast('Please select delivered orders first', 'error');
            return
        }
        let arr = [...selected.values()],
            firstDriver = String(arr[0].driver_id || ''),
            same = arr.every(x => String(x.driver_id) === firstDriver);
        if (!same) {
            toast('Ek settlement me sirf ek rider ke orders select karo.', 'error');
            return
        }
        let amount = arr.reduce((s, x) => s + Number(x.amount || 0), 0);
        settleIds.value = arr.map(x => x.id).join(',');
        settleDriver.value = firstDriver;
        settleDate.value = new Date().toISOString().slice(0, 10);
        cashAmount.value = amount.toFixed(2);
        onlineAmount.value = '0';
        adjustmentAmount.value = '0';
        settleNotes.value = '';
        selectedText.value = arr.length + ' orders selected';
        sumOrders.textContent = arr.length;
        sumCollectable.textContent = money(amount);
        updateSettleSummary();
        openModal('settleModal')
    }

    function updateSettleSummary() {
        let collect = Number(sumCollectable.textContent.replace(/[^0-9.-]/g, '') || 0),
            rec = Number(cashAmount.value || 0) + Number(onlineAmount.value || 0) + Number(adjustmentAmount.value || 0),
            diff = rec - collect;
        sumReceived.textContent = money(rec);
        sumDiff.textContent = (diff >= 0 ? '+' : '') + money(diff).replace('PKR ', 'PKR ')
    }
    ['cashAmount', 'onlineAmount', 'adjustmentAmount'].forEach(id => document.addEventListener('input', e => {
        if (e.target && e.target.id === id) updateSettleSummary()
    }));

    function saveSettlement() {
        let ids = settleIds.value.split(',').filter(Boolean);
        post('create_settlement', {
            assignment_ids: ids,
            driver_id: settleDriver.value,
            settlement_date: settleDate.value,
            cash_amount: cashAmount.value,
            online_amount: onlineAmount.value,
            adjustment_amount: adjustmentAmount.value,
            notes: settleNotes.value
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            if (r.success) {
                closeModal('settleModal');
                clearSelection();
                loadUnsettled(1);
                loadSettlements(1)
            }
        })
    }

    function viewSettlement(id) {
        get('settlement_detail', {
            id
        }).then(r => {
            if (!r.success) {
                toast(r.message, 'error');
                return
            }
            let s = r.settlement,
                rows = r.items || [];
            let html =
                `<div class="summary-box"><div class="summary-item"><div class="summary-label">Settlement</div><div class="summary-val">${esc(s.settlement_no)}</div></div><div class="summary-item"><div class="summary-label">Rider</div><div class="summary-val">${esc(s.driver_name||'-')}</div></div><div class="summary-item"><div class="summary-label">Received</div><div class="summary-val">${money(s.total_received)}</div></div><div class="summary-item"><div class="summary-label">Status</div><div class="summary-val">${esc(s.settlement_status)}</div></div></div><div class="table-wrap"><table class="custom-table"><thead><tr><th>Invoice</th><th>Customer</th><th>Order Amount</th><th>Collected</th><th>Payment</th><th>Date</th></tr></thead><tbody>`;
            rows.forEach(i => html +=
                `<tr><td><b>${esc(i.invoice_no)}</b></td><td>${esc(i.customer_name||'-')}<div class="small-muted">${esc(i.phone||'')}</div></td><td>${money(i.order_amount)}</td><td><b>${money(i.collected_amount)}</b></td><td>${esc(i.payment_method||'Cash')}</td><td>${esc(i.delivery_date||'')}</td></tr>`
                );
            html += '</tbody></table></div>';
            detailBody.innerHTML = html;
            openModal('detailModal')
        })
    }

    function openCancel(id) {
        cancelId.value = id;
        cancelReason.value = '';
        openModal('cancelModal')
    }

    function confirmCancel() {
        post('cancel_settlement', {
            id: cancelId.value,
            reason: cancelReason.value
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            if (r.success) {
                closeModal('cancelModal');
                loadSettlements(pageSet);
                loadUnsettled(1)
            }
        })
    }

    function refreshAll() {
        loadDrivers();
        loadStats();
        activeTab === 'unsettled' ? loadUnsettled(pageUn) : loadSettlements(pageSet)
    }
    document.addEventListener('DOMContentLoaded', () => {
        loadDrivers();
        loadStats();
        loadUnsettled(1)
    })
    </script>
</body>

</html>