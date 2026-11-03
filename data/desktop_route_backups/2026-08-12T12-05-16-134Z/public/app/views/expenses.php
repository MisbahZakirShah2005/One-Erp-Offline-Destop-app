<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

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

function redirect_self(): void
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function expenseStatusClass(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === 'approved') return 'status-completed';
    if ($status === 'pending') return 'status-pending';
    return 'status-approval';
}

function nextExpenseCode(mysqli $conn): string
{
    $res = $conn->query("SELECT expense_code FROM expenses ORDER BY id DESC LIMIT 1");
    $last = $res ? $res->fetch_assoc()['expense_code'] ?? null : null;

    if (!$last) {
        return 'EXP-000001';
    }

    $num = (int)str_replace('EXP-', '', $last);
    $num++;

    return 'EXP-' . str_pad((string)$num, 6, '0', STR_PAD_LEFT);
}

/* ================= STATIC OPTIONS ================= */
$categoryOptions = [
    'Utilities',
    'Salaries',
    'Fuel',
    'Office Supplies',
    'Repair & Maintenance',
    'Bank Transfer',
    'Marketing',
    'Rent',
    'Travel',
    'Miscellaneous'
];

$paymentMethods = [
    'Cash',
    'Bank Transfer',
    'Cheque',
    'JazzCash',
    'EasyPaisa'
];

$statusOptions = [
    'Approved',
    'Pending',
    'Pending Approval'
];

/* ================= CLIENTS ================= */
$clientList = [];
if ($logged_role_id === 1) {
    $clientRes = $conn->query("SELECT id, business_name FROM clients ORDER BY business_name ASC");
    if ($clientRes) {
        while ($row = $clientRes->fetch_assoc()) {
            $clientList[] = $row;
        }
    }
}

/* ================= BRANCHES ================= */
$branchList = [];
if ($logged_role_id === 1) {
    $branchQuery = "
        SELECT 
            b.id,
            b.client_id,
            b.name AS branch_name,
            b.address,
            c.business_name
        FROM branches b
        LEFT JOIN clients c ON c.id = b.client_id
        ORDER BY b.name ASC
    ";
    $branchRes = $conn->query($branchQuery);
} else {
    $stmt = $conn->prepare("
        SELECT id, client_id, name AS branch_name, address
        FROM branches
        WHERE client_id = ?
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $logged_client_id);
    $stmt->execute();
    $branchRes = $stmt->get_result();
    $stmt->close();
}

if ($branchRes) {
    while ($row = $branchRes->fetch_assoc()) {
        $branchList[] = $row;
    }
}

/* ================= HANDLE ADD EXPENSE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    $expense_id      = (int)($_POST['expense_id'] ?? 0);
    $branch_id       = (int)($_POST['branch_id'] ?? 0);
    $amount          = (float)($_POST['amount'] ?? 0);
    $description     = trim($_POST['description'] ?? '');
    $category        = trim($_POST['category'] ?? '');
    $payment_method  = trim($_POST['payment_method'] ?? '');
    $status          = trim($_POST['status'] ?? 'Pending');
    $expense_date    = trim($_POST['expense_date'] ?? '');
    $expense_code    = trim($_POST['expense_code'] ?? '');
    $client_id       = ($logged_role_id === 1) ? (int)($_POST['client_id'] ?? 0) : $logged_client_id;

    if ($expense_code === '') {
        $expense_code = nextExpenseCode($conn);
    }

    if (
        $client_id <= 0 ||
        $branch_id <= 0 ||
        $amount <= 0 ||
        $description === '' ||
        $category === '' ||
        $payment_method === '' ||
        $status === '' ||
        $expense_date === ''
    ) {
        $_SESSION['error'] = 'Please fill all required fields correctly.';
        redirect_self();
    }

    if ($logged_role_id !== 1) {
        $stmt = $conn->prepare("SELECT id FROM branches WHERE id = ? AND client_id = ? LIMIT 1");
        $stmt->bind_param("ii", $branch_id, $logged_client_id);
        $stmt->execute();
        $check = $stmt->get_result();
        $stmt->close();

        if (!$check || $check->num_rows === 0) {
            $_SESSION['error'] = 'Invalid branch selected.';
            redirect_self();
        }
    }

    if ($expense_id > 0) {
        if ($logged_role_id === 1) {
            $stmt = $conn->prepare("
                UPDATE expenses
                SET client_id = ?, branch_id = ?, amount = ?, description = ?, category = ?, payment_method = ?, status = ?, expense_date = ?, expense_code = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                "iidssssssi",
                $client_id,
                $branch_id,
                $amount,
                $description,
                $category,
                $payment_method,
                $status,
                $expense_date,
                $expense_code,
                $expense_id
            );
        } else {
            $stmt = $conn->prepare("
                UPDATE expenses
                SET branch_id = ?, amount = ?, description = ?, category = ?, payment_method = ?, status = ?, expense_date = ?, expense_code = ?
                WHERE id = ? AND client_id = ?
            ");
            $stmt->bind_param(
                "idssssssii",
                $branch_id,
                $amount,
                $description,
                $category,
                $payment_method,
                $status,
                $expense_date,
                $expense_code,
                $expense_id,
                $logged_client_id
            );
        }

        if ($stmt->execute()) {
            $_SESSION['success'] = 'Expense updated successfully.';
        } else {
            $_SESSION['error'] = 'Update failed: ' . $stmt->error;
        }
        $stmt->close();
        redirect_self();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO expenses (
                client_id, branch_id, amount, description, category,
                payment_method, status, expense_date, expense_code, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            "iidssssss",
            $client_id,
            $branch_id,
            $amount,
            $description,
            $category,
            $payment_method,
            $status,
            $expense_date,
            $expense_code
        );

        if ($stmt->execute()) {
            $_SESSION['success'] = 'Expense added successfully.';
        } else {
            $_SESSION['error'] = 'Insert failed: ' . $stmt->error;
        }
        $stmt->close();
        redirect_self();
    }
}

/* ================= HANDLE DELETE ================= */
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $expense_id = (int)$_GET['delete'];

    if ($logged_role_id === 1) {
        $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->bind_param("i", $expense_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $expense_id, $logged_client_id);
    }

    if ($stmt->execute()) {
        $_SESSION['success'] = 'Expense deleted successfully.';
    } else {
        $_SESSION['error'] = 'Delete failed: ' . $stmt->error;
    }

    $stmt->close();
    redirect_self();
}

