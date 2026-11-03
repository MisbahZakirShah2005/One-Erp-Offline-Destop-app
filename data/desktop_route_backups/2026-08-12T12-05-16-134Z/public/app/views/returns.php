<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
if ($client_id <= 0) {
    die('Invalid client session');
}

mysqli_report(MYSQLI_REPORT_OFF);

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function format_money($amount): string {
    return 'PKR ' . number_format((float)$amount, 0);
}

function format_datetime($value): string {
    if (!$value) return '-';
    return date('M d, Y h:i A', strtotime($value));
}

function table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $sql = "SHOW TABLES LIKE '{$safe}'";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function first_existing_column(mysqli $conn, string $table, array $columns, ?string $default = null): ?string {
    foreach ($columns as $column) {
        if (column_exists($conn, $table, $column)) {
            return $column;
        }
    }
    return $default;
}

function get_branch_name(array $branches, int $branchId): string {
    foreach ($branches as $branch) {
        if ((int)$branch['id'] === $branchId) {
            return (string)$branch['name'];
        }
    }
    return '-';
}

function get_sales_invoice_map(mysqli $conn, int $clientId): array {
    $map = [];
    if (!table_exists($conn, 'sales')) return $map;

    $invoiceCol = first_existing_column($conn, 'sales', ['invoice_no', 'invoice_number', 'sale_no'], 'invoice_no');
    $sql = "SELECT id, `{$invoiceCol}` AS invoice_no FROM sales WHERE client_id=?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $map;

    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $map[(int)$row['id']] = (string)($row['invoice_no'] ?? '');
    }

    $stmt->close();
    return $map;
}

function get_customers_map(mysqli $conn, int $clientId): array {
    $map = [];
    if (!table_exists($conn, 'customers')) return $map;

    $nameCol  = first_existing_column($conn, 'customers', ['name', 'customer_name'], 'name');
    $phoneCol = first_existing_column($conn, 'customers', ['phone', 'mobile', 'contact_no'], 'phone');

    $sql = "SELECT id, `{$nameCol}` AS name, `{$phoneCol}` AS phone FROM customers WHERE client_id=?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $map;

    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $map[(int)$row['id']] = [
            'name'  => (string)($row['name'] ?? '-'),
            'phone' => (string)($row['phone'] ?? '-'),
        ];
    }

    $stmt->close();
    return $map;
}

function get_customer_details(mysqli $conn, int $clientId, int $customerId = 0, string $fallbackName = ''): array {
    $customer = [
        'id'    => $customerId,
        'name'  => $fallbackName !== '' ? $fallbackName : '-',
        'phone' => '-',
    ];

    if (!table_exists($conn, 'customers') || $customerId <= 0) {
        return $customer;
    }

    $nameCol  = first_existing_column($conn, 'customers', ['name', 'customer_name'], 'name');
    $phoneCol = first_existing_column($conn, 'customers', ['phone', 'mobile', 'contact_no'], 'phone');

    $sql = "SELECT id, `{$nameCol}` AS name, `{$phoneCol}` AS phone
            FROM customers
            WHERE id=? AND client_id=?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $customer;

    $stmt->bind_param('ii', $customerId, $clientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $customer = [
            'id'    => (int)$row['id'],
            'name'  => (string)($row['name'] ?? ($fallbackName ?: '-')),
            'phone' => (string)($row['phone'] ?? '-'),
        ];
    }

    return $customer;
}

function get_return_item_product_list(mysqli $conn, int $clientId, int $returnId): array {
    if (!table_exists($conn, 'return_items')) {
        return ['items' => '-', 'total_qty' => 0];
    }

    $qtyCol = first_existing_column($conn, 'return_items', ['qty', 'quantity'], 'qty');
    $itemIdCol = first_existing_column($conn, 'return_items', ['item_id', 'product_id'], 'item_id');

    $hasProductName = column_exists($conn, 'return_items', 'product_name');

    if ($hasProductName) {
        $sql = "
            SELECT
                GROUP_CONCAT(
                    COALESCE(NULLIF(ri.product_name,''), CONCAT('Item #', ri.`{$itemIdCol}`))
                    SEPARATOR ', '
                ) AS items,
                COALESCE(SUM(ri.`{$qtyCol}`), 0) AS total_qty
            FROM return_items ri
            WHERE ri.return_id=?
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return ['items' => '-', 'total_qty' => 0];
        $stmt->bind_param('i', $returnId);
    } else {
        $itemNameCol = first_existing_column($conn, 'items', ['name', 'title'], 'name');
        $itemClientCol = first_existing_column($conn, 'items', ['client_id'], 'client_id');

        $sql = "
            SELECT
                GROUP_CONCAT(
                    COALESCE(i.`{$itemNameCol}`, CONCAT('Item #', ri.`{$itemIdCol}`))
                    SEPARATOR ', '
                ) AS items,
                COALESCE(SUM(ri.`{$qtyCol}`), 0) AS total_qty
            FROM return_items ri
            LEFT JOIN items i
                ON i.id = ri.`{$itemIdCol}`
               AND i.`{$itemClientCol}` = ?
            WHERE ri.return_id=?
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return ['items' => '-', 'total_qty' => 0];
        $stmt->bind_param('ii', $clientId, $returnId);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'items'     => (string)($row['items'] ?? '-') ?: '-',
        'total_qty' => (float)($row['total_qty'] ?? 0),
    ];
}

function fetch_sale_items(mysqli $conn, int $clientId, int $saleId): array {
    $rows = [];

    if (table_exists($conn, 'sale_items') && table_exists($conn, 'items')) {
        $saleFk     = first_existing_column($conn, 'sale_items', ['sale_id', 'sales_id', 'order_id'], 'sale_id');
        $itemIdCol  = first_existing_column($conn, 'sale_items', ['item_id', 'product_id'], 'item_id');
        $qtyCol     = first_existing_column($conn, 'sale_items', ['qty', 'quantity'], 'qty');
        $priceCol   = first_existing_column($conn, 'sale_items', ['price', 'unit_price', 'unitcost', 'rate'], 'price');

        $itemNameCol   = first_existing_column($conn, 'items', ['name', 'title'], 'name');
        $itemSkuCol    = first_existing_column($conn, 'items', ['sku', 'code'], 'sku');
        $itemClientCol = first_existing_column($conn, 'items', ['client_id'], 'client_id');

        $sql = "
            SELECT
                si.`{$itemIdCol}` AS item_id,
                COALESCE(i.`{$itemSkuCol}`, '') AS barcode,
                COALESCE(i.`{$itemNameCol}`, CONCAT('Item #', si.`{$itemIdCol}`)) AS product_name,
                COALESCE(si.`{$qtyCol}`, 1) AS qty,
                COALESCE(si.`{$priceCol}`, 0) AS unit_price
            FROM sale_items si
            LEFT JOIN items i
                ON i.id = si.`{$itemIdCol}`
               AND i.`{$itemClientCol}` = ?
            WHERE si.`{$saleFk}` = ?
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ii', $clientId, $saleId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $qty = (float)($row['qty'] ?? 1);
                $unitPrice = (float)($row['unit_price'] ?? 0);

                $rows[] = [
                    'item_id'       => (string)($row['item_id'] ?? ''),
                    'barcode'       => (string)($row['barcode'] ?? ''),
                    'product_name'  => (string)($row['product_name'] ?? '-'),
                    'qty'           => $qty > 0 ? $qty : 1,
                    'unit_price'    => $unitPrice,
                    'refund_amount' => $qty * $unitPrice,
                ];
            }

            $stmt->close();
        }
    }

    return $rows;
}

function get_sale_details(mysqli $conn, int $clientId, int $saleId): ?array {
    if (!table_exists($conn, 'sales')) return null;

    $invoiceCol      = first_existing_column($conn, 'sales', ['invoice_no', 'invoice_number', 'sale_no'], 'invoice_no');
    $customerIdCol   = first_existing_column($conn, 'sales', ['customer_id'], 'customer_id');
    $branchCol       = first_existing_column($conn, 'sales', ['branch_id', 'store_id'], 'branch_id');
    $customerNameCol = first_existing_column($conn, 'sales', ['customer_name'], null);

    $customerNameSelect = $customerNameCol ? ", `{$customerNameCol}` AS sales_customer_name" : ", '' AS sales_customer_name";

    $sql = "
        SELECT
            id,
            `{$invoiceCol}` AS invoice_no,
            `{$customerIdCol}` AS customer_id,
            `{$branchCol}` AS branch_id
            {$customerNameSelect}
        FROM sales
        WHERE id=? AND client_id=?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;

    $stmt->bind_param('ii', $saleId, $clientId);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sale) return null;

    $customerId = (int)($sale['customer_id'] ?? 0);
    $fallbackName = trim((string)($sale['sales_customer_name'] ?? ''));

    $customer = get_customer_details($conn, $clientId, $customerId, $fallbackName);
    $items = fetch_sale_items($conn, $clientId, $saleId);

    return [
        'sale'     => $sale,
        'customer' => $customer,
        'items'    => $items,
    ];
}

