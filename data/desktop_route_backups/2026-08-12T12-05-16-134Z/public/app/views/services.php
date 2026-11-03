<?php
session_start();
include('partials/performance.php');
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
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($res['cnt'] ?? 0) > 0);
}

function upload_service_photo(array $file, string $oldPhoto = ''): string
{
    if (empty($file['name'])) {
        return $oldPhoto;
    }

    $uploadDir = __DIR__ . '/uploads/services/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Only JPG, JPEG, PNG, WEBP files are allowed.');
    }

    $newName = time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $target = $uploadDir . $newName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Photo upload failed.');
    }

    if ($oldPhoto !== '' && file_exists($uploadDir . $oldPhoto)) {
        @unlink($uploadDir . $oldPhoto);
    }

    return $newName;
}

/* ===================== HANDLE POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'add_service') {
            $name        = trim($_POST['name'] ?? '');
            $category    = trim($_POST['category'] ?? '');
            $price       = (float)($_POST['price'] ?? 0);
            $duration    = trim($_POST['duration'] ?? '');
            $branch      = trim($_POST['branch'] ?? '');
            $staff       = trim($_POST['staff'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $is_popular  = isset($_POST['is_popular']) ? 1 : 0;
            $status      = normalize_status(trim($_POST['status'] ?? 'active'));
            $photo       = upload_service_photo($_FILES['photo'] ?? [], '');

            if ($name === '' || $category === '' || $price <= 0 || $duration === '') {
                redirect_with_message('danger', 'Please fill all required fields.');
            }

            $checkStmt = $conn->prepare("SELECT id FROM services WHERE client_id = ? AND name = ? LIMIT 1");
            $checkStmt->bind_param("is", $client_id, $name);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if ($exists) {
                redirect_with_message('danger', 'This service already exists.');
            }

            $stmt = $conn->prepare("
                INSERT INTO services
                (client_id, photo, name, category, description, price, duration, branch, staff, is_popular, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "issssdsssis",
                $client_id,
                $photo,
                $name,
                $category,
                $description,
                $price,
                $duration,
                $branch,
                $staff,
                $is_popular,
                $status
            );
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Service added successfully.');
        }

        if ($action === 'update_service') {
            $id          = (int)($_POST['id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $category    = trim($_POST['category'] ?? '');
            $price       = (float)($_POST['price'] ?? 0);
            $duration    = trim($_POST['duration'] ?? '');
            $branch      = trim($_POST['branch'] ?? '');
            $staff       = trim($_POST['staff'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $is_popular  = isset($_POST['is_popular']) ? 1 : 0;
            $status      = normalize_status(trim($_POST['status'] ?? 'active'));
            $oldPhoto    = trim($_POST['old_photo'] ?? '');
            $photo       = upload_service_photo($_FILES['photo'] ?? [], $oldPhoto);

            if ($id <= 0 || $name === '' || $category === '' || $price <= 0 || $duration === '') {
                redirect_with_message('danger', 'Invalid service data.');
            }

            $checkStmt = $conn->prepare("SELECT id FROM services WHERE client_id = ? AND name = ? AND id != ? LIMIT 1");
            $checkStmt->bind_param("isi", $client_id, $name, $id);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if ($exists) {
                redirect_with_message('danger', 'Another service already has this name.');
            }

            $stmt = $conn->prepare("
                UPDATE services
                SET photo = ?, name = ?, category = ?, description = ?, price = ?, duration = ?, branch = ?, staff = ?, is_popular = ?, status = ?
                WHERE id = ? AND client_id = ?
            ");
            $stmt->bind_param(
                "ssssdsssissi",
                $photo,
                $name,
                $category,
                $description,
                $price,
                $duration,
                $branch,
                $staff,
                $is_popular,
                $status,
                $id,
                $client_id
            );
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Service updated successfully.');
        }

        if ($action === 'delete_service') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                redirect_with_message('danger', 'Invalid service ID.');
            }

            $photoStmt = $conn->prepare("SELECT photo FROM services WHERE id = ? AND client_id = ?");
            $photoStmt->bind_param("ii", $id, $client_id);
            $photoStmt->execute();
            $photoRow = $photoStmt->get_result()->fetch_assoc();
            $photoStmt->close();

            if (!empty($photoRow['photo'])) {
                $file = __DIR__ . '/uploads/services/' . $photoRow['photo'];
                if (file_exists($file)) {
                    @unlink($file);
                }
            }

            $stmt = $conn->prepare("DELETE FROM services WHERE id = ? AND client_id = ?");
            $stmt->bind_param("ii", $id, $client_id);
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Service deleted successfully.');
        }

        if ($action === 'bulk_delete') {
            $ids = $_POST['selected_ids'] ?? [];

            if (!is_array($ids) || empty($ids)) {
                redirect_with_message('danger', 'Please select at least one service.');
            }

            $cleanIds = [];
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $cleanIds[] = $id;
                }
            }

            if (empty($cleanIds)) {
                redirect_with_message('danger', 'No valid services selected.');
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $types = str_repeat('i', count($cleanIds)) . 'i';
            $params = array_merge($cleanIds, [$client_id]);

            $photoSql = "SELECT photo FROM services WHERE id IN ($placeholders) AND client_id = ?";
            $photoStmt = $conn->prepare($photoSql);
            $photoStmt->bind_param($types, ...$params);
            $photoStmt->execute();
            $photoRes = $photoStmt->get_result();
            while ($p = $photoRes->fetch_assoc()) {
                if (!empty($p['photo'])) {
                    $file = __DIR__ . '/uploads/services/' . $p['photo'];
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }
            }
            $photoStmt->close();

            $sql = "DELETE FROM services WHERE id IN ($placeholders) AND client_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $deletedRows = $stmt->affected_rows;
            $stmt->close();

            redirect_with_message('success', $deletedRows . ' service(s) deleted successfully.');
        }

        if ($action === 'enable_disable_service') {
            $id = (int)($_POST['id'] ?? 0);
            $current_status = normalize_status($_POST['status'] ?? 'active');
            $newStatus = $current_status === 'active' ? 'disabled' : 'active';

            if ($id <= 0) {
                redirect_with_message('danger', 'Invalid service data.');
            }

            $stmt = $conn->prepare("UPDATE services SET status = ? WHERE id = ? AND client_id = ?");
            $stmt->bind_param("sii", $newStatus, $id, $client_id);
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', "Service status updated to {$newStatus}.");
        }

        if ($action === 'import_services') {
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

            fgetcsv($handle);

            $imported = 0;
            $skipped = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $name        = trim($row[0] ?? '');
                $category    = trim($row[1] ?? '');
                $price       = (float)($row[2] ?? 0);
                $duration    = trim($row[3] ?? '');
                $branch      = trim($row[4] ?? '');
                $staff       = trim($row[5] ?? '');
                $description = trim($row[6] ?? '');
                $is_popular  = (int)($row[7] ?? 0);
                $status      = normalize_status(trim($row[8] ?? 'active'));

                if ($name === '' || $category === '' || $price <= 0 || $duration === '') {
                    $skipped++;
                    continue;
                }

                $checkStmt = $conn->prepare("SELECT id FROM services WHERE client_id = ? AND name = ? LIMIT 1");
                $checkStmt->bind_param("is", $client_id, $name);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $photo = '';

                $stmt = $conn->prepare("
                    INSERT INTO services
                    (client_id, photo, name, category, description, price, duration, branch, staff, is_popular, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "issssdsssis",
                    $client_id,
                    $photo,
                    $name,
                    $category,
                    $description,
                    $price,
                    $duration,
                    $branch,
                    $staff,
                    $is_popular,
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

    } catch (Throwable $e) {
        redirect_with_message('danger', $e->getMessage());
    }
}

/* ===================== DROPDOWNS ===================== */
$categories = [];
$branches = [];
$staffMembers = [];

