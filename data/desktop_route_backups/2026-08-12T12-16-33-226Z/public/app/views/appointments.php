<?php
session_start();


require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
}

$client_id = (int)$_SESSION['client_id'];

// ====================== DELETE appointment (AJAX) ======================
if(($_POST['action'] ?? '') === 'delete_appointment'){
    $id = (int)($_POST['id'] ?? 0);
    if($id <= 0) exit('Invalid ID');

    $stmt = $conn->prepare("DELETE FROM appointments WHERE id=? AND client_id=?");
    if(!$stmt) exit('Prepare failed: '.$conn->error);
    $stmt->bind_param("ii",$id,$client_id);
    if(!$stmt->execute()) exit('Execute failed: '.$stmt->error);
    $stmt->close();
    exit('ok');
}

// ====================== SAVE / EDIT appointment (AJAX) ======================
if(($_POST['action'] ?? '') === 'save_appointment'){
    $id = (int)($_POST['appointment_id'] ?? 0);
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $service_id = (int)($_POST['service_id'] ?? 0);
    $appointment_date = $_POST['appointment_date'] ?? '';
    $appointment_time = $_POST['appointment_time'] ?? '';
    $status = $_POST['appointment_status'] ?? 'pending';
    $notes = $_POST['appointment_notes'] ?? '';

    if(!$customer_id || !$staff_id || !$service_id || !$appointment_date || !$appointment_time){
        exit('All fields are required');
    }

    if($id > 0){
        $stmt = $conn->prepare("UPDATE appointments SET customer_id=?, staff_id=?, service_id=?, appointment_date=?, appointment_time=?, status=?, notes=? WHERE id=? AND client_id=?");
        if(!$stmt) exit('Prepare failed: '.$conn->error);
        $stmt->bind_param("iiisssisi",$customer_id,$staff_id,$service_id,$appointment_date,$appointment_time,$status,$notes,$id,$client_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO appointments (client_id,customer_id,staff_id,service_id,appointment_date,appointment_time,status,notes) VALUES (?,?,?,?,?,?,?,?)");
        if(!$stmt) exit('Prepare failed: '.$conn->error);
        $stmt->bind_param("iiisssss",$client_id,$customer_id,$staff_id,$service_id,$appointment_date,$appointment_time,$status,$notes);
    }

    if(!$stmt->execute()) exit('Execute failed: '.$stmt->error);
    $stmt->close();
    exit('ok');
}

// ====================== GET single appointment (AJAX) ======================
if(($_GET['action'] ?? '') === 'get' && isset($_GET['id'])){
    $id = (int)$_GET['id'];
    if($id <= 0) exit(json_encode([]));

    $stmt = $conn->prepare("
        SELECT a.*, c.name AS customer_name, c.phone AS customer_phone, 
               u.name AS staff_name, sv.name AS service_name
        FROM appointments a
        LEFT JOIN customers c ON a.customer_id=c.id
        LEFT JOIN users u ON a.staff_id=u.id
        LEFT JOIN services sv ON a.service_id=sv.id
        WHERE a.id=? AND a.client_id=?
    ");
    if(!$stmt) exit(json_encode(['error'=>'Prepare failed: '.$conn->error]));
    $stmt->bind_param("ii",$id,$client_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    echo json_encode($res);
    exit;
}

// ====================== FETCH appointments ======================
$search    = trim($_GET['search'] ?? '');
$status    = trim($_GET['status'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');

$where = ["a.client_id=?"];
$params = [$client_id];
$types = "i";

if($search !== ''){
    $where[] = "(c.name LIKE ? OR u.name LIKE ? OR sv.name LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like,$like,$like]);
    $types .= "sss";
}
if($status !== ''){ $where[] = "a.status=?"; $params[]=$status; $types.="s"; }
if($date_from !== ''){ $where[]="DATE(a.appointment_date)>=?"; $params[]=$date_from; $types.="s"; }
if($date_to !== ''){ $where[]="DATE(a.appointment_date)<=?"; $params[]=$date_to; $types.="s"; }

$sql = "SELECT a.*, c.name AS customer_name, c.phone AS customer_phone, 
               u.name AS staff_name, sv.name AS service_name
        FROM appointments a
        LEFT JOIN customers c ON a.customer_id=c.id
        LEFT JOIN users u ON a.staff_id=u.id
        LEFT JOIN services sv ON a.service_id=sv.id
        WHERE ".implode(" AND ",$where)."
        ORDER BY a.appointment_date DESC,a.appointment_time DESC";

$stmt = $conn->prepare($sql);
if(!$stmt) die("Prepare failed: ".$conn->error);
if($params) $stmt->bind_param($types,...$params);
$stmt->execute();
$res = $stmt->get_result();
$appointments = [];
while($row = $res->fetch_assoc()) $appointments[] = $row;
$stmt->close();

// ====================== FETCH dropdowns ======================
$customers = $conn->query("SELECT id,name,phone FROM customers WHERE client_id=$client_id ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$staffs    = $conn->query("SELECT id,name FROM users WHERE client_id=$client_id ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$services  = $conn->query("SELECT id,name FROM services WHERE client_id=$client_id ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// ====================== HELPER ======================
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Appointments</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            margin:0;
            background:#f3f4f8;
            font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .container-scroll{
            display:flex;
            min-height:100vh;
        }

        .sidebar{
            width:270px;
            flex-shrink:0;
        }

        .main-panel{
            flex-grow:1;
            display:flex;
            flex-direction:column;
            min-width:0;
        }

        .topbar{
            min-height:70px;
            border-bottom:1px solid #e5e7eb;
            display:flex;
            align-items:center;
            background:#fff;
            padding:0;
        }

        .content-wrapper{
            padding:20px;
            flex-grow:1;
            overflow-y:auto;
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .page-title{
            font-size:28px;
            font-weight:700;
            color:#1f2937;
            margin:0;
        }

        .header-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn-soft{
            border:none;
            border-radius:10px;
            padding:10px 16px;
            color:#fff;
            font-weight:600;
            font-size:14px;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
            display:inline-flex;
            align-items:center;
            gap:8px;
        }

        .btn-add{ background:#2e9e59; }
        .btn-bulk{ background:#3f8efc; }
        .btn-import{ background:#2ea44f; }
        .btn-export{ background:#5c4fd6; }

        .stats-row{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:14px;
            margin-bottom:16px;
        }

        .stat-card{
            color:#fff;
            border-radius:10px;
            padding:14px 18px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
            cursor:pointer;
            transition:all .2s ease;
        }

        .stat-card .title{
            font-size:15px;
            font-weight:500;
        }

        .stat-card .count{
            font-size:30px;
            font-weight:700;
            line-height:1;
        }

        .stat-card.active{
            border:2px solid #1f2937;
            transform:translateY(-2px);
        }

        .card-blue{ background:linear-gradient(135deg,#4095ff,#2f80ed); }
        .card-green{ background:linear-gradient(135deg,#49be88,#2ea769); }
        .card-cyan{ background:linear-gradient(135deg,#4ac9d6,#26b4c0); }
        .card-yellow{ background:linear-gradient(135deg,#f7c943,#f4b400); }

        .filter-bar{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:12px;
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            margin-bottom:16px;
        }

        .quick-btn{
            height:44px;
            border:none;
            border-radius:10px;
            padding:0 14px;
            background:#111827;
            color:#fff;
            font-weight:600;
        }

        .search-wrap{
            flex:1.3;
            min-width:220px;
            position:relative;
        }

        .search-wrap i{
            position:absolute;
            left:12px;
            top:50%;
            transform:translateY(-50%);
            color:#6b7280;
        }

        .search-wrap input{
            width:100%;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 14px 0 38px;
            outline:none;
            background:#fff;
        }

        .filter-control{
            min-width:180px;
            height:44px;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:0 12px;
            outline:none;
            background:#fff;
        }

        .filter-btn{
            height:44px;
            border:none;
            border-radius:10px;
            padding:0 18px;
            background:#1f2937;
            color:#fff;
            font-weight:600;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
        }

        .table-card{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            overflow:hidden;
        }

        .table-responsive{
            overflow:auto;
        }

        .customers-table{
            width:100%;
            min-width:980px;
            border-collapse:collapse;
        }

        .customers-table thead th{
            background:#28344d;
            color:#fff;
            font-weight:600;
            font-size:14px;
            padding:14px 12px;
            border-right:1px solid rgba(255,255,255,0.08);
            white-space:nowrap;
        }

        .customers-table tbody td{
            padding:14px 12px;
            border-top:1px solid #edf0f5;
            vertical-align:middle;
            font-size:14px;
            color:#374151;
        }

        .customers-table tbody tr:hover{
            background:#fafbfc;
        }

        .customer-name-wrap{
            display:flex;
            align-items:center;
            gap:10px;
        }

        .avatar-badge{
            width:42px;
            height:42px;
            border-radius:50%;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:linear-gradient(135deg,#dbeafe,#bfdbfe);
            color:#1d4ed8;
            font-weight:700;
            flex-shrink:0;
        }

        .customer-name{
            font-weight:700;
            color:#1f2937;
        }

        .customer-email-sub{
            font-size:12px;
            color:#667085;
        }

        .badge-type{
            padding:6px 12px;
            border-radius:7px;
            font-size:12px;
            font-weight:700;
            display:inline-block;
        }

        .walkin{ background:#f0c541; color:#5b4300; }
        .wholesale{ background:#6da789; color:#fff; }
        .retail{ background:#68a4ea; color:#fff; }

        .actions{
            display:flex;
            gap:10px;
            align-items:center;
        }
        

        .actions button{
            border:none;
            background:none;
            color:#111827;
            font-size:16px;
            cursor:pointer;
        }
        .app-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
}

.app-btn{
    width:38px;
    height:38px;
    border:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

.app-btn:hover{
    transform:translateY(-2px);
}

.app-btn i{
    font-size:15px;
}

/* Edit */
.app-edit{
    background:#fff4d6;
    color:#e0a100;
}
.app-edit:hover{
    background:#e0a100;
    color:#fff;
}

/* Delete */
.app-delete{
    background:#ffe3e3;
    color:#ef4444;
}
.app-delete:hover{
    background:#ef4444;
    color:#fff;
}

        .table-footer{
            padding:14px;
            border-top:1px solid #e5e7eb;
            background:#fff;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .bulk-delete-btn{
            border:none;
            background:#ef4444;
            color:#fff;
            border-radius:8px;
            padding:10px 14px;
            font-size:14px;
            font-weight:600;
        }

/* OLD SUPPLIER POPUP STYLE PRESERVED */
    .modal-xl-custom{max-width:1020px}
    .po-modal-content{
        background:
            radial-gradient(circle at top left, rgba(25,43,120,.40), transparent 32%),
            radial-gradient(circle at bottom right, rgba(255,185,0,.12), transparent 28%),
            linear-gradient(180deg,#060916,#0b1031);
        border:none;border-radius:20px;overflow:hidden;color:#fff;box-shadow:0 30px 80px rgba(0,0,0,.35)
    }
    .po-modal-header{
        padding:18px 24px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:center
    }
    .po-modal-title{font-size:18px;font-weight:700;margin:0;color:#fff}
    .po-close{background:none;border:none;color:#d8def2;font-size:28px;line-height:1}
    .po-modal-body{padding:14px 24px 0}
    .dark-label{font-size:13px;font-weight:600;color:#f4f6fd;margin-bottom:6px}
    .req{color:#ffbf43}
    .dark-input,.dark-select,.dark-textarea{
        width:100%;border:none;outline:none;border-radius:8px;background:#f3f5fa;color:#1d2434;
        padding:12px 14px;font-size:14px
    }
    .dark-textarea{min-height:84px;resize:vertical}
    .upload-panel{
        background:rgba(255,255,255,.04);
        border:1px solid rgba(255,255,255,.10);
        border-radius:16px;
        padding:18px;
        height:100%;
    }
    .upload-box-dark{
        border:2px dashed rgba(255,255,255,.16);
        border-radius:14px;
        min-height:180px;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        text-align:center;
        padding:20px;
        color:#d9deed;
        cursor:pointer;
    }
    .upload-box-dark.small{
        min-height:150px;
    }
    .upload-btn-dark{
        width:100%;
        margin-top:12px;
        border:none;
        border-radius:10px;
        padding:12px;
        font-weight:700;
        background:#1d2858;
        color:#fff;
    }
    .or-sep{
        text-align:center;
        color:#bfc8df;
        margin:14px 0;
        font-size:13px;
    }
    .toggle-wrap-dark{
        display:flex;
        align-items:center;
        gap:10px;
        color:#fff;
        font-weight:600;
        margin-top:8px;
    }
    .preview-img{
        display:none;
        width:100%;
        max-height:120px;
        object-fit:contain;
        margin-top:10px;
        border:1px solid rgba(255,255,255,.18);
        border-radius:10px;
        padding:8px;
        background:#fff;
    }
    .doc-name{
        display:none;
        margin-top:8px;
        font-size:12px;
        color:#d3d9ea;
        word-break:break-word;
    }
    .po-modal-footer{
        padding:16px 24px;border-top:1px solid rgba(255,255,255,.08);display:flex;justify-content:flex-end;gap:12px;margin-top:16px
    }
    .cancel-btn-dark{
        min-width:130px;height:44px;border-radius:10px;border:none;background:#5d617a;color:#fff;font-weight:700
    }
    .save-btn-dark{
        min-width:160px;height:44px;border-radius:10px;border:none;background:linear-gradient(180deg,#f2bc24,#d99d00);color:#fff;font-weight:800
    }

        .required-star{
            color:#ef4444;
        }

        .sample-note{
            font-size:12px;
            color:#6b7280;
        }

        @media (max-width:1100px){
            .stats-row{
                grid-template-columns:repeat(2,1fr);
            }
        }

        @media (max-width:768px){
            .stats-row{
                grid-template-columns:1fr;
            }

            .filter-bar{
                flex-direction:column;
                align-items:stretch;
            }

            .search-wrap,
            .filter-control,
            .filter-btn{
                width:100%;
                min-width:100%;
            }

            .page-title{
                font-size:24px;
            }

            .sidebar{
                width:240px;
            }
        }
    

/* =========================================================
   FINAL APPOINTMENTS POPUP BLACK + GOLD + WHITE THEME
   Add/Edit Appointment Modal
   Backend/PHP/JS same - only popup theme CSS
========================================================= */

body.modal-open .modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

/* Modal size */
#appointmentModal .modal-dialog{
    max-width:min(900px,94vw) !important;
}

/* Main popup container */
#appointmentModal .modal-content,
#appointmentModal .po-modal-content{
    background:#0f1117 !important;
    color:#ffffff !important;
    border:1px solid rgba(201,168,76,.62) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

/* Header */
#appointmentModal .modal-header,
#appointmentModal .po-modal-header{
    background:linear-gradient(180deg,#151a26 0%,#0f1117 100%) !important;
    border-bottom:2px solid #c9a84c !important;
    padding:22px 24px !important;
    min-height:76px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#appointmentModal .modal-title,
#appointmentModal .po-modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#appointmentModal .modal-title::before,
#appointmentModal .po-modal-title::before{
    content:'' !important;
    width:13px !important;
    height:13px !important;
    border-radius:50% !important;
    background:#c9a84c !important;
    box-shadow:0 0 0 5px rgba(201,168,76,.15) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

/* Close button - proper white X */
#appointmentModal .po-close{
    position:relative !important;
    width:48px !important;
    height:48px !important;
    border-radius:10px !important;
    background:#252b3a !important;
    border:1px solid rgba(255,255,255,.14) !important;
    color:transparent !important;
    opacity:1 !important;
    box-shadow:none !important;
    padding:0 !important;
    font-size:0 !important;
    line-height:1 !important;
}

#appointmentModal .po-close::before,
#appointmentModal .po-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:22px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

#appointmentModal .po-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#appointmentModal .po-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#appointmentModal .po-close:hover{
    background:#c94b57 !important;
    transform:translateY(-1px) !important;
}

/* Body */
#appointmentModal .modal-body,
#appointmentModal .po-modal-body{
    background:#0f1117 !important;
    color:#ffffff !important;
    padding:26px 24px 22px !important;
}

#appointmentModal .po-modal-body::-webkit-scrollbar{
    width:7px !important;
}

#appointmentModal .po-modal-body::-webkit-scrollbar-track{
    background:#171c28 !important;
    border-radius:999px !important;
}

#appointmentModal .po-modal-body::-webkit-scrollbar-thumb{
    background:#c9a84c !important;
    border-radius:999px !important;
}

/* Footer */
#appointmentModal .modal-footer,
#appointmentModal .po-modal-footer{
    background:#0f1117 !important;
    border-top:1px solid rgba(201,168,76,.28) !important;
    padding:18px 24px 22px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:12px !important;
}

/* Labels */
#appointmentModal .dark-label,
#appointmentModal label{
    color:#ffffff !important;
    font-size:14px !important;
    font-weight:900 !important;
    margin-bottom:9px !important;
    letter-spacing:-.1px !important;
}

#appointmentModal .req{
    color:#f5c742 !important;
}

/* Inputs / selects / textarea */
#appointmentModal .dark-input,
#appointmentModal .dark-select,
#appointmentModal .dark-textarea,
#appointmentModal input[type="text"],
#appointmentModal input[type="date"],
#appointmentModal input[type="time"],
#appointmentModal select,
#appointmentModal textarea{
    background:#ffffff !important;
    color:#111827 !important;
    border:1.5px solid #d9dfec !important;
    border-radius:10px !important;
    font-size:15px !important;
    font-weight:500 !important;
    box-shadow:0 3px 10px rgba(0,0,0,.18) !important;
}

#appointmentModal .dark-input,
#appointmentModal .dark-select,
#appointmentModal input[type="text"],
#appointmentModal input[type="date"],
#appointmentModal input[type="time"],
#appointmentModal select{
    min-height:50px !important;
    height:50px !important;
    padding:10px 16px !important;
}

#appointmentModal .dark-textarea,
#appointmentModal textarea{
    min-height:112px !important;
    height:auto !important;
    padding:14px 16px !important;
    resize:vertical !important;
}

#appointmentModal input::placeholder,
#appointmentModal textarea::placeholder{
    color:#8993a5 !important;
}

#appointmentModal .dark-input:focus,
#appointmentModal .dark-select:focus,
#appointmentModal .dark-textarea:focus,
#appointmentModal input:focus,
#appointmentModal select:focus,
#appointmentModal textarea:focus{
    background:#ffffff !important;
    border-color:#c9a84c !important;
    box-shadow:0 0 0 4px rgba(201,168,76,.24),0 5px 14px rgba(0,0,0,.22) !important;
    outline:none !important;
}

/* Footer buttons */
#appointmentModal .cancel-btn-dark{
    height:44px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:10px !important;
    background:#252b3a !important;
    color:#ffffff !important;
    font-weight:900 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.22) !important;
}

#appointmentModal .cancel-btn-dark:hover{
    background:#32394b !important;
    color:#ffffff !important;
}

#appointmentModal .save-btn-dark{
    height:44px !important;
    min-width:150px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(180deg,#f5c742,#d8a500) !important;
    color:#111827 !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(216,165,0,.28) !important;
}

#appointmentModal .save-btn-dark:hover{
    background:linear-gradient(180deg,#e8bb34,#c99700) !important;
    transform:translateY(-1px) !important;
}

/* Mobile */
@media (max-width:768px){
    #appointmentModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #appointmentModal .modal-title,
    #appointmentModal .po-modal-title{
        font-size:18px !important;
    }

    #appointmentModal .po-close{
        width:42px !important;
        height:42px !important;
    }

    #appointmentModal .po-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #appointmentModal .cancel-btn-dark,
    #appointmentModal .save-btn-dark{
        width:100% !important;
    }
}

/* =====================================================
   APPOINTMENTS.PHP - DARK PURPLE BLACK GLASS THEME
   Paste at VERY END before 
   Backend/PHP/JS same - only UI theme
===================================================== */

html,
body{
    min-height:100% !important;
    margin:0 !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
    color:#f8fafc !important;
    font-family:Inter, "Segoe UI", Arial, sans-serif !important;
    overflow-x:hidden !important;
}

/* ================= LAYOUT ================= */

.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

.sidebar{
    width:270px !important;
    flex-shrink:0 !important;
    background:transparent !important;
}

.main-panel{
    flex:1 !important;
    min-width:0 !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.18), transparent 28%),
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

.topbar{
    min-height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
    color:#fff !important;
}

.content-wrapper{
    position:relative !important;
    padding:24px 28px !important;
    flex:1 !important;
    overflow:visible !important;
    min-height:calc(100vh - 70px) !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
}

/* subtle grid */
.content-wrapper::before{
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

.content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* ================= HEADER ================= */

.page-header{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-bottom:18px !important;
}

.page-title{
    color:#f8fafc !important;
    font-size:30px !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
    margin:0 !important;
}

.header-actions{
    display:flex !important;
    gap:10px !important;
    flex-wrap:wrap !important;
}

/* Header buttons */
.btn-soft{
    min-height:46px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    color:#fff !important;
    font-weight:900 !important;
    font-size:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    box-shadow:0 14px 28px rgba(0,0,0,.24) !important;
    transition:.22s ease !important;
}

.btn-soft:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

.btn-add{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
}

.btn-bulk{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
}

.btn-import{
    background:linear-gradient(135deg,#0891b2,#06b6d4) !important;
}

.btn-export{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
}

/* ================= STATS CARDS ================= */

.stats-row{
    display:grid !important;
    grid-template-columns:repeat(4, minmax(0,1fr)) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:118px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    position:relative !important;
    overflow:hidden !important;
    transition:.25s ease !important;
}

.stat-card::before{
    content:"" !important;
    position:absolute !important;
    inset:0 !important;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%) !important;
    z-index:0 !important;
}

.stat-card::after{
    content:"" !important;
    position:absolute !important;
    right:14px !important;
    top:14px !important;
    width:76px !important;
    height:76px !important;
    border-radius:20px !important;
    background:rgba(255,255,255,.10) !important;
    z-index:0 !important;
}

.stat-card > *{
    position:relative !important;
    z-index:1 !important;
}

.stat-card:hover,
.stat-card.active{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card .title{
    color:rgba(255,255,255,.94) !important;
    font-size:14px !important;
    font-weight:900 !important;
}

.stat-card .count{
    color:#fff !important;
    font-size:30px !important;
    font-weight:900 !important;
}

.card-blue{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
}

.card-green{
    background:linear-gradient(135deg,#047857,#16a34a) !important;
}

.card-cyan{
    background:linear-gradient(135deg,#0891b2,#06b6d4) !important;
}

.card-yellow{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
}

/* ================= FILTER BAR ================= */

.filter-bar{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.54)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    padding:14px 16px !important;
    display:grid !important;
    grid-template-columns:minmax(260px,1fr) minmax(180px,.45fr) 120px 120px !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.search-wrap{
    flex:unset !important;
    min-width:0 !important;
    width:100% !important;
    position:relative !important;
}

.search-wrap i{
    position:absolute !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#a78bfa !important;
    z-index:2 !important;
}

.search-wrap input,
.filter-control{
    width:100% !important;
    height:48px !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    outline:none !important;
    font-size:14px !important;
    font-weight:800 !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

.search-wrap input{
    padding:0 14px 0 42px !important;
}

.filter-control{
    padding:0 14px !important;
}

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.filter-control option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.search-wrap input:focus,
.filter-control:focus{
    background:rgba(17,24,39,.92) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

/* Filter / Reset buttons */
.filter-btn{
    width:100% !important;
    height:48px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 16px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    text-decoration:none !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
    transition:.22s ease !important;
}

.filter-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    box-shadow:
        0 18px 40px rgba(0,0,0,.36),
        0 0 34px rgba(124,58,237,.18) !important;
}

/* second filter-btn reset look */
.filter-bar .filter-btn:last-child{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.filter-bar .filter-btn:last-child:hover{
    background:rgba(124,58,237,.20) !important;
    color:#fff !important;
}

/* ================= TABLE ================= */

.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.table-responsive{
    overflow:auto !important;
    background:transparent !important;
}

.table-responsive::-webkit-scrollbar{
    height:10px !important;
    width:10px !important;
}

.table-responsive::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

.table-responsive::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

.customers-table{
    width:100% !important;
    min-width:980px !important;
    border-collapse:collapse !important;
    background:transparent !important;
    --bs-table-bg:transparent !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-hover-color:#fff !important;
}

.customers-table thead th{
    background:rgba(7,11,20,.82) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.06) !important;
    padding:14px 16px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

.customers-table tbody td{
    background:rgba(15,23,42,.30) !important;
    color:#e5e7eb !important;
    border-top:none !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    padding:15px 16px !important;
    font-size:14px !important;
    font-weight:750 !important;
    vertical-align:middle !important;
}

.customers-table tbody tr:hover td,
.customers-table tbody tr:focus-within td,
.customers-table tbody tr.table-active td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color:#fff !important;
    box-shadow:none !important;
}

/* checkbox selected row */
.customers-table tbody tr:has(input[type="checkbox"]:checked) td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.20), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.38)) !important;
    color:#fff !important;
}

.customer-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.customer-email-sub{
    color:#94a3b8 !important;
}

.avatar-badge{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:1px solid rgba(139,92,246,.35) !important;
}

/* Status badge */
.badge{
    border-radius:999px !important;
    padding:7px 12px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

/* Action buttons */
.app-actions{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
}

.app-btn{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:13px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:0 !important;
    cursor:pointer !important;
    transition:.22s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.app-btn:hover{
    transform:translateY(-2px) !important;
}

.app-edit{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.25) !important;
}

.app-edit:hover{
    background:#f59e0b !important;
    color:#111827 !important;
}

.app-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

.app-delete:hover{
    background:#ef4444 !important;
    color:#fff !important;
}

/* Footer */
.table-footer{
    padding:14px 16px !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    background:rgba(7,11,20,.32) !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
    flex-wrap:wrap !important;
}

.bulk-delete-btn{
    min-height:42px !important;
    border:none !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    border-radius:12px !important;
    padding:0 16px !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:0 12px 24px rgba(239,68,68,.20) !important;
}

/* Checkboxes */
.customers-table input[type="checkbox"]{
    width:18px !important;
    height:18px !important;
    accent-color:#7c3aed !important;
}

/* ================= APPOINTMENT MODAL - PURPLE GLASS ================= */

body.modal-open .modal-backdrop.show,
.modal-backdrop.show{
    opacity:.64 !important;
    background:#000 !important;
    backdrop-filter:blur(7px) !important;
}

#appointmentModal .modal-dialog{
    max-width:min(820px,94vw) !important;
    margin:18px auto !important;
}

#appointmentModal .modal-content,
#appointmentModal .po-modal-content{
    max-height:86vh !important;
    overflow:hidden !important;
    border-radius:20px !important;
    color:#f8fafc !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(34,197,94,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.94), rgba(17,24,39,.78)) !important;
    border:1px solid rgba(139,92,246,.38) !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.62),
        0 0 52px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
}

#appointmentModal .po-modal-header{
    min-height:60px !important;
    padding:14px 20px !important;
    background:linear-gradient(135deg, rgba(7,11,20,.48), rgba(30,20,58,.34)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#appointmentModal .po-modal-title{
    color:#f8fafc !important;
    font-size:21px !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

#appointmentModal .po-modal-title::before{
    content:"" !important;
    width:12px !important;
    height:12px !important;
    border-radius:50% !important;
    background:linear-gradient(135deg,#7c3aed,#22c55e) !important;
    box-shadow:0 0 18px rgba(124,58,237,.55) !important;
}

/* Close button */
#appointmentModal .po-close{
    width:40px !important;
    height:40px !important;
    border-radius:12px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:transparent !important;
    opacity:1 !important;
    position:relative !important;
    padding:0 !important;
    font-size:0 !important;
}

#appointmentModal .po-close::before,
#appointmentModal .po-close::after{
    content:"" !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:18px !important;
    height:2px !important;
    background:#e5e7eb !important;
    border-radius:999px !important;
}

#appointmentModal .po-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#appointmentModal .po-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#appointmentModal .po-close:hover{
    background:rgba(239,68,68,.24) !important;
}

/* Modal body */
#appointmentModal .po-modal-body{
    padding:16px 20px !important;
    max-height:62vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    background:rgba(15,23,42,.20) !important;
    color:#f8fafc !important;
}

#appointmentModal .po-modal-body::-webkit-scrollbar{
    width:8px !important;
}

#appointmentModal .po-modal-body::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

#appointmentModal .po-modal-body::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* Labels */
#appointmentModal .dark-label,
#appointmentModal label{
    color:#e5e7eb !important;
    font-size:13px !important;
    font-weight:900 !important;
    margin-bottom:6px !important;
}

#appointmentModal .req{
    color:#a78bfa !important;
}

/* Inputs */
#appointmentModal .dark-input,
#appointmentModal .dark-select,
#appointmentModal .dark-textarea,
#appointmentModal input[type="text"],
#appointmentModal input[type="date"],
#appointmentModal input[type="time"],
#appointmentModal select,
#appointmentModal textarea{
    width:100% !important;
    background:rgba(255,255,255,.94) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    border-radius:11px !important;
    font-size:14px !important;
    font-weight:800 !important;
    box-shadow:0 10px 24px rgba(0,0,0,.14) !important;
    outline:none !important;
}

#appointmentModal .dark-input,
#appointmentModal .dark-select,
#appointmentModal input[type="text"],
#appointmentModal input[type="date"],
#appointmentModal input[type="time"],
#appointmentModal select{
    height:42px !important;
    min-height:42px !important;
    padding:7px 13px !important;
}

