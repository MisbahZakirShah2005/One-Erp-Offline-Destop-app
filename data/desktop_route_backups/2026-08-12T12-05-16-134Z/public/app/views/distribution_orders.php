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

if ($client_id <= 0) {
    die('Client not found in session.');
}

/* =========================================================
   HELPERS - prefixed to avoid redeclare errors with partials
   ========================================================= */
function dist_orders_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function dist_orders_json($arr) { header('Content-Type: application/json'); echo json_encode($arr); exit; }

function dist_orders_table_exists(mysqli $conn, string $table): bool {
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

function dist_orders_col_exists(mysqli $conn, string $table, string $col): bool {
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

function dist_orders_first_col(mysqli $conn, string $table, array $cols, string $fallback = ''): string {
    foreach ($cols as $c) {
        if (dist_orders_col_exists($conn, $table, $c)) return $c;
    }
    return $fallback;
}

function dist_orders_scalar(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? null;
}

function dist_orders_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function dist_orders_total_expr(mysqli $conn): string {
    foreach (['grand_total','total_amount','total','net_total'] as $c) {
        if (dist_orders_col_exists($conn, 'sales', $c)) return "COALESCE(s.`$c`,0)";
    }
    return '0';
}

function dist_orders_invoice_expr(mysqli $conn): string {
    $c = dist_orders_first_col($conn, 'sales', ['invoice_no','order_no','reference_no','sale_no'], '');
    return $c ? "COALESCE(NULLIF(s.`$c`,''), CONCAT('ORD-',s.id))" : "CONCAT('ORD-',s.id)";
}

function dist_orders_payment_expr(mysqli $conn): string {
    if (dist_orders_col_exists($conn, 'sales', 'payment_status')) return "COALESCE(NULLIF(s.payment_status,''),'Unpaid')";
    if (dist_orders_col_exists($conn, 'sales', 'payment_method')) return "COALESCE(NULLIF(s.payment_method,''),'Cash')";
    return "'Cash'";
}

function dist_orders_customer_name_expr(mysqli $conn): string {
    $saleName = dist_orders_col_exists($conn, 'sales', 'customer_name') ? "NULLIF(s.customer_name,'')" : "NULL";
    $custCol = dist_orders_table_exists($conn, 'customers') ? dist_orders_first_col($conn, 'customers', ['name','customer_name','full_name'], '') : '';
    $custExpr = $custCol ? "NULLIF(c.`$custCol`,'')" : "NULL";
    return "COALESCE($custExpr, $saleName, 'Walk-in Customer')";
}

function dist_orders_customer_phone_expr(mysqli $conn): string {
    $phoneCol = dist_orders_table_exists($conn, 'customers') ? dist_orders_first_col($conn, 'customers', ['phone','mobile','contact_no','phone_number'], '') : '';
    return $phoneCol ? "COALESCE(c.`$phoneCol`,'')" : "''";
}

function dist_orders_customer_address_expr(mysqli $conn): string {
    $addrCol = dist_orders_table_exists($conn, 'customers') ? dist_orders_first_col($conn, 'customers', ['address','customer_address','location'], '') : '';
    return $addrCol ? "COALESCE(c.`$addrCol`,'')" : "''";
}

function dist_orders_sale_date_col(mysqli $conn): string {
    return dist_orders_first_col($conn, 'sales', ['created_at','sale_date','order_date','date'], 'id');
}

function dist_orders_qty(mysqli $conn, int $sale_id): float {
    if (!dist_orders_table_exists($conn, 'sale_items')) return 0;
    $qtyCol = dist_orders_first_col($conn, 'sale_items', ['qty','quantity','quantity_ordered'], 'qty');
    if (!$qtyCol) return 0;
    return (float)dist_orders_scalar($conn, "SELECT COALESCE(SUM(`$qtyCol`),0) FROM sale_items WHERE sale_id=?", 'i', [$sale_id]);
}

function dist_orders_next_dispatch_no(mysqli $conn, int $client_id): string {
    $today = date('Ymd');
    $prefix = 'DIS-' . $today . '-';
    $n = (int)dist_orders_scalar($conn, "SELECT COUNT(*) + 1 FROM distribution_dispatches WHERE client_id=? AND dispatch_no LIKE ?", 'is', [$client_id, $prefix . '%']);
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function dist_orders_auto_route(mysqli $conn, int $client_id, int $customer_id): ?array {
    if ($customer_id <= 0 || !dist_orders_table_exists($conn, 'distribution_route_customers') || !dist_orders_table_exists($conn, 'distribution_routes')) return null;
    $rows = dist_orders_rows($conn, "
        SELECT r.id, r.route_code, r.route_name, r.area, r.driver_id, r.driver_name, r.vehicle_name
        FROM distribution_route_customers rc
        INNER JOIN distribution_routes r ON r.id = rc.route_id AND r.client_id = rc.client_id
        WHERE rc.client_id=? AND rc.customer_id=? AND rc.status='Active' AND r.status='Active'
        ORDER BY rc.sequence_no ASC, rc.id ASC
        LIMIT 1
    ", 'ii', [$client_id, $customer_id]);
    return $rows[0] ?? null;
}

function dist_orders_refresh_dispatch(mysqli $conn, int $client_id, int $dispatch_id): void {
    if (!dist_orders_table_exists($conn, 'distribution_dispatches') || !dist_orders_table_exists($conn, 'distribution_dispatch_items')) return;
    $row = dist_orders_rows($conn, "
        SELECT COUNT(*) total_orders,
               COALESCE(SUM(order_amount),0) total_amount,
               COALESCE(SUM(collection_amount),0) total_collection
        FROM distribution_dispatch_items
        WHERE client_id=? AND dispatch_id=?
    ", 'ii', [$client_id, $dispatch_id]);
    $s = $row[0] ?? ['total_orders'=>0,'total_amount'=>0,'total_collection'=>0];
    $stmt = $conn->prepare("UPDATE distribution_dispatches SET total_orders=?, total_amount=?, total_collection=? WHERE id=? AND client_id=?");
    if ($stmt) {
        $stmt->bind_param('iddii', $s['total_orders'], $s['total_amount'], $s['total_collection'], $dispatch_id, $client_id);
        $stmt->execute();
        $stmt->close();
    }
}

/* =========================================================
   AJAX BACKEND
   ========================================================= */
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'stats') {
        $today = date('Y-m-d');
        $pending = (int)dist_orders_scalar($conn, "SELECT COUNT(*) FROM sales s LEFT JOIN delivery_order_assignments a ON a.sale_id=s.id AND a.client_id=s.client_id WHERE s.client_id=? AND a.id IS NULL", 'i', [$client_id]);
        $assigned = (int)dist_orders_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='assigned'", 'i', [$client_id]);
        $ready = (int)dist_orders_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='ready_for_dispatch'", 'i', [$client_id]);
        $out = (int)dist_orders_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='out_for_delivery'", 'i', [$client_id]);
        $delivered = (int)dist_orders_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='delivered' AND delivery_date=?", 'is', [$client_id, $today]);
        $failed = (int)dist_orders_scalar($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status IN ('failed','returned','cancelled') AND delivery_date=?", 'is', [$client_id, $today]);
        $dispatches = dist_orders_table_exists($conn, 'distribution_dispatches') ? (int)dist_orders_scalar($conn, "SELECT COUNT(*) FROM distribution_dispatches WHERE client_id=? AND dispatch_date=?", 'is', [$client_id, $today]) : 0;
        $collection = (float)dist_orders_scalar($conn, "SELECT COALESCE(SUM(collection_amount),0) FROM delivery_order_assignments WHERE client_id=? AND status='delivered' AND delivery_date=?", 'is', [$client_id, $today]);
        dist_orders_json(['success'=>true,'stats'=>compact('pending','assigned','ready','out','delivered','failed','dispatches','collection')]);
    }

    if ($action === 'routes') {
        $routes = dist_orders_table_exists($conn, 'distribution_routes') ? dist_orders_rows($conn, "
            SELECT id, route_code, route_name, area, city, driver_id, driver_name, vehicle_name
            FROM distribution_routes
            WHERE client_id=? AND status='Active'
            ORDER BY route_name ASC
        ", 'i', [$client_id]) : [];
        dist_orders_json(['success'=>true,'routes'=>$routes]);
    }

    if ($action === 'drivers') {
        $drivers = dist_orders_table_exists($conn, 'delivery_drivers') ? dist_orders_rows($conn, "
            SELECT id, driver_code, driver_name, phone, vehicle, status
            FROM delivery_drivers
            WHERE client_id=? AND status!='Inactive'
            ORDER BY driver_name ASC
        ", 'i', [$client_id]) : [];
        dist_orders_json(['success'=>true,'drivers'=>$drivers]);
    }

    if ($action === 'orders') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(5, min(100, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $tab = trim($_GET['tab'] ?? 'Pending');
        $search = trim($_GET['search'] ?? '');
        $routeId = (int)($_GET['route_id'] ?? 0);
        $driverId = (int)($_GET['driver_id'] ?? 0);
        $date = trim($_GET['date'] ?? '');
        $customerType = trim($_GET['customer_type'] ?? '');

        $where = ['s.client_id=?'];
        $types = 'i';
        $params = [$client_id];

        if ($branch_id > 0 && dist_orders_col_exists($conn, 'sales', 'branch_id')) {
            $where[] = '(s.branch_id=? OR s.branch_id IS NULL OR s.branch_id=0)';
            $types .= 'i';
            $params[] = $branch_id;
        }

        $inv = dist_orders_invoice_expr($conn);
        $cust = dist_orders_customer_name_expr($conn);
        $phone = dist_orders_customer_phone_expr($conn);
        $addr = dist_orders_customer_address_expr($conn);
        $totalExpr = dist_orders_total_expr($conn);
        $payExpr = dist_orders_payment_expr($conn);
        $saleDate = dist_orders_sale_date_col($conn);
        $dateSelect = $saleDate !== 'id' ? "s.`$saleDate`" : "NULL";
        $customerTypeSelect = dist_orders_col_exists($conn, 'sales', 'customer_type') ? "s.customer_type" : "''";

        if ($search !== '') {
            $where[] = "($inv LIKE ? OR $cust LIKE ? OR $phone LIKE ? OR CAST(s.id AS CHAR) LIKE ?)";
            $types .= 'ssss';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($date !== '' && $saleDate !== 'id') {
            $where[] = "DATE(s.`$saleDate`)=?";
            $types .= 's';
            $params[] = $date;
        }
        if ($routeId > 0) { $where[] = 'a.route_id=?'; $types .= 'i'; $params[] = $routeId; }
        if ($driverId > 0) { $where[] = 'a.driver_id=?'; $types .= 'i'; $params[] = $driverId; }
        if ($customerType !== '' && dist_orders_col_exists($conn, 'sales', 'customer_type')) { $where[] = 's.customer_type=?'; $types .= 's'; $params[] = $customerType; }

        switch ($tab) {
            case 'Pending': $where[] = 'a.id IS NULL'; break;
            case 'Assigned': $where[] = "a.status='assigned'"; break;
            case 'Ready For Dispatch': $where[] = "a.status='ready_for_dispatch'"; break;
            case 'Out For Delivery': $where[] = "a.status='out_for_delivery'"; break;
            case 'Delivered': $where[] = "a.status='delivered'"; break;
            case 'Failed': $where[] = "a.status='failed'"; break;
            case 'Returned': $where[] = "a.status='returned'"; break;
            case 'Cancelled': $where[] = "a.status='cancelled'"; break;
            case 'All': default: break;
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN delivery_order_assignments a ON a.sale_id=s.id AND a.client_id=s.client_id WHERE $whereSql";
        $totalRows = (int)dist_orders_scalar($conn, $countSql, $types, $params);

        $sql = "SELECT
                    s.id sale_id,
                    $inv invoice_no,
                    $dateSelect created_at,
                    s.customer_id,
                    $customerTypeSelect customer_type,
                    $cust customer_name,
                    $phone phone,
                    $addr address,
                    $totalExpr amount,
                    $payExpr payment_status,
                    a.id assignment_id,
                    a.status assignment_status,
                    a.delivery_date,
                    a.delivery_time_slot,
                    a.driver_id,
                    a.route_id,
                    a.vehicle,
                    a.collection_amount,
                    a.failed_reason,
                    d.driver_name,
                    d.phone driver_phone,
                    dr.route_name,
                    dr.route_code,
                    di.dispatch_id,
                    dp.dispatch_no
                FROM sales s
                LEFT JOIN customers c ON c.id=s.customer_id
                LEFT JOIN delivery_order_assignments a ON a.sale_id=s.id AND a.client_id=s.client_id
                LEFT JOIN delivery_drivers d ON d.id=a.driver_id AND d.client_id=s.client_id
                LEFT JOIN distribution_routes dr ON dr.id=a.route_id AND dr.client_id=s.client_id
                LEFT JOIN distribution_dispatch_items di ON di.sale_id=s.id AND di.client_id=s.client_id
                LEFT JOIN distribution_dispatches dp ON dp.id=di.dispatch_id AND dp.client_id=s.client_id
                WHERE $whereSql
                ORDER BY s.id DESC
                LIMIT ? OFFSET ?";
        $types2 = $types . 'ii';
        $params2 = $params;
        $params2[] = $limit;
        $params2[] = $offset;
        $orders = dist_orders_rows($conn, $sql, $types2, $params2);
        foreach ($orders as &$o) {
            $o['qty'] = dist_orders_qty($conn, (int)$o['sale_id']);
            $auto = dist_orders_auto_route($conn, $client_id, (int)($o['customer_id'] ?? 0));
            $o['auto_route_id'] = $auto['id'] ?? null;
            $o['auto_route_name'] = $auto['route_name'] ?? '';
            $o['auto_driver_id'] = $auto['driver_id'] ?? null;
            $o['auto_vehicle'] = $auto['vehicle_name'] ?? '';
        }
        unset($o);
        dist_orders_json(['success'=>true,'orders'=>$orders,'total'=>$totalRows,'page'=>$page,'pages'=>max(1, (int)ceil($totalRows/$limit))]);
    }

    if ($action === 'assign_order' || $action === 'bulk_assign') {
        $routeId = ($_POST['route_id'] ?? '') !== '' ? (int)$_POST['route_id'] : null;
        $driverId = ($_POST['driver_id'] ?? '') !== '' ? (int)$_POST['driver_id'] : null;
        $vehicle = trim($_POST['vehicle'] ?? '');
        $deliveryDate = trim($_POST['delivery_date'] ?? date('Y-m-d'));
        $slot = trim($_POST['time_slot'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $saleIds = $action === 'bulk_assign' ? ($_POST['sale_ids'] ?? []) : [(int)($_POST['sale_id'] ?? 0)];
        if (!is_array($saleIds)) $saleIds = explode(',', (string)$saleIds);
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));

        if (empty($saleIds)) dist_orders_json(['success'=>false,'message'=>'Please select at least one order.']);
        if (!$routeId && !$driverId) dist_orders_json(['success'=>false,'message'=>'Route ya rider select karna required hai.']);

        $route = null;
        if ($routeId) {
            $r = dist_orders_rows($conn, "SELECT * FROM distribution_routes WHERE id=? AND client_id=? AND status='Active' LIMIT 1", 'ii', [$routeId, $client_id]);
            if (!$r) dist_orders_json(['success'=>false,'message'=>'Selected route not found or inactive.']);
            $route = $r[0];
            if (!$driverId && !empty($route['driver_id'])) $driverId = (int)$route['driver_id'];
            if ($vehicle === '' && !empty($route['vehicle_name'])) $vehicle = $route['vehicle_name'];
        }
        if (!$driverId) dist_orders_json(['success'=>false,'message'=>'Rider required hai.']);
        $driver = dist_orders_rows($conn, "SELECT * FROM delivery_drivers WHERE id=? AND client_id=? AND status!='Inactive' LIMIT 1", 'ii', [$driverId, $client_id]);
        if (!$driver) dist_orders_json(['success'=>false,'message'=>'Selected rider not found or inactive.']);
        $driver = $driver[0];
        if ($vehicle === '') $vehicle = $driver['vehicle'] ?? '';

        $conn->begin_transaction();
        try {
            foreach ($saleIds as $saleId) {
                $sale = dist_orders_rows($conn, "SELECT s.id FROM sales s WHERE s.id=? AND s.client_id=? LIMIT 1", 'ii', [$saleId, $client_id]);
                if (!$sale) throw new Exception('Sale not found: ' . $saleId);
                $stmt = $conn->prepare("INSERT INTO delivery_order_assignments (client_id, branch_id, sale_id, route_id, driver_id, vehicle, delivery_date, delivery_time_slot, status, assigned_by, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'assigned', ?, ?)
                    ON DUPLICATE KEY UPDATE route_id=VALUES(route_id), driver_id=VALUES(driver_id), vehicle=VALUES(vehicle), delivery_date=VALUES(delivery_date), delivery_time_slot=VALUES(delivery_time_slot), status='assigned', assigned_by=VALUES(assigned_by), assigned_at=CURRENT_TIMESTAMP, notes=VALUES(notes)");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param('iiiiisssis', $client_id, $branch_id, $saleId, $routeId, $driverId, $vehicle, $deliveryDate, $slot, $user_id, $notes);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $stmt->close();
            }
            $stmt = $conn->prepare("UPDATE delivery_drivers SET status='On Delivery' WHERE id=? AND client_id=?");
            if ($stmt) { $stmt->bind_param('ii', $driverId, $client_id); $stmt->execute(); $stmt->close(); }
            $conn->commit();
            dist_orders_json(['success'=>true,'message'=>count($saleIds) . ' order(s) assigned successfully.']);
        } catch (Throwable $e) {
            $conn->rollback();
            dist_orders_json(['success'=>false,'message'=>$e->getMessage()]);
        }
    }

    if ($action === 'bulk_status') {
        $saleIds = $_POST['sale_ids'] ?? [];
        $status = trim($_POST['status'] ?? '');
        $collection = (float)($_POST['collection_amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if (!is_array($saleIds)) $saleIds = explode(',', (string)$saleIds);
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        $allowed = ['assigned','ready_for_dispatch','out_for_delivery','delivered','failed','returned','cancelled'];
        if (empty($saleIds) || !in_array($status, $allowed, true)) dist_orders_json(['success'=>false,'message'=>'Invalid bulk status request.']);
        $in = implode(',', array_fill(0, count($saleIds), '?'));
        $types = 'sd' . str_repeat('i', count($saleIds)) . 'i';
        $params = [$status, $collection];
        foreach ($saleIds as $id) $params[] = $id;
        $params[] = $client_id;
        $deliveredSql = $status === 'delivered' ? ', delivered_at=CURRENT_TIMESTAMP' : '';
        $stmt = $conn->prepare("UPDATE delivery_order_assignments SET status=?, collection_amount=?, failed_reason=" . ($reason !== '' ? '?' : 'failed_reason') . " $deliveredSql WHERE sale_id IN ($in) AND client_id=?");
        if ($reason !== '') {
            $types = 'sds' . str_repeat('i', count($saleIds)) . 'i';
            $params = [$status, $collection, $reason];
            foreach ($saleIds as $id) $params[] = $id;
            $params[] = $client_id;
        }
        if (!$stmt) dist_orders_json(['success'=>false,'message'=>$conn->error]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        dist_orders_json(['success'=>true,'message'=>'Bulk status updated.']);
    }

    if ($action === 'create_dispatch') {
        $saleIds = $_POST['sale_ids'] ?? [];
        if (!is_array($saleIds)) $saleIds = explode(',', (string)$saleIds);
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        if (empty($saleIds)) dist_orders_json(['success'=>false,'message'=>'Please select orders for dispatch.']);
        $dispatchDate = trim($_POST['dispatch_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        $in = implode(',', array_fill(0, count($saleIds), '?'));
        $types = str_repeat('i', count($saleIds)) . 'i';
        $params = $saleIds;
        $params[] = $client_id;
        $assignments = dist_orders_rows($conn, "
            SELECT a.*, s.customer_id, " . dist_orders_total_expr($conn) . " amount
            FROM delivery_order_assignments a
            INNER JOIN sales s ON s.id=a.sale_id AND s.client_id=a.client_id
            WHERE a.sale_id IN ($in) AND a.client_id=? AND a.status IN ('assigned','ready_for_dispatch')
        ", $types, $params);
        if (!$assignments) dist_orders_json(['success'=>false,'message'=>'Selected orders assigned/ready status me nahi hain.']);

        $routeId = $assignments[0]['route_id'] ?? null;
        $driverId = $assignments[0]['driver_id'] ?? null;
        $vehicle = $assignments[0]['vehicle'] ?? '';
        $dispatchNo = dist_orders_next_dispatch_no($conn, $client_id);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO distribution_dispatches (client_id, branch_id, dispatch_no, route_id, driver_id, vehicle, dispatch_date, dispatch_time, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, CURTIME(), 'ready', ?, ?)");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('iisiisssi', $client_id, $branch_id, $dispatchNo, $routeId, $driverId, $vehicle, $dispatchDate, $notes, $user_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $dispatchId = $stmt->insert_id;
            $stmt->close();

            $itemStmt = $conn->prepare("INSERT INTO distribution_dispatch_items (client_id, branch_id, dispatch_id, assignment_id, sale_id, customer_id, route_id, driver_id, order_amount, item_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ready') ON DUPLICATE KEY UPDATE dispatch_id=VALUES(dispatch_id), assignment_id=VALUES(assignment_id), route_id=VALUES(route_id), driver_id=VALUES(driver_id), order_amount=VALUES(order_amount), item_status='ready'");
            if (!$itemStmt) throw new Exception($conn->error);
            foreach ($assignments as $a) {
                $amount = (float)($a['amount'] ?? 0);
                $customerId = isset($a['customer_id']) ? (int)$a['customer_id'] : null;
                $assignmentId = (int)$a['id'];
                $saleId = (int)$a['sale_id'];
                $rId = isset($a['route_id']) ? (int)$a['route_id'] : null;
                $dId = isset($a['driver_id']) ? (int)$a['driver_id'] : null;
                $itemStmt->bind_param('iiiiiiiid', $client_id, $branch_id, $dispatchId, $assignmentId, $saleId, $customerId, $rId, $dId, $amount);
                if (!$itemStmt->execute()) throw new Exception($itemStmt->error);
            }
            $itemStmt->close();
            $upTypes = str_repeat('i', count($saleIds)) . 'i';
            $upParams = $saleIds; $upParams[] = $client_id;
            $up = $conn->prepare("UPDATE delivery_order_assignments SET status='ready_for_dispatch' WHERE sale_id IN ($in) AND client_id=?");
            if (!$up) throw new Exception($conn->error);
            $up->bind_param($upTypes, ...$upParams);
            $up->execute();
            $up->close();
            dist_orders_refresh_dispatch($conn, $client_id, $dispatchId);
            $conn->commit();
            dist_orders_json(['success'=>true,'message'=>'Dispatch created successfully.','dispatch_id'=>$dispatchId,'dispatch_no'=>$dispatchNo]);
        } catch (Throwable $e) {
            $conn->rollback();
            dist_orders_json(['success'=>false,'message'=>$e->getMessage()]);
        }
    }

    if ($action === 'order_detail') {
        $saleId = (int)($_GET['sale_id'] ?? 0);
        if ($saleId <= 0) dist_orders_json(['success'=>false,'message'=>'Invalid order.']);
        $inv = dist_orders_invoice_expr($conn);
        $total = dist_orders_total_expr($conn);
        $pay = dist_orders_payment_expr($conn);
        $cust = dist_orders_customer_name_expr($conn);
        $phone = dist_orders_customer_phone_expr($conn);
        $addr = dist_orders_customer_address_expr($conn);
        $saleDate = dist_orders_sale_date_col($conn);
        $dateSelect = $saleDate !== 'id' ? "s.`$saleDate`" : "NULL";
        $rows = dist_orders_rows($conn, "
            SELECT s.*, $inv invoice_no, $dateSelect created_at, $cust customer_name, $phone phone, $addr address, $total amount, $pay payment_status,
                   a.status assignment_status, a.delivery_date, a.delivery_time_slot, a.vehicle, a.collection_amount, a.failed_reason,
                   d.driver_name, d.phone driver_phone, dr.route_name, dr.route_code, dp.dispatch_no
            FROM sales s
            LEFT JOIN customers c ON c.id=s.customer_id
            LEFT JOIN delivery_order_assignments a ON a.sale_id=s.id AND a.client_id=s.client_id
            LEFT JOIN delivery_drivers d ON d.id=a.driver_id AND d.client_id=s.client_id
            LEFT JOIN distribution_routes dr ON dr.id=a.route_id AND dr.client_id=s.client_id
            LEFT JOIN distribution_dispatch_items di ON di.sale_id=s.id AND di.client_id=s.client_id
            LEFT JOIN distribution_dispatches dp ON dp.id=di.dispatch_id AND dp.client_id=s.client_id
            WHERE s.id=? AND s.client_id=? LIMIT 1
        ", 'ii', [$saleId, $client_id]);
        if (!$rows) dist_orders_json(['success'=>false,'message'=>'Order not found.']);
        $order = $rows[0];
        $order['qty'] = dist_orders_qty($conn, $saleId);
        dist_orders_json(['success'=>true,'order'=>$order]);
    }

    dist_orders_json(['success'=>false,'message'=>'Invalid action.']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('partials/head.php'); ?>
    <title>Distribution Orders</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root{--sidebar-width:292px;--topbar-height:78px;--gold:#f6b900;--page-bg:#f4f6ff;--navy:#17243d;--head:#263650;--border:#dfe5ef;--text:#13233d;--muted:#687282;--green:#16a34a;--red:#dc2626;--orange:#f59e0b;--blue:#2563eb;}
        *{box-sizing:border-box} body{margin:0;background:var(--page-bg);font-family:"Inter","Segoe UI",system-ui,sans-serif;color:var(--text);overflow-x:hidden}.container-scroll{display:flex;min-height:100vh}.sidebar{width:var(--sidebar-width);flex:0 0 var(--sidebar-width);min-height:100vh}.main-panel{flex:1;min-width:0;display:flex;flex-direction:column}.topbar{height:var(--topbar-height);background:#050505;position:sticky;top:0;z-index:999;display:flex;align-items:center}.content-wrapper{padding:24px 24px 60px;min-height:calc(100vh - var(--topbar-height))}.page-header{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}.page-title{font-size:33px;font-weight:900;letter-spacing:-.6px;margin:0}.page-sub{color:var(--muted);font-weight:700}.btn-main{height:48px;border:0;border-radius:10px;background:var(--gold);font-weight:900;color:#111;padding:0 20px}.btn-dark-main{height:48px;border:0;border-radius:10px;background:var(--navy);font-weight:900;color:#fff;padding:0 20px}.btn-gray-main{height:48px;border:0;border-radius:10px;background:#687282;font-weight:900;color:#fff;padding:0 20px}.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.kpi-card{min-height:76px;border-radius:10px;padding:0 18px;color:#fff;display:flex;align-items:center;gap:12px;box-shadow:0 8px 18px rgba(0,0,0,.08)}.kpi-card:nth-child(1){background:linear-gradient(135deg,#4095ff,#2f80ed)}.kpi-card:nth-child(2){background:linear-gradient(135deg,#49be88,#2ea769)}.kpi-card:nth-child(3){background:linear-gradient(135deg,#4ac9d6,#26b4c0)}.kpi-card:nth-child(4){background:linear-gradient(135deg,#f7c943,#f4b400)}.kpi-icon{font-size:21px}.kpi-label{font-size:14px;font-weight:800;line-height:1.1}.kpi-value{font-size:30px;font-weight:900;margin-left:auto}.status-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.status-tab{height:42px;border:1px solid var(--border);background:#fff;color:#17243d;border-radius:10px;padding:0 14px;font-weight:900}.status-tab.active{background:var(--navy);color:#fff;border-color:var(--navy)}.filter-bar{background:#fff;border:1px solid var(--border);border-radius:12px;padding:12px;box-shadow:0 10px 24px rgba(18,35,64,.06);margin-bottom:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.filter-bar:before{content:"⚡ Quick Filters";height:48px;min-width:160px;display:flex;align-items:center;justify-content:center;border-radius:10px;background:#111827;color:#fff;font-size:15px;font-weight:900;padding:0 16px}.filter-input,.filter-select{height:48px;border-radius:10px;border:1px solid var(--border);background:#fff;font-size:15px;color:#111827;padding:0 14px;min-width:160px}.filter-input{min-width:260px;flex:1}.white-card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 26px rgba(18,35,64,.07);overflow:hidden}.card-head{padding:16px 18px;border-bottom:1px solid #e7ecf4;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.card-title{font-size:17px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:8px}.table-wrap{overflow-x:auto}table.custom-table{width:100%;min-width:1320px;border-collapse:separate;border-spacing:0}.custom-table th{background:var(--head);color:#fff;font-size:14px;text-transform:none;padding:16px 14px;border:0;font-weight:900;white-space:nowrap}.custom-table td{padding:15px 14px;border-bottom:1px solid #e7ecf4;color:#19253a;background:#fff;font-size:14px;vertical-align:middle}.custom-table tr:hover td{background:#f8fbff}.order-no{font-weight:900;color:var(--navy)}.customer-name{font-weight:900}.small-muted{font-size:12px;color:var(--muted);font-weight:700}.badge-custom{border-radius:999px;padding:6px 11px;font-size:12px;font-weight:900;display:inline-flex;align-items:center;gap:5px}.badge-pending{background:#fff7ed;color:#ea580c}.badge-assigned{background:#eff6ff;color:#2563eb}.badge-ready{background:#fef3c7;color:#d97706}.badge-out{background:#ecfeff;color:#0891b2}.badge-delivered{background:#dcfce7;color:#16a34a}.badge-failed{background:#fee2e2;color:#dc2626}.badge-paid{background:#dcfce7;color:#16a34a}.badge-unpaid{background:#fee2e2;color:#dc2626}.badge-partial{background:#fef3c7;color:#d97706}.action-btn{width:34px;height:34px;border-radius:8px;background:transparent;border:0;color:#536174}.action-btn:hover{background:#eef4ff;color:var(--navy)}.bulk-bar{display:none;background:var(--navy);color:#fff;border-radius:12px;padding:12px 16px;align-items:center;gap:10px;margin-bottom:14px;font-weight:900}.bulk-bar.show{display:flex}.bulk-count{color:var(--gold)}.pagination-custom{padding:16px 18px;background:#fff;border-top:1px solid #e7ecf4;display:flex;align-items:center;gap:5px}.page-btn{border-radius:8px;width:34px;height:34px;border:1px solid var(--border);background:#fff;font-weight:900}.page-btn.active{background:var(--navy);border-color:var(--navy);color:#fff}.pagination-info{font-size:12px;color:var(--muted);font-weight:800;margin-left:auto}.modal-custom{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(2px)}.modal-custom.show{display:flex}.modal-box{background:#fff;border-radius:14px;width:92%;max-width:720px;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden}.modal-head{background:var(--head);color:#fff;padding:18px 22px;display:flex;align-items:center;justify-content:space-between}.modal-title{font-size:16px;font-weight:900}.modal-close{width:30px;height:30px;border:0;border-radius:8px;background:rgba(255,255,255,.12);color:#fff}.modal-body{padding:22px}.modal-foot{padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}.form-label-custom{font-size:13px;font-weight:900;color:#334155;margin-bottom:6px;display:block}.form-control-custom{width:100%;height:48px;border-radius:10px;border:1px solid var(--border);background:#fff;font-size:15px;color:#111827;padding:0 14px}.form-control-custom:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.08)}textarea.form-control-custom{height:auto;min-height:86px;padding-top:12px}.detail-row{display:flex;padding:9px 0;border-bottom:1px solid #f1f3f7;font-size:14px}.detail-label{width:150px;color:var(--muted);font-weight:800}.detail-value{font-weight:900;color:var(--text)}#toast-container{position:fixed;right:28px;bottom:28px;z-index:9999;display:flex;flex-direction:column;gap:10px}.toast-item{background:#1e2533;color:#fff;border-radius:10px;padding:12px 18px;min-width:280px;box-shadow:0 8px 24px rgba(0,0,0,.2);font-size:13.5px;font-weight:800}.toast-item.success{border-left:4px solid #22c55e}.toast-item.error{border-left:4px solid #ef4444}.toast-item.info{border-left:4px solid #2563eb}.custom-check{width:16px;height:16px;accent-color:var(--blue)}@media(max-width:1200px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:768px){.sidebar{display:none}.content-wrapper{padding:16px}.page-title{font-size:26px}.kpi-grid{grid-template-columns:1fr}.filter-bar:before,.filter-input,.filter-select,.btn-dark-main,.btn-gray-main,.btn-main{width:100%;min-width:100%}}
        
        
        /* =====================================================
   DISTRIBUTION_ORDERS.PHP DASHBOARD DARK PURPLE GLASS THEME
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

.page-sub,
.small-muted,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

/* ================= BUTTONS ================= */
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

.btn-gray-main{
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

.btn-main:hover,
.btn-dark-main:hover,
.btn-gray-main:hover{
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
.kpi-card:nth-child(3){background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
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

/* ================= STATUS TABS ================= */
.status-tabs{
    gap:10px !important;
    margin-bottom:16px !important;
}

.status-tab{
    height:44px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    padding:0 16px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.status-tab:hover,
.status-tab.active{
    color:#fff !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.42) !important;
    transform:translateY(-1px);
}

/* ================= GLASS PANELS ================= */
.filter-bar,
.white-card,
.bulk-bar{
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

/* ================= INPUTS ================= */
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
    min-height:86px !important;
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

.form-label-custom{
    color:#f8fafc !important;
    font-size:13px !important;
    font-weight:900 !important;
}

/* ================= TABLE ================= */
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

/* White hover remove */
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

.custom-table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

.order-no,
.customer-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* ================= BADGES ================= */
.badge-custom{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.badge-pending,
.badge-ready,
.badge-partial{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.badge-assigned,
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

.badge-failed,
.badge-unpaid{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* ================= ACTIONS / PAGINATION / BULK ================= */
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

.bulk-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(109,76,255,.82), rgba(15,23,42,.72)) !important;
}

.bulk-count{
    color:#fbbf24 !important;
}

.bulk-bar .btn{
    border-radius:10px !important;
    font-weight:900 !important;
}

.custom-check{
    accent-color:#7c3aed !important;
}

/* ================= TOAST ================= */
.toast-item{
    background:#0f172a !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}

.toast-item.success{border-left:4px solid #22c55e !important;}
.toast-item.error{border-left:4px solid #ef4444 !important;}
.toast-item.info{border-left:4px solid #7c3aed !important;}

/* ================= SCROLLBAR ================= */
.table-wrap::-webkit-scrollbar,
.modal-body::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-wrap::-webkit-scrollbar-track,
.modal-body::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb,
.modal-body::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb:hover,
.modal-body::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

/* =====================================================
   DISTRIBUTION ORDERS POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

#assignModal.modal-custom,
#statusModal.modal-custom,
#dispatchModal.modal-custom,
#detailModal.modal-custom{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
}

#assignModal .modal-box,
#statusModal .modal-box,
#dispatchModal .modal-box,
#detailModal .modal-box{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    max-height:92vh !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#assignModal .modal-box,
#statusModal .modal-box,
#dispatchModal .modal-box,
#detailModal .modal-box{
    max-width:680px !important;
}

#assignModal .modal-head,
#statusModal .modal-head,
#dispatchModal .modal-head,
#detailModal .modal-head{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    color:#fff !important;
}

#assignModal .modal-title,
#statusModal .modal-title,
#dispatchModal .modal-title,
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

#assignModal .modal-title::before,
#statusModal .modal-title::before,
#dispatchModal .modal-title::before,
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

#assignModal .modal-close,
#statusModal .modal-close,
#dispatchModal .modal-close,
#detailModal .modal-close{
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

#assignModal .modal-close::before,
#assignModal .modal-close::after,
#statusModal .modal-close::before,
#statusModal .modal-close::after,
#dispatchModal .modal-close::before,
#dispatchModal .modal-close::after,
#detailModal .modal-close::before,
#detailModal .modal-close::after{
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

#assignModal .modal-close::before,
#statusModal .modal-close::before,
#dispatchModal .modal-close::before,
#detailModal .modal-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#assignModal .modal-close::after,
#statusModal .modal-close::after,
#dispatchModal .modal-close::after,
#detailModal .modal-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#assignModal .modal-close:hover,
#statusModal .modal-close:hover,
#dispatchModal .modal-close:hover,
#detailModal .modal-close:hover{
    background:#ef4444 !important;
}

#assignModal .modal-body,
#statusModal .modal-body,
#dispatchModal .modal-body,
#detailModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#assignModal .modal-foot,
#statusModal .modal-foot,
#dispatchModal .modal-foot,
#detailModal .modal-foot{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#assignModal label,
#statusModal label,
#dispatchModal label,
#detailModal label,
#assignModal .form-label-custom,
#statusModal .form-label-custom,
#dispatchModal .form-label-custom{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#assignModal .form-control-custom,
#statusModal .form-control-custom,
#dispatchModal .form-control-custom,
#assignModal input,
#assignModal select,
#assignModal textarea,
#statusModal input,
#statusModal select,
#statusModal textarea,
#dispatchModal input,
#dispatchModal textarea{
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

#assignModal textarea,
#statusModal textarea,
#dispatchModal textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#assignModal input::placeholder,
#statusModal textarea::placeholder,
#dispatchModal textarea::placeholder{
    color:#94a3b8 !important;
}

#assignModal input:focus,
#assignModal select:focus,
#assignModal textarea:focus,
#statusModal input:focus,
#statusModal select:focus,
#statusModal textarea:focus,
#dispatchModal input:focus,
#dispatchModal textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#assignModal select option,
#statusModal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

#dispatchModal .alert-info{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:14px !important;
    font-weight:800 !important;
}

/* Detail modal rows */
#detailModal .detail-row{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    margin-bottom:10px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#detailModal .detail-label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

#detailModal .detail-value{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* Modal buttons */
#assignModal .btn-light,
#statusModal .btn-light,
#dispatchModal .btn-light,
#detailModal .btn-light{
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

#assignModal .btn-dark-main,
#statusModal .btn-dark-main,
#dispatchModal .btn-dark-main{
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

#assignModal .btn-light:hover,
#statusModal .btn-light:hover,
#dispatchModal .btn-light:hover,
#detailModal .btn-light:hover,
#assignModal .btn-dark-main:hover,
#statusModal .btn-dark-main:hover,
#dispatchModal .btn-dark-main:hover{
    transform:translateY(-1px);
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
                    <h1 class="page-title"><i class="bi bi-box-seam me-2"></i>Distribution Orders</h1>
                    <div class="page-sub">Pending → Assigned → Ready For Dispatch → Out For Delivery → Delivered / Failed</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn-main" onclick="openDispatchModal()"><i class="bi bi-truck-front me-1"></i>Create Dispatch</button>
                    <button class="btn-dark-main" onclick="loadOrders(1)"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                </div>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card"><i class="bi bi-hourglass-split kpi-icon"></i><div class="kpi-label">Pending<br>Orders</div><div class="kpi-value" id="kPending">0</div></div>
                <div class="kpi-card"><i class="bi bi-person-check kpi-icon"></i><div class="kpi-label">Assigned<br>Orders</div><div class="kpi-value" id="kAssigned">0</div></div>
                <div class="kpi-card"><i class="bi bi-truck kpi-icon"></i><div class="kpi-label">Out For<br>Delivery</div><div class="kpi-value" id="kOut">0</div></div>
                <div class="kpi-card"><i class="bi bi-cash-coin kpi-icon"></i><div class="kpi-label">Collection<br>Today</div><div class="kpi-value" id="kCollection">0</div></div>
            </div>

            <div class="status-tabs" id="statusTabs">
                <button class="status-tab active" data-tab="Pending" onclick="switchTab('Pending')">Pending</button>
                <button class="status-tab" data-tab="Assigned" onclick="switchTab('Assigned')">Assigned</button>
                <button class="status-tab" data-tab="Ready For Dispatch" onclick="switchTab('Ready For Dispatch')">Ready For Dispatch</button>
                <button class="status-tab" data-tab="Out For Delivery" onclick="switchTab('Out For Delivery')">Out For Delivery</button>
                <button class="status-tab" data-tab="Delivered" onclick="switchTab('Delivered')">Delivered</button>
                <button class="status-tab" data-tab="Failed" onclick="switchTab('Failed')">Failed</button>
                <button class="status-tab" data-tab="Returned" onclick="switchTab('Returned')">Returned</button>
                <button class="status-tab" data-tab="Cancelled" onclick="switchTab('Cancelled')">Cancelled</button>
                <button class="status-tab" data-tab="All" onclick="switchTab('All')">All</button>
            </div>

            <div class="filter-bar">
                <input id="fSearch" class="filter-input" placeholder="Search invoice, customer, phone, order ID">
                <select id="fRoute" class="filter-select"><option value="">All Routes</option></select>
                <select id="fDriver" class="filter-select"><option value="">All Riders</option></select>
                <select id="fCustomerType" class="filter-select"><option value="">All Customers</option><option>Walk-in</option><option>Retailer</option><option>Wholesaler</option></select>
                <input type="date" id="fDate" class="filter-select">
                <button class="btn-dark-main" onclick="loadOrders(1)"><i class="bi bi-funnel me-1"></i>Filter</button>
                <button class="btn-gray-main" onclick="resetFilters()">Reset</button>
            </div>

            <div id="bulkBar" class="bulk-bar">
                <span><span id="bulkCount" class="bulk-count">0</span> order(s) selected</span>
                <button class="btn btn-sm btn-light" onclick="openAssignModal(true)">Bulk Assign</button>
                <button class="btn btn-sm btn-light" onclick="bulkStatus('ready_for_dispatch')">Ready</button>
                <button class="btn btn-sm btn-light" onclick="bulkStatus('out_for_delivery')">Out For Delivery</button>
                <button class="btn btn-sm btn-success" onclick="openStatusModal('delivered')">Mark Delivered</button>
                <button class="btn btn-sm btn-warning" onclick="openStatusModal('failed')">Mark Failed</button>
                <button class="btn btn-sm btn-outline-light" onclick="openStatusModal('returned')">Mark Returned</button>
                <button class="btn btn-sm btn-outline-light ms-auto" onclick="clearSelection()">Cancel</button>
            </div>

            <section class="white-card">
                <div class="card-head">
                    <div class="card-title"><i class="bi bi-list-check"></i> POS Distribution Orders</div>
                    <div class="small-muted" id="orderCount">0 orders</div>
                </div>
                <div class="table-wrap">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="custom-check" id="selectAll" onchange="toggleSelectAll(this)"></th>
                                <th>Order</th><th>Customer</th><th>Type</th><th>Amount</th><th>Payment</th><th>Route</th><th>Rider</th><th>Delivery</th><th>Dispatch</th><th>Status</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="ordersBody"></tbody>
                    </table>
                </div>
                <div class="pagination-custom"><div id="paginationBtns"></div><div class="pagination-info" id="paginationInfo"></div></div>
            </section>
        </main>
    </div>
</div>

<div class="modal-custom" id="assignModal"><div class="modal-box"><div class="modal-head"><div class="modal-title"><i class="bi bi-send-check me-1"></i>Assign Route / Rider</div><button class="modal-close" onclick="closeModal('assignModal')">×</button></div><div class="modal-body"><input type="hidden" id="assignSaleIds"><div class="mb-3"><label class="form-label-custom">Distribution Route</label><select id="assignRoute" class="form-control-custom"><option value="">Select Route</option></select></div><div class="mb-3"><label class="form-label-custom">Rider</label><select id="assignDriver" class="form-control-custom"><option value="">Select Rider</option></select></div><div class="mb-3"><label class="form-label-custom">Vehicle</label><input id="assignVehicle" class="form-control-custom" placeholder="Auto from route/rider"></div><div class="row"><div class="col-md-6 mb-3"><label class="form-label-custom">Delivery Date</label><input type="date" id="assignDate" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div><div class="col-md-6 mb-3"><label class="form-label-custom">Time Slot</label><input id="assignSlot" class="form-control-custom" placeholder="2PM - 5PM"></div></div><div class="mb-3"><label class="form-label-custom">Notes</label><textarea id="assignNotes" class="form-control-custom"></textarea></div></div><div class="modal-foot"><button class="btn btn-light" onclick="closeModal('assignModal')">Cancel</button><button class="btn-dark-main" onclick="saveAssign()">Save Assign</button></div></div></div>
<div class="modal-custom" id="statusModal"><div class="modal-box"><div class="modal-head"><div class="modal-title"><i class="bi bi-pencil-square me-1"></i>Update Order Status</div><button class="modal-close" onclick="closeModal('statusModal')">×</button></div><div class="modal-body"><input type="hidden" id="statusSaleIds"><input type="hidden" id="statusValue"><div class="mb-3"><label class="form-label-custom">Collection Amount</label><input type="number" step="0.01" id="statusCollection" class="form-control-custom" value="0"></div><div class="mb-3"><label class="form-label-custom">Reason / Remarks</label><textarea id="statusReason" class="form-control-custom" placeholder="Failed/returned/cancelled reason"></textarea></div></div><div class="modal-foot"><button class="btn btn-light" onclick="closeModal('statusModal')">Cancel</button><button class="btn-dark-main" onclick="saveStatus()">Save Status</button></div></div></div>
<div class="modal-custom" id="dispatchModal"><div class="modal-box"><div class="modal-head"><div class="modal-title"><i class="bi bi-truck-front me-1"></i>Create Dispatch</div><button class="modal-close" onclick="closeModal('dispatchModal')">×</button></div><div class="modal-body"><input type="hidden" id="dispatchSaleIds"><div class="mb-3"><label class="form-label-custom">Dispatch Date</label><input type="date" id="dispatchDate" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div><div class="mb-3"><label class="form-label-custom">Notes</label><textarea id="dispatchNotes" class="form-control-custom" placeholder="Optional dispatch note"></textarea></div><div class="alert alert-info mb-0">Selected orders must be in Assigned or Ready For Dispatch status.</div></div><div class="modal-foot"><button class="btn btn-light" onclick="closeModal('dispatchModal')">Cancel</button><button class="btn-dark-main" onclick="createDispatch()">Create Dispatch</button></div></div></div>
<div class="modal-custom" id="detailModal"><div class="modal-box"><div class="modal-head"><div class="modal-title"><i class="bi bi-receipt-cutoff me-1"></i>Order Detail</div><button class="modal-close" onclick="closeModal('detailModal')">×</button></div><div class="modal-body" id="detailBody"></div><div class="modal-foot"><button class="btn btn-light" onclick="closeModal('detailModal')">Close</button></div></div></div>
<div id="toast-container"></div>

<script>
const pageUrl = '<?= basename(__FILE__) ?>';
let currentTab = 'Pending';
let currentPage = 1;
let visibleOrders = [];
let routes = [];
let drivers = [];
function esc(v){return String(v ?? '').replace(/[&<>'"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[m]));}
function money(v){return 'PKR ' + Number(v || 0).toLocaleString();}
function post(action, data={}){const fd=new FormData();fd.append('ajax','1');fd.append('action',action);Object.keys(data).forEach(k=>{if(Array.isArray(data[k])) data[k].forEach(v=>fd.append(k+'[]',v)); else fd.append(k,data[k]);});return fetch(pageUrl,{method:'POST',body:fd}).then(r=>r.json());}
function get(action, params={}){const u=new URL(pageUrl, window.location.href);u.searchParams.set('ajax','1');u.searchParams.set('action',action);Object.keys(params).forEach(k=>u.searchParams.set(k,params[k]));return fetch(u).then(r=>r.json());}
function toast(msg,type='success'){const el=document.createElement('div');el.className='toast-item '+type;el.textContent=msg;document.getElementById('toast-container').appendChild(el);setTimeout(()=>el.remove(),3300);}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function openModal(id){document.getElementById(id).classList.add('show');}
function statusBadge(s){s=(s||'Pending').toString();let cls='badge-pending', label='Pending'; if(s==='assigned'){cls='badge-assigned';label='Assigned'} else if(s==='ready_for_dispatch'){cls='badge-ready';label='Ready'} else if(s==='out_for_delivery'){cls='badge-out';label='Out For Delivery'} else if(s==='delivered'){cls='badge-delivered';label='Delivered'} else if(['failed','returned','cancelled'].includes(s)){cls='badge-failed';label=s.charAt(0).toUpperCase()+s.slice(1)} return `<span class="badge-custom ${cls}">${label}</span>`;}
function paymentBadge(p){p=(p||'Cash').toString();let l=p.toLowerCase();let cls=(l.includes('paid')||l.includes('cash'))?'badge-paid':(l.includes('partial')?'badge-partial':'badge-unpaid');return `<span class="badge-custom ${cls}">${esc(p)}</span>`;}
function loadStats(){get('stats').then(r=>{if(!r.success)return; const s=r.stats;kPending.textContent=s.pending;kAssigned.textContent=s.assigned;kOut.textContent=s.out;kCollection.textContent=money(s.collection).replace('PKR ','');});}
function loadRoutes(){get('routes').then(r=>{routes=r.routes||[];let opts='<option value="">All Routes</option>', opts2='<option value="">Select Route</option>';routes.forEach(rt=>{opts += `<option value="${rt.id}">${esc(rt.route_name)}${rt.area?' - '+esc(rt.area):''}</option>`;opts2 += `<option value="${rt.id}" data-driver="${esc(rt.driver_id||'')}" data-vehicle="${esc(rt.vehicle_name||'')}">${esc(rt.route_name)}${rt.area?' - '+esc(rt.area):''}</option>`;});fRoute.innerHTML=opts;assignRoute.innerHTML=opts2;});}
function loadDrivers(){get('drivers').then(r=>{drivers=r.drivers||[];let opts='<option value="">All Riders</option>', opts2='<option value="">Select Rider</option>';drivers.forEach(d=>{opts += `<option value="${d.id}">${esc(d.driver_name)}</option>`;opts2 += `<option value="${d.id}" data-vehicle="${esc(d.vehicle||'')}">${esc(d.driver_name)} - ${esc(d.vehicle||'')}</option>`;});fDriver.innerHTML=opts;assignDriver.innerHTML=opts2;});}
function switchTab(tab){currentTab=tab;document.querySelectorAll('.status-tab').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));loadOrders(1);}
function loadOrders(p=1){currentPage=p;get('orders',{page:p,limit:10,tab:currentTab,search:fSearch.value,route_id:fRoute.value,driver_id:fDriver.value,customer_type:fCustomerType.value,date:fDate.value}).then(r=>{if(!r.success){toast(r.message||'Orders load failed','error');return;}visibleOrders=r.orders||[];let html='';visibleOrders.forEach(o=>{let st=o.assignment_status||'Pending';let route=o.route_name||o.auto_route_name||'-';let routeHint=(!o.route_name&&o.auto_route_name)?`<div class="small-muted">Suggested: ${esc(o.auto_route_name)}</div>`:'';html+=`<tr><td><input type="checkbox" class="custom-check row-check" value="${o.sale_id}" onchange="updateBulk()"></td><td><div class="order-no">#${esc(o.invoice_no||o.sale_id)}</div><div class="small-muted">${esc(o.created_at||'')}</div></td><td><div class="customer-name">${esc(o.customer_name)}</div><div class="small-muted">${esc(o.phone||'')}${o.address?' • '+esc(o.address):''}</div></td><td>${esc(o.customer_type||'-')}</td><td><b>${money(o.amount)}</b><div class="small-muted">Qty: ${Number(o.qty||0)}</div></td><td>${paymentBadge(o.payment_status)}</td><td>${esc(route)}${routeHint}</td><td>${esc(o.driver_name||'-')}</td><td>${esc(o.delivery_date||'-')}<div class="small-muted">${esc(o.delivery_time_slot||'')}</div></td><td>${o.dispatch_no?`<span class="badge-custom badge-ready">${esc(o.dispatch_no)}</span>`:'-'}</td><td>${statusBadge(st)}</td><td><button class="action-btn" title="Assign" onclick="openAssignModal(false,${o.sale_id})"><i class="bi bi-send"></i></button><button class="action-btn" title="View" onclick="viewOrder(${o.sale_id})"><i class="bi bi-eye"></i></button>${o.assignment_id?`<button class="action-btn" title="Status" onclick="openStatusModal('delivered',${o.sale_id},${Number(o.amount||0)})"><i class="bi bi-check2-circle"></i></button>`:''}</td></tr>`;});ordersBody.innerHTML=html||'<tr><td colspan="12" class="text-center py-4">No orders found</td></tr>';orderCount.textContent=(r.total||0)+' orders';paginationInfo.textContent=`Page ${r.page} of ${r.pages}`;renderPagination(r.page,r.pages);loadStats();clearSelection(false);});}
function renderPagination(page,pages){let html='';html += `<button class="page-btn" ${page<=1?'disabled':''} onclick="loadOrders(${page-1})">‹</button>`;for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++)html += `<button class="page-btn ${i===page?'active':''}" onclick="loadOrders(${i})">${i}</button>`;html += `<button class="page-btn" ${page>=pages?'disabled':''} onclick="loadOrders(${page+1})">›</button>`;paginationBtns.innerHTML=html;}
function resetFilters(){fSearch.value='';fRoute.value='';fDriver.value='';fCustomerType.value='';fDate.value='';loadOrders(1);}
function selectedIds(){return [...document.querySelectorAll('.row-check:checked')].map(c=>c.value);}
function toggleSelectAll(el){document.querySelectorAll('.row-check').forEach(c=>c.checked=el.checked);updateBulk();}
function updateBulk(){let n=selectedIds().length;bulkBar.classList.toggle('show',n>0);bulkCount.textContent=n;}
function clearSelection(clear=true){document.querySelectorAll('.row-check').forEach(c=>c.checked=false);if(selectAll)selectAll.checked=false;updateBulk();}
function openAssignModal(isBulk=false,saleId=0){let ids=isBulk?selectedIds():[saleId];if(!ids.length||!ids[0]){toast('Please select order first','error');return;}assignSaleIds.value=ids.join(',');assignRoute.value='';assignDriver.value='';assignVehicle.value='';assignSlot.value='';assignNotes.value='';openModal('assignModal');}
function saveAssign(){let ids=assignSaleIds.value.split(',').filter(Boolean);let data={route_id:assignRoute.value,driver_id:assignDriver.value,vehicle:assignVehicle.value,delivery_date:assignDate.value,time_slot:assignSlot.value,notes:assignNotes.value};if(ids.length>1){data.sale_ids=ids;post('bulk_assign',data).then(afterSave);}else{data.sale_id=ids[0];post('assign_order',data).then(afterSave);}}
function afterSave(r){toast(r.message,r.success?'success':'error');if(r.success){closeModal('assignModal');clearSelection();loadOrders(currentPage);}}
function applyRouteDefaults(){let opt=assignRoute.options[assignRoute.selectedIndex];if(!opt)return;let d=opt.dataset.driver||'';let v=opt.dataset.vehicle||'';if(d&&!assignDriver.value)assignDriver.value=d;if(v&&!assignVehicle.value)assignVehicle.value=v;}
function applyDriverDefaults(){let opt=assignDriver.options[assignDriver.selectedIndex];if(opt&&opt.dataset.vehicle&&!assignVehicle.value)assignVehicle.value=opt.dataset.vehicle;}
assignRoute.addEventListener('change',applyRouteDefaults);assignDriver.addEventListener('change',applyDriverDefaults);
function bulkStatus(status){let ids=selectedIds();if(!ids.length){toast('Please select orders first','error');return;}post('bulk_status',{sale_ids:ids,status:status,collection_amount:0}).then(r=>{toast(r.message,r.success?'success':'error');if(r.success){clearSelection();loadOrders(currentPage);}});}
function openStatusModal(status,saleId=0,amount=0){let ids=saleId?[saleId]:selectedIds();if(!ids.length){toast('Please select orders first','error');return;}statusSaleIds.value=ids.join(',');statusValue.value=status;statusCollection.value=status==='delivered'?amount:0;statusReason.value='';openModal('statusModal');}
function saveStatus(){let ids=statusSaleIds.value.split(',').filter(Boolean);post('bulk_status',{sale_ids:ids,status:statusValue.value,collection_amount:statusCollection.value,reason:statusReason.value}).then(r=>{toast(r.message,r.success?'success':'error');if(r.success){closeModal('statusModal');clearSelection();loadOrders(currentPage);}});}
function openDispatchModal(){let ids=selectedIds();if(!ids.length){toast('Please select assigned/ready orders first','error');return;}dispatchSaleIds.value=ids.join(',');dispatchNotes.value='';openModal('dispatchModal');}
function createDispatch(){let ids=dispatchSaleIds.value.split(',').filter(Boolean);post('create_dispatch',{sale_ids:ids,dispatch_date:dispatchDate.value,notes:dispatchNotes.value}).then(r=>{toast(r.message,r.success?'success':'error');if(r.success){closeModal('dispatchModal');clearSelection();loadOrders(currentPage);}});}
function viewOrder(id){get('order_detail',{sale_id:id}).then(r=>{if(!r.success){toast(r.message,'error');return;}let o=r.order;detailBody.innerHTML=`<div class="detail-row"><div class="detail-label">Invoice</div><div class="detail-value">#${esc(o.invoice_no)}</div></div><div class="detail-row"><div class="detail-label">Customer</div><div class="detail-value">${esc(o.customer_name)} (${esc(o.phone||'')})</div></div><div class="detail-row"><div class="detail-label">Address</div><div class="detail-value">${esc(o.address||'-')}</div></div><div class="detail-row"><div class="detail-label">Amount</div><div class="detail-value">${money(o.amount)}</div></div><div class="detail-row"><div class="detail-label">Payment</div><div class="detail-value">${esc(o.payment_status||'-')}</div></div><div class="detail-row"><div class="detail-label">Route</div><div class="detail-value">${esc(o.route_name||'-')}</div></div><div class="detail-row"><div class="detail-label">Rider</div><div class="detail-value">${esc(o.driver_name||'-')} ${o.driver_phone?' / '+esc(o.driver_phone):''}</div></div><div class="detail-row"><div class="detail-label">Dispatch</div><div class="detail-value">${esc(o.dispatch_no||'-')}</div></div><div class="detail-row"><div class="detail-label">Status</div><div class="detail-value">${esc(o.assignment_status||'Pending')}</div></div>`;openModal('detailModal');});}
document.addEventListener('DOMContentLoaded',()=>{loadRoutes();loadDrivers();loadStats();loadOrders(1);});
</script>
</body>
</html>
