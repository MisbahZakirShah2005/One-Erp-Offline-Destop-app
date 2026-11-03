<?php
$base_url = '';

if (strpos($_SERVER['SCRIPT_NAME'], '/pos/') === 0) {
    $base_url = '/pos';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo "User not logged in!";
    exit;
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================= DB CONNECTION FIX ================= */
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check config/db.php and make sure it creates mysqli variable named \$conn.");
}

$conn->set_charset('utf8mb4');

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);

/* ================= CLIENT SIDEBAR THEME ================= */
/*
|--------------------------------------------------------------------------
| Default ONEERP sidebar remains unchanged.
| Client-selected colors apply only when use_custom_theme = 1.
|--------------------------------------------------------------------------
*/
$default_sidebar_bg    = '#0b0f19';
$default_sidebar_text  = '#f5f7fb';
$default_sidebar_hover = '#d9a323';

$sidebarThemeSettings = (
    isset($_SESSION['client_settings']) &&
    is_array($_SESSION['client_settings'])
)
    ? $_SESSION['client_settings']
    : [];

/*
|--------------------------------------------------------------------------
| Safe database fallback
|--------------------------------------------------------------------------
| Normally head.php/helper or login session provides these values.
| If the session does not contain the custom-theme flag yet, load only the
| sidebar theme fields for the current client. No permission/menu logic is
| changed by this lookup.
|--------------------------------------------------------------------------
*/
if (
    $client_id > 0 &&
    !array_key_exists('use_custom_theme', $sidebarThemeSettings) &&
    !isset($clientTheme['use_custom_theme'])
) {
    try {
        $themeStmt = $conn->prepare("
            SELECT
                use_custom_theme,
                sidebar_color,
                sidebar_text_color,
                sidebar_hover_color
            FROM client_settings
            WHERE client_id = ?
            LIMIT 1
        ");

        if ($themeStmt) {
            $themeStmt->bind_param('i', $client_id);
            $themeStmt->execute();
            $themeResult = $themeStmt->get_result();
            $themeRow = $themeResult
                ? $themeResult->fetch_assoc()
                : null;
            $themeStmt->close();

            if (is_array($themeRow)) {
                $sidebarThemeSettings = array_merge(
                    $sidebarThemeSettings,
                    $themeRow
                );
            }
        }
    } catch (Throwable $ignore) {
        /* Keep the original/default sidebar theme if theme data is unavailable. */
    }
}

$use_custom_theme = (
    (int)(
        $clientTheme['use_custom_theme']
        ?? $sidebarThemeSettings['use_custom_theme']
        ?? 0
    ) === 1
);

$normalizeSidebarThemeColor = static function (
    $value,
    string $fallback
): string {
    $value = trim((string)$value);

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value)
        ? strtolower($value)
        : $fallback;
};

$sidebar_bg = $normalizeSidebarThemeColor(
    $clientTheme['sidebar_bg']
        ?? $sidebarThemeSettings['sidebar_color']
        ?? '',
    $default_sidebar_bg
);

$sidebar_text = $normalizeSidebarThemeColor(
    $clientTheme['sidebar_text']
        ?? $sidebarThemeSettings['sidebar_text_color']
        ?? '',
    $default_sidebar_text
);

$sidebar_hover = $normalizeSidebarThemeColor(
    $clientTheme['sidebar_hover']
        ?? $sidebarThemeSettings['sidebar_hover_color']
        ?? '',
    $default_sidebar_hover
);

/* ================= SUPER ADMIN ================= */
$is_superadmin = ((int)$role_id === 1);

/* ================= PLAN FEATURES ================= */
$features = [];
$client_industry = '';

if (!$is_superadmin && $client_id > 0) {
    /* ===== Active Plan Industry ===== */
    $industryStmt = $conn->prepare("
        SELECT p.industry
        FROM subscriptions s
        INNER JOIN plans p ON p.id = s.plan_id
        WHERE s.client_id = ?
          AND s.status = 'active'
          AND (s.end_date IS NULL OR s.end_date >= CURDATE())
        ORDER BY s.id DESC
        LIMIT 1
    ");

    if ($industryStmt) {
        $industryStmt->bind_param("i", $client_id);
        $industryStmt->execute();
        $industryRes = $industryStmt->get_result();

        if ($industryRow = $industryRes->fetch_assoc()) {
            $client_industry = strtolower(trim((string)($industryRow['industry'] ?? '')));
            $client_industry = str_replace(['-', ' '], '_', $client_industry);

            if (in_array($client_industry, ['resturent', 'resturant'], true)) {
                $client_industry = 'restaurant';
            }
        }

        $industryStmt->close();
    }

    /* ===== Active Plan Features ===== */
    $stmt = $conn->prepare("
        SELECT pf.feature_key
        FROM subscriptions s
        JOIN plan_features pf ON s.plan_id = pf.plan_id
        WHERE s.client_id = ?
          AND s.status = 'active'
          AND (s.end_date IS NULL OR s.end_date >= CURDATE())
          AND pf.enabled = 1
    ");

    if ($stmt) {
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $key = trim((string)$row['feature_key']);
            if ($key !== '') {
                $features[$key] = true;
            }
        }

        $stmt->close();
    }

    /* ===== Client Overrides ===== */
    $stmt2 = $conn->prepare("
        SELECT feature_key, enabled
        FROM client_feature_overrides
        WHERE client_id = ?
    ");

    if ($stmt2) {
        $stmt2->bind_param("i", $client_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();

        while ($row = $res2->fetch_assoc()) {
            $key = trim((string)$row['feature_key']);

            if ($key === '') {
                continue;
            }

            if ((int)$row['enabled'] === 1) {
                $features[$key] = true;
            } else {
                unset($features[$key]);
            }
        }

        $stmt2->close();
    }
}

/* ================= STAFF PERMISSIONS ================= */
$user_permissions = [];

if (!$is_superadmin && $user_id > 0) {
    $stmt = $conn->prepare("
        SELECT feature_key
        FROM users_permissions
        WHERE user_id = ?
    ");

    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $key = trim((string)$row['feature_key']);
            if ($key !== '') {
                $user_permissions[$key] = true;
            }
        }

        $stmt->close();
    }
}

/* ================= USER TYPE ================= */
$is_staff = (!$is_superadmin && count($user_permissions) > 0);

/* ================= PERMISSION HELPERS =================
   STRICT RULE:
   - Client sidebar item tabhi show hoga jab plan_features / client_feature_overrides
     mein uska feature_key enabled ho.
   - Staff user ke liye plan feature + users_permissions dono required hain.
   - Super Admin ko apna admin sidebar complete show hoga.
========================================================================= */

/*
   Agar aapke plans.php mein feature_key names thore different hain,
   yahan alias add kar do. Example: "products" ke liye "inventory_products".
   can_show('products') in tamam aliases ko check karega.
*/
$feature_aliases = [
    'dashboard' => ['dashboard', 'client_dashboard'],

    'pos_sales' => ['pos_sales', 'pos', 'sales'],
    'invoices' => ['invoices', 'invoice_management', 'orders'],
    'sale_reports' => ['sale_reports', 'daily'],
    'cashier_shift' => ['cashier_shift', 'cashier'],
    'returns' => ['returns', 'refunds'],
    'quotations' => ['quotations', 'quotes'],
    'discounts' => ['discounts', 'coupons'],

    'products' => ['products', 'items', 'inventory_products'],
    'services' => ['services'],
    'inventory' => ['inventory'],
    'inventory_categories' => ['inventory_categories', 'categories', 'product_categories', 'item_categories'],
    'inventory_adjustments' => ['inventory_adjustments', 'inventory_adj'],
    'item_variants' => ['item_variants', 'item_batches', 'variants'],
    'barcode' => ['barcode', 'qr', 'barcode_qr'],
    'low_stock' => ['low_stock'],
    'products_report' => ['products_report', 'product_reports'],
    'best_selling_products' => ['best_selling_products', 'best_selling'],

    'customers' => ['customers', 'customer_list'],
    'memberships' => ['memberships', 'customer_memberships'],
    'membership' => ['membership', 'add_membership'],
    'customer_ledger' => ['customer_ledger'],

    'suppliers' => ['suppliers'],
    'purchase_orders' => ['purchase_orders', 'purchase_order'],
    'supplier_ledger' => ['supplier_ledger'],

    'users' => ['users', 'staff'],
    'attendance' => ['attendance', 'staff_attendance'],
    'appointments' => ['appointments', 'appointment'],
    'commissions' => ['commissions', 'commission_rates', 'staff_commissions'],

    'expenses' => ['expenses'],
    'payments' => ['payments'],
    'taxes' => ['taxes'],
    'tax_summary' => ['tax_summary'],
    'profit_loss' => ['profit_loss'],

    'wholesale_module' => ['wholesale_module', 'wholesale'],
    'fbr_integration' => ['fbr_integration', 'fbr'],
    'multi_branch' => ['multi_branch', 'branches'],
    'mobile_access' => ['mobile_access'],
    'integrations' => ['integrations'],
    /*
 * plans.php mein checkbox ka feature_key "settings" hai,
 * jabke actual page client_settings.php hai.
 */
'client_settings' => [
    'client_settings',
    'settings',
    'business_settings',
    'company_settings'
],

    /* ================= RESTAURANT MANAGEMENT MODULE ================= */
    'restaurant' => ['restaurant', 'restaurant_module'],
    'restaurant_bookings' => ['restaurant_bookings', 'reservations', 'table_reservations', 'bookings', 'restaurant_reservations'],
    'restaurant_tables' => ['restaurant_tables', 'tables'],
    'restaurant_pos' => ['restaurant_pos', 'restaurant_table_pos'],
    'restaurant_orders' => ['restaurant_orders', 'running_table_orders'],
    'restaurant_kot' => ['restaurant_kot', 'kot', 'kitchen_order_ticket'],
    'restaurant_kitchen' => ['restaurant_kitchen', 'kitchen_display'],
    'restaurant_waiters' => ['restaurant_waiters', 'waiters'],
    'restaurant_bill_print' => ['restaurant_bill_print', 'restaurant_bill'],
    'restaurant_payments' => ['restaurant_payments'],
    'restaurant_table_transfer' => ['restaurant_table_transfer', 'table_transfer'],
    'restaurant_table_merge' => ['restaurant_table_merge', 'table_merge'],
    'restaurant_split_bill' => ['restaurant_split_bill', 'split_bill'],
    'restaurant_reports' => ['restaurant_reports'],
    'restaurant_settings' => ['restaurant_settings'],

    /* ================= PRODUCTION / MANUFACTURING MODULE ================= */
    'production' => ['production', 'manufacturing', 'manufacture'],
    'production_formulas' => ['production_formulas', 'formulas', 'bom'],
    'production_work_orders' => ['production_work_orders', 'work_orders', 'production_orders'],
    'production_batches' => ['production_batches', 'batches', 'job_tracking'],
    'production_wastage' => ['production_wastage', 'wastage', 'scrap'],
    'production_costing' => ['production_costing', 'costing'],
    'production_reports' => ['production_reports'],

    'water_delivery' => ['water_delivery', 'water_plant'],
    'empty_bottles' => ['empty_bottles'],
    'wp_delivery_routes' => ['wp_delivery_routes'],
    'wp_dispatch' => ['wp_dispatch'],
    'wp_delivery_assign' => ['wp_delivery_assign'],
    'wp_distribution_operation' => ['wp_distribution_operation'],

    'distribution' => ['distribution'],

    'dist_routes' => ['dist_routes'],
    'dist_drivers' => ['dist_drivers', 'dist_riders'],
    'dist_dispatch' => ['dist_dispatch'],
    'dist_delivery_assign' => ['dist_delivery_assign'],
    'dist_distribution_operation' => ['dist_distribution_operation'],
    'dist_orders' => ['dist_orders'],
    'dist_delivery_update' => ['dist_delivery_update'],
    'dist_collections' => ['dist_collections'],
    'dist_reports' => ['dist_reports'],

    /* ================= OPD MANAGEMENT MODULE ================= */
    'opd' => ['opd', 'opd_module', 'opd_management'],
    'opd_dashboard' => ['opd_dashboard'],
    'opd_patients' => ['opd_patients'],
    'opd_departments' => ['opd_departments'],
    'opd_doctors' => ['opd_doctors'],
    'opd_reception' => ['opd_reception'],
    'opd_tokens' => ['opd_tokens'],
    'opd_billing' => ['opd_billing', 'opd_payments'],
    'opd_vitals' => ['opd_vitals', 'opd_triage'],
    'opd_queue' => ['opd_queue', 'opd_doctor_queue'],
    'opd_reports' => ['opd_reports'],
    'opd_settings' => ['opd_settings'],

    'real_estate' => ['real_estate', 'state_agency'],
    're_dashboard' => ['re_dashboard'],
    're_agency_leads' => ['re_agency_leads', 'agency_leads'],
    're_followups' => ['re_followups', 'followups'],
    're_site_visits' => ['re_site_visits', 'site_visits'],
    're_properties' => ['re_properties', 'properties'],
    're_deals' => ['re_deals', 'deals'],
    're_agent_assignment' => ['re_agent_assignment'],
    're_agent_commissions' => ['re_agent_commissions'],
    're_reports' => ['re_reports'],
];

function feature_keys_for($feature_key) {
    global $feature_aliases;

    $feature_key = trim((string)$feature_key);
    if ($feature_key === '') {
        return [];
    }

    $keys = [$feature_key];

    if (isset($feature_aliases[$feature_key]) && is_array($feature_aliases[$feature_key])) {
        $keys = array_merge($keys, $feature_aliases[$feature_key]);
    }

    return array_values(array_unique(array_filter(array_map('trim', $keys))));
}

function feature_enabled_for_client($feature_key) {
    global $features;

    foreach (feature_keys_for($feature_key) as $key) {
        if (isset($features[$key])) {
            return true;
        }
    }

    return false;
}

function feature_allowed_for_staff($feature_key) {
    global $user_permissions;

    foreach (feature_keys_for($feature_key) as $key) {
        if (isset($user_permissions[$key])) {
            return true;
        }
    }

    return false;
}

function is_production_feature_key($feature_key) {
    $feature_key = trim((string)$feature_key);

    return in_array($feature_key, [
        'production',
        'manufacturing',
        'manufacture',
        'production_formulas',
        'formulas',
        'bom',
        'production_work_orders',
        'work_orders',
        'production_orders',
        'production_batches',
        'batches',
        'job_tracking',
        'production_wastage',
        'wastage',
        'scrap',
        'production_costing',
        'costing',
        'production_reports'
    ], true);
}

function is_restaurant_industry_client() {
    global $client_industry;

    return in_array($client_industry, ['restaurant'], true);
}

function can_show($feature_key) {
    global $is_superadmin, $is_staff;

    if ($is_superadmin) {
        return true;
    }

    // Hard safety: restaurant industry clients must never see production features.
    // This also protects the sidebar when old/stale production keys still exist in plan_features or client_feature_overrides.
    if (is_restaurant_industry_client() && is_production_feature_key($feature_key)) {
        return false;
    }

    if (!feature_enabled_for_client($feature_key)) {
        return false;
    }

    if ($is_staff && !feature_allowed_for_staff($feature_key)) {
        return false;
    }

    return true;
}

function can_show_any($feature_keys = []) {
    foreach ($feature_keys as $feature_key) {
        if (can_show($feature_key)) {
            return true;
        }
    }

    return false;
}

function can_show_exact($feature_key) {
    global $is_superadmin, $is_staff, $features, $user_permissions;

    $feature_key = trim((string)$feature_key);

    if ($feature_key === '') {
        return false;
    }

    if ($is_superadmin) {
        return true;
    }

    // Hard safety: restaurant industry clients must never see production features.
    if (is_restaurant_industry_client() && is_production_feature_key($feature_key)) {
        return false;
    }

    // Exact feature key only. No aliases.
    if (!isset($features[$feature_key])) {
        return false;
    }

    // Staff ke liye exact staff permission bhi required hai.
    if ($is_staff && !isset($user_permissions[$feature_key])) {
        return false;
    }

    return true;
}

function can_show_exact_any($feature_keys = []) {
    foreach ($feature_keys as $feature_key) {
        if (can_show_exact($feature_key)) {
            return true;
        }
    }

    return false;
}

function is_services_industry_client() {
    global $client_industry;

    return in_array($client_industry, ['services', 'service'], true);
}


/* ================= ACTIVE PAGE HELPERS ================= */
$current_page = basename($_SERVER['PHP_SELF']);

function is_page($pages = []) {
    global $current_page;
    return in_array($current_page, $pages, true);
}

function menu_open($pages = []) {
    return is_page($pages) ? 'show' : '';
}

function link_active($pages = []) {
    return is_page($pages) ? 'active' : '';
}

function parent_active($pages = []) {
    return is_page($pages) ? 'active-parent' : '';
}

/* ================= MODULE PAGE ARRAYS ================= */
$productionPages = [

    'production_formulas.php',
    'production_formula_save.php',
    'production_work_orders.php',
    'production_work_order_create.php',
    'production_work_order_view.php',
    'production_work_order_complete.php',
    'production_batches.php',
    'production_wastage.php',
    'production_costing.php',

];

$restaurantPages = [

    'restaurant_tables.php',
    'restaurant_pos.php',
    'restaurant_orders.php',
    'restaurant_kitchen.php',
    'restaurant_waiters.php',
    'restaurant_reports.php',
    'restaurant_settings.php',
    'restaurant_bill_print.php',
    'restaurant_payment.php',
    'restaurant_bookings.php',
    'restaurant_merge_table.php',
    'restaurant_split_bill.php'
];

$waterPlantPages = [
    'empty_bottles.php',
    'routes_management.php',
    'dispatch.php',
    'distribution_operation.php',
    'delivery_assign.php'
];

$distributionPages = [
    'dashboard.php',
    'routes_management.php',
    'distribution-routes.php',
    'rider_management.php',
    'customer_routes.php',
    'customer_routes..php',
    'distribution-customer-routes.php',
    'distribution_orders.php',
    'distribution-orders.php',
    'dispatch.php',
    'distribution-dispatch.php',
    'delivery_update.php',
    'distribution-delivery-update.php',
    'distribution_collections.php',
    'distribution-collections.php',
    'reports.php',
    'distribution-reports.php'
];

$opdPages = [
    'opd_dashboard.php',
    'opd_reception.php',
    'opd_patients.php',
    'opd_patient_form.php',
    'opd_departments.php',
    'opd_doctors.php',
    'opd_vitals.php',
    'opd_queue.php',
    'opd_payments.php',
    'opd_token_print.php',
    'opd_reports.php',
    'opd_settings.php'
];

$realEstatePages = [
    'real-estate-dashboard.php',
    'agency-lead.php',
    'agency-lead.html',
    'follow-ups.php',
    'site-visit.php',
    'site-visit.html',
    'properties.php',
    'properties.html',
    'deals.php',
    'deals.html',
    'agent-assignment.php',
    'agent_assignment.php',
    'agent-assignment.html',
    'agent_assignment.html',
    'agent-commission.php',
    'agent-commissions.php',
    'agent_commissions.php',
    'agent-commission.html',
    'agent-commissions.html',
    'report.php',
    'report.html'
];

/* ================= MODULE VISIBILITY - STRICT PLAN BASED =================
   Important:
   - Water Plant only wp_* / water keys se show hoga.
   - Distribution only dist_* / distribution keys se show hoga.
   - State Agency only real_estate / re_* keys se show hoga.
   - dashboard/reports jaisi common keys se module open nahi hoga.
========================================================================= */
$showProductionModule = !is_restaurant_industry_client()
    && can_show_exact('production')
    && can_show_exact_any([
        'production_formulas',
        'production_work_orders',
        'production_batches',
        'production_wastage',
        'production_costing',
        'production_reports'
    ]);

$showRestaurantModule = can_show_any([
    'restaurant',
    'restaurant_bookings',
    'restaurant_tables',
    'restaurant_pos',
    'restaurant_orders',
    'restaurant_kot',
    'restaurant_kitchen',
    'restaurant_waiters',
    'restaurant_bill_print',
    'restaurant_payments',
    'restaurant_table_transfer',
    'restaurant_table_merge',
    'restaurant_split_bill',
    'restaurant_reports',
    'restaurant_settings'
]);

$showWaterPlantModule = can_show_any([
    'water_delivery',
    'wp_delivery_routes',
    'wp_dispatch',
    'wp_delivery_assign',
    'wp_distribution_operation',
    'empty_bottles'
]);

$showDistributionModule = can_show_any([
    'distribution',
    'dist_dashboard',
    'dist_routes',
    'dist_drivers',
    'dist_dispatch',
    'dist_delivery_assign',
    'dist_distribution_operation',
    'dist_orders',
    'dist_delivery_update',
    'dist_collections',
    'dist_reports'
]);

$showOpdModule = can_show_any([
    'opd',
    'opd_dashboard',
    'opd_patients',
    'opd_departments',
    'opd_doctors',
    'opd_reception',
    'opd_tokens',
    'opd_billing',
    'opd_vitals',
    'opd_queue',
    'opd_reports',
    'opd_settings'
]);

$showRealEstateModule = can_show_any([
    'real_estate',
    're_dashboard',
    're_agency_leads',
    're_followups',
    're_site_visits',
    're_properties',
    're_deals',
    're_agent_assignment',
    're_agent_commissions',
    're_reports'
]);
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
:root{
    --sidebar-bg: #0b1220;
    --sidebar-text: #e5e7eb;
    --sidebar-hover: #6d4cff;

    --pos-sidebar-width:270px;
    --pos-topbar-height:70px;
    --pos-sidebar-collapsed-width:78px;

    --oms-bg:#070b14;
    --oms-sidebar:#0b1220;
    --oms-sidebar-2:#0f172a;
    --oms-border:rgba(148,163,184,.14);
    --oms-text:#e5e7eb;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-purple-soft:rgba(109,76,255,.14);
}

*{ box-sizing:border-box; }

body{ overflow-x:hidden; }

.sidebar-wrap{
    width:100%;
    max-width:270px;
    height:100vh;
    min-height:100vh;
    color:var(--sidebar-text);
    position:relative;
    overflow:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.22), transparent 28%),
        radial-gradient(circle at 100% 34%, rgba(59,130,246,.08), transparent 26%),
        linear-gradient(180deg, #0b1220 0%, #070b14 100%) !important;
    border-right:1px solid rgba(148,163,184,.14) !important;
    box-shadow:10px 0 35px rgba(0,0,0,.35) !important;
}

.sidebar-wrap::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.035), transparent 25%, transparent 72%, rgba(109,76,255,.08));
    pointer-events:none;
}

