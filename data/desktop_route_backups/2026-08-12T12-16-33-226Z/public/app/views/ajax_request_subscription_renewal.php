<?php
/**
 * ONEERP - Submit Subscription Renewal Request
 *
 * Expected POST fields:
 * - csrf_token
 * - payment_method
 * - payment_reference
 * - client_note
 * - payment_proof (optional JPG/PNG/WEBP/PDF, max 5 MB)
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| JSON response headers
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';

/*
|--------------------------------------------------------------------------
| JSON response helper
|--------------------------------------------------------------------------
*/

if (!function_exists('renewalJsonResponse')) {
    function renewalJsonResponse($success, $message, array $data = array(), $statusCode = 200)
    {
        http_response_code((int)$statusCode);

        /*
         * Kisi warning/extra output ki wajah se JSON break na ho.
         */
        if (ob_get_length()) {
            ob_clean();
        }

        echo json_encode(
            array_merge(
                array(
                    'success' => (bool)$success,
                    'message' => (string)$message,
                ),
                $data
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Safe POST value helper
|--------------------------------------------------------------------------
*/

if (!function_exists('renewalPostString')) {
    function renewalPostString($key, $maxLength = 255)
    {
        $value = trim((string)($_POST[$key] ?? ''));
        $maxLength = max(0, (int)$maxLength);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }

        return substr($value, 0, $maxLength);
    }
}

/*
|--------------------------------------------------------------------------
| Request method
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    renewalJsonResponse(
        false,
        'Invalid request method.',
        array(),
        405
    );
}

/*
|--------------------------------------------------------------------------
| Logged-in user and client
|--------------------------------------------------------------------------
*/

$userId   = (int)($_SESSION['user_id'] ?? 0);
$clientId = (int)($_SESSION['client_id'] ?? 0);

if ($userId <= 0 || $clientId <= 0) {
    renewalJsonResponse(
        false,
        'Your session has expired. Please log in again.',
        array(
            'redirect' => '/onerp/app/onerp/app/public/app/views/login_view.php',
        ),
        401
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    renewalJsonResponse(
        false,
        'Database connection is not available.',
        array(),
        500
    );
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

$sessionCsrf = (string)($_SESSION['subscription_renewal_csrf'] ?? '');
$postedCsrf  = renewalPostString('csrf_token', 128);

if (
    $sessionCsrf === ''
    || $postedCsrf === ''
    || !hash_equals($sessionCsrf, $postedCsrf)
) {
    renewalJsonResponse(
        false,
        'Security token is invalid or expired. Refresh the page and try again.',
        array(),
        419
    );
}

/*
|--------------------------------------------------------------------------
| Input validation
|--------------------------------------------------------------------------
*/

$paymentMethod    = strtolower(renewalPostString('payment_method', 50));
$paymentReference = renewalPostString('payment_reference', 150);
$clientNote       = renewalPostString('client_note', 1000);

$allowedPaymentMethods = array(
    'bank_transfer',
    'easypaisa',
    'jazzcash',
    'cash',
    'other',
);

if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
    renewalJsonResponse(
        false,
        'Please select a valid payment method.',
        array(),
        422
    );
}

if ($paymentMethod !== 'cash' && $paymentReference === '') {
    renewalJsonResponse(
        false,
        'Payment reference/transaction ID is required.',
        array(),
        422
    );
}

/*
|--------------------------------------------------------------------------
| Optional payment proof upload
|--------------------------------------------------------------------------
*/

$paymentProofWebPath = null;
$uploadedAbsolutePath = null;

if (
    isset($_FILES['payment_proof'])
    && is_array($_FILES['payment_proof'])
    && (int)($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE)
        !== UPLOAD_ERR_NO_FILE
) {
    $file = $_FILES['payment_proof'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        renewalJsonResponse(
            false,
            'Payment proof upload failed. Please try again.',
            array(),
            422
        );
    }

    $fileSize = (int)($file['size'] ?? 0);

    if ($fileSize <= 0 || $fileSize > (5 * 1024 * 1024)) {
        renewalJsonResponse(
            false,
            'Payment proof must be smaller than 5 MB.',
            array(),
            422
        );
    }

    $temporaryPath = (string)($file['tmp_name'] ?? '');

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        renewalJsonResponse(
            false,
            'Invalid payment proof upload.',
            array(),
            422
        );
    }

    if (!class_exists('finfo')) {
        renewalJsonResponse(
            false,
            'Server fileinfo extension is not enabled.',
            array(),
            500
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($temporaryPath);

    $allowedMimeTypes = array(
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    );

    if (!isset($allowedMimeTypes[$mimeType])) {
        renewalJsonResponse(
            false,
            'Payment proof must be a JPG, PNG, WEBP, or PDF file.',
            array(),
            422
        );
    }

    $uploadDirectory =
        rtrim($_SERVER['DOCUMENT_ROOT'], '/')
        . '/onerp/app/public/uploads/subscription_renewals';

    if (
        !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0755, true)
        && !is_dir($uploadDirectory)
    ) {
        renewalJsonResponse(
            false,
            'Payment proof folder could not be created.',
            array(),
            500
        );
    }

    if (!is_writable($uploadDirectory)) {
        renewalJsonResponse(
            false,
            'Payment proof folder is not writable. Set folder permission to 755 or 775.',
            array(),
            500
        );
    }

    $extension = $allowedMimeTypes[$mimeType];

    try {
        $randomPart = bin2hex(random_bytes(8));
    } catch (Throwable $exception) {
        $randomPart = sha1(uniqid((string)$clientId, true));
    }

    $randomName = sprintf(
        'renewal_%d_%d_%s.%s',
        $clientId,
        time(),
        $randomPart,
        $extension
    );

    $uploadedAbsolutePath =
        $uploadDirectory . '/' . $randomName;

    $paymentProofWebPath =
        '/onerp/app/public/uploads/subscription_renewals/' . $randomName;

    if (!move_uploaded_file($temporaryPath, $uploadedAbsolutePath)) {
        renewalJsonResponse(
            false,
            'Payment proof could not be saved.',
            array(),
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Save renewal request
|--------------------------------------------------------------------------
*/

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn->begin_transaction();

    /*
    |--------------------------------------------------------------------------
    | Latest subscription and plan
    |--------------------------------------------------------------------------
    */

    $subscriptionStmt = $conn->prepare(
        "SELECT
            s.id AS subscription_id,
            s.client_id,
            s.plan_id,
            s.status,
            s.start_date,
            s.end_date,
            s.expires_at,
            COALESCE(p.name, 'Subscription Plan') AS plan_name,
            COALESCE(p.price, 0) AS plan_price,
            COALESCE(p.billing_cycle, 'monthly') AS billing_cycle
        FROM subscriptions s
        LEFT JOIN plans p
            ON p.id = s.plan_id
        WHERE s.client_id = ?
      ORDER BY
    CASE
        WHEN LOWER(TRIM(s.status)) = 'active' THEN 0
        WHEN LOWER(TRIM(s.status)) = 'trial' THEN 1
        ELSE 2
    END ASC,

    COALESCE(
        s.expires_at,
        CONCAT(s.end_date, ' 23:59:59')
    ) DESC,

    s.id DESC

LIMIT 1
FOR UPDATE"
    );

    $subscriptionStmt->bind_param('i', $clientId);
    $subscriptionStmt->execute();

    $subscription =
        $subscriptionStmt
            ->get_result()
            ->fetch_assoc();

    $subscriptionStmt->close();

    if (!$subscription) {
        throw new RuntimeException(
            'No subscription record was found for this client.'
        );
    }

    $subscriptionId =
        (int)$subscription['subscription_id'];

    $planId =
        (int)$subscription['plan_id'];

    $planName =
        trim((string)$subscription['plan_name']);

    $amount =
        (float)$subscription['plan_price'];

    if ($subscriptionId <= 0 || $planId <= 0) {
        throw new RuntimeException(
            'Subscription plan information is incomplete.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate pending request block
    |--------------------------------------------------------------------------
    */

    $pendingStmt = $conn->prepare(
        "SELECT id
         FROM subscription_renewal_requests
         WHERE client_id = ?
           AND subscription_id = ?
           AND status = 'pending'
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE"
    );

    $pendingStmt->bind_param(
        'ii',
        $clientId,
        $subscriptionId
    );

    $pendingStmt->execute();

    $existingPending =
        $pendingStmt
            ->get_result()
            ->fetch_assoc();

    $pendingStmt->close();

    if ($existingPending) {
        $conn->rollback();

        if (
            $uploadedAbsolutePath
            && is_file($uploadedAbsolutePath)
        ) {
            @unlink($uploadedAbsolutePath);
        }

        renewalJsonResponse(
            false,
            'A renewal request is already pending for this subscription.',
            array(
                'request_id' => (int)$existingPending['id'],
                'status'     => 'pending',
            ),
            409
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Insert pending request
    |--------------------------------------------------------------------------
    */

    $insertStmt = $conn->prepare(
        "INSERT INTO subscription_renewal_requests (
            client_id,
            subscription_id,
            plan_id,
            requested_by,
            amount,
            payment_method,
            payment_reference,
            payment_proof,
            status,
            client_note,
            requested_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW()
        )"
    );

    $insertStmt->bind_param(
        'iiiidssss',
        $clientId,
        $subscriptionId,
        $planId,
        $userId,
        $amount,
        $paymentMethod,
        $paymentReference,
        $paymentProofWebPath,
        $clientNote
    );

    $insertStmt->execute();

    $requestId =
        (int)$insertStmt->insert_id;

    $insertStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Client bell confirmation notification
    |--------------------------------------------------------------------------
    */

    $notificationMessage = sprintf(
        'Your subscription renewal request #%d for %s has been submitted and is awaiting approval.',
        $requestId,
        $planName
    );

    $notificationType =
        'subscription_renewal_requested';

    $referenceKey =
        'subscription_renewal_requested_' . $requestId;

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
        ) VALUES (?, ?, ?, ?, ?, 0, NOW())"
    );

    $notificationStmt->bind_param(
        'issss',
        $userId,
        $notificationMessage,
        $notificationType,
        $referenceKey,
        $notificationLink
    );

    $notificationStmt->execute();
    $notificationStmt->close();

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | Successful request ke baad CSRF token rotate
    |--------------------------------------------------------------------------
    */

    $_SESSION['subscription_renewal_csrf'] =
        bin2hex(random_bytes(32));

    renewalJsonResponse(
        true,
        'Renewal request submitted successfully. Admin approval is pending.',
        array(
            'request_id' => $requestId,
            'status'     => 'pending',
            'amount'     => $amount,
            'plan_name'  => $planName,
            'csrf_token' =>
                $_SESSION['subscription_renewal_csrf'],
        )
    );
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        /* No action required. */
    }

    if (
        $uploadedAbsolutePath
        && is_file($uploadedAbsolutePath)
    ) {
        @unlink($uploadedAbsolutePath);
    }

    error_log(
        'Subscription renewal request error: '
        . $exception->getMessage()
    );

    $safeMessage =
        $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Renewal request could not be submitted. Please try again.';

    renewalJsonResponse(
        false,
        $safeMessage,
        array(),
        500
    );
}
