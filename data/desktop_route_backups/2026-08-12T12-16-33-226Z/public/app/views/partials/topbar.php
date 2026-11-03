<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once($_SERVER['DOCUMENT_ROOT'].'/onerp/app/config/db.php');

/*
|--------------------------------------------------------------------------
| Logged-in user and client
|--------------------------------------------------------------------------
*/

$logged_user_id   = (int)($_SESSION['user_id'] ?? 0);
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_name      = $_SESSION['branch_name'] ?? '';


/* ========================================================================
   BUSINESS DAY / BUSINESS SESSION
   Additive only. Existing topbar/subscription logic remains unchanged.
======================================================================== */
$businessSessionHelperPath =
    $_SERVER['DOCUMENT_ROOT']
    . '/onerp/app/app/helpers/includes/business_session_helper.php';

if (is_file($businessSessionHelperPath)) {
    require_once $businessSessionHelperPath;
}

$oneerpBusinessBranchId = (int)($_SESSION['branch_id'] ?? 0);
$oneerpBusinessSession = null;
$oneerpBusinessLastClosed = null;

if (
    $logged_client_id > 0
    && function_exists('oneerpBusinessCurrentSession')
) {
    $oneerpBusinessSession = oneerpBusinessCurrentSession(
        $conn,
        $logged_client_id,
        $oneerpBusinessBranchId
    );

    $oneerpBusinessLastClosed = oneerpBusinessLastClosedSession(
        $conn,
        $logged_client_id,
        $oneerpBusinessBranchId
    );
}

/*
|--------------------------------------------------------------------------
| Subscription helper
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Subscription helper
|--------------------------------------------------------------------------
*/

$subscriptionHelperPath =
    $_SERVER['DOCUMENT_ROOT']
    . '/onerp/app/app/helpers/includes/subscription_helper.php';

