<?php
session_start();

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/shopify.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/integration_helper.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_crypto_helper.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_auth_helper.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_api_helper.php');

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$clientId = (int)($_SESSION['client_id'] ?? 0);

if ($clientId <= 0) {
    http_response_code(400);
    exit('Invalid client session.');
}

requirePlanFeature(
    $conn,
    $clientId,
    'shopify_integration'
);

if (empty($_SESSION['shopify_connect_csrf'])) {
    $_SESSION['shopify_connect_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = (string)$_SESSION['shopify_connect_csrf'];

$migrationReady = oneerpTableExists($conn, 'shopify_stores')
    && oneerpTableExists($conn, 'shopify_oauth_states');

$publicPatchReady = false;

if ($migrationReady) {
    try {
        $columnCheck = $conn->query("
            SHOW COLUMNS FROM shopify_stores
            LIKE 'integration_name'
        ");

        $stateColumnCheck = $conn->query("
            SHOW COLUMNS FROM shopify_oauth_states
            LIKE 'shopify_store_id'
        ");

        $publicPatchReady = (
            $columnCheck &&
            $columnCheck->num_rows > 0 &&
            $stateColumnCheck &&
            $stateColumnCheck->num_rows > 0
        );

        if ($columnCheck instanceof mysqli_result) {
            $columnCheck->free();
        }

        if ($stateColumnCheck instanceof mysqli_result) {
            $stateColumnCheck->free();
        }
    } catch (Throwable $ignore) {
        $publicPatchReady = false;
    }
}

$stores = [];

if ($migrationReady && $publicPatchReady) {
    $stmt = $conn->prepare("
        SELECT
            id,
            integration_name,
            store_label,
            shop_domain,
            shop_name,
            shop_email,
            shop_currency,
            api_version,
            connect_method,
            status,
            last_error,
            connected_at,
            last_sync_at,
            created_at
        FROM shopify_stores
        WHERE client_id = ?
        ORDER BY id DESC
        LIMIT 50
    ");

    if ($stmt) {
        $stmt->bind_param(
            'i',
            $clientId
        );

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $stores[] = $row;
        }

        $stmt->close();
    }
}

$totalIntegrations = count($stores);
$activeIntegrations = 0;
$inactiveIntegrations = 0;
$errorIntegrations = 0;

foreach ($stores as $storeRow) {
    $status = strtolower(
        trim((string)($storeRow['status'] ?? ''))
    );

    if ($status === 'active') {
        $activeIntegrations++;
    } elseif ($status === 'error' || $status === 'expired' || $status === 'revoked') {
        $errorIntegrations++;
    } else {
        $inactiveIntegrations++;
    }
}

$success = trim((string)($_GET['success'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$missingConfig = shopifyMissingConfigKeys();

function shopifyIntegrationStatusMeta($status): array
{
    $status = strtolower(trim((string)$status));

    if ($status === 'active') {
        return [
            'label' => 'Active',
            'class' => 'status-active',
            'icon' => 'bi-check-circle-fill',
        ];
    }

    if ($status === 'connecting') {
        return [
            'label' => 'Connecting',
            'class' => 'status-connecting',
            'icon' => 'bi-arrow-repeat',
        ];
    }

    if ($status === 'error' || $status === 'expired' || $status === 'revoked') {
        return [
            'label' => ucfirst($status),
            'class' => 'status-error',
            'icon' => 'bi-exclamation-triangle-fill',
        ];
    }

    return [
        'label' => 'Inactive',
        'class' => 'status-inactive',
        'icon' => 'bi-pause-circle-fill',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shopify Integrations - ONEERP</title>

    <?php include('partials/head.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root{
            --si-bg:radial-gradient(circle at 10% 0%,rgba(124,58,237,.22),transparent 29%),radial-gradient(circle at 90% 2%,rgba(0,128,96,.11),transparent 28%),linear-gradient(135deg,#050914 0%,#0b1024 45%,#160f2d 100%);
            --si-card:rgba(10,17,34,.84);
            --si-card-2:rgba(15,23,42,.78);
            --si-border:rgba(148,163,184,.15);
            --si-purple:#7c3aed;
            --si-purple-2:#6d4cff;
            --si-green:#008060;
            --si-text:#f8fafc;
            --si-muted:#94a3b8;
            --si-input:rgba(15,23,42,.82);
        }

        html[data-pos-theme="light"]{
            --si-bg:radial-gradient(circle at 10% 0%,rgba(124,58,237,.10),transparent 29%),radial-gradient(circle at 90% 2%,rgba(0,128,96,.06),transparent 28%),linear-gradient(135deg,#f7f8fd,#eef2f8);
            --si-card:rgba(255,255,255,.96);
            --si-card-2:#fff;
            --si-border:#dce3ee;
            --si-text:#0f172a;
            --si-muted:#64748b;
            --si-input:#fff;
        }

        body.shopify-integrations-page{
            min-height:100vh;
            overflow-x:hidden;
            background:var(--si-bg)!important;
            color:var(--si-text)!important;
        }

        body.shopify-integrations-page .main-panel{
            min-height:100vh;
            background:transparent!important;
        }

        body.shopify-integrations-page .content-wrapper{
            min-height:100vh;
            padding:94px 24px 30px!important;
            background:var(--si-bg)!important;
        }

        .si-shell{max-width:1500px;margin:0 auto}

        .si-hero,.si-card,.si-toolbar,.si-table-card{
            border:1px solid var(--si-border);
            background:linear-gradient(135deg,var(--si-card),var(--si-card-2));
            box-shadow:0 20px 52px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.04);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
        }

        html[data-pos-theme="light"] .si-hero,
        html[data-pos-theme="light"] .si-card,
        html[data-pos-theme="light"] .si-toolbar,
        html[data-pos-theme="light"] .si-table-card{
            box-shadow:0 16px 40px rgba(15,23,42,.08),inset 0 1px 0 #fff;
        }

        .si-hero{border-radius:18px;padding:20px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        .si-title-wrap{display:flex;align-items:center;gap:14px}
        .si-title-icon{width:52px;height:52px;border-radius:15px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--si-green),#00a47c);color:#fff;font-size:24px;box-shadow:0 14px 28px rgba(0,128,96,.26)}
        .si-title{margin:0;color:var(--si-text);font-size:27px;font-weight:950;letter-spacing:-.035em}
        .si-subtitle{margin-top:4px;color:var(--si-muted);font-size:12px;font-weight:750}
        .si-add-btn{min-height:44px;border:0;border-radius:12px;padding:0 17px;background:linear-gradient(135deg,var(--si-purple),#2563eb);color:#fff;font-weight:900;box-shadow:0 12px 26px rgba(124,58,237,.28)}
        .si-add-btn:hover{color:#fff;transform:translateY(-1px)}

        .si-alert{border-radius:14px;padding:13px 15px;margin-bottom:14px;font-size:12px;font-weight:800}
        .si-alert-warning{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.34);color:#fcd34d}
        .si-alert-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.30);color:#86efac}
        .si-alert-danger{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.30);color:#fca5a5}
        html[data-pos-theme="light"] .si-alert-warning{background:#fffbeb;color:#92400e;border-color:#fde68a}
        html[data-pos-theme="light"] .si-alert-success{background:#f0fdf4;color:#166534;border-color:#86efac}
        html[data-pos-theme="light"] .si-alert-danger{background:#fff1f2;color:#9f1239;border-color:#fda4af}

        .si-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
        .si-card{border-radius:17px;padding:17px;min-height:112px;position:relative;overflow:hidden}
        .si-card::after{content:"";position:absolute;width:86px;height:86px;border-radius:50%;right:-22px;top:-24px;background:rgba(255,255,255,.06)}
        .si-stat-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
        .si-stat-label{color:var(--si-muted);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.05em}
        .si-stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px}
        .si-purple{background:linear-gradient(135deg,#7c3aed,#6d4cff)}
        .si-green{background:linear-gradient(135deg,#059669,#10b981)}
        .si-orange{background:linear-gradient(135deg,#d97706,#f59e0b)}
        .si-blue{background:linear-gradient(135deg,#0284c7,#2563eb)}
        .si-stat-value{margin-top:10px;color:var(--si-text);font-size:27px;font-weight:950}
        .si-stat-note{margin-top:3px;color:var(--si-muted);font-size:10px;font-weight:700}

        .si-toolbar{border-radius:15px;padding:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
        .si-search-wrap{position:relative;flex:1 1 360px}
        .si-search-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--si-muted)}
        .si-search,.si-filter{min-height:43px;border-radius:11px!important;background:var(--si-input)!important;color:var(--si-text)!important;border:1px solid var(--si-border)!important;font-size:12px;font-weight:800}
        .si-search{padding-left:39px!important}
        .si-search::placeholder{color:var(--si-muted)}
        .si-filter{width:180px}
        .si-filter option{background:#0f172a;color:#fff}
        html[data-pos-theme="light"] .si-filter option{background:#fff;color:#0f172a}
        .si-reset-btn{min-height:43px;border-radius:11px;border:1px solid var(--si-border);background:transparent;color:var(--si-text);font-weight:850;padding:0 14px}

        .si-table-card{border-radius:18px;overflow:hidden}
        .si-table-wrap{overflow:auto}
        .si-table{min-width:1050px;width:100%;margin:0!important;--bs-table-bg:transparent;--bs-table-color:var(--si-text);--bs-table-border-color:var(--si-border)}
        .si-table thead th{padding:14px 15px;background:rgba(5,10,22,.94)!important;color:#cbd5e1!important;border:0!important;border-bottom:1px solid var(--si-border)!important;font-size:10px;font-weight:950;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
        html[data-pos-theme="light"] .si-table thead th{background:#f1f4f9!important;color:#475569!important}
        .si-table tbody td{padding:13px 15px;background:rgba(15,23,42,.22)!important;color:var(--si-text)!important;border:0!important;border-bottom:1px solid var(--si-border)!important;font-size:12px;vertical-align:middle}
        html[data-pos-theme="light"] .si-table tbody td{background:#fff!important;color:#334155!important}
        .si-table tbody tr:hover td{background:rgba(124,58,237,.09)!important}
        html[data-pos-theme="light"] .si-table tbody tr:hover td{background:#f5f3ff!important}
        .si-integration-name{font-weight:950;color:var(--si-text)}
        .si-type{margin-top:3px;color:var(--si-muted);font-size:10px;font-weight:700}
        .si-domain{color:#93c5fd;font-weight:850;text-decoration:none}
        html[data-pos-theme="light"] .si-domain{color:#1d4ed8}
        .si-badge{display:inline-flex;align-items:center;gap:6px;min-height:29px;padding:0 9px;border-radius:999px;font-size:10px;font-weight:950;white-space:nowrap}
        .status-active{background:rgba(34,197,94,.13);border:1px solid rgba(34,197,94,.28);color:#86efac}
        .status-connecting{background:rgba(124,58,237,.14);border:1px solid rgba(124,58,237,.28);color:#c4b5fd}
        .status-error{background:rgba(239,68,68,.13);border:1px solid rgba(239,68,68,.28);color:#fca5a5}
        .status-inactive{background:rgba(100,116,139,.12);border:1px solid rgba(100,116,139,.24);color:#cbd5e1}
        html[data-pos-theme="light"] .status-active{background:#dcfce7;color:#166534;border-color:#86efac}
        html[data-pos-theme="light"] .status-connecting{background:#f3efff;color:#6d28d9;border-color:#c4b5fd}
        html[data-pos-theme="light"] .status-error{background:#ffe4e6;color:#be123c;border-color:#fda4af}
        html[data-pos-theme="light"] .status-inactive{background:#f1f5f9;color:#475569;border-color:#cbd5e1}
        .si-action-btn{min-height:32px;border-radius:9px;padding:0 10px;font-size:10px;font-weight:900;border:1px solid var(--si-border);background:transparent;color:var(--si-text)}
        .si-action-btn:hover{background:rgba(124,58,237,.12);color:var(--si-text)}
        .si-action-danger{color:#fca5a5;border-color:rgba(239,68,68,.28)}
        .si-empty{padding:52px 20px!important;text-align:center;color:var(--si-muted)!important}

        .modal-backdrop.show{opacity:.76;background:#020617}
        .si-modal .modal-dialog{max-width:880px}
        .si-modal .modal-content{border-radius:18px;overflow:hidden;border:1px solid rgba(139,92,246,.30);background:linear-gradient(145deg,#0c1528,#0a1020);color:#fff;box-shadow:0 30px 90px rgba(0,0,0,.62)}
        html[data-pos-theme="light"] .si-modal .modal-content{background:#fff;color:#0f172a;border-color:#ddd6fe}
        .si-modal .modal-header{padding:17px 20px;border-color:rgba(148,163,184,.14);background:rgba(15,23,42,.38)}
        html[data-pos-theme="light"] .si-modal .modal-header{background:#fbfcff;border-color:#e5e7eb}
        .si-modal-title{font-size:20px;font-weight:950;margin:0}
        .si-modal-subtitle{color:#94a3b8;font-size:11px;font-weight:700;margin-top:2px}
        .si-modal .btn-close{filter:invert(1)}
        html[data-pos-theme="light"] .si-modal .btn-close{filter:none}
        .si-modal .modal-body{padding:20px}
        .si-modal .modal-footer{padding:14px 20px;border-color:rgba(148,163,184,.14);background:rgba(7,11,20,.55)}
        html[data-pos-theme="light"] .si-modal .modal-footer{background:#fbfcff;border-color:#e5e7eb}
        .si-label{color:inherit;font-size:11px;font-weight:900;margin-bottom:7px}
        .si-input{min-height:45px;border-radius:11px!important;background:rgba(15,23,42,.82)!important;color:#fff!important;border:1px solid rgba(148,163,184,.20)!important;font-size:12px;font-weight:800}
        html[data-pos-theme="light"] .si-input{background:#fff!important;color:#0f172a!important;border-color:#dbe2ec!important}
        .si-input:focus{border-color:#7c3aed!important;box-shadow:0 0 0 3px rgba(124,58,237,.15)!important}
        .si-help{margin-top:6px;color:#94a3b8;font-size:10px;font-weight:700}
        .si-info-box{padding:12px 13px;border-radius:12px;background:rgba(14,165,233,.10);border:1px solid rgba(14,165,233,.25);color:#bae6fd;font-size:10px;font-weight:750;line-height:1.55}
        html[data-pos-theme="light"] .si-info-box{background:#f0f9ff;color:#075985;border-color:#bae6fd}
        .si-connect-btn{min-height:44px;border:0;border-radius:11px;padding:0 22px;background:linear-gradient(135deg,#7c3aed,#2563eb);color:#fff;font-weight:950;min-width:220px}
        .si-connect-btn:disabled{opacity:.42;cursor:not-allowed}
        .si-cancel-btn{min-height:44px;border-radius:11px;padding:0 18px;border:1px solid rgba(148,163,184,.22);background:transparent;color:inherit;font-weight:900}

        @media(max-width:1199px){.si-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:767px){body.shopify-integrations-page .content-wrapper{padding:86px 14px 22px!important}.si-stats{grid-template-columns:1fr}.si-search-wrap,.si-filter,.si-reset-btn{width:100%;flex-basis:100%}.si-add-btn{width:100%}.si-modal .modal-dialog{margin:10px}.si-connect-btn,.si-cancel-btn{width:100%}.si-modal .modal-footer{flex-direction:column-reverse}}
    </style>
</head>
<body class="shopify-integrations-page">
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">
            <div class="si-shell">
                <div class="si-hero">
                    <div class="si-title-wrap">
                        <div class="si-title-icon"><i class="bi bi-bag-check-fill"></i></div>
                        <div>
                            <h2 class="si-title">Shopify Integrations</h2>
                            <div class="si-subtitle">Manage Public App connections for ONEERP clients.</div>
                        </div>
                    </div>

                    <button type="button" class="si-add-btn" data-bs-toggle="modal" data-bs-target="#shopifyConnectModal">
                        <i class="bi bi-plus-lg me-2"></i>Add Integration
                    </button>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="si-alert si-alert-success"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="si-alert si-alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if (!$migrationReady): ?>
                    <div class="si-alert si-alert-warning"><i class="bi bi-database-exclamation me-2"></i>Run the original Shopify migration first.</div>
                <?php elseif (!$publicPatchReady): ?>
                    <div class="si-alert si-alert-warning"><i class="bi bi-database-gear me-2"></i>Run <strong>shopify_public_connection_patch.sql</strong> before using the popup connection.</div>
                <?php endif; ?>

                <?php if (!shopifyConfigReady()): ?>
                    <div class="si-alert si-alert-warning">
                        <i class="bi bi-gear-fill me-2"></i>
                        Global Public App configuration is incomplete. Missing:
                        <strong><?= htmlspecialchars(implode(', ', $missingConfig)) ?></strong>.
                    </div>
                <?php endif; ?>

                <div class="si-stats">
                    <div class="si-card">
                        <div class="si-stat-top"><span class="si-stat-label">Total Integrations</span><span class="si-stat-icon si-purple"><i class="bi bi-boxes"></i></span></div>
                        <div class="si-stat-value"><?= number_format($totalIntegrations) ?></div>
                        <div class="si-stat-note">All Shopify connections</div>
                    </div>
                    <div class="si-card">
                        <div class="si-stat-top"><span class="si-stat-label">Active Integrations</span><span class="si-stat-icon si-green"><i class="bi bi-diagram-3"></i></span></div>
                        <div class="si-stat-value"><?= number_format($activeIntegrations) ?></div>
                        <div class="si-stat-note">Currently connected</div>
                    </div>
                    <div class="si-card">
                        <div class="si-stat-top"><span class="si-stat-label">Inactive Integrations</span><span class="si-stat-icon si-orange"><i class="bi bi-pause-circle"></i></span></div>
                        <div class="si-stat-value"><?= number_format($inactiveIntegrations) ?></div>
                        <div class="si-stat-note">Connecting or disconnected</div>
                    </div>
                    <div class="si-card">
                        <div class="si-stat-top"><span class="si-stat-label">Error Integrations</span><span class="si-stat-icon si-blue"><i class="bi bi-gear-wide-connected"></i></span></div>
                        <div class="si-stat-value"><?= number_format($errorIntegrations) ?></div>
                        <div class="si-stat-note">Needs attention</div>
                    </div>
                </div>

                <div class="si-toolbar">
                    <div class="si-search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="search" id="integrationSearch" class="form-control si-search" placeholder="Search by integration, store or domain...">
                    </div>
                    <select id="integrationStatusFilter" class="form-select si-filter">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="connecting">Connecting</option>
                        <option value="inactive">Inactive</option>
                        <option value="error">Error</option>
                    </select>
                    <button type="button" class="si-reset-btn" id="resetIntegrationFilters"><i class="bi bi-arrow-counterclockwise me-2"></i>Reset</button>
                </div>

                <div class="si-table-card">
                    <div class="si-table-wrap">
                        <table class="table si-table align-middle">
                            <thead>
                            <tr>
                                <th>Integration</th>
                                <th>Connected To</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>API Version</th>
                                <th>Last Sync</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody id="integrationRows">
                            <?php if (!$stores): ?>
                                <tr><td colspan="7" class="si-empty"><i class="bi bi-plug d-block fs-2 mb-2"></i>No Shopify integrations yet. Click <strong>Add Integration</strong> to connect a store.</td></tr>
                            <?php endif; ?>

                            <?php foreach ($stores as $store): ?>
                                <?php
                                $meta = shopifyIntegrationStatusMeta($store['status'] ?? '');
                                $statusKey = strtolower(trim((string)($store['status'] ?? 'inactive')));
                                if (!in_array($statusKey, ['active','connecting','error'], true)) {
                                    $statusKey = 'inactive';
                                }
                                $searchText = strtolower(implode(' ', [
                                    $store['integration_name'] ?? '',
                                    $store['store_label'] ?? '',
                                    $store['shop_name'] ?? '',
                                    $store['shop_domain'] ?? '',
                                    $store['status'] ?? '',
                                ]));
                                ?>
                                <tr class="integration-row" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>" data-status="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <td>
                                        <div class="si-integration-name"><?= htmlspecialchars((string)($store['integration_name'] ?? 'Shopify Integration')) ?></div>
                                        <div class="si-type">Shopify · E-commerce</div>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars((string)($store['store_label'] ?? $store['shop_name'] ?? '-')) ?></div>
                                        <a class="si-domain" href="https://<?= htmlspecialchars((string)$store['shop_domain']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$store['shop_domain']) ?></a>
                                    </td>
                                    <td><span class="si-badge status-connecting"><i class="bi bi-globe2"></i>Public App</span></td>
                                    <td>
                                        <span class="si-badge <?= htmlspecialchars($meta['class']) ?>"><i class="bi <?= htmlspecialchars($meta['icon']) ?>"></i><?= htmlspecialchars($meta['label']) ?></span>
                                        <?php if (!empty($store['last_error'])): ?><div class="si-type text-danger mt-1" title="<?= htmlspecialchars((string)$store['last_error'], ENT_QUOTES, 'UTF-8') ?>">Connection error available</div><?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($store['api_version'] ?? SHOPIFY_API_VERSION)) ?></td>
                                    <td>
                                        <?php if (!empty($store['last_sync_at'])): ?>
                                            <div class="fw-bold"><?= htmlspecialchars(date('d M Y', strtotime((string)$store['last_sync_at']))) ?></div>
                                            <div class="si-type"><?= htmlspecialchars(date('h:i A', strtotime((string)$store['last_sync_at']))) ?></div>
                                        <?php else: ?>
                                            <span class="si-type">Not synced</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <?php if (($store['status'] ?? '') === 'active'): ?>
                                                <button type="button" class="si-action-btn test-connection-btn" data-store-id="<?= (int)$store['id'] ?>"><i class="bi bi-eye me-1"></i>Test</button>
                                            <?php endif; ?>

                                            <button
                                                type="button"
                                                class="si-action-btn reconnect-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#shopifyConnectModal"
                                                data-integration-name="<?= htmlspecialchars((string)($store['integration_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-store-name="<?= htmlspecialchars((string)($store['store_label'] ?? $store['shop_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-shop="<?= htmlspecialchars((string)($store['shop_domain'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            ><i class="bi bi-pencil-square me-1"></i>Edit</button>

                                            <?php if (($store['status'] ?? '') !== 'disconnected'): ?>
                                                <button type="button" class="si-action-btn si-action-danger disconnect-btn" data-store-id="<?= (int)$store['id'] ?>"><i class="bi bi-pause-circle me-1"></i>Disconnect</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div id="integrationNoResults" class="si-empty" style="display:none">No integrations match the selected filter.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade si-modal" id="shopifyConnectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="shopify_connect.php" id="shopifyConnectForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="modal-header">
                    <div>
                        <h5 class="si-modal-title">Connect Shopify</h5>
                        <div class="si-modal-subtitle">Create or reconnect a Shopify Public App connection.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="si-label">Integration Name</label>
                            <input type="text" class="form-control si-input" name="integration_name" id="integrationName" placeholder="Shopify Main Store" maxlength="150" required>
                        </div>

                        <div class="col-md-6">
                            <label class="si-label">Store Name</label>
                            <input type="text" class="form-control si-input" name="store_name" id="storeName" placeholder="Karachi Online Store" maxlength="150" required>
                        </div>

                        <div class="col-12">
                            <label class="si-label">Shop Domain</label>
                            <input type="text" class="form-control si-input" name="shop" id="shopDomain" placeholder="your-store.myshopify.com" required>
                            <div class="si-help">Email address aur custom website domain enter na karein. Original <strong>.myshopify.com</strong> domain use karein.</div>
                        </div>

                        <div class="col-12">
                            <label class="si-label">Connect Method</label>
                            <select class="form-select si-input" name="connect_method" id="connectMethod" required>
                                <option value="public_app">Public App / Partner App (Global)</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="si-label">API Version</label>
                            <input type="text" class="form-control si-input" name="api_version" value="<?= htmlspecialchars(SHOPIFY_API_VERSION) ?>" readonly>
                        </div>

                        <div class="col-md-6 d-flex align-items-end">
                            <div class="si-info-box w-100">
                                <i class="bi bi-shield-check me-1"></i>
                                Client ID aur Client Secret ONEERP server ke global Shopify config mein rahenge. Client sirf store domain enter karke Shopify permissions approve karega.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="si-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="si-connect-btn" id="connectShopifyBtn" <?= (!$migrationReady || !$publicPatchReady || !shopifyConfigReady()) ? 'disabled' : '' ?>>
                        <i class="bi bi-link-45deg me-2"></i>Connect
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('shopifyConnectModal');
    const integrationName = document.getElementById('integrationName');
    const storeName = document.getElementById('storeName');
    const shopDomain = document.getElementById('shopDomain');
    const form = document.getElementById('shopifyConnectForm');
    const connectButton = document.getElementById('connectShopifyBtn');

    modalElement?.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        const isEdit = trigger?.classList?.contains('reconnect-btn');

        if (!isEdit) {
            form?.reset();
            return;
        }

        integrationName.value = trigger.dataset.integrationName || '';
        storeName.value = trigger.dataset.storeName || '';
        shopDomain.value = trigger.dataset.shop || '';
    });

    form?.addEventListener('submit', function () {
        if (!connectButton) return;
        connectButton.disabled = true;
        connectButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Redirecting to Shopify...';
    });

    const searchInput = document.getElementById('integrationSearch');
    const statusFilter = document.getElementById('integrationStatusFilter');
    const rows = Array.from(document.querySelectorAll('.integration-row'));
    const noResults = document.getElementById('integrationNoResults');

    function applyFilters() {
        const search = String(searchInput?.value || '').trim().toLowerCase();
        const status = String(statusFilter?.value || '').trim().toLowerCase();
        let visible = 0;

        rows.forEach(function (row) {
            const matchesSearch = search === '' || String(row.dataset.search || '').includes(search);
            const matchesStatus = status === '' || String(row.dataset.status || '') === status;
            row.hidden = !(matchesSearch && matchesStatus);
            if (!row.hidden) visible++;
        });

        if (noResults) noResults.style.display = rows.length > 0 && visible === 0 ? 'block' : 'none';
    }

    searchInput?.addEventListener('input', applyFilters);
    statusFilter?.addEventListener('change', applyFilters);
    document.getElementById('resetIntegrationFilters')?.addEventListener('click', function () {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = '';
        applyFilters();
    });

    async function shopifyAction(action, storeId) {
        const body = new URLSearchParams({action, store_id: String(storeId)});
        const response = await fetch('ajax_shopify.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Request failed.');
        return data;
    }

    document.querySelectorAll('.test-connection-btn').forEach(function (button) {
        button.addEventListener('click', async function () {
            const original = this.innerHTML;
            try {
                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing';
                const data = await shopifyAction('test_connection', this.dataset.storeId);
                alert(data.message);
                location.reload();
            } catch (error) {
                alert(error.message);
            } finally {
                this.disabled = false;
                this.innerHTML = original;
            }
        });
    });

    document.querySelectorAll('.disconnect-btn').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (!confirm('Disconnect this Shopify integration?')) return;
            const original = this.innerHTML;
            try {
                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Disconnecting';
                await shopifyAction('disconnect', this.dataset.storeId);
                location.reload();
            } catch (error) {
                alert(error.message);
            } finally {
                this.disabled = false;
                this.innerHTML = original;
            }
        });
    });
});
</script>
</body>
</html>