#appointmentModal .dark-textarea,
#appointmentModal textarea{
    min-height:72px !important;
    height:72px !important;
    padding:10px 13px !important;
    resize:vertical !important;
}

#appointmentModal .dark-input:focus,
#appointmentModal .dark-select:focus,
#appointmentModal .dark-textarea:focus,
#appointmentModal input:focus,
#appointmentModal select:focus,
#appointmentModal textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.22),
        0 10px 24px rgba(0,0,0,.18) !important;
}

/* Modal footer */
#appointmentModal .po-modal-footer{
    padding:12px 20px !important;
    min-height:58px !important;
    background:linear-gradient(135deg, rgba(7,11,20,.48), rgba(15,23,42,.36)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    justify-content:flex-end !important;
    align-items:center !important;
    gap:12px !important;
    margin-top:0 !important;
}

#appointmentModal .cancel-btn-dark{
    height:42px !important;
    min-width:120px !important;
    border:none !important;
    border-radius:11px !important;
    background:rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:0 10px 22px rgba(0,0,0,.20) !important;
}

#appointmentModal .save-btn-dark{
    height:42px !important;
    min-width:150px !important;
    border:none !important;
    border-radius:11px !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
    color:#fff !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.32),
        0 0 24px rgba(124,58,237,.18) !important;
}

