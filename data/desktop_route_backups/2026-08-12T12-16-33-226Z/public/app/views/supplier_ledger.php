<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
if ($client_id <= 0) {
    die('Invalid client session');
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function hasTable(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $tableEsc = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
    $exists = $res && $res->num_rows > 0;
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    $cache[$table] = $exists;
    return $exists;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (!hasTable($conn, $table)) {
        $cache[$key] = false;
        return false;
    }

    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    $exists = $res && $res->num_rows > 0;
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    $cache[$key] = $exists;
    return $exists;
}

function firstExistingColumn(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (hasColumn($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function supplierName(array $row): string
{
    $company = trim((string)($row['company_name'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    return $company !== '' ? $company : ($name !== '' ? $name : 'Unknown Supplier');
}

function ledgerMatchesSearch(array $entry, string $search): bool
{
    if ($search === '') {
        return true;
    }

    $haystack = implode(' ', [
        $entry['entry_type'] ?? '',
        $entry['supplier_name'] ?? '',
        $entry['reference'] ?? '',
        $entry['description'] ?? '',
        $entry['status'] ?? '',
    ]);

    return stripos($haystack, $search) !== false;
}

function bindAndExecute(mysqli_stmt $stmt, string $types = '', array $params = []): void
{
    if ($types !== '' && !empty($params)) {
        $refs = [$types];
        foreach ($params as $k => $v) {
            $refs[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
}

$selectedSupplierId = (int)($_GET['supplier_id'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

/*
|--------------------------------------------------------------------------
| Fetch suppliers
|--------------------------------------------------------------------------
*/
$supplierSelect = [
    'id',
    hasColumn($conn, 'suppliers', 'company_name') ? 'company_name' : "'' AS company_name",
    hasColumn($conn, 'suppliers', 'name') ? 'name' : "'' AS name",
    hasColumn($conn, 'suppliers', 'phone') ? 'phone' : "'' AS phone",
    hasColumn($conn, 'suppliers', 'email') ? 'email' : "'' AS email",
    hasColumn($conn, 'suppliers', 'full_address') ? 'full_address' : "'' AS full_address",
    hasColumn($conn, 'suppliers', 'address') ? 'address' : "'' AS address",
    hasColumn($conn, 'suppliers', 'opening_balance') ? 'opening_balance' : '0 AS opening_balance',
];

$suppliers = [];
$stmt = $conn->prepare("\n    SELECT " . implode(', ', $supplierSelect) . "\n    FROM suppliers\n    WHERE client_id = ?\n    ORDER BY company_name ASC, name ASC\n");
bindAndExecute($stmt, 'i', [$client_id]);
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $suppliers[] = $row;
}
$stmt->close();

$currentSupplier = null;
foreach ($suppliers as $s) {
    if ((int)$s['id'] === $selectedSupplierId) {
        $currentSupplier = $s;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Build supplier financial ledger
| Ledger rule:
| Opening balance + purchases = Debit
| Supplier payments + purchase returns = Credit
| Product assignment does not create financial ledger rows.
|--------------------------------------------------------------------------
*/
$ledgerAll = [];

/* Opening balance */
foreach ($suppliers as $supplier) {
    $supplierId = (int)$supplier['id'];
    if ($selectedSupplierId > 0 && $supplierId !== $selectedSupplierId) {
        continue;
    }

    $openingBalance = (float)($supplier['opening_balance'] ?? 0);
    if (abs($openingBalance) <= 0.00001) {
        continue;
    }

    $entry = [
        'entry_type' => 'Opening Balance',
        'date' => $dateFrom !== '' ? $dateFrom : date('Y-m-d', strtotime('1970-01-01')),
        'display_date' => 'Opening',
        'supplier_id' => $supplierId,
        'supplier_name' => supplierName($supplier),
        'reference' => 'OPEN-' . str_pad((string)$supplierId, 4, '0', STR_PAD_LEFT),
        'description' => 'Supplier opening balance',
        'debit' => $openingBalance > 0 ? $openingBalance : 0,
        'credit' => $openingBalance < 0 ? abs($openingBalance) : 0,
        'status' => 'Opening',
        'source_id' => $supplierId,
        'row_type' => 'opening',
        'attachment' => '',
        'due_date' => '-',
    ];

    if (ledgerMatchesSearch($entry, $search)) {
        $ledgerAll[] = $entry;
    }
}

/* Purchase Orders => Debit */
if (hasTable($conn, 'purchase_orders')) {
    $poAmountCol = firstExistingColumn($conn, 'purchase_orders', ['total_amount', 'grand_total', 'net_total', 'total', 'amount']);
    $poDateCol = firstExistingColumn($conn, 'purchase_orders', ['created_at', 'purchase_date', 'order_date', 'date']);

    if ($poAmountCol && $poDateCol && hasColumn($conn, 'purchase_orders', 'supplier_id') && hasColumn($conn, 'purchase_orders', 'client_id')) {
        $sql = "\n            SELECT\n                po.id,\n                po.supplier_id,\n                po.`{$poDateCol}` AS entry_date,\n                po.`{$poAmountCol}` AS total_amount,\n                COALESCE(NULLIF(s.company_name, ''), s.name, 'Unknown Supplier') AS supplier_name\n            FROM purchase_orders po\n            LEFT JOIN suppliers s ON s.id = po.supplier_id AND s.client_id = po.client_id\n            WHERE po.client_id = ?\n        ";
        $params = [$client_id];
        $types = 'i';

        if ($selectedSupplierId > 0) {
            $sql .= " AND po.supplier_id = ?";
            $params[] = $selectedSupplierId;
            $types .= 'i';
        }
        if ($dateFrom !== '') {
            $sql .= " AND DATE(po.`{$poDateCol}`) >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }
        if ($dateTo !== '') {
            $sql .= " AND DATE(po.`{$poDateCol}`) <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }

        $stmt = $conn->prepare($sql);
        bindAndExecute($stmt, $types, $params);
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $entry = [
                'entry_type' => 'Purchase Order',
                'date' => $row['entry_date'],
                'display_date' => '',
                'supplier_id' => (int)$row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'reference' => 'PO-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT),
                'description' => 'Purchase order from ' . $row['supplier_name'],
                'debit' => (float)$row['total_amount'],
                'credit' => 0,
                'status' => ((float)$row['total_amount'] > 0 ? 'Unpaid' : 'Paid'),
                'source_id' => (int)$row['id'],
                'row_type' => 'purchase',
                'attachment' => '',
                'due_date' => date('d/m/Y', strtotime($row['entry_date'] . ' + 30 days')),
            ];

            if (ledgerMatchesSearch($entry, $search)) {
                $ledgerAll[] = $entry;
            }
        }
        $stmt->close();
    }
}

/* Supplier Payments => Credit */
if (hasTable($conn, 'supplier_payments')) {
    $spAmountCol = firstExistingColumn($conn, 'supplier_payments', ['amount', 'paid_amount', 'payment_amount']);
    $spDateCol = firstExistingColumn($conn, 'supplier_payments', ['payment_date', 'created_at', 'date']);

    if ($spAmountCol && $spDateCol && hasColumn($conn, 'supplier_payments', 'supplier_id') && hasColumn($conn, 'supplier_payments', 'client_id')) {
        $methodCol = hasColumn($conn, 'supplier_payments', 'payment_method') ? 'payment_method' : "'' AS payment_method";
        $notesCol = hasColumn($conn, 'supplier_payments', 'notes') ? 'notes' : "'' AS notes";
        $attachmentCol = hasColumn($conn, 'supplier_payments', 'attachment') ? 'attachment' : "'' AS attachment";

        $sql = "\n            SELECT\n                sp.id,\n                sp.supplier_id,\n                sp.`{$spDateCol}` AS entry_date,\n                sp.`{$spAmountCol}` AS amount,\n                sp.{$methodCol},\n                sp.{$notesCol},\n                sp.{$attachmentCol},\n                COALESCE(NULLIF(s.company_name, ''), s.name, 'Unknown Supplier') AS supplier_name\n            FROM supplier_payments sp\n            LEFT JOIN suppliers s ON s.id = sp.supplier_id AND s.client_id = sp.client_id\n            WHERE sp.client_id = ?\n        ";
        $params = [$client_id];
        $types = 'i';

        if ($selectedSupplierId > 0) {
            $sql .= " AND sp.supplier_id = ?";
            $params[] = $selectedSupplierId;
            $types .= 'i';
        }
        if ($dateFrom !== '') {
            $sql .= " AND DATE(sp.`{$spDateCol}`) >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }
        if ($dateTo !== '') {
            $sql .= " AND DATE(sp.`{$spDateCol}`) <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }

        $stmt = $conn->prepare($sql);
        bindAndExecute($stmt, $types, $params);
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $description = trim((string)($row['payment_method'] ?? ''));
            if (!empty($row['notes'])) {
                $description .= ($description !== '' ? ' - ' : '') . $row['notes'];
            }
            if ($description === '') {
                $description = 'Supplier payment';
            }

            $entry = [
                'entry_type' => 'Supplier Payment',
                'date' => $row['entry_date'],
                'display_date' => '',
                'supplier_id' => (int)$row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'reference' => 'PAY-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT),
                'description' => $description,
                'debit' => 0,
                'credit' => (float)$row['amount'],
                'status' => 'Paid',
                'source_id' => (int)$row['id'],
                'row_type' => 'payment',
                'attachment' => $row['attachment'] ?? '',
                'due_date' => '-',
            ];

            if (ledgerMatchesSearch($entry, $search)) {
                $ledgerAll[] = $entry;
            }
        }
        $stmt->close();
    }
}

/* Purchase Returns / Debit Notes => Credit */
if (hasTable($conn, 'purchase_returns')) {
    $prAmountCol = firstExistingColumn($conn, 'purchase_returns', ['total_amount', 'grand_total', 'return_amount', 'amount', 'total']);
    $prDateCol = firstExistingColumn($conn, 'purchase_returns', ['return_date', 'created_at', 'date']);

    if ($prAmountCol && $prDateCol && hasColumn($conn, 'purchase_returns', 'supplier_id') && hasColumn($conn, 'purchase_returns', 'client_id')) {
        $sql = "\n            SELECT\n                pr.id,\n                pr.supplier_id,\n                pr.`{$prDateCol}` AS entry_date,\n                pr.`{$prAmountCol}` AS amount,\n                COALESCE(NULLIF(s.company_name, ''), s.name, 'Unknown Supplier') AS supplier_name\n            FROM purchase_returns pr\n            LEFT JOIN suppliers s ON s.id = pr.supplier_id AND s.client_id = pr.client_id\n            WHERE pr.client_id = ?\n        ";
        $params = [$client_id];
        $types = 'i';

        if ($selectedSupplierId > 0) {
            $sql .= " AND pr.supplier_id = ?";
            $params[] = $selectedSupplierId;
            $types .= 'i';
        }
        if ($dateFrom !== '') {
            $sql .= " AND DATE(pr.`{$prDateCol}`) >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }
        if ($dateTo !== '') {
            $sql .= " AND DATE(pr.`{$prDateCol}`) <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }

        $stmt = $conn->prepare($sql);
        bindAndExecute($stmt, $types, $params);
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $entry = [
                'entry_type' => 'Purchase Return',
                'date' => $row['entry_date'],
                'display_date' => '',
                'supplier_id' => (int)$row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'reference' => 'PR-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT),
                'description' => 'Purchase return / debit note',
                'debit' => 0,
                'credit' => (float)$row['amount'],
                'status' => 'Returned',
                'source_id' => (int)$row['id'],
                'row_type' => 'purchase_return',
                'attachment' => '',
                'due_date' => '-',
            ];

            if (ledgerMatchesSearch($entry, $search)) {
                $ledgerAll[] = $entry;
            }
        }
        $stmt->close();
    }
}

/* Manual supplier_ledger rows => Adjustment rows, if table exists */
if (hasTable($conn, 'supplier_ledger') && hasColumn($conn, 'supplier_ledger', 'supplier_id')) {
    $slDateCol = firstExistingColumn($conn, 'supplier_ledger', ['transaction_date', 'entry_date', 'date', 'created_at']);
    $slDebitCol = firstExistingColumn($conn, 'supplier_ledger', ['debit', 'debit_amount']);
    $slCreditCol = firstExistingColumn($conn, 'supplier_ledger', ['credit', 'credit_amount']);

    if ($slDateCol && ($slDebitCol || $slCreditCol)) {
        $slIdSelect = hasColumn($conn, 'supplier_ledger', 'id') ? 'sl.id' : '0 AS id';
        $slDescSelect = hasColumn($conn, 'supplier_ledger', 'description') ? 'sl.description' : "'' AS description";
        $slRefTypeSelect = hasColumn($conn, 'supplier_ledger', 'reference_type') ? 'sl.reference_type' : "'adjustment' AS reference_type";
        $slRefIdSelect = hasColumn($conn, 'supplier_ledger', 'reference_id') ? 'sl.reference_id' : '0 AS reference_id';
        $slDebitSelect = $slDebitCol ? "sl.`{$slDebitCol}` AS debit" : '0 AS debit';
        $slCreditSelect = $slCreditCol ? "sl.`{$slCreditCol}` AS credit" : '0 AS credit';

        $clientFilterSql = hasColumn($conn, 'supplier_ledger', 'client_id')
            ? 'sl.client_id = ?'
            : 's.client_id = ?';

        $sql = "\n            SELECT\n                {$slIdSelect},\n                sl.supplier_id,\n                sl.`{$slDateCol}` AS entry_date,\n                {$slDebitSelect},\n                {$slCreditSelect},\n                {$slDescSelect},\n                {$slRefTypeSelect},\n                {$slRefIdSelect},\n                COALESCE(NULLIF(s.company_name, ''), s.name, 'Unknown Supplier') AS supplier_name\n            FROM supplier_ledger sl\n            LEFT JOIN suppliers s ON s.id = sl.supplier_id\n            WHERE {$clientFilterSql}\n        ";
        $params = [$client_id];
        $types = 'i';

        if (hasColumn($conn, 'supplier_ledger', 'reference_type')) {
            $sql .= " AND (sl.reference_type IS NULL OR sl.reference_type NOT IN ('purchase_order','purchase','payment','supplier_payment'))";
        }
        if ($selectedSupplierId > 0) {
            $sql .= " AND sl.supplier_id = ?";
            $params[] = $selectedSupplierId;
            $types .= 'i';
        }
        if ($dateFrom !== '') {
            $sql .= " AND DATE(sl.`{$slDateCol}`) >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }
        if ($dateTo !== '') {
            $sql .= " AND DATE(sl.`{$slDateCol}`) <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }

        $stmt = $conn->prepare($sql);
        bindAndExecute($stmt, $types, $params);
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $debit = (float)($row['debit'] ?? 0);
            $credit = (float)($row['credit'] ?? 0);
            if (abs($debit) <= 0.00001 && abs($credit) <= 0.00001) {
                continue;
            }

            $entry = [
                'entry_type' => 'Adjustment',
                'date' => $row['entry_date'],
                'display_date' => '',
                'supplier_id' => (int)$row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'reference' => 'ADJ-' . str_pad((string)($row['id'] ?? 0), 5, '0', STR_PAD_LEFT),
                'description' => trim((string)($row['description'] ?? '')) !== '' ? $row['description'] : 'Manual supplier ledger adjustment',
                'debit' => $debit,
                'credit' => $credit,
                'status' => 'Adjustment',
                'source_id' => (int)($row['id'] ?? 0),
                'row_type' => 'adjustment',
                'attachment' => '',
                'due_date' => '-',
            ];

            if (ledgerMatchesSearch($entry, $search)) {
                $ledgerAll[] = $entry;
            }
        }
        $stmt->close();
    }
}

usort($ledgerAll, function ($a, $b) {
    $da = strtotime((string)($a['date'] ?? '1970-01-01')) ?: 0;
    $db = strtotime((string)($b['date'] ?? '1970-01-01')) ?: 0;
    if ($da === $db) {
        $typeOrder = ['opening' => 0, 'purchase' => 1, 'purchase_return' => 2, 'payment' => 3, 'adjustment' => 4];
        $ao = $typeOrder[$a['row_type'] ?? ''] ?? 9;
        $bo = $typeOrder[$b['row_type'] ?? ''] ?? 9;
        if ($ao === $bo) {
            return ((int)($a['source_id'] ?? 0)) <=> ((int)($b['source_id'] ?? 0));
        }
        return $ao <=> $bo;
    }
    return $da <=> $db;
});

$running = 0.00;
$totalPurchase = 0.00;
$totalPaid = 0.00;
$totalDebit = 0.00;
$totalCredit = 0.00;
$totalOpeningBalance = 0.00;
$purchaseReturnTotal = 0.00;
$adjustmentDebitTotal = 0.00;
$adjustmentCreditTotal = 0.00;

foreach ($ledgerAll as $k => $row) {
    $debit = (float)($row['debit'] ?? 0);
    $credit = (float)($row['credit'] ?? 0);

    $running += ($debit - $credit);
    $ledgerAll[$k]['running_balance'] = $running;

    $totalDebit += $debit;
    $totalCredit += $credit;

    if (($row['row_type'] ?? '') === 'purchase') {
        $totalPurchase += $debit;
    } elseif (($row['row_type'] ?? '') === 'payment') {
        $totalPaid += $credit;
    } elseif (($row['row_type'] ?? '') === 'opening') {
        $totalOpeningBalance += ($debit - $credit);
    } elseif (($row['row_type'] ?? '') === 'purchase_return') {
        $purchaseReturnTotal += $credit;
    } elseif (($row['row_type'] ?? '') === 'adjustment') {
        $adjustmentDebitTotal += $debit;
        $adjustmentCreditTotal += $credit;
    }
}

$ledger = $ledgerAll;
$outstandingBalance = $totalDebit - $totalCredit;
$overdueAmount = max(0, $outstandingBalance * 0.72);
$totalTransactions = count($ledger);
$avgPaymentCycle = 28;

function statusClass(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'paid') return 'status-paid';
    if ($status === 'part' || $status === 'partial') return 'status-part';
    if ($status === 'opening' || $status === 'adjustment' || $status === 'returned') return 'status-part';
    return 'status-unpaid';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supplier Ledger - ONE ERP</title>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#eef2f7;font-family:Inter,Arial,sans-serif;color:#1f2736}
  .container-scroll{
        display:flex;
        min-height:100vh;
           align-items:stretch;
    }

    .sidebar{
        width:250px;
        flex-shrink:0;
          display:flex;*/
  align-self:stretch;
  background:transparent;
    overflow:hidden;
    }

.sidebar > div{
    width:100%;
    min-height:100%;
    height:100%;
}

#dashboardSidebar .sidebar-wrap{
    min-height:100% !important;
    height:100% !important;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100% !important;
}
        .main-panel{flex:1;min-width:0;display:flex;flex-direction:column}
        .topbar{height:64px;background:#05070d;color:#fff;display:flex;align-items:center;padding:0 20px}
        .content-wrapper{padding:18px;flex:1;overflow:auto}
        .page-title{font-size:22px;font-weight:800;margin:0 0 16px;color:#243046}

        .toolbar,.card-box,.table-card,.chart-card,.supplier-strip{
            background:#fff;border:1px solid #e6ebf3;border-radius:16px;box-shadow:0 8px 22px rgba(18,38,63,.05)
        }
        .toolbar{padding:12px;display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 150px 120px;gap:10px;margin-bottom:16px}
        .toolbar-input,.toolbar-select,.toolbar-btn{
            height:44px;border:1px solid #dbe2ed;border-radius:10px;background:#fff;padding:0 14px;outline:none
        }
        .toolbar-btn{font-weight:700;display:flex;align-items:center;justify-content:center;text-decoration:none}
        .btn-green{background:linear-gradient(180deg,#53ae6e,#3e8f58);border:none;color:#fff}
        .btn-dark-soft{background:#f2f4fa;color:#44506a}
        .stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:16px}
        .stat-card{border-radius:14px;padding:18px;color:#fff;min-height:96px;position:relative;overflow:hidden}
        .stat-card::after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12)}
        .stat-green{background:linear-gradient(135deg,#4ea98d,#66c09f)}
        .stat-blue{background:linear-gradient(135deg,#5e84ff,#7fa9ff)}
        .stat-red{background:linear-gradient(135deg,#c7485e,#9a2940)}
        .stat-gold{background:linear-gradient(135deg,#cc8d1d,#a66f0d)}
        .stat-yellow{background:linear-gradient(135deg,#a88718,#8c6d10)}
        .stat-title{font-size:14px;font-weight:600}
        .stat-value{font-size:26px;font-weight:800;margin-top:10px}
        .charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px}
        .chart-card .body{padding:16px}
        .chart-title{font-size:16px;font-weight:800;color:#243046;margin-bottom:10px}
        .chart-box{height:180px;border-radius:12px;background:linear-gradient(to top,#f7f9fc,#ffffff);border:1px solid #eef2f7;position:relative;overflow:hidden}
        .bars{position:absolute;inset:24px 20px 28px 20px;display:flex;gap:18px;align-items:flex-end}
        .bar{width:28px;border-radius:8px 8px 0 0;background:linear-gradient(180deg,#79d36f,#426f37)}
        .line{position:absolute;inset:24px 20px 28px 20px}
        .line svg{width:100%;height:100%}
        .donut-wrap{display:flex;align-items:center;justify-content:center;height:180px}
        .donut{width:120px;height:120px;border-radius:50%;background:conic-gradient(#d8aa2b 0 35%, #d35c5c 35% 55%, #f0b049 55% 100%);position:relative}
        .donut:after{content:'15%';position:absolute;inset:20px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#243046}

        .supplier-strip-top{display:flex;align-items:center;gap:14px;padding:16px;border-bottom:1px solid #edf1f6}
        .warehouse-thumb{width:78px;height:50px;border-radius:10px;background:linear-gradient(135deg,#cfd9e8,#8fa0b5)}
        .supplier-strip-name{font-size:20px;font-weight:800;color:#243046}
        .supplier-summary-row{display:grid;grid-template-columns:1.3fr .9fr .9fr .9fr 1fr auto;gap:12px;padding:14px 16px;align-items:center}
        .summary-pill{color:#52607a;font-weight:700;font-size:15px}
        .summary-pill b{color:#243046}
        .table-tools{display:flex;gap:8px;align-items:center}
        .icon-box{width:34px;height:34px;border-radius:8px;border:1px solid #e1e7f0;display:flex;align-items:center;justify-content:center;color:#44506a;background:#fff}

        .table-card{overflow:hidden}
        .table-wrap{overflow:auto}
        .erp-table{width:100%;min-width:1380px;border-collapse:separate;border-spacing:0}
        .erp-table thead th{background:#1a1f2e;color:#fff;padding:14px 12px;font-size:14px;font-weight:600;white-space:nowrap}
        .erp-table tbody td{padding:13px 12px;border-bottom:1px solid #edf1f6;color:#243046;white-space:nowrap;vertical-align:middle;background:#fff}
        .erp-table tbody tr:hover td{background:#fafcff}
        .check-cell input{width:18px;height:18px}
        .status-pill{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:800}
        .status-paid{background:#dcefdc;color:#2c6b38}
        .status-part{background:#fff1c4;color:#8a6a00}
        .status-unpaid{background:#ffe1e5;color:#a73348}
        
        .table-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 16px;border-top:1px solid #edf1f6}
        .bulk-actions,.footer-right{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .bulk-btn{border:none;border-radius:10px;padding:10px 14px;font-weight:700;color:#44506a;background:#f4f7fb}
        
        /* NEW ICON STYLE */
.ledger-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    flex-wrap:nowrap;
}

.ledger-action-btn{
    width:38px;
    height:38px;
    border:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
    cursor:pointer;
    text-decoration:none;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

.ledger-action-btn:hover{
    transform:translateY(-2px);
}

.ledger-action-btn i{
    font-size:15px;
    line-height:1;
}

.ledger-view{
    background:#eef2ff;
    color:#6366f1;
}

.ledger-view:hover{
    background:#6366f1;
    color:#fff;
}

.ledger-edit{
    background:#fff4d6;
    color:#e0a100;
}

.ledger-edit:hover{
    background:#e0a100;
    color:#fff;
}

.ledger-delete{
    background:#ffe3e3;
    color:#ef4444;
}

.ledger-delete:hover{
    background:#ef4444;
    color:#fff;
}

.ledger-disabled{
    opacity:.4;
    cursor:not-allowed;
    pointer-events:none;
}


       /* Add Payment Modal - project style */
.payment-modal-dialog{
    max-width: 860px;
}

.payment-modal-content{
    background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
    color:#fff;
    border:none;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.35);
}

.payment-modal-header{
    background:transparent;
    border-bottom:1px solid rgba(255,255,255,0.08);
    padding:18px 22px;
}

.payment-modal-header .modal-title{
    font-size:18px;
    font-weight:700;
    color:#fff;
    margin:0;
}

.payment-modal-body{
    padding:20px 22px 10px;
}

.payment-modal-footer{
    border-top:1px solid rgba(255,255,255,0.08);
    padding:16px 22px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:14px;
}

.payment-label{
    display:block;
    font-size:15px;
    margin-bottom:7px;
    color:#e4e8f8;
    font-weight:500;
}

.payment-label span{
    color:#f4b21c;
}

.payment-input,
.payment-textarea{
    width:100%;
    border:1px solid rgba(255,255,255,0.10);
    background:#e9eaf3;
    color:#222;
    border-radius:8px;
    height:44px;
    padding:0 14px;
    outline:none;
    font-size:14px;
    box-shadow:none;
}

.payment-textarea{
    min-height:90px;
    resize:none;
    padding:12px 14px;
}

.payment-input:focus,
.payment-textarea:focus{
    border-color:#f0ae19;
    box-shadow:0 0 0 2px rgba(240,174,25,0.20);
}

.payment-balance-box{
    display:flex;
    gap:16px;
    flex-wrap:wrap;
    margin:4px 0 14px;
}

.payment-balance-item{
    flex:1;
    min-width:220px;
    border:1px solid rgba(255,255,255,0.08);
    background:rgba(255,255,255,0.04);
    border-radius:12px;
    padding:14px;
}

.payment-balance-item small{
    display:block;
    color:#cfd5ea;
    font-size:12px;
    margin-bottom:6px;
}

.payment-balance-item strong{
    font-size:18px;
    font-weight:800;
}

.payment-balance-item .red{
    color:#ff9aa8;
}

.payment-balance-item .gold{
    color:#ffd56a;
}

.payment-settle-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    min-height:44px;
    border:1px solid rgba(255,255,255,0.08);
    background:rgba(255,255,255,0.03);
    border-radius:10px;
    padding:0 14px;
}

.payment-settle-row .form-check-input{
    width:18px;
    height:18px;
    accent-color:#f0ae19;
}

.payment-upload-box{
    border:1px dashed rgba(255,255,255,0.18);
    border-radius:12px;
    padding:18px;
    text-align:center;
    color:#dbe0f4;
    margin-top:6px;
    cursor:pointer;
    background:rgba(255,255,255,0.03);
    transition:.2s ease;
}

.payment-upload-box:hover{
    background:rgba(255,255,255,0.05);
}

.payment-upload-box i{
    font-size:24px;
}

.payment-doc-name{
    margin-top:8px;
    color:#d3d9ea;
    font-size:12px;
    display:none;
    word-break:break-word;
}

.payment-summary{
    margin-top:18px;
    padding-top:14px;
    border-top:1px solid rgba(255,255,255,0.08);
}

.payment-summary-line{
    display:flex;
    justify-content:space-between;
    gap:18px;
    align-items:center;
    margin-bottom:12px;
    color:#e4e8f8;
}

.payment-summary-line b{
    font-size:18px;
    color:#fff;
}

.payment-footer-note{
    color:#dbe0f4;
    font-size:14px;
    font-weight:500;
}

.payment-footer-btns{
    display:flex;
    align-items:center;
    gap:10px;
}

.payment-cancel-btn{
    min-width:130px;
    height:42px;
    border-radius:8px;
    border:none;
    background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
    color:#fff;
    font-weight:600;
}

.payment-save-btn{
    min-width:140px;
    height:42px;
    border-radius:8px;
    border:none;
    background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
    color:#fff;
    font-weight:700;
}

.payment-modal-content .btn-close-white{
    filter:invert(1);
}

@media(max-width:768px){
    .payment-modal-footer{
        justify-content:flex-end;
    }

    .payment-footer-note{
        width:100%;
    }

    .payment-footer-btns{
        width:100%;
        flex-direction:column;
    }

    .payment-cancel-btn,
    .payment-save-btn{
        width:100%;
    }
}
        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}.charts-grid{grid-template-columns:1fr}}
        @media(max-width:991px){.toolbar{grid-template-columns:1fr 1fr}.supplier-summary-row{grid-template-columns:1fr 1fr}}
        @media(max-width:768px){.stats-grid{grid-template-columns:1fr}.toolbar{grid-template-columns:1fr}.supplier-summary-row{grid-template-columns:1fr}}
    

/* =========================================================
   FINAL SUPPLIER LEDGER POPUP BLACK + GOLD + WHITE THEME
   Add/Edit Payment Modal + View Ledger Entry Modal
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

/* Modal sizes */
#paymentModal .modal-dialog{
    max-width:min(900px,94vw) !important;
}

#viewEntryModal .modal-dialog{
    max-width:min(780px,94vw) !important;
}

/* Main popup containers */
#paymentModal .modal-content,
#paymentModal .payment-modal-content,
#viewEntryModal .modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.62) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

/* Headers */
#paymentModal .modal-header,
#paymentModal .payment-modal-header,
#viewEntryModal .modal-header{
    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
    min-height:76px !important;
}

#paymentModal .modal-title,
#viewEntryModal .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#paymentModal .modal-title::before,
#viewEntryModal .modal-title::before{
    content:'' !important;
    width:13px !important;
    height:13px !important;
    border-radius:50% !important;
    background:#c9a84c !important;
    box-shadow:0 0 0 5px rgba(201,168,76,.15) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

/* Close button - proper white X */
#paymentModal .btn-close,
#viewEntryModal .btn-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#paymentModal .btn-close::before,
#paymentModal .btn-close::after,
#viewEntryModal .btn-close::before,
#viewEntryModal .btn-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:22px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#paymentModal .btn-close::before,
#viewEntryModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#paymentModal .btn-close::after,
#viewEntryModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#paymentModal .btn-close:hover,
#viewEntryModal .btn-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px) !important;
}

/* Body */
#paymentModal .modal-body,
#paymentModal .payment-modal-body,
#viewEntryModal .modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#paymentModal .payment-modal-body::-webkit-scrollbar,
#viewEntryModal .modal-body::-webkit-scrollbar{
    width:7px !important;
}

#paymentModal .payment-modal-body::-webkit-scrollbar-track,
#viewEntryModal .modal-body::-webkit-scrollbar-track{
    background:#171c28 !important;
    border-radius:999px !important;
}

#paymentModal .payment-modal-body::-webkit-scrollbar-thumb,
#viewEntryModal .modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c !important;
    border-radius:999px !important;
}

/* Footer */
#paymentModal .modal-footer,
#paymentModal .payment-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.28) !important;
    padding:18px 24px 22px !important;
}

/* Labels */
#paymentModal .payment-label,
#paymentModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#paymentModal .payment-label span{
    color:#f5c742 !important;
}

/* Inputs / selects / textarea */
#paymentModal .payment-input,
#paymentModal .payment-textarea,
#paymentModal input[type="text"],
#paymentModal input[type="number"],
#paymentModal input[type="date"],
#paymentModal select,
#paymentModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#paymentModal .payment-input,
#paymentModal input[type="text"],
#paymentModal input[type="number"],
#paymentModal input[type="date"],
#paymentModal select{
    min-height:50px !important;
    height:50px !important;
    padding:10px 16px !important;
}

#paymentModal .payment-textarea,
#paymentModal textarea{
    min-height:112px !important;
    height:auto !important;
    padding:14px 16px !important;
    resize:vertical !important;
}

#paymentModal input::placeholder,
#paymentModal textarea::placeholder{
    color:#8993a5 !important;
}

#paymentModal .payment-input:focus,
#paymentModal .payment-textarea:focus,
#paymentModal input:focus,
#paymentModal select:focus,
#paymentModal textarea:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Balance cards */
#paymentModal .payment-balance-box{
    gap:14px !important;
}

#paymentModal .payment-balance-item{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.38) !important;
    border-radius:14px !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#paymentModal .payment-balance-item small{
    color:#dbe0f4 !important;
    font-weight:800 !important;
}

#paymentModal .payment-balance-item strong.red{
    color:#ff9aa8 !important;
    font-weight:900 !important;
}

#paymentModal .payment-balance-item strong.gold{
    color:#f5c742 !important;
    font-weight:900 !important;
}

/* Quick settle row */
#paymentModal .payment-settle-row{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.28) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    min-height:50px !important;
    color:#ffffff !important;
    font-weight:800 !important;
}

#paymentModal .form-check-label{
    color:#ffffff !important;
    font-weight:800 !important;
}

#paymentModal .form-check-input{
    background-color:#ffffff !important;
    border-color:#d9dfec !important;
    box-shadow:none !important;
}

#paymentModal .form-check-input:checked{
    background-color:#d8a500 !important;
    border-color:#d8a500 !important;
}

/* Upload receipt */
#paymentModal .payment-upload-box{
    background:#151a26 !important;
    border:1.5px dashed rgba(201,168,76,.58) !important;
    border-radius:14px !important;
    color:#dbe0f4 !important;
    font-weight:800 !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#paymentModal .payment-upload-box:hover{
    background:#1b2130 !important;
    border-color:#f5c742 !important;
    color:#ffffff !important;
}

#paymentModal .payment-upload-box i{
    color:#f5c742 !important;
}

#paymentModal .payment-doc-name{
    color:#dbe0f4 !important;
    font-weight:800 !important;
}

/* Summary */
#paymentModal .payment-summary{
    border-top:1px solid rgba(201,168,76,.28) !important;
}

#paymentModal .payment-summary-line{
    color:#ffffff !important;
    font-weight:800 !important;
}

#paymentModal .payment-summary-line b{
    color:#f5c742 !important;
    font-weight:900 !important;
}

#paymentModal .payment-summary-line[style]{
    border-top:1px solid rgba(201,168,76,.28) !important;
}

/* Footer notes/buttons */
#paymentModal .payment-footer-note{
    color:#dbe0f4 !important;
    font-weight:800 !important;
}

#paymentModal .payment-cancel-btn{
    height:44px !important;
    min-width:130px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#paymentModal .payment-cancel-btn:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#paymentModal .payment-save-btn{
    height:44px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#paymentModal .payment-save-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px) !important;
}

/* View ledger entry modal content */
#viewEntryModal .modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
}

#viewEntryModal .modal-body table,
#viewEntryModal .modal-body .table{
    background:#ffffff !important;
    color:#111827 !important;
    border-radius:12px !important;
    overflow:hidden !important;
}

#viewEntryModal .modal-body th{
    background:#151a26 !important;
    color:#ffffff !important;
    border-color:rgba(201,168,76,.28) !important;
}

#viewEntryModal .modal-body td{
    color:#111827 !important;
    background:#ffffff !important;
}

#viewEntryModal .modal-body .card,
#viewEntryModal .modal-body .detail-item,
#viewEntryModal .modal-body .mb-2{
    background:#ffffff !important;
    color:#111827 !important;
    border:1px solid #e5e7eb !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 5px 14px rgba(0,0,0,.18) !important;
}

@media(max-width:768px){
    #paymentModal .modal-dialog,
    #viewEntryModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #paymentModal .modal-title,
    #viewEntryModal .modal-title{
        font-size:18px !important;
    }

    #paymentModal .btn-close,
    #viewEntryModal .btn-close{
        width:42px !important;
        height:42px !important;
    }

    #paymentModal .payment-cancel-btn,
    #paymentModal .payment-save-btn{
        width:100% !important;
    }

    #paymentModal .payment-modal-footer{
        align-items:stretch !important;
    }

    #paymentModal .payment-footer-btns{
        width:100% !important;
        flex-direction:column !important;
    }

    #paymentModal .payment-footer-note{
        width:100% !important;
    }
}

/* =====================================================
   PURCHASE_ORDER.PHP - FINAL DARK PURPLE GLASS THEME
   Paste at VERY END before
===================================================== */

html,
body{
    min-height:100% !important;
    margin:0 !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
    color:#f8fafc !important;
    font-family:Inter, "Segoe UI", Arial, sans-serif !important;
}

/* Sidebar old typo fix */
.sidebar{
    width:250px;
    flex-shrink:0;
    display:flex !important;
    align-self:stretch;
    background:transparent;
    overflow:hidden;
}

/* Main page background */
.main-panel{
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

.content-wrapper{
    min-height:calc(100vh - 64px) !important;
    overflow:visible !important;
    padding:24px 28px !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
}

/* Topbar */
.topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Page title */
.page-title{
    color:#f8fafc !important;
    font-size:30px !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

/* New Purchase Order button */
.btn-green{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    border:none !important;
    border-radius:14px !important;
    min-height:50px !important;
    font-weight:900 !important;
    box-shadow:
        0 16px 34px rgba(34,197,94,.22),
        0 0 28px rgba(34,197,94,.12) !important;
    transition:.22s ease !important;
}

.btn-green:hover{
    transform:translateY(-2px) !important;
}

/* Stats cards */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    position:relative !important;
    overflow:hidden !important;
    transition:.25s ease !important;
}

.stat-card::before{
    content:"" !important;
    position:absolute !important;
    inset:0 !important;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%) !important;
    z-index:0 !important;
}

.stat-card::after{
    content:"" !important;
    position:absolute !important;
    right:14px !important;
    top:14px !important;
    width:76px !important;
    height:76px !important;
    border-radius:20px !important;
    background:rgba(255,255,255,.10) !important;
    z-index:0 !important;
}

.stat-card > *{
    position:relative !important;
    z-index:1 !important;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.stat-gold{background:linear-gradient(135deg,#8b6f47,#f59e0b) !important;}

.stat-title{
    color:rgba(255,255,255,.94) !important;
    font-weight:900 !important;
}

.stat-value{
    color:#fff !important;
    font-weight:900 !important;
}

/* Filter card */
.filter-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.54)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    padding:14px 16px !important;
    margin-bottom:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.filter-card form{
    display:grid !important;
    grid-template-columns:1fr !important;
    gap:10px !important;
}

.filter-row{
    display:grid !important;
    grid-template-columns:
        minmax(150px,1fr)
        minmax(160px,1fr)
        minmax(150px,1fr)
        minmax(150px,1fr)
        110px
        110px !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:0 !important;
}

.search-row{
    display:grid !important;
    grid-template-columns:1fr !important;
    gap:10px !important;
}

/* Inputs */
.erp-select,
.erp-input{
    height:46px !important;
    width:100% !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    padding:0 14px !important;
    outline:none !important;
    font-weight:800 !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

.erp-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.erp-input::placeholder{
    color:#94a3b8 !important;
}

.erp-select:focus,
.erp-input:focus{
    background:rgba(17,24,39,.92) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

.erp-select:disabled{
    background:rgba(15,23,42,.50) !important;
    color:#94a3b8 !important;
    opacity:1 !important;
}

/* Filter/reset buttons */
.filter-btn,
.reset-btn{
    height:46px !important;
    width:100% !important;
    border-radius:13px !important;
    border:none !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
}

.filter-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

.reset-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.filter-btn:hover,
.reset-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    box-shadow:
        0 18px 40px rgba(0,0,0,.36),
        0 0 34px rgba(124,58,237,.18) !important;
}

.reset-btn:hover{
    background:rgba(124,58,237,.20) !important;
}

/* Table card */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
}

.erp-table{
    background:transparent !important;
}

.erp-table thead th{
    background:rgba(7,11,20,.76) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.erp-table tbody td{
    background:rgba(15,23,42,.30) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    font-weight:700 !important;
}

.erp-table tbody tr:hover td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color:#fff !important;
}

.erp-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Supplier cell */
.supplier-icon{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 10px 20px rgba(124,58,237,.20) !important;
}

.status-pill{
    border-radius:999px !important;
    font-weight:900 !important;
}

.status-approval,
.status-pending{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}

.status-completed{
    background:rgba(34,197,94,.16) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.25) !important;
}

.work-pill{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

/* Action icons */
.icon-btn{
    width:40px !important;
    height:40px !important;
    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.icon-edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
}

.icon-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
}

.icon-view{
    background:rgba(124,58,237,.18) !important;
    color:#a78bfa !important;
}

/* Pending approval card */
.approval-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.54)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
}

.approval-head{
    background:rgba(7,11,20,.36) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

.approval-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.approval-body{
    background:rgba(15,23,42,.16) !important;
}

.approval-legend{
    color:#cbd5e1 !important;
    font-weight:800 !important;
}

.empty-state{
    color:#94a3b8 !important;
    background:transparent !important;
    font-weight:800 !important;
}

/* Main popup */
#poModal .modal-dialog,
#poModal .modal-dialog.modal-xl-custom{
    max-width:820px !important;
    width:820px !important;
    margin:18px auto !important;
}

#poModal .modal-content,
#poModal .po-modal-content{
    max-height:82vh !important;
    border-radius:18px !important;
    overflow:hidden !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.26), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.90), rgba(17,24,39,.76)) !important;
    border:1px solid rgba(139,92,246,.34) !important;
    color:#f8fafc !important;
    box-shadow:
        0 24px 70px rgba(0,0,0,.58),
        0 0 45px rgba(124,58,237,.16) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
}

#poModal .po-modal-header{
    min-height:54px !important;
    padding:12px 18px !important;
    background:rgba(7,11,20,.30) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

#poModal .po-modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
}

#poModal .po-close{
    width:36px !important;
    height:36px !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.16) !important;
    color:#e5e7eb !important;
    font-size:22px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}

