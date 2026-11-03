<?php
/*
|--------------------------------------------------------------------------
| agent-commissions.php
| State Agency / Real Estate - Agent Commissions
|--------------------------------------------------------------------------
| Path:
| C:\xampp\htdocs\pos\public\app\views\agent-commissions.php
|
| Logic:
| Deals se agent commission auto sync hoti hai.
| Commission payments re_commission_payments me save hoti hain.
| Page batata hai: kis agent ko kis property/deal par kitni commission milni hai.
*/

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');


error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id'])) {
    die("Login required");
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['current_branch_id'] ?? 0));

// if ($client_id <= 0) {
//     die("Invalid client session");
// }

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

/* ===================== HELPERS ===================== */
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->bind_result($c);
    $stmt->fetch();
    $stmt->close();
    return (int)$c > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->bind_result($c);
    $stmt->fetch();
    $stmt->close();
    return (int)$c > 0;
}

function redirectMsg(string $type, string $msg): void {
    header("Location: agent-commissions.php?msg_type=" . urlencode($type) . "&msg=" . urlencode($msg));
    exit;
}

function moneyValue($v): float {
    return (float)str_replace([',','Rs.','PKR',' '], '', (string)$v);
}

function moneyFmt($v): string {
    return 'PKR ' . number_format((float)$v, 0);
}

function badgePayment($status): string {
    $cls = [
        'Unpaid' => 'badge-red',
        'Partial' => 'badge-orange',
        'Paid' => 'badge-green'
    ][$status] ?? 'badge-blue';
    return '<span class="badge '.$cls.'">'.e($status).'</span>';
}

function badgeCommission($status): string {
    $cls = [
        'Pending' => 'badge-yellow',
        'Approved' => 'badge-blue',
        'Released' => 'badge-green',
        'Hold' => 'badge-red',
        'Cancelled' => 'badge-red'
    ][$status] ?? 'badge-blue';
    return '<span class="badge '.$cls.'">'.e($status).'</span>';
}

function initials($name): string {
    $parts = preg_split('/\s+/', trim((string)$name));
    $out = '';
    foreach ($parts as $p) {
        if ($p !== '') $out .= mb_substr($p, 0, 1);
    }
    return strtoupper(substr($out ?: 'A', 0, 2));
}

