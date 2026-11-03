<?php
/*
|--------------------------------------------------------------------------
| follow-ups.php
| State Agency / Real Estate Follow Ups
|--------------------------------------------------------------------------
| Required tables:
| - re_followups
| - re_leads
| - users
|
| Recommended URL:
| http://localhost/pos/public/app/views/follow-ups.php
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

function redirect_with_message($type, $message) {
    header("Location: follow-ups.php?msg_type=" . urlencode($type) . "&msg=" . urlencode($message));
    exit;
}

function normalize_enum($value, array $allowed, string $default) {
    $value = trim((string)$value);
    return in_array($value, $allowed, true) ? $value : $default;
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

function safeDateTime($value) {
    $value = trim((string)$value);
    if ($value === '') return null;

    // HTML datetime-local: 2026-05-05T15:00
    return str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '');
}

function typeBadgeClass($type) {
    $map = [
        'Phone Call'       => 'type-call',
        'WhatsApp Message' => 'type-wa',
        'Meeting'          => 'type-meeting',
        'Site Visit'       => 'type-visit',
        'Video Call'       => 'type-video',
        'Other'            => 'type-meeting',
    ];
    return $map[$type] ?? 'type-call';
}

function typeIcon($type) {
    $map = [
        'Phone Call'       => 'bi-telephone',
        'WhatsApp Message' => 'bi-whatsapp',
        'Meeting'          => 'bi-people',
        'Site Visit'       => 'bi-geo-alt',
        'Video Call'       => 'bi-camera-video',
        'Other'            => 'bi-chat-dots',
    ];
    return $map[$type] ?? 'bi-telephone';
}

function statusBadgeClass($status) {
    $map = [
        'Pending'   => 'status-pending',
        'Done'      => 'status-done',
        'Completed' => 'status-done',
        'Missed'    => 'status-missed',
        'Cancelled' => 'status-missed',
    ];
    return $map[$status] ?? 'status-pending';
}

/* ===================== REQUIRED TABLE CHECK ===================== */
if (!tableExists($conn, 're_followups')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>re_followups table missing</h3>
            <p>Pehle State Agency / Real Estate tables wali SQL run karo.</p>
            <pre style='white-space:pre-wrap;background:#fff;padding:15px;border-radius:8px;'>CREATE TABLE re_followups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  branch_id INT DEFAULT NULL,
  lead_id INT NOT NULL,
  property_id INT DEFAULT NULL,
  agent_id INT DEFAULT NULL,
  followup_type ENUM('Phone Call','WhatsApp Message','Email','Meeting','Site Visit','Video Call','Other') DEFAULT 'Phone Call',
  followup_at DATETIME NOT NULL,
  status ENUM('Pending','Done','Missed','Cancelled') DEFAULT 'Pending',
  response_status ENUM('No Response','Interested','Not Interested','Call Later','Visit Scheduled','Deal Possible','Other') DEFAULT 'No Response',
  notes TEXT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);</pre>
        </div>
    ");
}

if (!tableExists($conn, 're_leads')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>re_leads table missing</h3>
            <p>Pehle Agency Leads table create karo.</p>
        </div>
    ");
}

/* ===================== OPTIONS ===================== */
$followupTypes = [
    'Phone Call',
    'WhatsApp Message',
    'Meeting',
    'Site Visit',
    'Video Call',
    'Other'
];

$followupStatuses = [
    'Pending',
    'Done',
    'Missed',
    'Cancelled'
];

$responseStatuses = [
    'No Response',
    'Interested',
    'Not Interested',
    'Call Later',
    'Visit Scheduled',
    'Deal Possible',
    'Other'
];

/* ===================== COLUMN COMPATIBILITY ===================== */
$hasResponseStatus = columnExists($conn, 're_followups', 'response_status');
$hasResponse       = columnExists($conn, 're_followups', 'response');
$hasFollowupAt     = columnExists($conn, 're_followups', 'followup_at');
$hasFollowupDate   = columnExists($conn, 're_followups', 'followup_datetime');
$followupDateCol   = $hasFollowupAt ? 'followup_at' : ($hasFollowupDate ? 'followup_datetime' : 'followup_at');

$leadAgentExpr = columnExists($conn, 're_leads', 'assigned_agent_id')
    ? 'l.assigned_agent_id'
    : (columnExists($conn, 're_leads', 'agent_id') ? 'l.agent_id' : 'NULL');

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

/* ===================== LEADS DROPDOWN ===================== */
$leadsForDropdown = [];
$stmt = $conn->prepare("
    SELECT
        l.id,
        l.lead_name,
        l.phone,
        l.status,
        $leadAgentExpr AS assigned_agent_id
    FROM re_leads l
    WHERE l.client_id = ?
    ORDER BY l.id DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $leadsForDropdown[] = $row;
}
$stmt->close();