if (!is_file($subscriptionHelperPath)) {
    die(
        'Subscription helper file not found: '
        . htmlspecialchars(
            $subscriptionHelperPath,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

require_once $subscriptionHelperPath;
/*
|--------------------------------------------------------------------------
| Current client subscription state
|--------------------------------------------------------------------------
*/

$oneerpSubscriptionState = null;

if (
    $logged_client_id > 0
    && function_exists('oneerpGetSubscriptionState')
) {
    /*
|--------------------------------------------------------------------------
| Verify latest renewed subscription directly from database
|--------------------------------------------------------------------------
*/

try {
    $latestSubscriptionStmt = $conn->prepare(
        "SELECT
            id,
            status,
            COALESCE(
                expires_at,
                CONCAT(end_date, ' 23:59:59')
            ) AS actual_expiry
         FROM subscriptions
         WHERE client_id = ?
         ORDER BY
            COALESCE(
                expires_at,
                CONCAT(end_date, ' 23:59:59')
            ) DESC,
            id DESC
         LIMIT 1"
    );

    $latestSubscriptionStmt->bind_param(
        'i',
        $logged_client_id
    );

    $latestSubscriptionStmt->execute();

    $latestSubscriptionRow = $latestSubscriptionStmt
        ->get_result()
        ->fetch_assoc();

    $latestSubscriptionStmt->close();

    if (
        $latestSubscriptionRow
        && strtolower(
            trim(
                (string)$latestSubscriptionRow['status']
            )
        ) === 'active'
        && !empty(
            $latestSubscriptionRow['actual_expiry']
        )
    ) {
        $subscriptionTimezone =
            new DateTimeZone('Asia/Karachi');

        $subscriptionNow =
            new DateTime('now', $subscriptionTimezone);

        $latestExpiry =
            new DateTime(
                $latestSubscriptionRow['actual_expiry'],
                $subscriptionTimezone
            );

        $latestRemainingSeconds =
            $latestExpiry->getTimestamp()
            - $subscriptionNow->getTimestamp();

        $sevenDaysSeconds =
            7 * 24 * 60 * 60;

        /*
         * Renewed subscription 7 din se zyada valid hai,
         * is liye banner hide kar dein.
         */
        if (
            $latestRemainingSeconds
            > $sevenDaysSeconds
        ) {
            if (
                !is_array(
                    $oneerpSubscriptionState
                )
            ) {
                $oneerpSubscriptionState = [];
            }

            $oneerpSubscriptionState[
                'show_alert'
            ] = false;

            $oneerpSubscriptionState[
                'is_expired'
            ] = false;
        }
    }
} catch (Throwable $subscriptionVerifyError) {
    error_log(
        'Latest subscription verification error: '
        . $subscriptionVerifyError->getMessage()
    );
}

    /*
    |--------------------------------------------------------------------------
    | Bell notification create karein
    |--------------------------------------------------------------------------
    | Ye existing notifications fetch hone se pehle chalega.
    |--------------------------------------------------------------------------
    */

    if (
        $logged_user_id > 0
        && !empty($oneerpSubscriptionState['show_alert'])
        && function_exists(
            'oneerpCreateSubscriptionNotification'
        )
    ) {
        oneerpCreateSubscriptionNotification(
            $conn,
            $logged_user_id,
            $oneerpSubscriptionState
        );
    }
}
// Client settings
/*
|--------------------------------------------------------------------------
| Custom topbar theme
|--------------------------------------------------------------------------
| Default ONEERP topbar theme remains untouched.
| Client-selected colors are used only when use_custom_theme = 1.
|--------------------------------------------------------------------------
*/
$topbarThemeSettings = (
    isset($_SESSION['client_settings']) &&
    is_array($_SESSION['client_settings'])
)
    ? $_SESSION['client_settings']
    : [];

$use_custom_theme = (
    (int)(
        $clientTheme['use_custom_theme']
        ?? $topbarThemeSettings['use_custom_theme']
        ?? 0
    ) === 1
);

$normalizeTopbarThemeColor = static function (
    $value,
    string $fallback
): string {
    $value = trim((string)$value);

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value)
        ? strtolower($value)
        : $fallback;
};

$navbar_bg = $normalizeTopbarThemeColor(
    $clientTheme['header_bg']
        ?? $topbarThemeSettings['header_color']
        ?? '',
    '#0d6efd'
);

$navbar_text = $normalizeTopbarThemeColor(
    $clientTheme['header_text']
        ?? $topbarThemeSettings['header_text_color']
        ?? '',
    '#ffffff'
);

$navbar_hover = $normalizeTopbarThemeColor(
    $clientTheme['header_hover']
        ?? $topbarThemeSettings['header_hover_color']
        ?? '',
    '#0056b3'
);

/*
  Client-wise topbar logo:
  - Har client ka logo client_settings.logo se aayega.
  - Agar session me logo na ho to DB se same client_id ka logo load hoga.
  - Fallback old/profile image rahegi taake UI break na ho.
*/
$client_logo = trim((string)($_SESSION['client_settings']['logo'] ?? ''));

if ($client_logo === '' && (int)$logged_client_id > 0 && isset($conn) && $conn instanceof mysqli) {
    try {
        $stmt_logo_col = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'client_settings'
              AND COLUMN_NAME = 'logo'
        ");

        if ($stmt_logo_col) {
            $stmt_logo_col->execute();
            $logo_col_row = $stmt_logo_col->get_result()->fetch_assoc();
            $stmt_logo_col->close();

            if ((int)($logo_col_row['c'] ?? 0) > 0) {
                $stmt_logo = $conn->prepare("SELECT logo FROM client_settings WHERE client_id = ? LIMIT 1");
                if ($stmt_logo) {
                    $stmt_logo->bind_param('i', $logged_client_id);
                    $stmt_logo->execute();
                    $logo_row = $stmt_logo->get_result()->fetch_assoc();
                    $stmt_logo->close();

                    $client_logo = trim((string)($logo_row['logo'] ?? ''));
                    if ($client_logo !== '') {
                        $_SESSION['client_settings']['logo'] = $client_logo;
                    }
                }
            }
        }
    } catch (Throwable $ignore) {}
}

if ($client_logo === '') {
    $client_logo = 'assets/images/faces/face28.jpg';
}

if (!function_exists('topbar_logo_src')) {
    function topbar_logo_src(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return 'assets/images/faces/face28.jpg';
        }

        if (preg_match('~^https?://~i', $path) || strpos($path, 'data:image/') === 0) {
            return $path;
        }

        return $path;
    }
}

$client_logo_src = topbar_logo_src($client_logo);

// Fetch logged user info
$user_name = 'User';
if ($logged_user_id > 0) {
    $stmt_user = $conn->prepare("SELECT name FROM users WHERE id = ?");
    if ($stmt_user) {
        $stmt_user->bind_param("i", $logged_user_id);
        $stmt_user->execute();
        $stmt_user->bind_result($user_name);
        $stmt_user->fetch();
        $stmt_user->close();
    }
}

// Fetch last 10 notifications for this user
$notifications = [];
$unread_count = 0;

if ($logged_user_id > 0) {
    $safe_user_id = (int)$logged_user_id;
    $notifRes = $conn->query("
        SELECT *
        FROM notifications
        WHERE user_id = {$safe_user_id}
        ORDER BY created_at DESC
        LIMIT 10
    ");

    if ($notifRes) {
        while ($n = $notifRes->fetch_assoc()) {
            $notifications[] = $n;
            if ((int)$n['is_read'] === 0) {
                $unread_count++;
            }
        }
    }
}
?>


<script>
/*
|--------------------------------------------------------------------------
| ONEERP POS THEME EARLY INITIALIZER
|--------------------------------------------------------------------------
| Default light mode. Saved user choice takes priority.
|--------------------------------------------------------------------------
*/
(function () {
    const storageKey = 'oneerp_pos_theme';
    let theme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

        if (savedTheme === 'light' || savedTheme === 'dark') {
            theme = savedTheme;
        }
    } catch (error) {
        theme = 'light';
    }

    document.documentElement.setAttribute('data-pos-theme', theme);
})();
</script>

<style>
    :root{
        --oms-bg:#070b14;
        --oms-topbar:#070b14;
        --oms-card:#0f172a;
        --oms-card-2:#111827;
        --oms-border:rgba(148,163,184,.14);
        --oms-border-2:rgba(148,163,184,.22);
        --oms-text:#e5e7eb;
        --oms-muted:#94a3b8;
        --oms-purple:#6d4cff;
        --oms-purple-2:#7c3aed;
        --oms-purple-soft:rgba(109,76,255,.14);
    }

    .navbar.my-navbar {
        background:var(--oms-topbar) !important;
        height:70px;
        min-height:70px;
        color:var(--oms-text);
        border-bottom:1px solid var(--oms-border);
        box-shadow:0 10px 30px rgba(0,0,0,.24);
    }

    .navbar .navbar-menu-wrapper {
        width:100% !important;
        margin-left:0 !important;
        padding-left:18px !important;
        padding-right:22px !important;
        background:var(--oms-topbar) !important;
        color:var(--oms-text);
        position:relative;
        overflow:visible;
    }

    .navbar .navbar-menu-wrapper .navbar-toggler[data-toggle="minimize"],
    .navbar .navbar-menu-wrapper .pos-sidebar-toggle {
        width:44px;
        height:44px;
        min-width:44px;
        margin-left:0 !important;
        margin-right:14px !important;
        padding:0 !important;
        position:relative !important;
        left:0 !important;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        color:#cbd5e1 !important;
        background:var(--oms-card) !important;
        border:1px solid var(--oms-border) !important;
        border-radius:12px;
        outline:none !important;
        box-shadow:none !important;
        z-index:50;
        transition:all .2s ease;
    }

    .navbar .navbar-menu-wrapper .navbar-toggler[data-toggle="minimize"]:hover,
    .navbar .navbar-menu-wrapper .pos-sidebar-toggle:hover {
        color:#fff !important;
        background:rgba(109,76,255,.18) !important;
        border-color:rgba(109,76,255,.38) !important;
    }

    .header-search-wrap {
        position:absolute;
        left:38%;
        top:50%;
        transform:translate(-50%, -50%);
        width:540px;
        max-width:calc(100% - 560px);
        min-width:360px;
        z-index:20;
        pointer-events:none;
    }

    .navbar-nav.navbar-nav-right {
        position:relative;
        z-index:3;
        margin-left:auto;
        background:transparent;
        align-items:center;
        gap:8px;
    }

    .header-search-box {
        position:relative;
        width:100%;
        pointer-events:auto;
    }

    .header-search-input {
        width:100%;
        height:44px;
        border:1px solid var(--oms-border);
        border-radius:12px;
        background:var(--oms-card);
        color:var(--oms-text);
        outline:none;
        padding:0 55px 0 22px;
        font-size:14px;
        font-weight:500;
        box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
        transition:all .2s ease;
    }

    .header-search-input::placeholder {
        color:var(--oms-muted);
        opacity:1;
    }

    .header-search-input:focus {
        border-color:rgba(109,76,255,.45);
        background:var(--oms-card-2);
        box-shadow:0 0 0 3px rgba(109,76,255,.12);
    }

    .header-search-submit {
        position:absolute;
        right:10px;
        top:50%;
        transform:translateY(-50%);
        width:32px;
        height:32px;
        border:0;
        border-radius:10px;
        background:rgba(109,76,255,.18);
        color:#fff;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        cursor:pointer;
        z-index:3;
        transition:all .2s ease;
    }

    .header-search-submit:hover {
        background:rgba(109,76,255,.30);
    }

    .header-search-submit span,
    .header-search-submit i {
        font-size:15px;
        line-height:1;
    }

    .search-suggestions {
        position:absolute;
        top:calc(100% + 10px);
        left:0;
        width:100%;
        background:var(--oms-card);
        border:1px solid var(--oms-border);
        border-radius:16px;
        box-shadow:0 18px 45px rgba(0,0,0,.45);
        overflow:hidden;
        display:none;
        z-index:9999;
        max-height:320px;
        overflow-y:auto;
    }

    .search-suggestions.show {
        display:block;
    }

    .search-suggestion-item {
        padding:12px 15px;
        cursor:pointer;
        border-bottom:1px solid rgba(148,163,184,.10);
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        transition:background .2s ease;
    }

    .search-suggestion-item:last-child {
        border-bottom:none;
    }

    .search-suggestion-item:hover,
    .search-suggestion-item.active {
        background:rgba(109,76,255,.14);
    }

    .search-suggestion-left {
        display:flex;
        flex-direction:column;
        min-width:0;
    }

    .search-suggestion-title {
        color:#f8fafc;
        font-size:14px;
        font-weight:600;
        line-height:1.3;
    }

    .search-suggestion-keywords {
        color:var(--oms-muted);
        font-size:12px;
        margin-top:3px;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
        max-width:420px;
    }

    .search-suggestion-right {
        color:#a78bfa;
        font-size:11px;
        font-weight:700;
        white-space:nowrap;
    }

    .search-empty {
        padding:14px 15px;
        color:#cbd5e1;
        font-size:13px;
    }

    .search-highlight {
        color:#ffffff;
        font-weight:800;
        text-decoration:underline;
        text-underline-offset:2px;
    }

    .navbar .nav-link {
        color:#cbd5e1 !important;
    }

    .navbar .nav-link:hover {
        color:#fff !important;
    }

    .navbar-nav.navbar-nav-right .text-white {
        color:#e5e7eb !important;
        font-size:14px !important;
        padding:0 10px !important;
        max-width:220px;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .nav-profile img {
        width:38px;
        height:38px;
        border-radius:50%;
        border:2px solid rgba(109,76,255,.45);
        object-fit:cover;
    }

    .client-logo-upload-trigger {
        position:relative;
        width:42px;
        height:42px;
        min-width:42px;
        border-radius:50%;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        cursor:pointer;
    }

    .client-logo-upload-trigger img {
        cursor:pointer;
        transition:opacity .18s ease, transform .18s ease, border-color .18s ease;
    }

    .client-logo-upload-trigger:hover img {
        transform:scale(1.04);
        border-color:rgba(16,185,129,.70);
    }

    .client-logo-upload-trigger::after {
        content:'+';
        position:absolute;
        right:-2px;
        bottom:-2px;
        width:17px;
        height:17px;
        border-radius:50%;
        background:#10b981;
        color:#fff;
        font-size:13px;
        line-height:17px;
        text-align:center;
        font-weight:900;
        border:2px solid var(--oms-topbar);
        box-shadow:0 4px 12px rgba(0,0,0,.35);
    }

    .client-logo-upload-trigger.uploading img {
        opacity:.45;
    }

    .nav-profile .dropdown-toggle {
        display:flex;
        align-items:center;
        gap:8px;
        background:rgba(15,23,42,.72);
        border:1px solid var(--oms-border);
        border-radius:14px;
        padding:6px 10px !important;
    }

    .nav-profile .dropdown-toggle:hover {
        background:rgba(109,76,255,.14);
        border-color:rgba(109,76,255,.30);
    }

    .count-indicator {
        width:42px;
        height:42px;
        border-radius:12px;
        display:flex !important;
        align-items:center;
        justify-content:center;
        background:rgba(15,23,42,.72);
        border:1px solid var(--oms-border);
        position:relative;
    }

    .count-indicator .count {
        position:absolute;
        top:-5px;
        right:-4px;
        min-width:18px;
        height:18px;
        padding:0 5px;
        border-radius:999px;
        background:#ef4444;
        color:#fff;
        font-size:10px;
        display:flex;
        align-items:center;
        justify-content:center;
        border:2px solid var(--oms-topbar);
    }

    .navbar-dropdown.dropdown-menu {
        background:var(--oms-card);
        border:1px solid var(--oms-border);
        border-radius:14px;
        box-shadow:0 20px 45px rgba(0,0,0,.45);
        overflow:hidden;
    }

    .navbar-dropdown .dropdown-header,
    .navbar-dropdown .dropdown-item,
    .navbar-dropdown .preview-subject {
        color:#e5e7eb !important;
    }

    .navbar-dropdown .dropdown-item:hover {
        background:rgba(109,76,255,.14);
    }

    .navbar-dropdown .text-muted {
        color:#94a3b8 !important;
    }

    @media (max-width: 1200px) {
        .header-search-wrap {
            left:42%;
            width:450px;
            max-width:calc(100% - 520px);
            min-width:320px;
        }
    }

    @media (max-width: 991px) {
        .header-search-wrap {
            display:none;
        }

        .navbar .navbar-menu-wrapper {
            padding-left:12px !important;
            padding-right:12px !important;
        }

        .navbar-nav.navbar-nav-right .text-white,
        .nav-profile span {
            display:none !important;
        }

        .navbar .navbar-menu-wrapper .navbar-toggler[data-toggle="minimize"],
        .navbar .navbar-menu-wrapper .pos-sidebar-toggle {
            margin-right:8px !important;
        }
    }


    /* =========================================================
       POS LIGHT / DARK THEME TOGGLE
       UI only. No backend or topbar workflow changed.
    ========================================================= */

    .pos-theme-toggle-wrap {
        display:flex;
        align-items:center;
    }

    .pos-theme-toggle {
        width:42px;
        height:42px;
        min-width:42px;
        padding:0;
        border-radius:12px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        background:rgba(15,23,42,.72);
        color:#e5e7eb;
        border:1px solid var(--oms-border);
        outline:none;
        box-shadow:none;
        cursor:pointer;
        transition:all .2s ease;
    }

    .pos-theme-toggle:hover {
        color:#fff;
        background:rgba(109,76,255,.18);
        border-color:rgba(109,76,255,.38);
    }

    .pos-theme-toggle span {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        font-size:18px;
        line-height:1;
        pointer-events:none;
    }

    /* Light mode topbar */
    /*html[data-pos-theme="light"] .navbar.my-navbar,*/
    /*html[data-pos-theme="light"] .navbar .navbar-menu-wrapper {*/
    /*    background:#ffffff !important;*/
    /*    color:#172033 !important;*/
    /*    border-color:#e2e7f0 !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .navbar.my-navbar {*/
    /*    box-shadow:0 8px 24px rgba(30,41,59,.08) !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .header-search-input {*/
    /*    background:#f7f8fc !important;*/
    /*    color:#172033 !important;*/
    /*    border-color:#dce2ec !important;*/
    /*    box-shadow:none !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .header-search-input::placeholder {*/
    /*    color:#7b879c !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .header-search-input:focus {*/
    /*    background:#ffffff !important;*/
    /*    border-color:#7c3aed !important;*/
    /*    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .header-search-submit {*/
    /*    background:#ede9fe !important;*/
    /*    color:#6d28d9 !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .header-search-submit:hover {*/
    /*    background:#7c3aed !important;*/
    /*    color:#ffffff !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .navbar .navbar-menu-wrapper .navbar-toggler[data-toggle="minimize"],*/
    /*html[data-pos-theme="light"] .navbar .navbar-menu-wrapper .pos-sidebar-toggle,*/
    /*html[data-pos-theme="light"] .count-indicator,*/
    /*html[data-pos-theme="light"] .pos-theme-toggle,*/
    /*html[data-pos-theme="light"] .nav-profile .dropdown-toggle {*/
    /*    background:#ffffff !important;*/
    /*    color:#334155 !important;*/
    /*    border-color:#dce2ec !important;*/
    /*    box-shadow:0 4px 12px rgba(30,41,59,.05) !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .navbar .navbar-menu-wrapper .navbar-toggler[data-toggle="minimize"]:hover,*/
    /*html[data-pos-theme="light"] .navbar .navbar-menu-wrapper .pos-sidebar-toggle:hover,*/
    /*html[data-pos-theme="light"] .count-indicator:hover,*/
    /*html[data-pos-theme="light"] .pos-theme-toggle:hover,*/
    /*html[data-pos-theme="light"] .nav-profile .dropdown-toggle:hover {*/
    /*    background:#7c3aed !important;*/
    /*    color:#ffffff !important;*/
    /*    border-color:#7c3aed !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .navbar .nav-link,*/
    /*html[data-pos-theme="light"] .navbar-nav.navbar-nav-right .text-white,*/
    /*html[data-pos-theme="light"] .nav-profile span {*/
    /*    color:#334155 !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .navbar .nav-link:hover {*/
    /*    color:#6d28d9 !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .search-suggestions,*/
    /*html[data-pos-theme="light"] .navbar-dropdown.dropdown-menu {*/
    /*    background:#ffffff !important;*/
    /*    color:#172033 !important;*/
    /*    border-color:#dce2ec !important;*/
    /*    box-shadow:0 18px 45px rgba(30,41,59,.14) !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .search-suggestion-item {*/
    /*    border-color:#eef1f5 !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .search-suggestion-item:hover,*/
    /*html[data-pos-theme="light"] .search-suggestion-item.active,*/
    /*html[data-pos-theme="light"] .navbar-dropdown .dropdown-item:hover {*/
    /*    background:#f3efff !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .search-suggestion-title,*/
    /*html[data-pos-theme="light"] .navbar-dropdown .dropdown-header,*/
    /*html[data-pos-theme="light"] .navbar-dropdown .dropdown-item,*/
    /*html[data-pos-theme="light"] .navbar-dropdown .preview-subject {*/
    /*    color:#172033 !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .search-suggestion-keywords,*/
    /*html[data-pos-theme="light"] .navbar-dropdown .text-muted {*/
    /*    color:#718096 !important;*/
    /*}*/

    /*html[data-pos-theme="light"] .client-logo-upload-trigger::after,*/
    /*html[data-pos-theme="light"] .count-indicator .count {*/
    /*    border-color:#ffffff !important;*/
    /*}*/

    /*@media (max-width: 991px) {*/
    /*    .pos-theme-toggle {*/
    /*        width:38px;*/
    /*        height:38px;*/
    /*        min-width:38px;*/
    /*        border-radius:11px;*/
    /*    }*/
    /*}*/
    
    
    
    /* =========================================================
   LIGHT MODE - BLACK TOPBAR LIKE REFERENCE
========================================================= */

html[data-pos-theme="light"] .navbar.my-navbar,
html[data-pos-theme="light"] .navbar .navbar-menu-wrapper{
    background:#050505 !important;
    background-image:none !important;
    color:#ffffff !important;
    border-color:#1f1f1f !important;
}

html[data-pos-theme="light"] .navbar.my-navbar{
    box-shadow:0 8px 24px rgba(0,0,0,.18) !important;
}

/* Search field */
html[data-pos-theme="light"] .header-search-input{
    background:#090909 !important;
    color:#ffffff !important;
    border:1px solid #343434 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] .header-search-input::placeholder{
    color:#a3a3a3 !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .header-search-input:hover{
    border-color:#525252 !important;
}

html[data-pos-theme="light"] .header-search-input:focus{
    background:#0c0c0c !important;
    color:#ffffff !important;
    border-color:#ffffff !important;
    box-shadow:0 0 0 3px rgba(255,255,255,.10) !important;
}

/* Search button */
html[data-pos-theme="light"] .header-search-submit{
    background:#171717 !important;
    color:#ffffff !important;
    border:1px solid #303030 !important;
}

html[data-pos-theme="light"] .header-search-submit:hover{
    background:#ffffff !important;
    color:#050505 !important;
    border-color:#ffffff !important;
}

/* Hamburger, moon, bell and profile */
html[data-pos-theme="light"]
.navbar .navbar-menu-wrapper
.navbar-toggler[data-toggle="minimize"],

html[data-pos-theme="light"]
.navbar .navbar-menu-wrapper
.pos-sidebar-toggle,

html[data-pos-theme="light"] .count-indicator,

html[data-pos-theme="light"] .pos-theme-toggle,

html[data-pos-theme="light"] .nav-profile .dropdown-toggle{
    background:#090909 !important;
    color:#ffffff !important;
    border:1px solid #303030 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"]
.navbar .navbar-menu-wrapper
.navbar-toggler[data-toggle="minimize"]:hover,

html[data-pos-theme="light"]
.navbar .navbar-menu-wrapper
.pos-sidebar-toggle:hover,

html[data-pos-theme="light"] .count-indicator:hover,

html[data-pos-theme="light"] .pos-theme-toggle:hover,

html[data-pos-theme="light"] .nav-profile .dropdown-toggle:hover{
    background:#1a1a1a !important;
    color:#ffffff !important;
    border-color:#5a5a5a !important;
}

/* Force all topbar icons white */
html[data-pos-theme="light"] .navbar .icon-menu,
html[data-pos-theme="light"] .navbar .icon-bell,
html[data-pos-theme="light"] .navbar .icon-ellipsis,
html[data-pos-theme="light"] .navbar .nav-link i,
html[data-pos-theme="light"] .pos-theme-toggle i{
    color:#ffffff !important;
}

/* Current emoji moon/sun ko monochrome karein */
html[data-pos-theme="light"] .pos-theme-toggle span{
    filter:grayscale(1) brightness(3) !important;
}

/* Topbar text */
html[data-pos-theme="light"] .navbar .nav-link,
html[data-pos-theme="light"]
.navbar-nav.navbar-nav-right .text-white,

html[data-pos-theme="light"] .nav-profile span{
    color:#ffffff !important;
}

html[data-pos-theme="light"] .navbar .nav-link:hover{
    color:#ffffff !important;
}

/* Profile image */
html[data-pos-theme="light"] .nav-profile img{
    border-color:#ffffff !important;
}

html[data-pos-theme="light"]
.client-logo-upload-trigger:hover img{
    border-color:#ffffff !important;
}

/* Green logo plus */
html[data-pos-theme="light"]
.client-logo-upload-trigger::after{
    border-color:#050505 !important;
}

/* Notification badge */
html[data-pos-theme="light"]
.count-indicator .count{
    border-color:#050505 !important;
}

/* Search result dropdown white rakhein */
html[data-pos-theme="light"] .search-suggestions{
    background:#ffffff !important;
    color:#111111 !important;
    border:1px solid #e5e7eb !important;
    box-shadow:0 18px 45px rgba(0,0,0,.16) !important;
}

html[data-pos-theme="light"] .search-suggestion-item{
    border-bottom-color:#eeeeee !important;
}

html[data-pos-theme="light"] .search-suggestion-item:hover,
html[data-pos-theme="light"] .search-suggestion-item.active{
    background:#f4f4f5 !important;
}

html[data-pos-theme="light"] .search-suggestion-title{
    color:#111111 !important;
}

html[data-pos-theme="light"] .search-suggestion-keywords{
    color:#666666 !important;
}

html[data-pos-theme="light"] .search-suggestion-right{
    color:#111111 !important;
}

html[data-pos-theme="light"] .search-empty{
    color:#525252 !important;
}

html[data-pos-theme="light"] .search-highlight{
    color:#000000 !important;
}

/* Notification and profile dropdowns white */
html[data-pos-theme="light"]
.navbar-dropdown.dropdown-menu{
    background:#ffffff !important;
    color:#111111 !important;
    border:1px solid #e5e7eb !important;
    box-shadow:0 18px 45px rgba(0,0,0,.16) !important;
}

html[data-pos-theme="light"]
.navbar-dropdown .dropdown-header,

html[data-pos-theme="light"]
.navbar-dropdown .dropdown-item,

html[data-pos-theme="light"]
.navbar-dropdown .preview-subject{
    color:#111111 !important;
}

html[data-pos-theme="light"]
.navbar-dropdown .text-muted{
    color:#6b7280 !important;
}

html[data-pos-theme="light"]
.navbar-dropdown .dropdown-item:hover{
    background:#f4f4f5 !important;
    color:#000000 !important;
}

/* =========================================================
   LIGHT MODE TOPBAR - NAVY PURPLE REFERENCE THEME
   Content pages light rahenge, topbar purple/dark rahega
========================================================= */

html[data-pos-theme="light"] .navbar.my-navbar,
html[data-pos-theme="light"] .navbar .navbar-menu-wrapper{
    background:
        radial-gradient(
            circle at 78% 0%,
            rgba(139,92,246,.32),
            transparent 30%
        ),
        linear-gradient(
            100deg,
            #07162f 0%,
            #111b47 18%,
            #281168 48%,
            #3914a0 72%,
            #4c1fd0 100%
        ) !important;

    color:#ffffff !important;
    border-bottom:1px solid rgba(255,255,255,.10) !important;
}

html[data-pos-theme="light"] .navbar.my-navbar{
    box-shadow:
        0 10px 28px rgba(15,23,42,.22),
        0 4px 18px rgba(76,29,149,.15) !important;
}

/* =========================================================
   SIDEBAR TOGGLE
========================================================= */

html[data-pos-theme="light"]
.navbar .navbar-menu-wrapper
.navbar-toggler[data-toggle="minimize"],

html[data-pos-theme="light"]
.navbar .navbar-menu-wrapper
.pos-sidebar-toggle{
    background:rgba(7,22,47,.76) !important;
    color:#ffffff !important;

    border:1px solid rgba(255,255,255,.12) !important;

    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.06),
        0 8px 20px rgba(0,0,0,.16) !important;
}

html[data-pos-theme="light"]
.navbar .navbar-menu-wrapper
.navbar-toggler[data-toggle="minimize"]:hover,

html[data-pos-theme="light"]
.navbar .navbar-menu-wrapper
.pos-sidebar-toggle:hover{
    background:rgba(124,58,237,.65) !important;
    color:#ffffff !important;
    border-color:rgba(196,181,253,.48) !important;
}

/* =========================================================
   TOPBAR SEARCH
========================================================= */

html[data-pos-theme="light"] .header-search-input{
    background:rgba(15,23,42,.40) !important;
    color:#ffffff !important;

    border:1px solid rgba(255,255,255,.16) !important;

    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.06),
        0 8px 22px rgba(0,0,0,.12) !important;

    backdrop-filter:blur(14px) !important;
    -webkit-backdrop-filter:blur(14px) !important;
}

html[data-pos-theme="light"]
.header-search-input::placeholder{
    color:rgba(255,255,255,.68) !important;
    opacity:1 !important;
}

html[data-pos-theme="light"]
.header-search-input:hover{
    border-color:rgba(196,181,253,.38) !important;
}

html[data-pos-theme="light"]
.header-search-input:focus{
    background:rgba(15,23,42,.58) !important;
    color:#ffffff !important;

    border-color:#a78bfa !important;

    box-shadow:
        0 0 0 3px rgba(167,139,250,.18),
        0 10px 26px rgba(0,0,0,.18) !important;
}

html[data-pos-theme="light"]
.header-search-submit{
    background:
        linear-gradient(
            135deg,
            rgba(124,58,237,.86),
            rgba(109,76,255,.92)
        ) !important;

    color:#ffffff !important;
    border:1px solid rgba(255,255,255,.12) !important;
}

html[data-pos-theme="light"]
.header-search-submit:hover{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
}

/* =========================================================
   THEME, NOTIFICATION AND PROFILE BOXES
========================================================= */

html[data-pos-theme="light"] .count-indicator,
html[data-pos-theme="light"] .pos-theme-toggle,
html[data-pos-theme="light"] .nav-profile .dropdown-toggle{
    background:rgba(15,23,42,.34) !important;
    color:#ffffff !important;

    border:1px solid rgba(255,255,255,.13) !important;

    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.05),
        0 8px 20px rgba(0,0,0,.13) !important;

    backdrop-filter:blur(12px) !important;
    -webkit-backdrop-filter:blur(12px) !important;
}

