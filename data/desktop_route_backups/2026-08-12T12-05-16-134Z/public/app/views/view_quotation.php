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

$quotationItems = [];

if ($hasSelectedItemsJsonColumn && !empty($quotation['selected_items_json'])) {
    $decoded = json_decode($quotation['selected_items_json'], true);

    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            $qty = (float)($row['qty'] ?? 0);
            $rate = (float)($row['rate'] ?? 0);
            $amount = isset($row['amount']) ? (float)$row['amount'] : ($qty * $rate);

            $quotationItems[] = [
                'item_id' => $row['item_id'] ?? ($row['id'] ?? ''),
                'name'    => $row['name'] ?? '',
                'qty'     => $qty,
                'rate'    => $rate,
                'amount'  => $amount,
            ];
        }
    }
}

if (empty($quotationItems) && tableExists($conn, 'quotation_items')) {
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
            $quotationItems[] = [
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
foreach ($quotationItems as $row) {
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
    <title>Quotation - <?= h($quotation['quotation_no']) ?></title>
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
    }
    /* =====================================================
   VIEW QUOTATION PRINT FRIENDLY ALIGNMENT FIX
   Paste at VERY END before 
===================================================== */

:root{
    --print-black:#111827;
    --print-text:#1f2937;
    --print-muted:#4b5563;
    --print-line:#cfd6df;
    --print-soft:#f3f4f6;
    --print-light:#fafafa;
}

@page{
    size:A4;
    margin:10mm;
}

html,
body{
    margin:0 !important;
    padding:0 !important;
}

body{
    background:#e9eef5 !important;
    color:var(--print-text) !important;
    font-family:Arial, Helvetica, sans-serif !important;
}

/* Screen alignment */
.print-bar,
.screen-topbar,
.page{
    width:190mm !important;
    max-width:190mm !important;
}

.print-bar{
    margin:12px auto 0 !important;
    padding:0 !important;
    display:flex !important;
    justify-content:flex-end !important;
    align-items:center !important;
    gap:8px !important;
}

.screen-topbar{
    margin:6px auto 0 !important;
    padding:0 !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    font-size:11px !important;
    color:#111827 !important;
}

.page{
    margin:8px auto 22px !important;
    background:#ffffff !important;
    border:1px solid #d5dbe3 !important;
    box-shadow:0 10px 28px rgba(15,23,42,.10) !important;
    min-height:auto !important;
    overflow:visible !important;
}

.sheet-body{
    min-height:auto !important;
    display:block !important;
    background:#fff !important;
}

/* Header printer friendly */
.header{
    background:#ffffff !important;
    background-image:none !important;
    color:var(--print-black) !important;
    padding:18px 20px 14px !important;
    border-bottom:2px solid var(--print-black) !important;
}

.header-top{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) 230px !important;
    gap:16px !important;
    align-items:start !important;
    width:100% !important;
}

.company-block{
    min-width:0 !important;
}

.company-name{
    margin:0 0 6px !important;
    color:var(--print-black) !important;
    font-size:22px !important;
    font-weight:800 !important;
    line-height:1.15 !important;
}

.company-meta{
    color:var(--print-muted) !important;
    font-size:12px !important;
    line-height:1.55 !important;
}

.quotation-block{
    min-width:0 !important;
    margin-left:0 !important;
    text-align:right !important;
    align-items:flex-end !important;
}

.quotation-title{
    margin:0 !important;
    color:var(--print-black) !important;
    font-size:23px !important;
    font-weight:900 !important;
    letter-spacing:.5px !important;
    line-height:1.1 !important;
}

.quotation-ref{
    margin-top:8px !important;
    padding:5px 8px !important;
    background:var(--print-soft) !important;
    color:var(--print-black) !important;
    border:1px solid #9ca3af !important;
    border-radius:4px !important;
    font-size:10.5px !important;
    font-weight:700 !important;
}

/* Main spacing */
.section{
    padding:11px 20px 0 !important;
}

.top-grid{
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:10px !important;
}

.card{
    border:1px solid var(--print-line) !important;
    border-radius:8px !important;
    background:#fff !important;
    padding:11px 12px !important;
}

.card-title{
    margin:0 0 6px !important;
    color:var(--print-black) !important;
    font-size:10.5px !important;
    font-weight:800 !important;
    letter-spacing:.08em !important;
    text-transform:uppercase !important;
}

.card-text{
    color:var(--print-text) !important;
    font-size:12px !important;
    line-height:1.55 !important;
}

.card-text strong{
    color:var(--print-black) !important;
}

.divider{
    border-top:1px solid var(--print-line) !important;
    margin:11px 0 0 !important;
}

.section-title{
    margin:0 0 8px !important;
    color:var(--print-black) !important;
    font-size:15px !important;
    font-weight:800 !important;
}

/* Table clean alignment */
.table-wrap{
    border:1px solid var(--print-line) !important;
    border-radius:8px !important;
    overflow:hidden !important;
    background:#fff !important;
}

table{
    width:100% !important;
    border-collapse:collapse !important;
    table-layout:fixed !important;
}

colgroup col:nth-child(1){
    width:34px !important;
}

colgroup col:nth-child(2){
    width:auto !important;
}

colgroup col:nth-child(3){
    width:58px !important;
}

colgroup col:nth-child(4){
    width:90px !important;
}

colgroup col:nth-child(5){
    width:100px !important;
}

thead th{
    background:#eeeeee !important;
    color:var(--print-black) !important;
    padding:8px 7px !important;
    font-size:10.5px !important;
    font-weight:800 !important;
    border-bottom:1px solid var(--print-line) !important;
}

tbody td{
    background:#fff !important;
    color:var(--print-text) !important;
    padding:7px 7px !important;
    font-size:10.5px !important;
    line-height:1.35 !important;
    border-bottom:1px solid #e5e7eb !important;
    vertical-align:middle !important;
}

tbody tr:last-child td{
    border-bottom:none !important;
}

th:nth-child(1),
td:nth-child(1),
th:nth-child(3),
td:nth-child(3){
    text-align:center !important;
}

th:nth-child(2),
td:nth-child(2){
    text-align:left !important;
}

th:nth-child(4),
td:nth-child(4),
th:nth-child(5),
td:nth-child(5){
    text-align:right !important;
}

td:nth-child(2){
    color:var(--print-black) !important;
    font-weight:700 !important;
    word-break:break-word !important;
    overflow-wrap:anywhere !important;
}

/* Notes + Summary alignment */
.bottom-grid{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) 280px !important;
    gap:12px !important;
    align-items:start !important;
    margin-top:11px !important;
}

