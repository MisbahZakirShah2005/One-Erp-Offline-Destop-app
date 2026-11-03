<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
if ($client_id <= 0) {
    die("Invalid client session");
}

function e($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($amount) {
    return 'PKR ' . number_format((float)$amount, 2);
}

/* ================= FILTERS ================= */
$search    = trim($_GET['search'] ?? '');
$region    = trim($_GET['region'] ?? '');
$status    = trim($_GET['status'] ?? '');
$type      = trim($_GET['type'] ?? '');
$from_date = trim($_GET['from_date'] ?? '');
$to_date   = trim($_GET['to_date'] ?? '');

/* ================= FETCH BRANCHES ================= */
$branches = [];
$stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $branches[] = $row;
}
$stmt->close();

$branchMap = [];
foreach ($branches as $b) {
    $branchMap[(int)$b['id']] = $b['name'];
}

/* ================= BASE SALES FILTER SQL ================= */
$where = ["s.client_id = ?"];
$params = [$client_id];
$types = "i";

if ($search !== '') {
    $where[] = "(s.invoice_no LIKE ? OR s.customer_name LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

if ($region !== '') {
    $where[] = "s.branch_id = ?";
    $params[] = (int)$region;
    $types .= "i";
}

if ($status !== '') {
    $where[] = "LOWER(s.payment_method) = ?";
    $params[] = strtolower($status);
    $types .= "s";
}

if ($type !== '') {
    $where[] = "LOWER(s.source) = ?";
    $params[] = strtolower($type);
    $types .= "s";
}

if ($from_date !== '') {
    $where[] = "DATE(s.created_at) >= ?";
    $params[] = $from_date;
    $types .= "s";
}

if ($to_date !== '') {
    $where[] = "DATE(s.created_at) <= ?";
    $params[] = $to_date;
    $types .= "s";
}

$whereSql = implode(' AND ', $where);

/* ================= SALES SUMMARY STATS ================= */
$total_sales = 0;
$total_tax = 0;
$total_discount = 0;
$net_sales = 0;
$total_invoices = 0;
$pending_tax = 0;
$current_month_tax = 0;
$last_month_tax = 0;

$sql = "
    SELECT 
        COUNT(*) AS total_invoices,
        COALESCE(SUM(s.total), 0) AS total_sales,
        COALESCE(SUM(s.tax), 0) AS total_tax,
        COALESCE(SUM(s.discount), 0) AS total_discount,
        COALESCE(SUM(s.total - COALESCE(s.discount,0)), 0) AS net_sales
    FROM sales s
    WHERE {$whereSql}
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_invoices = (int)($summary['total_invoices'] ?? 0);
$total_sales = (float)($summary['total_sales'] ?? 0);
$total_tax = (float)($summary['total_tax'] ?? 0);
$total_discount = (float)($summary['total_discount'] ?? 0);
$net_sales = (float)($summary['net_sales'] ?? 0);

/* pending tax */
$sql = "
    SELECT COALESCE(SUM(s.tax), 0) AS pending_tax
    FROM sales s
    WHERE {$whereSql} AND s.due_date >= CURDATE()
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$pending_tax = (float)($stmt->get_result()->fetch_assoc()['pending_tax'] ?? 0);
$stmt->close();

/* current month tax */
$sql = "
    SELECT COALESCE(SUM(s.tax), 0) AS current_month_tax
    FROM sales s
    WHERE {$whereSql}
      AND MONTH(s.created_at) = MONTH(CURDATE())
      AND YEAR(s.created_at) = YEAR(CURDATE())
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$current_month_tax = (float)($stmt->get_result()->fetch_assoc()['current_month_tax'] ?? 0);
$stmt->close();

/* last month tax */
$sql = "
    SELECT COALESCE(SUM(s.tax), 0) AS last_month_tax
    FROM sales s
    WHERE {$whereSql}
      AND MONTH(s.created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
      AND YEAR(s.created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$last_month_tax = (float)($stmt->get_result()->fetch_assoc()['last_month_tax'] ?? 0);
$stmt->close();

$tax_percent_of_revenue = $total_sales > 0 ? (($total_tax / $total_sales) * 100) : 0;

/* ================= TAX SUMMARY TABLE DATA ================= */
$tax_summary_rows = [];

$sql = "
    SELECT
        DATE(s.created_at) AS sale_date,
        COUNT(*) AS invoice_count,
        COALESCE(SUM(s.total), 0) AS total_sales,
        COALESCE(SUM(s.tax), 0) AS total_tax,
        COALESCE(SUM(s.discount), 0) AS total_discount,
        COALESCE(SUM(s.total - COALESCE(s.discount,0)), 0) AS net_sales,
        COALESCE(MIN(s.payment_method), 'cash') AS payment_method,
        s.branch_id,
        COALESCE(b.name, 'N/A') AS branch_name
    FROM sales s
    LEFT JOIN branches b ON s.branch_id = b.id
    WHERE {$whereSql}
    GROUP BY DATE(s.created_at), s.branch_id
    ORDER BY DATE(s.created_at) DESC, s.branch_id ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $tax_summary_rows[] = $row;
}
$stmt->close();

/* ================= VAT/GST/OTHER SPLIT =================
   Note: since real tax-type data is not stored per-sale in this file,
   we estimate split from active tax rules for display consistency.
*/
$activeTaxes = [];
$stmt = $conn->prepare("
    SELECT name, rate
    FROM taxes
    WHERE client_id = ? AND status = 'Active'
    ORDER BY rate DESC, name ASC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $activeTaxes[] = $row;
}
$stmt->close();

$vat_rate = 0;
$gst_rate = 0;
$other_rate_total = 0;

foreach ($activeTaxes as $tax) {
    $name = strtolower(trim($tax['name'] ?? ''));
    $rate = (float)($tax['rate'] ?? 0);

    if (strpos($name, 'vat') !== false && $vat_rate == 0) {
        $vat_rate = $rate;
    } elseif (strpos($name, 'gst') !== false && $gst_rate == 0) {
        $gst_rate = $rate;
    } else {
        $other_rate_total += $rate;
    }
}

$total_known_rate = $vat_rate + $gst_rate + $other_rate_total;
$vat_ratio = $total_known_rate > 0 ? ($vat_rate / $total_known_rate) : 0;
$gst_ratio = $total_known_rate > 0 ? ($gst_rate / $total_known_rate) : 0;
$other_ratio = $total_known_rate > 0 ? ($other_rate_total / $total_known_rate) : 0;

$vat_amount = $total_tax * $vat_ratio;
$gst_amount = $total_tax * $gst_ratio;
$other_tax_amount = $total_tax * $other_ratio;

/* ================= CHART 1: TAX RULE BREAKDOWN ================= */
$taxBreakdownLabels = [];
$taxBreakdownValues = [];

foreach ($activeTaxes as $tax) {
    $taxBreakdownLabels[] = $tax['name'];
    $taxBreakdownValues[] = (float)$tax['rate'];
}

if (empty($taxBreakdownLabels)) {
    $taxBreakdownLabels = ['No Active Taxes'];
    $taxBreakdownValues = [1];
}

/* ================= CHART 2: MONTHLY TAX TREND ================= */
$monthLabels = [];
$walkinTaxSeries = [];
$onlineTaxSeries = [];
$totalTaxSeries = [];

$sql = "
    SELECT
        DATE_FORMAT(s.created_at, '%b %Y') AS month_label,
        YEAR(s.created_at) AS year_num,
        MONTH(s.created_at) AS month_num,
        COALESCE(SUM(CASE WHEN LOWER(s.source) = 'walkin' THEN s.tax ELSE 0 END), 0) AS walkin_tax,
        COALESCE(SUM(CASE WHEN LOWER(s.source) = 'online' THEN s.tax ELSE 0 END), 0) AS online_tax,
        COALESCE(SUM(s.tax), 0) AS total_tax
    FROM sales s
    WHERE s.client_id = ?
      AND s.created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY YEAR(s.created_at), MONTH(s.created_at), DATE_FORMAT(s.created_at, '%b %Y')
    ORDER BY YEAR(s.created_at), MONTH(s.created_at)
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $monthLabels[] = $row['month_label'];
    $walkinTaxSeries[] = (float)$row['walkin_tax'];
    $onlineTaxSeries[] = (float)$row['online_tax'];
    $totalTaxSeries[] = (float)$row['total_tax'];
}
$stmt->close();

if (empty($monthLabels)) {
    $monthLabels = ['No Data'];
    $walkinTaxSeries = [0];
    $onlineTaxSeries = [0];
    $totalTaxSeries = [0];
}

/* ================= CSV EXPORT ================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tax_summary_report.csv"');

    $output = fopen("php://output", "w");
    fputcsv($output, [
        'Date',
        'Invoice Count',
        'Total Sales',
        'Total Tax',
        'VAT',
        'GST',
        'Other Tax',
        'Discount',
        'Net Sales',
        'Payment Mode',
        'Branch'
    ]);

    foreach ($tax_summary_rows as $row) {
        $rowTax = (float)$row['total_tax'];
        $rowVat = $rowTax * $vat_ratio;
        $rowGst = $rowTax * $gst_ratio;
        $rowOther = $rowTax * $other_ratio;

        fputcsv($output, [
            $row['sale_date'],
            $row['invoice_count'],
            $row['total_sales'],
            $rowTax,
            $rowVat,
            $rowGst,
            $rowOther,
            $row['total_discount'],
            $row['net_sales'],
            $row['payment_method'],
            $row['branch_name']
        ]);
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tax Summary Report</title>
    <script>
(function () {
    let selectedTheme = 'light';

    try {
        const savedTheme =
            localStorage.getItem('oneerp_pos_theme') ||
            localStorage.getItem('oneerp_theme') ||
            localStorage.getItem('theme');

        if (savedTheme === 'light' || savedTheme === 'dark') {
            selectedTheme = savedTheme;
        }
    } catch (error) {
        selectedTheme = 'light';
    }

    document.documentElement.setAttribute(
        'data-pos-theme',
        selectedTheme
    );
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

        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
        .stat-card{border-radius:14px;padding:18px;min-height:92px;color:#fff;position:relative;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12)}
        .stat-card::after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12)}
        .stat-red{background:linear-gradient(135deg,#9f2430,#c94b57)}
        .stat-gold{background:linear-gradient(135deg,#b27b16,#d8a33a)}
        .stat-orange{background:linear-gradient(135deg,#b87a16,#dc9b2d)}
        .stat-green{background:linear-gradient(135deg,#386c58,#4c9075)}
        .stat-title{font-size:14px;font-weight:600;line-height:1.3}
        .stat-value{position:absolute;right:18px;bottom:14px;font-size:28px;font-weight:800;line-height:1}
        .stat-sub{margin-top:6px;font-size:13px;opacity:.95}

        .filter-card{
            background:#fff;
            padding:12px;
            border-radius:12px;
            border:1px solid #e0e5f2;
            margin-bottom:20px;
            box-shadow:0 4px 12px rgba(0,0,0,0.05);
        }
        .filter-toolbar-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .filter-item{
            background:#f4f7fe;
            border:1px solid #e0e5f2;
            border-radius:10px;
            display:flex;
            align-items:center;
            padding:0 12px;
            height:42px;
            min-width:180px;
        }
        .filter-item i{color:#a3afcf;margin-right:10px;font-size:14px}
        .filter-item select,.filter-item input{
            background:transparent;border:none;color:#2b3674;width:100%;
            outline:none;font-size:13px;font-weight:600
        }
        .filter-item select option{background:#fff;color:#2b3674}
        .filter-actions{display:flex;gap:6px;flex-wrap:wrap}
        .btn-action-gold{
            background:linear-gradient(180deg,#f5c742,#d8a500);
            border:none;color:#fff;border-radius:10px;padding:0 18px;height:42px;font-weight:700
        }
        .btn-action-dark{
            background:#111c44;border:none;color:#fff;border-radius:10px;padding:0 18px;height:42px;font-weight:600
        }
        .btn-action-whatsapp{
            background:linear-gradient(180deg,#4f9e4a,#3e7a3a);
            border:none;color:#fff;border-radius:10px;padding:0 18px;height:42px;font-weight:600
        }
        .search-wrap{min-width:250px}

        .premium-table-card{
            background:#fff;border-radius:16px;padding:20px;box-shadow:0 4px 20px rgba(0,0,0,0.05);
            border:1px solid #eef2f7;margin-top:20px
        }
        .table-header-flex{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
        .table-title{font-size:18px;font-weight:700;color:#1a202c;margin:0}
        .table-responsive-custom{overflow-x:auto;border-radius:8px}
        .custom-erp-table{width:100%;border-collapse:collapse;min-width:1000px}
        .custom-erp-table thead th{
            background:#f8fafc;color:#64748b;font-size:13px;font-weight:600;
            padding:14px 12px;text-align:left;border-bottom:2px solid #edf2f7;white-space:nowrap
        }
        .custom-erp-table tbody td{
            padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#334155;font-size:14px;vertical-align:middle
        }
        .custom-erp-table tbody tr:hover{background-color:#fbfcfe}
        .row-total-footer{background-color:#f8fafc;font-weight:700}
        .row-total-footer td{color:#1e293b;border-top:2px solid #e2e8f0}

        .tax-bar-container{display:flex;align-items:center;gap:8px}
        .tax-mini-bar{width:40px;height:12px;background:#e2e8f0;border-radius:4px;overflow:hidden}
        .tax-mini-bar span{display:block;height:100%;background:linear-gradient(90deg,#48bb78,#38a169)}
        .branch-pill-box{display:flex;align-items:center;gap:8px;color:#64748b}

        .tax-charts-grid{
            display:grid;
            grid-template-columns:1fr 1.25fr;
            gap:16px;
            margin:16px 0;
        }
        .tax-chart-card{
            position:relative;overflow:hidden;border-radius:18px;padding:18px;min-height:310px;
            color:#f5f7ff;border:1px solid rgba(255,255,255,.06);
            background:
                radial-gradient(circle at top left, rgba(70,45,20,.35), transparent 30%),
                radial-gradient(circle at bottom right, rgba(186,133,54,.10), transparent 30%),
                linear-gradient(180deg,#161018 0%, #120d13 100%);
            box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 16px 40px rgba(0,0,0,.25);
        }
        .tax-chart-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:10px}
        .tax-chart-title{margin:0;font-size:20px;font-weight:700;color:#f7ead2;letter-spacing:.2px}
        .tax-chart-badge{
            background:linear-gradient(180deg,#5b4314,#3d2c0c);color:#f5cf7c;
            border:1px solid rgba(245,207,124,.18);font-size:12px;padding:6px 10px;border-radius:999px;line-height:1
        }
        .tax-breakdown-wrap{display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:center;height:240px}
        .tax-breakdown-canvas-wrap{position:relative;width:220px;height:220px;margin:auto}
        .tax-center-label{
            position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
            flex-direction:column;pointer-events:none
        }
        .tax-center-label strong{font-size:28px;line-height:1;color:#fff}
        .tax-center-label span{font-size:14px;color:#c9c1c7;margin-top:4px;letter-spacing:.4px}
        .tax-legend{display:flex;flex-direction:column;gap:12px}
        .tax-legend-item{
            display:grid;grid-template-columns:14px 1fr auto auto;
            align-items:center;gap:10px;color:#ddd5da;font-size:14px
        }
        .tax-legend-color{width:12px;height:12px;border-radius:3px}
        .tax-legend-name{color:#e8e0e5;font-weight:500}
        .tax-legend-percent{color:#bfb6bd}
        .tax-legend-value{color:#f2cb71;font-weight:700}
        .tax-trend-canvas-wrap{position:relative;height:240px}

        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:1100px){
            .tax-charts-grid{grid-template-columns:1fr}
            .tax-breakdown-wrap{grid-template-columns:1fr;height:auto}
            .tax-breakdown-canvas-wrap{width:200px;height:200px}
        }
        @media(max-width:768px){
            .page-header{flex-direction:column;align-items:stretch}
            .stats-grid{grid-template-columns:1fr}
            .filter-toolbar-row{flex-direction:column;align-items:stretch}
            .filter-item,.search-wrap{min-width:100%}
        }
    
/* =========================================================
   FINAL TAX SUMMARY - TAXES.PHP MATCHING DARK BLACK PURPLE
   Transparent Glass Theme Override
   Backend/PHP/JS same - only UI theme
========================================================= */
:root{
    --ts-bg-0:#070912;
    --ts-bg-1:#0b0f1f;
    --ts-bg-2:#11142a;
    --ts-glass:rgba(18,22,46,.72);
    --ts-glass-2:rgba(24,18,55,.68);
    --ts-border:rgba(160,126,255,.18);
    --ts-border-2:rgba(255,255,255,.08);
    --ts-text:#f4f5ff;
    --ts-muted:#aab0c7;
    --ts-purple:#7c3cff;
    --ts-purple-2:#a855f7;
    --ts-cyan:#12b8d8;
    --ts-green:#0ea65f;
    --ts-gold:#f0a21a;
}

html,body{
    min-height:100%;
    background:
        radial-gradient(circle at 18% 10%, rgba(124,60,255,.22), transparent 28%),
        radial-gradient(circle at 85% 14%, rgba(18,184,216,.12), transparent 26%),
        radial-gradient(circle at 50% 85%, rgba(168,85,247,.12), transparent 30%),
        linear-gradient(180deg,#070912 0%, #0b0f1f 46%, #080a14 100%) !important;
    color:var(--ts-text) !important;
}

.container-scroll{
    background:transparent !important;
}

.main-panel{
    background:
        linear-gradient(180deg, rgba(10,13,28,.96), rgba(9,11,23,.98)) !important;
    position:relative;
    overflow:hidden;
}

.main-panel::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(rgba(255,255,255,.018) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.018) 1px, transparent 1px);
    background-size:34px 34px;
    opacity:.45;
}

