<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

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

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_with_message(string $type, string $message): void
{
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message) . "&msg_type=" . urlencode($type));
    exit;
}

function normalize_status(string $status): string
{
    return in_array($status, ['active', 'disabled'], true) ? $status : 'active';
}

function normalize_discount_type(string $type): string
{
    $allowed = ['percentage', 'fixed'];
    return in_array($type, $allowed, true) ? $type : 'fixed';
}

function normalize_date(?string $date): ?string
{
    $date = trim((string)$date);
    if ($date === '') {
        return null;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : null;
}

/* ===================== HANDLE POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_discount') {
        $code           = strtoupper(trim($_POST['code'] ?? ''));
        $discount_type  = normalize_discount_type(trim($_POST['discount_type'] ?? 'fixed'));
        $discount_value = (float)($_POST['discount_value'] ?? 0);
        $min_bill       = (float)($_POST['min_bill'] ?? 0);
        $usage_limit    = (int)($_POST['usage_limit'] ?? 0);
        $used_count     = (int)($_POST['used_count'] ?? 0);
        $expiry_date    = normalize_date($_POST['expiry_date'] ?? '');
        $status         = normalize_status(trim($_POST['status'] ?? 'active'));

        if ($code === '') {
            redirect_with_message('danger', 'Discount code is required.');
        }

        if ($discount_value <= 0) {
            redirect_with_message('danger', 'Discount value must be greater than 0.');
        }

        if ($discount_type === 'percentage' && $discount_value > 100) {
            redirect_with_message('danger', 'Percentage discount cannot be greater than 100.');
        }

        $checkStmt = $conn->prepare("SELECT id FROM coupons WHERE client_id = ? AND code = ? LIMIT 1");
        $checkStmt->bind_param("is", $client_id, $code);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows > 0) {
            $checkStmt->close();
            redirect_with_message('danger', 'This discount code already exists.');
        }
        $checkStmt->close();

        $stmt = $conn->prepare("
            INSERT INTO coupons
            (client_id, code, discount_type, discount_value, min_bill, usage_limit, used_count, expiry_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "issddiiss",
            $client_id,
            $code,
            $discount_type,
            $discount_value,
            $min_bill,
            $usage_limit,
            $used_count,
            $expiry_date,
            $status
        );
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Discount code added successfully.');
    }

    if ($action === 'update_discount') {
        $id             = (int)($_POST['id'] ?? 0);
        $code           = strtoupper(trim($_POST['code'] ?? ''));
        $discount_type  = normalize_discount_type(trim($_POST['discount_type'] ?? 'fixed'));
        $discount_value = (float)($_POST['discount_value'] ?? 0);
        $min_bill       = (float)($_POST['min_bill'] ?? 0);
        $usage_limit    = (int)($_POST['usage_limit'] ?? 0);
        $used_count     = (int)($_POST['used_count'] ?? 0);
        $expiry_date    = normalize_date($_POST['expiry_date'] ?? '');
        $status         = normalize_status(trim($_POST['status'] ?? 'active'));

        if ($id <= 0 || $code === '') {
            redirect_with_message('danger', 'Invalid discount data.');
        }

        if ($discount_value <= 0) {
            redirect_with_message('danger', 'Discount value must be greater than 0.');
        }

        if ($discount_type === 'percentage' && $discount_value > 100) {
            redirect_with_message('danger', 'Percentage discount cannot be greater than 100.');
        }

        $checkStmt = $conn->prepare("SELECT id FROM coupons WHERE client_id = ? AND code = ? AND id != ? LIMIT 1");
        $checkStmt->bind_param("isi", $client_id, $code, $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows > 0) {
            $checkStmt->close();
            redirect_with_message('danger', 'Another discount already has this code.');
        }
        $checkStmt->close();

        $stmt = $conn->prepare("
            UPDATE coupons
            SET code = ?, discount_type = ?, discount_value = ?, min_bill = ?, usage_limit = ?, used_count = ?, expiry_date = ?, status = ?
            WHERE id = ? AND client_id = ?
        ");
        $stmt->bind_param(
            "ssddiissii",
            $code,
            $discount_type,
            $discount_value,
            $min_bill,
            $usage_limit,
            $used_count,
            $expiry_date,
            $status,
            $id,
            $client_id
        );
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Discount code updated successfully.');
    }

    if ($action === 'delete_discount') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            redirect_with_message('danger', 'Invalid discount ID.');
        }

        $stmt = $conn->prepare("DELETE FROM coupons WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Discount code deleted successfully.');
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['selected_ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            redirect_with_message('danger', 'Please select at least one discount.');
        }

        $cleanIds = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $cleanIds[] = $id;
            }
        }

        if (empty($cleanIds)) {
            redirect_with_message('danger', 'No valid discounts selected.');
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $types = str_repeat('i', count($cleanIds)) . 'i';
        $params = array_merge($cleanIds, [$client_id]);

        $sql = "DELETE FROM coupons WHERE id IN ($placeholders) AND client_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $deletedRows = $stmt->affected_rows;
        $stmt->close();

        redirect_with_message('success', $deletedRows . ' discount(s) deleted successfully.');
    }

    if ($action === 'enable_disable_discount') {
        $id = (int)($_POST['id'] ?? 0);
        $current_status = normalize_status($_POST['status'] ?? 'active');
        $newStatus = $current_status === 'active' ? 'disabled' : 'active';

        if ($id <= 0) {
            redirect_with_message('danger', 'Invalid discount data.');
        }

        $stmt = $conn->prepare("UPDATE coupons SET status = ? WHERE id = ? AND client_id = ?");
        $stmt->bind_param("sii", $newStatus, $id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', "Discount status updated to {$newStatus}.");
    }

    if ($action === 'import_discounts') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            redirect_with_message('danger', 'Please choose a valid CSV file.');
        }

        $tmpName = $_FILES['import_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            redirect_with_message('danger', 'Only CSV files are allowed.');
        }

        $handle = fopen($tmpName, 'r');
        if (!$handle) {
            redirect_with_message('danger', 'Unable to read uploaded file.');
        }

        // skip header
        fgetcsv($handle);

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $code           = strtoupper(trim($row[0] ?? ''));
            $discount_type  = normalize_discount_type(trim($row[1] ?? 'fixed'));
            $discount_value = (float)($row[2] ?? 0);
            $min_bill       = (float)($row[3] ?? 0);
            $usage_limit    = (int)($row[4] ?? 0);
            $used_count     = (int)($row[5] ?? 0);
            $expiry_date    = normalize_date($row[6] ?? '');
            $status         = normalize_status(trim($row[7] ?? 'active'));

            if ($code === '' || $discount_value <= 0) {
                $skipped++;
                continue;
            }

            if ($discount_type === 'percentage' && $discount_value > 100) {
                $skipped++;
                continue;
            }

            $checkStmt = $conn->prepare("SELECT id FROM coupons WHERE client_id = ? AND code = ? LIMIT 1");
            $checkStmt->bind_param("is", $client_id, $code);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if ($exists) {
                $skipped++;
                continue;
            }

            $stmt = $conn->prepare("
                INSERT INTO coupons
                (client_id, code, discount_type, discount_value, min_bill, usage_limit, used_count, expiry_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "issddiiss",
                $client_id,
                $code,
                $discount_type,
                $discount_value,
                $min_bill,
                $usage_limit,
                $used_count,
                $expiry_date,
                $status
            );

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

/* ===================== FLASH MESSAGE ===================== */
$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ===================== FILTERS ===================== */
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$sql = "SELECT * FROM coupons WHERE client_id = ?";
$params = [$client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (code LIKE ? OR discount_type LIKE ?)";
    $searchLike = '%' . $search . '%';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= "ss";
}

if (in_array($status_filter, ['active', 'disabled'], true)) {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$discounts = [];
while ($row = $res->fetch_assoc()) {
    $discounts[] = $row;
}
$stmt->close();

/* ===================== STATS ===================== */
$stats_sql = "
    SELECT 
        COUNT(*) AS total_discounts,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_discounts,
        SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) AS disabled_discounts,
        SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expiring_soon,
        SUM(discount_value) AS total_discount_value
    FROM coupons
    WHERE client_id = ?
";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_discounts      = (int)($stats['total_discounts'] ?? 0);
$active_discounts     = (int)($stats['active_discounts'] ?? 0);
$disabled_discounts   = (int)($stats['disabled_discounts'] ?? 0);
$expiring_soon        = (int)($stats['expiring_soon'] ?? 0);
$total_discount_value = (float)($stats['total_discount_value'] ?? 0);

/* ===================== EXPORT CSV ===================== */
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=discounts_export_' . date('Y-m-d_H-i-s') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Code', 'Type', 'Value', 'Min Bill', 'Usage Limit', 'Used Count', 'Expiry Date', 'Status']);

    $exportSql = "SELECT code, discount_type, discount_value, min_bill, usage_limit, used_count, expiry_date, status FROM coupons WHERE client_id = ? ORDER BY id DESC";
    $stmt = $conn->prepare($exportSql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }

    fclose($output);
    $stmt->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Discount Management</title>
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
    /* ===== MODAL THEME (ITEM STYLE) ===== */

