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

if (!function_exists('dist_h')) {
    function dist_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('dist_json')) {
    function dist_json($arr){ header('Content-Type: application/json'); echo json_encode($arr); exit; }
}
if (!function_exists('dist_table_exists')) {
    function dist_table_exists(mysqli $conn, string $table): bool {
        static $cache=[];
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if(isset($cache[$table])) return $cache[$table];
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        if(!$stmt) return $cache[$table]=false;
        $stmt->bind_param('s',$table); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return $cache[$table]=((int)($row['c']??0)>0);
    }
}
if (!function_exists('dist_col_exists')) {
    function dist_col_exists(mysqli $conn, string $table, string $col): bool {
        static $cache=[];
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $key=$table.'.'.$col;
        if(isset($cache[$key])) return $cache[$key];
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        if(!$stmt) return $cache[$key]=false;
        $stmt->bind_param('ss',$table,$col); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return $cache[$key]=((int)($row['c']??0)>0);
    }
}
if (!function_exists('dist_first_col')) {
    function dist_first_col(mysqli $conn, string $table, array $cols, string $fallback=''): string {
        foreach($cols as $c){ if(dist_col_exists($conn,$table,$c)) return $c; }
        return $fallback;
    }
}
if (!function_exists('dist_scalar')) {
    function dist_scalar(mysqli $conn, string $sql, string $types='', array $params=[]){
        $stmt=$conn->prepare($sql); if(!$stmt) return null;
        if($types!=='') $stmt->bind_param($types, ...$params);
        $stmt->execute(); $res=$stmt->get_result(); $row=$res?$res->fetch_row():null; $stmt->close();
        return $row[0] ?? null;
    }
}
if (!function_exists('dist_rows')) {
    function dist_rows(mysqli $conn, string $sql, string $types='', array $params=[]): array{
        $stmt=$conn->prepare($sql); if(!$stmt) return [];
        if($types!=='') $stmt->bind_param($types, ...$params);
        $stmt->execute(); $res=$stmt->get_result(); $data=$res?$res->fetch_all(MYSQLI_ASSOC):[]; $stmt->close();
        return $data;
    }
}
function dist_invoice_expr(mysqli $conn): string {
    $c = dist_first_col($conn,'sales',['invoice_no','order_no','reference_no'],'');
    return $c ? "COALESCE(NULLIF(s.`$c`,''), CONCAT('ORD-',s.id))" : "CONCAT('ORD-',s.id)";
}
function dist_total_expr(mysqli $conn): string {
    foreach(['grand_total','total_amount','total','net_total'] as $c){ if(dist_col_exists($conn,'sales',$c)) return "COALESCE(s.`$c`,0)"; }
    return '0';
}
function dist_payment_expr(mysqli $conn): string {
    if(dist_col_exists($conn,'sales','payment_status')) return "COALESCE(NULLIF(s.payment_status,''),'Unpaid')";
    if(dist_col_exists($conn,'sales','payment_method')) return "COALESCE(NULLIF(s.payment_method,''),'Cash')";
    return "'Cash'";
}
function dist_customer_name_expr(mysqli $conn): string {
    $saleName = dist_col_exists($conn,'sales','customer_name') ? "NULLIF(s.customer_name,'')" : "NULL";
    $custCol = dist_table_exists($conn,'customers') ? dist_first_col($conn,'customers',['name','customer_name','full_name'],'') : '';
    $custExpr = $custCol ? "NULLIF(c.`$custCol`,'')" : "NULL";
    return "COALESCE($custExpr, $saleName, 'Walk-in Customer')";
}
function dist_customer_phone_expr(mysqli $conn): string {
    $phoneCol = dist_table_exists($conn,'customers') ? dist_first_col($conn,'customers',['phone','mobile','contact_no','phone_number'],'') : '';
    return $phoneCol ? "COALESCE(c.`$phoneCol`,'')" : "''";
}
function dist_next_dispatch_no(mysqli $conn, int $client_id): string {
    $ymd = date('ymd');
    $prefix = 'DSP-'.$ymd.'-';
    $n = (int)dist_scalar($conn,"SELECT COUNT(*)+1 FROM distribution_dispatches WHERE client_id=? AND dispatch_no LIKE ?",'is',[$client_id,$prefix.'%']);
    return $prefix.str_pad((string)$n,3,'0',STR_PAD_LEFT);
}
function dist_order_qty(mysqli $conn, int $sale_id): float {
    if(!dist_table_exists($conn,'sale_items')) return 0;
    $qty = dist_first_col($conn,'sale_items',['qty','quantity','quantity_ordered'],'qty');
    if(!$qty) return 0;
    return (float)dist_scalar($conn,"SELECT COALESCE(SUM(`$qty`),0) FROM sale_items WHERE sale_id=?",'i',[$sale_id]);
}
function dist_refresh_dispatch_totals(mysqli $conn, int $client_id, int $dispatch_id): void {
    $r = dist_rows($conn,"SELECT COUNT(*) total_orders, COALESCE(SUM(order_amount),0) total_amount, COALESCE(SUM(collection_amount),0) total_collection FROM distribution_dispatch_items WHERE client_id=? AND dispatch_id=?",'ii',[$client_id,$dispatch_id]);
    $x = $r[0] ?? ['total_orders'=>0,'total_amount'=>0,'total_collection'=>0];
    $stmt=$conn->prepare("UPDATE distribution_dispatches SET total_orders=?, total_amount=?, total_collection=? WHERE id=? AND client_id=?");
    if($stmt){ $stmt->bind_param('iddii',$x['total_orders'],$x['total_amount'],$x['total_collection'],$dispatch_id,$client_id); $stmt->execute(); $stmt->close(); }
}
function dist_loading_summary(mysqli $conn, int $client_id, int $dispatch_id): array {
    if(!dist_table_exists($conn,'sale_items')) return [];
    $qty = dist_first_col($conn,'sale_items',['qty','quantity','quantity_ordered'],'qty');
    $itemId = dist_first_col($conn,'sale_items',['item_id','product_id'],'item_id');
    $nameCol = dist_table_exists($conn,'items') ? dist_first_col($conn,'items',['name','item_name','product_name'],'name') : '';
    if(!$qty) return [];
    if($itemId && $nameCol && dist_table_exists($conn,'items')){
        return dist_rows($conn,"SELECT COALESCE(i.`$nameCol`, CONCAT('Item #',si.`$itemId`)) item_name, COALESCE(SUM(si.`$qty`),0) qty
            FROM distribution_dispatch_items ddi
            JOIN sale_items si ON si.sale_id=ddi.sale_id
            LEFT JOIN items i ON i.id=si.`$itemId`
            WHERE ddi.client_id=? AND ddi.dispatch_id=?
            GROUP BY si.`$itemId`, i.`$nameCol`
            ORDER BY qty DESC",'ii',[$client_id,$dispatch_id]);
    }
    return dist_rows($conn,"SELECT CONCAT('Sale Item') item_name, COALESCE(SUM(si.`$qty`),0) qty
        FROM distribution_dispatch_items ddi JOIN sale_items si ON si.sale_id=ddi.sale_id
        WHERE ddi.client_id=? AND ddi.dispatch_id=?",'ii',[$client_id,$dispatch_id]);
}

/* Printable dispatch sheet */
if(isset($_GET['print_dispatch'])){
    $dispatch_id = (int)$_GET['print_dispatch'];
    $dispatch = dist_rows($conn,"SELECT dd.*, dr.route_name, dr.area, drv.driver_name, drv.phone driver_phone
        FROM distribution_dispatches dd
        LEFT JOIN distribution_routes dr ON dr.id=dd.route_id AND dr.client_id=dd.client_id
        LEFT JOIN delivery_drivers drv ON drv.id=dd.driver_id AND drv.client_id=dd.client_id
        WHERE dd.id=? AND dd.client_id=? LIMIT 1",'ii',[$dispatch_id,$client_id]);
    if(!$dispatch){ die('Dispatch not found'); }
    $d=$dispatch[0];
    $inv=dist_invoice_expr($conn); $cust=dist_customer_name_expr($conn); $phone=dist_customer_phone_expr($conn); $total=dist_total_expr($conn);
    $items = dist_rows($conn,"SELECT ddi.*, $inv invoice_no, $cust customer_name, $phone phone, $total amount
        FROM distribution_dispatch_items ddi
        JOIN sales s ON s.id=ddi.sale_id
        LEFT JOIN customers c ON c.id=ddi.customer_id
        WHERE ddi.client_id=? AND ddi.dispatch_id=? ORDER BY ddi.id ASC",'ii',[$client_id,$dispatch_id]);
    $summary = dist_loading_summary($conn,$client_id,$dispatch_id);
    ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Dispatch Print</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        color: #111;
        margin: 24px
    }

    .head {
        display: flex;
        justify-content: space-between;
        border-bottom: 2px solid #111;
        padding-bottom: 12px;
        margin-bottom: 18px
    }

    .title {
        font-size: 26px;
        font-weight: 800
    }

    .meta {
        font-size: 13px;
        line-height: 1.8
    }

    .grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
        margin-bottom: 18px
    }

    .box {
        border: 1px solid #ccc;
        border-radius: 8px;
        padding: 12px
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px
    }

    th,
    td {
        border: 1px solid #ddd;
        padding: 8px;
        font-size: 12px;
        text-align: left
    }

    th {
        background: #f1f5f9
    }

    .sign {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 20px;
        margin-top: 50px
    }

    .line {
        border-top: 1px solid #111;
        text-align: center;
        padding-top: 8px;
        font-size: 12px
    }

    @media print {
        button {
            display: none
        }

        body {
            margin: 10mm
        }
    }
 
 /* =====================================================
   DISPATCH.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
   Paste before MAIN 
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

/* Layout + sidebar */
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

/* Topbar + background */
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

/* Header */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.text-muted,
.page-header .small{
    color:#94a3b8 !important;
}

/* Buttons */
.btn-main{
    min-height:46px !important;
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

.btn-darkx{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.btn-lightx{
    min-height:46px !important;
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
.btn-darkx:hover,
.btn-lightx:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* KPI cards */
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
.kpi-card:nth-child(4){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

.kpi-icon{
    font-size:30px !important;
    margin-right:12px !important;
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

/* Tabs */
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

/* Glass card + filter */
.cardx,
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

.cardx:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-row{
    background:transparent !important;
    padding:14px !important;
    gap:10px !important;
}

.filter-row::before{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-row input,
.filter-row select,
.form-grid input,
.form-grid select,
.form-grid textarea{
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-row input,
.filter-row select{
    height:46px !important;
}

.filter-row input::placeholder,
.form-grid input::placeholder,
.form-grid textarea::placeholder{
    color:#94a3b8 !important;
}

.filter-row input:focus,
.filter-row select:focus,
.form-grid input:focus,
.form-grid select:focus,
.form-grid textarea:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-row select option,
.form-grid select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Table transparent glass */
.table-responsive,
table,
tbody,
tbody tr{
    background:transparent !important;
}

table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

thead th{
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

tbody td{
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

/* Badges */
.badgex{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.st-ready,
.st-dispatched{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border:1px solid rgba(59,130,246,.24) !important;
}

.st-completed,
.pay-paid{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.st-draft,
.pay-partial{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.st-cancelled,
.pay-unpaid{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* Actions + pager */
.action{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(109,76,255,.18) !important;
    color:#c4b5fd !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.action:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.28) !important;
    color:#fff !important;
}

.pager{
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

.info{
    color:#94a3b8 !important;
}

.bulk-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(109,76,255,.82), rgba(15,23,42,.72)) !important;
}

.toastx{
    background:#0f172a !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-left:4px solid #7c3aed !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}


/* =====================================================
   DISPATCH POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

#dispatchModal.modal-back,
#itemsModal.modal-back{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
}

#dispatchModal .modal-box,
#itemsModal .modal-box{
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

#dispatchModal .modal-box{
    max-width:780px !important;
}

#itemsModal .modal-box{
    max-width:860px !important;
}

#dispatchModal .modal-head,
#itemsModal .modal-head{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    color:#fff !important;
    font-size:18px !important;
    font-weight:900 !important;
}

#dispatchModal .modal-head b,
#itemsModal .modal-head b{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    color:#fff !important;
    font-size:18px !important;
    font-weight:900 !important;
}

#dispatchModal .modal-head b::before,
#itemsModal .modal-head b::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
}

#dispatchModal .closex,
#itemsModal .closex{
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
}

#dispatchModal .closex::before,
#dispatchModal .closex::after,
#itemsModal .closex::before,
#itemsModal .closex::after{
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

#dispatchModal .closex::before,
#itemsModal .closex::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#dispatchModal .closex::after,
#itemsModal .closex::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#dispatchModal .closex:hover,
#itemsModal .closex:hover{
    background:#ef4444 !important;
}

#dispatchModal .modal-bodyx,
#itemsModal .modal-bodyx{
    background:transparent !important;
    color:#fff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#dispatchModal .modal-foot,
#itemsModal .modal-foot{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#dispatchModal .alert-info{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:14px !important;
    font-weight:800 !important;
}

#dispatchModal .alert-info b{
    color:#a78bfa !important;
}

#dispatchModal .form-grid{
    gap:12px !important;
}

#dispatchModal .form-grid label,
#itemsModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#dispatchModal .form-grid input,
#dispatchModal .form-grid select,
#dispatchModal .form-grid textarea{
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

#dispatchModal .form-grid textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#dispatchModal .form-grid input[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

#dispatchModal .form-grid input::placeholder,
#dispatchModal .form-grid textarea::placeholder{
    color:#94a3b8 !important;
}

#dispatchModal .form-grid input:focus,
#dispatchModal .form-grid select:focus,
#dispatchModal .form-grid textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14),0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Items modal */
#itemsModal h6{
    color:#f8fafc !important;
    font-size:16px !important;
    font-weight:900 !important;
    margin-bottom:12px !important;
}

#itemsModal #summaryBox{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:14px !important;
    padding:14px !important;
    min-height:54px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#itemsModal .summary-pill{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
    font-weight:900 !important;
}

#itemsModal .text-muted{
    color:#94a3b8 !important;
}

#itemsModal .table-responsive{
    background:rgba(15,23,42,.35) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    overflow:auto !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#itemsModal table,
#itemsModal tbody,
#itemsModal tbody tr{
    background:transparent !important;
}

#itemsModal thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#itemsModal tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

#itemsModal tbody tr:hover td{
    background:rgba(124,58,237,.12) !important;
    color:#fff !important;
}

#dispatchModal .btn-lightx,
#itemsModal .btn-lightx{
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

#dispatchModal .btn-main{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#dispatchModal .btn-lightx:hover,
#dispatchModal .btn-main:hover,
#itemsModal .btn-lightx:hover{
    transform:translateY(-1px);
}


@media(max-width:1100px){
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
    .kpi-grid{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .filter-row{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .filter-row::before,
    .filter-row input,
    .filter-row select,
    .filter-search,
    .btn-darkx,
    .btn-lightx,
    .btn-main{
        width:100% !important;
        min-width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .form-grid{
        grid-template-columns:1fr !important;
    }

    #dispatchModal .modal-box,
    #itemsModal .modal-box{
        width:96vw !important;
        max-height:94vh !important;
    }

    #dispatchModal .modal-foot,
    #itemsModal .modal-foot{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #dispatchModal .btn-lightx,
    #dispatchModal .btn-main,
    #itemsModal .btn-lightx{
        width:100% !important;
    }
}
 
    </style>
</head>

<body><button onclick="window.print()">Print</button>
    <div class="head">
        <div>
            <div class="title">Distribution Dispatch Sheet</div>
            <div><?=dist_h($d['dispatch_no'])?></div>
        </div>
        <div class="meta"><b>Date:</b> <?=dist_h($d['dispatch_date'])?>
            <?=dist_h($d['dispatch_time'])?><br><b>Status:</b> <?=dist_h($d['status'])?><br><b>Orders:</b>
            <?=number_format((int)$d['total_orders'])?></div>
    </div>
    <div class="grid">
        <div class="box"><b>Route</b><br><?=dist_h($d['route_name'] ?: '-')?><br><?=dist_h($d['area'] ?: '')?></div>
        <div class="box"><b>Rider / Vehicle</b><br><?=dist_h($d['driver_name'] ?: '-')?>
            <?= $d['driver_phone'] ? ' / '.dist_h($d['driver_phone']) : '' ?><br><?=dist_h($d['vehicle'] ?: '-')?></div>
    </div>
    <div class="box"><b>Product Loading Summary</b>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Qty</th>
                </tr>
            </thead>
            <tbody><?php if($summary): foreach($summary as $s): ?><tr>
                    <td><?=dist_h($s['item_name'])?></td>
                    <td><?=number_format((float)$s['qty'],2)?></td>
                </tr><?php endforeach; else: ?><tr>
                    <td colspan="2">No product summary available</td>
                </tr><?php endif; ?></tbody>
        </table>
    </div>
    <h3>Customer / Order List</h3>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Phone</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Signature</th>
            </tr>
        </thead>
        <tbody><?php foreach($items as $i=>$it): ?><tr>
                <td><?=$i+1?></td>
                <td><?=dist_h($it['invoice_no'])?></td>
                <td><?=dist_h($it['customer_name'])?></td>
                <td><?=dist_h($it['phone'])?></td>
                <td><?=number_format((float)$it['order_amount'],2)?></td>
                <td><?=dist_h($it['item_status'])?></td>
                <td style="width:150px"></td>
            </tr><?php endforeach; ?></tbody>
    </table>
    <div class="sign">
        <div class="line">Prepared By</div>
        <div class="line">Rider Signature</div>
        <div class="line">Manager Signature</div>
    </div>
</body>

</html><?php
    exit;
}

if(isset($_GET['ajax']) || isset($_POST['ajax'])){
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if($action==='stats'){
        $today=date('Y-m-d');
        $pendingAssigned=(int)dist_scalar($conn,"SELECT COUNT(*) FROM delivery_order_assignments a LEFT JOIN distribution_dispatch_items di ON di.assignment_id=a.id AND di.client_id=a.client_id WHERE a.client_id=? AND a.status='assigned' AND di.id IS NULL",'i',[$client_id]);
        $ready=(int)dist_scalar($conn,"SELECT COUNT(*) FROM distribution_dispatches WHERE client_id=? AND status='ready'",'i',[$client_id]);
        $dispatched=(int)dist_scalar($conn,"SELECT COUNT(*) FROM distribution_dispatches WHERE client_id=? AND status='dispatched'",'i',[$client_id]);
        $todayDispatch=(int)dist_scalar($conn,"SELECT COUNT(*) FROM distribution_dispatches WHERE client_id=? AND dispatch_date=?",'is',[$client_id,$today]);
        dist_json(['success'=>true,'stats'=>compact('pendingAssigned','ready','dispatched','todayDispatch')]);
    }

    if($action==='drivers'){
        $drivers = dist_table_exists($conn,'delivery_drivers') ? dist_rows($conn,"SELECT id, driver_code, driver_name, vehicle, status FROM delivery_drivers WHERE client_id=? AND status!='Inactive' ORDER BY driver_name ASC",'i',[$client_id]) : [];
        dist_json(['success'=>true,'drivers'=>$drivers]);
    }

    if($action==='routes'){
        $routes = dist_table_exists($conn,'distribution_routes') ? dist_rows($conn,"SELECT id, route_code, route_name, area, city, driver_id, driver_name, vehicle_name FROM distribution_routes WHERE client_id=? AND status='Active' ORDER BY route_name ASC",'i',[$client_id]) : [];
        dist_json(['success'=>true,'routes'=>$routes]);
    }

    if($action==='assigned_orders'){
        $page=max(1,(int)($_GET['page']??1)); $limit=max(5,min(100,(int)($_GET['limit']??10))); $offset=($page-1)*$limit;
        $search=trim($_GET['search']??''); $driver=(int)($_GET['driver_id']??0); $route=(int)($_GET['route_id']??0); $date=trim($_GET['date']??'');
        $where=["a.client_id=?", "a.status='assigned'", "di.id IS NULL"];
        $types='i'; $params=[$client_id];
        if($branch_id>0){ $where[]="(a.branch_id=? OR a.branch_id IS NULL OR a.branch_id=0)"; $types.='i'; $params[]=$branch_id; }
        if($driver>0){ $where[]="a.driver_id=?"; $types.='i'; $params[]=$driver; }
        if($route>0){ $where[]="a.route_id=?"; $types.='i'; $params[]=$route; }
        if($date!==''){ $where[]="a.delivery_date=?"; $types.='s'; $params[]=$date; }
        $inv=dist_invoice_expr($conn); $cust=dist_customer_name_expr($conn); $phone=dist_customer_phone_expr($conn); $total=dist_total_expr($conn); $pay=dist_payment_expr($conn);
        if($search!==''){ $where[]="($inv LIKE ? OR $cust LIKE ? OR $phone LIKE ? OR CAST(s.id AS CHAR) LIKE ?)"; $like='%'.$search.'%'; $types.='ssss'; array_push($params,$like,$like,$like,$like); }
        $whereSql=implode(' AND ',$where);
        $countSql="SELECT COUNT(*) FROM delivery_order_assignments a JOIN sales s ON s.id=a.sale_id LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN distribution_dispatch_items di ON di.assignment_id=a.id AND di.client_id=a.client_id WHERE $whereSql";
        $totalRows=(int)dist_scalar($conn,$countSql,$types,$params);
        $sql="SELECT a.id assignment_id, a.sale_id, a.driver_id, a.route_id, a.vehicle, a.delivery_date, a.delivery_time_slot,
                     d.driver_name, r.route_name, r.area, $inv invoice_no, $cust customer_name, $phone phone, $total amount, $pay payment_status
              FROM delivery_order_assignments a
              JOIN sales s ON s.id=a.sale_id
              LEFT JOIN customers c ON c.id=s.customer_id
              LEFT JOIN delivery_drivers d ON d.id=a.driver_id AND d.client_id=a.client_id
              LEFT JOIN distribution_routes r ON r.id=a.route_id AND r.client_id=a.client_id
              LEFT JOIN distribution_dispatch_items di ON di.assignment_id=a.id AND di.client_id=a.client_id
              WHERE $whereSql ORDER BY a.id DESC LIMIT ? OFFSET ?";
        $types2=$types.'ii'; $params2=$params; $params2[]=$limit; $params2[]=$offset;
        $rows=dist_rows($conn,$sql,$types2,$params2);
        foreach($rows as &$r){ $r['order_qty']=dist_order_qty($conn,(int)$r['sale_id']); }
        unset($r);
        dist_json(['success'=>true,'orders'=>$rows,'total'=>$totalRows,'page'=>$page,'pages'=>max(1,(int)ceil($totalRows/$limit))]);
    }

    if($action==='dispatches'){
        $page=max(1,(int)($_GET['page']??1)); $limit=max(5,min(100,(int)($_GET['limit']??10))); $offset=($page-1)*$limit;
        $search=trim($_GET['search']??''); $status=trim($_GET['status']??''); $date=trim($_GET['date']??'');
        $where=["dd.client_id=?"]; $types='i'; $params=[$client_id];
        if($search!==''){ $where[]="(dd.dispatch_no LIKE ? OR drv.driver_name LIKE ? OR dr.route_name LIKE ?)"; $like='%'.$search.'%'; $types.='sss'; array_push($params,$like,$like,$like); }
        if($status!==''){ $where[]="dd.status=?"; $types.='s'; $params[]=$status; }
        if($date!==''){ $where[]="dd.dispatch_date=?"; $types.='s'; $params[]=$date; }
        $whereSql=implode(' AND ',$where);
        $totalRows=(int)dist_scalar($conn,"SELECT COUNT(*) FROM distribution_dispatches dd LEFT JOIN delivery_drivers drv ON drv.id=dd.driver_id AND drv.client_id=dd.client_id LEFT JOIN distribution_routes dr ON dr.id=dd.route_id AND dr.client_id=dd.client_id WHERE $whereSql",$types,$params);
        $sql="SELECT dd.*, drv.driver_name, dr.route_name, dr.area FROM distribution_dispatches dd LEFT JOIN delivery_drivers drv ON drv.id=dd.driver_id AND drv.client_id=dd.client_id LEFT JOIN distribution_routes dr ON dr.id=dd.route_id AND dr.client_id=dd.client_id WHERE $whereSql ORDER BY dd.id DESC LIMIT ? OFFSET ?";
        $types2=$types.'ii'; $params2=$params; $params2[]=$limit; $params2[]=$offset;
        dist_json(['success'=>true,'dispatches'=>dist_rows($conn,$sql,$types2,$params2),'total'=>$totalRows,'page'=>$page,'pages'=>max(1,(int)ceil($totalRows/$limit))]);
    }

    if($action==='create_dispatch'){
        $assignmentIds=$_POST['assignment_ids'] ?? [];
        if(!is_array($assignmentIds)) $assignmentIds=explode(',',(string)$assignmentIds);
        $assignmentIds=array_values(array_unique(array_filter(array_map('intval',$assignmentIds))));
        $driverId=(int)($_POST['driver_id']??0); $routeId=($_POST['route_id']??'')!==''?(int)$_POST['route_id']:null;
        $dispatchDate=trim($_POST['dispatch_date']??date('Y-m-d')); $dispatchTime=trim($_POST['dispatch_time']??date('H:i'));
        $vehicle=trim($_POST['vehicle']??''); $notes=trim($_POST['notes']??'');
        if(empty($assignmentIds)) dist_json(['success'=>false,'message'=>'Please select assigned orders.']);
        if($routeId && dist_table_exists($conn,'distribution_routes')){
            $routeRow=dist_rows($conn,"SELECT driver_id, vehicle_name FROM distribution_routes WHERE id=? AND client_id=? LIMIT 1",'ii',[$routeId,$client_id]);
            if($routeRow){ if($driverId<=0 && !empty($routeRow[0]['driver_id'])) $driverId=(int)$routeRow[0]['driver_id']; if($vehicle==='' && !empty($routeRow[0]['vehicle_name'])) $vehicle=(string)$routeRow[0]['vehicle_name']; }
        }
        if($driverId<=0) dist_json(['success'=>false,'message'=>'Rider required.']);
        $driver=dist_rows($conn,"SELECT driver_name, vehicle FROM delivery_drivers WHERE id=? AND client_id=? AND status!='Inactive' LIMIT 1",'ii',[$driverId,$client_id]);
        if(!$driver) dist_json(['success'=>false,'message'=>'Selected rider not found or inactive.']);
        if($vehicle==='') $vehicle=(string)($driver[0]['vehicle'] ?? '');
        $dispatchNo=dist_next_dispatch_no($conn,$client_id);
        $conn->begin_transaction();
        try{
            $stmt=$conn->prepare("INSERT INTO distribution_dispatches (client_id,branch_id,dispatch_no,driver_id,route_id,vehicle,dispatch_date,dispatch_time,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?, 'ready', ?, ?)");
            if(!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('iisiissssi',$client_id,$branch_id,$dispatchNo,$driverId,$routeId,$vehicle,$dispatchDate,$dispatchTime,$notes,$user_id);
            if(!$stmt->execute()) throw new Exception($stmt->error);
            $dispatchId=(int)$conn->insert_id; $stmt->close();
            $itemStmt=$conn->prepare("INSERT INTO distribution_dispatch_items (client_id,branch_id,dispatch_id,assignment_id,sale_id,customer_id,route_id,driver_id,order_amount,collection_amount,item_status) VALUES (?,?,?,?,?,?,?,?,?,0,'ready')");
            if(!$itemStmt) throw new Exception($conn->error);
            $used=0;
            foreach($assignmentIds as $aid){
                $a=dist_rows($conn,"SELECT a.*, s.customer_id, ".dist_total_expr($conn)." amount FROM delivery_order_assignments a JOIN sales s ON s.id=a.sale_id WHERE a.id=? AND a.client_id=? AND a.status='assigned' LIMIT 1",'ii',[$aid,$client_id]);
                if(!$a) continue; $a=$a[0];
                $sid=(int)$a['sale_id']; $cid=isset($a['customer_id'])?(int)$a['customer_id']:null; $amount=(float)($a['amount'] ?? 0);
                $rId=$routeId ?: (isset($a['route_id'])?(int)$a['route_id']:null);
                $dId=$driverId ?: (isset($a['driver_id'])?(int)$a['driver_id']:null);
                $itemStmt->bind_param('iiiiiiiid',$client_id,$branch_id,$dispatchId,$aid,$sid,$cid,$rId,$dId,$amount);
                if(!$itemStmt->execute()) throw new Exception($itemStmt->error);
                $u=$conn->prepare("UPDATE delivery_order_assignments SET status='ready_for_dispatch', route_id=COALESCE(?,route_id), driver_id=?, vehicle=?, delivery_date=? WHERE id=? AND client_id=?");
                if($u){ $u->bind_param('iissii',$rId,$driverId,$vehicle,$dispatchDate,$aid,$client_id); $u->execute(); $u->close(); }
                $used++;
            }
            $itemStmt->close();
            if($used===0) throw new Exception('No valid assigned order selected.');
            dist_refresh_dispatch_totals($conn,$client_id,$dispatchId);
            $conn->commit(); dist_json(['success'=>true,'message'=>'Dispatch created: '.$dispatchNo,'dispatch_id'=>$dispatchId]);
        }catch(Throwable $e){ $conn->rollback(); dist_json(['success'=>false,'message'=>$e->getMessage()]); }
    }

    if($action==='dispatch_items'){
        $dispatchId=(int)($_GET['dispatch_id']??0);
        $inv=dist_invoice_expr($conn); $cust=dist_customer_name_expr($conn); $phone=dist_customer_phone_expr($conn);
        $items=dist_rows($conn,"SELECT ddi.*, $inv invoice_no, $cust customer_name, $phone phone
              FROM distribution_dispatch_items ddi JOIN sales s ON s.id=ddi.sale_id LEFT JOIN customers c ON c.id=ddi.customer_id
              WHERE ddi.client_id=? AND ddi.dispatch_id=? ORDER BY ddi.id ASC",'ii',[$client_id,$dispatchId]);
        $summary=dist_loading_summary($conn,$client_id,$dispatchId);
        dist_json(['success'=>true,'items'=>$items,'summary'=>$summary]);
    }

    if($action==='update_dispatch_status'){
        $dispatchId=(int)($_POST['dispatch_id']??0); $status=trim($_POST['status']??'');
        if(!in_array($status,['draft','ready','dispatched','completed','cancelled'],true)) dist_json(['success'=>false,'message'=>'Invalid status']);
        $stmt=$conn->prepare("UPDATE distribution_dispatches SET status=? WHERE id=? AND client_id=?");
        if(!$stmt) dist_json(['success'=>false,'message'=>$conn->error]);
        $stmt->bind_param('sii',$status,$dispatchId,$client_id);
        if(!$stmt->execute()) dist_json(['success'=>false,'message'=>$stmt->error]);
        $stmt->close();
        if($status==='dispatched'){
            $conn->query("UPDATE distribution_dispatch_items SET item_status='dispatched' WHERE dispatch_id=$dispatchId AND client_id=$client_id AND item_status='ready'");
            $conn->query("UPDATE delivery_order_assignments a INNER JOIN distribution_dispatch_items ddi ON ddi.assignment_id=a.id AND ddi.client_id=a.client_id SET a.status='out_for_delivery' WHERE ddi.dispatch_id=$dispatchId AND ddi.client_id=$client_id");
        } elseif($status==='cancelled'){
            $conn->query("UPDATE distribution_dispatch_items SET item_status='cancelled' WHERE dispatch_id=$dispatchId AND client_id=$client_id");
            $conn->query("UPDATE delivery_order_assignments a INNER JOIN distribution_dispatch_items ddi ON ddi.assignment_id=a.id AND ddi.client_id=a.client_id SET a.status='assigned' WHERE ddi.dispatch_id=$dispatchId AND ddi.client_id=$client_id AND a.status='ready_for_dispatch'");
        }
        dist_json(['success'=>true,'message'=>'Dispatch status updated.']);
    }

    dist_json(['success'=>false,'message'=>'Invalid action']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distribution Dispatch</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php include('partials/head.php'); ?>
    <style>
    :root {
        --sidebar-width: 292px;
        --topbar-height: 78px;
        --gold: #f6b900;
        --page-bg: #f4f6ff;
        --primary: #17243d;
        --head: #263650;
        --muted: #667085;
        --border: #dfe5ef;
        --green: #16a34a;
        --red: #dc2626;
        --orange: #d97706;
        --blue: #2563eb
    }

    body {
        margin: 0;
        background: var(--page-bg);
        font-family: "Inter", "Segoe UI", Tahoma, sans-serif;
        color: #10203a;
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
        min-width: 0
    }

    .topbar {
        height: var(--topbar-height);
        background: #050505;
        display: flex;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 999
    }

    .content-wrapper {
        padding: 24px 24px 60px
    }

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
        flex-wrap: wrap
    }

    .page-title {
        font-size: 33px;
        font-weight: 900;
        letter-spacing: -.6px
    }

    .btn-main {
        height: 48px;
        border: 0;
        border-radius: 10px;
        background: var(--gold);
        color: #111827;
        font-weight: 900;
        padding: 0 20px
    }

    .btn-darkx {
        height: 46px;
        border: 0;
        border-radius: 10px;
        background: var(--primary);
        color: #fff;
        font-weight: 900;
        padding: 0 18px
    }

    .btn-lightx {
        height: 46px;
        border: 0;
        border-radius: 10px;
        background: #eef2f7;
        color: #4b5563;
        font-weight: 900;
        padding: 0 18px
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
        background: var(--primary);
        color: #fff;
        border-color: var(--primary)
    }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
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
        background: linear-gradient(135deg, #f7c943, #f4b400)
    }

    .kpi-icon {
        font-size: 24px;
        margin-right: 12px
    }

    .kpi-label {
        font-size: 15px;
        font-weight: 800;
        line-height: 1.1
    }

    .kpi-value {
        font-size: 31px;
        font-weight: 900;
        margin-left: auto
    }

    .cardx {
        background: #fff;
        border: 1px solid #e3e8f0;
        border-radius: 12px;
        box-shadow: 0 12px 26px rgba(18, 35, 64, .07);
        margin-bottom: 14px;
        overflow: hidden
    }

    .card-head {
        padding: 16px 18px;
        border-bottom: 1px solid #e8edf5;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap
    }

    .card-title {
        font-size: 18px;
        font-weight: 900
    }

    .filter-row {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        padding: 12px
    }

    .filter-row:before {
        content: "⚡ Quick Filters";
        height: 46px;
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

    .filter-row input,
    .filter-row select {
        height: 46px;
        border: 1px solid #dbe2ed;
        border-radius: 10px;
        padding: 0 14px;
        min-width: 150px;
        background: #fff
    }

    .filter-search {
        flex: 1;
        min-width: 260px
    }

    .table-responsive {
        overflow-x: auto
    }

    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 1120px
    }

    thead th {
        background: var(--head);
        color: #fff;
        padding: 16px 14px;
        font-size: 14px;
        white-space: nowrap
    }

    tbody td {
        padding: 15px 14px;
        border-bottom: 1px solid #edf2f7;
        font-size: 14px;
        vertical-align: middle;
        background: #fff
    }

    .badgex {
        border-radius: 999px;
        padding: 6px 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-block
    }

    .st-ready {
        background: #eff6ff;
        color: #2563eb
    }

    .st-dispatched {
        background: #ecfeff;
        color: #0891b2
    }

    .st-completed {
        background: #dcfce7;
        color: #15803d
    }

    .st-draft {
        background: #fefce8;
        color: #a16207
    }

    .st-cancelled {
        background: #fee2e2;
        color: #b91c1c
    }

    .pay-paid {
        background: #dcfce7;
        color: #15803d
    }

    .pay-unpaid {
        background: #fee2e2;
        color: #b91c1c
    }

    .pay-partial {
        background: #fff7ed;
        color: #c2410c
    }

    .action {
        border: 0;
        background: #eef2ff;
        color: #17233b;
        border-radius: 8px;
        width: 34px;
        height: 34px
    }

    .pager {
        display: flex;
        gap: 5px;
        padding: 14px;
        border-top: 1px solid #e8edf5;
        align-items: center
    }

    .page-btn {
        width: 34px;
        height: 34px;
        border: 1px solid #d8e0ea;
        background: #fff;
        border-radius: 8px;
        font-weight: 900
    }

    .page-btn.active {
        background: #17233b;
        color: #fff
    }

    .info {
        margin-left: auto;
        color: #667085;
        font-size: 13px;
        font-weight: 800
    }

    .bulk-bar {
        display: none;
        background: #17233b;
        color: #fff;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 14px;
        align-items: center;
        gap: 10px;
        font-weight: 900
    }

    .bulk-bar.show {
        display: flex
    }

    .modal-back {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .55);
        z-index: 3000;
        align-items: center;
        justify-content: center
    }

    .modal-back.show {
        display: flex
    }

    .modal-box {
        background: #fff;
        border-radius: 14px;
        width: 92%;
        max-width: 840px;
        max-height: 90vh;
        overflow: auto
    }

    .modal-head {
        padding: 18px 22px;
        background: #28344d;
        color: #fff;
        display: flex;
        justify-content: space-between;
        font-weight: 900
    }

    .modal-bodyx {
        padding: 18px
    }

    .modal-foot {
        padding: 14px 18px;
        border-top: 1px solid #e8edf5;
        display: flex;
        gap: 8px;
        justify-content: flex-end
    }

    .closex {
        border: 0;
        background: rgba(255, 255, 255, .1);
        color: #fff;
        border-radius: 8px;
        width: 32px;
        height: 32px
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px
    }

    .form-grid label {
        font-size: 13px;
        font-weight: 900;
        margin-bottom: 5px
    }

    .form-grid input,
    .form-grid select,
    .form-grid textarea {
        width: 100%;
        height: 46px;
        border: 1px solid #dbe2ed;
        border-radius: 10px;
        padding: 0 12px
    }

    .form-grid textarea {
        height: 86px;
        padding-top: 10px
    }

    .toastx {
        position: fixed;
        right: 22px;
        bottom: 22px;
        z-index: 5000;
        background: #17233b;
        color: #fff;
        padding: 12px 16px;
        border-radius: 10px;
        display: none;
        font-weight: 800
    }

    .toastx.show {
        display: block
    }

    .summary-pill {
        display: inline-flex;
        margin: 4px;
        padding: 7px 10px;
        border-radius: 999px;
        background: #eef4ff;
        color: #17233b;
        font-weight: 800;
        font-size: 13px
    }

    @media(max-width:1000px) {
        .kpi-grid {
            grid-template-columns: repeat(2, 1fr)
        }

        .form-grid {
            grid-template-columns: 1fr
        }
    }

    @media(max-width:768px) {
        .sidebar {
            display: none
        }

        .content-wrapper {
            padding: 16px
        }

        .kpi-grid {
            grid-template-columns: 1fr
        }

        .filter-search {
            min-width: 100%
        }

        .filter-row:before,
        .filter-row input,
        .filter-row select,
        .btn-darkx,
        .btn-lightx {
            width: 100%;
            min-width: 100%
        }
    }
    

 
 /* =====================================================
   DISPATCH.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
   Paste before MAIN 
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

/* Layout + sidebar */
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

/* Topbar + background */
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

/* Header */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.text-muted,
.page-header .small{
    color:#94a3b8 !important;
}

/* Buttons */
.btn-main{
    min-height:46px !important;
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

.btn-darkx{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.btn-lightx{
    min-height:46px !important;
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
.btn-darkx:hover,
.btn-lightx:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* KPI cards */
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
.kpi-card:nth-child(4){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

.kpi-icon{
    font-size:30px !important;
    margin-right:12px !important;
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

/* Tabs */
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

/* Glass card + filter */
.cardx,
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

.cardx:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-row{
    background:transparent !important;
    padding:14px !important;
    gap:10px !important;
}

.filter-row::before{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-row input,
.filter-row select,
.form-grid input,
.form-grid select,
.form-grid textarea{
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-row input,
.filter-row select{
    height:46px !important;
}

.filter-row input::placeholder,
.form-grid input::placeholder,
.form-grid textarea::placeholder{
    color:#94a3b8 !important;
}

.filter-row input:focus,
.filter-row select:focus,
.form-grid input:focus,
.form-grid select:focus,
.form-grid textarea:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-row select option,
.form-grid select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Table transparent glass */
.table-responsive,
table,
tbody,
tbody tr{
    background:transparent !important;
}

table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

thead th{
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

tbody td{
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

/* Badges */
.badgex{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.st-ready,
.st-dispatched{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border:1px solid rgba(59,130,246,.24) !important;
}

.st-completed,
.pay-paid{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.st-draft,
.pay-partial{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.st-cancelled,
.pay-unpaid{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* Actions + pager */
.action{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(109,76,255,.18) !important;
    color:#c4b5fd !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.action:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.28) !important;
    color:#fff !important;
}

.pager{
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

.info{
    color:#94a3b8 !important;
}

.bulk-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(109,76,255,.82), rgba(15,23,42,.72)) !important;
}

.toastx{
    background:#0f172a !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-left:4px solid #7c3aed !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}


/* =====================================================
   DISPATCH POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

#dispatchModal.modal-back,
#itemsModal.modal-back{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
}

#dispatchModal .modal-box,
#itemsModal .modal-box{
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

#dispatchModal .modal-box{
    max-width:780px !important;
}

#itemsModal .modal-box{
    max-width:860px !important;
}

#dispatchModal .modal-head,
#itemsModal .modal-head{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    color:#fff !important;
    font-size:18px !important;
    font-weight:900 !important;
}

#dispatchModal .modal-head b,
#itemsModal .modal-head b{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    color:#fff !important;
    font-size:18px !important;
    font-weight:900 !important;
}

#dispatchModal .modal-head b::before,
#itemsModal .modal-head b::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
}

#dispatchModal .closex,
#itemsModal .closex{
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
}

#dispatchModal .closex::before,
#dispatchModal .closex::after,
#itemsModal .closex::before,
#itemsModal .closex::after{
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

#dispatchModal .closex::before,
#itemsModal .closex::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#dispatchModal .closex::after,
#itemsModal .closex::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#dispatchModal .closex:hover,
#itemsModal .closex:hover{
    background:#ef4444 !important;
}

#dispatchModal .modal-bodyx,
#itemsModal .modal-bodyx{
    background:transparent !important;
    color:#fff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#dispatchModal .modal-foot,
#itemsModal .modal-foot{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#dispatchModal .alert-info{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:14px !important;
    font-weight:800 !important;
}

#dispatchModal .alert-info b{
    color:#a78bfa !important;
}

#dispatchModal .form-grid{
    gap:12px !important;
}

#dispatchModal .form-grid label,
#itemsModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#dispatchModal .form-grid input,
#dispatchModal .form-grid select,
#dispatchModal .form-grid textarea{
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

#dispatchModal .form-grid textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#dispatchModal .form-grid input[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

#dispatchModal .form-grid input::placeholder,
#dispatchModal .form-grid textarea::placeholder{
    color:#94a3b8 !important;
}

#dispatchModal .form-grid input:focus,
#dispatchModal .form-grid select:focus,
#dispatchModal .form-grid textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14),0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Items modal */
#itemsModal h6{
    color:#f8fafc !important;
    font-size:16px !important;
    font-weight:900 !important;
    margin-bottom:12px !important;
}

#itemsModal #summaryBox{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:14px !important;
    padding:14px !important;
    min-height:54px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#itemsModal .summary-pill{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
    font-weight:900 !important;
}

#itemsModal .text-muted{
    color:#94a3b8 !important;
}

#itemsModal .table-responsive{
    background:rgba(15,23,42,.35) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    overflow:auto !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#itemsModal table,
#itemsModal tbody,
#itemsModal tbody tr{
    background:transparent !important;
}

#itemsModal thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#itemsModal tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

#itemsModal tbody tr:hover td{
    background:rgba(124,58,237,.12) !important;
    color:#fff !important;
}

#dispatchModal .btn-lightx,
#itemsModal .btn-lightx{
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

#dispatchModal .btn-main{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#dispatchModal .btn-lightx:hover,
#dispatchModal .btn-main:hover,
#itemsModal .btn-lightx:hover{
    transform:translateY(-1px);
}


@media(max-width:1100px){
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
    .kpi-grid{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .filter-row{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .filter-row::before,
    .filter-row input,
    .filter-row select,
    .filter-search,
    .btn-darkx,
    .btn-lightx,
    .btn-main{
        width:100% !important;
        min-width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .form-grid{
        grid-template-columns:1fr !important;
    }

    #dispatchModal .modal-box,
    #itemsModal .modal-box{
        width:96vw !important;
        max-height:94vh !important;
    }

    #dispatchModal .modal-foot,
    #itemsModal .modal-foot{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #dispatchModal .btn-lightx,
    #dispatchModal .btn-main,
    #itemsModal .btn-lightx{
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
                <div class="page-header">
                    <div>
                        <div class="page-title"><i class="bi bi-truck-front me-2"></i>Distribution Dispatch</div>
                        <div class="text-muted small fw-bold">Assigned POS orders ko dispatch sheet me convert karo
                        </div>
                    </div>
                    <button class="btn-main" onclick="openCreateDispatch()"><i class="bi bi-plus-circle me-1"></i>Create
                        Dispatch</button>
                </div>

                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div class="kpi-label">Assigned<br>Pending</div>
                        <div class="kpi-value" id="kpiPending">0</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon"><i class="bi bi-file-earmark-check"></i></div>
                        <div class="kpi-label">Ready<br>Dispatch</div>
                        <div class="kpi-value" id="kpiReady">0</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon"><i class="bi bi-truck"></i></div>
                        <div class="kpi-label">Dispatched</div>
                        <div class="kpi-value" id="kpiDispatched">0</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon"><i class="bi bi-calendar2-check"></i></div>
                        <div class="kpi-label">Today</div>
                        <div class="kpi-value" id="kpiToday">0</div>
                    </div>
                </div>

                <div class="tabs">
                    <button class="tab-btn active" id="tabAssigned" onclick="switchTab('assigned')">Assigned
                        Orders</button>
                    <button class="tab-btn" id="tabDispatches" onclick="switchTab('dispatches')">Dispatch List</button>
                </div>

                <div id="bulkBar" class="bulk-bar"><b id="bulkCount">0 selected</b><button class="btn-main"
                        onclick="openCreateDispatch()">Create Dispatch</button><button class="btn-lightx"
                        onclick="clearSelection()">Clear</button></div>

                <section id="assignedSection">
                    <div class="cardx">
                        <div class="filter-row">
                            <input class="filter-search" id="assignedSearch"
                                placeholder="Search invoice, customer, phone, order ID">
                            <select id="assignedDriver">
                                <option value="">All Riders</option>
                            </select>
                            <select id="assignedRoute">
                                <option value="">All Routes</option>
                            </select>
                            <input type="date" id="assignedDate">
                            <button class="btn-darkx" onclick="loadAssigned(1)">Filter</button>
                            <button class="btn-lightx" onclick="resetAssigned()">Reset</button>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>
                                        <th>Invoice</th>
                                        <th>Customer</th>
                                        <th>Route</th>
                                        <th>Rider</th>
                                        <th>Qty</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th>Delivery Date</th>
                                    </tr>
                                </thead>
                                <tbody id="assignedBody"></tbody>
                            </table>
                        </div>
                        <div class="pager" id="assignedPager"></div>
                    </div>
                </section>

                <section id="dispatchesSection" style="display:none">
                    <div class="cardx">
                        <div class="filter-row">
                            <input class="filter-search" id="dispatchSearch"
                                placeholder="Search dispatch, rider, route">
                            <select id="dispatchStatus">
                                <option value="">All Status</option>
                                <option value="ready">Ready</option>
                                <option value="dispatched">Dispatched</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <input type="date" id="dispatchDate">
                            <button class="btn-darkx" onclick="loadDispatches(1)">Filter</button>
                            <button class="btn-lightx" onclick="resetDispatches()">Reset</button>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Dispatch No</th>
                                        <th>Date</th>
                                        <th>Rider</th>
                                        <th>Route</th>
                                        <th>Vehicle</th>
                                        <th>Orders</th>
                                        <th>Total Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="dispatchBody"></tbody>
                            </table>
                        </div>
                        <div class="pager" id="dispatchPager"></div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div class="modal-back" id="dispatchModal">
        <div class="modal-box">
            <div class="modal-head"><b>Create Distribution Dispatch</b><button class="closex"
                    onclick="closeModal('dispatchModal')">×</button></div>
            <div class="modal-bodyx">
                <div class="alert alert-info small fw-bold">Selected assigned orders dispatch sheet me add honge. Save
                    ke baad order status <b>ready_for_dispatch</b> hoga; Dispatch button se <b>out_for_delivery</b> ho
                    jayega.</div>
                <div class="form-grid">
                    <div><label>Rider *</label><select id="dispatchDriver">
                            <option value="">Select Rider</option>
                        </select></div>
                    <div><label>Route</label><select id="dispatchRoute">
                            <option value="">Select Route</option>
                        </select></div>
                    <div><label>Vehicle</label><input id="dispatchVehicle" placeholder="Vehicle"></div>
                    <div><label>Dispatch Date *</label><input type="date" id="dispatchCreateDate"></div>
                    <div><label>Dispatch Time</label><input type="time" id="dispatchTime"></div>
                    <div><label>Selected Orders</label><input id="selectedText" readonly></div>
                    <div style="grid-column:1/-1"><label>Notes</label><textarea id="dispatchNotes"
                            placeholder="Dispatch notes"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button class="btn-lightx"
                    onclick="closeModal('dispatchModal')">Cancel</button><button class="btn-main"
                    onclick="saveDispatch()">Save Dispatch</button></div>
        </div>
    </div>

    <div class="modal-back" id="itemsModal">
        <div class="modal-box">
            <div class="modal-head"><b id="itemsTitle">Dispatch Items</b><button class="closex"
                    onclick="closeModal('itemsModal')">×</button></div>
            <div class="modal-bodyx">
                <h6 class="fw-bold">Product Loading Summary</h6>
                <div id="summaryBox" class="mb-3"></div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-foot"><button class="btn-lightx" onclick="closeModal('itemsModal')">Close</button></div>
        </div>
    </div>
    <div class="toastx" id="toast"></div>
    <script>
    let selected = new Set();
    let assignedPage = 1,
        dispatchPage = 1;
    const api = location.pathname;

    function esc(v) {
        return String(v ?? '').replace(/[&<>'"]/g, m => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        } [m]));
    }

    function post(action, data = {}) {
        data.ajax = 1;
        data.action = action;
        return fetch(api, {
            method: 'POST',
            body: new URLSearchParams(data)
        }).then(r => r.json());
    }

    function get(action, params = {}) {
        params.ajax = 1;
        params.action = action;
        return fetch(api + '?' + new URLSearchParams(params)).then(r => r.json());
    }

    function toast(msg) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2800);
    }

    function badgeStatus(s) {
        return `<span class="badgex st-${esc(s)}">${esc(s)}</span>`;
    }

    function payBadge(p) {
        let x = (p || '').toLowerCase();
        let cls = x.includes('paid') && !x.includes('un') ? 'pay-paid' : (x.includes('partial') ? 'pay-partial' :
            'pay-unpaid');
        return `<span class="badgex ${cls}">${esc(p||'-')}</span>`;
    }

    function switchTab(t) {
        assignedSection.style.display = t === 'assigned' ? 'block' : 'none';
        dispatchesSection.style.display = t === 'dispatches' ? 'block' : 'none';
        tabAssigned.classList.toggle('active', t === 'assigned');
        tabDispatches.classList.toggle('active', t === 'dispatches');
        if (t === 'assigned') loadAssigned(assignedPage);
        else loadDispatches(dispatchPage);
    }

    function loadStats() {
        get('stats').then(r => {
            if (!r.success) return;
            kpiPending.textContent = r.stats.pendingAssigned;
            kpiReady.textContent = r.stats.ready;
            kpiDispatched.textContent = r.stats.dispatched;
            kpiToday.textContent = r.stats.todayDispatch;
        });
    }

    function fillSelects() {
        get('drivers').then(r => {
            let opts = '<option value="">All Riders</option>';
            let opts2 = '<option value="">Select Rider</option>';
            (r.drivers || []).forEach(d => {
                opts += `<option value="${d.id}">${esc(d.driver_name)}</option>`;
                opts2 +=
                    `<option value="${d.id}" data-vehicle="${esc(d.vehicle||'')}">${esc(d.driver_name)} (${esc(d.status||'')})</option>`;
            });
            assignedDriver.innerHTML = opts;
            dispatchDriver.innerHTML = opts2;
        });
        get('routes').then(r => {
            let opts = '<option value="">All Routes</option>';
            let opts2 = '<option value="">Select Route</option>';
            (r.routes || []).forEach(x => {
                const title =
                    `${esc(x.route_name||x.route_code||'Route')}${x.area?' - '+esc(x.area):''}`;
                opts += `<option value="${x.id}">${title}</option>`;
                opts2 +=
                    `<option value="${x.id}" data-driver-id="${esc(x.driver_id||'')}" data-vehicle="${esc(x.vehicle_name||'')}">${title}</option>`;
            });
            assignedRoute.innerHTML = opts;
            dispatchRoute.innerHTML = opts2;
        });
    }

    function loadAssigned(page = 1) {
        assignedPage = page;
        get('assigned_orders', {
            page,
            search: assignedSearch.value,
            driver_id: assignedDriver.value,
            route_id: assignedRoute.value,
            date: assignedDate.value
        }).then(r => {
            let html = '';
            (r.orders || []).forEach(o => {
                html +=
                    `<tr><td><input type="checkbox" class="rowCheck" value="${o.assignment_id}" onchange="toggleOne(this)"></td><td><b>${esc(o.invoice_no)}</b></td><td>${esc(o.customer_name)}<br><small class="text-muted fw-bold">${esc(o.phone||'')}</small></td><td>${esc(o.route_name||'-')}</td><td>${esc(o.driver_name||'-')}</td><td>${Number(o.order_qty||0).toLocaleString()}</td><td>PKR ${Number(o.amount||0).toLocaleString()}</td><td>${payBadge(o.payment_status)}</td><td>${esc(o.delivery_date||'')}</td></tr>`;
            });
            assignedBody.innerHTML = html ||
                '<tr><td colspan="9" class="text-center text-muted py-4">No assigned orders pending for dispatch.</td></tr>';
            renderPager('assignedPager', r.page, r.pages, loadAssigned, r.total);
            selected.clear();
            updateBulk();
            loadStats();
        });
    }

    function loadDispatches(page = 1) {
        dispatchPage = page;
        get('dispatches', {
            page,
            search: dispatchSearch.value,
            status: dispatchStatus.value,
            date: dispatchDate.value
        }).then(r => {
            let html = '';
            (r.dispatches || []).forEach(d => {
                html +=
                    `<tr><td><b>${esc(d.dispatch_no)}</b></td><td>${esc(d.dispatch_date)} ${esc(d.dispatch_time||'')}</td><td>${esc(d.driver_name||'-')}</td><td>${esc(d.route_name||'-')}</td><td>${esc(d.vehicle||'-')}</td><td>${Number(d.total_orders||0)}</td><td>PKR ${Number(d.total_amount||0).toLocaleString()}</td><td>${badgeStatus(d.status)}</td><td><button class="action" title="View" onclick="viewItems(${d.id},'${esc(d.dispatch_no)}')"><i class="bi bi-eye"></i></button> <button class="action" title="Print" onclick="window.open(api+'?print_dispatch=${d.id}','_blank')"><i class="bi bi-printer"></i></button> <button class="action" title="Dispatch" onclick="setStatus(${d.id},'dispatched')"><i class="bi bi-truck"></i></button> <button class="action" title="Complete" onclick="setStatus(${d.id},'completed')"><i class="bi bi-check2-circle"></i></button></td></tr>`;
            });
            dispatchBody.innerHTML = html ||
                '<tr><td colspan="9" class="text-center text-muted py-4">No dispatches found.</td></tr>';
            renderPager('dispatchPager', r.page, r.pages, loadDispatches, r.total);
            loadStats();
        });
    }

    function renderPager(id, page, pages, fn, total) {
        let h = '';
        h += `<button class="page-btn" ${page<=1?'disabled':''} onclick="${fn.name}(${page-1})">‹</button>`;
        for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) {
            h += `<button class="page-btn ${i==page?'active':''}" onclick="${fn.name}(${i})">${i}</button>`;
        }
        h +=
        `<button class="page-btn" ${page>=pages?'disabled':''} onclick="${fn.name}(${page+1})">›</button><span class="info">Page ${page} of ${pages} · ${total||0} records</span>`;
        document.getElementById(id).innerHTML = h;
    }

    function toggleOne(cb) {
        cb.checked ? selected.add(cb.value) : selected.delete(cb.value);
        updateBulk();
    }

    function toggleAll(cb) {
        document.querySelectorAll('.rowCheck').forEach(x => {
            x.checked = cb.checked;
            cb.checked ? selected.add(x.value) : selected.delete(x.value);
        });
        updateBulk();
    }

    function updateBulk() {
        bulkCount.textContent = selected.size + ' selected';
        bulkBar.classList.toggle('show', selected.size > 0);
        selectedText.value = selected.size + ' orders selected';
    }

    function clearSelection() {
        selected.clear();
        document.querySelectorAll('.rowCheck,#checkAll').forEach(x => x.checked = false);
        updateBulk();
    }

    function openCreateDispatch() {
        if (selected.size === 0) {
            toast('Please select assigned orders first.');
            return;
        }
        dispatchCreateDate.value = new Date().toISOString().slice(0, 10);
        dispatchTime.value = new Date().toTimeString().slice(0, 5);
        updateBulk();
        dispatchModal.classList.add('show');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }
    dispatchDriver?.addEventListener('change', function() {
        let opt = this.selectedOptions[0];
        if (opt && opt.dataset.vehicle) dispatchVehicle.value = opt.dataset.vehicle || '';
    });
    dispatchRoute?.addEventListener('change', function() {
        let opt = this.selectedOptions[0];
        if (!opt) return;
        if (opt.dataset.driverId) {
            dispatchDriver.value = opt.dataset.driverId;
        }
        if (opt.dataset.vehicle) {
            dispatchVehicle.value = opt.dataset.vehicle;
        }
    });

    function saveDispatch() {
        post('create_dispatch', {
            assignment_ids: [...selected].join(','),
            driver_id: dispatchDriver.value,
            route_id: dispatchRoute.value,
            vehicle: dispatchVehicle.value,
            dispatch_date: dispatchCreateDate.value,
            dispatch_time: dispatchTime.value,
            notes: dispatchNotes.value
        }).then(r => {
            toast(r.message || 'Done');
            if (r.success) {
                closeModal('dispatchModal');
                clearSelection();
                loadAssigned(1);
                loadDispatches(1);
            }
        });
    }

    function viewItems(id, no) {
        itemsTitle.textContent = 'Dispatch Items - ' + no;
        get('dispatch_items', {
            dispatch_id: id
        }).then(r => {
            let sh = '';
            (r.summary || []).forEach(s => {
                sh +=
                    `<span class="summary-pill">${esc(s.item_name)}: ${Number(s.qty||0).toLocaleString()}</span>`;
            });
            summaryBox.innerHTML = sh || '<span class="text-muted fw-bold">No product summary available</span>';
            let html = '';
            (r.items || []).forEach(i => {
                html +=
                    `<tr><td><b>${esc(i.invoice_no)}</b></td><td>${esc(i.customer_name)}</td><td>${esc(i.phone||'')}</td><td>PKR ${Number(i.order_amount||0).toLocaleString()}</td><td>${esc(i.item_status)}</td></tr>`;
            });
            itemsBody.innerHTML = html ||
                '<tr><td colspan="5" class="text-center text-muted">No items</td></tr>';
            itemsModal.classList.add('show');
        });
    }

    function setStatus(id, status) {
        if (!confirm('Update dispatch status to ' + status + '?')) return;
        post('update_dispatch_status', {
            dispatch_id: id,
            status
        }).then(r => {
            toast(r.message || 'Updated');
            loadDispatches(dispatchPage);
            loadAssigned(assignedPage);
        });
    }

    function resetAssigned() {
        assignedSearch.value = '';
        assignedDriver.value = '';
        assignedRoute.value = '';
        assignedDate.value = '';
        loadAssigned(1);
    }

    function resetDispatches() {
        dispatchSearch.value = '';
        dispatchStatus.value = '';
        dispatchDate.value = '';
        loadDispatches(1);
    }
    document.addEventListener('DOMContentLoaded', () => {
        fillSelects();
        loadStats();
        loadAssigned(1);
    });
    </script>
</body>

</html>