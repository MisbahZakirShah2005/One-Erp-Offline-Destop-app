<?php
/*
|--------------------------------------------------------------------------
| agency-lead.php
| State Agency / Real Estate Leads
|--------------------------------------------------------------------------
| Required tables:
| - re_leads
| - re_lead_notes (optional, for notes)
| - users
| - branches
|
| Partial structure same as customers.php:
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

// if (!isset($_SESSION['user_id'])) {
//     die("Login required");
// }

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
    /*
     * MariaDB/XAMPP me SHOW TABLES LIKE ? prepared statement support nahi karta,
     * isliye INFORMATION_SCHEMA use kar rahe hain.
     */
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

function leadInitials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'LD';

    $parts = preg_split('/\s+/', $name);
    $initials = '';

    foreach ($parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }

    return $initials ?: 'LD';
}

function redirect_with_message($type, $message) {
    header("Location: agency-lead.php?msg_type=" . urlencode($type) . "&msg=" . urlencode($message));
    exit;
}

function normalize_enum($value, array $allowed, string $default) {
    $value = trim((string)$value);
    return in_array($value, $allowed, true) ? $value : $default;
}

function makeLeadNo(mysqli $conn, int $client_id): string {
    $prefix = 'REL-' . date('Ymd') . '-';

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM re_leads
        WHERE client_id = ?
          AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = ((int)($row['total'] ?? 0)) + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function moneyVal($value): float {
    $value = str_replace([',', 'Rs.', 'PKR'], '', (string)$value);
    return (float)trim($value);
}

function safeDateTimeOrNull($date, $time = '') {
    $date = trim((string)$date);
    $time = trim((string)$time);

    if ($date === '') return null;
    if ($time === '') $time = '09:00';

    return $date . ' ' . $time . ':00';
}

/* ===================== REQUIRED TABLE CHECK ===================== */
if (!tableExists($conn, 're_leads')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>re_leads table missing</h3>
            <p>Pehle State Agency / Real Estate tables wali SQL run karo. Uske baad agency-lead.php open karo.</p>
        </div>
    ");
}

/* ===================== ENUM OPTIONS ===================== */
$leadSources = [
    'Walk-in',
    'Facebook Ads',
    'Instagram',
    'WhatsApp',
    'Website',
    'Referral',
    'Zameen',
    'Graana',
    'OLX',
    'Call',
    'Other'
];

$interestTypes = [
    'Buy',
    'Sell',
    'Rent',
    'Investment',
    'Other'
];

$propertyTypes = [
    'Plot',
    'House',
    'Apartment',
    'Flat',
    'Shop',
    'Office',
    'Commercial',
    'Other'
];

$leadStatuses = [
    'New',
    'Contacted',
    'Interested',
    'Follow Up',
    'Site Visit',
    'Token',
    'Converted',
    'Lost',
    'Cancelled'
];

$priorities = [
    'Low',
    'Medium',
    'High',
    'Hot'
];

/* ===================== POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_lead') {
        $lead_name          = trim($_POST['lead_name'] ?? '');
        $phone              = trim($_POST['phone'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $address            = trim($_POST['address'] ?? '');
        $source             = normalize_enum($_POST['source'] ?? 'Walk-in', $leadSources, 'Walk-in');
        $interest_type      = normalize_enum($_POST['interest_type'] ?? 'Buy', $interestTypes, 'Buy');
        $property_type      = normalize_enum($_POST['property_type'] ?? 'Plot', $propertyTypes, 'Plot');
        $preferred_city     = trim($_POST['preferred_city'] ?? '');
        $preferred_location = trim($_POST['preferred_location'] ?? '');
        $budget_min         = moneyVal($_POST['budget_min'] ?? 0);
        $budget_max         = moneyVal($_POST['budget_max'] ?? 0);
        $requirement        = trim($_POST['requirement'] ?? '');
        $assigned_agent_id  = (int)($_POST['assigned_agent_id'] ?? 0);
        $status             = normalize_enum($_POST['status'] ?? 'New', $leadStatuses, 'New');
        $priority           = normalize_enum($_POST['priority'] ?? 'Medium', $priorities, 'Medium');
        $next_followup_at   = safeDateTimeOrNull($_POST['next_followup_date'] ?? '', $_POST['next_followup_time'] ?? '');
        $lead_notes         = trim($_POST['lead_notes'] ?? '');

        if ($lead_name === '') {
            redirect_with_message('danger', 'Lead name is required.');
        }

        if ($phone === '') {
            redirect_with_message('danger', 'Phone number is required.');
        }

        $lead_no = makeLeadNo($conn, $client_id);
        $branch_id_insert = $branch_id > 0 ? $branch_id : null;
        $assigned_agent_id_insert = $assigned_agent_id > 0 ? $assigned_agent_id : null;

        $stmt = $conn->prepare("
            INSERT INTO re_leads
            (
                client_id, branch_id, lead_no, lead_name, phone, email, address,
                source, interest_type, property_type, preferred_city, preferred_location,
                budget_min, budget_max, requirement, assigned_agent_id, status, priority,
                next_followup_at, created_by
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "iissssssssssddsisssi",
            $client_id,
            $branch_id_insert,
            $lead_no,
            $lead_name,
            $phone,
            $email,
            $address,
            $source,
            $interest_type,
            $property_type,
            $preferred_city,
            $preferred_location,
            $budget_min,
            $budget_max,
            $requirement,
            $assigned_agent_id_insert,
            $status,
            $priority,
            $next_followup_at,
            $user_id
        );

        $stmt->execute();
        $lead_id = $stmt->insert_id;
        $stmt->close();

        if ($lead_notes !== '' && tableExists($conn, 're_lead_notes')) {
            $noteType = 'Note';
            $stmt = $conn->prepare("
                INSERT INTO re_lead_notes (client_id, lead_id, agent_id, note_type, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiissi", $client_id, $lead_id, $assigned_agent_id_insert, $noteType, $lead_notes, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        redirect_with_message('success', 'Lead added successfully.');
    }

    if ($action === 'update_lead') {
        $id                 = (int)($_POST['id'] ?? 0);
        $lead_name          = trim($_POST['lead_name'] ?? '');
        $phone              = trim($_POST['phone'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $address            = trim($_POST['address'] ?? '');
        $source             = normalize_enum($_POST['source'] ?? 'Walk-in', $leadSources, 'Walk-in');
        $interest_type      = normalize_enum($_POST['interest_type'] ?? 'Buy', $interestTypes, 'Buy');
        $property_type      = normalize_enum($_POST['property_type'] ?? 'Plot', $propertyTypes, 'Plot');
        $preferred_city     = trim($_POST['preferred_city'] ?? '');
        $preferred_location = trim($_POST['preferred_location'] ?? '');
        $budget_min         = moneyVal($_POST['budget_min'] ?? 0);
        $budget_max         = moneyVal($_POST['budget_max'] ?? 0);
        $requirement        = trim($_POST['requirement'] ?? '');
        $assigned_agent_id  = (int)($_POST['assigned_agent_id'] ?? 0);
        $status             = normalize_enum($_POST['status'] ?? 'New', $leadStatuses, 'New');
        $priority           = normalize_enum($_POST['priority'] ?? 'Medium', $priorities, 'Medium');
        $next_followup_at   = safeDateTimeOrNull($_POST['next_followup_date'] ?? '', $_POST['next_followup_time'] ?? '');
        $lead_notes         = trim($_POST['lead_notes'] ?? '');

        if ($id <= 0 || $lead_name === '' || $phone === '') {
            redirect_with_message('danger', 'Invalid lead data.');
        }

        $assigned_agent_id_update = $assigned_agent_id > 0 ? $assigned_agent_id : null;

        $stmt = $conn->prepare("
            UPDATE re_leads
            SET
                lead_name = ?,
                phone = ?,
                email = ?,
                address = ?,
                source = ?,
                interest_type = ?,
                property_type = ?,
                preferred_city = ?,
                preferred_location = ?,
                budget_min = ?,
                budget_max = ?,
                requirement = ?,
                assigned_agent_id = ?,
                status = ?,
                priority = ?,
                next_followup_at = ?
            WHERE id = ? AND client_id = ?
        ");

        $stmt->bind_param(
            "sssssssssddsisssii",
            $lead_name,
            $phone,
            $email,
            $address,
            $source,
            $interest_type,
            $property_type,
            $preferred_city,
            $preferred_location,
            $budget_min,
            $budget_max,
            $requirement,
            $assigned_agent_id_update,
            $status,
            $priority,
            $next_followup_at,
            $id,
            $client_id
        );

        $stmt->execute();
        $stmt->close();

        if ($lead_notes !== '' && tableExists($conn, 're_lead_notes')) {
            $noteType = 'Note';
            $stmt = $conn->prepare("
                INSERT INTO re_lead_notes (client_id, lead_id, agent_id, note_type, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiissi", $client_id, $id, $assigned_agent_id_update, $noteType, $lead_notes, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        redirect_with_message('success', 'Lead updated successfully.');
    }

    if ($action === 'delete_lead') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            redirect_with_message('danger', 'Invalid lead id.');
        }

        if (tableExists($conn, 're_lead_notes')) {
            $stmt = $conn->prepare("DELETE FROM re_lead_notes WHERE lead_id = ? AND client_id = ?");
            $stmt->bind_param("ii", $id, $client_id);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM re_leads WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Lead deleted successfully.');
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['selected_ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            redirect_with_message('danger', 'Please select at least one lead.');
        }

        $cleanIds = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) $cleanIds[] = $id;
        }

        if (empty($cleanIds)) {
            redirect_with_message('danger', 'No valid leads selected.');
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $types = str_repeat('i', count($cleanIds)) . 'i';
        $params = array_merge($cleanIds, [$client_id]);

        if (tableExists($conn, 're_lead_notes')) {
            $sql = "DELETE FROM re_lead_notes WHERE lead_id IN ($placeholders) AND client_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }

        $sql = "DELETE FROM re_leads WHERE id IN ($placeholders) AND client_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $deletedRows = $stmt->affected_rows;
        $stmt->close();

        redirect_with_message('success', $deletedRows . ' lead(s) deleted successfully.');
    }

    if ($action === 'import_leads') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            redirect_with_message('danger', 'Please choose a valid CSV file.');
        }

        $handle = fopen($_FILES['import_file']['tmp_name'], 'r');

        if (!$handle) {
            redirect_with_message('danger', 'Unable to read uploaded CSV file.');
        }

        // Expected CSV columns:
        // lead_name, phone, email, source, status, priority, city, location, budget_min, budget_max, requirement
        fgetcsv($handle);
        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lead_name = trim($row[0] ?? '');
            $phone     = trim($row[1] ?? '');

            if ($lead_name === '' || $phone === '') {
                $skipped++;
                continue;
            }

            $email              = trim($row[2] ?? '');
            $source             = normalize_enum($row[3] ?? 'Walk-in', $leadSources, 'Walk-in');
            $status             = normalize_enum($row[4] ?? 'New', $leadStatuses, 'New');
            $priority           = normalize_enum($row[5] ?? 'Medium', $priorities, 'Medium');
            $preferred_city     = trim($row[6] ?? '');
            $preferred_location = trim($row[7] ?? '');
            $budget_min         = moneyVal($row[8] ?? 0);
            $budget_max         = moneyVal($row[9] ?? 0);
            $requirement        = trim($row[10] ?? '');
            $lead_no            = makeLeadNo($conn, $client_id);
            $interest_type      = 'Buy';
            $property_type      = 'Plot';
            $created_by         = $user_id;
            $branch_id_insert   = $branch_id > 0 ? $branch_id : null;

            $stmt = $conn->prepare("
                INSERT INTO re_leads
                (
                    client_id, branch_id, lead_no, lead_name, phone, email,
                    source, interest_type, property_type, preferred_city, preferred_location,
                    budget_min, budget_max, requirement, status, priority, created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "iisssssssssddsssi",
                $client_id,
                $branch_id_insert,
                $lead_no,
                $lead_name,
                $phone,
                $email,
                $source,
                $interest_type,
                $property_type,
                $preferred_city,
                $preferred_location,
                $budget_min,
                $budget_max,
                $requirement,
                $status,
                $priority,
                $created_by
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
$statusFilter = trim($_GET['status'] ?? '');
$sourceFilter = trim($_GET['source'] ?? '');
$agentFilter  = (int)($_GET['agent_id'] ?? 0);
$priorityFilter = trim($_GET['priority'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');

/* ===================== EXPORT CSV ===================== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "
        SELECT
            l.id, l.lead_no, l.lead_name, l.phone, l.email, l.source,
            l.status, l.priority, l.interest_type, l.property_type,
            l.preferred_city, l.preferred_location, l.budget_min, l.budget_max,
            l.next_followup_at, u.name AS agent_name, l.created_at
        FROM re_leads l
        LEFT JOIN users u ON u.id = l.assigned_agent_id
        WHERE l.client_id = ?
    ";

    $params = [$client_id];
    $types = "i";

    if ($search !== '') {
        $sql .= " AND (l.lead_name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.preferred_location LIKE ? OR l.lead_no LIKE ?)";
        $like = "%{$search}%";
        array_push($params, $like, $like, $like, $like, $like);
        $types .= "sssss";
    }

    if ($statusFilter !== '') {
        $sql .= " AND l.status = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }

    if ($sourceFilter !== '') {
        $sql .= " AND l.source = ?";
        $params[] = $sourceFilter;
        $types .= "s";
    }

    if ($priorityFilter !== '') {
        $sql .= " AND l.priority = ?";
        $params[] = $priorityFilter;
        $types .= "s";
    }

    if ($agentFilter > 0) {
        $sql .= " AND l.assigned_agent_id = ?";
        $params[] = $agentFilter;
        $types .= "i";
    }

    if ($dateFrom !== '') {
        $sql .= " AND DATE(l.created_at) >= ?";
        $params[] = $dateFrom;
        $types .= "s";
    }

    if ($dateTo !== '') {
        $sql .= " AND DATE(l.created_at) <= ?";
        $params[] = $dateTo;
        $types .= "s";
    }

    $sql .= " ORDER BY l.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=agency_leads_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID', 'Lead No', 'Name', 'Phone', 'Email', 'Source', 'Status', 'Priority',
        'Interest', 'Property Type', 'City', 'Location', 'Budget Min', 'Budget Max',
        'Next Followup', 'Agent', 'Created At'
    ]);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['lead_no'],
            $row['lead_name'],
            $row['phone'],
            $row['email'],
            $row['source'],
            $row['status'],
            $row['priority'],
            $row['interest_type'],
            $row['property_type'],
            $row['preferred_city'],
            $row['preferred_location'],
            $row['budget_min'],
            $row['budget_max'],
            $row['next_followup_at'],
            $row['agent_name'],
            $row['created_at']
        ]);
    }

    fclose($output);
    $stmt->close();
    exit;
}

/* ===================== FLASH MESSAGE ===================== */
$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ===================== STATS ===================== */
function countLeads(mysqli $conn, int $client_id, ?string $status = null): int {
    if ($status === null) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM re_leads WHERE client_id = ?");
        $stmt->bind_param("i", $client_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM re_leads WHERE client_id = ? AND status = ?");
        $stmt->bind_param("is", $client_id, $status);
    }

    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int)$count;
}

