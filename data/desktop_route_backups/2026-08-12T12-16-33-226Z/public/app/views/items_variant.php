<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
}

$client_id = $_SESSION['client_id'];
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

// ================== FETCH ITEM ==================
$stmt = $conn->prepare("SELECT * FROM items WHERE id=? AND client_id=?");
if(!$stmt) die("Prepare failed: ".$conn->error);
$stmt->bind_param("ii", $item_id, $client_id);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();
$stmt->close();

if(!$item){
    die("Item not found");
}

// ================== FETCH VARIANTS ==================
$variants = [];
$stmt = $conn->prepare("SELECT * FROM item_variants WHERE item_id=? ORDER BY id DESC");
if(!$stmt) die("Prepare failed: ".$conn->error);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $variants[] = $r;
$stmt->close();
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Items Varitants - SaaS POS</title>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{
    --iv-bg:#050711;
    --iv-panel:#0b1024;
    --iv-purple:#7c3aed;
    --iv-purple2:#6d4cff;
    --iv-border:rgba(167,139,250,.30);
    --iv-text:#f8fafc;
    --iv-muted:#aeb8d5;
}

*{
    box-sizing:border-box;
}

html,
body{
    margin:0 !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    font-family:Inter,"Segoe UI",Arial,sans-serif !important;
    background:#050711 !important;
    color:var(--iv-text) !important;
}

