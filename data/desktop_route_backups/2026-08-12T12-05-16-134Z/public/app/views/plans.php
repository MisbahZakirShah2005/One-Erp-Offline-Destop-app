<?php
session_start();

/* ================== DEBUG TEMPORARY ==================
   Live error 500 fix ke baad in 3 lines ko production me comment/remove kar dena.
====================================================== */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================== DB CONNECTION FIX ================== */
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Please check config/config.php and make sure it creates mysqli variable named \$conn.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ================== AUTH CHECK ================== */
// if (!isset($_SESSION['user_id'])) {
//     die('User not logged in!');
// }
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$logged_role_id = $_SESSION['role_id'] ?? 0;

if ((int)$logged_role_id !== 1) {
    die('Access denied!');
}

/* ================== HELPERS ================== */
function normalize_industry_key($industry)
{
    $industry = strtolower(trim((string)$industry));
    $industry = str_replace(['-', ' '], '_', $industry);

    $map = [
        'retailer' => 'retail',
        'retail' => 'retail',
        'services' => 'services',
        'service' => 'services',
        'restaurant' => 'restaurant',
        'restaurants' => 'restaurant',
        'resturent' => 'restaurant',
        'resturant' => 'restaurant',
        'water_plant' => 'water_plant',
        'distribution' => 'distribution',
        'delivery' => 'distribution',
        'delivery_business' => 'distribution',
        'distribution_business' => 'distribution',
        'real_estate' => 'real_estate',
        'state_agency' => 'state_agency',
        'agency' => 'agency',

        // Pharmacy / OPD
        'pharmacy' => 'pharmacy',
        'clinic' => 'opd',
        'healthcare' => 'opd',
        'hospital' => 'opd',
        'opd' => 'opd',
    ];

    return $map[$industry] ?? $industry;
}

function clean_billing_cycle($cycle)
{
    $cycle = strtolower(trim((string)$cycle));

    if ($cycle === 'yearly' || $cycle === 'year' || $cycle === 'annual' || $cycle === 'annually') {
        return 'yearly';
    }

    return 'monthly';
}

function pretty_industry_label($industry)
{
    return ucwords(str_replace('_', ' ', normalize_industry_key($industry)));
}

/* ================== FULL POS FEATURES ================== */
$all_features = [
    // Core
    'dashboard',
    'invoices',
    'pos_sales',
    'inventory',
    'inventory_adjustments',
    'customers',
    'inventory_categories',
    'suppliers',
    'users',
    'reports',
    'wholesale_module',
    'fbr_integration',
    'tables',
    'reservations',
    'recipes',
    'memberships',
    'appointments',
    'attendance',
    'commissions',
    'supplier_management',
    'supplier_ledger',
    'customer_ledger',
    'profit_loss',
    'sale_reports',
    'cashier_shift',
    'low_stock',
    'tax_summary',
    'discounts',
    'taxes',
    'multi_branch',
    'membership',
    'products_report',
'best_selling_products',
    'payments',
    'expenses',
    'returns',
    'notifications',
    'settings',
    'integrations',

    // Shopify Integration
    'shopify_integration',
    'shopify_products',
    'shopify_inventory_sync',
    'shopify_sync_logs',

    // WooCommerce Integration
    'woocommerce_integration',
    'woocommerce_products',
    'woocommerce_orders',
    'woocommerce_inventory_sync',
    'woocommerce_sync_logs',

    'barcode',
    'mobile_access',
    'products',
    'services',
    'item_variants',
    'quotations',

    // Restaurant Specific
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
    'restaurant_settings',

    // Generic Production / Manufacturing Module
    'production',
    'production_formulas',
    'production_work_orders',
    'production_batches',
    'production_wastage',
    'production_costing',
    'production_reports',

    // Water Plant Specific
    'subscriptions',
    'water_delivery',
    'wp_delivery_routes',
    'wp_dispatch',
    'wp_delivery_assign',
    'wp_distribution_operation',
    'empty_bottles',

    // Distribution Specific
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
    'dist_reports',

    // Real Estate / Agency Specific
    'real_estate',
    're_dashboard',
    're_agency_leads',
    're_followups',
    're_site_visits',
    're_properties',
    're_deals',
    're_agent_assignment',
    're_agent_commissions',
    're_reports',

    // OPD Management
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
];

$all_features = array_values(array_unique($all_features));

