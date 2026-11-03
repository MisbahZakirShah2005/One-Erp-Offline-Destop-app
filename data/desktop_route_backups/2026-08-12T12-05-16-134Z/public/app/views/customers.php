<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

$integrationHelperPath = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/integration_helper.php';
if (file_exists($integrationHelperPath)) {
    require_once $integrationHelperPath;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$client_id = (int)($_SESSION['client_id'] ?? 0);
if ($client_id <= 0) {
    die("Invalid client session");
}

$wholesaleEnabled = function_exists('isIntegrationEnabled')
    ? isIntegrationEnabled($conn, $client_id, 'Wholesale Pricing')
    : false;

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function customerInitials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'CU';

    $parts = preg_split('/\s+/', $name);
    $initials = '';

    foreach ($parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }

    return $initials ?: 'CU';
}

function redirect_with_message($type, $message) {
    header("Location: customers.php?msg_type=" . urlencode($type) . "&msg=" . urlencode($message));
    exit;
}

function normalize_customer_type($type) {
    global $wholesaleEnabled;

    $type = trim((string)$type);
    $lower = strtolower($type);

    /*
    |--------------------------------------------------------------------------
    | Walk-In removed
    |--------------------------------------------------------------------------
    | Walk-In / Walkin customers ab Retailer me merge honge.
    | Default customer type bhi Retailer rahega.
    */
    if (in_array($lower, ['wholesaler', 'wholesale'], true)) {
        return $wholesaleEnabled ? 'Wholesaler' : 'Retailer';
    }

    return 'Retailer';
}

function display_customer_type($type) {
    global $wholesaleEnabled;

    $type = trim((string)$type);
    $lower = strtolower($type);

    if (in_array($lower, ['wholesaler', 'wholesale'], true) && $wholesaleEnabled) {
        return 'Wholesaler';
    }

    /*
    |--------------------------------------------------------------------------
    | Walk-In + Retailer display as Retailer
    |--------------------------------------------------------------------------
    */
    return 'Retailer';
}

function normalize_customer_filter($type) {
    global $wholesaleEnabled;

    $type = trim((string)$type);
    $lower = strtolower($type);

    if (in_array($lower, ['wholesaler', 'wholesale'], true)) {
        return $wholesaleEnabled ? 'Wholesaler' : '';
    }

    if (in_array($lower, ['retailer', 'retail', 'walk-in', 'walkin', 'walk in'], true)) {
        return 'Retailer';
    }

    return '';
}

/* ===================== HANDLE POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_customer') {
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $type    = normalize_customer_type($_POST['type'] ?? 'Walk-in');

        if ($name === '') {
            redirect_with_message('danger', 'Customer name is required.');
        }

        $stmt = $conn->prepare("
            INSERT INTO customers (client_id, name, phone, email, address, type)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssss", $client_id, $name, $phone, $email, $address, $type);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Customer added successfully.');
    }

    if ($action === 'update_customer') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $type    = normalize_customer_type($_POST['type'] ?? 'Walk-in');

        if ($id <= 0 || $name === '') {
            redirect_with_message('danger', 'Invalid customer data.');
        }

        $stmt = $conn->prepare("
            UPDATE customers
            SET name = ?, phone = ?, email = ?, address = ?, type = ?
            WHERE id = ? AND client_id = ?
        ");
        $stmt->bind_param("sssssii", $name, $phone, $email, $address, $type, $id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Customer updated successfully.');
    }

    if ($action === 'delete_customer') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            redirect_with_message('danger', 'Invalid customer id.');
        }

        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Customer deleted successfully.');
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['selected_ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            redirect_with_message('danger', 'Please select at least one customer.');
        }

        $cleanIds = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $cleanIds[] = $id;
            }
        }

        if (empty($cleanIds)) {
            redirect_with_message('danger', 'No valid customers selected.');
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $types = str_repeat('i', count($cleanIds)) . 'i';
        $params = array_merge($cleanIds, [$client_id]);

        $sql = "DELETE FROM customers WHERE id IN ($placeholders) AND client_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $deletedRows = $stmt->affected_rows;
        $stmt->close();

        redirect_with_message('success', $deletedRows . ' customer(s) deleted successfully.');
    }

    if ($action === 'import_customers') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            redirect_with_message('danger', 'Please choose a valid CSV file.');
        }

        $tmpName = $_FILES['import_file']['tmp_name'];
        $handle = fopen($tmpName, 'r');

        if (!$handle) {
            redirect_with_message('danger', 'Unable to read uploaded file.');
        }

        $firstRow = fgetcsv($handle);
        $imported = 0;
        $skipped  = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $name    = trim($row[0] ?? '');
            $phone   = trim($row[1] ?? '');
            $email   = trim($row[2] ?? '');
            $address = trim($row[3] ?? '');
            $type    = normalize_customer_type($row[4] ?? 'Walk-in');

            if ($name === '') {
                $skipped++;
                continue;
            }

            $stmt = $conn->prepare("
                INSERT INTO customers (client_id, name, phone, email, address, type)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssss", $client_id, $name, $phone, $email, $address, $type);

            if ($stmt->execute()) {
                $imported++;
            } else {
                $skipped++;
            }

            $stmt->close();
        }

        fclose($handle);
        redirect_with_message('success', "Import completed. Imported: {$imported}, Skipped: {$skipped}");
    }
}

/* ===================== HANDLE EXPORT ===================== */
$search = trim($_GET['search'] ?? '');
$typeFilter = normalize_customer_filter($_GET['type'] ?? '');

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "SELECT id, name, phone, email, address, type FROM customers WHERE client_id = ?";
    $params = [$client_id];
    $types = "i";

    if ($search !== '') {
        $sql .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ?)";
        $searchLike = "%{$search}%";
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= "ssss";
    }

    if ($typeFilter === 'Wholesaler' && $wholesaleEnabled) {
        $sql .= " AND LOWER(TRIM(type)) IN ('wholesaler', 'wholesale')";
    } elseif ($typeFilter === 'Retailer') {
        $sql .= " AND (type IS NULL OR TRIM(type) = '' OR LOWER(TRIM(type)) IN ('retailer', 'retail', 'walk-in', 'walkin', 'walk in')";
        if (!$wholesaleEnabled) {
            $sql .= " OR LOWER(TRIM(type)) IN ('wholesaler', 'wholesale')";
        }
        $sql .= ")";
    }

    $sql .= " ORDER BY id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=customers_export_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Phone', 'Email', 'Address', 'Type']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'] ?? '',
            $row['name'] ?? '',
            $row['phone'] ?? '',
            $row['email'] ?? '',
            $row['address'] ?? '',
            display_customer_type($row['type'] ?? ''),
        ]);
    }

    fclose($output);
    $stmt->close();
    exit;
}

/* ===================== FLASH MESSAGE ===================== */
$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ===================== COUNTS ===================== */
$totalCustomers = 0;
$wholesaleCustomers = 0;
$retailCustomers = 0;

$stmt = $conn->prepare("SELECT COUNT(*) FROM customers WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stmt->bind_result($totalCustomers);
$stmt->fetch();
$stmt->close();

if ($wholesaleEnabled) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM customers
        WHERE client_id = ?
          AND LOWER(TRIM(type)) IN ('wholesaler', 'wholesale')
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $stmt->bind_result($wholesaleCustomers);
    $stmt->fetch();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM customers
        WHERE client_id = ?
          AND (
                type IS NULL
                OR TRIM(type) = ''
                OR LOWER(TRIM(type)) IN ('retailer', 'retail', 'walk-in', 'walkin', 'walk in')
          )
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $stmt->bind_result($retailCustomers);
    $stmt->fetch();
    $stmt->close();
} else {
    /*
    |--------------------------------------------------------------------------
    | Wholesale feature off
    |--------------------------------------------------------------------------
    | UI me Wholesaler hide rahega. Walk-In bhi Retailer me merged hai,
    | isliye Retail Customers total ke equal show honge.
    */
    $retailCustomers = (int)$totalCustomers;
}

/* ===================== FETCH CUSTOMERS ===================== */
$sql = "SELECT id, name, phone, email, address, type FROM customers WHERE client_id = ?";
$params = [$client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ?)";
    $searchLike = "%{$search}%";
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= "ssss";
}

