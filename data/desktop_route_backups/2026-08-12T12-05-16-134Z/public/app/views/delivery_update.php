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

function du_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function du_json($arr){ header('Content-Type: application/json'); echo json_encode($arr); exit; }

function du_table_exists(mysqli $conn, string $table): bool {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return $cache[$table] = false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$table] = ((int)($row['c'] ?? 0) > 0);
}

function du_col_exists(mysqli $conn, string $table, string $col): bool {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $key = $table . '.' . $col;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return $cache[$key] = false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function du_first_col(mysqli $conn, string $table, array $cols, string $fallback=''): string {
    foreach ($cols as $c) if (du_col_exists($conn, $table, $c)) return $c;
    return $fallback;
}

function du_scalar(mysqli $conn, string $sql, string $types='', array $params=[]) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? null;
}

function du_rows(mysqli $conn, string $sql, string $types='', array $params=[]): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function du_invoice_expr(mysqli $conn): string {
    $c = du_first_col($conn, 'sales', ['invoice_no','order_no','reference_no'], '');
    return $c ? "COALESCE(NULLIF(s.`$c`,''), CONCAT('ORD-',s.id))" : "CONCAT('ORD-',s.id)";
}

function du_total_expr(mysqli $conn): string {
    foreach (['grand_total','total_amount','total','net_total'] as $c) {
        if (du_col_exists($conn, 'sales', $c)) return "COALESCE(s.`$c`,0)";
    }
    return '0';
}

function du_payment_expr(mysqli $conn): string {
    if (du_col_exists($conn, 'sales', 'payment_status')) return "COALESCE(NULLIF(s.payment_status,''),'Unpaid')";
    if (du_col_exists($conn, 'sales', 'payment_method')) return "COALESCE(NULLIF(s.payment_method,''),'Cash')";
    return "'Cash'";
}

function du_customer_name_expr(mysqli $conn): string {
    $saleName = du_col_exists($conn, 'sales', 'customer_name') ? "NULLIF(s.customer_name,'')" : "NULL";
    $custCol = du_table_exists($conn, 'customers') ? du_first_col($conn, 'customers', ['name','customer_name','full_name'], '') : '';
    $custExpr = $custCol ? "NULLIF(c.`$custCol`,'')" : "NULL";
    return "COALESCE($custExpr, $saleName, 'Walk-in Customer')";
}

function du_customer_phone_expr(mysqli $conn): string {
    $phoneCol = du_table_exists($conn, 'customers') ? du_first_col($conn, 'customers', ['phone','mobile','contact_no','phone_number'], '') : '';
    return $phoneCol ? "COALESCE(c.`$phoneCol`,'')" : "''";
}

function du_customer_address_expr(mysqli $conn): string {
    $addrCol = du_table_exists($conn, 'customers') ? du_first_col($conn, 'customers', ['address','customer_address','location'], '') : '';
    return $addrCol ? "COALESCE(c.`$addrCol`,'')" : "''";
}

function du_sale_date_col(mysqli $conn): string {
    return du_first_col($conn, 'sales', ['created_at','sale_date','order_date','date'], 'id');
}

function du_order_qty(mysqli $conn, int $sale_id): float {
    if (!du_table_exists($conn, 'sale_items')) return 0;
    $qtyCol = du_first_col($conn, 'sale_items', ['qty','quantity','quantity_ordered'], 'qty');
    return (float)du_scalar($conn, "SELECT COALESCE(SUM(`$qtyCol`),0) FROM sale_items WHERE sale_id=?", 'i', [$sale_id]);
}