/* categories */
$checkCat = $conn->query("SHOW TABLES LIKE 'categories'");
if ($checkCat && $checkCat->num_rows > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM categories WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();
}

/* branches */
$checkBranch = $conn->query("SHOW TABLES LIKE 'branches'");
if ($checkBranch && $checkBranch->num_rows > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $branches[] = $row;
    }
    $stmt->close();
}

/* users table as staff source */
$checkUsers = $conn->query("SHOW TABLES LIKE 'users'");
if ($checkUsers && $checkUsers->num_rows > 0) {
    $userNameColumn = null;
    $possibleNameCols = ['name', 'full_name', 'username'];

    foreach ($possibleNameCols as $col) {
        if (column_exists($conn, 'users', $col)) {
            $userNameColumn = $col;
            break;
        }
    }

    if ($userNameColumn !== null && column_exists($conn, 'users', 'client_id')) {
        $sqlUsers = "SELECT id, {$userNameColumn} AS display_name FROM users WHERE client_id = ? ORDER BY {$userNameColumn} ASC";
        $stmt = $conn->prepare($sqlUsers);
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $staffMembers[] = $row;
        }
        $stmt->close();
    }
}

/* ===================== FLASH MESSAGE ===================== */
$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ===================== FILTERS ===================== */
$search      = trim($_GET['search'] ?? '');
$categoryF   = trim($_GET['category'] ?? '');
$branchF     = trim($_GET['branch'] ?? '');
$staffF      = trim($_GET['staff'] ?? '');
$statusF     = trim($_GET['status'] ?? '');
$dateFrom    = trim($_GET['date_from'] ?? '');
$dateTo      = trim($_GET['date_to'] ?? '');

$sql = "SELECT * FROM services WHERE client_id = ?";
$params = [$client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR description LIKE ? OR category LIKE ? OR branch LIKE ? OR staff LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like);
    $types .= "sssss";
}

if ($categoryF !== '') {
    $sql .= " AND category = ?";
    $params[] = $categoryF;
    $types .= "s";
}

if ($branchF !== '') {
    $sql .= " AND branch = ?";
    $params[] = $branchF;
    $types .= "s";
}

if ($staffF !== '') {
    $sql .= " AND staff = ?";
    $params[] = $staffF;
    $types .= "s";
}

if (in_array($statusF, ['active', 'disabled'], true)) {
    $sql .= " AND status = ?";
    $params[] = $statusF;
    $types .= "s";
}