.topbar{
    position:relative;
    z-index:2;
    background:rgba(6,9,19,.86) !important;
    border-bottom:1px solid rgba(255,255,255,.07) !important;
    box-shadow:0 12px 30px rgba(0,0,0,.28) !important;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}

.content-wrapper{
    position:relative;
    z-index:1;
    padding:28px 26px !important;
    background:
        radial-gradient(circle at 0% 0%, rgba(124,60,255,.18), transparent 28%),
        radial-gradient(circle at 100% 0%, rgba(18,184,216,.10), transparent 22%),
        linear-gradient(180deg, rgba(16,16,41,.48), rgba(11,12,25,.2)) !important;
}

.page-header{
    margin-bottom:24px !important;
}

.page-title{
    color:#ffffff !important;
    font-size:30px !important;
    font-weight:900 !important;
    letter-spacing:-.8px !important;
    text-shadow:0 3px 16px rgba(124,60,255,.28);
}

/* Stats exactly close to taxes.php dark cards */
.stats-grid{
    gap:18px !important;
    margin-bottom:22px !important;
}

.stat-card{
    min-height:120px !important;
    border-radius:18px !important;
    padding:22px 24px !important;
    border:1px solid rgba(255,255,255,.08) !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.10),
        0 18px 42px rgba(0,0,0,.34) !important;
    transition:all .22s ease !important;
    isolation:isolate;
}

