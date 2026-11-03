<?php


/*
|--------------------------------------------------------------------------
| ONEERP Live Account Access Polling Configuration
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$oneerpCurrentPage = basename(
    (string)($_SERVER['PHP_SELF'] ?? '')
);

$oneerpAccessPollingExcludedPages = [
    'login.php',
    'login_view.php',
    'logout.php',
    'account_deactivated.php',
    'ajax_check_account_access.php'
];

$oneerpEnableLiveAccessCheck = (
    !empty($_SESSION['user_id'])
    &&
    (int)($_SESSION['role_id'] ?? 0) !== 1
    &&
    !in_array(
        $oneerpCurrentPage,
        $oneerpAccessPollingExcludedPages,
        true
    )
);
/*
|--------------------------------------------------------------------------
| Client theme presentation variables
|--------------------------------------------------------------------------
| Default ONEERP sidebar/topbar theme remains untouched.
| Custom colors are applied only when use_custom_theme = 1.
|--------------------------------------------------------------------------
*/
$clientThemeHelperPath =
    $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/client_theme_helper.php';

if (is_file($clientThemeHelperPath)) {
    require_once $clientThemeHelperPath;
}

$useCustomClientTheme = (
    (int)(
        $clientTheme['use_custom_theme']
        ?? $_SESSION['client_settings']['use_custom_theme']
        ?? $settings['use_custom_theme']
        ?? 0
    ) === 1
);

$customSidebarBg = (string)(
    $clientTheme['sidebar_bg']
    ?? $_SESSION['client_settings']['sidebar_color']
    ?? $settings['sidebar_color']
    ?? '#0b0f19'
);

$customSidebarText = (string)(
    $clientTheme['sidebar_text']
    ?? $_SESSION['client_settings']['sidebar_text_color']
    ?? $settings['sidebar_text_color']
    ?? '#ffffff'
);

$customSidebarHover = (string)(
    $clientTheme['sidebar_hover']
    ?? $_SESSION['client_settings']['sidebar_hover_color']
    ?? $settings['sidebar_hover_color']
    ?? '#312e81'
);

$customHeaderBg = (string)(
    $clientTheme['header_bg']
    ?? $_SESSION['client_settings']['header_color']
    ?? $settings['header_color']
    ?? '#4c1d95'
);

$customHeaderText = (string)(
    $clientTheme['header_text']
    ?? $_SESSION['client_settings']['header_text_color']
    ?? $settings['header_text_color']
    ?? '#ffffff'
);

$customHeaderHover = (string)(
    $clientTheme['header_hover']
    ?? $_SESSION['client_settings']['header_hover_color']
    ?? $settings['header_hover_color']
    ?? '#6d28d9'
);

/*
|--------------------------------------------------------------------------
| Live subscription renewal banner status
|--------------------------------------------------------------------------
*/

$oneerpShowRenewBanner = false;
$oneerpSubscriptionDaysLeft = null;
$oneerpSubscriptionExpiry = null;

$loggedClientId = (int)($_SESSION['client_id'] ?? 0);