.item-modal-content{
    background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
    color:#fff;
    border:none;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.35);
}

.item-modal-header{
    background:transparent;
    border-bottom:1px solid rgba(255,255,255,0.08);
    padding:18px 22px;
}

.item-modal-body{
    padding:20px 22px 10px;
}

.item-modal-footer{
    border-top:1px solid rgba(255,255,255,0.08);
    padding:16px 22px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:14px;
}

 ===== LABELS ===== 

.item-label{
    display:block;
    font-size:15px;
    margin-bottom:7px;
    color:#e4e8f8;
    font-weight:500;
}

.item-label span{
    color:#f4b21c;
}

 ===== INPUTS ===== 

.item-input{
    width:100%;
    border:1px solid rgba(255,255,255,0.10);
    background:#e9eaf3;
    color:#222;
    border-radius:8px;
    height:44px;
    padding:0 14px;
    outline:none;
    font-size:14px;
    transition:0.2s ease;
}

 focus effect 
.item-input:focus{
    border-color:#f0ae19;
    box-shadow:0 0 0 2px rgba(240,174,25,0.2);
}

 ===== SELECT FIX ===== 

select.item-input{
    padding-right:35px;
    appearance:none;
}

 ===== FOOTER ===== 

.footer-left-note{
    color:#dbe0f4;
    font-size:14px;
    font-weight:500;
}

 ===== BUTTONS ===== 

.footer-btns{
    display:flex;
    align-items:center;
    gap:10px;
}

.cancel-btn{
    height:42px;
    min-width:110px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
    color:#fff;
    font-weight:600;
    transition:0.2s;
}

.cancel-btn:hover{
    opacity:0.9;
}

.save-btn{
    height:42px;
    min-width:130px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
    color:#fff;
    font-weight:700;
    transition:0.2s;
}

.save-btn:hover{
    opacity:0.9;
}

 ===== MODAL TITLE ===== 

.item-modal-header .modal-title{
    font-weight:700;
    font-size:18px;
    color:#ffffff !important;
}

#discountModal .form-label{
    color:#ffffff !important;
    font-weight:600;
}

#discountModal .required-star{
    color:#f4b21c !important;
}
 ===== CLOSE BUTTON WHITE ===== 

.btn-close-white{
    filter:invert(1);
}

 ===== RESPONSIVE ===== 