body.items-variant-theme{
    background:
        radial-gradient(circle at 8% 4%, rgba(124,58,237,.42), transparent 30%),
        radial-gradient(circle at 88% 6%, rgba(14,165,233,.16), transparent 28%),
        linear-gradient(135deg,#050711 0%,#080b18 38%,#160d33 72%,#050711 100%) !important;
}

/* main layout */
body.items-variant-theme .container-scroll{
    display:flex !important;
    min-height:100vh !important;
    width:100% !important;
    background:
        linear-gradient(90deg, rgba(255,255,255,.024) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.020) 1px, transparent 1px),
        radial-gradient(circle at 4% 0%, rgba(124,58,237,.34), transparent 32%),
        radial-gradient(circle at 82% 0%, rgba(14,165,233,.11), transparent 32%),
        linear-gradient(135deg,#070a17 0%,#0a0e21 38%,#160d33 74%,#060912 100%) !important;
    background-size:46px 46px,46px 46px,auto,auto,auto !important;
}

/* sidebar ko disturb na karo */
body.items-variant-theme .sidebar{
    width:270px !important;
    flex:0 0 270px !important;
    min-height:100vh !important;
    background:#070b18 !important;
}

/* main panel */
body.items-variant-theme .main-panel{
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:transparent !important;
}

/* topbar dark */
body.items-variant-theme .topbar{
    position:fixed !important;
    top:0 !important;
    left:270px !important;
    right:0 !important;
    height:76px !important;
    z-index:9999 !important;
    background:rgba(5,8,18,.96) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    box-shadow:0 18px 45px rgba(0,0,0,.36) !important;
    backdrop-filter:blur(18px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(150%) !important;
    padding:0 !important;
}

/* content topbar ke neeche */
body.items-variant-theme .content-wrapper{
    padding:106px 24px 36px !important;
    flex:1 !important;
    overflow-y:auto !important;
    min-height:100vh !important;
    background:transparent !important;
}

/* page header glass */
body.items-variant-theme .content-wrapper > .d-flex{
    align-items:center !important;
    padding:22px 24px !important;
    margin-bottom:22px !important;
    border-radius:22px !important;
    border:1px solid rgba(167,139,250,.30) !important;
    background:
        radial-gradient(circle at 16% 0%, rgba(124,58,237,.25), transparent 36%),
        linear-gradient(135deg, rgba(18,24,50,.84), rgba(8,13,31,.72)) !important;
    box-shadow:0 24px 70px rgba(0,0,0,.34), inset 0 1px 0 rgba(255,255,255,.08) !important;
    backdrop-filter:blur(18px) saturate(150%) !important;
}

/* title */
body.items-variant-theme h4{
    color:#fff !important;
    font-size:28px !important;
    font-weight:950 !important;
    margin:0 !important;
    letter-spacing:-.04em !important;
    text-shadow:0 0 28px rgba(124,58,237,.65) !important;
}

/* add button */
body.items-variant-theme .btn-warning{
    min-height:44px !important;
    border:0 !important;
    border-radius:14px !important;
    padding:0 20px !important;
    color:#fff !important;
    font-weight:950 !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    box-shadow:0 17px 34px rgba(109,76,255,.35), inset 0 1px 0 rgba(255,255,255,.18) !important;
}

body.items-variant-theme .btn-warning:hover{
    color:#fff !important;
    filter:brightness(1.08) !important;
    transform:translateY(-1px) !important;
}

/* table card look */
body.items-variant-theme .table{
    margin:0 !important;
    color:#e5e7eb !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    overflow:hidden !important;
    border-radius:20px !important;
    border:1px solid rgba(167,139,250,.30) !important;
    background:
        radial-gradient(circle at 10% 0%, rgba(124,58,237,.22), transparent 34%),
        linear-gradient(135deg, rgba(12,17,36,.88), rgba(9,13,28,.74)) !important;
    box-shadow:0 22px 58px rgba(0,0,0,.34), inset 0 1px 0 rgba(255,255,255,.06) !important;
}

/* table header */
body.items-variant-theme .table thead th,
body.items-variant-theme .table-dark th{
    background:rgba(3,7,18,.98) !important;
    color:#ffffff !important;
    padding:15px 16px !important;
    border-color:rgba(139,92,246,.35) !important;
    font-size:12px !important;
    font-weight:950 !important;
    text-transform:uppercase !important;
    letter-spacing:.06em !important;
}

/* table body */
body.items-variant-theme .table tbody td{
    padding:16px !important;
    color:#e5e7eb !important;
    background:linear-gradient(135deg, rgba(17,24,49,.70), rgba(24,13,54,.46)) !important;
    border-color:rgba(148,163,184,.12) !important;
    font-weight:750 !important;
}

body.items-variant-theme .table tbody tr:hover td{
    background:linear-gradient(135deg, rgba(44,28,86,.78), rgba(15,23,42,.62)) !important;
}

body.items-variant-theme .table tbody td.text-center{
    color:#aeb8d5 !important;
    font-weight:850 !important;
}

/* action icons */
body.items-variant-theme .editVariant{
    color:#fbbf24 !important;
    font-size:18px !important;
}

body.items-variant-theme .deleteVariant{
    color:#fb7185 !important;
    font-size:18px !important;
}

/* modal dark */
body.items-variant-theme .modal-content{
    color:#f8fafc !important;
    border-radius:22px !important;
    border:1px solid rgba(139,92,246,.44) !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.36), transparent 38%),
        linear-gradient(135deg, rgba(10,15,31,.98), rgba(17,24,39,.92)) !important;
    box-shadow:0 30px 90px rgba(0,0,0,.68) !important;
    backdrop-filter:blur(18px) saturate(145%) !important;
}

body.items-variant-theme .modal-header,
body.items-variant-theme .modal-footer{
    border-color:rgba(148,163,184,.16) !important;
}

body.items-variant-theme #modalTitle,
body.items-variant-theme .modal-title{
    color:#fff !important;
    font-weight:950 !important;
}

body.items-variant-theme .btn-close{
    filter:invert(1) !important;
    opacity:1 !important;
}

/* labels */
body.items-variant-theme label{
    color:#e0e7ff !important;
    font-weight:900 !important;
    margin-bottom:7px !important;
}

/* inputs */
body.items-variant-theme .form-control{
    min-height:46px !important;
    border-radius:14px !important;
    background:rgba(8,13,31,.90) !important;
    color:#f8fafc !important;
    border:1px solid rgba(191,219,254,.30) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.05),0 12px 28px rgba(0,0,0,.18) !important;
    font-weight:850 !important;
}

body.items-variant-theme .form-control:focus{
    background:rgba(10,16,35,.96) !important;
    border-color:rgba(139,92,246,.90) !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.22), inset 0 1px 0 rgba(255,255,255,.08) !important;
}

/* modal buttons */
body.items-variant-theme .btn-dark{
    min-height:44px !important;
    border-radius:14px !important;
    padding:0 18px !important;
    color:#eaf0ff !important;
    background:rgba(10,16,33,.82) !important;
    border:1px solid rgba(148,163,184,.28) !important;
    font-weight:900 !important;
}

