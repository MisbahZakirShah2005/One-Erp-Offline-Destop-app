<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$logged_user_id   = $_SESSION['user_id'];
$logged_role_id   = $_SESSION['role_id'] ?? 0;
$logged_client_id = (int)($_SESSION['client_id'] ?? 0);
$logged_branch_id = (int)($_SESSION['branch_id'] ?? 0);

if ($logged_client_id <= 0) {
    die("Invalid client session.");
}

function h($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function generateQuotationNo($conn, $clientId)
{
    $prefix = "QO-";
    $nextNumber = 1001;

    $sql = "SELECT quotation_no
            FROM quotations
            WHERE client_id = ?
            ORDER BY id DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $clientId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            if (!empty($row['quotation_no']) && preg_match('/(\d+)$/', $row['quotation_no'], $m)) {
                $nextNumber = ((int)$m[1]) + 1;
            }
        }

        $stmt->close();
    }

    return $prefix . $nextNumber;
}

$alertMessage = '';
$alertType = 'success';

/* ================= SUCCESS / ERROR ================= */
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created') {
        $alertMessage = "Quotation created successfully.";
        $alertType = "success";
    } elseif ($_GET['success'] === 'converted') {
        $alertMessage = "Selected quotations converted successfully.";
        $alertType = "success";
    } elseif ($_GET['success'] === 'deleted') {
        $alertMessage = "Selected quotations deleted successfully.";
        $alertType = "success";
    }
    elseif ($_GET['success'] === 'updated') {
    $alertMessage = "Quotation updated successfully.";
    $alertType = "success";
}
}

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'client_missing') {
        $alertMessage = "Client ID session me missing hai.";
        $alertType = "danger";
    } elseif ($_GET['error'] === 'customer_required') {
        $alertMessage = "Customer name required hai.";
        $alertType = "danger";
    } elseif ($_GET['error'] === 'amount_invalid') {
        $alertMessage = "Total amount 0 se greater hona chahiye.";
        $alertType = "danger";
    } elseif ($_GET['error'] === 'duplicate') {
        $alertMessage = "Duplicate quotation detect hui. Dobara save nahi hui.";
        $alertType = "warning";
    } elseif ($_GET['error'] === 'bulk_invalid') {
        $alertMessage = "Please select action and at least one quotation.";
        $alertType = "danger";
    } elseif ($_GET['error'] === 'db') {
        $alertMessage = "Database error aaya hai. Query check karo.";
        $alertType = "danger";
    }
}
/* ================= FETCH ITEMS DYNAMIC ================= */
$items = [];

/*
    Important:
    items table me branch_id default 0 hai.
    Isliye sirf branch_id = session_branch_id lagane se products missing ho jati hain.
    Logic:
    - Same client ke active items lao
    - branch_id current branch ho ya 0 ho
    - agar branch session 0 ho to branch filter loose rakho
*/
if ($logged_branch_id > 0) {
    $itemSql = "
        SELECT 
            id,
            name,
            price,
            sku,
            type,
            stock_qty,
            branch_id,
            status
        FROM items
        WHERE client_id = ?
          AND (branch_id = ? OR branch_id = 0)
          AND (status = 'active' OR status IS NULL OR status = '')
        ORDER BY name ASC
    ";

    $itemStmt = $conn->prepare($itemSql);

    if ($itemStmt) {
        $itemStmt->bind_param("ii", $logged_client_id, $logged_branch_id);
        $itemStmt->execute();
        $itemRes = $itemStmt->get_result();

        while ($row = $itemRes->fetch_assoc()) {
            $items[] = [
                'id'        => (int)$row['id'],
                'name'      => (string)($row['name'] ?? ''),
                'price'     => (float)($row['price'] ?? 0),
                'sku'       => (string)($row['sku'] ?? ''),
                'type'      => (string)($row['type'] ?? 'product'),
                'stock_qty' => (float)($row['stock_qty'] ?? 0),
                'branch_id' => (int)($row['branch_id'] ?? 0),
            ];
        }

        $itemStmt->close();
    }
} else {
    $itemSql = "
        SELECT 
            id,
            name,
            price,
            sku,
            type,
            stock_qty,
            branch_id,
            status
        FROM items
        WHERE client_id = ?
          AND (status = 'active' OR status IS NULL OR status = '')
        ORDER BY name ASC
    ";

    $itemStmt = $conn->prepare($itemSql);

    if ($itemStmt) {
        $itemStmt->bind_param("i", $logged_client_id);
        $itemStmt->execute();
        $itemRes = $itemStmt->get_result();

        while ($row = $itemRes->fetch_assoc()) {
            $items[] = [
                'id'        => (int)$row['id'],
                'name'      => (string)($row['name'] ?? ''),
                'price'     => (float)($row['price'] ?? 0),
                'sku'       => (string)($row['sku'] ?? ''),
                'type'      => (string)($row['type'] ?? 'product'),
                'stock_qty' => (float)($row['stock_qty'] ?? 0),
                'branch_id' => (int)($row['branch_id'] ?? 0),
            ];
        }

        $itemStmt->close();
    }
}
/* ================= FETCH CUSTOMERS DYNAMIC ================= */
$customers = [];
$custStmt = $conn->prepare("
    SELECT id, name, phone, address, type
    FROM customers
    WHERE client_id = ?
    ORDER BY name ASC
");
if ($custStmt) {
    $custStmt->bind_param("i", $logged_client_id);
    $custStmt->execute();
    $custRes = $custStmt->get_result();

    while ($row = $custRes->fetch_assoc()) {
        $customers[] = [
            'id'      => (int)$row['id'],
            'name'    => (string)($row['name'] ?? ''),
            'phone'   => (string)($row['phone'] ?? ''),
            'address' => (string)($row['address'] ?? ''),
            'type'    => (string)($row['type'] ?? ''),
        ];
    }

    $custStmt->close();
}

/* ================= POST ACTIONS ================= */
/* ================= POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ================= AUTO SAVE DRAFT ================= */
    if ($action === 'auto_save_draft') {
        header('Content-Type: application/json; charset=utf-8');

        $draft_id            = (int)($_POST['draft_id'] ?? 0);
        $customer_name       = trim($_POST['customer_name'] ?? '');
        $phone               = trim($_POST['phone'] ?? '');
        $total_amount        = (float)($_POST['total_amount'] ?? 0);
        $selected_items_json = trim($_POST['selected_items_json'] ?? '');
        $quotation_date      = date('Y-m-d');
        $created_at          = date('Y-m-d H:i:s');
        $statusValue         = 'Draft';

        if ($logged_client_id <= 0) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid client session'
            ]);
            exit;
        }

        if ($customer_name === '' || $total_amount <= 0 || $selected_items_json === '') {
            echo json_encode([
                'status' => 'skip',
                'message' => 'Incomplete draft data'
            ]);
            exit;
        }

        $decodedItems = json_decode($selected_items_json, true);
        if (!is_array($decodedItems) || count($decodedItems) === 0) {
            echo json_encode([
                'status' => 'skip',
                'message' => 'No valid items found'
            ]);
            exit;
        }

        try {
            if ($draft_id > 0) {
                $checkStmt = $conn->prepare("SELECT id FROM quotations WHERE id = ? AND client_id = ? LIMIT 1");
                if (!$checkStmt) {
                    throw new Exception($conn->error);
                }

                $checkStmt->bind_param("ii", $draft_id, $logged_client_id);
                $checkStmt->execute();
                $checkRes = $checkStmt->get_result();
                $exists = $checkRes->fetch_assoc();
                $checkStmt->close();

                if ($exists) {
                    $updateSql = "UPDATE quotations
                                  SET customer_name = ?, phone = ?, total_amount = ?, status = ?, quotation_date = ?, selected_items_json = ?
                                  WHERE id = ? AND client_id = ?";
                    $updateStmt = $conn->prepare($updateSql);

                    if (!$updateStmt) {
                        throw new Exception($conn->error);
                    }

                    $updateStmt->bind_param(
                        "ssdsssii",
                        $customer_name,
                        $phone,
                        $total_amount,
                        $statusValue,
                        $quotation_date,
                        $selected_items_json,
                        $draft_id,
                        $logged_client_id
                    );

                    if (!$updateStmt->execute()) {
                        throw new Exception($updateStmt->error);
                    }

                    $updateStmt->close();

                    echo json_encode([
                        'status' => 'success',
                        'draft_id' => $draft_id,
                        'mode' => 'updated'
                    ]);
                    exit;
                }
            }

            $quotation_no = generateQuotationNo($conn, $logged_client_id);

            $insertSql = "INSERT INTO quotations
                        (client_id, quotation_no, quotation_date, customer_name, phone, total_amount, status, created_at, selected_items_json)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) {
                throw new Exception($conn->error);
            }

            $insertStmt->bind_param(
                "issssdsss",
                $logged_client_id,
                $quotation_no,
                $quotation_date,
                $customer_name,
                $phone,
                $total_amount,
                $statusValue,
                $created_at,
                $selected_items_json
            );

            if (!$insertStmt->execute()) {
                throw new Exception($insertStmt->error);
            }

            $newDraftId = (int)$insertStmt->insert_id;
            $insertStmt->close();

            echo json_encode([
                'status' => 'success',
                'draft_id' => $newDraftId,
                'mode' => 'inserted'
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }

    /* ================= FINAL SAVE QUOTATION ================= */
    if ($action === 'create_quotation') {
  $draft_id            = (int)($_POST['draft_id'] ?? 0);
$edit_mode           = (int)($_POST['edit_mode'] ?? 0);
$customer_id         = (int)($_POST['customer_id'] ?? 0);
        $customer_name       = trim($_POST['customer_name'] ?? '');
        $phone               = trim($_POST['phone'] ?? '');
        $address             = trim($_POST['address'] ?? '');
        $total_amount        = (float)($_POST['total_amount'] ?? 0);
        $selected_items_json = trim($_POST['selected_items_json'] ?? '');
        $statusValue         = 'Draft';
        $quotation_date      = date('Y-m-d');
        $created_at          = date('Y-m-d H:i:s');

        if ($logged_client_id <= 0) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=client_missing");
            exit;
        }

        if ($customer_name === '') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=customer_required");
            exit;
        }

        if ($total_amount <= 0) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=amount_invalid");
            exit;
        }

        $decodedItems = json_decode($selected_items_json, true);
        if (!is_array($decodedItems) || count($decodedItems) === 0) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=amount_invalid");
            exit;
        }

        $form_token = trim($_POST['form_token'] ?? '');
        if ($form_token === '' || !isset($_SESSION['quotation_form_token']) || $form_token !== $_SESSION['quotation_form_token']) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=duplicate");
            exit;
        }

        unset($_SESSION['quotation_form_token']);

        $conn->begin_transaction();

        try {
            if ($draft_id > 0) {
                $checkStmt = $conn->prepare("SELECT id FROM quotations WHERE id = ? AND client_id = ? LIMIT 1");
                if (!$checkStmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }

                $checkStmt->bind_param("ii", $draft_id, $logged_client_id);
                $checkStmt->execute();
                $checkRes = $checkStmt->get_result();
                $existingDraft = $checkRes->fetch_assoc();
                $checkStmt->close();

                if ($existingDraft) {
                    $updateSql = "UPDATE quotations
                                  SET customer_name = ?, phone = ?, total_amount = ?, status = ?, quotation_date = ?, selected_items_json = ?
                                  WHERE id = ? AND client_id = ?";
                    $updateStmt = $conn->prepare($updateSql);

                    if (!$updateStmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }

                    $updateStmt->bind_param(
                        "ssdsssii",
                        $customer_name,
                        $phone,
                        $total_amount,
                        $statusValue,
                        $quotation_date,
                        $selected_items_json,
                        $draft_id,
                        $logged_client_id
                    );

                    if (!$updateStmt->execute()) {
                        throw new Exception("Execute failed: " . $updateStmt->error);
                    }

                    $updateStmt->close();
                    $conn->commit();

                   $successKey = $edit_mode === 1 ? 'updated' : 'created';
header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . $successKey);
exit;
                }
            }

            $quotation_no = generateQuotationNo($conn, $logged_client_id);

            $sql = "INSERT INTO quotations
                    (client_id, quotation_no, quotation_date, customer_name, phone, total_amount, status, created_at, selected_items_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param(
                "issssdsss",
                $logged_client_id,
                $quotation_no,
                $quotation_date,
                $customer_name,
                $phone,
                $total_amount,
                $statusValue,
                $created_at,
                $selected_items_json
            );

            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            $stmt->close();
            $conn->commit();

            header("Location: " . $_SERVER['PHP_SELF'] . "?success=created");
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=db");
            exit;
        }
    }

    /* ================= BULK ACTIONS ================= */
    if ($action === 'bulk_action') {
        $bulkAction = trim($_POST['bulk_action'] ?? '');
        $selectedIds = $_POST['selected_ids'] ?? [];

        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        $selectedIds = array_values(array_filter(array_map('intval', $selectedIds)));

        if ($bulkAction === '' || empty($selectedIds)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=bulk_invalid");
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $types = str_repeat('i', count($selectedIds));

        if ($bulkAction === 'convert') {
            $sql = "UPDATE quotations
                    SET status = 'View Bill'
                    WHERE client_id = ? AND id IN ($placeholders)";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $bindTypes = 'i' . $types;
                $params = array_merge([$logged_client_id], $selectedIds);
                $stmt->bind_param($bindTypes, ...$params);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=converted");
                    exit;
                }

                $stmt->close();
            }

            header("Location: " . $_SERVER['PHP_SELF'] . "?error=db");
            exit;
        }

        if ($bulkAction === 'delete') {
            $sql = "DELETE FROM quotations
                    WHERE client_id = ? AND id IN ($placeholders)";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $bindTypes = 'i' . $types;
                $params = array_merge([$logged_client_id], $selectedIds);
                $stmt->bind_param($bindTypes, ...$params);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=deleted");
                    exit;
                }

                $stmt->close();
            }

            header("Location: " . $_SERVER['PHP_SELF'] . "?error=db");
            exit;
        }
    }
}

/* ================= NEW FORM TOKEN ================= */
$_SESSION['quotation_form_token'] = bin2hex(random_bytes(32));
$currentFormToken = $_SESSION['quotation_form_token'];

/* ================= FILTERS ================= */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

/* ================= PAGINATION ================= */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;

/* ================= COUNT TOTAL ================= */
$countSql = "
    SELECT COUNT(*) as total
    FROM quotations q
    WHERE q.client_id = ?
";
$countParams = [$logged_client_id];
$countTypes = "i";

if ($search !== '') {
    $countSql .= " AND (
        q.quotation_no LIKE ?
        OR q.customer_name LIKE ?
        OR q.phone LIKE ?
    )";
    $like = "%{$search}%";
    $countParams[] = $like;
    $countParams[] = $like;
    $countParams[] = $like;
    $countTypes .= "sss";
}

if ($status !== '') {
    $countSql .= " AND q.status = ?";
    $countParams[] = $status;
    $countTypes .= "s";
}

