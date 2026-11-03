<?php
session_start();

header('Content-Type: application/json');

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id']) || empty($_SESSION['client_id'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'expired',
        'message' => 'Login required',
        'redirect' => '/onerp/app/onerp/app/public/app/views/login_view.php'
    ]);
    exit;
}
$logged_user_id   = (int)$_SESSION['user_id'];
$logged_client_id = (int)$_SESSION['client_id'];

if ($logged_client_id <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid client session'
    ]);
    exit;
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ? 
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

$feature_labels = [
    'dashboard' => 'Dashboard',
    'invoices' => 'Invoices / Billing',
    'pos_sales' => 'POS Sales',
    'inventory' => 'Products / Inventory',
    'inventory_adjustments' => 'Inventory Adjustments',
    'customers' => 'Customers',
    'suppliers' => 'Suppliers / Purchase Orders',
    'users' => 'Users / Staff',
    'reports' => 'Reports / Analytics',
    'wholesale_module' => 'Wholesale Module',
    'fbr_integration' => 'FBR Integration',
    'tables' => 'Restaurant Tables',
    'reservations' => 'Table Reservations',
    'recipes' => 'Recipes Management',
    'memberships' => 'Customer Memberships',
    'appointments' => 'Appointments',
    'attendance' => 'Staff Attendance',
    'commissions' => 'Commissions',
    'supplier_management' => 'Supplier Management',
    'supplier_ledger' => 'Supplier Ledger',
    'customer_ledger' => 'Customer Ledger',
    'profit_loss' => 'Profit & Loss',
    'daily_sales' => 'Daily Sales',
    'cashier_shift' => 'Cashier Shift',
    'low_stock' => 'Low Stock',
    'tax_summary' => 'Tax Summary',
    'discounts' => 'Discounts / Coupons',
    'taxes' => 'Taxes / VAT',
    'multi_branch' => 'Multi-Branch',
    'membership' => 'Membership / Subscription',
    'payments' => 'Payments / Gateways',
    'expenses' => 'Expenses / Costs',
    'returns' => 'Returns / Refunds',
    'notifications' => 'Notifications',
    'settings' => 'Settings / Configurations',
    'integrations' => 'Integrations',
    'barcode' => 'Barcode / QR',
    'mobile_access' => 'Mobile / Web Access',
    'products' => 'Products / Items',
    'item_variants' => 'Item Variants',
    'water_delivery' => 'Water Delivery',
    'delivery_routes' => 'Delivery Routes',
    'dispatch' => 'Dispatch',
    'empty_bottles' => 'Empty Bottles',
    'delivery_assign' => 'Delivery Assign',
    'distribution' => 'Distribution',
    'distribution_dashboard' => 'Distribution Dashboard',
    'distribution_orders' => 'Distribution Orders',
    'distribution_reports' => 'Distribution Reports'
];

