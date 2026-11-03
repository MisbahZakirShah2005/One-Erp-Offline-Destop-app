<?php
/*
|--------------------------------------------------------------------------
| properties.php
| State Agency / Real Estate Properties Inventory
|--------------------------------------------------------------------------
| Path:
| C:\xampp\htdocs\pos\public\app\views\properties.php
|
| Required tables:
| - re_properties
| - re_projects
| - users
|
| Partials:
| - partials/head.php
| - partials/sidebar.php
| - partials/topbar.php
*/

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['current_branch_id'] ?? 0));

if ($client_id <= 0) {
    die("Invalid client session");
}

/* ===================== HELPERS ===================== */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return ((int)$count) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return ((int)$count) > 0;
}

function redirect_with_message(string $type, string $message): void {
    header("Location: properties.php?msg_type=" . urlencode($type) . "&msg=" . urlencode($message));
    exit;
}

function normalize_option($value, array $allowed, string $default) {
    $value = trim((string)$value);
    return in_array($value, $allowed, true) ? $value : $default;
}

function moneyValue($value): float {
    $value = str_replace([',', 'Rs.', 'PKR', ' '], '', (string)$value);
    return (float)$value;
}

function propertyCode(mysqli $conn, int $client_id): string {
    $prefix = 'REP-' . date('Ymd') . '-';
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM re_properties 
        WHERE client_id = ?
          AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return $prefix . str_pad((string)((int)$count + 1), 4, '0', STR_PAD_LEFT);
}

function uploadPropertyImage(string $fieldName, int $client_id): ?string {
    if (
        !isset($_FILES[$fieldName]) ||
        !is_array($_FILES[$fieldName]) ||
        ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if (($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }

    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ((int)$_FILES[$fieldName]['size'] > $maxSize) {
        throw new RuntimeException('Image size must be less than 5 MB.');
    }

    $originalName = (string)($_FILES[$fieldName]['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Only JPG, PNG, WEBP and GIF images are allowed.');
    }

    $uploadDir = __DIR__ . '/uploads/real_estate/properties/' . $client_id;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $fileName = 'property_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to save uploaded image.');
    }

    return 'uploads/real_estate/properties/' . $client_id . '/' . $fileName;
}


function statusBadgeClass($status) {
    $map = [
        'Available' => 'badge-green',
        'On Hold'   => 'badge-orange',
        'Token'     => 'badge-yellow',
        'Booked'    => 'badge-purple',
        'Sold'      => 'badge-red',
        'Rented'    => 'badge-blue',
        'Inactive'  => 'badge-red',
    ];
    return $map[$status] ?? 'badge-blue';
}

function typeBadgeClass($type) {
    $map = [
        'Plot'       => 'badge-purple',
        'House'      => 'badge-blue',
        'Apartment'  => 'badge-yellow',
        'Flat'       => 'badge-yellow',
        'Shop'       => 'badge-orange',
        'Office'     => 'badge-orange',
        'Commercial' => 'badge-orange',
        'Farmhouse'  => 'badge-green',
        'Other'      => 'badge-blue',
    ];
    return $map[$type] ?? 'badge-blue';
}

function purposeBadgeClass($purpose) {
    return $purpose === 'Rent' ? 'badge-blue' : ($purpose === 'Both' ? 'badge-purple' : 'badge-green');
}

/* ===================== REQUIRED TABLE CHECK ===================== */
if (!tableExists($conn, 're_properties')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>re_properties table missing</h3>
            <p>Pehle <b>re_properties_schema.sql</b> phpMyAdmin me run karo.</p>
        </div>
    ");
}

/* ===================== OPTIONS ===================== */
$propertyTypes = ['Plot','House','Apartment','Flat','Shop','Office','Commercial','Farmhouse','Other'];
$purposes      = ['Sale','Rent','Both'];
$sizeUnits     = ['Sq Ft','Sq Yd','Marla','Kanal','Acre'];
$statuses      = ['Available','On Hold','Token','Booked','Sold','Rented','Inactive'];
$projectTypes  = ['Society','Apartment Project','Commercial Project','Mixed Project','Other'];

/* ===================== AGENTS ===================== */
$agents = [];

if (tableExists($conn, 'roles')) {
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.phone,
            u.role_id,
            COALESCE(r.name, '') AS role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.client_id = ?
          AND u.status = 'active'
          AND (
                u.role_id = 2
                OR LOWER(COALESCE(r.name, '')) IN (
                    'agent',
                    'staff',
                    'employee',
                    'sales agent',
                    'real estate agent',
                    'property agent'
                )
          )
        ORDER BY u.name ASC
    ");
    $stmt->bind_param("i", $client_id);
} else {
    $stmt = $conn->prepare("
        SELECT id, name, email, phone, role_id, '' AS role_name
        FROM users
        WHERE client_id = ?
          AND status = 'active'
          AND role_id = 2
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $client_id);
}

$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $agents[] = $row;
}
$stmt->close();

/* ===================== PROJECTS ===================== */
$projects = [];
if (tableExists($conn, 're_projects')) {
    $projectNameCol = columnExists($conn, 're_projects', 'project_name') ? 'project_name' : 'name';
    $stmt = $conn->prepare("
        SELECT id, `$projectNameCol` AS project_name
        FROM re_projects
        WHERE client_id = ?
        ORDER BY `$projectNameCol` ASC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $projects[] = $row;
    }
    $stmt->close();
}

