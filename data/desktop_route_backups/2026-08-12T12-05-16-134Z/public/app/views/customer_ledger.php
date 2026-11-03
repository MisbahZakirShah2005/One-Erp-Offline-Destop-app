<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = (int)($_SESSION['user_id'] ?? 0);
$logged_role_id   = (int)($_SESSION['role_id'] ?? 0);
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS cnt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['cnt'] ?? 0) > 0);
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['cnt'] ?? 0) > 0);
}

function build_query_string(array $extra = []): string
{
    $params = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return http_build_query($params);
}

$hasClientsTable   = table_exists($conn, 'clients');
$hasCustomersTable = table_exists($conn, 'customers');
$hasSalesTable     = table_exists($conn, 'sales');
$hasPaymentsTable  = table_exists($conn, 'payments');
$hasBranchesTable  = table_exists($conn, 'branches');

if (!$hasCustomersTable || !$hasSalesTable || !$hasPaymentsTable || !$hasBranchesTable) {
    die("Required tables are missing.");
}

$salesCustomerColumn = column_exists($conn, 'sales', 'customer_id')
    ? 'customer_id'
    : (column_exists($conn, 'sales', 'user_id') ? 'user_id' : '');

if ($salesCustomerColumn === '') {
    die("sales table must contain customer_id or user_id column.");
}

$salesDueDateColumn  = column_exists($conn, 'sales', 'due_date');
$salesInvoiceColumn  = column_exists($conn, 'sales', 'invoice_no');
$paymentsRefColumn   = column_exists($conn, 'payments', 'reference_no');
$customerPhoneColumn = column_exists($conn, 'customers', 'phone');

/* ================= FETCH CLIENTS ================= */
$clients = [];
if ($logged_role_id == 1 && $hasClientsTable) {
    $res = $conn->query("SELECT id, business_name FROM clients ORDER BY business_name ASC");
    while ($row = $res->fetch_assoc()) {
        $clients[] = $row;
    }
}

/* ================= SELECTED CLIENT ================= */
$selected_client = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
if ($logged_role_id != 1) {
    $selected_client = $logged_client_id;
}
if ($selected_client <= 0) {
    $selected_client = $logged_client_id;
}

/* ================= FETCH BRANCHES ================= */
$branches = [];
if ($selected_client > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $selected_client);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($b = $res->fetch_assoc()) {
        $branches[] = $b;
    }
    $stmt->close();
}

/* ================= FILTERS ================= */
$selected_branch      = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$start_date           = trim($_GET['start_date'] ?? date('Y-m-01'));
$end_date             = trim($_GET['end_date'] ?? date('Y-m-d'));
$search_customer      = trim($_GET['search_customer'] ?? '');
$selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if ($start_date === '') $start_date = date('Y-m-01');
if ($end_date === '') $end_date = date('Y-m-d');
if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

/* ================= SUMMARY + CUSTOMER LIST ================= */
$customerRows = [];
$totalDue = 0;
$paymentToday = 0;
$totalReceivable = 0;
$overdueBalance = 0;