/* ================== FEATURE LABELS ================== */
$feature_labels = [
    // Core
    'dashboard' => 'Dashboard Access',
    'invoices' => 'Invoices / Billing',
    'pos_sales' => 'POS Sales',
    'inventory' => 'Products / Inventory',
    'inventory_categories' => 'Inventory Categories',
    'inventory_adjustments' => 'Inventory Adjustments',
    'customers' => 'Customers',
    'suppliers' => 'Suppliers / Purchase Orders',
    'users' => 'Users / Staff',
    'reports' => 'Reports / Analytics',
    'wholesale_module' => 'Wholesale Module',
    'fbr_integration' => 'FBR Integration',
    'tables' => 'Restaurant Tables',
    'reservations' => 'Table Reservations',
    'recipes' => 'Recipes Management',
    'memberships' => 'Customer Memberships',
    'appointments' => 'Appointments',
    'attendance' => 'Staff Attendance',
    'commissions' => 'Commissions',
    'supplier_management' => 'Supplier Management',
    'supplier_ledger' => 'Supplier Ledger',
    'customer_ledger' => 'Customer Ledger',
    'profit_loss' => 'Profit & Loss',
    'sale_reports' => 'Sale Reports',
    'cashier_shift' => 'Cashier Shift',
    'low_stock' => 'Low Stock',
    'tax_summary' => 'Tax Summary',
    'discounts' => 'Discounts / Coupons',
    'taxes' => 'Taxes / VAT',
    'multi_branch' => 'Multi-Branch',
    'membership' => 'Membership / Subscription',
    'products_report' => 'Products Report',
'best_selling_products' => 'Best Selling Products',
    'payments' => 'Payments / Gateways',
    'expenses' => 'Expenses / Costs',
    'returns' => 'Returns / Refunds',
    'notifications' => 'Notifications',
    'settings' => 'Settings / Configurations',
    'integrations' => 'Integrations',

    // Shopify Integration
    'shopify_integration' => 'Shopify Store Connection',
    'shopify_products' => 'Shopify Product Sync',
    'shopify_inventory_sync' => 'Shopify Inventory Sync',
    'shopify_sync_logs' => 'Shopify Sync Logs',

    // WooCommerce Integration
    'woocommerce_integration' => 'WooCommerce Store Connection',
    'woocommerce_products' => 'WooCommerce Product Sync',
    'woocommerce_orders' => 'WooCommerce Orders Pull',
    'woocommerce_inventory_sync' => 'WooCommerce Inventory Sync',
    'woocommerce_sync_logs' => 'WooCommerce Sync Logs',

    'barcode' => 'Barcode / QR',
    'mobile_access' => 'Mobile / Web Access',
    'products' => 'Products / Items',
    'services' => 'Services',
    'item_variants' => 'Item Variants',
    'quotations' => 'Quotations',

    // Restaurant Specific
    'restaurant' => 'Restaurant Module',
    'restaurant_bookings' => 'Restaurant Bookings / Reservations',
    'restaurant_tables' => 'Restaurant Table Management',
    'restaurant_pos' => 'Restaurant Table POS',
    'restaurant_orders' => 'Running Table Orders',
    'restaurant_kot' => 'KOT / Kitchen Order Ticket',
    'restaurant_kitchen' => 'Kitchen Display',
    'restaurant_waiters' => 'Waiters Management',
    'restaurant_bill_print' => 'Restaurant Bill Print',
    'restaurant_payments' => 'Restaurant Payments',
    'restaurant_table_transfer' => 'Table Transfer',
    'restaurant_table_merge' => 'Table Merge',
    'restaurant_split_bill' => 'Split Bill',
    'restaurant_reports' => 'Restaurant Reports',
    'restaurant_settings' => 'Restaurant Settings',

    // Generic Production / Manufacturing Module
    'production' => 'Production Module',
    'production_formulas' => 'Formulas / BOM / Recipes',
    'production_work_orders' => 'Work Orders / Production Orders',
    'production_batches' => 'Batch / Job Tracking',
    'production_wastage' => 'Wastage / Scrap',
    'production_costing' => 'Production Costing',
    'production_reports' => 'Production Reports',

    // Water Plant Specific
    'subscriptions' => 'Water Subscription',
    'water_delivery' => 'Water Delivery',
    'wp_delivery_routes' => 'Water Delivery Routes',
    'wp_dispatch' => 'Water Dispatch',
    'wp_delivery_assign' => 'Water Delivery Assign',
    'wp_distribution_operation' => 'Water Distribution Operation',
    'empty_bottles' => 'Empty Bottle Tracking',

    // Distribution Specific
    'distribution' => 'Distribution Module',
    'dist_dashboard' => 'Distribution Dashboard',
    'dist_routes' => 'Distribution Routes',
    'dist_drivers' => 'Distribution Drivers',
    'dist_dispatch' => 'Distribution Dispatch',
    'dist_delivery_assign' => 'Distribution Delivery Assign',
    'dist_distribution_operation' => 'Distribution Operation',
    'dist_orders' => 'Distribution Orders',
    'dist_delivery_update' => 'Distribution Delivery Update',
    'dist_collections' => 'Distribution Collections',
    'dist_reports' => 'Distribution Reports',

    // Real Estate / Agency Specific
    'real_estate' => 'Real Estate / Agency Module',
    're_dashboard' => 'Real Estate Dashboard',
    're_agency_leads' => 'Agency Leads',
    're_followups' => 'Lead Follow Ups',
    're_site_visits' => 'Site Visits',
    're_properties' => 'Properties Management',
    're_deals' => 'Deals / Conversions',
    're_agent_assignment' => 'Agent Assignment',
    're_agent_commissions' => 'Agent Commissions',
    're_reports' => 'Real Estate Reports',

    // OPD Management
    'opd' => 'OPD Management Module',
    'opd_dashboard' => 'OPD Dashboard',
    'opd_patients' => 'OPD Patients',
    'opd_departments' => 'OPD Departments',
    'opd_doctors' => 'OPD Doctors',
    'opd_reception' => 'OPD Reception',
    'opd_tokens' => 'OPD Token Management',
    'opd_billing' => 'OPD Fee Collection',
    'opd_vitals' => 'OPD Vitals / Triage',
    'opd_queue' => 'OPD Doctor Queue',
    'opd_reports' => 'OPD Reports',
    'opd_settings' => 'OPD Settings'
];

/* ================== INDUSTRY CORE FEATURES ================== */
$industry_core_features = [
    'restaurant' => [
        'dashboard',
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
        'restaurant_reports',

        // Existing POS features reuse
        'pos_sales',
        'invoices',
        'inventory',
        'customers',
        'users',
        'reports',
        'tables',
        'reservations',
        'recipes',
        'payments',
        'taxes',
        'products_report',
'best_selling_products',
        'discounts',
        'quotations'
        
    ],

    'retail' => [
        'dashboard',
        'pos_sales',
        'invoices',
        'inventory',
        'customers',
        'products',
        'item_variants',
        'users',
        'products_report',
'best_selling_products',
        'reports',
        'quotations'
    ],

    'services' => [
        'dashboard',
        'invoices',
        'customers',
        'users',
        'payments',
        'reports',
        'appointments',
        'products_report',
'best_selling_products',
        'services',
        'settings'
    ],

    'water_plant' => [
        'dashboard',
        'pos_sales',
        'invoices',
        'customers',
        'payments',
        'reports',
        'users',
        'sale_reports',
        'customer_ledger',
        'subscriptions',
        'water_delivery',
        'wp_delivery_routes',
        'wp_dispatch',
        'wp_delivery_assign',
        'wp_distribution_operation',
        'empty_bottles'
    ],

    'distribution' => [
        'dashboard',
        'pos_sales',
        'invoices',
        'inventory',
        'customers',
        'products',
        'item_variants',
        'users',
        'payments',
        'reports',
        'sale_reports',
        'customer_ledger',
        'returns',
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
    ],

    'pharmacy' => [
        'dashboard',
        'pos_sales',
        'invoices',
        'inventory',
        'inventory_categories',
        'customers',
        'products',
        'item_variants',
        'users',
        'reports',

        // OPD Management
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
    ],

    'opd' => [
        'dashboard',
        'users',

        // OPD Management
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
    ],

    'real_estate' => [
        'dashboard',
        'customers',
        'users',
        'payments',
        'reports',
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
    ],

    'state_agency' => [
        'dashboard',
        'customers',
        'users',
        'payments',
        'reports',
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
    ],

    'agency' => [
        'dashboard',
        'customers',
        'users',
        'payments',
        'reports',
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
    ]
];


