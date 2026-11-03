<?php

/*
|--------------------------------------------------------------------------
| ONEERP POS Products AJAX
|--------------------------------------------------------------------------
| Server-side product loading for POS:
| - 10 products per request by default
| - Search by name / SKU / barcode / category
| - Category filtering
| - Existing POS card data attributes retained
| - Pharmacy product units retained
| - Wholesale prices retained
| - Cart / checkout backend is not changed
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function posProductsJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if (empty($_SESSION['user_id'])) {
    posProductsJson([
        'status'   => 'expired',
        'message'  => 'Session expired',
        'redirect' => '/onerp/app/public/app/views/login_view.php',
    ], 401);
}

$clientId = (int)($_SESSION['client_id'] ?? 0);

if ($clientId <= 0) {
    posProductsJson([
        'status'  => 'error',
        'message' => 'Invalid client session.',
    ], 400);
}

$sessionIndustry = (string)($_SESSION['client_industry'] ?? '');
$sessionIsPharmacy = (int)($_SESSION['is_pharmacy_client'] ?? 0);

function posProductsEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function posProductsNormalize($value): string
{
    return strtolower(trim((string)$value));
}

function posProductsLooksLikePharmacy($value): bool
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

function posProductsGetTableSchema(mysqli $conn, string $table): array
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

    if (function_exists('oneerpCacheRemember')) {
        $schema = oneerpCacheRemember(
            'pos_products_schema_' . $safeTable,
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

function posProductsHasColumn(array $schema, string $column): bool
{
    return array_key_exists($column, $schema);
}

function posProductsTableExists(mysqli $conn, string $table): bool
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
            'pos_products_table_' . $safeTable,
            3600,
            $resolver
        );
    } else {
        $exists = $resolver();
    }

    $requestCache[$safeTable] = $exists;

    return $exists;
}

function posProductsBind(mysqli_stmt $stmt, string $types, array &$values): bool
{
    if ($types === '' || empty($values)) {
        return true;
    }

    $params = [$types];

    foreach ($values as $index => &$value) {
        $params[] = &$value;
    }
    unset($value);

    return (bool)call_user_func_array([$stmt, 'bind_param'], $params);
}

function posProductsGetItemType(array $row): string
{
    if (!empty($row['type'])) {
        return strtolower(trim((string)$row['type']));
    }

    if (!empty($row['item_type'])) {
        return strtolower(trim((string)$row['item_type']));
    }

    return 'product';
}

function posProductsResolveTaxRate(array $item, array $taxes): float
{
    $itemType = posProductsGetItemType($item);

    foreach ($taxes as $tax) {
        $taxType = posProductsNormalize($tax['type'] ?? '');
        $applicationOn = posProductsNormalize($tax['application_on'] ?? '');
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

function posProductsCategoryExpression(array $itemSchema, string $alias = 'i'): string
{
    $parts = [];

    foreach (['category', 'category_name', 'item_category'] as $column) {
        if (posProductsHasColumn($itemSchema, $column)) {
            $parts[] = "NULLIF(TRIM({$alias}.`{$column}`), '')";
        }
    }

    if (empty($parts)) {
        return "'All'";
    }

    $parts[] = "'All'";

    return 'COALESCE(' . implode(', ', $parts) . ')';
}

function posProductsIsPharmacyClient(
    mysqli $conn,
    int $clientId,
    string $sessionIndustry,
    int $sessionIsPharmacy
): bool {
    if ($sessionIsPharmacy === 1 || posProductsLooksLikePharmacy($sessionIndustry)) {
        return true;
    }

    if (
        !posProductsTableExists($conn, 'subscriptions') ||
        !posProductsTableExists($conn, 'plans')
    ) {
        return false;
    }

    $subscriptionSchema = posProductsGetTableSchema($conn, 'subscriptions');
    $planSchema = posProductsGetTableSchema($conn, 'plans');

    if (
        !posProductsHasColumn($subscriptionSchema, 'client_id') ||
        !posProductsHasColumn($subscriptionSchema, 'plan_id') ||
        !posProductsHasColumn($planSchema, 'id')
    ) {
        return false;
    }

    $resolver = static function () use (
        $conn,
        $clientId,
        $subscriptionSchema,
        $planSchema
    ): bool {
        $select = [];
        $select[] = posProductsHasColumn($planSchema, 'name')
            ? 'p.name AS plan_name'
            : "'' AS plan_name";
        $select[] = posProductsHasColumn($planSchema, 'industry')
            ? 'p.industry AS plan_industry'
            : "'' AS plan_industry";

        $where = ['s.client_id = ?'];

        if (posProductsHasColumn($subscriptionSchema, 'status')) {
            $where[] = "LOWER(TRIM(s.status)) IN ('active', 'enabled', '1')";
        }

        if (posProductsHasColumn($subscriptionSchema, 'end_date')) {
            $where[] = '(s.end_date IS NULL OR s.end_date >= CURDATE())';
        }

        if (posProductsHasColumn($planSchema, 'status')) {
            $where[] = "LOWER(TRIM(p.status)) IN ('active', 'enabled', '1')";
        }

        $orderColumn = posProductsHasColumn($subscriptionSchema, 'id')
            ? 's.id'
            : 's.plan_id';

        $sql = "
            SELECT " . implode(', ', $select) . "
            FROM subscriptions s
            INNER JOIN plans p ON p.id = s.plan_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$orderColumn} DESC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $planText = trim(
            (string)($row['plan_name'] ?? '') . ' ' .
            (string)($row['plan_industry'] ?? '')
        );

        return posProductsLooksLikePharmacy($planText);
    };

    if (function_exists('oneerpCacheRemember')) {
        return (bool)oneerpCacheRemember(
            'pos_products_pharmacy_client_' . $clientId,
            300,
            $resolver
        );
    }

    return $resolver();
}

function posProductsLoadTaxes(mysqli $conn, int $clientId): array
{
    $resolver = static function () use ($conn, $clientId): array {
        if (!posProductsTableExists($conn, 'taxes')) {
            return [];
        }

        $taxSchema = posProductsGetTableSchema($conn, 'taxes');

        if (!posProductsHasColumn($taxSchema, 'client_id')) {
            return [];
        }

        $where = ['client_id = ?'];

        if (posProductsHasColumn($taxSchema, 'status')) {
            $where[] = 'status = 1';
        }

        $order = posProductsHasColumn($taxSchema, 'rate')
            ? 'rate DESC'
            : (posProductsHasColumn($taxSchema, 'id') ? 'id DESC' : 'client_id ASC');

        $stmt = $conn->prepare(
            'SELECT * FROM taxes WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $rows;
    };

    if (function_exists('oneerpCacheRemember')) {
        $rows = oneerpCacheRemember(
            'pos_products_taxes_' . $clientId,
            300,
            $resolver
        );

        return is_array($rows) ? $rows : [];
    }

    return $resolver();
}

function posProductsLoadCategories(
    mysqli $conn,
    int $clientId,
    array $itemSchema,
    string $categoryExpression
): array {
    $resolver = static function () use (
        $conn,
        $clientId,
        $itemSchema,
        $categoryExpression
    ): array {
        $where = ['i.client_id = ?'];

        if (posProductsHasColumn($itemSchema, 'is_raw_item')) {
            $where[] = 'COALESCE(i.is_raw_item, 0) = 0';
        }

        $sql = "
            SELECT DISTINCT {$categoryExpression} AS category_label
            FROM items i
            WHERE " . implode(' AND ', $where) . "
            ORDER BY category_label ASC
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return ['All'];
        }

        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = ['All'];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $category = trim((string)($row['category_label'] ?? ''));

                if ($category !== '' && strcasecmp($category, 'All') !== 0) {
                    $categories[] = $category;
                }
            }
        }

        $stmt->close();

        return array_values(array_unique($categories));
    };

    if (function_exists('oneerpCacheRemember')) {
        $categories = oneerpCacheRemember(
            'pos_products_categories_' . $clientId,
            300,
            $resolver
        );

        return is_array($categories) && !empty($categories)
            ? $categories
            : ['All'];
    }

    return $resolver();
}

$input = array_merge($_GET, $_POST);

$offset = max(0, (int)($input['offset'] ?? 0));
$limit = (int)($input['limit'] ?? 10);
$limit = max(1, min(50, $limit));

$search = trim((string)($input['search'] ?? ''));
$category = trim((string)($input['category'] ?? 'All'));

if (function_exists('mb_substr')) {
    $search = mb_substr($search, 0, 150, 'UTF-8');
    $category = mb_substr($category, 0, 150, 'UTF-8');
} else {
    $search = substr($search, 0, 150);
    $category = substr($category, 0, 150);
}

if ($category === '') {
    $category = 'All';
}

$itemSchema = posProductsGetTableSchema($conn, 'items');

if (empty($itemSchema) || !posProductsHasColumn($itemSchema, 'client_id')) {
    posProductsJson([
        'status'  => 'error',
        'message' => 'Items table is not available.',
    ], 500);
}

$categoryExpression = posProductsCategoryExpression($itemSchema, 'i');
$isPharmacyClient = posProductsIsPharmacyClient(
    $conn,
    $clientId,
    $sessionIndustry,
    $sessionIsPharmacy
);
$taxes = posProductsLoadTaxes($conn, $clientId);
$categories = posProductsLoadCategories(
    $conn,
    $clientId,
    $itemSchema,
    $categoryExpression
);
$productUnitsTableExists = $isPharmacyClient && posProductsTableExists($conn, 'product_units');

/*
|--------------------------------------------------------------------------
| Session values have been read. Release session lock before heavier queries.
| This allows cart AJAX requests to run in parallel.
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$where = ['i.client_id = ?'];
$bindTypes = 'i';
$bindValues = [$clientId];

if (posProductsHasColumn($itemSchema, 'is_raw_item')) {
    $where[] = 'COALESCE(i.is_raw_item, 0) = 0';
}

if ($search !== '') {
    $searchParts = [];
    $searchableColumns = [];

    foreach (['name', 'sku', 'barcode', 'category', 'category_name', 'item_category'] as $column) {
        if (posProductsHasColumn($itemSchema, $column)) {
            $searchableColumns[] = $column;
        }
    }

    $likeValue = '%' . $search . '%';

    foreach ($searchableColumns as $column) {
        $searchParts[] = "i.`{$column}` LIKE ?";
        $bindTypes .= 's';
        $bindValues[] = $likeValue;
    }

    if (!empty($searchParts)) {
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }
}

if (strcasecmp($category, 'All') !== 0) {
    $where[] = "{$categoryExpression} = ?";
    $bindTypes .= 's';
    $bindValues[] = $category;
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) AS total FROM items i WHERE {$whereSql}";
$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    error_log('POS products count prepare failed: ' . $conn->error);

    posProductsJson([
        'status'  => 'error',
        'message' => 'Unable to load product count.',
    ], 500);
}

$countBindValues = $bindValues;

if (!posProductsBind($countStmt, $bindTypes, $countBindValues)) {
    $countStmt->close();

    posProductsJson([
        'status'  => 'error',
        'message' => 'Unable to prepare product filters.',
    ], 500);
}

$countStmt->execute();
$countResult = $countStmt->get_result();
$total = (int)(($countResult ? $countResult->fetch_assoc()['total'] ?? 0 : 0));
$countStmt->close();

$productSql = "
    SELECT
        i.*,
        {$categoryExpression} AS category_label
    FROM items i
    WHERE {$whereSql}
    ORDER BY i.id DESC
    LIMIT ? OFFSET ?
";

$productStmt = $conn->prepare($productSql);

if (!$productStmt) {
    error_log('POS products prepare failed: ' . $conn->error);

    posProductsJson([
        'status'  => 'error',
        'message' => 'Unable to load products.',
    ], 500);
}

$productBindTypes = $bindTypes . 'ii';
$productBindValues = $bindValues;
$productBindValues[] = $limit;
$productBindValues[] = $offset;

if (!posProductsBind($productStmt, $productBindTypes, $productBindValues)) {
    $productStmt->close();

    posProductsJson([
        'status'  => 'error',
        'message' => 'Unable to prepare product request.',
    ], 500);
}

$productStmt->execute();
$productResult = $productStmt->get_result();
$items = $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : [];
$productStmt->close();

$itemProductUnits = [];

if ($productUnitsTableExists && !empty($items)) {
    $itemIds = array_values(array_filter(array_map(
        static fn(array $item): int => (int)($item['id'] ?? 0),
        $items
    )));

    if (!empty($itemIds)) {
        $idList = implode(',', $itemIds);
        $unitSchema = posProductsGetTableSchema($conn, 'product_units');

        $requiredUnitColumns = [
            'id',
            'product_id',
            'unit_name',
            'conversion_qty',
            'sale_price',
            'barcode',
            'is_base_unit',
            'is_default',
        ];

        $selectUnitColumns = [];

        foreach ($requiredUnitColumns as $column) {
            if (posProductsHasColumn($unitSchema, $column)) {
                $selectUnitColumns[] = "`{$column}`";
            }
        }

        if (
            posProductsHasColumn($unitSchema, 'product_id') &&
            !empty($selectUnitColumns)
        ) {
            $unitWhere = ["product_id IN ({$idList})"];

            if (posProductsHasColumn($unitSchema, 'status')) {
                $unitWhere[] = 'status = 1';
            }

            $unitOrder = ['product_id ASC'];

            foreach (['is_default DESC', 'is_base_unit DESC', 'conversion_qty ASC', 'id ASC'] as $orderPart) {
                $column = strtok($orderPart, ' ');

                if ($column && posProductsHasColumn($unitSchema, $column)) {
                    $unitOrder[] = $orderPart;
                }
            }

            $unitSql = "
                SELECT " . implode(', ', $selectUnitColumns) . "
                FROM product_units
                WHERE " . implode(' AND ', $unitWhere) . "
                ORDER BY " . implode(', ', $unitOrder) . "
            ";

            $unitResult = $conn->query($unitSql);

            if ($unitResult) {
                while ($unit = $unitResult->fetch_assoc()) {
                    $productId = (int)($unit['product_id'] ?? 0);

                    if ($productId <= 0) {
                        continue;
                    }

                    $itemProductUnits[$productId][] = [
                        'id'             => (int)($unit['id'] ?? 0),
                        'unit_name'      => (string)($unit['unit_name'] ?? ''),
                        'conversion_qty' => (string)($unit['conversion_qty'] ?? '1'),
                        'sale_price'     => (string)($unit['sale_price'] ?? '0'),
                        'barcode'        => (string)($unit['barcode'] ?? ''),
                        'is_base_unit'   => (int)($unit['is_base_unit'] ?? 0),
                        'is_default'     => (int)($unit['is_default'] ?? 0),
                    ];
                }

                $unitResult->free();
            }
        }
    }
}

$placeholderImage = "data:image/svg+xml,%3Csvg%20xmlns%3D'http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg'%20viewBox%3D'0%200%20220%20220'%3E%3Crect%20width%3D'220'%20height%3D'220'%20fill%3D'%23111827'%2F%3E%3Cpath%20d%3D'M55%20155l38-42%2027%2028%2018-20%2028%2034H55z'%20fill%3D'%23334155'%2F%3E%3Ccircle%20cx%3D'145'%20cy%3D'73'%20r%3D'18'%20fill%3D'%23475569'%2F%3E%3Ctext%20x%3D'110'%20y%3D'195'%20text-anchor%3D'middle'%20font-family%3D'Arial'%20font-size%3D'16'%20fill%3D'%2394a3b8'%3ENo%20Image%3C%2Ftext%3E%3C%2Fsvg%3E";

$productTaxMap = [];
$productUnitMap = [];

ob_start();

foreach ($items as $item) {
    $itemId = (int)($item['id'] ?? 0);

    if ($itemId <= 0) {
        continue;
    }

    $name = (string)($item['name'] ?? 'Item');
    $categoryLabel = trim((string)($item['category_label'] ?? 'All'));

    if ($categoryLabel === '') {
        $categoryLabel = 'All';
    }

    $imageName = trim((string)($item['image'] ?? ''));

    if ($imageName !== '' && preg_match('/^https?:\/\//i', $imageName)) {
        $imageSource = $imageName;
    } elseif ($imageName !== '') {
        $imageSource = '/uploads/items/' . rawurlencode($imageName);
    } else {
        $imageSource = $placeholderImage;
    }

    $sku = (string)($item['barcode'] ?? ($item['sku'] ?? ('ITEM-' . $itemId)));
    $itemType = posProductsGetItemType($item);
    $taxRate = posProductsResolveTaxRate($item, $taxes);
    $originalPrice = (float)($item['price'] ?? 0);
    $retailPrice = $originalPrice;

    $hasMaxDiscount = array_key_exists('has_max_discount', $item)
        ? (int)$item['has_max_discount']
        : (int)($item['has_discount'] ?? 0);

    $maxDiscountPercent = array_key_exists('max_discount_percent', $item)
        ? (float)$item['max_discount_percent']
        : (float)($item['discount_value'] ?? 0);

    $maxDiscountPercent = min(100, max(0, $maxDiscountPercent));

    if ($hasMaxDiscount !== 1 || $maxDiscountPercent <= 0) {
        $hasMaxDiscount = 0;
        $maxDiscountPercent = 0;
    }

    $maxDiscountLabel = rtrim(
        rtrim(number_format($maxDiscountPercent, 2, '.', ''), '0'),
        '.'
    ) . '%';

    $productUnits = $isPharmacyClient
        ? ($itemProductUnits[$itemId] ?? [])
        : [];

    $defaultUnit = null;
    $cardRetailPrice = $retailPrice;

    foreach ($productUnits as $unitRow) {
        if ((int)($unitRow['is_default'] ?? 0) === 1) {
            $defaultUnit = $unitRow;
            break;
        }
    }

    if ($defaultUnit === null && !empty($productUnits)) {
        $defaultUnit = $productUnits[0];
    }

    if ($defaultUnit !== null && array_key_exists('sale_price', $defaultUnit)) {
        $unitSalePrice = (float)$defaultUnit['sale_price'];

        if ($unitSalePrice > 0) {
            $cardRetailPrice = $unitSalePrice;
        }
    }

    $productTaxMap[$itemId] = [
        'tax_rate' => $taxRate,
        'item_type' => $itemType,
        'name' => $name,
    ];

    if (!empty($productUnits)) {
        $productUnitMap[$itemId] = $productUnits;
    }

    $searchText = trim($name . ' ' . $sku . ' ' . $categoryLabel);
    $onErrorSource = posProductsEsc(json_encode($placeholderImage, JSON_UNESCAPED_SLASHES));
    ?>
    <div
        class="item-card product-card"
        data-id="<?= $itemId ?>"
        data-name="<?= posProductsEsc($name) ?>"
        data-search-text="<?= posProductsEsc($searchText) ?>"
        data-category="<?= posProductsEsc($categoryLabel) ?>"
        data-item-type="<?= posProductsEsc($itemType) ?>"
        data-tax-rate="<?= posProductsEsc($taxRate) ?>"
        data-base-price="<?= posProductsEsc($cardRetailPrice) ?>"
        data-retail-price="<?= posProductsEsc($cardRetailPrice) ?>"
        data-original-price="<?= posProductsEsc($cardRetailPrice) ?>"
        data-has-discount="<?= posProductsEsc($hasMaxDiscount) ?>"
        data-discount-type="percent"
        data-discount-value="<?= posProductsEsc($maxDiscountPercent) ?>"
        data-has-max-discount="<?= posProductsEsc($hasMaxDiscount) ?>"
        data-max-discount-percent="<?= posProductsEsc($maxDiscountPercent) ?>"
        data-wholesale-price-1="<?= posProductsEsc($item['wholesaler_price_1'] ?? 0) ?>"
        data-wholesale-price-2="<?= posProductsEsc($item['wholesaler_price_2'] ?? 0) ?>"
        data-wholesale-price-3="<?= posProductsEsc($item['wholesaler_price_3'] ?? 0) ?>"
        <?php if ($isPharmacyClient && !empty($productUnits)): ?>
            data-has-unit-pricing="1"
            data-default-unit-id="<?= (int)($defaultUnit['id'] ?? 0) ?>"
            data-default-unit-name="<?= posProductsEsc($defaultUnit['unit_name'] ?? '') ?>"
            data-default-unit-price="<?= posProductsEsc($defaultUnit['sale_price'] ?? $cardRetailPrice) ?>"
            data-default-unit-conversion="<?= posProductsEsc($defaultUnit['conversion_qty'] ?? 1) ?>"
            data-product-units='<?= posProductsEsc(json_encode($productUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
        <?php endif; ?>
        onclick="addToCartFromCard(this, <?= $itemId ?>, <?= (float)$taxRate ?>, '<?= posProductsEsc($itemType) ?>')"
    >
        <div class="item-img">
            <img
                src="<?= posProductsEsc($imageSource) ?>"
                alt="<?= posProductsEsc($name) ?>"
                width="220"
                height="220"
                loading="lazy"
                decoding="async"
                onerror="this.onerror=null;this.src=<?= $onErrorSource ?>;"
            >
        </div>

        <div class="item-title"><?= posProductsEsc($name) ?></div>

        <div class="item-sku-wrap">
            <button
                type="button"
                class="sku-mini-btn"
                onclick="event.stopPropagation(); toggleCardSku(this);"
            >
                SKU
            </button>

            <div class="item-sku sku-hidden"><?= posProductsEsc($sku) ?></div>
        </div>

        <?php if ($taxRate > 0): ?>
            <div class="item-tax-badge">Tax <?= number_format($taxRate, 2) ?>%</div>
        <?php endif; ?>

        <div class="item-bottom">
            <div class="item-price" data-card-price="1">
                <small>Rs</small>
                <span class="card-price-value"><?= number_format($cardRetailPrice, 0) ?></span>

                <?php if ($hasMaxDiscount === 1 && $maxDiscountPercent > 0): ?>
                    <div class="item-discount-badge">
                        Max <?= posProductsEsc($maxDiscountLabel) ?>
                    </div>
                <?php endif; ?>
            </div>

            <button type="button" class="add-btn">+</button>
        </div>
    </div>
    <?php
}

$html = trim((string)ob_get_clean());
$loadedCount = count($items);
$nextOffset = $offset + $loadedCount;
$hasMore = $nextOffset < $total;

posProductsJson([
    'status'            => 'success',
    'html'              => $html,
    'count'             => $loadedCount,
    'total'             => $total,
    'offset'            => $offset,
    'next_offset'       => $nextOffset,
    'limit'             => $limit,
    'has_more'          => $hasMore,
    'remaining'         => max(0, $total - $nextOffset),
    'search'            => $search,
    'category'          => $category,
    'categories'        => $categories,
    'is_pharmacy'       => $isPharmacyClient,
    'product_tax_map'   => $productTaxMap,
    'product_unit_map'  => $productUnitMap,
]);
