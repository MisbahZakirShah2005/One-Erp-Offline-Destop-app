<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
} 
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $tableName): bool {
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
    return $result && $result->num_rows > 0;
}

function columnExists(mysqli $conn, string $tableName, string $columnName): bool {
    $tableName = $conn->real_escape_string($tableName);
    $columnName = $conn->real_escape_string($columnName);
    $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
    return $result && $result->num_rows > 0;
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die("Invalid quotation ID.");
}

$hasSelectedItemsJsonColumn = tableExists($conn, 'quotations') && columnExists($conn, 'quotations', 'selected_items_json');

if ($hasSelectedItemsJsonColumn) {
    $sql = "SELECT id, client_id, quotation_no, quotation_date, customer_name, phone, total_amount, status, created_at, selected_items_json
            FROM quotations
            WHERE id = ? AND client_id = ?
            LIMIT 1";
} else {
    $sql = "SELECT id, client_id, quotation_no, quotation_date, customer_name, phone, total_amount, status, created_at
            FROM quotations
            WHERE id = ? AND client_id = ?
            LIMIT 1";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("ii", $id, $logged_client_id);
$stmt->execute();
$quotation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quotation) {
    die("Quotation not found.");
}

$companyName = '';
$companyPhone = '';
$companyAddress = '';

if (tableExists($conn, 'clients')) {
    $fields = [];
    $fields[] = columnExists($conn, 'clients', 'business_name') ? "business_name" : "'' AS business_name";
    $fields[] = columnExists($conn, 'clients', 'contact_number') ? "contact_number" : "'' AS contact_number";
    $fields[] = columnExists($conn, 'clients', 'business_address') ? "business_address" : "'' AS business_address";

    $clientSql = "SELECT " . implode(', ', $fields) . " FROM clients WHERE id = ? LIMIT 1";
    $clientStmt = $conn->prepare($clientSql);

    if ($clientStmt) {
        $clientStmt->bind_param("i", $logged_client_id);
        $clientStmt->execute();
        $clientRes = $clientStmt->get_result();

        if ($clientRow = $clientRes->fetch_assoc()) {
            $companyName = trim((string)($clientRow['business_name'] ?? ''));
            $companyPhone = trim((string)($clientRow['contact_number'] ?? ''));
            $companyAddress = trim((string)($clientRow['business_address'] ?? ''));
        }

        $clientStmt->close();
    }
}

if ($companyName === '') $companyName = 'Company';
if ($companyPhone === '') $companyPhone = '-';
if ($companyAddress === '') $companyAddress = '-';

$billItems = [];

if ($hasSelectedItemsJsonColumn && !empty($quotation['selected_items_json'])) {
    $decoded = json_decode($quotation['selected_items_json'], true);

    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            $qty = (float)($row['qty'] ?? 0);
            $rate = (float)($row['rate'] ?? 0);
            $amount = isset($row['amount']) ? (float)$row['amount'] : ($qty * $rate);

            $billItems[] = [
                'item_id' => $row['item_id'] ?? '',
                'name'    => $row['name'] ?? '',
                'qty'     => $qty,
                'rate'    => $rate,
                'amount'  => $amount,
            ];
        }
    }
}

if (empty($billItems) && tableExists($conn, 'quotation_items')) {
    $qiSql = "SELECT item_id, item_name, qty, rate, amount
              FROM quotation_items
              WHERE quotation_id = ?
              ORDER BY id ASC";

    $qiStmt = $conn->prepare($qiSql);
    if ($qiStmt) {
        $qiStmt->bind_param("i", $id);
        $qiStmt->execute();
        $qiRes = $qiStmt->get_result();

        while ($row = $qiRes->fetch_assoc()) {
            $billItems[] = [
                'item_id' => $row['item_id'] ?? '',
                'name'    => $row['item_name'] ?? '',
                'qty'     => (float)($row['qty'] ?? 0),
                'rate'    => (float)($row['rate'] ?? 0),
                'amount'  => (float)($row['amount'] ?? 0),
            ];
        }

        $qiStmt->close();
    }
}

$subtotal = 0;
foreach ($billItems as $row) {
    $subtotal += (float)$row['amount'];
}

$grandTotal = (float)($quotation['total_amount'] ?? 0);
$discount = 0;
$tax = 0;