if ($typeFilter === 'Wholesaler' && $wholesaleEnabled) {
    $sql .= " AND LOWER(TRIM(type)) IN ('wholesaler', 'wholesale')";
} elseif ($typeFilter === 'Retailer') {
    $sql .= " AND (type IS NULL OR TRIM(type) = '' OR LOWER(TRIM(type)) IN ('retailer', 'retail', 'walk-in', 'walkin', 'walk in')";
    if (!$wholesaleEnabled) {
        $sql .= " OR LOWER(TRIM(type)) IN ('wholesaler', 'wholesale')";
    }
    $sql .= ")";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$customers = [];
while ($row = $result->fetch_assoc()) {
    $row['type'] = display_customer_type($row['type'] ?? '');
    $customers[] = $row;
}
$stmt->close();

$customersJson = json_encode($customers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customers</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <script>
(function () {
    const storageKey = 'oneerp_pos_theme';
    let selectedTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            margin:0;
            background:#f3f4f8;
            font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .container-scroll{
            display:flex;
            min-height:100vh;
        }

        .sidebar{
            width:270px;
            flex-shrink:0;
        }

        .main-panel{
            flex-grow:1;
            display:flex;
            flex-direction:column;
            min-width:0;
              background:#f3f4f8;
        }

        .topbar{
            min-height:70px;
            border-bottom:1px solid #e5e7eb;
            display:flex;
            align-items:center;
            background:#fff;
            padding:0;
        }

        .content-wrapper{
            padding:20px;
            flex-grow:1;
            overflow-y:auto;
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .page-title{
            font-size:28px;
            font-weight:700;
            color:#1f2937;
            margin:0;
        }

        .header-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn-soft{
            border:none;
            border-radius:10px;
            padding:10px 16px;
            color:#fff;
            font-weight:600;
            font-size:14px;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
            display:inline-flex;
            align-items:center;
            gap:8px;
        }

        .btn-add{ background:#2e9e59; }
        .btn-bulk{ background:#3f8efc; }
        .btn-import{ background:#2ea44f; }
        .btn-export{ background:#5c4fd6; }

        .stats-row{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
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
            cursor:pointer;
            transition:all .2s ease;
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

        .stat-card.active{
            border:2px solid #1f2937;
            transform:translateY(-2px);
        }

        .card-blue{ background:linear-gradient(135deg,#4095ff,#2f80ed); }
        .card-green{ background:linear-gradient(135deg,#49be88,#2ea769); }
        .card-cyan{ background:linear-gradient(135deg,#4ac9d6,#26b4c0); }
        .card-yellow{ background:linear-gradient(135deg,#f7c943,#f4b400); }

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

        .quick-btn{
            height:44px;
            border:none;
            border-radius:10px;
            padding:0 14px;
            background:#111827;
            color:#fff;
            font-weight:600;
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
            background:#fff;
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
            gap:6px;
        }

        .table-card{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            overflow:hidden;
        }

        .table-responsive{
            overflow:auto;
        }

        .customers-table{
            width:100%;
            min-width:980px;
            border-collapse:collapse;
        }

        .customers-table thead th{
            background:#28344d;
            color:#fff;
            font-weight:600;
            font-size:14px;
            padding:14px 12px;
            border-right:1px solid rgba(255,255,255,0.08);
            white-space:nowrap;
        }

        .customers-table tbody td{
            padding:14px 12px;
            border-top:1px solid #edf0f5;
            vertical-align:middle;
            font-size:14px;
            color:#374151;
        }

        .customers-table tbody tr:hover{
            background:#fafbfc;
        }

        .customer-name-wrap{
            display:flex;
            align-items:center;
            gap:10px;
        }

        .avatar-badge{
            width:42px;
            height:42px;
            border-radius:50%;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:linear-gradient(135deg,#dbeafe,#bfdbfe);
            color:#1d4ed8;
            font-weight:700;
            flex-shrink:0;
        }

        .customer-name{
            font-weight:700;
            color:#1f2937;
        }

        .customer-email-sub{
            font-size:12px;
            color:#667085;
        }

        .badge-type{
            padding:6px 12px;
            border-radius:7px;
            font-size:12px;
            font-weight:700;
            display:inline-block;
        }

        .walkin{ background:#f0c541; color:#5b4300; }
        .wholesale{ background:#6da789; color:#fff; }
        .retail{ background:#68a4ea; color:#fff; }

        .actions{
            display:flex;
            gap:10px;
            align-items:center;
        }

        .actions button{
            border:none;
            background:none;
            color:#111827;
            font-size:16px;
            cursor:pointer;
        }

        .table-footer{
            padding:14px;
            border-top:1px solid #e5e7eb;
            background:#fff;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .bulk-delete-btn{
            border:none;
            background:#ef4444;
            color:#fff;
            border-radius:8px;
            padding:10px 14px;
            font-size:14px;
            font-weight:600;
        }
        
        
.batch-actions-wrap{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    flex-wrap:nowrap;
}

.batch-action-btn{
    width:38px;
    height:38px;
    border:none;
    outline:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

.batch-action-btn i{
    font-size:15px;
    line-height:1;
}

.batch-action-btn:hover{
    transform:translateY(-2px);
}

.batch-action-edit{
    background:#fff4d6;
    color:#e0a100;
}

.batch-action-edit:hover{
    background:#e0a100;
    color:#fff;
}

.batch-action-delete{
    background:#ffe3e3;
    color:#ef4444;
}

.batch-action-delete:hover{
    background:#ef4444;
    color:#fff;
}


.required-star{
    color:#f4b21c;
}

/* ===================== UNIVERSAL PREMIUM MODAL UI ===================== */
.erp-modal-content{
    background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
    color:#fff;
    border:none;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.35);
}

.erp-modal-header{
    background:transparent;
    border-bottom:1px solid rgba(255,255,255,0.08);
    padding:18px 22px;
}

.erp-modal-header .modal-title{
    font-weight:700;
    font-size:18px;
    color:#fff;
}

.erp-modal-body{
    padding:20px 22px 10px;
}

.erp-modal-footer{
    border-top:1px solid rgba(255,255,255,0.08);
    padding:16px 22px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:14px;
}

.erp-left-note{
    color:#dbe0f4;
    font-size:14px;
    font-weight:500;
}

.erp-footer-btns{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.erp-cancel-btn{
    height:42px;
    min-width:110px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
    color:#fff;
    font-weight:600;
}

.erp-save-btn{
    height:42px;
    min-width:130px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
    color:#fff;
    font-weight:700;
}

.erp-submit-green{
    height:42px;
    min-width:130px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#2ecb70 0%, #1d9f54 100%);
    color:#fff;
    font-weight:700;
}

.erp-label{
    display:block;
    font-size:15px;
    margin-bottom:7px;
    color:#e4e8f8;
    font-weight:500;
}

.erp-label span{
    color:#f4b21c;
}

.erp-input,
.erp-select,
.erp-textarea{
    width:100%;
    border:1px solid rgba(255,255,255,0.10);
    background:#e9eaf3;
    color:#222;
    border-radius:8px;
    outline:none;
    font-size:14px;
    transition:all .2s ease;
}

.erp-input,
.erp-select{
    height:44px;
    padding:8px 14px;
}

.erp-textarea{
    min-height:96px;
    resize:none;
    padding:12px 14px;
}

.erp-input:focus,
.erp-select:focus,
.erp-textarea:focus{
    border-color:#f0ae19;
    box-shadow:0 0 0 2px rgba(240,174,25,0.18);
    background:#f3f4fa;
}

.erp-preview-card{
    border:1px dashed rgba(255,255,255,0.18);
    border-radius:12px;
    padding:14px;
    background:rgba(255,255,255,0.02);
    height:100%;
}

.erp-preview-box{
    min-height:220px;
    border:1px dashed rgba(255,255,255,0.15);
    border-radius:12px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:18px;
    overflow:hidden;
    background:rgba(255,255,255,0.02);
    text-align:center;
}

.erp-preview-box i{
    font-size:46px;
    color:#bfc4dc;
    margin-bottom:12px;
}

.erp-preview-title{
    font-size:18px;
    font-weight:600;
    color:#d7dbef;
    margin-bottom:4px;
}

.erp-preview-subtitle{
    font-size:12px;
    color:#8f96b8;
}

.erp-modal-note{
    color:#9aa3c7;
    font-size:12px;
    margin-top:10px;
    line-height:1.6;
}

.erp-note-box{
    border:1px dashed rgba(255,255,255,0.16);
    border-radius:12px;
    padding:12px 14px;
    background:rgba(255,255,255,0.03);
    color:#cfd5ea;
    font-size:13px;
    line-height:1.6;
    margin-top:4px;
}

.erp-type-grid{
    display:grid;
    grid-template-columns:1fr;
    gap:10px;
}

.erp-type-card{
    border:1px solid rgba(255,255,255,0.08);
    background:rgba(255,255,255,0.03);
    border-radius:10px;
    padding:12px 14px;
}

.erp-type-card strong{
    display:block;
    color:#fff;
    font-size:14px;
    margin-bottom:4px;
}

.erp-type-card span{
    display:block;
    color:#bfc7ea;
    font-size:12px;
}

.sample-note{
    font-size:12px;
    color:#cfd5ea;
    line-height:1.7;
}

.erp-modal-content .btn-close-white{
    filter:invert(1);
}

@media (max-width:768px){
    .erp-modal-footer{
        justify-content:flex-end;
    }

    .erp-left-note{
        width:100%;
    }

    .erp-footer-btns{
        width:100%;
        flex-direction:column;
    }

    .erp-cancel-btn,
    .erp-save-btn,
    .erp-submit-green{
        width:100%;
    }
}

        @media (max-width:1100px){
            .stats-row{
                grid-template-columns:repeat(2,1fr);
            }
        }

        @media (max-width:768px){
            .stats-row{
                grid-template-columns:1fr;
            }

            .filter-bar{
                flex-direction:column;
                align-items:stretch;
            }

            .search-wrap,
            .filter-control,
            .filter-btn{
                width:100%;
                min-width:100%;
            }

            .page-title{
                font-size:24px;
            }

            .sidebar{
                width:240px;
            }
        }
    

/* =========================================================
   CUSTOMERS POPUP BLACK + GOLD + WHITE THEME OVERRIDE
   Customer Add/Edit Modal + Import Modal
   Backend/PHP/JS same - only popup theme
========================================================= */

.modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
}
/* SMALL CUSTOMER MODAL SIZE FIX */
#customerModal .modal-dialog{
    max-width:760px !important;
    width:760px !important;
    margin:1rem auto !important;
}

#importModal .modal-dialog{
    max-width:680px !important;
    width:680px !important;
    margin:1rem auto !important;
}

#customerModal .erp-modal-content,
#importModal .erp-modal-content{
    max-height:calc(100vh - 40px) !important;
}

#customerModal .erp-modal-header,
#importModal .erp-modal-header{
    padding:14px 18px !important;
}

#customerModal .modal-title,
#importModal .modal-title{
    font-size:18px !important;
}

#customerModal .btn-close,
#importModal .btn-close{
    width:38px !important;
    height:38px !important;
}

#customerModal .erp-modal-body,
#importModal .erp-modal-body{
    padding:16px 18px !important;
    max-height:calc(100vh - 155px) !important;
    overflow-y:auto !important;
}

#customerModal .erp-modal-footer,
#importModal .erp-modal-footer{
    padding:12px 18px 14px !important;
}

#customerModal .erp-preview-card,
#importModal .erp-preview-card{
    display:none !important;
}

#customerModal .col-lg-4,
#importModal .col-lg-4{
    display:none !important;
}

#customerModal .col-lg-8,
#importModal .col-lg-8{
    width:100% !important;
    flex:0 0 100% !important;
    max-width:100% !important;
}

#customerModal .erp-input,
#customerModal .erp-select,
#importModal .erp-input,
#importModal .erp-select{
    height:42px !important;
    padding:8px 12px !important;
    font-size:14px !important;
}

#customerModal .erp-textarea,
#importModal .erp-textarea{
    min-height:70px !important;
    height:70px !important;
    padding:10px 12px !important;
    font-size:14px !important;
}

#customerModal .erp-label,
#importModal .erp-label{
    font-size:13px !important;
    margin-bottom:6px !important;
}

#customerModal .erp-type-grid{
    grid-template-columns:repeat(3,1fr) !important;
    gap:8px !important;
}

#customerModal .erp-type-card{
    padding:9px 10px !important;
}

#customerModal .erp-type-card strong{
    font-size:13px !important;
}

#customerModal .erp-type-card span{
    font-size:11px !important;
    line-height:1.35 !important;
}

#customerModal .erp-left-note,
#importModal .erp-left-note{
    font-size:12px !important;
}

#customerModal .erp-cancel-btn,
#customerModal .erp-save-btn,
#importModal .erp-cancel-btn,
#importModal .erp-submit-green{
    height:38px !important;
    min-width:105px !important;
    font-size:13px !important;
}

@media (max-width:850px){
    #customerModal .modal-dialog,
    #importModal .modal-dialog{
        width:94vw !important;
        max-width:94vw !important;
    }

    #customerModal .erp-type-grid{
        grid-template-columns:1fr !important;
    }
}