$totalRows = 0;
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    $countStmt->bind_param($countTypes, ...$countParams);
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $totalRows = (int)($countRes->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* ================= FETCH QUOTATIONS ================= */
$quotations = [];

$sql = "
    SELECT
        q.id,
        q.client_id,
        q.quotation_no,
        q.quotation_date,
        q.customer_name,
        q.phone,
     q.total_amount,
q.status,
q.created_at,
q.selected_items_json
    FROM quotations q
    WHERE q.client_id = ?
";

$params = [$logged_client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (
        q.quotation_no LIKE ?
        OR q.customer_name LIKE ?
        OR q.phone LIKE ?
    )";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($status !== '') {
    $sql .= " AND q.status = ?";
    $params[] = $status;
    $types .= "s";
}

$sql .= " ORDER BY q.id DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $quotations[] = $row;
    }
    $stmt->close();
}

/* ================= SUMMARY ================= */
$totalQuotationAmount = 0;
$pendingCount = 0;
$billedCount = 0;

$sumSql = "SELECT total_amount, status FROM quotations WHERE client_id = ?";
$sumStmt = $conn->prepare($sumSql);
if ($sumStmt) {
    $sumStmt->bind_param("i", $logged_client_id);
    $sumStmt->execute();
    $sumRes = $sumStmt->get_result();
    while ($q = $sumRes->fetch_assoc()) {
        $totalQuotationAmount += (float)$q['total_amount'];
        if (strtolower(trim($q['status'])) === 'view bill') {
            $billedCount++;
        } else {
            $pendingCount++;
        }
    }
    $sumStmt->close();
}

function buildPageUrl($pageNumber, $search, $status)
{
    $params = [
        'page' => $pageNumber
    ];
    if ($search !== '') $params['search'] = $search;
    if ($status !== '') $params['status'] = $status;
    return $_SERVER['PHP_SELF'] . '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quotation to Bill</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <script>
(function () {
    const storageKey = 'oneerp_pos_theme';
    let selectedTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

        if (savedTheme === 'light' || savedTheme === 'dark') {
            selectedTheme = savedTheme;
        }
    } catch (error) {
        selectedTheme = 'light';
    }

    document.documentElement.setAttribute(
        'data-pos-theme',
        selectedTheme
    );
})();
</script>
    
    
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        *{
            box-sizing:border-box;
        }

        :root{
            --bg:#f5f7fb;
            --card:#ffffff;
            --text:#1f2937;
            --muted:#6b7280;
            --border:#e7eaf3;
            --border-soft:#eef1f7;
            --primary:#5b49df;
            --primary-dark:#4338ca;
            --success:#16a34a;
            --danger:#ef4444;
            --warning:#f59e0b;
            --info:#2563eb;
            --thead-1:#071129;
            --thead-2:#0b1736;
            --shadow-sm:0 4px 14px rgba(15,23,42,.05);
            --shadow-md:0 10px 30px rgba(15,23,42,.07);
            --shadow-lg:0 16px 40px rgba(15,23,42,.09);
        }

        html,body{
            margin:0;
            padding:0;
            background:var(--bg);
            font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color:var(--text);
        }

        a{
            text-decoration:none;
        }

        /*.container-scroll{*/
        /*    display:flex;*/
        /*    min-height:100vh;*/
        /*    width:100%;*/
        /*}*/

/*.container-scroll{*/
/*    display:flex;*/
/*    min-height:100vh;*/
/*    width:100%;*/
  /*  align-items:stretch;  ← yeh add karo agar nahi hai */
/*}*/

/*       .sidebar{*/
/*    width:270px;*/
/*    min-width:270px;*/
/*    flex-shrink:0;*/
/*    align-self:stretch;*/
/*    overflow:hidden;*/
/*}*/


  .container-scroll{
        display:flex;
        min-height:100vh;
           align-items:stretch;
    }

    .sidebar{
        width:250px;
        flex-shrink:0;
          display:flex;*/
  align-self:stretch;
  background:transparent;
    overflow:hidden;
    }

.sidebar > .sidebar-wrap{
    width:100%;
    min-height:100%;
    height:100%;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100%;
}

        .main-panel{
            flex:1;
            min-width:0;
            display:flex;
            flex-direction:column;
        }

        .topbar{
            min-height:70px;
            background:#fff;
            border-bottom:1px solid var(--border);
            display:flex;
            align-items:center;
        }

        .content-wrapper{
            padding:24px;
            flex:1;
            overflow-x:hidden;
            overflow-y:auto;
        }

        .page-shell{
            background:transparent;
            padding:0;
            border:none;
            box-shadow:none;
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .page-title{
            margin:0;
            font-size:30px;
            line-height:1.1;
            font-weight:800;
            color:#111827;
        }

        .page-subtitle{
            margin:6px 0 0;
            font-size:14px;
            color:var(--muted);
        }

        .primary-btn,
        .secondary-btn,
        .apply-btn,
        .bill-btn,
        .print-btn,
        .viewbill-btn,
        .filter-btn{
            border:none;
            outline:none;
            border-radius:12px;
            padding:0 16px;
            min-height:44px;
            height:44px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            font-size:14px;
            font-weight:700;
            cursor:pointer;
            white-space:nowrap;
            transition:all .18s ease;
            text-decoration:none;
        }

        .primary-btn{
            background:linear-gradient(135deg,#22c55e,#16a34a);
            color:#fff;
            box-shadow:0 10px 20px rgba(34,197,94,.18);
        }

        .secondary-btn{
            background:#eef2f7;
            color:#374151;
        }

        .apply-btn,
        .bill-btn,
        .filter-btn{
            background:#111827;
            color:#fff;
        }

        .print-btn{
            background:#5b49df;
            color:#fff;
        }

        .viewbill-btn{
            background:#2563eb;
            color:#fff;
        }

        .primary-btn:hover,
        .secondary-btn:hover,
        .apply-btn:hover,
        .bill-btn:hover,
        .print-btn:hover,
        .viewbill-btn:hover,
        .filter-btn:hover{
            transform:translateY(-1px);
            opacity:.98;
            color:inherit;
        }

        .stats-row{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .stat-card{
            min-height:104px;
            border-radius:18px;
            padding:18px 20px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            color:#fff;
            box-shadow:var(--shadow-md);
        }

        .stat-card:nth-child(1){
            background:linear-gradient(135deg,#4f8dfd,#2f6fe4);
        }

        .stat-card:nth-child(2){
            background:linear-gradient(135deg,#f3c954,#e7ab12);
        }

        .stat-card:nth-child(3){
            background:linear-gradient(135deg,#43c489,#159957);
        }

        .stat-label{
            font-size:15px;
            font-weight:700;
            line-height:1.4;
            color:#fff;
        }

        .stat-value{
            font-size:34px;
            line-height:1;
            font-weight:800;
            color:#fff;
            flex-shrink:0;
        }

        .top-controls{
            display:grid;
            grid-template-columns:minmax(0,1fr) auto;
            gap:14px;
            align-items:stretch;
            margin-bottom:18px;
        }

        .filter-bar{
            background:var(--card);
            border:1px solid var(--border);
            border-radius:16px;
            padding:10px;
            display:grid;
            grid-template-columns:minmax(280px,1fr) 170px 120px 100px;
            gap:10px;
            align-items:center;
            box-shadow:var(--shadow-sm);
        }

        .bulk-actions-top{
            background:var(--card);
            border:1px solid var(--border);
            border-radius:16px;
            padding:10px;
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            min-width:300px;
            box-shadow:var(--shadow-sm);
        }

        .bulk-actions-top .bulk-select{
            min-width:190px;
            flex:1;
        }

        .search-wrap{
            position:relative;
            min-width:0;
        }

        .search-wrap i{
            position:absolute;
            left:14px;
            top:50%;
            transform:translateY(-50%);
            color:#94a3b8;
            font-size:15px;
        }

        .search-wrap input,
        .filter-select,
        .form-control-custom,
        .bulk-select{
            width:100%;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:0 14px;
            background:#fff;
            outline:none;
            font-size:14px;
            color:#374151;
            transition:all .18s ease;
        }

        .search-wrap input{
            padding-left:40px;
        }

        .search-wrap input:focus,
        .filter-select:focus,
        .form-control-custom:focus,
        .bulk-select:focus,
        .table-input:focus,
        .table-select:focus{
            border-color:#c7d2fe;
            box-shadow:0 0 0 3px rgba(99,102,241,.10);
        }

        .content-grid{
            display:block;
            width:100%;
        }

        .section-title{
            margin:8px 0 14px;
            font-size:24px;
            font-weight:800;
            color:#111827;
        }

        .table-card{
            width:100%;
            background:var(--card);
            border:1px solid var(--border);
            border-radius:22px;
            overflow:hidden;
            box-shadow:var(--shadow-md);
        }

        .table-responsive{
            width:100%;
            overflow-x:auto;
            overflow-y:hidden;
        }

        .quotation-table{
            width:100%;
            min-width:1180px;
            border-collapse:separate;
            border-spacing:0;
            background:#fff;
        }

        .quotation-table thead th{
            position:sticky;
            top:0;
            z-index:2;
            background:linear-gradient(90deg,var(--thead-1),var(--thead-2));
            color:#fff;
            font-size:14px;
            font-weight:800;
            padding:18px 16px;
            text-align:center;
            border:none;
            white-space:nowrap;
            letter-spacing:.2px;
        }

        .quotation-table thead th:first-child{
            width:58px;
            border-top-left-radius:20px;
        }

        .quotation-table thead th:last-child{
            border-top-right-radius:20px;
        }

        .quotation-table tbody td{
            padding:20px 16px;
            border-top:1px solid var(--border-soft);
            vertical-align:middle;
            font-size:15px;
            color:#334155;
            white-space:nowrap;
            background:#fff;
            text-align:center;
            transition:background .18s ease;
        }

        .quotation-table tbody tr:hover td{
            background:#f8faff;
        }

        .quotation-table tbody td:nth-child(2),
        .quotation-table tbody td:nth-child(4){
            text-align:left;
        }

        .quotation-table tbody td:nth-child(4){
            font-weight:600;
            color:#1f2937;
        }

        .quotation-no-link{
            color:#4f46e5;
            font-weight:800;
            font-size:16px;
            text-decoration:none;
        }

        .quotation-no-link:hover{
            color:#372fc9;
            text-decoration:underline;
        }

        .amount-text{
            font-weight:800;
            font-size:16px;
            color:#24324a;
        }

        .table-check{
            width:18px;
            height:18px;
            cursor:pointer;
            accent-color:#4f46e5;
            border-radius:4px;
        }

        .status-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            min-width:120px;
            padding:10px 16px;
            border-radius:999px;
            font-size:13px;
            font-weight:800;
            line-height:1;
            border:none;
        }

        .status-badge::before{
            content:"";
            width:9px;
            height:9px;
            border-radius:50%;
            display:inline-block;
            flex-shrink:0;
        }

        .status-draft{
            background:#eef4ff;
            color:#2563eb;
        }

        .status-draft::before{
            background:#2563eb;
        }

        .status-billed{
            background:#e9f9ef;
            color:#16a34a;
        }

        .status-billed::before{
            background:#16a34a;
        }

        .actions-cell{
            min-width:190px;
        }

        .action-group{
            display:flex;
            justify-content:center;
            align-items:center;
            gap:10px;
        }

        .icon-action{
            width:38px;
            height:38px;
            border:none;
            border-radius:50%;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:16px;
            text-decoration:none;
            transition:all .18s ease;
            box-shadow:0 4px 10px rgba(15,23,42,.06);
            cursor:pointer;
        }
.icon-action.only-bin{
    width: 38px;
    height: 38px;
    padding: 0;
    border: none;
    outline: none;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ffe9ea;
    color: #ef4444;
    text-decoration: none;
    box-shadow: 0 4px 10px rgba(15,23,42,.06);
}

.icon-action.only-bin i{
    font-size: 16px;
    line-height: 1;
}

.icon-action.only-bin::before,
.icon-action.only-bin::after,
.icon-delete::before,
.icon-delete::after{
    content: none !important;
    display: none !important;
}

.icon-action.only-bin:hover{
    background: #ef4444;
    color: #fff;
}
        .icon-action:hover{
            transform:translateY(-2px);
        }

        .icon-view{
            background:#efeaff;
            color:#7c61f3;
        }

        .icon-copy{
            background:#e9f9ef;
            color:#16a34a;
        }

        .icon-delete{
            background:#ffe9ea;
            color:#ef4444;
        }

        .icon-whatsapp{
            background:#ebfaef;
            color:#16a34a;
        }

        .disabled-convert{
            opacity:.55;
        }

        .table-footer{
            padding:16px 18px;
            border-top:1px solid var(--border-soft);
            background:#fff;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .table-footer-left{
            color:#6b7280;
            font-size:13px;
            font-weight:700;
        }

        .pagination-wrap{
            display:flex;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
        }

        .page-nav-btn,
        .page-number-btn{
            min-width:36px;
            height:36px;
            padding:0 10px;
            border-radius:10px;
            border:1px solid #e5e7eb;
            background:#fff;
            color:#374151;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            font-size:13px;
            font-weight:800;
            transition:all .18s ease;
        }

        .page-nav-btn:hover,
        .page-number-btn:hover{
            border-color:#c7d2fe;
            color:#4f46e5;
        }

        .page-number-btn.active{
            background:#4f46e5;
            color:#fff;
            border-color:#4f46e5;
        }

        .alert{
            border-radius:14px;
            font-weight:600;
            box-shadow:var(--shadow-sm);
        }

        .d-none{
            display:none !important;
        }

      /* ===== QUOTATION MODAL PREMIUM UI ===== */
.quotation-modal .modal-dialog{
    max-width:1150px;
}

.quotation-modal .modal-content{
    background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
    color:#fff;
    border:none;
    border-radius:18px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.35);
}

.quotation-modal-header{
    padding:18px 22px;
    border-bottom:1px solid rgba(255,255,255,0.08);
    background:transparent;
}

.quotation-modal-header .modal-title{
    font-size:20px;
    font-weight:800;
    color:#fff;
}

.quotation-modal-body{
    padding:22px;
    background:transparent;
}

.quotation-modal-footer{
    padding:16px 22px 20px;
    border-top:1px solid rgba(255,255,255,0.08);
    background:transparent;
}

.quotation-modal .btn-close{
    filter:invert(1);
}

.field-label{
    display:block;
    margin-bottom:8px;
    font-size:14px;
    font-weight:700;
    color: white;
}

.input-icon-wrap{
    position:relative;
}

.input-icon-wrap i{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#8f9bb3;
    z-index:2;
}

.with-icon{
    padding-left:40px !important;
}

.form-control-custom,
.table-input,
.table-select,
.quotation-modal .form-select,
.quotation-modal input[type="date"],
.quotation-modal input[type="text"],
.quotation-modal input[type="number"],
.quotation-modal select{
    width:100%;
    min-height:44px;
    border:1px solid rgba(255,255,255,0.10);
    background:#e9eaf3;
    color:#222;
    border-radius:10px;
    padding:10px 14px;
    outline:none;
    font-size:14px;
    transition:all .2s ease;
    box-shadow:none;
}

.form-control-custom:focus,
.table-input:focus,
.table-select:focus,
.quotation-modal .form-select:focus,
.quotation-modal input:focus,
.quotation-modal select:focus{
    border-color:#f0ae19;
    box-shadow:0 0 0 2px rgba(240,174,25,.20);
    background:#f5f6fb;
}

.product-search-wrap{
    position:relative;
}

.product-search-results{
    position:absolute;
    top:calc(100% + 6px);
    left:0;
    right:0;
    z-index:9999;
    max-height:260px;
    overflow-y:auto;
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    box-shadow:0 14px 30px rgba(0,0,0,.16);
}

.product-search-item{
    padding:12px 14px;
    border-bottom:1px solid #f1f5f9;
    cursor:pointer;
    transition:background .18s ease;
}

.product-search-item:last-child{
    border-bottom:none;
}

.product-search-item:hover{
    background:#f8fafc;
}

.product-search-name{
    font-size:14px;
    font-weight:800;
    color:#111827;
    margin-bottom:2px;
}

.product-search-meta{
    font-size:12px;
    color:#6b7280;
}

.quotation-product-table-wrap{
    border:1px solid rgba(255,255,255,0.08);
    border-radius:14px;
    overflow:hidden;
    background:rgba(255,255,255,.03);
}

.quotation-product-table{
    width:100%;
    table-layout:fixed;
    border-collapse:separate;
    border-spacing:0;
}

.quotation-product-table thead th{
    background:rgba(255,255,255,.08);
    color:#fff;
    padding:13px 10px;
    font-size:13px;
    font-weight:700;
    border:none;
    white-space:nowrap;
}

.quotation-product-table tbody td{
    padding:10px;
    border-top:1px solid rgba(255,255,255,.06);
    vertical-align:middle;
    background:transparent;
}

.item-amount-text{
    font-weight:800;
    color:#fff;
}

.delete-row-btn{
    border:none;
    background:linear-gradient(180deg,#ef4444 0%, #dc2626 100%);
    color:#fff;
    font-size:16px;
    cursor:pointer;
    width:38px;
    height:38px;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.quotation-summary-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:30px;
    padding-top:10px;
}

.summary-line{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:12px;
    font-size:15px;
    color:white;
}

.summary-line strong{
    font-weight:800;
    color:white;
}

.summary-line.total-line strong{
    font-size:18px;
}

.summary-line.grand-total-line{
    margin-top:8px;
    padding-top:12px;
    border-top:1px dashed rgba(255,255,255,.18);
}

#totalAmountBig{
    font-size:30px;
    font-weight:900;
    color:#fff;
    line-height:1.1;
}

.quotation-modal .secondary-btn{
    background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
    color:#fff;
    border:none;
}

.quotation-modal .primary-btn{
    background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
    color:#fff;
    border:none;
    box-shadow:none;
}

.quotation-modal .secondary-btn:hover,
.quotation-modal .primary-btn:hover{
    opacity:.92;
    transform:translateY(-1px);
}

.table-responsive::-webkit-scrollbar,
.product-search-results::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar{
    height:10px;
    width:10px;
}

.table-responsive::-webkit-scrollbar-thumb,
.product-search-results::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb{
    background:#cfd7e6;
    border-radius:20px;
}

.table-responsive::-webkit-scrollbar-track,
.product-search-results::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track{
    background:#f3f6fb;
}

@media (max-width:1100px){
    .quotation-summary-grid{
        grid-template-columns:1fr;
        gap:16px;
    }
}

@media (max-width:768px){
    .quotation-modal-footer{
        display:flex;
        flex-direction:column;
    }

    .quotation-modal-footer .primary-btn,
    .quotation-modal-footer .secondary-btn{
        width:100%;
    }

    #totalAmountBig{
        font-size:24px;
    }
}

        .field-label{
            display:block;
            margin-bottom:8px;
            font-size:13px;
            font-weight:800;
            color:white
        }

        .input-icon-wrap{
            position:relative;
            color: white;
        }

        .input-icon-wrap i{
            position:absolute;
            left:14px;
            top:50%;
            transform:translateY(-50%);
            color:#94a3b8;
            z-index:2;
        }

        .with-icon{
            padding-left:40px !important;
        }

        .product-search-wrap{
            position:relative;
        }

        .product-search-results{
            position:absolute;
            top:calc(100% + 6px);
            left:0;
            right:0;
            z-index:9999;
            max-height:260px;
            overflow-y:auto;
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:14px;
            box-shadow:var(--shadow-md);
        }

        .product-search-item{
            padding:12px 14px;
            border-bottom:1px solid #f1f5f9;
            cursor:pointer;
            transition:background .18s ease;
        }

        .product-search-item:last-child{
            border-bottom:none;
        }

        .product-search-item:hover{
            background:#f8fafc;
        }

        .product-search-name{
            font-size:14px;
            font-weight:800;
            color:#111827;
            margin-bottom:2px;
        }

        .product-search-meta{
            font-size:12px;
            color:#6b7280;
        }

        .quotation-product-table-wrap{
            border:1px solid #e5e7eb;
            border-radius:14px;
            overflow:hidden;
        }

        .quotation-product-table{
            width:100%;
            table-layout:fixed;
            border-collapse:separate;
            border-spacing:0;
        }

        .quotation-product-table thead th{
            background:#28344d;
            color:#fff;
            padding:13px 10px;
            font-size:13px;
            font-weight:700;
            border:none;
            white-space:nowrap;
        }

        .quotation-product-table tbody td{
            padding:10px;
            border-top:1px solid #edf0f5;
            vertical-align:middle;
            background:#fff;
        }

        .table-input,
        .table-select{
            width:100%;
            min-height:40px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:8px 10px;
            background:#fff;
            outline:none;
            font-size:14px;
            color:#374151;
        }

        .item-amount-text{
            font-weight:800;
            color:#334155;
        }

        .delete-row-btn{
            border:none;
            background:transparent;
            color:#ef4444;
            font-size:18px;
            cursor:pointer;
        }

        .quotation-summary-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:30px;
            padding-top:10px;
        }

        .summary-line{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            margin-bottom:12px;
            font-size:15px;
            color:#374151;
        }

        .summary-line strong{
            font-weight:800;
            color:#111827;
        }

        .summary-line.total-line strong{
            font-size:18px;
        }

        .summary-line.grand-total-line{
            margin-top:8px;
            padding-top:12px;
            border-top:1px dashed #dbe2ea;
        }

        #totalAmountBig{
            font-size:30px;
            font-weight:900;
            color:#111827;
            line-height:1.1;
        }

        .table-responsive::-webkit-scrollbar,
        .product-search-results::-webkit-scrollbar,
        .content-wrapper::-webkit-scrollbar{
            height:10px;
            width:10px;
        }

        .table-responsive::-webkit-scrollbar-thumb,
        .product-search-results::-webkit-scrollbar-thumb,
        .content-wrapper::-webkit-scrollbar-thumb{
            background:#cfd7e6;
            border-radius:20px;
        }

        .table-responsive::-webkit-scrollbar-track,
        .product-search-results::-webkit-scrollbar-track,
        .content-wrapper::-webkit-scrollbar-track{
            background:#f3f6fb;
        }

        @media (max-width:1200px){
            .filter-bar{
                grid-template-columns:minmax(220px,1fr) 160px 110px 100px;
            }

            .quotation-table{
                min-width:1100px;
            }
        }

        @media (max-width:1100px){
            .stats-row{
                grid-template-columns:repeat(2,minmax(0,1fr));
            }

            .top-controls{
                grid-template-columns:1fr;
            }

            .bulk-actions-top{
                min-width:100%;
                justify-content:flex-start;
            }

            .quotation-summary-grid{
                grid-template-columns:1fr;
                gap:16px;
            }
        }

        @media (max-width:768px){
            .content-wrapper{
                padding:16px;
            }

            .sidebar{
                width:230px;
            }

            .page-header{
                align-items:flex-start;
            }

            .page-title{
                font-size:24px;
            }

            .page-subtitle{
                font-size:13px;
            }

            .stats-row{
                grid-template-columns:1fr;
            }

            .stat-card{
                min-height:90px;
                padding:16px;
            }

            .stat-value{
                font-size:28px;
            }

            .filter-bar{
                grid-template-columns:1fr;
            }

            .bulk-actions-top{
                flex-direction:column;
                align-items:stretch;
            }

            .bulk-actions-top .bulk-select,
            .bulk-actions-top .apply-btn{
                width:100%;
            }

            .quotation-table thead th,
            .quotation-table tbody td{
                padding:16px 12px;
                font-size:14px;
            }

            .status-badge{
                min-width:106px;
                padding:9px 14px;
                font-size:12px;
            }

            .action-group{
                gap:8px;
            }

            .icon-action{
                width:34px;
                height:34px;
            }

            .quotation-modal-footer{
                display:flex;
                flex-direction:column;
            }

            .quotation-modal-footer .primary-btn,
            .quotation-modal-footer .secondary-btn{
                width:100%;
            }

            #totalAmountBig{
                font-size:24px;
            }
        }

        @media (max-width:576px){
            .content-wrapper{
                padding:12px;
            }

            .page-title{
                font-size:22px;
            }

            .section-title{
                font-size:20px;
            }

            .primary-btn,
            .secondary-btn,
            .apply-btn,
            .bill-btn,
            .print-btn,
            .viewbill-btn,
            .filter-btn{
                width:100%;
            }

            .page-header > div,
            .page-header > button{
                width:100%;
            }

            .table-footer{
                flex-direction:column;
                align-items:flex-start;
            }
        }
    

