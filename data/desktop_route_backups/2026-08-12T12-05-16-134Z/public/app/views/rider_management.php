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

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function json_out($arr) { header('Content-Type: application/json'); echo json_encode($arr); exit; }

function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $stmt->close();
    return (int)($row[0] ?? 0) > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $stmt->close();
    return (int)($row[0] ?? 0) > 0;
}

function qval(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? null;
}

function rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $data;
}

function nextDriverCode(mysqli $conn, int $client_id): string {
    $max = (int)qval(
        $conn,
        "SELECT COALESCE(MAX(CAST(SUBSTRING(driver_code, 5) AS UNSIGNED)), 0) FROM delivery_drivers WHERE client_id = ? AND driver_code LIKE 'DRV-%'",
        'i',
        [$client_id]
    );

    $n = max(1, $max + 1);
    do {
        $code = 'DRV-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $exists = (int)qval($conn, "SELECT COUNT(*) FROM delivery_drivers WHERE client_id=? AND driver_code=?", 'is', [$client_id, $code]);
        $n++;
    } while ($exists > 0);

    return $code;
}

function linkedDriverCount(mysqli $conn, int $client_id, int $driver_id): int {
    $count = 0;

    if (table_exists($conn, 'distribution_routes')) {
        $count += (int)qval($conn, "SELECT COUNT(*) FROM distribution_routes WHERE client_id=? AND driver_id=?", 'ii', [$client_id, $driver_id]);
    }

    if (table_exists($conn, 'distribution_route_deliveries')) {
        $count += (int)qval($conn, "SELECT COUNT(*) FROM distribution_route_deliveries WHERE client_id=? AND driver_id=?", 'ii', [$client_id, $driver_id]);
    }

    if (table_exists($conn, 'delivery_order_assignments')) {
        $count += (int)qval($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND driver_id=?", 'ii', [$client_id, $driver_id]);
    }

    return $count;
}

function driverWorkSummary(mysqli $conn, int $client_id, int $driver_id): array {
    $today = date('Y-m-d');
    $summary = [
        'routes' => 0,
        'today_deliveries' => 0,
        'today_orders' => 0,
        'pending_orders' => 0,
        'delivered_orders' => 0,
        'collection' => 0.00,
    ];

    if (table_exists($conn, 'distribution_routes')) {
        $summary['routes'] = (int)qval($conn, "SELECT COUNT(*) FROM distribution_routes WHERE client_id=? AND driver_id=?", 'ii', [$client_id, $driver_id]);
    }

    if (table_exists($conn, 'distribution_route_deliveries')) {
        $r = rows($conn, "
            SELECT 
                COUNT(*) AS today_deliveries,
                COALESCE(SUM(total_orders),0) AS today_orders,
                COALESCE(SUM(pending_orders),0) AS pending_orders,
                COALESCE(SUM(delivered_orders),0) AS delivered_orders,
                COALESCE(SUM(total_collection),0) AS collection
            FROM distribution_route_deliveries
            WHERE client_id=? AND driver_id=? AND delivery_date=?
        ", 'iis', [$client_id, $driver_id, $today]);

        if (!empty($r[0])) {
            $summary['today_deliveries'] = (int)$r[0]['today_deliveries'];
            $summary['today_orders']     = (int)$r[0]['today_orders'];
            $summary['pending_orders']   = (int)$r[0]['pending_orders'];
            $summary['delivered_orders'] = (int)$r[0]['delivered_orders'];
            $summary['collection']       = (float)$r[0]['collection'];
        }
    }

    return $summary;
}

/* ================= AJAX BACKEND - SAME FILE ================= */
if (isset($_POST['ajax']) || isset($_GET['ajax'])) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'driver_code') {
        json_out(['success' => true, 'code' => nextDriverCode($conn, $client_id)]);
    }

    if ($action === 'stats') {
        $today = date('Y-m-d');
        $stats = [
            'total'       => (int)qval($conn, "SELECT COUNT(*) FROM delivery_drivers WHERE client_id=?", 'i', [$client_id]),
            'available'   => (int)qval($conn, "SELECT COUNT(*) FROM delivery_drivers WHERE client_id=? AND status='Available'", 'i', [$client_id]),
            'on_delivery' => (int)qval($conn, "SELECT COUNT(*) FROM delivery_drivers WHERE client_id=? AND status='On Delivery'", 'i', [$client_id]),
            'inactive'    => (int)qval($conn, "SELECT COUNT(*) FROM delivery_drivers WHERE client_id=? AND status='Inactive'", 'i', [$client_id]),
            'routes'      => table_exists($conn, 'distribution_routes') ? (int)qval($conn, "SELECT COUNT(DISTINCT driver_id) FROM distribution_routes WHERE client_id=? AND driver_id IS NOT NULL", 'i', [$client_id]) : 0,
            'today_runs'  => table_exists($conn, 'distribution_route_deliveries') ? (int)qval($conn, "SELECT COUNT(*) FROM distribution_route_deliveries WHERE client_id=? AND delivery_date=?", 'is', [$client_id, $today]) : 0,
        ];
        json_out(['success' => true, 'stats' => $stats]);
    }

    if ($action === 'branches') {
        if (!table_exists($conn, 'branches')) json_out(['success' => true, 'data' => []]);
        $data = rows($conn, "SELECT id, name FROM branches WHERE client_id=? ORDER BY name ASC", 'i', [$client_id]);
        json_out(['success' => true, 'data' => $data]);
    }

    if ($action === 'get_riders') {
        $page   = max(1, (int)($_POST['page'] ?? 1));
        $limit  = max(5, min(100, (int)($_POST['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        $search = trim($_POST['search'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $vehicle = trim($_POST['vehicle'] ?? '');
        $branchFilter = trim($_POST['branch_id'] ?? '');

        $where = "d.client_id=?";
        $types = 'i';
        $params = [$client_id];

        if ($search !== '') {
            $where .= " AND (d.driver_code LIKE ? OR d.driver_name LIKE ? OR d.phone LIKE ? OR d.cnic LIKE ? OR d.license_no LIKE ? OR d.vehicle LIKE ?)";
            $like = "%$search%";
            $types .= 'ssssss';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        if ($status !== '') {
            $where .= " AND d.status=?";
            $types .= 's';
            $params[] = $status;
        }

        if ($vehicle !== '') {
            $where .= " AND d.vehicle LIKE ?";
            $types .= 's';
            $params[] = "%$vehicle%";
        }

        if ($branchFilter !== '' && $branchFilter !== 'all') {
            $where .= " AND d.branch_id=?";
            $types .= 'i';
            $params[] = (int)$branchFilter;
        }

        $branchNameSql = table_exists($conn, 'branches') ? "b.name AS branch_name" : "NULL AS branch_name";
        $joinBranchSql = table_exists($conn, 'branches') ? "LEFT JOIN branches b ON b.id=d.branch_id AND b.client_id=d.client_id" : "";

        $total = (int)qval($conn, "SELECT COUNT(*) FROM delivery_drivers d $joinBranchSql WHERE $where", $types, $params);

        $routeCountSql = table_exists($conn, 'distribution_routes')
            ? "(SELECT COUNT(*) FROM distribution_routes r WHERE r.client_id=d.client_id AND r.driver_id=d.id) AS route_count"
            : "0 AS route_count";

        $todayDeliverySql = table_exists($conn, 'distribution_route_deliveries')
            ? "(SELECT COUNT(*) FROM distribution_route_deliveries dd WHERE dd.client_id=d.client_id AND dd.driver_id=d.id AND dd.delivery_date=CURDATE()) AS today_deliveries"
            : "0 AS today_deliveries";

        $todayOrderSql = table_exists($conn, 'distribution_route_deliveries')
            ? "(SELECT COALESCE(SUM(total_orders),0) FROM distribution_route_deliveries dd WHERE dd.client_id=d.client_id AND dd.driver_id=d.id AND dd.delivery_date=CURDATE()) AS today_orders"
            : "0 AS today_orders";

        $sql = "
            SELECT d.*, $branchNameSql,
                   $routeCountSql,
                   $todayDeliverySql,
                   $todayOrderSql
            FROM delivery_drivers d
            $joinBranchSql
            WHERE $where
            ORDER BY d.id DESC
            LIMIT ? OFFSET ?
        ";
        $types2 = $types . 'ii';
        $params2 = $params;
        $params2[] = $limit;
        $params2[] = $offset;

        json_out([
            'success' => true,
            'data' => rows($conn, $sql, $types2, $params2),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit))
        ]);
    }

    if ($action === 'save_rider') {
        $id = (int)($_POST['id'] ?? 0);
        $driver_code = trim($_POST['driver_code'] ?? '');
        $driver_name = trim($_POST['driver_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $cnic = trim($_POST['cnic'] ?? '');
        $vehicle = trim($_POST['vehicle'] ?? '');
        $license_no = trim($_POST['license_no'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = trim($_POST['status'] ?? 'Available');
        $form_branch_id = ($_POST['branch_id'] ?? '') !== '' ? (int)$_POST['branch_id'] : $branch_id;

        $allowedStatuses = ['Available','On Delivery','Break','Inactive'];
        if (!in_array($status, $allowedStatuses, true)) $status = 'Available';

        if ($driver_name === '') json_out(['success' => false, 'message' => 'Rider name required hai.']);
        if ($phone === '') json_out(['success' => false, 'message' => 'Phone number required hai.']);
        if ($vehicle === '') json_out(['success' => false, 'message' => 'Vehicle required hai.']);
        if ($driver_code === '') $driver_code = nextDriverCode($conn, $client_id);

        $duplicate = (int)qval(
            $conn,
            "SELECT COUNT(*) FROM delivery_drivers WHERE client_id=? AND driver_code=? AND id<>?",
            'isi',
            [$client_id, $driver_code, $id]
        );
        if ($duplicate > 0) json_out(['success' => false, 'message' => 'Driver code already exists.']);

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE delivery_drivers SET branch_id=?, driver_code=?, driver_name=?, phone=?, cnic=?, vehicle=?, license_no=?, address=?, status=? WHERE id=? AND client_id=?");
            if (!$stmt) json_out(['success' => false, 'message' => $conn->error]);
            $stmt->bind_param('issssssssii', $form_branch_id, $driver_code, $driver_name, $phone, $cnic, $vehicle, $license_no, $address, $status, $id, $client_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO delivery_drivers (client_id, branch_id, driver_code, driver_name, phone, cnic, vehicle, license_no, address, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) json_out(['success' => false, 'message' => $conn->error]);
            $stmt->bind_param('iissssssssi', $client_id, $form_branch_id, $driver_code, $driver_name, $phone, $cnic, $vehicle, $license_no, $address, $status, $user_id);
        }

        if (!$stmt->execute()) json_out(['success' => false, 'message' => $stmt->error]);
        $stmt->close();
        json_out(['success' => true, 'message' => 'Rider saved successfully.']);
    }

    if ($action === 'rider_detail') {
        $id = (int)($_POST['id'] ?? 0);
        $branchNameSql = table_exists($conn, 'branches') ? "b.name AS branch_name" : "NULL AS branch_name";
        $joinBranchSql = table_exists($conn, 'branches') ? "LEFT JOIN branches b ON b.id=d.branch_id AND b.client_id=d.client_id" : "";
        $r = rows($conn, "SELECT d.*, $branchNameSql FROM delivery_drivers d $joinBranchSql WHERE d.id=? AND d.client_id=?", 'ii', [$id, $client_id]);
        if (empty($r)) json_out(['success' => false, 'message' => 'Rider not found.']);
        $summary = driverWorkSummary($conn, $client_id, $id);
        json_out(['success' => true, 'data' => $r[0], 'summary' => $summary]);
    }

    if ($action === 'delete_rider') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid rider.']);

        $linked = linkedDriverCount($conn, $client_id, $id);
        if ($linked > 0) {
            $stmt = $conn->prepare("UPDATE delivery_drivers SET status='Inactive' WHERE id=? AND client_id=?");
            $stmt->bind_param('ii', $id, $client_id);
            $stmt->execute();
            $stmt->close();
            json_out(['success' => true, 'message' => 'Rider routes/deliveries me linked tha, isliye delete ke bajaye Inactive kar diya.']);
        }

        $stmt = $conn->prepare("DELETE FROM delivery_drivers WHERE id=? AND client_id=?");
        if (!$stmt) json_out(['success' => false, 'message' => $conn->error]);
        $stmt->bind_param('ii', $id, $client_id);
        if (!$stmt->execute()) json_out(['success' => false, 'message' => $stmt->error]);
        $stmt->close();
        json_out(['success' => true, 'message' => 'Rider deleted successfully.']);
    }

    if ($action === 'bulk_status' || $action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) json_out(['success' => false, 'message' => 'Please select riders.']);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) json_out(['success' => false, 'message' => 'Invalid riders selected.']);

        if ($action === 'bulk_status') {
            $status = trim($_POST['status'] ?? 'Available');
            $allowedStatuses = ['Available','On Delivery','Break','Inactive'];
            if (!in_array($status, $allowedStatuses, true)) $status = 'Available';

            $in = implode(',', array_fill(0, count($ids), '?'));
            $types = 's' . str_repeat('i', count($ids)) . 'i';
            $params = array_merge([$status], $ids, [$client_id]);
            $stmt = $conn->prepare("UPDATE delivery_drivers SET status=? WHERE id IN ($in) AND client_id=?");
            if (!$stmt) json_out(['success' => false, 'message' => $conn->error]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            json_out(['success' => true, 'message' => 'Bulk status updated.']);
        }

        $deleted = 0;
        $inactive = 0;
        foreach ($ids as $id) {
            $linked = linkedDriverCount($conn, $client_id, $id);
            if ($linked > 0) {
                $stmt = $conn->prepare("UPDATE delivery_drivers SET status='Inactive' WHERE id=? AND client_id=?");
                $stmt->bind_param('ii', $id, $client_id);
                $stmt->execute();
                $stmt->close();
                $inactive++;
            } else {
                $stmt = $conn->prepare("DELETE FROM delivery_drivers WHERE id=? AND client_id=?");
                $stmt->bind_param('ii', $id, $client_id);
                $stmt->execute();
                $stmt->close();
                $deleted++;
            }
        }
        json_out(['success' => true, 'message' => "Bulk done. Deleted: $deleted | Inactive: $inactive"]);
    }

    json_out(['success' => false, 'message' => 'Invalid action.']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php include('partials/head.php'); ?>
    <style>
    :root {
        --sidebar-width: 250px;
        --topbar-height: 60px;
        --gold: #f2b90f;
        --page-bg: #f3f6fc;
        --primary: #2f55a4;
        --text: #10203a;
        --muted: #667085;
        --border: #dfe6f2;
        --green: #16a34a;
        --red: #dc2626;
        --orange: #d97706;
    }

    body {
        margin: 0;
        background: var(--page-bg);
        font-family: "Segoe UI", Tahoma, sans-serif;
        color: var(--text);
    }

    .container-scroll {
        display: flex;
        min-height: 100vh;
    }

    .sidebar {
        width: var(--sidebar-width);
        flex-shrink: 0;
    }

    .main-panel {
        flex: 1;
        min-width: 0;
    }

    .topbar {
        height: var(--topbar-height);
        background: #050505;
        display: flex;
        align-items: center;
        padding: 0 22px;
    }

    .content-wrapper {
        padding: 18px 20px 34px;
    }

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }

    .page-title {
        font-size: 28px;
        font-weight: 800;
    }

    .page-subtitle {
        color: var(--muted);
        font-size: 13px;
    }

    .btn-add {
        height: 44px;
        border: none;
        border-radius: 10px;
        background: var(--gold);
        color: #111827;
        font-weight: 800;
        padding: 0 18px;
    }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 16px;
    }

    .kpi-card {
        min-height: 86px;
        border-radius: 10px;
        padding: 14px 18px;
        color: #fff;
        display: flex;
        align-items: center;
        box-shadow: 0 12px 26px rgba(15, 23, 42, .10);
        overflow: hidden;
        position: relative;
    }

    .kpi-card:nth-child(1) {
        background: linear-gradient(135deg, #314d97, #415ba8);
    }

    .kpi-card:nth-child(2) {
        background: linear-gradient(135deg, #65ad49, #56a142);
    }

    .kpi-card:nth-child(3) {
        background: linear-gradient(135deg, #b93645, #d75a68);
    }

    .kpi-card:nth-child(4) {
        background: linear-gradient(135deg, #e2ad13, #f6bd1d);
    }

    .kpi-icon {
        font-size: 24px;
        margin-right: 12px;
    }

    .kpi-label {
        font-size: 15px;
        font-weight: 700;
    }

    .kpi-value {
        font-size: 30px;
        font-weight: 800;
        margin-left: auto;
    }

    .filter-card,
    .table-card {
        background: #fff;
        border: 1px solid #e3e8f0;
        border-radius: 12px;
        box-shadow: 0 10px 24px rgba(18, 38, 63, .06);
        margin-bottom: 14px;
    }

    .filter-card {
        padding: 12px;
    }

    .filter-row {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .filter-row input,
    .filter-row select {
        height: 44px;
        border: 1px solid #dbe2ed;
        border-radius: 10px;
        padding: 0 14px;
        min-width: 150px;
    }

    .filter-search {
        flex: 1;
        min-width: 260px;
    }

    .filter-btn {
        height: 44px;
        border: 0;
        border-radius: 10px;
        padding: 0 18px;
        background: #1f2746;
        color: #fff;
        font-weight: 800;
    }

    .reset-btn {
        height: 44px;
        border: 0;
        border-radius: 10px;
        padding: 0 18px;
        background: #eef2f7;
        color: #4b5563;
        font-weight: 800;
    }

    .table-card-header {
        padding: 14px 16px;
        border-bottom: 1px solid #e8edf5;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .table-title {
        font-size: 18px;
        font-weight: 800;
    }

    .table-count {
        background: #eff5ff;
        color: #2f55a4;
        border-radius: 999px;
        padding: 5px 12px;
        font-size: 13px;
        font-weight: 700;
    }

    .table-responsive {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1080px;
    }

    thead th {
        background: #28344d;
        color: #fff;
        padding: 14px 12px;
        font-size: 14px;
        white-space: nowrap;
    }

    tbody td {
        padding: 14px 12px;
        border-bottom: 1px solid #edf1f6;
        font-size: 14px;
        vertical-align: middle;
    }

    .driver-code {
        background: #eef4ff;
        color: #2f55a4;
        font-weight: 800;
        padding: 5px 10px;
        border-radius: 8px;
    }

    .muted-small {
        display: block;
        color: #6b7280;
        font-size: 12px;
        margin-top: 2px;
    }

    .status-badge {
        padding: 4px 9px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .status-available {
        background: #dcfce7;
        color: #16a34a;
    }

    .status-on-delivery {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .status-break {
        background: #fef3c7;
        color: #d97706;
    }

    .status-inactive {
        background: #fee2e2;
        color: #dc2626;
    }

    .action-btns {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .action-btn {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        border: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
    }

    .action-btn.view {
        background: rgba(99, 102, 241, .14);
        color: #6366f1;
    }

    .action-btn.edit {
        background: rgba(34, 197, 94, .14);
        color: #16a34a;
    }

    .action-btn.delete {
        background: rgba(239, 68, 68, .14);
        color: #ef4444;
    }

    .pagination-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 18px;
        border-top: 1px solid #f3f4f6;
        flex-wrap: wrap;
        gap: 10px;
    }

    .pag-btns {
        display: flex;
        gap: 5px;
    }

    .pag-btn {
        min-width: 36px;
        height: 36px;
        border: 1px solid var(--border);
        background: #fff;
        border-radius: 9px;
        font-weight: 700;
    }

    .pag-btn.active {
        background: var(--primary);
        color: #fff;
    }

    .bulk-bar {
        display: none;
        background: #1e40af;
        color: #fff;
        padding: 10px 18px;
        gap: 12px;
        align-items: center;
        font-size: 13px;
        font-weight: 700;
        flex-wrap: wrap;
    }

    .bulk-bar.show {
        display: flex;
    }

    .bulk-btn {
        background: rgba(255, 255, 255, .16);
        border: 1px solid rgba(255, 255, 255, .3);
        color: #fff;
        border-radius: 7px;
        padding: 5px 12px;
    }

    .form-control-custom {
        width: 100%;
        height: 42px;
        border: 1px solid var(--border);
        border-radius: 9px;
        padding: 0 11px;
    }

    textarea.form-control-custom {
        height: 82px;
        padding: 9px 11px;
        resize: vertical;
    }

    .toast-container {
        position: fixed;
        top: 75px;
        right: 20px;
        z-index: 9999;
    }

    .toast-msg {
        background: #fff;
        border-left: 4px solid var(--primary);
        border-radius: 10px;
        padding: 12px 16px;
        margin-bottom: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, .15);
        font-weight: 700;
    }

    .toast-msg.success {
        border-left-color: var(--green);
    }

    .toast-msg.error {
        border-left-color: var(--red);
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .detail-box {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 12px;
    }

    .detail-box b {
        display: block;
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 3px;
    }

    @media(max-width:900px) {
        .sidebar {
            display: none;
        }

        .topbar {
            margin-left: 0;
        }

        .kpi-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .detail-grid {
            grid-template-columns: 1fr;
        }
    }

    @media(max-width:560px) {
        .kpi-grid {
            grid-template-columns: 1fr;
        }

        .filter-search {
            min-width: 100%;
        }
    }
    
    /* =====================================================
   RIDER_MANAGEMENT.PHP DASHBOARD DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.14);
    --oms-border-2:rgba(148,163,184,.22);
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
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= SIDEBAR / LAYOUT ================= */
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
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
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
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
    transition:margin-left .25s ease;
}

/* Collapsed sidebar support */
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

/* ================= TOPBAR ================= */
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

/* ================= CONTENT BACKGROUND ================= */
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
    position:relative;
    z-index:1;
}

/* ================= HEADER ================= */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-subtitle,
.muted-small,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

/* ================= ADD BUTTON ================= */
.btn-add{
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

.btn-add:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* ================= KPI CARDS ================= */
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

/* ================= GLASS FILTER + TABLE CARD ================= */
.filter-card,
.table-card{
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

.filter-card:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-card{
    padding:14px !important;
    margin-bottom:16px !important;
}

.table-card{
    overflow:hidden !important;
}

/* ================= FILTER INPUTS ================= */
.filter-row{
    gap:10px !important;
}

.filter-row input,
.filter-row select,
.form-control-custom{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-row input::placeholder,
.form-control-custom::placeholder{
    color:#94a3b8 !important;
}

.filter-row input:focus,
.filter-row select:focus,
.form-control-custom:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-row select option,
.form-control-custom option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* ================= BUTTONS ================= */
.filter-btn{
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

.reset-btn{
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

.filter-btn:hover,
.reset-btn:hover{
    transform:translateY(-1px);
    border-color:rgba(139,92,246,.42) !important;
}

/* ================= TABLE HEADER ================= */
.table-card-header{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
}

.table-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.table-count{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
}

/* ================= TABLE DARK GLASS ================= */
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

table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

.driver-code{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
    font-weight:900 !important;
    padding:7px 12px !important;
    border-radius:999px !important;
}

/* ================= STATUS BADGES ================= */
.status-badge{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.status-available{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.status-on-delivery{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border:1px solid rgba(59,130,246,.24) !important;
}

.status-break{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.status-inactive{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* ================= ACTION BUTTONS ================= */
.action-btn{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    border:none !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.action-btn.view{
    background:rgba(109,76,255,.18) !important;
    color:#c4b5fd !important;
}

.action-btn.edit{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
}

.action-btn.delete{
    background:rgba(239,68,68,.16) !important;
    color:#f87171 !important;
}

.action-btn:hover{
    transform:translateY(-2px);
    color:#fff !important;
}

/* ================= PAGINATION / BULK ================= */
.pagination-bar{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#94a3b8 !important;
}

.pag-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:10px !important;
}

.pag-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.42) !important;
}

.bulk-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(109,76,255,.82), rgba(15,23,42,.72)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    color:#fff !important;
}

.bulk-btn{
    background:rgba(15,23,42,.45) !important;
    border:1px solid rgba(255,255,255,.20) !important;
    color:#fff !important;
    border-radius:10px !important;
    font-weight:800 !important;
}

/* ================= TOAST ================= */
.toast-msg{
    background:#0f172a !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-left:4px solid #7c3aed !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}

.toast-msg.success{
    border-left-color:#22c55e !important;
}

.toast-msg.error{
    border-left-color:#ef4444 !important;
}

/* ================= SCROLLBAR ================= */
.table-responsive::-webkit-scrollbar{
    height:10px;
}

.table-responsive::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-responsive::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-responsive::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}
   
   /* =====================================================
   RIDER MANAGEMENT POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

#riderFormModal .modal-dialog,
#detailModal .modal-dialog{
    max-width:min(680px,92vw) !important;
}

#riderFormModal .modal-content,
#detailModal .modal-content{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#riderFormModal .modal-header,
#detailModal .modal-header,
#riderFormModal .modal-header.bg-dark,
#detailModal .modal-header.bg-dark{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    color:#fff !important;
}

#riderFormModal .modal-title,
#detailModal .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#riderFormModal .modal-title::before,
#detailModal .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#riderFormModal .btn-close,
#detailModal .btn-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#riderFormModal .btn-close::before,
#riderFormModal .btn-close::after,
#detailModal .btn-close::before,
#detailModal .btn-close::after{
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

#riderFormModal .btn-close::before,
#detailModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#riderFormModal .btn-close::after,
#detailModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#riderFormModal .btn-close:hover,
#detailModal .btn-close:hover{
    background:#ef4444 !important;
}

#riderFormModal .modal-body,
#detailModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#riderFormModal .row.g-3{
    --bs-gutter-x:12px !important;
    --bs-gutter-y:10px !important;
}

#riderFormModal label,
#detailModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#riderFormModal .form-control-custom,
#riderFormModal input,
#riderFormModal select,
#riderFormModal textarea{
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

#riderFormModal textarea.form-control-custom{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#riderFormModal input[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

#riderFormModal input::placeholder,
#riderFormModal textarea::placeholder{
    color:#94a3b8 !important;
}

#riderFormModal .form-control-custom:focus,
#riderFormModal input:focus,
#riderFormModal select:focus,
#riderFormModal textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#riderFormModal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

#riderFormModal .modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#riderFormModal .btn-light{
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

#riderFormModal .btn-primary{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#riderFormModal .btn-light:hover,
#riderFormModal .btn-primary:hover{
    transform:translateY(-1px);
}

/* Detail popup boxes */
#detailModal .detail-grid{
    gap:12px !important;
}

#detailModal .detail-box{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#detailModal .detail-box b{
    color:#94a3b8 !important;
    font-size:12px !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
    margin-bottom:5px !important;
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
                        <div class="page-title"><i class="bi bi-person-badge me-2"></i>Rider Management</div>
                        <div class="page-subtitle">Distribution riders/drivers → routes → daily delivery workload</div>
                    </div>
                    <button class="btn-add" onclick="openRiderModal()"><i class="bi bi-plus-lg me-1"></i>Add New
                        Rider</button>
                </div>

                <div class="kpi-grid">
                    <div class="kpi-card"><i class="bi bi-people kpi-icon"></i>
                        <div class="kpi-label">Total Riders</div>
                        <div class="kpi-value" id="kpiTotal">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-check-circle kpi-icon"></i>
                        <div class="kpi-label">Available</div>
                        <div class="kpi-value" id="kpiAvailable">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-truck kpi-icon"></i>
                        <div class="kpi-label">On Delivery</div>
                        <div class="kpi-value" id="kpiOnDelivery">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-signpost-2 kpi-icon"></i>
                        <div class="kpi-label">Assigned Routes</div>
                        <div class="kpi-value" id="kpiRoutes">0</div>
                    </div>
                </div>

                <div class="filter-card">
                    <div class="filter-row">
                        <input id="filterSearch" class="filter-search"
                            placeholder="Search rider, code, phone, CNIC, vehicle">
                        <select id="filterStatus">
                            <option value="">All Status</option>
                            <option>Available</option>
                            <option>On Delivery</option>
                            <option>Break</option>
                            <option>Inactive</option>
                        </select>
                        <select id="filterBranch">
                            <option value="all">All Branches</option>
                        </select>
                        <input id="filterVehicle" placeholder="Vehicle">
                        <button class="filter-btn" onclick="loadRiders(1)"><i class="bi bi-funnel"></i> Filter</button>
                        <button class="reset-btn" onclick="resetFilters()">Reset</button>
                    </div>
                </div>

                <div class="table-card">
                    <div class="bulk-bar" id="bulkBar">
                        <span id="bulkCount">0</span> riders selected
                        <button class="bulk-btn" onclick="bulkStatus('Available')">Available</button>
                        <button class="bulk-btn" onclick="bulkStatus('On Delivery')">On Delivery</button>
                        <button class="bulk-btn" onclick="bulkStatus('Break')">Break</button>
                        <button class="bulk-btn" onclick="bulkStatus('Inactive')">Inactive</button>
                        <button class="bulk-btn" onclick="bulkDelete()">Delete</button>
                        <button class="bulk-btn ms-auto" onclick="clearSelection()">Cancel</button>
                    </div>
                    <div class="table-card-header">
                        <div class="table-title">Riders List</div>
                        <span class="table-count" id="riderCount">0 Riders</span>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                                    <th>Code</th>
                                    <th>Rider</th>
                                    <th>Phone</th>
                                    <th>CNIC</th>
                                    <th>Vehicle</th>
                                    <th>License</th>
                                    <th>Branch</th>
                                    <th>Routes</th>
                                    <th>Today Runs</th>
                                    <th>Today Orders</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="riderTableBody"></tbody>
                        </table>
                    </div>
                    <div class="pagination-bar">
                        <div id="riderPagInfo"></div>
                        <div class="pag-btns" id="riderPagBtns"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <div class="modal fade" id="riderFormModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="riderModalTitle">Add New Rider</h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editRiderId">
                    <div class="row g-3">
                        <div class="col-md-4"><label>Rider Code</label><input id="fCode" class="form-control-custom"
                                readonly></div>
                        <div class="col-md-4"><label>Rider Name <span class="text-danger">*</span></label><input
                                id="fName" class="form-control-custom" placeholder="Ali Khan"></div>
                        <div class="col-md-4"><label>Phone <span class="text-danger">*</span></label><input id="fPhone"
                                class="form-control-custom" placeholder="0300xxxxxxx"></div>
                        <div class="col-md-4"><label>CNIC</label><input id="fCnic" class="form-control-custom"
                                placeholder="42101-xxxxxxx-x"></div>
                        <div class="col-md-4"><label>Vehicle <span class="text-danger">*</span></label><input
                                id="fVehicle" class="form-control-custom" placeholder="Bike / Suzuki Pickup"></div>
                        <div class="col-md-4"><label>License No</label><input id="fLicense" class="form-control-custom"
                                placeholder="License number"></div>
                        <div class="col-md-6"><label>Branch</label><select id="fBranch" class="form-control-custom">
                                <option value="0">Default / No Branch</option>
                            </select></div>
                        <div class="col-md-6"><label>Status</label><select id="fStatus" class="form-control-custom">
                                <option>Available</option>
                                <option>On Delivery</option>
                                <option>Break</option>
                                <option>Inactive</option>
                            </select></div>
                        <div class="col-md-12"><label>Address</label><textarea id="fAddress" class="form-control-custom"
                                placeholder="Rider address"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" onclick="saveRider()">Save Rider</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Rider Detail</h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailBody"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const pageUrl = '<?= basename(__FILE__) ?>';
    let riderPage = 1;

    function post(action, data = {}) {
        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('action', action);
        Object.keys(data).forEach(k => {
            if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k + '[]', v));
            else fd.append(k, data[k]);
        });
        return fetch(pageUrl, {
            method: 'POST',
            body: fd
        }).then(r => r.json());
    }

    function esc(v) {
        return String(v ?? '').replace(/[&<>'"]/g, m => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        } [m]));
    }

    function toast(msg, type = 'success') {
        const el = document.createElement('div');
        el.className = 'toast-msg ' + type;
        el.innerHTML = esc(msg);
        document.getElementById('toastContainer').appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    function statusClass(s) {
        s = String(s || '').toLowerCase().replace(/\s+/g, '-');
        if (s === 'available') return 'status-available';
        if (s === 'on-delivery') return 'status-on-delivery';
        if (s === 'break') return 'status-break';
        return 'status-inactive';
    }

    function pag(container, page, pages, fn) {
        let html = `<button class="pag-btn" ${page<=1?'disabled':''} onclick="${fn}(${page-1})">‹</button>`;
        for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) {
            html += `<button class="pag-btn ${i===page?'active':''}" onclick="${fn}(${i})">${i}</button>`;
        }
        html += `<button class="pag-btn" ${page>=pages?'disabled':''} onclick="${fn}(${page+1})">›</button>`;
        document.getElementById(container).innerHTML = html;
    }

    function loadStats() {
        post('stats').then(res => {
            if (!res.success) return;
            const s = res.stats;
            kpiTotal.innerText = s.total || 0;
            kpiAvailable.innerText = s.available || 0;
            kpiOnDelivery.innerText = s.on_delivery || 0;
            kpiRoutes.innerText = s.routes || 0;
        });
    }

    function loadBranches() {
        post('branches').then(res => {
            let filterOptions = '<option value="all">All Branches</option>';
            let formOptions = '<option value="0">Default / No Branch</option>';
            (res.data || []).forEach(b => {
                filterOptions += `<option value="${esc(b.id)}">${esc(b.name)}</option>`;
                formOptions += `<option value="${esc(b.id)}">${esc(b.name)}</option>`;
            });
            filterBranch.innerHTML = filterOptions;
            fBranch.innerHTML = formOptions;
        });
    }

    function loadRiders(p = 1) {
        riderPage = p;
        post('get_riders', {
            page: p,
            limit: 10,
            search: filterSearch.value,
            status: filterStatus.value,
            vehicle: filterVehicle.value,
            branch_id: filterBranch.value
        }).then(res => {
            let html = '';
            (res.data || []).forEach(r => {
                html += `<tr>
                <td><input type="checkbox" class="row-check" value="${esc(r.id)}" onchange="updateBulk()"></td>
                <td><span class="driver-code">${esc(r.driver_code)}</span></td>
                <td><b>${esc(r.driver_name)}</b><span class="muted-small">Added: ${esc((r.created_at || '').slice(0,10) || '-')}</span></td>
                <td>${esc(r.phone)}</td>
                <td>${esc(r.cnic || '-')}</td>
                <td>${esc(r.vehicle || '-')}</td>
                <td>${esc(r.license_no || '-')}</td>
                <td>${esc(r.branch_name || 'Default')}</td>
                <td>${Number(r.route_count || 0).toLocaleString()}</td>
                <td>${Number(r.today_deliveries || 0).toLocaleString()}</td>
                <td>${Number(r.today_orders || 0).toLocaleString()}</td>
                <td><span class="status-badge ${statusClass(r.status)}">${esc(r.status)}</span></td>
                <td><div class="action-btns">
                    <button class="action-btn view" onclick="viewRider(${Number(r.id)})"><i class="bi bi-eye"></i></button>
                    <button class="action-btn edit" onclick='editRider(${JSON.stringify(r).replace(/'/g, '&#39;')})'><i class="bi bi-pencil"></i></button>
                    <button class="action-btn delete" onclick="deleteRider(${Number(r.id)})"><i class="bi bi-trash"></i></button>
                </div></td>
            </tr>`;
            });

            riderTableBody.innerHTML = html ||
                '<tr><td colspan="13" class="text-center py-4">No riders found</td></tr>';
            riderCount.innerText = `${res.total || 0} Riders`;
            riderPagInfo.innerText = `Showing page ${res.page} of ${res.pages} (${res.total} riders)`;
            pag('riderPagBtns', res.page, res.pages, 'loadRiders');
            clearSelection(false);
            loadStats();
        }).catch(() => toast('Riders load failed.', 'error'));
    }

    function resetFilters() {
        filterSearch.value = '';
        filterStatus.value = '';
        filterVehicle.value = '';
        filterBranch.value = 'all';
        loadRiders(1);
    }

    function openRiderModal() {
        editRiderId.value = '';
        riderModalTitle.innerText = 'Add New Rider';
        ['fName', 'fPhone', 'fCnic', 'fVehicle', 'fLicense', 'fAddress'].forEach(id => document.getElementById(id)
            .value = '');
        fStatus.value = 'Available';
        fBranch.value = '0';
        post('driver_code').then(r => fCode.value = r.code || 'DRV-001');
        new bootstrap.Modal(riderFormModal).show();
    }

    function editRider(r) {
        editRiderId.value = r.id || '';
        fCode.value = r.driver_code || '';
        fName.value = r.driver_name || '';
        fPhone.value = r.phone || '';
        fCnic.value = r.cnic || '';
        fVehicle.value = r.vehicle || '';
        fLicense.value = r.license_no || '';
        fAddress.value = r.address || '';
        fStatus.value = r.status || 'Available';
        fBranch.value = String(r.branch_id || 0);
        riderModalTitle.innerText = 'Edit Rider';
        new bootstrap.Modal(riderFormModal).show();
    }

    function saveRider() {
        post('save_rider', {
            id: editRiderId.value,
            driver_code: fCode.value,
            driver_name: fName.value,
            phone: fPhone.value,
            cnic: fCnic.value,
            vehicle: fVehicle.value,
            license_no: fLicense.value,
            address: fAddress.value,
            status: fStatus.value,
            branch_id: fBranch.value
        }).then(res => {
            toast(res.message, res.success ? 'success' : 'error');
            if (res.success) {
                bootstrap.Modal.getInstance(riderFormModal).hide();
                loadRiders(riderPage);
            }
        }).catch(() => toast('Save request failed.', 'error'));
    }

    function viewRider(id) {
        post('rider_detail', {
            id
        }).then(res => {
            if (!res.success) {
                toast(res.message || 'Rider not found', 'error');
                return;
            }
            const r = res.data;
            const s = res.summary || {};
            detailBody.innerHTML = `<div class="detail-grid">
            <div class="detail-box"><b>Rider Code</b>${esc(r.driver_code)}</div>
            <div class="detail-box"><b>Name</b>${esc(r.driver_name)}</div>
            <div class="detail-box"><b>Phone</b>${esc(r.phone)}</div>
            <div class="detail-box"><b>CNIC</b>${esc(r.cnic || '-')}</div>
            <div class="detail-box"><b>Vehicle</b>${esc(r.vehicle || '-')}</div>
            <div class="detail-box"><b>License No</b>${esc(r.license_no || '-')}</div>
            <div class="detail-box"><b>Branch</b>${esc(r.branch_name || 'Default')}</div>
            <div class="detail-box"><b>Status</b><span class="status-badge ${statusClass(r.status)}">${esc(r.status)}</span></div>
            <div class="detail-box"><b>Assigned Routes</b>${Number(s.routes || 0).toLocaleString()}</div>
            <div class="detail-box"><b>Today Deliveries</b>${Number(s.today_deliveries || 0).toLocaleString()}</div>
            <div class="detail-box"><b>Today Orders</b>${Number(s.today_orders || 0).toLocaleString()}</div>
            <div class="detail-box"><b>Pending Orders</b>${Number(s.pending_orders || 0).toLocaleString()}</div>
            <div class="detail-box"><b>Delivered Orders</b>${Number(s.delivered_orders || 0).toLocaleString()}</div>
            <div class="detail-box"><b>Today Collection</b>PKR ${Number(s.collection || 0).toLocaleString()}</div>
            <div class="detail-box" style="grid-column:1/-1"><b>Address</b>${esc(r.address || '-')}</div>
        </div>`;
            new bootstrap.Modal(detailModal).show();
        });
    }

    function deleteRider(id) {
        if (!confirm('Delete this rider? Agar rider route/delivery me linked hua to system usko Inactive karega.'))
            return;
        post('delete_rider', {
            id
        }).then(res => {
            toast(res.message, res.success ? 'success' : 'error');
            loadRiders(riderPage);
        });
    }

    function toggleSelectAll(el) {
        document.querySelectorAll('.row-check').forEach(c => c.checked = el.checked);
        updateBulk();
    }

    function updateBulk() {
        const n = document.querySelectorAll('.row-check:checked').length;
        bulkBar.classList.toggle('show', n > 0);
        bulkCount.innerText = n;
    }

    function clearSelection(update = true) {
        document.querySelectorAll('.row-check').forEach(c => c.checked = false);
        if (document.getElementById('selectAll')) selectAll.checked = false;
        if (update) updateBulk();
        else bulkBar.classList.remove('show');
    }

    function selectedRiderIds() {
        return [...document.querySelectorAll('.row-check:checked')].map(c => c.value);
    }

    function bulkStatus(status) {
        post('bulk_status', {
            ids: selectedRiderIds(),
            status
        }).then(res => {
            toast(res.message, res.success ? 'success' : 'error');
            clearSelection();
            loadRiders(riderPage);
        });
    }

    function bulkDelete() {
        if (!confirm('Delete selected riders? Linked riders will be marked Inactive.')) return;
        post('bulk_delete', {
            ids: selectedRiderIds()
        }).then(res => {
            toast(res.message, res.success ? 'success' : 'error');
            clearSelection();
            loadRiders(riderPage);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        loadBranches();
        loadStats();
        loadRiders(1);
    });
    </script>
</body>

</html>