.stat-card:hover{
    transform:translateY(-4px);
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.14),
        0 24px 55px rgba(0,0,0,.42),
        0 0 0 1px rgba(124,60,255,.16) !important;
}

.stat-card::after{
    width:86px !important;
    height:86px !important;
    right:18px !important;
    top:18px !important;
    border-radius:22px !important;
    background:rgba(255,255,255,.10) !important;
    filter:blur(.1px);
}

.stat-red{
    background:linear-gradient(135deg,#9d174d 0%, #b11f92 48%, #7c3cff 100%) !important;
}
.stat-orange{
    background:linear-gradient(135deg,#7c3cff 0%, #9b6bd2 45%, #f0a21a 100%) !important;
}
.stat-gold{
    background:linear-gradient(135deg,#7c3cff 0%, #9a72c5 45%, #f0a21a 100%) !important;
}
.stat-green{
    background:linear-gradient(135deg,#0f766e 0%, #0ea65f 55%, #069447 100%) !important;
}

.stat-title{
    color:#fff !important;
    font-size:15px !important;
    font-weight:900 !important;
    letter-spacing:.2px !important;
    text-shadow:0 2px 10px rgba(0,0,0,.30);
}

.stat-sub{
    color:#f7f2ff !important;
    font-size:14px !important;
    font-weight:800 !important;
    opacity:.95 !important;
}

.stat-value{
    color:#fff !important;
    right:22px !important;
    bottom:18px !important;
    font-size:30px !important;
    font-weight:950 !important;
    text-shadow:0 4px 16px rgba(0,0,0,.34);
}

/* Filter Glass same style as taxes.php */
.filter-card{
    background:linear-gradient(180deg, rgba(22,25,55,.80), rgba(16,18,43,.72)) !important;
    border:1px solid var(--ts-border) !important;
    border-radius:18px !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.06),
        0 16px 42px rgba(0,0,0,.30) !important;
    padding:18px !important;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}

.filter-toolbar-row{
    gap:10px !important;
}

.filter-item{
    background:rgba(12,17,36,.82) !important;
    border:1px solid rgba(152,129,255,.18) !important;
    border-radius:13px !important;
    height:50px !important;
    color:#fff !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
    transition:all .18s ease;
}

.filter-item:hover,
.filter-item:focus-within{
    border-color:rgba(124,60,255,.55) !important;
    box-shadow:0 0 0 3px rgba(124,60,255,.12), inset 0 1px 0 rgba(255,255,255,.06);
    transform:translateY(-1px);
}

.filter-item i{
    color:#bca7ff !important;
}

.filter-item input,
.filter-item select{
    color:#f6f7ff !important;
    font-weight:800 !important;
}

.filter-item input::placeholder{
    color:#aeb4ca !important;
}

.filter-item select option{
    background:#0f1328 !important;
    color:#fff !important;
}

.btn-action-gold,
.btn-action-dark,
.btn-action-whatsapp{
    height:50px !important;
    border-radius:13px !important;
    padding:0 20px !important;
    font-weight:900 !important;
    transition:all .2s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.22) !important;
}

.btn-action-gold{
    background:linear-gradient(180deg,#8b5cf6,#6d35e6) !important;
    color:#fff !important;
}

.btn-action-dark{
    background:rgba(12,17,36,.90) !important;
    border:1px solid rgba(152,129,255,.20) !important;
    color:#eef0ff !important;
}

.btn-action-whatsapp{
    background:rgba(12,17,36,.90) !important;
    border:1px solid rgba(152,129,255,.20) !important;
    color:#eef0ff !important;
}

.btn-action-gold:hover,
.btn-action-dark:hover,
.btn-action-whatsapp:hover{
    transform:translateY(-2px);
    filter:brightness(1.08);
}

/* Table glass */
.premium-table-card{
    background:linear-gradient(180deg, rgba(22,25,55,.82), rgba(14,17,35,.76)) !important;
    border:1px solid var(--ts-border) !important;
    border-radius:18px !important;
    padding:0 !important;
    overflow:hidden !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.06),
        0 18px 48px rgba(0,0,0,.34) !important;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}

.table-header-flex{
    padding:18px 22px 12px !important;
    margin-bottom:0 !important;
    border-bottom:1px solid rgba(255,255,255,.07);
}

.table-title{
    color:#ffffff !important;
    font-size:21px !important;
    font-weight:950 !important;
    letter-spacing:-.3px;
}

.table-responsive-custom{
    border-radius:0 !important;
}

.custom-erp-table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

.custom-erp-table thead th{
    background:rgba(7,10,22,.96) !important;
    color:#cfd4e8 !important;
    border-bottom:1px solid rgba(255,255,255,.07) !important;
    padding:16px 14px !important;
    font-size:13px !important;
    font-weight:950 !important;
    letter-spacing:.9px !important;
    text-transform:uppercase;
}

.custom-erp-table tbody td{
    background:rgba(18,22,46,.62) !important;
    color:#e8ebfb !important;
    border-bottom:1px solid rgba(255,255,255,.06) !important;
    padding:15px 14px !important;
    font-weight:650;
}

.custom-erp-table tbody tr:hover td{
    background:rgba(124,60,255,.15) !important;
}

.custom-erp-table tbody td strong{
    color:#ffffff !important;
}

.custom-erp-table .text-danger{
    color:#ff7b8a !important;
}

.custom-erp-table .badge{
    background:rgba(124,60,255,.16) !important;
    color:#ffffff !important;
    border-color:rgba(168,85,247,.28) !important;
    font-weight:850;
}

.row-total-footer td,
.custom-erp-table tbody tr.row-total-footer td{
    background:rgba(7,10,22,.92) !important;
    color:#ffffff !important;
    border-top:1px solid rgba(168,85,247,.25) !important;
    font-weight:950 !important;
}

.tax-mini-bar{
    background:rgba(255,255,255,.10) !important;
}

.tax-mini-bar span{
    background:linear-gradient(90deg,#7c3cff,#12b8d8) !important;
}

.branch-pill-box{
    color:#d7dbef !important;
}

/* Charts same black purple glass */
.tax-charts-grid{
    gap:18px !important;
    margin-top:22px !important;
}

.tax-chart-card{
    background:
        radial-gradient(circle at top left, rgba(124,60,255,.22), transparent 34%),
        radial-gradient(circle at bottom right, rgba(18,184,216,.10), transparent 28%),
        linear-gradient(180deg, rgba(22,25,55,.86), rgba(10,13,28,.90)) !important;
    border:1px solid var(--ts-border) !important;
    border-radius:18px !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.07),
        0 18px 48px rgba(0,0,0,.34) !important;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}

.tax-chart-title{
    color:#ffffff !important;
    font-weight:950 !important;
}

.tax-chart-badge{
    background:rgba(124,60,255,.18) !important;
    color:#ded3ff !important;
    border:1px solid rgba(168,85,247,.32) !important;
}

.tax-center-label strong,
.tax-center-label span,
.tax-legend-name,
.tax-legend-percent{
    color:#f5f6ff !important;
}

.tax-legend-value{
    color:#bca7ff !important;
}

/* Checkboxes */
input[type="checkbox"]{
    accent-color:#7c3cff !important;
}

/* Scrollbar */
.content-wrapper::-webkit-scrollbar,
.table-responsive-custom::-webkit-scrollbar{
    width:10px;
    height:10px;
}
.content-wrapper::-webkit-scrollbar-track,
.table-responsive-custom::-webkit-scrollbar-track{
    background:rgba(255,255,255,.05);
    border-radius:999px;
}
.content-wrapper::-webkit-scrollbar-thumb,
.table-responsive-custom::-webkit-scrollbar-thumb{
    background:rgba(124,60,255,.55);
    border-radius:999px;
}
.content-wrapper::-webkit-scrollbar-thumb:hover,
.table-responsive-custom::-webkit-scrollbar-thumb:hover{
    background:rgba(168,85,247,.75);
}

/* Compact popup/theme support if modal is added later */
.modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}
.modal-dialog{
    max-width:min(760px,94vw) !important;
}
.modal-content{
    background:linear-gradient(180deg, rgba(16,18,43,.96), rgba(8,10,22,.98)) !important;
    color:#fff !important;
    border:1px solid rgba(168,85,247,.30) !important;
    border-radius:18px !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    backdrop-filter:blur(18px);
}
.modal-header,.modal-footer{
    border-color:rgba(255,255,255,.08) !important;
}
.modal-title{
    color:#fff !important;
    font-weight:950 !important;
}
.modal-body{
    max-height:calc(100vh - 210px);
    overflow:auto;
}

/* Responsive */
@media(max-width:768px){
    .content-wrapper{padding:18px 14px !important}
    .page-title{font-size:24px !important}
    .stat-card{min-height:105px !important}
    .filter-actions{width:100%}
    .btn-action-gold,.btn-action-dark,.btn-action-whatsapp{width:100%;justify-content:center}
}


/* =====================================================
   TAX SUMMARY - TABLE WHITE HOVER / CLICK FIX
   Paste at VERY END before
===================================================== */

/* Bootstrap table hover white background force remove */
.premium-table-card table,
.premium-table-card .table,
.custom-erp-table{
    --bs-table-bg: transparent !important;
    --bs-table-hover-bg: rgba(124,58,237,.12) !important;
    --bs-table-hover-color: #ffffff !important;
    --bs-table-accent-bg: transparent !important;
    background: transparent !important;
}

/* Normal rows dark transparent */
.custom-erp-table tbody tr,
.custom-erp-table tbody td,
.premium-table-card table tbody tr,
.premium-table-card table tbody td{
    background: rgba(15,23,42,.30) !important;
    color: #e5e7eb !important;
}

/* Mouse hover white remove */
.custom-erp-table tbody tr:hover,
.custom-erp-table tbody tr:hover td,
.custom-erp-table tbody tr:hover th,
.premium-table-card table tbody tr:hover,
.premium-table-card table tbody tr:hover td,
.premium-table-card table tbody tr:hover th{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color: #ffffff !important;
    box-shadow: none !important;
}

/* Click / focus / active row white remove */
.custom-erp-table tbody tr:active,
.custom-erp-table tbody tr:active td,
.custom-erp-table tbody tr:focus,
.custom-erp-table tbody tr:focus td,
.custom-erp-table tbody tr:focus-within,
.custom-erp-table tbody tr:focus-within td,
.custom-erp-table tbody tr.selected,
.custom-erp-table tbody tr.selected td,
.custom-erp-table tbody tr.table-active,
.custom-erp-table tbody tr.table-active td,
.premium-table-card table tbody tr:active,
.premium-table-card table tbody tr:active td,
.premium-table-card table tbody tr:focus-within,
.premium-table-card table tbody tr:focus-within td,
.premium-table-card table tbody tr.selected,
.premium-table-card table tbody tr.selected td,
.premium-table-card table tbody tr.table-active,
.premium-table-card table tbody tr.table-active td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.38)) !important;
    color: #ffffff !important;
    outline: none !important;
}

