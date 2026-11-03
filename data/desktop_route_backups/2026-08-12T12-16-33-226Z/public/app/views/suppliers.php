<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
}

$client_id = (int)$_SESSION['client_id'];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function supplierName(array $row): string
{
    return trim((string)($row['company_name'] ?: ($row['name'] ?: 'Unnamed Supplier')));
}

function supplierAddress(array $row): string
{
    return trim((string)($row['full_address'] ?: ($row['address'] ?: '')));
}

function shortText($value, int $limit = 60): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function supplierStatusClass(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'active') return 'status-active';
    if ($status === 'pending') return 'status-pending';
    return 'status-inactive';
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "
    SELECT 
        s.*,
        COALESCE(ip.total_products, 0) AS total_products,
        COALESCE(ip.assigned_products, '') AS assigned_products,
        COALESCE(po.total_purchase, 0) AS total_purchase,
        COALESCE(sp.total_paid, 0) AS total_paid,
        (
            COALESCE(s.opening_balance, 0)
            + COALESCE(po.total_purchase, 0)
            - COALESCE(sp.total_paid, 0)
        ) AS current_balance
    FROM suppliers s

    LEFT JOIN (
        SELECT 
            client_id,
            supplier_id,
            COUNT(id) AS total_products,
            GROUP_CONCAT(name ORDER BY name ASC SEPARATOR ', ') AS assigned_products
        FROM items
        WHERE supplier_id IS NOT NULL
        GROUP BY client_id, supplier_id
    ) ip ON ip.supplier_id = s.id AND ip.client_id = s.client_id

    LEFT JOIN (
        SELECT 
            client_id,
            supplier_id,
            SUM(total_amount) AS total_purchase
        FROM purchase_orders
        GROUP BY client_id, supplier_id
    ) po ON po.supplier_id = s.id AND po.client_id = s.client_id

    LEFT JOIN (
        SELECT 
            client_id,
            supplier_id,
            SUM(amount) AS total_paid
        FROM supplier_payments
        GROUP BY client_id, supplier_id
    ) sp ON sp.supplier_id = s.id AND sp.client_id = s.client_id

    WHERE s.client_id = ?
";
$params = [$client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (
        company_name LIKE ?
        OR contact_person LIKE ?
        OR name LIKE ?
        OR phone LIKE ?
        OR email LIKE ?
        OR supplier_type LIKE ?
        OR payment_terms LIKE ?
        OR full_address LIKE ?
        OR address LIKE ?
    )";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    $types .= "sssssssss";
}

if ($statusFilter !== '') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$suppliers = [];
while ($row = $res->fetch_assoc()) {
    $suppliers[] = $row;
}
$stmt->close();

/* stats */
$totalSuppliers = 0;
$activeSuppliers = 0;
$pendingSuppliers = 0;
$overduePayments = 0.0;

$stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_suppliers,
        SUM(CASE WHEN LOWER(TRIM(status))='active' THEN 1 ELSE 0 END) AS active_suppliers,
        SUM(CASE WHEN LOWER(TRIM(status))='pending' THEN 1 ELSE 0 END) AS pending_suppliers,
        SUM(CASE WHEN COALESCE(pending_days,0) > 0 THEN COALESCE(opening_balance,0) ELSE 0 END) AS overdue_payments
    FROM suppliers
    WHERE client_id = ?
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($stats) {
    $totalSuppliers = (int)($stats['total_suppliers'] ?? 0);
    $activeSuppliers = (int)($stats['active_suppliers'] ?? 0);
    $pendingSuppliers = (int)($stats['pending_suppliers'] ?? 0);
    $overduePayments = (float)($stats['overdue_payments'] ?? 0);
}

/* chart data */
$monthlySpend = array_fill(1, 12, 0);
foreach ($suppliers as $s) {
    $createdMonth = (int)date('n', strtotime($s['created_at'] ?? date('Y-m-d')));
    $monthlySpend[$createdMonth] += (float)($s['opening_balance'] ?? 0);
}

$topSuppliers = $suppliers;
usort($topSuppliers, function ($a, $b) {
    return (float)($b['opening_balance'] ?? 0) <=> (float)($a['opening_balance'] ?? 0);
});
$topSuppliers = array_slice($topSuppliers, 0, 5);

$chartMax = max($monthlySpend ?: [1]);
if ($chartMax <= 0) $chartMax = 1;

$monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Suppliers Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<?php include("partials/head.php");?>

<style>
    body{
        margin:0;
        background:#f3f4f8;
        font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
    }
  .container-scroll{
        display:flex;
        min-height:100vh;
           align-items:stretch;
    }

    .sidebar{
        width:250px;
        flex-shrink:0;
          display:flex;*/
  align-self:stretch;
  background:transparent;
    overflow:hidden;
    }


