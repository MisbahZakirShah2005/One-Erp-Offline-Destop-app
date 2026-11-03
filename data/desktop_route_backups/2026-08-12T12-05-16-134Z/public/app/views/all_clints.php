<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

/* ================= AUTH CHECK ================= */
// if(!isset($_SESSION['user_id'])){
//     die("User not logged in!");
// } 
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

/* ================= AJAX EDIT ================= */
if (
    isset($_POST['ajax']) &&
    $_POST['ajax'] === 'edit_client'
) {
    header('Content-Type: text/plain; charset=utf-8');

    /*
    |--------------------------------------------------------------------------
    | Submitted values
    |--------------------------------------------------------------------------
    */

    $clientId = (int)($_POST['client_id'] ?? 0);
    $adminId  = (int)($_SESSION['user_id'] ?? 0);

    $businessName = trim(
        (string)($_POST['business_name'] ?? '')
    );

    $businessAddress = trim(
        (string)($_POST['business_address'] ?? '')
    );

    $contactNumber = trim(
        (string)($_POST['contact_number'] ?? '')
    );

    $industryId = (int)($_POST['industry'] ?? 0);

    $newStatus = strtolower(
        trim((string)($_POST['status'] ?? ''))
    );

    $username = trim(
        (string)($_POST['username'] ?? '')
    );

    $email = trim(
        (string)($_POST['email'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | Basic validation
    |--------------------------------------------------------------------------
    */

    if ($clientId <= 0) {
        http_response_code(422);
        echo 'Invalid client ID.';
        exit;
    }

    if ($adminId <= 0) {
        http_response_code(401);
        echo 'Admin session not found.';
        exit;
    }

    if (
        $businessName === '' ||
        $businessAddress === '' ||
        $contactNumber === '' ||
        $industryId <= 0
    ) {
        http_response_code(422);
        echo 'Please complete all required fields.';
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Valid clients.status values used by this screen
    |--------------------------------------------------------------------------
    */

    $allowedStatuses = [
        'active',
        'inactive',
        'suspended'
    ];

    if (!in_array($newStatus, $allowedStatuses, true)) {
        http_response_code(422);
        echo 'Invalid client status.';
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Read previous client status
    |--------------------------------------------------------------------------
    */

    $oldStatusStmt = $conn->prepare("
        SELECT status
        FROM clients
        WHERE id = ?
        LIMIT 1
    ");

    if (!$oldStatusStmt) {
        http_response_code(500);
        echo 'Unable to prepare old status query: ' . $conn->error;
        exit;
    }

    $oldStatusStmt->bind_param(
        'i',
        $clientId
    );

    if (!$oldStatusStmt->execute()) {
        http_response_code(500);
        echo 'Unable to read client status: '
            . $oldStatusStmt->error;
        $oldStatusStmt->close();
        exit;
    }

    $oldStatusStmt->bind_result($oldStatus);

    if (!$oldStatusStmt->fetch()) {
        http_response_code(404);
        echo 'Client not found.';
        $oldStatusStmt->close();
        exit;
    }

    $oldStatusStmt->close();

    $oldStatus = strtolower(
        trim((string)$oldStatus)
    );

    $statusChanged = ($oldStatus !== $newStatus);

    /*
    |--------------------------------------------------------------------------
    | Update client and owner safely
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Status changed:
        | Save which admin changed it and at what time
        |--------------------------------------------------------------------------
        */

        if ($statusChanged) {
            $stmt = $conn->prepare("
                UPDATE clients
                SET
                    business_name = ?,
                    business_address = ?,
                    contact_number = ?,
                    industry_id = ?,
                    status = ?,
                    status_changed_by = ?,
                    status_changed_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Client update prepare failed: '
                    . $conn->error
                );
            }

            $stmt->bind_param(
                'sssisii',
                $businessName,
                $businessAddress,
                $contactNumber,
                $industryId,
                $newStatus,
                $adminId,
                $clientId
            );
        } else {

            /*
            |--------------------------------------------------------------------------
            | Status unchanged:
            | Old status_changed_by/status_changed_at remain untouched
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE clients
                SET
                    business_name = ?,
                    business_address = ?,
                    contact_number = ?,
                    industry_id = ?,
                    status = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Client update prepare failed: '
                    . $conn->error
                );
            }

            $stmt->bind_param(
                'sssisi',
                $businessName,
                $businessAddress,
                $contactNumber,
                $industryId,
                $newStatus,
                $clientId
            );
        }

        if (!$stmt->execute()) {
            throw new Exception(
                'Error updating client: '
                . $stmt->error
            );
        }

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Update first owner information
        |--------------------------------------------------------------------------
        | Important:
        | Client inactive hone par users.status change nahi karna.
        | Complete company clients.status ki wajah se freeze hogi.
        |--------------------------------------------------------------------------
        */

        if ($username !== '' || $email !== '') {
            $stmt2 = $conn->prepare("
                UPDATE users
                SET
                    name = ?,
                    email = ?,
                    phone = ?
                WHERE client_id = ?
                LIMIT 1
            ");

            if (!$stmt2) {
                throw new Exception(
                    'Owner update prepare failed: '
                    . $conn->error
                );
            }

            $stmt2->bind_param(
                'sssi',
                $username,
                $email,
                $contactNumber,
                $clientId
            );

            if (!$stmt2->execute()) {
                throw new Exception(
                    'Error updating owner: '
                    . $stmt2->error
                );
            }

            $stmt2->close();
        }

        $conn->commit();

        echo 'ok';
        exit;

    } catch (Throwable $exception) {
        $conn->rollback();

        http_response_code(500);

        echo 'Error updating client: '
            . $exception->getMessage();

        exit;
    }
}

/* ================= AJAX DELETE ================= */
if(isset($_POST['ajax']) && $_POST['ajax']=='delete_client'){
    $id = (int)$_POST['id'];
    $conn->begin_transaction();
    try{
        // Delete all users
        $stmt = $conn->prepare("DELETE FROM users WHERE client_id = ?");
        $stmt->bind_param("i",$id);
        if(!$stmt->execute()) throw new Exception("Error deleting users: ".$stmt->error);
        $stmt->close();

        // Delete client
        $stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
        $stmt->bind_param("i",$id);
        if(!$stmt->execute()) throw new Exception("Error deleting client: ".$stmt->error);
        $stmt->close();

        $conn->commit();
        echo "deleted";
    }catch(Exception $e){
        $conn->rollback();
        http_response_code(500);
        echo $e->getMessage();
    }
    exit;
}

/* ================= ADD CLIENT ================= */
if(isset($_POST['add_client'])){
    $conn->begin_transaction();
    try{
        // Insert client
        $stmt = $conn->prepare("INSERT INTO clients (business_name, business_address, contact_number, industry_id, status, created_at) VALUES (?,?,?,?,?,NOW())");
        if(!$stmt) throw new Exception("Prepare failed: ".$conn->error);

        $stmt->bind_param("sssis",
            $_POST['business_name'],
            $_POST['business_address'],
            $_POST['contact_number'],
            $_POST['industry'],
            $_POST['status']
        );

        if(!$stmt->execute()) throw new Exception("Error adding client: ".$stmt->error);
        $client_id = $stmt->insert_id;
        $stmt->close();

        // Insert user if provided
        if(!empty($_POST['username']) && !empty($_POST['email']) && !empty($_POST['password'])){
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO users (client_id,name,email,phone,password,status,created_at) VALUES (?,?,?,?,?,?,NOW())");
            if(!$stmt) throw new Exception("Prepare failed: ".$conn->error);

            $stmt->bind_param(
                "isssss",
                $client_id,
                $_POST['username'],
                $_POST['email'],
                $_POST['contact_number'],
                $pass,
                $_POST['status']
            );

            if(!$stmt->execute()) throw new Exception("Error adding user: ".$stmt->error);
            $user_id = $stmt->insert_id;
            $stmt->close();

            // Assign role if provided
            if(!empty($_POST['role_id'])){
                $stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)");
                if(!$stmt) throw new Exception("Prepare failed: ".$conn->error);
                $stmt->bind_param("ii",$user_id,$_POST['role_id']);
                if(!$stmt->execute()) throw new Exception("Error assigning role: ".$stmt->error);
                $stmt->close();
            }
        }

        $conn->commit();
        $_SESSION['success'] = "Client added successfully!";
    }catch(Exception $e){
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
    }
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

/* ================= FETCH CLIENTS ================= */
$clients = [];
$res = $conn->query("
    SELECT c.*, i.name AS industry_name, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone 
    FROM clients c 
    LEFT JOIN industries i ON i.id=c.industry_id
    LEFT JOIN users u ON u.client_id=c.id
    ORDER BY c.id DESC
");
while($r = $res->fetch_assoc()) $clients[] = $r;

/* ================= FETCH INDUSTRIES & ROLES ================= */
$industriesArr = []; $res = $conn->query("SELECT id,name FROM industries"); while($r=$res->fetch_assoc()) $industriesArr[] = $r;
$rolesArr = []; $res = $conn->query("SELECT id,name FROM roles"); while($r=$res->fetch_assoc()) $rolesArr[] = $r;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Clients</title>
<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
/* =====================================================
   ALL_CLINTS.PHP - FINAL DARK PURPLE GLASS THEME
   Replace complete current <style> block with this
===================================================== */

html,
body.admin-dashboard-page{
    margin:0 !important;
    padding:0 !important;
    width:100% !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:#f8fafc !important;
    font-family:Inter, "Segoe UI", Arial, sans-serif !important;
}

body.admin-dashboard-page{
    --admin-sidebar-open-width:280px;
    --admin-sidebar-close-width:88px;
    --admin-current-sidebar-width:var(--admin-sidebar-open-width);
}

body.admin-dashboard-page.sidebar-collapsed,
body.admin-dashboard-page.dashboard-sidebar-collapsed{
    --admin-current-sidebar-width:var(--admin-sidebar-close-width);
}

/* ================= LAYOUT ================= */

body.admin-dashboard-page .container-scroll{
    display:flex !important;
    width:100% !important;
    min-height:100vh !important;
    align-items:stretch !important;
    margin:0 !important;
    padding:0 !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.admin-dashboard-page .sidebar,
body.admin-dashboard-page #dashboardSidebar{
    width:var(--admin-current-sidebar-width) !important;
    min-width:var(--admin-current-sidebar-width) !important;
    max-width:var(--admin-current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    min-height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page .sidebar > div,
body.admin-dashboard-page #dashboardSidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-wrap{
    width:var(--admin-current-sidebar-width) !important;
    min-width:var(--admin-current-sidebar-width) !important;
    max-width:var(--admin-current-sidebar-width) !important;
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

body.admin-dashboard-page #dashboardSidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

/* ================= MAIN PANEL ================= */

body.admin-dashboard-page .main-panel{
    margin-left:var(--admin-current-sidebar-width) !important;
    width:calc(100% - var(--admin-current-sidebar-width)) !important;
    min-width:0 !important;
    min-height:100vh !important;
    padding:70px 0 0 0 !important;
    display:block !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        radial-gradient(circle at 78% 6%, rgba(59,130,246,.12), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    transition:margin-left .25s ease, width .25s ease !important;
}

/* ================= TOPBAR ================= */

body.admin-dashboard-page .navbar.my-navbar.fixed-top{
    left:var(--admin-current-sidebar-width) !important;
    width:calc(100% - var(--admin-current-sidebar-width)) !important;
    height:70px !important;
    min-height:70px !important;
    margin:0 !important;
    z-index:999 !important;
    background:#070b14 !important;
    color:#fff !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
    transition:left .25s ease, width .25s ease !important;
}

/* ================= CONTENT ================= */

body.admin-dashboard-page .content-wrapper{
    width:100% !important;
    margin:0 !important;
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    position:relative !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.08), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
    color:#f8fafc !important;
}

body.admin-dashboard-page .content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.28;
    z-index:0;
}

body.admin-dashboard-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* ================= PAGE HEADER ================= */

body.admin-all-clients-page h4{
    color:#f8fafc !important;
    font-size:28px !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.35) !important;
    margin:0 !important;
}

body.admin-all-clients-page .d-flex.justify-content-between.mb-3{
    align-items:center !important;
    margin-bottom:18px !important;
}

/* Add Client Button */
body.admin-all-clients-page .btn-warning{
    min-height:46px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 20px !important;
    background:linear-gradient(135deg,#f59e0b,#facc15) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(245,158,11,.24),
        0 0 24px rgba(245,158,11,.12) !important;
    transition:.22s ease !important;
}

body.admin-all-clients-page .btn-warning:hover{
    transform:translateY(-2px) !important;
    filter:brightness(1.06) !important;
}

/* ================= TABLE GLASS ================= */

body.admin-all-clients-page .table-responsive,
body.admin-all-clients-page table.table{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.15), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.74), rgba(17,24,39,.55)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

/* table */
body.admin-all-clients-page table.table{
    width:100% !important;
    overflow:hidden !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    margin-bottom:0 !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.08) !important;
    --bs-table-hover-bg:rgba(124,58,237,.14) !important;
    --bs-table-hover-color:#fff !important;
    --bs-table-color:#e5e7eb !important;
}

/* header */
body.admin-all-clients-page table.table thead th,
body.admin-all-clients-page table.table thead.table-dark th{
    background:rgba(7,11,20,.92) !important;
    color:#cbd5e1 !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.06em !important;
    white-space:nowrap !important;
}

/* body cells */
body.admin-all-clients-page table.table tbody td{
    background:rgba(15,23,42,.34) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    padding:16px 18px !important;
    font-size:14px !important;
    font-weight:800 !important;
    vertical-align:middle !important;
    white-space:nowrap !important;
}

/* striped */
body.admin-all-clients-page table.table-striped tbody tr:nth-of-type(odd) td,
body.admin-all-clients-page table.table tbody tr:nth-child(odd) td{
    background:rgba(124,58,237,.08) !important;
}

/* hover white remove */
body.admin-all-clients-page table.table-hover tbody tr:hover td,
body.admin-all-clients-page table.table tbody tr:hover td,
body.admin-all-clients-page table.table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.36)) !important;
    color:#fff !important;
}

/* Action icons */
body.admin-all-clients-page .editClient,
body.admin-all-clients-page .deleteClient{
    width:36px !important;
    height:36px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    margin:0 4px !important;
    transition:.22s ease !important;
    box-shadow:0 10px 20px rgba(0,0,0,.20) !important;
}

body.admin-all-clients-page .editClient{
    background:rgba(59,130,246,.16) !important;
    color:#60a5fa !important;
    border:1px solid rgba(59,130,246,.28) !important;
}

body.admin-all-clients-page .deleteClient{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.26) !important;
}

body.admin-all-clients-page .editClient:hover,
body.admin-all-clients-page .deleteClient:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Alerts */
body.admin-all-clients-page .alert{
    border-radius:14px !important;
    font-weight:850 !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

body.admin-all-clients-page .alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.28) !important;
}

body.admin-all-clients-page .alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.28) !important;
}

