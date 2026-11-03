<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$token = trim($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($token === '') {
    $_SESSION['login_error'] = "Invalid password reset request.";
    header("Location: /public/app/views/login_view.php");
    exit;
}

if (strlen($password) < 6) {
    $_SESSION['reset_error'] = "Password must be at least 6 characters.";
    header("Location: /public/app/views/reset_password_view.php?token=" . urlencode($token));
    exit;
}

if ($password !== $confirmPassword) {
    $_SESSION['reset_error'] = "Password and confirm password do not match.";
    header("Location: /public/app/views/reset_password_view.php?token=" . urlencode($token));
    exit;
}

$stmt = $conn->prepare("
    SELECT id, email 
    FROM users 
    WHERE reset_token = ? 
    AND reset_token_expires >= NOW()
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['login_error'] = "Invalid or expired password reset link.";
    header("Location: /public/app/views/login_view.php");
    exit;
}

$user = $result->fetch_assoc();

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$update = $conn->prepare("
    UPDATE users 
    SET password = ?, reset_token = NULL, reset_token_expires = NULL 
    WHERE id = ?
");
$update->bind_param("si", $hashedPassword, $user['id']);

if ($update->execute()) {
    $_SESSION['register_success'] = "Password updated successfully. Please login.";
    header("Location: /public/app/views/login_view.php");
    exit;
} else {
    $_SESSION['reset_error'] = "Password could not be updated. Please try again.";
    header("Location: /public/app/views/reset_password_view.php?token=" . urlencode($token));
    exit;
}