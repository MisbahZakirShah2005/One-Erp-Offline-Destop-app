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

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function jsonOut($arr){ header('Content-Type: application/json'); echo json_encode($arr); exit; }

function tableExists(mysqli $conn, string $table): bool {
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

function colExists(mysqli $conn, string $table, string $col): bool {
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

function firstCol(mysqli $conn, string $table, array $cols, string $fallback = ''): string {
    foreach ($cols as $c) if (colExists($conn, $table, $c)) return $c;
    return $fallback;
}

function scalarVal(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? null;
}

function fetchRows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function saleTotalExpr(mysqli $conn): string {
    foreach (['grand_total', 'total_amount', 'total'] as $c) if (colExists($conn, 'sales', $c)) return "COALESCE(s.`$c`,0)";
    return '0';
}
function invoiceExpr(mysqli $conn): string {
    $c = firstCol($conn, 'sales', ['invoice_no', 'order_no', 'reference_no'], '');
    return $c ? "COALESCE(NULLIF(s.`$c`,''), CONCAT('ORD-',s.id))" : "CONCAT('ORD-',s.id)";
}
function paymentExpr(mysqli $conn): string {
    if (colExists($conn, 'sales', 'payment_status')) return "COALESCE(NULLIF(s.payment_status,''),'Unpaid')";
    if (colExists($conn, 'sales', 'payment_method')) return "COALESCE(NULLIF(s.payment_method,''),'Cash')";
    return "'Cash'";
}
function customerNameExpr(mysqli $conn): string {
    $saleName = colExists($conn, 'sales', 'customer_name') ? "NULLIF(s.customer_name,'')" : "NULL";
    $custCol = tableExists($conn, 'customers') ? firstCol($conn, 'customers', ['name', 'customer_name', 'full_name'], '') : '';
    $custExpr = $custCol ? "NULLIF(c.`$custCol`,'')" : "NULL";
    return "COALESCE($custExpr, $saleName, 'Walk-in Customer')";
}
function customerPhoneExpr(mysqli $conn): string {
    $phoneCol = tableExists($conn, 'customers') ? firstCol($conn, 'customers', ['phone', 'mobile', 'contact_no', 'phone_number'], '') : '';
    return $phoneCol ? "COALESCE(c.`$phoneCol`,'')" : "''";
}
function customerAddressExpr(mysqli $conn): string {
    $addrCol = tableExists($conn, 'customers') ? firstCol($conn, 'customers', ['address', 'customer_address', 'location'], '') : '';
    return $addrCol ? "COALESCE(c.`$addrCol`,'')" : "''";
}
function saleDateColumn(mysqli $conn): string { return firstCol($conn, 'sales', ['created_at', 'sale_date', 'order_date', 'date'], 'id'); }
function getOrderQty(mysqli $conn, int $sale_id): float {
    if (!tableExists($conn, 'sale_items')) return 0;
    $qtyCol = firstCol($conn, 'sale_items', ['qty', 'quantity', 'quantity_ordered'], 'qty');
    if (!$qtyCol) return 0;
    return (float)scalarVal($conn, "SELECT COALESCE(SUM(`$qtyCol`),0) FROM sale_items WHERE sale_id=?", 'i', [$sale_id]);
}
function nextDriverCode(mysqli $conn, int $client_id): string {
    $n = (int)scalarVal($conn, "SELECT COUNT(*) + 1 FROM delivery_drivers WHERE client_id=?", 'i', [$client_id]);
    return 'DRV-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}
function autoRouteForCustomer(mysqli $conn, int $client_id, int $customer_id): ?array {
    if ($customer_id <= 0 || !tableExists($conn, 'distribution_route_customers') || !tableExists($conn, 'distribution_routes')) return null;
    $rows = fetchRows($conn, "
        SELECT r.id, r.route_name, r.route_code, r.driver_id, r.driver_name, r.vehicle_name
        FROM distribution_route_customers rc
        INNER JOIN distribution_routes r ON r.id = rc.route_id AND r.client_id = rc.client_id
        WHERE rc.client_id = ? AND rc.customer_id = ? AND rc.status = 'Active' AND r.status = 'Active'
        ORDER BY rc.sequence_no ASC, rc.id ASC
        LIMIT 1
    ", 'ii', [$client_id, $customer_id]);
    return $rows[0] ?? null;
}
function refreshDistributionDelivery(mysqli $conn, int $client_id, int $delivery_id): void {
    if (!tableExists($conn, 'distribution_route_deliveries') || !tableExists($conn, 'distribution_route_delivery_items')) return;
    $s = fetchRows($conn, "
        SELECT COUNT(*) total_orders,
               SUM(delivery_status IN ('Pending','Out For Delivery')) pending_orders,
               SUM(delivery_status = 'Delivered') delivered_orders,
               SUM(delivery_status = 'Failed') failed_orders,
               SUM(delivery_status = 'Returned') returned_orders,
               COALESCE(SUM(payment_collected),0) total_collection
        FROM distribution_route_delivery_items
        WHERE client_id=? AND delivery_id=?
    ", 'ii', [$client_id, $delivery_id]);
    $row = $s[0] ?? [];
    $total = (int)($row['total_orders'] ?? 0);
    $pending = (int)($row['pending_orders'] ?? 0);
    $delivered = (int)($row['delivered_orders'] ?? 0);
    $failed = (int)($row['failed_orders'] ?? 0);
    $returned = (int)($row['returned_orders'] ?? 0);
    $collection = (float)($row['total_collection'] ?? 0);
    $status = 'Pending';
    if ($total > 0 && $pending === 0) $status = 'Completed';
    elseif ($delivered > 0 || $failed > 0 || $returned > 0) $status = 'In Progress';
    $stmt = $conn->prepare("UPDATE distribution_route_deliveries SET total_orders=?, pending_orders=?, delivered_orders=?, failed_orders=?, returned_orders=?, total_collection=?, status=? WHERE id=? AND client_id=?");
    if ($stmt) {
        $stmt->bind_param('iiiiidsii', $total, $pending, $delivered, $failed, $returned, $collection, $status, $delivery_id, $client_id);
        $stmt->execute();
        $stmt->close();
    }
}
function ensureDistributionDeliveryItem(mysqli $conn, int $client_id, int $branch_id, int $user_id, int $sale_id, ?int $route_id, int $driver_id, string $driver_name, string $vehicle, string $delivery_date, float $amount, ?int $customer_id, string $payment_method): void {
    if (!$route_id || $route_id <= 0 || !tableExists($conn, 'distribution_route_deliveries') || !tableExists($conn, 'distribution_route_delivery_items')) return;
    $stmt = $conn->prepare("INSERT INTO distribution_route_deliveries (client_id, branch_id, route_id, delivery_date, driver_id, driver_name, vehicle_name, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE driver_id=VALUES(driver_id), driver_name=VALUES(driver_name), vehicle_name=VALUES(vehicle_name), updated_at=CURRENT_TIMESTAMP");
    if (!$stmt) return;
    $stmt->bind_param('iiiisssi', $client_id, $branch_id, $route_id, $delivery_date, $driver_id, $driver_name, $vehicle, $user_id);
    $stmt->execute();
    $stmt->close();
    $delivery_id = (int)scalarVal($conn, "SELECT id FROM distribution_route_deliveries WHERE client_id=? AND route_id=? AND delivery_date=? LIMIT 1", 'iis', [$client_id, $route_id, $delivery_date]);
    if ($delivery_id <= 0) return;
    $sequence_no = $customer_id ? (int)scalarVal($conn, "SELECT COALESCE(sequence_no,0) FROM distribution_route_customers WHERE client_id=? AND route_id=? AND customer_id=? LIMIT 1", 'iii', [$client_id, $route_id, $customer_id]) : 0;
    $status = 'Pending';
    $stmt = $conn->prepare("INSERT INTO distribution_route_delivery_items (client_id, branch_id, delivery_id, route_id, sale_id, customer_id, sequence_no, order_amount, payment_collected, payment_method, delivery_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
        ON DUPLICATE KEY UPDATE route_id=VALUES(route_id), customer_id=VALUES(customer_id), order_amount=VALUES(order_amount), payment_method=VALUES(payment_method), updated_at=CURRENT_TIMESTAMP");
    if ($stmt) {
        $stmt->bind_param('iiiiiiidss', $client_id, $branch_id, $delivery_id, $route_id, $sale_id, $customer_id, $sequence_no, $amount, $payment_method, $status);
        $stmt->execute();
        $stmt->close();
    }
    refreshDistributionDelivery($conn, $client_id, $delivery_id);
}

if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'stats') {
        $today = date('Y-m-d');
        $pending = (int)scalarVal($conn, "SELECT COUNT(*) FROM sales s LEFT JOIN delivery_order_assignments a ON a.sale_id=s.id AND a.client_id=s.client_id WHERE s.client_id=? AND a.id IS NULL", 'i', [$client_id]);
        $assigned = (int)scalarVal($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='assigned'", 'i', [$client_id]);
        $ofd = (int)scalarVal($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='out_for_delivery'", 'i', [$client_id]);
        $deliveredToday = (int)scalarVal($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND status='delivered' AND delivery_date=?", 'is', [$client_id, $today]);
        $drivers = (int)scalarVal($conn, "SELECT COUNT(*) FROM delivery_drivers WHERE client_id=? AND status!='Inactive'", 'i', [$client_id]);
        $routeCustomers = tableExists($conn, 'distribution_route_customers') ? (int)scalarVal($conn, "SELECT COUNT(*) FROM distribution_route_customers WHERE client_id=? AND status='Active'", 'i', [$client_id]) : 0;
        jsonOut(['success'=>true, 'stats'=>compact('pending','assigned','ofd','deliveredToday','drivers','routeCustomers')]);
    }

    if ($action === 'driver_code') jsonOut(['success'=>true, 'code'=>nextDriverCode($conn, $client_id)]);

    if ($action === 'drivers' || $action === 'get_riders') {
        $today = date('Y-m-d');
        $search = trim($_GET['search'] ?? $_POST['search'] ?? '');
        $status = trim($_GET['status'] ?? $_POST['status'] ?? '');
        $where = ['d.client_id=?'];
        $types = 'i';
        $params = [$client_id];
        if ($status !== '') { $where[] = 'd.status=?'; $types .= 's'; $params[] = $status; }
        else { $where[] = "d.status!='Inactive'"; }
        if ($search !== '') { $where[] = '(d.driver_code LIKE ? OR d.driver_name LIKE ? OR d.phone LIKE ? OR d.vehicle LIKE ?)'; $types .= 'ssss'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like, $like); }
        $sql = "SELECT d.id, d.branch_id, d.driver_code, d.driver_name, d.phone, d.cnic, d.vehicle, d.license_no, d.address, d.status,
                    COALESCE(SUM(a.delivery_date=?),0) today_total,
                    COALESCE(SUM(a.delivery_date=? AND a.status='delivered'),0) today_delivered,
                    COALESCE(SUM(a.delivery_date=? AND a.status IN ('assigned','out_for_delivery')),0) today_pending
                FROM delivery_drivers d
                LEFT JOIN delivery_order_assignments a ON a.driver_id=d.id AND a.client_id=d.client_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY d.id
                ORDER BY d.id DESC";
        $types2 = 'sss' . $types;
        $params2 = [$today, $today, $today];
        foreach ($params as $p) $params2[] = $p;
        jsonOut(['success'=>true, 'drivers'=>fetchRows($conn, $sql, $types2, $params2), 'riders'=>fetchRows($conn, $sql, $types2, $params2)]);
    }

    if ($action === 'save_rider') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['driver_name'] ?? $_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $cnic = trim($_POST['cnic'] ?? '');
        $vehicle = trim($_POST['vehicle'] ?? '');
        $license = trim($_POST['license_no'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = trim($_POST['status'] ?? 'Available');
        if ($name === '' || $phone === '' || $vehicle === '') jsonOut(['success'=>false, 'message'=>'Rider name, phone aur vehicle required hain.']);
        if (!in_array($status, ['Available','On Delivery','Break','Inactive'], true)) $status = 'Available';
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE delivery_drivers SET driver_name=?, phone=?, cnic=?, vehicle=?, license_no=?, address=?, status=? WHERE id=? AND client_id=?");
            if (!$stmt) jsonOut(['success'=>false, 'message'=>$conn->error]);
            $stmt->bind_param('sssssssii', $name, $phone, $cnic, $vehicle, $license, $address, $status, $id, $client_id);
        } else {
            $code = trim($_POST['driver_code'] ?? '');
            if ($code === '') $code = nextDriverCode($conn, $client_id);
            $stmt = $conn->prepare("INSERT INTO delivery_drivers (client_id, branch_id, driver_code, driver_name, phone, cnic, vehicle, license_no, address, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) jsonOut(['success'=>false, 'message'=>$conn->error]);
            $stmt->bind_param('iissssssssi', $client_id, $branch_id, $code, $name, $phone, $cnic, $vehicle, $license, $address, $status, $user_id);
        }
        if (!$stmt->execute()) jsonOut(['success'=>false, 'message'=>$stmt->error]);
        $stmt->close();
        jsonOut(['success'=>true, 'message'=>'Rider saved successfully.']);
    }

    if ($action === 'rider_detail') {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $rows = fetchRows($conn, "SELECT * FROM delivery_drivers WHERE id=? AND client_id=?", 'ii', [$id, $client_id]);
        jsonOut(['success'=>!empty($rows), 'rider'=>$rows[0] ?? null]);
    }

    if ($action === 'delete_rider') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) jsonOut(['success'=>false, 'message'=>'Invalid rider.']);
        $linked = (int)scalarVal($conn, "SELECT COUNT(*) FROM delivery_order_assignments WHERE client_id=? AND driver_id=?", 'ii', [$client_id, $id]);
        if (tableExists($conn, 'distribution_routes')) $linked += (int)scalarVal($conn, "SELECT COUNT(*) FROM distribution_routes WHERE client_id=? AND driver_id=?", 'ii', [$client_id, $id]);
        if ($linked > 0) {
            $stmt = $conn->prepare("UPDATE delivery_drivers SET status='Inactive' WHERE id=? AND client_id=?");
            $stmt->bind_param('ii', $id, $client_id);
            $stmt->execute();
            $stmt->close();
            jsonOut(['success'=>true, 'message'=>'Rider linked tha, isliye Inactive kar diya.']);
        }
        $stmt = $conn->prepare("DELETE FROM delivery_drivers WHERE id=? AND client_id=?");
        $stmt->bind_param('ii', $id, $client_id);
        $stmt->execute();
        $stmt->close();
        jsonOut(['success'=>true, 'message'=>'Rider deleted.']);
    }

    if ($action === 'routes') {
        $routes = tableExists($conn, 'distribution_routes') ? fetchRows($conn, "SELECT id, route_code, route_name, area, city, driver_id, driver_name, vehicle_name FROM distribution_routes WHERE client_id=? AND status='Active' ORDER BY route_name ASC", 'i', [$client_id]) : [];
        jsonOut(['success'=>true, 'routes'=>$routes]);
    }

    if ($action === 'route_customers') {
        $routeId = (int)($_GET['route_id'] ?? $_POST['route_id'] ?? 0);
        $search = trim($_GET['search'] ?? $_POST['search'] ?? '');
        $where = ['rc.client_id=?'];
        $types = 'i';
        $params = [$client_id];
        if ($routeId > 0) { $where[] = 'rc.route_id=?'; $types .= 'i'; $params[] = $routeId; }
        $nameCol = firstCol($conn, 'customers', ['name','customer_name','full_name'], 'name');
        $phoneCol = firstCol($conn, 'customers', ['phone','mobile','contact_no','phone_number'], 'phone');
        $addrCol = firstCol($conn, 'customers', ['address','customer_address','location'], 'address');
        $typeCol = colExists($conn, 'customers', 'type') ? 'c.type' : "''";
        if ($search !== '') { $where[] = "(c.`$nameCol` LIKE ? OR c.`$phoneCol` LIKE ? OR c.`$addrCol` LIKE ? OR r.route_name LIKE ?)"; $types .= 'ssss'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like, $like); }
        $sql = "SELECT rc.id, rc.route_id, rc.customer_id, rc.sequence_no, rc.status, rc.created_at,
                       r.route_code, r.route_name, r.area,
                       c.`$nameCol` customer_name, c.`$phoneCol` phone, c.`$addrCol` address, $typeCol customer_type,
                       (SELECT COUNT(*) FROM sales s WHERE s.client_id=rc.client_id AND s.customer_id=rc.customer_id) total_orders
                FROM distribution_route_customers rc
                INNER JOIN distribution_routes r ON r.id=rc.route_id AND r.client_id=rc.client_id
                INNER JOIN customers c ON c.id=rc.customer_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.route_name ASC, rc.sequence_no ASC, rc.id ASC";
        jsonOut(['success'=>true, 'data'=>fetchRows($conn, $sql, $types, $params)]);
    }

    if ($action === 'available_customers') {
        $routeId = (int)($_GET['route_id'] ?? $_POST['route_id'] ?? 0);
        $search = trim($_GET['search'] ?? $_POST['search'] ?? '');
        $nameCol = firstCol($conn, 'customers', ['name','customer_name','full_name'], 'name');
        $phoneCol = firstCol($conn, 'customers', ['phone','mobile','contact_no','phone_number'], 'phone');
        $addrCol = firstCol($conn, 'customers', ['address','customer_address','location'], 'address');
        $typeCol = colExists($conn, 'customers', 'type') ? 'c.type' : "''";
        $where = ['c.client_id=?'];
        $types = 'i';
        $params = [$client_id];
        if ($routeId > 0) { $where[] = 'c.id NOT IN (SELECT customer_id FROM distribution_route_customers WHERE client_id=? AND route_id=?)'; $types .= 'ii'; array_push($params, $client_id, $routeId); }
        if ($search !== '') { $where[] = "(c.`$nameCol` LIKE ? OR c.`$phoneCol` LIKE ? OR c.`$addrCol` LIKE ?)"; $types .= 'sss'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like); }
        $sql = "SELECT c.id, c.`$nameCol` customer_name, c.`$phoneCol` phone, c.`$addrCol` address, $typeCol customer_type FROM customers c WHERE " . implode(' AND ', $where) . " ORDER BY c.`$nameCol` ASC LIMIT 100";
        jsonOut(['success'=>true, 'data'=>fetchRows($conn, $sql, $types, $params)]);
    }

    if ($action === 'assign_customers_to_route') {
        $routeId = (int)($_POST['route_id'] ?? 0);
        $ids = $_POST['customer_ids'] ?? [];
        if (!is_array($ids)) $ids = explode(',', (string)$ids);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($routeId <= 0 || empty($ids)) jsonOut(['success'=>false, 'message'=>'Route aur customers select karein.']);
        $seq = (int)scalarVal($conn, "SELECT COALESCE(MAX(sequence_no),0) FROM distribution_route_customers WHERE client_id=? AND route_id=?", 'ii', [$client_id, $routeId]);
        $stmt = $conn->prepare("INSERT INTO distribution_route_customers (client_id, branch_id, route_id, customer_id, sequence_no, status) VALUES (?, ?, ?, ?, ?, 'Active') ON DUPLICATE KEY UPDATE status='Active', sequence_no=VALUES(sequence_no)");
        if (!$stmt) jsonOut(['success'=>false, 'message'=>$conn->error]);
        foreach ($ids as $cid) {
            $seq++;
            $stmt->bind_param('iiiii', $client_id, $branch_id, $routeId, $cid, $seq);
            if (!$stmt->execute()) { $msg = $stmt->error; $stmt->close(); jsonOut(['success'=>false, 'message'=>$msg]); }
        }
        $stmt->close();
        jsonOut(['success'=>true, 'message'=>count($ids).' customer(s) assigned.']);
    }

    if ($action === 'save_route_customer') {
        $id = (int)($_POST['id'] ?? 0);
        $seq = (int)($_POST['sequence_no'] ?? 0);
        $status = trim($_POST['status'] ?? 'Active');
        if (!in_array($status, ['Active','Inactive'], true)) $status = 'Active';
        $stmt = $conn->prepare("UPDATE distribution_route_customers SET sequence_no=?, status=? WHERE id=? AND client_id=?");
        if (!$stmt) jsonOut(['success'=>false, 'message'=>$conn->error]);
        $stmt->bind_param('isii', $seq, $status, $id, $client_id);
        $stmt->execute();
        $stmt->close();
        jsonOut(['success'=>true, 'message'=>'Customer route updated.']);
    }

    if ($action === 'remove_route_customer') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM distribution_route_customers WHERE id=? AND client_id=?");
        if (!$stmt) jsonOut(['success'=>false, 'message'=>$conn->error]);
        $stmt->bind_param('ii', $id, $client_id);
        $stmt->execute();
        $stmt->close();
        jsonOut(['success'=>true, 'message'=>'Customer removed from route.']);
    }

    if ($action === 'orders') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(5, min(100, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $routeId = (int)($_GET['route_id'] ?? 0);
        $driverId = (int)($_GET['driver_id'] ?? 0);
        $status = trim($_GET['status'] ?? '');
        $date = trim($_GET['date'] ?? '');
        $customerType = trim($_GET['customer_type'] ?? '');
        $where = ['s.client_id=?']; $types = 'i'; $params = [$client_id];
        if ($branch_id > 0 && colExists($conn, 'sales', 'branch_id')) { $where[] = '(s.branch_id=? OR s.branch_id IS NULL OR s.branch_id=0)'; $types .= 'i'; $params[] = $branch_id; }
        $cust = customerNameExpr($conn); $phone = customerPhoneExpr($conn); $inv = invoiceExpr($conn);
        if ($search !== '') { $where[] = "($inv LIKE ? OR $cust LIKE ? OR $phone LIKE ? OR CAST(s.id AS CHAR) LIKE ?)"; $types .= 'ssss'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like, $like); }
        $saleDate = saleDateColumn($conn);
        if ($date !== '' && $saleDate !== 'id') { $where[] = "DATE(s.`$saleDate`)=?"; $types .= 's'; $params[] = $date; }
        if ($routeId > 0) { $where[] = 'a.route_id=?'; $types .= 'i'; $params[] = $routeId; }
        if ($driverId > 0) { $where[] = 'a.driver_id=?'; $types .= 'i'; $params[] = $driverId; }
        if ($customerType !== '' && colExists($conn, 'sales', 'customer_type')) { $where[] = 's.customer_type=?'; $types .= 's'; $params[] = $customerType; }
        if ($status === 'Pending') $where[] = 'a.id IS NULL';
        elseif ($status === 'Assigned') $where[] = "a.status='assigned'";
        elseif ($status === 'Out For Delivery') $where[] = "a.status='out_for_delivery'";
        elseif ($status === 'Delivered') $where[] = "a.status='delivered'";
        elseif ($status === 'Failed') $where[] = "a.status IN ('failed','returned','cancelled')";
        $whereSql = implode(' AND ', $where);
        $totalRows = (int)scalarVal($conn, "SELECT COUNT(*) FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN delivery_order_assignments a ON a.sale_id=s.id AND a.client_id=s.client_id WHERE $whereSql", $types, $params);
        $totalExpr = saleTotalExpr($conn); $payExpr = paymentExpr($conn); $addr = customerAddressExpr($conn);
        $dateSelect = $saleDate !== 'id' ? "s.`$saleDate`" : "NULL";
        $customerTypeSelect = colExists($conn, 'sales', 'customer_type') ? "s.customer_type" : "''";
        $sql = "SELECT s.id sale_id, $inv invoice_no, $dateSelect created_at, s.customer_id, $customerTypeSelect customer_type, $cust customer_name, $phone phone, $addr address, $totalExpr amount, $payExpr payment_status,
                    a.id assignment_id, a.status assignment_status, a.delivery_date, a.delivery_time_slot, a.driver_id, a.route_id, a.vehicle, a.collection_amount, a.failed_reason,
                    d.driver_name, dr.route_name, dr.route_code
                FROM sales s
                LEFT JOIN customers c ON c.id=s.customer_id
                LEFT JOIN delivery_order_assignments a ON a.sale_id=s.id AND a.client_id=s.client_id
                LEFT JOIN delivery_drivers d ON d.id=a.driver_id AND d.client_id=s.client_id
                LEFT JOIN distribution_routes dr ON dr.id=a.route_id AND dr.client_id=s.client_id
                WHERE $whereSql ORDER BY s.id DESC LIMIT ? OFFSET ?";
        $types2 = $types . 'ii'; $params2 = $params; $params2[] = $limit; $params2[] = $offset;
        $orders = fetchRows($conn, $sql, $types2, $params2);
        foreach ($orders as &$o) { $o['qty'] = getOrderQty($conn, (int)$o['sale_id']); $autoRoute = autoRouteForCustomer($conn, $client_id, (int)($o['customer_id'] ?? 0)); $o['auto_route_id'] = $autoRoute['id'] ?? null; $o['auto_route_name'] = $autoRoute['route_name'] ?? ''; $o['auto_driver_id'] = $autoRoute['driver_id'] ?? null; $o['auto_vehicle'] = $autoRoute['vehicle_name'] ?? ''; }
        unset($o);
        jsonOut(['success'=>true, 'orders'=>$orders, 'total'=>$totalRows, 'page'=>$page, 'limit'=>$limit, 'pages'=>max(1, (int)ceil($totalRows/$limit))]);
    }

    if ($action === 'assign_order' || $action === 'bulk_assign') {
        $driverId = (int)($_POST['driver_id'] ?? 0);
        $routeId = ($_POST['route_id'] ?? '') !== '' ? (int)$_POST['route_id'] : null;
        $vehicle = trim($_POST['vehicle'] ?? '');
        $deliveryDate = trim($_POST['delivery_date'] ?? date('Y-m-d'));
        $slot = trim($_POST['time_slot'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $saleIds = $action === 'bulk_assign' ? ($_POST['sale_ids'] ?? []) : [(int)($_POST['sale_id'] ?? 0)];
        if (!is_array($saleIds)) $saleIds = explode(',', (string)$saleIds);
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        if ($driverId <= 0 || empty($saleIds) || $deliveryDate === '') jsonOut(['success'=>false, 'message'=>'Order, rider aur delivery date required hain.']);
        $driver = fetchRows($conn, "SELECT * FROM delivery_drivers WHERE id=? AND client_id=? AND status!='Inactive' LIMIT 1", 'ii', [$driverId, $client_id]);
        if (!$driver) jsonOut(['success'=>false, 'message'=>'Selected rider not found or inactive.']);
        $driver = $driver[0]; if ($vehicle === '') $vehicle = $driver['vehicle'] ?? '';
        if ($routeId !== null && $routeId > 0) { $route = fetchRows($conn, "SELECT * FROM distribution_routes WHERE id=? AND client_id=? AND status='Active' LIMIT 1", 'ii', [$routeId, $client_id]); if (!$route) jsonOut(['success'=>false, 'message'=>'Selected distribution route not found or inactive.']); if ($vehicle === '' && !empty($route[0]['vehicle_name'])) $vehicle = $route[0]['vehicle_name']; }
        $conn->begin_transaction();
        try {
            foreach ($saleIds as $saleId) {
                $sale = fetchRows($conn, "SELECT s.id, s.customer_id, " . saleTotalExpr($conn) . " amount, " . paymentExpr($conn) . " payment_method FROM sales s WHERE s.id=? AND s.client_id=? LIMIT 1", 'ii', [$saleId, $client_id]);
                if (!$sale) throw new Exception('Sale not found: ' . $saleId);
                $sale = $sale[0]; $amount = (float)($sale['amount'] ?? 0); $customerId = isset($sale['customer_id']) ? (int)$sale['customer_id'] : null; $paymentMethod = (string)($sale['payment_method'] ?? 'Cash');
                $stmt = $conn->prepare("INSERT INTO delivery_order_assignments (client_id, branch_id, sale_id, route_id, driver_id, vehicle, delivery_date, delivery_time_slot, status, assigned_by, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'assigned', ?, ?)
                    ON DUPLICATE KEY UPDATE route_id=VALUES(route_id), driver_id=VALUES(driver_id), vehicle=VALUES(vehicle), delivery_date=VALUES(delivery_date), delivery_time_slot=VALUES(delivery_time_slot), status='assigned', assigned_by=VALUES(assigned_by), assigned_at=CURRENT_TIMESTAMP, notes=VALUES(notes)");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param('iiiiisssis', $client_id, $branch_id, $saleId, $routeId, $driverId, $vehicle, $deliveryDate, $slot, $user_id, $notes);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $stmt->close();
                ensureDistributionDeliveryItem($conn, $client_id, $branch_id, $user_id, $saleId, $routeId, $driverId, $driver['driver_name'], $vehicle, $deliveryDate, $amount, $customerId, $paymentMethod);
            }
            $stmt = $conn->prepare("UPDATE delivery_drivers SET status='On Delivery' WHERE id=? AND client_id=?"); if ($stmt) { $stmt->bind_param('ii', $driverId, $client_id); $stmt->execute(); $stmt->close(); }
            $conn->commit(); jsonOut(['success'=>true, 'message'=>count($saleIds) . ' order(s) assigned successfully.']);
        } catch (Throwable $e) { $conn->rollback(); jsonOut(['success'=>false, 'message'=>$e->getMessage()]); }
    }

    if ($action === 'update_status') {
        $saleId = (int)($_POST['sale_id'] ?? 0); $status = trim($_POST['status'] ?? ''); $collection = (float)($_POST['collection_amount'] ?? 0); $reason = trim($_POST['failed_reason'] ?? '');
        $allowed = ['assigned','out_for_delivery','delivered','failed','returned','cancelled'];
        if ($saleId <= 0 || !in_array($status, $allowed, true)) jsonOut(['success'=>false, 'message'=>'Invalid status update.']);
        $deliveredAtSql = $status === 'delivered' ? ', delivered_at=CURRENT_TIMESTAMP' : '';
        $stmt = $conn->prepare("UPDATE delivery_order_assignments SET status=?, collection_amount=?, failed_reason=? $deliveredAtSql WHERE sale_id=? AND client_id=?");
        if (!$stmt) jsonOut(['success'=>false, 'message'=>$conn->error]);
        $stmt->bind_param('sdsii', $status, $collection, $reason, $saleId, $client_id);
        if (!$stmt->execute()) jsonOut(['success'=>false, 'message'=>$stmt->error]);
        $stmt->close();
        if (tableExists($conn, 'distribution_route_delivery_items')) {
            $map = ['assigned'=>'Pending','out_for_delivery'=>'Out For Delivery','delivered'=>'Delivered','failed'=>'Failed','returned'=>'Returned','cancelled'=>'Failed'];
            $distStatus = $map[$status] ?? 'Pending'; $delSql = $status === 'delivered' ? ', delivered_at=CURRENT_TIMESTAMP' : '';
            $stmt = $conn->prepare("UPDATE distribution_route_delivery_items SET delivery_status=?, payment_collected=?, remarks=? $delSql WHERE client_id=? AND sale_id=?");
            if ($stmt) { $stmt->bind_param('sdsii', $distStatus, $collection, $reason, $client_id, $saleId); $stmt->execute(); $stmt->close(); }
            $deliveryIds = fetchRows($conn, "SELECT DISTINCT delivery_id FROM distribution_route_delivery_items WHERE client_id=? AND sale_id=?", 'ii', [$client_id, $saleId]);
            foreach ($deliveryIds as $d) refreshDistributionDelivery($conn, $client_id, (int)$d['delivery_id']);
        }
        jsonOut(['success'=>true, 'message'=>'Delivery status updated.']);
    }

    if ($action === 'order_detail') {
        $saleId = (int)($_GET['sale_id'] ?? 0); if ($saleId <= 0) jsonOut(['success'=>false, 'message'=>'Invalid order.']);
        $inv = invoiceExpr($conn); $total = saleTotalExpr($conn); $pay = paymentExpr($conn); $cust = customerNameExpr($conn); $phone = customerPhoneExpr($conn); $addr = customerAddressExpr($conn); $saleDate = saleDateColumn($conn); $dateSelect = $saleDate !== 'id' ? "s.`$saleDate`" : "NULL";
        $sql = "SELECT s.*, $inv invoice_no, $dateSelect created_at, $cust customer_name, $phone phone, $addr address, $total amount, $pay payment_status,
                    a.status assignment_status, a.delivery_date, a.delivery_time_slot, a.vehicle, a.collection_amount, a.failed_reason,
                    d.driver_name, d.phone driver_phone, dr.route_name, dr.route_code
                FROM sales s
                LEFT JOIN customers c ON c.id=s.customer_id
                LEFT JOIN delivery_order_assignments a ON a.sale_id=s.id AND a.client_id=s.client_id
                LEFT JOIN delivery_drivers d ON d.id=a.driver_id AND d.client_id=s.client_id
                LEFT JOIN distribution_routes dr ON dr.id=a.route_id AND dr.client_id=s.client_id
                WHERE s.id=? AND s.client_id=? LIMIT 1";
        $rows = fetchRows($conn, $sql, 'ii', [$saleId, $client_id]); if (!$rows) jsonOut(['success'=>false, 'message'=>'Order not found.']);
        $order = $rows[0]; $order['qty'] = getOrderQty($conn, $saleId); jsonOut(['success'=>true, 'order'=>$order]);
    }

    jsonOut(['success'=>false, 'message'=>'Invalid action.']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include('partials/head.php'); ?>
    <title>Distribution Operations</title>
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

    .btn-gray {
        background: #687282 !important
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
        background: linear-gradient(135deg, #4ac9d6, #26b4c0)
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

    .module-tabs {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 18px
    }

    .module-tab {
        height: 48px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: #fff;
        color: var(--navy);
        font-weight: 900;
        padding: 0 18px;
        display: flex;
        align-items: center;
        gap: 8px
    }

    .module-tab.active {
        background: var(--navy);
        border-color: var(--navy);
        color: #fff
    }

    .tab-section {
        display: none
    }

    .tab-section.active {
        display: block
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
        content: "⚡ Quick Filters";
        height: 48px;
        min-width: 160px;
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
        min-width: 260px;
        flex: 1
    }

    .delivery-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 16px
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
        min-width: 1120px;
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

    .badge-pending {
        background: #fff7ed;
        color: #ea580c
    }

    .badge-assigned {
        background: #eff6ff;
        color: #2563eb
    }

    .badge-out {
        background: #ecfeff;
        color: #0891b2
    }

    .badge-delivered {
        background: #dcfce7;
        color: #16a34a
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

    .badge-partial {
        background: #fef3c7;
        color: #d97706
    }

    .action-btn {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: transparent;
        border: 0;
        color: #536174
    }

    .action-btn:hover {
        background: #eef4ff;
        color: var(--navy)
    }

    .assign-form {
        padding: 18px
    }

    .form-label-custom {
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
        min-height: 86px;
        padding-top: 12px
    }

    .selected-box {
        display: none;
        background: #eef4ff;
        border: 1px solid #bfd3ff;
        border-radius: 10px;
        color: var(--navy);
        font-size: 13px;
        font-weight: 900;
        padding: 10px 12px;
        margin-bottom: 14px
    }

    .selected-box.show {
        display: block
    }

    .assign-panel .white-card {
        position: sticky;
        top: 98px
    }

    .bulk-bar {
        display: none;
        background: var(--navy);
        color: #fff;
        border-radius: 12px;
        padding: 12px 16px;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px;
        font-weight: 900
    }

    .bulk-bar.show {
        display: flex
    }

    .bulk-count {
        color: var(--gold)
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
        max-width: 760px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .22);
        overflow: hidden
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

    .detail-row {
        display: flex;
        padding: 9px 0;
        border-bottom: 1px solid #f1f3f7;
        font-size: 14px
    }

    .detail-label {
        width: 150px;
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

    .custom-check {
        width: 16px;
        height: 16px;
        accent-color: var(--blue)
    }

    @media(max-width:1200px) {
        .delivery-layout {
            grid-template-columns: 1fr
        }

        .assign-panel .white-card {
            position: static
        }

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
    }
    

/* =====================================================
   DISTRIBUTION_OPERATION.PHP DASHBOARD DARK PURPLE GLASS THEME
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

/* ================= Layout / Sidebar ================= */
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

/* ================= Topbar ================= */
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

/* ================= Content Background ================= */
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

/* ================= Header ================= */
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

/* ================= Buttons ================= */
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

.btn-gray{
    background:rgba(15,23,42,.72) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.btn-main:hover,
.btn-dark-main:hover,
.btn-gray:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
}

/* ================= KPI Cards ================= */
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

/* ================= Module Tabs ================= */
.module-tabs{
    gap:10px !important;
    margin-bottom:16px !important;
}

.module-tab{
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

.module-tab:hover,
.module-tab.active{
    color:#fff !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.42) !important;
    transform:translateY(-1px);
}

/* ================= Glass Panels ================= */
.filter-bar,
.white-card,
.bulk-bar,
.selected-box{
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

.assign-form{
    background:transparent !important;
}

/* ================= Inputs / Selects ================= */
.filter-input,
.filter-select,
.form-control-custom,
.form-control,
.form-select{
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
.form-control-custom::placeholder,
.form-control::placeholder{
    color:#94a3b8 !important;
}

.filter-input:focus,
.filter-select:focus,
.form-control-custom:focus,
.form-control:focus,
.form-select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-select option,
.form-control-custom option,
.form-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.form-label-custom{
    color:#f8fafc !important;
    font-size:13px !important;
    font-weight:900 !important;
}

/* ================= Table ================= */
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

.custom-table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

.order-no,
.customer-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* ================= Badges ================= */
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

/* ================= Actions / Pagination ================= */
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

.custom-check{
    accent-color:#7c3aed !important;
}

/* Toast */
.toast-item{
    background:#0f172a !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}

.toast-item.success{border-left:4px solid #22c55e !important;}
.toast-item.error{border-left:4px solid #ef4444 !important;}
.toast-item.info{border-left:4px solid #7c3aed !important;}

/* Scrollbar */
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
   DISTRIBUTION OPERATION POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

#riderModal.modal-custom,
#assignCustomersModal.modal-custom,
#detailModal.modal-custom,
#statusModal.modal-custom{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
}

#riderModal .modal-box,
#assignCustomersModal .modal-box,
#detailModal .modal-box,
#statusModal .modal-box{
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

#riderModal .modal-box,
#detailModal .modal-box,
#statusModal .modal-box{
    max-width:680px !important;
}

#assignCustomersModal .modal-box{
    max-width:860px !important;
}

#riderModal .modal-head,
#assignCustomersModal .modal-head,
#detailModal .modal-head,
#statusModal .modal-head{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    color:#fff !important;
}

#riderModal .modal-title,
#assignCustomersModal .modal-title,
#detailModal .modal-title,
#statusModal .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#riderModal .modal-title::before,
#assignCustomersModal .modal-title::before,
#detailModal .modal-title::before,
#statusModal .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#riderModal .modal-close,
#assignCustomersModal .modal-close,
#detailModal .modal-close,
#statusModal .modal-close{
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

#riderModal .modal-close::before,
#riderModal .modal-close::after,
#assignCustomersModal .modal-close::before,
#assignCustomersModal .modal-close::after,
#detailModal .modal-close::before,
#detailModal .modal-close::after,
#statusModal .modal-close::before,
#statusModal .modal-close::after{
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

#riderModal .modal-close::before,
#assignCustomersModal .modal-close::before,
#detailModal .modal-close::before,
#statusModal .modal-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#riderModal .modal-close::after,
#assignCustomersModal .modal-close::after,
#detailModal .modal-close::after,
#statusModal .modal-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#riderModal .modal-close:hover,
#assignCustomersModal .modal-close:hover,
#detailModal .modal-close:hover,
#statusModal .modal-close:hover{
    background:#ef4444 !important;
}

#riderModal .modal-body,
#assignCustomersModal .modal-body,
#detailModal .modal-body,
#statusModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#riderModal .modal-foot,
#assignCustomersModal .modal-foot,
#detailModal .modal-foot,
#statusModal .modal-foot{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#riderModal .form-label-custom,
#assignCustomersModal .form-label-custom,
#statusModal .form-label-custom,
#riderModal label,
#assignCustomersModal label,
#statusModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#riderModal .form-control-custom,
#assignCustomersModal .form-control-custom,
#statusModal .form-control-custom,
#riderModal input,
#riderModal select,
#assignCustomersModal input,
#assignCustomersModal select,
#statusModal input,
#statusModal select,
#statusModal textarea{
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

#statusModal textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#riderModal input[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

#riderModal input::placeholder,
#assignCustomersModal input::placeholder,
#statusModal textarea::placeholder{
    color:#94a3b8 !important;
}

#riderModal .form-control-custom:focus,
#assignCustomersModal .form-control-custom:focus,
#statusModal .form-control-custom:focus,
#riderModal input:focus,
#riderModal select:focus,
#assignCustomersModal input:focus,
#assignCustomersModal select:focus,
#statusModal input:focus,
#statusModal select:focus,
#statusModal textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Assign customers popup table */
#assignCustomersModal .table-wrap{
    background:rgba(15,23,42,.35) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    overflow:auto !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#assignCustomersModal table.custom-table,
#assignCustomersModal .custom-table tbody,
#assignCustomersModal .custom-table tbody tr{
    background:transparent !important;
}

#assignCustomersModal .custom-table th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#assignCustomersModal .custom-table td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

#assignCustomersModal .custom-table tr:hover td{
    background:rgba(124,58,237,.12) !important;
    color:#fff !important;
}

/* Detail modal boxes */
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
#riderModal .btn-light,
#assignCustomersModal .btn-light,
#detailModal .btn-light,
#statusModal .btn-light{
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

#riderModal .btn-dark-main,
#assignCustomersModal .btn-dark-main,
#statusModal .btn-dark-main{
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

#riderModal .btn-light:hover,
#assignCustomersModal .btn-light:hover,
#detailModal .btn-light:hover,
#statusModal .btn-light:hover,
#riderModal .btn-dark-main:hover,
#assignCustomersModal .btn-dark-main:hover,
#statusModal .btn-dark-main:hover{
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
                        <h1 class="page-title"><i class="bi bi-truck-front me-2"></i>Distribution Operations</h1>
                        <div class="page-sub">Delivery Assign + Rider Management + Customer Route Assign in one page
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap"><button class="btn-main"
                            onclick="autoSelectRouteForVisibleOrders()"><i class="bi bi-magic me-1"></i>Auto Route
                            Suggest</button><button class="btn-dark-main" onclick="refreshActiveTab()"><i
                                class="bi bi-arrow-clockwise me-1"></i>Refresh</button></div>
                </div>
                <div class="kpi-grid">
                    <div class="kpi-card"><i class="bi bi-hourglass-split kpi-icon"></i>
                        <div class="kpi-label">Pending<br>Orders</div>
                        <div class="kpi-value" id="kPending">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-person-check kpi-icon"></i>
                        <div class="kpi-label">Assigned<br>Orders</div>
                        <div class="kpi-value" id="kAssigned">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-truck kpi-icon"></i>
                        <div class="kpi-label">Out For<br>Delivery</div>
                        <div class="kpi-value" id="kOut">0</div>
                    </div>
                    <div class="kpi-card"><i class="bi bi-people kpi-icon"></i>
                        <div class="kpi-label">Riders /<br>Customers</div>
                        <div class="kpi-value"><span id="kDrivers">0</span>/<span id="kRouteCustomers">0</span></div>
                    </div>
                </div>
                <div class="module-tabs"><button class="module-tab active" data-tab="assign"
                        onclick="switchMainTab('assign')"><i class="bi bi-send-check"></i>Delivery
                        Assign</button><button class="module-tab" data-tab="riders" onclick="switchMainTab('riders')"><i
                            class="bi bi-person-badge"></i>Rider Management</button><button class="module-tab"
                        data-tab="customers" onclick="switchMainTab('customers')"><i
                            class="bi bi-signpost-split"></i>Customer Route Assign</button></div>

                <section id="tabAssign" class="tab-section active">
                    <div class="filter-bar"><input id="fSearch" class="filter-input"
                            placeholder="Search invoice, customer, phone, order ID"><select id="fStatus"
                            class="filter-select">
                            <option value="">All Status</option>
                            <option>Pending</option>
                            <option>Assigned</option>
                            <option>Out For Delivery</option>
                            <option>Delivered</option>
                            <option>Failed</option>
                        </select><select id="fRoute" class="filter-select">
                            <option value="">All Routes</option>
                        </select><select id="fDriver" class="filter-select">
                            <option value="">All Riders</option>
                        </select><select id="fCustomerType" class="filter-select">
                            <option value="">All Customers</option>
                            <option>Walk-in</option>
                            <option>Retailer</option>
                            <option>Wholesaler</option>
                        </select><input type="date" id="fDate" class="filter-select"><button class="btn-dark-main"
                            onclick="loadOrders(1)"><i class="bi bi-funnel me-1"></i>Filter</button><button
                            class="btn-dark-main btn-gray" onclick="resetFilters()">Reset</button></div>
                    <div id="bulkBar" class="bulk-bar"><span><span id="bulkCount" class="bulk-count">0</span> order(s)
                            selected</span><button class="btn btn-sm btn-light" onclick="useBulkSelection()">Assign
                            Selected</button><button class="btn btn-sm btn-outline-light ms-auto"
                            onclick="clearSelection()">Cancel</button></div>
                    <div class="delivery-layout">
                        <section class="white-card">
                            <div class="card-head">
                                <div class="card-title"><i class="bi bi-list-check"></i> POS Distribution Orders</div>
                                <div class="small-muted" id="orderCount">0 orders</div>
                            </div>
                            <div class="table-wrap">
                                <table class="custom-table">
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" class="custom-check" id="selectAll"
                                                    onchange="toggleSelectAll(this)"></th>
                                            <th>Order</th>
                                            <th>Customer</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Payment</th>
                                            <th>Route</th>
                                            <th>Rider</th>
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
                        <aside class="assign-panel">
                            <div class="white-card">
                                <div class="card-head">
                                    <div class="card-title"><i class="bi bi-send-check"></i> Assign Delivery</div>
                                </div>
                                <div class="assign-form">
                                    <div id="selectedBox" class="selected-box">No order selected</div><input
                                        type="hidden" id="selectedSaleId"><input type="hidden" id="selectedSaleIds">
                                    <div class="mb-3"><label class="form-label-custom">Distribution Route</label><select
                                            id="assignRoute" class="form-control-custom">
                                            <option value="">Select Route</option>
                                        </select></div>
                                    <div class="mb-3"><label class="form-label-custom">Rider</label><select
                                            id="assignDriver" class="form-control-custom">
                                            <option value="">Select Rider</option>
                                        </select></div>
                                    <div class="mb-3"><label class="form-label-custom">Vehicle</label><input
                                            id="assignVehicle" class="form-control-custom"
                                            placeholder="Auto from rider / route"></div>
                                    <div class="mb-3"><label class="form-label-custom">Delivery Date</label><input
                                            type="date" id="assignDate" class="form-control-custom"
                                            value="<?= date('Y-m-d') ?>"></div>
                                    <div class="mb-3"><label class="form-label-custom">Time Slot</label><input
                                            id="assignSlot" class="form-control-custom"
                                            placeholder="Example: 2PM - 5PM"></div>
                                    <div class="mb-3"><label class="form-label-custom">Notes</label><textarea
                                            id="assignNotes" class="form-control-custom"></textarea></div><button
                                        class="btn-dark-main w-100" onclick="assignSelected()"><i
                                            class="bi bi-check2-circle me-1"></i>Assign Delivery</button>
                                </div>
                            </div>
                        </aside>
                    </div>
                </section>

                <section id="tabRiders" class="tab-section">
                    <div class="filter-bar"><input id="riderSearch" class="filter-input"
                            placeholder="Search rider code, name, phone, vehicle"><select id="riderStatus"
                            class="filter-select">
                            <option value="">Active Riders</option>
                            <option>Available</option>
                            <option>On Delivery</option>
                            <option>Break</option>
                            <option>Inactive</option>
                        </select><button class="btn-dark-main" onclick="loadRiders()"><i
                                class="bi bi-search me-1"></i>Search</button><button class="btn-main"
                            onclick="openRiderModal()"><i class="bi bi-plus-lg me-1"></i>Add Rider</button></div>
                    <div class="white-card">
                        <div class="card-head">
                            <div class="card-title"><i class="bi bi-person-badge"></i>Riders List</div>
                            <div class="small-muted" id="riderCount">0 riders</div>
                        </div>
                        <div class="table-wrap">
                            <table class="custom-table">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Vehicle</th>
                                        <th>CNIC</th>
                                        <th>License</th>
                                        <th>Today Orders</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="ridersBody"></tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section id="tabCustomers" class="tab-section">
                    <div class="filter-bar"><select id="custRouteFilter" class="filter-select">
                            <option value="">All Routes</option>
                        </select><input id="custSearch" class="filter-input"
                            placeholder="Search customer, phone, address, route"><button class="btn-dark-main"
                            onclick="loadRouteCustomers()"><i class="bi bi-search me-1"></i>Search</button><button
                            class="btn-main" onclick="openAssignCustomersModal()"><i
                                class="bi bi-plus-lg me-1"></i>Assign Customers</button></div>
                    <div class="white-card">
                        <div class="card-head">
                            <div class="card-title"><i class="bi bi-people"></i>Customer Route Assignments</div>
                            <div class="small-muted" id="routeCustomerCount">0 assignments</div>
                        </div>
                        <div class="table-wrap">
                            <table class="custom-table">
                                <thead>
                                    <tr>
                                        <th>Seq</th>
                                        <th>Route</th>
                                        <th>Customer</th>
                                        <th>Phone</th>
                                        <th>Type</th>
                                        <th>Address</th>
                                        <th>Total Orders</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="routeCustomersBody"></tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <div class="modal-custom" id="riderModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title"><i class="bi bi-person-badge me-1"></i><span id="riderModalTitle">Add
                        Rider</span></div><button class="modal-close" onclick="closeModal('riderModal')">×</button>
            </div>
            <div class="modal-body"><input type="hidden" id="riderId">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label-custom">Code</label><input id="riderCode"
                            class="form-control-custom" readonly></div>
                    <div class="col-md-4"><label class="form-label-custom">Name *</label><input id="riderName"
                            class="form-control-custom"></div>
                    <div class="col-md-4"><label class="form-label-custom">Phone *</label><input id="riderPhone"
                            class="form-control-custom"></div>
                    <div class="col-md-4"><label class="form-label-custom">CNIC</label><input id="riderCnic"
                            class="form-control-custom"></div>
                    <div class="col-md-4"><label class="form-label-custom">Vehicle *</label><input id="riderVehicle"
                            class="form-control-custom"></div>
                    <div class="col-md-4"><label class="form-label-custom">License No</label><input id="riderLicense"
                            class="form-control-custom"></div>
                    <div class="col-md-4"><label class="form-label-custom">Status</label><select id="riderFormStatus"
                            class="form-control-custom">
                            <option>Available</option>
                            <option>On Delivery</option>
                            <option>Break</option>
                            <option>Inactive</option>
                        </select></div>
                    <div class="col-md-8"><label class="form-label-custom">Address</label><input id="riderAddress"
                            class="form-control-custom"></div>
                </div>
            </div>
            <div class="modal-foot"><button class="btn btn-light"
                    onclick="closeModal('riderModal')">Cancel</button><button class="btn-dark-main"
                    onclick="saveRider()">Save Rider</button></div>
        </div>
    </div>
    <div class="modal-custom" id="assignCustomersModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title"><i class="bi bi-person-plus me-1"></i>Assign Customers to Route</div><button
                    class="modal-close" onclick="closeModal('assignCustomersModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-5"><label class="form-label-custom">Route *</label><select id="assignCustRoute"
                            class="form-control-custom"></select></div>
                    <div class="col-md-7"><label class="form-label-custom">Search customers</label>
                        <div class="d-flex gap-2"><input id="availableCustomerSearch" class="form-control-custom"
                                placeholder="Name / phone / address"><button class="btn-dark-main"
                                onclick="loadAvailableCustomers()">Search</button></div>
                    </div>
                </div>
                <div class="table-wrap mt-3">
                    <table class="custom-table" style="min-width:700px">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="custom-check"
                                        onclick="document.querySelectorAll('.available-customer-check').forEach(c=>c.checked=this.checked)">
                                </th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Type</th>
                                <th>Address</th>
                            </tr>
                        </thead>
                        <tbody id="availableCustomersBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-foot"><button class="btn btn-light"
                    onclick="closeModal('assignCustomersModal')">Cancel</button><button class="btn-dark-main"
                    onclick="saveAssignedCustomers()">Assign Selected</button></div>
        </div>
    </div>
    <div class="modal-custom" id="detailModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title"><i class="bi bi-receipt-cutoff me-1"></i>Order Detail</div><button
                    class="modal-close" onclick="closeModal('detailModal')">×</button>
            </div>
            <div class="modal-body" id="detailBody"></div>
            <div class="modal-foot"><button class="btn btn-light" onclick="closeModal('detailModal')">Close</button>
            </div>
        </div>
    </div>
    <div class="modal-custom" id="statusModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title"><i class="bi bi-pencil-square me-1"></i>Update Delivery Status</div><button
                    class="modal-close" onclick="closeModal('statusModal')">×</button>
            </div>
            <div class="modal-body"><input type="hidden" id="statusSaleId">
                <div class="mb-3"><label class="form-label-custom">Status</label><select id="statusValue"
                        class="form-control-custom">
                        <option value="out_for_delivery">Out For Delivery</option>
                        <option value="delivered">Delivered</option>
                        <option value="failed">Failed</option>
                        <option value="returned">Returned</option>
                        <option value="cancelled">Cancelled</option>
                    </select></div>
                <div class="mb-3"><label class="form-label-custom">Collection Amount</label><input type="number"
                        step="0.01" id="collectionAmount" class="form-control-custom" value="0"></div>
                <div class="mb-3"><label class="form-label-custom">Reason / Remarks</label><textarea id="failedReason"
                        class="form-control-custom"></textarea></div>
            </div>
            <div class="modal-foot"><button class="btn btn-light"
                    onclick="closeModal('statusModal')">Cancel</button><button class="btn-dark-main"
                    onclick="saveStatus()">Save Status</button></div>
        </div>
    </div>
    <div id="toast-container"></div>

    <script>
    const pageUrl = '<?= basename(__FILE__) ?>';
    let currentTab = 'assign',
        currentPage = 1,
        routes = [],
        drivers = [],
        visibleOrders = [];

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

    function get(action, params = {}) {
        const u = new URL(pageUrl, window.location.href);
        u.searchParams.set('ajax', '1');
        u.searchParams.set('action', action);
        Object.keys(params).forEach(k => u.searchParams.set(k, params[k]));
        return fetch(u).then(r => r.json());
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

    function switchMainTab(tab) {
        currentTab = tab;
        document.querySelectorAll('.module-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
        document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
        refreshActiveTab();
    }

    function refreshActiveTab() {
        loadStats();
        if (currentTab === 'assign') loadOrders(currentPage);
        if (currentTab === 'riders') loadRiders();
        if (currentTab === 'customers') loadRouteCustomers();
    }

    function statusBadge(s) {
        s = (s || 'Pending').toString();
        let cls = 'badge-pending',
            label = 'Pending';
        if (s === 'assigned') {
            cls = 'badge-assigned';
            label = 'Assigned'
        } else if (s === 'out_for_delivery') {
            cls = 'badge-out';
            label = 'Out For Delivery'
        } else if (s === 'delivered') {
            cls = 'badge-delivered';
            label = 'Delivered'
        } else if (['failed', 'returned', 'cancelled'].includes(s)) {
            cls = 'badge-failed';
            label = s.charAt(0).toUpperCase() + s.slice(1)
        }
        return `<span class="badge-custom ${cls}">${label}</span>`;
    }

    function paymentBadge(p) {
        p = (p || 'Cash').toString();
        let l = p.toLowerCase();
        let cls = (l.includes('paid') || l.includes('cash')) ? 'badge-paid' : (l.includes('partial') ? 'badge-partial' :
            'badge-unpaid');
        return `<span class="badge-custom ${cls}">${esc(p)}</span>`;
    }

    function loadStats() {
        get('stats').then(r => {
            if (!r.success) return;
            const s = r.stats;
            kPending.textContent = s.pending;
            kAssigned.textContent = s.assigned;
            kOut.textContent = s.ofd;
            kDrivers.textContent = s.drivers;
            kRouteCustomers.textContent = s.routeCustomers;
        });
    }

    function loadDrivers() {
        get('drivers').then(r => {
            drivers = r.drivers || [];
            let opts = '<option value="">All Riders</option>',
                opts2 = '<option value="">Select Rider</option>';
            drivers.forEach(d => {
                opts += `<option value="${d.id}">${esc(d.driver_name)}</option>`;
                opts2 +=
                    `<option value="${d.id}" data-vehicle="${esc(d.vehicle)}">${esc(d.driver_name)} - ${esc(d.vehicle||'')}</option>`;
            });
            fDriver.innerHTML = opts;
            assignDriver.innerHTML = opts2;
        });
    }

    function loadRoutes() {
        get('routes').then(r => {
            routes = r.routes || [];
            let opts = '<option value="">All Routes</option>',
                opts2 = '<option value="">Select Route</option>';
            routes.forEach(rt => {
                opts +=
                    `<option value="${rt.id}">${esc(rt.route_name)}${rt.area?' - '+esc(rt.area):''}</option>`;
                opts2 +=
                    `<option value="${rt.id}" data-driver="${esc(rt.driver_id||'')}" data-vehicle="${esc(rt.vehicle_name||'')}">${esc(rt.route_name)}${rt.area?' - '+esc(rt.area):''}</option>`;
            });
            fRoute.innerHTML = opts;
            assignRoute.innerHTML = opts2;
            custRouteFilter.innerHTML = opts;
            assignCustRoute.innerHTML = opts2;
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
            customer_type: fCustomerType.value,
            date: fDate.value
        }).then(r => {
            if (!r.success) {
                toast(r.message || 'Orders load failed', 'error');
                return;
            }
            visibleOrders = r.orders || [];
            let html = '';
            visibleOrders.forEach(o => {
                let route = o.route_name || o.auto_route_name || '-';
                let routeHint = (!o.route_name && o.auto_route_name) ?
                    `<div class="small-muted">Suggested: ${esc(o.auto_route_name)}</div>` : '';
                html +=
                    `<tr><td><input type="checkbox" class="custom-check row-check" value="${o.sale_id}" onchange="updateBulk()"></td><td><div class="order-no">#${esc(o.invoice_no||o.sale_id)}</div><div class="small-muted">${esc(o.created_at||'')}</div></td><td><div class="customer-name">${esc(o.customer_name)}</div><div class="small-muted">${esc(o.phone||'')}${o.address?' • '+esc(o.address):''}</div></td><td>${esc(o.customer_type||'-')}</td><td><b>${money(o.amount)}</b><div class="small-muted">Qty: ${Number(o.qty||0)}</div></td><td>${paymentBadge(o.payment_status)}</td><td>${esc(route)}${routeHint}</td><td>${esc(o.driver_name||'-')}</td><td>${esc(o.delivery_date||'-')}<div class="small-muted">${esc(o.delivery_time_slot||'')}</div></td><td>${statusBadge(o.assignment_status||'Pending')}</td><td><button class="action-btn" onclick="selectOrder(${o.sale_id})"><i class="bi bi-send"></i></button><button class="action-btn" onclick="viewOrder(${o.sale_id})"><i class="bi bi-eye"></i></button>${o.assignment_id?`<button class="action-btn" onclick="openStatus(${o.sale_id},'${esc(o.assignment_status)}',${Number(o.amount||0)})"><i class="bi bi-pencil-square"></i></button>`:''}</td></tr>`;
            });
            ordersBody.innerHTML = html ||
                '<tr><td colspan="11" class="text-center py-4">No orders found</td></tr>';
            orderCount.textContent = (r.total || 0) + ' orders';
            paginationInfo.textContent = `Page ${r.page} of ${r.pages}`;
            renderPagination(r.page, r.pages);
            loadStats();
            clearSelection(false);
        });
    }

    function renderPagination(page, pages) {
        let html = `<button class="page-btn" ${page<=1?'disabled':''} onclick="loadOrders(${page-1})">‹</button>`;
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
        fCustomerType.value = '';
        fDate.value = '';
        loadOrders(1);
    }

    function selectedIds() {
        return [...document.querySelectorAll('.row-check:checked')].map(c => c.value);
    }

    function toggleSelectAll(el) {
        document.querySelectorAll('.row-check').forEach(c => c.checked = el.checked);
        updateBulk();
    }

    function updateBulk() {
        let n = selectedIds().length;
        bulkBar.classList.toggle('show', n > 0);
        bulkCount.textContent = n;
    }

    function clearSelection(clearBox = true) {
        document.querySelectorAll('.row-check').forEach(c => c.checked = false);
        if (selectAll) selectAll.checked = false;
        updateBulk();
        if (clearBox) {
            selectedSaleId.value = '';
            selectedSaleIds.value = '';
            selectedBox.classList.remove('show');
            selectedBox.innerHTML = 'No order selected';
        }
    }

    function selectOrder(saleId) {
        const o = visibleOrders.find(x => Number(x.sale_id) === Number(saleId));
        selectedSaleId.value = saleId;
        selectedSaleIds.value = '';
        selectedBox.classList.add('show');
        selectedBox.innerHTML = `Selected Order: <b>#${esc(o?.invoice_no||saleId)}</b> - ${esc(o?.customer_name||'')}`;
        if (o?.auto_route_id && !o.route_id) {
            assignRoute.value = o.auto_route_id;
            applyRouteDefaults();
        } else if (o?.route_id) {
            assignRoute.value = o.route_id;
            applyRouteDefaults();
        }
        if (o?.driver_id) assignDriver.value = o.driver_id;
        if (o?.vehicle) assignVehicle.value = o.vehicle;
    }

    function useBulkSelection() {
        let ids = selectedIds();
        if (!ids.length) {
            toast('Please select orders first', 'error');
            return;
        }
        selectedSaleId.value = '';
        selectedSaleIds.value = ids.join(',');
        selectedBox.classList.add('show');
        selectedBox.innerHTML = `Bulk Assign: <b>${ids.length}</b> order(s) selected`;
    }

    function assignSelected() {
        let ids = selectedSaleIds.value ? selectedSaleIds.value.split(',') : [];
        let single = selectedSaleId.value;
        if (!single && !ids.length) {
            toast('Please select at least one order', 'error');
            return;
        }
        let action = ids.length ? 'bulk_assign' : 'assign_order';
        let data = {
            route_id: assignRoute.value,
            driver_id: assignDriver.value,
            vehicle: assignVehicle.value,
            delivery_date: assignDate.value,
            time_slot: assignSlot.value,
            notes: assignNotes.value
        };
        if (ids.length) data.sale_ids = ids;
        else data.sale_id = single;
        post(action, data).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            if (r.success) {
                clearSelection();
                loadOrders(currentPage);
            }
        });
    }

    function applyRouteDefaults() {
        let opt = assignRoute.options[assignRoute.selectedIndex];
        if (!opt) return;
        let d = opt.dataset.driver || '',
            v = opt.dataset.vehicle || '';
        if (d && !assignDriver.value) assignDriver.value = d;
        if (v && !assignVehicle.value) assignVehicle.value = v;
    }

    function applyDriverDefaults() {
        let opt = assignDriver.options[assignDriver.selectedIndex];
        if (opt && opt.dataset.vehicle && !assignVehicle.value) assignVehicle.value = opt.dataset.vehicle;
    }
    assignRoute.addEventListener('change', applyRouteDefaults);
    assignDriver.addEventListener('change', applyDriverDefaults);

    function autoSelectRouteForVisibleOrders() {
        let target = visibleOrders.find(o => o.auto_route_id && !o.route_id);
        if (!target) {
            toast('Visible orders me auto route suggestion nahi mila', 'info');
            return;
        }
        selectOrder(target.sale_id);
        toast('Suggested route selected for order #' + (target.invoice_no || target.sale_id), 'success');
    }

    function openStatus(id, status, amount) {
        statusSaleId.value = id;
        statusValue.value = status === 'assigned' ? 'out_for_delivery' : (status || 'out_for_delivery');
        collectionAmount.value = (status === 'delivered' ? amount : 0);
        failedReason.value = '';
        openModal('statusModal');
    }

    function saveStatus() {
        post('update_status', {
            sale_id: statusSaleId.value,
            status: statusValue.value,
            collection_amount: collectionAmount.value,
            failed_reason: failedReason.value
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            if (r.success) {
                closeModal('statusModal');
                loadOrders(currentPage);
            }
        });
    }

    function viewOrder(id) {
        get('order_detail', {
            sale_id: id
        }).then(r => {
            if (!r.success) {
                toast(r.message, 'error');
                return;
            }
            let o = r.order;
            detailBody.innerHTML =
                `<div class="detail-row"><div class="detail-label">Invoice</div><div class="detail-value">#${esc(o.invoice_no)}</div></div><div class="detail-row"><div class="detail-label">Customer</div><div class="detail-value">${esc(o.customer_name)} (${esc(o.phone||'')})</div></div><div class="detail-row"><div class="detail-label">Address</div><div class="detail-value">${esc(o.address||'-')}</div></div><div class="detail-row"><div class="detail-label">Amount</div><div class="detail-value">${money(o.amount)}</div></div><div class="detail-row"><div class="detail-label">Route</div><div class="detail-value">${esc(o.route_name||'-')}</div></div><div class="detail-row"><div class="detail-label">Rider</div><div class="detail-value">${esc(o.driver_name||'-')}</div></div>`;
            openModal('detailModal');
        });
    }

    function riderStatusBadge(s) {
        let cls = s === 'Available' ? 'badge-delivered' : (s === 'On Delivery' ? 'badge-assigned' : (s === 'Break' ?
            'badge-pending' : 'badge-failed'));
        return `<span class="badge-custom ${cls}">${esc(s)}</span>`;
    }

    function loadRiders() {
        get('get_riders', {
            search: riderSearch.value,
            status: riderStatus.value
        }).then(r => {
            let data = r.riders || r.drivers || [];
            riderCount.textContent = data.length + ' riders';
            let html = '';
            data.forEach(d => {
                html +=
                    `<tr><td><b>${esc(d.driver_code)}</b></td><td><div class="customer-name">${esc(d.driver_name)}</div><div class="small-muted">${esc(d.address||'')}</div></td><td>${esc(d.phone)}</td><td>${esc(d.vehicle)}</td><td>${esc(d.cnic||'-')}</td><td>${esc(d.license_no||'-')}</td><td>${Number(d.today_total||0)} <span class="small-muted">(${Number(d.today_pending||0)} pending)</span></td><td>${riderStatusBadge(d.status)}</td><td><button class="action-btn" onclick='editRider(${JSON.stringify(d).replace(/'/g,"&#39;")})'><i class="bi bi-pencil"></i></button><button class="action-btn" onclick="deleteRider(${d.id})"><i class="bi bi-trash"></i></button></td></tr>`;
            });
            ridersBody.innerHTML = html ||
                '<tr><td colspan="9" class="text-center py-4">No riders found</td></tr>';
            loadDrivers();
            loadStats();
        });
    }

    function openRiderModal() {
        riderId.value = '';
        riderModalTitle.textContent = 'Add Rider';
        ['riderName', 'riderPhone', 'riderCnic', 'riderVehicle', 'riderLicense', 'riderAddress'].forEach(id => document
            .getElementById(id).value = '');
        riderFormStatus.value = 'Available';
        get('driver_code').then(r => {
            riderCode.value = r.code || '';
            openModal('riderModal');
        });
    }

    function editRider(d) {
        riderId.value = d.id;
        riderCode.value = d.driver_code || '';
        riderName.value = d.driver_name || '';
        riderPhone.value = d.phone || '';
        riderCnic.value = d.cnic || '';
        riderVehicle.value = d.vehicle || '';
        riderLicense.value = d.license_no || '';
        riderAddress.value = d.address || '';
        riderFormStatus.value = d.status || 'Available';
        riderModalTitle.textContent = 'Edit Rider';
        openModal('riderModal');
    }

    function saveRider() {
        post('save_rider', {
            id: riderId.value,
            driver_code: riderCode.value,
            driver_name: riderName.value,
            phone: riderPhone.value,
            cnic: riderCnic.value,
            vehicle: riderVehicle.value,
            license_no: riderLicense.value,
            address: riderAddress.value,
            status: riderFormStatus.value
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            if (r.success) {
                closeModal('riderModal');
                loadRiders();
            }
        });
    }

    function deleteRider(id) {
        if (!confirm('Delete / inactive this rider?')) return;
        post('delete_rider', {
            id
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            loadRiders();
        });
    }

    function loadRouteCustomers() {
        get('route_customers', {
            route_id: custRouteFilter.value,
            search: custSearch.value
        }).then(r => {
            let data = r.data || [];
            routeCustomerCount.textContent = data.length + ' assignments';
            let html = '';
            data.forEach(x => {
                html +=
                    `<tr><td><input class="form-control form-control-sm" style="width:70px" value="${x.sequence_no}" onchange="saveRouteCustomer(${x.id},this.value,'${esc(x.status)}')"></td><td><b>${esc(x.route_name)}</b><div class="small-muted">${esc(x.route_code||'')} ${x.area?' • '+esc(x.area):''}</div></td><td><div class="customer-name">${esc(x.customer_name)}</div></td><td>${esc(x.phone||'')}</td><td>${esc(x.customer_type||'-')}</td><td>${esc(x.address||'-')}</td><td>${Number(x.total_orders||0)}</td><td>${x.status==='Active'?'<span class="badge-custom badge-delivered">Active</span>':'<span class="badge-custom badge-failed">Inactive</span>'}</td><td><button class="action-btn" onclick="saveRouteCustomer(${x.id},${Number(x.sequence_no||0)},'${x.status==='Active'?'Inactive':'Active'}')"><i class="bi bi-toggle-on"></i></button><button class="action-btn" onclick="removeRouteCustomer(${x.id})"><i class="bi bi-trash"></i></button></td></tr>`;
            });
            routeCustomersBody.innerHTML = html ||
                '<tr><td colspan="9" class="text-center py-4">No customer route assignments found</td></tr>';
            loadStats();
        });
    }

    function openAssignCustomersModal() {
        if (!assignCustRoute.value && custRouteFilter.value) assignCustRoute.value = custRouteFilter.value;
        availableCustomerSearch.value = '';
        availableCustomersBody.innerHTML =
            '<tr><td colspan="5" class="text-center py-4">Route select karke search karein</td></tr>';
        openModal('assignCustomersModal');
    }

    function loadAvailableCustomers() {
        if (!assignCustRoute.value) {
            toast('Route select karein', 'error');
            return;
        }
        get('available_customers', {
            route_id: assignCustRoute.value,
            search: availableCustomerSearch.value
        }).then(r => {
            let html = '';
            (r.data || []).forEach(c => {
                html +=
                    `<tr><td><input type="checkbox" class="custom-check available-customer-check" value="${c.id}"></td><td><b>${esc(c.customer_name)}</b></td><td>${esc(c.phone||'')}</td><td>${esc(c.customer_type||'-')}</td><td>${esc(c.address||'-')}</td></tr>`;
            });
            availableCustomersBody.innerHTML = html ||
                '<tr><td colspan="5" class="text-center py-4">No available customers</td></tr>';
        });
    }

    function saveAssignedCustomers() {
        let ids = [...document.querySelectorAll('.available-customer-check:checked')].map(c => c.value);
        post('assign_customers_to_route', {
            route_id: assignCustRoute.value,
            customer_ids: ids
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            if (r.success) {
                closeModal('assignCustomersModal');
                custRouteFilter.value = assignCustRoute.value;
                loadRouteCustomers();
            }
        });
    }

    function saveRouteCustomer(id, seq, status) {
        post('save_route_customer', {
            id,
            sequence_no: seq,
            status
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            loadRouteCustomers();
        });
    }

    function removeRouteCustomer(id) {
        if (!confirm('Remove customer from route?')) return;
        post('remove_route_customer', {
            id
        }).then(r => {
            toast(r.message, r.success ? 'success' : 'error');
            loadRouteCustomers();
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