/* Checkbox click ke baad bhi row white na ho */
.custom-erp-table tbody tr:has(input[type="checkbox"]:checked) td,
.premium-table-card table tbody tr:has(input[type="checkbox"]:checked) td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.38)) !important;
    color: #ffffff !important;
}

/* Table header black/dark hi rahe */
.custom-erp-table thead th,
.premium-table-card table thead th{
    background: rgba(7,11,20,.82) !important;
    color: #cbd5e1 !important;
}

/* Footer/total row white remove */
.custom-erp-table tfoot td,
.custom-erp-table tbody tr.row-total-footer td,
.custom-erp-table tbody tr:last-child td{
    background: rgba(7,11,20,.58) !important;
    color: #f8fafc !important;
}

/* Table cell selection blue/white flash reduce */
.custom-erp-table td::selection,
.custom-erp-table th::selection{
    background: rgba(124,58,237,.35) !important;
    color: #fff !important;
}
/* =====================================================
   TAX SUMMARY - TOPBAR TOGGLER POSITION FIX
   Paste at VERY END before
===================================================== */

.topbar{
    height:70px !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:0 22px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Toggler ko left side fixed align karo */
.topbar #sidebarToggle,
.topbar #toggleSidebar,
.topbar .sidebar-toggle,
.topbar .sidebar-toggler,
.topbar .hamburger-btn,
.topbar .topbar-menu-toggle,
.topbar [data-sidebar-toggle],
.topbar button:first-child{
    position:absolute !important;
    left:22px !important;
    top:50% !important;
    transform:translateY(-50%) !important;

    width:46px !important;
    height:46px !important;
    min-width:46px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;

    box-shadow:
        0 12px 26px rgba(0,0,0,.22),
        inset 0 1px 0 rgba(255,255,255,.04) !important;

    z-index:5 !important;
    transition:.22s ease !important;
}

/* Hover */
.topbar #sidebarToggle:hover,
.topbar #toggleSidebar:hover,
.topbar .sidebar-toggle:hover,
.topbar .sidebar-toggler:hover,
.topbar .hamburger-btn:hover,
.topbar .topbar-menu-toggle:hover,
.topbar [data-sidebar-toggle]:hover,
.topbar button:first-child:hover{
    background:rgba(124,58,237,.22) !important;
    border-color:rgba(139,92,246,.45) !important;
    color:#fff !important;
    transform:translateY(-50%) translateY(-1px) !important;
}

/* Toggler ke icon ko center rakho */
.topbar #sidebarToggle i,
.topbar #toggleSidebar i,
.topbar .sidebar-toggle i,
.topbar .sidebar-toggler i,
.topbar .hamburger-btn i,
.topbar .topbar-menu-toggle i,
.topbar [data-sidebar-toggle] i,
.topbar button:first-child i{
    font-size:20px !important;
    line-height:1 !important;
    margin:0 !important;
}

/* Search bar center mein rahe, toggler se overlap na kare */
.topbar .topbar-search,
.topbar .search-box,
.topbar .global-search,
.topbar form,
.topbar .search-wrap{
    margin-left:70px !important;
    margin-right:auto !important;
    max-width:620px !important;
}

/* Right user icons right side par */
.topbar .topbar-right,
.topbar .user-area,
.topbar .profile-area,
.topbar .right-actions{
    margin-left:auto !important;
}

/* =====================================================
   TAX SUMMARY - TOPBAR TOGGLER PROFIT PAGE STYLE FIX
   Paste at VERY END before
===================================================== */

/* Topbar ko profit_loss.php jesa straight layout do */
.topbar{
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;

    display:flex !important;
    align-items:center !important;
    justify-content:flex-start !important;

    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;

    padding:0 24px !important;
    gap:18px !important;
}

/* Toggler ko topbar ke left corner par rakho */
.topbar #sidebarToggle,
.topbar #toggleSidebar,
.topbar .sidebar-toggle,
.topbar .sidebar-toggler,
.topbar .hamburger-btn,
.topbar .topbar-menu-toggle,
.topbar [data-sidebar-toggle],
.topbar button:first-child{
    position:relative !important;
    left:auto !important;
    top:auto !important;
    transform:none !important;

    width:46px !important;
    height:46px !important;
    min-width:46px !important;
    max-width:46px !important;

    margin:0 130px 0 0 !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;

    box-shadow:
        0 12px 26px rgba(0,0,0,.22),
        inset 0 1px 0 rgba(255,255,255,.04) !important;

    z-index:5 !important;
    transition:.22s ease !important;
    cursor:pointer !important;
}

/* Toggler hover */
.topbar #sidebarToggle:hover,
.topbar #toggleSidebar:hover,
.topbar .sidebar-toggle:hover,
.topbar .sidebar-toggler:hover,
.topbar .hamburger-btn:hover,
.topbar .topbar-menu-toggle:hover,
.topbar [data-sidebar-toggle]:hover,
.topbar button:first-child:hover{
    background:rgba(124,58,237,.22) !important;
    border-color:rgba(139,92,246,.45) !important;
    color:#fff !important;
    transform:translateY(-1px) !important;
}

/* Icon center */
.topbar #sidebarToggle i,
.topbar #toggleSidebar i,
.topbar .sidebar-toggle i,
.topbar .sidebar-toggler i,
.topbar .hamburger-btn i,
.topbar .topbar-menu-toggle i,
.topbar [data-sidebar-toggle] i,
.topbar button:first-child i{
    font-size:20px !important;
    line-height:1 !important;
    margin:0 !important;
}

/* Search box ko toggler ke baad center side par rakho */
.topbar .topbar-search,
.topbar .search-box,
.topbar .global-search,
.topbar form,
.topbar .search-wrap{
    flex:0 1 620px !important;
    max-width:620px !important;
    margin:0 auto 0 0 !important;
}

/* Right side icons/profile right par */
.topbar .topbar-right,
.topbar .user-area,
.topbar .profile-area,
.topbar .right-actions,
.topbar .notification-area{
    margin-left:auto !important;
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
}


/* =========================================================
   TAX SUMMARY.PHP - FINAL LIGHT MODE
   Topbar/sidebar dark reference theme mein rahenge.
   Sirf report content light hoga.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE BACKGROUND
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

/* Sidebar ko touch nahi karna */
html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 54%,
            #faf7ff 100%
        ) !important;

    color:#0f172a !important;
}