/* ================= FILTERS ================= */
$search            = trim($_GET['search'] ?? '');
$statusFilter      = trim($_GET['status'] ?? '');
$branchFilter      = trim($_GET['branch_id'] ?? '');
$methodFilter      = trim($_GET['payment_method'] ?? '');
$dateFilter        = trim($_GET['date_filter'] ?? '');

/* ================= FETCH EXPENSES ================= */
$sql = "
    SELECT 
        e.*,
        b.name AS branch_name
";

if ($logged_role_id === 1) {
    $sql .= ", c.business_name";
}

$sql .= "
    FROM expenses e
    LEFT JOIN branches b ON e.branch_id = b.id
";

if ($logged_role_id === 1) {
    $sql .= " LEFT JOIN clients c ON e.client_id = c.id";
}

$sql .= " WHERE 1=1 ";

$params = [];
$types  = '';

if ($logged_role_id !== 1) {
    $sql .= " AND e.client_id = ? ";
    $params[] = $logged_client_id;
    $types .= 'i';
}

if ($search !== '') {
    $sql .= " AND (
        e.expense_code LIKE ?
        OR e.description LIKE ?
        OR e.category LIKE ?
        OR e.payment_method LIKE ?
        OR b.name LIKE ?
    ) ";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

if ($statusFilter !== '') {
    $sql .= " AND e.status = ? ";
    $params[] = $statusFilter;
    $types .= 's';
}

if ($branchFilter !== '' && ctype_digit($branchFilter)) {
    $sql .= " AND e.branch_id = ? ";
    $params[] = (int)$branchFilter;
    $types .= 'i';
}

if ($methodFilter !== '') {
    $sql .= " AND e.payment_method = ? ";
    $params[] = $methodFilter;
    $types .= 's';
}

if ($dateFilter === 'today') {
    $sql .= " AND DATE(e.expense_date) = CURDATE() ";
} elseif ($dateFilter === 'this_month') {
    $sql .= " AND MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE()) ";
} elseif ($dateFilter === 'last_30_days') {
    $sql .= " AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ";
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC ";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$expenses = [];
while ($row = $res->fetch_assoc()) {
    $expenses[] = $row;
}
$stmt->close();

/* ================= STATS ================= */
$totalExpenses      = count($expenses);
$todayExpense       = 0;
$thisMonthExpense   = 0;
$pendingApprovalAmt = 0;

foreach ($expenses as $expense) {
    $amount = (float)$expense['amount'];
    $date   = !empty($expense['expense_date']) ? date('Y-m-d', strtotime($expense['expense_date'])) : '';
    $month  = !empty($expense['expense_date']) ? date('Y-m', strtotime($expense['expense_date'])) : '';

    if ($date === date('Y-m-d')) {
        $todayExpense += $amount;
    }

    if ($month === date('Y-m')) {
        $thisMonthExpense += $amount;
    }

    if (strtolower((string)$expense['status']) === 'pending' || strtolower((string)$expense['status']) === 'pending approval') {
        $pendingApprovalAmt += $amount;
    }
}

$totalAmount = 0;
foreach ($expenses as $expense) {
    $totalAmount += (float)$expense['amount'];
}

$nextCode = nextExpenseCode($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expenses - ONE ERP</title>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

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
    .expense-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
}

.expense-btn{
    width:38px;
    height:38px;
    border:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

.expense-btn:hover{
    transform:translateY(-2px);
}

.expense-btn i{
    font-size:15px;
}

/* EDIT */
.expense-edit{
    background:#fff4d6;
    color:#e0a100;
}
.expense-edit:hover{
    background:#e0a100;
    color:#fff;
}

/* DELETE */
.expense-delete{
    background:#ffe3e3;
    color:#ef4444;
}
.expense-delete:hover{
    background:#ef4444;
    color:#fff;
}

/* MORE */
.expense-more{
    background:#f3f5fa;
    color:#4b566d;
}
.expense-more:hover{
    background:#1a1f2e;
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
    

/* =========================================================
   FINAL EXPENSES POPUP BLACK + GOLD + WHITE THEME
   Add/Edit Expense Modal
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

/* Modal size */
#expenseModal .modal-dialog{
    max-width:min(760px,94vw) !important;
}

/* Main popup container */
#expenseModal .modal-content,
#expenseModal .exp-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.62) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

/* Header */
#expenseModal .modal-header,
#expenseModal .exp-modal-header{
    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
    min-height:76px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#expenseModal .modal-title,
#expenseModal .exp-modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#expenseModal .modal-title::before,
#expenseModal .exp-modal-title::before{
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
#expenseModal .exp-close{
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

#expenseModal .exp-close::before,
#expenseModal .exp-close::after{
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

#expenseModal .exp-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#expenseModal .exp-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#expenseModal .exp-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px) !important;
}

/* Body */
#expenseModal .modal-body,
#expenseModal .exp-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#expenseModal .exp-modal-body::-webkit-scrollbar{
    width:7px !important;
}

#expenseModal .exp-modal-body::-webkit-scrollbar-track{
    background:#171c28 !important;
    border-radius:999px !important;
}

#expenseModal .exp-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c !important;
    border-radius:999px !important;
}

/* Footer */
#expenseModal .modal-footer,
#expenseModal .exp-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.28) !important;
    padding:18px 24px 22px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:12px !important;
}

