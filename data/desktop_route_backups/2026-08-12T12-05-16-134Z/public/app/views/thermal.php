<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

date_default_timezone_set('Asia/Karachi');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$sale_id   = (int)($_GET['sale_id'] ?? 0);
if (!$sale_id) die("Sale ID missing.");

function tableExists(mysqli $conn, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $tableEsc = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
    $cache[$table] = $res && $res->num_rows > 0;

    if ($res instanceof mysqli_result) $res->free();
    return $cache[$table];
}

function colExists(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $tableEsc  = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    $cache[$key] = $res && $res->num_rows > 0;

    if ($res instanceof mysqli_result) $res->free();
    return $cache[$key];
}

function isFbrIntegrationEnabled(mysqli $conn, int $clientId): bool {
    /*
     * Your schema has integrations(client_id, integration_type, status).
     * FBR fee will print only when this client's FBR integration/status is ON.
     */
    if (tableExists($conn, 'integrations')
        && colExists($conn, 'integrations', 'client_id')
        && colExists($conn, 'integrations', 'integration_type')) {

        $statusSql = colExists($conn, 'integrations', 'status')
            ? " AND `status` IN (1, '1', 'active', 'connected', 'enabled', 'on')"
            : "";

        $stmt = $conn->prepare("
            SELECT id
            FROM integrations
            WHERE client_id = ?
              AND LOWER(TRIM(integration_type)) IN ('fbr', 'fbr_integration')
              {$statusSql}
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("i", $clientId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($row)) return true;
        }
    }

    /* Old/global fallback, only used if you are using global_settings.enable_fbr */
    if (tableExists($conn, 'global_settings') && colExists($conn, 'global_settings', 'enable_fbr')) {
        $res = $conn->query("SELECT enable_fbr FROM global_settings ORDER BY id DESC LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && (int)($row['enable_fbr'] ?? 0) === 1) return true;
    }

    return false;
}

$clientsHasStrn    = colExists($conn, 'clients', 'strn_no');
$clientsHasNtn     = colExists($conn, 'clients', 'ntn_no');
$clientsHasContact = colExists($conn, 'clients', 'contact_number');

$salesHasCounter   = colExists($conn, 'sales', 'counter_name');
$salesHasReference = colExists($conn, 'sales', 'reference_no');

$extraClientCols = "";
$extraClientCols .= $clientsHasStrn    ? ", c.strn_no" : ", NULL AS strn_no";
$extraClientCols .= $clientsHasNtn     ? ", c.ntn_no" : ", NULL AS ntn_no";
$extraClientCols .= $clientsHasContact ? ", c.contact_number" : ", NULL AS contact_number";

$extraSaleCols = "";
$extraSaleCols .= $salesHasCounter   ? ", s.counter_name" : ", NULL AS counter_name";
$extraSaleCols .= $salesHasReference ? ", s.reference_no" : ", NULL AS reference_no";

$stmt = $conn->prepare("
    SELECT 
        s.*,
        c.business_name,

        cust.name AS joined_customer_name,
        cust.phone AS joined_customer_phone,
        cust.address AS joined_customer_address

        $extraClientCols
        $extraSaleCols

    FROM sales s

    LEFT JOIN clients c
        ON c.id = s.client_id

    LEFT JOIN customers cust
        ON cust.id = s.customer_id
       AND cust.client_id = s.client_id

    WHERE s.id = ?
      AND s.client_id = ?

    LIMIT 1
");
$stmt->bind_param("ii", $sale_id, $client_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sale) {
    die("Order not found.");
}

/*
|--------------------------------------------------------------------------
| Resolve receipt customer details
|--------------------------------------------------------------------------
| Pehli priority sales table mein invoice ke waqt saved name/phone ko milegi.
| Agar sales mein empty ho to customers table se joined value use hogi.
|--------------------------------------------------------------------------
*/

$salesCustomerName = trim(
    (string)($sale['customer_name'] ?? '')
);

$joinedCustomerName = trim(
    (string)($sale['joined_customer_name'] ?? '')
);

$salesCustomerPhone = trim(
    (string)($sale['customer_phone'] ?? '')
);

$joinedCustomerPhone = trim(
    (string)($sale['joined_customer_phone'] ?? '')
);

$sale['customer_name'] =
    $salesCustomerName !== ''
        ? $salesCustomerName
        : $joinedCustomerName;

$sale['customer_phone'] =
    $salesCustomerPhone !== ''
        ? $salesCustomerPhone
        : $joinedCustomerPhone;

$sale['customer_address'] = trim(
    (string)(
        $sale['customer_address']
        ?? $sale['joined_customer_address']
        ?? ''
    )
);

/* ================= CLIENT INVOICE HEADER SETTINGS ================= */
$invoice_tagline   = 'make life glowing';
$invoice_top_text  = '';
$invoice_top_phone = '';
$invoice_address   = '';

/* Default: existing invoices par dono visible rahenge */
$show_client_type = 1;
$show_tax_numbers = 1;


if (tableExists($conn, 'client_settings')) {
    $invoiceSettingsColumns = [];

    if (colExists($conn, 'client_settings', 'invoice_tagline')) {
        $invoiceSettingsColumns[] = 'invoice_tagline';
    }

    if (colExists($conn, 'client_settings', 'invoice_top_text')) {
        $invoiceSettingsColumns[] = 'invoice_top_text';
    }

    if (colExists($conn, 'client_settings', 'invoice_top_phone')) {
        $invoiceSettingsColumns[] = 'invoice_top_phone';
    }
    if (colExists($conn, 'client_settings', 'address')) {
    $invoiceSettingsColumns[] = 'address';
}
    
    if (colExists($conn, 'client_settings', 'show_client_type')) {
    $invoiceSettingsColumns[] = 'show_client_type';
}

if (colExists($conn, 'client_settings', 'show_tax_numbers')) {
    $invoiceSettingsColumns[] = 'show_tax_numbers';
}

    if (!empty($invoiceSettingsColumns)) {
        $invoiceSettingsSql = "
            SELECT " . implode(', ', $invoiceSettingsColumns) . "
            FROM client_settings
            WHERE client_id = ?
            LIMIT 1
        ";

        $invoiceSettingsStmt = $conn->prepare($invoiceSettingsSql);

        if ($invoiceSettingsStmt) {
            $invoiceSettingsStmt->bind_param("i", $client_id);
            $invoiceSettingsStmt->execute();

            $invoiceSettingsResult = $invoiceSettingsStmt->get_result();
            $invoiceSettingsRow = $invoiceSettingsResult
                ? $invoiceSettingsResult->fetch_assoc()
                : null;

            if ($invoiceSettingsRow !== null) {
                if (array_key_exists('invoice_tagline', $invoiceSettingsRow)) {
                    $invoice_tagline = trim(
                        (string)($invoiceSettingsRow['invoice_tagline'] ?? '')
                    );
                }
                if (array_key_exists('show_client_type', $invoiceSettingsRow)) {
    $show_client_type =
        (int)$invoiceSettingsRow['show_client_type'] === 1
            ? 1
            : 0;
}

if (array_key_exists('show_tax_numbers', $invoiceSettingsRow)) {
    $show_tax_numbers =
        (int)$invoiceSettingsRow['show_tax_numbers'] === 1
            ? 1
            : 0;
}

                if (array_key_exists('invoice_top_text', $invoiceSettingsRow)) {
                    $invoice_top_text = trim(
                        (string)($invoiceSettingsRow['invoice_top_text'] ?? '')
                    );
                }

                if (array_key_exists('invoice_top_phone', $invoiceSettingsRow)) {
                    $invoice_top_phone = trim(
                        (string)($invoiceSettingsRow['invoice_top_phone'] ?? '')
                    );
                }
                
                if (array_key_exists('address', $invoiceSettingsRow)) {
    $invoice_address = trim(
        (string)($invoiceSettingsRow['address'] ?? '')
    );
}
            }

            $invoiceSettingsStmt->close();
        }
    }
}

$itemsHasRetailPrice     = colExists($conn, 'items', 'price');
$itemsHasMaxDiscount    = colExists($conn, 'items', 'has_max_discount');
$itemsHasMaxDiscountVal = colExists($conn, 'items', 'max_discount_percent');

/*
 |--------------------------------------------------------------------------
 | Maximum Discount columns
 |--------------------------------------------------------------------------
 | Ye receipt par discount auto item discount ki tarah show nahi karega.
 | POS/checkout par actual item-wise capped discount calculate ho kar
 | sales.discount_amount me save hota hai, receipt me wahi actual amount print hoga.
 */
$itemExtraCols = "";
$itemExtraCols .= $itemsHasRetailPrice     ? ", i.price AS item_retail_price" : ", NULL AS item_retail_price";
$itemExtraCols .= $itemsHasMaxDiscount    ? ", i.has_max_discount AS item_has_max_discount" : ", 0 AS item_has_max_discount";
$itemExtraCols .= $itemsHasMaxDiscountVal ? ", i.max_discount_percent AS item_max_discount_percent" : ", 0 AS item_max_discount_percent";

$stmt_items = $conn->prepare("
    SELECT 
        si.*,

        COALESCE(
            NULLIF(TRIM(i.name), ''),
            NULLIF(TRIM(si.item_name), ''),
            'Item'
        ) AS item_name

        $itemExtraCols
    FROM sale_items si
    LEFT JOIN items i ON i.id = si.item_id
    WHERE si.sale_id = ?
");
$stmt_items->bind_param("i", $sale_id);
$stmt_items->execute();
$items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_items->close();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return number_format((float)$value, 2);
}

function moneyLine($value) {
    /* Compact amount for item table columns, so decimals are not clipped on thermal paper */
    return number_format((float)$value, 2, '.', '');
}

function qtyFormat($value) {
    return rtrim(rtrim(number_format((float)$value, 3), '0'), '.');
}

function normalizeCustomerNameForReceipt(array $sale): string
{
    $customerName = trim((string)($sale['customer_name'] ?? ''));
    if ($customerName !== '') {
        return $customerName;
    }

    $customerType = strtolower(trim((string)($sale['customer_type'] ?? '')));
    if ($customerType === 'wholesaler' || $customerType === 'wholesale') {
        return 'Wholesale Customer';
    }

    /*
     * Retailer mode ab Walk-in ki tarah bhi kaam karta hai.
     * Is liye customer select na ho to receipt par clear label print hoga.
     */
    return 'Retailer / Walk-in Retailer';
}

function getItemTierLabel(array $item): string
{
    $tierType = strtolower(trim((string)($item['price_tier_type'] ?? '')));
    $tierNo = (int)($item['price_tier_no'] ?? 0);
    $tierLabel = trim((string)($item['price_tier_label'] ?? ''));

    if ($tierLabel !== '') {
        return $tierLabel;
    }

    if ($tierType === 'wholesale') {
        return $tierNo > 0 ? ('Wholesale ' . $tierNo) : 'Wholesale';
    }

    return '';
}

function getItemDiscountLabel(array $item): string
{
    /*
     * New workflow me item par auto discount nahi hota.
     * Item par sirf Maximum Discount Limit save hoti hai.
     * Receipt par misleading item-wise "Retail Discount" label show nahi karna.
     * Actual capped invoice discount total summary me sales.discount_amount se print hoga.
     */
    return '';
}

function getItemReceiptNote(array $item): string
{
    $tierLabel = getItemTierLabel($item);
    if ($tierLabel !== '') {
        return $tierLabel;
    }

    return getItemDiscountLabel($item);
}

/*
|--------------------------------------------------------------------------
| Check whether an actual product unit was selected
|--------------------------------------------------------------------------
| Sirf unit name "Piece" hone se unit selected nahi samjhenge.
| Selected unit ki proper ID save honi zaroori hai.
|--------------------------------------------------------------------------
*/
function hasReceiptSelectedUnit(array $item): bool
{
    $possibleUnitIdColumns = [
        'unit_id',
        'product_unit_id',
        'selected_unit_id'
    ];

    foreach ($possibleUnitIdColumns as $column) {
        if (
            isset($item[$column]) &&
            $item[$column] !== '' &&
            is_numeric($item[$column]) &&
            (int)$item[$column] > 0
        ) {
            return true;
        }
    }

    return false;
}

function getReceiptUnitName(array $item): string
{
    /*
    |--------------------------------------------------------------------------
    | Actual unit select nahi hui to unit name print nahi karna
    |--------------------------------------------------------------------------
    */
    if (!hasReceiptSelectedUnit($item)) {
        return '';
    }

    $unitName = trim(
        (string)($item['unit_name'] ?? '')
    );

    if ($unitName === '') {
        $unitName = trim(
            (string)($item['sale_unit_name'] ?? '')
        );
    }

    return $unitName;
}

function getReceiptUnitConversionQty(array $item): float
{
    foreach (['unit_conversion_qty', 'conversion_qty'] as $column) {
        if (isset($item[$column]) && $item[$column] !== '' && is_numeric($item[$column])) {
            $value = (float)$item[$column];

            if ($value > 0) {
                return $value;
            }
        }
    }

    return 1.00;
}

function getReceiptBaseQty(array $item): float
{
    if (isset($item['base_qty']) && $item['base_qty'] !== '' && is_numeric($item['base_qty'])) {
        return max(0, (float)$item['base_qty']);
    }

    $qty = max(0, (float)($item['qty'] ?? 0));
    $conversionQty = getReceiptUnitConversionQty($item);

    return round($qty * $conversionQty, 3);
}

function getReceiptQtyUnitDisplay(array $item): string
{
    $qty = qtyFormat($item['qty'] ?? 0);
    $unitName = getReceiptUnitName($item);

    /*
    |--------------------------------------------------------------------------
    | Unit saved ho to Qty ke sath Tablet / Strip / Box show hoga
    |--------------------------------------------------------------------------
    */
    if ($unitName !== '') {
        return $qty . ' ' . $unitName;
    }

    /*
    |--------------------------------------------------------------------------
    | Normal product jisme unit save nahi hai
    |--------------------------------------------------------------------------
    */
    return $qty;
}

function getReceiptUnitNote(array $item): string
{
    $unitName = getReceiptUnitName($item);

    if ($unitName === '') {
        return '';
    }

    $conversionQty = getReceiptUnitConversionQty($item);
    $baseQty = getReceiptBaseQty($item);

    if ($conversionQty > 1) {
        return 'Unit: ' . $unitName .
               ' | 1 ' . $unitName . ' = ' . qtyFormat($conversionQty) . ' base' .
               ' | Base Qty: ' . qtyFormat($baseQty);
    }

    return 'Unit: ' . $unitName;
}
function receiptPercentValue($value): float
{
    $value = (float)$value;

    if ($value < 0) {
        return 0.00;
    }

    if ($value > 100) {
        return 100.00;
    }

    return round($value, 2);
}

function getReceiptLineDiscountPercent(array $item): float
{
    foreach (['discount_percent', 'applied_discount_percent', 'discount'] as $column) {
        if (isset($item[$column]) && $item[$column] !== '' && is_numeric($item[$column])) {
            return receiptPercentValue($item[$column]);
        }
    }

    return 0.00;
}

function getReceiptLineGrossTotal(array $item): float
{
    foreach (['line_subtotal', 'line_total', 'total'] as $column) {
        if (isset($item[$column]) && $item[$column] !== '' && is_numeric($item[$column])) {
            return round((float)$item[$column], 2);
        }
    }

    $qty = (float)($item['qty'] ?? 0);
    $price = (float)($item['price'] ?? 0);

    return round($qty * $price, 2);
}

function getReceiptLineDiscountAmount(array $item, float $grossTotal, float $discountPercent): float
{
    foreach (['discount_amount', 'line_discount'] as $column) {
        if (isset($item[$column]) && $item[$column] !== '' && is_numeric($item[$column])) {
            return round(max(0, (float)$item[$column]), 2);
        }
    }

    return round($grossTotal * ($discountPercent / 100), 2);
}

function getReceiptLineNetTotal(array $item): float
{
    foreach (['line_total_after_discount', 'net_total'] as $column) {
        if (isset($item[$column]) && $item[$column] !== '' && is_numeric($item[$column])) {
            return round(max(0, (float)$item[$column]), 2);
        }
    }

    $grossTotal = getReceiptLineGrossTotal($item);
    $discountPercent = getReceiptLineDiscountPercent($item);
    $discountAmount = getReceiptLineDiscountAmount($item, $grossTotal, $discountPercent);

    return round(max(0, $grossTotal - $discountAmount), 2);
}

function getReceiptLineDiscountNote(array $item): string
{
    $grossTotal = getReceiptLineGrossTotal($item);
    $discountPercent = getReceiptLineDiscountPercent($item);
    $discountAmount = getReceiptLineDiscountAmount($item, $grossTotal, $discountPercent);
    $netTotal = getReceiptLineNetTotal($item);

    if ($discountPercent <= 0 && $discountAmount <= 0) {
        return '';
    }

    return 'Disc ' . qtyFormat($discountPercent) . '% / Rs ' . moneyLine($discountAmount) .
           ' | Net Rs ' . moneyLine($netTotal);
}


function normalizeWhatsappPhoneForReceipt($phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);

    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }

    if (strpos($digits, '0') === 0) {
        $digits = '92' . substr($digits, 1);
    } elseif (strlen($digits) === 10 && strpos($digits, '3') === 0) {
        $digits = '92' . $digits;
    }

    return $digits;
}

