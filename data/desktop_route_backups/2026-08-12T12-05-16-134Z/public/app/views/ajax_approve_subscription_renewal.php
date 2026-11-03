<?php
/*
|--------------------------------------------------------------------------
| ONEERP - Approve Subscription Renewal Request
|--------------------------------------------------------------------------
| File:
| /public_html/public/app/views/ajax_approve_subscription_renewal.php
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

function approveRenewalResponse($success, $message, $data = [], $statusCode = 200)
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

function approveRenewalPost($key, $maxLength = 1000)
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
    approveRenewalResponse(
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
    approveRenewalResponse(
        false,
        'Your session has expired. Please log in again.',
        [
            'redirect' =>
                '/onerp/app/public/app/views/login_view.php'
        ],
        401
    );
}

if ($adminUserId !== $allowedAdminId) {
    approveRenewalResponse(
        false,
        'Access denied. Sirf authorized ONEERP super admin renewal approve kar sakta hai.',
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
    approveRenewalResponse(
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

$postedToken = approveRenewalPost(
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
    approveRenewalResponse(
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

$adminNote = approveRenewalPost(
    'admin_note',
    1000
);

if ($requestId <= 0) {
    approveRenewalResponse(
        false,
        'Invalid renewal request ID.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Approval transaction
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
            amount,
            payment_method,
            payment_reference,
            payment_proof,
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

    $clientId       =
        (int)$renewalRequest['client_id'];

    $subscriptionId =
        (int)$renewalRequest['subscription_id'];

    $requestPlanId  =
        (int)$renewalRequest['plan_id'];

    if (
        $clientId <= 0
        || $subscriptionId <= 0
        || $requestPlanId <= 0
    ) {
        throw new RuntimeException(
            'Renewal request information is incomplete.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Lock subscription and plan
    |--------------------------------------------------------------------------
    */

    $subscriptionStmt = $conn->prepare(
        "SELECT
            s.id,
            s.client_id,
            s.plan_id,
            s.status,
            s.start_date,
            s.end_date,
            s.expires_at,

            COALESCE(
                s.expires_at,
                CONCAT(
                    s.end_date,
                    ' 23:59:59'
                )
            ) AS current_expiry,

            COALESCE(
                p.name,
                'Subscription Plan'
            ) AS plan_name,

            COALESCE(
                p.billing_cycle,
                'monthly'
            ) AS billing_cycle

         FROM subscriptions s

         LEFT JOIN plans p
            ON p.id = s.plan_id

         WHERE s.id = ?
           AND s.client_id = ?

         LIMIT 1
         FOR UPDATE"
    );

    $subscriptionStmt->bind_param(
        'ii',
        $subscriptionId,
        $clientId
    );

    $subscriptionStmt->execute();

    $subscription = $subscriptionStmt
        ->get_result()
        ->fetch_assoc();

    $subscriptionStmt->close();

    if (!$subscription) {
        throw new RuntimeException(
            'Subscription record was not found.'
        );
    }

    $subscriptionPlanId =
        (int)$subscription['plan_id'];

    if (
        $subscriptionPlanId <= 0
        || $subscriptionPlanId
            !== $requestPlanId
    ) {
        throw new RuntimeException(
            'Request plan aur current subscription plan match nahi karte.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate new expiry
    |--------------------------------------------------------------------------
    | Active subscription:
    | current expiry ke baad period add hoga.
    |
    | Expired subscription:
    | current approval time se period start hoga.
    |--------------------------------------------------------------------------
    */

    $timezone = new DateTimeZone(
        'Asia/Karachi'
    );

    $now = new DateTime(
        'now',
        $timezone
    );

    $currentExpiryValue = trim(
        (string)$subscription[
            'current_expiry'
        ]
    );

    $currentExpiry = null;

    if ($currentExpiryValue !== '') {
        try {
            $currentExpiry = new DateTime(
                $currentExpiryValue,
                $timezone
            );
        } catch (Throwable $dateError) {
            $currentExpiry = null;
        }
    }

    if (
        $currentExpiry instanceof DateTime
        && $currentExpiry > $now
    ) {
        $renewalBase = clone $currentExpiry;
    } else {
        $renewalBase = clone $now;
    }

    $billingCycle = strtolower(
        trim(
            (string)$subscription[
                'billing_cycle'
            ]
        )
    );

    $newExpiry = clone $renewalBase;

    if ($billingCycle === 'yearly') {
        $newExpiry->modify('+1 year');
        $renewalPeriodLabel = '1 year';
    } else {
        $billingCycle = 'monthly';
        $newExpiry->modify('+1 month');
        $renewalPeriodLabel = '1 month';
    }

    $newEndDate = $newExpiry->format(
        'Y-m-d'
    );

    $newExpiresAt = $newExpiry->format(
        'Y-m-d H:i:s'
    );

    /*
    |--------------------------------------------------------------------------
    | Activate and extend subscription
    |--------------------------------------------------------------------------
    */

    $updateSubscriptionStmt = $conn->prepare(
        "UPDATE subscriptions
         SET
            status = 'active',
            end_date = ?,
            expires_at = ?
         WHERE id = ?
           AND client_id = ?"
    );

    $updateSubscriptionStmt->bind_param(
        'ssii',
        $newEndDate,
        $newExpiresAt,
        $subscriptionId,
        $clientId
    );

    $updateSubscriptionStmt->execute();
if (
    $updateSubscriptionStmt
        ->affected_rows !== 1
) {
        $updateSubscriptionStmt->close();

        throw new RuntimeException(
            'Subscription update failed.'
        );
    }

    $updateSubscriptionStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Mark renewal request approved
    |--------------------------------------------------------------------------
    */

    $updateRequestStmt = $conn->prepare(
        "UPDATE subscription_renewal_requests
         SET
            status = 'approved',
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
            'Renewal request approval could not be saved.'
        );
    }

    $updateRequestStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Mark old expiry warnings as read
    |--------------------------------------------------------------------------
    */

    $readWarningsStmt = $conn->prepare(
        "UPDATE notifications n

         INNER JOIN users u
            ON u.id = n.user_id

         SET n.is_read = 1

         WHERE u.client_id = ?
           AND n.notification_type IN (
                'subscription_expiring',
                'subscription_expired'
           )"
    );

    $readWarningsStmt->bind_param(
        'i',
        $clientId
    );

    $readWarningsStmt->execute();
    $readWarningsStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Notify every active user of this client
    |--------------------------------------------------------------------------
    */

    $planName = trim(
        (string)$subscription['plan_name']
    );

    $expiryDisplay = $newExpiry->format(
        'd M Y, h:i A'
    );

    $notificationMessage = sprintf(
        'Aap ki %s subscription renewal request #%d approve ho gayi hai. New expiry: %s.',
        $planName,
        $requestId,
        $expiryDisplay
    );

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
        'subscription_renewed';

    $referenceKey =
        'subscription_renewed_'
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

    approveRenewalResponse(
        true,
        'Subscription successfully renew ho gayi hai.',
        [
            'request_id' =>
                $requestId,

            'client_id' =>
                $clientId,

            'subscription_id' =>
                $subscriptionId,

            'status' =>
                'approved',

            'billing_cycle' =>
                $billingCycle,

            'renewal_period' =>
                $renewalPeriodLabel,

            'new_end_date' =>
                $newEndDate,

            'new_expiry' =>
                $newExpiresAt,

            'new_expiry_display' =>
                $expiryDisplay,

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
        'Subscription renewal approval error: '
        . $exception->getMessage()
    );

    $safeMessage =
        $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Subscription renewal approve nahi ho saki. Please try again.';

    $statusCode =
        $exception instanceof RuntimeException
            ? 409
            : 500;

    approveRenewalResponse(
        false,
        $safeMessage,
        [],
        $statusCode
    );
}
