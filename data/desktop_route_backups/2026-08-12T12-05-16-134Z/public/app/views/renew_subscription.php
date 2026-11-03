<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');




/*
|--------------------------------------------------------------------------
| Login guard
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$clientId = (int)($_SESSION['client_id'] ?? 0);
$userId   = (int)($_SESSION['user_id'] ?? 0);

if ($clientId <= 0) {
    http_response_code(403);
    exit('Client session not found. Please log in again.');
}


/*
|--------------------------------------------------------------------------
| Subscription renewal CSRF token
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['subscription_renewal_csrf'])) {
    $_SESSION['subscription_renewal_csrf'] =
        bin2hex(random_bytes(32));
}

$renewalCsrfToken =
    (string)$_SESSION['subscription_renewal_csrf'];

/*
|--------------------------------------------------------------------------
| ONEERP support WhatsApp
|--------------------------------------------------------------------------
| Country code ke sath sirf digits rakhein, example: 923001234567
| Blank rehne par WhatsApp button disabled rahega.
|--------------------------------------------------------------------------
*/
$supportWhatsApp = '+92 335 2532300';

if (!function_exists('renewPageEscape')) {
    function renewPageEscape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Current subscription
|--------------------------------------------------------------------------
*/
$subscription = null;
$pageError = '';

$sql = "
    SELECT
        s.id AS subscription_id,
        s.client_id,
        s.plan_id,
        s.status,
        s.start_date,
        s.end_date,
        COALESCE(
            s.expires_at,
            CONCAT(s.end_date, ' 23:59:59')
        ) AS calculated_expires_at,
        COALESCE(p.name, 'Subscription Plan') AS plan_name,
        COALESCE(p.price, 0) AS plan_price,
        COALESCE(p.billing_cycle, 'monthly') AS billing_cycle,
        COALESCE(p.industry, 'retail') AS plan_industry
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
        NULLIF(
            s.expires_at,
            '0000-00-00 00:00:00'
        ),
        CONCAT(
            s.end_date,
            ' 23:59:59'
        )
    ) DESC,

    s.id DESC

LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    $pageError = 'Subscription details could not be loaded.';
    error_log('Renew subscription prepare error: ' . $conn->error);
} else {
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $subscription = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| View data
|--------------------------------------------------------------------------
*/
$subscriptionId = 0;
$planName        = 'No active plan';
$planPrice       = 0.00;
$billingCycle    = 'monthly';
$billingLabel    = 'Monthly';
$planIndustry    = 'Retail';
$status          = 'not_found';
$statusLabel     = 'Not Available';
$statusClass     = 'status-neutral';
$startDate       = '—';
$endDate         = '—';
$expiresAt       = '';
$expiresDisplay  = '—';
$expiresIso      = '';
$remainingSecs   = 0;
$isExpired       = false;
$renewalPeriod   = '1 month';

if ($subscription) {
    $subscriptionId = (int)$subscription['subscription_id'];
    $planName        = trim((string)$subscription['plan_name']);
    $planPrice       = (float)$subscription['plan_price'];
    $billingCycle    = strtolower(trim((string)$subscription['billing_cycle']));
    $planIndustry    = ucwords(str_replace(['_', '-'], ' ', (string)$subscription['plan_industry']));
    $status          = strtolower(trim((string)$subscription['status']));
    $startDateValue  = trim((string)$subscription['start_date']);
    $endDateValue    = trim((string)$subscription['end_date']);
    $expiresAt       = trim((string)$subscription['calculated_expires_at']);

    $billingLabel  = $billingCycle === 'yearly' ? 'Yearly' : 'Monthly';
    $renewalPeriod = $billingCycle === 'yearly' ? '1 year' : '1 month';

    if ($startDateValue !== '') {
        $startTimestamp = strtotime($startDateValue);
        $startDate = $startTimestamp ? date('d M Y', $startTimestamp) : $startDateValue;
    }

    if ($endDateValue !== '') {
        $endTimestamp = strtotime($endDateValue);
        $endDate = $endTimestamp ? date('d M Y', $endTimestamp) : $endDateValue;
    }

    if ($expiresAt !== '') {
        try {
            $timezone = new DateTimeZone('Asia/Karachi');
            $expiryDateTime = new DateTime($expiresAt, $timezone);
            $currentDateTime = new DateTime('now', $timezone);

            $remainingSecs  = $expiryDateTime->getTimestamp() - $currentDateTime->getTimestamp();
            $isExpired      = $remainingSecs <= 0;
            $expiresIso     = $expiryDateTime->format(DateTime::ATOM);
            $expiresDisplay = $expiryDateTime->format('d M Y, h:i A');
        } catch (Throwable $exception) {
            error_log('Renew subscription date error: ' . $exception->getMessage());
        }
    }

    if ($isExpired) {
        $statusLabel = 'Expired';
        $statusClass = 'status-expired';
    } elseif ($status === 'trial') {
        $statusLabel = 'Trial';
        $statusClass = 'status-trial';
    } elseif ($status === 'suspended') {
        $statusLabel = 'Suspended';
        $statusClass = 'status-suspended';
    } elseif ($status === 'active') {
        $statusLabel = 'Active';
        $statusClass = 'status-active';
    } else {
        $statusLabel = $status !== '' ? ucwords($status) : 'Not Available';
        $statusClass = 'status-neutral';
    }
}

$priceDisplay = 'PKR ' . number_format($planPrice, 0);
/*
|--------------------------------------------------------------------------
| Live subscription status endpoint for topbar
|--------------------------------------------------------------------------
| Topbar is endpoint ko poll karega aur successful renewal ke baad
| Renew Now banner ko automatically remove karega.
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['ajax_subscription_state'])
    && (string)$_GET['ajax_subscription_state'] === '1'
) {
    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    header('Pragma: no-cache');

    /*
    |--------------------------------------------------------------------------
    | Banner sirf expiry ke last 7 days mein show hota hai
    |--------------------------------------------------------------------------
    */

    $alertWindowSeconds =
        7 * 24 * 60 * 60;

    $hasValidSubscription = (
        !empty($subscription)
        && $subscriptionId > 0
        && $expiresIso !== ''
    );

    /*
    |--------------------------------------------------------------------------
    | Renew banner tab hide hoga jab:
    | - subscription record mil gaya ho
    | - status active ho
    | - subscription expired na ho
    | - expiry 7 days se zyada door ho
    |--------------------------------------------------------------------------
    */

    $hideRenewBanner = (
        $hasValidSubscription
        && $status === 'active'
        && !$isExpired
        && $remainingSecs > $alertWindowSeconds
    );

    $showRenewBanner = (
        $hasValidSubscription
        && (
            $isExpired
            || $remainingSecs <= $alertWindowSeconds
        )
    );

    echo json_encode(
        [
            'success' => true,

            'subscription_found' =>
                $hasValidSubscription,

            'subscription_id' =>
                $subscriptionId,

            'status' =>
                $status,

            'is_active' => (
                $status === 'active'
                && !$isExpired
            ),

            'is_expired' =>
                $isExpired,

            'show_renew_banner' =>
                $showRenewBanner,

            'hide_renew_banner' =>
                $hideRenewBanner,

            'remaining_seconds' =>
                max(0, (int)$remainingSecs),

            'expires_at' =>
                $expiresAt,

            'expires_iso' =>
                $expiresIso,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

$requestMessage = implode("\n", [
    'Assalam-o-Alaikum, mujhe ONEERP subscription renew karwani hai.',
    'Client ID: ' . $clientId,
    'Subscription ID: ' . ($subscriptionId > 0 ? $subscriptionId : 'N/A'),
    'Plan: ' . $planName,
    'Billing: ' . $billingLabel,
    'Current Expiry: ' . $expiresDisplay,
]);

$supportWhatsAppDigits = preg_replace('/\D+/', '', $supportWhatsApp);
$whatsAppUrl = $supportWhatsAppDigits !== ''
    ? 'https://wa.me/' . $supportWhatsAppDigits . '?text=' . rawurlencode($requestMessage)
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Renew Subscription - ONEERP</title>

    <?php if (is_file(__DIR__ . '/partials/head.php')): ?>
        <?php include __DIR__ . '/partials/head.php'; ?>
    <?php endif; ?>

    <style>
        * {
            box-sizing: border-box;
        }

        body.renew-subscription-page {
            margin: 0;
            background:
                radial-gradient(circle at 78% 8%, rgba(124, 58, 237, 0.12), transparent 28%),
                #f4f6fb;
            color: #172033;
        }

        .renew-subscription-page .container-scroll {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .renew-subscription-page .main-panel {
            flex: 1;
            min-width: 0;
        }

        .renew-subscription-page .content-wrapper {
            padding: 24px;
        }

        .renew-page-shell {
            max-width: 1180px;
            margin: 0 auto;
        }

        .renew-page-header {
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
            padding: 24px 26px;
            border: 1px solid rgba(124, 58, 237, 0.14);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 18px 45px rgba(30, 41, 59, 0.09);
        }

        .renew-page-header::after {
            content: '';
            position: absolute;
            right: -50px;
            top: -80px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.18), rgba(59, 130, 246, 0.08));
            pointer-events: none;
        }

        .renew-heading {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .renew-heading-icon {
            width: 54px;
            height: 54px;
            flex: 0 0 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            color: #ffffff;
            font-size: 24px;
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            box-shadow: 0 12px 24px rgba(124, 58, 237, 0.28);
        }

        .renew-heading h1 {
            margin: 0;
            font-size: 25px;
            line-height: 1.2;
            font-weight: 900;
            color: #111827;
        }

        .renew-heading p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
        }

        .back-dashboard-btn {
            position: relative;
            z-index: 1;
            min-height: 42px;
            padding: 9px 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid #ddd6fe;
            border-radius: 12px;
            color: #5b21b6 !important;
            background: #ffffff;
            text-decoration: none !important;
            font-size: 13px;
            font-weight: 800;
            transition: 0.2s ease;
        }

        .back-dashboard-btn:hover {
            transform: translateY(-1px);
            border-color: #a78bfa;
            box-shadow: 0 10px 24px rgba(91, 33, 182, 0.12);
        }

        .renew-alert {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid #fecaca;
            color: #991b1b;
            background: #fff1f2;
            font-size: 13px;
            font-weight: 700;
        }

        .renew-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(310px, 0.65fr);
            gap: 20px;
        }

        .renew-card {
            overflow: hidden;
            border: 1px solid #e6e9f2;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 18px 45px rgba(30, 41, 59, 0.08);
        }

        .renew-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 19px 21px;
            border-bottom: 1px solid #edf0f6;
        }

        .renew-card-header h2 {
            margin: 0;
            color: #111827;
            font-size: 16px;
            font-weight: 900;
        }

        .renew-card-body {
            padding: 21px;
        }

        .subscription-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .subscription-status::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-active {
            color: #047857;
            background: #d1fae5;
        }

        .status-trial {
            color: #6d28d9;
            background: #ede9fe;
        }

        .status-expired,
        .status-suspended {
            color: #b91c1c;
            background: #fee2e2;
        }

        .status-neutral {
            color: #475569;
            background: #e2e8f0;
        }

        .plan-hero {
            position: relative;
            overflow: hidden;
            padding: 22px;
            border-radius: 18px;
            color: #ffffff;
            background:
                radial-gradient(circle at 90% 10%, rgba(255,255,255,0.20), transparent 28%),
                linear-gradient(135deg, #111b47 0%, #3914a0 58%, #6d28d9 100%);
            box-shadow: 0 16px 32px rgba(76, 29, 149, 0.23);
        }

        .plan-hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
        }

        .plan-label {
            margin: 0 0 6px;
            color: rgba(255,255,255,0.72);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .plan-name {
            margin: 0;
            color: #ffffff;
            font-size: 25px;
            font-weight: 900;
        }

        .plan-industry {
            margin-top: 7px;
            color: rgba(255,255,255,0.78);
            font-size: 12px;
            font-weight: 700;
        }

        .plan-price {
            text-align: right;
            white-space: nowrap;
        }

        .plan-price strong {
            display: block;
            color: #ffffff;
            font-size: 24px;
            line-height: 1.1;
            font-weight: 900;
        }

        .plan-price span {
            display: block;
            margin-top: 5px;
            color: rgba(255,255,255,0.72);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .detail-box {
            padding: 15px;
            border: 1px solid #e8ebf3;
            border-radius: 14px;
            background: #f8fafc;
        }

        .detail-box span {
            display: block;
            color: #7b879c;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .detail-box strong {
            display: block;
            margin-top: 6px;
            color: #172033;
            font-size: 13px;
            font-weight: 900;
        }

        .countdown-panel {
            margin-top: 16px;
            padding: 17px;
            border: 1px solid #ddd6fe;
            border-radius: 16px;
            background: linear-gradient(135deg, #faf5ff, #f5f3ff);
        }

        .countdown-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .countdown-title strong {
            color: #4c1d95;
            font-size: 13px;
            font-weight: 900;
        }

        .countdown-title span {
            color: #7c3aed;
            font-size: 10px;
            font-weight: 800;
        }

        .renew-countdown {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }

        .renew-time-box {
            min-height: 67px;
            padding: 10px 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(124, 58, 237, 0.13);
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 7px 18px rgba(76, 29, 149, 0.06);
        }

        .renew-time-box strong {
            color: #4c1d95;
            font-size: 21px;
            line-height: 1;
            font-weight: 900;
        }

        .renew-time-box span {
            margin-top: 6px;
            color: #8b5cf6;
            font-size: 8px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .expired-countdown-message {
            padding: 14px;
            border: 1px solid #fecaca;
            border-radius: 12px;
            color: #991b1b;
            background: #fff1f2;
            font-size: 12px;
            font-weight: 800;
            text-align: center;
        }

        .renew-summary-card {
            position: sticky;
            top: 145px;
        }

        .renew-summary-box {
            padding: 20px;
            border-radius: 17px;
            background: #f8fafc;
            border: 1px solid #e6e9f2;
        }

        .summary-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px dashed #dbe1eb;
            font-size: 12px;
        }

        .summary-row:last-child {
            border-bottom: 0;
        }

        .summary-row span {
            color: #64748b;
            font-weight: 700;
        }

        .summary-row strong {
            color: #172033;
            font-weight: 900;
            text-align: right;
        }

        .renew-info-note {
            margin-top: 15px;
            padding: 13px 14px;
            border: 1px solid #bfdbfe;
            border-radius: 13px;
            color: #1e40af;
            background: #eff6ff;
            font-size: 11px;
            line-height: 1.55;
            font-weight: 700;
        }

        .renew-actions {
            display: grid;
            gap: 10px;
            margin-top: 16px;
        }

        .renew-primary-btn,
        .renew-secondary-btn,
        .renew-disabled-btn {
            min-height: 46px;
            padding: 10px 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 13px;
            font-size: 13px;
            font-weight: 900;
            text-decoration: none !important;
            transition: 0.2s ease;
        }

        .renew-primary-btn {
            color: #ffffff !important;
            border: 1px solid #16a34a;
            background: linear-gradient(135deg, #16a34a, #059669);
            box-shadow: 0 12px 24px rgba(5, 150, 105, 0.20);
        }

        .renew-primary-btn:hover {
            color: #ffffff !important;
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(5, 150, 105, 0.26);
        }

        .renew-secondary-btn {
            color: #5b21b6 !important;
            border: 1px solid #ddd6fe;
            background: #ffffff;
        }

        .renew-secondary-btn:hover {
            color: #4c1d95 !important;
            border-color: #a78bfa;
            transform: translateY(-1px);
        }

        .renew-disabled-btn {
            color: #64748b;
            border: 1px solid #cbd5e1;
            background: #e2e8f0;
            cursor: not-allowed;
        }

        .support-config-warning {
            margin-top: 10px;
            color: #b45309;
            font-size: 10px;
            line-height: 1.5;
            font-weight: 800;
            text-align: center;
        }

        .copy-feedback {
            min-height: 18px;
            margin-top: 8px;
            color: #047857;
            font-size: 11px;
            font-weight: 800;
            text-align: center;
        }

        @media (max-width: 1050px) {
            .renew-grid {
                grid-template-columns: 1fr;
            }

            .renew-summary-card {
                position: static;
            }
        }

        @media (max-width: 767px) {
            .renew-subscription-page .content-wrapper {
                padding: 14px;
            }

            .renew-page-header {
                align-items: flex-start;
                flex-direction: column;
                padding: 19px;
            }

            .renew-heading-icon {
                width: 46px;
                height: 46px;
                flex-basis: 46px;
            }

            .renew-heading h1 {
                font-size: 21px;
            }

            .plan-hero-top {
                flex-direction: column;
            }

            .plan-price {
                text-align: left;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .renew-countdown {
                gap: 5px;
            }

            .renew-time-box {
                min-height: 60px;
            }

            .renew-time-box strong {
                font-size: 17px;
            }
        }
        
        /* =========================================================
   SUBSCRIPTION RENEWAL REQUEST FORM
========================================================= */

.renewal-request-form {
    margin-top: 18px;
    padding: 18px;
    border: 1px solid #e6e9f2;
    border-radius: 17px;
    background: #ffffff;
    box-shadow: 0 10px 26px rgba(30, 41, 59, 0.06);
}

.renew-form-group {
    margin-bottom: 14px;
}

.renew-form-group label {
    display: block;
    margin-bottom: 7px;
    color: #172033;
    font-size: 12px;
    font-weight: 900;
}

.renew-form-group small {
    display: block;
    margin-top: 6px;
    color: #64748b;
    font-size: 10px;
    line-height: 1.5;
    font-weight: 700;
}

.renew-form-control {
    width: 100%;
    min-height: 44px;
    padding: 10px 12px;
    color: #172033;
    font-size: 13px;
    font-weight: 600;
    outline: none;
    border: 1px solid #dbe1eb;
    border-radius: 11px;
    background: #f8fafc;
    transition: 0.2s ease;
}

textarea.renew-form-control {
    min-height: 95px;
    resize: vertical;
}

.renew-form-control:focus {
    border-color: #8b5cf6;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.12);
}

.renew-form-message {
    margin-bottom: 14px;
    padding: 12px 14px;
    border-radius: 11px;
    font-size: 12px;
    line-height: 1.5;
    font-weight: 800;
}

.renew-submit-btn {
    width: 100%;
    min-height: 46px;
    padding: 10px 15px;
    color: #ffffff;
    font-size: 13px;
    font-weight: 900;
    border: 0;
    border-radius: 13px;
    background: linear-gradient(
        135deg,
        #7c3aed,
        #4f46e5
    );
    box-shadow: 0 12px 24px rgba(79, 70, 229, 0.22);
    cursor: pointer;
    transition: 0.2s ease;
}

.renew-submit-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 16px 30px rgba(79, 70, 229, 0.28);
}

.renew-submit-btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none;
}


/* =========================================================
   APPROVE / REJECT MODAL BOOTSTRAP CONFLICT FIX
========================================================= */

#actionModal.modal-bg {
    position: fixed !important;
    inset: 0 !important;
    z-index: 999999 !important;

    display: none !important;
    align-items: center !important;
    justify-content: center !important;

    width: 100% !important;
    height: 100% !important;

    padding: 20px !important;

    background: rgba(2, 6, 23, 0.82) !important;
    backdrop-filter: blur(7px) !important;
    -webkit-backdrop-filter: blur(7px) !important;
}

#actionModal.modal-bg.show {
    display: flex !important;
}

/*
|--------------------------------------------------------------------------
| Bootstrap .modal class ko override karna zaroori hai
|--------------------------------------------------------------------------
*/

#actionModal .modal {
    position: relative !important;

    top: auto !important;
    right: auto !important;
    bottom: auto !important;
    left: auto !important;
    inset: auto !important;

    display: block !important;

    width: 100% !important;
    max-width: 500px !important;

    height: auto !important;
    max-height: calc(100vh - 40px) !important;

    margin: 0 !important;
    padding: 0 !important;

    overflow: hidden !important;

    opacity: 1 !important;
    visibility: visible !important;

    transform: none !important;

    color: #e5e7eb !important;

    background:
        linear-gradient(
            145deg,
            #111a2f,
            #0b1425
        ) !important;

    border:
        1px solid rgba(139, 92, 246, 0.30) !important;

    border-radius: 18px !important;

    box-shadow:
        0 30px 90px rgba(0, 0, 0, 0.60),
        0 0 0 1px rgba(124, 58, 237, 0.10) !important;
}

