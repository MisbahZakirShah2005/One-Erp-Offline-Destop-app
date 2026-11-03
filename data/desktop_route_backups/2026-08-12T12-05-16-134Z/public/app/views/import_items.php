<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$client_id = $_SESSION['client_id'];
$branch_id = $_SESSION['branch_id'] ?? 0;

// ================= IMPORT CSV =================
if($_SERVER['REQUEST_METHOD']=='POST'){
    if(!isset($_FILES['import_file']) || $_FILES['import_file']['error']!=0){
        die("File not uploaded or error");
    }

    $file = $_FILES['import_file']['tmp_name'];
    $handle = fopen($file,'r');
    if(!$handle) die("Cannot open file");

    // Expected CSV header: Name,Price,SKU,Type,Has Variants (1/0),Has Expiry (1/0)
    $header = fgetcsv($handle); // skip header
    $added = 0;

    while($row=fgetcsv($handle)){
        $name = trim($row[0] ?? '');
        $price = floatval($row[1] ?? 0);
        $sku = trim($row[2] ?? '');
        $type = trim($row[3] ?? 'product');
        $has_variants = isset($row[4]) ? intval($row[4]) : 0;
        $has_expiry = isset($row[5]) ? intval($row[5]) : 0;

        if(!$name) continue; // skip empty rows

        $stmt = $conn->prepare("
            INSERT INTO items 
            (client_id, branch_id, name, price, sku, type, has_variants, has_expiry, is_raw_item) 
            VALUES (?,?,?,?,?,?,?,?,0)
        ");
        $stmt->bind_param("iisdssii", $client_id, $branch_id, $name, $price, $sku, $type, $has_variants, $has_expiry);
        $stmt->execute();
        $stmt->close();
        $added++;
    }

    fclose($handle);
    echo "$added items imported successfully.";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Items - SaaS POS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">

<div class="container">
    <h3>Import Items from CSV</h3>
    <p>CSV format: <strong>Name, Price, SKU, Type, Has Variants (1/0), Has Expiry (1/0)</strong></p>
    <form method="POST" enctype="multipart/form-data">
        <div class="mb-3">
            <input type="file" name="import_file" class="form-control" accept=".csv" required>
        </div>
        <button class="btn btn-success">Import Items</button>
        <a href="items.php" class="btn btn-secondary">Back to Items</a>
    </form>
</div>

</body>
</html>