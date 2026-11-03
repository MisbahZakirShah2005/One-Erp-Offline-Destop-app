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

$logged_user_id   = (int)($_SESSION['user_id'] ?? 0);
$logged_role_id   = (int)($_SESSION['role_id'] ?? 0);
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);

if ($logged_client_id <= 0) {
    http_response_code(403);
    exit('Invalid client session.');
}

/*
|--------------------------------------------------------------------------
| SIDEBAR PERMISSION ENGINE
|--------------------------------------------------------------------------
| Sidebar ko output buffer mein load kiya gaya hai taake usi existing
| can_show() permission logic ko direct URL guard ke liye reuse kiya ja sake.
| Sidebar UI baad mein exactly ek dafa render hogi.
|--------------------------------------------------------------------------
*/
ob_start();
include(__DIR__ . '/partials/sidebar.php');
$sidebarHtml = ob_get_clean();

/* ================= DIRECT URL SECURITY ================= */
$hasWooPermission = function_exists('can_show')
    && can_show('woocommerce_products');

$wooConnectionActive = isWooCommerceActive(
    $conn,
    $logged_client_id
);

if (!$hasWooPermission || !$wooConnectionActive) {
    $_SESSION['integration_error'] = !$hasWooPermission
        ? 'Aapke active plan ya staff permission mein WooCommerce Product Sync enabled nahi hai.'
        : 'WooCommerce integration Active aur Connected nahi hai.';

    header('Location: /public/app/views/integrations.php');
    exit;
}