/* Popup sections */

#actionModal .modal-header {
    position: relative !important;

    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;

    padding: 16px 18px !important;

    color: #ffffff !important;
    background: #10192d !important;

    border: 0 !important;
    border-bottom:
        1px solid rgba(148, 163, 184, 0.14) !important;
}

#actionModal .modal-header h3 {
    margin: 0 !important;
    color: #ffffff !important;
    font-size: 16px !important;
    font-weight: 900 !important;
}

#actionModal .modal-body {
    position: relative !important;

    display: block !important;

    padding: 18px !important;

    color: #e5e7eb !important;
    background: #0d1729 !important;

    overflow-y: auto !important;
}

#actionModal .modal-body p {
    margin: 0 0 14px !important;

    color: #cbd5e1 !important;

    font-size: 12px !important;
    line-height: 1.6 !important;
    font-weight: 600 !important;
}

#actionModal .modal-body textarea {
    display: block !important;

    width: 100% !important;
    min-height: 110px !important;

    padding: 12px !important;

    color: #e5e7eb !important;
    background: #091220 !important;

    border:
        1px solid rgba(148, 163, 184, 0.24) !important;

    border-radius: 11px !important;

    outline: none !important;
    resize: vertical !important;
}

#actionModal .modal-body textarea:focus {
    border-color: #8b5cf6 !important;

    box-shadow:
        0 0 0 3px rgba(139, 92, 246, 0.14) !important;
}