/* =========================================================
   QUOTATION POPUP BLACK + GOLD + WHITE THEME OVERRIDE
   New Quotation Modal
   Backend/PHP/JS same - only popup theme
========================================================= */

.modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
}

#newQuotationModal .modal-dialog{
    max-width:min(1240px, 94vw) !important;
}

#newQuotationModal .modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.55) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 30px 90px rgba(0,0,0,.65) !important;
}

#newQuotationModal .quotation-modal-header{
    background:linear-gradient(180deg,#161b28 0%, #0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
}

#newQuotationModal .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

#newQuotationModal .modal-title::before{
    content:'';
    width:13px;
    height:13px;
    border-radius:50%;
    background:#c9a84c;
    box-shadow:0 0 0 5px rgba(201,168,76,.15);
    display:inline-block;
    flex-shrink:0;
}

/* Proper visible white X button, without depending on Bootstrap SVG */
#newQuotationModal .btn-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.12) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#newQuotationModal .btn-close::before,
#newQuotationModal .btn-close::after{
    content:'';
    position:absolute;
    top:50%;
    left:50%;
    width:22px;
    height:2px;
    background:#ffffff;
    border-radius:2px;
    transform-origin:center;
}

#newQuotationModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg);
}

#newQuotationModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg);
}

#newQuotationModal .btn-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px);
}

#newQuotationModal .quotation-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#newQuotationModal .quotation-modal-body::-webkit-scrollbar{
    width:7px;
}

#newQuotationModal .quotation-modal-body::-webkit-scrollbar-track{
    background:#171c28;
    border-radius:999px;
}

#newQuotationModal .quotation-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c;
    border-radius:999px;
}

#newQuotationModal .quotation-modal-body::-webkit-scrollbar-thumb:hover{
    background:#e8bb34;
}

#newQuotationModal .quotation-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.25) !important;
    padding:18px 24px 22px !important;
}

#newQuotationModal .field-label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#newQuotationModal .form-control-custom,
#newQuotationModal .table-input,
#newQuotationModal .table-select,
#newQuotationModal .form-select,
#newQuotationModal input[type="date"],
#newQuotationModal input[type="text"],
#newQuotationModal input[type="number"],
#newQuotationModal select{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    min-height:50px !important;
    padding:10px 16px !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#newQuotationModal .with-icon{
    padding-left:42px !important;
}

#newQuotationModal .input-icon-wrap i{
    color:#c9a84c !important;
    z-index:3 !important;
}

#newQuotationModal input::placeholder{
    color:#8993a5 !important;
}

#newQuotationModal .form-control-custom:focus,
#newQuotationModal .table-input:focus,
#newQuotationModal .table-select:focus,
#newQuotationModal .form-select:focus,
#newQuotationModal input:focus,
#newQuotationModal select:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24), 0 5px 14px rgba(0,0,0,.22) !important;
}

/* Product search dropdown */
#newQuotationModal .product-search-results{
    background:#0f1117 !important;
    border:1px solid rgba(201,168,76,.45) !important;
    border-radius:12px !important;
    box-shadow:0 20px 40px rgba(0,0,0,.45) !important;
    overflow:hidden !important;
}

#newQuotationModal .product-search-item{
    background:#0f1117 !important;
    border-bottom:1px solid rgba(255,255,255,.06) !important;
}

#newQuotationModal .product-search-item:hover{
    background:#1b2130 !important;
}

#newQuotationModal .product-search-name{
    color:#f5c742 !important;
    font-weight:900 !important;
}

#newQuotationModal .product-search-meta{
    color:#dbe0f4 !important;
    font-weight:700 !important;
}

/* Product table */
#newQuotationModal .quotation-product-table-wrap{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.35) !important;
    border-radius:14px !important;
    overflow:hidden !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#newQuotationModal .quotation-product-table thead th{
    background:linear-gradient(180deg,#161b28 0%, #0f1117 100%) !important;
    color:#ffffff !important;
    border-bottom:1px solid rgba(201,168,76,.35) !important;
    font-weight:900 !important;
}

#newQuotationModal .quotation-product-table tbody td{
    background:#151a26 !important;
    color:#ffffff !important;
    border-top:1px solid rgba(201,168,76,.16) !important;
}

#newQuotationModal .quotation-product-table tbody tr.empty-row td,
#newQuotationModal .quotation-product-table .text-muted{
    color:#dbe0f4 !important;
    font-weight:700 !important;
}

#newQuotationModal .item-amount-text{
    color:#f5c742 !important;
    font-weight:900 !important;
}

#newQuotationModal .delete-row-btn{
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#c94b57,#9f2430) !important;
    color:#ffffff !important;
    width:40px !important;
    height:40px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    box-shadow:0 8px 18px rgba(201,75,87,.22) !important;
}

/* Summary area */
#newQuotationModal .quotation-summary-grid{
    background:#151a26 !important;
    border:1px solid rgba(201,168,76,.35) !important;
    border-radius:14px !important;
    padding:18px !important;
    box-shadow:0 8px 20px rgba(0,0,0,.20) !important;
}

#newQuotationModal .summary-line{
    color:#ffffff !important;
}

#newQuotationModal .summary-line strong{
    color:#f5c742 !important;
    font-weight:900 !important;
}

#newQuotationModal .summary-line.grand-total-line{
    border-top:1px dashed rgba(201,168,76,.45) !important;
}

#newQuotationModal #totalAmountBig{
    color:#f5c742 !important;
    font-weight:900 !important;
}

/* Footer buttons */
#newQuotationModal .secondary-btn{
    background:#252b3a !important;
    color:#ffffff !important;
    border:1px solid rgba(255,255,255,.12) !important;
    border-radius:10px !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#newQuotationModal .secondary-btn:hover{
    background:#32394b !important;
}

