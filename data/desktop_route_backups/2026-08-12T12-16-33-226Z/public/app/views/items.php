<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/*
|--------------------------------------------------------------------------
| Integration Helper
|--------------------------------------------------------------------------
| Correct live path:
| public_html/app/helpers/includes/integration_helper.php
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
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

if ($client_id <= 0) {
    die("Invalid client session");
}

/*
|--------------------------------------------------------------------------
| Business Feature Toggle
|--------------------------------------------------------------------------
| Wholesale Pricing integration page se Active/Inactive hogi.
| Active ho to Items page par wholesale option/columns show honge.
|--------------------------------------------------------------------------
*/
$wholesaleEnabled = false;
if (function_exists('isIntegrationEnabled')) {
    $wholesaleEnabled = isIntegrationEnabled($conn, $client_id, 'Wholesale Pricing');
}


/*
|--------------------------------------------------------------------------
| Detect Restaurant Client
|--------------------------------------------------------------------------
| Restaurant clients ko active subscription plan industry se detect karna hai.
| Fallback me clients.industry_id bhi check hoga.
|--------------------------------------------------------------------------
*/
$isRestaurantClient = false;

$normalizeIndustry = function ($value) {
    $value = strtolower(trim((string)$value));
    $value = str_replace(['-', ' '], '_', $value);

    if (in_array($value, ['resturent', 'resturant', 'restaurants'], true)) {
        $value = 'restaurant';
    }
    if (in_array($value, ['pharma', 'pharmaceutical', 'medical', 'medicine', 'medical_store', 'medicine_store', 'chemist'], true)) {
    $value = 'pharmacy';
}

    return $value;
};

try {
    /*
    |--------------------------------------------------------------------------
    | First priority: Active subscription plan industry
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT p.industry
        FROM subscriptions s
        INNER JOIN plans p ON p.id = s.plan_id
        WHERE s.client_id = ?
          AND s.status = 'active'
          AND (s.end_date IS NULL OR s.end_date >= CURDATE())
        ORDER BY s.id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $planIndustryRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $planIndustry = $normalizeIndustry($planIndustryRow['industry'] ?? '');

        if ($planIndustry === 'restaurant') {
            $isRestaurantClient = true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback: Client industry table
    |--------------------------------------------------------------------------
    */
    if (!$isRestaurantClient) {
        $stmt = $conn->prepare("
            SELECT 
                c.industry_id,
                i.name AS industry_name
            FROM clients c
            LEFT JOIN industries i ON i.id = c.industry_id
            WHERE c.id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $industryRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $industryId = (int)($industryRow['industry_id'] ?? 0);
            $industryName = $normalizeIndustry($industryRow['industry_name'] ?? '');

            $isRestaurantClient =
                $industryId === 13 ||
                $industryName === 'restaurant';
        }
    }

} catch (Throwable $e) {
    $isRestaurantClient = false;
}

/*
|--------------------------------------------------------------------------
| Detect Pharmacy Plan Client
|--------------------------------------------------------------------------
| Client industry Retailer ho sakti hai, lekin agar uska active plan
| pharmacy / medical / medicine hai to pharmacy unit feature show hoga.
|--------------------------------------------------------------------------
*/
$isPharmacyRetailClient = false;

try {
    $stmt = $conn->prepare("
        SELECT 
            p.name,
            p.industry
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

        $planName = strtolower(trim((string)($planRow['name'] ?? '')));
        $planIndustry = strtolower(trim((string)($planRow['industry'] ?? '')));

        $planText = $planName . ' ' . $planIndustry;

        if (
            strpos($planText, 'pharmacy') !== false ||
            strpos($planText, 'pharma') !== false ||
            strpos($planText, 'medical') !== false ||
            strpos($planText, 'medicine') !== false ||
            strpos($planText, 'chemist') !== false
        ) {
            $isPharmacyRetailClient = true;
        }
    }
} catch (Throwable $e) {
    $isPharmacyRetailClient = false;
}

/*
|--------------------------------------------------------------------------
| Table column count
|--------------------------------------------------------------------------
| Base columns with Max Discount = 11
| Wholesale enabled ho to +3 wholesale columns = 14
|--------------------------------------------------------------------------
*/
$baseTableColspan = $isRestaurantClient ? 10 : 12;

if ($isPharmacyRetailClient) {
    $baseTableColspan += 1; // Units column
}

/*
|--------------------------------------------------------------------------
| Product Active / Inactive Status column
|--------------------------------------------------------------------------
*/
$baseTableColspan += 1;

$tableColspan = $wholesaleEnabled
    ? ($baseTableColspan + 3)
    : $baseTableColspan;

/*
|--------------------------------------------------------------------------
| Fetch stats
|--------------------------------------------------------------------------
*/
$total_products = 0;
$low_stock = 0;
$out_of_stock = 0;
$tracked_inventory = 0;

$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_products,
        SUM(CASE WHEN COALESCE(stock_qty, 0) > 0 AND COALESCE(stock_qty, 0) <= 5 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN COALESCE(stock_qty, 0) <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN COALESCE(track_inventory, 1) = 1 THEN 1 ELSE 0 END) as tracked_inventory
FROM items
WHERE client_id = ?
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stats_result = $stmt->get_result();
if ($stats_row = $stats_result->fetch_assoc()) {
    $total_products = (int)$stats_row['total_products'];
    $low_stock = (int)$stats_row['low_stock'];
    $out_of_stock = (int)$stats_row['out_of_stock'];
    $tracked_inventory = (int)$stats_row['tracked_inventory'];
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| Fetch suppliers
|--------------------------------------------------------------------------
*/
$suppliers = [];
$stmt = $conn->prepare("
    SELECT id, name, company_name
    FROM suppliers
    WHERE client_id = ?
    ORDER BY COALESCE(NULLIF(company_name, ''), name) ASC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$supplierResult = $stmt->get_result();
while ($row = $supplierResult->fetch_assoc()) {
    $suppliers[] = $row;
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| Fetch categories
|--------------------------------------------------------------------------
*/
$categoriesList = [];

$stmt = $conn->prepare("
    SELECT id, name
    FROM categories
    WHERE client_id = ?
      AND deleted_at IS NULL
    ORDER BY name ASC
");

if ($stmt) {
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $catResult = $stmt->get_result();

    while ($cat = $catResult->fetch_assoc()) {
        $categoriesList[] = $cat;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Server-side items pagination and filters
|--------------------------------------------------------------------------
*/

$allowedPageLimits = [50, 100, 150, 200, 250, 300];

$page = isset($_GET['page'])
    ? max(1, (int)$_GET['page'])
    : 1;

$limit = isset($_GET['limit'])
    ? (int)$_GET['limit']
    : 50;

if (!in_array($limit, $allowedPageLimits, true)) {
    $limit = 50;
}

$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$stockFilter = trim((string)($_GET['stock'] ?? ''));


/*
|--------------------------------------------------------------------------
| Duplicate items filter
|--------------------------------------------------------------------------
| duplicates=1 par sirf duplicate groups show hongi.
|--------------------------------------------------------------------------
*/
$duplicateFilter = trim(
    (string)($_GET['duplicates'] ?? '')
);

if (!in_array(
    $duplicateFilter,
    ['', '1'],
    true
)) {
    $duplicateFilter = '';
}

if (!in_array(
    $typeFilter,
    ['', 'product', 'service', 'menu'],
    true
)) {
    $typeFilter = '';
}

if (!in_array(
    $stockFilter,
    ['', 'low', 'out'],
    true
)) {
    $stockFilter = '';
}

/*
|--------------------------------------------------------------------------
| Dynamic statement binding helper
|--------------------------------------------------------------------------
*/
$bindDynamicParams = function (
    mysqli_stmt $statement,
    string $types,
    array &$params
): void {
    if ($types === '' || empty($params)) {
        return;
    }

    $bindValues = [];
    $bindValues[] = &$types;

    foreach ($params as &$value) {
        $bindValues[] = &$value;
    }

    call_user_func_array(
        [$statement, 'bind_param'],
        $bindValues
    );

    unset($value);
};

/*
|--------------------------------------------------------------------------
| Duplicate item detection — variation aware
|--------------------------------------------------------------------------
| Same client ke andar duplicate tab maana jayega jab:
| 1. Non-empty normalized SKU same ho, OR
| 2. Normalized product name AND complete normalized variation signature same ho.
|
| Variation signature priority:
| - item_variants table ke sorted size + color combinations
| - fallback: items.variant_details when has_variants = 1
| - otherwise: no-variants
|
| Example:
| Milk Pack + 100ml + Chocolate != Milk Pack + 500ml + Chocolate
| Milk Pack + 100ml + Chocolate == Milk Pack + 100ml + Chocolate
|--------------------------------------------------------------------------
*/
$buildDuplicateKeySql = static function (
    string $columnSql
): string {
    $expression = "
        LOWER(
            TRIM(
                COALESCE({$columnSql}, '')
            )
        )
    ";

    /* Whitespace remove */
    $expression = "REPLACE({$expression}, ' ', '')";
    $expression = "REPLACE({$expression}, CHAR(9), '')";
    $expression = "REPLACE({$expression}, CHAR(10), '')";
    $expression = "REPLACE({$expression}, CHAR(13), '')";

    /* Common formatting punctuation remove */
    foreach (
        [
            '-',
            '_',
            '.',
            ',',
            '/',
            '(',
            ')',
            '[',
            ']',
            '{',
            '}',
            ':',
            ';',
            "'"
        ] as $character
    ) {
        $escapedCharacter = str_replace(
            "'",
            "''",
            $character
        );

        $expression = "REPLACE(
            {$expression},
            '{$escapedCharacter}',
            ''
        )";
    }

    return $expression;
};

/* item_variants table optional fallback protection */
$itemVariantsTableAvailable = false;
$variantTableResult = $conn->query("SHOW TABLES LIKE 'item_variants'");

if ($variantTableResult instanceof mysqli_result) {
    $itemVariantsTableAvailable = $variantTableResult->num_rows > 0;
    $variantTableResult->free();
}

/*
|--------------------------------------------------------------------------
| Build one deterministic variation signature for an items table alias
|--------------------------------------------------------------------------
| DISTINCT + ORDER BY ki wajah se rows ka save order duplicate result ko
| affect nahi karega. 100 ml aur 100ml bhi same normalized value banenge.
|--------------------------------------------------------------------------
*/
$buildItemVariantSignatureSql = static function (
    string $itemAlias
) use (
    $buildDuplicateKeySql,
    $itemVariantsTableAvailable
): string {
    $normalizedVariantDetailsSql =
        $buildDuplicateKeySql(
            $itemAlias . '.variant_details'
        );

    $fallbackSignatureSql = "
        CASE
            WHEN COALESCE({$itemAlias}.has_variants, 0) = 1
                THEN CONCAT(
                    'details:',
                    {$normalizedVariantDetailsSql}
                )
            ELSE 'no-variants'
        END
    ";

    if (!$itemVariantsTableAvailable) {
        return $fallbackSignatureSql;
    }

    $normalizedVariantSizeSql =
        $buildDuplicateKeySql('variant_row.size');

    $normalizedVariantColorSql =
        $buildDuplicateKeySql('variant_row.color');

    return "
        COALESCE(
            (
                SELECT CONCAT(
                    'rows:',
                    GROUP_CONCAT(
                        DISTINCT CONCAT(
                            {$normalizedVariantSizeSql},
                            '#',
                            {$normalizedVariantColorSql}
                        )
                        ORDER BY
                            {$normalizedVariantSizeSql},
                            {$normalizedVariantColorSql}
                        SEPARATOR '||'
                    )
                )
                FROM item_variants variant_row
                WHERE variant_row.item_id = {$itemAlias}.id
            ),
            {$fallbackSignatureSql}
        )
    ";
};

$normalizedItemNameSql =
    $buildDuplicateKeySql('i.name');

$normalizedItemSkuSql =
    $buildDuplicateKeySql('i.sku');

$normalizedItemVariantSignatureSql =
    $buildItemVariantSignatureSql('i');

$duplicateNameSourceSql =
    $buildDuplicateKeySql('source_item.name');

$duplicateNameSourceVariantSignatureSql =
    $buildItemVariantSignatureSql('source_item');

$duplicateSkuSourceSql =
    $buildDuplicateKeySql('source_sku.sku');

/*
|--------------------------------------------------------------------------
| Duplicate group joins
|--------------------------------------------------------------------------
| Name duplicate group now name + exact variation signature par banta hai.
| SKU duplicate rule variation se independent intentionally rakha gaya hai.
|--------------------------------------------------------------------------
*/
$duplicateJoinSql = "

    LEFT JOIN (
        SELECT
            duplicate_name_source.client_id,
            duplicate_name_source.duplicate_name_key,
            duplicate_name_source.duplicate_variant_key,
            MIN(duplicate_name_source.id) AS keep_name_id,
            COUNT(*) AS duplicate_name_count

        FROM (
            SELECT
                source_item.id,
                source_item.client_id,
                {$duplicateNameSourceSql} AS duplicate_name_key,
                {$duplicateNameSourceVariantSignatureSql}
                    AS duplicate_variant_key

            FROM items source_item

            WHERE source_item.client_id = {$client_id}
              AND TRIM(COALESCE(source_item.name, '')) <> ''
        ) duplicate_name_source

        GROUP BY
            duplicate_name_source.client_id,
            duplicate_name_source.duplicate_name_key,
            duplicate_name_source.duplicate_variant_key

        HAVING COUNT(*) > 1
    ) duplicate_names

        ON duplicate_names.client_id = i.client_id
        AND duplicate_names.duplicate_name_key =
            {$normalizedItemNameSql}
        AND duplicate_names.duplicate_variant_key =
            {$normalizedItemVariantSignatureSql}


    LEFT JOIN (
        SELECT
            source_sku.client_id,
            {$duplicateSkuSourceSql} AS duplicate_sku_key,
            MIN(source_sku.id) AS keep_sku_id,
            COUNT(*) AS duplicate_sku_count

        FROM items source_sku

        WHERE source_sku.client_id = {$client_id}
          AND TRIM(COALESCE(source_sku.sku, '')) <> ''

        GROUP BY
            source_sku.client_id,
            {$duplicateSkuSourceSql}

        HAVING COUNT(*) > 1
    ) duplicate_skus

        ON duplicate_skus.client_id = i.client_id
        AND duplicate_skus.duplicate_sku_key =
            {$normalizedItemSkuSql}
";

/*
|--------------------------------------------------------------------------
| Duplicate result expressions
|--------------------------------------------------------------------------
*/
$duplicateFlagSql = "
    (
        duplicate_names.keep_name_id IS NOT NULL
        OR duplicate_skus.keep_sku_id IS NOT NULL
    )
";

$duplicateMasterIdSql = "
    LEAST(
        COALESCE(
            duplicate_names.keep_name_id,
            i.id
        ),
        COALESCE(
            duplicate_skus.keep_sku_id,
            i.id
        )
    )
";

$duplicateReasonSql = "
    CASE
        WHEN duplicate_names.keep_name_id IS NOT NULL
             AND duplicate_skus.keep_sku_id IS NOT NULL
            THEN 'Name + Same Variation + SKU'

        WHEN duplicate_names.keep_name_id IS NOT NULL
            THEN 'Product Name + Same Variation'

        WHEN duplicate_skus.keep_sku_id IS NOT NULL
            THEN 'SKU'

        ELSE ''
    END
";

/*
|--------------------------------------------------------------------------
| Build WHERE conditions
|--------------------------------------------------------------------------
*/
$whereConditions = [
    'i.client_id = ?'
];

$bindTypes = 'i';
$bindParams = [
    $client_id
];

if ($search !== '') {
    $searchValue = '%' . $search . '%';

    $whereConditions[] = "
        (
            i.name LIKE ?
            OR i.sku LIKE ?
            OR i.category LIKE ?
            OR COALESCE(
                NULLIF(s.company_name, ''),
                s.name,
                ''
            ) LIKE ?
        )
    ";

    $bindTypes .= 'ssss';

    $bindParams[] = $searchValue;
    $bindParams[] = $searchValue;
    $bindParams[] = $searchValue;
    $bindParams[] = $searchValue;
}

if ($typeFilter !== '') {
    $whereConditions[] = 'i.type = ?';
    $bindTypes .= 's';
    $bindParams[] = $typeFilter;
}

if ($stockFilter === 'low') {
    $whereConditions[] = "
        COALESCE(i.stock_qty, 0) > 0
        AND COALESCE(i.stock_qty, 0) <= 5
    ";
} elseif ($stockFilter === 'out') {
    $whereConditions[] = "
        COALESCE(i.stock_qty, 0) <= 0
    ";
}

/*
|--------------------------------------------------------------------------
| Duplicate items only
|--------------------------------------------------------------------------
*/
if ($duplicateFilter === '1') {
    $whereConditions[] = $duplicateFlagSql;
}

$whereSql = implode(
    ' AND ',
    $whereConditions
);

/*
|--------------------------------------------------------------------------
| Count filtered items
|--------------------------------------------------------------------------
*/
$countSql = "
    SELECT COUNT(*) AS total_filtered_items

    FROM items i

    LEFT JOIN suppliers s
        ON s.id = i.supplier_id
        AND s.client_id = i.client_id

    {$duplicateJoinSql}

    WHERE {$whereSql}
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die(
        'Items count query prepare failed: ' .
        $conn->error
    );
}

$countParams = $bindParams;

$bindDynamicParams(
    $countStmt,
    $bindTypes,
    $countParams
);

$countStmt->execute();

$countRow = $countStmt
    ->get_result()
    ->fetch_assoc();

$filteredItemsCount = (int)(
    $countRow['total_filtered_items'] ?? 0
);

$countStmt->close();

$totalPages = max(
    1,
    (int)ceil(
        $filteredItemsCount / $limit
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| Fetch only current-page items
|--------------------------------------------------------------------------
*/
$items = [];

/*
|--------------------------------------------------------------------------
| Duplicate filter par groups ek saath order hongi
|--------------------------------------------------------------------------
*/
$itemsOrderSql = $duplicateFilter === '1'
    ? "
        duplicate_master_id ASC,
        i.name ASC,
        i.id ASC
    "
    : "
        i.id DESC
    ";

$itemsSql = "
    SELECT
        i.*,

        COALESCE(
            NULLIF(s.company_name, ''),
            s.name
        ) AS supplier_name,

        CASE
            WHEN {$duplicateFlagSql}
                THEN 1
            ELSE 0
        END AS is_duplicate,

        {$duplicateMasterIdSql}
            AS duplicate_master_id,

        {$duplicateReasonSql}
            AS duplicate_reason

    FROM items i

    LEFT JOIN suppliers s
        ON s.id = i.supplier_id
        AND s.client_id = i.client_id

    {$duplicateJoinSql}

    WHERE {$whereSql}

    ORDER BY {$itemsOrderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($itemsSql);

if (!$stmt) {
    die(
        'Items query prepare failed: ' .
        $conn->error
    );
}

$itemBindTypes = $bindTypes . 'ii';
$itemBindParams = $bindParams;

$itemBindParams[] = $limit;
$itemBindParams[] = $offset;

$bindDynamicParams(
    $stmt,
    $itemBindTypes,
    $itemBindParams
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Fetch dynamic wholesale prices
|--------------------------------------------------------------------------
*/
$itemWholesalePrices = [];

if (!empty($items)) {
    $itemIds = array_map(function ($row) {
        return (int)$row['id'];
    }, $items);

    $idList = implode(',', array_filter($itemIds));

    if ($idList !== '') {
        $stmt = $conn->prepare("
            SELECT item_id, label, price, sort_order
            FROM item_wholesale_prices
            WHERE client_id = ?
              AND item_id IN ($idList)
            ORDER BY item_id ASC, sort_order ASC, id ASC
        ");

        if ($stmt) {
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $wpResult = $stmt->get_result();

            while ($wp = $wpResult->fetch_assoc()) {
                $itemId = (int)$wp['item_id'];

                if (!isset($itemWholesalePrices[$itemId])) {
                    $itemWholesalePrices[$itemId] = [];
                }

                $itemWholesalePrices[$itemId][] = [
                    'label' => (string)$wp['label'],
                    'price' => (string)$wp['price'],
                ];
            }

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch product units for pharmacy clients
|--------------------------------------------------------------------------
*/
$itemProductUnits = [];

if ($isPharmacyRetailClient && !empty($items)) {
    $itemIds = array_map(function ($row) {
        return (int)$row['id'];
    }, $items);

    $idList = implode(',', array_filter($itemIds));

    if ($idList !== '') {
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
            ORDER BY product_id ASC, is_base_unit DESC, is_default DESC, conversion_qty ASC, id ASC
        ";

        $unitResult = $conn->query($unitSql);

        if ($unitResult) {
            while ($unit = $unitResult->fetch_assoc()) {
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

            $unitResult->free();
        }
    }
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Products / Items</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
(function () {
    const storageKey = 'oneerp_pos_theme';
    let selectedTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

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
})();
</script>
    <?php include("partials/head.php"); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root{
            --erp-dark:#0f172a;
            --erp-dark-2:#111827;
            --erp-border:#e5e7eb;
            --erp-text:#1f2937;
            --erp-muted:#6b7280;
            --erp-green:#22c55e;
            --erp-blue:#3b82f6;
            --erp-yellow:#fbbf24;
            --erp-red:#ef4444;
            --erp-bg:#f3f4f6;
        }

        body{
            margin:0;
            background:var(--erp-bg);
            color:var(--erp-text);
            font-family:Arial, Helvetica, sans-serif;
        }
        
        
  .container-scroll{
        display:flex;
        min-height:100vh;
           align-items:stretch;
    }

    .sidebar{
        width:250px;
        flex-shrink:0;
          display:flex;
  align-self:stretch;
  background:transparent;
    overflow:hidden;
    }

    /*    .container-scroll{*/
    /*           display:flex;
    /*min-height:100vh;*/
    /*width:100%;*/
    /*align-items:stretch;  /* ← yeh add karo agar nahi hai */
    /*    }*/

/*        .sidebar{*/
/*            width:270px;*/
/*            flex-shrink:0;*/
/*        }*/



/*#dashboardSidebar .sidebar-inner{*/
/*    min-height:100%;*/
/*}*/


/*.sidebar{*/
/*    width:270px;*/
/*    min-width:270px;*/
/*    flex-shrink:0;*/
/*    align-self:stretch;*/
/*    overflow:hidden;*/
/*}*/

.sidebar > .sidebar-wrap{
    width:100%;
    min-height:100%;
    height:100%;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100%;
}
        .main-panel{
            flex:1;
            display:flex;
            flex-direction:column;
            min-width:0;
        }

        .topbar{
            min-height:70px;
            background:#fff;
            border-bottom:1px solid var(--erp-border);
            display:flex;
            align-items:center;
            padding:0;
        }

        .content-wrapper{
            padding:20px;
            flex-grow:1;
            overflow-y:auto;
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:12px;
            margin-bottom:18px;
        }

        .page-title{
            font-size:28px;
            font-weight:700;
            margin:0;
            color:#1f2937;
        }

        .header-actions{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
        }

        .btn-soft{
            border:none;
            border-radius:10px;
            padding:10px 16px;
            font-size:14px;
            font-weight:600;
            color:#fff;
            display:inline-flex;
            align-items:center;
            gap:8px;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
        }

        .btn-add{ background:var(--erp-green); }
        .btn-bulk{ background:var(--erp-blue); }
        .btn-import{ background:var(--erp-green); }
        .btn-export{ background:#6366f1; }

        .stats-row{
            display:grid;
            grid-template-columns: repeat(4, 1fr);
            gap:14px;
            margin-bottom:16px;
        }

        .stat-card{
            border-radius:10px;
            color:#fff;
            padding:14px 18px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
        }

        .stat-card .title{
            font-size:15px;
            font-weight:500;
        }

        .stat-card .count{
            font-size:30px;
            font-weight:700;
            line-height:1;
        }

        .card-blue{ background:linear-gradient(135deg,#3b82f6,#2563eb); }
        .card-yellow{ background:linear-gradient(135deg,#fbbf24,#f59e0b); }
        .card-red{ background:linear-gradient(135deg,#ef4444,#dc2626); }
        .card-green{ background:linear-gradient(135deg,#22c55e,#16a34a); }

        .filter-bar{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:12px;
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
            margin-bottom:16px;
        }

        .quick-action-btn,
        .filter-btn,
        .ghost-btn{
            height:42px;
            border-radius:8px;
            border:1px solid #d1d5db;
            background:#fff;
            padding:0 14px;
            font-size:14px;
            font-weight:600;
            color:#374151;
        }

        .quick-action-btn{
            background:#111827;
            color:#fff;
            border-color:#111827;
        }

        .search-wrap{
            flex:1;
            min-width:240px;
            position:relative;
        }

        .search-wrap .bi-search{
            position:absolute;
            left:12px;
            top:50%;
            transform:translateY(-50%);
            color:#9ca3af;
            font-size:14px;
        }

        .search-input{
            width:100%;
            height:42px;
            border:1px solid #d1d5db;
            border-radius:8px;
            padding:0 14px 0 36px;
            outline:none;
            font-size:14px;
            background:#fff;
        }

        .table-card{
            border:1px solid #e5e7eb;
            border-radius:12px;
            overflow:hidden;
            background:#fff;
        }

        .table{
            margin:0;
            font-size:14px;
        }

        .table thead th{
            background:#28344d;
            color:#fff;
            font-weight:600;
            border-color:rgba(255,255,255,.08) !important;
            padding:14px 12px;
            white-space:nowrap;
            vertical-align:middle;
        }

        .table tbody td{
            vertical-align:middle;
            padding:14px 12px;
            border-color:#e5e7eb !important;
            color:#374151;
        }

        .table tbody tr:hover{
            background:#fafbfc;
        }

        .thumb-box{
            width:46px;
            height:46px;
            border-radius:50%;
            overflow:hidden;
            border:1px solid #e5e7eb;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#fff;
        }

        .thumb-box img{
            width:100%;
            height:100%;
            object-fit:cover;
        }

        .muted-text{
            color:var(--erp-muted);
        }

        .variant-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:36px;
            height:28px;
            border-radius:7px;
            background:#ecfdf5;
            color:#065f46;
            border:1px solid #a7f3d0;
            font-size:12px;
            font-weight:700;
            padding:0 8px;
        }

        .no-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:36px;
            height:28px;
            border-radius:7px;
            background:#f9fafb;
            color:#6b7280;
            border:1px solid #e5e7eb;
            font-size:12px;
            font-weight:700;
            padding:0 8px;
        }
.action-icons{
    display:flex;
    align-items:center;
    gap:10px;
    justify-content:center;
}

/* NEW MODERN ICON BUTTONS */
.action-icon{
    width:38px;
    height:38px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(0,0,0,0.06);
}

/* EDIT */
.action-edit{
    background:#fff4d6;
    color:#e0a100;
}
.action-edit:hover{
    background:#e0a100;
    color:#fff;
}

/* DELETE */
.action-delete{
    background:#ffe3e3;
    color:#ef4444;
}
.action-delete:hover{
    background:#ef4444;
    color:#fff;
}

        .table-footer{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            padding:14px;
            flex-wrap:wrap;
            border-top:1px solid #e5e7eb;
            background:#fff;
        }

        .footer-left,
        .footer-right{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }

        .entries-select{
            width:78px;
            height:38px;
            border:1px solid #d1d5db;
            border-radius:8px;
            padding:0 10px;
            background:#fff;
        }

        .pagination-wrap{
            display:flex;
            align-items:center;
            gap:8px;
        }

        .page-nav-btn,
        .page-number-btn{
            min-width:38px;
            height:38px;
            border:1px solid #d1d5db;
            border-radius:8px;
            background:#fff;
            font-size:14px;
            font-weight:600;
            color:#374151;
        }

        .page-number-btn.active{
            background:#fbbf24;
            border-color:#fbbf24;
            color:#111827;
        }

        .bulk-delete-btn{
            border:none;
            background:#ef4444;
            color:#fff;
            border-radius:8px;
            padding:10px 14px;
            font-size:14px;
            font-weight:600;
        }

        .table-responsive{
            overflow:auto;
        }

        .empty-state{
            padding:30px 15px;
            text-align:center;
            color:#6b7280;
        }

        select.form-select {
            padding: 1.1rem 1.75rem;
        }

        /* Modal UI like services.php */
        .item-modal-content{
            background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
            color:#fff;
            border:none;
            border-radius:14px;
            overflow:hidden;
            box-shadow:0 20px 60px rgba(0,0,0,0.35);
        }

        .item-modal-header{
            background:transparent;
            border-bottom:1px solid rgba(255,255,255,0.08);
            padding:18px 22px;
        }

        .item-modal-body{
            padding:20px 22px 10px;
        }

        .item-modal-footer{
            border-top:1px solid rgba(255,255,255,0.08);
            padding:16px 22px 18px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            flex-wrap:wrap;
            gap:14px;
        }

        .upload-box{
            border:1px dashed rgba(255,255,255,0.18);
            border-radius:12px;
            padding:12px;
            background:rgba(255,255,255,0.02);
            text-align:center;
            height:100%;
        }

        .upload-preview{
            min-height:220px;
            border:1px dashed rgba(255,255,255,0.15);
            border-radius:12px;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            padding:15px;
            overflow:hidden;
            background:rgba(255,255,255,0.02);
        }

        .upload-preview i{
            font-size:46px;
            color:#bfc4dc;
            margin-bottom:10px;
        }

        .upload-title{
            font-size:18px;
            font-weight:600;
            color:#d7dbef;
        }

        .upload-subtext{
            font-size:12px;
            color:#8f96b8;
            margin-top:4px;
        }

        .upload-preview img{
            max-width:100%;
            max-height:190px;
            border-radius:10px;
            object-fit:cover;
        }

        .upload-btn{
            margin-top:12px;
            width:100%;
            height:44px;
            border:none;
            border-radius:10px;
            background:linear-gradient(180deg,#3f4767 0%, #353d5b 100%);
            color:#fff;
            font-weight:600;
        }

        .item-label{
            display:block;
            font-size:15px;
            margin-bottom:7px;
            color:#e4e8f8;
            font-weight:500;
        }

        .item-label span{
            color:#f4b21c;
        }

        .item-input,
        .item-textarea{
            width:100%;
            border:1px solid rgba(255,255,255,0.10);
            background:#e9eaf3;
            color:#222;
            border-radius:8px;
            height:44px;
            padding:0 14px;
            outline:none;
            font-size:14px;
        }

        .item-textarea{
            min-height:90px;
            resize:none;
            padding:12px 14px;
        }

        .price-wrap{
            display:flex;
            align-items:center;
            background:#e9eaf3;
            border-radius:8px;
            overflow:hidden;
            border:1px solid rgba(255,255,255,0.10);
        }

        .price-prefix{
            min-width:64px;
            height:44px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#d9dce8;
            color:#222;
            font-weight:600;
            border-right:1px solid #c7cbda;
        }

        .price-input{
            border:none !important;
            background:transparent !important;
            box-shadow:none !important;
        }

        .option-pills{
            display:flex;
            align-items:center;
            gap:18px;
            flex-wrap:wrap;
            padding-top:6px;
        }

        .option-check{
            display:flex;
            align-items:center;
            gap:8px;
            color:#dbe0f4;
            font-size:14px;
        }

        .option-check input{
            width:18px;
            height:18px;
            accent-color:#f0ae19;
        }

        .modal-side-note{
            color:#9aa3c7;
            font-size:12px;
            margin-top:10px;
        }

        .footer-left-note{
            color:#dbe0f4;
            font-size:14px;
            font-weight:500;
        }

        .footer-btns{
            display:flex;
            align-items:center;
            gap:10px;
        }

        .cancel-btn{
            height:42px;
            min-width:110px;
            border:none;
            border-radius:8px;
            background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
            color:#fff;
            font-weight:600;
        }

        .save-btn{
            height:42px;
            min-width:130px;
            border:none;
            border-radius:8px;
            background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
            color:#fff;
            font-weight:700;
        }

        .import-modal-content .modal-header{
            background:linear-gradient(135deg,#000,#e4ac00);
            color:#fff;
        }

        /* Bulk Edit Modal - same as Add New Product modal */
.bulk-modal-content{
    background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
    color:#fff;
    border:none;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.35);
}

.bulk-modal-header{
    background:transparent;
    border-bottom:1px solid rgba(255,255,255,0.08);
    padding:18px 22px;
}

.bulk-modal-header .modal-title{
    font-weight:700;
    font-size:18px;
    color:#fff;
}

.bulk-modal-body{
    padding:20px 22px 10px;
}

.bulk-modal-footer{
    border-top:1px solid rgba(255,255,255,0.08);
    padding:16px 22px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:14px;
}

.bulk-label{
    display:block;
    font-size:15px;
    margin-bottom:7px;
    color:#e4e8f8;
    font-weight:500;
}

.bulk-input{
    width:100%;
    border:1px solid rgba(255,255,255,0.10);
    background:#e9eaf3;
    color:#222;
    border-radius:8px;
    height:44px;
    padding:0 14px;
    outline:none;
    font-size:14px;
    transition:0.2s ease;
}

.bulk-input:focus{
    border-color:#f0ae19;
    box-shadow:0 0 0 2px rgba(240,174,25,0.2);
}

.bulk-left-note{
    color:#dbe0f4;
    font-size:14px;
    font-weight:500;
}

.bulk-footer-btns{
    display:flex;
    align-items:center;
    gap:10px;
}

.bulk-cancel-btn{
    height:42px;
    min-width:110px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
    color:#fff;
    font-weight:600;
}

.bulk-save-btn{
    height:42px;
    min-width:130px;
    border:none;
    border-radius:8px;
    background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
    color:#fff;
    font-weight:700;
}

.bulk-modal-content .btn-close-white{
    filter:invert(1);
}

.bulk-note-box{
    border:1px dashed rgba(255,255,255,0.16);
    border-radius:12px;
    padding:12px 14px;
    background:rgba(255,255,255,0.03);
    color:#cfd5ea;
    font-size:13px;
    line-height:1.6;
    margin-top:4px;
}

@media (max-width:768px){
    .bulk-modal-footer{
        justify-content:flex-end;
    }

    .bulk-left-note{
        width:100%;
    }

    .bulk-footer-btns{
        width:100%;
        flex-direction:column;
    }

    .bulk-cancel-btn,
    .bulk-save-btn{
        width:100%;
    }
}

        .bulk-modal-content .modal-title{
            font-weight:700;
        }

        @media (max-width: 1200px){
            .stats-row{
                grid-template-columns: repeat(2, minmax(0,1fr));
            }
        }

        @media (max-width: 992px){
            .item-modal-body .row{
                row-gap:16px;
            }
        }

        @media (max-width: 768px){
            .stats-row{
                grid-template-columns: 1fr;
            }

            .filter-bar{
                flex-direction:column;
                align-items:stretch;
            }

            .search-wrap{
                min-width:100%;
            }

            .page-title{
                font-size:24px;
            }

            .sidebar{
                width:240px;
            }

            .item-modal-footer{
                justify-content:flex-end;
            }

            .footer-left-note{
                width:100%;
            }
        }
    

/* =========================================================
   PRODUCTS / ITEMS POPUP BLACK + GOLD + WHITE THEME OVERRIDE
   Add/Edit Item, Import Items, Bulk Edit
   Backend/PHP/JS same - only popup theme
========================================================= */

.modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
}

#itemModal .modal-dialog,
#importModal .modal-dialog,
#bulkEditModal .modal-dialog{
    max-width:min(1240px, 94vw) !important;
}

#importModal .modal-dialog,
#bulkEditModal .modal-dialog{
    max-width:min(620px, 94vw) !important;
}

#itemModal .item-modal-content,
#importModal .import-modal-content,
#bulkEditModal .bulk-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.55) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 30px 90px rgba(0,0,0,.65) !important;
}

#itemModal .item-modal-header,
#importModal .modal-header,
#bulkEditModal .bulk-modal-header{
    background:linear-gradient(180deg,#161b28 0%, #0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
}

#itemModal .modal-title,
#importModal .modal-title,
#bulkEditModal .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

#itemModal .modal-title::before,
#importModal .modal-title::before,
#bulkEditModal .modal-title::before{
    content:'';
    width:13px;
    height:13px;
    border-radius:50%;
    background:#c9a84c;
    box-shadow:0 0 0 5px rgba(201,168,76,.15);
    display:inline-block;
    flex-shrink:0;
}

/* Proper visible white X button */
#itemModal .btn-close,
#importModal .btn-close,
#bulkEditModal .btn-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.12) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#itemModal .btn-close::before,
#itemModal .btn-close::after,
#importModal .btn-close::before,
#importModal .btn-close::after,
#bulkEditModal .btn-close::before,
#bulkEditModal .btn-close::after{
    content:'';
    position:absolute;
    top:50%;
    left:50%;
    width:22px;
    height:2px;
    background:#ffffff;
    border-radius:2px;
    transform-origin:center;
}

#itemModal .btn-close::before,
#importModal .btn-close::before,
#bulkEditModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg);
}

#itemModal .btn-close::after,
#importModal .btn-close::after,
#bulkEditModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg);
}

#itemModal .btn-close:hover,
#importModal .btn-close:hover,
#bulkEditModal .btn-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px);
}

#itemModal .item-modal-body,
#importModal .modal-body,
#bulkEditModal .bulk-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#itemModal .item-modal-body::-webkit-scrollbar,
#importModal .modal-body::-webkit-scrollbar,
#bulkEditModal .bulk-modal-body::-webkit-scrollbar{
    width:7px;
}

#itemModal .item-modal-body::-webkit-scrollbar-track,
#importModal .modal-body::-webkit-scrollbar-track,
#bulkEditModal .bulk-modal-body::-webkit-scrollbar-track{
    background:#171c28;
    border-radius:999px;
}

#itemModal .item-modal-body::-webkit-scrollbar-thumb,
#importModal .modal-body::-webkit-scrollbar-thumb,
#bulkEditModal .bulk-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c;
    border-radius:999px;
}

#itemModal .item-modal-body::-webkit-scrollbar-thumb:hover,
#importModal .modal-body::-webkit-scrollbar-thumb:hover,
#bulkEditModal .bulk-modal-body::-webkit-scrollbar-thumb:hover{
    background:#e8bb34;
}

#itemModal .item-modal-footer,
#importModal .modal-footer,
#bulkEditModal .bulk-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.25) !important;
    padding:18px 24px 22px !important;
}

/* Labels */
#itemModal .item-label,
#importModal .form-label,
#bulkEditModal .bulk-label,
#itemModal label,
#importModal label,
#bulkEditModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#itemModal .item-label span{
    color:#f5c742 !important;
}

/* Inputs / selects */
#itemModal .item-input,
#itemModal .item-textarea,
#importModal .form-control,
#bulkEditModal .bulk-input,
#itemModal input,
#itemModal select,
#itemModal textarea,
#importModal input,
#bulkEditModal input,
#bulkEditModal select,
#bulkEditModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#itemModal .item-input,
#importModal .form-control,
#bulkEditModal .bulk-input,
#itemModal input,
#itemModal select,
#importModal input,
#bulkEditModal input,
#bulkEditModal select{
    min-height:50px !important;
    padding:10px 16px !important;
}

#itemModal .item-textarea,
#bulkEditModal textarea{
    min-height:112px !important;
    padding:14px 16px !important;
    resize:vertical !important;
}

#itemModal input::placeholder,
#itemModal textarea::placeholder,
#importModal input::placeholder,
#bulkEditModal input::placeholder{
    color:#8993a5 !important;
}

#itemModal .item-input:focus,
#itemModal .item-textarea:focus,
#importModal .form-control:focus,
#bulkEditModal .bulk-input:focus,
#itemModal input:focus,
#itemModal select:focus,
#itemModal textarea:focus,
#importModal input:focus,
#bulkEditModal input:focus,
#bulkEditModal select:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24), 0 5px 14px rgba(0,0,0,.22) !important;
}

/* Price wrapper */
#itemModal .price-wrap{
    background:#ffffff !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#itemModal .price-prefix{
    background:#fff7df !important;
    color:#111827 !important;
    border-right:1px solid rgba(201,168,76,.45) !important;
    font-weight:900 !important;
    min-height:50px !important;
}

#itemModal .price-input{
    background:#ffffff !important;
    box-shadow:none !important;
}

/* Upload image panel */
#itemModal .upload-box{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.35) !important;
    border-radius:14px !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#itemModal .upload-preview{
    background:#0f1117 !important;
    border:1.5px dashed rgba(201,168,76,.55) !important;
    border-radius:14px !important;
}