.sidebar > .sidebar-wrap{
    width:100%;
    min-height:100%;
    height:100%;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100%;
}
    .main-panel{
        flex-grow:1;
        display:flex;
        flex-direction:column;
        min-width:0;
    }
    .topbar{
        height:60px;
        border-bottom:1px solid #e5e7eb;
        display:flex;
        align-items:center;
        padding:0 20px;
        background:#fff;
    }
    .content-wrapper{
        padding:20px;
        flex-grow:1;
        overflow-y:auto;
    }
    .page-title{
        font-size:28px;
        font-weight:700;
        color:#1f2937;
        margin-bottom:18px;
    }

    .stats-row{
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:14px;
        margin-bottom:16px;
    }
    .stat-card{
        color:#fff;
        border-radius:10px;
        padding:14px 18px;
        display:flex;
        justify-content:space-between;
        align-items:center;
        box-shadow:0 8px 18px rgba(0,0,0,0.08);
    }
    .stat-card .title{
        font-size:15px;
        font-weight:500;
    }
    .stat-card .count{
        font-size:30px;
        font-weight:700;
        line-height:1;
    }
    .card-blue{ background:linear-gradient(135deg,#314a93,#405da8); }
    .card-green{ background:linear-gradient(135deg,#5a9b42,#73b552); }
    .card-cyan{ background:linear-gradient(135deg,#62b1bf,#7bc8d5); }
    .card-yellow{ background:linear-gradient(135deg,#d9a615,#f3be2d); }

    .filter-bar{
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:12px;
        padding:12px;
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        align-items:center;
        margin-bottom:16px;
    }
    .search-wrap{
        flex:1.3;
        min-width:220px;
        position:relative;
    }
    .search-wrap i{
        position:absolute;
        left:12px;
        top:50%;
        transform:translateY(-50%);
        color:#6b7280;
    }
    .search-wrap input{
        width:100%;
        height:44px;
        border:1px solid #e5e7eb;
        border-radius:10px;
        padding:0 14px 0 38px;
        outline:none;
    }
    .filter-control{
        min-width:180px;
        height:44px;
        border:1px solid #e5e7eb;
        border-radius:10px;
        padding:0 12px;
        outline:none;
        background:#fff;
    }
    .filter-btn{
        height:44px;
        border:none;
        border-radius:10px;
        padding:0 18px;
        background:#1f2937;
        color:#fff;
        font-weight:600;
        text-decoration:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
    }
    .add-btn{
        height:44px;
        border:none;
        border-radius:10px;
        padding:0 18px;
        background:#f2b90f;
        color:#111827;
        font-weight:700;
    }

    .chart-grid{
        display:grid;
        grid-template-columns:2fr 1fr;
        gap:16px;
        margin-bottom:16px;
    }
    .chart-card,
    .top-list-card,
    .table-card{
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:12px;
        overflow:hidden;
    }
    .card-title{
        padding:16px 18px 10px;
        font-size:18px;
        font-weight:700;
        color:#111827;
    }
    .chart-body{
        padding:12px 18px 18px;
    }
    .chart-area{
        height:250px;
        border:1px solid #eef1f5;
        border-radius:10px;
        background:linear-gradient(to top,#fafbfc,#ffffff);
        padding:16px;
        display:flex;
        align-items:flex-end;
        gap:14px;
    }
    .bar-wrap{
        flex:1;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:flex-end;
        height:100%;
    }
    .bar{
        width:100%;
        max-width:36px;
        background:linear-gradient(180deg,#7aa8ff,#375cc8);
        border-radius:8px 8px 0 0;
        min-height:8px;
    }
    .bar-label{
        margin-top:10px;
        font-size:12px;
        color:#6b7280;
    }
    .bar-value{
        margin-bottom:8px;
        font-size:11px;
        color:#374151;
        font-weight:600;
    }
    .top-list{
        padding:0 18px 18px;
    }
    .top-item{
        display:flex;
        justify-content:space-between;
        gap:10px;
        align-items:center;
        padding:12px 0;
        border-bottom:1px solid #edf0f5;
    }
    .top-item:last-child{border-bottom:none}
    .top-name{
        font-weight:600;
        color:#111827;
    }
    .top-amount{
        font-weight:700;
        color:#374151;
    }

    .table-responsive{
        overflow:auto;
    }
    .suppliers-table{
        width:100%;
        min-width:1250px;
        border-collapse:collapse;
    }
    .suppliers-table thead th{
        background:#28344d;
        color:#fff;
        font-weight:600;
        font-size:14px;
        padding:14px 12px;
        border-right:1px solid rgba(255,255,255,0.08);
        white-space:nowrap;
    }
    .suppliers-table tbody td{
        padding:14px 12px;
        border-top:1px solid #edf0f5;
        vertical-align:middle;
        font-size:14px;
        color:#374151;
    }
    .suppliers-table tbody tr:hover{
        background:#fafbfc;
    }

    .supplier-main{
        font-weight:700;
        color:#1f2937;
    }
    .supplier-sub{
        display:block;
        font-size:12px;
        color:#667085;
        margin-top:4px;
        white-space:normal;
    }

    .status-badge{
        padding:6px 12px;
        border-radius:7px;
        font-size:12px;
        font-weight:700;
        display:inline-block;
    }
    .status-active{ background:#e8f7ec; color:#17663c; }
    .status-pending{ background:#fff4dd; color:#8a5a00; }
    .status-inactive{ background:#fdecec; color:#a32929; }

    .balance-amt{
        font-weight:700;
        color:#111827;
    }

    .due-badge{
        padding:6px 12px;
        border-radius:999px;
        font-size:12px;
        font-weight:700;
        display:inline-flex;
        align-items:center;
        gap:6px;
        background:#fff3da;
        color:#956700;
    }

  .actions{
    display:flex;
    gap:10px;
    align-items:center;
    justify-content:center;
}

.icon-btn{
    width:38px;
    height:38px;
    border:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
    text-decoration:none;
    padding:0;
}

.icon-btn:hover{
    transform:translateY(-2px);
}

.icon-btn i{
    font-size:15px;
    line-height:1;
}

/* VIEW */
.icon-view{
    background:#eef2ff;
    color:#6366f1;
}
.icon-view:hover{
    background:#6366f1;
    color:#fff;
}

/* EDIT */
.icon-edit{
    background:#fff4d6;
    color:#e0a100;
}
.icon-edit:hover{
    background:#e0a100;
    color:#fff;
}

/* DELETE */
.icon-delete{
    background:#ffe3e3;
    color:#ef4444;
}
.icon-delete:hover{
    background:#ef4444;
    color:#fff;
}


.icon-delete::before,
.icon-delete::after{
    content:none !important;
    display:none !important;
}
    .detail-row{
        display:none;
        background:#f8fafc;
    }
    .detail-box{
        padding:18px;
        background:#f8fafc;
    }
    .detail-title{
        font-size:18px;
        font-weight:700;
        margin-bottom:12px;
        color:#111827;
    }
    .summary-grid{
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:12px;
    }
    .summary-item{
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:10px;
        padding:12px;
    }
    .summary-item span{
        display:block;
        color:#6b7280;
        font-size:12px;
        margin-bottom:4px;
    }
    .summary-item strong{
        color:#111827;
        font-size:17px;
    }

    .bottom-actions{
        padding:14px;
        border-top:1px solid #e5e7eb;
        background:#fff;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
    }
    .bulk-tools{
        display:flex;
        gap:10px;
        align-items:center;
        flex-wrap:wrap;
    }
    .delete-btn{
        background:#ef4444;
        color:#fff;
        border:none;
        border-radius:10px;
        padding:12px 18px;
        font-weight:700;
    }
    .csv-btn{
        background:#f2b90f;
        color:#111827;
        border:none;
        border-radius:10px;
        padding:12px 18px;
        font-weight:700;
    }

    /* OLD SUPPLIER POPUP STYLE PRESERVED */
    /* Supplier Modal - same UI as items.php modal */
.supplier-modal-content{
    background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
    color:#fff;
    border:none;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.35);
}

.supplier-modal-header{
    background:transparent;
    border-bottom:1px solid rgba(255,255,255,0.08);
    padding:18px 22px;
}

.supplier-modal-header .modal-title{
    font-size:18px;
    font-weight:700;
    color:#fff;
    margin:0;
}

.supplier-modal-body{
    padding:20px 22px 10px;
}

.supplier-modal-footer{
    border-top:1px solid rgba(255,255,255,0.08);
    padding:16px 22px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:14px;
}

.supplier-label{
    display:block;
    font-size:15px;
    margin-bottom:7px;
    color:#e4e8f8;
    font-weight:500;
}

.supplier-label span{
    color:#f4b21c;
}

.supplier-input,
.supplier-textarea{
    width:100%;
    border:1px solid rgba(255,255,255,0.10);
    background:#e9eaf3;
    color:#222;
    border-radius:8px;
    height:44px;
    padding:0 14px;
    outline:none;
    font-size:14px;
}

.supplier-textarea{
    min-height:90px;
    resize:none;
    padding:12px 14px;
}

.supplier-input:focus,
.supplier-textarea:focus{
    border-color:#f0ae19;
    box-shadow:0 0 0 2px rgba(240,174,25,0.20);
}

.supplier-upload-box{
    border:1px dashed rgba(255,255,255,0.18);
    border-radius:12px;
    padding:12px;
    background:rgba(255,255,255,0.02);
    text-align:center;
    height:100%;
}

.supplier-upload-preview{
    min-height:220px;
    border:1px dashed rgba(255,255,255,0.15);
    border-radius:12px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:15px;
    overflow:hidden;
    background:rgba(255,255,255,0.02);
}

.supplier-upload-preview i{
    font-size:46px;
    color:#bfc4dc;
    margin-bottom:10px;
}

.supplier-upload-title{
    font-size:18px;
    font-weight:600;
    color:#d7dbef;
}

.supplier-upload-subtext{
    font-size:12px;
    color:#8f96b8;
    margin-top:4px;
}

.supplier-upload-preview img{
    max-width:100%;
    max-height:190px;
    border-radius:10px;
    object-fit:cover;
}

.supplier-upload-btn{
    margin-top:12px;
    width:100%;
    height:44px;
    border:none;
    border-radius:10px;
    background:linear-gradient(180deg,#3f4767 0%, #353d5b 100%);
    color:#fff;
    font-weight:600;
}

.supplier-or-sep{
    text-align:center;
    color:#bfc8df;
    margin:14px 0;
    font-size:13px;
}

.supplier-note{
    color:#9aa3c7;
    font-size:12px;
    margin-top:10px;
}

.supplier-doc-name{
    display:none;
    margin-top:8px;
    font-size:12px;
    color:#d3d9ea;
    word-break:break-word;
}

.supplier-toggle-wrap{
    display:flex;
    align-items:center;
    gap:10px;
    color:#dbe0f4;
    font-size:14px;
    font-weight:500;
    margin-top:8px;
}

.supplier-toggle-wrap input{
    width:18px;
    height:18px;
    accent-color:#f0ae19;
}

.supplier-footer-note{
    color:#dbe0f4;
    font-size:14px;
    font-weight:500;
}

.supplier-footer-btns{
    display:flex;
    align-items:center;
    gap:10px;
}

.supplier-cancel-btn{
    height:42px;
    min-width:110px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
    color:#fff;
    font-weight:600;
}

.supplier-save-btn{
    height:42px;
    min-width:140px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
    color:#fff;
    font-weight:700;
}

.supplier-modal-content .btn-close-white{
    filter:invert(1);
}

.preview-img{
    display:none;
    width:100%;
    max-height:190px;
    object-fit:contain;
    margin-top:10px;
    border:1px solid rgba(255,255,255,.18);
    border-radius:10px;
    padding:8px;
    background:#fff;
}

@media (max-width:768px){
    .supplier-modal-footer{
        justify-content:flex-end;
    }

    .supplier-footer-note{
        width:100%;
    }

    .supplier-footer-btns{
        width:100%;
        flex-direction:column;
    }

    .supplier-cancel-btn,
    .supplier-save-btn{
        width:100%;
    }
}

    @media (max-width:1100px){
        .stats-row{grid-template-columns:repeat(2,1fr);}
        .chart-grid{grid-template-columns:1fr;}
    }
    @media (max-width:768px){
        .stats-row{grid-template-columns:1fr;}
        .filter-bar{flex-direction:column;align-items:stretch;}
        .search-wrap,.filter-control,.filter-btn,.add-btn{width:100%;min-width:100%;}
        .page-title{font-size:24px;}
        .sidebar{width:240px;}
        .summary-grid{grid-template-columns:1fr 1fr;}
    }
    @media (max-width:500px){
        .summary-grid{grid-template-columns:1fr;}
    }


/* =========================================================
   FINAL SUPPLIERS.PHP POPUP BLACK + GOLD + WHITE THEME
   Add/Edit Supplier Modal
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

/* Modal size */
#supplierModal .modal-dialog{
    max-width:min(1240px,94vw) !important;
}

/* Main popup container */
#supplierModal .modal-content,
#supplierModal .supplier-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.62) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

/* Header */
#supplierModal .modal-header,
#supplierModal .supplier-modal-header{
    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
    min-height:76px !important;
}

#supplierModal .modal-title,
#supplierModal .supplier-modal-header .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#supplierModal .modal-title::before{
    content:'' !important;
    width:13px !important;
    height:13px !important;
    border-radius:50% !important;
    background:#c9a84c !important;
    box-shadow:0 0 0 5px rgba(201,168,76,.15) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

/* Close button - proper white X */
#supplierModal .btn-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#supplierModal .btn-close::before,
#supplierModal .btn-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:22px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#supplierModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#supplierModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#supplierModal .btn-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px) !important;
}

/* Body */
#supplierModal .modal-body,
#supplierModal .supplier-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#supplierModal .supplier-modal-body::-webkit-scrollbar{
    width:7px !important;
}

#supplierModal .supplier-modal-body::-webkit-scrollbar-track{
    background:#171c28 !important;
    border-radius:999px !important;
}

#supplierModal .supplier-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c !important;
    border-radius:999px !important;
}

/* Footer */
#supplierModal .modal-footer,
#supplierModal .supplier-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.28) !important;
    padding:18px 24px 22px !important;
}

/* Labels */
#supplierModal .supplier-label,
#supplierModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#supplierModal .supplier-label span{
    color:#f5c742 !important;
}

/* Inputs / selects / textarea */
#supplierModal .supplier-input,
#supplierModal .supplier-textarea,
#supplierModal input[type="text"],
#supplierModal input[type="email"],
#supplierModal input[type="number"],
#supplierModal select,
#supplierModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#supplierModal .supplier-input,
#supplierModal input[type="text"],
#supplierModal input[type="email"],
#supplierModal input[type="number"],
#supplierModal select{
    min-height:50px !important;
    height:50px !important;
    padding:10px 16px !important;
}

#supplierModal .supplier-textarea,
#supplierModal textarea{
    min-height:112px !important;
    height:auto !important;
    padding:14px 16px !important;
    resize:vertical !important;
}

#supplierModal input::placeholder,
#supplierModal textarea::placeholder{
    color:#8993a5 !important;
}

#supplierModal .supplier-input:focus,
#supplierModal .supplier-textarea:focus,
#supplierModal input:focus,
#supplierModal select:focus,
#supplierModal textarea:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Upload boxes */
#supplierModal .supplier-upload-box{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.38) !important;
    border-radius:14px !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#supplierModal .supplier-upload-preview{
    background:#0f1117 !important;
    border:1.5px dashed rgba(201,168,76,.58) !important;
    border-radius:14px !important;
}

#supplierModal .supplier-upload-preview i{
    color:#f5c742 !important;
}

