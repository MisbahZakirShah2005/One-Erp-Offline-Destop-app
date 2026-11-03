<?php
session_start();
include('partials/performance.php');

require_once __DIR__ . '/../../../config/config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'expired',
        'message' => 'Login required',
        'redirect' => '/onerp/app/public/app/views/login_view.php'
    ]);
    exit;
}
$client_id = $_SESSION['client_id'];
$logged_user_id = $_SESSION['user_id'];
$platform = $_GET['platform'] ?? '';

if(!in_array($platform, ['foodpanda','uber_eats'])){
    echo json_encode(['status'=>'error','message'=>'Invalid platform']); exit;
}

// Fetch integration details
$stmt = $conn->prepare("SELECT * FROM integrations WHERE client_id=? AND integration_type=? AND status='active' LIMIT 1");
$stmt->bind_param("is",$client_id,$platform);
$stmt->execute();
$res = $stmt->get_result();

if($res->num_rows==0){
    echo json_encode(['status'=>'error','message'=>'No active integration found']); exit;
}

$integration = $res->fetch_assoc();

// API Call
$api_url = ($platform=='foodpanda') 
    ? 'https://api.foodpanda.com/v1/orders?status=new' 
    : 'https://api.ubereats.com/v1/orders?status=new';

$api_token = $integration['api_token'];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $api_token",
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
curl_close($ch);

// Log API
$stmt_log = $conn->prepare("INSERT INTO integration_logs (client_id,integration_type,action,request,response,status,created_at) VALUES (?,?,?,?,?,?,NOW())");

$action = 'fetch_orders';
$request = json_encode(['url'=>$api_url]);
$status = ($http_code==200)?'success':'failed';

$stmt_log->bind_param("isssss",$client_id,$platform,$action,$request,$response,$status);
$stmt_log->execute();

// API fail
if($http_code!=200){
    echo json_encode(['status'=>'error','message'=>'API Error']); exit;
}

$data = json_decode($response,true);

// ================= INSERT ORDERS =================
foreach($data['orders'] as $order){

    $branch_id = $order['branch_id'];
    $total     = $order['total'];
    $discount  = $order['discount'] ?? 0;
    $tax       = $order['tax'] ?? 0;
    $payment_method = $order['payment_method'];

    // 🔥 STEP 1: Insert Sale
    $stmt_sale = $conn->prepare("
        INSERT INTO sales 
        (client_id, branch_id, user_id, total, discount, tax, payment_method, created_at) 
        VALUES (?,?,?,?,?,?,?,NOW())
    ");
    $stmt_sale->bind_param("iiiddss",$client_id,$branch_id,$logged_user_id,$total,$discount,$tax,$payment_method);
    $stmt_sale->execute();

    $sale_id = $stmt_sale->insert_id;

    // 🔥 STEP 2: Generate Invoice
    $invoice = "INV-" . date('y') . "-" . str_pad($sale_id, 5, '0', STR_PAD_LEFT);

    // 🔥 STEP 3: Save Invoice in DB
    $stmt_update = $conn->prepare("UPDATE sales SET invoice_no=? WHERE id=?");
    $stmt_update->bind_param("si", $invoice, $sale_id);
    $stmt_update->execute();

    // 🔥 STEP 4: Insert Items
    foreach($order['items'] as $item){
        $stmt_item = $conn->prepare("
            INSERT INTO sale_items (sale_id,item_id,qty,price,total) 
            VALUES (?,?,?,?,?)
        ");
        $total_item = $item['qty'] * $item['price'];
        $stmt_item->bind_param("iiidd",$sale_id,$item['item_id'],$item['qty'],$item['price'],$total_item);
        $stmt_item->execute();
    }

    // 🔥 STEP 5: Notification (UPDATED)
    $stmt_notif = $conn->prepare("
        INSERT INTO notifications 
        (client_id,user_id,type,title,message,created_at,status) 
        VALUES (?,?,?,?,?,NOW(),'unread')
    ");

    $type = $platform.'_order';
    $title = "New ".ucfirst(str_replace('_',' ',$platform))." Order";
    $message = "New Order: ".$invoice;

    $stmt_notif->bind_param("iisss",$client_id,$logged_user_id,$type,$title,$message);
    $stmt_notif->execute();
}

echo json_encode(['status'=>'success']);
?>