<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_role_id   = $_SESSION['role_id'] ?? 0;
$logged_client_id = $_SESSION['client_id'] ?? 0;

/* ================= FILTERS ================= */
$filter_client = $_POST['client_id'] ?? '';
$filter_branch = $_POST['branch_id'] ?? '';
$filter_from   = $_POST['from_date'] ?? date('Y-m-d');
$filter_to     = $_POST['to_date'] ?? date('Y-m-d');
$search        = trim($_POST['search'] ?? '');

/* ================= BUILD WHERE ================= */
$where  = " WHERE 1=1 ";
$params = [];
$types  = '';

if ($logged_role_id != 1) {
    $where .= " AND s.client_id = ? ";
    $params[] = $logged_client_id;
    $types .= 's';
} elseif ($filter_client !== '') {
    $where .= " AND s.client_id = ? ";
    $params[] = $filter_client;
    $types .= 's';
}

if ($filter_branch !== '') {
    $where .= " AND s.branch_id = ? ";
    $params[] = $filter_branch;
    $types .= 's';
}

if ($filter_from !== '') {
    $where .= " AND DATE(s.created_at) >= ? ";
    $params[] = $filter_from;
    $types .= 's';
}

if ($filter_to !== '') {
    $where .= " AND DATE(s.created_at) <= ? ";
    $params[] = $filter_to;
    $types .= 's';
}

if ($search !== '') {
    $where .= " AND (b.name LIKE ? OR DATE(s.created_at) LIKE ?) ";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $types .= 'ss';
}

/* ================= FETCH DATA ================= */
$sql = "
    SELECT 
        DATE(s.created_at) AS sale_date,
        COALESCE(b.name, 'N/A') AS branch_name,
        COUNT(DISTINCT s.id) AS invoices,
        COALESCE(SUM(s.total) + SUM(s.tax) - SUM(s.discount), 0) AS total_amount
    FROM sales s
    LEFT JOIN branches b ON b.id = s.branch_id
    $where
    GROUP BY DATE(s.created_at), s.branch_id, b.name
    ORDER BY sale_date DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

/* ================= FILE DOWNLOAD ================= */
$filename = 'daily_sales_report_' . date('Y-m-d_H-i-s') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

/* UTF-8 BOM for Excel */
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

/* Report Heading */
fputcsv($output, ['Daily Sales Report']);
fputcsv($output, ['Generated At', date('Y-m-d h:i:s A')]);
fputcsv($output, ['From Date', $filter_from ?: '']);
fputcsv($output, ['To Date', $filter_to ?: '']);
fputcsv($output, ['Search', $search ?: '']);
fputcsv($output, []);

/* Table Header */
fputcsv($output, ['Date', 'Branch', 'Invoices', 'Total Amount']);

$grandInvoices = 0;
$grandTotal    = 0;

while ($row = $result->fetch_assoc()) {
    $saleDate    = $row['sale_date'] ?? '';
    $branchName  = $row['branch_name'] ?? 'N/A';
    $invoices    = (int)($row['invoices'] ?? 0);
    $totalAmount = (float)($row['total_amount'] ?? 0);

    $grandInvoices += $invoices;
    $grandTotal    += $totalAmount;

    fputcsv($output, [
        $saleDate,
        $branchName,
        $invoices,
        number_format($totalAmount, 2, '.', '')
    ]);
}

/* Footer Totals */
fputcsv($output, []);
fputcsv($output, ['Grand Total', '', $grandInvoices, number_format($grandTotal, 2, '.', '')]);

fclose($output);
$stmt->close();
exit;
?>