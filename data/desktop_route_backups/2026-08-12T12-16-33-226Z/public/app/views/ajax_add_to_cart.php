<?php
session_start();

require_once($_SERVER['DOCUMENT_ROOT'].'/onerp/app/config/db.php');

$integrationHelperPath = $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/integration_helper.php';
if (is_file($integrationHelperPath)) {
    require_once $integrationHelperPath;
}

$businessSessionHelperPath =
    $_SERVER['DOCUMENT_ROOT']
    . '/onerp/app/app/helpers/includes/business_session_helper.php';

if (is_file($businessSessionHelperPath)) {
    require_once $businessSessionHelperPath;
}
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'expired',
        'message' => 'Session expired',
        'redirect' => '/onerp/app/onerp/app/public/app/views/login_view.php'
    ]);
    exit;
}

if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

function esc($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

function getTableSchema(mysqli $conn, string $table): array
{
    static $requestCache = [];

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    if ($safeTable === '') {
        return [];
    }

    if (array_key_exists($safeTable, $requestCache)) {
        return $requestCache[$safeTable];
    }

    $resolver = static function () use ($conn, $safeTable): array {
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");

        if (!$result) {
            return [];
        }

        $schema = [];

        while ($row = $result->fetch_assoc()) {
            $field = (string)($row['Field'] ?? '');

            if ($field !== '') {
                $schema[$field] = $row;
            }
        }

        $result->free();

        return $schema;
    };

    /*
    |--------------------------------------------------------------------------
    | Speed optimization
    |--------------------------------------------------------------------------
    | Purane code me checkout par har possible column ke liye alag
    | SHOW COLUMNS query chalti thi. Ab ek table ke tamam columns ek hi query
    | me load honge aur performance.php available ho to 1 hour cache rahenge.
    |--------------------------------------------------------------------------
    */
    if (function_exists('oneerpCacheRemember')) {
        $schema = oneerpCacheRemember(
            'oneerp_schema_' . $safeTable,
            3600,
            $resolver
        );

        if (!is_array($schema)) {
            $schema = [];
        }
    } else {
        $schema = $resolver();
    }

    $requestCache[$safeTable] = $schema;

    return $schema;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $schema = getTableSchema($conn, $table);

    return array_key_exists($column, $schema);
}

function tableExists(mysqli $conn, string $table): bool
{
    static $requestCache = [];

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    if ($safeTable === '') {
        return false;
    }

    if (array_key_exists($safeTable, $requestCache)) {
        return $requestCache[$safeTable];
    }

    $resolver = static function () use ($conn, $safeTable): bool {
        $escaped = $conn->real_escape_string($safeTable);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;

        if ($result instanceof mysqli_result) {
            $result->free();
        }

        return $exists;
    };

    if (function_exists('oneerpCacheRemember')) {
        $exists = (bool)oneerpCacheRemember(
            'oneerp_table_exists_' . $safeTable,
            3600,
            $resolver
        );
    } else {
        $exists = $resolver();
    }

    $requestCache[$safeTable] = $exists;

    return $exists;
}

function isWholesalePricingEnabled(mysqli $conn, int $client_id): bool
{
    if ($client_id <= 0) {
        return false;
    }

    if (function_exists('isIntegrationEnabled')) {
        return isIntegrationEnabled($conn, $client_id, 'Wholesale Pricing');
    }

    if (!tableExists($conn, 'integrations')) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM integrations
        WHERE client_id = ?
          AND integration_type = 'Wholesale Pricing'
          AND LOWER(TRIM(status)) IN ('active', 'enabled', '1')
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $client_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $enabled = $result && $result->num_rows > 0;

    $stmt->close();

    return $enabled;
}



function normalizePharmacyIndustryValue($value): string
{
    $value = strtolower(trim((string)$value));
    $value = str_replace(['-', ' '], '_', $value);

    if (in_array($value, ['pharma', 'pharmacy', 'pharmaceutical', 'medical', 'medicine', 'medical_store', 'medicine_store', 'chemist'], true)) {
        return 'pharmacy';
    }

    return $value;
}

function isPharmacyUnitFeatureEnabled(mysqli $conn, int $client_id): bool
{
    static $cache = [];

    if ($client_id <= 0) {
        return false;
    }

    if (isset($cache[$client_id])) {
        return $cache[$client_id];
    }

    $looksPharmacy = static function ($value): bool {
        $value = strtolower(trim((string)$value));
        $value = str_replace(['-', '_'], ' ', $value);

        return (
            strpos($value, 'pharmacy') !== false ||
            strpos($value, 'pharma') !== false ||
            strpos($value, 'medical') !== false ||
            strpos($value, 'medicine') !== false ||
            strpos($value, 'chemist') !== false
        );
    };

    $enabled = false;

    try {
        if (tableExists($conn, 'subscriptions') && tableExists($conn, 'plans')) {
            $selectParts = ["p.name AS plan_name"];

            if (hasColumn($conn, 'plans', 'industry')) {
                $selectParts[] = "p.industry AS plan_industry";
            } else {
                $selectParts[] = "'' AS plan_industry";
            }

            $whereParts = ["s.client_id = ?"];

            if (hasColumn($conn, 'subscriptions', 'status')) {
                $whereParts[] = "LOWER(TRIM(s.status)) IN ('active', 'enabled', '1')";
            }

            if (hasColumn($conn, 'subscriptions', 'end_date')) {
                $whereParts[] = "(s.end_date IS NULL OR s.end_date >= CURDATE())";
            }

            if (hasColumn($conn, 'plans', 'status')) {
                $whereParts[] = "LOWER(TRIM(p.status)) IN ('active', 'enabled', '1')";
            }

            $sql = "
                SELECT " . implode(', ', $selectParts) . "
                FROM subscriptions s
                INNER JOIN plans p ON p.id = s.plan_id
                WHERE " . implode(' AND ', $whereParts) . "
                ORDER BY s.id DESC
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param("i", $client_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $planText = trim(
                    (string)($row['plan_name'] ?? '') . ' ' .
                    (string)($row['plan_industry'] ?? '')
                );

                if ($looksPharmacy($planText)) {
                    $enabled = true;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback: login session based guard
        |--------------------------------------------------------------------------
        */
        if (!$enabled) {
            $sessionIndustry = (string)($_SESSION['client_industry'] ?? '');
            $isPharmacySession = (int)($_SESSION['is_pharmacy_client'] ?? 0);

            if ($isPharmacySession === 1 || $looksPharmacy($sessionIndustry)) {
                $enabled = true;
            }
        }
    } catch (Throwable $e) {
        $enabled = false;
    }

    $cache[$client_id] = $enabled;
    return $enabled;
}

/*
|--------------------------------------------------------------------------
| Check whether units are enabled for this specific product
|--------------------------------------------------------------------------
| Items page par "Use Product Units" ON hoga to 1 return karega.
| Cream, shampoo ya normal product ke liye OFF hoga to false.
|--------------------------------------------------------------------------
*/
function itemUsesProductUnits(
    mysqli $conn,
    int $clientId,
    int $productId
): bool {
    static $cache = [];

    if (
        $clientId <= 0 ||
        $productId <= 0
    ) {
        return false;
    }

    $cacheKey =
        $clientId . ':' . $productId;

    if (
        array_key_exists(
            $cacheKey,
            $cache
        )
    ) {
        return $cache[$cacheKey];
    }

    /*
    |--------------------------------------------------------------------------
    | Database column protection
    |--------------------------------------------------------------------------
    */
    if (
        !hasColumn(
            $conn,
            'items',
            'allow_single_unit_sale'
        )
    ) {
        $cache[$cacheKey] = false;

        return false;
    }

    $stmt = $conn->prepare("
        SELECT
            COALESCE(
                allow_single_unit_sale,
                0
            ) AS use_product_units

        FROM items

        WHERE id = ?
          AND client_id = ?
          AND status = 'active'
          AND deleted_at IS NULL

        LIMIT 1
    ");

    if (!$stmt) {
        $cache[$cacheKey] = false;

        return false;
    }

    $stmt->bind_param(
        'ii',
        $productId,
        $clientId
    );

    if (!$stmt->execute()) {
        $stmt->close();

        $cache[$cacheKey] = false;

        return false;
    }

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    $enabled =
        (int)(
            $row[
                'use_product_units'
            ] ?? 0
        ) === 1;

    $cache[$cacheKey] = $enabled;

    return $enabled;
}

function resolvePharmacyProductUnit(mysqli $conn, int $clientId, int $productId, int $unitId): ?array
{
    if ($clientId <= 0 || $productId <= 0 || $unitId <= 0) {
        return null;
    }
/*
|--------------------------------------------------------------------------
| Client-level pharmacy feature check
|--------------------------------------------------------------------------
*/
if (
    !isPharmacyUnitFeatureEnabled(
        $conn,
        $clientId
    ) ||
    !tableExists(
        $conn,
        'product_units'
    )
) {
    return null;
}

/*
|--------------------------------------------------------------------------
| Product-level unit toggle check
|--------------------------------------------------------------------------
| Product par Use Product Units OFF hai to frontend se unit_id aane ke
| bawajood backend us unit ko reject karega.
|--------------------------------------------------------------------------
*/
if (
    !itemUsesProductUnits(
        $conn,
        $clientId,
        $productId
    )
) {
    return null;
}

    $stmt = $conn->prepare("\n        SELECT\n            id,\n            product_id,\n            unit_name,\n            conversion_qty,\n            sale_price,\n            barcode,\n            is_base_unit,\n            is_default\n        FROM product_units\n        WHERE id = ?\n          AND product_id = ?\n          AND status = 1\n        LIMIT 1\n    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ii", $unitId, $productId);
    $stmt->execute();
    $unit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$unit) {
        return null;
    }

    $conversionQty = cleanMoney($unit['conversion_qty'] ?? 1);
    if ($conversionQty <= 0) {
        $conversionQty = 1.00;
    }

    return [
        'unit_id' => (int)$unit['id'],
        'unit_name' => trim((string)$unit['unit_name']),
        'unit_conversion_qty' => $conversionQty,
        'unit_price' => cleanMoney($unit['sale_price'] ?? 0),
        'unit_barcode' => trim((string)($unit['barcode'] ?? '')),
        'is_base_unit' => (int)($unit['is_base_unit'] ?? 0),
        'is_default' => (int)($unit['is_default'] ?? 0),
    ];
}

function refreshCartItemBaseQty(string $cartKey): void
{
    if ($cartKey === '' || !isset($_SESSION['pos_cart'][$cartKey])) {
        return;
    }

    $qty = max(1, (float)($_SESSION['pos_cart'][$cartKey]['qty'] ?? 1));
    $conversionQty = max(1, (float)($_SESSION['pos_cart'][$cartKey]['unit_conversion_qty'] ?? 1));

    $_SESSION['pos_cart'][$cartKey]['base_qty'] = round($qty * $conversionQty, 2);
}


/*
|--------------------------------------------------------------------------
| Remove unit metadata from non-unit cart products
|--------------------------------------------------------------------------
*/
function clearCartItemUnitData(
    string $cartKey
): void {
    if (
        $cartKey === '' ||
        !isset(
            $_SESSION[
                'pos_cart'
            ][$cartKey]
        )
    ) {
        return;
    }

    $qty = max(
        1,
        (float)(
            $_SESSION[
                'pos_cart'
            ][$cartKey]['qty'] ?? 1
        )
    );

    $currentPrice = max(
        0,
        (float)(
            $_SESSION[
                'pos_cart'
            ][$cartKey]['price'] ?? 0
        )
    );

    $_SESSION['pos_cart'][$cartKey][
        'unit_id'
    ] = 0;

    $_SESSION['pos_cart'][$cartKey][
        'unit_name'
    ] = '';

    $_SESSION['pos_cart'][$cartKey][
        'unit_conversion_qty'
    ] = 1;

    $_SESSION['pos_cart'][$cartKey][
        'base_qty'
    ] = round($qty, 2);

    $_SESSION['pos_cart'][$cartKey][
        'unit_price'
    ] = $currentPrice;
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

function cleanMoney($value): float
{
    if ($value === null) {
        return 0.00;
    }

    $value = str_replace([',', 'Rs', 'rs', 'PKR', 'pkr', '₨', ' '], '', trim((string)$value));

    if ($value === '' || !is_numeric($value)) {
        return 0.00;
    }

    return round((float)$value, 2);
}


function normalizeRetailerWalkInCustomerName(string $name, string $phone): string
{
    $name = trim($name);
    $genericNames = [
        'retailer / walk-in',
        'retailer/walk-in',
        'retailer / walkin',
        'retailer customer',
        'walk-in customer',
        'walkin customer',
    ];

    if ($name === '' || in_array(strtolower($name), $genericNames, true)) {
        return trim($phone) !== '' ? 'Walk-In Retailer' : '';
    }

    return $name;
}

function findOrCreateRetailerWalkInCustomer(
    mysqli $conn,
    int $clientId,
    int $branchId,
    int $userId,
    string $customerType,
    string $customerName,
    string $customerPhone
): int {
    if ($clientId <= 0 || !tableExists($conn, 'customers')) {
        return 0;
    }

    $typeNormalized = strtolower(trim($customerType));
    if (in_array($typeNormalized, ['wholesaler', 'wholesale'], true)) {
        return 0;
    }

    $customerPhone = trim($customerPhone);
    $customerName = normalizeRetailerWalkInCustomerName($customerName, $customerPhone);

    if ($customerName === '' && $customerPhone === '') {
        return 0;
    }

    if ($customerPhone !== '' && hasColumn($conn, 'customers', 'client_id') && hasColumn($conn, 'customers', 'phone')) {
        $stmtFind = $conn->prepare("\n            SELECT id\n            FROM customers\n            WHERE client_id = ?\n              AND phone = ?\n            ORDER BY id DESC\n            LIMIT 1\n        ");

        if ($stmtFind) {
            $stmtFind->bind_param("is", $clientId, $customerPhone);
            $stmtFind->execute();
            $found = $stmtFind->get_result()->fetch_assoc();
            $stmtFind->close();

            if (!empty($found['id'])) {
                return (int)$found['id'];
            }
        }
    }

    if ($customerName === '') {
        $customerName = 'Walk-In Retailer';
    }

    $columns = [];
    $placeholders = [];
    $values = [];
    $types = '';

    $possibleCustomerData = [
        'client_id' => [$clientId, 'i'],
        'branch_id' => [$branchId, 'i'],
        'name' => [$customerName, 's'],
        'phone' => [$customerPhone, 's'],
        'type' => ['Retailer', 's'],
        'email' => ['', 's'],
        'address' => ['', 's'],
        'created_by' => [$userId, 'i'],
        'user_id' => [$userId, 'i'],
    ];

    foreach ($possibleCustomerData as $column => [$value, $type]) {
        if (hasColumn($conn, 'customers', $column)) {
            $columns[] = "`{$column}`";
            $placeholders[] = '?';
            $values[] = $value;
            $types .= $type;
        }
    }

    if (hasColumn($conn, 'customers', 'created_at')) {
        $columns[] = '`created_at`';
        $placeholders[] = 'NOW()';
    }

    if (hasColumn($conn, 'customers', 'updated_at')) {
        $columns[] = '`updated_at`';
        $placeholders[] = 'NOW()';
    }

    if (empty($columns) || !in_array('`name`', $columns, true)) {
        return 0;
    }

    $sql = "INSERT INTO customers (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return 0;
    }

    if (!empty($values)) {
        $bindParams = [];
        $bindParams[] = $types;
        foreach ($values as $k => $v) {
            $bindParams[] = &$values[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $newCustomerId = (int)$conn->insert_id;
    $stmt->close();

    return $newCustomerId;
}

function calculateItemRetailPrice(array $itemRow): float
{
    /*
    |--------------------------------------------------------------------------
    | Maximum Discount Workflow
    |--------------------------------------------------------------------------
    | Item discount ab auto price discount nahi hai.
    | POS me product original retail price par add hoga.
    | Discount checkout par item-wise max discount cap ke hisaab se calculate hoga.
    |--------------------------------------------------------------------------
    */
    return cleanMoney($itemRow['price'] ?? 0);
}

function getItemMaxDiscountPercentFromRow(array $itemRow): ?float
{
    /*
    |--------------------------------------------------------------------------
    | New columns support
    |--------------------------------------------------------------------------
    | has_max_discount + max_discount_percent
    |--------------------------------------------------------------------------
    */
    $hasMaxDiscount = (int)($itemRow['has_max_discount'] ?? 0);
    $maxDiscount = cleanMoney($itemRow['max_discount_percent'] ?? 0);

    if ($hasMaxDiscount === 1 && $maxDiscount > 0) {
        return min(100, max(0, $maxDiscount));
    }

    /*
    |--------------------------------------------------------------------------
    | Backward compatibility
    |--------------------------------------------------------------------------
    | Current items.php old columns me hi max discount save kar sakta hai:
    | has_discount + discount_value
    | Isko auto discount nahi, sirf max discount cap treat karna hai.
    |--------------------------------------------------------------------------
    */
    $hasOldDiscount = (int)($itemRow['has_discount'] ?? 0);
    $oldDiscountValue = cleanMoney($itemRow['discount_value'] ?? 0);

    if ($hasOldDiscount === 1 && $oldDiscountValue > 0) {
        return min(100, max(0, $oldDiscountValue));
    }

    return null;
}

function getCartItemMaxDiscountPercent(mysqli $conn, int $clientId, array $cartItem): ?float
{
    static $requestCache = [];

    $sourceId = (int)($cartItem['source_id'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Custom product par koi max discount limit nahi hoti.
    | Is par POS entered discount full apply hoga.
    |--------------------------------------------------------------------------
    */
    if ($clientId <= 0 || $sourceId <= 0) {
        return null;
    }

    $cacheKey = $clientId . ':' . $sourceId;

    if (array_key_exists($cacheKey, $requestCache)) {
        return $requestCache[$cacheKey];
    }

    /*
    |--------------------------------------------------------------------------
    | Sirf discount columns fetch karo
    |--------------------------------------------------------------------------
    | Same product unit/wholesale ki multiple cart rows me ho to database query
    | sirf pehli dafa chalegi. Result current request me reuse hoga.
    |--------------------------------------------------------------------------
    */
    $selectColumns = ['id'];

    foreach ([
        'has_max_discount',
        'max_discount_percent',
        'has_discount',
        'discount_value'
    ] as $column) {
        if (hasColumn($conn, 'items', $column)) {
            $selectColumns[] = "`{$column}`";
        }
    }

    $stmt = $conn->prepare("
        SELECT " . implode(', ', $selectColumns) . "
        FROM items
        WHERE id = ?
          AND client_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        $fallback = cleanMoney($cartItem['max_discount_percent'] ?? 0);
        $resolved = $fallback > 0 ? min(100, max(0, $fallback)) : null;
        $requestCache[$cacheKey] = $resolved;

        return $resolved;
    }

    $stmt->bind_param("ii", $sourceId, $clientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $fallback = cleanMoney($cartItem['max_discount_percent'] ?? 0);
        $resolved = $fallback > 0 ? min(100, max(0, $fallback)) : null;
        $requestCache[$cacheKey] = $resolved;

        return $resolved;
    }

    $resolved = getItemMaxDiscountPercentFromRow($row);
    $requestCache[$cacheKey] = $resolved;

    return $resolved;
}

function calculateCartLineDiscount(mysqli $conn, int $clientId, array $cartItem, float $requestedDiscountPercent): array
{
    $qty = max(0, (float)($cartItem['qty'] ?? 0));
    $price = max(0, (float)($cartItem['price'] ?? 0));
    $lineSubtotal = round($qty * $price, 2);

    $requestedDiscountPercent = min(100, max(0, $requestedDiscountPercent));
    $maxDiscountPercent = getCartItemMaxDiscountPercent($conn, $clientId, $cartItem);

    /*
    |--------------------------------------------------------------------------
    | Final rule
    |--------------------------------------------------------------------------
    | No max discount set => full POS discount apply.
    | Max discount set    => min(POS discount, item max discount) apply.
    |--------------------------------------------------------------------------
    */
    $appliedDiscountPercent = $requestedDiscountPercent;

    if ($maxDiscountPercent !== null && $maxDiscountPercent > 0) {
        $appliedDiscountPercent = min($requestedDiscountPercent, $maxDiscountPercent);
    }

    $lineDiscount = round($lineSubtotal * ($appliedDiscountPercent / 100), 2);
    $lineNet = round(max(0, $lineSubtotal - $lineDiscount), 2);

    return [
        'line_subtotal' => $lineSubtotal,
        'max_discount_percent' => $maxDiscountPercent,
        'applied_discount_percent' => $appliedDiscountPercent,
        'line_discount' => $lineDiscount,
        'line_net' => $lineNet,
    ];
}

function clampDiscountPercent($value): float
{
    $percent = cleanMoney($value);

    if ($percent < 0) {
        $percent = 0;
    }

    if ($percent > 100) {
        $percent = 100;
    }

    return round($percent, 2);
}

function makeLineDiscountPriceKey($price): string
{
    return number_format(cleanMoney($price), 2, '.', '');
}

function makeLineDiscountNameKey($name): string
{
    return strtolower(trim((string)$name));
}

function parsePostedLineDiscountPayload(): array
{
    $payload = [
        'by_cart_key'     => [],
        'by_source_price' => [],
        'by_name_price'   => [],
        'by_source_id'    => [],
    ];

    $raw = $_POST['line_discounts'] ?? '';

    if (!is_string($raw) || trim($raw) === '') {
        return $payload;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return $payload;
    }

    $sourcePercentTracker = [];

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $percent = clampDiscountPercent(
            $row['discount_percent']
            ?? $row['discount']
            ?? $row['applied_discount_percent']
            ?? 0
        );

        $cartKey = trim((string)($row['cart_key'] ?? $row['cart_id'] ?? $row['id'] ?? ''));

        /*
        |--------------------------------------------------------------------------
        | cart_key agar "1" aaye to ignore karo.
        | POS row me data-cart-item="1" hota hai, wo real cart key nahi hota.
        |--------------------------------------------------------------------------
        */
        if ($cartKey !== '' && $cartKey !== '1') {
            $payload['by_cart_key'][$cartKey] = $percent;
        }

        $sourceId = (int)($row['item_id'] ?? $row['source_id'] ?? $row['product_id'] ?? 0);
        $priceKey = makeLineDiscountPriceKey($row['price'] ?? 0);

        if ($sourceId > 0) {
            $payload['by_source_price'][$sourceId . '|' . $priceKey] = $percent;
            $sourcePercentTracker[$sourceId][] = $percent;
        }

        $nameKey = makeLineDiscountNameKey($row['name'] ?? '');

        if ($nameKey !== '') {
            $payload['by_name_price'][$nameKey . '|' . $priceKey] = $percent;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Agar same source_id sirf ek discount value ke sath aaya hai,
    | to source_id fallback bhi allow kar do.
    |--------------------------------------------------------------------------
    */
    foreach ($sourcePercentTracker as $sourceId => $percentList) {
        $unique = array_values(array_unique(array_map(static function ($value) {
            return number_format((float)$value, 2, '.', '');
        }, $percentList)));

        if (count($unique) === 1) {
            $payload['by_source_id'][(int)$sourceId] = (float)$unique[0];
        }
    }

    return $payload;
}

function resolvePostedLineDiscountPercent(array $payload, string $cartKey, array $item, float $fallbackPercent): float
{
    $fallbackPercent = clampDiscountPercent($fallbackPercent);

    if ($cartKey !== '' && isset($payload['by_cart_key'][$cartKey])) {
        return clampDiscountPercent($payload['by_cart_key'][$cartKey]);
    }

    $sourceId = (int)($item['source_id'] ?? 0);
    $priceKey = makeLineDiscountPriceKey($item['price'] ?? 0);

    if ($sourceId > 0) {
        $sourcePriceKey = $sourceId . '|' . $priceKey;

        if (isset($payload['by_source_price'][$sourcePriceKey])) {
            return clampDiscountPercent($payload['by_source_price'][$sourcePriceKey]);
        }
    }

    $nameKey = makeLineDiscountNameKey($item['name'] ?? '');

    if ($nameKey !== '') {
        $namePriceKey = $nameKey . '|' . $priceKey;

        if (isset($payload['by_name_price'][$namePriceKey])) {
            return clampDiscountPercent($payload['by_name_price'][$namePriceKey]);
        }
    }

    if ($sourceId > 0 && isset($payload['by_source_id'][$sourceId])) {
        return clampDiscountPercent($payload['by_source_id'][$sourceId]);
    }

    return $fallbackPercent;
}


function normalizeWholesalerTier(
    array $post,
    array $itemRow = [],
    ?mysqli $conn = null,
    int $clientId = 0,
    bool $wholesaleEnabled = false
): array {
    $tierTypeRaw = strtolower(trim((string)($post['price_tier_type'] ?? 'default')));
    $tierNo      = (int)($post['price_tier_no'] ?? 0);
    $label       = trim((string)($post['price_tier_label'] ?? ''));

    /*
    |--------------------------------------------------------------------------
    | Retail price resolver
    |--------------------------------------------------------------------------
    | Retailer mode me item original price par cart me add hoga.
    | Maximum discount checkout par item-wise calculate hoga.
    */
    $basePrice = cleanMoney($itemRow['price'] ?? 0);
    $retailPrice = calculateItemRetailPrice($itemRow);

    /*
    |--------------------------------------------------------------------------
    | Wholesale tier resolver
    |--------------------------------------------------------------------------
    | Wholesale Pricing integration inactive ho to wholesale request ignore hogi.
    | Wholesale active ho to wholesale price use hogi, discount wholesale par apply nahi hoga.
    */
    if (preg_match('/^wholesale[_-]?(\d+)$/', $tierTypeRaw, $match)) {
        $tierTypeRaw = 'wholesale';
        $tierNo = (int)$match[1];
    }

    if ($tierTypeRaw !== 'wholesale' || $tierNo <= 0 || !$wholesaleEnabled) {
        return [
            'price_tier_type'  => 'default',
            'price_tier_no'    => 0,
            'price_tier_label' => null,
            'selected_price'   => $retailPrice,
        ];
    }

    $price = 0.00;
    $dynamicLabel = '';
    $itemId = (int)($itemRow['id'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | New dynamic wholesale table support
    |--------------------------------------------------------------------------
    */
    if ($conn instanceof mysqli && $clientId > 0 && $itemId > 0 && tableExists($conn, 'item_wholesale_prices')) {
        $stmt = $conn->prepare("
            SELECT label, price
            FROM item_wholesale_prices
            WHERE client_id = ?
              AND item_id = ?
              AND sort_order = ?
              AND COALESCE(price, 0) > 0
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("iii", $clientId, $itemId, $tierNo);
            $stmt->execute();
            $tierRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($tierRow) {
                $price = cleanMoney($tierRow['price'] ?? 0);
                $dynamicLabel = trim((string)($tierRow['label'] ?? ''));
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Old 3 wholesale columns fallback
    |--------------------------------------------------------------------------
    */
    if ($price <= 0) {
        if ($tierNo === 1) {
            $price = cleanMoney($itemRow['wholesaler_price_1'] ?? 0);
        } elseif ($tierNo === 2) {
            $price = cleanMoney($itemRow['wholesaler_price_2'] ?? 0);
        } elseif ($tierNo === 3) {
            $price = cleanMoney($itemRow['wholesaler_price_3'] ?? 0);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | If selected wholesale tier has no price, fallback to normal retail base price.
    |--------------------------------------------------------------------------
    */
    if ($price <= 0) {
        $price = $basePrice;
    }

    if ($label === '') {
        $label = $dynamicLabel !== '' ? $dynamicLabel : 'Wholesale ' . $tierNo;
    }

    return [
        'price_tier_type'  => 'wholesale',
        'price_tier_no'    => $tierNo,
        'price_tier_label' => $label,
        'selected_price'   => $price,
    ];
}

function moveCartItemToTop(string $key): void
{
    if ($key === '' || !isset($_SESSION['pos_cart'][$key])) {
        return;
    }

    $item = $_SESSION['pos_cart'][$key];
    unset($_SESSION['pos_cart'][$key]);
    $_SESSION['pos_cart'] = [$key => $item] + $_SESSION['pos_cart'];
    $_SESSION['pos_cart_last_key'] = $key;
}

function renderCart(): string
{
    $cart = $_SESSION['pos_cart'] ?? [];

    if (empty($cart)) {
        return '<div class="empty-state">Cart is empty</div>';
    }

    $html = '';

    foreach ($cart as $key => $item) {
        $id        = esc($key);
        $sourceId  = (int)($item['source_id'] ?? 0);
        $name      = esc($item['name'] ?? 'Item');
        $sku       = esc($item['sku'] ?? '');
        $barcode   = esc($item['barcode'] ?? '');
        $qty       = (float)($item['qty'] ?? 1);
        $price     = (float)($item['price'] ?? 0);
        $taxRate   = (float)($item['tax_rate'] ?? 0);
        $itemType  = esc($item['item_type'] ?? 'product');
        $tierType  = strtolower(trim((string)($item['price_tier_type'] ?? 'default')));
        $tierNo    = (int)($item['price_tier_no'] ?? 0);
        $tierLabel = trim((string)($item['price_tier_label'] ?? ''));
        $hasMaxDiscount = (int)($item['has_max_discount'] ?? 0);
        $maxDiscountPercent = (float)($item['max_discount_percent'] ?? 0);
        $unitId = (int)($item['unit_id'] ?? 0);
        $unitNameRaw = trim((string)($item['unit_name'] ?? ''));
        $unitConversionQty = max(1, (float)($item['unit_conversion_qty'] ?? 1));
        $baseQty = max(0, (float)($item['base_qty'] ?? ($qty * $unitConversionQty)));
        $unitBadge = ($unitId > 0 && $unitNameRaw !== '')
            ? '<div class="pos-cart-badge">Unit: ' . esc($unitNameRaw) . '</div>'
            : '';
        $tierBadge = ($tierType === 'wholesale' && $tierNo > 0)
            ? '<div class="pos-cart-badge">' . esc($tierLabel !== '' ? $tierLabel : ('Wholesaler ' . $tierNo)) . '</div>'
            : '';

        $lineTotal = $qty * $price;
        $taxAmount = $lineTotal * ($taxRate / 100);

        $html .= '
        <div class="pos-cart-item"
             data-cart-item="1"
             data-id="' . $id . '"
             data-cart-id="' . $id . '"
             data-source-id="' . $sourceId . '"
             data-name="' . $name . '"
             data-sku="' . $sku . '"
             data-barcode="' . $barcode . '"
             data-item-type="' . $itemType . '"
             data-tax-rate="' . $taxRate . '"
             data-tax-amount="' . $taxAmount . '"
             data-has-max-discount="' . $hasMaxDiscount . '"
             data-max-discount-percent="' . esc($maxDiscountPercent) . '"
             data-unit-id="' . $unitId . '"
             data-unit-name="' . esc($unitNameRaw) . '"
             data-unit-conversion-qty="' . esc($unitConversionQty) . '"
             data-base-qty="' . esc($baseQty) . '">
            <div class="pos-cart-left">
                <div class="pos-cart-head">
                    <div class="pos-cart-title">' . $name . '</div>
                    ' . $tierBadge . '
                    ' . $unitBadge . '
                    ' . ($taxRate > 0 ? '<div class="pos-cart-badge">Tax ' . number_format($taxRate, 2) . '%</div>' : '') . '
                    <div class="pos-cart-sku">' . $sku . '</div>
                </div>

                <div class="pos-cart-controls">
                    <button type="button"
                            class="pos-cart-btn btn-minus"
                            data-cart-minus="1"
                            data-id="' . $id . '"
                            data-qty="' . $qty . '"
                            data-price="' . $price . '">-</button>

                    <input type="number"
                           class="pos-cart-qty"
                           value="' . esc($qty) . '"
                           min="1"
                           data-cart-qty-input="1"
                           data-id="' . $id . '"
                           data-price="' . $price . '">

                    <button type="button"
                            class="pos-cart-btn btn-plus"
                            data-cart-plus="1"
                            data-id="' . $id . '"
                            data-qty="' . $qty . '"
                            data-price="' . $price . '">+</button>

                    <button type="button"
                            class="pos-cart-btn btn-remove"
                            data-cart-remove="1"
                            data-id="' . $id . '">×</button>
                </div>
            </div>

            <div class="pos-cart-right">
                <input type="number"
                       class="pos-cart-price"
                      value="' . esc(number_format($price, 2, '.', '')) . '"
step="0.01"
                       min="0"
                       data-cart-price-input="1"
                       data-id="' . $id . '"
                       data-qty="' . $qty . '">

                <div class="pos-cart-total"
                     data-line-total="' . esc($lineTotal) . '"
                     data-line-qty="' . esc($qty) . '"
                     data-tax-amount="' . esc($taxAmount) . '">
               Rs ' . number_format($lineTotal, 2, '.', ',') . '
                </div>
            </div>
        </div>';
    }

    return $html;
}

function getPostedAction(): string
{
    return trim((string)($_POST['action'] ?? ''));
}


function resolveSalesStatusValue(mysqli $conn, string $desiredStatus): string
{
    $desiredStatus = strtolower(trim($desiredStatus));
    $fallback = $desiredStatus === 'hold' ? 'hold' : 'completed';

    $salesSchema = getTableSchema($conn, 'sales');

    if (!isset($salesSchema['status'])) {
        return $fallback;
    }

    $type = strtolower((string)($salesSchema['status']['Type'] ?? ''));

    /*
    |--------------------------------------------------------------------------
    | Non-enum/varchar status column
    |--------------------------------------------------------------------------
    */
    if (!preg_match('/^enum\((.*)\)$/i', $type, $match)) {
        return $fallback;
    }

    $enumValues = str_getcsv($match[1], ',', "'");
    $enumValues = array_values(array_filter(array_map(static function ($value) {
        return trim((string)$value);
    }, $enumValues), static function ($value) {
        return $value !== '';
    }));

    if (empty($enumValues)) {
        return $fallback;
    }

    $enumMap = [];
    foreach ($enumValues as $value) {
        $enumMap[strtolower($value)] = $value;
    }

    $preferences = $desiredStatus === 'hold'
        ? ['hold', 'held', 'on_hold', 'save_hold', 'saved_hold', 'pending', 'draft', 'unpaid']
        : ['completed', 'complete', 'paid', 'success', 'done', 'active'];

    foreach ($preferences as $candidate) {
        if (isset($enumMap[$candidate])) {
            return $enumMap[$candidate];
        }
    }

    return $enumValues[0];
}

$action = getPostedAction();



/*
|--------------------------------------------------------------------------
| SCAN BARCODE ITEM
|--------------------------------------------------------------------------
| Existing add/update/checkout logic untouched.
| Scanner se barcode aayega, item_barcodes table se item find hoga,
| phir same pos_cart structure me add hoga.
|--------------------------------------------------------------------------
*/
if ($action === 'scan_barcode') {
    $barcode  = trim((string)($_POST['barcode'] ?? ''));
    $taxRate  = (float)($_POST['tax_rate'] ?? 0);
    $itemType = trim((string)($_POST['item_type'] ?? 'product'));

    if ($barcode === '') {
        http_response_code(404);
        echo 'Barcode is empty';
        exit;
    }

    $clientIdForItem = (int)($_SESSION['client_id'] ?? 0);

    if ($clientIdForItem <= 0) {
        http_response_code(404);
        echo 'Invalid client';
        exit;
    }

    $row = null;
    $scannedBarcode = $barcode;

    /*
    |--------------------------------------------------------------------------
    | Main barcode table lookup
    |--------------------------------------------------------------------------
    | Tumhare generated barcodes item_barcodes table me hain.
    |--------------------------------------------------------------------------
    */
    if (tableExists($conn, 'item_barcodes')) {
   $stmt = $conn->prepare("
    SELECT 
        i.*,
        ib.barcode AS scanned_barcode
    FROM item_barcodes ib
    INNER JOIN items i ON i.id = ib.item_id
    WHERE ib.client_id = ?
      AND i.client_id = ?
      AND ib.barcode = ?
      AND COALESCE(i.is_raw_item, 0) = 0
      AND i.status = 'active'
      AND i.deleted_at IS NULL
    LIMIT 1
");

        if ($stmt) {
            $stmt->bind_param("iis", $clientIdForItem, $clientIdForItem, $barcode);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback
    |--------------------------------------------------------------------------
    | Agar kisi old item me barcode/sku direct items table me ho.
    |--------------------------------------------------------------------------
    */
    if (!$row) {
        $fallbackWhere = [];
        $fallbackTypes = 'i';
        $fallbackValues = [$clientIdForItem];

        if (hasColumn($conn, 'items', 'barcode')) {
            $fallbackWhere[] = "barcode = ?";
            $fallbackTypes .= 's';
            $fallbackValues[] = $barcode;
        }

        if (hasColumn($conn, 'items', 'sku')) {
            $fallbackWhere[] = "sku = ?";
            $fallbackTypes .= 's';
            $fallbackValues[] = $barcode;
        }

        if (!empty($fallbackWhere)) {
        $fallbackSql = "
    SELECT *
    FROM items
    WHERE client_id = ?
      AND COALESCE(is_raw_item, 0) = 0
      AND status = 'active'
      AND deleted_at IS NULL
      AND (" . implode(' OR ', $fallbackWhere) . ")
    LIMIT 1
";

            $stmt = $conn->prepare($fallbackSql);

            if ($stmt) {
                $bindParams = [];
                $bindParams[] = $fallbackTypes;

                foreach ($fallbackValues as $k => $v) {
                    $bindParams[] = &$fallbackValues[$k];
                }

                call_user_func_array([$stmt, 'bind_param'], $bindParams);
                $stmt->execute();

                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;

                $stmt->close();
            }
        }
    }

    if (!$row) {
        http_response_code(404);
        echo 'Barcode product not found';
        exit;
    }

    if (!empty($row['scanned_barcode'])) {
        $scannedBarcode = trim((string)$row['scanned_barcode']);
    } elseif (!empty($row['barcode'])) {
        $scannedBarcode = trim((string)$row['barcode']);
    }

    $itemId = (int)($row['id'] ?? 0);

    if ($itemId <= 0) {
        http_response_code(404);
        echo 'Invalid barcode item';
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Same existing cart logic as ADD ITEM
    |--------------------------------------------------------------------------
    */
    $wholesaleEnabled = isWholesalePricingEnabled($conn, $clientIdForItem);
    $tierData = normalizeWholesalerTier($_POST, $row, $conn, $clientIdForItem, $wholesaleEnabled);

    /*
    |--------------------------------------------------------------------------
    | PHARMACY UNIT PRICING
    |--------------------------------------------------------------------------
    | POS screen se unit_id aayega. Sirf pharmacy plan clients ke liye accept hoga.
    | Same product ki Tablet / Strip / Box cart me alag line banegi.
    */
    $postedUnitId = (int)($_POST['unit_id'] ?? 0);
    $unitData = resolvePharmacyProductUnit($conn, $clientIdForItem, $itemId, $postedUnitId);

    if ($unitData && $tierData['price_tier_type'] !== 'wholesale') {
        $tierData['selected_price'] = $unitData['unit_price'];
    }

    $itemMaxDiscountPercent = getItemMaxDiscountPercentFromRow($row);
    $itemHasMaxDiscount = ($itemMaxDiscountPercent !== null && $itemMaxDiscountPercent > 0) ? 1 : 0;

    $key = 'item_' . $itemId;

    if ($unitData && (int)$unitData['unit_id'] > 0) {
        // Same product ki Tablet / Strip / Box units ko cart me alag rows rakhna zaroori hai.
        $key .= '_unit_' . (int)$unitData['unit_id'];
    }

    if ($tierData['price_tier_type'] === 'wholesale' && $tierData['price_tier_no'] > 0) {
        $key .= '_wholesale_' . $tierData['price_tier_no'];
    }

    if (isset($_SESSION['pos_cart'][$key])) {
        $_SESSION['pos_cart'][$key]['qty'] += 1;
        $_SESSION['pos_cart'][$key]['price'] = $tierData['selected_price'] > 0
            ? $tierData['selected_price']
            : (float)($row['price'] ?? 0);
        $_SESSION['pos_cart'][$key]['barcode'] = $scannedBarcode;
        $_SESSION['pos_cart'][$key]['price_tier_type'] = $tierData['price_tier_type'];
        $_SESSION['pos_cart'][$key]['price_tier_no'] = $tierData['price_tier_no'];
        $_SESSION['pos_cart'][$key]['price_tier_label'] = $tierData['price_tier_label'];
        $_SESSION['pos_cart'][$key]['has_max_discount'] = $itemHasMaxDiscount;
        $_SESSION['pos_cart'][$key]['max_discount_percent'] = $itemMaxDiscountPercent !== null ? $itemMaxDiscountPercent : 0;

if ($unitData) {
    $_SESSION['pos_cart'][$key][
        'unit_id'
    ] = (int)$unitData['unit_id'];

    $_SESSION['pos_cart'][$key][
        'unit_name'
    ] = (string)$unitData['unit_name'];

    $_SESSION['pos_cart'][$key][
        'unit_conversion_qty'
    ] = (float)$unitData[
        'unit_conversion_qty'
    ];

    $_SESSION['pos_cart'][$key][
        'unit_price'
    ] = (float)$unitData[
        'unit_price'
    ];

    if (
        !empty(
            $unitData[
                'unit_barcode'
            ]
        )
    ) {
        $_SESSION['pos_cart'][$key][
            'barcode'
        ] = $unitData[
            'unit_barcode'
        ];
    }

    refreshCartItemBaseQty($key);

} else {
    /*
    |--------------------------------------------------------------------------
    | Product units disabled
    |--------------------------------------------------------------------------
    | Purani Piece/Tablet metadata session mein nahi rehne deni.
    |--------------------------------------------------------------------------
    */
    clearCartItemUnitData($key);
}
    } else {
        $_SESSION['pos_cart'][$key] = [
            'source_id' => $itemId,
            'name'      => $row['name'] ?? 'Item',
            'sku'       => $row['sku'] ?? ($row['barcode'] ?? ('ITEM-' . $itemId)),
            'barcode'   => ($unitData && !empty($unitData['unit_barcode'])) ? $unitData['unit_barcode'] : $scannedBarcode,
            'qty'       => 1,
            'price'     => $tierData['selected_price'] > 0 ? $tierData['selected_price'] : (float)($row['price'] ?? 0),
            'tax_rate'  => $taxRate,
            'item_type' => $itemType ?: getItemType($row),
            'price_tier_type'  => $tierData['price_tier_type'],
            'price_tier_no'    => $tierData['price_tier_no'],
            'price_tier_label' => $tierData['price_tier_label'],
            'has_max_discount' => $itemHasMaxDiscount,
            'max_discount_percent' => $itemMaxDiscountPercent !== null ? $itemMaxDiscountPercent : 0,
            'unit_id' => $unitData ? (int)$unitData['unit_id'] : 0,
            'unit_name' => $unitData ? $unitData['unit_name'] : '',
            'unit_conversion_qty' => $unitData ? (float)$unitData['unit_conversion_qty'] : 1,
            'base_qty' => $unitData ? round(1 * (float)$unitData['unit_conversion_qty'], 2) : 1,
            'unit_price' => $unitData ? (float)$unitData['unit_price'] : ($tierData['selected_price'] > 0 ? (float)$tierData['selected_price'] : (float)($row['price'] ?? 0)),
        ];
    }

    moveCartItemToTop($key);

    echo renderCart();
    exit;
}

/*
|--------------------------------------------------------------------------
| ADD ITEM
|--------------------------------------------------------------------------
*/
if ($action === 'add') {
    $itemId   = (int)($_POST['id'] ?? 0);
    $taxRate  = (float)($_POST['tax_rate'] ?? 0);
    $itemType = trim((string)($_POST['item_type'] ?? 'product'));

    if ($itemId <= 0) {
        echo renderCart();
        exit;
    }

$stmt = $conn->prepare("
    SELECT *
    FROM items
    WHERE id = ?
      AND client_id = ?
      AND status = 'active'
      AND deleted_at IS NULL
    LIMIT 1
");
    if (!$stmt) {
        echo renderCart();
        exit;
    }

    $clientIdForItem = (int)($_SESSION['client_id'] ?? 0);
    $stmt->bind_param("ii", $itemId, $clientIdForItem);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        echo renderCart();
        exit;
    }

    $wholesaleEnabled = isWholesalePricingEnabled($conn, $clientIdForItem);
    $tierData = normalizeWholesalerTier($_POST, $row, $conn, $clientIdForItem, $wholesaleEnabled);

    /*
    |--------------------------------------------------------------------------
    | PHARMACY UNIT PRICING
    |--------------------------------------------------------------------------
    | POS screen se unit_id aata hai. Backend product_units se price verify karega.
    | Is se Tablet - Rs 5 cart me Rs 5 hi calculate hoga, item.price Rs 400 nahi.
    |--------------------------------------------------------------------------
    */
    $postedUnitId = (int)($_POST['unit_id'] ?? 0);
    $unitData = resolvePharmacyProductUnit($conn, $clientIdForItem, $itemId, $postedUnitId);

    if ($unitData && $tierData['price_tier_type'] !== 'wholesale') {
        $unitPrice = (float)($unitData['unit_price'] ?? 0);

        if ($unitPrice > 0) {
            $tierData['selected_price'] = $unitPrice;
        }
    }

    $itemMaxDiscountPercent = getItemMaxDiscountPercentFromRow($row);
    $itemHasMaxDiscount = ($itemMaxDiscountPercent !== null && $itemMaxDiscountPercent > 0) ? 1 : 0;

    $unitSuffix = ($unitData && (int)$unitData['unit_id'] > 0) ? '_unit_' . (int)$unitData['unit_id'] : '';
    $key = 'item_' . $itemId . $unitSuffix;

    if ($tierData['price_tier_type'] === 'wholesale' && $tierData['price_tier_no'] > 0) {
        // Same product ke different wholesaler rates ko cart me alag rows rakhna zaroori hai.
        $key .= '_wholesale_' . $tierData['price_tier_no'];
    }

    if (isset($_SESSION['pos_cart'][$key])) {
        $_SESSION['pos_cart'][$key]['qty'] += 1;
        $_SESSION['pos_cart'][$key]['price'] = $tierData['selected_price'] > 0
            ? $tierData['selected_price']
            : (float)($row['price'] ?? 0);
        $_SESSION['pos_cart'][$key]['price_tier_type'] = $tierData['price_tier_type'];
        $_SESSION['pos_cart'][$key]['price_tier_no'] = $tierData['price_tier_no'];
        $_SESSION['pos_cart'][$key]['price_tier_label'] = $tierData['price_tier_label'];
        $_SESSION['pos_cart'][$key]['has_max_discount'] = $itemHasMaxDiscount;
        $_SESSION['pos_cart'][$key]['max_discount_percent'] = $itemMaxDiscountPercent !== null ? $itemMaxDiscountPercent : 0;
if ($unitData) {
    $_SESSION['pos_cart'][$key][
        'unit_id'
    ] = (int)$unitData['unit_id'];

    $_SESSION['pos_cart'][$key][
        'unit_name'
    ] = (string)$unitData['unit_name'];

    $_SESSION['pos_cart'][$key][
        'unit_conversion_qty'
    ] = (float)$unitData[
        'unit_conversion_qty'
    ];

    $_SESSION['pos_cart'][$key][
        'unit_price'
    ] = (float)$unitData[
        'unit_price'
    ];

    if (
        !empty(
            $unitData[
                'unit_barcode'
            ]
        )
    ) {
        $_SESSION['pos_cart'][$key][
            'barcode'
        ] = $unitData[
            'unit_barcode'
        ];
    }

    refreshCartItemBaseQty($key);

} else {
    /*
    |--------------------------------------------------------------------------
    | Product units disabled
    |--------------------------------------------------------------------------
    | Purani Piece/Tablet metadata session mein nahi rehne deni.
    |--------------------------------------------------------------------------
    */
    clearCartItemUnitData($key);
}
    } else {
        $_SESSION['pos_cart'][$key] = [
            'source_id' => $itemId,
            'name'      => $row['name'] ?? 'Item',
            'sku'       => $row['sku'] ?? ($row['barcode'] ?? ('ITEM-' . $itemId)),
            'barcode'   => ($unitData && !empty($unitData['unit_barcode'])) ? $unitData['unit_barcode'] : ($row['barcode'] ?? ''),
            'qty'       => 1,
            'price'     => $tierData['selected_price'] > 0 ? $tierData['selected_price'] : (float)($row['price'] ?? 0),
            'tax_rate'  => $taxRate,
            'item_type' => $itemType ?: getItemType($row),
            'price_tier_type'  => $tierData['price_tier_type'],
            'price_tier_no'    => $tierData['price_tier_no'],
            'price_tier_label' => $tierData['price_tier_label'],
            'has_max_discount' => $itemHasMaxDiscount,
            'max_discount_percent' => $itemMaxDiscountPercent !== null ? $itemMaxDiscountPercent : 0,
            'unit_id' => $unitData ? (int)$unitData['unit_id'] : 0,
            'unit_name' => $unitData ? $unitData['unit_name'] : '',
            'unit_conversion_qty' => $unitData ? (float)$unitData['unit_conversion_qty'] : 1,
            'base_qty' => $unitData ? round(1 * (float)$unitData['unit_conversion_qty'], 2) : 1,
            'unit_price' => $unitData ? (float)$unitData['unit_price'] : ($tierData['selected_price'] > 0 ? (float)$tierData['selected_price'] : (float)($row['price'] ?? 0)),
        ];
    }

    moveCartItemToTop($key);

    echo renderCart();
    exit;
}

/*
|--------------------------------------------------------------------------
| ADD CUSTOM PRODUCT
|--------------------------------------------------------------------------
*/
if ($action === 'add_custom') {
    $name     = trim((string)($_POST['name'] ?? ''));
    $price    = (float)($_POST['price'] ?? 0);
    $qty      = (int)($_POST['qty'] ?? 1);
    $taxRate  = (float)($_POST['tax_rate'] ?? 0);
    $itemType = trim((string)($_POST['item_type'] ?? 'custom'));

    if ($name === '' || $price <= 0 || $qty <= 0) {
        echo renderCart();
        exit;
    }

    $key = 'custom_' . uniqid('', true);

    $_SESSION['pos_cart'][$key] = [
        'source_id' => 0,
        'name'      => $name,
        'sku'       => 'CUSTOM',
        'barcode'   => '',
        'qty'       => $qty,
        'price'     => $price,
        'tax_rate'  => $taxRate,
        'item_type' => $itemType ?: 'custom',
        'price_tier_type'  => 'default',
        'price_tier_no'    => 0,
        'price_tier_label' => null,
        'has_max_discount' => 0,
        'max_discount_percent' => 0,
    ];

    moveCartItemToTop($key);

    echo renderCart();
    exit;
}

/*
|--------------------------------------------------------------------------
| UPDATE ITEM
|--------------------------------------------------------------------------
*/
if ($action === 'update') {
    $id    = trim((string)($_POST['id'] ?? ''));
    $qty   = (float)($_POST['qty'] ?? 1);
    $price = (float)($_POST['price'] ?? 0);

    if ($id !== '' && isset($_SESSION['pos_cart'][$id])) {
        $_SESSION['pos_cart'][$id]['qty'] = max(1, $qty);
        $_SESSION['pos_cart'][$id]['price'] = max(0, $price);
        refreshCartItemBaseQty($id);
        moveCartItemToTop($id);
    }

    echo renderCart();
    exit;
}

/*
|--------------------------------------------------------------------------
| REMOVE ITEM
|--------------------------------------------------------------------------
*/
if ($action === 'remove') {
    $id = trim((string)($_POST['id'] ?? ''));

    if ($id !== '' && isset($_SESSION['pos_cart'][$id])) {
        unset($_SESSION['pos_cart'][$id]);
    }

    echo renderCart();
    exit;
}

/*
|--------------------------------------------------------------------------
| RENDER CART
|--------------------------------------------------------------------------
*/
if ($action === 'render') {
    echo renderCart();
    exit;
}

/*
|--------------------------------------------------------------------------
| CHECKOUT
|--------------------------------------------------------------------------
*/
if ($action === 'checkout' || $action === 'save_hold' || $action === 'hold_sale') {
    $isHoldAction = in_array($action, ['save_hold', 'hold_sale'], true);
    $saleStatus = resolveSalesStatusValue($conn, $isHoldAction ? 'hold' : 'completed');

    $cart = $_SESSION['pos_cart'] ?? [];

    if (empty($cart)) {
        jsonResponse([
            'status' => 'error',
            'message' => 'Cart is empty'
        ], 400);
    }

    $clientId      = (int)($_SESSION['client_id'] ?? 0);
    $branchId      = (int)($_SESSION['branch_id'] ?? 0);
    $customerId       = (int)($_POST['customer_id'] ?? 0);
    $postedCustomerName  = trim((string)($_POST['customer_name'] ?? ''));
    $postedCustomerPhone = trim((string)($_POST['customer_phone'] ?? ''));
    $rawCustomerType  = trim((string)($_POST['type'] ?? 'Walk-in'));

    // POS buttons Wholesale1 / Wholesale2 / Wholesale3 hain, lekin customers/sales me type Wholesaler hi safe hai.
    $customerType = in_array($rawCustomerType, ['Wholesale1', 'Wholesale2', 'Wholesale3', 'Wholesale 1', 'Wholesale 2', 'Wholesale 3'], true)
        ? 'Wholesaler'
        : $rawCustomerType;

    if (trim($customerType) === '') {
        $customerType = 'Retailer';
    }

    $userId        = (int)($_SESSION['user_id'] ?? 0);

    if ($customerId <= 0) {
        $createdCustomerId = findOrCreateRetailerWalkInCustomer(
            $conn,
            $clientId,
            $branchId,
            $userId,
            $customerType,
            $postedCustomerName,
            $postedCustomerPhone
        );

        if ($createdCustomerId > 0) {
            $customerId = $createdCustomerId;
        }
    }

$discountPer   = clampDiscountPercent($_POST['discount'] ?? 0);
$taxPer        = max(0, (float)($_POST['tax'] ?? 0));
$paymentMethod = trim((string)($_POST['payment_method'] ?? 'cash'));

/* ========================================================================
   BUSINESS SESSION TAG
   Existing checkout ko block/change nahi karega.
======================================================================== */

$businessSessionId = null;

if (
    !$isHoldAction
    && function_exists('oneerpBusinessCurrentSession')
) {
    $activeBusinessSession =
        oneerpBusinessCurrentSession(
            $conn,
            $clientId,
            $branchId
        );

    if (
        is_array($activeBusinessSession)
        && !empty($activeBusinessSession['id'])
    ) {
        $businessSessionId =
            (int)$activeBusinessSession['id'];
    }
}

/*
|--------------------------------------------------------------------------
| ITEM-WISE DISCOUNT PAYLOAD
|--------------------------------------------------------------------------
| POS screen se line_discounts JSON aaye to har row ka discount use hoga.
| Agar line_discounts na aaye, old workflow same rahega: order discount apply hoga.
|--------------------------------------------------------------------------
*/
$lineDiscountPayload = parsePostedLineDiscountPayload();

    $subtotal = 0.00;
    $totalQty = 0.00;
    $discountAmount = 0.00;
    $itemWiseTaxTotal = 0.00;
    $checkoutLineDiscounts = [];

    /*
    |--------------------------------------------------------------------------
    | MAXIMUM DISCOUNT BACKEND CALCULATION
    |--------------------------------------------------------------------------
    | POS par cashier jo discount percent enter karega wo block nahi hoga.
    | Har item par alag calculation hogi:
    |
    | - Item max discount set hai => min(POS discount, item max discount)
    | - Item max discount set nahi => POS discount full apply
    |
    | Example:
    | POS discount 10%
    | Panadol max 5% => Panadol par 5%
    | Flagyl no max  => Flagyl par 10%
    |--------------------------------------------------------------------------
    */
    foreach ($cart as $cartKey => $item) {
        $qty      = max(0, (float)($item['qty'] ?? 0));
        $price    = max(0, (float)($item['price'] ?? 0));
        $taxRate  = max(0, (float)($item['tax_rate'] ?? 0));

   $requestedLineDiscountPer = resolvePostedLineDiscountPercent(
    $lineDiscountPayload,
    (string)$cartKey,
    $item,
    $discountPer
);

$lineData = calculateCartLineDiscount($conn, $clientId, $item, $requestedLineDiscountPer);

        $lineSubtotal = (float)$lineData['line_subtotal'];
        $lineDiscount = (float)$lineData['line_discount'];
        $lineNet      = (float)$lineData['line_net'];
        $lineTax      = $lineNet * ($taxRate / 100);

        $subtotal += $lineSubtotal;
        $totalQty += $qty;
        $discountAmount += $lineDiscount;
        $itemWiseTaxTotal += $lineTax;

    $checkoutLineDiscounts[$cartKey] = [
    'line_subtotal' => $lineSubtotal,
    'line_discount' => $lineDiscount,
    'line_net' => $lineNet,
    'line_tax' => $lineTax,
    'requested_discount_percent' => $requestedLineDiscountPer,
    'applied_discount_percent' => (float)$lineData['applied_discount_percent'],
    'max_discount_percent' => $lineData['max_discount_percent'],
];
    }

    $subtotal = round($subtotal, 2);
    $discountAmount = round($discountAmount, 2);
    $taxableAmount  = round(max(0, $subtotal - $discountAmount), 2);

    // current frontend global tax bhi bhej raha hai, isliye fallback rakha hai
    $globalTaxAmount = $taxableAmount * ($taxPer / 100);

    // agar item-wise tax موجود ho to usko priority do
    $taxAmount = $itemWiseTaxTotal > 0
        ? $itemWiseTaxTotal
        : $globalTaxAmount;

    $taxAmount = round($taxAmount, 2);
    $grandTotal = round($taxableAmount + $taxAmount, 2);

    mysqli_begin_transaction($conn);

    try {
        /*
        |--------------------------------------------------------------------------
        | SALES TABLE INSERT (dynamic columns)
        |--------------------------------------------------------------------------
        */
        $saleColumns = [];
        $saleValues  = [];
        $saleTypes   = '';

        $possibleSaleData = [
            'client_id'        => [$clientId, 'i'],
               'business_session_id' => [$businessSessionId, 'i'],
            
            'customer_id'      => [$customerId, 'i'],
            'customer_name'    => [$postedCustomerName, 's'],
            'customer_phone'   => [$postedCustomerPhone, 's'],
            'customer_type'    => [$customerType, 's'],
            'type'             => [$customerType, 's'],
            'subtotal'         => [$subtotal, 'd'],
            'discount_percent' => [$discountPer, 'd'],
            'discount'         => [$discountPer, 'd'],
            'discount_amount'  => [$discountAmount, 'd'],
            'tax_percent'      => [$taxPer, 'd'],
            'tax'              => [$taxPer, 'd'],
            'tax_amount'       => [$taxAmount, 'd'],
            'grand_total'      => [$grandTotal, 'd'],
            'total_amount'     => [$grandTotal, 'd'],
            'total'            => [$grandTotal, 'd'],
            'payment_method'   => [$paymentMethod, 's'],
            'user_id'          => [$userId, 'i'],
            'created_by'       => [$userId, 'i'],
            'status'           => [$saleStatus, 's'],
        ];

        foreach ($possibleSaleData as $column => [$value, $type]) {
            if (hasColumn($conn, 'sales', $column)) {
                $saleColumns[] = "`{$column}`";
                $saleValues[]  = $value;
                $saleTypes    .= $type;
            }
        }

        if (hasColumn($conn, 'sales', 'created_at')) {
            $saleColumns[] = "`created_at`";
        }
        if (hasColumn($conn, 'sales', 'updated_at')) {
            $saleColumns[] = "`updated_at`";
        }

        if (empty($saleColumns)) {
            throw new Exception('No compatible columns found in sales table.');
        }

        $placeholders = [];
        foreach ($saleColumns as $col) {
            if ($col === '`created_at`' || $col === '`updated_at`') {
                $placeholders[] = 'NOW()';
            } else {
                $placeholders[] = '?';
            }
        }

        $salesSql = "INSERT INTO `sales` (" . implode(', ', $saleColumns) . ")
                     VALUES (" . implode(', ', $placeholders) . ")";

        $stmtSale = $conn->prepare($salesSql);
        if (!$stmtSale) {
            throw new Exception('Sales prepare failed: ' . $conn->error);
        }

        if (!empty($saleValues)) {
            $bindParams = [];
            $bindParams[] = $saleTypes;
            foreach ($saleValues as $k => $v) {
                $bindParams[] = &$saleValues[$k];
            }
            call_user_func_array([$stmtSale, 'bind_param'], $bindParams);
        }

        if (!$stmtSale->execute()) {
            throw new Exception('Sales insert failed: ' . $stmtSale->error);
        }

        $saleId = (int)$conn->insert_id;
        $stmtSale->close();

        if ($saleId <= 0) {
            throw new Exception('Sale ID not generated.');
        }

$invoiceNo = 'INV-' . date('y') . '-' . str_pad($saleId, 5, '0', STR_PAD_LEFT);

if (hasColumn($conn, 'sales', 'invoice_no')) {
    $stmtInv = $conn->prepare("UPDATE sales SET invoice_no = ? WHERE id = ? AND client_id = ?");
    if (!$stmtInv) {
        throw new Exception('Invoice update prepare failed: ' . $conn->error);
    }

    $stmtInv->bind_param("sii", $invoiceNo, $saleId, $clientId);

    if (!$stmtInv->execute()) {
        throw new Exception('Invoice update failed: ' . $stmtInv->error);
    }

    $stmtInv->close();
}

        /*
        |--------------------------------------------------------------------------
        | SALE ITEMS INSERT
        |--------------------------------------------------------------------------
        */
        foreach ($cart as $cartKey => $item) {
            $qty      = (float)($item['qty'] ?? 0);
            $price    = (float)($item['price'] ?? 0);
            $taxRate  = (float)($item['tax_rate'] ?? 0);

$lineDetails = $checkoutLineDiscounts[$cartKey] ?? calculateCartLineDiscount(
    $conn,
    $clientId,
    $item,
    resolvePostedLineDiscountPercent($lineDiscountPayload, (string)$cartKey, $item, $discountPer)
);
            $line        = (float)($lineDetails['line_subtotal'] ?? ($qty * $price));
            $lineDiscount = (float)($lineDetails['line_discount'] ?? 0);
            $lineNet      = (float)($lineDetails['line_net'] ?? max(0, $line - $lineDiscount));
            $lineTax      = (float)($lineDetails['line_tax'] ?? ($lineNet * ($taxRate / 100)));
            $appliedDiscountPercent = (float)($lineDetails['applied_discount_percent'] ?? $discountPer);
            $maxDiscountPercent = $lineDetails['max_discount_percent'] ?? null;
            $itemName = (string)($item['name'] ?? 'Item');
            $sku      = (string)($item['sku'] ?? '');
            $barcode  = (string)($item['barcode'] ?? '');
            $sourceId = (int)($item['source_id'] ?? 0);
            $itemType = (string)($item['item_type'] ?? 'product');
            $priceTierType  = (string)($item['price_tier_type'] ?? 'default');
            $priceTierNo    = (int)($item['price_tier_no'] ?? 0);
            $priceTierLabel = (string)($item['price_tier_label'] ?? '');
/*
|--------------------------------------------------------------------------
| Final product unit verification before sale_items insert
|--------------------------------------------------------------------------
| Session data ko directly trust nahi karna.
| Unit ID, product ID aur product-level toggle dobara verify hoga.
|--------------------------------------------------------------------------
*/
$sessionUnitId =
    (int)(
        $item['unit_id'] ?? 0
    );

$verifiedUnitData = null;

if (
    $sourceId > 0 &&
    $sessionUnitId > 0
) {
    $verifiedUnitData =
        resolvePharmacyProductUnit(
            $conn,
            $clientId,
            $sourceId,
            $sessionUnitId
        );
}

if ($verifiedUnitData) {
    /*
    |--------------------------------------------------------------------------
    | Actual selected product unit
    |--------------------------------------------------------------------------
    */
    $unitId =
        (int)$verifiedUnitData[
            'unit_id'
        ];

    $unitName =
        trim(
            (string)$verifiedUnitData[
                'unit_name'
            ]
        );

    $unitConversionQty =
        max(
            1,
            (float)$verifiedUnitData[
                'unit_conversion_qty'
            ]
        );

} else {
    /*
    |--------------------------------------------------------------------------
    | Cream, shampoo, oil or normal non-unit product
    |--------------------------------------------------------------------------
    */
    $unitId = 0;
    $unitName = '';
    $unitConversionQty = 1;
}

$baseQty = round(
    $qty * $unitConversionQty,
    2
);

            if ($priceTierType !== 'wholesale') {
                $priceTierType = 'default';
                $priceTierNo = 0;
                $priceTierLabel = '';
            }

            $itemColumns = [];
            $itemValues  = [];
            $itemTypes   = '';

            $possibleItemData = [
                'sale_id'       => [$saleId, 'i'],
                'sales_id'      => [$saleId, 'i'],
                'order_id'      => [$saleId, 'i'],
                'item_id'       => [$sourceId, 'i'],
                'product_id'    => [$sourceId, 'i'],
                'source_id'     => [$sourceId, 'i'],
                'item_name'     => [$itemName, 's'],
                'product_name'  => [$itemName, 's'],
                'name'          => [$itemName, 's'],
                'sku'           => [$sku, 's'],
                'barcode'       => [$barcode, 's'],
                'qty'           => [$qty, 'd'],
                'quantity'      => [$qty, 'd'],
                'unit_id'       => [$unitId > 0 ? $unitId : null, 'i'],
                'unit_name'     => [$unitName, 's'],
                'unit_conversion_qty' => [$unitConversionQty, 'd'],
                'base_qty'      => [$baseQty, 'd'],
                'price'         => [$price, 'd'],
                'discount_percent' => [$appliedDiscountPercent, 'd'],
                'discount'         => [$appliedDiscountPercent, 'd'],
                'discount_amount'  => [$lineDiscount, 'd'],
                'line_discount'    => [$lineDiscount, 'd'],
                'max_discount_percent' => [$maxDiscountPercent !== null ? (float)$maxDiscountPercent : 0, 'd'],
                'net_total'        => [$lineNet, 'd'],
                'line_subtotal'    => [$line, 'd'],
'line_total_after_discount' => [$lineNet, 'd'],
                'price_tier_type'  => [$priceTierType, 's'],
                'price_tier_no'    => [$priceTierNo, 'i'],
                'price_tier_label' => [$priceTierLabel, 's'],
                'unit_price'    => [$price, 'd'],
                'line_total'    => [$line, 'd'],
                'total'         => [$line, 'd'],
                'tax_rate'      => [$taxRate, 'd'],
                'tax_percent'   => [$taxRate, 'd'],
                'tax_amount'    => [$lineTax, 'd'],
                'item_type'     => [$itemType, 's'],
                'type'          => [$itemType, 's'],
            ];

            foreach ($possibleItemData as $column => [$value, $type]) {
                if (hasColumn($conn, 'sale_items', $column)) {
                    $itemColumns[] = "`{$column}`";
                    $itemValues[]  = $value;
                    $itemTypes    .= $type;
                }
            }

            if (hasColumn($conn, 'sale_items', 'created_at')) {
                $itemColumns[] = "`created_at`";
            }
            if (hasColumn($conn, 'sale_items', 'updated_at')) {
                $itemColumns[] = "`updated_at`";
            }

            if (empty($itemColumns)) {
                throw new Exception('No compatible columns found in sale_items table.');
            }

            $itemPlaceholders = [];
            foreach ($itemColumns as $col) {
                if ($col === '`created_at`' || $col === '`updated_at`') {
                    $itemPlaceholders[] = 'NOW()';
                } else {
                    $itemPlaceholders[] = '?';
                }
            }

            $itemSql = "INSERT INTO `sale_items` (" . implode(', ', $itemColumns) . ")
                        VALUES (" . implode(', ', $itemPlaceholders) . ")";

            $stmtItem = $conn->prepare($itemSql);
            if (!$stmtItem) {
                throw new Exception('Sale items prepare failed: ' . $conn->error);
            }

            if (!empty($itemValues)) {
                $bindItemParams = [];
                $bindItemParams[] = $itemTypes;
                foreach ($itemValues as $k => $v) {
                    $bindItemParams[] = &$itemValues[$k];
                }
                call_user_func_array([$stmtItem, 'bind_param'], $bindItemParams);
            }

            if (!$stmtItem->execute()) {
                throw new Exception('Sale item insert failed: ' . $stmtItem->error);
            }

            $stmtItem->close();
        }

        mysqli_commit($conn);

        $_SESSION['pos_cart'] = [];

        jsonResponse([
            'status'          => 'success',
            'message'         => $isHoldAction ? 'Sale saved on hold successfully' : 'Order created successfully',
            'is_hold'         => $isHoldAction ? 1 : 0,
            'sale_id'         => $saleId,
            'invoice_no'      => $invoiceNo ?? '',
            'cart_html'       => renderCart(),
            'subtotal'        => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'tax_amount'      => round($taxAmount, 2),
            'grand_total'     => round($grandTotal, 2),
            'total_qty'       => round($totalQty, 2)
        ]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        jsonResponse([
            'status'  => 'error',
            'message' => 'Checkout failed: ' . $e->getMessage()
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| INVALID ACTION
|--------------------------------------------------------------------------
*/
echo renderCart();
exit;