<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ================= AUTH =================
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

// ================= GET INVOICE ID =================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0) die("Invalid invoice ID");

// ================= FETCH INVOICE =================
$invoiceQ = $conn->query("
    SELECT 
        i.*,
        c.business_name,
        p.name AS plan_name
    FROM invoices i
    JOIN clients c ON c.id = i.client_id
    JOIN subscriptions s ON s.id = i.subscription_id
    JOIN plans p ON p.id = s.plan_id
    WHERE i.id = $id
");

if(!$invoiceQ){
    die("SQL Error: ".$conn->error);
}

$invoice = $invoiceQ->fetch_assoc();

if(!$invoice){
    die("Invoice not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice #<?= $invoice['id'] ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {padding:20px; background:#f8f9fa;}
.invoice-box {max-width:800px; margin:auto; padding:30px; border:1px solid #eee; background:#fff; box-shadow:0 0 10px rgba(0,0,0,.15);}
.invoice-header {margin-bottom:20px;}
.invoice-header h2 {margin:0;}
.invoice-details {margin-bottom:20px;}
.invoice-footer {text-align:center; margin-top:30px; font-size:12px; color:#777;}
</style>
</head>
<body>

<div class="invoice-box">
    <div class="invoice-header d-flex justify-content-between">
        <h2>Invoice #<?= $invoice['id'] ?></h2>
        <span><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></span>
    </div>

    <div class="invoice-details row mb-4">
        <div class="col-md-6">
            <h5>Client:</h5>
            <p><?= htmlspecialchars($invoice['business_name']) ?></p>
        </div>
        <div class="col-md-6">
            <h5>Plan:</h5>
            <p><?= htmlspecialchars($invoice['plan_name']) ?></p>
        </div>
    </div>

    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>Amount</th>
                <th>Due Date</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>$<?= number_format($invoice['amount'],2) ?></td>
                <td><?= $invoice['due_date'] ?></td>
                <td>
                    <span class="badge bg-<?= $invoice['status']=='paid'?'success':($invoice['status']=='unpaid'?'secondary':'danger') ?>">
                        <?= ucfirst($invoice['status']) ?>
                    </span>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="text-end mt-3">
        <button class="btn btn-primary" onclick="window.print()">Print Invoice</button>
    </div>

    <div class="invoice-footer">
        &copy; <?= date('Y') ?> Your Company. All rights reserved.
    </div>
</div>

</body>
</html>