if ($selected_branch > 0) {
    $customerSql = "
        SELECT
            c.id AS customer_id,
            c.name AS customer_name,
            " . ($customerPhoneColumn ? "c.phone," : "NULL AS phone,") . "
            COALESCE(sales_agg.total_sales, 0) AS total_sales,
            COALESCE(sales_agg.total_tax, 0) AS total_tax,
            COALESCE(pay_agg.total_paid, 0) AS total_paid,
            pay_last.last_payment_date AS last_payment_date,
            sales_last.due_date AS due_date
        FROM customers c

        LEFT JOIN (
            SELECT
                s.{$salesCustomerColumn} AS customer_id,
                COALESCE(SUM(s.total), 0) AS total_sales,
                COALESCE(SUM(s.tax), 0) AS total_tax
            FROM sales s
            WHERE s.client_id = ?
              AND s.branch_id = ?
              AND DATE(s.created_at) BETWEEN ? AND ?
            GROUP BY s.{$salesCustomerColumn}
        ) AS sales_agg ON sales_agg.customer_id = c.id

        LEFT JOIN (
            SELECT
                p.customer_id,
                COALESCE(SUM(p.amount), 0) AS total_paid
            FROM payments p
            WHERE p.client_id = ?
              AND p.branch_id = ?
              AND DATE(p.created_at) BETWEEN ? AND ?
            GROUP BY p.customer_id
        ) AS pay_agg ON pay_agg.customer_id = c.id

        LEFT JOIN (
            SELECT
                p.customer_id,
                MAX(DATE(p.created_at)) AS last_payment_date
            FROM payments p
            WHERE p.client_id = ?
              AND p.branch_id = ?
            GROUP BY p.customer_id
        ) AS pay_last ON pay_last.customer_id = c.id

        LEFT JOIN (
            SELECT
                s.{$salesCustomerColumn} AS customer_id,
                MAX(" . ($salesDueDateColumn ? "DATE(s.due_date)" : "DATE(s.created_at)") . ") AS due_date
            FROM sales s
            WHERE s.client_id = ?
              AND s.branch_id = ?
            GROUP BY s.{$salesCustomerColumn}
        ) AS sales_last ON sales_last.customer_id = c.id

        WHERE c.client_id = ?
    ";

    $params = [
        $selected_client, $selected_branch, $start_date, $end_date,
        $selected_client, $selected_branch, $start_date, $end_date,
        $selected_client, $selected_branch,
        $selected_client, $selected_branch,
        $selected_client
    ];
    $types = "iissiissiiiii";

    if ($search_customer !== '') {
        $customerSql .= " AND (
            c.name LIKE ?
            " . ($customerPhoneColumn ? " OR c.phone LIKE ? " : "") . "
            OR CAST(c.id AS CHAR) LIKE ?
        ) ";

        $searchLike = '%' . $search_customer . '%';

        $params[] = $searchLike;
        $types .= "s";

        if ($customerPhoneColumn) {
            $params[] = $searchLike;
            $types .= "s";
        }

        $params[] = $searchLike;
        $types .= "s";
    }

    $customerSql .= " ORDER BY c.name ASC";

    $stmt = $conn->prepare($customerSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $balance = ((float)$row['total_sales'] + (float)$row['total_tax']) - (float)$row['total_paid'];
        $row['balance'] = $balance;

        // normal mode: only due customers
        if ($search_customer === '' && $balance <= 0) {
            continue;
        }

        $customerRows[] = $row;

        if ($balance > 0) {
            $totalDue += $balance;
            $totalReceivable += $balance;

            if (!empty($row['due_date']) && $row['due_date'] < date('Y-m-d')) {
                $overdueBalance += $balance;
            }
        }
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount),0) AS payment_today
        FROM payments
        WHERE client_id = ?
          AND branch_id = ?
          AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param("ii", $selected_client, $selected_branch);
    $stmt->execute();
    $paymentToday = (float)($stmt->get_result()->fetch_assoc()['payment_today'] ?? 0);
    $stmt->close();
}

/* ================= DEFAULT / SAFE SELECTED CUSTOMER ================= */
$customerIds = array_map(static fn($r) => (int)$r['customer_id'], $customerRows);

if ($selected_customer_id > 0 && !in_array($selected_customer_id, $customerIds, true)) {
    $selected_customer_id = 0;
}

if ($selected_customer_id <= 0 && !empty($customerRows)) {
    $selected_customer_id = (int)$customerRows[0]['customer_id'];
}

/* ================= CUSTOMER LEDGER DETAILS ================= */
/* ================= CUSTOMER LEDGER DETAILS ================= */
$ledgerRows = [];
$selectedCustomerName = '';
$ledgerTotalDue = 0;
$ledgerTotalPayment = 0;
$ledgerTotalBalance = 0;