/* Labels */
#expenseModal .dark-label,
#expenseModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#expenseModal .req{
    color:#f5c742 !important;
}

/* Inputs / selects / textarea */
#expenseModal .dark-input,
#expenseModal .dark-select,
#expenseModal .dark-textarea,
#expenseModal input[type="text"],
#expenseModal input[type="number"],
#expenseModal input[type="date"],
#expenseModal select,
#expenseModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#expenseModal .dark-input,
#expenseModal .dark-select,
#expenseModal input[type="text"],
#expenseModal input[type="number"],
#expenseModal input[type="date"],
#expenseModal select{
    min-height:50px !important;
    height:50px !important;
    padding:10px 16px !important;
}

#expenseModal .dark-textarea,
#expenseModal textarea{
    min-height:112px !important;
    height:auto !important;
    padding:14px 16px !important;
    resize:vertical !important;
}

#expenseModal input[readonly]{
    background:#f6f7fb !important;
    color:#4b5563 !important;
    font-weight:800 !important;
}

#expenseModal input::placeholder,
#expenseModal textarea::placeholder{
    color:#8993a5 !important;
}

#expenseModal .dark-input:focus,
#expenseModal .dark-select:focus,
#expenseModal .dark-textarea:focus,
#expenseModal input:focus,
#expenseModal select:focus,
#expenseModal textarea:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Footer buttons */
#expenseModal .cancel-btn{
    height:44px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#expenseModal .cancel-btn:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#expenseModal .save-btn{
    height:44px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#expenseModal .save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px) !important;
}

/* Mobile */
@media (max-width:768px){
    #expenseModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #expenseModal .modal-title,
    #expenseModal .exp-modal-title{
        font-size:18px !important;
    }

    #expenseModal .exp-close{
        width:42px !important;
        height:42px !important;
    }

    #expenseModal .exp-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #expenseModal .cancel-btn,
    #expenseModal .save-btn{
        width:100% !important;
    }
}



/* =====================================================
   EXPENSES.PHP - DARK PURPLE GLASS HOVER THEME
   Page + Add/Edit Popup Compact Size
   Backend / PHP / JS untouched
===================================================== */
:root{
    --erp-bg:#070b14;
    --erp-panel:rgba(15,23,42,.70);
    --erp-panel-2:rgba(17,24,39,.56);
    --erp-text:#f8fafc;
    --erp-muted:#94a3b8;
    --erp-border:rgba(148,163,184,.18);
    --erp-purple:#6d4cff;
    --erp-purple-2:#7c3aed;
    --erp-blue:#3b82f6;
    --erp-green:#22c55e;
    --erp-yellow:#f59e0b;
    --erp-red:#ef4444;
}

html,body{
    min-height:100% !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:var(--erp-text) !important;
    font-family:"Inter","Segoe UI",Arial,sans-serif !important;
}

.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    background:transparent !important;
}

.sidebar{
    width:250px !important;
    flex-shrink:0 !important;
    background:transparent !important;
    overflow:visible !important;
}

.main-panel{
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

.topbar{
    height:70px !important;
    min-height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    color:#fff !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
}

.content-wrapper{
    position:relative !important;
    flex:1 !important;
    padding:24px 26px !important;
    overflow:visible !important;
    min-height:calc(100vh - 70px) !important;
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
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.42;
    z-index:0;
}

.content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

.page-header{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-bottom:18px !important;
}

.page-title{
    font-size:30px !important;
    line-height:1.15 !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    letter-spacing:-.03em !important;
    margin:0 !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

.btn-yellow,
.btn-green,
.filter-btn,
.reset-btn,
.save-btn,
.cancel-btn,
.bulk-delete-btn,
.pager-btn{
    transition:all .22s ease !important;
}

.btn-yellow,
.btn-green{
    min-height:50px !important;
    border:none !important;
    border-radius:14px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 16px 34px rgba(124,58,237,.25),0 0 28px rgba(124,58,237,.14) !important;
}

.btn-yellow:hover,
.btn-green:hover,
.filter-btn:hover,
.reset-btn:hover,
.save-btn:hover,
.cancel-btn:hover,
.bulk-delete-btn:hover,
.pager-btn:hover{
    transform:translateY(-2px) !important;
}

.alert-wrap .alert{
    border-radius:15px !important;
    border:1px solid rgba(255,255,255,.14) !important;
    font-weight:800 !important;
    box-shadow:0 14px 34px rgba(0,0,0,.24) !important;
}

.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:116px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.30) !important;
    isolation:isolate !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat-card::after{
    content:"" !important;
    position:absolute !important;
    right:14px !important;
    top:14px !important;
    width:76px !important;
    height:76px !important;
    border-radius:20px !important;
    background:rgba(255,255,255,.10) !important;
    z-index:-1 !important;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.24) !important;
    filter:saturate(1.08) !important;
    box-shadow:0 26px 65px rgba(0,0,0,.46),0 0 48px rgba(124,58,237,.20) !important;
}

