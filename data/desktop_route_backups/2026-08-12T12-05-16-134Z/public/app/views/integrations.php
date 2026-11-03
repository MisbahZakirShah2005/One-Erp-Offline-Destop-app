<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = $_SESSION['user_id'];
$logged_role_id   = $_SESSION['role_id'];
$logged_client_id = $_SESSION['client_id'];

/* ================= FILTERS ================= */
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$category_filter = trim($_GET['category'] ?? '');

/* ================= WOOCOMMERCE HELPERS ================= */
function integrationTableHasColumn(mysqli $conn, string $columnName): bool
{
    $safeColumn = $conn->real_escape_string($columnName);
    $result = $conn->query("SHOW COLUMNS FROM integrations LIKE '{$safeColumn}'");

    $exists = $result && $result->num_rows > 0;

    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $exists;
}

function normalizeWooCommerceStoreUrl(string $storeUrl): string
{
    $storeUrl = trim($storeUrl);
    return rtrim($storeUrl, '/');
}

function testWooCommerceCredentials(string $storeUrl, string $consumerKey, string $consumerSecret): array
{
    $storeUrl = normalizeWooCommerceStoreUrl($storeUrl);
    $consumerKey = trim($consumerKey);
    $consumerSecret = trim($consumerSecret);

    if ($storeUrl === '' || !filter_var($storeUrl, FILTER_VALIDATE_URL)) {
        return [
            'success' => false,
            'message' => 'Valid WooCommerce Store URL required hai.'
        ];
    }

    if ($consumerKey === '' || $consumerSecret === '') {
        return [
            'success' => false,
            'message' => 'Consumer Key aur Consumer Secret required hain.'
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'message' => 'Server par PHP cURL extension enabled nahi hai.'
        ];
    }

    $endpoint = $storeUrl . '/wp-json/wc/v3/products?per_page=1';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $consumerKey . ':' . $consumerSecret,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: ONEERP-WooCommerce-Integration/1.0'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'message' => 'WooCommerce connection failed: ' . ($curlError ?: 'Unknown cURL error'),
            'http_code' => $httpCode
        ];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded)) {
        return [
            'success' => true,
            'message' => 'WooCommerce connection successful hai.',
            'http_code' => $httpCode
        ];
    }

    $message = 'WooCommerce API connection failed.';

    if (is_array($decoded)) {
        if (!empty($decoded['message'])) {
            $message = (string)$decoded['message'];
        } elseif (!empty($decoded['data']['message'])) {
            $message = (string)$decoded['data']['message'];
        }
    }

    return [
        'success' => false,
        'message' => $message,
        'http_code' => $httpCode
    ];
}