#customerModal .erp-modal-content,
#importModal .erp-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.55) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 30px 90px rgba(0,0,0,.65) !important;
}

#customerModal .erp-modal-header,
#importModal .erp-modal-header{
    background:linear-gradient(180deg,#161b28 0%, #0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
}

#customerModal .modal-title,
#importModal .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

#customerModal .modal-title::before,
#importModal .modal-title::before{
    content:'';
    width:13px;
    height:13px;
    border-radius:50%;
    background:#c9a84c;
    box-shadow:0 0 0 5px rgba(201,168,76,.15);
    display:inline-block;
    flex-shrink:0;
}

/* Proper visible white X button, without depending on Bootstrap SVG */
#customerModal .btn-close,
#importModal .btn-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.12) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
}

#customerModal .btn-close::before,
#customerModal .btn-close::after,
#importModal .btn-close::before,
#importModal .btn-close::after{
    content:'';
    position:absolute;
    top:50%;
    left:50%;
    width:22px;
    height:2px;
    background:#ffffff;
    border-radius:2px;
    transform-origin:center;
}

#customerModal .btn-close::before,
#importModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg);
}

#customerModal .btn-close::after,
#importModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg);
}

#customerModal .btn-close:hover,
#importModal .btn-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px);
}

#customerModal .erp-modal-body,
#importModal .erp-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#customerModal .erp-modal-body::-webkit-scrollbar,
#importModal .erp-modal-body::-webkit-scrollbar{
    width:7px;
}

#customerModal .erp-modal-body::-webkit-scrollbar-track,
#importModal .erp-modal-body::-webkit-scrollbar-track{
    background:#171c28;
    border-radius:999px;
}

#customerModal .erp-modal-body::-webkit-scrollbar-thumb,
#importModal .erp-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c;
    border-radius:999px;
}

#customerModal .erp-modal-footer,
#importModal .erp-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.25) !important;
    padding:18px 24px 22px !important;
}

#customerModal .erp-label,
#importModal .erp-label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#customerModal .erp-label span,
#importModal .erp-label span{
    color:#f5c742 !important;
}

#customerModal .erp-input,
#customerModal .erp-select,
#customerModal .erp-textarea,
#importModal .erp-input,
#importModal .erp-select,
#importModal .erp-textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#customerModal .erp-input,
#customerModal .erp-select,
#importModal .erp-input,
#importModal .erp-select{
    height:50px !important;
    padding:10px 16px !important;
}

#customerModal .erp-textarea,
#importModal .erp-textarea{
    min-height:112px !important;
    padding:14px 16px !important;
    resize:vertical !important;
}

#customerModal .erp-input::placeholder,
#customerModal .erp-textarea::placeholder,
#importModal .erp-input::placeholder,
#importModal .erp-textarea::placeholder{
    color:#8993a5 !important;
}

#customerModal .erp-input:focus,
#customerModal .erp-select:focus,
#customerModal .erp-textarea:focus,
#importModal .erp-input:focus,
#importModal .erp-select:focus,
#importModal .erp-textarea:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24), 0 5px 14px rgba(0,0,0,.22) !important;
}

#customerModal .erp-preview-card,
#importModal .erp-preview-card{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.35) !important;
    border-radius:14px !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#customerModal .erp-preview-box,
#importModal .erp-preview-box{
    background:#0f1117 !important;
    border:1.5px dashed rgba(201,168,76,.55) !important;
    border-radius:14px !important;
}

#customerModal .erp-preview-box i,
#importModal .erp-preview-box i{
    color:#f5c742 !important;
}

#customerModal .erp-preview-title,
#importModal .erp-preview-title{
    color:#ffffff !important;
    font-weight:900 !important;
}

#customerModal .erp-preview-subtitle,
#importModal .erp-preview-subtitle{
    color:#dbe0f4 !important;
    font-weight:700 !important;
}

#customerModal .erp-modal-note,
#importModal .erp-modal-note{
    color:#dbe0f4 !important;
    font-weight:700 !important;
}

#customerModal .erp-type-card{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.28) !important;
    border-radius:12px !important;
}

#customerModal .erp-type-card strong{
    color:#f5c742 !important;
    font-weight:900 !important;
}

#customerModal .erp-type-card span{
    color:#dbe0f4 !important;
}

