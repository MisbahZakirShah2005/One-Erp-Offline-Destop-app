<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/woocommerce_helper.php'
);

/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$loggedUserId   = (int)($_SESSION['user_id'] ?? 0);
$loggedRoleId   = (int)($_SESSION['role_id'] ?? 0);
$loggedClientId = (int)($_SESSION['client_id'] ?? 0);

if ($loggedClientId <= 0) {
    http_response_code(403);
    exit('Invalid client session.');
}

/* ================= SIDEBAR / EXISTING PERMISSIONS ================= */
ob_start();
include(__DIR__ . '/partials/sidebar.php');
$sidebarHtml = ob_get_clean();

$hasWooPermission = function_exists('can_show')
    && can_show('woocommerce_inventory_sync');

$wooConnectionActive = isWooCommerceActive($conn, $loggedClientId);

if (!$hasWooPermission || !$wooConnectionActive) {
    $_SESSION['integration_error'] = !$hasWooPermission
        ? 'Aapke active plan ya staff permission mein WooCommerce Inventory Sync enabled nahi hai.'
        : 'WooCommerce integration Active aur Connected nahi hai.';

    header('Location: /public/app/views/integrations.php');
    exit;
}

$wooConnection = getWooCommerceConnection(
    $conn,
    $loggedClientId,
    0,
    true
);

if (!$wooConnection) {
    $_SESSION['integration_error'] = 'WooCommerce connection record nahi mila.';
    header('Location: /public/app/views/integrations.php');
    exit;
}

$integrationId = (int)$wooConnection['id'];
$storeUrl = rtrim((string)$wooConnection['store_url'], '/');
$wooAdminProductsUrl = $storeUrl . '/wp-admin/edit.php?post_type=product';