#newQuotationModal .primary-btn{
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    border:none !important;
    border-radius:10px !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#newQuotationModal .primary-btn:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px);
}

@media (max-width:768px){
    #newQuotationModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #newQuotationModal .modal-title{
        font-size:18px !important;
    }

    #newQuotationModal .btn-close{
        width:42px !important;
        height:42px !important;
    }

    #newQuotationModal .secondary-btn,
    #newQuotationModal .primary-btn{
        width:100% !important;
    }
}


/* =====================================================
   QUOTATIONS PAGE DARK PURPLE GLASS THEME + POPUP FIX
   Paste at END before 
===================================================== */

html, body{
    min-height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-yellow:#f59e0b;
    --oms-cyan:#06b6d4;
    --oms-red:#ef4444;
}

/* ================= BASE + LAYOUT ================= */
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI", Tahoma, Geneva, Verdana, sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
    overflow-x:hidden !important;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    width:100% !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

.sidebar .sidebar-wrap,
#dashboardSidebar .sidebar-wrap,
#sidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar .sidebar-inner,
#dashboardSidebar .sidebar-inner,
#sidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

.sidebar .sidebar-menu,
#dashboardSidebar .sidebar-menu,
#sidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.main-panel{
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
    transition:margin-left .25s ease !important;
}

.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    align-items:center !important;
    padding:0 !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
}

.content-wrapper{
    position:relative !important;
    padding:24px 26px !important;
    flex:1 !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
}

.content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}

.content-wrapper::after{
    content:"";
    position:absolute;
    width:520px;
    height:520px;
    right:-190px;
    top:-210px;
    pointer-events:none;
    z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);
    opacity:.85;
}

.content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* ================= SIDEBAR COLLAPSE ================= */
body.dashboard-sidebar-collapsed .sidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed .sidebar .search-box,
body.dashboard-sidebar-collapsed .sidebar .menu-title,
body.dashboard-sidebar-collapsed .sidebar .menu-arrow,
body.dashboard-sidebar-collapsed .sidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-icon{
    margin:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .sidebar-inner{
    padding-left:8px !important;
    padding-right:8px !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn{
    width:52px !important;
    height:42px !important;
    margin:0 auto !important;
    padding:0 !important;
    border-radius:12px !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn span{
    display:none !important;
}

/* ================= PAGE HEADER ================= */
.page-title{
    color:#f8fafc !important;
    font-size:30px !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
}

.page-subtitle{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.section-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* ================= BUTTONS ================= */
.primary-btn{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    color:#fff !important;
    border:1px solid rgba(34,197,94,.28) !important;
    border-radius:13px !important;
    box-shadow:0 14px 28px rgba(34,197,94,.18) !important;
    font-weight:900 !important;
}

.apply-btn,
.filter-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:13px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    font-weight:900 !important;
}

.secondary-btn{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    font-weight:900 !important;
}

.primary-btn:hover,
.apply-btn:hover,
.filter-btn:hover,
.secondary-btn:hover{
    transform:translateY(-1px) !important;
    color:#fff !important;
}

/* ================= STATS ================= */
.stats-row{
    display:grid !important;
    grid-template-columns:repeat(3, minmax(0,1fr)) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
    z-index:-1;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card:nth-child(1){
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
}

.stat-card:nth-child(2){
    background:linear-gradient(135deg,#f59e0b,#facc15) !important;
}

.stat-card:nth-child(3){
    background:linear-gradient(135deg,#047857,#16a34a) !important;
}

.stat-label{
    color:#fff !important;
    font-weight:900 !important;
}

.stat-value{
    color:#fff !important;
    font-weight:900 !important;
}

/* ================= FILTER + BULK ================= */
.top-controls{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) 340px !important;
    gap:14px !important;
    align-items:stretch !important;
    margin-bottom:18px !important;
}

.filter-bar,
.bulk-actions-top{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    padding:12px !important;
}

.filter-bar{
    display:grid !important;
    grid-template-columns:1fr 180px 120px 110px !important;
    gap:10px !important;
    align-items:center !important;
}

.bulk-actions-top{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

.search-wrap input,
.filter-select,
.bulk-select{
    width:100% !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
    outline:none !important;
    box-shadow:none !important;
}

.search-wrap input{
    padding-left:42px !important;
}

.search-wrap i{
    color:#94a3b8 !important;
}

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.filter-select option,
.bulk-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.search-wrap input:focus,
.filter-select:focus,
.bulk-select:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
}

/* ================= TABLE ================= */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    overflow:hidden !important;
}

.quotation-table{
    background:transparent !important;
    border-collapse:collapse !important;
}

.quotation-table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:16px 14px !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.quotation-table tbody td{
    background:rgba(15,23,42,.24) !important;
    color:#e5e7eb !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    font-weight:700 !important;
}

.quotation-table tbody tr:hover td{
    background:rgba(124,58,237,.13) !important;
    color:#fff !important;
}

.quotation-table td.text-center,
.quotation-table td[colspan]{
    color:#94a3b8 !important;
    background:rgba(15,23,42,.32) !important;
    font-weight:900 !important;
}

.quotation-no-link{
    color:#93c5fd !important;
    font-weight:900 !important;
}

.quotation-no-link:hover{
    color:#c4b5fd !important;
}

.amount-text{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.table-check{
    accent-color:#7c3aed !important;
}

.table-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.13) !important;
}

.table-footer-left{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.page-nav-btn,
.page-number-btn{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.page-number-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.35) !important;
}

/* ================= ACTIONS ================= */
.icon-action{
    border-radius:14px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.icon-view{
    background:rgba(109,76,255,.16) !important;
    color:#a78bfa !important;
    border:1px solid rgba(109,76,255,.25) !important;
}

.icon-copy,
.icon-whatsapp{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.24) !important;
}

.icon-delete,
.icon-action.only-bin{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

.icon-action:hover{
    transform:translateY(-2px) !important;
}

/* ================= STATUS BADGES ================= */
.status-badge{
    border:1px solid transparent !important;
    font-weight:900 !important;
}

.status-draft{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border-color:rgba(59,130,246,.24) !important;
}

.status-billed{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

/* ================= ALERTS ================= */
.alert{
    border-radius:14px !important;
    font-weight:800 !important;
    backdrop-filter:blur(14px) !important;
}

.alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.26) !important;
}

.alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

.alert-warning{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.26) !important;
}

/* =====================================================
   NEW QUOTATION POPUP DARK PURPLE THEME
===================================================== */

.modal-backdrop.show{
    opacity:.76 !important;
    background:#000 !important;
}

#newQuotationModal{
    z-index:99999 !important;
}

#newQuotationModal .modal-dialog{
    max-width:min(1240px, 94vw) !important;
}

#newQuotationModal .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.22), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.98), rgba(17,24,39,.96)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:20px !important;
    overflow:hidden !important;
    box-shadow:
        0 30px 90px rgba(0,0,0,.72),
        0 0 55px rgba(124,58,237,.16) !important;
}

#newQuotationModal .quotation-modal-header{
    min-height:70px !important;
    padding:18px 22px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

#newQuotationModal .modal-title{
    color:#f8fafc !important;
    font-size:21px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
}

#newQuotationModal .modal-title::before{
    display:none !important;
}

#newQuotationModal .btn-close{
    width:42px !important;
    height:42px !important;
    border-radius:13px !important;
    background:rgba(148,163,184,.14) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    opacity:1 !important;
    filter:none !important;
    position:relative !important;
    background-image:none !important;
}

#newQuotationModal .btn-close::before,
#newQuotationModal .btn-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:20px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#newQuotationModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#newQuotationModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#newQuotationModal .quotation-modal-body{
    padding:24px 22px !important;
    background:rgba(15,23,42,.52) !important;
    max-height:calc(100vh - 190px) !important;
    overflow-y:auto !important;
}

#newQuotationModal .quotation-modal-footer{
    padding:16px 22px 18px !important;
    background:rgba(7,11,20,.48) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

#newQuotationModal .field-label{
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:7px !important;
}

#newQuotationModal .form-control-custom,
#newQuotationModal .table-input,
#newQuotationModal .table-select,
#newQuotationModal .form-select,
#newQuotationModal input[type="date"],
#newQuotationModal input[type="text"],
#newQuotationModal input[type="number"],
#newQuotationModal select{
    background:#fff !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.95) !important;
    border-radius:12px !important;
    min-height:48px !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.16) !important;
}

#newQuotationModal .form-control-custom:focus,
#newQuotationModal .table-input:focus,
#newQuotationModal .table-select:focus,
#newQuotationModal .form-select:focus,
#newQuotationModal input:focus,
#newQuotationModal select:focus{
    background:#fff !important;
    color:#111827 !important;
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 4px rgba(124,58,237,.22),
        0 10px 22px rgba(0,0,0,.18) !important;
}

#newQuotationModal .input-icon-wrap i{
    color:#7c3aed !important;
}

#newQuotationModal .product-search-results{
    background:#0f172a !important;
    border:1px solid rgba(139,92,246,.35) !important;
    border-radius:12px !important;
    box-shadow:0 20px 50px rgba(0,0,0,.45) !important;
}

#newQuotationModal .product-search-item{
    background:#0f172a !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
}

#newQuotationModal .product-search-item:hover{
    background:rgba(124,58,237,.16) !important;
}

#newQuotationModal .product-search-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

#newQuotationModal .product-search-meta{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

#newQuotationModal .quotation-product-table-wrap,
#newQuotationModal .quotation-summary-grid{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.14), transparent 34%),
        rgba(15,23,42,.68) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:16px !important;
    box-shadow:0 14px 30px rgba(0,0,0,.18) !important;
}

#newQuotationModal .quotation-product-table thead th{
    background:rgba(7,11,20,.72) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    font-weight:900 !important;
}

#newQuotationModal .quotation-product-table tbody td{
    background:rgba(15,23,42,.30) !important;
    color:#e5e7eb !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
}

#newQuotationModal .quotation-product-table .text-muted{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

#newQuotationModal .item-amount-text{
    color:#4ade80 !important;
    font-weight:900 !important;
}

#newQuotationModal .summary-line,
#newQuotationModal .summary-line strong{
    color:#e5e7eb !important;
}

#newQuotationModal #totalAmountBig,
#newQuotationModal #totalText{
    color:#4ade80 !important;
    font-weight:900 !important;
}

#newQuotationModal .delete-row-btn{
    background:rgba(239,68,68,.18) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
    border-radius:12px !important;
    width:42px !important;
    height:42px !important;
}

#newQuotationModal .delete-row-btn:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

#newQuotationModal .secondary-btn{
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

#newQuotationModal .primary-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:1px solid rgba(139,92,246,.35) !important;
}

/* ================= RESPONSIVE ================= */
@media (max-width:1200px){
    .top-controls{
        grid-template-columns:1fr !important;
    }

    .filter-bar{
        grid-template-columns:1fr 180px auto auto !important;
    }

    .bulk-actions-top{
        width:100% !important;
    }
}

@media (max-width:991px){
    .container-scroll{
        flex-direction:column !important;
    }

    .sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        position:relative !important;
        height:auto !important;
    }

    .sidebar .sidebar-wrap,
    #dashboardSidebar .sidebar-wrap,
    #sidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    .main-panel{
        margin-left:0 !important;
    }

    .topbar{
        position:relative !important;
    }

    .content-wrapper{
        padding:18px !important;
    }
}

@media (max-width:768px){
    /*.stats-row{*/
    /*    grid-template-columns:1fr !important;*/
    /*}*/

    /*.filter-bar{*/
    /*    grid-template-columns:1fr !important;*/
    /*}*/

    .bulk-actions-top{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .bulk-select,
    .apply-btn,
    .filter-btn,
    .secondary-btn,
    .primary-btn{
        width:100% !important;
    }

    #newQuotationModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #newQuotationModal .quotation-modal-footer{
        flex-direction:column !important;
    }
}


/* =====================================================
   NEW QUOTATION POPUP SMALL COMPACT FIX
   Paste at VERY END before 
===================================================== */

/* Page kam hide ho */
body.modal-open .modal-backdrop.show,
.modal-backdrop.show{
    opacity:.42 !important;
    background:#000 !important;
}

/* Popup width chota */
#newQuotationModal .quotation-small-modal,
#newQuotationModal .modal-dialog{
    max-width:720px !important;
    width:720px !important;
    margin:1rem auto !important;
}

/* Popup height control */
#newQuotationModal .modal-content{
    max-height:84vh !important;
    border-radius:16px !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.25), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.88), rgba(17,24,39,.72)) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
}

/* Header compact */
#newQuotationModal .quotation-modal-header{
    min-height:50px !important;
    padding:10px 16px !important;
}

#newQuotationModal .modal-title{
    font-size:17px !important;
}

/* Body compact + scroll */
#newQuotationModal .quotation-modal-body{
    padding:14px 16px !important;
    max-height:60vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* Bootstrap gaps compact */
#newQuotationModal .row{
    --bs-gutter-x:10px !important;
    --bs-gutter-y:10px !important;
}

/* Labels small */
#newQuotationModal .field-label{
    font-size:11px !important;
    margin-bottom:4px !important;
}

/* Inputs small */
#newQuotationModal .form-control-custom,
#newQuotationModal .table-input,
#newQuotationModal .table-select,
#newQuotationModal .form-select,
#newQuotationModal input[type="date"],
#newQuotationModal input[type="text"],
#newQuotationModal input[type="number"],
#newQuotationModal select{
    min-height:34px !important;
    height:34px !important;
    border-radius:9px !important;
    padding:5px 10px !important;
    font-size:12px !important;
}

/* Icon input padding */
#newQuotationModal .with-icon{
    padding-left:34px !important;
}

#newQuotationModal .input-icon-wrap i{
    left:11px !important;
    font-size:13px !important;
}

/* Product search dropdown compact */
#newQuotationModal .product-search-results{
    max-height:180px !important;
    border-radius:10px !important;
}

#newQuotationModal .product-search-item{
    padding:8px 10px !important;
}

#newQuotationModal .product-search-name{
    font-size:12px !important;
}

#newQuotationModal .product-search-meta{
    font-size:10px !important;
}

/* Product table compact */
#newQuotationModal .quotation-product-table-wrap{
    border-radius:12px !important;
    max-height:190px !important;
    overflow:auto !important;
}

#newQuotationModal .quotation-product-table{
    min-width:760px !important;
}

#newQuotationModal .quotation-product-table thead th{
    padding:9px 8px !important;
    font-size:11px !important;
}

#newQuotationModal .quotation-product-table tbody td{
    padding:7px 8px !important;
    font-size:12px !important;
}

#newQuotationModal .quotation-product-table tbody td.text-center,
#newQuotationModal .quotation-product-table tbody td[colspan]{
    padding:18px 8px !important;
    font-size:13px !important;
}

/* Add empty row button small */
#newQuotationModal #addQuotationRowBtn{
    min-height:34px !important;
    height:34px !important;
    border-radius:9px !important;
    font-size:12px !important;
    padding:0 12px !important;
}