if ($selected_customer_id > 0 && $selected_branch > 0) {
    $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $selected_customer_id, $selected_client);
    $stmt->execute();
    $selectedCustomerName = (string)($stmt->get_result()->fetch_assoc()['name'] ?? '');
    $stmt->close();

    $buildLedgerSql = function(bool $useDateFilter) use (
        $salesInvoiceColumn,
        $salesDueDateColumn,
        $paymentsRefColumn,
        $salesCustomerColumn
    ) {
        $salesDateWhere = $useDateFilter ? "AND DATE(s.created_at) BETWEEN ? AND ?" : "";
        $paymentDateWhere = $useDateFilter ? "AND DATE(p.created_at) BETWEEN ? AND ?" : "";

        $salesQuery = "
            SELECT
                DATE(s.created_at) AS row_date,
                CONVERT(
                    " . ($salesInvoiceColumn
                        ? "COALESCE(s.invoice_no, CONCAT('INV-', s.id))"
                        : "CONCAT('INV-', s.id)") . "
                    USING utf8mb4
                ) COLLATE utf8mb4_unicode_ci AS description_text,
                CONVERT('Sales Invoice' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS row_type,
                (COALESCE(s.total,0) + COALESCE(s.tax,0)) AS due_amount,
                0 AS payment_amount,
                " . ($salesDueDateColumn ? "DATE(s.due_date)" : "DATE(s.created_at)") . " AS due_date
            FROM sales s
            WHERE s.client_id = ?
              AND s.branch_id = ?
              AND s.{$salesCustomerColumn} = ?
              {$salesDateWhere}
        ";

        $paymentsQuery = "
            SELECT
                DATE(p.created_at) AS row_date,
                CONVERT(
                    " . ($paymentsRefColumn
                        ? "CONCAT('Payment Received Ref: ', COALESCE(p.reference_no, CONCAT('#PMT-', p.id)))"
                        : "CONCAT('Payment Received Ref: #PMT-', p.id)") . "
                    USING utf8mb4
                ) COLLATE utf8mb4_unicode_ci AS description_text,
                CONVERT('Payment' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS row_type,
                0 AS due_amount,
                COALESCE(p.amount,0) AS payment_amount,
                DATE(p.created_at) AS due_date
            FROM payments p
            WHERE p.client_id = ?
              AND p.branch_id = ?
              AND p.customer_id = ?
              {$paymentDateWhere}
        ";

        return "
            SELECT * FROM (
                {$salesQuery}
                UNION ALL
                {$paymentsQuery}
            ) ledger
            ORDER BY row_date ASC, row_type ASC
        ";
    };

    $fetchLedgerRows = function(bool $useDateFilter) use (
        $conn,
        $selected_client,
        $selected_branch,
        $selected_customer_id,
        $start_date,
        $end_date,
        &$buildLedgerSql
    ) {
        $sql = $buildLedgerSql($useDateFilter);
        $stmt = $conn->prepare($sql);

        if ($useDateFilter) {
            $stmt->bind_param(
                "iiissiiiss",
                $selected_client, $selected_branch, $selected_customer_id, $start_date, $end_date,
                $selected_client, $selected_branch, $selected_customer_id, $start_date, $end_date
            );
        } else {
            $stmt->bind_param(
                "iiiiii",
                $selected_client, $selected_branch, $selected_customer_id,
                $selected_client, $selected_branch, $selected_customer_id
            );
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    };

    // Pehle current date range ka ledger lao
    $rawLedgerRows = $fetchLedgerRows(true);

    // Agar empty ho to all-time ledger lao
    if (empty($rawLedgerRows)) {
        $rawLedgerRows = $fetchLedgerRows(false);
    }

    $runningBalance = 0;
    foreach ($rawLedgerRows as $row) {
        $runningBalance += (float)$row['due_amount'];
        $runningBalance -= (float)$row['payment_amount'];
        $row['balance'] = $runningBalance;

        if ((float)$row['payment_amount'] > 0) {
            $row['status_text'] = 'Paid';
            $row['status_class'] = 'status-green';
        } else {
            $row['status_text'] = $runningBalance > 0 ? 'Unpaid' : 'Paid';
            $row['status_class'] = $runningBalance > 0 ? 'status-red' : 'status-green';
        }

        $ledgerTotalDue += (float)$row['due_amount'];
        $ledgerTotalPayment += (float)$row['payment_amount'];
        $ledgerRows[] = $row;
    }

    $ledgerTotalBalance = $ledgerTotalDue - $ledgerTotalPayment;
}