if (isset($_GET['action']) && $_GET['action'] === 'sale_lookup') {
    $saleId = (int)($_GET['sale_id'] ?? 0);
    if ($saleId <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid invoice selected.'], 422);
    }

    $payload = get_sale_details($conn, $client_id, $saleId);
    if (!$payload) {
        json_response(['ok' => false, 'message' => 'Invoice not found.'], 404);
    }

    json_response(['ok' => true] + $payload);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_return') {
    $returnDate = trim($_POST['return_date'] ?? '');
    $saleId = (int)($_POST['sale_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $refundType = trim($_POST['refund_type'] ?? 'Refund Money');
    $refundNotes = trim($_POST['refund_notes'] ?? '');
    $adjustment = isset($_POST['adjustment']) ? 'On' : 'Off';
    $generateGrn = isset($_POST['generate_grn']) ? 1 : 0;
    $items = $_POST['items'] ?? [];

    if ($returnDate === '' || $saleId <= 0 || $reason === '') {
        json_response(['ok' => false, 'message' => 'Return date, invoice and reason are required.'], 422);
    }

    if (!is_array($items) || count($items) === 0) {
        json_response(['ok' => false, 'message' => 'At least one item is required.'], 422);
    }

    $salePayload = get_sale_details($conn, $client_id, $saleId);
    if (!$salePayload) {
        json_response(['ok' => false, 'message' => 'Selected invoice not found.'], 404);
    }

    $sale = $salePayload['sale'];
    $totalRefund = 0;
    $cleanItems = [];

    foreach ($items as $item) {
        $productName = trim((string)($item['product_name'] ?? ''));
        $barcode = trim((string)($item['barcode'] ?? ''));
        $itemId = trim((string)($item['item_id'] ?? ''));
        $qty = (float)($item['qty'] ?? 0);
        $unitPrice = (float)($item['unit_price'] ?? 0);
        $refundAmount = (float)($item['refund_amount'] ?? ($qty * $unitPrice));

        if ($itemId === '' || $qty <= 0) {
            continue;
        }

        $cleanItems[] = [
            'item_id' => $itemId,
            'barcode' => $barcode,
            'product_name' => $productName !== '' ? $productName : ('Item #' . $itemId),
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'refund_amount' => $refundAmount,
        ];
        $totalRefund += $refundAmount;
    }

    if (empty($cleanItems)) {
        json_response(['ok' => false, 'message' => 'Valid item rows not found.'], 422);
    }

    if (!table_exists($conn, 'returns') || !table_exists($conn, 'return_items')) {
        json_response(['ok' => false, 'message' => 'returns or return_items table not found.'], 500);
    }

    try {
        $conn->begin_transaction();

       $branchId   = (int)($sale['branch_id'] ?? 0);
$customerId = (int)($sale['customer_id'] ?? 0);
$createdAt  = $returnDate . ' ' . date('H:i:s');

/*
    DB schema ke hisaab se allowed status:
    Pending, Refunded, Exchange
*/
if ($refundType === 'Exchange') {
    $status = 'Exchange';
} else {
    $status = 'Refunded';
}

$returnSql = "
    INSERT INTO returns
    (
        client_id,
        branch_id,
        customer_id,
        sale_id,
        reason,
        status,
        total_refund,
        created_at,
        user_id,
        adjustment,
        refund_type,
        refund_notes,
        generate_grn,
        return_date
    )
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
";

$stmt = $conn->prepare($returnSql);
if (!$stmt) {
    throw new Exception('Unable to prepare returns insert query: ' . $conn->error);
}

$stmt->bind_param(
    'iiiissdsisssis',
    $client_id,
    $branchId,
    $customerId,
    $saleId,
    $reason,
    $status,
    $totalRefund,
    $createdAt,
    $user_id,
    $adjustment,
    $refundType,
    $refundNotes,
    $generateGrn,
    $returnDate
);

$stmt->execute();
$returnId = (int)$stmt->insert_id;
$stmt->close();

        $itemQtyCol = first_existing_column($conn, 'return_items', ['qty', 'quantity'], 'qty');

        $hasBarcodeCol     = column_exists($conn, 'return_items', 'barcode');
        $hasProductNameCol = column_exists($conn, 'return_items', 'product_name');
        $hasUnitPriceCol   = column_exists($conn, 'return_items', 'unit_price');
        $hasPriceCol       = column_exists($conn, 'return_items', 'price');
        $hasCreatedAtCol   = column_exists($conn, 'return_items', 'created_at');

        $priceInsertCol = $hasUnitPriceCol ? 'unit_price' : ($hasPriceCol ? 'price' : null);

        $itemColumns = ['return_id', 'item_id', $itemQtyCol];
        $itemValues  = ['?', '?', '?'];
        $itemTypes   = 'iid';

        if ($hasBarcodeCol) {
            $itemColumns[] = 'barcode';
            $itemValues[]  = '?';
            $itemTypes    .= 's';
        }

        if ($hasProductNameCol) {
            $itemColumns[] = 'product_name';
            $itemValues[]  = '?';
            $itemTypes    .= 's';
        }

        if ($priceInsertCol) {
            $itemColumns[] = $priceInsertCol;
            $itemValues[]  = '?';
            $itemTypes    .= 'd';
        }

        $itemColumns[] = 'refund_amount';
        $itemValues[]  = '?';
        $itemTypes    .= 'd';

        if ($hasCreatedAtCol) {
            $itemColumns[] = 'created_at';
            $itemValues[]  = '?';
            $itemTypes    .= 's';
        }

        $itemSql = "INSERT INTO return_items (`" . implode('`,`', $itemColumns) . "`) VALUES (" . implode(',', $itemValues) . ")";
        $stmtItem = $conn->prepare($itemSql);
        if (!$stmtItem) {
            throw new Exception('Unable to prepare return_items insert query.');
        }

        foreach ($cleanItems as $row) {
            $params = [
                $returnId,
                (int)$row['item_id'],
                (float)$row['qty']
            ];

            if ($hasBarcodeCol) {
                $params[] = (string)$row['barcode'];
            }

            if ($hasProductNameCol) {
                $params[] = (string)$row['product_name'];
            }

            if ($priceInsertCol) {
                $params[] = (float)$row['unit_price'];
            }

            $params[] = (float)$row['refund_amount'];

            if ($hasCreatedAtCol) {
                $params[] = $createdAt;
            }

            $stmtItem->bind_param($itemTypes, ...$params);
            $stmtItem->execute();
        }

        $stmtItem->close();
        $conn->commit();

        json_response(['ok' => true, 'message' => 'Return saved successfully.']);
    } catch (Throwable $e) {
        $conn->rollback();
        json_response(['ok' => false, 'message' => 'Save failed: ' . $e->getMessage()], 500);
    }
}

$branchFilter = (int)($_GET['branch_id'] ?? 0);
$dateFilter = trim($_GET['date'] ?? '');
$reasonFilter = trim($_GET['reason'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

$branches = [];
if (table_exists($conn, 'branches')) {
    $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id=? ORDER BY name ASC");
    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $branches[] = $row;
        }
        $stmt->close();
    }
}

$reasons = [];
if (table_exists($conn, 'returns')) {
    $stmt = $conn->prepare("SELECT DISTINCT reason FROM returns WHERE client_id=? AND reason IS NOT NULL AND reason != '' ORDER BY reason ASC");
    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $reasons[] = $row['reason'];
        }
        $stmt->close();
    }
}

$statuses = ['Pending', 'Refunded', 'Exchange'];

$todayReturns = 0;
$monthlyItems = 0;
$totalRefundPaid = 0;
$lowStockItems = 0;

if (table_exists($conn, 'returns')) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM returns WHERE client_id=? AND DATE(created_at)=CURDATE()");
    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $stmt->bind_result($todayReturns);
        $stmt->fetch();
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_refund),0) FROM returns WHERE client_id=?");
    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $stmt->bind_result($totalRefundPaid);
        $stmt->fetch();
        $stmt->close();
    }
}

if (table_exists($conn, 'return_items') && table_exists($conn, 'returns')) {
    $qtyCol = first_existing_column($conn, 'return_items', ['qty', 'quantity'], 'qty');
    $sql = "SELECT COALESCE(SUM(ri.`{$qtyCol}`),0)
            FROM return_items ri
            INNER JOIN returns r ON r.id = ri.return_id
            WHERE r.client_id=?
              AND MONTH(r.created_at)=MONTH(CURDATE())
              AND YEAR(r.created_at)=YEAR(CURDATE())";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $stmt->bind_result($monthlyItems);
        $stmt->fetch();
        $stmt->close();
    }
}

$sales = [];
if (table_exists($conn, 'sales')) {
    $invoiceCol = first_existing_column($conn, 'sales', ['invoice_no', 'invoice_number', 'sale_no'], 'invoice_no');
    $stmt = $conn->prepare("SELECT id, `{$invoiceCol}` AS invoice_no FROM sales WHERE client_id=? ORDER BY id DESC");
    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sales[] = $row;
        }
        $stmt->close();
    }
}

$invoiceMap = get_sales_invoice_map($conn, $client_id);
$customersMap = get_customers_map($conn, $client_id);

