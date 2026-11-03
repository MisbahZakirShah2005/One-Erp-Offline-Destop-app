<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if ($_SERVER['REQUEST_METHOD']=='POST'){

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT id, client_id, name, email, password, status FROM users WHERE email=?");
    if(!$stmt) die("Prepare failed: ".$conn->error);
    $stmt->bind_param("s",$email);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows==1){
        $stmt->bind_result($user_id, $client_id, $name, $email_db, $hashed_password, $status);
        $stmt->fetch();

        if($status!='active'){
            $_SESSION['login_error']="Your account is inactive!";
            header("Location: login.php"); exit;
        }

        if(password_verify($password,$hashed_password)){

            // Device log (optional)
            $device_key = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $stmt_device = $conn->prepare("SELECT id FROM devices WHERE device_key=? LIMIT 1");
            $stmt_device->bind_param("s",$device_key);
            $stmt_device->execute();
            $res_device = $stmt_device->get_result();
            if($res_device->num_rows==0){
                $stmt_insert_device = $conn->prepare("INSERT INTO devices (device_key,status) VALUES (?, 'active')");
                $stmt_insert_device->bind_param("s",$device_key);
                $stmt_insert_device->execute();
            }

            // Login log
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $stmt_loginlog = $conn->prepare("INSERT INTO login_logs (user_id, ip, device, created_at) VALUES (?,?,?,NOW())");
            $stmt_loginlog->bind_param("iss",$user_id,$ip,$device_key);
            $stmt_loginlog->execute();

            // =================== SESSION SETUP ===================
            $_SESSION['user_id'] = $user_id;
            $_SESSION['name'] = $name;
            $_SESSION['client_id'] = $client_id;
            
            // =================== CLIENT INDUSTRY / PHARMACY SESSION ===================
$_SESSION['client_industry'] = '';
$_SESSION['is_pharmacy_client'] = 0;

$stmt_industry = $conn->prepare("
    SELECT 
        c.industry_id,
        LOWER(TRIM(COALESCE(i.name, ''))) AS industry_name
    FROM clients c
    LEFT JOIN industries i ON i.id = c.industry_id
    WHERE c.id = ?
    LIMIT 1
");

if ($stmt_industry) {
    $stmt_industry->bind_param("i", $client_id);
    $stmt_industry->execute();
    $res_industry = $stmt_industry->get_result();
    $industryRow = $res_industry->fetch_assoc() ?? [];
    $stmt_industry->close();

    $industryName = strtolower(trim((string)($industryRow['industry_name'] ?? '')));

    $pharmacyWords = [
        'pharmacy',
        'pharma',
        'medical',
        'medicine',
        'medical store',
        'medicine store',
        'chemist',
        'pharmaceutical'
    ];

    foreach ($pharmacyWords as $word) {
        if ($industryName !== '' && strpos($industryName, $word) !== false) {
            $_SESSION['client_industry'] = 'pharmacy';
            $_SESSION['is_pharmacy_client'] = 1;
            break;
        }
    }
}

            // =================== CLIENT SETTINGS ===================
            $stmt_settings = $conn->prepare("SELECT * FROM client_settings WHERE client_id=? LIMIT 1");
            $stmt_settings->bind_param("i",$client_id);
            $stmt_settings->execute();
            $res_settings = $stmt_settings->get_result();
            $settings = $res_settings->fetch_assoc() ?? [];
            $stmt_settings->close();

            function getContrastColor($hexColor){
                $hexColor = str_replace("#","",$hexColor);
                $r = hexdec(substr($hexColor,0,2));
                $g = hexdec(substr($hexColor,2,2));
                $b = hexdec(substr($hexColor,4,2));
                return (($r*0.299 + $g*0.587 + $b*0.114) > 186) ? '#000000' : '#ffffff';
            }

            $settings['sidebar_color']       = $settings['sidebar_color'] ?? '#0d6efd';
            $settings['sidebar_text_color']  = $settings['sidebar_text_color'] ?? getContrastColor($settings['sidebar_color']);
            $settings['sidebar_hover_color'] = $settings['sidebar_hover_color'] ?? $settings['sidebar_color'];
            $settings['header_color']        = $settings['header_color'] ?? '#0d6efd';
            $settings['header_text_color']   = $settings['header_text_color'] ?? getContrastColor($settings['header_color']);
            $settings['header_hover_color']  = $settings['header_hover_color'] ?? $settings['header_color'];
            $_SESSION['client_settings'] = $settings;

            // =================== FETCH ROLE ===================
            $stmt_role = $conn->prepare("
                SELECT r.id, r.name 
                FROM roles r
                JOIN user_roles ur ON r.id = ur.role_id
                WHERE ur.user_id=? LIMIT 1
            ");
            $stmt_role->bind_param("i",$user_id);
            $stmt_role->execute();
            $stmt_role->bind_result($role_id,$role_name);
            $stmt_role->fetch();
            $_SESSION['role_id'] = $role_id ?? 0;
            $_SESSION['role'] = $role_name ?? '';
            $stmt_role->close();

            // =================== BRANCH ===================
            $branch_access = [];
            $branch_name = '';
            $_SESSION['branch_id'] = 0;

            if($role_name=='Client Admin' || $role_name=='Super Admin'){
                // Admin → default first branch
                $stmt_branch = $conn->prepare("SELECT id, name FROM branches WHERE client_id=? ORDER BY id ASC LIMIT 1");
                $stmt_branch->bind_param("i",$client_id);
                $stmt_branch->execute();
                $res_branch = $stmt_branch->get_result();
                if($row = $res_branch->fetch_assoc()){
                    $_SESSION['branch_id'] = $row['id'];
                    $branch_name = $row['name'];
                }

                // Admin → all branches
                $stmt_all = $conn->prepare("SELECT id FROM branches WHERE client_id=?");
                $stmt_all->bind_param("i",$client_id);
                $stmt_all->execute();
                $res_all = $stmt_all->get_result();
                while($b=$res_all->fetch_assoc()){
                    $branch_access[] = $b['id'];
                }

            } else {
                // Staff → only assigned branch
                $stmt_branch = $conn->prepare("
                    SELECT b.id, b.name 
                    FROM branches b
                    JOIN branch_users bu ON bu.branch_id = b.id
                    WHERE bu.user_id=?
                ");
                $stmt_branch->bind_param("i",$user_id);
                $stmt_branch->execute();
                $res_branch = $stmt_branch->get_result();
                while($row=$res_branch->fetch_assoc()){
                    $branch_access[] = $row['id'];
                    if($_SESSION['branch_id']==0){
                        $_SESSION['branch_id'] = $row['id'];
                        $branch_name = $row['name'];
                    }
                }
            }

            $_SESSION['branch_access'] = $branch_access;
            $_SESSION['branch_name'] = $branch_name;
// =================== REDIRECT BASED ON ROLE ===================
if($role_name == 'Super Admin'){
    header("Location: ../../public/app/views/dashboard_view.php");
} else {
    // Client Admin & Staff
    header("Location: ../../public/app/views/dashboard.php");
}
exit;

        } else {
            $_SESSION['login_error']="Incorrect password!";
            header("Location: ../../public/app/views/login_view.php"); exit;
        }

    } else {
        $_SESSION['login_error']="Email not found!";
        header("Location: ../../public/app/views/login_view.php"); exit;
    }
    $stmt->close();
}
?>