#poModal .po-modal-body{
    padding:12px 18px !important;
    max-height:62vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* Popup tabs */
#poModal .tab-bar{
    margin-bottom:12px !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

#poModal .tab-btn{
    padding:9px 14px !important;
    font-size:14px !important;
    color:#cbd5e1 !important;
}

#poModal .tab-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

/* Popup fields */
#poModal .dark-label{
    color:#e5e7eb !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#poModal .dark-input,
#poModal .dark-select,
#poModal input[type="text"],
#poModal input[type="number"],
#poModal input[type="date"],
#poModal select{
    height:38px !important;
    min-height:38px !important;
    padding:6px 12px !important;
    font-size:13px !important;
    border-radius:9px !important;
    background:rgba(255,255,255,.92) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    font-weight:800 !important;
}

#poModal .dark-textarea,
#poModal textarea{
    min-height:58px !important;
    height:58px !important;
    padding:8px 12px !important;
    font-size:13px !important;
    border-radius:9px !important;
    background:rgba(255,255,255,.92) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    font-weight:800 !important;
}

#poModal .row{
    --bs-gutter-x:14px !important;
    --bs-gutter-y:8px !important;
}

#poModal .mb-3{
    margin-bottom:10px !important;
}

/* Quantity */
#poModal .qty-wrap{
    height:42px !important;
    grid-template-columns:48px 1fr 48px !important;
    border-radius:9px !important;
}