/* ================= CSRF ================= */
if (empty($_SESSION['woo_inventory_csrf'])) {
    $_SESSION['woo_inventory_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['woo_inventory_csrf'];

/* ================= LOCAL HELPERS ================= */
if (!function_exists('wooInventoryJsonResponse')) {
    function wooInventoryJsonResponse(array $payload, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

if (!function_exists('wooInventoryBindParams')) {
    function wooInventoryBindParams(
        mysqli_stmt $stmt,
        string $types,
        array &$values
    ): bool {
        if ($types === '' || empty($values)) {
            return true;
        }

        $params = [];
        $params[] = &$types;

        foreach ($values as $index => &$value) {
            $params[] = &$value;
        }
        unset($value);

        return (bool)call_user_func_array([$stmt, 'bind_param'], $params);
    }
}

if (!function_exists('wooInventoryShortText')) {
    function wooInventoryShortText(string $value, int $limit = 110): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $limit
                ? mb_substr($value, 0, $limit, 'UTF-8') . '...'
                : $value;
        }

        return strlen($value) > $limit
            ? substr($value, 0, $limit) . '...'
            : $value;
    }
}

if (!function_exists('wooInventoryQueryUrl')) {
    function wooInventoryQueryUrl(array $changes = []): string
    {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return '?' . http_build_query($query);
    }
}

if (!function_exists('wooInventoryFetchItem')) {
    function wooInventoryFetchItem(
        mysqli $conn,
        int $clientId,
        int $integrationId,
        int $itemId
    ): ?array {
        if ($clientId <= 0 || $integrationId <= 0 || $itemId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                i.id,
                i.name,
                i.sku,
                i.stock_qty,
                i.track_inventory,
                i.low_stock_alert,
                i.status,
                i.has_variants,
                m.woo_product_id,
                m.woo_product_type,
                m.sync_status,
                m.last_synced_at,
                m.last_error
            FROM items i
            INNER JOIN woocommerce_product_mappings m
                ON m.item_id = i.id
               AND m.client_id = i.client_id
            WHERE i.id = ?
              AND i.client_id = ?
              AND m.integration_id = ?
              AND m.woo_product_id IS NOT NULL
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('iii', $itemId, $clientId, $integrationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('wooInventoryUpdateMapping')) {
    function wooInventoryUpdateMapping(
        mysqli $conn,
        int $clientId,
        int $integrationId,
        int $itemId,
        bool $success,
        string $errorMessage = ''
    ): bool {
        if (!wooTableExists($conn, 'woocommerce_product_mappings')) {
            return false;
        }

        $sets = [];
        $types = '';
        $values = [];

        if (wooColumnExists($conn, 'woocommerce_product_mappings', 'sync_status')) {
            $sets[] = 'sync_status = ?';
            $types .= 's';
            $values[] = $success ? 'synced' : 'failed';
        }

        if (wooColumnExists($conn, 'woocommerce_product_mappings', 'last_error')) {
            $sets[] = 'last_error = ?';
            $types .= 's';
            $values[] = $success ? null : wooTruncateText($errorMessage, 20000);
        }

        if ($success && wooColumnExists($conn, 'woocommerce_product_mappings', 'last_synced_at')) {
            $sets[] = 'last_synced_at = NOW()';
        }

        if (wooColumnExists($conn, 'woocommerce_product_mappings', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }

        if (empty($sets)) {
            return false;
        }

        $types .= 'iii';
        $values[] = $clientId;
        $values[] = $integrationId;
        $values[] = $itemId;

        $sql = 'UPDATE woocommerce_product_mappings SET '
            . implode(', ', $sets)
            . ' WHERE client_id = ? AND integration_id = ? AND item_id = ?';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        wooInventoryBindParams($stmt, $types, $values);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('wooInventoryTouchSettings')) {
    function wooInventoryTouchSettings(
        mysqli $conn,
        int $clientId,
        int $integrationId
    ): void {
        if (
            !wooTableExists($conn, 'woocommerce_sync_settings')
            || !wooColumnExists(
                $conn,
                'woocommerce_sync_settings',
                'last_inventory_sync_at'
            )
        ) {
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO woocommerce_sync_settings
                (client_id, integration_id, last_inventory_sync_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                last_inventory_sync_at = NOW()
        ");

        if ($stmt) {
            $stmt->bind_param('ii', $clientId, $integrationId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('wooInventoryPushToWoo')) {
    function wooInventoryPushToWoo(
        mysqli $conn,
        array $connection,
        int $clientId,
        int $integrationId,
        int $itemId
    ): array {
        $item = wooInventoryFetchItem(
            $conn,
            $clientId,
            $integrationId,
            $itemId
        );

        if (!$item) {
            return [
                'success' => false,
                'message' => 'Mapped ONEERP product nahi mila.'
            ];
        }

        $wooProductId = (int)($item['woo_product_id'] ?? 0);
        if ($wooProductId <= 0) {
            return [
                'success' => false,
                'message' => 'WooCommerce Product ID missing hai. Pehle Product Sync karein.'
            ];
        }

        $trackInventory = (int)($item['track_inventory'] ?? 0) === 1;
        $localQuantity = max(0, (float)($item['stock_qty'] ?? 0));
        $wooQuantity = (int)round($localQuantity);

        $payload = [
            'manage_stock' => $trackInventory,
            'stock_status' => $trackInventory
                ? ($wooQuantity > 0 ? 'instock' : 'outofstock')
                : 'instock'
        ];

        if ($trackInventory) {
            $payload['stock_quantity'] = $wooQuantity;
        }

        $result = wooCommerceRequestWithConnection(
            $connection,
            'PUT',
            'products/' . $wooProductId,
            $payload
        );

        $success = !empty($result['success'])
            && !empty($result['data']['id']);

        if (!$success) {
            $message = trim((string)($result['message'] ?? 'Inventory push failed.'));

            wooInventoryUpdateMapping(
                $conn,
                $clientId,
                $integrationId,
                $itemId,
                false,
                $message
            );

            writeWooCommerceLog(
                $conn,
                $clientId,
                'inventory_push_failed',
                [
                    'item_id' => $itemId,
                    'woo_product_id' => $wooProductId,
                    'payload' => $payload
                ],
                [
                    'message' => $message,
                    'response' => $result['data'] ?? null
                ],
                false,
                $integrationId,
                $itemId,
                (int)($result['http_code'] ?? 0)
            );

            return [
                'success' => false,
                'message' => $message,
                'item_id' => $itemId,
                'woo_product_id' => $wooProductId,
                'http_code' => (int)($result['http_code'] ?? 0)
            ];
        }

        $remoteData = (array)$result['data'];

        wooInventoryUpdateMapping(
            $conn,
            $clientId,
            $integrationId,
            $itemId,
            true,
            ''
        );
        wooInventoryTouchSettings($conn, $clientId, $integrationId);
        wooTouchLastSync($conn, $integrationId, $clientId);

        writeWooCommerceLog(
            $conn,
            $clientId,
            'inventory_push',
            [
                'item_id' => $itemId,
                'woo_product_id' => $wooProductId,
                'local_stock_qty' => $localQuantity,
                'payload' => $payload
            ],
            [
                'remote_stock_quantity' => $remoteData['stock_quantity'] ?? null,
                'manage_stock' => $remoteData['manage_stock'] ?? null,
                'stock_status' => $remoteData['stock_status'] ?? null
            ],
            true,
            $integrationId,
            $itemId,
            (int)($result['http_code'] ?? 200)
        );

        return [
            'success' => true,
            'message' => 'ONEERP stock WooCommerce par successfully update ho gaya.',
            'item_id' => $itemId,
            'woo_product_id' => $wooProductId,
            'local_stock_qty' => $localQuantity,
            'remote_stock_quantity' => $remoteData['stock_quantity'] ?? null,
            'manage_stock' => $remoteData['manage_stock'] ?? null,
            'stock_status' => $remoteData['stock_status'] ?? null,
            'date_modified' => $remoteData['date_modified'] ?? null
        ];
    }
}

if (!function_exists('wooInventoryCheckRemote')) {
    function wooInventoryCheckRemote(
        mysqli $conn,
        array $connection,
        int $clientId,
        int $integrationId,
        int $itemId
    ): array {
        $item = wooInventoryFetchItem(
            $conn,
            $clientId,
            $integrationId,
            $itemId
        );

        if (!$item) {
            return [
                'success' => false,
                'message' => 'Mapped ONEERP product nahi mila.'
            ];
        }

        $wooProductId = (int)($item['woo_product_id'] ?? 0);
        $result = wooCommerceRequestWithConnection(
            $connection,
            'GET',
            'products/' . $wooProductId
        );

        if (empty($result['success']) || empty($result['data']['id'])) {
            $message = trim((string)($result['message'] ?? 'Remote stock check failed.'));

            writeWooCommerceLog(
                $conn,
                $clientId,
                'inventory_check_failed',
                [
                    'item_id' => $itemId,
                    'woo_product_id' => $wooProductId
                ],
                ['message' => $message],
                false,
                $integrationId,
                $itemId,
                (int)($result['http_code'] ?? 0)
            );

            return [
                'success' => false,
                'message' => $message,
                'item_id' => $itemId,
                'woo_product_id' => $wooProductId
            ];
        }

        $remoteData = (array)$result['data'];

        return [
            'success' => true,
            'message' => 'WooCommerce stock successfully load ho gaya.',
            'item_id' => $itemId,
            'woo_product_id' => $wooProductId,
            'remote_stock_quantity' => $remoteData['stock_quantity'] ?? null,
            'manage_stock' => (bool)($remoteData['manage_stock'] ?? false),
            'stock_status' => (string)($remoteData['stock_status'] ?? ''),
            'product_type' => (string)($remoteData['type'] ?? ''),
            'date_modified' => $remoteData['date_modified'] ?? null,
            'permalink' => $remoteData['permalink'] ?? ''
        ];
    }
}

if (!function_exists('wooInventoryPullFromWoo')) {
    function wooInventoryPullFromWoo(
        mysqli $conn,
        array $connection,
        int $clientId,
        int $integrationId,
        int $itemId
    ): array {
        $item = wooInventoryFetchItem(
            $conn,
            $clientId,
            $integrationId,
            $itemId
        );

        if (!$item) {
            return [
                'success' => false,
                'message' => 'Mapped ONEERP product nahi mila.'
            ];
        }

        $wooProductId = (int)($item['woo_product_id'] ?? 0);
        $result = wooCommerceRequestWithConnection(
            $connection,
            'GET',
            'products/' . $wooProductId
        );

        if (empty($result['success']) || empty($result['data']['id'])) {
            $message = trim((string)($result['message'] ?? 'WooCommerce stock pull failed.'));

            writeWooCommerceLog(
                $conn,
                $clientId,
                'inventory_pull_failed',
                [
                    'item_id' => $itemId,
                    'woo_product_id' => $wooProductId
                ],
                ['message' => $message],
                false,
                $integrationId,
                $itemId,
                (int)($result['http_code'] ?? 0)
            );

            return [
                'success' => false,
                'message' => $message
            ];
        }

        $remoteData = (array)$result['data'];
        $manageStock = !empty($remoteData['manage_stock']);
        $remoteQuantity = $remoteData['stock_quantity'] ?? null;

        if ($manageStock && $remoteQuantity === null) {
            return [
                'success' => false,
                'message' => 'WooCommerce product stock quantity return nahi kar raha. Variable product variations ka stock alag manage ho sakta hai.'
            ];
        }

        $sets = ['track_inventory = ?'];
        $types = 'i';
        $values = [$manageStock ? 1 : 0];

        if ($manageStock) {
            $sets[] = 'stock_qty = ?';
            $types .= 'd';
            $values[] = max(0, (float)$remoteQuantity);
        }

        if (wooColumnExists($conn, 'items', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }

        $types .= 'ii';
        $values[] = $itemId;
        $values[] = $clientId;

        $sql = 'UPDATE items SET '
            . implode(', ', $sets)
            . ' WHERE id = ? AND client_id = ?';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [
                'success' => false,
                'message' => 'ONEERP stock update query prepare nahi ho saki.'
            ];
        }

        wooInventoryBindParams($stmt, $types, $values);
        $updated = $stmt->execute();
        $stmt->close();

        if (!$updated) {
            return [
                'success' => false,
                'message' => 'WooCommerce stock ONEERP mein save nahi ho saka.'
            ];
        }

        wooInventoryUpdateMapping(
            $conn,
            $clientId,
            $integrationId,
            $itemId,
            true,
            ''
        );
        wooInventoryTouchSettings($conn, $clientId, $integrationId);
        wooTouchLastSync($conn, $integrationId, $clientId);

        writeWooCommerceLog(
            $conn,
            $clientId,
            'inventory_pull',
            [
                'item_id' => $itemId,
                'woo_product_id' => $wooProductId
            ],
            [
                'old_local_stock_qty' => (float)($item['stock_qty'] ?? 0),
                'new_local_stock_qty' => $manageStock
                    ? max(0, (float)$remoteQuantity)
                    : (float)($item['stock_qty'] ?? 0),
                'track_inventory' => $manageStock ? 1 : 0,
                'stock_status' => $remoteData['stock_status'] ?? null
            ],
            true,
            $integrationId,
            $itemId,
            (int)($result['http_code'] ?? 200)
        );

        return [
            'success' => true,
            'message' => $manageStock
                ? 'WooCommerce stock ONEERP mein successfully update ho gaya.'
                : 'WooCommerce par stock management disabled hai; ONEERP inventory tracking bhi disable kar di gayi hai.',
            'item_id' => $itemId,
            'woo_product_id' => $wooProductId,
            'local_stock_qty' => $manageStock
                ? max(0, (float)$remoteQuantity)
                : (float)($item['stock_qty'] ?? 0),
            'manage_stock' => $manageStock,
            'stock_status' => $remoteData['stock_status'] ?? null,
            'date_modified' => $remoteData['date_modified'] ?? null
        ];
    }
}

/* ================= AJAX BACKEND ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $requestToken = (string)($_POST['csrf_token'] ?? '');

    if ($requestToken === '' || !hash_equals($csrfToken, $requestToken)) {
        wooInventoryJsonResponse([
            'success' => false,
            'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
        ], 419);
    }

    if (!can_show('woocommerce_inventory_sync')) {
        wooInventoryJsonResponse([
            'success' => false,
            'message' => 'WooCommerce Inventory Sync permission available nahi hai.'
        ], 403);
    }

    if (!isWooCommerceActive($conn, $loggedClientId)) {
        wooInventoryJsonResponse([
            'success' => false,
            'message' => 'WooCommerce integration Active aur Connected nahi hai.',
            'redirect' => '/onerp/app/public/app/views/integrations.php'
        ], 403);
    }

    if (!wooTableExists($conn, 'woocommerce_product_mappings')) {
        wooInventoryJsonResponse([
            'success' => false,
            'message' => 'woocommerce_product_mappings table missing hai.'
        ], 500);
    }

    $action = trim((string)$_POST['ajax']);
    $itemId = (int)($_POST['item_id'] ?? 0);

    if ($itemId <= 0) {
        wooInventoryJsonResponse([
            'success' => false,
            'message' => 'Invalid item ID.'
        ], 422);
    }

    try {
        if ($action === 'push_stock') {
            $result = wooInventoryPushToWoo(
                $conn,
                $wooConnection,
                $loggedClientId,
                $integrationId,
                $itemId
            );

            wooInventoryJsonResponse(
                $result,
                !empty($result['success']) ? 200 : 422
            );
        }

        if ($action === 'pull_stock') {
            $result = wooInventoryPullFromWoo(
                $conn,
                $wooConnection,
                $loggedClientId,
                $integrationId,
                $itemId
            );

            wooInventoryJsonResponse(
                $result,
                !empty($result['success']) ? 200 : 422
            );
        }

        if ($action === 'check_remote') {
            $result = wooInventoryCheckRemote(
                $conn,
                $wooConnection,
                $loggedClientId,
                $integrationId,
                $itemId
            );

            wooInventoryJsonResponse(
                $result,
                !empty($result['success']) ? 200 : 422
            );
        }

        wooInventoryJsonResponse([
            'success' => false,
            'message' => 'Unsupported inventory action.'
        ], 400);
    } catch (Throwable $e) {
        writeWooCommerceLog(
            $conn,
            $loggedClientId,
            'inventory_sync_exception',
            [
                'action' => $action,
                'item_id' => $itemId
            ],
            ['message' => $e->getMessage()],
            false,
            $integrationId,
            $itemId,
            0
        );

        wooInventoryJsonResponse([
            'success' => false,
            'message' => 'Inventory sync exception: ' . $e->getMessage()
        ], 500);
    }
}

/* ================= DATABASE REQUIREMENTS ================= */
$mappingTableReady = wooTableExists($conn, 'woocommerce_product_mappings');
$pageError = '';

if (!$mappingTableReady) {
    $pageError = 'woocommerce_product_mappings table missing hai. Pehle WooCommerce database migration run karein.';
}

/* ================= FILTERS ================= */
$search = trim((string)($_GET['search'] ?? ''));
$stockFilter = trim((string)($_GET['stock'] ?? ''));
$syncFilter = trim((string)($_GET['sync_status'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'latest'));
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedPerPage = [10, 25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 25);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

$allowedStockFilters = ['', 'in_stock', 'low_stock', 'out_of_stock', 'not_tracked'];
if (!in_array($stockFilter, $allowedStockFilters, true)) {
    $stockFilter = '';
}

$allowedSyncFilters = ['', 'synced', 'failed', 'pending'];
if (!in_array($syncFilter, $allowedSyncFilters, true)) {
    $syncFilter = '';
}

$sortMap = [
    'latest' => 'COALESCE(m.last_synced_at, m.created_at) DESC, i.id DESC',
    'name_asc' => 'i.name ASC, i.id DESC',
    'name_desc' => 'i.name DESC, i.id DESC',
    'stock_low' => 'i.stock_qty ASC, i.name ASC',
    'stock_high' => 'i.stock_qty DESC, i.name ASC',
    'woo_id' => 'm.woo_product_id DESC, i.id DESC'
];

if (!isset($sortMap[$sort])) {
    $sort = 'latest';
}

/* ================= STATS / TABLE DATA ================= */
$stats = [
    'mapped' => 0,
    'in_stock' => 0,
    'low_stock' => 0,
    'out_of_stock' => 0
];
$products = [];
$totalRows = 0;
$totalPages = 1;

if ($mappingTableReady) {
    $statsStmt = $conn->prepare("
        SELECT
            COUNT(*) AS mapped,
            SUM(CASE
                WHEN i.track_inventory = 1 AND i.stock_qty > 0
                THEN 1 ELSE 0 END
            ) AS in_stock,
            SUM(CASE
                WHEN i.track_inventory = 1
                 AND i.stock_qty > 0
                 AND i.stock_qty <= i.low_stock_alert
                THEN 1 ELSE 0 END
            ) AS low_stock,
            SUM(CASE
                WHEN i.track_inventory = 1 AND i.stock_qty <= 0
                THEN 1 ELSE 0 END
            ) AS out_of_stock
        FROM items i
        INNER JOIN woocommerce_product_mappings m
            ON m.item_id = i.id
           AND m.client_id = i.client_id
        WHERE i.client_id = ?
          AND m.integration_id = ?
          AND m.woo_product_id IS NOT NULL
          AND (i.deleted_at IS NULL)
    ");

    if ($statsStmt) {
        $statsStmt->bind_param('ii', $loggedClientId, $integrationId);
        $statsStmt->execute();
        $statsResult = $statsStmt->get_result();
        $statsRow = $statsResult ? $statsResult->fetch_assoc() : [];
        $statsStmt->close();

        foreach ($stats as $key => $value) {
            $stats[$key] = (int)($statsRow[$key] ?? 0);
        }
    }

    $where = [
        'i.client_id = ?',
        'm.integration_id = ?',
        'm.woo_product_id IS NOT NULL',
        'i.deleted_at IS NULL'
    ];
    $types = 'ii';
    $values = [$loggedClientId, $integrationId];

    if ($search !== '') {
        $where[] = '(i.name LIKE ? OR i.sku LIKE ? OR CAST(m.woo_product_id AS CHAR) LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sss';
        $values[] = $like;
        $values[] = $like;
        $values[] = $like;
    }

    if ($stockFilter === 'in_stock') {
        $where[] = 'i.track_inventory = 1 AND i.stock_qty > 0';
    } elseif ($stockFilter === 'low_stock') {
        $where[] = 'i.track_inventory = 1 AND i.stock_qty > 0 AND i.stock_qty <= i.low_stock_alert';
    } elseif ($stockFilter === 'out_of_stock') {
        $where[] = 'i.track_inventory = 1 AND i.stock_qty <= 0';
    } elseif ($stockFilter === 'not_tracked') {
        $where[] = 'i.track_inventory = 0';
    }

    if ($syncFilter !== '') {
        $where[] = 'm.sync_status = ?';
        $types .= 's';
        $values[] = $syncFilter;
    }

    $fromSql = "
        FROM items i
        INNER JOIN woocommerce_product_mappings m
            ON m.item_id = i.id
           AND m.client_id = i.client_id
    ";
    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $countSql = 'SELECT COUNT(*) AS total_rows ' . $fromSql . $whereSql;
    $countStmt = $conn->prepare($countSql);

    if ($countStmt) {
        wooInventoryBindParams($countStmt, $types, $values);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult ? $countResult->fetch_assoc() : [];
        $totalRows = (int)($countRow['total_rows'] ?? 0);
        $countStmt->close();
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $dataSql = "
        SELECT
            i.id,
            i.name,
            i.sku,
            i.category,
            i.image,
            i.stock_qty,
            i.low_stock_alert,
            i.track_inventory,
            i.status AS item_status,
            i.has_variants,
            m.woo_product_id,
            m.woo_product_type,
            m.sync_status,
            m.last_synced_at,
            m.last_error
        " . $fromSql . $whereSql . "
        ORDER BY " . $sortMap[$sort] . "
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataSql);
    if ($dataStmt) {
        $dataTypes = $types . 'ii';
        $dataValues = $values;
        $dataValues[] = $perPage;
        $dataValues[] = $offset;

        wooInventoryBindParams($dataStmt, $dataTypes, $dataValues);
        $dataStmt->execute();
        $dataResult = $dataStmt->get_result();

        while ($row = $dataResult->fetch_assoc()) {
            $products[] = $row;
        }

        $dataStmt->close();
    }
}

$displayFrom = $totalRows > 0 ? (($page - 1) * $perPage) + 1 : 0;
$displayTo = min($totalRows, $page * $perPage);
$lastSyncAt = trim((string)($wooConnection['last_sync_at'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en" data-pos-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WooCommerce Inventory Sync - ONEERP</title>

    <script>
    (function () {
        var selectedTheme = 'light';
        try {
            var savedTheme = localStorage.getItem('oneerp_pos_theme')
                || localStorage.getItem('oneerp_theme')
                || localStorage.getItem('theme');
            if (savedTheme === 'light' || savedTheme === 'dark') {
                selectedTheme = savedTheme;
            }
        } catch (error) {}
        document.documentElement.setAttribute('data-pos-theme', selectedTheme);
    })();
    </script>

    <?php include(__DIR__ . '/partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root{
            --woo-purple:#7f54b3;
            --woo-purple-2:#6d28d9;
            --woo-bg:#070b14;
            --woo-panel:rgba(15,23,42,.72);
            --woo-border:rgba(148,163,184,.16);
            --woo-text:#f8fafc;
            --woo-muted:#94a3b8;
        }
        *{box-sizing:border-box}
        html,body{margin:0;min-height:100%;overflow-x:hidden}
        body{
            background:#070b14;
            color:var(--woo-text);
            font-family:Inter,"Segoe UI",Arial,sans-serif;
        }
        .container-scroll{display:flex;min-height:100vh;width:100%}
        .main-panel{
            margin-left:270px;
            width:calc(100% - 270px);
            min-height:100vh;
            background:
                radial-gradient(circle at 12% 6%,rgba(127,84,179,.30),transparent 28%),
                radial-gradient(circle at 85% 4%,rgba(59,130,246,.13),transparent 30%),
                linear-gradient(135deg,#050914 0%,#0b1024 45%,#160f2d 72%,#090d18 100%);
        }
        .topbar{height:70px;background:#070b14;border-bottom:1px solid var(--woo-border);position:sticky;top:0;z-index:1030}
        .content-wrapper{padding:26px;min-height:calc(100vh - 70px);position:relative}
        .page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:18px}
        .eyebrow{display:inline-flex;align-items:center;gap:8px;color:#c4b5fd;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.09em;margin-bottom:8px}
        .page-title{font-size:30px;font-weight:950;letter-spacing:-.04em;margin:0;color:#fff}
        .page-subtitle{margin:8px 0 0;color:var(--woo-muted);font-size:14px;font-weight:600}
        .header-actions{display:flex;gap:10px;flex-wrap:wrap}
        .btn-woo,.btn-soft,.btn-danger-soft{
            min-height:44px;border-radius:12px;padding:0 16px;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;font-weight:900;font-size:13px;transition:.2s ease;cursor:pointer
        }
        .btn-woo{border:none;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;box-shadow:0 12px 28px rgba(124,58,237,.25)}
        .btn-soft{border:1px solid var(--woo-border);background:rgba(15,23,42,.72);color:#e5e7eb}
        .btn-danger-soft{border:1px solid rgba(239,68,68,.24);background:rgba(239,68,68,.12);color:#fca5a5}
        .btn-woo:hover,.btn-soft:hover,.btn-danger-soft:hover{transform:translateY(-1px);color:#fff}
        .btn-woo:disabled,.btn-soft:disabled{opacity:.45;cursor:not-allowed;transform:none}
        .connection-strip,.glass-card{
            background:radial-gradient(circle at 16% 0%,rgba(124,58,237,.13),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.82),rgba(17,24,39,.64));
            border:1px solid var(--woo-border);border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.26);backdrop-filter:blur(18px)
        }
        .connection-strip{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;margin-bottom:18px;flex-wrap:wrap}
        .connection-main{display:flex;align-items:center;gap:12px;min-width:0}
        .connection-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:rgba(34,197,94,.14);color:#4ade80;font-size:20px}
        .connection-title{font-weight:900;color:#fff}
        .connection-url{font-size:12px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:520px}
        .connected-badge{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:999px;background:rgba(34,197,94,.13);color:#4ade80;border:1px solid rgba(34,197,94,.24);font-size:12px;font-weight:900}
        .stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
        .stat-card{position:relative;overflow:hidden;min-height:126px;padding:18px;border-radius:18px;color:#fff;border:1px solid rgba(255,255,255,.10);box-shadow:0 18px 38px rgba(0,0,0,.25)}
        .stat-card::after{content:"";position:absolute;width:88px;height:88px;right:-18px;top:-18px;border-radius:28px;background:rgba(255,255,255,.10);transform:rotate(22deg)}
        .stat-mapped{background:linear-gradient(135deg,#2563eb,#6d4cff)}
        .stat-stock{background:linear-gradient(135deg,#047857,#16a34a)}
        .stat-low{background:linear-gradient(135deg,#d97706,#f59e0b)}
        .stat-out{background:linear-gradient(135deg,#be123c,#ef4444)}
        .stat-icon{font-size:24px;margin-bottom:12px}.stat-label{font-size:13px;font-weight:800;opacity:.86}.stat-number{font-size:29px;font-weight:950;line-height:1.1;margin-top:4px}
        .filter-card{padding:14px;margin-bottom:16px}
        .filter-grid{display:grid;grid-template-columns:minmax(240px,1.7fr) repeat(3,minmax(150px,.7fr)) auto;gap:10px}
        .search-wrap{position:relative}.search-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8}.search-wrap input{padding-left:40px!important}
        .filter-control{width:100%;height:44px;border-radius:11px;border:1px solid rgba(148,163,184,.18);background:rgba(15,23,42,.76);color:#e5e7eb;padding:0 12px;font-size:13px;font-weight:700;outline:none}
        .filter-control:focus{border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(124,58,237,.14)}
        .filter-control option{background:#0f172a;color:#e5e7eb}
        .toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid var(--woo-border)}
        .toolbar-left,.toolbar-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.selection-count,.result-count{font-size:12px;color:#94a3b8;font-weight:800}
        .table-responsive{overflow:auto}.inventory-table{width:100%;min-width:1260px;border-collapse:separate;border-spacing:0}
        .inventory-table th{padding:13px 12px;background:rgba(7,11,20,.78);color:#cbd5e1;border-bottom:1px solid var(--woo-border);font-size:11px;font-weight:950;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
        .inventory-table td{padding:13px 12px;background:rgba(15,23,42,.28);color:#e5e7eb;border-bottom:1px solid rgba(148,163,184,.09);font-size:13px;font-weight:650;vertical-align:middle}
        .inventory-table tr:hover td{background:rgba(124,58,237,.10)}
        .product-cell{display:flex;align-items:center;gap:11px;min-width:230px}.product-thumb{width:46px;height:46px;border-radius:12px;object-fit:cover;background:#fff;border:1px solid rgba(148,163,184,.18)}
        .product-name{font-weight:900;color:#fff;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.product-meta{font-size:11px;color:#94a3b8;margin-top:3px}
        .stock-number{font-size:17px;font-weight:950}.stock-ok{color:#4ade80}.stock-low{color:#fbbf24}.stock-out{color:#fb7185}.stock-off{color:#94a3b8}
        .status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid transparent}
        .badge-synced{background:rgba(34,197,94,.13);color:#4ade80;border-color:rgba(34,197,94,.22)}
        .badge-failed{background:rgba(239,68,68,.13);color:#f87171;border-color:rgba(239,68,68,.22)}
        .badge-pending{background:rgba(245,158,11,.13);color:#fbbf24;border-color:rgba(245,158,11,.22)}
        .woo-id{font-weight:950;color:#c4b5fd}.remote-stock{min-width:105px}.remote-value{font-weight:950;color:#fff}.remote-meta{display:block;font-size:10px;color:#94a3b8;margin-top:3px;white-space:normal}
        .row-actions{display:flex;gap:6px}.icon-btn{width:38px;height:38px;border-radius:10px;border:1px solid rgba(148,163,184,.18);background:rgba(15,23,42,.72);color:#e5e7eb;display:inline-grid;place-items:center;cursor:pointer}.icon-btn:hover{background:rgba(124,58,237,.18);color:#fff}.icon-btn.pull:hover{background:rgba(59,130,246,.18)}
        .error-text{max-width:220px;white-space:normal;color:#fca5a5;font-size:11px;line-height:1.4}.muted-text{color:#94a3b8;font-size:11px}
        .empty-state,.error-state{text-align:center;padding:54px 20px}.empty-state i,.error-state i{font-size:46px;color:#a78bfa}.empty-state h3,.error-state h3{margin:12px 0 7px;color:#fff;font-weight:950}.empty-state p,.error-state p{margin:0;color:#94a3b8}
        .pagination-wrap{display:flex;justify-content:center;padding:16px}.pagination .page-link{background:rgba(15,23,42,.72);border-color:rgba(148,163,184,.16);color:#cbd5e1}.pagination .page-item.active .page-link{background:#7c3aed;border-color:#7c3aed;color:#fff}
        .sync-overlay{position:fixed;inset:0;z-index:999999;background:rgba(2,6,23,.78);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:20px}.sync-overlay.show{display:flex}.sync-box{width:min(480px,96vw);padding:24px;border-radius:20px;background:linear-gradient(135deg,#0f172a,#17102d);border:1px solid rgba(139,92,246,.35);box-shadow:0 30px 90px rgba(0,0,0,.58);text-align:center}.sync-spinner{width:54px;height:54px;border:5px solid rgba(255,255,255,.12);border-top-color:#a78bfa;border-radius:50%;margin:0 auto 16px;animation:spin .85s linear infinite}.sync-title{font-size:20px;font-weight:950;color:#fff}.sync-text{font-size:13px;color:#94a3b8;margin-top:6px}.progress{height:9px;background:rgba(148,163,184,.13);margin-top:16px}.progress-bar{background:linear-gradient(90deg,#8b5cf6,#6d28d9)}
        .toast-box{position:fixed;right:22px;bottom:22px;z-index:1000000;max-width:min(420px,calc(100vw - 44px));padding:14px 16px;border-radius:13px;background:#0f172a;color:#fff;border:1px solid rgba(148,163,184,.20);box-shadow:0 20px 54px rgba(0,0,0,.4);display:none;font-size:13px;font-weight:750}.toast-box.show{display:block}.toast-box.success{border-color:rgba(34,197,94,.36)}.toast-box.error{border-color:rgba(239,68,68,.38)}
        @keyframes spin{to{transform:rotate(360deg)}}
        @media(max-width:1199px){.stats-grid{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:1fr 1fr 1fr}.filter-grid .search-wrap{grid-column:1/-1}}
        @media(max-width:991px){.main-panel{margin-left:0;width:100%}.content-wrapper{padding:16px}.filter-grid{grid-template-columns:1fr}.stats-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:576px){.stats-grid{grid-template-columns:1fr}.page-title{font-size:25px}.header-actions{width:100%}.header-actions a,.header-actions button{flex:1}.toolbar-left,.toolbar-right{width:100%}.toolbar-left .btn-woo,.toolbar-left .btn-soft{flex:1}}

        html[data-pos-theme="light"] body{background:#f3f6fb;color:#0f172a}
        html[data-pos-theme="light"] body .main-panel{background:radial-gradient(circle at 12% 7%,rgba(124,58,237,.08),transparent 28%),linear-gradient(135deg,#f8fafc,#f1f5f9 52%,#faf7ff)}
        html[data-pos-theme="light"] body .topbar{background:#071a35;border-bottom-color:rgba(148,163,184,.14)}
        html[data-pos-theme="light"] body .page-title,html[data-pos-theme="light"] body .product-name,html[data-pos-theme="light"] body .empty-state h3,html[data-pos-theme="light"] body .error-state h3{color:#0f172a}
        html[data-pos-theme="light"] body .page-subtitle,html[data-pos-theme="light"] body .product-meta,html[data-pos-theme="light"] body .muted-text,html[data-pos-theme="light"] body .selection-count,html[data-pos-theme="light"] body .result-count{color:#64748b}
        html[data-pos-theme="light"] body .connection-strip,html[data-pos-theme="light"] body .glass-card{background:#fff;background-image:none;border-color:#dce4ef;box-shadow:0 12px 32px rgba(15,23,42,.07);backdrop-filter:none}
        html[data-pos-theme="light"] body .connection-title{color:#0f172a}html[data-pos-theme="light"] body .connection-url{color:#64748b}
        html[data-pos-theme="light"] body .filter-control{background:#fff;color:#0f172a;border-color:#cbd5e1}html[data-pos-theme="light"] body .filter-control option{background:#fff;color:#0f172a}
        html[data-pos-theme="light"] body .inventory-table th{background:#f1f5f9;color:#334155;border-color:#cbd5e1}
        html[data-pos-theme="light"] body .inventory-table td{background:#fff;color:#1e293b;border-color:#e8edf4}html[data-pos-theme="light"] body .inventory-table tr:nth-child(even) td{background:#fbfcff}html[data-pos-theme="light"] body .inventory-table tr:hover td{background:#f6f2ff}
        html[data-pos-theme="light"] body .btn-soft,html[data-pos-theme="light"] body .icon-btn{background:#fff;color:#334155;border-color:#cbd5e1;box-shadow:none}
        html[data-pos-theme="light"] body .remote-value{color:#0f172a}html[data-pos-theme="light"] body .stock-off{color:#64748b}
        html[data-pos-theme="light"] body .pagination .page-link{background:#fff;color:#334155;border-color:#dce4ef}
    </style>
</head>
<body>
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?= $sidebarHtml ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php include(__DIR__ . '/partials/topbar.php'); ?>
        </div>

        <main class="content-wrapper">
            <div class="page-header">
                <div>
                    <div class="eyebrow"><i class="bi bi-wordpress"></i> WooCommerce Integration</div>
                    <h1 class="page-title">Inventory Sync</h1>
                    <p class="page-subtitle">ONEERP aur WooCommerce stock ko safely check, push aur pull karein.</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn-woo" id="pullRemoteProductsBtn"><i class="bi bi-cloud-download"></i> Pull Products</button>
                    <button type="button" class="btn-soft" id="pullRemoteOrdersBtn"><i class="bi bi-bag-check"></i> Pull Orders</button>
                    <a href="woocommerce_products.php" class="btn-soft"><i class="bi bi-box-seam"></i> Product Sync</a>
                    <a href="woocommerce_orders.php" class="btn-soft"><i class="bi bi-receipt"></i> Orders</a>
                    <a href="woocommerce_sync_logs.php" class="btn-soft"><i class="bi bi-journal-text"></i> Sync Logs</a>
                    <a href="<?= htmlspecialchars($wooAdminProductsUrl) ?>" target="_blank" rel="noopener" class="btn-soft"><i class="bi bi-box-arrow-up-right"></i> Open WooCommerce</a>
                </div>
            </div>

            <section class="connection-strip">
                <div class="connection-main">
                    <div class="connection-icon"><i class="bi bi-check-circle-fill"></i></div>
                    <div style="min-width:0">
                        <div class="connection-title"><?= htmlspecialchars((string)($wooConnection['integration_name'] ?? 'WooCommerce')) ?></div>
                        <div class="connection-url" title="<?= htmlspecialchars($storeUrl) ?>"><?= htmlspecialchars($storeUrl) ?></div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <?php if ($lastSyncAt !== ''): ?>
                        <span class="muted-text">Last sync: <?= htmlspecialchars($lastSyncAt) ?></span>
                    <?php endif; ?>
                    <span class="connected-badge"><i class="bi bi-shield-check"></i> Active &amp; Connected</span>
                </div>
            </section>

            <section class="stats-grid">
                <article class="stat-card stat-mapped"><div class="stat-icon"><i class="bi bi-link-45deg"></i></div><div class="stat-label">Mapped Products</div><div class="stat-number"><?= number_format($stats['mapped']) ?></div></article>
                <article class="stat-card stat-stock"><div class="stat-icon"><i class="bi bi-box2-check"></i></div><div class="stat-label">In Stock</div><div class="stat-number"><?= number_format($stats['in_stock']) ?></div></article>
                <article class="stat-card stat-low"><div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div><div class="stat-label">Low Stock</div><div class="stat-number"><?= number_format($stats['low_stock']) ?></div></article>
                <article class="stat-card stat-out"><div class="stat-icon"><i class="bi bi-x-octagon"></i></div><div class="stat-label">Out of Stock</div><div class="stat-number"><?= number_format($stats['out_of_stock']) ?></div></article>
            </section>

            <form method="get" class="glass-card filter-card" id="filterForm">
                <div class="filter-grid">
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" class="filter-control" name="search" placeholder="Search name, SKU or Woo ID..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <select class="filter-control" name="stock">
                        <option value="">All Stock</option>
                        <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                        <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="out_of_stock" <?= $stockFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                        <option value="not_tracked" <?= $stockFilter === 'not_tracked' ? 'selected' : '' ?>>Not Tracked</option>
                    </select>
                    <select class="filter-control" name="sync_status">
                        <option value="">All Mapping Status</option>
                        <option value="synced" <?= $syncFilter === 'synced' ? 'selected' : '' ?>>Synced</option>
                        <option value="failed" <?= $syncFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="pending" <?= $syncFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                    <select class="filter-control" name="sort">
                        <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest Sync</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="stock_low" <?= $sort === 'stock_low' ? 'selected' : '' ?>>Stock Low-High</option>
                        <option value="stock_high" <?= $sort === 'stock_high' ? 'selected' : '' ?>>Stock High-Low</option>
                        <option value="woo_id" <?= $sort === 'woo_id' ? 'selected' : '' ?>>Woo ID</option>
                    </select>
                    <button type="submit" class="btn-woo"><i class="bi bi-funnel"></i> Apply</button>
                </div>
                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
            </form>

            <section class="glass-card">
                <?php if ($pageError !== ''): ?>
                    <div class="error-state"><i class="bi bi-database-exclamation"></i><h3>Inventory Sync Setup Incomplete</h3><p><?= htmlspecialchars($pageError) ?></p></div>
                <?php else: ?>
                    <div class="toolbar">
                        <div class="toolbar-left">
                            <button type="button" class="btn-woo" id="pushSelectedBtn" disabled><i class="bi bi-cloud-arrow-up"></i> Push Selected</button>
                            <button type="button" class="btn-soft" id="pullSelectedBtn" disabled><i class="bi bi-cloud-arrow-down"></i> Pull Selected</button>
                            <span class="selection-count" id="selectionCount">0 products selected</span>
                        </div>
                        <div class="toolbar-right">
                            <span class="result-count">Showing <?= number_format($displayFrom) ?>–<?= number_format($displayTo) ?> of <?= number_format($totalRows) ?></span>
                            <select class="filter-control" id="perPageSelect" style="width:110px">
                                <?php foreach ($allowedPerPage as $size): ?>
                                    <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> / page</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if (empty($products)): ?>
                        <div class="empty-state"><i class="bi bi-boxes"></i><h3>No Mapped Products Found</h3><p>Pehle Product Sync page se products WooCommerce par push karein.</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="inventory-table">
                                <thead>
                                    <tr>
                                        <th style="width:48px"><input type="checkbox" class="form-check-input" id="selectAllProducts"></th>
                                        <th>Product</th>
                                        <th>Woo ID</th>
                                        <th>Local Stock</th>
                                        <th>Remote Stock</th>
                                        <th>Tracking</th>
                                        <th>Mapping</th>
                                        <th>Last Sync / Error</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($products as $product): ?>
                                    <?php
                                    $itemId = (int)$product['id'];
                                    $track = (int)$product['track_inventory'] === 1;
                                    $qty = (float)$product['stock_qty'];
                                    $lowAlert = (float)$product['low_stock_alert'];
                                    $stockClass = 'stock-off';
                                    $stockLabel = 'Not tracked';
                                    if ($track) {
                                        if ($qty <= 0) {
                                            $stockClass = 'stock-out';
                                            $stockLabel = 'Out of stock';
                                        } elseif ($qty <= $lowAlert) {
                                            $stockClass = 'stock-low';
                                            $stockLabel = 'Low stock';
                                        } else {
                                            $stockClass = 'stock-ok';
                                            $stockLabel = 'In stock';
                                        }
                                    }
                                    $mappingStatus = strtolower(trim((string)($product['sync_status'] ?? 'pending')));
                                    $mappingClass = $mappingStatus === 'synced' ? 'badge-synced' : ($mappingStatus === 'failed' ? 'badge-failed' : 'badge-pending');
                                    $mappingIcon = $mappingStatus === 'synced' ? 'bi-check-circle' : ($mappingStatus === 'failed' ? 'bi-exclamation-octagon' : 'bi-hourglass-split');
                                    $imageUrl = wooBuildPublicImageUrl((string)($product['image'] ?? ''), ['image_upload_path' => 'uploads/items']);
                                    if ($imageUrl === '') {
                                        $imageUrl = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="100%" height="100%" fill="#eef2ff"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-size="30" fill="#7c3aed">W</text></svg>');
                                    }
                                    ?>
                                    <tr data-item-id="<?= $itemId ?>">
                                        <td><input type="checkbox" class="form-check-input product-checkbox" value="<?= $itemId ?>"></td>
                                        <td>
                                            <div class="product-cell">
                                                <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="product-thumb" loading="lazy">
                                                <div style="min-width:0">
                                                    <div class="product-name" title="<?= htmlspecialchars((string)$product['name']) ?>"><?= htmlspecialchars((string)$product['name']) ?></div>
                                                    <div class="product-meta">SKU: <?= htmlspecialchars((string)($product['sku'] ?: '-')) ?> · <?= htmlspecialchars((string)($product['woo_product_type'] ?? 'simple')) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="woo-id">#<?= (int)$product['woo_product_id'] ?></span></td>
                                        <td>
                                            <div class="stock-number <?= $stockClass ?>" data-local-stock><?= number_format($qty, 2) ?></div>
                                            <div class="muted-text"><?= htmlspecialchars($stockLabel) ?> · Alert <?= number_format($lowAlert, 0) ?></div>
                                        </td>
                                        <td class="remote-stock" data-remote-cell>
                                            <span class="remote-value">Not checked</span>
                                            <span class="remote-meta">Use refresh button</span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $track ? 'badge-synced' : 'badge-pending' ?>">
                                                <i class="bi <?= $track ? 'bi-box-seam' : 'bi-slash-circle' ?>"></i>
                                                <?= $track ? 'Tracked' : 'Not Tracked' ?>
                                            </span>
                                        </td>
                                        <td><span class="status-badge <?= $mappingClass ?>"><i class="bi <?= $mappingIcon ?>"></i><?= htmlspecialchars(ucfirst($mappingStatus)) ?></span></td>
                                        <td>
                                            <?php if (!empty($product['last_error'])): ?>
                                                <div class="error-text" title="<?= htmlspecialchars((string)$product['last_error']) ?>"><?= htmlspecialchars(wooInventoryShortText((string)$product['last_error'], 110)) ?></div>
                                            <?php elseif (!empty($product['last_synced_at'])): ?>
                                                <div><?= htmlspecialchars((string)$product['last_synced_at']) ?></div><div class="muted-text">Last successful sync</div>
                                            <?php else: ?>
                                                <span class="muted-text">No inventory sync yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                <button type="button" class="icon-btn check-stock-btn" data-item-id="<?= $itemId ?>" title="Check WooCommerce stock"><i class="bi bi-arrow-clockwise"></i></button>
                                                <button type="button" class="icon-btn push-stock-btn" data-item-id="<?= $itemId ?>" title="Push ONEERP stock to WooCommerce"><i class="bi bi-cloud-arrow-up"></i></button>
                                                <button type="button" class="icon-btn pull pull-stock-btn" data-item-id="<?= $itemId ?>" title="Pull WooCommerce stock into ONEERP"><i class="bi bi-cloud-arrow-down"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="pagination-wrap">
                                <nav><ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(wooInventoryQueryUrl(['page' => max(1, $page - 1)])) ?>">Previous</a></li>
                                    <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                    ?>
                                        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(wooInventoryQueryUrl(['page' => $p])) ?>"><?= $p ?></a></li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(wooInventoryQueryUrl(['page' => min($totalPages, $page + 1)])) ?>">Next</a></li>
                                </ul></nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<div class="sync-overlay" id="syncOverlay">
    <div class="sync-box">
        <div class="sync-spinner"></div>
        <div class="sync-title" id="syncTitle">Synchronizing Inventory</div>
        <div class="sync-text" id="syncText">Please wait...</div>
        <div class="progress"><div class="progress-bar" id="syncProgress" style="width:0%"></div></div>
    </div>
</div>
<div class="toast-box" id="toastBox"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    var csrfToken = <?= json_encode($csrfToken) ?>;
    var integrationId = <?= (int)$integrationId ?>;
    var overlay = document.getElementById('syncOverlay');
    var syncTitle = document.getElementById('syncTitle');
    var syncText = document.getElementById('syncText');
    var syncProgress = document.getElementById('syncProgress');
    var toastBox = document.getElementById('toastBox');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.product-checkbox'));
    var selectAll = document.getElementById('selectAllProducts');
    var pushSelectedBtn = document.getElementById('pushSelectedBtn');
    var pullSelectedBtn = document.getElementById('pullSelectedBtn');
    var pullRemoteProductsBtn = document.getElementById('pullRemoteProductsBtn');
    var pullRemoteOrdersBtn = document.getElementById('pullRemoteOrdersBtn');

    function showToast(message, success) {
        toastBox.textContent = message || (success ? 'Completed.' : 'Request failed.');
        toastBox.className = 'toast-box show ' + (success ? 'success' : 'error');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(function () {
            toastBox.className = 'toast-box';
        }, 4200);
    }

    function setOverlay(show, title, text, percent) {
        if (show) {
            overlay.classList.add('show');
        } else {
            overlay.classList.remove('show');
        }
        syncTitle.textContent = title || 'Synchronizing Inventory';
        syncText.textContent = text || 'Please wait...';
        syncProgress.style.width = Math.max(0, Math.min(100, percent || 0)) + '%';
    }


    async function readJsonResponse(response) {
        var rawText = await response.text();
        var cleanText = String(rawText || '').replace(/^\uFEFF/, '').trim();

        if (cleanText === '') {
            throw new Error(
                'Server empty response de raha hai. HTTP ' + response.status +
                '. AJAX file path aur PHP error log check karein.'
            );
        }

        try {
            return JSON.parse(cleanText);
        } catch (error) {
            var preview = cleanText
                .replace(/<script[\s\S]*?<\/script>/gi, ' ')
                .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 420);

            throw new Error(
                'HTTP ' + response.status + ': ' +
                (preview || 'Server ne valid JSON response nahi diya.')
            );
        }
    }

    async function postAction(action, itemId) {
        var formData = new FormData();
        formData.append('ajax', action);
        formData.append('item_id', String(itemId));
        formData.append('csrf_token', csrfToken);

        var response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {'Accept': 'application/json'}
        });

        var result;
        try {
            result = await readJsonResponse(response);
        } catch (error) {
            result = {success:false,message:error.message || 'Server response read nahi ho saka.'};
        }

        if (result.redirect) {
            window.location.href = result.redirect;
        }

        return result;
    }

    function getSelectedIds() {
        return checkboxes.filter(function (checkbox) {
            return checkbox.checked;
        }).map(function (checkbox) {
            return parseInt(checkbox.value, 10);
        }).filter(function (id) {
            return id > 0;
        });
    }

    function updateSelection() {
        var count = getSelectedIds().length;
        document.getElementById('selectionCount').textContent = count + ' products selected';
        if (pushSelectedBtn) pushSelectedBtn.disabled = count === 0;
        if (pullSelectedBtn) pullSelectedBtn.disabled = count === 0;
        if (selectAll) selectAll.checked = checkboxes.length > 0 && count === checkboxes.length;
    }

    function updateRemoteCell(itemId, result) {
        var row = document.querySelector('tr[data-item-id="' + itemId + '"]');
        if (!row) return;
        var cell = row.querySelector('[data-remote-cell]');
        if (!cell) return;

        var qty = result.remote_stock_quantity;
        var qtyText = qty === null || typeof qty === 'undefined'
            ? (result.manage_stock ? 'N/A' : 'Not managed')
            : Number(qty).toFixed(2);
        var status = result.stock_status || '';
        var dateText = result.date_modified ? ' · ' + result.date_modified : '';

        cell.innerHTML = '<span class="remote-value">' + escapeHtml(qtyText) + '</span>'
            + '<span class="remote-meta">' + escapeHtml(status + dateText) + '</span>';
    }

    function updateLocalCell(itemId, result) {
        if (typeof result.local_stock_qty === 'undefined') return;
        var row = document.querySelector('tr[data-item-id="' + itemId + '"]');
        if (!row) return;
        var cell = row.querySelector('[data-local-stock]');
        if (cell) {
            cell.textContent = Number(result.local_stock_qty).toFixed(2);
        }
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = String(value || '');
        return div.innerHTML;
    }

    async function runSingle(action, itemId, button) {
        if (button) button.disabled = true;
        setOverlay(true, action === 'pull_stock' ? 'Pulling WooCommerce Stock' : (action === 'check_remote' ? 'Checking Remote Stock' : 'Pushing ONEERP Stock'), 'Product #' + itemId, 35);
        var result = await postAction(action, itemId);
        setOverlay(false, '', '', 100);
        if (button) button.disabled = false;

        if (result.success) {
            updateRemoteCell(itemId, result);
            updateLocalCell(itemId, result);
            showToast(result.message, true);
        } else {
            showToast(result.message, false);
        }
    }

    async function runBulk(action, ids) {
        if (!ids.length) return;
        var successCount = 0;
        var failedCount = 0;

        setOverlay(true, action === 'pull_stock' ? 'Pulling Selected Stock' : 'Pushing Selected Stock', 'Starting...', 0);

        for (var index = 0; index < ids.length; index++) {
            var itemId = ids[index];
            var percent = Math.round((index / ids.length) * 100);
            setOverlay(true, action === 'pull_stock' ? 'Pulling Selected Stock' : 'Pushing Selected Stock', 'Processing ' + (index + 1) + ' of ' + ids.length, percent);

            var result = await postAction(action, itemId);
            if (result.success) {
                successCount++;
                updateRemoteCell(itemId, result);
                updateLocalCell(itemId, result);
            } else {
                failedCount++;
            }
        }

        setOverlay(false, '', '', 100);
        showToast(successCount + ' successful, ' + failedCount + ' failed.', failedCount === 0);
        window.setTimeout(function () {
            window.location.reload();
        }, 1300);
    }

    document.querySelectorAll('.check-stock-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            runSingle('check_remote', parseInt(button.dataset.itemId, 10), button);
        });
    });
    document.querySelectorAll('.push-stock-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            runSingle('push_stock', parseInt(button.dataset.itemId, 10), button);
        });
    });
    document.querySelectorAll('.pull-stock-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!window.confirm('WooCommerce stock ONEERP local stock ko replace karega. Continue?')) return;
            runSingle('pull_stock', parseInt(button.dataset.itemId, 10), button);
        });
    });

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelection);
    });
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            updateSelection();
        });
    }
    if (pushSelectedBtn) {
        pushSelectedBtn.addEventListener('click', function () {
            runBulk('push_stock', getSelectedIds());
        });
    }
    if (pullSelectedBtn) {
        pullSelectedBtn.addEventListener('click', function () {
            var ids = getSelectedIds();
            if (!ids.length) return;
            if (!window.confirm('Selected WooCommerce stock values ONEERP local stock ko replace karenge. Continue?')) return;
            runBulk('pull_stock', ids);
        });
    }


    async function pullRemotePages(kind) {
        var isProducts = kind === 'products';
        var button = isProducts ? pullRemoteProductsBtn : pullRemoteOrdersBtn;
        var endpoint = isProducts
            ? '/onerp/app/public/app/views/ajax_woocommerce_pull_products.php'
            : '/onerp/app/public/app/views/ajax_woocommerce_pull_orders.php';
        var label = isProducts ? 'Products' : 'Orders';

        if (!button) return;

        if (!window.confirm(
            'WooCommerce ' + label.toLowerCase() + ' ONEERP mein pull/update karne hain?'
        )) {
            return;
        }

        button.disabled = true;
        var page = 1;
        var perPage = 50;
        var maxPages = 200;
        var created = 0;
        var updated = 0;
        var failed = 0;

        setOverlay(true, 'Pulling WooCommerce ' + label, 'Remote page 1 load ho raha hai...', 2);

        try {
            while (page <= maxPages) {
                var formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('integration_id', String(integrationId));
                formData.append('page', String(page));
                formData.append('per_page', String(perPage));

                setOverlay(
                    true,
                    'Pulling WooCommerce ' + label,
                    'Remote page ' + page + ' process ho raha hai...',
                    Math.min(95, 5 + page)
                );

                var response = await fetch(endpoint, {
                    method: 'POST',
                    body: formData,
                    headers: {'Accept': 'application/json'}
                });

                var result = await readJsonResponse(response);

                if (result.redirect) {
                    window.location.href = result.redirect;
                    return;
                }

                if (!result.success && !result.partial) {
                    throw new Error(result.message || (label + ' pull failed.'));
                }

                created += parseInt(result.created || 0, 10);
                updated += parseInt(result.updated || 0, 10);
                failed += parseInt(result.failed || 0, 10);

                if (!result.has_more) {
                    break;
                }

                page++;
            }

            setOverlay(
                true,
                label + ' Pull Completed',
                created + ' created, ' + updated + ' updated, ' + failed + ' failed.',
                100
            );

            window.setTimeout(function () {
                window.location.href = isProducts
                    ? window.location.href
                    : 'woocommerce_orders.php';
            }, 900);
        } catch (error) {
            setOverlay(false, '', '', 0);
            button.disabled = false;
            showToast(error.message || (label + ' pull failed.'), false);
        }
    }

    if (pullRemoteProductsBtn) {
        pullRemoteProductsBtn.addEventListener('click', function () {
            pullRemotePages('products');
        });
    }

    if (pullRemoteOrdersBtn) {
        pullRemoteOrdersBtn.addEventListener('click', function () {
            pullRemotePages('orders');
        });
    }

    var perPageSelect = document.getElementById('perPageSelect');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('per_page', perPageSelect.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    }

    updateSelection();
})();
</script>
</body>
</html>