/* ================== GENERIC INVENTORY CATEGORY FEATURE FOR ALL INDUSTRIES ================== */
foreach ($industry_core_features as $industryKey => $features) {
    if (!in_array('inventory_categories', $industry_core_features[$industryKey], true)) {
        $industry_core_features[$industryKey][] = 'inventory_categories';
    }
}

/* ================== GENERIC PRODUCTION FEATURES FOR ALL INDUSTRIES ================== */
$production_features = [
    'production',
    'production_formulas',
    'production_work_orders',
    'production_batches',
    'production_wastage',
    'production_costing',
    'products_report',
'best_selling_products',
    'production_reports'
];

foreach ($industry_core_features as $industryKey => $features) {
    foreach ($production_features as $pf) {
        if (!in_array($pf, $industry_core_features[$industryKey], true)) {
            $industry_core_features[$industryKey][] = $pf;
        }
    }
}

/* ================== AJAX SAVE PLAN ================== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'save_plan') {
    header('Content-Type: text/plain; charset=utf-8');

    $plan_id       = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
    $name          = trim($_POST['name'] ?? '');
    $price         = isset($_POST['price']) ? (float)$_POST['price'] : 0;
    $billing_cycle = clean_billing_cycle($_POST['billing_cycle'] ?? 'monthly');
    $industry      = normalize_industry_key($_POST['industry'] ?? '');
    $features      = $_POST['features'] ?? [];

    if ($name === '') {
        echo 'Error: Plan name is required.';
        exit;
    }

    if ($industry === '') {
        echo 'Error: Industry is required.';
        exit;
    }

    if (!is_array($features)) {
        $features = [];
    }

    $features = array_values(array_unique(array_filter(array_map('trim', $features))));

    try {
        $conn->begin_transaction();

        if ($plan_id > 0) {
            $stmt = $conn->prepare('UPDATE plans SET name = ?, price = ?, billing_cycle = ?, industry = ? WHERE id = ?');
            $stmt->bind_param('sdssi', $name, $price, $billing_cycle, $industry, $plan_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('DELETE FROM plan_features WHERE plan_id = ?');
            $stmt->bind_param('i', $plan_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO plans (name, price, billing_cycle, industry, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->bind_param('sdss', $name, $price, $billing_cycle, $industry);
            $stmt->execute();
            $plan_id = $stmt->insert_id;
            $stmt->close();
        }

        if (!empty($features)) {
            $stmt = $conn->prepare('
                INSERT INTO plan_features (plan_id, feature_key, enabled)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)
            ');

            foreach ($features as $f) {
                $stmt->bind_param('is', $plan_id, $f);
                $stmt->execute();
            }

            $stmt->close();
        }

        $conn->commit();
        echo 'ok';
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // ignore rollback error
        }

        echo 'Error: ' . $e->getMessage();
    }

    exit;
}

/* ================== AJAX DELETE PLAN ================== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete_plan') {
    header('Content-Type: text/plain; charset=utf-8');

    $plan_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($plan_id <= 0) {
        echo 'Error: Invalid plan id.';
        exit;
    }

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare('DELETE FROM plan_features WHERE plan_id = ?');
        $stmt->bind_param('i', $plan_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM plans WHERE id = ?');
        $stmt->bind_param('i', $plan_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        echo 'ok';
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // ignore rollback error
        }

        echo 'Error: ' . $e->getMessage();
    }

    exit;
}

/* ================== AJAX FETCH PLAN FEATURES ================== */
if (isset($_GET['plan_id'])) {
    header('Content-Type: application/json; charset=utf-8');

    $plan_id = (int)$_GET['plan_id'];
    $features = [];

    try {
        $stmt = $conn->prepare('SELECT feature_key FROM plan_features WHERE plan_id = ? AND enabled = 1');
        $stmt->bind_param('i', $plan_id);
        $stmt->execute();

        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            $features[] = $r['feature_key'];
        }

        $stmt->close();
        echo json_encode($features);
    } catch (Throwable $e) {
        echo json_encode([]);
    }

    exit;
}

/* ================== FETCH PLANS ================== */
$plans = [];

try {
    $res = $conn->query('SELECT * FROM plans ORDER BY id DESC');

    while ($r = $res->fetch_assoc()) {
        $r['industry'] = normalize_industry_key($r['industry'] ?? '');
        $r['billing_cycle'] = clean_billing_cycle($r['billing_cycle'] ?? 'monthly');
        $plans[] = $r;
    }
} catch (Throwable $e) {
    die('Plans fetch error: ' . $e->getMessage());
}

/* ================== FETCH INDUSTRIES ================== */
$industries = [];

try {
    $ind_res = $conn->query('SELECT name FROM industries ORDER BY name ASC');

    while ($ind = $ind_res->fetch_assoc()) {
        $key = normalize_industry_key($ind['name']);
        if ($key !== '' && !in_array($key, $industries, true)) {
            $industries[] = $key;
        }
    }
} catch (Throwable $e) {
    $industries = [];
}

$fallback_industries = [
    'retail',
    'restaurant',
    'services',
    'water_plant',
    'distribution',
    'pharmacy',
    'opd',
    'real_estate',
    'state_agency',
    'agency'
];