/* ===================== POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_project') {
        if (!tableExists($conn, 're_projects')) {
            redirect_with_message('danger', 're_projects table missing. Schema SQL run karo.');
        }

        $project_name   = trim($_POST['project_name'] ?? '');
        $developer_name = trim($_POST['developer_name'] ?? '');
        $city           = trim($_POST['project_city'] ?? '');
        $location       = trim($_POST['project_location'] ?? '');
        $project_type   = normalize_option($_POST['project_type'] ?? 'Society', $projectTypes, 'Society');
        $description    = trim($_POST['project_description'] ?? '');
        $status         = 'Active';
        $branch_id_db   = $branch_id > 0 ? $branch_id : null;

        if ($project_name === '') {
            redirect_with_message('danger', 'Project name is required.');
        }

        $stmt = $conn->prepare("
            INSERT INTO re_projects
            (client_id, branch_id, project_name, developer_name, city, location, project_type, status, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iisssssssi",
            $client_id,
            $branch_id_db,
            $project_name,
            $developer_name,
            $city,
            $location,
            $project_type,
            $status,
            $description,
            $user_id
        );
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Project added successfully.');
    }

    if ($action === 'save_property') {
        $id                = (int)($_POST['id'] ?? 0);
        $project_id        = (int)($_POST['project_id'] ?? 0);
        $property_code     = trim($_POST['property_code'] ?? '');
        $title             = trim($_POST['title'] ?? '');
        $property_type     = normalize_option($_POST['property_type'] ?? 'Plot', $propertyTypes, 'Plot');
        $purpose           = normalize_option($_POST['purpose'] ?? 'Sale', $purposes, 'Sale');
        $city              = trim($_POST['city'] ?? '');
        $area_location     = trim($_POST['area_location'] ?? '');
        $block_name        = trim($_POST['block_name'] ?? '');
        $street_no         = trim($_POST['street_no'] ?? '');
        $plot_no           = trim($_POST['plot_no'] ?? '');
        $sizeRaw           = trim((string)($_POST['size_value'] ?? ''));
        $size_value        = (float)$sizeRaw;
        $size_unit_raw     = $_POST['size_unit'] ?? 'Sq Yd';

        if (preg_match('/\b(Sq Ft|Sq Yd|Marla|Kanal|Acre)\b/i', $sizeRaw, $m)) {
            $matchedUnit = strtolower($m[1]);
            $unitMap = [
                'sq ft' => 'Sq Ft',
                'sq yd' => 'Sq Yd',
                'marla' => 'Marla',
                'kanal' => 'Kanal',
                'acre'  => 'Acre',
            ];
            $size_unit_raw = $unitMap[$matchedUnit] ?? $size_unit_raw;
        }

        $size_unit         = normalize_option($size_unit_raw, $sizeUnits, 'Sq Yd');
        $demand_price      = moneyValue($_POST['demand_price'] ?? 0);
        $min_price         = moneyValue($_POST['min_price'] ?? 0);
        $rent_price        = moneyValue($_POST['rent_price'] ?? 0);
        $owner_name        = trim($_POST['owner_name'] ?? '');
        $owner_phone       = trim($_POST['owner_phone'] ?? '');
        $owner_email       = trim($_POST['owner_email'] ?? '');
        $owner_address     = trim($_POST['owner_address'] ?? '');
        $assigned_agent_id = (int)($_POST['assigned_agent_id'] ?? 0);
        $status            = normalize_option($_POST['status'] ?? 'Available', $statuses, 'Available');
        $description       = trim($_POST['description'] ?? '');
        $main_image        = trim($_POST['main_image'] ?? '');

        try {
            $uploadedImage = uploadPropertyImage('property_image', $client_id);
            if ($uploadedImage !== null) {
                $main_image = $uploadedImage;
            }
        } catch (Throwable $e) {
            redirect_with_message('danger', $e->getMessage());
        }

        if ($title === '') {
            redirect_with_message('danger', 'Property title is required.');
        }

        if ($city === '') {
            redirect_with_message('danger', 'City is required.');
        }

        if ($area_location === '') {
            redirect_with_message('danger', 'Location / Area is required.');
        }

        if ($demand_price <= 0 && $rent_price <= 0) {
            redirect_with_message('danger', 'Demand price or rent price is required.');
        }

        $project_id_db = $project_id > 0 ? $project_id : null;
        $agent_id_db   = $assigned_agent_id > 0 ? $assigned_agent_id : null;

        if ($property_code === '') {
            $property_code = propertyCode($conn, $client_id);
        }

        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE re_properties
                SET
                    project_id = ?,
                    property_code = ?,
                    title = ?,
                    property_type = ?,
                    purpose = ?,
                    city = ?,
                    area_location = ?,
                    block_name = ?,
                    street_no = ?,
                    plot_no = ?,
                    size_value = ?,
                    size_unit = ?,
                    demand_price = ?,
                    min_price = ?,
                    rent_price = ?,
                    owner_name = ?,
                    owner_phone = ?,
                    owner_email = ?,
                    owner_address = ?,
                    assigned_agent_id = ?,
                    status = ?,
                    description = ?,
                    main_image = ?
                WHERE id = ? AND client_id = ?
            ");
            $stmt->bind_param(
                "isssssssssdssssssssisssii",
                $project_id_db,
                $property_code,
                $title,
                $property_type,
                $purpose,
                $city,
                $area_location,
                $block_name,
                $street_no,
                $plot_no,
                $size_value,
                $size_unit,
                $demand_price,
                $min_price,
                $rent_price,
                $owner_name,
                $owner_phone,
                $owner_email,
                $owner_address,
                $agent_id_db,
                $status,
                $description,
                $main_image,
                $id,
                $client_id
            );
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Property updated successfully.');
        } else {
            $branch_id_db = $branch_id > 0 ? $branch_id : null;

            $stmt = $conn->prepare("
                INSERT INTO re_properties
                (
                    client_id, branch_id, project_id, property_code, title, property_type, purpose,
                    city, area_location, block_name, street_no, plot_no, size_value, size_unit,
                    demand_price, min_price, rent_price, owner_name, owner_phone, owner_email,
                    owner_address, assigned_agent_id, status, description, main_image, created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iiisssssssssdssssssssisssi",
                $client_id,
                $branch_id_db,
                $project_id_db,
                $property_code,
                $title,
                $property_type,
                $purpose,
                $city,
                $area_location,
                $block_name,
                $street_no,
                $plot_no,
                $size_value,
                $size_unit,
                $demand_price,
                $min_price,
                $rent_price,
                $owner_name,
                $owner_phone,
                $owner_email,
                $owner_address,
                $agent_id_db,
                $status,
                $description,
                $main_image,
                $user_id
            );
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Property added successfully.');
        }
    }

    if ($action === 'delete_property') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            redirect_with_message('danger', 'Invalid property id.');
        }

        $stmt = $conn->prepare("DELETE FROM re_properties WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Property deleted successfully.');
    }

    if ($action === 'import_properties') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            redirect_with_message('danger', 'Please choose a valid CSV file.');
        }

        $handle = fopen($_FILES['import_file']['tmp_name'], 'r');
        if (!$handle) {
            redirect_with_message('danger', 'Unable to read uploaded CSV file.');
        }

        /*
         * CSV order:
         * title,property_type,project_name,city,location,purpose,demand_price,size_value,size_unit,status,agent_id,owner_name,owner_phone,description
         */
        fgetcsv($handle);
        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $title = trim($row[0] ?? '');
            $city  = trim($row[3] ?? '');
            $area  = trim($row[4] ?? '');

            if ($title === '' || $city === '' || $area === '') {
                $skipped++;
                continue;
            }

            $project_id_db = null;
            $project_name = trim($row[2] ?? '');
            if ($project_name !== '' && tableExists($conn, 're_projects')) {
                $stmt = $conn->prepare("SELECT id FROM re_projects WHERE client_id = ? AND project_name = ? LIMIT 1");
                $stmt->bind_param("is", $client_id, $project_name);
                $stmt->execute();
                $stmt->bind_result($projectFound);
                if ($stmt->fetch()) {
                    $project_id_db = (int)$projectFound;
                }
                $stmt->close();
            }

            $branch_id_db       = $branch_id > 0 ? $branch_id : null;
            $property_code      = propertyCode($conn, $client_id);
            $property_type      = normalize_option($row[1] ?? 'Plot', $propertyTypes, 'Plot');
            $purpose            = normalize_option($row[5] ?? 'Sale', $purposes, 'Sale');
            $demand_price       = moneyValue($row[6] ?? 0);
            $size_value         = (float)($row[7] ?? 0);
            $size_unit          = normalize_option($row[8] ?? 'Sq Yd', $sizeUnits, 'Sq Yd');
            $status             = normalize_option($row[9] ?? 'Available', $statuses, 'Available');
            $assigned_agent_id  = (int)($row[10] ?? 0);
            $agent_id_db        = $assigned_agent_id > 0 ? $assigned_agent_id : null;
            $owner_name         = trim($row[11] ?? '');
            $owner_phone        = trim($row[12] ?? '');
            $description        = trim($row[13] ?? '');

            $empty = '';
            $zero = 0.00;

            $stmt = $conn->prepare("
                INSERT INTO re_properties
                (
                    client_id, branch_id, project_id, property_code, title, property_type, purpose,
                    city, area_location, block_name, street_no, plot_no, size_value, size_unit,
                    demand_price, min_price, rent_price, owner_name, owner_phone, owner_email,
                    owner_address, assigned_agent_id, status, description, main_image, created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iiisssssssssdssssssssisssi",
                $client_id,
                $branch_id_db,
                $project_id_db,
                $property_code,
                $title,
                $property_type,
                $purpose,
                $city,
                $area,
                $empty,
                $empty,
                $empty,
                $size_value,
                $size_unit,
                $demand_price,
                $zero,
                $zero,
                $owner_name,
                $owner_phone,
                $empty,
                $empty,
                $agent_id_db,
                $status,
                $description,
                $empty,
                $user_id
            );

            try {
                $stmt->execute();
                $imported++;
            } catch (Throwable $e) {
                $skipped++;
            }
            $stmt->close();
        }

        fclose($handle);
        redirect_with_message('success', "Import completed. Imported: {$imported}, Skipped: {$skipped}");
    }
}