/* Main report area */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 5%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 88% 4%,
            rgba(59,130,246,.05),
            transparent 28%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 48%,
            #faf7ff 78%,
            #f8fafc 100%
        ) !important;

    color:#0f172a !important;
}

/* Main panel dark grid remove */
html[data-pos-theme="light"] body .main-panel::before{
    background:
        linear-gradient(
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ),
        linear-gradient(
            90deg,
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ) !important;

    opacity:.38 !important;
}

/* Page title */
html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

/* Colored cards colored hi rahenge */
html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;

    border:
        1px solid rgba(255,255,255,.28) !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.15) !important;
}

html[data-pos-theme="light"] body .stat-title,
html[data-pos-theme="light"] body .stat-sub,
html[data-pos-theme="light"] body .stat-value{
    color:#ffffff !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .stat-red{
    background:
        linear-gradient(
            135deg,
            #9d174d,
            #b11f92 48%,
            #7c3aed
        ) !important;
}

html[data-pos-theme="light"] body .stat-orange,
html[data-pos-theme="light"] body .stat-gold{
    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #9b6bd2 45%,
            #f59e0b
        ) !important;
}

html[data-pos-theme="light"] body .stat-green{
    background:
        linear-gradient(
            135deg,
            #047857,
            #16a34a
        ) !important;
}