#importModal .erp-note-box{
    background:#161b28 !important;
    border:1px solid rgba(201,168,76,.35) !important;
    color:#dbe0f4 !important;
    border-radius:12px !important;
    font-weight:700 !important;
}

#importModal .erp-note-box strong{
    color:#f5c742 !important;
}

#customerModal .erp-left-note,
#importModal .erp-left-note{
    color:#dbe0f4 !important;
    font-weight:800 !important;
}

#customerModal .erp-cancel-btn,
#importModal .erp-cancel-btn{
    height:44px !important;
    min-width:110px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#customerModal .erp-cancel-btn:hover,
#importModal .erp-cancel-btn:hover{
    background:#32394b !important;
}

#customerModal .erp-save-btn,
#importModal .erp-save-btn,
#importModal .erp-submit-green{
    height:44px !important;
    min-width:130px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#customerModal .erp-save-btn:hover,
#importModal .erp-save-btn:hover,
#importModal .erp-submit-green:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px);
}

@media (max-width:768px){
    #customerModal .modal-dialog,
    #importModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #customerModal .modal-title,
    #importModal .modal-title{
        font-size:18px !important;
    }

    #customerModal .btn-close,
    #importModal .btn-close{
        width:42px !important;
        height:42px !important;
    }
}

/* =====================================================
   CUSTOMERS.PHP - DARK PURPLE BLACK GLASS THEME
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
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
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

/* ================= PAGE HEADER ================= */
.page-header{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-bottom:20px !important;
}

.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

.header-actions{
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    align-items:center !important;
}

/* ================= ALERTS ================= */
.alert{
    border-radius:14px !important;
    font-weight:900 !important;
    backdrop-filter:blur(14px) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
}

.alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.26) !important;
}

.alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.26) !important;
}

/* ================= BUTTONS ================= */
.btn-soft{
    border:none !important;
    border-radius:13px !important;
    padding:11px 17px !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:14px !important;
    min-height:44px !important;
    box-shadow:0 14px 28px rgba(0,0,0,.22) !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    transition:.22s ease !important;
}

.btn-soft:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

.btn-add{ background:linear-gradient(135deg,#047857,#22c55e) !important; }
.btn-bulk{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
.btn-import{ background:linear-gradient(135deg,#0f766e,#06b6d4) !important; }
.btn-export{ background:linear-gradient(135deg,#6d4cff,#7c3aed) !important; }

.dropdown-menu{
    background:rgba(15,23,42,.96) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:14px !important;
    box-shadow:0 20px 45px rgba(0,0,0,.45) !important;
    padding:8px !important;
}

.dropdown-item{
    color:#e5e7eb !important;
    border-radius:10px !important;
    font-weight:800 !important;
}

.dropdown-item:hover{
    background:rgba(124,58,237,.18) !important;
    color:#fff !important;
}

/* ================= STATS ================= */
.stats-row{
    display:grid !important;
    grid-template-columns:repeat(4, 1fr) !important;
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
    cursor:pointer !important;
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

.stat-card:hover,
.stat-card.active{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.24) !important;
    filter:saturate(1.08) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card .title{
    font-size:15px !important;
    font-weight:900 !important;
    color:rgba(255,255,255,.95) !important;
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
    display:grid !important;
    grid-template-columns:auto 1fr 210px auto auto !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:18px !important;
}

.quick-btn,
.filter-btn{
    height:44px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 16px !important;
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    transition:.2s ease !important;
}

.quick-btn:hover,
.filter-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    background:rgba(124,58,237,.20) !important;
    border-color:rgba(139,92,246,.42) !important;
}

.filter-btn{
    text-decoration:none !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
}

.search-wrap{
    width:100% !important;
    min-width:0 !important;
    position:relative !important;
    flex:none !important;
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

/* ================= TABLE ================= */
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

.table-card:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.table-responsive{
    overflow:auto !important;
}

.customers-table{
    width:100% !important;
    min-width:980px !important;
    border-collapse:collapse !important;
    background:transparent !important;
}

.customers-table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:15px 14px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.customers-table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
}

/* White hover remove */
.customers-table tbody tr:hover td,
.customers-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
    box-shadow:none !important;
}

.customers-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.customer-name-wrap{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

.avatar-badge{
    width:42px !important;
    height:42px !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    flex-shrink:0 !important;
    box-shadow:0 12px 24px rgba(109,76,255,.24) !important;
}

.customer-name{
    font-weight:900 !important;
    color:#f8fafc !important;
}

.customer-email-sub{
    font-size:12px !important;
    color:#94a3b8 !important;
    margin-top:2px !important;
}

.badge-type{
    padding:7px 12px !important;
    border-radius:999px !important;
    font-size:12px !important;
    font-weight:900 !important;
    display:inline-flex !important;
}

.walkin{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}

.wholesale{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

.retail{
    background:rgba(59,130,246,.16) !important;
    color:#60a5fa !important;
    border:1px solid rgba(59,130,246,.25) !important;
}

/* Action buttons */
.batch-actions-wrap{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
    flex-wrap:nowrap !important;
}

.batch-action-btn{
    width:40px !important;
    height:40px !important;
    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:0 !important;
    cursor:pointer !important;
    transition:all .20s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.batch-action-btn:hover{
    transform:translateY(-2px) !important;
}

.batch-action-edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.batch-action-edit:hover{
    background:#f59e0b !important;
    color:#07111f !important;
}

.batch-action-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

.batch-action-delete:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

/* Table footer */
.table-footer{
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

.table-footer strong{
    color:#f8fafc !important;
}

.bulk-delete-btn{
    border:none !important;
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
    color:#fff !important;
    border-radius:12px !important;
    padding:11px 15px !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(239,68,68,.20) !important;
}

/* ================= MODALS - PURPLE GLASS ================= */
.modal-backdrop.show{
    opacity:.52 !important;
    background:#000 !important;
}

#customerModal .modal-dialog{
    max-width:720px !important;
    width:720px !important;
    margin:1rem auto !important;
}

#importModal .modal-dialog{
    max-width:620px !important;
    width:620px !important;
    margin:1rem auto !important;
}

#customerModal .erp-modal-content,
#importModal .erp-modal-content{
    max-height:84vh !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.82), rgba(17,24,39,.68)) !important;
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

#customerModal .erp-modal-header,
#importModal .erp-modal-header{
    min-height:54px !important;
    padding:12px 16px !important;
    background:rgba(7,11,20,.30) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

#customerModal .modal-title,
#importModal .modal-title{
    font-size:17px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    letter-spacing:-.02em !important;
}

#customerModal .modal-title::before,
#importModal .modal-title::before{
    display:none !important;
}

#customerModal .erp-modal-body,
#importModal .erp-modal-body{
    padding:14px 16px !important;
    background:rgba(15,23,42,.22) !important;
    max-height:60vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    color:#f8fafc !important;
}

#customerModal .erp-modal-footer,
#importModal .erp-modal-footer{
    padding:10px 16px !important;
    min-height:52px !important;
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

#customerModal .erp-preview-card,
#importModal .erp-preview-card,
#customerModal .col-lg-4,
#importModal .col-lg-4{
    display:none !important;
}

#customerModal .col-lg-8,
#importModal .col-lg-8{
    width:100% !important;
    flex:0 0 100% !important;
    max-width:100% !important;
}

#customerModal .row,
#importModal .row{
    --bs-gutter-x:10px !important;
    --bs-gutter-y:10px !important;
}

#customerModal .erp-label,
#importModal .erp-label{
    display:block !important;
    color:#e5e7eb !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin:0 0 5px !important;
}

#customerModal .erp-label span,
#importModal .erp-label span{
    color:#a78bfa !important;
}

#customerModal .erp-input,
#customerModal .erp-select,
#importModal .erp-input,
#importModal .erp-select{
    width:100% !important;
    height:36px !important;
    min-height:36px !important;
    padding:5px 10px !important;
    border-radius:9px !important;
    background:rgba(255,255,255,.90) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    font-size:13px !important;
    font-weight:700 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}

#customerModal .erp-textarea,
#importModal .erp-textarea{
    min-height:58px !important;
    height:58px !important;
    padding:8px 10px !important;
    border-radius:9px !important;
    background:rgba(255,255,255,.90) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    font-size:13px !important;
    font-weight:700 !important;
    resize:vertical !important;
}

#customerModal .erp-input:focus,
#customerModal .erp-select:focus,
#customerModal .erp-textarea:focus,
#importModal .erp-input:focus,
#importModal .erp-select:focus,
#importModal .erp-textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.20),
        0 8px 18px rgba(0,0,0,.18) !important;
    outline:none !important;
}