#poModal .qty-input{
    height:42px !important;
    min-height:42px !important;
    font-size:18px !important;
}

#poModal .qty-btn{
    font-size:22px !important;
}

#poModal .qty-meter{
    height:7px !important;
    margin-top:7px !important;
}

#poModal .qty-stats{
    font-size:12px !important;
    margin-top:5px !important;
}

/* Enable approval switch */
#poModal .switch-row{
    display:inline-flex !important;
    align-items:center !important;
    gap:12px !important;
    padding:10px 14px !important;
    margin-top:12px !important;
    border-radius:14px !important;
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.44)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#f8fafc !important;
    font-size:14px !important;
    font-weight:900 !important;
}

#poModal .switch-row .form-check{
    margin:0 !important;
    padding-left:0 !important;
    min-height:auto !important;
    display:flex !important;
    align-items:center !important;
}

#poModal .switch-row .form-check-input,
#poModal input#enable_approval.form-check-input{
    width:48px !important;
    height:26px !important;
    min-width:48px !important;
    min-height:26px !important;
    margin:0 !important;
    padding:0 !important;
    cursor:pointer !important;
    border-radius:999px !important;
    background-color:rgba(51,65,85,.95) !important;
    border:1px solid rgba(148,163,184,.32) !important;
    box-shadow:inset 0 2px 5px rgba(0,0,0,.32) !important;
    -webkit-appearance:none !important;
    appearance:none !important;
    background-image:none !important;
    position:relative !important;
}

