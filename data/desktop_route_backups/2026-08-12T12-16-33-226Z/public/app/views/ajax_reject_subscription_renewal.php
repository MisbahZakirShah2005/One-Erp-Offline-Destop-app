<?php
/*
|--------------------------------------------------------------------------
| ONEERP - Reject Subscription Renewal Request
|--------------------------------------------------------------------------
| File:
| /public_html/public/app/views/ajax_reject_subscription_renewal.php
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/

function rejectRenewalResponse($success, $message, $data = [], $statusCode = 200)
{
    http_response_code((int)$statusCode);

    if (ob_get_length()) {
        ob_clean();
    }

    echo json_encode(
        array_merge(
            [
                'success' => (bool)$success,
                'message' => (string)$message,
            ],
            is_array($data) ? $data : []
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Safe POST value
|--------------------------------------------------------------------------
*/

function rejectRenewalPost($key, $maxLength = 1000)
{
    $value = trim((string)($_POST[$key] ?? ''));

    if (function_exists('mb_substr')) {
        return mb_substr(
            $value,
            0,
            (int)$maxLength,
            'UTF-8'
        );
    }

    return substr(
        $value,
        0,
        (int)$maxLength
    );
}

/*
|--------------------------------------------------------------------------
| Request method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rejectRenewalResponse(
        false,
        'Invalid request method.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Exact ONEERP super admin guard
|--------------------------------------------------------------------------
| Current authorized admin:
| users.id = 43
| role_id = 1
| client_id = NULL
| status = active
|--------------------------------------------------------------------------
*/

$allowedAdminId = 43;
$adminUserId    = (int)($_SESSION['user_id'] ?? 0);

if ($adminUserId <= 0) {
    rejectRenewalResponse(
        false,
        'Your session has expired. Please log in again.',
        [
            'redirect' =>
                '/onerp/app/onerp/app/public/app/views/login_view.php'
        ],
        401
    );
}

if ($adminUserId !== $allowedAdminId) {
    rejectRenewalResponse(
        false,
        'Access denied. Sirf authorized ONEERP super admin renewal reject kar sakta hai.',
        [],
        403
    );
}

$adminStmt = $conn->prepare(
    "SELECT id
     FROM users
     WHERE id = ?
       AND role_id = 1
       AND client_id IS NULL
       AND status = 'active'
     LIMIT 1"
);

$adminStmt->bind_param(
    'i',
    $adminUserId
);

$adminStmt->execute();
$adminUser = $adminStmt
    ->get_result()
    ->fetch_assoc();

$adminStmt->close();

if (!$adminUser) {
    rejectRenewalResponse(
        false,
        'Admin account is not authorized or active.',
        [],
        403
    );
}

/*
|--------------------------------------------------------------------------
| CSRF validation
|--------------------------------------------------------------------------
*/

$sessionToken = (string)(
    $_SESSION[
        'admin_subscription_renewal_csrf'
    ] ?? ''
);

$postedToken = rejectRenewalPost(
    'csrf_token',
    128
);

if (
    $sessionToken === ''
    || $postedToken === ''
    || !hash_equals(
        $sessionToken,
        $postedToken
    )
) {
    rejectRenewalResponse(
        false,
        'Security token is invalid or expired. Page refresh karke dobara try karein.',
        [],
        419
    );
}

/*
|--------------------------------------------------------------------------
| Request data
|--------------------------------------------------------------------------
*/

$requestId = (int)(
    $_POST['request_id'] ?? 0
);

$adminNote = rejectRenewalPost(
    'admin_note',
    1000
);

if ($requestId <= 0) {
    rejectRenewalResponse(
        false,
        'Invalid renewal request ID.',
        [],
        422
    );
}