/* ===================== FILTERS ===================== */
$search       = trim($_GET['search'] ?? '');
$typeFilter   = trim($_GET['property_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$cityFilter   = trim($_GET['city'] ?? '');
$projectFilter = (int)($_GET['project_id'] ?? 0);
$agentFilter  = (int)($_GET['agent_id'] ?? 0);
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

/* ===================== EXPORT CSV ===================== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $where = " WHERE p.client_id = ? ";
    $params = [$client_id];
    $types = 'i';

    if ($search !== '') {
        $where .= " AND (p.title LIKE ? OR p.property_code LIKE ? OR p.city LIKE ? OR p.area_location LIKE ? OR p.owner_name LIKE ? OR p.owner_phone LIKE ?)";
        $like = "%{$search}%";
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= 'ssssss';
    }
    if ($typeFilter !== '') {
        $where .= " AND p.property_type = ?";
        $params[] = $typeFilter;
        $types .= 's';
    }
    if ($statusFilter !== '') {
        $where .= " AND p.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($cityFilter !== '') {
        $where .= " AND p.city = ?";
        $params[] = $cityFilter;
        $types .= 's';
    }
    if ($projectFilter > 0) {
        $where .= " AND p.project_id = ?";
        $params[] = $projectFilter;
        $types .= 'i';
    }
    if ($agentFilter > 0) {
        $where .= " AND p.assigned_agent_id = ?";
        $params[] = $agentFilter;
        $types .= 'i';
    }
    if ($dateFrom !== '') {
        $where .= " AND DATE(p.created_at) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where .= " AND DATE(p.created_at) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }

    $sql = "
        SELECT
            p.*,
            pr.project_name,
            u.name AS agent_name
        FROM re_properties p
        LEFT JOIN re_projects pr ON pr.id = p.project_id AND pr.client_id = p.client_id
        LEFT JOIN users u ON u.id = p.assigned_agent_id
        $where
        ORDER BY p.id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=properties_' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID', 'Code', 'Title', 'Type', 'Purpose', 'Project', 'City', 'Location',
        'Block', 'Plot No', 'Size', 'Demand Price', 'Rent Price', 'Status',
        'Owner Name', 'Owner Phone', 'Agent', 'Created At'
    ]);

    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['id'],
            $row['property_code'],
            $row['title'],
            $row['property_type'],
            $row['purpose'],
            $row['project_name'],
            $row['city'],
            $row['area_location'],
            $row['block_name'],
            $row['plot_no'],
            $row['size_value'] . ' ' . $row['size_unit'],
            $row['demand_price'],
            $row['rent_price'],
            $row['status'],
            $row['owner_name'],
            $row['owner_phone'],
            $row['agent_name'],
            $row['created_at'],
        ]);
    }

    fclose($out);
    $stmt->close();
    exit;
}

/* ===================== FLASH ===================== */
$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ===================== STATS ===================== */
function propertyCount(mysqli $conn, int $client_id, string $where = '', array $params = [], string $types = ''): int {
    $sql = "SELECT COUNT(*) FROM re_properties WHERE client_id = ? " . $where;
    $stmt = $conn->prepare($sql);
    $allParams = array_merge([$client_id], $params);
    $allTypes = 'i' . $types;
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count;
}

$totalProperties = propertyCount($conn, $client_id);
$availableCount  = propertyCount($conn, $client_id, " AND status = 'Available'");
$soldCount       = propertyCount($conn, $client_id, " AND status = 'Sold'");
$rentedCount     = propertyCount($conn, $client_id, " AND status = 'Rented'");
$plotCount       = propertyCount($conn, $client_id, " AND property_type = 'Plot'");
$commercialCount = propertyCount($conn, $client_id, " AND property_type IN ('Commercial','Shop','Office')");

/* ===================== CITY DROPDOWN ===================== */
$cities = [];
$stmt = $conn->prepare("
    SELECT DISTINCT city
    FROM re_properties
    WHERE client_id = ?
      AND city IS NOT NULL
      AND city != ''
    ORDER BY city ASC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $cities[] = $row['city'];
}
$stmt->close();

/* ===================== FETCH PROPERTIES ===================== */
$where = " WHERE p.client_id = ? ";
$params = [$client_id];
$types = 'i';

if ($search !== '') {
    $where .= " AND (p.title LIKE ? OR p.property_code LIKE ? OR p.city LIKE ? OR p.area_location LIKE ? OR p.owner_name LIKE ? OR p.owner_phone LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= 'ssssss';
}
if ($typeFilter !== '') {
    $where .= " AND p.property_type = ?";
    $params[] = $typeFilter;
    $types .= 's';
}
if ($statusFilter !== '') {
    $where .= " AND p.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($cityFilter !== '') {
    $where .= " AND p.city = ?";
    $params[] = $cityFilter;
    $types .= 's';
}
if ($projectFilter > 0) {
    $where .= " AND p.project_id = ?";
    $params[] = $projectFilter;
    $types .= 'i';
}
if ($agentFilter > 0) {
    $where .= " AND p.assigned_agent_id = ?";
    $params[] = $agentFilter;
    $types .= 'i';
}
if ($dateFrom !== '') {
    $where .= " AND DATE(p.created_at) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '') {
    $where .= " AND DATE(p.created_at) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

$countSql = "
    SELECT COUNT(*)
    FROM re_properties p
    LEFT JOIN re_projects pr ON pr.id = p.project_id AND pr.client_id = p.client_id
    LEFT JOIN users u ON u.id = p.assigned_agent_id
    $where
";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$countStmt->bind_result($totalFiltered);
$countStmt->fetch();
$countStmt->close();

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "
    SELECT
        p.*,
        pr.project_name,
        u.name AS agent_name
    FROM re_properties p
    LEFT JOIN re_projects pr ON pr.id = p.project_id AND pr.client_id = p.client_id
    LEFT JOIN users u ON u.id = p.assigned_agent_id
    $where
    ORDER BY p.id DESC
    LIMIT ? OFFSET ?
";

$paramsPage = array_merge($params, [$perPage, $offset]);
$typesPage = $types . 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($typesPage, ...$paramsPage);
$stmt->execute();
$res = $stmt->get_result();

$propertiesData = [];
while ($row = $res->fetch_assoc()) {
    $propertiesData[] = $row;
}
$stmt->close();

$propertiesJson = json_encode($propertiesData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$queryBase = $_GET;
unset($queryBase['page']);
function pageUrl($page, $queryBase) {
    $queryBase['page'] = $page;
    return 'properties.php?' . http_build_query($queryBase);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Properties – ONE ERP Real Estate</title>

<?php include('partials/head.php'); ?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>

<style>
:root {
  --bg:#eef2f7;
  --card-bg:#ffffff;
  --border:#e0e5f2;
  --text-primary:#1f2736;
  --text-secondary:#64748b;
  --text-muted:#9ca3af;
  --primary:#c9a84c;
  --primary-light:#fff7df;
  --success:#3e7a3a;
  --success-light:#e8f5e7;
  --warning:#dc9b2d;
  --warning-light:#fff4df;
  --danger:#c94b57;
  --danger-light:#fde8ea;
  --purple:#8a6a20;
  --purple-light:#fff7df;
  --orange:#dc9b2d;
  --orange-light:#fff4df;
  --yellow:#b88720;
  --yellow-light:#fff8e6;
  --shadow:0 4px 12px rgba(0,0,0,.05);
  --shadow-md:0 14px 28px rgba(0,0,0,.12);
  --radius:12px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-primary);min-height:100vh;overflow-x:hidden;}
a{text-decoration:none;}
.container-scroll{display:flex;min-height:100vh;background:var(--bg);}
.sidebar{width:270px;flex-shrink:0;}
.main-panel{flex-grow:1;display:flex;flex-direction:column;min-width:0;background:var(--bg);}
.topbar{min-height:70px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;background:#fff;padding:0;}
.content-wrapper{padding:24px;flex:1;}

/* PAGE HEADER */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px;gap:16px;flex-wrap:wrap;}
.page-title{font-size:24px;font-weight:900;color:#243046;}
.breadcrumb-custom{display:flex;align-items:center;gap:5px;margin-top:4px;}
.breadcrumb-custom span,.breadcrumb-custom a{font-size:12px;color:var(--text-muted);}
.breadcrumb-custom .active{color:#c9a84c;font-weight:800;}
.page-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;border:none;transition:all .18s;font-family:'Inter',sans-serif;white-space:nowrap;}
.btn .material-icons-round{font-size:17px;}
.btn-outline{background:#fff;border:1px solid #e0e5f2;color:#2b3674;}
.btn-outline:hover{background:#f4f7fe;border-color:#c9a84c;color:#8a6a20;}
.btn-primary{background:linear-gradient(180deg,#f5c742,#d8a500);color:#111;box-shadow:0 4px 12px rgba(216,165,0,.25);}
.btn-primary:hover{background:linear-gradient(180deg,#e8bb34,#c99700);transform:translateY(-1px);}
.btn-success{background:linear-gradient(180deg,#4f9e4a,#3e7a3a);color:#fff;}
.btn-danger{background:linear-gradient(180deg,#c94b57,#9f2430);color:#fff;}
.btn-sm{padding:6px 12px;font-size:12px;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px;}
.stat-card{border:none;border-radius:14px;min-height:112px;padding:18px 16px;color:#fff;position:relative;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12);}
.stat-card::after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12);}
.stat-card::before{content:'';position:absolute;width:70px;height:70px;border-radius:50%;right:45px;bottom:-28px;background:rgba(255,255,255,.08);}
.stat-card:nth-child(1){background:linear-gradient(135deg,#9f2430,#c94b57);}
.stat-card:nth-child(2){background:linear-gradient(135deg,#b27b16,#d8a33a);}
.stat-card:nth-child(3){background:linear-gradient(135deg,#b87a16,#dc9b2d);}
.stat-card:nth-child(4){background:linear-gradient(135deg,#386c58,#4c9075);}
.stat-card:nth-child(5){background:linear-gradient(135deg,#111c44,#20346d);}
.stat-card:nth-child(6){background:linear-gradient(135deg,#4c2f75,#7a4cb0);}
.stat-top{display:flex;justify-content:space-between;position:relative;z-index:1;}
.stat-label{font-size:12px;font-weight:800;color:rgba(255,255,255,.88);}
.stat-value{font-size:30px;font-weight:900;color:#fff;margin-top:7px;position:relative;z-index:1;}
.stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.20);}
.stat-icon .material-icons-round{font-size:21px;color:#fff;}
.stat-helper{font-size:11px;color:rgba(255,255,255,.85);position:relative;z-index:1;margin-top:4px;}

/* FILTERS */
.filter-card{background:#fff;border:1px solid #e0e5f2;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.05);padding:16px 20px;margin-bottom:22px;}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.filter-group{display:flex;flex-direction:column;gap:4px;flex:1;min-width:130px;}
.filter-group label{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;}
.filter-group input,.filter-group select{background:#f4f7fe;border:1px solid #e0e5f2;border-radius:10px;color:#2b3674;font-weight:700;height:42px;padding:8px 10px;font-size:12.5px;font-family:'Inter',sans-serif;outline:none;}
.filter-group input:focus,.filter-group select:focus{border-color:#c9a84c;background:#fff;box-shadow:0 0 0 3px rgba(201,168,76,.12);}
.filter-group.search-wide{flex:2;min-width:220px;}
.filter-actions{display:flex;gap:8px;align-items:flex-end;}

/* TABLE */
.table-card{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.05);overflow:hidden;}
.table-header{padding:16px 20px;border-bottom:1px solid #edf2f7;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;}
.table-title{font-size:18px;font-weight:900;color:#1a202c;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:1250px;}
thead th{background:#f8fafc;color:#64748b;font-size:13px;font-weight:800;padding:14px 12px;border-bottom:2px solid #edf2f7;text-align:left;white-space:nowrap;}
tbody td{padding:14px 12px;color:#334155;font-size:14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;white-space:nowrap;}
tbody tr:hover{background:#fbfcfe;}
.prop-thumb{width:44px;height:36px;border-radius:8px;object-fit:cover;border:1px solid #e2e8f0;background:#f3f4f6;}
.prop-title-cell{display:flex;align-items:center;gap:10px;}
.prop-title-text{font-weight:800;font-size:13px;color:#1e293b;max-width:180px;white-space:normal;line-height:1.3;}
.prop-sub{font-size:11px;color:#94a3b8;margin-top:1px;}
.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:900;letter-spacing:.2px;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-purple{background:#fef3c7;color:#92400e;}
.badge-orange{background:#ffedd5;color:#9a3412;}
.badge-yellow{background:#fef9c3;color:#854d0e;}
.badge-green{background:#dcfce7;color:#14532d;}
.badge-red{background:#fee2e2;color:#7f1d1d;}
.action-btns{display:flex;gap:5px;}
.action-btn{width:30px;height:30px;border-radius:7px;border:none;background:transparent;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:.15s;}
.action-btn .material-icons-round{font-size:17px;}
.action-btn.view{color:#3b6cf4;}
.action-btn.edit{color:#d8a500;}
.action-btn.del{color:#c94b57;}
.action-btn:hover.view{background:#eef2ff;}
.action-btn:hover.edit{background:#fef3c7;}
.action-btn:hover.del{background:#fee2e2;}
.pagination-bar{display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-top:1px solid #e5e7eb;gap:12px;flex-wrap:wrap;}
.pagination-info{font-size:12.5px;color:#64748b;}
.pagination-controls{display:flex;align-items:center;gap:5px;}
.page-btn{min-width:32px;height:32px;padding:0 8px;border-radius:6px;border:1px solid #e0e5f2;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#64748b;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
.page-btn.active{background:#d8a500;border-color:#d8a500;color:#fff;}
.page-btn.disabled{opacity:.45;pointer-events:none;}
.empty-state{text-align:center;padding:50px 20px;color:#94a3b8;}
.empty-state .material-icons-round{font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;}

/* MODAL - TAX SUMMARY STYLE */
.modal-overlay{
  display:none;
  position:fixed;
  inset:0;
  z-index:99999;
  background:rgba(0,0,0,.62);
  backdrop-filter:blur(6px);
  padding:32px 16px;
  overflow-y:auto;
  overflow-x:hidden;
  align-items:flex-start;
  justify-content:center;
}

.modal-overlay.open{
  display:flex;
}

.modal-box{
  width:100%;
  max-width:700px;
  max-height:none;
  background:#fff;
  border-radius:18px;
  box-shadow:0 30px 90px rgba(0,0,0,.30);
  overflow:hidden;
  margin:auto 0;
  display:block;
  border:1px solid rgba(255,255,255,.10);
}

#addModal .modal-box{
  max-width:700px;
}

.modal-header{
  height:74px;
  background:linear-gradient(135deg,#111 0%,#1a1a1a 100%);
  padding:0 24px;
  border-bottom:none;
  display:flex;
  align-items:center;
  justify-content:space-between;
  position:sticky;
  top:0;
  z-index:5;
}

.modal-title{
  color:#fff;
  font-size:18px;
  font-weight:900;
  letter-spacing:-.3px;
}

.modal-close{
  width:34px;
  height:34px;
  border-radius:9px;
  cursor:pointer;
  background:rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.12);
  color:#aaa;
  display:flex;
  align-items:center;
  justify-content:center;
  transition:all .18s ease;
}

.modal-close:hover{
  background:rgba(239,68,68,.20);
  color:#ef4444;
}

.modal-close .material-icons-round{
  font-size:21px;
}

.modal-body{
  padding:24px;
  max-height:calc(100vh - 230px);
  overflow-y:auto;
  overflow-x:hidden;
  background:#fff;
}

.modal-body::-webkit-scrollbar{
  width:7px;
}

.modal-body::-webkit-scrollbar-track{
  background:#f1f5f9;
  border-radius:999px;
}

.modal-body::-webkit-scrollbar-thumb{
  background:#cbd5e1;
  border-radius:999px;
}

.modal-body::-webkit-scrollbar-thumb:hover{
  background:#94a3b8;
}

.modal-footer{
  background:#fff;
  border-top:1px solid #e5e7eb;
  padding:16px 24px;
  display:flex;
  justify-content:flex-end;
  gap:10px;
  flex-wrap:wrap;
  position:sticky;
  bottom:0;
  z-index:5;
}

.form-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:18px 14px;
}

.form-group{
  display:flex;
  flex-direction:column;
  gap:8px;
}

.form-group.full{
  grid-column:1/-1;
}

.form-group label{
  color:#374151;
  font-size:12px;
  font-weight:900;
  text-transform:uppercase;
  letter-spacing:.45px;
}

.form-group input,
.form-group select,
.form-group textarea{
  width:100%;
  min-height:40px;
  background:#fafafa;
  border:1.5px solid #e5e7eb;
  border-radius:9px;
  padding:10px 12px;
  color:#1f2937;
  font-size:14px;
  font-weight:500;
  font-family:'Inter',sans-serif;
  outline:none;
  transition:all .18s ease;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
  border-color:#c9a84c;
  background:#fff;
  box-shadow:0 0 0 3px rgba(201,168,76,.12);
}

.form-group textarea{
  resize:vertical;
  min-height:76px;
}

.form-err-msg{
  display:block;
  color:#c94b57;
  font-size:11px;
  font-weight:700;
  min-height:0;
}

.import-note{
  background:#fff8e6;
  border:1px solid #f5d77c;
  color:#8a6a20;
  border-radius:8px;
  padding:10px 14px;
  font-size:12.5px;
  display:flex;
  align-items:flex-start;
  gap:8px;
  margin-bottom:14px;
}

body.modal-open-custom{
  overflow:hidden;
}

.view-img{width:100%;height:190px;border-radius:10px;object-fit:cover;margin-bottom:16px;border:1px solid #e5e7eb;background:#f3f4f6;}
.view-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.view-item label{font-size:10.5px;font-weight:900;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:3px;}
.view-item span{font-size:13px;font-weight:700;color:#1e293b;}
.view-desc{margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb;}
.view-desc label{font-size:10.5px;font-weight:900;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:5px;}
.view-desc p{font-size:13px;color:#64748b;line-height:1.6;}


/* PROPERTY MODAL SPACING + IMAGE UPLOAD */
#addModal .modal-box{
  max-width:740px;
}

#addModal .modal-body{
  padding:26px 24px 22px;
  max-height:calc(100vh - 220px);
}

#addModal .form-grid{
  gap:20px 16px;
}

#addModal .form-group{
  gap:9px;
}

#addModal .form-group label{
  margin:0;
  line-height:1.1;
}

#addModal .form-group input,
#addModal .form-group select,
#addModal .form-group textarea{
  min-height:42px;
}

.image-upload-box{
  border:1.5px dashed #d6dbe6;
  background:#fafafa;
  border-radius:10px;
  padding:12px;
  display:flex;
  gap:12px;
  align-items:center;
  transition:all .18s ease;
}

.image-upload-box:hover{
  border-color:#c9a84c;
  background:#fffdf5;
}

.image-upload-preview{
  width:78px;
  height:58px;
  border-radius:9px;
  background:#f1f5f9;
  border:1px solid #e5e7eb;
  display:flex;
  align-items:center;
  justify-content:center;
  overflow:hidden;
  flex:0 0 auto;
}

.image-upload-preview img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:none;
}

.image-upload-preview .material-icons-round{
  color:#94a3b8;
  font-size:28px;
}

.image-upload-content{
  flex:1;
  min-width:0;
}

.image-upload-content input[type="file"]{
  width:100%;
  min-height:auto;
  padding:9px 10px;
  background:#fff;
}

.image-upload-help{
  margin-top:6px;
  font-size:11.5px;
  color:#64748b;
  font-weight:600;
}


@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr);}}
@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr);}.form-grid,.view-grid{grid-template-columns:1fr;}.sidebar{width:240px;}}
@media(max-width:640px){.stats-grid{grid-template-columns:1fr;}.page-actions{width:100%;}.page-actions .btn{flex:1;justify-content:center;}.filter-row{flex-direction:column;align-items:stretch;}.filter-group,.filter-actions{width:100%;}.filter-actions .btn{flex:1;justify-content:center;}}

/* =====================================================
   PROPERTIES.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
   Paste before
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-border:rgba(148,163,184,.14);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT / SIDEBAR ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    background:transparent !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    background:transparent !important;
    overflow:visible !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

#dashboardSidebar .sidebar-wrap{
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
}

#dashboardSidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

#dashboardSidebar .sidebar-menu{
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
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow,
body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon{
    margin:0 !important;
}

/* ================= TOPBAR / PAGE BACKGROUND ================= */
.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid var(--oms-border) !important;
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
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
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
    position:relative;
    z-index:1;
}

/* ================= HEADER ================= */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.breadcrumb-custom span,
.breadcrumb-custom a,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.breadcrumb-custom .active{
    color:#c4b5fd !important;
    font-weight:900 !important;
}

.page-actions{
    gap:10px !important;
}

/* ================= BUTTONS ================= */
.btn{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    transition:.22s ease !important;
}

.btn-primary,
.filter-actions .btn-primary{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.page-actions .btn-primary{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
}

.btn-outline{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.btn-success{
    background:linear-gradient(135deg,#047857,#16a34a) !important;
    color:#fff !important;
}

.btn-danger{
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#fff !important;
}

.btn:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
    color:#fff !important;
}

/* ================= STATS CARDS ================= */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(6,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:138px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate;
    transition:.25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%) !important;
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
    background:rgba(255,255,255,.10) !important;
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card:nth-child(1){background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-card:nth-child(2){background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-card:nth-child(3){background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.stat-card:nth-child(4){background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.stat-card:nth-child(5){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.stat-card:nth-child(6){background:linear-gradient(135deg,#7c3aed,#a855f7) !important;}

.stat-label{
    font-size:14px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
}

.stat-value{
    font-size:30px !important;
    font-weight:900 !important;
    color:#fff !important;
}

.stat-helper{
    color:rgba(255,255,255,.82) !important;
    font-weight:700 !important;
}

.stat-icon{
    background:rgba(255,255,255,.16) !important;
    border:1px solid rgba(255,255,255,.12) !important;
}

/* ================= GLASS PANELS ================= */
.filter-card,
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    color:#e5e7eb !important;
}

.filter-card:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-card{
    padding:16px !important;
    margin-bottom:18px !important;
}

.table-card{
    overflow:hidden !important;
}

/* ================= FILTER INPUTS ================= */
.filter-group label{
    color:#cbd5e1 !important;
    font-weight:900 !important;
}

.filter-group input,
.filter-group select{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-group input::placeholder{
    color:#94a3b8 !important;
}

.filter-group input:focus,
.filter-group select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-group select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* ================= TABLE HEADER ================= */
.table-header{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
}

.table-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.table-header div[style]{
    color:#94a3b8 !important;
}

/* ================= TABLE DARK GLASS ================= */
.table-wrap,
table,
tbody,
tbody tr{
    background:transparent !important;
}

table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:14px 12px !important;
    white-space:nowrap;
}

tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-top:none !important;
    padding:14px 12px !important;
    vertical-align:middle;
    font-size:14px !important;
    font-weight:600;
    white-space:nowrap;
}

tbody tr:hover,
tbody tr:hover td,
tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

tbody tr:focus,
tbody tr:active,
tbody td:focus,
tbody td:active,
tbody td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

tbody td.text-center,
tbody td.py-4,
tbody td[colspan],
.empty-state{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

.prop-title-text,
tbody td div[style*="font-weight:800"],
tbody td[style*="font-weight:900"]{
    color:#f8fafc !important;
}

.prop-sub,
tbody td div[style*="color:#94a3b8"],
tbody td[style*="color:#9ca3af"],
tbody td[style*="color:#64748b"]{
    color:#94a3b8 !important;
}

.prop-thumb{
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.62) !important;
}

table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

/* ================= BADGES ================= */
.badge{
    border:1px solid rgba(148,163,184,.18) !important;
}

.badge-blue{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border-color:rgba(59,130,246,.24) !important;
}

.badge-purple{
    background:rgba(124,58,237,.14) !important;
    color:#c4b5fd !important;
    border-color:rgba(124,58,237,.24) !important;
}

.badge-orange,
.badge-yellow{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.badge-green{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

.badge-red{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

/* ================= ACTION BUTTONS ================= */
.action-btn{
    width:34px !important;
    height:34px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.62) !important;
    transition:.22s ease !important;
}

.action-btn.view{color:#60a5fa !important;}
.action-btn.edit{color:#fbbf24 !important;}
.action-btn.del{color:#f87171 !important;}

.action-btn:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.20) !important;
    color:#fff !important;
}

/* ================= PAGINATION ================= */
.pagination-bar{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#94a3b8 !important;
}

.pagination-info{
    color:#94a3b8 !important;
}

.page-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:10px !important;
}

.page-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.42) !important;
}

/* ================= ALERT ================= */
.alert{
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.58)) !important;
    color:#e5e7eb !important;
    box-shadow:0 16px 36px rgba(0,0,0,.26);
}

.alert-success{
    border-color:rgba(34,197,94,.25) !important;
}

.alert-danger{
    border-color:rgba(239,68,68,.25) !important;
}

/* ================= SCROLLBAR ================= */
.table-wrap::-webkit-scrollbar,
.modal-body::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-wrap::-webkit-scrollbar-track,
.modal-body::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb,
.modal-body::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb:hover,
.modal-body::-webkit-scrollbar-thumb:hover,
.content-wrapper::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

/* =====================================================
   PROPERTY POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

.modal-overlay{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
    align-items:flex-start !important;
    justify-content:center !important;
    padding:18px 20px !important;
}

.modal-overlay.open{
    display:flex !important;
}

.modal-box,
#addModal .modal-box,
#projectModal .modal-box,
#importModal .modal-box,
#viewModal .modal-box,
#confirmModal .modal-box{
    width:100% !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    max-height:92vh !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#addModal .modal-box{
    max-width:760px !important;
}

#projectModal .modal-box,
#viewModal .modal-box{
    max-width:640px !important;
}

#importModal .modal-box{
    max-width:520px !important;
}

#confirmModal .modal-box{
    max-width:440px !important;
}

.modal-header{
    height:auto !important;
    min-height:58px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

.modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

.modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

.modal-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    color:transparent !important;
    opacity:1 !important;
    box-shadow:none !important;
    padding:0 !important;
    font-size:0 !important;
    line-height:1 !important;
}

.modal-close .material-icons-round{
    opacity:0 !important;
}

.modal-close::before,
.modal-close::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    left:50% !important;
    width:18px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:2px !important;
    transform-origin:center !important;
}

.modal-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

.modal-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

.modal-close:hover{
    background:#ef4444 !important;
}

.modal-body,
#addModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

.modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

.form-grid,
#addModal .form-grid{
    gap:12px !important;
}

.form-group label,
#addModal .form-group label,
#projectModal .form-group label,
#importModal .form-group label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

.form-group input,
.form-group select,
.form-group textarea,
#addModal .form-group input,
#addModal .form-group select,
#addModal .form-group textarea,
#projectModal .form-group input,
#projectModal .form-group select,
#projectModal .form-group textarea,
#importModal .form-group input,
#importModal .form-group select,
#importModal .form-group textarea{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    min-height:40px !important;
    height:40px !important;
    padding:7px 12px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
    outline:none !important;
}

.form-group textarea,
#addModal .form-group textarea,
#projectModal .form-group textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

.form-group input::placeholder,
.form-group textarea::placeholder{
    color:#94a3b8 !important;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
}

.form-group select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.form-err-msg{
    color:#f87171 !important;
    font-weight:800 !important;
}

/* Image upload */
.image-upload-box{
    background:rgba(15,23,42,.45) !important;
    border:1.5px dashed rgba(139,92,246,.42) !important;
    border-radius:12px !important;
}

.image-upload-box:hover{
    background:rgba(109,76,255,.10) !important;
    border-color:rgba(139,92,246,.62) !important;
}

.image-upload-preview{
    background:rgba(15,23,42,.72) !important;
    border:1px solid rgba(148,163,184,.16) !important;
}

.image-upload-preview .material-icons-round{
    color:#a78bfa !important;
}

.image-upload-content input[type="file"]{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
}

.image-upload-help{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

/* Import note */
.import-note{
    background:rgba(109,76,255,.12) !important;
    border:1px solid rgba(139,92,246,.32) !important;
    color:#e5e7eb !important;
    border-radius:12px !important;
}

.import-note .material-icons-round{
    color:#c4b5fd !important;
}

/* View modal */
.view-img{
    border:1px solid rgba(148,163,184,.16) !important;
    background:rgba(15,23,42,.62) !important;
}

.view-grid{
    color:#ffffff !important;
}

.view-item{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

.view-item label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

.view-item span{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.view-desc{
    border-top:1px solid rgba(148,163,184,.16) !important;
}

.view-desc label{
    color:#94a3b8 !important;
    font-weight:900 !important;
}

.view-desc p{
    color:#e5e7eb !important;
    font-weight:600 !important;
}

/* Modal buttons */
.modal-footer .btn-outline,
.modal-footer .btn-secondary{
    min-height:38px !important;
    height:38px !important;
    min-width:105px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
}

.modal-footer .btn-primary,
.modal-footer .btn-success{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

#confirmModal .btn-danger{
    min-height:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    padding:7px 16px !important;
    box-shadow:0 14px 28px rgba(239,68,68,.24) !important;
}

.modal-footer .btn:hover{
    transform:translateY(-1px);
}

/* Confirm modal */
#confirmModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
}

#confirmModal h3{
    color:#f8fafc !important;
    font-weight:900 !important;
}

#confirmModal p{
    color:#94a3b8 !important;
}

@media(max-width:1280px){
    .stats-grid{
        grid-template-columns:repeat(3,1fr) !important;
    }
}

@media(max-width:991px){
    .container-scroll{
        flex-direction:column !important;
    }

    .sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        position:relative !important;
        height:auto !important;
        display:flex !important;
    }

    #dashboardSidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    #dashboardSidebar .sidebar-inner{
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

    .form-grid,
    .view-grid{
        grid-template-columns:1fr !important;
    }

    .form-group.full{
        grid-column:span 1 !important;
    }
}

@media(max-width:640px){
    .stats-grid{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .page-actions,
    .page-actions .btn{
        width:100% !important;
    }

    .page-actions .btn{
        justify-content:center !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .filter-row{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .filter-group,
    .filter-actions,
    .filter-actions .btn{
        width:100% !important;
    }

    .filter-actions{
        flex-direction:column !important;
    }

    #addModal .modal-box,
    #projectModal .modal-box,
    #importModal .modal-box,
    #viewModal .modal-box,
    #confirmModal .modal-box{
        width:96vw !important;
        max-width:96vw !important;
    }

    .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .modal-footer .btn,
    #deleteForm,
    #deleteForm .btn{
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
      <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= e($msgType) ?> alert-dismissible fade show" role="alert">
          <?= e($msg) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <div class="page-header">
        <div>
          <div class="page-title">Properties</div>
          <div class="breadcrumb-custom">
            <a href="dashboard.php">Home</a><span>›</span>
            <a href="agency-lead.php">Real Estate</a><span>›</span>
            <span class="active">Properties</span>
          </div>
        </div>

        <div class="page-actions">
          <button class="btn btn-outline" type="button" onclick="openProjectModal()">
            <span class="material-icons-round">apartment</span> Add Project
          </button>
          <button class="btn btn-outline" type="button" onclick="openImportModal()">
            <span class="material-icons-round">upload_file</span> Import Properties
          </button>
          <a class="btn btn-outline" href="properties.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
            <span class="material-icons-round">download</span> Export
          </a>
          <button class="btn btn-primary" type="button" onclick="openAddModal()">
            <span class="material-icons-round">add</span> Add New Property
          </button>
        </div>
      </div>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-top">
            <div>
              <div class="stat-label">Total Properties</div>
              <div class="stat-value"><?= (int)$totalProperties ?></div>
            </div>
            <div class="stat-icon"><span class="material-icons-round">apartment</span></div>
          </div>
          <div class="stat-helper">All property inventory</div>
        </div>

        <div class="stat-card">
          <div class="stat-top">
            <div>
              <div class="stat-label">Available</div>
              <div class="stat-value"><?= (int)$availableCount ?></div>
            </div>
            <div class="stat-icon"><span class="material-icons-round">check_circle</span></div>
          </div>
          <div class="stat-helper">Ready for deal</div>
        </div>

        <div class="stat-card">
          <div class="stat-top">
            <div>
              <div class="stat-label">Sold</div>
              <div class="stat-value"><?= (int)$soldCount ?></div>
            </div>
            <div class="stat-icon"><span class="material-icons-round">sell</span></div>
          </div>
          <div class="stat-helper">Closed properties</div>
        </div>

        <div class="stat-card">
          <div class="stat-top">
            <div>
              <div class="stat-label">Rented</div>
              <div class="stat-value"><?= (int)$rentedCount ?></div>
            </div>
            <div class="stat-icon"><span class="material-icons-round">key</span></div>
          </div>
          <div class="stat-helper">Rental inventory</div>
        </div>

        <div class="stat-card">
          <div class="stat-top">
            <div>
              <div class="stat-label">Plots</div>
              <div class="stat-value"><?= (int)$plotCount ?></div>
            </div>
            <div class="stat-icon"><span class="material-icons-round">landscape</span></div>
          </div>
          <div class="stat-helper">Plot listings</div>
        </div>

        <div class="stat-card">
          <div class="stat-top">
            <div>
              <div class="stat-label">Commercial</div>
              <div class="stat-value"><?= (int)$commercialCount ?></div>
            </div>
            <div class="stat-icon"><span class="material-icons-round">store</span></div>
          </div>
          <div class="stat-helper">Shop / office / commercial</div>
        </div>
      </div>

      <form class="filter-card" method="GET" action="properties.php">
        <div class="filter-row">
          <div class="filter-group search-wide">
            <label>Search</label>
            <input type="text" name="search" placeholder="Title, location, project, owner, phone..." value="<?= e($search) ?>">
          </div>

          <div class="filter-group">
            <label>Type</label>
            <select name="property_type">
              <option value="">All Types</option>
              <?php foreach ($propertyTypes as $type): ?>
                <option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e($type) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-group">
            <label>Status</label>
            <select name="status">
              <option value="">All Status</option>
              <?php foreach ($statuses as $st): ?>
                <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e($st) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-group">
            <label>City</label>
            <select name="city">
              <option value="">All Cities</option>
              <?php foreach ($cities as $city): ?>
                <option value="<?= e($city) ?>" <?= $cityFilter === $city ? 'selected' : '' ?>><?= e($city) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-group">
            <label>Project</label>
            <select name="project_id">
              <option value="">All Projects</option>
              <?php foreach ($projects as $project): ?>
                <option value="<?= (int)$project['id'] ?>" <?= $projectFilter === (int)$project['id'] ? 'selected' : '' ?>>
                  <?= e($project['project_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-group">
            <label>Agent</label>
            <select name="agent_id">
              <option value="">All Agents</option>
              <?php foreach ($agents as $agent): ?>
                <option value="<?= (int)$agent['id'] ?>" <?= $agentFilter === (int)$agent['id'] ? 'selected' : '' ?>>
                  <?= e($agent['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-group">
            <label>From Date</label>
            <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
          </div>

          <div class="filter-group">
            <label>To Date</label>
            <input type="date" name="date_to" value="<?= e($dateTo) ?>">
          </div>

          <div class="filter-actions">
            <button class="btn btn-primary" type="submit">
              <span class="material-icons-round">filter_list</span> Filter
            </button>
            <a class="btn btn-outline" href="properties.php">
              <span class="material-icons-round">refresh</span> Reset
            </a>
          </div>
        </div>
      </form>

      <div class="table-card">
        <div class="table-header">
          <div class="table-title">Property Listings</div>
          <div style="font-size:12px;color:#9ca3af;font-weight:800;"><?= (int)$totalFiltered ?> records</div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Property Title</th>
                <th>Type</th>
                <th>Project / Location</th>
                <th>Purpose</th>
                <th>Price</th>
                <th>Size</th>
                <th>Status</th>
                <th>Owner</th>
                <th>Assigned Agent</th>
                <th>Added Date</th>
                <th>Actions</th>
              </tr>
            </thead>

            <tbody>
              <?php if (empty($propertiesData)): ?>
                <tr>
                  <td colspan="12">
                    <div class="empty-state">
                      <span class="material-icons-round">search_off</span>
                      <p>No properties found matching your filters.</p>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($propertiesData as $idx => $p): ?>
                  <tr>
                    <td style="color:#9ca3af;font-size:12px;font-weight:800;"><?= (int)($offset + $idx + 1) ?></td>

                    <td>
                      <div class="prop-title-cell">
                        <?php if (!empty($p['main_image'])): ?>
                          <img class="prop-thumb" src="<?= e($p['main_image']) ?>" alt="">
                        <?php else: ?>
                          <div class="prop-thumb" style="display:grid;place-items:center;"><span class="material-icons-round" style="font-size:18px;color:#94a3b8;">home_work</span></div>
                        <?php endif; ?>
                        <div>
                          <div class="prop-title-text"><?= e($p['title']) ?></div>
                          <div class="prop-sub"><?= e($p['property_code']) ?></div>
                        </div>
                      </div>
                    </td>

                    <td><span class="badge <?= e(typeBadgeClass($p['property_type'])) ?>"><?= e($p['property_type']) ?></span></td>

                    <td>
                      <div style="font-size:12.5px;font-weight:800;"><?= e($p['project_name'] ?: 'No Project') ?></div>
                      <div style="font-size:11px;color:#94a3b8;">
                        <?= e($p['city']) ?><?= !empty($p['area_location']) ? ' • ' . e($p['area_location']) : '' ?>
                      </div>
                    </td>

                    <td><span class="badge <?= e(purposeBadgeClass($p['purpose'])) ?>"><?= e($p['purpose']) ?></span></td>

                    <td style="font-size:12.5px;font-weight:900;color:#1f2736;">
                      <?php if ($p['purpose'] === 'Rent'): ?>
                        PKR <?= number_format((float)$p['rent_price'], 0) ?>
                      <?php elseif ($p['purpose'] === 'Both'): ?>
                        PKR <?= number_format((float)$p['demand_price'], 0) ?> / <?= number_format((float)$p['rent_price'], 0) ?>
                      <?php else: ?>
                        PKR <?= number_format((float)$p['demand_price'], 0) ?>
                      <?php endif; ?>
                    </td>

                    <td style="font-size:12.5px;color:#64748b;font-weight:700;">
                      <?= e(rtrim(rtrim((string)$p['size_value'], '0'), '.')) ?> <?= e($p['size_unit']) ?>
                    </td>

                    <td><span class="badge <?= e(statusBadgeClass($p['status'])) ?>"><?= e($p['status']) ?></span></td>

                    <td>
                      <div style="font-size:12.5px;font-weight:800;"><?= e($p['owner_name'] ?: '-') ?></div>
                      <div style="font-size:11px;color:#94a3b8;"><?= e($p['owner_phone'] ?: '') ?></div>
                    </td>

                    <td style="font-size:12.5px;font-weight:700;"><?= e($p['agent_name'] ?: 'Unassigned') ?></td>

                    <td style="font-size:12px;color:#9ca3af;"><?= e(date('Y-m-d', strtotime($p['created_at']))) ?></td>

                    <td>
                      <div class="action-btns">
                        <button class="action-btn view" type="button" onclick="viewProperty(<?= (int)$p['id'] ?>)">
                          <span class="material-icons-round">visibility</span>
                        </button>
                        <button class="action-btn edit" type="button" onclick="editProperty(<?= (int)$p['id'] ?>)">
                          <span class="material-icons-round">edit</span>
                        </button>
                        <button class="action-btn del" type="button" onclick="askDelete(<?= (int)$p['id'] ?>)">
                          <span class="material-icons-round">delete</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="pagination-bar">
          <div class="pagination-info">
            Showing <?= $totalFiltered > 0 ? (int)$offset + 1 : 0 ?> to <?= min((int)$offset + $perPage, (int)$totalFiltered) ?> of <?= (int)$totalFiltered ?> entries
          </div>

          <div class="pagination-controls">
            <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(pageUrl($page - 1, $queryBase)) ?>">
              <span class="material-icons-round">chevron_left</span>
            </a>

            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <?php if ($p === 1 || $p === $totalPages || abs($p - $page) <= 1): ?>
                <a class="page-btn <?= $p === $page ? 'active' : '' ?>" href="<?= e(pageUrl($p, $queryBase)) ?>"><?= (int)$p ?></a>
              <?php elseif (abs($p - $page) === 2): ?>
                <span style="padding:0 4px;color:#9ca3af;">...</span>
              <?php endif; ?>
            <?php endfor; ?>

            <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(pageUrl($page + 1, $queryBase)) ?>">
              <span class="material-icons-round">chevron_right</span>
            </a>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>


<!-- ADD / EDIT PROPERTY MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title" id="addModalTitle">Add New Property</div>
      <button class="modal-close" type="button" onclick="closeAddModal()">
        <span class="material-icons-round">close</span>
      </button>
    </div>

    <form method="POST" id="propertyForm" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_property">
      <input type="hidden" name="id" id="fId">
      <input type="hidden" name="property_code" id="fCode">
      <input type="hidden" name="block_name" id="fBlock">
      <input type="hidden" name="street_no" id="fStreet">
      <input type="hidden" name="plot_no" id="fPlot">
      <input type="hidden" name="min_price" id="fMinPrice">
      <input type="hidden" name="rent_price" id="fRentPrice">
      <input type="hidden" name="owner_name" id="fOwnerName">
      <input type="hidden" name="owner_phone" id="fOwnerPhone">
      <input type="hidden" name="owner_email" id="fOwnerEmail">
      <input type="hidden" name="owner_address" id="fOwnerAddress">
      <input type="hidden" name="size_unit" id="fSizeUnit" value="Marla">

      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label>Property Title *</label>
            <input type="text" name="title" id="fTitle" placeholder="e.g. DHA Phase 5 – Plot 152 M" required>
            <span class="form-err-msg" id="errTitle"></span>
          </div>

          <div class="form-group">
            <label>Type *</label>
            <select name="property_type" id="fType" required>
              <option value="">Select Type</option>
              <?php foreach ($propertyTypes as $type): ?>
                <option value="<?= e($type) ?>"><?= e($type) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="form-err-msg" id="errType"></span>
          </div>

          <div class="form-group">
            <label>Project *</label>
            <select name="project_id" id="fProject">
              <option value="">Select Project</option>
              <?php foreach ($projects as $project): ?>
                <option value="<?= (int)$project['id'] ?>"><?= e($project['project_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="form-err-msg" id="errProject"></span>
          </div>

          <div class="form-group">
            <label>City *</label>
            <select name="city" id="fCity" required>
              <option value="">Select City</option>
              <?php
                $defaultCities = ['Lahore','Kharian','Islamabad','Karachi'];
                $cityOptions = array_values(array_unique(array_merge($defaultCities, $cities)));
                foreach ($cityOptions as $cityOpt):
              ?>
                <option value="<?= e($cityOpt) ?>"><?= e($cityOpt) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="form-err-msg" id="errCity"></span>
          </div>

          <div class="form-group">
            <label>Location / Area *</label>
            <input type="text" name="area_location" id="fLocation" placeholder="e.g. Block A, Main Boulevard" required>
            <span class="form-err-msg" id="errLocation"></span>
          </div>

          <div class="form-group">
            <label>Purpose *</label>
            <select name="purpose" id="fPurpose" required>
              <option value="">Select Purpose</option>
              <option value="Sale">Sale</option>
              <option value="Rent">Rent</option>
              <option value="Both">Both</option>
            </select>
            <span class="form-err-msg" id="errPurpose"></span>
          </div>

          <div class="form-group">
            <label>Price (PKR) *</label>
            <input type="text" name="demand_price" id="fDemandPrice" placeholder="e.g. 1,20,00,000" required>
            <span class="form-err-msg" id="errPrice"></span>
          </div>

          <div class="form-group">
            <label>Size *</label>
            <input type="text" name="size_value" id="fSizeValue" placeholder="e.g. 10 Marla, 5 Kanal" required>
            <span class="form-err-msg" id="errSize"></span>
          </div>

          <div class="form-group">
            <label>Status *</label>
            <select name="status" id="fStatus" required>
              <option value="">Select Status</option>
              <?php foreach ($statuses as $st): ?>
                <option value="<?= e($st) ?>"><?= e($st) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="form-err-msg" id="errStatus"></span>
          </div>

          <div class="form-group">
            <label>Assigned Agent *</label>
            <select name="assigned_agent_id" id="fAgent" required>
              <option value="">Select Agent</option>
              <?php foreach ($agents as $agent): ?>
                <option value="<?= (int)$agent['id'] ?>"><?= e($agent['name']) ?><?= !empty($agent['role_name']) ? ' - ' . e($agent['role_name']) : '' ?></option>
              <?php endforeach; ?>
            </select>
            <span class="form-err-msg" id="errAgent"></span>
          </div>

          <div class="form-group">
            <label>Added Date *</label>
            <input type="date" id="fDate" value="<?= e(date('Y-m-d')) ?>" readonly>
            <span class="form-err-msg" id="errDate"></span>
          </div>

          <div class="form-group full">
            <label>Image URL</label>
            <input type="url" name="main_image" id="fImage" placeholder="https://images.unsplash.com/...">
          </div>

          <div class="form-group full">
            <label>Upload Image</label>
            <div class="image-upload-box">
              <div class="image-upload-preview" id="imageUploadPreview">
                <span class="material-icons-round">image</span>
                <img id="fImagePreview" alt="Property image preview">
              </div>
              <div class="image-upload-content">
                <input type="file" name="property_image" id="fImageFile" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewPropertyImage(this)">
                <div class="image-upload-help">Upload JPG, PNG, WEBP or GIF. Max 5 MB. Uploaded image will be saved and used instead of URL.</div>
              </div>
            </div>
          </div>

          <div class="form-group full">
            <label>Description</label>
            <textarea name="description" id="fDesc" placeholder="Property details, features, nearby landmarks..."></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-outline" type="button" onclick="closeAddModal()">Cancel</button>
        <button class="btn btn-primary" type="submit">
          <span class="material-icons-round">save</span> Save Property
        </button>
      </div>
    </form>
  </div>
</div>


<!-- PROJECT MODAL -->
<div class="modal-overlay" id="projectModal">
  <div class="modal-box" style="max-width:620px;">
    <div class="modal-header">
      <div class="modal-title">Add Project / Society</div>
      <button class="modal-close" type="button" onclick="closeProjectModal()">
        <span class="material-icons-round">close</span>
      </button>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="save_project">

      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label>Project Name *</label>
            <input type="text" name="project_name" placeholder="Bahria Town / DHA / New Metro City" required>
          </div>

          <div class="form-group">
            <label>Developer Name</label>
            <input type="text" name="developer_name" placeholder="Developer">
          </div>

          <div class="form-group">
            <label>Project Type</label>
            <select name="project_type">
              <?php foreach ($projectTypes as $type): ?>
                <option value="<?= e($type) ?>"><?= e($type) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>City</label>
            <input type="text" name="project_city" placeholder="Karachi">
          </div>

          <div class="form-group">
            <label>Location</label>
            <input type="text" name="project_location" placeholder="Main location">
          </div>

          <div class="form-group full">
            <label>Description</label>
            <textarea name="project_description" placeholder="Project description"></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-outline" type="button" onclick="closeProjectModal()">Cancel</button>
        <button class="btn btn-primary" type="submit">
          <span class="material-icons-round">save</span> Save Project
        </button>
      </div>
    </form>
  </div>
</div>


<!-- VIEW MODAL -->
<div class="modal-overlay" id="viewModal">
  <div class="modal-box" style="max-width:620px;">
    <div class="modal-header">
      <div class="modal-title" id="viewTitle">Property Details</div>
      <button class="modal-close" type="button" onclick="closeViewModal()">
        <span class="material-icons-round">close</span>
      </button>
    </div>

    <div class="modal-body" id="viewBody"></div>

    <div class="modal-footer">
      <button class="btn btn-outline" type="button" onclick="closeViewModal()">Close</button>
    </div>
  </div>
</div>


<!-- IMPORT MODAL -->
<div class="modal-overlay" id="importModal">
  <div class="modal-box" style="max-width:480px;">
    <div class="modal-header">
      <div class="modal-title">Import Properties</div>
      <button class="modal-close" type="button" onclick="closeImportModal()">
        <span class="material-icons-round">close</span>
      </button>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="import_properties">

      <div class="modal-body">
        <div class="import-note">
          <span class="material-icons-round">info</span>
          CSV columns: title, property_type, project_name, city, location, purpose, demand_price, size_value, size_unit, status, agent_id, owner_name, owner_phone, description
        </div>

        <div class="form-group">
          <label>Select CSV File</label>
          <input type="file" name="import_file" accept=".csv" required>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-outline" type="button" onclick="closeImportModal()">Cancel</button>
        <button class="btn btn-primary" type="submit">
          <span class="material-icons-round">upload</span> Import
        </button>
      </div>
    </form>
  </div>
</div>


<!-- DELETE CONFIRM -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal-box" style="max-width:420px;">
    <div class="modal-body" style="text-align:center;">
      <span class="material-icons-round" style="font-size:52px;color:#c94b57;">delete_forever</span>
      <h3 style="font-size:18px;font-weight:900;margin:10px 0 6px;">Delete Property?</h3>
      <p style="font-size:13px;color:#64748b;line-height:1.5;">This action cannot be undone. The property record will be permanently removed.</p>
    </div>

    <div class="modal-footer" style="justify-content:center;">
      <button class="btn btn-outline" type="button" onclick="closeConfirm()">Cancel</button>

      <form method="POST" id="deleteForm">
        <input type="hidden" name="action" value="delete_property">
        <input type="hidden" name="id" id="deleteId">
        <button class="btn btn-danger" type="submit">
          <span class="material-icons-round">delete</span> Delete
        </button>
      </form>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const propertiesData = <?= $propertiesJson ?: '[]' ?>;

function byId(id){ return document.getElementById(id); }

function previewPropertyImage(input){
  const previewBox = byId('imageUploadPreview');
  const previewImg = byId('fImagePreview');
  if (!previewBox || !previewImg) return;

  const file = input.files && input.files[0] ? input.files[0] : null;
  if (!file) {
    previewImg.style.display = 'none';
    previewImg.src = '';
    return;
  }

  previewImg.src = URL.createObjectURL(file);
  previewImg.style.display = 'block';
}

function resetPropertyImagePreview(){
  const fileInput = byId('fImageFile');
  const previewImg = byId('fImagePreview');

  if (fileInput) fileInput.value = '';
  if (previewImg) {
    previewImg.style.display = 'none';
    previewImg.src = '';
  }
}

function showExistingImagePreview(src){
  const previewImg = byId('fImagePreview');
  if (!previewImg) return;

  if (src) {
    previewImg.src = src;
    previewImg.style.display = 'block';
  } else {
    previewImg.src = '';
    previewImg.style.display = 'none';
  }
}



function openAddModal(){
  byId('propertyForm').reset();
  byId('fId').value = '';
  byId('addModalTitle').textContent = 'Add New Property';
  byId('fType').value = '';
  byId('fPurpose').value = '';
  byId('fSizeUnit').value = 'Marla';
  byId('fStatus').value = '';
  document.body.classList.add('modal-open-custom');
  document.body.classList.add('modal-open-custom');
  byId('addModal').classList.add('open');
  setTimeout(() => {
    const body = byId('addModal')?.querySelector('.modal-body');
    if (body) body.scrollTop = 0;
  }, 0);
}

function closeAddModal(){
  byId('addModal').classList.remove('open');
  document.body.classList.remove('modal-open-custom');
}

function editProperty(id){
  const p = propertiesData.find(x => parseInt(x.id) === parseInt(id));
  if (!p) {
    alert('Property not found.');
    return;
  }

  byId('propertyForm').reset();
  byId('addModalTitle').textContent = 'Edit Property';
  byId('fId').value = p.id || '';
  byId('fCode').value = p.property_code || '';
  byId('fProject').value = p.project_id || '';
  byId('fTitle').value = p.title || '';
  byId('fType').value = p.property_type || 'Plot';
  byId('fPurpose').value = p.purpose || 'Sale';
  byId('fCity').value = p.city || '';
  byId('fLocation').value = p.area_location || '';
  byId('fBlock').value = p.block_name || '';
  byId('fStreet').value = p.street_no || '';
  byId('fPlot').value = p.plot_no || '';
  byId('fSizeValue').value = p.size_value || '';
  byId('fSizeUnit').value = p.size_unit || 'Sq Yd';
  byId('fDemandPrice').value = p.demand_price || '';
  byId('fMinPrice').value = p.min_price || '';
  byId('fRentPrice').value = p.rent_price || '';
  byId('fStatus').value = p.status || 'Available';
  byId('fAgent').value = p.assigned_agent_id || '';
  byId('fOwnerName').value = p.owner_name || '';
  byId('fOwnerPhone').value = p.owner_phone || '';
  byId('fOwnerEmail').value = p.owner_email || '';
  byId('fOwnerAddress').value = p.owner_address || '';
  byId('fImage').value = p.main_image || '';
  resetPropertyImagePreview();
  showExistingImagePreview(p.main_image || '');
  byId('fDesc').value = p.description || '';
  if (byId('fDate')) byId('fDate').value = (p.created_at || '').substring(0,10);
  byId('addModal').classList.add('open');
}

function viewProperty(id){
  const p = propertiesData.find(x => parseInt(x.id) === parseInt(id));
  if (!p) {
    alert('Property not found.');
    return;
  }

  byId('viewTitle').textContent = p.title || 'Property Details';

  const img = p.main_image
    ? `<img class="view-img" src="${escapeHtml(p.main_image)}" alt="">`
    : `<div class="view-img" style="display:grid;place-items:center;"><span class="material-icons-round" style="font-size:44px;color:#94a3b8;">home_work</span></div>`;

  const price = p.purpose === 'Rent'
    ? `PKR ${Number(p.rent_price || 0).toLocaleString()}`
    : (p.purpose === 'Both'
        ? `PKR ${Number(p.demand_price || 0).toLocaleString()} / ${Number(p.rent_price || 0).toLocaleString()}`
        : `PKR ${Number(p.demand_price || 0).toLocaleString()}`);

  byId('viewBody').innerHTML = `
    ${img}
    <div class="view-grid">
      <div class="view-item"><label>Code</label><span>${escapeHtml(p.property_code || '-')}</span></div>
      <div class="view-item"><label>Type</label><span>${escapeHtml(p.property_type || '-')}</span></div>
      <div class="view-item"><label>Purpose</label><span>${escapeHtml(p.purpose || '-')}</span></div>
      <div class="view-item"><label>Status</label><span>${escapeHtml(p.status || '-')}</span></div>
      <div class="view-item"><label>Project</label><span>${escapeHtml(p.project_name || 'No Project')}</span></div>
      <div class="view-item"><label>City</label><span>${escapeHtml(p.city || '-')}</span></div>
      <div class="view-item"><label>Location</label><span>${escapeHtml(p.area_location || '-')}</span></div>
      <div class="view-item"><label>Block / Plot</label><span>${escapeHtml((p.block_name || '-') + ' / ' + (p.plot_no || '-'))}</span></div>
      <div class="view-item"><label>Price</label><span style="color:#c9a84c;font-weight:900;">${price}</span></div>
      <div class="view-item"><label>Size</label><span>${escapeHtml((p.size_value || '') + ' ' + (p.size_unit || ''))}</span></div>
      <div class="view-item"><label>Owner</label><span>${escapeHtml(p.owner_name || '-')}</span></div>
      <div class="view-item"><label>Owner Phone</label><span>${escapeHtml(p.owner_phone || '-')}</span></div>
      <div class="view-item"><label>Agent</label><span>${escapeHtml(p.agent_name || 'Unassigned')}</span></div>
      <div class="view-item"><label>Created</label><span>${escapeHtml(p.created_at || '-')}</span></div>
    </div>
    <div class="view-desc"><label>Description</label><p>${escapeHtml(p.description || 'No description provided.')}</p></div>
  `;

  document.body.classList.add('modal-open-custom');
  byId('viewModal').classList.add('open');
}

function closeViewModal(){
  byId('viewModal').classList.remove('open');
  document.body.classList.remove('modal-open-custom');
}

function openImportModal(){
  document.body.classList.add('modal-open-custom');
  byId('importModal').classList.add('open');
}

function closeImportModal(){
  byId('importModal').classList.remove('open');
  document.body.classList.remove('modal-open-custom');
}

function openProjectModal(){
  document.body.classList.add('modal-open-custom');
  byId('projectModal').classList.add('open');
}

function closeProjectModal(){
  byId('projectModal').classList.remove('open');
  document.body.classList.remove('modal-open-custom');
}

function askDelete(id){
  byId('deleteId').value = id;
  document.body.classList.add('modal-open-custom');
  byId('confirmModal').classList.add('open');
}

function closeConfirm(){
  byId('confirmModal').classList.remove('open');
  document.body.classList.remove('modal-open-custom');
}

function escapeHtml(str){
  return String(str || '').replace(/[&<>'"]/g, c => ({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    "'":'&#039;',
    '"':'&quot;'
  }[c]));
}

['addModal','viewModal','importModal','confirmModal','projectModal'].forEach(id => {
  byId(id)?.addEventListener('click', e => {
    if (e.target === e.currentTarget) {
      e.currentTarget.classList.remove('open');
      document.body.classList.remove('modal-open-custom');
    }
  });
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    ['addModal','viewModal','importModal','confirmModal','projectModal'].forEach(id => byId(id)?.classList.remove('open'));
    document.body.classList.remove('modal-open-custom');
  }
});
</script>
</body>
</html>
