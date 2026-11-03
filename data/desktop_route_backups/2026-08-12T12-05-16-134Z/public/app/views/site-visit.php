<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
error_reporting(E_ALL); ini_set('display_errors',1); mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$user_id=(int)($_SESSION['user_id']??0); $client_id=(int)($_SESSION['client_id']??0); $branch_id=(int)($_SESSION['branch_id']??($_SESSION['current_branch_id']??0));
if($client_id<=0){ die('Invalid client session'); }
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function tableExists(mysqli $c,string $t):bool{$s=$c->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");$s->bind_param('s',$t);$s->execute();$s->bind_result($n);$s->fetch();$s->close();return (int)$n>0;}
function columnExists(mysqli $c,string $t,string $col):bool{$s=$c->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->bind_param('ss',$t,$col);$s->execute();$s->bind_result($n);$s->fetch();$s->close();return (int)$n>0;}
function flash($type,$msg){header('Location: site-visit.php?msg_type='.urlencode($type).'&msg='.urlencode($msg));exit;}
function norm($v,$allowed,$d){$v=trim((string)$v);return in_array($v,$allowed,true)?$v:$d;}
function initials($name){$name=trim((string)$name); if($name==='')return 'NA'; $p=preg_split('/\s+/',$name); $o=''; foreach($p as $x){$o.=strtoupper(substr($x,0,1)); if(strlen($o)>=2)break;} return $o?:'NA';}
function badgeClass($s){return ['Scheduled'=>'badge-scheduled','Completed'=>'badge-completed','Cancelled'=>'badge-cancelled','No Show'=>'badge-noshow'][$s]??'badge-scheduled';}
function pageUrl($p,$base){$base['page']=$p; return 'site-visit.php?'.http_build_query($base);} 
function fmtDate($d){return $d?date('d M Y',strtotime($d)):'-';} function fmtTime($t){return $t?date('h:i A',strtotime($t)):'-';}
if(!tableExists($conn,'re_site_visits')){die("<div style='font-family:Arial;margin:25px;padding:20px;background:#fff3cd;border:1px solid #ffecb5;border-radius:10px'><h3>re_site_visits table missing</h3><p>Pehle re_site_visits_schema.sql phpMyAdmin me run karo.</p></div>");}
if(!tableExists($conn,'re_leads')){die("<div style='font-family:Arial;margin:25px;padding:20px;background:#fff3cd;border:1px solid #ffecb5;border-radius:10px'><h3>re_leads table missing</h3><p>Pehle Agency Leads table create karo.</p></div>");}
$statuses=['Scheduled','Completed','Cancelled','No Show']; $hasProps=tableExists($conn,'re_properties');
$leadAgent=columnExists($conn,'re_leads','assigned_agent_id')?'l.assigned_agent_id':(columnExists($conn,'re_leads','agent_id')?'l.agent_id':'NULL');
$agents=[];
if(tableExists($conn,'roles')){$sql="SELECT u.id,u.name,u.email,u.phone,u.role_id,COALESCE(r.name,'') role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.client_id=? AND u.status='active' AND (u.role_id=2 OR LOWER(COALESCE(r.name,'')) IN ('agent','staff','employee','sales agent','real estate agent','property agent')) ORDER BY u.name";$st=$conn->prepare($sql);$st->bind_param('i',$client_id);}else{$st=$conn->prepare("SELECT id,name,email,phone,role_id,'' role_name FROM users WHERE client_id=? AND status='active' AND role_id=2 ORDER BY name");$st->bind_param('i',$client_id);} $st->execute();$r=$st->get_result();while($x=$r->fetch_assoc())$agents[]=$x;$st->close();
$leads=[]; $st=$conn->prepare("SELECT l.id,l.lead_name,l.phone,l.email,l.status,$leadAgent assigned_agent_id FROM re_leads l WHERE l.client_id=? ORDER BY l.id DESC");$st->bind_param('i',$client_id);$st->execute();$r=$st->get_result();while($x=$r->fetch_assoc())$leads[]=$x;$st->close();
$properties=[]; if($hasProps){$title=columnExists($conn,'re_properties','title')?'title':(columnExists($conn,'re_properties','property_name')?'property_name':'id'); $st=$conn->prepare("SELECT id, `$title` title FROM re_properties WHERE client_id=? ORDER BY id DESC");$st->bind_param('i',$client_id);$st->execute();$r=$st->get_result();while($x=$r->fetch_assoc())$properties[]=$x;$st->close();}
if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=trim($_POST['action']??'');
  if($action==='save_visit'){
    $id=(int)($_POST['id']??0); $lead_id=(int)($_POST['lead_id']??0); $property_id=(int)($_POST['property_id']??0); $manual=trim($_POST['manual_property']??''); $agent_id=(int)($_POST['agent_id']??0);
    $date=trim($_POST['visit_date']??''); $time=trim($_POST['visit_time']??''); $meeting=trim($_POST['meeting_point']??''); $visitors=max(1,(int)($_POST['visitor_count']??1)); $status=norm($_POST['status']??'Scheduled',$statuses,'Scheduled');
    $feedback=trim($_POST['customer_feedback']??''); $remarks=trim($_POST['agent_remarks']??'');
    if($lead_id<=0)flash('danger','Lead is required.'); if($agent_id<=0)flash('danger','Agent is required.'); if($date===''||$time==='')flash('danger','Visit date/time required.');
    $prop=$property_id>0?$property_id:null;
    if($id>0){$st=$conn->prepare("UPDATE re_site_visits SET lead_id=?,property_id=?,manual_property=?,agent_id=?,visit_date=?,visit_time=?,meeting_point=?,visitor_count=?,status=?,customer_feedback=?,agent_remarks=? WHERE id=? AND client_id=?");$st->bind_param('iisisssisssii',$lead_id,$prop,$manual,$agent_id,$date,$time,$meeting,$visitors,$status,$feedback,$remarks,$id,$client_id);$st->execute();$st->close();$msg='Site visit updated successfully.';}else{$br=$branch_id>0?$branch_id:null;$st=$conn->prepare("INSERT INTO re_site_visits (client_id,branch_id,lead_id,property_id,manual_property,agent_id,visit_date,visit_time,meeting_point,visitor_count,status,customer_feedback,agent_remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");$st->bind_param('iiiisisssisssi',$client_id,$br,$lead_id,$prop,$manual,$agent_id,$date,$time,$meeting,$visitors,$status,$feedback,$remarks,$user_id);$st->execute();$st->close();$msg='Site visit scheduled successfully.';}
    $leadStatus=in_array($status,['Scheduled','Completed'],true)?'Site Visit':'Follow Up'; $parts=[];$vals=[];$types=''; if(columnExists($conn,'re_leads','status')){$parts[]='status=?';$vals[]=$leadStatus;$types.='s';} if(columnExists($conn,'re_leads','assigned_agent_id')){$parts[]='assigned_agent_id=?';$vals[]=$agent_id;$types.='i';}elseif(columnExists($conn,'re_leads','agent_id')){$parts[]='agent_id=?';$vals[]=$agent_id;$types.='i';}
    if(in_array($status,['Cancelled','No Show'],true)){ if(columnExists($conn,'re_leads','next_followup_at')){$parts[]='next_followup_at=?';$vals[]=date('Y-m-d H:i:s',strtotime('+1 day'));$types.='s';} elseif(columnExists($conn,'re_leads','next_followup_date')){$parts[]='next_followup_date=?';$vals[]=date('Y-m-d',strtotime('+1 day'));$types.='s';}}
    if($parts){$vals[]=$lead_id;$vals[]=$client_id;$types.='ii';$sql='UPDATE re_leads SET '.implode(',',$parts).' WHERE id=? AND client_id=?';$st=$conn->prepare($sql);$st->bind_param($types,...$vals);$st->execute();$st->close();}
    flash('success',$msg);
  }
  if($action==='delete_visit'){$id=(int)($_POST['id']??0); if($id<=0)flash('danger','Invalid visit id.'); $st=$conn->prepare('DELETE FROM re_site_visits WHERE id=? AND client_id=?');$st->bind_param('ii',$id,$client_id);$st->execute();$st->close();flash('success','Site visit deleted successfully.');}
}
$search=trim($_GET['search']??''); $statusF=trim($_GET['status']??''); $agentF=(int)($_GET['agent_id']??0); $propF=(int)($_GET['property_id']??0); $from=trim($_GET['date_from']??''); $to=trim($_GET['date_to']??''); $page=max(1,(int)($_GET['page']??1)); $per=10; $off=($page-1)*$per;
if(isset($_GET['export'])&&$_GET['export']==='csv'){$where=' WHERE v.client_id=? ';$params=[$client_id];$types='i'; if($search!==''){$where.=" AND (l.lead_name LIKE ? OR l.phone LIKE ? OR u.name LIKE ? OR v.manual_property LIKE ? OR v.meeting_point LIKE ?)";$like="%$search%";array_push($params,$like,$like,$like,$like,$like);$types.='sssss';} if($statusF!==''){$where.=' AND v.status=?';$params[]=$statusF;$types.='s';} if($agentF>0){$where.=' AND v.agent_id=?';$params[]=$agentF;$types.='i';} if($propF>0){$where.=' AND v.property_id=?';$params[]=$propF;$types.='i';} if($from!==''){$where.=' AND v.visit_date>=?';$params[]=$from;$types.='s';} if($to!==''){$where.=' AND v.visit_date<=?';$params[]=$to;$types.='s';} $propSel=$hasProps?'COALESCE(p.title,v.manual_property,\'\')':'COALESCE(v.manual_property,\'\')'; $sql="SELECT v.id,l.lead_name,l.phone,$propSel property_title,v.visit_date,v.visit_time,v.status,u.name agent_name,v.meeting_point,v.customer_feedback,v.agent_remarks FROM re_site_visits v LEFT JOIN re_leads l ON l.id=v.lead_id AND l.client_id=v.client_id LEFT JOIN users u ON u.id=v.agent_id ".($hasProps?'LEFT JOIN re_properties p ON p.id=v.property_id AND p.client_id=v.client_id':'')." $where ORDER BY v.visit_date DESC,v.visit_time DESC,v.id DESC"; $st=$conn->prepare($sql);$st->bind_param($types,...$params);$st->execute();$res=$st->get_result(); header('Content-Type:text/csv;charset=utf-8'); header('Content-Disposition:attachment;filename=site_visits_'.date('Ymd_His').'.csv'); $out=fopen('php://output','w'); fputcsv($out,['ID','Lead','Phone','Property','Date','Time','Status','Agent','Meeting Point','Customer Feedback','Agent Remarks']); while($x=$res->fetch_assoc())fputcsv($out,$x); fclose($out); exit;}
function visitCount(mysqli $c,int $client_id,string $w=''):int{$st=$c->prepare("SELECT COUNT(*) FROM re_site_visits WHERE client_id=? $w");$st->bind_param('i',$client_id);$st->execute();$st->bind_result($n);$st->fetch();$st->close();return (int)$n;}
$totalVisits=visitCount($conn,$client_id); $scheduledVisits=visitCount($conn,$client_id,"AND status='Scheduled'"); $completedVisits=visitCount($conn,$client_id,"AND status='Completed'"); $issueVisits=visitCount($conn,$client_id,"AND status IN ('Cancelled','No Show')");
$where=' WHERE v.client_id=? ';$params=[$client_id];$types='i'; if($search!==''){$where.=" AND (l.lead_name LIKE ? OR l.phone LIKE ? OR u.name LIKE ? OR v.manual_property LIKE ? OR v.meeting_point LIKE ?)";$like="%$search%";array_push($params,$like,$like,$like,$like,$like);$types.='sssss';} if($statusF!==''){$where.=' AND v.status=?';$params[]=$statusF;$types.='s';} if($agentF>0){$where.=' AND v.agent_id=?';$params[]=$agentF;$types.='i';} if($propF>0){$where.=' AND v.property_id=?';$params[]=$propF;$types.='i';} if($from!==''){$where.=' AND v.visit_date>=?';$params[]=$from;$types.='s';} if($to!==''){$where.=' AND v.visit_date<=?';$params[]=$to;$types.='s';}
$cntSql="SELECT COUNT(*) FROM re_site_visits v LEFT JOIN re_leads l ON l.id=v.lead_id AND l.client_id=v.client_id LEFT JOIN users u ON u.id=v.agent_id $where"; $st=$conn->prepare($cntSql);$st->bind_param($types,...$params);$st->execute();$st->bind_result($totalFiltered);$st->fetch();$st->close(); $pages=max(1,(int)ceil($totalFiltered/$per)); if($page>$pages){$page=$pages;$off=($page-1)*$per;}
$propSel=$hasProps?'COALESCE(p.title,v.manual_property,\'\')':'COALESCE(v.manual_property,\'\')'; $sql="SELECT v.*,l.lead_name,l.phone lead_phone,l.email lead_email,u.name agent_name,$propSel property_title FROM re_site_visits v LEFT JOIN re_leads l ON l.id=v.lead_id AND l.client_id=v.client_id LEFT JOIN users u ON u.id=v.agent_id ".($hasProps?'LEFT JOIN re_properties p ON p.id=v.property_id AND p.client_id=v.client_id':'')." $where ORDER BY v.visit_date DESC,v.visit_time DESC,v.id DESC LIMIT ? OFFSET ?"; $params2=array_merge($params,[$per,$off]); $types2=$types.'ii'; $st=$conn->prepare($sql);$st->bind_param($types2,...$params2);$st->execute();$res=$st->get_result();$visits=[];while($x=$res->fetch_assoc())$visits[]=$x;$st->close();
$visitsJson=json_encode($visits,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); $leadsJson=json_encode($leads,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); $msg=trim($_GET['msg']??''); $msgType=trim($_GET['msg_type']??'success'); $base=$_GET; unset($base['page']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Visits</title><?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
    <style>
    body {
        margin: 0;
        background: #eef2f7;
        font-family: Segoe UI, Tahoma, sans-serif;
        color: #334155
    }

    .container-scroll {
        display: flex;
        min-height: 100vh
    }

    .sidebar {
        width: 270px;
        flex-shrink: 0
    }

    .main-panel {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
        background: #eef2f7
    }

    .topbar {
        min-height: 70px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        background: #fff
    }

    .content-wrapper {
        padding: 20px;
        flex: 1
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 20px
    }

    .page-title h1 {
        font-size: 28px;
        font-weight: 900;
        color: #1e293b;
        margin: 0
    }

    .crumb {
        display: flex;
        gap: 6px;
        margin-top: 6px;
        color: #94a3b8;
        font-size: 12.5px
    }

    .crumb .current {
        color: #b8860b;
        font-weight: 800
    }

    .actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap
    }

    .btnx {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 900;
        text-decoration: none;
        border: none
    }

    .btn-outline-x {
        background: #fff;
        color: #475569;
        border: 1.5px solid #e2e8f0
    }

    .btn-gold {
        background: linear-gradient(180deg, #f5c742, #d8a500);
        color: #111
    }

    .stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 18px
    }

    .stat {
        border-radius: 15px;
        padding: 18px;
        color: #fff;
        min-height: 108px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 14px 28px rgba(15, 23, 42, .10)
    }

    .stat:after {
        content: '';
        position: absolute;
        width: 118px;
        height: 118px;
        border-radius: 50%;
        right: -22px;
        top: -22px;
        background: rgba(255, 255, 255, .12)
    }

    .stat-title {
        position: relative;
        z-index: 1;
        font-size: 13px;
        font-weight: 900
    }

    .stat-sub {
        position: relative;
        z-index: 1;
        margin-top: 7px;
        font-size: 12px;
        opacity: .9
    }

    .stat-val {
        position: absolute;
        z-index: 1;
        right: 18px;
        bottom: 15px;
        font-size: 32px;
        font-weight: 900
    }

    .red {
        background: linear-gradient(135deg, #9f2430, #c94b57)
    }

    .gold {
        background: linear-gradient(135deg, #b27b16, #d8a33a)
    }

    .green {
        background: linear-gradient(135deg, #386c58, #4c9075)
    }

    .orange {
        background: linear-gradient(135deg, #b87a16, #dc9b2d)
    }

    .cardx {
        background: #fff;
        border-radius: 16px;
        border: 1px solid #eef2f7;
        box-shadow: 0 4px 20px rgba(15, 23, 42, .06)
    }

    .filter {
        padding: 16px 20px;
        margin-bottom: 20px
    }

    .filter-row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center
    }

    .fg {
        position: relative;
        flex: 1;
        min-width: 150px
    }

    .fg .material-symbols-outlined {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #a3afcf;
        font-size: 17px
    }

    .inp {
        width: 100%;
        height: 42px;
        background: #f4f7fe;
        border: 1px solid #e0e5f2;
        border-radius: 10px;
        padding: 0 12px 0 38px;
        font-size: 13px;
        color: #2b3674;
        font-weight: 800
    }

    .btn-filter,
    .btn-reset {
        height: 42px;
        padding: 0 18px;
        border-radius: 10px;
        color: #fff;
        font-size: 13px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        border: none
    }

    .btn-filter {
        background: linear-gradient(180deg, #f5c742, #d8a500);
        color: #111
    }

    .btn-reset {
        background: linear-gradient(180deg, #4f9e4a, #3e7a3a)
    }

    .thead {
        padding: 17px 20px;
        border-bottom: 1px solid #f0f2f9;
        display: flex;
        justify-content: space-between;
        align-items: center
    }

    .table-wrap {
        overflow-x: auto
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1100px
    }

    th {
        background: #f8fafc;
        color: #64748b;
        font-size: 12px;
        font-weight: 900;
        padding: 14px 16px;
        border-bottom: 2px solid #edf2f7
    }

    td {
        padding: 14px 16px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px
    }

    .lead {
        font-weight: 900;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 10px
    }

    .av {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-weight: 900
    }

    .muted {
        color: #64748b;
        font-size: 12.5px
    }

    .chip {
        background: #f3f4f8;
        color: #5a6280;
        padding: 4px 9px;
        border-radius: 7px;
        font-size: 12px;
        font-weight: 900
    }

    .agent {
        display: flex;
        align-items: center;
        gap: 7px;
        font-weight: 900
    }

    .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #d4a017
    }

    .badge-status {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 900
    }

    .badge-status:before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
        margin-right: 6px
    }

    .badge-scheduled {
        background: #dbeafe;
        color: #1d4ed8
    }

    .badge-completed {
        background: #dcfce7;
        color: #15803d
    }

    .badge-cancelled {
        background: #fee2e2;
        color: #b91c1c
    }

    .badge-noshow {
        background: #ffedd5;
        color: #c2410c
    }

    .feed {
        max-width: 190px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        display: block;
        color: #64748b
    }

    .no {
        color: #94a3b8;
        font-style: italic
    }

    .act {
        display: flex;
        gap: 4px
    }

    .ico {
        width: 31px;
        height: 31px;
        border-radius: 8px;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        border: none
    }

    .view {
        color: #3b82f6
    }

    .edit {
        color: #b8860b
    }

    .del {
        color: #ef4444
    }

    .tfoot {
        padding: 14px 20px;
        border-top: 1px solid #f0f2f9;
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap
    }

    .pg {
        display: flex;
        gap: 4px
    }

    .page-btn {
        min-width: 32px;
        height: 32px;
        border-radius: 7px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        font-size: 12px;
        font-weight: 900;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 9px;
        text-decoration: none
    }

    .page-btn.active {
        background: #111c44;
        border-color: #111c44;
        color: #fff
    }

    .page-btn.disabled {
        opacity: .45;
        pointer-events: none
    }

    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .58);
        z-index: 999999;
        align-items: flex-start;
        justify-content: center;
        padding: 10px 18px;
        overflow-y: auto
    }

    .modal-overlay.open {
        display: flex
    }

    .modal-box {
        background: #fff;
        border-radius: 14px;
        width: 100%;
        max-width: 760px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, .26);
        overflow: hidden;
        margin: 8px auto 18px
    }

    .mh {
        background: linear-gradient(135deg, #111, #1A1A1A);
        padding: 12px 18px;
        min-height: 54px;
        display: flex;
        justify-content: space-between;
        align-items: center
    }

    .mh-title {
        font-size: 16px;
        font-weight: 900;
        color: #fff
    }

    .mh-sub {
        font-size: 11px;
        color: #999
    }

    .close {
        width: 34px;
        height: 34px;
        background: rgba(255, 255, 255, .08);
        border: 1px solid rgba(255, 255, 255, .1);
        border-radius: 8px;
        color: #aaa
    }

    .mbody {
        padding: 14px 18px;
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px 12px;
        max-height: calc(100vh - 145px);
        overflow-y: auto
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 4px
    }

    .form-group.full {
        grid-column: span 2
    }

    .label {
        font-size: 10.5px;
        font-weight: 900;
        color: #374151
    }

    .ctrl {
        width: 100%;
        height: 38px;
        border: 1.5px solid #E5E7EB;
        border-radius: 9px;
        padding: 7px 10px;
        font-size: 12.5px;
        background: #FAFAFA
    }

    textarea.ctrl {
        height: 58px;
        min-height: 58px;
        resize: vertical
    }

    .field-error {
        border-color: #ef4444 !important;
        background: #fff5f5 !important;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, .12) !important
    }

    .mfoot {
        padding: 10px 18px;
        border-top: 1px solid #E5E7EB;
        display: flex;
        justify-content: space-between;
        gap: 10px;
        background: #FAFAFA;
        flex-wrap: wrap
    }

    .btn-save {
        background: linear-gradient(135deg, #d4a017, #b8860b);
        color: #000;
        padding: 9px 24px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 900
    }

    .btn-cancel {
        background: #F3F4F6;
        border: 1.5px solid #E5E7EB;
        color: #6B7280;
        padding: 9px 20px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 900
    }

    .view-grid {
        padding: 22px 24px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px
    }

    .full {
        grid-column: span 2
    }

    .vl {
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        color: #94a3b8
    }

    .vv {
        font-size: 13.5px;
        font-weight: 800;
        color: #1e293b
    }

    @media(max-width:1280px) {
        .stats {
            grid-template-columns: repeat(2, 1fr)
        }
    }

    @media(max-width:900px) {

        .mbody,
        .view-grid {
            grid-template-columns: 1fr
        }

        .full {
            grid-column: span 1
        }

        .sidebar {
            width: 240px
        }
    }

    @media(max-width:640px) {
        .stats {
            grid-template-columns: 1fr
        }

        .filter-row {
            flex-direction: column
        }

        .fg,
        .btn-filter,
        .btn-reset {
            width: 100%
        }
    }


/* =====================================================
   SITE-VISIT.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
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

/* ================= TOPBAR / PAGE BACKGROUND ================= */
.topbar{
    min-height:70px !important;
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
.page-title h1{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.crumb,
.crumb a,
.muted,
.no,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.crumb .current{
    color:#c4b5fd !important;
    font-weight:900 !important;
}

.actions{
    gap:10px !important;
}

.btnx{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    transition:.22s ease !important;
}

.btn-gold{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
}

.btn-outline-x{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btnx:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
    color:#fff !important;
}

/* ================= STATS CARDS ================= */
.stats{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat{
    min-height:138px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate;
    transition:.25s ease !important;
}

.stat::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
}

.stat:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.red{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.gold{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.orange{background:linear-gradient(135deg,#be123c,#ef4444) !important;}

.stat-title{
    font-size:15px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
}

.stat-sub{
    color:rgba(255,255,255,.82) !important;
    font-weight:700 !important;
}

.stat-val{
    font-size:32px !important;
    font-weight:900 !important;
    color:#fff !important;
}

/* ================= GLASS PANELS ================= */
.cardx,
.filter{
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

.cardx:hover,
.filter:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter{
    padding:14px !important;
    margin-bottom:18px !important;
}

/* ================= FILTER INPUTS ================= */
.filter-row{
    gap:10px !important;
}

.fg .material-symbols-outlined{
    color:#94a3b8 !important;
}

.inp{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.inp::placeholder{
    color:#94a3b8 !important;
}

.inp:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.inp option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.btn-filter,
.btn-reset{
    min-height:46px !important;
    height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
    text-decoration:none !important;
    transition:.22s ease !important;
}

.btn-filter{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-reset{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.btn-filter:hover,
.btn-reset:hover{
    transform:translateY(-1px);
    color:#fff !important;
}

/* ================= TABLE HEADER ================= */
.thead{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
}

.thead b{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.card-action{
    color:#c4b5fd !important;
    font-weight:900 !important;
    text-decoration:none !important;
}

.chip{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
    border-radius:999px !important;
    font-weight:900 !important;
}

/* ================= TABLE DARK GLASS ================= */
.table-wrap,
table,
tbody,
tbody tr{
    background:transparent !important;
}

table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:14px 12px !important;
    white-space:nowrap;
}

td{
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

tbody tr:hover,
tbody tr:hover td,
tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

tbody tr:focus,
tbody tr:active,
tbody td:focus,
tbody td:active,
tbody td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

tbody td.text-center,
tbody td.py-4,
tbody td[colspan]{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

.lead,
.agent{
    color:#f8fafc !important;
}

.av{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    box-shadow:0 10px 22px rgba(109,76,255,.24) !important;
}

.dot{
    background:#a78bfa !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
}

.feed{
    color:#94a3b8 !important;
}

/* ================= BADGES ================= */
.badge-status{
    border:1px solid rgba(148,163,184,.18) !important;
}

.badge-scheduled{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border-color:rgba(59,130,246,.24) !important;
}

.badge-completed{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

.badge-cancelled{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

.badge-noshow{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

/* ================= ACTION BUTTONS ================= */
.ico{
    width:34px !important;
    height:34px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.62) !important;
    transition:.22s ease !important;
}

.view{color:#60a5fa !important;}
.edit{color:#fbbf24 !important;}
.del{color:#f87171 !important;}

.ico:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.20) !important;
    color:#fff !important;
}

/* ================= PAGINATION ================= */
.tfoot{
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

/* ================= ALERT ================= */
.alert{
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.58)) !important;
    color:#e5e7eb !important;
    box-shadow:0 16px 36px rgba(0,0,0,.26);
}

.alert-success{
    border-color:rgba(34,197,94,.25) !important;
}

.alert-danger{
    border-color:rgba(239,68,68,.25) !important;
}

/* ================= SCROLLBAR ================= */
.table-wrap::-webkit-scrollbar,
.mbody::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-wrap::-webkit-scrollbar-track,
.mbody::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb,
.mbody::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb:hover,
.mbody::-webkit-scrollbar-thumb:hover,
.content-wrapper::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

/* =====================================================
   SITE VISIT POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

.modal-overlay{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
    align-items:flex-start !important;
    justify-content:center !important;
    padding:18px 20px !important;
}

.modal-overlay.open{
    display:flex !important;
}

.modal-box{
    width:100% !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    max-height:92vh !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#schedule-modal .modal-box{
    max-width:760px !important;
}

#view-modal .modal-box{
    max-width:640px !important;
}

#confirm-modal .modal-box{
    max-width:440px !important;
}

.mh{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

.mh-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

.mh-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

.mh-sub{
    color:#94a3b8 !important;
    font-size:11.5px !important;
    font-weight:700 !important;
    margin-top:3px !important;
}

.close{
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

.close::before,
.close::after{
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

.close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

.close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

.close:hover{
    background:#ef4444 !important;
}

.mbody{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    gap:12px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

.form-group{
    gap:5px !important;
}

.label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.ctrl{
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
    outline:none !important;
}

textarea.ctrl{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

.ctrl::placeholder{
    color:#94a3b8 !important;
}

.ctrl:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

select.ctrl option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

#form-error{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.28) !important;
    border-radius:10px !important;
}

.field-error{
    border-color:#ef4444 !important;
    background:rgba(127,29,29,.26) !important;
    box-shadow:0 0 0 3px rgba(239,68,68,.16) !important;
}

.mfoot{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    color:#94a3b8 !important;
}

.mfoot .muted{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:700 !important;
}

.btn-cancel,
#view-modal .btn-cancel,
#confirm-modal .btn-cancel{
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

.btn-save{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-cancel:hover,
.btn-save:hover{
    transform:translateY(-1px);
}

/* Confirm delete popup */
#confirm-modal h3{
    color:#f8fafc !important;
    font-weight:900 !important;
}

#confirm-modal .modal-box > div:first-child{
    background:transparent !important;
}

#deleteForm .btnx{
    min-height:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    padding:7px 18px !important;
}

/* View modal boxes */
.view-grid{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
}

.view-grid .vl{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
    margin-bottom:5px !important;
}

.view-grid .vv{
    background:rgba(15,23,42,.62) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    font-size:13.5px !important;
    font-weight:900 !important;
    min-height:44px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

@media(max-width:1280px){
    .stats{
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
        display:flex !important;
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

    .mbody,
    .view-grid{
        grid-template-columns:1fr !important;
    }

    .form-group.full,
    .full,
    #form-error{
        grid-column:span 1 !important;
    }
}

@media(max-width:640px){
    .stats{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .actions,
    .btnx{
        width:100% !important;
    }

    .btnx{
        justify-content:center !important;
    }

    .page-title h1{
        font-size:24px !important;
    }

    .filter-row{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .fg,
    .btn-filter,
    .btn-reset{
        width:100% !important;
    }

    #schedule-modal .modal-box,
    #view-modal .modal-box,
    #confirm-modal .modal-box{
        width:96vw !important;
        max-width:96vw !important;
    }

    .mfoot{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .mfoot > div{
        width:100% !important;
        display:flex !important;
        flex-direction:column !important;
        gap:8px !important;
    }

    .btn-cancel,
    .btn-save,
    #deleteForm .btnx{
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
            <div class="content-wrapper">
                <?php if($msg!==''):?><div class="alert alert-<?=e($msgType)?> alert-dismissible fade show">
                    <?=e($msg)?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif;?>
                <div class="page-header">
                    <div class="page-title">
                        <h1>Site Visits</h1>
                        <div class="crumb"><a href="dashboard.php">Home</a><span>›</span><a href="agency-lead.php">Real
                                Estate</a><span>›</span><span class="current">Site Visits</span></div>
                    </div>
                    <div class="actions"><a class="btnx btn-outline-x"
                            href="site-visit.php?<?=e(http_build_query(array_merge($_GET,['export'=>'csv'])))?>"><span
                                class="material-symbols-outlined">download</span> Export</a><button
                            class="btnx btn-gold" onclick="openScheduleModal()"><span
                                class="material-symbols-outlined">add</span> Schedule Visit</button></div>
                </div>
                <div class="stats">
                    <div class="stat red">
                        <div class="stat-title">Total Visits</div>
                        <div class="stat-sub">All site visit records</div>
                        <div class="stat-val"><?=$totalVisits?></div>
                    </div>
                    <div class="stat gold">
                        <div class="stat-title">Scheduled</div>
                        <div class="stat-sub">Upcoming visits</div>
                        <div class="stat-val"><?=$scheduledVisits?></div>
                    </div>
                    <div class="stat green">
                        <div class="stat-title">Completed</div>
                        <div class="stat-sub">Successful visits</div>
                        <div class="stat-val"><?=$completedVisits?></div>
                    </div>
                    <div class="stat orange">
                        <div class="stat-title">No Show / Cancelled</div>
                        <div class="stat-sub">Need follow-up</div>
                        <div class="stat-val"><?=$issueVisits?></div>
                    </div>
                </div>
                <form class="cardx filter" method="GET">
                    <div class="filter-row">
                        <div class="fg" style="flex:1.4;min-width:220px"><span
                                class="material-symbols-outlined">search</span><input class="inp" name="search"
                                placeholder="Search client, phone, agent..." value="<?=e($search)?>"></div>
                        <div class="fg"><span class="material-symbols-outlined">task_alt</span><select class="inp"
                                name="status">
                                <option value="">All Status</option><?php foreach($statuses as $s):?><option
                                    value="<?=e($s)?>" <?=$statusF===$s?'selected':''?>><?=e($s)?></option>
                                <?php endforeach;?>
                            </select></div>
                        <div class="fg"><span class="material-symbols-outlined">support_agent</span><select class="inp"
                                name="agent_id">
                                <option value="">All Agents</option><?php foreach($agents as $a):?><option
                                    value="<?=(int)$a['id']?>" <?=$agentF===(int)$a['id']?'selected':''?>>
                                    <?=e($a['name'])?></option><?php endforeach;?>
                            </select></div>
                        <div class="fg"><span class="material-symbols-outlined">apartment</span><select class="inp"
                                name="property_id">
                                <option value="">All Properties</option><?php foreach($properties as $p):?><option
                                    value="<?=(int)$p['id']?>" <?=$propF===(int)$p['id']?'selected':''?>>
                                    <?=e($p['title'])?></option><?php endforeach;?>
                            </select></div>
                        <div class="fg"><span class="material-symbols-outlined">calendar_today</span><input class="inp"
                                type="date" name="date_from" value="<?=e($from)?>"></div>
                        <div class="fg"><span class="material-symbols-outlined">event</span><input class="inp"
                                type="date" name="date_to" value="<?=e($to)?>"></div><button class="btn-filter"><span
                                class="material-symbols-outlined">filter_list</span>Filter</button><a class="btn-reset"
                            href="site-visit.php"><span class="material-symbols-outlined">restart_alt</span>Reset</a>
                    </div>
                </form>
                <div class="cardx">
                    <div class="thead"><b>Site Visit Records <span class="chip"><?=(int)$totalFiltered?>
                                Total</span></b><a href="site-visit.php" class="card-action">Refresh</a></div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Client Name</th>
                                    <th>Phone</th>
                                    <th>Property / Project</th>
                                    <th>Visit Date</th>
                                    <th>Visit Time</th>
                                    <th>Status</th>
                                    <th>Agent</th>
                                    <th>Feedback</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody><?php if(empty($visits)):?><tr>
                                    <td colspan="10" style="text-align:center;padding:45px;color:#94a3b8">No site visits
                                        found matching your filters.</td>
                                </tr><?php else: foreach($visits as $i=>$v):?><tr>
                                    <td class="muted"><?=($off+$i+1)?></td>
                                    <td>
                                        <div class="lead">
                                            <div class="av"><?=e(initials($v['lead_name']??''))?></div>
                                            <?=e($v['lead_name']?:'Unknown Lead')?>
                                        </div>
                                    </td>
                                    <td><span class="muted"><?=e($v['lead_phone']??'')?></span></td>
                                    <td><span class="chip"><?=e($v['property_title']?:'Manual / Not Selected')?></span>
                                    </td>
                                    <td><span class="muted"><?=e(fmtDate($v['visit_date']??''))?></span></td>
                                    <td><span class="muted"><?=e(fmtTime($v['visit_time']??''))?></span></td>
                                    <td><span
                                            class="badge-status <?=e(badgeClass($v['status']??''))?>"><?=e($v['status']??'')?></span>
                                    </td>
                                    <td>
                                        <div class="agent"><span
                                                class="dot"></span><?=e($v['agent_name']?:'Unassigned')?></div>
                                    </td>
                                    <td><?php if(!empty($v['customer_feedback'])):?><span class="feed"
                                            title="<?=e($v['customer_feedback'])?>"><?=e($v['customer_feedback'])?></span><?php elseif(!empty($v['agent_remarks'])):?><span
                                            class="feed"
                                            title="<?=e($v['agent_remarks'])?>"><?=e($v['agent_remarks'])?></span><?php else:?><span
                                            class="no">No feedback</span><?php endif;?></td>
                                    <td>
                                        <div class="act"><button class="ico view"
                                                onclick="viewVisit(<?=(int)$v['id']?>)"><span
                                                    class="material-symbols-outlined">visibility</span></button><button
                                                class="ico edit" onclick="editVisit(<?=(int)$v['id']?>)"><span
                                                    class="material-symbols-outlined">edit</span></button><button
                                                class="ico del" onclick="confirmDelete(<?=(int)$v['id']?>)"><span
                                                    class="material-symbols-outlined">delete</span></button></div>
                                    </td>
                                </tr><?php endforeach; endif;?></tbody>
                        </table>
                    </div>
                    <div class="tfoot">
                        <div class="muted">Showing <?=$totalFiltered>0?$off+1:0?> to <?=min($off+$per,$totalFiltered)?>
                            of <?=$totalFiltered?> entries</div>
                        <div class="pg"><a class="page-btn <?=$page<=1?'disabled':''?>"
                                href="<?=e(pageUrl($page-1,$base))?>">‹
                                Prev</a><?php for($p=1;$p<=$pages;$p++): if($p===1||$p===$pages||abs($p-$page)<=1):?><a
                                class="page-btn <?=$p===$page?'active':''?>"
                                href="<?=e(pageUrl($p,$base))?>"><?=$p?></a><?php elseif(abs($p-$page)===2):?><span
                                class="page-btn"
                                style="pointer-events:none;border:none;background:transparent">...</span><?php endif; endfor;?><a
                                class="page-btn <?=$page>=$pages?'disabled':''?>"
                                href="<?=e(pageUrl($page+1,$base))?>">Next ›</a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-overlay" id="schedule-modal">
        <div class="modal-box">
            <div class="mh">
                <div>
                    <div class="mh-title" id="modal-title">Schedule Visit</div>
                    <div class="mh-sub">Create or update property site visit</div>
                </div><button class="close" onclick="closeScheduleModal()">×</button>
            </div>
            <form method="POST" id="visitForm" novalidate onsubmit="return validateVisitForm()"><input type="hidden"
                    name="action" value="save_visit"><input type="hidden" name="id" id="m-id">
                <div class="mbody">
                    <div class="form-error" id="form-error"
                        style="display:none;grid-column:span 2;background:#fee2e2;color:#7f1d1d;border:1px solid #fecaca;border-radius:9px;padding:8px 10px;font-size:12.5px;font-weight:800">
                    </div>
                    <div class="form-group"><label class="label">Lead / Client *</label><select class="ctrl"
                            name="lead_id" id="m-lead" required onchange="autoSelectLeadAgent()">
                            <option value="">Select Lead</option><?php foreach($leads as $l):?><option
                                value="<?=(int)$l['id']?>">
                                <?=e($l['lead_name'])?><?=!empty($l['phone'])?' - '.e($l['phone']):''?></option>
                            <?php endforeach;?>
                        </select></div>
                    <div class="form-group"><label class="label">Agent *</label><select class="ctrl" name="agent_id"
                            id="m-agent" required>
                            <option value="">Select Agent</option><?php foreach($agents as $a):?><option
                                value="<?=(int)$a['id']?>">
                                <?=e($a['name'])?><?=!empty($a['role_name'])?' - '.e($a['role_name']):''?></option>
                            <?php endforeach;?>
                        </select></div>
                    <div class="form-group"><label class="label">Property / Project</label><select class="ctrl"
                            name="property_id" id="m-property">
                            <option value="">Select Property</option><?php foreach($properties as $p):?><option
                                value="<?=(int)$p['id']?>"><?=e($p['title'])?></option><?php endforeach;?>
                        </select></div>
                    <div class="form-group"><label class="label">Manual Property Name</label><input class="ctrl"
                            name="manual_property" id="m-manual" placeholder="DHA Phase 5 / Bahria Town"></div>
                    <div class="form-group"><label class="label">Status *</label><select class="ctrl" name="status"
                            id="m-status" required>
                            <option value="">Select Status</option><?php foreach($statuses as $s):?><option
                                value="<?=e($s)?>"><?=e($s)?></option><?php endforeach;?>
                        </select></div>
                    <div class="form-group"><label class="label">Visit Date *</label><input class="ctrl" type="date"
                            name="visit_date" id="m-date" required></div>
                    <div class="form-group"><label class="label">Visit Time *</label><input class="ctrl" type="time"
                            name="visit_time" id="m-time" required></div>
                    <div class="form-group"><label class="label">Visitors Count</label><input class="ctrl" type="number"
                            min="1" name="visitor_count" id="m-count" value="1"></div>
                    <div class="form-group full"><label class="label">Meeting Point</label><input class="ctrl"
                            name="meeting_point" id="m-meeting" placeholder="Office / Site Gate / Main Boulevard"></div>
                    <div class="form-group full"><label class="label">Customer Feedback</label><textarea class="ctrl"
                            name="customer_feedback" id="m-feedback"></textarea></div>
                    <div class="form-group full"><label class="label">Agent Remarks</label><textarea class="ctrl"
                            name="agent_remarks" id="m-remarks"></textarea></div>
                </div>
                <div class="mfoot"><span class="muted">Site visit activity will be saved in re_site_visits.</span>
                    <div><button type="button" class="btn-cancel" onclick="closeScheduleModal()">Cancel</button> <button
                            class="btn-save">Save Visit</button></div>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-overlay" id="view-modal">
        <div class="modal-box" style="max-width:560px">
            <div class="mh">
                <div>
                    <div class="mh-title">Visit Details</div>
                    <div class="mh-sub">Full site visit information</div>
                </div><button class="close" onclick="closeViewModal()">×</button>
            </div>
            <div class="view-grid" id="view-content"></div>
            <div class="mfoot"><span></span><button class="btn-cancel" onclick="closeViewModal()">Close</button></div>
        </div>
    </div>
    <div class="modal-overlay" id="confirm-modal">
        <div class="modal-box" style="max-width:430px">
            <div style="padding:28px;text-align:center">
                <h3>Delete Visit Record</h3>
                <p class="muted">Are you sure you want to delete this site visit?</p>
            </div>
            <div class="mfoot" style="justify-content:center"><button class="btn-cancel"
                    onclick="closeConfirmModal()">Cancel</button>
                <form method="POST" id="deleteForm"><input type="hidden" name="action" value="delete_visit"><input
                        type="hidden" name="id" id="delete-id"><button class="btnx"
                        style="background:#fee2e2;color:#b91c1c">Delete</button></form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const visits = <?=$visitsJson?:'[]'?>,
        leads = <?=$leadsJson?:'[]'?>;
    const $ = id => document.getElementById(id);

    function clearFormErrors() {
        document.querySelectorAll('#visitForm .field-error').forEach(el => el.classList.remove('field-error'));
        const box = $('form-error');
        if (box) {
            box.style.display = 'none';
            box.innerHTML = '';
        }
    }

    function showFormError(msg, el) {
        const box = $('form-error');
        if (box) {
            box.innerHTML = msg;
            box.style.display = 'block';
        }
        if (el) {
            el.classList.add('field-error');
            el.focus();
        }
        const body = document.querySelector('#schedule-modal .mbody');
        if (body) body.scrollTop = 0;
    }

    function validateVisitForm() {
        clearFormErrors();

        const required = [
            ['m-lead', 'Lead / Client select karna zaroori hai.'],
            ['m-agent', 'Agent select karna zaroori hai.'],
            ['m-status', 'Status select karna zaroori hai.'],
            ['m-date', 'Visit Date select karna zaroori hai.'],
            ['m-time', 'Visit Time select karna zaroori hai.']
        ];

        for (const item of required) {
            const el = $(item[0]);
            if (!el || String(el.value || '').trim() === '') {
                showFormError(item[1], el);
                return false;
            }
        }
        return true;
    }

    function openScheduleModal() {
        $('visitForm').reset();
        clearFormErrors();
        $('m-id').value = '';
        $('modal-title').innerText = 'Schedule Visit';
        $('m-status').value = 'Scheduled';
        $('m-count').value = 1;
        $('schedule-modal').classList.add('open');
        setTimeout(() => {
            const body = document.querySelector('#schedule-modal .mbody');
            if (body) body.scrollTop = 0;
        }, 0);
    }

    function closeScheduleModal() {
        $('schedule-modal').classList.remove('open');
        clearFormErrors();
    }

    function autoSelectLeadAgent() {
        let id = parseInt($('m-lead').value || 0),
            l = leads.find(x => parseInt(x.id) === id);
        if (l && l.assigned_agent_id) $('m-agent').value = l.assigned_agent_id;
    }

    function editVisit(id) {
        let v = visits.find(x => parseInt(x.id) === parseInt(id));
        if (!v) return alert('Visit not found');
        $('visitForm').reset();
        $('modal-title').innerText = 'Edit Visit';
        $('m-id').value = v.id || '';
        $('m-lead').value = v.lead_id || '';
        $('m-agent').value = v.agent_id || '';
        $('m-property').value = v.property_id || '';
        $('m-manual').value = v.manual_property || '';
        $('m-status').value = v.status || 'Scheduled';
        $('m-date').value = v.visit_date || '';
        $('m-time').value = v.visit_time ? String(v.visit_time).substring(0, 5) : '';
        $('m-count').value = v.visitor_count || 1;
        $('m-meeting').value = v.meeting_point || '';
        $('m-feedback').value = v.customer_feedback || '';
        $('m-remarks').value = v.agent_remarks || '';
        $('schedule-modal').classList.add('open');
        setTimeout(() => {
            const body = document.querySelector('#schedule-modal .mbody');
            if (body) body.scrollTop = 0;
        }, 0);
    }

    function viewVisit(id) {
        let v = visits.find(x => parseInt(x.id) === parseInt(id));
        if (!v) return alert('Visit not found');
        $('view-content').innerHTML =
            `<div><div class="vl">Client Name</div><div class="vv">${esc(v.lead_name||'-')}</div></div><div><div class="vl">Phone</div><div class="vv">${esc(v.lead_phone||'-')}</div></div><div><div class="vl">Property / Project</div><div class="vv">${esc(v.property_title||v.manual_property||'-')}</div></div><div><div class="vl">Status</div><div class="vv">${esc(v.status||'-')}</div></div><div><div class="vl">Visit Date</div><div class="vv">${esc(v.visit_date||'-')}</div></div><div><div class="vl">Visit Time</div><div class="vv">${esc(v.visit_time||'-')}</div></div><div><div class="vl">Agent</div><div class="vv">${esc(v.agent_name||'Unassigned')}</div></div><div><div class="vl">Meeting Point</div><div class="vv">${esc(v.meeting_point||'-')}</div></div><div class="full"><div class="vl">Customer Feedback</div><div class="vv" style="font-weight:500;color:#64748b;line-height:1.5">${esc(v.customer_feedback||'No feedback provided.')}</div></div><div class="full"><div class="vl">Agent Remarks</div><div class="vv" style="font-weight:500;color:#64748b;line-height:1.5">${esc(v.agent_remarks||'-')}</div></div>`;
        $('view-modal').classList.add('open');
    }

    function closeViewModal() {
        $('view-modal').classList.remove('open');
    }

    function confirmDelete(id) {
        $('delete-id').value = id;
        $('confirm-modal').classList.add('open');
    }

    function closeConfirmModal() {
        $('confirm-modal').classList.remove('open');
    }

    function esc(s) {
        return String(s || '').replace(/[&<>'"]/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        } [c]));
    }
    ['schedule-modal', 'view-modal', 'confirm-modal'].forEach(id => $(id)?.addEventListener('click', e => {
        if (e.target === e.currentTarget) e.currentTarget.classList.remove('open')
    }));
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeScheduleModal();
            closeViewModal();
            closeConfirmModal();
        }
    });
    </script>
</body>

</html>