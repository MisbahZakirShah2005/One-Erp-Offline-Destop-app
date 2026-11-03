<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================= CLIENT THEME HELPER ================= */
$clientThemeHelperPath = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/client_theme_helper.php';
if (is_file($clientThemeHelperPath)) {
    require_once $clientThemeHelperPath;
}

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_client_id = $_SESSION['client_id'] ?? 0;
if($logged_client_id <= 0){
    die("Invalid client");
}

/* ================= FETCH SETTINGS ================= */
$settings = [];
$stmt = $conn->prepare("SELECT * FROM client_settings WHERE client_id=?");
$stmt->bind_param("i",$logged_client_id);
$stmt->execute();
$res = $stmt->get_result();
if($res && $res->num_rows){
    $settings = $res->fetch_assoc();
}
$stmt->close();

/* ================= DEFAULT COLORS ================= */
/*
|--------------------------------------------------------------------------
| Existing black/white fallbacks are preserved.
| Helper available ho to wahi shared HEX validation use hogi.
|--------------------------------------------------------------------------
*/
$normalizeClientThemeColor = static function ($value, string $fallback): string {
    if (function_exists('oneerpNormalizeThemeColor')) {
        return oneerpNormalizeThemeColor($value, $fallback);
    }

    $value = trim((string)$value);

    if ($value !== '' && $value[0] !== '#') {
        $value = '#' . $value;
    }

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value)
        ? strtolower($value)
        : $fallback;
};

$sidebar_bg = $normalizeClientThemeColor(
    $settings['sidebar_color'] ?? '',
    '#000000'
);

$sidebar_text = $normalizeClientThemeColor(
    $settings['sidebar_text_color'] ?? '',
    '#ffffff'
);

$sidebar_hover = $normalizeClientThemeColor(
    $settings['sidebar_hover_color'] ?? '',
    '#333333'
);

$header_bg = $normalizeClientThemeColor(
    $settings['header_color'] ?? '',
    '#000000'
);

$header_text = $normalizeClientThemeColor(
    $settings['header_text_color'] ?? '',
    '#ffffff'
);

$header_hover = $normalizeClientThemeColor(
    $settings['header_hover_color'] ?? '',
    '#333333'
);

/*
|--------------------------------------------------------------------------
| Custom theme mode
|--------------------------------------------------------------------------
| 0 = Existing/default ONEERP sidebar and header theme
| 1 = Client-selected sidebar and header colors
*/
$use_custom_theme = (
    isset($settings['use_custom_theme']) &&
    (int)$settings['use_custom_theme'] === 1
) ? 1 : 0;

/* Shared helper/partials compatibility array */
$clientTheme = [
    'sidebar_bg' => $sidebar_bg,
    'sidebar_text' => $sidebar_text,
    'sidebar_hover' => $sidebar_hover,
    'header_bg' => $header_bg,
    'header_text' => $header_text,
    'header_hover' => $header_hover,
    'use_custom_theme' => $use_custom_theme,
];

/* ================= SAVE SETTINGS ================= */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_settings'])){

$business_name       = trim($_POST['business_name'] ?? '');
$invoice_tagline     = trim($_POST['invoice_tagline'] ?? '');

$invoice_top_text    = trim($_POST['invoice_top_text'] ?? '');
$invoice_top_phone   = trim($_POST['invoice_top_phone'] ?? '');

/*
|--------------------------------------------------------------------------
| Invoice visibility settings
|--------------------------------------------------------------------------
| Hidden inputs send 0 when a switch is off; checked switches send 1.
*/
$show_client_type = (
    isset($_POST['show_client_type']) &&
    (int)$_POST['show_client_type'] === 1
) ? 1 : 0;

$show_tax_numbers = (
    isset($_POST['show_tax_numbers']) &&
    (int)$_POST['show_tax_numbers'] === 1
) ? 1 : 0;

/*
|--------------------------------------------------------------------------
| Client theme mode
|--------------------------------------------------------------------------
| Checkbox off rehne par existing/default ONEERP theme use hogi.
*/
$use_custom_theme = (
    isset($_POST['use_custom_theme']) &&
    (int)$_POST['use_custom_theme'] === 1
) ? 1 : 0;