#poModal .switch-row .form-check-input::before,
#poModal input#enable_approval.form-check-input::before{
    content:"" !important;
    position:absolute !important;
    top:3px !important;
    left:3px !important;
    width:18px !important;
    height:18px !important;
    border-radius:50% !important;
    background:#fff !important;
    box-shadow:0 3px 8px rgba(0,0,0,.30) !important;
    transition:.22s ease !important;
}

#poModal .switch-row .form-check-input:checked,
#poModal input#enable_approval.form-check-input:checked{
    background:linear-gradient(135deg,#6d4cff,#22c55e) !important;
    border-color:rgba(34,197,94,.55) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.18),
        0 0 22px rgba(34,197,94,.22) !important;
}

#poModal .switch-row .form-check-input:checked::before,
#poModal input#enable_approval.form-check-input:checked::before{
    left:25px !important;
}

#poModal .switch-row span{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* Items tab */
#poModal .item-toolbar{
    grid-template-columns:1fr 140px !important;
    gap:10px !important;
    margin-bottom:10px !important;
}

#poModal .item-add-btn{
    height:38px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:9px !important;
}

#poModal .items-box{
    border-color:rgba(148,163,184,.16) !important;
}

#poModal .items-head{
    background:rgba(7,11,20,.48) !important;
}

#poModal .items-row{
    background:rgba(15,23,42,.28) !important;
}

#poModal .item-cell-input{
    height:34px !important;
    border-radius:8px !important;
}

/* Remove huge margin */
#poModal .total-line[style*="margin-top:180px"]{
    margin-top:18px !important;
}

/* Popup footer */
#poModal .po-modal-footer{
    padding:10px 18px !important;
    min-height:52px !important;
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

#poModal .cancel-btn,
#poModal .save-btn{
    height:38px !important;
    min-width:105px !important;
    font-size:13px !important;
    border-radius:9px !important;
}

#poModal .cancel-btn{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
}

#poModal .save-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

/* View details modal */
#viewPOModal .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.90), rgba(17,24,39,.74)) !important;
    border:1px solid rgba(139,92,246,.32) !important;
    color:#f8fafc !important;
    box-shadow:0 24px 70px rgba(0,0,0,.58) !important;
}

#viewPOModal .modal-header{
    background:rgba(7,11,20,.30) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

#viewPOModal .modal-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

#viewPOModal .modal-body{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
}

/* Responsive */
@media(max-width:1200px){
    .stats-grid{
        grid-template-columns:repeat(2,1fr) !important;
    }

    .filter-row{
        grid-template-columns:repeat(3,1fr) !important;
    }
}

@media(max-width:900px){
    #poModal .modal-dialog,
    #poModal .modal-dialog.modal-xl-custom{
        width:calc(100% - 24px) !important;
        max-width:calc(100% - 24px) !important;
        margin:12px auto !important;
    }

    #poModal .po-modal-body{
        max-height:66vh !important;
    }
}

@media(max-width:768px){
    .stats-grid,
    .filter-row{
        grid-template-columns:1fr !important;
    }

    .filter-btn,
    .reset-btn,
    .erp-select,
    .erp-input{
        width:100% !important;
    }

    #poModal .item-toolbar{
        grid-template-columns:1fr !important;
    }

    #poModal .po-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #poModal .cancel-btn,
    #poModal .save-btn{
        width:100% !important;
    }
}

/* =====================================================
   SUPPLIER_LEDGER.PHP - FINAL DARK PURPLE GLASS FIX
   Paste at VERY END before 
===================================================== */

/* Page white background remove */
html,
body{
    min-height:100% !important;
    margin:0 !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
    color:#f8fafc !important;
    font-family:Inter, "Segoe UI", Arial, sans-serif !important;
}

/* Sidebar typo fix */
.sidebar{
    width:250px;
    flex-shrink:0;
    display:flex !important;
    align-self:stretch;
    background:transparent;
    overflow:hidden;
}