#actionModal .modal-footer {
    position: relative !important;

    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 9px !important;

    padding: 15px 18px !important;

    background: #10192d !important;

    border: 0 !important;
    border-top:
        1px solid rgba(148, 163, 184, 0.14) !important;
}

/* Close button */

#actionModal .close {
    width: 34px !important;
    height: 34px !important;

    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;

    padding: 0 !important;

    color: #ffffff !important;
    background: rgba(148, 163, 184, 0.12) !important;

    border: 0 !important;
    border-radius: 9px !important;

    opacity: 1 !important;

    font-size: 21px !important;
    line-height: 1 !important;

    cursor: pointer !important;
}

#actionModal .close:hover {
    color: #ffffff !important;
    background: rgba(239, 68, 68, 0.25) !important;
}

/* Popup buttons */

#actionModal .modal-footer .reset {
    color: #d1d5db !important;
    background: #1f2937 !important;

    border:
        1px solid rgba(148, 163, 184, 0.24) !important;
}

#actionModal .modal-footer .apply {
    color: #ffffff !important;

    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #4f46e5
        ) !important;

    border:
        1px solid rgba(196, 181, 253, 0.22) !important;
}

#actionModal .modal-footer .btn {
    min-height: 40px !important;
    padding: 9px 15px !important;

    border-radius: 10px !important;

    font-size: 11px !important;
    font-weight: 900 !important;
}
    </style>