$address             = trim($_POST['address'] ?? '');
    $email               = trim($_POST['email']);
    $phone               = trim($_POST['phone']);
    $currency            = trim($_POST['currency']);
    $timezone            = trim($_POST['timezone']);
    $tax                 = (float)$_POST['tax_percentage'];
    $discount            = (float)$_POST['discount_percentage'];

    // Existing fallbacks preserved; shared helper validates saved HEX values.
    $sidebar_color = $normalizeClientThemeColor(
        $_POST['sidebar_color'] ?? '',
        '#000000'
    );

    $sidebar_text_color = $normalizeClientThemeColor(
        $_POST['sidebar_text_color'] ?? '',
        '#ffffff'
    );

    $sidebar_hover_color = $normalizeClientThemeColor(
        $_POST['sidebar_hover_color'] ?? '',
        '#333333'
    );

    $header_color = $normalizeClientThemeColor(
        $_POST['header_color'] ?? '',
        '#000000'
    );

    $header_text_color = $normalizeClientThemeColor(
        $_POST['header_text_color'] ?? '',
        '#ffffff'
    );

    $header_hover_color = $normalizeClientThemeColor(
        $_POST['header_hover_color'] ?? '',
        '#333333'
    );

    /* ================= FILE UPLOAD FOLDER ================= */
    $upload_dir = $_SERVER['DOCUMENT_ROOT'].'/SaasPos/uploads/';
    if(!is_dir($upload_dir)){
        mkdir($upload_dir,0755,true);
    }

    /* ================= LOGO UPLOAD ================= */
    $logo_path = $settings['logo'] ?? '';
    if(isset($_FILES['logo']) && $_FILES['logo']['error']===0){
        $allowed = ['jpg','jpeg','png','webp'];
        $ext = strtolower(pathinfo($_FILES['logo']['name'],PATHINFO_EXTENSION));
        if(in_array($ext,$allowed) && $_FILES['logo']['size'] <= 2*1024*1024){
            $logo_name = 'logo_'.$logged_client_id.'.'.$ext;
            $target = $upload_dir.$logo_name;
            if(move_uploaded_file($_FILES['logo']['tmp_name'],$target)){
                $logo_path = '/SaasPos/uploads/'.$logo_name;
            }
        }
    }

    /* ================= NOTIFICATION SOUND UPLOAD ================= */
    $notif_path = $settings['notification_sound'] ?? '';
    if(isset($_FILES['notification_sound']) && $_FILES['notification_sound']['error']===0){
        $allowed_audio = ['mp3','wav','ogg'];
        $ext = strtolower(pathinfo($_FILES['notification_sound']['name'],PATHINFO_EXTENSION));
        if(in_array($ext,$allowed_audio) && $_FILES['notification_sound']['size'] <= 5*1024*1024){
            $notif_name = 'notif_'.$logged_client_id.'.'.$ext;
            $target = $upload_dir.$notif_name;
            if(move_uploaded_file($_FILES['notification_sound']['tmp_name'],$target)){
                $notif_path = '/SaasPos/uploads/'.$notif_name;
            }
        }
    }

    /* ================= UPDATE OR INSERT ================= */
    if(count($settings)>0){
       $stmt = $conn->prepare("UPDATE client_settings SET
    logo=?,
    business_name=?,
    invoice_tagline=?,
    invoice_top_text=?,
    invoice_top_phone=?,
    address=?,
    email=?,
    phone=?,
    currency=?,
    timezone=?,
    tax_percentage=?,
    discount_percentage=?,
    sidebar_color=?,
    sidebar_text_color=?,
    sidebar_hover_color=?,
    header_color=?,
    header_text_color=?,
    header_hover_color=?,
    notification_sound=?
    WHERE client_id=?");

$stmt->bind_param(
    "ssssssssssddsssssssi",
    $logo_path,
    $business_name,
    $invoice_tagline,
    $invoice_top_text,
    $invoice_top_phone,
    $address,
    $email,
    $phone,
    $currency,
    $timezone,
    $tax,
    $discount,
    $sidebar_color,
    $sidebar_text_color,
    $sidebar_hover_color,
    $header_color,
    $header_text_color,
    $header_hover_color,
    $notif_path,
    $logged_client_id
);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success']="Settings updated successfully!";
    } else {
   $stmt = $conn->prepare("INSERT INTO client_settings
(
    client_id,
    logo,
    business_name,
    invoice_tagline,
    invoice_top_text,
    invoice_top_phone,
    address,
    email,
    phone,
    currency,
    timezone,
    tax_percentage,
    discount_percentage,
    sidebar_color,
    sidebar_text_color,
    sidebar_hover_color,
    header_color,
    header_text_color,
    header_hover_color,
    notification_sound
)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$stmt->bind_param(
    "issssssssssddsssssss",
    $logged_client_id,
    $logo_path,
    $business_name,
    $invoice_tagline,
    $invoice_top_text,
    $invoice_top_phone,
    $address,
    $email,
    $phone,
    $currency,
    $timezone,
    $tax,
    $discount,
    $sidebar_color,
    $sidebar_text_color,
    $sidebar_hover_color,
    $header_color,
    $header_text_color,
    $header_hover_color,
    $notif_path
);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success']="Settings saved successfully!";
    }

    /*
    |--------------------------------------------------------------------------
    | Invoice visibility settings
    |--------------------------------------------------------------------------
    | Kept separate so the existing large UPDATE/INSERT bind_param blocks
    | remain unchanged.
    */
    $visibilityStmt = $conn->prepare("
        UPDATE client_settings
        SET
            show_client_type = ?,
            show_tax_numbers = ?
        WHERE client_id = ?
    ");

    if ($visibilityStmt) {
        $visibilityStmt->bind_param(
            "iii",
            $show_client_type,
            $show_tax_numbers,
            $logged_client_id
        );
        $visibilityStmt->execute();
        $visibilityStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Custom theme mode
    |--------------------------------------------------------------------------
    | Separate update rakha gaya hai taake existing large UPDATE/INSERT
    | statements aur unke bind_param workflow ko change na karna pade.
    */
    $themeModeStmt = $conn->prepare("
        UPDATE client_settings
        SET use_custom_theme = ?
        WHERE client_id = ?
    ");

    if ($themeModeStmt) {
        $themeModeStmt->bind_param(
            "ii",
            $use_custom_theme,
            $logged_client_id
        );
        $themeModeStmt->execute();
        $themeModeStmt->close();
    }

    /* ================= UPDATE SESSION ================= */
    $_SESSION['client_settings'] = [
        'sidebar_color'       => $sidebar_color,
        'sidebar_text_color'  => $sidebar_text_color,
        'invoice_tagline' => $invoice_tagline,
        'invoice_top_text'    => $invoice_top_text,
        'invoice_top_phone'   => $invoice_top_phone,
        'show_client_type'    => $show_client_type,
        'show_tax_numbers'    => $show_tax_numbers,
        'use_custom_theme'    => $use_custom_theme,
        'sidebar_hover_color' => $sidebar_hover_color,
        'header_color'        => $header_color,
        'header_text_color'   => $header_text_color,
        'header_hover_color'  => $header_hover_color,
        'logo'                => $logo_path,
        'notification_sound'  => $notif_path,
        'business_name'       => $business_name
    ];

    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Client Settings</title>
<?php include('partials/head.php'); ?>

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


<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{
<?php if ($use_custom_theme === 1): ?>
    /* Client colors only when custom theme is enabled */
    --sidebar-bg: <?= htmlspecialchars($sidebar_bg, ENT_QUOTES, 'UTF-8') ?>;
    --sidebar-text: <?= htmlspecialchars($sidebar_text, ENT_QUOTES, 'UTF-8') ?>;
    --sidebar-hover: <?= htmlspecialchars($sidebar_hover, ENT_QUOTES, 'UTF-8') ?>;
    --header-bg: <?= htmlspecialchars($header_bg, ENT_QUOTES, 'UTF-8') ?>;
    --header-text: <?= htmlspecialchars($header_text, ENT_QUOTES, 'UTF-8') ?>;
    --header-hover: <?= htmlspecialchars($header_hover, ENT_QUOTES, 'UTF-8') ?>;

    --client-sidebar-bg: var(--sidebar-bg);
    --client-sidebar-text: var(--sidebar-text);
    --client-sidebar-hover: var(--sidebar-hover);
    --client-header-bg: var(--header-bg);
    --client-header-text: var(--header-text);
    --client-header-hover: var(--header-hover);
<?php endif; ?>

    /* ONEERP settings-page theme */
    --cs-bg:#060914;
    --cs-panel:#0b1220;
    --cs-panel-2:#111827;
    --cs-border:rgba(148,163,184,.16);
    --cs-text:#f8fafc;
    --cs-muted:#94a3b8;
    --cs-purple:#7c3aed;
    --cs-purple-2:#6d4cff;
}

html,
body.client-settings-page{
    margin:0 !important;
    padding:0 !important;
    width:100% !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 4%, rgba(124,58,237,.28), transparent 28%),
        radial-gradient(circle at 88% 8%, rgba(59,130,246,.12), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 46%,#160f2d 72%,#080c17 100%) !important;
    color:var(--cs-text) !important;
    font-family:Inter,"Segoe UI",Arial,sans-serif !important;
}

/* =====================================================
   FIXED ONEERP LAYOUT
   The sidebar partial expects a .sidebar wrapper.
===================================================== */
body.client-settings-page .container-scroll{
    display:flex !important;
    align-items:stretch !important;
    width:100% !important;
    min-height:100vh !important;
    margin:0 !important;
    padding:0 !important;
    background:transparent !important;
}

body.client-settings-page .container-scroll > .sidebar{
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    width:270px !important;
    min-width:270px !important;
    max-width:270px !important;
    height:100vh !important;
    z-index:1040 !important;
    overflow:hidden !important;
}

body.client-settings-page .container-scroll > .main-panel{
    display:block !important;
    position:relative !important;
    margin:0 0 0 270px !important;
    padding:70px 0 0 !important;
    width:calc(100% - 270px) !important;
    min-width:0 !important;
    min-height:100vh !important;
    background:transparent !important;
}

/* Collapsed sidebar icon rail ke baad content start ho */
@media (min-width:992px){

    body.client-settings-page.sidebar-collapsed
    .container-scroll > .main-panel,

    body.client-settings-page.dashboard-sidebar-collapsed
    .container-scroll > .main-panel{
        margin-left:78px !important;
        width:calc(100% - 78px) !important;
        padding-top:70px !important;
    }

    body.client-settings-page.sidebar-collapsed
    .container-scroll > .sidebar,

    body.client-settings-page.dashboard-sidebar-collapsed
    .container-scroll > .sidebar{
        width:78px !important;
        min-width:78px !important;
        max-width:78px !important;
        transform:translateX(0) !important;
    }
}
body.client-settings-page .content-wrapper{
    position:relative !important;
    width:100% !important;
    min-height:calc(100vh - 70px) !important;
    margin:0 !important;
    padding:26px !important;
    overflow:visible !important;
    background:
        linear-gradient(90deg, rgba(255,255,255,.018) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.018) 1px, transparent 1px),
        radial-gradient(circle at 14% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg,#070b14 0%,#0d1324 52%,#130d28 100%) !important;
    background-size:42px 42px,42px 42px,auto,auto !important;
}

/* Page heading */
.settings-page-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:18px;
    margin:0 0 18px;
}

.settings-page-header h4{
    margin:0 !important;
    color:#fff !important;
    font-size:27px !important;
    line-height:1.1 !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 24px rgba(124,58,237,.30);
}

.settings-page-header p{
    margin:7px 0 0 !important;
    color:var(--cs-muted) !important;
    font-size:13.5px !important;
    font-weight:600 !important;
}

/* Glass settings card */
body.client-settings-page .content-wrapper > form{
    position:relative;
    padding:24px !important;
    border:1px solid rgba(139,92,246,.24) !important;
    border-radius:20px !important;
    background:
        radial-gradient(circle at 12% 0%, rgba(124,58,237,.18), transparent 32%),
        linear-gradient(135deg,rgba(15,23,42,.90),rgba(17,24,39,.68)) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.42),
        0 0 0 1px rgba(124,58,237,.06),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    overflow:hidden;
}

body.client-settings-page .content-wrapper > form::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:linear-gradient(135deg,rgba(255,255,255,.035),transparent 34%,transparent 72%,rgba(124,58,237,.06));
}

/* Form spacing */
body.client-settings-page form .row{
    position:relative;
    z-index:1;
    row-gap:14px !important;
}

body.client-settings-page form .mb-3{
    margin-bottom:16px !important;
}

body.client-settings-page label{
    display:block !important;
    margin:0 0 7px !important;
    color:#dbe4ff !important;
    font-size:13px !important;
    line-height:1.2 !important;
    font-weight:800 !important;
}

/* Inputs */
body.client-settings-page .form-control,
body.client-settings-page input:not([type="color"]),
body.client-settings-page select,
body.client-settings-page textarea{
    min-height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(7,11,22,.82) !important;
    color:#f8fafc !important;
    caret-color:#a78bfa !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.035),
        0 8px 20px rgba(0,0,0,.16) !important;
    outline:none !important;
}

body.client-settings-page .form-control:focus,
body.client-settings-page input:focus,
body.client-settings-page select:focus,
body.client-settings-page textarea:focus{
    border-color:rgba(139,92,246,.72) !important;
    background:rgba(10,15,29,.96) !important;
    color:#fff !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 28px rgba(0,0,0,.24) !important;
}

body.client-settings-page input::placeholder,
body.client-settings-page textarea::placeholder{
    color:#64748b !important;
}

body.client-settings-page input[type="file"]::file-selector-button{
    height:44px;
    margin:-1px 12px -1px -13px;
    padding:0 16px;
    border:0;
    border-right:1px solid rgba(148,163,184,.18);
    background:rgba(124,58,237,.18);
    color:#ddd6fe;
    font-weight:850;
    cursor:pointer;
}

/* Color controls */
body.client-settings-page .form-control-color{
    width:100% !important;
    max-width:none !important;
    height:48px !important;
    min-height:48px !important;
    padding:6px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(7,11,22,.82) !important;
    cursor:pointer;
}

body.client-settings-page .form-control-color::-webkit-color-swatch-wrapper{
    padding:0;
}

body.client-settings-page .form-control-color::-webkit-color-swatch{
    border:0;
    border-radius:8px;
}

/* Existing logo/audio preview */
body.client-settings-page form img{
    display:block;
    max-width:180px;
    height:58px !important;
    object-fit:contain;
    margin-top:10px !important;
    padding:7px;
    border:1px solid rgba(148,163,184,.16);
    border-radius:11px;
    background:rgba(255,255,255,.92);
}

body.client-settings-page form audio{
    width:100% !important;
    max-width:100% !important;
    margin-top:10px !important;
    border-radius:12px;
}

/* Save button */
body.client-settings-page .btn-primary{
    position:relative;
    z-index:1;
    min-width:170px;
    min-height:46px;
    padding:0 22px !important;
    border:1px solid rgba(167,139,250,.38) !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 30px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.15) !important;
    transition:.2s ease !important;
}

body.client-settings-page .btn-primary:hover{
    transform:translateY(-1px);
    border-color:rgba(196,181,253,.62) !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
    color:#fff !important;
    box-shadow:
        0 18px 34px rgba(109,76,255,.38),
        0 0 30px rgba(124,58,237,.20) !important;
}

/* Alert */
body.client-settings-page .alert-success{
    margin-bottom:16px !important;
    border:1px solid rgba(34,197,94,.28) !important;
    border-radius:13px !important;
    background:rgba(34,197,94,.10) !important;
    color:#bbf7d0 !important;
    font-weight:800 !important;
    box-shadow:0 10px 28px rgba(0,0,0,.18);
}

/* Invoice visibility settings */
.invoice-visibility-setting{
    min-height:82px;
    padding:15px 16px;

    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;

    border:1px solid rgba(148,163,184,.16);
    border-radius:13px;

    background:
        linear-gradient(
            135deg,
            rgba(124,58,237,.10),
            rgba(7,11,22,.82)
        );

    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.035),
        0 10px 24px rgba(0,0,0,.18);
}