#supplierModal .supplier-upload-title{
    color:#ffffff !important;
    font-weight:900 !important;
}

#supplierModal .supplier-upload-subtext,
#supplierModal .supplier-note,
#supplierModal .supplier-doc-name,
#supplierModal .supplier-or-sep{
    color:#dbe0f4 !important;
    font-weight:700 !important;
}

#supplierModal .supplier-upload-btn{
    background:#252b3a !important;
    color:#ffffff !important;
    border:1px solid rgba(255,255,255,.14) !important;
    border-radius:10px !important;
    font-weight:900 !important;
    height:48px !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#supplierModal .supplier-upload-btn:hover{
    background:#32394b !important;
}

#supplierModal .preview-img{
    background:#ffffff !important;
    border:1px solid rgba(201,168,76,.38) !important;
    border-radius:12px !important;
}

/* Preferred supplier toggle */
#supplierModal .supplier-toggle-wrap{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.28) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    color:#ffffff !important;
    font-weight:800 !important;
}

#supplierModal .form-check-input{
    background-color:#ffffff !important;
    border-color:#d9dfec !important;
    box-shadow:none !important;
}

#supplierModal .form-check-input:checked{
    background-color:#d8a500 !important;
    border-color:#d8a500 !important;
}

/* Footer notes/buttons */
#supplierModal .supplier-footer-note{
    color:#dbe0f4 !important;
    font-weight:800 !important;
}

