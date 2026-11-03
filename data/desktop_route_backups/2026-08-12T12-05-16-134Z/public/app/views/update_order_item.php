<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Only allow logged-in users
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'expired',
        'message' => 'Login required',
        'redirect' => '/onerp/app/public/app/views/login_view.php'
    ]);
    exit;
}
// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);

if(!$data || !isset($data['item_id'],$data['sale_id'],$data['qty'],$data['price'])){
    echo json_encode(['status'=>'error','message'=>'Invalid request data']);
    exit;
}

$item_id = (int)$data['item_id'];
$sale_id = (int)$data['sale_id'];
$qty = (float)$data['qty'];
$price = (float)$data['price'];

try{
    // Check if the item exists
    $stmt = $conn->prepare("SELECT * FROM sale_items WHERE id=? AND sale_id=?");
    $stmt->bind_param("ii",$item_id,$sale_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows==0){
        echo json_encode(['status'=>'error','message'=>'Sale item not found']);
        exit;
    }

    // Calculate total
    $total = $qty * $price;

    // Update item
    $stmt = $conn->prepare("UPDATE sale_items SET qty=?, price=?, total=? WHERE id=?");
    $stmt->bind_param("dddi",$qty,$price,$total,$item_id);
    $stmt->execute();

    // Return success with new total
    echo json_encode(['status'=>'success','new_total'=>$total]);

} catch(Exception $e){
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
?>