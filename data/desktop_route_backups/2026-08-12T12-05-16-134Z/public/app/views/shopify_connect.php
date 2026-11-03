<?php
session_start();

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/shopify.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/integration_helper.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/shopify_auth_helper.php');

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$clientId = (int)($_SESSION['client_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

requirePlanFeature(
    $conn,
    $clientId,
    'shopify_integration'
);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid Shopify connection request.');
    }

    if (!shopifyConfigReady()) {
        throw new RuntimeException(
            'Global Shopify Public App credentials are not configured on the ONEERP server.'
        );
    }

    if (
        !oneerpTableExists($conn, 'shopify_stores') ||
        !oneerpTableExists($conn, 'shopify_oauth_states')
    ) {
        throw new RuntimeException(
            'Shopify database migration or public connection patch has not been run.'
        );
    }

    $postedCsrf = trim((string)($_POST['csrf_token'] ?? ''));
    $sessionCsrf = trim((string)($_SESSION['shopify_connect_csrf'] ?? ''));

    if (
        $postedCsrf === '' ||
        $sessionCsrf === '' ||
        !hash_equals($sessionCsrf, $postedCsrf)
    ) {
        throw new RuntimeException(
            'The connection form expired. Refresh the page and try again.'
        );
    }

    $integrationName = trim(
        (string)($_POST['integration_name'] ?? '')
    );

    $storeLabel = trim(
        (string)($_POST['store_name'] ?? '')
    );

    $connectMethod = strtolower(
        trim(
            (string)($_POST['connect_method'] ?? 'public_app')
        )
    );

    $shopDomain = normalizeShopifyDomain(
        (string)($_POST['shop'] ?? '')
    );

    if (
        $integrationName === '' ||
        mb_strlen($integrationName) > 150
    ) {
        throw new RuntimeException(
            'Enter a valid integration name up to 150 characters.'
        );
    }

    if (
        $storeLabel === '' ||
        mb_strlen($storeLabel) > 150
    ) {
        throw new RuntimeException(
            'Enter a valid store name up to 150 characters.'
        );
    }

    if ($connectMethod !== 'public_app') {
        throw new RuntimeException(
            'Only Public App / Partner App connection is supported.'
        );
    }

    if ($shopDomain === '') {
        throw new RuntimeException(
            'Enter the permanent Shopify domain, for example mystore.myshopify.com. Email or custom website domain is not valid.'
        );
    }

    $apiVersion = SHOPIFY_API_VERSION;
    $storeId = 0;

    $conn->begin_transaction();

    try {
        $existing = null;

        $lookup = $conn->prepare("
            SELECT id, status
            FROM shopify_stores
            WHERE client_id = ?
              AND shop_domain = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$lookup) {
            throw new RuntimeException(
                'Shopify store lookup failed: ' . $conn->error
            );
        }

        $lookup->bind_param(
            'is',
            $clientId,
            $shopDomain
        );

        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();

        if ($existing) {
            $storeId = (int)$existing['id'];
            $existingStatus = strtolower(
                trim((string)($existing['status'] ?? ''))
            );

            $nextStatus = $existingStatus === 'active'
                ? 'active'
                : 'connecting';

            $update = $conn->prepare("
                UPDATE shopify_stores
                SET integration_name = ?,
                    store_label = ?,
                    connect_method = 'public_app',
                    created_by_user_id = ?,
                    api_version = ?,
                    status = ?,
                    last_error = NULL
                WHERE id = ?
                  AND client_id = ?
            ");

            if (!$update) {
                throw new RuntimeException(
                    'Shopify connection update failed: ' . $conn->error
                );
            }

            $update->bind_param(
                'ssissii',
                $integrationName,
                $storeLabel,
                $userId,
                $apiVersion,
                $nextStatus,
                $storeId,
                $clientId
            );

            $update->execute();
            $update->close();
        } else {
            $insert = $conn->prepare("
                INSERT INTO shopify_stores (
                    client_id,
                    integration_name,
                    store_label,
                    connect_method,
                    created_by_user_id,
                    shop_domain,
                    api_version,
                    status,
                    last_error
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    'public_app',
                    ?,
                    ?,
                    ?,
                    'connecting',
                    NULL
                )
            ");

            if (!$insert) {
                throw new RuntimeException(
                    'Shopify connection create failed: ' . $conn->error
                );
            }

            $insert->bind_param(
                'ississ',
                $clientId,
                $integrationName,
                $storeLabel,
                $userId,
                $shopDomain,
                $apiVersion
            );

            $insert->execute();
            $storeId = (int)$insert->insert_id;
            $insert->close();
        }

        $state = createShopifyOAuthState(
            $conn,
            $clientId,
            $userId,
            $shopDomain,
            $storeId
        );

        $conn->commit();
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignore) {
        }

        throw $e;
    }

    $_SESSION['shopify_connect_csrf'] = bin2hex(
        random_bytes(32)
    );

    header(
        'Location: ' .
        buildShopifyAuthorizationUrl(
            $shopDomain,
            $state
        )
    );
    exit;
} catch (Throwable $e) {
    header(
        'Location: shopify_settings.php?error=' .
        urlencode($e->getMessage())
    );
    exit;
}