/* ================= EXPORT CSV ================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv_customers') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=credit_customer_details_' . date('Y-m-d_H-i-s') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['#', 'Customer Name', 'Total Due', 'Last Payment', 'Due Date']);

    foreach ($customerRows as $index => $row) {
        fputcsv($output, [
            $index + 1,
            $row['customer_name'],
            $row['balance'],
            $row['last_payment_date'],
            $row['due_date']
        ]);
    }

    fclose($output);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv_ledger') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=customer_ledger_' . date('Y-m-d_H-i-s') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['#', 'Date', 'Invoice # / Description', 'Type', 'Due Amount', 'Payment', 'Balance', 'Status']);

    foreach ($ledgerRows as $index => $row) {
        fputcsv($output, [
            $index + 1,
            $row['row_date'],
            $row['description_text'],
            $row['row_type'],
            $row['due_amount'],
            $row['payment_amount'],
            $row['balance'],
            $row['status_text']
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
<title>Credit Ledger Report</title>
<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef1f8;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;}
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
.main-panel{flex-grow:1;display:flex;flex-direction:column;min-width:0;}
.topbar{height:60px;border-bottom:1px solid #dde2ef;display:flex;align-items:center;padding:0 20px;background:#fff;}
.content-wrapper{padding:20px;flex-grow:1;overflow-y:auto;}
.page-title-wrap{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.page-title-box{background:#fff;border:1px solid #e3e7f1;padding:14px 18px;border-radius:6px;min-width:340px;}
.page-title{font-size:22px;font-weight:800;color:#14213d;margin:0;text-transform:uppercase;}
.top-actions{display:flex;gap:10px;flex-wrap:wrap;}
.btn-soft{height:40px;border:1px solid #d8ddea;border-radius:8px;background:#fff;padding:0 16px;font-weight:600;color:#374151;display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.search-row{display:grid;grid-template-columns:1fr 140px;gap:12px;margin-bottom:16px;}
.search-wrap{position:relative;}
.search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#6b7280;}
.search-wrap input{width:100%;height:44px;border:1px solid #d8ddea;border-radius:8px;padding:0 14px 0 42px;background:#fff;outline:none;}
.search-btn{height:44px;border:none;border-radius:8px;background:linear-gradient(180deg,#2866df 0%,#1f57c7 100%);color:#fff;font-size:16px;font-weight:700;}
.filter-mini{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.filter-mini select,.filter-mini input{height:40px;border:1px solid #d8ddea;border-radius:8px;padding:0 12px;background:#fff;outline:none;}
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px;}
.stat-card{border-radius:10px;color:#fff;padding:14px 16px;min-height:76px;display:flex;flex-direction:column;justify-content:center;box-shadow:0 8px 20px rgba(0,0,0,.08);}
.stat-title{font-size:14px;font-weight:600;opacity:.95;margin-bottom:4px;display:flex;align-items:center;gap:8px;}
.stat-value{font-size:18px;font-weight:800;}
.card-blue{background:linear-gradient(135deg,#2f6ef6,#2358c8);}
.card-green{background:linear-gradient(135deg,#17944e,#0f743d);}
.card-orange{background:linear-gradient(135deg,#f5a31c,#dd8610);}
.card-red{background:linear-gradient(135deg,#ef3b36,#cc2520);}
.card-ghost{background:linear-gradient(135deg,#d8b39d,#c59b84);}
.section-card{background:#fff;border:1px solid #dfe4ef;border-radius:12px;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.04);margin-bottom:18px;}
.section-head{padding:12px 14px;font-size:16px;font-weight:800;color:#1f2937;border-bottom:1px solid #edf0f5;}
.table-wrap{overflow:auto;}
.report-table{width:100%;min-width:980px;border-collapse:collapse;}
.report-table thead th{background:linear-gradient(180deg,#1d2840,#25344f);color:#fff;padding:12px 14px;font-size:14px;font-weight:700;white-space:nowrap;}
.report-table tbody td{padding:12px 14px;border-top:1px solid #eceff5;font-size:14px;color:#1f2937;vertical-align:middle;}
.report-table tbody tr:hover td{background:#fafbff;}
.action-btn{height:34px;border:none;border-radius:6px;background:linear-gradient(180deg,#6c728b,#59607a);color:#fff;font-weight:700;padding:0 14px;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;}
.footer-bar{padding:12px 14px;border-top:1px solid #eceff5;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:#fff;}
.pagination-wrap{display:flex;align-items:center;gap:8px;}
.page-btn{height:34px;min-width:34px;border:1px solid #d6dcea;background:#fff;border-radius:6px;padding:0 10px;font-weight:700;color:#374151;}
.page-btn.active{background:#1f57c7;color:#fff;border-color:#1f57c7;}
.ledger-title-wrap{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 14px 0 14px;}
.ledger-title{font-size:18px;font-weight:800;color:#1f2937;}
.breadcrumb-mini{font-size:14px;color:#1f57c7;padding:0 14px 12px 14px;}
.payment-entry-btn{height:36px;border:none;border-radius:8px;background:linear-gradient(180deg,#19a857,#158747);color:#fff;font-weight:700;padding:0 16px;display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.balance-pill{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:6px;font-weight:800;font-size:13px;color:#fff;min-width:100px;}
.status-red{background:linear-gradient(180deg,#df2d2d,#b41313);}
.status-green{background:linear-gradient(180deg,#1b8e54,#146d40);}
.total-row td{font-weight:800;background:#fafbff;}
.note-box{background:#fff3cd;color:#7a5a00;border:1px solid #ffe69c;padding:12px 14px;border-radius:8px;margin-bottom:16px;font-weight:600;}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){.search-row{grid-template-columns:1fr;}.stats-grid{grid-template-columns:1fr;}.page-title-wrap{align-items:stretch;}}

/* =====================================================
   CUSTOMER_LEDGER.PHP - DARK PURPLE TRANSPARENT GREEN GLASS THEME
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
    --oms-green:#22c55e;
    --oms-green-2:#16a34a;
    --oms-blue:#3b82f6;
    --oms-cyan:#06b6d4;
    --oms-yellow:#f59e0b;
    --oms-red:#ef4444;

    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
}

/* ================= BASE ================= */
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.18), transparent 30%),
        radial-gradient(circle at 82% 8%, rgba(34,197,94,.12), transparent 30%),
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
        radial-gradient(circle at 88% 14%, rgba(34,197,94,.10), transparent 30%),
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
        radial-gradient(circle at 78% 2%, rgba(34,197,94,.18), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(16,185,129,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #07110d 100%) !important;
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
    background:radial-gradient(circle, rgba(34,197,94,.22), transparent 68%);
    filter:blur(10px);
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

/* ================= PAGE TITLE / ACTIONS ================= */
.page-title-wrap{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-bottom:18px !important;
}

.page-title-box{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.50)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:18px !important;
    padding:16px 20px !important;
    min-width:340px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.page-title{
    font-size:26px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(34,197,94,.24) !important;
}

.top-actions{
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

.btn-soft{
    height:44px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.62) !important;
    padding:0 16px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    transition:.22s ease !important;
}

.btn-soft:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    background:rgba(34,197,94,.16) !important;
    border-color:rgba(34,197,94,.38) !important;
    box-shadow:
        0 18px 38px rgba(0,0,0,.32),
        0 0 30px rgba(34,197,94,.14) !important;
}

/* ================= NOTE BOX ================= */
.note-box{
    background:
        linear-gradient(135deg, rgba(245,158,11,.16), rgba(15,23,42,.46)) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.28) !important;
    padding:13px 15px !important;
    border-radius:14px !important;
    margin-bottom:16px !important;
    font-weight:900 !important;
    box-shadow:0 14px 32px rgba(0,0,0,.20) !important;
}