.stat-red{background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.stat-gold{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.stat-orange{background:linear-gradient(135deg,#7c3aed,#f59e0b) !important;}
.stat-green{background:linear-gradient(135deg,#047857,#22c55e) !important;}

.stat-title,
.stat-sub{
    color:rgba(255,255,255,.94) !important;
    font-weight:900 !important;
}

.stat-value{
    color:#fff !important;
    font-weight:900 !important;
    text-shadow:0 10px 30px rgba(0,0,0,.25) !important;
}

.pending-badge{
    background:rgba(245,158,11,.18) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.30) !important;
    box-shadow:0 8px 22px rgba(0,0,0,.22) !important;
}

.filter-card,
.table-card,
.approval-card{
    background:radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    box-shadow:0 18px 48px rgba(0,0,0,.30),inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.filter-card{
    padding:14px 16px !important;
    margin-bottom:18px !important;
}

.filter-card form{
    display:grid !important;
    grid-template-columns:1fr !important;
    gap:10px !important;
}

.filter-row{
    display:grid !important;
    grid-template-columns:minmax(150px,1fr) minmax(150px,1fr) minmax(150px,1fr) minmax(150px,1fr) 110px 110px !important;
    gap:10px !important;
    margin-bottom:0 !important;
    align-items:center !important;
}

.search-row{
    display:grid !important;
    grid-template-columns:1fr !important;
    gap:10px !important;
}

.erp-select,
.erp-input,
.dark-input,
.dark-select,
.dark-textarea{
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    outline:none !important;
    font-weight:800 !important;
    box-shadow:0 8px 22px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.04) !important;
}

.erp-select,
.erp-input{
    height:46px !important;
    padding:0 14px !important;
}

.erp-select option,
.dark-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.erp-input::placeholder,
.dark-input::placeholder,
.dark-textarea::placeholder{
    color:#94a3b8 !important;
}

.erp-select:focus,
.erp-input:focus,
.dark-input:focus,
.dark-select:focus,
.dark-textarea:focus{
    background:rgba(17,24,39,.92) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.16),0 12px 26px rgba(0,0,0,.24) !important;
}

.filter-btn,
.reset-btn{
    height:46px !important;
    width:100% !important;
    border-radius:13px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
}

.filter-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
}

.reset-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.reset-btn:hover{
    background:rgba(124,58,237,.20) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.45) !important;
}

.table-card{
    padding:0 !important;
    overflow:hidden !important;
}

.table-wrap{
    overflow:auto !important;
}

.erp-table{
    width:100% !important;
    min-width:1280px !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
}

.erp-table thead th{
    background:rgba(7,11,20,.76) !important;
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

.erp-table tbody td{
    background:rgba(15,23,42,.30) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    padding:15px 14px !important;
    vertical-align:middle !important;
    font-size:14px !important;
    font-weight:700 !important;
    white-space:nowrap !important;
}

.erp-table tbody tr:hover td{
    background:radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color:#fff !important;
}

.erp-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.expense-icon{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 8px 20px rgba(124,58,237,.26) !important;
}

.status-pill{
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.status-completed{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

.status-pending{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}

.status-approval{
    background:rgba(124,58,237,.18) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(124,58,237,.28) !important;
}

.expense-actions{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
}

.expense-btn{
    width:40px !important;
    height:40px !important;
    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
    transition:all .20s ease !important;
    text-decoration:none !important;
}

.expense-btn:hover{
    transform:translateY(-2px) !important;
}

.expense-edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.expense-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

.expense-more{
    background:rgba(124,58,237,.18) !important;
    color:#a78bfa !important;
    border-color:rgba(124,58,237,.28) !important;
}

.approval-card{
    margin-top:16px !important;
}

.approval-head{
    background:rgba(7,11,20,.36) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

.approval-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.approval-body{
    background:rgba(15,23,42,.16) !important;
}

.approval-legend,
.empty-state{
    color:#cbd5e1 !important;
    font-weight:800 !important;
}

/* ================= EXPENSE POPUP - COMPACT PURPLE GLASS ================= */
body.modal-open .modal-backdrop.show,
.modal-backdrop.show{
    opacity:.58 !important;
    background:#000 !important;
}

#expenseModal .modal-dialog,
#expenseModal .modal-dialog.modal-xl-custom{
    width:760px !important;
    max-width:760px !important;
    margin:18px auto !important;
}

#expenseModal .modal-content,
#expenseModal .exp-modal-content{
    max-height:84vh !important;
    background:radial-gradient(circle at 18% 0%, rgba(124,58,237,.26), transparent 38%),linear-gradient(135deg, rgba(15,23,42,.92), rgba(17,24,39,.78)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.34) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 28px 75px rgba(0,0,0,.60),0 0 50px rgba(124,58,237,.18),inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
}

#expenseModal .exp-modal-header,
#expenseModal .modal-header{
    min-height:54px !important;
    padding:12px 18px !important;
    background:rgba(7,11,20,.36) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

#expenseModal .exp-modal-title,
#expenseModal .modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
    margin:0 !important;
}

#expenseModal .exp-modal-title::before,
#expenseModal .modal-title::before{
    background:#7c3aed !important;
    box-shadow:0 0 0 5px rgba(124,58,237,.17) !important;
}

#expenseModal .exp-close{
    width:36px !important;
    height:36px !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.20) !important;
    color:transparent !important;
    font-size:0 !important;
    padding:0 !important;
    position:relative !important;
}

#expenseModal .exp-close::before,
#expenseModal .exp-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:16px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#expenseModal .exp-close::before{transform:translate(-50%,-50%) rotate(45deg) !important;}
#expenseModal .exp-close::after{transform:translate(-50%,-50%) rotate(-45deg) !important;}
#expenseModal .exp-close:hover{background:rgba(239,68,68,.24) !important;}

#expenseModal .exp-modal-body,
#expenseModal .modal-body{
    padding:12px 18px !important;
    max-height:62vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    background:rgba(15,23,42,.24) !important;
}

#expenseModal .row{
    --bs-gutter-x:14px !important;
    --bs-gutter-y:8px !important;
}

#expenseModal .mb-3{
    margin-bottom:10px !important;
}

#expenseModal .dark-label,
#expenseModal label{
    color:#e5e7eb !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#expenseModal .req{
    color:#fbbf24 !important;
}

#expenseModal .dark-input,
#expenseModal .dark-select,
#expenseModal input[type="text"],
#expenseModal input[type="number"],
#expenseModal input[type="date"],
#expenseModal select{
    height:38px !important;
    min-height:38px !important;
    padding:6px 12px !important;
    font-size:13px !important;
    font-weight:700 !important;
    border-radius:9px !important;
    background:rgba(255,255,255,.94) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}