html[data-pos-theme="light"] .count-indicator:hover,
html[data-pos-theme="light"] .pos-theme-toggle:hover,
html[data-pos-theme="light"]
.nav-profile .dropdown-toggle:hover{
    background:rgba(124,58,237,.46) !important;
    color:#ffffff !important;
    border-color:rgba(196,181,253,.42) !important;
}

/* Icons */
html[data-pos-theme="light"] .navbar .nav-link,
html[data-pos-theme="light"] .navbar .nav-link i,
html[data-pos-theme="light"] .navbar .icon-menu,
html[data-pos-theme="light"] .navbar .icon-bell,
html[data-pos-theme="light"] .navbar .icon-ellipsis{
    color:#ffffff !important;
}

html[data-pos-theme="light"]
.pos-theme-toggle span{
    filter:none !important;
    color:#fde047 !important;
}

/* Topbar text */
html[data-pos-theme="light"]
.navbar-nav.navbar-nav-right .text-white,

html[data-pos-theme="light"]
.nav-profile span{
    color:#ffffff !important;
}

/* User image */
html[data-pos-theme="light"] .nav-profile img{
    border:2px solid #a78bfa !important;

    box-shadow:
        0 0 0 3px rgba(167,139,250,.13),
        0 8px 18px rgba(0,0,0,.16) !important;
}