function du_refresh_dispatch(mysqli $conn, int $client_id, int $dispatch_id): void {
    if ($dispatch_id <= 0 || !du_table_exists($conn, 'distribution_dispatches') || !du_table_exists($conn, 'distribution_dispatch_items')) return;
    $row = du_rows($conn, "
        SELECT COUNT(*) total_orders,
               COALESCE(SUM(order_amount),0) total_amount,
               COALESCE(SUM(collection_amount),0) total_collection,
               SUM(item_status IN ('ready','dispatched','out_for_delivery','payment_pending')) pending_count,
               SUM(item_status IN ('delivered','partial_delivered','failed','returned','cancelled','customer_not_available','address_issue')) closed_count
        FROM distribution_dispatch_items
        WHERE client_id=? AND dispatch_id=?
    ", 'ii', [$client_id, $dispatch_id]);
    $r = $row[0] ?? [];
    $total = (int)($r['total_orders'] ?? 0);
    $pending = (int)($r['pending_count'] ?? 0);
    $closed = (int)($r['closed_count'] ?? 0);
    $status = 'dispatched';
    if ($total > 0 && $pending === 0 && $closed > 0) $status = 'completed';
    elseif ($closed > 0) $status = 'in_progress';
    $stmt = $conn->prepare("UPDATE distribution_dispatches SET total_orders=?, total_amount=?, total_collection=?, status=? WHERE id=? AND client_id=?");
    if ($stmt) {
        $totalAmount = (float)($r['total_amount'] ?? 0);
        $collection = (float)($r['total_collection'] ?? 0);
        $stmt->bind_param('iddsii', $total, $totalAmount, $collection, $status, $dispatch_id, $client_id);
        $stmt->execute();
        $stmt->close();
    }
}

function du_status_label(string $status): string {
    $map = [
        'out_for_delivery' => 'Out For Delivery',
        'delivered' => 'Delivered',
        'partial_delivered' => 'Partial Delivered',
        'customer_not_available' => 'Customer Not Available',
        'address_issue' => 'Address Issue',
        'payment_pending' => 'Payment Pending',
        'failed' => 'Failed',
        'returned' => 'Returned',
        'cancelled' => 'Cancelled',
    ];
    return $map[$status] ?? ucwords(str_replace('_',' ', $status));
}

if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'stats') {
        $today = date('Y-m-d');
        $out = (int)du_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='out_for_delivery'", 'i', [$client_id]);
        $delivered = (int)du_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='delivered' AND DATE(COALESCE(delivered_at, updated_at, assigned_at))=?", 'is', [$client_id, $today]);
        $issues = (int)du_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status IN ('partial_delivered','customer_not_available','address_issue','payment_pending','failed','returned','cancelled')", 'i', [$client_id]);
        $collection = (float)du_scalar($conn, "SELECT COALESCE(SUM(collection_amount),0) FROM delivery_order_assignments WHERE client_id=? AND status IN ('delivered','partial_delivered') AND DATE(COALESCE(delivered_at, updated_at, assigned_at))=?", 'is', [$client_id, $today]);
        du_json(['success'=>true, 'stats'=>compact('out','delivered','issues','collection')]);
    }

    if ($action === 'drivers') {
        $drivers = du_table_exists($conn, 'delivery_drivers')
            ? du_rows($conn, "SELECT id, driver_code, driver_name, vehicle, status FROM delivery_drivers WHERE client_id=? AND status!='Inactive' ORDER BY driver_name ASC", 'i', [$client_id])
            : [];
        du_json(['success'=>true, 'drivers'=>$drivers]);
    }

    if ($action === 'routes') {
        $routes = du_table_exists($conn, 'distribution_routes')
            ? du_rows($conn, "SELECT id, route_code, route_name, area, city, driver_id, driver_name, vehicle_name FROM distribution_routes WHERE client_id=? AND status='Active' ORDER BY route_name ASC", 'i', [$client_id])
            : [];
        du_json(['success'=>true, 'routes'=>$routes]);
    }

    if ($action === 'orders') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(5, min(100, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $driverId = (int)($_GET['driver_id'] ?? 0);
        $routeId = (int)($_GET['route_id'] ?? 0);
        $status = trim($_GET['status'] ?? '');
        $date = trim($_GET['date'] ?? '');

        $allowedStatuses = ['out_for_delivery','delivered','partial_delivered','customer_not_available','address_issue','payment_pending','failed','returned','cancelled'];
        $where = ["a.client_id=?"];
        $types = 'i';
        $params = [$client_id];

        if ($branch_id > 0) {
            $where[] = "(a.branch_id=? OR a.branch_id IS NULL OR a.branch_id=0)";
            $types .= 'i';
            $params[] = $branch_id;
        }

        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $where[] = "a.status=?";
            $types .= 's';
            $params[] = $status;
        } else {
            $where[] = "a.status IN ('out_for_delivery','delivered','partial_delivered','customer_not_available','address_issue','payment_pending','failed','returned','cancelled')";
        }

        if ($driverId > 0) { $where[] = "a.driver_id=?"; $types .= 'i'; $params[] = $driverId; }
        if ($routeId > 0) { $where[] = "a.route_id=?"; $types .= 'i'; $params[] = $routeId; }
        if ($date !== '') { $where[] = "a.delivery_date=?"; $types .= 's'; $params[] = $date; }

        $inv = du_invoice_expr($conn);
        $cust = du_customer_name_expr($conn);
        $phone = du_customer_phone_expr($conn);
        $addr = du_customer_address_expr($conn);
        $total = du_total_expr($conn);
        $pay = du_payment_expr($conn);
        $saleDate = du_sale_date_col($conn);
        $dateExpr = $saleDate !== 'id' ? "s.`$saleDate`" : "NULL";

        if ($search !== '') {
            $where[] = "($inv LIKE ? OR $cust LIKE ? OR $phone LIKE ? OR CAST(s.id AS CHAR) LIKE ? OR dd.dispatch_no LIKE ?)";
            $like = '%' . $search . '%';
            $types .= 'sssss';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*)
            FROM delivery_order_assignments a
            JOIN sales s ON s.id=a.sale_id AND s.client_id=a.client_id
            LEFT JOIN customers c ON c.id=s.customer_id
            LEFT JOIN distribution_dispatch_items ddi ON ddi.assignment_id=a.id AND ddi.client_id=a.client_id
            LEFT JOIN distribution_dispatches dd ON dd.id=ddi.dispatch_id AND dd.client_id=a.client_id
            WHERE $whereSql";
        $totalRows = (int)du_scalar($conn, $countSql, $types, $params);

        $sql = "SELECT a.id assignment_id, a.sale_id, a.route_id, a.driver_id, a.vehicle, a.delivery_date, a.delivery_time_slot,
                       a.status assignment_status, a.collection_amount, a.payment_method assignment_payment_method, a.return_qty, a.failed_reason, a.delivered_at,
                       $inv invoice_no, $dateExpr sale_date, s.customer_id, $cust customer_name, $phone phone, $addr address, $total order_amount, $pay payment_status,
                       d.driver_name, dr.route_name, dr.route_code, ddi.id dispatch_item_id, ddi.dispatch_id, ddi.item_status, dd.dispatch_no
                FROM delivery_order_assignments a
                JOIN sales s ON s.id=a.sale_id AND s.client_id=a.client_id
                LEFT JOIN customers c ON c.id=s.customer_id
                LEFT JOIN delivery_drivers d ON d.id=a.driver_id AND d.client_id=a.client_id
                LEFT JOIN distribution_routes dr ON dr.id=a.route_id AND dr.client_id=a.client_id
                LEFT JOIN distribution_dispatch_items ddi ON ddi.assignment_id=a.id AND ddi.client_id=a.client_id
                LEFT JOIN distribution_dispatches dd ON dd.id=ddi.dispatch_id AND dd.client_id=a.client_id
                WHERE $whereSql
                ORDER BY FIELD(a.status,'out_for_delivery','payment_pending','customer_not_available','address_issue','partial_delivered','failed','returned','cancelled','delivered'), a.id DESC
                LIMIT ? OFFSET ?";
        $types2 = $types . 'ii';
        $params2 = $params; $params2[] = $limit; $params2[] = $offset;
        $orders = du_rows($conn, $sql, $types2, $params2);
        foreach ($orders as &$o) $o['qty'] = du_order_qty($conn, (int)$o['sale_id']);
        unset($o);
        du_json(['success'=>true, 'orders'=>$orders, 'total'=>$totalRows, 'page'=>$page, 'pages'=>max(1, (int)ceil($totalRows/$limit))]);
    }

    if ($action === 'update_delivery') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $saleId = (int)($_POST['sale_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');
        $received = (float)($_POST['received_amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $returnQty = (float)($_POST['return_qty'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $allowed = ['delivered','partial_delivered','customer_not_available','address_issue','payment_pending','failed','returned','cancelled'];

        if ($assignmentId <= 0 || $saleId <= 0 || !in_array($newStatus, $allowed, true)) {
            du_json(['success'=>false, 'message'=>'Invalid delivery update data.']);
        }
        if (in_array($newStatus, ['customer_not_available','address_issue','failed','returned','cancelled','partial_delivered','payment_pending'], true) && $reason === '') {
            du_json(['success'=>false, 'message'=>'Reason required for selected status.']);
        }

        $row = du_rows($conn, "SELECT * FROM delivery_order_assignments WHERE id=? AND sale_id=? AND client_id=? LIMIT 1", 'iii', [$assignmentId, $saleId, $client_id]);
        if (!$row) du_json(['success'=>false, 'message'=>'Assignment not found.']);
        $a = $row[0];
        $previous = (string)($a['status'] ?? '');
        $dispatchItem = du_rows($conn, "SELECT * FROM distribution_dispatch_items WHERE client_id=? AND assignment_id=? LIMIT 1", 'ii', [$client_id, $assignmentId]);
        $di = $dispatchItem[0] ?? null;
        $dispatchId = $di ? (int)$di['dispatch_id'] : 0;
        $dispatchItemId = $di ? (int)$di['id'] : 0;

        $conn->begin_transaction();
        try {
            $deliveredAtSql = in_array($newStatus, ['delivered','partial_delivered'], true) ? ', delivered_at=CURRENT_TIMESTAMP' : '';
            $stmt = $conn->prepare("UPDATE delivery_order_assignments
                SET status=?, collection_amount=?, payment_method=?, return_qty=?, failed_reason=?, notes=? $deliveredAtSql
                WHERE id=? AND sale_id=? AND client_id=?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('sdsdssiii', $newStatus, $received, $paymentMethod, $returnQty, $reason, $remarks, $assignmentId, $saleId, $client_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();

            if ($dispatchItemId > 0) {
                $itemStatus = $newStatus;
                $deliveredItemSql = in_array($newStatus, ['delivered','partial_delivered'], true) ? ', delivered_at=CURRENT_TIMESTAMP' : '';
                $stmt = $conn->prepare("UPDATE distribution_dispatch_items
                    SET item_status=?, collection_amount=?, return_qty=?, remarks=? $deliveredItemSql
                    WHERE id=? AND client_id=?");
                if (!$stmt) throw new Exception($conn->error);
                $itemRemarks = trim($reason . ($remarks !== '' ? ' | ' . $remarks : ''));
                $stmt->bind_param('sddsii', $itemStatus, $received, $returnQty, $itemRemarks, $dispatchItemId, $client_id);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $stmt->close();
            }

            $stmt = $conn->prepare("INSERT INTO distribution_delivery_updates
                (client_id, branch_id, sale_id, assignment_id, dispatch_id, dispatch_item_id, route_id, driver_id, previous_status, new_status, received_amount, payment_method, return_qty, reason, remarks, updated_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) throw new Exception($conn->error);
            $routeId = isset($a['route_id']) ? (int)$a['route_id'] : null;
            $driverId = isset($a['driver_id']) ? (int)$a['driver_id'] : null;
            $stmt->bind_param('iiiiiiiissdsdssi', $client_id, $branch_id, $saleId, $assignmentId, $dispatchId, $dispatchItemId, $routeId, $driverId, $previous, $newStatus, $received, $paymentMethod, $returnQty, $reason, $remarks, $user_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();

            if ($dispatchId > 0) du_refresh_dispatch($conn, $client_id, $dispatchId);

            if (in_array($newStatus, ['delivered','partial_delivered','failed','returned','cancelled'], true) && $driverId) {
                $still = (int)du_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND driver_id=? AND status IN ('assigned','ready_for_dispatch','out_for_delivery','payment_pending')", 'ii', [$client_id, $driverId]);
                if ($still === 0) {
                    $stmt = $conn->prepare("UPDATE delivery_drivers SET status='Available' WHERE id=? AND client_id=?");
                    if ($stmt) { $stmt->bind_param('ii', $driverId, $client_id); $stmt->execute(); $stmt->close(); }
                }
            }

            $conn->commit();
            du_json(['success'=>true, 'message'=>'Delivery updated successfully.']);
        } catch (Throwable $e) {
            $conn->rollback();
            du_json(['success'=>false, 'message'=>$e->getMessage()]);
        }
    }

    if ($action === 'history') {
        $saleId = (int)($_GET['sale_id'] ?? 0);
        $rows = du_rows($conn, "SELECT u.*, d.driver_name
            FROM distribution_delivery_updates u
            LEFT JOIN delivery_drivers d ON d.id=u.driver_id AND d.client_id=u.client_id
            WHERE u.client_id=? AND u.sale_id=?
            ORDER BY u.id DESC LIMIT 20", 'ii', [$client_id, $saleId]);
        du_json(['success'=>true, 'history'=>$rows]);
    }

    du_json(['success'=>false, 'message'=>'Invalid action.']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include('partials/head.php'); ?>
    <title>Distribution Delivery Update</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
    :root {
        --sidebar-width: 292px;
        --topbar-height: 78px;
        --gold: #f6b900;
        --page-bg: #f4f6ff;
        --navy: #17243d;
        --head: #263650;
        --border: #dfe5ef;
        --text: #13233d;
        --muted: #687282;
        --green: #16a34a;
        --red: #dc2626;
        --orange: #f59e0b;
        --blue: #2563eb;
    }

    * {
        box-sizing: border-box
    }

    body {
        margin: 0;
        background: var(--page-bg);
        font-family: "Inter", "Segoe UI", system-ui, sans-serif;
        color: var(--text);
        overflow-x: hidden
    }

    .container-scroll {
        display: flex;
        min-height: 100vh
    }

    .sidebar {
        width: var(--sidebar-width);
        flex: 0 0 var(--sidebar-width);
        min-height: 100vh
    }

    .main-panel {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column
    }

    .topbar {
        height: var(--topbar-height);
        background: #050505;
        position: sticky;
        top: 0;
        z-index: 999;
        display: flex;
        align-items: center
    }

    .content-wrapper {
        padding: 24px 24px 60px;
        min-height: calc(100vh - var(--topbar-height))
    }

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 18px
    }

    .page-title {
        font-size: 33px;
        font-weight: 900;
        letter-spacing: -.6px;
        margin: 0
    }

    .page-sub {
        color: var(--muted);
        font-weight: 700
    }

    .btn-main {
        height: 48px;
        border: 0;
        border-radius: 10px;
        background: var(--gold);
        font-weight: 900;
        color: #111;
        padding: 0 20px
    }

    .btn-dark-main {
        height: 48px;
        border: 0;
        border-radius: 10px;
        background: var(--navy);
        font-weight: 900;
        color: #fff;
        padding: 0 20px
    }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 16px
    }

    .kpi-card {
        min-height: 76px;
        border-radius: 10px;
        padding: 0 18px;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 8px 18px rgba(0, 0, 0, .08)
    }

    .kpi-card:nth-child(1) {
        background: linear-gradient(135deg, #4095ff, #2f80ed)
    }

    .kpi-card:nth-child(2) {
        background: linear-gradient(135deg, #49be88, #2ea769)
    }

    .kpi-card:nth-child(3) {
        background: linear-gradient(135deg, #ef4444, #b91c1c)
    }

    .kpi-card:nth-child(4) {
        background: linear-gradient(135deg, #f7c943, #f4b400)
    }

    .kpi-icon {
        font-size: 21px
    }

    .kpi-label {
        font-size: 14px;
        font-weight: 800;
        line-height: 1.1
    }

    .kpi-value {
        font-size: 30px;
        font-weight: 900;
        margin-left: auto
    }

    .filter-bar {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 12px;
        box-shadow: 0 10px 24px rgba(18, 35, 64, .06);
        margin-bottom: 18px;
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap
    }

    .filter-bar:before {
        content: "⚡ Delivery Filters";
        height: 48px;
        min-width: 170px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #111827;
        color: #fff;
        font-size: 15px;
        font-weight: 900;
        padding: 0 16px
    }

    .filter-input,
    .filter-select {
        height: 48px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #fff;
        font-size: 15px;
        color: #111827;
        padding: 0 14px;
        min-width: 160px
    }

    .filter-input {
        min-width: 280px;
        flex: 1
    }

    .white-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: 0 12px 26px rgba(18, 35, 64, .07);
        overflow: hidden
    }

    .card-head {
        padding: 16px 18px;
        border-bottom: 1px solid #e7ecf4;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px
    }

    .card-title {
        font-size: 17px;
        font-weight: 900;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 8px
    }

    .table-wrap {
        overflow-x: auto
    }

    table.custom-table {
        width: 100%;
        min-width: 1220px;
        border-collapse: separate;
        border-spacing: 0
    }

    .custom-table th {
        background: var(--head);
        color: #fff;
        font-size: 14px;
        text-transform: none;
        padding: 16px 14px;
        border: 0;
        font-weight: 900;
        white-space: nowrap
    }

    .custom-table td {
        padding: 15px 14px;
        border-bottom: 1px solid #e7ecf4;
        color: #19253a;
        background: #fff;
        font-size: 14px;
        vertical-align: middle
    }

    .custom-table tr:hover td {
        background: #f8fbff
    }

    .order-no {
        font-weight: 900;
        color: var(--navy)
    }

    .customer-name {
        font-weight: 900
    }

    .small-muted {
        font-size: 12px;
        color: var(--muted);
        font-weight: 700
    }

    .badge-custom {
        border-radius: 999px;
        padding: 6px 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 5px
    }

    .badge-out {
        background: #ecfeff;
        color: #0891b2
    }

    .badge-delivered {
        background: #dcfce7;
        color: #16a34a
    }

    .badge-partial {
        background: #fef3c7;
        color: #d97706
    }

    .badge-issue {
        background: #fff7ed;
        color: #ea580c
    }

    .badge-failed {
        background: #fee2e2;
        color: #dc2626
    }

    .badge-paid {
        background: #dcfce7;
        color: #16a34a
    }

    .badge-unpaid {
        background: #fee2e2;
        color: #dc2626
    }

    .action-btn {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: transparent;
        border: 0;
        color: #536174
    }

    .action-btn:hover {
        background: #eef4ff;
        color: var(--navy)
    }

    .pagination-custom {
        padding: 16px 18px;
        background: #fff;
        border-top: 1px solid #e7ecf4;
        display: flex;
        align-items: center;
        gap: 5px
    }

    .page-btn {
        border-radius: 8px;
        width: 34px;
        height: 34px;
        border: 1px solid var(--border);
        background: #fff;
        font-weight: 900
    }

    .page-btn.active {
        background: var(--navy);
        border-color: var(--navy);
        color: #fff
    }

    .pagination-info {
        font-size: 12px;
        color: var(--muted);
        font-weight: 800;
        margin-left: auto
    }

    .modal-custom {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .6);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(2px)
    }

    .modal-custom.show {
        display: flex
    }

    .modal-box {
        background: #fff;
        border-radius: 14px;
        width: 92%;
        max-width: 720px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .22);
        overflow: hidden;
        max-height: 92vh;
        overflow-y: auto
    }

    .modal-head {
        background: var(--head);
        color: #fff;
        padding: 18px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between
    }

    .modal-title {
        font-size: 16px;
        font-weight: 900
    }

    .modal-close {
        width: 30px;
        height: 30px;
        border: 0;
        border-radius: 8px;
        background: rgba(255, 255, 255, .12);
        color: #fff
    }

    .modal-body {
        padding: 22px
    }

    .modal-foot {
        padding: 16px 22px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 8px
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px
    }

    .form-grid label {
        font-size: 13px;
        font-weight: 900;
        color: #334155;
        margin-bottom: 6px;
        display: block
    }

    .form-control-custom {
        width: 100%;
        height: 48px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #fff;
        font-size: 15px;
        color: #111827;
        padding: 0 14px
    }

    .form-control-custom:focus {
        outline: none;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .08)
    }

    textarea.form-control-custom {
        height: auto;
        min-height: 90px;
        padding-top: 12px
    }

    .detail-row {
        display: flex;
        padding: 9px 0;
        border-bottom: 1px solid #f1f3f7;
        font-size: 14px
    }

    .detail-label {
        width: 160px;
        color: var(--muted);
        font-weight: 800
    }

    .detail-value {
        font-weight: 900;
        color: var(--text)
    }

    #toast-container {
        position: fixed;
        right: 28px;
        bottom: 28px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 10px
    }

    .toast-item {
        background: #1e2533;
        color: #fff;
        border-radius: 10px;
        padding: 12px 18px;
        min-width: 280px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .2);
        font-size: 13.5px;
        font-weight: 800
    }

    .toast-item.success {
        border-left: 4px solid #22c55e
    }

    .toast-item.error {
        border-left: 4px solid #ef4444
    }

    .toast-item.info {
        border-left: 4px solid #2563eb
    }

    @media(max-width:1200px) {
        .kpi-grid {
            grid-template-columns: repeat(2, 1fr)
        }
    }

    @media(max-width:768px) {
        .sidebar {
            display: none
        }

        .content-wrapper {
            padding: 16px
        }

        .page-title {
            font-size: 26px
        }

        .kpi-grid {
            grid-template-columns: 1fr
        }

        .filter-bar:before,
        .filter-input,
        .filter-select,
        .btn-dark-main {
            width: 100%;
            min-width: 100%
        }

        .form-grid {
            grid-template-columns: 1fr
        }
    }
    
    
    /* =====================================================
   DELIVERY_UPDATE.PHP DASHBOARD DARK PURPLE GLASS THEME
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
    --oms-border:rgba(148,163,184,.14);
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
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* Layout + Sidebar */
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
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    background:transparent !important;
    overflow:visible !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease;
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
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

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

/* Topbar */
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

/* Content background */
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
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
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

/* Header */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-sub,
.small-muted,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

/* Buttons */
.btn-main{
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

.btn-dark-main{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.btn-light{
    min-height:38px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    padding:7px 14px !important;
}

.btn-main:hover,
.btn-dark-main:hover,
.btn-light:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* KPI cards */
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
    gap:12px !important;
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
.kpi-card:nth-child(3){background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.kpi-card:nth-child(4){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

.kpi-icon{
    font-size:30px !important;
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

/* Filter + cards glass */
.filter-bar,
.white-card{
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

.filter-bar:hover,
.white-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-bar{
    padding:14px !important;
    gap:10px !important;
    margin-bottom:18px !important;
}

.filter-bar::before{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.card-head{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
}

.card-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* Inputs */
.filter-input,
.filter-select,
.form-control-custom{
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-input,
.filter-select,
.form-control-custom{
    height:46px !important;
}

textarea.form-control-custom{
    height:auto !important;
    min-height:90px !important;
    padding-top:12px !important;
}

.filter-input::placeholder,
.form-control-custom::placeholder{
    color:#94a3b8 !important;
}

.filter-input:focus,
.filter-select:focus,
.form-control-custom:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-select option,
.form-control-custom option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.form-grid label{
    color:#f8fafc !important;
}

/* Table */
.table-wrap,
table.custom-table,
.custom-table tbody,
.custom-table tbody tr{
    background:transparent !important;
}

table.custom-table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

.custom-table th{
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

.custom-table td{
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

/* white hover remove */
.custom-table tr:hover,
.custom-table tr:hover td,
.custom-table td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

.custom-table tr:focus,
.custom-table tr:active,
.custom-table td:focus,
.custom-table td:active,
.custom-table td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

.custom-table td.text-center,
.custom-table td.py-4,
.custom-table td[colspan]{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

.order-no,
.customer-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* Badges */
.badge-custom{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.badge-out{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border:1px solid rgba(59,130,246,.24) !important;
}

.badge-delivered,
.badge-paid{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.badge-partial,
.badge-issue{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.badge-failed,
.badge-unpaid{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* Actions + pagination */
.action-btn{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(109,76,255,.18) !important;
    color:#c4b5fd !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.action-btn:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.28) !important;
    color:#fff !important;
}

.pagination-custom{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#94a3b8 !important;
}

.page-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:10px !important;
}

.page-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.42) !important;
}

.pagination-info{
    color:#94a3b8 !important;
}

.toast-item{
    background:#0f172a !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}

.toast-item.success{border-left:4px solid #22c55e !important;}
.toast-item.error{border-left:4px solid #ef4444 !important;}
.toast-item.info{border-left:4px solid #7c3aed !important;}

/* =====================================================
   DELIVERY UPDATE POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

#updateModal.modal-custom,
#historyModal.modal-custom{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
}

#updateModal .modal-box,
#historyModal .modal-box{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    max-height:92vh !important;
    max-width:680px !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#updateModal .modal-head,
#historyModal .modal-head{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    color:#fff !important;
}

#updateModal .modal-title,
#historyModal .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#updateModal .modal-title::before,
#historyModal .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#updateModal .modal-close,
#historyModal .modal-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    color:transparent !important;
    opacity:1 !important;
    box-shadow:none !important;
    padding:0 !important;
    font-size:0 !important;
    line-height:1 !important;
}

#updateModal .modal-close::before,
#updateModal .modal-close::after,
#historyModal .modal-close::before,
#historyModal .modal-close::after{
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

#updateModal .modal-close::before,
#historyModal .modal-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#updateModal .modal-close::after,
#historyModal .modal-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#updateModal .modal-close:hover,
#historyModal .modal-close:hover{
    background:#ef4444 !important;
}

#updateModal .modal-body,
#historyModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#updateModal .modal-foot,
#historyModal .modal-foot{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#updateModal .alert-info{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:14px !important;
    font-weight:800 !important;
}

#updateModal .alert-info b{
    color:#a78bfa !important;
}

#updateModal .form-grid{
    gap:12px !important;
}

#updateModal .form-grid label,
#historyModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#updateModal .form-control-custom,
#updateModal input,
#updateModal select,
#updateModal textarea{
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

#updateModal textarea.form-control-custom{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#updateModal input::placeholder,
#updateModal textarea::placeholder{
    color:#94a3b8 !important;
}

#updateModal .form-control-custom:focus,
#updateModal input:focus,
#updateModal select:focus,
#updateModal textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14),0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#updateModal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* History rows */
#historyModal .detail-row{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    margin-bottom:10px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#historyModal .detail-label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

#historyModal .detail-value{
    color:#f8fafc !important;
    font-weight:900 !important;
}

#historyModal small{
    color:#94a3b8 !important;
}
@media(max-width:1100px){
    .kpi-grid{
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

    #dashboardSidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    #dashboardSidebar .sidebar-inner{
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
    .kpi-grid{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .filter-bar{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .filter-bar::before,
    .filter-input,
    .filter-select,
    .btn-dark-main,
    .btn-main{
        width:100% !important;
        min-width:100% !important;
    }

    .form-grid{
        grid-template-columns:1fr !important;
    }

    #updateModal .modal-box,
    #historyModal .modal-box{
        width:96vw !important;
        max-height:94vh !important;
    }

    #updateModal .modal-foot,
    #historyModal .modal-foot{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #updateModal .btn-light,
    #updateModal .btn-dark-main,
    #historyModal .btn-light{
        width:100% !important;
    }
}
    
    </style>
</head>

<body>
    <div class="container-scroll">
        <div class="sidebar"><?php include('partials/sidebar.php'); ?></div>
        <div class="main-panel">
            <div class="topbar"><?php include('partials/topbar.php'); ?></div>
            <main class="content-wrapper">
                <div class="page-header">
                    <div>
                        <h1 class="page-title"><i class="bi bi-clipboard2-check me-2"></i>Distribution Delivery Update
                        </h1>
                        <div class="page-sub">Out for delivery orders → Delivered / Issue / Return / Collection</div>
                    </div>
                    <button class="btn-main" onclick="loadOrders(1)"><i
                            class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                </div>

                <div class="kpi-grid">
                    <div class="kpi-card"><i class="bi bi-truck kpi-icon"></i>
                        <div class="kpi-label">Out For<br>Delivery</div>
                        <div class="kpi-value" id="kOut">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-check2-circle kpi-icon"></i>
                        <div class="kpi-label">Delivered<br>Today</div>
                        <div class="kpi-value" id="kDelivered">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-exclamation-triangle kpi-icon"></i>
                        <div class="kpi-label">Issues /<br>Returns</div>
                        <div class="kpi-value" id="kIssues">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-cash-coin kpi-icon"></i>
                        <div class="kpi-label">Collection<br>Today</div>
                        <div class="kpi-value" id="kCollection">0</div>
                    </div>
                </div>

                <div class="filter-bar">
                    <input id="fSearch" class="filter-input"
                        placeholder="Search invoice, customer, phone, dispatch no, order ID">
                    <select id="fStatus" class="filter-select">
                        <option value="">All Delivery Status</option>
                        <option value="out_for_delivery">Out For Delivery</option>
                        <option value="delivered">Delivered</option>
                        <option value="partial_delivered">Partial Delivered</option>
                        <option value="customer_not_available">Customer Not Available</option>
                        <option value="address_issue">Address Issue</option>
                        <option value="payment_pending">Payment Pending</option>
                        <option value="failed">Failed</option>
                        <option value="returned">Returned</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <select id="fRoute" class="filter-select">
                        <option value="">All Routes</option>
                    </select>
                    <select id="fDriver" class="filter-select">
                        <option value="">All Riders</option>
                    </select>
                    <input type="date" id="fDate" class="filter-select">
                    <button class="btn-dark-main" onclick="loadOrders(1)"><i
                            class="bi bi-funnel me-1"></i>Filter</button>
                    <button class="btn-dark-main" style="background:#687282" onclick="resetFilters()">Reset</button>
                </div>

                <section class="white-card">
                    <div class="card-head">
                        <div class="card-title"><i class="bi bi-list-check"></i> Delivery Orders</div>
                        <div class="small-muted" id="orderCount">0 orders</div>
                    </div>
                    <div class="table-wrap">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Dispatch</th>
                                    <th>Route</th>
                                    <th>Rider</th>
                                    <th>Amount</th>
                                    <th>Collection</th>
                                    <th>Return Qty</th>
                                    <th>Delivery Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="ordersBody"></tbody>
                        </table>
                    </div>
                    <div class="pagination-custom">
                        <div id="paginationBtns"></div>
                        <div class="pagination-info" id="paginationInfo"></div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <div class="modal-custom" id="updateModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title"><i class="bi bi-pencil-square me-1"></i>Update Delivery</div><button
                    class="modal-close" onclick="closeModal('updateModal')">×</button>
            </div>
            <div class="modal-body">
                <div id="selectedOrderInfo" class="alert alert-info small fw-bold"></div>
                <input type="hidden" id="uAssignmentId"><input type="hidden" id="uSaleId">
                <div class="form-grid">
                    <div><label>Delivery Status *</label><select id="uStatus" class="form-control-custom">
                            <option value="delivered">Delivered</option>
                            <option value="partial_delivered">Partial Delivered</option>
                            <option value="customer_not_available">Customer Not Available</option>
                            <option value="address_issue">Address Issue</option>
                            <option value="payment_pending">Payment Pending</option>
                            <option value="failed">Failed</option>
                            <option value="returned">Returned</option>
                            <option value="cancelled">Cancelled</option>
                        </select></div>
                    <div><label>Received Amount</label><input type="number" step="0.01" id="uReceived"
                            class="form-control-custom" value="0"></div>
                    <div><label>Payment Method</label><select id="uPaymentMethod" class="form-control-custom">
                            <option>Cash</option>
                            <option>Bank Transfer</option>
                            <option>Card</option>
                            <option>JazzCash</option>
                            <option>EasyPaisa</option>
                            <option>COD</option>
                            <option>Other</option>
                        </select></div>
                    <div><label>Return Qty</label><input type="number" step="0.01" id="uReturnQty"
                            class="form-control-custom" value="0"></div>
                    <div style="grid-column:1/-1"><label>Reason</label><input id="uReason" class="form-control-custom"
                            placeholder="Required for partial/failed/returned/address issue/customer not available">
                    </div>
                    <div style="grid-column:1/-1"><label>Remarks</label><textarea id="uRemarks"
                            class="form-control-custom" placeholder="Optional remarks"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button class="btn btn-light"
                    onclick="closeModal('updateModal')">Cancel</button><button class="btn-dark-main"
                    onclick="saveUpdate()">Save Update</button></div>
        </div>
    </div>

    <div class="modal-custom" id="historyModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title"><i class="bi bi-clock-history me-1"></i>Delivery History</div><button
                    class="modal-close" onclick="closeModal('historyModal')">×</button>
            </div>
            <div class="modal-body" id="historyBody"></div>
            <div class="modal-foot"><button class="btn btn-light" onclick="closeModal('historyModal')">Close</button>
            </div>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
    const pageUrl = '<?= basename(__FILE__) ?>';
    let currentPage = 1;
    let visibleOrders = [];

    function esc(v) {
        return String(v ?? '').replace(/[&<>'"]/g, m => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        } [m]));
    }

    function money(v) {
        return 'PKR ' + Number(v || 0).toLocaleString();
    }

    function get(action, params = {}) {
        const u = new URL(pageUrl, window.location.href);
        u.searchParams.set('ajax', '1');
        u.searchParams.set('action', action);
        Object.keys(params).forEach(k => u.searchParams.set(k, params[k]));
        return fetch(u).then(r => r.json());
    }

    function post(action, data = {}) {
        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('action', action);
        Object.keys(data).forEach(k => fd.append(k, data[k]));
        return fetch(pageUrl, {
            method: 'POST',
            body: fd
        }).then(r => r.json());
    }

    function toast(msg, type = 'success') {
        const el = document.createElement('div');
        el.className = 'toast-item ' + type;
        el.textContent = msg;
        document.getElementById('toast-container').appendChild(el);
        setTimeout(() => el.remove(), 3300);
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    function openModal(id) {
        document.getElementById(id).classList.add('show');
    }

    function statusBadge(s) {
        s = (s || 'out_for_delivery').toString();
        let cls = 'badge-out',
            label = 'Out For Delivery';
        if (s === 'delivered') {
            cls = 'badge-delivered';
            label = 'Delivered'
        } else if (s === 'partial_delivered') {
            cls = 'badge-partial';
            label = 'Partial Delivered'
        } else if (['customer_not_available', 'address_issue', 'payment_pending'].includes(s)) {
            cls = 'badge-issue';
            label = s.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase())
        } else if (['failed', 'returned', 'cancelled'].includes(s)) {
            cls = 'badge-failed';
            label = s.charAt(0).toUpperCase() + s.slice(1)
        }
        return `<span class="badge-custom ${cls}">${label}</span>`;
    }

    function loadStats() {
        get('stats').then(r => {
            if (!r.success) return;
            kOut.textContent = r.stats.out || 0;
            kDelivered.textContent = r.stats.delivered || 0;
            kIssues.textContent = r.stats.issues || 0;
            kCollection.textContent = money(r.stats.collection || 0).replace('PKR ', '');
        });
    }

    function loadDrivers() {
        get('drivers').then(r => {
            let html = '<option value="">All Riders</option>';
            (r.drivers || []).forEach(d => html +=
                `<option value="${d.id}">${esc(d.driver_name)}${d.vehicle?' - '+esc(d.vehicle):''}</option>`
                );
            fDriver.innerHTML = html;
        });
    }

    function loadRoutes() {
        get('routes').then(r => {
            let html = '<option value="">All Routes</option>';
            (r.routes || []).forEach(rt => html +=
                `<option value="${rt.id}">${esc(rt.route_name)}${rt.area?' - '+esc(rt.area):''}</option>`);
            fRoute.innerHTML = html;
        });
    }

    function loadOrders(p = 1) {
        currentPage = p;
        get('orders', {
            page: p,
            limit: 10,
            search: fSearch.value,
            status: fStatus.value,
            route_id: fRoute.value,
            driver_id: fDriver.value,
            date: fDate.value
        }).then(r => {
            if (!r.success) {
                toast(r.message || 'Orders load failed', 'error');
                return;
            }
            visibleOrders = r.orders || [];
            let html = '';
            visibleOrders.forEach(o => {
                html +=
                    `<tr><td><div class="order-no">#${esc(o.invoice_no||o.sale_id)}</div><div class="small-muted">Qty: ${Number(o.qty||0)} | ${esc(o.sale_date||'')}</div></td><td><div class="customer-name">${esc(o.customer_name)}</div><div class="small-muted">${esc(o.phone||'')}${o.address?' • '+esc(o.address):''}</div></td><td>${esc(o.dispatch_no||'-')}</td><td>${esc(o.route_name||'-')}</td><td>${esc(o.driver_name||'-')}<div class="small-muted">${esc(o.vehicle||'')}</div></td><td><b>${money(o.order_amount)}</b><div class="small-muted">${esc(o.payment_status||'')}</div></td><td><b>${money(o.collection_amount)}</b><div class="small-muted">${esc(o.assignment_payment_method||'')}</div></td><td>${Number(o.return_qty||0)}</td><td>${esc(o.delivery_date||'-')}<div class="small-muted">${esc(o.delivery_time_slot||'')}</div></td><td>${statusBadge(o.assignment_status)}</td><td><button class="action-btn" title="Update" onclick="openUpdate(${o.assignment_id},${o.sale_id})"><i class="bi bi-pencil-square"></i></button><button class="action-btn" title="History" onclick="openHistory(${o.sale_id})"><i class="bi bi-clock-history"></i></button></td></tr>`;
            });
            ordersBody.innerHTML = html ||
                '<tr><td colspan="11" class="text-center py-4 text-muted">No delivery orders found.</td></tr>';
            orderCount.textContent = (r.total || 0) + ' orders';
            paginationInfo.textContent = `Page ${r.page} of ${r.pages}`;
            renderPagination(r.page, r.pages);
            loadStats();
        });
    }

    function renderPagination(page, pages) {
        let html = '';
        html += `<button class="page-btn" ${page<=1?'disabled':''} onclick="loadOrders(${page-1})">‹</button>`;
        for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) html +=
            `<button class="page-btn ${i===page?'active':''}" onclick="loadOrders(${i})">${i}</button>`;
        html += `<button class="page-btn" ${page>=pages?'disabled':''} onclick="loadOrders(${page+1})">›</button>`;
        paginationBtns.innerHTML = html;
    }

    function resetFilters() {
        fSearch.value = '';
        fStatus.value = '';
        fRoute.value = '';
        fDriver.value = '';
        fDate.value = '';
        loadOrders(1);
    }

    function openUpdate(assignmentId, saleId) {
        const o = visibleOrders.find(x => Number(x.assignment_id) === Number(assignmentId));
        uAssignmentId.value = assignmentId;
        uSaleId.value = saleId;
        selectedOrderInfo.innerHTML =
            `Order <b>#${esc(o?.invoice_no||saleId)}</b> - ${esc(o?.customer_name||'')} | Amount: <b>${money(o?.order_amount||0)}</b>`;
        uStatus.value = (o?.assignment_status === 'out_for_delivery' || !o?.assignment_status) ? 'delivered' : o
            .assignment_status;
        uReceived.value = o?.collection_amount || 0;
        uPaymentMethod.value = o?.assignment_payment_method || 'Cash';
        uReturnQty.value = o?.return_qty || 0;
        uReason.value = o?.failed_reason || '';
        uRemarks.value = '';
        openModal('updateModal');
    }

    function saveUpdate() {
        post('update_delivery', {
            assignment_id: uAssignmentId.value,
            sale_id: uSaleId.value,
            new_status: uStatus.value,
            received_amount: uReceived.value,
            payment_method: uPaymentMethod.value,
            return_qty: uReturnQty.value,
            reason: uReason.value,
            remarks: uRemarks.value
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            if (r.success) {
                closeModal('updateModal');
                loadOrders(currentPage);
            }
        });
    }

    function openHistory(saleId) {
        get('history', {
            sale_id: saleId
        }).then(r => {
            let html = '';
            (r.history || []).forEach(h => {
                html +=
                    `<div class="detail-row"><div class="detail-label">${esc(h.updated_at)}</div><div class="detail-value">${esc(h.previous_status||'-')} → ${esc(h.new_status)}<br><small>Received: ${money(h.received_amount)} | Return: ${Number(h.return_qty||0)} | Rider: ${esc(h.driver_name||'-')}</small><br><small>${esc(h.reason||'')} ${h.remarks?' | '+esc(h.remarks):''}</small></div></div>`;
            });
            historyBody.innerHTML = html || '<div class="text-muted text-center py-4">No history found.</div>';
            openModal('historyModal');
        });
    }
    document.addEventListener('DOMContentLoaded', () => {
        loadDrivers();
        loadRoutes();
        loadStats();
        loadOrders(1);
    });
    </script>
</body>

</html>