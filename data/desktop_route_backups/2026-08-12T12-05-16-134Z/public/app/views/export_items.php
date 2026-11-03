<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$client_id = $_SESSION['client_id'];
$branch_id = $_SESSION['branch_id'] ?? 0;

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="items_export.csv"');

$output = fopen('php://output','w');
fputcsv($output, ['ID','Name','Price','SKU','Type','Has Variants','Has Expiry']);

$stmt = $conn->prepare("SELECT id,name,price,sku,type,has_variants,has_expiry FROM items WHERE client_id=? AND branch_id=? ORDER BY id DESC");
$stmt->bind_param("ii",$client_id,$branch_id);
$stmt->execute();
$result = $stmt->get_result();

while($row=$result->fetch_assoc()){
    fputcsv($output, [$row['id'],$row['name'],$row['price'],$row['sku'],$row['type'],$row['has_variants'],$row['has_expiry']]);
}

fclose($output);
exit;
?>