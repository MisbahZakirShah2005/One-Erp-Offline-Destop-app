<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/woocommerce_helper.php'
);

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$loggedUserId = (int)($_SESSION['user_id'] ?? 0);
$loggedRoleId = (int)($_SESSION['role_id'] ?? 0);
$loggedClientId = (int)($_SESSION['client_id'] ?? 0);

if ($loggedClientId <= 0) {
    http_response_code(403);
    exit('Invalid client session.');
}

ob_start();
include(__DIR__ . '/partials/sidebar.php');
$sidebarHtml = ob_get_clean();

$hasPermission = function_exists('can_show') && can_show('woocommerce_orders');
$connectionActive = isWooCommerceActive($conn, $loggedClientId);

if (!$hasPermission || !$connectionActive) {
    $_SESSION['integration_error'] = !$hasPermission
        ? 'Aapke active plan ya staff permission mein WooCommerce Orders enabled nahi hain.'
        : 'WooCommerce integration Active aur Connected nahi hai.';
    header('Location: /public/app/views/integrations.php');
    exit;
}

$wooConnection = getWooCommerceConnection($conn, $loggedClientId, 0, true);
if (!$wooConnection) {
    $_SESSION['integration_error'] = 'WooCommerce connection record nahi mila.';
    header('Location: /public/app/views/integrations.php');
    exit;
}

$integrationId = (int)$wooConnection['id'];
$storeUrl = rtrim((string)$wooConnection['store_url'], '/');
$wooAdminOrdersUrl = $storeUrl . '/wp-admin/admin.php?page=wc-orders';

if (!wooTableExists($conn, 'woocommerce_orders') || !wooTableExists($conn, 'woocommerce_order_items')) {
    http_response_code(500);
    exit('WooCommerce orders migration queries pehle run karein.');
}