#supplierModal .supplier-cancel-btn{
    height:44px !important;
    min-width:110px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#supplierModal .supplier-cancel-btn:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#supplierModal .supplier-save-btn{
    height:44px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#supplierModal .supplier-save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px) !important;
}

@media (max-width:768px){
    #supplierModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #supplierModal .modal-title{
        font-size:18px !important;
    }

    #supplierModal .btn-close{
        width:42px !important;
        height:42px !important;
    }

    #supplierModal .supplier-cancel-btn,
    #supplierModal .supplier-save-btn{
        width:100% !important;
    }

    #supplierModal .supplier-modal-footer{
        align-items:stretch !important;
    }

    #supplierModal .supplier-footer-btns{
        width:100% !important;
        flex-direction:column !important;
    }

    #supplierModal .supplier-footer-note{
        width:100% !important;
    }
}


/* =====================================================
   SUPPLIERS.PHP - DARK PURPLE BLACK GLASS THEME
   Paste at VERY END before
   Backend / PHP / JS logic untouched
===================================================== */

html, body{
    min-height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-panel:#0f172a;
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-yellow:#f59e0b;
    --oms-orange:#f97316;
    --oms-red:#ef4444;
    --oms-cyan:#06b6d4;

    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
}

/* ================= BASE ================= */
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
    overflow-x:hidden !important;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    width:100% !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

.sidebar .sidebar-wrap,
#dashboardSidebar .sidebar-wrap,
#sidebar .sidebar-wrap{
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar .sidebar-inner,
#dashboardSidebar .sidebar-inner,
#sidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

.sidebar .sidebar-menu,
#dashboardSidebar .sidebar-menu,
#sidebar .sidebar-menu{
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
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
    transition:margin-left .25s ease !important;
}

.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
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
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
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
    position:relative !important;
    z-index:1 !important;
}

/* ================= SIDEBAR COLLAPSE ================= */
body.dashboard-sidebar-collapsed .sidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed .sidebar .search-box,
body.dashboard-sidebar-collapsed .sidebar .menu-title,
body.dashboard-sidebar-collapsed .sidebar .menu-arrow,
body.dashboard-sidebar-collapsed .sidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-icon{
    margin:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .sidebar-inner{
    padding-left:8px !important;
    padding-right:8px !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn{
    width:52px !important;
    height:42px !important;
    margin:0 auto !important;
    padding:0 !important;
    border-radius:12px !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn span{
    display:none !important;
}

/* ================= PAGE TITLE ================= */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin-bottom:18px !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

/* ================= STATS ================= */
.stats-row{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    color:#fff !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
    z-index:-1;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card .title{
    font-size:15px !important;
    font-weight:900 !important;
    color:rgba(255,255,255,.94) !important;
}

.stat-card .count{
    font-size:30px !important;
    font-weight:900 !important;
    line-height:1 !important;
    color:#fff !important;
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
.card-green{ background:linear-gradient(135deg,#047857,#16a34a) !important; }
.card-cyan{ background:linear-gradient(135deg,#0891b2,#06b6d4) !important; }
.card-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c) !important; }

/* ================= FILTER BAR ================= */
.filter-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    padding:14px !important;
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    align-items:center !important;
    margin-bottom:18px !important;
}

.search-wrap{
    flex:1.3 !important;
    min-width:220px !important;
    position:relative !important;
}

.search-wrap i{
    position:absolute !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#a78bfa !important;
    z-index:2 !important;
}

.search-wrap input,
.filter-control{
    width:100% !important;
    height:44px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
    box-shadow:none !important;
}

.search-wrap input{
    padding-left:42px !important;
}

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.filter-control{
    min-width:180px !important;
}

.filter-control option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.search-wrap input:focus,
.filter-control:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
}

.filter-btn,
.add-btn{
    height:44px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 18px !important;
    color:#fff !important;
    font-weight:900 !important;
    text-decoration:none !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    transition:.22s ease !important;
}

.filter-btn{
    background:rgba(15,23,42,.62) !important;
}

.filter-btn:hover{
    transform:translateY(-2px) !important;
    background:rgba(124,58,237,.20) !important;
    border-color:rgba(139,92,246,.42) !important;
    color:#fff !important;
}

.add-btn{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    border:none !important;
}

.add-btn:hover{
    transform:translateY(-2px) !important;
}

/* ================= CHARTS / TOP LIST ================= */
.chart-grid{
    display:grid !important;
    grid-template-columns:2fr 1fr !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.chart-card,
.top-list-card,
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    transition:.25s ease !important;
}

.chart-card:hover,
.top-list-card:hover,
.table-card:hover{
    transform:translateY(-3px) !important;
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.card-title{
    padding:17px 20px 12px !important;
    font-size:18px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    background:rgba(7,11,20,.32) !important;
    border-bottom:1px solid rgba(148,163,184,.13) !important;
}

.chart-body{
    padding:14px 18px 18px !important;
}

.chart-area{
    height:250px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:16px !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.12), transparent 34%),
        linear-gradient(135deg, rgba(7,11,20,.36), rgba(15,23,42,.30)) !important;
    padding:16px !important;
    display:flex !important;
    align-items:flex-end !important;
    gap:14px !important;
}

.bar{
    width:100% !important;
    max-width:36px !important;
    background:linear-gradient(180deg,#8b5cf6,#6d4cff) !important;
    border-radius:8px 8px 0 0 !important;
    min-height:8px !important;
    box-shadow:0 0 22px rgba(124,58,237,.24) !important;
}

.bar-label{
    margin-top:10px !important;
    font-size:12px !important;
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.bar-value{
    margin-bottom:8px !important;
    font-size:11px !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
}

.top-list{
    padding:0 18px 18px !important;
}

.top-item{
    display:flex !important;
    justify-content:space-between !important;
    gap:10px !important;
    align-items:center !important;
    padding:13px 0 !important;
    border-bottom:1px solid rgba(148,163,184,.12) !important;
    transition:.2s ease !important;
}

.top-item:hover{
    background:rgba(124,58,237,.08) !important;
    border-radius:12px !important;
    padding-left:10px !important;
    padding-right:10px !important;
}

.top-name{
    font-weight:900 !important;
    color:#f8fafc !important;
}

.top-amount{
    font-weight:900 !important;
    color:#cbd5e1 !important;
}

/* ================= TABLE ================= */
.table-responsive{
    overflow:auto !important;
}

.suppliers-table{
    width:100% !important;
    min-width:1250px !important;
    border-collapse:collapse !important;
    background:transparent !important;
}

.suppliers-table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:15px 14px !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.suppliers-table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
}

/* White hover remove */
.suppliers-table tbody tr:hover,
.suppliers-table tbody tr:hover td,
.suppliers-table tbody td:hover{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color:#ffffff !important;
    box-shadow:none !important;
}

.suppliers-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.supplier-main{
    font-weight:900 !important;
    color:#f8fafc !important;
}

.supplier-sub{
    display:block !important;
    font-size:12px !important;
    color:#94a3b8 !important;
    margin-top:4px !important;
    white-space:normal !important;
}

.balance-amt{
    font-weight:900 !important;
    color:#f8fafc !important;
}

.status-badge{
    padding:7px 12px !important;
    border-radius:999px !important;
    font-size:12px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.status-active{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

.status-pending{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}

.status-inactive{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.25) !important;
}

.due-badge{
    padding:7px 12px !important;
    border-radius:999px !important;
    font-size:12px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    gap:6px !important;
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}

/* Actions */
.actions{
    display:flex !important;
    gap:10px !important;
    align-items:center !important;
    justify-content:center !important;
}

.icon-btn{
    width:40px !important;
    height:40px !important;
    border:none !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:15px !important;
    cursor:pointer !important;
    transition:all .20s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    text-decoration:none !important;
    padding:0 !important;
}

.icon-btn:hover{
    transform:translateY(-2px) !important;
}

.icon-view{
    background:rgba(124,58,237,.18) !important;
    color:#a78bfa !important;
    border:1px solid rgba(124,58,237,.28) !important;
}

.icon-view:hover{
    background:#7c3aed !important;
    color:#fff !important;
}

.icon-edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.icon-edit:hover{
    background:#f59e0b !important;
    color:#07111f !important;
}

.icon-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

.icon-delete:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

/* Detail row */
.detail-row{
    display:none;
    background:transparent !important;
}

.detail-row td{
    background:rgba(15,23,42,.34) !important;
}

.detail-box{
    padding:18px !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.14), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.56), rgba(17,24,39,.42)) !important;
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.14) !important;
}

.detail-title{
    font-size:18px !important;
    font-weight:900 !important;
    margin-bottom:12px !important;
    color:#f8fafc !important;
}

.summary-grid{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:12px !important;
}

.summary-item{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    padding:13px !important;
}

.summary-item span{
    display:block !important;
    color:#94a3b8 !important;
    font-size:12px !important;
    margin-bottom:4px !important;
    font-weight:800 !important;
}

.summary-item strong{
    color:#f8fafc !important;
    font-size:15px !important;
}

/* Bottom actions */
.bottom-actions{
    padding:14px 16px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.30) !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    flex-wrap:wrap !important;
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.bulk-tools{
    display:flex !important;
    gap:10px !important;
    align-items:center !important;
    flex-wrap:wrap !important;
}

.delete-btn,
.csv-btn{
    border:none !important;
    border-radius:12px !important;
    padding:11px 16px !important;
    font-weight:900 !important;
    color:#fff !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    transition:.2s ease !important;
}

.delete-btn{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
}

.csv-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
}

.delete-btn:hover,
.csv-btn:hover{
    transform:translateY(-2px) !important;
}

/* ================= SUPPLIER POPUP - PURPLE GLASS ================= */
body.modal-open .modal-backdrop.show,
.modal-backdrop.show{
    opacity:.52 !important;
    background:#000 !important;
}

/* popup ko thora compact */
#supplierModal .modal-dialog{
    max-width:920px !important;
    width:920px !important;
    margin:1rem auto !important;
}

