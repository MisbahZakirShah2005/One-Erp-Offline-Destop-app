


<?php
session_start();
include('../../config/db.php');

if(isset($_SESSION['user_id'])){

    $user_id = $_SESSION['user_id'];
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
    $device  = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // optional logout log
    $stmt = $conn->prepare("
        INSERT INTO login_logs (user_id, ip, device, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iss", $user_id, $ip, $device);
    $stmt->execute();
}

session_unset();
session_destroy();

header("Location: /public/app/views/login_view.php");
exit;
