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
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);

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
$taxSql = "SELECT id, name, rate, type, application_on, inclusive, status
           FROM taxes
           WHERE client_id = {$client_id} AND status = 1
           ORDER BY rate DESC, id DESC";
$taxRes = $conn->query($taxSql);
if ($taxRes) {
    while ($row = $taxRes->fetch_assoc()) {
        $taxes[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| ITEMS - FAST INITIAL BATCH
|--------------------------------------------------------------------------
| HTML structure below remains unchanged so existing CSS stays pixel-perfect.
*/
$posInitialProductLimit = 48;
$items = [];
$productTaxMap = [];

$itemSql = "
    SELECT
        id,
        name,
        category,
        type,
        sku,
        image,
        price,
        has_discount,
        discount_value,
        has_max_discount,
        max_discount_percent,
        wholesaler_price_1,
        wholesaler_price_2,
        wholesaler_price_3
    FROM items
    WHERE client_id = {$client_id}
      AND is_raw_item = 0
    ORDER BY id DESC
    LIMIT {$posInitialProductLimit}
";
$itemRes = $conn->query($itemSql);

if ($itemRes) {
    while ($row = $itemRes->fetch_assoc()) {
        $cat = 'All';

        if (isset($row['category']) && $row['category'] !== '') {
            $cat = trim($row['category']);
        } elseif (isset($row['category_name']) && $row['category_name'] !== '') {
            $cat = trim($row['category_name']);
        } elseif (isset($row['item_category']) && $row['item_category'] !== '') {
            $cat = trim($row['item_category']);
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

    }
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
    $itemIds = array_map(static function ($item) {
        return (int)($item['id'] ?? 0);
    }, $items);

    $itemIds = array_values(array_filter($itemIds));

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
    <link rel="stylesheet" href="pos_screen.optimized.css?v=1.0.0">
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
    $imgSrc = 'pos-product-placeholder.svg';
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
                                        onerror="this.onerror=null;this.src='pos-product-placeholder.svg';"
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
                                       <span class="card-price-value"><?= number_format($cardRetailPrice, 0) ?></span>
                                        <?php if ($hasMaxDiscount === 1 && $maxDiscountPercent > 0): ?>
                                            <div class="item-discount-badge">Max <?= esc($maxDiscountLabel) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="add-btn">+</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
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
    productEndpoint: 'pos_products_api.php',
    productPageSize: <?= (int)$posInitialProductLimit ?>
};
</script>
<script src="pos_screen.optimized.js?v=1.0.0"></script>
<script src="pos_products.lazy-safe.js?v=2.0.0"></script>
</body>
</html>