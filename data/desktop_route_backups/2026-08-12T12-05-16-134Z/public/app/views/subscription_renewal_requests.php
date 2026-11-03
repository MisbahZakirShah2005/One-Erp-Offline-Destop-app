<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function ar_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ar_date($value) {
    $value = trim((string)$value);
    if ($value === '') return '—';
    $time = strtotime($value);
    return $time ? date('d M Y, h:i A', $time) : $value;
}

/*
|--------------------------------------------------------------------------
| Exact ONEERP admin guard
|--------------------------------------------------------------------------
| Current admin record:
| users.id = 43, role_id = 1, client_id = NULL, status = active
|--------------------------------------------------------------------------
*/
$allowedAdminId = 43;
$loggedUserId   = (int)($_SESSION['user_id'] ?? 0);

if ($loggedUserId <= 0) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$adminStmt = $conn->prepare(
    "SELECT id, name, email
     FROM users
     WHERE id = ?
       AND id = ?
       AND role_id = 1
       AND client_id IS NULL
       AND status = 'active'
     LIMIT 1"
);
$adminStmt->bind_param('ii', $loggedUserId, $allowedAdminId);
$adminStmt->execute();
$adminUser = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (!$adminUser) {
    http_response_code(403);
    exit('Access denied. Sirf authorized ONEERP super admin is page ko access kar sakta hai.');
}

if (empty($_SESSION['admin_subscription_renewal_csrf'])) {
    $_SESSION['admin_subscription_renewal_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_subscription_renewal_csrf'];

$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'cancelled'];
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}

$search = trim((string)($_GET['q'] ?? ''));
$search = function_exists('mb_substr')
    ? mb_substr($search, 0, 100, 'UTF-8')
    : substr($search, 0, 100);

/* Status counts */
$counts = [
    'all' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0
];

$countResult = $conn->query(
    "SELECT status, COUNT(*) total
     FROM subscription_renewal_requests
     GROUP BY status"
);

while ($row = $countResult->fetch_assoc()) {
    $key = strtolower((string)$row['status']);
    if (array_key_exists($key, $counts)) {
        $counts[$key] = (int)$row['total'];
    }
}

$counts['all'] =
    $counts['pending']
    + $counts['approved']
    + $counts['rejected']
    + $counts['cancelled'];

/* Filters */
$where = [];
$types = '';
$params = [];

if ($statusFilter !== 'all') {
    $where[] = 'rr.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = "(
        c.business_name LIKE ?
        OR c.contact_number LIKE ?
        OR p.name LIKE ?
        OR rr.payment_reference LIKE ?
        OR CAST(rr.id AS CHAR) LIKE ?
    )";
    $like = '%' . $search . '%';
    $types .= 'sssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        rr.id,
        rr.client_id,
        rr.subscription_id,
        rr.plan_id,
        rr.requested_by,
        rr.amount,
        rr.payment_method,
        rr.payment_reference,
        rr.payment_proof,
        rr.status,
        rr.client_note,
        rr.admin_note,
        rr.approved_by,
        rr.requested_at,
        rr.processed_at,

        COALESCE(NULLIF(c.business_name, ''), CONCAT('Client #', rr.client_id))
            AS client_name,
        COALESCE(c.contact_number, '') AS client_phone,

        COALESCE(NULLIF(p.name, ''), CONCAT('Plan #', rr.plan_id))
            AS plan_name,
        COALESCE(p.billing_cycle, 'monthly') AS billing_cycle,

        COALESCE(s.expires_at, CONCAT(s.end_date, ' 23:59:59'))
            AS current_expiry,
        COALESCE(s.status, '') AS subscription_status,

        COALESCE(requested_user.name, '') AS requested_by_name,
        COALESCE(processed_user.name, '') AS processed_by_name

    FROM subscription_renewal_requests rr
    LEFT JOIN clients c ON c.id = rr.client_id
    LEFT JOIN subscriptions s ON s.id = rr.subscription_id
    LEFT JOIN plans p ON p.id = rr.plan_id
    LEFT JOIN users requested_user ON requested_user.id = rr.requested_by
    LEFT JOIN users processed_user ON processed_user.id = rr.approved_by

    {$whereSql}

    ORDER BY
        CASE
            WHEN rr.status = 'pending' THEN 0
            WHEN rr.status = 'approved' THEN 1
            WHEN rr.status = 'rejected' THEN 2
            ELSE 3
        END,
        rr.requested_at DESC,
        rr.id DESC
    LIMIT 300