#itemModal .upload-preview i{
    color:#f5c742 !important;
}

#itemModal .upload-title{
    color:#ffffff !important;
    font-weight:900 !important;
}

#itemModal .upload-subtext,
#itemModal .modal-side-note,
#itemModal .footer-left-note,
#bulkEditModal .bulk-left-note{
    color:#dbe0f4 !important;
    font-weight:700 !important;
}

#itemModal .upload-btn{
    background:#252b3a !important;
    color:#ffffff !important;
    border:1px solid rgba(255,255,255,.12) !important;
    border-radius:10px !important;
    font-weight:900 !important;
}

#itemModal .upload-btn:hover{
    background:#32394b !important;
}

/* Options checkboxes */
#itemModal .option-pills{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.25) !important;
    border-radius:12px !important;
    padding:14px !important;
}

#itemModal .option-check{
    color:#ffffff !important;
    font-weight:800 !important;
}

#itemModal .option-check input{
    accent-color:#d8a500 !important;
}

/* Bulk note */
#bulkEditModal .bulk-note-box{
    background:#151a26 !important;
    border:1px dashed rgba(201,168,76,.45) !important;
    color:#dbe0f4 !important;
    border-radius:12px !important;
    font-weight:700 !important;
}

#bulkEditModal .bulk-note-box strong{
    color:#f5c742 !important;
}

/* Footer buttons */
#itemModal .cancel-btn,
#bulkEditModal .bulk-cancel-btn,
#importModal .btn-outline-dark,
#importModal .btn-secondary{
    height:44px !important;
    min-width:110px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#itemModal .cancel-btn:hover,
#bulkEditModal .bulk-cancel-btn:hover,
#importModal .btn-outline-dark:hover,
#importModal .btn-secondary:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#itemModal .save-btn,
#bulkEditModal .bulk-save-btn,
#importModal .btn-warning,
#importModal .btn-primary{
    height:44px !important;
    min-width:130px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#itemModal .save-btn:hover,
#bulkEditModal .bulk-save-btn:hover,
#importModal .btn-warning:hover,
#importModal .btn-primary:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px);
}

/* Import sample button */
#importModal .btn-sm{
    background:#252b3a !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.35) !important;
    border-radius:10px !important;
    font-weight:900 !important;
}

#importModal .btn-sm:hover{
    background:#32394b !important;
    color:#f5c742 !important;
}

@media (max-width:768px){
    #itemModal .modal-dialog,
    #importModal .modal-dialog,
    #bulkEditModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #itemModal .modal-title,
    #importModal .modal-title,
    #bulkEditModal .modal-title{
        font-size:18px !important;
    }

    #itemModal .btn-close,
    #importModal .btn-close,
    #bulkEditModal .btn-close{
        width:42px !important;
        height:42px !important;
    }

    #itemModal .cancel-btn,
    #itemModal .save-btn,
    #bulkEditModal .bulk-cancel-btn,
    #bulkEditModal .bulk-save-btn,
    #importModal .btn-outline-dark,
    #importModal .btn-warning{
        width:100% !important;
    }
}

/* =========================================================
   PRODUCT ADD/EDIT POPUP SMALL SIZE FIX
   Upload image feature keep same
========================================================= */

#itemModal .modal-dialog{
    max-width:900px !important;
    width:900px !important;
    margin:1rem auto !important;
}

#itemModal .item-modal-content{
    max-height:calc(100vh - 35px) !important;
}

#itemModal .item-modal-header{
    padding:14px 18px !important;
}

#itemModal .modal-title{
    font-size:18px !important;
}

#itemModal .btn-close{
    width:38px !important;
    height:38px !important;
}

#itemModal .btn-close::before,
#itemModal .btn-close::after{
    width:18px !important;
}

#itemModal .item-modal-body{
    padding:16px 18px !important;
    max-height:calc(100vh - 150px) !important;
    overflow-y:auto !important;
}

#itemModal .item-modal-footer{
    padding:12px 18px 14px !important;
}

/* Upload image section small but visible */
#itemModal .upload-box{
    padding:10px !important;
    border-radius:12px !important;
}

#itemModal .upload-preview{
    min-height:150px !important;
    height:150px !important;
    padding:12px !important;
}

#itemModal .upload-preview i{
    font-size:34px !important;
    margin-bottom:8px !important;
}

#itemModal .upload-title{
    font-size:15px !important;
}

#itemModal .upload-subtext{
    font-size:11px !important;
}

#itemModal .upload-preview img{
    max-height:128px !important;
    object-fit:cover !important;
}

#itemModal .upload-btn{
    height:38px !important;
    margin-top:10px !important;
    font-size:13px !important;
}

#itemModal .modal-side-note{
    font-size:11px !important;
    line-height:1.4 !important;
    margin-top:8px !important;
}

/* Form fields compact */
#itemModal .item-label,
#itemModal label{
    font-size:13px !important;
    margin-bottom:6px !important;
}

#itemModal .item-input,
#itemModal input,
#itemModal select{
    min-height:40px !important;
    height:40px !important;
    padding:7px 12px !important;
    font-size:14px !important;
}

#itemModal .price-prefix{
    min-height:40px !important;
    height:40px !important;
    min-width:54px !important;
}

#itemModal .price-wrap{
    min-height:40px !important;
}

#itemModal .option-pills{
    padding:10px 12px !important;
    gap:18px !important;
}

#itemModal .option-check{
    font-size:13px !important;
}

#itemModal .option-check input{
    width:16px !important;
    height:16px !important;
}

#itemModal .footer-left-note{
    font-size:12px !important;
}

#itemModal .cancel-btn,
#itemModal .save-btn{
    height:38px !important;
    min-width:105px !important;
    font-size:13px !important;
}

/* Better column ratio: image visible, form wider */
#itemModal .col-lg-4{
    width:32% !important;
    flex:0 0 32% !important;
    max-width:32% !important;
}

#itemModal .col-lg-8{
    width:68% !important;
    flex:0 0 68% !important;
    max-width:68% !important;
}

@media (max-width:992px){
    #itemModal .modal-dialog{
        width:94vw !important;
        max-width:94vw !important;
    }

    #itemModal .col-lg-4,
    #itemModal .col-lg-8{
        width:100% !important;
        flex:0 0 100% !important;
        max-width:100% !important;
    }

    #itemModal .upload-preview{
        min-height:130px !important;
        height:130px !important;
    }
}
#itemModal .image-url-box{
    margin-top:12px !important;
    padding-top:10px !important;
    border-top:1px dashed rgba(201,168,76,.35) !important;
}

#itemModal .url-preview-btn{
    margin-top:8px !important;
    height:36px !important;
    font-size:12px !important;
}

/* Requested product table columns */
#itemsTable th:nth-child(4),
#itemsTable td:nth-child(4){
    min-width:220px;
}
#itemsTable th:nth-child(5),
#itemsTable th:nth-child(6),
#itemsTable th:nth-child(7),
#itemsTable th:nth-child(8),
#itemsTable th:nth-child(9),
#itemsTable th:nth-child(10),
#itemsTable td:nth-child(5),
#itemsTable td:nth-child(6),
#itemsTable td:nth-child(7),
#itemsTable td:nth-child(8),
#itemsTable td:nth-child(9),
#itemsTable td:nth-child(10){
    white-space:nowrap;
}


/* =====================================================
   PRODUCTS / ITEMS - DARK PURPLE GLASS THEME
   Paste at END before 
   Backend / PHP / JS logic untouched
===================================================== */

html, body{
    min-height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-yellow:#f59e0b;
    --oms-cyan:#06b6d4;
    --oms-red:#ef4444;
}

/* ================= BASE ================= */
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI", Tahoma, Geneva, Verdana, sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
    overflow-x:hidden !important;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    width:100% !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

.sidebar .sidebar-wrap,
#dashboardSidebar .sidebar-wrap,
#sidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar .sidebar-inner,
#dashboardSidebar .sidebar-inner,
#sidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

.sidebar .sidebar-menu,
#dashboardSidebar .sidebar-menu,
#sidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.main-panel{
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
    transition:margin-left .25s ease !important;
}

.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    align-items:center !important;
    padding:0 !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
}

.content-wrapper{
    position:relative !important;
    padding:24px 26px !important;
    flex:1 !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
}

.content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}

.content-wrapper::after{
    content:"";
    position:absolute;
    width:520px;
    height:520px;
    right:-190px;
    top:-210px;
    pointer-events:none;
    z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);
    opacity:.85;
}

.content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* ================= SIDEBAR COLLAPSE SUPPORT ================= */
body.dashboard-sidebar-collapsed .sidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed .sidebar .search-box,
body.dashboard-sidebar-collapsed .sidebar .menu-title,
body.dashboard-sidebar-collapsed .sidebar .menu-arrow,
body.dashboard-sidebar-collapsed .sidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-icon{
    margin:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .sidebar-inner{
    padding-left:8px !important;
    padding-right:8px !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn{
    width:52px !important;
    height:42px !important;
    margin:0 auto !important;
    padding:0 !important;
    border-radius:12px !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn span{
    display:none !important;
}

/* ================= PAGE HEADER ================= */
.page-header{
    position:relative !important;
    z-index:50 !important;
    margin-bottom:20px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
}

.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
}

.header-actions{
    position:relative !important;
    z-index:80 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

/* ================= HEADER BUTTONS ================= */
.btn-soft{
    border:none !important;
    border-radius:13px !important;
    padding:11px 17px !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    cursor:pointer !important;
    min-height:46px !important;
    box-shadow:0 14px 28px rgba(0,0,0,.22) !important;
    transition:.22s ease !important;
}

.btn-soft:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

.btn-add{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

.btn-bulk{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
    border:1px solid rgba(139,92,246,.32) !important;
}

.btn-import{
    background:linear-gradient(135deg,#0891b2,#06b6d4) !important;
    border:1px solid rgba(6,182,212,.28) !important;
}

.btn-export{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border:1px solid rgba(139,92,246,.38) !important;
}

/* ================= STAT CARDS ================= */
.stats-row{
    position:relative !important;
    z-index:10 !important;
    display:grid !important;
    grid-template-columns:repeat(4, 1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    isolation:isolate !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
    z-index:-1;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card .title{
    font-size:15px !important;
    font-weight:900 !important;
    color:rgba(255,255,255,.96) !important;
    line-height:1.35 !important;
    position:relative !important;
    z-index:3 !important;
}

.stat-card .count{
    font-size:clamp(22px, 2vw, 30px) !important;
    font-weight:900 !important;
    color:#fff !important;
    line-height:1.1 !important;
    position:relative !important;
    z-index:3 !important;
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
.card-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c) !important; }
.card-red{ background:linear-gradient(135deg,#be123c,#ef4444) !important; }
.card-green{ background:linear-gradient(135deg,#047857,#16a34a) !important; }

/* ================= FILTER BAR ================= */
.filter-bar{
    position:relative !important;
    z-index:20 !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    padding:14px !important;
    display:grid !important;
    grid-template-columns:auto 1fr auto auto !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:18px !important;
    flex-wrap:nowrap !important;
}

.quick-action-btn,
.filter-btn,
.ghost-btn{
    height:46px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 16px !important;
    font-size:14px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
    background:rgba(15,23,42,.72) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    transition:.22s ease !important;
}

.quick-action-btn{
    background:linear-gradient(135deg,#111827,#1f2937) !important;
    color:#fff !important;
}

.filter-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.36) !important;
}

.ghost-btn{
    min-width:52px !important;
}

.quick-action-btn:hover,
.filter-btn:hover,
.ghost-btn:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

.search-wrap{
    width:100% !important;
    min-width:0 !important;
    position:relative !important;
    flex:none !important;
}

.search-wrap .bi-search{
    position:absolute !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#94a3b8 !important;
    font-size:15px !important;
    z-index:2 !important;
}

.search-input{
    width:100% !important;
    min-width:0 !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px 0 42px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
    box-shadow:none !important;
    transition:.2s !important;
}

.search-input::placeholder{
    color:#94a3b8 !important;
}

.search-input:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
}

/* Dropdown dark */
.dropdown-menu{
    background:#0f172a !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:14px !important;
    box-shadow:0 20px 50px rgba(0,0,0,.45) !important;
    padding:8px !important;
    z-index:99999 !important;
}

.dropdown-item{
    color:#e5e7eb !important;
    border-radius:10px !important;
    font-weight:800 !important;
}

.dropdown-item:hover{
    background:rgba(124,58,237,.16) !important;
    color:#fff !important;
}

.dropdown-item.text-danger{
    color:#f87171 !important;
}

.dropdown-menu .form-select{
    background:rgba(15,23,42,.86) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    min-height:44px !important;
    font-weight:800 !important;
    padding:.65rem 1rem !important;
}

.dropdown-menu .form-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* ================= TABLE ================= */
.table-card{
    position:relative !important;
    z-index:10 !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    transition:.25s ease !important;
}

.table-card:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.table-responsive{
    overflow:auto !important;
}

.table{
    margin:0 !important;
    color:#e5e7eb !important;
    background:transparent !important;
    min-width:1280px !important;
}

.table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:15px 14px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
}

.table tbody tr:hover td{
    background:rgba(124,58,237,.13) !important;
    color:#fff !important;
}

.table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.empty-state{
    padding:34px 16px !important;
    text-align:center !important;
    color:#94a3b8 !important;
    background:rgba(15,23,42,.32) !important;
    font-weight:900 !important;
}

.thumb-box{
    width:48px !important;
    height:48px !important;
    border-radius:14px !important;
    overflow:hidden !important;
    border:1px solid rgba(148,163,184,.18) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(15,23,42,.76) !important;
    color:#94a3b8 !important;
}

.thumb-box img{
    width:100% !important;
    height:100% !important;
    object-fit:cover !important;
}

.muted-text{
    color:#94a3b8 !important;
}

/* Badges */
.variant-badge{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    min-width:42px !important;
    height:30px !important;
    border-radius:999px !important;
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
    font-size:12px !important;
    font-weight:900 !important;
    padding:0 10px !important;
}

.no-badge{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    min-width:42px !important;
    height:30px !important;
    border-radius:999px !important;
    background:rgba(148,163,184,.12) !important;
    color:#94a3b8 !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-size:12px !important;
    font-weight:900 !important;
    padding:0 10px !important;
}

/* Actions */
.action-icons{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    justify-content:center !important;
}

.action-icon{
    width:40px !important;
    height:40px !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:16px !important;
    cursor:pointer !important;
    transition:all .20s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.action-icon:hover{
    transform:translateY(-2px) !important;
}

.action-edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.action-edit:hover{
    background:#f59e0b !important;
    color:#07111f !important;
}

.action-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

.action-delete:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

/* ================= TABLE FOOTER ================= */
.table-footer{
    padding:16px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.28) !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    flex-wrap:wrap !important;
    color:#94a3b8 !important;
    font-size:14px !important;
    font-weight:800 !important;
}

.footer-left,
.footer-right{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

.entries-select{
    width:78px !important;
    height:40px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    padding:0 10px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
}

.entries-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.page-nav-btn,
.page-number-btn{
    min-width:40px !important;
    height:40px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.62) !important;
    font-size:14px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
}

.page-number-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.35) !important;
    color:#fff !important;
}

.bulk-delete-btn{
    border:none !important;
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
    color:#fff !important;
    border-radius:13px !important;
    padding:11px 16px !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(239,68,68,.20) !important;
}

/* =====================================================
   MODALS - ADD / EDIT / IMPORT / BULK DARK PURPLE GLASS
===================================================== */

.modal-backdrop.show{
    opacity:.76 !important;
    background:#000 !important;
}

#itemModal,
#importModal,
#bulkEditModal{
    z-index:99999 !important;
}

#itemModal .modal-dialog{
    max-width:900px !important;
    width:900px !important;
    margin:1rem auto !important;
}

#importModal .modal-dialog,
#bulkEditModal .modal-dialog{
    max-width:min(620px, 94vw) !important;
}

#itemModal .item-modal-content,
#importModal .import-modal-content,
#bulkEditModal .bulk-modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.98), rgba(17,24,39,.96)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:20px !important;
    overflow:hidden !important;
    box-shadow:
        0 30px 90px rgba(0,0,0,.72),
        0 0 55px rgba(124,58,237,.16) !important;
}

#itemModal .item-modal-content{
    max-height:calc(100vh - 35px) !important;
}

#itemModal .item-modal-header,
#importModal .modal-header,
#bulkEditModal .bulk-modal-header{
    min-height:66px !important;
    padding:14px 18px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#itemModal .modal-title,
#importModal .modal-title,
#bulkEditModal .modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
}

#itemModal .modal-title::before,
#importModal .modal-title::before,
#bulkEditModal .modal-title::before{
    display:none !important;
}

#itemModal .btn-close,
#importModal .btn-close,
#bulkEditModal .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:13px !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    position:relative !important;
    background-image:none !important;
    padding:0 !important;
}

#itemModal .btn-close::before,
#itemModal .btn-close::after,
#importModal .btn-close::before,
#importModal .btn-close::after,
#bulkEditModal .btn-close::before,
#bulkEditModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:18px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#itemModal .btn-close::before,
#importModal .btn-close::before,
#bulkEditModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#itemModal .btn-close::after,
#importModal .btn-close::after,
#bulkEditModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#itemModal .btn-close:hover,
#importModal .btn-close:hover,
#bulkEditModal .btn-close:hover{
    background:rgba(239,68,68,.22) !important;
    border-color:rgba(239,68,68,.35) !important;
}

#itemModal .item-modal-body,
#importModal .modal-body,
#bulkEditModal .bulk-modal-body{
    padding:16px 18px !important;
    background:rgba(15,23,42,.52) !important;
    color:#f8fafc !important;
    max-height:calc(100vh - 150px) !important;
    overflow-y:auto !important;
}

#itemModal .item-modal-footer,
#importModal .modal-footer,
#bulkEditModal .bulk-modal-footer{
    padding:12px 18px 14px !important;
    background:rgba(7,11,20,.48) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

/* Modal labels */
#itemModal .item-label,
#importModal .form-label,
#bulkEditModal .bulk-label,
#itemModal label,
#importModal label,
#bulkEditModal label{
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:6px !important;
}

#itemModal .item-label span{
    color:#a78bfa !important;
}

/* Modal inputs */
#itemModal .item-input,
#itemModal .item-textarea,
#importModal .form-control,
#bulkEditModal .bulk-input,
#itemModal input,
#itemModal select,
#itemModal textarea,
#importModal input,
#bulkEditModal input,
#bulkEditModal select,
#bulkEditModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    min-height:40px !important;
    height:40px !important;
    font-size:14px !important;
    font-weight:700 !important;
    padding:7px 12px !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#itemModal .item-textarea,
#bulkEditModal textarea{
    min-height:96px !important;
    height:auto !important;
    padding:12px 14px !important;
}

#itemModal .item-input:focus,
#itemModal .item-textarea:focus,
#importModal .form-control:focus,
#bulkEditModal .bulk-input:focus,
#itemModal input:focus,
#itemModal select:focus,
#itemModal textarea:focus,
#importModal input:focus,
#bulkEditModal input:focus,
#bulkEditModal select:focus{
    background:#fff !important;
    color:#111827 !important;
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 4px rgba(124,58,237,.22),
        0 10px 22px rgba(0,0,0,.18) !important;
}

/* Price wrapper */
#itemModal .price-wrap{
    background:#ffffff !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    min-height:40px !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#itemModal .price-prefix{
    background:rgba(124,58,237,.12) !important;
    color:#111827 !important;
    border-right:1px solid rgba(124,58,237,.20) !important;
    font-weight:900 !important;
    min-height:40px !important;
    height:40px !important;
    min-width:54px !important;
}

#itemModal .price-input{
    background:#fff !important;
    box-shadow:none !important;
}

/* Upload box */
#itemModal .upload-box{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.14), transparent 34%),
        rgba(15,23,42,.68) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:16px !important;
    padding:10px !important;
    box-shadow:0 14px 30px rgba(0,0,0,.18) !important;
}

#itemModal .upload-preview{
    min-height:150px !important;
    height:150px !important;
    padding:12px !important;
    background:rgba(15,23,42,.76) !important;
    border:1px dashed rgba(139,92,246,.45) !important;
    border-radius:14px !important;
}

#itemModal .upload-preview i{
    color:#a78bfa !important;
    font-size:34px !important;
    margin-bottom:8px !important;
}

#itemModal .upload-title{
    color:#f8fafc !important;
    font-weight:900 !important;
    font-size:15px !important;
}

#itemModal .upload-subtext,
#itemModal .modal-side-note,
#itemModal .footer-left-note,
#bulkEditModal .bulk-left-note{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

#itemModal .upload-subtext{
    font-size:11px !important;
}

#itemModal .upload-preview img{
    max-height:128px !important;
    object-fit:cover !important;
    border-radius:12px !important;
}

#itemModal .upload-btn{
    height:38px !important;
    margin-top:10px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-size:13px !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#itemModal .image-url-box{
    margin-top:12px !important;
    padding-top:10px !important;
    border-top:1px dashed rgba(139,92,246,.35) !important;
}

#itemModal .url-preview-btn{
    margin-top:8px !important;
    height:36px !important;
    font-size:12px !important;
}