.sidebar-inner{
    position:relative;
    z-index:2;
    display:flex;
    flex-direction:column;
    height:100vh;
    min-height:100vh;
    padding:14px 12px 14px;
}

/* ================= OMS STYLE FULL VISIBLE LOGO ================= */
.sidebar-brand{
    min-height:112px;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:16px 8px 18px;
    margin-bottom:10px;
    border-bottom:1px solid rgba(148,163,184,.12);
    text-decoration:none;
    color:#fff;
}

.sidebar-brand:hover{
    color:#fff;
}

.sidebar-brand-logo{
    width:100%;
    max-width:225px;
    height:78px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:transparent !important;
    border-radius:0;
    box-shadow:none !important;
    overflow:visible;
    flex:0 0 auto;
}

.sidebar-brand-logo img{
    width:100%;
    height:100%;
    max-width:215px;
    max-height:72px;
    object-fit:contain;
    object-position:center;
    display:block;
    padding:0;
    transform:scale(1.08) !important;
    transform-origin:center;
    filter:
        drop-shadow(0 0 10px rgba(124,58,237,.55))
        drop-shadow(0 0 18px rgba(109,76,255,.35));
}

.sidebar-menu{
    flex:1;
    overflow-y:auto;
    overflow-x:hidden;
    padding-right:2px;
}

.sidebar-menu::-webkit-scrollbar{ width:5px; }
.sidebar-menu::-webkit-scrollbar-track{ background:transparent; }
.sidebar-menu::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.22);
    border-radius:999px;
}
.sidebar-menu::-webkit-scrollbar-thumb:hover{
    background:rgba(148,163,184,.34);
}

.menu-section{ margin-bottom:7px; }

.menu-link{
    width:100%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    text-decoration:none;
    color:#cbd5e1 !important;
    padding:10.5px 10px;
    border-radius:10px !important;
    transition:.22s ease;
    position:relative;
    border:1px solid transparent;
}

.menu-link:hover{
    background:rgba(148,163,184,.04) !important;
    border-color:rgba(148,163,184,.12) !important;
    color:#fff !important;
}

.menu-link.active-parent,
.menu-link.top-active{
    background:linear-gradient(90deg, rgba(109,76,255,.95), rgba(124,58,237,.88)) !important;
    border-color:rgba(139,92,246,.40) !important;
    color:#fff !important;
    box-shadow:0 12px 30px rgba(109,76,255,.22) !important;
}

.menu-main{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
}

.menu-icon{
    width:18px;
    min-width:18px;
    font-size:15px;
    color:inherit !important;
    text-align:center;
}