.main-panel{
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

.topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

.content-wrapper{
    min-height:calc(100vh - 64px) !important;
    overflow:visible !important;
    padding:24px 28px !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
}

.page-title{
    color:#f8fafc !important;
    font-size:30px !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

/* =====================================================
   FILTER TOOLBAR - ONE LINE + GLASS
===================================================== */

.toolbar{
    display:grid !important;
    grid-template-columns:
        minmax(220px, 1.5fr)
        minmax(180px, 1fr)
        minmax(165px, .9fr)
        minmax(165px, .9fr)
        130px
        120px !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:18px !important;
    padding:14px !important;

    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.54)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

/* Inputs/select dark glass */
.toolbar-input,
.toolbar-select{
    width:100% !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    padding:0 14px !important;
    outline:none !important;
    font-weight:800 !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

.toolbar-input::placeholder{
    color:#94a3b8 !important;
}

.toolbar-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.toolbar-input:focus,
.toolbar-select:focus{
    background:rgba(17,24,39,.92) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

/* Toolbar buttons */
.toolbar-btn,
.toolbar .btn-green,
.toolbar .btn-dark-soft{
    height:46px !important;
    border-radius:13px !important;
    border:none !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
    text-decoration:none !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
}

.toolbar .btn-green{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
}

.toolbar .btn-dark-soft{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.toolbar-btn:hover,
.toolbar .btn-green:hover,
.toolbar .btn-dark-soft:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    box-shadow:
        0 18px 40px rgba(0,0,0,.36),
        0 0 34px rgba(124,58,237,.18) !important;
}

.toolbar .btn-dark-soft:hover{
    background:rgba(124,58,237,.20) !important;
}

/* =====================================================
   TOP ACTION BUTTONS
===================================================== */

.btn-green{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    border:none !important;
    border-radius:14px !important;
    min-height:50px !important;
    font-weight:900 !important;
    box-shadow:
        0 16px 34px rgba(34,197,94,.22),
        0 0 28px rgba(34,197,94,.12) !important;
    transition:.22s ease !important;
}

.btn-green:hover{
    transform:translateY(-2px) !important;
}

.btn-dark-soft{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:14px !important;
    min-height:48px !important;
    font-weight:900 !important;
    transition:.22s ease !important;
}

.btn-dark-soft:hover{
    background:rgba(124,58,237,.20) !important;
    color:#fff !important;
    transform:translateY(-2px) !important;
}

/* =====================================================
   STATS CARDS
===================================================== */

.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(5,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    position:relative !important;
    overflow:hidden !important;
    transition:.25s ease !important;
}

.stat-card::after{
    width:86px !important;
    height:86px !important;
    right:14px !important;
    top:14px !important;
    border-radius:22px !important;
    background:rgba(255,255,255,.10) !important;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-red{background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.stat-gold{background:linear-gradient(135deg,#8b6f47,#f59e0b) !important;}
.stat-yellow{background:linear-gradient(135deg,#a16207,#eab308) !important;}

.stat-title{
    color:rgba(255,255,255,.94) !important;
    font-weight:900 !important;
}

.stat-value{
    color:#fff !important;
    font-weight:900 !important;
}

/* =====================================================
   CHART CARDS WHITE REMOVE
===================================================== */

.charts-grid{
    display:grid !important;
    grid-template-columns:2fr 1fr !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.chart-card,
.card-box,
.supplier-strip,
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    color:#f8fafc !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.chart-card:hover,
.card-box:hover,
.supplier-strip:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.chart-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.chart-box{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.12), transparent 34%),
        linear-gradient(135deg, rgba(7,11,20,.46), rgba(15,23,42,.34)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
}

.donut:after{
    background:rgba(15,23,42,.96) !important;
    color:#f8fafc !important;
}

/* =====================================================
   SUPPLIER STRIP WHITE REMOVE
===================================================== */

.supplier-strip-top{
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    background:rgba(7,11,20,.26) !important;
}

.warehouse-thumb{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.28), transparent 40%),
        linear-gradient(135deg, rgba(203,213,225,.22), rgba(15,23,42,.60)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.supplier-strip-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.supplier-summary-row{
    background:rgba(15,23,42,.22) !important;
}

.summary-pill{
    color:#cbd5e1 !important;
    font-weight:800 !important;
}

.summary-pill b{
    color:#f8fafc !important;
}

.icon-box{
    background:rgba(15,23,42,.72) !important;
    color:#cbd5e1 !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.icon-box:hover{
    background:rgba(124,58,237,.20) !important;
    color:#fff !important;
}

/* =====================================================
   LEDGER TABLE DARK GLASS + WHITE HOVER FIX
===================================================== */

.erp-table{
    width:100% !important;
    background:transparent !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
}

.erp-table thead th{
    background:rgba(7,11,20,.76) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.erp-table tbody td{
    background:rgba(15,23,42,.30) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    font-weight:700 !important;
}

.erp-table tbody tr:hover td,
.erp-table tbody td:hover{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color:#fff !important;
    box-shadow:none !important;
}

.erp-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* Empty row */
.erp-table .text-center,
.empty-state{
    color:#94a3b8 !important;
    background:transparent !important;
    font-weight:900 !important;
}

/* Footer */
.table-footer{
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#94a3b8 !important;
}

.bulk-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    font-weight:900 !important;
}

.bulk-btn:hover{
    background:rgba(124,58,237,.20) !important;
    color:#fff !important;
}

/* Ledger action icons */
.ledger-view{
    background:rgba(124,58,237,.18) !important;
    color:#a78bfa !important;
    border:1px solid rgba(124,58,237,.28) !important;
}

.ledger-edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.ledger-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

/* =====================================================
   ADD PAYMENT POPUP - DARK PURPLE GLASS
===================================================== */

.payment-modal-dialog{
    max-width:760px !important;
    width:760px !important;
    margin:18px auto !important;
}

.payment-modal-content{
    max-height:84vh !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.26), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.90), rgba(17,24,39,.76)) !important;
    border:1px solid rgba(139,92,246,.34) !important;
    color:#f8fafc !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 24px 70px rgba(0,0,0,.58),
        0 0 45px rgba(124,58,237,.16) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
}

.payment-modal-header{
    min-height:54px !important;
    padding:12px 18px !important;
    background:rgba(7,11,20,.30) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

.payment-modal-header .modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
}

.payment-modal-body{
    padding:14px 18px !important;
    max-height:62vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.payment-modal-footer{
    padding:10px 18px !important;
    min-height:52px !important;
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

.payment-label{
    color:#e5e7eb !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

.payment-label span{
    color:#a78bfa !important;
}

.payment-input,
.payment-textarea{
    width:100% !important;
    background:rgba(255,255,255,.92) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:800 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}

.payment-input{
    height:38px !important;
    min-height:38px !important;
    padding:6px 12px !important;
}

.payment-textarea{
    min-height:62px !important;
    height:62px !important;
    padding:8px 12px !important;
    resize:vertical !important;
}

.payment-input:focus,
.payment-textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.20),
        0 8px 18px rgba(0,0,0,.18) !important;
    outline:none !important;
}

/* Balance boxes */
.payment-balance-box{
    gap:10px !important;
    margin:4px 0 12px !important;
}

.payment-balance-item{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.44)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:14px !important;
    padding:12px !important;
}

.payment-balance-item small{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.payment-balance-item strong{
    color:#f8fafc !important;
}

.payment-balance-item .red{
    color:#f87171 !important;
}

.payment-balance-item .gold{
    color:#fbbf24 !important;
}

/* Settle row */
.payment-settle-row{
    background:rgba(15,23,42,.50) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
}

.payment-settle-row .form-check-input{
    accent-color:#7c3aed !important;
}

/* Upload */
.payment-upload-box{
    background:rgba(15,23,42,.44) !important;
    border:1px dashed rgba(139,92,246,.38) !important;
    border-radius:14px !important;
    color:#cbd5e1 !important;
}

.payment-upload-box:hover{
    background:rgba(124,58,237,.14) !important;
    color:#fff !important;
}

.payment-doc-name{
    color:#94a3b8 !important;
}

/* Summary */
.payment-summary{
    border-top:1px solid rgba(148,163,184,.14) !important;
}

.payment-summary-line{
    color:#e5e7eb !important;
    font-weight:800 !important;
}

.payment-summary-line b{
    color:#f8fafc !important;
}

/* Footer buttons */
.payment-cancel-btn,
.payment-save-btn{
    height:38px !important;
    min-width:110px !important;
    border:none !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:900 !important;
    transition:.22s ease !important;
}

.payment-cancel-btn{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
}

.payment-save-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 12px 24px rgba(109,76,255,.24) !important;
}

.payment-cancel-btn:hover,
.payment-save-btn:hover{
    transform:translateY(-2px) !important;
}

/* Modal close button */
.payment-modal-header .btn-close,
.modal .btn-close{
    width:34px !important;
    height:34px !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    background-image:none !important;
    position:relative !important;
    padding:0 !important;
}

.payment-modal-header .btn-close::before,
.payment-modal-header .btn-close::after,
.modal .btn-close::before,
.modal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:16px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

.payment-modal-header .btn-close::before,
.modal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

.payment-modal-header .btn-close::after,
.modal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

.payment-modal-header .btn-close:hover,
.modal .btn-close:hover{
    background:rgba(239,68,68,.24) !important;
}

/* =====================================================
   RESPONSIVE
===================================================== */

@media(max-width:1200px){
    .toolbar{
        grid-template-columns:repeat(3,1fr) !important;
    }

    .stats-grid{
        grid-template-columns:repeat(2,1fr) !important;
    }

    .charts-grid{
        grid-template-columns:1fr !important;
    }
}

@media(max-width:900px){
    .payment-modal-dialog{
        width:calc(100% - 24px) !important;
        max-width:calc(100% - 24px) !important;
        margin:12px auto !important;
    }

    .payment-modal-body{
        max-height:66vh !important;
    }
}

@media(max-width:768px){
    .toolbar,
    .stats-grid{
        grid-template-columns:1fr !important;
    }

    .supplier-summary-row{
        grid-template-columns:1fr !important;
    }

    .payment-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .payment-cancel-btn,
    .payment-save-btn{
        width:100% !important;
    }
}


/* =====================================================
   SUPPLIER LEDGER - ADD PAYMENT POPUP DARK PURPLE GLASS
   Paste at VERY END before
===================================================== */

/* Modal backdrop */
body.modal-open .modal-backdrop.show,
.modal-backdrop.show{
    opacity:.62 !important;
    background:#000 !important;
    backdrop-filter:blur(6px) !important;
}

/* Add Payment modal width */
#addPaymentModal .modal-dialog,
#paymentModal .modal-dialog,
#supplierPaymentModal .modal-dialog,
#supplierLedgerPaymentModal .modal-dialog,
.modal-dialog.payment-modal-dialog{
    max-width:760px !important;
    width:760px !important;
    margin:18px auto !important;
}

/* Main modal card */
#addPaymentModal .modal-content,
#paymentModal .modal-content,
#supplierPaymentModal .modal-content,
#supplierLedgerPaymentModal .modal-content,
.payment-modal-content{
    max-height:84vh !important;
    overflow:hidden !important;
    border-radius:20px !important;
    color:#f8fafc !important;

    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(34,197,94,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.94), rgba(17,24,39,.78)) !important;

    border:1px solid rgba(139,92,246,.38) !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.62),
        0 0 52px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;

    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
}

/* Header */
#addPaymentModal .modal-header,
#paymentModal .modal-header,
#supplierPaymentModal .modal-header,
#supplierLedgerPaymentModal .modal-header,
.payment-modal-header{
    min-height:58px !important;
    padding:14px 20px !important;
    background:
        linear-gradient(135deg, rgba(7,11,20,.48), rgba(30,20,58,.34)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

/* Title */
#addPaymentModal .modal-title,
#paymentModal .modal-title,
#supplierPaymentModal .modal-title,
#supplierLedgerPaymentModal .modal-title,
.payment-modal-header .modal-title{
    color:#f8fafc !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

/* Yellow dot ko purple-green glow bana do */
#addPaymentModal .modal-title::before,
#paymentModal .modal-title::before,
#supplierPaymentModal .modal-title::before,
#supplierLedgerPaymentModal .modal-title::before{
    content:"" !important;
    width:12px !important;
    height:12px !important;
    border-radius:50% !important;
    background:linear-gradient(135deg,#7c3aed,#22c55e) !important;
    box-shadow:0 0 18px rgba(124,58,237,.55) !important;
}

/* Body */
#addPaymentModal .modal-body,
#paymentModal .modal-body,
#supplierPaymentModal .modal-body,
#supplierLedgerPaymentModal .modal-body,
.payment-modal-body{
    padding:16px 20px !important;
    max-height:62vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    background:rgba(15,23,42,.20) !important;
}

/* Footer */
#addPaymentModal .modal-footer,
#paymentModal .modal-footer,
#supplierPaymentModal .modal-footer,
#supplierLedgerPaymentModal .modal-footer,
.payment-modal-footer{
    padding:12px 20px !important;
    min-height:58px !important;
    background:
        linear-gradient(135deg, rgba(7,11,20,.48), rgba(15,23,42,.36)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

/* Labels */
#addPaymentModal label,
#paymentModal label,
#supplierPaymentModal label,
#supplierLedgerPaymentModal label,
.payment-label{
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:6px !important;
}

/* Required star */
#addPaymentModal label span,
#paymentModal label span,
#supplierPaymentModal label span,
#supplierLedgerPaymentModal label span{
    color:#a78bfa !important;
}

/* Inputs, select, textarea */
/* =====================================================
   ADD PAYMENT BUTTON PURPLE
===================================================== */

#addPaymentModal .modal-footer .btn-primary,
#paymentModal .modal-footer .btn-primary,
#supplierPaymentModal .modal-footer .btn-primary,
#supplierLedgerPaymentModal .modal-footer .btn-primary,
#addPaymentModal .btn-warning,
#paymentModal .btn-warning,
#supplierPaymentModal .btn-warning,
#supplierLedgerPaymentModal .btn-warning,
#addPaymentModal .btn-submit,
#paymentModal .btn-submit,
#supplierPaymentModal .btn-submit,
#supplierLedgerPaymentModal .btn-submit {
    height: 42px !important;
    min-width: 145px !important;
    border: none !important;
    border-radius: 11px !important;
    background: linear-gradient(135deg,#7c3aed,#6d4cff) !important;
    color: #fff !important;
    font-size: 14px !important;
    font-weight: 900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.32),
        0 0 24px rgba(124,58,237,.18) !important;
}

#addPaymentModal .modal-footer .btn-primary:hover,
#paymentModal .modal-footer .btn-primary:hover,
#supplierPaymentModal .modal-footer .btn-primary:hover,
#supplierLedgerPaymentModal .modal-footer .btn-primary:hover,
#addPaymentModal .btn-warning:hover,
#paymentModal .btn-warning:hover,
#supplierPaymentModal .btn-warning:hover,
#supplierLedgerPaymentModal .btn-warning:hover,
#addPaymentModal .btn-submit:hover,
#paymentModal .btn-submit:hover,
#supplierPaymentModal .btn-submit:hover,
#supplierLedgerPaymentModal .btn-submit:hover {
    background: linear-gradient(135deg,#8b5cf6,#7c3aed) !important;
    color: #fff !important;
    transform: translateY(-2px) !important;
}

/* Cancel button */
#addPaymentModal .btn-secondary,
#paymentModal .btn-secondary,
#supplierPaymentModal .btn-secondary,
#supplierLedgerPaymentModal .btn-secondary {
    background: rgba(148,163,184,.18) !important;
    color: #e5e7eb !important;
    border: 1px solid rgba(148,163,184,.14) !important;
    border-radius: 11px !important;
}
#paymentModal input,
#paymentModal select,
#paymentModal textarea,
#supplierPaymentModal input,
#supplierPaymentModal select,
#supplierPaymentModal textarea,
#supplierLedgerPaymentModal input,
#supplierLedgerPaymentModal select,
#supplierLedgerPaymentModal textarea,
.payment-input,
.payment-textarea{
    width:100% !important;
    background:rgba(255,255,255,.94) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    border-radius:11px !important;
    font-size:14px !important;
    font-weight:800 !important;
    box-shadow:0 10px 24px rgba(0,0,0,.14) !important;
    outline:none !important;
}

#addPaymentModal input,
#addPaymentModal select,
#paymentModal input,
#paymentModal select,
#supplierPaymentModal input,
#supplierPaymentModal select,
#supplierLedgerPaymentModal input,
#supplierLedgerPaymentModal select,
.payment-input{
    height:42px !important;
    min-height:42px !important;
    padding:7px 13px !important;
}

#addPaymentModal textarea,
#paymentModal textarea,
#supplierPaymentModal textarea,
#supplierLedgerPaymentModal textarea,
.payment-textarea{
    min-height:68px !important;
    height:68px !important;
    padding:10px 13px !important;
    resize:vertical !important;
}

#addPaymentModal input:focus,
#addPaymentModal select:focus,
#addPaymentModal textarea:focus,
#paymentModal input:focus,
#paymentModal select:focus,
#paymentModal textarea:focus,
#supplierPaymentModal input:focus,
#supplierPaymentModal select:focus,
#supplierPaymentModal textarea:focus,
#supplierLedgerPaymentModal input:focus,
#supplierLedgerPaymentModal select:focus,
#supplierLedgerPaymentModal textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.22),
        0 10px 24px rgba(0,0,0,.18) !important;
}

/* Balance boxes */
.payment-balance-box,
.balance-box,
.balance-grid{
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:12px !important;
    margin:6px 0 14px !important;
}

.payment-balance-item,
.balance-item,
.summary-box{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:15px !important;
    padding:13px 15px !important;
    box-shadow:
        0 14px 30px rgba(0,0,0,.22),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

.payment-balance-item small,
.balance-item small,
.summary-box small{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.payment-balance-item strong,
.balance-item strong,
.summary-box strong{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.payment-balance-item .red,
.balance-item .red{
    color:#f87171 !important;
}

.payment-balance-item .gold,
.balance-item .gold{
    color:#fbbf24 !important;
}

/* Quick settle box */
.payment-settle-row,
.settle-row,
.quick-settle{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.16), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:15px !important;
    color:#f8fafc !important;
    font-weight:900 !important;
    padding:13px 15px !important;
}

/* Checkbox / toggle inside quick settle */
.payment-settle-row input[type="checkbox"],
.settle-row input[type="checkbox"],
.quick-settle input[type="checkbox"]{
    width:42px !important;
    height:22px !important;
    border-radius:999px !important;
    accent-color:#7c3aed !important;
}

/* Upload area */
.payment-upload-box,
.upload-box,
.receipt-upload{
    background:
        linear-gradient(135deg, rgba(15,23,42,.60), rgba(17,24,39,.42)) !important;
    border:1px dashed rgba(139,92,246,.42) !important;
    border-radius:15px !important;
    color:#cbd5e1 !important;
    padding:14px !important;
    transition:.22s ease !important;
}

.payment-upload-box:hover,
.upload-box:hover,
.receipt-upload:hover{
    background:rgba(124,58,237,.14) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.62) !important;
}

/* Summary lines */
.payment-summary,
.payment-summary-box{
    border-top:1px solid rgba(148,163,184,.16) !important;
    margin-top:12px !important;
    padding-top:12px !important;
}

.payment-summary-line{
    color:#e5e7eb !important;
    font-weight:800 !important;
}

.payment-summary-line b{
    color:#f8fafc !important;
}

/* Cancel / Save buttons */
#addPaymentModal .modal-footer .btn-secondary,
#paymentModal .modal-footer .btn-secondary,
#supplierPaymentModal .modal-footer .btn-secondary,
#supplierLedgerPaymentModal .modal-footer .btn-secondary,
.payment-cancel-btn{
    height:42px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:11px !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(0,0,0,.20) !important;
}

#addPaymentModal .modal-footer .btn-primary,
#paymentModal .modal-footer .btn-primary,
#supplierPaymentModal .modal-footer .btn-primary,
#supplierLedgerPaymentModal .modal-footer .btn-primary,
.payment-save-btn{
    height:42px !important;
    min-width:140px !important;
    border:none !important;
    border-radius:11px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.28),
        0 0 26px rgba(124,58,237,.16) !important;
}

#addPaymentModal .modal-footer .btn-secondary:hover,
#addPaymentModal .modal-footer .btn-primary:hover,
#paymentModal .modal-footer .btn-secondary:hover,
#paymentModal .modal-footer .btn-primary:hover,
#supplierPaymentModal .modal-footer .btn-secondary:hover,
#supplierPaymentModal .modal-footer .btn-primary:hover,
#supplierLedgerPaymentModal .modal-footer .btn-secondary:hover,
#supplierLedgerPaymentModal .modal-footer .btn-primary:hover,
.payment-cancel-btn:hover,
.payment-save-btn:hover{
    transform:translateY(-2px) !important;
}

/* Close button */
#addPaymentModal .btn-close,
#paymentModal .btn-close,
#supplierPaymentModal .btn-close,
#supplierLedgerPaymentModal .btn-close,
.payment-modal-header .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    background-image:none !important;
    position:relative !important;
    padding:0 !important;
}

#addPaymentModal .btn-close::before,
#addPaymentModal .btn-close::after,
#paymentModal .btn-close::before,
#paymentModal .btn-close::after,
#supplierPaymentModal .btn-close::before,
#supplierPaymentModal .btn-close::after,
#supplierLedgerPaymentModal .btn-close::before,
#supplierLedgerPaymentModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:17px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#addPaymentModal .btn-close::before,
#paymentModal .btn-close::before,
#supplierPaymentModal .btn-close::before,
#supplierLedgerPaymentModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#addPaymentModal .btn-close::after,
#paymentModal .btn-close::after,
#supplierPaymentModal .btn-close::after,
#supplierLedgerPaymentModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#addPaymentModal .btn-close:hover,
#paymentModal .btn-close:hover,
#supplierPaymentModal .btn-close:hover,
#supplierLedgerPaymentModal .btn-close:hover{
    background:rgba(239,68,68,.24) !important;
}

/* Agar modal ke andar yellow/gold border old CSS se aa raha ho */
#addPaymentModal *,
#paymentModal *,
#supplierPaymentModal *,
#supplierLedgerPaymentModal *{
    border-color:inherit;
}

@media(max-width:900px){
    #addPaymentModal .modal-dialog,
    #paymentModal .modal-dialog,
    #supplierPaymentModal .modal-dialog,
    #supplierLedgerPaymentModal .modal-dialog,
    .modal-dialog.payment-modal-dialog{
        width:calc(100% - 24px) !important;
        max-width:calc(100% - 24px) !important;
        margin:12px auto !important;
    }

    #addPaymentModal .modal-body,
    #paymentModal .modal-body,
    #supplierPaymentModal .modal-body,
    #supplierLedgerPaymentModal .modal-body,
    .payment-modal-body{
        max-height:66vh !important;
    }
}

@media(max-width:768px){
    .payment-balance-box,
    .balance-box,
    .balance-grid{
        grid-template-columns:1fr !important;
    }

    #addPaymentModal .modal-footer,
    #paymentModal .modal-footer,
    #supplierPaymentModal .modal-footer,
    #supplierLedgerPaymentModal .modal-footer,
    .payment-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #addPaymentModal .modal-footer .btn-secondary,
    #addPaymentModal .modal-footer .btn-primary,
    #paymentModal .modal-footer .btn-secondary,
    #paymentModal .modal-footer .btn-primary,
    #supplierPaymentModal .modal-footer .btn-secondary,
    #supplierPaymentModal .modal-footer .btn-primary,
    #supplierLedgerPaymentModal .modal-footer .btn-secondary,
    #supplierLedgerPaymentModal .modal-footer .btn-primary,
    .payment-cancel-btn,
    .payment-save-btn{
        width:100% !important;
    }
}

/* =====================================================
   SUPPLIER LEDGER POPUP FINAL FIX
   Toggle + File Input + Add Payment Purple Button
   Paste at VERY END before 
===================================================== */

/* ---------- File input white box hide fix ---------- */
#paymentModal input[type="file"],
#addPaymentModal input[type="file"],
#supplierPaymentModal input[type="file"],
#supplierLedgerPaymentModal input[type="file"],
#attachment {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    width: 0 !important;
    height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 0 !important;
    position: absolute !important;
    pointer-events: none !important;
}

/* Upload box proper glass look */
#paymentModal .payment-upload-box,
#addPaymentModal .payment-upload-box,
#supplierPaymentModal .payment-upload-box,
#supplierLedgerPaymentModal .payment-upload-box {
    width: 100% !important;
    min-height: 72px !important;
    border-radius: 14px !important;
    border: 1px dashed rgba(139,92,246,.55) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.65), rgba(17,24,39,.45)) !important;
    color: #e5e7eb !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 10px !important;
    font-weight: 900 !important;
    cursor: pointer !important;
    box-shadow:
        0 14px 30px rgba(0,0,0,.25),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