#appointmentModal .cancel-btn-dark:hover,
#appointmentModal .save-btn-dark:hover{
    transform:translateY(-2px) !important;
}

/* ================= RESPONSIVE ================= */

@media(max-width:1100px){
    .stats-row{
        grid-template-columns:repeat(2, minmax(0,1fr)) !important;
    }

    .filter-bar{
        grid-template-columns:1fr 1fr !important;
    }
}

@media(max-width:768px){
    .content-wrapper{
        padding:16px !important;
    }

    .page-header{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .header-actions{
        width:100% !important;
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .btn-soft{
        width:100% !important;
    }

    .stats-row,
    .filter-bar{
        grid-template-columns:1fr !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .search-wrap,
    .filter-control,
    .filter-btn{
        width:100% !important;
        min-width:100% !important;
    }

    .table-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .bulk-delete-btn{
        width:100% !important;
    }

    #appointmentModal .modal-dialog{
        max-width:calc(100% - 20px) !important;
        margin:10px auto !important;
    }

    #appointmentModal .po-modal-body{
        max-height:66vh !important;
    }

    #appointmentModal .po-modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #appointmentModal .cancel-btn-dark,
    #appointmentModal .save-btn-dark{
        width:100% !important;
    }
}

</style>
</head>
<body>

<div class="container-scroll">
    <div class="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <div class="topbar">
            <?php include('partials/topbar.php'); ?>
        </div>

        <div class="content-wrapper">

            <!--<?php if ($msg !== ''): ?>-->
            <!--    <div class="alert alert-<?= e($msgType) ?> alert-dismissible fade show" role="alert">-->
            <!--        <?= e($msg) ?>-->
            <!--        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>-->
            <!--    </div>-->
            <!--<?php endif; ?>-->

            <div class="page-header">
                <h1 class="page-title">Appointments</h1>

                <div class="header-actions">
                    <button class="btn-soft btn-add" type="button" onclick="openAppointmentModal()">
                        <i class="bi bi-plus-lg"></i> Add New Appointment
                    </button>


                    <button class="btn-soft btn-export" type="button" onclick="exportTable()">
                        <i class="bi bi-upload"></i> Export
                    </button>
                </div>
            </div>

  <!---->

             <form method="GET" class="filter-bar" id="filterForm">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search..." value="<?= e($search) ?>">
                </div>

                <select name="status" class="filter-control">
                    <option value="">All Status</option>
                    <option value="pending" <?= $status=='pending'?'selected':'' ?>>Pending</option>
                    <option value="confirmed" <?= $status=='confirmed'?'selected':'' ?>>Confirmed</option>
                    <option value="completed" <?= $status=='completed'?'selected':'' ?>>Completed</option>
                    <option value="cancelled" <?= $status=='cancelled'?'selected':'' ?>>Cancelled</option>
                </select>

                <button type="submit" class="filter-btn">
                    <i class="bi bi-funnel"></i> Filter
                </button>

                <a href="appointments.php" class="filter-btn">
                    <i class="bi bi-arrow-clockwise"></i> Reset
                </a>
            </form>


            <div class="table-card">
                <div class="table-responsive">
                    <table class="customers-table" id="customersTable">
                        <thead>
                            <tr>
                                <th style="width:50px;">
                                    <input type="checkbox" id="checkAll">
                                </th>
                                <th>ID</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Service</th>
                                    <th>Staff</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                    <th>Action</th>

                            </tr>
                        </thead>
                        <tbody>
                                <?php if(empty($appointments)): ?>
                                <tr>
                                    <td colspan="10">No appointments</td>
                                </tr>
                                <?php else: foreach($appointments as $a):