</head>
<body class="renew-subscription-page">
<div class="container-scroll">
    <div class="sidebar" id="dashboardSidebar">
        <?php if (is_file(__DIR__ . '/partials/sidebar.php')): ?>
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <?php endif; ?>
    </div>

    <div class="main-panel">
        <?php if (is_file(__DIR__ . '/partials/topbar.php')): ?>
            <?php include __DIR__ . '/partials/topbar.php'; ?>
        <?php endif; ?>

        <div class="content-wrapper">
            <div class="renew-page-shell">
                <div class="renew-page-header">
                    <div class="renew-heading">
                        <div class="renew-heading-icon">↻</div>
                        <div>
                            <h1>Renew Subscription</h1>
                            <p>Review your current plan and send a renewal request to ONEERP support.</p>
                        </div>
                    </div>

                    <a href="dashboard.php" class="back-dashboard-btn">
                        ← Back to Dashboard
                    </a>
                </div>

                <?php if ($pageError !== ''): ?>
                    <div class="renew-alert"><?= renewPageEscape($pageError) ?></div>
                <?php endif; ?>

                <div class="renew-grid">
                    <section class="renew-card">
                        <div class="renew-card-header">
                            <h2>Current Subscription</h2>
                            <span class="subscription-status <?= renewPageEscape($statusClass) ?>">
                                <?= renewPageEscape($statusLabel) ?>
                            </span>
                        </div>

                        <div class="renew-card-body">
                            <?php if ($subscription): ?>
                                <div class="plan-hero">
                                    <div class="plan-hero-top">
                                        <div>
                                            <p class="plan-label">Your Current Plan</p>
                                            <h3 class="plan-name"><?= renewPageEscape($planName) ?></h3>
                                            <div class="plan-industry">
                                                <?= renewPageEscape($planIndustry) ?> · <?= renewPageEscape($billingLabel) ?> Billing
                                            </div>
                                        </div>

                                        <div class="plan-price">
                                            <strong><?= renewPageEscape($priceDisplay) ?></strong>
                                            <span>Per <?= renewPageEscape($billingCycle === 'yearly' ? 'year' : 'month') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="details-grid">
                                    <div class="detail-box">
                                        <span>Subscription ID</span>
                                        <strong>#<?= (int)$subscriptionId ?></strong>
                                    </div>

                                    <div class="detail-box">
                                        <span>Billing Cycle</span>
                                        <strong><?= renewPageEscape($billingLabel) ?></strong>
                                    </div>

                                    <div class="detail-box">
                                        <span>Start Date</span>
                                        <strong><?= renewPageEscape($startDate) ?></strong>
                                    </div>

                                    <div class="detail-box">
                                        <span>Expiry Date & Time</span>
                                        <strong><?= renewPageEscape($expiresDisplay) ?></strong>
                                    </div>
                                </div>

                                <div class="countdown-panel">
                                    <div class="countdown-title">
                                        <strong id="renewCountdownHeading">
                                            <?= $isExpired ? 'Subscription Expired' : 'Time Remaining' ?>
                                        </strong>
                                        <span>Asia/Karachi</span>
                                    </div>

                                    <?php if (!$isExpired && $expiresIso !== ''): ?>
                                        <div
                                            class="renew-countdown"
                                            id="renewCountdown"
                                            data-expiry="<?= renewPageEscape($expiresIso) ?>"
                                        >
                                            <div class="renew-time-box">
                                                <strong id="renewDays">00</strong>
                                                <span>Days</span>
                                            </div>
                                            <div class="renew-time-box">
                                                <strong id="renewHours">00</strong>
                                                <span>Hours</span>
                                            </div>
                                            <div class="renew-time-box">
                                                <strong id="renewMinutes">00</strong>
                                                <span>Minutes</span>
                                            </div>
                                            <div class="renew-time-box">
                                                <strong id="renewSeconds">00</strong>
                                                <span>Seconds</span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="expired-countdown-message">
                                            Your subscription has expired. Send a renewal request to restore uninterrupted access.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="expired-countdown-message">
                                    No subscription record was found for this client. Please contact ONEERP support.
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <aside class="renew-card renew-summary-card">
                        <div class="renew-card-header">
                            <h2>Renewal Summary</h2>
                        </div>

                        <div class="renew-card-body">
                            <div class="renew-summary-box">
                                <div class="summary-row">
                                    <span>Plan</span>
                                    <strong><?= renewPageEscape($planName) ?></strong>
                                </div>

                                <div class="summary-row">
                                    <span>Renewal Period</span>
                                    <strong><?= renewPageEscape($renewalPeriod) ?></strong>
                                </div>

                                <div class="summary-row">
                                    <span>Renewal Amount</span>
                                    <strong><?= renewPageEscape($priceDisplay) ?></strong>
                                </div>

                                <div class="summary-row">
                                    <span>Current Expiry</span>
                                    <strong><?= renewPageEscape($expiresDisplay) ?></strong>
                                </div>
                            </div>

                     <div class="renew-info-note">
    Active subscription renew karne par naya period current expiry ke baad add hoga. Expired subscription renew karne par naya period renewal time se start hoga.