#supplierModal .modal-content,
#supplierModal .supplier-modal-content{
    max-height:86vh !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.86), rgba(17,24,39,.70)) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 24px 70px rgba(0,0,0,.58),
        0 0 45px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

#supplierModal .modal-header,
#supplierModal .supplier-modal-header{
    min-height:56px !important;
    padding:12px 16px !important;
    background:rgba(7,11,20,.30) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

#supplierModal .modal-title,
#supplierModal .supplier-modal-header .modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
    margin:0 !important;
}

#supplierModal .modal-title::before{
    display:none !important;
}

#supplierModal .modal-body,
#supplierModal .supplier-modal-body{
    background:rgba(15,23,42,.22) !important;
    color:#f8fafc !important;
    padding:14px 16px !important;
    max-height:64vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

#supplierModal .modal-footer,
#supplierModal .supplier-modal-footer{
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    padding:10px 16px !important;
    min-height:54px !important;
}

/* Form labels */
#supplierModal .supplier-label,
#supplierModal label{
    color:#e5e7eb !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#supplierModal .supplier-label span{
    color:#a78bfa !important;
}

/* Inputs */
#supplierModal .supplier-input,
#supplierModal .supplier-textarea,
#supplierModal input[type="text"],
#supplierModal input[type="email"],
#supplierModal input[type="number"],
#supplierModal select,
#supplierModal textarea{
    background:rgba(255,255,255,.92) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}

#supplierModal .supplier-input,
#supplierModal input[type="text"],
#supplierModal input[type="email"],
#supplierModal input[type="number"],
#supplierModal select{
    height:36px !important;
    min-height:36px !important;
    padding:5px 10px !important;
}

#supplierModal .supplier-textarea,
#supplierModal textarea{
    min-height:60px !important;
    height:60px !important;
    padding:8px 10px !important;
    resize:vertical !important;
}

#supplierModal .supplier-input:focus,
#supplierModal .supplier-textarea:focus,
#supplierModal input:focus,
#supplierModal select:focus,
#supplierModal textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.20),
        0 8px 18px rgba(0,0,0,.18) !important;
    outline:none !important;
}

/* Upload box */
#supplierModal .supplier-upload-box{
    background:rgba(15,23,42,.44) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    padding:12px !important;
}

#supplierModal .supplier-upload-preview{
    background:rgba(7,11,20,.30) !important;
    border:1px dashed rgba(139,92,246,.38) !important;
    border-radius:14px !important;
    min-height:150px !important;
}

#supplierModal .supplier-upload-preview i{
    color:#a78bfa !important;
}

#supplierModal .supplier-upload-title{
    color:#f8fafc !important;
    font-weight:900 !important;
    font-size:15px !important;
}

#supplierModal .supplier-upload-subtext,
#supplierModal .supplier-note,
#supplierModal .supplier-doc-name,
#supplierModal .supplier-or-sep{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

#supplierModal .supplier-upload-btn{
    background:rgba(15,23,42,.70) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:10px !important;
    font-weight:900 !important;
    height:38px !important;
}

#supplierModal .supplier-upload-btn:hover{
    background:rgba(124,58,237,.18) !important;
    color:#fff !important;
}

/* Toggle */
#supplierModal .supplier-toggle-wrap{
    background:rgba(15,23,42,.44) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:9px 12px !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
}

#supplierModal .form-check-input{
    box-shadow:none !important;
}

#supplierModal .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
}

/* Footer */
#supplierModal .supplier-footer-note{
    color:#94a3b8 !important;
    font-weight:800 !important;
    font-size:12px !important;
}

#supplierModal .supplier-cancel-btn,
#supplierModal .supplier-save-btn{
    height:36px !important;
    border:none !important;
    border-radius:10px !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.18) !important;
}