/* Options */
#itemModal .option-pills{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.14), transparent 34%),
        rgba(15,23,42,.68) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:14px !important;
    padding:10px 12px !important;
    gap:18px !important;
}

#itemModal .option-check{
    color:#e5e7eb !important;
    font-weight:800 !important;
    font-size:13px !important;
}

#itemModal .option-check input{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

/* Bulk note */
#bulkEditModal .bulk-note-box{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.14), transparent 34%),
        rgba(15,23,42,.68) !important;
    border:1px dashed rgba(139,92,246,.45) !important;
    color:#cbd5e1 !important;
    border-radius:14px !important;
    font-weight:800 !important;
}

#bulkEditModal .bulk-note-box strong{
    color:#a78bfa !important;
}

/* Modal buttons */
#itemModal .cancel-btn,
#bulkEditModal .bulk-cancel-btn,
#importModal .btn-outline-dark,
#importModal .btn-secondary{
    height:38px !important;
    min-width:105px !important;
    border-radius:12px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:0 18px !important;
    box-shadow:0 10px 20px rgba(0,0,0,.18) !important;
}

#itemModal .cancel-btn:hover,
#bulkEditModal .bulk-cancel-btn:hover,
#importModal .btn-outline-dark:hover,
#importModal .btn-secondary:hover{
    background:rgba(148,163,184,.28) !important;
    color:#fff !important;
}

#itemModal .save-btn,
#bulkEditModal .bulk-save-btn,
#importModal .btn-warning,
#importModal .btn-primary{
    height:38px !important;
    min-width:115px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:0 18px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#itemModal .save-btn:hover,
#bulkEditModal .bulk-save-btn:hover,
#importModal .btn-warning:hover,
#importModal .btn-primary:hover{
    transform:translateY(-1px) !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
}

/* Import sample button */
#importModal .btn-sm{
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:12px !important;
    font-weight:900 !important;
}

#importModal .btn-sm:hover{
    background:rgba(124,58,237,.18) !important;
    color:#fff !important;
}

/* Existing compact modal columns keep */
#itemModal .col-lg-4{
    width:32% !important;
    flex:0 0 32% !important;
    max-width:32% !important;
}

#itemModal .col-lg-8{
    width:68% !important;
    flex:0 0 68% !important;
    max-width:68% !important;
}

/* Table columns */
#itemsTable th:nth-child(4),
#itemsTable td:nth-child(4){
    min-width:220px !important;
}

#itemsTable th:nth-child(5),
#itemsTable th:nth-child(6),
#itemsTable th:nth-child(7),
#itemsTable th:nth-child(8),
#itemsTable th:nth-child(9),
#itemsTable th:nth-child(10),
#itemsTable td:nth-child(5),
#itemsTable td:nth-child(6),
#itemsTable td:nth-child(7),
#itemsTable td:nth-child(8),
#itemsTable td:nth-child(9),
#itemsTable td:nth-child(10){
    white-space:nowrap !important;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1200px){
    .stats-row{
        grid-template-columns:repeat(2, minmax(0,1fr)) !important;
    }

    .filter-bar{
        grid-template-columns:auto 1fr auto auto !important;
    }
}

@media (max-width:991px){
    .container-scroll{
        flex-direction:column !important;
    }

    .sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        position:relative !important;
        height:auto !important;
    }

    .sidebar .sidebar-wrap,
    #dashboardSidebar .sidebar-wrap,
    #sidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    .sidebar .sidebar-inner,
    #dashboardSidebar .sidebar-inner,
    #sidebar .sidebar-inner{
        height:auto !important;
        min-height:auto !important;
    }

    .main-panel{
        margin-left:0 !important;
    }

    .topbar{
        position:relative !important;
    }

    .content-wrapper{
        padding:18px !important;
    }

    #itemModal .modal-dialog{
        width:94vw !important;
        max-width:94vw !important;
    }

    #itemModal .col-lg-4,
    #itemModal .col-lg-8{
        width:100% !important;
        flex:0 0 100% !important;
        max-width:100% !important;
    }

    #itemModal .upload-preview{
        min-height:130px !important;
        height:130px !important;
    }
}

@media (max-width:768px){
    .page-header{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .header-actions{
        width:100% !important;
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .btn-soft{
        width:100% !important;
    }

    .stats-row{
        grid-template-columns:1fr !important;
    }

    .filter-bar{
        grid-template-columns:1fr !important;
    }

    .quick-action-btn,
    .filter-btn,
    .ghost-btn,
    .search-wrap{
        width:100% !important;
        min-width:100% !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .table-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .footer-left,
    .footer-right{
        width:100% !important;
        justify-content:flex-start !important;
    }

    #itemModal .modal-dialog,
    #importModal .modal-dialog,
    #bulkEditModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #itemModal .item-modal-footer,
    #bulkEditModal .bulk-modal-footer,
    #importModal .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #itemModal .cancel-btn,
    #itemModal .save-btn,
    #bulkEditModal .bulk-cancel-btn,
    #bulkEditModal .bulk-save-btn,
    #importModal .btn-outline-dark,
    #importModal .btn-warning{
        width:100% !important;
    }
}

/* =====================================================
   FINAL FIX: PRODUCTS TABLE WHITE HOVER REMOVE
   Paste at END before 
===================================================== */

/* Bootstrap table hover variables neutral karo */
#itemsTable,
#itemsTable tbody,
#itemsTable tr,
#itemsTable td,
#itemsTable th{
    --bs-table-bg: transparent !important;
    --bs-table-accent-bg: transparent !important;
    --bs-table-hover-bg: transparent !important;
    --bs-table-hover-color: #ffffff !important;
    --bs-table-striped-bg: transparent !important;
    --bs-table-active-bg: transparent !important;
    background-color: transparent !important;
}

/* Normal row/cell dark glass background */
#itemsTable tbody tr.item-row td{
    background: rgba(15,23,42,.24) !important;
    color: #e5e7eb !important;
    box-shadow: none !important;
}

/* Hover par white bilkul na aaye */
#itemsTable tbody tr.item-row:hover,
#itemsTable tbody tr.item-row:hover td,
#itemsTable tbody tr.item-row:hover > *,
#itemsTable tbody tr:hover,
#itemsTable tbody tr:hover td,
#itemsTable tbody tr:hover > *{
    background: rgba(124,58,237,.10) !important;
    background-color: rgba(124,58,237,.10) !important;
    color: #ffffff !important;
    box-shadow: none !important;
}

/* Kisi specific cell par mouse le jao tab bhi white na ho */
#itemsTable tbody td:hover,
#itemsTable tbody tr.item-row td:hover{
    background: rgba(124,58,237,.12) !important;
    background-color: rgba(124,58,237,.12) !important;
    color: #ffffff !important;
    box-shadow: none !important;
}

/* Checkbox/select/focus ki wajah se row white na ho */
#itemsTable tbody tr:focus,
#itemsTable tbody tr:focus-within,
#itemsTable tbody tr:active,
#itemsTable tbody tr:focus td,
#itemsTable tbody tr:focus-within td,
#itemsTable tbody tr:active td{
    background: rgba(124,58,237,.08) !important;
    background-color: rgba(124,58,237,.08) !important;
    color: #ffffff !important;
    box-shadow: none !important;
}

/* Empty state bhi white na ho */
#itemsTable tbody tr td[colspan],
#itemsTable .empty-state{
    background: rgba(15,23,42,.32) !important;
    background-color: rgba(15,23,42,.32) !important;
    color: #94a3b8 !important;
    box-shadow: none !important;
}

/* Header same dark rahe */
#itemsTable thead th{
    background: rgba(7,11,20,.72) !important;
    background-color: rgba(7,11,20,.72) !important;
    color: #cbd5e1 !important;
    box-shadow: none !important;
}

#itemModal .wholesale-row{
    display:grid !important;
    grid-template-columns: 1fr 1.3fr 42px !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:10px !important;
}

#itemModal .add-wholesale-btn,
#itemModal .remove-wholesale-btn{
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-size:12px !important;
    font-weight:900 !important;
    min-height:36px !important;
    padding:0 12px !important;
    box-shadow:0 10px 20px rgba(109,76,255,.20) !important;
}

#itemModal .remove-wholesale-btn{
    width:42px !important;
    padding:0 !important;
    background:rgba(239,68,68,.22) !important;
    border:1px solid rgba(239,68,68,.35) !important;
    color:#fff !important;
}

@media(max-width:768px){
    #itemModal .wholesale-row{
        grid-template-columns:1fr !important;
    }

    #itemModal .remove-wholesale-btn{
        width:100% !important;
    }
}


/* =====================================================
   PHARMACY UNIT PRICING UI
   Only visible for pharmacy plan clients
===================================================== */

#itemModal .pharmacy-unit-card{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.18), transparent 34%),
        rgba(15,23,42,.72) !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:16px !important;
    padding:14px !important;
    box-shadow:0 14px 30px rgba(0,0,0,.20) !important;
}

#itemModal .pharmacy-unit-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
    margin-bottom:12px !important;
}

#itemModal .pharmacy-unit-title{
    color:#f8fafc !important;
    font-size:15px !important;
    font-weight:900 !important;
}

#itemModal .pharmacy-unit-subtitle{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:700 !important;
    margin-top:3px !important;
}

#itemModal .add-unit-btn{
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-size:12px !important;
    font-weight:900 !important;
    min-height:36px !important;
    padding:0 12px !important;
    box-shadow:0 10px 20px rgba(109,76,255,.22) !important;
}

#itemModal .pharmacy-unit-table{
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    overflow:hidden !important;
}

#itemModal .pharmacy-unit-grid{
    display:grid !important;
    grid-template-columns: 1fr 1fr 1fr 1.1fr 80px 54px !important;
    gap:8px !important;
    align-items:center !important;
    padding:8px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
}

#itemModal .pharmacy-unit-grid:first-child{
    border-top:none !important;
}

#itemModal .pharmacy-unit-grid-head{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    font-size:11px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.03em !important;
}

#itemModal .pharmacy-unit-grid input{
    min-height:36px !important;
    height:36px !important;
    font-size:13px !important;
    padding:6px 9px !important;
    border-radius:10px !important;
}

#itemModal .unit-default-radio{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
    box-shadow:none !important;
}

#itemModal .remove-unit-btn{
    width:38px !important;
    height:36px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(239,68,68,.20) !important;
    border:1px solid rgba(239,68,68,.35) !important;
    color:#fff !important;
}

#itemModal .pharmacy-unit-note{
    margin-top:10px !important;
    background:rgba(59,130,246,.10) !important;
    border:1px solid rgba(59,130,246,.22) !important;
    color:#cbd5e1 !important;
    border-radius:12px !important;
    padding:9px 11px !important;
    font-size:12px !important;
    font-weight:800 !important;
}

@media(max-width:768px){
    #itemModal .pharmacy-unit-head{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #itemModal .add-unit-btn{
        width:100% !important;
    }

    #itemModal .pharmacy-unit-grid,
    #itemModal .pharmacy-unit-grid-head{
        grid-template-columns:1fr !important;
    }
}

/* =====================================================
   PHARMACY UNITS COLUMN - PRODUCTS TABLE
===================================================== */
#itemsTable .item-units{
    min-width:220px !important;
    max-width:320px !important;
}

.unit-chip-wrap{
    display:flex !important;
    align-items:center !important;
    flex-wrap:wrap !important;
    gap:6px !important;
}

.unit-chip{
    display:inline-flex !important;
    flex-direction:column !important;
    gap:1px !important;
    min-width:78px !important;
    padding:6px 8px !important;
    border-radius:12px !important;
    background:rgba(59,130,246,.12) !important;
    border:1px solid rgba(59,130,246,.25) !important;
    color:#e0f2fe !important;
    line-height:1.15 !important;
}

.unit-chip strong{
    font-size:12px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
}

.unit-chip small{
    font-size:10px !important;
    font-weight:800 !important;
    color:#93c5fd !important;
}

.unit-chip-default{
    background:rgba(124,58,237,.16) !important;
    border-color:rgba(139,92,246,.42) !important;
}

.unit-chip-default small{
    color:#c4b5fd !important;
}

/* =========================================================
   ONEERP PRODUCTS / ITEMS LIGHT MODE
   Must remain at the very end of the main style.
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE LAYOUT
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll,
html[data-pos-theme="light"] body .main-panel{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    border-bottom-color:#e2e8f0 !important;
    box-shadow:0 4px 18px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.09),
            transparent 28%
        ),
        radial-gradient(
            circle at 78% 2%,
            rgba(59,130,246,.06),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 45%,
            #faf7ff 75%,
            #f8fafc 100%
        ) !important;
}

html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.035) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ) !important;
    opacity:.55 !important;
}

html[data-pos-theme="light"] body .content-wrapper::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.08),
        transparent 68%
    ) !important;
    opacity:.42 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Header buttons branded rahenge */
html[data-pos-theme="light"] body .btn-add{
    background:linear-gradient(135deg,#059669,#16a34a) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .btn-bulk{
    background:linear-gradient(135deg,#3b82f6,#6d28d9) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .btn-import{
    background:linear-gradient(135deg,#0891b2,#06b6d4) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .btn-export{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .title,
html[data-pos-theme="light"] body .stat-card .count,
html[data-pos-theme="light"] body .stat-card i{
    color:#ffffff !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .stat-card:hover{
    box-shadow:
        0 18px 42px rgba(15,23,42,.18),
        0 0 0 3px rgba(124,58,237,.08) !important;
}

/* =========================================================
   FILTER BAR
========================================================= */

html[data-pos-theme="light"] body .filter-bar{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.05),
            transparent 34%
        ),
        #ffffff !important;

    border-color:#dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Quick Actions */
html[data-pos-theme="light"] body .quick-action-btn{
    background:#f8fafc !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .quick-action-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Search */
html[data-pos-theme="light"] body .search-input{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .search-input:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] body .search-wrap .bi-search{
    color:#64748b !important;
}

/* Filter button */
html[data-pos-theme="light"] body .filter-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
    box-shadow:0 10px 22px rgba(124,58,237,.18) !important;
}

/* Three dots button */
html[data-pos-theme="light"] body .ghost-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .ghost-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   DROPDOWNS
========================================================= */

html[data-pos-theme="light"] body .dropdown-menu{
    background:#ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 18px 42px rgba(15,23,42,.16) !important;
}

html[data-pos-theme="light"] body .dropdown-item{
    background:transparent !important;
    color:#334155 !important;
}

html[data-pos-theme="light"] body .dropdown-item:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
}

html[data-pos-theme="light"] body .dropdown-item.text-danger{
    color:#be123c !important;
}

html[data-pos-theme="light"] body .dropdown-item.text-danger:hover{
    background:#fff1f2 !important;
    color:#be123c !important;
}

html[data-pos-theme="light"] body
.dropdown-menu .form-select{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.dropdown-menu .form-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* =========================================================
   TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:
        0 16px 36px rgba(15,23,42,.09),
        0 0 0 1px rgba(124,58,237,.07) !important;
}

/* Bootstrap table dark variables reset */
html[data-pos-theme="light"] body #itemsTable,
html[data-pos-theme="light"] body #itemsTable tbody,
html[data-pos-theme="light"] body #itemsTable tr,
html[data-pos-theme="light"] body #itemsTable td,
html[data-pos-theme="light"] body #itemsTable th{
    --bs-table-bg:transparent !important;
    --bs-table-accent-bg:transparent !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
    --bs-table-striped-bg:transparent !important;
}

/* Table heading */
html[data-pos-theme="light"] body
#itemsTable thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom-color:#cbd5e1 !important;
    border-right-color:#e2e8f0 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

/* Normal rows */
html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row td,

html[data-pos-theme="light"] body
#itemsTable tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;
    color:#1e293b !important;
    border-top-color:#e8edf4 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

/* Hover rows */
html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row:hover td,

html[data-pos-theme="light"] body
#itemsTable tbody tr:hover td,

html[data-pos-theme="light"] body
#itemsTable tbody tr:hover > *{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;
    color:#0f172a !important;
    box-shadow:none !important;
}

/* Cell hover bhi white/dark na ho */
html[data-pos-theme="light"] body
#itemsTable tbody td:hover,

html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row td:hover{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    color:#0f172a !important;
}

/* Focus row */
html[data-pos-theme="light"] body
#itemsTable tbody tr:focus-within td{
    background:#f3efff !important;
    color:#0f172a !important;
}

/* Product name, price, cost */
html[data-pos-theme="light"] body
#itemsTable .item-name,

html[data-pos-theme="light"] body
#itemsTable .item-price,

html[data-pos-theme="light"] body
#itemsTable .item-cost,

html[data-pos-theme="light"] body
#itemsTable .item-discount{
    color:#0f172a !important;
}

/* Category / supplier */
html[data-pos-theme="light"] body
#itemsTable .item-category,

html[data-pos-theme="light"] body
#itemsTable .item-supplier{
    color:#334155 !important;
}

/* Muted text */
html[data-pos-theme="light"] body .muted-text{
    color:#64748b !important;
}

/* Thumbnail */
html[data-pos-theme="light"] body .thumb-box{
    background:#f8fafc !important;
    color:#64748b !important;
    border-color:#dce4ef !important;
}

/* Empty table */
html[data-pos-theme="light"] body
#itemsTable tbody tr td[colspan],

html[data-pos-theme="light"] body
#itemsTable .empty-state{
    background:#ffffff !important;
    background-color:#ffffff !important;
    color:#64748b !important;
}

/* =========================================================
   BADGES
========================================================= */

html[data-pos-theme="light"] body .variant-badge{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .no-badge{
    background:#f1f5f9 !important;
    color:#475569 !important;
    border-color:#cbd5e1 !important;
}

/* Pharmacy unit chips */
html[data-pos-theme="light"] body .unit-chip{
    background:#eff6ff !important;
    color:#1e3a8a !important;
    border-color:#bfdbfe !important;
}

html[data-pos-theme="light"] body .unit-chip strong{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .unit-chip small{
    color:#2563eb !important;
}

html[data-pos-theme="light"] body .unit-chip-default{
    background:#f3efff !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body
.unit-chip-default small{
    color:#6d28d9 !important;
}

/* =========================================================
   ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .action-icon{
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}

html[data-pos-theme="light"] body .action-edit{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] body .action-edit:hover{
    background:#f59e0b !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .action-delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body .action-delete:hover{
    background:#ef4444 !important;
    color:#ffffff !important;
}

/* =========================================================
   TABLE FOOTER
========================================================= */

html[data-pos-theme="light"] body .table-footer{
    background:#f8fafc !important;
    color:#64748b !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .entries-select{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.entries-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .page-nav-btn,
html[data-pos-theme="light"] body .page-number-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body .page-nav-btn:hover,
html[data-pos-theme="light"] body .page-number-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body
.page-number-btn.active{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

html[data-pos-theme="light"] body .bulk-delete-btn{
    background:linear-gradient(135deg,#e11d48,#ef4444) !important;
    color:#ffffff !important;
}

/* =========================================================
   ADD / EDIT / IMPORT / BULK MODALS
========================================================= */

html[data-pos-theme="light"] body
#itemModal .item-modal-content,

html[data-pos-theme="light"] body
#importModal .import-modal-content,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-modal-content{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#d8e0eb !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.20),
        0 0 0 1px rgba(124,58,237,.06) !important;
}

/* Modal headers */
html[data-pos-theme="light"] body
#itemModal .item-modal-header,

html[data-pos-theme="light"] body
#importModal .modal-header,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-modal-header{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

/* Modal titles */
html[data-pos-theme="light"] body
#itemModal .modal-title,

html[data-pos-theme="light"] body
#importModal .modal-title,

html[data-pos-theme="light"] body
#bulkEditModal .modal-title{
    color:#0f172a !important;
}

/* Modal bodies */
html[data-pos-theme="light"] body
#itemModal .item-modal-body,

html[data-pos-theme="light"] body
#importModal .modal-body,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Modal footers */
html[data-pos-theme="light"] body
#itemModal .item-modal-footer,

html[data-pos-theme="light"] body
#importModal .modal-footer,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-modal-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

/* Labels */
html[data-pos-theme="light"] body
#itemModal .item-label,

html[data-pos-theme="light"] body
#importModal .form-label,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-label,

html[data-pos-theme="light"] body
#itemModal label,

html[data-pos-theme="light"] body
#importModal label,

html[data-pos-theme="light"] body
#bulkEditModal label{
    color:#334155 !important;
}

/* Inputs */
html[data-pos-theme="light"] body
#itemModal .item-input,

html[data-pos-theme="light"] body
#itemModal .item-textarea,

html[data-pos-theme="light"] body
#importModal .form-control,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-input,

html[data-pos-theme="light"] body
#itemModal input,

html[data-pos-theme="light"] body
#itemModal select,

html[data-pos-theme="light"] body
#itemModal textarea,

html[data-pos-theme="light"] body
#importModal input,

html[data-pos-theme="light"] body
#bulkEditModal input,

html[data-pos-theme="light"] body
#bulkEditModal select,

html[data-pos-theme="light"] body
#bulkEditModal textarea{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

/* Inputs focus */
html[data-pos-theme="light"] body
#itemModal input:focus,

html[data-pos-theme="light"] body
#itemModal select:focus,

html[data-pos-theme="light"] body
#itemModal textarea:focus,

html[data-pos-theme="light"] body
#importModal input:focus,

html[data-pos-theme="light"] body
#bulkEditModal input:focus,

html[data-pos-theme="light"] body
#bulkEditModal select:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

/* Price wrapper */
html[data-pos-theme="light"] body
#itemModal .price-wrap{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#itemModal .price-prefix{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-right-color:#c4b5fd !important;
}

/* Upload section */
html[data-pos-theme="light"] body
#itemModal .upload-box{
    background:#f8fafc !important;
    border-color:#dce4ef !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#itemModal .upload-preview{
    background:#ffffff !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body
#itemModal .upload-preview i{
    color:#7c3aed !important;
}

html[data-pos-theme="light"] body
#itemModal .upload-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#itemModal .upload-subtext,

html[data-pos-theme="light"] body
#itemModal .modal-side-note,

html[data-pos-theme="light"] body
#itemModal .footer-left-note,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-left-note{
    color:#64748b !important;
}

/* Option panel */
html[data-pos-theme="light"] body
#itemModal .option-pills{
    background:#f8fafc !important;
    border-color:#dce4ef !important;
}

html[data-pos-theme="light"] body
#itemModal .option-check{
    color:#334155 !important;
}

/* Bulk note */
html[data-pos-theme="light"] body
#bulkEditModal .bulk-note-box{
    background:#f8fafc !important;
    color:#475569 !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body
#bulkEditModal .bulk-note-box strong{
    color:#5b21b6 !important;
}

/* Pharmacy unit panel */
html[data-pos-theme="light"] body
#itemModal .pharmacy-unit-card{
    background:#f8fafc !important;
    border-color:#c4b5fd !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#itemModal .pharmacy-unit-title{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#itemModal .pharmacy-unit-subtitle{
    color:#64748b !important;
}

html[data-pos-theme="light"] body
#itemModal .pharmacy-unit-table{
    background:#ffffff !important;
    border-color:#dce4ef !important;
}

html[data-pos-theme="light"] body
#itemModal .pharmacy-unit-grid-head{
    background:#f1f5f9 !important;
    color:#334155 !important;
}

html[data-pos-theme="light"] body
#itemModal .pharmacy-unit-grid{
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body
#itemModal .pharmacy-unit-note{
    background:#eff6ff !important;
    color:#1e40af !important;
    border-color:#bfdbfe !important;
}

/* Cancel buttons */
html[data-pos-theme="light"] body
#itemModal .cancel-btn,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-cancel-btn,

html[data-pos-theme="light"] body
#importModal .btn-outline-dark,

html[data-pos-theme="light"] body
#importModal .btn-secondary{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

/* Save buttons */
html[data-pos-theme="light"] body
#itemModal .save-btn,

html[data-pos-theme="light"] body
#bulkEditModal .bulk-save-btn,

html[data-pos-theme="light"] body
#importModal .btn-warning,

html[data-pos-theme="light"] body
#importModal .btn-primary{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
}

/* Close buttons */
html[data-pos-theme="light"] body
#itemModal .btn-close,

html[data-pos-theme="light"] body
#importModal .btn-close,

html[data-pos-theme="light"] body
#bulkEditModal .btn-close{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
#itemModal .btn-close::before,

html[data-pos-theme="light"] body
#itemModal .btn-close::after,

html[data-pos-theme="light"] body
#importModal .btn-close::before,

html[data-pos-theme="light"] body
#importModal .btn-close::after,

html[data-pos-theme="light"] body
#bulkEditModal .btn-close::before,

html[data-pos-theme="light"] body
#bulkEditModal .btn-close::after{
    background:#334155 !important;
}


/* =========================================================
   REFERENCE PHARMACY PRODUCT POPUP
========================================================= */

#itemModal .modal-dialog{
    width:min(1180px, 96vw) !important;
    max-width:min(1180px, 96vw) !important;
}

#itemModal .item-modal-content{
    max-height:calc(100dvh - 28px) !important;
}

#itemModal .item-modal-body{
    max-height:calc(100dvh - 142px) !important;
    overflow-y:auto !important;
}

/* Image column slightly smaller, form area wider */
@media (min-width:993px){

    #itemModal .col-lg-4{
        width:27% !important;
        flex:0 0 27% !important;
        max-width:27% !important;
    }

    #itemModal .col-lg-8{
        width:73% !important;
        flex:0 0 73% !important;
        max-width:73% !important;
    }
}

/* Paste image option */
#itemModal .paste-image-box{
    width:100% !important;
    min-height:58px !important;
    margin-top:10px !important;
    padding:9px 11px !important;

    display:grid !important;
    grid-template-columns:32px minmax(0,1fr) auto !important;
    align-items:center !important;
    gap:9px !important;

    border:1px dashed rgba(124,58,237,.45) !important;
    border-radius:12px !important;

    background:rgba(124,58,237,.07) !important;
    color:#334155 !important;

    cursor:pointer !important;
    outline:none !important;
}

#itemModal .paste-image-box:focus,
#itemModal .paste-image-box:hover{
    border-color:#7c3aed !important;
    background:rgba(124,58,237,.12) !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.10) !important;
}

#itemModal .paste-image-box > i{
    font-size:23px !important;
    color:#7c3aed !important;
}

#itemModal .paste-image-box strong{
    display:block !important;
    color:#0f172a !important;
    font-size:12px !important;
    font-weight:900 !important;
}

#itemModal .paste-image-box small{
    display:block !important;
    margin-top:2px !important;
    color:#64748b !important;
    font-size:10px !important;
    line-height:1.25 !important;
}

#itemModal .paste-image-box kbd{
    padding:4px 7px !important;
    border-radius:6px !important;
    background:#7c3aed !important;
    color:#fff !important;
    font-size:10px !important;
}

/* Single sale checkbox card */
#itemModal .single-sale-toggle-card{
    min-height:72px !important;
    padding:14px !important;

    display:flex !important;
    align-items:center !important;

    border:1px solid #d8e0eb !important;
    border-radius:14px !important;

    background:#f8fafc !important;
}

#itemModal .single-sale-check{
    margin:0 !important;
    color:#0f172a !important;
    font-weight:900 !important;
}

#itemModal .single-sale-check input{
    width:18px !important;
    height:18px !important;
    min-height:18px !important;
    accent-color:#7c3aed !important;
    box-shadow:none !important;
}

/* Conditional unit section */
#itemModal .single-tablet-workflow{
    width:100% !important;
    margin-top:4px !important;
}

#itemModal .pharmacy-unit-card{
    padding:16px !important;
    border:1px solid #c4b5fd !important;
    border-radius:16px !important;
    background:#ffffff !important;
    box-shadow:0 8px 24px rgba(15,23,42,.06) !important;
}

#itemModal .pharmacy-unit-head{
    margin-bottom:14px !important;
}

#itemModal .pharmacy-unit-title{
    color:#0f172a !important;
    font-size:17px !important;
    font-weight:950 !important;
}

#itemModal .pharmacy-unit-title i{
    color:#7c3aed !important;
}

#itemModal .pharmacy-unit-subtitle{
    color:#64748b !important;
    font-size:11px !important;
}

/* Recalculate button */
#itemModal .recalculate-unit-btn{
    height:36px !important;
    padding:0 13px !important;

    border:1px solid #c4b5fd !important;
    border-radius:10px !important;

    background:#ffffff !important;
    color:#6d28d9 !important;

    font-size:12px !important;
    font-weight:900 !important;

    transition:.18s ease !important;
}

#itemModal .recalculate-unit-btn:hover{
    background:#7c3aed !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

/* Top calculation fields */
#itemModal .single-tablet-calculator{
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:14px !important;

    margin-bottom:14px !important;
    padding:13px !important;

    background:#f8fafc !important;
    border:1px solid #e2e8f0 !important;
    border-radius:14px !important;
}

#itemModal .unit-input-suffix-wrap{
    display:flex !important;
    align-items:center !important;
    overflow:hidden !important;

    background:#ffffff !important;
    border:1px solid #cbd5e1 !important;
    border-radius:10px !important;
}

#itemModal .unit-input-suffix-wrap input{
    flex:1 !important;
    min-width:0 !important;
    border:0 !important;
    box-shadow:none !important;
}

#itemModal .unit-input-suffix{
    height:40px !important;
    padding:0 12px !important;

    display:flex !important;
    align-items:center !important;

    background:#f1f5f9 !important;
    color:#64748b !important;

    border-left:1px solid #e2e8f0 !important;

    font-size:11px !important;
    font-weight:800 !important;
}

/* Unit table */
#itemModal .reference-unit-table{
    border:1px solid #e2e8f0 !important;
    border-radius:13px !important;
    overflow:hidden !important;
    background:#ffffff !important;
}

#itemModal .reference-unit-table .pharmacy-unit-grid{
    grid-template-columns:
        100px
        minmax(135px,1fr)
        92px
        125px
        112px
        65px
        78px !important;

    gap:8px !important;
    align-items:center !important;
    padding:9px 10px !important;
}

#itemModal .reference-unit-table .pharmacy-unit-grid-head{
    background:#f1f5f9 !important;
    color:#334155 !important;
    font-size:10px !important;
    font-weight:950 !important;
}

#itemModal .reference-unit-table .product-unit-row{
    background:#ffffff !important;
    border-top:1px solid #e8edf4 !important;
}

#itemModal .reference-unit-table .product-unit-row:hover{
    background:#faf7ff !important;
}

#itemModal .reference-unit-table .item-input{
    width:100% !important;
    min-width:0 !important;
    height:36px !important;
    min-height:36px !important;
    padding:5px 8px !important;

    border:1px solid #cbd5e1 !important;
    border-radius:9px !important;

    background:#ffffff !important;
    color:#0f172a !important;

    font-size:11px !important;
    font-weight:800 !important;
    box-shadow:none !important;
}

#itemModal .unit-description{
    color:#475569 !important;
    font-size:11px !important;
    font-weight:700 !important;
}