function getClientAllowedFeatures(mysqli $conn, int $client_id, array $feature_labels): array
{
    $all_keys = array_keys($feature_labels);
    $allowed = [];

    if (tableExists($conn, 'subscriptions') && tableExists($conn, 'plan_features')) {
        if (columnExists($conn, 'subscriptions', 'client_id') && columnExists($conn, 'subscriptions', 'plan_id')) {
            $statusWhere = '';

            if (columnExists($conn, 'subscriptions', 'status')) {
                $statusWhere = " AND status IN ('active','trial','paid','running')";
            }

            $sql = "SELECT plan_id 
                    FROM subscriptions 
                    WHERE client_id = ? {$statusWhere}
                    ORDER BY id DESC 
                    LIMIT 1";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param("i", $client_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();

                if (!empty($row['plan_id'])) {
                    $plan_id = (int)$row['plan_id'];

                    $enabledCol = null;

                    if (columnExists($conn, 'plan_features', 'enabled')) {
                        $enabledCol = 'enabled';
                    } elseif (columnExists($conn, 'plan_features', 'is_enabled')) {
                        $enabledCol = 'is_enabled';
                    }

                    $enabledWhere = $enabledCol ? " AND {$enabledCol} = 1" : "";

                    $pfSql = "SELECT feature_key 
                              FROM plan_features 
                              WHERE plan_id = ? {$enabledWhere}";

                    $stmt = $conn->prepare($pfSql);

                    if ($stmt) {
                        $stmt->bind_param("i", $plan_id);
                        $stmt->execute();
                        $res = $stmt->get_result();

                        while ($r = $res->fetch_assoc()) {
                            if (!empty($r['feature_key'])) {
                                $allowed[] = $r['feature_key'];
                            }
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }

    if (tableExists($conn, 'client_feature_overrides')) {
        $enabledCol = null;

        if (columnExists($conn, 'client_feature_overrides', 'enabled')) {
            $enabledCol = 'enabled';
        } elseif (columnExists($conn, 'client_feature_overrides', 'is_enabled')) {
            $enabledCol = 'is_enabled';
        }

        if ($enabledCol && columnExists($conn, 'client_feature_overrides', 'client_id')) {
            $sql = "SELECT feature_key, {$enabledCol} AS is_allowed 
                    FROM client_feature_overrides 
                    WHERE client_id = ?";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param("i", $client_id);
                $stmt->execute();
                $res = $stmt->get_result();

                while ($r = $res->fetch_assoc()) {
                    $key = (string)$r['feature_key'];

                    if ((int)$r['is_allowed'] === 1) {
                        $allowed[] = $key;
                    } else {
                        $allowed = array_values(array_diff($allowed, [$key]));
                    }
                }

                $stmt->close();
            }
        }
    }

    $allowed = array_values(array_unique($allowed));

    if (empty($allowed)) {
        $allowed = $all_keys;
    }

    return array_values(array_intersect($allowed, $all_keys));
}

function respond(string $status, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $extra));
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $permissions = $_POST['permissions'] ?? [];

    if (!is_array($permissions)) {
        $permissions = [];
    }

    if ($name === '') {
        respond('error', 'Name is required');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond('error', 'Valid email is required');
    }

    if ($phone === '') {
        respond('error', 'Phone is required');
    }

    if ($user_id <= 0 && trim($password) === '') {
        respond('error', 'Password is required for new staff');
    }

    $allowed_features = getClientAllowedFeatures($conn, $logged_client_id, $feature_labels);

    $permissions = array_values(array_unique(array_filter($permissions, function ($key) use ($allowed_features) {
        return in_array($key, $allowed_features, true);
    })));

    $checkSql = "SELECT id 
                 FROM users 
                 WHERE email = ? 
                 AND client_id = ?";

    if ($user_id > 0) {
        $checkSql .= " AND id <> ?";
    }

    $checkSql .= " LIMIT 1";

    $stmt = $conn->prepare($checkSql);

    if (!$stmt) {
        respond('error', 'Email check failed: ' . $conn->error);
    }

    if ($user_id > 0) {
        $stmt->bind_param("sii", $email, $logged_client_id, $user_id);
    } else {
        $stmt->bind_param("si", $email, $logged_client_id);
    }

    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        respond('error', 'This email already exists for this client');
    }

    $stmt->close();

    $passwordColumn = null;

    if (columnExists($conn, 'users', 'password')) {
        $passwordColumn = 'password';
    } elseif (columnExists($conn, 'users', 'password_hash')) {
        $passwordColumn = 'password_hash';
    }

    $roleCol = columnExists($conn, 'users', 'role') ? 'role' : null;
    $statusCol = columnExists($conn, 'users', 'status') ? 'status' : null;
    $activeCol = columnExists($conn, 'users', 'is_active') ? 'is_active' : null;

    if ($user_id > 0) {
        $ownSql = "SELECT id FROM users WHERE id = ? AND client_id = ? LIMIT 1";
        $stmt = $conn->prepare($ownSql);

        if (!$stmt) {
            respond('error', 'Ownership check failed: ' . $conn->error);
        }

        $stmt->bind_param("ii", $user_id, $logged_client_id);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $stmt->close();
            respond('error', 'User not found in your client account');
        }

        $stmt->close();

        if ($passwordColumn && trim($password) !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "UPDATE users 
                    SET name = ?, email = ?, phone = ?, {$passwordColumn} = ? 
                    WHERE id = ? AND client_id = ?";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                respond('error', 'Update failed: ' . $conn->error);
            }

            $stmt->bind_param("ssssii", $name, $email, $phone, $hash, $user_id, $logged_client_id);
        } else {
            $sql = "UPDATE users 
                    SET name = ?, email = ?, phone = ? 
                    WHERE id = ? AND client_id = ?";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                respond('error', 'Update failed: ' . $conn->error);
            }

            $stmt->bind_param("sssii", $name, $email, $phone, $user_id, $logged_client_id);
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond('error', 'User update error: ' . $err);
        }

        $stmt->close();

    } else {
        if (!$passwordColumn) {
            respond('error', 'Password column not found in users table');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $cols = ['client_id', 'name', 'email', 'phone', $passwordColumn];
        $vals = ['?', '?', '?', '?', '?'];
        $types = 'issss';
        $params = [$logged_client_id, $name, $email, $phone, $hash];

        if ($roleCol) {
            $cols[] = $roleCol;
            $vals[] = '?';
            $types .= 's';
            $params[] = 'Employee';
        }

        if ($statusCol) {
            $cols[] = $statusCol;
            $vals[] = '?';
            $types .= 's';
            $params[] = 'active';
        }

        if ($activeCol) {
            $cols[] = $activeCol;
            $vals[] = '?';
            $types .= 'i';
            $params[] = 1;
        }

        $sql = "INSERT INTO users (" . implode(',', $cols) . ") 
                VALUES (" . implode(',', $vals) . ")";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            respond('error', 'Insert failed: ' . $conn->error);
        }

        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond('error', 'User insert error: ' . $err);
        }

        $user_id = (int)$stmt->insert_id;
        $stmt->close();
    }

    if (tableExists($conn, 'users_permissions')) {
        $delSql = "DELETE up 
                   FROM users_permissions up
                   INNER JOIN users u ON u.id = up.user_id
                   WHERE up.user_id = ? 
                   AND u.client_id = ?";

        $stmt = $conn->prepare($delSql);

        if (!$stmt) {
            respond('error', 'Permission delete failed: ' . $conn->error);
        }

        $stmt->bind_param("ii", $user_id, $logged_client_id);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond('error', 'Permission delete error: ' . $err);
        }

        $stmt->close();

        if (!empty($permissions)) {
            $hasClientCol = columnExists($conn, 'users_permissions', 'client_id');

            foreach ($permissions as $feature_key) {
                if ($hasClientCol) {
                    $pSql = "INSERT INTO users_permissions (client_id, user_id, feature_key) 
                             VALUES (?, ?, ?)";

                    $stmt = $conn->prepare($pSql);

                    if (!$stmt) {
                        respond('error', 'Permission insert failed: ' . $conn->error);
                    }

                    $stmt->bind_param("iis", $logged_client_id, $user_id, $feature_key);
                } else {
                    $pSql = "INSERT INTO users_permissions (user_id, feature_key) 
                             VALUES (?, ?)";

                    $stmt = $conn->prepare($pSql);

                    if (!$stmt) {
                        respond('error', 'Permission insert failed: ' . $conn->error);
                    }

                    $stmt->bind_param("is", $user_id, $feature_key);
                }

                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    respond('error', 'Permission insert error: ' . $err);
                }

                $stmt->close();
            }
        }
    }

    respond('ok', 'Staff saved successfully', [
        'id' => $user_id
    ]);
}