$returns = [];
if (table_exists($conn, 'returns')) {
    $sql = "SELECT * FROM returns WHERE client_id=?";
    $params = [$client_id];
    $types = 'i';

    if ($branchFilter > 0) {
        $sql .= " AND branch_id=?";
        $params[] = $branchFilter;
        $types .= 'i';
    }
    if ($dateFilter !== '') {
        $sql .= " AND DATE(created_at)=?";
        $params[] = $dateFilter;
        $types .= 's';
    }
    if ($reasonFilter !== '') {
        $sql .= " AND reason=?";
        $params[] = $reasonFilter;
        $types .= 's';
    }
    if ($statusFilter !== '') {
        $sql .= " AND status=?";
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= " AND (CAST(sale_id AS CHAR) LIKE ? OR CAST(customer_id AS CHAR) LIKE ? OR reason LIKE ? OR status LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'ssss';
    }

    $sql .= " ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $returns[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Returns / Refunds</title>
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
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>html, body{
    min-height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-panel-2:#111827;
    --oms-card:#101827;
    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-muted-2:#64748b;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-orange:#f97316;
    --oms-yellow:#f59e0b;
    --oms-cyan:#06b6d4;
    --oms-red:#ef4444;
}

body{
    margin:0;
    font-family:"Inter","Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    color:var(--oms-text);
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%);
    overflow-x:hidden;
}

/* ================= LAYOUT ================= */
body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

.container-scroll{
    display:flex;
    min-height:100vh;
    align-items:stretch;
}

.sidebar{
    width:var(--current-sidebar-width);
    min-width:var(--current-sidebar-width);
    max-width:var(--current-sidebar-width);
    flex-shrink:0;
    display:flex;
    align-self:stretch;
    background:transparent;
    overflow:visible;
    position:fixed;
    left:0;
    top:0;
    height:100vh;
    z-index:1000;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
}

.sidebar > div{
    width:100%;
    min-height:100vh;
    height:100vh;
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
    transition:width .25s ease, min-width .25s ease, max-width .25s ease;
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
    margin-left:var(--current-sidebar-width);
    flex:1;
    min-width:0;
    min-height:100vh;
    display:flex;
    flex-direction:column;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%);
    transition:margin-left .25s ease;
}

.topbar{
    min-height:70px;
    height:70px;
    background:#070b14;
    border-bottom:1px solid var(--oms-border);
    display:flex;
    align-items:center;
    padding:0;
    position:sticky;
    top:0;
    z-index:1030;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}

.content-wrapper{
    position:relative;
    padding:24px 26px;
    flex:1;
    min-height:calc(100vh - 70px);
    overflow:visible;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%);
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
    position:relative;
    z-index:1;
}

/* ================= SIDEBAR COLLAPSE ================= */
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

/* ================= HEADER ================= */
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.page-title{
    font-size:28px;
    font-weight:900;
    color:#f8fafc;
    margin:0;
    letter-spacing:-.03em;
}

.header-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.btn-soft{
    border:none;
    border-radius:13px;
    padding:11px 17px;
    color:#fff;
    font-weight:900;
    font-size:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    text-decoration:none;
    cursor:pointer;
    box-shadow:0 14px 28px rgba(0,0,0,.20);
    transition:.22s ease;
    height:46px;
}

.btn-soft:hover{
    transform:translateY(-1px);
    color:#fff;
}

.btn-filter{
    background:linear-gradient(135deg,#6d4cff,#7c3aed);
    box-shadow:0 14px 28px rgba(109,76,255,.24);
}

.btn-reset{
    background:rgba(148,163,184,.18);
    border:1px solid rgba(148,163,184,.18);
    color:#e5e7eb;
}

.btn-reset:hover{
    background:rgba(148,163,184,.28);
}

/* ================= ALERTS ================= */
.alert{
    border-radius:14px;
    padding:12px 14px;
    font-weight:800;
    font-size:14px;
    backdrop-filter:blur(14px);
}

.alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.26) !important;
}

.alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

.alert-warning{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.26) !important;
    border-radius:14px;
    font-weight:800;
}

/* ================= STAT CARDS ================= */
.stats-row{
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:16px;
    margin-bottom:18px;
}

.stat-card{
    min-height:112px;
    border-radius:18px;
    padding:20px 22px;
    color:#fff;
    position:relative;
    overflow:visible;
    border:1px solid rgba(255,255,255,.10);
    box-shadow:0 18px 40px rgba(0,0,0,.28);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    isolation:isolate;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease;
    min-width:0;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    border-radius:18px;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:0;
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
    z-index:0;
    opacity:.45;
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22);
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18);
}

.stat-card > div{
    position:relative;
    z-index:3;
}

.stat-card .title{
    font-size:15px;
    font-weight:800;
    color:rgba(255,255,255,.96);
}

.stat-card .sub{
    font-size:13px;
    opacity:.88;
    margin-top:6px;
    font-weight:600;
}

.stat-card .count{
    flex:1;
    min-width:0;
    font-size:clamp(20px, 2vw, 30px);
    font-weight:900;
    line-height:1.1;
    text-align:right;
    letter-spacing:-.03em;
    word-break:break-word;
    overflow-wrap:anywhere;
    white-space:normal;
}

.card-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff); }
.card-green{ background:linear-gradient(135deg,#047857,#16a34a); }
.card-cyan{ background:linear-gradient(135deg,#0891b2,#06b6d4); }
.card-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c); }

/* ================= FILTER BAR ================= */
.filter-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46));
    border:1px solid rgba(148,163,184,.16);
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045);
    border-radius:18px;
    padding:14px;
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:12px;
    align-items:end;
    margin-bottom:18px;
}

.field-group{
    display:flex;
    flex-direction:column;
    gap:7px;
    min-width:0;
    flex:1;
}

.field-group label{
    font-size:14px;
    font-weight:900;
    color:#cbd5e1;
    margin:0;
}

.filter-control{
    width:100%;
    height:46px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:13px;
    padding:0 14px;
    outline:none;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-weight:700;
    box-shadow:none;
    transition:.2s;
}

.filter-control:focus{
    background:#111827;
    color:#fff;
    border-color:rgba(109,76,255,.55);
    box-shadow:0 0 0 3px rgba(109,76,255,.14);
}

.filter-control option{
    background:#0f172a;
    color:#e5e7eb;
}

/* ================= TABLE ================= */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46));
    border:1px solid rgba(148,163,184,.16);
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045);
    border-radius:18px;
    overflow:hidden;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, background .25s ease;
}

.table-card:hover{
    border-color:rgba(139,92,246,.45);
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06);
}

.table-responsive{
    overflow:auto;
}

.report-table{
    width:100%;
    min-width:900px;
    border-collapse:collapse;
    background:transparent;
}

.report-table th:first-child,
.report-table td:first-child{
    width:48px;
    text-align:center;
}

.report-table thead th{
    background:rgba(7,11,20,.58);
    color:#cbd5e1;
    font-weight:900;
    font-size:13px;
    padding:15px 14px;
    border-bottom:1px solid rgba(148,163,184,.16);
    border-right:1px solid rgba(148,163,184,.08);
    white-space:nowrap;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.report-table tbody td{
    padding:15px 14px;
    border-top:1px solid rgba(148,163,184,.10);
    vertical-align:middle;
    font-size:14px;
    color:#e5e7eb;
    font-weight:600;
    background:rgba(15,23,42,.20);
}

.report-table tbody tr:hover td{
    background:rgba(124,58,237,.12);
    color:#fff;
}

.report-table input[type="checkbox"]{
    width:16px;
    height:16px;
    accent-color:#7c3aed;
}

.amount-strong{
    font-weight:900;
    color:#f8fafc;
}

/* ================= ACTION BUTTONS ================= */
.action-wrap{
    display:flex;
    align-items:center;
    gap:10px;
}

.action-btn{
    width:40px;
    height:40px;
    border:none;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:.2s ease;
    font-size:15px;
    box-shadow:0 12px 24px rgba(0,0,0,.18);
}

.action-btn.edit{
    background:rgba(245,158,11,.15);
    color:#fbbf24;
    border:1px solid rgba(245,158,11,.24);
}

.action-btn.edit:hover{
    background:#f59e0b;
    color:#07111f;
    transform:translateY(-2px);
}

.action-btn.delete{
    background:rgba(239,68,68,.14);
    color:#f87171;
    border:1px solid rgba(239,68,68,.24);
}

.action-btn.delete:hover{
    background:#ef4444;
    color:#fff;
    transform:translateY(-2px);
}

/* ================= FOOTER ================= */
.table-footer{
    padding:16px;
    border-top:1px solid rgba(148,163,184,.13);
    background:rgba(7,11,20,.24);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.footer-note{
    color:#94a3b8;
    font-size:14px;
    font-weight:700;
}

.footer-note strong{
    color:#f8fafc;
}

.empty-state{
    text-align:center;
    padding:30px 16px !important;
    color:#94a3b8 !important;
    font-size:14px;
    font-weight:700;
    background:rgba(15,23,42,.34) !important;
}

/* ================= MODAL DARK ================= */
.modal-content{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.94));
    border:1px solid rgba(148,163,184,.22);
    border-radius:18px;
    color:#f8fafc;
    box-shadow:0 24px 70px rgba(0,0,0,.55);
}

.modal-header,
.modal-footer{
    border-color:rgba(148,163,184,.14);
}

.modal-title{
    font-weight:900;
}

.btn-close{
    filter:invert(1);
    opacity:.75;
}

.form-label{
    color:#cbd5e1;
    font-weight:900;
}

.form-control{
    height:46px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:13px;
    padding:0 14px;
    outline:none;
    background:rgba(15,23,42,.72);
    color:#e5e7eb;
    font-weight:700;
    box-shadow:none;
}

.form-control:focus{
    background:#111827;
    color:#fff;
    border-color:rgba(109,76,255,.55);
    box-shadow:0 0 0 3px rgba(109,76,255,.14);
}

.form-control option{
    background:#0f172a;
    color:#e5e7eb;
}

