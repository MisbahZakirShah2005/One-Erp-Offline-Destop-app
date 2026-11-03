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
    && can_show('woocommerce_sync_logs');
$wooConnectionActive = isWooCommerceActive($conn, $loggedClientId);

if (!$hasWooPermission || !$wooConnectionActive) {
    $_SESSION['integration_error'] = !$hasWooPermission
        ? 'Aapke active plan ya staff permission mein WooCommerce Sync Logs enabled nahi hain.'
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

/* ================= CSRF ================= */
if (empty($_SESSION['woo_logs_csrf'])) {
    $_SESSION['woo_logs_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['woo_logs_csrf'];

/* ================= LOCAL HELPERS ================= */
if (!function_exists('wooLogsJsonResponse')) {
    function wooLogsJsonResponse(array $payload, int $httpCode = 200): void
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

if (!function_exists('wooLogsBindParams')) {
    function wooLogsBindParams(
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

if (!function_exists('wooLogsQueryUrl')) {
    function wooLogsQueryUrl(array $changes = []): string
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

if (!function_exists('wooLogsActionLabel')) {
    function wooLogsActionLabel(string $action): string
    {
        $action = trim($action);
        if ($action === '') {
            return 'Unknown Action';
        }

        return ucwords(str_replace(['_', '-'], ' ', $action));
    }
}

if (!function_exists('wooLogsShortText')) {
    function wooLogsShortText(string $value, int $limit = 100): string
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

if (!function_exists('wooLogsDecodeForResponse')) {
    function wooLogsDecodeForResponse($value)
    {
        $value = (string)$value;
        if (trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}

/* ================= TABLE CAPABILITIES ================= */
$logsTableReady = wooTableExists($conn, 'integration_logs');
$hasIntegrationId = $logsTableReady
    && wooColumnExists($conn, 'integration_logs', 'integration_id');
$hasItemId = $logsTableReady
    && wooColumnExists($conn, 'integration_logs', 'item_id');
$hasHttpCode = $logsTableReady
    && wooColumnExists($conn, 'integration_logs', 'http_code');

/* ================= AJAX BACKEND ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $requestToken = (string)($_POST['csrf_token'] ?? '');

    if ($requestToken === '' || !hash_equals($csrfToken, $requestToken)) {
        wooLogsJsonResponse([
            'success' => false,
            'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
        ], 419);
    }

    if (!can_show('woocommerce_sync_logs')) {
        wooLogsJsonResponse([
            'success' => false,
            'message' => 'WooCommerce Sync Logs permission available nahi hai.'
        ], 403);
    }

    if (!isWooCommerceActive($conn, $loggedClientId)) {
        wooLogsJsonResponse([
            'success' => false,
            'message' => 'WooCommerce integration Active aur Connected nahi hai.',
            'redirect' => '/onerp/app/public/app/views/integrations.php'
        ], 403);
    }

    if (!$logsTableReady) {
        wooLogsJsonResponse([
            'success' => false,
            'message' => 'integration_logs table missing hai.'
        ], 500);
    }

    $action = trim((string)$_POST['ajax']);

    if ($action === 'log_detail') {
        $logId = (int)($_POST['log_id'] ?? 0);
        if ($logId <= 0) {
            wooLogsJsonResponse([
                'success' => false,
                'message' => 'Invalid log ID.'
            ], 422);
        }

        $columns = [
            'l.id',
            'l.integration_type',
            'l.action',
            'l.request',
            'l.response',
            'l.status',
            'l.created_at'
        ];
        if ($hasIntegrationId) {
            $columns[] = 'l.integration_id';
        }
        if ($hasItemId) {
            $columns[] = 'l.item_id';
        }
        if ($hasHttpCode) {
            $columns[] = 'l.http_code';
        }

        $sql = 'SELECT ' . implode(', ', $columns)
            . " FROM integration_logs l
                WHERE l.id = ?
                  AND l.client_id = ?
                  AND l.integration_type = 'WooCommerce'";

        $types = 'ii';
        $values = [$logId, $loggedClientId];

        if ($hasIntegrationId) {
            $sql .= ' AND (l.integration_id = ? OR l.integration_id IS NULL)';
            $types .= 'i';
            $values[] = $integrationId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            wooLogsJsonResponse([
                'success' => false,
                'message' => 'Log detail query prepare nahi ho saki.'
            ], 500);
        }

        wooLogsBindParams($stmt, $types, $values);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            wooLogsJsonResponse([
                'success' => false,
                'message' => 'Log record nahi mila.'
            ], 404);
        }

        $row['id'] = (int)$row['id'];
        $row['status'] = (int)$row['status'];
        if (isset($row['integration_id'])) {
            $row['integration_id'] = (int)$row['integration_id'];
        }
        if (isset($row['item_id'])) {
            $row['item_id'] = (int)$row['item_id'];
        }
        if (isset($row['http_code'])) {
            $row['http_code'] = (int)$row['http_code'];
        }
        $row['request'] = wooLogsDecodeForResponse($row['request'] ?? '');
        $row['response'] = wooLogsDecodeForResponse($row['response'] ?? '');

        wooLogsJsonResponse([
            'success' => true,
            'log' => $row
        ]);
    }

    if ($action === 'delete_logs') {
        $idsRaw = $_POST['ids'] ?? [];
        if (is_string($idsRaw)) {
            $decoded = json_decode($idsRaw, true);
            $idsRaw = is_array($decoded) ? $decoded : explode(',', $idsRaw);
        }

        $ids = [];
        foreach ((array)$idsRaw as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);

        if (empty($ids)) {
            wooLogsJsonResponse([
                'success' => false,
                'message' => 'Delete karne ke liye logs select karein.'
            ], 422);
        }

        if (count($ids) > 200) {
            wooLogsJsonResponse([
                'success' => false,
                'message' => 'Ek request mein maximum 200 logs delete kiye ja sakte hain.'
            ], 422);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM integration_logs
                WHERE client_id = ?
                  AND integration_type = 'WooCommerce'
                  AND id IN ({$placeholders})";
        $types = 'i' . str_repeat('i', count($ids));
        $values = array_merge([$loggedClientId], $ids);

        if ($hasIntegrationId) {
            $sql .= ' AND (integration_id = ? OR integration_id IS NULL)';
            $types .= 'i';
            $values[] = $integrationId;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            wooLogsJsonResponse([
                'success' => false,
                'message' => 'Delete query prepare nahi ho saki.'
            ], 500);
        }

        wooLogsBindParams($stmt, $types, $values);
        $stmt->execute();
        $deleted = max(0, (int)$stmt->affected_rows);
        $stmt->close();

        wooLogsJsonResponse([
            'success' => true,
            'message' => $deleted . ' log(s) successfully delete ho gaye.',
            'deleted' => $deleted
        ]);
    }

    if ($action === 'clear_logs') {
        $sql = "DELETE FROM integration_logs
                WHERE client_id = ?
                  AND integration_type = 'WooCommerce'";
        $types = 'i';
        $values = [$loggedClientId];

        if ($hasIntegrationId) {
            $sql .= ' AND (integration_id = ? OR integration_id IS NULL)';
            $types .= 'i';
            $values[] = $integrationId;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            wooLogsJsonResponse([
                'success' => false,
                'message' => 'Clear logs query prepare nahi ho saki.'
            ], 500);
        }

        wooLogsBindParams($stmt, $types, $values);
        $stmt->execute();
        $deleted = max(0, (int)$stmt->affected_rows);
        $stmt->close();

        wooLogsJsonResponse([
            'success' => true,
            'message' => 'WooCommerce logs clear ho gaye.',
            'deleted' => $deleted
        ]);
    }

    wooLogsJsonResponse([
        'success' => false,
        'message' => 'Unsupported logs action.'
    ], 400);
}

/* ================= FILTERS ================= */
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedPerPage = [10, 25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 25);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}
if (!in_array($statusFilter, ['', 'success', 'failed'], true)) {
    $statusFilter = '';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

/* ================= QUERY BUILDER ================= */
$pageError = $logsTableReady
    ? ''
    : 'integration_logs table missing hai. Pehle WooCommerce database migration run karein.';
$stats = ['total' => 0, 'success' => 0, 'failed' => 0, 'today' => 0];
$actions = [];
$logs = [];
$totalRows = 0;
$totalPages = 1;

$baseWhere = [
    'l.client_id = ?',
    "l.integration_type = 'WooCommerce'"
];
$baseTypes = 'i';
$baseValues = [$loggedClientId];

if ($hasIntegrationId) {
    $baseWhere[] = '(l.integration_id = ? OR l.integration_id IS NULL)';
    $baseTypes .= 'i';
    $baseValues[] = $integrationId;
}

if ($logsTableReady) {
    $statsSql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN l.status = 1 THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN l.status <> 1 THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN DATE(l.created_at) = CURDATE() THEN 1 ELSE 0 END) AS today
        FROM integration_logs l
        WHERE " . implode(' AND ', $baseWhere);
    $statsStmt = $conn->prepare($statsSql);

    if ($statsStmt) {
        wooLogsBindParams($statsStmt, $baseTypes, $baseValues);
        $statsStmt->execute();
        $statsResult = $statsStmt->get_result();
        $statsRow = $statsResult ? $statsResult->fetch_assoc() : [];
        $statsStmt->close();

        foreach ($stats as $key => $value) {
            $stats[$key] = (int)($statsRow[$key] ?? 0);
        }
    }

    $actionsSql = 'SELECT DISTINCT l.action FROM integration_logs l WHERE '
        . implode(' AND ', $baseWhere)
        . " AND l.action IS NOT NULL AND l.action <> '' ORDER BY l.action ASC";
    $actionsStmt = $conn->prepare($actionsSql);
    if ($actionsStmt) {
        wooLogsBindParams($actionsStmt, $baseTypes, $baseValues);
        $actionsStmt->execute();
        $actionsResult = $actionsStmt->get_result();
        while ($row = $actionsResult->fetch_assoc()) {
            $actions[] = (string)$row['action'];
        }
        $actionsStmt->close();
    }

    $where = $baseWhere;
    $types = $baseTypes;
    $values = $baseValues;

    if ($search !== '') {
        $searchParts = [
            'l.action LIKE ?',
            'l.request LIKE ?',
            'l.response LIKE ?'
        ];
        $like = '%' . $search . '%';
        $types .= 'sss';
        $values[] = $like;
        $values[] = $like;
        $values[] = $like;

        if ($hasItemId) {
            $searchParts[] = 'CAST(l.item_id AS CHAR) LIKE ?';
            $types .= 's';
            $values[] = $like;
        }

        $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    if ($statusFilter === 'success') {
        $where[] = 'l.status = 1';
    } elseif ($statusFilter === 'failed') {
        $where[] = 'l.status <> 1';
    }

    if ($actionFilter !== '') {
        $where[] = 'l.action = ?';
        $types .= 's';
        $values[] = $actionFilter;
    }

    if ($dateFrom !== '') {
        $where[] = 'l.created_at >= ?';
        $types .= 's';
        $values[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $where[] = 'l.created_at <= ?';
        $types .= 's';
        $values[] = $dateTo . ' 23:59:59';
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $countSql = 'SELECT COUNT(*) AS total_rows FROM integration_logs l' . $whereSql;
    $countStmt = $conn->prepare($countSql);

    if ($countStmt) {
        wooLogsBindParams($countStmt, $types, $values);
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

    $columns = [
        'l.id',
        'l.action',
        'l.request',
        'l.response',
        'l.status',
        'l.created_at'
    ];
    if ($hasIntegrationId) {
        $columns[] = 'l.integration_id';
    }
    if ($hasItemId) {
        $columns[] = 'l.item_id';
    }
    if ($hasHttpCode) {
        $columns[] = 'l.http_code';
    }

    $dataSql = 'SELECT ' . implode(', ', $columns)
        . ' FROM integration_logs l'
        . $whereSql
        . ' ORDER BY l.id DESC LIMIT ? OFFSET ?';
    $dataTypes = $types . 'ii';
    $dataValues = $values;
    $dataValues[] = $perPage;
    $dataValues[] = $offset;
    $dataStmt = $conn->prepare($dataSql);

    if ($dataStmt) {
        wooLogsBindParams($dataStmt, $dataTypes, $dataValues);
        $dataStmt->execute();
        $dataResult = $dataStmt->get_result();
        while ($row = $dataResult->fetch_assoc()) {
            $logs[] = $row;
        }
        $dataStmt->close();
    }

    /* ================= CSV EXPORT ================= */
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $exportColumns = [
            'l.id',
            'l.action',
            'l.status',
            'l.request',
            'l.response',
            'l.created_at'
        ];
        if ($hasIntegrationId) {
            $exportColumns[] = 'l.integration_id';
        }
        if ($hasItemId) {
            $exportColumns[] = 'l.item_id';
        }
        if ($hasHttpCode) {
            $exportColumns[] = 'l.http_code';
        }

        $exportSql = 'SELECT ' . implode(', ', $exportColumns)
            . ' FROM integration_logs l'
            . $whereSql
            . ' ORDER BY l.id DESC LIMIT 10000';
        $exportStmt = $conn->prepare($exportSql);

        if (!$exportStmt) {
            http_response_code(500);
            exit('Export query prepare failed.');
        }

        wooLogsBindParams($exportStmt, $types, $values);
        $exportStmt->execute();
        $exportResult = $exportStmt->get_result();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="woocommerce-sync-logs-' . date('Y-m-d-His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");

        $headers = ['ID', 'Action', 'Status', 'Request', 'Response', 'Created At'];
        if ($hasIntegrationId) {
            $headers[] = 'Integration ID';
        }
        if ($hasItemId) {
            $headers[] = 'Item ID';
        }
        if ($hasHttpCode) {
            $headers[] = 'HTTP Code';
        }
        fputcsv($output, $headers);

        while ($row = $exportResult->fetch_assoc()) {
            $csvRow = [
                $row['id'],
                $row['action'],
                (int)$row['status'] === 1 ? 'Success' : 'Failed',
                $row['request'],
                $row['response'],
                $row['created_at']
            ];
            if ($hasIntegrationId) {
                $csvRow[] = $row['integration_id'];
            }
            if ($hasItemId) {
                $csvRow[] = $row['item_id'];
            }
            if ($hasHttpCode) {
                $csvRow[] = $row['http_code'];
            }
            fputcsv($output, $csvRow);
        }

        fclose($output);
        $exportStmt->close();
        exit;
    }
}

$displayFrom = $totalRows > 0 ? (($page - 1) * $perPage) + 1 : 0;
$displayTo = min($totalRows, $page * $perPage);
?>
<!DOCTYPE html>
<html lang="en" data-pos-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WooCommerce Sync Logs - ONEERP</title>

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
        :root{--woo-purple:#7f54b3;--woo-border:rgba(148,163,184,.16);--woo-muted:#94a3b8}
        *{box-sizing:border-box}html,body{margin:0;min-height:100%;overflow-x:hidden}
        body{background:#070b14;color:#f8fafc;font-family:Inter,"Segoe UI",Arial,sans-serif}
        .container-scroll{display:flex;min-height:100vh;width:100%}
        .main-panel{margin-left:270px;width:calc(100% - 270px);min-height:100vh;background:radial-gradient(circle at 12% 6%,rgba(127,84,179,.30),transparent 28%),radial-gradient(circle at 85% 4%,rgba(59,130,246,.13),transparent 30%),linear-gradient(135deg,#050914 0%,#0b1024 45%,#160f2d 72%,#090d18 100%)}
        .topbar{height:70px;background:#070b14;border-bottom:1px solid var(--woo-border);position:sticky;top:0;z-index:1030}
        .content-wrapper{padding:26px;min-height:calc(100vh - 70px)}
        .page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:18px}
        .eyebrow{display:inline-flex;align-items:center;gap:8px;color:#c4b5fd;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.09em;margin-bottom:8px}
        .page-title{font-size:30px;font-weight:950;letter-spacing:-.04em;margin:0;color:#fff}.page-subtitle{margin:8px 0 0;color:#94a3b8;font-size:14px;font-weight:600}
        .header-actions,.toolbar-left,.toolbar-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .btn-woo,.btn-soft,.btn-danger-soft{min-height:44px;border-radius:12px;padding:0 16px;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;font-weight:900;font-size:13px;transition:.2s ease;cursor:pointer}
        .btn-woo{border:none;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;box-shadow:0 12px 28px rgba(124,58,237,.25)}
        .btn-soft{border:1px solid var(--woo-border);background:rgba(15,23,42,.72);color:#e5e7eb}.btn-danger-soft{border:1px solid rgba(239,68,68,.26);background:rgba(239,68,68,.12);color:#fca5a5}
        .btn-woo:hover,.btn-soft:hover,.btn-danger-soft:hover{transform:translateY(-1px);color:#fff}.btn-woo:disabled,.btn-soft:disabled,.btn-danger-soft:disabled{opacity:.45;cursor:not-allowed;transform:none}
        .connection-strip,.glass-card{background:radial-gradient(circle at 16% 0%,rgba(124,58,237,.13),transparent 38%),linear-gradient(135deg,rgba(15,23,42,.82),rgba(17,24,39,.64));border:1px solid var(--woo-border);border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.26);backdrop-filter:blur(18px)}
        .connection-strip{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;margin-bottom:18px;flex-wrap:wrap}.connection-main{display:flex;align-items:center;gap:12px;min-width:0}.connection-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:rgba(34,197,94,.14);color:#4ade80;font-size:20px}.connection-title{font-weight:900;color:#fff}.connection-url{font-size:12px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:520px}.connected-badge{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:999px;background:rgba(34,197,94,.13);color:#4ade80;border:1px solid rgba(34,197,94,.24);font-size:12px;font-weight:900}
        .stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.stat-card{position:relative;overflow:hidden;min-height:126px;padding:18px;border-radius:18px;color:#fff;border:1px solid rgba(255,255,255,.10);box-shadow:0 18px 38px rgba(0,0,0,.25)}.stat-card::after{content:"";position:absolute;width:88px;height:88px;right:-18px;top:-18px;border-radius:28px;background:rgba(255,255,255,.10);transform:rotate(22deg)}.stat-total{background:linear-gradient(135deg,#2563eb,#6d4cff)}.stat-success{background:linear-gradient(135deg,#047857,#16a34a)}.stat-failed{background:linear-gradient(135deg,#be123c,#ef4444)}.stat-today{background:linear-gradient(135deg,#d97706,#f59e0b)}.stat-icon{font-size:24px;margin-bottom:12px}.stat-label{font-size:13px;font-weight:800;opacity:.86}.stat-number{font-size:29px;font-weight:950;line-height:1.1;margin-top:4px}
        .filter-card{padding:14px;margin-bottom:16px}.filter-grid{display:grid;grid-template-columns:minmax(240px,1.5fr) repeat(4,minmax(145px,.65fr)) auto;gap:10px}.search-wrap{position:relative}.search-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8}.search-wrap input{padding-left:40px!important}.filter-control{width:100%;height:44px;border-radius:11px;border:1px solid rgba(148,163,184,.18);background:rgba(15,23,42,.76);color:#e5e7eb;padding:0 12px;font-size:13px;font-weight:700;outline:none}.filter-control:focus{border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(124,58,237,.14)}.filter-control option{background:#0f172a;color:#e5e7eb}
        .toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid var(--woo-border)}.selection-count,.result-count{font-size:12px;color:#94a3b8;font-weight:800}
        .table-responsive{overflow:auto}.logs-table{width:100%;min-width:1160px;border-collapse:separate;border-spacing:0}.logs-table th{padding:13px 12px;background:rgba(7,11,20,.78);color:#cbd5e1;border-bottom:1px solid var(--woo-border);font-size:11px;font-weight:950;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}.logs-table td{padding:13px 12px;background:rgba(15,23,42,.28);color:#e5e7eb;border-bottom:1px solid rgba(148,163,184,.09);font-size:13px;font-weight:650;vertical-align:middle}.logs-table tr:hover td{background:rgba(124,58,237,.10)}
        .action-name{font-weight:950;color:#fff;min-width:180px}.action-code{font-size:10px;color:#94a3b8;margin-top:3px}.status-badge,.http-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid transparent}.badge-success{background:rgba(34,197,94,.13);color:#4ade80;border-color:rgba(34,197,94,.22)}.badge-failed{background:rgba(239,68,68,.13);color:#f87171;border-color:rgba(239,68,68,.22)}.http-good{background:rgba(34,197,94,.10);color:#4ade80;border-color:rgba(34,197,94,.18)}.http-warn{background:rgba(245,158,11,.12);color:#fbbf24;border-color:rgba(245,158,11,.22)}.http-bad{background:rgba(239,68,68,.12);color:#f87171;border-color:rgba(239,68,68,.22)}
        .summary-text{max-width:320px;white-space:normal;line-height:1.45;color:#cbd5e1}.muted-text{font-size:11px;color:#94a3b8}.icon-btn{width:38px;height:38px;border-radius:10px;border:1px solid rgba(148,163,184,.18);background:rgba(15,23,42,.72);color:#e5e7eb;display:inline-grid;place-items:center;cursor:pointer}.icon-btn:hover{background:rgba(124,58,237,.18);color:#fff}
        .empty-state,.error-state{text-align:center;padding:54px 20px}.empty-state i,.error-state i{font-size:46px;color:#a78bfa}.empty-state h3,.error-state h3{margin:12px 0 7px;color:#fff;font-weight:950}.empty-state p,.error-state p{margin:0;color:#94a3b8}.pagination-wrap{display:flex;justify-content:center;padding:16px}.pagination .page-link{background:rgba(15,23,42,.72);border-color:rgba(148,163,184,.16);color:#cbd5e1}.pagination .page-item.active .page-link{background:#7c3aed;border-color:#7c3aed;color:#fff}
        .modal-content{background:radial-gradient(circle at 20% 0%,rgba(124,58,237,.18),transparent 35%),linear-gradient(135deg,#0f172a,#17102d);color:#fff;border:1px solid rgba(139,92,246,.35);border-radius:18px;box-shadow:0 30px 90px rgba(0,0,0,.62)}.modal-header,.modal-footer{border-color:rgba(148,163,184,.15)}.modal-title{font-weight:950}.btn-close{filter:invert(1)}.detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}.detail-chip{padding:11px;border-radius:12px;background:rgba(15,23,42,.65);border:1px solid rgba(148,163,184,.14)}.detail-label{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;font-weight:900}.detail-value{margin-top:4px;color:#fff;font-weight:900;word-break:break-word}.code-title{font-size:12px;font-weight:950;color:#c4b5fd;margin:14px 0 7px}.code-box{max-height:300px;overflow:auto;margin:0;padding:14px;border-radius:13px;background:#020617;color:#dbeafe;border:1px solid rgba(148,163,184,.16);white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.55}.toast-box{position:fixed;right:22px;bottom:22px;z-index:1000000;max-width:min(420px,calc(100vw - 44px));padding:14px 16px;border-radius:13px;background:#0f172a;color:#fff;border:1px solid rgba(148,163,184,.20);box-shadow:0 20px 54px rgba(0,0,0,.4);display:none;font-size:13px;font-weight:750}.toast-box.show{display:block}.toast-box.success{border-color:rgba(34,197,94,.36)}.toast-box.error{border-color:rgba(239,68,68,.38)}
        @media(max-width:1199px){.stats-grid{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:1fr 1fr 1fr}.filter-grid .search-wrap{grid-column:1/-1}}
        @media(max-width:991px){.main-panel{margin-left:0;width:100%}.content-wrapper{padding:16px}.filter-grid{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:576px){.stats-grid{grid-template-columns:1fr}.page-title{font-size:25px}.header-actions{width:100%}.header-actions a{flex:1}.toolbar-left,.toolbar-right{width:100%}.toolbar-left button,.toolbar-right a{flex:1}.detail-grid{grid-template-columns:1fr}}
        html[data-pos-theme="light"] body{background:#f3f6fb;color:#0f172a}html[data-pos-theme="light"] body .main-panel{background:radial-gradient(circle at 12% 7%,rgba(124,58,237,.08),transparent 28%),linear-gradient(135deg,#f8fafc,#f1f5f9 52%,#faf7ff)}html[data-pos-theme="light"] body .topbar{background:#071a35;border-bottom-color:rgba(148,163,184,.14)}
        html[data-pos-theme="light"] body .page-title,html[data-pos-theme="light"] body .action-name,html[data-pos-theme="light"] body .empty-state h3,html[data-pos-theme="light"] body .error-state h3{color:#0f172a}html[data-pos-theme="light"] body .page-subtitle,html[data-pos-theme="light"] body .action-code,html[data-pos-theme="light"] body .muted-text,html[data-pos-theme="light"] body .selection-count,html[data-pos-theme="light"] body .result-count{color:#64748b}
        html[data-pos-theme="light"] body .connection-strip,html[data-pos-theme="light"] body .glass-card{background:#fff;background-image:none;border-color:#dce4ef;box-shadow:0 12px 32px rgba(15,23,42,.07);backdrop-filter:none}html[data-pos-theme="light"] body .connection-title{color:#0f172a}html[data-pos-theme="light"] body .connection-url{color:#64748b}
        html[data-pos-theme="light"] body .filter-control{background:#fff;color:#0f172a;border-color:#cbd5e1}html[data-pos-theme="light"] body .filter-control option{background:#fff;color:#0f172a}html[data-pos-theme="light"] body .logs-table th{background:#f1f5f9;color:#334155;border-color:#cbd5e1}html[data-pos-theme="light"] body .logs-table td{background:#fff;color:#1e293b;border-color:#e8edf4}html[data-pos-theme="light"] body .logs-table tr:nth-child(even) td{background:#fbfcff}html[data-pos-theme="light"] body .logs-table tr:hover td{background:#f6f2ff}html[data-pos-theme="light"] body .summary-text{color:#475569}html[data-pos-theme="light"] body .btn-soft,html[data-pos-theme="light"] body .icon-btn{background:#fff;color:#334155;border-color:#cbd5e1;box-shadow:none}html[data-pos-theme="light"] body .pagination .page-link{background:#fff;color:#334155;border-color:#dce4ef}
        html[data-pos-theme="light"] body .modal-content{background:#fff;background-image:none;color:#0f172a;border-color:#dce4ef}html[data-pos-theme="light"] body .modal-header,html[data-pos-theme="light"] body .modal-footer{border-color:#e2e8f0}html[data-pos-theme="light"] body .modal-title,html[data-pos-theme="light"] body .detail-value{color:#0f172a}html[data-pos-theme="light"] body .detail-chip{background:#f8fafc;border-color:#e2e8f0}html[data-pos-theme="light"] body .btn-close{filter:none}
    </style>
</head>
<body>
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar"><?= $sidebarHtml ?></div>
    <div class="main-panel">
        <div class="topbar"><?php include(__DIR__ . '/partials/topbar.php'); ?></div>
        <main class="content-wrapper">
            <div class="page-header">
                <div>
                    <div class="eyebrow"><i class="bi bi-wordpress"></i> WooCommerce Integration</div>
                    <h1 class="page-title">Sync Logs</h1>
                    <p class="page-subtitle">Product, inventory aur connection requests ka complete audit trail.</p>
                </div>
                <div class="header-actions">
                    <a href="woocommerce_products.php" class="btn-soft"><i class="bi bi-box-seam"></i> Product Sync</a>
                    <a href="woocommerce_inventory_sync.php" class="btn-soft"><i class="bi bi-boxes"></i> Inventory Sync</a>
                    <a href="<?= htmlspecialchars(wooLogsQueryUrl(['export' => 'csv', 'page' => null])) ?>" class="btn-woo"><i class="bi bi-download"></i> Export CSV</a>
                </div>
            </div>

            <section class="connection-strip">
                <div class="connection-main">
                    <div class="connection-icon"><i class="bi bi-check-circle-fill"></i></div>
                    <div style="min-width:0"><div class="connection-title"><?= htmlspecialchars((string)($wooConnection['integration_name'] ?? 'WooCommerce')) ?></div><div class="connection-url" title="<?= htmlspecialchars($storeUrl) ?>"><?= htmlspecialchars($storeUrl) ?></div></div>
                </div>
                <span class="connected-badge"><i class="bi bi-shield-check"></i> Active &amp; Connected</span>
            </section>

            <section class="stats-grid">
                <article class="stat-card stat-total"><div class="stat-icon"><i class="bi bi-journal-text"></i></div><div class="stat-label">Total Logs</div><div class="stat-number"><?= number_format($stats['total']) ?></div></article>
                <article class="stat-card stat-success"><div class="stat-icon"><i class="bi bi-check-circle"></i></div><div class="stat-label">Successful</div><div class="stat-number"><?= number_format($stats['success']) ?></div></article>
                <article class="stat-card stat-failed"><div class="stat-icon"><i class="bi bi-exclamation-octagon"></i></div><div class="stat-label">Failed</div><div class="stat-number"><?= number_format($stats['failed']) ?></div></article>
                <article class="stat-card stat-today"><div class="stat-icon"><i class="bi bi-calendar-check"></i></div><div class="stat-label">Today</div><div class="stat-number"><?= number_format($stats['today']) ?></div></article>
            </section>

            <form method="get" class="glass-card filter-card">
                <div class="filter-grid">
                    <div class="search-wrap"><i class="bi bi-search"></i><input type="text" class="filter-control" name="search" placeholder="Search action, request, response or item ID..." value="<?= htmlspecialchars($search) ?>"></div>
                    <select class="filter-control" name="status"><option value="">All Status</option><option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Success</option><option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option></select>
                    <select class="filter-control" name="action"><option value="">All Actions</option><?php foreach ($actions as $action): ?><option value="<?= htmlspecialchars($action) ?>" <?= $actionFilter === $action ? 'selected' : '' ?>><?= htmlspecialchars(wooLogsActionLabel($action)) ?></option><?php endforeach; ?></select>
                    <input type="date" class="filter-control" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" title="From date">
                    <input type="date" class="filter-control" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" title="To date">
                    <button type="submit" class="btn-woo"><i class="bi bi-funnel"></i> Apply</button>
                </div>
                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
            </form>

            <section class="glass-card">
                <?php if ($pageError !== ''): ?>
                    <div class="error-state"><i class="bi bi-database-exclamation"></i><h3>Sync Logs Setup Incomplete</h3><p><?= htmlspecialchars($pageError) ?></p></div>
                <?php else: ?>
                    <div class="toolbar">
                        <div class="toolbar-left">
                            <button type="button" class="btn-danger-soft" id="deleteSelectedBtn" disabled><i class="bi bi-trash"></i> Delete Selected</button>
                            <button type="button" class="btn-danger-soft" id="clearAllBtn" <?= $totalRows <= 0 ? 'disabled' : '' ?>><i class="bi bi-trash3"></i> Clear All Logs</button>
                            <span class="selection-count" id="selectionCount">0 logs selected</span>
                        </div>
                        <div class="toolbar-right"><span class="result-count">Showing <?= number_format($displayFrom) ?>–<?= number_format($displayTo) ?> of <?= number_format($totalRows) ?></span><select class="filter-control" id="perPageSelect" style="width:110px"><?php foreach ($allowedPerPage as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> / page</option><?php endforeach; ?></select></div>
                    </div>

                    <?php if (empty($logs)): ?>
                        <div class="empty-state"><i class="bi bi-journal-x"></i><h3>No Logs Found</h3><p>Current filters ke mutabiq koi WooCommerce log available nahi hai.</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="logs-table">
                                <thead><tr><th style="width:48px"><input type="checkbox" class="form-check-input" id="selectAllLogs"></th><th>ID / Action</th><?php if ($hasItemId): ?><th>Item ID</th><?php endif; ?><th>Status</th><?php if ($hasHttpCode): ?><th>HTTP</th><?php endif; ?><th>Response Summary</th><th>Created At</th><th>Details</th></tr></thead>
                                <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <?php
                                    $success = (int)$log['status'] === 1;
                                    $httpCode = $hasHttpCode ? (int)($log['http_code'] ?? 0) : 0;
                                    $httpClass = $httpCode >= 200 && $httpCode < 300 ? 'http-good' : ($httpCode >= 400 ? 'http-bad' : 'http-warn');
                                    $responseSummary = wooLogsShortText((string)($log['response'] ?? ''), 150);
                                    if ($responseSummary === '') {
                                        $responseSummary = wooLogsShortText((string)($log['request'] ?? ''), 150);
                                    }
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="form-check-input log-checkbox" value="<?= (int)$log['id'] ?>"></td>
                                        <td><div class="action-name"><?= htmlspecialchars(wooLogsActionLabel((string)$log['action'])) ?></div><div class="action-code">#<?= (int)$log['id'] ?> · <?= htmlspecialchars((string)$log['action']) ?></div></td>
                                        <?php if ($hasItemId): ?><td><?= !empty($log['item_id']) ? '#' . (int)$log['item_id'] : '<span class="muted-text">—</span>' ?></td><?php endif; ?>
                                        <td><span class="status-badge <?= $success ? 'badge-success' : 'badge-failed' ?>"><i class="bi <?= $success ? 'bi-check-circle' : 'bi-x-octagon' ?>"></i><?= $success ? 'Success' : 'Failed' ?></span></td>
                                        <?php if ($hasHttpCode): ?><td><?= $httpCode > 0 ? '<span class="http-badge ' . $httpClass . '">' . $httpCode . '</span>' : '<span class="muted-text">—</span>' ?></td><?php endif; ?>
                                        <td><div class="summary-text" title="<?= htmlspecialchars((string)($log['response'] ?? '')) ?>"><?= htmlspecialchars($responseSummary !== '' ? $responseSummary : 'No response data') ?></div></td>
                                        <td><div><?= htmlspecialchars((string)$log['created_at']) ?></div><div class="muted-text">Server time</div></td>
                                        <td><button type="button" class="icon-btn view-log-btn" data-log-id="<?= (int)$log['id'] ?>" title="View details"><i class="bi bi-eye"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="pagination-wrap"><nav><ul class="pagination pagination-sm mb-0"><li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(wooLogsQueryUrl(['page' => max(1, $page - 1)])) ?>">Previous</a></li><?php $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2); for ($p = $startPage; $p <= $endPage; $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(wooLogsQueryUrl(['page' => $p])) ?>"><?= $p ?></a></li><?php endfor; ?><li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(wooLogsQueryUrl(['page' => min($totalPages, $page + 1)])) ?>">Next</a></li></ul></nav></div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<div class="modal fade" id="logDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-journal-code me-2"></i>WooCommerce Log Detail</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="detail-grid">
                    <div class="detail-chip"><div class="detail-label">Log ID</div><div class="detail-value" id="detailId">—</div></div>
                    <div class="detail-chip"><div class="detail-label">Action</div><div class="detail-value" id="detailAction">—</div></div>
                    <div class="detail-chip"><div class="detail-label">Status / HTTP</div><div class="detail-value" id="detailStatus">—</div></div>
                    <div class="detail-chip"><div class="detail-label">Created At</div><div class="detail-value" id="detailCreated">—</div></div>
                </div>
                <div class="code-title">Request</div><pre class="code-box" id="detailRequest">Loading...</pre>
                <div class="code-title">Response</div><pre class="code-box" id="detailResponse">Loading...</pre>
            </div>
            <div class="modal-footer"><button type="button" class="btn-soft" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<div class="toast-box" id="toastBox"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    var csrfToken = <?= json_encode($csrfToken) ?>;
    var toastBox = document.getElementById('toastBox');
    var logCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.log-checkbox'));
    var selectAll = document.getElementById('selectAllLogs');
    var deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    var clearAllBtn = document.getElementById('clearAllBtn');
    var detailModalElement = document.getElementById('logDetailModal');
    var detailModal = detailModalElement ? new bootstrap.Modal(detailModalElement) : null;

    function showToast(message, success) {
        toastBox.textContent = message || (success ? 'Completed.' : 'Request failed.');
        toastBox.className = 'toast-box show ' + (success ? 'success' : 'error');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(function () {
            toastBox.className = 'toast-box';
        }, 4200);
    }

    async function postAction(action, data) {
        var formData = new FormData();
        formData.append('ajax', action);
        formData.append('csrf_token', csrfToken);
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            formData.append(key, typeof value === 'string' ? value : JSON.stringify(value));
        });

        var response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {'Accept': 'application/json'}
        });
        var result;
        try {
            result = await response.json();
        } catch (error) {
            result = {success:false,message:'Server ne valid JSON response nahi diya.'};
        }
        if (result.redirect) {
            window.location.href = result.redirect;
        }
        return result;
    }

    function selectedIds() {
        return logCheckboxes.filter(function (checkbox) {
            return checkbox.checked;
        }).map(function (checkbox) {
            return parseInt(checkbox.value, 10);
        }).filter(function (id) {
            return id > 0;
        });
    }

    function updateSelection() {
        var count = selectedIds().length;
        document.getElementById('selectionCount').textContent = count + ' logs selected';
        if (deleteSelectedBtn) deleteSelectedBtn.disabled = count === 0;
        if (selectAll) selectAll.checked = logCheckboxes.length > 0 && count === logCheckboxes.length;
    }

    function pretty(value) {
        if (value === null || typeof value === 'undefined' || value === '') return 'No data';
        if (typeof value === 'string') {
            try {
                return JSON.stringify(JSON.parse(value), null, 2);
            } catch (error) {
                return value;
            }
        }
        return JSON.stringify(value, null, 2);
    }

    document.querySelectorAll('.view-log-btn').forEach(function (button) {
        button.addEventListener('click', async function () {
            document.getElementById('detailId').textContent = '#' + button.dataset.logId;
            document.getElementById('detailAction').textContent = 'Loading...';
            document.getElementById('detailStatus').textContent = 'Loading...';
            document.getElementById('detailCreated').textContent = 'Loading...';
            document.getElementById('detailRequest').textContent = 'Loading...';
            document.getElementById('detailResponse').textContent = 'Loading...';
            detailModal.show();

            var result = await postAction('log_detail', {log_id:button.dataset.logId});
            if (!result.success) {
                document.getElementById('detailResponse').textContent = result.message || 'Log load failed.';
                showToast(result.message, false);
                return;
            }

            var log = result.log;
            document.getElementById('detailId').textContent = '#' + log.id;
            document.getElementById('detailAction').textContent = log.action || 'Unknown';
            document.getElementById('detailStatus').textContent = (Number(log.status) === 1 ? 'Success' : 'Failed') + (log.http_code ? ' / HTTP ' + log.http_code : '');
            document.getElementById('detailCreated').textContent = log.created_at || '—';
            document.getElementById('detailRequest').textContent = pretty(log.request);
            document.getElementById('detailResponse').textContent = pretty(log.response);
        });
    });

    logCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelection);
    });
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            logCheckboxes.forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            updateSelection();
        });
    }

    if (deleteSelectedBtn) {
        deleteSelectedBtn.addEventListener('click', async function () {
            var ids = selectedIds();
            if (!ids.length) return;
            if (!window.confirm(ids.length + ' selected log(s) permanently delete honge. Continue?')) return;
            deleteSelectedBtn.disabled = true;
            var result = await postAction('delete_logs', {ids:ids});
            showToast(result.message, !!result.success);
            if (result.success) {
                window.setTimeout(function () { window.location.reload(); }, 800);
            } else {
                deleteSelectedBtn.disabled = false;
            }
        });
    }

    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', async function () {
            if (!window.confirm('Is client ke tamam WooCommerce sync logs permanently delete honge. Continue?')) return;
            clearAllBtn.disabled = true;
            var result = await postAction('clear_logs', {});
            showToast(result.message, !!result.success);
            if (result.success) {
                window.setTimeout(function () { window.location.reload(); }, 800);
            } else {
                clearAllBtn.disabled = false;
            }
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