.invoice-visibility-setting label{
    color:#f8fafc !important;
    font-size:13.5px !important;
    font-weight:850 !important;
}

.invoice-setting-help{
    display:block;
    color:#94a3b8;
    font-size:11.5px;
    line-height:1.35;
    font-weight:600;
}

.invoice-visibility-setting .form-check{
    flex:0 0 auto;
}

.invoice-visibility-setting .form-check-input{
    width:46px !important;
    min-width:46px !important;
    height:24px !important;
    min-height:24px !important;
    margin:0 !important;
    padding:0 !important;

    border:1px solid rgba(148,163,184,.32) !important;
    border-radius:999px !important;
    background-color:#111827 !important;

    cursor:pointer;
    box-shadow:none !important;
}

.invoice-visibility-setting .form-check-input:checked{
    border-color:#8b5cf6 !important;
    background-color:#7c3aed !important;
}

.invoice-visibility-setting .form-check-input:focus{
    box-shadow:0 0 0 3px rgba(124,58,237,.16) !important;
}

/* Responsive */
@media(max-width:991px){
    body.client-settings-page .container-scroll > .sidebar{
        transform:translateX(-100%);
    }

    body.client-settings-page.sidebar-open .container-scroll > .sidebar{
        transform:translateX(0);
    }

    body.client-settings-page .container-scroll > .main-panel{
        margin-left:0 !important;
        width:100% !important;
        padding-top:70px !important;
    }

    body.client-settings-page .content-wrapper{
        padding:16px !important;
    }

    body.client-settings-page .content-wrapper > form{
        padding:18px !important;
        border-radius:16px !important;
    }
}