if ($subtotal > 0) {
    $netBeforeTax = $subtotal - $discount;
    $tax = max($grandTotal - $netBeforeTax, 0);
} else {
    $subtotal = $grandTotal;
    $tax = 0;
}

$taxRatePercent = ($subtotal > 0 && $tax > 0) ? round(($tax / $subtotal) * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice - <?= h($quotation['quotation_no']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

   <style>
    * {
        box-sizing: border-box;
    }

    :root {
        --dark: #0f172a;
        --dark-2: #13233f;
        --text: #111827;
        --muted: #64748b;
        --line: #d7dee7;
        --soft: #f8fafc;
        --yellow: #ffb600;
        --blue: #003366;
    }

    @page {
        size: A4;
        margin: 6mm;
    }

    html,
    body {
        margin: 0;
        padding: 0;
    }

    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #eef2f7;
        color: var(--text);
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .print-bar {
        width: 210mm;
        margin: 12px auto 0;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .print-btn {
        border: none;
        border-radius: 10px;
        padding: 10px 16px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
    }

    .print-btn.primary {
        background: var(--blue);
        color: #fff;
    }

    .print-btn.secondary {
        background: #e5e7eb;
        color: #111827;
    }

    .screen-topbar {
        width: 210mm;
        margin: 0 auto;
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: #111827;
    }

    .page {
        width: 210mm;
        min-height: calc(297mm - 12mm);
        margin: 10px auto 20px;
        background: #fff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
        padding: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .sheet-body {
        flex: 1;
        min-height: calc(297mm - 12mm);
        display: flex;
        flex-direction: column;
    }

    .header {
        background: linear-gradient(135deg, var(--dark) 0%, var(--dark-2) 100%);
        color: #fff;
        padding: 20px 22px 16px;
    }

    .header-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        width: 100%;
    }

    .company-block {
        flex: 1 1 auto;
        min-width: 0;
    }

    .company-name {
        margin: 0 0 8px;
        font-size: 22px;
        font-weight: 800;
        line-height: 1.1;
    }

    .company-meta {
        font-size: 12px;
        line-height: 1.6;
        color: rgba(255, 255, 255, .88);
    }

    .quotation-block {
        min-width: 220px;
        margin-left: auto;
        text-align: right;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        justify-content: flex-start;
        flex-shrink: 0;
    }

    .quotation-title {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: .3px;
        text-align: right;
        width: 100%;
    }

    .quotation-ref {
        display: inline-block;
        margin-top: 10px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 700;
        border-radius: 999px;
        background: rgba(255, 255, 255, .12);
        text-align: right;
    }

    .section {
        padding: 12px 22px 0;
    }

    .top-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .card {
        border: 1px solid var(--line);
        border-radius: 12px;
        background: #fff;
        padding: 12px 14px;
    }

    .card-title {
        margin: 0 0 6px;
        font-size: 11px;
        font-weight: 800;
        color: #415a77;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .card-text {
        font-size: 13px;
        line-height: 1.65;
        color: #334155;
    }

    .card-text strong {
        color: #111827;
    }

    .divider {
        border-top: 1px solid var(--line);
        margin: 12px 0 0;
    }

    .section-title {
        margin: 0 0 8px;
        font-size: 16px;
        font-weight: 800;
        color: #111827;
    }

    .table-wrap {
        border: 1px solid var(--line);
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    colgroup col:nth-child(1) {
        width: 36px;
    }

    colgroup col:nth-child(2) {
        width: auto;
    }

    colgroup col:nth-child(3) {
        width: 65px;
    }

    colgroup col:nth-child(4) {
        width: 95px;
    }

    colgroup col:nth-child(5) {
        width: 105px;
    }

    thead th {
        background: #eaf0f8;
        color: #0f172a;
        padding: 9px 8px;
        font-size: 11px;
        font-weight: 800;
        border-bottom: 1px solid var(--line);
    }

    tbody td {
        padding: 8px 8px;
        font-size: 11px;
        border-bottom: 1px solid #edf2f7;
        color: #334155;
        vertical-align: middle;
    }

    tbody tr:last-child td {
        border-bottom: none;
    }

    th:nth-child(1),
    td:nth-child(1),
    th:nth-child(3),
    td:nth-child(3) {
        text-align: center;
    }

    th:nth-child(2),
    td:nth-child(2) {
        text-align: left;
    }

    th:nth-child(4),
    td:nth-child(4),
    th:nth-child(5),
    td:nth-child(5) {
        text-align: right;
    }

    td:nth-child(2) {
        font-weight: 600;
        color: #111827;
        line-height: 1.4;
        word-break: break-word;
    }

    .editable-input {
        width: 100%;
        border: 1px solid transparent;
        background: transparent;
        border-radius: 6px;
        font-size: 11px;
        padding: 1px 3px;
        outline: none;
        color: #111827;
    }

    .editable-input:focus {
        border-color: #cbd5e1;
        background: #fff;
    }

    .qty-input {
        width: 66px;
        text-align: center;
        margin: 0 auto;
        display: block;
    }

    .rate-input {
        width: 100px;
        text-align: right;
        margin-left: auto;
        display: block;
    }

    .print-only-value {
        display: none;
        font-size: 11px;
        color: #111827;
        font-weight: 600;
    }

    .bottom-grid {
        display: grid;
        grid-template-columns: 1.2fr .85fr;
        gap: 14px;
        margin-top: 12px;
    }

    .notes-box {
        border: 1px solid var(--line);
        border-radius: 12px;
        background: #fff;
        padding: 14px;
        min-height: 120px;
    }

    .notes-title {
        margin: 0 0 8px;
        font-size: 14px;
        font-weight: 800;
        color: #111827;
    }

    .notes-text {
        margin: 0;
        font-size: 11px;
        line-height: 1.7;
        color: #334155;
    }

    .summary-card {
        border: 1px solid var(--line);
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
        align-self: start;
    }

    .summary-head {
        padding: 12px 14px 9px;
        background: #fbfcfe;
        border-bottom: 1px solid var(--line);
    }

    .summary-kicker {
        font-size: 10px;
        font-weight: 800;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .08em;
        margin-bottom: 4px;
    }

    .summary-title {
        margin: 0;
        font-size: 16px;
        font-weight: 800;
        color: #111827;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 9px 14px;
        font-size: 12px;
        color: #334155;
        border-bottom: 1px solid #edf2f7;
    }

    .summary-row:last-child {
        border-bottom: none;
    }

    .summary-row strong {
        color: #111827;
        font-size: 13px;
    }

    .summary-total {
        background: #0f172a;
        color: #fff;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
    }

    .summary-total small {
        display: block;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em;
        opacity: .9;
        margin-bottom: 4px;
    }

    .summary-total span {
        font-size: 11px;
        opacity: .8;
    }

    .summary-total strong {
        font-size: 22px;
        white-space: nowrap;
    }

    .spacer {
        flex: 1;
        display: block;
        min-height: 12px;
    }

    .footer {
        border-top: 1px solid var(--line);
        padding: 10px 22px 12px;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 16px;
        font-size: 10px;
        color: #475569;
        background: #fcfcfd;
        margin-top: auto;
        width: 100%;
    }

     .footer-left,
    .footer-right {
        line-height: 1.6;
        width: 50%;
    }
    .footer-left {
        text-align: left;
    }


   .footer-right {
        text-align: right;
        margin-left: auto;
    }

    .empty-items {
        padding: 16px;
        text-align: center;
        border: 1px dashed #cbd5e1;
        border-radius: 12px;
        background: #f8fafc;
        color: #64748b;
        font-size: 12px;
    }

    @media (max-width: 768px) {
        .print-bar,
        .screen-topbar,
        .page {
            width: 100%;
        }

        .page {
            min-height: auto;
            margin: 0;
        }

        .sheet-body {
            min-height: auto;
        }

        .header-top,
        .footer {
            flex-direction: column;
            align-items: flex-start;
        }

        .quotation-block,
        .footer-right {
            text-align: left;
            align-items: flex-start;
            margin-left: 0;
        }

        .top-grid,
        .bottom-grid {
            grid-template-columns: 1fr;
        }

        .spacer {
            display: none;
        }
    }

    @media print {
        @page {
            size: A4;
            margin: 6mm;
        }

        html,
        body {
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            width: 100% !important;
            height: auto !important;
        }

        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }

        body {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11px !important;
        }

        .print-bar,
        .screen-topbar {
            display: none !important;
        }

        .page {
            width: 100% !important;
            min-height: calc(297mm - 12mm) !important;
            margin: 0 !important;
            border: none !important;
            box-shadow: none !important;
            background: #fff !important;
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .sheet-body {
            min-height: calc(297mm - 12mm) !important;
            display: flex !important;
            flex-direction: column !important;
            flex: 1 !important;
        }

        .header {
            background: #0f172a !important;
            background-image: none !important;
            color: #fff !important;
            padding: 16px 18px 14px !important;
        }

        .header-top {
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            gap: 20px !important;
            width: 100% !important;
        }

        .company-block {
            flex: 1 1 auto !important;
            min-width: 0 !important;
        }

        .quotation-block {
            min-width: 220px !important;
            margin-left: auto !important;
            text-align: right !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: flex-end !important;
            justify-content: flex-start !important;
            flex-shrink: 0 !important;
        }

        .company-name {
            font-size: 20px !important;
            margin-bottom: 6px !important;
        }

        .company-meta,
        .quotation-title,
        .company-name,
        .quotation-ref {
            color: #fff !important;
        }

        .quotation-title {
            font-size: 20px !important;
            text-align: right !important;
            width: 100% !important;
        }

        .quotation-ref {
            background: rgba(255, 255, 255, 0.12) !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            margin-top: 8px !important;
            font-size: 10px !important;
            text-align: right !important;
        }

        .section {
            padding: 10px 18px 0 !important;
        }

        .top-grid {
            grid-template-columns: 1fr 1fr !important;
            gap: 10px !important;
        }

        .bottom-grid {
            grid-template-columns: 1.2fr .85fr !important;
            gap: 12px !important;
            margin-top: 10px !important;
        }

        .card,
        .notes-box,
        .summary-card,
        .table-wrap {
            background: #fff !important;
            border-radius: 10px !important;
        }

        .card {
            padding: 10px 12px !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .card-title {
            margin-bottom: 5px !important;
            font-size: 10px !important;
        }

        .card-text {
            font-size: 12px !important;
            line-height: 1.5 !important;
        }

        .section-title {
            font-size: 15px !important;
            margin-bottom: 7px !important;
        }

        table {
            width: 100% !important;
            table-layout: fixed !important;
        }

        colgroup col:nth-child(1) {
            width: 34px !important;
        }

        colgroup col:nth-child(2) {
            width: auto !important;
        }

        colgroup col:nth-child(3) {
            width: 60px !important;
        }

        colgroup col:nth-child(4) {
            width: 90px !important;
        }

        colgroup col:nth-child(5) {
            width: 95px !important;
        }

        thead {
            display: table-header-group !important;
        }

        thead th {
            background: #eaf0f8 !important;
            padding: 8px 6px !important;
            font-size: 10px !important;
        }

        tbody td {
            padding: 7px 6px !important;
            font-size: 10.5px !important;
            line-height: 1.35 !important;
            vertical-align: middle !important;
        }

        tr {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .table-wrap,
        .summary-card,
        .footer,
        .header {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .notes-box {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            min-height: 120px !important;
            padding: 12px !important;
        }

        .notes-title {
            font-size: 13px !important;
            margin-bottom: 6px !important;
        }

        .notes-text {
            font-size: 10px !important;
            line-height: 1.55 !important;
        }

        .summary-head {
            padding: 10px 12px 8px !important;
        }

        .summary-title {
            font-size: 15px !important;
        }

        .summary-row {
            padding: 8px 12px !important;
            font-size: 11px !important;
        }

        .summary-row strong {
            font-size: 12px !important;
        }

        .summary-total {
            background: #0f172a !important;
            color: #fff !important;
            padding: 10px 12px !important;
        }

        .summary-total small,
        .summary-total span,
        .summary-total strong {
            color: #fff !important;
        }

        .summary-total strong {
            font-size: 20px !important;
        }

        .editable-input {
            display: none !important;
        }

        .print-only-value {
            display: block !important;
            font-size: 10.5px !important;
            font-weight: 600 !important;
        }

        .qty-input + .print-only-value {
            text-align: center !important;
        }

        .rate-input + .print-only-value {
            text-align: right !important;
        }
        /* number input arrows / dropdown hide */
.qty-input::-webkit-outer-spin-button,
.qty-input::-webkit-inner-spin-button,
.rate-input::-webkit-outer-spin-button,
.rate-input::-webkit-inner-spin-button{
    -webkit-appearance: none;
    margin: 0;
}

.qty-input,
.rate-input{
    -moz-appearance: textfield;
    appearance: textfield;
}

        input[type=number]::-webkit-outer-spin-button,
        input[type=number]::-webkit-inner-spin-button {
            -webkit-appearance: none !important;
            margin: 0 !important;
        }

        input[type=number] {
            -moz-appearance: textfield !important;
            appearance: textfield !important;
        }

        .spacer {
            flex: 1 1 auto !important;
            display: block !important;
            min-height: 0 !important;
        }

        .footer {
            margin-top: auto !important;
            padding: 8px 18px 10px !important;
            background: #fcfcfd !important;
            font-size: 9.5px !important;

            display: table !important;
            width: 100% !important;
            table-layout: fixed !important;
            border-top: 1px solid #d7dee7 !important;
        }

        .footer-left,
        .footer-right {
            display: table-cell !important;
            width: 50% !important;
            vertical-align: bottom !important;
            line-height: 1.5 !important;
        }

        .footer-left {
            text-align: left !important;
            padding-right: 12px !important;
        }

        .footer-right {
            text-align: right !important;
            padding-left: 12px !important;
            margin-left: 0 !important;
        }
        
        /* FORCE REMOVE NUMBER INPUT SPINNER */
input[type="number"]::-webkit-outer-spin-button,
input[type="number"]::-webkit-inner-spin-button {
    -webkit-appearance: none !important;
    margin: 0 !important;
}

input[type="number"] {
    -moz-appearance: textfield !important;
    appearance: textfield !important;
}
    }
</style>
</head>
<body>

<div class="print-bar">
    <button class="print-btn secondary" onclick="window.close()">Close</button>
    <button class="print-btn primary" onclick="window.print()">Print Bill</button>
</div>

<div class="screen-topbar">
    <div><?= date('d/m/Y, H:i') ?></div>
    <div>Invoice - <?= h($quotation['quotation_no']) ?></div>
</div>

<div class="page">
    <div class="sheet-body">

        <div class="header">
            <div class="header-top">
                <div class="company-block">
                    <h1 class="company-name"><?= h($companyName) ?></h1>
                    <div class="company-meta">
                        <?= h($companyAddress) ?><br>
                        Phone: <?= h($companyPhone) ?>
                    </div>
                </div>

                <div class="quotation-block">
                    <h2 class="quotation-title">INVOICE</h2>
                    <div class="quotation-ref">Reference # <?= h($quotation['quotation_no']) ?></div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="top-grid">
                <div class="card">
                    <h3 class="card-title">Bill To</h3>
                    <div class="card-text">
                        <strong><?= h($quotation['customer_name']) ?></strong><br>
                        Phone: <?= h($quotation['phone']) ?><br>
                        Date: <?= h(date('d M Y', strtotime($quotation['quotation_date']))) ?>
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title">Invoice Details</h3>
                    <div class="card-text">
                        <strong>Invoice #:</strong> <?= h($quotation['quotation_no']) ?><br>
                        <strong>Created:</strong> <?= h(date('d M Y, h:i A', strtotime($quotation['created_at']))) ?><br>
                        <strong>Reference ID:</strong> <?= (int)$quotation['id'] ?>
                    </div>
                </div>
            </div>

            <div class="divider"></div>
        </div>

        <div class="section">
            <h3 class="section-title">Items Summary</h3>

            <?php if (!empty($billItems)): ?>
                <div class="table-wrap">
                    <table id="billTable">
                        <colgroup>
                            <col>
                            <col>
                            <col>
                            <col>
                            <col>
                        </colgroup>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Item Description</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($billItems as $index => $item): ?>
                                <tr class="item-row">
                                    <td><?= $index + 1 ?></td>
                                    <td><?= h($item['name']) ?></td>
                                    <td>
                                        <input
    type="number"
    step="1"
    min="1"
    class="editable-input qty-input"
    value="<?= h((int)$item['qty']) ?>"
>
                                        <span class="print-only-value">
                                            <?= h(rtrim(rtrim(number_format((float)$item['qty'], 2, '.', ''), '0'), '.')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="1"
                                            min="1"
                                            class="editable-input rate-input"
                                            value="<?= h(number_format((float)$item['rate'], 2, '.', '')) ?>"
                                        >
                                        <span class="print-only-value">
                                            Rs. <?= h(number_format((float)$item['rate'], 2)) ?>
                                        </span>
                                    </td>
                                    <td class="amount-output">Rs. <?= number_format((float)$item['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-items">
                    No line items were found for this invoice.
                </div>
            <?php endif; ?>

            <div class="bottom-grid">
                <div class="notes-box">
                    <h4 class="notes-title">Notes & Terms</h4>
                    <p class="notes-text">
                        This invoice is generated digitally and serves as an official business document.
                        Prices, quantities, and terms are subject to final confirmation where applicable.
                        Thank you for considering <?= h($companyName) ?>. We value your trust and look forward to serving you professionally.
                    </p>
                </div>

                <div class="summary-card">
                    <div class="summary-head">
                        <div class="summary-kicker">Financial Summary</div>
                        <h4 class="summary-title">Amount Details</h4>
                    </div>

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <strong id="subtotalValue">Rs. <?= number_format($subtotal, 2) ?></strong>
                    </div>

                    <div class="summary-row">
                        <span>Discount</span>
                        <strong id="discountValue">Rs. <?= number_format($discount, 2) ?></strong>
                    </div>

                    <div class="summary-row">
                        <span>Tax</span>
                        <strong id="taxValue">Rs. <?= number_format($tax, 2) ?></strong>
                    </div>

                    <div class="summary-total">
                        <div>
                            <small>Grand Total</small>
                            <span>Final payable amount</span>
                        </div>
                        <strong id="grandTotalValue">Rs. <?= number_format($grandTotal, 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>

     <div class="spacer"></div>

        <div class="footer">
            <div class="footer-left">
                <strong><?= h($companyName) ?></strong><br>
                This document was generated electronically and does not require a physical signature.
            </div>

            <div class="footer-right">
                <strong>Prepared On:</strong> <?= h(date('d M Y, h:i A')) ?><br>
                <strong>Status:</strong> <?= h(ucfirst($quotation['status'] ?? 'Pending')) ?>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    const subtotalEl = document.getElementById('subtotalValue');
    const discountEl = document.getElementById('discountValue');
    const taxEl = document.getElementById('taxValue');
    const grandTotalEl = document.getElementById('grandTotalValue');

    const taxRatePercent = <?= json_encode((float)$taxRatePercent) ?>;
    const fixedDiscount = <?= json_encode((float)$discount) ?>;

    function formatMoney(value) {
        return 'Rs. ' + Number(value).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatPlain(value) {
        const num = Number(value || 0);
        return num.toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
    }

    function formatRate(value) {
        const num = Number(value || 0);
        return 'Rs. ' + num.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function recalcBill() {
        let subtotal = 0;

        document.querySelectorAll('.item-row').forEach(row => {
            const qtyInput = row.querySelector('.qty-input');
            const rateInput = row.querySelector('.rate-input');
            const amountOutput = row.querySelector('.amount-output');

            const qtyPrint = qtyInput ? qtyInput.nextElementSibling : null;
            const ratePrint = rateInput ? rateInput.nextElementSibling : null;

            const qty = parseFloat(qtyInput?.value || 0);
            const rate = parseFloat(rateInput?.value || 0);
            const amount = qty * rate;

            subtotal += amount;

            if (amountOutput) {
                amountOutput.textContent = formatMoney(amount);
            }

            if (qtyPrint && qtyPrint.classList.contains('print-only-value')) {
                qtyPrint.textContent = formatPlain(qty);
            }

            if (ratePrint && ratePrint.classList.contains('print-only-value')) {
                ratePrint.textContent = formatRate(rate);
            }
        });

        const discount = fixedDiscount;
        const net = Math.max(subtotal - discount, 0);
        const tax = (net * taxRatePercent) / 100;
        const grandTotal = net + tax;

        subtotalEl.textContent = formatMoney(subtotal);
        discountEl.textContent = formatMoney(discount);
        taxEl.textContent = formatMoney(tax);
        grandTotalEl.textContent = formatMoney(grandTotal);
    }

    document.querySelectorAll('.qty-input, .rate-input').forEach(input => {
        input.addEventListener('input', recalcBill);
        input.addEventListener('change', recalcBill);
    });

    window.addEventListener('beforeprint', function () {
        recalcBill();
    });

    recalcBill();
})();
</script>

</body>
</html>