html[data-pos-theme="light"]
.client-logo-upload-trigger:hover img{
    border-color:#c4b5fd !important;
}

/* Green plus badge */
html[data-pos-theme="light"]
.client-logo-upload-trigger::after{
    border-color:#321276 !important;
}

/* Notification count */
html[data-pos-theme="light"]
.count-indicator .count{
    background:#8b5cf6 !important;
    color:#ffffff !important;
    border-color:#321276 !important;
}

/* =========================================================
   SEARCH AND PROFILE DROPDOWNS
========================================================= */

html[data-pos-theme="light"] .search-suggestions,
html[data-pos-theme="light"]
.navbar-dropdown.dropdown-menu{
    background:#ffffff !important;
    color:#172033 !important;

    border:1px solid #e2e8f0 !important;

    box-shadow:
        0 20px 50px rgba(15,23,42,.18) !important;
}

html[data-pos-theme="light"]
.search-suggestion-item{
    border-color:#eef1f5 !important;
}

html[data-pos-theme="light"]
.search-suggestion-item:hover,

html[data-pos-theme="light"]
.search-suggestion-item.active,

html[data-pos-theme="light"]
.navbar-dropdown .dropdown-item:hover{
    background:#f3efff !important;
}

html[data-pos-theme="light"]
.search-suggestion-title,

html[data-pos-theme="light"]
.navbar-dropdown .dropdown-header,

html[data-pos-theme="light"]
.navbar-dropdown .dropdown-item,

html[data-pos-theme="light"]
.navbar-dropdown .preview-subject{
    color:#172033 !important;
}

html[data-pos-theme="light"]
.search-suggestion-keywords,

html[data-pos-theme="light"]
.navbar-dropdown .text-muted{
    color:#718096 !important;
}

html[data-pos-theme="light"]
.search-suggestion-right{
    color:#6d28d9 !important;
}

html[data-pos-theme="light"]
.search-highlight{
    color:#5b21b6 !important;
}