@media(max-width:576px){
    .settings-page-header h4{
        font-size:23px !important;
    }

    body.client-settings-page .content-wrapper{
        padding:12px !important;
    }

    body.client-settings-page .content-wrapper > form{
        padding:15px !important;
    }

    body.client-settings-page .btn-primary{
        width:100%;
    }
}


/* =========================================================
   CLIENT SETTINGS - FINAL LIGHT MODE
   Topbar aur sidebar dark navy-purple hi rahenge.
========================================================= */

html[data-pos-theme="light"],
html[data-pos-theme="light"] body.client-settings-page{
    color-scheme:light;

    background:
        radial-gradient(
            circle at 12% 5%,
            rgba(124,58,237,.07),
            transparent 28%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 55%,
            #faf7ff 100%
        ) !important;

    color:#0f172a !important;
}

/* Main panel only — sidebar ko touch nahi karega */
html[data-pos-theme="light"]
body.client-settings-page
.container-scroll > .main-panel{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

/* Main settings area */
html[data-pos-theme="light"]
body.client-settings-page
.content-wrapper{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.022) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.022) 1px,
            transparent 1px
        ),
        radial-gradient(
            circle at 14% 0%,
            rgba(124,58,237,.07),
            transparent 34%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 52%,
            #faf7ff 100%
        ) !important;

    background-size:
        42px 42px,
        42px 42px,
        auto,
        auto !important;

    color:#0f172a !important;
}