/* Delete row button small */
#newQuotationModal .delete-row-btn{
    width:34px !important;
    height:34px !important;
    border-radius:9px !important;
    font-size:13px !important;
}

/* Summary compact */
#newQuotationModal .quotation-summary-grid{
    grid-template-columns:1fr !important;
    gap:10px !important;
    padding:12px !important;
    border-radius:12px !important;
}

#newQuotationModal .summary-line{
    margin-bottom:7px !important;
    font-size:12px !important;
}

#newQuotationModal .summary-line.total-line strong{
    font-size:14px !important;
}

#newQuotationModal #totalAmountBig{
    font-size:20px !important;
}

/* Footer compact */
#newQuotationModal .quotation-modal-footer{
    padding:10px 16px !important;
    min-height:52px !important;
    gap:8px !important;
}

#newQuotationModal .secondary-btn,
#newQuotationModal .primary-btn{
    min-height:34px !important;
    height:34px !important;
    border-radius:9px !important;
    font-size:12px !important;
    padding:0 12px !important;
}

/* Close button small */
#newQuotationModal .btn-close{
    width:32px !important;
    height:32px !important;
    border-radius:10px !important;
}

#newQuotationModal .btn-close::before,
#newQuotationModal .btn-close::after{
    width:15px !important;
}

/* Responsive */
@media(max-width:760px){
    #newQuotationModal .quotation-small-modal,
    #newQuotationModal .modal-dialog{
        width:calc(100% - 18px) !important;
        max-width:calc(100% - 18px) !important;
        margin:.65rem auto !important;
    }

    #newQuotationModal .quotation-modal-body{
        max-height:64vh !important;
    }

    #newQuotationModal .quotation-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #newQuotationModal .secondary-btn,
    #newQuotationModal .primary-btn{
        width:100% !important;
    }
}

/* =====================================================
   QUOTATIONS MOBILE FINAL FIX
   New Quotation full line + 2 cards + filter alignment
   Paste at VERY END before 
===================================================== */

@media (max-width:768px){

    body{
        overflow-x:hidden !important;
    }

    .content-wrapper{
        padding:16px 12px 24px 12px !important;
    }

    .page-header{
        display:flex !important;
        align-items:flex-start !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .page-header > div{
        width:100% !important;
    }

    .page-title{
        width:100% !important;
        font-size:24px !important;
        line-height:1.15 !important;
        margin-bottom:0 !important;
    }

    .page-subtitle{
        font-size:12px !important;
        line-height:1.3 !important;
        margin-top:5px !important;
    }

    /* New Quotation button */
    .page-header .primary-btn{
        width:100% !important;
        min-width:0 !important;
        height:44px !important;
        padding:0 10px !important;
        border-radius:14px !important;
        font-size:13px !important;
        justify-content:center !important;
        white-space:nowrap !important;
    }

    .page-header .primary-btn i{
        font-size:14px !important;
        margin-right:2px !important;
    }

    /* Cards 2 per row */
    .stats-row{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    .stat-card{
        min-height:86px !important;
        border-radius:16px !important;
        padding:12px 10px !important;
        gap:6px !important;
        display:flex !important;
        flex-direction:column !important;
        align-items:flex-start !important;
        justify-content:center !important;
        overflow:hidden !important;
    }

    .stat-card > div{
        width:100% !important;
        min-width:0 !important;
        position:relative !important;
        z-index:3 !important;
    }

    .stat-label{
        font-size:12px !important;
        line-height:1.15 !important;
        font-weight:900 !important;
        margin:0 !important;
        white-space:normal !important;
    }

    .stat-value{
        width:100% !important;
        text-align:left !important;
        font-size:19px !important;
        line-height:1.08 !important;
        margin-top:6px !important;
        word-break:break-word !important;
        overflow-wrap:anywhere !important;
        position:relative !important;
        z-index:3 !important;
    }

    .stat-card::after{
        width:48px !important;
        height:48px !important;
        right:8px !important;
        top:10px !important;
        border-radius:16px !important;
        opacity:.35 !important;
    }

    /* Top controls mobile */
    .top-controls{
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:10px !important;
        margin-bottom:14px !important;
    }

    /* Filter box 2 columns */
    .filter-bar{
        display:grid !important;
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
        padding:12px !important;
        border-radius:18px !important;
        margin-bottom:0 !important;
        align-items:center !important;
    }

    .search-wrap{
        grid-column:1 / -1 !important;
        width:100% !important;
        min-width:0 !important;
    }

    .search-wrap input{
        width:100% !important;
        min-width:0 !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:12px !important;
        padding-left:38px !important;
        padding-right:10px !important;
    }

    .search-wrap i{
        left:13px !important;
        font-size:14px !important;
    }

    .filter-select{
        width:100% !important;
        min-width:0 !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:12px !important;
        padding:0 10px !important;
    }

    /* Filter + Clear one line */
    .filter-bar .filter-btn,
    .filter-bar .secondary-btn{
        width:100% !important;
        min-width:0 !important;
        height:44px !important;
        border-radius:14px !important;
        font-size:13px !important;
        padding:0 10px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        white-space:nowrap !important;
    }

    /* Bulk action box */
    .bulk-actions-top{
        display:grid !important;
        grid-template-columns:1fr 1fr !important;
        gap:10px !important;
        padding:12px !important;
        border-radius:18px !important;
        width:100% !important;
        min-width:0 !important;
        align-items:center !important;
    }

    .bulk-actions-top .bulk-select{
        width:100% !important;
        min-width:0 !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:12px !important;
        padding:0 10px !important;
    }

    .bulk-actions-top .apply-btn{
        width:100% !important;
        min-width:0 !important;
        height:42px !important;
        border-radius:13px !important;
        font-size:12px !important;
        padding:0 10px !important;
        justify-content:center !important;
        white-space:nowrap !important;
    }

    .section-title{
        font-size:20px !important;
        margin:12px 0 12px !important;
    }

    .table-card{
        border-radius:18px !important;
        margin-top:0 !important;
    }
}

/* Extra small 328px / 360px */
@media (max-width:380px){

    .content-wrapper{
        padding-left:10px !important;
        padding-right:10px !important;
    }

    .page-title{
        font-size:23px !important;
    }

    .page-subtitle{
        font-size:11px !important;
    }

    .page-header .primary-btn{
        height:42px !important;
        font-size:12px !important;
        border-radius:13px !important;
    }

    .stats-row{
        gap:9px !important;
    }

    .stat-card{
        min-height:82px !important;
        padding:10px 9px !important;
    }

    .stat-label{
        font-size:11px !important;
    }

    .stat-value{
        font-size:17px !important;
    }

    .filter-bar,
    .bulk-actions-top{
        gap:9px !important;
        padding:11px !important;
    }

    .search-wrap input,
    .filter-select,
    .bulk-actions-top .bulk-select{
        height:40px !important;
        font-size:11px !important;
    }

    .filter-bar .filter-btn,
    .filter-bar .secondary-btn,
    .bulk-actions-top .apply-btn{
        height:42px !important;
        font-size:12px !important;
    }
}

/* =====================================================
   QUOTATIONS MOBILE FINAL OVERRIDE
   3 cards in one row + Clear button with Filter
   Paste at VERY END before
===================================================== */

@media (max-width:768px){

    .content-wrapper{
        padding:14px 10px 24px 10px !important;
    }

    .page-header{
        margin-bottom:12px !important;
        gap:8px !important;
    }

    .page-title{
        font-size:23px !important;
        line-height:1.15 !important;
    }

    .page-subtitle{
        font-size:11px !important;
        margin-top:4px !important;
    }

    .page-header .primary-btn{
        height:42px !important;
        font-size:12px !important;
        border-radius:13px !important;
    }

    /* 3 cards in one line */
    .stats-row{
        display:grid !important;
        grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
        gap:7px !important;
        margin-bottom:12px !important;
    }

    .stat-card{
        min-height:74px !important;
        padding:9px 7px !important;
        border-radius:13px !important;
        gap:4px !important;
        display:flex !important;
        flex-direction:column !important;
        align-items:flex-start !important;
        justify-content:center !important;
        overflow:hidden !important;
    }

    .stat-card > div{
        width:100% !important;
        min-width:0 !important;
    }

    .stat-label{
        font-size:10px !important;
        line-height:1.12 !important;
        font-weight:900 !important;
        display:block !important;
        white-space:normal !important;
        word-break:break-word !important;
    }

    .stat-value{
        font-size:15px !important;
        line-height:1 !important;
        margin-top:5px !important;
        text-align:left !important;
        width:100% !important;
    }

    .stat-card::after{
        width:38px !important;
        height:38px !important;
        right:5px !important;
        top:8px !important;
        border-radius:12px !important;
        opacity:.28 !important;
    }

    /* filter box compact */
    .top-controls{
        grid-template-columns:1fr !important;
        gap:9px !important;
        margin-bottom:12px !important;
    }

    .filter-bar{
        display:grid !important;
        grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
        gap:8px !important;
        padding:10px !important;
        border-radius:16px !important;
        align-items:center !important;
    }

    /* search full width */
    .filter-bar .search-wrap{
        grid-column:1 / -1 !important;
        width:100% !important;
        min-width:0 !important;
    }

    .search-wrap input{
        height:40px !important;
        font-size:11px !important;
        border-radius:12px !important;
        padding-left:36px !important;
        padding-right:9px !important;
    }

    .search-wrap i{
        left:12px !important;
        font-size:13px !important;
    }

    /* Status + Filter + Clear same line */
    .filter-select,
    .filter-bar .filter-btn,
    .filter-bar .secondary-btn{
        width:100% !important;
        min-width:0 !important;
        height:40px !important;
        border-radius:12px !important;
        font-size:11px !important;
        padding:0 6px !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        white-space:nowrap !important;
    }

    .filter-bar .filter-btn i,
    .filter-bar .secondary-btn i{
        font-size:12px !important;
        margin-right:2px !important;
    }

    /* Bulk action box compact */
    .bulk-actions-top{
        display:grid !important;
        grid-template-columns:1fr 1fr !important;
        gap:8px !important;
        padding:10px !important;
        border-radius:16px !important;
        width:100% !important;
        min-width:0 !important;
    }

    .bulk-actions-top .bulk-select,
    .bulk-actions-top .apply-btn{
        width:100% !important;
        min-width:0 !important;
        height:40px !important;
        border-radius:12px !important;
        font-size:11px !important;
        padding:0 8px !important;
    }

    .section-title{
        font-size:18px !important;
        margin:10px 0 10px !important;
    }
}

/* 328px screen extra compact */
@media (max-width:360px){

    .content-wrapper{
        padding-left:9px !important;
        padding-right:9px !important;
    }

    .stats-row{
        gap:6px !important;
    }

    .stat-card{
        min-height:70px !important;
        padding:8px 6px !important;
    }

    .stat-label{
        font-size:9px !important;
    }

    .stat-value{
        font-size:14px !important;
    }

    .filter-bar{
        gap:7px !important;
        padding:9px !important;
    }

    .filter-select,
    .filter-bar .filter-btn,
    .filter-bar .secondary-btn{
        height:38px !important;
        font-size:10px !important;
        padding:0 5px !important;
    }

    .search-wrap input{
        height:38px !important;
        font-size:10px !important;
    }
}

.icon-edit{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.26) !important;
}

.icon-edit:hover{
    background:rgba(245,158,11,.24) !important;
    color:#fde68a !important;
}

.actions-cell{
    min-width:240px !important;
}

.action-group{
    flex-wrap:nowrap !important;
}
/* =========================================================
   ONEERP QUOTATIONS PAGE LIGHT MODE
   MUST REMAIN AT THE VERY END OF MAIN STYLE
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   MAIN PAGE AND LAYOUT
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] .container-scroll,
html[data-pos-theme="light"] .main-panel{
    background:#f3f6fb !important;
}

/* Sidebar */
/*html[data-pos-theme="light"] .sidebar,*/
/*html[data-pos-theme="light"] .sidebar .sidebar-wrap,*/
/*html[data-pos-theme="light"] #dashboardSidebar .sidebar-wrap,*/
/*html[data-pos-theme="light"] #sidebar .sidebar-wrap{*/
/*    background:#ffffff !important;*/
/*    border-right-color:#e2e8f0 !important;*/
/*}*/

/* Topbar */
html[data-pos-theme="light"] .topbar{
    background:#ffffff !important;
    border-bottom-color:#e2e8f0 !important;
    box-shadow:0 4px 18px rgba(15,23,42,.06) !important;
}

/* Main background */
html[data-pos-theme="light"] .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.09),
            transparent 28%
        ),
        radial-gradient(
            circle at 78% 2%,
            rgba(59,130,246,.06),
            transparent 30%
        ),
        radial-gradient(
            circle at 92% 86%,
            rgba(245,158,11,.045),
            transparent 26%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 42%,
            #faf7ff 72%,
            #f8fafc 100%
        ) !important;
}

/* Background grid */
html[data-pos-theme="light"] .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.035) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ) !important;
    opacity:.55 !important;
}

/* Purple glow light */
html[data-pos-theme="light"] .content-wrapper::after{
    background:radial-gradient(
        circle,
        rgba(124,58,237,.08),
        transparent 68%
    ) !important;
    opacity:.45 !important;
}

/* =========================================================
   PAGE HEADINGS
========================================================= */

html[data-pos-theme="light"] .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] .page-subtitle{
    color:#64748b !important;
}

html[data-pos-theme="light"] .section-title{
    color:#0f172a !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

/* Colored cards colored hi rahenge */
html[data-pos-theme="light"] .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] .stat-card .stat-label,
html[data-pos-theme="light"] .stat-card .stat-value{
    color:#ffffff !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .stat-card:hover{
    box-shadow:
        0 18px 42px rgba(15,23,42,.18),
        0 0 0 3px rgba(124,58,237,.08) !important;
}

/* =========================================================
   BUTTONS
========================================================= */

html[data-pos-theme="light"] .primary-btn{
    background:linear-gradient(
        135deg,
        #059669,
        #16a34a
    ) !important;
    color:#ffffff !important;
    border-color:#16a34a !important;
}

html[data-pos-theme="light"] .filter-btn,
html[data-pos-theme="light"] .apply-btn{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
    box-shadow:0 10px 22px rgba(124,58,237,.18) !important;
}

html[data-pos-theme="light"] .secondary-btn{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] .secondary-btn:hover{
    background:#e2e8f0 !important;
    color:#0f172a !important;
}

/* =========================================================
   FILTER AND BULK ACTION BOXES
========================================================= */

html[data-pos-theme="light"] .filter-bar,
html[data-pos-theme="light"] .bulk-actions-top{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.05),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Search, status and bulk selects */
html[data-pos-theme="light"] .search-wrap input,
html[data-pos-theme="light"] .filter-select,
html[data-pos-theme="light"] .bulk-select{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .search-wrap input:hover,
html[data-pos-theme="light"] .filter-select:hover,
html[data-pos-theme="light"] .bulk-select:hover{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#a78bfa !important;
}

html[data-pos-theme="light"] .search-wrap input:focus,
html[data-pos-theme="light"] .filter-select:focus,
html[data-pos-theme="light"] .bulk-select:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.13) !important;
}