#expenseModal .dark-textarea,
#expenseModal textarea{
    min-height:58px !important;
    height:58px !important;
    padding:8px 12px !important;
    font-size:13px !important;
    border-radius:9px !important;
    background:rgba(255,255,255,.94) !important;
    color:#111827 !important;
}

#expenseModal input[readonly]{
    background:#eef2ff !important;
    color:#4b5563 !important;
}

#expenseModal input:focus,
#expenseModal select:focus,
#expenseModal textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.20),0 8px 18px rgba(0,0,0,.18) !important;
    outline:none !important;
}

#expenseModal .exp-modal-footer,
#expenseModal .modal-footer{
    padding:10px 18px !important;
    min-height:52px !important;
    margin-top:0 !important;
    background:rgba(7,11,20,.36) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#expenseModal .cancel-btn,
#expenseModal .save-btn{
    height:38px !important;
    min-width:105px !important;
    font-size:13px !important;
    border-radius:9px !important;
    font-weight:900 !important;
}

#expenseModal .cancel-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

#expenseModal .save-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
    box-shadow:0 12px 26px rgba(124,58,237,.24) !important;
}

@media(max-width:1200px){
    .stats-grid{grid-template-columns:repeat(2,1fr) !important;}
    .filter-row{grid-template-columns:repeat(3,1fr) !important;}
}

@media(max-width:900px){
    #expenseModal .modal-dialog,
    #expenseModal .modal-dialog.modal-xl-custom{
        width:calc(100% - 24px) !important;
        max-width:calc(100% - 24px) !important;
        margin:12px auto !important;
    }
    #expenseModal .exp-modal-body,
    #expenseModal .modal-body{max-height:66vh !important;}
}

@media(max-width:768px){
    .stats-grid{grid-template-columns:1fr !important;}
    .filter-row{grid-template-columns:1fr !important;}
    .page-header{flex-direction:column !important;align-items:stretch !important;}
    .btn-yellow,.btn-green{width:100% !important;}
    #expenseModal .exp-modal-footer,
    #expenseModal .modal-footer{flex-direction:column !important;align-items:stretch !important;}
    #expenseModal .cancel-btn,
    #expenseModal .save-btn{width:100% !important;}
}

/* =========================================================
   EXPENSES PAGE - FINAL LIGHT MODE
   Is block ko final
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   MAIN PAGE
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 52%,
            #faf7ff 100%
        ) !important;
}

/* Topbar */
html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    color:#0f172a !important;
    border-bottom:1px solid #dce4ef !important;
    box-shadow:0 5px 18px rgba(15,23,42,.06) !important;
}

/* Main content background */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 80% 4%,
            rgba(59,130,246,.05),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 48%,
            #faf7ff 76%,
            #f8fafc 100%
        ) !important;

    color:#0f172a !important;
}

/* Dark grid ko light karein */
html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ) !important;

    opacity:.5 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Add Expense button */
html[data-pos-theme="light"] body .btn-yellow{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 12px 26px rgba(124,58,237,.22) !important;
}

html[data-pos-theme="light"] body .btn-yellow:hover{
    color:#ffffff !important;
}

/* =========================================================
   ALERTS
========================================================= */

html[data-pos-theme="light"] body .alert-wrap .alert{
    box-shadow:0 10px 24px rgba(15,23,42,.07) !important;
}

html[data-pos-theme="light"] body .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;
    border:1px solid #fecdd3 !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.24) !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .stat-title,
html[data-pos-theme="light"] body .stat-card .stat-sub,
html[data-pos-theme="light"] body .stat-card .stat-value{
    color:#ffffff !important;
    opacity:1 !important;
}

/* Card colors preserve */
html[data-pos-theme="light"] body .stat-red{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
}

html[data-pos-theme="light"] body .stat-gold{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
}