.btn-primary{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-color:rgba(139,92,246,.45) !important;
    border-radius:12px;
    font-weight:900;
    box-shadow:0 14px 28px rgba(109,76,255,.24);
}

.btn-secondary{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px;
    font-weight:900;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1300px){
    .stat-card{
        padding:18px 16px;
        gap:10px;
    }

    .stat-card .count{
        font-size:clamp(18px, 1.55vw, 26px);
    }
}

@media (max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2,1fr);
    }

    .filter-bar{
        grid-template-columns:1fr 1fr;
    }
}

@media (max-width:991px){
    .container-scroll{
        flex-direction:column;
    }

    .sidebar{
        width:100%;
        min-width:100%;
        max-width:100%;
        position:relative;
        height:auto;
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
        margin-left:0;
    }

    .topbar{
        position:relative;
    }

    .content-wrapper{
        padding:18px;
    }
}

@media (max-width:768px){
    .stats-row{
        grid-template-columns:1fr;
    }

    .filter-bar{
        grid-template-columns:1fr;
    }

    .field-group,
    .btn-soft{
        width:100%;
        min-width:100%;
    }

    .page-title{
        font-size:24px;
    }

    .stat-card{
        min-height:112px;
        align-items:flex-start;
        flex-direction:column;
    }

    .stat-card .count{
        width:100%;
        text-align:left;
        font-size:26px;
    }
}
/* =====================================================
   RETURNS PAGE FINAL FIX - FILTER, BUTTONS, TABLE
   Paste at END before 


/* Header buttons visible and premium */
.btn-add{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    border:1px solid rgba(34,197,94,.28) !important;
    opacity:1 !important;
}

.btn-export{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:1px solid rgba(139,92,246,.38) !important;
    opacity:1 !important;
}

.btn-add:hover,
.btn-export:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    filter:saturate(1.08);
}

/* Filter ko proper one-line layout do */
.filter-bar{
    display:grid !important;
    grid-template-columns: 1fr .9fr 1fr 1fr 1.45fr auto auto !important;
    gap:10px !important;
    align-items:center !important;
    padding:14px !important;
    margin-bottom:18px !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:18px !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
}

/* Select/date/search same style */
.filter-control,
.search-wrap input{
    width:100% !important;
    min-width:0 !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
    font-size:14px !important;
    outline:none !important;
    box-shadow:none !important;
}

.filter-control{
    padding:0 14px !important;
}

.filter-control option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.search-wrap{
    width:100% !important;
    min-width:0 !important;
    position:relative !important;
    flex:none !important;
}

.search-wrap i{
    position:absolute !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#94a3b8 !important;
    z-index:2 !important;
}

.search-wrap input{
    padding:0 14px 0 42px !important;
}

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.filter-control:focus,
.search-wrap input:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
}

/* Filter + Reset buttons */
.filter-btn{
    width:auto !important;
    min-width:104px !important;
    height:46px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:14px !important;
    text-decoration:none !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
    white-space:nowrap !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.filter-btn:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

.filter-btn.reset{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 14px 28px rgba(0,0,0,.18) !important;
}

.filter-btn.reset:hover{
    background:rgba(148,163,184,.28) !important;
}

/* Returns table fix */
.returns-table{
    width:100% !important;
    min-width:1100px !important;
    border-collapse:collapse !important;
    background:transparent !important;
}

.returns-table thead th{
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
    opacity:1 !important;
}

.returns-table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.24) !important;
    opacity:1 !important;
}

.returns-table tbody tr:hover td{
    background:rgba(124,58,237,.13) !important;
    color:#fff !important;
}

.returns-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.returns-table strong{
    color:#f8fafc !important;
}

/* Empty row readable */
.returns-table td.text-center,
.returns-table td[colspan]{
    color:#94a3b8 !important;
    background:rgba(15,23,42,.32) !important;
    font-weight:900 !important;
    padding:34px 16px !important;
}

/* Table footer readable */
.table-footer{
    padding:16px !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
    background:rgba(7,11,20,.28) !important;
    color:#94a3b8 !important;
    font-size:14px !important;
    font-weight:800 !important;
}

.table-footer strong{
    color:#f8fafc !important;
}

/* Status badge readable */
.status-badge{
    display:inline-flex !important;
    align-items:center !important;
    gap:8px !important;
    padding:7px 12px !important;
    border-radius:999px !important;
    font-size:12px !important;
    font-weight:900 !important;
    background:rgba(15,23,42,.68) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.status-dot{
    width:8px !important;
    height:8px !important;
    border-radius:50% !important;
    display:inline-block !important;
}

.dot-green{ background:#22c55e !important; }
.dot-yellow{ background:#f59e0b !important; }
.dot-blue{ background:#3b82f6 !important; }

/* Table card thora clear */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
}

/* Responsive */
@media (max-width:1350px){
    .filter-bar{
        grid-template-columns: 1fr 1fr 1fr 1fr !important;
    }

    .search-wrap{
        grid-column:span 2 !important;
    }

    .filter-btn{
        width:100% !important;
    }
}

@media (max-width:768px){
    .filter-bar{
        grid-template-columns:1fr !important;
    }

    .search-wrap{
        grid-column:auto !important;
    }

    .filter-control,
    .search-wrap,
    .filter-btn{
        width:100% !important;
        min-width:100% !important;
    }
}

/* =====================================================
   RETURNS POPUP FINAL FIX - DARK PURPLE PREMIUM MODAL
   Paste at END before
===================================================== */

#returnModal{
    z-index:99999 !important;
}

#returnModal .modal-dialog{
    max-width:1220px !important;
    width:calc(100% - 40px) !important;
}

#returnModal .modal-content.return-modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.98), rgba(17,24,39,.96)) !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:20px !important;
    color:#f8fafc !important;
    overflow:hidden !important;
    box-shadow:
        0 30px 90px rgba(0,0,0,.72),
        0 0 55px rgba(124,58,237,.16) !important;
}

#returnModal .return-modal-header{
    min-height:70px !important;
    padding:18px 22px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#returnModal .modal-title{
    color:#f8fafc !important;
    font-size:21px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
}

#returnModal .btn-close{
    width:42px !important;
    height:42px !important;
    border-radius:13px !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    position:relative !important;
    background-image:none !important;
}

#returnModal .btn-close::before,
#returnModal .btn-close::after{
    content:"";
    position:absolute;
    left:50%;
    top:50%;
    width:20px;
    height:2px;
    background:#e5e7eb;
    border-radius:999px;
}

#returnModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg);
}

#returnModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg);
}

#returnModal .btn-close:hover{
    background:rgba(239,68,68,.22) !important;
    border-color:rgba(239,68,68,.35) !important;
}

#returnModal .return-modal-body{
    padding:24px 22px !important;
    background:rgba(15,23,42,.52) !important;
    color:#f8fafc !important;
    max-height:calc(100vh - 190px) !important;
    overflow-y:auto !important;
}

#returnModal .return-modal-body::-webkit-scrollbar{
    width:7px;
}

#returnModal .return-modal-body::-webkit-scrollbar-track{
    background:rgba(15,23,42,.7);
}

#returnModal .return-modal-body::-webkit-scrollbar-thumb{
    background:#7c3aed;
    border-radius:999px;
}

#returnModal .return-modal-footer{
    padding:16px 22px 18px !important;
    background:rgba(7,11,20,.48) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

#returnModal .return-label,
#returnModal label,
#returnModal .small.muted{
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:7px !important;
}

#returnModal .muted{
    color:#94a3b8 !important;
}

#returnModal .form-control,
#returnModal .form-select,
#returnModal input,
#returnModal select,
#returnModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    min-height:48px !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#returnModal textarea.form-control{
    min-height:96px !important;
    padding:12px 14px !important;
}

#returnModal .form-control:focus,
#returnModal .form-select:focus,
#returnModal input:focus,
#returnModal select:focus,
#returnModal textarea:focus{
    background:#fff !important;
    color:#111827 !important;
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 4px rgba(124,58,237,.22),
        0 10px 22px rgba(0,0,0,.18) !important;
}

#returnModal .return-customer-box,
#returnModal .return-item-card{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.14), transparent 34%),
        rgba(15,23,42,.68) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:16px !important;
    padding:14px !important;
    color:#f8fafc !important;
    box-shadow:0 14px 30px rgba(0,0,0,.18) !important;
}

#returnModal .return-items-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:10px !important;
    margin-bottom:10px !important;
}

#returnModal .return-item-grid{
    display:grid !important;
    grid-template-columns:120px 1.5fr 120px 140px 150px 48px !important;
    gap:10px !important;
    align-items:end !important;
}

#returnModal .return-total-box{
    min-height:48px !important;
    display:flex !important;
    align-items:center !important;
    padding:0 14px !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.78) !important;
    border:1px solid rgba(34,197,94,.24) !important;
    color:#4ade80 !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#returnModal .return-find-btn,
#returnModal .return-save-btn{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:0 18px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#returnModal .return-find-btn:hover,
#returnModal .return-save-btn:hover{
    transform:translateY(-1px) !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
}

#returnModal .return-cancel-btn{
    min-height:46px !important;
    border-radius:12px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    padding:0 18px !important;
}

#returnModal .return-cancel-btn:hover{
    background:rgba(148,163,184,.28) !important;
    color:#fff !important;
}

#returnModal .return-add-row-btn{
    min-height:40px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:0 14px !important;
}