.menu-title{
    font-size:14.5px;
    font-weight:600;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.menu-arrow{
    font-size:12px;
    color:rgba(203,213,225,.62);
    transition:transform .25s ease, color .25s ease;
}

.menu-link[aria-expanded="true"] .menu-arrow{
    transform:rotate(180deg);
    color:#fff;
}

.submenu{
    margin:7px 0 10px 22px;
    padding:6px 0 2px 14px;
    border-left:1px solid rgba(148,163,184,.14) !important;
}

.submenu .submenu-link{
    display:flex;
    align-items:center;
    gap:10px;
    text-decoration:none;
    color:#94a3b8 !important;
    font-size:13.5px;
    font-weight:600;
    padding:8.5px 8px;
    border-radius:10px;
    transition:.22s ease;
    position:relative;
    border:1px solid transparent;
}

.submenu .submenu-link::before{
    content:"";
    width:6px;
    height:6px;
    min-width:6px;
    border-radius:50%;
    background:#7c3aed !important;
    box-shadow:0 0 10px rgba(124,58,237,.65) !important;
}

.submenu .submenu-link:hover,
.submenu .submenu-link.active{
    background:rgba(109,76,255,.12) !important;
    border-color:rgba(109,76,255,.18) !important;
    color:#fff !important;
}

.submenu-collapse{
    overflow:hidden;
    max-height:0;
    opacity:0;
    transition:max-height .28s ease, opacity .22s ease;
}

.submenu-collapse.show{ opacity:1; }
.menu-toggle{ cursor:pointer; }

.sidebar-divider{
    height:1px;
    width:100%;
    background:linear-gradient(90deg, transparent, rgba(148,163,184,.14), transparent);
    margin:12px 0;
}

.bottom-area{ margin-top:6px; }

.new-customer-btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    width:100%;
    height:46px;
    text-decoration:none;
    color:#fff;
    font-size:15px;
    font-weight:700;
    border-radius:12px;
    border:1px solid rgba(109,76,255,.45) !important;
    background:linear-gradient(135deg, #6d4cff, #7c3aed) !important;
    box-shadow:0 14px 28px rgba(109,76,255,.25) !important;
    transition:.25s ease;
}

.new-customer-btn:hover{
    transform:translateY(-1px);
    color:#fff;
    background:linear-gradient(135deg, #7c3aed, #6d4cff) !important;
}

.menu-link.logout-link{ color:#f3f4f6 !important; }
.menu-link.logout-link .menu-icon{ color:#fb7185 !important; }
.menu-link.support-link .menu-icon{ color:#a78bfa !important; }

/* ================= GLOBAL FIXED LAYOUT COMPATIBILITY ================= */
.pos-layout,
.container-scroll{
    min-height:100vh;
    width:100%;
}

.pos-sidebar,
.container-scroll > .sidebar{
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    width:var(--pos-sidebar-width) !important;
    height:100vh !important;
    z-index:1040 !important;
    overflow:hidden !important;
    background:#0b1220 !important;
    transition:transform .25s ease;
}

.pos-main,
.container-scroll > .main-panel{
    min-height:100vh;
    margin-left:var(--pos-sidebar-width) !important;
    width:calc(100% - var(--pos-sidebar-width)) !important;
    transition:margin-left .25s ease, width .25s ease;
}

.pos-main .navbar.fixed-top,
.pos-main .my-navbar.fixed-top,
.container-scroll > .main-panel .navbar.fixed-top,
.container-scroll > .main-panel .my-navbar.fixed-top{
    left:var(--pos-sidebar-width) !important;
    width:calc(100% - var(--pos-sidebar-width)) !important;
    transition:left .25s ease, width .25s ease;
    z-index:1030;
}

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
body.sidebar-collapsed .pos-main .my-navbar.fixed-top,
body.sidebar-collapsed .container-scroll > .main-panel .navbar.fixed-top,
body.sidebar-collapsed .container-scroll > .main-panel .my-navbar.fixed-top{
    left:0 !important;
    width:100% !important;
}

.pos-content{ padding-top:94px; }

@media (max-width: 991px){
    .sidebar-wrap{
        max-width:100%;
        width:270px;
    }

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
    .pos-main .my-navbar.fixed-top,
    .container-scroll > .main-panel .navbar.fixed-top,
    .container-scroll > .main-panel .my-navbar.fixed-top{
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

    .sidebar-brand-text strong{ font-size:20px; }
}

/* =========================================================
   ONEERP SIDEBAR LIGHT MODE
   Is block ko sidebar ke final  se just pehle rakhein.
   Dark mode aur permissions/backend unchanged.
========================================================= */

/*html[data-pos-theme="light"]{*/
/*    color-scheme:light;*/
/*}*/

/* =========================================================
   SIDEBAR MAIN BACKGROUND
========================================================= */

/*html[data-pos-theme="light"] .sidebar-wrap{*/
/*    background:*/
/*        radial-gradient(*/
/*            circle at 20% 0%,*/
/*            rgba(124,58,237,.08),*/
/*            transparent 30%*/
/*        ),*/
/*        radial-gradient(*/
/*            circle at 100% 35%,*/
/*            rgba(59,130,246,.04),*/
/*            transparent 28%*/
/*        ),*/
/*        linear-gradient(*/
/*            180deg,*/
/*            #ffffff 0%,*/
/*            #fbfbff 100%*/
/*        ) !important;*/

/*    color:#1e293b !important;*/
/*    border-right:1px solid #dce3ee !important;*/

/*    box-shadow:*/
/*        8px 0 28px rgba(15,23,42,.08) !important;*/
/*}*/

/* Fixed sidebar parent bhi white rahe */
/*html[data-pos-theme="light"] .pos-sidebar,*/
/*html[data-pos-theme="light"] .container-scroll > .sidebar{*/
/*    background:#ffffff !important;*/
/*}*/

/* Dark glass overlay remove */
/*html[data-pos-theme="light"] .sidebar-wrap::before{*/
/*    background:*/
/*        linear-gradient(*/
/*            135deg,*/
/*            rgba(124,58,237,.025),*/
/*            transparent 35%,*/
/*            transparent 75%,*/
/*            rgba(124,58,237,.04)*/
/*        ) !important;*/
/*}*/

/* =========================================================
   LOGO AREA
========================================================= */

/*html[data-pos-theme="light"] .sidebar-brand{*/
/*    color:#0f172a !important;*/
/*    border-bottom-color:#e2e8f0 !important;*/
/*}*/

/*html[data-pos-theme="light"] .sidebar-brand:hover{*/
/*    color:#0f172a !important;*/
/*}*/

/*html[data-pos-theme="light"] .sidebar-brand-logo{*/
/*    background:transparent !important;*/
/*}*/

/* Logo ka heavy glow light mode mein kam */
/*html[data-pos-theme="light"] .sidebar-brand-logo img{*/
/*    filter:*/
/*        drop-shadow(0 4px 8px rgba(124,58,237,.18))*/
/*        drop-shadow(0 0 8px rgba(109,76,255,.10)) !important;*/
/*}*/

/* =========================================================
   NORMAL MAIN MENU LINKS
========================================================= */

/*html[data-pos-theme="light"] .sidebar-wrap .menu-link{*/
/*    color:#475569 !important;*/
/*    background:transparent !important;*/
/*    border-color:transparent !important;*/
/*    box-shadow:none !important;*/
/*}*/

/* Menu title */
/*html[data-pos-theme="light"] .sidebar-wrap .menu-title{*/
/*    color:inherit !important;*/
/*    opacity:1 !important;*/
/*}*/

/* Menu icons */
/*html[data-pos-theme="light"] .sidebar-wrap .menu-icon{*/
/*    color:#64748b !important;*/
/*    opacity:1 !important;*/
/*}*/

/* Menu arrow */
/*html[data-pos-theme="light"] .sidebar-wrap .menu-arrow{*/
/*    color:#64748b !important;*/
/*    opacity:1 !important;*/
/*}*/

/* Main menu hover */
/*html[data-pos-theme="light"] .sidebar-wrap .menu-link:hover{*/
/*    background:#f3efff !important;*/
/*    color:#5b21b6 !important;*/
/*    border-color:#ddd6fe !important;*/
/*    box-shadow:none !important;*/
/*}*/

/*html[data-pos-theme="light"] .sidebar-wrap .menu-link:hover .menu-icon,*/
/*html[data-pos-theme="light"] .sidebar-wrap .menu-link:hover .menu-arrow{*/
/*    color:#6d28d9 !important;*/
/*}*/

/* =========================================================
   ACTIVE PARENT MENU
   Example: POS / Sales
========================================================= */

/*html[data-pos-theme="light"] .sidebar-wrap .menu-link.active-parent,*/
/*html[data-pos-theme="light"] .sidebar-wrap .menu-link.top-active{*/
/*    background:*/
/*        linear-gradient(*/
/*            135deg,*/
/*            #8b5cf6,*/
/*            #6d28d9*/
/*        ) !important;*/

/*    color:#ffffff !important;*/
/*    border-color:#8b5cf6 !important;*/

/*    box-shadow:*/
/*        0 10px 22px rgba(124,58,237,.22) !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.active-parent .menu-title,*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.top-active .menu-title,*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.active-parent .menu-icon,*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.top-active .menu-icon,*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.active-parent .menu-arrow,*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.top-active .menu-arrow{*/
/*    color:#ffffff !important;*/
/*    opacity:1 !important;*/
/*}*/

/* =========================================================
   SUBMENU
========================================================= */

/*html[data-pos-theme="light"] .sidebar-wrap .submenu{*/
/*    border-left-color:#ddd6fe !important;*/
/*}*/

/* Normal submenu links */
/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .submenu .submenu-link{*/
/*    background:transparent !important;*/
/*    color:#64748b !important;*/
/*    border-color:transparent !important;*/
/*    opacity:1 !important;*/
/*}*/

/* Submenu dot */
/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .submenu .submenu-link::before{*/
/*    background:#8b5cf6 !important;*/
/*    box-shadow:0 0 6px rgba(124,58,237,.30) !important;*/
/*}*/

/* Submenu hover */
/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .submenu .submenu-link:hover{*/
/*    background:#f5f3ff !important;*/
/*    color:#5b21b6 !important;*/
/*    border-color:#ddd6fe !important;*/
/*}*/

/* Active submenu
   Example: Quotations */
/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .submenu .submenu-link.active{*/
/*    background:#ede9fe !important;*/
/*    color:#5b21b6 !important;*/
/*    border-color:#c4b5fd !important;*/
/*    box-shadow:*/
/*        inset 3px 0 0 #7c3aed !important;*/
/*    opacity:1 !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .submenu .submenu-link.active::before{*/
/*    background:#6d28d9 !important;*/
/*    box-shadow:*/
/*        0 0 0 3px rgba(124,58,237,.12),*/
/*        0 0 7px rgba(124,58,237,.28) !important;*/
/*}*/

/* =========================================================
   DIVIDERS
========================================================= */

/*html[data-pos-theme="light"] .sidebar-wrap .sidebar-divider{*/
/*    background:*/
/*        linear-gradient(*/
/*            90deg,*/
/*            transparent,*/
/*            #dce3ee,*/
/*            transparent*/
/*        ) !important;*/
/*}*/

/* =========================================================
   LOGOUT AND SUPPORT
========================================================= */

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.logout-link{*/
/*    color:#be123c !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.logout-link .menu-icon{*/
/*    color:#e11d48 !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.logout-link:hover{*/
/*    background:#fff1f2 !important;*/
/*    color:#be123c !important;*/
/*    border-color:#fecdd3 !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.support-link{*/
/*    color:#5b21b6 !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.support-link .menu-icon{*/
/*    color:#7c3aed !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .menu-link.support-link:hover{*/
/*    background:#f3efff !important;*/
/*    color:#5b21b6 !important;*/
/*    border-color:#ddd6fe !important;*/
/*}*/

/* =========================================================
   NEW CUSTOMER BUTTON
========================================================= */

/*html[data-pos-theme="light"] .sidebar-wrap .new-customer-btn{*/
/*    background:*/
/*        linear-gradient(*/
/*            135deg,*/
/*            #8b5cf6,*/
/*            #6d28d9*/
/*        ) !important;*/

/*    color:#ffffff !important;*/
/*    border-color:#8b5cf6 !important;*/

/*    box-shadow:*/
/*        0 10px 22px rgba(124,58,237,.22) !important;*/
/*}*/

/*html[data-pos-theme="light"] .sidebar-wrap .new-customer-btn:hover{*/
/*    background:*/
/*        linear-gradient(*/
/*            135deg,*/
/*            #7c3aed,*/
/*            #5b21b6*/
/*        ) !important;*/

/*    color:#ffffff !important;*/
/*}*/

/* =========================================================
   SCROLLBAR
========================================================= */

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .sidebar-menu::-webkit-scrollbar-track{*/
/*    background:transparent !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .sidebar-menu::-webkit-scrollbar-thumb{*/
/*    background:#c4b5fd !important;*/
/*    border-radius:999px !important;*/
/*}*/

/*html[data-pos-theme="light"]*/
/*.sidebar-wrap .sidebar-menu::-webkit-scrollbar-thumb:hover{*/
/*    background:#a78bfa !important;*/
/*}*/

/* =========================================================
   COLLAPSED SIDEBAR
========================================================= */

/*html[data-pos-theme="light"]*/
/*body.dashboard-sidebar-collapsed .sidebar-wrap,*/

/*html[data-pos-theme="light"]*/
/*body.sidebar-collapsed .sidebar-wrap{*/
/*    background:#ffffff !important;*/
/*}*/

/* =========================================================
   MOBILE SIDEBAR
========================================================= */

/*@media (max-width:991px){*/

/*    html[data-pos-theme="light"] .sidebar-wrap{*/
/*        background:#ffffff !important;*/

/*        box-shadow:*/
/*            12px 0 35px rgba(15,23,42,.16) !important;*/
/*    }*/

/*    html[data-pos-theme="light"] body.sidebar-open::after{*/
/*        background:rgba(15,23,42,.42) !important;*/
/*    }*/
/*}*/


/* =========================================================
   ONEERP SIDEBAR LIGHT MODE
   DARK NAVY + PURPLE REFERENCE THEME
   Page content light rahega, sidebar dark rahega
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   SIDEBAR BACKGROUND
========================================================= */

html[data-pos-theme="light"] .sidebar-wrap{
    background:
        radial-gradient(
            circle at 10% 0%,
            rgba(109,76,255,.22),
            transparent 28%
        ),
        radial-gradient(
            circle at 100% 45%,
            rgba(59,130,246,.11),
            transparent 28%
        ),
        linear-gradient(
            180deg,
            #071a35 0%,
            #08203f 48%,
            #06172f 100%
        ) !important;

    color:#ffffff !important;

    border-right:1px solid rgba(148,163,184,.14) !important;

    box-shadow:
        10px 0 34px rgba(15,23,42,.18) !important;
}

/* Fixed outer parent */
html[data-pos-theme="light"] .pos-sidebar,
html[data-pos-theme="light"]
.container-scroll > .sidebar{
    background:#071a35 !important;
}

/* Soft reference overlay */
html[data-pos-theme="light"] .sidebar-wrap::before{
    background:
        linear-gradient(
            145deg,
            rgba(255,255,255,.025),
            transparent 32%,
            transparent 70%,
            rgba(124,58,237,.10)
        ) !important;
}

/* Inner area */
html[data-pos-theme="light"] .sidebar-inner{
    color:#ffffff !important;
}

/* =========================================================
   LOGO AREA
========================================================= */

html[data-pos-theme="light"] .sidebar-brand{
    color:#ffffff !important;

    border-bottom:
        1px solid rgba(148,163,184,.13) !important;
}

html[data-pos-theme="light"] .sidebar-brand:hover{
    color:#ffffff !important;
}

html[data-pos-theme="light"]
.sidebar-brand-logo{
    background:transparent !important;
}

html[data-pos-theme="light"]
.sidebar-brand-logo img{
    filter:
        drop-shadow(0 0 10px rgba(124,58,237,.55))
        drop-shadow(0 0 20px rgba(109,76,255,.28)) !important;
}

/* =========================================================
   NORMAL MENU ITEMS
========================================================= */

html[data-pos-theme="light"]
.sidebar-wrap .menu-link{
    background:transparent !important;

    color:#e5e7eb !important;

    border:1px solid transparent !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-title,

html[data-pos-theme="light"]
.sidebar-wrap .menu-icon{
    color:inherit !important;
    opacity:1 !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-arrow{
    color:rgba(226,232,240,.62) !important;
}

/* Hover */
html[data-pos-theme="light"]
.sidebar-wrap .menu-link:hover{
    background:
        linear-gradient(
            135deg,
            rgba(109,76,255,.18),
            rgba(124,58,237,.13)
        ) !important;

    color:#ffffff !important;

    border-color:
        rgba(139,92,246,.20) !important;

    box-shadow:none !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-link:hover .menu-icon,

html[data-pos-theme="light"]
.sidebar-wrap .menu-link:hover .menu-arrow{
    color:#ffffff !important;
}

/* =========================================================
   ACTIVE MAIN MENU
========================================================= */

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.active-parent,

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.top-active{
    background:
        linear-gradient(
            135deg,
            #6d4cff 0%,
            #7c3aed 56%,
            #8b5cf6 100%
        ) !important;

    color:#ffffff !important;

    border-color:
        rgba(196,181,253,.30) !important;

    box-shadow:
        0 12px 28px rgba(109,76,255,.30),
        inset 0 1px 0 rgba(255,255,255,.12) !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.active-parent .menu-title,

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.top-active .menu-title,

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.active-parent .menu-icon,

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.top-active .menu-icon,

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.active-parent .menu-arrow,

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.top-active .menu-arrow{
    color:#ffffff !important;
}

/* =========================================================
   SUBMENU
========================================================= */

html[data-pos-theme="light"]
.sidebar-wrap .submenu{
    border-left:
        1px solid rgba(167,139,250,.25) !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .submenu .submenu-link{
    background:transparent !important;

    color:#94a3b8 !important;

    border:1px solid transparent !important;
}

/* Purple bullet */
html[data-pos-theme="light"]
.sidebar-wrap .submenu .submenu-link::before{
    background:#8b5cf6 !important;

    box-shadow:
        0 0 8px rgba(139,92,246,.65) !important;
}

/* Submenu hover */
html[data-pos-theme="light"]
.sidebar-wrap .submenu .submenu-link:hover{
    background:rgba(109,76,255,.14) !important;

    color:#ffffff !important;

    border-color:
        rgba(139,92,246,.17) !important;
}

/* Active submenu */
html[data-pos-theme="light"]
.sidebar-wrap .submenu .submenu-link.active{
    background:
        linear-gradient(
            135deg,
            rgba(109,76,255,.24),
            rgba(124,58,237,.18)
        ) !important;

    color:#ffffff !important;

    border-color:
        rgba(139,92,246,.28) !important;

    box-shadow:
        inset 3px 0 0 #8b5cf6 !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .submenu .submenu-link.active::before{
    background:#ffffff !important;

    box-shadow:
        0 0 0 3px rgba(139,92,246,.18),
        0 0 10px rgba(255,255,255,.35) !important;
}

/* =========================================================
   DIVIDER
========================================================= */

html[data-pos-theme="light"]
.sidebar-wrap .sidebar-divider{
    background:
        linear-gradient(
            90deg,
            transparent,
            rgba(148,163,184,.16),
            transparent
        ) !important;
}

/* =========================================================
   LOGOUT AND SUPPORT
========================================================= */

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.logout-link{
    color:#f8fafc !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.logout-link .menu-icon{
    color:#fb7185 !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.logout-link:hover{
    background:rgba(239,68,68,.12) !important;
    color:#ffffff !important;

    border-color:
        rgba(239,68,68,.18) !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.support-link{
    color:#e5e7eb !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.support-link .menu-icon{
    color:#a78bfa !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .menu-link.support-link:hover{
    background:rgba(124,58,237,.15) !important;
    color:#ffffff !important;

    border-color:
        rgba(139,92,246,.20) !important;
}

/* =========================================================
   NEW CUSTOMER BUTTON
========================================================= */

html[data-pos-theme="light"]
.sidebar-wrap .new-customer-btn{
    background:
        linear-gradient(
            135deg,
            #6d4cff,
            #7c3aed,
            #8b5cf6
        ) !important;

    color:#ffffff !important;

    border:
        1px solid rgba(196,181,253,.30) !important;

    box-shadow:
        0 12px 26px rgba(109,76,255,.28) !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .new-customer-btn:hover{
    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 16px 34px rgba(109,76,255,.36) !important;
}

/* =========================================================
   SIDEBAR SCROLLBAR
========================================================= */

html[data-pos-theme="light"]
.sidebar-wrap .sidebar-menu::-webkit-scrollbar-track{
    background:transparent !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .sidebar-menu::-webkit-scrollbar-thumb{
    background:rgba(139,92,246,.42) !important;
    border-radius:999px !important;
}

html[data-pos-theme="light"]
.sidebar-wrap .sidebar-menu::-webkit-scrollbar-thumb:hover{
    background:rgba(167,139,250,.65) !important;
}

/* =========================================================
   COLLAPSED SIDEBAR
========================================================= */

html[data-pos-theme="light"]
body.dashboard-sidebar-collapsed .sidebar-wrap,

html[data-pos-theme="light"]
body.sidebar-collapsed .sidebar-wrap{
    background:
        linear-gradient(
            180deg,
            #071a35,
            #06172f
        ) !important;
}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width:991px){

    html[data-pos-theme="light"] .sidebar-wrap{
        background:
            linear-gradient(
                180deg,
                #071a35,
                #06172f
            ) !important;

        box-shadow:
            14px 0 38px rgba(15,23,42,.32) !important;
    }

    html[data-pos-theme="light"]
    body.sidebar-open::after{
        background:rgba(2,6,23,.58) !important;
    }
}


<?php if ($use_custom_theme): ?>
/* =========================================================
   CLIENT CUSTOM SIDEBAR THEME
   Generated only when use_custom_theme = 1.
   When disabled, every original ONEERP sidebar style above remains active.
========================================================= */

/* Main sidebar background in light and dark page modes */
.sidebar-wrap.client-custom-sidebar,
.pos-sidebar,
.container-scroll > .sidebar{
    background:
        <?= htmlspecialchars(
            $sidebar_bg,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    background-image:none !important;

    color:
        <?= htmlspecialchars(
            $sidebar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    border-right-color:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

.sidebar-wrap.client-custom-sidebar::before{
    background:transparent !important;
}

/* Logo area and regular sidebar text */
.sidebar-wrap.client-custom-sidebar .sidebar-brand,

.sidebar-wrap.client-custom-sidebar .sidebar-brand:hover,

.sidebar-wrap.client-custom-sidebar .menu-link,

.sidebar-wrap.client-custom-sidebar .menu-title,

.sidebar-wrap.client-custom-sidebar .menu-icon,

.sidebar-wrap.client-custom-sidebar .menu-arrow,

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link,

.sidebar-wrap.client-custom-sidebar .logout-link,

.sidebar-wrap.client-custom-sidebar .support-link{
    color:
        <?= htmlspecialchars(
            $sidebar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

/* Normal links stay transparent until hover/active */
.sidebar-wrap.client-custom-sidebar .menu-link,

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link{
    background:transparent !important;
    box-shadow:none !important;
}

/* Client-selected hover color */
.sidebar-wrap.client-custom-sidebar .menu-link:hover,

.sidebar-wrap.client-custom-sidebar .menu-link.active-parent,

.sidebar-wrap.client-custom-sidebar .menu-link.top-active,

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link:hover,

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link.active,

.sidebar-wrap.client-custom-sidebar .new-customer-btn:hover{
    background:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    background-image:none !important;

    color:
        <?= htmlspecialchars(
            $sidebar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    border-color:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

/* Keep all child text/icons readable on hover and active rows */
.sidebar-wrap.client-custom-sidebar .menu-link:hover *,

.sidebar-wrap.client-custom-sidebar .menu-link.active-parent *,

.sidebar-wrap.client-custom-sidebar .menu-link.top-active *,

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link:hover,

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link.active{
    color:
        <?= htmlspecialchars(
            $sidebar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

/* Submenu accent and active indicator */
.sidebar-wrap.client-custom-sidebar .submenu{
    border-left-color:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link::before,

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link.active::before{
    background:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    box-shadow:none !important;
}

.sidebar-wrap.client-custom-sidebar .submenu .submenu-link.active{
    box-shadow:
        inset 3px 0 0
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

/* Dividers, scrollbar and bottom action */
.sidebar-wrap.client-custom-sidebar .sidebar-divider{
    background:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    opacity:.45;
}

.sidebar-wrap.client-custom-sidebar
.sidebar-menu::-webkit-scrollbar-thumb{
    background:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

.sidebar-wrap.client-custom-sidebar .new-customer-btn{
    background:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    background-image:none !important;

    color:
        <?= htmlspecialchars(
            $sidebar_text,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    border-color:
        <?= htmlspecialchars(
            $sidebar_hover,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;
}

/* Collapsed and mobile sidebar use the same selected background */
body.dashboard-sidebar-collapsed
.sidebar-wrap.client-custom-sidebar,

body.sidebar-collapsed
.sidebar-wrap.client-custom-sidebar,

body.sidebar-open
.sidebar-wrap.client-custom-sidebar{
    background:
        <?= htmlspecialchars(
            $sidebar_bg,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        !important;

    background-image:none !important;
}
<?php endif; ?>





/* =========================================================
   FINAL DESKTOP COLLAPSED SIDEBAR - ICON RAIL
   Sidebar completely hide nahi hogi.
   Close state mein icons visible rahenge.
========================================================= */

@media (min-width:992px){

    /* -----------------------------------------------------
       Collapsed sidebar main width
    ----------------------------------------------------- */

    body.sidebar-collapsed .pos-sidebar,
    body.sidebar-collapsed .container-scroll > .sidebar,
    body.sidebar-collapsed #dashboardSidebar,

    body.dashboard-sidebar-collapsed .pos-sidebar,
    body.dashboard-sidebar-collapsed .container-scroll > .sidebar,
    body.dashboard-sidebar-collapsed #dashboardSidebar{
        width:var(--pos-sidebar-collapsed-width) !important;
        min-width:var(--pos-sidebar-collapsed-width) !important;
        max-width:var(--pos-sidebar-collapsed-width) !important;

        transform:translateX(0) !important;

        overflow:hidden !important;

        transition:
            width .25s ease,
            min-width .25s ease,
            max-width .25s ease,
            transform .25s ease !important;
    }

    /*
    |--------------------------------------------------------------------------
    | Nested sidebar compatibility
    |--------------------------------------------------------------------------
    | Kuch pages mein #dashboardSidebar ke andar dobara #sidebar wrapper hai.
    |--------------------------------------------------------------------------
    */

    body.sidebar-collapsed
    .container-scroll > .sidebar > .sidebar,

    body.sidebar-collapsed
    #dashboardSidebar > #sidebar,

    body.dashboard-sidebar-collapsed
    .container-scroll > .sidebar > .sidebar,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar > #sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;

        margin:0 !important;
        padding:0 !important;

        overflow:hidden !important;
    }

    /* -----------------------------------------------------
       Sidebar inner wrapper
    ----------------------------------------------------- */

    body.sidebar-collapsed .sidebar-wrap,
    body.dashboard-sidebar-collapsed .sidebar-wrap{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;

        height:100vh !important;

        overflow:hidden !important;
    }

    body.sidebar-collapsed .sidebar-inner,
    body.dashboard-sidebar-collapsed .sidebar-inner{
        width:100% !important;

        padding:
            10px
            8px
            12px !important;

        align-items:center !important;
    }

    /* -----------------------------------------------------
       Main content should start after icon rail
    ----------------------------------------------------- */

    body.sidebar-collapsed .pos-main,
    body.sidebar-collapsed
    .container-scroll > .main-panel,

    body.dashboard-sidebar-collapsed .pos-main,
    body.dashboard-sidebar-collapsed
    .container-scroll > .main-panel{
        margin-left:
            var(--pos-sidebar-collapsed-width) !important;

        width:
            calc(
                100% -
                var(--pos-sidebar-collapsed-width)
            ) !important;

        transition:
            margin-left .25s ease,
            width .25s ease !important;
    }

    /* -----------------------------------------------------
       Fixed topbar position
    ----------------------------------------------------- */

    body.sidebar-collapsed
    .pos-main .navbar.fixed-top,

    body.sidebar-collapsed
    .pos-main .my-navbar.fixed-top,

    body.sidebar-collapsed
    .container-scroll > .main-panel
    .navbar.fixed-top,

    body.sidebar-collapsed
    .container-scroll > .main-panel
    .my-navbar.fixed-top,

    body.dashboard-sidebar-collapsed
    .pos-main .navbar.fixed-top,

    body.dashboard-sidebar-collapsed
    .pos-main .my-navbar.fixed-top,

    body.dashboard-sidebar-collapsed
    .container-scroll > .main-panel
    .navbar.fixed-top,

    body.dashboard-sidebar-collapsed
    .container-scroll > .main-panel
    .my-navbar.fixed-top{
        left:
            var(--pos-sidebar-collapsed-width) !important;

        width:
            calc(
                100% -
                var(--pos-sidebar-collapsed-width)
            ) !important;
    }

    /* -----------------------------------------------------
       Compact logo
    ----------------------------------------------------- */

    body.sidebar-collapsed .sidebar-brand,
    body.dashboard-sidebar-collapsed .sidebar-brand{
        width:100% !important;
        min-height:78px !important;

        margin:0 0 10px !important;
        padding:8px 2px 12px !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
    }

    body.sidebar-collapsed .sidebar-brand-logo,
    body.dashboard-sidebar-collapsed .sidebar-brand-logo{
        width:54px !important;
        min-width:54px !important;
        max-width:54px !important;

        height:54px !important;
        min-height:54px !important;
        max-height:54px !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;

        overflow:hidden !important;
    }

    body.sidebar-collapsed
    .sidebar-brand-logo img,

    body.dashboard-sidebar-collapsed
    .sidebar-brand-logo img{
        width:50px !important;
        max-width:50px !important;

        height:50px !important;
        max-height:50px !important;

        object-fit:contain !important;
        object-position:center !important;

        transform:none !important;

        padding:2px !important;
    }

    /* -----------------------------------------------------
       Hide text and arrows
    ----------------------------------------------------- */

    body.sidebar-collapsed .menu-title,
    body.sidebar-collapsed .menu-arrow,
    body.sidebar-collapsed .new-customer-btn span,

    body.dashboard-sidebar-collapsed .menu-title,
    body.dashboard-sidebar-collapsed .menu-arrow,
    body.dashboard-sidebar-collapsed
    .new-customer-btn span{
        display:none !important;
    }

    /* -----------------------------------------------------
       Submenus collapsed state mein hide
    ----------------------------------------------------- */

    body.sidebar-collapsed .submenu-collapse,
    body.sidebar-collapsed .submenu,

    body.dashboard-sidebar-collapsed
    .submenu-collapse,

    body.dashboard-sidebar-collapsed
    .submenu{
        display:none !important;

        max-height:0 !important;
        height:0 !important;

        opacity:0 !important;

        margin:0 !important;
        padding:0 !important;

        overflow:hidden !important;
    }

    /* -----------------------------------------------------
       Menu scrolling
    ----------------------------------------------------- */

    body.sidebar-collapsed .sidebar-menu,
    body.dashboard-sidebar-collapsed .sidebar-menu{
        width:100% !important;

        padding:0 !important;
        margin:0 !important;

        overflow-y:auto !important;
        overflow-x:hidden !important;

        display:flex !important;
        flex-direction:column !important;
        align-items:center !important;
    }

    body.sidebar-collapsed
    .sidebar-menu::-webkit-scrollbar,

    body.dashboard-sidebar-collapsed
    .sidebar-menu::-webkit-scrollbar{
        width:0 !important;
        display:none !important;
    }

    /* -----------------------------------------------------
       Menu sections
    ----------------------------------------------------- */

    body.sidebar-collapsed .menu-section,
    body.dashboard-sidebar-collapsed .menu-section{
        width:100% !important;

        margin:0 0 7px !important;
        padding:0 !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
    }

    /* -----------------------------------------------------
       Icon buttons
    ----------------------------------------------------- */

    body.sidebar-collapsed .menu-link,
    body.dashboard-sidebar-collapsed .menu-link{
        width:52px !important;
        min-width:52px !important;
        max-width:52px !important;

        height:48px !important;
        min-height:48px !important;

        margin:0 auto !important;
        padding:0 !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;

        border-radius:13px !important;

        overflow:hidden !important;
    }

    body.sidebar-collapsed .menu-main,
    body.dashboard-sidebar-collapsed .menu-main{
        width:100% !important;
        height:100% !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;

        gap:0 !important;
    }

    body.sidebar-collapsed .menu-icon,
    body.dashboard-sidebar-collapsed .menu-icon{
        width:auto !important;
        min-width:0 !important;

        margin:0 !important;

        font-size:19px !important;
        line-height:1 !important;

        text-align:center !important;
    }

    /* Active icon reference-style purple box */

    body.sidebar-collapsed
    .menu-link.active-parent,

    body.sidebar-collapsed
    .menu-link.top-active,

    body.dashboard-sidebar-collapsed
    .menu-link.active-parent,

    body.dashboard-sidebar-collapsed
    .menu-link.top-active{
        background:
            linear-gradient(
                135deg,
                #6d4cff,
                #7c3aed
            ) !important;

        color:#ffffff !important;

        border-color:
            rgba(196,181,253,.32) !important;

        box-shadow:
            0 10px 22px
            rgba(109,76,255,.30) !important;
    }

    /* Hover icon box */

    body.sidebar-collapsed
    .menu-link:hover,

    body.dashboard-sidebar-collapsed
    .menu-link:hover{
        background:
            rgba(124,58,237,.18) !important;

        color:#ffffff !important;

        border-color:
            rgba(139,92,246,.24) !important;
    }

    /* -----------------------------------------------------
       Divider
    ----------------------------------------------------- */

    body.sidebar-collapsed .sidebar-divider,
    body.dashboard-sidebar-collapsed
    .sidebar-divider{
        width:42px !important;
        min-width:42px !important;

        margin:
            10px
            auto !important;
    }

    /* -----------------------------------------------------
       Bottom new customer icon
    ----------------------------------------------------- */

    body.sidebar-collapsed .bottom-area,
    body.dashboard-sidebar-collapsed .bottom-area{
        width:100% !important;
    }

    body.sidebar-collapsed .new-customer-btn,
    body.dashboard-sidebar-collapsed
    .new-customer-btn{
        width:50px !important;
        min-width:50px !important;
        max-width:50px !important;

        height:48px !important;

        margin:0 auto !important;
        padding:0 !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;

        border-radius:13px !important;
    }

    body.sidebar-collapsed
    .new-customer-btn i,

    body.dashboard-sidebar-collapsed
    .new-customer-btn i{
        margin:0 !important;
        font-size:18px !important;
    }
}



/* =========================================================
   COLLAPSED SIDEBAR FLOATING MODULE MENU
========================================================= */

.collapsed-sidebar-flyout{
    position:fixed !important;
    z-index:999999 !important;

    width:260px !important;
    max-width:calc(100vw - 110px) !important;
    max-height:calc(100vh - 24px) !important;

    padding:10px !important;

    background:
        radial-gradient(
            circle at 0% 0%,
            rgba(124,58,237,.18),
            transparent 34%
        ),
        linear-gradient(
            145deg,
            #0f172a,
            #0b1220
        ) !important;

    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:15px !important;

    box-shadow:
        0 24px 60px rgba(0,0,0,.48),
        0 0 0 1px rgba(124,58,237,.08) !important;

    overflow-y:auto !important;
    overflow-x:hidden !important;

    opacity:0 !important;
    visibility:hidden !important;
    pointer-events:none !important;

    transform:
        translateX(-8px)
        scale(.98) !important;

    transform-origin:left top !important;

    transition:
        opacity .18s ease,
        visibility .18s ease,
        transform .18s ease !important;
}

.collapsed-sidebar-flyout.show{
    opacity:1 !important;
    visibility:visible !important;
    pointer-events:auto !important;

    transform:
        translateX(0)
        scale(1) !important;
}

/* Left arrow */
.collapsed-sidebar-flyout::before{
    content:"" !important;

    position:absolute !important;
    left:-7px !important;
    top:20px !important;

    width:14px !important;
    height:14px !important;

    background:#0f172a !important;

    border-left:
        1px solid rgba(148,163,184,.18) !important;

    border-bottom:
        1px solid rgba(148,163,184,.18) !important;

    transform:rotate(45deg) !important;
}

/* Module title */
.collapsed-flyout-title{
    min-height:42px !important;

    margin-bottom:7px !important;
    padding:10px 12px !important;

    display:flex !important;
    align-items:center !important;
    gap:9px !important;

    background:
        linear-gradient(
            135deg,
            rgba(109,76,255,.26),
            rgba(124,58,237,.17)
        ) !important;

    border:
        1px solid rgba(139,92,246,.24) !important;

    border-radius:11px !important;

    color:#ffffff !important;

    font-size:13px !important;
    font-weight:900 !important;
}

.collapsed-flyout-title i{
    width:20px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    color:#c4b5fd !important;
    font-size:15px !important;
}

/* Floating submenu list */
.collapsed-flyout-links{
    display:flex !important;
    flex-direction:column !important;
    gap:4px !important;
}

/* Page links */
.collapsed-flyout-link{
    width:100% !important;
    min-height:39px !important;

    padding:9px 11px !important;

    display:flex !important;
    align-items:center !important;
    gap:9px !important;

    border:
        1px solid transparent !important;

    border-radius:10px !important;

    background:transparent !important;
    color:#94a3b8 !important;

    text-decoration:none !important;

    font-size:12px !important;
    line-height:1.35 !important;
    font-weight:800 !important;

    transition:
        background .18s ease,
        border-color .18s ease,
        color .18s ease,
        transform .18s ease !important;
}

.collapsed-flyout-link::before{
    content:"" !important;

    width:6px !important;
    min-width:6px !important;
    height:6px !important;

    border-radius:50% !important;

    background:#8b5cf6 !important;

    box-shadow:
        0 0 8px rgba(139,92,246,.55) !important;
}

.collapsed-flyout-link:hover{
    background:rgba(124,58,237,.14) !important;
    border-color:rgba(139,92,246,.22) !important;

    color:#ffffff !important;

    transform:translateX(2px) !important;
}

.collapsed-flyout-link.active{
    background:
        linear-gradient(
            135deg,
            rgba(109,76,255,.30),
            rgba(124,58,237,.22)
        ) !important;

    border-color:
        rgba(139,92,246,.32) !important;

    color:#ffffff !important;
}

.collapsed-flyout-link.active::before{
    background:#ffffff !important;

    box-shadow:
        0 0 0 3px rgba(139,92,246,.18),
        0 0 9px rgba(255,255,255,.35) !important;
}

/* Selected parent icon */
body.sidebar-collapsed
.menu-link.collapsed-flyout-open,

body.dashboard-sidebar-collapsed
.menu-link.collapsed-flyout-open{
    background:
        linear-gradient(
            135deg,
            #6d4cff,
            #7c3aed
        ) !important;

    color:#ffffff !important;

    border-color:
        rgba(196,181,253,.34) !important;

    box-shadow:
        0 10px 24px rgba(109,76,255,.34) !important;
}

/* Scrollbar */
.collapsed-sidebar-flyout::-webkit-scrollbar{
    width:5px !important;
}

.collapsed-sidebar-flyout::-webkit-scrollbar-track{
    background:transparent !important;
}

.collapsed-sidebar-flyout::-webkit-scrollbar-thumb{
    background:rgba(139,92,246,.46) !important;
    border-radius:999px !important;
}

/* Custom client theme compatibility */
.sidebar-wrap.client-custom-sidebar
~ .collapsed-sidebar-flyout{
    color:inherit !important;
}

/* Only desktop collapsed sidebar */
@media (max-width:991px){
    .collapsed-sidebar-flyout{
        display:none !important;
    }
}



/* =========================================================
   UNIVERSAL COLLAPSED SIDEBAR ICON RAIL FIX
   All ONEERP pages
========================================================= */

@media (min-width:992px){

    /* Outer sidebar visible rahe */
    body.sidebar-collapsed
    .container-scroll > .sidebar,

    body.sidebar-collapsed
    #dashboardSidebar,

    body.dashboard-sidebar-collapsed
    .container-scroll > .sidebar,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar{
        display:block !important;
        visibility:visible !important;
        opacity:1 !important;

        width:78px !important;
        min-width:78px !important;
        max-width:78px !important;

        height:100vh !important;

        left:0 !important;
        top:0 !important;

        transform:translateX(0) !important;
        overflow:visible !important;
    }

    /* Sidebar wrapper */
    body.sidebar-collapsed
    #dashboardSidebar .sidebar-wrap,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar .sidebar-wrap,

    body.sidebar-collapsed
    .sidebar-wrap,

    body.dashboard-sidebar-collapsed
    .sidebar-wrap{
        display:block !important;
        visibility:visible !important;
        opacity:1 !important;

        width:78px !important;
        min-width:78px !important;
        max-width:78px !important;

        height:100vh !important;
        min-height:100vh !important;

        overflow:hidden !important;
    }

    /* Sidebar inner content */
    body.sidebar-collapsed
    #dashboardSidebar .sidebar-inner,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar .sidebar-inner,

    body.sidebar-collapsed
    .sidebar-inner,

    body.dashboard-sidebar-collapsed
    .sidebar-inner{
        display:flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:100% !important;
        height:100vh !important;

        flex-direction:column !important;
        align-items:center !important;

        padding:10px 8px 12px !important;
    }

    /* Small logo visible */
    body.sidebar-collapsed .sidebar-brand,
    body.dashboard-sidebar-collapsed .sidebar-brand{
        display:flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:100% !important;
        min-height:70px !important;

        align-items:center !important;
        justify-content:center !important;

        margin:0 0 9px !important;
        padding:7px 0 10px !important;
    }

    body.sidebar-collapsed .sidebar-brand-logo,
    body.dashboard-sidebar-collapsed .sidebar-brand-logo{
        display:flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:54px !important;
        min-width:54px !important;
        max-width:54px !important;

        height:50px !important;

        align-items:center !important;
        justify-content:center !important;

        overflow:hidden !important;
    }

    body.sidebar-collapsed .sidebar-brand-logo img,
    body.dashboard-sidebar-collapsed .sidebar-brand-logo img{
        display:block !important;
        visibility:visible !important;
        opacity:1 !important;

        width:50px !important;
        max-width:50px !important;
        height:46px !important;
        max-height:46px !important;

        object-fit:contain !important;
        transform:none !important;
    }

    /* Sidebar menu visible */
    body.sidebar-collapsed
    #dashboardSidebar .sidebar-menu,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar .sidebar-menu,

    body.sidebar-collapsed
    .sidebar-menu,

    body.dashboard-sidebar-collapsed
    .sidebar-menu{
        display:flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:100% !important;
        min-height:0 !important;

        flex:1 1 auto !important;
        flex-direction:column !important;
        align-items:center !important;

        margin:0 !important;
        padding:0 !important;

        overflow-y:auto !important;
        overflow-x:hidden !important;
    }

    /* Every module section visible */
    body.sidebar-collapsed
    #dashboardSidebar .menu-section,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar .menu-section,

    body.sidebar-collapsed
    .menu-section,

    body.dashboard-sidebar-collapsed
    .menu-section{
        display:flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:100% !important;

        align-items:center !important;
        justify-content:center !important;

        margin:0 0 7px !important;
        padding:0 !important;
    }

    /* Module icon button */
    body.sidebar-collapsed
    #dashboardSidebar .menu-link,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar .menu-link,

    body.sidebar-collapsed
    .menu-link,

    body.dashboard-sidebar-collapsed
    .menu-link{
        display:flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:52px !important;
        min-width:52px !important;
        max-width:52px !important;

        height:48px !important;
        min-height:48px !important;

        align-items:center !important;
        justify-content:center !important;

        margin:0 auto !important;
        padding:0 !important;

        border-radius:13px !important;
        overflow:hidden !important;
    }

    /* Icon wrapper */
    body.sidebar-collapsed
    #dashboardSidebar .menu-main,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar .menu-main,

    body.sidebar-collapsed
    .menu-main,

    body.dashboard-sidebar-collapsed
    .menu-main{
        display:flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:100% !important;
        height:100% !important;

        align-items:center !important;
        justify-content:center !important;

        gap:0 !important;
        margin:0 !important;
    }

    /* Actual Bootstrap icons */
    body.sidebar-collapsed
    #dashboardSidebar .menu-icon,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar .menu-icon,

    body.sidebar-collapsed
    .menu-icon,

    body.dashboard-sidebar-collapsed
    .menu-icon{
        display:inline-flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:22px !important;
        min-width:22px !important;
        height:22px !important;

        align-items:center !important;
        justify-content:center !important;

        margin:0 !important;
        padding:0 !important;

        color:currentColor !important;

        font-size:19px !important;
        line-height:1 !important;
        text-align:center !important;
    }

    body.sidebar-collapsed
    #dashboardSidebar .menu-icon::before,

    body.dashboard-sidebar-collapsed
    #dashboardSidebar .menu-icon::before,

    body.sidebar-collapsed
    .menu-icon::before,

    body.dashboard-sidebar-collapsed
    .menu-icon::before{
        display:block !important;
        visibility:visible !important;
        opacity:1 !important;

        margin:0 !important;
        line-height:1 !important;
    }

    /* Collapsed mode mein sirf text hide ho */
    body.sidebar-collapsed .menu-title,
    body.sidebar-collapsed .menu-arrow,
    body.dashboard-sidebar-collapsed .menu-title,
    body.dashboard-sidebar-collapsed .menu-arrow{
        display:none !important;
    }

    /* Original submenu hidden; floating box JS ise read karega */
    body.sidebar-collapsed .submenu,
    body.sidebar-collapsed .submenu-collapse,
    body.dashboard-sidebar-collapsed .submenu,
    body.dashboard-sidebar-collapsed .submenu-collapse{
        display:none !important;
        visibility:hidden !important;
        opacity:0 !important;

        width:0 !important;
        height:0 !important;
        max-height:0 !important;

        margin:0 !important;
        padding:0 !important;
        overflow:hidden !important;
    }

    /* Main content icon rail ke baad start ho */
    body.sidebar-collapsed
    .container-scroll > .main-panel,

    body.dashboard-sidebar-collapsed
    .container-scroll > .main-panel{
        margin-left:78px !important;
        width:calc(100% - 78px) !important;
    }

    /* Active module purple box */
    body.sidebar-collapsed
    .menu-link.active-parent,

    body.sidebar-collapsed
    .menu-link.top-active,

    body.dashboard-sidebar-collapsed
    .menu-link.active-parent,

    body.dashboard-sidebar-collapsed
    .menu-link.top-active{
        background:linear-gradient(
            135deg,
            #6d4cff,
            #7c3aed
        ) !important;

        color:#ffffff !important;

        border-color:rgba(196,181,253,.32) !important;

        box-shadow:
            0 10px 22px rgba(109,76,255,.30) !important;
    }

    /* Bottom button */
    body.sidebar-collapsed .new-customer-btn,
    body.dashboard-sidebar-collapsed .new-customer-btn{
        display:flex !important;
        visibility:visible !important;
        opacity:1 !important;

        width:50px !important;
        min-width:50px !important;
        max-width:50px !important;

        height:48px !important;

        align-items:center !important;
        justify-content:center !important;

        margin:0 auto !important;
        padding:0 !important;
    }

    body.sidebar-collapsed .new-customer-btn i,
    body.dashboard-sidebar-collapsed .new-customer-btn i{
        display:block !important;
        visibility:visible !important;
        opacity:1 !important;

        margin:0 !important;
    }
}


</style>

<div class="sidebar-wrap<?= $use_custom_theme ? ' client-custom-sidebar' : '' ?>">
    <div class="sidebar-inner">

        <a href="<?= $is_superadmin ? 'dashboard_view.php' : 'dashboard.php' ?>" class="sidebar-brand">
            <span class="sidebar-brand-logo">
                <img src="one-erp-logo-.png" alt="One ERP Logo">
            </span>
        </a>

        <div class="sidebar-menu">

            <?php if($is_superadmin): ?>

                <div class="menu-section">
                    <a class="menu-link <?= link_active(['dashboard_view.php']) ? 'top-active' : '' ?>" href="dashboard_view.php">
                        <div class="menu-main">
                            <i class="bi bi-grid menu-icon"></i>
                            <span class="menu-title">Dashboard</span>
                        </div>
                    </a>
                </div>

                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['add_role_view.php','all_clints.php','add_users.php','suspend_clients.php','branches.php']) ?>"
                       href="#clientsMenu"
                       aria-expanded="<?= is_page(['add_role_view.php','all_clints.php','add_users.php','suspend_clients.php','branches.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-buildings menu-icon"></i>
                            <span class="menu-title">Clients / Tenants</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['add_role_view.php','all_clints.php','add_users.php','suspend_clients.php','branches.php']) ?>" id="clientsMenu">
                        <div class="submenu">
                            <a class="submenu-link <?= link_active(['add_role_view.php']) ?>" href="add_role_view.php">Roles</a>
                            <a class="submenu-link <?= link_active(['all_clints.php']) ?>" href="all_clints.php">All Clients</a>
                            <a class="submenu-link <?= link_active(['add_users.php']) ?>" href="add_users.php">Add Users</a>
                            <a class="submenu-link <?= link_active(['suspend_clients.php']) ?>" href="suspend_clients.php">Suspended Clients</a>
                            <a class="submenu-link <?= link_active(['branches.php']) ?>" href="branches.php">Add Branches</a>
                        </div>
                    </div>
                </div>

       <div class="menu-section">

    <a
        class="menu-link menu-toggle <?= parent_active([
            'plans.php',
            'subscriptions.php',
            'subscription_renewal_requests.php',
            'invoices.php',
            'trial.php'
        ]) ?>"
        href="#subscriptionsMenu"
        aria-expanded="<?= is_page([
            'plans.php',
            'subscriptions.php',
            'subscription_renewal_requests.php',
            'invoices.php',
            'trial.php'
        ]) ? 'true' : 'false' ?>"
    >
        <div class="menu-main">
            <i class="bi bi-credit-card menu-icon"></i>
            <span class="menu-title">
                Subscriptions
            </span>
        </div>

        <i class="bi bi-chevron-down menu-arrow"></i>
    </a>

    <div
        class="submenu-collapse <?= menu_open([
            'plans.php',
            'subscriptions.php',
            'subscription_renewal_requests.php',
            'invoices.php',
            'trial.php'
        ]) ?>"
        id="subscriptionsMenu"
    >
        <div class="submenu">

            <a
                class="submenu-link <?= link_active([
                    'plans.php'
                ]) ?>"
                href="plans.php"
            >
                Plans
            </a>

            <a
                class="submenu-link <?= link_active([
                    'subscriptions.php'
                ]) ?>"
                href="subscriptions.php"
            >
                Client Membership
            </a>

            <a
                class="submenu-link <?= link_active([
                    'subscription_renewal_requests.php'
                ]) ?>"
                href="subscription_renewal_requests.php"
            >
                Renewal Requests
            </a>

            <a
                class="submenu-link <?= link_active([
                    'invoices.php'
                ]) ?>"
                href="invoices.php"
            >
                Invoices
            </a>

            <a
                class="submenu-link <?= link_active([
                    'trial.php'
                ]) ?>"
                href="trial.php"
            >
                Trial Management
            </a>

        </div>
    </div>

</div>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['fbr.php','wholesale.php','inventory_feature.php','idustry.php']) ?>"
                       href="#featureControlMenu"
                       aria-expanded="<?= is_page(['fbr.php','wholesale.php','inventory_feature.php','idustry.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-sliders menu-icon"></i>
                            <span class="menu-title">Feature Control</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['fbr.php','wholesale.php','inventory_feature.php','idustry.php']) ?>" id="featureControlMenu">
                        <div class="submenu">
                            <a class="submenu-link <?= link_active(['fbr.php']) ?>" href="fbr.php">FBR Integration</a>
                            <a class="submenu-link <?= link_active(['wholesale.php']) ?>" href="wholesale.php">Wholesale Enable/Disable</a>
                            <a class="submenu-link <?= link_active(['inventory_feature.php']) ?>" href="inventory_feature.php">Inventory Enable/Disable</a>
                            <a class="submenu-link <?= link_active(['idustry.php']) ?>" href="idustry.php">Industry Modules</a>
                        </div>
                    </div>
                </div>

                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['online_branches.php','pos_activity.php','fbr_sync.php']) ?>"
                       href="#systemMonitoringMenu"
                       aria-expanded="<?= is_page(['online_branches.php','pos_activity.php','fbr_sync.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-pc-display menu-icon"></i>
                            <span class="menu-title">System Monitoring</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['online_branches.php','pos_activity.php','fbr_sync.php']) ?>" id="systemMonitoringMenu">
                        <div class="submenu">
                            <a class="submenu-link <?= link_active(['all_clints.php']) ?>" href="all_clints.php">Active Clients</a>
                            <a class="submenu-link <?= link_active(['online_branches.php']) ?>" href="online_branches.php">Online Branches</a>
                            <a class="submenu-link <?= link_active(['pos_activity.php']) ?>" href="pos_activity.php">POS Activity</a>
                            <a class="submenu-link <?= link_active(['fbr_sync.php']) ?>" href="fbr_sync.php">FBR Sync Status</a>
                        </div>
                    </div>
                </div>

                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['daily.php','cashier_shift.php','profit_loss.php','inventory_stock.php','low_stock.php','tax_summary.php','customer_ledger.php','supplier_ledger.php']) ?>"
                       href="#reportsMenu"
                       aria-expanded="<?= is_page(['daily.php','cashier_shift.php','profit_loss.php','inventory_stock.php','low_stock.php','tax_summary.php','customer_ledger.php','supplier_ledger.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-bar-chart-line menu-icon"></i>
                            <span class="menu-title">Reports</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['daily.php','cashier_shift.php','profit_loss.php','inventory_stock.php','low_stock.php','tax_summary.php','customer_ledger.php','supplier_ledger.php']) ?>" id="reportsMenu">
                        <div class="submenu">
                            <a class="submenu-link <?= link_active(['daily.php']) ?>" href="daily.php">Sale Reports</a>
                            <a class="submenu-link <?= link_active(['cashier_shift.php']) ?>" href="cashier_shift.php">Cashier Shift</a>
                            <a class="submenu-link <?= link_active(['profit_loss.php']) ?>" href="profit_loss.php">Profit & Loss</a>
                            <a class="submenu-link <?= link_active(['inventory_stock.php']) ?>" href="inventory_stock.php">Inventory Stock</a>
                            <a class="submenu-link <?= link_active(['low_stock.php']) ?>" href="low_stock.php">Low Stock</a>
                            <a class="submenu-link <?= link_active(['tax_summary.php']) ?>" href="tax_summary.php">Tax Summary</a>
                            <a class="submenu-link <?= link_active(['customer_ledger.php']) ?>" href="customer_ledger.php">Customer Ledger</a>
                            <a class="submenu-link <?= link_active(['supplier_ledger.php']) ?>" href="supplier_ledger.php">Supplier Ledger</a>
                        </div>
                    </div>
                </div>

                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['login_logs.php','action_logs.php','devices.php']) ?>"
                       href="#auditMenu"
                       aria-expanded="<?= is_page(['login_logs.php','action_logs.php','devices.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-shield-lock menu-icon"></i>
                            <span class="menu-title">Audit & Security</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['login_logs.php','action_logs.php','devices.php']) ?>" id="auditMenu">
                        <div class="submenu">
                            <a class="submenu-link <?= link_active(['login_logs.php']) ?>" href="login_logs.php">Login Logs</a>
                            <a class="submenu-link <?= link_active(['action_logs.php']) ?>" href="action_logs.php">Action Logs</a>
                            <a class="submenu-link <?= link_active(['devices.php']) ?>" href="devices.php">IP / Device Tracking</a>
                        </div>
                    </div>
                </div>

                <div class="menu-section">
                    <a class="menu-link <?= link_active(['setting.php']) ? 'top-active' : '' ?>" href="setting.php">
                        <div class="menu-main">
                            <i class="bi bi-globe menu-icon"></i>
                            <span class="menu-title">Global Settings</span>
                        </div>
                    </a>
                </div>

            <?php else: ?>

                <?php if(can_show('dashboard')): ?>
                <div class="menu-section">
                    <a class="menu-link <?= link_active(['dashboard.php']) ? 'top-active' : '' ?>" href="dashboard.php">
                        <div class="menu-main">
                            <i class="bi bi-grid-1x2 menu-icon"></i>
                            <span class="menu-title">Dashboard</span>
                        </div>
                    </a>
                </div>
                <?php endif; ?>

                <!-- ================= OPD MANAGEMENT MODULE ================= -->
                <?php if($showOpdModule): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active($opdPages) ?>"
                       href="#opdMenu"
                       aria-expanded="<?= is_page($opdPages) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-heart-pulse menu-icon"></i>
                            <span class="menu-title">OPD Management</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open($opdPages) ?>" id="opdMenu">
                        <div class="submenu">

                            <?php if(can_show('opd_dashboard')): ?>
                                <a class="submenu-link <?= link_active(['opd_dashboard.php']) ?>" href="opd_dashboard.php">
                                    OPD Dashboard
                                </a>
                            <?php endif; ?>

                            <?php if(can_show_any(['opd_reception','opd_tokens'])): ?>
                                <a class="submenu-link <?= link_active(['opd_reception.php','opd_token_print.php']) ?>" href="opd_reception.php">
                                    Reception / Token
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('opd_patients')): ?>
                                <a class="submenu-link <?= link_active(['opd_patients.php','opd_patient_form.php']) ?>" href="opd_patients.php">
                                    Patients
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('opd_vitals')): ?>
                                <a class="submenu-link <?= link_active(['opd_vitals.php']) ?>" href="opd_vitals.php">
                                    Vitals / Triage
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('opd_queue')): ?>
                                <a class="submenu-link <?= link_active(['opd_queue.php']) ?>" href="opd_queue.php">
                                    Doctor Queue
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('opd_billing')): ?>
                                <a class="submenu-link <?= link_active(['opd_payments.php']) ?>" href="opd_payments.php">
                                    OPD Payments
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('opd_departments')): ?>
                                <a class="submenu-link <?= link_active(['opd_departments.php']) ?>" href="opd_departments.php">
                                    Departments
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('opd_doctors')): ?>
                                <a class="submenu-link <?= link_active(['opd_doctors.php']) ?>" href="opd_doctors.php">
                                    Doctors
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('opd_reports')): ?>
                                <a class="submenu-link <?= link_active(['opd_reports.php']) ?>" href="opd_reports.php">
                                    OPD Reports
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('opd_settings')): ?>
                                <a class="submenu-link <?= link_active(['opd_settings.php']) ?>" href="opd_settings.php">
                                    OPD Settings
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ================= RESTAURANT MANAGEMENT MODULE ================= -->
                <?php if($showRestaurantModule): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active($restaurantPages) ?>"
                       href="#restaurantMenu"
                       aria-expanded="<?= is_page($restaurantPages) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-cup-hot menu-icon"></i>
                            <span class="menu-title">Restaurant</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open($restaurantPages) ?>" id="restaurantMenu">
                        <div class="submenu">

                          
                          

                            <?php if(can_show('restaurant') || can_show('restaurant_tables')): ?>
                                <a class="submenu-link <?= link_active(['restaurant_tables.php']) ?>" href="restaurant_tables.php">
                                    Tables
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('restaurant') || can_show('restaurant_bookings')): ?>
                                <a class="submenu-link <?= link_active(['restaurant_bookings.php']) ?>" href="restaurant_bookings.php">
                                    Bookings
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('restaurant') || can_show('restaurant_pos')): ?>
                                <a class="submenu-link <?= link_active(['restaurant_pos.php']) ?>" href="restaurant_pos.php">
                                    Restaurant POS
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('restaurant') || can_show('restaurant_orders')): ?>
                                <a class="submenu-link <?= link_active(['restaurant_orders.php']) ?>" href="restaurant_orders.php">
                                    Running Orders
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('restaurant') || can_show('restaurant_kot') || can_show('restaurant_kitchen')): ?>
                                <a class="submenu-link <?= link_active(['restaurant_kitchen.php']) ?>" href="restaurant_kitchen.php">
                                    Kitchen / KOT
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('restaurant') || can_show('restaurant_waiters')): ?>
                                <a class="submenu-link <?= link_active(['restaurant_waiters.php']) ?>" href="restaurant_waiters.php">
                                    Waiters
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('restaurant') || can_show('restaurant_reports')): ?>
                                <a class="submenu-link <?= link_active(['restaurant_reports.php']) ?>" href="restaurant_reports.php">
                                    Reports
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('restaurant') || can_show('restaurant_settings')): ?>
                                <a class="submenu-link <?= link_active(['restaurant_settings.php']) ?>" href="restaurant_settings.php">
                                    Settings
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if(can_show_any(['pos_sales','invoices','sale_reports','cashier_shift','discounts','returns','quotations'])): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['pos_screen.php','orders.php','sale_reports.php','cashier_shift.php','returns.php','quotations.php','discounts.php']) ?>"
                       href="#posMenu"
                       aria-expanded="<?= is_page(['pos_screen.php','orders.php','sale_reports.php','cashier_shift.php','returns.php','quotations.php','discounts.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-shop-window menu-icon"></i>
                            <span class="menu-title">POS / Sales</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['pos_screen.php','orders.php','sale_reports.php','cashier_shift.php','returns.php','quotations.php','discounts.php']) ?>" id="posMenu">
                        <div class="submenu">
                            <?php if(can_show('pos_sales')): ?>
                                <a class="submenu-link <?= link_active(['pos_screen.php']) ?>" href="pos_screen.php">POS Screen</a>
                            <?php endif; ?>

                            <?php if(can_show('invoices')): ?>
                                <a class="submenu-link <?= link_active(['orders.php']) ?>" href="orders.php">Invoice Management</a>
                            <?php endif; ?>

                            <?php if(can_show('sale_reports')): ?>
                                <a class="submenu-link <?= link_active(['sale_reports.php']) ?>" href="sale_reports.php">Sale Reports</a>
                            <?php endif; ?>

                            <?php if(can_show('cashier_shift')): ?>
                                <a class="submenu-link <?= link_active(['cashier_shift.php']) ?>" href="cashier_shift.php">Cashier Shift</a>
                            <?php endif; ?>

                            <?php if(can_show('returns')): ?>
                                <a class="submenu-link <?= link_active(['returns.php']) ?>" href="returns.php">Returns / Refunds</a>
                            <?php endif; ?>

                            <?php if(can_show('quotations')): ?>
                                <a class="submenu-link <?= link_active(['quotations.php']) ?>" href="quotations.php">Quotations</a>
                            <?php endif; ?>

                            <?php if(can_show('discounts')): ?>
                                <a class="submenu-link <?= link_active(['discounts.php']) ?>" href="discounts.php">Discounts / Coupons</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

        <?php if(can_show_any(['products','services','inventory','inventory_categories','inventory_adjustments','item_variants','barcode','low_stock'])): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['items.php','categories.php','services.php','item_batches.php','inventory.php','add_barcode_pos.php','low_stock.php']) ?>"
                       href="#inventoryMenu"
                       aria-expanded="<?= is_page(['items.php','categories.php','services.php','item_batches.php','inventory.php','add_barcode_pos.php','low_stock.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-box-seam menu-icon"></i>
                            <span class="menu-title"><?= is_services_industry_client() ? 'Services' : 'Inventory' ?></span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['items.php','categories.php','services.php','item_batches.php','inventory.php','add_barcode_pos.php','low_stock.php']) ?>" id="inventoryMenu">
                        <div class="submenu">
                            <?php if(!is_services_industry_client() && can_show('products')): ?>
                                <a class="submenu-link <?= link_active(['items.php']) ?>" href="items.php">Products</a>
                            <?php endif; ?>
                            