#customerModal .erp-type-grid{
    display:grid !important;
    grid-template-columns:repeat(3,1fr) !important;
    gap:8px !important;
}

#customerModal .erp-type-card{
    background:rgba(15,23,42,.44) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:11px !important;
    padding:9px 10px !important;
}

#customerModal .erp-type-card strong{
    color:#f8fafc !important;
    font-size:13px !important;
    font-weight:900 !important;
}

#importModal .erp-note-box{
    background:rgba(15,23,42,.44) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    color:#cbd5e1 !important;
    border-radius:12px !important;
    font-weight:800 !important;
}

#importModal .erp-note-box strong{
    color:#a78bfa !important;
}

#customerModal .erp-left-note,
#importModal .erp-left-note{
    color:#94a3b8 !important;
    font-weight:800 !important;
    font-size:12px !important;
}

#customerModal .erp-cancel-btn,
#importModal .erp-cancel-btn{
    height:36px !important;
    min-width:95px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.18) !important;
}

#customerModal .erp-save-btn,
#importModal .erp-save-btn,
#importModal .erp-submit-green{
    height:36px !important;
    min-width:110px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(109,76,255,.24) !important;
}

#customerModal .erp-cancel-btn:hover,
#customerModal .erp-save-btn:hover,
#importModal .erp-cancel-btn:hover,
#importModal .erp-save-btn:hover,
#importModal .erp-submit-green:hover{
    transform:translateY(-1px) !important;
}

/* Close button */
#customerModal .btn-close,
#importModal .btn-close{
    width:32px !important;
    height:32px !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    background-image:none !important;
    position:relative !important;
    padding:0 !important;
}

#customerModal .btn-close::before,
#customerModal .btn-close::after,
#importModal .btn-close::before,
#importModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:15px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#customerModal .btn-close::before,
#importModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#customerModal .btn-close::after,
#importModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#customerModal .btn-close:hover,
#importModal .btn-close:hover{
    background:rgba(239,68,68,.24) !important;
}

/* ================= RESPONSIVE ================= */
@media(max-width:1200px){
    .stats-row{
        grid-template-columns:repeat(2,1fr) !important;
    }

    .filter-bar{
        grid-template-columns:1fr 1fr !important;
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
        grid-template-columns:1fr !important;
    }

    .header-actions{
        width:100% !important;
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .btn-soft,
    .quick-btn,
    .filter-btn,
    .filter-control,
    .search-wrap{
        width:100% !important;
        min-width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .table-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .bulk-delete-btn{
        width:100% !important;
    }

    #customerModal .modal-dialog,
    #importModal .modal-dialog{
        width:calc(100% - 18px) !important;
        max-width:calc(100% - 18px) !important;
        margin:.65rem auto !important;
    }

    #customerModal .erp-modal-body,
    #importModal .erp-modal-body{
        max-height:64vh !important;
    }

    #customerModal .erp-type-grid{
        grid-template-columns:1fr !important;
    }

    #customerModal .erp-modal-footer,
    #importModal .erp-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #customerModal .erp-footer-btns,
    #importModal .erp-footer-btns{
        width:100% !important;
        flex-direction:column !important;
    }

    #customerModal .erp-cancel-btn,
    #customerModal .erp-save-btn,
    #importModal .erp-cancel-btn,
    #importModal .erp-submit-green{
        width:100% !important;
    }
}

/* =====================================================
   CUSTOMERS TABLE WHITE HOVER / SELECT FIX
   Paste at VERY END before
===================================================== */

/* Table base transparent rakho */
.customers-table,
.customers-table thead,
.customers-table tbody,
.customers-table tr,
.customers-table th,
.customers-table td{
    background-color:transparent !important;
}

/* Normal row glass dark */
.customers-table tbody tr,
.customers-table tbody tr td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.30), rgba(17,24,39,.22)) !important;
    color:#e5e7eb !important;
}

/* Mouse hover par white remove */
.customers-table tbody tr:hover,
.customers-table tbody tr:hover td,
.customers-table tbody td:hover{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color:#ffffff !important;
    box-shadow:none !important;
}

/* Checkbox selected / focus / active state white remove */
.customers-table tbody tr:has(input[type="checkbox"]:checked),
.customers-table tbody tr:has(input[type="checkbox"]:checked) td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),
        linear-gradient(135deg, rgba(109,76,255,.20), rgba(15,23,42,.40)) !important;
    color:#ffffff !important;
}

/* Bootstrap table hover override */
.table-hover tbody tr:hover,
.table-hover tbody tr:hover td,
.table tbody tr:hover,
.table tbody tr:hover td{
    background-color:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

/* Row ke andar text bhi white/dark theme readable rahe */
.customers-table tbody tr:hover .customer-name,
.customers-table tbody tr:has(input[type="checkbox"]:checked) .customer-name{
    color:#ffffff !important;
}

.customers-table tbody tr:hover .customer-email-sub,
.customers-table tbody tr:has(input[type="checkbox"]:checked) .customer-email-sub{
    color:#cbd5e1 !important;
}

/* Browser text selection white/blue feel ko purple transparent karo */
.customers-table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

/* Checkbox accent */
.customers-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* =====================================================
   CUSTOMERS.PHP - BULK ACTION DROPDOWN OVERLAY FIX
   Paste at VERY END before 
===================================================== */

/* Header buttons/dropdowns ko cards se upar lao */
.page-header,
.header-actions,
.header-actions .dropdown,
.header-actions .btn-group{
    position:relative !important;
    z-index:9999 !important;
}

/* Bootstrap dropdown menu ko highest layer do */
.header-actions .dropdown-menu,
.dropdown-menu.show{
    position:absolute !important;
    z-index:10050 !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    border:1px solid rgba(139,92,246,.38) !important;
    border-radius:14px !important;
    box-shadow:
        0 24px 60px rgba(0,0,0,.55),
        0 0 35px rgba(124,58,237,.18) !important;
    backdrop-filter:blur(18px) saturate(140%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(140%) !important;
    overflow:hidden !important;
}

/* Dropdown text readable */
.header-actions .dropdown-item,
.dropdown-menu.show .dropdown-item{
    color:#e5e7eb !important;
    font-weight:800 !important;
    border-radius:10px !important;
    padding:9px 12px !important;
}

/* Dropdown hover */
.header-actions .dropdown-item:hover,
.dropdown-menu.show .dropdown-item:hover{
    background:rgba(124,58,237,.22) !important;
    color:#fff !important;
}

/* Danger item readable */
.header-actions .dropdown-item.text-danger{
    color:#f87171 !important;
}

.header-actions .dropdown-item.text-danger:hover{
    background:rgba(239,68,68,.16) !important;
    color:#fecaca !important;
}

/* Cards ko dropdown se neeche rakho */
.stats-row,
.stat-card,
.filter-bar,
.table-card{
    position:relative !important;
    z-index:1 !important;
}

/* Hover par bhi card dropdown ke upar na aaye */
.stat-card:hover,
.stat-card.active{
    z-index:2 !important;
}

/* Content wrapper pseudo layers dropdown ko block na karein */
.content-wrapper::before,
.content-wrapper::after{
    z-index:0 !important;
}

.content-wrapper > .page-header{
    z-index:9999 !important;
}

.content-wrapper > .stats-row,
.content-wrapper > .filter-bar,
.content-wrapper > .table-card{
    z-index:1 !important;
}


/* ================= CUSTOMER BUSINESS FEATURE OVERRIDES ================= */
.stats-row.stats-has-wholesale{
    grid-template-columns:repeat(3, 1fr) !important;
}

.stats-row.stats-no-wholesale{
    grid-template-columns:repeat(2, 1fr) !important;
}

#customerModal .erp-type-grid{
    grid-template-columns:repeat(2, 1fr) !important;
}

@media(max-width:768px){
    .stats-row.stats-has-wholesale,
    .stats-row.stats-no-wholesale,
    #customerModal .erp-type-grid{
        grid-template-columns:1fr !important;
    }
}

/* =========================================================
   CUSTOMERS PAGE - FINAL LIGHT MODE
   Must remain at the very end before 
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE LAYOUT
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .main-panel,
html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 78% 2%,
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
}

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

html[data-pos-theme="light"] body .content-wrapper::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.08),
        transparent 68%
    ) !important;
    opacity:.45 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Header buttons branded hi rahenge */