foreach ($fallback_industries as $fallback) {
    if (!in_array($fallback, $industries, true)) {
        $industries[] = $fallback;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Plans - SaaS POS</title>

<?php include('partials/head.php'); ?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
html,
body.admin-dashboard-page {
    margin: 0 !important;
    padding: 0 !important;
    width: 100%;
    min-height: 100%;
    overflow-x: hidden !important;
    background: #f4f7fb !important;
}

/* =====================================================
   ADMIN PLANS PAGE FIX
   Same fixed sidebar/topbar as admin dashboard
===================================================== */

body.admin-dashboard-page {
    --admin-sidebar-open-width: 280px;
    --admin-sidebar-close-width: 88px;
    --admin-current-sidebar-width: var(--admin-sidebar-open-width);
}

body.admin-dashboard-page.sidebar-collapsed,
body.admin-dashboard-page.dashboard-sidebar-collapsed {
    --admin-current-sidebar-width: var(--admin-sidebar-close-width);
}

body.admin-dashboard-page .container-scroll {
    display: flex !important;
    width: 100% !important;
    min-height: 100vh !important;
    align-items: stretch !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #f4f7fb !important;
}

body.admin-dashboard-page .sidebar,
body.admin-dashboard-page #dashboardSidebar {
    width: var(--admin-current-sidebar-width) !important;
    min-width: var(--admin-current-sidebar-width) !important;
    max-width: var(--admin-current-sidebar-width) !important;
    flex-shrink: 0 !important;
    display: flex !important;
    align-self: stretch !important;
    background: transparent !important;
    overflow: visible !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    height: 100vh !important;
    min-height: 100vh !important;
    z-index: 1000 !important;
    transition: width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page .sidebar > div,
body.admin-dashboard-page #dashboardSidebar > div {
    width: 100% !important;
    min-height: 100vh !important;
    height: 100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-wrap {
    width: var(--admin-current-sidebar-width) !important;
    min-width: var(--admin-current-sidebar-width) !important;
    max-width: var(--admin-current-sidebar-width) !important;
    height: 100vh !important;
    min-height: 100vh !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    z-index: 1000 !important;
    overflow: hidden !important;
    border-radius: 0 !important;
    transition: width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-inner {
    width: 100% !important;
    height: 100vh !important;
    min-height: 100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-menu {
    overflow-y: auto !important;
    overflow-x: hidden !important;
}

body.admin-dashboard-page .main-panel {
    margin-left: var(--admin-current-sidebar-width) !important;
    width: calc(100% - var(--admin-current-sidebar-width)) !important;
    min-width: 0 !important;
    min-height: 100vh !important;
    padding: 70px 0 0 0 !important;
    display: block !important;
    background: #f4f7fb !important;
    transition: margin-left .25s ease, width .25s ease !important;
}

body.admin-dashboard-page .navbar.my-navbar.fixed-top {
    left: var(--admin-current-sidebar-width) !important;
    width: calc(100% - var(--admin-current-sidebar-width)) !important;
    height: 70px !important;
    min-height: 70px !important;
    margin: 0 !important;
    z-index: 999 !important;
    transition: left .25s ease, width .25s ease !important;
}

body.admin-dashboard-page .content-wrapper {
    width: 100% !important;
    margin: 0 !important;
    padding: 24px !important;
    min-height: calc(100vh - 70px) !important;
    overflow: visible !important;
    position: relative !important;
    background: #f4f7fb !important;
}

.modal-header {
    background: linear-gradient(135deg, #000, #e4ac00);
    color: #fff;
}

#featuresList {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 10px 15px;
    padding: 20px;
    border: 1px solid #eee;
    border-radius: 10px;
    background: #fafafa;
    max-height: 420px;
    overflow-y: auto;
}

#featuresList .form-check {
    margin-bottom: 5px;
}

.feature-core {
    font-weight: 600;
}

.badge-industry {
    text-transform: capitalize;
}

.table-responsive {
    width: 100%;
}
/* Table / modal */
body.admin-dashboard-page .table-responsive {
    width: 100%;
    overflow-x: auto;
    background: #fff;
    border-radius: 12px;
}

body.admin-dashboard-page table.table {
    background: #fff;
    margin-bottom: 0;
}

body.admin-dashboard-page .table th,
body.admin-dashboard-page .table td {
    vertical-align: middle;
    white-space: nowrap;
}

body.admin-dashboard-page .modal {
    z-index: 5000 !important;
}

body.admin-dashboard-page .modal-backdrop {
    z-index: 4990 !important;
}

/* Collapsed sidebar text hide */
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-arrow,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow {
    display: none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .submenu-collapse,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse {
    display: none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-link,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-link {
    justify-content: center !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-main,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-main {
    justify-content: center !important;
    gap: 0 !important;
}

/* Mobile */
@media (max-width: 991.98px) {
    body.admin-dashboard-page {
        --admin-current-sidebar-width: 0px !important;
    }

    body.admin-dashboard-page .container-scroll {
        display: block !important;
        width: 100% !important;
        min-height: 100vh !important;
    }

    body.admin-dashboard-page .sidebar,
    body.admin-dashboard-page #dashboardSidebar {
        position: fixed !important;
        top: 0 !important;
        left: -280px !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        margin: 0 !important;
        padding: 0 !important;
        z-index: 3000 !important;
        overflow: hidden !important;
        transition: left .25s ease !important;
    }

    body.admin-dashboard-page .sidebar > div,
    body.admin-dashboard-page #dashboardSidebar > div,
    body.admin-dashboard-page #dashboardSidebar .sidebar-wrap,
    body.admin-dashboard-page #dashboardSidebar .sidebar-inner {
        position: relative !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        max-height: 100dvh !important;
        margin: 0 !important;
        overflow: hidden !important;
        border-radius: 0 !important;
    }

    body.admin-dashboard-page #dashboardSidebar .sidebar-menu {
        height: calc(100dvh - 120px) !important;
        max-height: calc(100dvh - 120px) !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
    }

    body.admin-dashboard-page.sidebar-open #dashboardSidebar,
    body.admin-dashboard-page.dashboard-mobile-sidebar-open #dashboardSidebar {
        left: 0 !important;
    }

    body.admin-dashboard-page .main-panel {
        margin-left: 0 !important;
        width: 100% !important;
        min-width: 0 !important;
        padding-top: 70px !important;
    }

    body.admin-dashboard-page .navbar.my-navbar.fixed-top {
        left: 0 !important;
        width: 100% !important;
        height: 70px !important;
        min-height: 70px !important;
    }

    body.admin-dashboard-page .content-wrapper {
        padding: 16px !important;
        min-height: calc(100vh - 70px) !important;
    }

    body.admin-dashboard-page #featuresList {
        grid-template-columns: 1fr;
        padding: 14px;
        max-height: 360px;
    }
}

/* =====================================================
   PLANS.PHP - DARK PURPLE GLASS THEME
   Paste at END before
===================================================== */

html,
body.admin-dashboard-page,
body.admin-plans-page{
    margin:0 !important;
    padding:0 !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:#f8fafc !important;
    font-family:Inter, "Segoe UI", Arial, sans-serif !important;
}

/* ================= Layout ================= */

body.admin-plans-page .container-scroll{
    display:flex !important;
    min-height:100vh !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.admin-plans-page .main-panel{
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        radial-gradient(circle at 78% 6%, rgba(59,130,246,.12), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
}

body.admin-plans-page .topbar,
body.admin-plans-page .navbar,
body.admin-plans-page .navbar.my-navbar.fixed-top{
    background:#070b14 !important;
    color:#fff !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

body.admin-plans-page .content-wrapper{
    position:relative !important;
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.08), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:#f8fafc !important;
    overflow:visible !important;
}

body.admin-plans-page .content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.28;
    z-index:0;
}

body.admin-plans-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* ================= Page Title ================= */

body.admin-plans-page h1,
body.admin-plans-page h2,
body.admin-plans-page h3,
body.admin-plans-page h4,
body.admin-plans-page .page-title{
    color:#f8fafc !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.35) !important;
}

/* ================= Cards / Plan Cards ================= */

body.admin-plans-page .card,
body.admin-plans-page .plan-card,
body.admin-plans-page .table-card,
body.admin-plans-page .chart-card,
body.admin-plans-page .filter-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.78), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    color:#f8fafc !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    overflow:hidden !important;
    transition:.25s ease !important;
}