#paymentModal .payment-upload-box:hover,
#addPaymentModal .payment-upload-box:hover,
#supplierPaymentModal .payment-upload-box:hover,
#supplierLedgerPaymentModal .payment-upload-box:hover {
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.28), transparent 36%),
        linear-gradient(135deg, rgba(30,20,58,.62), rgba(15,23,42,.52)) !important;
    border-color: rgba(167,139,250,.75) !important;
    color: #fff !important;
}

/* ---------- Quick Settle box ---------- */
#paymentModal .payment-settle-row,
#addPaymentModal .payment-settle-row,
#supplierPaymentModal .payment-settle-row,
#supplierLedgerPaymentModal .payment-settle-row {
    min-height: 72px !important;
    padding: 14px 16px !important;
    border-radius: 14px !important;
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.52)) !important;
    border: 1px solid rgba(148,163,184,.18) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 14px !important;
    box-shadow:
        0 12px 28px rgba(0,0,0,.22),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

/* Quick settle label */
#paymentModal .payment-settle-row label,
#addPaymentModal .payment-settle-row label,
#supplierPaymentModal .payment-settle-row label,
#supplierLedgerPaymentModal .payment-settle-row label {
    color: #f8fafc !important;
    font-weight: 900 !important;
    font-size: 15px !important;
    line-height: 1.35 !important;
    margin: 0 !important;
}