/* =========================================================
   PAGE HEADING
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page
.settings-page-header h4{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"]
body.client-settings-page
.settings-page-header p{
    color:#64748b !important;
}

/* =========================================================
   MAIN SETTINGS FORM
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page
.content-wrapper > form{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 14px 38px rgba(15,23,42,.08) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"]
body.client-settings-page
.content-wrapper > form::before{
    background:
        linear-gradient(
            135deg,
            rgba(124,58,237,.025),
            transparent 35%,
            transparent 75%,
            rgba(124,58,237,.035)
        ) !important;
}

/* =========================================================
   LABELS AND HELP TEXT
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page label{
    color:#334155 !important;
}

html[data-pos-theme="light"]
body.client-settings-page .content-wrapper small,
html[data-pos-theme="light"]
body.client-settings-page .invoice-setting-help{
    color:#64748b !important;
}

/* =========================================================
   INPUTS / SELECTS / TEXTAREAS
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page .form-control,

html[data-pos-theme="light"]
body.client-settings-page input:not([type="color"]),

html[data-pos-theme="light"]
body.client-settings-page select,

html[data-pos-theme="light"]
body.client-settings-page textarea{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;

    caret-color:#7c3aed !important;

    box-shadow:
        0 4px 14px rgba(15,23,42,.04) !important;
}

html[data-pos-theme="light"]
body.client-settings-page input::placeholder,

html[data-pos-theme="light"]
body.client-settings-page textarea::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"]
body.client-settings-page .form-control:hover,

html[data-pos-theme="light"]
body.client-settings-page input:hover,

html[data-pos-theme="light"]
body.client-settings-page select:hover,

html[data-pos-theme="light"]
body.client-settings-page textarea:hover{
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"]
body.client-settings-page .form-control:focus,

html[data-pos-theme="light"]
body.client-settings-page input:focus,

html[data-pos-theme="light"]
body.client-settings-page select:focus,

html[data-pos-theme="light"]
body.client-settings-page textarea:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13),
        0 7px 18px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"]
body.client-settings-page select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Number field arrows and date controls */
html[data-pos-theme="light"]
body.client-settings-page input{
    color-scheme:light !important;
}