body.admin-plans-page .card:hover,
body.admin-plans-page .plan-card:hover,
body.admin-plans-page .table-card:hover,
body.admin-plans-page .chart-card:hover{
    transform:translateY(-3px) !important;
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

body.admin-plans-page .card-header,
body.admin-plans-page .card-footer{
    background:rgba(7,11,20,.38) !important;
    border-color:rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
}

body.admin-plans-page .card-body{
    background:transparent !important;
    color:#f8fafc !important;
}

body.admin-plans-page .card-title{
    color:#ffffff !important;
    font-weight:900 !important;
}

body.admin-plans-page .font-weight-500,
body.admin-plans-page p,
body.admin-plans-page label,
body.admin-plans-page .text-muted{
    color:#cbd5e1 !important;
}

/* ================= Forms ================= */

body.admin-plans-page .form-control,
body.admin-plans-page .form-select,
body.admin-plans-page input,
body.admin-plans-page select,
body.admin-plans-page textarea{
    min-height:46px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    outline:none !important;
    font-weight:800 !important;
}

body.admin-plans-page textarea{
    min-height:100px !important;
}

body.admin-plans-page input::placeholder,
body.admin-plans-page textarea::placeholder{
    color:#94a3b8 !important;
}

body.admin-plans-page .form-control:focus,
body.admin-plans-page .form-select:focus,
body.admin-plans-page input:focus,
body.admin-plans-page select:focus,
body.admin-plans-page textarea:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

body.admin-plans-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* ================= Buttons ================= */

body.admin-plans-page .btn-primary,
body.admin-plans-page .btn-success,
body.admin-plans-page .btn-warning,
body.admin-plans-page button[type="submit"]{
    min-height:44px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.18) !important;
    transition:.22s ease !important;
}

body.admin-plans-page .btn-secondary,
body.admin-plans-page .btn-dark,
body.admin-plans-page .btn-light{
    min-height:42px !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-plans-page .btn-danger{
    min-height:42px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-plans-page .btn:hover,
body.admin-plans-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* ================= Tables ================= */

body.admin-plans-page .table-responsive{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(12,18,35,.88), rgba(20,12,40,.78)) !important;
    border:1px solid rgba(139,92,246,.22) !important;
    border-radius:18px !important;
    overflow:auto !important;
    box-shadow:
        0 18px 45px rgba(0,0,0,.28),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(16px) saturate(140%) !important;
    -webkit-backdrop-filter:blur(16px) saturate(140%) !important;
}

body.admin-plans-page table.table,
body.admin-plans-page .table{
    width:100% !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.06) !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

body.admin-plans-page table.table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

body.admin-plans-page table.table thead th,
body.admin-plans-page table.table thead.table-dark th{
    background:rgba(6,10,20,.95) !important;
    color:#f8fafc !important;
    border:none !important;
    border-bottom:1px solid rgba(139,92,246,.22) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

body.admin-plans-page table.table tbody td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.42), rgba(20,12,40,.28)) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:15px 18px !important;
    font-size:14px !important;
    font-weight:700 !important;
    vertical-align:middle !important;
}

body.admin-plans-page table.table tbody tr:hover td,
body.admin-plans-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(30,20,58,.58), rgba(15,23,42,.42)) !important;
    color:#ffffff !important;
}

/* Empty row */
body.admin-plans-page table.table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    font-weight:800 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.34), rgba(20,12,40,.22)) !important;
}

/* ================= Modal Dark Glass ================= */

body.admin-plans-page .modal{
    z-index:5000 !important;
}

body.admin-plans-page .modal-backdrop{
    z-index:4990 !important;
}

body.admin-plans-page .modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
    backdrop-filter:blur(7px) !important;
}

body.admin-plans-page .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.30), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(34,197,94,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.82)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.38) !important;
    border-radius:20px !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.62),
        0 0 52px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
    overflow:hidden !important;
}