";

$stmt = $conn->prepare($sql);

if ($types !== '') {
    $bind = [$types];
    foreach ($params as $i => $value) {
        $bind[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$stmt->execute();
$result = $stmt->get_result();
$requests = [];

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();

$adminName = trim((string)$adminUser['name']) ?: 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subscription Renewals - ONEERP Admin</title>

    <?php if (is_file(__DIR__ . '/partials/head.php')): ?>
        <?php include __DIR__ . '/partials/head.php'; ?>
    <?php endif; ?>

    <style>
        :root{
            --oneerp-bg:#090b1a;
            --oneerp-panel:#11142b;
            --oneerp-panel-2:#171938;
            --oneerp-panel-3:#0c1021;
            --oneerp-purple:#7c3aed;
            --oneerp-purple-2:#5b21b6;
            --oneerp-purple-soft:rgba(124,58,237,.18);
            --oneerp-border:rgba(139,92,246,.28);
            --oneerp-border-soft:rgba(148,163,184,.13);
            --oneerp-text:#f8fafc;
            --oneerp-muted:#a8b0c7;
            --oneerp-success:#3ddc97;
            --oneerp-warning:#f7b731;
            --oneerp-danger:#ff5c75;
            --oneerp-info:#67c7ff;
        }

        *{box-sizing:border-box}
        html,body{min-height:100%}
        body{
            margin:0;
            color:var(--oneerp-text);
            font-family:Arial,sans-serif;
            background:
                radial-gradient(circle at 12% 4%,rgba(124,58,237,.18),transparent 30%),
                radial-gradient(circle at 92% 16%,rgba(76,29,149,.14),transparent 28%),
                var(--oneerp-bg);
        }

        .container-scroll{width:100%;min-height:100vh;background:transparent}
        .main-panel{min-width:0;min-height:100vh;background:transparent}

        .page{
            min-height:100vh;
            padding:88px 25px 28px;
            background:
                linear-gradient(180deg,rgba(7,10,25,.22),rgba(7,10,25,.72)),
                radial-gradient(circle at 18% 0%,rgba(124,58,237,.18),transparent 34%),
                radial-gradient(circle at 100% 8%,rgba(67,56,202,.12),transparent 29%);
        }

        .shell{max-width:1500px;margin:0 auto}

        .header,
        .card,
        .stat,
        .welcome{
            backdrop-filter:blur(16px);
            -webkit-backdrop-filter:blur(16px);
        }

        .header{
            position:relative;
            overflow:hidden;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:18px;
            padding:18px 18px;
            margin-bottom:14px;
            border:1px solid var(--oneerp-border);
            border-radius:18px;
            background:
                linear-gradient(110deg,rgba(33,20,72,.95),rgba(17,20,50,.94) 55%,rgba(29,22,67,.92));
            box-shadow:0 18px 45px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.035);
        }

        .header:after{
            content:"";
            position:absolute;
            width:220px;
            height:220px;
            right:-90px;
            top:-145px;
            border-radius:50%;
            background:rgba(124,58,237,.18);
            filter:blur(8px);
            pointer-events:none;
        }

        .header h1{
            position:relative;
            z-index:1;
            margin:0;
            color:#fff;
            font-size:23px;
            font-weight:800;
            letter-spacing:-.35px;
        }

        .header p{
            position:relative;
            z-index:1;
            margin:6px 0 0;
            color:#b9c1d7;
            font-size:12px;
        }

        .header-actions{
            position:relative;
            z-index:1;
            display:flex;
            flex-wrap:wrap;
            gap:9px;
        }

        .btn-link,.btn{
            min-height:39px;
            padding:9px 14px;
            border-radius:10px;
            font-size:11px;
            font-weight:800;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            white-space:nowrap;
            cursor:pointer;
            transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease,background .18s ease;
        }

        .btn-link{
            color:#fff;
            border:1px solid rgba(139,92,246,.42);
            background:linear-gradient(135deg,rgba(91,33,182,.44),rgba(76,29,149,.30));
            box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
        }

        .btn-link:hover,.btn:hover{transform:translateY(-1px)}
        .btn-link:hover{
            border-color:rgba(167,139,250,.72);
            box-shadow:0 8px 22px rgba(91,33,182,.22);
        }

        .welcome{
            margin-bottom:14px;
            padding:11px 14px;
            border-radius:11px;
            color:#ddd6fe;
            background:linear-gradient(90deg,rgba(76,29,149,.16),rgba(30,41,59,.34));
            border:1px solid rgba(139,92,246,.25);
            box-shadow:inset 0 1px 0 rgba(255,255,255,.025);
            font-size:11px;
        }

        .welcome strong{color:#fff}

        .stats{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:11px;
            margin-bottom:14px;
        }

        .stat{
            position:relative;
            overflow:hidden;
            min-height:88px;
            padding:16px 17px;
            border-radius:15px;
            border:1px solid rgba(139,92,246,.18);
            background:linear-gradient(145deg,rgba(17,20,43,.98),rgba(13,16,37,.98));
            box-shadow:0 13px 28px rgba(0,0,0,.20),inset 0 1px 0 rgba(255,255,255,.025);
        }

        .stat:after{
            content:"";
            position:absolute;
            right:-26px;
            bottom:-38px;
            width:95px;
            height:95px;
            border-radius:50%;
            background:rgba(124,58,237,.08);
        }

        .stat span{
            position:relative;
            z-index:1;
            display:block;
            color:#98a2bd;
            font-size:9px;
            line-height:1.3;
            text-transform:uppercase;
            letter-spacing:.35px;
            font-weight:900;
        }

        .stat strong{
            position:relative;
            z-index:1;
            display:block;
            margin-top:8px;
            color:#fff;
            font-size:24px;
            line-height:1;
            font-weight:900;
        }

        .stat.pending strong{color:var(--oneerp-warning)}
        .stat.approved strong{color:var(--oneerp-success)}
        .stat.rejected strong{color:var(--oneerp-danger)}

        .card{
            border:1px solid rgba(139,92,246,.18);
            border-radius:15px;
            background:linear-gradient(145deg,rgba(17,20,43,.97),rgba(11,14,32,.98));
            box-shadow:0 16px 35px rgba(0,0,0,.24),inset 0 1px 0 rgba(255,255,255,.025);
        }

        .filter{padding:13px;margin-bottom:14px}
        .filter form{
            display:grid;
            grid-template-columns:minmax(260px,1fr) 170px auto auto;
            gap:9px;
            align-items:center;
        }

        .control{
            width:100%;
            min-height:42px;
            border-radius:9px;
            border:1px solid rgba(139,92,246,.22);
            background:#0b1022;
            color:#f8fafc;
            padding:9px 12px;
            outline:none;
            box-shadow:inset 0 1px 7px rgba(0,0,0,.18);
        }

        .control::placeholder{color:#7f89a5}
        .control:focus{
            border-color:#8b5cf6;
            box-shadow:0 0 0 3px rgba(124,58,237,.14),inset 0 1px 7px rgba(0,0,0,.18);
        }

        select.control{
            color-scheme:dark;
            cursor:pointer;
        }

        .apply{
            color:#fff;
            border:1px solid rgba(167,139,250,.18);
            background:linear-gradient(135deg,#7c3aed,#5b21b6);
            box-shadow:0 8px 20px rgba(91,33,182,.24);
        }

        .apply:hover{box-shadow:0 10px 25px rgba(91,33,182,.34)}

        .reset{
            color:#e9e5ff;
            border:1px solid rgba(139,92,246,.24);
            background:#15172f;
        }

        .reset:hover{background:#1b1e3d;border-color:rgba(167,139,250,.45)}

        .table-card{overflow:hidden}

        .table-head{
            padding:14px 17px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            border-bottom:1px solid rgba(139,92,246,.16);
            background:linear-gradient(90deg,rgba(39,25,80,.55),rgba(16,19,43,.82));
        }

        .table-head h2{
            margin:0;
            color:#fff;
            font-size:15px;
            font-weight:800;
        }

        .table-head span{color:#aab3ca;font-size:10px}
        .table-wrap{overflow:auto;background:#0f1228}

        .table-wrap::-webkit-scrollbar{height:10px;width:10px}
        .table-wrap::-webkit-scrollbar-track{background:#0b0e20}
        .table-wrap::-webkit-scrollbar-thumb{background:#383b5b;border-radius:999px;border:2px solid #0b0e20}
        .table-wrap::-webkit-scrollbar-thumb:hover{background:#505477}

        table{
            width:100%;
            min-width:1500px;
            border-collapse:separate;
            border-spacing:0;
            background:#11142b;
        }

        thead{position:sticky;top:0;z-index:2}

        th{
            padding:11px 10px;
            color:#f3efff;
            background:#090c1b;
            text-align:left;
            font-size:9px;
            line-height:1.2;
            text-transform:uppercase;
            letter-spacing:.28px;
            border-bottom:1px solid rgba(139,92,246,.25);
            white-space:nowrap;
        }

        td{
            padding:13px 10px;
            color:#d7dbea;
            vertical-align:middle;
            font-size:10px;
            line-height:1.45;
            border-bottom:1px solid rgba(148,163,184,.08);
            background:#141731;
        }

        tbody tr:nth-child(even) td{background:#12152d}
        tbody tr:hover td{background:#1c1d42}

        .main-text{
            display:block;
            color:#fff;
            font-size:11px;
            font-weight:800;
        }

        .sub-text{
            display:block;
            margin-top:4px;
            color:#929bb4;
            line-height:1.4;
        }

        .money{
            color:#fff;
            font-size:11px;
            font-weight:900;
            white-space:nowrap;
        }

        .status{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:5px 9px;
            border-radius:999px;
            font-size:9px;
            font-weight:900;
            text-transform:uppercase;
            border:1px solid transparent;
        }

        .status.pending{color:#ffc857;background:rgba(245,158,11,.13);border-color:rgba(245,158,11,.20)}
        .status.approved{color:#52e8a2;background:rgba(16,185,129,.13);border-color:rgba(16,185,129,.22)}
        .status.rejected{color:#ff7187;background:rgba(239,68,68,.13);border-color:rgba(239,68,68,.22)}
        .status.cancelled{color:#b6bfd2;background:rgba(148,163,184,.11);border-color:rgba(148,163,184,.18)}

        .proof{
            min-height:33px;
            padding:7px 10px;
            color:#d8c7ff!important;
            background:rgba(124,58,237,.15);
            border:1px solid rgba(139,92,246,.28);
        }

        .proof:hover{background:rgba(124,58,237,.24);border-color:rgba(167,139,250,.5)}

        .actions{display:flex;align-items:center;gap:6px}

        .approve{
            min-height:34px;
            padding:7px 11px;
            color:#d9ffef;
            background:rgba(5,150,105,.18);
            border:1px solid rgba(52,211,153,.30);
        }

        .approve:hover{background:rgba(5,150,105,.28)}

        .reject{
            min-height:34px;
            padding:7px 11px;
            color:#ffe5e9;
            background:rgba(220,38,38,.17);
            border:1px solid rgba(248,113,113,.30);
        }

        .reject:hover{background:rgba(220,38,38,.27)}

        .empty{
            padding:52px 20px;
            color:#9da6bf;
            text-align:center;
            background:#11142b;
        }

        .note{
            max-width:220px;
            white-space:normal;
            word-break:break-word;
            line-height:1.45;
        }

        .renewal-modal-bg{
            position:fixed;
            inset:0;
            z-index:99999;
            display:none;
            align-items:center;
            justify-content:center;
            padding:20px;
            background:rgba(4,6,18,.84);
            backdrop-filter:blur(7px);
            -webkit-backdrop-filter:blur(7px);
        }

        .renewal-modal-bg.show{display:flex}

        .renewal-modal-dialog{
            width:100%;
            max-width:500px;
            overflow:hidden;
            color:#f8fafc;
            border:1px solid rgba(139,92,246,.30);
            border-radius:18px;
            background:linear-gradient(145deg,#181a39,#101329);
            box-shadow:0 30px 80px rgba(0,0,0,.48);
        }

        .renewal-modal-header,.renewal-modal-footer{
            padding:15px 18px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            border-bottom:1px solid rgba(139,92,246,.16);
            background:rgba(20,22,49,.72);
        }

        .renewal-modal-footer{
            justify-content:flex-end;
            border-top:1px solid rgba(139,92,246,.16);
            border-bottom:0;
        }

        .renewal-modal-header h3{margin:0;font-size:15px;color:#fff}
        .renewal-modal-close{border:0;background:transparent;color:#fff;font-size:22px;cursor:pointer}
        .renewal-modal-body{padding:18px}
        .renewal-modal-body p{font-size:12px;color:#c9cfe0;line-height:1.6}

        textarea{
            width:100%;
            min-height:105px;
            padding:11px;
            resize:vertical;
            color:#f8fafc;
            background:#0b1022;
            border:1px solid rgba(139,92,246,.24);
            border-radius:10px;
            outline:none;
        }

        textarea:focus{
            border-color:#8b5cf6;
            box-shadow:0 0 0 3px rgba(124,58,237,.14);
        }

        .toast{
            position:fixed;
            right:20px;
            bottom:20px;
            z-index:100000;
            display:none;
            max-width:420px;
            padding:12px 15px;
            border-radius:11px;
            color:#fff;
            font-size:12px;
            font-weight:800;
            box-shadow:0 15px 35px rgba(0,0,0,.28);
        }

        .toast.show{display:block}
        .toast.success{background:#047857}
        .toast.error{background:#b91c1c}

        @media(max-width:1100px){
            .filter form{grid-template-columns:1fr 160px auto auto}
        }

        @media(max-width:900px){
            .stats{grid-template-columns:repeat(2,minmax(0,1fr))}
            .filter form{grid-template-columns:1fr 150px}
        }

        @media(max-width:650px){
            .page{padding:82px 12px 16px}
            .header{align-items:flex-start;flex-direction:column;padding:17px 15px}
            .header h1{font-size:20px}
            .header-actions{width:100%}
            .header-actions .btn-link{flex:1}
            .stats{grid-template-columns:1fr}
            .filter form{grid-template-columns:1fr}
            .filter .btn{width:100%}
            .table-head{align-items:flex-start;flex-direction:column}
        }
        

/* =========================================================
   SUBSCRIPTION RENEWALS — FINAL DASHBOARD THEME FIX
========================================================= */

html,
body {
    min-height: 100%;
    background: #081126 !important;
}

/* Grey background remove */
body .container-scroll,
body .main-panel {
    min-height: 100vh !important;
    background: #081126 !important;
}

body .main-panel .page {
    min-height: calc(100vh - 63px) !important;
    background:
        radial-gradient(
            circle at 5% 5%,
            rgba(101, 45, 190, 0.42),
            transparent 34%
        ),
        radial-gradient(
            circle at 95% 12%,
            rgba(40, 91, 200, 0.18),
            transparent 32%
        ),
        linear-gradient(
            135deg,
            #171031 0%,
            #101a35 52%,
            #081126 100%
        ) !important;
}

body .main-panel .page .shell {
    width: 100% !important;
    max-width: none !important;
}

/* Main title card */
body .page .header {
    border: 1px solid rgba(112, 82, 196, 0.48) !important;
    background:
        radial-gradient(
            circle at 5% 20%,
            rgba(124, 58, 237, 0.28),
            transparent 34%
        ),
        linear-gradient(
            135deg,
            #211747,
            #151b3a
        ) !important;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25) !important;
}

body .page .header h1 {
    color: #ffffff !important;
}

body .page .header p {
    color: #b9c5df !important;
}

/* Dashboard and refresh buttons */
body .page .btn-link,
body .page .btn-link:visited {
    color: #ffffff !important;
    background: linear-gradient(135deg, #6632d4, #492095) !important;
    border: 1px solid rgba(177, 145, 255, 0.56) !important;
    opacity: 1 !important;
}

body .page .btn-link:hover {
    color: #ffffff !important;
    background: linear-gradient(135deg, #804cf0, #6130c7) !important;
}

/* Logged-in bar */
body .page .welcome {
    color: #d3dcf4 !important;
    border: 1px solid rgba(71, 88, 139, 0.7) !important;
    background: linear-gradient(
        90deg,
        #1a2141,
        #121d39
    ) !important;
}

body .page .welcome strong {
    color: #ffffff !important;
}

/* Dashboard-style statistic cards */
body .page .stat {
    min-height: 112px !important;
    padding: 18px 19px !important;
    border: 0 !important;
    border-radius: 17px !important;
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.2) !important;
}

/* Total */
body .page .stat:nth-child(1) {
    background: linear-gradient(135deg, #169fba, #11b5cc) !important;
}

/* Pending */
body .page .stat.pending {
    background: linear-gradient(135deg, #4568ed, #7140ed) !important;
}

/* Approved */
body .page .stat.approved {
    background: linear-gradient(135deg, #299fe2, #4da8ef) !important;
}

/* Rejected */
body .page .stat.rejected {
    background: linear-gradient(135deg, #d52749, #f03c50) !important;
}

body .page .stat::after {
    content: "" !important;
    position: absolute !important;
    width: 72px !important;
    height: 72px !important;
    right: 14px !important;
    top: 13px !important;
    bottom: auto !important;
    border-radius: 20px !important;
    background: rgba(255, 255, 255, 0.13) !important;
}

body .page .stat span {
    color: rgba(255, 255, 255, 0.96) !important;
    font-size: 10px !important;
}

body .page .stat strong,
body .page .stat.pending strong,
body .page .stat.approved strong,
body .page .stat.rejected strong {
    margin-top: 15px !important;
    color: #ffffff !important;
    font-size: 27px !important;
}

/* Filter and table cards */
body .page .card {
    border: 1px solid rgba(63, 80, 132, 0.62) !important;
    background: linear-gradient(
        135deg,
        #121936,
        #0c162e
    ) !important;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.22) !important;
}

/* Search and dropdown */
body .page .control {
    color: #ffffff !important;
    background: #0a142d !important;
    border: 1px solid #30416f !important;
}

body .page .control::placeholder {
    color: #8e9abb !important;
    opacity: 1 !important;
}

body .page select.control,
body .page select.control option {
    color: #ffffff !important;
    background: #0a142d !important;
}

/* Apply button */
body .page .btn.apply {
    color: #ffffff !important;
    background: linear-gradient(135deg, #8044ec, #642ed5) !important;
    border: 1px solid #9061f2 !important;
    opacity: 1 !important;
}

/* Reset visibility fix */
body .page .btn.reset,
body .page .btn.reset:visited {
    color: #ffffff !important;
    background: #1b2746 !important;
    border: 1px solid #43547f !important;
    opacity: 1 !important;
    text-decoration: none !important;
}

body .page .btn.reset:hover {
    color: #ffffff !important;
    background: #263456 !important;
}

/* Table heading */
body .page .table-head {
    border-bottom: 1px solid #34436f !important;
    background:
        radial-gradient(
            circle at 3% 10%,
            rgba(124, 58, 237, 0.27),
            transparent 32%
        ),
        linear-gradient(
            90deg,
            #1b173f,
            #111a36
        ) !important;
}

/* Empty/table content */
body .page .table-wrap,
body .page .empty {
    color: #aab5d2 !important;
    background: #0e1731 !important;
}

body .page .empty {
    min-height: 115px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

body .page table {
    background: #0e1731 !important;
}

body .page table th {
    color: #ffffff !important;
    background: #080f22 !important;
}

body .page table td {
    color: #dce5fb !important;
    background: #111a38 !important;
}

body .page table tbody tr:nth-child(even) td {
    background: #0f1833 !important;
}

body .page table tbody tr:hover td {
    background: #19244a !important;
}

/* Action button visibility */
body .page .approve {
    color: #ffffff !important;
    background: linear-gradient(135deg, #07966b, #10b981) !important;
    border: 1px solid #34d399 !important;
}

body .page .reject {
    color: #ffffff !important;
    background: linear-gradient(135deg, #d92c48, #ef4058) !important;
    border: 1px solid #fb7185 !important;
}

body .page .proof {
    color: #ffffff !important;
    background: linear-gradient(135deg, #5d42d7, #7247e8) !important;
    border: 1px solid #876df0 !important;
}

@media (max-width: 900px) {
    body .page .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 650px) {
    body .page .stats {
        grid-template-columns: 1fr !important;
    }
}

        
          </style>
</head>
<body>

<div class="container-scroll">

    <div
        class="sidebar"
        id="dashboardSidebar"
    >
        <?php if (is_file(__DIR__ . '/partials/sidebar.php')): ?>
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <?php endif; ?>
    </div>

    <div class="main-panel">

        <?php if (is_file(__DIR__ . '/partials/topbar.php')): ?>
            <?php include __DIR__ . '/partials/topbar.php'; ?>
        <?php endif; ?>

        <div class="page">
        <div class="shell">

    <div class="header">
        <div>
            <h1>Subscription Renewal Requests</h1>
            <p>Client payment details review karein aur renewal approve/reject karein.</p>
        </div>
        <div class="header-actions">
            <a class="btn-link" href="dashboard_view.php">← Dashboard</a>
            <a class="btn-link" href="<?= ar_e($_SERVER['REQUEST_URI']) ?>">↻ Refresh</a>
        </div>
    </div>

    <div class="welcome">
        Logged in as <strong><?= ar_e($adminName) ?></strong> — authorized ONEERP super admin.
    </div>

    <div class="stats">
        <div class="stat"><span>Total Requests</span><strong><?= $counts['all'] ?></strong></div>
        <div class="stat pending"><span>Pending</span><strong><?= $counts['pending'] ?></strong></div>
        <div class="stat approved"><span>Approved</span><strong><?= $counts['approved'] ?></strong></div>
        <div class="stat rejected"><span>Rejected</span><strong><?= $counts['rejected'] ?></strong></div>
    </div>

    <div class="card filter">
        <form method="get">
            <input class="control" type="text" name="q" value="<?= ar_e($search) ?>"
                   placeholder="Client, plan, phone, reference ya request ID search karein">
            <select class="control" name="status">
                <?php foreach ($allowedStatuses as $option): ?>
                    <option value="<?= ar_e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>>
                        <?= ar_e(ucwords($option)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn apply" type="submit">Apply Filter</button>
            <a class="btn reset" href="subscription_renewal_requests.php">Reset</a>
        </form>
    </div>

    <div class="card table-card">
        <div class="table-head">
            <h2><?= ar_e(ucwords($statusFilter)) ?> Requests</h2>
            <span><?= count($requests) ?> request(s)</span>
        </div>

        <?php if ($requests): ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Request</th>
                    <th>Client</th>
                    <th>Plan</th>
                    <th>Amount</th>
                    <th>Payment</th>
                    <th>Reference</th>
                    <th>Proof</th>
                    <th>Current Expiry</th>
                    <th>Client Note</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th>Processed</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $row): ?>
                    <?php
                    $rowStatus = strtolower((string)$row['status']);
                    if (!in_array($rowStatus, ['pending','approved','rejected','cancelled'], true)) {
                        $rowStatus = 'cancelled';
                    }
                    $proof = trim((string)$row['payment_proof']);
                    ?>
                    <tr>
                        <td>
                            <span class="main-text">#<?= (int)$row['id'] ?></span>
                            <span class="sub-text">Sub #<?= (int)$row['subscription_id'] ?></span>
                        </td>
                        <td>
                            <span class="main-text"><?= ar_e($row['client_name']) ?></span>
                            <span class="sub-text">
                                Client #<?= (int)$row['client_id'] ?>
                                <?= trim((string)$row['client_phone']) !== '' ? ' · ' . ar_e($row['client_phone']) : '' ?>
                            </span>
                        </td>
                        <td>
                            <span class="main-text"><?= ar_e($row['plan_name']) ?></span>
                            <span class="sub-text"><?= ar_e(ucwords((string)$row['billing_cycle'])) ?></span>
                        </td>
                        <td><span class="money">PKR <?= number_format((float)$row['amount'], 2) ?></span></td>
                        <td><?= ar_e(ucwords(str_replace('_', ' ', (string)$row['payment_method']))) ?></td>
                        <td><?= trim((string)$row['payment_reference']) !== '' ? ar_e($row['payment_reference']) : '—' ?></td>
                        <td>
                            <?php if ($proof !== ''): ?>
                                <a class="btn proof" href="<?= ar_e($proof) ?>" target="_blank" rel="noopener noreferrer">
                                    View Proof
                                </a>
                            <?php else: ?>
                                <span class="sub-text">No proof</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= ar_e(ar_date($row['current_expiry'])) ?>
                            <span class="sub-text"><?= ar_e(ucwords((string)$row['subscription_status'])) ?></span>
                        </td>
                        <td><div class="note"><?= trim((string)$row['client_note']) !== '' ? nl2br(ar_e($row['client_note'])) : '—' ?></div></td>
                        <td>
                            <?= ar_e(ar_date($row['requested_at'])) ?>
                            <?php if (trim((string)$row['requested_by_name']) !== ''): ?>
                                <span class="sub-text">By <?= ar_e($row['requested_by_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status <?= ar_e($rowStatus) ?>"><?= ar_e($rowStatus) ?></span></td>
                        <td>
                            <?php if (trim((string)$row['processed_at']) !== ''): ?>
                                <?= ar_e(ar_date($row['processed_at'])) ?>
                                <span class="sub-text">By <?= ar_e($row['processed_by_name']) ?></span>
                                <?php if (trim((string)$row['admin_note']) !== ''): ?>
                                    <span class="sub-text"><?= ar_e($row['admin_note']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rowStatus === 'pending'): ?>
                            <div class="actions">
                                <button class="btn approve" type="button"
                                        data-action="approve"
                                        data-request-id="<?= (int)$row['id'] ?>"
                                        data-client-name="<?= ar_e($row['client_name']) ?>">
                                    Approve
                                </button>
                                <button class="btn reject" type="button"
                                        data-action="reject"
                                        data-request-id="<?= (int)$row['id'] ?>"
                                        data-client-name="<?= ar_e($row['client_name']) ?>">
                                    Reject
                                </button>
                            </div>
                            <?php else: ?>
                                <span class="sub-text">Processed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty">Is filter ke mutabiq koi renewal request nahi mili.</div>
        <?php endif; ?>
    </div>
        </div>

    </div>
</div>

<div class="renewal-modal-bg" id="actionModal">
    <div class="renewal-modal-dialog">
        <div class="renewal-modal-header">
            <h3 id="modalTitle">Process Request</h3>
            <button class="renewal-modal-close" type="button" id="modalClose">×</button>
        </div>
        <div class="renewal-modal-body">
            <p id="modalText"></p>
            <textarea id="adminNote" placeholder="Admin note..."></textarea>
        </div>
        <div class="renewal-modal-footer">
            <button class="btn reset" type="button" id="modalCancel">Cancel</button>
            <button class="btn apply" type="button" id="modalConfirm">Confirm</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
(function () {
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const modal = document.getElementById('actionModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalText = document.getElementById('modalText');
    const adminNote = document.getElementById('adminNote');
    const modalConfirm = document.getElementById('modalConfirm');
    const toast = document.getElementById('toast');

    let action = '';
    let requestId = 0;

    function showToast(message, success) {
        toast.className = 'toast show ' + (success ? 'success' : 'error');
        toast.textContent = message;
        setTimeout(function () {
            toast.className = 'toast';
        }, 3500);
    }

    function closeModal() {
        modal.classList.remove('show');
        action = '';
        requestId = 0;
        adminNote.value = '';
    }

    document.querySelectorAll('[data-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            action = button.dataset.action || '';
            requestId = parseInt(button.dataset.requestId || '0', 10);
            const clientName = button.dataset.clientName || 'client';

            modalTitle.textContent = action === 'approve'
                ? 'Approve Renewal Request'
                : 'Reject Renewal Request';

            modalText.textContent =
                (action === 'approve' ? 'Approve' : 'Reject')
                + ' request #' + requestId + ' for ' + clientName + '?';

            adminNote.placeholder = action === 'approve'
                ? 'Optional approval note...'
                : 'Rejection reason required...';

            modalConfirm.textContent = action === 'approve'
                ? 'Approve Subscription'
                : 'Reject Request';

            modal.classList.add('show');
        });
    });

    document.getElementById('modalClose').addEventListener('click', closeModal);
    document.getElementById('modalCancel').addEventListener('click', closeModal);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });

    modalConfirm.addEventListener('click', async function () {
        const note = adminNote.value.trim();

        if (requestId <= 0 || (action !== 'approve' && action !== 'reject')) {
            return;
        }

        if (action === 'reject' && note === '') {
            showToast('Reject karne ke liye reason required hai.', false);
            adminNote.focus();
            return;
        }

        const endpoint = action === 'approve'
            ? '/onerp/app/public/app/views/ajax_approve_subscription_renewal.php'
            : '/onerp/app/public/app/views/ajax_reject_subscription_renewal.php';

        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('request_id', String(requestId));
        data.append('admin_note', note);

        modalConfirm.disabled = true;
        modalConfirm.textContent = 'Processing...';

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: data,
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin'
            });

            const text = await response.text();
            let result;

            try {
                result = JSON.parse(text);
            } catch (error) {
                throw new Error(text || 'Invalid server response.');
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Request process nahi ho saki.');
            }

            showToast(result.message, true);
            closeModal();

            setTimeout(function () {
                window.location.reload();
            }, 900);
        } catch (error) {
            showToast(error.message || 'Request process nahi ho saki.', false);
        } finally {
            modalConfirm.disabled = false;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModal();
    });
})();
</script>
</body>
</html>