#itemModal .unit-price-per-tablet{
    color:#0f172a !important;
    font-size:11px !important;
    font-weight:950 !important;
    white-space:nowrap !important;
}

#itemModal .unit-row-price-wrap{
    display:grid !important;
    grid-template-columns:34px minmax(0,1fr) !important;
    align-items:center !important;

    overflow:hidden !important;
    border:1px solid #cbd5e1 !important;
    border-radius:9px !important;

    background:#ffffff !important;
}

#itemModal .unit-row-price-wrap > span{
    height:34px !important;

    display:flex !important;
    align-items:center !important;
    justify-content:center !important;

    background:#ecfdf5 !important;
    color:#047857 !important;

    border-right:1px solid #d1fae5 !important;

    font-size:10px !important;
    font-weight:950 !important;
}

#itemModal .unit-row-price-wrap input{
    border:0 !important;
    box-shadow:none !important;
}

#itemModal .unit-row-actions{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:5px !important;
}

#itemModal .edit-unit-btn,
#itemModal .remove-unit-btn{
    width:32px !important;
    height:32px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    border-radius:9px !important;
    font-size:12px !important;
}

#itemModal .edit-unit-btn{
    border:1px solid #cbd5e1 !important;
    background:#ffffff !important;
    color:#475569 !important;
}

#itemModal .edit-unit-btn:hover{
    border-color:#7c3aed !important;
    color:#7c3aed !important;
}

#itemModal .remove-unit-btn{
    border:1px solid #fecaca !important;
    background:#fee2e2 !important;
    color:#ef4444 !important;
}

#itemModal .unit-default-radio{
    width:16px !important;
    height:16px !important;
    min-height:16px !important;
    accent-color:#7c3aed !important;
    box-shadow:none !important;
}

/* Calculation note */
#itemModal .pharmacy-unit-note{
    margin-top:12px !important;
    padding:10px 12px !important;

    background:#eff6ff !important;
    border:1px solid #bfdbfe !important;
    border-radius:11px !important;

    color:#1d4ed8 !important;
    font-size:11px !important;
    line-height:1.45 !important;
    font-weight:700 !important;
}

/* Mobile */
@media (max-width:900px){

    #itemModal .single-tablet-calculator{
        grid-template-columns:1fr !important;
    }

    #itemModal .reference-unit-table{
        overflow-x:auto !important;
    }

    #itemModal .reference-unit-table .pharmacy-unit-grid{
        min-width:840px !important;
    }
}


/* =========================================================
   ITEMS TOP SEARCH BAR + COLUMN FILTERS
========================================================= */

.page-header{
    display:grid !important;
    grid-template-columns:
        max-content
        minmax(420px, 1fr)
        max-content !important;
    align-items:center !important;
    gap:14px !important;
    overflow:visible !important;
    margin-bottom:18px !important;
}

.page-header .filter-bar{
    width:100% !important;
    min-width:0 !important;
    margin:0 !important;
    padding:8px !important;
    display:grid !important;
    grid-template-columns:
        auto
        minmax(220px, 1fr)
        auto
        auto
        auto !important;
    align-items:center !important;
    gap:8px !important;
    border-radius:15px !important;
}

.page-header .search-wrap{
    width:100% !important;
    min-width:0 !important;
}

.page-header .search-input{
    width:100% !important;
    min-width:0 !important;
}

.page-header .header-actions{
    width:auto !important;
    flex-wrap:nowrap !important;
}

.column-filter-btn{
    min-width:48px !important;
    height:46px !important;
    padding:0 13px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:13px !important;
    background:rgba(124,58,237,.14) !important;
    color:#c4b5fd !important;
    font-size:13px !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.column-filter-btn:hover,
.column-filter-btn.show{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

.column-filter-menu{
    width:310px !important;
    max-width:calc(100vw - 30px) !important;
    max-height:min(620px, 82vh) !important;
    padding:0 !important;
    overflow:hidden !important;
    border-radius:18px !important;
}

.column-filter-title{
    padding:15px 16px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

.column-filter-title strong{
    display:block !important;
    color:#f8fafc !important;
    font-size:14px !important;
    font-weight:900 !important;
}

.column-filter-title small{
    display:block !important;
    margin-top:2px !important;
    color:#94a3b8 !important;
    font-size:10px !important;
    font-weight:700 !important;
}

.column-filter-title > i{
    color:#a78bfa !important;
    font-size:19px !important;
}

.column-filter-list{
    max-height:430px !important;
    overflow-y:auto !important;
    padding:10px !important;
}

.column-filter-option{
    min-height:42px !important;
    margin:0 0 7px !important;
    padding:8px 11px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    border:1px solid rgba(148,163,184,.15) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.62) !important;
    color:#cbd5e1 !important;
    cursor:pointer !important;
    font-size:12px !important;
    font-weight:800 !important;
}

.column-filter-option:hover{
    background:rgba(124,58,237,.14) !important;
    border-color:rgba(139,92,246,.38) !important;
    color:#ffffff !important;
}

.column-filter-option input{
    width:16px !important;
    height:16px !important;
    min-height:16px !important;
    margin:0 !important;
    padding:0 !important;
    accent-color:#7c3aed !important;
    box-shadow:none !important;
}

.column-filter-footer{
    padding:12px !important;
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:10px !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    background:rgba(7,11,20,.45) !important;
}

.column-reset-btn,
.column-apply-btn{
    height:42px !important;
    border-radius:12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.column-reset-btn{
    border:1px solid rgba(148,163,184,.20) !important;
    background:rgba(148,163,184,.12) !important;
    color:#cbd5e1 !important;
}

.column-apply-btn{
    border:none !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .column-filter-btn{
    background:#ffffff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .column-filter-btn:hover,
html[data-pos-theme="light"] body .column-filter-btn.show{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] body .column-filter-title{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .column-filter-title strong{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .column-filter-title small{
    color:#64748b !important;
}

html[data-pos-theme="light"] body .column-filter-option{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .column-filter-option:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body .column-filter-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body .column-reset-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

@media (max-width:1350px){
    .page-header{
        grid-template-columns:1fr auto !important;
    }

    .page-header .page-title{
        grid-column:1 !important;
    }

    .page-header .header-actions{
        grid-column:2 !important;
    }

    .page-header .filter-bar{
        grid-column:1 / -1 !important;
        grid-row:2 !important;
    }
}

@media (max-width:768px){
    .page-header{
        grid-template-columns:1fr !important;
    }

    .page-header .page-title,
    .page-header .filter-bar,
    .page-header .header-actions{
        grid-column:1 !important;
    }

    .page-header .filter-bar{
        grid-template-columns:1fr 1fr !important;
    }

    .page-header .search-wrap{
        grid-column:1 / -1 !important;
        grid-row:1 !important;
    }

    .page-header .quick-action-btn,
    .page-header .filter-btn,
    .page-header .column-filter-btn,
    .page-header .ghost-btn{
        width:100% !important;
    }

    .page-header .header-actions{
        width:100% !important;
        flex-direction:column !important;
    }

    .column-filter-menu{
        width:min(310px, calc(100vw - 28px)) !important;
    }
}


/* =========================================================
   FINAL PRODUCTS HEADER / FILTER ALIGNMENT FIX
   Title + actions first row
   Filters full width second row
========================================================= */

.page-header{
    width:100% !important;
    margin-bottom:18px !important;

    display:grid !important;
    grid-template-columns:minmax(0, 1fr) auto !important;
    grid-template-areas:
        "pageTitle pageActions"
        "pageFilters pageFilters" !important;

    align-items:center !important;
    gap:14px 16px !important;

    overflow:visible !important;
}

/* Products / Items title */
.page-header .page-title{
    grid-area:pageTitle !important;

    margin:0 !important;
    min-width:0 !important;

    display:flex !important;
    align-items:center !important;

    white-space:nowrap !important;
}

/* Add, Bulk Edit, Import, Export buttons */
.page-header .header-actions{
    grid-area:pageActions !important;

    width:auto !important;
    min-width:0 !important;

    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;

    flex-direction:row !important;
    flex-wrap:wrap !important;

    gap:10px !important;
    margin:0 !important;
}

/* All action buttons same alignment and height */
.page-header .header-actions .btn-soft{
    height:46px !important;
    min-height:46px !important;

    margin:0 !important;
    padding:0 16px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    white-space:nowrap !important;
    line-height:1 !important;
}

/* Filters full-width second row */
.page-header .filter-bar{
    grid-area:pageFilters !important;

    width:100% !important;
    max-width:none !important;
    min-width:0 !important;

    margin:0 !important;
    padding:10px !important;

    display:grid !important;
    grid-template-columns:
        max-content
        minmax(260px, 1fr)
        max-content
        max-content
        52px !important;

    align-items:center !important;
    gap:10px !important;

    overflow:visible !important;
}

/* Filter dropdown wrappers */
.page-header .filter-bar > .dropdown{
    min-width:0 !important;
    margin:0 !important;
}

/* Search field */
.page-header .search-wrap{
    width:100% !important;
    min-width:0 !important;
    margin:0 !important;
}

.page-header .search-input{
    width:100% !important;
    min-width:0 !important;
    height:46px !important;
    margin:0 !important;
}

/* Filter row buttons */
.page-header .quick-action-btn,
.page-header .filter-btn,
.page-header .column-filter-btn,
.page-header .ghost-btn{
    height:46px !important;
    min-height:46px !important;

    margin:0 !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    white-space:nowrap !important;
    line-height:1 !important;
}

/* Three dots fixed size */
.page-header .ghost-btn{
    width:52px !important;
    min-width:52px !important;
    padding:0 !important;
}

/* Medium screens */
@media (max-width:1100px){

    .page-header{
        grid-template-columns:1fr !important;
        grid-template-areas:
            "pageTitle"
            "pageActions"
            "pageFilters" !important;
    }

    .page-header .header-actions{
        width:100% !important;
        justify-content:flex-start !important;
    }

    .page-header .filter-bar{
        grid-template-columns:
            1fr
            1fr !important;
    }

    .page-header .search-wrap{
        grid-column:1 / -1 !important;
        grid-row:auto !important;
    }

    .page-header .quick-action-btn,
    .page-header .filter-btn,
    .page-header .column-filter-btn,
    .page-header .ghost-btn{
        width:100% !important;
    }
}

/* Mobile screens */
@media (max-width:768px){

    .page-header{
        gap:12px !important;
    }

    .page-header .header-actions{
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:8px !important;
    }

    .page-header .header-actions .btn-soft{
        width:100% !important;
    }

    .page-header .filter-bar{
        grid-template-columns:1fr !important;
    }

    .page-header .filter-bar > *{
        width:100% !important;
        min-width:100% !important;
    }

    .page-header .quick-action-btn,
    .page-header .filter-btn,
    .page-header .column-filter-btn,
    .page-header .ghost-btn{
        width:100% !important;
    }
}


/* =========================================================
   FINAL COLUMN FILTER DROPDOWN FIX
========================================================= */

.page-header,
.page-header .filter-bar,
.page-header .filter-bar > .dropdown{
    overflow:visible !important;
}

/* Dropdown ki maximum height browser screen ke mutabiq */
.column-filter-menu{
    width:310px !important;
    max-width:calc(100vw - 24px) !important;

    max-height:calc(100vh - 180px) !important;
    max-height:calc(100dvh - 180px) !important;

    padding:0 !important;
    overflow:hidden !important;
    border-radius:18px !important;
}

/* Sirf open state mein flex layout */
.column-filter-menu.show{
    display:flex !important;
    flex-direction:column !important;
}

/* Header fixed rahe */
.column-filter-title{
    flex:0 0 auto !important;
}

/* Sirf columns list scroll hogi */
.column-filter-list{
    flex:1 1 auto !important;
    min-height:0 !important;

    max-height:none !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;

    overscroll-behavior:contain !important;
}

/* Reset / Apply buttons hamesha visible rahenge */
.column-filter-footer{
    flex:0 0 auto !important;

    position:sticky !important;
    bottom:0 !important;
    z-index:20 !important;

    width:100% !important;
    margin-top:auto !important;

    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:10px !important;

    padding:12px !important;

    background:#0f172a !important;
    border-top:1px solid rgba(148,163,184,.20) !important;

    box-shadow:0 -12px 28px rgba(0,0,0,.22) !important;
}

.column-reset-btn,
.column-apply-btn{
    width:100% !important;
    height:42px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    cursor:pointer !important;
    pointer-events:auto !important;
}

/* Light theme footer */
html[data-pos-theme="light"] body .column-filter-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
    box-shadow:0 -10px 24px rgba(15,23,42,.08) !important;
}


/* =========================================================
   FINAL COLLAPSED SIDEBAR ICON FIX
========================================================= */

/* Dono possible collapse classes ko support karo */
body.dashboard-sidebar-collapsed,
body.sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width) !important;
}

/* Sidebar menu ko collapsed state mein hide na hone do */
body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-menu,
body.sidebar-collapsed #dashboardSidebar .sidebar-menu{
    display:block !important;
    visibility:visible !important;
    opacity:1 !important;
}

/* Menu sections visible */
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-section,
body.sidebar-collapsed #dashboardSidebar .menu-section{
    display:block !important;
    visibility:visible !important;
    opacity:1 !important;
}

/* Collapsed link alignment */
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link,
body.sidebar-collapsed #dashboardSidebar .menu-link{
    width:100% !important;
    min-height:44px !important;

    display:flex !important;
    align-items:center !important;
    justify-content:center !important;

    padding:10px 0 !important;
}

/* Icon container center */
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main,
body.sidebar-collapsed #dashboardSidebar .menu-main{
    width:100% !important;

    display:flex !important;
    align-items:center !important;
    justify-content:center !important;

    gap:0 !important;
}

/* Actual sidebar icons visible */
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon,
body.sidebar-collapsed #dashboardSidebar .menu-icon{
    width:22px !important;
    min-width:22px !important;
    height:22px !important;

    margin:0 !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    visibility:visible !important;
    opacity:1 !important;

    color:currentColor !important;
    font-size:18px !important;
    line-height:1 !important;
}

/* Bootstrap icon pseudo-element visible */
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon::before,
body.sidebar-collapsed #dashboardSidebar .menu-icon::before{
    display:block !important;
    visibility:visible !important;
    opacity:1 !important;
}

/* Text aur arrows collapsed state mein hide */
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow,
body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse,
body.sidebar-collapsed #dashboardSidebar .menu-title,
body.sidebar-collapsed #dashboardSidebar .menu-arrow,
body.sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}



/* =========================================================
   INLINE PRODUCT IMAGE URL EDITOR
========================================================= */

.item-image-cell{
    min-width:110px !important;
    transition:min-width .2s ease !important;
}

.item-image-display{
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:center !important;
    gap:5px !important;
}

.inline-image-trigger{
    position:relative !important;
    cursor:pointer !important;
    transition:
        transform .18s ease,
        border-color .18s ease,
        box-shadow .18s ease !important;
}

.inline-image-trigger:hover{
    transform:scale(1.06) !important;
    border-color:#8b5cf6 !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.15) !important;
}

.image-edit-hint{
    display:block !important;
    color:#94a3b8 !important;
    font-size:8px !important;
    line-height:1 !important;
    font-weight:900 !important;
    letter-spacing:.04em !important;
    white-space:nowrap !important;
}

.item-image-cell.is-editing{
    min-width:300px !important;
    width:300px !important;
}

.inline-image-editor{
    width:270px !important;
    display:flex !important;
    flex-direction:column !important;
    gap:8px !important;
    padding:10px !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:14px !important;
    background:#0f172a !important;
    box-shadow:
        0 16px 35px rgba(0,0,0,.35),
        0 0 0 3px rgba(124,58,237,.08) !important;
}

.inline-image-editor textarea{
    width:100% !important;
    min-height:76px !important;
    padding:9px 10px !important;
    resize:vertical !important;
    border:1px solid rgba(148,163,184,.28) !important;
    border-radius:10px !important;
    background:#111827 !important;
    color:#ffffff !important;
    font-size:12px !important;
    line-height:1.4 !important;
    outline:none !important;
}

.inline-image-editor textarea:focus{
    border-color:#8b5cf6 !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.16) !important;
}

.inline-image-editor textarea::placeholder{
    color:#94a3b8 !important;
}

.inline-image-editor-actions{
    display:flex !important;
    align-items:center !important;
    gap:7px !important;
}

.inline-image-save,
.inline-image-cancel{
    min-height:34px !important;
    padding:0 12px !important;
    border:none !important;
    border-radius:9px !important;
    font-size:11px !important;
    font-weight:900 !important;
    cursor:pointer !important;
}

.inline-image-save{
    background:linear-gradient(135deg,#3b82f6,#7c3aed) !important;
    color:#ffffff !important;
}

.inline-image-cancel{
    background:rgba(148,163,184,.18) !important;
    color:#e2e8f0 !important;
    border:1px solid rgba(148,163,184,.20) !important;
}

.inline-image-save:disabled{
    opacity:.60 !important;
    cursor:not-allowed !important;
}

/* Light mode */
html[data-pos-theme="light"] body .inline-image-editor{
    background:#ffffff !important;
    border-color:#c4b5fd !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body
.inline-image-editor textarea{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.inline-image-cancel{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] body
.image-edit-hint{
    color:#64748b !important;
}


/* =========================================================
   CLICK PRODUCT NAME TO COPY
========================================================= */

.copy-item-name{
    width:100% !important;
    min-width:0 !important;
    padding:7px 9px !important;

    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:10px !important;

    border:1px solid transparent !important;
    border-radius:10px !important;

    background:transparent !important;
    color:inherit !important;

    font:inherit !important;
    font-weight:800 !important;
    text-align:left !important;

    cursor:pointer !important;
    transition:
        background .18s ease,
        border-color .18s ease,
        color .18s ease,
        box-shadow .18s ease !important;
}

.copy-item-name-text{
    min-width:0 !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
}

.copy-item-name-icon{
    flex-shrink:0 !important;
    color:#8b5cf6 !important;
    font-size:14px !important;
    opacity:.72 !important;
    transition:.18s ease !important;
}

.copy-item-name:hover{
    background:rgba(124,58,237,.10) !important;
    border-color:rgba(139,92,246,.28) !important;
    color:inherit !important;
}

.copy-item-name:hover .copy-item-name-icon{
    opacity:1 !important;
    transform:scale(1.08) !important;
}

.copy-item-name:focus{
    outline:none !important;
    border-color:#8b5cf6 !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

/* Copy successful */
.copy-item-name.is-copied{
    background:rgba(34,197,94,.12) !important;
    border-color:rgba(34,197,94,.32) !important;
}

.copy-item-name.is-copied .copy-item-name-icon{
    color:#22c55e !important;
    opacity:1 !important;
}

/* Copy notification */
.item-copy-toast{
    position:fixed !important;
    right:24px !important;
    bottom:24px !important;
    z-index:999999 !important;

    max-width:360px !important;
    padding:12px 16px !important;

    display:flex !important;
    align-items:center !important;
    gap:9px !important;

    background:#0f172a !important;
    color:#ffffff !important;

    border:1px solid rgba(139,92,246,.40) !important;
    border-radius:12px !important;

    font-size:13px !important;
    font-weight:800 !important;

    box-shadow:0 18px 45px rgba(0,0,0,.35) !important;

    opacity:0 !important;
    visibility:hidden !important;
    transform:translateY(12px) !important;

    transition:
        opacity .20s ease,
        visibility .20s ease,
        transform .20s ease !important;
}

.item-copy-toast.show{
    opacity:1 !important;
    visibility:visible !important;
    transform:translateY(0) !important;
}

.item-copy-toast i{
    color:#4ade80 !important;
    font-size:17px !important;
}

/* Light mode */
html[data-pos-theme="light"] body .copy-item-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .copy-item-name:hover{
    background:#f3efff !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] body .copy-item-name.is-copied{
    background:#ecfdf5 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] body .item-copy-toast{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#c4b5fd !important;
    box-shadow:0 16px 40px rgba(15,23,42,.18) !important;
}



/* =========================================================
   PRODUCT NAME COPY ICON SPACING FIX
========================================================= */

/* Product name column ka extra width control */
#itemsTable th[data-column-key="name"],
#itemsTable td[data-column-key="name"]{
    width:330px !important;
    min-width:330px !important;
    max-width:330px !important;
}

/* Name aur clipboard icon ek saath */
.copy-item-name{
    width:auto !important;
    max-width:100% !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:flex-start !important;

    gap:6px !important;
    padding:5px 7px !important;
    margin:0 !important;
}

/* Name ko available space mein rakho */
.copy-item-name-text{
    display:block !important;
    min-width:0 !important;
    max-width:285px !important;

    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
}

/* Clipboard bilkul name ke paas */
.copy-item-name-icon{
    flex:0 0 auto !important;
    margin:0 !important;
    position:static !important;
    transform:none !important;
}

/* Hover par icon move na ho */
.copy-item-name:hover .copy-item-name-icon{
    transform:none !important;
}


/* =========================================================
   PRODUCT ACTIVE / INACTIVE STATUS
========================================================= */

#itemsTable .item-status-cell{
    min-width:130px !important;
    white-space:nowrap !important;
}

.item-status-select{
    width:112px !important;
    height:38px !important;
    min-height:38px !important;

    padding:0 30px 0 12px !important;

    border-radius:11px !important;
    outline:none !important;

    font-size:12px !important;
    font-weight:900 !important;

    cursor:pointer !important;

    transition:
        border-color .18s ease,
        background .18s ease,
        color .18s ease,
        box-shadow .18s ease !important;
}

.item-status-select.is-active{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.38) !important;
}

.item-status-select.is-inactive{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.38) !important;
}

.item-status-select:focus{
    border-color:#8b5cf6 !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.16) !important;
}

.item-status-select:disabled{
    opacity:.60 !important;
    cursor:not-allowed !important;
}

/* Options dropdown */
.item-status-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Inactive product row slightly faded */
#itemsTable tbody tr.item-row-inactive td{
    opacity:.76;
}

/* Dropdown ko full opacity rakho */
#itemsTable tbody tr.item-row-inactive
.item-status-cell{
    opacity:1 !important;
}

/* Light mode */
html[data-pos-theme="light"] body
.item-status-select.is-active{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#86efac !important;
}

html[data-pos-theme="light"] body
.item-status-select.is-inactive{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body
.item-status-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}


/* =====================================================
   DUPLICATE ITEMS
===================================================== */

#itemsTable tbody tr.item-row-duplicate-copy td{
    background:rgba(239,68,68,.16) !important;
    background-color:rgba(239,68,68,.16) !important;
    border-top:1px solid rgba(248,113,113,.38) !important;
    border-bottom:1px solid rgba(248,113,113,.18) !important;
    color:#ffffff !important;
}

#itemsTable tbody tr.item-row-duplicate-copy td:first-child{
    box-shadow:inset 5px 0 0 #ef4444 !important;
}

#itemsTable tbody tr.item-row-duplicate-copy:hover td,
#itemsTable tbody tr.item-row-duplicate-copy td:hover,
#itemsTable tbody tr.item-row-duplicate-copy:focus-within td{
    background:rgba(239,68,68,.24) !important;
    background-color:rgba(239,68,68,.24) !important;
    color:#ffffff !important;
}

#itemsTable tbody tr.item-row-duplicate-master td{
    background:rgba(34,197,94,.08) !important;
    background-color:rgba(34,197,94,.08) !important;
}

#itemsTable tbody tr.item-row-duplicate-master td:first-child{
    box-shadow:inset 5px 0 0 #22c55e !important;
}

#itemsTable tbody tr.item-row-duplicate-master:hover td,
#itemsTable tbody tr.item-row-duplicate-master td:hover,
#itemsTable tbody tr.item-row-duplicate-master:focus-within td{
    background:rgba(34,197,94,.14) !important;
    background-color:rgba(34,197,94,.14) !important;
}

.duplicate-item-information{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:6px;
    margin-top:6px;
    padding-left:7px;
}

.duplicate-item-information small{
    color:#fca5a5 !important;
    font-size:10px !important;
    font-weight:800 !important;
}

.duplicate-copy-badge,
.duplicate-master-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:4px 8px;
    border-radius:999px;
    font-size:10px;
    font-weight:900;
    line-height:1;
    white-space:nowrap;
}

.duplicate-copy-badge{
    background:rgba(239,68,68,.22);
    color:#fecaca;
    border:1px solid rgba(248,113,113,.42);
}

.duplicate-master-badge{
    background:rgba(34,197,94,.18);
    color:#86efac;
    border:1px solid rgba(74,222,128,.35);
}

/* Add/Edit item form message */
#itemModal .item-form-message{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:14px;
    padding:12px 14px;
    border-radius:12px;
    background:rgba(239,68,68,.16);
    border:1px solid rgba(248,113,113,.38);
    color:#fecaca;
    font-size:13px;
    font-weight:800;
    line-height:1.5;
}

#itemModal .item-form-message[hidden]{
    display:none !important;
}

#itemModal .item-form-message::before{
    content:"\F33A";
    font-family:"bootstrap-icons";
    color:#f87171;
    font-size:18px;
    flex-shrink:0;
}

#itemModal .item-form-message span{
    min-width:0;
    flex:1;
}

#itemModal .item-form-message a{
    margin-left:auto;
    color:#ffffff;
    background:rgba(239,68,68,.28);
    border:1px solid rgba(248,113,113,.35);
    border-radius:8px;
    padding:5px 9px;
    text-decoration:none;
    white-space:nowrap;
}

#itemModal .item-input-duplicate-error{
    border-color:#ef4444 !important;
    box-shadow:
        0 0 0 4px rgba(239,68,68,.18) !important;
}

/* Light mode duplicate rows */
html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row-duplicate-copy td{
    background:#fff1f2 !important;
    background-color:#fff1f2 !important;
    color:#881337 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row-duplicate-copy:hover td,
html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row-duplicate-copy td:hover,
html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row-duplicate-copy:focus-within td{
    background:#ffe4e6 !important;
    background-color:#ffe4e6 !important;
    color:#881337 !important;
}

html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row-duplicate-master td{
    background:#f0fdf4 !important;
    background-color:#f0fdf4 !important;
    color:#14532d !important;
}

html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row-duplicate-master:hover td,
html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row-duplicate-master td:hover,
html[data-pos-theme="light"] body
#itemsTable tbody tr.item-row-duplicate-master:focus-within td{
    background:#dcfce7 !important;
    background-color:#dcfce7 !important;
    color:#14532d !important;
}

html[data-pos-theme="light"] body
.duplicate-item-information small{
    color:#be123c !important;
}

html[data-pos-theme="light"] body
.duplicate-copy-badge{
    background:#ffe4e6;
    color:#be123c;
    border-color:#fda4af;
}

html[data-pos-theme="light"] body
.duplicate-master-badge{
    background:#dcfce7;
    color:#15803d;
    border-color:#86efac;
}

html[data-pos-theme="light"] body
#itemModal .item-form-message{
    background:#fff1f2;
    border-color:#fda4af;
    color:#9f1239;
}

html[data-pos-theme="light"] body
#itemModal .item-form-message a{
    background:#e11d48;
    border-color:#e11d48;
    color:#ffffff;
}

@media (max-width:768px){
    #itemModal .item-form-message{
        align-items:flex-start;
        flex-wrap:wrap;
    }

    #itemModal .item-form-message a{
        width:100%;
        margin-left:0;
        text-align:center;
    }
}



/* =========================================================
   FINAL DUPLICATE FILTER + DUPLICATE ROW VISIBILITY FIX
========================================================= */

/* Desktop filter row now includes visible Duplicates button */
.page-header .filter-bar{
    grid-template-columns:
        max-content
        minmax(260px, 1fr)
        max-content
        max-content
        max-content
        52px !important;
}

.duplicate-filter-btn{
    height:46px !important;
    min-height:46px !important;
    padding:0 15px !important;

    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;

    border:1px solid rgba(239,68,68,.34) !important;
    border-radius:13px !important;

    background:rgba(239,68,68,.12) !important;
    color:#fca5a5 !important;

    font-size:13px !important;
    font-weight:900 !important;
    line-height:1 !important;
    white-space:nowrap !important;

    cursor:pointer !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.2s ease !important;
}

.duplicate-filter-btn:hover,
.duplicate-filter-btn.is-active{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
    border-color:#ef4444 !important;
    color:#ffffff !important;
    transform:translateY(-1px) !important;
}