/* ================= AJAX: TEST WOOCOMMERCE CONNECTION ================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ajax_action'] ?? '') === 'test_woocommerce_connection'
) {
    header('Content-Type: application/json; charset=utf-8');

    $integrationId = (int)($_POST['integration_id'] ?? 0);
    $storeUrl = trim($_POST['store_url'] ?? '');
    $consumerKey = trim($_POST['api_key'] ?? '');
    $consumerSecret = trim($_POST['api_secret'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Saved integration ko dropdown se test karne par credentials DB se load honge.
    | Modal ke andar unsaved/new integration ko posted fields se test kiya jayega.
    |--------------------------------------------------------------------------
    */
    if ($integrationId > 0) {
        $hasStoreUrlColumn = integrationTableHasColumn($conn, 'store_url');

        if (!$hasStoreUrlColumn) {
            echo json_encode([
                'success' => false,
                'message' => 'Database mein integrations.store_url column missing hai.'
            ]);
            exit;
        }

        $stmtWoo = $conn->prepare("
            SELECT store_url, api_key, api_secret
            FROM integrations
            WHERE id = ?
              AND client_id = ?
              AND integration_type = 'WooCommerce'
            LIMIT 1
        ");
        $stmtWoo->bind_param("ii", $integrationId, $logged_client_id);
        $stmtWoo->execute();
        $savedWoo = $stmtWoo->get_result()->fetch_assoc();
        $stmtWoo->close();

        if (!$savedWoo) {
            echo json_encode([
                'success' => false,
                'message' => 'WooCommerce integration record nahi mila.'
            ]);
            exit;
        }

        if ($storeUrl === '') {
            $storeUrl = (string)($savedWoo['store_url'] ?? '');
        }
        if ($consumerKey === '') {
            $consumerKey = (string)($savedWoo['api_key'] ?? '');
        }
        if ($consumerSecret === '') {
            $consumerSecret = (string)($savedWoo['api_secret'] ?? '');
        }
    }

    $testResult = testWooCommerceCredentials($storeUrl, $consumerKey, $consumerSecret);

    /*
    |--------------------------------------------------------------------------
    | Agar migration ke connection columns available hon to test result save karo.
    | Existing integrations logic ko touch nahi kiya gaya.
    |--------------------------------------------------------------------------
    */
    if ($integrationId > 0 && integrationTableHasColumn($conn, 'connection_status')) {
        $connectionStatus = !empty($testResult['success']) ? 'connected' : 'error';
        $lastError = !empty($testResult['success'])
            ? null
            : (string)($testResult['message'] ?? 'Connection failed');

        $hasLastError = integrationTableHasColumn($conn, 'last_error');
        $hasLastTestedAt = integrationTableHasColumn($conn, 'last_tested_at');

        if ($hasLastError && $hasLastTestedAt) {
            $stmtUpdateTest = $conn->prepare("
                UPDATE integrations
                SET connection_status = ?,
                    last_error = ?,
                    last_tested_at = NOW()
                WHERE id = ?
                  AND client_id = ?
            ");
            $stmtUpdateTest->bind_param(
                "ssii",
                $connectionStatus,
                $lastError,
                $integrationId,
                $logged_client_id
            );
        } elseif ($hasLastError) {
            $stmtUpdateTest = $conn->prepare("
                UPDATE integrations
                SET connection_status = ?,
                    last_error = ?
                WHERE id = ?
                  AND client_id = ?
            ");
            $stmtUpdateTest->bind_param(
                "ssii",
                $connectionStatus,
                $lastError,
                $integrationId,
                $logged_client_id
            );
        } else {
            $stmtUpdateTest = $conn->prepare("
                UPDATE integrations
                SET connection_status = ?
                WHERE id = ?
                  AND client_id = ?
            ");
            $stmtUpdateTest->bind_param(
                "sii",
                $connectionStatus,
                $integrationId,
                $logged_client_id
            );
        }

        $stmtUpdateTest->execute();
        $stmtUpdateTest->close();
    }

    echo json_encode($testResult);
    exit;
}

/* ================= PREDEFINED INTEGRATIONS ================= */
$integration_types = [
    [
        'name' => 'Wholesale Pricing',
        'category' => 'Business Feature',
        'logo' => ''
    ],
    [
        'name' => 'Foodpanda',
        'category' => 'Delivery',
        'logo' => 'https://crushlogo.com/files/preview/800x800/11683635893fyddltmtztsrzuldd062dyprytcgvqvswm5ea9evcwiu9rytxpmcvzcnve99e6itcvicijln721ytutwnddzmgfjlpzkvhdwxzbw.png'
    ],
    [
        'name' => 'FBR',
        'category' => 'Tax',
        'logo' => 'https://crystalpng.com/wp-content/uploads/2025/04/fbr-logo-1.png'
    ],
    [
        'name' => 'IRS',
        'category' => 'Tax',
        'logo' => 'https://upload.wikimedia.org/wikipedia/commons/6/68/Blue-Eagle%2BIRS.png'
    ],
    [
        'name' => 'Uber Eats',
        'category' => 'Delivery',
        'logo' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQu5SmmKGr4uFz8s011YbIW_W34ep_AIe4Pg4zjj09QKJ71EDZwboV2xxXkcQCugtcHC5M&usqp=CAU'
    ],
    [
        'name' => 'WooCommerce',
        'category' => 'E-commerce',
        'logo' => 'https://www.logo.wine/a/logo/WooCommerce/WooCommerce-Logo.wine.svg'
    ],
    [
        'name' => 'Shopify',
        'category' => 'E-commerce',
        'logo' => 'https://toppng.com/uploads/preview/shopify-logo-11609376282ajachzgoup.png'
    ],
    [
        'name' => 'JazzCash',
        'category' => 'Payment',
        'logo' => 'https://seeklogo.com/images/J/jazzcash-logo-8298413528-seeklogo.com.png'
    ],
    [
        'name' => 'WhatsApp API',
        'category' => 'Messaging',
        'logo' => 'https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg'
    ]
];

/* ================= ADD / EDIT INTEGRATION ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_integration'])) {
    $integration_id   = (int)($_POST['integration_id'] ?? 0);
    $integration_type = trim($_POST['integration_type'] ?? '');
    $integration_name = trim($_POST['integration_name'] ?? '');
    $store_url       = trim($_POST['store_url'] ?? '');
    $api_key          = trim($_POST['api_key'] ?? '');
    $api_secret       = trim($_POST['api_secret'] ?? '');
    $api_token        = trim($_POST['api_token'] ?? '');
    $status           = trim($_POST['status'] ?? 'inactive');

    if ($integration_type === '') {
        $_SESSION['integration_error'] = "Integration type is required.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | BUSINESS FEATURE: WHOLESALE PRICING
    |--------------------------------------------------------------------------
    | Wholesale Pricing integration sirf feature ON/OFF toggle hai.
    | Pricing items.php me manage hogi. API fields is feature ke liye blank rahengi.
    */
    $isWholesaleFeature = ($integration_type === 'Wholesale Pricing');

    if ($isWholesaleFeature) {
        $integration_type = 'Wholesale Pricing';
        $integration_name = 'Wholesale Pricing';
        $api_key = '';
        $api_secret = '';
        $api_token = '';

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'inactive';
        }

        /*
        |--------------------------------------------------------------------------
        | One client ke liye Wholesale Pricing duplicate na bane.
        | Agar pehle se record hai aur user dobara Add kare,
        | to existing record update ho jayega.
        |--------------------------------------------------------------------------
        */
        if ($integration_id <= 0) {
            $stmtExisting = $conn->prepare("
                SELECT id
                FROM integrations
                WHERE client_id = ?
                  AND integration_type = 'Wholesale Pricing'
                LIMIT 1
            ");

            if ($stmtExisting) {
                $stmtExisting->bind_param("i", $logged_client_id);
                $stmtExisting->execute();
                $existingRow = $stmtExisting->get_result()->fetch_assoc();
                $stmtExisting->close();

                if (!empty($existingRow['id'])) {
                    $integration_id = (int)$existingRow['id'];
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | WOOCOMMERCE
    |--------------------------------------------------------------------------
    | Store URL integrations.store_url mein save hoga.
    | Consumer Key api_key aur Consumer Secret api_secret mein save honge.
    | WooCommerce ke liye API Token use nahi hota, is liye blank rahega.
    |--------------------------------------------------------------------------
    */
    $isWooCommerce = ($integration_type === 'WooCommerce');

    if ($isWooCommerce) {
        $store_url = normalizeWooCommerceStoreUrl($store_url);
        $api_token = '';

        if ($store_url === '' || !filter_var($store_url, FILTER_VALIDATE_URL)) {
            $_SESSION['integration_error'] = 'Valid WooCommerce Store URL required hai.';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        if ($api_key === '' || $api_secret === '') {
            $_SESSION['integration_error'] = 'WooCommerce Consumer Key aur Consumer Secret required hain.';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    } else {
        $store_url = '';
    }

    if ($integration_name === '') {
        $integration_name = $integration_type;
    }

    /*
    |--------------------------------------------------------------------------
    | Optional DB column support
    |--------------------------------------------------------------------------
    | Existing fallback behavior preserve kiya gaya hai.
    |--------------------------------------------------------------------------
    */
    $hasIntegrationNameColumn = integrationTableHasColumn($conn, 'integration_name');
    $hasStoreUrlColumn = integrationTableHasColumn($conn, 'store_url');

    if ($isWooCommerce && !$hasStoreUrlColumn) {
        $_SESSION['integration_error'] = 'Database mein integrations.store_url column missing hai.';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($integration_id > 0) {
        if ($hasIntegrationNameColumn && $hasStoreUrlColumn) {
            $stmt = $conn->prepare("
                UPDATE integrations
                SET integration_type=?,
                    integration_name=?,
                    store_url=?,
                    api_key=?,
                    api_secret=?,
                    api_token=?,
                    status=?,
                    updated_at=NOW()
                WHERE id=?
                  AND client_id=?
            ");
            $stmt->bind_param(
                "sssssssii",
                $integration_type,
                $integration_name,
                $store_url,
                $api_key,
                $api_secret,
                $api_token,
                $status,
                $integration_id,
                $logged_client_id
            );
        } elseif ($hasIntegrationNameColumn) {
            $stmt = $conn->prepare("
                UPDATE integrations
                SET integration_type=?,
                    integration_name=?,
                    api_key=?,
                    api_secret=?,
                    api_token=?,
                    status=?,
                    updated_at=NOW()
                WHERE id=?
                  AND client_id=?
            ");
            $stmt->bind_param(
                "ssssssii",
                $integration_type,
                $integration_name,
                $api_key,
                $api_secret,
                $api_token,
                $status,
                $integration_id,
                $logged_client_id
            );
        } elseif ($hasStoreUrlColumn) {
            $stmt = $conn->prepare("
                UPDATE integrations
                SET integration_type=?,
                    store_url=?,
                    api_key=?,
                    api_secret=?,
                    api_token=?,
                    status=?,
                    updated_at=NOW()
                WHERE id=?
                  AND client_id=?
            ");
            $stmt->bind_param(
                "ssssssii",
                $integration_type,
                $store_url,
                $api_key,
                $api_secret,
                $api_token,
                $status,
                $integration_id,
                $logged_client_id
            );
        } else {
            $stmt = $conn->prepare("
                UPDATE integrations
                SET integration_type=?,
                    api_key=?,
                    api_secret=?,
                    api_token=?,
                    status=?,
                    updated_at=NOW()
                WHERE id=?
                  AND client_id=?
            ");
            $stmt->bind_param(
                "sssssii",
                $integration_type,
                $api_key,
                $api_secret,
                $api_token,
                $status,
                $integration_id,
                $logged_client_id
            );
        }

        if ($stmt && $stmt->execute()) {
            $_SESSION['integration_success'] = "Integration updated successfully.";
        } else {
            $_SESSION['integration_error'] = "Failed to update integration.";
        }

        if ($stmt) {
            $stmt->close();
        }
    } else {
        if ($hasIntegrationNameColumn && $hasStoreUrlColumn) {
            $stmt = $conn->prepare("
                INSERT INTO integrations (
                    client_id,
                    integration_type,
                    integration_name,
                    store_url,
                    api_key,
                    api_secret,
                    api_token,
                    status,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                "isssssss",
                $logged_client_id,
                $integration_type,
                $integration_name,
                $store_url,
                $api_key,
                $api_secret,
                $api_token,
                $status
            );
        } elseif ($hasIntegrationNameColumn) {
            $stmt = $conn->prepare("
                INSERT INTO integrations (
                    client_id,
                    integration_type,
                    integration_name,
                    api_key,
                    api_secret,
                    api_token,
                    status,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                "issssss",
                $logged_client_id,
                $integration_type,
                $integration_name,
                $api_key,
                $api_secret,
                $api_token,
                $status
            );
        } elseif ($hasStoreUrlColumn) {
            $stmt = $conn->prepare("
                INSERT INTO integrations (
                    client_id,
                    integration_type,
                    store_url,
                    api_key,
                    api_secret,
                    api_token,
                    status,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                "issssss",
                $logged_client_id,
                $integration_type,
                $store_url,
                $api_key,
                $api_secret,
                $api_token,
                $status
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO integrations (
                    client_id,
                    integration_type,
                    api_key,
                    api_secret,
                    api_token,
                    status,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                "isssss",
                $logged_client_id,
                $integration_type,
                $api_key,
                $api_secret,
                $api_token,
                $status
            );
        }

        if ($stmt && $stmt->execute()) {
            $_SESSION['integration_success'] = "Integration added successfully.";
        } else {
            $_SESSION['integration_error'] = "Failed to add integration.";
        }

        if ($stmt) {
            $stmt->close();
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ================= DELETE INTEGRATION ================= */
if (isset($_GET['delete_integration'])) {
    $delete_id = (int)$_GET['delete_integration'];

    $stmt = $conn->prepare("DELETE FROM integrations WHERE id=? AND client_id=?");
    $stmt->bind_param("ii", $delete_id, $logged_client_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['integration_success'] = "Integration deleted successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ================= ENABLE / DISABLE INTEGRATION ================= */
if (isset($_GET['toggle_integration'])) {
    $toggle_id = (int)$_GET['toggle_integration'];
    $next_status = trim($_GET['status'] ?? 'inactive');

    if (!in_array($next_status, ['active', 'inactive'], true)) {
        $next_status = 'inactive';
    }

    $stmt = $conn->prepare("
        UPDATE integrations
        SET status = ?, updated_at = NOW()
        WHERE id = ?
          AND client_id = ?
    ");

    if ($stmt) {
        $stmt->bind_param("sii", $next_status, $toggle_id, $logged_client_id);

        if ($stmt->execute()) {
            $_SESSION['integration_success'] = $next_status === 'active'
                ? "Integration enabled successfully."
                : "Integration disabled successfully.";
        } else {
            $_SESSION['integration_error'] = "Failed to update integration status.";
        }

        $stmt->close();
    } else {
        $_SESSION['integration_error'] = "Failed to prepare integration status update.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ================= FETCH INTEGRATIONS ================= */
$integrations = [];
$sql = "SELECT * FROM integrations WHERE client_id=?";
$params = [$logged_client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (integration_type LIKE ? OR api_key LIKE ? OR api_token LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($status_filter !== '') {
    $sql .= " AND status=?";
    $params[] = $status_filter;
    $types .= "s";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $integrations[] = $row;
}
$stmt->close();

/* ================= STATS ================= */
$totalIntegrations = count($integrations);
$totalActive = 0;
$totalInactive = 0;
$totalError = 0;

foreach ($integrations as $item) {
    $statusValue = strtolower(trim($item['status'] ?? 'inactive'));
    if ($statusValue === 'active') {
        $totalActive++;
    } elseif ($statusValue === 'error') {
        $totalError++;
    } else {
        $totalInactive++;
    }
}

function getIntegrationMeta($integration_types, $type) {
    foreach ($integration_types as $it) {
        if ($it['name'] === $type) {
            return $it;
        }
    }
    return ['name' => $type, 'category' => 'Other', 'logo' => ''];
}

function maskValue($value) {
    $value = trim((string)$value);
    if ($value === '') return '-';
    $len = strlen($value);
    if ($len <= 4) return str_repeat('*', $len);
    return str_repeat('•', max(0, $len - 4)) . substr($value, -4);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Integrations</title>
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
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            background:#f3f4f8;
            font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
            color:#1f2937;
        }

        .container-scroll{
            display:flex;
            min-height:100vh;
        }

        .sidebar{
            width:250px;
            flex-shrink:0;
        }

        .main-panel{
            flex-grow:1;
            display:flex;
            flex-direction:column;
            min-width:0;
        }

        .topbar{
            height:60px;
            border-bottom:1px solid #e5e7eb;
            display:flex;
            align-items:center;
            padding:0 20px;
            background:#fff;
        }

        .content-wrapper{
            padding:20px;
            flex-grow:1;
            overflow-y:auto;
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            margin-bottom:16px;
            flex-wrap:wrap;
        }

        .page-title{
            font-size:28px;
            font-weight:700;
            color:#1f2937;
            margin:0;
        }

        .page-subtitle{
            margin:6px 0 0;
            color:#6b7280;
            font-size:14px;
        }

        .add-btn{
            height:44px;
            border:none;
            border-radius:10px;
            padding:0 18px;
            background:#f2b90f;
            color:#111827;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }

        .stats-row{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:14px;
            margin-bottom:16px;
        }

        .stat-card{
            color:#fff;
            border-radius:10px;
            padding:14px 18px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
            min-height:84px;
        }

        .stat-card .title{
            font-size:15px;
            font-weight:600;
            line-height:1.3;
        }

        .stat-card .count{
            font-size:30px;
            font-weight:700;
            line-height:1;
        }

        .stat-card .icon{
            font-size:24px;
            opacity:0.95;
            margin-right:12px;
        }

        .stat-left{
            display:flex;
            align-items:center;
            gap:12px;
        }

        .card-blue{ background:linear-gradient(135deg,#314a93,#405da8); }
        .card-green{ background:linear-gradient(135deg,#5a9b42,#73b552); }
        .card-red{ background:linear-gradient(135deg,#b23a48,#d85b69); }
        .card-yellow{ background:linear-gradient(135deg,#d9a615,#f3be2d); }

        .filter-bar{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:12px;
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            margin-bottom:16px;
        }

        .search-wrap{
            flex:1.6;
            min-width:240px;
            position:relative;
        }

        .search-wrap i{
            position:absolute;
            left:12px;
            top:50%;
            transform:translateY(-50%);
            color:#6b7280;
            font-size:14px;
        }

        .search-wrap input{
            width:100%;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 14px 0 38px;
            outline:none;
            background:#fff;
            color:#1f2937;
            font-size:14px;
        }

        .search-wrap input:focus,
        .filter-select:focus{
            border-color:#cbd5e1;
            box-shadow:none;
            outline:none;
        }

        .select-wrap{
            min-width:170px;
        }

        .filter-select{
            width:100%;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 36px 0 14px;
            outline:none;
            background:#fff;
            color:#1f2937;
            font-size:14px;
            font-weight:500;
            cursor:pointer;
        }

        .table-card{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            overflow:hidden;
            margin-bottom:20px;
        }

        .table-responsive{
            overflow:auto;
        }

        .integrations-table{
            width:100%;
            min-width:1100px;
            border-collapse:collapse;
        }

        .integrations-table thead th{
            background:#28344d;
            color:#fff;
            font-weight:600;
            font-size:14px;
            padding:14px 12px;
            border-right:1px solid rgba(255,255,255,0.08);
            white-space:nowrap;
        }

        .integrations-table tbody td{
            padding:14px 12px;
            border-top:1px solid #edf0f5;
            vertical-align:middle;
            font-size:14px;
            color:#374151;
            background:#fff;
            white-space:nowrap;
        }

        .integrations-table tbody tr:hover td{
            background:#fafbfc;
        }

        .integration-cell{
            display:flex;
            align-items:center;
            gap:10px;
            min-width:180px;
        }

        .integration-logo{
            width:28px;
            height:28px;
            object-fit:contain;
            border-radius:8px;
            background:#fff;
            border:1px solid #e5e7eb;
            padding:3px;
            flex-shrink:0;
        }

        .integration-fallback{
            width:28px;
            height:28px;
            border-radius:8px;
            background:#e5e7eb;
            color:#111827;
            font-size:12px;
            font-weight:700;
            display:flex;
            align-items:center;
            justify-content:center;
            flex-shrink:0;
        }

        .category-badge,
        .status-badge{
            padding:6px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            gap:6px;
        }

        .category-badge{
            background:#eef2ff;
            color:#314a93;
        }

        .status-active{
            background:#e8f7ec;
            color:#17663c;
        }

        .status-inactive{
            background:#fff4dd;
            color:#8a5a00;
        }

        .status-error{
            background:#fdecec;
            color:#a32929;
        }

        .action-btn{
            border:none;
            background:#f3f4f6;
            color:#111827;
            border-radius:10px;
            width:38px;
            height:38px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .action-btn:hover{
            background:#e5e7eb;
        }

        .dropdown-menu{
            border:1px solid #e5e7eb;
            border-radius:12px;
            box-shadow:0 10px 30px rgba(0,0,0,.08);
            padding:8px;
        }

        .dropdown-item{
            border-radius:8px;
            padding:10px 12px;
            color:#1f2937;
        }

        .dropdown-item:hover{
            background:#f3f4f6;
            color:#111827;
        }

        .empty-state{
            background:#fff;
            border:1px dashed #d1d5db;
            border-radius:14px;
            padding:48px 20px;
            text-align:center;
        }

        .empty-icon{
            width:72px;
            height:72px;
            margin:0 auto 18px;
            border-radius:18px;
            background:#fff7d6;
            border:1px solid #f0dc92;
            display:flex;
            align-items:center;
            justify-content:center;
            color:#8a6500;
            font-size:32px;
        }

        .empty-title{
            font-size:32px;
            font-weight:700;
            color:#1f2937;
            margin:0 0 10px;
        }

        .empty-text{
            color:#6b7280;
            font-size:15px;
            margin-bottom:20px;
        }

        .custom-modal{
            border:none;
            border-radius:14px;
            overflow:hidden;
            box-shadow:0 20px 50px rgba(0,0,0,0.15);
        }

        .custom-modal .modal-header{
            background:#fff;
            border-bottom:1px solid #e5e7eb;
            padding:18px 20px;
        }

        .custom-modal .modal-title{
            font-size:24px;
            font-weight:700;
            color:#1f2937;
        }

        .custom-modal .modal-body{
            background:#fff;
            padding:20px;
        }

        .custom-modal .modal-footer{
            background:#fff;
            border-top:1px solid #e5e7eb;
            padding:16px 20px;
        }

        .custom-input{
            height:46px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            background:#fff;
            color:#1f2937;
            box-shadow:none !important;
        }

        .custom-input:focus{
            border-color:#cbd5e1;
        }

        .logo-preview-box{
            width:80px;
            height:80px;
            border-radius:14px;
            border:1px solid #e5e7eb;
            background:#f9fafb;
            display:flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
        }

        .logo-preview-box img{
            width:48px;
            height:48px;
            object-fit:contain;
        }

        .cancel-btn{
            background:#e5e7eb;
            color:#111827;
            font-weight:700;
            border-radius:10px;
            padding:10px 18px;
            border:none;
        }

        .save-btn{
            background:#f2b90f;
            color:#111827;
            font-weight:700;
            border-radius:10px;
            padding:10px 18px;
            border:none;
        }

        .save-btn:hover{
            background:#e2ac0d;
            color:#111827;
        }

        .cancel-btn:hover{
            background:#d7dce3;
            color:#111827;
        }

        @media (max-width:1100px){
            .stats-row{
                grid-template-columns:repeat(2,1fr);
            }
        }

        @media (max-width:768px){
            .stats-row{
                grid-template-columns:1fr;
            }

            .filter-bar{
                flex-direction:column;
                align-items:stretch;
            }

            .search-wrap,
            .select-wrap,
            .add-btn{
                width:100%;
                min-width:100%;
            }

            .page-title{
                font-size:24px;
            }

            .sidebar{
                width:240px;
            }
        }
    

/* =========================================================
   FINAL INTEGRATIONS POPUP BLACK + GOLD + WHITE THEME
   Add/Edit Integration Modal
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

/* Modal size */
#integrationModal .modal-dialog{
    max-width:min(860px,94vw) !important;
}

/* Main popup container */
#integrationModal .modal-content,
#integrationModal .custom-modal{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.62) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

/* Header */
#integrationModal .modal-header{
    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
    min-height:76px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#integrationModal .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#integrationModal .modal-title::before{
    content:'' !important;
    width:13px !important;
    height:13px !important;
    border-radius:50% !important;
    background:#c9a84c !important;
    box-shadow:0 0 0 5px rgba(201,168,76,.15) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

/* Close button - proper white X */
#integrationModal .btn-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#integrationModal .btn-close::before,
#integrationModal .btn-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:22px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#integrationModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#integrationModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#integrationModal .btn-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px) !important;
}

/* Body */
#integrationModal .modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#integrationModal .modal-body::-webkit-scrollbar{
    width:7px !important;
}

#integrationModal .modal-body::-webkit-scrollbar-track{
    background:#171c28 !important;
    border-radius:999px !important;
}

#integrationModal .modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c !important;
    border-radius:999px !important;
}

/* Footer */
#integrationModal .modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.28) !important;
    padding:18px 24px 22px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:12px !important;
}

/* Labels */
#integrationModal .form-label,
#integrationModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#integrationModal .text-muted{
    color:#dbe0f4 !important;
    font-weight:700 !important;
}

/* Inputs / selects */
#integrationModal .custom-input,
#integrationModal .form-control,
#integrationModal .form-select,
#integrationModal input[type="text"],
#integrationModal select{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    min-height:50px !important;
    height:50px !important;
    padding:10px 16px !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#integrationModal input::placeholder{
    color:#8993a5 !important;
}

#integrationModal .custom-input:focus,
#integrationModal .form-control:focus,
#integrationModal .form-select:focus,
#integrationModal input:focus,
#integrationModal select:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Logo preview */
#integrationModal .logo-preview-box{
    width:92px !important;
    height:92px !important;
    border-radius:16px !important;
    border:1.5px dashed rgba(201,168,76,.58) !important;
    background:#151a26 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    overflow:hidden !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#integrationModal .logo-preview-box img{
    width:58px !important;
    height:58px !important;
    object-fit:contain !important;
    background:#ffffff !important;
    border-radius:12px !important;
    padding:6px !important;
}

/* Footer buttons */
#integrationModal .cancel-btn{
    height:44px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#integrationModal .cancel-btn:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#integrationModal .save-btn{
    height:44px !important;
    min-width:150px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#integrationModal .save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px) !important;
}

/* Mobile */
@media (max-width:768px){
    #integrationModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #integrationModal .modal-title{
        font-size:18px !important;
    }

    #integrationModal .btn-close{
        width:42px !important;
        height:42px !important;
    }

    #integrationModal .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #integrationModal .cancel-btn,
    #integrationModal .save-btn{
        width:100% !important;
    }
}




/* =====================================================
   INTEGRATIONS.PHP DASHBOARD DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.14);
    --oms-border-2:rgba(148,163,184,.22);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= SIDEBAR / LAYOUT ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

#dashboardSidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
}

#dashboardSidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

#dashboardSidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.main-panel{
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
    transition:margin-left .25s ease;
}

/* Sidebar collapsed support */
body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow,
body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon{
    margin:0 !important;
}

/* ================= TOPBAR ================= */
.topbar{
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid var(--oms-border) !important;
    display:flex !important;
    align-items:center !important;
    padding:0 !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
}

/* ================= CONTENT BACKGROUND ================= */
.content-wrapper{
    position:relative !important;
    padding:24px 26px !important;
    flex:1 !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
}

.content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}

.content-wrapper::after{
    content:"";
    position:absolute;
    width:520px;
    height:520px;
    right:-190px;
    top:-210px;
    pointer-events:none;
    z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);
    opacity:.85;
}

.content-wrapper > *{
    position:relative;
    z-index:1;
}

/* ================= PAGE HEADER ================= */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-subtitle{
    margin:6px 0 0 !important;
    color:#94a3b8 !important;
    font-size:14px !important;
    font-weight:600 !important;
}

/* ================= STATS CARDS ================= */
.stats-row{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:138px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    transition:.25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-left{
    display:flex !important;
    flex-direction:column !important;
    align-items:flex-start !important;
    gap:10px !important;
    position:relative;
    z-index:2;
}

.stat-card .icon{
    font-size:30px !important;
    opacity:.96;
}

.stat-card .title{
    font-size:15px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
}

.stat-card .count{
    font-size:30px !important;
    font-weight:900 !important;
    line-height:1 !important;
    color:#fff !important;
    position:relative;
    z-index:2;
}

.card-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.card-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.card-red{background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.card-yellow{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}

/* ================= GLASS PANELS ================= */
.filter-bar,
.table-card,
.empty-state{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    color:#e5e7eb !important;
}

.filter-bar:hover,
.table-card:hover,
.empty-state:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-bar{
    padding:14px !important;
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
    align-items:center !important;
    margin-bottom:16px !important;
}

/* ================= INPUTS / SELECTS ================= */
.search-wrap input,
.filter-select{
    width:100% !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.search-wrap input{
    padding:0 14px 0 40px !important;
}

.search-wrap i{
    color:#94a3b8 !important;
}

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.search-wrap input:focus,
.filter-select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* ================= BUTTONS ================= */
.add-btn,
.save-btn{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    transition:.22s ease !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.add-btn:hover,
.save-btn:hover{
    transform:translateY(-1px);
    color:#fff !important;
    filter:saturate(1.06);
}

.cancel-btn,
.action-btn{
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.action-btn{
    width:46px !important;
    height:46px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.cancel-btn{
    min-height:46px !important;
    padding:0 18px !important;
    font-weight:900 !important;
}

.cancel-btn:hover,
.action-btn:hover{
    color:#fff !important;
    background:rgba(109,76,255,.18) !important;
    border-color:rgba(139,92,246,.42) !important;
    transform:translateY(-1px);
}

/* ================= TABLE DARK GLASS ================= */
.table-card{
    overflow:hidden !important;
    margin-bottom:20px !important;
}

.integrations-table{
    width:100% !important;
    min-width:1100px !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
}

.integrations-table thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:14px 12px !important;
    white-space:nowrap;
}

.integrations-table tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-top:none !important;
    padding:14px 12px !important;
    vertical-align:middle;
    font-size:14px !important;
    font-weight:600;
    white-space:nowrap;
}

.integrations-table tbody tr:hover td{
    background:rgba(124,58,237,.12) !important;
    color:#fff !important;
}

.integration-cell div div,
.integration-cell strong{
    color:#f8fafc !important;
}

.integration-logo,
.integration-fallback{
    background:#fff !important;
    border:1px solid rgba(148,163,184,.20) !important;
    box-shadow:0 10px 22px rgba(0,0,0,.18);
}

.integration-fallback{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

/* ================= BADGES ================= */
.category-badge{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
}

.status-active{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.status-inactive{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.status-error{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* ================= DROPDOWN ================= */
.dropdown-menu{
    background:#0f172a !important;
    border:1px solid rgba(148,163,184,.22) !important;
    border-radius:14px !important;
    box-shadow:0 24px 60px rgba(0,0,0,.45) !important;
    padding:8px !important;
}

.dropdown-item{
    border-radius:10px !important;
    padding:10px 12px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
}

.dropdown-item:hover{
    background:rgba(109,76,255,.18) !important;
    color:#fff !important;
}

.dropdown-divider{
    border-color:rgba(148,163,184,.14) !important;
}

/* ================= EMPTY STATE ================= */
.empty-icon{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
}

.empty-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.empty-text{
    color:#94a3b8 !important;
}

/* ================= ALERTS ================= */
.alert{
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.58)) !important;
    color:#e5e7eb !important;
    box-shadow:0 16px 36px rgba(0,0,0,.26);
}

.alert-success{
    border-color:rgba(34,197,94,.25) !important;
}

.alert-danger{
    border-color:rgba(239,68,68,.25) !important;
}


/* =====================================================
   INTEGRATION POPUP SAME AS WHOLESALE POPUP
   Compact Dark Purple Glass Theme
===================================================== */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

#integrationModal .modal-dialog{
    max-width:min(680px,92vw) !important;
}

#integrationModal .modal-content,
#integrationModal .custom-modal{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#integrationModal .modal-header{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#integrationModal .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#integrationModal .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#integrationModal .btn-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#integrationModal .btn-close::before,
#integrationModal .btn-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:18px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#integrationModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#integrationModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#integrationModal .btn-close:hover{
    background:#ef4444 !important;
}

#integrationModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#integrationModal .row.g-3{
    --bs-gutter-x:12px !important;
    --bs-gutter-y:10px !important;
}

#integrationModal .form-label,
#integrationModal label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#integrationModal .text-muted{
    color:#94a3b8 !important;
}

#integrationModal .custom-input,
#integrationModal .form-control,
#integrationModal .form-select,
#integrationModal input[type="text"],
#integrationModal select{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    min-height:40px !important;
    height:40px !important;
    padding:7px 12px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#integrationModal .custom-input:focus,
#integrationModal .form-control:focus,
#integrationModal .form-select:focus,
#integrationModal input:focus,
#integrationModal select:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#integrationModal .form-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

#integrationModal input::placeholder{
    color:#94a3b8 !important;
}

#integrationModal .logo-preview-box{
    width:74px !important;
    height:74px !important;
    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.20) !important;
    background:rgba(15,23,42,.62) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    overflow:hidden !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#integrationModal .logo-preview-box img{
    width:46px !important;
    height:46px !important;
    object-fit:contain !important;
    background:#ffffff !important;
    border-radius:10px !important;
    padding:5px !important;
}

#integrationModal .modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#integrationModal .cancel-btn{
    min-height:38px !important;
    height:38px !important;
    min-width:105px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
}

#integrationModal .save-btn{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#integrationModal .cancel-btn:hover,
#integrationModal .save-btn:hover{
    transform:translateY(-1px);
}



/* =========================================================
   INTEGRATIONS.PHP - FINAL LIGHT MODE
   Topbar aur sidebar dark navy-purple hi rahenge.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE BACKGROUND
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

/* Sidebar ko target nahi kiya ja raha */
html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 52%,
            #faf7ff 100%
        ) !important;

    color:#0f172a !important;
}

/* Main content */
html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 7%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 87% 3%,
            rgba(59,130,246,.05),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 48%,
            #faf7ff 78%,
            #f8fafc 100%
        ) !important;

    color:#0f172a !important;
}

/* Dark grid light karein */
html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.022) 1px,
            transparent 1px
        ) !important;

    opacity:.42 !important;
    mask-image:none !important;
}

/* Purple glow soft karein */
html[data-pos-theme="light"] body .content-wrapper::after{
    background:
        radial-gradient(
            circle,
            rgba(124,58,237,.08),
            transparent 68%
        ) !important;

    opacity:.42 !important;
    filter:blur(16px) !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] body .page-subtitle{
    color:#64748b !important;
}

/* Add Integration button */
html[data-pos-theme="light"] body .add-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border:none !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

html[data-pos-theme="light"] body .add-btn:hover{
    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #5b21b6
        ) !important;

    color:#ffffff !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;

    border:
        1px solid rgba(255,255,255,.28) !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .title,
html[data-pos-theme="light"] body .stat-card .count,
html[data-pos-theme="light"] body .stat-card .icon{
    color:#ffffff !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .card-blue{
    background:
        linear-gradient(
            135deg,
            #2563eb,
            #6d4cff
        ) !important;
}

html[data-pos-theme="light"] body .card-green{
    background:
        linear-gradient(
            135deg,
            #047857,
            #16a34a
        ) !important;
}

html[data-pos-theme="light"] body .card-yellow{
    background:
        linear-gradient(
            135deg,
            #f59e0b,
            #fb923c
        ) !important;
}

html[data-pos-theme="light"] body .card-red{
    background:
        linear-gradient(
            135deg,
            #be123c,
            #ef4444
        ) !important;
}

/* =========================================================
   FILTER BAR
========================================================= */

html[data-pos-theme="light"] body .filter-bar{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .filter-bar:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 15px 34px rgba(15,23,42,.09) !important;
}

/* Search and selects */
html[data-pos-theme="light"] body .search-wrap input,
html[data-pos-theme="light"] body .filter-select{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"]
body .search-wrap input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-wrap i{
    color:#7c3aed !important;
}

html[data-pos-theme="light"] body .search-wrap input:hover,
html[data-pos-theme="light"] body .filter-select:hover{
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] body .search-wrap input:focus,
html[data-pos-theme="light"] body .filter-select:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .filter-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* =========================================================
   TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 36px rgba(15,23,42,.09) !important;
}

html[data-pos-theme="light"] body .table-responsive{
    background:#ffffff !important;
}

/* Table dark variables reset */
html[data-pos-theme="light"] body .integrations-table,
html[data-pos-theme="light"] body .integrations-table thead,
html[data-pos-theme="light"] body .integrations-table tbody,
html[data-pos-theme="light"] body .integrations-table tr,
html[data-pos-theme="light"] body .integrations-table th,
html[data-pos-theme="light"] body .integrations-table td{
    --bs-table-bg:transparent !important;
    --bs-table-color:#1e293b !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
    --bs-table-accent-bg:transparent !important;
}

/* Table header */
html[data-pos-theme="light"]
body .integrations-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-bottom:1px solid #cbd5e1 !important;
    border-right:1px solid #e2e8f0 !important;

    box-shadow:none !important;
}

/* Rows */
html[data-pos-theme="light"]
body .integrations-table tbody tr,

html[data-pos-theme="light"]
body .integrations-table tbody td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-bottom:1px solid #e8edf4 !important;

    box-shadow:none !important;
}

/* Alternate row */
html[data-pos-theme="light"]
body .integrations-table tbody tr:nth-child(even) td{
    background:#fbfcff !important;
}

/* Hover */
html[data-pos-theme="light"]
body .integrations-table tbody tr:hover td{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;

    color:#0f172a !important;
}

/* Integration name */
html[data-pos-theme="light"]
body .integration-cell div div,

html[data-pos-theme="light"]
body .integration-cell strong{
    color:#0f172a !important;
}

/* Logo */
html[data-pos-theme="light"] body .integration-logo{
    background:#ffffff !important;

    border:1px solid #e2e8f0 !important;

    box-shadow:
        0 5px 14px rgba(15,23,42,.08) !important;
}

html[data-pos-theme="light"] body .integration-fallback{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    border:none !important;

    box-shadow:
        0 6px 16px rgba(124,58,237,.18) !important;
}

/* =========================================================
   BADGES
========================================================= */

html[data-pos-theme="light"] body .category-badge{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border:1px solid #ddd6fe !important;
}

html[data-pos-theme="light"] body .status-active{
    background:#ecfdf5 !important;
    color:#047857 !important;

    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] body .status-inactive{
    background:#fffbeb !important;
    color:#b45309 !important;

    border:1px solid #fde68a !important;
}

html[data-pos-theme="light"] body .status-error{
    background:#fff1f2 !important;
    color:#be123c !important;

    border:1px solid #fecdd3 !important;
}

/* =========================================================
   ACTION BUTTON AND DROPDOWN
========================================================= */

html[data-pos-theme="light"] body .action-btn{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"] body .action-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border-color:#c4b5fd !important;
}

/* Sirf page content dropdown */
html[data-pos-theme="light"]
body .content-wrapper .dropdown-menu{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 18px 45px rgba(15,23,42,.16) !important;
}

html[data-pos-theme="light"]
body .content-wrapper .dropdown-item{
    color:#334155 !important;
}

html[data-pos-theme="light"]
body .content-wrapper .dropdown-item:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
}

html[data-pos-theme="light"]
body .content-wrapper .dropdown-item.text-danger{
    color:#e11d48 !important;
}

html[data-pos-theme="light"]
body .content-wrapper .dropdown-item.text-danger:hover{
    background:#fff1f2 !important;
    color:#be123c !important;
}

html[data-pos-theme="light"]
body .content-wrapper .dropdown-divider{
    border-color:#e2e8f0 !important;
}

/* =========================================================
   ALERTS
========================================================= */

html[data-pos-theme="light"] body .content-wrapper .alert{
    background:#ffffff !important;
    background-image:none !important;

    color:#334155 !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 10px 26px rgba(15,23,42,.07) !important;
}

html[data-pos-theme="light"]
body .content-wrapper .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;

    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"]
body .content-wrapper .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;

    border-color:#fecdd3 !important;
}

/* =========================================================
   EMPTY STATE
========================================================= */

html[data-pos-theme="light"] body .empty-state{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px dashed #cbd5e1 !important;

    box-shadow:
        0 12px 30px rgba(15,23,42,.06) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .empty-icon{
    background:#f3efff !important;
    color:#7c3aed !important;

    border:1px solid #ddd6fe !important;
}

html[data-pos-theme="light"] body .empty-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .empty-text{
    color:#64748b !important;
}

/* =========================================================
   INTEGRATION MODAL LIGHT MODE
========================================================= */

html[data-pos-theme="light"]
body.modal-open .modal-backdrop.show{
    background:#0f172a !important;
    opacity:.42 !important;
}

html[data-pos-theme="light"]
body #integrationModal .modal-content,

html[data-pos-theme="light"]
body #integrationModal .custom-modal{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #d8e0eb !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.22) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Modal header */
html[data-pos-theme="light"]
body #integrationModal .modal-header{
    background:#f8fafc !important;

    border-bottom:
        1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"]
body #integrationModal .modal-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"]
body #integrationModal .modal-title::before{
    background:#7c3aed !important;

    box-shadow:
        0 0 0 4px rgba(124,58,237,.14) !important;
}

/* Close button */
html[data-pos-theme="light"]
body #integrationModal .btn-close{
    background:#ffffff !important;

    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"]
body #integrationModal .btn-close::before,

html[data-pos-theme="light"]
body #integrationModal .btn-close::after{
    background:#334155 !important;
}

html[data-pos-theme="light"]
body #integrationModal .btn-close:hover{
    background:#fff1f2 !important;

    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"]
body #integrationModal .btn-close:hover::before,

html[data-pos-theme="light"]
body #integrationModal .btn-close:hover::after{
    background:#e11d48 !important;
}

/* Modal body */
html[data-pos-theme="light"]
body #integrationModal .modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Labels */
html[data-pos-theme="light"]
body #integrationModal .form-label,

html[data-pos-theme="light"]
body #integrationModal label{
    color:#334155 !important;
}

html[data-pos-theme="light"]
body #integrationModal .text-muted{
    color:#64748b !important;
}

/* Modal inputs */
html[data-pos-theme="light"]
body #integrationModal .custom-input,

html[data-pos-theme="light"]
body #integrationModal .form-control,

html[data-pos-theme="light"]
body #integrationModal .form-select,

html[data-pos-theme="light"]
body #integrationModal input[type="text"],

html[data-pos-theme="light"]
body #integrationModal select{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"]
body #integrationModal input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"]
body #integrationModal .custom-input:focus,

html[data-pos-theme="light"]
body #integrationModal .form-control:focus,

html[data-pos-theme="light"]
body #integrationModal .form-select:focus,

html[data-pos-theme="light"]
body #integrationModal input:focus,

html[data-pos-theme="light"]
body #integrationModal select:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"]
body #integrationModal .form-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Logo preview */
html[data-pos-theme="light"]
body #integrationModal .logo-preview-box{
    background:#f8fafc !important;

    border:1px dashed #c4b5fd !important;

    box-shadow:none !important;
}

/* Modal footer */
html[data-pos-theme="light"]
body #integrationModal .modal-footer{
    background:#f8fafc !important;

    border-top:
        1px solid #e2e8f0 !important;
}

/* Cancel */
html[data-pos-theme="light"]
body #integrationModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

/* Save */
html[data-pos-theme="light"]
body #integrationModal .save-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    border:none !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

/* =========================================================
   SCROLLBARS
========================================================= */

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-track,

html[data-pos-theme="light"]
body .table-responsive::-webkit-scrollbar-track,

html[data-pos-theme="light"]
body #integrationModal .modal-body::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"]
body .content-wrapper::-webkit-scrollbar-thumb,

html[data-pos-theme="light"]
body .table-responsive::-webkit-scrollbar-thumb,

html[data-pos-theme="light"]
body #integrationModal .modal-body::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
    border-radius:999px !important;
}


/* ================= WOOCOMMERCE POPUP ADDITIONS ================= */
#integrationModal .woo-test-btn{
    min-height:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#0f766e,#14b8a6) !important;
    color:#ffffff !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:7px 14px !important;
    box-shadow:0 12px 26px rgba(20,184,166,.22) !important;
}

