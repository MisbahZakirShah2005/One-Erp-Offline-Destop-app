<?php
/**
 * ONEERP WooCommerce Enable / Disable AJAX
 *
 * Save as:
 * /public/app/ajax/woocommerce_toggle.php
 *
 * Enable workflow:
 *   Connection test successful -> active + connected
 *   Connection test failed     -> inactive + error
 *
 * Disable workflow:
 *   status = inactive; credentials, mappings and logs remain saved.
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

function wooToggleAjaxResponse(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wooToggleReadInput(): array
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

function wooToggleValidateCsrf(string $provided): bool
{
    if ($provided === '') {
        return false;
    }

    foreach (['woo_integrations_csrf', 'integration_csrf', 'woo_ajax_csrf'] as $key) {
        $stored = (string)($_SESSION[$key] ?? '');
        if ($stored !== '' && hash_equals($stored, $provided)) {
            return true;
        }
    }

    return false;
}

function wooToggleSingleFeatureEnabled(mysqli $conn, int $clientId, string $featureKey): bool
{
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

    return $enabled;
}

function wooToggleFeatureAllowed(mysqli $conn, int $clientId, int $userId, int $roleId): bool
{
    if ($roleId === 1) {
        return true;
    }

    $featureKeys = ['woocommerce_integration', 'integrations'];
    $planAllowed = false;

    foreach ($featureKeys as $featureKey) {
        if (wooToggleSingleFeatureEnabled($conn, $clientId, $featureKey)) {
            $planAllowed = true;
            break;
        }
    }

    if (!$planAllowed) {
        return false;
    }

    $permissionCount = 0;
    $staffAllowed = false;

    $stmt = $conn->prepare("SELECT feature_key FROM users_permissions WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && ($row = $result->fetch_assoc())) {
            $permissionCount++;
            $permissionKey = trim((string)$row['feature_key']);
            if (in_array($permissionKey, $featureKeys, true)) {
                $staffAllowed = true;
            }
        }

        $stmt->close();
    }

    return $permissionCount === 0 || $staffAllowed;
}

function wooToggleSaveStatus(
    mysqli $conn,
    int $clientId,
    int $integrationId,
    string $status
): bool {
    $sets = ['status = ?'];
    $types = 's';
    $values = [$status];

    if (wooColumnExists($conn, 'integrations', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }

    $types .= 'ii';
    $values[] = $integrationId;
    $values[] = $clientId;

    $sql = "UPDATE integrations SET " . implode(', ', $sets)
        . " WHERE id = ? AND client_id = ? AND integration_type = 'WooCommerce'";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    wooBindParams($stmt, $types, $values);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wooToggleAjaxResponse([
        'success' => false,
        'message' => 'Only POST request allowed hai.'
    ], 405);
}

if (empty($_SESSION['user_id']) || empty($_SESSION['client_id'])) {
    wooToggleAjaxResponse([
        'success' => false,
        'message' => 'Session expire ho chuki hai. Dobara login karein.',
        'redirect' => '/onerp/app/public/app/views/login_view.php'
    ], 401);
}

$input = wooToggleReadInput();
$userId = (int)$_SESSION['user_id'];
$clientId = (int)$_SESSION['client_id'];
$roleId = (int)($_SESSION['role_id'] ?? 0);
$integrationId = (int)($input['integration_id'] ?? 0);
$csrfToken = trim((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));

$requestedStatus = strtolower(trim((string)($input['status'] ?? '')));
$requestedAction = strtolower(trim((string)($input['action'] ?? ($input['ajax'] ?? ''))));

if ($requestedStatus === '') {
    if (in_array($requestedAction, ['enable', 'activate', 'active'], true)) {
        $requestedStatus = 'active';
    } elseif (in_array($requestedAction, ['disable', 'deactivate', 'inactive'], true)) {
        $requestedStatus = 'inactive';
    }
}

if (!in_array($requestedStatus, ['active', 'inactive'], true)) {
    wooToggleAjaxResponse([
        'success' => false,
        'message' => 'Status active ya inactive hona chahiye.'
    ], 422);
}

if (!wooToggleValidateCsrf($csrfToken)) {
    wooToggleAjaxResponse([
        'success' => false,
        'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
    ], 419);
}

$dbFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';
$helperFile = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/woocommerce_helper.php';

if (!is_file($dbFile) || !is_file($helperFile)) {
    wooToggleAjaxResponse([
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
    wooToggleAjaxResponse([
        'success' => false,
        'message' => 'Database connection available nahi hai.'
    ], 500);
}

$conn->set_charset('utf8mb4');
require_once $helperFile;

try {
    if (!wooToggleFeatureAllowed($conn, $clientId, $userId, $roleId)) {
        wooToggleAjaxResponse([
            'success' => false,
            'message' => 'WooCommerce Integration permission available nahi hai.'
        ], 403);
    }

    if (!wooTableExists($conn, 'integrations')) {
        wooToggleAjaxResponse([
            'success' => false,
            'message' => 'integrations table database mein nahi mili.'
        ], 500);
    }

    if (!wooColumnExists($conn, 'integrations', 'connection_status')) {
        wooToggleAjaxResponse([
            'success' => false,
            'message' => 'Database migration incomplete hai: connection_status column missing hai.'
        ], 500);
    }

    $connection = getWooCommerceConnection($conn, $clientId, $integrationId, false);
    if (!$connection) {
        wooToggleAjaxResponse([
            'success' => false,
            'message' => 'WooCommerce integration record nahi mila.'
        ], 404);
    }

    $integrationId = (int)$connection['id'];

    if ($requestedStatus === 'inactive') {
        $saved = wooToggleSaveStatus($conn, $clientId, $integrationId, 'inactive');

        if (!$saved) {
            wooToggleAjaxResponse([
                'success' => false,
                'message' => 'WooCommerce integration disable nahi ho saki.'
            ], 500);
        }

        writeWooCommerceLog(
            $conn,
            $clientId,
            'integration_disabled',
            ['integration_id' => $integrationId],
            ['status' => 'inactive'],
            true,
            $integrationId,
            0,
            200
        );

        wooToggleAjaxResponse([
            'success' => true,
            'integration_id' => $integrationId,
            'status' => 'inactive',
            'connection_status' => (string)($connection['connection_status'] ?? 'untested'),
            'message' => 'WooCommerce integration successfully disabled hai. Credentials aur sync mappings safe hain.'
        ]);
    }

    if (empty($connection['is_configured'])) {
        wooUpdateConnectionTestStatus(
            $conn,
            $integrationId,
            $clientId,
            false,
            'Store URL, Consumer Key ya Consumer Secret missing hai.'
        );
        wooToggleSaveStatus($conn, $clientId, $integrationId, 'inactive');

        wooToggleAjaxResponse([
            'success' => false,
            'integration_id' => $integrationId,
            'status' => 'inactive',
            'connection_status' => 'error',
            'message' => 'Store URL, Consumer Key aur Consumer Secret pehle configure karein.'
        ], 422);
    }

    $testResult = testWooCommerceConnection($conn, $clientId, $integrationId);
    $testSuccess = !empty($testResult['success']);

    if (!$testSuccess) {
        wooToggleSaveStatus($conn, $clientId, $integrationId, 'inactive');

        writeWooCommerceLog(
            $conn,
            $clientId,
            'integration_enable_failed',
            ['integration_id' => $integrationId],
            [
                'message' => $testResult['message'] ?? 'Connection failed',
                'http_code' => $testResult['http_code'] ?? 0
            ],
            false,
            $integrationId,
            0,
            (int)($testResult['http_code'] ?? 0)
        );

        wooToggleAjaxResponse([
            'success' => false,
            'integration_id' => $integrationId,
            'status' => 'inactive',
            'connection_status' => 'error',
            'http_code' => (int)($testResult['http_code'] ?? 0),
            'message' => 'WooCommerce enable nahi hua: ' . (string)($testResult['message'] ?? 'Connection test failed.')
        ], 422);
    }

    $saved = wooToggleSaveStatus($conn, $clientId, $integrationId, 'active');
    if (!$saved) {
        wooToggleAjaxResponse([
            'success' => false,
            'integration_id' => $integrationId,
            'status' => 'inactive',
            'connection_status' => 'connected',
            'message' => 'Connection successful hai, lekin active status database mein save nahi hua.'
        ], 500);
    }

    writeWooCommerceLog(
        $conn,
        $clientId,
        'integration_enabled',
        ['integration_id' => $integrationId],
        ['status' => 'active', 'connection_status' => 'connected'],
        true,
        $integrationId,
        0,
        (int)($testResult['http_code'] ?? 200)
    );

    wooToggleAjaxResponse([
        'success' => true,
        'integration_id' => $integrationId,
        'status' => 'active',
        'connection_status' => 'connected',
        'http_code' => (int)($testResult['http_code'] ?? 200),
        'message' => 'WooCommerce connection test successful hai aur integration activate ho gayi hai.'
    ]);
} catch (Throwable $e) {
    if ($integrationId > 0) {
        try {
            wooToggleSaveStatus($conn, $clientId, $integrationId, 'inactive');
            wooUpdateConnectionTestStatus($conn, $integrationId, $clientId, false, $e->getMessage());
        } catch (Throwable $ignored) {
        }
    }

    wooToggleAjaxResponse([
        'success' => false,
        'integration_id' => $integrationId,
        'status' => 'inactive',
        'connection_status' => 'error',
        'message' => 'WooCommerce toggle exception: ' . $e->getMessage()
    ], 500);
}