html[data-pos-theme="light"] .search-wrap input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] .search-wrap i{
    color:#64748b !important;
}

html[data-pos-theme="light"] .filter-select option,
html[data-pos-theme="light"] .bulk-select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* =========================================================
   QUOTATION TABLE CARD
========================================================= */

html[data-pos-theme="light"] .table-card{
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(124,58,237,.04),
            transparent 34%
        ),
        #ffffff !important;
    border-color:#dce4ef !important;
    box-shadow:0 12px 32px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Main table */
html[data-pos-theme="light"] .quotation-table{
    background:#ffffff !important;
}

/* Table header */
html[data-pos-theme="light"] .quotation-table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom-color:#cbd5e1 !important;
    border-right-color:#e2e8f0 !important;
    opacity:1 !important;
}

/* Table rows */
html[data-pos-theme="light"] .quotation-table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-top-color:#e8edf4 !important;
    opacity:1 !important;
}

/* Row hover */
html[data-pos-theme="light"] .quotation-table tbody tr:hover td{
    background:#f6f2ff !important;
    color:#0f172a !important;
}

/* Empty result row */
html[data-pos-theme="light"] .quotation-table td.text-center,
html[data-pos-theme="light"] .quotation-table td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Quotation link */
html[data-pos-theme="light"] .quotation-no-link{
    color:#2563eb !important;
}

html[data-pos-theme="light"] .quotation-no-link:hover{
    color:#6d28d9 !important;
}

/* Amount */
html[data-pos-theme="light"] .amount-text{
    color:#0f172a !important;
}

/* Bootstrap muted date icon/text */
html[data-pos-theme="light"] .quotation-table .text-muted{
    color:#64748b !important;
}

/* =========================================================
   STATUS BADGES
========================================================= */

html[data-pos-theme="light"] .status-draft{
    background:#eff6ff !important;
    color:#1d4ed8 !important;
    border-color:#bfdbfe !important;
}

html[data-pos-theme="light"] .status-billed{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

/* =========================================================
   ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] .icon-action{
    box-shadow:0 5px 12px rgba(15,23,42,.07) !important;
}

/* View */
html[data-pos-theme="light"] .icon-view{
    background:#f1ebff !important;
    color:#6d28d9 !important;
    border-color:#c4b5fd !important;
}

/* Edit */
html[data-pos-theme="light"] .icon-edit{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

/* Convert and WhatsApp */
html[data-pos-theme="light"] .icon-copy,
html[data-pos-theme="light"] .icon-whatsapp{
    background:#ecfdf5 !important;
    color:#059669 !important;
    border-color:#a7f3d0 !important;
}

/* Delete */
html[data-pos-theme="light"] .icon-delete,
html[data-pos-theme="light"] .icon-action.only-bin{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

/* =========================================================
   TABLE FOOTER AND PAGINATION
========================================================= */

html[data-pos-theme="light"] .table-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] .table-footer-left{
    color:#64748b !important;
}

html[data-pos-theme="light"] .page-nav-btn,
html[data-pos-theme="light"] .page-number-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"] .page-nav-btn:hover,
html[data-pos-theme="light"] .page-number-btn:hover{
    background:#f3efff !important;
    color:#6d28d9 !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] .page-number-btn.active{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

/* =========================================================
   ALERTS
========================================================= */

html[data-pos-theme="light"] .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border-color:#a7f3d0 !important;
}

html[data-pos-theme="light"] .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] .alert-warning{
    background:#fffbeb !important;
    color:#b45309 !important;
    border-color:#fde68a !important;
}

/* =========================================================
   NEW QUOTATION MODAL LIGHT MODE
========================================================= */

html[data-pos-theme="light"] #newQuotationModal .modal-content{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#d8e0eb !important;
    box-shadow:
        0 28px 80px rgba(15,23,42,.20),
        0 0 0 1px rgba(124,58,237,.06) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Modal header */
html[data-pos-theme="light"]
#newQuotationModal .quotation-modal-header{
    background:#f8fafc !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] #newQuotationModal .modal-title{
    color:#0f172a !important;
}

/* Modal body */
html[data-pos-theme="light"]
#newQuotationModal .quotation-modal-body{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Modal footer */
html[data-pos-theme="light"]
#newQuotationModal .quotation-modal-footer{
    background:#f8fafc !important;
    border-top-color:#e2e8f0 !important;
}

/* Modal labels */
html[data-pos-theme="light"] #newQuotationModal .field-label{
    color:#334155 !important;
}

/* Modal inputs */
html[data-pos-theme="light"]
#newQuotationModal .form-control-custom,

html[data-pos-theme="light"]
#newQuotationModal .table-input,

html[data-pos-theme="light"]
#newQuotationModal .table-select,

html[data-pos-theme="light"]
#newQuotationModal .form-select,

html[data-pos-theme="light"]
#newQuotationModal input[type="date"],

html[data-pos-theme="light"]
#newQuotationModal input[type="text"],

html[data-pos-theme="light"]
#newQuotationModal input[type="number"],

html[data-pos-theme="light"]
#newQuotationModal select{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#cbd5e1 !important;
    box-shadow:none !important;
}

/* Input focus */
html[data-pos-theme="light"]
#newQuotationModal .form-control-custom:focus,

html[data-pos-theme="light"]
#newQuotationModal .table-input:focus,

html[data-pos-theme="light"]
#newQuotationModal .table-select:focus,

html[data-pos-theme="light"]
#newQuotationModal .form-select:focus,

html[data-pos-theme="light"]
#newQuotationModal input:focus,

html[data-pos-theme="light"]
#newQuotationModal select:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.14) !important;
}

html[data-pos-theme="light"]
#newQuotationModal input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"]
#newQuotationModal .input-icon-wrap i{
    color:#7c3aed !important;
}

/* Product search results */
html[data-pos-theme="light"]
#newQuotationModal .product-search-results{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
    box-shadow:0 18px 42px rgba(15,23,42,.16) !important;
}

html[data-pos-theme="light"]
#newQuotationModal .product-search-item{
    background:#ffffff !important;
    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"]
#newQuotationModal .product-search-item:hover{
    background:#f3efff !important;
}

html[data-pos-theme="light"]
#newQuotationModal .product-search-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"]
#newQuotationModal .product-search-meta{
    color:#64748b !important;
}

/* Product table and summary */
html[data-pos-theme="light"]
#newQuotationModal .quotation-product-table-wrap,

html[data-pos-theme="light"]
#newQuotationModal .quotation-summary-grid{
    background:#f8fafc !important;
    border-color:#dce4ef !important;
    box-shadow:0 6px 18px rgba(15,23,42,.05) !important;
}

/* Product table header */
html[data-pos-theme="light"]
#newQuotationModal .quotation-product-table thead th{
    background:#f1f5f9 !important;
    color:#334155 !important;
    border-bottom-color:#cbd5e1 !important;
}

/* Product table body */
html[data-pos-theme="light"]
#newQuotationModal .quotation-product-table tbody td{
    background:#ffffff !important;
    color:#1e293b !important;
    border-top-color:#e2e8f0 !important;
}

html[data-pos-theme="light"]
#newQuotationModal .quotation-product-table .text-muted{
    color:#64748b !important;
}

html[data-pos-theme="light"]
#newQuotationModal .item-amount-text{
    color:#047857 !important;
}

/* Summary text */
html[data-pos-theme="light"]
#newQuotationModal .summary-line{
    color:#334155 !important;
}

html[data-pos-theme="light"]
#newQuotationModal .summary-line strong{
    color:#0f172a !important;
}

html[data-pos-theme="light"]
#newQuotationModal .summary-line.grand-total-line{
    border-top-color:#cbd5e1 !important;
}

html[data-pos-theme="light"]
#newQuotationModal #totalAmountBig,

html[data-pos-theme="light"]
#newQuotationModal #totalText{
    color:#7c3aed !important;
}

/* Modal Cancel button */
html[data-pos-theme="light"]
#newQuotationModal .secondary-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;
    border-color:#cbd5e1 !important;
}

/* Modal save button */
html[data-pos-theme="light"]
#newQuotationModal .primary-btn{
    background:linear-gradient(
        135deg,
        #8b5cf6,
        #6d28d9
    ) !important;
    color:#ffffff !important;
    border-color:#8b5cf6 !important;
}

/* Close button */
html[data-pos-theme="light"]
#newQuotationModal .btn-close{
    background:#ffffff !important;
    border-color:#cbd5e1 !important;
}

html[data-pos-theme="light"]
#newQuotationModal .btn-close::before,

html[data-pos-theme="light"]
#newQuotationModal .btn-close::after{
    background:#334155 !important;
}

/* Modal scrollbar */
html[data-pos-theme="light"]
#newQuotationModal .quotation-modal-body::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"]
#newQuotationModal .quotation-modal-body::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
}

/* Main page scrollbars */
html[data-pos-theme="light"]
.table-responsive::-webkit-scrollbar-track,

html[data-pos-theme="light"]
.content-wrapper::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"]
.table-responsive::-webkit-scrollbar-thumb,

html[data-pos-theme="light"]
.content-wrapper::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
}
</style>
</head>
<body>

<div class="container-scroll">
    <!--<div class="sidebar"></div>-->
 <div class="sidebar" id="dashboardSidebar">
         <div class="sidebar" id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>
    </div>
    
    <div class="main-panel">
        <div class="topbar"><?php include('partials/topbar.php'); ?></div>

        <div class="content-wrapper">
            <div class="page-shell">

                <?php if ($alertMessage !== ''): ?>
                    <div class="alert alert-<?= h($alertType) ?> mb-3"><?= h($alertMessage) ?></div>
                <?php endif; ?>

                <div class="page-header">
                    <div>
                        <h4 class="page-title">Quotation to Bill</h4>
                        <p class="page-subtitle">Find a quotation and convert it into a sale bill</p>
                    </div>

                    <button class="primary-btn" type="button" data-bs-toggle="modal" data-bs-target="#newQuotationModal">
                        <i class="bi bi-plus-lg"></i>
                        New Quotation
                    </button>
                </div>

                <div class="stats-row">
                    <div class="stat-card">
                        <div>
                            <div class="stat-label">Total Quotations</div>
                        </div>
                        <div class="stat-value"><?= (int)$totalRows ?></div>
                    </div>

                    <div class="stat-card">
                        <div>
                            <div class="stat-label">Pending Quotations</div>
                        </div>
                        <div class="stat-value"><?= (int)$pendingCount ?></div>
                    </div>

                    <div class="stat-card">
                        <div>
                            <div class="stat-label">Converted Quotations</div>
                        </div>
                        <div class="stat-value"><?= (int)$billedCount ?></div>
                    </div>
                </div>

                <div class="top-controls">
                    <form method="GET" class="filter-bar">
                        <div class="search-wrap">
                            <i class="bi bi-search"></i>
                            <input
                                type="text"
                                name="search"
                                placeholder="Enter quotation ID, customer, or phone..."
                                value="<?= h($search) ?>"
                            >
                        </div>

                        <select name="status" class="filter-select">
                            <option value="">All Status</option>
                            <option value="Draft" <?= $status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="View Bill" <?= $status === 'View Bill' ? 'selected' : '' ?>>View Bill</option>
                        </select>

                        <button class="filter-btn" type="submit">
                            <i class="bi bi-funnel"></i>
                            Filter
                        </button>

                        <a href="<?= h($_SERVER['PHP_SELF']) ?>" class="secondary-btn">
                            <i class="bi bi-arrow-clockwise"></i>
                            Clear
                        </a>
                    </form>

                    <div class="bulk-actions-top">
                        <select class="bulk-select" id="bulkAction" name="bulk_action" form="bulkActionForm">
                            <option value="">Bulk Actions</option>
                            <option value="convert">Convert Selected</option>
                            <option value="delete">Delete Selected</option>
                            <option value="print">Print Selected</option>
                        </select>

                        <button type="button" class="apply-btn" id="applyBulkBtn">
                            <i class="bi bi-check2-square"></i>
                            Apply
                        </button>
                    </div>
                </div>

                <h5 class="section-title">Quotations</h5>
            </div>

            <div class="content-grid">
                <div>
                    <form method="POST" id="bulkActionForm">
                        <input type="hidden" name="action" value="bulk_action">

                        <div class="table-card">
                            <div class="table-responsive">
                                <table class="quotation-table">
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" id="checkAllQuotations" class="table-check"></th>
                                            <th>Quotation #</th>
                                            <th>Date</th>
                                            <th>Customer Name</th>
                                            <th>Phone</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($quotations)): ?>
                                            <?php foreach ($quotations as $q): ?>
                                                <?php
                                                    $statusText = trim((string)$q['status']);
                                                    $statusLower = strtolower($statusText);
                                                    $isBilled = $statusLower === 'view bill';
                                                    $waText = "Quotation: " . ($q['quotation_no'] ?? '') . "\nCustomer: " . ($q['customer_name'] ?? '') . "\nAmount: Rs. " . number_format((float)$q['total_amount'], 2);
                                                    $waLink = 'https://wa.me/' . preg_replace('/\D+/', '', (string)($q['phone'] ?? '')) . '?text=' . rawurlencode($waText);
                                                ?>
                                                <tr data-id="<?= (int)$q['id'] ?>" data-amount="<?= (float)$q['total_amount'] ?>" data-status="<?= h($q['status']) ?>">
                                                    <td>
                                                        <input
                                                            type="checkbox"
                                                            class="quotation-row-check table-check"
                                                            name="selected_ids[]"
                                                            value="<?= (int)$q['id'] ?>"
                                                            data-id="<?= (int)$q['id'] ?>"
                                                            data-amount="<?= (float)$q['total_amount'] ?>"
                                                            data-status="<?= h($q['status']) ?>"
                                                        >
                                                    </td>

                                                   <td>
    <a
        class="quotation-no-link"
        href="<?= 'view_quotation.php?id=' . (int)$q['id'] ?>"
        target="_blank"
        title="View Quotation Bill"
    >
        <?= h($q['quotation_no']) ?>
    </a>
</td>

                                                    <td>
                                                        <i class="bi bi-calendar3 me-1 text-muted"></i>
                                                        <?= h(date('d-m-Y', strtotime($q['quotation_date']))) ?>
                                                    </td>

                                                    <td><?= h($q['customer_name']) ?></td>
                                                    <td><?= h($q['phone']) ?></td>

                                                    <td class="amount-text">Rs. <?= number_format((float)$q['total_amount'], 2) ?></td>

                                                    <td>
                                                        <?php if ($isBilled): ?>
                                                            <span class="status-badge status-billed">View Bill</span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-draft"><?= h($statusText ?: 'Draft') ?></span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td class="actions-cell">
                                                        <div class="action-group">
 <a
    href="<?= $isBilled ? 'view_bill.php?id=' . (int)$q['id'] : 'view_quotation.php?id=' . (int)$q['id'] ?>"
    class="icon-action icon-view"
    title="<?= $isBilled ? 'View Invoice' : 'View Quotation' ?>"
    target="_blank"
>
    <i class="bi bi-eye"></i>
</a>

