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
    die('Client not found in session!');
}

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_out($arr) {
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
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

function table_has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    return (int)qval($conn, $sql, 'ss', [$table, $column]) > 0;
}

function sales_date_column(mysqli $conn): string {
    foreach (['created_at', 'sale_date', 'date', 'order_date'] as $col) {
        if (table_has_column($conn, 'sales', $col)) return $col;
    }
    return 'id';
}

function nextRouteCode(mysqli $conn, int $client_id): string {
    $n = (int)qval($conn, "SELECT COUNT(*) + 1 FROM distribution_routes WHERE client_id = ?", 'i', [$client_id]);
    return 'DR-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function refreshDistributionTotals(mysqli $conn, int $delivery_id, int $client_id): void {
    $stats = rows($conn, "
        SELECT
            COUNT(*) AS total_orders,
            SUM(delivery_status IN ('Pending','Out For Delivery','Partial')) AS pending_orders,
            SUM(delivery_status = 'Delivered') AS delivered_orders,
            SUM(delivery_status = 'Failed') AS failed_orders,
            SUM(delivery_status = 'Returned') AS returned_orders,
            COALESCE(SUM(payment_collected),0) AS total_collection
        FROM distribution_route_delivery_items
        WHERE delivery_id = ? AND client_id = ?
    ", 'ii', [$delivery_id, $client_id]);

    $s = $stats[0] ?? [
        'total_orders' => 0,
        'pending_orders' => 0,
        'delivered_orders' => 0,
        'failed_orders' => 0,
        'returned_orders' => 0,
        'total_collection' => 0,
    ];

    $status = 'Pending';
    if ((int)$s['total_orders'] > 0 && (int)$s['pending_orders'] === 0) {
        $status = 'Completed';
    } elseif ((int)$s['delivered_orders'] > 0 || (int)$s['failed_orders'] > 0 || (int)$s['returned_orders'] > 0) {
        $status = 'In Progress';
    }

    $stmt = $conn->prepare("UPDATE distribution_route_deliveries
        SET total_orders = ?, pending_orders = ?, delivered_orders = ?, failed_orders = ?, returned_orders = ?, total_collection = ?, status = ?, updated_at = NOW()
        WHERE id = ? AND client_id = ?");
    if ($stmt) {
        $stmt->bind_param(
            'iiiiidsii',
            $s['total_orders'],
            $s['pending_orders'],
            $s['delivered_orders'],
            $s['failed_orders'],
            $s['returned_orders'],
            $s['total_collection'],
            $status,
            $delivery_id,
            $client_id
        );
        $stmt->execute();
        $stmt->close();
    }
}

/* ================= AJAX BACKEND ================= */
if (isset($_POST['ajax']) || isset($_GET['ajax'])) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'route_code') {
        json_out(['success' => true, 'code' => nextRouteCode($conn, $client_id)]);
    }

    if ($action === 'stats') {
        $today = date('Y-m-d');
        $stats = [
            'routes'     => (int)qval($conn, "SELECT COUNT(*) FROM distribution_routes WHERE client_id = ?", 'i', [$client_id]),
            'active'     => (int)qval($conn, "SELECT COUNT(*) FROM distribution_routes WHERE client_id = ? AND status = 'Active'", 'i', [$client_id]),
            'customers'  => (int)qval($conn, "SELECT COUNT(DISTINCT customer_id) FROM distribution_route_customers WHERE client_id = ? AND status = 'Active'", 'i', [$client_id]),
            'drivers'    => (int)qval($conn, "SELECT COUNT(DISTINCT driver_id) FROM distribution_routes WHERE client_id = ? AND driver_id IS NOT NULL", 'i', [$client_id]),
            'today'      => (int)qval($conn, "SELECT COUNT(*) FROM distribution_route_deliveries WHERE client_id = ? AND delivery_date = ?", 'is', [$client_id, $today]),
            'pending'    => (int)qval($conn, "SELECT COALESCE(SUM(pending_orders),0) FROM distribution_route_deliveries WHERE client_id = ? AND delivery_date = ?", 'is', [$client_id, $today]),
            'collection' => (float)qval($conn, "SELECT COALESCE(SUM(total_collection),0) FROM distribution_route_deliveries WHERE client_id = ? AND delivery_date = ?", 'is', [$client_id, $today]),
            'failed'     => (int)qval($conn, "SELECT COALESCE(SUM(failed_orders + returned_orders),0) FROM distribution_route_deliveries WHERE client_id = ? AND delivery_date = ?", 'is', [$client_id, $today]),
        ];
        json_out(['success' => true, 'stats' => $stats]);
    }

    if ($action === 'drivers') {
        $data = [];
        if (qval($conn, "SHOW TABLES LIKE 'delivery_drivers'")) {
            $data = rows($conn, "
                SELECT id, driver_name AS name, vehicle, phone, status
                FROM delivery_drivers
                WHERE client_id = ? AND status != 'Inactive'
                ORDER BY driver_name ASC
            ", 'i', [$client_id]);
        }
        json_out(['success' => true, 'data' => $data]);
    }

    if ($action === 'get_routes') {
        $page   = max(1, (int)($_POST['page'] ?? 1));
        $limit  = max(5, min(100, (int)($_POST['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        $search = trim($_POST['search'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $driver = trim($_POST['driver'] ?? '');
        $area   = trim($_POST['area'] ?? '');
        $city   = trim($_POST['city'] ?? '');
        $day    = trim($_POST['day'] ?? '');

        $where = "r.client_id = ?";
        $types = 'i';
        $params = [$client_id];

        if ($search !== '') {
            $where .= " AND (r.route_code LIKE ? OR r.route_name LIKE ? OR r.area LIKE ? OR r.city LIKE ? OR r.driver_name LIKE ?)";
            $like = "%$search%";
            $types .= 'sssss';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($status !== '') { $where .= " AND r.status = ?"; $types .= 's'; $params[] = $status; }
        if ($driver !== '') { $where .= " AND r.driver_name = ?"; $types .= 's'; $params[] = $driver; }
        if ($area !== '') { $where .= " AND r.area LIKE ?"; $types .= 's'; $params[] = "%$area%"; }
        if ($city !== '') { $where .= " AND r.city LIKE ?"; $types .= 's'; $params[] = "%$city%"; }
        if ($day !== '') { $where .= " AND FIND_IN_SET(?, REPLACE(r.delivery_days,' ',''))"; $types .= 's'; $params[] = $day; }

        $total = (int)qval($conn, "SELECT COUNT(*) FROM distribution_routes r WHERE $where", $types, $params);
        $sql = "SELECT r.*,
            (SELECT COUNT(*) FROM distribution_route_customers rc WHERE rc.route_id = r.id AND rc.client_id = r.client_id AND rc.status = 'Active') AS customers,
            (SELECT COALESCE(SUM(total_orders),0) FROM distribution_route_deliveries d WHERE d.route_id = r.id AND d.client_id = r.client_id AND d.delivery_date = CURDATE()) AS today_orders,
            (SELECT COALESCE(SUM(pending_orders),0) FROM distribution_route_deliveries d WHERE d.route_id = r.id AND d.client_id = r.client_id AND d.delivery_date = CURDATE()) AS pending_orders,
            (SELECT COALESCE(SUM(total_collection),0) FROM distribution_route_deliveries d WHERE d.route_id = r.id AND d.client_id = r.client_id AND d.delivery_date = CURDATE()) AS collection
            FROM distribution_routes r
            WHERE $where
            ORDER BY r.id DESC
            LIMIT ? OFFSET ?";
        $types2 = $types . 'ii';
        $params2 = $params;
        $params2[] = $limit;
        $params2[] = $offset;

        json_out([
            'success' => true,
            'data'    => rows($conn, $sql, $types2, $params2),
            'total'   => $total,
            'page'    => $page,
            'pages'   => max(1, (int)ceil($total / $limit)),
        ]);
    }

    if ($action === 'save_route') {
        $id     = (int)($_POST['id'] ?? 0);
        $code   = trim($_POST['route_code'] ?? '');
        $name   = trim($_POST['route_name'] ?? '');
        $area   = trim($_POST['area'] ?? '');
        $city   = trim($_POST['city'] ?? '');
        $driver_id = ($_POST['driver_id'] ?? '') !== '' ? (int)$_POST['driver_id'] : null;
        $driver_name = trim($_POST['driver_name'] ?? '');
        $vehicle = trim($_POST['vehicle_name'] ?? '');
        $days    = trim($_POST['delivery_days'] ?? '');
        $start   = trim($_POST['start_time'] ?? '08:00');
        $end     = trim($_POST['end_time'] ?? '18:00');
        $status  = trim($_POST['status'] ?? 'Active');
        $notes   = trim($_POST['notes'] ?? '');

        if ($name === '' || $area === '') {
            json_out(['success' => false, 'message' => 'Route name aur area required hain.']);
        }
        if ($code === '') $code = nextRouteCode($conn, $client_id);
        if ($driver_id && $driver_name === '') {
            $driver_name = (string)qval($conn, "SELECT driver_name FROM delivery_drivers WHERE id = ? AND client_id = ?", 'ii', [$driver_id, $client_id]);
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE distribution_routes
                SET route_code=?, route_name=?, area=?, city=?, driver_id=?, driver_name=?, vehicle_name=?, delivery_days=?, start_time=?, end_time=?, status=?, notes=?, updated_at=NOW()
                WHERE id=? AND client_id=?");
            $stmt->bind_param('ssssisssssssii', $code, $name, $area, $city, $driver_id, $driver_name, $vehicle, $days, $start, $end, $status, $notes, $id, $client_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO distribution_routes
                (client_id, branch_id, route_code, route_name, area, city, driver_id, driver_name, vehicle_name, delivery_days, start_time, end_time, status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iissssisssssssi', $client_id, $branch_id, $code, $name, $area, $city, $driver_id, $driver_name, $vehicle, $days, $start, $end, $status, $notes, $user_id);
        }

        if (!$stmt || !$stmt->execute()) {
            json_out(['success' => false, 'message' => $stmt ? $stmt->error : $conn->error]);
        }
        $stmt->close();
        json_out(['success' => true, 'message' => 'Distribution route saved successfully.']);
    }

    if ($action === 'delete_route') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid route.']);

        $conn->begin_transaction();
        try {
            foreach (['distribution_route_delivery_items','distribution_route_deliveries','distribution_route_customers'] as $table) {
                $stmt = $conn->prepare("DELETE FROM `$table` WHERE route_id = ? AND client_id = ?");
                $stmt->bind_param('ii', $id, $client_id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare("DELETE FROM distribution_routes WHERE id = ? AND client_id = ?");
            $stmt->bind_param('ii', $id, $client_id);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            json_out(['success' => true, 'message' => 'Route deleted successfully.']);
        } catch (Throwable $e) {
            $conn->rollback();
            json_out(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
    }

    if ($action === 'bulk_status' || $action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) json_out(['success' => false, 'message' => 'Please select routes.']);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) json_out(['success' => false, 'message' => 'Invalid route selection.']);

        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids)) . 'i';
        $params = $ids;
        $params[] = $client_id;

        if ($action === 'bulk_status') {
            $status = trim($_POST['status'] ?? 'Active');
            $types = 's' . $types;
            array_unshift($params, $status);
            $stmt = $conn->prepare("UPDATE distribution_routes SET status = ?, updated_at = NOW() WHERE id IN ($in) AND client_id = ?");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            json_out(['success' => true, 'message' => 'Routes status updated.']);
        }

        $stmt = $conn->prepare("DELETE FROM distribution_routes WHERE id IN ($in) AND client_id = ?");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        json_out(['success' => true, 'message' => 'Selected routes deleted.']);
    }

    if ($action === 'route_detail') {
        $id = (int)($_POST['id'] ?? 0);
        $r = rows($conn, "SELECT * FROM distribution_routes WHERE id = ? AND client_id = ?", 'ii', [$id, $client_id]);
        json_out(['success' => !empty($r), 'data' => $r[0] ?? null]);
    }

    if ($action === 'get_customers') {
        $route_id = (int)($_POST['route_id'] ?? 0);
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $assigned = (int)($_POST['assigned'] ?? 1);
        $search = trim($_POST['search'] ?? '');

        $baseWhere = "c.client_id = ?";
        $types = 'i';
        $params = [$client_id];
        if ($search !== '') {
            $baseWhere .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR c.type LIKE ?)";
            $like = "%$search%";
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        }

        if ($assigned) {
            $where = "$baseWhere AND rc.route_id = ? AND rc.status = 'Active'";
            $types2 = $types . 'i';
            $params2 = $params;
            $params2[] = $route_id;
            $total = (int)qval($conn, "SELECT COUNT(*) FROM customers c INNER JOIN distribution_route_customers rc ON rc.customer_id = c.id AND rc.client_id = c.client_id WHERE $where", $types2, $params2);
            $sql = "SELECT c.*, rc.sequence_no, 1 AS assigned
                FROM customers c
                INNER JOIN distribution_route_customers rc ON rc.customer_id = c.id AND rc.client_id = c.client_id
                WHERE $where
                ORDER BY rc.sequence_no, c.name
                LIMIT ? OFFSET ?";
            $types3 = $types2 . 'ii';
            $params3 = $params2;
            $params3[] = $limit;
            $params3[] = $offset;
        } else {
            $where = "$baseWhere AND c.id NOT IN (SELECT customer_id FROM distribution_route_customers WHERE client_id = ? AND status = 'Active')";
            $types2 = $types . 'i';
            $params2 = $params;
            $params2[] = $client_id;
            $total = (int)qval($conn, "SELECT COUNT(*) FROM customers c WHERE $where", $types2, $params2);
            $sql = "SELECT c.*, 0 AS sequence_no, 0 AS assigned
                FROM customers c
                WHERE $where
                ORDER BY c.name
                LIMIT ? OFFSET ?";
            $types3 = $types2 . 'ii';
            $params3 = $params2;
            $params3[] = $limit;
            $params3[] = $offset;
        }

        json_out([
            'success' => true,
            'data' => rows($conn, $sql, $types3, $params3),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
        ]);
    }

    if ($action === 'assign_customers') {
        $route_id = (int)($_POST['route_id'] ?? 0);
        $ids = $_POST['customer_ids'] ?? [];
        if ($route_id <= 0 || !is_array($ids)) json_out(['success' => false, 'message' => 'Invalid data.']);
        $seq = (int)qval($conn, "SELECT COALESCE(MAX(sequence_no),0) FROM distribution_route_customers WHERE client_id = ? AND route_id = ?", 'ii', [$client_id, $route_id]);
        $stmt = $conn->prepare("INSERT IGNORE INTO distribution_route_customers (client_id, branch_id, route_id, customer_id, sequence_no, status) VALUES (?,?,?,?,?,'Active')");
        foreach ($ids as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $seq++;
                $stmt->bind_param('iiiii', $client_id, $branch_id, $route_id, $cid, $seq);
                $stmt->execute();
            }
        }
        $stmt->close();
        json_out(['success' => true, 'message' => 'Customers assigned to route.']);
    }

    if ($action === 'remove_route_customer') {
        $route_id = (int)($_POST['route_id'] ?? 0);
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE distribution_route_customers SET status = 'Inactive' WHERE client_id = ? AND route_id = ? AND customer_id = ?");
        $stmt->bind_param('iii', $client_id, $route_id, $customer_id);
        $stmt->execute();
        $stmt->close();
        json_out(['success' => true, 'message' => 'Customer removed from route.']);
    }

    if ($action === 'create_delivery') {
        $route_id = (int)($_POST['route_id'] ?? 0);
        $date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');
        $route = rows($conn, "SELECT * FROM distribution_routes WHERE id = ? AND client_id = ?", 'ii', [$route_id, $client_id]);
        if (!$route) json_out(['success' => false, 'message' => 'Route not found.']);
        $r = $route[0];
        $salesDateCol = sales_date_column($conn);
        $hasCustomerId = table_has_column($conn, 'sales', 'customer_id');
        $hasInvoice = table_has_column($conn, 'sales', 'invoice_no');

        if (!$hasCustomerId) {
            json_out(['success' => false, 'message' => 'sales table me customer_id column required hai distribution delivery generate karne ke liye.']);
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO distribution_route_deliveries
                (client_id, branch_id, route_id, delivery_date, driver_id, driver_name, vehicle_name, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE notes = VALUES(notes), driver_id = VALUES(driver_id), driver_name = VALUES(driver_name), vehicle_name = VALUES(vehicle_name), updated_at = NOW()");
            $stmt->bind_param('iiisisssi', $client_id, $branch_id, $route_id, $date, $r['driver_id'], $r['driver_name'], $r['vehicle_name'], $notes, $user_id);
            $stmt->execute();
            $stmt->close();

            $delivery_id = (int)qval($conn, "SELECT id FROM distribution_route_deliveries WHERE client_id = ? AND route_id = ? AND delivery_date = ?", 'iis', [$client_id, $route_id, $date]);

            $custs = rows($conn, "SELECT customer_id, sequence_no FROM distribution_route_customers WHERE client_id = ? AND route_id = ? AND status = 'Active' ORDER BY sequence_no", 'ii', [$client_id, $route_id]);
            $ins = $conn->prepare("INSERT IGNORE INTO distribution_route_delivery_items
                (client_id, branch_id, delivery_id, route_id, sale_id, customer_id, sequence_no, order_amount, delivery_status)
                VALUES (?,?,?,?,?,?,?,?, 'Pending')");

            foreach ($custs as $c) {
                $salesSql = "SELECT id, total FROM sales WHERE client_id = ? AND customer_id = ?";
                $salesTypes = 'ii';
                $salesParams = [$client_id, (int)$c['customer_id']];
                if ($salesDateCol !== 'id') {
                    $salesSql .= " AND DATE(`$salesDateCol`) = ?";
                    $salesTypes .= 's';
                    $salesParams[] = $date;
                }
                $salesSql .= " ORDER BY id ASC";
                $sales = rows($conn, $salesSql, $salesTypes, $salesParams);
                foreach ($sales as $sale) {
                    $sid = (int)$sale['id'];
                    $cid = (int)$c['customer_id'];
                    $seq = (int)$c['sequence_no'];
                    $amount = (float)($sale['total'] ?? 0);
                    $ins->bind_param('iiiiiiid', $client_id, $branch_id, $delivery_id, $route_id, $sid, $cid, $seq, $amount);
                    $ins->execute();
                }
            }
            $ins->close();
            refreshDistributionTotals($conn, $delivery_id, $client_id);
            $conn->commit();
            json_out(['success' => true, 'message' => 'Distribution delivery generated.', 'delivery_id' => $delivery_id]);
        } catch (Throwable $e) {
            $conn->rollback();
            json_out(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'get_deliveries') {
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $date = trim($_POST['date'] ?? '');
        $route_id = (int)($_POST['route_id'] ?? 0);

        $where = "d.client_id = ?";
        $types = 'i';
        $params = [$client_id];
        if ($date !== '') { $where .= " AND d.delivery_date = ?"; $types .= 's'; $params[] = $date; }
        if ($route_id > 0) { $where .= " AND d.route_id = ?"; $types .= 'i'; $params[] = $route_id; }

        $total = (int)qval($conn, "SELECT COUNT(*) FROM distribution_route_deliveries d WHERE $where", $types, $params);
        $sql = "SELECT d.*, r.route_code, r.route_name, r.area, r.city
            FROM distribution_route_deliveries d
            LEFT JOIN distribution_routes r ON r.id = d.route_id AND r.client_id = d.client_id
            WHERE $where
            ORDER BY d.delivery_date DESC, d.id DESC
            LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;
        json_out(['success' => true, 'data' => rows($conn, $sql, $types, $params), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $limit))]);
    }

    if ($action === 'get_items') {
        $delivery_id = (int)($_POST['delivery_id'] ?? 0);
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $total = (int)qval($conn, "SELECT COUNT(*) FROM distribution_route_delivery_items WHERE client_id = ? AND delivery_id = ?", 'ii', [$client_id, $delivery_id]);
        $invoiceSelect = table_has_column($conn, 'sales', 'invoice_no') ? 's.invoice_no' : 's.id AS invoice_no';
        $sql = "SELECT di.*, c.name AS customer_name, c.phone, c.address, c.type, $invoiceSelect,
            COALESCE(s.total, di.order_amount) AS sale_total,
            (SELECT COALESCE(SUM(qty),0) FROM sale_items si WHERE si.sale_id = di.sale_id) AS order_qty
            FROM distribution_route_delivery_items di
            LEFT JOIN customers c ON c.id = di.customer_id AND c.client_id = di.client_id
            LEFT JOIN sales s ON s.id = di.sale_id AND s.client_id = di.client_id
            WHERE di.client_id = ? AND di.delivery_id = ?
            ORDER BY di.sequence_no, di.id
            LIMIT ? OFFSET ?";
        json_out(['success' => true, 'data' => rows($conn, $sql, 'iiii', [$client_id, $delivery_id, $limit, $offset]), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $limit))]);
    }

    if ($action === 'update_item') {
        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['delivery_status'] ?? 'Pending');
        $pay = (float)($_POST['payment_collected'] ?? 0);
        $method = trim($_POST['payment_method'] ?? 'Cash');
        $remarks = trim($_POST['remarks'] ?? '');
        $item = rows($conn, "SELECT * FROM distribution_route_delivery_items WHERE id = ? AND client_id = ?", 'ii', [$id, $client_id]);
        if (!$item) json_out(['success' => false, 'message' => 'Delivery item not found.']);
        $it = $item[0];
        $deliveredAt = in_array($status, ['Delivered','Failed','Returned'], true) ? date('Y-m-d H:i:s') : null;

        $stmt = $conn->prepare("UPDATE distribution_route_delivery_items
            SET payment_collected = ?, payment_method = ?, delivery_status = ?, remarks = ?, delivered_at = ?, updated_at = NOW()
            WHERE id = ? AND client_id = ?");
        $stmt->bind_param('dssssii', $pay, $method, $status, $remarks, $deliveredAt, $id, $client_id);
        $stmt->execute();
        $stmt->close();
        refreshDistributionTotals($conn, (int)$it['delivery_id'], $client_id);
        json_out(['success' => true, 'message' => 'Delivery item updated.']);
    }

    json_out(['success' => false, 'message' => 'Invalid action.']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distribution Routes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php include('partials/head.php'); ?>
    <style>
        :root{--sidebar-width:250px;--topbar-height:60px;--gold:#f2b90f;--page-bg:#f3f6fc;--primary:#2f55a4;--text:#10203a;--muted:#667085;--border:#dfe6f2;--green:#16a34a;--red:#dc2626;--orange:#d97706}
        body{margin:0;background:var(--page-bg);font-family:"Segoe UI",Tahoma,sans-serif;color:var(--text)}
        .container-scroll{display:flex;min-height:100vh}.sidebar{width:var(--sidebar-width);flex-shrink:0}.main-panel{flex:1;min-width:0}.topbar{height:var(--topbar-height);background:#050505;display:flex;align-items:center;padding:0 22px}.content-wrapper{padding:18px 20px 34px}.page-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap}.page-title{font-size:28px;font-weight:800}.btn-add{height:44px;border:none;border-radius:10px;background:var(--gold);color:#111827;font-weight:800;padding:0 18px}.route-tabs{display:flex;gap:8px;margin:0 0 14px;flex-wrap:wrap}.tab-btn{border:1px solid var(--border);background:#fff;color:#17233b;border-radius:10px;height:42px;padding:0 16px;font-weight:800}.tab-btn.active{background:#17233b;color:#fff;border-color:#17233b}.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.kpi-card{min-height:86px;border-radius:10px;padding:14px 18px;color:#fff;display:flex;align-items:center;box-shadow:0 12px 26px rgba(15,23,42,.10);overflow:hidden;position:relative}.kpi-card:nth-child(1){background:linear-gradient(135deg,#314d97,#415ba8)}.kpi-card:nth-child(2){background:linear-gradient(135deg,#65ad49,#56a142)}.kpi-card:nth-child(3){background:linear-gradient(135deg,#b93645,#d75a68)}.kpi-card:nth-child(4){background:linear-gradient(135deg,#e2ad13,#f6bd1d)}.kpi-icon{font-size:24px;margin-right:12px}.kpi-label{font-size:15px;font-weight:700}.kpi-value{font-size:30px;font-weight:800;margin-left:auto}.filter-card,.table-card{background:#fff;border:1px solid #e3e8f0;border-radius:12px;box-shadow:0 10px 24px rgba(18,38,63,.06);margin-bottom:14px}.filter-card{padding:12px}.filter-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.filter-row input,.filter-row select{height:44px;border:1px solid #dbe2ed;border-radius:10px;padding:0 14px;min-width:150px}.filter-search{flex:1;min-width:260px}.filter-btn{height:44px;border:0;border-radius:10px;padding:0 18px;background:#1f2746;color:#fff;font-weight:800}.reset-btn{height:44px;border:0;border-radius:10px;padding:0 18px;background:#eef2f7;color:#4b5563;font-weight:800}.table-card-header{padding:14px 16px;border-bottom:1px solid #e8edf5;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}.table-title{font-size:18px;font-weight:800}.table-count{background:#eff5ff;color:#2f55a4;border-radius:999px;padding:5px 12px;font-size:13px;font-weight:700}.table-responsive{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:1080px}thead th{background:#28344d;color:#fff;padding:14px 12px;font-size:14px;white-space:nowrap}tbody td{padding:14px 12px;border-bottom:1px solid #edf1f6;font-size:14px;vertical-align:middle}.route-code{background:#eef4ff;color:#2f55a4;font-weight:800;padding:5px 10px;border-radius:8px}.status-badge{padding:4px 9px;border-radius:999px;font-size:12px;font-weight:800}.status-active,.status-delivered,.status-completed{background:#dcfce7;color:#16a34a}.status-inactive,.status-failed,.status-returned{background:#fee2e2;color:#dc2626}.status-onhold,.status-pending,.status-progress,.status-partial{background:#fef3c7;color:#d97706}.action-btns{display:flex;gap:7px;flex-wrap:wrap}.action-btn{width:34px;height:34px;border-radius:50%;border:0;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 8px 18px rgba(15,23,42,.06)}.action-btn.view{background:rgba(99,102,241,.14);color:#6366f1}.action-btn.edit{background:rgba(34,197,94,.14);color:#16a34a}.action-btn.assign{background:rgba(59,130,246,.14);color:#2563eb}.action-btn.customers{background:rgba(168,85,247,.14);color:#8b5cf6}.action-btn.delete{background:rgba(239,68,68,.14);color:#ef4444}.pagination-bar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-top:1px solid #f3f4f6}.pag-btns{display:flex;gap:5px}.pag-btn{min-width:36px;height:36px;border:1px solid var(--border);background:#fff;border-radius:9px;font-weight:700}.pag-btn.active{background:var(--primary);color:#fff}.section{display:none}.section.active{display:block}.bulk-bar{display:none;background:#1e40af;color:#fff;padding:10px 18px;gap:12px;align-items:center;font-size:13px;font-weight:700}.bulk-bar.show{display:flex}.bulk-btn{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:7px;padding:5px 12px}.form-control-custom{width:100%;height:42px;border:1px solid var(--border);border-radius:9px;padding:0 11px}.days-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}.toast-container{position:fixed;top:75px;right:20px;z-index:9999}.toast-msg{background:#fff;border-left:4px solid var(--primary);border-radius:10px;padding:12px 16px;margin-bottom:8px;box-shadow:0 4px 20px rgba(0,0,0,.15);font-weight:700}.toast-msg.success{border-left-color:var(--green)}.toast-msg.error{border-left-color:var(--red)}@media(max-width:900px){.sidebar{display:none}.kpi-grid{grid-template-columns:repeat(2,1fr)}}
    
/* =====================================================
   ROUTES_MANAGEMENT.PHP DASHBOARD DARK PURPLE GLASS THEME
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

/* Layout / Sidebar */
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

/* Collapsed Sidebar Support */
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

/* Content Background */
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

/* Header */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-header small,
.text-muted{
    color:#94a3b8 !important;
    font-weight:600 !important;
}

/* Add Button */
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

/* KPI Cards */
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
.kpi-card:nth-child(3){background:linear-gradient(135deg,#be123c,#ef4444) !important;}
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

/* Tabs */
.route-tabs{
    gap:10px !important;
    margin-bottom:16px !important;
}

.tab-btn{
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

.tab-btn:hover,
.tab-btn.active{
    color:#fff !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.42) !important;
    transform:translateY(-1px);
}

/* Glass Panels */
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
}

.table-card{
    overflow:hidden !important;
}

/* Inputs */
.filter-row input,
.filter-row select,
.form-control,
.form-select{
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
.form-control::placeholder{
    color:#94a3b8 !important;
}

.filter-row input:focus,
.filter-row select:focus,
.form-control:focus,
.form-select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-row select option,
.form-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Filter / Reset Buttons */
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

/* Table */
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

/* White hover remove */
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

/* Badges */
.route-code{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
    font-weight:900 !important;
    border-radius:999px !important;
}

.status-badge{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.status-active,
.status-delivered,
.status-completed{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.status-inactive,
.status-failed,
.status-returned{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

.status-onhold,
.status-pending,
.status-progress,
.status-partial{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

/* Action Buttons */
.action-btn{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:12px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.action-btn.view{background:rgba(109,76,255,.18) !important;color:#c4b5fd !important;}
.action-btn.edit{background:rgba(34,197,94,.16) !important;color:#4ade80 !important;}
.action-btn.assign{background:rgba(59,130,246,.16) !important;color:#60a5fa !important;}
.action-btn.customers{background:rgba(168,85,247,.16) !important;color:#c084fc !important;}
.action-btn.delete{background:rgba(239,68,68,.16) !important;color:#f87171 !important;}

.action-btn:hover{
    transform:translateY(-2px);
}

/* Pagination / Bulk */
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
    background:linear-gradient(135deg,rgba(109,76,255,.90),rgba(124,58,237,.84)) !important;
    color:#fff !important;
}

.bulk-btn{
    background:rgba(15,23,42,.45) !important;
    border:1px solid rgba(255,255,255,.20) !important;
    color:#fff !important;
    border-radius:10px !important;
}

/* Toast */
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

/* Scrollbar */
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
   ROUTES POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

#routeFormModal .modal-dialog,
#createDeliveryModal .modal-dialog,
#detailModal .modal-dialog{
    max-width:min(780px,94vw) !important;
}

#assignCustomersModal .modal-dialog{
    max-width:min(980px,94vw) !important;
}

#routeFormModal .modal-content,
#assignCustomersModal .modal-content,
#createDeliveryModal .modal-content,
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

#routeFormModal .modal-header,
#assignCustomersModal .modal-header,
#createDeliveryModal .modal-header,
#detailModal .modal-header,
#routeFormModal .modal-header.bg-dark,
#assignCustomersModal .modal-header.bg-dark,
#createDeliveryModal .modal-header.bg-dark,
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

#routeFormModal .modal-title,
#assignCustomersModal .modal-title,
#createDeliveryModal .modal-title,
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

#routeFormModal .modal-title::before,
#assignCustomersModal .modal-title::before,
#createDeliveryModal .modal-title::before,
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

#routeFormModal .btn-close,
#assignCustomersModal .btn-close,
#createDeliveryModal .btn-close,
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

#routeFormModal .btn-close::before,
#routeFormModal .btn-close::after,
#assignCustomersModal .btn-close::before,
#assignCustomersModal .btn-close::after,
#createDeliveryModal .btn-close::before,
#createDeliveryModal .btn-close::after,
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

#routeFormModal .btn-close::before,
#assignCustomersModal .btn-close::before,
#createDeliveryModal .btn-close::before,
#detailModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#routeFormModal .btn-close::after,
#assignCustomersModal .btn-close::after,
#createDeliveryModal .btn-close::after,
#detailModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#routeFormModal .btn-close:hover,
#assignCustomersModal .btn-close:hover,
#createDeliveryModal .btn-close:hover,
#detailModal .btn-close:hover{
    background:#ef4444 !important;
}

#routeFormModal .modal-body,
#assignCustomersModal .modal-body,
#createDeliveryModal .modal-body,
#detailModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#routeFormModal .row.g-3{
    --bs-gutter-x:12px !important;
    --bs-gutter-y:10px !important;
}

#routeFormModal label,
#assignCustomersModal label,
#createDeliveryModal label,
#detailModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#routeFormModal .form-control-custom,
#routeFormModal .form-control,
#routeFormModal .form-select,
#routeFormModal input[type="text"],
#routeFormModal input[type="time"],
#routeFormModal select,
#assignCustomersModal .form-control,
#assignCustomersModal .form-select,
#assignCustomersModal input[type="text"],
#assignCustomersModal select,
#createDeliveryModal .form-control,
#createDeliveryModal .form-select,
#createDeliveryModal input[type="date"],
#createDeliveryModal textarea{
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

#createDeliveryModal textarea,
#routeFormModal textarea,
#assignCustomersModal textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#routeFormModal input[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

#routeFormModal input::placeholder,
#assignCustomersModal input::placeholder,
#createDeliveryModal textarea::placeholder{
    color:#94a3b8 !important;
}

#routeFormModal input:focus,
#routeFormModal select:focus,
#assignCustomersModal input:focus,
#assignCustomersModal select:focus,
#createDeliveryModal input:focus,
#createDeliveryModal textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#routeFormModal .days-grid{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:10px 12px !important;
}

#routeFormModal .days-grid label{
    color:#e5e7eb !important;
    font-weight:800 !important;
    margin:0 !important;
}

#routeFormModal .days-grid input,
#assignCustomersModal input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Assign customer modal table */
#assignCustomersModal .filter-row{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    padding:12px !important;
}

#assignCustomersModal .table-responsive{
    background:rgba(15,23,42,.35) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    overflow:auto !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#assignCustomersModal table,
#assignCustomersModal tbody,
#assignCustomersModal tbody tr{
    background:transparent !important;
}

#assignCustomersModal thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#assignCustomersModal tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

#assignCustomersModal tbody tr:hover td{
    background:rgba(124,58,237,.12) !important;
    color:#fff !important;
}

#assignCustomersModal .pagination-bar{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#94a3b8 !important;
}

/* Detail modal boxes */
#detailModal #detailBody{
    background:transparent !important;
    color:#ffffff !important;
}

#detailModal #detailBody .col-md-6,
#detailModal #detailBody .col-md-12{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#detailModal #detailBody b{
    color:#94a3b8 !important;
    font-size:12px !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

/* Modal footer + buttons */
#routeFormModal .modal-footer,
#createDeliveryModal .modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#routeFormModal .btn-light,
#createDeliveryModal .btn-secondary{
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

#routeFormModal .btn-primary,
#assignCustomersModal .btn-primary,
#assignCustomersModal .btn-success,
#createDeliveryModal .btn-success,
#assignCustomersModal .filter-btn{
    min-height:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#routeFormModal .btn-light:hover,
#createDeliveryModal .btn-secondary:hover,
#routeFormModal .btn-primary:hover,
#assignCustomersModal .btn-primary:hover,
#assignCustomersModal .btn-success:hover,
#createDeliveryModal .btn-success:hover,
#assignCustomersModal .filter-btn:hover{
    transform:translateY(-1px);
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
                    <div class="page-title"><i class="bi bi-signpost-2 me-2"></i>Distribution Route Management</div>
                    <small class="text-muted">Routes → Customers → Daily Distribution → Order Updates</small>
                </div>
                <button class="btn-add" onclick="openRouteModal()"><i class="bi bi-plus-lg me-1"></i>Add New Route</button>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card"><i class="bi bi-signpost-2 kpi-icon"></i><div class="kpi-label">Total Routes</div><div class="kpi-value" id="kpiTotal">0</div></div>
                <div class="kpi-card"><i class="bi bi-check-circle kpi-icon"></i><div class="kpi-label">Active Routes</div><div class="kpi-value" id="kpiActive">0</div></div>
                <div class="kpi-card"><i class="bi bi-people kpi-icon"></i><div class="kpi-label">Route Customers</div><div class="kpi-value" id="kpiCustomers">0</div></div>
                <div class="kpi-card"><i class="bi bi-person-badge kpi-icon"></i><div class="kpi-label">Drivers</div><div class="kpi-value" id="kpiDrivers">0</div></div>
            </div>

            <div class="route-tabs">
                <button class="tab-btn active" data-tab="routes" onclick="switchTab('routes')">Routes</button>
                <button class="tab-btn" data-tab="customers" onclick="switchTab('customers')">Route Customers</button>
                <button class="tab-btn" data-tab="deliveries" onclick="switchTab('deliveries')">Daily Distribution</button>
                <button class="tab-btn" data-tab="items" onclick="switchTab('items')">Order Updates</button>
            </div>

            <div id="routesSection" class="section active">
                <div class="filter-card"><div class="filter-row">
                    <input id="filterSearch" class="filter-search" placeholder="Search route, area, city, driver">
                    <select id="filterStatus"><option value="">All Status</option><option>Active</option><option>Inactive</option><option>On Hold</option></select>
                    <select id="filterDriver"><option value="">All Drivers</option></select>
                    <input id="filterArea" placeholder="Area">
                    <input id="filterCity" placeholder="City">
                    <select id="filterDay"><option value="">All Days</option><option>Mon</option><option>Tue</option><option>Wed</option><option>Thu</option><option>Fri</option><option>Sat</option><option>Sun</option></select>
                    <button class="filter-btn" onclick="loadRoutes(1)"><i class="bi bi-funnel"></i> Filter</button>
                    <button class="reset-btn" onclick="resetRouteFilters()">Reset</button>
                </div></div>

                <div class="table-card">
                    <div class="bulk-bar" id="bulkBar"><span id="bulkCount">0</span> routes selected <button class="bulk-btn" onclick="bulkStatus('Active')">Activate</button><button class="bulk-btn" onclick="bulkStatus('Inactive')">Inactivate</button><button class="bulk-btn" onclick="bulkDelete()">Delete</button><button class="bulk-btn ms-auto" onclick="clearSelection()">Cancel</button></div>
                    <div class="table-card-header"><div class="table-title">Distribution Routes List</div><span class="table-count" id="routeCount">0 Routes</span></div>
                    <div class="table-responsive"><table><thead><tr><th><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th><th>Route Code</th><th>Route Name</th><th>Area</th><th>City</th><th>Driver</th><th>Vehicle</th><th>Customers</th><th>Today Orders</th><th>Pending</th><th>Collection</th><th>Status</th><th>Actions</th></tr></thead><tbody id="routeTableBody"></tbody></table></div>
                    <div class="pagination-bar"><div id="routePagInfo"></div><div class="pag-btns" id="routePagBtns"></div></div>
                </div>
            </div>

            <div id="customersSection" class="section">
                <div class="table-card"><div class="table-card-header"><div class="table-title">Customers for: <span id="currentRouteName">Select Route</span></div><div><button class="btn btn-sm btn-primary" onclick="openAssignCustomersModal()">Assign Customers</button> <button class="btn btn-sm btn-success" onclick="openCreateDeliveryModal()">Generate Distribution</button></div></div>
                    <div class="filter-card m-0 border-0 shadow-none"><div class="filter-row"><input id="custSearch" class="filter-search" placeholder="Search customers"><button class="filter-btn" onclick="loadRouteCustomers(1)">Search</button></div></div>
                    <div class="table-responsive"><table><thead><tr><th>#</th><th>Customer</th><th>Phone</th><th>Address</th><th>Type</th><th>Sequence</th><th>Action</th></tr></thead><tbody id="customersBody"></tbody></table></div>
                    <div class="pagination-bar"><div id="custPagInfo"></div><div class="pag-btns" id="custPagBtns"></div></div>
                </div>
            </div>

            <div id="deliveriesSection" class="section">
                <div class="filter-card"><div class="filter-row"><input type="date" id="deliveryDateFilter"><button class="filter-btn" onclick="loadDeliveries(1)">Filter</button><button class="reset-btn" onclick="document.getElementById('deliveryDateFilter').value='';loadDeliveries(1)">Reset</button></div></div>
                <div class="table-card"><div class="table-card-header"><div class="table-title">Daily Distribution Sheets</div><span class="table-count" id="deliveryCount">0</span></div>
                    <div class="table-responsive"><table><thead><tr><th>ID</th><th>Date</th><th>Route</th><th>Area/City</th><th>Driver</th><th>Orders</th><th>Pending</th><th>Delivered</th><th>Failed/Returned</th><th>Collection</th><th>Status</th><th>Action</th></tr></thead><tbody id="deliveriesBody"></tbody></table></div>
                    <div class="pagination-bar"><div id="delPagInfo"></div><div class="pag-btns" id="delPagBtns"></div></div>
                </div>
            </div>

            <div id="itemsSection" class="section">
                <div class="table-card"><div class="table-card-header"><div class="table-title">Distribution Orders: <span id="currentDeliveryLabel">Select Distribution</span></div></div>
                    <div class="table-responsive"><table><thead><tr><th>Customer</th><th>Phone</th><th>Order</th><th>Qty</th><th>Amount</th><th>Collected</th><th>Payment Method</th><th>Status</th><th>Remarks</th><th>Action</th></tr></thead><tbody id="itemsBody"></tbody></table></div>
                    <div class="pagination-bar"><div id="itemPagInfo"></div><div class="pag-btns" id="itemPagBtns"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<div class="modal fade" id="routeFormModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title" id="routeModalTitle">Add New Route</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" id="editRouteId"><div class="row g-3"><div class="col-md-4"><label>Route Code</label><input id="fCode" class="form-control-custom" readonly></div><div class="col-md-4"><label>Route Name</label><input id="fName" class="form-control-custom"></div><div class="col-md-4"><label>Area</label><input id="fArea" class="form-control-custom"></div><div class="col-md-4"><label>City</label><input id="fCity" class="form-control-custom" placeholder="Karachi"></div><div class="col-md-4"><label>Driver</label><select id="fDriver" class="form-control-custom"><option value="">-- Select Driver --</option></select></div><div class="col-md-4"><label>Vehicle</label><input id="fVehicle" class="form-control-custom" placeholder="Bike / Pickup / Van"></div><div class="col-md-6"><label>Delivery Days</label><div class="days-grid" id="daysGrid"><label><input type="checkbox" value="Mon"> Mon</label><label><input type="checkbox" value="Tue"> Tue</label><label><input type="checkbox" value="Wed"> Wed</label><label><input type="checkbox" value="Thu"> Thu</label><label><input type="checkbox" value="Fri"> Fri</label><label><input type="checkbox" value="Sat"> Sat</label><label><input type="checkbox" value="Sun"> Sun</label></div></div><div class="col-md-3"><label>Start</label><input type="time" id="fStartTime" class="form-control-custom" value="08:00"></div><div class="col-md-3"><label>End</label><input type="time" id="fEndTime" class="form-control-custom" value="18:00"></div><div class="col-md-6"><label>Status</label><select id="fStatus" class="form-control-custom"><option>Active</option><option>Inactive</option><option>On Hold</option></select></div><div class="col-md-6"><label>Notes</label><input id="fNotes" class="form-control-custom"></div></div></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" onclick="saveRoute()">Save Route</button></div></div></div></div>

<div class="modal fade" id="assignCustomersModal" tabindex="-1"><div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Assign Customers</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="filter-row mb-2"><input id="availableCustomerSearch" class="filter-search" placeholder="Search unassigned customers"><button class="filter-btn" onclick="loadAvailableCustomers(1)">Search</button><button class="btn btn-success" onclick="saveAssignedCustomers()">Assign Selected</button></div><div class="table-responsive"><table><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.assign-cust-check').forEach(c=>c.checked=this.checked)"></th><th>Name</th><th>Phone</th><th>Address</th><th>Type</th></tr></thead><tbody id="availableCustomersBody"></tbody></table></div><div class="pagination-bar"><div id="availableCustPagInfo"></div><div class="pag-btns" id="availableCustPagBtns"></div></div></div></div></div></div>

<div class="modal fade" id="createDeliveryModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Generate Daily Distribution</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><label>Distribution Date</label><input type="date" id="createDeliveryDate" class="form-control mb-2"><label>Notes</label><textarea id="createDeliveryNotes" class="form-control"></textarea><small class="text-muted">Selected date ke orders route customers ke mutabiq delivery sheet me add honge.</small></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" onclick="createDelivery()">Generate</button></div></div></div></div>

<div class="modal fade" id="detailModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Route Detail</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="detailBody"></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const pageUrl = '<?= basename(__FILE__) ?>';
let currentRoute = 0, currentRouteName = '', currentDelivery = 0;
let routePage = 1, custPage = 1, delPage = 1, itemPage = 1, availableCustPage = 1;

function post(action, data = {}) {
    let fd = new FormData(); fd.append('ajax','1'); fd.append('action', action);
    Object.keys(data).forEach(k => Array.isArray(data[k]) ? data[k].forEach(v => fd.append(k+'[]', v)) : fd.append(k, data[k]));
    return fetch(pageUrl, {method:'POST', body:fd}).then(r => r.json());
}
function toast(msg, type='success'){let el=document.createElement('div');el.className='toast-msg '+type;el.innerHTML=msg;toastContainer.appendChild(el);setTimeout(()=>el.remove(),3000)}
function esc(v){return String(v ?? '').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[m]))}
function money(v){return 'PKR '+Number(v||0).toLocaleString()}
function pag(container,page,pages,fn){let html=`<button class="pag-btn" ${page<=1?'disabled':''} onclick="${fn}(${page-1})">‹</button>`;for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++)html+=`<button class="pag-btn ${i===page?'active':''}" onclick="${fn}(${i})">${i}</button>`;html+=`<button class="pag-btn" ${page>=pages?'disabled':''} onclick="${fn}(${page+1})">›</button>`;document.getElementById(container).innerHTML=html}
function switchTab(tab){document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));document.getElementById(tab+'Section').classList.add('active');document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));document.querySelector('.tab-btn[data-tab="'+tab+'"]').classList.add('active');if(tab==='routes')loadRoutes(routePage);if(tab==='deliveries')loadDeliveries(delPage)}
function statusClass(s){s=(s||'').toLowerCase().replace(/\s+/g,'');if(['active','delivered','completed'].includes(s))return 'status-active';if(['inactive','failed','returned','cancelled'].includes(s))return 'status-inactive';if(s==='onhold')return 'status-onhold';if(s==='partial')return 'status-partial';return 'status-pending'}
function loadStats(){post('stats').then(res=>{if(!res.success)return;let s=res.stats;kpiTotal.innerText=s.routes;kpiActive.innerText=s.active;kpiCustomers.innerText=s.customers;kpiDrivers.innerText=s.drivers})}
function loadDrivers(){post('drivers').then(res=>{let opts='<option value="">All Drivers</option>', opts2='<option value="">-- Select Driver --</option>';(res.data||[]).forEach(d=>{let name=esc(d.name||''), vehicle=esc(d.vehicle||'');opts+=`<option value="${name}">${name}</option>`;opts2+=`<option value="${esc(d.id)}" data-name="${name}" data-vehicle="${vehicle}">${name}${vehicle?' - '+vehicle:''}</option>`});filterDriver.innerHTML=opts;fDriver.innerHTML=opts2})}
function loadRoutes(p=1){routePage=p;post('get_routes',{page:p,limit:10,search:filterSearch.value,status:filterStatus.value,driver:filterDriver.value,area:filterArea.value,city:filterCity.value,day:filterDay.value}).then(res=>{let html='';(res.data||[]).forEach(r=>{let safeName=String(r.route_name||'').replace(/'/g,"\\'");html+=`<tr><td><input type="checkbox" class="row-check" value="${r.id}" onchange="updateBulk()"></td><td><span class="route-code">${esc(r.route_code)}</span></td><td><b>${esc(r.route_name)}</b></td><td>${esc(r.area||'')}</td><td>${esc(r.city||'-')}</td><td>${esc(r.driver_name||'-')}</td><td>${esc(r.vehicle_name||'-')}</td><td>${r.customers||0}</td><td>${r.today_orders||0}</td><td>${r.pending_orders||0}</td><td>${money(r.collection||0)}</td><td><span class="status-badge ${statusClass(r.status)}">${esc(r.status)}</span></td><td><div class="action-btns"><button class="action-btn view" onclick="viewRoute(${r.id})"><i class="bi bi-eye"></i></button><button class="action-btn edit" onclick='editRoute(${JSON.stringify(r).replace(/'/g,"&#39;")})'><i class="bi bi-pencil"></i></button><button class="action-btn customers" onclick="openCustomers(${r.id},'${safeName}')"><i class="bi bi-people"></i></button><button class="action-btn assign" onclick="quickDelivery(${r.id},'${safeName}')"><i class="bi bi-truck"></i></button><button class="action-btn delete" onclick="deleteRoute(${r.id})"><i class="bi bi-trash"></i></button></div></td></tr>`});routeTableBody.innerHTML=html||'<tr><td colspan="13" class="text-center py-4">No routes found</td></tr>';routeCount.innerText=(res.total||0)+' Routes';routePagInfo.innerText=`Showing page ${res.page} of ${res.pages} (${res.total} routes)`;pag('routePagBtns',res.page,res.pages,'loadRoutes');loadStats()})}
function resetRouteFilters(){filterSearch.value='';filterStatus.value='';filterDriver.value='';filterArea.value='';filterCity.value='';filterDay.value='';loadRoutes(1)}
function openRouteModal(){editRouteId.value='';routeModalTitle.innerText='Add New Route';document.querySelectorAll('#daysGrid input').forEach(c=>c.checked=false);['fName','fArea','fCity','fVehicle','fNotes'].forEach(id=>document.getElementById(id).value='');fStatus.value='Active';fStartTime.value='08:00';fEndTime.value='18:00';fDriver.value='';post('route_code').then(r=>fCode.value=r.code);new bootstrap.Modal(routeFormModal).show()}
function editRoute(r){editRouteId.value=r.id;fCode.value=r.route_code;fName.value=r.route_name;fArea.value=r.area||'';fCity.value=r.city||'';fVehicle.value=r.vehicle_name||'';fStatus.value=r.status;fStartTime.value=(r.start_time||'08:00').slice(0,5);fEndTime.value=(r.end_time||'18:00').slice(0,5);fNotes.value=r.notes||'';fDriver.value=r.driver_id||'';let days=(r.delivery_days||'').split(',');document.querySelectorAll('#daysGrid input').forEach(c=>c.checked=days.includes(c.value));routeModalTitle.innerText='Edit Route';new bootstrap.Modal(routeFormModal).show()}
function autoFillVehicleFromDriver(){let opt=fDriver.options[fDriver.selectedIndex];if(opt && opt.dataset.vehicle && !fVehicle.value)fVehicle.value=opt.dataset.vehicle}fDriver.addEventListener('change',autoFillVehicleFromDriver);
function saveRoute(){let opt=fDriver.options[fDriver.selectedIndex];let days=[...document.querySelectorAll('#daysGrid input:checked')].map(c=>c.value).join(',');post('save_route',{id:editRouteId.value,route_code:fCode.value,route_name:fName.value,area:fArea.value,city:fCity.value,driver_id:fDriver.value,driver_name:opt?opt.dataset.name||'':'',vehicle_name:fVehicle.value,delivery_days:days,start_time:fStartTime.value,end_time:fEndTime.value,status:fStatus.value,notes:fNotes.value}).then(res=>{toast(res.message,res.success?'success':'error');if(res.success){bootstrap.Modal.getInstance(routeFormModal).hide();loadRoutes(routePage)}})}
function deleteRoute(id){if(!confirm('Delete this route?'))return;post('delete_route',{id}).then(res=>{toast(res.message,res.success?'success':'error');loadRoutes(routePage)})}
function toggleSelectAll(el){document.querySelectorAll('.row-check').forEach(c=>c.checked=el.checked);updateBulk()}function updateBulk(){let n=document.querySelectorAll('.row-check:checked').length;bulkBar.classList.toggle('show',n>0);bulkCount.innerText=n}function clearSelection(){document.querySelectorAll('.row-check').forEach(c=>c.checked=false);selectAll.checked=false;updateBulk()}function selectedRouteIds(){return [...document.querySelectorAll('.row-check:checked')].map(c=>c.value)}function bulkStatus(status){post('bulk_status',{ids:selectedRouteIds(),status}).then(r=>{toast(r.message,r.success?'success':'error');clearSelection();loadRoutes(routePage)})}function bulkDelete(){if(!confirm('Delete selected routes?'))return;post('bulk_delete',{ids:selectedRouteIds()}).then(r=>{toast(r.message,r.success?'success':'error');clearSelection();loadRoutes(routePage)})}
function viewRoute(id){post('route_detail',{id}).then(res=>{let r=res.data;if(!r)return;detailBody.innerHTML=`<div class="row g-3"><div class="col-md-6"><b>Code:</b> ${esc(r.route_code)}<br><b>Name:</b> ${esc(r.route_name)}<br><b>Area:</b> ${esc(r.area)}<br><b>City:</b> ${esc(r.city||'-')}<br><b>Driver:</b> ${esc(r.driver_name||'-')}</div><div class="col-md-6"><b>Vehicle:</b> ${esc(r.vehicle_name||'-')}<br><b>Days:</b> ${esc(r.delivery_days||'-')}<br><b>Time:</b> ${(r.start_time||'').slice(0,5)} - ${(r.end_time||'').slice(0,5)}<br><b>Status:</b> ${esc(r.status)}<br><b>Notes:</b> ${esc(r.notes||'-')}</div></div>`;new bootstrap.Modal(detailModal).show()})}
function openCustomers(id,name){currentRoute=id;currentRouteName=name;document.getElementById('currentRouteName').innerText=name;switchTab('customers');loadRouteCustomers(1)}
function loadRouteCustomers(p=1){custPage=p;if(!currentRoute){customersBody.innerHTML='<tr><td colspan="7" class="text-center py-4">Please select route first</td></tr>';return}post('get_customers',{route_id:currentRoute,page:p,assigned:1,search:custSearch.value}).then(res=>{let html='';(res.data||[]).forEach((c,i)=>html+=`<tr><td>${((res.page-1)*10)+i+1}</td><td><b>${esc(c.name||'')}</b></td><td>${esc(c.phone||'')}</td><td>${esc(c.address||'')}</td><td>${esc(c.type||'')}</td><td>${c.sequence_no||0}</td><td><button class="btn btn-sm btn-outline-danger" onclick="removeRouteCustomer(${c.id})">Remove</button></td></tr>`);customersBody.innerHTML=html||'<tr><td colspan="7" class="text-center py-4">No customers assigned</td></tr>';custPagInfo.innerText=`Page ${res.page} of ${res.pages} (${res.total})`;pag('custPagBtns',res.page,res.pages,'loadRouteCustomers')})}
function removeRouteCustomer(cid){post('remove_route_customer',{route_id:currentRoute,customer_id:cid}).then(r=>{toast(r.message);loadRouteCustomers(custPage);loadRoutes(routePage)})}
function openAssignCustomersModal(){if(!currentRoute){toast('Select route first','error');return}availableCustomerSearch.value='';loadAvailableCustomers(1);new bootstrap.Modal(assignCustomersModal).show()}
function loadAvailableCustomers(p=1){availableCustPage=p;post('get_customers',{route_id:currentRoute,page:p,assigned:0,search:availableCustomerSearch.value}).then(res=>{let html='';(res.data||[]).forEach(c=>html+=`<tr><td><input type="checkbox" class="assign-cust-check" value="${c.id}"></td><td><b>${esc(c.name||'')}</b></td><td>${esc(c.phone||'')}</td><td>${esc(c.address||'')}</td><td>${esc(c.type||'')}</td></tr>`);availableCustomersBody.innerHTML=html||'<tr><td colspan="5" class="text-center py-4">No unassigned customers</td></tr>';availableCustPagInfo.innerText=`Page ${res.page} of ${res.pages} (${res.total})`;pag('availableCustPagBtns',res.page,res.pages,'loadAvailableCustomers')})}
function saveAssignedCustomers(){let ids=[...document.querySelectorAll('.assign-cust-check:checked')].map(c=>c.value);post('assign_customers',{route_id:currentRoute,customer_ids:ids}).then(r=>{toast(r.message,r.success?'success':'error');if(r.success){bootstrap.Modal.getInstance(assignCustomersModal).hide();loadRouteCustomers(1);loadRoutes(routePage)}})}
function openCreateDeliveryModal(){if(!currentRoute){toast('Select route first','error');return}createDeliveryDate.value=new Date().toISOString().slice(0,10);createDeliveryNotes.value='';new bootstrap.Modal(createDeliveryModal).show()}function quickDelivery(id,name){currentRoute=id;document.getElementById('currentRouteName').innerText=name;openCreateDeliveryModal()}
function createDelivery(){post('create_delivery',{route_id:currentRoute,delivery_date:createDeliveryDate.value,notes:createDeliveryNotes.value}).then(r=>{toast(r.message,r.success?'success':'error');if(r.success){bootstrap.Modal.getInstance(createDeliveryModal).hide();switchTab('deliveries');loadDeliveries(1)}})}
function loadDeliveries(p=1){delPage=p;post('get_deliveries',{page:p,date:deliveryDateFilter.value,route_id:0}).then(res=>{let html='';(res.data||[]).forEach(d=>{let label=String((d.route_name||'')+' - '+d.delivery_date).replace(/'/g,"\\'");let fr=Number(d.failed_orders||0)+Number(d.returned_orders||0);html+=`<tr><td>#${d.id}</td><td>${esc(d.delivery_date)}</td><td><span class="route-code">${esc(d.route_code||'')}</span> ${esc(d.route_name||'')}</td><td>${esc((d.area||'-')+' / '+(d.city||'-'))}</td><td>${esc(d.driver_name||'-')}</td><td>${d.total_orders||0}</td><td>${d.pending_orders||0}</td><td>${d.delivered_orders||0}</td><td>${fr}</td><td>${money(d.total_collection||0)}</td><td><span class="status-badge ${statusClass(d.status)}">${esc(d.status)}</span></td><td><button class="btn btn-sm btn-primary" onclick="openDeliveryItems(${d.id},'${label}')">Open</button></td></tr>`});deliveriesBody.innerHTML=html||'<tr><td colspan="12" class="text-center py-4">No distribution generated</td></tr>';deliveryCount.innerText=(res.total||0)+' distributions';delPagInfo.innerText=`Page ${res.page} of ${res.pages}`;pag('delPagBtns',res.page,res.pages,'loadDeliveries');loadStats()})}
function openDeliveryItems(id,label){currentDelivery=id;currentDeliveryLabel.innerText=label;switchTab('items');loadDeliveryItems(1)}
function loadDeliveryItems(p=1){itemPage=p;if(!currentDelivery){itemsBody.innerHTML='<tr><td colspan="10" class="text-center py-4">Please open distribution first</td></tr>';return}post('get_items',{delivery_id:currentDelivery,page:p}).then(res=>{let html='';(res.data||[]).forEach(it=>html+=`<tr><td><b>${esc(it.customer_name||'Walk-in')}</b><br><small>${esc(it.address||'')}</small></td><td>${esc(it.phone||'')}</td><td>${esc(it.invoice_no||('Sale #'+(it.sale_id||'-')))}</td><td>${it.order_qty||0}</td><td>${money(it.sale_total||it.order_amount||0)}</td><td><input type="number" class="form-control form-control-sm" id="pay_${it.id}" value="${it.payment_collected||0}"></td><td><select class="form-select form-select-sm" id="method_${it.id}"><option ${it.payment_method==='Cash'?'selected':''}>Cash</option><option ${it.payment_method==='Bank'?'selected':''}>Bank</option><option ${it.payment_method==='JazzCash'?'selected':''}>JazzCash</option><option ${it.payment_method==='Easypaisa'?'selected':''}>Easypaisa</option><option ${it.payment_method==='Credit'?'selected':''}>Credit</option></select></td><td><select class="form-select form-select-sm" id="st_${it.id}"><option ${it.delivery_status==='Pending'?'selected':''}>Pending</option><option ${it.delivery_status==='Out For Delivery'?'selected':''}>Out For Delivery</option><option ${it.delivery_status==='Delivered'?'selected':''}>Delivered</option><option ${it.delivery_status==='Partial'?'selected':''}>Partial</option><option ${it.delivery_status==='Failed'?'selected':''}>Failed</option><option ${it.delivery_status==='Returned'?'selected':''}>Returned</option></select></td><td><input class="form-control form-control-sm" id="rem_${it.id}" value="${esc(it.remarks||'')}"></td><td><button class="btn btn-sm btn-success" onclick="updateDeliveryItem(${it.id})">Save</button></td></tr>`);itemsBody.innerHTML=html||'<tr><td colspan="10" class="text-center py-4">No orders found for this distribution</td></tr>';itemPagInfo.innerText=`Page ${res.page} of ${res.pages} (${res.total})`;pag('itemPagBtns',res.page,res.pages,'loadDeliveryItems')})}
function updateDeliveryItem(id){post('update_item',{id,payment_collected:document.getElementById('pay_'+id).value,payment_method:document.getElementById('method_'+id).value,delivery_status:document.getElementById('st_'+id).value,remarks:document.getElementById('rem_'+id).value}).then(r=>{toast(r.message,r.success?'success':'error');loadDeliveryItems(itemPage);loadDeliveries(delPage)})}

document.addEventListener('DOMContentLoaded',()=>{loadDrivers();loadStats();loadRoutes(1)});
</script>
</body>
</html>
