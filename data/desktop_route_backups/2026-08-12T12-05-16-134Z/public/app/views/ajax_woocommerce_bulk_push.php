<?php
/**
 * ONEERP WooCommerce Bulk Product Push AJAX
 *
 * Save as:
 * /public/app/ajax/woocommerce_bulk_push.php
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

function wooBulkAjaxResponse(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wooBulkReadInput(): array
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

function wooBulkValidateCsrf(string $provided): bool
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

function wooBulkFeatureAllowed(mysqli $conn, int $clientId, int $userId, int $roleId, string $featureKey): bool
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

function wooBulkExtractItemIds($value): array
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
    foreach ($value as $itemId) {
        $itemId = (int)$itemId;
        if ($itemId > 0) {
            $ids[$itemId] = $itemId;
        }
    }

    return array_values($ids);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wooBulkAjaxResponse([
        'success' => false,
        'message' => 'Only POST request allowed hai.'
    ], 405);
}

if (empty($_SESSION['user_id']) || empty($_SESSION['client_id'])) {
    wooBulkAjaxResponse([
        'success' => false,
        'message' => 'Session expire ho chuki hai. Dobara login karein.',
        'redirect' => '/onerp/app/public/app/views/login_view.php'
    ], 401);
}

$input = wooBulkReadInput();
$userId = (int)$_SESSION['user_id'];
$clientId = (int)$_SESSION['client_id'];
$roleId = (int)($_SESSION['role_id'] ?? 0);
$integrationId = (int)($input['integration_id'] ?? 0);
$csrfToken = trim((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
$itemIds = wooBulkExtractItemIds($input['item_ids'] ?? ($input['items'] ?? []));

if (empty($itemIds) && !empty($input['item_id'])) {
    $itemIds = wooBulkExtractItemIds($input['item_id']);
}

if ($clientId <= 0) {
    wooBulkAjaxResponse([
        'success' => false,
        'message' => 'Invalid client session.'
    ], 401);
}

if (!wooBulkValidateCsrf($csrfToken)) {
    wooBulkAjaxResponse([
        'success' => false,
        'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
    ], 419);
}

if (empty($itemIds)) {
    wooBulkAjaxResponse([
        'success' => false,
        'message' => 'Kam az kam aik valid item ID required hai.'
    ], 422);
}

$maxItems = 100;
if (count($itemIds) > $maxItems) {
    wooBulkAjaxResponse([
        'success' => false,
        'message' => 'Aik request mein maximum ' . $maxItems . ' products sync kiye ja sakte hain.'
    ], 422);
}

$dbFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';
$helperFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/woocommerce_helper.php';

if (!is_file($dbFile) || !is_file($helperFile)) {
    wooBulkAjaxResponse([
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
    wooBulkAjaxResponse([
        'success' => false,
        'message' => 'Database connection available nahi hai.'
    ], 500);
}

$conn->set_charset('utf8mb4');
require_once $helperFile;

try {
    if (!wooBulkFeatureAllowed($conn, $clientId, $userId, $roleId, 'woocommerce_products')) {
        wooBulkAjaxResponse([
            'success' => false,
            'message' => 'WooCommerce Product Sync permission available nahi hai.'
        ], 403);
    }

    if (!wooTableExists($conn, 'woocommerce_product_mappings')) {
        wooBulkAjaxResponse([
            'success' => false,
            'message' => 'woocommerce_product_mappings table missing hai.'
        ], 500);
    }

    $connection = getWooCommerceConnection($conn, $clientId, $integrationId, true);
    if (!$connection || empty($connection['is_configured'])) {
        wooBulkAjaxResponse([
            'success' => false,
            'message' => 'WooCommerce integration Active aur Connected nahi hai.',
            'redirect' => '/onerp/app/public/app/views/integrations.php'
        ], 403);
    }

    $baseOptions = [
        'integration_id' => (int)$connection['id'],
        'require_active' => true,
        'image_upload_path' => 'uploads/items'
    ];

    foreach (['force_create', 'sync_images', 'sync_categories', 'sync_variations'] as $optionKey) {
        if (array_key_exists($optionKey, $input)) {
            $baseOptions[$optionKey] = filter_var($input[$optionKey], FILTER_VALIDATE_BOOLEAN);
        }
    }

    if (!empty($input['default_status']) && in_array($input['default_status'], ['publish', 'draft', 'private', 'pending'], true)) {
        $baseOptions['default_status'] = (string)$input['default_status'];
    }

    $results = [];
    $summary = [
        'total' => count($itemIds),
        'successful' => 0,
        'failed' => 0,
        'partial' => 0,
        'created' => 0,
        'updated' => 0
    ];

    foreach ($itemIds as $itemId) {
        try {
            $result = pushWooCommerceProduct($conn, $clientId, $itemId, $baseOptions);
        } catch (Throwable $itemException) {
            $result = [
                'success' => false,
                'item_id' => $itemId,
                'message' => 'Product sync exception: ' . $itemException->getMessage()
            ];

            writeWooCommerceLog(
                $conn,
                $clientId,
                'bulk_product_sync_exception',
                ['item_id' => $itemId],
                ['message' => $itemException->getMessage()],
                false,
                (int)$connection['id'],
                $itemId,
                0
            );
        }

        $results[] = $result;

        if (!empty($result['success'])) {
            $summary['successful']++;
            if (($result['action'] ?? '') === 'created') {
                $summary['created']++;
            } elseif (($result['action'] ?? '') === 'updated') {
                $summary['updated']++;
            }
        } elseif (!empty($result['partial'])) {
            $summary['partial']++;
        } else {
            $summary['failed']++;
        }
    }

    $allSuccessful = $summary['successful'] === $summary['total'];
    $anySuccessful = ($summary['successful'] + $summary['partial']) > 0;

    writeWooCommerceLog(
        $conn,
        $clientId,
        'bulk_product_sync',
        ['item_ids' => $itemIds],
        ['summary' => $summary],
        $allSuccessful,
        (int)$connection['id'],
        0,
        200
    );

    wooBulkAjaxResponse([
        'success' => $allSuccessful,
        'partial' => !$allSuccessful && $anySuccessful,
        'message' => $allSuccessful
            ? 'Selected products WooCommerce par successfully sync ho gaye.'
            : ($anySuccessful
                ? 'Bulk sync complete hua, lekin kuch products fail ya partial rahe.'
                : 'Koi product sync nahi ho saka.'),
        'summary' => $summary,
        'results' => $results
    ], $allSuccessful ? 200 : ($anySuccessful ? 207 : 422));
} catch (Throwable $e) {
    wooBulkAjaxResponse([
        'success' => false,
        'message' => 'Bulk product sync exception: ' . $e->getMessage()
    ], 500);
}