@media (max-width: 768px){
    .item-modal-footer{
        justify-content:flex-end;
    }

    .footer-left-note{
        width:100%;
    }
}
        body{
            margin:0;
            background:#f3f4f8;
            font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }
        .container-scroll{display:flex;min-height:100vh;}
        .sidebar{width:270px;flex-shrink:0;}
        .main-panel{flex-grow:1;display:flex;flex-direction:column;min-width:0;}
        .topbar{min-height:70px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;background:#fff;padding:0;}
        .content-wrapper{padding:20px;flex-grow:1;overflow-y:auto;}
        .page-header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
        .page-title{font-size:28px;font-weight:700;color:#1f2937;margin:0;}
        .header-actions{display:flex;gap:10px;flex-wrap:wrap;}
        .btn-soft{
            border:none;border-radius:10px;padding:10px 16px;color:#fff;font-weight:600;font-size:14px;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);display:inline-flex;align-items:center;gap:8px;
        }
        .btn-add{ background:#2e9e59; }
        .btn-bulk{ background:#3f8efc; }
        .btn-import{ background:#2ea44f; }
        .btn-export{ background:#5c4fd6; }

        .stats-row{display:grid;grid-template-columns:repeat(5, 1fr);gap:14px;margin-bottom:16px;}
        .stat-card{
            color:#fff;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
        }
        .stat-card .title{font-size:15px;font-weight:500;}
        .stat-card .count{font-size:30px;font-weight:700;line-height:1;}
        .card-blue{ background:linear-gradient(135deg,#4095ff,#2f80ed); }
        .card-green{ background:linear-gradient(135deg,#49be88,#2ea769); }
        .card-cyan{ background:linear-gradient(135deg,#4ac9d6,#26b4c0); }
        .card-yellow{ background:linear-gradient(135deg,#f7c943,#f4b400); }
        .card-red{ background:linear-gradient(135deg,#ef4444,#dc2626); }

        .filter-bar{
            background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;display:flex;gap:10px;
            flex-wrap:wrap;align-items:center;margin-bottom:16px;
        }
        .search-wrap{flex:1.3;min-width:220px;position:relative;}
        .search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#6b7280;}
        .search-wrap input{
            width:100%;height:44px;border:1px solid #e5e7eb;border-radius:10px;padding:0 14px 0 38px;outline:none;background:#fff;
        }
        .filter-control{
            min-width:180px;height:44px;border:1px solid #e5e7eb;border-radius:10px;padding:0 12px;outline:none;background:#fff;
        }
        .filter-btn{
            height:44px;border:none;border-radius:10px;padding:0 18px;background:#1f2937;color:#fff;font-weight:600;text-decoration:none;
            display:inline-flex;align-items:center;justify-content:center;gap:6px;
        }

        .table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;}
        .table-responsive{overflow:auto;}
        .customers-table{width:100%;min-width:980px;border-collapse:collapse;}
        .customers-table thead th{
            background:#28344d;color:#fff;font-weight:600;font-size:14px;padding:14px 12px;border-right:1px solid rgba(255,255,255,0.08);white-space:nowrap;
        }
        .customers-table tbody td{
            padding:14px 12px;border-top:1px solid #edf0f5;vertical-align:middle;font-size:14px;color:#374151;
        }
        .customers-table tbody tr:hover{background:#fafbfc;}
        .table-footer{
            padding:14px;border-top:1px solid #e5e7eb;background:#fff;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;
        }
        .bulk-delete-btn{
            border:none;background:#ef4444;color:#fff;border-radius:8px;padding:10px 14px;font-size:14px;font-weight:600;
        }
                .actions-cell{
            min-width:170px;
            text-align:center;
        }

        .action-group{
            display:flex;
            align-items:center;
            justify-content:center;
            gap:10px;
            flex-wrap:wrap;
        }

        .icon-action{
            width:38px;
            height:38px;
            border:none;
            border-radius:50%;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:16px;
            line-height:1;
            text-decoration:none;
            cursor:pointer;
            transition:all .18s ease;
            box-shadow:0 4px 10px rgba(15,23,42,.06);
        }

        .icon-action:hover{
            transform:translateY(-2px);
        }

        .icon-action i{
            font-size:16px;
            line-height:1;
        }

    .icon-edit{
    background:#fff4d6;
    color:#e0a100;
}
.icon-edit:hover{
    background:#e0a100;
    color:#fff;
}

.icon-delete{
    background:#ffe3e3;
    color:#ef4444;
}
.icon-delete:hover{
    background:#ef4444;
    color:#fff;
}

.icon-toggle-disable{
    background:#fff4d6;
    color:#e0a100;
}
.icon-toggle-disable:hover{
    background:#e0a100;
    color:#fff;
}

.icon-toggle-enable{
    background:#e9f9ef;
    color:#16a34a;
}
.icon-toggle-enable:hover{
    background:#16a34a;
    color:#fff;
}

.icon-delete::before,
.icon-delete::after{
    content:none !important;
    display:none !important;
}
    
        .icon-toggle-disable{
            background:#fff4e5;
            color:#f59e0b;
        }

        .icon-toggle-disable:hover{
            background:#f59e0b;
            color:#fff;
        }

        .icon-toggle-enable{
            background:#e9f9ef;
            color:#16a34a;
        }

        .icon-toggle-enable:hover{
            background:#16a34a;
            color:#fff;
        }
        
        
        
        .modal-header.custom-header{background:linear-gradient(135deg,#111827,#374151);color:#fff;}
        .required-star{color:#ef4444;}
        .badge-soft{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;}
        .badge-active{background:#dcfce7;color:#166534;}
        .badge-disabled{background:#fee2e2;color:#991b1b;}

        @media (max-width:1100px){ .stats-row{grid-template-columns:repeat(2,1fr);} }
        @media (max-width:768px){
            .stats-row{grid-template-columns:1fr;}
            .filter-bar{flex-direction:column;align-items:stretch;}
            .search-wrap,.filter-control,.filter-btn{width:100%;min-width:100%;}
            .page-title{font-size:24px;}
            .sidebar{width:240px;}
        }
    

/* =========================================================
   DISCOUNT POPUP BLACK + GOLD + WHITE THEME OVERRIDE
   Add/Edit Discount Modal
   Backend/PHP/JS same - only popup theme
========================================================= */

.modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
}

#discountModal .modal-dialog{
    max-width:min(900px, 94vw) !important;
}

#discountModal .item-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.55) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 30px 90px rgba(0,0,0,.65) !important;
}

#discountModal .item-modal-header{
    background:linear-gradient(180deg,#161b28 0%, #0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
}

#discountModal .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

#discountModal .modal-title::before{
    content:'';
    width:13px;
    height:13px;
    border-radius:50%;
    background:#c9a84c;
    box-shadow:0 0 0 5px rgba(201,168,76,.15);
    display:inline-block;
    flex-shrink:0;
}

/* Proper visible white X button */
#discountModal .btn-close{
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
    filter:none !important;
}

#discountModal .btn-close::before,
#discountModal .btn-close::after{
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

#discountModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg);
}

#discountModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg);
}

#discountModal .btn-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px);
}

#discountModal .item-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#discountModal .item-modal-body::-webkit-scrollbar{
    width:7px;
}

#discountModal .item-modal-body::-webkit-scrollbar-track{
    background:#171c28;
    border-radius:999px;
}

#discountModal .item-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c;
    border-radius:999px;
}

#discountModal .item-modal-body::-webkit-scrollbar-thumb:hover{
    background:#e8bb34;
}

#discountModal .item-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.25) !important;
    padding:18px 24px 22px !important;
}

#discountModal .form-label,
#discountModal .item-label,
#discountModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#discountModal .required-star,
#discountModal .item-label span{
    color:#f5c742 !important;
}

#discountModal .item-input,
#discountModal .form-control,
#discountModal .form-select,
#discountModal input,
#discountModal select{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    min-height:50px !important;
    padding:10px 16px !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#discountModal input::placeholder{
    color:#8993a5 !important;
}

#discountModal .item-input:focus,
#discountModal .form-control:focus,
#discountModal .form-select:focus,
#discountModal input:focus,
#discountModal select:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24), 0 5px 14px rgba(0,0,0,.22) !important;
}

/* Footer note */
#discountModal .footer-left-note{
    color:#dbe0f4 !important;
    font-weight:800 !important;
}

/* Footer buttons */
#discountModal .btn-secondary,
#discountModal .cancel-btn{
    height:44px !important;
    min-width:110px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#discountModal .btn-secondary:hover,
#discountModal .cancel-btn:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#discountModal .btn-primary,
#discountModal .save-btn{
    height:44px !important;
    min-width:130px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#discountModal .btn-primary:hover,
#discountModal .save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px);
}

/* If duplicate discountModal markup exists, this keeps both modal bodies consistent */
#discountModal .row{
    row-gap:4px;
}

@media (max-width:768px){
    #discountModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #discountModal .modal-title{
        font-size:18px !important;
    }

    #discountModal .btn-close{
        width:42px !important;
        height:42px !important;
    }

    #discountModal .btn-secondary,
    #discountModal .btn-primary,
    #discountModal .cancel-btn,
    #discountModal .save-btn{
        width:100% !important;
    }
}

/* =====================================================
   DISCOUNT MANAGEMENT - DARK PURPLE GLASS THEME
   Paste at END before
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
    --oms-cyan:#06b6d4;
    --oms-red:#ef4444;
}

/* ================= BASE ================= */
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI", Tahoma, Geneva, Verdana, sans-serif !important;
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
    margin-bottom:20px !important;
}

.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
}

.header-actions{
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

/* ================= HEADER BUTTONS ================= */
.btn-soft{
    border:none !important;
    border-radius:13px !important;
    padding:11px 17px !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    cursor:pointer !important;
    min-height:46px !important;
    box-shadow:0 14px 28px rgba(0,0,0,.22) !important;
    transition:.22s ease !important;
}

.btn-soft:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

.btn-add{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

.btn-bulk{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
    border:1px solid rgba(139,92,246,.32) !important;
}

.btn-import{
    background:linear-gradient(135deg,#0891b2,#06b6d4) !important;
    border:1px solid rgba(6,182,212,.28) !important;
}

.btn-export{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border:1px solid rgba(139,92,246,.38) !important;
}

/* Dropdown dark */
.dropdown-menu{
    background:#0f172a !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:14px !important;
    box-shadow:0 20px 50px rgba(0,0,0,.45) !important;
    padding:8px !important;
}

.dropdown-item{
    color:#e5e7eb !important;
    border-radius:10px !important;
    font-weight:800 !important;
}

.dropdown-item:hover{
    background:rgba(124,58,237,.16) !important;
    color:#fff !important;
}

.dropdown-item.text-danger{
    color:#f87171 !important;
}

/* ================= ALERTS ================= */
.alert{
    border-radius:14px !important;
    font-weight:800 !important;
    backdrop-filter:blur(14px) !important;
    box-shadow:0 14px 28px rgba(0,0,0,.18) !important;
}

.alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.26) !important;
}

.alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

.alert-warning{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.26) !important;
}