$totalLeads      = countLeads($conn, $client_id);
$newLeads        = countLeads($conn, $client_id, 'New');
$interestedLeads = countLeads($conn, $client_id, 'Interested');
$followupLeads   = countLeads($conn, $client_id, 'Follow Up');
$siteVisitLeads  = countLeads($conn, $client_id, 'Site Visit');
$convertedLeads  = countLeads($conn, $client_id, 'Converted');

/* ===================== AGENTS ===================== */
$agents = [];
$stmt = $conn->prepare("
    SELECT id, name, email, phone
    FROM users
    WHERE client_id = ?
      AND status = 'active'
    ORDER BY name ASC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $agents[] = $row;
}
$stmt->close();

/* ===================== FETCH LEADS ===================== */
$sql = "
    SELECT
        l.*,
        u.name AS agent_name,
        b.name AS branch_name
    FROM re_leads l
    LEFT JOIN users u ON u.id = l.assigned_agent_id
    LEFT JOIN branches b ON b.id = l.branch_id
    WHERE l.client_id = ?
";

$params = [$client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (l.lead_name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.preferred_location LIKE ? OR l.lead_no LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like);
    $types .= "sssss";
}

if ($statusFilter !== '') {
    $sql .= " AND l.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($sourceFilter !== '') {
    $sql .= " AND l.source = ?";
    $params[] = $sourceFilter;
    $types .= "s";
}

