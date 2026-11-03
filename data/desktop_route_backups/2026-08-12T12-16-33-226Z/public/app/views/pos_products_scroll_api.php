<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'expired',
        'redirect' => '/onerp/app/onerp/app/public/app/views/login_view.php',
    ]);
    exit;
}

$clientId = (int)($_SESSION['client_id'] ?? 0);
$isPharmacyClient = (string)($_GET['pharmacy'] ?? '0') === '1';

// Release session lock immediately so cart/checkout requests are never blocked.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const POS_PRODUCTS_PAGE_SIZE = 30;

$placeholderImage = "data:image/svg+xml,%3Csvg%20xmlns%3D'http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg'%20viewBox%3D'0%200%20220%20220'%3E%3Crect%20width%3D'220'%20height%3D'220'%20fill%3D'%23111827'%2F%3E%3Cpath%20d%3D'M55%20155l38-42%2027%2028%2018-20%2028%2034H55z'%20fill%3D'%23334155'%2F%3E%3Ccircle%20cx%3D'145'%20cy%3D'73'%20r%3D'18'%20fill%3D'%23475569'%2F%3E%3Ctext%20x%3D'110'%20y%3D'195'%20text-anchor%3D'middle'%20font-family%3D'Arial'%20font-size%3D'16'%20fill%3D'%2394a3b8'%3ENo%20Image%3C%2Ftext%3E%3C%2Fsvg%3E";

function posEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function posItemType(array $row): string
{
    if (!empty($row['type'])) {
        return strtolower(trim((string)$row['type']));
    }

    if (!empty($row['item_type'])) {
        return strtolower(trim((string)$row['item_type']));
    }

    return 'product';
}

function posNormalize($value): string
{
    return strtolower(trim((string)$value));
}

function posTableExists(mysqli $conn, string $tableName): bool
{
    static $cache = [];

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    if ($safeTable === '') {
        return false;
    }

    if (array_key_exists($safeTable, $cache)) {
        return $cache[$safeTable];
    }

    try {
        $escaped = $conn->real_escape_string($safeTable);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;

        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } catch (Throwable $e) {
        $exists = false;
    }

    $cache[$safeTable] = $exists;
    return $exists;
}

function posTableColumns(mysqli $conn, string $tableName): array
{
    static $cache = [];

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    if ($safeTable === '') {
        return [];
    }

    if (isset($cache[$safeTable])) {
        return $cache[$safeTable];
    }

    $columns = [];

    try {
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
            $result->free();
        }
    } catch (Throwable $e) {
        $columns = [];
    }

    $cache[$safeTable] = $columns;
    return $columns;
}

function posResolveTaxRate(array $item, array $taxes): float
{
    $itemType = posItemType($item);

    foreach ($taxes as $tax) {
        if ((int)($tax['status'] ?? 0) !== 1) {
            continue;
        }

        $taxType = posNormalize($tax['type'] ?? '');
        $applicationOn = posNormalize($tax['application_on'] ?? '');

        $typeMatched = $taxType === $itemType || $taxType === 'all' || $taxType === '';
        $applicationMatched = $applicationOn === $itemType || $applicationOn === 'all' || $applicationOn === '';

        if ($itemType === 'product' && ($typeMatched || $applicationMatched)) {
            return (float)($tax['rate'] ?? 0);
        }
    }

    return 0.0;
}

function posLoadTaxes(mysqli $conn, int $clientId): array
{
    $taxes = [];

    try {
        $result = $conn->query(
            "SELECT id, name, rate, type, application_on, inclusive, status
             FROM taxes
             WHERE client_id = {$clientId} AND status = 1
             ORDER BY rate DESC, id DESC"
        );
    } catch (Throwable $e) {
        try {
            $result = $conn->query(
                "SELECT * FROM taxes
                 WHERE client_id = {$clientId} AND status = 1
                 ORDER BY rate DESC, id DESC"
            );
        } catch (Throwable $fallbackError) {
            $result = false;
        }
    }

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $taxes[] = $row;
        }
        $result->free();
    }

    return $taxes;
}