html[data-pos-theme="light"] body .stat-orange{
    background:linear-gradient(135deg,#7c3aed,#f59e0b) !important;
}

html[data-pos-theme="light"] body .stat-green{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
}

html[data-pos-theme="light"] body .pending-badge{
    background:#fef3c7 !important;
    color:#92400e !important;
    border:1px solid #fde68a !important;
    box-shadow:none !important;
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
        0 12px 28px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .filter-card:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 34px rgba(15,23,42,.09) !important;
}

/* Filter/search fields */
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

html[data-pos-theme="light"] body .erp-select:hover,
html[data-pos-theme="light"] body .erp-input:hover{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] body .erp-select:focus,
html[data-pos-theme="light"] body .erp-input:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .erp-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Filter button */
html[data-pos-theme="light"] body .filter-btn{
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

/* Reset button */
html[data-pos-theme="light"] body .reset-btn{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .reset-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   EXPENSE TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 36px rgba(15,23,42,.09) !important;
}

/* Bootstrap table dark variables reset */
html[data-pos-theme="light"] body .erp-table,
html[data-pos-theme="light"] body .erp-table thead,
html[data-pos-theme="light"] body .erp-table tbody,
html[data-pos-theme="light"] body .erp-table tr,
html[data-pos-theme="light"] body .erp-table th,
html[data-pos-theme="light"] body .erp-table td{
    --bs-table-bg:transparent !important;
    --bs-table-color:#1e293b !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
    --bs-table-accent-bg:transparent !important;
}

/* Table header */
html[data-pos-theme="light"] body .erp-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-right:1px solid #e2e8f0 !important;
    border-bottom:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

/* Normal rows */
html[data-pos-theme="light"] body .erp-table tbody,
html[data-pos-theme="light"] body .erp-table tbody tr,
html[data-pos-theme="light"] body .erp-table tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-bottom-color:#e8edf4 !important;

    box-shadow:none !important;
}

/* Row hover */
html[data-pos-theme="light"] body .erp-table tbody tr:hover td,
html[data-pos-theme="light"] body .erp-table tbody td:hover{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;

    color:#0f172a !important;
    box-shadow:none !important;
}

/* Empty table */
html[data-pos-theme="light"] body .erp-table tbody td.text-center,
html[data-pos-theme="light"] body .erp-table tbody td.py-4,
html[data-pos-theme="light"] body .erp-table tbody td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Checkbox */
html[data-pos-theme="light"] body .erp-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Expense icon */
html[data-pos-theme="light"] body .expense-icon{
    background:#f3efff !important;
    color:#6d28d9 !important;
    border:1px solid #c4b5fd !important;
    box-shadow:none !important;
}

/* =========================================================
   STATUS PILLS
========================================================= */

html[data-pos-theme="light"] body .status-completed{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .status-pending{
    background:#fffbeb !important;
    color:#b45309 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .status-approval{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border:1px solid #c4b5fd !important;
}

/* =========================================================
   TABLE ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .expense-btn{
    box-shadow:
        0 5px 12px rgba(15,23,42,.07) !important;
}

/* Edit */
html[data-pos-theme="light"] body .expense-edit{
    background:#fffbeb !important;
    color:#d97706 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .expense-edit:hover{
    background:#f59e0b !important;
    color:#ffffff !important;
}

/* Delete */
html[data-pos-theme="light"] body .expense-delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border:1px solid #fecdd3 !important;
}

html[data-pos-theme="light"] body .expense-delete:hover{
    background:#ef4444 !important;
    color:#ffffff !important;
}

/* More */
html[data-pos-theme="light"] body .expense-more{
    background:#f3efff !important;
    color:#6d28d9 !important;
    border:1px solid #c4b5fd !important;
}

html[data-pos-theme="light"] body .expense-more:hover{
    background:#7c3aed !important;
    color:#ffffff !important;
}

/* =========================================================
   EXPENSE SUMMARY CARD
========================================================= */

html[data-pos-theme="light"] body .approval-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .approval-head{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body .approval-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .approval-body{
    background:#ffffff !important;
    color:#334155 !important;
}

html[data-pos-theme="light"] body .approval-legend{
    color:#475569 !important;
}

html[data-pos-theme="light"] body .empty-state{
    color:#64748b !important;
}

/* Total Rows button inside summary */
html[data-pos-theme="light"] body .approval-head .reset-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
}

/* =========================================================
   ADD / EDIT EXPENSE MODAL
========================================================= */

html[data-pos-theme="light"] body
#expenseModal .modal-content,

html[data-pos-theme="light"] body
#expenseModal .exp-modal-content{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #d8e0eb !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.20) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Modal header */
html[data-pos-theme="light"] body
#expenseModal .exp-modal-header,

html[data-pos-theme="light"] body
#expenseModal .modal-header{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body
#expenseModal .exp-modal-title,

html[data-pos-theme="light"] body
#expenseModal .modal-title{
    color:#0f172a !important;
}

/* Modal title dot */
html[data-pos-theme="light"] body
#expenseModal .exp-modal-title::before,

html[data-pos-theme="light"] body
#expenseModal .modal-title::before{
    background:#7c3aed !important;

    box-shadow:
        0 0 0 4px rgba(124,58,237,.14) !important;
}

/* Close button */
html[data-pos-theme="light"] body
#expenseModal .exp-close{
    background:#ffffff !important;
    color:transparent !important;

    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body
#expenseModal .exp-close::before,

html[data-pos-theme="light"] body
#expenseModal .exp-close::after{
    background:#334155 !important;
}

html[data-pos-theme="light"] body
#expenseModal .exp-close:hover{
    background:#fff1f2 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body
#expenseModal .exp-close:hover::before,

html[data-pos-theme="light"] body
#expenseModal .exp-close:hover::after{
    background:#e11d48 !important;
}

/* Modal body */
html[data-pos-theme="light"] body
#expenseModal .exp-modal-body,

html[data-pos-theme="light"] body
#expenseModal .modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Labels */
html[data-pos-theme="light"] body
#expenseModal .dark-label,

html[data-pos-theme="light"] body
#expenseModal label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
#expenseModal .req{
    color:#e11d48 !important;
}

/* Modal inputs */
html[data-pos-theme="light"] body
#expenseModal .dark-input,

html[data-pos-theme="light"] body
#expenseModal .dark-select,

html[data-pos-theme="light"] body
#expenseModal .dark-textarea,

html[data-pos-theme="light"] body
#expenseModal input,

html[data-pos-theme="light"] body
#expenseModal select,

html[data-pos-theme="light"] body
#expenseModal textarea{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#expenseModal input[readonly]{
    background:#f1f5f9 !important;
    color:#475569 !important;
}

html[data-pos-theme="light"] body
#expenseModal input::placeholder,

html[data-pos-theme="light"] body
#expenseModal textarea::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body
#expenseModal input:focus,

html[data-pos-theme="light"] body
#expenseModal select:focus,

html[data-pos-theme="light"] body
#expenseModal textarea:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body
#expenseModal select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Date input */
html[data-pos-theme="light"] body
#expenseModal input[type="date"]{
    color-scheme:light !important;
}

/* Modal footer */
html[data-pos-theme="light"] body
#expenseModal .exp-modal-footer,

html[data-pos-theme="light"] body
#expenseModal .modal-footer{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

/* Cancel */
html[data-pos-theme="light"] body
#expenseModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

/* Save */
html[data-pos-theme="light"] body
#expenseModal .save-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

/* Backdrop */
html[data-pos-theme="light"] body.modal-open
.modal-backdrop.show{
    background:#0f172a !important;
    opacity:.45 !important;
}

/* =========================================================
   SCROLLBARS
========================================================= */

html[data-pos-theme="light"] body
.table-wrap::-webkit-scrollbar-track,

html[data-pos-theme="light"] body
.content-wrapper::-webkit-scrollbar-track,