</div>


<!-- =====================================================
     SUBSCRIPTION RENEWAL PAYMENT FORM
===================================================== -->

<form
    id="subscriptionRenewalForm"
    method="post"
    action="/onerp/app/public/app/views/ajax_request_subscription_renewal.php"
    enctype="multipart/form-data"
    class="renewal-request-form"
>
    <input
        type="hidden"
        name="csrf_token"
        id="renewalCsrfToken"
        value="<?= renewPageEscape($renewalCsrfToken) ?>"
    >

    <div class="renew-form-group">
        <label for="paymentMethod">
            Payment Method
        </label>

        <select
            name="payment_method"
            id="paymentMethod"
            class="renew-form-control"
            required
        >
            <option value="">
                Select Payment Method
            </option>

            <option value="bank_transfer">
                Bank Transfer
            </option>

            <option value="easypaisa">
                Easypaisa
            </option>

            <option value="jazzcash">
                JazzCash
            </option>

            <option value="cash">
                Cash
            </option>

            <option value="other">
                Other
            </option>
        </select>
    </div>

    <div class="renew-form-group">
        <label for="paymentReference">
            Transaction / Reference ID
        </label>

        <input
            type="text"
            name="payment_reference"
            id="paymentReference"
            class="renew-form-control"
            maxlength="150"
            placeholder="Enter transaction/reference ID"
        >

        <small>
            Cash payment ke liye reference optional hai.
        </small>
    </div>

    <div class="renew-form-group">
        <label for="paymentProof">
            Payment Proof
        </label>

        <input
            type="file"
            name="payment_proof"
            id="paymentProof"
            class="renew-form-control"
            accept=".jpg,.jpeg,.png,.webp,.pdf"
        >

        <small>
            JPG, PNG, WEBP ya PDF. Maximum 5 MB.
        </small>
    </div>

    <div class="renew-form-group">
        <label for="clientNote">
            Note
        </label>

        <textarea
            name="client_note"
            id="clientNote"
            class="renew-form-control"
            maxlength="1000"
            rows="4"
            placeholder="Additional information..."
        ></textarea>
    </div>

    <div
        id="renewalFormMessage"
        class="renew-form-message"
        style="display:none;"
    ></div>

    <button
        type="submit"
        id="submitRenewalButton"
        class="renew-submit-btn"
        <?= !$subscription ? 'disabled' : '' ?>
    >
        Submit Renewal Request
    </button>