function posRenderCards(
    array $items,
    array $unitMap,
    bool $isPharmacyClient,
    string $placeholderImage
): string {
    ob_start();

    foreach ($items as $item) {
        $imageName = trim((string)($item['image'] ?? ''));

        if ($imageName !== '' && preg_match('/^https?:\/\//i', $imageName)) {
            $imageSource = $imageName;
        } elseif ($imageName !== '') {
            $imageSource = '/uploads/items/' . rawurlencode($imageName);
        } else {
            $imageSource = $placeholderImage;
        }

        $itemId = (int)($item['id'] ?? 0);
        $sku = $item['barcode'] ?? ($item['sku'] ?? ('ITEM-' . $itemId));
        $itemType = (string)($item['resolved_item_type'] ?? 'product');
        $taxRate = (float)($item['resolved_tax_rate'] ?? 0);
        $retailPrice = (float)($item['price'] ?? 0);

        $hasMaxDiscount = isset($item['has_max_discount'])
            ? (int)$item['has_max_discount']
            : (int)($item['has_discount'] ?? 0);

        $maxDiscountPercent = isset($item['max_discount_percent'])
            ? (float)$item['max_discount_percent']
            : (float)($item['discount_value'] ?? 0);

        $maxDiscountPercent = max(0.0, min(100.0, $maxDiscountPercent));

        if ($hasMaxDiscount !== 1 || $maxDiscountPercent <= 0) {
            $hasMaxDiscount = 0;
            $maxDiscountPercent = 0.0;
        }

        $maxDiscountLabel = rtrim(rtrim(number_format($maxDiscountPercent, 2), '0'), '.') . '%';
        $productUnits = [];
$defaultUnit = null;
$cardRetailPrice = $retailPrice;

/*
|--------------------------------------------------------------------------
| Per-product units toggle
|--------------------------------------------------------------------------
*/
$useProductUnits =
    (int)(
        $item[
            'allow_single_unit_sale'
        ] ?? 0
    ) === 1;

if (
    $isPharmacyClient &&
    $useProductUnits
) {
    $productUnits =
        $unitMap[$itemId] ?? [];

    foreach ($productUnits as $unitRow) {
        if (
            (int)(
                $unitRow[
                    'is_default'
                ] ?? 0
            ) === 1
        ) {
            $defaultUnit = $unitRow;
            break;
        }
    }

    if (
        !$defaultUnit &&
        !empty($productUnits)
    ) {
        $defaultUnit =
            $productUnits[0];
    }

    if (
        $defaultUnit &&
        isset(
            $defaultUnit[
                'sale_price'
            ]
        )
    ) {
        $cardRetailPrice =
            (float)$defaultUnit[
                'sale_price'
            ];
    }
}
        ?>
        <div
            class="item-card product-card"
            data-id="<?= $itemId ?>"
            data-name="<?= posEsc($item['name'] ?? '') ?>"
            data-search-text="<?= posEsc(trim(($item['name'] ?? '') . ' ' . $sku . ' ' . ($item['category_label'] ?? 'All'))) ?>"
            data-category="<?= posEsc($item['category_label'] ?? 'All') ?>"
            data-item-type="<?= posEsc($itemType) ?>"
            data-tax-rate="<?= posEsc($taxRate) ?>"
            data-base-price="<?= posEsc($cardRetailPrice) ?>"
            data-retail-price="<?= posEsc($cardRetailPrice) ?>"
            data-original-price="<?= posEsc($cardRetailPrice) ?>"
            data-has-discount="<?= posEsc($hasMaxDiscount) ?>"
            data-discount-type="percent"
            data-discount-value="<?= posEsc($maxDiscountPercent) ?>"
            data-has-max-discount="<?= posEsc($hasMaxDiscount) ?>"
            data-max-discount-percent="<?= posEsc($maxDiscountPercent) ?>"
            data-wholesale-price-1="<?= posEsc($item['wholesaler_price_1'] ?? 0) ?>"
            data-wholesale-price-2="<?= posEsc($item['wholesaler_price_2'] ?? 0) ?>"
            data-wholesale-price-3="<?= posEsc($item['wholesaler_price_3'] ?? 0) ?>"
        <?php if (
    $isPharmacyClient &&
    $useProductUnits &&
    !empty($productUnits)
): ?>
                data-has-unit-pricing="1"
                data-default-unit-id="<?= (int)($defaultUnit['id'] ?? 0) ?>"
                data-default-unit-name="<?= posEsc((string)($defaultUnit['unit_name'] ?? '')) ?>"
                data-default-unit-price="<?= posEsc((float)($defaultUnit['sale_price'] ?? $cardRetailPrice)) ?>"
                data-default-unit-conversion="<?= posEsc((float)($defaultUnit['conversion_qty'] ?? 1)) ?>"
            <?php endif; ?>
            onclick="addToCartFromCard(this, <?= $itemId ?>, <?= $taxRate ?>, '<?= posEsc($itemType) ?>')"
        >
            <div class="item-img">
                <img
                    src="<?= posEsc($imageSource) ?>"
                    alt="<?= posEsc($item['name'] ?? '') ?>"
                    loading="lazy"
                    decoding="async"
                    fetchpriority="low"
                    width="220"
                    height="220"
                    onerror="this.onerror=null;this.src='<?= posEsc($placeholderImage) ?>';"
                >
            </div>

            <div class="item-title"><?= posEsc($item['name'] ?? '') ?></div>
            <div class="item-sku-wrap">
                <button type="button" class="sku-mini-btn" onclick="event.stopPropagation(); toggleCardSku(this);">SKU</button>
                <div class="item-sku sku-hidden"><?= posEsc($sku) ?></div>
            </div>

            <?php if ($taxRate > 0): ?>
                <div class="item-tax-badge">Tax <?= number_format($taxRate, 2) ?>%</div>
            <?php endif; ?>

            <div class="item-bottom">
                <div class="item-price" data-card-price="1">
                    <small>Rs</small>
                    <span class="card-price-value"><?= number_format($cardRetailPrice, 0) ?></span>
                    <?php if ($hasMaxDiscount === 1 && $maxDiscountPercent > 0): ?>
                        <div class="item-discount-badge">Max <?= posEsc($maxDiscountLabel) ?></div>
                    <?php endif; ?>
                </div>
                <button type="button" class="add-btn">+</button>
            </div>
        </div>
        <?php
    }

    return (string)ob_get_clean();
}