/* mobile */
@media(max-width:991px){
    body.items-variant-theme .sidebar{
        width:0 !important;
        flex:0 0 0 !important;
    }

    body.items-variant-theme .topbar{
        left:0 !important;
        height:72px !important;
    }

    body.items-variant-theme .content-wrapper{
        padding:96px 14px 24px !important;
    }

    body.items-variant-theme .content-wrapper > .d-flex{
        display:block !important;
        padding:18px !important;
    }

    body.items-variant-theme h4{
        font-size:24px !important;
        margin-bottom:14px !important;
    }

    body.items-variant-theme .btn-warning{
        width:100% !important;
    }

    body.items-variant-theme .table{
        min-width:760px !important;
    }
}
</style>
</head>

<body class="items-variant-theme">

    <div class="container-scroll">

        <?php include('partials/sidebar.php'); ?>

        <div class="main-panel">
            <div class="topbar"><?php include('partials/topbar.php'); ?></div>
            <div class="content-wrapper">
                <div class="d-flex justify-content-between mb-3">
                    <h4>Variants for: <?= htmlspecialchars($item['name']) ?></h4>
                    <button class="btn btn-warning mb-3" onclick="openAdd()">Add Variant</button>


                </div>

                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Size</th>
                            <th>Color</th>
                            <th>Actions</th>

                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($variants)): ?>
                        <tr>
                            <td colspan="4" class="text-center">No variants found</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach($variants as $v): ?>
                        <tr>
                            <td><?= $v['id'] ?></td>
                            <td><?= htmlspecialchars($v['size']) ?></td>
                            <td><?= htmlspecialchars($v['color']) ?></td>
                            <td>
                                <i class="bi bi-pencil-square text-warning me-2 editVariant" style="cursor:pointer"
                                    data-id="<?= $v['id'] ?>"
                                    data-size="<?= htmlspecialchars($v['size'], ENT_QUOTES) ?>"
                                    data-color="<?= htmlspecialchars($v['color'], ENT_QUOTES) ?>"></i>
                                <i class="bi bi-trash text-danger deleteVariant" style="cursor:pointer"
                                    data-id="<?= $v['id'] ?>"></i>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                    </tbody>
                </table>

            </div>
        </div>
    </div>

    <!-- MODAL -->
    <div class="modal fade" id="variantModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="variantForm">
                    <input type="hidden" name="variant_id" id="variant_id">
                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                    <div class="modal-header">
                        <h5 id="modalTitle">Add Variant</h5>
                        <button class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2"><label>Size</label><input type="text" name="size" id="size"
                                class="form-control" required></div>
                        <div class="mb-2"><label>Color</label><input type="text" name="color" id="color"
                                class="form-control" required></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-warning">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
    const modal = new bootstrap.Modal(document.getElementById('variantModal'));

    function openAdd() {
        document.getElementById('variantForm').reset();
        document.getElementById('variant_id').value = '';
        document.getElementById('modalTitle').innerText = "Add Variant";
        modal.show();
    }

    // EDIT
    document.querySelectorAll('.editVariant').forEach(icon => {
        icon.addEventListener('click', () => {
            document.getElementById('variant_id').value = icon.dataset.id;
            document.getElementById('size').value = icon.dataset.size;
            document.getElementById('color').value = icon.dataset.color;
            document.getElementById('modalTitle').innerText = "Edit Variant";
            modal.show();
        });
    });

    // DELETE
    document.querySelectorAll('.deleteVariant').forEach(icon => {
        icon.addEventListener('click', () => {
            if (!confirm("Delete this variant?")) return;
            fetch('ajax_items_variant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=delete&variant_id=' + icon.dataset.id
            }).then(() => location.reload());
        });
    });

    // SAVE
    document.getElementById('variantForm').addEventListener('submit', e => {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'save');
        fetch('ajax_items_variant.php', {
            method: 'POST',
            body: formData
        }).then(r => r.text()).then(res => {
            if (res == 'ok') location.reload();
            else alert(res);
        });
    });
    </script>


</body>

</html>