</form>


<!-- Existing WhatsApp aur copy buttons -->
<div class="renew-actions">
                                <?php if ($whatsAppUrl !== ''): ?>
                                    <a
                                        href="<?= renewPageEscape($whatsAppUrl) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="renew-primary-btn"
                                    >
                                        Send Renewal Request
                                    </a>
                                <?php else: ?>
                                    <span class="renew-disabled-btn" title="Configure support WhatsApp number in this file">
                                        WhatsApp Number Not Configured
                                    </span>
                                    <div class="support-config-warning">
                                        Is file ke start mein <code>$supportWhatsApp</code> mein ONEERP support number add karein.
                                    </div>
                                <?php endif; ?>

                                <button
                                    type="button"
                                    class="renew-secondary-btn"
                                    id="copyRenewalRequest"
                                    data-request="<?= renewPageEscape($requestMessage) ?>"
                                >
                                    Copy Renewal Details
                                </button>
                            </div>

                            <div class="copy-feedback" id="copyRenewalFeedback"></div>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function initializeRenewalCountdown() {
        const countdown = document.getElementById('renewCountdown');

        if (!countdown) {
            return;
        }

        const expiryValue = countdown.dataset.expiry || '';
        const expiryTimestamp = Date.parse(expiryValue);

        if (!expiryValue || Number.isNaN(expiryTimestamp)) {
            return;
        }

        const daysElement = document.getElementById('renewDays');
        const hoursElement = document.getElementById('renewHours');
        const minutesElement = document.getElementById('renewMinutes');
        const secondsElement = document.getElementById('renewSeconds');
        const headingElement = document.getElementById('renewCountdownHeading');

        function update() {
            const difference = expiryTimestamp - Date.now();

            if (difference <= 0) {
                if (daysElement) daysElement.textContent = '00';
                if (hoursElement) hoursElement.textContent = '00';
                if (minutesElement) minutesElement.textContent = '00';
                if (secondsElement) secondsElement.textContent = '00';
                if (headingElement) headingElement.textContent = 'Subscription Expired';
                return false;
            }

            const day = 1000 * 60 * 60 * 24;
            const hour = 1000 * 60 * 60;
            const minute = 1000 * 60;

            const days = Math.floor(difference / day);
            const hours = Math.floor((difference % day) / hour);
            const minutes = Math.floor((difference % hour) / minute);
            const seconds = Math.floor((difference % minute) / 1000);

            if (daysElement) daysElement.textContent = pad(days);
            if (hoursElement) hoursElement.textContent = pad(hours);
            if (minutesElement) minutesElement.textContent = pad(minutes);
            if (secondsElement) secondsElement.textContent = pad(seconds);

            return true;
        }

        update();

        const interval = window.setInterval(function () {
            if (!update()) {
                window.clearInterval(interval);
            }
        }, 1000);
    }

    function initializeCopyButton() {
        const button = document.getElementById('copyRenewalRequest');
        const feedback = document.getElementById('copyRenewalFeedback');

        if (!button) {
            return;
        }

        button.addEventListener('click', async function () {
            const requestText = button.dataset.request || '';

            if (!requestText) {
                return;
            }

            try {
                await navigator.clipboard.writeText(requestText);
                if (feedback) feedback.textContent = 'Renewal details copied.';
            } catch (error) {
                const temporary = document.createElement('textarea');
                temporary.value = requestText;
                temporary.style.position = 'fixed';
                temporary.style.opacity = '0';
                document.body.appendChild(temporary);
                temporary.select();
                document.execCommand('copy');
                temporary.remove();

                if (feedback) feedback.textContent = 'Renewal details copied.';
            }

            window.setTimeout(function () {
                if (feedback) feedback.textContent = '';
            }, 2500);
        });
    }

    function initializePage() {
        initializeRenewalCountdown();
        initializeCopyButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePage, { once: true });
    } else {
        initializePage();
    }
})();
</script>