<?php if (!$isBilled): ?>
    <a
        href="javascript:void(0)"
        class="icon-action icon-edit"
        title="Edit Quotation"
        data-id="<?= (int)$q['id'] ?>"
        data-customer="<?= h($q['customer_name']) ?>"
        data-phone="<?= h($q['phone']) ?>"
        data-items="<?= h($q['selected_items_json'] ?? '[]') ?>"
        onclick="openEditQuotation(this); return false;"
    >
        <i class="bi bi-pencil-square"></i>
    </a>
<?php else: ?>
    <a
        href="javascript:void(0)"
        class="icon-action icon-edit disabled-convert"
        title="Converted quotation cannot be edited here"
        onclick="alert('Ye quotation invoice me convert ho chuki hai. Invoice edit karna ho to invoice page se karo.'); return false;"
    >
        <i class="bi bi-pencil-square"></i>
    </a>
<?php endif; ?>


                                                            <a
                                                                href="#"
                                                                class="icon-action icon-copy <?= $isBilled ? 'disabled-convert' : '' ?>"
                                                                title="<?= $isBilled ? 'Already Converted' : 'Convert to Invoice' ?>"
                                                                onclick="convertSingleQuotation(<?= (int)$q['id'] ?>, '<?= h($statusText) ?>'); return false;"
                                                            >
                                                                <i class="bi bi-receipt-cutoff"></i>
                                                            </a>

                                                          <a
    href="javascript:void(0)"
    class="icon-action icon-delete only-bin"
    title="Delete"
    onclick="toggleSingleDelete(<?= (int)$q['id'] ?>); return false;"
>
    <i class="bi bi-trash-fill"></i>
</a>

                                                            <a
                                                                href="<?= h($waLink) ?>"
                                                                target="_blank"
                                                                class="icon-action icon-whatsapp"
                                                                title="WhatsApp"
                                                            >
                                                                <i class="bi bi-whatsapp"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">No quotations found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="table-footer">
                                <div class="table-footer-left">
                                    Showing <?= $totalRows > 0 ? ($offset + 1) : 0 ?> to <?= min($offset + count($quotations), $totalRows) ?> of <?= $totalRows ?> quotations
                                </div>

                                <div class="pagination-wrap">
                                    <?php if ($page > 1): ?>
                                        <a class="page-nav-btn" href="<?= h(buildPageUrl($page - 1, $search, $status)) ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-nav-btn opacity-50">
                                            <i class="bi bi-chevron-left"></i>
                                        </span>
                                    <?php endif; ?>

                                    <?php
                                    $startPage = max(1, $page - 1);
                                    $endPage = min($totalPages, $page + 1);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                    ?>
                                        <a class="page-number-btn <?= $p === $page ? 'active' : '' ?>" href="<?= h(buildPageUrl($p, $search, $status)) ?>">
                                            <?= $p ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a class="page-nav-btn" href="<?= h(buildPageUrl($page + 1, $search, $status)) ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-nav-btn opacity-50">
                                            <i class="bi bi-chevron-right"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="singleDeleteForm" class="d-none">
    <input type="hidden" name="action" value="bulk_action">
    <input type="hidden" name="bulk_action" value="delete">
    <input type="hidden" name="selected_ids[]" id="singleDeleteId">
</form>

<form method="POST" id="singleConvertForm" class="d-none">
    <input type="hidden" name="action" value="bulk_action">
    <input type="hidden" name="bulk_action" value="convert">
    <input type="hidden" name="selected_ids[]" id="singleConvertId">
</form>

<div class="modal fade quotation-modal" id="newQuotationModal" tabindex="-1" aria-hidden="true">
   <div class="modal-dialog modal-dialog-centered quotation-small-modal">
        <div class="modal-content">
            <form method="POST" id="quotationCreateForm">
               <input type="hidden" name="action" value="create_quotation">