/* ===================== POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_followup' || $action === 'update_followup') {
        $id              = (int)($_POST['id'] ?? 0);
        $lead_id         = (int)($_POST['lead_id'] ?? 0);
        $agent_id        = (int)($_POST['agent_id'] ?? 0);
        $followup_type   = normalize_enum($_POST['followup_type'] ?? 'Phone Call', $followupTypes, 'Phone Call');
        $followup_at     = safeDateTime($_POST['followup_datetime'] ?? '');
        $status          = normalize_enum($_POST['status'] ?? 'Pending', $followupStatuses, 'Pending');
        $response_status = normalize_enum($_POST['response_status'] ?? 'No Response', $responseStatuses, 'No Response');
        $response_text   = trim($_POST['response'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');
        $next_followup   = safeDateTime($_POST['next_followup_at'] ?? '');

        if ($lead_id <= 0) {
            redirect_with_message('danger', 'Lead is required.');
        }

        if ($agent_id <= 0) {
            redirect_with_message('danger', 'Agent is required.');
        }

        if ($followup_at === null) {
            redirect_with_message('danger', 'Follow-up date/time is required.');
        }

        /*
         * Agar response_status column hai to response_status me selected response save hoga.
         * Agar response column hai to free text response save hoga.
         * Agar dono me se koi nahi hai to response notes ke sath notes column me merge hoga.
         */
        $finalNotes = $notes;
        if (!$hasResponseStatus && !$hasResponse && $response_text !== '') {
            $finalNotes = trim("Response: {$response_text}\n" . $notes);
        }

        if ($action === 'add_followup') {
            $columns = ['client_id', 'branch_id', 'lead_id', 'agent_id', 'followup_type', $followupDateCol, 'status', 'notes', 'created_by'];
            $values  = [$client_id, ($branch_id > 0 ? $branch_id : null), $lead_id, $agent_id, $followup_type, $followup_at, $status, $finalNotes, $user_id];
            $types   = "iiiissssi";

            if ($hasResponseStatus) {
                $columns[] = 'response_status';
                $values[] = $response_status;
                $types .= "s";
            }

            if ($hasResponse) {
                $columns[] = 'response';
                $values[] = $response_text;
                $types .= "s";
            }

            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO re_followups (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $stmt->close();

            /*
             * Lead ko auto update:
             * - lead status Follow Up
             * - assigned agent update
             * - next follow-up update if column exists
             */
            $updateParts = [];
            $updateValues = [];
            $updateTypes = "";

            if (columnExists($conn, 're_leads', 'status')) {
                $updateParts[] = "status = ?";
                $updateValues[] = ($followup_type === 'Site Visit') ? 'Site Visit' : 'Follow Up';
                $updateTypes .= "s";
            }

            if (columnExists($conn, 're_leads', 'assigned_agent_id')) {
                $updateParts[] = "assigned_agent_id = ?";
                $updateValues[] = $agent_id;
                $updateTypes .= "i";
            } elseif (columnExists($conn, 're_leads', 'agent_id')) {
                $updateParts[] = "agent_id = ?";
                $updateValues[] = $agent_id;
                $updateTypes .= "i";
            }

            if ($next_followup !== null && columnExists($conn, 're_leads', 'next_followup_at')) {
                $updateParts[] = "next_followup_at = ?";
                $updateValues[] = $next_followup;
                $updateTypes .= "s";
            } elseif ($next_followup !== null && columnExists($conn, 're_leads', 'next_followup_date')) {
                $updateParts[] = "next_followup_date = ?";
                $updateValues[] = substr($next_followup, 0, 10);
                $updateTypes .= "s";
            }

            if (!empty($updateParts)) {
                $updateValues[] = $lead_id;
                $updateValues[] = $client_id;
                $updateTypes .= "ii";

                $sql = "UPDATE re_leads SET " . implode(', ', $updateParts) . " WHERE id = ? AND client_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($updateTypes, ...$updateValues);
                $stmt->execute();
                $stmt->close();
            }

            redirect_with_message('success', 'Follow-up added successfully.');
        }

        if ($action === 'update_followup') {
            if ($id <= 0) {
                redirect_with_message('danger', 'Invalid follow-up id.');
            }

            $setParts = [
                'lead_id = ?',
                'agent_id = ?',
                'followup_type = ?',
                "`$followupDateCol` = ?",
                'status = ?',
                'notes = ?'
            ];

            $values = [$lead_id, $agent_id, $followup_type, $followup_at, $status, $finalNotes];
            $types = "iissss";

            if ($hasResponseStatus) {
                $setParts[] = "response_status = ?";
                $values[] = $response_status;
                $types .= "s";
            }

            if ($hasResponse) {
                $setParts[] = "response = ?";
                $values[] = $response_text;
                $types .= "s";
            }

            $values[] = $id;
            $values[] = $client_id;
            $types .= "ii";

            $sql = "UPDATE re_followups SET " . implode(', ', $setParts) . " WHERE id = ? AND client_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $stmt->close();

            redirect_with_message('success', 'Follow-up updated successfully.');
        }
    }

    if ($action === 'delete_followup') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            redirect_with_message('danger', 'Invalid follow-up id.');
        }

        $stmt = $conn->prepare("DELETE FROM re_followups WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Follow-up deleted successfully.');
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['selected_ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            redirect_with_message('danger', 'Please select at least one follow-up.');
        }

        $cleanIds = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) $cleanIds[] = $id;
        }

        if (empty($cleanIds)) {
            redirect_with_message('danger', 'No valid follow-ups selected.');
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $types = str_repeat('i', count($cleanIds)) . 'i';
        $params = array_merge($cleanIds, [$client_id]);

        $sql = "DELETE FROM re_followups WHERE id IN ($placeholders) AND client_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $deletedRows = $stmt->affected_rows;
        $stmt->close();

        redirect_with_message('success', $deletedRows . ' follow-up(s) deleted successfully.');
    }
}

/* ===================== FILTERS ===================== */
$search       = trim($_GET['search'] ?? '');
$agentFilter  = (int)($_GET['agent_id'] ?? 0);
$typeFilter   = trim($_GET['followup_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dateFilter   = trim($_GET['date'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');

/* ===================== EXPORT CSV ===================== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $responseSelect = $hasResponseStatus
        ? "f.response_status AS response_value"
        : ($hasResponse ? "f.response AS response_value" : "'' AS response_value");

    $sql = "
        SELECT
            f.id,
            l.lead_name,
            l.phone,
            u.name AS agent_name,
            f.followup_type,
            f.`$followupDateCol` AS followup_at,
            f.status,
            $responseSelect,
            f.notes,
            f.created_at
        FROM re_followups f
        LEFT JOIN re_leads l ON l.id = f.lead_id AND l.client_id = f.client_id
        LEFT JOIN users u ON u.id = f.agent_id
        WHERE f.client_id = ?
    ";

    $params = [$client_id];
    $types = "i";

    if ($search !== '') {
        $sql .= " AND (l.lead_name LIKE ? OR l.phone LIKE ? OR u.name LIKE ? OR f.notes LIKE ?)";
        $like = "%{$search}%";
        array_push($params, $like, $like, $like, $like);
        $types .= "ssss";
    }

    if ($agentFilter > 0) {
        $sql .= " AND f.agent_id = ?";
        $params[] = $agentFilter;
        $types .= "i";
    }

    if ($typeFilter !== '') {
        $sql .= " AND f.followup_type = ?";
        $params[] = $typeFilter;
        $types .= "s";
    }

    if ($statusFilter !== '') {
        $sql .= " AND f.status = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }

    if ($dateFilter !== '') {
        $sql .= " AND DATE(f.`$followupDateCol`) = ?";
        $params[] = $dateFilter;
        $types .= "s";
    }

    if ($dateFrom !== '') {
        $sql .= " AND DATE(f.`$followupDateCol`) >= ?";
        $params[] = $dateFrom;
        $types .= "s";
    }

    if ($dateTo !== '') {
        $sql .= " AND DATE(f.`$followupDateCol`) <= ?";
        $params[] = $dateTo;
        $types .= "s";
    }

    $sql .= " ORDER BY f.`$followupDateCol` DESC, f.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=followups_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Lead Name', 'Phone', 'Agent', 'Type', 'Date Time', 'Status', 'Response', 'Notes', 'Created At']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['lead_name'],
            $row['phone'],
            $row['agent_name'],
            $row['followup_type'],
            $row['followup_at'],
            $row['status'],
            $row['response_value'],
            $row['notes'],
            $row['created_at'],
        ]);
    }

    fclose($output);
    $stmt->close();
    exit;
}

/* ===================== FLASH MESSAGE ===================== */
$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ===================== COUNTS ===================== */
function countFollowups(mysqli $conn, int $client_id, string $whereSql = '', array $params = [], string $types = ''): int {
    $sql = "SELECT COUNT(*) FROM re_followups WHERE client_id = ? " . $whereSql;
    $stmt = $conn->prepare($sql);

    $allParams = array_merge([$client_id], $params);
    $allTypes  = 'i' . $types;

    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int)$count;
}