try {
    $lastId = max(0, (int)($_GET['last_id'] ?? 0));
    $search = trim((string)($_GET['q'] ?? ''));
    $category = trim((string)($_GET['category'] ?? 'All'));
    $limit = POS_PRODUCTS_PAGE_SIZE + 1;

    $itemColumns = posTableColumns($conn, 'items');
$where = [
    'client_id = ' . $clientId,
    'is_raw_item = 0',
    "status = 'active'",
    'deleted_at IS NULL',
];
    if ($lastId > 0) {
        $where[] = 'id < ' . $lastId;
    }

    if ($search !== '') {
        $escapedSearch = $conn->real_escape_string($search);
        $searchColumns = [];

        foreach (['name', 'sku', 'barcode', 'category', 'category_name', 'item_category'] as $column) {
            if (isset($itemColumns[$column])) {
                $searchColumns[] = "`{$column}` LIKE '%{$escapedSearch}%'";
            }
        }

        if (!empty($searchColumns)) {
            $where[] = '(' . implode(' OR ', $searchColumns) . ')';
        }
    }

    if ($category !== '' && strcasecmp($category, 'All') !== 0) {
        $escapedCategory = $conn->real_escape_string($category);
        $categoryColumns = [];

        foreach (['category', 'category_name', 'item_category'] as $column) {
            if (isset($itemColumns[$column])) {
                $categoryColumns[] = "`{$column}` = '{$escapedCategory}'";
            }
        }

        if (!empty($categoryColumns)) {
            $where[] = '(' . implode(' OR ', $categoryColumns) . ')';
        }
    }

    $sql = "SELECT *
            FROM items
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
            LIMIT {$limit}";

    $result = $conn->query($sql);
    $rawItems = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rawItems[] = $row;
        }
        $result->free();
    }

    $hasMore = count($rawItems) > POS_PRODUCTS_PAGE_SIZE;
    if ($hasMore) {
        $rawItems = array_slice($rawItems, 0, POS_PRODUCTS_PAGE_SIZE);
    }

    $taxes = posLoadTaxes($conn, $clientId);
    $items = [];
    $taxMap = [];

    foreach ($rawItems as $row) {
        $categoryLabel = 'All';

        if (!empty($row['category'])) {
            $categoryLabel = trim((string)$row['category']);
        } elseif (!empty($row['category_name'])) {
            $categoryLabel = trim((string)$row['category_name']);
        } elseif (!empty($row['item_category'])) {
            $categoryLabel = trim((string)$row['item_category']);
        }

        $row['category_label'] = $categoryLabel;
        $row['resolved_item_type'] = posItemType($row);
        $row['resolved_tax_rate'] = posResolveTaxRate($row, $taxes);

        $items[] = $row;
        $taxMap[(int)$row['id']] = [
            'tax_rate' => (float)$row['resolved_tax_rate'],
            'item_type' => (string)$row['resolved_item_type'],
            'name' => (string)($row['name'] ?? ''),
        ];
    }