<?php if(can_show('inventory_categories')): ?>
    <a class="submenu-link <?= link_active(['categories.php']) ?>" href="categories.php">Categories</a>
<?php endif; ?>

                            <?php if(can_show('services') || (is_services_industry_client() && can_show_any(['products','inventory','services']))): ?>
                                <a class="submenu-link <?= link_active(['services.php']) ?>" href="services.php">Services</a>
                            <?php endif; ?>

                            <?php if(can_show('item_variants')): ?>
                                <a class="submenu-link <?= link_active(['item_batches.php']) ?>" href="item_batches.php">Item Batches</a>
                            <?php endif; ?>

                            <?php if(can_show('inventory_adjustments')): ?>
                                <a class="submenu-link <?= link_active(['inventory.php']) ?>" href="inventory.php">Inventory Adj</a>
                            <?php endif; ?>

                            <?php if(can_show('barcode')): ?>
                                <a class="submenu-link <?= link_active(['add_barcode_pos.php']) ?>" href="add_barcode_pos.php">Barcode / QR</a>
                            <?php endif; ?>

                            <?php if(can_show('low_stock')): ?>
                                <a class="submenu-link <?= link_active(['low_stock.php']) ?>" href="low_stock.php">Low Stock</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ================= PRODUCTION / MANUFACTURING MODULE ================= -->
                <?php if($showProductionModule): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active($productionPages) ?>"
                       href="#productionMenu"
                       aria-expanded="<?= is_page($productionPages) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-gear-wide-connected menu-icon"></i>
                            <span class="menu-title">Production</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open($productionPages) ?>" id="productionMenu">
                        <div class="submenu">

                        
                            <?php if(can_show_exact('production_formulas')): ?>
                                <a class="submenu-link <?= link_active(['production_formulas.php','production_formula_save.php']) ?>" href="production_formulas.php">Formulas / BOM</a>
                            <?php endif; ?>

                            <?php if(can_show_exact('production_work_orders')): ?>
                                <a class="submenu-link <?= link_active(['production_work_orders.php','production_work_order_create.php','production_work_order_view.php','production_work_order_complete.php']) ?>" href="production_work_orders.php">Work Orders</a>
                            <?php endif; ?>

                            <?php if(can_show_exact('production_batches')): ?>
                                <a class="submenu-link <?= link_active(['production_batches.php']) ?>" href="production_batches.php">Batch / Job Tracking</a>
                            <?php endif; ?>

                            <?php if(can_show_exact('production_wastage')): ?>
                                <a class="submenu-link <?= link_active(['production_wastage.php']) ?>" href="production_wastage.php">Wastage / Scrap</a>
                            <?php endif; ?>

                            <?php if(can_show_exact('production_costing')): ?>
                                <a class="submenu-link <?= link_active(['production_costing.php']) ?>" href="production_costing.php">Costing</a>
                            <?php endif; ?>

                          

                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if(can_show_any(['customers','memberships','membership','customer_ledger'])): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['customers.php','customer_membership.php','membership.php','membership_card_template.php','customer_ledger.php','customer-membership-assign.php']) ?>"
                       href="#customerMenu"
                       aria-expanded="<?= is_page(['customers.php','customer_membership.php','membership.php','membership_card_template.php','customer_ledger.php','customer-membership-assign.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-person menu-icon"></i>
                            <span class="menu-title">Customers</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['customers.php','customer_membership.php','membership.php','membership_card_template.php','customer_ledger.php','customer-membership-assign.php']) ?>" id="customerMenu">
                        <div class="submenu">
                            <?php if(can_show('customers')): ?>
                                <a class="submenu-link <?= link_active(['customers.php']) ?>" href="customers.php">Customer List</a>
                            <?php endif; ?>

                            <?php if(can_show('memberships')): ?>
                                <a class="submenu-link <?= link_active(['customer-membership-assign.php']) ?>" href="customer-membership-assign.php">Memberships Assign</a>
                                <a class="submenu-link <?= link_active(['customer_membership.php']) ?>" href="customer_membership.php">Membership Customers</a>
                            <?php endif; ?>

                            <?php if(can_show('membership')): ?>
                                <a class="submenu-link <?= link_active(['membership.php']) ?>" href="membership.php">Add Membership</a>
                                <a class="submenu-link <?= link_active(['membership_card_template.php']) ?>" href="membership_card_template.php">Membership Card</a>
                            <?php endif; ?>

                            <?php if(can_show('customer_ledger')): ?>
                                <a class="submenu-link <?= link_active(['customer_ledger.php']) ?>" href="customer_ledger.php">Customer Ledger</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if(can_show_any(['suppliers','purchase_orders','supplier_ledger'])): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['suppliers.php','purchase_order.php','supplier_ledger.php']) ?>"
                       href="#supplierMenu"
                       aria-expanded="<?= is_page(['suppliers.php','purchase_order.php','supplier_ledger.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-people menu-icon"></i>
                            <span class="menu-title">Suppliers</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['suppliers.php','purchase_order.php','supplier_ledger.php']) ?>" id="supplierMenu">
                        <div class="submenu">
                            <?php if(can_show('suppliers')): ?>
                                <a class="submenu-link <?= link_active(['suppliers.php']) ?>" href="suppliers.php">Suppliers</a>
                            <?php endif; ?>

                            <?php if(can_show('purchase_orders')): ?>
                                <a class="submenu-link <?= link_active(['purchase_order.php']) ?>" href="purchase_order.php">Purchase Orders</a>
                            <?php endif; ?>

                            <?php if(can_show('supplier_ledger')): ?>
                                <a class="submenu-link <?= link_active(['supplier_ledger.php']) ?>" href="supplier_ledger.php">Supplier Ledger</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if(can_show_any(['users','attendance','commissions','appointments'])): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['staff.php','attendance.php','appointments.php','appointment.php','commissions_rates.php','staff_commissions.php','staff_attendance_report.php']) ?>"
                       href="#staffMenu"
                       aria-expanded="<?= is_page(['staff.php','attendance.php','appointments.php','appointment.php','commissions_rates.php','staff_commissions.php','staff_attendance_report.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-person-badge menu-icon"></i>
                            <span class="menu-title">Staff</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['staff.php','attendance.php','appointments.php','appointment.php','commissions_rates.php','staff_commissions.php','staff_attendance_report.php']) ?>" id="staffMenu">
                        <div class="submenu">
                            <?php if(can_show('users')): ?>
                                <a class="submenu-link <?= link_active(['staff.php']) ?>" href="staff.php">Users / Staff</a>
                            <?php endif; ?>

                            <?php if(can_show('attendance')): ?>
                                <a class="submenu-link <?= link_active(['attendance.php']) ?>" href="attendance.php">Staff Attendance</a>
                                <a class="submenu-link <?= link_active(['staff_attendance_report.php']) ?>" href="staff_attendance_report.php">Attendance Reports</a>
                            <?php endif; ?>

                            <?php if(can_show('appointments')): ?>
                                <a class="submenu-link <?= link_active(['appointments.php','appointment.php']) ?>" href="appointments.php">Appointments</a>
                            <?php endif; ?>

                            <?php if(can_show('commissions')): ?>
                                <a class="submenu-link <?= link_active(['commissions_rates.php']) ?>" href="commissions_rates.php">Commission Rates</a>
                                <a class="submenu-link <?= link_active(['staff_commissions.php']) ?>" href="staff_commissions.php">Staff Commissions</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if(can_show_any(['expenses','payments','taxes','tax_summary','profit_loss','products_report','best_selling_products'])): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active(['expenses.php','payments.php','taxes.php','tax_summary.php','profit_loss.php','products_report.php','best_selling_products.php']) ?>"
                       href="#financeMenu"
                       aria-expanded="<?= is_page(['expenses.php','payments.php','taxes.php','tax_summary.php','profit_loss.php','products_report.php','best_selling_products.php']) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-wallet2 menu-icon"></i>
                            <span class="menu-title">Finance</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open(['expenses.php','payments.php','taxes.php','tax_summary.php','profit_loss.php','products_report.php','best_selling_products.php']) ?>" id="financeMenu">
                        <div class="submenu">
                            <?php if(can_show('expenses')): ?>
                                <a class="submenu-link <?= link_active(['expenses.php']) ?>" href="expenses.php">Expenses</a>
                            <?php endif; ?>

                            <?php if(can_show('payments')): ?>
                                <a class="submenu-link <?= link_active(['payments.php']) ?>" href="payments.php">Payments</a>
                            <?php endif; ?>

                            <?php if(can_show('taxes')): ?>
                                <a class="submenu-link <?= link_active(['taxes.php']) ?>" href="taxes.php">Taxes</a>
                            <?php endif; ?>

                            <?php if(can_show('tax_summary')): ?>
                                <a class="submenu-link <?= link_active(['tax_summary.php']) ?>" href="tax_summary.php">Tax Summary</a>
                            <?php endif; ?>

                            <?php if(can_show('profit_loss')): ?>
                                <a class="submenu-link <?= link_active(['profit_loss.php']) ?>" href="profit_loss.php">Profit & Loss</a>
                            <?php endif; ?>

                            <?php if(can_show('products_report')): ?>
                                <a class="submenu-link <?= link_active(['products_report.php']) ?>" href="products_report.php">Products Report</a>
                            <?php endif; ?>

                            <?php if(can_show('best_selling_products')): ?>
                                <a class="submenu-link <?= link_active(['best_selling_products.php']) ?>" href="best_selling_products.php">Best Selling Products</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

             <?php