function recalcCommission(mysqli $conn, int $commission_id, int $client_id): void {
    $stmt = $conn->prepare("
        SELECT commission_amount
        FROM re_agent_commissions
        WHERE id = ? AND client_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $commission_id, $client_id);
    $stmt->execute();
    $stmt->bind_result($commission_amount);
    if (!$stmt->fetch()) {
        $stmt->close();
        return;
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM re_commission_payments
        WHERE commission_id = ? AND client_id = ?
    ");
    $stmt->bind_param("ii", $commission_id, $client_id);
    $stmt->execute();
    $stmt->bind_result($paid_amount);
    $stmt->fetch();
    $stmt->close();

    $commission_amount = (float)$commission_amount;
    $paid_amount = (float)$paid_amount;
    $balance = max(0, $commission_amount - $paid_amount);

    if ($paid_amount <= 0) {
        $payment_status = 'Unpaid';
    } elseif ($paid_amount >= $commission_amount) {
        $payment_status = 'Paid';
    } else {
        $payment_status = 'Partial';
    }

    $status = $payment_status === 'Paid' ? 'Released' : ($payment_status === 'Partial' ? 'Approved' : 'Pending');

    $stmt = $conn->prepare("
        UPDATE re_agent_commissions
        SET paid_amount = ?, balance_amount = ?, payment_status = ?, commission_status = ?
        WHERE id = ? AND client_id = ?
    ");
    $stmt->bind_param("ddssii", $paid_amount, $balance, $payment_status, $status, $commission_id, $client_id);
    $stmt->execute();
    $stmt->close();
}

function syncCommissionsFromDeals(mysqli $conn, int $client_id, int $user_id): void {
    if (!tableExists($conn, 're_deals') || !tableExists($conn, 're_agent_commissions')) {
        return;
    }

    $hasProps = tableExists($conn, 're_properties');
    $propertyJoin = $hasProps ? "LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id" : "";
    $propertySelect = $hasProps ? "COALESCE(p.title, CONCAT('Property #', d.property_id))" : "CONCAT('Property #', d.property_id)";

    $sql = "
        SELECT
            d.id AS deal_id,
            d.branch_id,
            d.agent_id,
            d.property_id,
            d.deal_no,
            d.deal_amount,
            d.commission_percent,
            d.commission_amount,
            $propertySelect AS property_title
        FROM re_deals d
        $propertyJoin
        WHERE d.client_id = ?
          AND d.agent_id IS NOT NULL
          AND d.agent_id > 0
          AND d.commission_amount > 0
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($d = $res->fetch_assoc()) {
        $deal_id = (int)$d['deal_id'];

        $check = $conn->prepare("SELECT id FROM re_agent_commissions WHERE client_id = ? AND deal_id = ? LIMIT 1");
        $check->bind_param("ii", $client_id, $deal_id);
        $check->execute();
        $check->bind_result($existing_id);
        $exists = $check->fetch();
        $check->close();

        $branch_db = !empty($d['branch_id']) ? (int)$d['branch_id'] : null;
        $agent_id = (int)$d['agent_id'];
        $property_id = !empty($d['property_id']) ? (int)$d['property_id'] : null;
        $deal_no = (string)$d['deal_no'];
        $property_title = (string)$d['property_title'];
        $deal_amount = (float)$d['deal_amount'];
        $commission_percent = (float)$d['commission_percent'];
        $commission_amount = (float)$d['commission_amount'];

        if ($exists) {
            $up = $conn->prepare("
                UPDATE re_agent_commissions
                SET branch_id=?, agent_id=?, property_id=?, deal_no=?, property_title=?,
                    deal_amount=?, commission_percent=?, commission_amount=?
                WHERE id=? AND client_id=?
            ");
            $up->bind_param(
                "iiissdddii",
                $branch_db,
                $agent_id,
                $property_id,
                $deal_no,
                $property_title,
                $deal_amount,
                $commission_percent,
                $commission_amount,
                $existing_id,
                $client_id
            );
            $up->execute();
            $up->close();
            recalcCommission($conn, (int)$existing_id, $client_id);
        } else {
            $paid = 0.00;
            $balance = $commission_amount;
            $payment_status = 'Unpaid';
            $commission_status = 'Pending';

            $ins = $conn->prepare("
                INSERT INTO re_agent_commissions
                (
                    client_id, branch_id, deal_id, agent_id, property_id, deal_no, property_title,
                    deal_amount, commission_percent, commission_amount, paid_amount, balance_amount,
                    payment_status, commission_status, created_by
                )
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->bind_param(
                "iiiiiissddddssi",
                $client_id,
                $branch_db,
                $deal_id,
                $agent_id,
                $property_id,
                $deal_no,
                $property_title,
                $deal_amount,
                $commission_percent,
                $commission_amount,
                $paid,
                $balance,
                $payment_status,
                $commission_status,
                $user_id
            );
            $ins->execute();
            $newId = (int)$ins->insert_id;
            $ins->close();
            recalcCommission($conn, $newId, $client_id);
        }
    }
    $stmt->close();
}

/* ===================== REQUIRED TABLES ===================== */
if (!tableExists($conn, 're_agent_commissions') || !tableExists($conn, 're_commission_payments')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>Commission tables missing</h3>
            <p>Pehle <b>re_agent_commissions_schema.sql</b> phpMyAdmin me run karo.</p>
        </div>
    ");
}

syncCommissionsFromDeals($conn, $client_id, $user_id);

/* ===================== POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_payment') {
        $commission_id = (int)($_POST['commission_id'] ?? 0);
        $amount = moneyValue($_POST['amount'] ?? 0);
        $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $method = trim($_POST['method'] ?? 'Cash');
        $reference_no = trim($_POST['reference_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($commission_id <= 0) redirectMsg('danger', 'Commission record select karo.');
        if ($amount <= 0) redirectMsg('danger', 'Payment amount required hai.');

        $stmt = $conn->prepare("
            SELECT balance_amount
            FROM re_agent_commissions
            WHERE id = ? AND client_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $commission_id, $client_id);
        $stmt->execute();
        $stmt->bind_result($balance_amount);
        if (!$stmt->fetch()) {
            $stmt->close();
            redirectMsg('danger', 'Commission record not found.');
        }
        $stmt->close();

        if ($amount > (float)$balance_amount) {
            redirectMsg('danger', 'Payment amount balance se zyada nahi ho sakti.');
        }

        $stmt = $conn->prepare("
            INSERT INTO re_commission_payments
            (client_id, commission_id, amount, payment_date, method, reference_no, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param("iidssssi", $client_id, $commission_id, $amount, $payment_date, $method, $reference_no, $notes, $user_id);
        $stmt->execute();
        $stmt->close();

        recalcCommission($conn, $commission_id, $client_id);
        redirectMsg('success', 'Commission payment save ho gayi.');
    }

    if ($action === 'update_status') {
        $commission_id = (int)($_POST['commission_id'] ?? 0);
        $commission_status = trim($_POST['commission_status'] ?? 'Pending');
        $allowed = ['Pending','Approved','Released','Hold','Cancelled'];
        if (!in_array($commission_status, $allowed, true)) $commission_status = 'Pending';

        $stmt = $conn->prepare("
            UPDATE re_agent_commissions
            SET commission_status = ?
            WHERE id = ? AND client_id = ?
        ");
        $stmt->bind_param("sii", $commission_status, $commission_id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirectMsg('success', 'Commission status update ho gaya.');
    }

    if ($action === 'delete_payment') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT commission_id
            FROM re_commission_payments
            WHERE id = ? AND client_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $payment_id, $client_id);
        $stmt->execute();
        $stmt->bind_result($commission_id);
        if (!$stmt->fetch()) {
            $stmt->close();
            redirectMsg('danger', 'Payment not found.');
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM re_commission_payments WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $payment_id, $client_id);
        $stmt->execute();
        $stmt->close();

        recalcCommission($conn, (int)$commission_id, $client_id);
        redirectMsg('success', 'Payment delete ho gayi.');
    }
}

/* ===================== FILTERS ===================== */
$search = trim($_GET['search'] ?? '');
$agentFilter = (int)($_GET['agent_id'] ?? 0);
$paymentStatus = trim($_GET['payment_status'] ?? '');
$commissionStatus = trim($_GET['commission_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
$perPage = in_array($perPage, [10,20,50,100], true) ? $perPage : 10;
$offset = ($page - 1) * $perPage;

$where = " WHERE ac.client_id = ? ";
$params = [$client_id];
$types = "i";

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR ac.property_title LIKE ? OR ac.deal_no LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like);
    $types .= "sss";
}
if ($agentFilter > 0) {
    $where .= " AND ac.agent_id = ?";
    $params[] = $agentFilter;
    $types .= "i";
}
if ($paymentStatus !== '') {
    $where .= " AND ac.payment_status = ?";
    $params[] = $paymentStatus;
    $types .= "s";
}
if ($commissionStatus !== '') {
    $where .= " AND ac.commission_status = ?";
    $params[] = $commissionStatus;
    $types .= "s";
}
if ($dateFrom !== '') {
    $where .= " AND DATE(ac.created_at) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}
if ($dateTo !== '') {
    $where .= " AND DATE(ac.created_at) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

/* ===================== EXPORT ===================== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "
        SELECT ac.*, u.name AS agent_name
        FROM re_agent_commissions ac
        LEFT JOIN users u ON u.id = ac.agent_id
        $where
        ORDER BY ac.id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=agent_commissions_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Agent Name','Property','Deal No','Deal Amount','Commission %','Commission Amount','Paid Amount','Balance','Payment Status','Commission Status']);

    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['agent_name'],
            $r['property_title'],
            $r['deal_no'],
            $r['deal_amount'],
            $r['commission_percent'],
            $r['commission_amount'],
            $r['paid_amount'],
            $r['balance_amount'],
            $r['payment_status'],
            $r['commission_status']
        ]);
    }
    fclose($out);
    exit;
}

/* ===================== AGENTS ===================== */
$agents = [];
if (tableExists($conn, 'roles')) {
    $stmt = $conn->prepare("
        SELECT u.id, u.name, COALESCE(r.name,'') AS role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.client_id = ?
          AND u.status = 'active'
          AND (u.role_id = 2 OR LOWER(COALESCE(r.name,'')) IN ('agent','staff','employee','sales agent','real estate agent','property agent'))
        ORDER BY u.name ASC
    ");
} else {
    $stmt = $conn->prepare("
        SELECT id, name, '' AS role_name
        FROM users
        WHERE client_id = ? AND status = 'active' AND role_id = 2
        ORDER BY name ASC
    ");
}
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $agents[] = $row;
$stmt->close();

/* ===================== COUNTS + DATA ===================== */
$countSql = "
    SELECT COUNT(*)
    FROM re_agent_commissions ac
    LEFT JOIN users u ON u.id = ac.agent_id
    $where
";
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

$sql = "
    SELECT
        ac.*,
        u.name AS agent_name,
        u.email AS agent_email,
        u.phone AS agent_phone,
        d.deal_date
    FROM re_agent_commissions ac
    LEFT JOIN users u ON u.id = ac.agent_id
    LEFT JOIN re_deals d ON d.id = ac.deal_id AND d.client_id = ac.client_id
    $where
    ORDER BY ac.id DESC
    LIMIT ? OFFSET ?
";
$paramsPage = array_merge($params, [$perPage, $offset]);
$typesPage = $types . "ii";
$stmt = $conn->prepare($sql);
$stmt->bind_param($typesPage, ...$paramsPage);
$stmt->execute();
$res = $stmt->get_result();
$records = [];
while ($row = $res->fetch_assoc()) $records[] = $row;
$stmt->close();

$summarySql = "
    SELECT
        COUNT(*) AS total_records,
        COALESCE(SUM(ac.commission_amount),0) AS total_commission,
        COALESCE(SUM(ac.paid_amount),0) AS total_paid,
        COALESCE(SUM(ac.balance_amount),0) AS total_balance,
        SUM(CASE WHEN ac.payment_status='Partial' THEN 1 ELSE 0 END) AS partial_count,
        SUM(CASE WHEN ac.payment_status='Paid' THEN 1 ELSE 0 END) AS paid_count
    FROM re_agent_commissions ac
    LEFT JOIN users u ON u.id = ac.agent_id
    $where
";
$stmt = $conn->prepare($summarySql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$commissionOptions = [];
$stmt = $conn->prepare("
    SELECT ac.id, ac.deal_no, ac.property_title, ac.commission_amount, ac.paid_amount, ac.balance_amount, u.name AS agent_name
    FROM re_agent_commissions ac
    LEFT JOIN users u ON u.id = ac.agent_id
    WHERE ac.client_id = ? AND ac.balance_amount > 0
    ORDER BY ac.id DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $commissionOptions[] = $row;
$stmt->close();

$recordsJson = json_encode($records, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$queryBase = $_GET;
unset($queryBase['page']);
function pageUrl($p, $queryBase) {
    $queryBase['page'] = $p;
    return 'agent-commissions.php?' . http_build_query($queryBase);
}

$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Agent Commissions – ONE ERP Real Estate</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php include('partials/head.php'); ?>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet" />

    <style>
    :root {
        --bg: #eef2f7;
        --card: #fff;
        --border: #e0e5f2;
        --text: #1f2736;
        --muted: #64748b;
        --gold: #c9a84c;
        --gold2: #d8a500;
        --green: #3e7a3a;
        --orange: #dc9b2d;
        --red: #c94b57;
        --shadow: 0 4px 20px rgba(0, 0, 0, .05);
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0
    }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--bg);
        color: var(--text);
        overflow-x: hidden;
    }

    a {
        text-decoration: none;
        color: inherit
    }

    .container-scroll {
        display: flex;
        min-height: 100vh;
        background: var(--bg);
    }

    .sidebar {
        width: 270px;
        flex-shrink: 0
    }

    .main-panel {
        flex: 1;
        min-width: 0;
        background: var(--bg);
        display: flex;
        flex-direction: column
    }

    .topbar {
        min-height: 70px;
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center
    }

    .content-wrapper {
        padding: 24px;
        flex: 1
    }

    .page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        margin-bottom: 22px;
        gap: 16px;
        flex-wrap: wrap
    }

    .page-title {
        font-size: 24px;
        font-weight: 900;
        color: #243046
    }

    .breadcrumb-custom {
        display: flex;
        gap: 5px;
        margin-top: 5px;
        font-size: 12px;
        color: #9ca3af
    }

    .breadcrumb-custom .active {
        color: var(--gold);
        font-weight: 800
    }

    .page-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 42px;
        padding: 0 16px;
        border-radius: 10px;
        border: none;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
        font-family: inherit;
        white-space: nowrap
    }

    .btn .material-icons-round {
        font-size: 17px
    }

    .btn-primary {
        background: linear-gradient(180deg, #f5c742, #d8a500);
        color: #111;
        box-shadow: 0 4px 12px rgba(216, 165, 0, .25)
    }

    .btn-outline {
        background: #fff;
        border: 1px solid var(--border);
        color: #2b3674
    }

    .btn-danger {
        background: linear-gradient(180deg, #c94b57, #9f2430);
        color: #fff
    }

    .btn-sm {
        height: 32px;
        padding: 0 11px;
        font-size: 12px
    }

    .alert {
        padding: 12px 14px;
        border-radius: 12px;
        margin-bottom: 18px;
        font-weight: 750
    }

    .alert-success {
        background: #dcfce7;
        color: #14532d
    }

    .alert-danger {
        background: #fee2e2;
        color: #7f1d1d
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 14px;
        margin-bottom: 20px
    }

    .stat-card {
        min-height: 112px;
        border-radius: 14px;
        padding: 18px 16px;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 14px 28px rgba(0, 0, 0, .12)
    }

    .stat-card:after {
        content: '';
        position: absolute;
        width: 110px;
        height: 110px;
        border-radius: 50%;
        right: -18px;
        top: -18px;
        background: rgba(255, 255, 255, .12)
    }

    .stat-card:nth-child(1) {
        background: linear-gradient(135deg, #9f2430, #c94b57)
    }

    .stat-card:nth-child(2) {
        background: linear-gradient(135deg, #b27b16, #d8a33a)
    }

    .stat-card:nth-child(3) {
        background: linear-gradient(135deg, #386c58, #4c9075)
    }

    .stat-card:nth-child(4) {
        background: linear-gradient(135deg, #111c44, #20346d)
    }

    .stat-card:nth-child(5) {
        background: linear-gradient(135deg, #4c2f75, #7a4cb0)
    }

    .stat-label {
        font-size: 12px;
        font-weight: 800;
        color: rgba(255, 255, 255, .88);
        position: relative;
        z-index: 1
    }

    .stat-value {
        font-size: 24px;
        font-weight: 900;
        margin-top: 10px;
        position: relative;
        z-index: 1
    }

    .stat-value.small {
        font-size: 17px
    }

    .stat-helper {
        font-size: 11px;
        color: rgba(255, 255, 255, .84);
        margin-top: 4px;
        position: relative;
        z-index: 1
    }

    .filter-card,
    .table-card,
    .summary-card {
        background: #fff;
        border: 1px solid #eef2f7;
        border-radius: 16px;
        box-shadow: var(--shadow)
    }

    .filter-card {
        padding: 16px 20px;
        margin-bottom: 22px
    }

    .filter-row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: flex-end
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
        flex: 1;
        min-width: 130px
    }

    .filter-group.search-wide {
        flex: 2;
        min-width: 220px
    }

    .filter-group label {
        font-size: 11px;
        font-weight: 850;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .45px
    }

    .filter-group input,
    .filter-group select {
        height: 42px;
        background: #f4f7fe;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 8px 10px;
        font-size: 12.5px;
        color: #2b3674;
        font-weight: 700;
        outline: none
    }

    .filter-group input:focus,
    .filter-group select:focus {
        border-color: var(--gold);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(201, 168, 76, .12)
    }

    .filter-actions {
        display: flex;
        gap: 8px
    }

    .table-card {
        overflow: hidden;
        margin-bottom: 18px
    }

    .table-head {
        padding: 16px 20px;
        border-bottom: 1px solid #edf2f7;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap
    }

    .table-title {
        font-size: 18px;
        font-weight: 900;
        color: #1a202c
    }

    .table-count {
        background: #fff7df;
        color: #8a6a20;
        border-radius: 20px;
        padding: 3px 10px;
        font-size: 11.5px;
        font-weight: 900
    }

    .table-wrap {
        overflow-x: auto
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1250px
    }

    thead th {
        background: #f8fafc;
        color: #64748b;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        padding: 14px 12px;
        text-align: left;
        border-bottom: 2px solid #edf2f7;
        white-space: nowrap
    }

    tbody td {
        padding: 14px 12px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
        color: #334155;
        vertical-align: middle;
        white-space: nowrap
    }

    tbody tr:hover td {
        background: #fbfcfe
    }

    .agent-cell {
        display: flex;
        align-items: center;
        gap: 10px
    }

    .agent-avatar {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        background: linear-gradient(135deg, #c9a84c, #e8c96d);
        color: #111;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 12px
    }

    .agent-name {
        font-weight: 900;
        color: #1e293b
    }

    .agent-sub {
        font-size: 11px;
        color: #94a3b8;
        margin-top: 2px
    }

    .property-cell {
        font-weight: 750;
        color: #1e293b;
        max-width: 230px;
        white-space: normal;
        line-height: 1.35
    }

    .deal-no {
        font-weight: 900;
        color: #8a6a20;
        background: #fff7df;
        padding: 4px 9px;
        border-radius: 8px;
        font-size: 12px
    }

    .amount-text {
        font-weight: 900;
        color: #1e293b
    }

    .paid-text {
        font-weight: 900;
        color: #14532d
    }

    .balance-text {
        font-weight: 900;
        color: #9a3412
    }

    .percent-text {
        font-weight: 900;
        color: #8a6a20
    }

    .badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11.5px;
        font-weight: 900;
        white-space: nowrap
    }

    .badge:before {
        content: '';
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: currentColor;
        margin-right: 6px
    }

    .badge-green {
        background: #dcfce7;
        color: #14532d
    }

    .badge-blue {
        background: #dbeafe;
        color: #1e40af
    }

    .badge-orange {
        background: #ffedd5;
        color: #9a3412
    }

    .badge-yellow {
        background: #fef9c3;
        color: #854d0e
    }

    .badge-red {
        background: #fee2e2;
        color: #7f1d1d
    }

    .action-btns {
        display: flex;
        gap: 5px
    }

    .action-btn {
        width: 30px;
        height: 30px;
        border-radius: 7px;
        border: none;
        background: transparent;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center
    }

    .action-btn .material-icons-round {
        font-size: 17px
    }

    .action-btn.view {
        color: #3b6cf4
    }

    .action-btn.pay {
        color: #3e7a3a
    }

    .action-btn.edit {
        color: #d8a500
    }

    .action-btn.del {
        color: #c94b57
    }

    .action-btn:hover {
        background: #f8fafc
    }

    .pagination-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 13px 20px;
        border-top: 1px solid #edf2f7;
        gap: 12px;
        flex-wrap: wrap
    }

    .pagination-info {
        font-size: 12.5px;
        color: #64748b
    }

    .pagination-controls {
        display: flex;
        gap: 5px
    }

    .page-btn {
        min-width: 32px;
        height: 32px;
        padding: 0 8px;
        border-radius: 6px;
        border: 1px solid var(--border);
        background: #fff;
        color: #64748b;
        font-size: 12.5px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center
    }

    .page-btn.active {
        background: var(--gold2);
        border-color: var(--gold2);
        color: #fff
    }

    .page-btn.disabled {
        opacity: .45;
        pointer-events: none
    }

    .summary-label {
        font-size: 11px;
        font-weight: 900;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .5px;
        margin-bottom: 6px
    }

    .summary-value {
        font-size: 18px;
        font-weight: 900;
        color: #1e293b
    }

    .summary-value.green {
        color: #14532d
    }

    .summary-value.orange {
        color: #9a3412
    }

    .summary-value.purple {
        color: #8a6a20
    }

    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .58);
        backdrop-filter: blur(5px);
        z-index: 999999;
        align-items: flex-start;
        justify-content: center;
        padding: 30px 16px;
        overflow-y: auto
    }

    .modal-overlay.open {
        display: flex !important
    }

    .erp-modal {
        display: block !important;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, .25);
        width: 100%;
        max-width: 720px;
        overflow: hidden;
        margin: auto 0
    }

    .modal-header {
        height: 70px;
        padding: 0 24px;
        background: linear-gradient(135deg, #111, #1a1a1a);
        display: flex;
        align-items: center;
        justify-content: space-between
    }

    .modal-title {
        font-size: 16px;
        font-weight: 900;
        color: #fff
    }

    .modal-close {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: rgba(255, 255, 255, .08);
        border: 1px solid rgba(255, 255, 255, .12);
        color: #aaa;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer
    }

    .modal-body {
        padding: 22px 24px;
        max-height: calc(100vh - 220px);
        overflow-y: auto
    }

    .modal-footer {
        padding: 15px 24px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        background: #fff
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px 14px
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 7px
    }

    .form-group.full {
        grid-column: 1/-1
    }

    .form-group label {
        font-size: 11.5px;
        font-weight: 900;
        color: #374151;
        text-transform: uppercase;
        letter-spacing: .35px
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        height: 40px;
        border: 1.5px solid #e5e7eb;
        border-radius: 9px;
        background: #fafafa;
        color: #111827;
        font-size: 13px;
        padding: 0 12px;
        outline: none
    }

    .form-group textarea {
        height: 78px;
        padding: 10px 12px;
        resize: vertical
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: var(--gold);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(201, 168, 76, .12)
    }

    .view-detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px
    }

    .view-detail-item {
        background: #f8fafc;
        border: 1px solid #edf2f7;
        border-radius: 10px;
        padding: 12px
    }

    .view-detail-item.full {
        grid-column: 1/-1
    }

    .view-detail-item label {
        font-size: 10.5px;
        font-weight: 900;
        color: #94a3b8;
        text-transform: uppercase;
        display: block;
        margin-bottom: 4px
    }

    .view-detail-item span {
        font-size: 13.5px;
        font-weight: 800;
        color: #1e293b
    }

    .empty-state {
        text-align: center;
        padding: 48px 20px;
        color: #94a3b8
    }
    
/* =====================================================
   AGENT-COMMISSIONS.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
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

.breadcrumb-custom,
.breadcrumb-custom a{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.breadcrumb-custom .active{
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
.stat-card:nth-child(2){background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.stat-card:nth-child(3){background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-card:nth-child(4){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.stat-card:nth-child(5){background:linear-gradient(135deg,#be123c,#ef4444) !important;}

.stat-label{
    font-size:14px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
    position:relative;
    z-index:2;
}

.stat-value{
    font-size:30px !important;
    font-weight:900 !important;
    color:#fff !important;
    position:relative;
    z-index:2;
}

.stat-value.small{
    font-size:18px !important;
    line-height:1.3 !important;
}

.stat-helper{
    color:rgba(255,255,255,.82) !important;
    font-weight:700 !important;
    position:relative;
    z-index:2;
}

/* Glass Cards */
.filter-card,
.table-card,
.summary-card{
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
.table-card:hover,
.summary-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filter-card{
    padding:16px !important;
    margin-bottom:22px !important;
}

.table-card{
    overflow:hidden !important;
}

/* Filters */
.filter-group label{
    color:#f8fafc !important;
    font-weight:900 !important;
    font-size:12px !important;
}

.filter-group input,
.filter-group select{
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

/* Table */
.table-head{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

.table-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.table-count{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
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
.empty-state{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

/* Table text */
.agent-avatar{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 10px 22px rgba(109,76,255,.24) !important;
}

.agent-name,
.property-cell,
.amount-text,
.summary-value{
    color:#f8fafc !important;
}

.agent-sub,
.pagination-info,
.summary-label{
    color:#94a3b8 !important;
}

.deal-no{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
}

.paid-text,
.summary-value.green{
    color:#4ade80 !important;
}

.balance-text,
.summary-value.orange{
    color:#fbbf24 !important;
}

.percent-text,
.summary-value.purple{
    color:#c4b5fd !important;
}

/* Badges */
.badge{
    border:1px solid rgba(148,163,184,.18) !important;
}

.badge-green{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

.badge-blue{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border-color:rgba(59,130,246,.24) !important;
}

.badge-orange,
.badge-yellow{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.badge-red{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
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
.action-btn.pay{color:#4ade80 !important;}
.action-btn.edit{color:#fbbf24 !important;}
.action-btn.del{color:#f87171 !important;}

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



/* =====================================================
   AGENT COMMISSIONS POPUPS - DARK PURPLE GLASS COMPACT
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
#paymentModal .erp-modal,
#statusModal .erp-modal,
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

#paymentModal .erp-modal{
    max-width:720px !important;
}

#statusModal .erp-modal{
    max-width:460px !important;
}

#viewModal .erp-modal{
    max-width:650px !important;
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

/* View detail cards */
.view-detail-grid{
    color:#ffffff !important;
}

.view-detail-item{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

.view-detail-item label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

.view-detail-item span,
.view-detail-item span[style]{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.view-detail-item span[style*="14532d"]{
    color:#4ade80 !important;
}

.view-detail-item span[style*="9a3412"]{
    color:#fbbf24 !important;
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
    .form-grid,
    .view-detail-grid{
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
                <?php if ($msg !== ''): ?>
                <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
                <?php endif; ?>

                <div class="page-header">
                    <div>
                        <div class="page-title">Agent Commissions</div>
                        <div class="breadcrumb-custom">
                            <a href="dashboard.php">Home</a><span>›</span><a href="agency-lead.php">Real
                                Estate</a><span>›</span><span class="active">Agent Commissions</span>
                        </div>
                    </div>
                    <div class="page-actions">
                        <a class="btn btn-outline"
                            href="agent-commissions.php?<?= e(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
                            <span class="material-icons-round">download</span> Export
                        </a>
                        <button class="btn btn-primary" type="button" onclick="openPaymentModal()">
                            <span class="material-icons-round">payments</span> Add Payment
                        </button>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Records</div>
                        <div class="stat-value"><?= (int)$summary['total_records'] ?></div>
                        <div class="stat-helper">All agent commissions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Total Commission</div>
                        <div class="stat-value small"><?= e(moneyFmt($summary['total_commission'])) ?></div>
                        <div class="stat-helper">Calculated from deals</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Total Paid</div>
                        <div class="stat-value small"><?= e(moneyFmt($summary['total_paid'])) ?></div>
                        <div class="stat-helper">Released to agents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Balance Payable</div>
                        <div class="stat-value small"><?= e(moneyFmt($summary['total_balance'])) ?></div>
                        <div class="stat-helper">Remaining commission</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Paid / Partial</div>
                        <div class="stat-value"><?= (int)$summary['paid_count'] ?>/<?= (int)$summary['partial_count'] ?>
                        </div>
                        <div class="stat-helper">Paid vs partial</div>
                    </div>
                </div>

                <form class="filter-card" method="GET" action="agent-commissions.php">
                    <div class="filter-row">
                        <div class="filter-group search-wide">
                            <label>Search</label>
                            <input type="text" name="search" placeholder="Agent, property, deal no..."
                                value="<?= e($search) ?>">
                        </div>
                        <div class="filter-group">
                            <label>Agent</label>
                            <select name="agent_id">
                                <option value="">All Agents</option>
                                <?php foreach ($agents as $a): ?>
                                <option value="<?= (int)$a['id'] ?>"
                                    <?= $agentFilter === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Payment Status</label>
                            <select name="payment_status">
                                <option value="">All Payment</option>
                                <?php foreach (['Unpaid','Partial','Paid'] as $s): ?>
                                <option value="<?= e($s) ?>" <?= $paymentStatus === $s ? 'selected' : '' ?>><?= e($s) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Commission Status</label>
                            <select name="commission_status">
                                <option value="">All Status</option>
                                <?php foreach (['Pending','Approved','Released','Hold','Cancelled'] as $s): ?>
                                <option value="<?= e($s) ?>" <?= $commissionStatus === $s ? 'selected' : '' ?>>
                                    <?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group"><label>From Date</label><input type="date" name="date_from"
                                value="<?= e($dateFrom) ?>"></div>
                        <div class="filter-group"><label>To Date</label><input type="date" name="date_to"
                                value="<?= e($dateTo) ?>"></div>
                        <div class="filter-group" style="max-width:100px;">
                            <label>Rows</label>
                            <select name="per_page">
                                <?php foreach ([10,20,50,100] as $pp): ?>
                                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button class="btn btn-primary" type="submit"><span
                                    class="material-icons-round">filter_list</span> Filter</button>
                            <a class="btn btn-outline" href="agent-commissions.php"><span
                                    class="material-icons-round">refresh</span> Reset</a>
                        </div>
                    </div>
                </form>

                <div class="table-card">
                    <div class="table-head">
                        <div><span class="table-title">Agent Commission Records</span> <span
                                class="table-count"><?= (int)$totalFiltered ?></span></div>
                        <div style="font-size:12px;color:#94a3b8;font-weight:800;">Data Source: re_agent_commissions +
                            re_commission_payments</div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Agent Name</th>
                                    <th>Property</th>
                                    <th>Deal No</th>
                                    <th>Deal Amount</th>
                                    <th>Commission %</th>
                                    <th>Commission Amount</th>
                                    <th>Paid Amount</th>
                                    <th>Balance</th>
                                    <th>Payment Status</th>
                                    <th>Commission Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="12">
                                        <div class="empty-state"><span class="material-icons-round"
                                                style="font-size:48px;color:#d1d5db;display:block;margin-bottom:12px;">search_off</span>No
                                            commission records found.</div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($records as $i => $r): ?>
                                <tr>
                                    <td style="color:#94a3b8;font-size:12px;font-weight:900;">
                                        <?= (int)($offset + $i + 1) ?></td>
                                    <td>
                                        <div class="agent-cell">
                                            <div class="agent-avatar"><?= e(initials($r['agent_name'] ?? 'A')) ?></div>
                                            <div>
                                                <div class="agent-name"><?= e($r['agent_name'] ?: 'Unknown Agent') ?>
                                                </div>
                                                <div class="agent-sub"><?= e($r['agent_phone'] ?: 'Sales Agent') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="property-cell"><?= e($r['property_title']) ?></div>
                                    </td>
                                    <td><span class="deal-no"><?= e($r['deal_no']) ?></span></td>
                                    <td><span class="amount-text"><?= e(moneyFmt($r['deal_amount'])) ?></span></td>
                                    <td><span class="percent-text"><?= e((float)$r['commission_percent']) ?>%</span>
                                    </td>
                                    <td><span class="amount-text"><?= e(moneyFmt($r['commission_amount'])) ?></span>
                                    </td>
                                    <td><span class="paid-text"><?= e(moneyFmt($r['paid_amount'])) ?></span></td>
                                    <td><span class="balance-text"><?= e(moneyFmt($r['balance_amount'])) ?></span></td>
                                    <td><?= badgePayment($r['payment_status']) ?></td>
                                    <td><?= badgeCommission($r['commission_status']) ?></td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="action-btn view" type="button"
                                                onclick="viewRecord(<?= (int)$r['id'] ?>)" title="View"><span
                                                    class="material-icons-round">visibility</span></button>
                                            <?php if ((float)$r['balance_amount'] > 0): ?>
                                            <button class="action-btn pay" type="button"
                                                onclick="openPaymentModal(<?= (int)$r['id'] ?>)"
                                                title="Add Payment"><span
                                                    class="material-icons-round">payments</span></button>
                                            <?php endif; ?>
                                            <button class="action-btn edit" type="button"
                                                onclick="openStatusModal(<?= (int)$r['id'] ?>)" title="Status"><span
                                                    class="material-icons-round">edit</span></button>
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
                            Showing <?= $totalFiltered > 0 ? (int)$offset + 1 : 0 ?> to
                            <?= min($offset + $perPage, (int)$totalFiltered) ?> of <?= (int)$totalFiltered ?> entries
                        </div>
                        <div class="pagination-controls">
                            <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                                href="<?= e(pageUrl($page - 1, $queryBase)) ?>">‹</a>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php if ($p === 1 || $p === $totalPages || abs($p - $page) <= 1): ?>
                            <a class="page-btn <?= $p === $page ? 'active' : '' ?>"
                                href="<?= e(pageUrl($p, $queryBase)) ?>"><?= (int)$p ?></a>
                            <?php elseif (abs($p - $page) === 2): ?><span
                                style="padding:0 4px;color:#94a3b8;">...</span><?php endif; ?>
                            <?php endfor; ?>
                            <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                href="<?= e(pageUrl($page + 1, $queryBase)) ?>">›</a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- PAYMENT MODAL -->
    <div class="modal-overlay" id="paymentModal">
        <div class="erp-modal">
            <div class="modal-header">
                <div class="modal-title">Add Commission Payment</div>
                <button class="modal-close" type="button" onclick="closePaymentModal()"><span
                        class="material-icons-round">close</span></button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="add_payment">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Commission Record *</label>
                            <select name="commission_id" id="payCommission" required onchange="fillPaymentInfo()">
                                <option value="">Select commission / deal</option>
                                <?php foreach ($commissionOptions as $o): ?>
                                <option value="<?= (int)$o['id'] ?>" data-balance="<?= e($o['balance_amount']) ?>"
                                    data-agent="<?= e($o['agent_name']) ?>"
                                    data-property="<?= e($o['property_title']) ?>" data-deal="<?= e($o['deal_no']) ?>">
                                    <?= e(($o['agent_name'] ?: 'Agent') . ' | ' . $o['deal_no'] . ' | Balance ' . moneyFmt($o['balance_amount'])) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Agent</label>
                            <input type="text" id="payAgent" readonly placeholder="Auto">
                        </div>
                        <div class="form-group">
                            <label>Deal No</label>
                            <input type="text" id="payDeal" readonly placeholder="Auto">
                        </div>
                        <div class="form-group full">
                            <label>Property</label>
                            <input type="text" id="payProperty" readonly placeholder="Auto">
                        </div>
                        <div class="form-group">
                            <label>Balance</label>
                            <input type="text" id="payBalance" readonly placeholder="Auto">
                        </div>
                        <div class="form-group">
                            <label>Paid Amount *</label>
                            <input type="text" name="amount" id="payAmount" required placeholder="50000">
                        </div>
                        <div class="form-group">
                            <label>Payment Date</label>
                            <input type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Method</label>
                            <select name="method">
                                <option>Cash</option>
                                <option>Bank Transfer</option>
                                <option>Cheque</option>
                                <option>JazzCash</option>
                                <option>EasyPaisa</option>
                                <option>Other</option>
                            </select>
                        </div>
                        <div class="form-group full">
                            <label>Reference No</label>
                            <input type="text" name="reference_no" placeholder="Bank ref / cheque no">
                        </div>
                        <div class="form-group full">
                            <label>Notes</label>
                            <textarea name="notes" placeholder="Payment remarks..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" type="button" onclick="closePaymentModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit"><span class="material-icons-round">save</span> Save
                        Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- STATUS MODAL -->
    <div class="modal-overlay" id="statusModal">
        <div class="erp-modal" style="max-width:460px;">
            <div class="modal-header">
                <div class="modal-title">Update Commission Status</div>
                <button class="modal-close" type="button" onclick="closeStatusModal()"><span
                        class="material-icons-round">close</span></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="commission_id" id="statusCommissionId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Commission Status</label>
                        <select name="commission_status" id="statusValue">
                            <option>Pending</option>
                            <option>Approved</option>
                            <option>Released</option>
                            <option>Hold</option>
                            <option>Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" type="button" onclick="closeStatusModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- VIEW MODAL -->
    <div class="modal-overlay" id="viewModal">
        <div class="erp-modal" style="max-width:650px;">
            <div class="modal-header">
                <div class="modal-title">Commission Details</div>
                <button class="modal-close" type="button" onclick="closeViewModal()"><span
                        class="material-icons-round">close</span></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer"><button class="btn btn-outline" type="button"
                    onclick="closeViewModal()">Close</button></div>
        </div>
    </div>

    <script>
    const records = <?= $recordsJson ?: '[]' ?>;

    function byId(id) {
        return document.getElementById(id);
    }

    function moneyFmt(n) {
        return 'PKR ' + Number(n || 0).toLocaleString('en-PK');
    }

    function esc(s) {
        return String(s || '').replace(/[&<>'"]/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        } [c]));
    }

    function openPaymentModal(id = null) {
        byId('paymentModal').classList.add('open');
        if (id) {
            byId('payCommission').value = id;
            fillPaymentInfo();
        }
    }

    function closePaymentModal() {
        byId('paymentModal').classList.remove('open');
    }

    function fillPaymentInfo() {
        const opt = byId('payCommission').selectedOptions[0];
        if (!opt) return;
        byId('payAgent').value = opt.dataset.agent || '';
        byId('payDeal').value = opt.dataset.deal || '';
        byId('payProperty').value = opt.dataset.property || '';
        byId('payBalance').value = moneyFmt(opt.dataset.balance || 0);
    }

    function openStatusModal(id) {
        const r = records.find(x => Number(x.id) === Number(id));
        byId('statusCommissionId').value = id;
        byId('statusValue').value = r ? r.commission_status : 'Pending';
        byId('statusModal').classList.add('open');
    }

    function closeStatusModal() {
        byId('statusModal').classList.remove('open');
    }

    function viewRecord(id) {
        const r = records.find(x => Number(x.id) === Number(id));
        if (!r) return;

        byId('viewModalBody').innerHTML = `
    <div class="view-detail-grid">
      <div class="view-detail-item"><label>Agent Name</label><span>${esc(r.agent_name || 'Unknown Agent')}</span></div>
      <div class="view-detail-item"><label>Deal No</label><span>${esc(r.deal_no)}</span></div>
      <div class="view-detail-item full"><label>Property</label><span>${esc(r.property_title)}</span></div>
      <div class="view-detail-item"><label>Deal Amount</label><span>${moneyFmt(r.deal_amount)}</span></div>
      <div class="view-detail-item"><label>Commission %</label><span>${esc(r.commission_percent)}%</span></div>
      <div class="view-detail-item"><label>Commission Amount</label><span>${moneyFmt(r.commission_amount)}</span></div>
      <div class="view-detail-item"><label>Paid Amount</label><span style="color:#14532d;">${moneyFmt(r.paid_amount)}</span></div>
      <div class="view-detail-item"><label>Balance</label><span style="color:#9a3412;">${moneyFmt(r.balance_amount)}</span></div>
      <div class="view-detail-item"><label>Payment Status</label><span>${esc(r.payment_status)}</span></div>
      <div class="view-detail-item"><label>Commission Status</label><span>${esc(r.commission_status)}</span></div>
      <div class="view-detail-item full"><label>Created</label><span>${esc(r.created_at || '-')}</span></div>
    </div>`;
        byId('viewModal').classList.add('open');
    }

    function closeViewModal() {
        byId('viewModal').classList.remove('open');
    }

    ['paymentModal', 'statusModal', 'viewModal'].forEach(id => {
        byId(id)?.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('open');
        });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closePaymentModal();
            closeStatusModal();
            closeViewModal();
        }
    });
    </script>
</body>

</html>