if ($priorityFilter !== '') {
    $sql .= " AND l.priority = ?";
    $params[] = $priorityFilter;
    $types .= "s";
}

if ($agentFilter > 0) {
    $sql .= " AND l.assigned_agent_id = ?";
    $params[] = $agentFilter;
    $types .= "i";
}

if ($dateFrom !== '') {
    $sql .= " AND DATE(l.created_at) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if ($dateTo !== '') {
    $sql .= " AND DATE(l.created_at) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$sql .= " ORDER BY l.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$leads = [];
while ($row = $result->fetch_assoc()) {
    $leads[] = $row;
}
$stmt->close();

$leadsJson = json_encode($leads, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

/* ===================== BADGE HELPERS ===================== */
function statusBadgeClass($status) {
    $status = trim((string)$status);

    $map = [
        'New'        => 'badge-new',
        'Contacted'  => 'badge-contacted',
        'Interested' => 'badge-interested',
        'Follow Up'  => 'badge-followup',
        'Site Visit' => 'badge-sitevisit',
        'Token'      => 'badge-token',
        'Converted'  => 'badge-converted',
        'Lost'       => 'badge-lost',
        'Cancelled'  => 'badge-lost',
    ];

    return $map[$status] ?? 'badge-new';
}

function priorityBadgeClass($priority) {
    $priority = trim((string)$priority);

    $map = [
        'Low'    => 'priority-low',
        'Medium' => 'priority-medium',
        'High'   => 'priority-high',
        'Hot'    => 'priority-hot',
    ];

    return $map[$priority] ?? 'priority-medium';
}

function dateOnly($dt) {
    if (!$dt) return '';
    return substr((string)$dt, 0, 10);
}

function timeOnly($dt) {
    if (!$dt) return '';
    return substr((string)$dt, 11, 5);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agency Leads</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php include('partials/head.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            margin:0;
            background:#eef2f7;
            font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color:#1f2937;
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
            background:#eef2f7;
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
            font-weight:800;
            color:#1f2937;
            margin:0;
        }

        .page-subtitle{
            color:#6b7280;
            margin-top:4px;
            font-size:13px;
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
            font-weight:700;
            font-size:14px;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
            display:inline-flex;
            align-items:center;
            gap:8px;
            text-decoration:none;
        }

        .btn-add{ background:linear-gradient(135deg,#d8a500,#b8860b); }
        .btn-import{ background:#111827; }
        .btn-export{ background:#5c4fd6; }
        .btn-bulk{ background:#3f8efc; }

        .stats-row{
            display:grid;
            grid-template-columns:repeat(6, 1fr);
            gap:14px;
            margin-bottom:16px;
        }

        .stat-card{
            color:#fff;
            border-radius:14px;
            padding:16px 18px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            box-shadow:0 8px 18px rgba(0,0,0,0.10);
            overflow:hidden;
            position:relative;
        }

        .stat-card::after{
            content:"";
            position:absolute;
            width:80px;
            height:80px;
            border-radius:50%;
            right:-24px;
            bottom:-24px;
            background:rgba(255,255,255,.14);
        }

        .stat-card .title{
            font-size:13px;
            font-weight:700;
            opacity:.94;
        }

        .stat-card .count{
            font-size:30px;
            font-weight:900;
            line-height:1;
        }

        .stat-card i{
            font-size:28px;
            opacity:.8;
            z-index:2;
        }

        .card-blue{ background:linear-gradient(135deg,#9f2430,#c94b57); }
        .card-green{ background:linear-gradient(135deg,#386c58,#4c9075); }
        .card-cyan{ background:linear-gradient(135deg,#14b8a6,#0d9488); }
        .card-yellow{ background:linear-gradient(135deg,#b27b16,#d8a33a); }
        .card-orange{ background:linear-gradient(135deg,#f97316,#c2410c); }
        .card-dark{ background:linear-gradient(135deg,#111c44,#263875); }

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
            box-shadow:0 4px 12px rgba(15,23,42,.04);
        }

        .quick-btn{
            height:44px;
            border:none;
            border-radius:10px;
            padding:0 14px;
            background:#111827;
            color:#fff;
            font-weight:700;
        }

        .search-wrap{
            flex:1.3;
            min-width:240px;
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
            min-width:150px;
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
            font-weight:700;
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
            box-shadow:0 4px 12px rgba(15,23,42,.04);
        }

        .table-responsive{
            overflow:auto;
        }

        .leads-table{
            width:100%;
            min-width:1250px;
            border-collapse:collapse;
        }

        .leads-table thead th{
            background:#111827;
            color:#fff;
            font-weight:700;
            font-size:13px;
            padding:14px 12px;
            border-right:1px solid rgba(255,255,255,0.08);
            white-space:nowrap;
        }

        .leads-table tbody td{
            padding:14px 12px;
            border-top:1px solid #edf0f5;
            vertical-align:middle;
            font-size:14px;
            color:#374151;
        }

        .leads-table tbody tr:hover{
            background:#fafbfc;
        }

        .lead-name-wrap{
            display:flex;
            align-items:center;
            gap:10px;
        }

        .avatar-badge{
            width:42px;
            height:42px;
            border-radius:12px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:linear-gradient(135deg,#d8a500,#b8860b);
            color:#111;
            font-weight:900;
            flex-shrink:0;
        }

        .lead-name{
            font-weight:800;
            color:#1f2937;
        }

        .lead-sub{
            font-size:12px;
            color:#667085;
        }

        .badge-status,
        .badge-priority{
            padding:6px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:800;
            display:inline-block;
            white-space:nowrap;
        }

        .badge-new{ background:#dbeafe; color:#1e40af; }
        .badge-contacted{ background:#e0e7ff; color:#3730a3; }
        .badge-interested{ background:#d1fae5; color:#065f46; }
        .badge-followup{ background:#fef3c7; color:#92400e; }
        .badge-sitevisit{ background:#cffafe; color:#0e7490; }
        .badge-token{ background:#fff4cc; color:#946400; }
        .badge-converted{ background:#dcfce7; color:#14532d; }
        .badge-lost{ background:#fee2e2; color:#7f1d1d; }

        .priority-low{ background:#f3f4f6; color:#4b5563; }
        .priority-medium{ background:#dbeafe; color:#1d4ed8; }
        .priority-high{ background:#ffedd5; color:#c2410c; }
        .priority-hot{ background:#fee2e2; color:#b91c1c; }

        .source-chip{
            background:#f3f4f8;
            color:#5a6280;
            padding:5px 10px;
            border-radius:7px;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }

        .agent-cell{
            display:flex;
            align-items:center;
            gap:8px;
            font-weight:700;
            color:#334155;
            white-space:nowrap;
        }

        .agent-dot{
            width:8px;
            height:8px;
            border-radius:50%;
            background:#d8a500;
        }

        .actions{
            display:flex;
            gap:8px;
            align-items:center;
        }

        .action-btn{
            width:36px;
            height:36px;
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

        .action-btn:hover{
            transform:translateY(-2px);
        }

        .action-view{
            background:#e8ecff;
            color:#111c44;
        }

        .action-edit{
            background:#fff4d6;
            color:#e0a100;
        }

        .action-delete{
            background:#ffe3e3;
            color:#ef4444;
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
            font-weight:700;
        }

        /* ================= MODAL ================= */
        .erp-modal-content{
            background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
            color:#fff;
            border:none;
            border-radius:14px;
            overflow:hidden;
            box-shadow:0 20px 60px rgba(0,0,0,0.35);
        }

        .erp-modal-header{
            background:transparent;
            border-bottom:1px solid rgba(255,255,255,0.08);
            padding:18px 22px;
        }

        .erp-modal-header .modal-title{
            font-weight:800;
            font-size:18px;
            color:#fff;
        }

        .erp-modal-body{
            padding:20px 22px 10px;
        }

        .erp-modal-footer{
            border-top:1px solid rgba(255,255,255,0.08);
            padding:16px 22px 18px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            flex-wrap:wrap;
            gap:14px;
        }

        .erp-left-note{
            color:#dbe0f4;
            font-size:14px;
            font-weight:600;
        }

        .erp-footer-btns{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }

        .erp-cancel-btn{
            height:42px;
            min-width:110px;
            border:none;
            border-radius:8px;
            background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
            color:#fff;
            font-weight:700;
        }

        .erp-save-btn{
            height:42px;
            min-width:130px;
            border:none;
            border-radius:8px;
            background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
            color:#fff;
            font-weight:800;
        }

        .erp-label{
            display:block;
            font-size:14px;
            margin-bottom:7px;
            color:#e4e8f8;
            font-weight:600;
        }

        .erp-label span{
            color:#f4b21c;
        }

        .erp-input,
        .erp-select,
        .erp-textarea{
            width:100%;
            border:1px solid rgba(255,255,255,0.10);
            background:#e9eaf3;
            color:#222;
            border-radius:8px;
            outline:none;
            font-size:14px;
            transition:all .2s ease;
        }

        .erp-input,
        .erp-select{
            height:44px;
            padding:8px 14px;
        }

        .erp-textarea{
            min-height:96px;
            resize:none;
            padding:12px 14px;
        }

        .erp-input:focus,
        .erp-select:focus,
        .erp-textarea:focus{
            border-color:#f0ae19;
            box-shadow:0 0 0 2px rgba(240,174,25,0.18);
            background:#f3f4fa;
        }

        .view-box{
            background:#f8fafc;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:14px;
            margin-bottom:12px;
        }

        .view-label{
            font-size:12px;
            color:#64748b;
            font-weight:700;
            text-transform:uppercase;
            margin-bottom:4px;
        }

        .view-value{
            color:#111827;
            font-weight:800;
        }

        @media (max-width:1100px){
            .stats-row{
                grid-template-columns:repeat(3,1fr);
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
    /* =====================================================
   AGENCY-LEAD.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
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

/* Layout / Sidebar */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
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

/* Topbar / Page Background */
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

/* Header */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.page-subtitle,
.lead-sub,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

/* Header Buttons */
.btn-soft{
    min-height:46px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 18px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    transition:.22s ease !important;
}

.btn-add{
    background:linear-gradient(135deg,#f59e0b,#fb923c) !important;
    color:#111827 !important;
    box-shadow:0 14px 28px rgba(245,158,11,.22) !important;
}

.btn-import,
.btn-export,
.btn-bulk{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-soft:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
    color:#fff !important;
}

/* Stats Cards */
.stats-row{
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
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    transition:.25s ease !important;
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
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.card-blue{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.card-dark{background:linear-gradient(135deg,#111827,#4338ca) !important;}
.card-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.card-yellow{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.card-cyan{background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.card-orange{background:linear-gradient(135deg,#be123c,#ef4444) !important;}

.stat-card .title{
    font-size:14px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
}

.stat-card .count{
    font-size:30px !important;
    font-weight:900 !important;
    color:#fff !important;
    line-height:1 !important;
}

.stat-card i{
    position:relative;
    z-index:2;
    color:#fff !important;
    opacity:.94 !important;
}

/* Glass Panels */
.filter-bar,
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

.filter-bar:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-bar{
    padding:14px !important;
    gap:10px !important;
    margin-bottom:18px !important;
}

.table-card{
    overflow:hidden !important;
}

/* Inputs / Filter */
.quick-btn,
.filter-btn{
    min-height:44px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 16px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.quick-btn:hover,
.filter-btn:hover{
    transform:translateY(-1px);
    color:#fff !important;
}

.search-wrap i{
    color:#94a3b8 !important;
}

.search-wrap input,
.filter-control{
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

.search-wrap input::placeholder{
    color:#94a3b8 !important;
}

.search-wrap input:focus,
.filter-control:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-control option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.dropdown-menu{
    background:#0f172a !important;
    border:1px solid rgba(148,163,184,.22) !important;
    border-radius:14px !important;
    box-shadow:0 24px 60px rgba(0,0,0,.45) !important;
    padding:8px !important;
}

.dropdown-item{
    border-radius:10px !important;
    padding:10px 12px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
}

.dropdown-item:hover{
    background:rgba(109,76,255,.18) !important;
    color:#fff !important;
}

/* Table */
.table-responsive,
.leads-table,
.leads-table tbody,
.leads-table tbody tr{
    background:transparent !important;
}

.leads-table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

.leads-table thead th{
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

.leads-table tbody td{
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

/* White hover remove */
.leads-table tbody tr:hover,
.leads-table tbody tr:hover td,
.leads-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

.leads-table tbody tr:focus,
.leads-table tbody tr:active,
.leads-table tbody td:focus,
.leads-table tbody td:active,
.leads-table tbody td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

.leads-table tbody td.text-center,
.leads-table tbody td.py-4,
.leads-table tbody td[colspan]{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

.leads-table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

.lead-name,
.agent-cell{
    color:#f8fafc !important;
}

.avatar-badge{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 10px 22px rgba(109,76,255,.24) !important;
}

.agent-dot{
    background:#a78bfa !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
}

.source-chip{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
    border-radius:999px !important;
    font-weight:900 !important;
}

/* Badges */
.badge-status,
.badge-priority{
    border:1px solid rgba(148,163,184,.18) !important;
}

.badge-new,
.badge-contacted,
.priority-medium{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border-color:rgba(59,130,246,.24) !important;
}

.badge-interested,
.badge-converted,
.priority-low{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

.badge-followup,
.badge-sitevisit,
.badge-token,
.priority-high{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.badge-lost,
.priority-hot{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

/* Actions */
.action-btn{
    width:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.62) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.action-view{color:#60a5fa !important;}
.action-edit{color:#fbbf24 !important;}
.action-delete{color:#f87171 !important;}

.action-btn:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.20) !important;
    color:#fff !important;
}

/* Footer / Bulk */
.table-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
    color:#94a3b8 !important;
}

.table-footer strong{
    color:#f8fafc !important;
}

.bulk-delete-btn{
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 14px 28px rgba(239,68,68,.22) !important;
}

/* Alert */
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

/* Scrollbar */
.table-responsive::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-responsive::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-responsive::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-responsive::-webkit-scrollbar-thumb:hover,
.content-wrapper::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

/* =====================================================
   AGENCY LEAD POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

.modal-backdrop.show{
    opacity:.74 !important;
    background:#000 !important;
}

#leadModal .modal-dialog{
    max-width:min(980px,94vw) !important;
}

#importModal .modal-dialog{
    max-width:min(560px,94vw) !important;
}

#viewLeadModal .modal-dialog{
    max-width:min(860px,94vw) !important;
}

#leadModal .erp-modal-content,
#importModal .erp-modal-content,
#viewLeadModal .modal-content{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#leadModal .erp-modal-header,
#importModal .erp-modal-header,
#viewLeadModal .modal-header{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

#leadModal .modal-title,
#importModal .modal-title,
#viewLeadModal .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

#leadModal .modal-title::before,
#importModal .modal-title::before,
#viewLeadModal .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

#leadModal .btn-close,
#importModal .btn-close,
#viewLeadModal .btn-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    min-width:38px !important;
    min-height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    background-image:none !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    padding:0 !important;
    margin:0 !important;
    box-shadow:none !important;
    filter:none !important;
}

#leadModal .btn-close::before,
#leadModal .btn-close::after,
#importModal .btn-close::before,
#importModal .btn-close::after,
#viewLeadModal .btn-close::before,
#viewLeadModal .btn-close::after{
    content:'' !important;
    position:absolute !important;
    left:50% !important;
    top:50% !important;
    width:18px !important;
    height:2px !important;
    background:#ffffff !important;
    border-radius:99px !important;
    transform-origin:center !important;
}

#leadModal .btn-close::before,
#importModal .btn-close::before,
#viewLeadModal .btn-close::before{
    transform:translate(-50%, -50%) rotate(45deg) !important;
}

#leadModal .btn-close::after,
#importModal .btn-close::after,
#viewLeadModal .btn-close::after{
    transform:translate(-50%, -50%) rotate(-45deg) !important;
}

#leadModal .btn-close:hover,
#importModal .btn-close:hover,
#viewLeadModal .btn-close:hover{
    background:#ef4444 !important;
}

#leadModal .erp-modal-body,
#importModal .erp-modal-body,
#viewLeadModal .modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#leadModal .erp-label,
#importModal .erp-label,
#viewLeadModal .view-label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

#leadModal .erp-label span,
#importModal .erp-label span{
    color:#fbbf24 !important;
}

#leadModal .erp-input,
#leadModal .erp-select,
#leadModal .erp-textarea,
#importModal .erp-input,
#importModal .erp-select,
#importModal .erp-textarea{
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
}

#leadModal .erp-textarea,
#importModal .erp-textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

#leadModal .erp-input::placeholder,
#leadModal .erp-textarea::placeholder,
#importModal .erp-input::placeholder,
#importModal .erp-textarea::placeholder{
    color:#94a3b8 !important;
}

#leadModal .erp-input:focus,
#leadModal .erp-select:focus,
#leadModal .erp-textarea:focus,
#importModal .erp-input:focus,
#importModal .erp-select:focus,
#importModal .erp-textarea:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

#leadModal select option,
#importModal select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

#leadModal .erp-modal-footer,
#importModal .erp-modal-footer,
#viewLeadModal .modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
}

#leadModal .erp-left-note,
#importModal .erp-left-note{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

#leadModal .erp-cancel-btn,
#importModal .erp-cancel-btn,
#viewLeadModal .btn-secondary,
#viewLeadModal .btn-outline-secondary{
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

#leadModal .erp-save-btn,
#importModal .erp-save-btn,
#viewLeadModal .btn-primary{
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

#leadModal .erp-cancel-btn:hover,
#importModal .erp-cancel-btn:hover,
#leadModal .erp-save-btn:hover,
#importModal .erp-save-btn:hover,
#viewLeadModal .btn-secondary:hover,
#viewLeadModal .btn-primary:hover{
    transform:translateY(-1px);
}

#viewLeadModal .view-box{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#viewLeadModal .view-label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

#viewLeadModal .view-value{
    color:#f8fafc !important;
    font-weight:900 !important;
}

#importModal .erp-modal-body strong{
    color:#c4b5fd !important;
}

#importModal .erp-modal-body .mt-3{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:14px !important;
    color:#94a3b8 !important;
}

@media(max-width:1280px){
    .stats-row{
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
}

@media(max-width:768px){
    .stats-row{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .header-actions,
    .btn-soft{
        width:100% !important;
    }

    .btn-soft{
        justify-content:center !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .filter-bar{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .search-wrap,
    .filter-control,
    .filter-btn,
    .quick-btn{
        width:100% !important;
        min-width:100% !important;
    }

    .erp-footer-btns{
        width:100% !important;
        flex-direction:column !important;
    }

    #leadModal .modal-dialog,
    #importModal .modal-dialog,
    #viewLeadModal .modal-dialog{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    #leadModal .modal-title,
    #importModal .modal-title,
    #viewLeadModal .modal-title{
        font-size:18px !important;
    }

    #leadModal .erp-cancel-btn,
    #leadModal .erp-save-btn,
    #importModal .erp-cancel-btn,
    #importModal .erp-save-btn{
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
                    <h1 class="page-title">Agency Leads</h1>
                    <div class="page-subtitle">State Agency / Real Estate lead management with agent assignment and follow-up tracking</div>
                </div>

                <div class="header-actions">
                    <button class="btn-soft btn-add" type="button" onclick="openLeadModal()">
                        <i class="bi bi-plus-lg"></i> Add New Lead
                    </button>

                    <button class="btn-soft btn-import" type="button" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="bi bi-upload"></i> Import
                    </button>

                    <a class="btn-soft btn-export" href="agency-lead.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
                        <i class="bi bi-download"></i> Export
                    </a>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card card-blue">
                    <div>
                        <div class="title">Total Leads</div>
                        <div class="count"><?= (int)$totalLeads ?></div>
                    </div>
                    <i class="bi bi-people-fill"></i>
                </div>

                <div class="stat-card card-dark">
                    <div>
                        <div class="title">New Leads</div>
                        <div class="count"><?= (int)$newLeads ?></div>
                    </div>
                    <i class="bi bi-person-plus-fill"></i>
                </div>

                <div class="stat-card card-green">
                    <div>
                        <div class="title">Interested</div>
                        <div class="count"><?= (int)$interestedLeads ?></div>
                    </div>
                    <i class="bi bi-stars"></i>
                </div>

                <div class="stat-card card-yellow">
                    <div>
                        <div class="title">Follow Up</div>
                        <div class="count"><?= (int)$followupLeads ?></div>
                    </div>
                    <i class="bi bi-telephone-forward-fill"></i>
                </div>

                <div class="stat-card card-cyan">
                    <div>
                        <div class="title">Site Visit</div>
                        <div class="count"><?= (int)$siteVisitLeads ?></div>
                    </div>
                    <i class="bi bi-geo-alt-fill"></i>
                </div>

                <div class="stat-card card-orange">
                    <div>
                        <div class="title">Converted</div>
                        <div class="count"><?= (int)$convertedLeads ?></div>
                    </div>
                    <i class="bi bi-trophy-fill"></i>
                </div>
            </div>

            <form class="filter-bar" method="GET" action="agency-lead.php">
                <div class="dropdown">
                    <button class="quick-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-lightning-charge-fill me-1"></i> Quick Actions
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="selectAllRows(event)">Select All</a></li>
                        <li><a class="dropdown-item" href="#" onclick="unselectAllRows(event)">Unselect All</a></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="deleteSelected(event)">Delete Selected</a></li>
                    </ul>
                </div>

                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="searchInput" placeholder="Search by lead name, phone, email, location, lead no." value="<?= e($search) ?>">
                </div>

                <select name="status" class="filter-control">
                    <option value="">All Status</option>
                    <?php foreach ($leadStatuses as $st): ?>
                        <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="source" class="filter-control">
                    <option value="">All Sources</option>
                    <?php foreach ($leadSources as $src): ?>
                        <option value="<?= e($src) ?>" <?= $sourceFilter === $src ? 'selected' : '' ?>><?= e($src) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="priority" class="filter-control">
                    <option value="">All Priority</option>
                    <?php foreach ($priorities as $pr): ?>
                        <option value="<?= e($pr) ?>" <?= $priorityFilter === $pr ? 'selected' : '' ?>><?= e($pr) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="agent_id" class="filter-control">
                    <option value="">All Agents</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?= (int)$agent['id'] ?>" <?= $agentFilter === (int)$agent['id'] ? 'selected' : '' ?>>
                            <?= e($agent['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="date" name="date_from" class="filter-control" value="<?= e($dateFrom) ?>">
                <input type="date" name="date_to" class="filter-control" value="<?= e($dateTo) ?>">

                <button type="submit" class="filter-btn">
                    <i class="bi bi-funnel"></i> Filter
                </button>

                <a href="agency-lead.php" class="filter-btn">
                    <i class="bi bi-arrow-clockwise"></i> Reset
                </a>
            </form>

            <div class="table-card">
                <form method="POST" id="bulkDeleteForm">
                    <input type="hidden" name="action" value="bulk_delete">

                    <div class="table-responsive">
                        <table class="leads-table">
                            <thead>
                                <tr>
                                    <th style="width:50px;"><input type="checkbox" id="checkAll"></th>
                                    <th style="width:80px;">ID</th>
                                    <th>Lead</th>
                                    <th>Phone</th>
                                    <th>Source</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Interest</th>
                                    <th>Location</th>
                                    <th>Budget</th>
                                    <th>Agent</th>
                                    <th>Next Follow-up</th>
                                    <th style="width:140px;">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($leads)): ?>
                                    <tr>
                                        <td colspan="13" class="text-center py-4">No leads found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($leads as $lead): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="rowCheck" name="selected_ids[]" value="<?= (int)$lead['id'] ?>">
                                            </td>

                                            <td><?= (int)$lead['id'] ?></td>

                                            <td>
                                                <div class="lead-name-wrap">
                                                    <span class="avatar-badge"><?= e(leadInitials($lead['lead_name'] ?? '')) ?></span>
                                                    <div>
                                                        <div class="lead-name"><?= e($lead['lead_name'] ?? '') ?></div>
                                                        <div class="lead-sub"><?= e($lead['lead_no'] ?? '') ?> <?= !empty($lead['email']) ? ' • ' . e($lead['email']) : '' ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td><?= e($lead['phone'] ?? '') ?></td>

                                            <td>
                                                <span class="source-chip"><?= e($lead['source'] ?? '') ?></span>
                                            </td>

                                            <td>
                                                <span class="badge-status <?= e(statusBadgeClass($lead['status'] ?? '')) ?>">
                                                    <?= e($lead['status'] ?? '') ?>
                                                </span>
                                            </td>

                                            <td>
                                                <span class="badge-priority <?= e(priorityBadgeClass($lead['priority'] ?? '')) ?>">
                                                    <?= e($lead['priority'] ?? '') ?>
                                                </span>
                                            </td>

                                            <td><?= e(($lead['interest_type'] ?? '') . ' / ' . ($lead['property_type'] ?? '')) ?></td>

                                            <td>
                                                <strong><?= e($lead['preferred_city'] ?? '') ?></strong>
                                                <div class="lead-sub"><?= e($lead['preferred_location'] ?? '') ?></div>
                                            </td>

                                            <td>
                                                Rs. <?= number_format((float)($lead['budget_min'] ?? 0), 0) ?>
                                                -
                                                Rs. <?= number_format((float)($lead['budget_max'] ?? 0), 0) ?>
                                            </td>

                                            <td>
                                                <div class="agent-cell">
                                                    <span class="agent-dot"></span>
                                                    <?= e($lead['agent_name'] ?: 'Unassigned') ?>
                                                </div>
                                            </td>

                                            <td>
                                                <?php if (!empty($lead['next_followup_at'])): ?>
                                                    <?= e(date('d M Y h:i A', strtotime($lead['next_followup_at']))) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <div class="actions">
                                                    <button type="button" class="action-btn action-view" title="View" onclick="viewLead(<?= (int)$lead['id'] ?>)">
                                                        <i class="bi bi-eye"></i>
                                                    </button>

                                                    <button type="button" class="action-btn action-edit" title="Edit" onclick="editLead(<?= (int)$lead['id'] ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>

                                                    <button type="button" class="action-btn action-delete" title="Delete" onclick="deleteLead(<?= (int)$lead['id'] ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-footer">
                        <div>
                            Showing <strong><?= count($leads) ?></strong> lead(s)
                        </div>

                        <button type="button" class="bulk-delete-btn" onclick="deleteSelected(event)">
                            <i class="bi bi-trash me-1"></i> Delete Selected
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>


<!-- ADD / EDIT LEAD MODAL -->
<div class="modal fade" id="leadModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content erp-modal-content">
            <form method="POST" id="leadForm">
                <input type="hidden" name="action" id="formAction" value="add_lead">
                <input type="hidden" name="id" id="lead_id">

                <div class="modal-header erp-modal-header">
                    <h5 class="modal-title" id="leadModalTitle">Add New Lead</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body erp-modal-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="erp-label">Lead Name <span>*</span></label>
                            <input type="text" name="lead_name" id="lead_name" class="erp-input" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="erp-label">Phone <span>*</span></label>
                            <input type="text" name="phone" id="phone" class="erp-input" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="erp-label">Email</label>
                            <input type="email" name="email" id="email" class="erp-input">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="erp-label">Address</label>
                            <input type="text" name="address" id="address" class="erp-input">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Lead Source</label>
                            <select name="source" id="source" class="erp-select">
                                <?php foreach ($leadSources as $src): ?>
                                    <option value="<?= e($src) ?>"><?= e($src) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Priority</label>
                            <select name="priority" id="priority" class="erp-select">
                                <?php foreach ($priorities as $pr): ?>
                                    <option value="<?= e($pr) ?>"><?= e($pr) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Interested Type</label>
                            <select name="interest_type" id="interest_type" class="erp-select">
                                <?php foreach ($interestTypes as $type): ?>
                                    <option value="<?= e($type) ?>"><?= e($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Property Type</label>
                            <select name="property_type" id="property_type" class="erp-select">
                                <?php foreach ($propertyTypes as $type): ?>
                                    <option value="<?= e($type) ?>"><?= e($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">City</label>
                            <input type="text" name="preferred_city" id="preferred_city" class="erp-input" placeholder="Karachi">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Preferred Location</label>
                            <input type="text" name="preferred_location" id="preferred_location" class="erp-input" placeholder="DHA / Bahria / Gulshan">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Budget Min</label>
                            <input type="number" step="0.01" name="budget_min" id="budget_min" class="erp-input">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Budget Max</label>
                            <input type="number" step="0.01" name="budget_max" id="budget_max" class="erp-input">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Assigned Agent</label>
                            <select name="assigned_agent_id" id="assigned_agent_id" class="erp-select">
                                <option value="">Unassigned</option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?= (int)$agent['id'] ?>"><?= e($agent['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Status</label>
                            <select name="status" id="status" class="erp-select">
                                <?php foreach ($leadStatuses as $st): ?>
                                    <option value="<?= e($st) ?>"><?= e($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Next Follow-up Date</label>
                            <input type="date" name="next_followup_date" id="next_followup_date" class="erp-input">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="erp-label">Next Follow-up Time</label>
                            <input type="time" name="next_followup_time" id="next_followup_time" class="erp-input">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="erp-label">Requirement</label>
                            <textarea name="requirement" id="requirement" class="erp-textarea" placeholder="Customer requirement, property size, location, demand etc."></textarea>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="erp-label">Lead Notes</label>
                            <textarea name="lead_notes" id="lead_notes" class="erp-textarea" placeholder="Call summary, WhatsApp notes, internal remarks etc."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer erp-modal-footer">
                    <div class="erp-left-note">
                        Fields marked <span style="color:#f4b21c;">*</span> are required
                    </div>

                    <div class="erp-footer-btns">
                        <button type="button" class="erp-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="erp-save-btn">Save Lead</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- VIEW LEAD MODAL -->
<div class="modal fade" id="viewLeadModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#111827;color:#fff;">
                <h5 class="modal-title">Lead Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="viewLeadBody"></div>
        </div>
    </div>
</div>


<!-- IMPORT MODAL -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content erp-modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_leads">

                <div class="modal-header erp-modal-header">
                    <h5 class="modal-title">Import Leads CSV</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body erp-modal-body">
                    <label class="erp-label">CSV File</label>
                    <input type="file" name="import_file" class="erp-input" accept=".csv" required>

                    <div class="mt-3" style="color:#dbe0f4;font-size:13px;line-height:1.7;">
                        CSV columns order:<br>
                        <strong>lead_name, phone, email, source, status, priority, city, location, budget_min, budget_max, requirement</strong>
                    </div>
                </div>

                <div class="modal-footer erp-modal-footer">
                    <div></div>
                    <div class="erp-footer-btns">
                        <button type="button" class="erp-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="erp-save-btn">Import</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- DELETE SINGLE FORM -->
<form method="POST" id="deleteLeadForm" style="display:none;">
    <input type="hidden" name="action" value="delete_lead">
    <input type="hidden" name="id" id="deleteLeadId">
</form>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const leads = <?= $leadsJson ?: '[]' ?>;

const leadModal = new bootstrap.Modal(document.getElementById('leadModal'));
const viewLeadModal = new bootstrap.Modal(document.getElementById('viewLeadModal'));

function byId(id) {
    return document.getElementById(id);
}

function openLeadModal() {
    byId('leadForm').reset();
    byId('formAction').value = 'add_lead';
    byId('lead_id').value = '';
    byId('leadModalTitle').innerText = 'Add New Lead';
    byId('status').value = 'New';
    byId('priority').value = 'Medium';
    byId('source').value = 'Walk-in';
    leadModal.show();
}

function editLead(id) {
    const lead = leads.find(l => parseInt(l.id) === parseInt(id));

    if (!lead) {
        alert('Lead not found.');
        return;
    }

    byId('leadForm').reset();
    byId('formAction').value = 'update_lead';
    byId('lead_id').value = lead.id || '';
    byId('leadModalTitle').innerText = 'Edit Lead';

    byId('lead_name').value = lead.lead_name || '';
    byId('phone').value = lead.phone || '';
    byId('email').value = lead.email || '';
    byId('address').value = lead.address || '';
    byId('source').value = lead.source || 'Walk-in';
    byId('priority').value = lead.priority || 'Medium';
    byId('interest_type').value = lead.interest_type || 'Buy';
    byId('property_type').value = lead.property_type || 'Plot';
    byId('preferred_city').value = lead.preferred_city || '';
    byId('preferred_location').value = lead.preferred_location || '';
    byId('budget_min').value = lead.budget_min || '';
    byId('budget_max').value = lead.budget_max || '';
    byId('assigned_agent_id').value = lead.assigned_agent_id || '';
    byId('status').value = lead.status || 'New';
    byId('requirement').value = lead.requirement || '';
    byId('lead_notes').value = '';

    if (lead.next_followup_at) {
        byId('next_followup_date').value = String(lead.next_followup_at).substring(0, 10);
        byId('next_followup_time').value = String(lead.next_followup_at).substring(11, 16);
    } else {
        byId('next_followup_date').value = '';
        byId('next_followup_time').value = '';
    }

    leadModal.show();
}

function viewLead(id) {
    const lead = leads.find(l => parseInt(l.id) === parseInt(id));

    if (!lead) {
        alert('Lead not found.');
        return;
    }

    const budgetMin = parseFloat(lead.budget_min || 0).toLocaleString();
    const budgetMax = parseFloat(lead.budget_max || 0).toLocaleString();

    byId('viewLeadBody').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Lead Name</div>
                    <div class="view-value">${escapeHtml(lead.lead_name || '')}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Lead No</div>
                    <div class="view-value">${escapeHtml(lead.lead_no || '')}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Phone</div>
                    <div class="view-value">${escapeHtml(lead.phone || '')}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Email</div>
                    <div class="view-value">${escapeHtml(lead.email || '-')}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="view-box">
                    <div class="view-label">Source</div>
                    <div class="view-value">${escapeHtml(lead.source || '')}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="view-box">
                    <div class="view-label">Status</div>
                    <div class="view-value">${escapeHtml(lead.status || '')}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="view-box">
                    <div class="view-label">Priority</div>
                    <div class="view-value">${escapeHtml(lead.priority || '')}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Interest / Property Type</div>
                    <div class="view-value">${escapeHtml((lead.interest_type || '') + ' / ' + (lead.property_type || ''))}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Assigned Agent</div>
                    <div class="view-value">${escapeHtml(lead.agent_name || 'Unassigned')}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Location</div>
                    <div class="view-value">${escapeHtml((lead.preferred_city || '') + ' - ' + (lead.preferred_location || ''))}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Budget</div>
                    <div class="view-value">Rs. ${budgetMin} - Rs. ${budgetMax}</div>
                </div>
            </div>
            <div class="col-md-12">
                <div class="view-box">
                    <div class="view-label">Requirement</div>
                    <div class="view-value">${escapeHtml(lead.requirement || '-')}</div>
                </div>
            </div>
        </div>
    `;

    viewLeadModal.show();
}

function deleteLead(id) {
    if (!confirm('Delete this lead?')) return;

    byId('deleteLeadId').value = id;
    byId('deleteLeadForm').submit();
}

function selectAllRows(e) {
    if (e) e.preventDefault();
    document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = true);
    byId('checkAll').checked = true;
}

function unselectAllRows(e) {
    if (e) e.preventDefault();
    document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = false);
    byId('checkAll').checked = false;
}

function deleteSelected(e) {
    if (e) e.preventDefault();

    const checked = document.querySelectorAll('.rowCheck:checked');

    if (!checked.length) {
        alert('Please select at least one lead.');
        return;
    }

    if (!confirm('Delete selected leads?')) return;

    byId('bulkDeleteForm').submit();
}

byId('checkAll')?.addEventListener('change', function () {
    document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = this.checked);
});

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
</script>

</body>
</html>
