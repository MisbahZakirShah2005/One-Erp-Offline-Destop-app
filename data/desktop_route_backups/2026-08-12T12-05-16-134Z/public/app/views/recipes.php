<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = $_SESSION['client_id'];

/* ================= POST ================= */
if($_SERVER['REQUEST_METHOD']==='POST'){

    $product_item_id = $_POST['product_item_id'] ?? null;
    $raw_item_id     = $_POST['raw_item_id'] ?? null;
    $qty_required    = $_POST['qty_required'] ?? null;

    // ADD / UPDATE
    if(isset($_POST['save_recipe'])){

        if(empty($product_item_id) || empty($raw_item_id) || empty($qty_required)){
            $_SESSION['error']="All fields are required!";
            header("Location: recipes.php"); exit;
        }

        $check = $conn->prepare("
            SELECT id FROM item_recipes 
            WHERE product_item_id=? AND raw_item_id=?
        ");
        $check->bind_param("ii",$product_item_id,$raw_item_id);
        $check->execute();
        $res = $check->get_result();

        if($res->num_rows > 0){
            $stmt = $conn->prepare("
                UPDATE item_recipes 
                SET qty_required=? 
                WHERE product_item_id=? AND raw_item_id=?
            ");
            $stmt->bind_param("dii",$qty_required,$product_item_id,$raw_item_id);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO item_recipes 
                (product_item_id, raw_item_id, qty_required) 
                VALUES (?,?,?)
            ");
            $stmt->bind_param("iid",$product_item_id,$raw_item_id,$qty_required);
        }

        if($stmt->execute()){
            $_SESSION['success']="Recipe saved successfully!";
        } else {
            $_SESSION['error']="Database error: ".$stmt->error;
        }

        $stmt->close();
        header("Location: recipes.php"); exit;
    }

    // DELETE
    if(isset($_POST['delete_recipe'])){
        $id=$_POST['id'];
        $stmt=$conn->prepare("DELETE FROM item_recipes WHERE id=?");
        $stmt->bind_param("i",$id);
        if($stmt->execute()){
            $_SESSION['success']="Recipe deleted!";
        } else {
            $_SESSION['error']="Database error: ".$stmt->error;
        }
        $stmt->close();
        header("Location: recipes.php"); exit;
    }
}

/* ================= FETCH ================= */
$recipes = $conn->query("
    SELECT ir.*, 
           p.name AS product_name,
           r.name AS raw_name
    FROM item_recipes ir
    JOIN items p ON ir.product_item_id=p.id
    JOIN items r ON ir.raw_item_id=r.id
    WHERE p.client_id=$client_id
    ORDER BY p.name ASC
");
if(!$recipes){
    die("Recipes Query Error: ".$conn->error);
}

/* ================= PRODUCTS ================= */
$products = $conn->query("
    SELECT id,name FROM items 
    WHERE client_id=$client_id AND is_raw_item=0
    ORDER BY name ASC
");
if(!$products) die("Products Query Error: ".$conn->error);
$products_data = $products->fetch_all(MYSQLI_ASSOC);

/* ================= RAW ITEMS ================= */
$raw_items = $conn->query("
    SELECT id,name FROM items 
    WHERE client_id=$client_id AND is_raw_item=1
    ORDER BY name ASC
");
if(!$raw_items) die("Raw Items Query Error: ".$conn->error);
$raw_items_data = $raw_items->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Recipe Management</title>
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

                <h4 class="mb-3">Recipe Management</h4>

                <div class="mb-3 text-end">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recipeModal">
                        + Add Recipe
                    </button>
                </div>

                <?php
        if(isset($_SESSION['success'])){
            echo '<div class="alert alert-success">'.$_SESSION['success'].'</div>';
            unset($_SESSION['success']);
        }
        if(isset($_SESSION['error'])){
            echo '<div class="alert alert-danger">'.$_SESSION['error'].'</div>';
            unset($_SESSION['error']);
        }
        ?>

                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Raw Item</th>
                            <th>Qty Required</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row=$recipes->fetch_assoc()): ?>
                        <tr>
                            <td><?=$row['id']?></td>
                            <td><?=$row['product_name']?></td>
                            <td><?=$row['raw_name']?></td>
                            <td><?=$row['qty_required']?></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="id" value="<?=$row['id']?>">
                                    <button class="btn btn-sm btn-danger" name="delete_recipe">Delete</button>
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
    <div class="modal fade" id="recipeModal">
        <div class="modal-dialog">
            <form method="post" class="modal-content">

                <div class="modal-header">
                    <h5>Add Recipe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <select name="product_item_id" class="form-control mb-2" required>
                        <option value="">Select Product</option>
                        <?php foreach($products_data as $p): ?>
                        <option value="<?=$p['id']?>"><?=$p['name']?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="raw_item_id" class="form-control mb-2" required>
                        <option value="">Select Raw Item</option>
                        <?php foreach($raw_items_data as $r): ?>
                        <option value="<?=$r['id']?>"><?=$r['name']?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="number" step="0.001" name="qty_required" class="form-control"
                        placeholder="Qty Required" required>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_recipe" class="btn btn-primary">Save</button>
                </div>

            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>