function companyInitials($name) {
    $name = trim(preg_replace('/[^A-Za-z0-9 ]/', ' ', (string)$name));
    if ($name === '') return 'POS';

    $words = preg_split('/\s+/', $name);
    $initials = '';

    foreach ($words as $word) {
        if ($word === '') continue;
        $initials .= strtoupper(substr($word, 0, 1));
        if (strlen($initials) >= 3) break;
    }

    return $initials ?: 'POS';
}

function receiptDateTime($createdAt): string {
    try {
        /*
         * DB created_at is stored like UTC in your case.
         * Convert it to Pakistan time so receipt time is correct.
         */
        $dt = !empty($createdAt)
            ? new DateTime((string)$createdAt, new DateTimeZone('UTC'))
            : new DateTime('now', new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Karachi'));
        return $dt->format('D, d-M-Y h:i A');
    } catch (Throwable $e) {
        return date('D, d-M-Y h:i A');
    }
}

$subtotal  = 0;
$total_qty = 0;

foreach ($items as $item) {
    $qty   = (float)($item['qty'] ?? 0);
    $price = (float)($item['price'] ?? 0);
    $subtotal += ($qty * $price);
    $total_qty += $qty;
}

$total_items = count($items);

$discount_percent = (float)($sale['discount_percent'] ?? ($sale['discount'] ?? 0));
$tax_percent      = (float)($sale['tax_percent'] ?? ($sale['tax'] ?? 0));

/*
 |--------------------------------------------------------------------------
 | Actual item-wise capped discount
 |--------------------------------------------------------------------------
 | Pehle yahan subtotal ka direct % calculate hota tha.
 | Ab POS/checkout har item par max discount limit check karta hai:
 | applied % = min(invoice discount %, item max discount %)
 | Jo actual total discount banta hai wo sales.discount_amount me save hota hai.
 | Receipt par wahi actual amount print hoga.
 */
if (isset($sale['discount_amount']) && is_numeric($sale['discount_amount'])) {
    $discount_amount = (float)$sale['discount_amount'];
} else {
    $discount_amount = ($subtotal * $discount_percent) / 100;
}

if ($discount_amount < 0) {
    $discount_amount = 0;
}

if ($discount_amount > $subtotal) {
    $discount_amount = $subtotal;
}

$taxable_amount = max(0, $subtotal - $discount_amount);

/*
 |--------------------------------------------------------------------------
 | Actual tax amount
 |--------------------------------------------------------------------------
 | Backend checkout agar tax_amount save kar raha ho to wahi print karo.
 | Warna fallback old calculation use hoga.
 */
if (isset($sale['tax_amount']) && is_numeric($sale['tax_amount'])) {
    $tax_amount = (float)$sale['tax_amount'];
} else {
    $tax_amount = ($taxable_amount * $tax_percent) / 100;
}

$fbr_enabled     = isFbrIntegrationEnabled($conn, $client_id);
$other_charges   = isset($sale['other_charges']) ? (float)$sale['other_charges'] : 0;
$fbr_service_fee = $fbr_enabled ? $other_charges : 0;
$grand_total     = $taxable_amount + $tax_amount + $fbr_service_fee;

$store_name = !empty($sale['business_name']) ? $sale['business_name'] : "Default Store";
$company_code = companyInitials($store_name);
$invoice_no = "INV-" . $company_code . "-" . $sale_id;

$customer_name = normalizeCustomerNameForReceipt($sale);
$customer_phone = trim((string)($sale['customer_phone'] ?? ''));
$customer_whatsapp_phone = normalizeWhatsappPhoneForReceipt($customer_phone);

$payment_method = !empty($sale['payment_method']) ? $sale['payment_method'] : "Cash";
$created_at = receiptDateTime($sale['created_at'] ?? null);

$operator_name = $_SESSION['user_name'] ?? $_SESSION['username'] ?? "Admin";

$counter_name = !empty($sale['counter_name']) ? $sale['counter_name'] : "Main Counter";
$reference_no = !empty($sale['reference_no']) ? $sale['reference_no'] : $sale_id;

$strn_no    = !empty($sale['strn_no']) ? $sale['strn_no'] : "3277876117333";
$ntn_no     = !empty($sale['ntn_no']) ? $sale['ntn_no'] : "42856108";
$contact_no = !empty($sale['contact_number']) ? $sale['contact_number'] : "21111734628";

$qr_text = "Store: {$store_name}\nInvoice: {$invoice_no}\nAmount: " . money($grand_total) . "\nDate: {$created_at}";
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=170x170&data=" . urlencode($qr_text);

$wa_lines = [];
$wa_lines[] = "*{$store_name}*";
$wa_lines[] = "Sale Receipt";
$wa_lines[] = "";
$wa_lines[] = "Invoice: {$invoice_no}";
$wa_lines[] = "Date: {$created_at}";
$wa_lines[] = "Customer: {$customer_name}";
if ($customer_phone !== '') {
    $wa_lines[] = "Phone: {$customer_phone}";
}
$wa_lines[] = "Payment: " . ucfirst($payment_method);
$wa_lines[] = "";
$wa_lines[] = "*Items:*";

foreach ($items as $index => $item) {
    $qty = qtyFormat($item['qty'] ?? 0);
    $price = moneyLine($item['price'] ?? 0);
$line_gross_total = getReceiptLineGrossTotal($item);
$line_discount_note = getReceiptLineDiscountNote($item);
$line_total = moneyLine(getReceiptLineNetTotal($item));
    $item_name = trim((string)($item['item_name'] ?? 'Item'));
    if ($item_name === '') $item_name = 'Item';

    $item_note = getItemReceiptNote($item);
    $item_unit_display = getReceiptQtyUnitDisplay($item);

    $wa_lines[] = ($index + 1) . ". {$item_name}";
if ($line_discount_note !== '') {
    $wa_lines[] = "   {$line_discount_note}";
}
    $wa_lines[] = "   Qty: {$item_unit_display} | Price: {$price} | Total: {$line_total}";
}

$wa_lines[] = "";
$wa_lines[] = "Total Items: {$total_items}";
$wa_lines[] = "Total Qty: " . qtyFormat($total_qty);
$wa_lines[] = "Gross Amount: Rs " . money($subtotal);
$wa_lines[] = "Discount: Rs " . money($discount_amount);
$wa_lines[] = "GST: Rs " . money($tax_amount);

if ($fbr_enabled) {
    $wa_lines[] = "FBR Service Fee: Rs " . money($fbr_service_fee);
}

$wa_lines[] = "*Grand Total: Rs " . money($grand_total) . "*";
$wa_lines[] = "";
$wa_lines[] = "Powered By gapsoedigital";
$wa_lines[] = "Thank you for your purchase!";

$whatsapp_message = implode("\n", $wa_lines);
$whatsapp_url = $customer_whatsapp_phone !== ""
    ? ("https://wa.me/" . $customer_whatsapp_phone . "?text=" . urlencode($whatsapp_message))
    : ("https://wa.me/?text=" . urlencode($whatsapp_message));

/* PDF share fallback opens WhatsApp without pre-filled invoice text. */
$whatsapp_chat_url = $customer_whatsapp_phone !== ""
    ? ("https://wa.me/" . $customer_whatsapp_phone)
    : "https://wa.me/";

$whatsapp_link_base_url = $customer_whatsapp_phone !== ""
    ? ("https://wa.me/" . $customer_whatsapp_phone)
    : "https://wa.me/";
?>
<?php
/*
|--------------------------------------------------------------------------
| Reference-style receipt display helpers
|--------------------------------------------------------------------------
| Presentation only. Existing sale calculations and database logic stay same.
*/
$receiptDateObject = DateTime::createFromFormat('D, d-M-Y h:i A', $created_at);
$receiptDateDisplay = $receiptDateObject ? $receiptDateObject->format('d/m/Y') : $created_at;
$receiptTimeDisplay = $receiptDateObject ? $receiptDateObject->format('H:i') : '';

$receiptSaleType = trim((string)($sale['customer_type'] ?? ''));
if ($receiptSaleType === '') {
    $receiptSaleType = 'Retail';
}
$receiptSaleType = ucwords(str_replace(['_', '-'], ' ', $receiptSaleType));

$amount_paid = 0.00;
foreach (['amount_paid', 'paid_amount', 'received_amount'] as $paidColumn) {
    if (isset($sale[$paidColumn]) && $sale[$paidColumn] !== '' && is_numeric($sale[$paidColumn])) {
        $amount_paid = (float)$sale[$paidColumn];
        break;
    }
}
if ($amount_paid <= 0) {
    $amount_paid = $grand_total;
}

$change_amount = 0.00;
foreach (['change_amount', 'change', 'return_amount'] as $changeColumn) {
    if (isset($sale[$changeColumn]) && $sale[$changeColumn] !== '' && is_numeric($sale[$changeColumn])) {
        $change_amount = max(0, (float)$sale[$changeColumn]);
        break;
    }
}
if ($change_amount <= 0 && $amount_paid > $grand_total) {
    $change_amount = $amount_paid - $grand_total;
}

function receiptMoneyCompact($value): string
{
    $formatted = number_format((float)$value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

$barcode_value = preg_replace('/[^A-Za-z0-9\-]/', '', $invoice_no) . '-' . date('Ymd');
$barcode_url = 'https://bwipjs-api.metafloor.com/?bcid=code128'
    . '&text=' . urlencode($barcode_value)
    . '&scale=2&height=14&includetext=false&backgroundcolor=ffffff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tax Invoice <?= e($invoice_no) ?></title>

<style>
* {
    box-sizing: border-box;
}

@page {
    margin: 0;
}

html,
body {
    margin: 0 !important;
    padding: 0 !important;
    width: 80mm !important;
    max-width: 80mm !important;
    min-width: 80mm !important;
    background: #ffffff !important;
    color: #000000 !important;
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 10.5px;
    line-height: 1.22;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

body {
    overflow-x: hidden;
}

.receipt,
.receipt * {
    color: #000000 !important;
    font-family: Arial, Helvetica, sans-serif !important;
    text-shadow: none !important;
    filter: none !important;
}

.receipt {
    width: 80mm !important;
    max-width: 80mm !important;
    min-width: 80mm !important;
    margin: 0 !important;
    padding: 4mm 4.5mm 5mm !important;
    background: #ffffff !important;
    overflow: hidden;
}

.center {
    text-align: center;
}


/* =========================================
   OPTIONAL TOP DELIVERY MESSAGE
========================================= */

.invoice-top-message {
    width: 100%;
    margin: 0 0 5px;
    text-align: center;
    page-break-inside: avoid;
    break-inside: avoid;
}

.invoice-top-title {
    margin: 0;
    font-size: 13.5px;
    line-height: 1.12;
    font-weight: 900;
    letter-spacing: .15px;
    overflow-wrap: anywhere;
}

.invoice-top-phone {
    margin-top: 2px;
    font-size: 15px;
    line-height: 1.08;
    font-weight: 900;
    letter-spacing: .7px;
    white-space: nowrap;
}

.store-name {
    margin: 0;
    text-align: center;
    font-size: 19px;
    line-height: 1.05;
    font-weight: 900;
    letter-spacing: .15px;
    text-transform: uppercase;
}

.store-tag {
    margin-top: 1px;
    text-align: center;
    font-size: 15.5px;
    line-height: 1.15;
    font-weight: 700;
}

.company-info {
    margin-top: 3px;
    text-align: center;
    font-size: 9.8px;
    line-height: 1.28;
    font-weight: 500;
}

.dashed-line {
    width: 100%;
    border-top: 1px dashed #000;
    margin: 6px 0;
}

.invoice-title {
    margin: 0;
    text-align: center;
    font-size: 18px;
    line-height: 1.05;
    font-weight: 900;
    letter-spacing: .3px;
    text-transform: uppercase;
}

.invoice-thanks {
    margin-top: 3px;
    text-align: center;
    font-size: 9.8px;
    line-height: 1.15;
    font-weight: 500;
}

.invoice-meta {
    width: 100%;
    font-size: 10px;
    line-height: 1.25;
}

.invoice-meta-row {
    display: grid;
    grid-template-columns: 16mm minmax(0, 1fr) 13mm minmax(0, .9fr);
    column-gap: 1.1mm;
    align-items: start;
    margin: 2px 0;
}

.meta-label {
    font-weight: 800;
    white-space: nowrap;
}

.meta-value {
    min-width: 0;
    font-weight: 500;
    word-break: break-word;
}

.items-table-head,
.item-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 15mm 14mm 15mm;
    column-gap: .8mm;
    align-items: start;
    width: 100%;
}

.items-table-head {
    padding: 2px 0 4px;
    font-size: 10.5px;
    font-weight: 900;
    border-bottom: 1px dashed #000;
}

.items-table-head span:nth-child(1),
.item-row span:nth-child(1) {
    text-align: left;
}

.items-table-head span:nth-child(2),
.item-row span:nth-child(2) {
    text-align: center;
}

.items-table-head span:nth-child(3),
.items-table-head span:nth-child(4),
.item-row span:nth-child(3),
.item-row span:nth-child(4) {
    text-align: right;
}

.item-entry {
    padding: 4px 0;
    border-bottom: 1px dashed #000;
    break-inside: avoid;
    page-break-inside: avoid;
}

.item-row {
    font-size: 9.7px;
    line-height: 1.2;
    font-weight: 500;
}

.item-name {
    min-width: 0;
    padding-right: 1mm;
    overflow-wrap: anywhere;
}

.item-qty,
.item-price,
.item-total {
    white-space: nowrap;
}

.item-qty {
    font-size: 9px;
}

.item-note {
    margin-top: 2px;
    padding-left: 1mm;
    font-size: 8.7px;
    line-height: 1.2;
    font-weight: 500;
}

.bill-totals {
    padding: 3px 0 2px;
    border-bottom: 1px dashed #000;
}

.total-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 25mm;
    align-items: center;
    min-height: 20px;
    font-size: 10.5px;
    line-height: 1.15;
}

.total-label {
    text-align: right;
    padding-right: 6mm;
    font-weight: 500;
}

.total-value {
    text-align: right;
    font-weight: 800;
    white-space: nowrap;
}

.grand-total {
    margin-top: 2px;
    padding: 4px 0;
    border-top: 1px solid #000;
    border-bottom: 1px solid #000;
    font-size: 12px;
    font-weight: 900;
}

.grand-total .total-label,
.grand-total .total-value {
    font-weight: 900;
}

.payment-details {
    padding: 4px 0 3px;
    border-bottom: 1px dashed #000;
}

.payment-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 25mm;
    align-items: center;
    min-height: 19px;
    font-size: 10px;
    line-height: 1.15;
}

.payment-row span:first-child {
    text-align: left;
    font-weight: 500;
}

.payment-row span:last-child {
    text-align: right;
    font-weight: 800;
    white-space: nowrap;
}

.receipt-footer {
    padding-top: 5px;
    text-align: center;
}

.provider-name {
    font-size: 11.5px;
    line-height: 1.15;
    font-weight: 900;
    text-transform: uppercase;
}

.powered-line {
    margin-top: 2px;
    font-size: 9.2px;
    line-height: 1.15;
    font-weight: 500;
}

.barcode-box {
    margin: 4px auto 0;
    text-align: center;
}

.barcode-box img {
    width: 64mm;
    max-width: 100%;
    height: 14mm;
    object-fit: fill;
    display: block;
    margin: 0 auto;
}

.barcode-text {
    margin-top: 2px;
    font-size: 8.8px;
    line-height: 1.1;
    font-weight: 500;
    overflow-wrap: anywhere;
}

.no-print {
    width: 80mm;
    max-width: 80mm;
    padding: 7px 0;
    text-align: center;
    background: #fff;
}

.action-buttons {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.print-btn,
.whatsapp-btn,
.whatsapp-link-btn {
    border: 0;
    border-radius: 5px;
    padding: 7px 12px;
    color: #fff !important;
    font-size: 12px;
    line-height: 1.2;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.print-btn {
    background: #111;
}

.whatsapp-btn {
    background: #16a34a;
}

.whatsapp-link-btn {
    background: #0ea5e9;
}

.whatsapp-btn:disabled,
.whatsapp-link-btn:disabled {
    opacity: .65;
    cursor: wait;
}

@media print {
    @page {
        margin: 0;
    }

    html,
    body,
    .receipt {
        width: 80mm !important;
        max-width: 80mm !important;
        min-width: 80mm !important;
        margin: 0 !important;
        background: #fff !important;
    }

    body,
    .receipt,
    .receipt * {
        color: #000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .receipt {
        padding: 4mm 4.5mm 5mm !important;
    }

    .no-print {
        display: none !important;
    }
}
</style>
</head>
<body>

<div class="no-print">
    <div class="action-buttons">
        <button type="button" class="print-btn" onclick="window.print()">Print</button>
        <!--<button type="button"-->
        <!--        class="whatsapp-btn"-->
        <!--        id="sharePdfBtn">-->
        <!--    Send PDF to WhatsApp-->
        <!--</button>-->

        <button type="button"
                class="whatsapp-link-btn"
                id="shareLinkBtn">
            Send Link to WhatsApp
        </button>
    </div>
</div>

<div class="receipt">

    <?php if ($invoice_top_text !== '' || $invoice_top_phone !== ''): ?>
        <div class="invoice-top-message">

            <?php if ($invoice_top_text !== ''): ?>
                <div class="invoice-top-title">
                    <?= e($invoice_top_text) ?>
                </div>
            <?php endif; ?>

            <?php if ($invoice_top_phone !== ''): ?>
                <div class="invoice-top-phone">
                    <?= e($invoice_top_phone) ?>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>

    <h1 class="store-name"><?= e($store_name) ?></h1>
   <?php if ($invoice_tagline !== ''): ?>
    <div class="store-tag">
        <?= e($invoice_tagline) ?>
    </div>
<?php endif; ?>

<div class="company-info">

    <?php if ($contact_no !== ''): ?>
        <div>
            Phone: <?= e($contact_no) ?>
        </div>
    <?php endif; ?>
        <?php if ($invoice_address !== ''): ?>
        <div>
            Address: <?= e($invoice_address) ?>
        </div>
    <?php endif; ?>

    <?php if (
        $show_tax_numbers === 1 &&
        ($ntn_no !== '' || $strn_no !== '')
    ): ?>

        <div>

            <?php if ($ntn_no !== ''): ?>
                NTN: <?= e($ntn_no) ?>
            <?php endif; ?>

            <?php if ($ntn_no !== '' && $strn_no !== ''): ?>
                |
            <?php endif; ?>

            <?php if ($strn_no !== ''): ?>
                STRN: <?= e($strn_no) ?>
            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

    <div class="dashed-line"></div>

    <h2 class="invoice-title">Invoice</h2>
    <div class="invoice-thanks">Thank You! Visit Again</div>

    <div class="dashed-line"></div>

    <div class="invoice-meta">
        <div class="invoice-meta-row">
            <span class="meta-label">Invoice</span>
            <span class="meta-value">: <?= e($invoice_no) ?></span>
            <span class="meta-label">Type</span>
            <span class="meta-value">: <?= e($receiptSaleType) ?></span>
        </div>

        <div class="invoice-meta-row">
            <span class="meta-label">Date</span>
            <span class="meta-value">: <?= e($receiptDateDisplay) ?></span>
            <span class="meta-label">Counter</span>
            <span class="meta-value">: <?= e($counter_name) ?></span>
        </div>

     <div class="invoice-meta-row">

    <span class="meta-label">Time</span>
    <span class="meta-value">
        : <?= e($receiptTimeDisplay) ?>
    </span>

    <span class="meta-label">Customer</span>
    <span class="meta-value">
        : <?= e($customer_name) ?>
    </span>

</div>

<div class="invoice-meta-row">

    <span class="meta-label">Phone</span>
    <span class="meta-value">
        : <?= e(
            $customer_phone !== ''
                ? $customer_phone
                : '-'
        ) ?>
    </span>

    <span></span>
    <span></span>

</div>


        <div class="invoice-meta-row">
            <span class="meta-label">Cashier</span>
            <span class="meta-value">: <?= e($operator_name) ?></span>
            <span class="meta-label">Pay</span>
            <span class="meta-value">: <?= e(ucfirst($payment_method)) ?></span>
        </div>
    </div>

    <div class="dashed-line"></div>

    <div class="items-table-head">
        <span>Item Name</span>
        <span>Qty</span>
        <span>Price</span>
        <span>Total</span>
    </div>

    <?php foreach ($items as $item):
        $price = (float)($item['price'] ?? 0);
        $line_gross_total = getReceiptLineGrossTotal($item);
        $line_net_total = getReceiptLineNetTotal($item);
        $line_discount_percent = getReceiptLineDiscountPercent($item);
        $line_discount_amount = getReceiptLineDiscountAmount(
            $item,
            $line_gross_total,
            $line_discount_percent
        );
        $item_note = getItemReceiptNote($item);
    ?>
        <div class="item-entry">
            <div class="item-row">
                <span class="item-name"><?= e($item['item_name'] ?: 'Item') ?></span>
                <span class="item-qty">
                    <?= e(getReceiptQtyUnitDisplay($item)) ?>
                </span>
                <span class="item-price"><?= e(moneyLine($price)) ?></span>
                <span class="item-total"><?= e(moneyLine($line_net_total)) ?></span>
            </div>

            <?php if ($line_discount_amount > 0 || $item_note !== ''): ?>
                <div class="item-note">
                    <?php if ($line_discount_amount > 0): ?>
                        Discount: Rs. <?= e(receiptMoneyCompact($line_discount_amount)) ?>
                    <?php endif; ?>
                    <?php if ($line_discount_amount > 0 && $item_note !== ''): ?> | <?php endif; ?>
                    <?php if ($item_note !== ''): ?><?= e($item_note) ?><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="bill-totals">
        <div class="total-row">
            <span class="total-label">Subtotal</span>
            <span class="total-value">Rs. <?= e(receiptMoneyCompact($subtotal)) ?></span>
        </div>

        <div class="total-row">
            <span class="total-label">Discount</span>
            <span class="total-value">-Rs. <?= e(receiptMoneyCompact($discount_amount)) ?></span>
        </div>

        <div class="total-row">
            <span class="total-label">Tax</span>
            <span class="total-value">Rs. <?= e(receiptMoneyCompact($tax_amount)) ?></span>
        </div>

        <?php if ($fbr_enabled): ?>
            <div class="total-row">
                <span class="total-label">FBR Fee</span>
                <span class="total-value">Rs. <?= e(receiptMoneyCompact($fbr_service_fee)) ?></span>
            </div>
        <?php endif; ?>

        <div class="total-row grand-total">
            <span class="total-label">Grand Total</span>
            <span class="total-value">Rs. <?= e(receiptMoneyCompact($grand_total)) ?></span>
        </div>
    </div>

    <div class="payment-details">
        <div class="payment-row">
            <span>Payment Method</span>
            <span><?= e(ucfirst($payment_method)) ?></span>
        </div>
        <div class="payment-row">
            <span>Amount Paid</span>
            <span>Rs. <?= e(receiptMoneyCompact($amount_paid)) ?></span>
        </div>
        <div class="payment-row">
            <span>Change</span>
            <span>Rs. <?= e(receiptMoneyCompact($change_amount)) ?></span>
        </div>
    </div>

    <div class="receipt-footer">
        <div class="provider-name">Gapsoe Digital</div>
        <div class="powered-line">Powered By ONEERP POS System</div>

        <div class="barcode-box">
            <img src="<?= e($barcode_url) ?>" alt="Invoice Barcode">
            <div class="barcode-text"><?= e($barcode_value) ?></div>
        </div>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    const shareButton = document.getElementById('sharePdfBtn');
    const shareLinkButton = document.getElementById('shareLinkBtn');
    const receipt = document.querySelector('.receipt');
    const invoiceNo = <?= json_encode($invoice_no, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const storeName = <?= json_encode($store_name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const grandTotal = <?= json_encode(receiptMoneyCompact($grand_total), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const whatsappChatUrl = <?= json_encode($whatsapp_chat_url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const whatsappLinkBaseUrl = <?= json_encode($whatsapp_link_base_url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    if (!receipt) {
        return;
    }

    function waitForReceiptImages() {
        const images = Array.from(receipt.querySelectorAll('img'));
        return Promise.all(images.map(function (img) {
            if (img.complete) {
                return Promise.resolve();
            }

            return new Promise(function (resolve) {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', resolve, { once: true });
            });
        }));
    }

    async function createReceiptPdfFile() {
        if (typeof html2pdf === 'undefined') {
            throw new Error('PDF library load nahi hui. Internet connection check karein.');
        }

        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }
        await waitForReceiptImages();

        const widthPx = Math.max(receipt.scrollWidth, receipt.getBoundingClientRect().width);
        const heightPx = Math.max(receipt.scrollHeight, receipt.getBoundingClientRect().height);
        const pdfWidthMm = 80;
        const pdfHeightMm = Math.max(100, Math.ceil((heightPx / widthPx) * pdfWidthMm) + 2);
        const safeInvoice = String(invoiceNo || 'invoice').replace(/[^a-zA-Z0-9_-]+/g, '-');
        const filename = safeInvoice + '.pdf';

        const options = {
            margin: 0,
            filename: filename,
            image: { type: 'jpeg', quality: 1 },
            html2canvas: {
                scale: 3,
                useCORS: true,
                allowTaint: false,
                backgroundColor: '#ffffff',
                scrollX: 0,
                scrollY: 0,
                logging: false
            },
            jsPDF: {
                unit: 'mm',
                format: [pdfWidthMm, pdfHeightMm],
                orientation: 'portrait',
                compress: true
            },
            pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
        };

        const pdfBlob = await html2pdf()
            .set(options)
            .from(receipt)
            .outputPdf('blob');

        return new File([pdfBlob], filename, { type: 'application/pdf' });
    }

    function downloadPdfFile(file) {
        const objectUrl = URL.createObjectURL(file);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = file.name;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(function () {
            URL.revokeObjectURL(objectUrl);
        }, 1500);
    }

    if (shareButton) {
    shareButton.addEventListener('click', async function () {
        const originalText = shareButton.textContent;
        shareButton.disabled = true;
        shareButton.textContent = 'Generating PDF...';

        let fallbackWindow = null;
        let nativeFileShareAvailable = false;

        try {
            const testPdfFile = new File([''], 'test.pdf', { type: 'application/pdf' });
            nativeFileShareAvailable = Boolean(
                navigator.share &&
                navigator.canShare &&
                navigator.canShare({ files: [testPdfFile] })
            );
        } catch (shareSupportError) {
            nativeFileShareAvailable = false;
        }

        if (!nativeFileShareAvailable) {
            fallbackWindow = window.open('about:blank', '_blank');
        }

        try {
            const pdfFile = await createReceiptPdfFile();
            const shareData = {
                title: invoiceNo,
                text: storeName + ' - ' + invoiceNo,
                files: [pdfFile]
            };

            if (navigator.share && navigator.canShare && navigator.canShare({ files: [pdfFile] })) {
                await navigator.share(shareData);
                return;
            }

            downloadPdfFile(pdfFile);

            if (fallbackWindow) {
                fallbackWindow.location.href = whatsappChatUrl;
            } else {
                window.open(whatsappChatUrl, '_blank', 'noopener');
            }

            alert(
                'PDF download ho gayi hai. Desktop browser WhatsApp Web mein file ko security ki wajah se auto-attach nahi kar sakta. WhatsApp mein paperclip se downloaded PDF select karein.'
            );
        } catch (error) {
            if (fallbackWindow && !fallbackWindow.closed) {
                fallbackWindow.close();
            }
            console.error(error);
            alert(error && error.message ? error.message : 'PDF generate nahi ho saki.');
        } finally {
            shareButton.disabled = false;
            shareButton.textContent = originalText;
        }
    });
    }

    if (shareLinkButton) {
        shareLinkButton.addEventListener('click', function () {
            const invoiceLink = window.location.href;
            const message = [
                '*' + storeName + '*',
                'Invoice: ' + invoiceNo,
                'Amount: Rs. ' + grandTotal,
                '',
                'View Invoice:',
                invoiceLink
            ].join('\n');

            const whatsappUrl =
                whatsappLinkBaseUrl +
                '?text=' +
                encodeURIComponent(message);

            window.open(whatsappUrl, '_blank', 'noopener');
        });
    }
})();
</script>

</body>
</html>