/* ================= STAT CARDS ================= */
.stats-row{
    display:grid !important;
    grid-template-columns:repeat(5, 1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    border-radius:18px !important;
    padding:18px 18px !important;
    color:#fff !important;
    position:relative !important;
    overflow:visible !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    isolation:isolate !important;
    min-width:0 !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    border-radius:18px;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:0;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:12px;
    top:12px;
    width:68px;
    height:68px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
    z-index:0;
    opacity:.45;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card > div{
    position:relative !important;
    z-index:3 !important;
    min-width:0 !important;
}

.stat-card .title{
    font-size:14px !important;
    font-weight:900 !important;
    color:rgba(255,255,255,.96) !important;
    line-height:1.35 !important;
}

.stat-card .count{
    flex:1 !important;
    min-width:0 !important;
    font-size:clamp(18px, 1.65vw, 28px) !important;
    font-weight:900 !important;
    line-height:1.1 !important;
    text-align:right !important;
    letter-spacing:-.03em !important;
    color:#fff !important;
    word-break:break-word !important;
    overflow-wrap:anywhere !important;
    white-space:normal !important;
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
.card-green{ background:linear-gradient(135deg,#047857,#16a34a) !important; }
.card-red{ background:linear-gradient(135deg,#be123c,#ef4444) !important; }
.card-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c) !important; }
.card-cyan{ background:linear-gradient(135deg,#0891b2,#06b6d4) !important; }

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
    grid-template-columns:1fr 190px auto auto !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:18px !important;
    flex-wrap:nowrap !important;
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
    color:#94a3b8 !important;
    font-size:15px !important;
    z-index:2 !important;
}

.search-wrap input,
.filter-control{
    width:100% !important;
    min-width:0 !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
    box-shadow:none !important;
    transition:.2s !important;
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

.filter-btn{
    width:auto !important;
    min-width:118px !important;
    height:46px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    text-decoration:none !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
    white-space:nowrap !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.filter-btn:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

.filter-bar .filter-btn[style]{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 14px 28px rgba(0,0,0,.18) !important;
}

/* ================= TABLE ================= */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    overflow:hidden !important;
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
    min-width:1080px !important;
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

.customers-table tbody tr:hover td{
    background:rgba(124,58,237,.13) !important;
    color:#fff !important;
}

.customers-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.customers-table strong{
    color:#f8fafc !important;
}

.customers-table td.text-center,
.customers-table td[colspan]{
    color:#94a3b8 !important;
    background:rgba(15,23,42,.32) !important;
    font-weight:900 !important;
}

/* ================= BADGES ================= */
.badge-soft{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    min-width:86px !important;
    padding:7px 12px !important;
    border-radius:999px !important;
    font-size:12px !important;
    font-weight:900 !important;
    border:1px solid transparent !important;
}

.badge-active{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

.badge-disabled{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

/* ================= ACTION BUTTONS ================= */
.actions-cell{
    min-width:180px !important;
    text-align:center !important;
}

.action-group{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

.icon-action{
    width:40px !important;
    height:40px !important;
    border:none !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:16px !important;
    line-height:1 !important;
    text-decoration:none !important;
    cursor:pointer !important;
    transition:all .20s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    padding:0 !important;
}

.icon-action:hover{
    transform:translateY(-2px) !important;
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

.icon-toggle-disable{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.icon-toggle-disable:hover{
    background:#f59e0b !important;
    color:#07111f !important;
}

.icon-toggle-enable{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.icon-toggle-enable:hover{
    background:#22c55e !important;
    color:#07111f !important;
}

/* ================= FOOTER ================= */
.table-footer{
    padding:16px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.28) !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    flex-wrap:wrap !important;
    color:#94a3b8 !important;
    font-size:14px !important;
    font-weight:800 !important;
}

.table-footer strong{
    color:#f8fafc !important;
}

.bulk-delete-btn{
    border:none !important;
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
    color:#fff !important;
    border-radius:13px !important;
    padding:11px 16px !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(239,68,68,.20) !important;
}

/* =====================================================
   DISCOUNT MODAL - DARK PURPLE GLASS THEME
===================================================== */

.modal-backdrop.show{
    opacity:.76 !important;
    background:#000 !important;
}

#discountModal{
    z-index:99999 !important;
}

#discountModal .modal-dialog{
    max-width:min(900px, 94vw) !important;
}

#discountModal .item-modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.98), rgba(17,24,39,.96)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:20px !important;
    overflow:hidden !important;
    box-shadow:
        0 30px 90px rgba(0,0,0,.72),
        0 0 55px rgba(124,58,237,.16) !important;
}

#discountModal .item-modal-header{
    min-height:70px !important;
    padding:18px 22px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#discountModal .modal-title{
    color:#f8fafc !important;
    font-size:21px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
}

#discountModal .modal-title::before{
    display:none !important;
}

#discountModal .btn-close{
    width:42px !important;
    height:42px !important;
    border-radius:13px !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    position:relative !important;
    background-image:none !important;
}

#discountModal .btn-close::before,
#discountModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:20px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#discountModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#discountModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#discountModal .btn-close:hover{
    background:rgba(239,68,68,.22) !important;
    border-color:rgba(239,68,68,.35) !important;
}

#discountModal .item-modal-body{
    padding:24px 22px !important;
    background:rgba(15,23,42,.52) !important;
    color:#f8fafc !important;
    max-height:calc(100vh - 190px) !important;
    overflow-y:auto !important;
}

#discountModal .item-modal-footer{
    padding:16px 22px 18px !important;
    background:rgba(7,11,20,.48) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

#discountModal .form-label,
#discountModal .item-label,
#discountModal label{
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:7px !important;
}

#discountModal .required-star,
#discountModal .item-label span{
    color:#a78bfa !important;
}

#discountModal .item-input,
#discountModal .form-control,
#discountModal .form-select,
#discountModal input,
#discountModal select{
    background:#ffffff !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    min-height:48px !important;
    font-size:14px !important;
    font-weight:700 !important;
    padding:10px 14px !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#discountModal .item-input:focus,
#discountModal .form-control:focus,
#discountModal .form-select:focus,
#discountModal input:focus,
#discountModal select:focus{
    background:#fff !important;
    color:#111827 !important;
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 4px rgba(124,58,237,.22),
        0 10px 22px rgba(0,0,0,.18) !important;
}

#discountModal .footer-left-note{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

#discountModal .btn-secondary,
#discountModal .cancel-btn{
    min-height:46px !important;
    border-radius:12px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    padding:0 18px !important;
}

#discountModal .btn-secondary:hover,
#discountModal .cancel-btn:hover{
    background:rgba(148,163,184,.28) !important;
    color:#fff !important;
}

#discountModal .btn-primary,
#discountModal .save-btn{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:0 18px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#discountModal .btn-primary:hover,
#discountModal .save-btn:hover{
    transform:translateY(-1px) !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1350px){
    .stats-row{
        grid-template-columns:repeat(3,1fr) !important;
    }
}

@media (max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2,1fr) !important;
    }

    .filter-bar{
        grid-template-columns:1fr 1fr !important;
    }

    .filter-btn{
        width:100% !important;
    }
}