/* ---------- Settle Full toggle exact fix ---------- */
#paymentModal #settle_full,
#addPaymentModal #settle_full,
#supplierPaymentModal #settle_full,
#supplierLedgerPaymentModal #settle_full,
input#settle_full {
    appearance: none !important;
    -webkit-appearance: none !important;

    width: 48px !important;
    min-width: 48px !important;
    max-width: 48px !important;

    height: 26px !important;
    min-height: 26px !important;
    max-height: 26px !important;

    padding: 0 !important;
    margin: 0 !important;
    border-radius: 999px !important;

    background: rgba(71,85,105,.72) !important;
    border: 1px solid rgba(148,163,184,.35) !important;
    box-shadow:
        inset 0 2px 5px rgba(0,0,0,.32),
        0 0 0 0 rgba(124,58,237,0) !important;

    cursor: pointer !important;
    position: relative !important;
    display: inline-block !important;
    vertical-align: middle !important;
    transition: all .22s ease !important;
}

/* Toggle knob */
#paymentModal #settle_full::before,
#addPaymentModal #settle_full::before,
#supplierPaymentModal #settle_full::before,
#supplierLedgerPaymentModal #settle_full::before,
input#settle_full::before {
    content: "" !important;
    position: absolute !important;
    top: 3px !important;
    left: 3px !important;
    width: 18px !important;
    height: 18px !important;
    border-radius: 50% !important;
    background: #ffffff !important;
    box-shadow: 0 3px 8px rgba(0,0,0,.32) !important;
    transition: all .22s ease !important;
}

/* Checked toggle purple */
#paymentModal #settle_full:checked,
#addPaymentModal #settle_full:checked,
#supplierPaymentModal #settle_full:checked,
#supplierLedgerPaymentModal #settle_full:checked,
input#settle_full:checked {
    background: linear-gradient(135deg,#7c3aed,#6d4cff) !important;
    border-color: rgba(167,139,250,.75) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.20),
        0 0 24px rgba(124,58,237,.22),
        inset 0 1px 0 rgba(255,255,255,.10) !important;
}

#paymentModal #settle_full:checked::before,
#addPaymentModal #settle_full:checked::before,
#supplierPaymentModal #settle_full:checked::before,
#supplierLedgerPaymentModal #settle_full:checked::before,
input#settle_full:checked::before {
    left: 25px !important;
}

/* ---------- Send receipt checkbox exact fix ---------- */
#paymentModal #send_receipt,
#addPaymentModal #send_receipt,
#supplierPaymentModal #send_receipt,
#supplierLedgerPaymentModal #send_receipt,
input#send_receipt {
    appearance: none !important;
    -webkit-appearance: none !important;

    width: 20px !important;
    min-width: 20px !important;
    max-width: 20px !important;

    height: 20px !important;
    min-height: 20px !important;
    max-height: 20px !important;

    padding: 0 !important;
    margin: 0 10px 0 0 !important;

    border-radius: 6px !important;
    border: 1.5px solid rgba(139,92,246,.70) !important;
    background: rgba(15,23,42,.78) !important;
    box-shadow: none !important;

    cursor: pointer !important;
    position: relative !important;
    display: inline-block !important;
    vertical-align: middle !important;
}

#paymentModal #send_receipt:checked,
#addPaymentModal #send_receipt:checked,
#supplierPaymentModal #send_receipt:checked,
#supplierLedgerPaymentModal #send_receipt:checked,
input#send_receipt:checked {
    background: linear-gradient(135deg,#7c3aed,#6d4cff) !important;
    border-color: #a78bfa !important;
    box-shadow: 0 0 0 3px rgba(124,58,237,.18) !important;
}

#paymentModal #send_receipt:checked::after,
#addPaymentModal #send_receipt:checked::after,
#supplierPaymentModal #send_receipt:checked::after,
#supplierLedgerPaymentModal #send_receipt:checked::after,
input#send_receipt:checked::after {
    content: "" !important;
    position: absolute !important;
    left: 6px !important;
    top: 2px !important;
    width: 5px !important;
    height: 10px !important;
    border: solid #fff !important;
    border-width: 0 2px 2px 0 !important;
    transform: rotate(45deg) !important;
}

/* Receipt row alignment */
#paymentModal .form-check,
#addPaymentModal .form-check,
#supplierPaymentModal .form-check,
#supplierLedgerPaymentModal .form-check {
    display: flex !important;
    align-items: center !important;
    gap: 0 !important;
    padding-left: 0 !important;
    margin: 10px 0 0 !important;
}

#paymentModal .form-check-label,
#addPaymentModal .form-check-label,
#supplierPaymentModal .form-check-label,
#supplierLedgerPaymentModal .form-check-label {
    color: #e5e7eb !important;
    font-weight: 800 !important;
    margin: 0 !important;
    cursor: pointer !important;
}

/* ---------- Add Payment button purple force fix ---------- */
#paymentModal #savePaymentBtn,
#addPaymentModal #savePaymentBtn,
#supplierPaymentModal #savePaymentBtn,
#supplierLedgerPaymentModal #savePaymentBtn,
button#savePaymentBtn,
.payment-save-btn,
#paymentModal .payment-save-btn,
#addPaymentModal .payment-save-btn,
#supplierPaymentModal .payment-save-btn,
#supplierLedgerPaymentModal .payment-save-btn {
    height: 42px !important;
    min-width: 150px !important;
    border: none !important;
    border-radius: 12px !important;

    background: linear-gradient(135deg,#7c3aed,#6d4cff) !important;
    color: #ffffff !important;

    font-size: 14px !important;
    font-weight: 900 !important;

    box-shadow:
        0 14px 30px rgba(109,76,255,.34),
        0 0 26px rgba(124,58,237,.20) !important;

    transition: all .22s ease !important;
}

#paymentModal #savePaymentBtn:hover,
#addPaymentModal #savePaymentBtn:hover,
#supplierPaymentModal #savePaymentBtn:hover,
#supplierLedgerPaymentModal #savePaymentBtn:hover,
button#savePaymentBtn:hover,
.payment-save-btn:hover {
    background: linear-gradient(135deg,#8b5cf6,#7c3aed) !important;
    color: #ffffff !important;
    transform: translateY(-2px) !important;
}

/* Disabled state */
#paymentModal #savePaymentBtn:disabled,
#addPaymentModal #savePaymentBtn:disabled,
#supplierPaymentModal #savePaymentBtn:disabled,
#supplierLedgerPaymentModal #savePaymentBtn:disabled,
button#savePaymentBtn:disabled,
.payment-save-btn:disabled {
    opacity: .65 !important;
    cursor: not-allowed !important;
    transform: none !important;
}

/* Cancel button clean */
.payment-cancel-btn,
#paymentModal .payment-cancel-btn,
#addPaymentModal .payment-cancel-btn,
#supplierPaymentModal .payment-cancel-btn,
#supplierLedgerPaymentModal .payment-cancel-btn {
    height: 42px !important;
    min-width: 120px !important;
    border: 1px solid rgba(148,163,184,.16) !important;
    border-radius: 12px !important;
    background: rgba(148,163,184,.18) !important;
    color: #e5e7eb !important;
    font-weight: 900 !important;
}

/* Remove any old yellow/gold button from modal footer */
#paymentModal .modal-footer button,
#addPaymentModal .modal-footer button,
#supplierPaymentModal .modal-footer button,
#supplierLedgerPaymentModal .modal-footer button {
    border-color: transparent !important;
}