/* ================= MODAL DARK GLASS ================= */

body.admin-all-clients-page .modal{
    z-index:5000 !important;
}

body.admin-all-clients-page .modal-backdrop{
    z-index:4990 !important;
}

body.admin-all-clients-page .modal-backdrop.show{
    opacity:.72 !important;
    background:#000 !important;
    backdrop-filter:blur(7px) !important;
}

body.admin-all-clients-page .modal-content{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.30), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(34,197,94,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.82)) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.38) !important;
    border-radius:20px !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.62),
        0 0 52px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
    overflow:hidden !important;
}

body.admin-all-clients-page .modal-header{
    background:rgba(7,11,20,.38) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    color:#f8fafc !important;
}

body.admin-all-clients-page .modal-footer{
    background:rgba(7,11,20,.38) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
}

body.admin-all-clients-page .modal-title{
    color:#f8fafc !important;
    font-size:21px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
}

body.admin-all-clients-page .modal-body{
    background:rgba(15,23,42,.18) !important;
    color:#e5e7eb !important;
}

body.admin-all-clients-page label{
    color:#e5e7eb !important;
    font-weight:800 !important;
    margin-bottom:6px !important;
}

/* Form fields modal */
body.admin-all-clients-page .form-control,
body.admin-all-clients-page .form-select,
body.admin-all-clients-page select,
body.admin-all-clients-page input{
    /*min-height:44px !important;*/
    /*border-radius:12px !important;*/
    /*background:rgba(255,255,255,.95) !important;*/
    /*color:#111827 !important;*/
    /*border:1px solid rgba(203,213,225,.82) !important;*/
    /*box-shadow:0 10px 24px rgba(0,0,0,.14) !important;*/
    /*font-weight:800 !important;*/
}