@media (max-width:991px){
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

    .sidebar .sidebar-inner,
    #dashboardSidebar .sidebar-inner,
    #sidebar .sidebar-inner{
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

@media (max-width:768px){
    .stats-row{
        grid-template-columns:1fr !important;
    }

    .filter-bar{
        grid-template-columns:1fr !important;
    }

    .search-wrap,
    .filter-control,
    .filter-btn{
        width:100% !important;
        min-width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .header-actions{
        width:100% !important;
    }

    .btn-soft{
        width:100% !important;
    }

    .stat-card{
        min-height:112px !important;
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .stat-card .count{
        width:100% !important;
        text-align:left !important;
        font-size:26px !important;
    }

    #discountModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #discountModal .item-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #discountModal .btn-secondary,
    #discountModal .btn-primary,
    #discountModal .cancel-btn,
    #discountModal .save-btn{
        width:100% !important;
    }
}
/* =====================================================
   FINAL FIX: DISCOUNTS HEADER BUTTONS / BULK OVERLAY FIX
   Paste at END before
===================================================== */

/* Header buttons ko stats cards se upar rakho */
.page-header{
    position:relative !important;
    z-index:50 !important;
    margin-bottom:26px !important;
    align-items:flex-start !important;
}

/* Right side actions ko proper layer do */
.header-actions{
    position:relative !important;
    z-index:80 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

/* Bulk dropdown button and menu should stay above cards */
.header-actions .dropdown,
.header-actions .btn-soft,
.btn-bulk,
.btn-add,
.btn-import,
.btn-export{
    position:relative !important;
    z-index:90 !important;
}

.dropdown-menu{
    z-index:99999 !important;
}

/* Stats cards ko header buttons ke neeche layer par rakho */
.stats-row{
    position:relative !important;
    z-index:5 !important;
    margin-top:0 !important;
    clear:both !important;
}

/* Stat card hover par bhi header buttons ke upar na aaye */
.stat-card,
.stat-card:hover{
    position:relative !important;
    z-index:5 !important;
}

/* Content wrapper background layers ko buttons se conflict na karne do */
.content-wrapper::before,
.content-wrapper::after{
    z-index:0 !important;
}

.content-wrapper > *{
    position:relative !important;
}

/* Header ko explicitly above all page cards */
.content-wrapper .page-header{
    z-index:100 !important;
}

.content-wrapper .stats-row,
.content-wrapper .filter-bar,
.content-wrapper .table-card{
    z-index:10 !important;
}

/* Agar 5 cards tight ho rahe hon to cards thore compact */
.stats-row{
    grid-template-columns:repeat(5, minmax(0, 1fr)) !important;
    gap:14px !important;
}

.stat-card{
    min-width:0 !important;
}

/* Total Discount Value card ka text button ke neeche na ghuse */
.stat-card .title{
    max-width:150px !important;
    line-height:1.25 !important;
}

.stat-card .count{
    white-space:nowrap !important;
    font-size:clamp(18px, 1.45vw, 26px) !important;
}

/* Medium screens par header actions ko separate row jaisa behave karwao */
@media (max-width:1450px){
    .page-header{
        display:flex !important;
        flex-direction:column !important;
        align-items:stretch !important;
        gap:14px !important;
        margin-bottom:18px !important;
    }

    .header-actions{
        justify-content:flex-start !important;
    }

    .stats-row{
        grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
    }
}

@media (max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width:768px){
    .header-actions{
        width:100% !important;
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .header-actions .dropdown,
    .header-actions .btn-soft{
        width:100% !important;
    }

    .stats-row{
        grid-template-columns:1fr !important;
    }
}
/* =====================================================
   DISCOUNTS MOBILE FINAL FIX
   Header buttons aligned + 2 cards per row + filter alignment
   Paste at VERY END before 
===================================================== */

@media (max-width:768px){

    body{
        overflow-x:hidden !important;
    }

    .content-wrapper{
        padding:16px 12px 24px 12px !important;
    }

    .page-header{
        display:flex !important;
        flex-direction:column !important;
        align-items:stretch !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .page-title{
        width:100% !important;
        font-size:24px !important;
        line-height:1.15 !important;
        margin:0 !important;
    }

    /* Header buttons mobile aligned */
    .header-actions{
        width:100% !important;
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:8px !important;
        align-items:center !important;
    }

    .header-actions .dropdown,
    .header-actions .btn-soft{
        width:100% !important;
        min-width:0 !important;
    }

    .header-actions .btn-soft{
        height:42px !important;
        min-height:42px !important;
        padding:0 8px !important;
        border-radius:13px !important;
        font-size:11px !important;
        line-height:1.1 !important;
        white-space:nowrap !important;
        justify-content:center !important;
    }

    .header-actions .btn-soft i{
        font-size:13px !important;
        margin-right:2px !important;
    }

    /* Cards 2 per row */
    .stats-row{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .stat-card{
        min-height:84px !important;
        border-radius:16px !important;
        padding:11px 9px !important;
        gap:6px !important;
        display:flex !important;
        flex-direction:column !important;
        align-items:flex-start !important;
        justify-content:center !important;
        overflow:hidden !important;
    }

    .stat-card > div{
        width:100% !important;
        min-width:0 !important;
        position:relative !important;
        z-index:3 !important;
    }

    .stat-card .title{
        max-width:100% !important;
        font-size:11px !important;
        line-height:1.15 !important;
        font-weight:900 !important;
        white-space:normal !important;
        word-break:break-word !important;
    }

    .stat-card .title i{
        font-size:12px !important;
        margin-right:3px !important;
    }

    .stat-card .count{
        width:100% !important;
        text-align:left !important;
        font-size:17px !important;
        line-height:1.05 !important;
        margin-top:5px !important;
        white-space:normal !important;
        word-break:break-word !important;
        overflow-wrap:anywhere !important;
        position:relative !important;
        z-index:3 !important;
    }

    .stat-card::after{
        width:46px !important;
        height:46px !important;
        right:7px !important;
        top:8px !important;
        border-radius:15px !important;
        opacity:.30 !important;
    }

    /* Last odd card full width for better look */
    .stats-row .stat-card:nth-child(5){
        grid-column:1 / -1 !important;
        min-height:78px !important;
    }

    /* Filter box mobile 2 column */
    .filter-bar{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:9px !important;
        padding:11px !important;
        border-radius:18px !important;
        margin-bottom:14px !important;
        align-items:center !important;
    }

    .filter-bar .search-wrap{
        grid-column:1 / -1 !important;
        width:100% !important;
        min-width:0 !important;
    }

    .search-wrap input{
        width:100% !important;
        min-width:0 !important;
        height:41px !important;
        border-radius:13px !important;
        font-size:11px !important;
        padding-left:38px !important;
        padding-right:10px !important;
    }

    .search-wrap i{
        left:13px !important;
        font-size:13px !important;
    }

    .filter-control{
        width:100% !important;
        min-width:0 !important;
        height:41px !important;
        border-radius:13px !important;
        font-size:11px !important;
        padding:0 10px !important;
    }

    /* Apply Filter + Reset one line */
    .filter-bar .filter-btn{
        width:100% !important;
        min-width:0 !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:11px !important;
        padding:0 8px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        white-space:nowrap !important;
    }

    .filter-bar .filter-btn i{
        font-size:12px !important;
        margin-right:2px !important;
    }

    .table-card{
        border-radius:18px !important;
        margin-top:0 !important;
    }

    .table-footer{
        gap:10px !important;
        align-items:stretch !important;
    }

    .table-footer > div{
        width:100% !important;
    }

    .bulk-delete-btn{
        width:100% !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:12px !important;
    }
}

/* Extra small 328px / 360px */
@media (max-width:380px){

    .content-wrapper{
        padding-left:10px !important;
        padding-right:10px !important;
    }

    .page-title{
        font-size:23px !important;
    }

    .header-actions{
        gap:7px !important;
    }

    .header-actions .btn-soft{
        height:40px !important;
        min-height:40px !important;
        font-size:10px !important;
        padding:0 6px !important;
    }

    .stats-row{
        gap:8px !important;
    }

    .stat-card{
        min-height:78px !important;
        padding:9px 8px !important;
    }

    .stat-card .title{
        font-size:10px !important;
    }

    .stat-card .count{
        font-size:15px !important;
    }

    .filter-bar{
        gap:8px !important;
        padding:10px !important;
    }

    .search-wrap input,
    .filter-control{
        height:39px !important;
        font-size:10px !important;
    }

    .filter-bar .filter-btn{
        height:40px !important;
        font-size:10px !important;
        padding:0 6px !important;
    }
}

/* =====================================================
   FINAL FIX: DISCOUNTS TABLE HOVER WHITE REMOVE
   Paste at VERY END before 
===================================================== */

/* old white hover ko kill karo */
.customers-table tbody tr:hover{
    background: transparent !important;
}

/* normal row background dark glass */
.customers-table tbody tr td{
    background:
        linear-gradient(135deg, rgba(17,24,49,.52), rgba(24,13,54,.34)) !important;
    color:#e5e7eb !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
}

/* hover par transparent purple glass */
.customers-table tbody tr:hover td{
    background:
        radial-gradient(circle at 18% 50%, rgba(124,58,237,.24), transparent 42%),
        linear-gradient(135deg, rgba(124,58,237,.18), rgba(15,23,42,.62)) !important;
    color:#ffffff !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.06),
        inset 0 -1px 0 rgba(167,139,250,.14) !important;
}

/* bootstrap/table hover ka white background bhi remove */
.table-hover > tbody > tr:hover > *,
.table > tbody > tr:hover > *,
.customers-table.table-hover > tbody > tr:hover > *{
    --bs-table-accent-bg: transparent !important;
    --bs-table-hover-bg: transparent !important;
    background-color: transparent !important;
}

/* checkbox cell bhi white na ho */
.customers-table tbody tr:hover td:first-child{
    background:
        linear-gradient(135deg, rgba(124,58,237,.18), rgba(15,23,42,.62)) !important;
}

/* badge hover par bhi light na ho */
.customers-table tbody tr:hover .badge-active{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.30) !important;
}

.customers-table tbody tr:hover .badge-disabled{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.30) !important;
}

/* =========================================================
   ONEERP DISCOUNT MANAGEMENT LIGHT MODE
   MUST REMAIN AT THE VERY END OF MAIN STYLE
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE AND LAYOUT
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll,
html[data-pos-theme="light"] body .main-panel{
    background:#f3f6fb !important;
}

/* Topbar */
html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    border-bottom-color:#e2e8f0 !important;
    box-shadow:0 4px 18px rgba(15,23,42,.06) !important;
}

/* Main page background */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.09),
            transparent 28%
        ),
        radial-gradient(
            circle at 78% 2%,
            rgba(59,130,246,.06),
            transparent 30%
        ),
        radial-gradient(
            circle at 92% 86%,
            rgba(245,158,11,.045),
            transparent 26%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 42%,
            #faf7ff 72%,
            #f8fafc 100%
        ) !important;
}