if (empty($_SESSION['woo_orders_csrf'])) {
    $_SESSION['woo_orders_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['woo_orders_csrf'];

function wooOrdersJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wooOrdersBind(mysqli_stmt $stmt, string $types, array &$values): bool
{
    if ($types === '' || empty($values)) {
        return true;
    }

    $params = [&$types];
    foreach ($values as &$value) {
        $params[] = &$value;
    }
    unset($value);

    return (bool)call_user_func_array([$stmt, 'bind_param'], $params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    if ($requestToken === '' || !hash_equals($csrfToken, $requestToken)) {
        wooOrdersJson([
            'success' => false,
            'message' => 'Security token invalid ya expire ho gaya. Page refresh karein.'
        ], 419);
    }

    if (!can_show('woocommerce_orders')) {
        wooOrdersJson([
            'success' => false,
            'message' => 'WooCommerce Orders permission available nahi hai.'
        ], 403);
    }

    $action = trim((string)$_POST['ajax']);

    if ($action === 'order_detail') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            wooOrdersJson(['success' => false, 'message' => 'Invalid order ID.'], 422);
        }

        $stmt = $conn->prepare("\n            SELECT *\n            FROM woocommerce_orders\n            WHERE id = ? AND client_id = ? AND integration_id = ?\n            LIMIT 1\n        ");
        $stmt->bind_param('iii', $orderId, $loggedClientId, $integrationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$order) {
            wooOrdersJson(['success' => false, 'message' => 'Order nahi mila.'], 404);
        }

        $items = [];
        $stmt = $conn->prepare("\n            SELECT *\n            FROM woocommerce_order_items\n            WHERE order_mapping_id = ? AND client_id = ? AND integration_id = ?\n            ORDER BY id ASC\n        ");
        $stmt->bind_param('iii', $orderId, $loggedClientId, $integrationId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $items[] = $row;
        }
        $stmt->close();

        wooOrdersJson([
            'success' => true,
            'order' => $order,
            'items' => $items
        ]);
    }

    wooOrdersJson(['success' => false, 'message' => 'Invalid AJAX action.'], 422);
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}

$where = [
    'o.client_id = ?',
    'o.integration_id = ?'
];
$params = [$loggedClientId, $integrationId];
$types = 'ii';

if ($search !== '') {
    $where[] = "(o.order_number LIKE ? OR CAST(o.woo_order_id AS CHAR) LIKE ? OR o.billing_name LIKE ? OR o.billing_email LIKE ? OR o.billing_phone LIKE ?)";
    $like = '%' . $search . '%';
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if ($statusFilter !== '') {
    $where[] = 'o.woo_status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($dateFrom !== '') {
    $where[] = 'DATE(o.date_created) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'DATE(o.date_created) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) AS total FROM woocommerce_orders o WHERE {$whereSql}";
$stmt = $conn->prepare($countSql);
$countParams = $params;
wooOrdersBind($stmt, $types, $countParams);
$stmt->execute();
$countResult = $stmt->get_result();
$totalRows = (int)(($countResult ? $countResult->fetch_assoc()['total'] : 0) ?? 0);
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = "\n    SELECT\n        o.*,\n        (SELECT COUNT(*) FROM woocommerce_order_items oi WHERE oi.order_mapping_id = o.id) AS item_count,\n        (SELECT COALESCE(SUM(oi.quantity), 0) FROM woocommerce_order_items oi WHERE oi.order_mapping_id = o.id) AS total_quantity\n    FROM woocommerce_orders o\n    WHERE {$whereSql}\n    ORDER BY COALESCE(o.date_created, o.created_at) DESC, o.id DESC\n    LIMIT ? OFFSET ?\n";
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;
$listTypes = $types . 'ii';
$stmt = $conn->prepare($listSql);
wooOrdersBind($stmt, $listTypes, $listParams);
$stmt->execute();
$result = $stmt->get_result();
$orders = [];
while ($result && ($row = $result->fetch_assoc())) {
    $orders[] = $row;
}
$stmt->close();

$stats = [
    'total' => 0,
    'processing' => 0,
    'completed' => 0,
    'revenue' => 0.0,
    'currency' => ''
];
$stmt = $conn->prepare("\n    SELECT\n        COUNT(*) AS total,\n        SUM(CASE WHEN woo_status = 'processing' THEN 1 ELSE 0 END) AS processing,\n        SUM(CASE WHEN woo_status = 'completed' THEN 1 ELSE 0 END) AS completed,\n        COALESCE(SUM(total), 0) AS revenue,
        MAX(currency) AS currency\n    FROM woocommerce_orders\n    WHERE client_id = ? AND integration_id = ?\n");
$stmt->bind_param('ii', $loggedClientId, $integrationId);
$stmt->execute();
$statsResult = $stmt->get_result();
$statsRow = $statsResult ? $statsResult->fetch_assoc() : null;
$stmt->close();
if ($statsRow) {
    $stats['total'] = (int)$statsRow['total'];
    $stats['processing'] = (int)$statsRow['processing'];
    $stats['completed'] = (int)$statsRow['completed'];
    $stats['revenue'] = (float)$statsRow['revenue'];
    $stats['currency'] = (string)($statsRow['currency'] ?? '');
}

function wooOrderStatusClass(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['completed'], true)) return 'status-completed';
    if (in_array($status, ['processing'], true)) return 'status-processing';
    if (in_array($status, ['cancelled', 'failed', 'refunded'], true)) return 'status-failed';
    if (in_array($status, ['on-hold'], true)) return 'status-hold';
    return 'status-pending';
}

function wooOrdersQueryUrl(array $changes = []): string
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WooCommerce Orders</title>
<script>
(function(){
    var theme='light';
    try{
        var saved=localStorage.getItem('oneerp_pos_theme')||localStorage.getItem('oneerp_theme')||localStorage.getItem('theme');
        if(saved==='light'||saved==='dark') theme=saved;
    }catch(e){}
    document.documentElement.setAttribute('data-pos-theme',theme);
})();
</script>
<?php include(__DIR__ . '/partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{--woo-purple:#7c3aed;--woo-purple2:#6d4cff;--woo-bg:#060a15;--woo-panel:rgba(15,23,42,.72);--woo-border:rgba(148,163,184,.17);--woo-text:#f8fafc;--woo-muted:#94a3b8}
*{box-sizing:border-box}body{margin:0;background:#070b14;color:var(--woo-text);font-family:Inter,"Segoe UI",sans-serif;overflow-x:hidden}.container-scroll{display:flex;min-height:100vh}.sidebar{width:270px;flex:0 0 270px}.main-panel{flex:1;min-width:0;margin-left:0;background:radial-gradient(circle at 15% 0%,rgba(124,58,237,.23),transparent 30%),linear-gradient(135deg,#050914,#0b1024 55%,#160f2d)}.topbar{height:70px;position:sticky;top:0;z-index:1020;background:#070b14;border-bottom:1px solid var(--woo-border)}.content-wrapper{padding:24px 26px;min-height:calc(100vh - 70px)}.page-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:16px}.eyebrow{font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#c4b5fd}.page-title{font-size:30px;font-weight:950;margin:5px 0;color:#fff}.page-subtitle{margin:0;color:var(--woo-muted);font-weight:600}.header-actions{display:flex;gap:9px;flex-wrap:wrap}.btn-soft,.btn-woo{min-height:44px;padding:0 15px;border-radius:12px;display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-weight:900;border:1px solid var(--woo-border);transition:.2s}.btn-soft{background:rgba(15,23,42,.72);color:#e5e7eb}.btn-soft:hover{background:rgba(124,58,237,.16);color:#fff}.btn-woo{border:0;background:linear-gradient(135deg,var(--woo-purple2),var(--woo-purple));color:#fff;box-shadow:0 12px 26px rgba(109,76,255,.28)}.btn-woo:hover{color:#fff;transform:translateY(-1px)}.connection-strip,.filters,.table-card{background:radial-gradient(circle at 15% 0%,rgba(124,58,237,.12),transparent 32%),linear-gradient(135deg,rgba(15,23,42,.76),rgba(17,24,39,.58));border:1px solid var(--woo-border);border-radius:17px;box-shadow:0 18px 44px rgba(0,0,0,.26);backdrop-filter:blur(18px)}.connection-strip{padding:13px 16px;display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.connection-main{display:flex;align-items:center;gap:11px;min-width:0}.connection-icon{width:38px;height:38px;border-radius:12px;background:rgba(34,197,94,.14);color:#4ade80;display:grid;place-items:center}.connection-name{font-weight:900}.connection-url{font-size:12px;color:var(--woo-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.connected-badge{padding:7px 11px;border-radius:999px;background:rgba(34,197,94,.13);border:1px solid rgba(34,197,94,.25);color:#4ade80;font-size:12px;font-weight:900}.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}.stat-card{min-height:120px;border-radius:17px;padding:17px;color:#fff;position:relative;overflow:hidden;box-shadow:0 16px 38px rgba(0,0,0,.25)}.stat-card::after{content:"";position:absolute;width:90px;height:90px;border-radius:28px;background:rgba(255,255,255,.11);right:-20px;top:-20px}.stat-total{background:linear-gradient(135deg,#2563eb,#6d4cff)}.stat-processing{background:linear-gradient(135deg,#d97706,#f59e0b)}.stat-completed{background:linear-gradient(135deg,#047857,#16a34a)}.stat-revenue{background:linear-gradient(135deg,#be123c,#ec4899)}.stat-icon{font-size:23px}.stat-label{font-size:13px;font-weight:800;opacity:.9;margin-top:12px}.stat-value{font-size:28px;font-weight:950;margin-top:2px}.filters{padding:13px;margin-bottom:16px}.filters form{display:grid;grid-template-columns:minmax(240px,1.4fr) repeat(3,minmax(145px,.55fr)) auto;gap:10px}.input-wrap{position:relative}.input-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8}.form-control,.form-select{height:44px;border-radius:11px!important;background:rgba(15,23,42,.8)!important;border:1px solid rgba(148,163,184,.2)!important;color:#e5e7eb!important;font-weight:700}.input-wrap .form-control{padding-left:39px}.form-control:focus,.form-select:focus{border-color:#7c3aed!important;box-shadow:0 0 0 3px rgba(124,58,237,.14)!important}.form-select option{background:#0f172a;color:#e5e7eb}.table-card{overflow:hidden}.table-responsive{overflow:auto}.orders-table{width:100%;min-width:1120px;border-collapse:collapse}.orders-table th{background:rgba(7,11,20,.72);color:#cbd5e1;padding:14px 12px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}.orders-table td{background:rgba(15,23,42,.2);color:#e5e7eb;padding:14px 12px;border-top:1px solid rgba(148,163,184,.1);vertical-align:middle}.orders-table tr:hover td{background:rgba(124,58,237,.1)}.order-number{font-weight:950;color:#fff}.small-muted{font-size:12px;color:#94a3b8}.status-badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:900;text-transform:capitalize}.status-completed{background:rgba(34,197,94,.14);color:#4ade80;border:1px solid rgba(34,197,94,.25)}.status-processing{background:rgba(59,130,246,.14);color:#60a5fa;border:1px solid rgba(59,130,246,.25)}.status-failed{background:rgba(239,68,68,.14);color:#f87171;border:1px solid rgba(239,68,68,.25)}.status-hold{background:rgba(245,158,11,.14);color:#fbbf24;border:1px solid rgba(245,158,11,.25)}.status-pending{background:rgba(148,163,184,.14);color:#cbd5e1;border:1px solid rgba(148,163,184,.25)}.action-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--woo-border);background:rgba(15,23,42,.78);color:#e5e7eb;display:inline-grid;place-items:center}.action-btn:hover{background:rgba(124,58,237,.18);color:#fff}.table-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 15px;border-top:1px solid var(--woo-border);flex-wrap:wrap}.pagination{margin:0}.page-link{background:rgba(15,23,42,.76)!important;color:#cbd5e1!important;border-color:rgba(148,163,184,.16)!important}.page-item.active .page-link{background:#7c3aed!important;color:#fff!important}.empty-state{text-align:center;padding:50px 20px;color:#94a3b8}.empty-state i{font-size:48px;color:#a78bfa}.modal-content{background:linear-gradient(135deg,#0f172a,#111827)!important;color:#f8fafc!important;border:1px solid rgba(139,92,246,.35)!important;border-radius:18px!important}.modal-header,.modal-footer{border-color:rgba(148,163,184,.14)!important}.detail-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.detail-box{background:rgba(15,23,42,.58);border:1px solid rgba(148,163,184,.14);border-radius:13px;padding:12px}.detail-label{font-size:11px;text-transform:uppercase;color:#94a3b8;font-weight:900}.detail-value{font-weight:800;margin-top:4px;word-break:break-word}.detail-items{width:100%;border-collapse:collapse;margin-top:15px}.detail-items th,.detail-items td{padding:10px;border-bottom:1px solid rgba(148,163,184,.12);font-size:13px}.sync-overlay{position:fixed;inset:0;background:rgba(2,6,23,.72);backdrop-filter:blur(7px);z-index:99999;display:none;align-items:center;justify-content:center}.sync-overlay.show{display:flex}.sync-card{width:min(470px,92vw);background:linear-gradient(135deg,#0f172a,#17102c);border:1px solid rgba(139,92,246,.38);border-radius:18px;padding:23px;box-shadow:0 28px 80px rgba(0,0,0,.58)}.progress{height:10px;background:#1e293b}.progress-bar{background:linear-gradient(90deg,#6d4cff,#a855f7)}.toast-box{position:fixed;right:22px;bottom:22px;z-index:100000;max-width:430px;padding:13px 16px;border-radius:13px;color:#fff;font-weight:800;display:none}.toast-box.show{display:block}.toast-box.success{background:#047857}.toast-box.error{background:#be123c}
html[data-pos-theme="light"] body{background:#f3f6fb;color:#0f172a}html[data-pos-theme="light"] .main-panel{background:linear-gradient(135deg,#f8fafc,#f1f5f9 55%,#faf7ff)}html[data-pos-theme="light"] .content-wrapper{color:#0f172a}html[data-pos-theme="light"] .page-title{color:#0f172a}html[data-pos-theme="light"] .page-subtitle,html[data-pos-theme="light"] .connection-url,html[data-pos-theme="light"] .small-muted{color:#64748b}html[data-pos-theme="light"] .connection-strip,html[data-pos-theme="light"] .filters,html[data-pos-theme="light"] .table-card{background:#fff;border-color:#dce4ef;box-shadow:0 12px 30px rgba(15,23,42,.07);backdrop-filter:none}html[data-pos-theme="light"] .form-control,html[data-pos-theme="light"] .form-select{background:#fff!important;color:#0f172a!important;border-color:#cbd5e1!important}html[data-pos-theme="light"] .form-select option{background:#fff;color:#0f172a}html[data-pos-theme="light"] .orders-table th{background:#f1f5f9;color:#334155}html[data-pos-theme="light"] .orders-table td{background:#fff;color:#1e293b;border-color:#e8edf4}html[data-pos-theme="light"] .orders-table tr:hover td{background:#f6f2ff}html[data-pos-theme="light"] .order-number{color:#0f172a}html[data-pos-theme="light"] .btn-soft,html[data-pos-theme="light"] .action-btn{background:#fff;color:#334155;border-color:#cbd5e1}html[data-pos-theme="light"] .modal-content{background:#fff!important;color:#0f172a!important;border-color:#d8e0eb!important}html[data-pos-theme="light"] .detail-box{background:#f8fafc;border-color:#e2e8f0}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr)}.filters form{grid-template-columns:1fr 1fr}.filters form .btn-woo{grid-column:span 2}}@media(max-width:700px){.content-wrapper{padding:16px}.stats-grid{grid-template-columns:1fr}.filters form{grid-template-columns:1fr}.filters form .btn-woo{grid-column:auto}.header-actions{width:100%}.header-actions a,.header-actions button{flex:1}.detail-grid{grid-template-columns:1fr}}
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
                    <h1 class="page-title">WooCommerce Orders</h1>
                    <p class="page-subtitle">WooCommerce orders ko ONEERP order inbox mein pull aur monitor karein.</p>
                </div>
                <div class="header-actions">
                    <a href="woocommerce_products.php" class="btn-soft"><i class="bi bi-box-seam"></i> Product Sync</a>
                    <a href="woocommerce_inventory_sync.php" class="btn-soft"><i class="bi bi-arrow-left-right"></i> Inventory Sync</a>
                    <button type="button" class="btn-woo" id="pullOrdersBtn"><i class="bi bi-cloud-download"></i> Pull Orders</button>
                    <a href="<?= htmlspecialchars($wooAdminOrdersUrl) ?>" target="_blank" rel="noopener" class="btn-soft"><i class="bi bi-box-arrow-up-right"></i> Open WooCommerce</a>
                </div>
            </div>

            <section class="connection-strip">
                <div class="connection-main">
                    <div class="connection-icon"><i class="bi bi-check-circle-fill"></i></div>
                    <div style="min-width:0">
                        <div class="connection-name"><?= htmlspecialchars((string)($wooConnection['integration_name'] ?? 'WooCommerce')) ?></div>
                        <div class="connection-url"><?= htmlspecialchars($storeUrl) ?></div>
                    </div>
                </div>
                <span class="connected-badge"><i class="bi bi-shield-check"></i> Active &amp; Connected</span>
            </section>

            <section class="stats-grid">
                <article class="stat-card stat-total"><div class="stat-icon"><i class="bi bi-bag-check"></i></div><div class="stat-label">Pulled Orders</div><div class="stat-value"><?= number_format($stats['total']) ?></div></article>
                <article class="stat-card stat-processing"><div class="stat-icon"><i class="bi bi-arrow-repeat"></i></div><div class="stat-label">Processing</div><div class="stat-value"><?= number_format($stats['processing']) ?></div></article>
                <article class="stat-card stat-completed"><div class="stat-icon"><i class="bi bi-check2-circle"></i></div><div class="stat-label">Completed</div><div class="stat-value"><?= number_format($stats['completed']) ?></div></article>
                <article class="stat-card stat-revenue"><div class="stat-icon"><i class="bi bi-cash-stack"></i></div><div class="stat-label">Order Value</div><div class="stat-value"><?= htmlspecialchars($stats['currency']) ?> <?= number_format($stats['revenue'], 2) ?></div></article>
            </section>

            <section class="filters">
                <form method="get">
                    <div class="input-wrap"><i class="bi bi-search"></i><input class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Order, customer, email, phone..."></div>
                    <select class="form-select" name="status">
                        <option value="">All Status</option>
                        <?php foreach (['pending','processing','on-hold','completed','cancelled','refunded','failed'] as $status): ?>
                            <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= ucwords(str_replace('-', ' ', $status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                    <button class="btn-woo" type="submit"><i class="bi bi-funnel"></i> Apply</button>
                </form>
            </section>

            <section class="table-card">
                <div class="table-responsive">
                    <table class="orders-table">
                        <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Status</th><th>Payment</th><th>Total</th><th>Date</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="8"><div class="empty-state"><i class="bi bi-bag-x"></i><h3>No Orders Found</h3><p>Pull Orders button se WooCommerce orders import karein.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><div class="order-number">#<?= htmlspecialchars((string)$order['order_number']) ?></div><div class="small-muted">Woo ID: <?= (int)$order['woo_order_id'] ?></div></td>
                                    <td><div style="font-weight:850"><?= htmlspecialchars((string)($order['billing_name'] ?: 'Guest')) ?></div><div class="small-muted"><?= htmlspecialchars((string)($order['billing_email'] ?: $order['billing_phone'])) ?></div></td>
                                    <td><div style="font-weight:850"><?= number_format((float)$order['total_quantity'], 2) ?> qty</div><div class="small-muted"><?= (int)$order['item_count'] ?> line items</div></td>
                                    <td><span class="status-badge <?= wooOrderStatusClass((string)$order['woo_status']) ?>"><?= htmlspecialchars(ucwords(str_replace('-', ' ', (string)$order['woo_status']))) ?></span></td>
                                    <td><div style="font-weight:800"><?= htmlspecialchars((string)($order['payment_method_title'] ?: $order['payment_method'] ?: 'N/A')) ?></div><div class="small-muted"><?= htmlspecialchars((string)$order['transaction_id']) ?></div></td>
                                    <td><div style="font-weight:950"><?= htmlspecialchars((string)$order['currency']) ?> <?= number_format((float)$order['total'], 2) ?></div><div class="small-muted">Tax <?= number_format((float)$order['total_tax'], 2) ?></div></td>
                                    <td><div style="font-weight:800"><?= !empty($order['date_created']) ? htmlspecialchars(date('d M Y', strtotime($order['date_created']))) : '-' ?></div><div class="small-muted"><?= !empty($order['date_created']) ? htmlspecialchars(date('h:i A', strtotime($order['date_created']))) : '' ?></div></td>
                                    <td><button type="button" class="action-btn order-detail-btn" data-order-id="<?= (int)$order['id'] ?>" title="View details"><i class="bi bi-eye"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">
                    <div class="small-muted">Showing <?= $totalRows > 0 ? ($offset + 1) : 0 ?>-<?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?></div>
                    <div class="d-flex align-items-center gap-2">
                        <select class="form-select" id="perPageSelect" style="width:120px">
                            <?php foreach ([10,25,50,100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> / page</option><?php endforeach; ?>
                        </select>
                        <nav><ul class="pagination pagination-sm">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(wooOrdersQueryUrl(['page' => max(1, $page - 1)])) ?>">Previous</a></li>
                            <li class="page-item active"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(wooOrdersQueryUrl(['page' => min($totalPages, $page + 1)])) ?>">Next</a></li>
                        </ul></nav>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal fade" id="orderDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">WooCommerce Order Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="orderDetailBody"><div class="text-center py-5"><span class="spinner-border"></span></div></div>
            <div class="modal-footer"><button type="button" class="btn-soft" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<div class="sync-overlay" id="syncOverlay"><div class="sync-card"><h4 id="syncTitle">Pulling WooCommerce Orders</h4><p class="small-muted" id="syncText">Please wait...</p><div class="progress"><div class="progress-bar" id="syncProgress" style="width:0%"></div></div></div></div>
<div class="toast-box" id="toastBox"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    'use strict';
    var csrfToken=<?= json_encode($csrfToken) ?>;
    var integrationId=<?= (int)$integrationId ?>;
    var pullBtn=document.getElementById('pullOrdersBtn');
    var overlay=document.getElementById('syncOverlay');
    var syncTitle=document.getElementById('syncTitle');
    var syncText=document.getElementById('syncText');
    var syncProgress=document.getElementById('syncProgress');
    var toastBox=document.getElementById('toastBox');
    var detailModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('orderDetailModal'));

    function showToast(message,success){toastBox.textContent=message||'Completed.';toastBox.className='toast-box show '+(success?'success':'error');clearTimeout(showToast.timer);showToast.timer=setTimeout(function(){toastBox.className='toast-box';},5000)}
    function setOverlay(show,title,text,percent){overlay.classList.toggle('show',!!show);if(title)syncTitle.textContent=title;if(text)syncText.textContent=text;if(typeof percent!=='undefined')syncProgress.style.width=Math.max(0,Math.min(100,percent))+'%'}
    async function post(url,data){var body=new URLSearchParams();Object.keys(data).forEach(function(key){body.set(key,data[key])});var response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:body.toString()});var result;try{result=await response.json()}catch(e){throw new Error('Server ne valid JSON response nahi diya.')}if(result.redirect){window.location.href=result.redirect}return result}

    if(pullBtn){pullBtn.addEventListener('click',async function(){if(!confirm('WooCommerce ke tamam available orders ONEERP mein pull/update karne hain?'))return;pullBtn.disabled=true;var page=1,perPage=50,totalCreated=0,totalUpdated=0,totalFailed=0,maxPages=200;setOverlay(true,'Pulling WooCommerce Orders','Page 1 load ho raha hai...',2);try{while(page<=maxPages){setOverlay(true,'Pulling WooCommerce Orders','Remote page '+page+' process ho raha hai...',Math.min(95,5+page));var result=await post('/onerp/app/public/app/ajax/woocommerce_pull_orders.php',{csrf_token:csrfToken,integration_id:integrationId,page:page,per_page:perPage});if(!result.success&&!result.partial){throw new Error(result.message||'Orders pull failed.')}totalCreated+=parseInt(result.created||0,10);totalUpdated+=parseInt(result.updated||0,10);totalFailed+=parseInt(result.failed||0,10);if(!result.has_more)break;page++}setOverlay(true,'Orders Pull Completed',totalCreated+' created, '+totalUpdated+' updated, '+totalFailed+' failed.',100);setTimeout(function(){window.location.reload()},900)}catch(error){setOverlay(false);showToast(error.message||'Orders pull failed.',false);pullBtn.disabled=false}})}

    document.querySelectorAll('.order-detail-btn').forEach(function(button){button.addEventListener('click',async function(){var orderId=parseInt(button.dataset.orderId||'0',10);var body=document.getElementById('orderDetailBody');body.innerHTML='<div class="text-center py-5"><span class="spinner-border"></span></div>';detailModal.show();try{var result=await post(window.location.pathname,{ajax:'order_detail',csrf_token:csrfToken,order_id:orderId});if(!result.success)throw new Error(result.message||'Order detail load failed.');var o=result.order||{},items=result.items||[];var html='<div class="detail-grid">'+
        '<div class="detail-box"><div class="detail-label">Order</div><div class="detail-value">#'+escapeHtml(o.order_number)+' · Woo ID '+escapeHtml(o.woo_order_id)+'</div></div>'+
        '<div class="detail-box"><div class="detail-label">Status</div><div class="detail-value">'+escapeHtml(o.woo_status)+'</div></div>'+
        '<div class="detail-box"><div class="detail-label">Customer</div><div class="detail-value">'+escapeHtml(o.billing_name||'Guest')+'<br>'+escapeHtml(o.billing_email||'')+' '+escapeHtml(o.billing_phone||'')+'</div></div>'+
        '<div class="detail-box"><div class="detail-label">Payment</div><div class="detail-value">'+escapeHtml(o.payment_method_title||o.payment_method||'N/A')+'<br>'+escapeHtml(o.transaction_id||'')+'</div></div>'+
        '<div class="detail-box"><div class="detail-label">Billing Address</div><div class="detail-value">'+escapeHtml(o.billing_address||'-')+'</div></div>'+
        '<div class="detail-box"><div class="detail-label">Shipping Address</div><div class="detail-value">'+escapeHtml(o.shipping_address||'-')+'</div></div></div>'+
        '<table class="detail-items"><thead><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Mapping</th></tr></thead><tbody>';
        items.forEach(function(item){html+='<tr><td>'+escapeHtml(item.name||'')+'</td><td>'+escapeHtml(item.sku||'-')+'</td><td>'+Number(item.quantity||0).toFixed(2)+'</td><td>'+Number(item.unit_price||0).toFixed(2)+'</td><td>'+Number(item.total||0).toFixed(2)+'</td><td>'+(parseInt(item.item_id||0,10)>0?'ONEERP #'+escapeHtml(item.item_id):'Not mapped')+'</td></tr>'});
        html+='</tbody></table><div class="detail-box mt-3"><div class="detail-label">Totals</div><div class="detail-value">Subtotal '+escapeHtml(o.currency)+' '+Number(o.subtotal||0).toFixed(2)+' · Discount '+Number(o.discount_total||0).toFixed(2)+' · Shipping '+Number(o.shipping_total||0).toFixed(2)+' · Tax '+Number(o.total_tax||0).toFixed(2)+' · Grand Total '+Number(o.total||0).toFixed(2)+'</div></div>';
        body.innerHTML=html}catch(error){body.innerHTML='<div class="alert alert-danger">'+escapeHtml(error.message||'Order detail load failed.')+'</div>'}})})

    function escapeHtml(value){var div=document.createElement('div');div.textContent=String(value===null||typeof value==='undefined'?'':value);return div.innerHTML}
    var perPageSelect=document.getElementById('perPageSelect');if(perPageSelect){perPageSelect.addEventListener('change',function(){var url=new URL(window.location.href);url.searchParams.set('per_page',perPageSelect.value);url.searchParams.set('page','1');window.location.href=url.toString()})}
})();
</script>
</body>
</html>