<script>
(function () {
    function initializeRenewalRequestForm() {
        const form = document.getElementById(
            'subscriptionRenewalForm'
        );

        if (!form) {
            return;
        }

        const submitButton = document.getElementById(
            'submitRenewalButton'
        );

        const messageBox = document.getElementById(
            'renewalFormMessage'
        );

        const csrfInput = document.getElementById(
            'renewalCsrfToken'
        );

        const paymentMethodInput = document.getElementById(
            'paymentMethod'
        );

        const paymentReferenceInput = document.getElementById(
            'paymentReference'
        );

        const paymentProofInput = document.getElementById(
            'paymentProof'
        );

        function showRenewalMessage(message, success) {
            if (!messageBox) {
                alert(message);
                return;
            }

            messageBox.style.display = 'block';
            messageBox.textContent = message;

            if (success) {
                messageBox.style.color = '#166534';
                messageBox.style.background = '#dcfce7';
                messageBox.style.border =
                    '1px solid #86efac';
            } else {
                messageBox.style.color = '#991b1b';
                messageBox.style.background = '#fee2e2';
                messageBox.style.border =
                    '1px solid #fca5a5';
            }
        }

        form.addEventListener(
            'submit',
            async function (event) {
                event.preventDefault();

                const paymentMethod =
                    paymentMethodInput
                        ? paymentMethodInput.value.trim()
                        : '';

                const paymentReference =
                    paymentReferenceInput
                        ? paymentReferenceInput.value.trim()
                        : '';

                /*
                |--------------------------------------------------------------------------
                | Frontend validation
                |--------------------------------------------------------------------------
                */

                if (paymentMethod === '') {
                    showRenewalMessage(
                        'Please select a payment method.',
                        false
                    );

                    if (paymentMethodInput) {
                        paymentMethodInput.focus();
                    }

                    return;
                }

                if (
                    paymentMethod !== 'cash'
                    && paymentReference === ''
                ) {
                    showRenewalMessage(
                        'Transaction/reference ID required hai.',
                        false
                    );

                    if (paymentReferenceInput) {
                        paymentReferenceInput.focus();
                    }

                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | File size validation
                |--------------------------------------------------------------------------
                */

                if (
                    paymentProofInput
                    && paymentProofInput.files
                    && paymentProofInput.files[0]
                ) {
                    const selectedFile =
                        paymentProofInput.files[0];

                    const maximumFileSize =
                        5 * 1024 * 1024;

                    if (
                        selectedFile.size
                        > maximumFileSize
                    ) {
                        showRenewalMessage(
                            'Payment proof 5 MB se chhota hona chahiye.',
                            false
                        );

                        return;
                    }
                }

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent =
                        'Submitting Request...';
                }

                if (messageBox) {
                    messageBox.style.display = 'none';
                }

                try {
                    const formData =
                        new FormData(form);

                    const response = await fetch(
                        form.action,
                        {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'Accept': 'application/json'
                            },
                            credentials: 'same-origin'
                        }
                    );

                    const responseText =
                        await response.text();

                    let result;

                    try {
                        result =
                            JSON.parse(responseText);
                    } catch (parseError) {
                        console.error(
                            'Invalid AJAX response:',
                            responseText
                        );

                        throw new Error(
                            responseText
                            || 'Server returned an invalid response.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Session expired redirect
                    |--------------------------------------------------------------------------
                    */

                    if (
                        result.redirect
                        && response.status === 401
                    ) {
                        window.location.href =
                            result.redirect;

                        return;
                    }

                    if (
                        !response.ok
                        || !result.success
                    ) {
                        throw new Error(
                            result.message
                            || 'Renewal request could not be submitted.'
                        );
                    }

                    showRenewalMessage(
                        result.message
                        || 'Renewal request submitted successfully.',
                        true
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Form clear aur new CSRF token
                    |--------------------------------------------------------------------------
                    */

                    form.reset();

                    if (
                        csrfInput
                        && result.csrf_token
                    ) {
                        csrfInput.value =
                            result.csrf_token;
                    }

                    if (submitButton) {
                        submitButton.textContent =
                            'Request Submitted';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Success ke baad page reload
                    |--------------------------------------------------------------------------
                    */

                    window.setTimeout(
                        function () {
                            window.location.reload();
                        },
                        1800
                    );
                } catch (error) {
                    console.error(
                        'Renewal request error:',
                        error
                    );

                    showRenewalMessage(
                        error.message
                        || 'Request submit nahi ho saki.',
                        false
                    );

                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent =
                            'Submit Renewal Request';
                    }
                }
            }
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeRenewalRequestForm,
            { once: true }
        );
    } else {
        initializeRenewalRequestForm();
    }
})();
</script>


</body>
</html>