.notes-box{
    border:1px solid var(--print-line) !important;
    border-radius:8px !important;
    background:#fff !important;
    padding:12px !important;
    min-height:118px !important;
}

.notes-title{
    margin:0 0 7px !important;
    color:var(--print-black) !important;
    font-size:13px !important;
    font-weight:800 !important;
}

.notes-text{
    margin:0 !important;
    color:var(--print-text) !important;
    font-size:10.5px !important;
    line-height:1.6 !important;
}

.summary-card{
    width:100% !important;
    border:1px solid var(--print-line) !important;
    border-radius:8px !important;
    overflow:hidden !important;
    background:#fff !important;
    align-self:start !important;
}

.summary-head{
    background:#f6f7f9 !important;
    border-bottom:1px solid var(--print-line) !important;
    padding:10px 12px 8px !important;
}

.summary-kicker{
    color:var(--print-muted) !important;
    font-size:9.5px !important;
    font-weight:800 !important;
    text-transform:uppercase !important;
    letter-spacing:.08em !important;
    margin-bottom:4px !important;
}

.summary-title{
    margin:0 !important;
    color:var(--print-black) !important;
    font-size:15px !important;
    font-weight:800 !important;
}

.summary-row{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:10px !important;
    padding:8px 12px !important;
    color:var(--print-text) !important;
    font-size:11px !important;
    border-bottom:1px solid #e5e7eb !important;
}

.summary-row strong{
    color:var(--print-black) !important;
    font-size:12px !important;
    white-space:nowrap !important;
}

.summary-total{
    background:#eeeeee !important;
    color:var(--print-black) !important;
    border-top:1px solid var(--print-line) !important;
    padding:10px 12px !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:10px !important;
}

.summary-total small,
.summary-total span,
.summary-total strong{
    color:var(--print-black) !important;
}

.summary-total small{
    display:block !important;
    font-size:9px !important;
    font-weight:800 !important;
    text-transform:uppercase !important;
    letter-spacing:.08em !important;
    margin-bottom:3px !important;
}

.summary-total span{
    font-size:10px !important;
}

.summary-total strong{
    font-size:19px !important;
    font-weight:900 !important;
    white-space:nowrap !important;
}

/* Footer */
.spacer{
    display:block !important;
    min-height:12px !important;
}