if ($dateFrom !== '') {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if ($dateTo !== '') {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$services = [];
while ($row = $res->fetch_assoc()) {
    $services[] = $row;
}
$stmt->close();

/* ===================== STATS ===================== */
$stats_sql = "
    SELECT
        COUNT(*) AS total_services,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_services,
        SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) AS disabled_services,
        SUM(CASE WHEN is_popular = 1 THEN 1 ELSE 0 END) AS popular_services,
        SUM(price) AS total_service_value
    FROM services
    WHERE client_id = ?
";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_services      = (int)($stats['total_services'] ?? 0);
$active_services     = (int)($stats['active_services'] ?? 0);
$disabled_services   = (int)($stats['disabled_services'] ?? 0);
$popular_services    = (int)($stats['popular_services'] ?? 0);
$total_service_value = (float)($stats['total_service_value'] ?? 0);

/* ===================== EXPORT CSV ===================== */
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=services_export_' . date('Y-m-d_H-i-s') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Category', 'Price', 'Duration', 'Branch', 'Staff', 'Description', 'Popular', 'Status']);

    $exportSql = "SELECT name, category, price, duration, branch, staff, description, is_popular, status FROM services WHERE client_id = ? ORDER BY id DESC";
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
    <title>Services</title>
    <?php include('partials/head.php'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{
            --erp-bg:#050816;
            --erp-bg-2:#080b18;
            --erp-card:rgba(17, 20, 42, .72);
            --erp-card-2:rgba(22, 25, 52, .82);
            --erp-border:rgba(139, 92, 246, .22);
            --erp-border-soft:rgba(255,255,255,.08);
            --erp-text:#f8fafc;
            --erp-muted:#a8b0c7;
            --erp-purple:#7c3aed;
            --erp-purple-2:#4f46e5;
            --erp-gold:#f59e0b;
            --erp-danger:#ef4444;
            --erp-success:#22c55e;
        }

        *{box-sizing:border-box;}

        body{
            margin:0;
            min-height:100vh;
            background:
                radial-gradient(circle at 75% 8%, rgba(124,58,237,.36) 0%, rgba(124,58,237,.12) 28%, transparent 52%),
                radial-gradient(circle at 35% 0%, rgba(59,130,246,.18) 0%, transparent 38%),
                linear-gradient(135deg,#040711 0%,#070b1a 42%,#120a2a 100%) !important;
            font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color:var(--erp-text);
            overflow-x:hidden;
        }

        body::before{
            content:"";
            position:fixed;
            inset:0;
            pointer-events:none;
            background:
                linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
            background-size:42px 42px;
            mask-image:linear-gradient(to bottom, rgba(0,0,0,.9), transparent 78%);
            z-index:0;
        }

        .container-scroll{
            display:flex;
            min-height:100vh;
            position:relative;
            z-index:1;
            background:transparent !important;
        }

        .sidebar{
            width:250px;
            flex-shrink:0;
            position:relative;
            z-index:20;
        }

        .main-panel{
            flex-grow:1;
            display:flex;
            flex-direction:column;
            min-width:0;
            background:transparent !important;
        }

        .topbar{
            min-height:78px;
            border-bottom:1px solid rgba(139,92,246,.16) !important;
            display:flex;
            align-items:center;
            padding:0 20px;
            background:rgba(3,7,18,.82) !important;
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
            box-shadow:0 18px 45px rgba(0,0,0,.25);
            position:sticky;
            top:0;
            z-index:12;
        }

        .content-wrapper{
            padding:26px 30px 34px;
            flex-grow:1;
            overflow-y:auto;
            background:transparent !important;
        }

        .page-shell{
            background:linear-gradient(180deg, rgba(18,21,45,.84) 0%, rgba(10,13,29,.72) 100%) !important;
            border:1px solid rgba(139,92,246,.24) !important;
            border-radius:24px;
            box-shadow:0 28px 70px rgba(0,0,0,.38), inset 0 1px 0 rgba(255,255,255,.06);
            padding:20px;
            backdrop-filter:blur(20px);
            -webkit-backdrop-filter:blur(20px);
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .page-title{
            font-size:24px;
            font-weight:900;
            letter-spacing:.4px;
            margin:0;
            color:#fff;
            text-shadow:0 6px 18px rgba(124,58,237,.20);
        }

        .header-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}

        .action-btn{
            border:1px solid rgba(255,255,255,.12);
            border-radius:14px;
            padding:12px 20px;
            color:#fff;
            font-size:15px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:9px;
            box-shadow:0 12px 26px rgba(0,0,0,.22);
            transition:.22s ease;
        }
        .action-btn:hover{transform:translateY(-2px);filter:brightness(1.07);}
        .btn-add{background:linear-gradient(135deg,#f59e0b 0%,#fbbf24 100%);color:#111827;border-color:rgba(251,191,36,.50);}
        .btn-import{background:linear-gradient(135deg,rgba(91,103,138,.88),rgba(54,64,98,.92));}
        .btn-report{background:linear-gradient(135deg,rgba(69,82,118,.88),rgba(25,32,55,.96));}

        .btn-icon-search,
        .search-btn-custom{
            width:48px;
            height:48px;
            border-radius:14px;
            border:1px solid rgba(139,92,246,.28);
            background:rgba(15,23,42,.74);
            color:#dbeafe;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:18px;
            text-decoration:none;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.05);
            transition:.22s ease;
        }
        .btn-icon-search:hover,.search-btn-custom:hover{background:rgba(124,58,237,.22);color:#fff;transform:translateY(-1px);}

        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:18px;}
        .stat-card{
            border-radius:18px;
            color:#fff;
            padding:20px 22px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            min-height:118px;
            border:1px solid rgba(255,255,255,.10);
            box-shadow:0 18px 40px rgba(0,0,0,.26), inset 0 1px 0 rgba(255,255,255,.10);
            overflow:hidden;
            position:relative;
        }
        .stat-card::after{
            content:"";
            position:absolute;
            right:18px;
            top:18px;
            width:78px;
            height:78px;
            border-radius:22px;
            background:rgba(255,255,255,.12);
            pointer-events:none;
        }
        .stat-title{font-size:15px;font-weight:800;opacity:.96;margin-bottom:10px;position:relative;z-index:1;}
        .stat-sub{font-size:14px;opacity:.88;position:relative;z-index:1;}
        .stat-value{font-size:28px;font-weight:900;text-align:right;line-height:1.1;position:relative;z-index:1;}
        .stat-mini{font-size:12px;opacity:.92;font-weight:800;margin-top:4px;}
        .card-blue{background:linear-gradient(135deg,#2563eb 0%,#4f46e5 55%,#7c3aed 100%);}
        .card-green{background:linear-gradient(135deg,#047857 0%,#059669 100%);}
        .card-purple{background:linear-gradient(135deg,#6d28d9 0%,#8b5cf6 100%);}
        .card-gold{background:linear-gradient(135deg,#d97706 0%,#f59e0b 100%);}

        .service-filter-bar{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            background:rgba(8,11,28,.66) !important;
            border:1px solid rgba(139,92,246,.22);
            border-radius:18px;
            padding:12px;
            margin-bottom:20px;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
        }
        .filter-field{
            display:flex;
            align-items:center;
            gap:9px;
            background:rgba(15,23,42,.74);
            border:1px solid rgba(148,163,184,.16);
            border-radius:13px;
            height:48px;
            padding:0 14px;
            min-width:170px;
            flex:1;
        }
        .filter-field:focus-within{border-color:rgba(139,92,246,.55);box-shadow:0 0 0 4px rgba(124,58,237,.14);}
        .filter-field i{color:#c4b5fd;font-size:15px;flex-shrink:0;}
        .filter-field select,.filter-field input{
            border:none;
            outline:none;
            background:transparent;
            width:100%;
            font-size:14px;
            color:#f8fafc;
            box-shadow:none;
        }
        .filter-field select option{background:#111827;color:#fff;}
        .filter-field input::placeholder{color:#9ca3af;}
        .filter-field input[type="date"]{color-scheme:dark;}
        .date-field{min-width:160px;}
        .filter-btn-custom{
            height:48px;
            padding:0 22px;
            border:1px solid rgba(139,92,246,.36);
            border-radius:13px;
            background:linear-gradient(135deg,#4f46e5,#7c3aed);
            color:#fff;
            font-size:15px;
            font-weight:800;
            display:flex;
            align-items:center;
            gap:8px;
            box-shadow:0 10px 24px rgba(79,70,229,.28);
        }

        .table-shell{
            background:rgba(8,11,28,.72) !important;
            border:1px solid rgba(139,92,246,.22) !important;
            border-radius:18px;
            overflow:hidden;
            box-shadow:0 24px 55px rgba(0,0,0,.26), inset 0 1px 0 rgba(255,255,255,.05);
        }
        .table-responsive{overflow-x:auto;}
        .table-responsive::-webkit-scrollbar{height:10px;width:10px;}
        .table-responsive::-webkit-scrollbar-track{background:rgba(15,23,42,.85);border-radius:999px;}
        .table-responsive::-webkit-scrollbar-thumb{background:linear-gradient(90deg,#4f46e5,#7c3aed);border-radius:999px;}
        .services-table{width:100%;min-width:1280px;border-collapse:separate;border-spacing:0;}
        .services-table thead th{
            background:rgba(15,18,38,.96) !important;
            color:#f8fafc;
            font-weight:900;
            font-size:13px;
            letter-spacing:.35px;
            text-transform:uppercase;
            padding:15px 14px;
            border-right:1px solid rgba(255,255,255,.06);
            border-bottom:1px solid rgba(139,92,246,.20);
            white-space:nowrap;
        }
        .services-table tbody td{
            padding:14px 14px;
            border-bottom:1px solid rgba(255,255,255,.07);
            color:#dbe3f4;
            font-size:14px;
            vertical-align:middle;
            background:rgba(11,15,34,.62) !important;
        }
        .services-table tbody tr:nth-child(even) td{background:rgba(17,21,44,.62) !important;}
        .services-table tbody tr:hover td{background:rgba(124,58,237,.15) !important;color:#fff;}
        .services-table input[type="checkbox"]{accent-color:#7c3aed;}
        .thumb{
            width:48px;
            height:48px;
            border-radius:12px;
            object-fit:cover;
            border:1px solid rgba(139,92,246,.26);
            background:rgba(15,23,42,.85);
            color:#a78bfa;
        }

        .status-pill{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;font-size:12px;font-weight:900;}
        .status-active{background:rgba(34,197,94,.14);color:#86efac;border:1px solid rgba(34,197,94,.28);}
        .status-inactive{background:rgba(239,68,68,.13);color:#fca5a5;border:1px solid rgba(239,68,68,.28);}
        .switch{position:relative;display:inline-block;width:48px;height:26px;}
        .switch input{display:none;}
        .slider{position:absolute;inset:0;cursor:pointer;background:#3f455d;border-radius:999px;transition:.25s;}
        .slider:before{content:"";position:absolute;width:20px;height:20px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 3px 10px rgba(0,0,0,.25);}
        .switch input:checked + .slider{background:linear-gradient(135deg,#22c55e,#16a34a);}
        .switch input:checked + .slider:before{transform:translateX(22px);}

        .actions-wrap{display:flex;align-items:center;gap:10px;justify-content:center;white-space:nowrap;}
        .icon-btn{
            width:38px;
            height:38px;
            border:1px solid rgba(255,255,255,.10);
            border-radius:12px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:15px;
            cursor:pointer;
            transition:all .18s ease;
            box-shadow:0 10px 20px rgba(0,0,0,.18);
        }
        .icon-btn:hover{transform:translateY(-2px);}
        .icon-edit{background:rgba(245,158,11,.14);color:#fbbf24;}
        .icon-edit:hover{background:#f59e0b;color:#111827;}
        .icon-delete{background:rgba(239,68,68,.14);color:#f87171;}
        .icon-delete:hover{background:#ef4444;color:#fff;}
        .icon-view{background:rgba(124,58,237,.17);color:#c4b5fd;}
        .icon-view:hover{background:#7c3aed;color:#fff;}
        .icon-delete::before,.icon-delete::after{content:none !important;display:none !important;}

        .table-footer{
            padding:16px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            background:rgba(7,10,24,.82) !important;
            border-top:1px solid rgba(139,92,246,.18);
            color:#e5e7eb;
        }
        .footer-left,.footer-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
        .footer-select{
            min-width:155px;
            height:44px;
            border:1px solid rgba(148,163,184,.18);
            border-radius:12px;
            background:rgba(15,23,42,.82);
            color:#f8fafc;
            padding:0 12px;
        }
        .footer-select option{background:#111827;color:#fff;}
        .btn-delete-selected{height:44px;border:none;border-radius:12px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;padding:0 18px;font-weight:800;}
        .btn-export{height:44px;border:1px solid rgba(148,163,184,.20);border-radius:12px;background:rgba(15,23,42,.80);color:#dbeafe;padding:0 16px;font-weight:800;}
        .btn-service-report{height:44px;border:none;border-radius:12px;background:linear-gradient(135deg,#111827,#3b2a5f);color:#fff;padding:0 18px;font-weight:800;}
        .btn-export:hover,.btn-service-report:hover,.btn-delete-selected:hover{transform:translateY(-1px);filter:brightness(1.06);}

        .alert{border-radius:14px;border:1px solid rgba(255,255,255,.12);box-shadow:0 14px 30px rgba(0,0,0,.20);}
        .alert-success{background:rgba(34,197,94,.14);color:#bbf7d0;border-color:rgba(34,197,94,.28);}
        .alert-danger{background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.28);}

        /* ===================== DARK PURPLE GLASS MODALS ===================== */
        body.modal-open .modal-backdrop.show{opacity:.82!important;background:#020617!important;}
        #serviceModal .modal-dialog{max-width:min(1240px,94vw)!important;}
        #importModal .modal-dialog,#viewModal .modal-dialog{max-width:min(640px,94vw)!important;}
        #serviceModal .modal-content,#serviceModal .service-modal-content,#importModal .modal-content,#viewModal .modal-content{
            background:linear-gradient(180deg,rgba(17,20,42,.94),rgba(7,10,24,.96))!important;
            color:#fff!important;
            border:1px solid rgba(139,92,246,.34)!important;
            border-radius:22px!important;
            overflow:hidden!important;
            box-shadow:0 36px 100px rgba(0,0,0,.72), inset 0 1px 0 rgba(255,255,255,.06)!important;
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
        }
        #serviceModal .modal-header,#serviceModal .service-modal-header,#importModal .modal-header,#viewModal .modal-header,
        #importModal .modal-header.bg-dark,#viewModal .modal-header.bg-dark{
            background:linear-gradient(135deg,rgba(10,14,32,.98),rgba(42,22,88,.72))!important;
            border-bottom:1px solid rgba(139,92,246,.28)!important;
            padding:20px 24px!important;
            min-height:74px!important;
        }
        #serviceModal .modal-title,#importModal .modal-title,#viewModal .modal-title{color:#fff!important;font-size:22px!important;font-weight:900!important;letter-spacing:-.3px!important;display:flex!important;align-items:center!important;gap:10px!important;margin:0!important;}
        #serviceModal .modal-title::before,#importModal .modal-title::before,#viewModal .modal-title::before{content:""!important;width:12px!important;height:12px!important;border-radius:50%!important;background:#8b5cf6!important;box-shadow:0 0 0 6px rgba(139,92,246,.16),0 0 22px rgba(139,92,246,.80)!important;display:inline-block!important;flex-shrink:0!important;}
        #serviceModal .btn-close,#importModal .btn-close,#viewModal .btn-close{position:relative!important;width:46px!important;height:46px!important;border-radius:14px!important;background:rgba(15,23,42,.80)!important;border:1px solid rgba(255,255,255,.12)!important;opacity:1!important;background-image:none!important;box-shadow:none!important;padding:0!important;filter:none!important;}
        #serviceModal .btn-close::before,#serviceModal .btn-close::after,#importModal .btn-close::before,#importModal .btn-close::after,#viewModal .btn-close::before,#viewModal .btn-close::after{content:""!important;position:absolute!important;top:50%!important;left:50%!important;width:22px!important;height:2px!important;background:#fff!important;border-radius:2px!important;transform-origin:center!important;}
        #serviceModal .btn-close::before,#importModal .btn-close::before,#viewModal .btn-close::before{transform:translate(-50%,-50%) rotate(45deg)!important;}
        #serviceModal .btn-close::after,#importModal .btn-close::after,#viewModal .btn-close::after{transform:translate(-50%,-50%) rotate(-45deg)!important;}
        #serviceModal .btn-close:hover,#importModal .btn-close:hover,#viewModal .btn-close:hover{background:#ef4444!important;transform:translateY(-1px)!important;}
        #serviceModal .modal-body,#serviceModal .service-modal-body,#importModal .modal-body,#viewModal .modal-body{background:rgba(7,10,24,.92)!important;color:#fff!important;padding:24px!important;}
        #serviceModal .modal-footer,#serviceModal .service-modal-footer,#importModal .modal-footer{background:rgba(7,10,24,.96)!important;border-top:1px solid rgba(139,92,246,.24)!important;padding:18px 24px 22px!important;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;}
        #serviceModal .service-label,#importModal .form-label,#serviceModal label,#importModal label{color:#f8fafc!important;font-size:14px!important;font-weight:900!important;margin-bottom:9px!important;letter-spacing:-.1px!important;}
        #serviceModal .service-label span{color:#fbbf24!important;}
        #serviceModal .service-input,#serviceModal .service-textarea,#importModal .form-control,#serviceModal input[type="text"],#serviceModal input[type="number"],#serviceModal select,#serviceModal textarea,#importModal input[type="file"]{
            background:rgba(15,23,42,.86)!important;
            color:#fff!important;
            border:1px solid rgba(148,163,184,.18)!important;
            border-radius:12px!important;
            font-size:15px!important;
            font-weight:600!important;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 18px rgba(0,0,0,.20)!important;
        }
        #serviceModal .service-input,#importModal .form-control,#serviceModal input[type="text"],#serviceModal input[type="number"],#serviceModal select,#importModal input[type="file"]{min-height:50px!important;height:50px!important;padding:10px 16px!important;}
        #serviceModal .service-textarea,#serviceModal textarea{min-height:112px!important;height:auto!important;padding:14px 16px!important;resize:vertical!important;}
        #serviceModal select option{background:#111827;color:#fff;}
        #serviceModal input::placeholder,#serviceModal textarea::placeholder{color:#94a3b8!important;}
        #serviceModal .service-input:focus,#serviceModal .service-textarea:focus,#importModal .form-control:focus,#serviceModal input:focus,#serviceModal select:focus,#serviceModal textarea:focus{border-color:rgba(139,92,246,.70)!important;box-shadow:0 0 0 4px rgba(124,58,237,.18),0 8px 20px rgba(0,0,0,.28)!important;outline:none!important;}
        #serviceModal .price-wrap{background:rgba(15,23,42,.86)!important;border:1px solid rgba(148,163,184,.18)!important;border-radius:12px!important;overflow:hidden!important;box-shadow:0 8px 18px rgba(0,0,0,.20)!important;}
        #serviceModal .price-prefix{min-width:68px!important;height:50px!important;background:linear-gradient(135deg,#4f46e5,#7c3aed)!important;color:#fff!important;border-right:1px solid rgba(255,255,255,.12)!important;font-weight:900!important;display:flex;align-items:center;justify-content:center;}
        #serviceModal .price-input{background:transparent!important;border:none!important;box-shadow:none!important;}
        #serviceModal .upload-box{background:rgba(15,23,42,.70)!important;border:1px solid rgba(139,92,246,.26)!important;border-radius:16px!important;box-shadow:0 12px 26px rgba(0,0,0,.24)!important;padding:12px;text-align:center;height:100%;}
        #serviceModal .upload-preview{min-height:220px;background:rgba(5,8,22,.80)!important;border:1.5px dashed rgba(139,92,246,.50)!important;border-radius:16px!important;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:15px;overflow:hidden;}
        #serviceModal .upload-preview i{font-size:46px;color:#a78bfa!important;margin-bottom:10px;}
        #serviceModal .upload-title{color:#fff!important;font-size:18px;font-weight:900!important;}
        #serviceModal .upload-subtext{color:#a8b0c7!important;font-weight:700!important;font-size:12px;margin-top:4px;}
        #serviceModal .upload-preview img{max-width:100%;max-height:190px;border-radius:12px;object-fit:cover;}
        #serviceModal .upload-btn{margin-top:12px;width:100%;height:48px;border:none!important;border-radius:12px!important;background:linear-gradient(135deg,#4f46e5,#7c3aed)!important;color:#fff!important;font-weight:900!important;box-shadow:0 12px 22px rgba(79,70,229,.28)!important;}
        #serviceModal .popular-toggle-wrap{display:flex;align-items:center;gap:10px;background:rgba(15,23,42,.72)!important;border:1px solid rgba(139,92,246,.24)!important;border-radius:14px!important;padding:12px 14px!important;}
        #serviceModal .popular-label{color:#fff!important;font-weight:800!important;font-size:15px;}
        #serviceModal .switch input:checked + .slider{background:linear-gradient(135deg,#8b5cf6,#4f46e5)!important;}
        #serviceModal .cancel-btn,#importModal .btn-secondary{height:44px!important;min-width:110px!important;border:none!important;border-radius:12px!important;background:rgba(30,41,59,.90)!important;color:#fff!important;font-weight:900!important;box-shadow:0 10px 20px rgba(0,0,0,.22)!important;}
        #serviceModal .save-btn,#importModal .btn-success{height:44px!important;min-width:130px!important;border:none!important;border-radius:12px!important;background:linear-gradient(135deg,#f59e0b,#fbbf24)!important;color:#111827!important;font-weight:900!important;box-shadow:0 12px 22px rgba(245,158,11,.28)!important;}
        #serviceModal .cancel-btn:hover,#importModal .btn-secondary:hover,#serviceModal .save-btn:hover,#importModal .btn-success:hover{transform:translateY(-1px)!important;filter:brightness(1.06)!important;}
        #importModal .text-muted{background:rgba(15,23,42,.76)!important;border:1px solid rgba(139,92,246,.22)!important;border-radius:12px!important;padding:12px 14px!important;color:#cbd5e1!important;font-weight:700!important;}
        #viewModal #viewModalBody{background:rgba(7,10,24,.92)!important;color:#fff!important;}
        #viewModal #viewModalBody .mb-2{background:rgba(15,23,42,.78)!important;color:#e5e7eb!important;border:1px solid rgba(139,92,246,.20)!important;border-radius:12px!important;padding:12px 14px!important;margin-bottom:10px!important;box-shadow:0 8px 18px rgba(0,0,0,.20)!important;}
        #viewModal #viewModalBody strong{color:#c4b5fd!important;font-size:12px!important;text-transform:uppercase!important;letter-spacing:.35px!important;}

        @media (max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr);}.content-wrapper{padding:22px;}}
        @media (max-width:992px){.service-filter-bar{flex-direction:column;align-items:stretch;}.filter-field,.filter-btn-custom,.search-btn-custom{width:100%;min-width:100%;}.topbar{min-height:70px;}}
        @media (max-width:768px){.stats-grid{grid-template-columns:1fr;}.content-wrapper{padding:14px;}.page-shell{padding:14px;border-radius:20px;}.page-header{align-items:stretch;}.header-actions{width:100%;}.action-btn{width:100%;justify-content:center;}#serviceModal .modal-dialog,#importModal .modal-dialog,#viewModal .modal-dialog{max-width:96vw!important;margin:.75rem auto!important;}#serviceModal .modal-title,#importModal .modal-title,#viewModal .modal-title{font-size:18px!important;}#serviceModal .btn-close,#importModal .btn-close,#viewModal .btn-close{width:42px!important;height:42px!important;}#serviceModal .cancel-btn,#serviceModal .save-btn,#importModal .btn-secondary,#importModal .btn-success{width:100%!important;}.footer-btns{width:100%;display:flex;flex-direction:column;gap:10px;}}

        @media print{
            body{background:#fff!important;color:#111827!important;}
            .sidebar,.topbar,.header-actions,.service-filter-bar,.table-footer,.actions-wrap{display:none!important;}
            .content-wrapper{padding:0!important;}
            .page-shell,.table-shell{background:#fff!important;border:0!important;box-shadow:none!important;}
            .services-table thead th{background:#111827!important;color:#fff!important;}
            .services-table tbody td{background:#fff!important;color:#111827!important;border-bottom:1px solid #e5e7eb!important;}
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
            <div class="page-shell">

                <?php if ($msg !== ''): ?>
                    <div class="alert alert-<?= e($msgType) ?> alert-dismissible fade show" role="alert">
                        <?= e($msg) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="page-header">
                    <h1 class="page-title">Services</h1>

                    <div class="header-actions">
                        <button type="button" class="action-btn btn-add" onclick="openCreateServiceModal()">
                            <i class="bi bi-plus-lg"></i> Add Service
                        </button>

                        <button type="button" class="action-btn btn-import" onclick="openImportModal()">
                            <i class="bi bi-download"></i> Import Services
                        </button>

                        <button type="button" class="action-btn btn-report" onclick="printServiceReport()">
                            <i class="bi bi-bar-chart-line"></i> Reports
                        </button>

                        <a href="javascript:void(0)" class="btn-icon-search" onclick="document.getElementById('searchBox').focus()">
                            <i class="bi bi-search"></i>
                        </a>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card card-blue">
                        <div>
                            <div class="stat-title"><i class="bi bi-tools me-2"></i>Total Services</div>
                            <div class="stat-sub">All services</div>
                        </div>
                        <div class="stat-value"><?= $total_services ?></div>
                    </div>

                    <div class="stat-card card-green">
                        <div>
                            <div class="stat-title"><i class="bi bi-star-fill me-2"></i>Popular Services</div>
                            <div class="stat-sub">Marked popular</div>
                        </div>
                        <div class="stat-value"><?= $popular_services ?></div>
                    </div>

                    <div class="stat-card card-purple">
                        <div>
                            <div class="stat-title"><i class="bi bi-check-circle me-2"></i>Active Services</div>
                            <div class="stat-sub">Enabled services</div>
                        </div>
                        <div class="stat-value"><?= $active_services ?></div>
                    </div>

                    <div class="stat-card card-gold">
                        <div>
                            <div class="stat-title"><i class="bi bi-cash-coin me-2"></i>Total Revenue</div>
                            <div class="stat-sub">Sum of prices</div>
                        </div>
                        <div class="stat-value">
                            <?= number_format($total_service_value, 0) ?>
                            <div class="stat-mini">PKR</div>
                        </div>
                    </div>
                </div>

                <form class="service-filter-bar" method="GET">
                    <div class="filter-field">
                        <i class="bi bi-folder"></i>
                        <select name="category">
                            <option value="">All Categories</option>
                            <?php if (!empty($categories)): ?>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= e($cat['name']) ?>" <?= $categoryF === $cat['name'] ? 'selected' : '' ?>>
                                        <?= e($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="Salon" <?= $categoryF === 'Salon' ? 'selected' : '' ?>>Salon</option>
                                <option value="Auto Repair" <?= $categoryF === 'Auto Repair' ? 'selected' : '' ?>>Auto Repair</option>
                                <option value="Electronics Repair" <?= $categoryF === 'Electronics Repair' ? 'selected' : '' ?>>Electronics Repair</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <i class="bi bi-geo-alt-fill"></i>
                        <select name="branch">
                            <option value="">All Branches</option>
                            <?php if (!empty($branches)): ?>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= e($branch['name']) ?>" <?= $branchF === $branch['name'] ? 'selected' : '' ?>>
                                        <?= e($branch['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="Main Branch" <?= $branchF === 'Main Branch' ? 'selected' : '' ?>>Main Branch</option>
                                <option value="Northside Branch" <?= $branchF === 'Northside Branch' ? 'selected' : '' ?>>Northside Branch</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <i class="bi bi-people-fill"></i>
                        <select name="staff">
                            <option value="">All Staff</option>
                            <?php foreach ($staffMembers as $staff): ?>
                                <option value="<?= e($staff['display_name']) ?>" <?= $staffF === $staff['display_name'] ? 'selected' : '' ?>>
                                    <?= e($staff['display_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field date-field">
                        <i class="bi bi-calendar-event"></i>
                        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <div class="filter-field date-field">
                        <i class="bi bi-calendar-event"></i>
                        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <button type="submit" class="filter-btn-custom">
                        <i class="bi bi-funnel"></i> Filter
                    </button>

                    <a href="<?= e($_SERVER['PHP_SELF']) ?>" class="search-btn-custom">
                        <i class="bi bi-search"></i>
                    </a>

                    <div class="filter-field" style="min-width:220px;">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchBox" name="search" value="<?= e($search) ?>" placeholder="Search service...">
                    </div>
                </form>

                <div class="table-shell">
                    <div class="table-responsive">
                        <table class="services-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="mainCheckbox" onclick="toggleAllRows(this)"></th>
                                    <th>Photo</th>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Duration</th>
                                    <th>Staff</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th>Popular</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($services)): ?>
                                <tr>
                                    <td colspan="12" class="text-center py-4">No services found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($services as $s): ?>
                                    <tr>
                                        <td><input type="checkbox" class="rowCheck" value="<?= (int)$s['id'] ?>"></td>
                                        <td>
                                            <?php if (!empty($s['photo'])): ?>
                                                <img class="thumb" src="uploads/services/<?= e($s['photo']) ?>" alt="photo">
                                            <?php else: ?>
                                                <div class="thumb d-flex align-items-center justify-content-center"><i class="bi bi-image text-muted"></i></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int)$s['id'] ?></td>
                                        <td><?= e($s['name']) ?></td>
                                        <td><?= e($s['category']) ?></td>
                                        <td><?= number_format((float)$s['price'], 2) ?> PKR</td>
                                        <td><?= e($s['duration']) ?></td>
                                        <td><?= e($s['staff']) ?></td>
                                        <td><?= e($s['branch']) ?></td>
                                        <td>
                                            <label class="switch">
                                                <input type="checkbox" <?= $s['status'] === 'active' ? 'checked' : '' ?> onchange="toggleServiceStatus(<?= (int)$s['id'] ?>, '<?= e($s['status']) ?>')">
                                                <span class="slider"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <?php if ((int)$s['is_popular'] === 1): ?>
                                                <span class="status-pill status-active">Popular</span>
                                            <?php else: ?>
                                                <span class="status-pill status-inactive">Normal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
    <div class="actions-wrap">

        <!-- EDIT -->
        <button type="button" class="icon-btn icon-edit"
            onclick='editService(<?= json_encode([
                "id" => (int)$s["id"],
                "photo" => $s["photo"],
                "name" => $s["name"],
                "category" => $s["category"],
                "description" => $s["description"],
                "price" => $s["price"],
                "duration" => $s["duration"],
                "branch" => $s["branch"],
                "staff" => $s["staff"],
                "is_popular" => $s["is_popular"],
                "status" => $s["status"]
            ]) ?>)'>
            <i class="bi bi-pencil-fill"></i>
        </button>

        <!-- DELETE -->
        <button type="button" class="icon-btn icon-delete"
            onclick="deleteService(<?= (int)$s['id'] ?>)">
            <i class="bi bi-trash-fill"></i>
        </button>

        <!-- VIEW -->
        <button type="button" class="icon-btn icon-view"
            onclick='viewService(<?= json_encode($s) ?>)'>
            <i class="bi bi-eye-fill"></i>
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
                        <div class="footer-left">
                            <span>Total Rows: <strong><?= count($services) ?></strong></span>

                            <select class="footer-select" id="bulkAction">
                                <option value="">Bulk Actions</option>
                                <option value="delete">Delete Selected</option>
                            </select>

                            <button type="button" class="btn-delete-selected" onclick="applyBulkAction()">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>

                        <div class="footer-right">
                            <button type="button" class="btn-export" onclick="exportCSV()">
                                <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                            </button>

                            <button type="button" class="btn-service-report" onclick="printServiceReport()">
                                <i class="bi bi-bar-chart-fill"></i> Service Report
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="serviceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content service-modal-content">
            <form method="POST" enctype="multipart/form-data" id="serviceForm">
                <input type="hidden" name="action" id="serviceFormAction" value="add_service">
                <input type="hidden" name="id" id="service_id">
                <input type="hidden" name="old_photo" id="old_photo">

                <div class="modal-header service-modal-header">
                    <h5 class="modal-title" id="serviceModalTitle">Add Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body service-modal-body">
                    <div class="row g-3">
                        <div class="col-lg-3">
                            <div class="upload-box">
                                <div class="upload-preview" id="uploadPreview">
                                    <i class="bi bi-camera-fill"></i>
                                    <div class="upload-title">Upload Photo</div>
                                    <div class="upload-subtext">JPG, PNG, WEBP</div>
                                </div>

                                <input type="file" name="photo" id="photoInput" accept=".jpg,.jpeg,.png,.webp" hidden>

                                <button type="button" class="upload-btn" onclick="document.getElementById('photoInput').click()">
                                    <i class="bi bi-plus-lg"></i> Add Photo
                                </button>
                            </div>
                        </div>

                        <div class="col-lg-9">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="service-label">Service Name <span>*</span></label>
                                    <input type="text" class="service-input" name="name" id="service_name" placeholder="Enter Service Name..." required>
                                </div>

                                <div class="col-md-6">
                                    <label class="service-label">Category <span>*</span></label>
                                    <select class="service-input" name="category" id="service_category" required>
                                        <option value="">Select Category...</option>
                                        <?php if (!empty($categories)): ?>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= e($cat['name']) ?>"><?= e($cat['name']) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="Salon">Salon</option>
                                            <option value="Auto Repair">Auto Repair</option>
                                            <option value="Electronics Repair">Electronics Repair</option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="service-label">Price <span>*</span></label>
                                    <div class="price-wrap">
                                        <span class="price-prefix">PKR</span>
                                        <input type="number" step="0.01" class="service-input price-input" name="price" id="service_price" placeholder="Enter Price" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="service-label">Duration <span>*</span></label>
                                    <select class="service-input" name="duration" id="service_duration" required>
                                        <option value="">Select Duration...</option>
                                        <option value="15 min">15 min</option>
                                        <option value="30 min">30 min</option>
                                        <option value="45 min">45 min</option>
                                        <option value="1 hour">1 hour</option>
                                        <option value="2 hours">2 hours</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="service-label">Branch</label>
                                    <select class="service-input" name="branch" id="service_branch">
                                        <option value="">Select Branch...</option>
                                        <?php if (!empty($branches)): ?>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?= e($branch['name']) ?>"><?= e($branch['name']) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="Main Branch">Main Branch</option>
                                            <option value="Northside Branch">Northside Branch</option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="service-label">Assign Staff</label>
                                    <select class="service-input" name="staff" id="service_staff">
                                        <option value="">Select Staff...</option>
                                        <?php foreach ($staffMembers as $staff): ?>
                                            <option value="<?= e($staff['display_name']) ?>">
                                                <?= e($staff['display_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="service-label">Description</label>
                                    <textarea class="service-textarea" name="description" id="service_description" placeholder="Enter service description..."></textarea>
                                </div>

                                <div class="col-md-4">
                                    <label class="service-label">Status</label>
                                    <select class="service-input" name="status" id="service_status">
                                        <option value="active">Active</option>
                                        <option value="disabled">Disabled</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer service-modal-footer">
                    <div class="popular-toggle-wrap">
                        <label class="switch">
                            <input type="checkbox" name="is_popular" id="service_is_popular" value="1">
                            <span class="slider"></span>
                        </label>
                        <span class="popular-label">Set as Popular Service</span>
                    </div>

                    <div class="footer-btns">
                        <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn" id="serviceSubmitBtn">Add Service</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_services">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Import Services</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <label class="form-label">Upload CSV File</label>
                    <input type="file" name="import_file" class="form-control" accept=".csv" required>
                    <div class="mt-2 text-muted small">
                        CSV columns:
                        name, category, price, duration, branch, staff, description, is_popular, status
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success" type="submit">Import Now</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Service Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>
</div>

<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete_service">
    <input type="hidden" name="id" id="delete_id">
</form>

<form method="POST" id="toggleStatusForm" style="display:none;">
    <input type="hidden" name="action" value="enable_disable_service">
    <input type="hidden" name="id" id="toggle_id">
    <input type="hidden" name="status" id="toggle_status">
</form>

<form method="POST" id="bulkDeleteForm" style="display:none;">
    <input type="hidden" name="action" value="bulk_delete">
    <div id="bulkDeleteInputs"></div>
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const serviceModal = new bootstrap.Modal(document.getElementById('serviceModal'));
const importModal  = new bootstrap.Modal(document.getElementById('importModal'));
const viewModal    = new bootstrap.Modal(document.getElementById('viewModal'));

function openImportModal() {
    importModal.show();
}

function resetServiceForm() {
    document.getElementById('serviceForm').reset();
    document.getElementById('service_id').value = '';
    document.getElementById('old_photo').value = '';
    document.getElementById('serviceFormAction').value = 'add_service';
    document.getElementById('serviceModalTitle').textContent = 'Add Service';
    document.getElementById('serviceSubmitBtn').textContent = 'Add Service';
    document.getElementById('service_status').value = 'active';

    document.getElementById('uploadPreview').innerHTML = `
        <i class="bi bi-camera-fill"></i>
        <div class="upload-title">Upload Photo</div>
        <div class="upload-subtext">JPG, PNG, WEBP</div>
    `;
}

function openCreateServiceModal() {
    resetServiceForm();
    serviceModal.show();
}

function editService(service) {
    resetServiceForm();

    document.getElementById('serviceFormAction').value = 'update_service';
    document.getElementById('serviceModalTitle').textContent = 'Edit Service';
    document.getElementById('serviceSubmitBtn').textContent = 'Update Service';

    document.getElementById('service_id').value = service.id ?? '';
    document.getElementById('service_name').value = service.name ?? '';
    document.getElementById('service_category').value = service.category ?? '';
    document.getElementById('service_price').value = service.price ?? '';
    document.getElementById('service_duration').value = service.duration ?? '';
    document.getElementById('service_branch').value = service.branch ?? '';
    document.getElementById('service_staff').value = service.staff ?? '';
    document.getElementById('service_description').value = service.description ?? '';
    document.getElementById('service_is_popular').checked = Number(service.is_popular) === 1;
    document.getElementById('service_status').value = service.status ?? 'active';
    document.getElementById('old_photo').value = service.photo ?? '';

    if (service.photo) {
        document.getElementById('uploadPreview').innerHTML = `<img src="uploads/services/${service.photo}" alt="Service Photo">`;
    }

    serviceModal.show();
}

function viewService(service) {
    let html = `
        <div class="mb-2"><strong>Name:</strong> ${escapeHtml(service.name || '')}</div>
        <div class="mb-2"><strong>Category:</strong> ${escapeHtml(service.category || '')}</div>
        <div class="mb-2"><strong>Price:</strong> ${parseFloat(service.price || 0).toFixed(2)} PKR</div>
        <div class="mb-2"><strong>Duration:</strong> ${escapeHtml(service.duration || '')}</div>
        <div class="mb-2"><strong>Branch:</strong> ${escapeHtml(service.branch || '')}</div>
        <div class="mb-2"><strong>Staff:</strong> ${escapeHtml(service.staff || '')}</div>
        <div class="mb-2"><strong>Status:</strong> ${escapeHtml(service.status || '')}</div>
        <div class="mb-2"><strong>Popular:</strong> ${(Number(service.is_popular) === 1 ? 'Yes' : 'No')}</div>
        <div class="mb-2"><strong>Description:</strong><br>${escapeHtml(service.description || '')}</div>
    `;
    document.getElementById('viewModalBody').innerHTML = html;
    viewModal.show();
}

document.getElementById('photoInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(event) {
        document.getElementById('uploadPreview').innerHTML = `<img src="${event.target.result}" alt="Preview">`;
    };
    reader.readAsDataURL(file);
});

function deleteService(id) {
    if (!confirm('Delete this service?')) return;
    document.getElementById('delete_id').value = id;
    document.getElementById('deleteForm').submit();
}

function toggleServiceStatus(id, status) {
    document.getElementById('toggle_id').value = id;
    document.getElementById('toggle_status').value = status;
    document.getElementById('toggleStatusForm').submit();
}

function toggleAllRows(main) {
    document.querySelectorAll('.rowCheck').forEach(el => el.checked = main.checked);
}

function applyBulkAction() {
    const action = document.getElementById('bulkAction').value;
    const ids = Array.from(document.querySelectorAll('.rowCheck:checked')).map(el => el.value);

    if (!action) {
        alert('Please select a bulk action.');
        return;
    }
    if (ids.length === 0) {
        alert('Please select at least one row.');
        return;
    }
    if (action === 'delete' && !confirm('Delete selected services?')) return;

    const container = document.getElementById('bulkDeleteInputs');
    container.innerHTML = '';

    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        container.appendChild(input);
    });

    document.getElementById('bulkDeleteForm').submit();
}

function exportCSV() {
    window.location.href = '<?= e($_SERVER['PHP_SELF']) ?>?export=1';
}

function printServiceReport() {
    window.print();
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>
</body>
</html>