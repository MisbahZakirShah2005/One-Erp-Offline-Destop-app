<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/*
|--------------------------------------------------------------------------
| Integration helper
|--------------------------------------------------------------------------
| Wholesale Pricing is controlled from Integrations page.
| Correct live path: /app/helpers/includes/integration_helper.php
|--------------------------------------------------------------------------
*/
$integrationHelperPath = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/integration_helper.php';
if (file_exists($integrationHelperPath)) {
    require_once $integrationHelperPath;
}

if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$posPlaceholderImage = "data:image/svg+xml,%3Csvg%20xmlns%3D'http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg'%20viewBox%3D'0%200%20220%20220'%3E%3Crect%20width%3D'220'%20height%3D'220'%20fill%3D'%23111827'%2F%3E%3Cpath%20d%3D'M55%20155l38-42%2027%2028%2018-20%2028%2034H55z'%20fill%3D'%23334155'%2F%3E%3Ccircle%20cx%3D'145'%20cy%3D'73'%20r%3D'18'%20fill%3D'%23475569'%2F%3E%3Ctext%20x%3D'110'%20y%3D'195'%20text-anchor%3D'middle'%20font-family%3D'Arial'%20font-size%3D'16'%20fill%3D'%2394a3b8'%3ENo%20Image%3C%2Ftext%3E%3C%2Fsvg%3E";

function esc($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function getItemType(array $row): string
{
    if (!empty($row['type'])) {
        return strtolower(trim((string)$row['type']));
    }
    if (!empty($row['item_type'])) {
        return strtolower(trim((string)$row['item_type']));
    }
    return 'product';
}

function normalizeText($value): string
{
    return strtolower(trim((string)$value));
}

/*
|--------------------------------------------------------------------------
| POS schema lookup cache
|--------------------------------------------------------------------------
| SHOW TABLES on every POS request is unnecessary. When performance.php is
| loaded through db.php, table existence is cached. Fallback remains exactly
| the old SHOW TABLES behavior.
|--------------------------------------------------------------------------
*/
function posTableExists(mysqli $conn, string $tableName): bool
{
    static $requestCache = [];

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);

    if ($safeTable === '') {
        return false;
    }

    if (array_key_exists($safeTable, $requestCache)) {
        return $requestCache[$safeTable];
    }

    $resolver = static function () use ($conn, $safeTable): bool {
        $escapedTable = $conn->real_escape_string($safeTable);
        $result = $conn->query("SHOW TABLES LIKE '{$escapedTable}'");
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;

        if ($result instanceof mysqli_result) {
            $result->free();
        }

        return $exists;
    };

    if (function_exists('oneerpCacheRemember')) {
        $exists = (bool)oneerpCacheRemember(
            'pos_table_exists_' . $safeTable,
            3600,
            $resolver
        );
    } else {
        $exists = $resolver();
    }

    $requestCache[$safeTable] = $exists;

    return $exists;
}

function resolveItemTaxRate(array $item, array $taxes): float
{
    $itemType = getItemType($item);

    foreach ($taxes as $tax) {
        $taxType = normalizeText($tax['type'] ?? '');
        $applicationOn = normalizeText($tax['application_on'] ?? '');
        $status = (int)($tax['status'] ?? 0);

        if ($status !== 1) {
            continue;
        }

        $typeMatched = (
            $taxType === $itemType ||
            $taxType === 'all' ||
            $taxType === ''
        );

        $applicationMatched = (
            $applicationOn === $itemType ||
            $applicationOn === 'all' ||
            $applicationOn === ''
        );

        if ($itemType === 'product' && ($typeMatched || $applicationMatched)) {
            return (float)($tax['rate'] ?? 0);
        }
    }

    return 0.00;
}

function calculateDiscountedRetailPrice($price, $hasDiscount, $discountType, $discountValue): float
{
    $price = (float)$price;
    $hasDiscount = (int)$hasDiscount === 1;
    $discountType = strtolower(trim((string)$discountType));
    $discountValue = (float)$discountValue;

    if (!$hasDiscount || $discountValue <= 0 || $price <= 0) {
        return round(max(0, $price), 2);
    }

    if ($discountType === 'fixed') {
        $finalPrice = $price - $discountValue;
    } else {
        if ($discountValue > 100) {
            $discountValue = 100;
        }

        $finalPrice = $price - (($price * $discountValue) / 100);
    }

    return round(max(0, $finalPrice), 2);
}

function getDiscountLabel($hasDiscount, $discountType, $discountValue): string
{
    $hasDiscount = (int)$hasDiscount === 1;
    $discountType = strtolower(trim((string)$discountType));
    $discountValue = (float)$discountValue;

    if (!$hasDiscount || $discountValue <= 0) {
        return '';
    }

    if ($discountType === 'fixed') {
        return 'Rs ' . number_format($discountValue, 0) . ' OFF';
    }

    return rtrim(rtrim(number_format(min($discountValue, 100), 2), '0'), '.') . '% OFF';
}

/*
|--------------------------------------------------------------------------
| CUSTOMERS
|--------------------------------------------------------------------------
*/
$customers = [
    'Retailer'   => [],
    'Wholesaler' => [],
];

$custSql = "SELECT id, name, phone, type FROM customers WHERE client_id = {$client_id} ORDER BY name ASC";
$custRes = $conn->query($custSql);
if ($custRes) {
    while ($row = $custRes->fetch_assoc()) {
        /*
        |--------------------------------------------------------------------------
        | Retailer = Walk-In + Retail customer
        |--------------------------------------------------------------------------
        | Old Walk-in records ko POS par Retailer ke andar hi show karna hai.
        | Retailer mode me customer select optional hai, is liye default walk-in
        | sale bhi Retailer type ke sath checkout hogi.
        */
        $typeRaw = !empty($row['type']) ? trim((string)$row['type']) : 'Retailer';
        $typeKey = strtolower(str_replace([' ', '_'], ['-', '-'], $typeRaw));

        if (in_array($typeKey, ['walk-in', 'walkin', 'retail', 'retailer', ''], true)) {
            $type = 'Retailer';
        } elseif (in_array($typeKey, ['wholesale', 'wholesaler'], true)) {
            $type = 'Wholesaler';
        } else {
            $type = 'Retailer';
        }

        $row['type'] = $type;

        if (!isset($customers[$type])) {
            $customers[$type] = [];
        }
        $customers[$type][] = $row;
    }
    $custRes->free();
}

/*
|--------------------------------------------------------------------------
| WHOLESALE PRICING BUSINESS FEATURE
|--------------------------------------------------------------------------
| Wholesale buttons and wholesale pricing are controlled from Integrations page.
| If "Wholesale Pricing" is active for this client, POS can show Wholesale buttons.
| If inactive, POS will remain Retailer only.
|--------------------------------------------------------------------------
*/
$wholesaleEnabled = false;

if (function_exists('isIntegrationEnabled')) {
    $wholesaleEnabled = isIntegrationEnabled($conn, $client_id, 'Wholesale Pricing');
}

/*
|--------------------------------------------------------------------------
| Existing JS/CSS already uses $hasWholesaleCustomer variable name.
| Keep same variable to avoid disturbing old frontend logic.
|--------------------------------------------------------------------------
*/
$hasWholesaleCustomer = (bool)$wholesaleEnabled;


/*
|--------------------------------------------------------------------------
| PHARMACY UNIT FEATURE GUARD
|--------------------------------------------------------------------------
| Sirf active Pharmacy / Medical plan walay clients ko unit dropdown show hoga.
|--------------------------------------------------------------------------
*/
function posLooksLikePharmacyText($value): bool
{
    $value = strtolower(trim((string)$value));
    $value = str_replace(['-', '_'], ' ', $value);

    return (
        strpos($value, 'pharmacy') !== false ||
        strpos($value, 'pharma') !== false ||
        strpos($value, 'medical') !== false ||
        strpos($value, 'medicine') !== false ||
        strpos($value, 'chemist') !== false
    );
}

$isPharmacyRetailClient = false;

try {
    $stmt = $conn->prepare("
        SELECT
            p.name AS plan_name,
            p.industry AS plan_industry
        FROM subscriptions s
        INNER JOIN plans p ON p.id = s.plan_id
        WHERE s.client_id = ?
          AND LOWER(TRIM(s.status)) IN ('active', 'enabled', '1')
          AND (s.end_date IS NULL OR s.end_date >= CURDATE())
        ORDER BY s.id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $planRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $planText = trim(
            (string)($planRow['plan_name'] ?? '') . ' ' .
            (string)($planRow['plan_industry'] ?? '')
        );

        if (posLooksLikePharmacyText($planText)) {
            $isPharmacyRetailClient = true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback: login session based guard
    |--------------------------------------------------------------------------
    */
    if (!$isPharmacyRetailClient) {
        $sessionIndustry = (string)($_SESSION['client_industry'] ?? '');
        $isPharmacySession = (int)($_SESSION['is_pharmacy_client'] ?? 0);

        if ($isPharmacySession === 1 || posLooksLikePharmacyText($sessionIndustry)) {
            $isPharmacyRetailClient = true;
        }
    }
} catch (Throwable $e) {
    $isPharmacyRetailClient = false;
}

/*
|--------------------------------------------------------------------------
| TAXES
|--------------------------------------------------------------------------
*/
$taxes = [];
$taxSql = "SELECT id, client_id, name, rate, type, application_on, region, inclusive, status, created_at, updated_at, fbr_date, effective_date
           FROM taxes
           WHERE client_id = {$client_id} AND status = 1
           ORDER BY rate DESC, id DESC";
$taxRes = $conn->query($taxSql);
if ($taxRes) {
    while ($row = $taxRes->fetch_assoc()) {
        $taxes[] = $row;
    }
    $taxRes->free();
}

/*
|--------------------------------------------------------------------------
| ITEMS - FAST INITIAL BATCH
|--------------------------------------------------------------------------
| First request par sirf 30 products load honge. Remaining products same
| folder ke lightweight endpoint se product-grid scroll par load honge.
| SELECT * intentionally retained for tenant-schema compatibility, but only
| 31 rows are fetched so the request remains light and HTTP 500 safe.
|--------------------------------------------------------------------------
*/
$posProductPageSize = 30;
$posInitialHasMoreProducts = false;
$posInitialLastId = 0;
$items = [];
$categories = ['All'];
$categorySet = ['All' => true];
$productTaxMap = [];
$rawInitialItems = [];

$itemSql = "
    SELECT *
    FROM items
    WHERE client_id = {$client_id}
      AND is_raw_item = 0
      AND status = 'active'
      AND deleted_at IS NULL
    ORDER BY id DESC
    LIMIT " . ($posProductPageSize + 1);

try {
    $itemRes = $conn->query($itemSql);
} catch (Throwable $e) {
    $itemRes = false;
}

if ($itemRes instanceof mysqli_result) {
    while ($row = $itemRes->fetch_assoc()) {
        $rawInitialItems[] = $row;
    }
    $itemRes->free();
}

if (count($rawInitialItems) > $posProductPageSize) {
    $posInitialHasMoreProducts = true;
    $rawInitialItems = array_slice($rawInitialItems, 0, $posProductPageSize);
}

foreach ($rawInitialItems as $row) {
    $cat = 'All';

    if (isset($row['category']) && $row['category'] !== '') {
        $cat = trim((string)$row['category']);
    } elseif (isset($row['category_name']) && $row['category_name'] !== '') {
        $cat = trim((string)$row['category_name']);
    } elseif (isset($row['item_category']) && $row['item_category'] !== '') {
        $cat = trim((string)$row['item_category']);
    }

    $row['category_label'] = $cat;
    $row['resolved_item_type'] = getItemType($row);
    $row['resolved_tax_rate'] = resolveItemTaxRate($row, $taxes);

    $items[] = $row;
    $productTaxMap[(int)$row['id']] = [
        'tax_rate' => (float)$row['resolved_tax_rate'],
        'item_type' => $row['resolved_item_type'],
        'name' => $row['name'] ?? ''
    ];

    if (!isset($categorySet[$cat])) {
        $categorySet[$cat] = true;
        $categories[] = $cat;
    }
}

if (!empty($items)) {
    $lastInitialItem = $items[array_key_last($items)];
    $posInitialLastId = (int)($lastInitialItem['id'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| PRODUCT UNITS FOR PHARMACY POS
|--------------------------------------------------------------------------
| Product card par Tablet / Strip / Box dropdown ke liye units fetch.
|--------------------------------------------------------------------------
*/
$itemProductUnits = [];

if ($isPharmacyRetailClient && !empty($items)) {
   /*
|--------------------------------------------------------------------------
| Sirf un products ki units POS ko deni hain
| jinke Items page par Use Product Units enabled hai
|--------------------------------------------------------------------------
*/
$itemIds = array_map(
    static function ($item) {

        $useProductUnits =
            (int)(
                $item[
                    'allow_single_unit_sale'
                ] ?? 0
            ) === 1;

        if (!$useProductUnits) {
            return 0;
        }

        return (int)(
            $item['id'] ?? 0
        );
    },
    $items
);

$itemIds = array_values(
    array_filter($itemIds)
);
    if (!empty($itemIds)) {
        $idList = implode(',', $itemIds);

        if (posTableExists($conn, 'product_units')) {
            $unitSql = "
                SELECT 
                    id,
                    product_id,
                    unit_name,
                    conversion_qty,
                    sale_price,
                    barcode,
                    is_base_unit,
                    is_default
                FROM product_units
                WHERE product_id IN ($idList)
                  AND status = 1
                ORDER BY product_id ASC, is_default DESC, is_base_unit DESC, conversion_qty ASC, id ASC
            ";

            $unitRes = $conn->query($unitSql);

            if ($unitRes) {
                while ($unit = $unitRes->fetch_assoc()) {
                    $pid = (int)$unit['product_id'];

                    if (!isset($itemProductUnits[$pid])) {
                        $itemProductUnits[$pid] = [];
                    }

                    $itemProductUnits[$pid][] = [
                        'id' => (int)$unit['id'],
                        'unit_name' => (string)$unit['unit_name'],
                        'conversion_qty' => (string)$unit['conversion_qty'],
                        'sale_price' => (string)$unit['sale_price'],
                        'barcode' => (string)($unit['barcode'] ?? ''),
                        'is_base_unit' => (int)$unit['is_base_unit'],
                        'is_default' => (int)$unit['is_default'],
                    ];
                }

                $unitRes->free();
            }
        }

    }
}
/*
|--------------------------------------------------------------------------
| Wholesale card pricing
|--------------------------------------------------------------------------
| The frontend uses wholesaler_price_1/2/3 already fetched with each item.
| The previous item_wholesale_prices query built an array that was never read,
| so it has been removed without changing the active POS pricing workflow.
|--------------------------------------------------------------------------
*/

$defaultProductTaxRate = 0.00;
foreach ($taxes as $tx) {
    $txType = normalizeText($tx['type'] ?? '');
    $appOn  = normalizeText($tx['application_on'] ?? '');
    if ($txType === 'product' || $appOn === 'product' || $txType === 'all' || $appOn === 'all') {
        $defaultProductTaxRate = (float)($tx['rate'] ?? 0);
        break;
    }
}

$posScriptDirectory = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$posScriptDirectory = $posScriptDirectory === '/' ? '' : rtrim($posScriptDirectory, '/');
$posProductsEndpoint = $posScriptDirectory . '/pos_products_scroll_api.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
/*
|--------------------------------------------------------------------------
| POS THEME INITIALIZER
|--------------------------------------------------------------------------
| Pehli dafa POS light mode mein open hoga.
| User ka selected theme localStorage mein save rahega.
|--------------------------------------------------------------------------
*/
(function () {
    const storageKey = 'oneerp_pos_theme';
    let selectedTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

        if (savedTheme === 'dark' || savedTheme === 'light') {
            selectedTheme = savedTheme;
        }
    } catch (error) {
        selectedTheme = 'light';
    }

    document.documentElement.setAttribute(
        'data-pos-theme',
        selectedTheme
    );
})();
</script>
    <title>POS Screen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php include("partials/head.php");?>
    <style>
:root{
    --bg:#0b0e17;
    --panel:#111827;
    --panel-2:#151d2e;
    --panel-3:#1a2337;
    --text:#f8fafc;
    --muted:#93a4bf;
    --line:rgba(255,255,255,.08);
    --gold:#d2a43c;
    --gold-main:#d2a43c;
    --gold-light:#f6d56f;
    --gold-dark:#8a5f12;
    --gold-hover:#e8b94d;
    --gold-border:rgba(246,213,111,.45);
    --danger:#ff4b55;
    --chip:#1b2435;
    --shadow:0 10px 30px rgba(0,0,0,.35);
}

*{
    box-sizing:border-box;
}

html,
body{
    width:100%;
    height:100%;
    margin:0;
    padding:0;
    overflow:hidden;
}

body{
    font-family:Inter, Arial, sans-serif;
    background:
        radial-gradient(circle at top left, rgba(72,92,173,.18), transparent 30%),
        radial-gradient(circle at top right, rgba(226,165,73,.10), transparent 28%),
        linear-gradient(180deg, #090c13 0%, #0c111d 100%);
    color:var(--text);
}

/* MAIN FIXED APP WRAPPER */
.container-scroll{
    width:100%;
    height:100vh;
    display:flex;
    overflow:hidden;
}

/* SIDEBAR FIX */
.sidebar{
    width:250px;
    min-width:250px;
    height:100vh;
    flex-shrink:0;
    overflow:hidden;
    background:transparent;
}

/* MAIN PANEL FIX */
.main-panel{
    flex:1;
    min-width:0;
    height:100vh;
    display:flex;
    flex-direction:column;
    overflow:hidden;
}

/* TOPBAR FIXED */
.topbar{
    height:70px;
    min-height:70px;
    max-height:70px;
    border-bottom:1px solid var(--line);
    background:#050505;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 16px;
    flex-shrink:0;
    overflow:hidden;
}

.topbar-wrap{
    flex:1;
    min-width:0;
}

.topbar-right{
    display:flex;
    align-items:center;
    gap:14px;
    flex-shrink:0;
}

.icon-btn{
    width:38px;
    height:38px;
    border-radius:12px;
    border:1px solid rgba(210,164,60,.35);
    background:linear-gradient(180deg, rgba(35,32,24,.96), rgba(24,23,20,.96));
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:.18s ease;
}

.icon-btn:hover{
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    border-color:var(--gold-border);
    box-shadow:0 10px 22px rgba(210,164,60,.25);
}

.user-pill{
    display:flex;
    align-items:center;
    gap:10px;
    padding:8px 12px;
    border-radius:14px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.03);
}

.avatar{
    width:34px;
    height:34px;
    border-radius:50%;
    background:linear-gradient(135deg,#f3d28f,#8e6030);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#111;
    font-weight:800;
}

/* CONTENT FIX */
.content-wrapper{
    flex:1;
    min-height:0;
    height:calc(100vh - 70px);
    padding:14px;
    overflow:hidden;
}

/* POS MAIN GRID */
.pos-layout{
    height:100%;
    display:grid;
    grid-template-columns:minmax(0, 1fr) 590px;
    background-color: black;
    gap:14px;
    overflow:hidden;
}

/* COMMON PANELS */
.items-area,
.cart-area{
    height:100%;
    min-height:0;
    background:linear-gradient(180deg, rgba(19,27,43,.92), rgba(15,22,36,.95));
    border:1px solid var(--line);
    border-radius:22px;
    box-shadow:var(--shadow);
    overflow:hidden;
}

/* ITEMS AREA SEPARATED */
.items-area{
    padding:14px;
    display:flex;
    flex-direction:column;
}

/* CART AREA SEPARATED */
.cart-area{
    padding:14px;
    display:flex;
    flex-direction:column;
}

/* ITEMS HEADER */
.toolbar{
    flex-shrink:0;
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:12px;
}

.type-buttons{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.type-btn{
    border:none;
    color:#fff;
    background:linear-gradient(180deg, rgba(35,32,24,.96), rgba(24,23,20,.96));
    padding:10px 14px;
    border-radius:12px;
    font-weight:800;
    min-width:110px;
    cursor:pointer;
    border:1px solid rgba(210,164,60,.35);
    transition:.18s ease;
}

.type-btn.active,
.type-btn:hover{
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    border-color:var(--gold-border);
    box-shadow:0 10px 24px rgba(210,164,60,.28), inset 0 1px 0 rgba(255,255,255,.28);
}

.selected-label{
    font-size:28px;
    font-weight:900;
    margin-left:8px;
    color:#fff;
    letter-spacing:.5px;
}

.toolbar-right{
    margin-left:auto;
    display:flex;
    gap:8px;
    align-items:center;
}

.chip{
    border:1px solid rgba(210,164,60,.35);
    background:linear-gradient(180deg, rgba(35,32,24,.96), rgba(24,23,20,.96));
    color:#fff;
    border-radius:12px;
    padding:9px 12px;
    font-size:13px;
    font-weight:800;
}

.chip:hover{
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
}

/* CATEGORY + SEARCH */
.categories{
    flex-shrink:0;
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-bottom:12px;
    align-items:center;
}

.category-btn{
    border:none;
    border-radius:12px;
    padding:9px 14px;
    background:linear-gradient(180deg, rgba(35,32,24,.96), rgba(24,23,20,.96));
    color:#fff;
    font-weight:800;
    font-size:13px;
    border:1px solid rgba(210,164,60,.30);
    cursor:pointer;
    transition:.18s ease;
}

.category-btn.active,
.category-btn:hover{
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    border-color:var(--gold-border);
    box-shadow:0 10px 22px rgba(210,164,60,.25), inset 0 1px 0 rgba(255,255,255,.25);
}

.search-wrap{
    margin-left:auto;
    min-width:280px;
}

.search-input,
.cart-search,
.field-dark{
    width:100%;
    background:#0f1625;
    border:1px solid var(--line);
    color:#fff;
    border-radius:12px;
    padding:10px 12px;
    outline:none;
    transition:.18s ease;
}

.search-input:focus,
.cart-search:focus,
.field-dark:focus{
    border-color:var(--gold-main);
    box-shadow:0 0 0 3px rgba(210,164,60,.16);
}

/* ITEMS GRID SCROLL ONLY INSIDE ITEM AREA */
.items-grid{
    flex:1;
    min-height:0;
    overflow-y:auto;
    overflow-x:hidden;
    padding-right:6px;
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(165px,1fr));
    align-content:start;
    gap:14px;
}

.items-grid::-webkit-scrollbar,
.cart-items::-webkit-scrollbar{
    width:8px;
}

.items-grid::-webkit-scrollbar-thumb,
.cart-items::-webkit-scrollbar-thumb{
    background:rgba(210,164,60,.35);
    border-radius:20px;
}

.items-grid::-webkit-scrollbar-track,
.cart-items::-webkit-scrollbar-track{
    background:transparent;
}

/* PRODUCT CARD */
.item-card{
    background:linear-gradient(180deg, rgba(34,43,65,.96), rgba(19,27,42,.96));
    border:1px solid rgba(255,255,255,.06);
    border-radius:18px;
    padding:12px;
    transition:.18s ease;
    cursor:pointer;
    min-height:220px;
    display:flex;
    flex-direction:column;
}

.item-card:hover{
    transform:translateY(-3px);
    border-color:rgba(210,164,60,.50);
    box-shadow:0 14px 28px rgba(0,0,0,.28), 0 0 0 1px rgba(210,164,60,.12);
}

.item-img{
    height:95px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:10px;
    background:rgba(255,255,255,.02);
}

.item-img img{
    max-width:100%;
    max-height:100%;
    object-fit:contain;
}

.item-title{
    font-size:14px;
    font-weight:800;
    min-height:38px;
    line-height:1.35;
    color:#fff;
}

.item-sku{
    font-size:11px;
    color:#7d92b4;
    margin-top:3px;
}

.item-bottom{
    margin-top:auto;
    padding-top:10px;
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:8px;
}

.item-price{
    font-size:22px;
    font-weight:900;
    line-height:1;
    color:#fff;
}

.item-price small{
    display:block;
    font-size:11px;
    color:#8ea4c5;
    margin-bottom:4px;
    font-weight:600;
}

.item-tax-badge{
    margin-top:8px;
    display:inline-block;
    font-size:11px;
    font-weight:800;
    color:#111827;
    background:#facc15;
    border-radius:999px;
    padding:4px 9px;
}

.add-btn{
    width:40px;
    height:40px;
    border:none;
    border-radius:12px;
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    font-size:24px;
    font-weight:900;
    cursor:pointer;
    transition:.18s ease;
    border:1px solid var(--gold-border);
    box-shadow:0 10px 22px rgba(210,164,60,.25);
}

.add-btn:hover{
    background:linear-gradient(180deg, #ffe28a, var(--gold-hover) 45%, #9d6d12);
    box-shadow:0 14px 28px rgba(210,164,60,.32);
}

/* CART TOP FIX */
.cart-top{
    flex-shrink:0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:10px;
    color:#fff;
}

.cart-title{
    min-width:0;
    display:flex;
    align-items:center;
    gap:10px;
    font-size:22px;
    font-weight:900;
}

.cart-title span:last-child{
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.status-dot{
    width:14px;
    height:14px;
    border-radius:50%;
    background:var(--gold-main);
    box-shadow:0 0 0 5px rgba(210,164,60,.16);
    flex-shrink:0;
}

.customer-box{
    flex-shrink:0;
    border:1px solid var(--line);
    background:rgba(255,255,255,.03);
    border-radius:14px;
    padding:10px 12px;
    margin-bottom:10px;
    color:#fff;
}

.cart-search{
    flex-shrink:0;
    margin-bottom:8px;
}

/* CART ITEMS SCROLL ONLY */
.cart-items{
    flex:1;
    min-height:80px;
    overflow-y:auto;
    overflow-x:hidden;
    padding-right:6px;
    color:#fff;
}

/* CART ITEM DESIGN */
.pos-cart-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px;
    margin-bottom:10px;
    border:1px solid rgba(255,255,255,0.08);
    border-radius:14px;
    background:#0f172a;
    box-shadow:0 6px 18px rgba(0,0,0,0.15);
}

.pos-cart-left{
    flex:1;
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:8px;
}

.pos-cart-title{
    color:#e2e8f0;
    font-size:13px;
    font-weight:800;
    line-height:1.3;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.pos-cart-badge{
    font-size:11px;
    color:#fbbf24;
    font-weight:700;
}

.pos-cart-sku{
    margin-top:3px;
    color:#94a3b8;
    font-size:12px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.pos-cart-controls{
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
}

.pos-cart-btn{
    width:30px;
    height:30px;
    border:none;
    outline:none;
    border-radius:8px;
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    font-size:16px;
    font-weight:900;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    transition:all .18s ease;
    border:1px solid var(--gold-border);
}

.pos-cart-btn:hover{
    background:linear-gradient(180deg, #ffe28a, var(--gold-hover) 45%, #9d6d12);
    box-shadow:0 10px 22px rgba(210,164,60,.25);
}

.pos-cart-qty{
    width:54px;
    height:30px;
    border:none;
    outline:none;
    border-radius:8px;
    text-align:center;
    background:#111827;
    color:#fff;
    font-weight:700;
    padding:0 6px;
}

.pos-cart-right{
    width:105px;
    min-width:105px;
    text-align:right;
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    justify-content:center;
}

.pos-cart-price{
    width:100%;
    height:32px;
    border:none;
    outline:none;
    border-radius:8px;
    background:#111827;
    color:#fff;
    text-align:right;
    padding:0 8px;
    font-weight:700;
}

.pos-cart-total{
    margin-top:7px;
    font-size:13px;
    font-weight:900;
    color:var(--gold-light);
}

.empty-state{
    text-align:center;
    color:#89a0c1;
    padding:35px 10px;
    border:1px dashed rgba(255,255,255,.08);
    border-radius:18px;
    background:rgba(255,255,255,.02);
}

/* CART BOTTOM FIXED INSIDE CART */
.summary{
    flex-shrink:0;
    margin-top:10px;
    border-top:1px solid rgba(255,255,255,.08);
    padding-top:10px;
}

.summary-row{
    display:flex;
    justify-content:space-between;
    margin-bottom:6px;
    color:#cedcf5;
    font-size:14px;
}

.summary-row.total{
    font-size:17px;
    font-weight:900;
    color:#fff;
    margin-top:6px;
}

.custom-product-box{
    flex-shrink:0;
    margin-top:10px;
    padding:12px;
    border:1px solid rgba(255,255,255,.08);
    border-radius:16px;
    background:rgba(255,255,255,.03);
}

.custom-product-title{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:17px;
    font-weight:900;
    color:#fff;
    margin-bottom:10px;
}

.custom-product-title .plus{
    width:36px;
    height:36px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    font-size:22px;
    font-weight:900;
    border:1px solid var(--gold-border);
}

.inline-custom-product{
    display:grid;
    grid-template-columns:1.2fr 85px 55px 75px;
    border:1px solid rgba(255,255,255,.08);
    border-radius:16px;
    overflow:hidden;
    background:#f3f1ec;
    width:100%;
}

.inline-custom-input{
    width:100%;
    height:42px;
    border:none;
    outline:none;
    background:#f3f1ec;
    color:#111827;
    padding:0 10px;
    font-size:13px;
    font-weight:700;
    border-right:1px solid rgba(17,24,39,.08);
}

.inline-custom-input::placeholder{
    color:#9ca3af;
    font-weight:600;
    font-size:12px;
}

.inline-custom-input.qty{
    text-align:center;
    padding:0 8px;
}

.inline-custom-btn{
    width:100%;
    height:42px;
    border:none;
    outline:none;
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    font-size:14px;
    font-weight:900;
    cursor:pointer;
    transition:.18s ease;
}

.inline-custom-btn:hover{
    background:linear-gradient(180deg, #ffe28a, var(--gold-hover) 45%, #9d6d12);
}

.discount-tax-wrap{
    flex-shrink:0;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
    margin:10px 0;
}

.payment-row{
    flex-shrink:0;
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:8px;
    margin-bottom:10px;
}

.pay-btn{
    border:none;
    border-radius:12px;
    padding:11px 8px;
    font-weight:900;
    color:#fff;
    background:linear-gradient(180deg, rgba(35,32,24,.96), rgba(24,23,20,.96));
    border:1px solid rgba(210,164,60,.35);
    cursor:pointer;
    transition:.18s ease;
}

.pay-btn.active,
.pay-btn:hover{
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    border-color:var(--gold-border);
    box-shadow:0 10px 22px rgba(210,164,60,.25);
}

.invoice-btn{
    flex-shrink:0;
    width:100%;
    border:none;
    border-radius:16px;
    padding:15px 16px;
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
    font-size:20px;
    font-weight:900;
    cursor:pointer;
    transition:.18s ease;
    border:1px solid var(--gold-border);
    box-shadow:0 14px 28px rgba(210,164,60,.26);
}

.invoice-btn:hover{
    background:linear-gradient(180deg, #ffe28a, var(--gold-hover) 45%, #9d6d12);
    box-shadow:0 16px 32px rgba(210,164,60,.34);
}

input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button{
    -webkit-appearance:none;
    margin:0;
}

input[type=number]{
    -moz-appearance:textfield;
}

/* Cart price input spinner enable */
.pos-cart-price[type=number]{
    -moz-appearance:auto !important;
    appearance:auto !important;
    padding-right:4px !important;
}

.pos-cart-price[type=number]::-webkit-inner-spin-button,
.pos-cart-price[type=number]::-webkit-outer-spin-button{
    -webkit-appearance:auto !important;
    appearance:auto !important;
    opacity:1 !important;
    margin:0 !important;
}

.muted{
    color:#93a4bf;
}

/* MODAL BUTTONS */
.btn-primary,
.modal .btn-primary,
.modal button[type="submit"]{
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark)) !important;
    color:#111827 !important;
    border:1px solid var(--gold-border) !important;
    font-weight:900 !important;
    box-shadow:none !important;
}

.btn-primary:hover,
.modal .btn-primary:hover,
.modal button[type="submit"]:hover{
    background:linear-gradient(180deg, #ffe28a, var(--gold-hover) 45%, #9d6d12) !important;
    color:#111827 !important;
    box-shadow:0 10px 22px rgba(210,164,60,.25) !important;
}

/* RESPONSIVE */
@media (max-width:1200px){
    html,
    body{
        overflow:auto;
    }

    .container-scroll{
        height:auto;
        min-height:100vh;
        overflow:visible;
    }

    .main-panel{
        height:auto;
        min-height:100vh;
        overflow:visible;
    }

    .content-wrapper{
        height:auto;
        min-height:calc(100vh - 70px);
        overflow:visible;
    }

    .pos-layout{
        height:auto;
        grid-template-columns:1fr;
        overflow:visible;
    }

    .items-area,
    .cart-area{
        height:auto;
        min-height:600px;
    }

    .items-grid{
        max-height:620px;
    }

    .cart-items{
        max-height:420px;
    }
}

@media (max-width:768px){
    .sidebar{
        display:none;
    }

    .topbar{
        height:auto;
        min-height:64px;
        max-height:none;
        padding:10px;
    }

    .content-wrapper{
        padding:10px;
    }

    .payment-row{
        grid-template-columns:repeat(2,1fr);
    }

    .discount-tax-wrap{
        grid-template-columns:1fr;
    }

    .items-grid{
        grid-template-columns:repeat(auto-fill,minmax(145px,1fr));
    }

    .selected-label{
        font-size:22px;
    }

    .search-wrap{
        width:100%;
        min-width:100%;
        margin-left:0;
    }

    .inline-custom-product{
        grid-template-columns:1fr;
    }

    .inline-custom-input{
        border-right:none;
        border-bottom:1px solid rgba(17,24,39,.08);
        text-align:left;
    }

    .inline-custom-input.qty{
        text-align:left;
    }

    .pos-cart-item{
        align-items:flex-start;
        flex-direction:column;
    }

    .pos-cart-right{
        width:100%;
        min-width:100%;
        align-items:stretch;
        text-align:left;
    }

    .pos-cart-price{
        text-align:left;
    }

    .pos-cart-total{
        text-align:right;
    }
}

/* =========================================================
   POS SCREEN FINAL HEIGHT FIX
   Bottom content hide nahi hoga - cart compact + internal scroll
========================================================= */

html,
body{
    height:100%;
    overflow:hidden !important;
}

.container-scroll{
    height:100dvh !important;
    max-height:100dvh !important;
    overflow:hidden !important;
}

.main-panel{
    height:100dvh !important;
    max-height:100dvh !important;
    overflow:hidden !important;
}

.topbar{
    height:64px !important;
    min-height:64px !important;
    max-height:64px !important;
}

.content-wrapper{
    height:calc(100dvh - 64px) !important;
    min-height:0 !important;
    padding:10px 12px !important;
    overflow:hidden !important;
}

.pos-layout{
    height:100% !important;
    min-height:0 !important;
    grid-template-columns:minmax(0, 1fr) 590px !important;
    gap:12px !important;
    overflow:hidden !important;
}

.items-area,
.cart-area{
    height:100% !important;
    min-height:0 !important;
    overflow:hidden !important;
    border-radius:18px !important;
}

.items-area{
    padding:12px !important;
}

.cart-area{
    padding:12px !important;
    display:flex !important;
    flex-direction:column !important;
    gap:0 !important;
}

/* LEFT ITEMS HEADER COMPACT */
.toolbar{
    margin-bottom:9px !important;
    gap:8px !important;
}

.type-btn{
    padding:9px 13px !important;
    min-width:105px !important;
    border-radius:11px !important;
}

.selected-label{
    font-size:26px !important;
    line-height:1 !important;
}

.categories{
    margin-bottom:9px !important;
    gap:7px !important;
}

.category-btn{
    padding:8px 13px !important;
}

.search-input{
    padding:9px 12px !important;
}

.items-grid{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* RIGHT CART COMPACT */
.cart-top{
    margin-bottom:7px !important;
    flex-shrink:0 !important;
}

.cart-title{
    font-size:20px !important;
    line-height:1.1 !important;
}

.icon-btn{
    width:34px !important;
    height:34px !important;
    min-width:34px !important;
}

.customer-box{
    padding:8px 10px !important;
    margin-bottom:7px !important;
    border-radius:12px !important;
    font-size:14px !important;
    flex-shrink:0 !important;
}

.customer-box .mt-1{
    margin-top:2px !important;
}

.cart-search{
    height:40px !important;
    padding:8px 11px !important;
    margin-bottom:7px !important;
    border-radius:11px !important;
    flex-shrink:0 !important;
}

/* Cart empty / items area ko zyada height nahi lene deni */
.cart-items{
    flex:1 1 auto !important;
    min-height:58px !important;
    max-height:none !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding-right:5px !important;
}

.empty-state{
    padding:22px 10px !important;
    border-radius:14px !important;
}

/* Summary compact */
.summary{
    flex-shrink:0 !important;
    margin-top:7px !important;
    padding-top:8px !important;
}

.summary-row{
    margin-bottom:4px !important;
    font-size:13px !important;
    line-height:1.25 !important;
}

.summary-row.total{
    font-size:16px !important;
    margin-top:4px !important;
}

/* Custom Product compact */
.custom-product-box{
    flex-shrink:0 !important;
    margin-top:8px !important;
    padding:10px !important;
    border-radius:14px !important;
}

.custom-product-title{
    font-size:16px !important;
    margin-bottom:8px !important;
    gap:9px !important;
    line-height:1 !important;
}

.custom-product-title .plus{
    width:32px !important;
    height:32px !important;
    border-radius:10px !important;
    font-size:20px !important;
}

.inline-custom-product{
    grid-template-columns:1.15fr 82px 52px 72px !important;
    border-radius:13px !important;
}

.inline-custom-input,
.inline-custom-btn{
    height:38px !important;
    font-size:12px !important;
}

/* Discount / Tax compact */
.discount-tax-wrap{
    flex-shrink:0 !important;
    margin:8px 0 8px !important;
    gap:8px !important;
}

.field-dark{
    height:39px !important;
    padding:8px 11px !important;
    border-radius:11px !important;
}

/* Payment + Invoice compact */
.payment-row{
    flex-shrink:0 !important;
    gap:7px !important;
    margin-bottom:8px !important;
}

.pay-btn{
    padding:9px 6px !important;
    min-height:39px !important;
    border-radius:11px !important;
    font-size:13px !important;
}

.invoice-btn{
    flex-shrink:0 !important;
    padding:12px 14px !important;
    min-height:48px !important;
    border-radius:15px !important;
    font-size:18px !important;
    line-height:1.1 !important;
}

/* Agar browser height choti ho to aur compact */
@media (max-height:760px){
    .topbar{
        height:58px !important;
        min-height:58px !important;
        max-height:58px !important;
    }

    .content-wrapper{
        height:calc(100dvh - 58px) !important;
        padding:8px 10px !important;
    }

    .cart-area{
        padding:10px !important;
    }

    .cart-title{
        font-size:18px !important;
    }

    .customer-box{
        padding:7px 9px !important;
        margin-bottom:6px !important;
    }

    .cart-search{
        height:36px !important;
        margin-bottom:6px !important;
    }

    .empty-state{
        padding:18px 10px !important;
    }

    .summary{
        margin-top:6px !important;
        padding-top:7px !important;
    }

    .summary-row{
        font-size:12.5px !important;
        margin-bottom:3px !important;
    }

    .summary-row.total{
        font-size:15px !important;
    }

    .custom-product-box{
        margin-top:6px !important;
        padding:8px !important;
    }

    .custom-product-title{
        font-size:15px !important;
        margin-bottom:7px !important;
    }

    .custom-product-title .plus{
        width:30px !important;
        height:30px !important;
    }

    .inline-custom-input,
    .inline-custom-btn{
        height:35px !important;
    }

    .discount-tax-wrap{
        margin:7px 0 !important;
    }

    .field-dark{
        height:36px !important;
    }

    .pay-btn{
        min-height:36px !important;
        padding:8px 5px !important;
    }

    .invoice-btn{
        min-height:44px !important;
        padding:10px 12px !important;
        font-size:17px !important;
    }
}

/* Laptop 1366/1536 width ke liye cart thora compact */
@media (max-width:1450px){
    .pos-layout{
        grid-template-columns:minmax(0, 1fr) 385px !important;
    }

    .cart-title{
        font-size:19px !important;
    }

    .custom-product-title{
        font-size:15.5px !important;
    }
}

/* Mobile / small screens par normal page scroll allow */
@media (max-width:1200px){
    html,
    body{
        overflow:auto !important;
    }

    .container-scroll,
    .main-panel{
        height:auto !important;
        max-height:none !important;
        overflow:visible !important;
    }

    .content-wrapper{
        height:auto !important;
        min-height:calc(100dvh - 64px) !important;
        overflow:visible !important;
    }

    .pos-layout{
        height:auto !important;
        grid-template-columns:1fr !important;
        overflow:visible !important;
    }

    .items-area,
    .cart-area{
        height:auto !important;
        min-height:auto !important;
        overflow:visible !important;
    }

    .items-grid,
    .cart-items{
        max-height:450px !important;
        overflow-y:auto !important;
    }
}

.content-wrapper{
    height:calc(100dvh - 74px) !important;
    padding:10px 12px 9px 12px !important;
    overflow:hidden !important;
}

/* POS layout ko full height mein but topbar se neeche fit karo */
.pos-layout{
    height:100% !important;
    min-height:0 !important;
    gap:10px !important;
}

/* Dono main boxes ko thora compact + top se safe */
.items-area,
.cart-area{
    height:100% !important;
    min-height:0 !important;
    border-radius:17px !important;
}

/* Left content ko top se thora compact karo */
.items-area{
    padding:10px 12px !important;
}

.toolbar{
    margin-bottom:7px !important;
    gap:7px !important;
}

.type-btn{
    height:38px !important;
    min-height:38px !important;
    padding:7px 14px !important;
    line-height:1 !important;
}

.selected-label{
    font-size:24px !important;
    line-height:1 !important;
}

.chip{
    height:36px !important;
    min-height:36px !important;
    padding:7px 11px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.categories{
    margin-bottom:8px !important;
}

.category-btn{
    height:36px !important;
    min-height:36px !important;
    padding:7px 12px !important;
    line-height:1 !important;
}

.search-input{
    height:38px !important;
    padding:7px 12px !important;
}

/* Right cart bhi top se compact */
.cart-area{
    padding:10px !important;
}

.cart-top{
    margin-bottom:6px !important;
}

.cart-title{
    font-size:18px !important;
    line-height:1 !important;
}

.status-dot{
    width:12px !important;
    height:12px !important;
}

.customer-box{
    padding:7px 10px !important;
    margin-bottom:6px !important;
}

.cart-search{
    height:36px !important;
    padding:7px 11px !important;
    margin-bottom:6px !important;
}

.empty-state{
    padding:18px 10px !important;
}

/* Cart middle ko flexible rakho */
.cart-items{
    flex:1 1 auto !important;
    min-height:45px !important;
    overflow-y:auto !important;
}

/* Bottom controls aur compact */
.summary{
    margin-top:6px !important;
    padding-top:7px !important;
}

.summary-row{
    font-size:12.5px !important;
    margin-bottom:3px !important;
    line-height:1.15 !important;
}

.summary-row.total{
    font-size:15px !important;
    margin-top:3px !important;
}

.custom-product-box{
    margin-top:6px !important;
    padding:8px !important;
}

.custom-product-title{
    font-size:15px !important;
    margin-bottom:6px !important;
}

.custom-product-title .plus{
    width:29px !important;
    height:29px !important;
}

.inline-custom-input,
.inline-custom-btn{
    height:35px !important;
}

.discount-tax-wrap{
    margin:7px 0 !important;
}

.field-dark{
    height:35px !important;
}

.payment-row{
    margin-bottom:7px !important;
}

.pay-btn{
    min-height:36px !important;
    padding:7px 5px !important;
}

.invoice-btn{
    min-height:43px !important;
    padding:9px 12px !important;
    font-size:17px !important;
}

/* Left product area ko available height mein scrollable rakho */
.items-grid{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
}

/* Choti laptop height ke liye aur safe */
@media (max-height:760px){
    .topbar{
        height:70px !important;
        min-height:70px !important;
        max-height:70px !important;
    }

    .content-wrapper{
        height:calc(100dvh - 70px) !important;
        padding-top:8px !important;
        padding-bottom:8px !important;
    }

    .type-btn{
        height:36px !important;
        min-height:36px !important;
    }

    .selected-label{
        font-size:22px !important;
    }

    .category-btn,
    .chip,
    .search-input{
        height:35px !important;
        min-height:35px !important;
    }

    .cart-title{
        font-size:17px !important;
    }

    .customer-box{
        padding:6px 9px !important;
    }

    .cart-search,
    .field-dark,
    .inline-custom-input,
    .inline-custom-btn{
        height:34px !important;
    }

    .invoice-btn{
        min-height:41px !important;
        font-size:16px !important;
    }
}



/* Price tier select modal */
.tier-modal-card{
    background:#131b2b;
    color:#fff;
    border:1px solid rgba(210,164,60,.42);
    border-radius:20px;
    box-shadow:0 24px 80px rgba(0,0,0,.45);
}
.tier-option-btn{
    width:100%;
    border:1px solid rgba(210,164,60,.35);
    background:#0f1625;
    color:#fff;
    border-radius:14px;
    padding:13px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px;
    font-weight:900;
    transition:.18s ease;
}
.tier-option-btn:hover{
    background:linear-gradient(180deg, var(--gold-light), var(--gold-main) 45%, var(--gold-dark));
    color:#111827;
}
.tier-option-price{
    font-size:18px;
}
.pos-cart-tier-badge{
    display:inline-flex;
    width:max-content;
    margin-top:3px;
    padding:3px 8px;
    border-radius:999px;
    background:rgba(210,164,60,.16);
    color:#f6d56f;
    border:1px solid rgba(210,164,60,.28);
    font-size:11px;
    font-weight:900;
}

/* =========================================================
   CART PRICE INPUT ALIGNMENT FIX
   Price + spinner ke darmiyan proper spacing
========================================================= */

.pos-cart-item{
    align-items:center !important;
    gap:12px !important;
}

.pos-cart-left{
    flex:1 1 auto !important;
    min-width:0 !important;
}

.pos-cart-right{
    width:128px !important;
    min-width:128px !important;
    max-width:128px !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:flex-end !important;
    justify-content:center !important;
    gap:6px !important;
    padding-left:8px !important;
}

.pos-cart-price{
    width:118px !important;
    max-width:118px !important;
    height:34px !important;
    padding:0 24px 0 10px !important;
    border-radius:9px !important;
    text-align:right !important;
    font-size:14px !important;
    font-weight:800 !important;
    line-height:34px !important;
    background:#111827 !important;
    color:#fff !important;
}

.pos-cart-price[type=number]{
    -moz-appearance:auto !important;
    appearance:auto !important;
}

.pos-cart-price[type=number]::-webkit-inner-spin-button,
.pos-cart-price[type=number]::-webkit-outer-spin-button{
    -webkit-appearance:auto !important;
    appearance:auto !important;
    opacity:1 !important;
    margin-left:6px !important;
}

.pos-cart-total{
    width:118px !important;
    text-align:right !important;
    margin-top:0 !important;
    font-size:13px !important;
    font-weight:900 !important;
    color:var(--gold-light) !important;
    white-space:nowrap !important;
}

/* Qty buttons alignment */
.pos-cart-controls{
    display:flex !important;
    align-items:center !important;
    gap:7px !important;
}

.pos-cart-qty{
    width:50px !important;
    min-width:50px !important;
    height:30px !important;
    text-align:center !important;
}

/* Small screen pe full width */
@media (max-width:768px){
    .pos-cart-right{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        padding-left:0 !important;
        align-items:flex-end !important;
    }

    .pos-cart-price,
    .pos-cart-total{
        width:130px !important;
        max-width:130px !important;
    }
}



/* =========================================================
   NEW POS UI THEME - Purple / Dark Premium
   Backend + JS logic unchanged. This block only overrides UI.
========================================================= */
:root{
    --bg:#060912 !important;
    --panel:#0b1020 !important;
    --panel-2:#10182b !important;
    --panel-3:#121c33 !important;
    --text:#eef4ff !important;
    --muted:#8896ad !important;
    --line:rgba(148,163,184,.14) !important;
    --gold:#7c3aed !important;
    --gold-main:#7c3aed !important;
    --gold-light:#9f67ff !important;
    --gold-dark:#5b21b6 !important;
    --gold-hover:#8b5cf6 !important;
    --gold-border:rgba(124,58,237,.55) !important;
    --danger:#ef4444 !important;
    --chip:#111827 !important;
    --shadow:0 18px 50px rgba(0,0,0,.32) !important;
}

body{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 34%),
        radial-gradient(circle at 88% 8%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#050816 0%,#070b14 100%) !important;
    color:#e5edf8 !important;
}

.sidebar{
    width:260px !important;
    min-width:260px !important;
    background:#070b14 !important;
    border-right:1px solid rgba(148,163,184,.10) !important;
}

.main-panel,
.content-wrapper{
    background:#070b14 !important;
}

.topbar{
    height:72px !important;
    min-height:72px !important;
    max-height:72px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

.content-wrapper{
    height:calc(100dvh - 72px) !important;
    padding:12px !important;
}

.pos-layout{
    grid-template-columns:minmax(0,1fr) 590px !important;
    gap:14px !important;
}

.items-area,
.cart-area{
    background:#0b1020 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-radius:18px !important;
    box-shadow:0 20px 60px rgba(0,0,0,.24) !important;
}

.items-area{ padding:16px !important; }
.cart-area{ padding:16px !important; }

.toolbar{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin-bottom:12px !important;
}

.type-buttons{
    gap:8px !important;
    flex-wrap:nowrap !important;
}

.type-btn{
    min-width:118px !important;
    height:42px !important;
    padding:0 18px !important;
    border-radius:8px !important;
    background:#0a0f1d !important;
    color:#d6deee !important;
    border:1px solid rgba(148,163,184,.14) !important;
    box-shadow:none !important;
    font-size:14px !important;
    font-weight:800 !important;
}

.type-btn.active,
.type-btn:hover{
    background:linear-gradient(135deg,#7c3aed,#6d28d9) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.85) !important;
    box-shadow:0 12px 28px rgba(124,58,237,.28) !important;
}

.selected-label{
    display:none !important;
}

.toolbar-right{
    margin-left:auto !important;
}

.chip{
    height:42px !important;
    min-height:42px !important;
    padding:0 14px !important;
    background:#0a0f1d !important;
    border:1px solid rgba(148,163,184,.14) !important;
    color:#d6deee !important;
    border-radius:8px !important;
    box-shadow:none !important;
}

.chip:first-child{
    min-width:130px !important;
    justify-content:center !important;
}

.categories{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) auto !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:12px !important;
}

.category-btn{
    display:none !important;
}

.category-btn.active{
    display:flex !important;
    width:142px !important;
    height:42px !important;
    order:2 !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:8px !important;
    background:#111827 !important;
    color:#d7deeb !important;
    border:1px solid rgba(148,163,184,.14) !important;
    box-shadow:none !important;
}

.category-btn.active::after{
    content:'⌄';
    margin-left:10px;
    color:#9ca3af;
}

.search-wrap{
    order:1 !important;
    width:100% !important;
    min-width:0 !important;
    margin-left:0 !important;
}

.search-input,
.cart-search,
.field-dark{
    height:42px !important;
    background:#060b16 !important;
    border:1px solid rgba(148,163,184,.14) !important;
    border-radius:8px !important;
    color:#e5edf8 !important;
    box-shadow:none !important;
}

.search-input::placeholder,
.cart-search::placeholder,
.field-dark::placeholder{
    color:#7f8ba3 !important;
}

.search-input:focus,
.cart-search:focus,
.field-dark:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.18) !important;
}

.items-grid{
    grid-template-columns:repeat(auto-fill,minmax(190px,1fr)) !important;
    gap:14px !important;
    padding-right:6px !important;
}

.item-card{
    min-height:214px !important;
    padding:0 !important;
    overflow:hidden !important;
    background:#0f172a !important;
    border:1px solid rgba(148,163,184,.13) !important;
    border-radius:10px !important;
    box-shadow:none !important;
}

.item-card:hover{
    transform:translateY(-2px) !important;
    border-color:rgba(124,58,237,.75) !important;
    box-shadow:0 16px 34px rgba(0,0,0,.28), 0 0 0 1px rgba(124,58,237,.16) !important;
}

.item-img{
    height:92px !important;
    width:100% !important;
    margin:0 !important;
    border-radius:0 !important;
    background:#0a0f1d !important;
    position:relative !important;
    overflow:hidden !important;
}

.item-img::after{
    content:'In Stock';
    position:absolute;
    top:8px;
    left:8px;
    padding:3px 8px;
    border-radius:4px;
    background:#16a34a;
    color:#fff;
    font-size:10px;
    font-weight:800;
}

.item-img img{
    width:100% !important;
    height:100% !important;
    max-width:none !important;
    max-height:none !important;
    object-fit:cover !important;
    opacity:.86 !important;
}

.item-title{
    min-height:37px !important;
    padding:10px 12px 0 !important;
    color:#edf2ff !important;
    font-size:13px !important;
    line-height:1.35 !important;
}

.item-sku{
    padding:2px 12px 0 !important;
    color:#8896ad !important;
    font-size:11px !important;
}

.item-tax-badge{
    margin-left:12px !important;
    background:rgba(124,58,237,.16) !important;
    border:1px solid rgba(124,58,237,.35) !important;
    color:#c4b5fd !important;
}

.item-bottom{
    padding:8px 12px 12px !important;
    align-items:center !important;
}

.item-price{
    font-size:19px !important;
    color:#f8fafc !important;
}

.item-price small{
    color:#cbd5e1 !important;
    display:inline !important;
    margin-right:3px !important;
    font-size:12px !important;
}

.add-btn,
.pos-cart-btn,
.custom-product-title .plus,
.inline-custom-btn,
.invoice-btn,
.btn-primary,
.modal .btn-primary,
.modal button[type="submit"]{
    background:linear-gradient(135deg,#7c3aed,#6d28d9) !important;
    color:#fff !important;
    border:1px solid rgba(139,92,246,.7) !important;
    box-shadow:0 12px 26px rgba(124,58,237,.25) !important;
}

.add-btn{
    width:34px !important;
    height:34px !important;
    border-radius:7px !important;
    font-size:22px !important;
}

.cart-area{
    background:#090f1c !important;
}

.cart-top{
    margin-bottom:12px !important;
    padding-bottom:10px !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

.cart-title{
    font-size:17px !important;
    color:#f3f6ff !important;
}

.status-dot{
    width:0 !important;
    height:0 !important;
    box-shadow:none !important;
}

.status-dot::before{
    content:'♙';
    color:#7c3aed;
    font-size:22px;
    line-height:1;
}

.icon-btn{
    width:38px !important;
    height:38px !important;
    background:#0f172a !important;
    border:1px solid rgba(148,163,184,.14) !important;
    color:#cbd5e1 !important;
    border-radius:8px !important;
    box-shadow:none !important;
}

.icon-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
}

.customer-box{
    padding:0 !important;
    margin-bottom:10px !important;
    border:0 !important;
    background:transparent !important;
    color:#e8eefc !important;
}

.customer-box strong{
    color:#fff !important;
}

.customer-box .muted small{
    display:block !important;
    height:39px !important;
    line-height:39px !important;
    margin-top:8px !important;
    padding:0 12px !important;
    border-radius:8px !important;
    background:#060b16 !important;
    border:1px solid rgba(148,163,184,.14) !important;
    color:#8b98ad !important;
}

.cart-items{
    padding-right:4px !important;
}

.pos-cart-item{
    background:transparent !important;
    border:0 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-radius:0 !important;
    padding:9px 0 !important;
    box-shadow:none !important;
}

.pos-cart-title{
    color:#f0f5ff !important;
    font-size:12.5px !important;
}

.pos-cart-sku,
.pos-cart-badge{
    color:#8b98ad !important;
    font-size:11px !important;
}

.pos-cart-controls{
    gap:4px !important;
}

.pos-cart-btn{
    width:28px !important;
    height:28px !important;
    border-radius:4px !important;
    background:#111827 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    color:#dbe5f5 !important;
    box-shadow:none !important;
}

.pos-cart-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
}

.pos-cart-qty,
.pos-cart-price{
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.10) !important;
    color:#e5edf8 !important;
    border-radius:5px !important;
}

.pos-cart-total{
    color:#f8fafc !important;
    font-size:12.5px !important;
}

.summary{
    border-top:1px solid rgba(148,163,184,.10) !important;
}

.summary-row{
    color:#c6d0df !important;
}

.summary-row.total{
    padding-top:8px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    color:#fff !important;
    font-size:20px !important;
}

.summary-row.total span:last-child{
    color:#a855f7 !important;
    font-size:22px !important;
}

.custom-product-box{
    background:transparent !important;
    border:1px dashed rgba(148,163,184,.18) !important;
    border-radius:8px !important;
}

.custom-product-title{
    color:#cbd5e1 !important;
    justify-content:center !important;
    font-weight:700 !important;
}

.custom-product-title .plus{
    width:22px !important;
    height:22px !important;
    border-radius:50% !important;
    font-size:16px !important;
    box-shadow:none !important;
}

.inline-custom-product{
    background:#060b16 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-radius:8px !important;
}

.inline-custom-input{
    background:#060b16 !important;
    color:#e5edf8 !important;
    border-right:1px solid rgba(148,163,184,.10) !important;
}

.inline-custom-btn{
    border-radius:0 !important;
    box-shadow:none !important;
}

.discount-tax-wrap{
    grid-template-columns:1fr 1fr !important;
    gap:8px !important;
}

.payment-row{
    gap:8px !important;
}

.pay-btn{
    min-height:44px !important;
    background:#0a0f1d !important;
    color:#dbe5f5 !important;
    border:1px solid rgba(148,163,184,.14) !important;
    border-radius:8px !important;
    box-shadow:none !important;
}

.pay-btn.active,
.pay-btn:hover{
    background:linear-gradient(135deg,#7c3aed,#6d28d9) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.75) !important;
    box-shadow:0 12px 26px rgba(124,58,237,.25) !important;
}

.invoice-btn{
    min-height:58px !important;
    border-radius:8px !important;
    font-size:15px !important;
    letter-spacing:.3px !important;
    text-transform:uppercase !important;
}

.items-grid::-webkit-scrollbar-thumb,
.cart-items::-webkit-scrollbar-thumb{
    background:rgba(124,58,237,.65) !important;
}

.modal-content,
.tier-modal-card{
    background:#0b1020 !important;
    color:#e5edf8 !important;
    border:1px solid rgba(148,163,184,.16) !important;
}

.tier-option-btn{
    background:#060b16 !important;
    border:1px solid rgba(148,163,184,.14) !important;
}

.tier-option-btn:hover{
    background:linear-gradient(135deg,#7c3aed,#6d28d9) !important;
    color:#fff !important;
}

@media (max-width:1450px){
    .pos-layout{ grid-template-columns:minmax(0,1fr) 395px !important; }
    .items-grid{ grid-template-columns:repeat(auto-fill,minmax(175px,1fr)) !important; }
    .type-btn{ min-width:104px !important; padding:0 12px !important; }
}

@media (max-height:760px){
    .topbar{ height:64px !important; min-height:64px !important; max-height:64px !important; }
    .content-wrapper{ height:calc(100dvh - 64px) !important; padding:10px !important; }
    .items-area,.cart-area{ padding:12px !important; }
    .item-img{ height:82px !important; }
    .item-card{ min-height:195px !important; }
    .invoice-btn{ min-height:48px !important; }
}


/* =========================================================
   EXACT REFERENCE CART AREA UI OVERRIDE
   Paste-safe: only cart-area look changed, POS logic unchanged
========================================================= */
.pos-layout{
    grid-template-columns:minmax(0,1fr) 590px !important;
}
.cart-area{
    background:#080d18 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-radius:18px !important;
    padding:14px !important;
    overflow:hidden !important;
    color:#eef4ff !important;
}
.ref-cart-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
    padding:0 0 10px !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    margin-bottom:10px !important;
    flex-shrink:0 !important;
}
.ref-cart-customer{
    min-width:0 !important;
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    font-size:18px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
}
.ref-cart-customer-icon{
    width:36px !important;
    height:36px !important;
    border-radius:12px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    color:#8b5cf6 !important;
    background:rgba(124,58,237,.10) !important;
    border:1px solid rgba(124,58,237,.24) !important;
    font-size:20px !important;
    flex-shrink:0 !important;
}
.ref-cart-change-btn,
.ref-clear-btn{
    border:1px solid rgba(148,163,184,.14) !important;
    background:#101827 !important;
    color:#dbe5f5 !important;
    height:38px !important;
    padding:0 14px !important;
    border-radius:8px !important;
    font-size:13px !important;
    font-weight:800 !important;
    transition:.18s ease !important;
}
.ref-cart-change-btn:hover,
.ref-clear-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.75) !important;
}
.ref-phone-wrap{
    position:relative !important;
    margin-bottom:12px !important;
    flex-shrink:0 !important;
}
.ref-phone-icon{
    position:absolute !important;
    left:13px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#64748b !important;
    z-index:2 !important;
}
.ref-phone-input{
    width:100% !important;
    height:44px !important;
    padding:0 14px 0 42px !important;
    border-radius:8px !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.13) !important;
    color:#dbeafe !important;
    outline:none !important;
}
.ref-phone-input:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.15) !important;
}
.ref-order-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:10px !important;
    margin:0 0 8px !important;
    flex-shrink:0 !important;
}
.ref-order-title{
    font-size:14px !important;
    letter-spacing:.4px !important;
    font-weight:900 !important;
    color:#cbd5e1 !important;
    text-transform:uppercase !important;
}
.ref-clear-btn{
    background:transparent !important;
    color:#fb7185 !important;
    border-color:rgba(244,63,94,.20) !important;
}
.ref-clear-btn:hover{
    background:rgba(244,63,94,.12) !important;
    color:#fb7185 !important;
}
.ref-cart-table-head{
    display:grid !important;
    grid-template-columns:minmax(160px,1.35fr) 80px 126px 96px 28px !important;
    gap:10px !important;
    align-items:center !important;
    height:42px !important;
    padding:0 10px !important;
    margin-bottom:4px !important;
    background:#101827 !important;
    border:1px solid rgba(148,163,184,.08) !important;
    border-radius:8px 8px 0 0 !important;
    color:#cbd5e1 !important;
    font-size:11px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
}
.cart-items{
    flex:1 1 auto !important;
    min-height:145px !important;
    padding-right:5px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}
.pos-cart-item.ref-row-ready{
    display:grid !important;
    grid-template-columns:minmax(160px,1.35fr) 80px 126px 96px 28px !important;
    gap:10px !important;
    align-items:center !important;
    min-height:76px !important;
    padding:10px !important;
    margin:0 !important;
    border:0 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-radius:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}
.ref-product-cell{
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    min-width:0 !important;
}
.ref-cart-img{
    width:54px !important;
    height:54px !important;
    min-width:54px !important;
    border-radius:8px !important;
    object-fit:cover !important;
    background:#111827 !important;
    border:1px solid rgba(148,163,184,.10) !important;
}
.pos-cart-left{
    min-width:0 !important;
    display:flex !important;
    flex-direction:column !important;
    gap:3px !important;
}
.pos-cart-title{
    color:#f8fafc !important;
    font-size:13.5px !important;
    line-height:1.25 !important;
    font-weight:900 !important;
    white-space:normal !important;
    display:-webkit-box !important;
    -webkit-line-clamp:2 !important;
    -webkit-box-orient:vertical !important;
    overflow:hidden !important;
}
.pos-cart-badge{
    color:#a78bfa !important;
    font-size:11px !important;
    font-weight:800 !important;
    background:transparent !important;
    padding:0 !important;
}
.pos-cart-sku{
    color:#94a3b8 !important;
    font-size:11px !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}
.ref-price-cell,
.ref-total-cell,
.ref-remove-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
}
.ref-qty-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}
.pos-cart-controls{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:0 !important;
    flex-wrap:nowrap !important;
}
.pos-cart-btn{
    width:32px !important;
    height:30px !important;
    min-width:32px !important;
    border-radius:0 !important;
    background:#0b1220 !important;
    border:1px solid rgba(148,163,184,.10) !important;
    color:#cbd5e1 !important;
    box-shadow:none !important;
    font-size:16px !important;
    font-weight:900 !important;
}
.pos-cart-btn:first-child{
    border-radius:6px 0 0 6px !important;
}
.pos-cart-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
}
.pos-cart-qty{
    width:42px !important;
    min-width:42px !important;
    height:30px !important;
    border-radius:0 !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.10) !important;
    border-left:0 !important;
    border-right:0 !important;
    color:#fff !important;
    text-align:center !important;
    font-size:13px !important;
    font-weight:900 !important;
}
.ref-remove-cell .pos-cart-btn,
.pos-cart-btn[data-cart-remove]{
    border-radius:50% !important;
    width:26px !important;
    height:26px !important;
    min-width:26px !important;
    background:transparent !important;
    color:#94a3b8 !important;
    border:1px solid rgba(148,163,184,.22) !important;
    font-size:13px !important;
}
.ref-remove-cell .pos-cart-btn:hover,
.pos-cart-btn[data-cart-remove]:hover{
    background:rgba(244,63,94,.12) !important;
    color:#fb7185 !important;
    border-color:rgba(244,63,94,.35) !important;
}
.pos-cart-right{
    width:auto !important;
    min-width:0 !important;
    max-width:none !important;
    padding-left:0 !important;
    display:contents !important;
}
.pos-cart-price{
    width:74px !important;
    max-width:74px !important;
    height:30px !important;
    padding:0 4px !important;
    background:transparent !important;
    border:0 !important;
    color:#f8fafc !important;
    text-align:center !important;
    font-size:13px !important;
    font-weight:900 !important;
    line-height:30px !important;
    box-shadow:none !important;
}
.pos-cart-total{
    width:96px !important;
    margin:0 !important;
    color:#f8fafc !important;
    text-align:right !important;
    font-size:14px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
}
.custom-product-box{
    flex-shrink:0 !important;
    margin-top:8px !important;
    padding:0 !important;
    background:transparent !important;
    border:0 !important;
}
.custom-product-title{
    height:54px !important;
    margin:0 0 8px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:12px !important;
    border:1px dashed rgba(148,163,184,.20) !important;
    border-radius:8px !important;
    color:#cbd5e1 !important;
    background:#070d19 !important;
    font-size:15px !important;
    font-weight:800 !important;
}
.custom-product-title .plus{
    width:26px !important;
    height:26px !important;
    border-radius:50% !important;
    font-size:18px !important;
    background:#111827 !important;
    color:#a855f7 !important;
    border:1px solid rgba(124,58,237,.40) !important;
    box-shadow:none !important;
}
.inline-custom-product{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) 90px 62px 96px !important;
    border-radius:6px !important;
    overflow:hidden !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.10) !important;
}
.inline-custom-input,
.inline-custom-btn{
    height:38px !important;
}
.inline-custom-input{
    background:#050a14 !important;
    color:#e5edf8 !important;
    border-right:1px solid rgba(148,163,184,.10) !important;
    font-size:12px !important;
}
.inline-custom-btn{
    background:linear-gradient(135deg,#8b5cf6,#7c3aed) !important;
    color:#fff !important;
    border:0 !important;
    font-size:13px !important;
    font-weight:900 !important;
}
.summary{
    flex-shrink:0 !important;
    margin-top:10px !important;
    padding:12px 0 0 !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
}
.summary-row{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    min-height:29px !important;
    margin:0 !important;
    color:#cbd5e1 !important;
    font-size:14px !important;
}
.summary-row.total{
    margin-top:8px !important;
    padding-top:13px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    color:#fff !important;
    font-size:19px !important;
    font-weight:900 !important;
}
.summary-row.total span:last-child{
    color:#a855f7 !important;
    font-size:28px !important;
    font-weight:950 !important;
}
.discount-tax-wrap{
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:10px !important;
    margin:10px 0 12px !important;
}
.field-dark{
    height:42px !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-radius:8px !important;
    color:#e5edf8 !important;
}
.payment-row{
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:8px !important;
    margin:0 0 12px !important;
}
.pay-btn{
    height:50px !important;
    min-height:50px !important;
    border-radius:8px !important;
    background:#0a0f1d !important;
    color:#f1f5f9 !important;
    border:1px solid rgba(148,163,184,.14) !important;
    font-size:13px !important;
    font-weight:900 !important;
    box-shadow:none !important;
}
.pay-btn.active,
.pay-btn:hover{
    background:linear-gradient(135deg,#8b5cf6,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.75) !important;
    box-shadow:0 12px 28px rgba(124,58,237,.28) !important;
}
.invoice-btn{
    flex-shrink:0 !important;
    min-height:64px !important;
    border-radius:8px !important;
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    border:1px solid rgba(139,92,246,.75) !important;
    box-shadow:0 18px 36px rgba(124,58,237,.32) !important;
    color:#fff !important;
    font-size:16px !important;
    font-weight:950 !important;
    letter-spacing:1px !important;
    text-transform:uppercase !important;
}
.empty-state{
    border:1px dashed rgba(148,163,184,.18) !important;
    background:#070d19 !important;
    border-radius:8px !important;
    color:#94a3b8 !important;
}
@media (max-width:1600px){
    .pos-layout{grid-template-columns:minmax(0,1fr) 490px !important;}
    .ref-cart-table-head,
    .pos-cart-item.ref-row-ready{grid-template-columns:minmax(150px,1.25fr) 74px 118px 88px 26px !important;gap:8px !important;}
    .pos-cart-total{width:88px !important;font-size:13px !important;}
    .pos-cart-price{width:70px !important;max-width:70px !important;}
}
@media (max-width:1450px){
    .pos-layout{grid-template-columns:minmax(0,1fr) 460px !important;}
    .ref-cart-table-head,
    .pos-cart-item.ref-row-ready{grid-template-columns:minmax(130px,1.1fr) 66px 108px 78px 24px !important;gap:7px !important;padding-left:8px !important;padding-right:8px !important;}
    .ref-cart-img{width:46px !important;height:46px !important;min-width:46px !important;}
    .pos-cart-title{font-size:12.5px !important;}
    .pos-cart-btn{width:28px !important;min-width:28px !important;height:28px !important;}
    .pos-cart-qty{width:36px !important;min-width:36px !important;height:28px !important;}
    .pos-cart-price{width:62px !important;max-width:62px !important;font-size:12px !important;}
    .pos-cart-total{width:78px !important;font-size:12px !important;}
    .summary-row.total span:last-child{font-size:24px !important;}
}
@media (max-height:760px){
    .cart-area{padding:10px !important;}
    .ref-cart-head{margin-bottom:7px !important;padding-bottom:7px !important;}
    .ref-phone-input{height:36px !important;}
    .ref-phone-wrap{margin-bottom:8px !important;}
    .ref-cart-table-head{height:34px !important;}
    .pos-cart-item.ref-row-ready{min-height:62px !important;padding-top:7px !important;padding-bottom:7px !important;}
    .ref-cart-img{width:42px !important;height:42px !important;min-width:42px !important;}
    .custom-product-title{height:42px !important;}
    .inline-custom-input,.inline-custom-btn,.field-dark{height:34px !important;}
    .summary{padding-top:7px !important;margin-top:7px !important;}
    .summary-row{min-height:24px !important;font-size:12.5px !important;}
    .summary-row.total{padding-top:8px !important;margin-top:5px !important;}
    .payment-row{margin-bottom:8px !important;}
    .pay-btn{height:40px !important;min-height:40px !important;}
    .invoice-btn{min-height:46px !important;}
}



/* =========================================================
   FINAL CART ONLY FIX - reference alignment + bottom visible
   Backend / product area logic untouched
========================================================= */
.cart-area{
    height:100% !important;
    max-height:100% !important;
    min-height:0 !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
    padding:12px 14px !important;
    background:#080d18 !important;
}
.cart-area .cart-search{
    display:none !important;
}
.ref-cart-head{
    height:50px !important;
    min-height:50px !important;
    padding:0 0 9px !important;
    margin:0 0 10px !important;
}
.ref-cart-customer{
    font-size:18px !important;
    line-height:1 !important;
}
.ref-cart-customer-icon{
    width:36px !important;
    height:36px !important;
    min-width:36px !important;
}
.ref-cart-change-btn{
    height:36px !important;
    min-width:78px !important;
}
.ref-phone-wrap{
    margin:0 0 10px !important;
    flex-shrink:0 !important;
}
.ref-phone-input{
    height:42px !important;
}
.ref-order-head{
    height:34px !important;
    min-height:34px !important;
    margin:0 0 6px !important;
}
.ref-order-title{
    font-size:16px !important;
    font-weight:950 !important;
    color:#d8e2f3 !important;
}
.ref-clear-btn{
    height:34px !important;
    padding:0 12px !important;
}
.ref-cart-table-head{
    flex-shrink:0 !important;
    height:38px !important;
    min-height:38px !important;
    margin:0 !important;
    padding:0 10px !important;
    grid-template-columns:minmax(0,1.45fr) 82px 126px 100px 28px !important;
    gap:10px !important;
}
.cart-items{
    flex:1 1 auto !important;
    min-height:92px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding-right:5px !important;
    margin:0 !important;
}
#cart.has-cart-items .empty-state{
    display:none !important;
}
.pos-cart-item.ref-row-ready{
    width:100% !important;
    min-height:74px !important;
    height:auto !important;
    display:grid !important;
    grid-template-columns:minmax(0,1.45fr) 82px 126px 100px 28px !important;
    gap:10px !important;
    align-items:center !important;
    padding:9px 10px !important;
    margin:0 !important;
    border:0 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-radius:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}
.ref-product-cell{
    display:grid !important;
    grid-template-columns:58px minmax(0,1fr) !important;
    align-items:center !important;
    column-gap:12px !important;
    min-width:0 !important;
    width:100% !important;
}
.ref-cart-img{
    width:56px !important;
    height:56px !important;
    min-width:56px !important;
    border-radius:7px !important;
    object-fit:cover !important;
    background:#111827 !important;
    border:1px solid rgba(148,163,184,.12) !important;
}
.ref-product-text{
    min-width:0 !important;
    display:flex !important;
    flex-direction:column !important;
    justify-content:center !important;
    gap:3px !important;
}
.ref-product-text .pos-cart-title{
    font-size:14px !important;
    line-height:1.22 !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    white-space:normal !important;
    display:-webkit-box !important;
    -webkit-line-clamp:2 !important;
    -webkit-box-orient:vertical !important;
    overflow:hidden !important;
}
.ref-product-text .pos-cart-badge{
    color:#a78bfa !important;
    font-size:11px !important;
    line-height:1.1 !important;
    font-weight:850 !important;
}
.ref-product-text .pos-cart-sku{
    color:#94a3b8 !important;
    font-size:11px !important;
    line-height:1.1 !important;
}
.ref-price-cell,
.ref-total-cell,
.ref-remove-cell,
.ref-qty-cell{
    min-width:0 !important;
    display:flex !important;
    align-items:center !important;
}
.ref-price-cell,
.ref-total-cell,
.ref-remove-cell{
    justify-content:flex-end !important;
}
.ref-qty-cell{
    justify-content:center !important;
}
.ref-price-cell::before{
    content:'Rs ';
    color:#f8fafc !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-right:4px !important;
}
.pos-cart-price{
    width:54px !important;
    max-width:54px !important;
    height:28px !important;
    padding:0 !important;
    background:transparent !important;
    border:0 !important;
    color:#f8fafc !important;
    text-align:left !important;
    font-size:13px !important;
    font-weight:900 !important;
    appearance:textfield !important;
    -moz-appearance:textfield !important;
}
.pos-cart-price::-webkit-inner-spin-button,
.pos-cart-price::-webkit-outer-spin-button{
    -webkit-appearance:none !important;
    appearance:none !important;
    margin:0 !important;
    opacity:0 !important;
}
.pos-cart-controls{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    flex-wrap:nowrap !important;
    gap:0 !important;
}
.pos-cart-btn{
    width:32px !important;
    min-width:32px !important;
    height:32px !important;
    border-radius:0 !important;
    background:#0b1220 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    color:#dbe5f5 !important;
    box-shadow:none !important;
}
.pos-cart-btn[data-cart-minus]{
    border-radius:6px 0 0 6px !important;
}
.pos-cart-btn[data-cart-plus]{
    border-radius:0 6px 6px 0 !important;
}
.pos-cart-qty{
    width:42px !important;
    min-width:42px !important;
    height:32px !important;
    border-radius:0 !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-left:0 !important;
    border-right:0 !important;
    color:#fff !important;
    font-size:14px !important;
    font-weight:900 !important;
    text-align:center !important;
}
.pos-cart-total{
    width:100px !important;
    margin:0 !important;
    color:#f8fafc !important;
    text-align:right !important;
    font-size:14px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
}
.ref-remove-cell .pos-cart-btn,
.pos-cart-btn[data-cart-remove]{
    width:24px !important;
    min-width:24px !important;
    height:24px !important;
    border-radius:50% !important;
    font-size:12px !important;
    background:transparent !important;
    color:#94a3b8 !important;
    border:1px solid rgba(148,163,184,.24) !important;
}
.summary{
    flex-shrink:0 !important;
    margin-top:8px !important;
    padding-top:10px !important;
}
.summary-row{
    min-height:26px !important;
    font-size:14px !important;
    line-height:1 !important;
}
.summary-row.total{
    min-height:46px !important;
    margin-top:5px !important;
    padding-top:10px !important;
    font-size:20px !important;
}
.summary-row.total span:last-child{
    font-size:30px !important;
    line-height:1 !important;
}
.custom-product-box{
    flex-shrink:0 !important;
    margin-top:8px !important;
}
.custom-product-title{
    height:48px !important;
    min-height:48px !important;
    margin-bottom:7px !important;
}
.inline-custom-product{
    grid-template-columns:minmax(0,1fr) 86px 56px 90px !important;
}
.inline-custom-input,
.inline-custom-btn{
    height:36px !important;
}
.discount-tax-wrap{
    flex-shrink:0 !important;
    margin:8px 0 9px !important;
}
.field-dark{
    height:38px !important;
}
.payment-row{
    flex-shrink:0 !important;
    margin-bottom:9px !important;
}
.pay-btn{
    height:44px !important;
    min-height:44px !important;
}
.invoice-btn{
    flex-shrink:0 !important;
    min-height:52px !important;
    height:52px !important;
    padding:0 16px !important;
}
@media (max-width:1450px){
    .cart-area{padding:10px 12px !important;}
    .ref-cart-head{height:46px !important;min-height:46px !important;margin-bottom:8px !important;}
    .ref-phone-input{height:38px !important;}
    .ref-phone-wrap{margin-bottom:8px !important;}
    .ref-order-head{height:30px !important;min-height:30px !important;}
    .ref-cart-table-head,
    .pos-cart-item.ref-row-ready{
        grid-template-columns:minmax(0,1.35fr) 68px 108px 84px 24px !important;
        gap:7px !important;
        padding-left:8px !important;
        padding-right:8px !important;
    }
    .pos-cart-item.ref-row-ready{min-height:62px !important;padding-top:7px !important;padding-bottom:7px !important;}
    .ref-product-cell{grid-template-columns:46px minmax(0,1fr) !important;column-gap:9px !important;}
    .ref-cart-img{width:44px !important;height:44px !important;min-width:44px !important;}
    .ref-product-text .pos-cart-title{font-size:12.5px !important;}
    .ref-product-text .pos-cart-badge,
    .ref-product-text .pos-cart-sku{font-size:10.5px !important;}
    .ref-price-cell::before{font-size:12px !important;margin-right:2px !important;}
    .pos-cart-price{width:42px !important;max-width:42px !important;font-size:12px !important;}
    .pos-cart-btn{width:28px !important;min-width:28px !important;height:28px !important;}
    .pos-cart-qty{width:34px !important;min-width:34px !important;height:28px !important;font-size:12px !important;}
    .pos-cart-total{width:84px !important;font-size:12px !important;}
    .summary{margin-top:6px !important;padding-top:8px !important;}
    .summary-row{min-height:23px !important;font-size:13px !important;}
    .summary-row.total{min-height:39px !important;font-size:19px !important;padding-top:8px !important;}
    .summary-row.total span:last-child{font-size:26px !important;}
    .custom-product-title{height:42px !important;min-height:42px !important;}
    .inline-custom-input,.inline-custom-btn{height:33px !important;}
    .field-dark{height:34px !important;}
    .pay-btn{height:38px !important;min-height:38px !important;}
    .invoice-btn{height:44px !important;min-height:44px !important;}
}
@media (max-height:760px){
    .cart-area{padding:8px 10px !important;}
    .ref-cart-head{height:42px !important;min-height:42px !important;padding-bottom:6px !important;margin-bottom:6px !important;}
    .ref-cart-customer-icon{width:32px !important;height:32px !important;min-width:32px !important;}
    .ref-phone-input{height:34px !important;}
    .ref-phone-wrap{margin-bottom:6px !important;}
    .ref-order-head{height:28px !important;min-height:28px !important;margin-bottom:4px !important;}
    .ref-order-title{font-size:14px !important;}
    .ref-cart-table-head{height:30px !important;min-height:30px !important;}
    .cart-items{min-height:72px !important;}
    .pos-cart-item.ref-row-ready{min-height:54px !important;padding-top:5px !important;padding-bottom:5px !important;}
    .ref-cart-img{width:38px !important;height:38px !important;min-width:38px !important;}
    .ref-product-cell{grid-template-columns:40px minmax(0,1fr) !important;}
    .summary-row{min-height:20px !important;font-size:12px !important;}
    .summary-row.total{min-height:34px !important;font-size:17px !important;margin-top:3px !important;padding-top:6px !important;}
    .summary-row.total span:last-child{font-size:24px !important;}
    .custom-product-title{height:36px !important;min-height:36px !important;margin-bottom:5px !important;}
    .inline-custom-input,.inline-custom-btn{height:30px !important;}
    .discount-tax-wrap{margin:6px 0 7px !important;}
    .field-dark{height:31px !important;}
    .payment-row{margin-bottom:7px !important;}
    .pay-btn{height:34px !important;min-height:34px !important;font-size:12px !important;}
    .invoice-btn{height:40px !important;min-height:40px !important;font-size:14px !important;}
}


/* =========================================================
   CART MICRO FIX - small columns + simple image + clean title
   Only cart area UI touched. Backend / product area untouched.
========================================================= */
.cart-area .ref-cart-table-head,
.cart-area .pos-cart-item.ref-row-ready{
    grid-template-columns:minmax(0,1.22fr) 74px 112px 86px 24px !important;
    gap:8px !important;
}
.cart-area .ref-cart-table-head span{
    font-size:10.5px !important;
    letter-spacing:.35px !important;
}
.cart-area .pos-cart-item.ref-row-ready{
    min-height:58px !important;
    padding:7px 8px !important;
}
.cart-area .ref-product-cell{
    grid-template-columns:42px minmax(0,1fr) !important;
    column-gap:9px !important;
    overflow:hidden !important;
}
.cart-area .ref-cart-img{
    width:38px !important;
    height:38px !important;
    min-width:38px !important;
    border-radius:6px !important;
    object-fit:contain !important;
    background:#0b1220 !important;
    border:0 !important;
    padding:2px !important;
    box-shadow:none !important;
}
.cart-area .ref-product-text,
.cart-area .pos-cart-left,
.cart-area .ref-product-cell{
    min-width:0 !important;
    max-width:100% !important;
}
.cart-area .ref-product-text .pos-cart-title{
    font-size:12px !important;
    line-height:1.15 !important;
    font-weight:900 !important;
    max-width:100% !important;
    white-space:nowrap !important;
    display:block !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}
.cart-area .ref-product-text .pos-cart-badge,
.cart-area .pos-cart-tier-badge{
    display:none !important;
}
.cart-area .ref-product-text .pos-cart-sku{
    margin-top:2px !important;
    font-size:10.5px !important;
    line-height:1.1 !important;
    color:#8fa1bb !important;
    max-width:100% !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}
.cart-area .ref-price-cell{
    justify-content:flex-start !important;
    overflow:hidden !important;
}
.cart-area .ref-price-cell::before{
    content:'Rs ' !important;
    font-size:11.5px !important;
    line-height:1 !important;
    margin-right:2px !important;
    color:#f8fafc !important;
    font-weight:900 !important;
}
.cart-area .pos-cart-price,
.cart-area .pos-cart-price[type=number],
.cart-area input[data-cart-price-input]{
    width:42px !important;
    max-width:42px !important;
    min-width:42px !important;
    height:24px !important;
    padding:0 !important;
    margin:0 !important;
    border:0 !important;
    outline:none !important;
    background:transparent !important;
    color:#f8fafc !important;
    text-align:left !important;
    font-size:11.5px !important;
    font-weight:900 !important;
    line-height:24px !important;
    box-shadow:none !important;
    appearance:textfield !important;
    -moz-appearance:textfield !important;
}
.cart-area .pos-cart-price::-webkit-inner-spin-button,
.cart-area .pos-cart-price::-webkit-outer-spin-button,
.cart-area input[data-cart-price-input]::-webkit-inner-spin-button,
.cart-area input[data-cart-price-input]::-webkit-outer-spin-button{
    -webkit-appearance:none !important;
    appearance:none !important;
    display:none !important;
    opacity:0 !important;
    margin:0 !important;
}
.cart-area .pos-cart-controls{
    transform:scale(.88) !important;
    transform-origin:center !important;
}
.cart-area .pos-cart-btn{
    width:28px !important;
    min-width:28px !important;
    height:28px !important;
    font-size:13px !important;
}
.cart-area .pos-cart-qty{
    width:34px !important;
    min-width:34px !important;
    height:28px !important;
    font-size:12px !important;
}
.cart-area .pos-cart-total{
    width:86px !important;
    font-size:12px !important;
    line-height:1 !important;
    text-align:right !important;
}
.cart-area .ref-remove-cell .pos-cart-btn,
.cart-area .pos-cart-btn[data-cart-remove]{
    width:22px !important;
    min-width:22px !important;
    height:22px !important;
    font-size:11px !important;
}
@media (max-width:1450px){
    .cart-area .ref-cart-table-head,
    .cart-area .pos-cart-item.ref-row-ready{
        grid-template-columns:minmax(0,1.15fr) 58px 98px 76px 22px !important;
        gap:6px !important;
    }
    .cart-area .ref-product-cell{
        grid-template-columns:34px minmax(0,1fr) !important;
        column-gap:7px !important;
    }
    .cart-area .ref-cart-img{
        width:32px !important;
        height:32px !important;
        min-width:32px !important;
    }
    .cart-area .ref-product-text .pos-cart-title{
        font-size:11.2px !important;
    }
    .cart-area .ref-product-text .pos-cart-sku{
        font-size:10px !important;
    }
    .cart-area .ref-price-cell::before,
    .cart-area .pos-cart-price,
    .cart-area .pos-cart-price[type=number],
    .cart-area input[data-cart-price-input]{
        font-size:11px !important;
    }
    .cart-area .pos-cart-price,
    .cart-area input[data-cart-price-input]{
        width:36px !important;
        max-width:36px !important;
        min-width:36px !important;
    }
    .cart-area .pos-cart-total{
        width:76px !important;
        font-size:11.5px !important;
    }
    .cart-area .pos-cart-controls{
        transform:scale(.84) !important;
    }
}


/* =========================================================
   PREMIUM POS CART AREA - Reference Inspired Dark SaaS UI
   Only right cart area affected
========================================================= */

:root{
    --pos-purple:#7c3aed;
    --pos-purple-2:#8b5cf6;
    --pos-purple-3:#6d28d9;
    --pos-bg:#070b17;
    --pos-panel:#0d1324;
    --pos-panel-2:#121a2d;
    --pos-panel-3:#101827;
    --pos-text:#ffffff;
    --pos-muted:#9aa4c7;
    --pos-border:rgba(255,255,255,0.06);
    --pos-border-2:rgba(148,163,184,0.14);
    --pos-danger:#ef4444;
}

/* Right cart width like reference */
.pos-layout{
    grid-template-columns:minmax(0,1fr) 390px !important;
    gap:14px !important;
}

.pos-premium-cart{
    height:100% !important;
    min-height:0 !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
    padding:14px !important;
    background:
        radial-gradient(circle at top right, rgba(124,58,237,.15), transparent 30%),
        linear-gradient(180deg,#0b1020 0%,#080d18 100%) !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-radius:18px !important;
    box-shadow:
        0 24px 70px rgba(0,0,0,.35),
        inset 0 1px 0 rgba(255,255,255,.03) !important;
    color:var(--pos-text) !important;
}

/* Hide old search */
.pos-premium-cart .cart-search{
    display:none !important;
}

/* Customer header */
.pos-cart-customer-header{
    flex-shrink:0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:0 0 10px;
    margin-bottom:10px;
    border-bottom:1px solid rgba(148,163,184,.10);
}

.pos-cart-customer-left{
    min-width:0;
    display:flex;
    align-items:center;
    gap:12px;
}

.pos-cart-customer-icon{
    width:34px;
    height:34px;
    min-width:34px;
    border-radius:11px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(124,58,237,.11);
    border:1px solid rgba(124,58,237,.28);
    color:#a78bfa;
    font-size:20px;
}

.pos-cart-customer-info{
    min-width:0;
}

.pos-cart-customer-info h4{
    margin:0;
    color:#f8fafc;
    font-size:15px;
    font-weight:900;
    line-height:1.1;
    max-width:210px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.pos-cart-customer-info small{
    display:block;
    margin-top:3px;
    color:#7f8ba3;
    font-size:11px;
    font-weight:600;
}

.pos-cart-change-btn{
    height:34px;
    padding:0 13px;
    border-radius:8px;
    border:1px solid rgba(148,163,184,.14);
    background:#111827;
    color:#dbe5f5;
    font-size:12px;
    font-weight:800;
    transition:.18s ease;
}

.pos-cart-change-btn:hover{
    background:linear-gradient(135deg,var(--pos-purple-2),var(--pos-purple));
    color:#fff;
    border-color:rgba(139,92,246,.75);
    box-shadow:0 12px 26px rgba(124,58,237,.25);
}

/* Phone input */
.pos-cart-phone-box{
    flex-shrink:0;
    position:relative;
    margin-bottom:12px;
}

.pos-phone-icon{
    position:absolute;
    left:13px;
    top:50%;
    transform:translateY(-50%);
    color:#64748b;
    font-size:14px;
    z-index:2;
}

.pos-cart-phone-input{
    width:100%;
    height:40px;
    padding:0 12px 0 40px;
    border-radius:8px;
    background:#060b16;
    border:1px solid rgba(148,163,184,.13);
    color:#e5edf8;
    font-size:13px;
    outline:none;
    transition:.18s ease;
}

.pos-cart-phone-input::placeholder{
    color:#7f8ba3;
}

.pos-cart-phone-input:focus{
    border-color:var(--pos-purple);
    box-shadow:0 0 0 3px rgba(124,58,237,.16);
}

/* Order title */
.pos-order-items-top{
    flex-shrink:0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:8px;
}

.pos-order-items-title{
    color:#d8e2f3;
    font-size:13px;
    font-weight:950;
    text-transform:uppercase;
    letter-spacing:.35px;
}

.pos-clear-cart-btn{
    height:30px;
    padding:0 10px;
    border-radius:8px;
    border:1px solid rgba(244,63,94,.22);
    background:rgba(244,63,94,.04);
    color:#fb7185;
    font-size:12px;
    font-weight:800;
    transition:.18s ease;
}

.pos-clear-cart-btn:hover{
    background:rgba(244,63,94,.13);
    border-color:rgba(244,63,94,.42);
}

/* Cart table head */
.pos-cart-table-head{
    flex-shrink:0;
    height:36px;
    display:grid;
    grid-template-columns:minmax(0,1.32fr) 58px 94px 76px 22px;
    gap:6px;
    align-items:center;
    padding:0 8px;
    background:#101827;
    border:1px solid rgba(148,163,184,.08);
    border-radius:8px 8px 0 0;
    color:#cbd5e1;
    font-size:10px;
    font-weight:950;
    text-transform:uppercase;
    letter-spacing:.35px;
}

/* Cart scroll */
.pos-cart-items-list{
    flex:1 1 auto !important;
    min-height:82px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding-right:4px !important;
    margin:0 !important;
}

.pos-cart-items-list::-webkit-scrollbar{
    width:5px;
}

.pos-cart-items-list::-webkit-scrollbar-thumb{
    background:rgba(124,58,237,.65);
    border-radius:20px;
}

.pos-cart-items-list::-webkit-scrollbar-track{
    background:transparent;
}

/* Dynamic row layout after JS enhancer */
.pos-cart-item.pos-ref-row{
    width:100%;
    min-height:58px;
    display:grid !important;
    grid-template-columns:minmax(0,1.32fr) 58px 94px 76px 22px;
    gap:6px;
    align-items:center;
    padding:7px 8px !important;
    margin:0 !important;
    border:0 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-radius:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}

.pos-ref-product-cell{
    min-width:0;
    display:grid !important;
    grid-template-columns:38px minmax(0,1fr);
    column-gap:8px;
    align-items:center;
}

.pos-ref-img{
    width:36px;
    height:36px;
    min-width:36px;
    object-fit:contain;
    padding:2px;
    border-radius:6px;
    background:#0b1220;
    border:1px solid rgba(148,163,184,.08);
}

.pos-ref-product-text{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:2px;
}

.pos-ref-product-text .pos-cart-title,
.pos-cart-item .pos-cart-title{
    color:#f8fafc !important;
    font-size:11.5px !important;
    line-height:1.15 !important;
    font-weight:900 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    max-width:100%;
}

.pos-ref-product-text .pos-cart-sku,
.pos-cart-item .pos-cart-sku{
    color:#8fa1bb !important;
    font-size:10px !important;
    line-height:1.1 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.pos-ref-product-text .pos-cart-badge,
.pos-cart-tier-badge{
    display:none !important;
}

/* Price cell */
.pos-ref-price-cell{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    min-width:0;
}

.pos-ref-price-cell::before{
    content:'Rs';
    margin-right:3px;
    color:#f8fafc;
    font-size:11px;
    font-weight:900;
}

.pos-ref-price-cell .pos-cart-price,
.pos-ref-price-cell input[data-cart-price-input]{
    width:36px !important;
    max-width:36px !important;
    min-width:36px !important;
    height:24px !important;
    padding:0 !important;
    border:0 !important;
    outline:none !important;
    background:transparent !important;
    color:#f8fafc !important;
    text-align:left !important;
    font-size:11px !important;
    font-weight:900 !important;
    appearance:textfield !important;
    -moz-appearance:textfield !important;
    box-shadow:none !important;
}

.pos-ref-price-cell input::-webkit-inner-spin-button,
.pos-ref-price-cell input::-webkit-outer-spin-button{
    -webkit-appearance:none !important;
    appearance:none !important;
    margin:0 !important;
    display:none !important;
}

/* Qty controls */
.pos-ref-qty-cell{
    display:flex;
    align-items:center;
    justify-content:center;
}

.pos-ref-qty-cell .pos-cart-controls{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:0 !important;
    transform:scale(.86);
    transform-origin:center;
}

.pos-ref-qty-cell .pos-cart-btn{
    width:28px !important;
    min-width:28px !important;
    height:28px !important;
    border-radius:0 !important;
    background:#0b1220 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    color:#dbe5f5 !important;
    box-shadow:none !important;
    font-size:13px !important;
    font-weight:900 !important;
    transition:.18s ease;
}

.pos-ref-qty-cell .pos-cart-btn:hover{
    background:var(--pos-purple) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.75) !important;
}

.pos-ref-qty-cell .pos-cart-btn[data-cart-minus]{
    border-radius:6px 0 0 6px !important;
}

.pos-ref-qty-cell .pos-cart-btn[data-cart-plus]{
    border-radius:0 6px 6px 0 !important;
}

.pos-ref-qty-cell .pos-cart-qty{
    width:34px !important;
    min-width:34px !important;
    height:28px !important;
    border-radius:0 !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-left:0 !important;
    border-right:0 !important;
    color:#fff !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-align:center !important;
}

/* Total */
.pos-ref-total-cell{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    min-width:0;
}

.pos-ref-total-cell .pos-cart-total,
.pos-ref-total-cell [data-line-total]{
    width:76px !important;
    margin:0 !important;
    color:#f8fafc !important;
    text-align:right !important;
    font-size:11.5px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
}

/* Remove */
.pos-ref-remove-cell{
    display:flex;
    align-items:center;
    justify-content:flex-end;
}

.pos-ref-remove-cell .pos-cart-btn,
.pos-ref-remove-cell [data-cart-remove]{
    width:21px !important;
    min-width:21px !important;
    height:21px !important;
    border-radius:50% !important;
    background:transparent !important;
    color:#94a3b8 !important;
    border:1px solid rgba(148,163,184,.22) !important;
    font-size:10px !important;
    box-shadow:none !important;
}

.pos-ref-remove-cell .pos-cart-btn:hover,
.pos-ref-remove-cell [data-cart-remove]:hover{
    background:rgba(244,63,94,.13) !important;
    color:#fb7185 !important;
    border-color:rgba(244,63,94,.40) !important;
}

/* Empty cart */
.pos-cart-empty,
.pos-premium-cart .empty-state{
    min-height:90px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    color:#94a3b8;
    border:1px dashed rgba(148,163,184,.18);
    background:#070d19;
    border-radius:8px;
    font-size:13px;
}

/* Add custom product */
.pos-add-custom-wrap{
    flex-shrink:0;
    margin-top:8px;
}

.pos-add-custom-main{
    width:100%;
    height:44px;
    border-radius:8px;
    border:1px dashed rgba(148,163,184,.22);
    background:#070d19;
    color:#cbd5e1;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    font-size:13px;
    font-weight:800;
    transition:.18s ease;
}

.pos-add-custom-main:hover{
    border-color:rgba(124,58,237,.55);
    color:#fff;
    box-shadow:0 0 0 3px rgba(124,58,237,.12);
}

.pos-add-custom-icon{
    width:22px;
    height:22px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#111827;
    color:#a855f7;
    border:1px solid rgba(124,58,237,.38);
    font-size:16px;
    font-weight:900;
}

.pos-custom-product-form{
    display:none;
    grid-template-columns:minmax(0,1fr) 70px 52px 64px;
    overflow:hidden;
    margin-top:7px;
    border-radius:8px;
    border:1px solid rgba(148,163,184,.11);
    background:#050a14;
}

.pos-custom-product-form.show{
    display:grid;
}

.pos-custom-input{
    height:34px;
    width:100%;
    border:0;
    outline:none;
    padding:0 9px;
    background:#050a14;
    color:#e5edf8;
    font-size:12px;
    font-weight:700;
    border-right:1px solid rgba(148,163,184,.10);
}

.pos-custom-input::placeholder{
    color:#7f8ba3;
    font-weight:600;
}

.pos-custom-add-btn{
    height:34px;
    border:0;
    background:linear-gradient(135deg,var(--pos-purple-2),var(--pos-purple));
    color:#fff;
    font-size:12px;
    font-weight:950;
}

/* Billing summary */
.pos-billing-summary{
    flex-shrink:0;
    margin-top:8px !important;
    padding-top:10px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
}

.pos-summary-row{
    min-height:25px !important;
    margin:0 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    color:#cbd5e1 !important;
    font-size:13px !important;
    line-height:1 !important;
}

.pos-summary-row span{
    color:#cbd5e1;
}

.pos-summary-row strong{
    color:#f8fafc;
    font-size:13px;
    font-weight:850;
}

.pos-discount-inline{
    height:30px;
    display:flex;
    align-items:center;
    overflow:hidden;
    border-radius:7px;
    background:#050a14;
    border:1px solid rgba(148,163,184,.10);
}

.pos-discount-pill{
    width:34px;
    height:30px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,var(--pos-purple-2),var(--pos-purple));
    color:#fff !important;
    font-size:12px;
    font-weight:900;
}

.pos-hidden-discount{
    width:92px !important;
    height:30px !important;
    border:0 !important;
    background:#050a14 !important;
    color:#e5edf8 !important;
    text-align:right !important;
    padding:0 9px !important;
    border-radius:0 !important;
    font-size:12px !important;
    box-shadow:none !important;
}

.pos-grand-total-row{
    min-height:44px !important;
    margin-top:5px !important;
    padding-top:10px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
}

.pos-grand-total-row span{
    color:#fff !important;
    font-size:17px !important;
    font-weight:950 !important;
}

.pos-grand-total-row strong{
    color:#a855f7 !important;
    font-size:25px !important;
    font-weight:950 !important;
    letter-spacing:.5px;
}

/* Payment buttons */
.pos-payment-methods{
    flex-shrink:0;
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:8px !important;
    margin:9px 0 10px !important;
}

.pos-payment-methods .pay-btn{
    height:44px !important;
    min-height:44px !important;
    border-radius:8px !important;
    background:#0a0f1d !important;
    color:#dbe5f5 !important;
    border:1px solid rgba(148,163,184,.14) !important;
    font-size:11.5px !important;
    font-weight:950 !important;
    box-shadow:none !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;
    transition:.18s ease !important;
}

.pos-payment-methods .pay-btn span{
    font-size:13px;
}

.pos-payment-methods .pay-btn.active,
.pos-payment-methods .pay-btn:hover{
    background:linear-gradient(135deg,var(--pos-purple-2),var(--pos-purple-3)) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.75) !important;
    box-shadow:0 12px 28px rgba(124,58,237,.26) !important;
}

/* Proceed button */
.pos-proceed-btn{
    flex-shrink:0 !important;
    width:100% !important;
    height:52px !important;
    min-height:52px !important;
    border-radius:8px !important;
    border:1px solid rgba(139,92,246,.75) !important;
    background:linear-gradient(135deg,var(--pos-purple-2),var(--pos-purple-3)) !important;
    color:#fff !important;
    font-size:14px !important;
    font-weight:950 !important;
    letter-spacing:.6px !important;
    text-transform:uppercase !important;
    box-shadow:0 18px 36px rgba(124,58,237,.32) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:12px !important;
    transition:.18s ease !important;
}

.pos-proceed-btn:hover{
    transform:translateY(-1px);
    box-shadow:0 22px 42px rgba(124,58,237,.40) !important;
}

.pos-proceed-arrow{
    font-size:20px;
    line-height:1;
}

/* Compact screens */
@media (max-width:1450px){
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 370px !important;
    }

    .pos-premium-cart{
        padding:10px !important;
    }

    .pos-cart-table-head,
    .pos-cart-item.pos-ref-row{
        grid-template-columns:minmax(0,1.22fr) 52px 82px 68px 20px;
        gap:5px;
        padding-left:7px !important;
        padding-right:7px !important;
    }

    .pos-ref-product-cell{
        grid-template-columns:32px minmax(0,1fr);
        column-gap:7px;
    }

    .pos-ref-img{
        width:30px;
        height:30px;
        min-width:30px;
    }

    .pos-ref-product-text .pos-cart-title,
    .pos-cart-item .pos-cart-title{
        font-size:10.8px !important;
    }

    .pos-ref-product-text .pos-cart-sku,
    .pos-cart-item .pos-cart-sku{
        font-size:9.6px !important;
    }

    .pos-ref-price-cell::before,
    .pos-ref-price-cell .pos-cart-price{
        font-size:10.5px !important;
    }

    .pos-ref-qty-cell .pos-cart-controls{
        transform:scale(.80);
    }

    .pos-ref-total-cell .pos-cart-total,
    .pos-ref-total-cell [data-line-total]{
        width:68px !important;
        font-size:10.8px !important;
    }

    .pos-grand-total-row strong{
        font-size:22px !important;
    }
}

@media (max-height:760px){
    .pos-premium-cart{
        padding:8px 10px !important;
    }

    .pos-cart-customer-header{
        margin-bottom:6px;
        padding-bottom:6px;
    }

    .pos-cart-customer-icon{
        width:30px;
        height:30px;
        min-width:30px;
    }

    .pos-cart-customer-info h4{
        font-size:13px;
    }

    .pos-cart-phone-input{
        height:34px;
    }

    .pos-cart-phone-box{
        margin-bottom:6px;
    }

    .pos-order-items-top{
        margin-bottom:5px;
    }

    .pos-cart-table-head{
        height:30px;
    }

    .pos-cart-item.pos-ref-row{
        min-height:50px;
        padding-top:5px !important;
        padding-bottom:5px !important;
    }

    .pos-add-custom-main{
        height:36px;
    }

    .pos-custom-input,
    .pos-custom-add-btn{
        height:30px;
    }

    .pos-summary-row{
        min-height:20px !important;
        font-size:12px !important;
    }

    .pos-grand-total-row{
        min-height:34px !important;
        padding-top:6px !important;
    }

    .pos-grand-total-row span{
        font-size:15px !important;
    }

    .pos-grand-total-row strong{
        font-size:22px !important;
    }

    .pos-payment-methods .pay-btn{
        height:34px !important;
        min-height:34px !important;
        font-size:10.5px !important;
    }

    .pos-proceed-btn{
        height:40px !important;
        min-height:40px !important;
        font-size:13px !important;
    }
}

@media (max-width:1200px){
    .pos-layout{
        grid-template-columns:1fr !important;
    }

    .pos-premium-cart{
        min-height:720px !important;
    }
}

@media (max-width:576px){
    .pos-cart-table-head{
        display:none;
    }

    .pos-cart-item.pos-ref-row{
        grid-template-columns:1fr;
        gap:8px;
        border:1px solid rgba(148,163,184,.12) !important;
        border-radius:10px !important;
        margin-bottom:8px !important;
        background:#0b1220 !important;
    }

    .pos-ref-product-cell{
        grid-template-columns:44px minmax(0,1fr);
    }

    .pos-ref-img{
        width:42px;
        height:42px;
        min-width:42px;
    }

    .pos-ref-price-cell,
    .pos-ref-qty-cell,
    .pos-ref-total-cell,
    .pos-ref-remove-cell{
        justify-content:space-between;
        width:100%;
    }

    .pos-custom-product-form{
        grid-template-columns:1fr;
    }

    .pos-custom-input{
        border-right:0;
        border-bottom:1px solid rgba(148,163,184,.10);
    }

    .pos-payment-methods{
        grid-template-columns:repeat(2,1fr) !important;
    }
}


/* =========================================================
   SENIOR DEV FINAL CART ALIGNMENT FIX
   Product column separated from price; name always visible
   Cart row columns: Product | Price | Qty | Total | Remove
========================================================= */
.pos-layout{
    grid-template-columns:minmax(0,1fr) 590px !important;
}

.pos-premium-cart{
    min-width:0 !important;
}

.pos-premium-cart .pos-cart-table-head,
.pos-premium-cart .pos-cart-item.pos-ref-row,
.pos-premium-cart .pos-cart-item.ref-row-ready{
    grid-template-columns:minmax(0,1.15fr) 64px 94px 78px 22px !important;
    gap:6px !important;
    align-items:center !important;
}

.pos-premium-cart .pos-cart-table-head{
    height:34px !important;
    padding:0 8px !important;
    font-size:10px !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-cart-item.pos-ref-row,
.pos-premium-cart .pos-cart-item.ref-row-ready{
    min-height:58px !important;
    padding:7px 8px !important;
    margin:0 !important;
    border:0 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-radius:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-ref-product-cell,
.pos-premium-cart .ref-product-cell{
    min-width:0 !important;
    max-width:100% !important;
    width:100% !important;
    overflow:hidden !important;
    display:grid !important;
    grid-template-columns:36px minmax(0,1fr) !important;
    column-gap:8px !important;
    align-items:center !important;
}

.pos-premium-cart .pos-ref-img,
.pos-premium-cart .ref-cart-img{
    width:34px !important;
    height:34px !important;
    min-width:34px !important;
    max-width:34px !important;
    object-fit:contain !important;
    padding:2px !important;
    border-radius:6px !important;
    background:#0b1220 !important;
    border:1px solid rgba(148,163,184,.08) !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-ref-product-text,
.pos-premium-cart .ref-product-text{
    min-width:0 !important;
    max-width:100% !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    justify-content:center !important;
    gap:2px !important;
}

.pos-premium-cart .pos-cart-title{
    display:block !important;
    width:100% !important;
    max-width:100% !important;
    color:#f8fafc !important;
    font-size:11.3px !important;
    line-height:1.15 !important;
    font-weight:900 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    margin:0 !important;
}

.pos-premium-cart .pos-cart-badge,
.pos-premium-cart .pos-cart-tier-badge{
    display:none !important;
}

.pos-premium-cart .pos-cart-sku{
    display:block !important;
    width:100% !important;
    max-width:100% !important;
    color:#8fa1bb !important;
    font-size:9.7px !important;
    line-height:1.1 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    margin:0 !important;
}

.pos-premium-cart .pos-ref-price-cell,
.pos-premium-cart .ref-price-cell{
    min-width:0 !important;
    width:64px !important;
    max-width:64px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-start !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-ref-price-cell::before,
.pos-premium-cart .ref-price-cell::before{
    content:'Rs' !important;
    margin-right:3px !important;
    color:#f8fafc !important;
    font-size:10.7px !important;
    font-weight:900 !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-cart-price,
.pos-premium-cart input[data-cart-price-input]{
    width:38px !important;
    min-width:38px !important;
    max-width:38px !important;
    height:24px !important;
    padding:0 !important;
    margin:0 !important;
    border:0 !important;
    outline:none !important;
    background:transparent !important;
    color:#f8fafc !important;
    text-align:left !important;
    font-size:10.8px !important;
    font-weight:900 !important;
    line-height:24px !important;
    box-shadow:none !important;
    appearance:textfield !important;
    -moz-appearance:textfield !important;
}

.pos-premium-cart .pos-cart-price::-webkit-inner-spin-button,
.pos-premium-cart .pos-cart-price::-webkit-outer-spin-button,
.pos-premium-cart input[data-cart-price-input]::-webkit-inner-spin-button,
.pos-premium-cart input[data-cart-price-input]::-webkit-outer-spin-button{
    -webkit-appearance:none !important;
    appearance:none !important;
    display:none !important;
    opacity:0 !important;
    margin:0 !important;
}

.pos-premium-cart .pos-ref-qty-cell,
.pos-premium-cart .ref-qty-cell{
    width:94px !important;
    max-width:94px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-cart-controls{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:0 !important;
    flex-wrap:nowrap !important;
    transform:scale(.82) !important;
    transform-origin:center !important;
}

.pos-premium-cart .pos-cart-btn{
    width:28px !important;
    min-width:28px !important;
    height:28px !important;
    border-radius:0 !important;
    background:#0b1220 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    color:#dbe5f5 !important;
    box-shadow:none !important;
    font-size:13px !important;
    font-weight:900 !important;
}

.pos-premium-cart .pos-cart-btn[data-cart-minus]{
    border-radius:6px 0 0 6px !important;
}

.pos-premium-cart .pos-cart-btn[data-cart-plus]{
    border-radius:0 6px 6px 0 !important;
}

.pos-premium-cart .pos-cart-qty{
    width:34px !important;
    min-width:34px !important;
    height:28px !important;
    border-radius:0 !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-left:0 !important;
    border-right:0 !important;
    color:#fff !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-align:center !important;
}

.pos-premium-cart .pos-ref-total-cell,
.pos-premium-cart .ref-total-cell{
    width:78px !important;
    max-width:78px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-cart-total,
.pos-premium-cart [data-line-total]{
    width:78px !important;
    max-width:78px !important;
    margin:0 !important;
    color:#f8fafc !important;
    text-align:right !important;
    font-size:10.8px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.pos-premium-cart .pos-ref-remove-cell,
.pos-premium-cart .ref-remove-cell{
    width:22px !important;
    max-width:22px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
}

.pos-premium-cart .pos-ref-remove-cell .pos-cart-btn,
.pos-premium-cart .ref-remove-cell .pos-cart-btn,
.pos-premium-cart [data-cart-remove]{
    width:20px !important;
    min-width:20px !important;
    height:20px !important;
    border-radius:50% !important;
    background:transparent !important;
    color:#94a3b8 !important;
    border:1px solid rgba(148,163,184,.22) !important;
    font-size:10px !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-cart-right{
    display:contents !important;
}

.pos-premium-cart #cart.has-cart-items .empty-state{
    display:none !important;
}

@media (max-width:1450px){
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 530px !important;
    }

    .pos-premium-cart .pos-cart-table-head,
    .pos-premium-cart .pos-cart-item.pos-ref-row,
    .pos-premium-cart .pos-cart-item.ref-row-ready{
        grid-template-columns:minmax(0,1.05fr) 56px 82px 66px 20px !important;
        gap:5px !important;
        padding-left:6px !important;
        padding-right:6px !important;
    }

    .pos-premium-cart .pos-ref-product-cell,
    .pos-premium-cart .ref-product-cell{
        grid-template-columns:30px minmax(0,1fr) !important;
        column-gap:6px !important;
    }

    .pos-premium-cart .pos-ref-img,
    .pos-premium-cart .ref-cart-img{
        width:28px !important;
        height:28px !important;
        min-width:28px !important;
    }

    .pos-premium-cart .pos-cart-title{
        font-size:10.4px !important;
    }

    .pos-premium-cart .pos-cart-sku{
        font-size:9px !important;
    }

    .pos-premium-cart .pos-ref-price-cell,
    .pos-premium-cart .ref-price-cell{
        width:56px !important;
        max-width:56px !important;
    }

    .pos-premium-cart .pos-cart-price,
    .pos-premium-cart input[data-cart-price-input]{
        width:32px !important;
        min-width:32px !important;
        max-width:32px !important;
        font-size:10px !important;
    }

    .pos-premium-cart .pos-ref-qty-cell,
    .pos-premium-cart .ref-qty-cell{
        width:82px !important;
        max-width:82px !important;
    }

    .pos-premium-cart .pos-cart-controls{
        transform:scale(.78) !important;
    }

    .pos-premium-cart .pos-ref-total-cell,
    .pos-premium-cart .ref-total-cell,
    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total]{
        width:66px !important;
        max-width:66px !important;
        font-size:10px !important;
    }
}


/* =========================================================
   PRODUCT CARD IMAGE UI UPDATE
   Square + slightly bigger product images only.
   Backend / POS logic unchanged.
========================================================= */
.items-grid{
    grid-template-columns:repeat(auto-fill,minmax(205px,1fr)) !important;
    gap:16px !important;
}

.item-card,
.product-card{
    min-height:305px !important;
}

.item-card .item-img,
.product-card .item-img{
    width:100% !important;
    height:auto !important;
    aspect-ratio:1 / 1 !important;
    min-height:170px !important;
    margin:0 !important;
    border-radius:10px 10px 0 0 !important;
    overflow:hidden !important;
}

.item-card .item-img img,
.product-card .item-img img{
    width:100% !important;
    height:100% !important;
    max-width:none !important;
    max-height:none !important;
    object-fit:cover !important;
    display:block !important;
}

@media (max-width:1450px){
    .items-grid{
        grid-template-columns:repeat(auto-fill,minmax(190px,1fr)) !important;
    }
    .item-card,
    .product-card{
        min-height:285px !important;
    }
    .item-card .item-img,
    .product-card .item-img{
        min-height:160px !important;
    }
}

@media (max-height:760px){
    .item-card,
    .product-card{
        min-height:270px !important;
    }
    .item-card .item-img,
    .product-card .item-img{
        height:auto !important;
        aspect-ratio:1 / 1 !important;
        min-height:150px !important;
    }
}

/* REMOVE GREEN IN STOCK LABEL FROM PRODUCT CARDS */
.item-img::after,
.item-card .item-img::after,
.product-card .item-img::after{
    content:none !important;
    display:none !important;
}

/* PRODUCT CARD SMALL SKU BUTTON */
.item-sku-wrap{
    padding:4px 12px 0 !important;
    display:flex !important;
    align-items:center !important;
    gap:6px !important;
    min-height:24px !important;
}

.sku-mini-btn{
    height:20px !important;
    min-width:34px !important;
    padding:0 7px !important;
    border-radius:6px !important;
    border:1px solid rgba(124,58,237,.45) !important;
    background:rgba(124,58,237,.16) !important;
    color:#c4b5fd !important;
    font-size:10px !important;
    font-weight:900 !important;
    line-height:18px !important;
    cursor:pointer !important;
    transition:.18s ease !important;
}

.sku-mini-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
    border-color:#8b5cf6 !important;
}

.item-sku{
    padding:0 !important;
    margin:0 !important;
    color:#9ca3af !important;
    font-size:10.5px !important;
    font-weight:700 !important;
    line-height:1.1 !important;
    max-width:105px !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.item-sku.sku-hidden{
    display:none !important;
}

.item-sku-wrap.sku-open .sku-mini-btn{
    background:#7c3aed !important;
    color:#fff !important;
}

/* SKU value sirf button ke andar show hogi, side wali duplicate value hide rahegi */
.item-sku-wrap .item-sku{
    display:none !important;
}

/* =========================================================
   POS SCREEN MOBILE RESPONSIVE FINAL OVERRIDE
   Paste at the very END before
========================================================= */

@media (max-width: 991.98px) {

    html,
    body {
        width: 100% !important;
        height: auto !important;
        min-height: 100% !important;
        overflow-x: hidden !important;
        overflow-y: auto !important;
    }

    body {
        background: #070b14 !important;
    }

    .container-scroll {
        display: block !important;
        width: 100% !important;
        height: auto !important;
        min-height: 100dvh !important;
        max-height: none !important;
        overflow: visible !important;
    }

    /* Mobile sidebar drawer */
    .sidebar,
    #dashboardSidebar {
        display: block !important;
        position: fixed !important;
        top: 0 !important;
        left: -280px !important;
        width: 270px !important;
        min-width: 270px !important;
        max-width: 270px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        max-height: 100dvh !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        background: #070b14 !important;
        z-index: 100000 !important;
        transition: left .25s ease !important;
        box-shadow: 20px 0 60px rgba(0,0,0,.55) !important;
    }

    body.sidebar-open #dashboardSidebar,
    body.dashboard-mobile-sidebar-open #dashboardSidebar {
        left: 0 !important;
    }

    body.sidebar-open,
    body.dashboard-mobile-sidebar-open {
        overflow: hidden !important;
    }

    body.sidebar-open::before,
    body.dashboard-mobile-sidebar-open::before {
        content: "";
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.62);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        z-index: 99990;
    }

    body.sidebar-open #dashboardSidebar,
    body.dashboard-mobile-sidebar-open #dashboardSidebar {
        z-index: 100000 !important;
    }

    .main-panel {
        width: 100% !important;
        min-width: 0 !important;
        height: auto !important;
        min-height: 100dvh !important;
        max-height: none !important;
        overflow: visible !important;
        display: flex !important;
        flex-direction: column !important;
    }

    .topbar {
        position: sticky !important;
        top: 0 !important;
        width: 100% !important;
        height: 58px !important;
        min-height: 58px !important;
        max-height: 58px !important;
        padding: 0 8px !important;
        z-index: 9000 !important;
        background: #070b14 !important;
        overflow: visible !important;
    }

    .topbar-wrap {
        min-width: 0 !important;
        flex: 1 !important;
    }

    .topbar-right {
        gap: 6px !important;
    }

    .topbar-right .user-pill {
        display: none !important;
    }

    .topbar-right .icon-btn {
        width: 34px !important;
        height: 34px !important;
        min-width: 34px !important;
    }

    .content-wrapper {
        width: 100% !important;
        height: auto !important;
        min-height: calc(100dvh - 58px) !important;
        max-height: none !important;
        padding: 10px !important;
        overflow: visible !important;
    }

    .pos-layout {
        width: 100% !important;
        height: auto !important;
        min-height: auto !important;
        max-height: none !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 12px !important;
        overflow: visible !important;
        background: transparent !important;
    }

    .items-area,
    .cart-area,
    .pos-premium-cart {
        width: 100% !important;
        height: auto !important;
        min-height: auto !important;
        max-height: none !important;
        overflow: visible !important;
        border-radius: 16px !important;
    }

    .items-area {
        padding: 10px !important;
        order: 1 !important;
    }

    .cart-area,
    .pos-premium-cart {
        padding: 10px !important;
        order: 2 !important;
    }

    /* Toolbar mobile */
    .toolbar {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
        margin-bottom: 10px !important;
    }

    .type-buttons {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 8px !important;
    }

    .toolbar > .type-btn {
        width: 100% !important;
    }

    .type-btn {
        width: 100% !important;
        min-width: 0 !important;
        height: 40px !important;
        min-height: 40px !important;
        padding: 0 8px !important;
        font-size: 12px !important;
        border-radius: 10px !important;
        white-space: nowrap !important;
    }

    .selected-label {
        display: none !important;
    }

    .toolbar-right {
        width: 100% !important;
        margin-left: 0 !important;
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
    }

    .chip {
        width: 100% !important;
        height: 38px !important;
        min-height: 38px !important;
        font-size: 12px !important;
        border-radius: 10px !important;
    }

    /* Search + category */
    .categories {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
        margin-bottom: 10px !important;
    }

    .category-btn {
        display: none !important;
    }

    .category-btn.active {
        display: flex !important;
        width: 100% !important;
        height: 40px !important;
        justify-content: center !important;
        order: 2 !important;
    }

    .search-wrap {
        order: 1 !important;
        width: 100% !important;
        min-width: 0 !important;
        margin-left: 0 !important;
    }

    .search-input {
        height: 42px !important;
        font-size: 14px !important;
    }

    /* Products grid */
    .items-grid {
        width: 100% !important;
        max-height: 60dvh !important;
        min-height: 260px !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px !important;
        padding-right: 4px !important;
    }

    .item-card,
    .product-card {
        min-height: 230px !important;
        border-radius: 12px !important;
    }

    .item-card .item-img,
    .product-card .item-img {
        min-height: 120px !important;
        aspect-ratio: 1 / 1 !important;
        border-radius: 12px 12px 0 0 !important;
    }

    .item-title {
        font-size: 12px !important;
        line-height: 1.25 !important;
        min-height: 34px !important;
        padding: 8px 9px 0 !important;
    }

    .item-sku-wrap {
        padding: 4px 9px 0 !important;
    }

    .item-price {
        font-size: 16px !important;
    }

    .item-bottom {
        padding: 7px 9px 9px !important;
    }

    .add-btn {
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        font-size: 20px !important;
    }

    /* Cart mobile */
    .pos-premium-cart {
        min-height: auto !important;
    }

    .pos-cart-customer-header {
        gap: 8px !important;
        padding-bottom: 8px !important;
        margin-bottom: 8px !important;
    }

    .pos-cart-customer-left {
        gap: 8px !important;
    }

    .pos-cart-customer-icon {
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        border-radius: 10px !important;
    }

    .pos-cart-customer-info h4 {
        max-width: 180px !important;
        font-size: 13px !important;
    }

    .pos-cart-customer-info small {
        font-size: 10px !important;
    }

    .pos-cart-change-btn {
        height: 32px !important;
        padding: 0 10px !important;
        font-size: 11px !important;
    }

    .pos-cart-phone-input {
        height: 38px !important;
        font-size: 13px !important;
    }

    .pos-order-items-top {
        margin-bottom: 7px !important;
    }

    .pos-order-items-title {
        font-size: 12px !important;
    }

    .pos-clear-cart-btn {
        height: 30px !important;
        font-size: 11px !important;
    }

    .pos-cart-table-head {
        display: none !important;
    }

    .cart-items,
    .pos-cart-items-list {
        min-height: 120px !important;
        max-height: 45dvh !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        padding-right: 3px !important;
    }

    /* Cart row as mobile card */
    .pos-cart-item.pos-ref-row,
    .pos-cart-item.ref-row-ready,
    .pos-premium-cart .pos-cart-item.pos-ref-row,
    .pos-premium-cart .pos-cart-item.ref-row-ready {
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 8px !important;
        width: 100% !important;
        min-height: auto !important;
        padding: 10px !important;
        margin-bottom: 8px !important;
        border: 1px solid rgba(148,163,184,.12) !important;
        border-radius: 12px !important;
        background: #0b1220 !important;
    }

    .pos-ref-product-cell,
    .ref-product-cell,
    .pos-premium-cart .pos-ref-product-cell,
    .pos-premium-cart .ref-product-cell {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: 46px minmax(0, 1fr) !important;
        column-gap: 10px !important;
    }

    .pos-ref-img,
    .ref-cart-img,
    .pos-premium-cart .pos-ref-img,
    .pos-premium-cart .ref-cart-img {
        width: 44px !important;
        height: 44px !important;
        min-width: 44px !important;
        max-width: 44px !important;
        border-radius: 8px !important;
    }

    .pos-cart-title,
    .pos-premium-cart .pos-cart-title {
        font-size: 12px !important;
        line-height: 1.25 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .pos-cart-sku,
    .pos-premium-cart .pos-cart-sku {
        font-size: 10px !important;
    }

    .pos-ref-price-cell,
    .ref-price-cell,
    .pos-ref-qty-cell,
    .ref-qty-cell,
    .pos-ref-total-cell,
    .ref-total-cell,
    .pos-ref-remove-cell,
    .ref-remove-cell,
    .pos-premium-cart .pos-ref-price-cell,
    .pos-premium-cart .ref-price-cell,
    .pos-premium-cart .pos-ref-qty-cell,
    .pos-premium-cart .ref-qty-cell,
    .pos-premium-cart .pos-ref-total-cell,
    .pos-premium-cart .ref-total-cell,
    .pos-premium-cart .pos-ref-remove-cell,
    .pos-premium-cart .ref-remove-cell {
        width: 100% !important;
        max-width: 100% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
    }

    .pos-ref-price-cell::before,
    .ref-price-cell::before {
        content: "Price" !important;
        color: #94a3b8 !important;
        font-size: 11px !important;
        font-weight: 900 !important;
        margin-right: 8px !important;
        text-transform: uppercase !important;
    }

    .pos-ref-qty-cell::before,
    .ref-qty-cell::before {
        content: "Qty" !important;
        color: #94a3b8 !important;
        font-size: 11px !important;
        font-weight: 900 !important;
        text-transform: uppercase !important;
    }

    .pos-ref-total-cell::before,
    .ref-total-cell::before {
        content: "Total" !important;
        color: #94a3b8 !important;
        font-size: 11px !important;
        font-weight: 900 !important;
        text-transform: uppercase !important;
    }

    .pos-cart-price,
    .pos-premium-cart .pos-cart-price,
    .pos-premium-cart input[data-cart-price-input] {
        width: 90px !important;
        min-width: 90px !important;
        max-width: 90px !important;
        height: 30px !important;
        text-align: right !important;
        font-size: 12px !important;
        background: #070d19 !important;
        border: 1px solid rgba(148,163,184,.12) !important;
        border-radius: 8px !important;
        padding: 0 8px !important;
    }

    .pos-cart-controls,
    .pos-premium-cart .pos-cart-controls {
        transform: none !important;
        gap: 0 !important;
    }

    .pos-cart-btn,
    .pos-premium-cart .pos-cart-btn {
        width: 30px !important;
        min-width: 30px !important;
        height: 30px !important;
    }

    .pos-cart-qty,
    .pos-premium-cart .pos-cart-qty {
        width: 42px !important;
        min-width: 42px !important;
        height: 30px !important;
    }

    .pos-cart-total,
    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total] {
        width: auto !important;
        max-width: 160px !important;
        font-size: 13px !important;
        text-align: right !important;
    }

    .pos-ref-remove-cell .pos-cart-btn,
    .ref-remove-cell .pos-cart-btn,
    .pos-premium-cart [data-cart-remove] {
        width: 28px !important;
        min-width: 28px !important;
        height: 28px !important;
    }

    /* Custom product */
    .pos-add-custom-main {
        height: 42px !important;
        font-size: 13px !important;
    }

    .pos-custom-product-form {
        grid-template-columns: 1fr !important;
    }

    .pos-custom-input {
        height: 36px !important;
        border-right: 0 !important;
        border-bottom: 1px solid rgba(148,163,184,.10) !important;
    }

    .pos-custom-add-btn {
        height: 38px !important;
    }

    /* Summary */
    .pos-billing-summary {
        margin-top: 8px !important;
        padding-top: 8px !important;
    }

    .pos-summary-row {
        min-height: 24px !important;
        font-size: 12px !important;
    }

    .pos-discount-inline {
        height: 30px !important;
    }

    .pos-hidden-discount {
        width: 90px !important;
    }

    .pos-grand-total-row {
        min-height: 40px !important;
    }

    .pos-grand-total-row span {
        font-size: 15px !important;
    }

    .pos-grand-total-row strong {
        font-size: 21px !important;
    }

    .pos-payment-methods {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
        margin: 8px 0 !important;
    }

    .pos-payment-methods .pay-btn {
        height: 40px !important;
        min-height: 40px !important;
        font-size: 11px !important;
    }

    .pos-proceed-btn,
    .invoice-btn {
        height: 46px !important;
        min-height: 46px !important;
        font-size: 12px !important;
        border-radius: 10px !important;
    }

    /* Modals */
    .modal-dialog {
        max-width: calc(100vw - 22px) !important;
        margin: 10px auto !important;
    }

    .modal-content {
        border-radius: 16px !important;
    }

    #custList {
        max-height: 60dvh !important;
    }
}

@media (max-width: 575.98px) {

    .content-wrapper {
        padding: 8px !important;
    }

    .items-area,
    .cart-area,
    .pos-premium-cart {
        padding: 8px !important;
        border-radius: 14px !important;
    }

    .type-buttons {
        grid-template-columns: 1fr !important;
    }

    .items-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
        max-height: 55dvh !important;
    }

    .item-card,
    .product-card {
        min-height: 205px !important;
    }

    .item-card .item-img,
    .product-card .item-img {
        min-height: 105px !important;
    }

    .item-title {
        font-size: 11px !important;
        padding: 7px 8px 0 !important;
    }

    .sku-mini-btn {
        height: 19px !important;
        font-size: 9px !important;
        padding: 0 6px !important;
    }

    .item-price {
        font-size: 14px !important;
    }

    .item-price small {
        font-size: 10px !important;
    }

    .add-btn {
        width: 28px !important;
        height: 28px !important;
        min-width: 28px !important;
        font-size: 18px !important;
    }

    .pos-cart-customer-info h4 {
        max-width: 145px !important;
    }

    .cart-items,
    .pos-cart-items-list {
        max-height: 42dvh !important;
    }

    .pos-payment-methods {
        grid-template-columns: 1fr 1fr !important;
    }
}

@media (max-width: 390px) {

    .items-grid {
        grid-template-columns: 1fr !important;
    }

    .item-card,
    .product-card {
        min-height: auto !important;
    }

    .item-card .item-img,
    .product-card .item-img {
        min-height: 160px !important;
    }

    .pos-cart-customer-header {
        flex-wrap: wrap !important;
    }

    .pos-cart-change-btn {
        width: 100% !important;
    }

    .pos-payment-methods {
        grid-template-columns: 1fr !important;
    }
}


/* =========================================================
   POS MOBILE APP VIEW - EXACT REFERENCE LIKE UI
   Paste this at the VERY END before 
========================================================= */

@media (max-width: 767.98px) {

    html,
    body {
        width: 100% !important;
        height: auto !important;
        min-height: 100dvh !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow-x: hidden !important;
        overflow-y: auto !important;
        background:
            radial-gradient(circle at 50% -10%, rgba(124,58,237,.22), transparent 38%),
            linear-gradient(180deg, #050816 0%, #070b14 100%) !important;
    }

    .container-scroll {
        display: block !important;
        width: 100% !important;
        min-height: 100dvh !important;
        height: auto !important;
        max-height: none !important;
        overflow: visible !important;
        background: transparent !important;
    }

    .main-panel {
        width: 100% !important;
        min-width: 0 !important;
        min-height: 100dvh !important;
        height: auto !important;
        max-height: none !important;
        overflow: visible !important;
        background: transparent !important;
    }

    /* Sidebar mobile drawer */
    .sidebar,
    #dashboardSidebar {
        display: block !important;
        position: fixed !important;
        top: 0 !important;
        left: -285px !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        max-height: 100dvh !important;
        overflow-y: auto !important;
        background: #070b14 !important;
        z-index: 100000 !important;
        transition: left .25s ease !important;
        box-shadow: 18px 0 55px rgba(0,0,0,.62) !important;
    }

    body.sidebar-open #dashboardSidebar,
    body.dashboard-mobile-sidebar-open #dashboardSidebar {
        left: 0 !important;
    }

    body.sidebar-open::before,
    body.dashboard-mobile-sidebar-open::before {
        content: "" !important;
        position: fixed !important;
        inset: 0 !important;
        background: rgba(0,0,0,.62) !important;
        backdrop-filter: blur(5px) !important;
        -webkit-backdrop-filter: blur(5px) !important;
        z-index: 99990 !important;
    }

    /* Topbar like mobile app */
    .topbar {
        position: sticky !important;
        top: 0 !important;
        z-index: 9000 !important;
        width: 100% !important;
        height: 68px !important;
        min-height: 68px !important;
        max-height: 68px !important;
        padding: 8px 12px !important;
        background: rgba(7, 11, 20, .92) !important;
        backdrop-filter: blur(14px) !important;
        -webkit-backdrop-filter: blur(14px) !important;
        border-bottom: 1px solid rgba(148,163,184,.12) !important;
        overflow: visible !important;
    }

    .topbar-wrap {
        min-width: 0 !important;
        flex: 1 !important;
    }

    .topbar-right {
        gap: 8px !important;
    }

    .topbar-right .user-pill {
        display: none !important;
    }

    .topbar-right .icon-btn,
    .icon-btn {
        width: 42px !important;
        height: 42px !important;
        min-width: 42px !important;
        border-radius: 14px !important;
        background: rgba(15,23,42,.85) !important;
        border: 1px solid rgba(148,163,184,.16) !important;
        color: #f8fafc !important;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.04) !important;
    }

    .content-wrapper {
        width: 100% !important;
        height: auto !important;
        min-height: calc(100dvh - 68px) !important;
        max-height: none !important;
        overflow: visible !important;
        padding: 10px 12px 18px !important;
        background: transparent !important;
    }

    .pos-layout {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 12px !important;
        height: auto !important;
        min-height: auto !important;
        max-height: none !important;
        overflow: visible !important;
        background: transparent !important;
    }

    .items-area,
    .cart-area,
    .pos-premium-cart {
        width: 100% !important;
        height: auto !important;
        min-height: auto !important;
        max-height: none !important;
        overflow: visible !important;
        border-radius: 16px !important;
        background: transparent !important;
        border: 0 !important;
        box-shadow: none !important;
        padding: 0 !important;
    }

    /* Customer type buttons: Retailer / Wholesale 1 / 2 / 3 */
    .toolbar {
        display: block !important;
        margin-bottom: 10px !important;
    }

    .type-buttons {
        width: 100% !important;
        display: contents !important;
    }

    .toolbar {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 8px !important;
        align-items: center !important;
    }

    .toolbar > .type-btn,
    .toolbar .type-buttons .type-btn {
        width: 100% !important;
        min-width: 0 !important;
        height: 43px !important;
        min-height: 43px !important;
        padding: 0 6px !important;
        border-radius: 13px !important;
        background: rgba(15,23,42,.88) !important;
        border: 1px solid rgba(148,163,184,.14) !important;
        color: #e5e7eb !important;
        font-size: 11.5px !important;
        font-weight: 900 !important;
        white-space: nowrap !important;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.04) !important;
    }

    .toolbar > .type-btn.active,
    .toolbar .type-buttons .type-btn.active,
    .toolbar > .type-btn:hover,
    .toolbar .type-buttons .type-btn:hover {
        background: linear-gradient(135deg, #9f67ff, #7c3aed 60%, #5b21b6) !important;
        color: #fff !important;
        border-color: rgba(167,139,250,.80) !important;
        box-shadow: 0 12px 28px rgba(124,58,237,.34) !important;
    }

    .selected-label,
    .toolbar-right {
        display: none !important;
    }

    /* Search + category same row */
    .categories {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) 125px !important;
        gap: 8px !important;
        align-items: center !important;
        margin-bottom: 12px !important;
    }

    .search-wrap {
        order: 1 !important;
        width: 100% !important;
        min-width: 0 !important;
        margin: 0 !important;
    }

    .search-input {
        width: 100% !important;
        height: 46px !important;
        border-radius: 13px !important;
        background: rgba(8,13,28,.92) !important;
        border: 1px solid rgba(148,163,184,.16) !important;
        color: #f8fafc !important;
        font-size: 13px !important;
        padding: 0 13px !important;
    }

    .search-input::placeholder {
        color: #8b98ad !important;
    }

    .category-btn {
        display: none !important;
    }

    .category-btn.active {
        order: 2 !important;
        display: flex !important;
        width: 100% !important;
        height: 46px !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 13px !important;
        background: rgba(15,23,42,.88) !important;
        border: 1px solid rgba(148,163,184,.16) !important;
        color: #e5e7eb !important;
        font-size: 12px !important;
        font-weight: 800 !important;
        box-shadow: none !important;
    }

    .category-btn.active::after {
        content: "⌄" !important;
        margin-left: 8px !important;
        color: #cbd5e1 !important;
    }

    /* Product grid exactly like reference: 3 cards on mobile */
    .items-grid {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 8px !important;
        max-height: none !important;
        min-height: auto !important;
        overflow: visible !important;
        padding: 0 !important;
    }

    .item-card,
    .product-card {
        width: 100% !important;
        min-height: 176px !important;
        height: auto !important;
        padding: 0 !important;
        border-radius: 12px !important;
        overflow: hidden !important;
        background: linear-gradient(180deg, rgba(15,23,42,.96), rgba(8,13,28,.98)) !important;
        border: 1px solid rgba(148,163,184,.18) !important;
        box-shadow: none !important;
    }

    .item-card .item-img,
    .product-card .item-img {
        width: 100% !important;
        height: 82px !important;
        min-height: 82px !important;
        aspect-ratio: auto !important;
        margin: 0 !important;
        border-radius: 0 !important;
        background: #111827 !important;
    }

    .item-card .item-img img,
    .product-card .item-img img {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover !important;
        display: block !important;
    }

    .item-title {
        min-height: 30px !important;
        padding: 7px 8px 0 !important;
        font-size: 10.8px !important;
        line-height: 1.25 !important;
        font-weight: 900 !important;
        color: #f8fafc !important;
        display: -webkit-box !important;
        -webkit-line-clamp: 2 !important;
        -webkit-box-orient: vertical !important;
        overflow: hidden !important;
    }

    .item-sku-wrap {
        min-height: 20px !important;
        padding: 3px 8px 0 !important;
    }

    .sku-mini-btn {
        height: 18px !important;
        min-width: 34px !important;
        padding: 0 6px !important;
        border-radius: 6px !important;
        font-size: 9px !important;
        line-height: 16px !important;
        background: rgba(124,58,237,.22) !important;
        color: #c4b5fd !important;
        border: 1px solid rgba(124,58,237,.42) !important;
    }

    .item-tax-badge {
        display: none !important;
    }

    .item-bottom {
        padding: 5px 8px 8px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 5px !important;
    }

    .item-price {
        font-size: 14px !important;
        line-height: 1 !important;
        font-weight: 950 !important;
        color: #f8fafc !important;
    }

    .item-price small {
        display: inline !important;
        font-size: 10px !important;
        color: #dbeafe !important;
        margin: 0 2px 0 0 !important;
    }

    .add-btn {
        width: 29px !important;
        height: 29px !important;
        min-width: 29px !important;
        border-radius: 9px !important;
        font-size: 19px !important;
        line-height: 1 !important;
        color: #fff !important;
        background: linear-gradient(135deg, #9f67ff, #7c3aed) !important;
        border: 1px solid rgba(167,139,250,.78) !important;
        box-shadow: 0 8px 18px rgba(124,58,237,.34) !important;
    }

    /* Cart box like reference */
    .pos-premium-cart {
        margin-top: 6px !important;
        padding: 0 !important;
        background: transparent !important;
    }

    .pos-cart-customer-header {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px !important;
        padding: 10px 11px !important;
        margin: 0 0 10px !important;
        border-radius: 14px !important;
        border: 1px solid rgba(148,163,184,.16) !important;
        background:
            radial-gradient(circle at right, rgba(124,58,237,.20), transparent 40%),
            rgba(15,23,42,.86) !important;
    }

    .pos-cart-customer-left {
        min-width: 0 !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
    }

    .pos-cart-customer-icon {
        width: 38px !important;
        height: 38px !important;
        min-width: 38px !important;
        border-radius: 13px !important;
        background: rgba(124,58,237,.16) !important;
        border: 1px solid rgba(124,58,237,.36) !important;
        color: #a78bfa !important;
    }

    .pos-cart-customer-info h4 {
        max-width: 190px !important;
        margin: 0 !important;
        font-size: 14px !important;
        line-height: 1.15 !important;
        color: #fff !important;
        font-weight: 950 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .pos-cart-customer-info small {
        font-size: 10px !important;
        color: #aab4c8 !important;
    }

    .pos-cart-change-btn {
        height: 34px !important;
        padding: 0 13px !important;
        border-radius: 11px !important;
        background: rgba(15,23,42,.88) !important;
        border: 1px solid rgba(148,163,184,.18) !important;
        color: #f8fafc !important;
        font-size: 11px !important;
        font-weight: 900 !important;
    }

    .pos-cart-phone-box {
        display: none !important;
    }

    .pos-order-items-top {
        padding: 10px 11px 8px !important;
        margin: 0 !important;
        border-radius: 14px 14px 0 0 !important;
        border: 1px solid rgba(148,163,184,.16) !important;
        border-bottom: 0 !important;
        background: rgba(15,23,42,.86) !important;
    }

    .pos-order-items-title {
        font-size: 13px !important;
        color: #f8fafc !important;
        font-weight: 950 !important;
    }

    .pos-clear-cart-btn {
        height: 30px !important;
        padding: 0 8px !important;
        border-radius: 9px !important;
        color: #fb7185 !important;
        background: transparent !important;
        border: 1px solid rgba(244,63,94,.22) !important;
        font-size: 11px !important;
        font-weight: 900 !important;
    }

    .pos-cart-table-head {
        display: none !important;
    }

    .cart-items,
    .pos-cart-items-list {
        width: 100% !important;
        min-height: 90px !important;
        max-height: none !important;
        overflow: visible !important;
        padding: 0 10px 10px !important;
        background: rgba(15,23,42,.86) !important;
        border-left: 1px solid rgba(148,163,184,.16) !important;
        border-right: 1px solid rgba(148,163,184,.16) !important;
    }

    .pos-cart-empty,
    .empty-state {
        min-height: 80px !important;
        border-radius: 12px !important;
        background: rgba(8,13,28,.78) !important;
        border: 1px dashed rgba(148,163,184,.20) !important;
        color: #94a3b8 !important;
        font-size: 12px !important;
    }

    /* Cart item compact horizontal card */
    .pos-cart-item.pos-ref-row,
    .pos-cart-item.ref-row-ready,
    .pos-premium-cart .pos-cart-item.pos-ref-row,
    .pos-premium-cart .pos-cart-item.ref-row-ready {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) 82px !important;
        gap: 8px !important;
        align-items: center !important;
        width: 100% !important;
        min-height: 64px !important;
        padding: 8px 0 !important;
        margin: 0 !important;
        border: 0 !important;
        border-bottom: 1px solid rgba(148,163,184,.10) !important;
        border-radius: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
    }

    .pos-ref-product-cell,
    .ref-product-cell,
    .pos-premium-cart .pos-ref-product-cell,
    .pos-premium-cart .ref-product-cell {
        display: grid !important;
        grid-template-columns: 46px minmax(0,1fr) !important;
        column-gap: 9px !important;
        align-items: center !important;
        min-width: 0 !important;
        width: 100% !important;
    }

    .pos-ref-img,
    .ref-cart-img,
    .pos-premium-cart .pos-ref-img,
    .pos-premium-cart .ref-cart-img {
        width: 42px !important;
        height: 42px !important;
        min-width: 42px !important;
        max-width: 42px !important;
        border-radius: 8px !important;
        object-fit: cover !important;
        padding: 0 !important;
        background: #111827 !important;
        border: 1px solid rgba(148,163,184,.10) !important;
    }

    .pos-cart-title,
    .pos-premium-cart .pos-cart-title {
        font-size: 12px !important;
        line-height: 1.2 !important;
        color: #fff !important;
        font-weight: 950 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .pos-cart-sku,
    .pos-premium-cart .pos-cart-sku {
        font-size: 10px !important;
        color: #a78bfa !important;
    }

    .pos-ref-price-cell,
    .ref-price-cell {
        display: none !important;
    }

    .pos-ref-qty-cell,
    .ref-qty-cell,
    .pos-premium-cart .pos-ref-qty-cell,
    .pos-premium-cart .ref-qty-cell {
        grid-column: 2 !important;
        grid-row: 1 !important;
        width: 82px !important;
        max-width: 82px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
    }

    .pos-cart-controls,
    .pos-premium-cart .pos-cart-controls {
        transform: none !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 0 !important;
    }

    .pos-cart-btn,
    .pos-premium-cart .pos-cart-btn {
        width: 27px !important;
        min-width: 27px !important;
        height: 27px !important;
        border-radius: 8px !important;
        background: rgba(8,13,28,.95) !important;
        border: 1px solid rgba(148,163,184,.16) !important;
        color: #fff !important;
        font-size: 13px !important;
    }

    .pos-cart-qty,
    .pos-premium-cart .pos-cart-qty {
        width: 28px !important;
        min-width: 28px !important;
        height: 27px !important;
        border-radius: 0 !important;
        background: transparent !important;
        border: 0 !important;
        color: #fff !important;
        font-size: 13px !important;
        font-weight: 900 !important;
    }

    .pos-ref-total-cell,
    .ref-total-cell,
    .pos-premium-cart .pos-ref-total-cell,
    .pos-premium-cart .ref-total-cell {
        grid-column: 1 / -1 !important;
        width: 100% !important;
        max-width: 100% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        margin-top: -4px !important;
    }

    .pos-cart-total,
    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total] {
        width: auto !important;
        max-width: none !important;
        color: #f8fafc !important;
        font-size: 12px !important;
        font-weight: 950 !important;
        white-space: nowrap !important;
    }

    .pos-ref-remove-cell,
    .ref-remove-cell,
    .pos-premium-cart .pos-ref-remove-cell,
    .pos-premium-cart .ref-remove-cell {
        position: absolute !important;
        right: 10px !important;
        top: 10px !important;
        width: auto !important;
        max-width: none !important;
    }

    .pos-ref-remove-cell .pos-cart-btn,
    .ref-remove-cell .pos-cart-btn,
    .pos-premium-cart [data-cart-remove] {
        width: 24px !important;
        min-width: 24px !important;
        height: 24px !important;
        border-radius: 50% !important;
        background: rgba(244,63,94,.10) !important;
        color: #fb7185 !important;
        border: 1px solid rgba(244,63,94,.25) !important;
        font-size: 11px !important;
    }

    /* Add custom product hidden compact */
    .pos-add-custom-wrap {
        margin: 0 !important;
        padding: 0 10px 10px !important;
        background: rgba(15,23,42,.86) !important;
        border-left: 1px solid rgba(148,163,184,.16) !important;
        border-right: 1px solid rgba(148,163,184,.16) !important;
    }

    .pos-add-custom-main {
        height: 40px !important;
        border-radius: 12px !important;
        background: rgba(8,13,28,.78) !important;
        border: 1px dashed rgba(148,163,184,.22) !important;
        color: #cbd5e1 !important;
        font-size: 12px !important;
    }

    .pos-custom-product-form {
        grid-template-columns: 1fr !important;
        margin-top: 8px !important;
        border-radius: 12px !important;
    }

    .pos-custom-input,
    .pos-custom-add-btn {
        height: 38px !important;
    }

    /* Summary card */
    .pos-billing-summary {
        margin: 0 !important;
        padding: 10px 16px !important;
        background: rgba(15,23,42,.86) !important;
        border-left: 1px solid rgba(148,163,184,.16) !important;
        border-right: 1px solid rgba(148,163,184,.16) !important;
        border-top: 1px solid rgba(148,163,184,.10) !important;
    }

    .pos-summary-row {
        min-height: 27px !important;
        font-size: 13px !important;
        color: #dbeafe !important;
    }

    .pos-summary-row span {
        color: #dbeafe !important;
    }

    .pos-summary-row strong {
        font-size: 13px !important;
        color: #fff !important;
    }

    .pos-discount-inline {
        height: 30px !important;
        border-radius: 8px !important;
        background: rgba(8,13,28,.90) !important;
    }

    .pos-discount-pill {
        width: 34px !important;
        height: 30px !important;
        background: linear-gradient(135deg, #9f67ff, #7c3aed) !important;
    }

    .pos-hidden-discount {
        width: 78px !important;
        height: 30px !important;
        font-size: 12px !important;
    }

    .pos-grand-total-row {
        min-height: 44px !important;
        margin-top: 6px !important;
        padding-top: 10px !important;
        border-top: 1px dashed rgba(148,163,184,.16) !important;
    }

    .pos-grand-total-row span {
        font-size: 17px !important;
        font-weight: 950 !important;
        color: #fff !important;
    }

    .pos-grand-total-row strong {
        font-size: 23px !important;
        color: #a855f7 !important;
        font-weight: 950 !important;
    }

    /* Payment buttons 4 in one row like reference */
    .pos-payment-methods {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 7px !important;
        margin: 0 !important;
        padding: 0 10px 10px !important;
        background: rgba(15,23,42,.86) !important;
        border-left: 1px solid rgba(148,163,184,.16) !important;
        border-right: 1px solid rgba(148,163,184,.16) !important;
    }

    .pos-payment-methods .pay-btn {
        width: 100% !important;
        height: 44px !important;
        min-height: 44px !important;
        border-radius: 12px !important;
        background: rgba(8,13,28,.86) !important;
        border: 1px solid rgba(148,163,184,.16) !important;
        color: #f8fafc !important;
        font-size: 10.5px !important;
        font-weight: 950 !important;
        padding: 0 4px !important;
        gap: 4px !important;
    }

    .pos-payment-methods .pay-btn span {
        font-size: 13px !important;
    }

    .pos-payment-methods .pay-btn.active,
    .pos-payment-methods .pay-btn:hover {
        background: linear-gradient(135deg, #9f67ff, #7c3aed) !important;
        border-color: rgba(167,139,250,.75) !important;
        box-shadow: 0 10px 24px rgba(124,58,237,.30) !important;
    }

    .pos-proceed-btn,
    .invoice-btn {
        width: calc(100% - 20px) !important;
        height: 56px !important;
        min-height: 56px !important;
        margin: 0 10px 12px !important;
        border-radius: 13px !important;
        background: linear-gradient(135deg, #9f67ff, #7c3aed 58%, #5b21b6) !important;
        border: 1px solid rgba(167,139,250,.78) !important;
        color: #fff !important;
        font-size: 14px !important;
        letter-spacing: .8px !important;
        font-weight: 950 !important;
        box-shadow: 0 16px 34px rgba(124,58,237,.34) !important;
    }

    .pos-proceed-arrow {
        font-size: 20px !important;
    }

    /* Cart bottom rounded wrapper */
    .pos-proceed-btn {
        position: relative !important;
    }

    .pos-premium-cart::after {
        content: "" !important;
        display: block !important;
        height: 0 !important;
    }

    /* Modal mobile */
    .modal-dialog {
        max-width: calc(100vw - 20px) !important;
        margin: 10px auto !important;
    }

    .modal-content {
        border-radius: 18px !important;
        background: #0b1020 !important;
    }

    #custList {
        max-height: 60dvh !important;
    }
}

/* iPhone narrow screens */
@media (max-width: 430px) {
    .content-wrapper {
        padding-left: 10px !important;
        padding-right: 10px !important;
    }

    .toolbar {
        gap: 7px !important;
    }

    .toolbar > .type-btn,
    .toolbar .type-buttons .type-btn {
        height: 41px !important;
        font-size: 10.6px !important;
        border-radius: 12px !important;
    }

    .categories {
        grid-template-columns: minmax(0, 1fr) 116px !important;
        gap: 7px !important;
    }

    .search-input,
    .category-btn.active {
        height: 44px !important;
    }

    .items-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 7px !important;
    }

    .item-card,
    .product-card {
        min-height: 168px !important;
        border-radius: 11px !important;
    }

    .item-card .item-img,
    .product-card .item-img {
        height: 76px !important;
        min-height: 76px !important;
    }

    .item-title {
        font-size: 10.2px !important;
        padding-left: 7px !important;
        padding-right: 7px !important;
    }

    .item-bottom {
        padding-left: 7px !important;
        padding-right: 7px !important;
    }

    .item-price {
        font-size: 13px !important;
    }

    .add-btn {
        width: 27px !important;
        height: 27px !important;
        min-width: 27px !important;
    }

    .pos-cart-customer-info h4 {
        max-width: 160px !important;
    }

    .pos-grand-total-row strong {
        font-size: 22px !important;
    }
}

/* Very small mobile fallback */
@media (max-width: 360px) {
    .items-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .item-card .item-img,
    .product-card .item-img {
        height: 95px !important;
        min-height: 95px !important;
    }

    .toolbar {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .pos-payment-methods {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

/* =========================================================
   FINAL EXACT MOBILE POS VIEW - SAME AS REFERENCE IMAGE
   Paste at the VERY END before
========================================================= */

/*@media (max-width: 767.98px){*/

/*    :root{*/
/*        --m-bg:#050816;*/
/*        --m-card:#081126;*/
/*        --m-card2:#0b1530;*/
/*        --m-line:rgba(148,163,184,.20);*/
/*        --m-text:#f8fafc;*/
/*        --m-muted:#9ca3af;*/
/*        --m-purple:#8b5cf6;*/
/*        --m-purple2:#7c3aed;*/
/*        --m-purple3:#5b21b6;*/
/*        --m-red:#fb7185;*/
/*    }*/

/*    html,*/
/*    body{*/
/*        width:100% !important;*/
/*        min-height:100dvh !important;*/
/*        height:auto !important;*/
/*        margin:0 !important;*/
/*        padding:0 !important;*/
/*        overflow-x:hidden !important;*/
/*        overflow-y:auto !important;*/
/*        background:*/
/*            radial-gradient(circle at 50% -8%, rgba(124,58,237,.28), transparent 35%),*/
/*            radial-gradient(circle at 90% 8%, rgba(59,130,246,.14), transparent 28%),*/
/*            linear-gradient(180deg,#030712 0%, #07101f 100%) !important;*/
/*        color:var(--m-text) !important;*/
/*    }*/

/*    *{*/
/*        box-sizing:border-box !important;*/
/*    }*/

/*    .container-scroll,*/
/*    .main-panel{*/
/*        width:100% !important;*/
/*        min-width:0 !important;*/
/*        height:auto !important;*/
/*        min-height:100dvh !important;*/
/*        max-height:none !important;*/
/*        display:block !important;*/
/*        overflow:visible !important;*/
/*        background:transparent !important;*/
/*    }*/

    /* Sidebar drawer */
/*    .sidebar,*/
/*    #dashboardSidebar{*/
/*        display:block !important;*/
/*        position:fixed !important;*/
/*        top:0 !important;*/
/*        left:-286px !important;*/
/*        width:280px !important;*/
/*        min-width:280px !important;*/
/*        max-width:280px !important;*/
/*        height:100dvh !important;*/
/*        max-height:100dvh !important;*/
/*        overflow-y:auto !important;*/
/*        background:#060b16 !important;*/
/*        z-index:100000 !important;*/
/*        transition:left .25s ease !important;*/
/*        box-shadow:22px 0 70px rgba(0,0,0,.70) !important;*/
/*    }*/

/*    body.sidebar-open #dashboardSidebar,*/
/*    body.dashboard-mobile-sidebar-open #dashboardSidebar{*/
/*        left:0 !important;*/
/*    }*/

/*    body.sidebar-open::before,*/
/*    body.dashboard-mobile-sidebar-open::before{*/
/*        content:"" !important;*/
/*        position:fixed !important;*/
/*        inset:0 !important;*/
/*        background:rgba(0,0,0,.65) !important;*/
/*        backdrop-filter:blur(5px) !important;*/
/*        -webkit-backdrop-filter:blur(5px) !important;*/
/*        z-index:99990 !important;*/
/*    }*/

    /* Top mobile header */
/*    .topbar{*/
/*        position:sticky !important;*/
/*        top:0 !important;*/
/*        z-index:9000 !important;*/
/*        width:100% !important;*/
/*        height:78px !important;*/
/*        min-height:78px !important;*/
/*        max-height:78px !important;*/
/*        padding:14px 14px 10px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        background:rgba(5,8,22,.94) !important;*/
/*        backdrop-filter:blur(16px) !important;*/
/*        -webkit-backdrop-filter:blur(16px) !important;*/
/*        border-bottom:1px solid rgba(148,163,184,.14) !important;*/
/*        overflow:visible !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .topbar-wrap{*/
/*        flex:1 1 auto !important;*/
/*        min-width:0 !important;*/
/*    }*/

/*    .topbar-right{*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        gap:10px !important;*/
/*        flex-shrink:0 !important;*/
/*    }*/

/*    .topbar-right .user-pill{*/
/*        display:none !important;*/
/*    }*/

/*    .icon-btn,*/
/*    .topbar-right .icon-btn,*/
/*    #sidebarToggle,*/
/*    #toggleSidebar,*/
/*    .sidebar-toggle,*/
/*    .sidebar-toggler,*/
/*    .topbar-menu-toggle,*/
/*    .hamburger-btn{*/
/*        width:46px !important;*/
/*        height:46px !important;*/
/*        min-width:46px !important;*/
/*        border-radius:15px !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        color:#fff !important;*/
/*        box-shadow:inset 0 1px 0 rgba(255,255,255,.05) !important;*/
/*    }*/

/*    .content-wrapper{*/
/*        width:100% !important;*/
/*        height:auto !important;*/
/*        min-height:calc(100dvh - 78px) !important;*/
/*        max-height:none !important;*/
/*        padding:12px 12px 22px !important;*/
/*        overflow:visible !important;*/
/*        background:transparent !important;*/
/*    }*/

/*    .pos-layout{*/
/*        width:100% !important;*/
/*        height:auto !important;*/
/*        min-height:0 !important;*/
/*        max-height:none !important;*/
/*        display:flex !important;*/
/*        flex-direction:column !important;*/
/*        gap:14px !important;*/
/*        overflow:visible !important;*/
/*        background:transparent !important;*/
/*    }*/

/*    .items-area,*/
/*    .cart-area,*/
/*    .pos-premium-cart{*/
/*        width:100% !important;*/
/*        height:auto !important;*/
/*        min-height:0 !important;*/
/*        max-height:none !important;*/
/*        overflow:visible !important;*/
/*        padding:0 !important;*/
/*        background:transparent !important;*/
/*        border:0 !important;*/
/*        border-radius:0 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

    /* Top price type tabs */
/*    .toolbar{*/
/*        width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:repeat(4,minmax(0,1fr)) !important;*/
/*        gap:9px !important;*/
/*        align-items:center !important;*/
/*        margin:0 0 12px !important;*/
/*        padding:0 !important;*/
/*    }*/

/*    .type-buttons{*/
/*        display:contents !important;*/
/*    }*/

/*    .toolbar > .type-btn,*/
/*    .toolbar .type-buttons .type-btn{*/
/*        width:100% !important;*/
/*        min-width:0 !important;*/
/*        height:48px !important;*/
/*        min-height:48px !important;*/
/*        max-height:48px !important;*/
/*        padding:0 6px !important;*/
/*        border-radius:15px !important;*/
/*        background:rgba(15,23,42,.86) !important;*/
/*        border:1px solid rgba(148,163,184,.20) !important;*/
/*        color:#f1f5f9 !important;*/
/*        font-size:12px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:950 !important;*/
/*        white-space:nowrap !important;*/
/*        box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;*/
/*    }*/

/*    .toolbar > .type-btn.active,*/
/*    .toolbar .type-buttons .type-btn.active,*/
/*    .toolbar > .type-btn:hover,*/
/*    .toolbar .type-buttons .type-btn:hover{*/
/*        background:linear-gradient(135deg,#a855f7 0%, #7c3aed 62%, #5b21b6 100%) !important;*/
/*        border-color:rgba(192,132,252,.80) !important;*/
/*        color:#fff !important;*/
/*        box-shadow:0 12px 28px rgba(124,58,237,.40), inset 0 1px 0 rgba(255,255,255,.16) !important;*/
/*    }*/

/*    .selected-label,*/
/*    .toolbar-right,*/
/*    .chip{*/
/*        display:none !important;*/
/*    }*/

    /* Search + category row */
/*    .categories{*/
/*        width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:minmax(0,1fr) 128px !important;*/
/*        gap:9px !important;*/
/*        align-items:center !important;*/
/*        margin:0 0 13px !important;*/
/*        padding:0 !important;*/
/*    }*/

/*    .search-wrap{*/
/*        order:1 !important;*/
/*        width:100% !important;*/
/*        min-width:0 !important;*/
/*        margin:0 !important;*/
/*    }*/

/*    .search-input{*/
/*        width:100% !important;*/
/*        height:52px !important;*/
/*        min-height:52px !important;*/
/*        padding:0 14px !important;*/
/*        border-radius:15px !important;*/
/*        background:rgba(8,13,28,.90) !important;*/
/*        border:1px solid rgba(148,163,184,.20) !important;*/
/*        color:#f8fafc !important;*/
/*        font-size:14px !important;*/
/*        font-weight:600 !important;*/
/*        outline:none !important;*/
/*        box-shadow:inset 0 1px 0 rgba(255,255,255,.03) !important;*/
/*    }*/

/*    .search-input::placeholder{*/
/*        color:#8b98ad !important;*/
/*    }*/

/*    .search-input:focus{*/
/*        border-color:rgba(139,92,246,.78) !important;*/
/*        box-shadow:0 0 0 3px rgba(124,58,237,.18) !important;*/
/*    }*/

/*    .category-btn{*/
/*        display:none !important;*/
/*    }*/

/*    .category-btn.active{*/
/*        order:2 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        width:100% !important;*/
/*        height:52px !important;*/
/*        min-height:52px !important;*/
/*        padding:0 8px !important;*/
/*        border-radius:15px !important;*/
/*        background:rgba(15,23,42,.86) !important;*/
/*        border:1px solid rgba(148,163,184,.20) !important;*/
/*        color:#f1f5f9 !important;*/
/*        font-size:12px !important;*/
/*        font-weight:850 !important;*/
/*        box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;*/
/*    }*/

/*    .category-btn.active::after{*/
/*        content:"⌄" !important;*/
/*        margin-left:8px !important;*/
/*        color:#cbd5e1 !important;*/
/*        font-size:15px !important;*/
/*    }*/

    /* Product grid 3 columns */
/*    .items-grid{*/
/*        width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:repeat(3,minmax(0,1fr)) !important;*/
/*        gap:9px !important;*/
/*        padding:0 !important;*/
/*        margin:0 !important;*/
/*        height:auto !important;*/
/*        min-height:0 !important;*/
/*        max-height:none !important;*/
/*        overflow:visible !important;*/
/*        align-content:start !important;*/
/*    }*/

/*    .item-card,*/
/*    .product-card{*/
/*        width:100% !important;*/
/*        min-width:0 !important;*/
/*        min-height:190px !important;*/
/*        height:auto !important;*/
/*        padding:0 !important;*/
/*        border-radius:14px !important;*/
/*        overflow:hidden !important;*/
/*        background:linear-gradient(180deg,rgba(12,21,44,.96),rgba(7,13,29,.98)) !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        box-shadow:none !important;*/
/*        display:flex !important;*/
/*        flex-direction:column !important;*/
/*    }*/

/*    .item-card:hover,*/
/*    .product-card:hover{*/
/*        transform:none !important;*/
/*    }*/

/*    .item-card .item-img,*/
/*    .product-card .item-img,*/
/*    .item-img{*/
/*        width:100% !important;*/
/*        height:92px !important;*/
/*        min-height:92px !important;*/
/*        max-height:92px !important;*/
/*        aspect-ratio:auto !important;*/
/*        margin:0 !important;*/
/*        padding:0 !important;*/
/*        border-radius:0 !important;*/
/*        overflow:hidden !important;*/
/*        background:#111827 !important;*/
/*    }*/

/*    .item-img::after{*/
/*        content:none !important;*/
/*        display:none !important;*/
/*    }*/

/*    .item-card .item-img img,*/
/*    .product-card .item-img img,*/
/*    .item-img img{*/
/*        width:100% !important;*/
/*        height:100% !important;*/
/*        min-width:100% !important;*/
/*        min-height:100% !important;*/
/*        max-width:none !important;*/
/*        max-height:none !important;*/
/*        object-fit:cover !important;*/
/*        display:block !important;*/
/*        opacity:1 !important;*/
/*    }*/

/*    .item-title{*/
/*        min-height:34px !important;*/
/*        padding:8px 9px 0 !important;*/
/*        color:#fff !important;*/
/*        font-size:11.2px !important;*/
/*        line-height:1.25 !important;*/
/*        font-weight:950 !important;*/
/*        display:-webkit-box !important;*/
/*        -webkit-line-clamp:2 !important;*/
/*        -webkit-box-orient:vertical !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .item-sku-wrap{*/
/*        min-height:23px !important;*/
/*        padding:4px 9px 0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        gap:5px !important;*/
/*    }*/

/*    .sku-mini-btn{*/
/*        height:20px !important;*/
/*        min-width:44px !important;*/
/*        max-width:100% !important;*/
/*        padding:0 7px !important;*/
/*        border-radius:7px !important;*/
/*        background:rgba(124,58,237,.24) !important;*/
/*        border:1px solid rgba(139,92,246,.42) !important;*/
/*        color:#ddd6fe !important;*/
/*        font-size:9.5px !important;*/
/*        font-weight:950 !important;*/
/*        line-height:18px !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*        white-space:nowrap !important;*/
/*    }*/

/*    .item-sku-wrap .item-sku,*/
/*    .item-sku{*/
/*        display:none !important;*/
/*    }*/

/*    .item-tax-badge{*/
/*        display:none !important;*/
/*    }*/

/*    .item-bottom{*/
/*        margin-top:auto !important;*/
/*        padding:6px 9px 9px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        gap:6px !important;*/
/*    }*/

/*    .item-price{*/
/*        color:#fff !important;*/
/*        font-size:15px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:950 !important;*/
/*        white-space:nowrap !important;*/
/*    }*/

/*    .item-price small{*/
/*        display:inline !important;*/
/*        color:#e2e8f0 !important;*/
/*        font-size:10.5px !important;*/
/*        font-weight:700 !important;*/
/*        margin:0 3px 0 0 !important;*/
/*    }*/

/*    .add-btn{*/
/*        width:31px !important;*/
/*        min-width:31px !important;*/
/*        height:31px !important;*/
/*        min-height:31px !important;*/
/*        border-radius:9px !important;*/
/*        border:1px solid rgba(192,132,252,.75) !important;*/
/*        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;*/
/*        color:#fff !important;*/
/*        font-size:20px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:900 !important;*/
/*        box-shadow:0 10px 20px rgba(124,58,237,.36) !important;*/
/*    }*/

    /* Customer card */
/*    .pos-cart-customer-header{*/
/*        width:100% !important;*/
/*        min-height:72px !important;*/
/*        margin:8px 0 11px !important;*/
/*        padding:12px 12px !important;*/
/*        border-radius:15px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        gap:10px !important;*/
/*        background:*/
/*            radial-gradient(circle at right,rgba(124,58,237,.22),transparent 48%),*/
/*            rgba(15,23,42,.88) !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;*/
/*    }*/

/*    .pos-cart-customer-left{*/
/*        min-width:0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        gap:11px !important;*/
/*    }*/

/*    .pos-cart-customer-icon{*/
/*        width:43px !important;*/
/*        height:43px !important;*/
/*        min-width:43px !important;*/
/*        border-radius:50% !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        background:rgba(124,58,237,.18) !important;*/
/*        border:1px solid rgba(139,92,246,.42) !important;*/
/*        color:#c084fc !important;*/
/*        font-size:21px !important;*/
/*    }*/

/*    .pos-cart-customer-info h4{*/
/*        max-width:190px !important;*/
/*        margin:0 !important;*/
/*        color:#fff !important;*/
/*        font-size:15px !important;*/
/*        line-height:1.15 !important;*/
/*        font-weight:950 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-cart-customer-info small{*/
/*        display:block !important;*/
/*        margin-top:3px !important;*/
/*        color:#aeb9cc !important;*/
/*        font-size:10.5px !important;*/
/*        font-weight:600 !important;*/
/*    }*/

/*    .pos-cart-change-btn{*/
/*        height:37px !important;*/
/*        min-width:78px !important;*/
/*        padding:0 13px !important;*/
/*        border-radius:12px !important;*/
/*        background:rgba(15,23,42,.90) !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        color:#fff !important;*/
/*        font-size:11.5px !important;*/
/*        font-weight:950 !important;*/
/*    }*/

/*    .pos-cart-phone-box{*/
/*        display:none !important;*/
/*    }*/

    /* Cart main card */
/*    .pos-order-items-top{*/
/*        margin:0 !important;*/
/*        padding:13px 12px 10px !important;*/
/*        border-radius:15px 15px 0 0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        border-bottom:0 !important;*/
/*    }*/

/*    .pos-order-items-title{*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        font-weight:950 !important;*/
/*        letter-spacing:.3px !important;*/
/*    }*/

/*    .pos-order-items-title::before{*/
/*        content:"🛒 " !important;*/
/*    }*/

/*    .pos-clear-cart-btn{*/
/*        height:32px !important;*/
/*        padding:0 9px !important;*/
/*        border-radius:10px !important;*/
/*        background:transparent !important;*/
/*        border:1px solid rgba(244,63,94,.26) !important;*/
/*        color:#fb7185 !important;*/
/*        font-size:11.5px !important;*/
/*        font-weight:900 !important;*/
/*    }*/

/*    .pos-cart-table-head{*/
/*        display:none !important;*/
/*    }*/

/*    .cart-items,*/
/*    .pos-cart-items-list{*/
/*        width:100% !important;*/
/*        min-height:95px !important;*/
/*        max-height:none !important;*/
/*        height:auto !important;*/
/*        padding:0 12px 8px !important;*/
/*        margin:0 !important;*/
/*        overflow:visible !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border-left:1px solid rgba(148,163,184,.22) !important;*/
/*        border-right:1px solid rgba(148,163,184,.22) !important;*/
/*    }*/

/*    .pos-cart-empty,*/
/*    .empty-state{*/
/*        min-height:90px !important;*/
/*        padding:18px 10px !important;*/
/*        border-radius:13px !important;*/
/*        background:rgba(8,13,28,.70) !important;*/
/*        border:1px dashed rgba(148,163,184,.22) !important;*/
/*        color:#94a3b8 !important;*/
/*        font-size:12px !important;*/
/*    }*/

    /* Cart row same as image: product | qty | price | total | delete */
/*    .pos-cart-item.pos-ref-row,*/
/*    .pos-cart-item.ref-row-ready,*/
/*    .pos-premium-cart .pos-cart-item.pos-ref-row,*/
/*    .pos-premium-cart .pos-cart-item.ref-row-ready{*/
/*        position:relative !important;*/
/*        width:100% !important;*/
/*        min-height:64px !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:minmax(0,1fr) 96px 62px 70px 28px !important;*/
/*        gap:7px !important;*/
/*        align-items:center !important;*/
/*        padding:8px 0 !important;*/
/*        margin:0 !important;*/
/*        border:0 !important;*/
/*        border-bottom:1px solid rgba(148,163,184,.12) !important;*/
/*        border-radius:0 !important;*/
/*        background:transparent !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-ref-product-cell,*/
/*    .ref-product-cell,*/
/*    .pos-premium-cart .pos-ref-product-cell,*/
/*    .pos-premium-cart .ref-product-cell{*/
/*        min-width:0 !important;*/
/*        width:100% !important;*/
/*        max-width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:46px minmax(0,1fr) !important;*/
/*        gap:9px !important;*/
/*        align-items:center !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .pos-ref-img,*/
/*    .ref-cart-img,*/
/*    .pos-premium-cart .pos-ref-img,*/
/*    .pos-premium-cart .ref-cart-img{*/
/*        width:43px !important;*/
/*        min-width:43px !important;*/
/*        max-width:43px !important;*/
/*        height:43px !important;*/
/*        max-height:43px !important;*/
/*        border-radius:8px !important;*/
/*        object-fit:cover !important;*/
/*        padding:0 !important;*/
/*        background:#111827 !important;*/
/*        border:1px solid rgba(148,163,184,.12) !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-ref-product-text,*/
/*    .ref-product-text{*/
/*        min-width:0 !important;*/
/*        max-width:100% !important;*/
/*        display:flex !important;*/
/*        flex-direction:column !important;*/
/*        gap:3px !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .pos-cart-title,*/
/*    .pos-premium-cart .pos-cart-title{*/
/*        width:100% !important;*/
/*        max-width:100% !important;*/
/*        margin:0 !important;*/
/*        color:#fff !important;*/
/*        font-size:12.5px !important;*/
/*        line-height:1.15 !important;*/
/*        font-weight:950 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-cart-badge,*/
/*    .pos-cart-tier-badge{*/
/*        display:none !important;*/
/*    }*/

/*    .pos-cart-sku,*/
/*    .pos-premium-cart .pos-cart-sku{*/
/*        width:max-content !important;*/
/*        max-width:100% !important;*/
/*        margin:0 !important;*/
/*        padding:3px 7px !important;*/
/*        border-radius:6px !important;*/
/*        background:rgba(124,58,237,.22) !important;*/
/*        color:#c4b5fd !important;*/
/*        font-size:10px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:850 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-ref-qty-cell,*/
/*    .ref-qty-cell,*/
/*    .pos-premium-cart .pos-ref-qty-cell,*/
/*    .pos-premium-cart .ref-qty-cell{*/
/*        width:96px !important;*/
/*        max-width:96px !important;*/
/*        display:flex !important;*/
/*        justify-content:center !important;*/
/*        align-items:center !important;*/
/*    }*/

/*    .pos-cart-controls,*/
/*    .pos-premium-cart .pos-cart-controls{*/
/*        width:96px !important;*/
/*        height:36px !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:30px 36px 30px !important;*/
/*        align-items:center !important;*/
/*        gap:0 !important;*/
/*        transform:none !important;*/
/*        border-radius:14px !important;*/
/*        overflow:hidden !important;*/
/*        background:rgba(8,13,28,.82) !important;*/
/*        border:1px solid rgba(148,163,184,.18) !important;*/
/*    }*/

/*    .pos-cart-btn,*/
/*    .pos-premium-cart .pos-cart-btn{*/
/*        width:30px !important;*/
/*        min-width:30px !important;*/
/*        height:36px !important;*/
/*        min-height:36px !important;*/
/*        border:0 !important;*/
/*        border-radius:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        font-size:15px !important;*/
/*        font-weight:900 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-cart-qty,*/
/*    .pos-premium-cart .pos-cart-qty{*/
/*        width:36px !important;*/
/*        min-width:36px !important;*/
/*        height:36px !important;*/
/*        border:0 !important;*/
/*        border-radius:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        text-align:center !important;*/
/*        font-size:14px !important;*/
/*        font-weight:950 !important;*/
/*        padding:0 !important;*/
/*    }*/

/*    .pos-ref-price-cell,*/
/*    .ref-price-cell,*/
/*    .pos-premium-cart .pos-ref-price-cell,*/
/*    .pos-premium-cart .ref-price-cell{*/
/*        width:62px !important;*/
/*        max-width:62px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:flex-end !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .pos-ref-price-cell::before,*/
/*    .ref-price-cell::before{*/
/*        content:"Rs" !important;*/
/*        margin-right:3px !important;*/
/*        color:#e2e8f0 !important;*/
/*        font-size:11px !important;*/
/*        font-weight:850 !important;*/
/*    }*/

/*    .pos-cart-price,*/
/*    .pos-premium-cart .pos-cart-price,*/
/*    .pos-premium-cart input[data-cart-price-input]{*/
/*        width:39px !important;*/
/*        min-width:39px !important;*/
/*        max-width:39px !important;*/
/*        height:24px !important;*/
/*        padding:0 !important;*/
/*        margin:0 !important;*/
/*        border:0 !important;*/
/*        outline:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        text-align:left !important;*/
/*        font-size:11.5px !important;*/
/*        line-height:24px !important;*/
/*        font-weight:900 !important;*/
/*        box-shadow:none !important;*/
/*        appearance:textfield !important;*/
/*        -moz-appearance:textfield !important;*/
/*    }*/

/*    .pos-cart-price::-webkit-inner-spin-button,*/
/*    .pos-cart-price::-webkit-outer-spin-button,*/
/*    input[data-cart-price-input]::-webkit-inner-spin-button,*/
/*    input[data-cart-price-input]::-webkit-outer-spin-button{*/
/*        -webkit-appearance:none !important;*/
/*        appearance:none !important;*/
/*        display:none !important;*/
/*        opacity:0 !important;*/
/*        margin:0 !important;*/
/*    }*/

/*    .pos-ref-total-cell,*/
/*    .ref-total-cell,*/
/*    .pos-premium-cart .pos-ref-total-cell,*/
/*    .pos-premium-cart .ref-total-cell{*/
/*        width:70px !important;*/
/*        max-width:70px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:flex-end !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .pos-cart-total,*/
/*    .pos-premium-cart .pos-cart-total,*/
/*    .pos-premium-cart [data-line-total]{*/
/*        width:70px !important;*/
/*        max-width:70px !important;*/
/*        margin:0 !important;*/
/*        color:#fff !important;*/
/*        font-size:11.5px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:950 !important;*/
/*        text-align:right !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-ref-remove-cell,*/
/*    .ref-remove-cell,*/
/*    .pos-premium-cart .pos-ref-remove-cell,*/
/*    .pos-premium-cart .ref-remove-cell{*/
/*        width:28px !important;*/
/*        max-width:28px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:flex-end !important;*/
/*    }*/

/*    .pos-ref-remove-cell .pos-cart-btn,*/
/*    .ref-remove-cell .pos-cart-btn,*/
/*    .pos-premium-cart [data-cart-remove]{*/
/*        width:27px !important;*/
/*        min-width:27px !important;*/
/*        height:27px !important;*/
/*        min-height:27px !important;*/
/*        border-radius:9px !important;*/
/*        background:rgba(244,63,94,.10) !important;*/
/*        border:1px solid rgba(244,63,94,.24) !important;*/
/*        color:#fb7185 !important;*/
/*        font-size:12px !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-cart-right{*/
/*        display:contents !important;*/
/*    }*/

    /* Hide custom product in exact mobile view */
/*    .pos-add-custom-wrap{*/
/*        display:none !important;*/
/*    }*/

    /* Summary */
/*    .pos-billing-summary{*/
/*        margin:0 !important;*/
/*        padding:12px 16px 10px !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border-left:1px solid rgba(148,163,184,.22) !important;*/
/*        border-right:1px solid rgba(148,163,184,.22) !important;*/
/*        border-top:1px solid rgba(148,163,184,.12) !important;*/
/*    }*/

/*    .pos-summary-row{*/
/*        min-height:29px !important;*/
/*        margin:0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        color:#e2e8f0 !important;*/
/*        font-size:14px !important;*/
/*        line-height:1 !important;*/
/*    }*/

/*    .pos-summary-row span{*/
/*        color:#e2e8f0 !important;*/
/*    }*/

/*    .pos-summary-row strong{*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        font-weight:900 !important;*/
/*    }*/

/*    .pos-summary-row:first-child{*/
/*        display:none !important;*/
/*    }*/

/*    .pos-discount-inline{*/
/*        height:34px !important;*/
/*        border-radius:9px !important;*/
/*        overflow:hidden !important;*/
/*        background:rgba(8,13,28,.86) !important;*/
/*        border:1px solid rgba(148,163,184,.16) !important;*/
/*    }*/

/*    .pos-discount-pill{*/
/*        width:38px !important;*/
/*        height:34px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;*/
/*        color:#fff !important;*/
/*        font-size:13px !important;*/
/*        font-weight:950 !important;*/
/*    }*/

/*    .pos-hidden-discount{*/
/*        width:76px !important;*/
/*        height:34px !important;*/
/*        padding:0 8px !important;*/
/*        border:0 !important;*/
/*        background:transparent !important;*/
/*        color:#e5e7eb !important;*/
/*        text-align:right !important;*/
/*        font-size:13px !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-grand-total-row{*/
/*        min-height:50px !important;*/
/*        margin-top:8px !important;*/
/*        padding-top:13px !important;*/
/*        border-top:1px dashed rgba(148,163,184,.20) !important;*/
/*    }*/

/*    .pos-grand-total-row span{*/
/*        color:#fff !important;*/
/*        font-size:18px !important;*/
/*        font-weight:950 !important;*/
/*    }*/

/*    .pos-grand-total-row strong{*/
/*        color:#a855f7 !important;*/
/*        font-size:26px !important;*/
/*        font-weight:950 !important;*/
/*        letter-spacing:.4px !important;*/
/*    }*/

    /* Payment row */
/*    .pos-payment-methods{*/
/*        margin:0 !important;*/
/*        padding:0 12px 12px !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:repeat(4,minmax(0,1fr)) !important;*/
/*        gap:8px !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border-left:1px solid rgba(148,163,184,.22) !important;*/
/*        border-right:1px solid rgba(148,163,184,.22) !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn{*/
/*        width:100% !important;*/
/*        height:48px !important;*/
/*        min-height:48px !important;*/
/*        padding:0 4px !important;*/
/*        border-radius:13px !important;*/
/*        background:rgba(8,13,28,.86) !important;*/
/*        border:1px solid rgba(148,163,184,.18) !important;*/
/*        color:#fff !important;*/
/*        font-size:11.5px !important;*/
/*        font-weight:950 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        gap:5px !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn span{*/
/*        font-size:14px !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn.active,*/
/*    .pos-payment-methods .pay-btn:hover{*/
/*        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;*/
/*        border-color:rgba(192,132,252,.75) !important;*/
/*        box-shadow:0 12px 26px rgba(124,58,237,.34) !important;*/
/*    }*/

/*    .pos-proceed-btn,*/
/*    .invoice-btn{*/
/*        width:calc(100% - 24px) !important;*/
/*        height:58px !important;*/
/*        min-height:58px !important;*/
/*        margin:0 12px 14px !important;*/
/*        padding:0 14px !important;*/
/*        border-radius:14px !important;*/
/*        background:linear-gradient(135deg,#a855f7 0%, #7c3aed 58%, #5b21b6 100%) !important;*/
/*        border:1px solid rgba(192,132,252,.75) !important;*/
/*        color:#fff !important;*/
/*        font-size:15px !important;*/
/*        font-weight:950 !important;*/
/*        letter-spacing:.8px !important;*/
/*        text-transform:uppercase !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        gap:12px !important;*/
/*        box-shadow:0 18px 38px rgba(124,58,237,.36) !important;*/
/*    }*/

/*    .pos-proceed-arrow{*/
/*        font-size:22px !important;*/
/*        line-height:1 !important;*/
/*    }*/

    /* Bottom rounded part */
/*    .pos-proceed-btn{*/
/*        border-bottom-left-radius:15px !important;*/
/*        border-bottom-right-radius:15px !important;*/
/*    }*/

    /* Modals */
/*    .modal-dialog{*/
/*        max-width:calc(100vw - 22px) !important;*/
/*        margin:10px auto !important;*/
/*    }*/

/*    .modal-content{*/
/*        border-radius:18px !important;*/
/*        background:#0b1020 !important;*/
/*        color:#fff !important;*/
/*    }*/

/*    #custList{*/
/*        max-height:60dvh !important;*/
/*    }*/
/*}*/

/* iPhone 13/14/15 type width */
/*@media (max-width:430px){*/

/*    .content-wrapper{*/
/*        padding:11px 10px 20px !important;*/
/*    }*/

/*    .topbar{*/
/*        height:74px !important;*/
/*        min-height:74px !important;*/
/*        max-height:74px !important;*/
/*        padding:12px 12px 9px !important;*/
/*    }*/

/*    .toolbar{*/
/*        gap:7px !important;*/
/*    }*/

/*    .toolbar > .type-btn,*/
/*    .toolbar .type-buttons .type-btn{*/
/*        height:46px !important;*/
/*        min-height:46px !important;*/
/*        font-size:11px !important;*/
/*        border-radius:14px !important;*/
/*    }*/

/*    .categories{*/
/*        grid-template-columns:minmax(0,1fr) 124px !important;*/
/*        gap:8px !important;*/
/*    }*/

/*    .search-input,*/
/*    .category-btn.active{*/
/*        height:50px !important;*/
/*        min-height:50px !important;*/
/*    }*/

/*    .items-grid{*/
/*        grid-template-columns:repeat(3,minmax(0,1fr)) !important;*/
/*        gap:8px !important;*/
/*    }*/

/*    .item-card,*/
/*    .product-card{*/
/*        min-height:181px !important;*/
/*        border-radius:13px !important;*/
/*    }*/

/*    .item-card .item-img,*/
/*    .product-card .item-img,*/
/*    .item-img{*/
/*        height:88px !important;*/
/*        min-height:88px !important;*/
/*        max-height:88px !important;*/
/*    }*/

/*    .item-title{*/
/*        font-size:10.7px !important;*/
/*        padding:7px 8px 0 !important;*/
/*    }*/

/*    .item-price{*/
/*        font-size:14px !important;*/
/*    }*/

/*    .add-btn{*/
/*        width:29px !important;*/
/*        min-width:29px !important;*/
/*        height:29px !important;*/
/*        min-height:29px !important;*/
/*    }*/

/*    .pos-cart-customer-info h4{*/
/*        max-width:168px !important;*/
/*        font-size:14px !important;*/
/*    }*/

/*    .pos-cart-item.pos-ref-row,*/
/*    .pos-cart-item.ref-row-ready,*/
/*    .pos-premium-cart .pos-cart-item.pos-ref-row,*/
/*    .pos-premium-cart .pos-cart-item.ref-row-ready{*/
/*        grid-template-columns:minmax(0,1fr) 90px 56px 62px 25px !important;*/
/*        gap:6px !important;*/
/*    }*/

/*    .pos-ref-product-cell,*/
/*    .ref-product-cell,*/
/*    .pos-premium-cart .pos-ref-product-cell,*/
/*    .pos-premium-cart .ref-product-cell{*/
/*        grid-template-columns:42px minmax(0,1fr) !important;*/
/*        gap:8px !important;*/
/*    }*/

/*    .pos-ref-img,*/
/*    .ref-cart-img,*/
/*    .pos-premium-cart .pos-ref-img,*/
/*    .pos-premium-cart .ref-cart-img{*/
/*        width:39px !important;*/
/*        min-width:39px !important;*/
/*        max-width:39px !important;*/
/*        height:39px !important;*/
/*    }*/

/*    .pos-cart-title,*/
/*    .pos-premium-cart .pos-cart-title{*/
/*        font-size:11.5px !important;*/
/*    }*/

/*    .pos-cart-sku,*/
/*    .pos-premium-cart .pos-cart-sku{*/
/*        font-size:9.5px !important;*/
/*        padding:3px 6px !important;*/
/*    }*/

/*    .pos-cart-controls,*/
/*    .pos-premium-cart .pos-cart-controls{*/
/*        width:90px !important;*/
/*        grid-template-columns:28px 34px 28px !important;*/
/*        height:34px !important;*/
/*    }*/

/*    .pos-cart-btn,*/
/*    .pos-premium-cart .pos-cart-btn{*/
/*        width:28px !important;*/
/*        min-width:28px !important;*/
/*        height:34px !important;*/
/*    }*/

/*    .pos-cart-qty,*/
/*    .pos-premium-cart .pos-cart-qty{*/
/*        width:34px !important;*/
/*        min-width:34px !important;*/
/*        height:34px !important;*/
/*        font-size:13px !important;*/
/*    }*/

/*    .pos-ref-price-cell,*/
/*    .ref-price-cell,*/
/*    .pos-premium-cart .pos-ref-price-cell,*/
/*    .pos-premium-cart .ref-price-cell{*/
/*        width:56px !important;*/
/*        max-width:56px !important;*/
/*    }*/

/*    .pos-cart-price,*/
/*    .pos-premium-cart .pos-cart-price,*/
/*    .pos-premium-cart input[data-cart-price-input]{*/
/*        width:34px !important;*/
/*        min-width:34px !important;*/
/*        max-width:34px !important;*/
/*        font-size:10.8px !important;*/
/*    }*/

/*    .pos-ref-total-cell,*/
/*    .ref-total-cell,*/
/*    .pos-premium-cart .pos-ref-total-cell,*/
/*    .pos-premium-cart .ref-total-cell,*/
/*    .pos-cart-total,*/
/*    .pos-premium-cart .pos-cart-total,*/
/*    .pos-premium-cart [data-line-total]{*/
/*        width:62px !important;*/
/*        max-width:62px !important;*/
/*        font-size:10.8px !important;*/
/*    }*/

/*    .pos-ref-remove-cell,*/
/*    .ref-remove-cell,*/
/*    .pos-premium-cart .pos-ref-remove-cell,*/
/*    .pos-premium-cart .ref-remove-cell{*/
/*        width:25px !important;*/
/*        max-width:25px !important;*/
/*    }*/

/*    .pos-ref-remove-cell .pos-cart-btn,*/
/*    .ref-remove-cell .pos-cart-btn,*/
/*    .pos-premium-cart [data-cart-remove]{*/
/*        width:24px !important;*/
/*        min-width:24px !important;*/
/*        height:24px !important;*/
/*        min-height:24px !important;*/
/*    }*/

/*    .pos-grand-total-row strong{*/
/*        font-size:24px !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn{*/
/*        height:46px !important;*/
/*        min-height:46px !important;*/
/*        font-size:10.8px !important;*/
/*    }*/
/*}*/

/* Very small mobile */
/*@media (max-width:370px){*/

/*    .toolbar{*/
/*        grid-template-columns:repeat(2,minmax(0,1fr)) !important;*/
/*    }*/

/*    .items-grid{*/
/*        grid-template-columns:repeat(2,minmax(0,1fr)) !important;*/
/*    }*/

/*    .item-card .item-img,*/
/*    .product-card .item-img,*/
/*    .item-img{*/
/*        height:105px !important;*/
/*        min-height:105px !important;*/
/*        max-height:105px !important;*/
/*    }*/

/*    .pos-payment-methods{*/
/*        grid-template-columns:repeat(2,minmax(0,1fr)) !important;*/
/*    }*/

/*    .pos-cart-item.pos-ref-row,*/
/*    .pos-cart-item.ref-row-ready,*/
/*    .pos-premium-cart .pos-cart-item.pos-ref-row,*/
/*    .pos-premium-cart .pos-cart-item.ref-row-ready{*/
/*        grid-template-columns:1fr !important;*/
/*        gap:8px !important;*/
/*    }*/

/*    .pos-ref-price-cell,*/
/*    .ref-price-cell,*/
/*    .pos-ref-qty-cell,*/
/*    .ref-qty-cell,*/
/*    .pos-ref-total-cell,*/
/*    .ref-total-cell,*/
/*    .pos-ref-remove-cell,*/
/*    .ref-remove-cell{*/
/*        width:100% !important;*/
/*        max-width:100% !important;*/
/*        justify-content:space-between !important;*/
/*    }*/
/*}*/

/* =========================================================
   POS MOBILE FINAL EXACT REFERENCE VIEW
   Paste this at the VERY END before 
   This overrides all old mobile media conflicts.
========================================================= */

/*@media only screen and (max-width: 991.98px){*/

/*    html,*/
/*    body{*/
/*        width:100% !important;*/
/*        height:auto !important;*/
/*        min-height:100dvh !important;*/
/*        margin:0 !important;*/
/*        padding:0 !important;*/
/*        overflow-x:hidden !important;*/
/*        overflow-y:auto !important;*/
/*        background:*/
/*            radial-gradient(circle at 50% 0%, rgba(124,58,237,.30), transparent 34%),*/
/*            linear-gradient(180deg,#060916 0%,#0a0f22 100%) !important;*/
/*        color:#fff !important;*/
/*    }*/

/*    .container-scroll,*/
/*    .main-panel{*/
/*        width:100% !important;*/
/*        min-width:0 !important;*/
/*        height:auto !important;*/
/*        min-height:100dvh !important;*/
/*        max-height:none !important;*/
/*        display:block !important;*/
/*        overflow:visible !important;*/
/*        background:transparent !important;*/
/*    }*/

/*    .content-wrapper{*/
/*        width:100% !important;*/
/*        height:auto !important;*/
/*        min-height:auto !important;*/
/*        max-height:none !important;*/
/*        padding:12px 10px 22px !important;*/
/*        overflow:visible !important;*/
/*        background:*/
/*            radial-gradient(circle at top, rgba(124,58,237,.24), transparent 34%),*/
/*            linear-gradient(180deg,#160d3d 0%,#091126 45%,#07101f 100%) !important;*/
/*    }*/

/*    .pos-layout{*/
/*        width:100% !important;*/
/*        height:auto !important;*/
/*        min-height:0 !important;*/
/*        max-height:none !important;*/
/*        display:flex !important;*/
/*        flex-direction:column !important;*/
/*        gap:12px !important;*/
/*        overflow:visible !important;*/
/*        background:transparent !important;*/
/*    }*/

/*    .items-area,*/
/*    .cart-area,*/
/*    .pos-premium-cart{*/
/*        width:100% !important;*/
/*        height:auto !important;*/
/*        min-height:0 !important;*/
/*        max-height:none !important;*/
/*        overflow:visible !important;*/
/*        padding:0 !important;*/
/*        margin:0 !important;*/
/*        background:transparent !important;*/
/*        border:0 !important;*/
/*        border-radius:0 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

    /* ================= TOPBAR ================= */

/*    .topbar{*/
/*        position:sticky !important;*/
/*        top:0 !important;*/
/*        z-index:9990 !important;*/
/*        width:100% !important;*/
/*        height:74px !important;*/
/*        min-height:74px !important;*/
/*        max-height:74px !important;*/
/*        padding:10px 14px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        background:#050b17 !important;*/
/*        border-bottom:1px solid rgba(148,163,184,.14) !important;*/
/*        overflow:visible !important;*/
/*    }*/

/*    .topbar-wrap{*/
/*        flex:1 1 auto !important;*/
/*        min-width:0 !important;*/
/*    }*/

/*    .topbar-right{*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        gap:12px !important;*/
/*        flex-shrink:0 !important;*/
/*    }*/

/*    .topbar-right .user-pill{*/
/*        display:none !important;*/
/*    }*/

/*    .icon-btn,*/
/*    .topbar-right .icon-btn,*/
/*    #sidebarToggle,*/
/*    #toggleSidebar,*/
/*    .sidebar-toggle,*/
/*    .sidebar-toggler,*/
/*    .topbar-menu-toggle,*/
/*    .hamburger-btn{*/
/*        width:48px !important;*/
/*        height:48px !important;*/
/*        min-width:48px !important;*/
/*        border-radius:17px !important;*/
/*        background:#101827 !important;*/
/*        border:1px solid rgba(148,163,184,.24) !important;*/
/*        color:#e5edf8 !important;*/
/*        box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;*/
/*    }*/

    /* ================= SIDEBAR DRAWER ================= */

/*    .sidebar,*/
/*    #dashboardSidebar{*/
/*        display:block !important;*/
/*        position:fixed !important;*/
/*        top:0 !important;*/
/*        left:-285px !important;*/
/*        width:280px !important;*/
/*        min-width:280px !important;*/
/*        max-width:280px !important;*/
/*        height:100dvh !important;*/
/*        max-height:100dvh !important;*/
/*        overflow-y:auto !important;*/
/*        background:#070b14 !important;*/
/*        z-index:100000 !important;*/
/*        transition:left .25s ease !important;*/
/*        box-shadow:22px 0 65px rgba(0,0,0,.65) !important;*/
/*    }*/

/*    body.sidebar-open #dashboardSidebar,*/
/*    body.dashboard-mobile-sidebar-open #dashboardSidebar{*/
/*        left:0 !important;*/
/*    }*/

/*    body.sidebar-open::before,*/
/*    body.dashboard-mobile-sidebar-open::before{*/
/*        content:"" !important;*/
/*        position:fixed !important;*/
/*        inset:0 !important;*/
/*        background:rgba(0,0,0,.62) !important;*/
/*        backdrop-filter:blur(5px) !important;*/
/*        -webkit-backdrop-filter:blur(5px) !important;*/
/*        z-index:99990 !important;*/
/*    }*/

    /* ================= WHOLESALE TABS - 4 IN ONE LINE ================= */

/*    .toolbar{*/
/*        width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:repeat(4, minmax(0, 1fr)) !important;*/
/*        gap:8px !important;*/
/*        margin:10px 0 10px !important;*/
/*        padding:0 !important;*/
/*        align-items:center !important;*/
/*    }*/

/*    .type-buttons{*/
/*        display:contents !important;*/
/*    }*/

/*    .toolbar > .type-btn,*/
/*    .toolbar .type-buttons .type-btn{*/
/*        width:100% !important;*/
/*        min-width:0 !important;*/
/*        max-width:none !important;*/
/*        height:46px !important;*/
/*        min-height:46px !important;*/
/*        padding:0 4px !important;*/
/*        border-radius:15px !important;*/
/*        background:#111827 !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        color:#f8fafc !important;*/
/*        font-size:11px !important;*/
/*        font-weight:950 !important;*/
/*        line-height:1 !important;*/
/*        white-space:nowrap !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .toolbar > .type-btn.active,*/
/*    .toolbar .type-buttons .type-btn.active,*/
/*    .toolbar > .type-btn:hover,*/
/*    .toolbar .type-buttons .type-btn:hover{*/
/*        background:linear-gradient(135deg,#a855f7 0%,#7c3aed 60%,#5b21b6 100%) !important;*/
/*        border-color:rgba(192,132,252,.80) !important;*/
/*        color:#fff !important;*/
/*        box-shadow:0 12px 26px rgba(124,58,237,.38) !important;*/
/*    }*/

/*    .selected-label,*/
/*    .toolbar-right,*/
/*    .chip{*/
/*        display:none !important;*/
/*    }*/

    /* ================= SEARCH + CATEGORY ================= */

/*    .categories{*/
/*        width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:minmax(0,1fr) 122px !important;*/
/*        gap:8px !important;*/
/*        margin:0 0 10px !important;*/
/*        padding:0 !important;*/
/*        align-items:center !important;*/
/*    }*/

/*    .search-wrap{*/
/*        width:100% !important;*/
/*        min-width:0 !important;*/
/*        margin:0 !important;*/
/*        order:1 !important;*/
/*    }*/

/*    .search-input{*/
/*        width:100% !important;*/
/*        height:50px !important;*/
/*        min-height:50px !important;*/
/*        padding:0 14px !important;*/
/*        border-radius:15px !important;*/
/*        background:#071021 !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        font-weight:700 !important;*/
/*        outline:none !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .search-input::placeholder{*/
/*        color:#9aa7bb !important;*/
/*    }*/

/*    .category-btn{*/
/*        display:none !important;*/
/*    }*/

/*    .category-btn.active{*/
/*        display:flex !important;*/
/*        order:2 !important;*/
/*        width:100% !important;*/
/*        height:50px !important;*/
/*        min-height:50px !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        padding:0 10px !important;*/
/*        border-radius:15px !important;*/
/*        background:#111827 !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        color:#fff !important;*/
/*        font-size:13px !important;*/
/*        font-weight:950 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .category-btn.active::after{*/
/*        content:"⌄" !important;*/
/*        margin-left:8px !important;*/
/*        color:#cbd5e1 !important;*/
/*        font-size:14px !important;*/
/*    }*/

    /* ================= PRODUCTS - FORCE 3 COLUMNS ================= */

/*    .items-grid{*/
/*        width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:repeat(3, minmax(0, 1fr)) !important;*/
/*        gap:8px !important;*/
/*        padding:0 !important;*/
/*        margin:0 !important;*/
/*        height:auto !important;*/
/*        min-height:0 !important;*/
/*        max-height:none !important;*/
/*        overflow:visible !important;*/
/*        align-content:start !important;*/
/*    }*/

/*    .item-card,*/
/*    .product-card{*/
/*        width:100% !important;*/
/*        min-width:0 !important;*/
/*        min-height:181px !important;*/
/*        height:auto !important;*/
/*        padding:0 !important;*/
/*        display:flex !important;*/
/*        flex-direction:column !important;*/
/*        overflow:hidden !important;*/
/*        border-radius:14px !important;*/
/*        background:linear-gradient(180deg,#0c1428 0%,#081021 100%) !important;*/
/*        border:1px solid rgba(148,163,184,.24) !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .item-card:hover,*/
/*    .product-card:hover{*/
/*        transform:none !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .item-img,*/
/*    .item-card .item-img,*/
/*    .product-card .item-img{*/
/*        width:100% !important;*/
/*        height:86px !important;*/
/*        min-height:86px !important;*/
/*        max-height:86px !important;*/
/*        aspect-ratio:auto !important;*/
/*        margin:0 !important;*/
/*        padding:0 !important;*/
/*        border-radius:0 !important;*/
/*        overflow:hidden !important;*/
/*        background:#111827 !important;*/
/*    }*/

/*    .item-img::after,*/
/*    .item-card .item-img::after,*/
/*    .product-card .item-img::after{*/
/*        content:none !important;*/
/*        display:none !important;*/
/*    }*/

/*    .item-img img,*/
/*    .item-card .item-img img,*/
/*    .product-card .item-img img{*/
/*        width:100% !important;*/
/*        height:100% !important;*/
/*        max-width:none !important;*/
/*        max-height:none !important;*/
/*        object-fit:cover !important;*/
/*        display:block !important;*/
/*        opacity:1 !important;*/
/*    }*/

/*    .item-title{*/
/*        min-height:33px !important;*/
/*        padding:8px 8px 0 !important;*/
/*        color:#fff !important;*/
/*        font-size:10.8px !important;*/
/*        line-height:1.25 !important;*/
/*        font-weight:950 !important;*/
/*        display:-webkit-box !important;*/
/*        -webkit-line-clamp:2 !important;*/
/*        -webkit-box-orient:vertical !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .item-sku-wrap{*/
/*        min-height:24px !important;*/
/*        padding:4px 8px 0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*    }*/

/*    .sku-mini-btn{*/
/*        height:20px !important;*/
/*        min-width:42px !important;*/
/*        max-width:100% !important;*/
/*        padding:0 7px !important;*/
/*        border-radius:7px !important;*/
/*        background:rgba(124,58,237,.24) !important;*/
/*        border:1px solid rgba(139,92,246,.45) !important;*/
/*        color:#ddd6fe !important;*/
/*        font-size:9.5px !important;*/
/*        font-weight:950 !important;*/
/*        line-height:18px !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .item-sku-wrap .item-sku,*/
/*    .item-sku{*/
/*        display:none !important;*/
/*    }*/

/*    .item-tax-badge{*/
/*        display:none !important;*/
/*    }*/

/*    .item-bottom{*/
/*        margin-top:auto !important;*/
/*        padding:6px 8px 9px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        gap:5px !important;*/
/*    }*/

/*    .item-price{*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:950 !important;*/
/*        white-space:nowrap !important;*/
/*    }*/

/*    .item-price small{*/
/*        display:inline !important;*/
/*        margin:0 3px 0 0 !important;*/
/*        color:#e2e8f0 !important;*/
/*        font-size:10px !important;*/
/*        font-weight:800 !important;*/
/*    }*/

/*    .add-btn{*/
/*        width:29px !important;*/
/*        min-width:29px !important;*/
/*        height:29px !important;*/
/*        min-height:29px !important;*/
/*        border-radius:9px !important;*/
/*        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;*/
/*        border:1px solid rgba(192,132,252,.78) !important;*/
/*        color:#fff !important;*/
/*        font-size:20px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:950 !important;*/
/*        box-shadow:0 10px 20px rgba(124,58,237,.35) !important;*/
/*    }*/

    /* ================= CUSTOMER CARD ================= */

/*    .pos-cart-customer-header{*/
/*        width:100% !important;*/
/*        min-height:68px !important;*/
/*        margin:10px 0 10px !important;*/
/*        padding:11px 12px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        gap:10px !important;*/
/*        border-radius:15px !important;*/
/*        background:*/
/*            radial-gradient(circle at right,rgba(124,58,237,.22),transparent 46%),*/
/*            rgba(15,23,42,.88) !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;*/
/*    }*/

/*    .pos-cart-customer-left{*/
/*        min-width:0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        gap:10px !important;*/
/*    }*/

/*    .pos-cart-customer-icon{*/
/*        width:42px !important;*/
/*        height:42px !important;*/
/*        min-width:42px !important;*/
/*        border-radius:50% !important;*/
/*        background:rgba(124,58,237,.18) !important;*/
/*        border:1px solid rgba(139,92,246,.42) !important;*/
/*        color:#c084fc !important;*/
/*        font-size:21px !important;*/
/*    }*/

/*    .pos-cart-customer-info h4{*/
/*        max-width:170px !important;*/
/*        margin:0 !important;*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        line-height:1.15 !important;*/
/*        font-weight:950 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-cart-customer-info small{*/
/*        display:block !important;*/
/*        margin-top:3px !important;*/
/*        color:#aeb9cc !important;*/
/*        font-size:10.5px !important;*/
/*        font-weight:600 !important;*/
/*    }*/

/*    .pos-cart-change-btn{*/
/*        height:36px !important;*/
/*        min-width:76px !important;*/
/*        padding:0 12px !important;*/
/*        border-radius:12px !important;*/
/*        background:#111827 !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        color:#fff !important;*/
/*        font-size:11px !important;*/
/*        font-weight:950 !important;*/
/*    }*/

/*    .pos-cart-phone-box{*/
/*        display:none !important;*/
/*    }*/

    /* ================= CART CARD ================= */

/*    .pos-order-items-top{*/
/*        margin:0 !important;*/
/*        padding:12px 12px 9px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        border-radius:15px 15px 0 0 !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border:1px solid rgba(148,163,184,.22) !important;*/
/*        border-bottom:0 !important;*/
/*    }*/

/*    .pos-order-items-title{*/
/*        color:#fff !important;*/
/*        font-size:13px !important;*/
/*        font-weight:950 !important;*/
/*        letter-spacing:.3px !important;*/
/*    }*/

/*    .pos-order-items-title::before{*/
/*        content:"🛒 " !important;*/
/*    }*/

/*    .pos-clear-cart-btn{*/
/*        height:31px !important;*/
/*        padding:0 9px !important;*/
/*        border-radius:10px !important;*/
/*        background:transparent !important;*/
/*        border:1px solid rgba(244,63,94,.26) !important;*/
/*        color:#fb7185 !important;*/
/*        font-size:11px !important;*/
/*        font-weight:900 !important;*/
/*    }*/

/*    .pos-cart-table-head{*/
/*        display:none !important;*/
/*    }*/

/*    .cart-items,*/
/*    .pos-cart-items-list{*/
/*        width:100% !important;*/
/*        min-height:90px !important;*/
/*        max-height:none !important;*/
/*        height:auto !important;*/
/*        margin:0 !important;*/
/*        padding:0 12px 8px !important;*/
/*        overflow:visible !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border-left:1px solid rgba(148,163,184,.22) !important;*/
/*        border-right:1px solid rgba(148,163,184,.22) !important;*/
/*    }*/

/*    .pos-cart-empty,*/
/*    .empty-state{*/
/*        min-height:82px !important;*/
/*        padding:18px 10px !important;*/
/*        border-radius:13px !important;*/
/*        background:rgba(8,13,28,.70) !important;*/
/*        border:1px dashed rgba(148,163,184,.22) !important;*/
/*        color:#94a3b8 !important;*/
/*        font-size:12px !important;*/
/*    }*/

/*    .pos-cart-item.pos-ref-row,*/
/*    .pos-cart-item.ref-row-ready,*/
/*    .pos-premium-cart .pos-cart-item.pos-ref-row,*/
/*    .pos-premium-cart .pos-cart-item.ref-row-ready{*/
/*        position:relative !important;*/
/*        width:100% !important;*/
/*        min-height:62px !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:minmax(0,1fr) 88px 54px 60px 24px !important;*/
/*        gap:6px !important;*/
/*        align-items:center !important;*/
/*        padding:8px 0 !important;*/
/*        margin:0 !important;*/
/*        border:0 !important;*/
/*        border-bottom:1px solid rgba(148,163,184,.12) !important;*/
/*        border-radius:0 !important;*/
/*        background:transparent !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-ref-product-cell,*/
/*    .ref-product-cell,*/
/*    .pos-premium-cart .pos-ref-product-cell,*/
/*    .pos-premium-cart .ref-product-cell{*/
/*        min-width:0 !important;*/
/*        width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:40px minmax(0,1fr) !important;*/
/*        gap:8px !important;*/
/*        align-items:center !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .pos-ref-img,*/
/*    .ref-cart-img,*/
/*    .pos-premium-cart .pos-ref-img,*/
/*    .pos-premium-cart .ref-cart-img{*/
/*        width:38px !important;*/
/*        min-width:38px !important;*/
/*        max-width:38px !important;*/
/*        height:38px !important;*/
/*        border-radius:8px !important;*/
/*        object-fit:cover !important;*/
/*        padding:0 !important;*/
/*        background:#111827 !important;*/
/*        border:1px solid rgba(148,163,184,.12) !important;*/
/*    }*/

/*    .pos-ref-product-text,*/
/*    .ref-product-text{*/
/*        min-width:0 !important;*/
/*        max-width:100% !important;*/
/*        display:flex !important;*/
/*        flex-direction:column !important;*/
/*        gap:3px !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .pos-cart-title,*/
/*    .pos-premium-cart .pos-cart-title{*/
/*        width:100% !important;*/
/*        max-width:100% !important;*/
/*        margin:0 !important;*/
/*        color:#fff !important;*/
/*        font-size:11.3px !important;*/
/*        line-height:1.15 !important;*/
/*        font-weight:950 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-cart-badge,*/
/*    .pos-cart-tier-badge{*/
/*        display:none !important;*/
/*    }*/

/*    .pos-cart-sku,*/
/*    .pos-premium-cart .pos-cart-sku{*/
/*        width:max-content !important;*/
/*        max-width:100% !important;*/
/*        margin:0 !important;*/
/*        padding:3px 6px !important;*/
/*        border-radius:6px !important;*/
/*        background:rgba(124,58,237,.22) !important;*/
/*        color:#c4b5fd !important;*/
/*        font-size:9.5px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:850 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-ref-qty-cell,*/
/*    .ref-qty-cell,*/
/*    .pos-premium-cart .pos-ref-qty-cell,*/
/*    .pos-premium-cart .ref-qty-cell{*/
/*        width:88px !important;*/
/*        max-width:88px !important;*/
/*        display:flex !important;*/
/*        justify-content:center !important;*/
/*        align-items:center !important;*/
/*    }*/

/*    .pos-cart-controls,*/
/*    .pos-premium-cart .pos-cart-controls{*/
/*        width:88px !important;*/
/*        height:34px !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:27px 34px 27px !important;*/
/*        align-items:center !important;*/
/*        gap:0 !important;*/
/*        transform:none !important;*/
/*        border-radius:13px !important;*/
/*        overflow:hidden !important;*/
/*        background:rgba(8,13,28,.82) !important;*/
/*        border:1px solid rgba(148,163,184,.18) !important;*/
/*    }*/

/*    .pos-cart-btn,*/
/*    .pos-premium-cart .pos-cart-btn{*/
/*        width:27px !important;*/
/*        min-width:27px !important;*/
/*        height:34px !important;*/
/*        min-height:34px !important;*/
/*        border:0 !important;*/
/*        border-radius:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        font-weight:900 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-cart-qty,*/
/*    .pos-premium-cart .pos-cart-qty{*/
/*        width:34px !important;*/
/*        min-width:34px !important;*/
/*        height:34px !important;*/
/*        border:0 !important;*/
/*        border-radius:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        text-align:center !important;*/
/*        font-size:13px !important;*/
/*        font-weight:950 !important;*/
/*        padding:0 !important;*/
/*    }*/

/*    .pos-ref-price-cell,*/
/*    .ref-price-cell,*/
/*    .pos-premium-cart .pos-ref-price-cell,*/
/*    .pos-premium-cart .ref-price-cell{*/
/*        width:54px !important;*/
/*        max-width:54px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:flex-end !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .pos-ref-price-cell::before,*/
/*    .ref-price-cell::before{*/
/*        content:"Rs" !important;*/
/*        margin-right:3px !important;*/
/*        color:#e2e8f0 !important;*/
/*        font-size:10.5px !important;*/
/*        font-weight:850 !important;*/
/*    }*/

/*    .pos-cart-price,*/
/*    .pos-premium-cart .pos-cart-price,*/
/*    .pos-premium-cart input[data-cart-price-input]{*/
/*        width:32px !important;*/
/*        min-width:32px !important;*/
/*        max-width:32px !important;*/
/*        height:24px !important;*/
/*        padding:0 !important;*/
/*        margin:0 !important;*/
/*        border:0 !important;*/
/*        outline:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        text-align:left !important;*/
/*        font-size:10.5px !important;*/
/*        line-height:24px !important;*/
/*        font-weight:900 !important;*/
/*        box-shadow:none !important;*/
/*        appearance:textfield !important;*/
/*        -moz-appearance:textfield !important;*/
/*    }*/

/*    .pos-cart-price::-webkit-inner-spin-button,*/
/*    .pos-cart-price::-webkit-outer-spin-button,*/
/*    input[data-cart-price-input]::-webkit-inner-spin-button,*/
/*    input[data-cart-price-input]::-webkit-outer-spin-button{*/
/*        -webkit-appearance:none !important;*/
/*        appearance:none !important;*/
/*        display:none !important;*/
/*        opacity:0 !important;*/
/*        margin:0 !important;*/
/*    }*/

/*    .pos-ref-total-cell,*/
/*    .ref-total-cell,*/
/*    .pos-premium-cart .pos-ref-total-cell,*/
/*    .pos-premium-cart .ref-total-cell{*/
/*        width:60px !important;*/
/*        max-width:60px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:flex-end !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .pos-cart-total,*/
/*    .pos-premium-cart .pos-cart-total,*/
/*    .pos-premium-cart [data-line-total]{*/
/*        width:60px !important;*/
/*        max-width:60px !important;*/
/*        margin:0 !important;*/
/*        color:#fff !important;*/
/*        font-size:10.5px !important;*/
/*        line-height:1 !important;*/
/*        font-weight:950 !important;*/
/*        text-align:right !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-ref-remove-cell,*/
/*    .ref-remove-cell,*/
/*    .pos-premium-cart .pos-ref-remove-cell,*/
/*    .pos-premium-cart .ref-remove-cell{*/
/*        width:24px !important;*/
/*        max-width:24px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:flex-end !important;*/
/*    }*/

/*    .pos-ref-remove-cell .pos-cart-btn,*/
/*    .ref-remove-cell .pos-cart-btn,*/
/*    .pos-premium-cart [data-cart-remove]{*/
/*        width:23px !important;*/
/*        min-width:23px !important;*/
/*        height:23px !important;*/
/*        min-height:23px !important;*/
/*        border-radius:8px !important;*/
/*        background:rgba(244,63,94,.10) !important;*/
/*        border:1px solid rgba(244,63,94,.24) !important;*/
/*        color:#fb7185 !important;*/
/*        font-size:11px !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-cart-right{*/
/*        display:contents !important;*/
/*    }*/

/*    .pos-add-custom-wrap{*/
/*        display:none !important;*/
/*    }*/

    /* ================= SUMMARY ================= */

/*    .pos-billing-summary{*/
/*        margin:0 !important;*/
/*        padding:12px 16px 10px !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border-left:1px solid rgba(148,163,184,.22) !important;*/
/*        border-right:1px solid rgba(148,163,184,.22) !important;*/
/*        border-top:1px solid rgba(148,163,184,.12) !important;*/
/*    }*/

/*    .pos-summary-row{*/
/*        min-height:28px !important;*/
/*        margin:0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        color:#e2e8f0 !important;*/
/*        font-size:14px !important;*/
/*        line-height:1 !important;*/
/*    }*/

/*    .pos-summary-row:first-child{*/
/*        display:none !important;*/
/*    }*/

/*    .pos-summary-row span{*/
/*        color:#e2e8f0 !important;*/
/*    }*/

/*    .pos-summary-row strong{*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        font-weight:900 !important;*/
/*    }*/

/*    .pos-discount-inline{*/
/*        height:34px !important;*/
/*        border-radius:9px !important;*/
/*        overflow:hidden !important;*/
/*        background:rgba(8,13,28,.86) !important;*/
/*        border:1px solid rgba(148,163,184,.16) !important;*/
/*    }*/

/*    .pos-discount-pill{*/
/*        width:38px !important;*/
/*        height:34px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;*/
/*        color:#fff !important;*/
/*        font-size:13px !important;*/
/*        font-weight:950 !important;*/
/*    }*/

/*    .pos-hidden-discount{*/
/*        width:76px !important;*/
/*        height:34px !important;*/
/*        padding:0 8px !important;*/
/*        border:0 !important;*/
/*        background:transparent !important;*/
/*        color:#e5e7eb !important;*/
/*        text-align:right !important;*/
/*        font-size:13px !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-grand-total-row{*/
/*        min-height:50px !important;*/
/*        margin-top:8px !important;*/
/*        padding-top:13px !important;*/
/*        border-top:1px dashed rgba(148,163,184,.20) !important;*/
/*    }*/

/*    .pos-grand-total-row span{*/
/*        color:#fff !important;*/
/*        font-size:18px !important;*/
/*        font-weight:950 !important;*/
/*    }*/

/*    .pos-grand-total-row strong{*/
/*        color:#a855f7 !important;*/
/*        font-size:24px !important;*/
/*        font-weight:950 !important;*/
/*        letter-spacing:.3px !important;*/
/*    }*/

    /* ================= PAYMENT + INVOICE ================= */

/*    .pos-payment-methods{*/
/*        margin:0 !important;*/
/*        padding:0 12px 12px !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:repeat(4,minmax(0,1fr)) !important;*/
/*        gap:8px !important;*/
/*        background:rgba(15,23,42,.88) !important;*/
/*        border-left:1px solid rgba(148,163,184,.22) !important;*/
/*        border-right:1px solid rgba(148,163,184,.22) !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn{*/
/*        width:100% !important;*/
/*        height:46px !important;*/
/*        min-height:46px !important;*/
/*        padding:0 4px !important;*/
/*        border-radius:13px !important;*/
/*        background:rgba(8,13,28,.86) !important;*/
/*        border:1px solid rgba(148,163,184,.18) !important;*/
/*        color:#fff !important;*/
/*        font-size:10.5px !important;*/
/*        font-weight:950 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        gap:4px !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn span{*/
/*        font-size:13px !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn.active,*/
/*    .pos-payment-methods .pay-btn:hover{*/
/*        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;*/
/*        border-color:rgba(192,132,252,.75) !important;*/
/*        box-shadow:0 12px 26px rgba(124,58,237,.34) !important;*/
/*    }*/

/*    .pos-proceed-btn,*/
/*    .invoice-btn{*/
/*        width:calc(100% - 24px) !important;*/
/*        height:58px !important;*/
/*        min-height:58px !important;*/
/*        margin:0 12px 14px !important;*/
/*        padding:0 14px !important;*/
/*        border-radius:14px !important;*/
/*        background:linear-gradient(135deg,#a855f7 0%,#7c3aed 58%,#5b21b6 100%) !important;*/
/*        border:1px solid rgba(192,132,252,.75) !important;*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        font-weight:950 !important;*/
/*        letter-spacing:.8px !important;*/
/*        text-transform:uppercase !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        gap:12px !important;*/
/*        box-shadow:0 18px 38px rgba(124,58,237,.36) !important;*/
/*    }*/

/*    .pos-proceed-arrow{*/
/*        font-size:22px !important;*/
/*        line-height:1 !important;*/
/*    }*/
/*}*/

/* =========================================================
   IMPORTANT: smallest phones par bhi 3 products + 4 tabs rahen
   This fixes your current 2-column issue.
========================================================= */

/*@media only screen and (max-width: 480px){*/

/*    .content-wrapper{*/
/*        padding:11px 10px 22px !important;*/
/*    }*/

/*    .toolbar{*/
/*        grid-template-columns:repeat(4,minmax(0,1fr)) !important;*/
/*        gap:7px !important;*/
/*    }*/

/*    .toolbar > .type-btn,*/
/*    .toolbar .type-buttons .type-btn{*/
/*        height:44px !important;*/
/*        min-height:44px !important;*/
/*        font-size:10px !important;*/
/*        padding:0 2px !important;*/
/*        border-radius:14px !important;*/
/*    }*/

/*    .categories{*/
/*        grid-template-columns:minmax(0,1fr) 112px !important;*/
/*        gap:8px !important;*/
/*    }*/

/*    .search-input,*/
/*    .category-btn.active{*/
/*        height:48px !important;*/
/*        min-height:48px !important;*/
/*        border-radius:14px !important;*/
/*    }*/

/*    .items-grid{*/
/*        grid-template-columns:repeat(3,minmax(0,1fr)) !important;*/
/*        gap:8px !important;*/
/*    }*/

/*    .item-card,*/
/*    .product-card{*/
/*        min-height:174px !important;*/
/*        border-radius:13px !important;*/
/*    }*/

/*    .item-img,*/
/*    .item-card .item-img,*/
/*    .product-card .item-img{*/
/*        height:80px !important;*/
/*        min-height:80px !important;*/
/*        max-height:80px !important;*/
/*    }*/

/*    .item-title{*/
/*        font-size:10.2px !important;*/
/*        min-height:32px !important;*/
/*        padding:7px 7px 0 !important;*/
/*    }*/

/*    .item-sku-wrap{*/
/*        padding:4px 7px 0 !important;*/
/*    }*/

/*    .sku-mini-btn{*/
/*        height:19px !important;*/
/*        min-width:40px !important;*/
/*        font-size:9px !important;*/
/*    }*/

/*    .item-bottom{*/
/*        padding:6px 7px 8px !important;*/
/*    }*/

/*    .item-price{*/
/*        font-size:13px !important;*/
/*    }*/

/*    .item-price small{*/
/*        font-size:9.5px !important;*/
/*    }*/

/*    .add-btn{*/
/*        width:28px !important;*/
/*        min-width:28px !important;*/
/*        height:28px !important;*/
/*        min-height:28px !important;*/
/*        font-size:19px !important;*/
/*    }*/

/*    .pos-cart-customer-info h4{*/
/*        max-width:155px !important;*/
/*    }*/
/*}*/

/* Do NOT allow old CSS to make 2 columns */
/*@media only screen and (max-width: 370px){*/

/*    .toolbar{*/
/*        grid-template-columns:repeat(4,minmax(0,1fr)) !important;*/
/*    }*/

/*    .items-grid{*/
/*        grid-template-columns:repeat(3,minmax(0,1fr)) !important;*/
/*    }*/

/*    .pos-payment-methods{*/
/*        grid-template-columns:repeat(4,minmax(0,1fr)) !important;*/
/*    }*/
/*}*/

/* =========================================================
   FINAL MOBILE CART BOX SAME AS REFERENCE
   Sirf cart section ke liye
   Paste at VERY END before 
========================================================= */

/*@media only screen and (max-width: 991.98px){*/

    /* ===== cart wrapper ===== */
/*    .cart-area,*/
/*    .pos-premium-cart{*/
/*        width:100% !important;*/
/*        margin-top:10px !important;*/
/*        padding:0 !important;*/
/*        background:transparent !important;*/
/*        border:0 !important;*/
/*        box-shadow:none !important;*/
/*        overflow:visible !important;*/
/*    }*/

    /* ===== customer box ===== */
/*    .pos-cart-customer-header{*/
/*        width:100% !important;*/
/*        min-height:68px !important;*/
/*        margin:0 0 10px !important;*/
/*        padding:11px 12px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        gap:10px !important;*/
/*        border-radius:14px !important;*/
/*        background:*/
/*            radial-gradient(circle at right, rgba(124,58,237,.20), transparent 45%),*/
/*            linear-gradient(180deg,#11182c 0%,#0c1326 100%) !important;*/
/*        border:1px solid rgba(148,163,184,.18) !important;*/
/*        box-shadow:0 10px 28px rgba(0,0,0,.18) !important;*/
/*    }*/

/*    .pos-cart-customer-left{*/
/*        min-width:0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        gap:10px !important;*/
/*    }*/

/*    .pos-cart-customer-icon{*/
/*        width:38px !important;*/
/*        height:38px !important;*/
/*        min-width:38px !important;*/
/*        border-radius:50% !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        background:rgba(124,58,237,.16) !important;*/
/*        border:1px solid rgba(139,92,246,.35) !important;*/
/*        color:#b388ff !important;*/
/*        font-size:18px !important;*/
/*    }*/

/*    .pos-cart-customer-info h4{*/
/*        margin:0 !important;*/
/*        max-width:165px !important;*/
/*        color:#fff !important;*/
/*        font-size:13px !important;*/
/*        line-height:1.15 !important;*/
/*        font-weight:900 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .pos-cart-customer-info small{*/
/*        display:block !important;*/
/*        margin-top:3px !important;*/
/*        color:#aab4c8 !important;*/
/*        font-size:10px !important;*/
/*        font-weight:600 !important;*/
/*    }*/

/*    .pos-cart-change-btn{*/
/*        height:34px !important;*/
/*        min-width:72px !important;*/
/*        padding:0 12px !important;*/
/*        border-radius:10px !important;*/
/*        background:#111827 !important;*/
/*        border:1px solid rgba(148,163,184,.18) !important;*/
/*        color:#fff !important;*/
/*        font-size:11px !important;*/
/*        font-weight:900 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-cart-phone-box{*/
/*        display:none !important;*/
/*    }*/

    /* ===== cart head ===== */
/*    .pos-order-items-top{*/
/*        margin:0 !important;*/
/*        padding:11px 12px 9px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        gap:10px !important;*/
/*        background:linear-gradient(180deg,#10192d 0%,#0d1528 100%) !important;*/
/*        border:1px solid rgba(148,163,184,.18) !important;*/
/*        border-bottom:0 !important;*/
/*        border-radius:14px 14px 0 0 !important;*/
/*    }*/

/*    .pos-order-items-title{*/
/*        color:#fff !important;*/
/*        font-size:13px !important;*/
/*        font-weight:900 !important;*/
/*        letter-spacing:.2px !important;*/
/*        text-transform:uppercase !important;*/
/*    }*/

/*    .pos-order-items-title::before{*/
/*        content:"🛒 " !important;*/
/*    }*/

/*    .pos-clear-cart-btn{*/
/*        height:30px !important;*/
/*        padding:0 8px !important;*/
/*        border-radius:9px !important;*/
/*        background:transparent !important;*/
/*        border:1px solid rgba(244,63,94,.22) !important;*/
/*        color:#fb7185 !important;*/
/*        font-size:11px !important;*/
/*        font-weight:900 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-cart-table-head{*/
/*        display:none !important;*/
/*    }*/

    /* ===== cart item list ===== */
/*    .cart-items,*/
/*    .pos-cart-items-list{*/
/*        width:100% !important;*/
/*        margin:0 !important;*/
/*        padding:0 12px 8px !important;*/
/*        background:linear-gradient(180deg,#0f182c 0%,#0c1325 100%) !important;*/
/*        border-left:1px solid rgba(148,163,184,.18) !important;*/
/*        border-right:1px solid rgba(148,163,184,.18) !important;*/
/*        overflow:visible !important;*/
/*        max-height:none !important;*/
/*    }*/

/*    .empty-state,*/
/*    .pos-cart-empty{*/
/*        min-height:85px !important;*/
/*        padding:18px 10px !important;*/
/*        border-radius:12px !important;*/
/*        background:rgba(8,13,28,.72) !important;*/
/*        border:1px dashed rgba(148,163,184,.22) !important;*/
/*        color:#94a3b8 !important;*/
/*        font-size:12px !important;*/
/*    }*/

    /* ===== exact mobile row ===== */
/*    .cart-area .pos-cart-item,*/
/*    .pos-premium-cart .pos-cart-item,*/
/*    .cart-area .pos-cart-item.ref-row-ready,*/
/*    .cart-area .pos-cart-item.pos-ref-row{*/
/*        position:relative !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:minmax(0,1fr) 88px 50px 60px 24px !important;*/
/*        gap:6px !important;*/
/*        align-items:center !important;*/
/*        width:100% !important;*/
/*        min-height:62px !important;*/
/*        padding:8px 0 !important;*/
/*        margin:0 !important;*/
/*        background:transparent !important;*/
/*        border:0 !important;*/
/*        border-bottom:1px solid rgba(148,163,184,.10) !important;*/
/*        border-radius:0 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

    /* left product column */
/*    .cart-area .pos-cart-left,*/
/*    .pos-premium-cart .pos-cart-left,*/
/*    .cart-area .ref-product-cell,*/
/*    .cart-area .pos-ref-product-cell{*/
/*        min-width:0 !important;*/
/*        width:100% !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:38px minmax(0,1fr) !important;*/
/*        gap:8px !important;*/
/*        align-items:center !important;*/
/*        overflow:hidden !important;*/
/*    }*/

/*    .cart-area .ref-cart-img,*/
/*    .cart-area .pos-ref-img,*/
/*    .cart-area .pos-cart-item img{*/
/*        width:36px !important;*/
/*        height:36px !important;*/
/*        min-width:36px !important;*/
/*        max-width:36px !important;*/
/*        border-radius:7px !important;*/
/*        object-fit:cover !important;*/
/*        background:#111827 !important;*/
/*        border:1px solid rgba(148,163,184,.12) !important;*/
/*    }*/

/*    .cart-area .pos-cart-title,*/
/*    .pos-premium-cart .pos-cart-title{*/
/*        margin:0 !important;*/
/*        color:#fff !important;*/
/*        font-size:11px !important;*/
/*        line-height:1.15 !important;*/
/*        font-weight:900 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

/*    .cart-area .pos-cart-badge,*/
/*    .cart-area .pos-cart-tier-badge{*/
/*        display:none !important;*/
/*    }*/

/*    .cart-area .pos-cart-sku,*/
/*    .pos-premium-cart .pos-cart-sku{*/
/*        width:max-content !important;*/
/*        max-width:100% !important;*/
/*        margin:2px 0 0 !important;*/
/*        padding:3px 6px !important;*/
/*        border-radius:6px !important;*/
/*        background:rgba(124,58,237,.20) !important;*/
/*        color:#c4b5fd !important;*/
/*        font-size:9px !important;*/
/*        font-weight:800 !important;*/
/*        line-height:1 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

    /* qty column */
/*    .cart-area .pos-cart-controls,*/
/*    .pos-premium-cart .pos-cart-controls{*/
/*        width:88px !important;*/
/*        height:34px !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:27px 34px 27px !important;*/
/*        align-items:center !important;*/
/*        gap:0 !important;*/
/*        border-radius:12px !important;*/
/*        overflow:hidden !important;*/
/*        background:#091120 !important;*/
/*        border:1px solid rgba(148,163,184,.16) !important;*/
/*        justify-self:center !important;*/
/*    }*/

/*    .cart-area .pos-cart-btn,*/
/*    .pos-premium-cart .pos-cart-btn{*/
/*        width:27px !important;*/
/*        min-width:27px !important;*/
/*        height:34px !important;*/
/*        min-height:34px !important;*/
/*        border:0 !important;*/
/*        border-radius:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        font-weight:900 !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .cart-area .pos-cart-qty,*/
/*    .pos-premium-cart .pos-cart-qty{*/
/*        width:34px !important;*/
/*        min-width:34px !important;*/
/*        height:34px !important;*/
/*        border:0 !important;*/
/*        border-radius:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        text-align:center !important;*/
/*        font-size:13px !important;*/
/*        font-weight:900 !important;*/
/*        padding:0 !important;*/
/*    }*/

    /* price */
/*    .cart-area .pos-cart-price,*/
/*    .pos-premium-cart .pos-cart-price,*/
/*    .cart-area input[data-cart-price-input]{*/
/*        width:32px !important;*/
/*        min-width:32px !important;*/
/*        max-width:32px !important;*/
/*        height:22px !important;*/
/*        padding:0 !important;*/
/*        margin:0 !important;*/
/*        border:0 !important;*/
/*        outline:0 !important;*/
/*        background:transparent !important;*/
/*        color:#fff !important;*/
/*        text-align:left !important;*/
/*        font-size:10px !important;*/
/*        font-weight:900 !important;*/
/*        line-height:22px !important;*/
/*        appearance:textfield !important;*/
/*        -moz-appearance:textfield !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .cart-area .pos-cart-price::-webkit-inner-spin-button,*/
/*    .cart-area .pos-cart-price::-webkit-outer-spin-button,*/
/*    .cart-area input[data-cart-price-input]::-webkit-inner-spin-button,*/
/*    .cart-area input[data-cart-price-input]::-webkit-outer-spin-button{*/
/*        -webkit-appearance:none !important;*/
/*        appearance:none !important;*/
/*        display:none !important;*/
/*        margin:0 !important;*/
/*    }*/

    /* total */
/*    .cart-area .pos-cart-total,*/
/*    .pos-premium-cart .pos-cart-total,*/
/*    .cart-area [data-line-total]{*/
/*        width:60px !important;*/
/*        max-width:60px !important;*/
/*        margin:0 !important;*/
/*        color:#fff !important;*/
/*        text-align:right !important;*/
/*        font-size:10px !important;*/
/*        font-weight:900 !important;*/
/*        white-space:nowrap !important;*/
/*        overflow:hidden !important;*/
/*        text-overflow:ellipsis !important;*/
/*    }*/

    /* remove */
/*    .cart-area .pos-cart-btn[data-cart-remove],*/
/*    .cart-area .ref-remove-cell .pos-cart-btn,*/
/*    .pos-premium-cart [data-cart-remove]{*/
/*        width:23px !important;*/
/*        min-width:23px !important;*/
/*        height:23px !important;*/
/*        min-height:23px !important;*/
/*        border-radius:8px !important;*/
/*        background:rgba(244,63,94,.10) !important;*/
/*        border:1px solid rgba(244,63,94,.24) !important;*/
/*        color:#fb7185 !important;*/
/*        font-size:11px !important;*/
/*    }*/

/*    .cart-area .pos-cart-right{*/
/*        display:contents !important;*/
/*        width:auto !important;*/
/*        min-width:0 !important;*/
/*        max-width:none !important;*/
/*        padding-left:0 !important;*/
/*    }*/

    /* ===== summary box ===== */
/*    .pos-billing-summary{*/
/*        margin:0 !important;*/
/*        padding:12px 14px 10px !important;*/
/*        background:linear-gradient(180deg,#10192d 0%,#0d1528 100%) !important;*/
/*        border-left:1px solid rgba(148,163,184,.18) !important;*/
/*        border-right:1px solid rgba(148,163,184,.18) !important;*/
/*        border-top:1px solid rgba(148,163,184,.10) !important;*/
/*    }*/

/*    .pos-summary-row{*/
/*        min-height:26px !important;*/
/*        margin:0 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:space-between !important;*/
/*        color:#dbe7f5 !important;*/
/*        font-size:13px !important;*/
/*        line-height:1 !important;*/
/*    }*/

/*    .pos-summary-row:first-child{*/
/*        display:none !important;*/
/*    }*/

/*    .pos-summary-row span{*/
/*        color:#dbe7f5 !important;*/
/*    }*/

/*    .pos-summary-row strong{*/
/*        color:#fff !important;*/
/*        font-size:13px !important;*/
/*        font-weight:900 !important;*/
/*    }*/

/*    .pos-discount-inline{*/
/*        height:32px !important;*/
/*        border-radius:8px !important;*/
/*        overflow:hidden !important;*/
/*        background:#091120 !important;*/
/*        border:1px solid rgba(148,163,184,.16) !important;*/
/*    }*/

/*    .pos-discount-pill{*/
/*        width:34px !important;*/
/*        height:32px !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;*/
/*        color:#fff !important;*/
/*        font-size:12px !important;*/
/*        font-weight:900 !important;*/
/*    }*/

/*    .pos-hidden-discount{*/
/*        width:72px !important;*/
/*        height:32px !important;*/
/*        padding:0 8px !important;*/
/*        border:0 !important;*/
/*        background:transparent !important;*/
/*        color:#e5e7eb !important;*/
/*        text-align:right !important;*/
/*        font-size:12px !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-grand-total-row{*/
/*        min-height:44px !important;*/
/*        margin-top:7px !important;*/
/*        padding-top:10px !important;*/
/*        border-top:1px dashed rgba(148,163,184,.18) !important;*/
/*    }*/

/*    .pos-grand-total-row span{*/
/*        color:#fff !important;*/
/*        font-size:17px !important;*/
/*        font-weight:900 !important;*/
/*    }*/

/*    .pos-grand-total-row strong{*/
/*        color:#a855f7 !important;*/
/*        font-size:22px !important;*/
/*        font-weight:900 !important;*/
/*    }*/

    /* ===== payment row ===== */
/*    .pos-payment-methods{*/
/*        margin:0 !important;*/
/*        padding:0 12px 12px !important;*/
/*        display:grid !important;*/
/*        grid-template-columns:repeat(4,minmax(0,1fr)) !important;*/
/*        gap:8px !important;*/
/*        background:linear-gradient(180deg,#10192d 0%,#0d1528 100%) !important;*/
/*        border-left:1px solid rgba(148,163,184,.18) !important;*/
/*        border-right:1px solid rgba(148,163,184,.18) !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn{*/
/*        width:100% !important;*/
/*        height:44px !important;*/
/*        min-height:44px !important;*/
/*        padding:0 4px !important;*/
/*        border-radius:12px !important;*/
/*        background:#0b1324 !important;*/
/*        border:1px solid rgba(148,163,184,.16) !important;*/
/*        color:#fff !important;*/
/*        font-size:10px !important;*/
/*        font-weight:900 !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        gap:4px !important;*/
/*        box-shadow:none !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn span{*/
/*        font-size:13px !important;*/
/*    }*/

/*    .pos-payment-methods .pay-btn.active,*/
/*    .pos-payment-methods .pay-btn:hover{*/
/*        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;*/
/*        border-color:rgba(192,132,252,.72) !important;*/
/*        box-shadow:0 10px 22px rgba(124,58,237,.30) !important;*/
/*    }*/

    /* ===== invoice button ===== */
/*    .pos-proceed-btn,*/
/*    .invoice-btn{*/
/*        width:calc(100% - 24px) !important;*/
/*        height:54px !important;*/
/*        min-height:54px !important;*/
/*        margin:0 12px 14px !important;*/
/*        padding:0 14px !important;*/
/*        border-radius:13px !important;*/
/*        background:linear-gradient(135deg,#a855f7 0%,#7c3aed 58%,#5b21b6 100%) !important;*/
/*        border:1px solid rgba(192,132,252,.74) !important;*/
/*        color:#fff !important;*/
/*        font-size:14px !important;*/
/*        font-weight:900 !important;*/
/*        letter-spacing:.6px !important;*/
/*        text-transform:uppercase !important;*/
/*        display:flex !important;*/
/*        align-items:center !important;*/
/*        justify-content:center !important;*/
/*        gap:10px !important;*/
/*        box-shadow:0 16px 34px rgba(124,58,237,.34) !important;*/
/*    }*/

/*    .pos-proceed-arrow{*/
/*        font-size:20px !important;*/
/*        line-height:1 !important;*/
/*    }*/

    /* custom product mobile me hide */
/*    .pos-add-custom-wrap,*/
/*    .custom-product-box{*/
/*        display:none !important;*/
/*    }*/
/*}*/

/* slightly smaller phones */
/*@media only screen and (max-width: 430px){*/

/*    .pos-cart-customer-info h4{*/
/*        max-width:150px !important;*/
/*        font-size:13px !important;*/
/*    }*/

/*    .cart-area .pos-cart-item,*/
/*    .pos-premium-cart .pos-cart-item{*/
/*        grid-template-columns:minmax(0,1fr) 84px 48px 56px 22px !important;*/
/*    }*/

/*    .cart-area .pos-cart-controls,*/
/*    .pos-premium-cart .pos-cart-controls{*/
/*        width:84px !important;*/
/*        grid-template-columns:25px 34px 25px !important;*/
/*    }*/

/*    .cart-area .pos-cart-total,*/
/*    .pos-premium-cart .pos-cart-total{*/
/*        width:56px !important;*/
/*        max-width:56px !important;*/
/*        font-size:9.8px !important;*/
/*    }*/

/*    .pos-grand-total-row strong{*/
/*        font-size:21px !important;*/
/*    }*/
/*}*/





/* =========================================================
   FINAL MOBILE FIX:
   6 PRODUCTS VISIBLE + PRODUCTS INTERNAL SCROLL + CART FRONT
   Paste this at the VERY END before
========================================================= */

@media only screen and (max-width: 991.98px){

    html,
    body{
        width:100% !important;
        min-height:100dvh !important;
        height:auto !important;
        overflow-x:hidden !important;
        overflow-y:auto !important;
        background:#120938 !important;
    }

    .container-scroll,
    .main-panel{
        width:100% !important;
        height:auto !important;
        min-height:100dvh !important;
        max-height:none !important;
        display:block !important;
        overflow:visible !important;
        background:transparent !important;
    }

    .content-wrapper{
        width:100% !important;
        height:auto !important;
        min-height:auto !important;
        max-height:none !important;
        overflow:visible !important;
        padding:0 10px 18px !important;
        background:
            radial-gradient(circle at 50% 0%, rgba(124,58,237,.35), transparent 34%),
            linear-gradient(180deg,#201060 0%,#160940 42%,#080f22 100%) !important;
    }

    .pos-layout{
        width:100% !important;
        height:auto !important;
        min-height:0 !important;
        display:flex !important;
        flex-direction:column !important;
        gap:10px !important;
        overflow:visible !important;
        background:transparent !important;
    }

    .items-area,
    .cart-area{
        width:100% !important;
        height:auto !important;
        min-height:0 !important;
        max-height:none !important;
        padding:0 !important;
        margin:0 !important;
        background:transparent !important;
        border:0 !important;
        box-shadow:none !important;
        overflow:visible !important;
    }

    /* TOPBAR */
    .topbar{
        position:sticky !important;
        top:0 !important;
        z-index:9999 !important;
        width:100% !important;
        height:74px !important;
        min-height:74px !important;
        max-height:74px !important;
        padding:10px 14px !important;
        background:#050b17 !important;
        border-bottom:1px solid rgba(148,163,184,.16) !important;
    }

    .topbar-right{
        gap:12px !important;
    }

    .topbar-right .user-pill{
        display:none !important;
    }

    .icon-btn,
    .topbar-right .icon-btn{
        width:48px !important;
        height:48px !important;
        min-width:48px !important;
        border-radius:17px !important;
        background:#101827 !important;
        border:1px solid rgba(148,163,184,.24) !important;
        color:#e5edf8 !important;
        box-shadow:none !important;
    }

    /* WHOLESALE BUTTONS - 4 SAME LINE */
    .toolbar{
        width:100% !important;
        display:grid !important;
        grid-template-columns:repeat(4,minmax(0,1fr)) !important;
        gap:8px !important;
        margin:12px 0 10px !important;
        padding:0 !important;
    }

    .type-buttons{
        display:contents !important;
    }

    .toolbar > .type-btn,
    .toolbar .type-buttons .type-btn{
        width:100% !important;
        min-width:0 !important;
        height:44px !important;
        min-height:44px !important;
        padding:0 3px !important;
        border-radius:14px !important;
        background:#111827 !important;
        border:1px solid rgba(148,163,184,.22) !important;
        color:#fff !important;
        font-size:10px !important;
        line-height:1 !important;
        font-weight:950 !important;
        white-space:nowrap !important;
        box-shadow:none !important;
    }

    .toolbar > .type-btn.active,
    .toolbar .type-buttons .type-btn.active{
        background:linear-gradient(135deg,#a855f7,#7c3aed 60%,#5b21b6) !important;
        border-color:rgba(192,132,252,.78) !important;
        color:#fff !important;
        box-shadow:0 12px 26px rgba(124,58,237,.38) !important;
    }

    .selected-label,
    .toolbar-right,
    .chip{
        display:none !important;
    }

    /* SEARCH + CATEGORY */
    .categories{
        width:100% !important;
        display:grid !important;
        grid-template-columns:minmax(0,1fr) 112px !important;
        gap:8px !important;
        margin:0 0 10px !important;
        padding:0 !important;
    }

    .search-wrap{
        width:100% !important;
        min-width:0 !important;
        margin:0 !important;
    }

    .search-input{
        width:100% !important;
        height:48px !important;
        min-height:48px !important;
        padding:0 14px !important;
        border-radius:14px !important;
        background:#071021 !important;
        border:1px solid rgba(148,163,184,.22) !important;
        color:#fff !important;
        font-size:14px !important;
        font-weight:800 !important;
    }

    .search-input::placeholder{
        color:#9aa7bb !important;
    }

    .category-btn{
        display:none !important;
    }

    .category-btn.active{
        display:flex !important;
        width:100% !important;
        height:48px !important;
        align-items:center !important;
        justify-content:center !important;
        border-radius:14px !important;
        background:#111827 !important;
        border:1px solid rgba(148,163,184,.22) !important;
        color:#fff !important;
        font-size:13px !important;
        font-weight:950 !important;
    }

    .category-btn.active::after{
        content:"⌄" !important;
        margin-left:8px !important;
        color:#cbd5e1 !important;
    }

    /* IMPORTANT:
       Sirf 6 products visible.
       Baqi products isi grid ke andar scroll hongi.
       Cart ab 6 products ke baad upar hi aa jayega.
    */
    .items-grid{
        width:100% !important;
        display:grid !important;
        grid-template-columns:repeat(3,minmax(0,1fr)) !important;
        gap:8px !important;
        padding:0 0 2px !important;
        margin:0 !important;

        height:336px !important;
        min-height:336px !important;
        max-height:336px !important;

        overflow-y:auto !important;
        overflow-x:hidden !important;
        align-content:start !important;
        scrollbar-width:none !important;
        -ms-overflow-style:none !important;
    }

    .items-grid::-webkit-scrollbar{
        display:none !important;
    }

    .item-card,
    .product-card{
        width:100% !important;
        min-width:0 !important;
        height:164px !important;
        min-height:164px !important;
        max-height:164px !important;
        padding:0 !important;
        display:flex !important;
        flex-direction:column !important;
        overflow:hidden !important;
        border-radius:13px !important;
        background:linear-gradient(180deg,#0c1428 0%,#081021 100%) !important;
        border:1px solid rgba(148,163,184,.24) !important;
        box-shadow:none !important;
    }

    .item-img,
    .item-card .item-img,
    .product-card .item-img{
        width:100% !important;
        height:74px !important;
        min-height:74px !important;
        max-height:74px !important;
        margin:0 !important;
        padding:0 !important;
        border-radius:0 !important;
        overflow:hidden !important;
        background:#111827 !important;
    }

    .item-img::after{
        content:none !important;
        display:none !important;
    }

    .item-img img,
    .item-card .item-img img,
    .product-card .item-img img{
        width:100% !important;
        height:100% !important;
        max-width:none !important;
        max-height:none !important;
        object-fit:cover !important;
        display:block !important;
        opacity:1 !important;
    }

    .item-title{
        min-height:31px !important;
        padding:7px 7px 0 !important;
        color:#fff !important;
        font-size:10px !important;
        line-height:1.22 !important;
        font-weight:950 !important;
        display:-webkit-box !important;
        -webkit-line-clamp:2 !important;
        -webkit-box-orient:vertical !important;
        overflow:hidden !important;
    }

    .item-sku-wrap{
        min-height:22px !important;
        padding:4px 7px 0 !important;
        display:flex !important;
        align-items:center !important;
    }

    .sku-mini-btn{
        height:19px !important;
        min-width:40px !important;
        padding:0 7px !important;
        border-radius:7px !important;
        background:rgba(124,58,237,.24) !important;
        border:1px solid rgba(139,92,246,.45) !important;
        color:#ddd6fe !important;
        font-size:9px !important;
        font-weight:950 !important;
        line-height:17px !important;
    }

    .item-sku,
    .item-sku-wrap .item-sku,
    .item-tax-badge{
        display:none !important;
    }

    .item-bottom{
        margin-top:auto !important;
        padding:5px 7px 8px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:space-between !important;
        gap:5px !important;
    }

    .item-price{
        color:#fff !important;
        font-size:13px !important;
        line-height:1 !important;
        font-weight:950 !important;
        white-space:nowrap !important;
    }

    .item-price small{
        display:inline !important;
        margin:0 3px 0 0 !important;
        color:#e2e8f0 !important;
        font-size:9.5px !important;
        font-weight:800 !important;
    }

    .add-btn{
        width:28px !important;
        min-width:28px !important;
        height:28px !important;
        min-height:28px !important;
        border-radius:9px !important;
        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;
        border:1px solid rgba(192,132,252,.78) !important;
        color:#fff !important;
        font-size:19px !important;
        line-height:1 !important;
        font-weight:950 !important;
        box-shadow:0 10px 20px rgba(124,58,237,.35) !important;
    }

    /* CART FRONT PAGE PAR - products ke just baad */
    .cart-area{
        margin-top:10px !important;
    }

    .pos-cart-customer-header{
        width:100% !important;
        min-height:62px !important;
        margin:0 0 8px !important;
        padding:10px 11px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:space-between !important;
        gap:10px !important;
        border-radius:14px !important;
        background:
            radial-gradient(circle at right,rgba(124,58,237,.22),transparent 45%),
            rgba(15,23,42,.90) !important;
        border:1px solid rgba(148,163,184,.20) !important;
        box-shadow:none !important;
    }

    .pos-cart-customer-left{
        min-width:0 !important;
        display:flex !important;
        align-items:center !important;
        gap:10px !important;
    }

    .pos-cart-customer-icon{
        width:38px !important;
        height:38px !important;
        min-width:38px !important;
        border-radius:50% !important;
        background:rgba(124,58,237,.18) !important;
        border:1px solid rgba(139,92,246,.40) !important;
        color:#c084fc !important;
    }

    .pos-cart-customer-info h4{
        max-width:155px !important;
        margin:0 !important;
        color:#fff !important;
        font-size:13px !important;
        line-height:1.15 !important;
        font-weight:950 !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
    }

    .pos-cart-customer-info small{
        display:block !important;
        margin-top:3px !important;
        color:#aeb9cc !important;
        font-size:10px !important;
    }

    .pos-cart-change-btn{
        height:34px !important;
        min-width:72px !important;
        padding:0 11px !important;
        border-radius:11px !important;
        background:#111827 !important;
        border:1px solid rgba(148,163,184,.20) !important;
        color:#fff !important;
        font-size:10.5px !important;
        font-weight:900 !important;
    }

    .pos-cart-phone-box,
    .pos-add-custom-wrap,
    .custom-product-box{
        display:none !important;
    }

    .pos-order-items-top{
        margin:0 !important;
        padding:11px 12px 8px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:space-between !important;
        border-radius:14px 14px 0 0 !important;
        background:rgba(15,23,42,.90) !important;
        border:1px solid rgba(148,163,184,.20) !important;
        border-bottom:0 !important;
    }

    .pos-order-items-title{
        color:#fff !important;
        font-size:12.5px !important;
        font-weight:950 !important;
        letter-spacing:.3px !important;
        text-transform:uppercase !important;
    }

    .pos-order-items-title::before{
        content:"🛒 " !important;
    }

    .pos-clear-cart-btn{
        height:30px !important;
        padding:0 8px !important;
        border-radius:9px !important;
        background:transparent !important;
        border:1px solid rgba(244,63,94,.24) !important;
        color:#fb7185 !important;
        font-size:10.5px !important;
        font-weight:900 !important;
    }

    .pos-cart-table-head{
        display:none !important;
    }

    .cart-items,
    .pos-cart-items-list{
        width:100% !important;
        margin:0 !important;
        padding:0 12px 8px !important;
        max-height:none !important;
        overflow:visible !important;
        background:rgba(15,23,42,.90) !important;
        border-left:1px solid rgba(148,163,184,.20) !important;
        border-right:1px solid rgba(148,163,184,.20) !important;
    }

    .pos-cart-item,
    .pos-cart-item.ref-row-ready,
    .pos-cart-item.pos-ref-row{
        position:relative !important;
        width:100% !important;
        min-height:58px !important;
        display:grid !important;
        grid-template-columns:minmax(0,1fr) 82px 50px 56px 22px !important;
        gap:5px !important;
        align-items:center !important;
        padding:7px 0 !important;
        margin:0 !important;
        background:transparent !important;
        border:0 !important;
        border-bottom:1px solid rgba(148,163,184,.11) !important;
        border-radius:0 !important;
        box-shadow:none !important;
    }

    .pos-ref-product-cell,
    .ref-product-cell,
    .pos-cart-left{
        min-width:0 !important;
        width:100% !important;
        display:grid !important;
        grid-template-columns:38px minmax(0,1fr) !important;
        gap:7px !important;
        align-items:center !important;
        overflow:hidden !important;
    }

    .pos-ref-img,
    .ref-cart-img,
    .pos-cart-item img{
        width:36px !important;
        min-width:36px !important;
        max-width:36px !important;
        height:36px !important;
        border-radius:7px !important;
        object-fit:cover !important;
        background:#111827 !important;
    }

    .pos-cart-title{
        color:#fff !important;
        font-size:10.8px !important;
        line-height:1.15 !important;
        font-weight:950 !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
    }

    .pos-cart-badge,
    .pos-cart-tier-badge{
        display:none !important;
    }

    .pos-cart-sku{
        width:max-content !important;
        max-width:100% !important;
        margin-top:2px !important;
        padding:3px 6px !important;
        border-radius:6px !important;
        background:rgba(124,58,237,.22) !important;
        color:#c4b5fd !important;
        font-size:9px !important;
        line-height:1 !important;
        font-weight:850 !important;
    }

    .pos-cart-controls{
        width:82px !important;
        height:32px !important;
        display:grid !important;
        grid-template-columns:24px 34px 24px !important;
        gap:0 !important;
        align-items:center !important;
        border-radius:12px !important;
        overflow:hidden !important;
        background:rgba(8,13,28,.86) !important;
        border:1px solid rgba(148,163,184,.16) !important;
    }

    .pos-cart-btn{
        width:24px !important;
        min-width:24px !important;
        height:32px !important;
        min-height:32px !important;
        border:0 !important;
        border-radius:0 !important;
        background:transparent !important;
        color:#fff !important;
        font-size:13px !important;
        font-weight:900 !important;
        box-shadow:none !important;
    }

    .pos-cart-qty{
        width:34px !important;
        min-width:34px !important;
        height:32px !important;
        border:0 !important;
        background:transparent !important;
        color:#fff !important;
        font-size:12.5px !important;
        font-weight:950 !important;
        text-align:center !important;
        padding:0 !important;
    }

    .pos-cart-right{
        display:contents !important;
        width:auto !important;
        min-width:0 !important;
        max-width:none !important;
        padding:0 !important;
    }

    .pos-cart-price{
        width:50px !important;
        max-width:50px !important;
        height:22px !important;
        padding:0 !important;
        border:0 !important;
        background:transparent !important;
        color:#fff !important;
        font-size:10px !important;
        font-weight:900 !important;
        text-align:center !important;
        box-shadow:none !important;
        appearance:textfield !important;
        -moz-appearance:textfield !important;
    }

    .pos-cart-price::-webkit-inner-spin-button,
    .pos-cart-price::-webkit-outer-spin-button{
        -webkit-appearance:none !important;
        display:none !important;
    }

    .pos-cart-total{
        width:56px !important;
        max-width:56px !important;
        margin:0 !important;
        color:#fff !important;
        text-align:right !important;
        font-size:10px !important;
        font-weight:950 !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
    }

    .pos-cart-btn[data-cart-remove]{
        width:22px !important;
        min-width:22px !important;
        height:22px !important;
        min-height:22px !important;
        border-radius:8px !important;
        background:rgba(244,63,94,.10) !important;
        border:1px solid rgba(244,63,94,.24) !important;
        color:#fb7185 !important;
        font-size:10px !important;
    }

    .pos-billing-summary{
        margin:0 !important;
        padding:10px 14px 9px !important;
        background:rgba(15,23,42,.90) !important;
        border-left:1px solid rgba(148,163,184,.20) !important;
        border-right:1px solid rgba(148,163,184,.20) !important;
        border-top:1px solid rgba(148,163,184,.10) !important;
    }

    .pos-summary-row{
        min-height:25px !important;
        margin:0 !important;
        display:flex !important;
        align-items:center !important;
        justify-content:space-between !important;
        color:#dbe7f5 !important;
        font-size:12.5px !important;
    }

    .pos-summary-row:first-child{
        display:none !important;
    }

    .pos-summary-row strong{
        color:#fff !important;
        font-size:12.5px !important;
        font-weight:900 !important;
    }

    .pos-grand-total-row{
        min-height:42px !important;
        margin-top:6px !important;
        padding-top:10px !important;
        border-top:1px dashed rgba(148,163,184,.18) !important;
    }

    .pos-grand-total-row span{
        color:#fff !important;
        font-size:16px !important;
        font-weight:950 !important;
    }

    .pos-grand-total-row strong{
        color:#a855f7 !important;
        font-size:21px !important;
        font-weight:950 !important;
    }

    .pos-payment-methods{
        margin:0 !important;
        padding:0 12px 11px !important;
        display:grid !important;
        grid-template-columns:repeat(4,minmax(0,1fr)) !important;
        gap:7px !important;
        background:rgba(15,23,42,.90) !important;
        border-left:1px solid rgba(148,163,184,.20) !important;
        border-right:1px solid rgba(148,163,184,.20) !important;
    }

    .pos-payment-methods .pay-btn{
        height:42px !important;
        min-height:42px !important;
        border-radius:12px !important;
        background:#0b1324 !important;
        border:1px solid rgba(148,163,184,.16) !important;
        color:#fff !important;
        font-size:9.8px !important;
        font-weight:950 !important;
        padding:0 3px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        gap:4px !important;
    }

    .pos-payment-methods .pay-btn.active{
        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;
        border-color:rgba(192,132,252,.72) !important;
    }

    .pos-proceed-btn,
    .invoice-btn{
        width:calc(100% - 24px) !important;
        height:52px !important;
        min-height:52px !important;
        margin:0 12px 12px !important;
        border-radius:13px !important;
        background:linear-gradient(135deg,#a855f7,#7c3aed 58%,#5b21b6) !important;
        border:1px solid rgba(192,132,252,.74) !important;
        color:#fff !important;
        font-size:13.5px !important;
        font-weight:950 !important;
        letter-spacing:.6px !important;
        text-transform:uppercase !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        gap:10px !important;
        box-shadow:0 16px 34px rgba(124,58,237,.34) !important;
    }
}



/* =========================================================
   POS MOBILE CART BOTTOM FIX
   Grand Total invoice button ke paas + invoice sticky bottom
   Paste at VERY END before 
========================================================= */

@media only screen and (max-width: 991.98px){

    body{
        padding-bottom:92px !important;
    }

    .content-wrapper{
        padding-bottom:105px !important;
    }

    .cart-area,
    .pos-premium-cart{
        padding-bottom:0 !important;
        margin-bottom:0 !important;
    }

    /* Summary ko compact karo */
    .pos-billing-summary{
        display:flex !important;
        flex-direction:column !important;
        gap:0 !important;
        padding:8px 12px 6px !important;
        margin:0 !important;
        background:rgba(15,23,42,.92) !important;
        border-left:1px solid rgba(148,163,184,.20) !important;
        border-right:1px solid rgba(148,163,184,.20) !important;
        border-top:1px solid rgba(148,163,184,.10) !important;
    }

    /* Subtotal / Discount / Tax compact */
    .pos-summary-row{
        min-height:22px !important;
        height:22px !important;
        margin:0 !important;
        padding:0 !important;
        font-size:11.5px !important;
        line-height:1 !important;
        display:flex !important;
        align-items:center !important;
        justify-content:space-between !important;
        color:#dbe7f5 !important;
    }

    .pos-summary-row strong{
        font-size:11.5px !important;
        font-weight:900 !important;
        color:#fff !important;
    }

    .pos-discount-inline{
        height:28px !important;
        border-radius:8px !important;
        overflow:hidden !important;
        background:#091120 !important;
        border:1px solid rgba(148,163,184,.16) !important;
    }

    .pos-discount-pill{
        width:32px !important;
        height:28px !important;
        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;
        color:#fff !important;
        font-size:12px !important;
        font-weight:900 !important;
    }

    .pos-hidden-discount{
        width:64px !important;
        height:28px !important;
        padding:0 7px !important;
        background:transparent !important;
        border:0 !important;
        color:#e5e7eb !important;
        font-size:11px !important;
        text-align:right !important;
    }

    /* Payment buttons grand total se upar */
    .pos-payment-methods{
        order:20 !important;
        display:grid !important;
        grid-template-columns:repeat(4,minmax(0,1fr)) !important;
        gap:6px !important;
        padding:8px 12px 8px !important;
        margin:0 !important;
        background:rgba(15,23,42,.92) !important;
        border-left:1px solid rgba(148,163,184,.20) !important;
        border-right:1px solid rgba(148,163,184,.20) !important;
    }

    .pos-payment-methods .pay-btn,
    .pay-btn{
        height:34px !important;
        min-height:34px !important;
        padding:0 4px !important;
        border-radius:9px !important;
        background:#0b1324 !important;
        border:1px solid rgba(148,163,184,.16) !important;
        color:#fff !important;
        font-size:9px !important;
        font-weight:900 !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        gap:3px !important;
        box-shadow:none !important;
    }

    .pos-payment-methods .pay-btn.active,
    .pay-btn.active{
        background:linear-gradient(135deg,#a855f7,#7c3aed) !important;
        border-color:rgba(192,132,252,.75) !important;
        box-shadow:0 10px 24px rgba(124,58,237,.30) !important;
    }

    /* Grand Total ko invoice button ke bilkul upar prominent karo */
    .pos-grand-total-row,
    .summary-row.total{
        order:30 !important;
        min-height:42px !important;
        height:42px !important;
        margin:0 !important;
        padding:8px 12px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:space-between !important;
        border-top:1px dashed rgba(148,163,184,.22) !important;
        border-left:1px solid rgba(148,163,184,.20) !important;
        border-right:1px solid rgba(148,163,184,.20) !important;
        background:rgba(15,23,42,.96) !important;
        color:#fff !important;
    }

    .pos-grand-total-row span,
    .summary-row.total span:first-child{
        font-size:14px !important;
        font-weight:950 !important;
        color:#fff !important;
    }

    .pos-grand-total-row strong,
    .summary-row.total span:last-child{
        font-size:20px !important;
        font-weight:950 !important;
        color:#a855f7 !important;
        letter-spacing:.2px !important;
    }

    /* Invoice button sticky bottom */
    .pos-proceed-btn,
    .invoice-btn{
        position:fixed !important;
        left:12px !important;
        right:12px !important;
        bottom:12px !important;
        z-index:99999 !important;

        width:auto !important;
        height:58px !important;
        min-height:58px !important;
        margin:0 !important;
        padding:0 14px !important;

        border-radius:14px !important;
        background:linear-gradient(135deg,#a855f7 0%,#7c3aed 58%,#5b21b6 100%) !important;
        border:1px solid rgba(192,132,252,.78) !important;
        color:#fff !important;

        font-size:14px !important;
        font-weight:950 !important;
        letter-spacing:.7px !important;
        text-transform:uppercase !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        gap:10px !important;

        box-shadow:
            0 18px 40px rgba(124,58,237,.45),
            0 -12px 34px rgba(5,8,22,.72) !important;
    }

    .pos-proceed-btn::before,
    .invoice-btn::before{
        content:"" !important;
        position:fixed !important;
        left:0 !important;
        right:0 !important;
        bottom:0 !important;
        height:92px !important;
        z-index:-1 !important;
        background:linear-gradient(180deg, transparent, rgba(5,8,22,.96) 45%, #050816 100%) !important;
        pointer-events:none !important;
    }

    .pos-proceed-arrow{
        font-size:20px !important;
        line-height:1 !important;
    }
}

/* 360px / small mobile tuning */
@media only screen and (max-width: 390px){

    body{
        padding-bottom:86px !important;
    }

    .content-wrapper{
        padding-bottom:98px !important;
    }

    .pos-summary-row{
        min-height:21px !important;
        height:21px !important;
        font-size:11px !important;
    }

    .pos-summary-row strong{
        font-size:11px !important;
    }

    .pos-payment-methods{
        gap:5px !important;
        padding:7px 10px 7px !important;
    }

    .pos-payment-methods .pay-btn,
    .pay-btn{
        height:32px !important;
        min-height:32px !important;
        font-size:8.5px !important;
        border-radius:8px !important;
    }

    .pos-grand-total-row,
    .summary-row.total{
        min-height:40px !important;
        height:40px !important;
        padding:7px 10px !important;
    }

    .pos-grand-total-row span,
    .summary-row.total span:first-child{
        font-size:13px !important;
    }

    .pos-grand-total-row strong,
    .summary-row.total span:last-child{
        font-size:18px !important;
    }

    .pos-proceed-btn,
    .invoice-btn{
        left:10px !important;
        right:10px !important;
        bottom:10px !important;
        height:54px !important;
        min-height:54px !important;
        border-radius:13px !important;
        font-size:13px !important;
    }
}


/* =========================================================
   POS MOBILE FINAL BOTTOM BAR
   Grand Total left + Proceed button right
   Same like Generate sticky bottom image
   Paste at VERY END before 
========================================================= */

@media only screen and (max-width: 991.98px){

    html,
    body{
        overflow-x:hidden !important;
    }

    body{
        padding-bottom:86px !important;
    }

    .content-wrapper{
        padding-bottom:96px !important;
    }

    .cart-area,
    .pos-premium-cart{
        padding-bottom:0 !important;
        margin-bottom:12px !important;
    }

    /* Normal summary rows compact rakho */
    .pos-billing-summary{
        padding-bottom:8px !important;
    }

    .pos-summary-row{
        min-height:22px !important;
        height:22px !important;
        font-size:11px !important;
        margin:0 !important;
    }

    .pos-summary-row strong{
        font-size:11px !important;
    }

    /* Payment buttons sticky bar ke upar normal rahen */
    .pos-payment-methods{
        padding-bottom:10px !important;
        margin-bottom:0 !important;
    }

    /* =====================================================
       Grand Total ko bottom sticky bar ka LEFT part banao
    ===================================================== */
    .pos-grand-total-row,
    .summary-row.total{
        position:fixed !important;
        left:12px !important;
        right:12px !important;
        bottom:12px !important;
        z-index:99998 !important;

        height:58px !important;
        min-height:58px !important;
        margin:0 !important;
        padding:8px 142px 8px 14px !important;

        display:flex !important;
        flex-direction:column !important;
        align-items:flex-start !important;
        justify-content:center !important;
        gap:3px !important;

        border-radius:14px !important;
        border:1px solid rgba(148,163,184,.20) !important;
        background:
            radial-gradient(circle at 85% 20%, rgba(124,58,237,.22), transparent 34%),
            linear-gradient(180deg, rgba(15,23,42,.98), rgba(8,13,28,.98)) !important;

        box-shadow:
            0 -8px 34px rgba(5,8,22,.72),
            0 14px 35px rgba(0,0,0,.34),
            inset 0 1px 0 rgba(255,255,255,.05) !important;
    }

    .pos-grand-total-row::before,
    .summary-row.total::before{
        content:"" !important;
        position:fixed !important;
        left:0 !important;
        right:0 !important;
        bottom:0 !important;
        height:94px !important;
        z-index:-1 !important;
        background:linear-gradient(
            180deg,
            transparent 0%,
            rgba(5,8,22,.78) 36%,
            #050816 100%
        ) !important;
        pointer-events:none !important;
    }

    .pos-grand-total-row span,
    .summary-row.total span:first-child{
        display:block !important;
        width:auto !important;
        color:#dbe7f5 !important;
        font-size:10px !important;
        line-height:1 !important;
        font-weight:850 !important;
        text-transform:none !important;
    }

    .pos-grand-total-row strong,
    .summary-row.total span:last-child{
        display:block !important;
        width:auto !important;
        color:#a855f7 !important;
        font-size:17px !important;
        line-height:1.05 !important;
        font-weight:950 !important;
        letter-spacing:.2px !important;
        text-align:left !important;
    }

    /* =====================================================
       Proceed Invoice button ko same bar ka RIGHT part banao
    ===================================================== */
    .pos-proceed-btn,
    .invoice-btn{
        position:fixed !important;
        right:22px !important;
        left:auto !important;
        bottom:22px !important;
        z-index:99999 !important;

        width:126px !important;
        min-width:126px !important;
        max-width:126px !important;
        height:38px !important;
        min-height:38px !important;

        margin:0 !important;
        padding:0 12px !important;

        border-radius:12px !important;
        border:1px solid rgba(192,132,252,.78) !important;
        background:linear-gradient(135deg,#a855f7 0%,#7c3aed 60%,#5b21b6 100%) !important;
        color:#fff !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        gap:6px !important;

        font-size:10px !important;
        line-height:1 !important;
        font-weight:950 !important;
        letter-spacing:0 !important;
        text-transform:none !important;
        white-space:nowrap !important;

        box-shadow:0 12px 26px rgba(124,58,237,.42) !important;
    }

    .pos-proceed-btn::before,
    .invoice-btn::before{
        content:none !important;
        display:none !important;
    }

    .pos-proceed-btn::after,
    .invoice-btn::after{
        content:" →" !important;
        font-size:14px !important;
        line-height:1 !important;
        font-weight:900 !important;
        margin-left:2px !important;
    }

    .pos-proceed-arrow{
        display:none !important;
    }
}

/* 360px exact mobile */
@media only screen and (max-width: 390px){

    body{
        padding-bottom:82px !important;
    }

    .content-wrapper{
        padding-bottom:92px !important;
    }

    .pos-grand-total-row,
    .summary-row.total{
        left:10px !important;
        right:10px !important;
        bottom:10px !important;
        height:56px !important;
        min-height:56px !important;
        padding:8px 132px 8px 12px !important;
        border-radius:13px !important;
    }

    .pos-grand-total-row span,
    .summary-row.total span:first-child{
        font-size:9.5px !important;
    }

    .pos-grand-total-row strong,
    .summary-row.total span:last-child{
        font-size:16px !important;
    }

    .pos-proceed-btn,
    .invoice-btn{
        right:20px !important;
        bottom:19px !important;
        width:118px !important;
        min-width:118px !important;
        max-width:118px !important;
        height:38px !important;
        min-height:38px !important;
        border-radius:11px !important;
        font-size:9.5px !important;
        padding:0 9px !important;
    }
}


/* =========================================================
   FINAL POS MOBILE CHECKOUT BAR FIX
   Grand Total left + Proceed button right
   Same like reference Generate bar
   Paste at VERY END before 
========================================================= */

@media only screen and (max-width: 991.98px){

    body{
        padding-bottom:78px !important;
    }

    .content-wrapper{
        padding-bottom:88px !important;
    }

    /* Purane invoice full-width sticky ko reset */
    .invoice-btn,
    .pos-proceed-btn{
        position:fixed !important;
        left:auto !important;
        right:20px !important;
        bottom:19px !important;
        z-index:100001 !important;

        width:128px !important;
        min-width:128px !important;
        max-width:128px !important;
        height:38px !important;
        min-height:38px !important;

        margin:0 !important;
        padding:0 10px !important;

        border-radius:12px !important;
        background:linear-gradient(135deg,#a855f7,#7c3aed 60%,#5b21b6) !important;
        border:1px solid rgba(192,132,252,.75) !important;
        color:#fff !important;

        font-size:9.5px !important;
        font-weight:950 !important;
        line-height:1 !important;
        letter-spacing:0 !important;
        text-transform:uppercase !important;
        white-space:nowrap !important;

        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        gap:5px !important;

        box-shadow:0 10px 24px rgba(124,58,237,.42) !important;
    }

    .invoice-btn::before,
    .pos-proceed-btn::before{
        content:none !important;
        display:none !important;
    }

    .invoice-btn::after,
    .pos-proceed-btn::after{
        content:"→" !important;
        font-size:13px !important;
        font-weight:900 !important;
        margin-left:3px !important;
    }

    .pos-proceed-arrow{
        display:none !important;
    }

    /* Grand Total ko bottom checkout bar bana do */
    .summary-row.total,
    .pos-grand-total-row{
        position:fixed !important;
        left:10px !important;
        right:10px !important;
        bottom:10px !important;
        z-index:100000 !important;

        height:56px !important;
        min-height:56px !important;
        margin:0 !important;
        padding:8px 150px 8px 12px !important;

        display:flex !important;
        flex-direction:column !important;
        align-items:flex-start !important;
        justify-content:center !important;
        gap:3px !important;

        border-radius:14px !important;
        border:1px solid rgba(148,163,184,.20) !important;
        background:
            radial-gradient(circle at 86% 30%, rgba(124,58,237,.24), transparent 35%),
            linear-gradient(180deg,#101827 0%,#070b16 100%) !important;

        box-shadow:
            0 -10px 30px rgba(5,8,22,.75),
            0 12px 28px rgba(0,0,0,.35),
            inset 0 1px 0 rgba(255,255,255,.05) !important;
    }

    .summary-row.total::before,
    .pos-grand-total-row::before{
        content:"" !important;
        position:fixed !important;
        left:0 !important;
        right:0 !important;
        bottom:0 !important;
        height:82px !important;
        z-index:-1 !important;
        background:linear-gradient(
            180deg,
            transparent 0%,
            rgba(5,8,22,.75) 35%,
            #050816 100%
        ) !important;
        pointer-events:none !important;
    }

    .summary-row.total span:first-child,
    .pos-grand-total-row span{
        display:block !important;
        color:#dbe7f5 !important;
        font-size:9px !important;
        line-height:1 !important;
        font-weight:850 !important;
        text-align:left !important;
        margin:0 !important;
    }

    .summary-row.total span:last-child,
    .pos-grand-total-row strong{
        display:block !important;
        color:#a855f7 !important;
        font-size:17px !important;
        line-height:1 !important;
        font-weight:950 !important;
        text-align:left !important;
        margin:0 !important;
        letter-spacing:.2px !important;
    }

    /* Payment row ko sticky bar ke upar normal compact rakho */
    .payment-row,
    .pos-payment-methods{
        margin-bottom:12px !important;
        padding-bottom:0 !important;
    }
}

/* 360px exact tuning */
@media only screen and (max-width: 390px){

    .summary-row.total,
    .pos-grand-total-row{
        left:10px !important;
        right:10px !important;
        bottom:10px !important;
        height:54px !important;
        min-height:54px !important;
        padding:8px 140px 8px 12px !important;
        border-radius:13px !important;
    }

    .invoice-btn,
    .pos-proceed-btn{
        right:20px !important;
        bottom:18px !important;
        width:120px !important;
        min-width:120px !important;
        max-width:120px !important;
        height:36px !important;
        min-height:36px !important;
        font-size:8.8px !important;
        border-radius:11px !important;
    }

    .summary-row.total span:first-child,
    .pos-grand-total-row span{
        font-size:8.8px !important;
    }

    .summary-row.total span:last-child,
    .pos-grand-total-row strong{
        font-size:16px !important;
    }
}


/* =========================================================
   ITEM DISCOUNT UI
   Retail discount display only. Wholesale prices stay separate.
========================================================= */
.item-old-price{
    color:#94a3b8 !important;
    font-size:11px !important;
    font-weight:800 !important;
    text-decoration:line-through !important;
    margin-bottom:3px !important;
    line-height:1 !important;
}

.item-discount-badge{
    display:inline-flex !important;
    margin-top:5px !important;
    padding:3px 7px !important;
    border-radius:999px !important;
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
    font-size:10px !important;
    font-weight:900 !important;
    line-height:1 !important;
}


/* =========================================================
   Retailer / Walk-In Customer Info Popup + WhatsApp Link
========================================================= */
.pos-cart-phone-box{
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
}
.pos-cart-phone-box .pos-cart-phone-input{
    flex:1 1 auto !important;
    min-width:0 !important;
}
.pos-cart-phone-wa{
    height:40px !important;
    padding:0 12px !important;
    border-radius:8px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    text-decoration:none !important;
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.28) !important;
    font-size:12px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
}
.pos-cart-phone-wa:hover{
    background:#22c55e !important;
    color:#07111f !important;
}
.walkin-info-note{
    color:#9ca3af;
    font-size:12px;
    line-height:1.45;
    margin-top:7px;
}
.walkin-wa-preview{
    display:none;
    margin-top:8px;
    color:#4ade80 !important;
    text-decoration:none;
    font-weight:900;
    font-size:13px;
}
@media (max-width:767.98px){
    .pos-cart-phone-box{
        display:none !important;
    }
}








/* =========================================================
   FINAL CART VIEW - Reference invoice style, dark theme
   Only .pos-premium-cart UI affected
========================================================= */

@media (min-width:1201px){
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 590px !important;
    }
}

.pos-premium-cart{
    height:100% !important;
    min-height:0 !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    padding:12px !important;
    background:#080d18 !important;
    border:1px solid rgba(148,163,184,.14) !important;
    border-radius:18px !important;
}

/* header compact */
.pos-premium-cart .pos-cart-customer-header{
    min-height:46px !important;
    padding:0 0 8px !important;
    margin:0 0 8px !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

.pos-premium-cart .pos-cart-customer-icon{
    width:32px !important;
    height:32px !important;
    min-width:32px !important;
    border-radius:10px !important;
}

.pos-premium-cart .pos-cart-customer-info h4{
    font-size:13px !important;
    line-height:1.1 !important;
}

.pos-premium-cart .pos-cart-customer-info small{
    font-size:10px !important;
}

.pos-premium-cart .pos-cart-change-btn{
    height:30px !important;
    padding:0 11px !important;
    font-size:11px !important;
    border-radius:8px !important;
}

/* phone */
.pos-premium-cart .pos-cart-phone-box{
    margin-bottom:8px !important;
}

.pos-premium-cart .pos-cart-phone-input{
    height:36px !important;
    font-size:12px !important;
    border-radius:7px !important;
}

/* order title */
.pos-premium-cart .pos-order-items-top{
    height:30px !important;
    min-height:30px !important;
    margin-bottom:6px !important;
}

.pos-premium-cart .pos-order-items-title{
    font-size:12px !important;
    font-weight:950 !important;
    color:#e5edf8 !important;
}

.pos-premium-cart .pos-clear-cart-btn{
    height:28px !important;
    padding:0 9px !important;
    font-size:11px !important;
    border-radius:7px !important;
}

/* table columns: Item | Batch | Qty | Unit Price | Discount | Total | Remove */
.pos-premium-cart .pos-cart-table-head,
.pos-premium-cart .pos-cart-item.pos-ref-row,
.pos-premium-cart .pos-cart-item.ref-row-ready{
    width:100% !important;
    max-width:100% !important;
    display:grid !important;
    grid-template-columns:minmax(130px,1.4fr) 54px 86px 74px 86px 78px 20px !important;
    gap:5px !important;
    align-items:center !important;
    box-sizing:border-box !important;
}

.pos-premium-cart .pos-cart-table-head{
    height:32px !important;
    min-height:32px !important;
    padding:0 7px !important;
    background:#101827 !important;
    border:1px solid rgba(148,163,184,.10) !important;
    border-radius:7px 7px 0 0 !important;
    color:#dbeafe !important;
    font-size:9.4px !important;
    font-weight:950 !important;
    text-transform:none !important;
    letter-spacing:.15px !important;
}

.pos-premium-cart .pos-cart-table-head > div{
    min-width:0 !important;
    overflow:hidden !important;
    white-space:nowrap !important;
    text-overflow:ellipsis !important;
}

.pos-premium-cart .pos-cart-table-head > div:nth-child(1){
    text-align:left !important;
}

.pos-premium-cart .pos-cart-table-head > div:nth-child(n+2){
    text-align:center !important;
}

/* cart scroll */
.pos-premium-cart .pos-cart-items-list,
.pos-premium-cart #cart{
    flex:1 1 auto !important;
    min-height:120px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding-right:3px !important;
    margin:0 !important;
}

.pos-premium-cart #cart.has-cart-items .empty-state{
    display:none !important;
}

/* row */
.pos-premium-cart .pos-cart-item.pos-ref-row,
.pos-premium-cart .pos-cart-item.ref-row-ready{
    min-height:58px !important;
    padding:7px !important;
    margin:0 !important;
    border:0 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-radius:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}

/* item column */
.pos-premium-cart .pos-ref-product-cell,
.pos-premium-cart .pos-cart-left{
    min-width:0 !important;
    width:100% !important;
    overflow:hidden !important;
    display:block !important;
}

.pos-premium-cart .pos-ref-img,
.pos-premium-cart .ref-cart-img{
    display:none !important;
}

.pos-premium-cart .pos-ref-product-text{
    min-width:0 !important;
    width:100% !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-cart-head{
    min-width:0 !important;
    width:100% !important;
}

.pos-premium-cart .pos-cart-title{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
    color:#f8fafc !important;
    font-size:10.8px !important;
    line-height:1.18 !important;
    font-weight:950 !important;
    white-space:normal !important;
    display:-webkit-box !important;
    -webkit-line-clamp:2 !important;
    -webkit-box-orient:vertical !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-cart-sku{
    margin-top:3px !important;
    color:#9aa7bb !important;
    font-size:9.3px !important;
    line-height:1.05 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.pos-premium-cart .pos-cart-badge,
.pos-premium-cart .pos-cart-tier-badge{
    display:none !important;
}

/* batch */
.pos-premium-cart .pos-ref-batch-cell{
    min-width:0 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-ref-batch-value{
    max-width:100% !important;
    color:#cbd5e1 !important;
    font-size:9.4px !important;
    font-weight:800 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

/* qty */
.pos-premium-cart .pos-ref-qty-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-cart-controls{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:0 !important;
    flex-wrap:nowrap !important;
    transform:scale(.82) !important;
    transform-origin:center !important;
}

.pos-premium-cart .pos-cart-btn{
    width:27px !important;
    min-width:27px !important;
    height:27px !important;
    border-radius:0 !important;
    background:#0b1220 !important;
    border:1px solid rgba(148,163,184,.13) !important;
    color:#dbeafe !important;
    box-shadow:none !important;
    font-size:12px !important;
    font-weight:950 !important;
    padding:0 !important;
}

.pos-premium-cart .pos-cart-btn[data-cart-minus]{
    border-radius:6px 0 0 6px !important;
}

.pos-premium-cart .pos-cart-btn[data-cart-plus]{
    border-radius:0 6px 6px 0 !important;
}

.pos-premium-cart .pos-cart-btn:hover{
    background:#7c3aed !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.75) !important;
}

.pos-premium-cart .pos-cart-qty{
    width:34px !important;
    min-width:34px !important;
    height:27px !important;
    border-radius:0 !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.13) !important;
    border-left:0 !important;
    border-right:0 !important;
    color:#fff !important;
    text-align:center !important;
    font-size:11px !important;
    font-weight:950 !important;
    padding:0 !important;
}

/* price */
.pos-premium-cart .pos-ref-price-cell{
    min-width:0 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-ref-price-cell::before{
    content:"Rs" !important;
    margin-right:3px !important;
    color:#f8fafc !important;
    font-size:10px !important;
    font-weight:950 !important;
}

.pos-premium-cart .pos-cart-price,
.pos-premium-cart input[data-cart-price-input]{
    width:40px !important;
    min-width:40px !important;
    max-width:40px !important;
    height:24px !important;
    padding:0 !important;
    margin:0 !important;
    border:0 !important;
    outline:none !important;
    background:transparent !important;
    color:#f8fafc !important;
    text-align:left !important;
    font-size:10px !important;
    font-weight:950 !important;
    box-shadow:none !important;
    appearance:textfield !important;
    -moz-appearance:textfield !important;
}

.pos-premium-cart .pos-cart-price::-webkit-inner-spin-button,
.pos-premium-cart .pos-cart-price::-webkit-outer-spin-button,
.pos-premium-cart input[data-cart-price-input]::-webkit-inner-spin-button,
.pos-premium-cart input[data-cart-price-input]::-webkit-outer-spin-button{
    -webkit-appearance:none !important;
    appearance:none !important;
    display:none !important;
}

/* discount */
.pos-premium-cart .pos-ref-discount-cell{
    min-width:0 !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:center !important;
    gap:3px !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-line-discount-control{
    width:54px !important;
    height:24px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:5px !important;
    overflow:hidden !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.12) !important;
}

.pos-premium-cart .pos-line-discount-input{
    width:34px !important;
    height:22px !important;
    padding:0 !important;
    border:0 !important;
    outline:none !important;
    background:transparent !important;
    color:#fff !important;
    text-align:center !important;
    font-size:10px !important;
    font-weight:950 !important;
}

.pos-premium-cart .pos-line-discount-control span{
    color:#cbd5e1 !important;
    font-size:10px !important;
    font-weight:900 !important;
}

.pos-premium-cart .pos-line-discount-text{
    max-width:82px !important;
    color:#94a3b8 !important;
    font-size:8.4px !important;
    line-height:1 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

/* total */
.pos-premium-cart .pos-ref-total-cell{
    min-width:0 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-cart-total,
.pos-premium-cart [data-line-total]{
    width:78px !important;
    max-width:78px !important;
    margin:0 !important;
    color:#f8fafc !important;
    text-align:right !important;
    font-size:10px !important;
    font-weight:950 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

/* remove */
.pos-premium-cart .pos-ref-remove-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-ref-remove-cell .pos-cart-btn,
.pos-premium-cart [data-cart-remove]{
    width:19px !important;
    min-width:19px !important;
    height:19px !important;
    border-radius:50% !important;
    background:transparent !important;
    border:1px solid rgba(148,163,184,.24) !important;
    color:#94a3b8 !important;
    font-size:10px !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-ref-remove-cell .pos-cart-btn:hover,
.pos-premium-cart [data-cart-remove]:hover{
    background:rgba(239,68,68,.14) !important;
    border-color:rgba(239,68,68,.40) !important;
    color:#fb7185 !important;
}

.pos-premium-cart .pos-cart-right{
    display:contents !important;
}

/* add custom product reference style */
.pos-premium-cart .pos-add-custom-wrap{
    flex-shrink:0 !important;
    margin-top:7px !important;
}

.pos-premium-cart .pos-add-custom-main{
    height:38px !important;
    border-radius:7px !important;
    font-size:12px !important;
    background:#070d19 !important;
}

/* summary compact */
.pos-premium-cart .pos-billing-summary{
    flex-shrink:0 !important;
    margin-top:7px !important;
    padding-top:8px !important;
}

.pos-premium-cart .pos-summary-row{
    min-height:23px !important;
    font-size:12px !important;
}

.pos-premium-cart .pos-grand-total-row{
    min-height:38px !important;
    margin-top:4px !important;
    padding-top:8px !important;
}

.pos-premium-cart .pos-grand-total-row span{
    font-size:15px !important;
}

.pos-premium-cart .pos-grand-total-row strong{
    font-size:22px !important;
    color:#a855f7 !important;
}

/* payment and proceed */
.pos-premium-cart .pos-payment-methods{
    flex-shrink:0 !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:7px !important;
    margin:7px 0 8px !important;
}

.pos-premium-cart .pos-payment-methods .pay-btn{
    height:38px !important;
    min-height:38px !important;
    border-radius:7px !important;
    font-size:10px !important;
}

.pos-premium-cart .pos-proceed-btn{
    flex-shrink:0 !important;
    height:44px !important;
    min-height:44px !important;
    border-radius:7px !important;
    font-size:13px !important;
}

/* laptop compact */
@media (max-width:1450px){
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 560px !important;
    }

    .pos-premium-cart .pos-cart-table-head,
    .pos-premium-cart .pos-cart-item.pos-ref-row,
    .pos-premium-cart .pos-cart-item.ref-row-ready{
        grid-template-columns:minmax(115px,1.25fr) 46px 76px 64px 74px 68px 18px !important;
        gap:4px !important;
        padding-left:6px !important;
        padding-right:6px !important;
    }

    .pos-premium-cart .pos-cart-title{
        font-size:10px !important;
    }

    .pos-premium-cart .pos-cart-sku,
    .pos-premium-cart .pos-ref-batch-value{
        font-size:8.8px !important;
    }

    .pos-premium-cart .pos-cart-controls{
        transform:scale(.78) !important;
    }

    .pos-premium-cart .pos-line-discount-control{
        width:48px !important;
    }

    .pos-premium-cart .pos-line-discount-text{
        max-width:70px !important;
        font-size:7.8px !important;
    }

    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total]{
        width:68px !important;
        max-width:68px !important;
        font-size:9.4px !important;
    }
}

/* small height */
@media (max-height:760px){
    .pos-premium-cart{
        padding:8px 10px !important;
    }

    .pos-premium-cart .pos-cart-customer-header{
        min-height:38px !important;
        margin-bottom:5px !important;
        padding-bottom:5px !important;
    }

    .pos-premium-cart .pos-cart-phone-input{
        height:32px !important;
    }

    .pos-premium-cart .pos-cart-phone-box{
        margin-bottom:5px !important;
    }

    .pos-premium-cart .pos-order-items-top{
        height:26px !important;
        min-height:26px !important;
        margin-bottom:4px !important;
    }

    .pos-premium-cart .pos-cart-table-head{
        height:28px !important;
        min-height:28px !important;
    }

    .pos-premium-cart .pos-cart-item.pos-ref-row,
    .pos-premium-cart .pos-cart-item.ref-row-ready{
        min-height:48px !important;
        padding-top:5px !important;
        padding-bottom:5px !important;
    }

    .pos-premium-cart .pos-add-custom-main{
        height:32px !important;
    }

    .pos-premium-cart .pos-summary-row{
        min-height:19px !important;
        font-size:11px !important;
    }

    .pos-premium-cart .pos-payment-methods .pay-btn{
        height:32px !important;
        min-height:32px !important;
    }

    .pos-premium-cart .pos-proceed-btn{
        height:38px !important;
        min-height:38px !important;
    }
}

/* =========================================================
   POS CART BOTTOM - Reference Image Style
   Only bottom cart UI changed
========================================================= */

.pos-premium-cart .pos-ref-add-item-row{
    flex-shrink:0 !important;
    margin:8px 0 6px !important;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
}

.pos-premium-cart .pos-ref-add-item-btn{
    width:104px !important;
    height:32px !important;
    min-height:32px !important;
    border-radius:6px !important;
    border:1px solid rgba(124,58,237,.35) !important;
    background:rgba(124,58,237,.06) !important;
    color:#a78bfa !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;
    font-size:11px !important;
    font-weight:800 !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-ref-add-item-btn .pos-add-custom-icon{
    width:auto !important;
    height:auto !important;
    min-width:0 !important;
    border:0 !important;
    background:transparent !important;
    color:#7c3aed !important;
    box-shadow:none !important;
    font-size:13px !important;
}

.pos-premium-cart .pos-custom-product-form{
    margin-top:6px !important;
    grid-template-columns:minmax(0,1fr) 72px 52px 60px !important;
    border-radius:6px !important;
}

/* Summary like white reference, but dark theme */
.pos-premium-cart .pos-billing-summary{
    flex-shrink:0 !important;
    margin:0 !important;
    padding:8px 0 0 !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
    background:transparent !important;
}

.pos-premium-cart .pos-summary-row{
    min-height:23px !important;
    height:23px !important;
    margin:0 !important;
    padding:0 !important;
    display:grid !important;
    grid-template-columns:140px minmax(0,1fr) !important;
    align-items:center !important;
    gap:10px !important;
    color:#dbeafe !important;
    font-size:11.5px !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-summary-row span{
    color:#dbeafe !important;
    font-weight:700 !important;
}

.pos-premium-cart .pos-summary-row strong{
    color:#f8fafc !important;
    font-size:11.5px !important;
    font-weight:900 !important;
    text-align:right !important;
}

.pos-premium-cart .pos-green-value{
    color:#22c55e !important;
}

.pos-premium-cart .pos-discount-row{
    grid-template-columns:140px minmax(0,1fr) !important;
}

.pos-ref-discount-control{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) 72px !important;
    gap:8px !important;
    align-items:center !important;
}

.pos-discount-type-select{
    width:100% !important;
    height:28px !important;
    border-radius:5px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:#050a14 !important;
    color:#e5edf8 !important;
    padding:0 8px !important;
    font-size:10.5px !important;
    outline:none !important;
}

.pos-ref-discount-box{
    height:28px !important;
    display:flex !important;
    align-items:center !important;
    overflow:hidden !important;
    border-radius:5px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:#050a14 !important;
}

.pos-ref-discount-box #discount,
.pos-ref-discount-box .pos-hidden-discount{
    width:44px !important;
    height:28px !important;
    border:0 !important;
    background:transparent !important;
    color:#f8fafc !important;
    text-align:center !important;
    padding:0 !important;
    font-size:10.5px !important;
    box-shadow:none !important;
}

.pos-ref-percent-pill{
    width:28px !important;
    height:28px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-left:1px solid rgba(148,163,184,.14) !important;
    color:#cbd5e1 !important;
    font-size:10px !important;
    font-weight:900 !important;
}

/* Total payable */
.pos-premium-cart .pos-grand-total-row{
    min-height:31px !important;
    height:31px !important;
    margin-top:3px !important;
    padding-top:6px !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
}

.pos-premium-cart .pos-grand-total-row span{
    color:#a78bfa !important;
    font-size:12px !important;
    font-weight:950 !important;
}

.pos-premium-cart .pos-grand-total-row strong{
    color:#a855f7 !important;
    font-size:17px !important;
    font-weight:950 !important;
    text-align:right !important;
}

/* Customer pay / change return */
.pos-customer-pay-row{
    min-height:33px !important;
    height:33px !important;
    display:grid !important;
    grid-template-columns:140px minmax(0,1fr) !important;
    gap:10px !important;
    align-items:center !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
}

.pos-customer-pay-row label{
    margin:0 !important;
    color:#dbeafe !important;
    font-size:11.5px !important;
    font-weight:800 !important;
}

.pos-pay-amount-input{
    width:100% !important;
    max-width:136px !important;
    height:26px !important;
    justify-self:end !important;
    border-radius:5px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:#050a14 !important;
    color:#f8fafc !important;
    text-align:right !important;
    padding:0 8px !important;
    font-size:12px !important;
    font-weight:900 !important;
    outline:none !important;
}

.pos-change-return-input{
    color:#22c55e !important;
    background:rgba(34,197,94,.05) !important;
}

/* Payment title */
.pos-payment-title{
    flex-shrink:0 !important;
    margin:6px 0 5px !important;
    color:#dbeafe !important;
    font-size:11.5px !important;
    font-weight:900 !important;
}

/* Payment methods same reference */
.pos-premium-cart .pos-payment-methods{
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:repeat(6, minmax(0,1fr)) !important;
    gap:7px !important;
    margin:0 0 8px !important;
    padding:0 !important;
}

.pos-premium-cart .pos-method-btn{
    width:100% !important;
    height:48px !important;
    min-height:48px !important;
    padding:5px 3px !important;
    border-radius:6px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:#0a0f1d !important;
    color:#dbeafe !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:center !important;
    gap:3px !important;
    font-size:9.2px !important;
    line-height:1 !important;
    font-weight:850 !important;
    text-transform:none !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-method-btn.active,
.pos-premium-cart .pos-method-btn:hover{
    background:linear-gradient(135deg,#22c55e,#16a34a) !important;
    color:#fff !important;
    border-color:rgba(34,197,94,.65) !important;
    box-shadow:0 10px 22px rgba(34,197,94,.20) !important;
}

.pos-pay-logo{
    width:22px !important;
    height:22px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:15px !important;
}

.pos-pay-brand{
    width:26px !important;
    height:18px !important;
    border-radius:999px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:7.5px !important;
    font-weight:950 !important;
    color:#fff !important;
}

.pos-pay-brand-jazz{
    background:#ef4444 !important;
}

.pos-pay-brand-easy{
    background:#0f766e !important;
}

.pos-pay-text{
    max-width:100% !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

/* Bottom Save/Print/Complete row */
.pos-invoice-actions-row{
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:1fr 1fr 1.55fr !important;
    gap:7px !important;
    margin:0 !important;
}

.pos-invoice-action-btn,
.pos-premium-cart .pos-complete-sale-btn{
    height:42px !important;
    min-height:42px !important;
    border-radius:7px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:#0a0f1d !important;
    color:#dbeafe !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
    font-size:11px !important;
    font-weight:900 !important;
    box-shadow:none !important;
    padding:0 8px !important;
}

.pos-invoice-action-btn:hover{
    background:#111827 !important;
    color:#fff !important;
}

.pos-invoice-action-btn kbd,
.pos-complete-sale-btn kbd{
    min-width:24px !important;
    height:17px !important;
    padding:0 4px !important;
    border-radius:4px !important;
    border:1px solid rgba(148,163,184,.20) !important;
    background:#050a14 !important;
    color:#94a3b8 !important;
    font-size:8.5px !important;
    line-height:15px !important;
    font-weight:900 !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-complete-sale-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    border-color:rgba(139,92,246,.75) !important;
    color:#fff !important;
    text-transform:none !important;
    letter-spacing:0 !important;
}

.pos-premium-cart .pos-complete-sale-btn:hover{
    box-shadow:0 12px 28px rgba(124,58,237,.30) !important;
}

/* Compact laptop */
@media (max-width:1450px){
    .pos-premium-cart .pos-summary-row,
    .pos-customer-pay-row{
        grid-template-columns:118px minmax(0,1fr) !important;
    }

    .pos-ref-discount-control{
        grid-template-columns:minmax(0,1fr) 64px !important;
        gap:6px !important;
    }

    .pos-premium-cart .pos-method-btn{
        height:44px !important;
        min-height:44px !important;
        font-size:8.4px !important;
    }

    .pos-invoice-action-btn,
    .pos-premium-cart .pos-complete-sale-btn{
        height:38px !important;
        min-height:38px !important;
        font-size:10px !important;
    }
}

/* =========================================================
   FINAL POS CART CLEAN FIX
   Red marked blocks removed + bottom buttons visible
   Backend / JS logic unchanged
========================================================= */

/* right cart ko full height flex banao */
.pos-layout{
    grid-template-columns:minmax(0,1fr) 560px !important;
}

.cart-area.pos-premium-cart,
.pos-premium-cart{
    height:100% !important;
    max-height:100% !important;
    min-height:0 !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
    padding:10px 12px !important;
    background:#080d18 !important;
    border-radius:14px !important;
}

/* RED MARKED TOP AREA REMOVE */
.pos-premium-cart .pos-cart-customer-header,
.pos-premium-cart .pos-cart-phone-box{
    display:none !important;
}

/* Clear All bhi top red area mein aa raha tha */
.pos-premium-cart .pos-clear-cart-btn{
    display:none !important;
}

/* Order title compact */
.pos-premium-cart .pos-order-items-top{
    height:24px !important;
    min-height:24px !important;
    margin:0 0 5px !important;
    padding:0 !important;
    display:flex !important;
    align-items:center !important;
}

.pos-premium-cart .pos-order-items-title{
    font-size:12px !important;
    line-height:1 !important;
    font-weight:950 !important;
    color:#f8fafc !important;
}

/* Table header alignment: Item | Batch | Qty | Unit Price | Discount | Total | Remove */
.pos-premium-cart .pos-cart-table-head,
.pos-premium-cart .pos-cart-item.pos-ref-row,
.pos-premium-cart .pos-cart-item.ref-row-ready{
    display:grid !important;
    grid-template-columns:minmax(0,1.35fr) 52px 78px 68px 70px 70px 18px !important;
    gap:5px !important;
    align-items:center !important;
    width:100% !important;
    box-sizing:border-box !important;
}

.pos-premium-cart .pos-cart-table-head{
    height:28px !important;
    min-height:28px !important;
    padding:0 7px !important;
    margin:0 !important;
    background:#101827 !important;
    border:1px solid rgba(148,163,184,.10) !important;
    border-radius:6px 6px 0 0 !important;
    color:#dbeafe !important;
    font-size:9px !important;
    font-weight:950 !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-cart-table-head > div{
    min-width:0 !important;
    overflow:hidden !important;
    white-space:nowrap !important;
    text-overflow:ellipsis !important;
}

.pos-premium-cart .pos-cart-table-head > div:not(:first-child){
    text-align:center !important;
}

/* Cart list ko scroll area banao taa ke neeche buttons hide na hon */
.pos-premium-cart .pos-cart-items-list,
.pos-premium-cart #cart{
    flex:1 1 auto !important;
    min-height:72px !important;
    max-height:none !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    margin:0 !important;
    padding:0 3px 0 0 !important;
}

.pos-premium-cart .empty-state,
.pos-premium-cart .pos-cart-empty{
    min-height:78px !important;
    height:78px !important;
    padding:0 !important;
    margin:0 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:0 0 6px 6px !important;
    font-size:12px !important;
}

/* cart item compact rows */
.pos-premium-cart .pos-cart-item.pos-ref-row,
.pos-premium-cart .pos-cart-item.ref-row-ready{
    min-height:48px !important;
    padding:5px 7px !important;
    margin:0 !important;
    border:0 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-radius:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-ref-product-cell{
    min-width:0 !important;
    display:flex !important;
    flex-direction:column !important;
    justify-content:center !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-cart-title{
    font-size:10.5px !important;
    line-height:1.15 !important;
    font-weight:900 !important;
    color:#fff !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.pos-premium-cart .pos-cart-sku,
.pos-premium-cart .pos-ref-batch-value{
    font-size:9px !important;
    line-height:1 !important;
    color:#94a3b8 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

/* Qty */
.pos-premium-cart .pos-cart-controls{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:0 !important;
    transform:scale(.74) !important;
    transform-origin:center !important;
}

.pos-premium-cart .pos-cart-btn{
    width:25px !important;
    min-width:25px !important;
    height:25px !important;
    padding:0 !important;
    border-radius:0 !important;
    background:#0b1220 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    color:#fff !important;
    box-shadow:none !important;
    font-size:12px !important;
}

.pos-premium-cart .pos-cart-btn[data-cart-minus]{
    border-radius:5px 0 0 5px !important;
}

.pos-premium-cart .pos-cart-btn[data-cart-plus]{
    border-radius:0 5px 5px 0 !important;
}

.pos-premium-cart .pos-cart-qty{
    width:31px !important;
    min-width:31px !important;
    height:25px !important;
    padding:0 !important;
    border-radius:0 !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    border-left:0 !important;
    border-right:0 !important;
    color:#fff !important;
    text-align:center !important;
    font-size:11px !important;
    font-weight:900 !important;
}

/* price / discount / total */
.pos-premium-cart .pos-ref-price-cell,
.pos-premium-cart .pos-ref-discount-cell,
.pos-premium-cart .pos-ref-total-cell,
.pos-premium-cart .pos-ref-batch-cell,
.pos-premium-cart .pos-ref-qty-cell,
.pos-premium-cart .pos-ref-remove-cell{
    min-width:0 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-ref-price-cell::before{
    content:"Rs" !important;
    margin-right:2px !important;
    font-size:9.5px !important;
    font-weight:900 !important;
    color:#fff !important;
}

.pos-premium-cart .pos-cart-price,
.pos-premium-cart input[data-cart-price-input]{
    width:38px !important;
    min-width:38px !important;
    max-width:38px !important;
    height:22px !important;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
    color:#fff !important;
    font-size:10px !important;
    font-weight:900 !important;
    text-align:left !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-line-discount-control{
    width:46px !important;
    height:22px !important;
    border-radius:5px !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.12) !important;
}

.pos-premium-cart .pos-line-discount-input{
    width:28px !important;
    height:20px !important;
    font-size:9.5px !important;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
    color:#fff !important;
    text-align:center !important;
}

.pos-premium-cart .pos-line-discount-text{
    display:none !important;
}

.pos-premium-cart .pos-cart-total,
.pos-premium-cart [data-line-total]{
    width:70px !important;
    max-width:70px !important;
    margin:0 !important;
    text-align:right !important;
    font-size:10px !important;
    font-weight:900 !important;
    color:#fff !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.pos-premium-cart [data-cart-remove]{
    width:18px !important;
    min-width:18px !important;
    height:18px !important;
    border-radius:50% !important;
    background:transparent !important;
    border:1px solid rgba(148,163,184,.22) !important;
    color:#94a3b8 !important;
    font-size:9px !important;
}

/* Add item compact */
.pos-premium-cart .pos-add-custom-wrap{
    flex-shrink:0 !important;
    margin:6px 0 5px !important;
    padding:0 !important;
}

.pos-premium-cart .pos-add-custom-main{
    width:104px !important;
    height:30px !important;
    min-height:30px !important;
    border-radius:6px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;
    background:rgba(124,58,237,.06) !important;
    border:1px solid rgba(124,58,237,.35) !important;
    color:#a78bfa !important;
    font-size:11px !important;
    font-weight:900 !important;
}

/* Summary compact + aligned */
.pos-premium-cart .pos-billing-summary{
    flex-shrink:0 !important;
    margin:0 !important;
    padding:7px 0 0 !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
}

.pos-premium-cart .pos-summary-row{
    min-height:22px !important;
    height:22px !important;
    margin:0 !important;
    padding:0 !important;
    display:grid !important;
    grid-template-columns:130px minmax(0,1fr) !important;
    align-items:center !important;
    gap:8px !important;
    font-size:11px !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-summary-row span{
    color:#dbeafe !important;
    font-size:11px !important;
    font-weight:800 !important;
}

.pos-premium-cart .pos-summary-row strong{
    color:#fff !important;
    font-size:11px !important;
    font-weight:900 !important;
    text-align:right !important;
}

/* customer pay/change return red marked area remove */
.pos-premium-cart .pos-customer-pay-row{
    display:none !important;
}

/* discount row align */
.pos-premium-cart .pos-ref-discount-control{
    width:100% !important;
    display:grid !important;
    grid-template-columns:minmax(0,1fr) 64px !important;
    gap:6px !important;
    align-items:center !important;
}

.pos-premium-cart .pos-discount-type-select{
    height:26px !important;
    border-radius:5px !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.16) !important;
    color:#fff !important;
    font-size:10px !important;
    padding:0 6px !important;
}

.pos-premium-cart .pos-ref-discount-box{
    height:26px !important;
    display:flex !important;
    align-items:center !important;
    border-radius:5px !important;
    overflow:hidden !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.16) !important;
}

.pos-premium-cart #discount{
    width:38px !important;
    height:26px !important;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
    color:#fff !important;
    text-align:center !important;
    font-size:10px !important;
}

.pos-premium-cart .pos-ref-percent-pill{
    width:26px !important;
    height:26px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-left:1px solid rgba(148,163,184,.14) !important;
    color:#cbd5e1 !important;
    font-size:10px !important;
}

/* Grand total */
.pos-premium-cart .pos-grand-total-row{
    height:30px !important;
    min-height:30px !important;
    margin-top:4px !important;
    padding-top:6px !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
}

.pos-premium-cart .pos-grand-total-row span{
    color:#a78bfa !important;
    font-size:12px !important;
    font-weight:950 !important;
}

.pos-premium-cart .pos-grand-total-row strong{
    color:#a855f7 !important;
    font-size:18px !important;
    font-weight:950 !important;
}

/* Payment title */
.pos-premium-cart .pos-payment-title{
    flex-shrink:0 !important;
    margin:4px 0 4px !important;
    color:#dbeafe !important;
    font-size:11px !important;
    font-weight:900 !important;
}

/* Payment methods visible */
.pos-premium-cart .pos-payment-methods{
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:repeat(6,minmax(0,1fr)) !important;
    gap:5px !important;
    margin:0 0 6px !important;
    padding:0 !important;
    background:transparent !important;
    border:0 !important;
}

.pos-premium-cart .pos-payment-methods .pay-btn,
.pos-premium-cart .pos-method-btn{
    width:100% !important;
    height:40px !important;
    min-height:40px !important;
    padding:3px 2px !important;
    border-radius:6px !important;
    background:#0a0f1d !important;
    border:1px solid rgba(148,163,184,.16) !important;
    color:#dbeafe !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:center !important;
    gap:2px !important;
    font-size:8.2px !important;
    line-height:1 !important;
    font-weight:900 !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-payment-methods .pay-btn.active,
.pos-premium-cart .pos-method-btn.active,
.pos-premium-cart .pos-payment-methods .pay-btn:hover{
    background:linear-gradient(135deg,#22c55e,#16a34a) !important;
    color:#fff !important;
    border-color:rgba(34,197,94,.65) !important;
}

/* Bottom action buttons visible */
.pos-premium-cart .pos-invoice-actions-row{
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:1fr 1fr 1.45fr !important;
    gap:6px !important;
    margin:0 !important;
    padding:0 !important;
}

.pos-premium-cart .pos-invoice-action-btn,
.pos-premium-cart .pos-complete-sale-btn,
.pos-premium-cart .pos-proceed-btn,
.pos-premium-cart .invoice-btn{
    width:100% !important;
    height:38px !important;
    min-height:38px !important;
    margin:0 !important;
    padding:0 7px !important;
    border-radius:7px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:5px !important;
    font-size:10px !important;
    font-weight:950 !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-invoice-action-btn{
    background:#0a0f1d !important;
    border:1px solid rgba(148,163,184,.16) !important;
    color:#dbeafe !important;
}

.pos-premium-cart .pos-complete-sale-btn,
.pos-premium-cart .pos-proceed-btn,
.pos-premium-cart .invoice-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    border:1px solid rgba(139,92,246,.75) !important;
    color:#fff !important;
    text-transform:none !important;
    letter-spacing:0 !important;
}

.pos-premium-cart kbd{
    min-width:22px !important;
    height:15px !important;
    padding:0 3px !important;
    border-radius:4px !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#94a3b8 !important;
    font-size:8px !important;
    line-height:13px !important;
    box-shadow:none !important;
}

/* 1366 / small height fix */
@media (max-width:1450px), (max-height:760px){
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 520px !important;
    }

    .pos-premium-cart{
        padding:8px 10px !important;
    }

    .pos-premium-cart .pos-cart-table-head,
    .pos-premium-cart .pos-cart-item.pos-ref-row,
    .pos-premium-cart .pos-cart-item.ref-row-ready{
        grid-template-columns:minmax(0,1.25fr) 46px 70px 60px 60px 64px 18px !important;
        gap:4px !important;
    }

    .pos-premium-cart .pos-cart-table-head{
        height:26px !important;
        min-height:26px !important;
        font-size:8.4px !important;
    }

    .pos-premium-cart .pos-cart-items-list,
    .pos-premium-cart #cart{
        min-height:60px !important;
    }

    .pos-premium-cart .empty-state,
    .pos-premium-cart .pos-cart-empty{
        height:62px !important;
        min-height:62px !important;
    }

    .pos-premium-cart .pos-summary-row{
        height:20px !important;
        min-height:20px !important;
        font-size:10.5px !important;
    }

    .pos-premium-cart .pos-grand-total-row{
        height:28px !important;
        min-height:28px !important;
    }

    .pos-premium-cart .pos-payment-methods .pay-btn,
    .pos-premium-cart .pos-method-btn{
        height:36px !important;
        min-height:36px !important;
        font-size:7.8px !important;
    }

    .pos-premium-cart .pos-invoice-action-btn,
    .pos-premium-cart .pos-complete-sale-btn,
    .pos-premium-cart .pos-proceed-btn,
    .pos-premium-cart .invoice-btn{
        height:35px !important;
        min-height:35px !important;
        font-size:9.3px !important;
    }
}



/* =========================================================
   FINAL DISCOUNT SUMMARY CLEAN FIX
   Discount on Items removed + Percentage dropdown removed
========================================================= */

.pos-premium-cart .pos-billing-summary{
    margin-top:6px !important;
    padding-top:7px !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
}

/* Agar old HTML row reh bhi jaye to hide ho jaye */
.pos-premium-cart .pos-summary-row:has(#sumDiscountAmount){
    display:none !important;
}

/* Old percentage select hide */
.pos-premium-cart .pos-discount-type-select{
    display:none !important;
}

/* Summary rows clean alignment */
.pos-premium-cart .pos-summary-row{
    min-height:23px !important;
    height:23px !important;
    margin:0 !important;
    padding:0 !important;
    display:grid !important;
    grid-template-columns:135px minmax(0,1fr) !important;
    gap:8px !important;
    align-items:center !important;
    font-size:11.5px !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-summary-row span{
    color:#dbeafe !important;
    font-size:11.5px !important;
    font-weight:850 !important;
}

.pos-premium-cart .pos-summary-row strong{
    color:#fff !important;
    font-size:11.5px !important;
    font-weight:950 !important;
    text-align:right !important;
}

/* Discount row: label left, input right */
.pos-premium-cart .pos-discount-row{
    grid-template-columns:135px minmax(0,1fr) !important;
}

.pos-premium-cart .pos-ref-discount-simple,
.pos-premium-cart .pos-ref-discount-control{
    width:100% !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:0 !important;
}

.pos-premium-cart .pos-ref-discount-box{
    width:76px !important;
    height:26px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    overflow:hidden !important;
    border-radius:5px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:#050a14 !important;
}

.pos-premium-cart #discount,
.pos-premium-cart .pos-hidden-discount{
    width:48px !important;
    height:26px !important;
    padding:0 6px !important;
    border:0 !important;
    outline:none !important;
    background:transparent !important;
    color:#fff !important;
    text-align:right !important;
    font-size:10.5px !important;
    font-weight:900 !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-ref-percent-pill{
    width:28px !important;
    height:26px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-left:1px solid rgba(148,163,184,.14) !important;
    color:#cbd5e1 !important;
    font-size:10px !important;
    font-weight:950 !important;
}

/* Tax ke baad total payable clean */
.pos-premium-cart .pos-grand-total-row{
    height:32px !important;
    min-height:32px !important;
    margin-top:4px !important;
    padding-top:7px !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
}

.pos-premium-cart .pos-grand-total-row span{
    color:#a78bfa !important;
    font-size:12px !important;
    font-weight:950 !important;
}

.pos-premium-cart .pos-grand-total-row strong{
    color:#a855f7 !important;
    font-size:19px !important;
    font-weight:950 !important;
}

/* Small laptop compact */
@media (max-width:1450px), (max-height:760px){
    .pos-premium-cart .pos-summary-row{
        grid-template-columns:120px minmax(0,1fr) !important;
        height:21px !important;
        min-height:21px !important;
        font-size:10.8px !important;
    }

    .pos-premium-cart .pos-discount-row{
        grid-template-columns:120px minmax(0,1fr) !important;
    }

    .pos-premium-cart .pos-ref-discount-box{
        width:70px !important;
        height:24px !important;
    }

    .pos-premium-cart #discount,
    .pos-premium-cart .pos-hidden-discount{
        width:44px !important;
        height:24px !important;
        font-size:10px !important;
    }

    .pos-premium-cart .pos-ref-percent-pill{
        width:26px !important;
        height:24px !important;
    }
}


/* =========================================================
   FINAL PAYMENT BUTTON ALIGNMENT FIX
   Payment Method + Save/Print/Complete row
========================================================= */

/* Payment title */
.pos-premium-cart .pos-payment-title{
    flex-shrink:0 !important;
    margin:5px 0 6px !important;
    padding:0 !important;
    color:#dbeafe !important;
    font-size:11.5px !important;
    font-weight:950 !important;
    line-height:1 !important;
}

/* 6 payment buttons equal alignment */
.pos-premium-cart .pos-payment-methods,
.pos-premium-cart .payment-row.pos-payment-methods,
.pos-premium-cart .pos-method-grid{
    width:100% !important;
    max-width:100% !important;
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:repeat(6, minmax(0, 1fr)) !important;
    gap:7px !important;
    margin:0 0 7px !important;
    padding:0 !important;
    background:transparent !important;
    border:0 !important;
    box-sizing:border-box !important;
}

/* Payment button UI */
.pos-premium-cart .pos-payment-methods .pay-btn,
.pos-premium-cart .pos-method-btn{
    width:100% !important;
    min-width:0 !important;
    max-width:100% !important;
    height:48px !important;
    min-height:48px !important;
    padding:4px 2px !important;
    margin:0 !important;
    border-radius:7px !important;
    background:#0a0f1d !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#dbeafe !important;
    box-shadow:none !important;

    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:center !important;
    gap:3px !important;

    font-size:9.5px !important;
    font-weight:950 !important;
    line-height:1 !important;
    text-align:center !important;
    text-transform:none !important;
    letter-spacing:0 !important;
    overflow:hidden !important;
}

/* Active cash green */
.pos-premium-cart .pos-payment-methods .pay-btn.active,
.pos-premium-cart .pos-payment-methods .pay-btn:hover,
.pos-premium-cart .pos-method-btn.active,
.pos-premium-cart .pos-method-btn:hover{
    background:linear-gradient(135deg,#22c55e,#16a34a) !important;
    color:#fff !important;
    border-color:rgba(34,197,94,.72) !important;
    box-shadow:0 8px 18px rgba(34,197,94,.18) !important;
}

/* Icons/text alignment */
.pos-premium-cart .pos-pay-logo,
.pos-premium-cart .pos-pay-brand{
    height:17px !important;
    min-height:17px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    margin:0 !important;
    padding:0 !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-pay-logo{
    font-size:14px !important;
}

.pos-premium-cart .pos-pay-brand{
    min-width:25px !important;
    padding:0 5px !important;
    border-radius:999px !important;
    font-size:7px !important;
    font-weight:950 !important;
    color:#fff !important;
}

.pos-premium-cart .pos-pay-brand-jazz{
    background:#ef4444 !important;
}

.pos-premium-cart .pos-pay-brand-easy{
    background:#0f766e !important;
}

.pos-premium-cart .pos-pay-text{
    width:100% !important;
    max-width:100% !important;
    display:block !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    text-align:center !important;
    font-size:9px !important;
    line-height:1 !important;
}

/* Bottom actions row */
.pos-premium-cart .pos-invoice-actions-row{
    width:100% !important;
    max-width:100% !important;
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:1fr 1fr 1.55fr !important;
    gap:7px !important;
    margin:0 !important;
    padding:0 !important;
    box-sizing:border-box !important;
}

/* Save / Print / Complete buttons */
.pos-premium-cart .pos-invoice-action-btn,
.pos-premium-cart .pos-complete-sale-btn,
.pos-premium-cart .pos-proceed-btn,
.pos-premium-cart .invoice-btn{
    width:100% !important;
    max-width:100% !important;
    height:38px !important;
    min-height:38px !important;
    margin:0 !important;
    padding:0 8px !important;
    border-radius:7px !important;
    box-sizing:border-box !important;
    box-shadow:none !important;

    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;

    font-size:10px !important;
    font-weight:950 !important;
    line-height:1 !important;
    text-align:center !important;
    text-transform:none !important;
    letter-spacing:0 !important;
    overflow:hidden !important;
}

/* Save / Print dark */
.pos-premium-cart .pos-invoice-action-btn{
    background:#0a0f1d !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#dbeafe !important;
}

.pos-premium-cart .pos-invoice-action-btn:hover{
    background:#111827 !important;
    color:#fff !important;
}

/* Complete Sale purple */
.pos-premium-cart .pos-complete-sale-btn,
.pos-premium-cart .pos-proceed-btn,
.pos-premium-cart .invoice-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    border:1px solid rgba(139,92,246,.78) !important;
    color:#fff !important;
}

.pos-premium-cart .pos-complete-sale-btn:hover,
.pos-premium-cart .pos-proceed-btn:hover,
.pos-premium-cart .invoice-btn:hover{
    transform:none !important;
    box-shadow:0 10px 22px rgba(124,58,237,.24) !important;
}

/* F6 / F5 / F12 badge */
.pos-premium-cart .pos-invoice-action-btn kbd,
.pos-premium-cart .pos-complete-sale-btn kbd,
.pos-premium-cart .pos-proceed-btn kbd,
.pos-premium-cart .invoice-btn kbd{
    min-width:22px !important;
    height:15px !important;
    padding:0 4px !important;
    border-radius:4px !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.20) !important;
    color:#94a3b8 !important;
    font-size:8px !important;
    font-weight:900 !important;
    line-height:13px !important;
    box-shadow:none !important;
}

/* Text overflow fix */
.pos-premium-cart .pos-invoice-action-btn span,
.pos-premium-cart .pos-complete-sale-btn span{
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

/* 1366 / small cart width */
@media (max-width:1450px){
    .pos-premium-cart .pos-payment-methods,
    .pos-premium-cart .payment-row.pos-payment-methods,
    .pos-premium-cart .pos-method-grid{
        gap:5px !important;
        margin-bottom:6px !important;
    }

    .pos-premium-cart .pos-payment-methods .pay-btn,
    .pos-premium-cart .pos-method-btn{
        height:43px !important;
        min-height:43px !important;
        border-radius:7px !important;
        font-size:8.5px !important;
        padding:3px 1px !important;
    }

    .pos-premium-cart .pos-pay-logo{
        font-size:13px !important;
    }

    .pos-premium-cart .pos-pay-text{
        font-size:8.2px !important;
    }

    .pos-premium-cart .pos-invoice-actions-row{
        gap:5px !important;
        grid-template-columns:1fr 1fr 1.45fr !important;
    }

    .pos-premium-cart .pos-invoice-action-btn,
    .pos-premium-cart .pos-complete-sale-btn,
    .pos-premium-cart .pos-proceed-btn,
    .pos-premium-cart .invoice-btn{
        height:35px !important;
        min-height:35px !important;
        font-size:9px !important;
        padding:0 5px !important;
        gap:5px !important;
    }
}

/* Small height fix */
@media (max-height:760px){
    .pos-premium-cart .pos-payment-title{
        margin:3px 0 4px !important;
    }

    .pos-premium-cart .pos-payment-methods .pay-btn,
    .pos-premium-cart .pos-method-btn{
        height:40px !important;
        min-height:40px !important;
    }

    .pos-premium-cart .pos-invoice-action-btn,
    .pos-premium-cart .pos-complete-sale-btn,
    .pos-premium-cart .pos-proceed-btn,
    .pos-premium-cart .invoice-btn{
        height:34px !important;
        min-height:34px !important;
    }
}


/* =========================================================
   CART INVOICE HEADER - Invoice # + Date Time
   Reference style, dark theme
========================================================= */

.pos-premium-cart .pos-cart-invoice-header{
    width:100% !important;
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:minmax(0,1fr) auto !important;
    align-items:start !important;
    gap:12px !important;
    padding:0 0 9px !important;
    margin:0 0 8px !important;
    border-bottom:1px solid rgba(148,163,184,.12) !important;
    box-sizing:border-box !important;
}

.pos-premium-cart .pos-cart-invoice-left,
.pos-premium-cart .pos-cart-invoice-right{
    min-width:0 !important;
    display:flex !important;
    flex-direction:column !important;
    gap:4px !important;
}

.pos-premium-cart .pos-cart-invoice-right{
    align-items:flex-end !important;
    text-align:right !important;
}

.pos-premium-cart .pos-cart-invoice-left span,
.pos-premium-cart .pos-cart-invoice-right span{
    color:#cbd5e1 !important;
    font-size:10px !important;
    font-weight:850 !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-cart-invoice-left strong{
    color:#8b5cf6 !important;
    font-size:13px !important;
    font-weight:950 !important;
    line-height:1 !important;
    letter-spacing:.2px !important;
}

.pos-premium-cart .pos-cart-invoice-right strong{
    color:#f8fafc !important;
    font-size:10.5px !important;
    font-weight:850 !important;
    line-height:1 !important;
    white-space:nowrap !important;
}

/* Order items ko invoice header ke neeche compact rakho */
.pos-premium-cart .pos-order-items-top-with-invoice,
.pos-premium-cart .pos-order-items-top{
    height:22px !important;
    min-height:22px !important;
    margin:0 0 5px !important;
    padding:0 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

.pos-premium-cart .pos-order-items-title{
    font-size:12px !important;
    font-weight:950 !important;
    line-height:1 !important;
    color:#f8fafc !important;
}

/* Agar previous CSS ne clear all hide kar rakha hai to hidden hi rahega */
.pos-premium-cart .pos-clear-cart-btn{
    height:24px !important;
    min-height:24px !important;
    padding:0 8px !important;
    font-size:10px !important;
    border-radius:6px !important;
}

/* Small laptop / height compact */
@media (max-width:1450px), (max-height:760px){
    .pos-premium-cart .pos-cart-invoice-header{
        padding-bottom:7px !important;
        margin-bottom:6px !important;
    }

    .pos-premium-cart .pos-cart-invoice-left span,
    .pos-premium-cart .pos-cart-invoice-right span{
        font-size:9px !important;
    }

    .pos-premium-cart .pos-cart-invoice-left strong{
        font-size:12px !important;
    }

    .pos-premium-cart .pos-cart-invoice-right strong{
        font-size:9.5px !important;
    }

    .pos-premium-cart .pos-order-items-top-with-invoice,
    .pos-premium-cart .pos-order-items-top{
        height:20px !important;
        min-height:20px !important;
        margin-bottom:4px !important;
    }
}

/* =========================================================
   FINAL ADD ITEM + SUMMARY INLINE ALIGNMENT
   Left Add Item, Right Billing Summary - Reference Style
========================================================= */

.pos-premium-cart .pos-ref-bottom-aligned{
    width:100% !important;
    flex-shrink:0 !important;
    display:grid !important;
    grid-template-columns:118px minmax(0,1fr) !important;
    column-gap:14px !important;
    row-gap:7px !important;
    align-items:start !important;
    margin:7px 0 0 !important;
    padding:8px 0 0 !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
    box-sizing:border-box !important;
}

/* Left add item button */
.pos-premium-cart .pos-ref-add-inline{
    grid-column:1 !important;
    grid-row:1 !important;
    margin:0 !important;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
    align-self:start !important;
}

.pos-premium-cart .pos-ref-add-inline-btn,
.pos-premium-cart .pos-add-custom-main.pos-ref-add-inline-btn{
    width:104px !important;
    height:30px !important;
    min-height:30px !important;
    padding:0 10px !important;
    border-radius:6px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;
    background:rgba(124,58,237,.06) !important;
    border:1px solid rgba(124,58,237,.38) !important;
    color:#a78bfa !important;
    font-size:11px !important;
    font-weight:900 !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-ref-add-inline-btn .pos-add-custom-icon{
    width:auto !important;
    height:auto !important;
    min-width:0 !important;
    border:0 !important;
    background:transparent !important;
    color:#a78bfa !important;
    font-size:13px !important;
    box-shadow:none !important;
}

/* Right summary */
.pos-premium-cart .pos-ref-summary-inline,
.pos-premium-cart .pos-billing-summary.pos-ref-summary-inline{
    grid-column:2 !important;
    grid-row:1 !important;
    width:100% !important;
    margin:0 !important;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
}

/* Summary rows clean right-side alignment */
.pos-premium-cart .pos-ref-summary-inline .pos-summary-row{
    width:100% !important;
    min-height:22px !important;
    height:22px !important;
    margin:0 !important;
    padding:0 !important;
    display:grid !important;
    grid-template-columns:minmax(115px,1fr) 88px !important;
    gap:8px !important;
    align-items:center !important;
    color:#dbeafe !important;
    font-size:11.5px !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-ref-summary-inline .pos-summary-row span{
    color:#dbeafe !important;
    font-size:11.5px !important;
    font-weight:850 !important;
    white-space:nowrap !important;
}

.pos-premium-cart .pos-ref-summary-inline .pos-summary-row strong{
    color:#f8fafc !important;
    font-size:11.5px !important;
    font-weight:950 !important;
    text-align:right !important;
    white-space:nowrap !important;
}

/* Discount row input right aligned */
.pos-premium-cart .pos-ref-summary-inline .pos-discount-row{
    grid-template-columns:minmax(115px,1fr) 88px !important;
}

.pos-premium-cart .pos-ref-summary-inline .pos-ref-discount-control{
    width:88px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
}

.pos-premium-cart .pos-ref-summary-inline .pos-ref-discount-box{
    width:76px !important;
    height:25px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    overflow:hidden !important;
    border-radius:5px !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.16) !important;
}

.pos-premium-cart .pos-ref-summary-inline #discount,
.pos-premium-cart .pos-ref-summary-inline .pos-hidden-discount{
    width:48px !important;
    height:25px !important;
    padding:0 6px !important;
    border:0 !important;
    outline:none !important;
    background:transparent !important;
    color:#f8fafc !important;
    text-align:right !important;
    font-size:10.5px !important;
    font-weight:900 !important;
    box-shadow:none !important;
}

.pos-premium-cart .pos-ref-summary-inline .pos-ref-percent-pill{
    width:28px !important;
    height:25px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-left:1px solid rgba(148,163,184,.14) !important;
    color:#cbd5e1 !important;
    font-size:10px !important;
    font-weight:950 !important;
}

/* Total Payable row */
.pos-premium-cart .pos-ref-summary-inline .pos-grand-total-row{
    height:32px !important;
    min-height:32px !important;
    margin-top:5px !important;
    padding-top:7px !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
}

.pos-premium-cart .pos-ref-summary-inline .pos-grand-total-row span{
    color:#a78bfa !important;
    font-size:12px !important;
    font-weight:950 !important;
}

.pos-premium-cart .pos-ref-summary-inline .pos-grand-total-row strong{
    color:#a855f7 !important;
    font-size:20px !important;
    font-weight:950 !important;
}

/* Custom form full width below both columns */
.pos-premium-cart .pos-ref-custom-inline-form,
.pos-premium-cart .pos-ref-bottom-aligned .pos-custom-product-form{
    grid-column:1 / -1 !important;
    width:100% !important;
    margin:2px 0 0 !important;
    grid-template-columns:minmax(0,1fr) 78px 58px 70px !important;
    border-radius:6px !important;
    overflow:hidden !important;
    background:#050a14 !important;
    border:1px solid rgba(148,163,184,.12) !important;
}

/* Old rows agar bach gayi hon */
.pos-premium-cart .pos-summary-row:has(#sumDiscountAmount),
.pos-premium-cart .pos-summary-row:has(#sumQty){
    display:none !important;
}

/* Compact laptop */
@media (max-width:1450px), (max-height:760px){
    .pos-premium-cart .pos-ref-bottom-aligned{
        grid-template-columns:106px minmax(0,1fr) !important;
        column-gap:10px !important;
        padding-top:7px !important;
        margin-top:6px !important;
    }

    .pos-premium-cart .pos-ref-add-inline-btn,
    .pos-premium-cart .pos-add-custom-main.pos-ref-add-inline-btn{
        width:96px !important;
        height:28px !important;
        min-height:28px !important;
        font-size:10.5px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline .pos-summary-row{
        grid-template-columns:minmax(95px,1fr) 76px !important;
        min-height:20px !important;
        height:20px !important;
        font-size:10.8px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline .pos-summary-row span,
    .pos-premium-cart .pos-ref-summary-inline .pos-summary-row strong{
        font-size:10.8px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline .pos-discount-row{
        grid-template-columns:minmax(95px,1fr) 76px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline .pos-ref-discount-control{
        width:76px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline .pos-ref-discount-box{
        width:68px !important;
        height:23px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline #discount,
    .pos-premium-cart .pos-ref-summary-inline .pos-hidden-discount{
        width:42px !important;
        height:23px !important;
        font-size:10px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline .pos-ref-percent-pill{
        width:26px !important;
        height:23px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline .pos-grand-total-row{
        height:28px !important;
        min-height:28px !important;
        padding-top:6px !important;
    }

    .pos-premium-cart .pos-ref-summary-inline .pos-grand-total-row strong{
        font-size:18px !important;
    }
}

/* =========================================================
   FINAL TOTAL PAYABLE ABOVE PAYMENT BUTTONS
   Payment Method text removed, Total Payable moved here
========================================================= */

.pos-premium-cart .pos-payment-title.pos-payment-total-title{
    width:100% !important;
    flex-shrink:0 !important;
    min-height:38px !important;
    margin:4px 0 6px !important;
    padding:6px 0 7px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    color:#f8fafc !important;
    line-height:1 !important;
}

.pos-premium-cart .pos-payment-title.pos-payment-total-title span{
    color:#a78bfa !important;
    font-size:15px !important;
    font-weight:950 !important;
    line-height:1 !important;
    white-space:nowrap !important;
}

.pos-premium-cart .pos-payment-title.pos-payment-total-title strong{
    color:#a855f7 !important;
    font-size:26px !important;
    font-weight:950 !important;
    line-height:1 !important;
    text-align:right !important;
    white-space:nowrap !important;
    letter-spacing:.3px !important;
}

/* Summary ke andar old total row agar galti se reh bhi jaye to hide */
.pos-premium-cart .pos-ref-summary-inline .pos-grand-total-row{
    display:none !important;
}

/* Payment buttons ko total ke bilkul neeche aligned rakho */
.pos-premium-cart .pos-payment-methods,
.pos-premium-cart .payment-row.pos-payment-methods,
.pos-premium-cart .pos-method-grid{
    margin:0 0 7px !important;
    padding:0 !important;
    width:100% !important;
    display:grid !important;
    grid-template-columns:repeat(6, minmax(0, 1fr)) !important;
    gap:7px !important;
    align-items:stretch !important;
}

/* Buttons equal height / alignment */
.pos-premium-cart .pos-payment-methods .pay-btn,
.pos-premium-cart .pos-method-btn{
    height:42px !important;
    min-height:42px !important;
    border-radius:7px !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:center !important;
    gap:3px !important;
    margin:0 !important;
    padding:4px 2px !important;
    font-size:8.8px !important;
    line-height:1 !important;
}

/* Bottom action buttons thora compact + visible */
.pos-premium-cart .pos-invoice-actions-row{
    margin:0 !important;
    padding:0 !important;
    display:grid !important;
    grid-template-columns:1fr 1fr 1.45fr !important;
    gap:6px !important;
}

.pos-premium-cart .pos-invoice-action-btn,
.pos-premium-cart .pos-complete-sale-btn,
.pos-premium-cart .pos-proceed-btn,
.pos-premium-cart .invoice-btn{
    height:35px !important;
    min-height:35px !important;
    border-radius:7px !important;
    font-size:9.5px !important;
    margin:0 !important;
}

/* Small laptop / height compact */
@media (max-width:1450px), (max-height:760px){
    .pos-premium-cart .pos-payment-title.pos-payment-total-title{
        min-height:34px !important;
        margin:3px 0 5px !important;
        padding:5px 0 6px !important;
    }

    .pos-premium-cart .pos-payment-title.pos-payment-total-title span{
        font-size:13.5px !important;
    }

    .pos-premium-cart .pos-payment-title.pos-payment-total-title strong{
        font-size:23px !important;
    }

    .pos-premium-cart .pos-payment-methods .pay-btn,
    .pos-premium-cart .pos-method-btn{
        height:39px !important;
        min-height:39px !important;
        font-size:8px !important;
    }

    .pos-premium-cart .pos-invoice-action-btn,
    .pos-premium-cart .pos-complete-sale-btn,
    .pos-premium-cart .pos-proceed-btn,
    .pos-premium-cart .invoice-btn{
        height:33px !important;
        min-height:33px !important;
        font-size:8.8px !important;
    }
}

/* =========================================================
   ITEMS AREA STICKY BOTTOM ACTION BUTTONS
   Hold Sale | Clear Cart | Scan Barcode
========================================================= */

.items-area{
    position:relative !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
}

/* Products grid scroll karega, buttons fixed bottom par rahenge */
.items-area .items-grid{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding-bottom:10px !important;
}

.pos-items-sticky-actions{
    flex-shrink:0 !important;
    position:sticky !important;
    bottom:0 !important;
    z-index:30 !important;

    width:100% !important;
    display:grid !important;
    grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
    gap:28px !important;

    margin:12px 0 0 !important;
    padding:14px 16px !important;

    background:
        linear-gradient(180deg, rgba(7,11,20,.30), rgba(7,11,20,.96) 28%, rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
    backdrop-filter:blur(12px) !important;
    -webkit-backdrop-filter:blur(12px) !important;
}

.pos-items-sticky-btn{
    width:100% !important;
    height:46px !important;
    min-height:46px !important;
    padding:0 16px !important;

    border-radius:7px !important;
    border:1px solid rgba(124,58,237,.28) !important;
    background:#ffffff !important;
    color:#5b21b6 !important;

    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:10px !important;

    font-size:12px !important;
    font-weight:900 !important;
    line-height:1 !important;
    box-shadow:0 10px 22px rgba(0,0,0,.12) !important;
    cursor:pointer !important;
    transition:.18s ease !important;
}

.pos-items-sticky-btn:hover{
    transform:translateY(-1px) !important;
    border-color:rgba(124,58,237,.55) !important;
    box-shadow:0 14px 28px rgba(124,58,237,.18) !important;
}

.pos-items-sticky-btn.danger{
    color:#ef4444 !important;
    border-color:rgba(239,68,68,.25) !important;
}

.pos-items-sticky-btn.danger:hover{
    border-color:rgba(239,68,68,.45) !important;
    box-shadow:0 14px 28px rgba(239,68,68,.14) !important;
}

.pos-items-btn-left{
    min-width:0 !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.pos-items-btn-icon{
    width:18px !important;
    min-width:18px !important;
    height:18px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:15px !important;
    line-height:1 !important;
}

.pos-items-sticky-btn kbd{
    min-width:32px !important;
    height:18px !important;
    padding:0 6px !important;
    border-radius:4px !important;
    border:1px solid rgba(148,163,184,.25) !important;
    background:#f8fafc !important;
    color:#64748b !important;
    font-size:9px !important;
    font-weight:900 !important;
    line-height:16px !important;
    box-shadow:none !important;
}

/* Dark theme option: buttons white rahen, lekin area dark blend kare */
.items-area .pos-items-sticky-actions::before{
    content:"" !important;
    position:absolute !important;
    left:0 !important;
    right:0 !important;
    top:-18px !important;
    height:18px !important;
    pointer-events:none !important;
    background:linear-gradient(180deg, transparent, rgba(7,11,20,.92)) !important;
}

/* 1366 / small laptop */
@media (max-width:1450px), (max-height:760px){
    .pos-items-sticky-actions{
        gap:16px !important;
        padding:10px 12px !important;
        margin-top:8px !important;
    }

    .pos-items-sticky-btn{
        height:38px !important;
        min-height:38px !important;
        padding:0 12px !important;
        font-size:10.5px !important;
    }

    .pos-items-sticky-btn kbd{
        min-width:28px !important;
        height:16px !important;
        font-size:8px !important;
        line-height:14px !important;
    }
}

/* Mobile par bhi 3 buttons ek line mein compact */
@media (max-width:767.98px){
    .pos-items-sticky-actions{
        position:sticky !important;
        bottom:0 !important;
        grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
        gap:7px !important;
        padding:9px 0 0 !important;
        margin-top:10px !important;
        background:#070b14 !important;
        border-top:1px solid rgba(148,163,184,.14) !important;
    }

    .pos-items-sticky-btn{
        height:38px !important;
        min-height:38px !important;
        padding:0 6px !important;
        border-radius:9px !important;
        font-size:9px !important;
        justify-content:center !important;
        flex-direction:column !important;
        gap:3px !important;
    }

    .pos-items-btn-left{
        gap:4px !important;
    }

    .pos-items-btn-icon{
        display:none !important;
    }

    .pos-items-sticky-btn kbd{
        height:13px !important;
        min-width:24px !important;
        font-size:7px !important;
        line-height:11px !important;
    }
}

/* =========================================================
   PHARMACY UNIT DROPDOWN ON POS PRODUCT CARD
   Shortcut keys / backend logic untouched
========================================================= */

.pos-unit-wrap{
    padding:6px 12px 0 !important;
    width:100% !important;
}

.pos-unit-select{
    width:100% !important;
    height:30px !important;
    border-radius:8px !important;
    border:1px solid rgba(124,58,237,.38) !important;
    background:#070d19 !important;
    color:#e5edf8 !important;
    font-size:11px !important;
    font-weight:800 !important;
    padding:0 8px !important;
    outline:none !important;
    cursor:pointer !important;
}

.pos-unit-select:focus{
    border-color:#8b5cf6 !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.18) !important;
}

.pos-unit-select option{
    background:#0f172a !important;
    color:#e5edf8 !important;
}

@media (max-width:767.98px){
    .pos-unit-wrap{
        padding:4px 7px 0 !important;
    }

    .pos-unit-select{
        height:25px !important;
        font-size:9px !important;
        border-radius:7px !important;
        padding:0 5px !important;
    }
}



/* =========================================================
   FINAL PHARMACY CART UNIT WORKFLOW + CART ALIGNMENT FIX
   Product-card unit dropdown hidden; unit select lives in cart only.
   Shortcuts / totals / discount JS logic untouched.
========================================================= */
.pos-unit-wrap{display:none !important;}

.pos-cart-table-head.pos-cart-table-head-cart-units,
.pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
.pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
.pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
    display:grid !important;
    grid-template-columns:minmax(0,1.35fr) 92px 46px 94px 76px 70px 82px 24px !important;
    gap:6px !important;
    align-items:center !important;
    width:100% !important;
}

.pos-cart-table-head.pos-cart-table-head-cart-units{
    height:36px !important;
    min-height:36px !important;
    padding:0 8px !important;
    background:#101827 !important;
    border:1px solid rgba(148,163,184,.10) !important;
    border-radius:8px 8px 0 0 !important;
    color:#cbd5e1 !important;
    font-size:10px !important;
    line-height:1 !important;
    font-weight:950 !important;
    letter-spacing:.25px !important;
    text-transform:uppercase !important;
}

.pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
.pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
    min-height:64px !important;
    padding:8px !important;
    margin:0 !important;
    border:0 !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-radius:0 !important;
    background:transparent !important;
    box-shadow:none !important;
    overflow:visible !important;
}

.pos-ref-unit-cell,
.pos-ref-batch-cell,
.pos-ref-price-cell,
.pos-ref-discount-cell,
.pos-ref-total-cell,
.pos-ref-remove-cell,
.pos-ref-qty-cell{
    min-width:0 !important;
    max-width:100% !important;
    overflow:hidden !important;
}

.pos-ref-unit-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.pos-cart-unit-select{
    width:100% !important;
    height:31px !important;
    min-height:31px !important;
    border-radius:7px !important;
    border:1px solid rgba(124,58,237,.38) !important;
    background:#070d19 !important;
    color:#f8fafc !important;
    font-size:11px !important;
    font-weight:850 !important;
    padding:0 8px !important;
    outline:none !important;
    cursor:pointer !important;
}

.pos-cart-unit-select:focus{
    border-color:#8b5cf6 !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.15) !important;
}

.pos-cart-unit-select option{
    background:#0f172a !important;
    color:#fff !important;
}

.pos-cart-unit-static{
    width:100% !important;
    min-height:28px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:7px !important;
    background:#070d19 !important;
    border:1px solid rgba(148,163,184,.12) !important;
    color:#cbd5e1 !important;
    font-size:11px !important;
    font-weight:850 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    padding:0 7px !important;
}

.pos-premium-cart .pos-ref-product-cell,
.pos-premium-cart .ref-product-cell{
    display:grid !important;
    grid-template-columns:38px minmax(0,1fr) !important;
    column-gap:8px !important;
    align-items:center !important;
    min-width:0 !important;
    overflow:hidden !important;
}

.pos-premium-cart .pos-ref-img,
.pos-premium-cart .ref-cart-img{
    width:36px !important;
    height:36px !important;
    min-width:36px !important;
    max-width:36px !important;
    object-fit:contain !important;
    border-radius:6px !important;
    background:#0b1220 !important;
    padding:2px !important;
    border:1px solid rgba(148,163,184,.08) !important;
}

.pos-premium-cart .pos-cart-title{
    display:block !important;
    width:100% !important;
    color:#f8fafc !important;
    font-size:11.5px !important;
    line-height:1.18 !important;
    font-weight:950 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    margin:0 !important;
}

.pos-premium-cart .pos-cart-sku{
    width:100% !important;
    color:#8fa1bb !important;
    font-size:9.8px !important;
    line-height:1.1 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.pos-premium-cart .pos-cart-badge,
.pos-premium-cart .pos-cart-tier-badge{display:none !important;}

.pos-ref-batch-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    color:#94a3b8 !important;
    font-size:11px !important;
    font-weight:800 !important;
}

.pos-ref-qty-cell{display:flex !important;align-items:center !important;justify-content:center !important;}
.pos-premium-cart .pos-cart-controls{display:inline-flex !important;align-items:center !important;justify-content:center !important;gap:0 !important;transform:none !important;}
.pos-premium-cart .pos-cart-btn{width:28px !important;min-width:28px !important;height:28px !important;background:#0b1220 !important;border:1px solid rgba(148,163,184,.12) !important;color:#fff !important;box-shadow:none !important;font-size:13px !important;}
.pos-premium-cart .pos-cart-btn[data-cart-minus]{border-radius:6px 0 0 6px !important;}
.pos-premium-cart .pos-cart-btn[data-cart-plus]{border-radius:0 6px 6px 0 !important;}
.pos-premium-cart .pos-cart-qty{width:36px !important;min-width:36px !important;height:28px !important;border-radius:0 !important;background:#070d19 !important;border:1px solid rgba(148,163,184,.12) !important;border-left:0 !important;border-right:0 !important;color:#fff !important;text-align:center !important;font-size:12px !important;font-weight:900 !important;}

.pos-ref-price-cell{display:flex !important;align-items:center !important;justify-content:flex-start !important;}
.pos-ref-price-cell::before{content:'Rs' !important;margin-right:3px !important;color:#f8fafc !important;font-size:11px !important;font-weight:900 !important;}
.pos-premium-cart .pos-cart-price,
.pos-premium-cart input[data-cart-price-input]{width:48px !important;min-width:48px !important;max-width:48px !important;height:26px !important;padding:0 !important;margin:0 !important;border:0 !important;background:transparent !important;color:#f8fafc !important;text-align:left !important;font-size:11.5px !important;font-weight:950 !important;appearance:textfield !important;-moz-appearance:textfield !important;box-shadow:none !important;}
.pos-premium-cart .pos-cart-price::-webkit-inner-spin-button,
.pos-premium-cart .pos-cart-price::-webkit-outer-spin-button,
.pos-premium-cart input[data-cart-price-input]::-webkit-inner-spin-button,
.pos-premium-cart input[data-cart-price-input]::-webkit-outer-spin-button{display:none !important;-webkit-appearance:none !important;appearance:none !important;}

.pos-ref-discount-cell{display:flex !important;align-items:center !important;justify-content:center !important;}
.pos-premium-cart .pos-line-discount-control{width:54px !important;height:28px !important;display:flex !important;align-items:center !important;justify-content:center !important;border-radius:7px !important;background:#070d19 !important;border:1px solid rgba(148,163,184,.12) !important;}
.pos-premium-cart .pos-line-discount-input{width:32px !important;height:26px !important;border:0 !important;background:transparent !important;color:#fff !important;text-align:center !important;font-size:11px !important;font-weight:900 !important;outline:none !important;}
.pos-premium-cart .pos-line-discount-control span{font-size:10px !important;color:#cbd5e1 !important;font-weight:900 !important;}
.pos-premium-cart .pos-line-discount-text{display:none !important;}

.pos-ref-total-cell{display:flex !important;align-items:center !important;justify-content:flex-end !important;}
.pos-premium-cart .pos-cart-total,
.pos-premium-cart [data-line-total]{width:82px !important;max-width:82px !important;color:#f8fafc !important;text-align:right !important;font-size:11.5px !important;font-weight:950 !important;white-space:nowrap !important;overflow:hidden !important;text-overflow:ellipsis !important;}
.pos-ref-remove-cell{display:flex !important;align-items:center !important;justify-content:flex-end !important;}
.pos-premium-cart [data-cart-remove]{width:22px !important;min-width:22px !important;height:22px !important;border-radius:50% !important;background:transparent !important;color:#94a3b8 !important;border:1px solid rgba(148,163,184,.22) !important;font-size:10px !important;box-shadow:none !important;}
.pos-premium-cart [data-cart-remove]:hover{background:rgba(244,63,94,.13) !important;color:#fb7185 !important;border-color:rgba(244,63,94,.40) !important;}

@media (max-width:1450px){
    .pos-layout{grid-template-columns:minmax(0,1fr) 540px !important;}
    .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        grid-template-columns:minmax(0,1.18fr) 76px 34px 86px 62px 58px 68px 22px !important;
        gap:5px !important;
        padding-left:6px !important;
        padding-right:6px !important;
    }
    .pos-premium-cart .pos-ref-product-cell,.pos-premium-cart .ref-product-cell{grid-template-columns:30px minmax(0,1fr) !important;column-gap:6px !important;}
    .pos-premium-cart .pos-ref-img,.pos-premium-cart .ref-cart-img{width:28px !important;height:28px !important;min-width:28px !important;max-width:28px !important;}
    .pos-cart-unit-select{height:27px !important;font-size:10px !important;padding:0 4px !important;}
    .pos-premium-cart .pos-cart-title{font-size:10.2px !important;}
    .pos-premium-cart .pos-cart-sku{font-size:8.8px !important;}
    .pos-premium-cart .pos-cart-price,.pos-premium-cart input[data-cart-price-input]{width:36px !important;min-width:36px !important;max-width:36px !important;font-size:10px !important;}
    .pos-premium-cart .pos-cart-total,.pos-premium-cart [data-line-total]{width:68px !important;max-width:68px !important;font-size:10px !important;}
    .pos-premium-cart .pos-line-discount-control{width:48px !important;}
    .pos-premium-cart .pos-line-discount-input{width:28px !important;font-size:10px !important;}
}

@media (max-width:1200px){
    .pos-cart-table-head.pos-cart-table-head-cart-units{display:none !important;}
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:8px !important;
        border:1px solid rgba(148,163,184,.12) !important;
        border-radius:12px !important;
        margin-bottom:8px !important;
        background:#0b1220 !important;
    }
    .pos-ref-unit-cell::before{content:'Unit'; color:#94a3b8; font-size:11px; font-weight:900; text-transform:uppercase;}
    .pos-ref-price-cell::before{content:'Unit Price' !important; color:#94a3b8 !important; font-size:11px !important; font-weight:900 !important; text-transform:uppercase !important;}
    .pos-ref-qty-cell::before{content:'Qty'; color:#94a3b8; font-size:11px; font-weight:900; text-transform:uppercase;}
    .pos-ref-discount-cell::before{content:'Discount'; color:#94a3b8; font-size:11px; font-weight:900; text-transform:uppercase;}
    .pos-ref-total-cell::before{content:'Total'; color:#94a3b8; font-size:11px; font-weight:900; text-transform:uppercase;}
    .pos-ref-unit-cell,.pos-ref-price-cell,.pos-ref-qty-cell,.pos-ref-discount-cell,.pos-ref-total-cell,.pos-ref-remove-cell{width:100% !important;display:flex !important;justify-content:space-between !important;align-items:center !important;}
    .pos-cart-unit-select{max-width:150px !important;}
}


/* =========================================================
   FINAL CART ALIGNMENT FIX - NO BATCH COLUMN
   Only UI alignment changed. Backend / shortcut keys / discount / totals untouched.
========================================================= */

/* Product-card unit dropdown stays hidden; unit selection remains inside cart only */
.pos-unit-wrap{
    display:none !important;
}

/* Remove Batch column from visual cart table */
.pos-cart-table-head.pos-cart-table-head-cart-units > div:nth-child(3),
.pos-premium-cart .pos-ref-batch-cell,
.pos-ref-batch-cell{
    display:none !important;
}

/* Cart columns: Item | Unit | Qty | Unit Price | Discount | Total | Remove */
.pos-cart-table-head.pos-cart-table-head-cart-units,
.pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
.pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
.pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
    display:grid !important;
    grid-template-columns:minmax(0,1.9fr) 82px 88px 72px 64px 78px 22px !important;
    gap:6px !important;
    align-items:center !important;
    width:100% !important;
}

/* Header clean spacing */
.pos-cart-table-head.pos-cart-table-head-cart-units{
    height:36px !important;
    min-height:36px !important;
    padding:0 8px !important;
    overflow:hidden !important;
}

.pos-cart-table-head.pos-cart-table-head-cart-units > div{
    min-width:0 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

/* Item column: name must not cut like pana... */
.pos-premium-cart .pos-ref-product-cell,
.pos-premium-cart .ref-product-cell{
    display:grid !important;
    grid-template-columns:34px minmax(0,1fr) !important;
    column-gap:8px !important;
    align-items:center !important;
    min-width:0 !important;
    width:100% !important;
    overflow:visible !important;
}

.pos-premium-cart .pos-ref-product-text,
.pos-premium-cart .ref-product-text,
.pos-premium-cart .pos-cart-left{
    min-width:0 !important;
    max-width:100% !important;
    overflow:visible !important;
}

.pos-premium-cart .pos-cart-title,
.pos-premium-cart .pos-ref-product-text .pos-cart-title,
.pos-premium-cart .ref-product-text .pos-cart-title{
    width:100% !important;
    max-width:100% !important;
    white-space:normal !important;
    overflow:visible !important;
    text-overflow:clip !important;
    display:block !important;
    word-break:break-word !important;
    line-height:1.22 !important;
    font-size:12px !important;
    font-weight:950 !important;
}

/* SKU/batch info stays small under item name, not a separate column */
.pos-premium-cart .pos-cart-sku,
.pos-premium-cart .pos-ref-product-text .pos-cart-sku,
.pos-premium-cart .ref-product-text .pos-cart-sku{
    width:100% !important;
    max-width:100% !important;
    white-space:normal !important;
    overflow:visible !important;
    text-overflow:clip !important;
    line-height:1.15 !important;
    font-size:10px !important;
}

/* Controls alignment */
.pos-ref-unit-cell,
.pos-ref-qty-cell,
.pos-ref-price-cell,
.pos-ref-discount-cell,
.pos-ref-total-cell,
.pos-ref-remove-cell{
    min-width:0 !important;
    max-width:100% !important;
    overflow:hidden !important;
}

.pos-ref-unit-cell,
.pos-ref-qty-cell,
.pos-ref-discount-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.pos-ref-price-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-start !important;
}

.pos-ref-total-cell,
.pos-ref-remove-cell{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
}

.pos-cart-unit-select{
    width:100% !important;
    max-width:100% !important;
    height:30px !important;
    font-size:11px !important;
}

.pos-premium-cart .pos-cart-controls{
    transform:none !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:0 !important;
}

.pos-premium-cart .pos-cart-btn{
    width:27px !important;
    min-width:27px !important;
    height:28px !important;
}

.pos-premium-cart .pos-cart-qty{
    width:34px !important;
    min-width:34px !important;
    height:28px !important;
}

.pos-premium-cart .pos-cart-price,
.pos-premium-cart input[data-cart-price-input]{
    width:42px !important;
    min-width:42px !important;
    max-width:42px !important;
    font-size:11px !important;
}

.pos-premium-cart .pos-line-discount-control{
    width:54px !important;
    max-width:54px !important;
}

.pos-premium-cart .pos-cart-total,
.pos-premium-cart [data-line-total]{
    width:78px !important;
    max-width:78px !important;
    text-align:right !important;
    white-space:nowrap !important;
    font-size:11.5px !important;
}

/* Laptop compact width */
@media (max-width:1450px){
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 540px !important;
    }

    .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        grid-template-columns:minmax(0,1.75fr) 76px 82px 64px 56px 68px 20px !important;
        gap:5px !important;
        padding-left:6px !important;
        padding-right:6px !important;
    }

    .pos-premium-cart .pos-ref-product-cell,
    .pos-premium-cart .ref-product-cell{
        grid-template-columns:28px minmax(0,1fr) !important;
        column-gap:6px !important;
    }

    .pos-premium-cart .pos-ref-img,
    .pos-premium-cart .ref-cart-img{
        width:26px !important;
        height:26px !important;
        min-width:26px !important;
        max-width:26px !important;
    }

    .pos-premium-cart .pos-cart-title,
    .pos-premium-cart .pos-ref-product-text .pos-cart-title,
    .pos-premium-cart .ref-product-text .pos-cart-title{
        font-size:11px !important;
        line-height:1.18 !important;
    }

    .pos-cart-unit-select{
        font-size:10px !important;
        padding:0 4px !important;
    }

    .pos-premium-cart .pos-cart-price,
    .pos-premium-cart input[data-cart-price-input]{
        width:34px !important;
        min-width:34px !important;
        max-width:34px !important;
        font-size:10px !important;
    }

    .pos-premium-cart .pos-line-discount-control{
        width:48px !important;
    }

    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total]{
        width:68px !important;
        max-width:68px !important;
        font-size:10.5px !important;
    }
}

/* Mobile card view remains clean */
@media (max-width:1200px){
    .pos-cart-table-head.pos-cart-table-head-cart-units{
        display:none !important;
    }

    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:8px !important;
    }

    .pos-ref-unit-cell::before{content:'Unit'; color:#94a3b8; font-size:11px; font-weight:900; text-transform:uppercase;}
    .pos-ref-qty-cell::before{content:'Qty'; color:#94a3b8; font-size:11px; font-weight:900; text-transform:uppercase;}
    .pos-ref-price-cell::before{content:'Unit Price' !important; color:#94a3b8 !important; font-size:11px !important; font-weight:900 !important; text-transform:uppercase !important;}
    .pos-ref-discount-cell::before{content:'Discount'; color:#94a3b8; font-size:11px; font-weight:900; text-transform:uppercase;}
    .pos-ref-total-cell::before{content:'Total'; color:#94a3b8; font-size:11px; font-weight:900; text-transform:uppercase;}

    .pos-ref-unit-cell,
    .pos-ref-qty-cell,
    .pos-ref-price-cell,
    .pos-ref-discount-cell,
    .pos-ref-total-cell,
    .pos-ref-remove-cell{
        width:100% !important;
        display:flex !important;
        justify-content:space-between !important;
        align-items:center !important;
    }
}



/* =========================================================
   FINAL SENIOR CART COLUMN FIX - NO BATCH COLUMN
   Only cart UI/alignment changed. Backend, shortcuts, discount,
   totals, payment workflow untouched.
   Columns: Item | Unit | Qty | Unit Price | Discount | Total | Remove
========================================================= */

/* Product card par unit dropdown nahi chahiye */
.pos-unit-wrap,
.product-card .pos-unit-wrap,
.item-card .pos-unit-wrap{
    display:none !important;
}

/* Purani CSS Qty header ko hide kar rahi thi; usko force show karo */
.pos-cart-table-head.pos-cart-table-head-cart-units > div:nth-child(3){
    display:block !important;
}

/* Batch visual column remove; JS already batch cell remove karta hai */
.pos-ref-batch-cell,
.pos-premium-cart .pos-ref-batch-cell{
    display:none !important;
}

/* Desktop cart width balanced */
@media (min-width:1201px){
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 560px !important;
    }

    .pos-premium-cart{
        padding:12px !important;
        min-width:0 !important;
        overflow:hidden !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        display:grid !important;
        grid-template-columns:minmax(148px,1fr) 74px 78px 58px 54px 66px 20px !important;
        gap:6px !important;
        align-items:center !important;
        width:100% !important;
        max-width:100% !important;
        box-sizing:border-box !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units{
        height:36px !important;
        min-height:36px !important;
        padding:0 8px !important;
        margin:0 !important;
        overflow:hidden !important;
        background:#101827 !important;
        border:1px solid rgba(148,163,184,.10) !important;
        border-radius:8px 8px 0 0 !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div{
        display:block !important;
        min-width:0 !important;
        overflow:hidden !important;
        white-space:nowrap !important;
        text-overflow:ellipsis !important;
        color:#dbeafe !important;
        font-size:10px !important;
        line-height:1 !important;
        font-weight:950 !important;
        text-transform:uppercase !important;
        letter-spacing:.18px !important;
        text-align:center !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div:first-child{
        text-align:left !important;
    }

    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        min-height:62px !important;
        height:auto !important;
        padding:8px !important;
        margin:0 !important;
        border:0 !important;
        border-bottom:1px solid rgba(148,163,184,.10) !important;
        border-radius:0 !important;
        background:transparent !important;
        box-shadow:none !important;
        overflow:hidden !important;
    }

    /* Item column: image hide, full item name readable */
    .pos-premium-cart .pos-ref-img,
    .pos-premium-cart .ref-cart-img,
    .pos-premium-cart .pos-cart-item img{
        display:none !important;
    }

    .pos-premium-cart .pos-ref-product-cell,
    .pos-premium-cart .ref-product-cell,
    .pos-premium-cart .pos-cart-left{
        display:block !important;
        min-width:0 !important;
        width:100% !important;
        max-width:100% !important;
        overflow:visible !important;
    }

    .pos-premium-cart .pos-ref-product-text,
    .pos-premium-cart .ref-product-text,
    .pos-premium-cart .pos-cart-head{
        display:block !important;
        min-width:0 !important;
        width:100% !important;
        max-width:100% !important;
        overflow:visible !important;
    }

    .pos-premium-cart .pos-cart-title,
    .pos-premium-cart .pos-ref-product-text .pos-cart-title,
    .pos-premium-cart .ref-product-text .pos-cart-title{
        display:block !important;
        width:100% !important;
        max-width:100% !important;
        margin:0 !important;
        color:#f8fafc !important;
        font-size:12px !important;
        line-height:1.22 !important;
        font-weight:950 !important;
        white-space:normal !important;
        word-break:normal !important;
        overflow:visible !important;
        text-overflow:clip !important;
        -webkit-line-clamp:unset !important;
        -webkit-box-orient:unset !important;
    }

    .pos-premium-cart .pos-cart-sku,
    .pos-premium-cart .pos-ref-product-text .pos-cart-sku,
    .pos-premium-cart .ref-product-text .pos-cart-sku{
        display:block !important;
        width:100% !important;
        max-width:100% !important;
        margin-top:3px !important;
        padding:0 !important;
        background:transparent !important;
        color:#8fa1bb !important;
        font-size:9.5px !important;
        line-height:1.15 !important;
        font-weight:800 !important;
        white-space:normal !important;
        overflow:visible !important;
        text-overflow:clip !important;
    }

    .pos-premium-cart .pos-cart-badge,
    .pos-premium-cart .pos-cart-tier-badge{
        display:none !important;
    }

    /* Unit */
    .pos-premium-cart .pos-ref-unit-cell{
        min-width:0 !important;
        overflow:visible !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
    }

    .pos-premium-cart .pos-cart-unit-select,
    .pos-premium-cart .pos-cart-unit-static{
        width:100% !important;
        max-width:100% !important;
        height:31px !important;
        min-height:31px !important;
        border-radius:7px !important;
        font-size:11px !important;
        font-weight:850 !important;
        padding:0 7px !important;
        white-space:nowrap !important;
    }

    /* Qty */
    .pos-premium-cart .pos-ref-qty-cell{
        min-width:0 !important;
        overflow:visible !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
    }

    .pos-premium-cart .pos-cart-controls{
        width:78px !important;
        max-width:78px !important;
        display:inline-grid !important;
        grid-template-columns:24px 30px 24px !important;
        gap:0 !important;
        align-items:center !important;
        justify-content:center !important;
        transform:none !important;
        border-radius:7px !important;
        overflow:hidden !important;
        background:#070d19 !important;
        border:1px solid rgba(148,163,184,.12) !important;
    }

    .pos-premium-cart .pos-cart-btn{
        width:24px !important;
        min-width:24px !important;
        height:28px !important;
        min-height:28px !important;
        border:0 !important;
        border-radius:0 !important;
        background:transparent !important;
        color:#fff !important;
        font-size:12px !important;
        font-weight:950 !important;
        padding:0 !important;
        box-shadow:none !important;
    }

    .pos-premium-cart .pos-cart-qty{
        width:30px !important;
        min-width:30px !important;
        height:28px !important;
        border:0 !important;
        border-left:1px solid rgba(148,163,184,.12) !important;
        border-right:1px solid rgba(148,163,184,.12) !important;
        border-radius:0 !important;
        background:transparent !important;
        color:#fff !important;
        font-size:12px !important;
        font-weight:950 !important;
        text-align:center !important;
        padding:0 !important;
    }

    /* Unit Price */
    .pos-premium-cart .pos-ref-price-cell{
        min-width:0 !important;
        overflow:hidden !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
    }

    .pos-premium-cart .pos-ref-price-cell::before{
        content:'Rs' !important;
        margin-right:3px !important;
        color:#f8fafc !important;
        font-size:10.5px !important;
        font-weight:950 !important;
    }

    .pos-premium-cart .pos-cart-price,
    .pos-premium-cart input[data-cart-price-input]{
        width:36px !important;
        min-width:36px !important;
        max-width:36px !important;
        height:24px !important;
        padding:0 !important;
        margin:0 !important;
        border:0 !important;
        outline:none !important;
        background:transparent !important;
        color:#f8fafc !important;
        text-align:left !important;
        font-size:10.5px !important;
        font-weight:950 !important;
        box-shadow:none !important;
        appearance:textfield !important;
        -moz-appearance:textfield !important;
    }

    .pos-premium-cart .pos-cart-price::-webkit-inner-spin-button,
    .pos-premium-cart .pos-cart-price::-webkit-outer-spin-button,
    .pos-premium-cart input[data-cart-price-input]::-webkit-inner-spin-button,
    .pos-premium-cart input[data-cart-price-input]::-webkit-outer-spin-button{
        display:none !important;
        -webkit-appearance:none !important;
        appearance:none !important;
    }

    /* Discount */
    .pos-premium-cart .pos-ref-discount-cell{
        min-width:0 !important;
        overflow:hidden !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
    }

    .pos-premium-cart .pos-line-discount-control{
        width:50px !important;
        max-width:50px !important;
        height:27px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        border-radius:7px !important;
        background:#070d19 !important;
        border:1px solid rgba(148,163,184,.12) !important;
    }

    .pos-premium-cart .pos-line-discount-input{
        width:30px !important;
        height:25px !important;
        padding:0 !important;
        border:0 !important;
        outline:none !important;
        background:transparent !important;
        color:#fff !important;
        text-align:center !important;
        font-size:10.5px !important;
        font-weight:950 !important;
    }

    .pos-premium-cart .pos-line-discount-control span{
        color:#cbd5e1 !important;
        font-size:10px !important;
        font-weight:900 !important;
    }

    .pos-premium-cart .pos-line-discount-text{
        display:none !important;
    }

    /* Total */
    .pos-premium-cart .pos-ref-total-cell{
        min-width:0 !important;
        overflow:hidden !important;
        display:flex !important;
        align-items:center !important;
        justify-content:flex-end !important;
    }

    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total]{
        width:66px !important;
        max-width:66px !important;
        margin:0 !important;
        color:#f8fafc !important;
        text-align:right !important;
        font-size:10.8px !important;
        font-weight:950 !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
    }

    /* Remove */
    .pos-premium-cart .pos-ref-remove-cell{
        min-width:0 !important;
        overflow:hidden !important;
        display:flex !important;
        align-items:center !important;
        justify-content:flex-end !important;
    }

    .pos-premium-cart .pos-ref-remove-cell .pos-cart-btn,
    .pos-premium-cart [data-cart-remove]{
        width:20px !important;
        min-width:20px !important;
        height:20px !important;
        min-height:20px !important;
        border-radius:50% !important;
        background:transparent !important;
        border:1px solid rgba(148,163,184,.24) !important;
        color:#94a3b8 !important;
        font-size:10px !important;
        line-height:1 !important;
        box-shadow:none !important;
    }

    .pos-premium-cart .pos-cart-right{
        display:contents !important;
    }
}

/* Very compact laptop width */
@media (min-width:1201px) and (max-width:1450px){
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 540px !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        grid-template-columns:minmax(136px,1fr) 72px 76px 54px 52px 62px 20px !important;
        gap:5px !important;
        padding-left:6px !important;
        padding-right:6px !important;
    }

    .pos-premium-cart .pos-cart-title,
    .pos-premium-cart .pos-ref-product-text .pos-cart-title,
    .pos-premium-cart .ref-product-text .pos-cart-title{
        font-size:11.5px !important;
        line-height:1.2 !important;
    }

    .pos-premium-cart .pos-cart-sku{
        font-size:9px !important;
    }

    .pos-premium-cart .pos-cart-unit-select,
    .pos-premium-cart .pos-cart-unit-static{
        font-size:10.5px !important;
        padding:0 5px !important;
    }

    .pos-premium-cart .pos-cart-controls{
        width:76px !important;
        grid-template-columns:23px 30px 23px !important;
    }

    .pos-premium-cart .pos-cart-btn{
        width:23px !important;
        min-width:23px !important;
    }

    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total]{
        width:62px !important;
        max-width:62px !important;
        font-size:10.3px !important;
    }
}

/* Mobile card view: header hide, each row label based */
@media (max-width:1200px){
    .pos-cart-table-head.pos-cart-table-head-cart-units{
        display:none !important;
    }

    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        grid-template-columns:1fr !important;
        gap:8px !important;
    }
}


/* =========================================================
   FINAL CART HEADER FULL TEXT FIX
   Unit Price + Discount full show
   Backend / JS / shortcut keys / totals / discount logic untouched
========================================================= */

@media (min-width:1201px){

    /* Cart ko thori proper width do taake columns squeeze na hon */
    .pos-layout{
        grid-template-columns:minmax(0,1fr) 590px !important;
    }

    .pos-premium-cart{
        min-width:0 !important;
        overflow:hidden !important;
    }

    /*
      Columns:
      Item | Unit | Qty | Unit Price | Discount | Total | Remove
    */
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        display:grid !important;
        grid-template-columns:
            minmax(118px, 1fr)
            78px
            84px
            84px
            78px
            72px
            22px !important;
        gap:7px !important;
        align-items:center !important;
        width:100% !important;
        max-width:100% !important;
        box-sizing:border-box !important;
    }

    /* Header text ko cut/ellipsis na karo */
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units{
        height:38px !important;
        min-height:38px !important;
        padding:0 8px !important;
        overflow:visible !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div{
        display:flex !important;
        align-items:center !important;
        justify-content:flex-start !important;
        min-width:0 !important;
        overflow:visible !important;
        white-space:nowrap !important;
        text-overflow:clip !important;
        color:#eaf1ff !important;
        font-size:9.6px !important;
        line-height:1 !important;
        font-weight:950 !important;
        letter-spacing:0 !important;
        text-transform:uppercase !important;
    }

    /* Unit Price aur Discount ko center rakho */
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div:nth-child(4),
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div:nth-child(5){
        justify-content:center !important;
        font-size:9.4px !important;
    }

    /* Total right aligned */
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div:nth-child(6){
        justify-content:flex-end !important;
    }

    /* Row cells alignment */
    .pos-premium-cart .pos-ref-unit-cell{
        width:78px !important;
        max-width:78px !important;
        justify-content:center !important;
    }

    .pos-premium-cart .pos-ref-qty-cell{
        width:84px !important;
        max-width:84px !important;
        justify-content:center !important;
    }

    .pos-premium-cart .pos-ref-price-cell{
        width:84px !important;
        max-width:84px !important;
        justify-content:center !important;
        overflow:visible !important;
    }

    .pos-premium-cart .pos-ref-discount-cell{
        width:78px !important;
        max-width:78px !important;
        justify-content:center !important;
        overflow:visible !important;
    }

    .pos-premium-cart .pos-ref-total-cell{
        width:72px !important;
        max-width:72px !important;
        justify-content:flex-end !important;
    }

    .pos-premium-cart .pos-ref-remove-cell{
        width:22px !important;
        max-width:22px !important;
        justify-content:flex-end !important;
    }

    /* Unit price value */
    .pos-premium-cart .pos-ref-price-cell::before{
        content:'Rs' !important;
        margin-right:3px !important;
        font-size:11px !important;
        font-weight:900 !important;
        color:#f8fafc !important;
    }

    .pos-premium-cart .pos-cart-price,
    .pos-premium-cart input[data-cart-price-input]{
        width:44px !important;
        min-width:44px !important;
        max-width:44px !important;
        font-size:11px !important;
        text-align:left !important;
    }

    /* Discount box ko proper width do */
    .pos-premium-cart .pos-line-discount-control{
        width:62px !important;
        min-width:62px !important;
        max-width:62px !important;
        height:28px !important;
    }

    .pos-premium-cart .pos-line-discount-input{
        width:34px !important;
        min-width:34px !important;
        max-width:34px !important;
        font-size:11px !important;
        text-align:center !important;
    }

    .pos-premium-cart .pos-line-discount-control span,
    .pos-premium-cart .pos-line-discount-control .pos-line-discount-sign{
        width:20px !important;
        min-width:20px !important;
        font-size:11px !important;
    }

    /* Total amount */
    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total]{
        width:72px !important;
        max-width:72px !important;
        font-size:11px !important;
        text-align:right !important;
        white-space:nowrap !important;
    }

    /* Qty control thora compact but readable */
    .pos-premium-cart .pos-cart-controls{
        width:84px !important;
        display:grid !important;
        grid-template-columns:24px 34px 24px !important;
        transform:none !important;
    }

    .pos-premium-cart .pos-cart-btn{
        width:24px !important;
        min-width:24px !important;
        height:28px !important;
    }

    .pos-premium-cart .pos-cart-qty{
        width:34px !important;
        min-width:34px !important;
        height:28px !important;
    }
}

/* 1366px / laptop width ke liye extra safe compact version */
@media (min-width:1201px) and (max-width:1450px){

    .pos-layout{
        grid-template-columns:minmax(0,1fr) 560px !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        grid-template-columns:
            minmax(112px, 1fr)
            74px
            78px
            78px
            74px
            66px
            20px !important;
        gap:6px !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div{
        font-size:9px !important;
        letter-spacing:0 !important;
    }

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div:nth-child(4),
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div:nth-child(5){
        font-size:8.8px !important;
    }

    .pos-premium-cart .pos-ref-unit-cell{ width:74px !important; max-width:74px !important; }
    .pos-premium-cart .pos-ref-qty-cell{ width:78px !important; max-width:78px !important; }
    .pos-premium-cart .pos-ref-price-cell{ width:78px !important; max-width:78px !important; }
    .pos-premium-cart .pos-ref-discount-cell{ width:74px !important; max-width:74px !important; }
    .pos-premium-cart .pos-ref-total-cell{ width:66px !important; max-width:66px !important; }
}

/* =========================================================
   FINAL CART REMOVE ICON VISIBLE FIX
   Delete / cross icon cut nahi hoga
   Backend / JS / shortcut keys / discount / totals untouched
========================================================= */

@media (min-width:1201px){

    .pos-premium-cart{
        overflow:hidden !important;
    }

    .pos-premium-cart .pos-cart-items-list,
    .pos-premium-cart .cart-items{
        overflow-y:auto !important;
        overflow-x:hidden !important;
        padding-right:0 !important;
    }

    /* Columns: Item | Unit | Qty | Unit Price | Discount | Total | Remove */
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        grid-template-columns:
            minmax(142px, 1fr)
            74px
            78px
            58px
            54px
            62px
            30px !important;
        gap:6px !important;
        padding-left:8px !important;
        padding-right:6px !important;
        box-sizing:border-box !important;
        overflow:visible !important;
    }

    /* Total ko thora compact karo taake remove icon ke liye space banay */
    .pos-premium-cart .pos-ref-total-cell{
        width:62px !important;
        max-width:62px !important;
        justify-content:flex-end !important;
        overflow:visible !important;
    }

    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total]{
        width:62px !important;
        max-width:62px !important;
        font-size:11px !important;
        text-align:right !important;
        white-space:nowrap !important;
        overflow:visible !important;
        text-overflow:clip !important;
    }

    /* Remove column full visible */
    .pos-premium-cart .pos-ref-remove-cell,
    .pos-premium-cart .ref-remove-cell{
        width:30px !important;
        min-width:30px !important;
        max-width:30px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        overflow:visible !important;
        position:static !important;
        z-index:5 !important;
    }

    .pos-premium-cart .pos-ref-remove-cell .pos-cart-btn,
    .pos-premium-cart .ref-remove-cell .pos-cart-btn,
    .pos-premium-cart [data-cart-remove]{
        width:22px !important;
        min-width:22px !important;
        max-width:22px !important;
        height:22px !important;
        min-height:22px !important;
        max-height:22px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        border-radius:50% !important;
        background:transparent !important;
        color:#94a3b8 !important;
        border:1px solid rgba(148,163,184,.28) !important;
        font-size:11px !important;
        line-height:1 !important;
        padding:0 !important;
        margin:0 !important;
        opacity:1 !important;
        visibility:visible !important;
        overflow:visible !important;
        cursor:pointer !important;
        pointer-events:auto !important;
    }

    .pos-premium-cart .pos-ref-remove-cell .pos-cart-btn:hover,
    .pos-premium-cart .ref-remove-cell .pos-cart-btn:hover,
    .pos-premium-cart [data-cart-remove]:hover{
        background:rgba(239,68,68,.15) !important;
        color:#fb7185 !important;
        border-color:rgba(239,68,68,.45) !important;
    }

    /* Header ka last empty delete column bhi space le */
    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units > div:last-child{
        width:30px !important;
        min-width:30px !important;
        max-width:30px !important;
        display:block !important;
        overflow:visible !important;
    }
}

/* 1366 laptop safe */
@media (min-width:1201px) and (max-width:1450px){

    .pos-premium-cart .pos-cart-table-head.pos-cart-table-head-cart-units,
    .pos-premium-cart .pos-cart-item.pos-ref-row.pos-cart-unit-row-ready,
    .pos-premium-cart .pos-cart-item.ref-row-ready.pos-cart-unit-row-ready{
        grid-template-columns:
            minmax(132px, 1fr)
            72px
            76px
            56px
            52px
            58px
            30px !important;
        gap:5px !important;
        padding-right:6px !important;
    }

    .pos-premium-cart .pos-ref-total-cell,
    .pos-premium-cart .pos-cart-total,
    .pos-premium-cart [data-line-total]{
        width:58px !important;
        max-width:58px !important;
        font-size:10.5px !important;
    }

    .pos-premium-cart .pos-ref-remove-cell,
    .pos-premium-cart .ref-remove-cell{
        width:30px !important;
        min-width:30px !important;
        max-width:30px !important;
    }
}

/* =========================================================
   PRODUCT CARD 2-LINE CONTENT FIX
   Line 1: Name + SKU
   Line 2: Price + Add Button
   Image box bigger
   Backend / JS / POS logic untouched
========================================================= */

@media (min-width:1201px){

    /* Cards ko 3-column layout mein proper width do */
    .items-grid{
        grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)) !important;
        gap:16px !important;
        align-content:start !important;
    }

    /* Card structure:
       Row 1 = Image
       Row 2 = Name + SKU
       Row 3 = Price + Add button
    */
    .item-card,
    .product-card{
        position:relative !important;
        display:grid !important;
        grid-template-rows:minmax(190px, 1fr) 34px 46px !important;
        min-height:270px !important;
        padding:0 !important;
        overflow:hidden !important;
        border-radius:12px !important;
        background:#0f172a !important;
    }

    /* Image box bara */
    .item-card .item-img,
    .product-card .item-img{
        grid-row:1 !important;
        width:100% !important;
        height:100% !important;
        min-height:190px !important;
        aspect-ratio:auto !important;
        margin:0 !important;
        border-radius:12px 12px 0 0 !important;
        background:#0a0f1d !important;
        overflow:hidden !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
    }

    .item-card .item-img img,
    .product-card .item-img img{
        width:100% !important;
        height:100% !important;
        max-width:none !important;
        max-height:none !important;
        object-fit:cover !important;
        display:block !important;
    }

    /* Line 1: Product name */
    .item-card .item-title,
    .product-card .item-title{
        grid-row:2 !important;
        width:100% !important;
        height:34px !important;
        min-height:34px !important;
        max-height:34px !important;
        padding:0 64px 0 12px !important;
        margin:0 !important;
        display:flex !important;
        align-items:center !important;
        color:#f8fafc !important;
        font-size:13px !important;
        font-weight:900 !important;
        line-height:1 !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
        -webkit-line-clamp:unset !important;
        -webkit-box-orient:unset !important;
    }

    /* Same line par right side SKU button */
    .item-card .item-sku-wrap,
    .product-card .item-sku-wrap{
        grid-row:2 !important;
        position:absolute !important;
        right:12px !important;
        bottom:46px !important;
        height:34px !important;
        min-height:34px !important;
        padding:0 !important;
        margin:0 !important;
        display:flex !important;
        align-items:center !important;
        justify-content:flex-end !important;
        z-index:3 !important;
    }

    .item-card .sku-mini-btn,
    .product-card .sku-mini-btn{
        height:20px !important;
        min-width:36px !important;
        padding:0 7px !important;
        border-radius:6px !important;
        font-size:10px !important;
        line-height:18px !important;
        font-weight:900 !important;
    }

    /* SKU duplicate text hide rakho, sirf SKU button show ho */
    .item-card .item-sku-wrap .item-sku,
    .product-card .item-sku-wrap .item-sku{
        display:none !important;
    }

    /* Tax badge etc. third line na banaye */
    .item-card .item-tax-badge,
    .product-card .item-tax-badge{
        display:none !important;
    }

    /* Line 2: Price + Add button */
    .item-card .item-bottom,
    .product-card .item-bottom{
        grid-row:3 !important;
        height:46px !important;
        min-height:46px !important;
        padding:0 12px 12px !important;
        margin:0 !important;
        display:flex !important;
        align-items:center !important;
        justify-content:space-between !important;
        gap:10px !important;
    }

    .item-card .item-price,
    .product-card .item-price{
        display:flex !important;
        align-items:center !important;
        gap:4px !important;
        margin:0 !important;
        color:#f8fafc !important;
        font-size:20px !important;
        font-weight:950 !important;
        line-height:1 !important;
        white-space:nowrap !important;
    }

    .item-card .item-price small,
    .product-card .item-price small{
        display:inline !important;
        margin:0 !important;
        color:#dbeafe !important;
        font-size:12px !important;
        font-weight:900 !important;
        line-height:1 !important;
    }

    .item-card .add-btn,
    .product-card .add-btn{
        width:34px !important;
        height:34px !important;
        min-width:34px !important;
        border-radius:9px !important;
        font-size:22px !important;
        line-height:1 !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
    }
}

/* 1366px laptop safe compact */
@media (min-width:1201px) and (max-width:1450px){

    .items-grid{
        grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)) !important;
        gap:14px !important;
    }

    .item-card,
    .product-card{
        grid-template-rows:minmax(180px, 1fr) 32px 44px !important;
        min-height:256px !important;
    }

    .item-card .item-img,
    .product-card .item-img{
        min-height:180px !important;
    }

    .item-card .item-title,
    .product-card .item-title{
        height:32px !important;
        min-height:32px !important;
        max-height:32px !important;
        padding-right:62px !important;
        font-size:12.5px !important;
    }

    .item-card .item-sku-wrap,
    .product-card .item-sku-wrap{
        bottom:44px !important;
        height:32px !important;
        min-height:32px !important;
    }

    .item-card .item-bottom,
    .product-card .item-bottom{
        height:44px !important;
        min-height:44px !important;
        padding-bottom:10px !important;
    }

    .item-card .item-price,
    .product-card .item-price{
        font-size:19px !important;
    }

    .item-card .add-btn,
    .product-card .add-btn{
        width:32px !important;
        height:32px !important;
        min-width:32px !important;
        font-size:21px !important;
    }
}

/* =========================================================
   FINAL PREMIUM PRODUCT CARD FOOTER UI
   Professional 2-line card content:
   Line 1: Product Name + SKU
   Line 2: Premium Price Pill + Add Button
   Backend / JS / Cart / Shortcut keys untouched
========================================================= */

@media (min-width:1201px){

    .items-grid{
        grid-template-columns:repeat(auto-fill, minmax(225px, 1fr)) !important;
        gap:16px !important;
        align-content:start !important;
        padding-right:8px !important;
    }

    .item-card,
    .product-card{
        position:relative !important;
        display:grid !important;
        grid-template-rows:minmax(178px, 1fr) 42px 52px !important;
        min-height:272px !important;
        padding:0 !important;
        overflow:hidden !important;
        border-radius:14px !important;
        background:
            linear-gradient(180deg, rgba(15,23,42,.95), rgba(8,13,28,.98)) !important;
        border:1px solid rgba(148,163,184,.16) !important;
        box-shadow:
            0 16px 38px rgba(0,0,0,.24),
            inset 0 1px 0 rgba(255,255,255,.04) !important;
        transition:all .18s ease !important;
    }

    .item-card:hover,
    .product-card:hover{
        transform:translateY(-3px) !important;
        border-color:rgba(139,92,246,.75) !important;
        box-shadow:
            0 22px 46px rgba(0,0,0,.34),
            0 0 0 1px rgba(124,58,237,.22),
            0 0 28px rgba(124,58,237,.14) !important;
    }

    /* Bottom premium glass footer */
    .item-card::before,
    .product-card::before{
        content:"" !important;
        position:absolute !important;
        left:0 !important;
        right:0 !important;
        bottom:0 !important;
        height:94px !important;
        background:
            linear-gradient(180deg, rgba(15,23,42,.88), rgba(12,18,34,.98)) !important;
        border-top:1px solid rgba(148,163,184,.10) !important;
        z-index:1 !important;
        pointer-events:none !important;
    }

    /* Image area clean and bigger */
    .item-card .item-img,
    .product-card .item-img{
        grid-row:1 !important;
        width:100% !important;
        height:100% !important;
        min-height:178px !important;
        margin:0 !important;
        border-radius:14px 14px 0 0 !important;
        background:
            radial-gradient(circle at 50% 40%, rgba(255,255,255,.08), transparent 42%),
            #0a0f1d !important;
        overflow:hidden !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        z-index:0 !important;
    }

    .item-card .item-img img,
    .product-card .item-img img{
        width:100% !important;
        height:100% !important;
        max-width:none !important;
        max-height:none !important;
        object-fit:cover !important;
        display:block !important;
        opacity:.96 !important;
        transition:transform .22s ease !important;
    }

    .item-card:hover .item-img img,
    .product-card:hover .item-img img{
        transform:scale(1.035) !important;
    }

    /* Line 1: Name */
    .item-card .item-title,
    .product-card .item-title{
        grid-row:2 !important;
        position:relative !important;
        z-index:2 !important;
        width:100% !important;
        height:42px !important;
        min-height:42px !important;
        max-height:42px !important;
        margin:0 !important;
        padding:10px 66px 0 12px !important;
        display:block !important;
        color:#f8fafc !important;
        font-size:13.5px !important;
        font-weight:950 !important;
        line-height:1.15 !important;
        letter-spacing:.1px !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
        -webkit-line-clamp:unset !important;
        -webkit-box-orient:unset !important;
    }

    /* SKU ko name wali line ke right side premium pill bana do */
    .item-card .item-sku-wrap,
    .product-card .item-sku-wrap{
        position:absolute !important;
        right:12px !important;
        bottom:52px !important;
        z-index:4 !important;
        height:42px !important;
        min-height:42px !important;
        padding:0 !important;
        margin:0 !important;
        display:flex !important;
        align-items:center !important;
        justify-content:flex-end !important;
    }

    .item-card .sku-mini-btn,
    .product-card .sku-mini-btn{
        height:22px !important;
        min-width:38px !important;
        padding:0 8px !important;
        border-radius:999px !important;
        background:rgba(124,58,237,.20) !important;
        border:1px solid rgba(167,139,250,.42) !important;
        color:#ddd6fe !important;
        font-size:9.5px !important;
        font-weight:950 !important;
        line-height:20px !important;
        box-shadow:inset 0 1px 0 rgba(255,255,255,.05) !important;
    }

    .item-card .item-sku-wrap .item-sku,
    .product-card .item-sku-wrap .item-sku,
    .item-card .item-tax-badge,
    .product-card .item-tax-badge{
        display:none !important;
    }

    /* Line 2: Price + Add */
    .item-card .item-bottom,
    .product-card .item-bottom{
        grid-row:3 !important;
        position:relative !important;
        z-index:2 !important;
        height:52px !important;
        min-height:52px !important;
        margin:0 !important;
        padding:0 12px 12px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:space-between !important;
        gap:10px !important;
    }

    /* Price ko premium pill bana do */
    .item-card .item-price,
    .product-card .item-price{
        min-width:88px !important;
        height:36px !important;
        padding:0 12px !important;
        margin:0 !important;
        display:inline-flex !important;
        align-items:center !important;
        justify-content:flex-start !important;
        gap:5px !important;
        border-radius:11px !important;
        background:
            linear-gradient(135deg, rgba(15,23,42,.96), rgba(30,41,59,.72)) !important;
        border:1px solid rgba(148,163,184,.14) !important;
        color:#ffffff !important;
        font-size:20px !important;
        font-weight:950 !important;
        line-height:1 !important;
        letter-spacing:.2px !important;
        white-space:nowrap !important;
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.05),
            0 8px 18px rgba(0,0,0,.18) !important;
    }

    .item-card .item-price small,
    .product-card .item-price small{
        display:inline-flex !important;
        align-items:center !important;
        margin:0 !important;
        color:#a78bfa !important;
        font-size:12px !important;
        font-weight:950 !important;
        line-height:1 !important;
        text-transform:uppercase !important;
    }

    /* Plus button premium square */
    .item-card .add-btn,
    .product-card .add-btn{
        width:38px !important;
        height:38px !important;
        min-width:38px !important;
        border-radius:12px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        padding:0 !important;
        margin:0 !important;
        color:#fff !important;
        font-size:24px !important;
        font-weight:900 !important;
        line-height:1 !important;
        background:
            linear-gradient(135deg, #a855f7 0%, #7c3aed 58%, #5b21b6 100%) !important;
        border:1px solid rgba(192,132,252,.70) !important;
        box-shadow:
            0 12px 24px rgba(124,58,237,.36),
            inset 0 1px 0 rgba(255,255,255,.16) !important;
        transition:all .18s ease !important;
    }

    .item-card .add-btn:hover,
    .product-card .add-btn:hover{
        transform:translateY(-1px) scale(1.04) !important;
        box-shadow:
            0 16px 30px rgba(124,58,237,.48),
            inset 0 1px 0 rgba(255,255,255,.20) !important;
    }
}

/* 1366 laptop safe */
@media (min-width:1201px) and (max-width:1450px){

    .items-grid{
        grid-template-columns:repeat(auto-fill, minmax(215px, 1fr)) !important;
        gap:14px !important;
    }

    .item-card,
    .product-card{
        grid-template-rows:minmax(168px, 1fr) 40px 50px !important;
        min-height:258px !important;
    }

    .item-card::before,
    .product-card::before{
        height:90px !important;
    }

    .item-card .item-img,
    .product-card .item-img{
        min-height:168px !important;
    }

    .item-card .item-title,
    .product-card .item-title{
        height:40px !important;
        min-height:40px !important;
        max-height:40px !important;
        padding:9px 64px 0 12px !important;
        font-size:13px !important;
    }

    .item-card .item-sku-wrap,
    .product-card .item-sku-wrap{
        bottom:50px !important;
        height:40px !important;
        min-height:40px !important;
    }

    .item-card .item-bottom,
    .product-card .item-bottom{
        height:50px !important;
        min-height:50px !important;
        padding-bottom:11px !important;
    }

    .item-card .item-price,
    .product-card .item-price{
        min-width:82px !important;
        height:34px !important;
        font-size:19px !important;
        padding:0 11px !important;
    }

    .item-card .item-price small,
    .product-card .item-price small{
        font-size:11.5px !important;
    }

    .item-card .add-btn,
    .product-card .add-btn{
        width:36px !important;
        height:36px !important;
        min-width:36px !important;
        font-size:23px !important;
    }
}

/* PRODUCT SEARCH VISIBILITY FIX */
.product-card.pos-search-hidden,
.item-card.pos-search-hidden{
    display:none !important;
}

/* =========================================================
   PRODUCT SEARCH — HIGH VISIBILITY + BARCODE ICON
   UI only. Existing search/scanner/backend unchanged.
========================================================= */

.search-wrap.pos-search-highlight{
    width:100% !important;
    min-width:0 !important;
    margin:0 !important;
    padding:7px !important;

    border:1px solid rgba(139,92,246,.75) !important;
    border-radius:12px !important;

    background:
        radial-gradient(
            circle at left center,
            rgba(124,58,237,.22),
            transparent 45%
        ),
        linear-gradient(
            135deg,
            rgba(124,58,237,.14),
            rgba(6,11,22,.98) 60%
        ) !important;

    box-shadow:
        0 0 0 1px rgba(124,58,237,.12),
        0 10px 28px rgba(0,0,0,.25),
        0 0 24px rgba(124,58,237,.10) !important;

    transition:border-color .18s ease, box-shadow .18s ease !important;
}

.search-wrap.pos-search-highlight:focus-within{
    border-color:#a78bfa !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.18),
        0 14px 34px rgba(0,0,0,.30),
        0 0 28px rgba(124,58,237,.18) !important;
}

/* Search title */
.pos-search-caption{
    min-height:16px !important;
    margin:0 3px 5px !important;

    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:10px !important;
}

.pos-search-caption > span{
    color:#c4b5fd !important;
    font-size:10.5px !important;
    line-height:1 !important;
    font-weight:950 !important;
    letter-spacing:.9px !important;
}

.pos-search-caption > small{
    color:#94a3b8 !important;
    font-size:10px !important;
    line-height:1 !important;
    font-weight:650 !important;
}

/* Input + scan button row */
.pos-search-controls{
    width:100% !important;
    min-width:0 !important;

    display:grid !important;
    grid-template-columns:minmax(0,1fr) auto !important;
    align-items:center !important;
    gap:7px !important;

    position:relative !important;
}

/* Search icon inside input */
.pos-search-leading-icon{
    position:absolute !important;
    left:13px !important;
    top:50% !important;
    transform:translateY(-50%) !important;

    width:19px !important;
    height:19px !important;

    display:flex !important;
    align-items:center !important;
    justify-content:center !important;

    color:#a78bfa !important;
    pointer-events:none !important;
    z-index:3 !important;
}

.pos-search-leading-icon svg{
    fill:none !important;
    stroke:currentColor !important;
    stroke-width:2 !important;
    stroke-linecap:round !important;
    stroke-linejoin:round !important;
}

/* More visible product search input */
#productSearch.pos-visible-search-input{
    width:100% !important;
    min-width:0 !important;
    height:42px !important;

    padding:0 13px 0 41px !important;

    border:1px solid rgba(167,139,250,.38) !important;
    border-radius:9px !important;

    background:#050a15 !important;
    color:#f8fafc !important;

    font-size:13px !important;
    font-weight:700 !important;

    outline:none !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.03) !important;
}

#productSearch.pos-visible-search-input::placeholder{
    color:#a5afc2 !important;
    opacity:1 !important;
}

#productSearch.pos-visible-search-input:focus{
    border-color:#8b5cf6 !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

/* Barcode scan button */
.pos-search-scan-btn{
    height:42px !important;
    min-width:94px !important;
    padding:0 10px !important;

    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;

    border:1px solid rgba(167,139,250,.65) !important;
    border-radius:9px !important;

    background:
        linear-gradient(
            135deg,
            #9f67ff,
            #7c3aed 58%,
            #5b21b6
        ) !important;

    color:#fff !important;

    font-size:11px !important;
    font-weight:900 !important;

    cursor:pointer !important;

    box-shadow:
        0 9px 20px rgba(124,58,237,.28),
        inset 0 1px 0 rgba(255,255,255,.16) !important;

    transition:
        transform .18s ease,
        box-shadow .18s ease,
        border-color .18s ease !important;
}

.pos-search-scan-btn:hover{
    transform:translateY(-1px) !important;
    border-color:#c4b5fd !important;

    box-shadow:
        0 13px 26px rgba(124,58,237,.38),
        inset 0 1px 0 rgba(255,255,255,.18) !important;
}

.pos-search-scan-btn:active{
    transform:translateY(0) !important;
}

.pos-search-scan-btn svg{
    fill:none !important;
    stroke:currentColor !important;
    stroke-width:1.8 !important;
    stroke-linecap:round !important;
}

.pos-search-scan-btn kbd{
    min-width:25px !important;
    height:19px !important;
    padding:0 4px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border:1px solid rgba(255,255,255,.28) !important;
    border-radius:5px !important;

    background:rgba(5,8,22,.32) !important;
    color:#fff !important;

    font-size:9px !important;
    line-height:1 !important;
    font-family:inherit !important;
    font-weight:900 !important;

    box-shadow:none !important;
}

/* Mobile: icon button compact rahega */
@media (max-width:767.98px){

    .search-wrap.pos-search-highlight{
        padding:6px !important;
        border-radius:12px !important;
    }

    .pos-search-caption{
        margin-bottom:5px !important;
    }

    .pos-search-caption > small{
        display:none !important;
    }

    .pos-search-controls{
        grid-template-columns:minmax(0,1fr) 44px !important;
        gap:6px !important;
    }

    #productSearch.pos-visible-search-input{
        height:42px !important;
        padding-left:38px !important;
        font-size:12px !important;
    }

    .pos-search-leading-icon{
        left:12px !important;
    }

    .pos-search-scan-btn{
        width:44px !important;
        min-width:44px !important;
        height:42px !important;
        padding:0 !important;
    }

    .pos-search-scan-btn > span,
    .pos-search-scan-btn > kbd{
        display:none !important;
    }
}


/* =========================================================
   ONEERP POS LIGHT / DARK THEME
   Add-only override. Backend and POS functionality unchanged.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

html[data-pos-theme="dark"]{
    color-scheme:dark;
}

/* Theme toggle button */
.pos-theme-toggle{
    position:relative !important;
    overflow:hidden !important;
    font-size:18px !important;
}

.pos-theme-toggle span{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    line-height:1 !important;
    pointer-events:none !important;
}

/* =========================================================
   LIGHT MODE: PAGE BACKGROUND
========================================================= */

html[data-pos-theme="light"] body,
html[data-pos-theme="light"] .container-scroll,
html[data-pos-theme="light"] .main-panel,
html[data-pos-theme="light"] .content-wrapper,
html[data-pos-theme="light"] .pos-layout{
    background:#f3f5fb !important;
    color:#172033 !important;
}

html[data-pos-theme="light"] .topbar{
    /*background:#ffffff !important;*/
    border-bottom:1px solid #e2e8f0 !important;
    box-shadow:0 4px 18px rgba(15,23,42,.06) !important;
    
        background: #f3f6fb !important;
}

/* =========================================================
   LIGHT MODE: TOPBAR
========================================================= */

/*html[data-pos-theme="light"] .topbar,*/
/*html[data-pos-theme="light"] .topbar-wrap{*/
/*    background:#ffffff !important;*/
/*    color:#172033 !important;*/
/*    border-color:#e2e7f0 !important;*/
/*}*/

/*html[data-pos-theme="light"] .topbar{*/
/*    box-shadow:0 4px 18px rgba(30,41,59,.06) !important;*/
/*}*/

/*html[data-pos-theme="light"] .topbar input,*/
/*html[data-pos-theme="light"] .topbar select{*/
/*    background:#f7f8fc !important;*/
/*    color:#172033 !important;*/
/*    border-color:#dce2ec !important;*/
/*}*/

/*html[data-pos-theme="light"] .topbar input::placeholder{*/
/*    color:#7b879c !important;*/
/*}*/

/*html[data-pos-theme="light"] .user-pill,*/
/*html[data-pos-theme="light"] .icon-btn,*/
/*html[data-pos-theme="light"] .pos-theme-toggle{*/
/*    background:#ffffff !important;*/
/*    color:#334155 !important;*/
/*    border-color:#dce2ec !important;*/
/*    box-shadow:0 4px 12px rgba(30,41,59,.06) !important;*/
/*}*/

/*html[data-pos-theme="light"] .icon-btn:hover,*/
/*html[data-pos-theme="light"] .pos-theme-toggle:hover{*/
/*    background:#7c3aed !important;*/
/*    color:#ffffff !important;*/
/*    border-color:#7c3aed !important;*/
/*}*/



/* =========================================================
   LIGHT MODE: MAIN PANELS
========================================================= */

html[data-pos-theme="light"] .items-area,
html[data-pos-theme="light"] .cart-area,
html[data-pos-theme="light"] .pos-premium-cart{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#dfe5ef !important;
    box-shadow:0 12px 32px rgba(30,41,59,.08) !important;
}

html[data-pos-theme="light"] .pos-premium-cart{
    background:
        radial-gradient(
            circle at top right,
            rgba(124,58,237,.08),
            transparent 32%
        ),
        #ffffff !important;
}

/* =========================================================
   LIGHT MODE: RETAILER / CATEGORY BUTTONS
========================================================= */

html[data-pos-theme="light"] .type-btn,
html[data-pos-theme="light"] .category-btn,
html[data-pos-theme="light"] .chip{
    background:#f7f8fc !important;
    color:#475569 !important;
    border-color:#dce2ec !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] .type-btn.active,
html[data-pos-theme="light"] .type-btn:hover,
html[data-pos-theme="light"] .category-btn.active,
html[data-pos-theme="light"] .category-btn:hover{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
    box-shadow:0 10px 22px rgba(124,58,237,.20) !important;
}

/* =========================================================
   LIGHT MODE: PRODUCT SEARCH
========================================================= */

html[data-pos-theme="light"] .search-wrap.pos-search-highlight{
    background:
        radial-gradient(
            circle at left center,
            rgba(124,58,237,.09),
            transparent 48%
        ),
        #faf9ff !important;
    border-color:#bfa9f4 !important;
    box-shadow:
        0 0 0 1px rgba(124,58,237,.06),
        0 8px 20px rgba(30,41,59,.06) !important;
}

html[data-pos-theme="light"] .pos-search-caption > span{
    color:#6d28d9 !important;
}

html[data-pos-theme="light"] .pos-search-caption > small{
    color:#64748b !important;
}

html[data-pos-theme="light"] #productSearch.pos-visible-search-input,
html[data-pos-theme="light"] .search-input,
html[data-pos-theme="light"] .cart-search,
html[data-pos-theme="light"] .field-dark{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#d5dce8 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] #productSearch.pos-visible-search-input::placeholder,
html[data-pos-theme="light"] .search-input::placeholder,
html[data-pos-theme="light"] .cart-search::placeholder,
html[data-pos-theme="light"] .field-dark::placeholder{
    color:#8490a3 !important;
}

html[data-pos-theme="light"] #productSearch.pos-visible-search-input:focus,
html[data-pos-theme="light"] .search-input:focus,
html[data-pos-theme="light"] .field-dark:focus{
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

/* =========================================================
   LIGHT MODE: PRODUCT CARDS
========================================================= */

html[data-pos-theme="light"] .item-card,
html[data-pos-theme="light"] .product-card{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#dfe5ef !important;
    box-shadow:0 8px 22px rgba(30,41,59,.07) !important;
}

html[data-pos-theme="light"] .item-card:hover,
html[data-pos-theme="light"] .product-card:hover{
    border-color:#9f67ff !important;
    box-shadow:
        0 14px 30px rgba(30,41,59,.10),
        0 0 0 1px rgba(124,58,237,.12) !important;
}

html[data-pos-theme="light"] .item-card::before,
html[data-pos-theme="light"] .product-card::before{
    background:linear-gradient(
        180deg,
        rgba(255,255,255,0),
        rgba(255,255,255,.12)
    ) !important;
}

html[data-pos-theme="light"] .item-img,
html[data-pos-theme="light"] .item-card .item-img,
html[data-pos-theme="light"] .product-card .item-img{
    background:#f6f7fb !important;
}

html[data-pos-theme="light"] .item-title,
html[data-pos-theme="light"] .item-card .item-title,
html[data-pos-theme="light"] .product-card .item-title{
    color:#172033 !important;
}

html[data-pos-theme="light"] .item-sku{
    color:#718096 !important;
}

html[data-pos-theme="light"] .sku-mini-btn{
    background:#f1ebff !important;
    color:#6d28d9 !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] .sku-mini-btn:hover,
html[data-pos-theme="light"] .item-sku-wrap.sku-open .sku-mini-btn{
    background:#7c3aed !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

html[data-pos-theme="light"] .item-price,
html[data-pos-theme="light"] .item-card .item-price,
html[data-pos-theme="light"] .product-card .item-price{
    background:#f4f0ff !important;
    color:#172033 !important;
    border:1px solid #ded2ff !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] .item-price small,
html[data-pos-theme="light"] .item-card .item-price small,
html[data-pos-theme="light"] .product-card .item-price small{
    color:#6d28d9 !important;
}

/* Purple action buttons remain branded */
html[data-pos-theme="light"] .add-btn,
html[data-pos-theme="light"] .pos-search-scan-btn,
html[data-pos-theme="light"] .inline-custom-btn,
html[data-pos-theme="light"] .pos-custom-add-btn,
html[data-pos-theme="light"] .invoice-btn,
html[data-pos-theme="light"] .pos-proceed-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
}

/* =========================================================
   LIGHT MODE: CART HEADER AND TABLE
========================================================= */

html[data-pos-theme="light"] .pos-cart-customer-header,
html[data-pos-theme="light"] .ref-cart-head,
html[data-pos-theme="light"] .pos-order-items-top,
html[data-pos-theme="light"] .ref-order-head{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#e2e7f0 !important;
}

html[data-pos-theme="light"] .pos-cart-customer-info h4,
html[data-pos-theme="light"] .ref-cart-customer,
html[data-pos-theme="light"] .pos-order-items-title,
html[data-pos-theme="light"] .ref-order-title{
    color:#172033 !important;
}

html[data-pos-theme="light"] .pos-cart-customer-info small{
    color:#718096 !important;
}

html[data-pos-theme="light"] .pos-cart-change-btn,
html[data-pos-theme="light"] .ref-cart-change-btn{
    background:#f7f8fc !important;
    color:#475569 !important;
    border-color:#dce2ec !important;
}

html[data-pos-theme="light"] .pos-cart-phone-input,
html[data-pos-theme="light"] .ref-phone-input{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#d5dce8 !important;
}

html[data-pos-theme="light"] .pos-cart-table-head,
html[data-pos-theme="light"] .ref-cart-table-head{
    background:#f3f0fb !important;
    color:#475569 !important;
    border-color:#e1d8f6 !important;
}

html[data-pos-theme="light"] .cart-items,
html[data-pos-theme="light"] .pos-cart-items-list{
    background:#ffffff !important;
    color:#172033 !important;
}

/* =========================================================
   LIGHT MODE: CART ROWS
========================================================= */

html[data-pos-theme="light"] .pos-cart-item,
html[data-pos-theme="light"] .pos-cart-item.pos-ref-row,
html[data-pos-theme="light"] .pos-cart-item.ref-row-ready{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#e7eaf0 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] .pos-cart-title,
html[data-pos-theme="light"] .pos-premium-cart .pos-cart-title{
    color:#172033 !important;
}

html[data-pos-theme="light"] .pos-cart-sku,
html[data-pos-theme="light"] .pos-cart-badge{
    color:#718096 !important;
}

html[data-pos-theme="light"] .pos-cart-price,
html[data-pos-theme="light"] .pos-cart-qty,
html[data-pos-theme="light"] .pos-premium-cart select,
html[data-pos-theme="light"] .pos-premium-cart input{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#d8deea !important;
}

html[data-pos-theme="light"] .pos-premium-cart select option{
    background:#ffffff !important;
    color:#172033 !important;
}

html[data-pos-theme="light"] .pos-ref-price-cell::before,
html[data-pos-theme="light"] .ref-price-cell::before{
    color:#172033 !important;
}

html[data-pos-theme="light"] .pos-cart-total,
html[data-pos-theme="light"] .pos-premium-cart [data-line-total]{
    color:#172033 !important;
}

html[data-pos-theme="light"] .pos-cart-btn{
    background:#f3f0fb !important;
    color:#6d28d9 !important;
    border-color:#ddd3f4 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] .pos-cart-btn:hover{
    background:#7c3aed !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

html[data-pos-theme="light"] .pos-ref-remove-cell .pos-cart-btn,
html[data-pos-theme="light"] .ref-remove-cell .pos-cart-btn,
html[data-pos-theme="light"] .pos-premium-cart [data-cart-remove]{
    background:#fff5f5 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

/* =========================================================
   LIGHT MODE: EMPTY CART AND ADD ITEM
========================================================= */

html[data-pos-theme="light"] .empty-state,
html[data-pos-theme="light"] .pos-cart-empty{
    background:#f8f9fc !important;
    color:#718096 !important;
    border-color:#d5dce8 !important;
}

html[data-pos-theme="light"] .pos-add-custom-main,
html[data-pos-theme="light"] .custom-product-title{
    background:#f8f9fc !important;
    color:#475569 !important;
    border-color:#cfd7e4 !important;
}

html[data-pos-theme="light"] .pos-add-custom-main:hover{
    background:#f3efff !important;
    color:#6d28d9 !important;
    border-color:#9f67ff !important;
}

html[data-pos-theme="light"] .pos-add-custom-icon,
html[data-pos-theme="light"] .custom-product-title .plus{
    background:#ede9fe !important;
    color:#6d28d9 !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] .pos-custom-product-form,
html[data-pos-theme="light"] .inline-custom-product{
    background:#ffffff !important;
    border-color:#dce2ec !important;
}

html[data-pos-theme="light"] .pos-custom-input,
html[data-pos-theme="light"] .inline-custom-input{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#e2e7f0 !important;
}

html[data-pos-theme="light"] .pos-custom-input::placeholder,
html[data-pos-theme="light"] .inline-custom-input::placeholder{
    color:#8490a3 !important;
}

/* =========================================================
   LIGHT MODE: BILLING SUMMARY
========================================================= */

html[data-pos-theme="light"] .summary,
html[data-pos-theme="light"] .pos-billing-summary{
    background:#ffffff !important;
    border-color:#e2e7f0 !important;
}

html[data-pos-theme="light"] .summary-row,
html[data-pos-theme="light"] .pos-summary-row,
html[data-pos-theme="light"] .pos-summary-row span{
    color:#475569 !important;
}

html[data-pos-theme="light"] .summary-row strong,
html[data-pos-theme="light"] .pos-summary-row strong{
    color:#172033 !important;
}

html[data-pos-theme="light"] .summary-row.total,
html[data-pos-theme="light"] .pos-grand-total-row,
html[data-pos-theme="light"] .pos-grand-total-row span{
    color:#172033 !important;
    border-color:#e2e7f0 !important;
}

html[data-pos-theme="light"] .summary-row.total span:last-child,
html[data-pos-theme="light"] .pos-grand-total-row strong{
    color:#7c3aed !important;
}

html[data-pos-theme="light"] .pos-discount-inline,
html[data-pos-theme="light"] .pos-hidden-discount{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#dce2ec !important;
}

/* =========================================================
   LIGHT MODE: PAYMENT BUTTONS
========================================================= */

html[data-pos-theme="light"] .pay-btn,
html[data-pos-theme="light"] .pos-payment-methods .pay-btn{
    background:#ffffff !important;
    color:#475569 !important;
    border-color:#dce2ec !important;
    box-shadow:0 4px 10px rgba(30,41,59,.04) !important;
}

html[data-pos-theme="light"] .pay-btn.active,
html[data-pos-theme="light"] .pay-btn:hover,
html[data-pos-theme="light"] .pos-payment-methods .pay-btn.active,
html[data-pos-theme="light"] .pos-payment-methods .pay-btn:hover{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
    box-shadow:0 10px 22px rgba(124,58,237,.20) !important;
}

/* =========================================================
   LIGHT MODE: MODALS
========================================================= */

html[data-pos-theme="light"] .modal-content,
html[data-pos-theme="light"] .tier-modal-card{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#dce2ec !important;
}

html[data-pos-theme="light"] .modal-header,
html[data-pos-theme="light"] .modal-footer{
    border-color:#e2e7f0 !important;
}

html[data-pos-theme="light"] .modal-title,
html[data-pos-theme="light"] .modal label{
    color:#172033 !important;
}

html[data-pos-theme="light"] .modal input,
html[data-pos-theme="light"] .modal select,
html[data-pos-theme="light"] .modal textarea{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#d5dce8 !important;
}

html[data-pos-theme="light"] .tier-option-btn{
    background:#ffffff !important;
    color:#172033 !important;
    border-color:#dce2ec !important;
}

html[data-pos-theme="light"] .tier-option-btn:hover{
    background:#7c3aed !important;
    color:#ffffff !important;
}

/* Scrollbars */
html[data-pos-theme="light"] .items-grid::-webkit-scrollbar-thumb,
html[data-pos-theme="light"] .cart-items::-webkit-scrollbar-thumb,
html[data-pos-theme="light"] .pos-cart-items-list::-webkit-scrollbar-thumb{
    background:#b9a4ee !important;
}



/* =========================================================
   LIGHT MODE TEXT CONTRAST FIX
   Sirf light mode mein fonts dark honge.
   Dark mode aur backend/functionality unchanged.
========================================================= */

/* Main readable text */
html[data-pos-theme="light"] body,
html[data-pos-theme="light"] .items-area,
html[data-pos-theme="light"] .cart-area,
html[data-pos-theme="light"] .pos-premium-cart{
    color:#0f172a !important;
}

/* Product names */
html[data-pos-theme="light"] .item-title,
html[data-pos-theme="light"] .product-card .item-title,
html[data-pos-theme="light"] .item-card .item-title{
    color:#0f172a !important;
    opacity:1 !important;
}

/* Product prices */
html[data-pos-theme="light"] .item-price,
html[data-pos-theme="light"] .item-price strong,
html[data-pos-theme="light"] .item-bottom{
    color:#111827 !important;
}

/* SKU and secondary product text */
html[data-pos-theme="light"] .item-sku,
html[data-pos-theme="light"] .pos-cart-sku,
html[data-pos-theme="light"] .pos-cart-badge,
html[data-pos-theme="light"] .pos-cart-tier-badge{
    color:#475569 !important;
    opacity:1 !important;
}

/* Search heading and helper text */
html[data-pos-theme="light"] .pos-search-caption,
html[data-pos-theme="light"] .pos-search-caption span{
    color:#4c1d95 !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .pos-search-caption small{
    color:#475569 !important;
    opacity:1 !important;
}

/* Search and form inputs */
html[data-pos-theme="light"] input,
html[data-pos-theme="light"] select,
html[data-pos-theme="light"] textarea,
html[data-pos-theme="light"] .search-input,
html[data-pos-theme="light"] .header-search-input,
html[data-pos-theme="light"] .pos-custom-input,
html[data-pos-theme="light"] .inline-custom-input,
html[data-pos-theme="light"] .pos-cart-price,
html[data-pos-theme="light"] .pos-cart-qty,
html[data-pos-theme="light"] .pos-hidden-discount{
    color:#0f172a !important;
    opacity:1 !important;
}

/* Input placeholders */
html[data-pos-theme="light"] input::placeholder,
html[data-pos-theme="light"] textarea::placeholder,
html[data-pos-theme="light"] .search-input::placeholder,
html[data-pos-theme="light"] .header-search-input::placeholder,
html[data-pos-theme="light"] .pos-custom-input::placeholder,
html[data-pos-theme="light"] .inline-custom-input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

/* Cart headings and product names */
html[data-pos-theme="light"] .pos-cart-title,
html[data-pos-theme="light"] .pos-ref-product-text .pos-cart-title,
html[data-pos-theme="light"] .ref-product-text .pos-cart-title,
html[data-pos-theme="light"] .pos-cart-customer-info h4,
html[data-pos-theme="light"] .pos-order-items-title,
html[data-pos-theme="light"] .ref-order-title,
html[data-pos-theme="light"] .ref-cart-customer{
    color:#0f172a !important;
    opacity:1 !important;
}

/* Cart table headings */
html[data-pos-theme="light"] .pos-cart-table-head,
html[data-pos-theme="light"] .pos-cart-table-head span,
html[data-pos-theme="light"] .ref-cart-table-head,
html[data-pos-theme="light"] .ref-cart-table-head span{
    color:#334155 !important;
    opacity:1 !important;
}

/* Cart price, quantity and totals */
html[data-pos-theme="light"] .pos-cart-price,
html[data-pos-theme="light"] .pos-cart-qty,
html[data-pos-theme="light"] .pos-cart-total,
html[data-pos-theme="light"] [data-line-total],
html[data-pos-theme="light"] .pos-ref-price-cell::before,
html[data-pos-theme="light"] .ref-price-cell::before{
    color:#0f172a !important;
    opacity:1 !important;
}

/* Billing summary */
html[data-pos-theme="light"] .summary-row,
html[data-pos-theme="light"] .summary-row span,
html[data-pos-theme="light"] .pos-summary-row,
html[data-pos-theme="light"] .pos-summary-row span{
    color:#334155 !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .summary-row strong,
html[data-pos-theme="light"] .pos-summary-row strong,
html[data-pos-theme="light"] .summary-row.total,
html[data-pos-theme="light"] .pos-grand-total-row span{
    color:#0f172a !important;
    opacity:1 !important;
}

/* Customer secondary information */
html[data-pos-theme="light"] .pos-cart-customer-info small,
html[data-pos-theme="light"] .customer-box,
html[data-pos-theme="light"] .customer-box small,
html[data-pos-theme="light"] .muted,
html[data-pos-theme="light"] small.muted{
    color:#475569 !important;
    opacity:1 !important;
}

/* Empty cart */
html[data-pos-theme="light"] .empty-state,
html[data-pos-theme="light"] .pos-cart-empty{
    color:#475569 !important;
    opacity:1 !important;
}

/* Normal unselected buttons */
html[data-pos-theme="light"] .type-btn:not(.active),
html[data-pos-theme="light"] .category-btn:not(.active),
html[data-pos-theme="light"] .chip,
html[data-pos-theme="light"] .pay-btn:not(.active),
html[data-pos-theme="light"] .pos-payment-methods .pay-btn:not(.active),
html[data-pos-theme="light"] .pos-add-custom-main,
html[data-pos-theme="light"] .pos-cart-change-btn{
    color:#1e293b !important;
    opacity:1 !important;
}


html[data-pos-theme="light"] .navbar-dropdown .text-muted{
    color:#475569 !important;
}

/* Search suggestions */
html[data-pos-theme="light"] .search-suggestion-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] .search-suggestion-keywords,
html[data-pos-theme="light"] .search-empty{
    color:#475569 !important;
}

/* Keep purple/active buttons text white */
html[data-pos-theme="light"] .type-btn.active,
html[data-pos-theme="light"] .category-btn.active,
html[data-pos-theme="light"] .pay-btn.active,
html[data-pos-theme="light"] .pay-btn:hover,
html[data-pos-theme="light"] .add-btn,
html[data-pos-theme="light"] .pos-search-scan-btn,
html[data-pos-theme="light"] .pos-custom-add-btn,
html[data-pos-theme="light"] .inline-custom-btn,
html[data-pos-theme="light"] .invoice-btn,
html[data-pos-theme="light"] .pos-proceed-btn,
html[data-pos-theme="light"] .pos-discount-pill{
    color:#ffffff !important;
}

/* =========================================================
   FINAL LIGHT MODE CART TEXT FIX
   EXACT CURRENT POS CLASSES
========================================================= */

/* Invoice # and Date & Time labels */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-cart-invoice-left span,

html[data-pos-theme="light"] body
.pos-premium-cart .pos-cart-invoice-right span{
    color:#475569 !important;
    opacity:1 !important;
}

/* Invoice number */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-cart-invoice-left strong,

html[data-pos-theme="light"] body
.pos-premium-cart #cartPreviewInvoiceNo{
    color:#6d28d9 !important;
    opacity:1 !important;
}

/* Date and time value */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-cart-invoice-right strong,

html[data-pos-theme="light"] body
.pos-premium-cart #cartPreviewDateTime{
    color:#0f172a !important;
    opacity:1 !important;
}


/* Add Item button */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-add-inline-btn,

html[data-pos-theme="light"] body
.pos-premium-cart .pos-add-custom-main.pos-ref-add-inline-btn{
    background:#faf8ff !important;
    color:#5b21b6 !important;
    border-color:#8b5cf6 !important;
}

/* Add Item button text and plus icon */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-add-inline-btn span,

html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-add-inline-btn .pos-add-custom-icon{
    color:#5b21b6 !important;
    opacity:1 !important;
}


/* Summary main container */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-summary-inline{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Subtotal, Discount and Tax labels */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-summary-inline .pos-summary-row > span{
    color:#0f172a !important;
    opacity:1 !important;
    text-shadow:none !important;
}

/* Subtotal and Tax values */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-summary-inline .pos-summary-row > strong,

html[data-pos-theme="light"] body
.pos-premium-cart #sumSubtotal,

html[data-pos-theme="light"] body
.pos-premium-cart #sumTax{
    color:#0f172a !important;
    opacity:1 !important;
    text-shadow:none !important;
}


/* Discount input complete box */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-summary-inline .pos-ref-discount-box{
    background:#ffffff !important;
    border-color:#94a3b8 !important;
}

/* Discount number */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-summary-inline #discount,

html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-summary-inline .pos-hidden-discount{
    background:#ffffff !important;
    color:#0f172a !important;
    opacity:1 !important;
}

/* Discount percentage */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-summary-inline .pos-ref-percent-pill{
    background:#f1f5f9 !important;
    color:#0f172a !important;
    border-left-color:#94a3b8 !important;
    opacity:1 !important;
}


/* Total Payable row */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-payment-title.pos-payment-total-title{
    color:#0f172a !important;
    border-top-color:#cbd5e1 !important;
    border-bottom-color:#cbd5e1 !important;
}

/* Total Payable label */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-payment-title.pos-payment-total-title > span{
    color:#5b21b6 !important;
    opacity:1 !important;
    text-shadow:none !important;
}

/* Total Payable amount */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-payment-title.pos-payment-total-title > strong,

html[data-pos-theme="light"] body
.pos-premium-cart #sumGrand{
    color:#7c3aed !important;
    opacity:1 !important;
    text-shadow:none !important;
}


/* =====================================================
   PRODUCT UNIT SELECTION MODAL
===================================================== */

.product-unit-modal-card{
    background:
        radial-gradient(
            circle at top right,
            rgba(124,58,237,.18),
            transparent 38%
        ),
        linear-gradient(
            180deg,
            #10172a 0%,
            #090f1d 100%
        );

    color:#ffffff;
    border:1px solid rgba(139,92,246,.30);
    border-radius:18px;
    overflow:hidden;

    box-shadow:
        0 28px 80px rgba(0,0,0,.50),
        inset 0 1px 0 rgba(255,255,255,.04);
}

.product-unit-modal-card .modal-header{
    padding:18px 18px 10px;
}

.product-unit-modal-card .modal-title{
    margin:0;
    color:#ffffff;
    font-size:18px;
    font-weight:900;
}

.product-unit-modal-subtitle{
    display:block;
    margin-top:4px;
    color:#94a3b8;
    font-size:12px;
    font-weight:600;
}

.product-unit-option{
    width:100%;
    min-height:64px;
    margin-bottom:10px;
    padding:12px 14px;

    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;

    background:#070d19;
    color:#f8fafc;

    border:1px solid rgba(148,163,184,.14);
    border-radius:12px;

    cursor:pointer;
    text-align:left;
    transition:all .18s ease;
}

.product-unit-option:last-child{
    margin-bottom:0;
}

.product-unit-option:hover{
    transform:translateY(-1px);

    background:
        linear-gradient(
            135deg,
            rgba(124,58,237,.30),
            rgba(109,40,217,.18)
        );

    border-color:rgba(139,92,246,.70);

    box-shadow:
        0 12px 30px rgba(124,58,237,.18);
}

.product-unit-option-left{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:4px;
}

.product-unit-option-name{
    color:#ffffff;
    font-size:15px;
    font-weight:900;
}

.product-unit-option-conversion{
    color:#94a3b8;
    font-size:11px;
    font-weight:600;
}

.product-unit-option-price{
    flex-shrink:0;

    padding:7px 10px;
    border-radius:9px;

    background:rgba(124,58,237,.16);
    color:#c4b5fd;

    border:1px solid rgba(124,58,237,.28);

    font-size:14px;
    font-weight:900;
}

.product-unit-option-right{
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:8px;
}

.product-unit-option-add{
    width:34px;
    height:34px;
    min-width:34px;

    display:flex;
    align-items:center;
    justify-content:center;

    border-radius:9px;

    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    );

    color:#ffffff;
    border:1px solid rgba(167,139,250,.70);

    font-size:22px;
    line-height:1;
    font-weight:900;

    box-shadow:
        0 8px 18px rgba(124,58,237,.28);

    transition:.18s ease;
}

.product-unit-option:hover
.product-unit-option-add{
    transform:scale(1.06);
}

 /* =====================================================
   UNIT MODAL FINAL HOVER + LIGHT MODE FIX
===================================================== */

/* Dark mode/default unit row */
.product-unit-option{
    background:#070d19 !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.14) !important;
    box-shadow:none !important;
    outline:none !important;
}

.product-unit-option:hover{
    transform:translateY(-1px) !important;

    background:linear-gradient(
        135deg,
        rgba(124,58,237,.18),
        rgba(109,40,217,.10)
    ) !important;

    color:#ffffff !important;
    border-color:rgba(139,92,246,.55) !important;

    box-shadow:
        0 8px 22px rgba(124,58,237,.14) !important;
}

.product-unit-option:active{
    transform:scale(.985) !important;
}

/* Hover par text visible rahe */
.product-unit-option:hover
.product-unit-option-name{
    color:#ffffff !important;
}

.product-unit-option:hover
.product-unit-option-conversion{
    color:#cbd5e1 !important;
}

.product-unit-option:hover
.product-unit-option-price{
    background:rgba(124,58,237,.20) !important;
    color:#ddd6fe !important;
    border-color:rgba(167,139,250,.38) !important;
}

/* Plus normal state */
.product-unit-option-add{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;

    color:#ffffff !important;
    border:1px solid rgba(167,139,250,.65) !important;

    box-shadow:
        0 7px 16px rgba(124,58,237,.24) !important;
}

/* Plus hover */
.product-unit-option:hover
.product-unit-option-add{
    transform:scale(1.04) !important;

    background:linear-gradient(
        135deg,
        #7c3aed,
        #5b21b6
    ) !important;

    color:#ffffff !important;
}


/* =====================================================
   LIGHT MODE UNIT MODAL
===================================================== */

html[data-pos-theme="light"]
.product-unit-modal-card{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #dbe3ef !important;

    box-shadow:
        0 24px 60px rgba(15,23,42,.18) !important;
}

html[data-pos-theme="light"]
.product-unit-modal-card .modal-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"]
.product-unit-modal-subtitle{
    color:#64748b !important;
}

/* Light mode normal row */
html[data-pos-theme="light"]
.product-unit-option{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #dbe3ef !important;
    box-shadow:none !important;
}

/* Light mode hover */
html[data-pos-theme="light"]
.product-unit-option:hover{
    background:#f5f3ff !important;
    color:#0f172a !important;

    border-color:#8b5cf6 !important;

    box-shadow:
        0 8px 20px rgba(124,58,237,.12) !important;
}

/* Light mode text */
html[data-pos-theme="light"]
.product-unit-option-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"]
.product-unit-option-conversion{
    color:#64748b !important;
}

html[data-pos-theme="light"]
.product-unit-option:hover
.product-unit-option-name{
    color:#312e81 !important;
}

html[data-pos-theme="light"]
.product-unit-option:hover
.product-unit-option-conversion{
    color:#64748b !important;
}

/* Light mode price */
html[data-pos-theme="light"]
.product-unit-option-price{
    background:#f3e8ff !important;
    color:#6d28d9 !important;
    border:1px solid #ddd6fe !important;
}

html[data-pos-theme="light"]
.product-unit-option:hover
.product-unit-option-price{
    background:#ede9fe !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Light mode plus */
html[data-pos-theme="light"]
.product-unit-option-add{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;

    color:#ffffff !important;
    border-color:#8b5cf6 !important;
}

/* Light mode close icon */
html[data-pos-theme="light"]
.product-unit-modal-card .btn-close{
    filter:none !important;
    opacity:.75 !important;
}


/* =========================================================
   FINAL SEARCH FULL WIDTH + CUSTOMER BUTTONS BELOW
========================================================= */

@media (min-width:768px){

    .items-area > .toolbar{
        width:100% !important;

        display:flex !important;
        flex-direction:row !important;
        flex-wrap:wrap !important;

        align-items:center !important;
        justify-content:flex-start !important;

        gap:8px !important;
        margin-bottom:10px !important;
    }

    /* Search poori first row lega */
    .items-area > .toolbar > .search-wrap.pos-search-highlight{
        order:1 !important;

        flex:0 0 100% !important;
        width:100% !important;
        min-width:0 !important;
        max-width:none !important;

        margin:0 !important;
        box-sizing:border-box !important;
    }

    /* Input aur scan bhi available width use karenge */
    .items-area > .toolbar
    > .search-wrap.pos-search-highlight
    > .pos-search-controls{
        width:100% !important;
        min-width:0 !important;
    }

    /* Retailer search ke neeche */
    .items-area > .toolbar > .type-btn[data-type="Retailer"]{
        order:2 !important;

        flex:0 0 112px !important;
        width:112px !important;
        min-width:112px !important;

        height:42px !important;
        min-height:42px !important;

        margin:0 !important;
        padding:0 14px !important;
    }

    /* Wholesale buttons Retailer ke saath */
    .items-area > .toolbar > .type-buttons{
        order:3 !important;

        width:auto !important;
        display:flex !important;
        align-items:center !important;
        justify-content:flex-start !important;
        flex-wrap:wrap !important;

        gap:8px !important;
        margin:0 !important;
    }

    .items-area > .toolbar > .selected-label{
        display:none !important;
    }
}



/* =========================================================
   FINAL POS SHORTCUT BUTTON COLORS
   Hold Sale | Clear Cart | Scan Barcode
   Save & Hold | Print | Complete Sale
   Dark + Light themes
   Functionality / shortcut logic unchanged
========================================================= */

/* Smooth hover for all shortcut buttons */
.pos-items-sticky-btn,
.pos-premium-cart .pos-invoice-action-btn,
.pos-premium-cart .pos-complete-sale-btn{
    transition:
        background .18s ease,
        border-color .18s ease,
        color .18s ease,
        transform .18s ease,
        box-shadow .18s ease !important;
}


/* =========================================================
   DARK MODE
========================================================= */

/* Left shortcut buttons area: black strip remove */
html[data-pos-theme="dark"] body
.pos-items-sticky-actions{
    background:
        linear-gradient(
            135deg,
            rgba(76,29,149,.30),
            rgba(30,41,79,.92)
        ) !important;

    border-top:
        1px solid rgba(139,92,246,.28) !important;

    box-shadow:
        0 -8px 26px rgba(76,29,149,.14) !important;
}

/* Old black fading layer remove */
html[data-pos-theme="dark"] body
.items-area .pos-items-sticky-actions::before{
    background:
        linear-gradient(
            180deg,
            transparent,
            rgba(49,46,129,.78)
        ) !important;
}


/* Hold Sale */
html[data-pos-theme="dark"] body
.pos-items-sticky-btn:nth-child(1){
    background:
        linear-gradient(
            135deg,
            #6d4cff,
            #7c3aed
        ) !important;

    color:#ffffff !important;
    border-color:rgba(167,139,250,.70) !important;

    box-shadow:
        0 8px 20px rgba(124,58,237,.26) !important;
}


/* Clear Cart */
html[data-pos-theme="dark"] body
.pos-items-sticky-btn.danger{
    background:
        linear-gradient(
            135deg,
            #f43f5e,
            #dc2626
        ) !important;

    color:#ffffff !important;
    border-color:rgba(251,113,133,.72) !important;

    box-shadow:
        0 8px 20px rgba(239,68,68,.22) !important;
}


/* Scan Barcode */
html[data-pos-theme="dark"] body
.pos-items-sticky-btn:nth-child(3){
    background:
        linear-gradient(
            135deg,
            #2563eb,
            #4f46e5
        ) !important;

    color:#ffffff !important;
    border-color:rgba(96,165,250,.70) !important;

    box-shadow:
        0 8px 20px rgba(37,99,235,.22) !important;
}


/* Left buttons hover */
html[data-pos-theme="dark"] body
.pos-items-sticky-btn:hover{
    transform:translateY(-1px) !important;

    filter:brightness(1.10) !important;

    box-shadow:
        0 12px 26px rgba(124,58,237,.30) !important;
}


/* Left F6 / F9 / F3 badges */
html[data-pos-theme="dark"] body
.pos-items-sticky-btn kbd{
    background:rgba(255,255,255,.16) !important;
    color:#ffffff !important;

    border:
        1px solid rgba(255,255,255,.28) !important;

    box-shadow:none !important;
}


/* Save & Hold */
html[data-pos-theme="dark"] body
.pos-premium-cart .pos-invoice-save-btn{
    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border-color:rgba(167,139,250,.65) !important;

    box-shadow:
        0 8px 20px rgba(124,58,237,.22) !important;
}


/* Print */
html[data-pos-theme="dark"] body
.pos-premium-cart .pos-invoice-print-btn{
    background:
        linear-gradient(
            135deg,
            #2563eb,
            #1d4ed8
        ) !important;

    color:#ffffff !important;
    border-color:rgba(96,165,250,.65) !important;

    box-shadow:
        0 8px 20px rgba(37,99,235,.22) !important;
}


/* Complete Sale */
html[data-pos-theme="dark"] body
.pos-premium-cart .pos-complete-sale-btn{
    background:
        linear-gradient(
            135deg,
            #9f67ff,
            #7c3aed 55%,
            #5b21b6
        ) !important;

    color:#ffffff !important;
    border-color:rgba(192,132,252,.72) !important;

    box-shadow:
        0 10px 24px rgba(124,58,237,.30) !important;
}


/* Right buttons hover */
html[data-pos-theme="dark"] body
.pos-premium-cart .pos-invoice-save-btn:hover,

html[data-pos-theme="dark"] body
.pos-premium-cart .pos-invoice-print-btn:hover,

html[data-pos-theme="dark"] body
.pos-premium-cart .pos-complete-sale-btn:hover{
    transform:translateY(-1px) !important;
    filter:brightness(1.10) !important;
}


/* Right F5 / F6 / F12 badges */
html[data-pos-theme="dark"] body
.pos-premium-cart .pos-invoice-action-btn kbd,

html[data-pos-theme="dark"] body
.pos-premium-cart .pos-complete-sale-btn kbd{
    background:rgba(255,255,255,.16) !important;
    color:#ffffff !important;

    border:
        1px solid rgba(255,255,255,.28) !important;

    box-shadow:none !important;
}


/* =========================================================
   LIGHT MODE
========================================================= */

/* Left shortcut area */
html[data-pos-theme="light"] body
.pos-items-sticky-actions{
    background:
        linear-gradient(
            135deg,
            #ffffff,
            #f5f3ff
        ) !important;

    border-top:1px solid #ddd6fe !important;

    box-shadow:
        0 -8px 24px rgba(91,33,182,.08) !important;
}

/* Remove dark fade */
html[data-pos-theme="light"] body
.items-area .pos-items-sticky-actions::before{
    background:
        linear-gradient(
            180deg,
            transparent,
            #f5f3ff
        ) !important;
}


/* Hold Sale */
html[data-pos-theme="light"] body
.pos-items-sticky-btn:nth-child(1){
    background:#ede9fe !important;
    color:#5b21b6 !important;

    border-color:#c4b5fd !important;

    box-shadow:
        0 6px 16px rgba(124,58,237,.10) !important;
}


/* Clear Cart */
html[data-pos-theme="light"] body
.pos-items-sticky-btn.danger{
    background:#fff1f2 !important;
    color:#dc2626 !important;

    border-color:#fecdd3 !important;

    box-shadow:
        0 6px 16px rgba(239,68,68,.08) !important;
}


/* Scan Barcode */
html[data-pos-theme="light"] body
.pos-items-sticky-btn:nth-child(3){
    background:#eff6ff !important;
    color:#1d4ed8 !important;

    border-color:#bfdbfe !important;

    box-shadow:
        0 6px 16px rgba(37,99,235,.08) !important;
}


/* Light left hover */
html[data-pos-theme="light"] body
.pos-items-sticky-btn:nth-child(1):hover{
    background:#ddd6fe !important;
    border-color:#8b5cf6 !important;
}

html[data-pos-theme="light"] body
.pos-items-sticky-btn.danger:hover{
    background:#ffe4e6 !important;
    border-color:#fb7185 !important;
}

html[data-pos-theme="light"] body
.pos-items-sticky-btn:nth-child(3):hover{
    background:#dbeafe !important;
    border-color:#60a5fa !important;
}


/* Light left shortcut badges */
html[data-pos-theme="light"] body
.pos-items-sticky-btn kbd{
    background:#ffffff !important;
    color:#475569 !important;

    border:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}


/* Save & Hold */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-save-btn{
    background:#ede9fe !important;
    color:#5b21b6 !important;

    border-color:#c4b5fd !important;

    box-shadow:
        0 6px 16px rgba(124,58,237,.10) !important;
}


/* Print */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-print-btn{
    background:#eff6ff !important;
    color:#1d4ed8 !important;

    border-color:#bfdbfe !important;

    box-shadow:
        0 6px 16px rgba(37,99,235,.10) !important;
}


/* Complete Sale */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-complete-sale-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border-color:#7c3aed !important;

    box-shadow:
        0 8px 20px rgba(124,58,237,.20) !important;
}


/* Light right hover */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-save-btn:hover{
    background:#ddd6fe !important;
    color:#4c1d95 !important;
    border-color:#8b5cf6 !important;
}

html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-print-btn:hover{
    background:#dbeafe !important;
    color:#1e40af !important;
    border-color:#60a5fa !important;
}

html[data-pos-theme="light"] body
.pos-premium-cart .pos-complete-sale-btn:hover{
    background:
        linear-gradient(
            135deg,
            #7c3aed,
            #5b21b6
        ) !important;

    color:#ffffff !important;
}


/* Light right shortcut badges */
html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-save-btn kbd{
    background:#ffffff !important;
    color:#6d28d9 !important;
    border:1px solid #c4b5fd !important;
}

html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-print-btn kbd{
    background:#ffffff !important;
    color:#1d4ed8 !important;
    border:1px solid #bfdbfe !important;
}

html[data-pos-theme="light"] body
.pos-premium-cart .pos-complete-sale-btn kbd{
    background:rgba(255,255,255,.18) !important;
    color:#ffffff !important;

    border:
        1px solid rgba(255,255,255,.30) !important;
}

/* =========================================================
   FINAL SHORTCUT BADGES + PAYMENT ICONS HIGHLIGHT
   Sirf UI styling — HTML, JS, shortcuts aur backend unchanged
========================================================= */

/* =========================================================
   1. ALL SHORTCUT KEY BADGES
   F3, F5, F6, F9, F12
========================================================= */

.pos-items-sticky-btn kbd,
.pos-premium-cart .pos-invoice-action-btn kbd,
.pos-premium-cart .pos-complete-sale-btn kbd,
.pos-premium-cart .pos-proceed-btn kbd,
.pos-premium-cart .invoice-btn kbd{
    width:auto !important;
    min-width:34px !important;
    height:25px !important;
    min-height:25px !important;

    padding:0 8px !important;
    margin:0 !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border-radius:7px !important;

    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    border:
        1px solid rgba(196,181,253,.85) !important;

    font-family:Inter, Arial, sans-serif !important;
    font-size:11px !important;
    font-weight:950 !important;
    line-height:1 !important;
    letter-spacing:.4px !important;

    box-shadow:
        0 5px 12px rgba(124,58,237,.30),
        inset 0 1px 0 rgba(255,255,255,.24) !important;

    flex-shrink:0 !important;

    transition:
        transform .18s ease,
        background .18s ease,
        border-color .18s ease,
        box-shadow .18s ease !important;
}


/* Button hover par shortcut badge highlight */

.pos-items-sticky-btn:hover kbd,
.pos-premium-cart .pos-invoice-action-btn:hover kbd,
.pos-premium-cart .pos-complete-sale-btn:hover kbd,
.pos-premium-cart .pos-proceed-btn:hover kbd,
.pos-premium-cart .invoice-btn:hover kbd{
    transform:
        translateY(-2px)
        scale(1.09) !important;

    background:
        linear-gradient(
            135deg,
            #a78bfa,
            #7c3aed
        ) !important;

    color:#ffffff !important;

    border-color:#ddd6fe !important;

    box-shadow:
        0 8px 18px rgba(124,58,237,.42),
        0 0 0 3px rgba(139,92,246,.14),
        inset 0 1px 0 rgba(255,255,255,.30) !important;
}


/* =========================================================
   LEFT SHORTCUTS — INDIVIDUAL BADGE COLORS
========================================================= */

/* Hold Sale F6 */

.pos-items-sticky-btn:nth-child(1) kbd{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    border-color:#c4b5fd !important;

    box-shadow:
        0 5px 13px rgba(124,58,237,.32) !important;
}


/* Clear Cart F9 */

.pos-items-sticky-btn.danger kbd{
    background:
        linear-gradient(
            135deg,
            #fb7185,
            #dc2626
        ) !important;

    border-color:#fecdd3 !important;

    box-shadow:
        0 5px 13px rgba(239,68,68,.28) !important;
}

.pos-items-sticky-btn.danger:hover kbd{
    background:
        linear-gradient(
            135deg,
            #f43f5e,
            #b91c1c
        ) !important;

    border-color:#ffffff !important;

    box-shadow:
        0 8px 18px rgba(239,68,68,.40),
        0 0 0 3px rgba(239,68,68,.13) !important;
}


/* Scan Barcode F3 */

.pos-items-sticky-btn:nth-child(3) kbd{
    background:
        linear-gradient(
            135deg,
            #3b82f6,
            #1d4ed8
        ) !important;

    border-color:#bfdbfe !important;

    box-shadow:
        0 5px 13px rgba(37,99,235,.28) !important;
}

.pos-items-sticky-btn:nth-child(3):hover kbd{
    background:
        linear-gradient(
            135deg,
            #60a5fa,
            #2563eb
        ) !important;

    border-color:#ffffff !important;

    box-shadow:
        0 8px 18px rgba(37,99,235,.40),
        0 0 0 3px rgba(37,99,235,.13) !important;
}


/* =========================================================
   RIGHT ACTION SHORTCUTS
========================================================= */

/* Save & Hold F6 */

.pos-premium-cart
.pos-invoice-save-btn kbd{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    border-color:#c4b5fd !important;
}


/* Print F5 */

.pos-premium-cart
.pos-invoice-print-btn kbd{
    background:
        linear-gradient(
            135deg,
            #3b82f6,
            #1d4ed8
        ) !important;

    border-color:#bfdbfe !important;

    box-shadow:
        0 5px 13px rgba(37,99,235,.30) !important;
}

.pos-premium-cart
.pos-invoice-print-btn:hover kbd{
    background:
        linear-gradient(
            135deg,
            #60a5fa,
            #2563eb
        ) !important;

    border-color:#ffffff !important;

    box-shadow:
        0 8px 18px rgba(37,99,235,.42),
        0 0 0 3px rgba(37,99,235,.13) !important;
}


/* Complete Sale F12 */

.pos-premium-cart
.pos-complete-sale-btn kbd{
    min-width:42px !important;

    background:rgba(255,255,255,.18) !important;
    color:#ffffff !important;

    border:
        1px solid rgba(255,255,255,.55) !important;

    box-shadow:
        0 5px 14px rgba(76,29,149,.30),
        inset 0 1px 0 rgba(255,255,255,.25) !important;
}

.pos-premium-cart
.pos-complete-sale-btn:hover kbd{
    background:#ffffff !important;
    color:#6d28d9 !important;

    border-color:#ffffff !important;

    box-shadow:
        0 8px 20px rgba(76,29,149,.34),
        0 0 0 3px rgba(255,255,255,.16) !important;
}


/* =========================================================
   2. PAYMENT METHOD BUTTON ICONS
   Cash, Card, Bank, JazzCash, EasyPaisa, Other
========================================================= */

.pos-premium-cart .pos-payment-methods .pay-btn,
.pos-premium-cart .pos-method-grid .pos-method-btn{
    overflow:visible !important;

    transition:
        transform .18s ease,
        background .18s ease,
        border-color .18s ease,
        box-shadow .18s ease !important;
}


/* Normal symbol / emoji icons */

.pos-premium-cart .pos-pay-logo{
    width:29px !important;
    min-width:29px !important;
    height:27px !important;
    min-height:27px !important;

    margin:0 !important;
    padding:0 !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border-radius:8px !important;

    background:
        linear-gradient(
            135deg,
            rgba(139,92,246,.24),
            rgba(109,40,217,.12)
        ) !important;

    color:#8b5cf6 !important;

    border:
        1px solid rgba(139,92,246,.38) !important;

    font-size:20px !important;
    line-height:1 !important;

    box-shadow:
        0 4px 10px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.14) !important;

    flex-shrink:0 !important;

    transition:
        transform .18s ease,
        background .18s ease,
        color .18s ease,
        border-color .18s ease,
        box-shadow .18s ease !important;
}


/* Material Symbols ki proper size */

.pos-premium-cart
.pos-pay-logo.material-symbols-rounded{
    font-size:20px !important;
    font-weight:500 !important;
}


/* JazzCash / EasyPaisa brand badges */

.pos-premium-cart .pos-pay-brand{
    width:auto !important;
    min-width:34px !important;
    height:23px !important;
    min-height:23px !important;

    padding:0 6px !important;
    margin:0 !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border-radius:999px !important;

    font-size:9px !important;
    font-weight:950 !important;
    line-height:1 !important;
    letter-spacing:.2px !important;

    box-shadow:
        0 4px 10px rgba(0,0,0,.12),
        inset 0 1px 0 rgba(255,255,255,.18) !important;

    flex-shrink:0 !important;

    transition:
        transform .18s ease,
        box-shadow .18s ease,
        filter .18s ease !important;
}


/* JazzCash red badge */

.pos-premium-cart .pos-pay-brand-jazz{
    background:
        linear-gradient(
            135deg,
            #ef4444,
            #b91c1c
        ) !important;

    color:#ffffff !important;

    border:
        1px solid rgba(254,202,202,.75) !important;
}


/* EasyPaisa green badge */

.pos-premium-cart .pos-pay-brand-easy{
    background:
        linear-gradient(
            135deg,
            #10b981,
            #047857
        ) !important;

    color:#ffffff !important;

    border:
        1px solid rgba(167,243,208,.72) !important;
}


/* Payment button text clearer */

.pos-premium-cart .pos-pay-text{
    font-size:9.5px !important;
    font-weight:950 !important;
    line-height:1 !important;
    color:inherit !important;
}


/* =========================================================
   PAYMENT BUTTON HOVER EFFECT
========================================================= */

.pos-premium-cart
.pos-payment-methods .pay-btn:hover,

.pos-premium-cart
.pos-method-grid .pos-method-btn:hover{
    transform:translateY(-2px) !important;

    border-color:
        rgba(139,92,246,.70) !important;

    box-shadow:
        0 9px 20px rgba(124,58,237,.24) !important;
}


/* Icon lift and glow */

.pos-premium-cart
.pos-payment-methods .pay-btn:hover .pos-pay-logo,

.pos-premium-cart
.pos-method-grid .pos-method-btn:hover .pos-pay-logo{
    transform:
        translateY(-2px)
        scale(1.12) !important;

    background:rgba(255,255,255,.18) !important;
    color:#ffffff !important;

    border-color:
        rgba(255,255,255,.48) !important;

    box-shadow:
        0 7px 16px rgba(0,0,0,.20),
        0 0 0 3px rgba(255,255,255,.10) !important;
}


/* JazzCash / EasyPaisa hover */

.pos-premium-cart
.pos-payment-methods .pay-btn:hover .pos-pay-brand,

.pos-premium-cart
.pos-method-grid .pos-method-btn:hover .pos-pay-brand{
    transform:
        translateY(-2px)
        scale(1.10) !important;

    filter:brightness(1.10) !important;

    box-shadow:
        0 7px 16px rgba(0,0,0,.24),
        0 0 0 3px rgba(255,255,255,.10) !important;
}


/* Active selected payment icon */

.pos-premium-cart
.pos-payment-methods .pay-btn.active .pos-pay-logo,

.pos-premium-cart
.pos-method-grid .pos-method-btn.active .pos-pay-logo{
    background:rgba(255,255,255,.20) !important;
    color:#ffffff !important;

    border-color:
        rgba(255,255,255,.50) !important;

    transform:scale(1.08) !important;

    box-shadow:
        0 6px 15px rgba(0,0,0,.20),
        0 0 0 3px rgba(255,255,255,.10) !important;
}


/* =========================================================
   LIGHT THEME
========================================================= */

html[data-pos-theme="light"] body
.pos-items-sticky-btn kbd,

html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-action-btn kbd{
    color:#ffffff !important;
}


html[data-pos-theme="light"] body
.pos-premium-cart .pos-pay-logo{
    background:#f3efff !important;
    color:#6d28d9 !important;

    border-color:#c4b5fd !important;

    box-shadow:
        0 4px 10px rgba(124,58,237,.12) !important;
}


html[data-pos-theme="light"] body
.pos-premium-cart
.pos-payment-methods .pay-btn:hover .pos-pay-logo,

html[data-pos-theme="light"] body
.pos-premium-cart
.pos-method-grid .pos-method-btn:hover .pos-pay-logo{
    background:#ffffff !important;
    color:#6d28d9 !important;

    border-color:#ffffff !important;

    box-shadow:
        0 7px 16px rgba(91,33,182,.22),
        0 0 0 3px rgba(255,255,255,.18) !important;
}


/* =========================================================
   SMALL LAPTOP HEIGHT
========================================================= */

@media (max-height:760px){

    .pos-items-sticky-btn kbd,
    .pos-premium-cart .pos-invoice-action-btn kbd,
    .pos-premium-cart .pos-complete-sale-btn kbd{
        min-width:32px !important;
        height:23px !important;
        min-height:23px !important;

        padding:0 7px !important;

        font-size:10px !important;
    }

    .pos-premium-cart .pos-complete-sale-btn kbd{
        min-width:39px !important;
    }

    .pos-premium-cart .pos-pay-logo{
        width:26px !important;
        min-width:26px !important;
        height:24px !important;
        min-height:24px !important;

        font-size:18px !important;
    }

    .pos-premium-cart .pos-pay-brand{
        min-width:31px !important;
        height:21px !important;
        min-height:21px !important;

        font-size:8px !important;
    }
}



/* =========================================================
   FINAL CART BOTTOM ACTIONS
   Save & Hold | Cash | Card | Complete Sale
========================================================= */

.pos-premium-cart .pos-invoice-actions-row{
    width:100% !important;

    display:grid !important;
    grid-template-columns:
        1.15fr
        .78fr
        .78fr
        1.45fr !important;

    align-items:stretch !important;
    gap:7px !important;

    margin:0 !important;
    padding:0 !important;

    flex-shrink:0 !important;
}


/* All four buttons */

.pos-premium-cart
.pos-invoice-actions-row
> button{
    width:100% !important;
    min-width:0 !important;

    height:42px !important;
    min-height:42px !important;

    margin:0 !important;
    padding:0 8px !important;

    border-radius:8px !important;

    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;

    font-size:10.5px !important;
    font-weight:950 !important;
    line-height:1 !important;

    white-space:nowrap !important;

    transition:
        transform .18s ease,
        background .18s ease,
        color .18s ease,
        border-color .18s ease,
        box-shadow .18s ease !important;
}


/* Cash and Card common design */

.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-payment-btn{
    background:#0a0f1d !important;
    color:#dbeafe !important;

    border:
        1px solid rgba(148,163,184,.18) !important;

    box-shadow:none !important;
}


/* Payment icons */

.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-pay-icon{
    width:23px !important;
    height:23px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border-radius:7px !important;

    font-size:15px !important;
    line-height:1 !important;

    background:rgba(148,163,184,.10) !important;
    border:1px solid rgba(148,163,184,.16) !important;

    transition:
        transform .18s ease,
        background .18s ease,
        box-shadow .18s ease !important;
}


/* Cash hover and selected */

.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-cash-btn:hover,

.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-cash-btn.active{
    background:
        linear-gradient(
            135deg,
            #22c55e,
            #15803d
        ) !important;

    color:#ffffff !important;

    border-color:
        rgba(74,222,128,.75) !important;

    box-shadow:
        0 8px 19px rgba(34,197,94,.24) !important;
}


/* Card hover and selected */

.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-card-btn:hover,

.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-card-btn.active{
    background:
        linear-gradient(
            135deg,
            #3b82f6,
            #1d4ed8
        ) !important;

    color:#ffffff !important;

    border-color:
        rgba(96,165,250,.78) !important;

    box-shadow:
        0 8px 19px rgba(37,99,235,.25) !important;
}


/* Selected payment icon */

.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-payment-btn.active
.pos-bottom-pay-icon,

.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-payment-btn:hover
.pos-bottom-pay-icon{
    transform:scale(1.10) !important;

    background:rgba(255,255,255,.18) !important;

    border-color:
        rgba(255,255,255,.38) !important;

    box-shadow:
        0 5px 12px rgba(0,0,0,.18) !important;
}


/* General button hover */

.pos-premium-cart
.pos-invoice-actions-row
> button:hover{
    transform:translateY(-1px) !important;
}


/* Light theme */

html[data-pos-theme="light"] body
.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-payment-btn:not(.active){
    background:#ffffff !important;
    color:#334155 !important;

    border-color:#cbd5e1 !important;

    box-shadow:
        0 4px 12px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] body
.pos-premium-cart
.pos-invoice-actions-row
.pos-bottom-pay-icon{
    background:#f8fafc !important;
    border-color:#e2e8f0 !important;
}


/* Small laptop */

@media (max-width:1450px),
       (max-height:760px){

    .pos-premium-cart
    .pos-invoice-actions-row{
        grid-template-columns:
            1.08fr
            .72fr
            .72fr
            1.30fr !important;

        gap:5px !important;
    }

    .pos-premium-cart
    .pos-invoice-actions-row
    > button{
        height:38px !important;
        min-height:38px !important;

        padding:0 5px !important;

        font-size:9.5px !important;
    }

    .pos-premium-cart
    .pos-invoice-actions-row
    .pos-bottom-pay-icon{
        width:20px !important;
        height:20px !important;

        font-size:13px !important;
    }
}


/* Mobile */

@media (max-width:767.98px){

    .pos-premium-cart
    .pos-invoice-actions-row{
        grid-template-columns:
            1fr
            1fr !important;

        gap:7px !important;
    }

    .pos-premium-cart
    .pos-invoice-actions-row
    > button{
        height:44px !important;
        min-height:44px !important;
    }
}



/* =========================================================
   FINAL LIGHT THEME VISIBILITY FIX
   1. Shortcut badges always visible
   2. Shortcut hover animation removed
   3. Cart unit price visible
   Only CSS — JavaScript/backend unchanged
========================================================= */


/* =========================================================
   1. SHORTCUT BADGES — COMMON FIX
========================================================= */

.pos-items-sticky-btn kbd,
.pos-premium-cart .pos-invoice-action-btn kbd,
.pos-premium-cart .pos-invoice-save-btn kbd,
.pos-premium-cart .pos-complete-sale-btn kbd,
.pos-premium-cart .pos-proceed-btn kbd,
.pos-premium-cart .invoice-btn kbd{
    min-width:34px !important;
    height:24px !important;
    min-height:24px !important;

    padding:0 8px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border-radius:7px !important;

    font-size:10px !important;
    font-weight:950 !important;
    line-height:1 !important;
    letter-spacing:.3px !important;

    opacity:1 !important;
    visibility:visible !important;

    transform:none !important;
    transition:none !important;
}


/* =========================================================
   2. REMOVE SHORTCUT BADGE HOVER EFFECT
========================================================= */

.pos-items-sticky-btn:hover kbd,
.pos-premium-cart .pos-invoice-action-btn:hover kbd,
.pos-premium-cart .pos-invoice-save-btn:hover kbd,
.pos-premium-cart .pos-complete-sale-btn:hover kbd,
.pos-premium-cart .pos-proceed-btn:hover kbd,
.pos-premium-cart .invoice-btn:hover kbd{
    transform:none !important;
    filter:none !important;
}


/* =========================================================
   3. LIGHT THEME SHORTCUT BADGES
========================================================= */

/* Hold Sale F6 */

html[data-pos-theme="light"] body
.pos-items-sticky-btn:nth-child(1) kbd{
    background:#6d28d9 !important;
    color:#ffffff !important;

    border:1px solid #5b21b6 !important;

    box-shadow:
        0 3px 8px rgba(109,40,217,.24) !important;
}


/* Clear Cart F9 */

html[data-pos-theme="light"] body
.pos-items-sticky-btn.danger kbd{
    background:#dc2626 !important;
    color:#ffffff !important;

    border:1px solid #b91c1c !important;

    box-shadow:
        0 3px 8px rgba(220,38,38,.22) !important;
}


/* Scan Barcode F3 */

html[data-pos-theme="light"] body
.pos-items-sticky-btn:nth-child(3) kbd{
    background:#2563eb !important;
    color:#ffffff !important;

    border:1px solid #1d4ed8 !important;

    box-shadow:
        0 3px 8px rgba(37,99,235,.22) !important;
}


/* Save & Hold F6 */

html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-save-btn kbd{
    background:#6d28d9 !important;
    color:#ffffff !important;

    border:1px solid #5b21b6 !important;

    box-shadow:
        0 3px 8px rgba(109,40,217,.24) !important;
}


/* Complete Sale F12 */

html[data-pos-theme="light"] body
.pos-premium-cart .pos-complete-sale-btn kbd,

html[data-pos-theme="light"] body
.pos-premium-cart .pos-proceed-btn kbd,

html[data-pos-theme="light"] body
.pos-premium-cart .invoice-btn kbd{
    background:#4c1d95 !important;
    color:#ffffff !important;

    border:1px solid #ddd6fe !important;

    box-shadow:
        0 3px 8px rgba(76,29,149,.28) !important;
}


/*
|--------------------------------------------------------------------------
| Hover par bhi same colors rahenge
|--------------------------------------------------------------------------
*/

html[data-pos-theme="light"] body
.pos-items-sticky-btn:nth-child(1):hover kbd{
    background:#6d28d9 !important;
    color:#ffffff !important;
    border-color:#5b21b6 !important;
}

html[data-pos-theme="light"] body
.pos-items-sticky-btn.danger:hover kbd{
    background:#dc2626 !important;
    color:#ffffff !important;
    border-color:#b91c1c !important;
}

html[data-pos-theme="light"] body
.pos-items-sticky-btn:nth-child(3):hover kbd{
    background:#2563eb !important;
    color:#ffffff !important;
    border-color:#1d4ed8 !important;
}

html[data-pos-theme="light"] body
.pos-premium-cart .pos-invoice-save-btn:hover kbd{
    background:#6d28d9 !important;
    color:#ffffff !important;
    border-color:#5b21b6 !important;
}

html[data-pos-theme="light"] body
.pos-premium-cart .pos-complete-sale-btn:hover kbd,

html[data-pos-theme="light"] body
.pos-premium-cart .pos-proceed-btn:hover kbd,

html[data-pos-theme="light"] body
.pos-premium-cart .invoice-btn:hover kbd{
    background:#4c1d95 !important;
    color:#ffffff !important;
    border-color:#ddd6fe !important;
}


/* =========================================================
   4. CART UNIT PRICE — LIGHT THEME
========================================================= */

/*
|--------------------------------------------------------------------------
| Rs label
|--------------------------------------------------------------------------
*/

html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-price-cell::before,

html[data-pos-theme="light"] body
.pos-premium-cart .ref-price-cell::before,

html[data-pos-theme="light"] body
.cart-area .ref-price-cell::before{
    color:#0f172a !important;
    -webkit-text-fill-color:#0f172a !important;

    opacity:1 !important;
    visibility:visible !important;

    font-weight:950 !important;
}


/*
|--------------------------------------------------------------------------
| Actual unit-price input value
|--------------------------------------------------------------------------
*/

html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-price-cell .pos-cart-price,

html[data-pos-theme="light"] body
.pos-premium-cart .ref-price-cell .pos-cart-price,

html[data-pos-theme="light"] body
.pos-premium-cart .pos-ref-price-cell input[data-cart-price-input],

html[data-pos-theme="light"] body
.pos-premium-cart .ref-price-cell input[data-cart-price-input],

html[data-pos-theme="light"] body
.cart-area .ref-price-cell .pos-cart-price,

html[data-pos-theme="light"] body
.cart-area input[data-cart-price-input]{
    color:#0f172a !important;
    -webkit-text-fill-color:#0f172a !important;

    caret-color:#6d28d9 !important;

    background:#f8fafc !important;

    border:
        1px solid #cbd5e1 !important;

    border-radius:6px !important;

    opacity:1 !important;
    visibility:visible !important;

    font-weight:950 !important;

    box-shadow:none !important;
}


/*
|--------------------------------------------------------------------------
| Readonly/disabled input bhi visible rahe
|--------------------------------------------------------------------------
*/

html[data-pos-theme="light"] body
.pos-premium-cart input.pos-cart-price:disabled,

html[data-pos-theme="light"] body
.pos-premium-cart input.pos-cart-price[readonly],

html[data-pos-theme="light"] body
.pos-premium-cart input[data-cart-price-input]:disabled,

html[data-pos-theme="light"] body
.pos-premium-cart input[data-cart-price-input][readonly]{
    color:#0f172a !important;
    -webkit-text-fill-color:#0f172a !important;

    background:#f8fafc !important;

    opacity:1 !important;
}


/* Unit price focus */

html[data-pos-theme="light"] body
.pos-premium-cart .pos-cart-price:focus,

html[data-pos-theme="light"] body
.pos-premium-cart input[data-cart-price-input]:focus{
    color:#0f172a !important;
    -webkit-text-fill-color:#0f172a !important;

    background:#ffffff !important;

    border-color:#8b5cf6 !important;

    box-shadow:
        0 0 0 2px rgba(139,92,246,.14) !important;

    outline:none !important;
}


/* =========================================================
   5. DARK THEME UNIT PRICE SAFETY
========================================================= */

html[data-pos-theme="dark"] body
.pos-premium-cart .pos-cart-price,

html[data-pos-theme="dark"] body
.pos-premium-cart input[data-cart-price-input]{
    color:#f8fafc !important;
    -webkit-text-fill-color:#f8fafc !important;

    background:#070d19 !important;

    border:
        1px solid rgba(148,163,184,.16) !important;

    opacity:1 !important;
}

/* Performance: skip layout/paint for off-screen product cards. */
#itemsGrid .product-card{
    content-visibility:auto;
    contain-intrinsic-size:305px 205px;
}

/* Product lazy-load indicator: absolute overlay, grid/UI structure unchanged. */
.items-area{
    position:relative;
}

.pos-products-loader{
    position:absolute;
    left:50%;
    bottom:74px;
    z-index:25;
    transform:translateX(-50%);
    padding:9px 14px;
    border-radius:999px;
    border:1px solid rgba(210,164,60,.35);
    background:rgba(8,12,21,.94);
    color:#f8fafc;
    box-shadow:0 12px 30px rgba(0,0,0,.28);
    font-size:12px;
    font-weight:850;
    pointer-events:none;
}

.pos-products-loader[hidden]{
    display:none !important;
}

html[data-pos-theme="light"] .pos-products-loader{
    background:rgba(255,255,255,.96);
    color:#111827;
    border-color:rgba(139,92,246,.28);
}



/* =========================================================
   NEW CART ITEM ARRIVAL ANIMATION
   Sirf item add hone ke waqt chalegi
   Mouse hover se iska koi relation nahi
========================================================= */

.pos-premium-cart
.pos-cart-item.cart-item-just-added,

.cart-area
.pos-cart-item.cart-item-just-added{
    position:relative !important;
    isolation:isolate !important;
    z-index:6 !important;

    animation:
        oneerpCartItemEnter .75s
        cubic-bezier(.22,.80,.25,1)
        both !important;
}

/*
|--------------------------------------------------------------------------
| Purple highlight layer
|--------------------------------------------------------------------------
*/
.pos-premium-cart
.pos-cart-item.cart-item-just-added::before,

.cart-area
.pos-cart-item.cart-item-just-added::before{
    content:"" !important;

    position:absolute !important;
    inset:2px !important;

    border-radius:9px !important;

    background:
        linear-gradient(
            90deg,
            rgba(124,58,237,.26) 0%,
            rgba(139,92,246,.16) 55%,
            rgba(124,58,237,.08) 100%
        ) !important;

    border:
        1px solid rgba(167,139,250,.78) !important;

    box-shadow:
        inset 4px 0 0 #a855f7,
        0 8px 24px rgba(91,33,182,.28),
        0 0 18px rgba(124,58,237,.24) !important;

    pointer-events:none !important;
    z-index:0 !important;

    animation:
        oneerpCartItemGlow 1.15s
        ease-out
        forwards !important;
}

/*
|--------------------------------------------------------------------------
| Cart row content highlight layer se upar rahe
|--------------------------------------------------------------------------
*/
.pos-premium-cart
.pos-cart-item.cart-item-just-added > *,

.cart-area
.pos-cart-item.cart-item-just-added > *{
    position:relative !important;
    z-index:1 !important;
}

/*
|--------------------------------------------------------------------------
| Product image temporary pop
|--------------------------------------------------------------------------
*/
.pos-premium-cart
.pos-cart-item.cart-item-just-added
.pos-ref-img,

.pos-premium-cart
.pos-cart-item.cart-item-just-added
.ref-cart-img,

.cart-area
.pos-cart-item.cart-item-just-added
.pos-ref-img,

.cart-area
.pos-cart-item.cart-item-just-added
.ref-cart-img{
    animation:
        oneerpCartImagePop .75s
        cubic-bezier(.22,.80,.25,1)
        both !important;
}

/*
|--------------------------------------------------------------------------
| Row top se enter hogi
|--------------------------------------------------------------------------
*/
@keyframes oneerpCartItemEnter{
    0%{
        transform:
            translateY(-10px)
            scale(.985);

        opacity:.45;
    }

    45%{
        transform:
            translateY(2px)
            scale(1.006);

        opacity:1;
    }

    100%{
        transform:
            translateY(0)
            scale(1);

        opacity:1;
    }
}

/*
|--------------------------------------------------------------------------
| Highlight automatic fade
|--------------------------------------------------------------------------
*/
@keyframes oneerpCartItemGlow{
    0%{
        opacity:0;
        transform:scale(.97);
    }

    18%{
        opacity:1;
        transform:scale(1);
    }

    68%{
        opacity:.88;
    }

    100%{
        opacity:0;
        transform:scale(1);
    }
}

/*
|--------------------------------------------------------------------------
| Image pop animation
|--------------------------------------------------------------------------
*/
@keyframes oneerpCartImagePop{
    0%{
        transform:scale(.82);
    }

    55%{
        transform:scale(1.10);
    }

    100%{
        transform:scale(1);
    }
}

/* =========================================================
   LIGHT MODE ARRIVAL HIGHLIGHT
========================================================= */

html[data-pos-theme="light"]
.pos-premium-cart
.pos-cart-item.cart-item-just-added::before,

html[data-pos-theme="light"]
.cart-area
.pos-cart-item.cart-item-just-added::before{
    background:
        linear-gradient(
            90deg,
            rgba(139,92,246,.20) 0%,
            rgba(196,181,253,.22) 58%,
            rgba(255,255,255,.60) 100%
        ) !important;

    border-color:
        #a78bfa !important;

    box-shadow:
        inset 4px 0 0 #7c3aed,
        0 8px 22px rgba(91,33,182,.16),
        0 0 15px rgba(124,58,237,.16) !important;
}


    </style>
</head>
<body>

<div class="container-scroll">
<div class="sidebar" id="dashboardSidebar">
    <?php include('partials/sidebar.php'); ?>
</div>

    <div class="main-panel">
        <div class="topbar">
            <div class="topbar-wrap">
                <?php include('partials/topbar.php'); ?>
            </div>

            <div class="topbar-right">
                <button class="icon-btn" type="button">🔔</button>
                <div class="user-pill">
                    <div class="avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?></div>
                    <div><?= esc($_SESSION['name'] ?? 'User') ?></div>
                </div>
            </div>
        </div>

        <?php
        /*
        |--------------------------------------------------------------------------
        | Speed: release PHP session lock
        |--------------------------------------------------------------------------
        | Sidebar/topbar aur user session values read ho chuki hain. Ab POS AJAX
        | requests ko page request ke session lock ka wait nahi karna padega.
        */
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ?>

  <div class="content-wrapper">
            <div class="pos-layout">

                <div class="items-area">
                    <div class="toolbar">
                        <button class="type-btn active" data-type="Retailer">Retailer</button>

                        <?php if ($hasWholesaleCustomer): ?>
                            <div class="type-buttons">
                                <button class="type-btn" data-type="Wholesale1">Wholesale 1</button>
                                <button class="type-btn" data-type="Wholesale2">Wholesale 2</button>
                                <button class="type-btn" data-type="Wholesale3">Wholesale 3</button>
                            </div>
                        <?php endif; ?>

                        <div class="selected-label" id="selectedTypeLabel">RETAILER</div>
                                   <div class="search-wrap pos-search-highlight">

    <div class="pos-search-caption">
        <span>PRODUCT SEARCH</span>
        <small>Search by name, SKU or scan barcode</small>
    </div>

    <div class="pos-search-controls">

        <span class="pos-search-leading-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="18" height="18">
                <circle cx="11" cy="11" r="7"></circle>
                <path d="M16.5 16.5L21 21"></path>
            </svg>
        </span>

        <input
            type="text"
            id="productSearch"
            class="search-input pos-visible-search-input"
            placeholder="Search products here..."
            autocomplete="off"
        >

        <button
            type="button"
            class="pos-search-scan-btn"
            title="Scan Barcode (F3)"
            aria-label="Scan Barcode"
            onclick="
                const productSearchInput = document.getElementById('productSearch');
                if (productSearchInput) {
                    productSearchInput.focus();
                    productSearchInput.select();
                }
            "
        >
            <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true">
                <path d="M3 5V19"></path>
                <path d="M6 5V19"></path>
                <path d="M10 5V19"></path>
                <path d="M13 5V19"></path>
                <path d="M17 5V19"></path>
                <path d="M21 5V19"></path>
            </svg>

            <span>Scan</span>
            <kbd>F3</kbd>
        </button>

    </div>
</div>
                    </div>


                    <div class="items-grid" id="itemsGrid">
                        <?php foreach ($items as $i): ?>
                            <?php
                           $imageName = trim((string)($i['image'] ?? ''));

if ($imageName !== '' && preg_match('/^https?:\/\//i', $imageName)) {
    $imgSrc = $imageName;
} elseif ($imageName !== '') {
    // Browser onerror handles missing local files; avoids one file_exists() per product.
    $imgSrc = '/uploads/items/' . rawurlencode($imageName);
} else {
    $imgSrc = $posPlaceholderImage;
}
                                $sku = $i['barcode'] ?? ($i['sku'] ?? ('ITEM-' . ($i['id'] ?? 0)));
                                $itemType = $i['resolved_item_type'] ?? 'product';
                                $itemTaxRate = (float)($i['resolved_tax_rate'] ?? 0);

                                $originalPrice = (float)($i['price'] ?? 0);

                                /*
                                |--------------------------------------------------------------------------
                                | Maximum Discount Limit
                                |--------------------------------------------------------------------------
                                | POS product price original retail price hi rahegi.
                                | Discount auto apply nahi hoga. Ye fields sirf cart/invoice
                                | discount ko item-wise max limit dene ke liye hain.
                                */
                                $retailPrice = $originalPrice;
                                $hasMaxDiscount = isset($i['has_max_discount'])
                                    ? (int)$i['has_max_discount']
                                    : (int)($i['has_discount'] ?? 0);

                                $maxDiscountPercent = isset($i['max_discount_percent'])
                                    ? (float)$i['max_discount_percent']
                                    : (float)($i['discount_value'] ?? 0);

                                if ($maxDiscountPercent < 0) {
                                    $maxDiscountPercent = 0;
                                }

                                if ($maxDiscountPercent > 100) {
                                    $maxDiscountPercent = 100;
                                }

                                if ($hasMaxDiscount !== 1 || $maxDiscountPercent <= 0) {
                                    $hasMaxDiscount = 0;
                                    $maxDiscountPercent = 0;
                                }

                                $maxDiscountLabel = rtrim(rtrim(number_format($maxDiscountPercent, 2), '0'), '.') . '%';
                                
                                
                                $productUnits = [];
$defaultUnit = null;
$cardRetailPrice = $retailPrice;

if ($isPharmacyRetailClient) {
    $productUnits = $itemProductUnits[(int)($i['id'] ?? 0)] ?? [];

    foreach ($productUnits as $unitRow) {
        if ((int)($unitRow['is_default'] ?? 0) === 1) {
            $defaultUnit = $unitRow;
            break;
        }
    }

    if (!$defaultUnit && !empty($productUnits)) {
        $defaultUnit = $productUnits[0];
    }

    if ($defaultUnit && isset($defaultUnit['sale_price'])) {
        $cardRetailPrice = (float)$defaultUnit['sale_price'];
    }
}
                            ?>
                            <div
                                class="item-card product-card"
                                data-id="<?= (int)$i['id'] ?>"
                                data-name="<?= esc($i['name'] ?? '') ?>"
                                data-search-text="<?= esc(trim(($i['name'] ?? '') . ' ' . $sku . ' ' . ($i['category_label'] ?? 'All'))) ?>"
                                data-category="<?= esc($i['category_label'] ?? 'All') ?>"
                                data-item-type="<?= esc($itemType) ?>"
                                data-tax-rate="<?= esc($itemTaxRate) ?>"
                               data-base-price="<?= esc($cardRetailPrice) ?>"
data-retail-price="<?= esc($cardRetailPrice) ?>"
data-original-price="<?= esc($cardRetailPrice) ?>"
                                data-has-discount="<?= esc($hasMaxDiscount) ?>"
                                data-discount-type="percent"
                                data-discount-value="<?= esc($maxDiscountPercent) ?>"
                                data-has-max-discount="<?= esc($hasMaxDiscount) ?>"
                                data-max-discount-percent="<?= esc($maxDiscountPercent) ?>"
                                data-wholesale-price-1="<?= esc($i['wholesaler_price_1'] ?? 0) ?>"
                                data-wholesale-price-2="<?= esc($i['wholesaler_price_2'] ?? 0) ?>"
                                data-wholesale-price-3="<?= esc($i['wholesaler_price_3'] ?? 0) ?>"
                                <?php if ($isPharmacyRetailClient && !empty($productUnits)): ?>
    data-has-unit-pricing="1"
    data-default-unit-id="<?= (int)($defaultUnit['id'] ?? 0) ?>"
    data-default-unit-name="<?= esc((string)($defaultUnit['unit_name'] ?? '')) ?>"
    data-default-unit-price="<?= esc((float)($defaultUnit['sale_price'] ?? $cardRetailPrice)) ?>"
    data-default-unit-conversion="<?= esc((float)($defaultUnit['conversion_qty'] ?? 1)) ?>"
<?php endif; ?>
                                onclick="addToCartFromCard(this, <?= (int)$i['id'] ?>, <?= (float)$itemTaxRate ?>, '<?= esc($itemType) ?>')"
                            >
                                <div class="item-img">
                                    <img
                                        src="<?= esc($imgSrc) ?>"
                                        alt="<?= esc($i['name'] ?? '') ?>"
                                        loading="lazy"
                                        decoding="async"
                                        fetchpriority="low"
                                        width="220"
                                        height="220"
                                        onerror="this.onerror=null;this.src='<?= esc($posPlaceholderImage) ?>';"
                                    >
                                </div>

                                <div class="item-title"><?= esc($i['name'] ?? '') ?></div>
<div class="item-sku-wrap">
    <button type="button" class="sku-mini-btn" onclick="event.stopPropagation(); toggleCardSku(this);">
        SKU
    </button>
    <div class="item-sku sku-hidden"><?= esc($sku) ?></div>
</div>

<?php if ($itemTaxRate > 0): ?>
                                    <div class="item-tax-badge">Tax <?= number_format($itemTaxRate, 2) ?>%</div>
                                <?php endif; ?>

                                <div class="item-bottom">
                                    <div class="item-price" data-card-price="1">
                                        <small>Rs</small>
<span class="card-price-value">
    <?= number_format((float)$cardRetailPrice, 2, '.', ',') ?>
</span>
                                        <?php if ($hasMaxDiscount === 1 && $maxDiscountPercent > 0): ?>
                                            <div class="item-discount-badge">Max <?= esc($maxDiscountLabel) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="add-btn">+</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="posProductsLoader" class="pos-products-loader" hidden>Loading products…</div>
                    <!-- Items Area Sticky Bottom Actions -->
<div class="pos-items-sticky-actions">
    <button type="button" class="pos-items-sticky-btn" onclick="holdSaleShortcut()">
        <span class="pos-items-btn-left">
            <span class="pos-items-btn-icon">↺</span>
            <span>Hold Sale</span>
        </span>
        <kbd>F6</kbd>
    </button>

    <button type="button" class="pos-items-sticky-btn danger" onclick="clearCartShortcut()">
        <span class="pos-items-btn-left">
            <span class="pos-items-btn-icon">🗑</span>
            <span>Clear Cart</span>
        </span>
        <kbd>F9</kbd>
    </button>

    <button type="button" class="pos-items-sticky-btn" onclick="focusProductSearchShortcut()">
        <span class="pos-items-btn-left">
            <span class="pos-items-btn-icon">▥</span>
            <span>Scan Barcode</span>
        </span>
        <kbd>F3</kbd>
    </button>
</div>
                </div>

             <div class="cart-area pos-premium-cart">

    <!-- Customer Header -->
    <div class="pos-cart-customer-header">
        <div class="pos-cart-customer-left">
            <div class="pos-cart-customer-icon">
                <span>♙</span>
            </div>

            <div class="pos-cart-customer-info">
                <h4 id="cartCustomerTitle">Retailer Customer</h4>
                <small>Current billing customer</small>
            </div>
        </div>

        <button class="pos-cart-change-btn" type="button" onclick="openCurrentCustomerSelector()">
            Change
        </button>
    </div>

    <!-- Phone Input -->
    <div class="pos-cart-phone-box">
        <span class="pos-phone-icon">☎</span>
        <input
            type="text"
            class="pos-cart-phone-input"
            id="selectedCustomerPhoneInput"
            placeholder="Enter phone number (optional)"
            readonly
        >
        <a
            href="#"
            class="pos-cart-phone-wa"
            id="selectedCustomerWhatsappLink"
            target="_blank"
            rel="noopener"
            style="display:none;"
        >WhatsApp</a>
    </div>

    <!-- Hidden old customer box for JS compatibility -->
    <div class="customer-box d-none">
        <div><strong>Customer:</strong> <span id="selectedCustomerName">Retailer / Walk-In</span></div>
        <div class="muted mt-1"><small id="selectedCustomerPhone">No phone</small></div>
    </div>

    <!-- Keep existing cart search for JS compatibility, hidden by CSS -->
    <input type="text" class="cart-search" placeholder="Search or scan product..." id="cartSearch">

  <!-- Invoice Header -->
<div class="pos-cart-invoice-header">
    <div class="pos-cart-invoice-left">
        <span>Invoice #</span>
        <strong id="cartPreviewInvoiceNo">INV-000000</strong>
    </div>

    <div class="pos-cart-invoice-right">
        <span>Date &amp; Time</span>
        <strong id="cartPreviewDateTime">--</strong>
    </div>
</div>

    <!-- Table Head -->
<div class="pos-cart-table-head pos-cart-table-head-cart-units">
    <div>Item</div>
    <div>Unit</div>
    <div>Qty</div>
    <div>Unit Price</div>
    <div>Discount</div>
    <div>Total</div>
    <div></div>
</div>

    <!-- Dynamic Cart Items -->
    <div class="cart-items pos-cart-items-list" id="cart">
        <div class="empty-state pos-cart-empty">Cart is empty</div>
    </div>
<!-- Add Item + Billing Summary Inline -->
<div class="pos-ref-bottom-aligned">

    <!-- Left: Add Item -->
    <div class="pos-add-custom-wrap pos-ref-add-inline">
        <button type="button" class="pos-add-custom-main pos-ref-add-inline-btn" onclick="toggleCustomProductForm()">
            <span class="pos-add-custom-icon">+</span>
            <span>Add Item</span>
        </button>
    </div>

    <!-- Right: Billing Summary -->
    <div class="summary pos-billing-summary pos-ref-summary pos-ref-summary-inline">
        <div class="summary-row pos-summary-row">
            <span>Subtotal</span>
            <strong id="sumSubtotal">Rs 0</strong>
        </div>

        <div class="summary-row pos-summary-row pos-discount-row">
            <span>Discount (Order)</span>

            <div class="pos-ref-discount-control pos-ref-discount-simple">
                <div class="pos-ref-discount-box">
                    <input
                        type="number"
                        class="field-dark pos-hidden-discount"
                        id="discount"
                        placeholder="0"
                        min="0"
                        step="0.01"
                    >
                    <span class="pos-ref-percent-pill">%</span>
                </div>
            </div>
        </div>

        <div class="summary-row pos-summary-row">
            <span>Tax (GST 17%)</span>
            <strong id="sumTax">Rs 0</strong>
        </div>

        <input type="number" class="field-dark d-none" id="tax" placeholder="Tax %" min="0" step="0.01">

      
    </div>

    <!-- Custom Product Form -->
    <div class="pos-custom-product-form pos-ref-custom-inline-form">
        <input
            type="text"
            id="customProductName"
            class="pos-custom-input"
            placeholder="Item name"
        >

        <input
            type="number"
            id="customProductPrice"
            class="pos-custom-input"
            placeholder="Price"
            min="0"
            step="0.01"
        >

        <input
            type="number"
            id="customProductQty"
            class="pos-custom-input"
            placeholder="Qty"
            min="1"
            step="1"
            value="1"
        >

        <button type="button" class="pos-custom-add-btn" onclick="addCustomProduct()">
            Add
        </button>
    </div>
</div>

<!-- Total Payable -->
<div class="pos-payment-title pos-payment-total-title">
    <span>Total Payable</span>
    <strong id="sumGrand">Rs 0</strong>
</div>

<!-- Bottom Actions -->
<div class="pos-invoice-actions-row">

    <!-- Save & Hold -->
    <button
        type="button"
        class="pos-invoice-action-btn pos-invoice-save-btn"
        onclick="holdSaleShortcut()"
    >
        <span>Save &amp; Hold</span>
        <kbd>F6</kbd>
    </button>

    <!-- Cash Payment -->
    <button
        type="button"
        class="pay-btn pos-invoice-action-btn pos-bottom-payment-btn pos-bottom-cash-btn active"
        data-pay="cash"
    >
        <span class="pos-bottom-pay-icon">💵</span>
        <span>Cash</span>
    </button>

    <!-- Card Payment -->
    <button
        type="button"
        class="pay-btn pos-invoice-action-btn pos-bottom-payment-btn pos-bottom-card-btn"
        data-pay="card"
    >
        <span class="pos-bottom-pay-icon">💳</span>
        <span>Card</span>
    </button>

    <!-- Complete Sale -->
    <button
        type="button"
        class="invoice-btn pos-proceed-btn pos-complete-sale-btn"
        onclick="completeSaleShortcut()"
    >
        <span>Complete Sale</span>
        <kbd>F12</kbd>
    </button>

</div>



</div>
            </div>
        </div>
</div>



<div class="modal fade" id="priceTierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content tier-modal-card">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="priceTierModalTitle">Select Price</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="priceTierOptions"></div>
                <button type="button" class="tier-option-btn" id="defaultTierBtn">
                    <span>Default Price</span>
                    <span class="tier-option-price">Rs 0</span>
                </button>
            </div>
        </div>
    </div>
</div>


<!-- =====================================================
     PRODUCT UNIT SELECTION MODAL
===================================================== -->
<div class="modal fade" id="productUnitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">

        <div class="modal-content product-unit-modal-card">

            <div class="modal-header border-0">
                <div>
                    <h5 class="modal-title" id="productUnitModalTitle">
                        Select Unit
                    </h5>

                    <small class="product-unit-modal-subtitle">
                        Select the unit to add into cart
                    </small>
                </div>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
            </div>

            <div class="modal-body pt-1">
                <div id="productUnitOptions"></div>
            </div>

        </div>

    </div>
</div>

<div class="modal fade" id="custModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:#131b2b;color:#fff;border:1px solid rgba(255,255,255,.08);border-radius:20px;">
            <div class="modal-header border-0">
                <h5 class="modal-title">Select Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input
                    type="text"
                    id="searchCustomer"
                    class="form-control mb-3"
                    placeholder="Search customer..."
                    style="background:#0f1625;border:1px solid rgba(255,255,255,.08);color:#fff;border-radius:12px;"
                >
                <div id="custList" class="list-group" style="max-height:360px;overflow:auto;"></div>
            </div>
        </div>
    </div>
</div>



<div class="modal fade" id="walkinCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:#131b2b;color:#fff;border:1px solid rgba(255,255,255,.08);border-radius:20px;">
            <div class="modal-header border-0">
                <h5 class="modal-title">Retailer / Walk-In Customer Info</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Customer Name</label>
                <input
                    type="text"
                    id="walkinCustomerName"
                    class="form-control mb-3"
                    placeholder="Customer Name"
                    style="background:#0f1625;border:1px solid rgba(255,255,255,.08);color:#fff;border-radius:12px;"
                >

                <label class="form-label">WhatsApp / Phone Number</label>
                <input
                    type="text"
                    id="walkinCustomerPhone"
                    class="form-control"
                    placeholder="03xxxxxxxxx"
                    style="background:#0f1625;border:1px solid rgba(255,255,255,.08);color:#fff;border-radius:12px;"
                >

                <a href="#" id="walkinWhatsappPreview" class="walkin-wa-preview" target="_blank" rel="noopener">
                    Open WhatsApp Link
                </a>

                <div class="walkin-info-note">
                    Ye name/number is order ke customer me save hoga. Invoice/receipt ka WhatsApp isi number par send hoga.
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" onclick="skipRetailerCustomerInfo()">Skip</button>
                <button type="button" class="btn btn-primary" onclick="saveRetailerCustomerInfo()">Save Customer Info</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="newCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="save_customer.php" style="background:#131b2b;color:#fff;border:1px solid rgba(255,255,255,.08);border-radius:20px;">
            <div class="modal-header border-0">
                <h5 class="modal-title">Add New Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="text" name="name" class="form-control mb-3" placeholder="Customer Name" required style="background:#0f1625;border:1px solid rgba(255,255,255,.08);color:#fff;border-radius:12px;">
                <input type="text" name="phone" class="form-control mb-3" placeholder="Phone" style="background:#0f1625;border:1px solid rgba(255,255,255,.08);color:#fff;border-radius:12px;">
                <select name="type" class="form-select mb-3" style="background:#0f1625;border:1px solid rgba(255,255,255,.08);color:#fff;border-radius:12px;">
                    <option value="Retailer">Retailer</option>
                    <?php if ($hasWholesaleCustomer): ?>
                        <option value="Wholesaler">Wholesaler</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="modal-footer border-0">
                <button type="submit" class="btn btn-primary">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.ONEERP_POS_CONFIG = {
    customers: <?= json_encode($customers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    productTaxMap: <?= json_encode($productTaxMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    defaultProductTaxRate: <?= json_encode((float)$defaultProductTaxRate) ?>,
    hasWholesaleCustomer: <?= json_encode((bool)$hasWholesaleCustomer) ?>,
    pharmacyUnitEnabled: <?= json_encode((bool)$isPharmacyRetailClient) ?>,
    productUnitMap: <?= json_encode($itemProductUnits, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    productEndpoint: <?= json_encode($posProductsEndpoint, JSON_UNESCAPED_SLASHES) ?>,
    productPageSize: <?= (int)$posProductPageSize ?>,
    initialProductLastId: <?= (int)$posInitialLastId ?>,
    hasMoreProducts: <?= json_encode((bool)$posInitialHasMoreProducts) ?>
};
</script>
<script>
async function handleAjaxSession(response) {
    const text = await response.text();

    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    try {
        const data = JSON.parse(text);
        if (data && data.status === 'expired' && data.redirect) {
            window.location.href = data.redirect;
            return null;
        }
    } catch (e) {
        // Not JSON, normal cart HTML response.
    }

    if (text.includes('/onerp/app/onerp/app/public/app/views/login_view.php') || text.trim() === 'Login required') {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return text;
}

async function handleAjaxJsonSession(response) {
    const text = await response.text();

    if (response.status === 401) {
        try {
            const data = JSON.parse(text);
            window.location.href = data.redirect || '/onerp/app/onerp/app/public/app/views/login_view.php';
        } catch (e) {
            window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        }
        return null;
    }

    try {
        const data = JSON.parse(text);

        if (data && data.status === 'expired' && data.redirect) {
            window.location.href = data.redirect;
            return null;
        }

        return data;
    } catch (e) {
        throw new Error('Invalid JSON response: ' + text);
    }
}
    
const POS_CONFIG = window.ONEERP_POS_CONFIG || {};
const customers = POS_CONFIG.customers || {Retailer: [], Wholesaler: []};
const productTaxMap = POS_CONFIG.productTaxMap || {};
const defaultProductTaxRate = Number(POS_CONFIG.defaultProductTaxRate || 0);
const hasWholesaleCustomer = Boolean(POS_CONFIG.hasWholesaleCustomer);
const PHARMACY_UNIT_ENABLED = Boolean(POS_CONFIG.pharmacyUnitEnabled);
const PRODUCT_UNIT_MAP = POS_CONFIG.productUnitMap || {};

function isWholesaleMode(type) {
    return type === 'Wholesale1' || type === 'Wholesale2' || type === 'Wholesale3';
}

function guardWholesaleMode(type) {
    if (isWholesaleMode(type) && !hasWholesaleCustomer) {
        alert('Wholesale function is not available for this client.');
        return false;
    }
    return true;
}

let selectedType = 'Retailer';
let selectedCustomerId = '';
let selectedCustomerName = 'Retailer / Walk-In';
let selectedCustomerPhone = '';
let selectedPaymentMethod = 'cash';
let retailerCustomerInfoPromptShown = false;
let pendingRetailerCheckoutAfterCustomerInfo = false;
let activeCategory = 'All';
let isManualTaxOverride = false;
let lastTouchedCartId = null;

function getCartRowId(row) {
    if (!row) return '';

    if (row.dataset.id) return String(row.dataset.id);
    if (row.dataset.cartId) return String(row.dataset.cartId);

    const holder = row.querySelector('[data-id]');
    if (holder && holder.dataset.id) {
        return String(holder.dataset.id);
    }

    return '';
}

function arrangeCartLatestFirst() {
    const cart = document.getElementById('cart');
    if (!cart) return;

    const rows = Array.from(cart.querySelectorAll('[data-cart-item]'));
    if (!rows.length) return;

    if (lastTouchedCartId !== null && lastTouchedCartId !== '') {
        const touchedRow = rows.find(row => getCartRowId(row) === String(lastTouchedCartId));
        if (touchedRow) {
            cart.prepend(touchedRow);
        }
    }
}


/*
|--------------------------------------------------------------------------
| Animate only the product that has just been added
|--------------------------------------------------------------------------
*/
let cartArrivalAnimationTimer = null;

function animateLastTouchedCartItem() {

    const cart =
        document.getElementById('cart');

    const touchedCartId =
        String(
            lastTouchedCartId || ''
        );

    if (
        !cart ||
        touchedCartId === ''
    ) {
        return;
    }

    const cartRows =
        Array.from(
            cart.querySelectorAll(
                '[data-cart-item]'
            )
        );

    const touchedRow =
        cartRows.find(
            function (row) {
                return (
                    getCartRowId(row) ===
                    touchedCartId
                );
            }
        );

    if (!touchedRow) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Previous item animation remove
    |--------------------------------------------------------------------------
    */
    cart
        .querySelectorAll(
            '.cart-item-just-added'
        )
        .forEach(
            function (row) {
                row.classList.remove(
                    'cart-item-just-added'
                );
            }
        );

    if (cartArrivalAnimationTimer) {
        clearTimeout(
            cartArrivalAnimationTimer
        );

        cartArrivalAnimationTimer =
            null;
    }

    /*
    |--------------------------------------------------------------------------
    | Browser ko animation restart karne par force karein
    |--------------------------------------------------------------------------
    */
    touchedRow.classList.remove(
        'cart-item-just-added'
    );

    void touchedRow.offsetWidth;

    requestAnimationFrame(
        function () {

            touchedRow.classList.add(
                'cart-item-just-added'
            );

            /*
            |--------------------------------------------------------------------------
            | Naya item cart ke visible area mein rahe
            |--------------------------------------------------------------------------
            */
            touchedRow.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Animation ke baad class clean
    |--------------------------------------------------------------------------
    */
    cartArrivalAnimationTimer =
        setTimeout(
            function () {

                touchedRow.classList.remove(
                    'cart-item-just-added'
                );

                cartArrivalAnimationTimer =
                    null;
            },
            1250
        );
}
const custModal = new bootstrap.Modal(document.getElementById('custModal'));
const newCustomerModal = new bootstrap.Modal(document.getElementById('newCustomerModal'));
const priceTierModal = new bootstrap.Modal(document.getElementById('priceTierModal'));
const productUnitModalElement =
    document.getElementById('productUnitModal');

const productUnitModal = productUnitModalElement
    ? new bootstrap.Modal(productUnitModalElement)
    : null;

let pendingUnitProduct = null;

if (productUnitModalElement) {
    productUnitModalElement.addEventListener(
        'hidden.bs.modal',
        function () {
            pendingUnitProduct = null;

            const optionsBox =
                document.getElementById('productUnitOptions');

            if (optionsBox) {
                optionsBox.innerHTML = '';
            }
        }
    );
}
const walkinCustomerModalEl = document.getElementById('walkinCustomerModal');
if (walkinCustomerModalEl) {
    walkinCustomerModalEl.addEventListener('hidden.bs.modal', function () {
        if (!retailerCustomerInfoPromptShown) {
            pendingRetailerCheckoutAfterCustomerInfo = false;
        }
    });
}
let pendingTierProduct = null;

function openNewCustomerModal() {
    newCustomerModal.show();
}

function normalizeWhatsappPhoneForPos(phone) {
    let digits = String(phone || '').replace(/\D+/g, '');

    if (!digits) return '';

    if (digits.startsWith('00')) {
        digits = digits.substring(2);
    }

    if (digits.startsWith('0')) {
        digits = '92' + digits.substring(1);
    } else if (digits.length === 10 && digits.startsWith('3')) {
        digits = '92' + digits;
    }

    return digits;
}

function updateWalkinWhatsappPreview() {
    const phoneInput = document.getElementById('walkinCustomerPhone');
    const preview = document.getElementById('walkinWhatsappPreview');

    if (!phoneInput || !preview) return;

    const waPhone = normalizeWhatsappPhoneForPos(phoneInput.value || '');

    if (waPhone) {
        preview.href = 'https://wa.me/' + encodeURIComponent(waPhone);
        preview.style.display = 'inline-block';
    } else {
        preview.href = '#';
        preview.style.display = 'none';
    }
}

function openRetailerCustomerInfoPopup(force = false) {
    if (selectedType !== 'Retailer') return;
    if (selectedCustomerId && !force) return;

    const modalEl = document.getElementById('walkinCustomerModal');
    if (!modalEl) return;

    const nameInput = document.getElementById('walkinCustomerName');
    const phoneInput = document.getElementById('walkinCustomerPhone');

    if (nameInput) {
        const currentName = (selectedCustomerName || '').trim();
        nameInput.value = (currentName && currentName !== 'Retailer / Walk-In') ? currentName : '';
    }

    if (phoneInput) {
        phoneInput.value = selectedCustomerPhone || '';
    }

    updateWalkinWhatsappPreview();
    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    setTimeout(() => {
        if (nameInput) nameInput.focus();
    }, 250);
}

function saveRetailerCustomerInfo() {
    const modalEl = document.getElementById('walkinCustomerModal');
    const nameInput = document.getElementById('walkinCustomerName');
    const phoneInput = document.getElementById('walkinCustomerPhone');

    const customerName = (nameInput?.value || '').trim();
    const customerPhone = (phoneInput?.value || '').trim();
    const shouldContinueCheckout = pendingRetailerCheckoutAfterCustomerInfo;

    selectedCustomerId = '';
    selectedCustomerName = customerName || 'Retailer / Walk-In';
    selectedCustomerPhone = customerPhone;
    retailerCustomerInfoPromptShown = true;
    pendingRetailerCheckoutAfterCustomerInfo = false;

    updateSelectedCustomerBox();

    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }

    if (shouldContinueCheckout) {
        setTimeout(() => checkout(), 250);
    }
}

function skipRetailerCustomerInfo() {
    const shouldContinueCheckout = pendingRetailerCheckoutAfterCustomerInfo;
    retailerCustomerInfoPromptShown = true;
    pendingRetailerCheckoutAfterCustomerInfo = false;

    const modalEl = document.getElementById('walkinCustomerModal');

    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }

    if (shouldContinueCheckout) {
        setTimeout(() => checkout(), 250);
    }
}

function maybeOpenRetailerCustomerInfoPopupAfterAdd() {
    /*
    |--------------------------------------------------------------------------
    | Product add par popup nahi dikhana.
    | Customer info popup ab sirf Proceed To Invoice click ke baad aayega.
    |--------------------------------------------------------------------------
    */
    return;
}

function getCustomerLookupType(type) {
    if (isWholesaleMode(type)) {
        if (!hasWholesaleCustomer) {
            return 'Retailer';
        }
        return 'Wholesaler';
    }

    return type;
}

function getCheckoutCustomerType() {
    if (isWholesaleMode(selectedType)) {
        if (!hasWholesaleCustomer) {
            return 'Retailer';
        }
        return 'Wholesaler';
    }

    return 'Retailer';
}

function getDisplayTypeLabel(type) {
    if (type === 'Wholesale1') return 'WHOLESALE 1';
    if (type === 'Wholesale2') return 'WHOLESALE 2';
    if (type === 'Wholesale3') return 'WHOLESALE 3';
    if (type === 'Retailer') return 'RETAILER';
    return String(type || '').toUpperCase();
}

function getDefaultCustomerName(type) {
    if (isWholesaleMode(type)) {
        return getDisplayTypeLabel(type) + ' Customer';
    }

    return 'Retailer / Walk-In';
}

function openCurrentCustomerSelector() {
    if (selectedType === 'Retailer') {
        openRetailerCustomerInfoPopup(true);
        return;
    }

    const customerType = getCustomerLookupType(selectedType);
    loadCustomers(customerType, selectedType);
    custModal.show();
}

function clearAllCartItems() {
    const ids = Array.from(document.querySelectorAll('[data-cart-remove]')).map(btn => btn.dataset.id).filter(Boolean);

    if (!ids.length) {
        loadCart();
        return;
    }

    if (!confirm('Clear all cart items?')) return;

    let chain = Promise.resolve();
    ids.forEach(id => {
        chain = chain.then(() => fetch('ajax_add_to_cart.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=remove&id=${encodeURIComponent(id)}`
        }).then(handleAjaxSession));
    });

    chain.then(result => {
        if (result === null) return;
        loadCart();
    });
}


function getPriceForSelectedType(card) {
    const originalPrice = parseFloat(card.dataset.originalPrice || card.dataset.retailPrice || card.dataset.basePrice || 0);
    const retailPrice = originalPrice;

    if (!hasWholesaleCustomer) {
        return retailPrice;
    }

    const wholesale1 = parseFloat(card.dataset.wholesalePrice1 || 0);
    const wholesale2 = parseFloat(card.dataset.wholesalePrice2 || 0);
    const wholesale3 = parseFloat(card.dataset.wholesalePrice3 || 0);

    if (selectedType === 'Wholesale1') {
        return wholesale1 > 0 ? wholesale1 : originalPrice;
    }

    if (selectedType === 'Wholesale2') {
        return wholesale2 > 0 ? wholesale2 : originalPrice;
    }

    if (selectedType === 'Wholesale3') {
        return wholesale3 > 0 ? wholesale3 : originalPrice;
    }

    return retailPrice;
}


function getPriceTierMetaForSelectedType() {
    if (!hasWholesaleCustomer) {
        return { tierType: 'default', tierNo: 0, tierLabel: 'Default' };
    }

    if (selectedType === 'Wholesale1') {
        return { tierType: 'wholesale', tierNo: 1, tierLabel: 'Wholesale 1' };
    }

    if (selectedType === 'Wholesale2') {
        return { tierType: 'wholesale', tierNo: 2, tierLabel: 'Wholesale 2' };
    }

    if (selectedType === 'Wholesale3') {
        return { tierType: 'wholesale', tierNo: 3, tierLabel: 'Wholesale 3' };
    }

    return { tierType: 'default', tierNo: 0, tierLabel: 'Default' };
}

function updateProductDisplayedPrices() {
    document.querySelectorAll('.product-card').forEach(card => {
        const price = getPriceForSelectedType(card);
        const holder = card.querySelector('.card-price-value');
        const oldPrice = card.querySelector('.item-old-price');
        const discountBadge = card.querySelector('.item-discount-badge');

        if (holder) {
           holder.textContent = formatRefMoney(price, 2);
        }

        if (oldPrice) {
            oldPrice.style.display = 'none';
        }

        if (discountBadge) {
            discountBadge.style.display = selectedType === 'Retailer' ? '' : 'none';
        }
    });
}

function updateSelectedCustomerBox() {
    const defaultName = getDefaultCustomerName(selectedType);
    document.getElementById('selectedCustomerName').textContent = selectedCustomerName || defaultName;
    document.getElementById('selectedCustomerPhone').textContent = selectedCustomerPhone ? selectedCustomerPhone : (selectedType === 'Retailer' ? 'Walk-in / optional customer' : 'No phone');
    document.getElementById('cartCustomerTitle').textContent = selectedCustomerName || defaultName;
    document.getElementById('selectedTypeLabel').textContent = getDisplayTypeLabel(selectedType);

    const phoneInput = document.getElementById('selectedCustomerPhoneInput');
    if (phoneInput) {
        phoneInput.value = selectedCustomerPhone || '';
        phoneInput.placeholder = selectedCustomerPhone ? '' : 'Enter phone number (optional)';
    }

    const waLink = document.getElementById('selectedCustomerWhatsappLink');
    if (waLink) {
        const waPhone = normalizeWhatsappPhoneForPos(selectedCustomerPhone || '');

        if (waPhone) {
            waLink.href = 'https://wa.me/' + encodeURIComponent(waPhone);
            waLink.style.display = 'inline-flex';
        } else {
            waLink.href = '#';
            waLink.style.display = 'none';
        }
    }
}
updateSelectedCustomerBox();

document.querySelectorAll('.type-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const requestedType = this.dataset.type;

        if (!guardWholesaleMode(requestedType)) {
            return;
        }

        document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');

        selectedType = requestedType;
        updateProductDisplayedPrices();

        selectedCustomerId = '';
        selectedCustomerName = getDefaultCustomerName(selectedType);
        selectedCustomerPhone = '';
        retailerCustomerInfoPromptShown = false;
        updateSelectedCustomerBox();

        /*
        |--------------------------------------------------------------------------
        | Retailer = Walk-In sale + Retail customer
        |--------------------------------------------------------------------------
        | Retailer mode me customer select karna optional hai.
        | Button click par customer modal force open nahi hoga.
        | Change button se retailer customer optional select ho sakta hai.
        */
        if (selectedType === 'Retailer') {
            updateWalkInCartScroller();
            return;
        }

        const customerType = getCustomerLookupType(selectedType);
        loadCustomers(customerType, selectedType);
        custModal.show();
        updateWalkInCartScroller();
    });
});

function loadCustomers(type, selectedMode = type) {
    const list = document.getElementById('custList');
    list.innerHTML = '';

    const rows = customers[type] || [];

    if (!rows.length) {
        list.innerHTML = `<div class="text-secondary p-3">No ${type} customers found.</div>`;
        return;
    }

    rows.forEach(c => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action';
        item.style.background = '#0f1625';
        item.style.color = '#fff';
        item.style.border = '1px solid rgba(255,255,255,.06)';
        item.style.marginBottom = '8px';
        item.style.borderRadius = '12px';
        item.innerHTML = `
            <div class="fw-bold">${escapeHtml(c.name || '')}</div>
            <small class="text-secondary">${escapeHtml(c.phone || '')}</small>
        `;
        item.onclick = function () {
            selectedCustomerId = c.id || '';
            selectedCustomerName = c.name || getDefaultCustomerName(selectedMode);
            selectedCustomerPhone = c.phone || '';
            retailerCustomerInfoPromptShown = true;
            updateSelectedCustomerBox();
            updateWalkInCartScroller();
            custModal.hide();
        };
        list.appendChild(item);
    });
}

document.getElementById('walkinCustomerPhone')?.addEventListener('input', updateWalkinWhatsappPreview);
document.getElementById('walkinCustomerName')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        saveRetailerCustomerInfo();
    }
});
document.getElementById('walkinCustomerPhone')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        saveRetailerCustomerInfo();
    }
});

document.getElementById('searchCustomer').addEventListener('keyup', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#custList .list-group-item').forEach(li => {
        li.style.display = li.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});

const categoryButtons = Array.from(document.querySelectorAll('.category-btn'));
const productSearchInput = document.getElementById('productSearch');
const productsGrid = document.getElementById('itemsGrid');
const productsLoader = document.getElementById('posProductsLoader');
const POS_PRODUCTS_ENDPOINT = String(POS_CONFIG.productEndpoint || 'pos_products_scroll_api.php');
let posProductsLastId = Number(POS_CONFIG.initialProductLastId || 0);
let posProductsHaveMore = Boolean(POS_CONFIG.hasMoreProducts);
let posProductsLoading = false;
let posProductsRequestSerial = 0;
let posProductsAbortController = null;
let posProductsSearchTimer = 0;

function setProductsLoadingState(isLoading) {
    posProductsLoading = Boolean(isLoading);

    if (productsLoader) {
        productsLoader.hidden = !posProductsLoading;
    }
}

function updateLoadedProductCount() {
    const productCountEl = document.getElementById('productCount');

    if (productCountEl && productsGrid) {
        productCountEl.textContent = productsGrid.querySelectorAll('.product-card').length;
    }
}

async function loadPosProducts(reset = false) {
    if (!productsGrid) {
        return;
    }

    if (!reset && (posProductsLoading || !posProductsHaveMore)) {
        return;
    }

    if (reset && posProductsAbortController) {
        posProductsAbortController.abort();
    }

    const requestSerial = ++posProductsRequestSerial;
    const controller = new AbortController();
    posProductsAbortController = controller;

    const query = productSearchInput ? productSearchInput.value.trim() : '';
    const category = String(activeCategory || 'All');
    const lastId = reset ? 0 : posProductsLastId;

    if (reset) {
        posProductsHaveMore = true;
    }

    setProductsLoadingState(true);

    try {
        const endpointUrl = new URL(POS_PRODUCTS_ENDPOINT, window.location.origin);
        endpointUrl.searchParams.set('last_id', String(lastId));
        endpointUrl.searchParams.set('q', query);
        endpointUrl.searchParams.set('category', category);
        endpointUrl.searchParams.set('pharmacy', PHARMACY_UNIT_ENABLED ? '1' : '0');
        endpointUrl.searchParams.set('_', String(Date.now()));

        const response = await fetch(endpointUrl.toString(), {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store',
            signal: controller.signal
        });

        const payload = await handleAjaxJsonSession(response);

        if (!payload || requestSerial !== posProductsRequestSerial) {
            return;
        }

        if (!response.ok || payload.ok !== true) {
            throw new Error(payload.message || 'Products could not be loaded.');
        }

        if (reset) {
            productsGrid.innerHTML = payload.html || '';
            productsGrid.scrollTop = 0;
        } else if (payload.html) {
            productsGrid.insertAdjacentHTML('beforeend', payload.html);
        }

        Object.assign(productTaxMap, payload.productTaxMap || {});
        Object.assign(PRODUCT_UNIT_MAP, payload.productUnitMap || {});

        posProductsLastId = Number(payload.lastId || 0);
        posProductsHaveMore = Boolean(payload.hasMore);

        updateLoadedProductCount();

        // Current Retailer/Wholesale tier price newly loaded cards par apply karo.
        if (typeof updateProductDisplayedPrices === 'function') {
            updateProductDisplayedPrices();
        }
    } catch (error) {
        if (error && error.name === 'AbortError') {
            return;
        }

        console.error('POS products load error:', error);
    } finally {
        if (requestSerial === posProductsRequestSerial) {
            setProductsLoadingState(false);
        }
    }
}

function scheduleServerProductSearch() {
    clearTimeout(posProductsSearchTimer);

    posProductsSearchTimer = window.setTimeout(function () {
        loadPosProducts(true);
    }, 220);
}

categoryButtons.forEach(btn => {
    btn.addEventListener('click', function () {
        categoryButtons.forEach(button => button.classList.remove('active'));
        this.classList.add('active');
        activeCategory = this.dataset.category || 'All';
        loadPosProducts(true);
    });
});

if (productSearchInput) {
    productSearchInput.addEventListener('input', scheduleServerProductSearch, {passive: true});
}

if (productsGrid) {
    productsGrid.addEventListener('scroll', function () {
        const remaining = productsGrid.scrollHeight - productsGrid.scrollTop - productsGrid.clientHeight;

        if (remaining <= 320) {
            loadPosProducts(false);
        }
    }, {passive: true});
}

window.posReloadProducts = function () {
    return loadPosProducts(true);
};

updateLoadedProductCount();

document.getElementById('cartSearch').addEventListener('input', filterCartItems);

function filterCartItems() {
    const q = document.getElementById('cartSearch').value.toLowerCase().trim();
    const items = document.querySelectorAll('#cart [data-cart-item]');
    let visible = 0;

    items.forEach(item => {
        const name = (item.dataset.name || '').toLowerCase();
        const sku = (item.dataset.sku || '').toLowerCase();
        const barcode = (item.dataset.barcode || '').toLowerCase();

        const matched =
            !q ||
            name.includes(q) ||
            sku.includes(q) ||
            barcode.includes(q);

        item.style.display = matched ? '' : 'none';

        if (matched) {
            visible++;
        }
    });

    const emptyState = document.querySelector('#cart .empty-state');
    if (!visible && items.length > 0 && !emptyState) {
        const msg = document.createElement('div');
        msg.className = 'empty-state cart-search-empty';
        msg.textContent = 'No matching cart item found';
        document.getElementById('cart').appendChild(msg);
    } else {
        const oldMsg = document.querySelector('#cart .cart-search-empty');
        if (oldMsg) oldMsg.remove();
    }
}

function updateWalkInCartScroller() {
    const cart = document.getElementById('cart');
    if (!cart) return;
    cart.classList.remove('walkin-scroll');
}
function setAutoTaxField(rate) {
    const taxEl = document.getElementById('tax');
    if (!taxEl) return;

    taxEl.placeholder = 'Tax %';
    if (rate > 0) {
        taxEl.placeholder = 'Tax % (Auto: ' + Number(rate).toFixed(2) + '%)';
    }

    if (!isManualTaxOverride) {
        taxEl.value = rate > 0 ? Number(rate).toFixed(2) : '';
    }
}

function detectCartAutoTaxRate() {
    let highestTaxRate = 0;

    document.querySelectorAll('[data-cart-item]').forEach(item => {
        const itemType = (item.dataset.itemType || '').toLowerCase();
        const sourceId = parseInt(item.dataset.sourceId || 0, 10);
        let taxRate = parseFloat(item.dataset.taxRate || 0);

        if ((!taxRate || taxRate <= 0) && sourceId > 0 && productTaxMap[sourceId]) {
            taxRate = parseFloat(productTaxMap[sourceId].tax_rate || 0);
        }

        if (itemType === 'product' && taxRate > highestTaxRate) {
            highestTaxRate = taxRate;
        }
    });

    if (highestTaxRate <= 0) {
        highestTaxRate = defaultProductTaxRate;
    }

    return highestTaxRate;
}

function findProductImageForCartRow(row) {
    const sourceId = row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || '';
    let img = '';

    if (sourceId) {
        const card = document.querySelector(`.product-card[data-id="${CSS.escape(String(sourceId))}"]`);
        const cardImg = card ? card.querySelector('.item-img img') : null;

        if (cardImg) {
            img = cardImg.getAttribute('src') || '';
        }
    }

    if (!img) {
        const rowName = (row.dataset.name || '').trim().toLowerCase();

        if (rowName) {
            document.querySelectorAll('.product-card').forEach(card => {
                if (img) return;

                const cardName = (card.dataset.name || '').trim().toLowerCase();

                if (cardName === rowName) {
                    const cardImg = card.querySelector('.item-img img');

                    if (cardImg) {
                        img = cardImg.getAttribute('src') || '';
                    }
                }
            });
        }
    }

    return img || 'https://via.placeholder.com/80x80?text=Item';
}

function formatRefMoney(value, decimals = 0) {
    value = Number(value || 0);
    if (!Number.isFinite(value)) value = 0;

    return value.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function getRefBatchValue(row) {
    const sku = (row.dataset.sku || '').trim();
    const barcode = (row.dataset.barcode || '').trim();

    if (barcode) return barcode;
    if (sku) return sku;

    return '—';
}

function ensureRefCell(row, selector, className) {
    let cell = row.querySelector(selector);

    if (!cell) {
        cell = document.createElement('div');
        cell.className = className;
        row.appendChild(cell);
    }

    return cell;
}

window.__posLineDiscounts = window.__posLineDiscounts || {};

function getCartRowKey(row) {
    if (!row) return '';

    return (
        row.dataset.id ||
        row.getAttribute('data-id') ||
        row.dataset.cartId ||
        row.getAttribute('data-cart-id') ||
        row.dataset.sourceId ||
        row.dataset.itemId ||
        row.dataset.productId ||
        ''
    );
}
function normalizePercentValue(value) {
    let percent = parseFloat(value || 0);

    if (!Number.isFinite(percent) || percent < 0) {
        percent = 0;
    }

    if (percent > 100) {
        percent = 100;
    }

    return percent;
}

function formatPercentInputValue(value) {
    value = normalizePercentValue(value);

    return Number.isInteger(value) ? String(value) : value.toFixed(2);
}

function updateReferenceLineDiscountPreview() {
    const discountInput = document.getElementById('discount');
    const requestedDiscount = normalizePercentValue(discountInput?.value || 0);

    document.querySelectorAll('#cart [data-cart-item]').forEach(row => {
        const qtyInput = row.querySelector('[data-cart-qty-input]');
        const priceInput = row.querySelector('[data-cart-price-input]');

        const qty = Math.max(1, parseFloat(qtyInput?.value || row.dataset.qty || 1) || 1);
        const price = Math.max(0, parseFloat(priceInput?.value || 0) || 0);
        const lineSubtotal = qty * price;

        const discountCell = row.querySelector('.pos-ref-discount-cell');
        if (!discountCell) return;

        const input = discountCell.querySelector('.pos-line-discount-input');
        const text = discountCell.querySelector('.pos-line-discount-text');

        const rowKey = getCartRowKey(row);
        const savedManualDiscount = rowKey && window.__posLineDiscounts
            ? window.__posLineDiscounts[rowKey]
            : undefined;

        let appliedDiscount;

        if (input && (input.dataset.manualDiscount === '1' || savedManualDiscount !== undefined)) {
            appliedDiscount = normalizePercentValue(savedManualDiscount !== undefined ? savedManualDiscount : input.value);
            input.dataset.manualDiscount = '1';
        } else {
            const hasMaxDiscount = String(row.dataset.hasMaxDiscount || '0') === '1';
            const maxDiscountPercent = parseFloat(row.dataset.maxDiscountPercent || 0) || 0;

            appliedDiscount = requestedDiscount;

            if (hasMaxDiscount && maxDiscountPercent > 0) {
                appliedDiscount = Math.min(requestedDiscount, maxDiscountPercent);
            }
        }

        const discountAmount = lineSubtotal * (appliedDiscount / 100);

        row.dataset.appliedDiscountPercent = appliedDiscount.toFixed(2);
        row.dataset.lineDiscount = discountAmount.toFixed(2);

        if (input) {
            input.value = formatPercentInputValue(appliedDiscount);
        }

        if (text) {
            text.textContent = 'Discounted: ' + formatRefMoney(discountAmount, 2);
        }
    });
}


function enhanceCartRowsForReferenceUI() {
    const cart = document.getElementById('cart');
    if (!cart) return;

    const rows = Array.from(cart.querySelectorAll('[data-cart-item]'));
    const counter = document.getElementById('cartItemsCount');

    if (counter) {
        counter.textContent = rows.length;
    }

    cart.classList.toggle('has-cart-items', rows.length > 0);

    rows.forEach(row => {
        row.classList.add('pos-ref-row', 'ref-row-ready', 'pos-cart-unit-row-ready');

        const left = row.querySelector('.pos-cart-left');
        const oldRight = row.querySelector('.pos-cart-right');
        const controls = row.querySelector('.pos-cart-controls');
        const priceInput = row.querySelector('[data-cart-price-input]');
        const lineTotal = row.querySelector('[data-line-total]');
        const removeBtn = row.querySelector('[data-cart-remove]');

        if (left) {
            left.classList.add('pos-ref-product-cell');

            let textBox = left.querySelector('.pos-ref-product-text');

            if (!textBox) {
                textBox = document.createElement('div');
                textBox.className = 'pos-ref-product-text';

                const head = left.querySelector('.pos-cart-head');
                if (head) {
                    textBox.appendChild(head);
                }

                left.appendChild(textBox);
            }
        }

        const unitCell = ensureRefCell(row, '.pos-ref-unit-cell', 'pos-ref-unit-cell');
        renderCartUnitSelector(row, unitCell);

        const batchCell = row.querySelector('.pos-ref-batch-cell');
        if (batchCell) {
            batchCell.remove();
        }

        const qtyCell = ensureRefCell(row, '.pos-ref-qty-cell', 'pos-ref-qty-cell');
        if (controls && controls.parentElement !== qtyCell) {
            qtyCell.appendChild(controls);
        }

        const priceCell = ensureRefCell(row, '.pos-ref-price-cell', 'pos-ref-price-cell');
        if (priceInput && priceInput.parentElement !== priceCell) {
            priceCell.appendChild(priceInput);
        }

        const discountCell = ensureRefCell(row, '.pos-ref-discount-cell', 'pos-ref-discount-cell');
        if (!discountCell.querySelector('.pos-line-discount-control')) {
            const rowKey = getCartRowKey(row);
            const savedDiscount = rowKey && window.__posLineDiscounts
                ? window.__posLineDiscounts[rowKey]
                : undefined;

            const hasSavedDiscount = savedDiscount !== undefined;
            const discountValue = hasSavedDiscount ? normalizePercentValue(savedDiscount) : 0;

            discountCell.innerHTML = `
                <div class="pos-line-discount-control">
                    <input
                        type="number"
                        class="pos-line-discount-input"
                        value="${formatPercentInputValue(discountValue)}"
                        min="0"
                        max="100"
                        step="0.01"
                        data-line-discount-input="1"
                        ${hasSavedDiscount ? 'data-manual-discount="1"' : ''}
                    >
                    <span>%</span>
                </div>
                <div class="pos-line-discount-text">Discounted: 0.00</div>
            `;
        }

        const totalCell = ensureRefCell(row, '.pos-ref-total-cell', 'pos-ref-total-cell');
        if (lineTotal && lineTotal.parentElement !== totalCell) {
            totalCell.appendChild(lineTotal);
        }

        const removeCell = ensureRefCell(row, '.pos-ref-remove-cell', 'pos-ref-remove-cell');
        if (removeBtn && removeBtn.parentElement !== removeCell) {
            removeCell.appendChild(removeBtn);
        }

        if (oldRight) {
            oldRight.remove();
        }
    });

    updateReferenceLineDiscountPreview();
}


document.addEventListener('input', function (e) {
    if (!e.target || typeof e.target.matches !== 'function') return;

    if (e.target.matches('.pos-line-discount-input')) {
        const row = e.target.closest('[data-cart-item]');
        const rowKey = getCartRowKey(row);
        const value = normalizePercentValue(e.target.value);

        e.target.value = formatPercentInputValue(value);
        e.target.dataset.manualDiscount = '1';

        if (rowKey) {
            window.__posLineDiscounts[rowKey] = value;
        }

        updateReferenceLineDiscountPreview();

        if (typeof calculateSummaryFromDom === 'function') {
            calculateSummaryFromDom();
        }

        return;
    }

    if (
        e.target.id === 'discount' ||
        e.target.matches('[data-cart-qty-input]') ||
        e.target.matches('[data-cart-price-input]')
    ) {
        updateReferenceLineDiscountPreview();

        if (typeof calculateSummaryFromDom === 'function') {
            calculateSummaryFromDom();
        }
    }
});
function refreshCartHtml(html) {
    document.getElementById('cart').innerHTML = html.trim() ? html : '<div class="empty-state">Cart is empty</div>';
    arrangeCartLatestFirst();
    enhanceCartRowsForReferenceUI();
    bindCartControls();
    const autoTaxRate = detectCartAutoTaxRate();
    setAutoTaxField(autoTaxRate);
    calculateSummaryFromDom();
    updateWalkInCartScroller();
    filterCartItems();
}

function loadCart() {
    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=render'
    })
    .then(handleAjaxSession)
    .then(html => {
        if (html === null) return;
        refreshCartHtml(html);
    });
}

function safeParseJson(value, fallback = []) {
    try {
        const parsed = JSON.parse(value || '[]');
        return Array.isArray(parsed) ? parsed : fallback;
    } catch (e) {
        return fallback;
    }
}

function normalizeProductUnitMeta(unit = {}) {
    const id = parseInt(unit.id || unit.unit_id || 0, 10) || 0;
    const name = String(unit.unit_name || unit.name || '').trim();
    const price = parseFloat(unit.sale_price || unit.price || unit.unit_price || 0) || 0;
    const conversion = parseFloat(unit.conversion_qty || unit.unit_conversion_qty || unit.conversion || 1) || 1;
    const isDefault = String(unit.is_default || '0') === '1';

    return { id, name, price, conversion, isDefault };
}

function getProductUnitsForProductId(productId) {
    if (!PHARMACY_UNIT_ENABLED || !productId) return [];

    const rows = PRODUCT_UNIT_MAP && PRODUCT_UNIT_MAP[String(productId)]
        ? PRODUCT_UNIT_MAP[String(productId)]
        : [];

    return Array.isArray(rows)
        ? rows.map(normalizeProductUnitMeta).filter(unit => unit.id > 0 && unit.name !== '')
        : [];
}

function getProductUnitsFromCard(card) {
    if (!PHARMACY_UNIT_ENABLED || !card) return [];

    const fromMap = getProductUnitsForProductId(card.dataset.id || '');
    if (fromMap.length) return fromMap;

    return safeParseJson(card.dataset.productUnits || '[]', [])
        .map(normalizeProductUnitMeta)
        .filter(unit => unit.id > 0 && unit.name !== '');
}

function getDefaultProductUnitForCard(card) {
    if (!PHARMACY_UNIT_ENABLED || !card) return null;

    const units = getProductUnitsFromCard(card);
    if (!units.length) return null;

    const defaultId = parseInt(card.dataset.defaultUnitId || '0', 10) || 0;
    return units.find(unit => unit.id === defaultId) || units.find(unit => unit.isDefault) || units[0];
}

/* Compatibility name: ab product card par dropdown nahi hai, default unit silently add hogi. */
function getSelectedProductUnit(card) {
    return getDefaultProductUnitForCard(card);
}

/* Compatibility only: product card unit dropdown removed. */



function getCartUnitRowsForRow(row) {
    if (!PHARMACY_UNIT_ENABLED || !row) return [];
    const productId = row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || '';
    return getProductUnitsForProductId(productId);
}

function renderCartUnitSelector(row, unitCell) {
    if (!unitCell || !row) return;

    const units = getCartUnitRowsForRow(row);
    const currentUnitId = parseInt(row.dataset.unitId || '0', 10) || 0;
    const currentName = String(row.dataset.unitName || '').trim();

 /*
|--------------------------------------------------------------------------
| Product units disabled / unavailable
|--------------------------------------------------------------------------
| Unit column bilkul blank rahega.
|--------------------------------------------------------------------------
*/
if (
    !PHARMACY_UNIT_ENABLED ||
    units.length === 0
) {
    unitCell.innerHTML = '';

    /*
    |--------------------------------------------------------------------------
    | Browser row se purani Piece/Tablet metadata bhi remove
    |--------------------------------------------------------------------------
    */
    row.dataset.unitId = '0';
    row.dataset.unitName = '';
    row.dataset.unitConversionQty = '1';

    return;
}

    const selectedUnit = units.find(unit => unit.id === currentUnitId)
        || units.find(unit => unit.isDefault)
        || units[0];

    unitCell.innerHTML = `
        <select class="pos-cart-unit-select" data-cart-unit-select="1" onchange="handleCartUnitChange(this)">
            ${units.map(unit => `
                <option
                    value="${unit.id}"
                    data-unit-name="${escapeHtml(unit.name)}"
                    data-price="${unit.price}"
                    data-conversion="${unit.conversion}"
                    ${selectedUnit && unit.id === selectedUnit.id ? 'selected' : ''}
                >${escapeHtml(unit.name)}</option>
            `).join('')}
        </select>
    `;

    if (selectedUnit) {
        row.dataset.unitId = String(selectedUnit.id);
        row.dataset.unitName = selectedUnit.name;
        row.dataset.unitConversionQty = String(selectedUnit.conversion);
    }
}

function deriveCartKeyForUnit(productId, unitId, priceTierType = 'default', priceTierNo = 0) {
    let key = 'item_' + productId;

    if (unitId > 0) {
        key += '_unit_' + unitId;
    }

    if (priceTierType === 'wholesale' && parseInt(priceTierNo || 0, 10) > 0) {
        key += '_wholesale_' + parseInt(priceTierNo || 0, 10);
    }

    return key;
}

function postPosCart(body) {
    return fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body
    }).then(handleAjaxSession);
}

function handleCartUnitChange(select) {
    if (!PHARMACY_UNIT_ENABLED || !select) return;

    const row = select.closest('[data-cart-item]');
    if (!row) return;

    const option = select.options[select.selectedIndex];
    if (!option) return;

    const productId = parseInt(row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || '0', 10) || 0;
    if (productId <= 0) return;

    const oldKey = getCartRowKey(row);
    const qtyInput = row.querySelector('[data-cart-qty-input]');
    const qty = Math.max(1, parseFloat(qtyInput?.value || row.dataset.qty || 1) || 1);
    const unitId = parseInt(select.value || '0', 10) || 0;
    const unitName = option.dataset.unitName || option.textContent.trim();
    const unitPrice = parseFloat(option.dataset.price || '0') || 0;
    const unitConversion = parseFloat(option.dataset.conversion || '1') || 1;
    const taxRate = parseFloat(row.dataset.taxRate || 0) || 0;
    const itemType = row.dataset.itemType || 'product';
    const tierMeta = getPriceTierMetaForSelectedType();
    const newKey = deriveCartKeyForUnit(productId, unitId, tierMeta.tierType, tierMeta.tierNo);
    const oldDiscount = oldKey && window.__posLineDiscounts ? window.__posLineDiscounts[oldKey] : undefined;

    select.disabled = true;
    lastTouchedCartId = newKey;

    const addBody =
        'action=add' +
        '&id=' + encodeURIComponent(productId) +
        '&tax_rate=' + encodeURIComponent(taxRate) +
        '&item_type=' + encodeURIComponent(itemType) +
        '&selected_price=' + encodeURIComponent(unitPrice) +
        '&price_tier_type=' + encodeURIComponent(tierMeta.tierType) +
        '&price_tier_no=' + encodeURIComponent(tierMeta.tierNo) +
        '&price_tier_label=' + encodeURIComponent(tierMeta.tierLabel) +
        '&unit_id=' + encodeURIComponent(unitId) +
        '&unit_name=' + encodeURIComponent(unitName) +
        '&unit_conversion_qty=' + encodeURIComponent(unitConversion);

    const updateBody =
        'action=update' +
        '&id=' + encodeURIComponent(newKey) +
        '&qty=' + encodeURIComponent(qty) +
        '&price=' + encodeURIComponent(unitPrice);

    let chain = Promise.resolve('');

    if (oldKey && oldKey !== newKey) {
        chain = chain.then(() => postPosCart('action=remove&id=' + encodeURIComponent(oldKey)));
    }

    chain
        .then(() => postPosCart(addBody))
        .then(html => {
            if (html === null) return null;
            if (qty > 1 || oldKey === newKey) {
                return postPosCart(updateBody);
            }
            return html;
        })
        .then(html => {
            if (html === null) return;

            if (oldDiscount !== undefined && window.__posLineDiscounts) {
                delete window.__posLineDiscounts[oldKey];
                window.__posLineDiscounts[newKey] = oldDiscount;
            }

            refreshCartHtml(html);
        })
        .catch(err => {
            console.error(err);
            alert('Unit change failed. Please try again.');
            loadCart();
        });
}


function handleProductUnitChange(select) {
    if (!PHARMACY_UNIT_ENABLED || !select) {
        return;
    }

    const card = select.closest('.product-card');
    if (!card) {
        return;
    }

    const unit = getSelectedProductUnit(card);
    if (!unit) {
        return;
    }

    /*
    | Retailer mode mein unit price show hoga.
    | Wholesale mode ko existing wholesale logic handle karega.
    */
    if (!isWholesaleMode(selectedType)) {
        card.dataset.basePrice = String(unit.price);
        card.dataset.retailPrice = String(unit.price);
        card.dataset.originalPrice = String(unit.price);

        const holder = card.querySelector('.card-price-value');
        if (holder) {
          holder.textContent = formatRefMoney(unit.price, 2);
        }
    }

    updateProductDisplayedPrices();
}


function addToCartFromCard(
    card,
    id,
    taxRate = 0,
    itemType = 'product'
) {
    if (!card || !id) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Existing wholesale guard unchanged
    |--------------------------------------------------------------------------
    */
    if (
        isWholesaleMode(selectedType) &&
        !hasWholesaleCustomer
    ) {
        selectedType = 'Retailer';
    }

    const tierMeta =
        getPriceTierMetaForSelectedType();

    const productUnits =
        getProductUnitsFromCard(card);

    /*
    |--------------------------------------------------------------------------
    | New pharmacy unit popup workflow
    |--------------------------------------------------------------------------
    | Retailer mode + units available:
    | Product direct cart mein nahi jayega.
    | Pehle unit selection popup open hoga.
    |--------------------------------------------------------------------------
    */
 if (
    PHARMACY_UNIT_ENABLED &&
    !isWholesaleMode(selectedType) &&
    productUnits.length > 1
) {
        openProductUnitPopup(
            card,
            id,
            taxRate,
            itemType,
            productUnits,
            tierMeta
        );

        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Existing fallback workflow
    |--------------------------------------------------------------------------
    | Product units nahi hain ya wholesale mode hai to old logic chalega.
    |--------------------------------------------------------------------------
    */
    const defaultUnit =
        getDefaultProductUnitForCard(card);

    let selectedPrice =
        getPriceForSelectedType(card);

    if (
        defaultUnit &&
        !isWholesaleMode(selectedType)
    ) {
        selectedPrice = defaultUnit.price;
    }

    addToCart(
        id,
        taxRate,
        itemType,
        selectedPrice,
        tierMeta.tierType,
        tierMeta.tierNo,
        tierMeta.tierLabel,
        defaultUnit ? defaultUnit.id : 0,
        defaultUnit ? defaultUnit.name : '',
        defaultUnit ? defaultUnit.conversion : 1
    );
}

/* =====================================================
   OPEN PRODUCT UNIT POPUP
===================================================== */
function openProductUnitPopup(
    card,
    id,
    taxRate,
    itemType,
    units,
    tierMeta
) {
    if (
        !productUnitModal ||
        !card ||
        !Array.isArray(units) ||
        units.length === 0
    ) {
        return false;
    }

    pendingUnitProduct = {
        card: card,
        id: parseInt(id || 0, 10),
        taxRate: parseFloat(taxRate || 0),
        itemType: itemType || 'product',
        units: units,
        tierMeta: tierMeta
    };

    const titleElement =
        document.getElementById('productUnitModalTitle');

    const optionsBox =
        document.getElementById('productUnitOptions');

    if (!optionsBox) {
        return false;
    }

    const productName =
        String(card.dataset.name || 'Product').trim();

    if (titleElement) {
        titleElement.textContent =
            'Select Unit — ' + productName;
    }

    optionsBox.innerHTML = '';

    units.forEach(function (unit) {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'product-unit-option';

        const conversionText =
            parseFloat(unit.conversion || 1) > 1
                ? 'Contains ' + unit.conversion + ' base units'
                : 'Single base unit';

   button.innerHTML = `
    <span class="product-unit-option-left">
        <span class="product-unit-option-name">
            ${escapeHtml(unit.name)}
        </span>

        <span class="product-unit-option-conversion">
            ${escapeHtml(conversionText)}
        </span>
    </span>

    <span class="product-unit-option-right">
        <span class="product-unit-option-price">
           Rs ${formatRefMoney(unit.price, 2)}
        </span>

        <span
            class="product-unit-option-add"
            aria-hidden="true"
        >
            +
        </span>
    </span>
`;

button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();

    selectProductUnitAndAdd(unit.id);
});

        optionsBox.appendChild(button);
    });

    productUnitModal.show();

    return true;
}


/* =====================================================
   SELECT UNIT AND ADD PRODUCT
===================================================== */
function selectProductUnitAndAdd(unitId) {
    const pending = pendingUnitProduct;

    if (!pending) {
        console.error(
            'Pending unit product data is missing.'
        );
        return;
    }

    const selectedUnit = pending.units.find(function (unit) {
        return parseInt(unit.id || 0, 10) ===
            parseInt(unitId || 0, 10);
    });

    if (!selectedUnit) {
        alert('Selected unit was not found.');
        return;
    }

    const productId =
        parseInt(pending.id || 0, 10);

    const selectedUnitId =
        parseInt(selectedUnit.id || 0, 10);

    const selectedPrice =
        parseFloat(selectedUnit.price || 0);

    const selectedConversion =
        parseFloat(selectedUnit.conversion || 1);

    if (productId <= 0) {
        alert('Invalid product ID.');
        return;
    }

    if (selectedUnitId <= 0) {
        alert('Invalid unit ID.');
        return;
    }

    if (selectedPrice <= 0) {
        alert('Selected unit price is invalid.');
        return;
    }

    const tierMeta = pending.tierMeta || {
        tierType: 'default',
        tierNo: 0,
        tierLabel: 'Default'
    };

    /*
    |--------------------------------------------------------------------------
    | Har click par direct cart request
    |--------------------------------------------------------------------------
    | Button disable nahi hoga.
    | Loading dots nahi aayenge.
    | Tick nahi aayega.
    | Popup open rahega.
    |--------------------------------------------------------------------------
    */
    addToCart(
        productId,
        parseFloat(pending.taxRate || 0),
        pending.itemType || 'product',
        selectedPrice,
        tierMeta.tierType || 'default',
        parseInt(tierMeta.tierNo || 0, 10),
        tierMeta.tierLabel || 'Default',
        selectedUnitId,
        selectedUnit.name || '',
        selectedConversion > 0
            ? selectedConversion
            : 1
    );
}

function addToCart(
    id,
    taxRate = 0,
    itemType = 'product',
    selectedPrice = '',
    priceTierType = 'default',
    priceTierNo = 0,
    priceTierLabel = 'Default',
    unitId = 0,
    unitName = '',
    unitConversionQty = 1
) {
    const productId = parseInt(id || 0, 10);
    const selectedUnitId = parseInt(unitId || 0, 10);
    const selectedUnitConversion = parseFloat(
        unitConversionQty || 1
    );

    if (productId <= 0) {
        console.error('Invalid product ID:', id);
        return Promise.resolve(false);
    }

    const tierSuffix =
        priceTierType !== 'default'
            ? '_' + priceTierType + '_' + priceTierNo
            : '';

    const unitSuffix =
        selectedUnitId > 0
            ? '_unit_' + selectedUnitId
            : '';

    lastTouchedCartId =
        'item_' + productId + unitSuffix + tierSuffix;

    const requestBody = new URLSearchParams();

    requestBody.set('action', 'add');
    requestBody.set('id', String(productId));
    requestBody.set('tax_rate', String(taxRate || 0));
    requestBody.set('item_type', itemType || 'product');
    requestBody.set('selected_price', String(selectedPrice || 0));
    requestBody.set(
        'price_tier_type',
        priceTierType || 'default'
    );
    requestBody.set(
        'price_tier_no',
        String(priceTierNo || 0)
    );
    requestBody.set(
        'price_tier_label',
        priceTierLabel || 'Default'
    );
    requestBody.set(
        'unit_id',
        String(selectedUnitId)
    );
    requestBody.set(
        'unit_name',
        unitName || ''
    );
    requestBody.set(
        'unit_conversion_qty',
        String(
            selectedUnitConversion > 0
                ? selectedUnitConversion
                : 1
        )
    );

    return fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type':
                'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: requestBody.toString()
    })
    .then(async function (response) {
        /*
        |--------------------------------------------------------------------------
        | Session handler ko 401 response handle karne do
        |--------------------------------------------------------------------------
        */
        if (response.status === 401) {
            return handleAjaxSession(response);
        }

        /*
        |--------------------------------------------------------------------------
        | PHP/backend error ko silently ignore nahi karna
        |--------------------------------------------------------------------------
        */
        if (!response.ok) {
            const errorText = await response.text();

            throw new Error(
                errorText ||
                ('Cart request failed: HTTP ' + response.status)
            );
        }

        return handleAjaxSession(response);
    })
    .then(function (html) {
        if (html === null) {
            return null;
        }

        const cartHtml = String(html || '').trim();

        if (cartHtml === '') {
            throw new Error(
                'Cart backend returned an empty response.'
            );
        }

refreshCartHtml(cartHtml);

/*
|--------------------------------------------------------------------------
| Sirf abhi add/update hui product row animate hogi
|--------------------------------------------------------------------------
*/
animateLastTouchedCartItem();

return cartHtml;
    })
    .catch(function (error) {
        console.error('Add to cart failed:', error);

        alert(
            'Product cart mein add nahi ho saka. ' +
            'Browser console mein error check karein.'
        );

        return null;
    });
}
/*
|--------------------------------------------------------------------------
| CUSTOM ITEM FORM TOGGLE
|--------------------------------------------------------------------------
| Sirf custom item form ko open/close karta hai.
| Kisi backend ya cart logic ko change nahi karta.
|--------------------------------------------------------------------------
*/
function toggleCustomProductForm() {
    const form = document.querySelector(
        '.pos-ref-bottom-aligned .pos-custom-product-form'
    );

    if (!form) {
        console.error('Custom product form not found.');
        return;
    }

    const isOpened = form.classList.toggle('show');

    const button = document.querySelector(
        '.pos-add-custom-main.pos-ref-add-inline-btn'
    );

    if (button) {
        button.setAttribute('aria-expanded', isOpened ? 'true' : 'false');
    }

    if (isOpened) {
        setTimeout(() => {
            const nameInput = document.getElementById('customProductName');

            if (nameInput) {
                nameInput.focus();
            }
        }, 50);
    }
}

function addCustomProduct() {
    const nameEl = document.getElementById('customProductName');
    const priceEl = document.getElementById('customProductPrice');
    const qtyEl = document.getElementById('customProductQty');

    const name = (nameEl?.value || '').trim();
    const price = parseFloat(priceEl?.value || 0);
    const qty = parseInt(qtyEl?.value || 1, 10);

    if (!name) {
        alert('Please enter product name');
        nameEl.focus();
        return;
    }

    if (price <= 0) {
        alert('Please enter valid price');
        priceEl.focus();
        return;
    }

    if (qty <= 0) {
        alert('Please enter valid quantity');
        qtyEl.focus();
        return;
    }

    lastTouchedCartId = null;

    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body:
            'action=add_custom' +
            '&name=' + encodeURIComponent(name) +
            '&price=' + encodeURIComponent(price) +
            '&qty=' + encodeURIComponent(qty) +
            '&tax_rate=0' +
            '&item_type=custom'
    })
    .then(handleAjaxSession)
    .then(html => {
        if (html === null) return;

        refreshCartHtml(html);

        nameEl.value = '';
        priceEl.value = '';
        qtyEl.value = '1';

        nameEl.focus();
    })
    .catch(error => {
        console.error('Custom product add failed:', error);
        alert('Custom product add failed');
    });
}

function updateCartRealtime(id, qty, price) {
    lastTouchedCartId = id;

    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update&id=${encodeURIComponent(id)}&qty=${encodeURIComponent(qty)}&price=${encodeURIComponent(price)}`
    })
    .then(handleAjaxSession)
    .then(html => {
        if (html === null) return;
        refreshCartHtml(html);
    });
}

function removeItem(id) {
    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=remove&id=${encodeURIComponent(id)}`
    })
    .then(handleAjaxSession)
    .then(html => {
        if (html === null) return;
        refreshCartHtml(html);
    });
}

function bindCartControls() {
    document.querySelectorAll('[data-cart-minus]').forEach(btn => {
        btn.onclick = function () {
            const id = this.dataset.id;
            const qty = parseFloat(this.dataset.qty || 1);
            const price = parseFloat(this.dataset.price || 0);
            const newQty = qty > 1 ? qty - 1 : 1;
            updateCartRealtime(id, newQty, price);
        };
    });

    document.querySelectorAll('[data-cart-plus]').forEach(btn => {
        btn.onclick = function () {
            const id = this.dataset.id;
            const qty = parseFloat(this.dataset.qty || 1);
            const price = parseFloat(this.dataset.price || 0);
            updateCartRealtime(id, qty + 1, price);
        };
    });

    document.querySelectorAll('[data-cart-remove]').forEach(btn => {
        btn.onclick = function () {
            removeItem(this.dataset.id);
        };
    });

    document.querySelectorAll('[data-cart-qty-input]').forEach(inp => {
        inp.onchange = function () {
            const id = this.dataset.id;
            const price = parseFloat(this.dataset.price || 0);
            const qty = Math.max(1, parseFloat(this.value || 1));
            updateCartRealtime(id, qty, price);
        };
    });

    document.querySelectorAll('[data-cart-price-input]').forEach(inp => {
inp.type = 'number';
inp.min = '0';
inp.step = '0.01';

        let priceTimer = null;

        const updatePrice = function () {
            const id = this.dataset.id;
            const qty = Math.max(1, parseFloat(this.dataset.qty || 1));
           const rawPrice = parseFloat(this.value || 0);

const price = Math.max(
    0,
    Math.round(
        (Number.isFinite(rawPrice) ? rawPrice : 0) * 100
    ) / 100
);

            lastTouchedCartId = id;
            clearTimeout(priceTimer);

            priceTimer = setTimeout(() => {
                updateCartRealtime(id, qty, price);
            }, 250);
        };

        inp.oninput = updatePrice;
        inp.onchange = updatePrice;
    });
}

function getCartItemMaxDiscountPercent(row) {
    /*
    |--------------------------------------------------------------------------
    | Maximum Discount Limit per item
    |--------------------------------------------------------------------------
    | Product card se max discount read hota hai.
    | Agar item par max limit set nahi hai to null return hoga,
    | yani POS me entered full discount us line par apply hoga.
    */
    if (!row) return null;

    const sourceId = parseInt(row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || 0, 10);

    if (!sourceId || sourceId <= 0) {
        return null;
    }

    const card = document.querySelector(`.product-card[data-id="${CSS.escape(String(sourceId))}"]`);

    if (!card) {
        return null;
    }

    const hasMaxDiscount = parseInt(card.dataset.hasMaxDiscount || card.dataset.hasDiscount || 0, 10) === 1;
    let maxPercent = parseFloat(card.dataset.maxDiscountPercent || card.dataset.discountValue || 0);

    if (!Number.isFinite(maxPercent) || maxPercent <= 0 || !hasMaxDiscount) {
        return null;
    }

    if (maxPercent > 100) {
        maxPercent = 100;
    }

    return maxPercent;
}

function calculateItemWiseCappedDiscount(discountPer) {
    discountPer = parseFloat(discountPer || 0);

    if (!Number.isFinite(discountPer) || discountPer < 0) {
        discountPer = 0;
    }

    if (discountPer > 100) {
        discountPer = 100;
    }

    let discountAmount = 0;

    document.querySelectorAll('#cart [data-cart-item]').forEach(row => {
        const lineEl = row.querySelector('[data-line-total]');
        const lineSubtotal = lineEl ? parseFloat(lineEl.dataset.lineTotal || 0) : 0;

        if (!lineSubtotal || lineSubtotal <= 0 || discountPer <= 0) {
            row.dataset.appliedDiscountPercent = '0';
            row.dataset.lineDiscount = '0';
            return;
        }

   const lineDiscountInput = row.querySelector('.pos-line-discount-input');
const rowKey = getCartRowKey(row);

let appliedPercent;

if (
    lineDiscountInput &&
    (
        lineDiscountInput.dataset.manualDiscount === '1' ||
        (rowKey && window.__posLineDiscounts[rowKey] !== undefined)
    )
) {
    appliedPercent = normalizePercentValue(
        rowKey && window.__posLineDiscounts[rowKey] !== undefined
            ? window.__posLineDiscounts[rowKey]
            : lineDiscountInput.value
    );
} else {
    const maxPercent = getRowMaxDiscountPercentForSummary(row);
    appliedPercent = maxPercent !== null
        ? Math.min(discountPer, maxPercent)
        : discountPer;
}

const lineDiscount = lineSubtotal * (appliedPercent / 100);

        row.dataset.appliedDiscountPercent = appliedPercent.toFixed(2);
        row.dataset.lineDiscount = lineDiscount.toFixed(2);

        discountAmount += lineDiscount;
    });

    return discountAmount;
}

function setSummaryText(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value;
    }
}

function formatPosMoney(value) {
    value = Number(value || 0);

    if (!Number.isFinite(value)) {
        value = 0;
    }

    return 'Rs ' + value.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function getRowMaxDiscountPercentForSummary(row) {
    if (!row) return null;

    const hasRowMax = String(row.dataset.hasMaxDiscount || '0') === '1';
    const rowMax = parseFloat(row.dataset.maxDiscountPercent || 0);

    if (hasRowMax && Number.isFinite(rowMax) && rowMax > 0) {
        return Math.min(100, Math.max(0, rowMax));
    }

    if (typeof getCartItemMaxDiscountPercent === 'function') {
        return getCartItemMaxDiscountPercent(row);
    }

    return null;
}

function calculateSummaryFromDom() {
    let qty = 0;
    let subtotal = 0;
    let discountAmt = 0;
    let itemWiseTaxAmt = 0;
    let hasItemTax = false;

    const discountInput = document.getElementById('discount');
    const taxInput = document.getElementById('tax');

    let discountPer = normalizePercentValue(discountInput?.value || 0);
    let taxPer = parseFloat(taxInput?.value || 0);

    if (!Number.isFinite(taxPer) || taxPer < 0) {
        taxPer = 0;
    }

    if (typeof isManualTaxOverride !== 'undefined' && !isManualTaxOverride && (!taxPer || taxPer <= 0)) {
        if (typeof detectCartAutoTaxRate === 'function') {
            taxPer = detectCartAutoTaxRate();
        }

        if (typeof setAutoTaxField === 'function') {
            setAutoTaxField(taxPer);
        }
    }

    document.querySelectorAll('#cart [data-cart-item]').forEach(row => {
        const qtyInput = row.querySelector('[data-cart-qty-input]');
        const priceInput = row.querySelector('[data-cart-price-input]');
        const lineTotalEl = row.querySelector('[data-line-total]');
        const lineDiscountInput = row.querySelector('.pos-line-discount-input');
        const rowKey = getCartRowKey(row);

        const rowQty = Math.max(1, parseFloat(qtyInput?.value || row.dataset.qty || 1) || 1);
        const rowPrice = Math.max(0, parseFloat(priceInput?.value || 0) || 0);
        const lineSubtotal = rowQty * rowPrice;

        qty += rowQty;
        subtotal += lineSubtotal;

        let appliedPercent;

        if (
            lineDiscountInput &&
            (
                lineDiscountInput.dataset.manualDiscount === '1' ||
                (rowKey && window.__posLineDiscounts && window.__posLineDiscounts[rowKey] !== undefined)
            )
        ) {
            appliedPercent = normalizePercentValue(
                rowKey && window.__posLineDiscounts && window.__posLineDiscounts[rowKey] !== undefined
                    ? window.__posLineDiscounts[rowKey]
                    : lineDiscountInput.value
            );
        } else {
            const maxPercent = getRowMaxDiscountPercentForSummary(row);
            appliedPercent = maxPercent !== null
                ? Math.min(discountPer, maxPercent)
                : discountPer;
        }

        const lineDiscount = lineSubtotal * (appliedPercent / 100);
        const lineNet = Math.max(0, lineSubtotal - lineDiscount);

        discountAmt += lineDiscount;

        row.dataset.appliedDiscountPercent = appliedPercent.toFixed(2);
        row.dataset.lineDiscount = lineDiscount.toFixed(2);
        row.dataset.lineNetTotal = lineNet.toFixed(2);

        if (lineDiscountInput) {
            lineDiscountInput.value = formatPercentInputValue(appliedPercent);

            if (rowKey && lineDiscountInput.dataset.manualDiscount === '1') {
                window.__posLineDiscounts[rowKey] = appliedPercent;
            }
        }

        if (lineTotalEl) {
            lineTotalEl.dataset.lineTotal = lineNet.toFixed(2);
            lineTotalEl.dataset.lineSubtotal = lineSubtotal.toFixed(2);
            lineTotalEl.dataset.lineQty = rowQty.toFixed(2);
            lineTotalEl.textContent = formatPosMoney(lineNet);
        }

        const discountText = row.querySelector('.pos-line-discount-text');
        if (discountText) {
            discountText.textContent = 'Discounted: ' + formatRefMoney(lineDiscount, 2);
        }

        const rowTaxRate = parseFloat(row.dataset.taxRate || 0) || 0;
        if (rowTaxRate > 0) {
            hasItemTax = true;
            itemWiseTaxAmt += lineNet * (rowTaxRate / 100);
        }
    });

    const taxable = Math.max(0, subtotal - discountAmt);
    const taxAmt = hasItemTax ? itemWiseTaxAmt : (taxable * (taxPer / 100));
    const grand = taxable + taxAmt;

    const cartItemsCountEl = document.getElementById('cartItemsCount');
    if (cartItemsCountEl) {
        cartItemsCountEl.textContent = document.querySelectorAll('#cart [data-cart-item]').length;
    }

    setSummaryText('sumQty', qty.toFixed(0));
    setSummaryText('sumSubtotal', formatPosMoney(subtotal));
    setSummaryText('sumDiscount', formatPosMoney(discountAmt));
    setSummaryText('sumDiscountAmount', formatPosMoney(discountAmt));
    setSummaryText('sumTax', formatPosMoney(taxAmt));
    setSummaryText('sumGrand', formatPosMoney(grand));

    if (typeof updateCustomerChangeReturn === 'function') {
        updateCustomerChangeReturn(grand);
    }
}


const discountEl = document.getElementById('discount');
if (discountEl) {
    discountEl.addEventListener('input', calculateSummaryFromDom);
}

const taxEl = document.getElementById('tax');
if (taxEl) {
    taxEl.addEventListener('input', function () {
        const val = this.value.trim();
        isManualTaxOverride = val !== '';
        calculateSummaryFromDom();
    });
}

document.querySelectorAll('.pay-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        selectedPaymentMethod = this.dataset.pay || 'cash';
    });
});

function updateCustomerChangeReturn(grandTotalValue) {
    const customerPayInput = document.getElementById('customerPay');
    const changeReturnInput = document.getElementById('changeReturn');

    if (!customerPayInput || !changeReturnInput) return;

    let grand = Number(grandTotalValue || 0);

    if (!grand || grand <= 0) {
        const grandText = document.getElementById('sumGrand')?.textContent || '0';
        grand = Number(grandText.replace(/[^\d.]/g, '')) || 0;
    }

    const paid = Number(customerPayInput.value || 0);
    const change = Math.max(0, paid - grand);

    changeReturnInput.value = change.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'customerPay') {
        updateCustomerChangeReturn();
    }
});
/*
|--------------------------------------------------------------------------
| CHECKOUT
|--------------------------------------------------------------------------
| Modes:
| complete = normal invoice
| print    = complete + invoice page print
| hold     = save sale as hold
|--------------------------------------------------------------------------
*/

function buildLineDiscountPayload() {
    const lineDiscounts = [];

    document.querySelectorAll('#cart [data-cart-item]').forEach(row => {
        const qtyInput = row.querySelector('[data-cart-qty-input]');
        const priceInput = row.querySelector('[data-cart-price-input]');
        const discountInput = row.querySelector('.pos-line-discount-input');

        const qty = Math.max(1, parseFloat(qtyInput?.value || row.dataset.qty || 1) || 1);
        const price = Math.max(0, parseFloat(priceInput?.value || 0) || 0);
        const discountPercent = normalizePercentValue(
            discountInput ? discountInput.value : row.dataset.appliedDiscountPercent
        );

        const lineSubtotal = qty * price;
        const discountAmount = lineSubtotal * (discountPercent / 100);
        const lineTotalAfterDiscount = Math.max(0, lineSubtotal - discountAmount);

        lineDiscounts.push({
            cart_key: getCartRowKey(row),
            item_id: row.dataset.sourceId || row.dataset.itemId || row.dataset.productId || '',
            name: row.dataset.name || '',
            qty: Number(qty.toFixed(2)),
            price: Number(price.toFixed(2)),
            discount_percent: Number(discountPercent.toFixed(2)),
            discount_amount: Number(discountAmount.toFixed(2)),
            line_subtotal: Number(lineSubtotal.toFixed(2)),
            line_total_after_discount: Number(lineTotalAfterDiscount.toFixed(2))
        });
    });

    return lineDiscounts;
}

function checkout() {
    if (isWholesaleMode(selectedType) && !hasWholesaleCustomer) {
        alert('Wholesale checkout is not available for this client.');
        selectedType = 'Retailer';
        selectedCustomerId = '';
        selectedCustomerName = getDefaultCustomerName(selectedType);
        selectedCustomerPhone = '';
        retailerCustomerInfoPromptShown = false;
        updateSelectedCustomerBox();
        updateProductDisplayedPrices();
        return;
    }

    const cartItemsCount = document.querySelectorAll('#cart [data-cart-item]').length;
    if (cartItemsCount <= 0) {
        alert('Cart is empty.');
        if (typeof focusProductSearchShortcut === 'function') {
            focusProductSearchShortcut();
        }
        return;
    }

    const discountEl = document.getElementById('discount');
    const taxEl = document.getElementById('tax');

    const discount = discountEl ? (discountEl.value || 0) : 0;
    const tax = taxEl ? (taxEl.value || detectCartAutoTaxRate()) : detectCartAutoTaxRate();
    const checkoutType = getCheckoutCustomerType();
    const checkoutCustomerId = selectedCustomerId || 0;
    
    updateReferenceLineDiscountPreview();
calculateSummaryFromDom();

const lineDiscounts = buildLineDiscountPayload();

    const posMode = window.__posCheckoutMode || 'complete';
    const isHoldMode = posMode === 'hold';
    const shouldPrintAfterCheckout = posMode === 'print';
    const ajaxAction = isHoldMode ? 'save_hold' : 'checkout';

    /*
    |--------------------------------------------------------------------------
    | Retailer / Walk-In customer info prompt
    |--------------------------------------------------------------------------
    | Hold Sale par popup nahi aayega.
    | Complete / Print par pehli dafa customer info popup aayega.
    |--------------------------------------------------------------------------
    */
    if (!isHoldMode && selectedType === 'Retailer' && !selectedCustomerId && !retailerCustomerInfoPromptShown) {
        const hasCartItems = document.querySelectorAll('#cart [data-cart-item]').length > 0;

        if (hasCartItems) {
            pendingRetailerCheckoutAfterCustomerInfo = true;
            openRetailerCustomerInfoPopup(true);
            return;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Wholesale customer required
    |--------------------------------------------------------------------------
    */
    if (isWholesaleMode(selectedType) && !selectedCustomerId) {
        alert('Please select wholesale customer');
        window.__posCheckoutMode = '';
        return;
    }

    fetch('ajax_add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body:
            `action=${encodeURIComponent(ajaxAction)}` +
            `&customer_id=${encodeURIComponent(checkoutCustomerId)}` +
            `&type=${encodeURIComponent(checkoutType)}` +
            `&customer_name=${encodeURIComponent(selectedCustomerName || '')}` +
            `&customer_phone=${encodeURIComponent(selectedCustomerPhone || '')}` +
          `&discount=${encodeURIComponent(discount)}` +
`&line_discounts=${encodeURIComponent(JSON.stringify(lineDiscounts))}` +
`&tax=${encodeURIComponent(tax)}` +
`&payment_method=${encodeURIComponent(selectedPaymentMethod)}`
            
    })
    .then(handleAjaxJsonSession)
    .then(res => {
        if (res === null) return;

        if (res.status === 'success') {
            if (isHoldMode) {
                window.__posCheckoutMode = '';
                retailerCustomerInfoPromptShown = false;
                pendingRetailerCheckoutAfterCustomerInfo = false;

                if (typeof loadCart === 'function') {
                    loadCart();
                }

                if (typeof calculateSummaryFromDom === 'function') {
                    setTimeout(calculateSummaryFromDom, 150);
                }

                alert(res.message || 'Sale saved on hold successfully');
                return;
            }

        if (res.sale_id && Number(res.sale_id) > 0) {
    window.__posCheckoutMode = '';

    // Direct bill / invoice generate page
    let redirectUrl = 'thermal.php?sale_id=' + encodeURIComponent(res.sale_id);

    // Agar Print mode se checkout hua hai to auto print bhi trigger hoga
    if (shouldPrintAfterCheckout) {
        redirectUrl += '&auto_print=1';
    }

    window.location.href = redirectUrl;
} else {
    window.__posCheckoutMode = '';
    alert('Order saved, but sale_id not found in response.');
    console.log(res);
}
        } else {
            window.__posCheckoutMode = '';
            alert(res.message || 'Checkout failed');
        }
    })
    .catch((error) => {
        window.__posCheckoutMode = '';
        console.error('Checkout error:', error);
        alert('Checkout request failed. Check console/backend response.');
    });
}
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('dashboardSidebar');
    if (!sidebar) return;

    const clickable = sidebar.querySelectorAll('a, button');
    const matches = [];

    clickable.forEach(function (el) {
        const txt = (el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (txt === 'new customer' || txt.includes('new customer')) {
            matches.push(el);
        }
    });

    if (matches.length > 1) {
        matches.forEach(function (el, index) {
            if (index > 0) {
                const parent = el.closest('li, .nav-item, .menu-item, .dropdown-item, .d-grid, .btn-group');
                if (parent) {
                    parent.style.display = 'none';
                } else {
                    el.style.display = 'none';
                }
            }
        });
    }
});

function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

['customProductName', 'customProductPrice', 'customProductQty'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addCustomProduct();
            }
        });
    }
});

updateProductDisplayedPrices();
loadCart();
updateWalkInCartScroller();
setAutoTaxField(defaultProductTaxRate);



function toggleCardSku(btn) {
    const wrap = btn.closest('.item-sku-wrap');
    if (!wrap) return;

    const sku = wrap.querySelector('.item-sku');
    if (!sku) return;

    const skuValue = sku.textContent.trim();

    wrap.classList.toggle('sku-open');

    if (wrap.classList.contains('sku-open')) {
        btn.textContent = skuValue || 'SKU';
        btn.title = 'Click to hide SKU';
    } else {
        btn.textContent = 'SKU';
        btn.title = skuValue ? 'Show SKU: ' + skuValue : 'Show SKU';
    }
}


/* POS MOBILE SIDEBAR TOGGLE */
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.getElementById('dashboardSidebar');

    const toggleSelectors = [
        '#sidebarToggle',
        '#toggleSidebar',
        '.sidebar-toggle',
        '.sidebar-toggler',
        '.topbar-menu-toggle',
        '.hamburger-btn',
        '[data-sidebar-toggle]',
        '.topbar .bi-list'
    ];

    let toggleBtn = null;

    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            if (window.innerWidth <= 991) {
                e.preventDefault();
                body.classList.toggle('sidebar-open');
                body.classList.toggle('dashboard-mobile-sidebar-open');
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (window.innerWidth > 991) return;

        const isToggle = e.target.closest('#sidebarToggle, #toggleSidebar, .sidebar-toggle, .sidebar-toggler, .topbar-menu-toggle, .hamburger-btn, [data-sidebar-toggle], .topbar .bi-list');

        if (
            body.classList.contains('sidebar-open') &&
            sidebar &&
            !sidebar.contains(e.target) &&
            !isToggle
        ) {
            body.classList.remove('sidebar-open');
            body.classList.remove('dashboard-mobile-sidebar-open');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            body.classList.remove('sidebar-open');
            body.classList.remove('dashboard-mobile-sidebar-open');
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 991) {
            body.classList.remove('sidebar-open');
            body.classList.remove('dashboard-mobile-sidebar-open');
        }
    });
});


function initCartPreviewInvoiceHeader() {
    const invoiceNoEl = document.getElementById('cartPreviewInvoiceNo');
    const dateTimeEl = document.getElementById('cartPreviewDateTime');

    if (!invoiceNoEl || !dateTimeEl) return;

    if (!invoiceNoEl.dataset.generated) {
        const now = new Date();
        const previewNo = String(Math.floor(now.getTime() / 1000)).slice(-6);
        invoiceNoEl.textContent = 'INV-' + previewNo;
        invoiceNoEl.dataset.generated = '1';
    }

    function updateCartHeaderDateTime() {
        const now = new Date();

        const datePart = now.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });

        const timePart = now.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });

        dateTimeEl.textContent = datePart + ' | ' + timePart;
    }

    updateCartHeaderDateTime();
    setInterval(updateCartHeaderDateTime, 30000);
}

document.addEventListener('DOMContentLoaded', initCartPreviewInvoiceHeader);
initCartPreviewInvoiceHeader();

/* =========================================================
   POS BARCODE SCANNER AJAX
   Shortcut keys ko bilkul touch nahi karta.
   F3 / Scan Barcode button productSearch focus karega.
   Scanner barcode type karega + Enter bhejega.
   Enter par ajax_add_to_cart.php me scan_barcode action chalega.
========================================================= */
(function () {
    if (window.__POS_BARCODE_SCANNER_BOUND__) return;
    window.__POS_BARCODE_SCANNER_BOUND__ = true;

    const POS_SCAN_AJAX_URL = 'ajax_add_to_cart.php';
    let barcodeScanBusy = false;

    function normalizeBarcode(value) {
        return String(value || '')
            .replace(/[\r\n\t]+/g, '')
            .trim();
    }

    function showBarcodeMessage(message) {
        let toast = document.getElementById('posShortcutToast');

        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'posShortcutToast';
            toast.style.cssText = [
                'position:fixed',
                'right:18px',
                'bottom:18px',
                'z-index:999999',
                'min-width:220px',
                'max-width:360px',
                'padding:12px 14px',
                'border-radius:10px',
                'background:#0b1220',
                'color:#fff',
                'border:1px solid rgba(139,92,246,.45)',
                'box-shadow:0 18px 40px rgba(0,0,0,.35)',
                'font-size:13px',
                'font-weight:800',
                'display:none'
            ].join(';');

            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.display = 'block';

        clearTimeout(window.__posShortcutToastTimer);
        window.__posShortcutToastTimer = setTimeout(function () {
            toast.style.display = 'none';
        }, 1800);
    }

    function getScannerInput() {
        return document.getElementById('productSearch') || document.getElementById('cartSearch');
    }

    function getScanTierMeta() {
        if (typeof getPriceTierMetaForSelectedType === 'function') {
            return getPriceTierMetaForSelectedType();
        }

        return {
            tierType: 'default',
            tierNo: 0,
            tierLabel: 'Default'
        };
    }

    function getScanCustomerType() {
        if (typeof getCheckoutCustomerType === 'function') {
            return getCheckoutCustomerType();
        }

        if (typeof selectedType !== 'undefined' && selectedType) {
            return selectedType;
        }

        if (window.selectedType) {
            return window.selectedType;
        }

        return 'Retailer';
    }

    async function sendScannedBarcodeToCart(barcode) {
        barcode = normalizeBarcode(barcode);

        if (!barcode) {
            return;
        }

        if (barcode.length < 3) {
            showBarcodeMessage('Invalid barcode.');
            return;
        }

        if (barcodeScanBusy) {
            return;
        }

        barcodeScanBusy = true;

        const tierMeta = getScanTierMeta();

        const body = new URLSearchParams();
        body.set('action', 'scan_barcode');
        body.set('barcode', barcode);

        /*
        |--------------------------------------------------------------------------
        | Existing wholesale/customer pricing support
        |--------------------------------------------------------------------------
        | Ye values existing POS logic se read hongi.
        | Shortcut / checkout / cart logic change nahi ho raha.
        |--------------------------------------------------------------------------
        */
        body.set('customer_type', getScanCustomerType());
        body.set('selected_type', getScanCustomerType());
        body.set('price_tier_type', tierMeta.tierType || 'default');
        body.set('price_tier_no', tierMeta.tierNo || 0);
        body.set('price_tier_label', tierMeta.tierLabel || 'Default');

        try {
            const response = await fetch(POS_SCAN_AJAX_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            });

            let html = '';

            if (typeof handleAjaxSession === 'function') {
                html = await handleAjaxSession(response);
                if (html === null) {
                    barcodeScanBusy = false;
                    return;
                }
            } else {
                html = await response.text();
            }

            if (!response.ok) {
                showBarcodeMessage((html || 'Barcode product not found.').trim());
                barcodeScanBusy = false;
                focusScannerInputAgain();
                return;
            }

            if (typeof refreshCartHtml === 'function') {
                refreshCartHtml(html);
            } else {
                const cart = document.getElementById('cart');
                if (cart) {
                    cart.innerHTML = html;
                }

                if (typeof calculateSummaryFromDom === 'function') {
                    calculateSummaryFromDom();
                }
            }

            showBarcodeMessage('Barcode item added.');

            barcodeScanBusy = false;
            focusScannerInputAgain();
        } catch (err) {
            console.error(err);
            barcodeScanBusy = false;
            showBarcodeMessage('Barcode scan request failed.');
            focusScannerInputAgain();
        }
    }

    function focusScannerInputAgain() {
        const input = getScannerInput();

        if (!input) {
            return;
        }

        setTimeout(function () {
            input.value = '';
            input.focus();

            if (typeof input.select === 'function') {
                input.select();
            }
        }, 80);
    }

    function bindScannerInput(input) {
        if (!input || input.dataset.barcodeScannerBound === '1') {
            return;
        }

        input.dataset.barcodeScannerBound = '1';
        input.setAttribute('autocomplete', 'off');

        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') {
                return;
            }

            const barcode = normalizeBarcode(input.value);

            if (!barcode) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            input.value = '';
            sendScannedBarcodeToCart(barcode);
        });
    }

    function initBarcodeScanner() {
        bindScannerInput(document.getElementById('productSearch'));
        bindScannerInput(document.getElementById('cartSearch'));
    }

    document.addEventListener('DOMContentLoaded', initBarcodeScanner);
    initBarcodeScanner();

    /*
    |--------------------------------------------------------------------------
    | Manual testing ke liye:
    | Console me posScanBarcodeToCart('123456') run kar sakte ho.
    |--------------------------------------------------------------------------
    */
    window.posScanBarcodeToCart = sendScannedBarcodeToCart;
})();


/* =========================================================
   POS REAL WORKING SHORTCUTS
   F3 Scan/Search | F5 Print Sale | F6 Hold Sale | F9 Clear Cart | F12 Complete Sale
========================================================= */
(function () {
    if (window.__POS_SHORTCUTS_BOUND__) return;
    window.__POS_SHORTCUTS_BOUND__ = true;

    window.__posCheckoutMode = window.__posCheckoutMode || '';

    function normalizeShortcutKey(e) {
        const codeMap = {
            114: 'F3',
            116: 'F5',
            117: 'F6',
            120: 'F9',
            123: 'F12'
        };

        const code = e.keyCode || e.which;
        if (codeMap[code]) return codeMap[code];

        const key = String(e.key || e.code || '').toUpperCase();
        if (['F3', 'F5', 'F6', 'F9', 'F12'].includes(key)) return key;

        return '';
    }

    function getCartItemsCountForShortcut() {
        return document.querySelectorAll('#cart [data-cart-item]').length;
    }

    function showShortcutMessage(message) {
        let toast = document.getElementById('posShortcutToast');

        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'posShortcutToast';
            toast.style.cssText = [
                'position:fixed',
                'right:18px',
                'bottom:18px',
                'z-index:999999',
                'min-width:220px',
                'max-width:360px',
                'padding:12px 14px',
                'border-radius:10px',
                'background:#0b1220',
                'color:#fff',
                'border:1px solid rgba(139,92,246,.45)',
                'box-shadow:0 18px 40px rgba(0,0,0,.35)',
                'font-size:13px',
                'font-weight:800',
                'display:none'
            ].join(';');
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.display = 'block';

        clearTimeout(window.__posShortcutToastTimer);
        window.__posShortcutToastTimer = setTimeout(function () {
            toast.style.display = 'none';
        }, 1800);
    }

    window.focusProductSearchShortcut = function () {
        const search = document.getElementById('productSearch') || document.querySelector('.search-input');

        if (!search) {
            showShortcutMessage('Product search field not found.');
            return;
        }

        search.focus();
        if (typeof search.select === 'function') {
            search.select();
        }

        showShortcutMessage('Scan/Search ready.');
    };

    window.clearCartShortcut = function () {
        if (typeof clearAllCartItems === 'function') {
            clearAllCartItems();
            return;
        }

        showShortcutMessage('Clear cart function not found.');
    };

    window.holdSaleShortcut = function () {
        if (getCartItemsCountForShortcut() <= 0) {
            showShortcutMessage('Cart is empty.');
            focusProductSearchShortcut();
            return;
        }

        if (!confirm('Hold current sale?')) {
            return;
        }

        window.__posCheckoutMode = 'hold';

        if (typeof checkout === 'function') {
            checkout();
            return;
        }

        showShortcutMessage('Checkout function not found.');
    };

    window.completeSaleShortcut = function () {
        if (getCartItemsCountForShortcut() <= 0) {
            showShortcutMessage('Cart is empty.');
            focusProductSearchShortcut();
            return;
        }

        window.__posCheckoutMode = 'complete';

        if (typeof checkout === 'function') {
            checkout();
            return;
        }

        showShortcutMessage('Checkout function not found.');
    };

    window.printCurrentSaleShortcut = function () {
        if (getCartItemsCountForShortcut() <= 0) {
            showShortcutMessage('Cart is empty.');
            focusProductSearchShortcut();
            return;
        }

        window.__posCheckoutMode = 'print';

        if (typeof checkout === 'function') {
            checkout();
            return;
        }

        showShortcutMessage('Checkout function not found.');
    };

    function runPosShortcut(e) {
        const key = normalizeShortcutKey(e);
        if (!key) return;

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        if (key === 'F3') {
            focusProductSearchShortcut();
            return;
        }
/*
|--------------------------------------------------------------------------
| F5 Print Shortcut Disabled
|--------------------------------------------------------------------------
| Print action UI se remove ho chuka hai.
| preventDefault pehle execute hota hai, isliye F5 page refresh bhi nahi karega.
|--------------------------------------------------------------------------
*/
if (key === 'F5') {
    return;
}

        if (key === 'F6') {
            holdSaleShortcut();
            return;
        }

        if (key === 'F9') {
            clearCartShortcut();
            return;
        }

        if (key === 'F12') {
            completeSaleShortcut();
            return;
        }
    }

    window.addEventListener('keydown', runPosShortcut, true);
    document.addEventListener('keydown', runPosShortcut, true);
})();

</script>
</body>
</html>