body.admin-plans-page .modal-header,
body.admin-plans-page .modal-footer{
    background:rgba(7,11,20,.38) !important;
    border-color:rgba(148,163,184,.16) !important;
    color:#f8fafc !important;
}

body.admin-plans-page .modal-title{
    color:#f8fafc !important;
    font-size:21px !important;
    font-weight:900 !important;
}

body.admin-plans-page .modal-body{
    background:rgba(15,23,42,.18) !important;
    color:#e5e7eb !important;
}

body.admin-plans-page .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

/* ================= Checkboxes / Permissions ================= */

body.admin-plans-page .form-check,
body.admin-plans-page .permission-item,
body.admin-plans-page .permission-box{
    background:rgba(15,23,42,.48) !important;
    border:1px solid rgba(148,163,184,.14) !important;
    border-radius:13px !important;
    padding:12px 14px !important;
    color:#e5e7eb !important;
    transition:.22s ease !important;
}

body.admin-plans-page .form-check:hover,
body.admin-plans-page .permission-item:hover,
body.admin-plans-page .permission-box:hover{
    background:rgba(124,58,237,.14) !important;
    border-color:rgba(124,58,237,.35) !important;
}

body.admin-plans-page .form-check-input{
    border-color:rgba(139,92,246,.45) !important;
}

body.admin-plans-page .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
}

/* ================= Responsive ================= */

@media(max-width:991.98px){
    body.admin-plans-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-plans-page table.table{
        min-width:900px !important;
    }

    body.admin-plans-page .card{
        margin-bottom:16px !important;
    }
}
/* =====================================================
   PLANS.PHP - ADD PLAN POPUP COMPACT DARK GLASS FIX
   Paste at END before 
===================================================== */

/* Modal backdrop */
body.admin-plans-page .modal-backdrop.show{
    opacity:.78 !important;
    background:#000 !important;
    backdrop-filter:blur(8px) !important;
    -webkit-backdrop-filter:blur(8px) !important;
}

/* Modal z-index */
body.admin-plans-page .modal{
    z-index:5000 !important;
}

/* Popup size small */
body.admin-plans-page .modal-dialog{
    max-width:880px !important;
    width:calc(100% - 32px) !important;
    margin:22px auto !important;
}

/* Main popup glass */
body.admin-plans-page .modal-content{
    max-height:calc(100vh - 44px) !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;

    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.26), transparent 38%),
        radial-gradient(circle at 88% 0%, rgba(34,197,94,.08), transparent 30%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.84)) !important;

    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.38) !important;
    border-radius:20px !important;

    box-shadow:
        0 28px 80px rgba(0,0,0,.64),
        0 0 52px rgba(124,58,237,.20),
        inset 0 1px 0 rgba(255,255,255,.06) !important;

    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
}

/* Header compact */
body.admin-plans-page .modal-header{
    flex:0 0 auto !important;
    min-height:64px !important;
    padding:16px 20px !important;
    background:
        linear-gradient(135deg, rgba(30,20,58,.72), rgba(7,11,20,.42)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

body.admin-plans-page .modal-title{
    color:#f8fafc !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
    text-shadow:0 0 22px rgba(124,58,237,.35) !important;
}

/* Close button */
body.admin-plans-page .btn-close{
    width:42px !important;
    height:42px !important;
    border-radius:13px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
    box-shadow:0 10px 24px rgba(0,0,0,.26) !important;
}

body.admin-plans-page .btn-close:hover{
    background-color:rgba(239,68,68,.24) !important;
    transform:translateY(-1px) !important;
}

/* Body scrollable - footer ab hide nahi hoga */
body.admin-plans-page .modal-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:18px 20px 14px !important;
    background:rgba(15,23,42,.16) !important;
}

/* Footer always visible */
body.admin-plans-page .modal-footer{
    flex:0 0 auto !important;
    position:sticky !important;
    bottom:0 !important;
    z-index:20 !important;
    padding:14px 20px !important;

    background:
        linear-gradient(180deg, rgba(7,11,20,.90), rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    box-shadow:0 -18px 38px rgba(0,0,0,.34) !important;
}

/* Form labels */
body.admin-plans-page .modal label,
body.admin-plans-page .modal .form-label{
    color:#dbe4ff !important;
    font-size:13px !important;
    font-weight:850 !important;
    margin-bottom:7px !important;
}

/* Inputs compact dark glass */
body.admin-plans-page .modal .form-control,
body.admin-plans-page .modal .form-select,
body.admin-plans-page .modal input,
body.admin-plans-page .modal select,
body.admin-plans-page .modal textarea{
    min-height:44px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.20) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    font-weight:800 !important;
}

body.admin-plans-page .modal .form-control:focus,
body.admin-plans-page .modal .form-select:focus,
body.admin-plans-page .modal input:focus,
body.admin-plans-page .modal select:focus,
body.admin-plans-page .modal textarea:focus{
    background:rgba(17,24,39,.92) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

body.admin-plans-page .modal input::placeholder,
body.admin-plans-page .modal textarea::placeholder{
    color:#94a3b8 !important;
}

body.admin-plans-page .modal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Top fields spacing compact */
body.admin-plans-page .modal .row{
    row-gap:12px !important;
}

body.admin-plans-page .modal .mb-3{
    margin-bottom:12px !important;
}

/* Description text */
body.admin-plans-page .modal p,
body.admin-plans-page .modal small,
body.admin-plans-page .modal .text-muted{
    color:#aab4cf !important;
    font-weight:700 !important;
}

/* Features title */
body.admin-plans-page #featuresList{
    max-height:300px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:10px !important;

    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.10), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.60), rgba(17,24,39,.44)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:16px !important;
}

/* Features grid */
body.admin-plans-page #featuresList .row,
body.admin-plans-page .features-grid{
    display:grid !important;
    grid-template-columns:repeat(3, minmax(0,1fr)) !important;
    gap:10px !important;
}