#returnModal .return-remove-btn{
    width:44px !important;
    height:48px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(239,68,68,.18) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

#returnModal .return-remove-btn:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

#returnModal .form-check{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:14px !important;
    padding:12px 14px 12px 40px !important;
}

#returnModal .form-check-label{
    color:#e5e7eb !important;
    font-weight:800 !important;
}

#returnModal .form-check-input{
    width:18px !important;
    height:18px !important;
    background-color:#fff !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

#returnModal .form-check-input:checked{
    background-color:#7c3aed !important;
    border-color:#7c3aed !important;
}

/* Select2 invoice fix */
#returnModal .select2-container{
    width:100% !important;
}

#returnModal .select2-container .select2-selection--single{
    height:48px !important;
    background:#fff !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    display:flex !important;
    align-items:center !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#returnModal .select2-container--default .select2-selection--single .select2-selection__rendered{
    color:#111827 !important;
    line-height:46px !important;
    padding-left:14px !important;
    font-weight:700 !important;
}

#returnModal .select2-container--default .select2-selection--single .select2-selection__arrow{
    height:46px !important;
}

.select2-dropdown{
    background:#0f172a !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:12px !important;
    overflow:hidden !important;
    box-shadow:0 20px 50px rgba(0,0,0,.45) !important;
}

.select2-results__option{
    color:#e5e7eb !important;
    padding:10px 14px !important;
}

.select2-results__option--highlighted{
    background:#7c3aed !important;
    color:#fff !important;
}

#returnModal #returnAlert .alert{
    border-radius:12px !important;
    font-weight:800 !important;
}

/* Modal responsive */
@media (max-width:991px){
    #returnModal .modal-dialog{
        width:calc(100% - 20px) !important;
        margin:10px auto !important;
    }

    #returnModal .return-item-grid{
        grid-template-columns:1fr 1fr !important;
    }
}

@media (max-width:576px){
    #returnModal .return-item-grid{
        grid-template-columns:1fr !important;
    }

    #returnModal .return-modal-footer{
        flex-direction:column !important;
    }

    #returnModal .return-cancel-btn,
    #returnModal .return-save-btn{
        width:100% !important;
    }
}


/* =====================================================
   RETURNS POPUP SMALL COMPACT FIX
   Paste at VERY END before
===================================================== */

/* Page kam hide ho */
body.modal-open .modal-backdrop.show{
    opacity:.42 !important;
    background:#000 !important;
}

/* Popup width small */
#returnModal .return-small-modal,
#returnModal .modal-dialog{
    max-width:680px !important;
    width:680px !important;
    margin:1rem auto !important;
}

/* Popup height control */
#returnModal .modal-content.return-modal-content{
    max-height:84vh !important;
    border-radius:16px !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.25), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.88), rgba(17,24,39,.72)) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
}

/* Header small */
#returnModal .return-modal-header{
    min-height:50px !important;
    padding:10px 16px !important;
}

#returnModal .modal-title{
    font-size:17px !important;
}

/* Body compact + scroll */
#returnModal .return-modal-body{
    padding:14px 16px !important;
    max-height:60vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* Form rows compact */
#returnModal .row{
    --bs-gutter-x:10px !important;
    --bs-gutter-y:10px !important;
}

/* Labels small */
#returnModal .return-label,
#returnModal label,
#returnModal .small.muted{
    font-size:11px !important;
    margin-bottom:4px !important;
}

/* Inputs small */
#returnModal .form-control,
#returnModal .form-select,
#returnModal input,
#returnModal select,
#returnModal textarea{
    min-height:34px !important;
    height:34px !important;
    border-radius:9px !important;
    padding:5px 10px !important;
    font-size:12px !important;
}

/* Notes textarea small */
#returnModal textarea.form-control{
    min-height:52px !important;
    height:52px !important;
    padding:7px 10px !important;
}

/* Select2 invoice small */
#returnModal .select2-container .select2-selection--single{
    height:34px !important;
    min-height:34px !important;
    border-radius:9px !important;
}

#returnModal .select2-container--default .select2-selection--single .select2-selection__rendered{
    line-height:32px !important;
    font-size:12px !important;
    padding-left:10px !important;
}

#returnModal .select2-container--default .select2-selection--single .select2-selection__arrow{
    height:32px !important;
}

/* Find button small */
#returnModal .return-find-btn,
#returnModal .return-save-btn,
#returnModal .return-cancel-btn{
    min-height:34px !important;
    height:34px !important;
    border-radius:9px !important;
    font-size:12px !important;
    padding:0 12px !important;
}

/* Customer box compact */
#returnModal .return-customer-box{
    padding:10px 12px !important;
    border-radius:12px !important;
    margin-bottom:10px !important;
}

/* Add row button compact */
#returnModal .return-items-head{
    margin-bottom:8px !important;
}

#returnModal .return-add-row-btn{
    min-height:34px !important;
    height:34px !important;
    border-radius:9px !important;
    font-size:12px !important;
    padding:0 12px !important;
}

/* Item row card compact */
#returnModal .return-item-card{
    padding:10px !important;
    border-radius:12px !important;
    margin-bottom:8px !important;
}

/* Return item grid smaller */
#returnModal .return-item-grid{
    grid-template-columns:80px 1.4fr 70px 90px 100px 38px !important;
    gap:8px !important;
    align-items:end !important;
}

/* Remove button small */
#returnModal .return-remove-btn{
    width:34px !important;
    height:34px !important;
    border-radius:9px !important;
}

/* Total refund box small */
#returnModal .return-total-box{
    min-height:34px !important;
    height:34px !important;
    border-radius:9px !important;
    padding:0 10px !important;
    font-size:12px !important;
}

/* Checkboxes compact */
#returnModal .form-check{
    padding:8px 10px 8px 34px !important;
    border-radius:10px !important;
    margin-bottom:6px !important;
}

#returnModal .form-check-input{
    width:15px !important;
    height:15px !important;
    min-height:15px !important;
}

#returnModal .form-check-label{
    font-size:12px !important;
}

/* Footer small */
#returnModal .return-modal-footer{
    padding:10px 16px !important;
    min-height:52px !important;
    gap:8px !important;
}

/* Close button small */
#returnModal .btn-close{
    width:32px !important;
    height:32px !important;
    border-radius:10px !important;
}

#returnModal .btn-close::before,
#returnModal .btn-close::after{
    width:15px !important;
}

/* Mobile responsive */
@media(max-width:760px){
    #returnModal .return-small-modal,
    #returnModal .modal-dialog{
        width:calc(100% - 18px) !important;
        max-width:calc(100% - 18px) !important;
        margin:.65rem auto !important;
    }

    #returnModal .return-modal-body{
        max-height:64vh !important;
    }

    #returnModal .return-item-grid{
        grid-template-columns:1fr !important;
    }

    #returnModal .return-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #returnModal .return-cancel-btn,
    #returnModal .return-save-btn{
        width:100% !important;
    }
}
/* =====================================================
   RETURNS MOBILE FINAL FIX
   Header buttons one line + 2 cards + filter alignment
   Paste at VERY END before 
===================================================== */

