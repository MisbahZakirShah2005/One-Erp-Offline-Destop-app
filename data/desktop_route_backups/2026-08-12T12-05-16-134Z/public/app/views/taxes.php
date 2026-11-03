<?php
// ================= ERROR DISPLAY =================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = $_SESSION['client_id'] ?? 0;

// ================= HANDLE MODAL SAVE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tax'])) {
    $taxId = !empty($_POST['tax_id']) ? (int)$_POST['tax_id'] : null;
    $name = trim($_POST['name']);
    $rate = floatval($_POST['rate']);
    $type = $_POST['type'] ?? 'General';
    $application_on = $_POST['application_on'] ?? 'All Sales';
    $region = $_POST['region'] ?? null;
    $inclusive = isset($_POST['inclusive']) ? 1 : 0;
    $status = $_POST['status'] ?? 'Active';

    // Validation
    if (empty($name) || $rate < 0) {
        $_SESSION['error'] = "Please enter a valid Tax Name and Rate.";
        header("Location: taxes.php");
        exit;
    }

    if ($taxId) {
        // UPDATE existing tax
        $stmt = $conn->prepare("
            UPDATE taxes 
            SET name=?, rate=?, type=?, application_on=?, region=?, inclusive=?, status=?, updated_at=NOW() 
            WHERE id=? AND client_id=?
        ");
        $stmt->bind_param(
            "sdsssiiii", 
            $name, $rate, $type, $application_on, $region, $inclusive, $status, $taxId, $client_id
        );
        $msg = "Tax updated successfully!";
    } else {
        // INSERT new tax
        $stmt = $conn->prepare("
            INSERT INTO taxes 
            (client_id, name, rate, type, application_on, region, inclusive, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param(
            "isdsssis", 
            $client_id, $name, $rate, $type, $application_on, $region, $inclusive, $status
        );
        $msg = "Tax added successfully!";
    }

    if ($stmt->execute()) {
        $_SESSION['success'] = $msg;
    } else {
        $_SESSION['error'] = "Database error: " . $stmt->error;
    }

    $stmt->close();
    header("Location: taxes.php");
    exit;
}

// ================= FILTER VARIABLES =================
$search     = $_GET['search'] ?? '';
$region     = $_GET['region'] ?? '';
$status     = $_GET['status'] ?? '';
$type       = $_GET['type'] ?? '';
$from_date  = $_GET['from_date'] ?? '';
$to_date    = $_GET['to_date'] ?? '';

// ================= FETCH BRANCHES =================
$branches = [];
$res = $conn->query("SELECT id, name FROM branches WHERE client_id=$client_id ORDER BY name ASC");
while($row = $res->fetch_assoc()){
    $branches[] = $row;
}

// ================= FETCH TAXES =================
$taxes = [];
$stmt = $conn->prepare("SELECT * FROM taxes WHERE client_id=? ORDER BY id DESC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $taxes[] = $r;
$stmt->close();

// ================= TAX STATS =================
$totalTaxRules = $conn->prepare("SELECT COUNT(*) as total FROM taxes WHERE client_id=? AND status='Active'");
$totalTaxRules->bind_param("i", $client_id);
$totalTaxRules->execute();
$totalTaxRules = $totalTaxRules->get_result()->fetch_assoc()['total'] ?? 0;

$totalTaxCollected = $conn->prepare("SELECT SUM(tax) as total FROM sales WHERE client_id=?");
$totalTaxCollected->bind_param("i", $client_id);
$totalTaxCollected->execute();
$totalTaxCollected = $totalTaxCollected->get_result()->fetch_assoc()['total'] ?? 0;

$pendingTax = $conn->prepare("SELECT SUM(tax) as total FROM sales WHERE client_id=? AND due_date >= CURDATE()");
$pendingTax->bind_param("i", $client_id);
$pendingTax->execute();
$pendingTax = $pendingTax->get_result()->fetch_assoc()['total'] ?? 0;

// ================= CURRENT & LAST MONTH TAX =================
$stmt = $conn->prepare("SELECT SUM(tax) as total FROM sales WHERE client_id=? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$currentMonthTax = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT SUM(tax) as total FROM sales WHERE client_id=? AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$lastMonthTax = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

// ================= FILTERED SALES =================
$query = "SELECT * FROM sales WHERE client_id=?";
$params = [$client_id];
$types  = "i";

if (!empty($search)) {
    $query .= " AND (invoice_no LIKE ? OR customer_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

if (!empty($region)) {
    $query .= " AND branch_id=?";
    $params[] = $region;
    $types .= "i";
}

if (!empty($status)) {
    $query .= " AND payment_method=?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($type)) {
    $query .= " AND source=?";
    $params[] = $type;
    $types .= "s";
}

if (!empty($from_date)) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $from_date;
    $types .= "s";
}
if (!empty($to_date)) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $to_date;
    $types .= "s";
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$sales = [];
while($row = $result->fetch_assoc()){
    $sales[] = $row;
}
$stmt->close();

// ================= EXPORT CSV =================
if(isset($_GET['export'])){
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_export.csv"');

    $output = fopen("php://output", "w");
    fputcsv($output, ['Invoice', 'Customer', 'Total', 'Tax', 'Date']);

    foreach($sales as $s){
        fputcsv($output, [
            $s['invoice_no'],
            $s['customer_name'],
            $s['total'],
            $s['tax'],
            $s['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// ================= HELPER FUNCTION =================
function e($str){
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// ================= STATUS OPTIONS =================
$statusOptions = ['Active', 'Inactive'];


// Active taxes ka breakdown
$taxBreakdownLabels = [];
$taxBreakdownValues = [];
$taxBreakdownRates  = [];

$taxBreakdownStmt = $conn->prepare("
    SELECT name, rate
    FROM taxes
    WHERE client_id=? AND status='Active'
    ORDER BY rate DESC, name ASC
    LIMIT 5
");
$taxBreakdownStmt->bind_param("i", $client_id);
$taxBreakdownStmt->execute();
$taxBreakdownRes = $taxBreakdownStmt->get_result();

while($row = $taxBreakdownRes->fetch_assoc()){
    $taxBreakdownLabels[] = $row['name'];
    $taxBreakdownValues[] = (float)$row['rate'];
    $taxBreakdownRates[]  = (float)$row['rate'];
}
$taxBreakdownStmt->close();

if(empty($taxBreakdownLabels)){
    $taxBreakdownLabels = ['VAT', 'GST', 'Sales Tax', 'City Tax', 'Other'];
    $taxBreakdownValues = [55.8, 16.7, 14.0, 5.6, 5.3];
    $taxBreakdownRates  = [55.8, 16.7, 14.0, 5.6, 5.3];
}

// Monthly tax trend (last 6 months)
$monthLabels = [];
$vatSeries   = [];
$gstSeries   = [];
$salesSeries = [];

$monthlyTrendStmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%b') AS month_name,
        MONTH(created_at) AS month_num,
        YEAR(created_at) AS year_num,
        SUM(CASE WHEN LOWER(source)='walkin' THEN tax ELSE 0 END) AS vat_total,
        SUM(CASE WHEN LOWER(source)='online' THEN tax ELSE 0 END) AS gst_total,
        SUM(tax) AS sales_total
    FROM sales
    WHERE client_id=? 
      AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY YEAR(created_at), MONTH(created_at), DATE_FORMAT(created_at, '%b')
    ORDER BY YEAR(created_at), MONTH(created_at)
");
$monthlyTrendStmt->bind_param("i", $client_id);
$monthlyTrendStmt->execute();
$monthlyTrendRes = $monthlyTrendStmt->get_result();

while($row = $monthlyTrendRes->fetch_assoc()){
    $monthLabels[] = $row['month_name'];
    $vatSeries[]   = (float)$row['vat_total'];
    $gstSeries[]   = (float)$row['gst_total'];
    $salesSeries[] = (float)$row['sales_total'];
}
$monthlyTrendStmt->close();

if(empty($monthLabels)){
    $monthLabels = ['Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr'];
    $vatSeries   = [250000, 320000, 480000, 610000, 780000, 930000];
    $gstSeries   = [180000, 210000, 260000, 340000, 420000, 510000];
    $salesSeries = [430000, 530000, 740000, 950000, 1200000, 1550000];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tax / VAT - ONE ERP</title>
    
    <script>
(function () {
    const root = document.documentElement;

    function detectTheme() {
        let savedTheme = null;

        try {
            savedTheme =
                localStorage.getItem('oneerp_pos_theme') ||
                localStorage.getItem('oneerp_theme') ||
                localStorage.getItem('theme');
        } catch (e) {
            savedTheme = null;
        }

        if (savedTheme === 'light' || savedTheme === 'dark') {
            return savedTheme;
        }

        const body = document.body;

        const currentTheme =
            root.getAttribute('data-pos-theme') ||
            root.getAttribute('data-theme') ||
            body?.getAttribute('data-pos-theme') ||
            body?.getAttribute('data-theme');

        if (currentTheme === 'light' || currentTheme === 'dark') {
            return currentTheme;
        }

        const hasLightClass =
            root.classList.contains('light') ||
            root.classList.contains('light-theme') ||
            root.classList.contains('light-mode') ||
            body?.classList.contains('light') ||
            body?.classList.contains('light-theme') ||
            body?.classList.contains('light-mode');

        return hasLightClass ? 'light' : 'light';
    }

    function applyTaxTheme() {
        const selectedTheme = detectTheme();
        root.setAttribute('data-pos-theme', selectedTheme);

        if (document.body) {
            document.body.setAttribute('data-pos-theme', selectedTheme);
        }
    }

    applyTaxTheme();
    document.addEventListener('DOMContentLoaded', applyTaxTheme);
    window.addEventListener('storage', applyTaxTheme);

    document.addEventListener('click', function () {
        setTimeout(applyTaxTheme, 80);
    }, true);
})();
</script>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#eef2f7;font-family:Inter,Arial,sans-serif;color:#1f2736}
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

.sidebar > div{
    width:100%;
    min-height:100%;
    height:100%;
}

#dashboardSidebar .sidebar-wrap{
    min-height:100% !important;
    height:100% !important;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100% !important;
}
        .main-panel{flex:1;min-width:0;display:flex;flex-direction:column}
        .topbar{height:64px;background:#05070d;color:#fff;display:flex;align-items:center;padding:0 20px}
        .content-wrapper{padding:18px;flex:1;overflow:auto}
        .page-header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
        .page-title{font-size:22px;font-weight:800;margin:0;color:#243046}
        .btn-yellow{border:none;border-radius:12px;background:linear-gradient(180deg,#f2bc24,#d99d00);color:#fff;font-weight:700;padding:12px 18px;min-width:170px}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
        .stat-card{border-radius:14px;padding:18px;min-height:92px;color:#fff;position:relative;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12)}
        .stat-card::after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12)}
        .stat-red{background:linear-gradient(135deg,#9f2430,#c94b57)}
        .stat-gold{background:linear-gradient(135deg,#b27b16,#d8a33a)}
        .stat-orange{background:linear-gradient(135deg,#b87a16,#dc9b2d)}
        .stat-green{background:linear-gradient(135deg,#386c58,#4c9075)}
        .stat-title{font-size:14px;font-weight:600;line-height:1.3}
        .stat-value{position:absolute;right:18px;bottom:14px;font-size:30px;font-weight:800;line-height:1}
        .stat-sub{margin-top:6px;font-size:13px;opacity:.95}
        .pending-badge{position:absolute;right:15px;top:12px;background:#f8dfa2;color:#7e5900;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:800}
        .filter-card,.table-card,.approval-card{background:#fff;border:1px solid #e6ebf3;border-radius:16px;box-shadow:0 8px 22px rgba(18,38,63,.05)}
        .filter-card{padding:14px;margin-bottom:16px}
        .filter-row{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 120px 120px;gap:10px;margin-bottom:10px}
        .search-row{display:grid;grid-template-columns:1fr;gap:10px}
        .erp-select,.erp-input{height:44px;border:1px solid #dbe2ed;border-radius:10px;background:#fff;padding:0 14px;outline:none;width:100%}
        .filter-btn{height:44px;border:none;border-radius:10px;background:#1f2642;color:#fff;font-weight:700}
        .reset-btn{height:44px;border:none;border-radius:10px;background:#f2f4fa;color:#4b566d;font-weight:700}
        .table-card{padding:0;overflow:hidden}
        .table-wrap{overflow:auto}
        .erp-table{width:100%;min-width:1280px;border-collapse:separate;border-spacing:0}
        .erp-table thead th{background:#1a1f2e;color:#fff;padding:14px 12px;font-size:14px;font-weight:600;white-space:nowrap}
        .erp-table tbody td{background:#fff;padding:13px 12px;border-bottom:1px solid #edf1f6;vertical-align:middle;white-space:nowrap}
        .erp-table tbody tr:hover td{background:#fafcff}
        .erp-table thead th:first-child{border-top-left-radius:12px}
        .erp-table thead th:last-child{border-top-right-radius:12px}
        .expense-cell{display:flex;align-items:center;gap:10px}
        .expense-icon{width:28px;height:28px;border-radius:8px;background:#d4a214;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px}
        .status-pill{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:800}
        .status-approval{background:#f5e2aa;color:#7f5d00}
        .status-pending{background:#f7e4a8;color:#916800}
        .status-completed{background:#dcefdc;color:#2c6b38}
        .action-btn{width:34px;height:34px;border:none;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;margin-right:4px;cursor:pointer}
       .tax-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
}

.tax-btn{
    width:38px;
    height:38px;
    border:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

.tax-btn:hover{
    transform:translateY(-2px);
}

.tax-btn i{
    font-size:15px;
}

/* EDIT */
.tax-edit{
    background:#fff4d6;
    color:#e0a100;
}
.tax-edit:hover{
    background:#e0a100;
    color:#fff;
}

/* DELETE */
.tax-delete{
    background:#ffe3e3;
    color:#ef4444;
}
.tax-delete:hover{
    background:#ef4444;
    color:#fff;
}
        .act-dot{background:#f3f5fa;color:#444}
        .approval-card{margin-top:16px;padding:0;overflow:hidden}
        .approval-head{padding:16px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #edf1f6}
        .approval-title{font-size:16px;font-weight:800;color:#253046;display:flex;align-items:center;gap:10px}
        .approval-body{padding:14px 18px}
        .approval-legend{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:20px;color:#4c566d;font-size:14px}
        .legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}
        .empty-state{text-align:center;color:#6a7488;font-size:18px;padding:24px 0 28px}
        .modal-xl-custom{max-width:760px}
        .exp-modal-content{
            background:
                radial-gradient(circle at top left, rgba(25,43,120,.40), transparent 32%),
                radial-gradient(circle at bottom right, rgba(255,185,0,.12), transparent 28%),
                linear-gradient(180deg,#060916,#0b1031);
            border:none;border-radius:20px;overflow:hidden;color:#fff;box-shadow:0 30px 80px rgba(0,0,0,.35)
        }
        .exp-modal-header{padding:18px 24px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:center}
        .exp-modal-title{font-size:18px;font-weight:700;margin:0;color:#fff}
        .exp-close{background:none;border:none;color:#d8def2;font-size:28px;line-height:1}
        .exp-modal-body{padding:18px 24px 0}
        .dark-label{font-size:13px;font-weight:600;color:#f4f6fd;margin-bottom:6px}
        .req{color:#ffbf43}
        .dark-input,.dark-select,.dark-textarea{
            width:100%;border:none;outline:none;border-radius:8px;background:#f3f5fa;color:#1d2434;
            padding:12px 14px;font-size:14px
        }
        .dark-textarea{min-height:84px;resize:vertical}
        .exp-modal-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.08);display:flex;justify-content:flex-end;gap:12px;margin-top:16px}
        .cancel-btn{min-width:130px;height:44px;border-radius:10px;border:none;background:#5d617a;color:#fff;font-weight:700}
        .save-btn{min-width:140px;height:44px;border-radius:10px;border:none;background:linear-gradient(180deg,#f2bc24,#d99d00);color:#fff;font-weight:800}
        .alert-wrap{margin-bottom:14px}
        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:991px){.filter-row{grid-template-columns:1fr 1fr}}
        @media(max-width:768px){
            .page-header{flex-direction:column;align-items:stretch}
            .stats-grid{grid-template-columns:1fr}
            .filter-row{grid-template-columns:1fr}
        }
        .switch {
    position: relative;
    display: inline-block;
    width: 42px;
    height: 22px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.switch .slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: #ccc;
    transition: .3s;
}

.switch .slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 3px;
    bottom: 3px;
    background-color: #fff;
    transition: .3s;
}

.switch input:checked + .slider {
    background-color: #4CAF50;
}

.switch input:checked + .slider:before {
    transform: translateX(20px);
}

.switch .slider.round {
    border-radius: 22px;
}

.switch .slider.round:before {
    border-radius: 50%;
}

/* ================= TAX CHARTS ================= */
.tax-charts-grid{
    display:grid;
    grid-template-columns: 1fr 1.25fr;
    gap:16px;
    margin:16px 0;
}

.tax-chart-card{
    position:relative;
    overflow:hidden;
    border-radius:18px;
    padding:18px;
    min-height:310px;
    color:#f5f7ff;
    border:1px solid rgba(255,255,255,.06);
    background:
        radial-gradient(circle at top left, rgba(70,45,20,.35), transparent 30%),
        radial-gradient(circle at bottom right, rgba(186,133,54,.10), transparent 30%),
        linear-gradient(180deg,#161018 0%, #120d13 100%);
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.04),
        0 16px 40px rgba(0,0,0,.25);
}

.tax-chart-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:14px;
    gap:10px;
}

.tax-chart-title{
    margin:0;
    font-size:20px;
    font-weight:700;
    color:#f7ead2;
    letter-spacing:.2px;
}

.tax-chart-badge{
    background:linear-gradient(180deg,#5b4314,#3d2c0c);
    color:#f5cf7c;
    border:1px solid rgba(245,207,124,.18);
    font-size:12px;
    padding:6px 10px;
    border-radius:999px;
    line-height:1;
}

.tax-breakdown-wrap{
    display:grid;
    grid-template-columns: 220px 1fr;
    gap:18px;
    align-items:center;
    height:240px;
}

.tax-breakdown-canvas-wrap{
    position:relative;
    width:220px;
    height:220px;
    margin:auto;
}

.tax-center-label{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    pointer-events:none;
}

.tax-center-label strong{
    font-size:34px;
    line-height:1;
    color:#ffffff;
}

.tax-center-label span{
    font-size:14px;
    color:#c9c1c7;
    margin-top:4px;
    letter-spacing:.4px;
}

.tax-legend{
    display:flex;
    flex-direction:column;
    gap:12px;
}

.tax-legend-item{
    display:grid;
    grid-template-columns: 14px 1fr auto auto;
    align-items:center;
    gap:10px;
    color:#ddd5da;
    font-size:14px;
}

.tax-legend-color{
    width:12px;
    height:12px;
    border-radius:3px;
}

.tax-legend-name{
    color:#e8e0e5;
    font-weight:500;
}

.tax-legend-percent{
    color:#bfb6bd;
}

.tax-legend-value{
    color:#f2cb71;
    font-weight:700;
}

.tax-trend-canvas-wrap{
    position:relative;
    height:240px;
}

@media (max-width: 1100px){
    .tax-charts-grid{
        grid-template-columns:1fr;
    }

    .tax-breakdown-wrap{
        grid-template-columns:1fr;
        height:auto;
    }

    .tax-breakdown-canvas-wrap{
        width:200px;
        height:200px;
    }
}

    

/* =========================================================
   FINAL TAXES / VAT POPUP BLACK + GOLD + WHITE THEME
   Add/Edit Tax Modal
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

/* Modal size */
#taxModal .modal-dialog{
    max-width:min(760px,94vw) !important;
}

/* Main popup container */
#taxModal .modal-content,
#taxModal .exp-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.62) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

/* Header */
#taxModal .modal-header,
#taxModal .exp-modal-header{
    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
    min-height:76px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#taxModal .modal-title,
#taxModal .exp-modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#taxModal .modal-title::before,
#taxModal .exp-modal-title::before{
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
#taxModal .exp-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.14) !important;
    color:transparent !important;
    opacity:1 !important;
    box-shadow:none !important;
    padding:0 !important;
    font-size:0 !important;
    line-height:1 !important;
}

#taxModal .exp-close::before,
#taxModal .exp-close::after{
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

#taxModal .exp-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#taxModal .exp-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#taxModal .exp-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px) !important;
}

/* Body */
#taxModal .modal-body,
#taxModal .exp-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#taxModal .exp-modal-body::-webkit-scrollbar{
    width:7px !important;
}

#taxModal .exp-modal-body::-webkit-scrollbar-track{
    background:#171c28 !important;
    border-radius:999px !important;
}

#taxModal .exp-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c !important;
    border-radius:999px !important;
}

/* Footer */
#taxModal .modal-footer,
#taxModal .exp-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.28) !important;
    padding:18px 24px 22px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:12px !important;
}

/* Labels */
#taxModal .dark-label,
#taxModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#taxModal .req{
    color:#f5c742 !important;
}

/* Inputs / selects / textarea */
#taxModal .dark-input,
#taxModal .dark-select,
#taxModal .dark-textarea,
#taxModal input[type="text"],
#taxModal input[type="number"],
#taxModal input[type="date"],
#taxModal select,
#taxModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#taxModal .dark-input,
#taxModal .dark-select,
#taxModal input[type="text"],
#taxModal input[type="number"],
#taxModal input[type="date"],
#taxModal select{
    min-height:50px !important;
    height:50px !important;
    padding:10px 16px !important;
}

#taxModal .dark-textarea,
#taxModal textarea{
    min-height:112px !important;
    height:auto !important;
    padding:14px 16px !important;
    resize:vertical !important;
}

#taxModal input::placeholder,
#taxModal textarea::placeholder{
    color:#8993a5 !important;
}

#taxModal .dark-input:focus,
#taxModal .dark-select:focus,
#taxModal .dark-textarea:focus,
#taxModal input:focus,
#taxModal select:focus,
#taxModal textarea:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Inclusive switch inside modal */
#taxModal .switch{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.28) !important;
    border-radius:999px !important;
    padding:0 !important;
}

#taxModal .switch .slider{
    background:#3a4156 !important;
}

#taxModal .switch input:checked + .slider{
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
}

#taxModal .switch .slider:before{
    background:#ffffff !important;
}

/* Footer buttons */
#taxModal .cancel-btn{
    height:44px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#taxModal .cancel-btn:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#taxModal .save-btn{
    height:44px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#taxModal .save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px) !important;
}

/* Mobile */
@media (max-width:768px){
    #taxModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #taxModal .modal-title,
    #taxModal .exp-modal-title{
        font-size:18px !important;
    }

    #taxModal .exp-close{
        width:42px !important;
        height:42px !important;
    }

    #taxModal .exp-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #taxModal .cancel-btn,
    #taxModal .save-btn{
        width:100% !important;
    }
}


/* =====================================================
   TAXES.PHP - DARK PURPLE GLASS THEME + COMPACT POPUP
   Backend / PHP / JS logic untouched
===================================================== */
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
}
html,body{min-height:100% !important;}
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        radial-gradient(circle at 84% 12%, rgba(59,130,246,.11), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
    overflow-x:hidden !important;
    --current-sidebar-width:var(--sidebar-open-width);
}
body.dashboard-sidebar-collapsed{--current-sidebar-width:var(--sidebar-close-width);}
.container-scroll{display:flex !important;min-height:100vh !important;align-items:stretch !important;width:100% !important;}
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
    left:0 !important;top:0 !important;height:100vh !important;z-index:1000 !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease !important;
}
.sidebar > div{width:100% !important;min-height:100vh !important;height:100vh !important;}
.sidebar .sidebar-wrap,#dashboardSidebar .sidebar-wrap,#sidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;min-height:100vh !important;
    position:fixed !important;left:0 !important;top:0 !important;z-index:1000 !important;
    overflow:hidden !important;border-radius:0 !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease !important;
}
.sidebar .sidebar-inner,#dashboardSidebar .sidebar-inner,#sidebar .sidebar-inner{width:100% !important;height:100vh !important;min-height:100vh !important;}
.sidebar .sidebar-menu,#dashboardSidebar .sidebar-menu,#sidebar .sidebar-menu{overflow-y:auto !important;overflow-x:hidden !important;}
.main-panel{
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;min-width:0 !important;min-height:100vh !important;
    display:flex !important;flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
    transition:margin-left .25s ease !important;
}
.topbar{
    min-height:70px !important;height:70px !important;background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;align-items:center !important;padding:0 !important;
    position:sticky !important;top:0 !important;z-index:1030 !important;
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
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.08), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
}
.content-wrapper::before{
    content:"";position:absolute;inset:0;pointer-events:none;z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}
.content-wrapper::after{
    content:"";position:absolute;width:520px;height:520px;right:-190px;top:-210px;pointer-events:none;z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);opacity:.85;
}
.content-wrapper > *{position:relative !important;z-index:1 !important;}
.page-header{display:flex !important;justify-content:space-between !important;align-items:center !important;gap:14px !important;flex-wrap:wrap !important;margin-bottom:18px !important;}
.page-title{
    font-size:30px !important;font-weight:900 !important;color:#f8fafc !important;margin:0 !important;
    letter-spacing:-.03em !important;text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}
.btn-yellow{
    min-height:50px !important;border:none !important;border-radius:14px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;font-weight:900 !important;padding:12px 18px !important;
    box-shadow:0 16px 34px rgba(124,58,237,.26),0 0 28px rgba(124,58,237,.14) !important;
    transition:.22s ease !important;
}
.btn-yellow:hover{transform:translateY(-2px) !important;box-shadow:0 22px 46px rgba(124,58,237,.34) !important;}
.stats-grid{display:grid !important;grid-template-columns:repeat(4,1fr) !important;gap:16px !important;margin-bottom:18px !important;}
.stat-card{
    min-height:112px !important;color:#fff !important;border-radius:18px !important;padding:20px 22px !important;
    position:relative !important;overflow:hidden !important;border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;isolation:isolate !important;
    transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease,filter .25s ease !important;
}
.stat-card::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.10),transparent 42%),radial-gradient(circle at 86% 10%,rgba(255,255,255,.16),transparent 28%);z-index:-1;}
.stat-card::after{content:"";position:absolute;right:14px;top:14px;width:76px;height:76px;border-radius:20px;background:rgba(255,255,255,.10);z-index:-1;}
.stat-card:hover{transform:translateY(-4px) !important;border-color:rgba(255,255,255,.22) !important;filter:saturate(1.08) !important;box-shadow:0 26px 65px rgba(0,0,0,.46),0 0 48px rgba(124,58,237,.18) !important;}
.stat-red{background:linear-gradient(135deg,#be123c,#7c3aed) !important;}
.stat-gold{background:linear-gradient(135deg,#6d4cff,#f59e0b) !important;}
.stat-orange{background:linear-gradient(135deg,#f97316,#7c3aed) !important;}
.stat-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-title{font-size:15px !important;font-weight:900 !important;color:rgba(255,255,255,.94) !important;}
.stat-value{font-size:28px !important;font-weight:900 !important;color:#fff !important;}
.stat-sub{color:rgba(255,255,255,.86) !important;font-weight:800 !important;}
.filter-card,.table-card,.approval-card,.tax-chart-card{
    background:radial-gradient(circle at 20% 0%,rgba(124,58,237,.14),transparent 34%),linear-gradient(135deg,rgba(15,23,42,.72),rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;border-radius:18px !important;
    box-shadow:0 18px 48px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;-webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}
.filter-card{padding:14px 16px !important;margin-bottom:18px !important;}
.filter-row{display:grid !important;grid-template-columns:minmax(150px,1fr) minmax(150px,1fr) minmax(150px,1fr) minmax(150px,1fr) 100px 100px !important;gap:10px !important;align-items:center !important;margin-bottom:10px !important;}
.search-row{display:grid !important;grid-template-columns:1fr !important;gap:10px !important;}
.erp-select,.erp-input{
    height:46px !important;width:100% !important;border:1px solid rgba(148,163,184,.20) !important;border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;color:#e5e7eb !important;padding:0 14px !important;outline:none !important;
    font-weight:800 !important;box-shadow:0 8px 22px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.04) !important;
}
.erp-select option{background:#0f172a !important;color:#e5e7eb !important;}
.erp-input::placeholder{color:#94a3b8 !important;}
.erp-select:focus,.erp-input:focus{background:rgba(17,24,39,.92) !important;color:#fff !important;border-color:rgba(124,58,237,.62) !important;box-shadow:0 0 0 3px rgba(124,58,237,.16),0 12px 26px rgba(0,0,0,.24) !important;}
.filter-btn,.reset-btn{
    height:46px !important;width:100% !important;border-radius:13px !important;font-weight:900 !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;gap:6px !important;transition:.22s ease !important;box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
}
.filter-btn{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;border:none !important;}
.reset-btn{background:rgba(15,23,42,.72) !important;color:#e5e7eb !important;border:1px solid rgba(148,163,184,.18) !important;text-decoration:none !important;}
.filter-btn:hover,.reset-btn:hover{transform:translateY(-2px) !important;color:#fff !important;border-color:rgba(139,92,246,.45) !important;box-shadow:0 18px 40px rgba(0,0,0,.36),0 0 34px rgba(124,58,237,.18) !important;}
.reset-btn:hover{background:rgba(124,58,237,.20) !important;}
.table-card{overflow:hidden !important;padding:0 !important;}
.erp-table{width:100% !important;border-collapse:collapse !important;background:transparent !important;}
.erp-table thead th{
    background:rgba(7,11,20,.76) !important;color:#cbd5e1 !important;font-weight:900 !important;font-size:13px !important;
    padding:15px 14px !important;border-right:1px solid rgba(148,163,184,.08) !important;border-bottom:1px solid rgba(148,163,184,.16) !important;
    white-space:nowrap !important;text-transform:uppercase !important;letter-spacing:.04em !important;
}
.erp-table tbody td{
    padding:15px 14px !important;border-top:1px solid rgba(148,163,184,.10) !important;vertical-align:middle !important;
    font-size:14px !important;color:#e5e7eb !important;font-weight:700 !important;background:rgba(15,23,42,.30) !important;
}
.erp-table tbody tr:hover td{background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.18),transparent 38%),linear-gradient(135deg,rgba(30,20,58,.42),rgba(15,23,42,.34)) !important;color:#fff !important;}
.erp-table strong,.expense-cell span{color:#f8fafc !important;}
.erp-table input[type="checkbox"]{width:16px !important;height:16px !important;accent-color:#7c3aed !important;}
.expense-icon{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;}
.status-pill{border-radius:999px !important;padding:7px 12px !important;font-weight:900 !important;}
.status-approval,.status-pending{background:rgba(245,158,11,.16) !important;color:#fbbf24 !important;border:1px solid rgba(245,158,11,.25) !important;}
.status-completed{background:rgba(34,197,94,.16) !important;color:#4ade80 !important;border:1px solid rgba(34,197,94,.25) !important;}
.tax-actions{display:flex !important;gap:10px !important;align-items:center !important;justify-content:center !important;}
.tax-btn{width:40px !important;height:40px !important;border:none !important;border-radius:14px !important;box-shadow:0 12px 24px rgba(0,0,0,.18) !important;transition:.20s ease !important;}
.tax-btn:hover{transform:translateY(-2px) !important;}
.tax-edit{background:rgba(245,158,11,.15) !important;color:#fbbf24 !important;border:1px solid rgba(245,158,11,.24) !important;}
.tax-delete{background:rgba(239,68,68,.14) !important;color:#f87171 !important;border:1px solid rgba(239,68,68,.24) !important;}
.tax-charts-grid{gap:16px !important;margin:18px 0 !important;}
.tax-chart-card{min-height:310px !important;color:#f5f7ff !important;}
.tax-chart-title{color:#f8fafc !important;font-weight:900 !important;}
.tax-chart-badge{background:rgba(124,58,237,.20) !important;color:#c4b5fd !important;border:1px solid rgba(124,58,237,.30) !important;}
.tax-legend-name{color:#e5e7eb !important;}.tax-legend-percent{color:#aeb8c7 !important;}.tax-legend-value{color:#c4b5fd !important;}
.switch .slider{background:#334155 !important;}
.switch input:checked + .slider{background:linear-gradient(135deg,#6d4cff,#22c55e) !important;}
.alert-wrap .alert{border-radius:14px !important;border:none !important;font-weight:800 !important;}

/* ============= COMPACT PURPLE GLASS TAX POPUP ============= */
body.modal-open .modal-backdrop.show,.modal-backdrop.show{opacity:.58 !important;background:#000 !important;}
#taxModal .modal-dialog,#taxModal .modal-dialog.modal-xl-custom{max-width:760px !important;width:760px !important;margin:18px auto !important;}
#taxModal .modal-content,#taxModal .exp-modal-content{
    max-height:82vh !important;
    background:radial-gradient(circle at 18% 0%,rgba(124,58,237,.24),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.90),rgba(17,24,39,.76)) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;-webkit-backdrop-filter:blur(22px) saturate(145%) !important;
    color:#f8fafc !important;border:1px solid rgba(139,92,246,.34) !important;border-radius:18px !important;overflow:hidden !important;
    box-shadow:0 24px 70px rgba(0,0,0,.58),0 0 45px rgba(124,58,237,.16),inset 0 1px 0 rgba(255,255,255,.06) !important;
}
#taxModal .modal-header,#taxModal .exp-modal-header{
    min-height:54px !important;padding:12px 18px !important;
    background:rgba(7,11,20,.34) !important;border-bottom:1px solid rgba(148,163,184,.14) !important;
    display:flex !important;align-items:center !important;justify-content:space-between !important;
}
#taxModal .modal-title,#taxModal .exp-modal-title{font-size:18px !important;color:#f8fafc !important;font-weight:900 !important;margin:0 !important;text-shadow:0 0 22px rgba(124,58,237,.26) !important;}
#taxModal .modal-title::before,#taxModal .exp-modal-title::before{background:#7c3aed !important;box-shadow:0 0 0 5px rgba(124,58,237,.16) !important;width:10px !important;height:10px !important;}
#taxModal .modal-body,#taxModal .exp-modal-body{padding:12px 18px !important;max-height:62vh !important;overflow-y:auto !important;overflow-x:hidden !important;background:rgba(15,23,42,.22) !important;color:#f8fafc !important;}
#taxModal .row{--bs-gutter-x:14px !important;--bs-gutter-y:8px !important;}
#taxModal .mb-3{margin-bottom:10px !important;}
#taxModal .dark-label,#taxModal label{font-size:12px !important;margin-bottom:5px !important;color:#e5e7eb !important;font-weight:900 !important;}
#taxModal .req{color:#c4b5fd !important;}
#taxModal .dark-input,#taxModal .dark-select,#taxModal input[type="text"],#taxModal input[type="number"],#taxModal input[type="date"],#taxModal select,#taxModal textarea{
    height:38px !important;min-height:38px !important;padding:6px 12px !important;font-size:13px !important;border-radius:9px !important;
    background:rgba(255,255,255,.92) !important;color:#111827 !important;border:1px solid rgba(203,213,225,.82) !important;
    font-weight:700 !important;box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}
#taxModal textarea,#taxModal .dark-textarea{min-height:58px !important;height:58px !important;padding:8px 12px !important;}
#taxModal .dark-input:focus,#taxModal .dark-select:focus,#taxModal input:focus,#taxModal select:focus,#taxModal textarea:focus{border-color:#7c3aed !important;box-shadow:0 0 0 3px rgba(124,58,237,.20),0 8px 18px rgba(0,0,0,.18) !important;outline:none !important;}
#taxModal .exp-close{
    position:relative !important;width:36px !important;height:36px !important;border-radius:10px !important;background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;color:transparent !important;font-size:0 !important;opacity:1 !important;padding:0 !important;
}
#taxModal .exp-close::before,#taxModal .exp-close::after{content:"" !important;position:absolute !important;left:50% !important;top:50% !important;width:16px !important;height:2px !important;background:#e5e7eb !important;border-radius:999px !important;}
#taxModal .exp-close::before{transform:translate(-50%,-50%) rotate(45deg) !important;}
#taxModal .exp-close::after{transform:translate(-50%,-50%) rotate(-45deg) !important;}
#taxModal .exp-close:hover{background:rgba(239,68,68,.24) !important;}
#taxModal .switch{width:46px !important;height:24px !important;background:transparent !important;border:none !important;}
#taxModal .switch .slider{background:#334155 !important;border-radius:999px !important;}
#taxModal .switch .slider:before{height:18px !important;width:18px !important;left:3px !important;bottom:3px !important;}
#taxModal .switch input:checked + .slider:before{transform:translateX(22px) !important;}
#taxModal .modal-footer,#taxModal .exp-modal-footer{padding:10px 18px !important;min-height:52px !important;margin-top:0 !important;background:rgba(7,11,20,.34) !important;border-top:1px solid rgba(148,163,184,.14) !important;display:flex !important;justify-content:flex-end !important;gap:10px !important;}
#taxModal .cancel-btn,#taxModal .save-btn{height:38px !important;min-width:105px !important;font-size:13px !important;border-radius:9px !important;font-weight:900 !important;}
#taxModal .cancel-btn{background:rgba(15,23,42,.72) !important;color:#e5e7eb !important;border:1px solid rgba(148,163,184,.18) !important;}
#taxModal .save-btn{background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;color:#fff !important;border:none !important;box-shadow:0 12px 26px rgba(124,58,237,.22) !important;}
#taxModal .save-btn:hover,#taxModal .cancel-btn:hover{transform:translateY(-1px) !important;}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr) !important}.filter-row{grid-template-columns:repeat(3,1fr) !important;}}
@media(max-width:991px){.container-scroll{flex-direction:column !important}.sidebar{position:relative !important;width:100% !important;min-width:100% !important;max-width:100% !important;height:auto !important}.sidebar .sidebar-wrap,#dashboardSidebar .sidebar-wrap,#sidebar .sidebar-wrap{position:relative !important;width:100% !important;min-width:100% !important;max-width:100% !important;height:auto !important;min-height:auto !important}.main-panel{margin-left:0 !important}.topbar{position:relative !important}.content-wrapper{padding:18px !important}#taxModal .modal-dialog,#taxModal .modal-dialog.modal-xl-custom{width:calc(100% - 24px) !important;max-width:calc(100% - 24px) !important;margin:12px auto !important}}
@media(max-width:768px){.stats-grid{grid-template-columns:1fr !important}.filter-row{grid-template-columns:1fr !important}.page-header{flex-direction:column !important;align-items:stretch !important}.filter-btn,.reset-btn,.erp-select,.erp-input,.btn-yellow{width:100% !important;min-width:100% !important}#taxModal .modal-footer,#taxModal .exp-modal-footer{flex-direction:column !important;align-items:stretch !important}#taxModal .cancel-btn,#taxModal .save-btn{width:100% !important}}




/* =========================================================
   TAXES.PHP - FINAL LIGHT MODE
   Is block ko hamesha LAST me rakhna hai
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;

    --tax-bg:#f4f7fb;
    --tax-white:#ffffff;
    --tax-soft:#f8fafc;
    --tax-border:#dce4ef;
    --tax-border-strong:#cbd5e1;
    --tax-title:#0f172a;
    --tax-text:#334155;
    --tax-muted:#64748b;
    --tax-purple:#7c3aed;
    --tax-purple-dark:#6d28d9;
}

/* Page base */
html[data-pos-theme="light"] body{
    background:var(--tax-bg) !important;
    color:var(--tax-title) !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:var(--tax-bg) !important;
}

html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(135deg,#f8fafc 0%,#f1f5f9 55%,#faf7ff 100%) !important;
}

html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    color:#0f172a !important;
    border-bottom:1px solid #dce4ef !important;
    box-shadow:0 5px 18px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.08), transparent 28%),
        radial-gradient(circle at 80% 4%, rgba(59,130,246,.05), transparent 30%),
        linear-gradient(135deg,#f8fafc 0%,#f1f5f9 50%,#faf7ff 78%,#f8fafc 100%) !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(90deg, rgba(15,23,42,.025) 1px, transparent 1px),
        linear-gradient(180deg, rgba(15,23,42,.022) 1px, transparent 1px) !important;
    opacity:.55 !important;
    mask-image:none !important;
}

html[data-pos-theme="light"] body .content-wrapper::after{
    opacity:.28 !important;
    filter:blur(18px) !important;
}

/* Header */
html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body .btn-yellow{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border:none !important;
    box-shadow:0 12px 26px rgba(124,58,237,.20) !important;
}

/* Stat cards */
html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border:1px solid rgba(255,255,255,.28) !important;
    box-shadow:0 14px 30px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-title,
html[data-pos-theme="light"] body .stat-value,
html[data-pos-theme="light"] body .stat-sub{
    color:#ffffff !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .stat-red{
    background:linear-gradient(135deg,#db2777,#8b5cf6) !important;
}

html[data-pos-theme="light"] body .stat-gold{
    background:linear-gradient(135deg,#8b5cf6,#f59e0b) !important;
}

html[data-pos-theme="light"] body .stat-orange{
    background:linear-gradient(135deg,#f97316,#a855f7) !important;
}

html[data-pos-theme="light"] body .stat-green{
    background:linear-gradient(135deg,#059669,#16a34a) !important;
}

html[data-pos-theme="light"] body .pending-badge{
    background:#fff7db !important;
    color:#9a6700 !important;
    border:1px solid #f7d774 !important;
}

/* Cards */
html[data-pos-theme="light"] body .filter-card,
html[data-pos-theme="light"] body .table-card,
html[data-pos-theme="light"] body .approval-card,
html[data-pos-theme="light"] body .tax-chart-card{
    background:#ffffff !important;
    background-image:none !important;
    color:#0f172a !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .filter-card:hover,
html[data-pos-theme="light"] body .table-card:hover,
html[data-pos-theme="light"] body .approval-card:hover,
html[data-pos-theme="light"] body .tax-chart-card:hover{
    border-color:#c4b5fd !important;
}

/* Inputs */
html[data-pos-theme="light"] body .erp-select,
html[data-pos-theme="light"] body .erp-input{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .erp-input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .erp-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .erp-select:hover,
html[data-pos-theme="light"] body .erp-input:hover{
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] body .erp-select:focus,
html[data-pos-theme="light"] body .erp-input:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.12) !important;
}

/* Buttons */
html[data-pos-theme="light"] body .filter-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border:none !important;
    box-shadow:0 10px 22px rgba(124,58,237,.20) !important;
}

html[data-pos-theme="light"] body .reset-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
    text-decoration:none !important;
}

html[data-pos-theme="light"] body .reset-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Table */
html[data-pos-theme="light"] body .erp-table{
    background:transparent !important;
}

html[data-pos-theme="light"] body .erp-table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-right:1px solid #e2e8f0 !important;
    border-bottom:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body .erp-table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-top:1px solid #e8edf4 !important;
}

html[data-pos-theme="light"] body .erp-table tbody tr:hover td{
    background:#f6f2ff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .erp-table strong,
html[data-pos-theme="light"] body .expense-cell span{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .erp-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Status pills */
html[data-pos-theme="light"] body .status-pill{
    border-radius:999px !important;
}

html[data-pos-theme="light"] body .status-approval,
html[data-pos-theme="light"] body .status-pending{
    background:#fffbeb !important;
    color:#b45309 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .status-completed{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

/* Action buttons */
html[data-pos-theme="light"] body .tax-edit{
    background:#fff4d6 !important;
    color:#d89b00 !important;
    border:1px solid #f4d37a !important;
}

html[data-pos-theme="light"] body .tax-delete{
    background:#ffe3e3 !important;
    color:#ef4444 !important;
    border:1px solid #ffc4c4 !important;
}

/* Charts section */
html[data-pos-theme="light"] body .tax-chart-card{
    min-height:310px !important;
}

html[data-pos-theme="light"] body .tax-chart-title{
    color:#0f172a !important;
    font-weight:900 !important;
}

html[data-pos-theme="light"] body .tax-chart-badge{
    background:#f3efff !important;
    color:#6d28d9 !important;
    border:1px solid #ddd6fe !important;
}

html[data-pos-theme="light"] body .tax-center-label strong{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .tax-center-label span{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .tax-legend-item{
    color:#475569 !important;
}

html[data-pos-theme="light"] body .tax-legend-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .tax-legend-percent{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .tax-legend-value{
    color:#6d28d9 !important;
}

/* Approval / alert */
html[data-pos-theme="light"] body .approval-head{
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .approval-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .approval-body,
html[data-pos-theme="light"] body .approval-legend,
html[data-pos-theme="light"] body .empty-state{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .alert-wrap .alert{
    border-radius:14px !important;
}

/* Switch */
html[data-pos-theme="light"] body .switch .slider{
    background:#cbd5e1 !important;
}

html[data-pos-theme="light"] body .switch input:checked + .slider{
    background:linear-gradient(135deg,#8b5cf6,#16a34a) !important;
}

/* ================= MODAL LIGHT MODE ================= */

html[data-pos-theme="light"] body.modal-open .modal-backdrop.show,
html[data-pos-theme="light"] .modal-backdrop.show{
    background:#0f172a !important;
    opacity:.42 !important;
}

html[data-pos-theme="light"] body #taxModal .modal-content,
html[data-pos-theme="light"] body #taxModal .exp-modal-content{
    background:#ffffff !important;
    background-image:none !important;
    color:#0f172a !important;
    border:1px solid #d8e0eb !important;
    box-shadow:0 28px 80px rgba(15,23,42,.20) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body #taxModal .modal-header,
html[data-pos-theme="light"] body #taxModal .exp-modal-header{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body #taxModal .modal-title,
html[data-pos-theme="light"] body #taxModal .exp-modal-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body #taxModal .modal-title::before,
html[data-pos-theme="light"] body #taxModal .exp-modal-title::before{
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.14) !important;
}

html[data-pos-theme="light"] body #taxModal .modal-body,
html[data-pos-theme="light"] body #taxModal .exp-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body #taxModal .dark-label,
html[data-pos-theme="light"] body #taxModal label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body #taxModal .req{
    color:#e11d48 !important;
}

html[data-pos-theme="light"] body #taxModal .dark-input,
html[data-pos-theme="light"] body #taxModal .dark-select,
html[data-pos-theme="light"] body #taxModal .dark-textarea,
html[data-pos-theme="light"] body #taxModal input[type="text"],
html[data-pos-theme="light"] body #taxModal input[type="number"],
html[data-pos-theme="light"] body #taxModal input[type="date"],
html[data-pos-theme="light"] body #taxModal select,
html[data-pos-theme="light"] body #taxModal textarea{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body #taxModal input::placeholder,
html[data-pos-theme="light"] body #taxModal textarea::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body #taxModal input:focus,
html[data-pos-theme="light"] body #taxModal select:focus,
html[data-pos-theme="light"] body #taxModal textarea:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body #taxModal .exp-close{
    background:#ffffff !important;
    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body #taxModal .exp-close::before,
html[data-pos-theme="light"] body #taxModal .exp-close::after{
    background:#334155 !important;
}

html[data-pos-theme="light"] body #taxModal .switch .slider{
    background:#cbd5e1 !important;
}

html[data-pos-theme="light"] body #taxModal .modal-footer,
html[data-pos-theme="light"] body #taxModal .exp-modal-footer{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body #taxModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body #taxModal .save-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border:none !important;
    box-shadow:0 10px 22px rgba(124,58,237,.20) !important;
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
        <div class="topbar"><?php include('partials/topbar.php'); ?></div>

        <div class="content-wrapper">
            <div class="page-header">
                <h1 class="page-title">Taxes / VAT</h1>
                <button type="button" class="btn-yellow" onclick="openTaxModal()">
                    <i class="bi bi-plus-lg me-1"></i> Add Tax
                </button>
            </div>

            <div class="alert-wrap">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success mb-0"><?= e($_SESSION['success']); unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger mb-0"><?= e($_SESSION['error']); unset($_SESSION['error']); ?></div>
                <?php endif; ?>
            </div>

            <div class="stats-grid">
                <div class="stat-card stat-red">
                    <div class="stat-title">Total Tax Collected</div>
                    <div class="stat-sub">PKR <?= number_format($totalTaxCollected, 2) ?></div>
                    <div class="stat-value"><?= number_format($totalTaxCollected, 2) ?></div>
                </div>
                <div class="stat-card stat-gold">
                    <div class="stat-title">Pending Tax Liability</div>
                    <div class="stat-sub">PKR <?= number_format($pendingTax, 2) ?></div>
                    <div class="stat-value"><?= number_format($pendingTax, 2) ?></div>
                </div>
                <div class="stat-card stat-gold">
                    <div class="stat-title">Last Month vs Current Month</div>
                    <div class="stat-sub">PKR <?= number_format($currentMonthTax, 2) ?> vs <?= number_format($lastMonthTax, 2) ?></div>
                </div>
                <div class="stat-card stat-green">
                    <div class="stat-title">Total Tax Rules</div>
                    <div class="stat-sub"><?= $totalTaxRules ?></div>
                    <div class="stat-value" style="font-size:22px;"><?= $totalTaxRules ?></div>
                </div>
            </div>

            <div class="filter-card">
                <form method="GET">
                    <div class="filter-row">
                        <select class="erp-select" name="region">
                            <option value="">All Regions</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= ($region == $b['id']) ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select class="erp-select" name="status">
                            <option value="">All Status</option>
                            <option value="paid" <?= $status=='paid'?'selected':'' ?>>Paid</option>
                            <option value="unpaid" <?= $status=='unpaid'?'selected':'' ?>>Unpaid</option>
                        </select>

                        <select class="erp-select" name="type">
                            <option value="">All Types</option>
                            <option value="walkin" <?= $type=='walkin'?'selected':'' ?>>Walk-in</option>
                            <option value="online" <?= $type=='online'?'selected':'' ?>>Online</option>
                        </select>

                        <input type="date" name="from_date" class="erp-input" value="<?= e($from_date) ?>">
                        <button type="submit" class="filter-btn"><i class="bi bi-search me-1"></i> Filter</button>
                        <a href="taxes.php" class="reset-btn d-inline-flex align-items-center justify-content-center text-decoration-none">
                            <i class="bi bi-arrow-clockwise me-1"></i> Reset
                        </a>
                    </div>
                    <div class="search-row">
                        <input type="text" class="erp-input" name="search" placeholder="Search taxes..." value="<?= e($search) ?>">
                    </div>
                </form>
            </div>

            <div class="table-card">
                <div class="table-wrap">
                    <table class="erp-table">
                        <thead>
    <tr>
        <th><input type="checkbox" id="checkAll"></th>
        <th>ID</th>
        <th>Tax Name</th>
        <th>Rate (%)</th>
        <th>Type</th>
        <th>Application On</th>
        <th>Region / Cities</th>
        <th>Inclusive</th>
        <th>FBR Date</th>
        <th>Effective Date</th>
        <th>Actions</th>
    </tr>
</thead>
                        <tbody>
                            <?php if(empty($taxes)): ?>
                                <tr><td colspan="11" class="text-center py-4">No tax rules found</td></tr>
                            <?php else: ?>
                                <?php foreach($taxes as $t): ?>
                               <tr>
    <td>
        <input 
            type="checkbox" 
            class="row-check" 
            value="<?= (int)$t['id'] ?>"
        >
    </td>
    <td><?= (int)$t['id'] ?></td>
    <td><strong><?= e($t['name']) ?></strong></td>
    <td><?= number_format($t['rate'],2) ?>%</td>
    <td><?= e($t['type'] ?? 'General') ?></td>
    <td><?= e($t['application_on'] ?? 'All Sales') ?></td>
    <td><?= e($t['region'] ?? 'All') ?></td>
    <td>
        <label class="switch">
            <input type="checkbox" class="toggleInclusive" data-id="<?= $t['id'] ?>" <?= ($t['inclusive']??0)?'checked':'' ?>>
            <span class="slider round"></span>
        </label>
    </td>
    <td><?= !empty($t['fbr_date']) ? 'Yes':'No' ?></td>
    <td><?= e($t['effective_date'] ?? $t['created_at']) ?></td>
<td>
    <div class="tax-actions">

        <!-- EDIT -->
        <button
            class="tax-btn tax-edit editTax"
            data-id="<?= $t['id'] ?>"
            title="Edit Tax">
            <i class="bi bi-pencil-fill"></i>
        </button>

        <!-- DELETE -->
        <button
            class="tax-btn tax-delete deleteTax"
            data-id="<?= $t['id'] ?>"
            title="Delete Tax">
            <i class="bi bi-trash-fill"></i>
        </button>

    </div>
</td>
</tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            </div>
<div class="tax-charts-grid">
    <!-- Tax Breakdown -->
    <div class="tax-chart-card">
        <div class="tax-chart-header">
            <h3 class="tax-chart-title">Tax Breakdown</h3>
            <div class="tax-chart-badge">Active</div>
        </div>

        <div class="tax-breakdown-wrap">
            <div class="tax-breakdown-canvas-wrap">
                <canvas id="taxBreakdownChart"></canvas>
                <div class="tax-center-label">
                    <strong>VAT</strong>
                    <span>Tax Mix</span>
                </div>
            </div>

            <div class="tax-legend" id="taxLegendCustom"></div>
        </div>
    </div>

    <!-- Monthly Tax Trend -->
    <div class="tax-chart-card">
        <div class="tax-chart-header">
            <h3 class="tax-chart-title">Monthly Tax Trend</h3>
            <div class="tax-chart-badge">Last 6 Months</div>
        </div>

        <div class="tax-trend-canvas-wrap">
            <canvas id="monthlyTaxTrendChart"></canvas>
        </div>
    </div>
</div>
        </div>
        
    </div>
</div>


<!-- TAX MODAL -->
<div class="modal fade" id="taxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl-custom">
        <div class="modal-content exp-modal-content">
            <form method="POST" id="taxForm">
                <input type="hidden" name="save_tax" value="1">
                <input type="hidden" name="tax_id" id="tax_id">

                <div class="exp-modal-header">
                    <h5 class="exp-modal-title" id="taxModalTitle">Add Tax</h5>
                    <button type="button" class="exp-close" data-bs-dismiss="modal">&times;</button>
                </div>

                <div class="exp-modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Tax Name <span class="req">*</span></label>
                            <input type="text" name="name" id="name" class="dark-input" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Rate (%) <span class="req">*</span></label>
                            <input type="number" name="rate" id="rate" class="dark-input" step="1" min="1" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Type</label>
                            <select name="type" id="type" class="dark-select">
                                <option value="">Select Type</option>
                                <option value="walkin">Walk-in</option>
                                <option value="online">Online</option>
                                <option value="general">General</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Application On</label>
                            <select name="application_on" id="application_on" class="dark-select">
                                <option value="">All Sales</option>
                                <option value="product">Product</option>
                                <option value="service">Service</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Region / Cities</label>
                            <select name="region" id="region" class="dark-select">
                                <option value="">All Regions</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Inclusive</label>
                            <label class="switch">
                                <input type="checkbox" name="inclusive" id="inclusive">
                                <span class="slider round"></span>
                            </label>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Status <span class="req">*</span></label>
                            <select name="status" id="status" class="dark-select" required>
                                <?php foreach($statusOptions as $statusOpt): ?>
                                    <option value="<?= e($statusOpt) ?>" <?= $statusOpt==='Active'?'selected':'' ?>><?= e($statusOpt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">FBR Date</label>
                            <input type="date" name="fbr_date" id="fbr_date" class="dark-input">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Effective Date</label>
                            <input type="date" name="effective_date" id="effective_date" class="dark-input">
                        </div>
                    </div>
                </div>

                <div class="exp-modal-footer">
                    <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="save-btn" id="saveTaxBtn">Save Tax</button>
                </div>
                
            </form>
            
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const taxModal = new bootstrap.Modal(document.getElementById('taxModal'));

function openTaxModal(){
    document.getElementById('taxForm').reset();
    document.getElementById('tax_id').value = '';
    document.getElementById('taxModalTitle').innerText = 'Add Tax';
    document.getElementById('saveTaxBtn').innerText = 'Save Tax';
    document.getElementById('inclusive').checked = false;
    taxModal.show();
}

// Edit tax
document.querySelectorAll('.editTax').forEach(btn=>{
    btn.addEventListener('click', function(){
        const tr = this.closest('tr');
        document.getElementById('tax_id').value = this.dataset.id;
        document.getElementById('name').value = tr.children[1].innerText;
        document.getElementById('rate').value = parseFloat(tr.children[2].innerText);
        document.getElementById('type').value = tr.children[3].innerText;
        document.getElementById('application_on').value = tr.children[4].innerText;
        document.getElementById('region').value = tr.children[5].innerText;
        document.getElementById('inclusive').checked = tr.children[6].querySelector('input').checked;
        const statusSelect = document.getElementById('status');
        statusSelect.value = tr.children[9].querySelector('.editTax') ? 'Active':'Inactive';

        document.getElementById('taxModalTitle').innerText = 'Edit Tax';
        document.getElementById('saveTaxBtn').innerText = 'Update Tax';
        taxModal.show();
    });
});
document.addEventListener('DOMContentLoaded', function () {
    const breakdownLabels = <?= json_encode($taxBreakdownLabels) ?>;
    const breakdownValues = <?= json_encode($taxBreakdownValues) ?>;
    const breakdownRates  = <?= json_encode($taxBreakdownRates) ?>;

    const monthLabels = <?= json_encode($monthLabels) ?>;
    const vatSeries   = <?= json_encode($vatSeries) ?>;
    const gstSeries   = <?= json_encode($gstSeries) ?>;
    const salesSeries = <?= json_encode($salesSeries) ?>;

    const chartColors = [
        '#3E8C64',
        '#C89B3C',
        '#9E6C28',
        '#7F8B97',
        '#5A4F56'
    ];

    // Custom legend
    const legendContainer = document.getElementById('taxLegendCustom');
    const totalBreakdown = breakdownValues.reduce((a, b) => a + Number(b), 0);

    legendContainer.innerHTML = breakdownLabels.map((label, index) => {
        const value = Number(breakdownValues[index] || 0);
        const percent = totalBreakdown > 0 ? ((value / totalBreakdown) * 100).toFixed(1) : '0.0';

        return `
            <div class="tax-legend-item">
                <span class="tax-legend-color" style="background:${chartColors[index % chartColors.length]}"></span>
                <span class="tax-legend-name">${label}</span>
                <span class="tax-legend-percent">${percent}%</span>
                <span class="tax-legend-value">${value.toFixed(1)}%</span>
            </div>
        `;
    }).join('');

    // Doughnut Chart
    const breakdownCtx = document.getElementById('taxBreakdownChart');
    if (breakdownCtx) {
        new Chart(breakdownCtx, {
            type: 'doughnut',
            data: {
                labels: breakdownLabels,
                datasets: [{
                    data: breakdownValues,
                    backgroundColor: chartColors,
                    borderWidth: 2,
                    borderColor: '#171118',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#171118',
                        titleColor: '#fff',
                        bodyColor: '#ddd',
                        borderColor: 'rgba(255,255,255,.08)',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                return `${context.label}: ${Number(context.raw).toFixed(1)}%`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Currency formatter
    function formatPKR(value) {
        value = Number(value || 0);

        if (value >= 10000000) return 'PKR ' + (value / 10000000).toFixed(2) + 'Cr';
        if (value >= 100000) return 'PKR ' + (value / 100000).toFixed(2) + 'L';
        if (value >= 1000) return 'PKR ' + (value / 1000).toFixed(0) + 'K';
        return 'PKR ' + value.toFixed(0);
    }

    // Mixed bar + line chart
    const trendCtx = document.getElementById('monthlyTaxTrendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            data: {
                labels: monthLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'VAT',
                        data: vatSeries,
                        backgroundColor: 'rgba(74, 148, 97, 0.78)',
                        borderRadius: 6,
                        barThickness: 22
                    },
                    {
                        type: 'bar',
                        label: 'GST',
                        data: gstSeries,
                        backgroundColor: 'rgba(196, 154, 64, 0.78)',
                        borderRadius: 6,
                        barThickness: 22
                    },
                    {
                        type: 'line',
                        label: 'Sales Tax',
                        data: salesSeries,
                        borderColor: '#D7A64A',
                        backgroundColor: 'rgba(215,166,74,.18)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: false,
                        pointRadius: 4,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#D7A64A',
                        pointBorderColor: '#D7A64A'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            color: '#d9d0d6',
                            usePointStyle: true,
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 16
                        }
                    },
                    tooltip: {
                        backgroundColor: '#171118',
                        titleColor: '#fff',
                        bodyColor: '#ddd',
                        borderColor: 'rgba(255,255,255,.08)',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${formatPKR(context.raw)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            color: '#bfb6bd'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255,255,255,.08)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#bfb6bd',
                            callback: function(value) {
                                if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                                if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                                return value;
                            }
                        }
                    }
                }
            }
        });
    }
});

const checkAll = document.getElementById('checkAll');

checkAll?.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.checked = this.checked;
    });
});
</script>
</body>
</html>