.footer{
    margin-top:16px !important;
    border-top:1px solid var(--print-line) !important;
    background:#fafafa !important;
    padding:9px 20px 11px !important;
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:14px !important;
    align-items:end !important;
    color:var(--print-muted) !important;
    font-size:9.5px !important;
}

.footer-left,
.footer-right{
    width:auto !important;
    line-height:1.5 !important;
}

.footer-left{
    text-align:left !important;
}

.footer-right{
    text-align:right !important;
    margin-left:0 !important;
}

/* ================= PRINT FINAL ================= */
@media print{
    @page{
        size:A4;
        margin:10mm;
    }

    html,
    body{
        margin:0 !important;
        padding:0 !important;
        background:#fff !important;
        width:auto !important;
        height:auto !important;
        overflow:visible !important;
    }

    *{
        -webkit-print-color-adjust:exact !important;
        print-color-adjust:exact !important;
        color-adjust:exact !important;
    }

    .print-bar,
    .screen-topbar{
        display:none !important;
    }

    .page{
        width:100% !important;
        max-width:none !important;
        min-height:calc(297mm - 20mm) !important;
        margin:0 !important;
        padding:0 !important;
        border:none !important;
        box-shadow:none !important;
        background:#fff !important;
        overflow:visible !important;
    }

    .sheet-body{
        min-height:calc(297mm - 20mm) !important;
        display:flex !important;
        flex-direction:column !important;
        background:#fff !important;
    }

    .header{
        padding:14px 16px 12px !important;
        background:#fff !important;
        color:#111827 !important;
        border-bottom:2px solid #111827 !important;
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    .header-top{
        display:grid !important;
        grid-template-columns:minmax(0,1fr) 220px !important;
        gap:14px !important;
        align-items:start !important;
    }

    .company-name{
        font-size:20px !important;
        margin:0 0 5px !important;
        color:#111827 !important;
    }

    .company-meta{
        color:#374151 !important;
        font-size:11px !important;
        line-height:1.45 !important;
    }

    .quotation-block{
        text-align:right !important;
        align-items:flex-end !important;
    }

    .quotation-title{
        color:#111827 !important;
        font-size:21px !important;
    }

    .quotation-ref{
        background:#f3f4f6 !important;
        color:#111827 !important;
        border:1px solid #9ca3af !important;
        border-radius:4px !important;
        font-size:9.5px !important;
        padding:4px 7px !important;
    }

    .section{
        padding:9px 16px 0 !important;
    }

    .top-grid{
        display:grid !important;
        grid-template-columns:1fr 1fr !important;
        gap:8px !important;
    }

    .card{
        padding:9px 10px !important;
        border-radius:7px !important;
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    .card-title{
        font-size:9.5px !important;
        margin-bottom:4px !important;
    }

    .card-text{
        font-size:10.8px !important;
        line-height:1.45 !important;
    }

    .divider{
        margin-top:9px !important;
    }

    .section-title{
        font-size:14px !important;
        margin-bottom:6px !important;
    }

    .table-wrap{
        border-radius:7px !important;
        page-break-inside:auto !important;
        break-inside:auto !important;
    }

    table{
        width:100% !important;
        table-layout:fixed !important;
    }

    thead{
        display:table-header-group !important;
    }

    thead th{
        background:#eeeeee !important;
        color:#111827 !important;
        padding:7px 6px !important;
        font-size:9.5px !important;
    }

    tbody td{
        padding:6px 6px !important;
        font-size:9.8px !important;
        line-height:1.3 !important;
    }

    tr{
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    .bottom-grid{
        display:grid !important;
        grid-template-columns:minmax(0,1fr) 72mm !important;
        gap:8mm !important;
        margin-top:9px !important;
        align-items:start !important;
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    .notes-box{
        min-height:110px !important;
        padding:10px !important;
        border-radius:7px !important;
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    .notes-title{
        font-size:12px !important;
        margin-bottom:5px !important;
    }

    .notes-text{
        font-size:9.5px !important;
        line-height:1.5 !important;
    }

    .summary-card{
        border-radius:7px !important;
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    .summary-head{
        padding:8px 10px 7px !important;
    }

    .summary-title{
        font-size:13.5px !important;
    }

    .summary-row{
        padding:7px 10px !important;
        font-size:10px !important;
    }

    .summary-row strong{
        font-size:10.5px !important;
    }

    .summary-total{
        background:#eeeeee !important;
        color:#111827 !important;
        padding:8px 10px !important;
    }

    .summary-total strong{
        font-size:17px !important;
    }

    .spacer{
        flex:1 1 auto !important;
        min-height:8px !important;
    }

    .footer{
        margin-top:auto !important;
        padding:8px 16px 9px !important;
        background:#fafafa !important;
        border-top:1px solid #cfd6df !important;
        display:grid !important;
        grid-template-columns:1fr 1fr !important;
        gap:12px !important;
        font-size:8.8px !important;
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    .footer-left{
        text-align:left !important;
    }

    .footer-right{
        text-align:right !important;
    }
}

/* Mobile screen only */
@media screen and (max-width:768px){
    .print-bar,
    .screen-topbar,
    .page{
        width:100% !important;
        max-width:100% !important;
    }

    .page{
        margin:0 !important;
        border:none !important;
    }

    .header-top,
    .top-grid,
    .bottom-grid,
    .footer{
        grid-template-columns:1fr !important;
    }

    .quotation-block,
    .footer-right{
        text-align:left !important;
        align-items:flex-start !important;
    }
}
    
    /* =====================================================
   A4 + THERMAL PRINT MODE FINAL
   Paste at VERY END before 
===================================================== */

.print-btn.mode-btn{
    background:#f3f4f6 !important;
    color:#111827 !important;
    border:1px solid #cfd6df !important;
}

.print-btn.mode-btn.active{
    background:#111827 !important;
    color:#fff !important;
}

.print-btn.thermal{
    background:#047857 !important;
    color:#fff !important;
}

/* ================= A4 SCREEN MODE ================= */

body.print-mode-a4 .print-bar,
body.print-mode-a4 .screen-topbar,
body.print-mode-a4 .page{
    width:190mm !important;
    max-width:190mm !important;
}

body.print-mode-a4 .page{
    margin:8px auto 22px !important;
}

/* ================= THERMAL SCREEN MODE ================= */

body.print-mode-thermal{
    background:#f3f4f6 !important;
}

body.print-mode-thermal .print-bar,
body.print-mode-thermal .screen-topbar{
    width:80mm !important;
    max-width:80mm !important;
    flex-wrap:wrap !important;
    justify-content:center !important;
}

body.print-mode-thermal .print-bar{
    gap:6px !important;
}

body.print-mode-thermal .print-btn{
    min-height:32px !important;
    padding:7px 9px !important;
    border-radius:7px !important;
    font-size:11px !important;
}

body.print-mode-thermal .screen-topbar{
    font-size:9px !important;
    margin-top:5px !important;
}

body.print-mode-thermal .page{
    width:80mm !important;
    max-width:80mm !important;
    margin:7px auto 18px !important;
    border:1px solid #d1d5db !important;
    box-shadow:0 8px 20px rgba(0,0,0,.08) !important;
}

body.print-mode-thermal .sheet-body{
    min-height:auto !important;
    display:block !important;
}

body.print-mode-thermal .header{
    padding:9px 7px 8px !important;
    border-bottom:1px dashed #111827 !important;
    text-align:center !important;
}

body.print-mode-thermal .header-top{
    display:block !important;
}

body.print-mode-thermal .company-name{
    font-size:16px !important;
    line-height:1.15 !important;
    margin:0 0 4px !important;
    text-align:center !important;
}

body.print-mode-thermal .company-meta{
    font-size:9.5px !important;
    line-height:1.35 !important;
    text-align:center !important;
}

body.print-mode-thermal .quotation-block{
    margin:8px 0 0 !important;
    text-align:center !important;
    align-items:center !important;
}

body.print-mode-thermal .quotation-title{
    font-size:15px !important;
    text-align:center !important;
    letter-spacing:.4px !important;
}

body.print-mode-thermal .quotation-ref{
    margin-top:5px !important;
    padding:3px 6px !important;
    border-radius:4px !important;
    font-size:9px !important;
}

body.print-mode-thermal .section{
    padding:7px 6px 0 !important;
}

body.print-mode-thermal .top-grid{
    grid-template-columns:1fr !important;
    gap:5px !important;
}

body.print-mode-thermal .card{
    padding:6px 7px !important;
    border-radius:5px !important;
}

body.print-mode-thermal .card-title{
    font-size:8.8px !important;
    margin-bottom:3px !important;
}

body.print-mode-thermal .card-text{
    font-size:9.5px !important;
    line-height:1.35 !important;
}

body.print-mode-thermal .divider{
    margin-top:6px !important;
}

body.print-mode-thermal .section-title{
    font-size:12px !important;
    margin:0 0 5px !important;
    text-align:center !important;
}

body.print-mode-thermal .table-wrap{
    border-radius:5px !important;
}

body.print-mode-thermal table{
    table-layout:fixed !important;
}

body.print-mode-thermal colgroup col:nth-child(1){
    width:7mm !important;
}

body.print-mode-thermal colgroup col:nth-child(2){
    width:auto !important;
}

body.print-mode-thermal colgroup col:nth-child(3){
    width:8mm !important;
}

body.print-mode-thermal colgroup col:nth-child(4){
    width:17mm !important;
}

body.print-mode-thermal colgroup col:nth-child(5){
    width:18mm !important;
}

body.print-mode-thermal thead th{
    padding:5px 3px !important;
    font-size:8.2px !important;
    background:#eeeeee !important;
}

body.print-mode-thermal tbody td{
    padding:5px 3px !important;
    font-size:8.2px !important;
    line-height:1.25 !important;
}

body.print-mode-thermal td:nth-child(2){
    word-break:break-word !important;
    overflow-wrap:anywhere !important;
}

body.print-mode-thermal .bottom-grid{
    grid-template-columns:1fr !important;
    gap:6px !important;
    margin-top:7px !important;
}

body.print-mode-thermal .notes-box{
    padding:7px !important;
    border-radius:5px !important;
    min-height:auto !important;
}

body.print-mode-thermal .notes-title{
    font-size:10.5px !important;
    margin-bottom:4px !important;
}

body.print-mode-thermal .notes-text{
    font-size:8.5px !important;
    line-height:1.4 !important;
}

body.print-mode-thermal .summary-card{
    border-radius:5px !important;
}

body.print-mode-thermal .summary-head{
    padding:6px 7px !important;
}

body.print-mode-thermal .summary-kicker{
    font-size:8px !important;
}

body.print-mode-thermal .summary-title{
    font-size:12px !important;
}

body.print-mode-thermal .summary-row{
    padding:6px 7px !important;
    font-size:9px !important;
}

body.print-mode-thermal .summary-row strong{
    font-size:9.5px !important;
}

body.print-mode-thermal .summary-total{
    padding:7px !important;
    background:#eeeeee !important;
    color:#111827 !important;
}

body.print-mode-thermal .summary-total small,
body.print-mode-thermal .summary-total span,
body.print-mode-thermal .summary-total strong{
    color:#111827 !important;
}

body.print-mode-thermal .summary-total small{
    font-size:7.8px !important;
}

body.print-mode-thermal .summary-total span{
    font-size:8px !important;
}

body.print-mode-thermal .summary-total strong{
    font-size:14px !important;
}

body.print-mode-thermal .footer{
    margin-top:8px !important;
    padding:7px 6px !important;
    grid-template-columns:1fr !important;
    gap:4px !important;
    font-size:8.5px !important;
    text-align:center !important;
}

body.print-mode-thermal .footer-left,
body.print-mode-thermal .footer-right{
    text-align:center !important;
}

/* ================= PRINT A4 FINAL ================= */

@media print{
    body.print-mode-a4 .page{
        width:100% !important;
        max-width:none !important;
        min-height:calc(297mm - 20mm) !important;
        margin:0 !important;
        border:none !important;
        box-shadow:none !important;
    }

    body.print-mode-a4 .sheet-body{
        min-height:calc(297mm - 20mm) !important;
        display:flex !important;
        flex-direction:column !important;
    }
}

/* ================= PRINT THERMAL FINAL ================= */

@media print{
    body.print-mode-thermal{
        background:#fff !important;
        margin:0 !important;
        padding:0 !important;
    }
    
    body.print-mode-thermal .sheet-body,
body.print-mode-thermal .header,
body.print-mode-thermal .section,
body.print-mode-thermal .footer{
    width:100% !important;
    max-width:100% !important;
}

body.print-mode-thermal table{
    width:100% !important;
    max-width:100% !important;
}

body.print-mode-thermal .summary-card,
body.print-mode-thermal .table-wrap{
    width:100% !important;
    max-width:100% !important;
}

    body.print-mode-thermal .print-bar,
    body.print-mode-thermal .screen-topbar{
        display:none !important;
    }

 body.print-mode-thermal .page{
    width:76mm !important;
    min-width:76mm !important;
    max-width:76mm !important;
    min-height:auto !important;
    margin:0 auto !important;
    padding:0 !important;
    border:none !important;
    box-shadow:none !important;
    background:#fff !important;
    overflow:visible !important;
}

    body.print-mode-thermal .sheet-body{
        min-height:auto !important;
        display:block !important;
    }

    body.print-mode-thermal .header{
        padding:6px 0 6px !important;
        border-bottom:1px dashed #111827 !important;
        text-align:center !important;
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    body.print-mode-thermal .header-top{
        display:block !important;
    }

    body.print-mode-thermal .company-name{
        font-size:14px !important;
        margin:0 0 3px !important;
        text-align:center !important;
    }

    body.print-mode-thermal .company-meta{
        font-size:8.5px !important;
        line-height:1.3 !important;
        text-align:center !important;
    }

    body.print-mode-thermal .quotation-block{
        margin-top:6px !important;
        text-align:center !important;
        align-items:center !important;
    }

    body.print-mode-thermal .quotation-title{
        font-size:13px !important;
        text-align:center !important;
    }

    body.print-mode-thermal .quotation-ref{
        font-size:8px !important;
        padding:2px 5px !important;
        border:1px solid #9ca3af !important;
        background:#f3f4f6 !important;
        color:#111827 !important;
    }

    body.print-mode-thermal .section{
        padding:5px 0 0 !important;
    }

    body.print-mode-thermal .top-grid{
        grid-template-columns:1fr !important;
        gap:4px !important;
    }

    body.print-mode-thermal .card{
        padding:5px 0 !important;
        border:none !important;
        border-bottom:1px dashed #d1d5db !important;
        border-radius:0 !important;
    }

    body.print-mode-thermal .card-title{
        font-size:8px !important;
        margin-bottom:2px !important;
    }

    body.print-mode-thermal .card-text{
        font-size:8.5px !important;
        line-height:1.3 !important;
    }

    body.print-mode-thermal .divider{
        display:none !important;
    }

    body.print-mode-thermal .section-title{
        font-size:10.5px !important;
        margin:5px 0 4px !important;
        text-align:center !important;
    }

    body.print-mode-thermal .table-wrap{
        border:none !important;
        border-top:1px dashed #111827 !important;
        border-bottom:1px dashed #111827 !important;
        border-radius:0 !important;
    }

    body.print-mode-thermal table{
        width:100% !important;
        table-layout:fixed !important;
        border-collapse:collapse !important;
    }

    body.print-mode-thermal colgroup col:nth-child(1){
        width:6mm !important;
    }

    body.print-mode-thermal colgroup col:nth-child(2){
        width:auto !important;
    }

    body.print-mode-thermal colgroup col:nth-child(3){
        width:7mm !important;
    }

    body.print-mode-thermal colgroup col:nth-child(4){
        width:15mm !important;
    }

    body.print-mode-thermal colgroup col:nth-child(5){
        width:16mm !important;
    }

    body.print-mode-thermal thead th{
        background:#fff !important;
        color:#111827 !important;
        padding:4px 2px !important;
        font-size:7.8px !important;
        border-bottom:1px dashed #111827 !important;
    }

    body.print-mode-thermal tbody td{
        padding:4px 2px !important;
        font-size:7.8px !important;
        line-height:1.22 !important;
        border-bottom:1px dotted #d1d5db !important;
    }

    body.print-mode-thermal tr{
        page-break-inside:avoid !important;
        break-inside:avoid !important;
    }

    body.print-mode-thermal .bottom-grid{
        grid-template-columns:1fr !important;
        gap:5px !important;
        margin-top:5px !important;
    }

    /* Thermal print me notes hide rakhe hain taake receipt clean nikle */
    body.print-mode-thermal .notes-box{
        display:none !important;
    }

    body.print-mode-thermal .summary-card{
        border:none !important;
        border-top:1px dashed #111827 !important;
        border-radius:0 !important;
    }

    body.print-mode-thermal .summary-head{
        display:none !important;
    }

    body.print-mode-thermal .summary-row{
        padding:4px 0 !important;
        font-size:8.5px !important;
        border-bottom:1px dotted #d1d5db !important;
    }

    body.print-mode-thermal .summary-row strong{
        font-size:8.8px !important;
    }

    body.print-mode-thermal .summary-total{
        background:#fff !important;
        color:#111827 !important;
        border-top:1px dashed #111827 !important;
        border-bottom:1px dashed #111827 !important;
        padding:6px 0 !important;
    }

    body.print-mode-thermal .summary-total small{
        font-size:7.5px !important;
    }

    body.print-mode-thermal .summary-total span{
        display:none !important;
    }

    body.print-mode-thermal .summary-total strong{
        font-size:13px !important;
    }

    body.print-mode-thermal .spacer{
        display:none !important;
    }

    body.print-mode-thermal .footer{
        margin-top:6px !important;
        padding:5px 0 0 !important;
        border-top:none !important;
        background:#fff !important;
        display:block !important;
        font-size:7.8px !important;
        text-align:center !important;
    }

    body.print-mode-thermal .footer-left,
    body.print-mode-thermal .footer-right{
        display:block !important;
        width:100% !important;
        text-align:center !important;
        padding:0 !important;
        margin:0 !important;
    }
}
/* =========================================
   QUOTATION WATERMARK - FINAL TUNED
========================================= */

.page{
    position: relative !important;
    overflow: hidden !important;
    isolation: isolate !important;
}

.page > .sheet-body{
    position: relative !important;
    z-index: 2 !important;
    background: transparent !important;
}

.page-watermark{
    position: absolute !important;
    left: 49% !important;
    top: 61% !important;
    transform: translate(-50%, -50%) rotate(-30deg) !important;

    z-index: 9 !important;
    pointer-events: none !important;
    user-select: none !important;
    white-space: nowrap !important;

    font-size: 88px !important;
    font-style: italic !important;
    font-weight: 600 !important;
    letter-spacing: 4px !important;
    text-transform: uppercase !important;
    line-height: 1 !important;

    color: rgba(17, 24, 39, 0.055) !important;
    opacity: 1 !important;
    display: block !important;
}

/* A4 screen */
body.print-mode-a4 .page-watermark{
    left: 49% !important;
    top: 61% !important;
    font-size: 88px !important;
    font-style: italic !important;
    font-weight: 600 !important;
    letter-spacing: 4px !important;
    color: rgba(17, 24, 39, 0.055) !important;
    transform: translate(-50%, -50%) rotate(-30deg) !important;
}

/* Thermal screen */
body.print-mode-thermal .page-watermark{
    left: 50% !important;
    top: 58% !important;
    font-size: 30px !important;
    font-style: italic !important;
    font-weight: 600 !important;
    letter-spacing: 2px !important;
    color: rgba(17, 24, 39, 0.05) !important;
    transform: translate(-50%, -50%) rotate(-28deg) !important;
}

/* Print */
@media print{
    .page{
        position: relative !important;
        overflow: hidden !important;
        isolation: isolate !important;
    }

    .page-watermark{
        display: block !important;
        z-index: 9 !important;
        opacity: 1 !important;
        font-style: italic !important;
        font-weight: 600 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body.print-mode-a4 .page-watermark{
        left: 49% !important;
        top: 61% !important;
        font-size: 84px !important;
        letter-spacing: 4px !important;
        color: rgba(17, 24, 39, 0.06) !important;
        transform: translate(-50%, -50%) rotate(-30deg) !important;
    }

    body.print-mode-thermal .page-watermark{
        left: 50% !important;
        top: 57% !important;
        font-size: 24px !important;
        letter-spacing: 1.5px !important;
        color: rgba(17, 24, 39, 0.045) !important;
        transform: translate(-50%, -50%) rotate(-28deg) !important;
    }
}
</style>
</head>
<body class="print-mode-a4">

<div class="print-bar">
    <button type="button" class="print-btn secondary" onclick="window.close()">Close</button>

    <button type="button" class="print-btn mode-btn active" id="a4ModeBtn" onclick="setPrintMode('a4')">
        A4 View
    </button>

    <button type="button" class="print-btn mode-btn" id="thermalModeBtn" onclick="setPrintMode('thermal')">
        Thermal View
    </button>

    <button type="button" class="print-btn primary" onclick="printQuotation('a4')">
        Print A4
    </button>

    <button type="button" class="print-btn thermal" onclick="printQuotation('thermal')">
        Print Thermal
    </button>
</div>
<div class="screen-topbar">
    <div><?= date('d/m/Y, H:i') ?></div>
    <div>Quotation - <?= h($quotation['quotation_no']) ?></div>
</div>

<div class="page">
    <div class="page-watermark">QUOTATION</div>
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
                    <h2 class="quotation-title">QUOTATION</h2>
                    <div class="quotation-ref">Reference # <?= h($quotation['quotation_no']) ?></div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="top-grid">
                <div class="card">
                    <h3 class="card-title">Quotation For</h3>
                    <div class="card-text">
                        <strong><?= h($quotation['customer_name']) ?></strong><br>
                        Phone: <?= h($quotation['phone']) ?><br>
                        Date: <?= h(date('d M Y', strtotime($quotation['quotation_date']))) ?>
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title">Quotation Details</h3>
                    <div class="card-text">
                        <strong>Quotation #:</strong> <?= h($quotation['quotation_no']) ?><br>
                        <strong>Created:</strong> <?= h(date('d M Y, h:i A', strtotime($quotation['created_at']))) ?><br>
                        <strong>Reference ID:</strong> <?= (int)$quotation['id'] ?>
                    </div>
                </div>
            </div>

            <div class="divider"></div>
        </div>

        <div class="section">
            <h3 class="section-title">Items Summary</h3>

            <?php if (!empty($quotationItems)): ?>
                <div class="table-wrap">
                    <table>
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
                            <?php foreach ($quotationItems as $index => $item): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= h($item['name']) ?></td>
                                    <td><?= h(rtrim(rtrim(number_format((float)$item['qty'], 2, '.', ''), '0'), '.')) ?></td>
                                    <td>Rs. <?= number_format((float)$item['rate'], 2) ?></td>
                                    <td>Rs. <?= number_format((float)$item['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-items">
                    No line items were found for this quotation.
                </div>
            <?php endif; ?>

            <div class="bottom-grid">
                <div class="notes-box">
                    <h4 class="notes-title">Notes & Terms</h4>
                    <p class="notes-text">
                        This quotation is generated digitally and is intended for customer review and approval.
                        Prices, quantities, and terms may be subject to final confirmation before invoice conversion.
                        Thank you for choosing <?= h($companyName) ?>. We appreciate the opportunity to serve you professionally.
                    </p>
                </div>

                <div class="summary-card">
                    <div class="summary-head">
                        <div class="summary-kicker">Quotation Summary</div>
                        <h4 class="summary-title">Amount Details</h4>
                    </div>

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <strong>Rs. <?= number_format($subtotal, 2) ?></strong>
                    </div>

                    <div class="summary-row">
                        <span>Discount</span>
                        <strong>Rs. <?= number_format($discount, 2) ?></strong>
                    </div>

                    <div class="summary-row">
                        <span>Tax</span>
                        <strong>Rs. <?= number_format($tax, 2) ?></strong>
                    </div>

                    <div class="summary-total">
                        <div>
                            <small>Quoted Total</small>
                            <span>Estimated payable amount</span>
                        </div>
                        <strong>Rs. <?= number_format($grandTotal, 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="spacer"></div>

        <div class="footer">
            <div class="footer-left">
                <strong><?= h($companyName) ?></strong><br>
                This quotation was generated electronically and does not require a physical signature.
            </div>

            <div class="footer-right">
                <strong>Prepared On:</strong> <?= h(date('d M Y, h:i A')) ?><br>
                <strong>Status:</strong> <?= h(ucfirst($quotation['status'] ?? 'Draft')) ?>
            </div>
        </div>

    </div>
</div>


<script>
(function () {
    let currentPrintMode = 'a4';

    function updateDynamicPageStyle(mode) {
        let styleTag = document.getElementById('dynamicPrintPageStyle');

        if (!styleTag) {
            styleTag = document.createElement('style');
            styleTag.id = 'dynamicPrintPageStyle';
            document.head.appendChild(styleTag);
        }

     if (mode === 'thermal') {
    styleTag.textContent = `
        @media print {
            @page {
                size: 80mm 220mm;
                margin: 2mm;
            }

            html,
            body {
                width: 80mm !important;
                min-width: 80mm !important;
                max-width: 80mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
        }
    `;
} else {
            styleTag.textContent = `
                @media print {
                    @page {
                        size: A4;
                        margin: 10mm;
                    }
                }
            `;
        }
    }

    window.setPrintMode = function (mode) {
        currentPrintMode = mode === 'thermal' ? 'thermal' : 'a4';

        document.body.classList.remove('print-mode-a4', 'print-mode-thermal');
        document.body.classList.add(currentPrintMode === 'thermal' ? 'print-mode-thermal' : 'print-mode-a4');

        const a4Btn = document.getElementById('a4ModeBtn');
        const thermalBtn = document.getElementById('thermalModeBtn');

        if (a4Btn) a4Btn.classList.toggle('active', currentPrintMode === 'a4');
        if (thermalBtn) thermalBtn.classList.toggle('active', currentPrintMode === 'thermal');

        updateDynamicPageStyle(currentPrintMode);
    };

    window.printQuotation = function (mode) {
        window.setPrintMode(mode);

        setTimeout(function () {
            window.print();
        }, 200);
    };

    window.addEventListener('beforeprint', function () {
        updateDynamicPageStyle(currentPrintMode);
    });

    window.setPrintMode('a4');
})();
</script>

</body>
</html>