/* =========================================================
   FILTER CARD
========================================================= */

html[data-pos-theme="light"] body .filter-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Filter inputs */
html[data-pos-theme="light"] body .filter-item{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"] body .filter-item:hover,
html[data-pos-theme="light"] body .filter-item:focus-within{
    background:#ffffff !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.12) !important;

    transform:none !important;
}

html[data-pos-theme="light"] body .filter-item i{
    color:#7c3aed !important;
}

html[data-pos-theme="light"] body .filter-item input,
html[data-pos-theme="light"] body .filter-item select{
    background:transparent !important;
    color:#0f172a !important;
    opacity:1 !important;
}

html[data-pos-theme="light"]
body .filter-item input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"]
body .filter-item select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"]
body input[type="date"]{
    color-scheme:light !important;
}

/* Filter button */
html[data-pos-theme="light"] body .btn-action-gold{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border:none !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

/* CSV and Reset */
html[data-pos-theme="light"] body .btn-action-dark,
html[data-pos-theme="light"] body .btn-action-whatsapp{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"] body .btn-action-dark:hover,
html[data-pos-theme="light"] body .btn-action-whatsapp:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;

    transform:translateY(-1px) !important;
}

/* =========================================================
   TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .premium-table-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Table title */
html[data-pos-theme="light"] body .table-header-flex{
    background:#ffffff !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .table-title{
    color:#0f172a !important;
}

/* Bootstrap dark table variables reset */
html[data-pos-theme="light"] body .custom-erp-table,
html[data-pos-theme="light"] body .custom-erp-table thead,
html[data-pos-theme="light"] body .custom-erp-table tbody,
html[data-pos-theme="light"] body .custom-erp-table tr,
html[data-pos-theme="light"] body .custom-erp-table th,
html[data-pos-theme="light"] body .custom-erp-table td{
    --bs-table-bg:transparent !important;
    --bs-table-color:#1e293b !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
    --bs-table-accent-bg:transparent !important;
}

/* Table header */
html[data-pos-theme="light"]
body .custom-erp-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-bottom:1px solid #cbd5e1 !important;
    border-right:1px solid #e2e8f0 !important;

    box-shadow:none !important;
}

/* Normal rows */
html[data-pos-theme="light"]
body .custom-erp-table tbody tr,

html[data-pos-theme="light"]
body .custom-erp-table tbody td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-bottom-color:#e8edf4 !important;

    box-shadow:none !important;
}