/* Feature item */
body.admin-plans-page #featuresList .form-check,
body.admin-plans-page .permission-box,
body.admin-plans-page .permission-item{
    min-height:54px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
    padding:10px 12px !important;

    background:
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(30,20,58,.44)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:13px !important;
    color:#e5e7eb !important;
    box-shadow:
        0 10px 22px rgba(0,0,0,.16),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    transition:.18s ease !important;
}

/* Feature hover */
body.admin-plans-page #featuresList .form-check:hover,
body.admin-plans-page .permission-box:hover,
body.admin-plans-page .permission-item:hover{
    transform:translateY(-1px) !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.66), rgba(15,23,42,.50)) !important;
    border-color:rgba(124,58,237,.42) !important;
}

/* Checkbox */
body.admin-plans-page #featuresList .form-check-input,
body.admin-plans-page .modal .form-check-input{
    width:18px !important;
    height:18px !important;
    min-height:18px !important;
    margin:0 !important;
    flex:0 0 18px !important;
    background-color:rgba(15,23,42,.82) !important;
    border:1px solid rgba(139,92,246,.48) !important;
    box-shadow:none !important;
    cursor:pointer !important;
}

body.admin-plans-page #featuresList .form-check-input:checked,
body.admin-plans-page .modal .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.16) !important;
}

body.admin-plans-page #featuresList .form-check-label{
    color:#dbe4ff !important;
    font-size:13px !important;
    font-weight:800 !important;
    line-height:1.25 !important;
    cursor:pointer !important;
}

/* Features scrollbar */
body.admin-plans-page #featuresList::-webkit-scrollbar,
body.admin-plans-page .modal-body::-webkit-scrollbar{
    width:10px !important;
}

body.admin-plans-page #featuresList::-webkit-scrollbar-track,
body.admin-plans-page .modal-body::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-plans-page #featuresList::-webkit-scrollbar-thumb,
body.admin-plans-page .modal-body::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* Footer buttons */
body.admin-plans-page .modal-footer .btn,
body.admin-plans-page .modal-footer button{
    min-height:44px !important;
    border-radius:13px !important;
    padding:0 22px !important;
    font-weight:900 !important;
    border:none !important;
    transition:.22s ease !important;
}

body.admin-plans-page .modal-footer .btn-primary,
body.admin-plans-page .modal-footer button[type="submit"]{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.18) !important;
}

body.admin-plans-page .modal-footer .btn-secondary,
body.admin-plans-page .modal-footer .btn-light{
    background:rgba(15,23,42,.86) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
}

body.admin-plans-page .modal-footer .btn:hover,
body.admin-plans-page .modal-footer button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Mobile */
@media(max-width:991px){
    body.admin-plans-page .modal-dialog{
        max-width:94vw !important;
        margin:14px auto !important;
    }

    body.admin-plans-page .modal-content{
        max-height:calc(100vh - 28px) !important;
    }

    body.admin-plans-page #featuresList .row,
    body.admin-plans-page .features-grid{
        grid-template-columns:repeat(2, minmax(0,1fr)) !important;
    }
}

@media(max-width:576px){
    body.admin-plans-page .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:9px auto !important;
    }

    body.admin-plans-page .modal-header{
        padding:14px 16px !important;
    }

    body.admin-plans-page .modal-body{
        padding:14px 16px !important;
    }

    body.admin-plans-page .modal-footer{
        padding:12px 16px !important;
        flex-direction:column !important;
    }

    body.admin-plans-page .modal-footer .btn,
    body.admin-plans-page .modal-footer button{
        width:100% !important;
    }

    body.admin-plans-page #featuresList .row,
    body.admin-plans-page .features-grid{
        grid-template-columns:1fr !important;
    }
}

</style>
</head>

<body class="admin-dashboard-page admin-plans-page">

<div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">

            <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                <div>
                    <h4 class="mb-0">Plans</h4>
                    <small class="text-muted">Manage POS, Water Plant, Distribution, and Real Estate / Agency plan features</small>
                </div>

                <button type="button" class="btn btn-warning" onclick="openAdd()">
                    <i class="bi bi-plus-circle"></i> Add Plan
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th width="70">ID</th>
                            <th>Name</th>
                            <th width="130">Price</th>
                            <th width="160">Billing Cycle</th>
                            <th width="180">Industry</th>
                            <th width="110">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No plans found</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($plans as $p): ?>
                        <tr>
                            <td><?= (int)$p['id'] ?></td>

                            <td>
                                <strong><?= htmlspecialchars($p['name'] ?? '') ?></strong>
                            </td>

                            <td>
                                Rs. <?= number_format((float)($p['price'] ?? 0), 2) ?>
                            </td>

                            <td><?= htmlspecialchars(ucfirst($p['billing_cycle'] ?? 'monthly')) ?></td>

                            <td>
                                <span class="badge bg-secondary badge-industry">
                                    <?= htmlspecialchars(pretty_industry_label($p['industry'] ?? '')) ?>
                                </span>
                            </td>

                            <td>
                                <i class="bi bi-pencil-square text-warning me-2 editPlan"
                                   title="Edit"
                                   style="cursor:pointer; font-size:18px;"
                                   data-id="<?= (int)$p['id'] ?>"
                                   data-name="<?= htmlspecialchars($p['name'] ?? '', ENT_QUOTES) ?>"
                                   data-price="<?= htmlspecialchars((string)($p['price'] ?? 0), ENT_QUOTES) ?>"
                                   data-cycle="<?= htmlspecialchars(clean_billing_cycle($p['billing_cycle'] ?? 'monthly'), ENT_QUOTES) ?>"
                                   data-industry="<?= htmlspecialchars(normalize_industry_key($p['industry'] ?? ''), ENT_QUOTES) ?>">
                                </i>

                                <i class="bi bi-trash text-danger deletePlan"
                                   title="Delete"
                                   style="cursor:pointer; font-size:18px;"
                                   data-id="<?= (int)$p['id'] ?>">
                                </i>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- PLAN MODAL -->