#integrationModal .woo-test-btn:hover{
    transform:translateY(-1px) !important;
    color:#ffffff !important;
}

#integrationModal .woo-test-btn:disabled{
    opacity:.68 !important;
    cursor:not-allowed !important;
    transform:none !important;
}

#integrationModal .woo-test-result{
    border-radius:10px !important;
    padding:10px 12px !important;
    font-size:13px !important;
    font-weight:800 !important;
    border:1px solid rgba(148,163,184,.20) !important;
    background:rgba(15,23,42,.58) !important;
    color:#e5e7eb !important;
}

#integrationModal .woo-test-result.success{
    border-color:rgba(34,197,94,.35) !important;
    background:rgba(34,197,94,.12) !important;
    color:#86efac !important;
}

#integrationModal .woo-test-result.error{
    border-color:rgba(239,68,68,.35) !important;
    background:rgba(239,68,68,.12) !important;
    color:#fca5a5 !important;
}

html[data-pos-theme="light"] body #integrationModal .woo-test-result{
    background:#f8fafc !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body #integrationModal .woo-test-result.success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body #integrationModal .woo-test-result.error{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

</style>
</head>
<body>
    <div class="container-scroll">
        <div class="sidebar">
            <?php include('partials/sidebar.php'); ?>
        </div>

        <div class="main-panel">
            <div class="topbar">
                <?php include('partials/topbar.php'); ?>
            </div>

            <div class="content-wrapper">

                <div class="page-header">
                    <div>
                        <h4 class="page-title">Integrations</h4>
                        <p class="page-subtitle">Connect your payment, e-commerce, messaging, and business feature services.</p>
                    </div>

                    <button class="add-btn" data-bs-toggle="modal" data-bs-target="#integrationModal" onclick="resetIntegrationModal()">
                        <i class="bi bi-plus-lg"></i>
                        Add Integration
                    </button>
                </div>

                <div class="stats-row">
                    <div class="stat-card card-blue">
                        <div class="stat-left">
                            <div class="icon"><i class="bi bi-plug"></i></div>
                            <div class="title">Total Integrations</div>
                        </div>
                        <div class="count"><?= $totalIntegrations ?></div>
                    </div>

                    <div class="stat-card card-green">
                        <div class="stat-left">
                            <div class="icon"><i class="bi bi-check-circle"></i></div>
                            <div class="title">Active</div>
                        </div>
                        <div class="count"><?= $totalActive ?></div>
                    </div>

                    <div class="stat-card card-yellow">
                        <div class="stat-left">
                            <div class="icon"><i class="bi bi-pause-circle"></i></div>
                            <div class="title">Inactive</div>
                        </div>
                        <div class="count"><?= $totalInactive ?></div>
                    </div>

                    <div class="stat-card card-red">
                        <div class="stat-left">
                            <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
                            <div class="title">Errors</div>
                        </div>
                        <div class="count"><?= $totalError ?></div>
                    </div>
                </div>

                <?php if (!empty($_SESSION['integration_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['integration_success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['integration_success']); ?>
                <?php endif; ?>

                <?php if (!empty($_SESSION['integration_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['integration_error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['integration_error']); ?>
                <?php endif; ?>

                <form method="GET" class="filter-bar">
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input
                            type="text"
                            name="search"
                            placeholder="Search integration..."
                            value="<?= htmlspecialchars($search) ?>"
                        >
                    </div>

                    <div class="select-wrap">
                        <select name="category" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <option value="Business Feature" <?= $category_filter === 'Business Feature' ? 'selected' : '' ?>>Business Feature</option>
                            <option value="E-commerce" <?= $category_filter === 'E-commerce' ? 'selected' : '' ?>>E-commerce</option>
                            <option value="Payment" <?= $category_filter === 'Payment' ? 'selected' : '' ?>>Payment</option>
                            <option value="Messaging" <?= $category_filter === 'Messaging' ? 'selected' : '' ?>>Messaging</option>
                            <option value="Delivery" <?= $category_filter === 'Delivery' ? 'selected' : '' ?>>Delivery</option>
                            <option value="Tax" <?= $category_filter === 'Tax' ? 'selected' : '' ?>>Tax</option>
                        </select>
                    </div>

                    <div class="select-wrap">
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="error" <?= $status_filter === 'error' ? 'selected' : '' ?>>Error</option>
                        </select>
                    </div>
                </form>

                <div class="table-card">
                    <div class="table-responsive">
                        <table class="integrations-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Integration</th>
                                    <th>Category</th>
                                    <th>Account</th>
                                    <th>Status</th>
                                    <th>Last Sync</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rowCount = 0;
                                foreach ($integrations as $i):
                                    $meta = getIntegrationMeta($integration_types, $i['integration_type']);

                                    if ($category_filter !== '' && $meta['category'] !== $category_filter) {
                                        continue;
                                    }

                                    $rowCount++;
                                    $statusValue = strtolower(trim($i['status'] ?? 'inactive'));
                                    $statusClass = 'status-inactive';

                                    if ($statusValue === 'active') {
                                        $statusClass = 'status-active';
                                    } elseif ($statusValue === 'error') {
                                        $statusClass = 'status-error';
                                    }

                                    $isBusinessFeature = ($meta['category'] === 'Business Feature');

                                    $accountDisplay = $i['integration_name'] ?? '';
                                    if ($isBusinessFeature) {
                                        $accountDisplay = 'Feature Toggle';
                                    } elseif ($accountDisplay === '') {
                                        $accountDisplay = maskValue(($i['api_token'] ?? '') ?: ($i['api_key'] ?? ''));
                                    }

                                    $lastSync = !empty($i['last_sync_at']) ? $i['last_sync_at'] : (!empty($i['updated_at']) ? $i['updated_at'] : (!empty($i['created_at']) ? $i['created_at'] : '-'));
                                ?>
                                    <tr>
                                        <td><?= str_pad((string)$i['id'], 2, '0', STR_PAD_LEFT) ?></td>

                                        <td>
                                            <div class="integration-cell">
                                                <?php if (!empty($meta['logo'])): ?>
                                                    <img src="<?= htmlspecialchars($meta['logo']) ?>" alt="" class="integration-logo">
                                                <?php else: ?>
                                                    <div class="integration-fallback"><?= strtoupper(substr($i['integration_type'], 0, 1)) ?></div>
                                                <?php endif; ?>

                                                <div>
                                                    <div style="font-weight:700; color:#1f2937;"><?= htmlspecialchars($i['integration_type']) ?></div>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="category-badge">
                                                <i class="bi bi-tags"></i>
                                                <?= htmlspecialchars($meta['category']) ?>
                                            </span>
                                        </td>

                                        <td><?= htmlspecialchars($accountDisplay) ?></td>

                                        <td>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <?= ucfirst($statusValue) ?>
                                            </span>
                                        </td>

                                        <td><?= htmlspecialchars($lastSync) ?></td>

                                        <td>
                                            <div class="dropdown">
                                                <button class="action-btn" data-bs-toggle="dropdown" type="button">
                                                    <i class="bi bi-three-dots"></i>
                                                </button>

                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <button
                                                            class="dropdown-item"
                                                            type="button"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#integrationModal"
                                                            onclick="editIntegration(
                                                                '<?= (int)$i['id'] ?>',
                                                                '<?= htmlspecialchars($i['integration_type'], ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($i['integration_name'] ?? '', ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($i['store_url'] ?? '', ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($i['api_key'] ?? '', ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($i['api_secret'] ?? '', ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($i['api_token'] ?? '', ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($statusValue, ENT_QUOTES) ?>'
                                                            )"
                                                        >
                                                            <i class="bi bi-gear"></i> Configure
                                                        </button>
                                                    </li>

                                                    <?php if (!$isBusinessFeature): ?>
                                                        <li>
                                                            <?php if (($i['integration_type'] ?? '') === 'WooCommerce'): ?>
                                                                <button
                                                                    class="dropdown-item"
                                                                    type="button"
                                                                    onclick="testSavedWooCommerceConnection(<?= (int)$i['id'] ?>, this)"
                                                                >
                                                                    <i class="bi bi-wifi"></i> Test Connection
                                                                </button>
                                                            <?php else: ?>
                                                                <button class="dropdown-item" type="button">
                                                                    <i class="bi bi-wifi"></i> Test Connection
                                                                </button>
                                                            <?php endif; ?>
                                                        </li>
                                                        <li><button class="dropdown-item" type="button"><i class="bi bi-arrow-repeat"></i> Sync Now</button></li>
                                                    <?php endif; ?>

                                                    <li>
                                                        <?php
                                                            $nextStatus = $statusValue === 'active' ? 'inactive' : 'active';
                                                            $toggleLabel = $statusValue === 'active' ? 'Disable' : 'Enable';
                                                            $toggleIcon = $statusValue === 'active' ? 'bi-pause-circle' : 'bi-play-circle';
                                                        ?>
                                                        <a
                                                            class="dropdown-item"
                                                            href="?toggle_integration=<?= (int)$i['id'] ?>&status=<?= htmlspecialchars($nextStatus) ?>"
                                                            onclick="return confirm('Are you sure you want to <?= strtolower($toggleLabel) ?> this integration?')"
                                                        >
                                                            <i class="bi <?= $toggleIcon ?>"></i>
                                                            <?= $toggleLabel ?>
                                                        </a>
                                                    </li>

                                                    <li><hr class="dropdown-divider"></li>

                                                    <li>
                                                        <a class="dropdown-item text-danger" href="?delete_integration=<?= (int)$i['id'] ?>" onclick="return confirm('Are you sure you want to delete this integration?')">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if ($rowCount === 0): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">No integrations found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (count($integrations) === 0): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="bi bi-plug"></i>
                        </div>
                        <h3 class="empty-title">No integrations added</h3>
                        <p class="empty-text">Connect your payment, ecommerce, messaging, or business features.</p>
                        <button class="add-btn" data-bs-toggle="modal" data-bs-target="#integrationModal" onclick="resetIntegrationModal()">
                            <i class="bi bi-plus-lg"></i>
                            Add Integration
                        </button>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- ADD / EDIT MODAL -->
    <div class="modal fade" id="integrationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="post" class="modal-content custom-modal">
                <input type="hidden" name="save_integration" value="1">
                <input type="hidden" name="integration_id" id="integration_id" value="0">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Integration</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Integration Type</label>
                            <select name="integration_type" id="integration_type" class="form-select custom-input" required>
                                <option value="">Select Integration</option>
                                <?php foreach ($integration_types as $int): ?>
                                    <option
                                        value="<?= htmlspecialchars($int['name']) ?>"
                                        data-logo="<?= htmlspecialchars($int['logo']) ?>"
                                        data-category="<?= htmlspecialchars($int['category']) ?>"
                                    >
                                        <?= htmlspecialchars($int['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Integration Name</label>
                            <input type="text" name="integration_name" id="integration_name" class="form-control custom-input" placeholder="Enter integration name">
                        </div>

                        <div class="col-12" id="store_url_wrap" style="display:none;">
                            <label class="form-label" id="store_url_label">WooCommerce Store URL</label>
                            <input
                                type="url"
                                name="store_url"
                                id="store_url"
                                class="form-control custom-input"
                                placeholder="https://example-store.com"
                            >
                        </div>

                        <div class="col-md-6 api-field-wrap" id="api_key_wrap">
                            <label class="form-label" id="api_key_label">API Key</label>
                            <input type="text" name="api_key" id="api_key" class="form-control custom-input" placeholder="Enter API key">
                        </div>

                        <div class="col-md-6 api-field-wrap" id="api_secret_wrap">
                            <label class="form-label" id="api_secret_label">API Secret</label>
                            <input type="text" name="api_secret" id="api_secret" class="form-control custom-input" placeholder="Enter API secret">
                        </div>

                        <div class="col-12 api-field-wrap" id="api_token_wrap">
                            <label class="form-label">API Token <span class="text-muted">(Optional)</span></label>
                            <input type="text" name="api_token" id="api_token" class="form-control custom-input" placeholder="Enter API token">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="integration_status" class="form-select custom-input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="error">Error</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label d-block">Selected Integration Logo</label>
                            <div class="logo-preview-box">
                                <img id="integrationLogoPreview" src="" alt="">
                            </div>
                        </div>

                        <div class="col-12" id="woo_test_result_wrap" style="display:none;">
                            <div id="woo_test_result" class="woo-test-result"></div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn woo-test-btn"
                        id="wooTestConnectionBtn"
                        onclick="testWooCommerceConnectionFromModal(this)"
                        style="display:none;"
                    >
                        <i class="bi bi-wifi"></i> Test Connection
                    </button>
                    <button type="button" class="btn cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn save-btn">Save Integration</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const integrationType = document.getElementById('integration_type');
        const integrationLogo = document.getElementById('integrationLogoPreview');

        function isWholesalePricingSelected() {
            return integrationType && integrationType.value === 'Wholesale Pricing';
        }

        function isWooCommerceSelected() {
            return integrationType && integrationType.value === 'WooCommerce';
        }

        function updateLogoPreview() {
            const selectedOption = integrationType.options[integrationType.selectedIndex];
            const logo = selectedOption ? selectedOption.getAttribute('data-logo') : '';

            if (integrationLogo) {
                integrationLogo.src = logo ? logo : '';
                integrationLogo.style.display = logo ? 'block' : 'none';
            }
        }

        function resetWooTestResult() {
            const wrap = document.getElementById('woo_test_result_wrap');
            const result = document.getElementById('woo_test_result');

            if (wrap) {
                wrap.style.display = 'none';
            }

            if (result) {
                result.className = 'woo-test-result';
                result.textContent = '';
            }
        }

        function showWooTestResult(success, message) {
            const wrap = document.getElementById('woo_test_result_wrap');
            const result = document.getElementById('woo_test_result');

            if (!wrap || !result) {
                return;
            }

            wrap.style.display = '';
            result.className = 'woo-test-result ' + (success ? 'success' : 'error');
            result.textContent = message;
        }

        function toggleBusinessFeatureFields() {
            const isWholesale = isWholesalePricingSelected();
            const isWooCommerce = isWooCommerceSelected();

            const storeUrlWrap = document.getElementById('store_url_wrap');
            const apiKeyWrap = document.getElementById('api_key_wrap');
            const apiSecretWrap = document.getElementById('api_secret_wrap');
            const apiTokenWrap = document.getElementById('api_token_wrap');
            const wooTestButton = document.getElementById('wooTestConnectionBtn');

            const integrationName = document.getElementById('integration_name');
            const storeUrl = document.getElementById('store_url');
            const apiKey = document.getElementById('api_key');
            const apiSecret = document.getElementById('api_secret');
            const apiToken = document.getElementById('api_token');
            const status = document.getElementById('integration_status');

            const apiKeyLabel = document.getElementById('api_key_label');
            const apiSecretLabel = document.getElementById('api_secret_label');

            [apiKeyWrap, apiSecretWrap].forEach(function (el) {
                if (el) {
                    el.style.display = isWholesale ? 'none' : '';
                }
            });

            if (apiTokenWrap) {
                apiTokenWrap.style.display = (isWholesale || isWooCommerce) ? 'none' : '';
            }

            if (storeUrlWrap) {
                storeUrlWrap.style.display = isWooCommerce ? '' : 'none';
            }

            if (wooTestButton) {
                wooTestButton.style.display = isWooCommerce ? '' : 'none';
            }

            if (storeUrl) {
                storeUrl.required = isWooCommerce;
            }

            if (apiKey) {
                apiKey.required = isWooCommerce;
                apiKey.placeholder = isWooCommerce
                    ? 'ck_xxxxxxxxxxxxxxxxx'
                    : 'Enter API key';
            }

            if (apiSecret) {
                apiSecret.required = isWooCommerce;
                apiSecret.placeholder = isWooCommerce
                    ? 'cs_xxxxxxxxxxxxxxxxx'
                    : 'Enter API secret';
            }

            if (apiKeyLabel) {
                apiKeyLabel.textContent = isWooCommerce ? 'Consumer Key' : 'API Key';
            }

            if (apiSecretLabel) {
                apiSecretLabel.textContent = isWooCommerce ? 'Consumer Secret' : 'API Secret';
            }

            if (isWholesale) {
                if (integrationName) {
                    integrationName.value = 'Wholesale Pricing';
                    integrationName.readOnly = true;
                }

                if (storeUrl) storeUrl.value = '';
                if (apiKey) apiKey.value = '';
                if (apiSecret) apiSecret.value = '';
                if (apiToken) apiToken.value = '';

                if (status && status.value === 'error') {
                    status.value = 'inactive';
                }
            } else {
                if (integrationName) {
                    integrationName.readOnly = false;
                }

                if (isWooCommerce && integrationName && integrationName.value.trim() === '') {
                    integrationName.value = 'WooCommerce';
                }

                if (isWooCommerce && apiToken) {
                    apiToken.value = '';
                }
            }

            if (!isWooCommerce) {
                resetWooTestResult();
            }
        }

        integrationType.addEventListener('change', function() {
            updateLogoPreview();
            toggleBusinessFeatureFields();
            resetWooTestResult();
        });

        function resetIntegrationModal() {
            document.getElementById('integration_id').value = 0;
            document.getElementById('modalTitle').innerText = 'Add Integration';
            document.getElementById('integration_type').value = '';
            document.getElementById('integration_name').value = '';
            document.getElementById('integration_name').readOnly = false;
            document.getElementById('store_url').value = '';
            document.getElementById('api_key').value = '';
            document.getElementById('api_secret').value = '';
            document.getElementById('api_token').value = '';
            document.getElementById('integration_status').value = 'active';

            updateLogoPreview();
            toggleBusinessFeatureFields();
            resetWooTestResult();
        }

        function editIntegration(id, type, name, storeUrl, key, secret, token, status) {
            document.getElementById('integration_id').value = id;
            document.getElementById('integration_type').value = type;
            document.getElementById('integration_name').value = name;
            document.getElementById('store_url').value = storeUrl;
            document.getElementById('api_key').value = key;
            document.getElementById('api_secret').value = secret;
            document.getElementById('api_token').value = token;
            document.getElementById('integration_status').value = status;
            document.getElementById('modalTitle').innerText = 'Edit Integration';

            updateLogoPreview();
            toggleBusinessFeatureFields();
            resetWooTestResult();
        }

        function postWooCommerceTest(payload) {
            return fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams(payload).toString()
            }).then(function (response) {
                return response.json();
            });
        }

        function testWooCommerceConnectionFromModal(button) {
            if (!isWooCommerceSelected()) {
                return;
            }

            const storeUrl = document.getElementById('store_url').value.trim();
            const consumerKey = document.getElementById('api_key').value.trim();
            const consumerSecret = document.getElementById('api_secret').value.trim();
            const integrationId = document.getElementById('integration_id').value;

            if (!storeUrl || !consumerKey || !consumerSecret) {
                showWooTestResult(
                    false,
                    'Store URL, Consumer Key aur Consumer Secret enter karein.'
                );
                return;
            }

            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';
            resetWooTestResult();

            postWooCommerceTest({
                ajax_action: 'test_woocommerce_connection',
                integration_id: integrationId,
                store_url: storeUrl,
                api_key: consumerKey,
                api_secret: consumerSecret
            })
                .then(function (result) {
                    showWooTestResult(
                        !!result.success,
                        result.message || 'Unknown response'
                    );
                })
                .catch(function () {
                    showWooTestResult(
                        false,
                        'Connection test request failed.'
                    );
                })
                .finally(function () {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                });
        }

        function testSavedWooCommerceConnection(integrationId, button) {
            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

            postWooCommerceTest({
                ajax_action: 'test_woocommerce_connection',
                integration_id: integrationId
            })
                .then(function (result) {
                    alert(result.message || (result.success
                        ? 'WooCommerce connection successful hai.'
                        : 'WooCommerce connection failed.'));
                })
                .catch(function () {
                    alert('Connection test request failed.');
                })
                .finally(function () {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                });
        }

        document.getElementById('integrationModal').addEventListener('hidden.bs.modal', function () {
            resetIntegrationModal();
        });

        updateLogoPreview();
        toggleBusinessFeatureFields();
    </script>
</body>
</html>