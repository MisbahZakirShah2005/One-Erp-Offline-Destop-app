<?php
session_start();

require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php'
);

require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/shopify.php'
);

require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/integration_helper.php'
);

require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_crypto_helper.php'
);

require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_auth_helper.php'
);

require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_api_helper.php'
);

require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_inventory_helper.php'
);

require_once(
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_product_helper.php'
);

if (empty($_SESSION['user_id'])) {
    header(
        'Location: /public/app/views/login_view.php'
    );
    exit;
}

$clientId = (int)(
    $_SESSION['client_id'] ?? 0
);

requirePlanFeature(
    $conn,
    $clientId,
    'shopify_products'
);

if (empty($_SESSION['shopify_product_csrf'])) {
    $_SESSION['shopify_product_csrf'] =
        bin2hex(
            random_bytes(32)
        );
}

$shopifyProductCsrf = (string)(
    $_SESSION['shopify_product_csrf']
);

$activeStore = null;

try {
    $storeCandidate = getShopifyStoreForClient(
        $conn,
        $clientId
    );

    if (
        $storeCandidate &&
        strtolower(
            (string)($storeCandidate['status'] ?? '')
        ) === 'active'
    ) {
        $activeStore = $storeCandidate;
    }
} catch (Throwable $ignore) {
    $activeStore = null;
}

$activeStoreId = (int)(
    $activeStore['id'] ?? 0
);

$shopifyConnectionReady =
    $activeStoreId > 0;

$items = [];