/* Background grid */
html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.035) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ) !important;
    opacity:.55 !important;
}

/* Dark purple glow ko soft kare */
html[data-pos-theme="light"] body .content-wrapper::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.08),
        transparent 68%
    ) !important;
    opacity:.42 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Header buttons branded rahenge */
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
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .title,
html[data-pos-theme="light"] body .stat-card .count,
html[data-pos-theme="light"] body .stat-card i{
    color:#ffffff !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .stat-card:hover{
    box-shadow:
        0 18px 42px rgba(15,23,42,.18),
        0 0 0 3px rgba(124,58,237,.08) !important;
}

/* =========================================================
   FILTER BAR
========================================================= */

html[data-pos-theme="light"] body .filter-bar{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.05),
            transparent 34%
        ),
        #ffffff !important;

    border-color:#dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Search and select */
html[data-pos-theme="light"] body .search-wrap input,
html[data-pos-theme="light"] body .filter-control{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-wrap input:hover,
html[data-pos-theme="light"] body .filter-control:hover{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] body .search-wrap input:focus,
html[data-pos-theme="light"] body .filter-control:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .search-wrap input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-wrap i{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .filter-control option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Apply Filter */
html[data-pos-theme="light"] body .filter-bar
.filter-btn:not([style]){
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
    box-shadow:0 10px 22px rgba(124,58,237,.18) !important;
}

/* Reset */
html[data-pos-theme="light"] body .filter-bar
.filter-btn[style]{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .filter-bar
.filter-btn[style]:hover{
    background:#e2e8f0 !important;
    color:#0f172a !important;
}

/* =========================================================
   BULK ACTION DROPDOWN
========================================================= */

html[data-pos-theme="light"] body .dropdown-menu{
    background:#ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 18px 42px rgba(15,23,42,.16) !important;
}

html[data-pos-theme="light"] body .dropdown-item{
    background:transparent !important;
    color:#334155 !important;
}

html[data-pos-theme="light"] body .dropdown-item:hover{
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
   TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.04),
            transparent 34%
        ),
        #ffffff !important;

    border-color:#dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:
        0 16px 36px rgba(15,23,42,.09),
        0 0 0 1px rgba(124,58,237,.07) !important;
}

/* Table */
html[data-pos-theme="light"] body .customers-table{
    background:#ffffff !important;
}

/* Table headings */
html[data-pos-theme="light"] body
.customers-table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom-color:#cbd5e1 !important;
    border-right-color:#e2e8f0 !important;
    opacity:1 !important;
}