@media (max-width:768px){

    body{
        overflow-x:hidden !important;
    }

    .content-wrapper{
        padding:16px 12px 24px 12px !important;
    }

    .page-header{
        display:flex !important;
        align-items:flex-start !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .page-title{
        width:100% !important;
        font-size:24px !important;
        line-height:1.15 !important;
        margin-bottom:0 !important;
    }

    /* New Return + Print one line */
    .header-actions{
        width:100% !important;
        display:grid !important;
        grid-template-columns:1fr 1fr !important;
        gap:8px !important;
        flex-wrap:nowrap !important;
    }

    .header-actions .btn-soft,
    .btn-soft{
        width:100% !important;
        min-width:0 !important;
        height:44px !important;
        padding:0 10px !important;
        border-radius:14px !important;
        font-size:13px !important;
        justify-content:center !important;
        white-space:nowrap !important;
    }

    .header-actions .btn-soft i{
        font-size:14px !important;
        margin-right:2px !important;
    }

    /* Cards 2 per row */
    .stats-row{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .stat-card{
        min-height:86px !important;
        border-radius:16px !important;
        padding:12px 10px !important;
        gap:6px !important;
        display:flex !important;
        flex-direction:column !important;
        align-items:flex-start !important;
        justify-content:center !important;
        overflow:hidden !important;
    }

    .stat-left{
        width:100% !important;
        min-width:0 !important;
        position:relative !important;
        z-index:3 !important;
    }

    .stat-card .title{
        font-size:12px !important;
        line-height:1.15 !important;
        font-weight:900 !important;
        margin:0 !important;
        white-space:normal !important;
    }

    .stat-card .title i{
        font-size:13px !important;
        margin-right:4px !important;
    }

    .stat-card .sub{
        font-size:10px !important;
        line-height:1.15 !important;
        margin-top:4px !important;
    }

    .stat-card .count{
        width:100% !important;
        text-align:left !important;
        font-size:18px !important;
        line-height:1.08 !important;
        margin-top:6px !important;
        word-break:break-word !important;
        overflow-wrap:anywhere !important;
        position:relative !important;
        z-index:3 !important;
    }

    .stat-card::after{
        width:48px !important;
        height:48px !important;
        right:8px !important;
        top:10px !important;
        border-radius:16px !important;
        opacity:.35 !important;
    }

    /* Filter box 2 columns */
    .filter-bar{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        padding:12px !important;
        border-radius:18px !important;
        margin-bottom:14px !important;
        align-items:center !important;
    }

    .filter-control{
        width:100% !important;
        min-width:0 !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:12px !important;
        padding:0 10px !important;
    }

    .search-wrap{
        grid-column:1 / -1 !important;
        width:100% !important;
        min-width:0 !important;
    }

    .search-wrap input{
        width:100% !important;
        min-width:0 !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:12px !important;
        padding-left:38px !important;
        padding-right:10px !important;
    }

    .search-wrap i{
        left:13px !important;
        font-size:14px !important;
    }

    /* Filter + Reset buttons one line */
    .filter-bar .filter-btn{
        width:100% !important;
        min-width:0 !important;
        height:44px !important;
        border-radius:14px !important;
        font-size:13px !important;
        padding:0 10px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        white-space:nowrap !important;
    }

    .table-card{
        border-radius:18px !important;
        margin-top:0 !important;
    }
}

/* Extra small 328px / 360px */
@media (max-width:380px){

    .content-wrapper{
        padding-left:10px !important;
        padding-right:10px !important;
    }

    .page-title{
        font-size:23px !important;
    }

    .header-actions{
        gap:7px !important;
    }

    .header-actions .btn-soft{
        height:42px !important;
        font-size:12px !important;
        border-radius:13px !important;
    }

    .stats-row{
        gap:9px !important;
    }

    .stat-card{
        min-height:82px !important;
        padding:10px 9px !important;
    }

    .stat-card .title{
        font-size:11px !important;
    }

    .stat-card .sub{
        font-size:9px !important;
    }

    .stat-card .count{
        font-size:16px !important;
    }

    .filter-bar{
        gap:9px !important;
        padding:11px !important;
    }

    .filter-control,
    .search-wrap input{
        height:40px !important;
        font-size:11px !important;
    }

    .filter-bar .filter-btn{
        height:42px !important;
        font-size:12px !important;
    }
}


/* =====================================================
   RETURNS POPUP INPUT HOVER / FOCUS WHITE REMOVE
   Paste at VERY END before 
===================================================== */

/* modal inputs normal dark glass */
#returnModal .form-control,
#returnModal .form-select,
#returnModal input,
#returnModal select,
#returnModal textarea{
    background:rgba(8,13,31,.88) !important;
    color:#f8fafc !important;
    border:1px solid rgba(167,139,250,.28) !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.06),
        0 10px 24px rgba(0,0,0,.18) !important;
}

/* hover par white na ho */
#returnModal .form-control:hover,
#returnModal .form-select:hover,
#returnModal input:hover,
#returnModal select:hover,
#returnModal textarea:hover{
    background:rgba(15,23,42,.92) !important;
    color:#ffffff !important;
    border-color:rgba(139,92,246,.55) !important;
}

/* focus/click par transparent purple glass */
#returnModal .form-control:focus,
#returnModal .form-select:focus,
#returnModal input:focus,
#returnModal select:focus,
#returnModal textarea:focus{
    background:
        radial-gradient(circle at 10% 0%, rgba(124,58,237,.18), transparent 38%),
        rgba(8,13,31,.96) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.26),
        inset 0 1px 0 rgba(255,255,255,.08),
        0 14px 30px rgba(0,0,0,.26) !important;
    outline:none !important;
}

/* placeholder color */
#returnModal input::placeholder,
#returnModal textarea::placeholder{
    color:rgba(203,213,225,.58) !important;
}

/* select options dark */
#returnModal select option{
    background:#0f172a !important;
    color:#f8fafc !important;
}

/* Select2 invoice box dark glass */
#returnModal .select2-container--default .select2-selection--single{
    background:rgba(8,13,31,.88) !important;
    color:#f8fafc !important;
    border:1px solid rgba(167,139,250,.28) !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.06),
        0 10px 24px rgba(0,0,0,.18) !important;
}

#returnModal .select2-container--default .select2-selection--single:hover,
#returnModal .select2-container--open .select2-selection--single{
    background:rgba(15,23,42,.94) !important;
    border-color:rgba(139,92,246,.65) !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.22) !important;
}

#returnModal .select2-container--default .select2-selection--single .select2-selection__rendered{
    color:#f8fafc !important;
}

/* Select2 dropdown search input white remove */
.select2-container--open .select2-search--dropdown{
    background:#0f172a !important;
    padding:8px !important;
}

.select2-container--open .select2-search--dropdown .select2-search__field{
    background:rgba(8,13,31,.96) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.55) !important;
    border-radius:9px !important;
    outline:none !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.18) !important;
}

/* Select2 dropdown hover glass */
.select2-dropdown{
    background:#0f172a !important;
    border:1px solid rgba(139,92,246,.45) !important;
    box-shadow:0 20px 55px rgba(0,0,0,.55) !important;
}

.select2-results__option{
    background:rgba(15,23,42,.90) !important;
    color:#e5e7eb !important;
}

.select2-results__option:hover,
.select2-results__option--highlighted{
    background:
        linear-gradient(135deg, rgba(124,58,237,.90), rgba(109,76,255,.82)) !important;
    color:#ffffff !important;
}

/* readonly inputs bhi dark rahen */
#returnModal input[readonly],
#returnModal textarea[readonly]{
    background:rgba(15,23,42,.72) !important;
    color:#cbd5e1 !important;
    border-color:rgba(148,163,184,.22) !important;
}

/* =========================================================
   ONEERP RETURNS / REFUNDS LIGHT MODE
   MUST REMAIN AT THE END OF THE MAIN STYLE
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

html[data-pos-theme="light"] .container-scroll,
html[data-pos-theme="light"] .main-panel{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] .sidebar,
html[data-pos-theme="light"] .sidebar .sidebar-wrap,
html[data-pos-theme="light"] #dashboardSidebar .sidebar-wrap,
html[data-pos-theme="light"] #sidebar .sidebar-wrap{
    background:#ffffff !important;
    border-right-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] .topbar{
    background:#ffffff !important;
    border-bottom-color:#e2e8f0 !important;
    box-shadow:0 4px 18px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] .content-wrapper{
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
            #f1f5f9 42%,
            #faf7ff 72%,
            #f8fafc 100%
        ) !important;
}

html[data-pos-theme="light"] .content-wrapper::before{
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

html[data-pos-theme="light"] .content-wrapper::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.09),
        transparent 68%
    ) !important;
    opacity:.45 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* New Return and Print branded buttons */
html[data-pos-theme="light"] .btn-add{
    background:linear-gradient(135deg,#059669,#16a34a) !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"] .btn-export{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] .stat-card .title,
html[data-pos-theme="light"] .stat-card .sub,
html[data-pos-theme="light"] .stat-card .count,
html[data-pos-theme="light"] .stat-card i{
    color:#ffffff !important;
    opacity:1 !important;
}

/* =========================================================
   FILTER BAR
========================================================= */

html[data-pos-theme="light"] .filter-bar{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.055),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] .filter-control,
html[data-pos-theme="light"] .search-wrap input{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .filter-control:hover,
html[data-pos-theme="light"] .search-wrap input:hover{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] .filter-control:focus,
html[data-pos-theme="light"] .search-wrap input:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] .search-wrap input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .search-wrap i{
    color:#64748b !important;
}

html[data-pos-theme="light"] .filter-control option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] input[type="date"]{
    color-scheme:light !important;
}

html[data-pos-theme="light"] .filter-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
    box-shadow:0 10px 22px rgba(124,58,237,.18) !important;
}

html[data-pos-theme="light"] .filter-btn.reset{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] .filter-btn.reset:hover{
    background:#e2e8f0 !important;
    color:#0f172a !important;
}

/* =========================================================
   RETURNS TABLE CARD
========================================================= */

html[data-pos-theme="light"] .table-card{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.04),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] .table-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:
        0 16px 36px rgba(15,23,42,.09),
        0 0 0 1px rgba(124,58,237,.07) !important;
}

/* Table headings */
html[data-pos-theme="light"] .returns-table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom-color:#cbd5e1 !important;
    border-right-color:#e2e8f0 !important;
    opacity:1 !important;
}

/* Table rows */
html[data-pos-theme="light"] .returns-table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-top-color:#e8edf4 !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .returns-table tbody tr:hover td{
    background:#f6f2ff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] .returns-table strong,
html[data-pos-theme="light"] .amount-strong{
    color:#0f172a !important;
}

html[data-pos-theme="light"] .returns-table .muted,
html[data-pos-theme="light"] .returns-table .small{
    color:#64748b !important;
}

html[data-pos-theme="light"] .returns-table td.text-center,
html[data-pos-theme="light"] .returns-table td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Status badge */
html[data-pos-theme="light"] .status-badge{
    background:#f8fafc !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

/* Table footer */
html[data-pos-theme="light"] .table-footer{
    background:#f8fafc !important;
    color:#64748b !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] .table-footer strong{
    color:#0f172a !important;
}

/* =========================================================
   ALERTS
========================================================= */

html[data-pos-theme="light"] .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] .alert-warning{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

/* =========================================================
   RETURN MODAL LIGHT MODE
========================================================= */

html[data-pos-theme="light"] #returnModal
.modal-content.return-modal-content{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#d8e0eb !important;
    box-shadow:
        0 28px 80px rgba(15,23,42,.20),
        0 0 0 1px rgba(124,58,237,.06) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Modal header */
html[data-pos-theme="light"] #returnModal .return-modal-header{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] #returnModal .modal-title{
    color:#0f172a !important;
}

/* Modal body */
html[data-pos-theme="light"] #returnModal .return-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Modal footer */
html[data-pos-theme="light"] #returnModal .return-modal-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

/* Labels */
html[data-pos-theme="light"] #returnModal .return-label,
html[data-pos-theme="light"] #returnModal label,
html[data-pos-theme="light"] #returnModal .small.muted{
    color:#334155 !important;
}

