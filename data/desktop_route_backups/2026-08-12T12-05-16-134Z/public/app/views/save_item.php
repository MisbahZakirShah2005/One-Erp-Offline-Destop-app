<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

header('Content-Type: application/json');




try {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'status' => 'expired',
            'message' => 'Login required',
            'redirect' => '/onerp/app/public/app/views/login_view.php'
        ]);
        exit;
    }

    if (empty($_SESSION['client_id']) || empty($_SESSION['branch_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'status' => 'expired',
            'message' => 'Session error (client/branch missing)',
            'redirect' => '/onerp/app/public/app/views/login_view.php'
        ]);
        exit;
    }
    
        $client_id = (int)$_SESSION['client_id'];
    $branch_id = (int)$_SESSION['branch_id'];

    /*
    |-------------------------------------------------------
    | SINGLE DELETE
    |-------------------------------------------------------
    */
    if (isset($_POST['delete_item_id'])) {
        $item_id = (int)$_POST['delete_item_id'];

        if ($item_id <= 0) {
            throw new Exception("Invalid item id");
        }

        // old image remove if exists
        $oldImage = '';
        $stmt = $conn->prepare("SELECT image FROM items WHERE id=? AND client_id=? AND branch_id=? LIMIT 1");
        $stmt->bind_param("iii", $item_id, $client_id, $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $oldImage = $row['image'] ?? '';
        }
        $stmt->close();

        $stmt = $conn->prepare("
            DELETE FROM items
            WHERE id=? AND client_id=? AND branch_id=?
        ");
        $stmt->bind_param("iii", $item_id, $client_id, $branch_id);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        $stmt->close();

        if (!empty($oldImage)) {
            $imagePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/items/' . $oldImage;
            if (file_exists($imagePath)) {
                @unlink($imagePath);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Item deleted successfully'
        ]);
        exit;
    }

    /*
    |-------------------------------------------------------
    | BULK DELETE
    |-------------------------------------------------------
    */
    if (isset($_POST['delete_item_ids'])) {
        $rawIds = json_decode($_POST['delete_item_ids'], true);

        if (!is_array($rawIds) || empty($rawIds)) {
            throw new Exception("No items selected for delete");
        }

        $itemIds = array_map('intval', $rawIds);
        $itemIds = array_filter($itemIds, fn($id) => $id > 0);

        if (empty($itemIds)) {
            throw new Exception("Invalid selected item ids");
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

        // fetch images first
        $types = str_repeat('i', count($itemIds) + 2);
        $params = array_merge($itemIds, [$client_id, $branch_id]);

        $stmt = $conn->prepare("
            SELECT image
            FROM items
            WHERE id IN ($placeholders) AND client_id=? AND branch_id=?
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $images = [];
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['image'])) {
                $images[] = $row['image'];
            }
        }
        $stmt->close();

        $stmt = $conn->prepare("
            DELETE FROM items
            WHERE id IN ($placeholders) AND client_id=? AND branch_id=?
        ");
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $deletedRows = $stmt->affected_rows;
        $stmt->close();

        foreach ($images as $img) {
            $imagePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/items/' . $img;
            if (file_exists($imagePath)) {
                @unlink($imagePath);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $deletedRows . ' item(s) deleted successfully'
        ]);
        exit;
    }

    /*
    |-------------------------------------------------------
    | FORM DATA
    |-------------------------------------------------------
    */
    $item_id = (int)($_POST['item_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $type = trim($_POST['type'] ?? 'product');
    $track_inventory = (int)($_POST['track_inventory'] ?? 1);
    $stock_qty = (float)($_POST['stock_qty'] ?? 0);
    $is_raw = (int)($_POST['is_raw'] ?? 0);
    $has_variants = isset($_POST['has_variants']) ? 1 : 0;
    $has_expiry = isset($_POST['has_expiry']) ? 1 : 0;

    if ($name === '') {
        throw new Exception("Product name required");
    }

    /*
    |-------------------------------------------------------
    | IMAGE UPLOAD
    |-------------------------------------------------------
    */
    $imageName = '';

    if (!empty($_FILES['image']['name'])) {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/items/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (!in_array($ext, $allowed, true)) {
            throw new Exception("Invalid image format");
        }

        $imageName = 'item_' . time() . '_' . rand(1000, 9999) . '.' . $ext;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName)) {
            throw new Exception("Image upload failed");
        }
    }

    /*
    |-------------------------------------------------------
    | UPDATE ITEM
    |-------------------------------------------------------
    */
    if ($item_id > 0) {
        $oldImage = '';

        if ($imageName !== '') {
            $stmt = $conn->prepare("SELECT image FROM items WHERE id=? AND client_id=? AND branch_id=? LIMIT 1");
            $stmt->bind_param("iii", $item_id, $client_id, $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $oldImage = $row['image'] ?? '';
            }
            $stmt->close();

            $stmt = $conn->prepare("
                UPDATE items SET
                    name=?, sku=?, price=?, type=?, track_inventory=?, stock_qty=?,
                    is_raw_item=?, has_variants=?, has_expiry=?, image=?
                WHERE id=? AND client_id=? AND branch_id=?
            ");

            $stmt->bind_param(
                "ssdsidiiiisii",
                $name,
                $sku,
                $price,
                $type,
                $track_inventory,
                $stock_qty,
                $is_raw,
                $has_variants,
                $has_expiry,
                $imageName,
                $item_id,
                $client_id,
                $branch_id
            );
        } else {
            $stmt = $conn->prepare("
                UPDATE items SET
                    name=?, sku=?, price=?, type=?, track_inventory=?, stock_qty=?,
                    is_raw_item=?, has_variants=?, has_expiry=?
                WHERE id=? AND client_id=? AND branch_id=?
            ");

            $stmt->bind_param(
                "ssdsidiiiiii",
                $name,
                $sku,
                $price,
                $type,
                $track_inventory,
                $stock_qty,
                $is_raw,
                $has_variants,
                $has_expiry,
                $item_id,
                $client_id,
                $branch_id
            );
        }

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        $stmt->close();

        if ($imageName !== '' && !empty($oldImage)) {
            $oldImagePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/items/' . $oldImage;
            if (file_exists($oldImagePath)) {
                @unlink($oldImagePath);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Item updated'
        ]);
        exit;
    }

    /*
    |-------------------------------------------------------
    | INSERT ITEM
    |-------------------------------------------------------
    */
    $stmt = $conn->prepare("
        INSERT INTO items
        (client_id, branch_id, name, sku, price, type, track_inventory, stock_qty, is_raw_item, has_variants, has_expiry, image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "iissdsidiiis",
        $client_id,
        $branch_id,
        $name,
        $sku,
        $price,
        $type,
        $track_inventory,
        $stock_qty,
        $is_raw,
        $has_variants,
        $has_expiry,
        $imageName
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Item added'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}