<?php
/*
|--------------------------------------------------------------------------
| agent-assignment.php
| ONE ERP - Real Estate Agent Assignment
|--------------------------------------------------------------------------
| Path: C:\xampp\htdocs\pos\public\app\views\agent-assignment.php
|
| Logic:
| - Add New Agent => users table me role_id = 2 + status active
| - Single Assign => re_leads.assigned_agent_id update
| - Bulk Assign => selected leads assigned to selected agent
| - Audit Trail => re_agent_assignments table me history
| - All agents dropdowns DB users table se aate hain
*/

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// if (!isset($_SESSION['user_id'])) {
//     die('Login required');
// }
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['current_branch_id'] ?? 0));
$role_id   = (int)($_SESSION['role_id'] ?? 0);

if ($client_id <= 0) {
    die('Invalid client session');
}

/* ===================== HELPERS ===================== */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count > 0;
}

function redirectMsg(string $type, string $message): void {
    header('Location: agent-assignment.php?msg_type=' . urlencode($type) . '&msg=' . urlencode($message));
    exit;
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach ($parts as $p) {
        if ($p !== '') $out .= strtoupper(substr($p, 0, 1));
    }
    return substr($out ?: 'A', 0, 2);
}

function agentValid(mysqli $conn, int $client_id, int $agent_id): bool {
    if ($agent_id <= 0) return false;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND client_id = ? AND status = 'active' AND role_id = 2");
    $stmt->bind_param('ii', $agent_id, $client_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count > 0;
}

function getLeadOldAgent(mysqli $conn, int $client_id, int $lead_id): ?int {
    $stmt = $conn->prepare("SELECT assigned_agent_id FROM re_leads WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param('ii', $lead_id, $client_id);
    $stmt->execute();
    $stmt->bind_result($old_agent_id);
    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();
    return $old_agent_id !== null ? (int)$old_agent_id : null;
}

function logAssignment(mysqli $conn, int $client_id, ?int $branch_id, int $lead_id, ?int $old_agent_id, ?int $new_agent_id, string $type, string $remarks, int $assigned_by): void {
    if (!tableExists($conn, 're_agent_assignments')) return;
    $stmt = $conn->prepare("\n        INSERT INTO re_agent_assignments\n        (client_id, branch_id, lead_id, old_agent_id, new_agent_id, assignment_type, remarks, assigned_by)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n    ");
    $stmt->bind_param('iiiiissi', $client_id, $branch_id, $lead_id, $old_agent_id, $new_agent_id, $type, $remarks, $assigned_by);
    $stmt->execute();
    $stmt->close();
}

function assignLeadToAgent(mysqli $conn, int $client_id, ?int $branch_id, int $lead_id, int $agent_id, string $type, string $remarks, int $user_id): bool {
    $old_agent_id = getLeadOldAgent($conn, $client_id, $lead_id);
    if ($old_agent_id === null && !tableExists($conn, 're_leads')) return false;

    $stmt = $conn->prepare("\n        UPDATE re_leads\n        SET assigned_agent_id = ?,\n            agent_id = ?,\n            status = CASE\n                WHEN status IN ('Converted','Lost','Cancelled') THEN status\n                ELSE 'Assigned'\n            END\n        WHERE id = ? AND client_id = ?\n    ");
    $stmt->bind_param('iiii', $agent_id, $agent_id, $lead_id, $client_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected >= 0) {
        logAssignment($conn, $client_id, $branch_id, $lead_id, $old_agent_id, $agent_id, $type, $remarks, $user_id);
        return true;
    }
    return false;
}

function unassignLead(mysqli $conn, int $client_id, ?int $branch_id, int $lead_id, string $remarks, int $user_id): bool {
    $old_agent_id = getLeadOldAgent($conn, $client_id, $lead_id);

    $stmt = $conn->prepare("\n        UPDATE re_leads\n        SET assigned_agent_id = NULL,\n            agent_id = NULL,\n            status = CASE\n                WHEN status IN ('Converted','Lost','Cancelled') THEN status\n                ELSE 'New'\n            END\n        WHERE id = ? AND client_id = ?\n    ");
    $stmt->bind_param('ii', $lead_id, $client_id);
    $stmt->execute();
    $stmt->close();

    logAssignment($conn, $client_id, $branch_id, $lead_id, $old_agent_id, null, 'Unassigned', $remarks, $user_id);
    return true;
}

/* ===================== REQUIRED TABLE CHECKS ===================== */
if (!tableExists($conn, 're_leads')) {
    die("<div style='font-family:Arial;margin:25px;padding:18px;border-radius:10px;background:#fff3cd;color:#664d03;'>re_leads table missing.</div>");
}
if (!tableExists($conn, 'users')) {
    die("<div style='font-family:Arial;margin:25px;padding:18px;border-radius:10px;background:#fff3cd;color:#664d03;'>users table missing.</div>");
}
if (!columnExists($conn, 're_leads', 'assigned_agent_id')) {
    die("<div style='font-family:Arial;margin:25px;padding:18px;border-radius:10px;background:#fff3cd;color:#664d03;'>re_leads.assigned_agent_id missing. Pehle SQL schema run karo.</div>");
}

/* ===================== POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_agent') {
        $name        = trim($_POST['name'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $designation = trim($_POST['designation'] ?? 'Sales Agent');
        $agent_city  = trim($_POST['city'] ?? '');
        $agent_notes = trim($_POST['notes'] ?? '');
        $agent_status_in = trim($_POST['status'] ?? 'active');
        $new_branch_id = (int)($_POST['branch_id'] ?? 0);
        $agent_status = strtolower($agent_status_in) === 'inactive' ? 'inactive' : 'active';
        $passwordRaw = trim($_POST['password'] ?? '123456');

        if ($name === '') redirectMsg('danger', 'Agent name required hai.');
        if ($phone === '') redirectMsg('danger', 'Agent phone required hai.');
        if ($email === '') redirectMsg('danger', 'Agent email required hai.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) redirectMsg('danger', 'Agent email valid nahi hai.');

        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE client_id = ? AND email = ?");
        $stmt->bind_param('is', $client_id, $email);
        $stmt->execute();
        $stmt->bind_result($exists);
        $stmt->fetch();
        $stmt->close();
        if ((int)$exists > 0) redirectMsg('danger', 'Is email se user already exists hai.');

        $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);
        $agentRoleId = 2;

        $stmt = $conn->prepare("\n            INSERT INTO users (client_id, role_id, name, email, phone, password, status, email_verified)\n            VALUES (?, ?, ?, ?, ?, ?, ?, 1)\n        ");
        $stmt->bind_param('iisssss', $client_id, $agentRoleId, $name, $email, $phone, $passwordHash, $agent_status);
        $stmt->execute();
        $new_agent_id = (int)$stmt->insert_id;
        $stmt->close();

        if (tableExists($conn, 're_agent_profiles')) {
            $branchForProfile = $new_branch_id > 0 ? $new_branch_id : ($branch_id > 0 ? $branch_id : null);
            $stmt = $conn->prepare("\n                INSERT INTO re_agent_profiles\n                (client_id, user_id, branch_id, designation, city, notes, created_by)\n                VALUES (?, ?, ?, ?, ?, ?, ?)\n            ");
            $stmt->bind_param('iiisssi', $client_id, $new_agent_id, $branchForProfile, $designation, $agent_city, $agent_notes, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        if ($new_branch_id > 0 && tableExists($conn, 'branch_users')) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM branch_users WHERE branch_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $new_branch_id, $new_agent_id);
            $stmt->execute();
            $stmt->bind_result($buExists);
            $stmt->fetch();
            $stmt->close();
            if ((int)$buExists === 0) {
                $stmt = $conn->prepare("INSERT INTO branch_users (branch_id, user_id) VALUES (?, ?)");
                $stmt->bind_param('ii', $new_branch_id, $new_agent_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        redirectMsg('success', 'New agent add ho gaya. Ab dropdowns me DB se show hoga. Default password: ' . $passwordRaw);
    }

    if ($action === 'single_assign') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $agent_id = (int)($_POST['agent_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        if ($lead_id <= 0) redirectMsg('danger', 'Lead select nahi hai.');
        if (!agentValid($conn, $client_id, $agent_id)) redirectMsg('danger', 'Valid active agent select karo.');
        assignLeadToAgent($conn, $client_id, $branch_id ?: null, $lead_id, $agent_id, 'Manual', $remarks, $user_id);
        redirectMsg('success', 'Lead assigned successfully.');
    }

    if ($action === 'bulk_assign') {
        $agent_id = (int)($_POST['bulk_agent_id'] ?? 0);
        $lead_ids = $_POST['lead_ids'] ?? [];
        $remarks = trim($_POST['bulk_remarks'] ?? 'Bulk assignment');

        if (!agentValid($conn, $client_id, $agent_id)) redirectMsg('danger', 'Bulk assignment ke liye valid active agent select karo.');
        if (!is_array($lead_ids) || count($lead_ids) === 0) redirectMsg('danger', 'Bulk assignment ke liye kam az kam 1 lead select karo.');

        $done = 0;
        foreach ($lead_ids as $lead_id_raw) {
            $lead_id = (int)$lead_id_raw;
            if ($lead_id <= 0) continue;
            if (assignLeadToAgent($conn, $client_id, $branch_id ?: null, $lead_id, $agent_id, 'Bulk', $remarks, $user_id)) {
                $done++;
            }
        }
        redirectMsg('success', $done . ' leads bulk assigned successfully.');
    }

    if ($action === 'unassign') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        if ($lead_id <= 0) redirectMsg('danger', 'Invalid lead.');
        unassignLead($conn, $client_id, $branch_id ?: null, $lead_id, 'Agent removed from assignment page', $user_id);
        redirectMsg('success', 'Lead unassigned successfully.');
    }
}

/* ===================== FILTERS ===================== */
$search = trim($_GET['search'] ?? '');
$sourceFilter = trim($_GET['source'] ?? '');
$agentFilter = trim($_GET['agent_filter'] ?? ''); // all/unassigned/agent id
$statusFilter = trim($_GET['status'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 10);
$perPage = in_array($perPage, [10,20,50,100], true) ? $perPage : 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/* ===================== BRANCHES ===================== */
$branches = [];
if (tableExists($conn, 'branches')) {
    $stmt = $conn->prepare("SELECT id, name FROM branches WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $branches[] = $row;
    $stmt->close();
}

/* ===================== AGENTS FROM DB ===================== */
$agents = [];
$profileJoin = tableExists($conn, 're_agent_profiles') ? "LEFT JOIN re_agent_profiles ap ON ap.user_id = u.id AND ap.client_id = u.client_id" : "";
$profileSelect = tableExists($conn, 're_agent_profiles') ? "ap.designation, ap.city AS agent_city, ap.notes AS agent_notes," : "'Sales Agent' AS designation, '' AS agent_city, '' AS agent_notes,";
$stmt = $conn->prepare("\n    SELECT\n        u.id, u.name, u.email, u.phone, u.status, u.role_id,\n        $profileSelect\n        COALESCE((SELECT COUNT(*) FROM re_leads l WHERE l.client_id = u.client_id AND l.assigned_agent_id = u.id AND l.status NOT IN ('Converted','Lost','Cancelled')), 0) AS active_leads\n    FROM users u\n    $profileJoin\n    WHERE u.client_id = ?\n      AND u.role_id = 2\n      AND u.status = 'active'\n    ORDER BY u.name ASC\n");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $agents[] = $row;
$stmt->close();

/* ===================== SOURCES ===================== */
$sources = [];
$stmt = $conn->prepare("SELECT DISTINCT source FROM re_leads WHERE client_id = ? AND source IS NOT NULL AND source <> '' ORDER BY source ASC");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $sources[] = $row['source'];
$stmt->close();

/* ===================== STATS ===================== */
function scalarVal(mysqli $conn, string $sql, string $types, array $params) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();
    return $value ?? 0;
}
$totalLeads = (int)scalarVal($conn, "SELECT COUNT(*) FROM re_leads WHERE client_id = ?", 'i', [$client_id]);
$unassignedLeads = (int)scalarVal($conn, "SELECT COUNT(*) FROM re_leads WHERE client_id = ? AND (assigned_agent_id IS NULL OR assigned_agent_id = 0)", 'i', [$client_id]);
$assignedLeads = $totalLeads - $unassignedLeads;
$hotLeads = (int)scalarVal($conn, "SELECT COUNT(*) FROM re_leads WHERE client_id = ? AND (priority = 'Hot' OR status = 'Hot Lead')", 'i', [$client_id]);
$todayLeads = (int)scalarVal($conn, "SELECT COUNT(*) FROM re_leads WHERE client_id = ? AND DATE(created_at) = CURDATE()", 'i', [$client_id]);

/* ===================== LEADS QUERY ===================== */
$where = " WHERE l.client_id = ? ";
$params = [$client_id];
$types = 'i';

if ($search !== '') {
    $where .= " AND (l.lead_name LIKE ? OR l.phone LIKE ? OR l.source LIKE ? OR l.requirement LIKE ?) ";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($sourceFilter !== '') {
    $where .= " AND l.source = ? ";
    $params[] = $sourceFilter;
    $types .= 's';
}
if ($agentFilter === 'unassigned') {
    $where .= " AND (l.assigned_agent_id IS NULL OR l.assigned_agent_id = 0) ";
} elseif ((int)$agentFilter > 0) {
    $where .= " AND l.assigned_agent_id = ? ";
    $params[] = (int)$agentFilter;
    $types .= 'i';
}
if ($statusFilter !== '') {
    $where .= " AND l.status = ? ";
    $params[] = $statusFilter;
    $types .= 's';
}

$countSql = "SELECT COUNT(*) FROM re_leads l LEFT JOIN users au ON au.id = l.assigned_agent_id $where";
$stmt = $conn->prepare($countSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->bind_result($totalFiltered);
$stmt->fetch();
$stmt->close();

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "\n    SELECT\n        l.id, l.lead_no, l.lead_name, l.phone, l.email, l.source, l.status, l.priority,\n        l.requirement, l.notes, l.created_at, l.updated_at, l.next_followup_at, l.next_followup_date,\n        l.assigned_agent_id, au.name AS assigned_agent_name, au.phone AS assigned_agent_phone,\n        (SELECT COUNT(*) FROM re_agent_assignments aa WHERE aa.client_id = l.client_id AND aa.lead_id = l.id) AS assignment_count\n    FROM re_leads l\n    LEFT JOIN users au ON au.id = l.assigned_agent_id AND au.client_id = l.client_id\n    $where\n    ORDER BY\n        CASE WHEN l.assigned_agent_id IS NULL OR l.assigned_agent_id = 0 THEN 0 ELSE 1 END ASC,\n        l.id DESC\n    LIMIT ? OFFSET ?\n";
$paramsPage = array_merge($params, [$perPage, $offset]);
$typesPage = $types . 'ii';
$stmt = $conn->prepare($sql);
$stmt->bind_param($typesPage, ...$paramsPage);
$stmt->execute();
$res = $stmt->get_result();
$leads = [];
while ($row = $res->fetch_assoc()) $leads[] = $row;
$stmt->close();

/* ===================== ASSIGNMENT HISTORY FOR VIEW MODAL ===================== */
$historyByLead = [];
if (tableExists($conn, 're_agent_assignments')) {
    $ids = array_column($leads, 'id');
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $historyTypes = 'i' . str_repeat('i', count($ids));
        $historyParams = array_merge([$client_id], $ids);
        $historySql = "\n            SELECT aa.*, oldu.name AS old_agent_name, newu.name AS new_agent_name, adminu.name AS assigned_by_name\n            FROM re_agent_assignments aa\n            LEFT JOIN users oldu ON oldu.id = aa.old_agent_id\n            LEFT JOIN users newu ON newu.id = aa.new_agent_id\n            LEFT JOIN users adminu ON adminu.id = aa.assigned_by\n            WHERE aa.client_id = ? AND aa.lead_id IN ($placeholders)\n            ORDER BY aa.id DESC\n        ";
        $stmt = $conn->prepare($historySql);
        $stmt->bind_param($historyTypes, ...$historyParams);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($h = $res->fetch_assoc()) {
            $historyByLead[(int)$h['lead_id']][] = $h;
        }
        $stmt->close();
    }
}

$agentsJson = json_encode($agents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$leadsJson = json_encode($leads, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$historyJson = json_encode($historyByLead, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

$queryBase = $_GET;
unset($queryBase['page']);
function pageUrl(int $p, array $queryBase): string {
    $queryBase['page'] = $p;
    return 'agent-assignment.php?' . http_build_query($queryBase);
}

/* ===================== EXPORT ===================== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = "\n        SELECT l.lead_name, l.phone, l.email, l.source, l.status, l.priority, au.name AS assigned_agent_name, l.created_at\n        FROM re_leads l\n        LEFT JOIN users au ON au.id = l.assigned_agent_id AND au.client_id = l.client_id\n        $where\n        ORDER BY l.id DESC\n    ";
    $stmt = $conn->prepare($exportSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=agent_assignment_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Lead Name','Phone','Email','Source','Status','Priority','Assigned Agent','Created At']);
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [$r['lead_name'], $r['phone'], $r['email'], $r['source'], $r['status'], $r['priority'], $r['assigned_agent_name'] ?: 'Unassigned', $r['created_at']]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Agent Assignment – ONE ERP Real Estate</title>
<?php include('partials/head.php'); ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<style>
:root{--bg:#eef2f7;--card-bg:#fff;--border:#e0e5f2;--text-primary:#1f2736;--text-secondary:#64748b;--text-muted:#9ca3af;--primary:#c9a84c;--primary-dark:#d8a500;--primary-light:#fff7df;--success:#3e7a3a;--warning:#dc9b2d;--danger:#c94b57;--blue:#3b6cf4;--shadow:0 4px 12px rgba(0,0,0,.05);}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-primary);overflow-x:hidden}.container-scroll{display:flex;min-height:100vh;background:var(--bg)}.sidebar{width:270px;flex-shrink:0}.main-panel{flex:1;min-width:0;display:flex;flex-direction:column;background:var(--bg)}.topbar{min-height:70px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;align-items:center}.content-wrapper{padding:24px;flex:1}.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px;gap:16px;flex-wrap:wrap}.page-title{font-size:24px;font-weight:900;color:#243046;letter-spacing:-.3px}.breadcrumb{display:flex;align-items:center;gap:5px;margin-top:5px}.breadcrumb span{font-size:12px;color:var(--text-muted)}.breadcrumb .crumb-active{color:var(--primary);font-weight:800}.page-header-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;border:none;transition:all .18s;white-space:nowrap;text-decoration:none}.btn .material-icons-round{font-size:17px}.btn-outline{background:#fff;border:1px solid var(--border);color:#2b3674}.btn-outline:hover{background:#f4f7fe;border-color:var(--primary);color:#8a6a20}.btn-primary{background:linear-gradient(180deg,#f5c742,#d8a500);color:#111;box-shadow:0 4px 12px rgba(216,165,0,.25)}.btn-danger{background:linear-gradient(180deg,#c94b57,#9f2430);color:#fff}.btn-sm{padding:6px 12px;font-size:12px}.alert{margin-bottom:18px;padding:12px 14px;border-radius:12px;font-weight:800}.alert-success{background:#dcfce7;color:#14532d;border:1px solid #bbf7d0}.alert-danger{background:#fee2e2;color:#7f1d1d;border:1px solid #fecaca}
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}.stat-card{border:none;border-radius:14px;min-height:112px;padding:18px 16px;color:#fff;position:relative;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12);display:flex;flex-direction:column;justify-content:space-between}.stat-card::after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12)}.stat-card:nth-child(1){background:linear-gradient(135deg,#9f2430,#c94b57)}.stat-card:nth-child(2){background:linear-gradient(135deg,#b27b16,#d8a33a)}.stat-card:nth-child(3){background:linear-gradient(135deg,#b87a16,#dc9b2d)}.stat-card:nth-child(4){background:linear-gradient(135deg,#386c58,#4c9075)}.stat-card:nth-child(5){background:linear-gradient(135deg,#111c44,#20346d)}.stat-card-top{position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.stat-label{font-size:11.5px;font-weight:800;color:rgba(255,255,255,.88);margin-bottom:8px}.stat-value{font-size:28px;font-weight:900;letter-spacing:-.5px;color:#fff;line-height:1}.stat-helper{position:relative;z-index:1;font-size:11px;color:rgba(255,255,255,.84);margin-top:8px}.stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.20);flex-shrink:0}
.workload-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}.workload-card{background:#fff;border:1px solid #eef2f7;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,.05);padding:16px;display:flex;align-items:center;gap:12px}.agent-avatar{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#c9a84c,#e8c96d);color:#0f1117;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0}.workload-name{font-size:13px;font-weight:900;color:#1e293b;margin-bottom:4px}.workload-meta{font-size:12px;color:#64748b}.workload-bar{height:7px;background:#edf2f7;border-radius:999px;overflow:hidden;margin-top:8px}.workload-bar span{display:block;height:100%;background:linear-gradient(90deg,#d8a500,#c9a84c);border-radius:999px}
.filter-card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:16px 20px;margin-bottom:22px}.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.filter-group{display:flex;flex-direction:column;gap:4px;flex:1;min-width:140px}.filter-group label{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px}.filter-group input,.filter-group select{background:#f4f7fe;border:1px solid var(--border);border-radius:10px;padding:8px 10px;font-size:12.5px;color:#2b3674;font-weight:700;outline:none;height:42px}.filter-group input:focus,.filter-group select:focus{border-color:var(--primary);background:#fff;box-shadow:0 0 0 3px rgba(201,168,76,.12)}.filter-group.search-wide{flex:2;min-width:220px}.filter-actions{display:flex;gap:8px;align-items:flex-end}.bulk-panel{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;background:#fff8e6;border:1px solid #f5d77c;border-radius:12px;margin-bottom:14px}.bulk-panel-left{display:flex;align-items:center;gap:8px;color:#8a6a20;font-size:13px;font-weight:800}.bulk-panel-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.bulk-panel select{height:36px;border:1px solid var(--border);border-radius:8px;padding:0 10px;color:#2b3674;font-weight:800;background:#fff}
.table-card{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.05);overflow:hidden}.table-card-header{padding:16px 20px;border-bottom:1px solid #edf2f7;display:flex;align-items:center;justify-content:space-between;gap:12px}.table-card-title{font-size:18px;font-weight:900;color:#1a202c}.table-count{font-size:12px;color:#94a3b8;font-weight:800}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px;min-width:1080px}thead th{background:#f8fafc;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase;padding:14px 12px;border-bottom:2px solid #edf2f7;text-align:left;white-space:nowrap}tbody tr{border-bottom:1px solid #f1f5f9;transition:background .13s}tbody tr:hover{background:#fbfcfe}tbody td{padding:14px 12px;color:#334155;font-size:13px;vertical-align:middle;white-space:nowrap}.checkbox-cell{width:40px;text-align:center}.row-check,.select-all{width:16px;height:16px;accent-color:#d8a500;cursor:pointer}.lead-cell{display:flex;align-items:center;gap:10px}.lead-avatar{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:900;flex-shrink:0;background:linear-gradient(135deg,#9f2430,#c94b57)}.lead-name{font-size:13.5px;font-weight:900;color:#1e293b}.lead-sub{font-size:11px;color:#94a3b8;margin-top:1px}.phone-text{font-size:13px;color:#64748b;font-weight:700}.source-chip{display:inline-flex;padding:4px 10px;border-radius:8px;background:#f3f4f6;color:#5a6280;font-size:12px;font-weight:800}.agent-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#2b3674;font-size:12px;font-weight:900}.agent-chip.unassigned{background:#fee2e2;color:#7f1d1d}.agent-dot{width:7px;height:7px;border-radius:50%;background:#d8a500}.new-agent-select{height:36px;border:1px solid var(--border);border-radius:9px;background:#f4f7fe;color:#2b3674;font-weight:800;min-width:160px;padding:0 10px;outline:none}.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:900}.badge-new{background:#dbeafe;color:#1e40af}.badge-hot{background:#fee2e2;color:#7f1d1d}.badge-follow{background:#fef3c7;color:#92400e}.badge-assigned{background:#dcfce7;color:#14532d}.action-btns{display:flex;gap:5px}.action-btn{width:32px;height:32px;border-radius:7px;border:none;background:transparent;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#64748b}.action-btn.view{color:#3b6cf4}.action-btn.assign{color:#d8a500}.action-btn.clear{color:#c94b57}.pagination-bar{display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-top:1px solid #edf2f7;gap:12px;flex-wrap:wrap;background:#fff}.pagination-info{font-size:12.5px;color:#64748b}.pagination-controls{display:flex;align-items:center;gap:5px}.page-btn{min-width:32px;height:32px;padding:0 8px;border-radius:6px;border:1px solid var(--border);background:#fff;font-size:12.5px;font-weight:800;color:#64748b;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.page-btn.active{background:#d8a500;border-color:#d8a500;color:#fff}.page-btn.disabled{opacity:.4;pointer-events:none}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.58);backdrop-filter:blur(5px);z-index:999999;align-items:flex-start;justify-content:center;padding:30px 16px;overflow-y:auto}.modal-overlay.open{display:flex!important}.erp-modal{display:block!important;background:#fff;border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.25);width:100%;max-width:620px;overflow:hidden;margin:auto 0}.modal-header{height:70px;padding:0 24px;background:linear-gradient(135deg,#111,#1a1a1a);display:flex;align-items:center;justify-content:space-between}.modal-title{color:#fff;font-size:16px;font-weight:900;display:flex;align-items:center;gap:8px}.modal-close{width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:9px;cursor:pointer;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10);color:#aaa}.modal-body{padding:22px 24px;max-height:calc(100vh - 220px);overflow-y:auto}.modal-footer{padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;background:#fff}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.form-group{display:flex;flex-direction:column;gap:6px}.form-group.full{grid-column:1/-1}.form-group label{font-size:11.5px;font-weight:900;color:#374151;text-transform:uppercase;letter-spacing:.4px}.form-group input,.form-group select,.form-group textarea{border:1.5px solid #e5e7eb;border-radius:9px;padding:9px 12px;font-size:13px;color:#1f2736;outline:none;background:#fafafa}.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#c9a84c;background:#fff;box-shadow:0 0 0 3px rgba(201,168,76,.12)}.form-group textarea{resize:vertical;min-height:76px}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.detail-item{background:#f8fafc;border:1px solid #edf2f7;border-radius:12px;padding:12px}.detail-item.full{grid-column:1/-1}.detail-label{font-size:10.5px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}.detail-value{font-size:13.5px;font-weight:800;color:#1f2736;line-height:1.4}.history-list{display:flex;flex-direction:column;gap:8px}.history-item{background:#fff;border:1px solid #edf2f7;border-radius:10px;padding:10px 12px;font-size:12px;color:#64748b}.history-item b{color:#1e293b}.hint{display:flex;align-items:flex-start;gap:8px;margin-top:14px;background:#fff8e6;border:1px solid #f5d77c;color:#8a6a20;border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:700}.toast-container{position:fixed;bottom:24px;right:24px;z-index:9999999;display:flex;flex-direction:column;gap:8px}.toast{background:#111827;color:#fff;border-radius:10px;padding:12px 18px;font-size:13px;font-weight:800;display:flex;align-items:center;gap:8px;box-shadow:0 10px 30px rgba(0,0,0,.2)}.toast.success{border-left:4px solid #3e7a3a}.toast.error{border-left:4px solid #c94b57}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr)}.workload-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}.form-grid,.detail-grid{grid-template-columns:1fr}.filter-actions,.bulk-panel-right{width:100%}.filter-actions .btn{flex:1;justify-content:center}}@media(max-width:640px){.stats-grid,.workload-grid{grid-template-columns:1fr}.bulk-panel{align-items:flex-start;flex-direction:column}}

/* =====================================================
   AGENT-ASSIGNMENT.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
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

/* Topbar / Content Background */
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

.breadcrumb span{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.breadcrumb .crumb-active{
    color:#c4b5fd !important;
    font-weight:900 !important;
}

/* Buttons */
.btn{
    min-height:42px !important;
    border-radius:12px !important;
    font-weight:900 !important;
    transition:.22s ease !important;
}

.btn-primary{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-outline{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.btn-danger{
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#fff !important;
    border:none !important;
    box-shadow:0 14px 28px rgba(239,68,68,.22) !important;
}

.btn:hover{
    transform:translateY(-1px);
    color:#fff !important;
    filter:saturate(1.06);
}

/* Alerts */
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

/* Stats Cards */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(5,1fr) !important;
    gap:16px !important;
    margin-bottom:20px !important;
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

.stat-card:nth-child(1){background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-card:nth-child(2){background:linear-gradient(135deg,#be123c,#ef4444) !important;}
.stat-card:nth-child(3){background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-card:nth-child(4){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.stat-card:nth-child(5){background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}

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
    color:#fff !important;
}

/* Glass Cards */
.workload-card,
.filter-card,
.bulk-panel,
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

.workload-card:hover,
.filter-card:hover,
.table-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.workload-name,
.table-card-title,
.lead-name{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.workload-meta,
.table-count,
.lead-sub,
.phone-text,
.pagination-info{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.agent-avatar,
.lead-avatar{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 10px 22px rgba(109,76,255,.24) !important;
}

.workload-bar{
    background:rgba(15,23,42,.72) !important;
}

.workload-bar span{
    background:linear-gradient(90deg,#6d4cff,#a78bfa) !important;
}

/* Filter + Bulk */
.filter-card{
    padding:16px !important;
}

.filter-group label{
    color:#f8fafc !important;
    font-weight:900 !important;
    font-size:12px !important;
}

.filter-group input,
.filter-group select,
.bulk-panel select,
.bulk-panel input,
.new-agent-select{
    height:42px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-group input::placeholder,
.bulk-panel input::placeholder{
    color:#94a3b8 !important;
}

.filter-group input:focus,
.filter-group select:focus,
.bulk-panel select:focus,
.bulk-panel input:focus,
.new-agent-select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-group select option,
.bulk-panel select option,
.new-agent-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.bulk-panel{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(109,76,255,.26), rgba(15,23,42,.72)) !important;
}

.bulk-panel-left{
    color:#e5e7eb !important;
}

.bulk-panel-left .material-icons-round{
    color:#c4b5fd !important;
}

.row-check,
.select-all{
    accent-color:#7c3aed !important;
}

/* Table */
.table-card-header{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

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

/* White hover remove */
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

tbody td[colspan],
tbody td[style*="text-align:center"]{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

/* Chips / Badges */
.source-chip{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
    border-radius:999px !important;
}

.agent-chip{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border:1px solid rgba(59,130,246,.24) !important;
}

.agent-chip.unassigned{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

.agent-dot{
    background:#a78bfa !important;
}

.badge{
    border:1px solid rgba(148,163,184,.18) !important;
}

.badge-new{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border-color:rgba(59,130,246,.24) !important;
}

.badge-hot{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

.badge-follow{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.badge-assigned{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

/* Actions */
.action-btn{
    width:36px !important;
    height:36px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.62) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.action-btn.view{color:#60a5fa !important;}
.action-btn.assign{color:#fbbf24 !important;}
.action-btn.clear{color:#f87171 !important;}

.action-btn:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.20) !important;
    color:#fff !important;
}

/* Pagination */
.pagination-bar{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
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

.page-btn.disabled{
    opacity:.45 !important;
}

/* Toast */
.toast{
    background:#0f172a !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}

.toast.success{
    border-left:4px solid #22c55e !important;
}

.toast.error{
    border-left:4px solid #ef4444 !important;
}

/* =====================================================
   AGENT ASSIGNMENT POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

.modal-overlay{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:999999 !important;
}

.modal-overlay.open{
    display:flex !important;
}

.erp-modal,
#assignModal .erp-modal,
#newAgentModal .erp-modal,
#viewModal .erp-modal{
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

#assignModal .erp-modal,
#viewModal .erp-modal{
    max-width:680px !important;
}

#newAgentModal .erp-modal{
    max-width:760px !important;
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
    margin:0 !important;
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

.modal-title .material-icons-round{
    color:#c4b5fd !important;
}

.modal-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    min-width:38px !important;
    min-height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    color:#ffffff !important;
    opacity:1 !important;
    padding:0 !important;
    box-shadow:none !important;
}

.modal-close .material-icons-round{
    font-size:22px !important;
    color:#fff !important;
}

.modal-close:hover{
    background:#ef4444 !important;
    transform:translateY(-1px);
}

.modal-body{
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

.form-group label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

.form-group input,
.form-group select,
.form-group textarea{
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

.form-group textarea{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

.form-group input[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
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
    outline:none !important;
}

.form-group select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.hint{
    background:rgba(15,23,42,.62) !important;
    border:1px solid rgba(139,92,246,.32) !important;
    color:#e5e7eb !important;
    border-radius:14px !important;
    font-weight:800 !important;
}

.hint .material-icons-round{
    color:#c4b5fd !important;
}

/* View details */
.detail-grid{
    color:#ffffff !important;
}

.detail-item{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

.detail-label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

.detail-value{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.history-list{
    gap:9px !important;
}

.history-item{
    background:rgba(15,23,42,.50) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    color:#cbd5e1 !important;
    border-radius:10px !important;
    box-shadow:0 8px 20px rgba(0,0,0,.18) !important;
}

.history-item b{
    color:#ffffff !important;
}

.history-item small{
    color:#94a3b8 !important;
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

.modal-footer .btn:hover{
    transform:translateY(-1px);
}

/* Scrollbar */
.modal-body::-webkit-scrollbar,
.table-wrap::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.modal-body::-webkit-scrollbar-track,
.table-wrap::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.modal-body::-webkit-scrollbar-thumb,
.table-wrap::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.modal-body::-webkit-scrollbar-thumb:hover,
.table-wrap::-webkit-scrollbar-thumb:hover,
.content-wrapper::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

@media(max-width:1280px){
    .stats-grid{
        grid-template-columns:repeat(3,1fr) !important;
    }

    .workload-grid{
        grid-template-columns:repeat(2,1fr) !important;
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
    .stats-grid,
    .workload-grid,
    .form-grid,
    .detail-grid{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .page-header-right,
    .page-header-right .btn{
        width:100% !important;
    }

    .page-header-right .btn{
        justify-content:center !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .filter-group,
    .filter-group.search-wide{
        min-width:100% !important;
    }

    .filter-actions{
        width:100% !important;
        flex-direction:column !important;
    }

    .filter-actions .btn{
        width:100% !important;
        justify-content:center !important;
    }

    .bulk-panel{
        align-items:stretch !important;
        flex-direction:column !important;
    }

    .bulk-panel-right,
    .bulk-panel-right select,
    .bulk-panel-right input,
    .bulk-panel-right .btn{
        width:100% !important;
    }

    .erp-modal{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    .modal-title{
        font-size:18px !important;
    }

    .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .modal-footer .btn{
        width:100% !important;
        justify-content:center !important;
    }
}

</style>
</head>
<body>
<div class="container-scroll">
  <div class="sidebar"><?php include('partials/sidebar.php'); ?></div>
  <div class="main-panel">
    <div class="topbar"><?php include('partials/topbar.php'); ?></div>
    <div class="content-wrapper">
      <?php if ($msg !== ''): ?><div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div><?php endif; ?>

      <div class="page-header">
        <div>
          <div class="page-title">Agent Assignment</div>
          <div class="breadcrumb"><span>Home</span><span>›</span><span>Real Estate</span><span>›</span><span class="crumb-active">Agent Assignment</span></div>
        </div>
        <div class="page-header-right">
          <a class="btn btn-outline" href="agent-assignment.php?<?= e(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>"><span class="material-icons-round">download</span> Export</a>
          <button class="btn btn-outline" type="button" onclick="openNewAgentModal()"><span class="material-icons-round">person_add</span> Add New Agent</button>
          <button class="btn btn-primary" type="button" onclick="focusBulk()"><span class="material-icons-round">assignment_ind</span> Bulk Assign</button>
        </div>
      </div>

      <div class="stats-grid">
        <div class="stat-card"><div class="stat-card-top"><div><div class="stat-label">Total Leads</div><div class="stat-value"><?= (int)$totalLeads ?></div></div><div class="stat-icon"><span class="material-icons-round">groups</span></div></div><div class="stat-helper">Assignment Queue</div></div>
        <div class="stat-card"><div class="stat-card-top"><div><div class="stat-label">Unassigned</div><div class="stat-value"><?= (int)$unassignedLeads ?></div></div><div class="stat-icon"><span class="material-icons-round">person_off</span></div></div><div class="stat-helper">Need assignment</div></div>
        <div class="stat-card"><div class="stat-card-top"><div><div class="stat-label">Assigned</div><div class="stat-value"><?= (int)$assignedLeads ?></div></div><div class="stat-icon"><span class="material-icons-round">assignment_ind</span></div></div><div class="stat-helper">Already assigned</div></div>
        <div class="stat-card"><div class="stat-card-top"><div><div class="stat-label">Hot Leads</div><div class="stat-value"><?= (int)$hotLeads ?></div></div><div class="stat-icon"><span class="material-icons-round">local_fire_department</span></div></div><div class="stat-helper">Priority hot</div></div>
        <div class="stat-card"><div class="stat-card-top"><div><div class="stat-label">Today Leads</div><div class="stat-value"><?= (int)$todayLeads ?></div></div><div class="stat-icon"><span class="material-icons-round">today</span></div></div><div class="stat-helper">Created today</div></div>
      </div>

      <div class="workload-grid">
        <?php if (empty($agents)): ?>
          <div class="workload-card" style="grid-column:1/-1;"><div class="agent-avatar">!</div><div><div class="workload-name">No active agent found</div><div class="workload-meta">Add New Agent button se role_id=2 user create karo.</div></div></div>
        <?php else: ?>
          <?php $maxLoad = max(1, ...array_map(fn($a)=>(int)$a['active_leads'], $agents)); ?>
          <?php foreach (array_slice($agents, 0, 4) as $a): $pct = round(((int)$a['active_leads'] / $maxLoad) * 100); ?>
            <div class="workload-card"><div class="agent-avatar"><?= e(initials($a['name'])) ?></div><div style="flex:1;min-width:0;"><div class="workload-name"><?= e($a['name']) ?></div><div class="workload-meta"><?= (int)$a['active_leads'] ?> active leads</div><div class="workload-bar"><span style="width:<?= (int)$pct ?>%"></span></div></div></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <form class="filter-card" method="GET" action="agent-assignment.php">
        <div class="filter-row">
          <div class="filter-group search-wide"><label>Search Lead</label><input type="text" name="search" placeholder="Lead name, phone or source..." value="<?= e($search) ?>"></div>
          <div class="filter-group"><label>Source</label><select name="source"><option value="">All Sources</option><?php foreach($sources as $src): ?><option value="<?= e($src) ?>" <?= $sourceFilter===$src?'selected':'' ?>><?= e($src) ?></option><?php endforeach; ?></select></div>
          <div class="filter-group"><label>Current Agent</label><select name="agent_filter"><option value="">All Agents</option><option value="unassigned" <?= $agentFilter==='unassigned'?'selected':'' ?>>Unassigned</option><?php foreach($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>" <?= (string)$agentFilter===(string)$agent['id']?'selected':'' ?>><?= e($agent['name']) ?></option><?php endforeach; ?></select></div>
          <div class="filter-group"><label>Status</label><select name="status"><option value="">All Status</option><?php foreach(['New','Assigned','Follow-up','Follow Up','Hot Lead','Converted','Lost','Cancelled'] as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter===$st?'selected':'' ?>><?= e($st) ?></option><?php endforeach; ?></select></div>
          <div class="filter-group" style="max-width:100px;"><label>Rows</label><select name="per_page"><?php foreach([10,20,50,100] as $pp): ?><option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option><?php endforeach; ?></select></div>
          <div class="filter-actions"><button class="btn btn-primary" type="submit"><span class="material-icons-round">filter_list</span> Filter</button><a class="btn btn-outline" href="agent-assignment.php"><span class="material-icons-round">refresh</span> Reset</a></div>
        </div>
      </form>

      <form method="POST" id="bulkAssignForm">
        <input type="hidden" name="action" value="bulk_assign">
        <div class="bulk-panel">
          <div class="bulk-panel-left"><span class="material-icons-round">info</span><span id="selectedInfo">Select leads for bulk assignment</span></div>
          <div class="bulk-panel-right">
            <select name="bulk_agent_id" id="bulkAgent" required><option value="">Assign selected to...</option><?php foreach($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>"><?= e($agent['name']) ?></option><?php endforeach; ?></select>
            <input type="text" name="bulk_remarks" placeholder="Bulk note optional" style="height:36px;border:1px solid var(--border);border-radius:8px;padding:0 10px;">
            <button class="btn btn-primary" type="submit"><span class="material-icons-round">done_all</span> Assign Selected</button>
            <button class="btn btn-outline" type="button" onclick="clearSelection()"><span class="material-icons-round">close</span> Clear</button>
          </div>
        </div>

        <div class="table-card">
          <div class="table-card-header"><div><div class="table-card-title">Lead Assignment Queue</div><div class="table-count"><?= (int)$totalFiltered ?> records</div></div><div class="table-count">Last updated: <?= e(date('d M Y h:i A')) ?></div></div>
          <div class="table-wrap">
            <table>
              <thead><tr><th class="checkbox-cell"><input type="checkbox" class="select-all" id="selectAll" onchange="toggleSelectAll(this)"></th><th>Lead Name</th><th>Phone</th><th>Source</th><th>Current Agent</th><th>Status</th><th>New Agent</th><th>Last Activity</th><th>Assign Button</th></tr></thead>
              <tbody>
                <?php if (empty($leads)): ?>
                  <tr><td colspan="9" style="text-align:center;padding:44px;color:#94a3b8;"><span class="material-icons-round" style="font-size:44px;display:block;margin-bottom:8px;">search_off</span>No leads found.</td></tr>
                <?php else: ?>
                  <?php foreach($leads as $idx=>$l):
                    $isUnassigned = empty($l['assigned_agent_id']);
                    $displayStatus = $l['status'] ?: 'New';
                    $badgeClass = 'badge-new';
                    if (stripos($displayStatus, 'hot') !== false || ($l['priority'] ?? '') === 'Hot') $badgeClass = 'badge-hot';
                    elseif (stripos($displayStatus, 'follow') !== false) $badgeClass = 'badge-follow';
                    elseif (stripos($displayStatus, 'assign') !== false) $badgeClass = 'badge-assigned';
                    $activity = $l['requirement'] ?: ($l['notes'] ?: ('Created: ' . $l['created_at']));
                  ?>
                    <tr>
                      <td class="checkbox-cell"><input type="checkbox" class="row-check" name="lead_ids[]" value="<?= (int)$l['id'] ?>" onchange="updateSelectedInfo()"></td>
                      <td><div class="lead-cell"><div class="lead-avatar"><?= e(initials($l['lead_name'])) ?></div><div><div class="lead-name"><?= e($l['lead_name']) ?></div><div class="lead-sub">Lead ID: <?= e($l['lead_no'] ?: str_pad((string)$l['id'], 4, '0', STR_PAD_LEFT)) ?></div></div></div></td>
                      <td><span class="phone-text"><?= e($l['phone'] ?: '-') ?></span></td>
                      <td><span class="source-chip"><?= e($l['source'] ?: 'Manual') ?></span></td>
                      <td><?php if($isUnassigned): ?><span class="agent-chip unassigned"><span class="agent-dot" style="background:#c94b57"></span>Unassigned</span><?php else: ?><span class="agent-chip"><span class="agent-dot"></span><?= e($l['assigned_agent_name']) ?></span><?php endif; ?></td>
                      <td><span class="badge <?= e($badgeClass) ?>"><?= e($displayStatus) ?></span></td>
                      <td><select class="new-agent-select" id="rowAgent<?= (int)$l['id'] ?>"><option value="">Select Agent</option><?php foreach($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>" <?= (int)$l['assigned_agent_id']===(int)$agent['id']?'selected':'' ?>><?= e($agent['name']) ?></option><?php endforeach; ?></select></td>
                      <td><div style="max-width:220px;white-space:normal;line-height:1.35;color:#64748b;font-size:12.5px;font-weight:600;"><?= e($activity) ?></div></td>
                      <td><div class="action-btns"><button class="action-btn view" type="button" title="View" onclick="viewLead(<?= (int)$l['id'] ?>)"><span class="material-icons-round">visibility</span></button><button class="action-btn assign" type="button" title="Assign" onclick="quickAssign(<?= (int)$l['id'] ?>)"><span class="material-icons-round">assignment_ind</span></button><button class="action-btn clear" type="button" title="Clear Agent" onclick="unassignLead(<?= (int)$l['id'] ?>)"><span class="material-icons-round">person_remove</span></button></div></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="pagination-bar"><div class="pagination-info">Showing <?= $totalFiltered > 0 ? (int)$offset + 1 : 0 ?> to <?= min($offset + $perPage, (int)$totalFiltered) ?> of <?= (int)$totalFiltered ?> entries</div><div class="pagination-controls"><a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(pageUrl($page - 1, $queryBase)) ?>">‹</a><?php for($p=1;$p<=$totalPages;$p++): ?><?php if($p===1 || $p===$totalPages || abs($p-$page)<=1): ?><a class="page-btn <?= $p===$page?'active':'' ?>" href="<?= e(pageUrl($p, $queryBase)) ?>"><?= (int)$p ?></a><?php elseif(abs($p-$page)===2): ?><span style="padding:0 4px;color:#94a3b8;">...</span><?php endif; ?><?php endfor; ?><a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(pageUrl($page + 1, $queryBase)) ?>">›</a></div></div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- HIDDEN SINGLE ASSIGN FORM -->
<form method="POST" id="singleAssignForm" style="display:none;"><input type="hidden" name="action" value="single_assign"><input type="hidden" name="lead_id" id="singleLeadId"><input type="hidden" name="agent_id" id="singleAgentId"><input type="hidden" name="remarks" id="singleRemarks" value="Quick assignment from row"></form>
<form method="POST" id="unassignForm" style="display:none;"><input type="hidden" name="action" value="unassign"><input type="hidden" name="lead_id" id="unassignLeadId"></form>

<!-- ASSIGN MODAL -->
<div class="modal-overlay" id="assignModal"><div class="erp-modal"><div class="modal-header"><div class="modal-title"><span class="material-icons-round">assignment_ind</span><span>Assign Lead</span></div><button class="modal-close" type="button" onclick="closeAssignModal()"><span class="material-icons-round">close</span></button></div><form method="POST"><input type="hidden" name="action" value="single_assign"><input type="hidden" name="lead_id" id="mLeadId"><div class="modal-body"><div class="form-grid"><div class="form-group full"><label>Lead</label><input type="text" id="mLead" readonly></div><div class="form-group"><label>Current Agent</label><input type="text" id="mCurrentAgent" readonly></div><div class="form-group"><label>New Agent *</label><select name="agent_id" id="mNewAgent" required><option value="">Select Agent</option><?php foreach($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>"><?= e($agent['name']) ?></option><?php endforeach; ?></select></div><div class="form-group full"><label>Assignment Note</label><textarea name="remarks" id="mNote" placeholder="Optional note for this assignment..."></textarea></div></div></div><div class="modal-footer"><button class="btn btn-outline" type="button" onclick="closeAssignModal()">Cancel</button><button class="btn btn-primary" type="submit"><span class="material-icons-round">save</span> Assign Lead</button></div></form></div></div>

<!-- NEW AGENT MODAL -->
<div class="modal-overlay" id="newAgentModal"><div class="erp-modal" style="max-width:760px;"><div class="modal-header"><div class="modal-title"><span class="material-icons-round">person_add</span> Add New Agent</div><button class="modal-close" type="button" onclick="closeNewAgentModal()"><span class="material-icons-round">close</span></button></div><form method="POST"><input type="hidden" name="action" value="add_agent"><div class="modal-body"><div class="form-grid"><div class="form-group"><label>Agent Full Name *</label><input type="text" name="name" placeholder="e.g. Ahmed Khan" required></div><div class="form-group"><label>Phone *</label><input type="text" name="phone" placeholder="0300-1234567" required></div><div class="form-group"><label>Email *</label><input type="email" name="email" placeholder="agent@example.com" required></div><div class="form-group"><label>Password</label><input type="text" name="password" value="123456" placeholder="Default password"></div><div class="form-group"><label>Designation</label><input type="text" name="designation" value="Sales Agent" placeholder="Sales Agent"></div><div class="form-group"><label>Branch</label><select name="branch_id"><option value="0">No Branch / Main</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>City</label><input type="text" name="city" placeholder="Karachi / Lahore / Islamabad"></div><div class="form-group"><label>Status</label><select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="form-group full"><label>Notes</label><textarea name="notes" placeholder="Optional joining notes, territory, or instructions..."></textarea></div></div><div class="hint"><span class="material-icons-round">info</span> New agent users table me role_id = 2 ke sath save hoga. Save ke baad Agency Leads, Follow Ups, Site Visits, Properties, Deals aur Commission pages me dropdown DB se ye agent show karega.</div></div><div class="modal-footer"><button class="btn btn-outline" type="button" onclick="closeNewAgentModal()">Cancel</button><button class="btn btn-primary" type="submit"><span class="material-icons-round">save</span> Save Agent</button></div></form></div></div>

<!-- VIEW MODAL -->
<div class="modal-overlay" id="viewModal"><div class="erp-modal"><div class="modal-header"><div class="modal-title"><span class="material-icons-round">visibility</span> Lead Assignment Detail</div><button class="modal-close" type="button" onclick="closeViewModal()"><span class="material-icons-round">close</span></button></div><div class="modal-body" id="viewContent"></div><div class="modal-footer"><button class="btn btn-outline" type="button" onclick="closeViewModal()">Close</button></div></div></div>
<div class="toast-container" id="toastContainer"></div>

<script>
const leads = <?= $leadsJson ?: '[]' ?>;
const agents = <?= $agentsJson ?: '[]' ?>;
const historyByLead = <?= $historyJson ?: '{}' ?>;
function byId(id){return document.getElementById(id)}
function esc(s){return String(s||'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))}
function statusBadge(status, priority){let cls='badge-new'; if((status||'').toLowerCase().includes('hot') || priority==='Hot') cls='badge-hot'; else if((status||'').toLowerCase().includes('follow')) cls='badge-follow'; else if((status||'').toLowerCase().includes('assign')) cls='badge-assigned'; return `<span class="badge ${cls}">${esc(status||'New')}</span>`}
function agentChip(name){if(!name)return `<span class="agent-chip unassigned"><span class="agent-dot" style="background:#c94b57"></span>Unassigned</span>`;return `<span class="agent-chip"><span class="agent-dot"></span>${esc(name)}</span>`}
function updateSelectedInfo(){const checks=[...document.querySelectorAll('.row-check')];const selected=checks.filter(c=>c.checked).length;byId('selectedInfo').textContent=selected?`${selected} leads selected for bulk assignment`:'Select leads for bulk assignment';const all=byId('selectAll');if(all)all.checked=checks.length>0&&checks.every(c=>c.checked)}
function toggleSelectAll(cb){document.querySelectorAll('.row-check').forEach(c=>c.checked=cb.checked);updateSelectedInfo()}
function clearSelection(){document.querySelectorAll('.row-check').forEach(c=>c.checked=false);updateSelectedInfo()}
function quickAssign(id){const sel=byId('rowAgent'+id);if(!sel||!sel.value){openAssignModal(id);return}byId('singleLeadId').value=id;byId('singleAgentId').value=sel.value;byId('singleAssignForm').submit()}
function unassignLead(id){if(!confirm('Is lead ka agent remove karna hai?'))return;byId('unassignLeadId').value=id;byId('unassignForm').submit()}
function openAssignModal(id){const l=leads.find(x=>Number(x.id)===Number(id));if(!l)return;byId('mLeadId').value=id;byId('mLead').value=(l.lead_name||'')+' - '+(l.phone||'');byId('mCurrentAgent').value=l.assigned_agent_name||'Unassigned';byId('mNewAgent').value=l.assigned_agent_id||'';byId('mNote').value='';byId('assignModal').classList.add('open')}
function closeAssignModal(){byId('assignModal').classList.remove('open')}
function openNewAgentModal(){byId('newAgentModal').classList.add('open')}
function closeNewAgentModal(){byId('newAgentModal').classList.remove('open')}
function viewLead(id){const l=leads.find(x=>Number(x.id)===Number(id));if(!l)return;const history=historyByLead[id]||[];let hHtml=history.length?history.map(h=>`<div class="history-item"><b>${esc(h.assignment_type)}</b>: ${esc(h.old_agent_name||'Unassigned')} → ${esc(h.new_agent_name||'Unassigned')}<br><small>${esc(h.assigned_at||'')} by ${esc(h.assigned_by_name||'-')}</small><br><small>${esc(h.remarks||'')}</small></div>`).join(''):'<div class="history-item">No assignment history yet.</div>';byId('viewContent').innerHTML=`<div class="detail-grid"><div class="detail-item"><div class="detail-label">Lead Name</div><div class="detail-value">${esc(l.lead_name)}</div></div><div class="detail-item"><div class="detail-label">Phone</div><div class="detail-value">${esc(l.phone||'-')}</div></div><div class="detail-item"><div class="detail-label">Source</div><div class="detail-value">${esc(l.source||'Manual')}</div></div><div class="detail-item"><div class="detail-label">Status</div><div class="detail-value">${statusBadge(l.status,l.priority)}</div></div><div class="detail-item"><div class="detail-label">Current Agent</div><div class="detail-value">${agentChip(l.assigned_agent_name)}</div></div><div class="detail-item"><div class="detail-label">Created At</div><div class="detail-value">${esc(l.created_at||'-')}</div></div><div class="detail-item full"><div class="detail-label">Requirement / Notes</div><div class="detail-value">${esc(l.requirement||l.notes||'-')}</div></div><div class="detail-item full"><div class="detail-label">Assignment History</div><div class="history-list">${hHtml}</div></div></div>`;byId('viewModal').classList.add('open')}
function closeViewModal(){byId('viewModal').classList.remove('open')}
function focusBulk(){byId('bulkAgent')?.focus();showToast('Select leads, choose agent, then click Assign Selected.')}
function showToast(msg,type='success'){const c=byId('toastContainer');const t=document.createElement('div');t.className=`toast ${type}`;t.innerHTML=`<span class="material-icons-round">${type==='success'?'check_circle':'error'}</span>${esc(msg)}`;c.appendChild(t);setTimeout(()=>{t.style.opacity='0';t.style.transition='opacity .25s';setTimeout(()=>t.remove(),250)},3000)}
['assignModal','newAgentModal','viewModal'].forEach(id=>{byId(id)?.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')})});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeAssignModal();closeNewAgentModal();closeViewModal()}});
updateSelectedInfo();
</script>
</body>
</html>