/* Strong values */
html[data-pos-theme="light"]
body .custom-erp-table tbody td strong{
    color:#0f172a !important;
}

/* Row hover */
html[data-pos-theme="light"]
body .custom-erp-table tbody tr:hover td{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;

    color:#0f172a !important;

    box-shadow:none !important;
}

/* Checked row */
html[data-pos-theme="light"]
body .custom-erp-table tbody tr:has(
    input[type="checkbox"]:checked
) td{
    background:#f3efff !important;
    background-image:none !important;

    color:#0f172a !important;
}

/* Focus and active row */
html[data-pos-theme="light"]
body .custom-erp-table tbody tr:active td,

html[data-pos-theme="light"]
body .custom-erp-table tbody tr:focus-within td,

html[data-pos-theme="light"]
body .custom-erp-table tbody tr.selected td,

html[data-pos-theme="light"]
body .custom-erp-table tbody tr.table-active td{
    background:#f3efff !important;
    background-image:none !important;

    color:#0f172a !important;
}

/* Discount text */
html[data-pos-theme="light"]
body .custom-erp-table .text-danger{
    color:#e11d48 !important;
}

/* Payment badge */
html[data-pos-theme="light"]
body .custom-erp-table .badge{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border:1px solid #ddd6fe !important;
}

/* Branch */
html[data-pos-theme="light"] body .branch-pill-box{
    color:#475569 !important;
}

/* Tax mini bar */
html[data-pos-theme="light"] body .tax-mini-bar{
    background:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .tax-mini-bar span{
    background:
        linear-gradient(
            90deg,
            #7c3aed,
            #06b6d4
        ) !important;
}

/* Total row */
html[data-pos-theme="light"]
body .custom-erp-table tbody tr.row-total-footer td{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#0f172a !important;

    border-top:1px solid #cbd5e1 !important;
    border-bottom-color:#cbd5e1 !important;

    font-weight:900 !important;
}

/* Existing last-child dark rule cancel */
html[data-pos-theme="light"]
body .custom-erp-table tbody tr:last-child td{
    background:#ffffff !important;
    color:#1e293b !important;
}

html[data-pos-theme="light"]
body .custom-erp-table tbody tr.row-total-footer:last-child td{
    background:#f1f5f9 !important;
    color:#0f172a !important;
}

/* Checkbox */
html[data-pos-theme="light"]
body input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* =========================================================
   CHART CARDS
========================================================= */

html[data-pos-theme="light"] body .tax-chart-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .tax-chart-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .tax-chart-badge{
    background:#f3efff !important;
    color:#5b21b6 !important;

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

/* =========================================================
   SCROLLBARS
========================================================= */

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-track,

html[data-pos-theme="light"]
body .table-responsive-custom::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-thumb,

html[data-pos-theme="light"]
body .table-responsive-custom::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
}

/* =========================================================
   OPTIONAL MODAL LIGHT MODE
========================================================= */

html[data-pos-theme="light"] body .modal-content{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.22) !important;
}

