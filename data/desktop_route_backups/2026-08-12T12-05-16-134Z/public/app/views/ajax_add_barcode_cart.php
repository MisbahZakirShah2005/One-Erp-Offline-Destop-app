<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

// if(!isset($_SESSION['user_id'])) die("Login required");

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = $_SESSION['client_id'];
$branch_id = $_SESSION['branch_id'] ?? 0;
$cart =& $_SESSION['cart'];
if(!isset($cart)) $cart = [];

$action = $_POST['action'] ?? '';

if($action=='add'){
    $item_id = (int)($_POST['item_id'] ?? 0);
    $barcode = $_POST['barcode'] ?? '';

    // Fetch item
    if($barcode){
        $stmt = $conn->prepare("SELECT id,name,price,image FROM items WHERE barcode=? AND client_id=?");
        $stmt->bind_param("si",$barcode,$client_id);
    }else{
        $stmt = $conn->prepare("SELECT id,name,price,image FROM items WHERE id=? AND client_id=?");
        $stmt->bind_param("ii",$item_id,$client_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if($res->num_rows==0) { echo "<p class='text-danger'>Item not found</p>"; exit; }
    $item = $res->fetch_assoc();
    $stmt->close();

    $id = $item['id'];
    if(isset($cart[$id])) $cart[$id]['qty'] +=1;
    else $cart[$id] = ['name'=>$item['name'],'price'=>$item['price'],'image'=>$item['image'],'qty'=>1];

    echo renderCart($cart);
    exit;
}

// Update discount & tax
if($action=='update_discount_tax'){
    $_SESSION['order_discount'] = (float)($_POST['discount'] ?? 0);
    $_SESSION['order_tax'] = (float)($_POST['tax'] ?? 0);
    exit;
}

// Remove item
if($action=='remove'){
    $item_id = (int)($_POST['item_id'] ?? 0);
    if(isset($cart[$item_id])) unset($cart[$item_id]);
    echo renderCart($cart);
    exit;
}

// Checkout
if($action=='checkout'){
    if(empty($cart)){ echo "Cart empty!"; exit; }

    $user_id = $_SESSION['user_id'];
    $discount = $_SESSION['order_discount'] ?? 0;
    $tax = $_SESSION['order_tax'] ?? 0;

    $total = 0;
    foreach($cart as $c) $total += $c['price']*$c['qty'];

    $total_after_discount = $total - $discount;
    $total_after_tax = $total_after_discount + $tax;

    // Insert into sales
    $stmt = $conn->prepare("INSERT INTO sales (client_id, branch_id, user_id, total, discount, tax, created_at) VALUES (?,?,?,?,?,?,NOW())");
    $stmt->bind_param("iiiddd",$client_id,$branch_id,$user_id,$total_after_tax,$discount,$tax);
    $stmt->execute();
    $sale_id = $stmt->insert_id;
    $stmt->close();

    // Insert items
    foreach($cart as $item_id=>$c){
        $item_total = $c['price']*$c['qty'];
        $stmt = $conn->prepare("INSERT INTO sales_items (sale_id,item_id,qty,price,total) VALUES (?,?,?,?,?)");
        $stmt->bind_param("iiidd",$sale_id,$item_id,$c['qty'],$c['price'],$item_total);
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION['cart'] = [];
    $_SESSION['order_discount'] = 0;
    $_SESSION['order_tax'] = 0;

    echo "Order placed successfully!";
    exit;
}

function renderCart($cart){
    if(empty($cart)) return "<p>No items</p>";
    $html = '';
    foreach($cart as $id=>$c){
        $html .= '<div class="cart-item d-flex justify-content-between mb-2">';
        $html .= '<div class="d-flex">';
        if(!empty($c['image']) && file_exists($_SERVER['DOCUMENT_ROOT'].'/uploads/items/'.$c['image'])){
            $html .= '<img src="/uploads/items/'.$c['image'].'" style="width:40px;height:40px;object-fit:cover;margin-right:5px;">';
        }
        $html .= '<div>'.htmlspecialchars($c['name']).'<br>Rs '.number_format($c['price'],2).' x '.$c['qty'].'</div></div>';
        $html .= '<button class="btn btn-sm btn-danger" onclick="removeFromCart('.$id.')">X</button>';
        $html .= '</div>';
    }
    $html .= '<button class="btn btn-primary w-100 mt-2" onclick="checkout()">Checkout</button>';
    return $html;
}

die("Invalid action");