html[data-pos-theme="light"] body .btn-add{
    background:linear-gradient(135deg,#059669,#16a34a) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .btn-bulk{
    background:linear-gradient(135deg,#2563eb,#6d28d9) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .btn-import{
    background:linear-gradient(135deg,#0891b2,#06b6d4) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .btn-export{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.24) !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .title,
html[data-pos-theme="light"] body .stat-card .count,
html[data-pos-theme="light"] body .stat-card i{
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .stat-card.active,
html[data-pos-theme="light"] body .stat-card:hover{
    border-color:rgba(255,255,255,.40) !important;
    box-shadow:0 18px 42px rgba(15,23,42,.18) !important;
}

/* =========================================================
   FILTER BAR
========================================================= */

html[data-pos-theme="light"] body .filter-bar{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 12px 28px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Quick Actions */
html[data-pos-theme="light"] body .quick-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .quick-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Search */
html[data-pos-theme="light"] body .search-wrap input{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-wrap input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-wrap i{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .search-wrap input:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

/* Type select */
html[data-pos-theme="light"] body .filter-control{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .filter-control:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .filter-control option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Filter and Reset */
html[data-pos-theme="light"] body .filter-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .filter-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   DROPDOWNS
========================================================= */

html[data-pos-theme="light"] body .dropdown-menu,
html[data-pos-theme="light"] body .dropdown-menu.show,
html[data-pos-theme="light"] body .header-actions .dropdown-menu{
    background:#ffffff !important;
    background-image:none !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 18px 42px rgba(15,23,42,.16) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .dropdown-item,
html[data-pos-theme="light"] body .dropdown-menu.show .dropdown-item,
html[data-pos-theme="light"] body .header-actions .dropdown-item{
    background:transparent !important;
    color:#334155 !important;
}

html[data-pos-theme="light"] body .dropdown-item:hover,
html[data-pos-theme="light"] body .dropdown-menu.show .dropdown-item:hover,
html[data-pos-theme="light"] body .header-actions .dropdown-item:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
}

html[data-pos-theme="light"] body .dropdown-item.text-danger{
    color:#be123c !important;
}

html[data-pos-theme="light"] body .dropdown-item.text-danger:hover{
    background:#fff1f2 !important;
    color:#be123c !important;
}

/* =========================================================
   CUSTOMERS TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:0 16px 36px rgba(15,23,42,.09) !important;
}

/* Bootstrap dark table variables reset */
html[data-pos-theme="light"] body .customers-table,
html[data-pos-theme="light"] body .customers-table thead,
html[data-pos-theme="light"] body .customers-table tbody,
html[data-pos-theme="light"] body .customers-table tr,
html[data-pos-theme="light"] body .customers-table th,
html[data-pos-theme="light"] body .customers-table td{
    --bs-table-bg:transparent !important;
    --bs-table-accent-bg:transparent !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
}

/* Table header */
html[data-pos-theme="light"] body .customers-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom:1px solid #cbd5e1 !important;
    border-right:1px solid #e2e8f0 !important;
    box-shadow:none !important;
}

/* Normal rows */
html[data-pos-theme="light"] body
.customers-table tbody tr,

html[data-pos-theme="light"] body
.customers-table tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;
    color:#1e293b !important;
    border-top-color:#e8edf4 !important;
    box-shadow:none !important;
}

/* Row hover */
html[data-pos-theme="light"] body
.customers-table tbody tr:hover,

html[data-pos-theme="light"] body
.customers-table tbody tr:hover td,

html[data-pos-theme="light"] body
.customers-table tbody td:hover,

html[data-pos-theme="light"] body
.table-hover tbody tr:hover td,

html[data-pos-theme="light"] body
.table tbody tr:hover td{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;
    color:#0f172a !important;
    box-shadow:none !important;
}

/* Checked row */
html[data-pos-theme="light"] body
.customers-table tbody tr:has(input[type="checkbox"]:checked),

html[data-pos-theme="light"] body
.customers-table tbody tr:has(input[type="checkbox"]:checked) td{
    background:#f3efff !important;
    background-color:#f3efff !important;
    background-image:none !important;
    color:#0f172a !important;
}

/* Empty table */
html[data-pos-theme="light"] body
.customers-table td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Customer name */
html[data-pos-theme="light"] body .customer-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
.customers-table tbody tr:hover .customer-name,

html[data-pos-theme="light"] body
.customers-table tbody tr:has(input[type="checkbox"]:checked) .customer-name{
    color:#0f172a !important;
}

/* Email subtext */
html[data-pos-theme="light"] body .customer-email-sub{
    color:#64748b !important;
}

html[data-pos-theme="light"] body
.customers-table tbody tr:hover .customer-email-sub,

html[data-pos-theme="light"] body
.customers-table tbody tr:has(input[type="checkbox"]:checked) .customer-email-sub{
    color:#64748b !important;
}

/* Avatar */
html[data-pos-theme="light"] body .avatar-badge{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    box-shadow:0 8px 18px rgba(124,58,237,.18) !important;
}

/* Checkbox */
html[data-pos-theme="light"] body
.customers-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Text selection */
html[data-pos-theme="light"] body
.customers-table ::selection{
    background:rgba(124,58,237,.22) !important;
    color:#0f172a !important;
}

/* =========================================================
   CUSTOMER TYPE BADGES
========================================================= */

html[data-pos-theme="light"] body .walkin{
    background:#fffbeb !important;
    color:#b45309 !important;
    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .wholesale{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .retail{
    background:#eff6ff !important;
    color:#1d4ed8 !important;
    border:1px solid #bfdbfe !important;
}

/* =========================================================
   ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .batch-action-btn{
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}

html[data-pos-theme="light"] body .batch-action-edit{
    background:#fffbeb !important;
    color:#d97706 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] body .batch-action-edit:hover{
    background:#f59e0b !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .batch-action-delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body .batch-action-delete:hover{
    background:#ef4444 !important;
    color:#ffffff !important;
}

/* =========================================================
   TABLE FOOTER
========================================================= */

html[data-pos-theme="light"] body .table-footer{
    background:#f8fafc !important;
    color:#64748b !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .table-footer strong{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .bulk-delete-btn{
    background:linear-gradient(135deg,#e11d48,#ef4444) !important;
    color:#ffffff !important;
}

/* =========================================================
   ALERTS
========================================================= */

html[data-pos-theme="light"] body .alert{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#dce4ef !important;
    backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

/* =========================================================
   CUSTOMER + IMPORT MODALS
========================================================= */

html[data-pos-theme="light"] body
#customerModal .erp-modal-content,

html[data-pos-theme="light"] body
#importModal .erp-modal-content{
    background:#ffffff !important;
    background-image:none !important;
    color:#0f172a !important;
    border:1px solid #d8e0eb !important;
    box-shadow:0 28px 80px rgba(15,23,42,.20) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Modal headers */
html[data-pos-theme="light"] body
#customerModal .erp-modal-header,

html[data-pos-theme="light"] body
#importModal .erp-modal-header{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body
#customerModal .modal-title,

html[data-pos-theme="light"] body
#importModal .modal-title{
    color:#0f172a !important;
}

/* Modal bodies */
html[data-pos-theme="light"] body
#customerModal .erp-modal-body,

html[data-pos-theme="light"] body
#importModal .erp-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Modal footers */
html[data-pos-theme="light"] body
#customerModal .erp-modal-footer,

html[data-pos-theme="light"] body
#importModal .erp-modal-footer{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

/* Labels */
html[data-pos-theme="light"] body
#customerModal .erp-label,

html[data-pos-theme="light"] body
#importModal .erp-label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
#customerModal .erp-label span,

html[data-pos-theme="light"] body
#importModal .erp-label span{
    color:#e11d48 !important;
}

/* Inputs */
html[data-pos-theme="light"] body
#customerModal .erp-input,

html[data-pos-theme="light"] body
#customerModal .erp-select,

html[data-pos-theme="light"] body
#customerModal .erp-textarea,

html[data-pos-theme="light"] body
#importModal .erp-input,

html[data-pos-theme="light"] body
#importModal .erp-select,

html[data-pos-theme="light"] body
#importModal .erp-textarea{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#customerModal .erp-input::placeholder,

html[data-pos-theme="light"] body
#customerModal .erp-textarea::placeholder,

html[data-pos-theme="light"] body
#importModal .erp-input::placeholder,

html[data-pos-theme="light"] body
#importModal .erp-textarea::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body
#customerModal .erp-input:focus,

html[data-pos-theme="light"] body
#customerModal .erp-select:focus,

html[data-pos-theme="light"] body
#customerModal .erp-textarea:focus,

html[data-pos-theme="light"] body
#importModal .erp-input:focus,

html[data-pos-theme="light"] body
#importModal .erp-select:focus,

html[data-pos-theme="light"] body
#importModal .erp-textarea:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

/* Type cards */
html[data-pos-theme="light"] body
#customerModal .erp-type-card{
    background:#f8fafc !important;
    border-color:#dce4ef !important;
}

html[data-pos-theme="light"] body
#customerModal .erp-type-card strong{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#customerModal .erp-type-card span{
    color:#64748b !important;
}

/* Import note */
html[data-pos-theme="light"] body
#importModal .erp-note-box{
    background:#f8fafc !important;
    color:#475569 !important;
    border-color:#dce4ef !important;
}

html[data-pos-theme="light"] body
#importModal .erp-note-box strong{
    color:#5b21b6 !important;
}

/* Footer note */
html[data-pos-theme="light"] body
#customerModal .erp-left-note,

html[data-pos-theme="light"] body
#importModal .erp-left-note{
    color:#64748b !important;
}

/* Cancel buttons */
html[data-pos-theme="light"] body
#customerModal .erp-cancel-btn,

html[data-pos-theme="light"] body
#importModal .erp-cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

/* Save and import buttons */
html[data-pos-theme="light"] body
#customerModal .erp-save-btn,

html[data-pos-theme="light"] body
#importModal .erp-save-btn,

html[data-pos-theme="light"] body
#importModal .erp-submit-green{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
}

/* Close buttons */
html[data-pos-theme="light"] body
#customerModal .btn-close,

html[data-pos-theme="light"] body
#importModal .btn-close{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
#customerModal .btn-close::before,

html[data-pos-theme="light"] body
#customerModal .btn-close::after,

html[data-pos-theme="light"] body
#importModal .btn-close::before,

html[data-pos-theme="light"] body
#importModal .btn-close::after{
    background:#334155 !important;
}

/* Modal scrollbar */
html[data-pos-theme="light"] body
#customerModal .erp-modal-body::-webkit-scrollbar-track,

html[data-pos-theme="light"] body
#importModal .erp-modal-body::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"] body
#customerModal .erp-modal-body::-webkit-scrollbar-thumb,

html[data-pos-theme="light"] body
#importModal .erp-modal-body::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
}

/* Modal backdrop */
html[data-pos-theme="light"] body.modal-open
.modal-backdrop.show{
    background:#0f172a !important;
    opacity:.45 !important;
}

</style>
</head>
<body>

<div class="container-scroll">
    <div class="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">

            <?php if ($msg !== ''): ?>
                <div class="alert alert-<?= e($msgType) ?> alert-dismissible fade show" role="alert">
                    <?= e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="page-header">
                <h1 class="page-title">Customers</h1>

                <div class="header-actions">
                    <button class="btn-soft btn-add" type="button" onclick="openCustomerModal()">
                        <i class="bi bi-plus-lg"></i> Add New Customer
                    </button>

                    <div class="dropdown">
                        <button class="btn-soft btn-bulk dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-list-check"></i> Bulk Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="selectAllRows(event)">Select All</a></li>
                            <li><a class="dropdown-item" href="#" onclick="unselectAllRows(event)">Unselect All</a></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteSelected(event)">Delete Selected</a></li>
                        </ul>
                    </div>

                    <button class="btn-soft btn-import" type="button" onclick="openImportModal()">
                        <i class="bi bi-download"></i> Import
                    </button>

                    <button class="btn-soft btn-export" type="button" onclick="exportTable()">
                        <i class="bi bi-upload"></i> Export
                    </button>
                </div>
            </div>

            <div class="stats-row <?= $wholesaleEnabled ? 'stats-has-wholesale' : 'stats-no-wholesale' ?>">
                <div class="stat-card card-blue <?= $typeFilter === '' ? 'active' : '' ?>" data-type="">
                    <div><div class="title"><i class="bi bi-people me-2"></i> Total Customers</div></div>
                    <div class="count"><?= (int)$totalCustomers ?></div>
                </div>

                <?php if ($wholesaleEnabled): ?>
                    <div class="stat-card card-green <?= $typeFilter === 'Wholesaler' ? 'active' : '' ?>" data-type="Wholesaler">
                        <div><div class="title"><i class="bi bi-briefcase me-2"></i> Wholesale Customers</div></div>
                        <div class="count"><?= (int)$wholesaleCustomers ?></div>
                    </div>
                <?php endif; ?>

                <div class="stat-card card-cyan <?= $typeFilter === 'Retailer' ? 'active' : '' ?>" data-type="Retailer">
                    <div><div class="title"><i class="bi bi-shop me-2"></i> Retail / Walk-In Customers</div></div>
                    <div class="count"><?= (int)$retailCustomers ?></div>
                </div>
            </div>

            <form method="GET" class="filter-bar" id="filterForm">
                <div class="dropdown">
                    <button class="quick-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-lightning-charge-fill me-1"></i> Quick Actions
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="selectAllRows(event)">Select All</a></li>
                        <li><a class="dropdown-item" href="#" onclick="unselectAllRows(event)">Unselect All</a></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="deleteSelected(event)">Delete Selected</a></li>
                    </ul>
                </div>

                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="searchInput" placeholder="Search by name, phone, email, address..." value="<?= e($search) ?>">
                </div>

                <select name="type" class="filter-control" id="typeFilter">
                    <option value="">All Types</option>
                    <?php if ($wholesaleEnabled): ?>
                        <option value="Wholesaler" <?= $typeFilter === 'Wholesaler' ? 'selected' : '' ?>>Wholesale</option>
                    <?php endif; ?>
                    <option value="Retailer" <?= $typeFilter === 'Retailer' ? 'selected' : '' ?>>Retail / Walk-In</option>
                </select>

                <button type="submit" class="filter-btn">
                    <i class="bi bi-funnel"></i> Filter
                </button>

                <a href="customers.php" class="filter-btn">
                    <i class="bi bi-arrow-clockwise"></i> Reset
                </a>
            </form>

            <div class="table-card">
                <div class="table-responsive">
                    <table class="customers-table" id="customersTable">
                        <thead>
                            <tr>
                                <th style="width:50px;">
                                    <input type="checkbox" id="checkAll">
                                </th>
                                <th style="width:70px;">ID</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Address</th>
                                <th style="width:130px;">Type</th>
                                <th style="width:120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No customers found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $c): ?>
                                    <?php
                                        $typeClass = '';
                                        $typeValue = trim((string)($c['type'] ?? ''));

                                        if (strcasecmp($typeValue, 'Walk-in') === 0) {
                                            $typeClass = 'walkin';
                                        } elseif (strcasecmp($typeValue, 'Wholesaler') === 0) {
                                            $typeClass = 'wholesale';
                                        } elseif (strcasecmp($typeValue, 'Retailer') === 0) {
                                            $typeClass = 'retail';
                                        }
                                    ?>
                                    <tr class="customer-row" data-type="<?= e($typeValue) ?>">
                                        <td><input type="checkbox" class="rowCheck" value="<?= (int)$c['id'] ?>"></td>
                                        <td><?= (int)$c['id'] ?></td>
                                        <td>
                                            <div class="customer-name-wrap">
                                                <span class="avatar-badge"><?= e(customerInitials($c['name'] ?? '')) ?></span>
                                                <div>
                                                    <div class="customer-name"><?= e($c['name'] ?? '') ?></div>
                                                    <div class="customer-email-sub"><?= e($c['email'] ?? '') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= e($c['phone'] ?? '') ?></td>
                                        <td><?= e($c['email'] ?? '') ?></td>
                                        <td><?= e($c['address'] ?? '') ?></td>
                                        <td><span class="badge-type <?= $typeClass ?>"><?= e($typeValue) ?></span></td>
                                      <td>
    <div class="batch-actions-wrap">
        <button
            type="button"
            class="batch-action-btn batch-action-edit"
            onclick="editCustomer(<?= (int)$c['id'] ?>)"
            title="Edit"
        >
            <i class="bi bi-pencil-fill"></i>
        </button>

        <button
            type="button"
            class="batch-action-btn batch-action-delete"
            onclick="deleteCustomer(<?= (int)$c['id'] ?>)"
            title="Delete"
        >
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

                <div class="table-footer">
                    <div><span>Total Records: <strong><?= count($customers) ?></strong></span></div>
                    <div>
                        <button type="button" class="bulk-delete-btn" onclick="deleteSelected(event)">
                            Delete Selected
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content">
            <form method="POST" id="customerForm">
                <input type="hidden" name="action" id="customerFormAction" value="add_customer">
                <input type="hidden" name="id" id="customer_id">

                <div class="modal-header erp-modal-header">
                    <h5 class="modal-title" id="customerModalTitle">Add New Customer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body erp-modal-body">
                    <div class="row g-4 align-items-start">
                        <div class="col-lg-4">
                            <div class="erp-preview-card">
                                <div class="erp-preview-box">
                                    <i class="bi bi-person-vcard-fill"></i>
                                    <div class="erp-preview-title">Customer Details</div>
                                    <div class="erp-preview-subtitle">Create and manage customer profile</div>
                                </div>

                                <div class="erp-modal-note">
                                    Retail / Walk-In customers Retailer type me save honge. Wholesale option sirf Wholesale Pricing feature active hone par show hoga.
                                    Same modal add aur edit dono ke liye use ho raha hai.
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="erp-label">Customer Name <span>*</span></label>
                                    <input type="text" class="erp-input" name="name" id="customer_name" required placeholder="Enter customer name">
                                </div>

                                <div class="col-md-6">
                                    <label class="erp-label">Phone</label>
                                    <input type="text" class="erp-input" name="phone" id="customer_phone" placeholder="Enter phone number">
                                </div>

                                <div class="col-md-6">
                                    <label class="erp-label">Email</label>
                                    <input type="email" class="erp-input" name="email" id="customer_email" placeholder="Enter email address">
                                </div>

                                <div class="col-md-6">
                                    <label class="erp-label">Type</label>
                                    <select class="erp-select" name="type" id="customer_type">
                                        <option value="Retailer">Retail / Walk-In</option>
                                        <?php if ($wholesaleEnabled): ?>
                                            <option value="Wholesaler">Wholesale</option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="erp-label">Address</label>
                                    <textarea class="erp-textarea" name="address" id="customer_address" rows="3" placeholder="Enter full address"></textarea>
                                </div>

                                <div class="col-12">
                                    <div class="erp-type-grid">
                                        <div class="erp-type-card">
                                            <strong>Retail / Walk-In</strong>
                                            <span>Walk-In customer bhi Retailer type me save hoga.</span>
                                        </div>

                                        <?php if ($wholesaleEnabled): ?>
                                            <div class="erp-type-card">
                                                <strong>Wholesale</strong>
                                                <span>Wholesale Pricing feature active hone par available.</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer erp-modal-footer">
                    <div class="erp-left-note">Create or update customer details.</div>

                    <div class="erp-footer-btns">
                        <button class="erp-cancel-btn" type="button" data-bs-dismiss="modal">Cancel</button>
                        <button class="erp-save-btn" type="submit" id="customerSubmitBtn">Save Customer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>  

<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content erp-modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_customers">

                <div class="modal-header erp-modal-header">
                    <h5 class="modal-title">Import Customers</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body erp-modal-body">
                    <div class="row g-4 align-items-start">
                        <div class="col-lg-4">
                            <div class="erp-preview-card">
                                <div class="erp-preview-box">
                                    <i class="bi bi-file-earmark-arrow-up-fill"></i>
                                    <div class="erp-preview-title">Upload CSV File</div>
                                    <div class="erp-preview-subtitle">Import customer records in bulk</div>
                                </div>

                                <div class="erp-modal-note">
                                    File sirf CSV format mein honi chahiye. Har row ek naya customer create karegi.
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="erp-label">Choose CSV File <span>*</span></label>
                                    <input type="file" class="erp-input" name="import_file" accept=".csv" required>
                                </div>

                                <div class="col-12">
                                    <div class="erp-note-box sample-note">
                                        CSV format:<br>
                                        <strong>name, phone, email, address, type</strong><br><br>
                                        Example:<br>
                                        Ali Khan,03001234567,ali@example.com,Karachi,Retailer
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer erp-modal-footer">
                    <div class="erp-left-note">Import customer data using CSV file.</div>

                    <div class="erp-footer-btns">
                        <button class="erp-cancel-btn" type="button" data-bs-dismiss="modal">Cancel</button>
                        <button class="erp-submit-green" type="submit">Import File</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="deleteSingleForm" style="display:none;">
    <input type="hidden" name="action" value="delete_customer">
    <input type="hidden" name="id" id="delete_customer_id">
</form>

<form method="POST" id="bulkDeleteForm" style="display:none;">
    <input type="hidden" name="action" value="bulk_delete">
    <div id="bulkDeleteInputs"></div>
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const customersData = <?= $customersJson ?: '[]' ?>;
const customerModalEl = document.getElementById('customerModal');
const importModalEl = document.getElementById('importModal');
const customerModal = customerModalEl ? new bootstrap.Modal(customerModalEl) : null;
const importModal = importModalEl ? new bootstrap.Modal(importModalEl) : null;

document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('checkAll');
    const rowChecks = document.querySelectorAll('.rowCheck');
    const statCards = document.querySelectorAll('.stat-card');
    const typeSelect = document.getElementById('typeFilter');

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            rowChecks.forEach(chk => chk.checked = this.checked);
        });
    }

    rowChecks.forEach(chk => {
        chk.addEventListener('change', function () {
            const all = document.querySelectorAll('.rowCheck');
            const checked = document.querySelectorAll('.rowCheck:checked');
            if (checkAll) {
                checkAll.checked = all.length > 0 && all.length === checked.length;
            }
        });
    });

    statCards.forEach(card => {
        card.addEventListener('click', function () {
            const type = this.dataset.type || '';
            if (typeSelect) {
                typeSelect.value = type;
            }
            document.getElementById('filterForm').submit();
        });
    });
});