html[data-pos-theme="light"] body .modal-header,
html[data-pos-theme="light"] body .modal-footer{
    background:#f8fafc !important;
    border-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .modal-title{
    color:#0f172a !important;
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
                <h1 class="page-title">Tax Summary Report</h1>
            </div>

            <div class="stats-grid">
                <div class="stat-card stat-red">
                    <div class="stat-title">Total Sales</div>
                    <div class="stat-sub"><?= money($total_sales) ?></div>
                    <div class="stat-value"><?= number_format($total_sales, 0) ?></div>
                </div>

                <div class="stat-card stat-orange">
                    <div class="stat-title">Net Sales</div>
                    <div class="stat-sub"><?= money($net_sales) ?></div>
                    <div class="stat-value"><?= number_format($net_sales, 0) ?></div>
                </div>

                <div class="stat-card stat-gold">
                    <div class="stat-title">Pending Tax Liability</div>
                    <div class="stat-sub"><?= money($pending_tax) ?></div>
                    <div class="stat-value"><?= number_format($pending_tax, 0) ?></div>
                </div>

                <div class="stat-card stat-green">
                    <div class="stat-title">Tax % of Revenue</div>
                    <div class="stat-sub"><?= number_format($tax_percent_of_revenue, 2) ?>%</div>
                    <div class="stat-value"><?= number_format($tax_percent_of_revenue, 2) ?>%</div>
                </div>
            </div>

            <div class="filter-card">
                <form method="GET">
                    <div class="filter-toolbar-row">
                        <div class="filter-item search-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" placeholder="Search invoice or customer..." value="<?= e($search) ?>">
                        </div>

                        <div class="filter-item">
                            <i class="bi bi-geo-alt"></i>
                            <select name="region">
                                <option value="">All Regions</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>" <?= ($region == $b['id']) ? 'selected' : '' ?>>
                                        <?= e($b['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-item">
                            <i class="bi bi-grid"></i>
                            <select name="type">
                                <option value="">All Types</option>
                                <option value="walkin" <?= $type === 'walkin' ? 'selected' : '' ?>>Walk-in</option>
                                <option value="online" <?= $type === 'online' ? 'selected' : '' ?>>Online</option>
                            </select>
                        </div>

                        <div class="filter-item">
                            <i class="bi bi-credit-card"></i>
                            <select name="status">
                                <option value="">All Payment Modes</option>
                                <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                <option value="cash" <?= $status === 'cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="card" <?= $status === 'card' ? 'selected' : '' ?>>Card</option>
                            </select>
                        </div>

                        <div class="filter-item">
                            <i class="bi bi-calendar-date"></i>
                            <input type="date" name="from_date" value="<?= e($from_date) ?>">
                        </div>

                        <div class="filter-item">
                            <i class="bi bi-calendar-date"></i>
                            <input type="date" name="to_date" value="<?= e($to_date) ?>">
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn-action-gold">
                                <i class="bi bi-funnel me-1"></i> Filter
                            </button>

                            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-action-dark d-inline-flex align-items-center text-decoration-none">
                                <i class="bi bi-download me-1"></i> CSV
                            </a>

                            <a href="<?= e(strtok($_SERVER["REQUEST_URI"], '?')) ?>" class="btn-action-whatsapp d-inline-flex align-items-center text-decoration-none">
                                <i class="bi bi-arrow-clockwise me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <div class="premium-table-card">
                <div class="table-header-flex">
                    <h2 class="table-title">Tax Summary Detail</h2>
                </div>

                <div class="table-responsive-custom">
                    <table class="custom-erp-table" id="reportTable">
                        <thead>
    <tr>
        <th><input type="checkbox" id="checkAllRows"></th>
        <th>Date</th>
        <th>Invoice Count</th>
        <th>Total Sales</th>
        <th>Total Tax</th>
        <th>VAT / GST (Other | Total)</th>
        <th>Discounts</th>
        <th>Net Sales</th>
        <th>Payment Mode</th>
        <th>Branch</th>
    </tr>
</thead>
                        <tbody>
    <?php if (!empty($tax_summary_rows)): ?>
        <?php foreach ($tax_summary_rows as $row): ?>
            <?php
                $rowTax = (float)$row['total_tax'];
                $barPercent = $total_tax > 0 ? (($rowTax / $total_tax) * 100) : 0;
                $paymentMode = strtolower(trim($row['payment_method'] ?? 'cash'));

                $rowVat   = $rowTax * $vat_ratio;
                $rowGst   = $rowTax * $gst_ratio;
                $rowOther = $rowTax * $other_ratio;

                $rowKey = md5(($row['sale_date'] ?? '') . '|' . ($row['branch_id'] ?? '') . '|' . ($row['payment_method'] ?? ''));
            ?>
            <tr>
                <td>
                    <input
                        type="checkbox"
                        class="summary-row-check"
                        value="<?= e($rowKey) ?>"
                        data-date="<?= e($row['sale_date']) ?>"
                        data-branch-id="<?= (int)($row['branch_id'] ?? 0) ?>"
                        data-payment="<?= e($row['payment_method'] ?? '') ?>"
                    >
                </td>
                <td><?= e(date('d M Y', strtotime($row['sale_date']))) ?></td>
                <td><?= (int)$row['invoice_count'] ?></td>
                <td><?= money($row['total_sales']) ?></td>
                <td>
                    <div class="tax-bar-container">
                        <div class="tax-mini-bar">
                            <span style="width:<?= max(6, min(100, $barPercent)) ?>%;"></span>
                        </div>
                        <span><?= money($rowTax) ?></span>
                    </div>
                </td>
                <td><?= money($rowVat) ?> / <?= money($rowGst) ?> (<?= money($rowOther) ?> | <?= money($rowTax) ?>)</td>
                <td class="text-danger">-<?= money($row['total_discount']) ?></td>
                <td><strong><?= money($row['net_sales']) ?></strong></td>
                <td><span class="badge border text-dark bg-light"><?= e(ucfirst($paymentMode)) ?></span></td>
                <td>
                    <div class="branch-pill-box">
                        <?= e($row['branch_name']) ?>
                        <i class="bi bi-chevron-right opacity-50"></i>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>

        <tr class="row-total-footer">
            <td></td>
            <td>Total:</td>
            <td><?= (int)$total_invoices ?></td>
            <td><?= money($total_sales) ?></td>
            <td><?= money($total_tax) ?></td>
            <td><?= money($vat_amount) ?> / <?= money($gst_amount) ?> (<?= money($other_tax_amount) ?> | <?= money($total_tax) ?>)</td>
            <td><?= money($total_discount) ?></td>
            <td><?= money($net_sales) ?></td>
            <td>-</td>
            <td>-</td>
        </tr>
    <?php else: ?>
        <tr>
            <td colspan="10" class="text-center py-5">No records found.</td>
        </tr>
    <?php endif; ?>
</tbody>
                    </table>
                </div>
            </div>

            <div class="tax-charts-grid">
                <div class="tax-chart-card">
                    <div class="tax-chart-header">
                        <h3 class="tax-chart-title">Active Tax Rules</h3>
                        <div class="tax-chart-badge">Configured Rates</div>
                    </div>

                    <div class="tax-breakdown-wrap">
                        <div class="tax-breakdown-canvas-wrap">
                            <canvas id="taxBreakdownChart"></canvas>
                            <div class="tax-center-label">
                                <strong><?= count($activeTaxes) ?></strong>
                                <span>Active Taxes</span>
                            </div>
                        </div>

                        <div class="tax-legend" id="taxLegendCustom"></div>
                    </div>
                </div>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const breakdownLabels = <?= json_encode($taxBreakdownLabels) ?>;
    const breakdownValues = <?= json_encode($taxBreakdownValues) ?>;

    const monthLabels = <?= json_encode($monthLabels) ?>;
    const walkinTaxSeries = <?= json_encode($walkinTaxSeries) ?>;
    const onlineTaxSeries = <?= json_encode($onlineTaxSeries) ?>;
    const totalTaxSeries = <?= json_encode($totalTaxSeries) ?>;

    const chartColors = ['#3E8C64','#C89B3C','#9E6C28','#7F8B97','#5A4F56','#A855F7','#EC4899'];

    const legendContainer = document.getElementById('taxLegendCustom');
    const totalBreakdown = breakdownValues.reduce((a, b) => a + Number(b), 0);

    if (legendContainer) {
        legendContainer.innerHTML = breakdownLabels.map((label, index) => {
            const value = Number(breakdownValues[index] || 0);
            const percent = totalBreakdown > 0 ? ((value / totalBreakdown) * 100).toFixed(1) : '0.0';

            return `
                <div class="tax-legend-item">
                    <span class="tax-legend-color" style="background:${chartColors[index % chartColors.length]}"></span>
                    <span class="tax-legend-name">${label}</span>
                    <span class="tax-legend-percent">${percent}%</span>
                    <span class="tax-legend-value">${value.toFixed(2)}%</span>
                </div>
            `;
        }).join('');
    }

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
                                return `${context.label}: ${Number(context.raw).toFixed(2)}%`;
                            }
                        }
                    }
                }
            }
        });
    }

    function formatPKR(value) {
        value = Number(value || 0);
        if (value >= 10000000) return 'PKR ' + (value / 10000000).toFixed(2) + 'Cr';
        if (value >= 100000) return 'PKR ' + (value / 100000).toFixed(2) + 'L';
        if (value >= 1000) return 'PKR ' + (value / 1000).toFixed(0) + 'K';
        return 'PKR ' + value.toFixed(0);
    }

    const trendCtx = document.getElementById('monthlyTaxTrendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            data: {
                labels: monthLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Walk-in Tax',
                        data: walkinTaxSeries,
                        backgroundColor: 'rgba(74, 148, 97, 0.78)',
                        borderRadius: 6,
                        barThickness: 22
                    },
                    {
                        type: 'bar',
                        label: 'Online Tax',
                        data: onlineTaxSeries,
                        backgroundColor: 'rgba(196, 154, 64, 0.78)',
                        borderRadius: 6,
                        barThickness: 22
                    },
                    {
                        type: 'line',
                        label: 'Total Tax',
                        data: totalTaxSeries,
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
                        grid: { display: false, drawBorder: false },
                        ticks: { color: '#bfb6bd' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255,255,255,.08)', drawBorder: false },
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
const checkAllRows = document.getElementById('checkAllRows');

checkAllRows?.addEventListener('change', function () {
    document.querySelectorAll('.summary-row-check').forEach(cb => {
        cb.checked = this.checked;
    });
});


</script>
</body>
</html>