$businessFeaturePages = [
    'wholesale.php',
    'fbr.php',
    'branches.php',
    'mobile_access.php',
    'integrations.php',
    'client_settings.php'
];
?>

<?php if(can_show_any([
    'wholesale_module',
    'fbr_integration',
    'multi_branch',
    'mobile_access',
    'integrations',
    'client_settings'
])): ?>

<div class="menu-section">

    <a class="menu-link menu-toggle <?= parent_active($businessFeaturePages) ?>"
       href="#businessFeatureMenu"
       aria-expanded="<?= is_page($businessFeaturePages) ? 'true' : 'false' ?>">

        <div class="menu-main">
            <i class="bi bi-gear-wide-connected menu-icon"></i>
            <span class="menu-title">Business Features</span>
        </div>

        <i class="bi bi-chevron-down menu-arrow"></i>
    </a>

    <div class="submenu-collapse <?= menu_open($businessFeaturePages) ?>"
         id="businessFeatureMenu">

        <div class="submenu">

            <?php if(can_show('wholesale_module')): ?>
                <a class="submenu-link <?= link_active(['wholesale.php']) ?>"
                   href="wholesale.php">
                    Wholesale Module
                </a>
            <?php endif; ?>

            <?php if(can_show('fbr_integration')): ?>
                <a class="submenu-link <?= link_active(['fbr.php']) ?>"
                   href="fbr.php">
                    FBR Integration
                </a>
            <?php endif; ?>

            <?php if(can_show('multi_branch')): ?>
                <a class="submenu-link <?= link_active(['branches.php']) ?>"
                   href="branches.php">
                    Multi Branch
                </a>
            <?php endif; ?>

            <?php if(can_show('mobile_access')): ?>
                <a class="submenu-link <?= link_active(['mobile_access.php']) ?>"
                   href="mobile_access.php">
                    Mobile / Web Access
                </a>
            <?php endif; ?>

            <?php if(can_show('integrations')): ?>
                <a class="submenu-link <?= link_active(['integrations.php']) ?>"
                   href="integrations.php">
                    Integrations
                </a>
            <?php endif; ?>

            <?php if(can_show('client_settings')): ?>
                <a class="submenu-link <?= link_active(['client_settings.php']) ?>"
                   href="client_settings.php">
                    Settings / Configurations
                </a>
            <?php endif; ?>

        </div>
    </div>