function openCustomerModal() {
    document.getElementById('customerModalTitle').textContent = 'Add New Customer';
    document.getElementById('customerFormAction').value = 'add_customer';
    document.getElementById('customerSubmitBtn').textContent = 'Save Customer';
    document.getElementById('customer_id').value = '';
    document.getElementById('customer_name').value = '';
    document.getElementById('customer_phone').value = '';
    document.getElementById('customer_email').value = '';
    document.getElementById('customer_address').value = '';
    document.getElementById('customer_type').value = 'Retailer';
    if (customerModal) customerModal.show();
}

function openImportModal() {
    if (importModal) importModal.show();
}

function editCustomer(id) {
    const customer = customersData.find(item => parseInt(item.id) === parseInt(id));
    if (!customer) {
        alert('Customer data not found.');
        return;
    }

    document.getElementById('customerModalTitle').textContent = 'Edit Customer';
    document.getElementById('customerFormAction').value = 'update_customer';
    document.getElementById('customerSubmitBtn').textContent = 'Update Customer';
    document.getElementById('customer_id').value = customer.id || '';
    document.getElementById('customer_name').value = customer.name || '';
    document.getElementById('customer_phone').value = customer.phone || '';
    document.getElementById('customer_email').value = customer.email || '';
    document.getElementById('customer_address').value = customer.address || '';
    const customerTypeSelect = document.getElementById('customer_type');
    const desiredCustomerType = customer.type || 'Retailer';
    const hasDesiredCustomerType = Array.from(customerTypeSelect.options).some(option => option.value === desiredCustomerType);
    customerTypeSelect.value = hasDesiredCustomerType ? desiredCustomerType : 'Retailer';

    if (customerModal) customerModal.show();
}