$wooConnection = getWooCommerceConnection(
    $conn,
    $logged_client_id,
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
if (empty($_SESSION['woo_products_csrf'])) {
    $_SESSION['woo_products_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['woo_products_csrf'];

/* ================= PAGE HELPERS ================= */
if (!function_exists('wooProductsJsonResponse')) {
    function wooProductsJsonResponse(array $payload, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

if (!function_exists('wooProductsBindParams')) {
    function wooProductsBindParams(
        mysqli_stmt $stmt,
        string $types,
        array &$values
    ): bool {
        if ($types === '' || empty($values)) {
            return true;
        }

        $params = [];
        $params[] = &$types;

        foreach ($values as $key => &$value) {
            $params[] = &$value;
        }
        unset($value);

        return (bool)call_user_func_array(
            [$stmt, 'bind_param'],
            $params
        );
    }
}

if (!function_exists('wooProductsSafeImageUrl')) {
    function wooProductsSafeImageUrl(string $image): string
    {
        return wooBuildPublicImageUrl(
            $image,
            ['image_upload_path' => 'uploads/items']
        );
    }
}

if (!function_exists('wooProductsBuildQueryUrl')) {
    function wooProductsBuildQueryUrl(array $changes = []): string
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

/* ================= AJAX BACKEND ================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax'])
) {
    $requestToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $requestToken === ''
        || !hash_equals($csrfToken, $requestToken)
    ) {
        wooProductsJsonResponse([
            'success' => false,
            'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
        ], 419);
    }

    if (!can_show('woocommerce_products')) {
        wooProductsJsonResponse([
            'success' => false,
            'message' => 'WooCommerce Product Sync permission available nahi hai.'
        ], 403);
    }

    if (!isWooCommerceActive($conn, $logged_client_id)) {
        wooProductsJsonResponse([
            'success' => false,
            'message' => 'WooCommerce integration Active aur Connected nahi hai.',
            'redirect' => '/onerp/app/public/app/views/integrations.php'
        ], 403);
    }

    $ajaxAction = trim((string)$_POST['ajax']);

    if ($ajaxAction === 'push_product') {
        $itemId = (int)($_POST['item_id'] ?? 0);

        if ($itemId <= 0) {
            wooProductsJsonResponse([
                'success' => false,
                'message' => 'Invalid item ID.'
            ], 422);
        }

        try {
            $result = pushWooCommerceProduct(
                $conn,
                $logged_client_id,
                $itemId,
                [
                    'integration_id' => $integrationId,
                    'require_active' => true,
                    'image_upload_path' => 'uploads/items'
                ]
            );

            wooProductsJsonResponse($result, !empty($result['success']) ? 200 : 422);
        } catch (Throwable $e) {
            writeWooCommerceLog(
                $conn,
                $logged_client_id,
                'product_sync_exception',
                ['item_id' => $itemId],
                ['message' => $e->getMessage()],
                false,
                $integrationId,
                $itemId,
                0
            );

            wooProductsJsonResponse([
                'success' => false,
                'message' => 'Product sync exception: ' . $e->getMessage()
            ], 500);
        }
    }

    if ($ajaxAction === 'test_connection') {
        try {
            $result = testWooCommerceConnection(
                $conn,
                $logged_client_id,
                $integrationId
            );

            wooProductsJsonResponse(
                $result,
                !empty($result['success']) ? 200 : 422
            );
        } catch (Throwable $e) {
            wooProductsJsonResponse([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    wooProductsJsonResponse([
        'success' => false,
        'message' => 'Unsupported AJAX action.'
    ], 400);
}

/* ================= DATABASE REQUIREMENTS ================= */
$mappingTableReady = wooTableExists(
    $conn,
    'woocommerce_product_mappings'
);

$pageError = '';
if (!$mappingTableReady) {
    $pageError = 'woocommerce_product_mappings table missing hai. Pehle WooCommerce database migration run karein.';
}

$productTypeColumn = 'woo_product_type';
if (
    $mappingTableReady
    && !wooColumnExists(
        $conn,
        'woocommerce_product_mappings',
        'woo_product_type'
    )
) {
    $productTypeColumn = 'product_type';
}

/* ================= FILTERS ================= */
$search = trim((string)($_GET['search'] ?? ''));
$categoryFilter = trim((string)($_GET['category'] ?? ''));
$itemStatusFilter = trim((string)($_GET['item_status'] ?? ''));
$syncStatusFilter = trim((string)($_GET['sync_status'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'latest'));

$allowedItemStatuses = ['', 'active', 'inactive'];
if (!in_array($itemStatusFilter, $allowedItemStatuses, true)) {
    $itemStatusFilter = '';
}

$allowedSyncStatuses = ['', 'unsynced', 'pending', 'synced', 'failed'];
if (!in_array($syncStatusFilter, $allowedSyncStatuses, true)) {
    $syncStatusFilter = '';
}

$allowedSorts = [
    'latest',
    'name_asc',
    'name_desc',
    'price_low',
    'price_high',
    'stock_low',
    'stock_high'
];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

$perPage = (int)($_GET['per_page'] ?? 25);
$allowedPerPage = [10, 25, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

$currentPageNumber = max(1, (int)($_GET['page'] ?? 1));

/* ================= CLIENT CURRENCY ================= */
$currency = 'PKR';
try {
    $currencyStmt = $conn->prepare("
        SELECT currency
        FROM client_settings
        WHERE client_id = ?
        LIMIT 1
    ");

    if ($currencyStmt) {
        $currencyStmt->bind_param('i', $logged_client_id);
        $currencyStmt->execute();
        $currencyRow = $currencyStmt->get_result()->fetch_assoc();
        $currencyStmt->close();

        if (!empty($currencyRow['currency'])) {
            $currency = strtoupper(trim((string)$currencyRow['currency']));
        }
    }
} catch (Throwable $ignore) {
    $currency = 'PKR';
}

/* ================= CATEGORY OPTIONS ================= */
$categories = [];
try {
    $categoryStmt = $conn->prepare("
        SELECT DISTINCT category
        FROM items
        WHERE client_id = ?
          AND deleted_at IS NULL
          AND category IS NOT NULL
          AND TRIM(category) <> ''
        ORDER BY category ASC
    ");

    if ($categoryStmt) {
        $categoryStmt->bind_param('i', $logged_client_id);
        $categoryStmt->execute();
        $categoryResult = $categoryStmt->get_result();

        while ($categoryResult && ($categoryRow = $categoryResult->fetch_assoc())) {
            $categories[] = (string)$categoryRow['category'];
        }

        $categoryStmt->close();
    }
} catch (Throwable $ignore) {
    $categories = [];
}

/* ================= STATS ================= */
$stats = [
    'total' => 0,
    'synced' => 0,
    'failed' => 0,
    'unsynced' => 0
];

if ($mappingTableReady) {
    try {
        $statsSql = "
            SELECT
                COUNT(i.id) AS total,
                SUM(CASE WHEN m.sync_status = 'synced' THEN 1 ELSE 0 END) AS synced,
                SUM(CASE WHEN m.sync_status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN m.id IS NULL THEN 1 ELSE 0 END) AS unsynced
            FROM items i
            LEFT JOIN woocommerce_product_mappings m
              ON m.client_id = i.client_id
             AND m.item_id = i.id
             AND m.integration_id = ?
            WHERE i.client_id = ?
              AND i.deleted_at IS NULL
        ";

        $statsStmt = $conn->prepare($statsSql);
        if ($statsStmt) {
            $statsStmt->bind_param(
                'ii',
                $integrationId,
                $logged_client_id
            );
            $statsStmt->execute();
            $statsRow = $statsStmt->get_result()->fetch_assoc();
            $statsStmt->close();

            if ($statsRow) {
                $stats = [
                    'total' => (int)($statsRow['total'] ?? 0),
                    'synced' => (int)($statsRow['synced'] ?? 0),
                    'failed' => (int)($statsRow['failed'] ?? 0),
                    'unsynced' => (int)($statsRow['unsynced'] ?? 0)
                ];
            }
        }
    } catch (Throwable $e) {
        $pageError = $pageError !== ''
            ? $pageError
            : 'Stats load nahi ho sake: ' . $e->getMessage();
    }
}

/* ================= PRODUCT LIST QUERY ================= */
$products = [];
$totalRows = 0;
$totalPages = 1;

if ($mappingTableReady && $pageError === '') {
    try {
        $where = [
            'i.client_id = ?',
            'i.deleted_at IS NULL'
        ];

        $types = 'ii';
        $params = [
            $integrationId,
            $logged_client_id
        ];

        if ($search !== '') {
            $where[] = '(i.name LIKE ? OR i.sku LIKE ? OR i.description LIKE ?)';
            $like = '%' . $search . '%';
            $types .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($categoryFilter !== '') {
            $where[] = 'i.category = ?';
            $types .= 's';
            $params[] = $categoryFilter;
        }

        if ($itemStatusFilter !== '') {
            $where[] = 'i.status = ?';
            $types .= 's';
            $params[] = $itemStatusFilter;
        }

        if ($syncStatusFilter === 'unsynced') {
            $where[] = 'm.id IS NULL';
        } elseif ($syncStatusFilter !== '') {
            $where[] = 'm.sync_status = ?';
            $types .= 's';
            $params[] = $syncStatusFilter;
        }

        $joinSql = "
            FROM items i
            LEFT JOIN woocommerce_product_mappings m
              ON m.client_id = i.client_id
             AND m.item_id = i.id
             AND m.integration_id = ?
        ";

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $countSql = 'SELECT COUNT(i.id) AS total_rows '
            . $joinSql
            . $whereSql;

        $countStmt = $conn->prepare($countSql);
        if (!$countStmt) {
            throw new RuntimeException('Product count query prepare nahi hui.');
        }

        wooProductsBindParams($countStmt, $types, $params);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();

        $totalRows = (int)($countRow['total_rows'] ?? 0);
        $totalPages = max(1, (int)ceil($totalRows / $perPage));

        if ($currentPageNumber > $totalPages) {
            $currentPageNumber = $totalPages;
        }

        $offset = ($currentPageNumber - 1) * $perPage;

        $orderBy = 'i.id DESC';
        switch ($sort) {
            case 'name_asc':
                $orderBy = 'i.name ASC, i.id DESC';
                break;
            case 'name_desc':
                $orderBy = 'i.name DESC, i.id DESC';
                break;
            case 'price_low':
                $orderBy = 'i.price ASC, i.id DESC';
                break;
            case 'price_high':
                $orderBy = 'i.price DESC, i.id DESC';
                break;
            case 'stock_low':
                $orderBy = 'i.stock_qty ASC, i.id DESC';
                break;
            case 'stock_high':
                $orderBy = 'i.stock_qty DESC, i.id DESC';
                break;
        }

        $selectSql = "
            SELECT
                i.id,
                i.name,
                i.description,
                i.sku,
                i.type,
                i.has_variants,
                i.price,
                i.stock_qty,
                i.track_inventory,
                i.category,
                i.status AS item_status,
                i.image,
                m.id AS mapping_id,
                m.woo_product_id,
                m.`{$productTypeColumn}` AS woo_product_type,
                m.sync_status,
                m.last_error,
                m.last_synced_at
            {$joinSql}
            {$whereSql}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ";

        $selectTypes = $types . 'ii';
        $selectParams = $params;
        $selectParams[] = $perPage;
        $selectParams[] = $offset;

        $selectStmt = $conn->prepare($selectSql);
        if (!$selectStmt) {
            throw new RuntimeException('Product list query prepare nahi hui.');
        }

        wooProductsBindParams(
            $selectStmt,
            $selectTypes,
            $selectParams
        );
        $selectStmt->execute();
        $selectResult = $selectStmt->get_result();

        while ($selectResult && ($productRow = $selectResult->fetch_assoc())) {
            $products[] = $productRow;
        }

        $selectStmt->close();
    } catch (Throwable $e) {
        $pageError = 'Products load nahi ho sake: ' . $e->getMessage();
    }
}

$displayFrom = $totalRows > 0
    ? (($currentPageNumber - 1) * $perPage) + 1
    : 0;
$displayTo = min($totalRows, $currentPageNumber * $perPage);

$wooAdminProductsUrl = $storeUrl
    . '/wp-admin/edit.php?post_type=product';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WooCommerce Product Sync</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script>
    (function () {
        let selectedTheme = 'light';

        try {
            const savedTheme =
                localStorage.getItem('oneerp_pos_theme') ||
                localStorage.getItem('oneerp_theme') ||
                localStorage.getItem('theme');

            if (savedTheme === 'light' || savedTheme === 'dark') {
                selectedTheme = savedTheme;
            }
        } catch (error) {
            selectedTheme = 'light';
        }

        document.documentElement.setAttribute(
            'data-pos-theme',
            selectedTheme
        );

        document.addEventListener('DOMContentLoaded', function () {
            document.body.setAttribute(
                'data-pos-theme',
                selectedTheme
            );
        });
    })();
    </script>

    <?php include(__DIR__ . '/partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        *{box-sizing:border-box;}

        :root{
            --woo-bg:#070b14;
            --woo-panel:rgba(15,23,42,.68);
            --woo-panel-strong:rgba(15,23,42,.88);
            --woo-border:rgba(148,163,184,.16);
            --woo-text:#f8fafc;
            --woo-muted:#94a3b8;
            --woo-purple:#7c3aed;
            --woo-purple-2:#6d4cff;
            --woo-green:#22c55e;
            --woo-yellow:#f59e0b;
            --woo-red:#ef4444;
            --woo-blue:#3b82f6;
            --current-sidebar-width:270px;
        }

        html,body{min-height:100%;}

        body{
            margin:0 !important;
            overflow-x:hidden;
            color:var(--woo-text) !important;
            font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
            background:
                radial-gradient(circle at 10% 0%,rgba(124,58,237,.26),transparent 30%),
                radial-gradient(circle at 88% 4%,rgba(59,130,246,.14),transparent 30%),
                linear-gradient(135deg,#050914 0%,#0b1024 45%,#160f2d 72%,#090d18 100%) !important;
        }

        body.sidebar-collapsed,
        body.dashboard-sidebar-collapsed{
            --current-sidebar-width:78px;
        }

        .container-scroll{
            display:flex;
            min-height:100vh;
            width:100%;
        }

        .sidebar{
            width:var(--current-sidebar-width);
            min-width:var(--current-sidebar-width);
            max-width:var(--current-sidebar-width);
            position:fixed;
            left:0;
            top:0;
            bottom:0;
            z-index:1040;
            transition:.25s ease;
        }

        .main-panel{
            margin-left:var(--current-sidebar-width);
            width:calc(100% - var(--current-sidebar-width));
            min-width:0;
            min-height:100vh;
            display:flex;
            flex-direction:column;
            transition:.25s ease;
        }

        .topbar{
            height:70px;
            min-height:70px;
            position:sticky;
            top:0;
            z-index:1030;
            background:#070b14;
            border-bottom:1px solid var(--woo-border);
            box-shadow:0 12px 34px rgba(0,0,0,.28);
        }

        .content-wrapper{
            position:relative;
            flex:1;
            padding:24px 26px 38px;
            background:
                radial-gradient(circle at 14% 6%,rgba(124,58,237,.25),transparent 28%),
                radial-gradient(circle at 84% 2%,rgba(59,130,246,.11),transparent 30%),
                linear-gradient(135deg,#050914 0%,#0b1024 42%,#160f2d 72%,#090d18 100%);
        }

        .content-wrapper::before{
            content:"";
            position:absolute;
            inset:0;
            pointer-events:none;
            background:
                linear-gradient(90deg,rgba(255,255,255,.028) 1px,transparent 1px),
                linear-gradient(180deg,rgba(255,255,255,.025) 1px,transparent 1px);
            background-size:44px 44px;
            opacity:.35;
        }

        .content-wrapper>*{position:relative;z-index:1;}

        .page-header{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:18px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:8px;
            color:#c4b5fd;
            font-size:12px;
            font-weight:900;
            letter-spacing:.08em;
            text-transform:uppercase;
        }

        .page-title{
            margin:0;
            color:#fff;
            font-size:30px;
            font-weight:950;
            letter-spacing:-.035em;
        }

        .page-subtitle{
            margin:7px 0 0;
            color:var(--woo-muted);
            font-size:14px;
            font-weight:600;
        }

        .header-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn-woo,
        .btn-soft{
            min-height:44px;
            padding:0 16px;
            border-radius:12px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            text-decoration:none;
            font-size:13px;
            font-weight:900;
            transition:.2s ease;
        }

        .btn-woo{
            border:none;
            color:#fff;
            background:linear-gradient(135deg,var(--woo-purple-2),var(--woo-purple));
            box-shadow:0 14px 30px rgba(109,76,255,.28);
        }

        .btn-soft{
            border:1px solid var(--woo-border);
            color:#e5e7eb;
            background:rgba(15,23,42,.72);
            box-shadow:0 10px 24px rgba(0,0,0,.18);
        }

        .btn-woo:hover,.btn-soft:hover{
            transform:translateY(-1px);
            color:#fff;
        }

        .connection-strip{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            flex-wrap:wrap;
            margin-bottom:18px;
            padding:15px 17px;
            border:1px solid rgba(34,197,94,.22);
            border-radius:16px;
            background:
                radial-gradient(circle at 0% 0%,rgba(34,197,94,.12),transparent 34%),
                linear-gradient(135deg,rgba(15,23,42,.80),rgba(17,24,39,.60));
            box-shadow:0 16px 38px rgba(0,0,0,.24);
            backdrop-filter:blur(16px);
        }

        .connection-main{
            display:flex;
            align-items:center;
            gap:12px;
            min-width:0;
        }

        .connection-icon{
            width:46px;
            height:46px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:rgba(34,197,94,.14);
            border:1px solid rgba(34,197,94,.24);
            color:#4ade80;
            font-size:20px;
            flex-shrink:0;
        }

        .connection-title{font-size:14px;font-weight:900;color:#fff;}
        .connection-url{
            max-width:680px;
            margin-top:3px;
            color:#94a3b8;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }

        .connected-badge{
            padding:7px 12px;
            border-radius:999px;
            background:rgba(34,197,94,.14);
            border:1px solid rgba(34,197,94,.22);
            color:#4ade80;
            font-size:12px;
            font-weight:900;
        }

        .stats-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .stat-card{
            min-height:126px;
            padding:18px;
            border-radius:18px;
            position:relative;
            overflow:hidden;
            border:1px solid rgba(255,255,255,.10);
            box-shadow:0 18px 42px rgba(0,0,0,.28);
            color:#fff;
            transition:.22s ease;
        }

        .stat-card:hover{transform:translateY(-3px);}
        .stat-total{background:linear-gradient(135deg,#2563eb,#6d4cff);}
        .stat-synced{background:linear-gradient(135deg,#047857,#16a34a);}
        .stat-failed{background:linear-gradient(135deg,#be123c,#ef4444);}
        .stat-unsynced{background:linear-gradient(135deg,#d97706,#f59e0b);}

        .stat-card::after{
            content:"";
            position:absolute;
            right:14px;
            top:14px;
            width:68px;
            height:68px;
            border-radius:20px;
            background:rgba(255,255,255,.10);
        }

        .stat-icon{font-size:24px;position:relative;z-index:1;}
        .stat-label{margin-top:13px;font-size:13px;font-weight:800;opacity:.86;}
        .stat-number{margin-top:4px;font-size:30px;font-weight:950;line-height:1;}

        .glass-card{
            border:1px solid var(--woo-border);
            border-radius:18px;
            background:
                radial-gradient(circle at 18% 0%,rgba(124,58,237,.12),transparent 34%),
                linear-gradient(135deg,rgba(15,23,42,.72),rgba(17,24,39,.54));
            box-shadow:0 18px 48px rgba(0,0,0,.26);
            backdrop-filter:blur(18px) saturate(130%);
            overflow:hidden;
        }

        .filter-card{
            padding:14px;
            margin-bottom:16px;
        }

        .filter-grid{
            display:grid;
            grid-template-columns:minmax(240px,1.8fr) repeat(4,minmax(145px,.8fr)) auto;
            gap:10px;
            align-items:center;
        }

        .search-wrap{position:relative;}
        .search-wrap i{
            position:absolute;
            left:13px;
            top:50%;
            transform:translateY(-50%);
            color:#94a3b8;
        }

        .filter-control{
            width:100%;
            height:44px;
            border-radius:11px;
            border:1px solid rgba(148,163,184,.18);
            background:rgba(15,23,42,.78);
            color:#e5e7eb;
            padding:0 12px;
            font-size:13px;
            font-weight:750;
            outline:none;
        }

        .search-wrap .filter-control{padding-left:39px;}
        .filter-control:focus{
            border-color:rgba(124,58,237,.60);
            box-shadow:0 0 0 3px rgba(124,58,237,.14);
        }
        .filter-control option{background:#0f172a;color:#e5e7eb;}

        .toolbar{
            min-height:62px;
            padding:11px 14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            border-bottom:1px solid var(--woo-border);
        }

        .toolbar-left,.toolbar-right{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }

        .selection-count{
            color:#cbd5e1;
            font-size:13px;
            font-weight:800;
        }

        .result-count{
            color:#94a3b8;
            font-size:12px;
            font-weight:750;
        }

        .table-responsive{overflow:auto;}
        .products-table{
            width:100%;
            min-width:1180px;
            border-collapse:separate;
            border-spacing:0;
        }

        .products-table thead th{
            padding:14px 12px;
            background:rgba(7,11,20,.72);
            color:#cbd5e1;
            border-bottom:1px solid var(--woo-border);
            font-size:12px;
            font-weight:950;
            text-transform:uppercase;
            letter-spacing:.045em;
            white-space:nowrap;
        }

        .products-table tbody td{
            padding:13px 12px;
            color:#e5e7eb;
            background:rgba(15,23,42,.24);
            border-bottom:1px solid rgba(148,163,184,.09);
            font-size:13px;
            font-weight:650;
            vertical-align:middle;
        }

        .products-table tbody tr:hover td{
            background:rgba(124,58,237,.11);
        }

        .product-cell{
            display:flex;
            align-items:center;
            gap:11px;
            min-width:260px;
        }

        .product-thumb{
            width:50px;
            height:50px;
            border-radius:13px;
            object-fit:cover;
            border:1px solid rgba(148,163,184,.18);
            background:#111827;
            flex-shrink:0;
        }

        .product-fallback{
            width:50px;
            height:50px;
            border-radius:13px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:linear-gradient(135deg,#6d4cff,#7c3aed);
            color:#fff;
            font-size:17px;
            font-weight:950;
            flex-shrink:0;
        }

        .product-name{
            max-width:260px;
            color:#fff;
            font-size:13px;
            font-weight:900;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }

        .product-meta{
            margin-top:4px;
            color:#94a3b8;
            font-size:11px;
            font-weight:700;
        }

        .badge-pill{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:6px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:900;
            white-space:nowrap;
        }

        .badge-synced{background:rgba(34,197,94,.14);color:#4ade80;border:1px solid rgba(34,197,94,.22);}
        .badge-failed{background:rgba(239,68,68,.14);color:#f87171;border:1px solid rgba(239,68,68,.22);}
        .badge-pending{background:rgba(59,130,246,.14);color:#60a5fa;border:1px solid rgba(59,130,246,.22);}
        .badge-unsynced{background:rgba(245,158,11,.14);color:#fbbf24;border:1px solid rgba(245,158,11,.22);}
        .badge-active{background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.18);}
        .badge-inactive{background:rgba(148,163,184,.12);color:#cbd5e1;border:1px solid rgba(148,163,184,.18);}
        .badge-variable{background:rgba(124,58,237,.15);color:#c4b5fd;border:1px solid rgba(124,58,237,.22);}

        .stock-value.low{color:#f87171;font-weight:900;}
        .stock-value.ok{color:#e5e7eb;font-weight:850;}

        .sync-error{
            max-width:220px;
            color:#fca5a5;
            font-size:11px;
            font-weight:750;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            cursor:help;
        }

        .row-actions{display:flex;align-items:center;gap:7px;}
        .icon-btn{
            width:38px;
            height:38px;
            border-radius:10px;
            border:1px solid rgba(148,163,184,.18);
            display:inline-flex;
            align-items:center;
            justify-content:center;
            color:#e5e7eb;
            background:rgba(15,23,42,.74);
            transition:.18s ease;
            text-decoration:none;
        }
        .icon-btn:hover{color:#fff;background:rgba(124,58,237,.20);border-color:rgba(124,58,237,.35);}
        .icon-btn.primary{background:linear-gradient(135deg,#6d4cff,#7c3aed);border:none;color:#fff;}
        .icon-btn:disabled{opacity:.55;cursor:not-allowed;transform:none;}

        .pagination-wrap{
            min-height:66px;
            padding:12px 14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            border-top:1px solid var(--woo-border);
        }

        .pagination{margin:0;gap:5px;}
        .page-link{
            border-radius:9px !important;
            border:1px solid rgba(148,163,184,.17) !important;
            background:rgba(15,23,42,.70) !important;
            color:#cbd5e1 !important;
            font-size:12px;
            font-weight:850;
            box-shadow:none !important;
        }
        .page-item.active .page-link{
            background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
            color:#fff !important;
            border-color:transparent !important;
        }
        .page-item.disabled .page-link{opacity:.45;}

        .empty-state,.error-state{
            padding:54px 20px;
            text-align:center;
        }
        .empty-state i,.error-state i{
            width:70px;
            height:70px;
            margin:0 auto 15px;
            border-radius:20px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:28px;
        }
        .empty-state i{background:rgba(124,58,237,.14);color:#c4b5fd;border:1px solid rgba(124,58,237,.22);}
        .error-state i{background:rgba(239,68,68,.14);color:#f87171;border:1px solid rgba(239,68,68,.22);}
        .empty-state h3,.error-state h3{margin:0;color:#fff;font-size:20px;font-weight:950;}
        .empty-state p,.error-state p{margin:8px 0 0;color:#94a3b8;font-size:13px;font-weight:650;}

        .toast-container{z-index:999999;}
        .woo-toast{
            min-width:320px;
            border-radius:14px;
            border:1px solid rgba(148,163,184,.18);
            background:#0f172a;
            color:#e5e7eb;
            box-shadow:0 24px 60px rgba(0,0,0,.48);
        }

        .sync-overlay{
            position:fixed;
            inset:0;
            z-index:999998;
            display:none;
            align-items:center;
            justify-content:center;
            padding:20px;
            background:rgba(2,6,23,.74);
            backdrop-filter:blur(8px);
        }
        .sync-overlay.show{display:flex;}
        .sync-progress-card{
            width:min(460px,94vw);
            padding:24px;
            border-radius:20px;
            border:1px solid rgba(124,58,237,.35);
            background:
                radial-gradient(circle at 18% 0%,rgba(124,58,237,.22),transparent 38%),
                linear-gradient(135deg,#0f172a,#111827);
            box-shadow:0 32px 90px rgba(0,0,0,.62);
        }
        .sync-progress-title{color:#fff;font-size:18px;font-weight:950;}
        .sync-progress-text{margin-top:7px;color:#94a3b8;font-size:13px;font-weight:700;}
        .progress{height:10px;margin-top:18px;background:rgba(148,163,184,.16);border-radius:999px;overflow:hidden;}
        .progress-bar{background:linear-gradient(90deg,#6d4cff,#8b5cf6);}

        @media(max-width:1250px){
            .filter-grid{grid-template-columns:repeat(3,minmax(0,1fr));}
            .search-wrap{grid-column:span 2;}
            .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
        }

        @media(max-width:991px){
            .sidebar{width:270px;min-width:270px;max-width:270px;transform:translateX(-100%);}
            body.sidebar-open .sidebar{transform:translateX(0);}
            .main-panel{margin-left:0;width:100%;}
            .content-wrapper{padding:18px 14px 28px;}
            .filter-grid{grid-template-columns:1fr 1fr;}
            .search-wrap{grid-column:1/-1;}
        }

        @media(max-width:620px){
            .page-title{font-size:25px;}
            .header-actions{width:100%;}
            .header-actions .btn-woo,.header-actions .btn-soft{flex:1;}
            .stats-grid{grid-template-columns:1fr;}
            .filter-grid{grid-template-columns:1fr;}
            .search-wrap{grid-column:auto;}
            .toolbar,.pagination-wrap{align-items:stretch;}
            .toolbar-left,.toolbar-right{width:100%;justify-content:space-between;}
            .btn-woo,.btn-soft{width:100%;}
        }

        /* ================= LIGHT MODE ================= */
        html[data-pos-theme="light"] body{
            color:#0f172a !important;
            background:#f3f6fb !important;
        }
        html[data-pos-theme="light"] body .main-panel,
        html[data-pos-theme="light"] body .content-wrapper{
            background:
                radial-gradient(circle at 10% 0%,rgba(124,58,237,.07),transparent 30%),
                radial-gradient(circle at 88% 4%,rgba(59,130,246,.05),transparent 30%),
                linear-gradient(135deg,#f8fafc,#f1f5f9 56%,#faf7ff) !important;
        }
        html[data-pos-theme="light"] body .content-wrapper::before{
            background:
                linear-gradient(90deg,rgba(15,23,42,.022) 1px,transparent 1px),
                linear-gradient(180deg,rgba(15,23,42,.020) 1px,transparent 1px);
        }
        html[data-pos-theme="light"] body .page-title,
        html[data-pos-theme="light"] body .product-name,
        html[data-pos-theme="light"] body .empty-state h3,
        html[data-pos-theme="light"] body .error-state h3{
            color:#0f172a;
        }
        html[data-pos-theme="light"] body .page-subtitle,
        html[data-pos-theme="light"] body .product-meta,
        html[data-pos-theme="light"] body .result-count,
        html[data-pos-theme="light"] body .empty-state p,
        html[data-pos-theme="light"] body .error-state p{
            color:#64748b;
        }
        html[data-pos-theme="light"] body .glass-card,
        html[data-pos-theme="light"] body .filter-card,
        html[data-pos-theme="light"] body .connection-strip{
            background:#fff;
            background-image:none;
            border-color:#dce4ef;
            box-shadow:0 12px 32px rgba(15,23,42,.07);
            backdrop-filter:none;
        }
        html[data-pos-theme="light"] body .connection-title{color:#0f172a;}
        html[data-pos-theme="light"] body .connection-url{color:#64748b;}
        html[data-pos-theme="light"] body .filter-control{
            background:#fff;
            color:#0f172a;
            border-color:#cbd5e1;
        }
        html[data-pos-theme="light"] body .filter-control option{background:#fff;color:#0f172a;}
        html[data-pos-theme="light"] body .toolbar,
        html[data-pos-theme="light"] body .pagination-wrap{border-color:#e2e8f0;}
        html[data-pos-theme="light"] body .selection-count{color:#334155;}
        html[data-pos-theme="light"] body .products-table thead th{
            background:#f1f5f9;
            color:#334155;
            border-color:#dce4ef;
        }
        html[data-pos-theme="light"] body .products-table tbody td{
            background:#fff;
            color:#334155;
            border-color:#e8edf4;
        }
        html[data-pos-theme="light"] body .products-table tbody tr:nth-child(even) td{background:#fbfcff;}
        html[data-pos-theme="light"] body .products-table tbody tr:hover td{background:#f6f2ff;}
        html[data-pos-theme="light"] body .icon-btn,
        html[data-pos-theme="light"] body .btn-soft,
        html[data-pos-theme="light"] body .page-link{
            background:#fff !important;
            color:#334155 !important;
            border-color:#cbd5e1 !important;
            box-shadow:none;
        }
        html[data-pos-theme="light"] body .icon-btn.primary,
        html[data-pos-theme="light"] body .btn-woo,
        html[data-pos-theme="light"] body .page-item.active .page-link{
            background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
            color:#fff !important;
            border-color:transparent !important;
        }
        html[data-pos-theme="light"] body .product-thumb{background:#f8fafc;border-color:#e2e8f0;}
        html[data-pos-theme="light"] body .stock-value.ok{color:#334155;}
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
                    <div class="eyebrow">
                        <i class="bi bi-wordpress"></i>
                        WooCommerce Integration
                    </div>
                    <h1 class="page-title">Product Sync</h1>
                    <p class="page-subtitle">
                        ONEERP products ko WooCommerce par create, update aur monitor karein.
                    </p>
                </div>

                <div class="header-actions">
                    <button type="button" class="btn-woo" id="pullProductsBtn">
                        <i class="bi bi-cloud-download"></i>
                        Pull Products
                    </button>
                    <a href="woocommerce_orders.php" class="btn-soft">
                        <i class="bi bi-bag-check"></i>
                        Orders
                    </a>
                    <button type="button" class="btn-soft" id="testConnectionBtn">
                        <i class="bi bi-wifi"></i>
                        Test Connection
                    </button>
                    <a href="<?= htmlspecialchars($wooAdminProductsUrl) ?>"
                       target="_blank"
                       rel="noopener"
                       class="btn-woo">
                        <i class="bi bi-box-arrow-up-right"></i>
                        Open WooCommerce
                    </a>
                </div>
            </div>

            <section class="connection-strip">
                <div class="connection-main">
                    <div class="connection-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div style="min-width:0;">
                        <div class="connection-title">
                            <?= htmlspecialchars(
                                (string)($wooConnection['integration_name'] ?? 'WooCommerce')
                            ) ?>
                        </div>
                        <div class="connection-url" title="<?= htmlspecialchars($storeUrl) ?>">
                            <?= htmlspecialchars($storeUrl) ?>
                        </div>
                    </div>
                </div>
                <span class="connected-badge">
                    <i class="bi bi-shield-check"></i>
                    Active &amp; Connected
                </span>
            </section>

            <section class="stats-grid">
                <article class="stat-card stat-total">
                    <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
                    <div class="stat-label">Total ONEERP Products</div>
                    <div class="stat-number"><?= number_format($stats['total']) ?></div>
                </article>
                <article class="stat-card stat-synced">
                    <div class="stat-icon"><i class="bi bi-cloud-check"></i></div>
                    <div class="stat-label">Successfully Synced</div>
                    <div class="stat-number"><?= number_format($stats['synced']) ?></div>
                </article>
                <article class="stat-card stat-failed">
                    <div class="stat-icon"><i class="bi bi-exclamation-octagon"></i></div>
                    <div class="stat-label">Failed Products</div>
                    <div class="stat-number"><?= number_format($stats['failed']) ?></div>
                </article>
                <article class="stat-card stat-unsynced">
                    <div class="stat-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                    <div class="stat-label">Not Synced Yet</div>
                    <div class="stat-number"><?= number_format($stats['unsynced']) ?></div>
                </article>
            </section>

            <form method="get" class="glass-card filter-card" id="filterForm">
                <div class="filter-grid">
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text"
                               class="filter-control"
                               name="search"
                               placeholder="Search product name, SKU or description..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <select class="filter-control" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>"
                                <?= $categoryFilter === $category ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select class="filter-control" name="item_status">
                        <option value="">All Item Status</option>
                        <option value="active" <?= $itemStatusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $itemStatusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>

                    <select class="filter-control" name="sync_status">
                        <option value="">All Sync Status</option>
                        <option value="unsynced" <?= $syncStatusFilter === 'unsynced' ? 'selected' : '' ?>>Not Synced</option>
                        <option value="synced" <?= $syncStatusFilter === 'synced' ? 'selected' : '' ?>>Synced</option>
                        <option value="failed" <?= $syncStatusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="pending" <?= $syncStatusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>

                    <select class="filter-control" name="sort">
                        <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest First</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price Low-High</option>
                        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price High-Low</option>
                        <option value="stock_low" <?= $sort === 'stock_low' ? 'selected' : '' ?>>Stock Low-High</option>
                        <option value="stock_high" <?= $sort === 'stock_high' ? 'selected' : '' ?>>Stock High-Low</option>
                    </select>

                    <button type="submit" class="btn-woo">
                        <i class="bi bi-funnel"></i>
                        Apply
                    </button>
                </div>
                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
            </form>

            <section class="glass-card">
                <?php if ($pageError !== ''): ?>
                    <div class="error-state">
                        <i class="bi bi-database-exclamation"></i>
                        <h3>WooCommerce Sync Setup Incomplete</h3>
                        <p><?= htmlspecialchars($pageError) ?></p>
                    </div>
                <?php else: ?>
                    <div class="toolbar">
                        <div class="toolbar-left">
                            <button type="button" class="btn-woo" id="pushSelectedBtn" disabled>
                                <i class="bi bi-cloud-arrow-up"></i>
                                Push Selected
                            </button>
                            <span class="selection-count" id="selectionCount">0 products selected</span>
                        </div>

                        <div class="toolbar-right">
                            <span class="result-count">
                                Showing <?= number_format($displayFrom) ?>–<?= number_format($displayTo) ?>
                                of <?= number_format($totalRows) ?>
                            </span>
                            <select class="filter-control" id="perPageSelect" style="width:105px;">
                                <?php foreach ($allowedPerPage as $pageSize): ?>
                                    <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>>
                                        <?= $pageSize ?> / page
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if (empty($products)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inboxes"></i>
                            <h3>No Products Found</h3>
                            <p>Current filters ke mutabiq koi ONEERP product available nahi hai.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="products-table">
                                <thead>
                                    <tr>
                                        <th style="width:48px;">
                                            <input type="checkbox" class="form-check-input" id="selectAllProducts">
                                        </th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <th>ONEERP Status</th>
                                        <th>Woo ID</th>
                                        <th>Sync Status</th>
                                        <th>Last Sync / Error</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($products as $product): ?>
                                    <?php
                                        $itemId = (int)$product['id'];
                                        $syncStatus = strtolower(trim((string)($product['sync_status'] ?? '')));
                                        if ($syncStatus === '') {
                                            $syncStatus = 'unsynced';
                                        }

                                        $syncClass = 'badge-unsynced';
                                        $syncIcon = 'bi-cloud-arrow-up';
                                        $syncLabel = 'Not Synced';

                                        if ($syncStatus === 'synced') {
                                            $syncClass = 'badge-synced';
                                            $syncIcon = 'bi-check-circle';
                                            $syncLabel = 'Synced';
                                        } elseif ($syncStatus === 'failed') {
                                            $syncClass = 'badge-failed';
                                            $syncIcon = 'bi-exclamation-circle';
                                            $syncLabel = 'Failed';
                                        } elseif ($syncStatus === 'pending') {
                                            $syncClass = 'badge-pending';
                                            $syncIcon = 'bi-hourglass-split';
                                            $syncLabel = 'Pending';
                                        }

                                        $imageUrl = wooProductsSafeImageUrl(
                                            (string)($product['image'] ?? '')
                                        );

                                        $productType = trim((string)($product['woo_product_type'] ?? ''));
                                        if ($productType === '') {
                                            $productType = (int)($product['has_variants'] ?? 0) === 1
                                                ? 'variable'
                                                : 'simple';
                                        }

                                        $wooProductId = (int)($product['woo_product_id'] ?? 0);
                                        $remoteEditUrl = $wooProductId > 0
                                            ? $storeUrl . '/wp-admin/post.php?post=' . $wooProductId . '&action=edit'
                                            : '';

                                        $itemStatus = strtolower(trim((string)($product['item_status'] ?? 'inactive')));
                                        $stockQty = (float)($product['stock_qty'] ?? 0);
                                        $trackInventory = (int)($product['track_inventory'] ?? 1) === 1;
                                        $lastSync = trim((string)($product['last_synced_at'] ?? ''));
                                        $lastError = trim((string)($product['last_error'] ?? ''));
                                    ?>
                                    <tr id="productRow<?= $itemId ?>">
                                        <td>
                                            <input type="checkbox"
                                                   class="form-check-input product-checkbox"
                                                   value="<?= $itemId ?>"
                                                   aria-label="Select <?= htmlspecialchars((string)$product['name']) ?>">
                                        </td>

                                        <td>
                                            <div class="product-cell">
                                                <?php if ($imageUrl !== ''): ?>
                                                    <img class="product-thumb"
                                                         loading="lazy"
                                                         src="<?= htmlspecialchars($imageUrl) ?>"
                                                         alt="<?= htmlspecialchars((string)$product['name']) ?>"
                                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                                    <div class="product-fallback" style="display:none;">
                                                        <?= htmlspecialchars(strtoupper(substr((string)$product['name'], 0, 1))) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="product-fallback">
                                                        <?= htmlspecialchars(strtoupper(substr((string)$product['name'], 0, 1))) ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div style="min-width:0;">
                                                    <div class="product-name" title="<?= htmlspecialchars((string)$product['name']) ?>">
                                                        <?= htmlspecialchars((string)$product['name']) ?>
                                                    </div>
                                                    <div class="product-meta">
                                                        SKU: <?= htmlspecialchars((string)($product['sku'] ?: 'Auto-generated')) ?>
                                                        <?php if ($productType === 'variable'): ?>
                                                            · <span class="badge-pill badge-variable" style="padding:3px 7px;">
                                                                Variable
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td><?= htmlspecialchars((string)($product['category'] ?: '-')) ?></td>

                                        <td>
                                            <strong><?= htmlspecialchars($currency) ?> <?= number_format((float)$product['price'], 2) ?></strong>
                                        </td>

                                        <td>
                                            <?php if (!$trackInventory || (string)$product['type'] === 'service'): ?>
                                                <span class="badge-pill badge-pending">Not Tracked</span>
                                            <?php else: ?>
                                                <span class="stock-value <?= $stockQty <= 0 ? 'low' : 'ok' ?>">
                                                    <?= number_format($stockQty, 2) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="badge-pill <?= $itemStatus === 'active' ? 'badge-active' : 'badge-inactive' ?>">
                                                <?= ucfirst(htmlspecialchars($itemStatus)) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= $wooProductId > 0 ? '#' . number_format($wooProductId) : '-' ?>
                                        </td>

                                        <td>
                                            <span class="badge-pill <?= $syncClass ?>" id="syncBadge<?= $itemId ?>">
                                                <i class="bi <?= $syncIcon ?>"></i>
                                                <?= $syncLabel ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if ($syncStatus === 'failed' && $lastError !== ''): ?>
                                                <div class="sync-error" title="<?= htmlspecialchars($lastError) ?>">
                                                    <?= htmlspecialchars($lastError) ?>
                                                </div>
                                            <?php elseif ($lastSync !== ''): ?>
                                                <span title="<?= htmlspecialchars($lastSync) ?>">
                                                    <?= htmlspecialchars(date('d M Y, h:i A', strtotime($lastSync))) ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div class="row-actions">
                                                <button type="button"
                                                        class="icon-btn primary push-product-btn"
                                                        data-item-id="<?= $itemId ?>"
                                                        title="<?= $wooProductId > 0 ? 'Update on WooCommerce' : 'Push to WooCommerce' ?>">
                                                    <i class="bi <?= $wooProductId > 0 ? 'bi-arrow-repeat' : 'bi-cloud-arrow-up' ?>"></i>
                                                </button>

                                                <?php if ($remoteEditUrl !== ''): ?>
                                                    <a class="icon-btn"
                                                       href="<?= htmlspecialchars($remoteEditUrl) ?>"
                                                       target="_blank"
                                                       rel="noopener"
                                                       title="Open WooCommerce product">
                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination-wrap">
                            <div class="result-count">
                                Page <?= number_format($currentPageNumber) ?> of <?= number_format($totalPages) ?>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <nav aria-label="WooCommerce products pagination">
                                    <ul class="pagination pagination-sm">
                                        <li class="page-item <?= $currentPageNumber <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(wooProductsBuildQueryUrl(['page' => max(1, $currentPageNumber - 1)])) ?>">
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>

                                        <?php
                                            $startPage = max(1, $currentPageNumber - 2);
                                            $endPage = min($totalPages, $currentPageNumber + 2);
                                        ?>

                                        <?php if ($startPage > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?= htmlspecialchars(wooProductsBuildQueryUrl(['page' => 1])) ?>">1</a>
                                            </li>
                                            <?php if ($startPage > 2): ?>
                                                <li class="page-item disabled"><span class="page-link">…</span></li>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php for ($pageNo = $startPage; $pageNo <= $endPage; $pageNo++): ?>
                                            <li class="page-item <?= $pageNo === $currentPageNumber ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= htmlspecialchars(wooProductsBuildQueryUrl(['page' => $pageNo])) ?>">
                                                    <?= $pageNo ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($endPage < $totalPages): ?>
                                            <?php if ($endPage < $totalPages - 1): ?>
                                                <li class="page-item disabled"><span class="page-link">…</span></li>
                                            <?php endif; ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?= htmlspecialchars(wooProductsBuildQueryUrl(['page' => $totalPages])) ?>">
                                                    <?= $totalPages ?>
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <li class="page-item <?= $currentPageNumber >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(wooProductsBuildQueryUrl(['page' => min($totalPages, $currentPageNumber + 1)])) ?>">
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3">
    <div id="wooToast" class="toast woo-toast" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header" style="background:transparent;color:inherit;border-color:rgba(148,163,184,.14);">
            <i class="bi bi-wordpress me-2" id="toastIcon"></i>
            <strong class="me-auto">WooCommerce Sync</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body" id="toastMessage"></div>
    </div>
</div>

<div class="sync-overlay" id="syncOverlay">
    <div class="sync-progress-card">
        <div class="sync-progress-title" id="syncProgressTitle">Syncing products...</div>
        <div class="sync-progress-text" id="syncProgressText">Please wait.</div>
        <div class="progress">
            <div class="progress-bar" id="syncProgressBar" role="progressbar" style="width:0%"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    const csrfToken = <?= json_encode($csrfToken) ?>;
    const integrationId = <?= (int)$integrationId ?>;
    const endpoint = window.location.pathname + window.location.search;

    const selectAll = document.getElementById('selectAllProducts');
    const productCheckboxes = Array.from(
        document.querySelectorAll('.product-checkbox')
    );
    const pushSelectedBtn = document.getElementById('pushSelectedBtn');
    const selectionCount = document.getElementById('selectionCount');
    const overlay = document.getElementById('syncOverlay');
    const progressTitle = document.getElementById('syncProgressTitle');
    const progressText = document.getElementById('syncProgressText');
    const progressBar = document.getElementById('syncProgressBar');
    const testConnectionBtn = document.getElementById('testConnectionBtn');
    const pullProductsBtn = document.getElementById('pullProductsBtn');
    const perPageSelect = document.getElementById('perPageSelect');

    let isSyncRunning = false;

    function showToast(message, success) {
        const toastElement = document.getElementById('wooToast');
        const toastMessage = document.getElementById('toastMessage');
        const toastIcon = document.getElementById('toastIcon');

        if (!toastElement || !toastMessage || !toastIcon) {
            alert(message);
            return;
        }

        toastMessage.textContent = message;
        toastIcon.className = success
            ? 'bi bi-check-circle-fill me-2 text-success'
            : 'bi bi-exclamation-triangle-fill me-2 text-danger';

        bootstrap.Toast.getOrCreateInstance(toastElement, {
            delay: success ? 3200 : 6000
        }).show();
    }

    function setOverlay(show, title, text, percent) {
        if (!overlay) return;

        if (show) {
            overlay.classList.add('show');
        } else {
            overlay.classList.remove('show');
        }

        if (title !== undefined && progressTitle) {
            progressTitle.textContent = title;
        }
        if (text !== undefined && progressText) {
            progressText.textContent = text;
        }
        if (percent !== undefined && progressBar) {
            progressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        }
    }

    async function postAction(action, data) {
        const body = new URLSearchParams();
        body.set('ajax', action);
        body.set('csrf_token', csrfToken);

        Object.keys(data || {}).forEach(function (key) {
            body.set(key, data[key]);
        });

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body: body.toString()
        });

        let result;
        try {
            result = await response.json();
        } catch (error) {
            throw new Error('Server ne valid JSON response nahi diya.');
        }

        if (result.redirect) {
            window.location.href = result.redirect;
            return result;
        }

        if (!response.ok && !result.message) {
            throw new Error('WooCommerce request failed.');
        }

        return result;
    }

    function updateSelectionState() {
        const selected = productCheckboxes.filter(function (checkbox) {
            return checkbox.checked;
        });

        if (selectionCount) {
            selectionCount.textContent = selected.length
                + (selected.length === 1 ? ' product selected' : ' products selected');
        }

        if (pushSelectedBtn) {
            pushSelectedBtn.disabled = selected.length === 0 || isSyncRunning;
        }

        if (selectAll) {
            selectAll.checked = selected.length > 0
                && selected.length === productCheckboxes.length;
            selectAll.indeterminate = selected.length > 0
                && selected.length < productCheckboxes.length;
        }
    }

    async function syncSingleProduct(itemId, button, silent) {
        if (!itemId || isSyncRunning && !silent) return null;

        const oldHtml = button ? button.innerHTML : '';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        }

        try {
            const result = await postAction('push_product', {
                item_id: itemId
            });

            if (!silent) {
                showToast(
                    result.message || (result.success ? 'Product synced.' : 'Product sync failed.'),
                    !!result.success
                );
            }

            return result;
        } catch (error) {
            if (!silent) {
                showToast(error.message || 'Product sync failed.', false);
            }

            return {
                success: false,
                message: error.message || 'Product sync failed.'
            };
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = oldHtml;
            }
        }
    }

    document.querySelectorAll('.push-product-btn').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (isSyncRunning) return;

            const itemId = parseInt(button.dataset.itemId || '0', 10);
            const result = await syncSingleProduct(itemId, button, false);

            if (result && result.success) {
                window.setTimeout(function () {
                    window.location.reload();
                }, 650);
            }
        });
    });

    productCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelectionState);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            productCheckboxes.forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            updateSelectionState();
        });
    }

    if (pushSelectedBtn) {
        pushSelectedBtn.addEventListener('click', async function () {
            const selectedIds = productCheckboxes
                .filter(function (checkbox) {
                    return checkbox.checked;
                })
                .map(function (checkbox) {
                    return parseInt(checkbox.value, 10);
                })
                .filter(function (itemId) {
                    return itemId > 0;
                });

            if (selectedIds.length === 0 || isSyncRunning) return;

            if (!window.confirm(
                selectedIds.length + ' selected products WooCommerce par sync karne hain?'
            )) {
                return;
            }

            isSyncRunning = true;
            updateSelectionState();
            setOverlay(true, 'WooCommerce Product Sync', 'Starting sync...', 0);

            let successCount = 0;
            let failedCount = 0;

            for (let index = 0; index < selectedIds.length; index++) {
                const itemId = selectedIds[index];
                const percent = Math.round((index / selectedIds.length) * 100);

                setOverlay(
                    true,
                    'WooCommerce Product Sync',
                    'Product ' + (index + 1) + ' of ' + selectedIds.length + ' sync ho raha hai...',
                    percent
                );

                const result = await syncSingleProduct(itemId, null, true);
                if (result && result.success) {
                    successCount++;
                } else {
                    failedCount++;
                }
            }

            setOverlay(
                true,
                'Sync Completed',
                successCount + ' successful, ' + failedCount + ' failed.',
                100
            );

            isSyncRunning = false;

            window.setTimeout(function () {
                setOverlay(false);
                showToast(
                    successCount + ' products synced, ' + failedCount + ' failed.',
                    failedCount === 0
                );
                window.location.reload();
            }, 900);
        });
    }


    if (pullProductsBtn) {
        pullProductsBtn.addEventListener('click', async function () {
            if (isSyncRunning) return;

            if (!window.confirm(
                'WooCommerce products ONEERP mein pull/update karne hain? Same Woo Product ID ya SKU wali item update hogi.'
            )) {
                return;
            }

            isSyncRunning = true;
            pullProductsBtn.disabled = true;

            let page = 1;
            const perPage = 50;
            const maxPages = 200;
            let created = 0;
            let updated = 0;
            let failed = 0;

            setOverlay(true, 'Pulling WooCommerce Products', 'Remote page 1 load ho raha hai...', 2);

            try {
                while (page <= maxPages) {
                    const body = new URLSearchParams();
                    body.set('csrf_token', csrfToken);
                    body.set('integration_id', String(integrationId));
                    body.set('page', String(page));
                    body.set('per_page', String(perPage));

                    setOverlay(
                        true,
                        'Pulling WooCommerce Products',
                        'Remote page ' + page + ' process ho raha hai...',
                        Math.min(95, 5 + page)
                    );

                    const response = await fetch(
                        '/onerp/app/public/app/ajax/woocommerce_pull_products.php',
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                                'Accept': 'application/json'
                            },
                            body: body.toString()
                        }
                    );

                    let result;
                    try {
                        result = await response.json();
                    } catch (error) {
                        throw new Error('Server ne valid JSON response nahi diya.');
                    }

                    if (result.redirect) {
                        window.location.href = result.redirect;
                        return;
                    }

                    if (!result.success && !result.partial) {
                        throw new Error(result.message || 'Products pull failed.');
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
                    'Products Pull Completed',
                    created + ' created, ' + updated + ' updated, ' + failed + ' failed.',
                    100
                );

                window.setTimeout(function () {
                    window.location.reload();
                }, 900);
            } catch (error) {
                setOverlay(false);
                isSyncRunning = false;
                pullProductsBtn.disabled = false;
                showToast(error.message || 'Products pull failed.', false);
            }
        });
    }

    if (testConnectionBtn) {
        testConnectionBtn.addEventListener('click', async function () {
            const originalHtml = testConnectionBtn.innerHTML;
            testConnectionBtn.disabled = true;
            testConnectionBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

            try {
                const result = await postAction('test_connection', {});
                showToast(
                    result.message || 'Connection test completed.',
                    !!result.success
                );
            } catch (error) {
                showToast(error.message || 'Connection test failed.', false);
            } finally {
                testConnectionBtn.disabled = false;
                testConnectionBtn.innerHTML = originalHtml;
            }
        });
    }

    if (perPageSelect) {
        perPageSelect.addEventListener('change', function () {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', perPageSelect.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    }

    updateSelectionState();
})();
</script>
</body>
</html>
