<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

// ================= AUTH =================
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$logged_user_id = $_SESSION['user_id'];
$logged_role_id = $_SESSION['role_id']; // 1=superadmin, 2=client

/* ================= AJAX ================= */
if(isset($_POST['ajax'])){

    // Toggle client feature
    if($_POST['ajax']==='toggle_client_feature' && $logged_role_id==1){
        $client_id = (int)$_POST['client_id'];
        $feature_key = $_POST['feature_key'];
        $enabled = (int)$_POST['enabled'];

        $stmt = $conn->prepare("INSERT INTO client_feature_overrides (client_id,feature_key,enabled)
                                VALUES (?,?,?)
                                ON DUPLICATE KEY UPDATE enabled=?");
        $stmt->bind_param("isii",$client_id,$feature_key,$enabled,$enabled);
        $stmt->execute();
        echo "ok";
        exit;
    }
}

/* ================= DATA ================= */
$all_features = [
    'fbr_integration'=>'FBR Integration',
    'wholesale'=>'Wholesale',
    'inventory'=>'Inventory',
    'industry_modules'=>'Industry Modules'
];

if($logged_role_id==1){
    // Superadmin view -> all clients + their features
    $clients_res = $conn->query("SELECT * FROM clients ORDER BY business_name");
    $clients = [];
    while($r=$clients_res->fetch_assoc()) $clients[]=$r;

    // Fetch all client overrides
    $overrides_res = $conn->query("SELECT * FROM client_feature_overrides");
    $overrides = [];
    while($r=$overrides_res->fetch_assoc()) $overrides[$r['client_id']][$r['feature_key']]=$r['enabled'];
} else {
    // Client view -> show enabled features
    $res = $conn->query("SELECT feature_key FROM client_feature_overrides WHERE client_id=$logged_user_id AND enabled=1");
    $client_features = [];
    while($r=$res->fetch_assoc()) $client_features[$r['feature_key']] = $all_features[$r['feature_key']] ?? $r['feature_key'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Feature Control</title>
<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<div class="container-scroll">
<?php include('partials/sidebar.php'); ?>

<div class="main-panel">
<div class="topbar"><?php include('partials/topbar.php'); ?></div>
<div class="content-wrapper">
<h4>Feature Control</h4>

<?php if($logged_role_id==1): ?>
    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>Client</th>
                <?php foreach($all_features as $key=>$label): ?>
                <th><?= $label ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach($clients as $client): ?>
            <tr>
                <td><?= htmlspecialchars($client['business_name']) ?></td>
                <?php foreach($all_features as $key=>$label): 
                    $enabled = $overrides[$client['id']][$key]??0;
                ?>
                <td class="text-center">
                    <input type="checkbox" class="featureToggle" 
                        data-client="<?= $client['id'] ?>" 
                        data-feature="<?= $key ?>" <?= $enabled?'checked':'' ?>>
                </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <h5>Your Features</h5>
    <ul>
        <?php foreach($client_features as $f): ?>
        <li><?= htmlspecialchars($f) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
document.querySelectorAll('.featureToggle').forEach(cb=>{
    cb.addEventListener('change',()=>{
        let client_id = cb.dataset.client;
        let feature_key = cb.dataset.feature;
        let enabled = cb.checked?1:0;

        fetch("",{
            method:"POST",
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`ajax=toggle_client_feature&client_id=${client_id}&feature_key=${feature_key}&enabled=${enabled}`
        }).then(r=>r.text()).then(r=>{
            if(r.trim()!="ok") alert("Error updating feature");
        });
    });
});
</script>

</body>
</html>
