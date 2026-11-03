<?php
/**
 * ONEERP WooCommerce Orders Pull AJAX
 * Save as: /public/app/ajax/woocommerce_pull_orders.php
 * PHP 7.2+
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function wooPullOrdersResponse(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wooPullOrdersReadInput(): array
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

function wooPullOrdersCsrfValid(string $provided): bool
{
    if ($provided === '') {
        return false;
    }

    foreach (['woo_orders_csrf', 'woo_inventory_csrf', 'woo_products_csrf', 'woo_ajax_csrf'] as $key) {
        $stored = (string)($_SESSION[$key] ?? '');
        if ($stored !== '' && hash_equals($stored, $provided)) {
            return true;
        }
    }

    return false;
}

function wooPullOrdersFeatureAllowed(
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

    $stmt = $conn->prepare("\n        SELECT enabled FROM client_feature_overrides\n        WHERE client_id = ? AND feature_key = ? LIMIT 1\n    ");

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
    wooPullOrdersResponse([
        'success' => false,
        'message' => 'Only POST request allowed hai.'
    ], 405);
}

if (empty($_SESSION['user_id']) || empty($_SESSION['client_id'])) {
    wooPullOrdersResponse([
        'success' => false,
        'message' => 'Session expire ho chuki hai. Dobara login karein.',
        'redirect' => '/onerp/app/onerp/app/public/app/views/login_view.php'
    ], 401);
}

$input = wooPullOrdersReadInput();
$userId = (int)$_SESSION['user_id'];
$clientId = (int)$_SESSION['client_id'];
$roleId = (int)($_SESSION['role_id'] ?? 0);
$integrationId = (int)($input['integration_id'] ?? 0);
$page = max(1, (int)($input['page'] ?? 1));
$perPage = max(1, min(100, (int)($input['per_page'] ?? 50)));
$csrfToken = trim((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));

if (!wooPullOrdersCsrfValid($csrfToken)) {
    wooPullOrdersResponse([
        'success' => false,
        'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
    ], 419);
}

$dbFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';
$helperFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/woocommerce_helper.php';

if (!is_file($dbFile) || !is_file($helperFile)) {
    wooPullOrdersResponse([
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
    wooPullOrdersResponse([
        'success' => false,
        'message' => 'Database connection available nahi hai.'
    ], 500);
}

$conn->set_charset('utf8mb4');
require_once $helperFile;

try {
    if (!wooPullOrdersFeatureAllowed(
        $conn,
        $clientId,
        $userId,
        $roleId,
        'woocommerce_orders'
    )) {
        wooPullOrdersResponse([
            'success' => false,
            'message' => 'WooCommerce Orders permission available nahi hai.'
        ], 403);
    }

    if (!function_exists('pullWooCommerceOrdersPage')) {
        wooPullOrdersResponse([
            'success' => false,
            'message' => 'Updated WooCommerce helper install nahi hua.'
        ], 500);
    }

    if (!wooTableExists($conn, 'woocommerce_orders') || !wooTableExists($conn, 'woocommerce_order_items')) {
        wooPullOrdersResponse([
            'success' => false,
            'message' => 'WooCommerce orders migration queries pehle run karein.'
        ], 500);
    }

    $connection = getWooCommerceConnection($conn, $clientId, $integrationId, true);
    if (!$connection || empty($connection['is_configured'])) {
        wooPullOrdersResponse([
            'success' => false,
            'message' => 'WooCommerce integration Active aur Connected nahi hai.',
            'redirect' => '/onerp/app/public/app/views/integrations.php'
        ], 403);
    }

    $result = pullWooCommerceOrdersPage(
        $conn,
        $clientId,
        $page,
        $perPage,
        (int)$connection['id']
    );

    wooPullOrdersResponse(
        $result,
        !empty($result['success']) || !empty($result['partial']) ? 200 : 422
    );
} catch (Throwable $e) {
    wooPullOrdersResponse([
        'success' => false,
        'message' => 'Orders pull exception: ' . $e->getMessage()
    ], 500);
}