<?php if ($use_custom_theme): ?>
/* =========================================================
   CLIENT CUSTOM TOPBAR THEME
   This block is generated only when use_custom_theme = 1.
   When disabled, all original ONEERP topbar CSS above remains active.
========================================================= */

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.navbar-menu-wrapper{
    background:
        <?= htmlspecialchars(
            $navbar_bg,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    background-image:none !important;

    color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

/* Topbar text and icons */
html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.nav-link,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.nav-link i,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.icon-menu,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.icon-bell,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.icon-ellipsis,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.navbar-nav-right .text-white,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.nav-profile span,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.pos-theme-toggle{
    color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

/* Search field keeps the selected topbar visible */
html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.header-search-input{
    background:rgba(255,255,255,.12) !important;

    color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    border-color:rgba(255,255,255,.24) !important;
}

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.header-search-input::placeholder{
    color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    opacity:.72 !important;
}

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.header-search-input:focus{
    background:rgba(255,255,255,.16) !important;

    color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    border-color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    box-shadow:0 0 0 3px rgba(255,255,255,.14) !important;
}

/* Topbar action boxes */
html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.navbar-menu-wrapper
.navbar-toggler[data-toggle="minimize"],

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.navbar-menu-wrapper
.pos-sidebar-toggle,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.count-indicator,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.pos-theme-toggle,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.nav-profile .dropdown-toggle,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.header-search-submit{
    background:rgba(255,255,255,.12) !important;

    color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    border-color:rgba(255,255,255,.22) !important;
}

/* Client-selected hover color */
html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.navbar-menu-wrapper
.navbar-toggler[data-toggle="minimize"]:hover,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.navbar-menu-wrapper
.pos-sidebar-toggle:hover,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.count-indicator:hover,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.pos-theme-toggle:hover,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.nav-profile .dropdown-toggle:hover,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.header-search-submit:hover,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.nav-link:hover{
    background:
        <?= htmlspecialchars(
            $navbar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    border-color:
        <?= htmlspecialchars(
            $navbar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

/* Image and notification badge borders follow selected header */
html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.nav-profile img{
    border-color:
        <?= htmlspecialchars(
            $navbar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.client-logo-upload-trigger::after,

html[data-pos-theme]
.navbar.my-navbar.client-custom-topbar
.count-indicator .count{
    border-color:
        <?= htmlspecialchars(
            $navbar_bg,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}
<?php endif; ?>



/* =========================================================
   ONEERP SUBSCRIPTION EXPIRY MARQUEE
========================================================= */

.oneerp-subscription-strip {
    position: fixed;
    top: 70px;
    left: 0;
    right: 0;
    z-index: 1028;

    min-height: 54px;
    padding: 6px 18px;

    display: flex;
    align-items: center;
    gap: 14px;

    color: #ffffff;

    border-top: 1px solid rgba(255, 255, 255, 0.12);
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);

    box-shadow:
        0 10px 28px rgba(15, 23, 42, 0.24),
        inset 0 1px 0 rgba(255, 255, 255, 0.10);
}

/*
|--------------------------------------------------------------------------
| Normal warning: last 7 days
|--------------------------------------------------------------------------
*/

.oneerp-subscription-warning {
    background:
        radial-gradient(
            circle at 80% 0%,
            rgba(253, 230, 138, 0.25),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #b45309,
            #f59e0b
        );
}

/*
|--------------------------------------------------------------------------
| Urgent warning: last 24 hours
|--------------------------------------------------------------------------
*/

.oneerp-subscription-urgent {
    background:
        radial-gradient(
            circle at 80% 0%,
            rgba(254, 202, 202, 0.25),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #c2410c,
            #ef4444
        );
}

/*
|--------------------------------------------------------------------------
| Subscription expired
|--------------------------------------------------------------------------
*/

.oneerp-subscription-expired {
    background:
        radial-gradient(
            circle at 80% 0%,
            rgba(254, 202, 202, 0.20),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #7f1d1d,
            #dc2626
        );
}

/*
|--------------------------------------------------------------------------
| Content ko marquee ke neeche space dene ke liye
|--------------------------------------------------------------------------
*/
.oneerp-subscription-spacer {
    width: 100%;
    height: 66px;
    min-height: 66px;
    flex: 0 0 66px;
}
/*
|--------------------------------------------------------------------------
| Bell icon
|--------------------------------------------------------------------------
*/

.oneerp-subscription-bell {
    flex: 0 0 auto;

    width: 38px;
    height: 38px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    color: #ffffff;
    font-size: 17px;

    border-radius: 50%;
    background: rgba(0, 0, 0, 0.16);
}

/*
|--------------------------------------------------------------------------
| Actual marquee
|--------------------------------------------------------------------------
*/

.oneerp-subscription-marquee-wrap {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}

.oneerp-subscription-marquee-wrap marquee {
    display: block;
    width: 100%;

    color: #ffffff;
    font-size: 13px;
    font-weight: 800;
    line-height: 38px;
}

/*
|--------------------------------------------------------------------------
| Countdown
|--------------------------------------------------------------------------
*/

.oneerp-subscription-countdown {
    flex: 0 0 auto;

    display: flex;
    align-items: center;
    gap: 6px;
}

.oneerp-subscription-time-box {
    width: 54px;
    min-height: 42px;
    padding: 4px;

    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;

    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: 9px;
    background: rgba(0, 0, 0, 0.16);
}

.oneerp-subscription-time-box strong {
    color: #ffffff;
    font-size: 15px;
    font-weight: 900;
    line-height: 1;
}

.oneerp-subscription-time-box span {
    margin-top: 4px;

    color: rgba(255, 255, 255, 0.88);
    font-size: 8px;
    font-weight: 800;
    line-height: 1;
    text-transform: uppercase;
}

/*
|--------------------------------------------------------------------------
| Renew button
|--------------------------------------------------------------------------
*/

.oneerp-subscription-renew {
    flex: 0 0 auto;

    min-height: 38px;
    padding: 7px 14px;

    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;

    color: #7f1d1d !important;
    font-size: 12px;
    font-weight: 900;
    text-decoration: none !important;

    border-radius: 9px;
    background: #ffffff;

    box-shadow:
        0 7px 18px rgba(0, 0, 0, 0.18);

    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.oneerp-subscription-renew:hover {
    color: #7f1d1d !important;
    text-decoration: none !important;

    transform: translateY(-1px);

    box-shadow:
        0 10px 24px rgba(0, 0, 0, 0.24);
}

/*
|--------------------------------------------------------------------------
| Close button
|--------------------------------------------------------------------------
*/

.oneerp-subscription-close {
    flex: 0 0 auto;

    width: 30px;
    height: 30px;
    padding: 0;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    color: #ffffff;
    font-size: 20px;
    line-height: 1;

    border: 0;
    border-radius: 50%;
    background: transparent;

    cursor: pointer;
}

.oneerp-subscription-close:hover {
    color: #ffffff;
    background: rgba(0, 0, 0, 0.16);
}

/*
|--------------------------------------------------------------------------
| Responsive
|--------------------------------------------------------------------------
*/

@media (max-width: 1050px) {
    .oneerp-subscription-strip {
        gap: 8px;
        padding: 6px 10px;
    }

    .oneerp-subscription-time-box {
        width: 44px;
    }

    .oneerp-subscription-marquee-wrap marquee {
        font-size: 12px;
    }
}

@media (max-width: 767px) {
    .oneerp-subscription-strip {
        min-height: 48px;
        padding: 5px 8px;
    }

 .oneerp-subscription-spacer {
    height: 58px;
    min-height: 58px;
}

    .oneerp-subscription-bell {
        display: none;
    }

    .oneerp-subscription-countdown {
        display: none;
    }

    .oneerp-subscription-renew {
        padding: 6px 9px;
        font-size: 10px;
    }

    .oneerp-subscription-marquee-wrap marquee {
        line-height: 34px;
        font-size: 11px;
    }
}


</style>

<nav class="navbar my-navbar col-lg-12 col-12 p-0 fixed-top d-flex flex-row<?= $use_custom_theme ? ' client-custom-topbar' : '' ?>">
    <div class="navbar-menu-wrapper d-flex align-items-center justify-content-end">
        <button class="navbar-toggler navbar-toggler align-self-center pos-sidebar-toggle" type="button" data-toggle="minimize" aria-label="Toggle sidebar">
            <span class="icon-menu"></span>
        </button>

        <!-- Center Search Bar -->
        <div class="header-search-wrap">
            <div class="header-search-box">
                <input
                    type="text"
                    id="globalSearch"
                    name="header_search"
                    class="header-search-input"
                    placeholder="Search pages, modules, reports..."
                    autocomplete="off"
                >

                <button type="button" class="header-search-submit" id="globalSearchBtn" aria-label="Search">
                    <span>⌕</span>
                </button>

                <div class="search-suggestions" id="searchSuggestions"></div>
            </div>
        </div>

        <ul class="navbar-nav navbar-nav-right">
            <div class="text-white text-center" style="font-weight:bold; font-size:16px; padding:20px 10px;">
                <?= htmlspecialchars($branch_name) ?>
            </div>

            <!-- BUSINESS DAY BUTTON -->
            <li class="nav-item">
                <button
                    type="button"
                    id="oneerpBusinessDayBtn"
                    class="oneerp-business-day-btn<?= $oneerpBusinessSession ? ' is-open' : '' ?>"
                    title="<?= $oneerpBusinessSession ? 'Close Business Day' : 'Start Business Day' ?>"
                    aria-label="<?= $oneerpBusinessSession ? 'Close Business Day' : 'Start Business Day' ?>"
                >
                    <span class="oneerp-business-day-power" aria-hidden="true">⏻</span>

                    <span class="oneerp-business-day-label">
                        <?= $oneerpBusinessSession
                            ? 'Close Business Day'
                            : 'Start Business Day' ?>
                    </span>
                </button>
            </li>

            <!-- POS LIGHT / DARK THEME BUTTON -->
            <li class="nav-item pos-theme-toggle-wrap">
                <button
                    class="pos-theme-toggle"
                    id="posThemeToggle"
                    type="button"
                    title="Switch to dark mode"
                    aria-label="Switch to dark mode"
                    aria-pressed="false"
                >
                    <span id="posThemeIcon" aria-hidden="true">🌙</span>
                </button>
            </li>

            <li class="nav-item dropdown">
                <a class="nav-link count-indicator dropdown-toggle" id="notificationDropdown" href="#" data-bs-toggle="dropdown">
                    <i class="icon-bell mx-0"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="count"><?= (int)$unread_count ?></span>
                    <?php endif; ?>
                </a>

                <div class="dropdown-menu dropdown-menu-right navbar-dropdown preview-list" aria-labelledby="notificationDropdown">
                    <p class="mb-0 font-weight-normal float-left dropdown-header">Notifications</p>

                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $n): ?>
                            <a class="dropdown-item preview-item notif-item <?= (int)$n['is_read'] === 0 ? 'bg-light' : '' ?>"
                               href="<?= htmlspecialchars($n['link'] ?? '#') ?>"
                               data-id="<?= (int)$n['id'] ?>">
                                <div class="preview-thumbnail">
                                    <div class="preview-icon bg-info">
                                        <i class="ti-bell mx-0"></i>
                                    </div>
                                </div>
                                <div class="preview-item-content">
                                    <h6 class="preview-subject font-weight-normal"><?= htmlspecialchars($n['message']) ?></h6>
                                    <p class="font-weight-light small-text mb-0 text-muted"><?= date('d M Y H:i', strtotime($n['created_at'])) ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="dropdown-item">No notifications</span>
                    <?php endif; ?>
                </div>
            </li>

            <li class="nav-item nav-profile dropdown">
                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" id="profileDropdown">
                    <span class="client-logo-upload-trigger" id="clientLogoUploadTrigger" title="Click to upload client logo">
                        <img id="topbarClientLogoImg" src="<?= htmlspecialchars($client_logo_src) ?>" alt="client logo" onerror="this.src='assets/images/faces/face28.jpg';" />
                    </span>
                    <span class="ms-2"><?= htmlspecialchars($user_name) ?></span>
                </a>
                <input type="file" id="topbarClientLogoInput" accept="image/png,image/jpeg,image/jpg,image/webp,image/gif" style="display:none;">
                <div class="dropdown-menu dropdown-menu-right navbar-dropdown" aria-labelledby="profileDropdown">
                    <a class="dropdown-item" href="setting.php">
                        <i class="ti-settings text-primary"></i> Settings
                    </a>
                    <a class="dropdown-item" href="/onerp/app/onerp/app/app/auth/logout.php">
                        <i class="ti-power-off text-primary"></i> Logout
                    </a>
                </div>
            </li>

            <li class="nav-item nav-settings d-none d-lg-flex">
                <a class="nav-link" href="#">
                    <i class="icon-ellipsis"></i>
                </a>
            </li>
        </ul>

        <button class="navbar-toggler navbar-toggler-right d-lg-none align-self-center pos-sidebar-toggle" type="button" data-toggle="offcanvas" aria-label="Toggle sidebar">
            <span class="icon-menu"></span>
        </button>
    </div>
</nav>

<?php
/* ========================================================================
   BUSINESS DAY MODAL
   UI-only include; existing topbar and subscription workflow remain unchanged.
======================================================================== */
$businessSessionModalPath =
    __DIR__ . '/business_session_modal.php';

if (is_file($businessSessionModalPath)) {
    include $businessSessionModalPath;
}
?>


<?php if (
    !empty($oneerpSubscriptionState)
    && !empty($oneerpSubscriptionState['show_alert'])
): ?>

    <?php
    /*
    |--------------------------------------------------------------------------
    | Safe alert type
    |--------------------------------------------------------------------------
    */

    $subscriptionAlertType =
        (string)(
            $oneerpSubscriptionState['alert_type']
            ?? 'warning'
        );

    $allowedSubscriptionAlertTypes = [
        'warning',
        'urgent',
        'expired'
    ];

    if (!in_array(
        $subscriptionAlertType,
        $allowedSubscriptionAlertTypes,
        true
    )) {
        $subscriptionAlertType = 'warning';
    }

    $subscriptionExpiryIso =
        (string)(
            $oneerpSubscriptionState['expires_iso']
            ?? ''
        );
    ?>

    <div
        class="
            oneerp-subscription-strip
            oneerp-subscription-<?= htmlspecialchars(
                $subscriptionAlertType,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        "
        id="oneerpSubscriptionStrip"
        data-expiry="<?= htmlspecialchars(
            $subscriptionExpiryIso,
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
        data-expired="<?= !empty(
            $oneerpSubscriptionState['is_expired']
        ) ? '1' : '0' ?>"
    >
        <div class="oneerp-subscription-bell">
            <i class="icon-bell"></i>
        </div>

        <!-- Subscription message marquee -->
        <div class="oneerp-subscription-marquee-wrap">
            <marquee
                id="oneerpSubscriptionMarquee"
                behavior="scroll"
                direction="left"
                scrollamount="5"
                scrolldelay="30"
                loop="-1"
                onmouseenter="this.stop();"
                onmouseleave="this.start();"
            >
                <?= htmlspecialchars(
                    $oneerpSubscriptionState['message']
                    ?? 'Aap ki subscription expire hone wali hai. Abhi renew karein.',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </marquee>
        </div>

        <?php if (empty(
            $oneerpSubscriptionState['is_expired']
        )): ?>

            <div
                class="oneerp-subscription-countdown"
                id="oneerpSubscriptionCountdown"
            >
                <div class="oneerp-subscription-time-box">
                    <strong id="oneerpSubDays">00</strong>
                    <span>Days</span>
                </div>

                <div class="oneerp-subscription-time-box">
                    <strong id="oneerpSubHours">00</strong>
                    <span>Hours</span>
                </div>

                <div class="oneerp-subscription-time-box">
                    <strong id="oneerpSubMinutes">00</strong>
                    <span>Minutes</span>
                </div>

                <div class="oneerp-subscription-time-box">
                    <strong id="oneerpSubSeconds">00</strong>
                    <span>Seconds</span>
                </div>
            </div>

        <?php endif; ?>

        <a
            href="/onerp/app/public/app/views/renew_subscription.php"
            class="oneerp-subscription-renew"
        >
            Renew Now
            <span aria-hidden="true">→</span>
        </a>

        <button
            type="button"
            class="oneerp-subscription-close"
            id="oneerpSubscriptionClose"
            title="Close notification"
            aria-label="Close subscription notification"
        >
            ×
        </button>
    </div>

    <div
        class="oneerp-subscription-spacer"
        id="oneerpSubscriptionSpacer"
    ></div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /*
      Topbar + Sidebar sync JS
      - Desktop: sidebar collapse/open
      - Mobile: sidebar open/close with overlay auto-close
      - Duplicate binding safe for head.php/sidebar.php scripts
    */

    const toggleButtons = document.querySelectorAll(
        '.pos-sidebar-toggle, .navbar-toggler[data-toggle="minimize"], .navbar-toggler[data-toggle="offcanvas"], #sidebarToggle, #sidebar-toggle, .sidebar-toggle'
    );

    function isMobileView() {
        return window.innerWidth <= 991;
    }

    function openMobileSidebar() {
        document.body.classList.add('sidebar-open');
        document.body.classList.remove('sidebar-collapsed');
    }

    function closeMobileSidebar() {
        document.body.classList.remove('sidebar-open');
    }

    function toggleMobileSidebar() {
        if (document.body.classList.contains('sidebar-open')) {
            closeMobileSidebar();
        } else {
            openMobileSidebar();
        }
    }

    function toggleDesktopSidebar() {
        document.body.classList.toggle('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
    }

    toggleButtons.forEach(function (btn) {
        if (!btn || btn.dataset.posSidebarBound === '1') return;
        btn.dataset.posSidebarBound = '1';

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            if (isMobileView()) {
                toggleMobileSidebar();
            } else {
                toggleDesktopSidebar();
            }
        });
    });

    /* Mobile: outside click / overlay click par sidebar close */
    document.addEventListener('click', function (e) {
        if (!isMobileView()) return;
        if (!document.body.classList.contains('sidebar-open')) return;

        const clickedInsideSidebar =
            e.target.closest('.sidebar-wrap') ||
            e.target.closest('.pos-sidebar') ||
            e.target.closest('.container-scroll > .sidebar');

        const clickedToggle =
            e.target.closest('.pos-sidebar-toggle') ||
            e.target.closest('.navbar-toggler[data-toggle="minimize"]') ||
            e.target.closest('.navbar-toggler[data-toggle="offcanvas"]') ||
            e.target.closest('#sidebarToggle') ||
            e.target.closest('#sidebar-toggle') ||
            e.target.closest('.sidebar-toggle');

        if (!clickedInsideSidebar && !clickedToggle) {
            closeMobileSidebar();
        }
    });

    /* Mobile: normal sidebar link click par close, dropdown toggle par close nahi */
    document.querySelectorAll('.sidebar-wrap a, .pos-sidebar a, .container-scroll > .sidebar a').forEach(function (link) {
        if (!link || link.dataset.sidebarAutoCloseBound === '1') return;
        link.dataset.sidebarAutoCloseBound = '1';

        link.addEventListener('click', function () {
            if (!isMobileView()) return;

            const href = (link.getAttribute('href') || '').trim();
            const isDropdownToggle =
                link.classList.contains('menu-toggle') ||
                href.startsWith('#') ||
                link.getAttribute('data-bs-toggle') === 'collapse' ||
                link.getAttribute('data-toggle') === 'collapse';

            if (isDropdownToggle) return;

            closeMobileSidebar();
        });
    });

    /* ESC key se mobile sidebar close */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isMobileView()) {
            closeMobileSidebar();
        }
    });

    /* Resize safety: mobile/desktop classes mix na hon */
    window.addEventListener('resize', function () {
        if (isMobileView()) {
            document.body.classList.remove('sidebar-collapsed');
        } else {
            document.body.classList.remove('sidebar-open');
        }
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const trigger = document.getElementById('clientLogoUploadTrigger');
    const input   = document.getElementById('topbarClientLogoInput');
    const img     = document.getElementById('topbarClientLogoImg');

    if (!trigger || !input || !img) return;

    trigger.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        input.click();
    });

    input.addEventListener('click', function (e) {
        e.stopPropagation();
    });

    input.addEventListener('change', function () {
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) return;

        if (!file.type || file.type.indexOf('image/') !== 0) {
            alert('Please select a valid image file.');
            input.value = '';
            return;
        }

        if (file.size > (3 * 1024 * 1024)) {
            alert('Image size must be less than 3MB.');
            input.value = '';
            return;
        }

        const formData = new FormData();
        formData.append('client_logo', file);

        trigger.classList.add('uploading');

        fetch('/onerp/app/public/app/views/ajax_upload_client_logo.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        })
        .then(async function (response) {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(text || 'Invalid server response.');
            }
        })
        .then(function (data) {
            if (!data.success) {
                alert(data.message || 'Logo upload failed.');
                return;
            }

            const logoUrl = data.logo_url || data.logo || '';
            if (logoUrl) {
                img.src = logoUrl + (logoUrl.indexOf('?') === -1 ? '?v=' : '&v=') + Date.now();
            }
        })
        .catch(function (err) {
            alert(err.message || 'Logo upload failed.');
        })
        .finally(function () {
            trigger.classList.remove('uploading');
            input.value = '';
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('globalSearch');
    const suggestionsBox = document.getElementById('searchSuggestions');
    const searchBtn = document.getElementById('globalSearchBtn');

    if (!input || !suggestionsBox) return;

    let selectedIndex = -1;
    let currentSuggestions = [];

    const searchRoutes = [
        { title: 'Dashboard', keywords: ['dashboard', 'home', 'main'], url: 'dashboard.php', type: 'Page' },
        { title: 'POS Screen', keywords: ['pos screen', 'pos', 'billing', 'counter'], url: 'pos_screen.php', type: 'Page' },
        { title: 'Daily Sales', keywords: ['sale', 'sales', 'sell'], url: 'daily_sales.php', type: 'Page' },
        { title: 'Orders', keywords: ['invoice', 'invoices', 'bill', 'bills', 'order', 'orders'], url: 'orders.php', type: 'Module' },
        { title: 'Quotations', keywords: ['quotation', 'quotations', 'quote', 'quotes'], url: 'quotations.php', type: 'Module' },
        { title: 'Customers', keywords: ['customer', 'customers', 'client', 'clients'], url: 'customers.php', type: 'Module' },
        { title: 'Items', keywords: ['item', 'items', 'product', 'products'], url: 'items.php', type: 'Module' },
        { title: 'Services', keywords: ['service', 'services'], url: 'services.php', type: 'Module' },
        { title: 'Purchases', keywords: ['purchase', 'purchases'], url: 'purchases.php', type: 'Module' },
        { title: 'Suppliers', keywords: ['supplier', 'suppliers', 'vendor', 'vendors'], url: 'suppliers.php', type: 'Module' },
        { title: 'Expenses', keywords: ['expense', 'expenses'], url: 'expenses.php', type: 'Module' },
        { title: 'Staff', keywords: ['staff', 'employee', 'employees', 'user', 'users'], url: 'staff.php', type: 'Module' },
        { title: 'Reports', keywords: ['report', 'reports'], url: 'report.php', type: 'Module' },
        { title: 'Real Estate Reports', keywords: ['real estate report', 'state agency report', 'agency report'], url: 'report.php', type: 'Module' },
        { title: 'Agency Leads', keywords: ['agency lead', 'leads', 'real estate leads'], url: 'agency-lead.php', type: 'Module' },
        { title: 'Follow Ups', keywords: ['follow up', 'followups', 'follow ups'], url: 'follow-ups.php', type: 'Module' },
        { title: 'Site Visits', keywords: ['site visit', 'visits', 'property visit'], url: 'site-visit.php', type: 'Module' },
        { title: 'Properties', keywords: ['property', 'properties', 'real estate property'], url: 'properties.php', type: 'Module' },
        { title: 'Deals', keywords: ['deal', 'deals', 'conversion', 'conversions'], url: 'deals.php', type: 'Module' },
        { title: 'Agent Assignment', keywords: ['agent assignment', 'assign agent'], url: 'agent-assignment.php', type: 'Module' },
        { title: 'Agent Commissions', keywords: ['agent commission', 'commissions'], url: 'agent-commissions.php', type: 'Module' },
        { title: 'Inventory', keywords: ['stock', 'inventory'], url: 'inventory.php', type: 'Module' },
        { title: 'Branches', keywords: ['branch', 'branches'], url: 'branches.php', type: 'Module' },
        { title: 'Settings', keywords: ['setting', 'settings', 'config', 'configuration'], url: 'settings.php', type: 'Page' },
        { title: 'Profile', keywords: ['profile', 'account'], url: 'profile.php', type: 'Page' }
    ];

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function highlightText(text, query) {
        if (!query) return escapeHtml(text);
        const safeText = escapeHtml(text);
        const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp('(' + escapedQuery + ')', 'ig');
        return safeText.replace(regex, '<span class="search-highlight">$1</span>');
    }

    function getMatchedRoutes(query) {
        const q = query.toLowerCase().trim();
        if (!q) return [];

        const scored = [];

        searchRoutes.forEach(route => {
            let score = 0;
            let matched = false;

            if (route.title.toLowerCase().includes(q)) {
                score += 100;
                matched = true;
            }

            route.keywords.forEach(keyword => {
                const kw = keyword.toLowerCase();

                if (kw === q) {
                    score += 90;
                    matched = true;
                } else if (kw.startsWith(q)) {
                    score += 70;
                    matched = true;
                } else if (kw.includes(q)) {
                    score += 50;
                    matched = true;
                }

                if (q.includes(kw)) {
                    score += 30;
                    matched = true;
                }
            });

            if (matched) {
                scored.push({ ...route, score: score });
            }
        });

        scored.sort((a, b) => b.score - a.score);
        return scored.slice(0, 8);
    }

    function renderSuggestions(routes, query) {
        suggestionsBox.innerHTML = '';
        selectedIndex = -1;
        currentSuggestions = routes;

        if (!query.trim()) {
            suggestionsBox.classList.remove('show');
            return;
        }

        if (routes.length === 0) {
            suggestionsBox.innerHTML = `
                <div class="search-empty">
                    No matching page found for "<strong>${escapeHtml(query)}</strong>"
                </div>
            `;
            suggestionsBox.classList.add('show');
            return;
        }

        routes.forEach((route, index) => {
            const item = document.createElement('div');
            item.className = 'search-suggestion-item';
            item.setAttribute('data-index', index);
            item.setAttribute('data-url', route.url);

            item.innerHTML = `
                <div class="search-suggestion-left">
                    <div class="search-suggestion-title">${highlightText(route.title, query)}</div>
                    <div class="search-suggestion-keywords">${highlightText(route.keywords.join(', '), query)}</div>
                </div>
                <div class="search-suggestion-right">${escapeHtml(route.type)}</div>
            `;

            item.addEventListener('click', function () {
                window.location.href = route.url;
            });

            suggestionsBox.appendChild(item);
        });

        suggestionsBox.classList.add('show');
    }

    function updateActiveSuggestion() {
        const items = suggestionsBox.querySelectorAll('.search-suggestion-item');

        items.forEach((item, index) => {
            item.classList.toggle('active', index === selectedIndex);
        });

        if (selectedIndex >= 0 && items[selectedIndex]) {
            items[selectedIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    function openSelectedSuggestion() {
        if (selectedIndex >= 0 && currentSuggestions[selectedIndex]) {
            window.location.href = currentSuggestions[selectedIndex].url;
            return;
        }

        const query = input.value.trim();
        if (!query) return;

        const matchedRoutes = getMatchedRoutes(query);
        if (matchedRoutes.length > 0) {
            window.location.href = matchedRoutes[0].url;
        } else {
            alert('No matching page found for: ' + query);
        }
    }

    input.addEventListener('input', function () {
        const query = input.value.trim();
        const matchedRoutes = getMatchedRoutes(query);
        renderSuggestions(matchedRoutes, query);
    });

    input.addEventListener('focus', function () {
        const query = input.value.trim();
        if (query) {
            const matchedRoutes = getMatchedRoutes(query);
            renderSuggestions(matchedRoutes, query);
        }
    });

    input.addEventListener('keydown', function (e) {
        const items = suggestionsBox.querySelectorAll('.search-suggestion-item');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!items.length) return;
            selectedIndex = (selectedIndex + 1) % items.length;
            updateActiveSuggestion();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (!items.length) return;
            selectedIndex = (selectedIndex - 1 + items.length) % items.length;
            updateActiveSuggestion();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            openSelectedSuggestion();
        } else if (e.key === 'Escape') {
            suggestionsBox.classList.remove('show');
            selectedIndex = -1;
        }
    });

    if (searchBtn) {
        searchBtn.addEventListener('click', function () {
            openSelectedSuggestion();
        });
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.header-search-box')) {
            suggestionsBox.classList.remove('show');
            selectedIndex = -1;
        }
    });
});
</script>

<script>
/*
|--------------------------------------------------------------------------
| ONEERP POS LIGHT / DARK THEME TOGGLE
|--------------------------------------------------------------------------
| Existing sidebar, search, notification, logo upload and backend logic untouched.
|--------------------------------------------------------------------------
*/
(function () {
    const storageKey = 'oneerp_pos_theme';

    function getCurrentTheme() {
        return document.documentElement.getAttribute('data-pos-theme') === 'dark'
            ? 'dark'
            : 'light';
    }

    function updateThemeButton(theme) {
        const button = document.getElementById('posThemeToggle');
        const icon = document.getElementById('posThemeIcon');

        if (!button || !icon) {
            return;
        }

        if (theme === 'dark') {
            icon.textContent = '☀️';
            button.title = 'Switch to light mode';
            button.setAttribute('aria-label', 'Switch to light mode');
            button.setAttribute('aria-pressed', 'true');
        } else {
            icon.textContent = '🌙';
            button.title = 'Switch to dark mode';
            button.setAttribute('aria-label', 'Switch to dark mode');
            button.setAttribute('aria-pressed', 'false');
        }
    }

    function applyTheme(theme, saveTheme) {
        const safeTheme = theme === 'dark' ? 'dark' : 'light';

        document.documentElement.setAttribute(
            'data-pos-theme',
            safeTheme
        );

        updateThemeButton(safeTheme);

        if (saveTheme) {
            try {
                localStorage.setItem(storageKey, safeTheme);
            } catch (error) {
                console.warn('POS theme preference could not be saved.', error);
            }
        }

        document.dispatchEvent(
            new CustomEvent('oneerp:pos-theme-change', {
                detail: { theme: safeTheme }
            })
        );
    }

    function initializeThemeToggle() {
        const button = document.getElementById('posThemeToggle');

        applyTheme(getCurrentTheme(), false);

        if (!button || button.dataset.posThemeBound === '1') {
            return;
        }

        button.dataset.posThemeBound = '1';

        button.addEventListener('click', function () {
            const nextTheme = getCurrentTheme() === 'light'
                ? 'dark'
                : 'light';

            applyTheme(nextTheme, true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeThemeToggle,
            { once: true }
        );
    } else {
        initializeThemeToggle();
    }
})();
</script>



<script>
(function () {
    function initializeSubscriptionCountdown() {
        const strip = document.getElementById(
            'oneerpSubscriptionStrip'
        );

        if (!strip) {
            return;
        }

        const spacer = document.getElementById(
            'oneerpSubscriptionSpacer'
        );

        const closeButton = document.getElementById(
            'oneerpSubscriptionClose'
        );

        const marquee = document.getElementById(
            'oneerpSubscriptionMarquee'
        );

        /*
        |--------------------------------------------------------------------------
        | Close button
        |--------------------------------------------------------------------------
        */

        if (closeButton) {
            closeButton.addEventListener(
                'click',
                function () {
                    strip.style.display = 'none';

                    if (spacer) {
                        spacer.style.display = 'none';
                    }
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Agar PHP ke mutabiq pehle se expired hai
        |--------------------------------------------------------------------------
        */

        const alreadyExpired =
            strip.dataset.expired === '1';

        if (alreadyExpired) {
            return;
        }

        const expiryValue =
            strip.dataset.expiry || '';

        const expiryTimestamp =
            Date.parse(expiryValue);

        if (
            expiryValue === ''
            || Number.isNaN(expiryTimestamp)
        ) {
            console.error(
                'Subscription expiry date invalid:',
                expiryValue
            );

            return;
        }

        const daysElement = document.getElementById(
            'oneerpSubDays'
        );

        const hoursElement = document.getElementById(
            'oneerpSubHours'
        );

        const minutesElement = document.getElementById(
            'oneerpSubMinutes'
        );

        const secondsElement = document.getElementById(
            'oneerpSubSeconds'
        );

        function padNumber(number) {
            return String(number).padStart(2, '0');
        }

        function setTime(
            days,
            hours,
            minutes,
            seconds
        ) {
            if (daysElement) {
                daysElement.textContent =
                    padNumber(days);
            }

            if (hoursElement) {
                hoursElement.textContent =
                    padNumber(hours);
            }

            if (minutesElement) {
                minutesElement.textContent =
                    padNumber(minutes);
            }

            if (secondsElement) {
                secondsElement.textContent =
                    padNumber(seconds);
            }
        }

        function updateCountdown() {
            const remainingTime =
                expiryTimestamp - Date.now();

            /*
            |--------------------------------------------------------------------------
            | Timer complete
            |--------------------------------------------------------------------------
            */

            if (remainingTime <= 0) {
                setTime(0, 0, 0, 0);

                strip.classList.remove(
                    'oneerp-subscription-warning',
                    'oneerp-subscription-urgent'
                );

                strip.classList.add(
                    'oneerp-subscription-expired'
                );

                strip.dataset.expired = '1';

                if (marquee) {
                    marquee.textContent =
                        'Aap ki subscription expire ho chuki hai. ' +
                        'Service continue karne ke liye abhi renew karein.';
                }

                return false;
            }

            const oneDay =
                1000 * 60 * 60 * 24;

            const oneHour =
                1000 * 60 * 60;

            const oneMinute =
                1000 * 60;

            const days = Math.floor(
                remainingTime / oneDay
            );

            const hours = Math.floor(
                (remainingTime % oneDay)
                / oneHour
            );

            const minutes = Math.floor(
                (remainingTime % oneHour)
                / oneMinute
            );

            const seconds = Math.floor(
                (remainingTime % oneMinute)
                / 1000
            );

            setTime(
                days,
                hours,
                minutes,
                seconds
            );

            /*
            |--------------------------------------------------------------------------
            | Last 24 hours mein red urgent theme
            |--------------------------------------------------------------------------
            */

            if (remainingTime <= oneDay) {
                strip.classList.remove(
                    'oneerp-subscription-warning'
                );

                strip.classList.add(
                    'oneerp-subscription-urgent'
                );
            }

            return true;
        }

        updateCountdown();

        const countdownInterval =
            window.setInterval(
                function () {
                    const continueTimer =
                        updateCountdown();

                    if (!continueTimer) {
                        window.clearInterval(
                            countdownInterval
                        );
                    }
                },
                1000
            );
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeSubscriptionCountdown,
            { once: true }
        );
    } else {
        initializeSubscriptionCountdown();
    }
})();


</script>



<script>
(function () {
    'use strict';

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicate subscription polling
    |--------------------------------------------------------------------------
    */

    if (
        window.__oneerpSubscriptionRenewalPollingStarted
        === true
    ) {
        return;
    }

    window.__oneerpSubscriptionRenewalPollingStarted =
        true;

    const statusEndpoint =
        '/onerp/app/public/app/views/renew_subscription.php'
        + '?ajax_subscription_state=1';

    const pollingIntervalMilliseconds =
        5000;

    let requestRunning = false;
    let pollingTimer = null;

    /*
    |--------------------------------------------------------------------------
    | Stop polling
    |--------------------------------------------------------------------------
    */

    function stopSubscriptionPolling() {
        if (pollingTimer !== null) {
            window.clearInterval(
                pollingTimer
            );

            pollingTimer = null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Remove subscription banner and its spacer
    |--------------------------------------------------------------------------
    */

    function removeSubscriptionBanner() {
        const strip =
            document.getElementById(
                'oneerpSubscriptionStrip'
            );

        const spacer =
            document.getElementById(
                'oneerpSubscriptionSpacer'
            );

        if (strip) {
            strip.remove();
        }

        if (spacer) {
            spacer.remove();
        }

        /*
        |--------------------------------------------------------------------------
        | Renewal successful hone ke baad further polling required nahi
        |--------------------------------------------------------------------------
        */

        stopSubscriptionPolling();
    }

    /*
    |--------------------------------------------------------------------------
    | Check latest subscription status
    |--------------------------------------------------------------------------
    */

    async function checkLatestSubscriptionStatus() {
        if (requestRunning) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Banner screen par hai hi nahi to polling required nahi
        |--------------------------------------------------------------------------
        */

        const currentStrip =
            document.getElementById(
                'oneerpSubscriptionStrip'
            );

        if (!currentStrip) {
            stopSubscriptionPolling();
            return;
        }

        requestRunning = true;

        try {
            const requestUrl =
                statusEndpoint
                + '&_='
                + Date.now();

            const response = await fetch(
                requestUrl,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept':
                            'application/json',

                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
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
                    'Invalid subscription status response:',
                    responseText
                );

                return;
            }

            if (
                !response.ok
                || result.success !== true
            ) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Admin ne renewal approve kar di aur new expiry 7 days se zyada hai
            |--------------------------------------------------------------------------
            */

            if (
                result.hide_renew_banner
                === true
            ) {
                removeSubscriptionBanner();
            }

        } catch (error) {
            /*
            |--------------------------------------------------------------------------
            | Temporary network error par existing banner disturb nahi karna
            |--------------------------------------------------------------------------
            */

            console.warn(
                'Subscription status check failed:',
                error
            );

        } finally {
            requestRunning = false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Start polling
    |--------------------------------------------------------------------------
    */

    function startSubscriptionPolling() {
        const strip =
            document.getElementById(
                'oneerpSubscriptionStrip'
            );

        if (!strip) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Page load par immediately check
        |--------------------------------------------------------------------------
        */

        checkLatestSubscriptionStatus();

        /*
        |--------------------------------------------------------------------------
        | Har 5 seconds mein latest subscription check
        |--------------------------------------------------------------------------
        */

        pollingTimer =
            window.setInterval(
                checkLatestSubscriptionStatus,
                pollingIntervalMilliseconds
            );
    }

    if (
        document.readyState === 'loading'
    ) {
        document.addEventListener(
            'DOMContentLoaded',
            startSubscriptionPolling,
            { once: true }
        );
    } else {
        startSubscriptionPolling();
    }

    /*
    |--------------------------------------------------------------------------
    | Browser tab par wapas aane par immediate status check
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'visibilitychange',
        function () {
            if (
                document.visibilityState
                === 'visible'
            ) {
                checkLatestSubscriptionStatus();
            }
        }
    );

    window.addEventListener(
        'focus',
        checkLatestSubscriptionStatus
    );

    window.addEventListener(
        'beforeunload',
        stopSubscriptionPolling
    );

})();
</script>


<script>
(function () {
    'use strict';

    if (window.__oneerpRenewBannerPolling === true) {
        return;
    }

    window.__oneerpRenewBannerPolling = true;

    let requestRunning = false;
    let pollingTimer = null;

    function removeRenewBanner() {
        const strip = document.getElementById(
            'oneerpSubscriptionStrip'
        );

        const spacer = document.getElementById(
            'oneerpSubscriptionSpacer'
        );

        if (strip) {
            strip.remove();
        }

        if (spacer) {
            spacer.remove();
        }

        if (pollingTimer !== null) {
            window.clearInterval(pollingTimer);
            pollingTimer = null;
        }
    }

    async function checkSubscriptionRenewal() {
        const strip = document.getElementById(
            'oneerpSubscriptionStrip'
        );

        if (!strip || requestRunning) {
            return;
        }

        requestRunning = true;

        try {
            const response = await fetch(
                '/onerp/app/public/app/views/renew_subscription.php'
                + '?ajax_subscription_state=1'
                + '&_='
                + Date.now(),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

            const responseText =
                await response.text();

            let result;

            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error(
                    'Subscription endpoint returned invalid JSON:',
                    responseText
                );

                return;
            }

            console.log(
                'Subscription live status:',
                result
            );

            if (
                response.ok
                && result.success === true
                && result.hide_renew_banner === true
            ) {
                removeRenewBanner();
            }
        } catch (error) {
            console.error(
                'Subscription live check failed:',
                error
            );
        } finally {
            requestRunning = false;
        }
    }

    function startRenewalPolling() {
        const strip = document.getElementById(
            'oneerpSubscriptionStrip'
        );

        if (!strip) {
            return;
        }

        checkSubscriptionRenewal();

        pollingTimer = window.setInterval(
            checkSubscriptionRenewal,
            5000
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            startRenewalPolling,
            { once: true }
        );
    } else {
        startRenewalPolling();
    }

    window.addEventListener(
        'focus',
        checkSubscriptionRenewal
    );
})();
</script>