#supplierModal .supplier-cancel-btn{
    min-width:95px !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
}

#supplierModal .supplier-save-btn{
    min-width:125px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

/* Close button */
#supplierModal .btn-close{
    width:34px !important;
    height:34px !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    background-image:none !important;
    position:relative !important;
    padding:0 !important;
}

#supplierModal .btn-close::before,
#supplierModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:16px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#supplierModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#supplierModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#supplierModal .btn-close:hover{
    background:rgba(239,68,68,.24) !important;
}

/* ================= RESPONSIVE ================= */
@media(max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2,1fr) !important;
    }

    .chart-grid{
        grid-template-columns:1fr !important;
    }

    #supplierModal .modal-dialog{
        width:calc(100% - 24px) !important;
        max-width:calc(100% - 24px) !important;
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

    .sidebar .sidebar-wrap,
    #dashboardSidebar .sidebar-wrap,
    #sidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
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
    .stats-row{
        grid-template-columns:1fr !important;
    }

    .filter-bar{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .search-wrap,
    .filter-control,
    .filter-btn,
    .add-btn{
        width:100% !important;
        min-width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .summary-grid{
        grid-template-columns:1fr 1fr !important;
    }

    #supplierModal .supplier-modal-footer{
        align-items:stretch !important;
        flex-direction:column !important;
    }

    #supplierModal .supplier-footer-btns{
        width:100% !important;
        flex-direction:column !important;
    }

    #supplierModal .supplier-cancel-btn,
    #supplierModal .supplier-save-btn{
        width:100% !important;
    }
}

@media(max-width:500px){
    .summary-grid{
        grid-template-columns:1fr !important;
    }
}


