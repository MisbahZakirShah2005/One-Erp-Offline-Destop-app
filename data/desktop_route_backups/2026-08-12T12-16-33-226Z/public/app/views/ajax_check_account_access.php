<?php

/*
|--------------------------------------------------------------------------
| ONEERP Live Account Access Checker
|--------------------------------------------------------------------------
| Current logged-in user aur client/company ka live status check karta hai.
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| JSON and no-cache headers
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('X-Content-Type-Options: nosniff');

/*
|--------------------------------------------------------------------------
| JSON response helper
|--------------------------------------------------------------------------
*/

function oneerpAccessJsonResponse(array $data, $httpCode = 200)
{
    http_response_code((int)$httpCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Session validation
|--------------------------------------------------------------------------
*/

$userId = isset($_SESSION['user_id'])
    ? (int)$_SESSION['user_id']
    : 0;

$roleId = isset($_SESSION['role_id'])
    ? (int)$_SESSION['role_id']
    : 0;

if ($userId <= 0) {
    oneerpAccessJsonResponse([
        'success'  => false,
        'active'   => false,
        'reason'   => 'session_expired',
        'message'  => 'Your login session has expired.',
        'redirect' => '/onerp/app/onerp/app/public/app/views/login_view.php'
    ]);
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
| db.php mein guard include hai, lekin account access checker guard ki
| excluded files list mein hona chahiye.
|--------------------------------------------------------------------------
*/

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log(
        'ajax_check_account_access.php: database connection not found.'
    );

    oneerpAccessJsonResponse([
        'success' => false,
        'active'  => null,
        'reason'  => 'database_unavailable',
        'message' => 'Unable to verify account access.'
    ], 503);
}

/*
|--------------------------------------------------------------------------
| Super Admin bypass
|--------------------------------------------------------------------------
| ONEERP mein role_id 1 Super Admin hai.
|--------------------------------------------------------------------------
*/

if ($roleId === 1) {
    unset($_SESSION['account_access_lock']);

    oneerpAccessJsonResponse([
        'success'       => true,
        'active'        => true,
        'is_superadmin' => true
    ]);
}

/*
|--------------------------------------------------------------------------
| Fetch user and client status
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        u.id,
        u.client_id,
        u.name,
        u.email,
        u.status AS user_status,
        c.business_name,
        c.status AS client_status
    FROM users u
    LEFT JOIN clients c
        ON c.id = u.client_id
    WHERE u.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log(
        'Account access check prepare error: ' . $conn->error
    );

    oneerpAccessJsonResponse([
        'success' => false,
        'active'  => null,
        'reason'  => 'database_error',
        'message' => 'Unable to verify account access.'
    ], 503);
}

$stmt->bind_param('i', $userId);

if (!$stmt->execute()) {
    error_log(
        'Account access check execute error: ' . $stmt->error
    );

    $stmt->close();

    oneerpAccessJsonResponse([
        'success' => false,
        'active'  => null,
        'reason'  => 'database_error',
        'message' => 'Unable to verify account access.'
    ], 503);
}

$stmt->bind_result(
    $dbUserId,
    $dbClientId,
    $dbUserName,
    $dbUserEmail,
    $dbUserStatus,
    $dbBusinessName,
    $dbClientStatus
);

$accountFound = $stmt->fetch();

$stmt->close();

/*
|--------------------------------------------------------------------------
| User record no longer exists
|--------------------------------------------------------------------------
*/

if (!$accountFound) {
    $_SESSION['account_access_lock'] = [
        'reason'        => 'user_not_found',
        'message'       => 'Your user account is no longer available.',
        'user_id'       => $userId,
        'client_id'     => 0,
        'user_name'     => '',
        'user_email'    => '',
        'business_name' => '',
        'user_status'   => 'missing',
        'client_status' => 'missing',
        'locked_at'     => date('Y-m-d H:i:s')
    ];

    oneerpAccessJsonResponse([
        'success'  => true,
        'active'   => false,
        'reason'   => 'user_inactive',
        'status'   => 'missing',
        'message'  => 'Your user account is no longer available.',
        'redirect' => '/onerp/app/public/app/views/account_deactivated.php'
    ]);
}

/*
|--------------------------------------------------------------------------
| Normalize database values
|--------------------------------------------------------------------------
*/

$dbUserId   = (int)$dbUserId;
$dbClientId = (int)$dbClientId;

$userStatus = strtolower(
    trim((string)$dbUserStatus)
);

$clientStatus = strtolower(
    trim((string)$dbClientStatus)
);

/*
|--------------------------------------------------------------------------
| Individual user status check
|--------------------------------------------------------------------------
*/

if ($userStatus !== 'active') {
    $message = 'Your user account has been deactivated.';

    if ($userStatus === 'suspended') {
        $message = 'Your user account has been suspended.';
    } elseif ($userStatus === 'deleted') {
        $message = 'Your user account is no longer available.';
    }

    $_SESSION['account_access_lock'] = [
        'reason'        => 'user_inactive',
        'message'       => $message,
        'user_id'       => $dbUserId,
        'client_id'     => $dbClientId,
        'user_name'     => (string)$dbUserName,
        'user_email'    => (string)$dbUserEmail,
        'business_name' => (string)$dbBusinessName,
        'user_status'   => $userStatus,
        'client_status' => $clientStatus,
        'locked_at'     => date('Y-m-d H:i:s')
    ];

    oneerpAccessJsonResponse([
        'success'  => true,
        'active'   => false,
        'reason'   => 'user_inactive',
        'status'   => $userStatus,
        'message'  => $message,
        'redirect' => '/onerp/app/public/app/views/account_deactivated.php'
    ]);
}

/*
|--------------------------------------------------------------------------
| Client record validation
|--------------------------------------------------------------------------
*/

if ($dbClientId <= 0 || $dbBusinessName === null) {
    $message = 'Your company account is no longer available.';

    $_SESSION['account_access_lock'] = [
        'reason'        => 'client_inactive',
        'message'       => $message,
        'user_id'       => $dbUserId,
        'client_id'     => $dbClientId,
        'user_name'     => (string)$dbUserName,
        'user_email'    => (string)$dbUserEmail,
        'business_name' => '',
        'user_status'   => $userStatus,
        'client_status' => 'missing',
        'locked_at'     => date('Y-m-d H:i:s')
    ];

    oneerpAccessJsonResponse([
        'success'  => true,
        'active'   => false,
        'reason'   => 'client_inactive',
        'status'   => 'missing',
        'message'  => $message,
        'redirect' => '/onerp/app/public/app/views/account_deactivated.php'
    ]);
}

/*
|--------------------------------------------------------------------------
| Complete client/company status check
|--------------------------------------------------------------------------
*/

if ($clientStatus !== 'active') {
    $message = 'Your company account has been deactivated.';

    if ($clientStatus === 'suspended') {
        $message = 'Your company account has been suspended.';
    } elseif ($clientStatus === 'deleted') {
        $message = 'Your company account is no longer available.';
    }

    $_SESSION['account_access_lock'] = [
        'reason'        => 'client_inactive',
        'message'       => $message,
        'user_id'       => $dbUserId,
        'client_id'     => $dbClientId,
        'user_name'     => (string)$dbUserName,
        'user_email'    => (string)$dbUserEmail,
        'business_name' => (string)$dbBusinessName,
        'user_status'   => $userStatus,
        'client_status' => $clientStatus,
        'locked_at'     => date('Y-m-d H:i:s')
    ];

    oneerpAccessJsonResponse([
        'success'       => true,
        'active'        => false,
        'reason'        => 'client_inactive',
        'status'        => $clientStatus,
        'message'       => $message,
        'business_name' => (string)$dbBusinessName,
        'redirect'      => '/onerp/app/public/app/views/account_deactivated.php'
    ]);
}

/*
|--------------------------------------------------------------------------
| Account and client both active
|--------------------------------------------------------------------------
*/

unset($_SESSION['account_access_lock']);

oneerpAccessJsonResponse([
    'success'       => true,
    'active'        => true,
    'user_id'       => $dbUserId,
    'client_id'     => $dbClientId,
    'user_status'   => $userStatus,
    'client_status' => $clientStatus
]);