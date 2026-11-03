<?php
/**
 * ONEERP WooCommerce Single Product Push AJAX
 *
 * Save as:
 * /public/app/ajax/woocommerce_push_product.php
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

function wooPushAjaxResponse(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wooPushReadInput(): array
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

function wooPushValidateCsrf(string $provided): bool
{
    if ($provided === '') {
        return false;
    }

    foreach (['woo_products_csrf', 'woo_ajax_csrf'] as $key) {
        $stored = (string)($_SESSION[$key] ?? '');
        if ($stored !== '' && hash_equals($stored, $provided)) {
            return true;
        }
    }

    return false;
}

function wooPushFeatureAllowed(mysqli $conn, int $clientId, int $userId, int $roleId, string $featureKey): bool
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

    $stmt = $conn->prepare("\n        SELECT feature_key\n        FROM users_permissions\n        WHERE user_id = ?\n    ");

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wooPushAjaxResponse([
        'success' => false,
        'message' => 'Only POST request allowed hai.'
    ], 405);
}

if (empty($_SESSION['user_id']) || empty($_SESSION['client_id'])) {
    wooPushAjaxResponse([
        'success' => false,
        'message' => 'Session expire ho chuki hai. Dobara login karein.',
        'redirect' => '/onerp/app/onerp/app/public/app/views/login_view.php'
    ], 401);
}

$input = wooPushReadInput();
$userId = (int)$_SESSION['user_id'];
$clientId = (int)$_SESSION['client_id'];
$roleId = (int)($_SESSION['role_id'] ?? 0);
$itemId = (int)($input['item_id'] ?? 0);
$integrationId = (int)($input['integration_id'] ?? 0);
$csrfToken = trim((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));

if ($clientId <= 0 || $itemId <= 0) {
    wooPushAjaxResponse([
        'success' => false,
        'message' => 'Invalid client ya item ID.'
    ], 422);
}

if (!wooPushValidateCsrf($csrfToken)) {
    wooPushAjaxResponse([
        'success' => false,
        'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
    ], 419);
}

$dbFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';
$helperFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/woocommerce_helper.php';

if (!is_file($dbFile) || !is_file($helperFile)) {
    wooPushAjaxResponse([
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
    wooPushAjaxResponse([
        'success' => false,
        'message' => 'Database connection available nahi hai.'
    ], 500);
}

$conn->set_charset('utf8mb4');
require_once $helperFile;

try {
    if (!wooPushFeatureAllowed($conn, $clientId, $userId, $roleId, 'woocommerce_products')) {
        wooPushAjaxResponse([
            'success' => false,
            'message' => 'WooCommerce Product Sync permission available nahi hai.'
        ], 403);
    }

    if (!wooTableExists($conn, 'woocommerce_product_mappings')) {
        wooPushAjaxResponse([
            'success' => false,
            'message' => 'woocommerce_product_mappings table missing hai.'
        ], 500);
    }

    $connection = getWooCommerceConnection($conn, $clientId, $integrationId, true);
    if (!$connection || empty($connection['is_configured'])) {
        wooPushAjaxResponse([
            'success' => false,
            'message' => 'WooCommerce integration Active aur Connected nahi hai.',
            'redirect' => '/onerp/app/public/app/views/integrations.php'
        ], 403);
    }

    $options = [
        'integration_id' => (int)$connection['id'],
        'require_active' => true,
        'image_upload_path' => 'uploads/items'
    ];

    foreach (['force_create', 'sync_images', 'sync_categories', 'sync_variations'] as $optionKey) {
        if (array_key_exists($optionKey, $input)) {
            $options[$optionKey] = filter_var($input[$optionKey], FILTER_VALIDATE_BOOLEAN);
        }
    }

    if (!empty($input['default_status']) && in_array($input['default_status'], ['publish', 'draft', 'private', 'pending'], true)) {
        $options['default_status'] = (string)$input['default_status'];
    }

    $result = pushWooCommerceProduct($conn, $clientId, $itemId, $options);
    $statusCode = !empty($result['success']) ? 200 : (!empty($result['partial']) ? 207 : 422);

    wooPushAjaxResponse($result, $statusCode);
} catch (Throwable $e) {
    try {
        writeWooCommerceLog(
            $conn,
            $clientId,
            'product_sync_exception',
            ['item_id' => $itemId],
            ['message' => $e->getMessage()],
            false,
            $integrationId,
            $itemId,
            0
        );
    } catch (Throwable $ignored) {
    }

    wooPushAjaxResponse([
        'success' => false,
        'message' => 'Product sync exception: ' . $e->getMessage()
    ], 500);
}