/* Table rows: final dark hover override ko bhi replace karega */
html[data-pos-theme="light"] body
.customers-table tbody tr td{
    background:#ffffff !important;
    background-image:none !important;
    color:#1e293b !important;
    border-top-color:#e8edf4 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

/* Row hover */
html[data-pos-theme="light"] body
.customers-table tbody tr:hover td,

html[data-pos-theme="light"] body
.customers-table.table-hover tbody tr:hover td,

html[data-pos-theme="light"] body
.table-hover > tbody > tr:hover > *{
    background:#f6f2ff !important;
    background-image:none !important;
    background-color:#f6f2ff !important;
    color:#0f172a !important;
    box-shadow:none !important;
    --bs-table-accent-bg:#f6f2ff !important;
    --bs-table-hover-bg:#f6f2ff !important;
}

/* Strong values */
html[data-pos-theme="light"] body
.customers-table strong{
    color:#0f172a !important;
}

/* Empty table row */
html[data-pos-theme="light"] body
.customers-table td.text-center,

html[data-pos-theme="light"] body
.customers-table td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Checkbox */
html[data-pos-theme="light"] body
.customers-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* =========================================================
   STATUS BADGES
========================================================= */

html[data-pos-theme="light"] body .badge-active,
html[data-pos-theme="light"] body
.customers-table tbody tr:hover .badge-active{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .badge-disabled,
html[data-pos-theme="light"] body
.customers-table tbody tr:hover .badge-disabled{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

/* =========================================================
   ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .icon-action{
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}

/* Edit */
html[data-pos-theme="light"] body .icon-edit{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

/* Delete */
html[data-pos-theme="light"] body .icon-delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

/* Disable */
html[data-pos-theme="light"] body .icon-toggle-disable{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

/* Enable */
html[data-pos-theme="light"] body .icon-toggle-enable{
    background:#ecfdf5 !important;
    color:#059669 !important;
    border-color:#a7f3d0 !important;
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

/* Delete selected button */
html[data-pos-theme="light"] body .bulk-delete-btn{
    background:linear-gradient(135deg,#e11d48,#ef4444) !important;
    color:#ffffff !important;
}

/* =========================================================
   ALERTS
========================================================= */

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

html[data-pos-theme="light"] body .alert-warning{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

/* =========================================================
   DISCOUNT MODAL LIGHT MODE
========================================================= */

html[data-pos-theme="light"] body
#discountModal .item-modal-content{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#d8e0eb !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.20),
        0 0 0 1px rgba(124,58,237,.06) !important;
}

/* Modal header */
html[data-pos-theme="light"] body
#discountModal .item-modal-header{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body
#discountModal .modal-title{
    color:#0f172a !important;
}

/* Modal body */
html[data-pos-theme="light"] body
#discountModal .item-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Modal footer */
html[data-pos-theme="light"] body
#discountModal .item-modal-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

/* Labels */
html[data-pos-theme="light"] body
#discountModal .form-label,

html[data-pos-theme="light"] body
#discountModal .item-label,

html[data-pos-theme="light"] body
#discountModal label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
#discountModal .required-star,

html[data-pos-theme="light"] body
#discountModal .item-label span{
    color:#e11d48 !important;
}

/* Inputs */
html[data-pos-theme="light"] body
#discountModal .item-input,

html[data-pos-theme="light"] body
#discountModal .form-control,

html[data-pos-theme="light"] body
#discountModal .form-select,

html[data-pos-theme="light"] body
#discountModal input,

html[data-pos-theme="light"] body
#discountModal select{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

/* Input focus */
html[data-pos-theme="light"] body
#discountModal .item-input:focus,

html[data-pos-theme="light"] body
#discountModal .form-control:focus,

html[data-pos-theme="light"] body
#discountModal .form-select:focus,

html[data-pos-theme="light"] body
#discountModal input:focus,

html[data-pos-theme="light"] body
#discountModal select:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

html[data-pos-theme="light"] body
#discountModal input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body
#discountModal select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Footer note */
html[data-pos-theme="light"] body
#discountModal .footer-left-note{
    color:#64748b !important;
}

/* Cancel button */
html[data-pos-theme="light"] body
#discountModal .btn-secondary,

html[data-pos-theme="light"] body
#discountModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

/* Save button */
html[data-pos-theme="light"] body
#discountModal .btn-primary,

html[data-pos-theme="light"] body
#discountModal .save-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
}

/* Close button */
html[data-pos-theme="light"] body
#discountModal .btn-close{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
#discountModal .btn-close::before,

html[data-pos-theme="light"] body
#discountModal .btn-close::after{
    background:#334155 !important;
}

/* Modal scrollbar */
html[data-pos-theme="light"] body
#discountModal .item-modal-body::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"] body
#discountModal .item-modal-body::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
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
                <h1 class="page-title">Discount Management</h1>

                <div class="header-actions">
                    <button class="btn-soft btn-add" type="button" onclick="openCreateDiscountModal()">
                        <i class="bi bi-plus-lg"></i> Create Discount
                    </button>

                    <div class="dropdown">
                        <button class="btn-soft btn-bulk dropdown-toggle" type="button" data-bs-toggle="dropdown">
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

                    <button class="btn-soft btn-export" type="button" onclick="exportDiscounts()">
                        <i class="bi bi-upload"></i> Export
                    </button>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div><div class="title"><i class="bi bi-tags me-2"></i>Total Discounts</div></div>
                    <div class="count"><?= $total_discounts ?></div>
                </div>
                <div class="stat-card card-green">
                    <div><div class="title"><i class="bi bi-check-circle me-2"></i>Active Discounts</div></div>
                    <div class="count"><?= $active_discounts ?></div>
                </div>
                <div class="stat-card card-red">
                    <div><div class="title"><i class="bi bi-x-circle me-2"></i>Disabled Discounts</div></div>
                    <div class="count"><?= $disabled_discounts ?></div>
                </div>
                <div class="stat-card card-yellow">
                    <div><div class="title"><i class="bi bi-clock-history me-2"></i>Expiring Soon</div></div>
                    <div class="count"><?= $expiring_soon ?></div>
                </div>
                <div class="stat-card card-cyan">
                    <div><div class="title"><i class="bi bi-cash-stack me-2"></i>Total Discount Value</div></div>
                    <div class="count"><?= number_format($total_discount_value, 2) ?></div>
                </div>
            </div>

            <form class="filter-bar" method="GET">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search code or type...">
                </div>

                <select name="status" class="filter-control">
                    <option value="">All Status</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="disabled" <?= $status_filter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                </select>

                <button type="submit" class="filter-btn">
                    <i class="bi bi-funnel"></i> Apply Filter
                </button>

                <a href="<?= e($_SERVER['PHP_SELF']) ?>" class="filter-btn" style="background:#6b7280;">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            </form>

            <div class="table-card">
                <div class="table-responsive">
                    <table class="customers-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="mainCheckbox" onclick="toggleMainCheckbox(this)"></th>
                                <th>Code</th>
                                <th>Type</th>
                                <th>Value</th>
                                <th>Min Bill</th>
                                <th>Used Count</th>
                                <th>Limit</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                               <th width="220" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($discounts)): ?>
                                <?php foreach ($discounts as $discount): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="rowCheck" value="<?= (int)$discount['id'] ?>">
                                        </td>
                                        <td><strong><?= e($discount['code']) ?></strong></td>
                                        <td><?= e(ucfirst($discount['discount_type'])) ?></td>
                                        <td>
                                            <?= $discount['discount_type'] === 'percentage'
                                                ? e($discount['discount_value']) . '%'
                                                : number_format((float)$discount['discount_value'], 2) ?>
                                        </td>
                                        <td><?= number_format((float)$discount['min_bill'], 2) ?></td>
                                        <td><?= (int)$discount['used_count'] ?></td>
                                        <td><?= (int)$discount['usage_limit'] ?></td>
                                        <td><?= $discount['expiry_date'] ? e($discount['expiry_date']) : '-' ?></td>
                                        <td>
                                            <span class="badge-soft <?= $discount['status'] === 'active' ? 'badge-active' : 'badge-disabled' ?>">
                                                <?= e(ucfirst($discount['status'])) ?>
                                            </span>
                                        </td>
                                                                               <td class="actions-cell">
                                            <div class="action-group">
                                                <button
                                                    type="button"
                                                    class="icon-action icon-edit"
                                                    title="Edit Discount"
                                                    onclick='editDiscount(<?= json_encode([
                                                        "id" => (int)$discount["id"],
                                                        "code" => $discount["code"],
                                                        "discount_type" => $discount["discount_type"],
                                                        "discount_value" => $discount["discount_value"],
                                                        "min_bill" => $discount["min_bill"],
                                                        "usage_limit" => $discount["usage_limit"],
                                                        "used_count" => $discount["used_count"],
                                                        "expiry_date" => $discount["expiry_date"],
                                                        "status" => $discount["status"]
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                >
                                                    <i class="bi bi-pencil-fill"></i>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="icon-action icon-delete"
                                                    title="Delete Discount"
                                                    onclick="deleteDiscount(<?= (int)$discount['id'] ?>)"
                                                >
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="icon-action <?= $discount['status'] === 'active' ? 'icon-toggle-disable' : 'icon-toggle-enable' ?>"
                                                    title="<?= $discount['status'] === 'active' ? 'Disable Discount' : 'Enable Discount' ?>"
                                                    onclick="toggleDiscountStatus(<?= (int)$discount['id'] ?>, '<?= e($discount['status']) ?>')"
                                                >
                                                    <i class="bi <?= $discount['status'] === 'active' ? 'bi-pause-circle' : 'bi-check-circle' ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4">No discounts found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <div>Total Records: <strong><?= count($discounts) ?></strong></div>
                    <div>
                        <button type="button" class="bulk-delete-btn" onclick="deleteSelected(event)">Delete Selected</button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="discountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content item-modal-content">
            <form method="POST" id="discountForm">
                <input type="hidden" name="action" id="discountFormAction" value="add_discount">
                <input type="hidden" name="id" id="discount_id">

               <div class="modal-header item-modal-header">

                    <h5 class="modal-title" id="discountModalTitle">Add New Discount</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body item-modal-body">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Discount Code <span class="required-star">*</span></label>
                            <input type="text" class="item-input" name="code" id="discount_code" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Discount Type</label>
                            <select class="form-select" name="discount_type" id="discount_type" required>
                                <option value="fixed">Fixed</option>
                                <option value="percentage">Percentage</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Discount Value</label>
                            <input type="number" step="0.01" class="form-control" name="discount_value" id="discount_value" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Min Bill</label>
                            <input type="number" step="0.01" class="form-control" name="min_bill" id="min_bill" value="0">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Usage Limit</label>
                            <input type="number" class="form-control" name="usage_limit" id="usage_limit" value="0">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Used Count</label>
                            <input type="number" class="form-control" name="used_count" id="used_count" value="0">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" name="expiry_date" id="expiry_date">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="status">
                                <option value="active">Active</option>
                                <option value="disabled">Disabled</option>
                            </select>
                        </div>
                    </div>
                </div>

               <div class="modal-footer item-modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit" id="discountSubmitBtn">Save Discount</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="discountModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content item-modal-content">

            <form method="POST" id="discountForm">
                <input type="hidden" name="action" id="discountFormAction" value="add_discount">
                <input type="hidden" name="id" id="discount_id">

                <div class="modal-header item-modal-header">
                    <h5 class="modal-title" id="discountModalTitle">Add New Discount</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body item-modal-body">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="item-label">Discount Code <span>*</span></label>
                            <input type="text" class="item-input" name="code" id="discount_code" required>
                        </div>

                        <div class="col-md-6">
                            <label class="item-label color:WHITE">Discount Type</label>
                            <select class="item-input" name="discount_type" id="discount_type">
                                <option value="fixed">Fixed</option>
                                <option value="percentage">Percentage</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="item-label">Discount Value</label>
                            <input type="number" step="0.01" class="item-input" name="discount_value" id="discount_value">
                        </div>

                        <div class="col-md-6">
                            <label class="item-label">Min Bill</label>
                            <input type="number" step="0.01" class="item-input" name="min_bill" id="min_bill" value="0">
                        </div>

                        <div class="col-md-6">
                            <label class="item-label">Usage Limit</label>
                            <input type="number" class="item-input" name="usage_limit" id="usage_limit" value="0">
                        </div>

                        <div class="col-md-6">
                            <label class="item-label">Used Count</label>
                            <input type="number" class="item-input" name="used_count" id="used_count" value="0">
                        </div>

                        <div class="col-md-6">
                            <label class="item-label">Expiry Date</label>
                            <input type="date" class="item-input" name="expiry_date" id="expiry_date">
                        </div>

                        <div class="col-md-6">
                            <label class="item-label">Status</label>
                            <select class="item-input" name="status" id="status">
                                <option value="active">Active</option>
                                <option value="disabled">Disabled</option>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="modal-footer item-modal-footer">
                    <div class="footer-left-note">
                        Create or update your discount details.
                    </div>

                    <div class="footer-btns">
                        <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn" id="discountSubmitBtn">Save Discount</button>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete_discount">
    <input type="hidden" name="id" id="delete_id">
</form>

<form method="POST" id="toggleStatusForm" style="display:none;">
    <input type="hidden" name="action" value="enable_disable_discount">
    <input type="hidden" name="id" id="toggle_id">
    <input type="hidden" name="status" id="toggle_status">
</form>

<form method="POST" id="bulkDeleteForm" style="display:none;">
    <input type="hidden" name="action" value="bulk_delete">
    <div id="bulkDeleteInputs"></div>
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const discountModalEl = document.getElementById('discountModal');
const discountModal = new bootstrap.Modal(discountModalEl);
const importModal = new bootstrap.Modal(document.getElementById('importModal'));

function resetDiscountForm() {
    document.getElementById('discountForm').reset();
    document.getElementById('discount_id').value = '';
    document.getElementById('discountFormAction').value = 'add_discount';
    document.getElementById('discountModalTitle').textContent = 'Add New Discount';
    document.getElementById('discountSubmitBtn').textContent = 'Save Discount';
    document.getElementById('status').value = 'active';
    document.getElementById('discount_type').value = 'fixed';
}

function openCreateDiscountModal() {
    resetDiscountForm();
    discountModal.show();
}

function openImportModal() {
    importModal.show();
}

function editDiscount(discount) {
    resetDiscountForm();
    document.getElementById('discountFormAction').value = 'update_discount';
    document.getElementById('discountModalTitle').textContent = 'Edit Discount';
    document.getElementById('discountSubmitBtn').textContent = 'Update Discount';

    document.getElementById('discount_id').value = discount.id ?? '';
    document.getElementById('discount_code').value = discount.code ?? '';
    document.getElementById('discount_type').value = discount.discount_type ?? 'fixed';
    document.getElementById('discount_value').value = discount.discount_value ?? '';
    document.getElementById('min_bill').value = discount.min_bill ?? 0;
    document.getElementById('usage_limit').value = discount.usage_limit ?? 0;
    document.getElementById('used_count').value = discount.used_count ?? 0;
    document.getElementById('expiry_date').value = discount.expiry_date ?? '';
    document.getElementById('status').value = discount.status ?? 'active';

    discountModal.show();
}

function deleteDiscount(id) {
    if (!confirm('Are you sure you want to delete this discount?')) return;
    document.getElementById('delete_id').value = id;
    document.getElementById('deleteForm').submit();
}

function toggleDiscountStatus(id, status) {
    const actionWord = status === 'active' ? 'disable' : 'enable';
    if (!confirm(`Are you sure you want to ${actionWord} this discount?`)) return;

    document.getElementById('toggle_id').value = id;
    document.getElementById('toggle_status').value = status;
    document.getElementById('toggleStatusForm').submit();
}

function toggleMainCheckbox(mainCheckbox) {
    document.querySelectorAll('.rowCheck').forEach(chk => {
        chk.checked = mainCheckbox.checked;
    });
}

function selectAllRows(event) {
    if (event) event.preventDefault();
    document.querySelectorAll('.rowCheck').forEach(chk => chk.checked = true);
    const main = document.getElementById('mainCheckbox');
    if (main) main.checked = true;
}

function unselectAllRows(event) {
    if (event) event.preventDefault();
    document.querySelectorAll('.rowCheck').forEach(chk => chk.checked = false);
    const main = document.getElementById('mainCheckbox');
    if (main) main.checked = false;
}

function deleteSelected(event) {
    if (event) event.preventDefault();

    const selected = Array.from(document.querySelectorAll('.rowCheck:checked')).map(chk => chk.value);

    if (selected.length === 0) {
        alert('Please select at least one discount to delete.');
        return;
    }

    if (!confirm('Are you sure you want to delete the selected discounts?')) {
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

function exportDiscounts() {
    window.location.href = '<?= e($_SERVER['PHP_SELF']) ?>?export=1';
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;

    const savedState = localStorage.getItem('dashboard_sidebar_state');
    if (savedState === 'collapsed') {
        body.classList.add('dashboard-sidebar-collapsed');
    }

    const toggleSelectors = [
        '#sidebarToggle',
        '#toggleSidebar',
        '.sidebar-toggle',
        '.sidebar-toggler',
        '.topbar-menu-toggle',
        '.hamburger-btn',
        '[data-sidebar-toggle]',
        '.topbar .bi-list',
        '.topbar .fa-bars'
    ];

    let toggleBtn = null;

    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();

            body.classList.toggle('dashboard-sidebar-collapsed');

            if (body.classList.contains('dashboard-sidebar-collapsed')) {
                localStorage.setItem('dashboard_sidebar_state', 'collapsed');
            } else {
                localStorage.setItem('dashboard_sidebar_state', 'open');
            }
        });
    }
});
</script>

</body>
</html>