/* ================= FILTERS ================= */
.filter-mini{
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    margin-bottom:14px !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:18px !important;
    padding:14px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.filter-mini select,
.filter-mini input{
    height:42px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    padding:0 12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    outline:none !important;
    font-weight:800 !important;
    min-width:170px !important;
    box-shadow:none !important;
}

.filter-mini select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.filter-mini select:focus,
.filter-mini input:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(34,197,94,.55) !important;
    box-shadow:0 0 0 3px rgba(34,197,94,.14) !important;
}

.search-row{
    display:grid !important;
    grid-template-columns:1fr 150px !important;
    gap:12px !important;
    margin-bottom:18px !important;
}

.search-wrap{
    position:relative !important;
}

.search-wrap i{
    position:absolute !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#4ade80 !important;
    z-index:2 !important;
}

.search-wrap input{
    width:100% !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px 0 42px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    outline:none !important;
    font-weight:800 !important;
}

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.search-wrap input:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(34,197,94,.55) !important;
    box-shadow:0 0 0 3px rgba(34,197,94,.14) !important;
}

.search-btn{
    height:46px !important;
    border:none !important;
    border-radius:13px !important;
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    font-size:15px !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(34,197,94,.20) !important;
    transition:.22s ease !important;
}

.search-btn:hover{
    transform:translateY(-2px) !important;
}