<input type="hidden" name="form_token" value="<?= h($currentFormToken) ?>">
<input type="hidden" name="draft_id" id="draftIdInput" value="">
<input type="hidden" name="edit_mode" id="editModeInput" value="0">
<input type="hidden" name="customer_id" id="customerIdInput" value="">
<input type="hidden" name="total_amount" id="totalAmountInput" value="0">
<input type="hidden" name="selected_items_json" id="selectedItemsJson" value="">

                <div class="modal-header quotation-modal-header">
                    <h5 class="modal-title">New Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body quotation-modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="field-label">Select Customer</label>
                            <div class="input-icon-wrap">
                                <i class="bi bi-person"></i>
                                <select name="customer_name" id="customerSelect" class="form-control-custom with-icon" required>
                                    <option value="">Select Customer...</option>
                                    <option value="Walk-In Customer" data-id="" data-phone="" data-address="">Walk-In Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option
                                            value="<?= h($customer['name']) ?>"
                                            data-id="<?= (int)$customer['id'] ?>"
                                            data-phone="<?= h($customer['phone']) ?>"
                                            data-address="<?= h($customer['address']) ?>"
                                        >
                                            <?= h($customer['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="field-label">Phone</label>
                            <input type="text" name="phone" id="customerPhoneInput" class="form-control-custom" placeholder="Customer phone">
                        </div>

                        <div class="col-md-3">
                            <label class="field-label">Quotation Date</label>
                            <input type="date" class="form-control-custom" value="<?= date('Y-m-d') ?>" readonly>
                        </div>

                        <div class="col-12">
                            <label class="field-label">Address</label>
                            <input type="text" name="address" id="customerAddressInput" class="form-control-custom" placeholder="Customer address">
                        </div>

                          <div class="col-12">
                            <label class="field-label">Search Product / Item</label>
                            <div class="product-search-wrap">
                                <input type="text" id="productSearchInput" class="form-control-custom" placeholder="Search item by name, sku, or type...">
                                <div id="productSearchResults" class="product-search-results d-none"></div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="quotation-product-table-wrap">
                                <table class="quotation-product-table">
                                    <thead>
                                        <tr>
                                            <th style="width:30%;">Item</th>
                                            <th style="width:12%;">SKU</th>
                                            <th style="width:10%;">Type</th>
                                            <th style="width:10%;">Qty</th>
                                            <th style="width:14%;">Rate</th>
                                            <th style="width:14%;">Amount</th>
                                            <th style="width:10%;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="quotationItemsBody">
                                        <tr class="empty-row">
                                            <td colspan="7" class="text-center text-muted py-4">No items added yet</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3">
                                <button type="button" class="secondary-btn" id="addQuotationRowBtn">
                                    <i class="bi bi-plus-circle"></i>
                                    Add Empty Row
                                </button>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="quotation-summary-grid">
                                <div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="field-label">Discount <small>(Optional)</small></label>
                                            <input type="number" step="0.01" min="0" id="discountField" class="form-control-custom" value="0" placeholder="0">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="field-label">Tax % <small>(Optional)</small></label>
                                            <input type="number" step="0.01" min="0" id="taxPercentField" class="form-control-custom" value="0" placeholder="0">
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <div class="summary-line">
                                        <span>Subtotal</span>
                                        <strong id="subtotalText">0.00 PKR</strong>
                                    </div>

                                    <div class="summary-line">
                                        <span>Discount</span>
                                        <strong id="discountText">0.00 PKR</strong>
                                    </div>

                                    <div class="summary-line">
                                        <span>Tax <span id="taxTextLeft">(0%)</span></span>
                                        <strong id="taxText">0.00 PKR</strong>
                                    </div>

                                    <div class="summary-line grand-total-line total-line">
                                        <span>Total Amount</span>
                                        <strong id="totalText">0.00 PKR</strong>
                                    </div>

                                    <div class="mt-2">
                                        <div id="totalAmountBig">0.00 PKR</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="quotation-modal-footer d-flex justify-content-end gap-2">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="primary-btn" id="saveQuotationBtn">
                        <i class="bi bi-check2-circle"></i>
                        Save Quotation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const EXISTING_ITEMS = <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const checkAllQuotations = document.getElementById('checkAllQuotations');
    const bulkActionForm = document.getElementById('bulkActionForm');
    const bulkActionSelect = document.getElementById('bulkAction');
    const applyBulkBtn = document.getElementById('applyBulkBtn');

    function getSelectedCheckboxes() {
        return Array.from(document.querySelectorAll('.quotation-row-check:checked'));
    }

    function getSelectedQuotationIds() {
        return getSelectedCheckboxes().map(cb => cb.value);
    }

    function updateSelectionSummary() {
        const selected = getSelectedCheckboxes();

        if (checkAllQuotations) {
            const allChecks = document.querySelectorAll('.quotation-row-check');
            checkAllQuotations.checked = allChecks.length > 0 && selected.length === allChecks.length;
        }
    }

    if (checkAllQuotations) {
        checkAllQuotations.addEventListener('change', function () {
            document.querySelectorAll('.quotation-row-check').forEach(cb => {
                cb.checked = this.checked;
            });
            updateSelectionSummary();
        });
    }

    document.querySelectorAll('.quotation-row-check').forEach(cb => {
        cb.addEventListener('change', function () {
            if (!this.checked && checkAllQuotations) {
                checkAllQuotations.checked = false;
            }
            updateSelectionSummary();
        });
    });

    if (applyBulkBtn) {
        applyBulkBtn.addEventListener('click', function () {
            const action = bulkActionSelect.value;
            const selected = getSelectedQuotationIds();

            if (!action) {
                alert('Please select a bulk action');
                return;
            }

            if (selected.length === 0) {
                alert('Please select at least one quotation');
                return;
            }

            if (action === 'print') {
                const firstSelectedViewBill = getSelectedCheckboxes().find(cb =>
                    String(cb.dataset.status || '').trim().toLowerCase() === 'view bill'
                );

                if (firstSelectedViewBill) {
                    window.open('view_bill.php?id=' + firstSelectedViewBill.dataset.id, '_blank');
                } else {
                    window.print();
                }
                return;
            }

            if (action === 'delete' && !confirm('Are you sure you want to delete selected quotations?')) {
                return;
            }

            if (action === 'convert' && !confirm('Are you sure you want to convert selected quotations into invoice?')) {
                return;
            }

            bulkActionForm.submit();
        });
    }

    function toggleSingleDelete(id) {
        if (!confirm('Are you sure you want to delete this quotation?')) return;
        const input = document.getElementById('singleDeleteId');
        const form = document.getElementById('singleDeleteForm');
        if (input && form) {
            input.value = id;
            form.submit();
        }
    }

    function convertSingleQuotation(id, status) {
        const normalizedStatus = String(status || '').trim().toLowerCase();

        if (normalizedStatus === 'view bill') {
            alert('Ye quotation pehle se invoice me convert ho chuki hai.');
            return;
        }

        if (!confirm('Do you want to convert this quotation into an invoice?')) {
            return;
        }

        const input = document.getElementById('singleConvertId');
        const form = document.getElementById('singleConvertForm');

        if (input && form) {
            input.value = id;
            form.submit();
        }
    }

    /* ================= MODAL / CUSTOMER / ITEMS ================= */
    const quotationItemsBody = document.getElementById('quotationItemsBody');
    const addQuotationRowBtn = document.getElementById('addQuotationRowBtn');
    const discountField = document.getElementById('discountField');
    const taxPercentField = document.getElementById('taxPercentField');
    const totalAmountInput = document.getElementById('totalAmountInput');
    const selectedItemsJson = document.getElementById('selectedItemsJson');

    const subtotalText = document.getElementById('subtotalText');
    const discountText = document.getElementById('discountText');
    const taxText = document.getElementById('taxText');
    const taxTextLeft = document.getElementById('taxTextLeft');
    const totalText = document.getElementById('totalText');
    const totalAmountBig = document.getElementById('totalAmountBig');

    const productSearchInput = document.getElementById('productSearchInput');
    const productSearchResults = document.getElementById('productSearchResults');

    const customerSelect = document.getElementById('customerSelect');
    const customerIdInput = document.getElementById('customerIdInput');
    const customerPhoneInput = document.getElementById('customerPhoneInput');
    const customerAddressInput = document.getElementById('customerAddressInput');

    const quotationCreateForm = document.getElementById('quotationCreateForm');
    const saveQuotationBtn = document.getElementById('saveQuotationBtn');
    const newQuotationModal = document.getElementById('newQuotationModal');

   let autoSaveTimer = null;
let lastDraftPayload = '';
let isManualSubmitting = false;
let autoSavedDraftId = null;
let isEditMode = false;

const editModeInput = document.getElementById('editModeInput');
const draftIdInput = document.getElementById('draftIdInput');

    function formatPKR(value) {
        return Number(value || 0).toFixed(2) + ' PKR';
    }

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function removeEmptyRow() {
        const emptyRow = quotationItemsBody?.querySelector('.empty-row');
        if (emptyRow) emptyRow.remove();
    }

    function ensureEmptyRow() {
        if (!quotationItemsBody) return;

        const realRows = quotationItemsBody.querySelectorAll('tr[data-item-row="1"]');
        if (realRows.length === 0) {
            quotationItemsBody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="7" class="text-center text-muted py-4">No items added yet</td>
                </tr>
            `;
        }
    }

    function buildRow(item = {}) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-item-row', '1');
        tr.innerHTML = `
            <td>
                <input type="hidden" class="item-id-input" value="${escapeHtml(item.id || '')}">
                <input type="text" class="table-input item-name-input" value="${escapeHtml(item.name || '')}" placeholder="Item name">
            </td>
            <td>
                <input type="text" class="table-input item-sku-input" value="${escapeHtml(item.sku || '')}" placeholder="SKU">
            </td>
            <td>
                <select class="table-select item-type-input">
                    <option value="product" ${String(item.type || 'product') === 'product' ? 'selected' : ''}>Product</option>
                    <option value="service" ${String(item.type || '') === 'service' ? 'selected' : ''}>Service</option>
                </select>
            </td>
            <td>
                <input type="number" step="1" min="1" class="table-input qty-input" value="${escapeHtml(item.qty || 1)}">
            </td>
            <td>
                <input type="number" step="1" min="1" class="table-input rate-input" value="${escapeHtml(item.rate || item.price || 0)}">
            </td>
            <td>
                <span class="item-amount-text">0.00</span>
            </td>
            <td class="text-center">
                <button type="button" class="delete-row-btn" title="Remove row">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        tr.querySelectorAll('.qty-input, .rate-input, .item-name-input, .item-sku-input, .item-type-input').forEach(el => {
            el.addEventListener('input', function () {
                calculateQuotationTotals();
                queueAutoSaveDraft();
            });
            el.addEventListener('change', function () {
                calculateQuotationTotals();
                queueAutoSaveDraft();
            });
        });

        tr.querySelector('.delete-row-btn').addEventListener('click', function () {
            tr.remove();
            ensureEmptyRow();
            calculateQuotationTotals();
            queueAutoSaveDraft();
        });

        updateRowAmount(tr);
        return tr;
    }

    function addQuotationRow(item = {}) {
        if (!quotationItemsBody) return;
        removeEmptyRow();
        quotationItemsBody.appendChild(buildRow(item));
        calculateQuotationTotals();
        queueAutoSaveDraft();
    }

    function updateRowAmount(row) {
        const qty = parseFloat(row.querySelector('.qty-input')?.value || 0);
        const rate = parseFloat(row.querySelector('.rate-input')?.value || 0);
        const amount = qty * rate;
        const amountEl = row.querySelector('.item-amount-text');

        if (amountEl) amountEl.textContent = amount.toFixed(2);
        return amount;
    }

    function collectSelectedItems() {
        const rows = Array.from(quotationItemsBody.querySelectorAll('tr[data-item-row="1"]'));

        return rows.map(row => {
            const qty = parseFloat(row.querySelector('.qty-input')?.value || 0);
            const rate = parseFloat(row.querySelector('.rate-input')?.value || 0);

            return {
                id: row.querySelector('.item-id-input')?.value || '',
                name: row.querySelector('.item-name-input')?.value || '',
                sku: row.querySelector('.item-sku-input')?.value || '',
                type: row.querySelector('.item-type-input')?.value || 'product',
                qty: qty,
                rate: rate,
                amount: qty * rate
            };
        }).filter(item => item.name.trim() !== '' && item.qty > 0);
    }

    function calculateQuotationTotals() {
        let subtotal = 0;
        const rows = Array.from(quotationItemsBody.querySelectorAll('tr[data-item-row="1"]'));

        rows.forEach(row => {
            subtotal += updateRowAmount(row);
        });

        const discount = parseFloat(discountField?.value || 0);
        const taxPercent = parseFloat(taxPercentField?.value || 0);
        const net = Math.max(subtotal - discount, 0);
        const tax = (net * taxPercent) / 100;
        const total = net + tax;

        if (subtotalText) subtotalText.textContent = formatPKR(subtotal);
        if (discountText) discountText.textContent = formatPKR(discount);
        if (taxText) taxText.textContent = formatPKR(tax);
        if (taxTextLeft) taxTextLeft.textContent = '(' + taxPercent + '%)';
        if (totalText) totalText.textContent = formatPKR(total);
        if (totalAmountBig) totalAmountBig.textContent = formatPKR(total);
        if (totalAmountInput) totalAmountInput.value = total.toFixed(2);
        if (selectedItemsJson) selectedItemsJson.value = JSON.stringify(collectSelectedItems());
    }

function renderProductResults(query) {
    if (!productSearchResults) return;

    const q = String(query || '').trim().toLowerCase();

    if (!q) {
        productSearchResults.innerHTML = '';
        productSearchResults.classList.add('d-none');
        return;
    }

    const safeItems = Array.isArray(EXISTING_ITEMS) ? EXISTING_ITEMS : [];

    const matched = safeItems.filter(item => {
        const name = String(item.name || '').toLowerCase();
        const sku = String(item.sku || '').toLowerCase();
        const type = String(item.type || '').toLowerCase();

        return name.includes(q) || sku.includes(q) || type.includes(q);
    }).slice(0, 20);

    if (matched.length === 0) {
        productSearchResults.innerHTML = `
            <div class="product-search-item">
                <div class="product-search-name">No item found</div>
                <div class="product-search-meta">Is client / branch me koi matching product nahi mila</div>
            </div>
        `;
        productSearchResults.classList.remove('d-none');
        return;
    }

    productSearchResults.innerHTML = matched.map(item => {
        const price = Number(item.price || 0);
        const stock = Number(item.stock_qty || 0);

        return `
            <div class="product-search-item"
                data-id="${escapeHtml(item.id)}"
                data-name="${escapeHtml(item.name)}"
                data-sku="${escapeHtml(item.sku)}"
                data-type="${escapeHtml(item.type || 'product')}"
                data-price="${escapeHtml(price)}">
                <div class="product-search-name">${escapeHtml(item.name || 'Unnamed Item')}</div>
                <div class="product-search-meta">
                    SKU: ${escapeHtml(item.sku || '-')} |
                    Type: ${escapeHtml(item.type || 'product')} |
                    Stock: ${stock.toFixed(2)} |
                    Price: ${price.toFixed(2)}
                </div>
            </div>
        `;
    }).join('');

    productSearchResults.classList.remove('d-none');

    productSearchResults.querySelectorAll('.product-search-item[data-id]').forEach(itemEl => {
        itemEl.addEventListener('click', function () {
            addQuotationRow({
                id: this.dataset.id || '',
                name: this.dataset.name || '',
                sku: this.dataset.sku || '',
                type: this.dataset.type || 'product',
                price: parseFloat(this.dataset.price || 0),
                rate: parseFloat(this.dataset.price || 0),
                qty: 1
            });

            productSearchInput.value = '';
            productSearchResults.innerHTML = '';
            productSearchResults.classList.add('d-none');
            queueAutoSaveDraft();
        });
    });
}

    function getDraftPayload() {
        const items = collectSelectedItems();
        const customerOption = customerSelect?.options?.[customerSelect.selectedIndex] || null;
        const customerName = customerSelect?.value?.trim() || '';
        const phone = customerPhoneInput?.value?.trim() || '';
        const address = customerAddressInput?.value?.trim() || '';
        const customerId = customerOption?.dataset?.id || '';
        const discount = parseFloat(discountField?.value || 0);
        const taxPercent = parseFloat(taxPercentField?.value || 0);
        const totalAmount = parseFloat(totalAmountInput?.value || 0);

        return {
            draft_id: autoSavedDraftId || '',
            customer_id: customerId,
            customer_name: customerName,
            phone: phone,
            address: address,
            discount: discount,
            tax_percent: taxPercent,
            total_amount: totalAmount,
            selected_items_json: JSON.stringify(items)
        };
    }

    function canAutoSaveDraft(payload) {
        const items = JSON.parse(payload.selected_items_json || '[]');
        return payload.customer_name !== '' && items.length > 0 && payload.total_amount > 0;
    }

 function queueAutoSaveDraft() {
    if (isManualSubmitting || isEditMode) return;

    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(() => {
        autoSaveDraft();
    }, 1200);
}

    function autoSaveDraft() {
        const payload = getDraftPayload();

        if (!canAutoSaveDraft(payload)) {
            return;
        }

        const payloadHash = JSON.stringify(payload);
        if (payloadHash === lastDraftPayload) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'auto_save_draft');
        formData.append('draft_id', payload.draft_id);
        formData.append('customer_id', payload.customer_id);
        formData.append('customer_name', payload.customer_name);
        formData.append('phone', payload.phone);
        formData.append('address', payload.address);
        formData.append('discount', payload.discount);
        formData.append('tax_percent', payload.tax_percent);
        formData.append('total_amount', payload.total_amount);
        formData.append('selected_items_json', payload.selected_items_json);

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(text => {
            lastDraftPayload = payloadHash;

            try {
                const data = JSON.parse(text);
                if (data.status === 'success' && data.draft_id) {
                    autoSavedDraftId = data.draft_id;
                }
            } catch (err) {
                console.log('Draft response is not JSON:', text);
            }
        })
        .catch(error => {
            console.error('Auto draft save failed:', error);
        });
    }

    function resetQuotationModalForm() {
       isEditMode = false;

if (editModeInput) editModeInput.value = '0';
if (draftIdInput) draftIdInput.value = '';

const modalTitle = newQuotationModal?.querySelector('.modal-title');
if (modalTitle) modalTitle.textContent = 'New Quotation';

if (saveQuotationBtn) {
    saveQuotationBtn.disabled = false;
    saveQuotationBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Save Quotation';
}

        autoSavedDraftId = null;
        lastDraftPayload = '';
        isManualSubmitting = false;
        clearTimeout(autoSaveTimer);

        if (quotationCreateForm) {
            quotationCreateForm.reset();
        }

        if (customerIdInput) customerIdInput.value = '';
        if (totalAmountInput) totalAmountInput.value = '0';
        if (selectedItemsJson) selectedItemsJson.value = '';

        if (quotationItemsBody) {
            quotationItemsBody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="7" class="text-center text-muted py-4">No items added yet</td>
                </tr>
            `;
        }

        if (discountField) discountField.value = 0;
        if (taxPercentField) taxPercentField.value = 0;

        calculateQuotationTotals();
    }

    if (productSearchInput) {
        productSearchInput.addEventListener('input', function () {
            renderProductResults(this.value);
        });
    }

    document.addEventListener('click', function (e) {
        if (
            productSearchResults &&
            productSearchInput &&
            !productSearchResults.contains(e.target) &&
            e.target !== productSearchInput
        ) {
            productSearchResults.classList.add('d-none');
        }
    });

    if (addQuotationRowBtn) {
        addQuotationRowBtn.addEventListener('click', function () {
            addQuotationRow({
                name: '',
                sku: '',
                type: 'product',
                qty: 1,
                rate: 0
            });
        });
    }

    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            if (!selected) return;

            if (customerIdInput) customerIdInput.value = selected.dataset.id || '';
            if (customerPhoneInput) customerPhoneInput.value = selected.dataset.phone || '';
            if (customerAddressInput) customerAddressInput.value = selected.dataset.address || '';

            calculateQuotationTotals();
            queueAutoSaveDraft();
        });
    }

    if (customerPhoneInput) {
        customerPhoneInput.addEventListener('input', queueAutoSaveDraft);
    }

    if (customerAddressInput) {
        customerAddressInput.addEventListener('input', queueAutoSaveDraft);
    }

    [discountField, taxPercentField].forEach(el => {
        el?.addEventListener('input', function () {
            calculateQuotationTotals();
            queueAutoSaveDraft();
        });
        el?.addEventListener('change', function () {
            calculateQuotationTotals();
            queueAutoSaveDraft();
        });
    });

    if (quotationCreateForm && saveQuotationBtn) {
        quotationCreateForm.addEventListener('submit', function (e) {
            const items = collectSelectedItems();

            if (items.length === 0) {
                e.preventDefault();
                alert('Please add at least one item');
                return;
            }

            if (parseFloat(totalAmountInput?.value || 0) <= 0) {
                e.preventDefault();
                alert('Total amount must be greater than zero');
                return;
            }

            isManualSubmitting = true;
            selectedItemsJson.value = JSON.stringify(items);

           if (autoSavedDraftId) {
    const hiddenDraftInput = document.getElementById('draftIdInput');
    if (hiddenDraftInput) {
        hiddenDraftInput.value = autoSavedDraftId;
    }
}

            saveQuotationBtn.disabled = true;
            saveQuotationBtn.innerText = 'Saving...';
        });
    }

    if (newQuotationModal) {
        newQuotationModal.addEventListener('hidden.bs.modal', function () {
            resetQuotationModalForm();
        });
    }

function selectCustomerForEdit(customerName, phone) {
    if (!customerSelect) return;

    let found = false;

    Array.from(customerSelect.options).forEach(option => {
        if (String(option.value).trim() === String(customerName).trim()) {
            option.selected = true;
            found = true;

            if (customerIdInput) customerIdInput.value = option.dataset.id || '';
            if (customerPhoneInput) customerPhoneInput.value = phone || option.dataset.phone || '';
            if (customerAddressInput) customerAddressInput.value = option.dataset.address || '';
        }
    });

    if (!found) {
        const tempOption = document.createElement('option');
        tempOption.value = customerName;
        tempOption.textContent = customerName;
        tempOption.selected = true;
        tempOption.dataset.id = '';
        tempOption.dataset.phone = phone || '';
        tempOption.dataset.address = '';

        customerSelect.appendChild(tempOption);

        if (customerIdInput) customerIdInput.value = '';
        if (customerPhoneInput) customerPhoneInput.value = phone || '';
        if (customerAddressInput) customerAddressInput.value = '';
    }
}

function openEditQuotation(btn) {
    if (!btn || !newQuotationModal) return;

    isEditMode = true;
    isManualSubmitting = false;
    clearTimeout(autoSaveTimer);
    lastDraftPayload = '';

    const quotationId = btn.dataset.id || '';
    const customerName = btn.dataset.customer || '';
    const phone = btn.dataset.phone || '';
    const itemsRaw = btn.dataset.items || '[]';

    autoSavedDraftId = quotationId;

    if (draftIdInput) draftIdInput.value = quotationId;
    if (editModeInput) editModeInput.value = '1';

    const modalTitle = newQuotationModal.querySelector('.modal-title');
    if (modalTitle) modalTitle.textContent = 'Edit Quotation';

    if (saveQuotationBtn) {
        saveQuotationBtn.disabled = false;
        saveQuotationBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Update Quotation';
    }

    selectCustomerForEdit(customerName, phone);

    if (quotationItemsBody) {
        quotationItemsBody.innerHTML = '';
    }

    let items = [];

    try {
        items = JSON.parse(itemsRaw || '[]');
    } catch (e) {
        items = [];
    }

    if (Array.isArray(items) && items.length > 0) {
        items.forEach(item => {
            addQuotationRow({
                id: item.id || '',
                name: item.name || '',
                sku: item.sku || '',
                type: item.type || 'product',
                qty: item.qty || 1,
                rate: item.rate || item.price || 0,
                price: item.rate || item.price || 0
            });
        });
    } else {
        ensureEmptyRow();
    }

    calculateQuotationTotals();

    const modalInstance = bootstrap.Modal.getOrCreateInstance(newQuotationModal);
    modalInstance.show();
}

    calculateQuotationTotals();
    updateSelectionSummary();
 
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;

    const savedState = localStorage.getItem('dashboard_sidebar_state');
    if (savedState === 'collapsed') {
        body.classList.add('dashboard-sidebar-collapsed');
    }

    const toggleSelectors = [
        '#sidebarToggle',
        '#toggleSidebar',
        '.sidebar-toggle',
        '.sidebar-toggler',
        '.topbar-menu-toggle',
        '.hamburger-btn',
        '[data-sidebar-toggle]',
        '.topbar .bi-list',
        '.topbar .fa-bars'
    ];

    let toggleBtn = null;

    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();

            body.classList.toggle('dashboard-sidebar-collapsed');

            if (body.classList.contains('dashboard-sidebar-collapsed')) {
                localStorage.setItem('dashboard_sidebar_state', 'collapsed');
            } else {
                localStorage.setItem('dashboard_sidebar_state', 'open');
            }
        });
    }
});
</script>
 <script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;

    const savedState = localStorage.getItem('dashboard_sidebar_state');
    if (savedState === 'collapsed') {
        body.classList.add('dashboard-sidebar-collapsed');
    }

    const toggleSelectors = [
        '#sidebarToggle',
        '#toggleSidebar',
        '.sidebar-toggle',
        '.sidebar-toggler',
        '.topbar-menu-toggle',
        '.hamburger-btn',
        '[data-sidebar-toggle]',
        '.topbar .bi-list',
        '.topbar .fa-bars'
    ];

    let toggleBtn = null;

    for (const selector of toggleSelectors) {
        toggleBtn = document.querySelector(selector);
        if (toggleBtn) break;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();

            body.classList.toggle('dashboard-sidebar-collapsed');

            if (body.classList.contains('dashboard-sidebar-collapsed')) {
                localStorage.setItem('dashboard_sidebar_state', 'collapsed');
            } else {
                localStorage.setItem('dashboard_sidebar_state', 'open');
            }
        });
    }
});
</script>   

</body>
</html>