if (
    $loggedClientId > 0
    && isset($conn)
    && $conn instanceof mysqli
) {
    try {
        $subscriptionBannerStmt = $conn->prepare(
            "SELECT
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

        $subscriptionBannerStmt->bind_param(
            'i',
            $loggedClientId
        );

        $subscriptionBannerStmt->execute();

        $currentSubscription = $subscriptionBannerStmt
            ->get_result()
            ->fetch_assoc();

        $subscriptionBannerStmt->close();

        if (
            $currentSubscription
            && strtolower(
                trim(
                    (string)$currentSubscription['status']
                )
            ) === 'active'
            && !empty($currentSubscription['actual_expiry'])
        ) {
            $subscriptionTimezone = new DateTimeZone(
                'Asia/Karachi'
            );

            $subscriptionNow = new DateTime(
                'now',
                $subscriptionTimezone
            );

            $subscriptionExpiryDate = new DateTime(
                $currentSubscription['actual_expiry'],
                $subscriptionTimezone
            );

            $secondsRemaining =
                $subscriptionExpiryDate->getTimestamp()
                - $subscriptionNow->getTimestamp();

            $oneerpSubscriptionDaysLeft = (int)ceil(
                $secondsRemaining / 86400
            );

            $oneerpSubscriptionExpiry =
                $subscriptionExpiryDate->format(
                    'Y-m-d H:i:s'
                );

            /*
             * Banner sirf tab show hoga jab:
             * subscription active ho
             * expiry abhi future mein ho
             * aur 7 din ya us se kam remaining hon
             */
            $oneerpShowRenewBanner = (
                $secondsRemaining > 0
                && $oneerpSubscriptionDaysLeft <= 7
            );
        }
    } catch (Throwable $subscriptionBannerError) {
        error_log(
            'Subscription banner check error: '
            . $subscriptionBannerError->getMessage()
        );

        $oneerpShowRenewBanner = false;
    }
}
?>

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>ONE -ERP</title>

    <!-- plugins:css -->
    <link rel="stylesheet" href="assets/vendors/feather/feather.css">
    <link rel="stylesheet" href="assets/vendors/ti-icons/css/themify-icons.css">
    <link rel="stylesheet" href="assets/vendors/css/vendor.bundle.base.css">
    <link rel="stylesheet" href="assets/vendors/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/vendors/mdi/css/materialdesignicons.min.css">
    <!-- endinject -->

    <!-- Plugin css for this page -->
    <!-- <link rel="stylesheet" href="assets/vendors/datatables.net-bs4/dataTables.bootstrap4.css"> -->
    <link rel="stylesheet" href="assets/vendors/datatables.net-bs5/dataTables.bootstrap5.css">
    <link rel="stylesheet" href="assets/vendors/ti-icons/css/themify-icons.css">
    <link rel="stylesheet" type="text/css" href="assets/js/select.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@coreui/icons/css/all.min.css">
    <!-- End plugin css for this page -->

    <!-- inject:css -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- endinject -->

    <link rel="shortcut icon" href="assets/images/favicon.png" />

    <!-- =========================================================
         GLOBAL FIXED SIDEBAR + TOPBAR LAYOUT CSS
         Is CSS ko head.php mein rakho taake sab pages par same layout chale.
         Required page wrapper:
         <div class="pos-layout">
             <aside class="pos-sidebar">sidebar include</aside>
             <main class="pos-main">
                 topbar include
                 <div class="pos-content">page content</div>
             </main>
         </div>
    ========================================================== -->
    <style>
       :root{
            --pos-sidebar-width:270px;
            --pos-topbar-height:70px;
            --pos-page-bg:#eef2f7;
        }

        html,
        body{
            min-height:100%;
        }

        body{
            overflow-x:hidden;
            background:var(--pos-page-bg);
        }

        /*
          Global layout wrapper
        */
        .pos-layout{
            min-height:100vh;
            width:100%;
            background:var(--pos-page-bg);
        }

        /*
          Fixed sidebar parent
          Sidebar partial ke andar .sidebar-wrap rahegi.
        */
        .pos-sidebar{
            position:fixed;
            left:0;
            top:0;
            width:var(--pos-sidebar-width);
            height:100vh;
            z-index:1040;
            overflow:hidden;
            background:#0b0f19;
            transition:transform .25s ease;
        }

        .pos-sidebar .sidebar-wrap{
            width:100%;
            max-width:100%;
            height:100vh;
            min-height:100vh;
        }

        .pos-sidebar .sidebar-inner{
            height:100vh;
            min-height:100vh;
        }

        .pos-sidebar .sidebar-menu{
            overflow-y:auto;
            overflow-x:hidden;
        }

        /*
          Main area sidebar ke baad start hoga
        */
        .pos-main{
            min-height:100vh;
            margin-left:var(--pos-sidebar-width);
            width:calc(100% - var(--pos-sidebar-width));
            background:var(--pos-page-bg);
            transition:margin-left .25s ease,width .25s ease;
        }

        /*
          Fixed topbar ko sidebar ke baad start karna
        */
        .pos-main .navbar.fixed-top,
        .pos-main .my-navbar.fixed-top{
            left:var(--pos-sidebar-width) !important;
            width:calc(100% - var(--pos-sidebar-width)) !important;
            transition:left .25s ease,width .25s ease;
            z-index:1030;
        }

        /*
          Page content topbar ke neeche rahe
        */
        .pos-content{
            padding:94px 24px 24px;
            min-height:100vh;
            background:var(--pos-page-bg);
        }

        /*
          Old wrappers compatibility
          Agar kisi page mein abhi bhi .container-scroll/.sidebar/.main-panel use ho raha ho
          to bhi sidebar fixed ho jaye.
        */
        .container-scroll{
            display:block !important;
            min-height:100vh;
            background:var(--pos-page-bg);
        }

        .container-scroll > .sidebar{
            position:fixed !important;
            left:0 !important;
            top:0 !important;
            width:var(--pos-sidebar-width) !important;
            height:100vh !important;
            z-index:1040 !important;
            overflow:hidden !important;
            background:#0b0f19 !important;
            transition:transform .25s ease !important;
        }

        .container-scroll > .sidebar .sidebar-wrap{
            width:100%;
            max-width:100%;
            height:100vh;
            min-height:100vh;
        }

        .container-scroll > .sidebar .sidebar-inner{
            height:100vh;
            min-height:100vh;
        }

        .container-scroll > .main-panel{
            margin-left:var(--pos-sidebar-width) !important;
            width:calc(100% - var(--pos-sidebar-width)) !important;
            min-height:100vh !important;
            transition:margin-left .25s ease,width .25s ease !important;
        }

        .container-scroll > .main-panel .topbar{
            position:sticky;
            top:0;
            z-index:1030;
        }

        /*
          Sidebar close desktop
        */
        body.sidebar-collapsed .pos-sidebar,
        body.sidebar-collapsed .container-scroll > .sidebar{
            transform:translateX(-100%);
        }

        body.sidebar-collapsed .pos-main,
        body.sidebar-collapsed .container-scroll > .main-panel{
            margin-left:0 !important;
            width:100% !important;
        }

        body.sidebar-collapsed .pos-main .navbar.fixed-top,
        body.sidebar-collapsed .pos-main .my-navbar.fixed-top{
            left:0 !important;
            width:100% !important;
        }

        /*
          Mobile / tablet sidebar overlay
        */
        @media(max-width:991px){
            .pos-sidebar,
            .container-scroll > .sidebar{
                transform:translateX(-100%);
                z-index:1050 !important;
            }

            .pos-main,
            .container-scroll > .main-panel{
                margin-left:0 !important;
                width:100% !important;
            }

            .pos-main .navbar.fixed-top,
            .pos-main .my-navbar.fixed-top{
                left:0 !important;
                width:100% !important;
            }

            body.sidebar-open .pos-sidebar,
            body.sidebar-open .container-scroll > .sidebar{
                transform:translateX(0);
            }

            body.sidebar-open::after{
                content:"";
                position:fixed;
                inset:0;
                background:rgba(0,0,0,.45);
                z-index:1045;
            }

            .pos-content{
                padding:86px 14px 18px;
            }
        }

        /*
          Print mein sidebar/topbar hide
        */
        @media print{
            .pos-sidebar,
            .container-scroll > .sidebar,
            .navbar.fixed-top,
            .my-navbar.fixed-top{
                display:none !important;
            }

            .pos-main,
            .container-scroll > .main-panel{
                margin-left:0 !important;
                width:100% !important;
            }

            .pos-content{
                padding:0 !important;
            }
        }

<?php if ($useCustomClientTheme): ?>
        /* =========================================================
           CLIENT CUSTOM THEME
           This block is emitted only when use_custom_theme = 1.
           Default ONEERP navy sidebar and purple-gradient header stay
           exactly as they are when the option is disabled.
        ========================================================= */

        :root{
            --client-sidebar-bg:
                <?= htmlspecialchars(
                    $customSidebarBg,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>;

            --client-sidebar-text:
                <?= htmlspecialchars(
                    $customSidebarText,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>;

            --client-sidebar-hover:
                <?= htmlspecialchars(
                    $customSidebarHover,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>;

            --client-header-bg:
                <?= htmlspecialchars(
                    $customHeaderBg,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>;

            --client-header-text:
                <?= htmlspecialchars(
                    $customHeaderText,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>;

            --client-header-hover:
                <?= htmlspecialchars(
                    $customHeaderHover,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>;
        }

        /* Sidebar background */
        .pos-sidebar,
        .container-scroll > .sidebar,
        .pos-sidebar .sidebar-wrap,
        .container-scroll > .sidebar .sidebar-wrap,
        .pos-sidebar .sidebar-inner,
        .container-scroll > .sidebar .sidebar-inner,
        .pos-sidebar .sidebar-menu,
        .container-scroll > .sidebar .sidebar-menu{
            background:var(--client-sidebar-bg) !important;
            background-image:none !important;
            color:var(--client-sidebar-text) !important;
        }

        /* Sidebar text and icons */
        .pos-sidebar a,
        .pos-sidebar button,
        .pos-sidebar .nav-link,
        .pos-sidebar .menu-link,
        .pos-sidebar .menu-title,
        .pos-sidebar .menu-icon,
        .pos-sidebar i,
        .container-scroll > .sidebar a,
        .container-scroll > .sidebar button,
        .container-scroll > .sidebar .nav-link,
        .container-scroll > .sidebar .menu-link,
        .container-scroll > .sidebar .menu-title,
        .container-scroll > .sidebar .menu-icon,
        .container-scroll > .sidebar i{
            color:var(--client-sidebar-text) !important;
        }

        .pos-sidebar svg,
        .container-scroll > .sidebar svg{
            color:var(--client-sidebar-text) !important;
        }

        /* Sidebar hover and active items */
        .pos-sidebar a:hover,
        .pos-sidebar button:hover,
        .pos-sidebar .nav-link:hover,
        .pos-sidebar .menu-link:hover,
        .pos-sidebar .nav-link.active,
        .pos-sidebar .menu-link.active,
        .container-scroll > .sidebar a:hover,
        .container-scroll > .sidebar button:hover,
        .container-scroll > .sidebar .nav-link:hover,
        .container-scroll > .sidebar .menu-link:hover,
        .container-scroll > .sidebar .nav-link.active,
        .container-scroll > .sidebar .menu-link.active{
            background:var(--client-sidebar-hover) !important;
            color:var(--client-sidebar-text) !important;
        }

        /* Header background */
        .pos-main .navbar.fixed-top,
        .pos-main .my-navbar.fixed-top,
        .container-scroll > .main-panel .topbar,
        .container-scroll > .main-panel .navbar.fixed-top,
        .container-scroll > .main-panel .my-navbar.fixed-top{
            background:var(--client-header-bg) !important;
            background-image:none !important;
            color:var(--client-header-text) !important;
        }

        /* Header text and icons */
        .pos-main .navbar.fixed-top a,
        .pos-main .navbar.fixed-top button,
        .pos-main .navbar.fixed-top i,
        .pos-main .my-navbar.fixed-top a,
        .pos-main .my-navbar.fixed-top button,
        .pos-main .my-navbar.fixed-top i,
        .container-scroll > .main-panel .topbar a,
        .container-scroll > .main-panel .topbar button,
        .container-scroll > .main-panel .topbar i{
            color:var(--client-header-text) !important;
        }

        .pos-main .navbar.fixed-top svg,
        .pos-main .my-navbar.fixed-top svg,
        .container-scroll > .main-panel .topbar svg{
            color:var(--client-header-text) !important;
        }

        /* Header hover */
        .pos-main .navbar.fixed-top a:hover,
        .pos-main .navbar.fixed-top button:hover,
        .pos-main .my-navbar.fixed-top a:hover,
        .pos-main .my-navbar.fixed-top button:hover,
        .container-scroll > .main-panel .topbar a:hover,
        .container-scroll > .main-panel .topbar button:hover{
            background:var(--client-header-hover) !important;
            color:var(--client-header-text) !important;
        }
<?php endif; ?>

    </style>

    <!-- =========================================================
         GLOBAL SIDEBAR TOGGLER JS
         Topbar ke button:
         data-toggle="minimize" ya data-toggle="offcanvas"
         ya class="pos-sidebar-toggle/sidebar-toggle"
    ========================================================== -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggleSelectors = [
                '.pos-sidebar-toggle',
                '.sidebar-toggle',
                '.navbar-toggler[data-toggle="minimize"]',
                '.navbar-toggler[data-toggle="offcanvas"]',
                '.navbar-toggler[data-bs-toggle="minimize"]',
                '.navbar-toggler[data-bs-toggle="offcanvas"]'
            ];

            let toggles = [];

            toggleSelectors.forEach(function(selector){
                document.querySelectorAll(selector).forEach(function(btn){
                    if (!toggles.includes(btn)) {
                        toggles.push(btn);
                    }
                });
            });

            toggles.forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    e.stopPropagation();

                    if (window.innerWidth <= 991) {
                        document.body.classList.toggle('sidebar-open');
                        document.body.classList.remove('sidebar-collapsed');
                    } else {
                        document.body.classList.toggle('sidebar-collapsed');
                        document.body.classList.remove('sidebar-open');
                    }
                });
            });

            document.addEventListener('click', function(e){
                if (
                    window.innerWidth <= 991 &&
                    document.body.classList.contains('sidebar-open') &&
                    !e.target.closest('.pos-sidebar') &&
                    !e.target.closest('.container-scroll > .sidebar') &&
                    !toggleSelectors.some(function(selector){
                        return e.target.closest(selector);
                    })
                ) {
                    document.body.classList.remove('sidebar-open');
                }
            });

            window.addEventListener('resize', function(){
                if (window.innerWidth > 991) {
                    document.body.classList.remove('sidebar-open');
                }
            });
        });
    </script>
    
    
    <?php if ($oneerpEnableLiveAccessCheck): ?>

<script>
(function () {
    'use strict';

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicate polling
    |--------------------------------------------------------------------------
    */

    if (window.__oneerpAccountAccessPollingStarted === true) {
        return;
    }

    window.__oneerpAccountAccessPollingStarted = true;

    const accessCheckEndpoint =
        '/onerp/app/public/app/views/ajax_check_account_access.php';

    const accountLockedPage =
        '/onerp/app/public/app/views/account_deactivated.php';

    const loginPage =
        '/onerp/app/public/app/views/login_view.php';

    let requestRunning = false;
    let redirectStarted = false;
    let pollingTimer = null;

    /*
    |--------------------------------------------------------------------------
    | Stop polling
    |--------------------------------------------------------------------------
    */

    function stopOneerpAccessPolling() {
        if (pollingTimer !== null) {
            clearInterval(pollingTimer);
            pollingTimer = null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Redirect safely
    |--------------------------------------------------------------------------
    */

    function redirectOneerpAccess(url) {
        if (redirectStarted) {
            return;
        }

        redirectStarted = true;
        requestRunning = false;

        stopOneerpAccessPolling();

        window.location.replace(url);
    }

    /*
    |--------------------------------------------------------------------------
    | Check current user and client status
    |--------------------------------------------------------------------------
    */

    async function checkOneerpAccountAccess() {
        if (
            requestRunning ||
            redirectStarted
        ) {
            return;
        }

        requestRunning = true;

        try {
            const requestUrl =
                accessCheckEndpoint
                + '?_='
                + Date.now();

            const response = await fetch(requestUrl, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const rawResponse = await response.text();

            let result;

            try {
                result = JSON.parse(rawResponse);
            } catch (jsonError) {
                /*
                |--------------------------------------------------------------------------
                | Invalid/non-JSON response par current screen freeze nahi karni
                |--------------------------------------------------------------------------
                */

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Account is still active
            |--------------------------------------------------------------------------
            */

            if (result.active === true) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Login session expired
            |--------------------------------------------------------------------------
            */

            if (
                result.active === false &&
                result.reason === 'session_expired'
            ) {
                redirectOneerpAccess(
                    result.redirect || loginPage
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | User or client inactive/suspended/deleted
            |--------------------------------------------------------------------------
            */

            if (result.active === false) {
                redirectOneerpAccess(
                    result.redirect || accountLockedPage
                );
            }

        } catch (error) {
            /*
            |--------------------------------------------------------------------------
            | Temporary network/server issue par account lock assume nahi karna
            |--------------------------------------------------------------------------
            */

            return;

        } finally {
            requestRunning = false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Start live polling after page is ready
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            /*
            |--------------------------------------------------------------------------
            | Page open hote hi first status check
            |--------------------------------------------------------------------------
            */

            checkOneerpAccountAccess();

            /*
            |--------------------------------------------------------------------------
            | Har 2 seconds baad account status check
            |--------------------------------------------------------------------------
            */

            pollingTimer = window.setInterval(
                checkOneerpAccountAccess,
                2000
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | User browser tab par wapas aaye to immediately check
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'visibilitychange',
        function () {
            if (
                document.visibilityState === 'visible' &&
                !redirectStarted
            ) {
                checkOneerpAccountAccess();
            }
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Browser window focus par immediate check
    |--------------------------------------------------------------------------
    */

    window.addEventListener(
        'focus',
        function () {
            if (!redirectStarted) {
                checkOneerpAccountAccess();
            }
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Page leave hone par interval stop
    |--------------------------------------------------------------------------
    */

    window.addEventListener(
        'beforeunload',
        stopOneerpAccessPolling
    );

})();
</script>

<?php endif; ?>
</head>
