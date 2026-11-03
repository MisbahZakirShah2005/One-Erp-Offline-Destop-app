<?php
/**
 * ONEERP WooCommerce Products Pull AJAX
 * Save as: /public/app/ajax/woocommerce_pull_products.php
 * PHP 7.2+
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function wooPullProductsResponse(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wooPullProductsReadInput(): array
{
    $input = $_POST;
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

    if (strpos($contentType, 'application/json') !== false) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($decoded)) {
            $input = array_merge($input, $decoded);
        }
    }

    return $input;
}

function wooPullProductsCsrfValid(string $provided): bool
{
    if ($provided === '') {
        return false;
    }

    foreach (['woo_products_csrf', 'woo_inventory_csrf', 'woo_orders_csrf', 'woo_ajax_csrf'] as $key) {
        $stored = (string)($_SESSION[$key] ?? '');
        if ($stored !== '' && hash_equals($stored, $provided)) {
            return true;
        }
    }

    return false;
}

function wooPullProductsFeatureAllowed(
    mysqli $conn,
    int $clientId,
    int $userId,
    int $roleId,
    string $featureKey
): bool {
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

    $stmt = $conn->prepare("\n        SELECT enabled\n        FROM client_feature_overrides\n        WHERE client_id = ? AND feature_key = ?\n        LIMIT 1\n    ");

    if ($stmt) {
        $stmt->bind_param('is', $clientId, $featureKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (is_array($row)) {
            $enabled = (int)$row['enabled'] === 1;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wooPullProductsResponse([
        'success' => false,
        'message' => 'Only POST request allowed hai.'
    ], 405);
}

if (empty($_SESSION['user_id']) || empty($_SESSION['client_id'])) {
    wooPullProductsResponse([
        'success' => false,
        'message' => 'Session expire ho chuki hai. Dobara login karein.',
        'redirect' => '/onerp/app/public/app/views/login_view.php'
    ], 401);
}

$input = wooPullProductsReadInput();
$userId = (int)$_SESSION['user_id'];
$clientId = (int)$_SESSION['client_id'];
$roleId = (int)($_SESSION['role_id'] ?? 0);
$integrationId = (int)($input['integration_id'] ?? 0);
$page = max(1, (int)($input['page'] ?? 1));
$perPage = max(1, min(100, (int)($input['per_page'] ?? 50)));
$csrfToken = trim((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));

if (!wooPullProductsCsrfValid($csrfToken)) {
    wooPullProductsResponse([
        'success' => false,
        'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
    ], 419);
}

$dbFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';
$helperFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/woocommerce_helper.php';

if (!is_file($dbFile) || !is_file($helperFile)) {
    wooPullProductsResponse([
        'success' => false,
        'message' => 'Database ya WooCommerce helper file missing hai.'
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
    wooPullProductsResponse([
        'success' => false,
        'message' => 'Database connection available nahi hai.'
    ], 500);
}

$conn->set_charset('utf8mb4');
require_once $helperFile;

try {
    if (!wooPullProductsFeatureAllowed(
        $conn,
        $clientId,
        $userId,
        $roleId,
        'woocommerce_products'
    )) {
        wooPullProductsResponse([
            'success' => false,
            'message' => 'WooCommerce Product Sync permission available nahi hai.'
        ], 403);
    }

    if (!function_exists('pullWooCommerceProductsPage')) {
        wooPullProductsResponse([
            'success' => false,
            'message' => 'Updated WooCommerce helper install nahi hua.'
        ], 500);
    }

    $connection = getWooCommerceConnection($conn, $clientId, $integrationId, true);
    if (!$connection || empty($connection['is_configured'])) {
        wooPullProductsResponse([
            'success' => false,
            'message' => 'WooCommerce integration Active aur Connected nahi hai.',
            'redirect' => '/onerp/app/public/app/views/integrations.php'
        ], 403);
    }

    $result = pullWooCommerceProductsPage(
        $conn,
        $clientId,
        $page,
        $perPage,
        (int)$connection['id']
    );

    wooPullProductsResponse(
        $result,
        !empty($result['success']) || !empty($result['partial']) ? 200 : 422
    );
} catch (Throwable $e) {
    wooPullProductsResponse([
        'success' => false,
        'message' => 'Products pull exception: ' . $e->getMessage()
    ], 500);
}
