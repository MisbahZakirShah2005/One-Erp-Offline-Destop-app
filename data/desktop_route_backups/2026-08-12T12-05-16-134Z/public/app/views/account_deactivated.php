<?php

/*
|--------------------------------------------------------------------------
| ONEERP Account Deactivated Screen
|--------------------------------------------------------------------------
| Inactive user/client ki session destroy nahi hoti.
| Admin account active kare to "Check Access Again" se access restore hoga.
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
| No-cache security headers
|--------------------------------------------------------------------------
*/

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/*
|--------------------------------------------------------------------------
| Login validation
|--------------------------------------------------------------------------
*/

$userId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$roleId = isset($_SESSION['role_id'])
    ? (int) $_SESSION['role_id']
    : 0;

if ($userId <= 0) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Super Admin should never stay on this page
|--------------------------------------------------------------------------
*/

if ($roleId === 1) {
    header('Location: /public/app/views/dashboard_view.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
| account_deactivated.php ko account_access_guard.php ki excluded list mein
| zaroor rakhein.
|--------------------------------------------------------------------------
*/

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('oneerpLockEscape')) {
    function oneerpLockEscape($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('oneerpLockColumnExists')) {
    function oneerpLockColumnExists($conn, $tableName, $columnName)
    {
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ss',
            $tableName,
            $columnName
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('oneerpFormatLockDate')) {
    function oneerpFormatLockDate($dateValue)
    {
        $dateValue = trim((string) $dateValue);

        if ($dateValue === '') {
            return 'Not available';
        }

        $timestamp = strtotime($dateValue);

        if ($timestamp === false) {
            return $dateValue;
        }

        return date('M d, Y - h:i A', $timestamp);
    }
}

/*
|--------------------------------------------------------------------------
| Check optional status audit columns
|--------------------------------------------------------------------------
| Agar columns abhi create nahi hui to page fatal error nahi dega.
|--------------------------------------------------------------------------
*/

$hasUserChangedBy = oneerpLockColumnExists(
    $conn,
    'users',
    'status_changed_by'
);

$hasUserChangedAt = oneerpLockColumnExists(
    $conn,
    'users',
    'status_changed_at'
);

$hasClientChangedBy = oneerpLockColumnExists(
    $conn,
    'clients',
    'status_changed_by'
);

$hasClientChangedAt = oneerpLockColumnExists(
    $conn,
    'clients',
    'status_changed_at'
);

$userChangedBySql = $hasUserChangedBy
    ? 'u.status_changed_by'
    : 'NULL';

$userChangedAtSql = $hasUserChangedAt
    ? 'u.status_changed_at'
    : 'NULL';

$clientChangedBySql = $hasClientChangedBy
    ? 'c.status_changed_by'
    : 'NULL';

$clientChangedAtSql = $hasClientChangedAt
    ? 'c.status_changed_at'
    : 'NULL';

/*
|--------------------------------------------------------------------------
| Current user and company information
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        u.id,
        u.client_id,
        u.name,
        u.email,
        u.status AS user_status,

        {$userChangedBySql} AS user_changed_by,
        {$userChangedAtSql} AS user_changed_at,

        c.business_name,
        c.contact_number,
        c.status AS client_status,

        {$clientChangedBySql} AS client_changed_by,
        {$clientChangedAtSql} AS client_changed_at

    FROM users u

    LEFT JOIN clients c
        ON c.id = u.client_id

    WHERE u.id = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

$account = array();

if ($stmt) {
    $stmt->bind_param('i', $userId);

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        if ($result) {
            $account = $result->fetch_assoc() ?: array();
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Session lock fallback
|--------------------------------------------------------------------------
| User record missing ho ya query fail ho to guard ka stored data use hoga.
|--------------------------------------------------------------------------
*/

$sessionLock = isset($_SESSION['account_access_lock'])
    && is_array($_SESSION['account_access_lock'])
        ? $_SESSION['account_access_lock']
        : array();

$accountName = trim(
    (string) (
        isset($account['name'])
            ? $account['name']
            : (
                isset($sessionLock['user_name'])
                    ? $sessionLock['user_name']
                    : (
                        isset($_SESSION['name'])
                            ? $_SESSION['name']
                            : 'Account User'
                    )
            )
    )
);

$accountEmail = trim(
    (string) (
        isset($account['email'])
            ? $account['email']
            : (
                isset($sessionLock['user_email'])
                    ? $sessionLock['user_email']
                    : ''
            )
    )
);

$businessName = trim(
    (string) (
        isset($account['business_name'])
            ? $account['business_name']
            : (
                isset($sessionLock['business_name'])
                    ? $sessionLock['business_name']
                    : 'ONEERP Client'
            )
    )
);

/*
|--------------------------------------------------------------------------
| ONEERP Support Contact
|--------------------------------------------------------------------------
*/

$supportPhoneDisplay = '+92 335 2532300';
$supportPhoneLink    = '+923352532300';
$userStatus = strtolower(
    trim(
        (string) (
            isset($account['user_status'])
                ? $account['user_status']
                : (
                    isset($sessionLock['user_status'])
                        ? $sessionLock['user_status']
                        : 'missing'
                )
        )
    )
);

$clientStatus = strtolower(
    trim(
        (string) (
            isset($account['client_status'])
                ? $account['client_status']
                : (
                    isset($sessionLock['client_status'])
                        ? $sessionLock['client_status']
                        : 'missing'
                )
        )
    )
);

/*
|--------------------------------------------------------------------------
| If account is active again, redirect immediately
|--------------------------------------------------------------------------
*/

if (
    !empty($account) &&
    $userStatus === 'active' &&
    $clientStatus === 'active'
) {
    unset($_SESSION['account_access_lock']);

    header('Location: /public/app/views/dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Determine lock source
|--------------------------------------------------------------------------
*/

$lockType = 'client';

if ($userStatus !== 'active') {
    $lockType = 'user';
} elseif ($clientStatus !== 'active') {
    $lockType = 'client';
}

/*
|--------------------------------------------------------------------------
| Lock screen content
|--------------------------------------------------------------------------
*/

$pageTitle = 'Your account has been deactivated';
$mainMessage = 'Your account has been deactivated. You can no longer access ONEERP.';
$statusLabel = 'Inactive';
$restrictionLabel = 'User Account';

$changedById = null;
$changedAt = null;

if ($lockType === 'user') {
    $restrictionLabel = 'User Account';

    $changedById = isset($account['user_changed_by'])
        ? (int) $account['user_changed_by']
        : null;

    $changedAt = isset($account['user_changed_at'])
        ? $account['user_changed_at']
        : null;

    if ($userStatus === 'suspended') {
        $pageTitle = 'Your account has been suspended';
        $mainMessage = 'Your user account has been suspended. You can no longer access ONEERP.';
        $statusLabel = 'Suspended';
    } elseif ($userStatus === 'deleted' || $userStatus === 'missing') {
        $pageTitle = 'Your account is unavailable';
        $mainMessage = 'Your user account is no longer available.';
        $statusLabel = 'Unavailable';
    } else {
        $pageTitle = 'Your account has been deactivated';
        $mainMessage = 'Your user account has been deactivated. You can no longer access ONEERP.';
        $statusLabel = 'Inactive';
    }
} else {
    $restrictionLabel = 'Company Account';

    $changedById = isset($account['client_changed_by'])
        ? (int) $account['client_changed_by']
        : null;

    $changedAt = isset($account['client_changed_at'])
        ? $account['client_changed_at']
        : null;

    if ($clientStatus === 'suspended') {
        $pageTitle = 'Your company account has been suspended';
        $mainMessage = 'Your company account has been suspended. You can no longer access ONEERP.';
        $statusLabel = 'Suspended';
    } elseif (
        $clientStatus === 'deleted' ||
        $clientStatus === 'missing'
    ) {
        $pageTitle = 'Your company account is unavailable';
        $mainMessage = 'Your company account is no longer available.';
        $statusLabel = 'Unavailable';
    } else {
        $pageTitle = 'Your company account has been deactivated';
        $mainMessage = 'Your company account has been deactivated. You can no longer access ONEERP.';
        $statusLabel = 'Inactive';
    }
}

/*
|--------------------------------------------------------------------------
| Changed by admin information
|--------------------------------------------------------------------------
*/

$changedByName = 'System Administrator';
$changedByEmail = '';

if (!empty($changedById)) {
    $changerStmt = $conn->prepare("
        SELECT name, email
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if ($changerStmt) {
        $changerStmt->bind_param('i', $changedById);

        if ($changerStmt->execute()) {
            $changerResult = $changerStmt->get_result();

            if ($changerResult) {
                $changer = $changerResult->fetch_assoc();

                if ($changer) {
                    $changedByName = trim(
                        (string) $changer['name']
                    );

                    $changedByEmail = trim(
                        (string) $changer['email']
                    );

                    if ($changedByName === '') {
                        $changedByName = 'System Administrator';
                    }
                }
            }
        }

        $changerStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Fallback lock time
|--------------------------------------------------------------------------
*/

if (empty($changedAt) && !empty($sessionLock['locked_at'])) {
    $changedAt = $sessionLock['locked_at'];
}

$formattedChangedAt = oneerpFormatLockDate($changedAt);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= oneerpLockEscape($pageTitle) ?> | ONEERP
    </title>

    <style>
        :root {
            --page-bg: #030610;
            --page-bg-two: #080d1d;
            --card-bg: rgba(10, 16, 35, 0.88);
            --card-bg-two: rgba(17, 24, 48, 0.74);
            --border: rgba(139, 92, 246, 0.28);
            --border-soft: rgba(148, 163, 184, 0.14);
            --text: #f8fafc;
            --muted: #9ca3af;
            --purple: #a855f7;
            --purple-two: #6d4cff;
            --blue: #3b82f6;
            --danger: #fb7185;
            --success: #22c55e;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
        }

        body {
            min-height: 100vh;
            padding: 34px 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            color: var(--text);
            font-family:
                Inter,
                "Segoe UI",
                Arial,
                sans-serif;
            background:
                radial-gradient(
                    circle at 12% 12%,
                    rgba(124, 58, 237, 0.24),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 88% 10%,
                    rgba(59, 130, 246, 0.14),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 80% 90%,
                    rgba(168, 85, 247, 0.12),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    var(--page-bg) 0%,
                    var(--page-bg-two) 48%,
                    #09051a 100%
                );
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: 0.3;
            background:
                linear-gradient(
                    90deg,
                    rgba(255, 255, 255, 0.03) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    180deg,
                    rgba(255, 255, 255, 0.025) 1px,
                    transparent 1px
                );
            background-size: 44px 44px;
        }

        .lock-wrapper {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 980px;
        }

        .lock-card {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 28px;
            background:
                radial-gradient(
                    circle at 18% 0%,
                    rgba(124, 58, 237, 0.22),
                    transparent 38%
                ),
                linear-gradient(
                    135deg,
                    var(--card-bg),
                    var(--card-bg-two)
                );
            backdrop-filter: blur(24px) saturate(140%);
            -webkit-backdrop-filter: blur(24px) saturate(140%);
            box-shadow:
                0 35px 100px rgba(0, 0, 0, 0.58),
                0 0 70px rgba(124, 58, 237, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        .lock-card::before {
            content: "";
            position: absolute;
            width: 520px;
            height: 520px;
            left: -280px;
            top: -300px;
            pointer-events: none;
            border-radius: 50%;
            background:
                radial-gradient(
                    circle,
                    rgba(168, 85, 247, 0.2),
                    transparent 68%
                );
        }

        .lock-header {
            padding: 46px 46px 34px;
            text-align: center;
            border-bottom: 1px solid var(--border-soft);
        }

        .lock-icon {
            width: 78px;
            height: 78px;
            margin: 0 auto 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 24px;
            color: #fecdd3;
            border: 1px solid rgba(251, 113, 133, 0.34);
            background:
                linear-gradient(
                    135deg,
                    rgba(244, 63, 94, 0.18),
                    rgba(124, 58, 237, 0.13)
                );
            box-shadow:
                0 18px 42px rgba(244, 63, 94, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }

        .lock-icon svg {
            width: 38px;
            height: 38px;
        }

        .lock-title {
            max-width: 720px;
            margin: 0 auto 14px;
            color: #ffffff;
            font-size: clamp(30px, 4vw, 48px);
            font-weight: 900;
            line-height: 1.08;
            letter-spacing: -0.04em;
            text-shadow:
                0 0 32px rgba(124, 58, 237, 0.3);
        }

        .lock-description {
            max-width: 720px;
            margin: 0 auto;
            color: #cbd5e1;
            font-size: 15px;
            font-weight: 650;
            line-height: 1.8;
        }

        .status-chip {
            margin-top: 18px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            color: #fecdd3;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border: 1px solid rgba(251, 113, 133, 0.28);
            background: rgba(244, 63, 94, 0.12);
        }

        .status-chip::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--danger);
            box-shadow:
                0 0 12px rgba(251, 113, 133, 0.8);
        }

        .lock-body {
            padding: 30px 46px 42px;
        }

        .details-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .detail-box {
            min-height: 108px;
            padding: 18px 20px;
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            background:
                linear-gradient(
                    135deg,
                    rgba(30, 41, 59, 0.58),
                    rgba(15, 23, 42, 0.42)
                );
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.035);
        }

        .detail-label {
            margin-bottom: 9px;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .detail-value {
            color: #f8fafc;
            font-size: 15px;
            font-weight: 850;
            line-height: 1.55;
            word-break: break-word;
        }

        .detail-secondary {
            margin-top: 2px;
            color: #aeb9ca;
            font-size: 13px;
            font-weight: 650;
        }

        .help-panel {
            margin-top: 18px;
            padding: 21px;
            border-radius: 18px;
            border: 1px solid rgba(124, 58, 237, 0.3);
            background:
                linear-gradient(
                    135deg,
                    rgba(76, 29, 149, 0.22),
                    rgba(30, 27, 75, 0.2)
                );
        }

        .help-title {
            margin: 0 0 7px;
            color: #f5f3ff;
            font-size: 16px;
            font-weight: 900;
        }

        .help-text {
            margin: 0;
            color: #b9c2d0;
            font-size: 13px;
            font-weight: 650;
            line-height: 1.7;
        }

        .contact-chip {
            margin-top: 13px;
            display: inline-flex;
            align-items: center;
            min-height: 38px;
            padding: 0 13px;
            border-radius: 11px;
            color: #ddd6fe;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            border: 1px solid rgba(139, 92, 246, 0.28);
            background: rgba(124, 58, 237, 0.12);
        }

        .contact-chip:hover {
            color: #ffffff;
            border-color: rgba(167, 139, 250, 0.5);
            background: rgba(124, 58, 237, 0.2);
        }

        .feedback {
            display: none;
            margin-top: 16px;
            padding: 13px 15px;
            border-radius: 13px;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.6;
        }

        .feedback.show {
            display: block;
        }

        .feedback.error {
            color: #fecaca;
            border: 1px solid rgba(239, 68, 68, 0.26);
            background: rgba(239, 68, 68, 0.12);
        }

        .feedback.success {
            color: #bbf7d0;
            border: 1px solid rgba(34, 197, 94, 0.26);
            background: rgba(34, 197, 94, 0.12);
        }

        .feedback.info {
            color: #bfdbfe;
            border: 1px solid rgba(59, 130, 246, 0.26);
            background: rgba(59, 130, 246, 0.12);
        }

        .button-row {
            margin-top: 20px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
        }

        .action-button {
            min-height: 54px;
            padding: 0 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border-radius: 15px;
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            font-weight: 900;
            text-decoration: none;
            transition:
                transform 0.22s ease,
                filter 0.22s ease,
                box-shadow 0.22s ease;
        }

        .action-button:hover {
            transform: translateY(-2px);
        }

        .action-button:disabled {
            cursor: wait;
            opacity: 0.72;
            transform: none;
        }

        .check-button {
            color: #ffffff;
            background:
                linear-gradient(
                    135deg,
                    var(--purple),
                    var(--purple-two),
                    var(--blue)
                );
            box-shadow:
                0 18px 38px rgba(109, 76, 255, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.14);
        }

        .check-button:hover {
            filter: brightness(1.08);
            box-shadow:
                0 22px 46px rgba(109, 76, 255, 0.38);
        }

        .signout-button {
            min-width: 132px;
            color: #fecdd3;
            border: 1px solid rgba(251, 113, 133, 0.28);
            background: rgba(244, 63, 94, 0.11);
        }

        .signout-button:hover {
            color: #ffffff;
            background: rgba(244, 63, 94, 0.18);
        }

        .footer-note {
            margin-top: 20px;
            color: #64748b;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.6;
        }

        .spinner {
            width: 17px;
            height: 17px;
            display: none;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            animation: spin 0.7s linear infinite;
        }

        .checking .spinner {
            display: inline-block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 720px) {
            body {
                padding: 18px 12px;
                align-items: flex-start;
            }

            .lock-card {
                border-radius: 22px;
            }

            .lock-header {
                padding: 34px 20px 28px;
            }

            .lock-body {
                padding: 22px 18px 28px;
            }

            .lock-title {
                font-size: 31px;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .detail-box {
                min-height: auto;
            }

            .button-row {
                grid-template-columns: 1fr;
            }

            .signout-button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="lock-wrapper">
    <section class="lock-card">

        <header class="lock-header">

            <div class="lock-icon" aria-hidden="true">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <rect
                        x="4"
                        y="10"
                        width="16"
                        height="11"
                        rx="2.5"
                    ></rect>

                    <path
                        d="M8 10V7a4 4 0 0 1 8 0v3"
                    ></path>

                    <path d="M12 14v3"></path>
                </svg>
            </div>

            <h1 class="lock-title">
                <?= oneerpLockEscape($pageTitle) ?>
            </h1>

            <p class="lock-description">
                <?= oneerpLockEscape($mainMessage) ?>
                Contact your administrator if you believe this was a mistake.
            </p>

            <div class="status-chip" id="accountStatusChip">
                <?= oneerpLockEscape($statusLabel) ?>
            </div>

        </header>

        <main class="lock-body">

            <div class="details-grid">

                <article class="detail-box">
                    <div class="detail-label">Account</div>

                    <div class="detail-value">
                        <?= oneerpLockEscape($accountName) ?>
                    </div>

                    <div class="detail-secondary">
                        <?= oneerpLockEscape(
                            $accountEmail !== ''
                                ? $accountEmail
                                : 'Email not available'
                        ) ?>
                    </div>
                </article>

                <article class="detail-box">
                    <div class="detail-label">Company</div>

                    <div class="detail-value">
                        <?= oneerpLockEscape($businessName) ?>
                    </div>

                    <?php if ($contactNumber !== ''): ?>
                        <div class="detail-secondary">
                            <?= oneerpLockEscape($contactNumber) ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="detail-box">
                    <div class="detail-label">Restriction</div>

                    <div class="detail-value">
                        <?= oneerpLockEscape($restrictionLabel) ?>
                    </div>

                    <div class="detail-secondary">
                        Status:
                        <?= oneerpLockEscape($statusLabel) ?>
                    </div>
                </article>

                <article class="detail-box">
                    <div class="detail-label">Changed By</div>

                    <div class="detail-value">
                        <?= oneerpLockEscape($changedByName) ?>
                    </div>

                    <?php if ($changedByEmail !== ''): ?>
                        <div class="detail-secondary">
                            <?= oneerpLockEscape($changedByEmail) ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="detail-box">
                    <div class="detail-label">Changed At</div>

                    <div class="detail-value">
                        <?= oneerpLockEscape($formattedChangedAt) ?>
                    </div>
                </article>

                <article class="detail-box">
                    <div class="detail-label">Access State</div>

                    <div class="detail-value">
                        Access Frozen
                    </div>

                    <div class="detail-secondary">
                        Pages, forms and live operations are blocked.
                    </div>
                </article>

            </div>

            <section class="help-panel">
                <h2 class="help-title">
                    Need access restored?
                </h2>

                <p class="help-text">
                    Contact your administrator. When your account is
                    reactivated, press “Check Access Again” to continue
                    without signing in again.
                </p>

       <a
    class="contact-chip"
    href="tel:<?= oneerpLockEscape($supportPhoneLink) ?>"
>
    <?= oneerpLockEscape($supportPhoneDisplay) ?>
</a>
            </section>

            <div
                class="feedback"
                id="accessFeedback"
                role="status"
                aria-live="polite"
            ></div>

            <div class="button-row">

                <button
                    type="button"
                    class="action-button check-button"
                    id="checkAccessButton"
                >
                    <span class="spinner" aria-hidden="true"></span>

                    <span id="checkAccessText">
                        Check Access Again
                    </span>
                </button>

                <a
                    href="/onerp/app/app/auth/logout.php"
                    class="action-button signout-button"
                >
                    Sign Out
                </a>

            </div>

            <p class="footer-note">
                This restriction is enforced on direct URLs, page loads,
                form submissions and live ONEERP sessions.
            </p>

        </main>
    </section>
</div>

<script>
(function () {
    'use strict';

    const checkButton =
        document.getElementById('checkAccessButton');

    const checkText =
        document.getElementById('checkAccessText');

    const feedback =
        document.getElementById('accessFeedback');

    const statusChip =
        document.getElementById('accountStatusChip');

    let requestRunning = false;

    function showFeedback(type, message) {
        feedback.className = 'feedback show ' + type;
        feedback.textContent = message;
    }

    function setChecking(checking) {
        requestRunning = checking;
        checkButton.disabled = checking;

        if (checking) {
            checkButton.classList.add('checking');
            checkText.textContent = 'Checking Access...';
        } else {
            checkButton.classList.remove('checking');
            checkText.textContent = 'Check Access Again';
        }
    }

    async function checkAccountAccess() {
        if (requestRunning) {
            return;
        }

        setChecking(true);

        showFeedback(
            'info',
            'Checking your current account status...'
        );

        try {
            const endpoint =
                '/onerp/app/public/app/views/ajax_check_account_access.php'
                + '?_='
                + Date.now();

            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const rawText = await response.text();

            let data;

            try {
                data = JSON.parse(rawText);
            } catch (jsonError) {
                throw new Error(
                    'Invalid response received from the server.'
                );
            }

            if (data.active === true) {
                showFeedback(
                    'success',
                    'Access restored successfully. Redirecting...'
                );

                statusChip.textContent = 'Active';

                const redirectUrl = data.is_superadmin === true
                    ? '/onerp/app/public/app/views/dashboard_view.php'
                    : '/onerp/app/public/app/views/dashboard.php';

                window.setTimeout(function () {
                    window.location.replace(redirectUrl);
                }, 500);

                return;
            }

            if (data.reason === 'session_expired') {
                window.location.replace(
                    '/onerp/app/public/app/views/login_view.php'
                );

                return;
            }

            statusChip.textContent =
                data.status
                    ? String(data.status).toUpperCase()
                    : 'INACTIVE';

            showFeedback(
                'error',
                data.message
                    || 'Your account is still inactive. Contact your administrator.'
            );

        } catch (error) {
            showFeedback(
                'error',
                error && error.message
                    ? error.message
                    : 'Unable to verify access right now. Please try again.'
            );
        } finally {
            setChecking(false);
        }
    }

    checkButton.addEventListener(
        'click',
        checkAccountAccess
    );

    /*
    |--------------------------------------------------------------------------
    | Browser tab dobara open ho to access automatically verify karein
    |--------------------------------------------------------------------------
    */

    document.addEventListener('visibilitychange', function () {
        if (
            document.visibilityState === 'visible' &&
            !requestRunning
        ) {
            checkAccountAccess();
        }
    });
})();
</script>

</body>
</html>