/* Normal inputs */
html[data-pos-theme="light"] #returnModal .form-control,
html[data-pos-theme="light"] #returnModal .form-select,
html[data-pos-theme="light"] #returnModal input,
html[data-pos-theme="light"] #returnModal select,
html[data-pos-theme="light"] #returnModal textarea{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

/* Input hover */
html[data-pos-theme="light"] #returnModal .form-control:hover,
html[data-pos-theme="light"] #returnModal .form-select:hover,
html[data-pos-theme="light"] #returnModal input:hover,
html[data-pos-theme="light"] #returnModal select:hover,
html[data-pos-theme="light"] #returnModal textarea:hover{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#a78bfa !important;
}

/* Input focus */
html[data-pos-theme="light"] #returnModal .form-control:focus,
html[data-pos-theme="light"] #returnModal .form-select:focus,
html[data-pos-theme="light"] #returnModal input:focus,
html[data-pos-theme="light"] #returnModal select:focus,
html[data-pos-theme="light"] #returnModal textarea:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

/* Placeholder */
html[data-pos-theme="light"] #returnModal input::placeholder,
html[data-pos-theme="light"] #returnModal textarea::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

/* Select options */
html[data-pos-theme="light"] #returnModal select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Readonly fields */
html[data-pos-theme="light"] #returnModal input[readonly],
html[data-pos-theme="light"] #returnModal textarea[readonly]{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-color:#d8e0eb !important;
}

/* Customer and item boxes */
html[data-pos-theme="light"] #returnModal .return-customer-box,
html[data-pos-theme="light"] #returnModal .return-item-card{
    background:#f8fafc !important;
    color:#0f172a !important;
    border-color:#dce4ef !important;
    box-shadow:0 6px 18px rgba(15,23,42,.05) !important;
}

html[data-pos-theme="light"] #returnModal .muted{
    color:#64748b !important;
}

/* Total refund */
html[data-pos-theme="light"] #returnModal .return-total-box{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
    box-shadow:none !important;
}

/* Checkboxes */
html[data-pos-theme="light"] #returnModal .form-check{
    background:#f8fafc !important;
    border-color:#dce4ef !important;
}

html[data-pos-theme="light"] #returnModal .form-check-label{
    color:#334155 !important;
}

/* Cancel button */
html[data-pos-theme="light"] #returnModal .return-cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] #returnModal .return-cancel-btn:hover{
    background:#cbd5e1 !important;
    color:#0f172a !important;
}

/* Close button */
html[data-pos-theme="light"] #returnModal .btn-close{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] #returnModal .btn-close::before,
html[data-pos-theme="light"] #returnModal .btn-close::after{
    background:#334155 !important;
}

/* Modal scrollbar */
html[data-pos-theme="light"] #returnModal
.return-modal-body::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"] #returnModal
.return-modal-body::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
}

/* =========================================================
   SELECT2 LIGHT MODE
========================================================= */

html[data-pos-theme="light"] #returnModal
.select2-container--default .select2-selection--single{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] #returnModal
.select2-container--default
.select2-selection--single
.select2-selection__rendered{
    color:#0f172a !important;
}

html[data-pos-theme="light"] #returnModal
.select2-container--default
.select2-selection--single:hover,

html[data-pos-theme="light"] #returnModal
.select2-container--open
.select2-selection--single{
    background:#ffffff !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

/* Select2 dropdown */
html[data-pos-theme="light"] .select2-dropdown{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
    box-shadow:0 18px 42px rgba(15,23,42,.16) !important;
}

html[data-pos-theme="light"] .select2-results__option{
    background:#ffffff !important;
    color:#1e293b !important;
}

html[data-pos-theme="light"]
.select2-results__option:hover,

html[data-pos-theme="light"]
.select2-results__option--highlighted{
    background:#7c3aed !important;
    color:#ffffff !important;
}

html[data-pos-theme="light"]
.select2-container--open .select2-search--dropdown{
    background:#ffffff !important;
}

html[data-pos-theme="light"]
.select2-container--open
.select2-search--dropdown
.select2-search__field{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.12) !important;
}

</style>

</head>
<body>