$statusClass = '';
if ($a['status'] == 'pending') $statusClass = 'bg-warning text-dark';
elseif ($a['status'] == 'confirmed') $statusClass = 'bg-primary';
elseif ($a['status'] == 'completed') $statusClass = 'bg-success';
elseif ($a['status'] == 'cancelled') $statusClass = 'bg-danger';

?>
                                <tr>
                                    <td><input type="checkbox" class="rowCheck" value="<?= $a['id'] ?>"></td>

                                    <td><?= $a['id'] ?></td>

                                    <td>
                                        <div class="customer-name-wrap">
                                            <div class="customer-name"><?= e($a['customer_name']) ?></div>
                                        </div>
                                    </td>

                                    <td><?= e($a['customer_phone']) ?></td>
                                    <td><?= e($a['staff_name']) ?></td>
                                    <td><?= e($a['service_name']) ?></td>
                                    <td><?= date('d M Y', strtotime($a['appointment_date'])) ?></td>
                                    <td><?= date('h:i A', strtotime($a['appointment_time'])) ?></td>

                                    <td><span class="badge <?= $statusClass ?>"><?= ucfirst($a['status']) ?></span></td>
                                    <td><div class="app-actions">
    <button type="button"
        class="app-btn app-edit"
        onclick="editAppointment(<?= (int)$a['id'] ?>)"
        title="Edit Appointment">
        <i class="bi bi-pencil-fill"></i>
    </button>

    <button type="button"
        class="app-btn app-delete"
        onclick="deleteAppointment(<?= (int)$a['id'] ?>)"
        title="Delete Appointment">
        <i class="bi bi-trash-fill"></i>
    </button>