function deleteCustomer(id) {
    if (!confirm('Are you sure you want to delete this customer?')) {
        return;
    }
    document.getElementById('delete_customer_id').value = id;
    document.getElementById('deleteSingleForm').submit();
}

function selectAllRows(e) {
    if (e) e.preventDefault();
    document.querySelectorAll('.rowCheck').forEach(chk => chk.checked = true);
    const checkAll = document.getElementById('checkAll');
    if (checkAll) checkAll.checked = true;
}

function unselectAllRows(e) {
    if (e) e.preventDefault();
    document.querySelectorAll('.rowCheck').forEach(chk => chk.checked = false);
    const checkAll = document.getElementById('checkAll');
    if (checkAll) checkAll.checked = false;
}

function deleteSelected(e) {
    if (e) e.preventDefault();

    const selected = Array.from(document.querySelectorAll('.rowCheck:checked')).map(chk => chk.value);

    if (selected.length === 0) {
        alert('Please select at least one customer.');
        return;
    }

    if (!confirm('Are you sure you want to delete selected customers?')) {
        return;
    }

    const container = document.getElementById('bulkDeleteInputs');
    container.innerHTML = '';

    selected.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        container.appendChild(input);
    });

    document.getElementById('bulkDeleteForm').submit();
}

function exportTable() {
    const search = document.getElementById('searchInput').value || '';
    const type = document.getElementById('typeFilter').value || '';
    const url = new URL(window.location.href);

    url.searchParams.set('export', 'csv');

    if (search !== '') {
        url.searchParams.set('search', search);
    } else {
        url.searchParams.delete('search');
    }

    if (type !== '') {
        url.searchParams.set('type', type);
    } else {
        url.searchParams.delete('type');
    }

    window.location.href = url.toString();
}
</script>

</body>
</html>