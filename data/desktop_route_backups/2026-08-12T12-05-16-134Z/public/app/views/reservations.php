<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = $_SESSION['client_id'];
$branch_id = $_SESSION['branch_id'] ?? 0;

/* ================= POST ================= */
if($_SERVER['REQUEST_METHOD']==='POST'){

    $branch   = $_POST['branch_id'] ?? $branch_id;
    $table_id = $_POST['table_id'] ?? 0;
    $customer_name  = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $reserve_date   = $_POST['reservation_date'];
    $reserve_time   = $_POST['reservation_time'];
    $status         = $_POST['status'] ?? 'Pending'; // default Pending

    // ADD
    if(isset($_POST['add_reservation'])){
        if(empty($table_id) || empty($customer_name) || empty($reserve_date) || empty($reserve_time)){
            $_SESSION['error'] = "All fields are required!";
            header("Location: reservations.php"); exit;
        }

        // Check table availability only for Pending/Confirm (optional)
        $check = $conn->prepare("SELECT * FROM table_reservations WHERE table_id=? AND reservation_date=? AND reservation_time=? AND status IN ('Pending','Confirm')");
        if(!$check) die("Prepare failed: ".$conn->error);
        $check->bind_param("iss",$table_id,$reserve_date,$reserve_time);
        $check->execute();
        $res = $check->get_result();
        if($res->num_rows>0){
            $_SESSION['error'] = "This table is already reserved at that time!";
            header("Location: reservations.php"); exit;
        }
        $check->close();

        $stmt = $conn->prepare("INSERT INTO table_reservations (client_id,branch_id,table_id,customer_name,customer_phone,reservation_date,reservation_time,status,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        if(!$stmt) die("Prepare failed: ".$conn->error);

        $stmt->bind_param("iiisssss",$client_id,$branch,$table_id,$customer_name,$customer_phone,$reserve_date,$reserve_time,$status);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success']="Reservation added!";
        header("Location: reservations.php"); exit;
    }

    // EDIT
    if(isset($_POST['edit_reservation'])){
        $id = $_POST['id'];
        $stmt = $conn->prepare("UPDATE table_reservations SET branch_id=?, table_id=?, customer_name=?, customer_phone=?, reservation_date=?, reservation_time=?, status=? WHERE id=? AND client_id=?");
        if(!$stmt) die("Prepare failed: ".$conn->error);
        $stmt->bind_param("iisssssii",$branch,$table_id,$customer_name,$customer_phone,$reserve_date,$reserve_time,$status,$id,$client_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success']="Reservation updated!";
        header("Location: reservations.php"); exit;
    }

    // CANCEL
    if(isset($_POST['cancel_reservation'])){
        $id = $_POST['id'];
        $stmt = $conn->prepare("UPDATE table_reservations SET status='Cancel' WHERE id=? AND client_id=?");
        $stmt->bind_param("ii",$id,$client_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success']="Reservation cancelled!";
        header("Location: reservations.php"); exit;
    }
}

/* ================= FETCH ================= */
$stmt = $conn->prepare("SELECT tr.*, t.table_no, b.name AS branch_name FROM table_reservations tr 
LEFT JOIN restaurant_tables t ON tr.table_id=t.id 
LEFT JOIN branches b ON tr.branch_id=b.id
WHERE tr.client_id=? ORDER BY tr.reservation_date ASC, tr.reservation_time ASC");
$stmt->bind_param("i",$client_id);
$stmt->execute();
$reservations = $stmt->get_result();

/* ================= TABLES ================= */
$tstmt = $conn->prepare("SELECT * FROM restaurant_tables WHERE client_id=? ORDER BY table_no ASC");
$tstmt->bind_param("i",$client_id);
$tstmt->execute();
$tables = $tstmt->get_result();

/* ================= BRANCHES ================= */
$bstmt = $conn->prepare("SELECT * FROM branches WHERE client_id=? ORDER BY name ASC");
$bstmt->bind_param("i",$client_id);
$bstmt->execute();
$branches = $bstmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Table Reservations</title>
    <?php include('partials/head.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    body {
        margin: 0;
    }

    .container-scroll {
        display: flex;
        min-height: 100vh;
    }

    .sidebar {
        width: 220px;
    }

    .main-panel {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .topbar {
        height: 60px;
        border-bottom: 1px solid #dee2e6;
        padding: 0 20px;
        background: #fff;
    }

    .content-wrapper {
        padding: 20px;
        flex-grow: 1;
    }
    </style>
</head>

<body>
    <div class="container-scroll">
        <?php include('partials/sidebar.php'); ?>

        <div class="main-panel">
            <div class="topbar"><?php include('partials/topbar.php'); ?></div>
            <div class="content-wrapper">

                <h4 class="mb-3">Table Reservations</h4>
                <div class="mb-3 text-end">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reservationModal"
                        id="addBtn">+ Add Reservation</button>
                </div>

                <?php
        if(isset($_SESSION['success'])){ echo '<div class="alert alert-success">'.$_SESSION['success'].'</div>'; unset($_SESSION['success']); }
        if(isset($_SESSION['error'])){ echo '<div class="alert alert-danger">'.$_SESSION['error'].'</div>'; unset($_SESSION['error']); }
        ?>

                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Table</th>
                            <th>Branch</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row=$reservations->fetch_assoc()): ?>
                        <tr>
                            <td><?=$row['id']?></td>
                            <td><?=$row['customer_name']?></td>
                            <td><?=$row['customer_phone']?></td>
                            <td><?=$row['table_no']?></td>
                            <td><?=$row['branch_name']?></td>
                            <td><?=$row['reservation_date']?></td>
                            <td><?=$row['reservation_time']?></td>
                            <td><?=$row['status']?></td>
                            <td>
                                <button class="btn btn-sm btn-warning editBtn" data-id="<?=$row['id']?>"
                                    data-branch_id="<?=$row['branch_id']?>" data-table_id="<?=$row['table_id']?>"
                                    data-customer_name="<?=$row['customer_name']?>"
                                    data-customer_phone="<?=$row['customer_phone']?>"
                                    data-reservation_date="<?=$row['reservation_date']?>"
                                    data-reservation_time="<?=$row['reservation_time']?>"
                                    data-status="<?=$row['status']?>">Edit</button>

                                <form method="post" style="display:inline">
                                    <input type="hidden" name="id" value="<?=$row['id']?>">
                                    <button type="submit" name="cancel_reservation"
                                        class="btn btn-sm btn-danger">Cancel</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

            </div>
        </div>
    </div>

    <!-- MODAL -->
    <div class="modal fade" id="reservationModal">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="add_reservation" id="addInput" value="1">
                <input type="hidden" name="edit_reservation" id="editInput" value="">
                <input type="hidden" name="id" id="reservationId">

                <div class="modal-header">
                    <h5 id="modalTitle">Add Reservation</h5>
                </div>
                <div class="modal-body">

                    <select name="branch_id" class="form-control mb-2" id="branchSelect" required>
                        <?php
            $branches->data_seek(0);
            while($b=$branches->fetch_assoc()){
                echo "<option value='{$b['id']}'>{$b['name']}</option>";
            }
            ?>
                    </select>

                    <select name="table_id" class="form-control mb-2" id="tableSelect" required>
                        <?php
            $tables->data_seek(0);
            while($t=$tables->fetch_assoc()){
                echo "<option value='{$t['id']}'>{$t['table_no']}</option>";
            }
            ?>
                    </select>

                    <input type="text" name="customer_name" class="form-control mb-2" id="customerName"
                        placeholder="Customer Name" required>
                    <input type="text" name="customer_phone" class="form-control mb-2" id="customerPhone"
                        placeholder="Customer Phone" required>
                    <input type="date" name="reservation_date" class="form-control mb-2" id="reservationDate" required>
                    <input type="time" name="reservation_time" class="form-control mb-2" id="reservationTime" required>

                    <select name="status" class="form-control mb-2" id="statusSelect" required>
                        <option value="Pending">Pending</option>
                        <option value="Confirm">Confirm</option>
                        <option value="Cancel">Cancel</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="modalBtn">Add</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.getElementById('addBtn').addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = 'Add Reservation';
        document.getElementById('modalBtn').innerText = 'Add';
        document.getElementById('addInput').value = 1;
        document.getElementById('editInput').value = '';
        document.getElementById('reservationId').value = '';
        document.getElementById('customerName').value = '';
        document.getElementById('customerPhone').value = '';
        document.getElementById('reservationDate').value = '';
        document.getElementById('reservationTime').value = '';
        document.getElementById('statusSelect').value = 'Pending';
    });

    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').innerText = 'Edit Reservation';
            document.getElementById('modalBtn').innerText = 'Save';
            document.getElementById('reservationId').value = this.dataset.id;
            document.getElementById('branchSelect').value = this.dataset.branch_id;
            document.getElementById('tableSelect').value = this.dataset.table_id;
            document.getElementById('customerName').value = this.dataset.customer_name;
            document.getElementById('customerPhone').value = this.dataset.customer_phone;
            document.getElementById('reservationDate').value = this.dataset.reservation_date;
            document.getElementById('reservationTime').value = this.dataset.reservation_time;
            document.getElementById('statusSelect').value = this.dataset.status;
            document.getElementById('addInput').value = '';
            document.getElementById('editInput').value = 1;
            new bootstrap.Modal(document.getElementById('reservationModal')).show();
        });
    });
    </script>
</body>

</html>