</div>

<?php endif; ?>

                <!-- ================= WATER PLANT MODULE ================= -->
                <?php if($showWaterPlantModule): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active($waterPlantPages) ?>"
                       href="#waterPlantMenu"
                       aria-expanded="<?= is_page($waterPlantPages) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-droplet-half menu-icon"></i>
                            <span class="menu-title">Water Plant</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open($waterPlantPages) ?>" id="waterPlantMenu">
                        <div class="submenu">

                            <?php if(can_show('empty_bottles')): ?>
                                <a class="submenu-link <?= link_active(['empty_bottles.php']) ?>" href="empty_bottles.php">Empty Bottles</a>
                            <?php endif; ?>

                            <?php if(can_show('wp_delivery_routes')): ?>
                                <a class="submenu-link <?= link_active(['routes_management.php']) ?>" href="routes_management.php">Delivery Routes</a>
                            <?php endif; ?>

                            <?php if(can_show('wp_dispatch')): ?>
                                <a class="submenu-link <?= link_active(['dispatch.php']) ?>" href="dispatch.php">Dispatch</a>
                            <?php endif; ?>

                            <?php if(can_show('wp_delivery_assign') || can_show('wp_distribution_operation')): ?>
                                <a class="submenu-link <?= link_active(['distribution_operation.php','delivery_assign.php']) ?>" href="distribution_operation.php">Delivery Assign</a>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ================= DISTRIBUTION MODULE ================= -->
                <?php if($showDistributionModule): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active($distributionPages) ?>"
                       href="#distributionMenu"
                       aria-expanded="<?= is_page($distributionPages) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-truck menu-icon"></i>
                            <span class="menu-title">Distribution</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open($distributionPages) ?>" id="distributionMenu">
                        <div class="submenu">

                         

                            <?php if(can_show('distribution') || can_show('dist_routes')): ?>
                                <a class="submenu-link <?= link_active(['routes_management.php','distribution-routes.php']) ?>" href="routes_management.php">
                                    Routes
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('distribution') || can_show('dist_drivers')): ?>
                                <a class="submenu-link <?= link_active(['rider_management.php']) ?>" href="rider_management.php">
                                    Drivers / Riders
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('distribution') || can_show('dist_delivery_assign') || can_show('dist_distribution_operation')): ?>
                                <a class="submenu-link <?= link_active(['distribution_operation.php','distribution-operation.php','delivery_assign.php']) ?>" href="distribution_operation.php">
                                    Delivery Assign
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('distribution') || can_show('dist_orders')): ?>
                                <a class="submenu-link <?= link_active(['distribution_orders.php','distribution-orders.php']) ?>" href="distribution_orders.php">
                                    Orders
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('distribution') || can_show('dist_dispatch')): ?>
                                <a class="submenu-link <?= link_active(['dispatch.php','distribution-dispatch.php']) ?>" href="dispatch.php">
                                    Dispatch
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('distribution') || can_show('dist_delivery_update')): ?>
                                <a class="submenu-link <?= link_active(['delivery_update.php','distribution-delivery-update.php']) ?>" href="delivery_update.php">
                                    Delivery Update
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('distribution') || can_show('dist_collections')): ?>
                                <a class="submenu-link <?= link_active(['distribution_collections.php','distribution-collections.php']) ?>" href="distribution_collections.php">
                                    Collections
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('distribution') || can_show('dist_reports')): ?>
                                <a class="submenu-link <?= link_active(['reports.php','distribution-reports.php']) ?>" href="reports.php">
                                    Reports
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ================= STATE AGENCY / REAL ESTATE MODULE ================= -->
                <?php if($showRealEstateModule): ?>
                <div class="menu-section">
                    <a class="menu-link menu-toggle <?= parent_active($realEstatePages) ?>"
                       href="#realEstateMenu"
                       aria-expanded="<?= is_page($realEstatePages) ? 'true' : 'false' ?>">
                        <div class="menu-main">
                            <i class="bi bi-buildings-fill menu-icon"></i>
                            <span class="menu-title">State Agency</span>
                        </div>
                        <i class="bi bi-chevron-down menu-arrow"></i>
                    </a>

                    <div class="submenu-collapse <?= menu_open($realEstatePages) ?>" id="realEstateMenu">
                        <div class="submenu">



                            <?php if(can_show('real_estate') || can_show('re_agency_leads')): ?>
                                <a class="submenu-link <?= link_active(['agency-lead.php','agency-lead.html']) ?>" href="agency-lead.php">
                                    Agency Leads
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('real_estate') || can_show('re_followups')): ?>
                                <a class="submenu-link <?= link_active(['follow-ups.php']) ?>" href="follow-ups.php">
                                    Follow Ups
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('real_estate') || can_show('re_site_visits')): ?>
                                <a class="submenu-link <?= link_active(['site-visit.php','site-visit.html']) ?>" href="site-visit.php">
                                    Site Visits
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('real_estate') || can_show('re_properties')): ?>
                                <a class="submenu-link <?= link_active(['properties.php','properties.html']) ?>" href="properties.php">
                                    Properties
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('real_estate') || can_show('re_deals')): ?>
                                <a class="submenu-link <?= link_active(['deals.php','deals.html']) ?>" href="deals.php">
                                    Deals / Conversions
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('real_estate') || can_show('re_agent_assignment')): ?>
                                <a class="submenu-link <?= link_active(['agent-assignment.php','agent_assignment.php','agent-assignment.html','agent_assignment.html']) ?>" href="agent-assignment.php">
                                    Agent Assignment
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('real_estate') || can_show('re_agent_commissions')): ?>
                                <a class="submenu-link <?= link_active(['agent-commission.php','agent-commissions.php','agent_commissions.php','agent-commission.html','agent-commissions.html']) ?>" href="agent-commissions.php">
                                    Agent Commissions
                                </a>
                            <?php endif; ?>

                            <?php if(can_show('real_estate') || can_show('re_reports')): ?>
                                <a class="submenu-link <?= link_active(['report.php','report.html']) ?>" href="report.php">
                                    Reports
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>

            <div class="sidebar-divider"></div>

            <div class="menu-section">
                <a class="menu-link logout-link" href="/onerp/app/app/auth/logout.php">
                    <div class="menu-main">
                        <i class="bi bi-power menu-icon"></i>
                        <span class="menu-title">Logout</span>
                    </div>
                </a>
            </div>

            <?php if(!$is_superadmin): ?>
            <div class="menu-section">
                <a class="menu-link support-link" href="https://wa.me/923001234567" target="_blank">
                    <div class="menu-main">
                        <i class="bi bi-headset menu-icon"></i>
                        <span class="menu-title">Support</span>
                    </div>
                </a>
            </div>
            <?php endif; ?>

        </div>

        <?php if(!$is_superadmin && can_show('customers')): ?>
        <div class="bottom-area">
            <div class="sidebar-divider"></div>

            <a href="customers.php" class="new-customer-btn">
                <i class="bi bi-plus-lg"></i>
                <span>New Customer</span>
            </a>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggles = document.querySelectorAll('.menu-toggle');

    function closeMenu(toggle, target) {
        toggle.setAttribute('aria-expanded', 'false');
        target.classList.remove('show');
        target.style.maxHeight = '0px';
    }

    function openMenu(toggle, target) {
        toggle.setAttribute('aria-expanded', 'true');
        target.classList.add('show');
        target.style.maxHeight = target.scrollHeight + 'px';
    }

    toggles.forEach(toggle => {
        const targetSelector = toggle.getAttribute('href');
        const target = document.querySelector(targetSelector);

        if (!target) return;

        if (target.classList.contains('show')) {
            target.style.maxHeight = target.scrollHeight + 'px';
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            target.style.maxHeight = '0px';
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();

            const isOpen = target.classList.contains('show');

            if (isOpen) {
                closeMenu(toggle, target);
                return;
            }

            toggles.forEach(otherToggle => {
                const otherTargetSelector = otherToggle.getAttribute('href');
                const otherTarget = document.querySelector(otherTargetSelector);

                if (!otherTarget) return;
                closeMenu(otherToggle, otherTarget);
            });

            openMenu(toggle, target);
        });
    });

    window.addEventListener('resize', function () {
        document.querySelectorAll('.submenu-collapse.show').forEach(menu => {
            menu.style.maxHeight = menu.scrollHeight + 'px';
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /*
      Sidebar auto close + responsive toggle fix
      - Desktop: sidebar collapse/open
      - Mobile: sidebar drawer open/close
      - Mobile outside click: auto close
      - Mobile menu page link click: auto close
      - Dropdown/menu-toggle click: sidebar close nahi hoga, sirf submenu open/close hoga
      - Duplicate binding protection: topbar/head.php scripts ke sath double toggle nahi hoga
    */

    const sidebarToggleSelector = [
        '.pos-sidebar-toggle',
        '.navbar-toggler[data-toggle="minimize"]',
        '.navbar-toggler[data-toggle="offcanvas"]',
        '#sidebarToggle',
        '#sidebar-toggle',
        '.sidebar-toggle'
    ].join(', ');

    const sidebarAreaSelector = [
        '.sidebar-wrap',
        '.pos-sidebar',
        '.container-scroll > .sidebar'
    ].join(', ');

    const sidebarToggles = document.querySelectorAll(sidebarToggleSelector);

    function isMobileSidebar() {
        return window.innerWidth <= 991;
    }
    
    /* ✅ By default desktop par sidebar collapsed rahega */
if (!isMobileSidebar()) {
    document.body.classList.add('sidebar-collapsed');
} else {
    document.body.classList.remove('sidebar-open');
}

    function openSidebarMobile() {
        document.body.classList.add('sidebar-open');
        document.body.classList.remove('sidebar-collapsed');
    }

    function closeSidebarMobile() {
        document.body.classList.remove('sidebar-open');
    }

    function toggleSidebarMobile() {
        if (document.body.classList.contains('sidebar-open')) {
            closeSidebarMobile();
        } else {
            openSidebarMobile();
        }
    }

    function toggleSidebarDesktop() {
        document.body.classList.toggle('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
    }

    sidebarToggles.forEach(function (btn) {
        if (btn.dataset.sidebarAutoCloseBound === '1') return;
        btn.dataset.sidebarAutoCloseBound = '1';

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            if (isMobileSidebar()) {
                toggleSidebarMobile();
            } else {
                toggleSidebarDesktop();
            }
        });
    });

    document.addEventListener('click', function (e) {
        if (!isMobileSidebar()) return;
        if (!document.body.classList.contains('sidebar-open')) return;

        const clickedInsideSidebar = e.target.closest(sidebarAreaSelector);
        const clickedToggleButton = e.target.closest(sidebarToggleSelector);

        if (!clickedInsideSidebar && !clickedToggleButton) {
            closeSidebarMobile();
        }
    });

    document.querySelectorAll('.sidebar-wrap a').forEach(function (link) {
        if (link.dataset.sidebarLinkAutoCloseBound === '1') return;
        link.dataset.sidebarLinkAutoCloseBound = '1';

        link.addEventListener('click', function () {
            if (!isMobileSidebar()) return;

            // Parent dropdown par click se sidebar close nahi karna.
            if (link.classList.contains('menu-toggle')) return;

            closeSidebarMobile();
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isMobileSidebar()) {
            closeSidebarMobile();
        }
    });

    window.addEventListener('resize', function () {
        if (isMobileSidebar()) {
            document.body.classList.remove('sidebar-collapsed');
        } else {
            document.body.classList.remove('sidebar-open');
        }
    });
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | Collapsed Sidebar Floating Module Menu
    |--------------------------------------------------------------------------
    | Desktop collapsed sidebar mein module icon click karne par floating
    | submenu box open hoga. Expanded sidebar ka existing workflow same rahega.
    |--------------------------------------------------------------------------
    */

    let activeCollapsedToggle = null;

    const flyout = document.createElement('div');

    flyout.id = 'collapsedSidebarFlyout';
    flyout.className = 'collapsed-sidebar-flyout';

    flyout.innerHTML = `
        <div class="collapsed-flyout-title">
            <i class="bi bi-grid"></i>
            <span>Module</span>
        </div>

        <div class="collapsed-flyout-links"></div>
    `;

    document.body.appendChild(flyout);


    function isDesktopSidebar() {
        return window.innerWidth >= 992;
    }


    function isSidebarCollapsed() {
        return (
            document.body.classList.contains(
                'sidebar-collapsed'
            ) ||
            document.body.classList.contains(
                'dashboard-sidebar-collapsed'
            )
        );
    }


    function closeCollapsedFlyout() {

        flyout.classList.remove('show');

        if (activeCollapsedToggle) {

            activeCollapsedToggle.classList.remove(
                'collapsed-flyout-open'
            );

            activeCollapsedToggle = null;
        }
    }


    function positionCollapsedFlyout(toggle) {

        const toggleRect =
            toggle.getBoundingClientRect();

        const flyoutWidth = 260;
        const pageGap = 10;
        const screenPadding = 12;

        /*
        |--------------------------------------------------------------------------
        | Sidebar icon ke right side box open hoga
        |--------------------------------------------------------------------------
        */
        let left =
            toggleRect.right + pageGap;

        /*
        |--------------------------------------------------------------------------
        | Screen ke right se bahar ja raha ho to left side open karo
        |--------------------------------------------------------------------------
        */
        if (
            left + flyoutWidth >
            window.innerWidth - screenPadding
        ) {
            left =
                toggleRect.left -
                flyoutWidth -
                pageGap;
        }

        left = Math.max(
            screenPadding,
            left
        );

        flyout.style.left = left + 'px';

        /*
        |--------------------------------------------------------------------------
        | Pehle temporary visible karke actual height calculate karo
        |--------------------------------------------------------------------------
        */
        flyout.style.top = toggleRect.top + 'px';
        flyout.style.visibility = 'hidden';
        flyout.style.display = 'block';

        const flyoutHeight =
            flyout.offsetHeight;

        let top = toggleRect.top;

        if (
            top + flyoutHeight >
            window.innerHeight - screenPadding
        ) {
            top =
                window.innerHeight -
                flyoutHeight -
                screenPadding;
        }

        top = Math.max(
            screenPadding,
            top
        );

        flyout.style.top = top + 'px';
        flyout.style.visibility = '';
        flyout.style.display = '';
    }


    function openCollapsedFlyout(toggle) {

        const targetSelector =
            toggle.getAttribute('href');

        if (
            !targetSelector ||
            !targetSelector.startsWith('#')
        ) {
            return;
        }

        const originalSubmenu =
            document.querySelector(
                targetSelector
            );

        if (!originalSubmenu) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Same icon dobara click ho to box close
        |--------------------------------------------------------------------------
        */
        if (
            activeCollapsedToggle === toggle &&
            flyout.classList.contains('show')
        ) {
            closeCollapsedFlyout();
            return;
        }

        closeCollapsedFlyout();

        const menuTitle =
            (
                toggle.querySelector(
                    '.menu-title'
                )?.textContent || 'Module'
            ).trim();

        const originalIcon =
            toggle.querySelector(
                '.menu-icon'
            );

        const flyoutTitle =
            flyout.querySelector(
                '.collapsed-flyout-title'
            );

        const titleIcon =
            flyoutTitle.querySelector('i');

        const titleText =
            flyoutTitle.querySelector('span');

        titleText.textContent =
            menuTitle;

        /*
        |--------------------------------------------------------------------------
        | Parent module wala same icon flyout title mein
        |--------------------------------------------------------------------------
        */
        if (originalIcon) {

            titleIcon.className =
                originalIcon.className
                    .replace('menu-icon', '')
                    .trim();

        } else {

            titleIcon.className =
                'bi bi-grid';
        }

        const linksContainer =
            flyout.querySelector(
                '.collapsed-flyout-links'
            );

        linksContainer.innerHTML = '';

        const submenuLinks =
            originalSubmenu.querySelectorAll(
                '.submenu-link'
            );

        submenuLinks.forEach(function (originalLink) {

            const clonedLink =
                document.createElement('a');

            clonedLink.className =
                'collapsed-flyout-link';

            if (
                originalLink.classList.contains(
                    'active'
                )
            ) {
                clonedLink.classList.add(
                    'active'
                );
            }

            clonedLink.href =
                originalLink.getAttribute('href') || '#';

            clonedLink.textContent =
                (
                    originalLink.textContent || ''
                ).trim();

            /*
            |--------------------------------------------------------------------------
            | target="_blank" ho to preserve karo
            |--------------------------------------------------------------------------
            */
            if (
                originalLink.hasAttribute('target')
            ) {
                clonedLink.setAttribute(
                    'target',
                    originalLink.getAttribute(
                        'target'
                    )
                );
            }

            linksContainer.appendChild(
                clonedLink
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Empty submenu safety
        |--------------------------------------------------------------------------
        */
        if (submenuLinks.length === 0) {

            const emptyMessage =
                document.createElement('div');

            emptyMessage.className =
                'collapsed-flyout-link';

            emptyMessage.textContent =
                'No pages available';

            linksContainer.appendChild(
                emptyMessage
            );
        }

        activeCollapsedToggle = toggle;

        toggle.classList.add(
            'collapsed-flyout-open'
        );

        positionCollapsedFlyout(toggle);

        requestAnimationFrame(function () {
            flyout.classList.add('show');
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Capture phase use ki gayi hai taake existing submenu click handler
    | collapsed mode mein is click ko intercept na kare.
    |--------------------------------------------------------------------------
    */
    document.addEventListener(
        'click',
        function (event) {

            const clickedToggle =
                event.target.closest(
                    '.menu-toggle'
                );

            /*
            |--------------------------------------------------------------------------
            | Flyout ke page link par click
            |--------------------------------------------------------------------------
            */
            if (
                event.target.closest(
                    '#collapsedSidebarFlyout'
                )
            ) {

                const clickedFlyoutLink =
                    event.target.closest(
                        '.collapsed-flyout-link[href]'
                    );

                if (clickedFlyoutLink) {
                    closeCollapsedFlyout();
                }

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Sirf desktop collapsed sidebar mein custom box
            |--------------------------------------------------------------------------
            */
            if (
                clickedToggle &&
                isDesktopSidebar() &&
                isSidebarCollapsed()
            ) {

                event.preventDefault();
                event.stopPropagation();

                openCollapsedFlyout(
                    clickedToggle
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Box ke bahar click hone par close
            |--------------------------------------------------------------------------
            */
            if (
                flyout.classList.contains('show')
            ) {
                closeCollapsedFlyout();
            }

        },
        true
    );


    /*
    |--------------------------------------------------------------------------
    | Escape key
    |--------------------------------------------------------------------------
    */
    document.addEventListener(
        'keydown',
        function (event) {

            if (event.key === 'Escape') {
                closeCollapsedFlyout();
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Window resize / page scroll par close
    |--------------------------------------------------------------------------
    */
    window.addEventListener(
        'resize',
        closeCollapsedFlyout
    );

    window.addEventListener(
        'scroll',
        closeCollapsedFlyout,
        true
    );


    /*
    |--------------------------------------------------------------------------
    | Sidebar open hone par floating box close
    |--------------------------------------------------------------------------
    */
    const bodyObserver =
        new MutationObserver(function () {

            if (!isSidebarCollapsed()) {
                closeCollapsedFlyout();
            }
        });

    bodyObserver.observe(
        document.body,
        {
            attributes:true,
            attributeFilter:['class']
        }
    );

});
</script>