<div class="container-scroll">
    <div class="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title">Returns / Refunds</h1>

                <div class="header-actions">
                    <button type="button" class="btn-soft btn-add" data-bs-toggle="modal" data-bs-target="#returnModal">
                        <i class="bi bi-plus-lg"></i> New Return
                    </button>

                    <button type="button" class="btn-soft btn-export" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div class="stat-left">
                        <div class="title"><i class="bi bi-arrow-counterclockwise me-2"></i>Today Returns</div>
                        <div class="sub">Created today</div>
                    </div>
                    <div class="count"><?= (int)$todayReturns ?></div>
                </div>

                <div class="stat-card card-green">
                    <div class="stat-left">
                        <div class="title"><i class="bi bi-box-seam me-2"></i>Monthly Items</div>
                        <div class="sub">Returned this month</div>
                    </div>
                    <div class="count"><?= number_format((float)$monthlyItems, 0) ?></div>
                </div>

                <div class="stat-card card-cyan">
                    <div class="stat-left">
                        <div class="title"><i class="bi bi-cash-stack me-2"></i>Total Refund</div>
                        <div class="sub">All refund amount</div>
                    </div>
                    <div class="count"><?= format_money($totalRefundPaid) ?></div>
                </div>

                <div class="stat-card card-yellow">
                    <div class="stat-left">
                        <div class="title"><i class="bi bi-exclamation-triangle me-2"></i>Eligible / Placeholder</div>
                        <div class="sub">Future rule based stat</div>
                    </div>
                    <div class="count"><?= (int)$lowStockItems ?></div>
                </div>
            </div>

            <form method="GET" class="filter-bar">
                <select name="branch_id" class="filter-control">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $branchFilter === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="date" name="date" class="filter-control" value="<?= e($dateFilter) ?>">

                <select name="reason" class="filter-control">
                    <option value="">All Reasons</option>
                    <?php foreach ($reasons as $reason): ?>
                        <option value="<?= e($reason) ?>" <?= $reasonFilter === $reason ? 'selected' : '' ?>>
                            <?= e($reason) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="filter-control">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= e($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by invoice, reason, status..." value="<?= e($search) ?>">
                </div>

                <button type="submit" class="filter-btn">
                    <i class="bi bi-funnel"></i> Filter
                </button>

                <a href="returns.php" class="filter-btn reset">
                    <i class="bi bi-arrow-clockwise"></i> Reset
                </a>
            </form>

            <div class="table-card">
                <div class="table-responsive">
                    <table class="returns-table">
                        <thead>
    <tr>
        <th><input type="checkbox" id="checkAllReturns"></th>
        <th>ID</th>
        <th>Invoice Number</th>
        <th>Branch</th>
        <th>Customer Name</th>
        <th>Item Returns</th>
        <th>Qty</th>
        <th>Reason</th>
        <th>Status</th>
        <th>Date</th>
        <th>Refund</th>
    </tr>
</thead>
                       <tbody>
<?php if (empty($returns)): ?>
    <tr>
        <td colspan="11" class="text-center py-4">No returns found</td>
    </tr>
<?php else: ?>
    <?php foreach ($returns as $r): ?>
        <?php
            $rowKey = (int)$r['id'];
            $branchName = get_branch_name($branches, (int)($r['branch_id'] ?? 0));
            $customerId = (int)($r['customer_id'] ?? 0);
            $customer = $customersMap[$customerId] ?? ['name' => '-', 'phone' => '-'];
            $returnItems = get_return_item_product_list($conn, $client_id, (int)$r['id']);
            $status = trim((string)($r['status'] ?? 'Pending'));
            $invoiceNo = $invoiceMap[(int)($r['sale_id'] ?? 0)] ?? ('#' . (int)($r['sale_id'] ?? 0));

          $dotClass = 'dot-yellow';

if (strcasecmp($status, 'Refunded') === 0) {
    $dotClass = 'dot-green';
}

if (strcasecmp($status, 'Exchange') === 0) {
    $dotClass = 'dot-blue';
}

if (strcasecmp($status, 'Pending') === 0) {
    $dotClass = 'dot-yellow';
}
        ?>
        <tr>
            <td>
                <input 
                    type="checkbox" 
                    class="return-row-check"
                    value="<?= $rowKey ?>"
                    data-id="<?= $rowKey ?>"
                    data-invoice="<?= e($invoiceNo) ?>"
                >
            </td>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e($invoiceNo) ?></td>
            <td><?= e($branchName) ?></td>
            <td>
                <div><strong><?= e($customer['name'] ?? '-') ?></strong></div>
                <div class="muted small"><?= e($customer['phone'] ?? '-') ?></div>
            </td>
            <td><?= e($returnItems['items'] ?? '-') ?></td>
            <td><?= number_format((float)($returnItems['total_qty'] ?? 0), 0) ?></td>
            <td><?= e($r['reason'] ?? '-') ?></td>
            <td>
                <span class="status-badge">
                    <span class="status-dot <?= $dotClass ?>"></span>
                    <?= e($status) ?>
                </span>
            </td>
            <td><?= format_datetime($r['created_at'] ?? '') ?></td>
            <td><strong><?= format_money($r['total_refund'] ?? 0) ?></strong></td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <div>Total Records: <strong><?= count($returns) ?></strong></div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Return Modal -->
<!-- Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered return-small-modal">
        <div class="modal-content return-modal-content">
            <div class="modal-header return-modal-header">
                <h5 class="modal-title fw-bold">Create Return / Refund</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form id="returnForm">
                <input type="hidden" name="action" value="save_return">

                <div class="modal-body return-modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="return-label">Return Date</label>
                            <input type="date" class="form-control" name="return_date" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-5">
                            <label class="return-label">Invoice</label>
                            <select class="form-select invoice-select" name="sale_id" id="sale_id" required>
                                <option value="">Select Invoice</option>
                                <?php foreach ($sales as $sale): ?>
                                    <option value="<?= (int)$sale['id'] ?>"><?= e($sale['invoice_no']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" class="btn return-find-btn w-100 fw-bold" id="findInvoiceBtn">
                                Find Invoice
                            </button>
                        </div>
                    </div>

                    <div class="return-customer-box mb-3" id="customerInfoBox">
                        <div class="fw-bold mb-1">Customer</div>
                        <div class="muted">Select an invoice to load customer details.</div>
                    </div>

                    <div class="mb-3">
                        <div class="return-items-head">
                            <label class="return-label m-0">Return Items</label>
                            <button type="button" class="return-add-row-btn" id="addItemBtn">
                                <i class="bi bi-plus-lg"></i> Add Row
                            </button>
                        </div>

                        <div id="returnItemsWrap"></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="return-label">Reason</label>
                            <select class="form-select" name="reason" required>
                                <option value="">Select reason</option>
                                <option value="Damaged">Damaged</option>
                                <option value="Wrong Item">Wrong Item</option>
                                <option value="Customer Changed Mind">Customer Changed Mind</option>
                                <option value="Expired">Expired</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="return-label">Refund Type</label>
                            <select class="form-select" name="refund_type">
                                <option value="Refund Money">Refund Money</option>
                                <option value="Exchange">Exchange</option>
                                <option value="Store Credit">Store Credit</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="return-label">Total Refund</label>
                            <div class="return-total-box" id="refundTotalDisplay">PKR 0</div>
                        </div>

                        <div class="col-12">
                            <label class="return-label">Notes</label>
                            <textarea class="form-control" name="refund_notes" rows="3"></textarea>
                        </div>

                        <div class="col-12">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="adjustment" id="adjustmentCheck">
                                <label class="form-check-label" for="adjustmentCheck">Stock Adjustment</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="generate_grn" id="grnCheck">
                                <label class="form-check-label" for="grnCheck">Generate GRN</label>
                            </div>
                        </div>
                    </div>

                    <div id="returnAlert" class="mt-3"></div>
                </div>

                <div class="modal-footer return-modal-footer">
                    <button type="button" class="return-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="return-save-btn">Process Return</button>
                </div>
            </form>
        </div>
    </div>
</div>
<template id="itemRowTemplate">
    <div class="return-item-card">
        <div class="return-item-grid">
            <div>
                <label class="small muted">Barcode</label>
                <input type="text" class="form-control form-control-sm item-barcode" name="items[__INDEX__][barcode]" readonly>
            </div>

            <div>
                <label class="small muted">Product</label>
                <input type="hidden" class="item-id" name="items[__INDEX__][item_id]">
                <input type="text" class="form-control form-control-sm item-name" name="items[__INDEX__][product_name]" readonly>
            </div>

            <div>
                <label class="small muted">Qty</label>
                <input type="number" min="1" step="1" class="form-control form-control-sm item-qty" name="items[__INDEX__][qty]" value="1">
            </div>

            <div>
                <label class="small muted">Unit Price</label>
                <input type="number" min="0" step="0.01" class="form-control form-control-sm item-unit-price" name="items[__INDEX__][unit_price]" value="0">
            </div>

            <div>
                <label class="small muted">Refund Amount</label>
                <input type="number" min="0" step="0.01" class="form-control form-control-sm item-refund-amount" name="items[__INDEX__][refund_amount]" value="0">
            </div>

            <div>
                <button type="button" class="return-remove-btn remove-item-row">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </div>
</template>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
let itemRowIndex = 0;

function money(val) {
    return 'PKR ' + Number(val || 0).toLocaleString();
}

function recalcRefundTotal() {
    let total = 0;
    document.querySelectorAll('.item-refund-amount').forEach(input => {
        total += parseFloat(input.value || 0);
    });
    document.getElementById('refundTotalDisplay').textContent = money(total);
}

function bindRowEvents(row) {
    const qty = row.querySelector('.item-qty');
    const unitPrice = row.querySelector('.item-unit-price');
    const refundAmount = row.querySelector('.item-refund-amount');
    const removeBtn = row.querySelector('.remove-item-row');

    function autoCalc() {
        const q = parseFloat(qty.value || 0);
        const p = parseFloat(unitPrice.value || 0);
        refundAmount.value = (q * p).toFixed(2);
        recalcRefundTotal();
    }

    qty.addEventListener('input', autoCalc);
    unitPrice.addEventListener('input', autoCalc);
    refundAmount.addEventListener('input', recalcRefundTotal);

    removeBtn.addEventListener('click', function() {
        row.remove();
        recalcRefundTotal();
    });
}

function addItemRow(data = {}) {
    const tpl = document.getElementById('itemRowTemplate').innerHTML.replaceAll('__INDEX__', itemRowIndex++);
    const wrap = document.createElement('div');
    wrap.innerHTML = tpl;
    const row = wrap.firstElementChild;

    row.querySelector('.item-id').value = data.item_id || '';
    row.querySelector('.item-barcode').value = data.barcode || '';
    row.querySelector('.item-name').value = data.product_name || '';
    row.querySelector('.item-qty').value = data.qty || 1;
    row.querySelector('.item-unit-price').value = data.unit_price || 0;
    row.querySelector('.item-refund-amount').value = data.refund_amount || 0;

    bindRowEvents(row);
    document.getElementById('returnItemsWrap').appendChild(row);
    recalcRefundTotal();
}

function showAlert(message, type = 'danger') {
    document.getElementById('returnAlert').innerHTML =
        `<div class="alert alert-${type} mb-0">${message}</div>`;
}

function clearAlert() {
    document.getElementById('returnAlert').innerHTML = '';
}

document.addEventListener('DOMContentLoaded', function() {
    $('.invoice-select').select2({
        dropdownParent: $('#returnModal'),
        width: '100%'
    });

    document.getElementById('addItemBtn').addEventListener('click', function() {
        addItemRow();
    });

    document.getElementById('findInvoiceBtn').addEventListener('click', function() {
        clearAlert();
        const saleId = document.getElementById('sale_id').value;
        if (!saleId) {
            showAlert('Please select invoice first.');
            return;
        }

        fetch(`returns.php?action=sale_lookup&sale_id=${encodeURIComponent(saleId)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.ok) {
                    showAlert(data.message || 'Invoice not found.');
                    return;
                }

                const customer = data.customer || {};
                document.getElementById('customerInfoBox').innerHTML = `
                    <div class="fw-bold mb-1">Customer</div>
                    <div><strong>${customer.name || '-'}</strong></div>
                    <div class="muted">${customer.phone || '-'}</div>
                `;

                document.getElementById('returnItemsWrap').innerHTML = '';
                itemRowIndex = 0;

                if (Array.isArray(data.items) && data.items.length) {
                    data.items.forEach(item => addItemRow(item));
                } else {
                    addItemRow();
                }
            })
            .catch(() => showAlert('Failed to load invoice details.'));
    });

    document.getElementById('returnForm').addEventListener('submit', function(e) {
        e.preventDefault();
        clearAlert();

        const form = e.target;
        const formData = new FormData(form);

        fetch('returns.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (!data.ok) {
                showAlert(data.message || 'Save failed.');
                return;
            }
            showAlert(data.message || 'Return saved successfully.', 'success');
            setTimeout(() => {
                window.location.href = 'returns.php';
            }, 900);
        })
        .catch(() => showAlert('Something went wrong while saving.'));
    });
});
</script>
<script>
const checkAllReturns = document.getElementById('checkAllReturns');

checkAllReturns?.addEventListener('change', function () {
    document.querySelectorAll('.return-row-check').forEach(cb => {
        cb.checked = this.checked;
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;

    const savedState = localStorage.getItem('dashboard_sidebar_state');
    if (savedState === 'collapsed') {
        body.classList.add('dashboard-sidebar-collapsed');
    }

    const toggleSelectors = [
        '#sidebarToggle',
        '#toggleSidebar',
        '.sidebar-toggle',
        '.sidebar-toggler',
        '.topbar-menu-toggle',
        '.hamburger-btn',
        '[data-sidebar-toggle]',
        '.topbar .bi-list',
        '.topbar .fa-bars'
    ];

    let toggleBtn = null;

    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();

            body.classList.toggle('dashboard-sidebar-collapsed');

            if (body.classList.contains('dashboard-sidebar-collapsed')) {
                localStorage.setItem('dashboard_sidebar_state', 'collapsed');
            } else {
                localStorage.setItem('dashboard_sidebar_state', 'open');
            }
        });
    }
});
</script>

</body>
</html>