<div class="modal fade" id="planModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <form id="planForm">
                <input type="hidden" name="plan_id" id="plan_id">

                <div class="modal-header">
                    <h5 id="modalTitle" class="modal-title">Add Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Plan Name</label>
                            <input type="text" name="name" id="plan_name" class="form-control" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label">Price</label>
                            <input type="number" step="0.01" name="price" id="plan_price" class="form-control" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label">Billing Cycle</label>
                            <select name="billing_cycle" id="plan_cycle" class="form-control" required>
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Industry</label>
                        <select name="industry" id="industrySelect" class="form-control" required>
                            <?php foreach ($industries as $industry): ?>
                                <option value="<?= htmlspecialchars($industry) ?>">
                                    <?= htmlspecialchars(pretty_industry_label($industry)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Restaurant select karne par Restaurant Bookings, POS, Tables, KOT, Kitchen, Waiters aur Reports auto checked honge.
                            Water Plant select karne par sirf Water Plant specific features auto checked honge.
                            Distribution select karne par sirf Distribution specific features auto checked honge.
                            Pharmacy / OPD select karne par OPD Management features auto checked honge.
                            Real Estate / Agency select karne par sirf Real Estate specific features auto checked honge.
                        </small>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Features</label>
                        <div id="featuresList"></div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Save Plan</button>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const allFeatures   = <?= json_encode($all_features) ?>;
const featureLabels = <?= json_encode($feature_labels) ?>;
const industryCore  = <?= json_encode($industry_core_features) ?>;

function normalizeIndustry(industry) {
    if (!industry) return '';

    industry = String(industry).trim().toLowerCase();
    industry = industry.replace(/[-\s]+/g, '_');

    const map = {
        'retailer': 'retail',
        'retail': 'retail',
        'services': 'services',
        'service': 'services',
        'restaurant': 'restaurant',
        'restaurants': 'restaurant',
        'resturent': 'restaurant',
        'resturant': 'restaurant',
        'real_estate': 'real_estate',
        'state_agency': 'state_agency',
        'agency': 'agency',
        'water_plant': 'water_plant',
        'distribution': 'distribution',
        'distribution_business': 'distribution',
        'delivery': 'distribution',
        'delivery_business': 'distribution',

        'pharmacy': 'pharmacy',
        'clinic': 'opd',
        'healthcare': 'opd',
        'hospital': 'opd',
        'opd': 'opd'
    };

    return map[industry] || industry;
}

function loadFeatures(industry) {
    const container = document.getElementById('featuresList');
    container.innerHTML = '';

    const normalizedIndustry = normalizeIndustry(industry);
    const core = industryCore[normalizedIndustry] || [];

    const rest = allFeatures.filter(f => !core.includes(f));
    const ordered = [...core, ...rest];

    ordered.forEach(f => {
        const div = document.createElement('div');
        div.classList.add('form-check');

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'form-check-input';
        input.name = 'features[]';
        input.value = f;
        input.id = 'feat_' + f.replace(/[^a-zA-Z0-9_-]/g, '_');

        if (core.includes(f)) {
            input.checked = true;
        }

        const label = document.createElement('label');
        label.className = 'form-check-label';

        if (core.includes(f)) {
            label.classList.add('feature-core');
        }

        label.htmlFor = input.id;
        label.innerText = featureLabels[f] || f;

        div.appendChild(input);
        div.appendChild(label);
        container.appendChild(div);
    });
}

const industrySelect = document.getElementById('industrySelect');

if (industrySelect) {
    industrySelect.addEventListener('change', function () {
        loadFeatures(this.value);
    });
}

const modal = new bootstrap.Modal(document.getElementById('planModal'));

function openAdd() {
    document.getElementById('planForm').reset();
    document.getElementById('modalTitle').innerText = 'Add Plan';
    document.getElementById('plan_id').value = '';
    document.getElementById('plan_cycle').value = 'monthly';

    const currentIndustry = document.getElementById('industrySelect').value;
    loadFeatures(currentIndustry);

    modal.show();
}

document.querySelectorAll('.editPlan').forEach(icon => {
    icon.addEventListener('click', () => {
        const id       = icon.dataset.id;
        const name     = icon.dataset.name;
        const price    = icon.dataset.price;
        const cycle    = icon.dataset.cycle;
        const industry = normalizeIndustry(icon.dataset.industry);

        document.getElementById('plan_id').value = id;
        document.getElementById('plan_name').value = name;
        document.getElementById('plan_price').value = price;
        document.getElementById('plan_cycle').value = String(cycle || 'monthly').toLowerCase();

        const industrySelect = document.getElementById('industrySelect');
        industrySelect.value = industry;

        loadFeatures(industry);

        fetch('?plan_id=' + encodeURIComponent(id), {
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(res => res.json())
            .then(features => {
                document.querySelectorAll('#featuresList input[type="checkbox"]').forEach(cb => {
                    cb.checked = false;
                });

                features.forEach(f => {
                    const safeId = 'feat_' + String(f).replace(/[^a-zA-Z0-9_-]/g, '_');
                    const cb = document.getElementById(safeId);

                    if (cb) {
                        cb.checked = true;
                    }
                });
            })
            .catch(() => {
                alert('Features load nahi ho saken.');
            });

        document.getElementById('modalTitle').innerText = 'Edit Plan';
        modal.show();
    });
});

document.querySelectorAll('.deletePlan').forEach(icon => {
    icon.addEventListener('click', () => {
        if (!confirm('Delete this plan?')) return;

        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `ajax=delete_plan&id=${encodeURIComponent(icon.dataset.id)}`
        })
        .then(res => res.text())
        .then(res => {
            if (res.trim() === 'ok') {
                location.reload();
            } else {
                alert(res);
            }
        })
        .catch(() => {
            alert('Delete request failed.');
        });
    });
});

document.getElementById('planForm').addEventListener('submit', e => {
    e.preventDefault();

    const formData = new FormData(e.target);
    formData.append('ajax', 'save_plan');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(res => {
        if (res.trim() === 'ok') {
            location.reload();
        } else {
            alert(res);
        }
    })
    .catch(() => {
        alert('Save request failed.');
    });
});

if (industrySelect) {
    loadFeatures(industrySelect.value);
}
</script>

</body>
</html>