html[data-pos-theme="light"] body
#expenseModal .exp-modal-body::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"] body
.table-wrap::-webkit-scrollbar-thumb,

html[data-pos-theme="light"] body
.content-wrapper::-webkit-scrollbar-thumb,

html[data-pos-theme="light"] body
#expenseModal .exp-modal-body::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
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
                <h1 class="page-title">Expenses</h1>
                <button type="button" class="btn-yellow" onclick="openExpenseModal()">
                    <i class="bi bi-plus-lg me-1"></i> Add Expense
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
                    <div class="stat-title">Today Expense</div>
                    <div class="stat-sub">PKR <?= number_format($todayExpense) ?></div>
                    <div class="stat-value"><?= number_format($todayExpense) ?></div>
                </div>

                <div class="stat-card stat-gold">
                    <div class="stat-title">This Month</div>
                    <div class="stat-sub">PKR <?= number_format($thisMonthExpense) ?></div>
                    <div class="stat-value"><?= number_format($thisMonthExpense) ?></div>
                </div>

                <div class="stat-card stat-orange">
                    <div class="pending-badge">
                        <?= count(array_filter($expenses, fn($x) => in_array(strtolower((string)$x['status']), ['pending', 'pending approval'], true))) ?>
                    </div>
                    <div class="stat-title">Pending Approval</div>
                    <div class="stat-sub">PKR <?= number_format($pendingApprovalAmt) ?></div>
                    <div class="stat-value"><?= number_format($pendingApprovalAmt) ?></div>
                </div>

                <div class="stat-card stat-green">
                    <div class="stat-title">Total Expense</div>
                    <div class="stat-sub">PKR <?= number_format($totalAmount) ?></div>
                    <div class="stat-value" style="font-size:22px;">PKR <?= number_format($totalAmount) ?></div>
                </div>
            </div>

            <div class="filter-card">
                <form method="GET">
                    <div class="filter-row">
                        <select class="erp-select" name="branch_id">
                            <option value="">All Branches</option>
                            <?php foreach ($branchList as $branch): ?>
                                <option value="<?= (int)$branch['id'] ?>" <?= $branchFilter !== '' && (int)$branchFilter === (int)$branch['id'] ? 'selected' : '' ?>>
                                    <?= e($branch['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select class="erp-select" name="payment_method">
                            <option value="">All Methods</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= e($method) ?>" <?= $methodFilter === $method ? 'selected' : '' ?>>
                                    <?= e($method) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select class="erp-select" name="status">
                            <option value="">All Status</option>
                            <?php foreach ($statusOptions as $st): ?>
                                <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>
                                    <?= e($st) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select class="erp-select" name="date_filter">
                            <option value="">All Dates</option>
                            <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="this_month" <?= $dateFilter === 'this_month' ? 'selected' : '' ?>>This Month</option>
                            <option value="last_30_days" <?= $dateFilter === 'last_30_days' ? 'selected' : '' ?>>Last 30 Days</option>
                        </select>

                        <button type="submit" class="filter-btn"><i class="bi bi-search me-1"></i> Filter</button>
                        <a href="expenses.php" class="reset-btn d-inline-flex align-items-center justify-content-center text-decoration-none">
                            <i class="bi bi-arrow-clockwise me-1"></i> Reset
                        </a>
                    </div>

                    <div class="search-row">
                        <input type="text" class="erp-input" name="search" placeholder="Search expenses..." value="<?= e($search) ?>">
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
                                <th>Date</th>
                                <th>Branch</th>
                                <?php if ($logged_role_id === 1): ?>
                                    <th>Client</th>
                                <?php endif; ?>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Payment Method</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="<?= $logged_role_id === 1 ? 11 : 10 ?>" class="text-center py-4">No expenses found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expenses as $expense): ?>
                                    <tr>
                                        <td><input type="checkbox" class="row-check" value="<?= (int)$expense['id'] ?>"></td>

                                        <td><?= e($expense['expense_code'] ?: ('EXP-' . str_pad((string)$expense['id'], 6, '0', STR_PAD_LEFT))) ?></td>
                                        <td><?= !empty($expense['expense_date']) ? date('d/m/Y', strtotime($expense['expense_date'])) : '-' ?></td>

                                        <td>
                                            <div class="expense-cell">
                                                <span class="expense-icon"><i class="bi bi-wallet2"></i></span>
                                                <span><?= e($expense['branch_name'] ?? '-') ?></span>
                                            </div>
                                        </td>

                                        <?php if ($logged_role_id === 1): ?>
                                            <td><?= e($expense['business_name'] ?? '-') ?></td>
                                        <?php endif; ?>

                                        <td><?= e($expense['category'] ?? '-') ?></td>
                                        <td><?= e($expense['description'] ?? '-') ?></td>
                                        <td><?= e($expense['payment_method'] ?? '-') ?></td>
                                        <td>PKR <?= number_format((float)$expense['amount']) ?></td>
                                        <td>
                                            <span class="status-pill <?= expenseStatusClass((string)$expense['status']) ?>">
                                                <?= e($expense['status'] ?: 'Pending') ?>
                                            </span>
                                        </td>
                                     <td>
    <div class="expense-actions">

        <!-- EDIT -->
        <button
            type="button"
            class="expense-btn expense-edit editExpenseBtn"
            data-id="<?= (int)$expense['id'] ?>"
            data-client_id="<?= (int)$expense['client_id'] ?>"
            data-branch_id="<?= (int)$expense['branch_id'] ?>"
            data-amount="<?= e($expense['amount']) ?>"
            data-description="<?= e($expense['description']) ?>"
            data-category="<?= e($expense['category']) ?>"
            data-payment_method="<?= e($expense['payment_method']) ?>"
            data-status="<?= e($expense['status']) ?>"
            data-expense_date="<?= e($expense['expense_date']) ?>"
            data-expense_code="<?= e($expense['expense_code']) ?>"
            title="Edit Expense">
            <i class="bi bi-pencil-fill"></i>
        </button>

        <!-- DELETE -->
        <a
            href="?delete=<?= (int)$expense['id'] ?>"
            class="expense-btn expense-delete"
            onclick="return confirm('Delete this expense?')"
            title="Delete Expense">
            <i class="bi bi-trash-fill"></i>
        </a>

        <!-- MORE -->
        <button
            type="button"
            class="expense-btn expense-more"
            title="More Options">
            <i class="bi bi-three-dots-vertical"></i>
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

            <div class="approval-card">
                <div class="approval-head">
                    <div class="approval-title">
                        <i class="bi bi-hourglass-split text-warning"></i>
                        Expense Summary
                    </div>
                    <button type="button" class="reset-btn" style="height:40px;">
                        Total Rows: <?= $totalExpenses ?>
                    </button>
                </div>
                <div class="approval-body">
                    <div class="approval-legend">
                        <span><span class="legend-dot" style="background:#d9aa25;"></span>Pending / Pending Approval</span>
                        <span><span class="legend-dot" style="background:#69b46a;"></span>Approved</span>
                        <span><span class="legend-dot" style="background:#4c8dff;"></span>Daily + Monthly Tracking</span>
                    </div>
                    <div class="empty-state" style="padding-top:8px;">Manage your business expenses from one place.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl-custom">
        <div class="modal-content exp-modal-content">
            <form method="POST" id="expenseForm">
                <input type="hidden" name="save_expense" value="1">
                <input type="hidden" name="expense_id" id="expense_id">

                <div class="exp-modal-header">
                    <h5 class="exp-modal-title" id="expenseModalTitle">Add Expense</h5>
                    <button type="button" class="exp-close" data-bs-dismiss="modal">&times;</button>
                </div>

                <div class="exp-modal-body">
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="dark-label">Expense Title <span class="req">*</span></label>
                            <input type="text" name="description" id="description" class="dark-input" placeholder="Expense title..." required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Category <span class="req">*</span></label>
                            <select name="category" id="category" class="dark-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categoryOptions as $category): ?>
                                    <option value="<?= e($category) ?>"><?= e($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Expense Code</label>
                            <input type="text" name="expense_code" id="expense_code" class="dark-input" value="<?= e($nextCode) ?>" readonly>
                        </div>

                        <?php if ($logged_role_id === 1): ?>
                            <div class="col-md-6 mb-3">
                                <label class="dark-label">Client <span class="req">*</span></label>
                                <select name="client_id" id="client_id" class="dark-select" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clientList as $client): ?>
                                        <option value="<?= (int)$client['id'] ?>"><?= e($client['business_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="client_id" value="<?= (int)$logged_client_id ?>">
                        <?php endif; ?>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Branch <span class="req">*</span></label>
                            <select name="branch_id" id="branch_id" class="dark-select" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branchList as $branch): ?>
                                    <?php
                                        $allowBranch = ($logged_role_id === 1)
                                            ? true
                                            : ((int)$branch['client_id'] === (int)$logged_client_id);
                                        if (!$allowBranch) continue;
                                    ?>
                                    <option value="<?= (int)$branch['id'] ?>"><?= e($branch['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Amount <span class="req">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="dark-input" placeholder="0" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Payment Method <span class="req">*</span></label>
                            <select name="payment_method" id="payment_method" class="dark-select" required>
                                <option value="">Select Payment Method</option>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <option value="<?= e($method) ?>"><?= e($method) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Status <span class="req">*</span></label>
                            <select name="status" id="status" class="dark-select" required>
                                <?php foreach ($statusOptions as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $status === 'Pending' ? 'selected' : '' ?>>
                                        <?= e($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="dark-label">Date <span class="req">*</span></label>
                            <input type="date" name="expense_date" id="expense_date" class="dark-input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                </div>

                <div class="exp-modal-footer">
                    <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="save-btn" id="saveExpenseBtn">Add Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const expenseModal = new bootstrap.Modal(document.getElementById('expenseModal'));

function openExpenseModal() {
    resetExpenseForm();
    expenseModal.show();
}

function resetExpenseForm() {
    document.getElementById('expenseForm').reset();
    document.getElementById('expense_id').value = '';
    document.getElementById('expenseModalTitle').innerText = 'Add Expense';
    document.getElementById('saveExpenseBtn').innerText = 'Add Expense';
    document.getElementById('expense_date').value = new Date().toISOString().slice(0, 10);
    document.getElementById('expense_code').value = '<?= e($nextCode) ?>';

    const statusField = document.getElementById('status');
    if (statusField) statusField.value = 'Pending';

    <?php if ($logged_role_id === 1): ?>
    const clientField = document.getElementById('client_id');
    if (clientField) clientField.value = '';
    <?php endif; ?>
}

document.querySelectorAll('.editExpenseBtn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('expense_id').value = this.dataset.id || '';
        document.getElementById('description').value = this.dataset.description || '';
        document.getElementById('category').value = this.dataset.category || '';
        document.getElementById('branch_id').value = this.dataset.branch_id || '';
        document.getElementById('amount').value = this.dataset.amount || '';
        document.getElementById('payment_method').value = this.dataset.payment_method || '';
        document.getElementById('status').value = this.dataset.status || 'Pending';
        document.getElementById('expense_date').value = this.dataset.expense_date || '';
        document.getElementById('expense_code').value = this.dataset.expense_code || '';

        <?php if ($logged_role_id === 1): ?>
        document.getElementById('client_id').value = this.dataset.client_id || '';
        <?php endif; ?>

        document.getElementById('expenseModalTitle').innerText = 'Edit Expense';
        document.getElementById('saveExpenseBtn').innerText = 'Update Expense';

        expenseModal.show();
    });
});

document.getElementById('checkAll')?.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});
</script>
</body>
</html>