/* Supplier product assignment + ledger styles */
.assigned-products{
    display:inline-block;
    max-width:220px;
    color:#e5e7eb;
    font-weight:800;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.product-count-badge{
    display:inline-flex;
    min-width:36px;
    height:28px;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    background:rgba(34,197,94,.14);
    color:#4ade80;
    border:1px solid rgba(34,197,94,.24);
    font-size:12px;
    font-weight:900;
    padding:0 10px;
}
.icon-ledger{
    background:rgba(99,102,241,.16) !important;
    color:#a5b4fc !important;
    border:1px solid rgba(99,102,241,.28) !important;
    text-decoration:none !important;
}
.icon-ledger:hover{
    background:#6366f1 !important;
    color:#fff !important;
}

</style>
</head>
<body>

<div class="container-scroll">
 <div class="sidebar" id="dashboardSidebar">
         <div class="sidebar" id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>
    </div>
    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">
            <h4 class="page-title">Suppliers Management</h4>

            <div class="stats-row mb-3">
                <div class="stat-card card-blue">
                    <div><div class="title">Total Suppliers</div></div>
                    <div class="count"><?= $totalSuppliers ?></div>
                </div>
                <div class="stat-card card-green">
                    <div><div class="title">Active Suppliers</div></div>
                    <div class="count"><?= $activeSuppliers ?></div>
                </div>
                <div class="stat-card card-cyan">
                    <div><div class="title">Pending Suppliers</div></div>
                    <div class="count"><?= $pendingSuppliers ?></div>
                </div>
                <div class="stat-card card-yellow">
                    <div><div class="title">Overdue Payments</div></div>
                    <div class="count">PKR <?= number_format($overduePayments, 0) ?></div>
                </div>
            </div>

            <form method="GET" class="filter-bar">
                <div class="search-wrap">
                    <i class="fa fa-search"></i>
                    <input type="text" name="search" placeholder="Search suppliers..." value="<?= e($search) ?>">
                </div>

                <select name="status" class="filter-control">
                    <option value="">All Status</option>
                    <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>

                <button class="filter-btn" type="submit">
                    <i class="fa fa-filter me-2"></i> Filter
                </button>

                <a href="suppliers.php" class="filter-btn" style="background:#6b7280;">
                    <i class="fa fa-rotate-left me-2"></i> Reset
                </a>

                <button class="add-btn" type="button" id="openSupplierModalBtn">
                    <i class="fa fa-plus me-2"></i> Add Supplier
                </button>
            </form>

            <div class="chart-grid">
                <div class="chart-card">
                    <div class="card-title">Monthly Supplier Spend</div>
                    <div class="chart-body">
                        <div class="chart-area">
                            <?php foreach ($monthlySpend as $monthNum => $value): 
                                $height = max(8, ($value / $chartMax) * 180);
                            ?>
                                <div class="bar-wrap">
                                    <div class="bar-value"><?= number_format($value, 0) ?></div>
                                    <div class="bar" style="height:<?= (float)$height ?>px;"></div>
                                    <div class="bar-label"><?= $monthLabels[$monthNum - 1] ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="top-list-card">
                    <div class="card-title">Top Suppliers</div>
                    <div class="top-list">
                        <?php if (empty($topSuppliers)): ?>
                            <div class="text-muted">No supplier data</div>
                        <?php else: ?>
                            <?php foreach ($topSuppliers as $ts): ?>
                                <div class="top-item">
                                    <div class="top-name"><?= e(supplierName($ts)) ?></div>
                                    <div class="top-amount">PKR <?= number_format((float)($ts['opening_balance'] ?? 0), 0) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <form id="bulkDeleteForm">
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="suppliers-table">
                            <thead>
                                <tr>
                                    <th style="width:50px;"><input type="checkbox" id="checkAll"></th>
                                    <th>ID</th>
                                    <th>Supplier</th>
                                    <th>Contact Person</th>
                                    <th>Phone / WhatsApp</th>
                                    <th>Supplier Type</th>
                                    <th>Assigned Products</th>
                                    <th>Total Products</th>
                                    <th>Balance</th>
                                    <th>Pending</th>
                                    <th>Status</th>
                                    <th style="width:160px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($suppliers)): ?>
                                    <tr>
                                        <td colspan="12" class="text-center py-4">No suppliers found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($suppliers as $s): ?>
                                        <tr>
                                            <td><input type="checkbox" class="rowCheck" value="<?= (int)$s['id'] ?>"></td>
                                            <td><?= (int)$s['id'] ?></td>
                                            <td>
                                                <span class="supplier-main"><?= e(supplierName($s)) ?></span>
                                                <span class="supplier-sub"><?= e($s['email'] ?? '') ?></span>
                                            </td>
                                            <td><?= e($s['contact_person'] ?: '-') ?></td>
                                            <td><?= e($s['phone'] ?: '-') ?></td>
                                            <td><?= e($s['supplier_type'] ?: '-') ?></td>
                                            <td>
                                                <?php if (!empty($s['assigned_products'])): ?>
                                                    <span class="assigned-products" title="<?= e($s['assigned_products']) ?>">
                                                        <?= e(shortText($s['assigned_products'], 60)) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">No Products</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="product-count-badge"><?= (int)($s['total_products'] ?? 0) ?></span>
                                            </td>
                                            <td>
                                                <span class="balance-amt">PKR <?= number_format((float)($s['current_balance'] ?? 0), 0) ?></span>
                                            </td>
                                            <td>
                                                <?php if ((int)($s['pending_days'] ?? 0) > 0): ?>
                                                    <span class="due-badge">
                                                        <i class="fa fa-clock"></i>
                                                        Due <?= (int)$s['pending_days'] ?> days
                                                    </span>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?= supplierStatusClass((string)($s['status'] ?? '')) ?>">
                                                    <?= e($s['status'] ?: 'Inactive') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="actions">
    <button
        type="button"
        class="icon-btn icon-view toggle-detail"
        data-id="<?= (int)$s['id'] ?>"
        title="View">
        <i class="bi bi-eye-fill"></i>
    </button>

    <a
        href="supplier_ledger.php?supplier_id=<?= (int)$s['id'] ?>"
        class="icon-btn icon-ledger"
        title="Supplier Ledger">
        <i class="bi bi-journal-text"></i>
    </a>

    <button type="button"
        class="icon-btn icon-edit edit-btn"
        data-id="<?= (int)$s['id'] ?>"
        data-company_name="<?= e($s['company_name'] ?? '') ?>"
        data-contact_person="<?= e($s['contact_person'] ?? '') ?>"
        data-phone="<?= e($s['phone'] ?? '') ?>"
        data-email="<?= e($s['email'] ?? '') ?>"
        data-supplier_type="<?= e($s['supplier_type'] ?? '') ?>"
        data-payment_terms="<?= e($s['payment_terms'] ?? '') ?>"
        data-ntn_strn="<?= e($s['ntn_strn'] ?? '') ?>"
        data-credit_limit="<?= e($s['credit_limit'] ?? '') ?>"
        data-full_address="<?= e($s['full_address'] ?? '') ?>"
        data-opening_balance="<?= e($s['opening_balance'] ?? '') ?>"
        data-status="<?= e($s['status'] ?? 'Active') ?>"
        data-pending_days="<?= e($s['pending_days'] ?? 0) ?>"
        data-notes="<?= e($s['notes'] ?? '') ?>"
        data-preferred_supplier="<?= (int)($s['preferred_supplier'] ?? 0) ?>"
        title="Edit">
        <i class="bi bi-pencil-fill"></i>
    </button>

    <button
        type="button"
        class="icon-btn icon-delete delete-btn-single"
        data-id="<?= (int)$s['id'] ?>"
        title="Delete">
        <i class="bi bi-trash-fill"></i>
    </button>
</div>
                                            </td>
                                        </tr>

                                        <tr class="detail-row" id="detail-<?= (int)$s['id'] ?>">
                                            <td colspan="12">
                                                <div class="detail-box">
                                                    <div class="detail-title">Supplier Details</div>

                                                    <div class="summary-grid">
                                                        <div class="summary-item">
                                                            <span>Company Name</span>
                                                            <strong><?= e(supplierName($s)) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Payment Terms</span>
                                                            <strong><?= e($s['payment_terms'] ?: '-') ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Credit Limit</span>
                                                            <strong>PKR <?= number_format((float)($s['credit_limit'] ?? 0), 0) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Opening Balance</span>
                                                            <strong>PKR <?= number_format((float)($s['opening_balance'] ?? 0), 0) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Assigned Products</span>
                                                            <strong><?= !empty($s['assigned_products']) ? e(shortText($s['assigned_products'], 120)) : 'No Products' ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Total Products</span>
                                                            <strong><?= (int)($s['total_products'] ?? 0) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Total Purchase</span>
                                                            <strong>PKR <?= number_format((float)($s['total_purchase'] ?? 0), 0) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Total Paid</span>
                                                            <strong>PKR <?= number_format((float)($s['total_paid'] ?? 0), 0) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Current Balance</span>
                                                            <strong>PKR <?= number_format((float)($s['current_balance'] ?? 0), 0) ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>NTN / STRN</span>
                                                            <strong><?= e($s['ntn_strn'] ?: '-') ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Address</span>
                                                            <strong><?= e(supplierAddress($s) ?: '-') ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Preferred Supplier</span>
                                                            <strong><?= (int)($s['preferred_supplier'] ?? 0) === 1 ? 'Yes' : 'No' ?></strong>
                                                        </div>
                                                        <div class="summary-item">
                                                            <span>Notes</span>
                                                            <strong><?= e($s['notes'] ?: '-') ?></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="bottom-actions">
                        <div class="bulk-tools">
                            <button type="button" class="delete-btn" id="bulkDeleteBtn">
                                <i class="fa fa-trash me-2"></i> Delete Selected
                            </button>

                            <button type="button" class="csv-btn" id="exportCsvBtn">
                                <i class="fa fa-download me-2"></i> Export CSV
                            </button>
                        </div>

                        <div>Showing <?= count($suppliers) ?> suppliers</div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- OLD POPUP DESIGN KE STYLE ME -->
<div class="modal fade" id="supplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content supplier-modal-content">
            <form id="supplierForm" enctype="multipart/form-data">
                <input type="hidden" name="supplier_id" id="supplier_id">

                <div class="modal-header supplier-modal-header">
                    <h5 class="modal-title" id="supplierModalTitle">Add Supplier</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body supplier-modal-body">
                    <div class="row g-4 align-items-start">
                        <div class="col-lg-4">
                            <div class="supplier-upload-box">
                                <div class="supplier-upload-preview" id="uploadPhotoBox" onclick="document.getElementById('photo').click()">
                                    <i class="fa fa-camera"></i>
                                    <div class="supplier-upload-title">Upload Supplier Photo</div>
                                    <div class="supplier-upload-subtext">JPG, PNG, WEBP</div>
                                    <img id="photoPreview" class="preview-img" alt="Photo Preview">
                                </div>

                                <button type="button" class="supplier-upload-btn" onclick="document.getElementById('photo').click()">
                                    <i class="fa fa-cloud-upload-alt me-2"></i>Choose Photo
                                </button>
                                <input type="file" name="photo" id="photo" accept=".jpg,.jpeg,.png,.webp" hidden>

                                <div class="supplier-or-sep">— OR —</div>

                                <div class="supplier-upload-preview" style="min-height:150px;" onclick="document.getElementById('document').click()">
                                    <i class="fa fa-paperclip"></i>
                                    <div class="supplier-upload-title">Upload Documents</div>
                                    <div class="supplier-upload-subtext">JPG, PNG, PDF</div>
                                </div>
                                <input type="file" name="document" id="document" accept=".jpg,.jpeg,.png,.pdf" hidden>
                                <div id="docName" class="supplier-doc-name"></div>

                                <div class="supplier-note">
                                    Photo aur document optional hain. Edit me purani files alag handle hongi.
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="supplier-label">Company Name <span>*</span></label>
                                    <input type="text" class="supplier-input" name="company_name" id="company_name" required placeholder="Enter company name">
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Contact Person <span>*</span></label>
                                    <input type="text" class="supplier-input" name="contact_person" id="contact_person" required placeholder="Enter contact person">
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Phone / WhatsApp <span>*</span></label>
                                    <input type="text" class="supplier-input" name="phone" id="phone" required placeholder="Enter phone number">
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Email</label>
                                    <input type="email" class="supplier-input" name="email" id="email" placeholder="Enter email">
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Supplier Type</label>
                                    <select class="supplier-input" name="supplier_type" id="supplier_type">
                                        <option value="">Select supplier type...</option>
                                        <option value="Distributor">Distributor</option>
                                        <option value="Manufacturer">Manufacturer</option>
                                        <option value="Wholesaler">Wholesaler</option>
                                        <option value="General">General</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Payment Terms</label>
                                    <select class="supplier-input" name="payment_terms" id="payment_terms">
                                        <option value="">Select payment terms...</option>
                                        <option value="Cash">Cash</option>
                                        <option value="7 Days">7 Days</option>
                                        <option value="15 Days">15 Days</option>
                                        <option value="30 Days">30 Days</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">NTN / STRN</label>
                                    <input type="text" class="supplier-input" name="ntn_strn" id="ntn_strn" placeholder="Enter NTN / STRN">
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Credit Limit (PKR)</label>
                                    <input type="number" step="0.01" class="supplier-input" name="credit_limit" id="credit_limit" placeholder="0.00">
                                </div>

                                <div class="col-12">
                                    <label class="supplier-label">Full Address</label>
                                    <textarea class="supplier-textarea" name="full_address" id="full_address" placeholder="Enter full address"></textarea>
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Opening Balance (PKR)</label>
                                    <input type="number" step="0.01" class="supplier-input" name="opening_balance" id="opening_balance" placeholder="0.00">
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Status</label>
                                    <select class="supplier-input" name="status" id="status">
                                        <option value="Active">Active</option>
                                        <option value="Pending">Pending</option>
                                        <option value="Inactive">Inactive</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="supplier-label">Pending Days</label>
                                    <input type="number" class="supplier-input" name="pending_days" id="pending_days" placeholder="0">
                                </div>

                                <div class="col-12">
                                    <label class="supplier-label">Notes / Remarks</label>
                                    <textarea class="supplier-textarea" name="notes" id="notes" placeholder="Write notes here..."></textarea>
                                </div>

                                <div class="col-12">
                                    <div class="supplier-toggle-wrap">
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" name="preferred_supplier" id="preferred_supplier" value="1">
                                        </div>
                                        Preferred Supplier
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer supplier-modal-footer">
                    <div class="supplier-footer-note">Create or update supplier details.</div>

                    <div class="supplier-footer-btns">
                        <button type="button" class="supplier-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="supplier-save-btn">Save Supplier</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
const supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));