$todayFollowups = countFollowups($conn, $client_id, " AND DATE(`$followupDateCol`) = CURDATE()");
$pendingFollowups = countFollowups($conn, $client_id, " AND status = 'Pending'");
$siteVisitFollowups = countFollowups($conn, $client_id, " AND followup_type = 'Site Visit' AND `$followupDateCol` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$completedFollowups = countFollowups($conn, $client_id, " AND status = 'Done'");
$interestedFollowups = $hasResponseStatus
    ? countFollowups($conn, $client_id, " AND response_status = 'Interested'")
    : 0;

/* ===================== FETCH FOLLOWUPS ===================== */
$responseSelect = $hasResponseStatus
    ? "f.response_status AS response_value"
    : ($hasResponse ? "f.response AS response_value" : "'' AS response_value");

$sql = "
    SELECT
        f.*,
        f.`$followupDateCol` AS followup_at_value,
        $responseSelect,
        l.lead_name,
        l.phone AS lead_phone,
        l.email AS lead_email,
        l.status AS lead_status,
        u.name AS agent_name
    FROM re_followups f
    LEFT JOIN re_leads l ON l.id = f.lead_id AND l.client_id = f.client_id
    LEFT JOIN users u ON u.id = f.agent_id
    WHERE f.client_id = ?
";

$params = [$client_id];
$types = "i";

if ($search !== '') {
    $sql .= " AND (l.lead_name LIKE ? OR l.phone LIKE ? OR u.name LIKE ? OR f.notes LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}

if ($agentFilter > 0) {
    $sql .= " AND f.agent_id = ?";
    $params[] = $agentFilter;
    $types .= "i";
}

if ($typeFilter !== '') {
    $sql .= " AND f.followup_type = ?";
    $params[] = $typeFilter;
    $types .= "s";
}

if ($statusFilter !== '') {
    $sql .= " AND f.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($dateFilter !== '') {
    $sql .= " AND DATE(f.`$followupDateCol`) = ?";
    $params[] = $dateFilter;
    $types .= "s";
}

if ($dateFrom !== '') {
    $sql .= " AND DATE(f.`$followupDateCol`) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if ($dateTo !== '') {
    $sql .= " AND DATE(f.`$followupDateCol`) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$sql .= " ORDER BY f.`$followupDateCol` DESC, f.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$followups = [];
while ($row = $result->fetch_assoc()) {
    $followups[] = $row;
}
$stmt->close();

$followupsJson = json_encode($followups, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$leadsJson = json_encode($leadsForDropdown, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Follow Ups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php include('partials/head.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>

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
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
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
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn-soft{
            border:none;
            border-radius:10px;
            padding:10px 16px;
            color:#fff;
            font-weight:800;
            font-size:14px;
            box-shadow:0 8px 18px rgba(0,0,0,0.08);
            display:inline-flex;
            align-items:center;
            gap:8px;
            text-decoration:none;
        }

        .btn-add{ background:linear-gradient(135deg,#d8a500,#b8860b); color:#111; }
        .btn-export{ background:#5c4fd6; }
        .btn-calendar{ background:#111827; }

        .stats-grid{
            display:grid;
            grid-template-columns:repeat(5, 1fr);
            gap:14px;
            margin-bottom:18px;
        }

        .stat-card{
            border-radius:14px;
            padding:18px 17px;
            min-height:105px;
            color:#fff;
            position:relative;
            overflow:hidden;
            box-shadow:0 14px 28px rgba(0,0,0,.12);
        }

        .stat-card::after{
            content:'';
            position:absolute;
            width:116px;
            height:116px;
            border-radius:50%;
            right:-35px;
            top:-30px;
            background:rgba(255,255,255,.12);
        }

        .stat-header{
            display:flex;
            justify-content:space-between;
            gap:12px;
            position:relative;
            z-index:1;
        }

        .stat-label{
            font-size:12px;
            font-weight:800;
            opacity:.92;
            line-height:1.3;
        }

        .stat-icon{
            width:38px;
            height:38px;
            border-radius:12px;
            display:grid;
            place-items:center;
            background:rgba(255,255,255,.18);
        }

        .stat-value{
            position:relative;
            z-index:1;
            font-size:30px;
            font-weight:900;
            line-height:1;
            margin-top:16px;
        }

        .stat-sub{
            position:relative;
            z-index:1;
            font-size:11.5px;
            opacity:.86;
            margin-top:5px;
        }

        .stat-red{ background:linear-gradient(135deg,#9f2430,#c94b57); }
        .stat-gold{ background:linear-gradient(135deg,#b27b16,#d8a33a); }
        .stat-orange{ background:linear-gradient(135deg,#b87a16,#dc9b2d); }
        .stat-green{ background:linear-gradient(135deg,#386c58,#4c9075); }
        .stat-dark{ background:linear-gradient(135deg,#111c44,#1f2d64); }

        .filter-card,
        .premium-table-card,
        .flow-card{
            background:#fff;
            border-radius:16px;
            box-shadow:0 4px 20px rgba(15,23,42,.06);
            border:1px solid rgba(226,232,240,.85);
        }

        .filter-card{
            padding:14px;
            margin-bottom:18px;
        }

        .filter-row{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }

        .filter-group{
            position:relative;
            flex:1;
            min-width:145px;
        }

        .filter-group .material-symbols-outlined{
            position:absolute;
            left:12px;
            top:50%;
            transform:translateY(-50%);
            color:#a3afcf;
            font-size:17px;
            pointer-events:none;
        }

        .filter-input,
        .filter-select{
            width:100%;
            height:42px;
            background:#f4f7fe;
            border:1px solid #e0e5f2;
            border-radius:10px;
            padding:0 12px 0 39px;
            color:#2b3674;
            font-size:13px;
            font-weight:700;
            transition:all .22s ease;
        }

        .filter-input:focus,
        .filter-select:focus{
            border-color:rgba(212,160,23,.55);
            background:#fff;
            box-shadow:0 0 0 4px rgba(212,160,23,.11);
            outline:none;
        }

        .filter-select{
            appearance:none;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='13' height='13' viewBox='0 0 24 24' fill='%239399b0'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
            background-repeat:no-repeat;
            background-position:right 12px center;
            padding-right:32px;
        }

        .btn-filter,
        .btn-reset{
            height:42px;
            border:none;
            border-radius:10px;
            padding:0 18px;
            color:#fff;
            font-size:13px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            white-space:nowrap;
            text-decoration:none;
        }

        .btn-filter{ background:linear-gradient(180deg,#f5c742,#d8a500); color:#111; }
        .btn-reset{ background:linear-gradient(180deg,#4f9e4a,#3e7a3a); }

        .flow-grid{
            display:grid;
            grid-template-columns:1fr 1fr 1fr;
            gap:14px;
            margin:18px 0;
        }

        .flow-card{
            padding:17px;
            position:relative;
            overflow:hidden;
        }

        .flow-card::after{
            content:'';
            position:absolute;
            width:84px;
            height:84px;
            border-radius:50%;
            right:-28px;
            top:-28px;
            background:rgba(212,160,23,.10);
        }

        .flow-card h3{
            font-size:14px;
            font-weight:900;
            color:#172033;
            margin-bottom:9px;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .flow-card h3 .material-symbols-outlined{
            color:#b8860b;
            font-size:19px;
        }

        .flow-card p{
            color:#64748b;
            margin:0;
            font-size:13px;
            line-height:1.55;
        }

        .premium-table-card{
            padding:20px;
            overflow:hidden;
        }

        .table-header-flex{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:15px;
        }

        .table-title{
            font-size:18px;
            font-weight:900;
            color:#1a202c;
            margin:0;
            display:flex;
            align-items:center;
            gap:9px;
        }

        .table-title .material-symbols-outlined{
            color:#b8860b;
        }

        .mini-btn{
            height:34px;
            padding:0 12px;
            border:none;
            border-radius:9px;
            font-size:12px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:6px;
            text-decoration:none;
        }

        .mini-dark{ background:#111c44; color:#fff; }
        .mini-danger{ background:#ef4444; color:#fff; }

        .table-responsive-custom{
            overflow-x:auto;
            border-radius:12px;
        }

        .follow-table{
            width:100%;
            border-collapse:collapse;
            min-width:1180px;
        }

        .follow-table thead th{
            background:#f8fafc;
            color:#64748b;
            font-size:12px;
            font-weight:900;
            padding:14px 12px;
            text-align:left;
            border-bottom:2px solid #edf2f7;
            white-space:nowrap;
        }

        .follow-table tbody td{
            padding:14px 12px;
            border-bottom:1px solid #f1f5f9;
            color:#334155;
            font-size:13.5px;
            vertical-align:middle;
        }

        .follow-table tbody tr:hover td{
            background:#fbfcfe;
        }

        .lead-name{
            display:flex;
            align-items:center;
            gap:10px;
            font-weight:900;
            color:#172033;
        }

        .lead-avatar{
            width:32px;
            height:32px;
            border-radius:10px;
            display:grid;
            place-items:center;
            color:#fff;
            font-size:11.5px;
            font-weight:900;
            flex-shrink:0;
            background:linear-gradient(135deg,#9f2430,#c94b57);
        }

        .sub-text{
            display:block;
            color:#8b95a7;
            font-size:11.5px;
            margin-top:2px;
            font-weight:600;
        }

        .agent-cell{
            display:flex;
            align-items:center;
            gap:8px;
            font-weight:800;
            color:#334155;
            white-space:nowrap;
        }

        .agent-dot{
            width:8px;
            height:8px;
            border-radius:50%;
            background:#d4a017;
            box-shadow:0 0 0 4px rgba(212,160,23,.12);
        }

        .type-chip,
        .status-badge{
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:5px 10px;
            border-radius:999px;
            font-size:11.5px;
            font-weight:900;
            white-space:nowrap;
        }

        .type-call{ background:#dbeafe; color:#1e40af; }
        .type-wa{ background:#dcfce7; color:#14532d; }
        .type-meeting{ background:#fef3c7; color:#92400e; }
        .type-visit{ background:#ffedd5; color:#9a3412; }
        .type-video{ background:#ede9fe; color:#5b21b6; }

        .status-pending{ background:#fef3c7; color:#92400e; }
        .status-done{ background:#dcfce7; color:#14532d; }
        .status-missed{ background:#fee2e2; color:#7f1d1d; }

        .response-cell{
            max-width:220px;
            line-height:1.45;
            font-weight:700;
        }

        .notes-cell{
            max-width:260px;
            color:#64748b;
            line-height:1.45;
        }

        .actions{
            display:flex;
            align-items:center;
            gap:5px;
        }

        .action-icon{
            width:31px;
            height:31px;
            border:none;
            border-radius:8px;
            display:grid;
            place-items:center;
            background:transparent;
            transition:all .22s ease;
        }

        .action-view{ color:#3b82f6; }
        .action-edit{ color:#b8860b; }
        .action-next{ color:#15803d; }
        .action-delete{ color:#ef4444; }
        .action-view:hover{ background:#dbeafe; }
        .action-edit:hover{ background:#fff7db; }
        .action-next:hover{ background:#dcfce7; }
        .action-delete:hover{ background:#fee2e2; }

        .modal-overlay{
            display:none;
            position:fixed;
            inset:0;
            z-index:9999;
            background:rgba(0,0,0,.58);
            backdrop-filter:blur(4px);
            align-items:center;
            justify-content:center;
            padding:24px;
            overflow:auto;
        }

        .modal-overlay.open{
            display:flex;
        }

        .modal-box{
            width:100%;
            max-width:920px;
            background:#fff;
            border-radius:18px;
            box-shadow:0 30px 80px rgba(0,0,0,.28);
            overflow:hidden;
        }

        .modal-header-custom{
            background:linear-gradient(135deg,#111 0%,#1A1A1A 100%);
            padding:18px 24px;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }

        .mh-left{
            display:flex;
            align-items:center;
            gap:12px;
        }

        .mh-icon{
            width:40px;
            height:40px;
            border-radius:11px;
            background:linear-gradient(135deg,#f0c040,#b8860b);
            display:grid;
            place-items:center;
            color:#000;
        }

        .mh-title{
            color:#fff;
            font-size:16px;
            font-weight:900;
        }

        .mh-sub{
            color:#aaa;
            font-size:11.5px;
            margin-top:2px;
        }

        .modal-close{
            width:35px;
            height:35px;
            border-radius:10px;
            background:rgba(255,255,255,.08);
            color:#aaa;
            display:grid;
            place-items:center;
            border:1px solid rgba(255,255,255,.1);
        }

        .modal-close:hover{
            color:#ef4444;
            background:rgba(239,68,68,.17);
        }

        .modal-body-custom{
            padding:22px 24px;
        }

        .form-section-label{
            font-size:11px;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.8px;
            color:#b8860b;
            margin-bottom:12px;
            display:flex;
            align-items:center;
            gap:7px;
        }

        .form-section-label::after{
            content:'';
            flex:1;
            height:1px;
            background:linear-gradient(90deg,rgba(212,160,23,.35),transparent);
        }

        .form-label-custom{
            font-size:12px;
            font-weight:800;
            color:#374151;
            margin-bottom:6px;
            display:block;
        }

        .form-label-custom .req{
            color:#ef4444;
        }

        .form-control-custom{
            width:100%;
            border:1.5px solid #e5e7eb;
            border-radius:10px;
            padding:9px 12px;
            font-size:13px;
            color:#172033;
            background:#fafafa;
            transition:all .22s ease;
        }

        .form-control-custom:focus{
            border-color:#d4a017;
            background:#fff;
            box-shadow:0 0 0 4px rgba(212,160,23,.12);
            outline:none;
        }

        textarea.form-control-custom{
            min-height:92px;
            resize:vertical;
        }

        .quick-type-row{
            display:flex;
            flex-wrap:wrap;
            gap:9px;
        }

        .quick-type{
            display:flex;
            align-items:center;
            gap:7px;
            padding:9px 13px;
            border:1.5px solid #e5e7eb;
            border-radius:10px;
            font-size:12.5px;
            font-weight:800;
            cursor:pointer;
            transition:all .22s ease;
            user-select:none;
        }

        .quick-type:hover{
            border-color:#d4a017;
            color:#b8860b;
            background:#fffaf0;
        }

        .quick-type input{
            accent-color:#d4a017;
        }

        .modal-footer-custom{
            background:#fafafa;
            border-top:1px solid #e5e7eb;
            padding:16px 24px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .footer-hint{
            color:#64748b;
            font-size:12px;
            display:flex;
            align-items:center;
            gap:6px;
        }

        .btn-modal-reset,
        .btn-modal-save{
            height:40px;
            border:none;
            border-radius:10px;
            padding:0 18px;
            font-size:13px;
            font-weight:900;
        }

        .btn-modal-reset{
            background:#f3f4f6;
            color:#6b7280;
            border:1.5px solid #e5e7eb;
        }

        .btn-modal-save{
            background:linear-gradient(135deg,#f0c040,#b8860b);
            color:#000;
            display:inline-flex;
            align-items:center;
            gap:8px;
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

        @media (max-width:1280px){
            .stats-grid{ grid-template-columns:repeat(3,1fr); }
            .flow-grid{ grid-template-columns:1fr; }
        }

        @media (max-width:768px){
            .stats-grid{ grid-template-columns:1fr; }
            .filter-row{ flex-direction:column; align-items:stretch; }
            .filter-group,
            .btn-filter,
            .btn-reset{ width:100%; }
            .sidebar{ width:240px; }
        }
    

      /* =====================================================
   FOLLOW-UPS.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
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
    font-family:"Inter","Segoe UI",Tahoma,GeneVA,Verdana,sans-serif !important;
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

/* ================= TOPBAR / PAGE BG ================= */
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

.page-subtitle,
.sub-text,
.text-muted{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.header-actions{
    gap:10px !important;
}

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

.btn-export,
.btn-calendar{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-soft:hover{
    transform:translateY(-1px);
    filter:saturate(1.06);
    color:#fff !important;
}

/* ================= STATS CARDS ================= */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(5,1fr) !important;
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

.stat-red{background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.stat-gold{background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.stat-orange{background:linear-gradient(135deg,#d97706,#f97316) !important;}
.stat-green{background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-dark{background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}

.stat-label{
    font-size:14px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
}

.stat-icon{
    background:rgba(255,255,255,.16) !important;
    border:1px solid rgba(255,255,255,.12) !important;
}

.stat-value{
    font-size:30px !important;
    font-weight:900 !important;
    color:#fff !important;
}

.stat-sub{
    color:rgba(255,255,255,.82) !important;
    font-weight:700 !important;
}

/* ================= GLASS PANELS ================= */
.filter-card,
.premium-table-card,
.flow-card{
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
.premium-table-card:hover,
.flow-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-card{
    padding:14px !important;
    margin-bottom:18px !important;
}

.flow-card{
    padding:17px !important;
}

.flow-card h3,
.table-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.flow-card h3 .material-symbols-outlined,
.table-title .material-symbols-outlined{
    color:#a78bfa !important;
}

.flow-card p,
.notes-cell{
    color:#94a3b8 !important;
}

.premium-table-card{
    padding:20px !important;
    overflow:hidden !important;
}

/* ================= FILTER INPUTS ================= */
.filter-row{
    gap:10px !important;
}

.filter-group .material-symbols-outlined{
    color:#94a3b8 !important;
}

.filter-input,
.filter-select{
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

.filter-input::placeholder{
    color:#94a3b8 !important;
}

.filter-input:focus,
.filter-select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.btn-filter,
.btn-reset,
.mini-btn{
    min-height:42px !important;
    border:none !important;
    border-radius:12px !important;
    padding:0 16px !important;
    font-size:13px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:7px !important;
    transition:.22s ease !important;
    text-decoration:none !important;
}

.btn-filter,
.mini-dark{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-reset{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.mini-danger{
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#fff !important;
}

.btn-filter:hover,
.btn-reset:hover,
.mini-btn:hover{
    transform:translateY(-1px);
    color:#fff !important;
}

/* ================= TABLE DARK GLASS ================= */
.table-responsive-custom,
.follow-table,
.follow-table tbody,
.follow-table tbody tr{
    background:transparent !important;
}

.follow-table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

.follow-table thead th{
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

.follow-table tbody td{
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

.follow-table tbody tr:hover,
.follow-table tbody tr:hover td,
.follow-table tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

.follow-table tbody tr:focus,
.follow-table tbody tr:active,
.follow-table tbody td:focus,
.follow-table tbody td:active,
.follow-table tbody td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

.follow-table tbody td.text-center,
.follow-table tbody td.py-4,
.follow-table tbody td[colspan]{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

.follow-table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

.lead-name,
.agent-cell{
    color:#f8fafc !important;
}

.lead-avatar{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    box-shadow:0 10px 22px rgba(109,76,255,.24) !important;
}

.agent-dot{
    background:#a78bfa !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
}

/* ================= BADGES ================= */
.type-chip,
.status-badge{
    border:1px solid rgba(148,163,184,.18) !important;
}

.type-call,
.type-video{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border-color:rgba(59,130,246,.24) !important;
}

.type-wa,
.status-done{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

.type-meeting,
.type-visit,
.status-pending{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.status-missed{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

.response-cell{
    color:#e5e7eb !important;
}

/* ================= ACTION BUTTONS ================= */
.action-icon{
    width:34px !important;
    height:34px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.62) !important;
    transition:.22s ease !important;
}

.action-view{color:#60a5fa !important;}
.action-edit{color:#fbbf24 !important;}
.action-next{color:#4ade80 !important;}
.action-delete{color:#f87171 !important;}

.action-icon:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.20) !important;
    color:#fff !important;
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
.table-responsive-custom::-webkit-scrollbar,
.modal-body-custom::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-responsive-custom::-webkit-scrollbar-track,
.modal-body-custom::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-responsive-custom::-webkit-scrollbar-thumb,
.modal-body-custom::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-responsive-custom::-webkit-scrollbar-thumb:hover,
.modal-body-custom::-webkit-scrollbar-thumb:hover,
.content-wrapper::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}



/* =====================================================
   FOLLOW-UP POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

.modal-overlay{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:99999 !important;
}

.modal-box{
    max-width:760px !important;
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

.modal-header-custom{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

.mh-icon{
    width:36px !important;
    height:36px !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.mh-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
}

.mh-sub{
    color:#94a3b8 !important;
    font-size:11.5px !important;
    font-weight:700 !important;
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

.modal-body-custom{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

.form-section-label{
    color:#c4b5fd !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:12px !important;
}

.form-section-label::after{
    background:linear-gradient(90deg,rgba(124,58,237,.42),transparent) !important;
}

.form-label-custom{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

.form-control-custom{
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

textarea.form-control-custom{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

.form-control-custom[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

.form-control-custom::placeholder{
    color:#94a3b8 !important;
}

.form-control-custom:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

select.form-control-custom option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.quick-type-row{
    gap:9px !important;
}

.quick-type{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:9px 13px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.quick-type i{
    color:#a78bfa !important;
}

.quick-type:hover,
.quick-type:has(input:checked){
    background:rgba(109,76,255,.16) !important;
    border-color:rgba(139,92,246,.42) !important;
    color:#ffffff !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.12) !important;
}

.quick-type input{
    accent-color:#7c3aed !important;
}

.modal-footer-custom{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
}

.footer-hint{
    color:#94a3b8 !important;
    font-size:11.5px !important;
    font-weight:700 !important;
}

.btn-modal-reset,
.btn-modal-save{
    min-height:38px !important;
    height:38px !important;
    border:none !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:900 !important;
    padding:7px 14px !important;
}

.btn-modal-reset{
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
}

.btn-modal-save{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-modal-reset:hover,
.btn-modal-save:hover{
    transform:translateY(-1px);
}

/* Bootstrap View Modal */
#viewFollowupModal .modal-content{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
}

#viewFollowupModal .modal-header{
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    min-height:58px !important;
}

#viewFollowupModal .modal-title{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
}

#viewFollowupModal .modal-title::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
}

#viewFollowupModal .modal-body{
    background:transparent !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

#viewFollowupModal .btn-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    opacity:1 !important;
    background-image:none !important;
    box-shadow:none !important;
    padding:0 !important;
    filter:none !important;
}

#viewFollowupModal .btn-close::before,
#viewFollowupModal .btn-close::after{
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

#viewFollowupModal .btn-close::before{
    transform:translate(-50%,-50%) rotate(45deg) !important;
}

#viewFollowupModal .btn-close::after{
    transform:translate(-50%,-50%) rotate(-45deg) !important;
}

#viewFollowupModal .btn-close:hover{
    background:#ef4444 !important;
}

#viewFollowupModal .view-box,
.view-box{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

#viewFollowupModal .view-label,
.view-label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

#viewFollowupModal .view-value,
.view-value{
    color:#f8fafc !important;
    font-weight:900 !important;
}

@media(max-width:1280px){
    .stats-grid{
        grid-template-columns:repeat(3,1fr) !important;
    }

    .flow-grid{
        grid-template-columns:1fr !important;
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
    .stats-grid{
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

    .filter-row{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .filter-group,
    .btn-filter,
    .btn-reset,
    .mini-btn{
        width:100% !important;
    }

    .table-header-flex{
        align-items:stretch !important;
        flex-direction:column !important;
    }

    .table-actions{
        display:flex !important;
        flex-direction:column !important;
        gap:8px !important;
        width:100% !important;
    }

    .modal-box,
    #viewFollowupModal .modal-dialog{
        width:96vw !important;
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    .modal-footer-custom{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .modal-footer-custom > div:last-child{
        width:100% !important;
        flex-direction:column !important;
    }

    .btn-modal-reset,
    .btn-modal-save{
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
                    <h1 class="page-title">Follow-up Activity</h1>
                    <div class="page-subtitle">Agent calls, WhatsApp, meetings, site visits and next follow-up tracking</div>
                </div>

                <div class="header-actions">
                    <a class="btn-soft btn-export" href="follow-ups.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
                        <i class="bi bi-download"></i> Export
                    </a>

                    <a class="btn-soft btn-calendar" href="follow-ups.php?date=<?= e(date('Y-m-d')) ?>">
                        <i class="bi bi-calendar-month"></i> Today
                    </a>

                    <button class="btn-soft btn-add" type="button" onclick="openFollowupModal()">
                        <i class="bi bi-telephone-plus"></i> Add Follow-up
                    </button>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card stat-red">
                    <div class="stat-header">
                        <span class="stat-label">Today Follow-ups</span>
                        <div class="stat-icon"><span class="material-symbols-outlined">today</span></div>
                    </div>
                    <div class="stat-value"><?= (int)$todayFollowups ?></div>
                    <div class="stat-sub">Scheduled today</div>
                </div>

                <div class="stat-card stat-gold">
                    <div class="stat-header">
                        <span class="stat-label">Pending</span>
                        <div class="stat-icon"><span class="material-symbols-outlined">schedule</span></div>
                    </div>
                    <div class="stat-value"><?= (int)$pendingFollowups ?></div>
                    <div class="stat-sub">Need response</div>
                </div>

                <div class="stat-card stat-orange">
                    <div class="stat-header">
                        <span class="stat-label">Site Visits</span>
                        <div class="stat-icon"><span class="material-symbols-outlined">location_on</span></div>
                    </div>
                    <div class="stat-value"><?= (int)$siteVisitFollowups ?></div>
                    <div class="stat-sub">Recent visits</div>
                </div>

                <div class="stat-card stat-green">
                    <div class="stat-header">
                        <span class="stat-label">Completed</span>
                        <div class="stat-icon"><span class="material-symbols-outlined">task_alt</span></div>
                    </div>
                    <div class="stat-value"><?= (int)$completedFollowups ?></div>
                    <div class="stat-sub">Done follow-ups</div>
                </div>

                <div class="stat-card stat-dark">
                    <div class="stat-header">
                        <span class="stat-label">Interested Leads</span>
                        <div class="stat-icon"><span class="material-symbols-outlined">favorite</span></div>
                    </div>
                    <div class="stat-value"><?= (int)$interestedFollowups ?></div>
                    <div class="stat-sub">Ready for next step</div>
                </div>
            </div>

            <form class="filter-card" method="GET" action="follow-ups.php">
                <div class="filter-row">
                    <div class="filter-group" style="flex:1.5; min-width:220px;">
                        <span class="material-symbols-outlined">search</span>
                        <input class="filter-input" type="text" name="search" placeholder="Search lead, phone, agent or notes..." value="<?= e($search) ?>">
                    </div>

                    <div class="filter-group">
                        <span class="material-symbols-outlined">support_agent</span>
                        <select class="filter-select" name="agent_id">
                            <option value="">All Agents</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?= (int)$agent['id'] ?>" <?= $agentFilter === (int)$agent['id'] ? 'selected' : '' ?>>
                                    <?= e($agent['name']) ?><?= !empty($agent['role_name']) ? ' - ' . e($agent['role_name']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="material-symbols-outlined">call</span>
                        <select class="filter-select" name="followup_type">
                            <option value="">All Types</option>
                            <?php foreach ($followupTypes as $type): ?>
                                <option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="material-symbols-outlined">tune</span>
                        <select class="filter-select" name="status">
                            <option value="">All Status</option>
                            <?php foreach ($followupStatuses as $st): ?>
                                <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="material-symbols-outlined">calendar_today</span>
                        <input class="filter-input" type="date" name="date" value="<?= e($dateFilter) ?>">
                    </div>

                    <button class="btn-filter" type="submit">
                        <span class="material-symbols-outlined">filter_list</span> Filter
                    </button>

                    <a class="btn-reset" href="follow-ups.php">
                        <span class="material-symbols-outlined">refresh</span> Reset
                    </a>
                </div>
            </form>

            <div class="flow-grid">
                <div class="flow-card">
                    <h3><span class="material-symbols-outlined">phone_in_talk</span>Example Activity</h3>
                    <p><strong>Agent Ali</strong> ne lead ko call ki aur customer ne project details maangi.</p>
                </div>
                <div class="flow-card">
                    <h3><span class="material-symbols-outlined">sentiment_satisfied</span>Response</h3>
                    <p>Customer <strong>interested</strong> hai, budget confirm karna chahta hai.</p>
                </div>
                <div class="flow-card">
                    <h3><span class="material-symbols-outlined">event_upcoming</span>Next Follow-up</h3>
                    <p>Next follow-up <strong>kal 3:00 PM</strong> schedule hoga aur record <strong>re_followups</strong> me save hoga.</p>
                </div>
            </div>

            <div class="premium-table-card">
                <form method="POST" id="bulkDeleteForm">
                    <input type="hidden" name="action" value="bulk_delete">

                    <div class="table-header-flex">
                        <h2 class="table-title"><span class="material-symbols-outlined">history</span>Follow-up Activity Track</h2>
                        <div class="table-actions">
                            <button type="button" class="mini-btn mini-danger" onclick="deleteSelected(event)">
                                <span class="material-symbols-outlined">delete</span> Delete Selected
                            </button>
                            <a class="mini-btn mini-dark" href="follow-ups.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
                                <span class="material-symbols-outlined">file_download</span> CSV
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive-custom">
                        <table class="follow-table">
                            <thead>
                                <tr>
                                    <th style="width:50px;"><input type="checkbox" id="checkAll"></th>
                                    <th>Lead Name</th>
                                    <th>Agent</th>
                                    <th>Follow-up Type</th>
                                    <th>Follow-up Date/Time</th>
                                    <th>Status</th>
                                    <th>Response</th>
                                    <th>Notes</th>
                                    <th style="width:150px;">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($followups)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">No follow-ups found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($followups as $f): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="rowCheck" name="selected_ids[]" value="<?= (int)$f['id'] ?>">
                                            </td>

                                            <td>
                                                <div class="lead-name">
                                                    <div class="lead-avatar"><?= e(leadInitials($f['lead_name'] ?? '')) ?></div>
                                                    <div>
                                                        <?= e($f['lead_name'] ?: 'Unknown Lead') ?>
                                                        <span class="sub-text"><?= e($f['lead_phone'] ?? '') ?></span>
                                                    </div>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="agent-cell">
                                                    <span class="agent-dot"></span>
                                                    <?= e($f['agent_name'] ?: 'Unassigned') ?>
                                                </div>
                                            </td>

                                            <td>
                                                <span class="type-chip <?= e(typeBadgeClass($f['followup_type'] ?? '')) ?>">
                                                    <i class="bi <?= e(typeIcon($f['followup_type'] ?? '')) ?>"></i>
                                                    <?= e($f['followup_type'] ?? '') ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?php if (!empty($f['followup_at_value'])): ?>
                                                    <strong><?= e(date('d M Y', strtotime($f['followup_at_value']))) ?></strong>
                                                    <span class="sub-text"><?= e(date('h:i A', strtotime($f['followup_at_value']))) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span class="status-badge <?= e(statusBadgeClass($f['status'] ?? '')) ?>">
                                                    <?= e($f['status'] ?? '') ?>
                                                </span>
                                            </td>

                                            <td class="response-cell">
                                                <?= e($f['response_value'] ?? '') ?>
                                            </td>

                                            <td class="notes-cell">
                                                <?= e($f['notes'] ?? '') ?>
                                            </td>

                                            <td>
                                                <div class="actions">
                                                    <button type="button" class="action-icon action-view" title="View" onclick="viewFollowup(<?= (int)$f['id'] ?>)">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>

                                                    <button type="button" class="action-icon action-edit" title="Edit" onclick="editFollowup(<?= (int)$f['id'] ?>)">
                                                        <span class="material-symbols-outlined">edit</span>
                                                    </button>

                                                    <button type="button" class="action-icon action-next" title="Create Next" onclick="nextFollowup(<?= (int)$f['id'] ?>)">
                                                        <span class="material-symbols-outlined">add_call</span>
                                                    </button>

                                                    <button type="button" class="action-icon action-delete" title="Delete" onclick="deleteFollowup(<?= (int)$f['id'] ?>)">
                                                        <span class="material-symbols-outlined">delete</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>


<!-- ADD / EDIT MODAL -->
<div class="modal-overlay" id="followupModal">
    <div class="modal-box">
        <div class="modal-header-custom">
            <div class="mh-left">
                <div class="mh-icon">
                    <span class="material-symbols-outlined">add_call</span>
                </div>
                <div>
                    <div class="mh-title" id="followupModalTitle">Add Follow-up Activity</div>
                    <div class="mh-sub">Ye record re_followups table me save hoga</div>
                </div>
            </div>

            <button type="button" class="modal-close" onclick="closeFollowupModal()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form method="POST" id="followupForm">
            <input type="hidden" name="action" id="formAction" value="add_followup">
            <input type="hidden" name="id" id="followup_id">

            <div class="modal-body-custom">
                <div class="form-section-label">
                    <span class="material-symbols-outlined">person_search</span> Lead & Agent
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label-custom">Lead Name <span class="req">*</span></label>
                        <select class="form-control-custom" name="lead_id" id="lead_id" required onchange="autoSelectLeadAgent()">
                            <option value="">Select Lead</option>
                            <?php foreach ($leadsForDropdown as $lead): ?>
                                <option value="<?= (int)$lead['id'] ?>">
                                    <?= e($lead['lead_name']) ?><?= !empty($lead['phone']) ? ' - ' . e($lead['phone']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-custom">Agent <span class="req">*</span></label>
                        <select class="form-control-custom" name="agent_id" id="agent_id" required>
                            <option value="">Select Agent</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?= (int)$agent['id'] ?>">
                                    <?= e($agent['name']) ?><?= !empty($agent['role_name']) ? ' - ' . e($agent['role_name']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (empty($agents)): ?>
                            <small style="display:block;margin-top:6px;color:#b8860b;font-weight:800;">
                                No active staff/agent found. Staff user ka role_id = 2 aur status = active hona chahiye.
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-section-label">
                    <span class="material-symbols-outlined">rule</span> Follow-up Details
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label-custom">Follow-up Type <span class="req">*</span></label>

                        <div class="quick-type-row">
                            <?php foreach ($followupTypes as $type): ?>
                                <label class="quick-type">
                                    <input type="radio" name="followup_type" value="<?= e($type) ?>" <?= $type === 'Phone Call' ? 'required' : '' ?>>
                                    <i class="bi <?= e(typeIcon($type)) ?>"></i>
                                    <?= e($type) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-custom">Follow-up Date/Time <span class="req">*</span></label>
                        <input class="form-control-custom" type="datetime-local" name="followup_datetime" id="followup_datetime" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-custom">Status <span class="req">*</span></label>
                        <select class="form-control-custom" name="status" id="status" required>
                            <?php foreach ($followupStatuses as $st): ?>
                                <option value="<?= e($st) ?>"><?= e($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-section-label">
                    <span class="material-symbols-outlined">chat</span> Response & Notes
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-custom">Response Status</label>
                        <select class="form-control-custom" name="response_status" id="response_status">
                            <?php foreach ($responseStatuses as $rs): ?>
                                <option value="<?= e($rs) ?>"><?= e($rs) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-custom">Response Text</label>
                        <input class="form-control-custom" type="text" name="response" id="response" placeholder="Customer interested hai / No response / Call back later">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-custom">Next Follow-up Date/Time</label>
                        <input class="form-control-custom" type="datetime-local" name="next_followup_at" id="next_followup_at">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-custom">Quick Notes Example</label>
                        <input class="form-control-custom" type="text" value="Customer interested hai, next follow-up kal 3 PM" readonly>
                    </div>

                    <div class="col-12">
                        <label class="form-label-custom">Notes</label>
                        <textarea class="form-control-custom" name="notes" id="notes" placeholder="Example: Customer interested hai, next follow-up kal 3 PM..."></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer-custom">
                <div class="footer-hint">
                    <i class="bi bi-database-check"></i>
                    Save fields: lead_id, agent_id, followup_type, followup_at, status, response_status, notes
                </div>

                <div style="display:flex; gap:10px;">
                    <button type="reset" class="btn-modal-reset">Reset</button>
                    <button type="submit" class="btn-modal-save">
                        <span class="material-symbols-outlined">save</span> Save Follow-up
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>


<!-- VIEW MODAL -->
<div class="modal fade" id="viewFollowupModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#111827;color:#fff;">
                <h5 class="modal-title">Follow-up Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="viewFollowupBody"></div>
        </div>
    </div>
</div>


<!-- DELETE FORM -->
<form method="POST" id="deleteFollowupForm" style="display:none;">
    <input type="hidden" name="action" value="delete_followup">
    <input type="hidden" name="id" id="deleteFollowupId">
</form>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const followups = <?= $followupsJson ?: '[]' ?>;
const leadsList = <?= $leadsJson ?: '[]' ?>;

const viewFollowupModal = new bootstrap.Modal(document.getElementById('viewFollowupModal'));

function byId(id) {
    return document.getElementById(id);
}

function openFollowupModal() {
    byId('followupForm').reset();
    byId('formAction').value = 'add_followup';
    byId('followup_id').value = '';
    byId('followupModalTitle').innerText = 'Add Follow-up Activity';
    byId('status').value = 'Pending';
    byId('response_status').value = 'No Response';

    const phoneRadio = document.querySelector('input[name="followup_type"][value="Phone Call"]');
    if (phoneRadio) phoneRadio.checked = true;

    byId('followupModal').classList.add('open');
}

function closeFollowupModal() {
    byId('followupModal').classList.remove('open');
}

function autoSelectLeadAgent() {
    const leadId = parseInt(byId('lead_id').value || 0);
    const lead = leadsList.find(l => parseInt(l.id) === leadId);

    if (lead && lead.assigned_agent_id) {
        byId('agent_id').value = lead.assigned_agent_id;
    }
}

function editFollowup(id) {
    const row = followups.find(f => parseInt(f.id) === parseInt(id));

    if (!row) {
        alert('Follow-up not found.');
        return;
    }

    byId('followupForm').reset();
    byId('formAction').value = 'update_followup';
    byId('followup_id').value = row.id || '';
    byId('followupModalTitle').innerText = 'Edit Follow-up Activity';

    byId('lead_id').value = row.lead_id || '';
    byId('agent_id').value = row.agent_id || '';
    byId('status').value = row.status || 'Pending';
    byId('response_status').value = row.response_value || 'No Response';
    byId('response').value = row.response || '';
    byId('notes').value = row.notes || '';

    const radio = document.querySelector('input[name="followup_type"][value="' + cssEscape(row.followup_type || 'Phone Call') + '"]');
    if (radio) radio.checked = true;

    if (row.followup_at_value) {
        byId('followup_datetime').value = String(row.followup_at_value).replace(' ', 'T').substring(0, 16);
    }

    byId('followupModal').classList.add('open');
}

function nextFollowup(id) {
    const row = followups.find(f => parseInt(f.id) === parseInt(id));

    openFollowupModal();

    if (!row) return;

    byId('lead_id').value = row.lead_id || '';
    byId('agent_id').value = row.agent_id || '';
    byId('status').value = 'Pending';
    byId('response_status').value = 'No Response';
    byId('notes').value = 'Previous follow-up: ' + (row.notes || '');

    const phoneRadio = document.querySelector('input[name="followup_type"][value="Phone Call"]');
    if (phoneRadio) phoneRadio.checked = true;
}

function viewFollowup(id) {
    const row = followups.find(f => parseInt(f.id) === parseInt(id));

    if (!row) {
        alert('Follow-up not found.');
        return;
    }

    const dt = row.followup_at_value
        ? new Date(String(row.followup_at_value).replace(' ', 'T'))
        : null;

    byId('viewFollowupBody').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Lead Name</div>
                    <div class="view-value">${escapeHtml(row.lead_name || '-')}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="view-box">
                    <div class="view-label">Agent</div>
                    <div class="view-value">${escapeHtml(row.agent_name || 'Unassigned')}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="view-box">
                    <div class="view-label">Follow-up Type</div>
                    <div class="view-value">${escapeHtml(row.followup_type || '-')}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="view-box">
                    <div class="view-label">Status</div>
                    <div class="view-value">${escapeHtml(row.status || '-')}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="view-box">
                    <div class="view-label">Response</div>
                    <div class="view-value">${escapeHtml(row.response_value || '-')}</div>
                </div>
            </div>
            <div class="col-md-12">
                <div class="view-box">
                    <div class="view-label">Follow-up Date/Time</div>
                    <div class="view-value">${dt ? escapeHtml(dt.toLocaleString()) : '-'}</div>
                </div>
            </div>
            <div class="col-md-12">
                <div class="view-box">
                    <div class="view-label">Notes</div>
                    <div class="view-value">${escapeHtml(row.notes || '-')}</div>
                </div>
            </div>
        </div>
    `;

    viewFollowupModal.show();
}

function deleteFollowup(id) {
    if (!confirm('Delete this follow-up?')) return;

    byId('deleteFollowupId').value = id;
    byId('deleteFollowupForm').submit();
}

function deleteSelected(e) {
    if (e) e.preventDefault();

    const checked = document.querySelectorAll('.rowCheck:checked');

    if (!checked.length) {
        alert('Please select at least one follow-up.');
        return;
    }

    if (!confirm('Delete selected follow-ups?')) return;

    byId('bulkDeleteForm').submit();
}

byId('checkAll')?.addEventListener('change', function () {
    document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = this.checked);
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeFollowupModal();
});

byId('followupModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeFollowupModal();
});

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function cssEscape(value) {
    if (window.CSS && CSS.escape) return CSS.escape(value);
    return String(value).replace(/"/g, '\\"');
}
</script>

</body>
</html>