if (oneerpTableExists($conn, 'shopify_product_map')) {
    $stmt = $conn->prepare("
        SELECT
            i.id,
            i.name,
            i.sku,
            i.price,
            i.stock_qty,
            m.sync_status,
            m.last_error,
            m.last_synced_at,
            m.shopify_product_gid,
            m.shopify_handle
        FROM items i
        LEFT JOIN shopify_product_map m
            ON m.item_id = i.id
           AND m.client_id = i.client_id
           AND m.shopify_store_id = ?
        WHERE i.client_id = ?
        ORDER BY i.id DESC
        LIMIT 200
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $activeStoreId,
            $clientId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| UI summary only
|--------------------------------------------------------------------------
| Existing Shopify backend workflow remains unchanged.
|--------------------------------------------------------------------------
*/
$totalItems = count($items);
$syncedItems = 0;
$pendingItems = 0;
$failedItems = 0;
$notSyncedItems = 0;
$latestSyncAt = null;

foreach ($items as $item) {
    $status = strtolower(
        trim(
            (string)($item['sync_status'] ?? '')
        )
    );

    if ($status === 'synced') {
        $syncedItems++;
    } elseif (
        $status === 'pending' ||
        $status === 'syncing'
    ) {
        $pendingItems++;
    } elseif ($status === 'failed') {
        $failedItems++;
    } else {
        $notSyncedItems++;
    }

    $lastSyncedAt = trim(
        (string)($item['last_synced_at'] ?? '')
    );

    if (
        $lastSyncedAt !== '' &&
        (
            $latestSyncAt === null ||
            strtotime($lastSyncedAt) > strtotime($latestSyncAt)
        )
    ) {
        $latestSyncAt = $lastSyncedAt;
    }
}

function shopifyProductStatusMeta($status): array
{
    $status = strtolower(
        trim(
            (string)$status
        )
    );

    if ($status === 'synced') {
        return [
            'label' => 'Synced',
            'class' => 'sync-synced',
            'icon' => 'bi-check-circle-fill',
        ];
    }

    if ($status === 'syncing') {
        return [
            'label' => 'Syncing',
            'class' => 'sync-syncing',
            'icon' => 'bi-arrow-repeat',
        ];
    }

    if ($status === 'pending') {
        return [
            'label' => 'Pending',
            'class' => 'sync-pending',
            'icon' => 'bi-clock-fill',
        ];
    }

    if ($status === 'failed') {
        return [
            'label' => 'Failed',
            'class' => 'sync-failed',
            'icon' => 'bi-exclamation-triangle-fill',
        ];
    }

    if ($status === 'deleted') {
        return [
            'label' => 'Deleted',
            'class' => 'sync-deleted',
            'icon' => 'bi-trash3-fill',
        ];
    }

    return [
        'label' => 'Not synced',
        'class' => 'sync-none',
        'icon' => 'bi-cloud-slash-fill',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Shopify Products - ONEERP</title>

    <?php include('partials/head.php'); ?>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>
        /* =====================================================
           SHOPIFY PRODUCTS PAGE
           Existing ONEERP layout / backend remains unchanged.
        ===================================================== */

        body.shopify-products-page{
            min-height:100vh;
            overflow-x:hidden;
            background:
                radial-gradient(
                    circle at 10% 5%,
                    rgba(124,58,237,.24),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 88% 4%,
                    rgba(34,197,94,.10),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #050914 0%,
                    #0b1024 46%,
                    #160f2d 100%
                ) !important;
            color:#e5e7eb;
        }

        body.shopify-products-page .main-panel{
            min-height:100vh;
            background:transparent !important;
        }

        body.shopify-products-page .content-wrapper{
            position:relative;
            min-height:100vh;
            padding:
                94px
                24px
                28px !important;
            background:
                radial-gradient(
                    circle at 14% 4%,
                    rgba(124,58,237,.18),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 85% 8%,
                    rgba(34,197,94,.08),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #050914 0%,
                    #0b1024 48%,
                    #160f2d 100%
                ) !important;
        }

        body.shopify-products-page .content-wrapper::before{
            content:"";
            position:absolute;
            inset:0;
            pointer-events:none;
            background:
                linear-gradient(
                    90deg,
                    rgba(255,255,255,.025) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    180deg,
                    rgba(255,255,255,.02) 1px,
                    transparent 1px
                );
            background-size:44px 44px;
            opacity:.28;
        }

        body.shopify-products-page .content-wrapper > *{
            position:relative;
            z-index:1;
        }

        /* =====================================================
           HEADER
        ===================================================== */

        .shopify-page-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:18px;
            flex-wrap:wrap;
            margin-bottom:20px;
            padding:19px 20px;
            border:1px solid rgba(139,92,246,.24);
            border-radius:18px;
            background:
                radial-gradient(
                    circle at 12% 0%,
                    rgba(124,58,237,.24),
                    transparent 38%
                ),
                linear-gradient(
                    135deg,
                    rgba(15,23,42,.82),
                    rgba(17,24,39,.62)
                );
            box-shadow:
                0 18px 48px rgba(0,0,0,.28),
                inset 0 1px 0 rgba(255,255,255,.05);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
        }

        .shopify-title-group{
            display:flex;
            align-items:center;
            gap:14px;
            min-width:0;
        }

        .shopify-title-icon{
            width:52px;
            min-width:52px;
            height:52px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:15px;
            color:#ffffff;
            font-size:24px;
            background:
                linear-gradient(
                    135deg,
                    #008060,
                    #00a47c
                );
            box-shadow:
                0 14px 28px rgba(0,128,96,.28),
                inset 0 1px 0 rgba(255,255,255,.18);
        }

        .shopify-page-header h3{
            margin:0;
            color:#f8fafc;
            font-size:25px;
            font-weight:900;
            letter-spacing:-.03em;
        }

        .shopify-page-subtitle{
            margin-top:4px;
            color:#94a3b8;
            font-size:13px;
            font-weight:700;
        }

        .foundation-badge{
            display:inline-flex;
            align-items:center;
            gap:8px;
            min-height:38px;
            padding:0 13px;
            border-radius:999px;
            color:#86efac;
            background:rgba(34,197,94,.12);
            border:1px solid rgba(34,197,94,.28);
            font-size:12px;
            font-weight:900;
            white-space:nowrap;
        }

        /* =====================================================
           SUMMARY CARDS
        ===================================================== */

        .shopify-summary-grid{
            display:grid;
            grid-template-columns:
                repeat(4, minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .shopify-summary-card{
            position:relative;
            overflow:hidden;
            min-height:126px;
            padding:18px;
            border-radius:18px;
            border:1px solid rgba(148,163,184,.16);
            background:
                linear-gradient(
                    135deg,
                    rgba(15,23,42,.82),
                    rgba(30,20,58,.64)
                );
            box-shadow:
                0 16px 38px rgba(0,0,0,.25),
                inset 0 1px 0 rgba(255,255,255,.05);
            backdrop-filter:blur(16px);
            -webkit-backdrop-filter:blur(16px);
        }

        .shopify-summary-card::after{
            content:"";
            position:absolute;
            right:-25px;
            top:-25px;
            width:100px;
            height:100px;
            border-radius:50%;
            background:rgba(255,255,255,.07);
        }

        .summary-card-top{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .summary-card-label{
            color:#cbd5e1;
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.05em;
        }

        .summary-card-icon{
            width:38px;
            height:38px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:12px;
            font-size:17px;
            background:rgba(255,255,255,.08);
        }

        .summary-card-value{
            color:#ffffff;
            font-size:29px;
            line-height:1;
            font-weight:950;
            letter-spacing:-.03em;
        }

        .summary-card-note{
            margin-top:8px;
            color:#94a3b8;
            font-size:11px;
            font-weight:700;
        }

        .summary-total{
            background:
                linear-gradient(
                    135deg,
                    rgba(59,130,246,.78),
                    rgba(99,102,241,.72)
                );
        }

        .summary-synced{
            background:
                linear-gradient(
                    135deg,
                    rgba(5,150,105,.82),
                    rgba(16,185,129,.72)
                );
        }

        .summary-pending{
            background:
                linear-gradient(
                    135deg,
                    rgba(124,58,237,.86),
                    rgba(139,92,246,.72)
                );
        }

        .summary-failed{
            background:
                linear-gradient(
                    135deg,
                    rgba(190,24,93,.84),
                    rgba(239,68,68,.72)
                );
        }

        .shopify-summary-card[class*="summary-"]
        .summary-card-label,
        .shopify-summary-card[class*="summary-"]
        .summary-card-note{
            color:rgba(255,255,255,.86);
        }

        /* =====================================================
           TOOLBAR
        ===================================================== */

        .shopify-toolbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:14px;
            padding:13px;
            border-radius:16px;
            border:1px solid rgba(148,163,184,.16);
            background:
                linear-gradient(
                    135deg,
                    rgba(15,23,42,.80),
                    rgba(17,24,39,.58)
                );
            box-shadow:
                0 14px 34px rgba(0,0,0,.22),
                inset 0 1px 0 rgba(255,255,255,.04);
            backdrop-filter:blur(16px);
            -webkit-backdrop-filter:blur(16px);
        }

        .shopify-search-wrap{
            position:relative;
            flex:1 1 360px;
            min-width:220px;
        }

        .shopify-search-wrap i{
            position:absolute;
            left:14px;
            top:50%;
            transform:translateY(-50%);
            color:#94a3b8;
            pointer-events:none;
        }

        .shopify-search-input,
        .shopify-status-filter{
            min-height:44px;
            border-radius:12px !important;
            background:rgba(15,23,42,.78) !important;
            color:#f8fafc !important;
            border:1px solid rgba(148,163,184,.18) !important;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.03) !important;
            font-size:13px;
            font-weight:800;
        }

        .shopify-search-input{
            padding-left:41px !important;
        }

        .shopify-search-input::placeholder{
            color:#64748b;
        }

        .shopify-search-input:focus,
        .shopify-status-filter:focus{
            border-color:rgba(124,58,237,.62) !important;
            box-shadow:
                0 0 0 3px rgba(124,58,237,.15) !important;
        }

        .shopify-status-filter{
            width:190px;
            max-width:100%;
        }

        .shopify-status-filter option{
            background:#0f172a;
            color:#f8fafc;
        }

        .table-result-count{
            display:flex;
            align-items:center;
            gap:7px;
            min-height:38px;
            padding:0 11px;
            border-radius:10px;
            color:#cbd5e1;
            background:rgba(124,58,237,.10);
            border:1px solid rgba(124,58,237,.18);
            font-size:12px;
            font-weight:900;
            white-space:nowrap;
        }

        /* =====================================================
           TABLE PANEL
        ===================================================== */

        .shopify-panel{
            overflow:hidden;
            border-radius:19px;
            border:1px solid rgba(139,92,246,.22);
            background:
                radial-gradient(
                    circle at 10% 0%,
                    rgba(124,58,237,.12),
                    transparent 34%
                ),
                linear-gradient(
                    135deg,
                    rgba(12,18,35,.90),
                    rgba(20,12,40,.78)
                );
            box-shadow:
                0 22px 58px rgba(0,0,0,.32),
                inset 0 1px 0 rgba(255,255,255,.05);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
        }

        .shopify-table-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            padding:16px 18px;
            border-bottom:1px solid rgba(148,163,184,.12);
            background:rgba(7,11,20,.38);
        }

        .shopify-table-title{
            display:flex;
            align-items:center;
            gap:10px;
            color:#f8fafc;
            font-size:15px;
            font-weight:900;
        }

        .latest-sync-text{
            color:#94a3b8;
            font-size:11px;
            font-weight:800;
        }

        .shopify-table-wrap{
            width:100%;
            overflow:auto;
        }

        .shopify-products-table{
            width:100%;
            min-width:1180px;
            margin:0 !important;
            border-collapse:separate;
            border-spacing:0;
            color:#e5e7eb;
            --bs-table-bg:transparent;
            --bs-table-color:#e5e7eb;
            --bs-table-border-color:rgba(148,163,184,.10);
        }

        .shopify-products-table thead th{
            position:sticky;
            top:0;
            z-index:2;
            padding:14px 16px;
            background:rgba(6,10,20,.96) !important;
            color:#cbd5e1 !important;
            border:none !important;
            border-bottom:1px solid rgba(139,92,246,.22) !important;
            font-size:11px;
            font-weight:950;
            text-transform:uppercase;
            letter-spacing:.06em;
            white-space:nowrap;
        }

        .shopify-products-table tbody td{
            padding:13px 16px;
            color:#dbe4f0 !important;
            background:rgba(15,23,42,.26) !important;
            border:none !important;
            border-bottom:1px solid rgba(148,163,184,.08) !important;
            font-size:13px;
            font-weight:700;
            vertical-align:middle;
        }

        .shopify-products-table tbody tr{
            transition:.18s ease;
        }

        .shopify-products-table tbody tr:hover td{
            background:rgba(124,58,237,.10) !important;
            color:#ffffff !important;
        }

        .product-id-badge{
            display:inline-flex;
            align-items:center;
            min-height:28px;
            padding:0 9px;
            border-radius:9px;
            color:#c4b5fd;
            background:rgba(124,58,237,.12);
            border:1px solid rgba(124,58,237,.20);
            font-size:11px;
            font-weight:900;
        }

        .product-name-cell{
            min-width:300px;
        }

        .product-name-text{
            display:block;
            max-width:520px;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
            color:#f8fafc;
            font-weight:900;
        }

        .product-secondary{
            margin-top:4px;
            color:#64748b;
            font-size:10px;
            font-weight:800;
        }

        .sku-value{
            display:inline-flex;
            align-items:center;
            min-height:28px;
            padding:0 8px;
            border-radius:8px;
            color:#bae6fd;
            background:rgba(14,165,233,.10);
            border:1px solid rgba(14,165,233,.16);
            font-size:11px;
            font-weight:900;
        }

        .sku-empty{
            color:#64748b;
            font-size:11px;
            font-weight:800;
        }

        .money-value{
            color:#f8fafc;
            font-weight:950;
            white-space:nowrap;
        }

        .stock-value{
            display:inline-flex;
            align-items:center;
            gap:6px;
            white-space:nowrap;
            color:#dbe4f0;
            font-weight:900;
        }

        .stock-dot{
            width:8px;
            height:8px;
            border-radius:50%;
            background:#22c55e;
            box-shadow:0 0 9px rgba(34,197,94,.48);
        }

        .stock-zero .stock-dot{
            background:#ef4444;
            box-shadow:0 0 9px rgba(239,68,68,.45);
        }

        /* =====================================================
           STATUS BADGES
        ===================================================== */

        .sync-badge{
            display:inline-flex;
            align-items:center;
            gap:7px;
            min-height:31px;
            padding:0 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:950;
            white-space:nowrap;
        }

        .sync-synced{
            color:#86efac;
            background:rgba(34,197,94,.13);
            border:1px solid rgba(34,197,94,.28);
        }

        .sync-pending{
            color:#fcd34d;
            background:rgba(245,158,11,.12);
            border:1px solid rgba(245,158,11,.27);
        }

        .sync-syncing{
            color:#c4b5fd;
            background:rgba(124,58,237,.14);
            border:1px solid rgba(124,58,237,.28);
        }

        .sync-syncing i{
            animation:shopifySpin 1.1s linear infinite;
        }

        .sync-failed{
            color:#fca5a5;
            background:rgba(239,68,68,.13);
            border:1px solid rgba(239,68,68,.28);
        }

        .sync-deleted{
            color:#cbd5e1;
            background:rgba(100,116,139,.14);
            border:1px solid rgba(100,116,139,.25);
        }

        .sync-none{
            color:#94a3b8;
            background:rgba(100,116,139,.10);
            border:1px solid rgba(100,116,139,.20);
        }

        @keyframes shopifySpin{
            to{
                transform:rotate(360deg);
            }
        }

        .sync-error-text{
            display:block;
            max-width:260px;
            margin-top:5px;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
            color:#fca5a5;
            font-size:10px;
            font-weight:800;
        }

        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .shopify-empty-state{
            padding:56px 22px !important;
            text-align:center;
        }

        .shopify-empty-icon{
            width:66px;
            height:66px;
            display:flex;
            align-items:center;
            justify-content:center;
            margin:0 auto 14px;
            border-radius:18px;
            color:#c4b5fd;
            background:rgba(124,58,237,.12);
            border:1px solid rgba(124,58,237,.22);
            font-size:28px;
        }

        .shopify-empty-title{
            color:#f8fafc;
            font-size:15px;
            font-weight:900;
        }

        .shopify-empty-text{
            margin-top:5px;
            color:#94a3b8;
            font-size:12px;
            font-weight:700;
        }

        .shopify-no-search-results{
            display:none;
            padding:36px 22px;
            text-align:center;
            color:#94a3b8;
            font-size:13px;
            font-weight:800;
        }

        /* =====================================================
           SCROLLBAR
        ===================================================== */

        .shopify-table-wrap::-webkit-scrollbar{
            width:9px;
            height:9px;
        }

        .shopify-table-wrap::-webkit-scrollbar-track{
            background:rgba(15,23,42,.72);
        }

        .shopify-table-wrap::-webkit-scrollbar-thumb{
            background:
                linear-gradient(
                    135deg,
                    #6d4cff,
                    #7c3aed
                );
            border-radius:999px;
        }

        /* =====================================================
           LIGHT MODE
        ===================================================== */

        html[data-pos-theme="light"]
        body.shopify-products-page{
            background:
                radial-gradient(
                    circle at 12% 4%,
                    rgba(124,58,237,.09),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 86% 6%,
                    rgba(34,197,94,.06),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #f7f8fd,
                    #eef2f8
                ) !important;
            color:#0f172a;
        }

        html[data-pos-theme="light"]
        body.shopify-products-page .content-wrapper{
            background:
                radial-gradient(
                    circle at 12% 4%,
                    rgba(124,58,237,.08),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 86% 6%,
                    rgba(34,197,94,.05),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #f7f8fd,
                    #eef2f8
                ) !important;
        }

        html[data-pos-theme="light"]
        body.shopify-products-page .content-wrapper::before{
            background:
                linear-gradient(
                    90deg,
                    rgba(15,23,42,.025) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    180deg,
                    rgba(15,23,42,.02) 1px,
                    transparent 1px
                );
        }

        html[data-pos-theme="light"] .shopify-page-header,
        html[data-pos-theme="light"] .shopify-toolbar,
        html[data-pos-theme="light"] .shopify-panel,
        html[data-pos-theme="light"] .shopify-summary-card{
            background:
                linear-gradient(
                    135deg,
                    rgba(255,255,255,.98),
                    rgba(248,250,252,.94)
                ) !important;
            border-color:#dde3ee !important;
            box-shadow:
                0 16px 38px rgba(15,23,42,.08),
                inset 0 1px 0 #ffffff !important;
        }

        html[data-pos-theme="light"]
        .shopify-summary-card[class*="summary-"]{
            color:#ffffff;
            border-color:transparent !important;
        }

        html[data-pos-theme="light"] .summary-total{
            background:
                linear-gradient(
                    135deg,
                    #3b82f6,
                    #6366f1
                ) !important;
        }

        html[data-pos-theme="light"] .summary-synced{
            background:
                linear-gradient(
                    135deg,
                    #059669,
                    #10b981
                ) !important;
        }

        html[data-pos-theme="light"] .summary-pending{
            background:
                linear-gradient(
                    135deg,
                    #7c3aed,
                    #8b5cf6
                ) !important;
        }

        html[data-pos-theme="light"] .summary-failed{
            background:
                linear-gradient(
                    135deg,
                    #be185d,
                    #ef4444
                ) !important;
        }

        html[data-pos-theme="light"] .shopify-page-header h3,
        html[data-pos-theme="light"] .shopify-table-title,
        html[data-pos-theme="light"] .product-name-text,
        html[data-pos-theme="light"] .money-value,
        html[data-pos-theme="light"] .shopify-empty-title{
            color:#0f172a !important;
        }

        html[data-pos-theme="light"] .shopify-page-subtitle,
        html[data-pos-theme="light"] .latest-sync-text,
        html[data-pos-theme="light"] .summary-card-note{
            color:#64748b;
        }

        html[data-pos-theme="light"] .shopify-search-input,
        html[data-pos-theme="light"] .shopify-status-filter{
            background:#ffffff !important;
            color:#0f172a !important;
            border-color:#dbe2ec !important;
        }

        html[data-pos-theme="light"] .shopify-search-input::placeholder{
            color:#94a3b8;
        }

        html[data-pos-theme="light"] .shopify-status-filter option{
            background:#ffffff;
            color:#0f172a;
        }

        html[data-pos-theme="light"] .table-result-count{
            color:#5b21b6;
            background:#f3efff;
            border-color:#ddd6fe;
        }

        html[data-pos-theme="light"] .shopify-table-head{
            background:#fbfcff;
            border-color:#e3e8f0;
        }

        html[data-pos-theme="light"]
        .shopify-products-table{
            color:#1e293b;
            --bs-table-color:#1e293b;
            --bs-table-border-color:#e6eaf0;
        }

        html[data-pos-theme="light"]
        .shopify-products-table thead th{
            background:#f1f4f9 !important;
            color:#475569 !important;
            border-bottom-color:#dce3ee !important;
        }

        html[data-pos-theme="light"]
        .shopify-products-table tbody td{
            background:#ffffff !important;
            color:#334155 !important;
            border-bottom-color:#e7ebf1 !important;
        }

        html[data-pos-theme="light"]
        .shopify-products-table tbody tr:hover td{
            background:#f5f3ff !important;
            color:#0f172a !important;
        }

        html[data-pos-theme="light"] .product-name-text{
            color:#0f172a !important;
        }

        html[data-pos-theme="light"] .product-secondary{
            color:#94a3b8;
        }

        html[data-pos-theme="light"] .product-id-badge{
            color:#6d28d9;
            background:#f3efff;
            border-color:#ddd6fe;
        }

        html[data-pos-theme="light"] .sku-value{
            color:#0369a1;
            background:#f0f9ff;
            border-color:#bae6fd;
        }

        html[data-pos-theme="light"] .stock-value{
            color:#334155;
        }

        html[data-pos-theme="light"]
        .shopify-table-wrap::-webkit-scrollbar-track{
            background:#eef2f7;
        }


        /* =====================================================
           PRODUCT PUSH ACTIONS
        ===================================================== */

        .shopify-page-message{
            display:none;
            align-items:flex-start;
            gap:10px;
            margin-bottom:14px;
            padding:12px 14px;
            border-radius:13px;
            font-size:12px;
            font-weight:850;
            line-height:1.5;
        }

        .shopify-page-message.is-success{
            display:flex;
            color:#86efac;
            background:rgba(34,197,94,.12);
            border:1px solid rgba(34,197,94,.28);
        }

        .shopify-page-message.is-error{
            display:flex;
            color:#fca5a5;
            background:rgba(239,68,68,.12);
            border:1px solid rgba(239,68,68,.28);
        }

        .shopify-connection-warning{
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom:14px;
            padding:12px 14px;
            border-radius:13px;
            color:#fcd34d;
            background:rgba(245,158,11,.12);
            border:1px solid rgba(245,158,11,.28);
            font-size:12px;
            font-weight:850;
        }

        .shopify-bulk-actions{
            display:flex;
            align-items:center;
            gap:9px;
            flex-wrap:wrap;
        }

        .shopify-push-selected-btn,
        .shopify-push-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            border:0;
            color:#ffffff;
            font-weight:900;
            background:
                linear-gradient(
                    135deg,
                    #008060,
                    #00a47c
                );
            box-shadow:
                0 10px 22px rgba(0,128,96,.22);
            transition:.18s ease;
        }

        .shopify-push-selected-btn{
            min-height:42px;
            padding:0 14px;
            border-radius:11px;
            font-size:12px;
        }

        .shopify-push-btn{
            min-height:32px;
            padding:0 11px;
            border-radius:9px;
            font-size:10px;
            white-space:nowrap;
        }

        .shopify-push-selected-btn:hover,
        .shopify-push-btn:hover{
            color:#ffffff;
            transform:translateY(-1px);
        }

        .shopify-push-selected-btn:disabled,
        .shopify-push-btn:disabled{
            opacity:.48;
            cursor:not-allowed;
            transform:none;
        }

        .shopify-selected-count{
            display:inline-flex;
            align-items:center;
            gap:6px;
            min-height:38px;
            padding:0 10px;
            border-radius:10px;
            color:#c4b5fd;
            background:rgba(124,58,237,.12);
            border:1px solid rgba(124,58,237,.22);
            font-size:11px;
            font-weight:900;
            white-space:nowrap;
        }

        .shopify-row-checkbox,
        .shopify-select-all{
            width:17px;
            height:17px;
            cursor:pointer;
            accent-color:#7c3aed;
        }

        .shopify-action-cell{
            white-space:nowrap;
        }

        .shopify-view-link{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:32px;
            padding:0 10px;
            margin-left:6px;
            border-radius:9px;
            color:#c4b5fd;
            border:1px solid rgba(124,58,237,.24);
            text-decoration:none;
            font-size:10px;
            font-weight:900;
        }

        .shopify-view-link:hover{
            color:#ffffff;
            background:rgba(124,58,237,.14);
        }

        html[data-pos-theme="light"] .shopify-page-message.is-success{
            color:#166534;
            background:#f0fdf4;
            border-color:#86efac;
        }

        html[data-pos-theme="light"] .shopify-page-message.is-error{
            color:#9f1239;
            background:#fff1f2;
            border-color:#fda4af;
        }

        html[data-pos-theme="light"] .shopify-connection-warning{
            color:#92400e;
            background:#fffbeb;
            border-color:#fde68a;
        }

        html[data-pos-theme="light"] .shopify-selected-count{
            color:#6d28d9;
            background:#f3efff;
            border-color:#ddd6fe;
        }

        html[data-pos-theme="light"] .shopify-view-link{
            color:#6d28d9;
            border-color:#ddd6fe;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media(max-width:1199.98px){
            .shopify-summary-grid{
                grid-template-columns:
                    repeat(2, minmax(0,1fr));
            }
        }

        @media(max-width:767.98px){
            body.shopify-products-page .content-wrapper{
                padding:
                    86px
                    14px
                    20px !important;
            }

            .shopify-page-header{
                padding:16px;
            }

            .shopify-title-icon{
                width:46px;
                min-width:46px;
                height:46px;
                font-size:21px;
            }

            .shopify-page-header h3{
                font-size:21px;
            }

            .shopify-summary-grid{
                grid-template-columns:1fr;
            }

            .shopify-summary-card{
                min-height:112px;
            }

            .shopify-toolbar{
                align-items:stretch;
            }

            .shopify-search-wrap,
            .shopify-status-filter,
            .table-result-count{
                width:100%;
                flex-basis:100%;
            }

            .table-result-count{
                justify-content:center;
            }
        }
    </style>
</head>

<body class="shopify-products-page">

<div class="container-scroll">

    <div
        class="sidebar"
        id="dashboardSidebar"
    >
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">

        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">

            <div class="shopify-page-header">

                <div class="shopify-title-group">

                    <div class="shopify-title-icon">
                        <i class="bi bi-bag-check-fill"></i>
                    </div>

                    <div>
                        <h3>
                            Shopify Product Sync
                        </h3>

                        <div class="shopify-page-subtitle">
                            Review ONEERP products and their Shopify synchronization status.
                        </div>
                    </div>

                </div>

                <div class="foundation-badge">
                    <i class="bi <?= $shopifyConnectionReady
                        ? 'bi-shield-check'
                        : 'bi-shield-exclamation'; ?>"></i>

                    <?= $shopifyConnectionReady
                        ? 'Shopify store active'
                        : 'Store connection required'; ?>
                </div>

            </div>

            <div
                id="shopifyPageMessage"
                class="shopify-page-message"
                role="alert"
                aria-live="polite"
            ></div>

            <?php if (!$shopifyConnectionReady): ?>
                <div class="shopify-connection-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Connect and activate a Shopify store before pushing products.
                </div>
            <?php endif; ?>

            <div class="shopify-summary-grid">

                <div class="shopify-summary-card summary-total">

                    <div class="summary-card-top">
                        <span class="summary-card-label">
                            Total Products
                        </span>

                        <span class="summary-card-icon">
                            <i class="bi bi-box-seam"></i>
                        </span>
                    </div>

                    <div class="summary-card-value">
                        <?= number_format($totalItems) ?>
                    </div>

                    <div class="summary-card-note">
                        Loaded from ONEERP
                    </div>

                </div>

                <div class="shopify-summary-card summary-synced">

                    <div class="summary-card-top">
                        <span class="summary-card-label">
                            Synced
                        </span>

                        <span class="summary-card-icon">
                            <i class="bi bi-check-circle"></i>
                        </span>
                    </div>

                    <div class="summary-card-value">
                        <?= number_format($syncedItems) ?>
                    </div>

                    <div class="summary-card-note">
                        Successfully mapped
                    </div>

                </div>

                <div class="shopify-summary-card summary-pending">

                    <div class="summary-card-top">
                        <span class="summary-card-label">
                            Pending
                        </span>

                        <span class="summary-card-icon">
                            <i class="bi bi-clock-history"></i>
                        </span>
                    </div>

                    <div class="summary-card-value">
                        <?= number_format($pendingItems) ?>
                    </div>

                    <div class="summary-card-note">
                        Waiting for synchronization
                    </div>

                </div>

                <div class="shopify-summary-card summary-failed">

                    <div class="summary-card-top">
                        <span class="summary-card-label">
                            Failed
                        </span>

                        <span class="summary-card-icon">
                            <i class="bi bi-exclamation-triangle"></i>
                        </span>
                    </div>

                    <div class="summary-card-value">
                        <?= number_format($failedItems) ?>
                    </div>

                    <div class="summary-card-note">
                        Needs attention
                    </div>

                </div>

            </div>

            <div class="shopify-toolbar">

                <div class="shopify-search-wrap">
                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        id="shopifyProductSearch"
                        class="form-control shopify-search-input"
                        placeholder="Search product, SKU or ID..."
                        autocomplete="off"
                    >
                </div>

                <select
                    id="shopifyStatusFilter"
                    class="form-select shopify-status-filter"
                >
                    <option value="">
                        All sync statuses
                    </option>

                    <option value="synced">
                        Synced
                    </option>

                    <option value="pending">
                        Pending
                    </option>

                    <option value="syncing">
                        Syncing
                    </option>

                    <option value="failed">
                        Failed
                    </option>

                    <option value="deleted">
                        Deleted
                    </option>

                    <option value="not-synced">
                        Not synced
                    </option>
                </select>

                <div class="shopify-bulk-actions">

                    <button
                        type="button"
                        id="pushSelectedProductsBtn"
                        class="shopify-push-selected-btn"
                        <?= !$shopifyConnectionReady
                            ? 'disabled'
                            : ''; ?>
                    >
                        <i class="bi bi-cloud-arrow-up-fill"></i>
                        Push Selected
                    </button>

                    <div class="shopify-selected-count">
                        <i class="bi bi-check2-square"></i>
                        <span id="selectedProductCount">0</span>
                        selected
                    </div>

                </div>

                <div class="table-result-count">
                    <i class="bi bi-list-check"></i>

                    <span id="visibleProductCount">
                        <?= number_format($totalItems) ?>
                    </span>

                    products visible
                </div>

            </div>

            <div class="shopify-panel">

                <div class="shopify-table-head">

                    <div class="shopify-table-title">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                        Product Synchronization
                    </div>

                    <div class="latest-sync-text">
                        Last sync:
                        <strong>
                            <?php if ($latestSyncAt): ?>
                                <?= htmlspecialchars(
                                    date(
                                        'd M Y, h:i A',
                                        strtotime($latestSyncAt)
                                    )
                                ) ?>
                            <?php else: ?>
                                Not available
                            <?php endif; ?>
                        </strong>
                    </div>

                </div>

                <div class="shopify-table-wrap">

                    <table
                        class="table align-middle shopify-products-table"
                        id="shopifyProductsTable"
                    >

                        <thead>
                            <tr>
                                <th style="width:52px;">
                                    <input
                                        type="checkbox"
                                        id="selectAllShopifyProducts"
                                        class="shopify-select-all"
                                        aria-label="Select all visible products"
                                    >
                                </th>

                                <th style="width:90px;">
                                    ID
                                </th>

                                <th>
                                    Product
                                </th>

                                <th style="width:170px;">
                                    SKU
                                </th>

                                <th style="width:150px;">
                                    Price
                                </th>

                                <th style="width:130px;">
                                    Stock
                                </th>

                                <th style="width:190px;">
                                    Shopify Status
                                </th>

                                <th style="width:190px;">
                                    Actions
                                </th>
                            </tr>
                        </thead>

                        <tbody id="shopifyProductsBody">

                        <?php if (!$items): ?>

                            <tr>
                                <td
                                    colspan="8"
                                    class="shopify-empty-state"
                                >
                                    <div class="shopify-empty-icon">
                                        <i class="bi bi-cloud-slash"></i>
                                    </div>

                                    <div class="shopify-empty-title">
                                        No Shopify product data available
                                    </div>

                                    <div class="shopify-empty-text">
                                        Run the Shopify SQL migration to load product synchronization status.
                                    </div>
                                </td>
                            </tr>

                        <?php endif; ?>

                        <?php foreach ($items as $item): ?>

                            <?php
                                $statusMeta =
                                    shopifyProductStatusMeta(
                                        $item['sync_status']
                                            ?? ''
                                    );

                                $rawStatus = strtolower(
                                    trim(
                                        (string)(
                                            $item['sync_status']
                                                ?? ''
                                        )
                                    )
                                );

                                $filterStatus =
                                    $rawStatus !== ''
                                        ? $rawStatus
                                        : 'not-synced';

                                $productId =
                                    (int)($item['id'] ?? 0);

                                $productName =
                                    trim(
                                        (string)(
                                            $item['name']
                                                ?? ''
                                        )
                                    );

                                $productSku =
                                    trim(
                                        (string)(
                                            $item['sku']
                                                ?? ''
                                        )
                                    );

                                $stockQty =
                                    (float)(
                                        $item['stock_qty']
                                            ?? 0
                                    );

                                $lastError =
                                    trim(
                                        (string)(
                                            $item['last_error']
                                                ?? ''
                                        )
                                    );

                                $lastSyncedAt =
                                    trim(
                                        (string)(
                                            $item['last_synced_at']
                                                ?? ''
                                        )
                                    );

                                $searchText = strtolower(
                                    implode(
                                        ' ',
                                        [
                                            $productId,
                                            $productName,
                                            $productSku,
                                            $statusMeta['label'],
                                        ]
                                    )
                                );
                            ?>

                            <tr
                                class="shopify-product-row"
                                data-item-id="<?= $productId ?>"
                                data-search="<?= htmlspecialchars(
                                    $searchText,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                data-status="<?= htmlspecialchars(
                                    $filterStatus,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >

                                <td>
                                    <input
                                        type="checkbox"
                                        class="shopify-row-checkbox"
                                        value="<?= $productId ?>"
                                        aria-label="Select <?= htmlspecialchars(
                                            $productName,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
                                </td>

                                <td>
                                    <span class="product-id-badge">
                                        #<?= $productId ?>
                                    </span>
                                </td>

                                <td class="product-name-cell">
                                    <span
                                        class="product-name-text"
                                        title="<?= htmlspecialchars(
                                            $productName,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $productName
                                        ) ?>
                                    </span>

                                    <div class="product-secondary">
                                        ONEERP product
                                    </div>
                                </td>

                                <td>
                                    <?php if ($productSku !== ''): ?>

                                        <span class="sku-value">
                                            <?= htmlspecialchars(
                                                $productSku
                                            ) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="sku-empty">
                                            No SKU
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="money-value">
                                        PKR
                                        <?= number_format(
                                            (float)($item['price'] ?? 0),
                                            2
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="stock-value <?= $stockQty <= 0
                                        ? 'stock-zero'
                                        : ''; ?>"
                                    >
                                        <span class="stock-dot"></span>

                                        <?= number_format(
                                            $stockQty,
                                            2
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="sync-badge <?= htmlspecialchars(
                                        $statusMeta['class']
                                    ) ?>">
                                        <i class="bi <?= htmlspecialchars(
                                            $statusMeta['icon']
                                        ) ?>"></i>

                                        <?= htmlspecialchars(
                                            $statusMeta['label']
                                        ) ?>
                                    </span>

                                    <?php if ($lastError !== ''): ?>

                                        <span
                                            class="sync-error-text"
                                            title="<?= htmlspecialchars(
                                                $lastError,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >
                                            <?= htmlspecialchars(
                                                $lastError
                                            ) ?>
                                        </span>

                                    <?php elseif ($lastSyncedAt !== ''): ?>

                                        <div class="product-secondary">
                                            <?= htmlspecialchars(
                                                date(
                                                    'd M Y, h:i A',
                                                    strtotime($lastSyncedAt)
                                                )
                                            ) ?>
                                        </div>

                                    <?php endif; ?>
                                </td>

                                <td class="shopify-action-cell">
                                    <button
                                        type="button"
                                        class="shopify-push-btn"
                                        data-item-id="<?= $productId ?>"
                                        <?= !$shopifyConnectionReady
                                            ? 'disabled'
                                            : ''; ?>
                                    >
                                        <i class="bi bi-cloud-arrow-up-fill"></i>

                                        <?= $filterStatus === 'synced'
                                            ? 'Update'
                                            : (
                                                $filterStatus === 'failed'
                                                    ? 'Retry'
                                                    : 'Push'
                                            ); ?>
                                    </button>

                                    <?php if (
                                        !empty($item['shopify_product_gid'])
                                    ): ?>
                                        <?php
                                            $shopifyProductNumericId =
                                                preg_replace(
                                                    '/\D+/',
                                                    '',
                                                    (string)$item['shopify_product_gid']
                                                );
                                        ?>

                                        <a
                                            class="shopify-view-link"
                                            href="https://<?= htmlspecialchars(
                                                (string)($activeStore['shop_domain'] ?? ''),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>/admin/products/<?= htmlspecialchars(
                                                $shopifyProductNumericId,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <i class="bi bi-box-arrow-up-right"></i>
                                            View
                                        </a>
                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                    <?php if ($items): ?>

                        <div
                            id="shopifyNoSearchResults"
                            class="shopify-no-search-results"
                        >
                            <i class="bi bi-search me-2"></i>
                            No products match the selected search or status.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        const searchInput =
            document.getElementById(
                'shopifyProductSearch'
            );

        const statusFilter =
            document.getElementById(
                'shopifyStatusFilter'
            );

        const rows = Array.from(
            document.querySelectorAll(
                '.shopify-product-row'
            )
        );

        const visibleCount =
            document.getElementById(
                'visibleProductCount'
            );

        const noResults =
            document.getElementById(
                'shopifyNoSearchResults'
            );

        function applyProductFilters() {
            const searchValue = String(
                searchInput?.value || ''
            )
                .trim()
                .toLowerCase();

            const statusValue = String(
                statusFilter?.value || ''
            )
                .trim()
                .toLowerCase();

            let visibleRows = 0;

            rows.forEach(function (row) {
                const rowSearch = String(
                    row.dataset.search || ''
                ).toLowerCase();

                const rowStatus = String(
                    row.dataset.status || ''
                ).toLowerCase();

                const matchesSearch =
                    searchValue === '' ||
                    rowSearch.includes(
                        searchValue
                    );

                const matchesStatus =
                    statusValue === '' ||
                    rowStatus === statusValue;

                const shouldShow =
                    matchesSearch &&
                    matchesStatus;

                row.hidden = !shouldShow;

                if (shouldShow) {
                    visibleRows++;
                }
            });

            if (visibleCount) {
                visibleCount.textContent =
                    visibleRows.toLocaleString();
            }

            if (noResults) {
                noResults.style.display =
                    visibleRows === 0
                        ? 'block'
                        : 'none';
            }

            updateSelectionState();
        }

        searchInput?.addEventListener(
            'input',
            applyProductFilters
        );

        statusFilter?.addEventListener(
            'change',
            applyProductFilters
        );

        const csrfToken = <?= json_encode(
            $shopifyProductCsrf,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ) ?>;

        const selectAll =
            document.getElementById(
                'selectAllShopifyProducts'
            );

        const rowCheckboxes = Array.from(
            document.querySelectorAll(
                '.shopify-row-checkbox'
            )
        );

        const pushSelectedButton =
            document.getElementById(
                'pushSelectedProductsBtn'
            );

        const selectedCount =
            document.getElementById(
                'selectedProductCount'
            );

        const pageMessage =
            document.getElementById(
                'shopifyPageMessage'
            );

        function visibleCheckboxes() {
            return rowCheckboxes.filter(
                function (checkbox) {
                    const row =
                        checkbox.closest(
                            '.shopify-product-row'
                        );

                    return row && !row.hidden;
                }
            );
        }

        function selectedIds() {
            return rowCheckboxes
                .filter(function (checkbox) {
                    return checkbox.checked;
                })
                .map(function (checkbox) {
                    return parseInt(
                        checkbox.value,
                        10
                    );
                })
                .filter(function (id) {
                    return Number.isInteger(id) &&
                        id > 0;
                });
        }

        function updateSelectionState() {
            const visible =
                visibleCheckboxes();

            const checkedVisible =
                visible.filter(function (checkbox) {
                    return checkbox.checked;
                });

            const totalSelected =
                selectedIds().length;

            if (selectedCount) {
                selectedCount.textContent =
                    totalSelected.toLocaleString();
            }

            if (selectAll) {
                selectAll.checked =
                    visible.length > 0 &&
                    checkedVisible.length ===
                        visible.length;

                selectAll.indeterminate =
                    checkedVisible.length > 0 &&
                    checkedVisible.length <
                        visible.length;
            }
        }

        function showPageMessage(
            message,
            type
        ) {
            if (!pageMessage) {
                return;
            }

            pageMessage.className =
                'shopify-page-message ' +
                (
                    type === 'success'
                        ? 'is-success'
                        : 'is-error'
                );

            pageMessage.replaceChildren();

            const icon =
                document.createElement('i');

            icon.className =
                type === 'success'
                    ? 'bi bi-check-circle-fill'
                    : 'bi bi-exclamation-triangle-fill';

            const text =
                document.createElement('span');

            text.textContent =
                message ||
                'Shopify request failed.';

            pageMessage.append(
                icon,
                text
            );

            pageMessage.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        async function shopifyProductRequest(
            action,
            payload
        ) {
            const body =
                new URLSearchParams();

            body.set(
                'action',
                action
            );

            body.set(
                'csrf_token',
                csrfToken
            );

            Object.entries(
                payload || {}
            ).forEach(function (entry) {
                const key = entry[0];
                const value = entry[1];

                if (Array.isArray(value)) {
                    value.forEach(
                        function (item) {
                            body.append(
                                key + '[]',
                                String(item)
                            );
                        }
                    );

                    return;
                }

                body.set(
                    key,
                    String(value)
                );
            });

            const response = await fetch(
                'ajax_shopify_products.php',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body
                }
            );

            const responseText =
                await response.text();

            let data;

            try {
                data = JSON.parse(
                    responseText
                );
            } catch (error) {
                console.error(
                    'Shopify raw response:',
                    responseText
                );

                throw new Error(
                    'Server returned invalid JSON.'
                );
            }

            if (
                !response.ok ||
                !data.success
            ) {
                throw new Error(
                    data.message ||
                    'Shopify request failed.'
                );
            }

            return data;
        }

        selectAll?.addEventListener(
            'change',
            function () {
                visibleCheckboxes().forEach(
                    function (checkbox) {
                        checkbox.checked =
                            selectAll.checked;
                    }
                );

                updateSelectionState();
            }
        );

        rowCheckboxes.forEach(
            function (checkbox) {
                checkbox.addEventListener(
                    'change',
                    updateSelectionState
                );
            }
        );

        document
            .querySelectorAll(
                '.shopify-push-btn'
            )
            .forEach(function (button) {
                button.addEventListener(
                    'click',
                    async function () {
                        const itemId = parseInt(
                            this.dataset.itemId || '0',
                            10
                        );

                        if (itemId <= 0) {
                            showPageMessage(
                                'Invalid ONEERP product.',
                                'error'
                            );

                            return;
                        }

                        const originalHtml =
                            this.innerHTML;

                        try {
                            this.disabled = true;

                            this.innerHTML =
                                '<span class="spinner-border spinner-border-sm"></span>' +
                                ' Syncing';

                            const data =
                                await shopifyProductRequest(
                                    'push_product',
                                    {
                                        item_id: itemId
                                    }
                                );

                            showPageMessage(
                                data.message,
                                'success'
                            );

                            window.setTimeout(
                                function () {
                                    window.location.reload();
                                },
                                700
                            );
                        } catch (error) {
                            showPageMessage(
                                error.message,
                                'error'
                            );

                            this.disabled = false;
                            this.innerHTML =
                                originalHtml;
                        }
                    }
                );
            });

        pushSelectedButton?.addEventListener(
            'click',
            async function () {
                const ids = selectedIds();

                if (ids.length === 0) {
                    showPageMessage(
                        'Select at least one product.',
                        'error'
                    );

                    return;
                }

                if (ids.length > 20) {
                    showPageMessage(
                        'Push a maximum of 20 products in one request.',
                        'error'
                    );

                    return;
                }

                const originalHtml =
                    this.innerHTML;

                try {
                    this.disabled = true;

                    this.innerHTML =
                        '<span class="spinner-border spinner-border-sm"></span>' +
                        ' Syncing ' +
                        ids.length;

                    const data =
                        await shopifyProductRequest(
                            'bulk_push_products',
                            {
                                item_ids: ids
                            }
                        );

                    showPageMessage(
                        data.message,
                        data.failed_count > 0
                            ? 'error'
                            : 'success'
                    );

                    window.setTimeout(
                        function () {
                            window.location.reload();
                        },
                        1000
                    );
                } catch (error) {
                    showPageMessage(
                        error.message,
                        'error'
                    );

                    this.disabled = false;
                    this.innerHTML =
                        originalHtml;
                }
            }
        );

        updateSelectionState();
    }
);
</script>

</body>
</html>