</style>
</head>
<body>
<div class="container-scroll">
 <div class="sidebar" id="dashboardSidebar">
         <div class="sidebar" id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>
    </div>
    <div class="main-panel">
        <div class="topbar"><?php include('partials/topbar.php'); ?></div>

        <div class="content-wrapper">
            <h1 class="page-title">Supplier Ledger</h1>

            <form method="GET" class="toolbar">
                <input type="text" class="toolbar-input" name="search" placeholder="Search..." value="<?= e($search) ?>">

                <select class="toolbar-select" name="supplier_id">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $selectedSupplierId === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= e(supplierName($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="date" class="toolbar-input" name="date_from" value="<?= e($dateFrom) ?>">
                <input type="date" class="toolbar-input" name="date_to" value="<?= e($dateTo) ?>">

                <button type="submit" class="toolbar-btn btn-dark-soft"><i class="bi bi-funnel me-2"></i>Filter</button>
                <a href="supplier_ledger.php" class="toolbar-btn btn-dark-soft"><i class="bi bi-arrow-clockwise me-2"></i>Reset</a>
            </form>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:16px;">
                <button type="button" class="toolbar-btn btn-green" onclick="openPaymentModal()"><i class="bi bi-plus-lg me-2"></i>Add Payment</button>
                <button type="button" class="toolbar-btn btn-dark-soft" onclick="window.print()"><i class="bi bi-envelope me-2"></i>Email</button>
            </div>

            <div class="stats-grid">
                <div class="stat-card stat-green"><div class="stat-title">Total Purchase</div><div class="stat-value">PKR <?= number_format($totalPurchase) ?></div></div>
                <div class="stat-card stat-blue"><div class="stat-title">Total Paid</div><div class="stat-value">PKR <?= number_format($totalPaid) ?></div></div>
                <div class="stat-card stat-red"><div class="stat-title">Outstanding Balance</div><div class="stat-value">PKR <?= number_format($outstandingBalance) ?></div></div>
                <div class="stat-card stat-gold"><div class="stat-title">Overdue Amount</div><div class="stat-value">PKR <?= number_format($overdueAmount) ?></div></div>
                <div class="stat-card stat-yellow"><div class="stat-title">Transactions</div><div class="stat-value"><?= $totalTransactions ?></div></div>
            </div>

            <div class="charts-grid">
                <div class="chart-card"><div class="body"><div class="chart-title">Purchase vs Payment</div><div class="chart-box"><div class="bars"><div class="bar" style="height:45%"></div><div class="bar" style="height:35%"></div><div class="bar" style="height:55%"></div><div class="bar" style="height:65%"></div><div class="bar" style="height:42%"></div><div class="bar" style="height:58%"></div><div class="bar" style="height:24%"></div><div class="bar" style="height:30%"></div></div><div class="line"><svg viewBox="0 0 100 100" preserveAspectRatio="none"><polyline fill="none" stroke="#6b7ea7" stroke-width="1.6" points="0,70 12,45 24,48 36,30 48,38 60,24 72,15 84,28 100,22"/></svg></div></div></div></div>
                <div class="chart-card"><div class="body"><div class="chart-title">Outstanding Balance Analysis</div><div class="donut-wrap"><div class="donut"></div></div></div></div>
            </div>

            <div class="supplier-strip">
                <div class="supplier-strip-top">
                    <div class="warehouse-thumb"></div>
                    <div class="supplier-strip-name"><?= $currentSupplier ? e(supplierName($currentSupplier)) : 'All Suppliers' ?></div>
                </div>
                <div class="supplier-summary-row">
                    <div class="summary-pill"><b><?= $currentSupplier ? e(supplierName($currentSupplier)) : 'All Suppliers' ?></b></div>
                    <div class="summary-pill">PKR <b><?= number_format($totalPurchase) ?></b></div>
                    <div class="summary-pill">Outstanding: <b>PKR <?= number_format($outstandingBalance) ?></b></div>
                    <div class="summary-pill"><i class="bi bi-calendar-date"></i> <b><?= date('d/m/Y') ?></b></div>
                    <div class="summary-pill"><i class="bi bi-hourglass-split"></i> Avg Payment Cycle : <b><?= $avgPaymentCycle ?> days</b></div>
                    
                </div>
            </div>

            <div class="table-card">
                <div class="table-wrap">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th class="check-cell"><input type="checkbox" id="checkAll"></th>
                                <th>Date</th>
                                <th>Transaction Type</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Debit</th>
                                <th>Credit</th>
                                <th>Running Balance</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ledger)): ?>
                                <tr><td colspan="11" class="text-center py-4">No ledger entries found</td></tr>
                            <?php else: ?>
                                <?php foreach ($ledger as $index => $row): ?>
                                    <?php
                                    $statusCss = statusClass((string)($row['status'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="check-cell"><input type="checkbox" class="row-check" value="<?= e($row['row_type']) ?>-<?= (int)$row['source_id'] ?>"></td>
                                        <td><?= e($row['display_date'] ?: date('m/d/y', strtotime($row['date']))) ?></td>
                                        <td><?= e($row['entry_type']) ?></td>
                                        <td><?= e($row['reference']) ?></td>
                                        <td><?= e($row['description']) ?></td>
                                        <td><?= $row['debit'] > 0 ? number_format($row['debit']) : '' ?></td>
                                        <td><?= $row['credit'] > 0 ? number_format($row['credit']) : '' ?></td>
                                        <td><?= number_format($row['running_balance']) ?></td>
                                        <td><?= e($row['due_date']) ?></td>
                                        <td><span class="status-pill <?= $statusCss ?>"><?= e($row['status']) ?></span></td>
                                       <td>
    <div class="ledger-actions">
        <?php if (in_array($row['row_type'], ['purchase', 'payment'], true)): ?>
            <button
                class="ledger-action-btn ledger-view viewEntryBtn"
                type="button"
                data-row-type="<?= e($row['row_type']) ?>"
                data-id="<?= (int)$row['source_id'] ?>"
                title="View"
            >
                <i class="bi bi-eye-fill"></i>
            </button>
        <?php else: ?>
            <span class="ledger-action-btn ledger-view ledger-disabled" title="View disabled">
                <i class="bi bi-eye-fill"></i>
            </span>
        <?php endif; ?>

        <?php if ($row['row_type'] === 'payment'): ?>
            <button
                class="ledger-action-btn ledger-edit editPaymentBtn"
                type="button"
                data-id="<?= (int)$row['source_id'] ?>"
                title="Edit"
            >
                <i class="bi bi-pencil-fill"></i>
            </button>

            <button
                class="ledger-action-btn ledger-delete deletePaymentBtn"
                type="button"
                data-id="<?= (int)$row['source_id'] ?>"
                title="Delete"
            >
                <i class="bi bi-trash-fill"></i>
            </button>
        <?php else: ?>
            <span class="ledger-action-btn ledger-edit ledger-disabled" title="Edit disabled">
                <i class="bi bi-pencil-fill"></i>
            </span>

            <span class="ledger-action-btn ledger-delete ledger-disabled" title="Delete disabled">
                <i class="bi bi-trash-fill"></i>
            </span>
        <?php endif; ?>
    </div>
</td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td></td>
                                    <td><b>Total</b></td>
                                    <td><b>Ledger Total</b></td>
                                    <td></td>
                                    <td>Opening: PKR <?= number_format($totalOpeningBalance) ?></td>
                                    <td><?= number_format($totalDebit) ?></td>
                                    <td><?= number_format($totalCredit) ?></td>
                                    <td style="color:#c7485e;font-weight:800;">PKR <?= number_format($outstandingBalance) ?></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <div class="bulk-actions">
                        <button type="button" class="bulk-btn" id="bulkDeleteBtn"><i class="bi bi-trash me-1"></i>Bulk Delete</button>
                        <button type="button" class="bulk-btn" onclick="window.print()"><i class="bi bi-printer me-1"></i>Bulk Print</button>
                    </div>
                    <div class="footer-right">
                        <button type="button" class="bulk-btn">Export</button>
                        <span><?= count($ledger) ?> entries</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered payment-modal-dialog">
        <div class="modal-content payment-modal-content">
            <form id="paymentForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_payment">
                <input type="hidden" name="payment_id" id="payment_id">

                <div class="modal-header payment-modal-header">
                    <h5 class="modal-title" id="paymentModalTitle">Add Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body payment-modal-body">
                    <div class="mb-3">
                        <label class="payment-label">Supplier <span>*</span></label>
                        <select class="payment-input" name="supplier_id" id="payment_supplier_id" required>
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= $selectedSupplierId === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= e(supplierName($s)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="payment-balance-box">
                        <div class="payment-balance-item">
                            <small>Total Balance</small>
                            <strong class="red" id="modalTotalBalance">PKR <?= number_format($outstandingBalance) ?></strong>
                        </div>

                        <div class="payment-balance-item">
                            <small>Overdue Amount</small>
                            <strong class="gold" id="modalOverdueAmount">PKR <?= number_format($overdueAmount) ?></strong>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="payment-label">Payment Date</label>
                            <input type="date" class="payment-input" name="payment_date" id="payment_date" value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="payment-label">Payment Method</label>
                            <select class="payment-input" name="payment_method" id="payment_method">
                                <option value="">Select Method...</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="JazzCash">JazzCash</option>
                                <option value="EasyPaisa">EasyPaisa</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="payment-label">Amount <span>*</span></label>
                            <input type="number" step="0.01" min="0" class="payment-input" name="amount" id="payment_amount" placeholder="50000" required>
                        </div>

                        <div class="col-md-6">
                            <label class="payment-label">Quick Settle</label>
                            <div class="payment-settle-row">
                                <span>Settle full outstanding amount</span>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" name="settle_full" id="settle_full" value="1">
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="payment-label">Attachments</label>
                            <div class="payment-upload-box" onclick="document.getElementById('attachment').click()">
                                <i class="bi bi-upload me-2"></i> Upload Receipt (PDF, JPG, PNG)
                            </div>
                            <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png" hidden>
                            <div id="docName" class="payment-doc-name"></div>
                        </div>

                        <div class="col-12">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="send_receipt" name="send_receipt" value="1" checked>
                                <label class="form-check-label" for="send_receipt" style="color:#dbe0f4;">Send Payment Receipt to Supplier</label>
                            </div>
                        </div>

                        <div class="col-12 payment-summary">
                            <div class="payment-summary-line">
                                <span>Remaining Balance</span>
                                <b id="remainingBalanceText">PKR <?= number_format(max(0, $outstandingBalance)) ?></b>
                            </div>

                            <div class="payment-summary-line">
                                <span>Amount (PKR)</span>
                                <b id="amountText">PKR 0</b>
                            </div>

                            <div class="payment-summary-line">
                                <span>Settled Balance (PKR)</span>
                                <b id="settledText">PKR <?= number_format(max(0, $outstandingBalance)) ?></b>
                            </div>

                            <div class="payment-summary-line" style="border-top:1px solid rgba(255,255,255,0.08);padding-top:12px;">
                                <span>Total Purchase</span>
                                <b>PKR <?= number_format($totalPurchase) ?></b>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer payment-modal-footer">
                    <div class="payment-footer-note">Create or update supplier payment details.</div>

                    <div class="payment-footer-btns">
                        <button type="button" class="payment-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="payment-save-btn" id="savePaymentBtn">Add Payment</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewEntryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header">
                <h5 class="modal-title">Ledger Entry Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewEntryBody">Loading...</div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
const viewEntryModal = new bootstrap.Modal(document.getElementById('viewEntryModal'));
const totalOutstanding = <?= json_encode((float)$outstandingBalance) ?>;

function formatPKR(n){ return 'PKR ' + Number(n || 0).toLocaleString(); }

function resetPaymentForm() {
    document.getElementById('paymentForm').reset();
    document.getElementById('payment_id').value = '';
    document.getElementById('paymentModalTitle').innerText = 'Add Payment';
    document.getElementById('savePaymentBtn').innerText = 'Add Payment';
    document.getElementById('docName').style.display = 'none';
    document.getElementById('docName').textContent = '';
    updatePaymentSummary();
}

function openPaymentModal() {
    resetPaymentForm();
    paymentModal.show();
}

function updatePaymentSummary() {
    let amount = Number(document.getElementById('payment_amount').value || 0);
    if (document.getElementById('settle_full').checked) {
        amount = totalOutstanding;
        document.getElementById('payment_amount').value = totalOutstanding;
    }
    const remaining = Math.max(0, totalOutstanding - amount);
    document.getElementById('amountText').innerText = formatPKR(amount);
    document.getElementById('remainingBalanceText').innerText = formatPKR(remaining);
    document.getElementById('settledText').innerText = formatPKR(remaining);
}

document.getElementById('payment_amount').addEventListener('input', updatePaymentSummary);
document.getElementById('settle_full').addEventListener('change', updatePaymentSummary);

document.getElementById('attachment').addEventListener('change', function() {
    const file = this.files[0];
    const docName = document.getElementById('docName');
    if (!file) {
        docName.style.display = 'none';
        docName.textContent = '';
        return;
    }
    docName.textContent = file.name;
    docName.style.display = 'block';
});

document.getElementById('paymentForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const btn = document.getElementById('savePaymentBtn');
    const oldText = btn.innerText;
    btn.disabled = true;
    btn.innerText = 'Saving...';

    try {
        const response = await fetch('ajax_supplier_ledger.php', { method:'POST', body:formData });
        const res = await response.text();

        if (res.trim() === 'saved') {
            location.reload();
            return;
        }

        alert(res);
        btn.disabled = false;
        btn.innerText = oldText;
    } catch(e) {
        alert('Request failed');
        btn.disabled = false;
        btn.innerText = oldText;
    }
});

document.querySelectorAll('.viewEntryBtn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const rowType = this.dataset.rowType;
        const id = this.dataset.id;

        const body = new URLSearchParams();
        body.append('action', 'view_entry');
        body.append('row_type', rowType);
        body.append('id', id);

        const response = await fetch('ajax_supplier_ledger.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: body.toString()
        });
        const html = await response.text();
        document.getElementById('viewEntryBody').innerHTML = html;
        viewEntryModal.show();
    });
});

document.querySelectorAll('.editPaymentBtn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const body = new URLSearchParams();
        body.append('action', 'get_payment');
        body.append('id', this.dataset.id);

        const response = await fetch('ajax_supplier_ledger.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: body.toString()
        });

        const data = await response.json();
        if (!data.success) {
            alert(data.message || 'Payment not found');
            return;
        }

        resetPaymentForm();
        document.getElementById('payment_id').value = data.payment.id;
        document.getElementById('payment_supplier_id').value = data.payment.supplier_id;
        document.getElementById('payment_date').value = data.payment.payment_date;
        document.getElementById('payment_method').value = data.payment.payment_method || '';
        document.getElementById('payment_amount').value = data.payment.amount;
        document.getElementById('settle_full').checked = Number(data.payment.settle_full) === 1;
        document.getElementById('send_receipt').checked = Number(data.payment.send_receipt) === 1;

        document.getElementById('paymentModalTitle').innerText = 'Edit Payment';
        document.getElementById('savePaymentBtn').innerText = 'Update Payment';

        if (data.payment.attachment_name) {
            const docName = document.getElementById('docName');
            docName.textContent = data.payment.attachment_name;
            docName.style.display = 'block';
        }

        updatePaymentSummary();
        paymentModal.show();
    });
});

document.querySelectorAll('.deletePaymentBtn').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Delete this payment?')) return;

        const body = new URLSearchParams();
        body.append('action', 'delete_payment');
        body.append('id', this.dataset.id);

        const response = await fetch('ajax_supplier_ledger.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: body.toString()
        });

        const res = await response.text();
        if (res.trim() === 'deleted') {
            location.reload();
            return;
        }
        alert(res);
    });
});

document.getElementById('bulkDeleteBtn').addEventListener('click', async function() {
    const ids = Array.from(document.querySelectorAll('.row-check:checked'))
        .map(el => el.value)
        .filter(v => v.startsWith('payment-'))
        .map(v => v.replace('payment-',''));

    if (!ids.length) {
        alert('Select at least one payment row');
        return;
    }

    if (!confirm('Delete selected payment rows?')) return;

    for (const id of ids) {
        const body = new URLSearchParams();
        body.append('action', 'delete_payment');
        body.append('id', id);

        const response = await fetch('ajax_supplier_ledger.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: body.toString()
        });

        const res = await response.text();
        if (res.trim() !== 'deleted') {
            alert('Error deleting payment #' + id + ': ' + res);
            return;
        }
    }

    location.reload();
});

document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(ch => ch.checked = this.checked);
});
</script>
</body>
</html>