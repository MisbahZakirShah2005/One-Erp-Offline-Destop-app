<?php
/*
|--------------------------------------------------------------------------
| ONEERP OPD Reception shared bootstrap
|--------------------------------------------------------------------------
| Session, DB, permission, CSRF and common OPD helpers.
| This file does not change any existing ONEERP backend or sidebar logic.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Karachi');

function opd_rec_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function opd_rec_db(): ?mysqli
{
    require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

    if (isset($conn) && $conn instanceof mysqli) {
        return $conn;
    }
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        return $mysqli;
    }
    if (isset($db) && $db instanceof mysqli) {
        return $db;
    }

    return null;
}

function opd_rec_table_exists(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

function opd_rec_feature_access(
    mysqli $conn,
    int $clientId,
    int $userId,
    int $roleId,
    array $featureKeys
): bool {
    if ($clientId <= 0 || $userId <= 0 || empty($featureKeys)) {
        return false;
    }

    if ($roleId === 1) {
        return true;
    }

    $featureKeys = array_values(array_unique(array_filter(array_map('trim', $featureKeys))));
    if (empty($featureKeys)) {
        return false;
    }

    $enabled = false;

    foreach ($featureKeys as $featureKey) {
        $keyEnabled = false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM subscriptions s
             INNER JOIN plan_features pf ON pf.plan_id = s.plan_id
             WHERE s.client_id = ?
               AND s.status = 'active'
               AND (s.end_date IS NULL OR s.end_date >= CURDATE())
               AND pf.feature_key = ?
               AND pf.enabled = 1
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('is', $clientId, $featureKey);
            $stmt->execute();
            $result = $stmt->get_result();
            $keyEnabled = $result && $result->num_rows > 0;
            $stmt->close();
        }

        $stmt = $conn->prepare(
            'SELECT enabled FROM client_feature_overrides '
            . 'WHERE client_id = ? AND feature_key = ? LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('is', $clientId, $featureKey);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && ($row = $result->fetch_assoc())) {
                $keyEnabled = (int)$row['enabled'] === 1;
            }
            $stmt->close();
        }

        if ($keyEnabled) {
            $enabled = true;
            break;
        }
    }

    if (!$enabled) {
        return false;
    }

    // Existing sidebar behavior: when any permission rows exist, user is staff
    // and must possess at least one of the exact required OPD feature keys.
    $permissionCount = 0;
    $hasRequiredPermission = false;
    $stmt = $conn->prepare('SELECT feature_key FROM users_permissions WHERE user_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $permissionCount++;
            if (in_array(trim((string)$row['feature_key']), $featureKeys, true)) {
                $hasRequiredPermission = true;
            }
        }
        $stmt->close();
    }

    return $permissionCount === 0 || $hasRequiredPermission;
}

function opd_rec_bootstrap(array $featureKeys, bool $api = false): array
{
    if (empty($_SESSION['user_id'])) {
        if ($api) {
            opd_rec_json(['success' => false, 'message' => 'Session expired. Please login again.'], 401);
        }
        header('Location: /public/app/views/login_view.php');
        exit;
    }

    $conn = opd_rec_db();
    if (!($conn instanceof mysqli)) {
        if ($api) {
            opd_rec_json(['success' => false, 'message' => 'Database connection not found.'], 500);
        }
        die('Database connection not found. Please check config/db.php.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $context = [
        'conn' => $conn,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'role_id' => (int)($_SESSION['role_id'] ?? 0),
        'client_id' => (int)($_SESSION['client_id'] ?? 0),
        'session_branch_id' => (int)(
            $_SESSION['branch_id']
            ?? $_SESSION['selected_branch_id']
            ?? $_SESSION['current_branch_id']
            ?? 0
        )
    ];

    if (!opd_rec_feature_access(
        $conn,
        $context['client_id'],
        $context['user_id'],
        $context['role_id'],
        $featureKeys
    )) {
        if ($api) {
            opd_rec_json(['success' => false, 'message' => 'You do not have permission for this OPD action.'], 403);
        }
        http_response_code(403);
        die('Access denied! OPD Reception feature is not enabled for this account.');
    }

    if (empty($_SESSION['opd_csrf_token'])) {
        $_SESSION['opd_csrf_token'] = bin2hex(random_bytes(32));
    }
    $context['csrf_token'] = (string)$_SESSION['opd_csrf_token'];

    return $context;
}

function opd_rec_require_csrf(): void
{
    $provided = (string)(
        $_POST['csrf_token']
        ?? $_GET['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ''
    );
    $stored = (string)($_SESSION['opd_csrf_token'] ?? '');

    if ($stored === '' || $provided === '' || !hash_equals($stored, $provided)) {
        opd_rec_json(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 419);
    }
}

function opd_rec_validate_branch(mysqli $conn, int $clientId, int $branchId): int
{
    if ($branchId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare(
        'SELECT id FROM branches '
        . 'WHERE id = ? AND client_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('ii', $branchId, $clientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = $result && $result->num_rows > 0;
    $stmt->close();

    return $valid ? $branchId : 0;
}

function opd_rec_client_has_branches(mysqli $conn, int $clientId): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM branches WHERE client_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $hasBranches = $result && $result->num_rows > 0;
    $stmt->close();
    return $hasBranches;
}

function opd_rec_settings(mysqli $conn, int $clientId, int $branchId): array
{
    $settings = [
        'mr_prefix' => 'MR',
        'mr_number_length' => 6,
        'default_token_prefix' => 'OPD',
        'token_number_length' => 3,
        'auto_print_token' => 1,
        'show_waiting_count' => 1,
        'receipt_footer' => ''
    ];

    if (!opd_rec_table_exists($conn, 'opd_settings')) {
        return $settings;
    }

    $stmt = $conn->prepare(
        "SELECT mr_prefix, mr_number_length, default_token_prefix,
                token_number_length, auto_print_token, show_waiting_count,
                receipt_footer
         FROM opd_settings
         WHERE client_id = ?
           AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)
         ORDER BY CASE WHEN branch_id = ? THEN 0 ELSE 1 END, id DESC
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('iii', $clientId, $branchId, $branchId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && ($row = $result->fetch_assoc())) {
            $settings = array_merge($settings, $row);
        }
        $stmt->close();
    }

    $settings['mr_number_length'] = max(3, min(10, (int)$settings['mr_number_length']));
    $settings['token_number_length'] = max(2, min(8, (int)$settings['token_number_length']));
    $settings['auto_print_token'] = (int)$settings['auto_print_token'];
    $settings['show_waiting_count'] = (int)$settings['show_waiting_count'];

    return $settings;
}

function opd_rec_clean_phone(string $value): string
{
    $value = trim($value);
    return preg_replace('/[^0-9+\-() ]/', '', $value) ?? '';
}

function opd_rec_age_label(?int $years, ?int $months): string
{
    $parts = [];
    if ($years !== null && $years > 0) {
        $parts[] = $years . 'y';
    }
    if ($months !== null && $months > 0) {
        $parts[] = $months . 'm';
    }
    return $parts ? implode(' ', $parts) : '—';
}