/* ================= STATS ================= */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(5,1fr) !important;
    gap:14px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    border-radius:18px !important;
    color:#fff !important;
    padding:18px 20px !important;
    display:flex !important;
    flex-direction:column !important;
    justify-content:center !important;
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
    width:70px;
    height:70px;
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
        0 0 48px rgba(34,197,94,.16) !important;
}

.stat-title{
    font-size:14px !important;
    font-weight:900 !important;
    opacity:.95 !important;
    margin-bottom:8px !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
}

.stat-value{
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
}

.card-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.card-green{background:linear-gradient(135deg,#047857,#22c55e) !important;}
.card-orange{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.card-red{background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.card-ghost{
    background:
        radial-gradient(circle at 18% 0%, rgba(34,197,94,.18), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.56), rgba(17,24,39,.42)) !important;
    border:1px solid rgba(34,197,94,.18) !important;
}

/* ================= SECTION / TABLE CARDS ================= */
.section-card{
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
    margin-bottom:18px !important;
    transition:.25s ease !important;
}

.section-card:hover{
    border-color:rgba(34,197,94,.38) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(34,197,94,.12),
        0 0 42px rgba(34,197,94,.14),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.section-head{
    padding:15px 18px !important;
    font-size:17px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    background:rgba(7,11,20,.36) !important;
    letter-spacing:-.01em !important;
}

.table-wrap{
    overflow:auto !important;
}

.report-table{
    width:100% !important;
    min-width:980px !important;
    border-collapse:collapse !important;
    background:transparent !important;
}

.report-table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    padding:15px 14px !important;
    font-size:13px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

.report-table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    vertical-align:middle !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
}

/* White hover remove */
.report-table tbody tr:hover td,
.report-table tbody td:hover{
    background:
        radial-gradient(circle at 18% 0%, rgba(34,197,94,.16), transparent 38%),
        linear-gradient(135deg, rgba(15,80,50,.28), rgba(15,23,42,.34)) !important;
    color:#ffffff !important;
    box-shadow:none !important;
}

.report-table strong{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.report-table .text-center{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

/* Total row */
.total-row td{
    font-weight:900 !important;
    background:
        linear-gradient(135deg, rgba(34,197,94,.12), rgba(124,58,237,.12)) !important;
    color:#fff !important;
}

/* ================= LEDGER TITLE ================= */
.ledger-title-wrap{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    flex-wrap:wrap !important;
    padding:16px 18px 0 18px !important;
}

.ledger-title{
    font-size:18px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
}

.breadcrumb-mini{
    font-size:13px !important;
    color:#4ade80 !important;
    padding:0 18px 13px 18px !important;
    font-weight:800 !important;
}

.payment-entry-btn{
    height:40px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:0 16px !important;
    display:inline-flex !important;
    align-items:center !important;
    gap:8px !important;
    text-decoration:none !important;
    box-shadow:0 14px 28px rgba(34,197,94,.20) !important;
    transition:.22s ease !important;
}

.payment-entry-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* ================= BADGES / PILL ================= */
.balance-pill{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:7px 12px !important;
    border-radius:999px !important;
    font-weight:900 !important;
    font-size:12px !important;
    color:#fff !important;
    min-width:100px !important;
}

.status-red{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.25) !important;
}

.status-green{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

/* ================= ACTION / FOOTER ================= */
.action-btn{
    height:36px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:0 14px !important;
    font-size:13px !important;
    text-decoration:none !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    box-shadow:0 12px 24px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.action-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

.footer-bar{
    padding:14px 18px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    flex-wrap:wrap !important;
    background:rgba(7,11,20,.30) !important;
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.pagination-wrap{
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    flex-wrap:wrap !important;
}

.page-btn{
    height:36px !important;
    min-width:36px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.62) !important;
    border-radius:10px !important;
    padding:0 12px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
    transition:.2s ease !important;
}

.page-btn:hover{
    background:rgba(34,197,94,.16) !important;
    color:#fff !important;
    border-color:rgba(34,197,94,.38) !important;
}

.page-btn.active{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    border-color:rgba(34,197,94,.42) !important;
}

/* ================= PRINT FIX ================= */
@media print{
    .sidebar,
    .topbar,
    .top-actions,
    .filter-mini,
    .search-row,
    .footer-bar,
    .payment-entry-btn{
        display:none !important;
    }

    .main-panel{
        margin-left:0 !important;
        background:#fff !important;
    }

    .content-wrapper{
        background:#fff !important;
        padding:0 !important;
    }

    .section-card,
    .page-title-box{
        background:#fff !important;
        color:#111827 !important;
        box-shadow:none !important;
        border:1px solid #ddd !important;
    }

    .report-table thead th{
        background:#111827 !important;
        color:#fff !important;
    }

    .report-table tbody td{
        background:#fff !important;
        color:#111827 !important;
    }
}

/* ================= RESPONSIVE ================= */
@media(max-width:1200px){
    .stats-grid{
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
    .page-title-wrap{
        align-items:stretch !important;
    }

    .page-title-box{
        min-width:100% !important;
        width:100% !important;
    }

    .top-actions{
        width:100% !important;
        flex-direction:column !important;
    }

    .btn-soft{
        width:100% !important;
    }

    .filter-mini{
        flex-direction:column !important;
    }

    .filter-mini select,
    .filter-mini input{
        width:100% !important;
        min-width:100% !important;
    }

    .search-row{
        grid-template-columns:1fr !important;
    }

    .stats-grid{
        grid-template-columns:1fr !important;
    }

    .ledger-title-wrap{
        align-items:stretch !important;
    }

    .payment-entry-btn{
        width:100% !important;
        justify-content:center !important;
    }
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

            <div class="page-title-wrap">
                <div class="page-title-box">
                    <h1 class="page-title">CREDIT LEDGER REPORT</h1>
                </div>

                <div class="top-actions">
                    <a class="btn-soft" href="?<?= e(build_query_string(['export' => 'csv_customers'])) ?>"><i class="bi bi-box-arrow-up-right"></i> Export Report</a>
                    <button class="btn-soft" type="button" onclick="window.print()"><i class="bi bi-printer-fill"></i> Export PDF</button>
                    <button class="btn-soft" type="button" onclick="window.print()"><i class="bi bi-whatsapp text-success"></i> WhatsApp PDF</button>
                    <button class="btn-soft" type="button" onclick="window.print()"><i class="bi bi-file-earmark"></i></button>
                    <button class="btn-soft" type="button" onclick="window.print()"><i class="bi bi-layout-text-window"></i></button>
                </div>
            </div>

            <?php if ($selected_branch <= 0): ?>
                <div class="note-box">
                    Please select a branch first, then search customer ledger.
                </div>
            <?php endif; ?>

            <form method="get">
                <div class="filter-mini">
                    <?php if($logged_role_id == 1): ?>
                        <select name="client_id" onchange="this.form.submit()">
                            <option value="">Select Client</option>
                            <?php foreach($clients as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $selected_client == (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['business_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="client_id" value="<?= (int)$selected_client ?>">
                    <?php endif; ?>

                    <select name="branch_id" onchange="this.form.submit()">
                        <option value="">Select Branch</option>
                        <?php foreach($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $selected_branch == (int)$b['id'] ? 'selected' : '' ?>>
                                <?= e($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="date" name="start_date" value="<?= e($start_date) ?>">
                    <input type="date" name="end_date" value="<?= e($end_date) ?>">
                </div>

                <div class="search-row">
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search_customer" value="<?= e($search_customer) ?>" placeholder="Search customer by name, phone or ID...">
                    </div>
                    <button class="search-btn" type="submit">Search</button>
                </div>
            </form>

            <div class="stats-grid">
                <div class="stat-card card-blue">
                    <div class="stat-title"><i class="bi bi-wallet2"></i> Total Due</div>
                    <div class="stat-value">PKR <?= number_format($totalDue, 0) ?></div>
                </div>
                <div class="stat-card card-green">
                    <div class="stat-title"><i class="bi bi-person-fill"></i> Payment Today</div>
                    <div class="stat-value">PKR <?= number_format($paymentToday, 0) ?></div>
                </div>
                <div class="stat-card card-orange">
                    <div class="stat-title"><i class="bi bi-cash-stack"></i> Total Receivable</div>
                    <div class="stat-value">PKR <?= number_format($totalReceivable, 0) ?></div>
                </div>
                <div class="stat-card card-red">
                    <div class="stat-title"><i class="bi bi-exclamation-circle-fill"></i> Overdue Balance</div>
                    <div class="stat-value">PKR <?= number_format($overdueBalance, 0) ?></div>
                </div>
                <div class="stat-card card-ghost"></div>
            </div>

            <div class="section-card">
                <div class="section-head">Credit Customer Details</div>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer Name</th>
                                <th>Total Due</th>
                                <th>Last Payment</th>
                                <th>Due Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($customerRows)): ?>
                                <?php foreach($customerRows as $index => $row): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= e($row['customer_name']) ?></td>
                                        <td><strong>PKR <?= number_format((float)$row['balance'], 0) ?></strong></td>
                                        <td><?= !empty($row['last_payment_date']) ? date('d/m/Y', strtotime($row['last_payment_date'])) : '—' ?></td>
                                        <td><?= !empty($row['due_date']) ? date('d/m/Y', strtotime($row['due_date'])) : '—' ?></td>
                                        <td>
                                            <a class="action-btn" href="?<?= e(build_query_string([
    'customer_id' => (int)$row['customer_id'],
    'export' => null
])) ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No customer ledger data found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>Showing 1 to <?= count($customerRows) ?> of <?= count($customerRows) ?> entries</div>
                    <div class="pagination-wrap">
                        <button class="page-btn" type="button">&lt; Prev</button>
                        <button class="page-btn active" type="button">1</button>
                        <button class="page-btn" type="button">Next &gt;</button>
                    </div>
                </div>
            </div>

            <div class="section-card">
                <div class="ledger-title-wrap">
                    <div class="ledger-title">Customer Ledger: <?= strtoupper(e($selectedCustomerName ?: 'N/A')) ?></div>
                    <?php if($selected_customer_id > 0): ?>
                        <a class="payment-entry-btn" href="payments.php?customer_id=<?= (int)$selected_customer_id ?>&branch_id=<?= (int)$selected_branch ?>">
                            <i class="bi bi-check-circle-fill"></i> Payment Today Entry
                        </a>
                    <?php endif; ?>
                </div>
                <div class="breadcrumb-mini">Credit Ledger Report / <?= e($selectedCustomerName ?: '-') ?></div>

                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Invoice # / Description</th>
                                <th>Type</th>
                                <th>Due Amount</th>
                                <th>Payment</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($ledgerRows)): ?>
                                <?php foreach($ledgerRows as $index => $row): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= !empty($row['row_date']) ? date('d-m-Y', strtotime($row['row_date'])) : '—' ?></td>
                                        <td><?= e($row['description_text']) ?></td>
                                        <td><?= e($row['row_type']) ?></td>
                                        <td>PKR <?= number_format((float)$row['due_amount'], 0) ?></td>
                                        <td>PKR <?= number_format((float)$row['payment_amount'], 0) ?></td>
                                        <td>
                                            <span class="balance-pill <?= e($row['status_class']) ?>">
                                                PKR <?= number_format((float)$row['balance'], 0) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="balance-pill <?= e($row['status_class']) ?>">
                                                <?= e($row['status_text']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <tr class="total-row">
                                    <td></td>
                                    <td colspan="3">TOTAL:</td>
                                    <td>PKR <?= number_format($ledgerTotalDue, 0) ?></td>
                                    <td>PKR <?= number_format($ledgerTotalPayment, 0) ?></td>
                                    <td>PKR <?= number_format($ledgerTotalBalance, 0) ?></td>
                                    <td>
                                        <span class="balance-pill <?= $ledgerTotalBalance > 0 ? 'status-red' : 'status-green' ?>">
                                            <?= $ledgerTotalBalance > 0 ? 'Due' : 'Clear' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center">No ledger entries found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div></div>
                    <div class="pagination-wrap">
                        <a class="btn-soft" href="?<?= e(build_query_string(['export' => 'csv_ledger'])) ?>"><i class="bi bi-download"></i> Export Ledger CSV</a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
</body>
</html>