/* =========================================================
   FILE UPLOAD FIELD
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page
input[type="file"]{
    background:#ffffff !important;
    color:#334155 !important;
}

html[data-pos-theme="light"]
body.client-settings-page
input[type="file"]::file-selector-button{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border-right:1px solid #ddd6fe !important;
}

html[data-pos-theme="light"]
body.client-settings-page
input[type="file"]::file-selector-button:hover{
    background:#ede9fe !important;
    color:#4c1d95 !important;
}

/* =========================================================
   COLOR PICKERS
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page .form-control-color{
    background:#ffffff !important;
    border:1px solid #cbd5e1 !important;

    box-shadow:
        0 4px 14px rgba(15,23,42,.04) !important;
}

html[data-pos-theme="light"]
body.client-settings-page
.form-control-color:focus{
    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

/* =========================================================
   LOGO AND AUDIO PREVIEW
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page form img{
    background:#ffffff !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 6px 18px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"]
body.client-settings-page form audio{
    background:#f8fafc !important;
}

/* =========================================================
   INVOICE VISIBILITY CARDS
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page
.invoice-visibility-setting{
    background:
        linear-gradient(
            135deg,
            #faf7ff,
            #f8fafc
        ) !important;

    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 7px 20px rgba(15,23,42,.05) !important;
}

html[data-pos-theme="light"]
body.client-settings-page
.invoice-visibility-setting:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 10px 26px rgba(15,23,42,.07) !important;
}

html[data-pos-theme="light"]
body.client-settings-page
.invoice-visibility-setting label{
    color:#0f172a !important;
}

html[data-pos-theme="light"]
body.client-settings-page
.invoice-setting-help{
    color:#64748b !important;
}

/* Switch off */
html[data-pos-theme="light"]
body.client-settings-page
.invoice-visibility-setting
.form-check-input{
    background-color:#e2e8f0 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

/* Switch on */
html[data-pos-theme="light"]
body.client-settings-page
.invoice-visibility-setting
.form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
}

/* Switch focus */
html[data-pos-theme="light"]
body.client-settings-page
.invoice-visibility-setting
.form-check-input:focus{
    box-shadow:
        0 0 0 3px rgba(124,58,237,.14) !important;
}

/* =========================================================
   SAVE BUTTON
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page .btn-primary{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    border:1px solid #8b5cf6 !important;

    box-shadow:
        0 11px 24px rgba(124,58,237,.22) !important;
}

html[data-pos-theme="light"]
body.client-settings-page .btn-primary:hover{
    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #5b21b6
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 14px 30px rgba(124,58,237,.28) !important;
}

/* =========================================================
   SUCCESS ALERT
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;

    border:1px solid #a7f3d0 !important;

    box-shadow:
        0 8px 22px rgba(15,23,42,.05) !important;
}

/* =========================================================
   MOBILE
========================================================= */

@media(max-width:991px){

    html[data-pos-theme="light"]
    body.client-settings-page
    .container-scroll > .main-panel{
        background:#f3f6fb !important;
    }

    html[data-pos-theme="light"]
    body.client-settings-page
    .content-wrapper{
        background:
            linear-gradient(
                135deg,
                #f8fafc,
                #f3f0ff
            ) !important;
    }

    html[data-pos-theme="light"]
    body.client-settings-page
    .content-wrapper > form{
        background:#ffffff !important;
    }
}

