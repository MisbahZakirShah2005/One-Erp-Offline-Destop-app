<?php
/**
 * ONEERP WooCommerce Inventory Sync AJAX
 *
 * Save as:
 * /public/app/ajax/woocommerce_sync_inventory.php
 *
 * Supported actions:
 *   push_stock, pull_stock, check_remote
 *   bulk_push_stock, bulk_pull_stock, bulk_check_remote
 *
 * PHP compatibility: PHP 7.2+
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

@set_time_limit(0);
ignore_user_abort(true);

function wooInventoryAjaxResponse(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wooInventoryAjaxReadInput(): array
{
    $input = $_POST;
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $input = array_merge($input, $decoded);
        }
    }

    return $input;
}

function wooInventoryAjaxValidateCsrf(string $provided): bool
{
    if ($provided === '') {
        return false;
    }

    foreach (['woo_inventory_csrf', 'woo_ajax_csrf'] as $key) {
        $stored = (string)($_SESSION[$key] ?? '');
        if ($stored !== '' && hash_equals($stored, $provided)) {
            return true;
        }
    }

    return false;
}

function wooInventoryAjaxFeatureAllowed(mysqli $conn, int $clientId, int $userId, int $roleId, string $featureKey): bool
{
    if ($roleId === 1) {
        return true;
    }

    $enabled = false;

    $stmt = $conn->prepare("\n        SELECT 1\n        FROM subscriptions s\n        INNER JOIN plan_features pf ON pf.plan_id = s.plan_id\n        WHERE s.client_id = ?\n          AND s.status = 'active'\n          AND (s.end_date IS NULL OR s.end_date >= CURDATE())\n          AND pf.feature_key = ?\n          AND pf.enabled = 1\n        LIMIT 1\n    ");

    if ($stmt) {
        $stmt->bind_param('is', $clientId, $featureKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $enabled = $result && $result->num_rows > 0;
        $stmt->close();
    }

    $stmt = $conn->prepare("\n        SELECT enabled\n        FROM client_feature_overrides\n        WHERE client_id = ?\n          AND feature_key = ?\n        LIMIT 1\n    ");

    if ($stmt) {
        $stmt->bind_param('is', $clientId, $featureKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $override = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (is_array($override)) {
            $enabled = (int)$override['enabled'] === 1;
        }
    }

    if (!$enabled) {
        return false;
    }

    $permissionCount = 0;
    $hasPermission = false;

    $stmt = $conn->prepare("SELECT feature_key FROM users_permissions WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && ($row = $result->fetch_assoc())) {
            $permissionCount++;
            if (trim((string)$row['feature_key']) === $featureKey) {
                $hasPermission = true;
            }
        }

        $stmt->close();
    }

    return $permissionCount === 0 || $hasPermission;
}

function wooInventoryAjaxExtractIds($value): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            $value = $decoded;
        } else {
            $value = preg_split('/[\s,;]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        }
    }

    if (!is_array($value)) {
        $value = [$value];
    }

    $ids = [];
    foreach ($value as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function wooInventoryAjaxFetchItem(mysqli $conn, int $clientId, int $integrationId, int $itemId): ?array
{
    $stmt = $conn->prepare("\n        SELECT\n            i.id,\n            i.name,\n            i.sku,\n            i.stock_qty,\n            i.track_inventory,\n            i.status,\n            i.has_variants,\n            m.woo_product_id\n        FROM items i\n        INNER JOIN woocommerce_product_mappings m\n            ON m.item_id = i.id\n           AND m.client_id = i.client_id\n        WHERE i.id = ?\n          AND i.client_id = ?\n          AND m.integration_id = ?\n          AND m.woo_product_id IS NOT NULL\n        LIMIT 1\n    ");

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

function wooInventoryAjaxUpdateMapping(
    mysqli $conn,
    int $clientId,
    int $integrationId,
    int $itemId,
    bool $success,
    string $errorMessage = ''
): bool {
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

    wooBindParams($stmt, $types, $values);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function wooInventoryAjaxTouchSettings(mysqli $conn, int $clientId, int $integrationId): void
{
    if (
        !wooTableExists($conn, 'woocommerce_sync_settings')
        || !wooColumnExists($conn, 'woocommerce_sync_settings', 'last_inventory_sync_at')
    ) {
        return;
    }

    $stmt = $conn->prepare("\n        INSERT INTO woocommerce_sync_settings\n            (client_id, integration_id, last_inventory_sync_at)\n        VALUES (?, ?, NOW())\n        ON DUPLICATE KEY UPDATE last_inventory_sync_at = NOW()\n    ");

    if ($stmt) {
        $stmt->bind_param('ii', $clientId, $integrationId);
        $stmt->execute();
        $stmt->close();
    }
}

function wooInventoryAjaxPush(
    mysqli $conn,
    array $connection,
    int $clientId,
    int $integrationId,
    int $itemId
): array {
    $item = wooInventoryAjaxFetchItem($conn, $clientId, $integrationId, $itemId);

    if (!$item) {
        return [
            'success' => false,
            'item_id' => $itemId,
            'message' => 'Mapped ONEERP product nahi mila. Pehle Product Sync karein.'
        ];
    }

    $wooProductId = (int)($item['woo_product_id'] ?? 0);
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

    if (empty($result['success']) || empty($result['data']['id'])) {
        $message = trim((string)($result['message'] ?? 'Inventory push failed.'));

        wooInventoryAjaxUpdateMapping($conn, $clientId, $integrationId, $itemId, false, $message);
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
            'item_id' => $itemId,
            'woo_product_id' => $wooProductId,
            'http_code' => (int)($result['http_code'] ?? 0),
            'message' => $message
        ];
    }

    $remote = (array)$result['data'];
    wooInventoryAjaxUpdateMapping($conn, $clientId, $integrationId, $itemId, true, '');
    wooInventoryAjaxTouchSettings($conn, $clientId, $integrationId);
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
            'remote_stock_quantity' => $remote['stock_quantity'] ?? null,
            'manage_stock' => $remote['manage_stock'] ?? null,
            'stock_status' => $remote['stock_status'] ?? null
        ],
        true,
        $integrationId,
        $itemId,
        (int)($result['http_code'] ?? 200)
    );

    return [
        'success' => true,
        'item_id' => $itemId,
        'woo_product_id' => $wooProductId,
        'local_stock_qty' => $localQuantity,
        'remote_stock_quantity' => $remote['stock_quantity'] ?? null,
        'manage_stock' => $remote['manage_stock'] ?? null,
        'stock_status' => $remote['stock_status'] ?? null,
        'date_modified' => $remote['date_modified'] ?? null,
        'message' => 'ONEERP stock WooCommerce par successfully update ho gaya.'
    ];
}

function wooInventoryAjaxCheck(
    mysqli $conn,
    array $connection,
    int $clientId,
    int $integrationId,
    int $itemId
): array {
    $item = wooInventoryAjaxFetchItem($conn, $clientId, $integrationId, $itemId);

    if (!$item) {
        return [
            'success' => false,
            'item_id' => $itemId,
            'message' => 'Mapped ONEERP product nahi mila.'
        ];
    }

    $wooProductId = (int)$item['woo_product_id'];
    $result = wooCommerceRequestWithConnection($connection, 'GET', 'products/' . $wooProductId);

    if (empty($result['success']) || empty($result['data']['id'])) {
        $message = trim((string)($result['message'] ?? 'Remote stock check failed.'));

        writeWooCommerceLog(
            $conn,
            $clientId,
            'inventory_check_failed',
            ['item_id' => $itemId, 'woo_product_id' => $wooProductId],
            ['message' => $message],
            false,
            $integrationId,
            $itemId,
            (int)($result['http_code'] ?? 0)
        );

        return [
            'success' => false,
            'item_id' => $itemId,
            'woo_product_id' => $wooProductId,
            'message' => $message
        ];
    }

    $remote = (array)$result['data'];

    return [
        'success' => true,
        'item_id' => $itemId,
        'woo_product_id' => $wooProductId,
        'remote_stock_quantity' => $remote['stock_quantity'] ?? null,
        'manage_stock' => (bool)($remote['manage_stock'] ?? false),
        'stock_status' => (string)($remote['stock_status'] ?? ''),
        'product_type' => (string)($remote['type'] ?? ''),
        'date_modified' => $remote['date_modified'] ?? null,
        'permalink' => $remote['permalink'] ?? '',
        'message' => 'WooCommerce stock successfully load ho gaya.'
    ];
}

function wooInventoryAjaxPull(
    mysqli $conn,
    array $connection,
    int $clientId,
    int $integrationId,
    int $itemId
): array {
    $item = wooInventoryAjaxFetchItem($conn, $clientId, $integrationId, $itemId);

    if (!$item) {
        return [
            'success' => false,
            'item_id' => $itemId,
            'message' => 'Mapped ONEERP product nahi mila.'
        ];
    }

    $wooProductId = (int)$item['woo_product_id'];
    $result = wooCommerceRequestWithConnection($connection, 'GET', 'products/' . $wooProductId);

    if (empty($result['success']) || empty($result['data']['id'])) {
        $message = trim((string)($result['message'] ?? 'WooCommerce stock pull failed.'));

        writeWooCommerceLog(
            $conn,
            $clientId,
            'inventory_pull_failed',
            ['item_id' => $itemId, 'woo_product_id' => $wooProductId],
            ['message' => $message],
            false,
            $integrationId,
            $itemId,
            (int)($result['http_code'] ?? 0)
        );

        return [
            'success' => false,
            'item_id' => $itemId,
            'woo_product_id' => $wooProductId,
            'message' => $message
        ];
    }

    $remote = (array)$result['data'];
    $manageStock = !empty($remote['manage_stock']);
    $remoteQuantity = $remote['stock_quantity'] ?? null;

    if ($manageStock && $remoteQuantity === null) {
        return [
            'success' => false,
            'item_id' => $itemId,
            'woo_product_id' => $wooProductId,
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

    $sql = 'UPDATE items SET ' . implode(', ', $sets) . ' WHERE id = ? AND client_id = ?';
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [
            'success' => false,
            'item_id' => $itemId,
            'message' => 'ONEERP stock update query prepare nahi ho saki.'
        ];
    }

    wooBindParams($stmt, $types, $values);
    $updated = $stmt->execute();
    $stmt->close();

    if (!$updated) {
        return [
            'success' => false,
            'item_id' => $itemId,
            'message' => 'WooCommerce stock ONEERP mein save nahi ho saka.'
        ];
    }

    wooInventoryAjaxUpdateMapping($conn, $clientId, $integrationId, $itemId, true, '');
    wooInventoryAjaxTouchSettings($conn, $clientId, $integrationId);
    wooTouchLastSync($conn, $integrationId, $clientId);

    $localQuantity = $manageStock
        ? max(0, (float)$remoteQuantity)
        : (float)($item['stock_qty'] ?? 0);

    writeWooCommerceLog(
        $conn,
        $clientId,
        'inventory_pull',
        ['item_id' => $itemId, 'woo_product_id' => $wooProductId],
        [
            'old_local_stock_qty' => (float)($item['stock_qty'] ?? 0),
            'new_local_stock_qty' => $localQuantity,
            'track_inventory' => $manageStock ? 1 : 0,
            'stock_status' => $remote['stock_status'] ?? null
        ],
        true,
        $integrationId,
        $itemId,
        (int)($result['http_code'] ?? 200)
    );

    return [
        'success' => true,
        'item_id' => $itemId,
        'woo_product_id' => $wooProductId,
        'local_stock_qty' => $localQuantity,
        'remote_stock_quantity' => $remoteQuantity,
        'manage_stock' => $manageStock,
        'stock_status' => $remote['stock_status'] ?? null,
        'date_modified' => $remote['date_modified'] ?? null,
        'message' => $manageStock
            ? 'WooCommerce stock ONEERP mein successfully update ho gaya.'
            : 'WooCommerce par stock management disabled hai; ONEERP inventory tracking bhi disable kar di gayi hai.'
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Only POST request allowed hai.'
    ], 405);
}

if (empty($_SESSION['user_id']) || empty($_SESSION['client_id'])) {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Session expire ho chuki hai. Dobara login karein.',
        'redirect' => '/onerp/app/public/app/views/login_view.php'
    ], 401);
}

$input = wooInventoryAjaxReadInput();
$userId = (int)$_SESSION['user_id'];
$clientId = (int)$_SESSION['client_id'];
$roleId = (int)($_SESSION['role_id'] ?? 0);
$integrationId = (int)($input['integration_id'] ?? 0);
$csrfToken = trim((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
$action = trim((string)($input['action'] ?? ($input['ajax'] ?? '')));
$itemId = (int)($input['item_id'] ?? 0);
$itemIds = wooInventoryAjaxExtractIds($input['item_ids'] ?? []);

if (!wooInventoryAjaxValidateCsrf($csrfToken)) {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
    ], 419);
}

$allowedActions = [
    'push_stock',
    'pull_stock',
    'check_remote',
    'bulk_push_stock',
    'bulk_pull_stock',
    'bulk_check_remote'
];

if (!in_array($action, $allowedActions, true)) {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Unsupported inventory action.'
    ], 400);
}

$isBulk = strpos($action, 'bulk_') === 0 || !empty($itemIds);
if ($isBulk && empty($itemIds) && $itemId > 0) {
    $itemIds = [$itemId];
}

if (!$isBulk && $itemId <= 0) {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Invalid item ID.'
    ], 422);
}

if ($isBulk && empty($itemIds)) {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Kam az kam aik valid item ID required hai.'
    ], 422);
}

if (count($itemIds) > 100) {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Aik request mein maximum 100 products ka stock sync ho sakta hai.'
    ], 422);
}

$dbFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';
$helperFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/woocommerce_helper.php';

if (!is_file($dbFile) || !is_file($helperFile)) {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Required database ya WooCommerce helper file missing hai.'
    ], 500);
}

require_once $dbFile;

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Database connection available nahi hai.'
    ], 500);
}

$conn->set_charset('utf8mb4');
require_once $helperFile;

try {
    if (!wooInventoryAjaxFeatureAllowed($conn, $clientId, $userId, $roleId, 'woocommerce_inventory_sync')) {
        wooInventoryAjaxResponse([
            'success' => false,
            'message' => 'WooCommerce Inventory Sync permission available nahi hai.'
        ], 403);
    }

    if (!wooTableExists($conn, 'woocommerce_product_mappings')) {
        wooInventoryAjaxResponse([
            'success' => false,
            'message' => 'woocommerce_product_mappings table missing hai.'
        ], 500);
    }

    $connection = getWooCommerceConnection($conn, $clientId, $integrationId, true);
    if (!$connection || empty($connection['is_configured'])) {
        wooInventoryAjaxResponse([
            'success' => false,
            'message' => 'WooCommerce integration Active aur Connected nahi hai.',
            'redirect' => '/onerp/app/public/app/views/integrations.php'
        ], 403);
    }

    $integrationId = (int)$connection['id'];

    $runAction = function (string $singleAction, int $currentItemId) use (
        $conn,
        $connection,
        $clientId,
        $integrationId
    ): array {
        if ($singleAction === 'push_stock') {
            return wooInventoryAjaxPush($conn, $connection, $clientId, $integrationId, $currentItemId);
        }

        if ($singleAction === 'pull_stock') {
            return wooInventoryAjaxPull($conn, $connection, $clientId, $integrationId, $currentItemId);
        }

        return wooInventoryAjaxCheck($conn, $connection, $clientId, $integrationId, $currentItemId);
    };

    if (!$isBulk) {
        $result = $runAction($action, $itemId);
        wooInventoryAjaxResponse($result, !empty($result['success']) ? 200 : 422);
    }

    $singleAction = str_replace('bulk_', '', $action);
    $results = [];
    $successful = 0;
    $failed = 0;

    foreach ($itemIds as $currentItemId) {
        try {
            $result = $runAction($singleAction, $currentItemId);
        } catch (Throwable $itemException) {
            $result = [
                'success' => false,
                'item_id' => $currentItemId,
                'message' => 'Inventory sync exception: ' . $itemException->getMessage()
            ];
        }

        $results[] = $result;
        if (!empty($result['success'])) {
            $successful++;
        } else {
            $failed++;
        }
    }

    $allSuccessful = $failed === 0;
    writeWooCommerceLog(
        $conn,
        $clientId,
        'bulk_' . $singleAction,
        ['item_ids' => $itemIds],
        [
            'total' => count($itemIds),
            'successful' => $successful,
            'failed' => $failed
        ],
        $allSuccessful,
        $integrationId,
        0,
        200
    );

    wooInventoryAjaxResponse([
        'success' => $allSuccessful,
        'partial' => !$allSuccessful && $successful > 0,
        'message' => $allSuccessful
            ? 'Selected products ka inventory operation successfully complete ho gaya.'
            : ($successful > 0
                ? 'Inventory operation complete hua, lekin kuch products fail rahe.'
                : 'Inventory operation kisi product par complete nahi ho saka.'),
        'summary' => [
            'total' => count($itemIds),
            'successful' => $successful,
            'failed' => $failed
        ],
        'results' => $results
    ], $allSuccessful ? 200 : ($successful > 0 ? 207 : 422));
} catch (Throwable $e) {
    try {
        writeWooCommerceLog(
            $conn,
            $clientId,
            'inventory_sync_exception',
            [
                'action' => $action,
                'item_id' => $itemId,
                'item_ids' => $itemIds
            ],
            ['message' => $e->getMessage()],
            false,
            $integrationId,
            $itemId,
            0
        );
    } catch (Throwable $ignored) {
    }

    wooInventoryAjaxResponse([
        'success' => false,
        'message' => 'Inventory sync exception: ' . $e->getMessage()
    ], 500);
}