$unitMap = [];

if (
    $isPharmacyClient &&
    !empty($items) &&
    posTableExists($conn, 'product_units')
) {
    /*
    |--------------------------------------------------------------------------
    | Sirf units-enabled products
    |--------------------------------------------------------------------------
    | Items page par "Use Product Units" ON ho to hi product_units load hongi.
    |--------------------------------------------------------------------------
    */
    $ids = array_values(
        array_filter(
            array_map(
                static function (array $row): int {

                    $useProductUnits =
                        (int)(
                            $row[
                                'allow_single_unit_sale'
                            ] ?? 0
                        ) === 1;

                    if (!$useProductUnits) {
                        return 0;
                    }

                    return (int)(
                        $row['id'] ?? 0
                    );
                },
                $items
            )
        )
    );

    if (!empty($ids)) {
            $idList = implode(',', $ids);

            try {
                $unitResult = $conn->query(
                    "SELECT id, product_id, unit_name, conversion_qty, sale_price,
                            barcode, is_base_unit, is_default
                     FROM product_units
                     WHERE product_id IN ({$idList}) AND status = 1
                     ORDER BY product_id ASC, is_default DESC, is_base_unit DESC,
                              conversion_qty ASC, id ASC"
                );
            } catch (Throwable $e) {
                $unitResult = false;
            }

            if ($unitResult instanceof mysqli_result) {
                while ($unit = $unitResult->fetch_assoc()) {
                    $productId = (int)$unit['product_id'];
                    $unitMap[$productId][] = [
                        'id' => (int)$unit['id'],
                        'unit_name' => (string)$unit['unit_name'],
                        'conversion_qty' => (string)$unit['conversion_qty'],
                        'sale_price' => (string)$unit['sale_price'],
                        'barcode' => (string)($unit['barcode'] ?? ''),
                        'is_base_unit' => (int)$unit['is_base_unit'],
                        'is_default' => (int)$unit['is_default'],
                    ];
                }
                $unitResult->free();
            }
        }
    }

    $lastLoadedId = !empty($items)
        ? (int)($items[array_key_last($items)]['id'] ?? 0)
        : 0;

    echo json_encode([
        'ok' => true,
        'html' => posRenderCards($items, $unitMap, $isPharmacyClient, $placeholderImage),
        'hasMore' => $hasMore,
        'lastId' => $lastLoadedId,
        'loadedCount' => count($items),
        'productTaxMap' => $taxMap,
        'productUnitMap' => $unitMap,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);

    error_log('POS products scroll API error: ' . $e->getMessage());

    echo json_encode([
        'ok' => false,
        'message' => 'Products could not be loaded.',
    ]);
}