if ($action === 'delete') {
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id <= 0) {
        respond('error', 'Invalid user ID');
    }

    if ($user_id === $logged_user_id) {
        respond('error', 'You cannot delete your own logged-in user');
    }

    $ownSql = "SELECT id FROM users WHERE id = ? AND client_id = ? LIMIT 1";
    $stmt = $conn->prepare($ownSql);

    if (!$stmt) {
        respond('error', 'Ownership check failed: ' . $conn->error);
    }

    $stmt->bind_param("ii", $user_id, $logged_client_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        $stmt->close();
        respond('error', 'User not found in your client account');
    }

    $stmt->close();

    if (tableExists($conn, 'users_permissions')) {
        $delPermSql = "DELETE up 
                       FROM users_permissions up
                       INNER JOIN users u ON u.id = up.user_id
                       WHERE up.user_id = ? 
                       AND u.client_id = ?";

        $stmt = $conn->prepare($delPermSql);

        if (!$stmt) {
            respond('error', 'Permission delete failed: ' . $conn->error);
        }

        $stmt->bind_param("ii", $user_id, $logged_client_id);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond('error', 'Permission delete error: ' . $err);
        }

        $stmt->close();
    }

    $delSql = "DELETE FROM users WHERE id = ? AND client_id = ?";
    $stmt = $conn->prepare($delSql);

    if (!$stmt) {
        respond('error', 'User delete failed: ' . $conn->error);
    }

    $stmt->bind_param("ii", $user_id, $logged_client_id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        respond('error', 'User delete error: ' . $err);
    }

    $stmt->close();

    respond('ok', 'Staff deleted successfully');
}

respond('error', 'Invalid action');