</div>                              </td>

                              

                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                            
                            
                            

                    </table>
                </div>

 <div class="table-footer">
                    <button class="bulk-delete-btn" onclick="deleteSelectedAppointments(event)">Delete Selected</button>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- Modal -->
<!-- Appointment Modal - Dark Styled -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content po-modal-content">
            <form method="POST" id="appointmentForm">
                <input type="hidden" name="action" value="save_appointment">
                <input type="hidden" name="id" id="appointment_id">

                <div class="po-modal-header">
                    <h5 class="po-modal-title" id="modalTitle">Add Appointment</h5>
                    <button type="button" class="po-close" data-bs-dismiss="modal">&times;</button>
                </div>

                <div class="po-modal-body">
                    <div class="row">
                        <div class="col-lg-6 mb-3">
                            <label class="dark-label">Customer <span class="req">*</span></label>
                            <select class="dark-select" name="customer_id" id="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-6 mb-3">
                            <label class="dark-label">Staff</label>
                            <select class="dark-select" name="staff_id" id="staff_id">
                                <option value="">Select Staff</option>
                                <?php foreach($staffs as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-6 mb-3">
                            <label class="dark-label">Service</label>
                            <select class="dark-select" name="service_id" id="service_id">
                                <option value="">Select Service</option>
                                <?php foreach($services as $sv): ?>
                                    <option value="<?= $sv['id'] ?>"><?= e($sv['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3 mb-3">
                            <label class="dark-label">Date <span class="req">*</span></label>
                            <input type="date" class="dark-input" name="appointment_date" id="appointment_date" required>
                        </div>

                        <div class="col-lg-3 mb-3">
                            <label class="dark-label">Time <span class="req">*</span></label>
                            <input type="time" class="dark-input" name="appointment_time" id="appointment_time" required>
                        </div>

                        <div class="col-lg-6 mb-3">
                            <label class="dark-label">Status</label>
                            <select class="dark-select" name="status" id="appointment_status">
                                <option value="pending">Pending</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>

                        <div class="col-lg-6 mb-3">
                            <label class="dark-label">Notes / Remarks</label>
                            <textarea class="dark-textarea" name="notes" id="appointment_notes"></textarea>
                        </div>
                    </div>
                </div>

                <div class="po-modal-footer">
                    <button type="button" class="cancel-btn-dark" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="save-btn-dark">Save Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete_appointment">
    <input type="hidden" name="id" id="delete_id">
</form>

<form method="POST" id="bulkDeleteForm" style="display:none;">
    <input type="hidden" name="action" value="bulk_delete_appointments">
    <div id="bulkDeleteInputs"></div>
</form>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
    
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
const appointmentModal = new bootstrap.Modal(document.getElementById('appointmentModal'));

// Open modal for adding a new appointment
function openAppointmentModal() {
    document.getElementById('modalTitle').textContent = 'Add Appointment';
    document.getElementById('appointmentForm').reset();
    document.getElementById('appointment_id').value = '';
    appointmentModal.show();
}

// Open modal for editing an appointment
function editAppointment(id) {
    fetch('ajax_appointments.php?action=get&id=' + id)
        .then(res => res.json())
        .then(data => {
            if (!data.id) return;
            document.getElementById('modalTitle').textContent = 'Edit Appointment';
            document.getElementById('appointment_id').value = data.id;
            document.getElementById('customer_id').value = data.customer_id;
            document.getElementById('staff_id').value = data.staff_id;
            document.getElementById('service_id').value = data.service_id;
            document.getElementById('appointment_date').value = data.appointment_date;
            document.getElementById('appointment_time').value = data.appointment_time;
            document.getElementById('appointment_status').value = data.status;
            appointmentModal.show();
        });
}

// Delete a single appointment
function deleteAppointment(id) {
    if (!confirm('Delete this appointment?')) return;
    fetch('ajax_appointments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&id=' + id
    }).then(res => res.text()).then(r => {
        if (r === 'ok') location.reload();
        else alert(r);
    });
}

// Bulk delete selected appointments
function deleteSelectedAppointments(e) {
    e.preventDefault();
    const selected = Array.from(document.querySelectorAll('.rowCheck:checked')).map(chk => chk.value);
    if (selected.length === 0) { alert('Select at least one appointment.'); return; }
    if (!confirm('Delete selected appointments?')) return;

    const container = document.getElementById('bulkDeleteInputs');
    container.innerHTML = '';
    selected.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        container.appendChild(input);
    });
    document.getElementById('bulkDeleteForm').submit();
}

// Check/uncheck all rows
document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.rowCheck').forEach(chk => chk.checked = this.checked);
});



// Handle form submit (add/edit appointment)
document.getElementById('appointmentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'save');

    fetch('ajax_appointments.php', {
        method: 'POST',
        body: formData
    }).then(res => res.text())
      .then(r => {
          if (r === 'ok') location.reload();
          else alert(r);
      });
});

// Optional: mark appointment as completed
function markComplete(id) {
    if (!confirm('Mark this appointment as completed?')) return;
    fetch('ajax_appointments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mark_complete&appointment_id=' + id
    }).then(res => res.text()).then(r => {
        if (r === 'ok') location.reload();
        else alert(r);
    });
}
</script>

</body>
</html>