/* =========================================================
   SCROLLBAR
========================================================= */

html[data-pos-theme="light"]
body.client-settings-page
.content-wrapper::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"]
body.client-settings-page
.content-wrapper::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
    border-radius:999px !important;
}

html[data-pos-theme="light"]
body.client-settings-page
.content-wrapper::-webkit-scrollbar-thumb:hover{
    background:#8b5cf6 !important;
}


/* =========================================================
   CLIENT SETTINGS COLLAPSED SIDEBAR ALIGNMENT FIX
========================================================= */

@media (min-width:992px){

    body.client-settings-page.sidebar-collapsed
    .content-wrapper,

    body.client-settings-page.dashboard-sidebar-collapsed
    .content-wrapper{
        width:100% !important;
        margin:0 !important;
        padding-left:26px !important;
        padding-right:26px !important;
    }

    body.client-settings-page.sidebar-collapsed
    .settings-page-header,

    body.client-settings-page.dashboard-sidebar-collapsed
    .settings-page-header{
        width:100% !important;
        margin-left:0 !important;
    }
}

</style>
</head>
<body class="client-settings-page">
<div class="container-scroll">

    <!-- ================= SIDEBAR ================= -->
    <aside class="sidebar" id="dashboardSidebar">
        <?php
        /*
        |--------------------------------------------------------------------------
        | Default theme stays untouched
        |--------------------------------------------------------------------------
        | Theme variables sirf tab pass hongi jab client custom theme enable kare.
        */
        if ($use_custom_theme === 1) {
            $sidebarColorStyle = "background-color: $sidebar_bg; color: $sidebar_text;";
            $sidebarHoverColor = $sidebar_hover;
            $sidebarTextColor = $sidebar_text;
        } else {
            unset(
                $sidebarColorStyle,
                $sidebarHoverColor,
                $sidebarTextColor
            );
        }

        include(__DIR__ . '/partials/sidebar.php');
        ?>
    </aside>

    <!-- ================= MAIN PANEL ================= -->
    <main class="main-panel">
        <?php
        if ($use_custom_theme === 1) {
            $topbarColorStyle = "background-color: $header_bg; color: $header_text;";
            $topbarHoverColor = $header_hover;
            $topbarTextColor = $header_text;
        } else {
            unset(
                $topbarColorStyle,
                $topbarHoverColor,
                $topbarTextColor
            );
        }

        include('partials/topbar.php');
        ?>

        <div class="content-wrapper">
            <div class="settings-page-header">
                <div>
                    <h4>Client Settings</h4>
                    <p>Manage business profile, regional settings, branding colors, logo and notifications.</p>
                </div>
            </div>
            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="save_settings" value="1">

                <div class="row mb-3">
                    <div class="col-md-6"><label>Business Name</label>
                        <input name="business_name" class="form-control" value="<?= htmlspecialchars($settings['business_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6"><label>Email</label>
                        <input name="email" type="email" class="form-control" value="<?= htmlspecialchars($settings['email'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="row mb-3">
    <div class="col-md-12">
        <label>Invoice Tagline</label>

        <input
            type="text"
            name="invoice_tagline"
            class="form-control"
            maxlength="150"
            placeholder="Example: make life glowing"
            value="<?= htmlspecialchars(
                $settings['invoice_tagline'] ?? 'make life glowing',
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <small style="display:block; margin-top:6px; color:#94a3b8;">
            Yeh line invoice par business name ke neeche show hogi.
            Field empty save karne par line hide ho jayegi.
        </small>
    </div>