body.admin-all-clients-page .form-control:focus,
body.admin-all-clients-page .form-select:focus,
body.admin-all-clients-page select:focus,
body.admin-all-clients-page input:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.22),
        0 10px 24px rgba(0,0,0,.18) !important;
}

body.admin-all-clients-page .btn-close{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
}

/* ================= COLLAPSED SIDEBAR ================= */

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-arrow,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow{
    display:none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .submenu-collapse,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-link,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-link{
    justify-content:center !important;
    padding-left:0 !important;
    padding-right:0 !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-main,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

/* ================= MOBILE ================= */

@media(max-width:991.98px){
    body.admin-dashboard-page{
        --admin-current-sidebar-width:0px !important;
    }

    body.admin-dashboard-page .container-scroll{
        display:block !important;
        width:100% !important;
        min-height:100vh !important;
    }

    body.admin-dashboard-page .sidebar,
    body.admin-dashboard-page #dashboardSidebar{
        position:fixed !important;
        top:0 !important;
        left:-280px !important;
        width:280px !important;
        min-width:280px !important;
        max-width:280px !important;
        height:100dvh !important;
        min-height:100dvh !important;
        z-index:3000 !important;
        overflow:hidden !important;
        transition:left .25s ease !important;
    }

    body.admin-dashboard-page.sidebar-open #dashboardSidebar,
    body.admin-dashboard-page.dashboard-mobile-sidebar-open #dashboardSidebar{
        left:0 !important;
    }

    body.admin-dashboard-page .main-panel{
        margin-left:0 !important;
        width:100% !important;
        padding-top:70px !important;
    }

    body.admin-dashboard-page .navbar.my-navbar.fixed-top{
        left:0 !important;
        width:100% !important;
    }

    body.admin-dashboard-page .content-wrapper{
        padding:16px !important;
        min-height:calc(100vh - 70px) !important;
    }

    body.admin-all-clients-page .d-flex.justify-content-between.mb-3{
        flex-direction:column !important;
        align-items:stretch !important;
        gap:12px !important;
    }

    body.admin-all-clients-page .btn-warning{
        width:100% !important;
    }

    body.admin-all-clients-page table.table{
        min-width:1100px !important;
    }
}
</style>
</head>
<body class="admin-dashboard-page admin-all-clients-page">
<div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">
            <div class="d-flex justify-content-between mb-3">
                <h4>Clients</h4>
                <button class="btn btn-warning" onclick="openAdd()">+ Add Client</button>
            </div>

            <?php
            if(isset($_SESSION['success'])){echo "<div class='alert alert-success'>".$_SESSION['success']."</div>"; unset($_SESSION['success']);}
            if(isset($_SESSION['error'])){echo "<div class='alert alert-danger'>".$_SESSION['error']."</div>"; unset($_SESSION['error']);}
            ?>

            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Business</th>
                        <th>Address</th>
                        <th>Contact</th>
                        <th>Industry</th>
                        <th>Status</th>
                        <th>Owner Name</th>
                        <th>Email</th>
                        <th width="100">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($clients as $c): ?>
                    <tr>
                        <td><?= $c['id'] ?></td>
                        <td><?= htmlspecialchars($c['business_name']) ?></td>
                        <td><?= htmlspecialchars($c['business_address']) ?></td>
                        <td><?= htmlspecialchars($c['owner_phone'] ?? $c['contact_number']) ?></td>
                        <td><?= htmlspecialchars($c['industry_name']) ?></td>
                        <td><?= ucfirst($c['status']) ?></td>
                        <td><?= htmlspecialchars($c['owner_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($c['owner_email'] ?? '') ?></td>
                        <td class="text-center">
                            <i class="bi bi-pencil-square text-primary me-2 editClient" style="cursor:pointer" data-client='<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>'></i>
                            <i class="bi bi-trash text-danger deleteClient" style="cursor:pointer" data-id="<?= $c['id'] ?>"></i>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>
    </div>
</div>

<!-- Skydash Medium Modal -->
<div class="modal fade" id="clientModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <form id="clientForm" method="POST">
                <input type="hidden" name="add_client" id="add_client" value="1">
                <input type="hidden" name="client_id" id="client_id">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Client</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label>Business Name</label>
                            <input name="business_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label>Industry</label>
                            <select name="industry" class="form-control" id="industrySelect" required></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label>Business Address</label>
                            <input name="business_address" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label>Contact Number</label>
                            <input name="contact_number" class="form-control" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Owner Name</label>
                            <input name="username" class="form-control">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label>Email</label>
                            <input name="email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label>Role</label>
                            <select name="role_id" class="form-control" id="roleSelect"></select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Save</button>
                </div>

            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modal = new bootstrap.Modal(document.getElementById('clientModal'));
const industries = <?php echo json_encode($industriesArr) ?>;
const roles = <?php echo json_encode($rolesArr) ?>;

function fillModalOptions(){
    const industrySelect = document.getElementById('industrySelect');
    industrySelect.innerHTML = "";
    industries.forEach(i => industrySelect.innerHTML += `<option value="${i.id}">${i.name}</option>`);

    const roleSelect = document.getElementById('roleSelect');
    roleSelect.innerHTML = "";
    roles.forEach(r => roleSelect.innerHTML += `<option value="${r.id}">${r.name}</option>`);
}
fillModalOptions();

function openAdd(){
    clientForm.reset();
    modalTitle.innerText="Add Client";
    add_client.value=1;
    client_id.value="";
    fillModalOptions();
    modal.show();
}

// EDIT
document.querySelectorAll('.editClient').forEach(e=>{
    e.onclick=()=>{
        const d = JSON.parse(e.getAttribute('data-client'));
        modalTitle.innerText="Edit Client";
        add_client.value="";
        client_id.value=d.id;
        clientForm.business_name.value=d.business_name;
        clientForm.business_address.value=d.business_address;
        clientForm.contact_number.value=d.owner_phone ?? d.contact_number;
        clientForm.status.value=d.status;
        clientForm.username.value=d.owner_name ?? '';
        clientForm.email.value=d.owner_email ?? '';
        fillModalOptions();
        clientForm.industry.value=d.industry_id;
        clientForm.role_id.value=d.role_id ?? '';
        modal.show();
    }
});

// DELETE
document.querySelectorAll('.deleteClient').forEach(d=>{
    d.onclick=async ()=>{
        if(!confirm("Delete this client?")) return;
        try{
            const res = await fetch("",{
                method:"POST",
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:`ajax=delete_client&id=${d.dataset.id}`
            });
            if(!res.ok) throw new Error(await res.text());
            location.reload();
        }catch(err){
            alert("Delete failed: " + err.message);
        }
    }
});

// SUBMIT EDIT
clientForm.onsubmit = e=>{
    if(add_client.value) return; // normal add
    e.preventDefault();
    const formData = new FormData(clientForm);
    formData.append('ajax','edit_client');
    fetch("",{method:"POST",body:formData}).then(res=>{
        if(!res.ok) throw new Error("Update failed");
        location.reload();
    });
};
</script>
</body>
</html>