html[data-pos-theme="light"] body
.duplicate-filter-btn{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fda4af !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
.duplicate-filter-btn:hover,
html[data-pos-theme="light"] body
.duplicate-filter-btn.is-active{
    background:linear-gradient(135deg,#e11d48,#ef4444) !important;
    color:#ffffff !important;
    border-color:#e11d48 !important;
}

/*
|--------------------------------------------------------------------------
| Duplicate copy: every column gets red lines and red text
|--------------------------------------------------------------------------
*/
#itemsTable tbody
tr.item-row-duplicate-copy > td{
    background:rgba(127,29,29,.22) !important;
    background-color:rgba(127,29,29,.22) !important;

    color:#f87171 !important;

    border-top:2px solid rgba(239,68,68,.82) !important;
    border-bottom:2px solid rgba(239,68,68,.82) !important;
    border-right:1px solid rgba(239,68,68,.24) !important;

    opacity:1 !important;
}

#itemsTable tbody
tr.item-row-duplicate-copy > td:first-child{
    border-left:5px solid #ef4444 !important;
    box-shadow:none !important;
}

#itemsTable tbody
tr.item-row-duplicate-copy > td:last-child{
    border-right:2px solid rgba(239,68,68,.82) !important;
}

#itemsTable tbody
tr.item-row-duplicate-copy
.copy-item-name,

#itemsTable tbody
tr.item-row-duplicate-copy
.copy-item-name-text,

#itemsTable tbody
tr.item-row-duplicate-copy
.item-category,

#itemsTable tbody
tr.item-row-duplicate-copy
.item-supplier,

#itemsTable tbody
tr.item-row-duplicate-copy
.item-price,

#itemsTable tbody
tr.item-row-duplicate-copy
.item-cost,

#itemsTable tbody
tr.item-row-duplicate-copy
.item-discount,

#itemsTable tbody
tr.item-row-duplicate-copy
[data-column-key="id"]{
    color:#f87171 !important;
    font-weight:950 !important;
}

#itemsTable tbody
tr.item-row-duplicate-copy
.copy-item-name-icon{
    color:#ef4444 !important;
    opacity:1 !important;
}

#itemsTable tbody
tr.item-row-duplicate-copy:hover > td,
#itemsTable tbody
tr.item-row-duplicate-copy:focus-within > td{
    background:rgba(153,27,27,.30) !important;
    background-color:rgba(153,27,27,.30) !important;
    color:#fecaca !important;
}

/* Light mode: clear red row, red separators and red writing */
html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy > td{
    background:#fff1f2 !important;
    background-color:#fff1f2 !important;

    color:#b91c1c !important;

    border-top:2px solid #ef4444 !important;
    border-bottom:2px solid #ef4444 !important;
    border-right:1px solid #fecaca !important;

    opacity:1 !important;
}

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy > td:first-child{
    border-left:5px solid #dc2626 !important;
}

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy
.copy-item-name,

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy
.copy-item-name-text,

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy
.item-category,

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy
.item-supplier,

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy
.item-price,

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy
.item-cost,

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy
.item-discount,

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy
[data-column-key="id"]{
    color:#b91c1c !important;
    font-weight:950 !important;
}

html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy:hover > td,
html[data-pos-theme="light"] body
#itemsTable tbody
tr.item-row-duplicate-copy:focus-within > td{
    background:#ffe4e6 !important;
    background-color:#ffe4e6 !important;
    color:#991b1b !important;
}

@media (max-width:1100px){
    .page-header .filter-bar{
        grid-template-columns:1fr 1fr !important;
    }

    .duplicate-filter-btn{
        width:100% !important;
    }
}

@media (max-width:768px){
    .page-header .filter-bar{
        grid-template-columns:1fr !important;
    }

    .duplicate-filter-btn{
        width:100% !important;
    }
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
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title">Products / Items</h1>

                <div class="filter-bar">
                    <div class="dropdown">
                        <button class="quick-action-btn dropdown-toggle" data-bs-toggle="dropdown" type="button">
                            <i class="bi bi-lightning-charge-fill me-1"></i> Quick Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="selectAllRows(); return false;">Select All</a></li>
                            <li><a class="dropdown-item" href="#" onclick="unselectAllRows(); return false;">Unselect All</a></li>
                            <li>
                                <a
                                    class="dropdown-item text-danger"
                                    href="#"
                                    onclick="selectDuplicateCopies(); return false;"
                                >
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    Show & Select Duplicate Copies
                                </a>
                            </li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteSelected(); return false;">Delete Selected</a></li>
                        </ul>
                    </div>

                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                     <input
    type="text"
    id="searchInput"
    class="search-input"
    placeholder="Search products..."
    value="<?php echo e($search); ?>"
>
                    </div>

                    <div class="dropdown">
                        <button class="filter-btn dropdown-toggle" data-bs-toggle="dropdown" type="button">
                            <i class="bi bi-funnel me-1"></i> Filter
                        </button>
                        <ul class="dropdown-menu p-3" style="min-width:200px;">
                            <li>
                          <select
    class="form-select"
    onchange="filterStock(this.value)"
>
    <option value="">
        All Stock
    </option>

    <option
        value="low"
        <?php echo $stockFilter === 'low' ? 'selected' : ''; ?>
    >
        Low Stock
    </option>

    <option
        value="out"
        <?php echo $stockFilter === 'out' ? 'selected' : ''; ?>
    >
        Out of Stock
    </option>
</select>
                            </li>
                            <li class="mt-2">
                                <select
                                    class="form-select"
                                    onchange="filterDuplicates(this.value)"
                                >
                                    <option value="">
                                        All Products
                                    </option>

                                    <option
                                        value="1"
                                        <?php echo $duplicateFilter === '1'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Duplicate Items Only
                                    </option>
                                </select>
                            </li>
                        </ul>
                    </div>

                    <button
                        type="button"
                        class="duplicate-filter-btn <?php echo $duplicateFilter === '1'
                            ? 'is-active'
                            : ''; ?>"
                        onclick="filterDuplicates('<?php echo $duplicateFilter === '1'
                            ? ''
                            : '1'; ?>')"
                        title="<?php echo $duplicateFilter === '1'
                            ? 'Show all products'
                            : 'Show duplicate item groups'; ?>"
                    >
                        <i class="bi bi-files"></i>
                        <span>
                            <?php echo $duplicateFilter === '1'
                                ? 'Showing Duplicates'
                                : 'Duplicates'; ?>
                        </span>
                    </button>

                    <div class="dropdown">
                        <button
                            type="button"
                            id="columnFilterButton"
                            class="column-filter-btn dropdown-toggle"
                            data-bs-toggle="dropdown"
                            data-bs-auto-close="outside"
                            title="Column Filters"
                        >
                            <i class="bi bi-layout-three-columns"></i>
                            <span>Columns</span>
                        </button>

                        <div
                            class="dropdown-menu dropdown-menu-end column-filter-menu"
                            id="columnFilterMenu"
                        >
                            <div class="column-filter-title">
                                <div>
                                    <strong>Column Filters</strong>
                                    <small>Select columns to display</small>
                                </div>
                                <i class="bi bi-layout-three-columns"></i>
                            </div>

                            <div class="column-filter-list">
                                <label class="column-filter-option">
                                    <input type="checkbox" class="item-column-checkbox" value="id" checked>
                                    <span>ID</span>
                                </label>

                                <label class="column-filter-option">
                                    <input type="checkbox" class="item-column-checkbox" value="image" checked>
                                    <span>Image</span>
                                </label>

                                <label class="column-filter-option">
                                    <input type="checkbox" class="item-column-checkbox" value="name" checked>
                                    <span>Product Name</span>
                                </label>

                                <label class="column-filter-option">
                                    <input type="checkbox" class="item-column-checkbox" value="category" checked>
                                    <span>Category</span>
                                </label>

                                <?php if (!$isRestaurantClient): ?>
                                    <label class="column-filter-option">
                                        <input type="checkbox" class="item-column-checkbox" value="supplier" checked>
                                        <span>Supplier</span>
                                    </label>
                                <?php endif; ?>

                                <label class="column-filter-option">
                                    <input type="checkbox" class="item-column-checkbox" value="price" checked>
                                    <span>Price</span>
                                </label>

                                <?php if ($isPharmacyRetailClient): ?>
                                    <label class="column-filter-option">
                                        <input type="checkbox" class="item-column-checkbox" value="units" checked>
                                        <span>Units</span>
                                    </label>
                                <?php endif; ?>

                                <?php if (!$isRestaurantClient): ?>
                                    <label class="column-filter-option">
                                        <input type="checkbox" class="item-column-checkbox" value="cost" checked>
                                        <span>Purchase Cost</span>
                                    </label>
                                <?php endif; ?>

                                <label class="column-filter-option">
                                    <input type="checkbox" class="item-column-checkbox" value="discount" checked>
                                    <span>Max Discount</span>
                                </label>

                                <?php if ($wholesaleEnabled): ?>
                                    <label class="column-filter-option">
                                        <input type="checkbox" class="item-column-checkbox" value="wholesale1" checked>
                                        <span>Wholesale 1</span>
                                    </label>

                                    <label class="column-filter-option">
                                        <input type="checkbox" class="item-column-checkbox" value="wholesale2" checked>
                                        <span>Wholesale 2</span>
                                    </label>

                                    <label class="column-filter-option">
                                        <input type="checkbox" class="item-column-checkbox" value="wholesale3" checked>
                                        <span>Wholesale 3</span>
                                    </label>
                                <?php endif; ?>

                                <label class="column-filter-option">
                                    <input type="checkbox" class="item-column-checkbox" value="variant" checked>
                                    <span>Variant</span>
                                </label>

                               <label class="column-filter-option">
    <input
        type="checkbox"
        class="item-column-checkbox"
        value="expiry"
        checked
    >
    <span>Expire</span>
</label>

<label class="column-filter-option">
    <input
        type="checkbox"
        class="item-column-checkbox"
        value="status"
        checked
    >
    <span>Status</span>
</label>

<label class="column-filter-option">
    <input
        type="checkbox"
        class="item-column-checkbox"
        value="actions"
        checked
    >
    <span>Actions</span>
</label>
                            </div>

                            <div class="column-filter-footer">
                                <button type="button" class="column-reset-btn" onclick="resetItemsColumnFilters()">
                                    Reset
                                </button>
                                <button type="button" class="column-apply-btn" onclick="applyItemsColumnFilters()">
                                    Apply
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="dropdown">
                        <button class="ghost-btn dropdown-toggle" data-bs-toggle="dropdown" type="button">
                            <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" onclick="refreshPage(); return false;">Refresh</a></li>
                            <li><a class="dropdown-item" href="#" onclick="window.print(); return false;">Print</a></li>
                        </ul>
                    </div>
                </div>

                <div class="header-actions">
                    <button class="btn-soft btn-add" type="button" onclick="openItemModal(false)">
                        <i class="bi bi-plus-lg"></i> Add New Product
                    </button>

                    <button class="btn-soft btn-bulk" type="button" onclick="openBulkEditModal()">
                        Bulk Edit
                    </button>

                    <button class="btn-soft btn-import" type="button" onclick="openImportModal()">
                        <i class="bi bi-upload"></i> Import
                    </button>

                    <button class="btn-soft btn-export" type="button" onclick="exportItems()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div class="title"><i class="bi bi-box-seam me-2"></i> Total Products</div>
                    <div class="count"><?php echo $total_products; ?></div>
                </div>

                <div class="stat-card card-yellow">
                    <div class="title"><i class="bi bi-exclamation-triangle me-2"></i> Low Stock</div>
                    <div class="count"><?php echo $low_stock; ?></div>
                </div>

                <div class="stat-card card-red">
                    <div class="title"><i class="bi bi-x-circle me-2"></i> Out of Stock</div>
                    <div class="count"><?php echo $out_of_stock; ?></div>
                </div>

                <div class="stat-card card-green">
                    <div class="title"><i class="bi bi-check2-square me-2"></i> Track Inventory</div>
                    <div class="count"><?php echo $tracked_inventory; ?></div>
                </div>
            </div>

            <form id="bulkForm">
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="table align-middle" id="itemsTable">
                            <thead>
                                <tr>
                                    <th style="width:50px;">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th data-column-key="id">ID</th>
                                    <th data-column-key="image">Image</th>
                                    <th data-column-key="name">Product Name</th>
                                    <th data-column-key="category">Category</th>
                                    <?php if (!$isRestaurantClient): ?>
                                        <th data-column-key="supplier">Supplier</th>
                                    <?php endif; ?>

                                    <th data-column-key="price">Price</th>

                                    <?php if ($isPharmacyRetailClient): ?>
                                        <th data-column-key="units">Units</th>
                                    <?php endif; ?>

                                    <?php if (!$isRestaurantClient): ?>
                                        <th data-column-key="cost">Purchase Cost</th>
                                    <?php endif; ?>

                                    <th data-column-key="discount">Max Discount</th>

                                    <?php if ($wholesaleEnabled): ?>
                                        <th data-column-key="wholesale1">Wholesale1</th>
                                        <th data-column-key="wholesale2">Wholesale2</th>
                                        <th data-column-key="wholesale3">Wholesale3</th>
                                    <?php endif; ?>

                              <th data-column-key="variant">Variant</th>
<th data-column-key="expiry">Expire</th>
<th data-column-key="status">Status</th>
<th data-column-key="actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="itemsTableBody">
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="<?php echo (int)$tableColspan; ?>">
                                        <div class="empty-state">No items found</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                     <?php foreach ($items as $item): ?>
    <?php
        $imageValue = trim((string)($item['image'] ?? ''));
        $isImageUrl = preg_match('/^https?:\/\//i', $imageValue);

        $imagePath =
            $_SERVER['DOCUMENT_ROOT'] .
            '/uploads/items/' .
            $imageValue;

        $hasImage =
            $imageValue !== '' &&
            (
                $isImageUrl ||
                file_exists($imagePath)
            );

        $imageSrc = $isImageUrl
            ? $imageValue
            : '/uploads/items/' . $imageValue;

        $stockQty = isset($item['stock_qty'])
            ? (float)$item['stock_qty']
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Product status
        |--------------------------------------------------------------------------
        */
        $itemStatus = strtolower(
            trim(
                (string)($item['status'] ?? 'active')
            )
        );

        if (!in_array(
            $itemStatus,
            ['active', 'inactive'],
            true
        )) {
            $itemStatus = 'active';
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate row information
        |--------------------------------------------------------------------------
        */
        $isDuplicate =
            (int)($item['is_duplicate'] ?? 0) === 1;

        $duplicateMasterId =
            (int)($item['duplicate_master_id'] ?? $item['id']);

        $duplicateReason =
            trim(
                (string)($item['duplicate_reason'] ?? '')
            );

        $isDuplicateCopy =
            $isDuplicate &&
            (int)$item['id'] !== $duplicateMasterId;

        $isDuplicateMaster =
            $isDuplicate &&
            (int)$item['id'] === $duplicateMasterId;
    ?>

    <tr
        class="item-row
            <?php echo $itemStatus === 'inactive'
                ? 'item-row-inactive'
                : ''; ?>

            <?php echo $isDuplicateCopy
                ? 'item-row-duplicate-copy'
                : ''; ?>

            <?php echo $isDuplicateMaster
                ? 'item-row-duplicate-master'
                : ''; ?>
        "

        data-duplicate="<?php echo $isDuplicate
            ? '1'
            : '0'; ?>"

        data-duplicate-copy="<?php echo $isDuplicateCopy
            ? '1'
            : '0'; ?>"

        data-duplicate-master="<?php echo $isDuplicateMaster
            ? '1'
            : '0'; ?>"

        data-duplicate-group="<?php echo $duplicateMasterId; ?>"
    >
                                        <td>
                                            <input type="checkbox" name="item_ids[]" value="<?php echo (int)$item['id']; ?>" class="row-check">
                                        </td>
                                        <td data-column-key="id"><?php echo (int)$item['id']; ?></td>
<td data-column-key="image" class="item-image-cell">

    <div class="item-image-display">

        <div
            class="thumb-box inline-image-trigger"
            data-item-id="<?php echo (int)$item['id']; ?>"
            data-current-image="<?php echo htmlspecialchars(
                $imageValue,
                ENT_QUOTES,
                'UTF-8'
            ); ?>"
            title="Double click to edit image URL"
        >
            <?php if ($hasImage): ?>

                <img
                    src="<?php echo htmlspecialchars(
                        $imageSrc,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                    alt="item"
                    loading="lazy"
                    onerror="
                        this.parentElement.innerHTML =
                        '<i class=&quot;bi bi-image muted-text&quot;></i>';
                    "
                >

            <?php else: ?>

                <i class="bi bi-image muted-text"></i>

            <?php endif; ?>
        </div>

        <small class="image-edit-hint">
            DOUBLE CLICK EDIT
        </small>

    </div>

</td>
                                       <td class="item-name" data-column-key="name">

    <button
        type="button"
        class="copy-item-name"
        data-copy-text="<?php echo htmlspecialchars(
            $item['name'],
            ENT_QUOTES,
            'UTF-8'
        ); ?>"
        title="Click to copy item name"
    >
        <span class="copy-item-name-text">
            <?php echo htmlspecialchars(
                $item['name'],
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </span>

        <i class="bi bi-clipboard copy-item-name-icon"></i>
    </button>

    <?php if ($isDuplicate): ?>

        <div class="duplicate-item-information">

            <?php if ($isDuplicateCopy): ?>

                <span class="duplicate-copy-badge">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Duplicate Item
                </span>

            <?php else: ?>

                <span class="duplicate-master-badge">
                    <i class="bi bi-shield-check"></i>
                    Keep Original
                </span>

            <?php endif; ?>

            <small>
                Match by:
                <?php echo htmlspecialchars(
                    $duplicateReason !== ''
                        ? $duplicateReason
                        : 'Product',
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>

                · Group #<?php echo $duplicateMasterId; ?>
            </small>

        </div>

    <?php endif; ?>

</td>

<td class="item-category" data-column-key="category">
    <?php
        $itemCategory = trim((string)($item['category'] ?? ''));
        echo $itemCategory !== ''
            ? htmlspecialchars($itemCategory)
            : '<span class="muted-text">Uncategorized</span>';
    ?>
</td>

<?php if (!$isRestaurantClient): ?>
    <td class="item-supplier" data-column-key="supplier">
        <?php
            echo !empty($item['supplier_name'])
                ? htmlspecialchars($item['supplier_name'])
                : '<span class="muted-text">No Supplier</span>';
        ?>
    </td>
<?php endif; ?>

<td class="item-price" data-column-key="price">Rs <?php echo number_format((float)$item['price'], 2); ?></td>

<?php if ($isPharmacyRetailClient): ?>
    <td class="item-units" data-column-key="units">
<?php
    $useProductUnits =
        (int)($item['allow_single_unit_sale'] ?? 0) === 1;

    $unitsForThisItem =
        $useProductUnits
            ? (
                $itemProductUnits[
                    (int)$item['id']
                ] ?? []
            )
            : [];
?>

    <?php if (
    $useProductUnits &&
    !empty($unitsForThisItem)
): ?>



            <div class="unit-chip-wrap">
                <?php foreach ($unitsForThisItem as $unit): ?>
                    <?php
                        $unitName = trim((string)($unit['unit_name'] ?? ''));
                        $unitPrice = (float)($unit['sale_price'] ?? 0);
                        $unitConversion = (float)($unit['conversion_qty'] ?? 1);
                        $isDefaultUnit = (int)($unit['is_default'] ?? 0) === 1;
                        $isBaseUnit = (int)($unit['is_base_unit'] ?? 0) === 1;
                    ?>
                    <span class="unit-chip <?php echo $isDefaultUnit ? 'unit-chip-default' : ''; ?>">
                        <strong><?php echo htmlspecialchars($unitName !== '' ? $unitName : 'Unit'); ?></strong>
                        <small>
                            Rs <?php echo number_format($unitPrice, 0); ?>
                            <?php if ($unitConversion > 0): ?>
                                / <?php echo rtrim(rtrim(number_format($unitConversion, 2), '0'), '.'); ?>
                            <?php endif; ?>
                            <?php if ($isBaseUnit): ?> Base<?php endif; ?>
                            <?php if ($isDefaultUnit): ?> Default<?php endif; ?>
                        </small>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <span class="no-badge">No Units</span>
        <?php endif; ?>
    </td>
<?php endif; ?>

<?php if (!$isRestaurantClient): ?>
    <td class="item-cost" data-column-key="cost">Rs <?php echo number_format((float)($item['cost'] ?? 0), 2); ?></td>
<?php endif; ?>

<td class="item-discount" data-column-key="discount">
                                            <?php
                                                /*
                                                |--------------------------------------------------------------------------
                                                | Maximum Discount Limit
                                                |--------------------------------------------------------------------------
                                                | Ab yeh item par auto discount apply nahi karta.
                                                | Yeh sirf POS invoice discount ki per-item maximum limit hai.
                                                | New columns available hon to has_max_discount/max_discount_percent use honge.
                                                | Fallback old columns has_discount/discount_value par rakha hai.
                                                */
                                                $hasMaxDiscount = (int)($item['has_max_discount'] ?? ($item['has_discount'] ?? 0)) === 1;
                                                $maxDiscountPercent = (float)($item['max_discount_percent'] ?? ($item['discount_value'] ?? 0));

                                                if ($hasMaxDiscount && $maxDiscountPercent > 0) {
                                                    echo 'Max ' . number_format($maxDiscountPercent, 2) . '%';
                                                } else {
                                                    echo '<span class="no-badge">No</span>';
                                                }
                                            ?>
                                        </td>
                                        <?php if ($wholesaleEnabled): ?>
                                            <td class="item-wholesale1" data-column-key="wholesale1">Rs <?php echo number_format((float)($item['wholesaler_price_1'] ?? 0), 2); ?></td>
                                            <td class="item-wholesale2" data-column-key="wholesale2">Rs <?php echo number_format((float)($item['wholesaler_price_2'] ?? 0), 2); ?></td>
                                            <td class="item-wholesale3" data-column-key="wholesale3">Rs <?php echo number_format((float)($item['wholesaler_price_3'] ?? 0), 2); ?></td>
                                        <?php endif; ?>
                                        <td class="text-center" data-column-key="variant">
                                            <?php if ((int)($item['has_variants'] ?? 0) === 1): ?>
                                                <a href="items_variant.php?item_id=<?php echo (int)$item['id']; ?>" class="variant-badge text-decoration-none">
                                                    Yes
                                                </a>
                                            <?php else: ?>
                                                <span class="no-badge">No</span>
                                            <?php endif; ?>
                                        </td>
                                     <td class="text-center" data-column-key="expiry">
    <?php if ((int)($item['has_expiry'] ?? 0) === 1): ?>

        <?php if (!empty($item['expiry_date'])): ?>

            <span class="variant-badge">
                <?php echo date(
                    'd M Y',
                    strtotime($item['expiry_date'])
                ); ?>
            </span>

        <?php else: ?>

            <span class="variant-badge">Yes</span>

        <?php endif; ?>

    <?php else: ?>

        <span class="no-badge">No</span>

    <?php endif; ?>
</td>

<td
    class="text-center item-status-cell"
    data-column-key="status"
>
    <select
        class="item-status-select <?php echo $itemStatus === 'active'
            ? 'is-active'
            : 'is-inactive'; ?>"
        data-item-id="<?php echo (int)$item['id']; ?>"
        data-previous-status="<?php echo htmlspecialchars(
            $itemStatus,
            ENT_QUOTES,
            'UTF-8'
        ); ?>"
        aria-label="Product status"
    >
        <option
            value="active"
            <?php echo $itemStatus === 'active'
                ? 'selected'
                : ''; ?>
        >
            Active
        </option>

        <option
            value="inactive"
            <?php echo $itemStatus === 'inactive'
                ? 'selected'
                : ''; ?>
        >
            Inactive
        </option>
    </select>
</td>

<td data-column-key="actions">
                                            <div class="action-icons">
                                                <i
                                                    class="bi bi-pencil-fill action-icon action-edit editItem"
                                                    data-id="<?php echo (int)$item['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>"
                                                    data-supplier_id="<?php echo (int)($item['supplier_id'] ?? 0); ?>"
                                                    data-category="<?php echo htmlspecialchars((string)($item['category'] ?? ''), ENT_QUOTES); ?>"
                                                    data-description="<?php echo htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES); ?>"
                                                    data-price="<?php echo htmlspecialchars((string)$item['price'], ENT_QUOTES); ?>"
                                                    data-cost="<?php echo htmlspecialchars((string)($item['cost'] ?? 0), ENT_QUOTES); ?>"
                                                    data-wholesaler_price_1="<?php echo htmlspecialchars((string)($item['wholesaler_price_1'] ?? 0), ENT_QUOTES); ?>"
                                                    data-wholesaler_price_2="<?php echo htmlspecialchars((string)($item['wholesaler_price_2'] ?? 0), ENT_QUOTES); ?>"
                                                    data-wholesaler_price_3="<?php echo htmlspecialchars((string)($item['wholesaler_price_3'] ?? 0), ENT_QUOTES); ?>"
                                                    
                                                    data-has_wholesale="<?php echo (int)($item['has_wholesale'] ?? 0); ?>"
data-wholesale_prices='<?php echo htmlspecialchars(json_encode($itemWholesalePrices[(int)$item['id']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                                    <?php
                                                        $editHasMaxDiscount = (int)($item['has_max_discount'] ?? ($item['has_discount'] ?? 0));
                                                        $editMaxDiscountPercent = (float)($item['max_discount_percent'] ?? ($item['discount_value'] ?? 0));
                                                    ?>
                                                    data-has_discount="<?php echo $editHasMaxDiscount; ?>"
                                                    data-discount_type="percent"
                                                    data-discount_value="<?php echo htmlspecialchars((string)$editMaxDiscountPercent, ENT_QUOTES); ?>"
                                                    data-has_max_discount="<?php echo $editHasMaxDiscount; ?>"
                                                    data-max_discount_percent="<?php echo htmlspecialchars((string)$editMaxDiscountPercent, ENT_QUOTES); ?>"
                                                    data-sku="<?php echo htmlspecialchars($item['sku'] ?? '', ENT_QUOTES); ?>"
                                                    data-type="<?php echo htmlspecialchars($item['type'] ?? 'product', ENT_QUOTES); ?>"
                                                    data-has_variants="<?php echo (int)($item['has_variants'] ?? 0); ?>"
                                                    data-has_expiry="<?php echo (int)($item['has_expiry'] ?? 0); ?>"
                                                    data-expiry_date="<?php echo htmlspecialchars((string)($item['expiry_date'] ?? ''), ENT_QUOTES); ?>"
                                                    data-variant_details="<?php echo htmlspecialchars((string)($item['variant_details'] ?? ''), ENT_QUOTES); ?>"
                                                    data-image="<?php echo htmlspecialchars($item['image'] ?? '', ENT_QUOTES); ?>"
                                                    data-is_raw="<?php echo (int)($item['is_raw_item'] ?? 0); ?>"
                                                    data-track_inventory="<?php echo (int)($item['track_inventory'] ?? 1); ?>"
                                                    data-stock_qty="<?php echo htmlspecialchars((string)$stockQty, ENT_QUOTES); ?>"
                                                    <?php if ($isPharmacyRetailClient): ?>
    data-base_unit_name="<?php echo htmlspecialchars((string)($item['base_unit_name'] ?? 'Tablet'), ENT_QUOTES); ?>"
    data-allow_single_unit_sale="<?php echo (int)($item['allow_single_unit_sale'] ?? 0); ?>"
    data-product_units='<?php echo htmlspecialchars(json_encode($itemProductUnits[(int)$item['id']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
<?php endif; ?>
                                                    title="Edit"
                                                ></i>

                                                <i
                                                    class="bi bi-trash-fill action-icon action-delete deleteItem"
                                                    data-id="<?php echo (int)$item['id']; ?>"
                                                    title="Delete"
                                                ></i>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                   <div class="table-footer">

    <div class="footer-left">

        <button
            type="button"
            class="bulk-delete-btn"
            id="bulkDeleteBtn"
        >
            <i class="bi bi-trash me-1"></i>
            Delete Selected
        </button>

        <div class="d-flex align-items-center gap-2 flex-wrap">

            <span class="muted-text">
                Entries per page:
            </span>

            <select
                id="entriesPerPage"
                class="entries-select"
            >
                <?php foreach ($allowedPageLimits as $pageLimit): ?>

                    <option
                        value="<?php echo (int)$pageLimit; ?>"
                        <?php echo $limit === $pageLimit
                            ? 'selected'
                            : ''; ?>
                    >
                        <?php echo (int)$pageLimit; ?>
                    </option>

                <?php endforeach; ?>
            </select>

            <?php
                $showingFrom = $filteredItemsCount > 0
                    ? $offset + 1
                    : 0;

                $showingTo = min(
                    $offset + count($items),
                    $filteredItemsCount
                );
            ?>

            <span
                class="muted-text"
                id="entriesInfo"
            >
                Showing
                <?php echo (int)$showingFrom; ?>
                to
                <?php echo (int)$showingTo; ?>
                of
                <?php echo (int)$filteredItemsCount; ?>
                entries
            </span>

        </div>

    </div>

    <div class="footer-right">

        <div class="pagination-wrap">

            <button
                type="button"
                class="page-nav-btn"
                id="prevPageBtn"
                <?php echo $page <= 1
                    ? 'disabled'
                    : ''; ?>
            >
                <i class="bi bi-chevron-left"></i>
            </button>

            <button
                type="button"
                class="page-number-btn active"
                id="currentPageBtn"
                disabled
            >
                <?php echo (int)$page; ?>
                /
                <?php echo (int)$totalPages; ?>
            </button>

            <button
                type="button"
                class="page-nav-btn"
                id="nextPageBtn"
                <?php echo $page >= $totalPages
                    ? 'disabled'
                    : ''; ?>
            >
                Next
                <i class="bi bi-chevron-right"></i>
            </button>

        </div>

    </div>

</div>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- Add / Edit Item Modal -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content item-modal-content">
            <form id="itemForm" enctype="multipart/form-data">
                <input type="hidden" name="item_id" id="item_id">
                <input type="hidden" name="is_raw" id="is_raw" value="0">
                <input type="hidden" name="branch_id" id="branch_id" value="<?php echo $branch_id; ?>">
                <input type="hidden" name="description" id="description" value="">

                <div class="modal-header item-modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body item-modal-body">
                    <div
                        id="itemFormMessage"
                        class="item-form-message"
                        role="alert"
                        aria-live="polite"
                        hidden
                    ></div>

                    <div class="row g-4 align-items-start">
                        <div class="col-lg-4">
                            <div class="upload-box">
                                <div class="upload-preview" id="uploadPreview">
                                    <i class="bi bi-camera-fill"></i>
                                    <div class="upload-title">Upload Item Image</div>
                                    <div class="upload-subtext">JPG, PNG, WEBP</div>
                                </div>

                                <button type="button" class="upload-btn" onclick="document.getElementById('image').click()">
                                    <i class="bi bi-cloud-upload me-2"></i>Choose Photo
                                </button>
                                <input type="file" name="image" id="image" class="d-none" accept="image/*">
                                
                                <div
    class="paste-image-box"
    id="pasteImageBox"
    tabindex="0"
    title="Click here and press Ctrl + V"
>
    <i class="bi bi-clipboard2-image"></i>

    <div>
        <strong>Paste Product Image</strong>
        <small>Copy an image, click here and press Ctrl + V</small>
    </div>

    <kbd>Ctrl + V</kbd>
</div>
                                
                                <div class="image-url-box mt-3">
    <label class="item-label">Image URL</label>
    <input 
        type="url" 
        name="image_url" 
        id="image_url" 
        class="item-input" 
        placeholder="https://example.com/product-image.jpg"
    >
    <button type="button" class="upload-btn url-preview-btn" onclick="previewImageUrl()">
        <i class="bi bi-link-45deg me-2"></i>Preview URL Image
    </button>
</div>

                                <div class="modal-side-note">
                                    Image optional hai. Edit me purani image bhi show hogi.
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="item-label">Name <span>*</span></label>
                                    <input type="text" name="name" id="name" class="item-input" required placeholder="Enter item name">
                                </div>

                                <div class="col-md-6">
                                    <label class="item-label">SKU</label>
                                    <input type="text" name="sku" id="sku" class="item-input" placeholder="Enter SKU">
                                </div>

                                <div class="col-md-6">
                                    <label class="item-label">Type</label>
                                    <select name="type" id="type" class="item-input">
                                        <option value="product">Product</option>
                                        <option value="service">Service</option>
                                        <option value="menu">Menu</option>
                                    </select>
                                </div>

                          <?php if (!$isRestaurantClient): ?>
    <div class="col-md-6 product-only">
        <label class="item-label">Supplier</label>
        <select name="supplier_id" id="supplier_id" class="item-input">
            <option value="">No Supplier</option>
            <?php foreach ($suppliers as $supplier): ?>
                <?php
                    $supplierLabel = trim((string)($supplier['company_name'] ?? ''));
                    if ($supplierLabel === '') {
                        $supplierLabel = trim((string)($supplier['name'] ?? ''));
                    }
                ?>
                <option value="<?php echo (int)$supplier['id']; ?>">
                    <?php echo htmlspecialchars($supplierLabel); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
<?php else: ?>
    <input type="hidden" name="supplier_id" id="supplier_id" value="">
<?php endif; ?>
                                <div class="col-md-6">
    <label class="item-label">Category</label>
    <select name="category" id="category" class="item-input">
        <option value="">Uncategorized</option>
        <?php foreach ($categoriesList as $cat): ?>
            <option value="<?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>">
                <?php echo htmlspecialchars($cat['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

                                <div class="col-md-6 product-only">
                                    <label class="item-label">Price</label>
                                    <div class="price-wrap">
                                        <div class="price-prefix">Rs</div>
                                        <input type="number" step="0.01" name="price" id="price" class="item-input price-input" value="0" placeholder="0.00">
                                    </div>
                                </div>

                            <?php if (!$isRestaurantClient): ?>
    <div class="col-md-6 product-only">
        <label class="item-label">Purchase Cost</label>
        <div class="price-wrap">
            <div class="price-prefix">Rs</div>
            <input type="number" step="0.01" name="cost" id="cost" class="item-input price-input" value="0" placeholder="0.00">
        </div>
    </div>
<?php else: ?>
    <input type="hidden" name="cost" id="cost" value="0">
<?php endif; ?>

                             <input type="hidden" name="wholesaler_price_1" id="wholesaler_price_1" value="0">
<input type="hidden" name="wholesaler_price_2" id="wholesaler_price_2" value="0">
<input type="hidden" name="wholesaler_price_3" id="wholesaler_price_3" value="0">

                                <div class="col-md-6">
                                    <label class="item-label">Track Inventory</label>
                                    <select name="track_inventory" id="track_inventory" class="item-input">
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>

                                <div class="col-md-12">
                                    <label class="item-label">Stock Qty</label>
                                    <input type="number" step="0.01" name="stock_qty" id="stock_qty" class="item-input" value="0" placeholder="0">
                                </div>
<?php if ($isPharmacyRetailClient): ?>

    <!-- Base Stock Unit -->
    <div class="col-md-6 product-only pharmacy-unit-feature">
        <label class="item-label">Base Stock Unit</label>

        <input
            type="text"
            name="base_unit_name"
            id="base_unit_name"
            class="item-input"
            value="Tablet"
            placeholder="Example: Tablet"
        >

        <div class="modal-side-note">
            Stock quantity base unit mein maintain hogi. Example: 200 Tablets.
        </div>
    </div>

<div
    id="baseStockUnitField"
    class="col-md-6 product-only pharmacy-unit-feature"
>
    <label class="item-label">Base Stock Unit</label>

    <div class="single-sale-toggle-card">
        <label class="option-check single-sale-check">
            <input
                type="checkbox"
                id="allow_single_unit_sale"
                name="allow_single_unit_sale"
                value="1"
            >

            <span>Yes, use units for this product</span>
        </label>
    </div>

    <div class="modal-side-note">
        Enable for Tablet, Strip, Box or other unit-based products.
        Keep disabled for creams, shampoos and normal products.
    </div>
</div>

    <!-- Conditional Unit Pricing Section -->
    <div class="col-md-12 product-only pharmacy-unit-feature">

        <div
            id="singleTabletUnitSection"
            class="single-tablet-workflow"
            style="display:none;"
        >

            <div class="pharmacy-unit-card">

                <div class="pharmacy-unit-head">

                    <div>
                        <div class="pharmacy-unit-title">
                            <i class="bi bi-capsule-pill me-2"></i>
                            Unit Pricing &amp; Conversion
                        </div>

                        <div class="pharmacy-unit-subtitle">
                            Enter strip details below and units will be calculated automatically.
                        </div>
                    </div>

                    <button
                        type="button"
                        class="recalculate-unit-btn"
                        onclick="recalculateSingleTabletPricing()"
                    >
                        <i class="bi bi-arrow-repeat me-1"></i>
                        Recalculate
                    </button>

                </div>

                <!-- Calculation Inputs -->
                <div class="single-tablet-calculator">

                    <div>
                        <label class="item-label">
                            Total Tablets in 1 Strip <span>*</span>
                        </label>

                        <div class="unit-input-suffix-wrap">
                            <input
                                type="number"
                                id="strip_tablet_qty"
                                class="item-input"
                                min="1"
                                step="1"
                                value="8"
                                placeholder="8"
                            >

                            <span class="unit-input-suffix">Tablets</span>
                        </div>

                        <div class="modal-side-note">
                            How many tablets are in one strip.
                        </div>
                    </div>

                    <div>
                        <label class="item-label">
                            Strip Selling Price (PKR) <span>*</span>
                        </label>

                        <div class="price-wrap">
                            <div class="price-prefix">Rs</div>

                            <input
                                type="number"
                                id="strip_sale_price"
                                class="item-input price-input"
                                min="0"
                                step="0.01"
                                value="0"
                                placeholder="220.00"
                            >
                        </div>

                        <div class="modal-side-note">
                            Enter selling price of one strip.
                        </div>
                    </div>

                </div>

                <!-- Unit Table -->
                <div class="pharmacy-unit-table reference-unit-table">

                    <div class="pharmacy-unit-grid pharmacy-unit-grid-head">
                        <div>Unit</div>
                        <div>Description</div>
                        <div>Qty in Unit</div>
                        <div>Selling Price</div>
                        <div>Price per Tablet</div>
                        <div>Default</div>
                        <div>Action</div>
                    </div>

                    <div id="productUnitRows"></div>

                </div>

                <div class="pharmacy-unit-note">
                    <i class="bi bi-info-circle me-1"></i>

                    <strong>Calculation Logic:</strong><br>

                    Price per Tablet = Strip Price ÷ Total Tablets in Strip.<br>
                    Single Tablet price automatically Price per Tablet ke equal hogi.
                </div>

            </div>
        </div>
    </div>

<?php endif; ?>

                                <div class="col-md-12 product-only">
                                    <label class="item-label">Options</label>
                                    <div class="option-pills">
                                        <label class="option-check">
                                            <input type="checkbox" id="has_variants" name="has_variants">
                                            <span>Has Variants</span>
                                        </label>

                                        <label class="option-check">
                                            <input type="checkbox" id="has_expiry" name="has_expiry">
                                            <span>Has Expiry</span>
                                        </label>

                                        <label class="option-check">
                                            <input type="checkbox" id="has_discount" name="has_discount">
                                            <span>Has Maximum Discount</span>
                                        </label>

                                        <?php if ($wholesaleEnabled): ?>
                                            <label class="option-check">
                                                <input type="checkbox" id="has_wholesale" name="has_wholesale">
                                                <span>Has Wholesale</span>
                                            </label>
                                        <?php endif; ?>
                                    </div>

                                    <div class="conditional-options mt-3">
                                        <div id="variantDetailsBox" style="display:none;">
                                            <label class="item-label">Variant Details</label>
                                            <input
                                                type="text"
                                                name="variant_details"
                                                id="variant_details"
                                                class="item-input"
                                                placeholder="Example: Size: S, M, L / Color: Red, Blue"
                                            >
                                            <!--<div class="modal-side-note">-->
                                            <!--    Yahan likho ke product ke variants kya hain, jaise Size, Color, Weight, etc.-->
                                            <!--</div>-->
                                        </div>

                                        <div id="expiryDateBox" class="mt-3" style="display:none;">
                                            <label class="item-label">Expiry Date</label>
                                            <input
                                                type="date"
                                                name="expiry_date"
                                                id="expiry_date"
                                                class="item-input"
                                            >
                                            <!--<div class="modal-side-note">-->
                                            <!--    Yahan product ki expiry date select karo.-->
                                            <!--</div>-->
                                        </div>
                                        <div id="discountDetailsBox" class="mt-3" style="display:none;">
                                            <label class="item-label">Maximum Discount Limit</label>

                                            <input type="hidden" name="discount_type" id="discount_type" value="percent">

                                            <div class="row g-2">
                                                <div class="col-md-12">
                                                    <div class="price-wrap">
                                                        <div class="price-prefix" id="discountPrefix">%</div>
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            max="100"
                                                            name="discount_value"
                                                            id="discount_value"
                                                            class="item-input price-input"
                                                            value="0"
                                                            placeholder="Example: 5"
                                                        >
                                                    </div>
                                                    <div class="modal-side-note">
                                                        Yeh product par auto discount nahi lagayega. POS me invoice discount is item par is limit se zyada apply nahi hoga.
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if ($wholesaleEnabled): ?>
                                            <div id="wholesalePricesBox" class="mt-3" style="display:none;">
                                                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                                    <label class="item-label mb-0">Wholesale Prices</label>

                                                    <button type="button" class="add-wholesale-btn" onclick="addWholesaleRow()">
                                                        <i class="bi bi-plus-lg me-1"></i> Add Wholesale
                                                    </button>
                                                </div>

                                                <div id="wholesalePriceRows"></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer item-modal-footer">
                    <div class="footer-left-note" id="itemFooterNote">Create or update your item details.</div>

                    <div class="footer-btns">
                        <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn" id="itemSubmitBtn">Save Item</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content import-modal-content">
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Import Items</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <label class="form-label">Select CSV File</label>
                    <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>

                    <div class="mt-3">
                        <a href="download_sample_csv.php" class="btn btn-sm" style="background: linear-gradient(135deg, #000, #e4ac00); color:#fff; border:none;">
                            <i class="bi bi-download me-1"></i> Download Sample CSV
                        </a>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-outline-dark" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-warning" type="submit">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Edit Modal -->
<!-- Bulk Edit Modal -->
<div class="modal fade" id="bulkEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content bulk-modal-content">
            <form id="bulkEditForm">
                <div class="modal-header bulk-modal-header">
                    <h5 class="modal-title">Bulk Edit Selected Items</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body bulk-modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="bulk-label">Type</label>
                            <select name="bulk_type" id="bulk_type" class="bulk-input">
                                <option value="">Do not change</option>
                                <option value="product">Product</option>
                                <option value="service">Service</option>
                                <option value="menu">Menu</option>
                            </select>
                        </div>


<div class="col-12">
    <label class="bulk-label">Product Status</label>

    <select
        name="bulk_status"
        id="bulk_status"
        class="bulk-input"
    >
        <option value="">Do not change</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
</div>
                        <div class="col-12">
                            <label class="bulk-label">Track Inventory</label>
                            <select name="bulk_track_inventory" id="bulk_track_inventory" class="bulk-input">
                                <option value="">Do not change</option>
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="bulk-label">Has Expiry</label>
                            <select name="bulk_has_expiry" id="bulk_has_expiry" class="bulk-input">
                                <option value="">Do not change</option>
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="bulk-label">Has Variants</label>
                            <select name="bulk_has_variants" id="bulk_has_variants" class="bulk-input">
                                <option value="">Do not change</option>
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="bulk-note-box">
                                Only the selected items will be updated. Any field set to <strong>"Do not change"</strong> will retain its current value.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bulk-modal-footer">
                    <div class="bulk-left-note">Apply changes to all selected products.</div>

                    <div class="bulk-footer-btns">
                        <button type="button" class="bulk-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="bulk-save-btn">Apply Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const WHOLESALE_ENABLED = <?php echo $wholesaleEnabled ? 'true' : 'false'; ?>;
const PHARMACY_UNIT_ENABLED = <?php echo $isPharmacyRetailClient ? 'true' : 'false'; ?>;

function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
const itemModal = new bootstrap.Modal(document.getElementById('itemModal'));
const importModal = new bootstrap.Modal(document.getElementById('importModal'));
const bulkEditModal = new bootstrap.Modal(document.getElementById('bulkEditModal'));

const searchInput = document.getElementById('searchInput');
const entriesPerPageSelect = document.getElementById('entriesPerPage');
const entriesInfo = document.getElementById('entriesInfo');
const currentPageBtn = document.getElementById('currentPageBtn');
const prevPageBtn = document.getElementById('prevPageBtn');
const nextPageBtn = document.getElementById('nextPageBtn');

const currentPage = <?php echo (int)$page; ?>;
const totalPages = <?php echo (int)$totalPages; ?>;

/*
|--------------------------------------------------------------------------
| ITEMS TABLE COLUMN FILTERS
|--------------------------------------------------------------------------
| Sirf frontend columns hide/show hongi.
| Backend, database, pagination aur item workflow untouched rahega.
|--------------------------------------------------------------------------
*/
const ITEMS_COLUMN_STORAGE_KEY =
    'oneerp_items_visible_columns_v2';


function getAvailableItemsColumnKeys() {

    return Array.from(
        document.querySelectorAll(
            '#itemsTable thead [data-column-key]'
        )
    )
    .map(function (column) {
        return (
            column.dataset.columnKey || ''
        ).trim();
    })
    .filter(Boolean);
}


function setItemsColumnCheckboxes(visibleKeys) {

    const visibleSet =
        new Set(visibleKeys);

    document
        .querySelectorAll(
            '.item-column-checkbox'
        )
        .forEach(function (checkbox) {

            checkbox.checked =
                visibleSet.has(
                    checkbox.value
                );
        });
}


function setItemsColumnVisibility(
    visibleKeys,
    savePreference = true
) {

    const availableKeys =
        getAvailableItemsColumnKeys();

    const visibleSet =
        new Set(
            visibleKeys.filter(
                function (key) {
                    return availableKeys.includes(
                        key
                    );
                }
            )
        );

    availableKeys.forEach(
        function (columnKey) {

            const columnCells =
                document.querySelectorAll(
                    '#itemsTable ' +
                    '[data-column-key="' +
                    columnKey +
                    '"]'
                );

            columnCells.forEach(
                function (cell) {

                    if (
                        visibleSet.has(columnKey)
                    ) {

                        /*
                        |--------------------------------------------------------------------------
                        | Column show
                        |--------------------------------------------------------------------------
                        */
                        cell.style.removeProperty(
                            'display'
                        );

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Column hide
                        | important use kiya hai taake CSS override block na kare.
                        |--------------------------------------------------------------------------
                        */
                        cell.style.setProperty(
                            'display',
                            'none',
                            'important'
                        );
                    }
                }
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Empty table colspan update
    |--------------------------------------------------------------------------
    */
    const emptyTableCell =
        document.querySelector(
            '#itemsTableBody td[colspan]'
        );

    if (emptyTableCell) {

        /*
        |--------------------------------------------------------------------------
        | +1 first checkbox column ke liye
        |--------------------------------------------------------------------------
        */
        emptyTableCell.setAttribute(
            'colspan',
            String(visibleSet.size + 1)
        );
    }

    setItemsColumnCheckboxes(
        Array.from(visibleSet)
    );

    if (savePreference) {

        try {

            localStorage.setItem(
                ITEMS_COLUMN_STORAGE_KEY,
                JSON.stringify(
                    Array.from(visibleSet)
                )
            );

        } catch (error) {

            console.warn(
                'Column preferences save nahi ho saken:',
                error
            );
        }
    }
}


function closeItemsColumnDropdown() {

    const columnButton =
        document.getElementById(
            'columnFilterButton'
        );

    if (
        !columnButton ||
        typeof bootstrap === 'undefined' ||
        !bootstrap.Dropdown
    ) {
        return;
    }

    bootstrap.Dropdown
        .getOrCreateInstance(columnButton)
        .hide();
}


function applyItemsColumnFilters() {

    const selectedKeys =
        Array.from(
            document.querySelectorAll(
                '.item-column-checkbox:checked'
            )
        )
        .map(function (checkbox) {
            return checkbox.value;
        });

    setItemsColumnVisibility(
        selectedKeys,
        true
    );

    closeItemsColumnDropdown();
}


function resetItemsColumnFilters() {

    const allColumnKeys =
        getAvailableItemsColumnKeys();

    setItemsColumnVisibility(
        allColumnKeys,
        true
    );

    closeItemsColumnDropdown();
}


function restoreItemsColumnFilters() {

    const allColumnKeys =
        getAvailableItemsColumnKeys();

    let savedColumnKeys = null;

    try {

        const savedValue =
            localStorage.getItem(
                ITEMS_COLUMN_STORAGE_KEY
            );

        if (savedValue !== null) {

            const parsedValue =
                JSON.parse(savedValue);

            if (Array.isArray(parsedValue)) {

                savedColumnKeys =
                    parsedValue.filter(
                        function (key) {
                            return allColumnKeys.includes(
                                key
                            );
                        }
                    );
            }
        }

    } catch (error) {

        console.warn(
            'Saved column preferences read nahi ho saken:',
            error
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Saved setting na ho to tamam columns visible
    |--------------------------------------------------------------------------
    */
    if (!Array.isArray(savedColumnKeys)) {
        savedColumnKeys = allColumnKeys;
    }

    setItemsColumnVisibility(
        savedColumnKeys,
        false
    );
}


/*
|--------------------------------------------------------------------------
| Inline onclick handlers ke liye functions global rakhein
|--------------------------------------------------------------------------
*/

window.applyItemsColumnFilters =
    applyItemsColumnFilters;

window.resetItemsColumnFilters =
    resetItemsColumnFilters;


/*
|--------------------------------------------------------------------------
| Page load par saved column selection restore
|--------------------------------------------------------------------------
*/

restoreItemsColumnFilters();

function allRows() {
    return Array.from(
        document.querySelectorAll(
            '#itemsTableBody .item-row'
        )
    );
}

function navigateItems(changes = {}) {
    const url = new URL(
        window.location.href
    );

    Object.entries(changes).forEach(
        ([key, value]) => {
            const cleanValue = String(
                value ?? ''
            ).trim();

            if (
                cleanValue === '' ||
                cleanValue === '0'
            ) {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(
                    key,
                    cleanValue
                );
            }
        }
    );

    window.location.href = url.toString();
}

function filterType(value) {
    navigateItems({
        type: value,
        page: 1
    });
}

function filterStock(value) {
    navigateItems({
        stock: value,
        page: 1
    });
}

function filterDuplicates(value) {
    navigateItems({
        duplicates: value,
        page: 1
    });
}
function refreshPage() {
    window.location.reload();
}

function exportItems() {
    window.location.href = 'export_items.php';
}

function openImportModal() {
    importModal.show();
}

function openBulkEditModal() {
    bulkEditModal.show();
}

function getImageSrc(imageValue = '') {
    imageValue = (imageValue || '').trim();

    if (!imageValue) return '';

    if (/^https?:\/\//i.test(imageValue)) {
        return imageValue;
    }

    return `/uploads/items/${imageValue}`;
}

function setUploadPreview(imageValue = '') {
    const preview = document.getElementById('uploadPreview');
    const imageUrlInput = document.getElementById('image_url');

    if (imageValue) {
        const src = getImageSrc(imageValue);

        preview.innerHTML = `
            <img 
                src="${src}" 
                alt="Item Photo"
                onerror="this.parentElement.innerHTML='<i class=&quot;bi bi-image&quot;></i><div class=&quot;upload-title&quot;>Image not loaded</div><div class=&quot;upload-subtext&quot;>Invalid or blocked image URL</div>';"
            >
        `;

        if (imageUrlInput && /^https?:\/\//i.test(imageValue)) {
            imageUrlInput.value = imageValue;
        }
    } else {
        preview.innerHTML = `
            <i class="bi bi-camera-fill"></i>
            <div class="upload-title">Upload Item Image</div>
            <div class="upload-subtext">JPG, PNG, WEBP</div>
        `;

        if (imageUrlInput) {
            imageUrlInput.value = '';
        }
    }
}
function previewImageUrl() {
    const imageUrlInput = document.getElementById('image_url');
    const url = imageUrlInput ? imageUrlInput.value.trim() : '';

    if (!url) {
        alert('Please enter image URL.');
        return;
    }

    if (!/^https?:\/\//i.test(url)) {
        alert('Image URL must start with http:// or https://');
        return;
    }

    const preview = document.getElementById('uploadPreview');

    preview.innerHTML = `
        <img 
            src="${url}" 
            alt="URL Preview"
            onerror="this.parentElement.innerHTML='<i class=&quot;bi bi-image&quot;></i><div class=&quot;upload-title&quot;>Image not loaded</div><div class=&quot;upload-subtext&quot;>Use direct JPG, PNG, WEBP image URL</div>';"
        >
    `;

    const fileInput = document.getElementById('image');
    if (fileInput) {
        fileInput.value = '';
    }
}

function applyProductImageFile(file) {
    if (!file) return;

    if (
        !file.type ||
        !file.type.startsWith('image/')
    ) {
        alert(
            'Clipboard content image nahi hai.'
        );

        return;
    }

    const maxFileSize =
        5 * 1024 * 1024;

    if (file.size > maxFileSize) {
        alert(
            'Image maximum 5MB honi chahiye.'
        );

        return;
    }

    const extension =
        file.type.split('/')[1] || 'png';

    const finalFile =
        file.name
            ? file
            : new File(
                [file],
                `pasted-product-${Date.now()}.${extension}`,
                {
                    type: file.type
                }
            );

    const imageInput =
        document.getElementById('image');

    const imageUrlInput =
        document.getElementById(
            'image_url'
        );

    if (!imageInput) return;

    const dataTransfer =
        new DataTransfer();

    dataTransfer.items.add(finalFile);

    imageInput.files =
        dataTransfer.files;

    if (imageUrlInput) {
        imageUrlInput.value = '';
    }

    const reader =
        new FileReader();

    reader.onload = function (event) {
        document.getElementById(
            'uploadPreview'
        ).innerHTML = `
            <img
                src="${event.target.result}"
                alt="Pasted Product Image"
            >
        `;
    };

    reader.readAsDataURL(finalFile);
}

function handleProductImagePaste(event) {
    const clipboardItems =
        Array.from(
            event.clipboardData?.items || []
        );

    const imageClipboardItem =
        clipboardItems.find(item => {
            return (
                item.kind === 'file' &&
                item.type.startsWith('image/')
            );
        });

    if (!imageClipboardItem) {
        return;
    }

    const imageFile =
        imageClipboardItem.getAsFile();

    if (!imageFile) {
        return;
    }

    event.preventDefault();

    applyProductImageFile(imageFile);
}

function toggleItemExtraOptions() {
    const hasVariants = document.getElementById('has_variants');
    const hasExpiry = document.getElementById('has_expiry');
    const hasWholesale = document.getElementById('has_wholesale');
    const hasDiscount = document.getElementById('has_discount');

    const variantBox = document.getElementById('variantDetailsBox');
    const expiryBox = document.getElementById('expiryDateBox');
    const wholesaleBox = document.getElementById('wholesalePricesBox');
    const discountBox = document.getElementById('discountDetailsBox');

    const variantInput = document.getElementById('variant_details');
    const expiryInput = document.getElementById('expiry_date');
    const discountValueInput = document.getElementById('discount_value');
    const discountTypeInput = document.getElementById('discount_type');
    const discountPrefix = document.getElementById('discountPrefix');
    const wholesaleRows = document.getElementById('wholesalePriceRows');

    if (variantBox) {
        variantBox.style.display = hasVariants && hasVariants.checked ? 'block' : 'none';
    }

    if (expiryBox) {
        expiryBox.style.display = hasExpiry && hasExpiry.checked ? 'block' : 'none';
    }

    if (wholesaleBox) {
        wholesaleBox.style.display = hasWholesale && hasWholesale.checked ? 'block' : 'none';
    }

    if (discountBox) {
        discountBox.style.display = hasDiscount && hasDiscount.checked ? 'block' : 'none';
    }

    if (discountPrefix) {
        discountPrefix.textContent = '%';
    }

    if (discountTypeInput) {
        discountTypeInput.value = 'percent';
    }

    if (variantInput && hasVariants && !hasVariants.checked) {
        variantInput.value = '';
    }

    if (expiryInput && hasExpiry && !hasExpiry.checked) {
        expiryInput.value = '';
    }

    if (discountValueInput && hasDiscount && !hasDiscount.checked) {
        discountValueInput.value = '0';
        if (discountTypeInput) {
            discountTypeInput.value = 'percent';
        }
    }

    if (hasWholesale && hasWholesale.checked) {
        if (wholesaleRows && wholesaleRows.querySelectorAll('.wholesale-row').length === 0) {
            addWholesaleRow('Wholesale1', '0');
        }
    } else {
        if (wholesaleRows) {
            wholesaleRows.innerHTML = '';
        }
        syncFirstThreeWholesaleFields();
    }
}


function escapeHtmlForAttr(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function getDefaultPharmacyUnits(stripPrice = '0', stripQty = '8') {
    let qty = parseFloat(stripQty || '8');
    let price = parseFloat(stripPrice || '0');

    if (!Number.isFinite(qty) || qty <= 0) {
        qty = 8;
    }

    if (!Number.isFinite(price) || price < 0) {
        price = 0;
    }

    const tabletPrice = qty > 0 ? price / qty : 0;

    return [
        {
            unit_name: 'Strip',
            conversion_qty: String(qty),
            sale_price: price.toFixed(2),
            barcode: '',
            is_base_unit: 0,
            is_default: 1
        },
        {
            unit_name: 'Single Tablet',
            conversion_qty: '1',
            sale_price: tabletPrice.toFixed(2),
            barcode: '',
            is_base_unit: 1,
            is_default: 0
        }
    ];
}

function getUnitRowValues(row) {
    const unitName =
        row.querySelector('.unit-name-input')?.value.trim() || '';

    const conversionQty = parseFloat(
        row.querySelector('.unit-conversion-input')?.value || '0'
    );

    const salePrice = parseFloat(
        row.querySelector('.unit-price-input')?.value || '0'
    );

    return {
        unitName,
        conversionQty:
            Number.isFinite(conversionQty) ? conversionQty : 0,
        salePrice:
            Number.isFinite(salePrice) ? salePrice : 0
    };
}

function refreshProductUnitRow(row) {
    if (!row) return;

    const values = getUnitRowValues(row);

    const descriptionEl =
        row.querySelector('.unit-description');

    const pricePerTabletEl =
        row.querySelector('.unit-price-per-tablet');

    let description = 'Unit details';

    if (values.conversionQty === 1) {
        description = '1 Tablet';
    } else if (values.conversionQty > 1) {
        description =
            `1 ${values.unitName || 'Unit'} ` +
            `(${values.conversionQty} Tablets)`;
    }

    const pricePerTablet =
        values.conversionQty > 0
            ? values.salePrice / values.conversionQty
            : 0;

    if (descriptionEl) {
        descriptionEl.textContent = description;
    }

    if (pricePerTabletEl) {
        pricePerTabletEl.textContent =
            `Rs ${pricePerTablet.toFixed(2)}`;
    }
}

function refreshAllProductUnitRows() {
    document
        .querySelectorAll('#productUnitRows .product-unit-row')
        .forEach(refreshProductUnitRow);
}

function addProductUnitRow(unit = {}) {
    if (!PHARMACY_UNIT_ENABLED) return;

    const rowsBox =
        document.getElementById('productUnitRows');

    if (!rowsBox) return;

    const row = document.createElement('div');

    row.className =
        'pharmacy-unit-grid product-unit-row';

    const unitName =
        unit.unit_name || '';

    const conversionQty =
        unit.conversion_qty || '1';

    const salePrice =
        unit.sale_price || '0';

    const barcode =
        unit.barcode || '';

    const isBaseUnit =
        String(unit.is_base_unit || '0') === '1';

    const isDefault =
        String(unit.is_default || '0') === '1';

    /*
    |--------------------------------------------------------------------------
    | Frontend row role
    |--------------------------------------------------------------------------
    | Unit ka naam rename hone ke baad bhi row ka original role preserve rahega.
    | Is metadata ka backend/database se koi relation nahi hai.
    |--------------------------------------------------------------------------
    */
    const parsedConversionQty =
        parseFloat(conversionQty || '0');

    if (isBaseUnit) {
        row.dataset.unitRole = 'base';
    } else if (
        Number.isFinite(parsedConversionQty) &&
        parsedConversionQty > 1
    ) {
        row.dataset.unitRole = 'strip';
    }

    row.innerHTML = `
        <div>
            <input
                type="text"
                class="item-input unit-name-input"
                value="${escapeHtmlForAttr(unitName)}"
                placeholder="Strip / Single Tablet"
            >
        </div>

        <div class="unit-description">
            Unit details
        </div>

        <div>
            <input
                type="number"
                step="0.01"
                min="0.01"
                class="item-input unit-conversion-input"
                value="${escapeHtmlForAttr(conversionQty)}"
                placeholder="1"
            >
        </div>

        <div>
            <div class="unit-row-price-wrap">
                <span>Rs</span>

                <input
                    type="number"
                    step="0.01"
                    min="0"
                    class="item-input unit-price-input"
                    value="${escapeHtmlForAttr(salePrice)}"
                    placeholder="0.00"
                >
            </div>
        </div>

        <div class="unit-price-per-tablet">
            Rs 0.00
        </div>

        <div class="text-center">
            <input
                type="radio"
                name="unit_default_radio"
                class="unit-default-radio"
                ${isDefault ? 'checked' : ''}
            >
        </div>

        <div class="unit-row-actions">

            <button
                type="button"
                class="edit-unit-btn"
                onclick="focusProductUnitRow(this)"
                title="Edit Unit"
            >
                <i class="bi bi-pencil"></i>
            </button>

            <button
                type="button"
                class="remove-unit-btn"
                onclick="removeProductUnitRow(this)"
                title="Remove Unit"
            >
                <i class="bi bi-trash"></i>
            </button>

        </div>

        <input
            type="hidden"
            class="unit-barcode-input"
            value="${escapeHtmlForAttr(barcode)}"
        >
    `;

    rowsBox.appendChild(row);

    row
        .querySelectorAll(
            '.unit-name-input, ' +
            '.unit-conversion-input, ' +
            '.unit-price-input'
        )
        .forEach(input => {
            input.addEventListener('input', function () {
                refreshProductUnitRow(row);
            });
        });

    refreshProductUnitRow(row);

    const radios =
        rowsBox.querySelectorAll('.unit-default-radio');

    if (
        radios.length === 1 &&
        !Array.from(radios).some(radio => radio.checked)
    ) {
        radios[0].checked = true;
    }
}
function focusProductUnitRow(button) {
    const row =
        button.closest('.product-unit-row');

    const input =
        row?.querySelector('.unit-name-input');

    if (input) {
        input.focus();
        input.select();
    }
}

function removeProductUnitRow(button) {
    const row =
        button.closest('.product-unit-row');

    if (row) {
        row.remove();
    }

    const rowsBox =
        document.getElementById('productUnitRows');

    if (!rowsBox) return;

    const radios =
        rowsBox.querySelectorAll('.unit-default-radio');

    if (
        radios.length > 0 &&
        !Array.from(radios).some(radio => radio.checked)
    ) {
        radios[0].checked = true;
    }
}

function toggleSingleTabletUnitSection() {
    if (!PHARMACY_UNIT_ENABLED) {
        return;
    }

    const checkbox =
        document.getElementById(
            'allow_single_unit_sale'
        );

    const section =
        document.getElementById(
            'singleTabletUnitSection'
        );

    const baseUnitField =
        document.getElementById(
            'baseStockUnitField'
        );

    const rowsBox =
        document.getElementById(
            'productUnitRows'
        );

    if (!checkbox || !section) {
        return;
    }

    const enabled =
        checkbox.checked;

    /*
    |--------------------------------------------------------------------------
    | Unit pricing section
    |--------------------------------------------------------------------------
    */
    section.style.display =
        enabled ? 'block' : 'none';

    /*
    |--------------------------------------------------------------------------
    | Base Stock Unit field
    |--------------------------------------------------------------------------
    */
    if (baseUnitField) {
        baseUnitField.style.display =
            enabled ? '' : 'none';
    }

    /*
    |--------------------------------------------------------------------------
    | Unit enabled ho aur rows na hon to existing default rows create karo
    |--------------------------------------------------------------------------
    */
    if (
        enabled &&
        rowsBox &&
        rowsBox.querySelectorAll(
            '.product-unit-row'
        ).length === 0
    ) {
        const mainPrice =
            document.getElementById(
                'price'
            )?.value || '0';

        const stripQty =
            document.getElementById(
                'strip_tablet_qty'
            )?.value || '8';

        getDefaultPharmacyUnits(
            mainPrice,
            stripQty
        ).forEach(function (unit) {
            addProductUnitRow(unit);
        });

        const stripPriceInput =
            document.getElementById(
                'strip_sale_price'
            );

        if (stripPriceInput) {
            stripPriceInput.value =
                parseFloat(mainPrice || 0) > 0
                    ? mainPrice
                    : '0';
        }
    }
}

function normalizeProductUnitKey(name = '') {
    return String(name)
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ');
}

function getProductUnitKind(row) {
    const values = getUnitRowValues(row);

    /*
    |--------------------------------------------------------------------------
    | First priority: row role
    |--------------------------------------------------------------------------
    | Unit name editable hai. Isliye Strip/Tablet ko naam se identify karne ke
    | bajaye add/load ke waqt save ki hui frontend role use hogi.
    |--------------------------------------------------------------------------
    */
    const savedRole =
        String(row?.dataset?.unitRole || '').trim();

    if (savedRole === 'strip') {
        return 'strip';
    }

    if (savedRole === 'base') {
        return 'base';
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback for old rows
    |--------------------------------------------------------------------------
    | Purani rendered row par metadata na ho to conversion quantity se role
    | identify hoga. Custom names jaise Box, Sachet, Packet bhi work karenge.
    |--------------------------------------------------------------------------
    */
    if (
        Number.isFinite(values.conversionQty) &&
        values.conversionQty > 1
    ) {
        row.dataset.unitRole = 'strip';
        return 'strip';
    }

    if (
        Number.isFinite(values.conversionQty) &&
        Math.abs(values.conversionQty - 1) < 0.00001
    ) {
        row.dataset.unitRole = 'base';
        return 'base';
    }

    const unitName = normalizeProductUnitKey(
        values.unitName
    );

    const baseUnitName = normalizeProductUnitKey(
        document.getElementById('base_unit_name')?.value || 'Tablet'
    );

    if (
        unitName === baseUnitName ||
        unitName.includes('tablet') ||
        unitName.includes('capsule') ||
        unitName.includes('piece') ||
        unitName.includes('single')
    ) {
        row.dataset.unitRole = 'base';
        return 'base';
    }

    if (
        unitName.includes('strip') ||
        unitName.includes('box') ||
        unitName.includes('pack') ||
        unitName.includes('packet')
    ) {
        row.dataset.unitRole = 'strip';
        return 'strip';
    }

    return 'other';
}
function deduplicateLoadedProductUnits(units = []) {
    const unitMap = new Map();

    units.forEach(unit => {
        const unitKey = normalizeProductUnitKey(
            unit.unit_name || ''
        );

        if (!unitKey) {
            return;
        }

        const currentQty = parseFloat(
            unit.conversion_qty || 0
        );

        const existing = unitMap.get(unitKey);

        if (!existing) {
            unitMap.set(unitKey, unit);
            return;
        }

        const existingQty = parseFloat(
            existing.conversion_qty || 0
        );

        const currentId = parseInt(
            unit.id || 0,
            10
        );

        const existingId = parseInt(
            existing.id || 0,
            10
        );

        /*
        |--------------------------------------------------------------------------
        | Same unit name duplicate ho
        |--------------------------------------------------------------------------
        | Higher conversion row ko preference milegi.
        | Example: Strip qty 7 ko Strip qty 1 par preference milegi.
        */
        if (
            currentQty > existingQty ||
            (
                currentQty === existingQty &&
                currentId > existingId
            )
        ) {
            unitMap.set(unitKey, unit);
        }
    });

    return Array.from(unitMap.values());
}

function loadProductUnitRows(
    units = [],
    fallbackPrice = '0'
) {
    if (!PHARMACY_UNIT_ENABLED) {
        return;
    }

    const rowsBox =
        document.getElementById(
            'productUnitRows'
        );

    const allowSingleCheckbox =
        document.getElementById(
            'allow_single_unit_sale'
        );

    if (!rowsBox) {
        return;
    }

    rowsBox.innerHTML = '';

    if (
        !allowSingleCheckbox ||
        !allowSingleCheckbox.checked
    ) {
        toggleSingleTabletUnitSection();
        return;
    }

    if (!Array.isArray(units)) {
        units = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Database se aane wali repeated Strip/Tablet rows remove
    |--------------------------------------------------------------------------
    */
    units = deduplicateLoadedProductUnits(
        units
    );

    if (units.length === 0) {
        units = getDefaultPharmacyUnits(
            fallbackPrice,
            '8'
        );
    }

    units.forEach(unit => {
        addProductUnitRow(unit);
    });

    const stripUnit =
        units.find(unit => {
            const unitName =
                normalizeProductUnitKey(
                    unit.unit_name || ''
                );

            return unitName.includes('strip');
        }) ||
        units.find(unit => {
            return (
                parseFloat(
                    unit.conversion_qty || 0
                ) > 1
            );
        });

    if (stripUnit) {
        const stripQtyInput =
            document.getElementById(
                'strip_tablet_qty'
            );

        const stripPriceInput =
            document.getElementById(
                'strip_sale_price'
            );

        if (stripQtyInput) {
            stripQtyInput.value =
                stripUnit.conversion_qty || '8';
        }

        if (stripPriceInput) {
            stripPriceInput.value =
                stripUnit.sale_price || '0';
        }
    }

    const radios =
        rowsBox.querySelectorAll(
            '.unit-default-radio'
        );

    if (
        radios.length > 0 &&
        !Array.from(radios).some(
            radio => radio.checked
        )
    ) {
        radios[0].checked = true;
    }

    toggleSingleTabletUnitSection();
    refreshAllProductUnitRows();
}

function recalculateSingleTabletPricing() {
    if (!PHARMACY_UNIT_ENABLED) return;

    const allowSingleCheckbox =
        document.getElementById(
            'allow_single_unit_sale'
        );

    if (
        !allowSingleCheckbox ||
        !allowSingleCheckbox.checked
    ) {
        return;
    }

    const qtyInput =
        document.getElementById(
            'strip_tablet_qty'
        );

    const stripPriceInput =
        document.getElementById(
            'strip_sale_price'
        );

    let totalTablets =
        parseFloat(qtyInput?.value || '0');

    let stripPrice =
        parseFloat(stripPriceInput?.value || '0');

    if (
        !Number.isFinite(totalTablets) ||
        totalTablets <= 0
    ) {
        totalTablets = 1;

        if (qtyInput) {
            qtyInput.value = '1';
        }
    }

    if (
        !Number.isFinite(stripPrice) ||
        stripPrice < 0
    ) {
        stripPrice = 0;

        if (stripPriceInput) {
            stripPriceInput.value = '0';
        }
    }

    const tabletPrice =
        stripPrice / totalTablets;

    let rows = Array.from(
        document.querySelectorAll(
            '#productUnitRows .product-unit-row'
        )
    );

    /*
    |--------------------------------------------------------------------------
    | Current default preserve karo
    |--------------------------------------------------------------------------
    | Recalculate/submit ke waqt temporary row add hone se selected radio
    | uncheck nahi hoga.
    |--------------------------------------------------------------------------
    */
    const previouslyDefaultRow =
        rows.find(row => {
            return Boolean(
                row.querySelector(
                    '.unit-default-radio'
                )?.checked
            );
        }) || null;

    let stripRow =
        rows.find(row => {
            return (
                row === previouslyDefaultRow &&
                getProductUnitKind(row) === 'strip'
            );
        }) ||
        rows.find(row => {
            return getProductUnitKind(row) === 'strip';
        });

    let tabletRow =
        rows.find(row => {
            return (
                row === previouslyDefaultRow &&
                getProductUnitKind(row) === 'base'
            );
        }) ||
        rows.find(row => {
            return getProductUnitKind(row) === 'base';
        });

    if (!stripRow) {
        const alreadyHasDefault =
            rows.some(row => {
                return Boolean(
                    row.querySelector(
                        '.unit-default-radio'
                    )?.checked
                );
            });

        addProductUnitRow({
            unit_name: 'Strip',
            conversion_qty: String(totalTablets),
            sale_price: stripPrice.toFixed(2),
            barcode: '',
            is_base_unit: 0,
            is_default: alreadyHasDefault ? 0 : 1
        });

        rows = Array.from(
            document.querySelectorAll(
                '#productUnitRows .product-unit-row'
            )
        );

        stripRow =
            rows.find(row => {
                return getProductUnitKind(row) === 'strip';
            });
    }

    if (!tabletRow) {
        const baseUnitName =
            document.getElementById(
                'base_unit_name'
            )?.value.trim() || 'Tablet';

        addProductUnitRow({
            unit_name: baseUnitName,
            conversion_qty: '1',
            sale_price: tabletPrice.toFixed(2),
            barcode: '',
            is_base_unit: 1,
            is_default: 0
        });

        rows = Array.from(
            document.querySelectorAll(
                '#productUnitRows .product-unit-row'
            )
        );

        tabletRow =
            rows.find(row => {
                return getProductUnitKind(row) === 'base';
            });
    }

    if (stripRow) {
        stripRow.dataset.unitRole = 'strip';

        const unitName =
            stripRow.querySelector(
                '.unit-name-input'
            );

        const conversionInput =
            stripRow.querySelector(
                '.unit-conversion-input'
            );

        const priceInput =
            stripRow.querySelector(
                '.unit-price-input'
            );

        /*
        |--------------------------------------------------------------------------
        | Custom unit name preserve
        |--------------------------------------------------------------------------
        | Sirf empty name par default "Strip" lagayega.
        |--------------------------------------------------------------------------
        */
        if (unitName && !unitName.value.trim()) {
            unitName.value = 'Strip';
        }

        if (conversionInput) {
            conversionInput.value =
                String(totalTablets);
        }

        if (priceInput) {
            priceInput.value =
                stripPrice.toFixed(2);
        }
    }

    if (tabletRow) {
        tabletRow.dataset.unitRole = 'base';

        const unitName =
            tabletRow.querySelector(
                '.unit-name-input'
            );

        const conversionInput =
            tabletRow.querySelector(
                '.unit-conversion-input'
            );

        const priceInput =
            tabletRow.querySelector(
                '.unit-price-input'
            );

        /*
        |--------------------------------------------------------------------------
        | Custom base unit name preserve
        |--------------------------------------------------------------------------
        | Sirf empty name par default text lagayega.
        |--------------------------------------------------------------------------
        */
        if (unitName && !unitName.value.trim()) {
            unitName.value =
                document.getElementById(
                    'base_unit_name'
                )?.value.trim() || 'Single Tablet';
        }

        if (conversionInput) {
            conversionInput.value = '1';
        }

        if (priceInput) {
            priceInput.value =
                tabletPrice.toFixed(2);
        }
    }

    const mainPriceInput =
        document.getElementById('price');

    if (mainPriceInput) {
        mainPriceInput.value =
            stripPrice.toFixed(2);
    }

    /*
    |--------------------------------------------------------------------------
    | Existing duplicate cleanup same rahega
    |--------------------------------------------------------------------------
    */
    rows = Array.from(
        document.querySelectorAll(
            '#productUnitRows .product-unit-row'
        )
    );

    let stripKept = false;
    let baseKept = false;

    rows.forEach(row => {
        const kind = getProductUnitKind(row);

        if (kind === 'strip') {
            if (row === stripRow && !stripKept) {
                stripKept = true;
                return;
            }

            row.remove();
            return;
        }

        if (kind === 'base') {
            if (row === tabletRow && !baseKept) {
                baseKept = true;
                return;
            }

            row.remove();
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Default radio restore
    |--------------------------------------------------------------------------
    | Selected row survive kare to wahi default rahe. Agar koi selected na ho
    | to strip row, warna first row default banegi.
    |--------------------------------------------------------------------------
    */
    const finalRows = Array.from(
        document.querySelectorAll(
            '#productUnitRows .product-unit-row'
        )
    );

    if (
        previouslyDefaultRow &&
        finalRows.includes(previouslyDefaultRow)
    ) {
        const previousRadio =
            previouslyDefaultRow.querySelector(
                '.unit-default-radio'
            );

        if (previousRadio) {
            previousRadio.checked = true;
        }
    }

    const defaultStillExists =
        finalRows.some(row => {
            return Boolean(
                row.querySelector(
                    '.unit-default-radio'
                )?.checked
            );
        });

    if (!defaultStillExists && finalRows.length > 0) {
        const fallbackDefaultRow =
            (
                stripRow &&
                finalRows.includes(stripRow)
            )
                ? stripRow
                : finalRows[0];

        const fallbackRadio =
            fallbackDefaultRow.querySelector(
                '.unit-default-radio'
            );

        if (fallbackRadio) {
            fallbackRadio.checked = true;
        }
    }

    refreshAllProductUnitRows();
}
function collectProductUnitRows(formData) {
    if (!PHARMACY_UNIT_ENABLED) {
        return true;
    }

    const baseUnitEl =
        document.getElementById(
            'base_unit_name'
        );

    const allowSingleEl =
        document.getElementById(
            'allow_single_unit_sale'
        );

    let baseUnitName =
        baseUnitEl
            ? baseUnitEl.value.trim()
            : '';

    const allowSingle =
        allowSingleEl &&
        allowSingleEl.checked
            ? '1'
            : '0';

 formData.set(
    'allow_single_unit_sale',
    allowSingle
);

formData.set(
    'base_unit_name',
    allowSingle === '1'
        ? (baseUnitName || 'Tablet')
        : ''
);
    formData.delete('unit_names[]');
    formData.delete('unit_conversion_qty[]');
    formData.delete('unit_sale_prices[]');
    formData.delete('unit_barcodes[]');
    formData.delete('unit_is_base[]');
    formData.delete('unit_is_default[]');
    formData.delete('default_sale_unit_name');

    /*
    |--------------------------------------------------------------------------
    | Checkbox unchecked
    |--------------------------------------------------------------------------
    | Unit box hidden hoga aur unit validation run nahi hogi.
    |--------------------------------------------------------------------------
    */
    if (allowSingle !== '1') {
        return true;
    }

    recalculateSingleTabletPricing();

    const rows = Array.from(
        document.querySelectorAll(
            '#productUnitRows .product-unit-row'
        )
    );

    if (rows.length === 0) {
        alert(
            'Please enter strip and single tablet details.'
        );

        return false;
    }

    let hasValidRow = false;
    let defaultFound = false;
    
    const seenUnitNames = new Set();
let baseUnitAssigned = false;

    for (
        let index = 0;
        index < rows.length;
        index++
    ) {
        const row = rows[index];

        const unitName =
            row.querySelector(
                '.unit-name-input'
            )?.value.trim() || '';

        const conversionQty =
            parseFloat(
                row.querySelector(
                    '.unit-conversion-input'
                )?.value || '0'
            );

        const salePrice =
            parseFloat(
                row.querySelector(
                    '.unit-price-input'
                )?.value || '0'
            );

        const barcode =
            row.querySelector(
                '.unit-barcode-input'
            )?.value.trim() || '';

        const isDefault =
            row.querySelector(
                '.unit-default-radio'
            )?.checked
                ? '1'
                : '0';

        if (unitName === '') {
            continue;
        }
        
        const unitKey =
    normalizeProductUnitKey(unitName);

if (seenUnitNames.has(unitKey)) {
    alert(
        `Duplicate unit "${unitName}" remove karein. ` +
        `Har product mein ek unit name sirf ek dafa ho sakta hai.`
    );

    return false;
}

seenUnitNames.add(unitKey);

        if (
            !Number.isFinite(conversionQty) ||
            conversionQty <= 0
        ) {
            alert(
                'Unit conversion quantity 0 se zyada honi chahiye.'
            );

            return false;
        }

        if (
            !Number.isFinite(salePrice) ||
            salePrice < 0
        ) {
            alert(
                'Unit selling price invalid hai.'
            );

            return false;
        }

   const rowKind =
    getProductUnitKind(row);

const isBase =
    !baseUnitAssigned &&
    rowKind === 'base'
        ? '1'
        : '0';

if (isBase === '1') {
    baseUnitAssigned = true;
}

        if (isDefault === '1') {
            defaultFound = true;

            formData.set(
                'default_sale_unit_name',
                unitName
            );
        }

        formData.append(
            'unit_names[]',
            unitName
        );

        formData.append(
            'unit_conversion_qty[]',
            String(conversionQty)
        );

        formData.append(
            'unit_sale_prices[]',
            String(salePrice)
        );

        formData.append(
            'unit_barcodes[]',
            barcode
        );

        formData.append(
            'unit_is_base[]',
            isBase
        );

        formData.append(
            'unit_is_default[]',
            isDefault
        );

        hasValidRow = true;
    }

    if (!hasValidRow) {
        alert(
            'Please enter at least one valid unit.'
        );

        return false;
    }

    if (!defaultFound) {
        alert(
            'Please select default sale unit.'
        );

        return false;
    }

    return true;
}

function addWholesaleRow(label = '', price = '0') {
    const rowsBox = document.getElementById('wholesalePriceRows');
    if (!rowsBox) return;

    const count = rowsBox.querySelectorAll('.wholesale-row').length + 1;
    const finalLabel = label || `Wholesale${count}`;
    const finalPrice = price !== '' && price !== null && price !== undefined ? price : '0';

    const row = document.createElement('div');
    row.className = 'wholesale-row';

    row.innerHTML = `
        <input
            type="text"
            name="wholesale_labels[]"
            class="item-input dynamic-wholesale-label"
            value="${escapeHtmlForAttr(finalLabel)}"
            placeholder="Wholesale name"
            oninput="syncFirstThreeWholesaleFields()"
        >

        <div class="price-wrap">
            <div class="price-prefix">Rs</div>
            <input
                type="number"
                step="0.01"
                name="wholesale_prices[]"
                class="item-input price-input dynamic-wholesale-price"
                value="${escapeHtmlForAttr(finalPrice)}"
                placeholder="0.00"
                oninput="syncFirstThreeWholesaleFields()"
            >
        </div>

        <button type="button" class="remove-wholesale-btn" onclick="removeWholesaleRow(this)">
            <i class="bi bi-trash"></i>
        </button>
    `;

    rowsBox.appendChild(row);
    syncFirstThreeWholesaleFields();
}

function removeWholesaleRow(btn) {
    const row = btn.closest('.wholesale-row');
    if (row) {
        row.remove();
    }

    syncFirstThreeWholesaleFields();

    const rowsBox = document.getElementById('wholesalePriceRows');
    const hasWholesale = document.getElementById('has_wholesale');

    if (hasWholesale && hasWholesale.checked && rowsBox && rowsBox.querySelectorAll('.wholesale-row').length === 0) {
        addWholesaleRow('Wholesale1', '0');
    }
}

function getWholesaleRows() {
    const rows = Array.from(document.querySelectorAll('#wholesalePriceRows .wholesale-row'));

    return rows.map((row, index) => {
        const labelInput = row.querySelector('.dynamic-wholesale-label');
        const priceInput = row.querySelector('.dynamic-wholesale-price');

        let label = labelInput ? labelInput.value.trim() : '';
        let price = priceInput && priceInput.value !== '' ? priceInput.value : '0';

        if (label === '') {
            label = `Wholesale${index + 1}`;
        }

        return { label, price };
    });
}

function syncFirstThreeWholesaleFields() {
    const rows = getWholesaleRows();

    const setVal = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.value = value;
    };

    setVal('wholesaler_price_1', rows[0] ? rows[0].price : '0');
    setVal('wholesaler_price_2', rows[1] ? rows[1].price : '0');
    setVal('wholesaler_price_3', rows[2] ? rows[2].price : '0');
}

function loadWholesaleRows(rows = []) {
    const rowsBox = document.getElementById('wholesalePriceRows');
    if (!rowsBox) return;

    rowsBox.innerHTML = '';

    rows.forEach((row, index) => {
        addWholesaleRow(row.label || `Wholesale${index + 1}`, row.price || '0');
    });

    syncFirstThreeWholesaleFields();
}

function resetItemForm(isRaw = false) {
    const form = document.getElementById('itemForm');
    if (form) form.reset();

    const setVal = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.value = value;
    };

    const setChecked = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.checked = value;
    };

    setVal('item_id', '');
    setVal('is_raw', isRaw ? '1' : '0');
    setVal('description', '');
    setVal('name', '');
    setVal('sku', '');
    setVal('type', 'product');
    setVal('price', '0');
    setVal('cost', '0');
    setVal('wholesaler_price_1', '0');
    setVal('wholesaler_price_2', '0');
    setVal('wholesaler_price_3', '0');
    loadWholesaleRows([]);
    setVal('track_inventory', '1');
    setVal('stock_qty', '0');
    
if (PHARMACY_UNIT_ENABLED) {
    setVal(
        'base_unit_name',
        'Tablet'
    );

    setChecked(
        'allow_single_unit_sale',
        false
    );

    setVal(
        'strip_tablet_qty',
        '8'
    );

    setVal(
        'strip_sale_price',
        '0'
    );

    const rowsBox =
        document.getElementById(
            'productUnitRows'
        );

    if (rowsBox) {
        rowsBox.innerHTML = '';
    }

    toggleSingleTabletUnitSection();
}
    setVal('image_url', '');
    setVal('supplier_id', '');
    setVal('category', '');
    setVal('expiry_date', '');
    setVal('variant_details', '');
    setVal('discount_type', 'percent');
    setVal('discount_value', '0');

    setChecked('has_variants', false);
    setChecked('has_expiry', false);
    setChecked('has_discount', false);
    setChecked('has_wholesale', false);

    const submitBtn = document.getElementById('itemSubmitBtn');
    if (submitBtn) submitBtn.textContent = 'Save Item';

    const modalTitle = document.getElementById('modalTitle');
    if (modalTitle) modalTitle.innerText = isRaw ? 'Add Raw Item' : 'Add New Product';

    const footerNote = document.getElementById('itemFooterNote');
    if (footerNote) {
        footerNote.innerText = isRaw ? 'Create a raw item entry.' : 'Create or update your item details.';
    }

    setUploadPreview('');
    toggleProductFields(!isRaw);
    toggleItemExtraOptions();
}

function openItemModal(isRaw = false) {
    clearItemFormMessage();
    resetItemForm(isRaw);
    itemModal.show();
}

function toggleProductFields(show) {
    document.querySelectorAll('.product-only').forEach(el => {
        el.style.display = show ? '' : 'none';
    });

    const note = document.getElementById('itemFooterNote');
    if (!show) {
        note.innerText = 'Raw item mode active. Product-only fields hidden.';
    }
}

function selectAllRows() {
    document.querySelectorAll('input[name="item_ids[]"]').forEach(chk => {
        if (chk.closest('tr') && chk.closest('tr').style.display !== 'none') {
            chk.checked = true;
        }
    });
}

function showItemsNotice(message) {
    let toast =
        document.getElementById(
            'duplicateItemsNotice'
        );

    if (!toast) {
        toast =
            document.createElement('div');

        toast.id =
            'duplicateItemsNotice';

        toast.className =
            'item-copy-toast';

        toast.innerHTML = `
            <i class="bi bi-files"></i>
            <span></span>
        `;

        document.body.appendChild(toast);
    }

    const messageElement =
        toast.querySelector('span');

    if (messageElement) {
        messageElement.textContent =
            String(message || '');
    }

    toast.classList.add('show');

    clearTimeout(
        window.duplicateItemsNoticeTimer
    );

    window.duplicateItemsNoticeTimer =
        setTimeout(function () {
            toast.classList.remove('show');
        }, 2600);
}

function selectDuplicateCopiesOnCurrentPage(
    showNotice = true
) {
    const duplicateCheckboxes =
        Array.from(
            document.querySelectorAll(
                '#itemsTableBody ' +
                'tr[data-duplicate-copy="1"] ' +
                'input[name="item_ids[]"]'
            )
        );

    unselectAllRows();

    let selectedCount = 0;

    duplicateCheckboxes.forEach(
        function (checkbox) {
            const row =
                checkbox.closest('tr');

            if (
                row &&
                row.style.display !== 'none'
            ) {
                checkbox.checked = true;
                selectedCount++;
            }
        }
    );

    const selectAllCheckbox =
        document.getElementById(
            'selectAll'
        );

    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
    }

    if (showNotice) {
        if (selectedCount > 0) {
            showItemsNotice(
                selectedCount +
                ' duplicate copies selected.'
            );
        } else {
            showItemsNotice(
                'No duplicate copies found on this page.'
            );
        }
    }

    return selectedCount;
}

function selectDuplicateCopies() {
    const currentUrl =
        new URL(window.location.href);

    /*
    |--------------------------------------------------------------------------
    | Quick Action ko actual duplicate filter bhi banao
    |--------------------------------------------------------------------------
    | User kisi bhi page par ho, pehle duplicate groups load hongi.
    | Load ke baad duplicate copies automatically select ho jayengi.
    |--------------------------------------------------------------------------
    */
    if (
        currentUrl.searchParams.get(
            'duplicates'
        ) !== '1'
    ) {
        currentUrl.searchParams.set(
            'duplicates',
            '1'
        );

        currentUrl.searchParams.set(
            'select_duplicates',
            '1'
        );

        currentUrl.searchParams.set(
            'page',
            '1'
        );

        window.location.href =
            currentUrl.toString();

        return;
    }

    selectDuplicateCopiesOnCurrentPage(
        true
    );
}

/*
|--------------------------------------------------------------------------
| Quick Action redirect ke baad auto selection
|--------------------------------------------------------------------------
*/
(function autoSelectDuplicateCopies() {
    const currentUrl =
        new URL(window.location.href);

    if (
        currentUrl.searchParams.get(
            'select_duplicates'
        ) !== '1'
    ) {
        return;
    }

    setTimeout(function () {
        selectDuplicateCopiesOnCurrentPage(
            true
        );

        currentUrl.searchParams.delete(
            'select_duplicates'
        );

        window.history.replaceState(
            {},
            '',
            currentUrl.toString()
        );
    }, 0);
})();

function unselectAllRows() {
    document.querySelectorAll('input[name="item_ids[]"]').forEach(chk => chk.checked = false);
    document.getElementById('selectAll').checked = false;
}

function deleteSelected() {
    const selected = Array.from(document.querySelectorAll('input[name="item_ids[]"]:checked')).map(el => el.value);

    if (selected.length === 0) {
        alert('Please select at least one item.');
        return;
    }

    if (!confirm('Are you sure you want to delete selected items?')) {
        return;
    }

    const formData = new FormData();
    formData.append('delete_item_ids', JSON.stringify(selected));

    fetch('ajax_items.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Bulk delete failed.');
        }
    })
    .catch(() => alert('Bulk delete request failed.'));
}

document.getElementById('selectAll').addEventListener('change', function () {
    document.querySelectorAll('input[name="item_ids[]"]').forEach(chk => {
        if (chk.closest('tr') && chk.closest('tr').style.display !== 'none') {
            chk.checked = this.checked;
        }
    });
});

document
    .getElementById('image')
    .addEventListener(
        'change',
        function () {
            const file =
                this.files &&
                this.files[0];

            if (!file) {
                setUploadPreview('');
                return;
            }

            applyProductImageFile(file);
        }
    );

document
    .getElementById('itemModal')
    .addEventListener(
        'paste',
        handleProductImagePaste
    );

document
    .getElementById('pasteImageBox')
    ?.addEventListener(
        'click',
        function () {
            this.focus();
        }
    );

document.getElementById('type').addEventListener('change', function () {
    const selectedType = (this.value || '').toLowerCase();
    const isRaw = document.getElementById('is_raw').value === '1';

    if (!isRaw) {
        toggleProductFields(selectedType !== 'service');
    }
});

document.querySelectorAll('.editItem').forEach(icon => {
    icon.addEventListener('click', function () {
        const isRaw = this.dataset.is_raw === '1';

        const setVal = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.value = value;
        };

        const setChecked = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.checked = value;
        };

        const form = document.getElementById('itemForm');
        if (form) form.reset();

        setVal('item_id', this.dataset.id || '');
        setVal('name', this.dataset.name || '');
        setVal('description', this.dataset.description || '');
        setVal('sku', this.dataset.sku || '');
        setVal('type', this.dataset.type || 'product');
        setVal('price', this.dataset.price || '0');
        setVal('cost', this.dataset.cost || '0');
        setVal('wholesaler_price_1', this.dataset.wholesaler_price_1 || '0');
        setVal('wholesaler_price_2', this.dataset.wholesaler_price_2 || '0');
        setVal('wholesaler_price_3', this.dataset.wholesaler_price_3 || '0');
        setVal('is_raw', isRaw ? '1' : '0');
        setVal('track_inventory', this.dataset.track_inventory || '1');
        setVal('stock_qty', this.dataset.stock_qty || '0');
       if (PHARMACY_UNIT_ENABLED) {
    setVal(
        'base_unit_name',
        this.dataset.base_unit_name || 'Tablet'
    );

    setChecked(
        'allow_single_unit_sale',
        this.dataset.allow_single_unit_sale === '1'
    );

    let productUnits = [];

    try {
        productUnits = JSON.parse(
            this.dataset.product_units || '[]'
        );
    } catch (error) {
        productUnits = [];
    }

    if (!Array.isArray(productUnits)) {
        productUnits = [];
    }

    loadProductUnitRows(
        productUnits,
        this.dataset.price || '0'
    );

    toggleSingleTabletUnitSection();
}
        setVal('supplier_id', this.dataset.supplier_id || '');
        setVal('category', this.dataset.category || '');
        setVal('expiry_date', this.dataset.expiry_date || '');
        setVal('variant_details', this.dataset.variant_details || '');

        setChecked('has_variants', this.dataset.has_variants === '1');
        setChecked('has_expiry', this.dataset.has_expiry === '1');

        const maxDiscountPercent = this.dataset.max_discount_percent || this.dataset.discount_value || '0';
        const hasMaxDiscountValue = this.dataset.has_max_discount === '1' || this.dataset.has_discount === '1' || parseFloat(maxDiscountPercent || 0) > 0;

        setChecked('has_discount', hasMaxDiscountValue);
        setVal('discount_type', 'percent');
        setVal('discount_value', maxDiscountPercent || '0');

        let wholesaleRows = [];

try {
    wholesaleRows = JSON.parse(this.dataset.wholesale_prices || '[]');
} catch (err) {
    wholesaleRows = [];
}

if (!Array.isArray(wholesaleRows)) {
    wholesaleRows = [];
}

if (wholesaleRows.length === 0) {
    const legacyRows = [
        { label: 'Wholesale1', price: this.dataset.wholesaler_price_1 || '0' },
        { label: 'Wholesale2', price: this.dataset.wholesaler_price_2 || '0' },
        { label: 'Wholesale3', price: this.dataset.wholesaler_price_3 || '0' }
    ];

    wholesaleRows = legacyRows.filter(row => parseFloat(row.price || 0) > 0);
}

const hasWholesaleValue = this.dataset.has_wholesale === '1' || wholesaleRows.length > 0;

setChecked('has_wholesale', hasWholesaleValue);
loadWholesaleRows(wholesaleRows);

        const modalTitle = document.getElementById('modalTitle');
        if (modalTitle) modalTitle.innerText = isRaw ? 'Edit Raw Item' : 'Edit Product';

        const submitBtn = document.getElementById('itemSubmitBtn');
        if (submitBtn) submitBtn.textContent = 'Update Item';

        const footerNote = document.getElementById('itemFooterNote');
        if (footerNote) footerNote.innerText = isRaw ? 'Update raw item details.' : 'Update your item details.';

        setUploadPreview(this.dataset.image || '');
        toggleProductFields(!isRaw);
        toggleItemExtraOptions();

        itemModal.show();
    });
});


/* =========================================================
   INLINE IMAGE URL EDITING
========================================================= */

document
    .getElementById('itemsTableBody')
    ?.addEventListener('dblclick', function (event) {

        const imageTrigger =
            event.target.closest('.inline-image-trigger');

        if (!imageTrigger) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const imageCell =
            imageTrigger.closest('.item-image-cell');

        if (
            !imageCell ||
            imageCell.dataset.editing === '1'
        ) {
            return;
        }

        const itemId =
            imageTrigger.dataset.itemId || '';

        const currentImage =
            (
                imageTrigger.dataset.currentImage || ''
            ).trim();

        if (!itemId) {
            alert('Invalid item ID.');
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Cancel par original image wapas lane ke liye HTML save
        |--------------------------------------------------------------------------
        */
        const originalHtml =
            imageCell.innerHTML;

        imageCell.dataset.editing = '1';
        imageCell.classList.add('is-editing');

        imageCell.innerHTML = `
            <div class="inline-image-editor">

                <textarea
                    class="inline-image-url-input"
                    placeholder="Direct image URL paste karein...&#10;https://example.com/product.jpg"
                ></textarea>

                <div class="inline-image-editor-actions">

                    <button
                        type="button"
                        class="inline-image-save"
                    >
                        <i class="bi bi-check-lg me-1"></i>
                        Save
                    </button>

                    <button
                        type="button"
                        class="inline-image-cancel"
                    >
                        Cancel
                    </button>

                </div>

            </div>
        `;

        const urlInput =
            imageCell.querySelector(
                '.inline-image-url-input'
            );

        const saveButton =
            imageCell.querySelector(
                '.inline-image-save'
            );

        const cancelButton =
            imageCell.querySelector(
                '.inline-image-cancel'
            );

        /*
        |--------------------------------------------------------------------------
        | Existing image URL ho to input mein show karo.
        | Local uploaded filename ko URL input mein show nahi karna.
        |--------------------------------------------------------------------------
        */
        if (
            urlInput &&
            /^https?:\/\//i.test(currentImage)
        ) {
            urlInput.value = currentImage;
        }

        const cancelEditing = function () {
            imageCell.innerHTML = originalHtml;

            imageCell.classList.remove(
                'is-editing'
            );

            delete imageCell.dataset.editing;
        };

        cancelButton?.addEventListener(
            'click',
            cancelEditing
        );

        saveButton?.addEventListener(
            'click',
            async function () {

                const imageUrl =
                    urlInput
                        ? urlInput.value.trim()
                        : '';

                if (!imageUrl) {
                    alert(
                        'Image URL paste karein.'
                    );

                    urlInput?.focus();
                    return;
                }

                if (
                    !/^https?:\/\//i.test(imageUrl)
                ) {
                    alert(
                        'Image URL http:// ya https:// se start honi chahiye.'
                    );

                    urlInput?.focus();
                    return;
                }

                const formData =
                    new FormData();

                formData.set(
                    'action',
                    'update_item_image_url'
                );

                formData.set(
                    'item_id',
                    itemId
                );

                formData.set(
                    'image_url',
                    imageUrl
                );

                saveButton.disabled = true;
                saveButton.innerHTML = `
                    <span
                        class="spinner-border spinner-border-sm me-1"
                    ></span>
                    Saving
                `;

                try {

                    const response = await fetch(
                        '/onerp/app/public/app/views/ajax_items.php?v=' +
                        Date.now(),
                        {
                            method: 'POST',
                            body: formData,
                            cache: 'no-store'
                        }
                    );

                    const responseText =
                        await response.text();

                    let data;

                    try {
                        data =
                            JSON.parse(responseText);
                    } catch (parseError) {

                        console.error(
                            'Server raw response:',
                            responseText
                        );

                        throw new Error(
                            'Server response JSON nahi hai.'
                        );
                    }

                    if (!data.success) {
                        throw new Error(
                            data.message ||
                            'Image save failed.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Reload ke baad database wali final image show hogi
                    |--------------------------------------------------------------------------
                    */
                    window.location.reload();

                } catch (error) {

                    console.error(error);

                    alert(
                        error.message ||
                        'Image save request failed.'
                    );

                    saveButton.disabled = false;
                    saveButton.innerHTML = `
                        <i class="bi bi-check-lg me-1"></i>
                        Save
                    `;
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Escape = Cancel
        | Ctrl + Enter = Save
        |--------------------------------------------------------------------------
        */
        urlInput?.addEventListener(
            'keydown',
            function (keyboardEvent) {

                if (
                    keyboardEvent.key === 'Escape'
                ) {
                    keyboardEvent.preventDefault();
                    cancelEditing();
                }

                if (
                    keyboardEvent.ctrlKey &&
                    keyboardEvent.key === 'Enter'
                ) {
                    keyboardEvent.preventDefault();
                    saveButton?.click();
                }
            }
        );

        setTimeout(function () {
            urlInput?.focus();
            urlInput?.select();
        }, 50);
    });

/* =========================================================
   CLICK PRODUCT NAME TO COPY
========================================================= */

function copyItemTextToClipboard(text) {

    /*
    |--------------------------------------------------------------------------
    | Modern browser clipboard
    |--------------------------------------------------------------------------
    */
    if (
        navigator.clipboard &&
        window.isSecureContext
    ) {
        return navigator.clipboard.writeText(text);
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback for older browsers
    |--------------------------------------------------------------------------
    */
    return new Promise(function (resolve, reject) {

        const textarea =
            document.createElement('textarea');

        textarea.value = text;
        textarea.setAttribute('readonly', '');

        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        textarea.style.opacity = '0';

        document.body.appendChild(textarea);

        textarea.focus();
        textarea.select();

        try {
            const copied =
                document.execCommand('copy');

            if (!copied) {
                throw new Error(
                    'Browser copy command failed.'
                );
            }

            resolve();

        } catch (error) {
            reject(error);

        } finally {
            textarea.remove();
        }
    });
}


function showItemCopyToast(itemName) {

    let toast =
        document.getElementById('itemCopyToast');

    if (!toast) {
        toast =
            document.createElement('div');

        toast.id = 'itemCopyToast';
        toast.className = 'item-copy-toast';

        toast.innerHTML = `
            <i class="bi bi-clipboard-check"></i>
            <span></span>
        `;

        document.body.appendChild(toast);
    }

    const message =
        toast.querySelector('span');

    if (message) {
        message.textContent =
            `"${itemName}" copied`;
    }

    toast.classList.add('show');

    clearTimeout(
        window.itemCopyToastTimer
    );

    window.itemCopyToastTimer =
        setTimeout(function () {
            toast.classList.remove('show');
        }, 1800);
}


document
    .getElementById('itemsTableBody')
    ?.addEventListener('click', async function (event) {

        const copyButton =
            event.target.closest('.copy-item-name');

        if (!copyButton) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const itemName =
            (
                copyButton.dataset.copyText || ''
            ).trim();

        if (!itemName) {
            return;
        }

        const icon =
            copyButton.querySelector(
                '.copy-item-name-icon'
            );

        const originalIconClass =
            icon
                ? icon.className
                : '';

        try {

            await copyItemTextToClipboard(
                itemName
            );

            copyButton.classList.add(
                'is-copied'
            );

            copyButton.title =
                'Item name copied';

            if (icon) {
                icon.className =
                    'bi bi-check2 copy-item-name-icon';
            }

            showItemCopyToast(itemName);

            setTimeout(function () {

                copyButton.classList.remove(
                    'is-copied'
                );

                copyButton.title =
                    'Click to copy item name';

                if (icon) {
                    icon.className =
                        originalIconClass;
                }

            }, 1500);

        } catch (error) {

            console.error(
                'Product name copy failed:',
                error
            );

            alert(
                'Item name copy nahi ho saka.'
            );
        }
    });
    
    
    /* =========================================================
   PRODUCT ACTIVE / INACTIVE STATUS
========================================================= */

document
    .querySelectorAll('.item-status-select')
    .forEach(function (statusSelect) {

        statusSelect.addEventListener(
            'change',
            async function () {

                const selectElement = this;

                const itemId = parseInt(
                    selectElement.dataset.itemId || '0',
                    10
                );

                const previousStatus =
                    selectElement.dataset.previousStatus ||
                    'active';

                const newStatus =
                    (
                        selectElement.value ||
                        ''
                    ).toLowerCase();

                if (
                    itemId <= 0 ||
                    !['active', 'inactive'].includes(
                        newStatus
                    )
                ) {
                    selectElement.value =
                        previousStatus;

                    alert(
                        'Invalid product status.'
                    );

                    return;
                }

                if (newStatus === previousStatus) {
                    return;
                }

                const formData =
                    new FormData();

                formData.set(
                    'action',
                    'update_item_status'
                );

                formData.set(
                    'item_id',
                    String(itemId)
                );

                formData.set(
                    'status',
                    newStatus
                );

                selectElement.disabled = true;

                try {

                 const response = await fetch(
    'ajax_update_item_status.php?v=' +
    Date.now(),
    {
        method: 'POST',
        body: formData,
        cache: 'no-store'
    }
);

                    if (response.status === 401) {
                        window.location.href =
                            '/onerp/app/onerp/app/public/app/views/login_view.php';

                        return;
                    }

                    const responseText =
                        await response.text();

                    let data;

                    try {
                        data =
                            JSON.parse(
                                responseText
                            );
                    } catch (parseError) {

                        console.error(
                            'Status raw response:',
                            responseText
                        );

                        throw new Error(
                            'Server response JSON nahi hai.'
                        );
                    }

                    if (
                        !response.ok ||
                        !data.success
                    ) {
                        throw new Error(
                            data.message ||
                            'Product status update failed.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | New status save
                    |--------------------------------------------------------------------------
                    */

                    selectElement.dataset.previousStatus =
                        newStatus;

                    selectElement.classList.toggle(
                        'is-active',
                        newStatus === 'active'
                    );

                    selectElement.classList.toggle(
                        'is-inactive',
                        newStatus === 'inactive'
                    );

                    const productRow =
                        selectElement.closest(
                            '.item-row'
                        );

                    if (productRow) {
                        productRow.classList.toggle(
                            'item-row-inactive',
                            newStatus === 'inactive'
                        );
                    }

                    alert(
                        newStatus === 'active'
                            ? 'Product Active ho gaya hai.'
                            : 'Product Inactive ho gaya hai aur POS par show nahi hoga.'
                    );

                } catch (error) {

                    console.error(
                        'Product status update error:',
                        error
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Error par purana status restore
                    |--------------------------------------------------------------------------
                    */

                    selectElement.value =
                        previousStatus;

                    selectElement.classList.toggle(
                        'is-active',
                        previousStatus === 'active'
                    );

                    selectElement.classList.toggle(
                        'is-inactive',
                        previousStatus === 'inactive'
                    );

                    alert(
                        error.message ||
                        'Product status update request failed.'
                    );

                } finally {

                    selectElement.disabled =
                        false;
                }
            }
        );
    });

document.querySelectorAll('.deleteItem').forEach(icon => {
    icon.addEventListener('click', function () {
        const id = this.dataset.id;

        if (!id) {
            alert('Invalid item id.');
            return;
        }

        if (!confirm('Are you sure you want to delete this item?')) return;

        const formData = new FormData();
        formData.append('delete_item_id', id);

        fetch('ajax_items.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message || 'Delete failed.');
            }
        })
        .catch(() => alert('Delete request failed.'));
    });
});

function clearItemFormMessage() {
    const messageBox =
        document.getElementById(
            'itemFormMessage'
        );

    if (messageBox) {
        messageBox.hidden = true;
        messageBox.replaceChildren();
    }

    document
        .getElementById('name')
        ?.classList.remove(
            'item-input-duplicate-error'
        );

    document
        .getElementById('sku')
        ?.classList.remove(
            'item-input-duplicate-error'
        );
}

function showItemFormMessage(
    message,
    duplicateItemId = 0,
    duplicateField = ''
) {
    const messageBox =
        document.getElementById(
            'itemFormMessage'
        );

    if (!messageBox) {
        return;
    }

    messageBox.replaceChildren();

    const messageText =
        document.createElement('span');

    messageText.textContent =
        message ||
        'Product save nahi ho saka.';

    messageBox.appendChild(
        messageText
    );

    if (duplicateItemId > 0) {
        const duplicateLink =
            document.createElement('a');

        const duplicateUrl =
            new URL(
                window.location.href
            );

        duplicateUrl.searchParams.set(
            'duplicates',
            '1'
        );

        duplicateUrl.searchParams.set(
            'page',
            '1'
        );

        duplicateLink.href =
            duplicateUrl.toString();

        duplicateLink.textContent =
            'View Duplicates';

        messageBox.appendChild(
            duplicateLink
        );
    }

    messageBox.hidden = false;

    /*
    |--------------------------------------------------------------------------
    | Old backend compatibility
    |--------------------------------------------------------------------------
    | ajax_items.php duplicate_field na bheje to response message se field
    | identify hogi. Backend workflow ko change nahi kiya gaya.
    |--------------------------------------------------------------------------
    */
    if (
        duplicateField !== 'name' &&
        duplicateField !== 'sku'
    ) {
        duplicateField =
            /\bsku\b/i.test(
                String(message || '')
            )
                ? 'sku'
                : 'name';
    }

    const duplicateInput =
        duplicateField === 'sku'
            ? document.getElementById('sku')
            : document.getElementById('name');

    if (duplicateInput) {
        duplicateInput.classList.add(
            'item-input-duplicate-error'
        );

        duplicateInput.focus();
    }
}

['name', 'sku'].forEach(function (fieldId) {
    document
        .getElementById(fieldId)
        ?.addEventListener(
            'input',
            clearItemFormMessage
        );
});

document.getElementById('itemForm').addEventListener('submit', function(e){
    e.preventDefault();

    clearItemFormMessage();

    const form = this;
    const formData = new FormData(form);

    // IMPORTANT: cost ko manually FormData me set kar rahe hain,
    // taake hidden/old/cached form issue ki wajah se 0 na jaye.
    const priceEl = document.getElementById('price');
    const costEl = document.getElementById('cost');
    const wholesale1El = document.getElementById('wholesaler_price_1');
    const wholesale2El = document.getElementById('wholesaler_price_2');
    const wholesale3El = document.getElementById('wholesaler_price_3');
    const stockEl = document.getElementById('stock_qty');
    const imageUrlEl = document.getElementById('image_url');
    const supplierEl = document.getElementById('supplier_id');
    const categoryEl = document.getElementById('category');
    const hasVariantsEl = document.getElementById('has_variants');
    const hasExpiryEl = document.getElementById('has_expiry');
    const hasWholesaleEl = document.getElementById('has_wholesale');
    const hasDiscountEl = document.getElementById('has_discount');
    const discountTypeEl = document.getElementById('discount_type');
    const discountValueEl = document.getElementById('discount_value');
    const expiryDateEl = document.getElementById('expiry_date');
    const variantDetailsEl = document.getElementById('variant_details');

    syncFirstThreeWholesaleFields();
    const wholesaleRows = getWholesaleRows();

    const priceValue = priceEl && priceEl.value !== '' ? priceEl.value : '0';
    const costValue = costEl && costEl.value !== '' ? costEl.value : '0';
    const wholesale1Value = wholesale1El && wholesale1El.value !== '' ? wholesale1El.value : '0';
    const wholesale2Value = wholesale2El && wholesale2El.value !== '' ? wholesale2El.value : '0';
    const wholesale3Value = wholesale3El && wholesale3El.value !== '' ? wholesale3El.value : '0';
    const stockValue = stockEl && stockEl.value !== '' ? stockEl.value : '0';
    const imageUrlValue = imageUrlEl && imageUrlEl.value !== '' ? imageUrlEl.value.trim() : '';
    const supplierValue = supplierEl && supplierEl.value !== '' ? supplierEl.value : '';
    const categoryValue = categoryEl && categoryEl.value !== '' ? categoryEl.value.trim() : '';
    const discountTypeValue = 'percent';
    let discountValue = discountValueEl && discountValueEl.value !== '' ? discountValueEl.value : '0';

    if (!(hasDiscountEl && hasDiscountEl.checked)) {
        discountValue = '0';
    }

    let maxDiscountNumber = parseFloat(discountValue || '0');
    if (isNaN(maxDiscountNumber) || maxDiscountNumber < 0) {
        maxDiscountNumber = 0;
    }
    if (maxDiscountNumber > 100) {
        alert('Maximum discount 100% se zyada nahi ho sakta.');
        return;
    }
    discountValue = String(maxDiscountNumber);

    formData.set('action', 'save');
    formData.set('price', priceValue); // Retail
    formData.set('cost', costValue);   // Purchase
    formData.set('wholesaler_price_1', wholesale1Value);
    formData.set('wholesaler_price_2', wholesale2Value);
    formData.set('wholesaler_price_3', wholesale3Value);
    formData.set('has_wholesale', hasWholesaleEl && hasWholesaleEl.checked ? '1' : '0');

    formData.delete('wholesale_labels[]');
    formData.delete('wholesale_prices[]');

    if (hasWholesaleEl && hasWholesaleEl.checked) {
        wholesaleRows.forEach(row => {
            formData.append('wholesale_labels[]', row.label);
            formData.append('wholesale_prices[]', row.price);
        });
    }
    formData.set('stock_qty', stockValue);
    try {
    if (!collectProductUnitRows(formData)) {
        return;
    }
} catch (err) {
    console.error(err);
    return;
}
    
    formData.set('image_url', imageUrlValue);
    formData.set('supplier_id', supplierValue);
    formData.set('category', categoryValue);
    formData.set('has_variants', hasVariantsEl && hasVariantsEl.checked ? '1' : '0');
    formData.set('has_expiry', hasExpiryEl && hasExpiryEl.checked ? '1' : '0');
    formData.set('has_discount', hasDiscountEl && hasDiscountEl.checked ? '1' : '0');
    formData.set('discount_type', discountTypeValue);
    formData.set('discount_value', discountValue);

    // Maximum Discount aliases for new backend workflow.
    // Old ajax_items.php compatibility ke liye has_discount/discount_value bhi same rahenge.
    formData.set('has_max_discount', hasDiscountEl && hasDiscountEl.checked ? '1' : '0');
    formData.set('max_discount_percent', discountValue);
    formData.set('expiry_date', expiryDateEl ? expiryDateEl.value : '');
    formData.set('variant_details', variantDetailsEl ? variantDetailsEl.value.trim() : '');
    // Extra aliases: agar backend/old ajax kisi aur key se read kar raha ho to bhi value milegi.
    formData.set('retail_price', priceValue);
    formData.set('item_cost', costValue);
    formData.set('cost_price', costValue);
    formData.set('purchase_price', costValue);
    formData.set('wholesale1', wholesale1Value);
    formData.set('wholesale2', wholesale2Value);
    formData.set('wholesale3', wholesale3Value);

    const submitButton =
        document.getElementById(
            'itemSubmitBtn'
        );

    const originalButtonText =
        submitButton
            ? submitButton.innerHTML
            : '';

    if (submitButton) {
        submitButton.disabled = true;

        submitButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>' +
            'Saving...';
    }

    fetch(
        '/onerp/app/public/app/views/ajax_items.php?v=' +
        Date.now(),
        {
            method: 'POST',
            body: formData,
            cache: 'no-store'
        }
    )
    .then(async function (response) {
        const responseText =
            await response.text();

        let data;

        try {
            data = JSON.parse(
                responseText
            );
        } catch (parseError) {
            console.error(
                'Server raw response:',
                responseText
            );

            throw new Error(
                'Server response JSON nahi hai.'
            );
        }

        return {
            response,
            data
        };
    })
    .then(function (result) {
        const response = result.response;
        const data = result.data;

        if (
            !response.ok ||
            !data.success
        ) {
            showItemFormMessage(
                data.message ||
                'Product save nahi ho saka.',

                parseInt(
                    data.duplicate_item_id || '0',
                    10
                ),

                data.duplicate_field || ''
            );

            return;
        }

        window.location.reload();
    })
    .catch(function (error) {
        console.error(
            'Item save error:',
            error
        );

        showItemFormMessage(
            error.message ||
            'Save request failed.'
        );
    })
    .finally(function () {
        if (submitButton) {
            submitButton.disabled = false;

            submitButton.innerHTML =
                originalButtonText;
        }
    });
});

document.getElementById('importForm').addEventListener('submit', function(e){
    e.preventDefault();

    const form = this;
    const fileInput = document.getElementById('csv_file');

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        alert('Please select CSV file.');
        return;
    }

    const formData = new FormData(form);
    formData.set('action', 'import');
    formData.set('csv_file', fileInput.files[0]);

    const submitBtn = form.querySelector('button[type="submit"]');
    const oldText = submitBtn ? submitBtn.innerHTML : '';

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Importing...';
    }

   fetch('/onerp/app/public/app/views/ajax_items.php?v=' + Date.now(), {
        method: 'POST',
        body: formData,
        cache: 'no-store'
    })
    .then(async function(res) {
        const text = await res.text();

        let data;
        try {
            data = JSON.parse(text);
        } catch (err) {
            console.error('Raw server response:', text);
            throw new Error('Server JSON response nahi de raha. Console check karo. Response: ' + text.substring(0, 500));
        }

        if (!res.ok) {
            throw new Error(data.message || 'HTTP Error ' + res.status);
        }

        return data;
    })
    .then(function(data) {
        if (data.success) {
            let msg = data.message || 'Import completed successfully';
            msg += '\nInserted: ' + (data.inserted ?? 0);
            msg += '\nUpdated: ' + (data.updated ?? 0);
            msg += '\nSkipped: ' + (data.skipped ?? 0);

            if (data.errors && data.errors.length > 0) {
                msg += '\n\nErrors:\n' + data.errors.join('\n');
            }

            alert(msg);
            window.location.reload();
        } else {
            let msg = data.message || 'Import failed.';

            if (data.errors && data.errors.length > 0) {
                msg += '\n\nErrors:\n' + data.errors.join('\n');
            }

            alert(msg);
        }
    })
    .catch(function(err) {
        console.error('Import Error:', err);
        alert('Import request failed.\n\n' + err.message);
    })
    .finally(function() {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = oldText;
        }
    });
});

document
    .getElementById('bulkEditForm')
    .addEventListener('submit', async function (event) {

        event.preventDefault();

        const bulkForm = this;

        /*
        |--------------------------------------------------------------------------
        | Selected product IDs
        |--------------------------------------------------------------------------
        */
        const selectedItemIds = Array
            .from(
                document.querySelectorAll(
                    'input[name="item_ids[]"]:checked'
                )
            )
            .map(function (checkbox) {
                return parseInt(
                    checkbox.value || '0',
                    10
                );
            })
            .filter(function (itemId) {
                return itemId > 0;
            });

        if (selectedItemIds.length === 0) {
            alert(
                'Please select at least one item.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Bulk status
        |--------------------------------------------------------------------------
        */
        const bulkStatusElement =
            document.getElementById(
                'bulk_status'
            );

        const bulkStatus =
            (
                bulkStatusElement?.value ||
                ''
            ).toLowerCase();

        /*
        |--------------------------------------------------------------------------
        | Existing bulk fields
        |--------------------------------------------------------------------------
        */
        const regularBulkFieldIds = [
            'bulk_type',
            'bulk_track_inventory',
            'bulk_has_expiry',
            'bulk_has_variants'
        ];

        const hasRegularBulkChanges =
            regularBulkFieldIds.some(
                function (fieldId) {

                    const field =
                        document.getElementById(
                            fieldId
                        );

                    return Boolean(
                        field &&
                        field.value !== ''
                    );
                }
            );

        const hasStatusChange =
            ['active', 'inactive'].includes(
                bulkStatus
            );

        if (
            !hasRegularBulkChanges &&
            !hasStatusChange
        ) {
            alert(
                'Please select at least one field to update.'
            );

            return;
        }

        const submitButton =
            bulkForm.querySelector(
                'button[type="submit"]'
            );

        const originalButtonHtml =
            submitButton
                ? submitButton.innerHTML
                : '';

        if (submitButton) {
            submitButton.disabled = true;

            submitButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
        }

        /*
        |--------------------------------------------------------------------------
        | JSON response helper
        |--------------------------------------------------------------------------
        */
        const readJsonResponse =
            async function (
                response,
                defaultErrorMessage
            ) {

                if (response.status === 401) {
                    window.location.href =
                        '/onerp/app/onerp/app/public/app/views/login_view.php';

                    throw new Error(
                        'Session expired.'
                    );
                }

                const responseText =
                    await response.text();

                let responseData;

                try {
                    responseData =
                        JSON.parse(
                            responseText
                        );
                } catch (parseError) {

                    console.error(
                        'Bulk update raw response:',
                        responseText
                    );

                    throw new Error(
                        'Server response JSON nahi hai.'
                    );
                }

                if (
                    !response.ok ||
                    !responseData.success
                ) {
                    throw new Error(
                        responseData.message ||
                        defaultErrorMessage
                    );
                }

                return responseData;
            };

        try {

            /*
            |--------------------------------------------------------------------------
            | Existing Bulk Edit workflow
            |--------------------------------------------------------------------------
            | Type, Track Inventory, Expiry aur Variants ajax_items.php
            | ke existing workflow se update honge.
            |--------------------------------------------------------------------------
            */
            if (hasRegularBulkChanges) {

                const regularFormData =
                    new FormData(
                        bulkForm
                    );

                /*
                |--------------------------------------------------------------------------
                | Status ajax_items.php ko nahi bhejna
                |--------------------------------------------------------------------------
                */
                regularFormData.delete(
                    'bulk_status'
                );

                regularFormData.set(
                    'action',
                    'bulk_edit'
                );

                regularFormData.set(
                    'item_ids',
                    JSON.stringify(
                        selectedItemIds
                    )
                );

                const regularResponse =
                    await fetch(
                        'ajax_items.php?v=' +
                        Date.now(),
                        {
                            method: 'POST',
                            body: regularFormData,
                            cache: 'no-store'
                        }
                    );

                await readJsonResponse(
                    regularResponse,
                    'Existing bulk edit failed.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Bulk Active / Inactive update
            |--------------------------------------------------------------------------
            */
            if (hasStatusChange) {

                const statusFormData =
                    new FormData();

                statusFormData.set(
                    'action',
                    'bulk_update_item_status'
                );

                statusFormData.set(
                    'status',
                    bulkStatus
                );

                statusFormData.set(
                    'item_ids',
                    JSON.stringify(
                        selectedItemIds
                    )
                );

                const statusResponse =
                    await fetch(
                        'ajax_update_item_status.php?v=' +
                        Date.now(),
                        {
                            method: 'POST',
                            body: statusFormData,
                            cache: 'no-store'
                        }
                    );

                await readJsonResponse(
                    statusResponse,
                    'Bulk product status update failed.'
                );
            }

            alert(
                selectedItemIds.length +
                ' selected product(s) successfully updated.'
            );

            window.location.reload();

        } catch (error) {

            console.error(
                'Bulk edit error:',
                error
            );

            alert(
                error.message ||
                'Bulk edit request failed.'
            );

        } finally {

            if (submitButton) {
                submitButton.disabled =
                    false;

                submitButton.innerHTML =
                    originalButtonHtml;
            }
        }
    });

document.getElementById('bulkDeleteBtn').addEventListener('click', function () {
    deleteSelected();
});

let searchRequestTimer = null;

searchInput?.addEventListener(
    'input',
    function () {
        clearTimeout(
            searchRequestTimer
        );

        searchRequestTimer = setTimeout(
            () => {
                navigateItems({
                    search: this.value,
                    page: 1
                });
            },
            600
        );
    }
);

/* =========================================================
   SERVER-SIDE ITEMS PAGINATION
========================================================= */

entriesPerPageSelect?.addEventListener(
    'change',
    function () {

        navigateItems({
            limit: this.value,
            page: 1
        });
    }
);

prevPageBtn?.addEventListener(
    'click',
    function () {

        if (currentPage <= 1) {
            return;
        }

        navigateItems({
            page: currentPage - 1
        });
    }
);

nextPageBtn?.addEventListener(
    'click',
    function () {

        if (currentPage >= totalPages) {
            return;
        }

        navigateItems({
            page: currentPage + 1
        });
    }
);

document
    .getElementById(
        'allow_single_unit_sale'
    )
    ?.addEventListener(
        'change',
        function () {
            toggleSingleTabletUnitSection();

            if (this.checked) {
                recalculateSingleTabletPricing();
            }
        }
    );

document
    .getElementById(
        'strip_tablet_qty'
    )
    ?.addEventListener(
        'input',
        recalculateSingleTabletPricing
    );

document
    .getElementById(
        'strip_sale_price'
    )
    ?.addEventListener(
        'input',
        recalculateSingleTabletPricing
    );


document.getElementById('has_variants')?.addEventListener('change', toggleItemExtraOptions);
document.getElementById('has_expiry')?.addEventListener('change', toggleItemExtraOptions);
document.getElementById('has_discount')?.addEventListener('change', toggleItemExtraOptions);
document.getElementById('discount_type')?.addEventListener('change', toggleItemExtraOptions);
document.getElementById('has_wholesale')?.addEventListener('change', toggleItemExtraOptions);

toggleProductFields(true);
toggleItemExtraOptions();
initializeItemsColumnFilters();




</script>

</body>
</html>