function resetSupplierForm() {
    document.getElementById('supplierForm').reset();
    document.getElementById('supplier_id').value = '';
    document.getElementById('supplierModalTitle').innerText = 'Add Supplier';
    document.getElementById('photoPreview').style.display = 'none';
    document.getElementById('photoPreview').src = '';
    document.getElementById('docName').style.display = 'none';
    document.getElementById('docName').textContent = '';
}

document.getElementById('openSupplierModalBtn').addEventListener('click', function() {
    resetSupplierForm();
    supplierModal.show();
});

document.querySelectorAll('.toggle-detail').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const row = document.getElementById('detail-' + id);
        row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
    });
});

document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        resetSupplierForm();

        document.getElementById('supplier_id').value = this.dataset.id || '';
        document.getElementById('company_name').value = this.dataset.company_name || '';
        document.getElementById('contact_person').value = this.dataset.contact_person || '';
        document.getElementById('phone').value = this.dataset.phone || '';
        document.getElementById('email').value = this.dataset.email || '';
        document.getElementById('supplier_type').value = this.dataset.supplier_type || '';
        document.getElementById('payment_terms').value = this.dataset.payment_terms || '';
        document.getElementById('ntn_strn').value = this.dataset.ntn_strn || '';
        document.getElementById('credit_limit').value = this.dataset.credit_limit || '';
        document.getElementById('full_address').value = this.dataset.full_address || '';
        document.getElementById('opening_balance').value = this.dataset.opening_balance || '';
        document.getElementById('status').value = this.dataset.status || 'Active';
        document.getElementById('pending_days').value = this.dataset.pending_days || '';
        document.getElementById('notes').value = this.dataset.notes || '';
        document.getElementById('preferred_supplier').checked = Number(this.dataset.preferred_supplier || 0) === 1;

        document.getElementById('supplierModalTitle').innerText = 'Edit Supplier';
        supplierModal.show();
    });
});

document.querySelectorAll('.delete-btn-single').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;
        if (!confirm('Delete this supplier?')) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('supplier_id', id);

        const response = await fetch('ajax_suppliers.php', {
            method: 'POST',
            body: formData
        });

        const text = await response.text();
        if (text.trim() === 'deleted') {
            location.reload();
        } else {
            alert(text);
        }
    });
});

document.getElementById('supplierForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'save');

    const response = await fetch('ajax_suppliers.php', {
        method: 'POST',
        body: formData
    });

    const text = await response.text();
    if (text.trim() === 'saved') {
        location.reload();
    } else {
        alert(text);
    }
});

document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.rowCheck').forEach(ch => ch.checked = this.checked);
});

document.getElementById('bulkDeleteBtn').addEventListener('click', async function() {
    const ids = Array.from(document.querySelectorAll('.rowCheck:checked')).map(el => el.value);
    if (!ids.length) {
        alert('Select at least one supplier');
        return;
    }

    if (!confirm('Delete selected suppliers?')) return;

    const formData = new FormData();
    formData.append('action', 'bulk_delete');
    ids.forEach(id => formData.append('ids[]', id));

    const response = await fetch('ajax_suppliers.php', {
        method: 'POST',
        body: formData
    });

    const text = await response.text();
    if (text.trim() === 'deleted') {
        location.reload();
    } else {
        alert(text);
    }
});

document.getElementById('exportCsvBtn').addEventListener('click', function() {
    let csv = 'ID,Company Name,Contact Person,Phone,Email,Supplier Type,Payment Terms,Opening Balance,Status\n';
    <?php foreach ($suppliers as $s): ?>
    csv += `"<?= (int)$s['id'] ?>","<?= addslashes(supplierName($s)) ?>","<?= addslashes($s['contact_person'] ?? '') ?>","<?= addslashes($s['phone'] ?? '') ?>","<?= addslashes($s['email'] ?? '') ?>","<?= addslashes($s['supplier_type'] ?? '') ?>","<?= addslashes($s['payment_terms'] ?? '') ?>","<?= (float)($s['opening_balance'] ?? 0) ?>","<?= addslashes($s['status'] ?? '') ?>"\n`;
    <?php endforeach; ?>

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'suppliers.csv';
    link.click();
});

document.getElementById('photo').addEventListener('change', function() {
    const file = this.files[0];
    const preview = document.getElementById('photoPreview');

    if (!file) {
        preview.style.display = 'none';
        preview.src = '';
        return;
    }

    preview.src = URL.createObjectURL(file);
    preview.style.display = 'block';
});

document.getElementById('document').addEventListener('change', function() {
    const file = this.files[0];
    const docName = document.getElementById('docName');

    if (!file) {
        docName.style.display = 'none';
        docName.textContent = '';
        return;
    }

    docName.textContent = file.name;
    docName.style.display = 'block';
});
</script>
</body>
</html>