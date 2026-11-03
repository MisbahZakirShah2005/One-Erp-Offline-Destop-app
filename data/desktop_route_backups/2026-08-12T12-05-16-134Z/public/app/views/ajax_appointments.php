<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)$_SESSION['client_id'];

// ================= GET SINGLE APPOINTMENT =================
if(isset($_GET['action']) && $_GET['action'] === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE id=? AND client_id=?");
    $stmt->bind_param("ii", $id, $client_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    echo json_encode($data);
    $stmt->close();
    exit;
}

// ================= HANDLE POST REQUESTS =================
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';

    // ================ SAVE / ADD / EDIT =================
    if($action === 'save'){
        $id = (int)($_POST['appointment_id'] ?? 0);
        $customer_id = (int)($_POST['customer_id']);
        $staff_id    = (int)($_POST['staff_id']);
        $service_id  = (int)($_POST['service_id']);
        $appointment_date = $_POST['appointment_date'];
        $appointment_time = $_POST['appointment_time'];
        $status      = $_POST['appointment_status'] ?? 'pending';

        if($id > 0){
            // Update
            $stmt = $conn->prepare("UPDATE appointments SET customer_id=?, staff_id=?, service_id=?, appointment_date=?, appointment_time=?, status=? WHERE id=? AND client_id=?");
            $stmt->bind_param("iiisssii", $customer_id,$staff_id,$service_id,$appointment_date,$appointment_time,$status,$id,$client_id);
        } else {
            // Insert
            $stmt = $conn->prepare("INSERT INTO appointments (client_id, customer_id, staff_id, service_id, appointment_date, appointment_time, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("iiiiiss", $client_id,$customer_id,$staff_id,$service_id,$appointment_date,$appointment_time,$status);
        }

        if($stmt->execute()){
            echo 'ok';
        } else {
            echo 'Error: '.$conn->error;
        }
        $stmt->close();
        exit;
    }

    // ================ DELETE SINGLE =================
    if($action === 'delete'){
        $id = (int)($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM appointments WHERE id=? AND client_id=?");
        $stmt->bind_param("ii", $id, $client_id);
        if($stmt->execute()){
            echo 'ok';
        } else {
            echo 'Error: '.$conn->error;
        }
        $stmt->close();
        exit;
    }

    // ================ BULK DELETE =================
    if($action === 'bulk_delete'){
        $ids = $_POST['selected_ids'] ?? [];
        if(!empty($ids)){
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids)+1);
            $stmt = $conn->prepare("DELETE FROM appointments WHERE id IN ($placeholders) AND client_id=?");
            $stmt->bind_param($types, ...array_merge($ids, [$client_id]));
            if($stmt->execute()){
                echo 'ok';
            } else echo 'Error: '.$conn->error;
            $stmt->close();
        } else echo 'No IDs selected';
        exit;
    }

    // ================ MARK COMPLETE =================
    if($action === 'mark_complete'){
        $id = (int)($_POST['appointment_id']);
        $stmt = $conn->prepare("UPDATE appointments SET status='completed' WHERE id=? AND client_id=?");
        $stmt->bind_param("ii", $id,$client_id);
        if($stmt->execute()) echo 'ok';
        else echo 'Error: '.$conn->error;
        $stmt->close();
        exit;
    }
}
?>