if ($adminNote === '') {
    rejectRenewalResponse(
        false,
        'Reject karne ke liye reason required hai.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Rejection transaction
|--------------------------------------------------------------------------
*/

try {
    $conn->begin_transaction();

    /*
    |--------------------------------------------------------------------------
    | Lock renewal request
    |--------------------------------------------------------------------------
    */

    $requestStmt = $conn->prepare(
        "SELECT
            id,
            client_id,
            subscription_id,
            plan_id,
            requested_by,
            status
         FROM subscription_renewal_requests
         WHERE id = ?
         LIMIT 1
         FOR UPDATE"
    );

    $requestStmt->bind_param(
        'i',
        $requestId
    );

    $requestStmt->execute();

    $renewalRequest = $requestStmt
        ->get_result()
        ->fetch_assoc();

    $requestStmt->close();

    if (!$renewalRequest) {
        throw new RuntimeException(
            'Renewal request was not found.'
        );
    }

    if (
        strtolower(
            trim(
                (string)$renewalRequest['status']
            )
        ) !== 'pending'
    ) {
        throw new RuntimeException(
            'Ye renewal request pehle hi process ho chuki hai.'
        );
    }

    $clientId =
        (int)$renewalRequest['client_id'];

    if ($clientId <= 0) {
        throw new RuntimeException(
            'Renewal request client information is incomplete.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Mark request rejected
    |--------------------------------------------------------------------------
    | Subscription table ko bilkul change nahi kiya jayega.
    |--------------------------------------------------------------------------
    */

    $updateRequestStmt = $conn->prepare(
        "UPDATE subscription_renewal_requests
         SET
            status = 'rejected',
            admin_note = ?,
            approved_by = ?,
            processed_at = NOW()
         WHERE id = ?
           AND status = 'pending'"
    );

    $updateRequestStmt->bind_param(
        'sii',
        $adminNote,
        $adminUserId,
        $requestId
    );

    $updateRequestStmt->execute();

    if (
        $updateRequestStmt
            ->affected_rows !== 1
    ) {
        $updateRequestStmt->close();

        throw new RuntimeException(
            'Renewal request rejection could not be saved.'
        );
    }

    $updateRequestStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Notify every active user of this client
    |--------------------------------------------------------------------------
    */

    $notificationMessage =
        'Aap ki subscription renewal request #'
        . $requestId
        . ' reject kar di gayi hai. Reason: '
        . $adminNote;

    if (function_exists('mb_substr')) {
        $notificationMessage = mb_substr(
            $notificationMessage,
            0,
            255,
            'UTF-8'
        );
    } else {
        $notificationMessage = substr(
            $notificationMessage,
            0,
            255
        );
    }

    $notificationType =
        'subscription_renewal_rejected';

    $referenceKey =
        'subscription_renewal_rejected_'
        . $requestId;

    $notificationLink =
        '/onerp/app/public/app/views/renew_subscription.php';

    $notificationStmt = $conn->prepare(
        "INSERT IGNORE INTO notifications (
            user_id,
            message,
            notification_type,
            reference_key,
            link,
            is_read,
            created_at
         )
         SELECT
            u.id,
            ?,
            ?,
            ?,
            ?,
            0,
            NOW()
         FROM users u
         WHERE u.client_id = ?
           AND u.status = 'active'"
    );

    $notificationStmt->bind_param(
        'ssssi',
        $notificationMessage,
        $notificationType,
        $referenceKey,
        $notificationLink,
        $clientId
    );

    $notificationStmt->execute();
    $notificationStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    $_SESSION[
        'admin_subscription_renewal_csrf'
    ] = bin2hex(
        random_bytes(32)
    );

    rejectRenewalResponse(
        true,
        'Renewal request successfully reject kar di gayi hai.',
        [
            'request_id' =>
                $requestId,

            'client_id' =>
                $clientId,

            'status' =>
                'rejected',

            'admin_note' =>
                $adminNote,

            'csrf_token' =>
                $_SESSION[
                    'admin_subscription_renewal_csrf'
                ],
        ]
    );

} catch (Throwable $exception) {

    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // No action required.
    }

    error_log(
        'Subscription renewal rejection error: '
        . $exception->getMessage()
    );

    $safeMessage =
        $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Renewal request reject nahi ho saki. Please try again.';

    $statusCode =
        $exception instanceof RuntimeException
            ? 409
            : 500;

    rejectRenewalResponse(
        false,
        $safeMessage,
        [],
        $statusCode
    );
}