</div>
<div class="row mb-3">

    <div class="col-md-6">
        <label>Invoice Top Message</label>

        <input
            type="text"
            name="invoice_top_text"
            class="form-control"
            maxlength="120"
            placeholder="Example: Medicine Home Delivery"
            value="<?= htmlspecialchars(
                $settings['invoice_top_text'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <small style="display:block; margin-top:6px; color:#94a3b8;">
            Yeh message invoice ke bilkul top par show hoga.
        </small>
    </div>

    <div class="col-md-6">
        <label>Invoice Top Phone Number</label>

        <input
            type="text"
            name="invoice_top_phone"
            class="form-control"
            maxlength="40"
            placeholder="Example: 0322-3475043"
            value="<?= htmlspecialchars(
                $settings['invoice_top_phone'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <small style="display:block; margin-top:6px; color:#94a3b8;">
            Home-delivery ya contact number invoice top par print hoga.
        </small>
    </div>

</div>

<div class="row mb-3">

    <div class="col-md-6">
        <div class="invoice-visibility-setting">

            <div>
                <label class="mb-1">
                    Show Client Type
                </label>

                <small class="invoice-setting-help">
                    Invoice par Client: Retailer / Walk-in Retailer show karein.
                </small>
            </div>

            <div class="form-check form-switch m-0">

                <input
                    type="hidden"
                    name="show_client_type"
                    value="0"
                >

                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    name="show_client_type"
                    value="1"
                    id="showClientType"
                    <?=
                        !isset($settings['show_client_type']) ||
                        (int)$settings['show_client_type'] === 1
                            ? 'checked'
                            : ''
                    ?>
                >

            </div>

        </div>
    </div>

    <div class="col-md-6">
        <div class="invoice-visibility-setting">

            <div>
                <label class="mb-1">
                    Show NTN / STRN
                </label>

                <small class="invoice-setting-help">
                    Invoice header par NTN aur STRN numbers show karein.
                </small>
            </div>

            <div class="form-check form-switch m-0">

                <input
                    type="hidden"
                    name="show_tax_numbers"
                    value="0"
                >

                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    name="show_tax_numbers"
                    value="1"
                    id="showTaxNumbers"
                    <?=
                        !isset($settings['show_tax_numbers']) ||
                        (int)$settings['show_tax_numbers'] === 1
                            ? 'checked'
                            : ''
                    ?>
                >

            </div>

        </div>
    </div>

</div>

                <div class="row mb-3">
                    <div class="col-md-6"><label>Phone</label>
                        <input name="phone" class="form-control" value="<?= htmlspecialchars($settings['phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-6"><label>Address</label>
                        <input name="address" class="form-control" value="<?= htmlspecialchars($settings['address'] ?? '') ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-3"><label>Currency</label>
                        <input name="currency" class="form-control" value="<?= htmlspecialchars($settings['currency'] ?? 'USD') ?>" required>
                    </div>
                    <div class="col-md-3"><label>Timezone</label>
                        <input name="timezone" class="form-control" value="<?= htmlspecialchars($settings['timezone'] ?? 'UTC') ?>" required>
                    </div>
                    <div class="col-md-3"><label>Tax %</label>
                        <input name="tax_percentage" type="number" step="0.01" class="form-control" value="<?= htmlspecialchars($settings['tax_percentage'] ?? 0) ?>">
                    </div>
                    <div class="col-md-3"><label>Discount %</label>
                        <input name="discount_percentage" type="number" step="0.01" class="form-control" value="<?= htmlspecialchars($settings['discount_percentage'] ?? 0) ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="invoice-visibility-setting">
                            <div>
                                <label class="mb-1">
                                    Use Custom Sidebar and Header Theme
                                </label>

                                <small class="invoice-setting-help">
                                    Switch off rehne par default ONEERP navy sidebar
                                    aur purple header theme use hogi.
                                </small>
                            </div>

                            <div class="form-check form-switch m-0">
                                <input
                                    type="hidden"
                                    name="use_custom_theme"
                                    value="0"
                                >

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    name="use_custom_theme"
                                    value="1"
                                    id="useCustomTheme"
                                    <?= $use_custom_theme === 1
                                        ? 'checked'
                                        : '' ?>
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-2"><label>Sidebar Background</label>
                        <input name="sidebar_color" type="color" class="form-control form-control-color" value="<?= htmlspecialchars($sidebar_bg) ?>">
                    </div>
                    <div class="col-md-2"><label>Sidebar Text</label>
                        <input name="sidebar_text_color" type="color" class="form-control form-control-color" value="<?= htmlspecialchars($sidebar_text) ?>">
                    </div>
                    <div class="col-md-2"><label>Sidebar Hover</label>
                        <input name="sidebar_hover_color" type="color" class="form-control form-control-color" value="<?= htmlspecialchars($sidebar_hover) ?>">
                    </div>
                    <div class="col-md-2"><label>Header Background</label>
                        <input name="header_color" type="color" class="form-control form-control-color" value="<?= htmlspecialchars($header_bg) ?>">
                    </div>
                    <div class="col-md-2"><label>Header Text</label>
                        <input name="header_text_color" type="color" class="form-control form-control-color" value="<?= htmlspecialchars($header_text) ?>">
                    </div>
                    <div class="col-md-2"><label>Header Hover</label>
                        <input name="header_hover_color" type="color" class="form-control form-control-color" value="<?= htmlspecialchars($header_hover) ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6"><label>Logo</label>
                        <input name="logo" type="file" class="form-control">
                        <?php if(!empty($settings['logo'])): ?>
                            <img src="<?= $settings['logo'] ?>" alt="Logo" style="height:50px;margin-top:5px;">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6"><label>Notification Sound</label>
                        <input name="notification_sound" type="file" class="form-control">
                        <?php if(!empty($settings['notification_sound'])): ?>
                            <audio controls style="margin-top:5px;">
                                <source src="<?= $settings['notification_sound'] ?>" type="audio/mpeg">
                            </audio>
                        <?php endif; ?>
                    </div>
                </div>

                <button class="btn btn-primary">Save Settings</button>
            </form>

        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>