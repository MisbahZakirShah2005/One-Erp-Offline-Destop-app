<?php
/*
|--------------------------------------------------------------------------
| ONEERP OPD Dashboard
|--------------------------------------------------------------------------
| Fully database-driven OPD dashboard with live AJAX statistics.
| No consultation, prescription, lab or pharmacy workflow is included.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $conn = $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found. Please check config/db.php.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$role_id   = (int)($_SESSION['role_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);

$session_branch_id = (int)(
    $_SESSION['branch_id']
    ?? $_SESSION['selected_branch_id']
    ?? $_SESSION['current_branch_id']
    ?? 0
);

function opd_page_table_exists(mysqli $conn, $tableName)
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = ($result && $result->num_rows > 0);
    $stmt->close();

    return $exists;
}

function opd_page_has_dashboard_access(mysqli $conn, $clientId, $userId, $roleId)
{
    if ($clientId <= 0 || $userId <= 0) {
        return false;
    }

    if ((int)$roleId === 1) {
        return true;
    }

    $enabled = false;

    $stmt = $conn->prepare("\n        SELECT 1\n        FROM subscriptions s\n        INNER JOIN plan_features pf ON pf.plan_id = s.plan_id\n        WHERE s.client_id = ?\n          AND s.status = 'active'\n          AND (s.end_date IS NULL OR s.end_date >= CURDATE())\n          AND pf.feature_key = 'opd_dashboard'\n          AND pf.enabled = 1\n        LIMIT 1\n    ");

    if ($stmt) {
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $result = $stmt->get_result();
        $enabled = ($result && $result->num_rows > 0);
        $stmt->close();
    }

    $stmt = $conn->prepare("\n        SELECT enabled\n        FROM client_feature_overrides\n        WHERE client_id = ?\n          AND feature_key = 'opd_dashboard'\n        ORDER BY enabled DESC\n        LIMIT 1\n    ");

    if ($stmt) {
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && ($row = $result->fetch_assoc())) {
            $enabled = ((int)$row['enabled'] === 1);
        }
        $stmt->close();
    }

    if (!$enabled) {
        return false;
    }

    $permissionCount = 0;
    $hasPermission = false;

    $stmt = $conn->prepare('SELECT feature_key FROM users_permissions WHERE user_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && ($row = $result->fetch_assoc())) {
            $permissionCount++;
            if (trim((string)$row['feature_key']) === 'opd_dashboard') {
                $hasPermission = true;
            }
        }
        $stmt->close();
    }

    if ($permissionCount > 0 && !$hasPermission) {
        return false;
    }

    return true;
}

if (!opd_page_has_dashboard_access($conn, $client_id, $user_id, $role_id)) {
    http_response_code(403);
    die('Access denied! OPD Dashboard feature is not enabled for this account.');
}

$required_opd_tables = [
    'opd_visits',
    'opd_patients',
    'opd_payments',
    'opd_doctors',
    'opd_departments',
    'opd_vitals'
];

$missing_opd_tables = [];
foreach ($required_opd_tables as $requiredTable) {
    if (!opd_page_table_exists($conn, $requiredTable)) {
        $missing_opd_tables[] = $requiredTable;
    }
}

$business_name = 'ONEERP OPD';
$business_address = '';
$business_phone = '';

try {
    $stmt = $conn->prepare("\n        SELECT\n            COALESCE(NULLIF(cs.business_name, ''), NULLIF(c.business_name, ''), 'ONEERP OPD') AS business_name,\n            COALESCE(NULLIF(cs.address, ''), NULLIF(c.business_address, ''), '') AS business_address,\n            COALESCE(NULLIF(cs.phone, ''), NULLIF(c.contact_number, ''), '') AS business_phone\n        FROM clients c\n        LEFT JOIN client_settings cs ON cs.client_id = c.id\n        WHERE c.id = ?\n        LIMIT 1\n    ");

    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && ($row = $result->fetch_assoc())) {
            $business_name = $row['business_name'] ?: $business_name;
            $business_address = $row['business_address'] ?: '';
            $business_phone = $row['business_phone'] ?: '';
        }
        $stmt->close();
    }
} catch (Throwable $ignore) {
    // Dashboard remains usable with the safe defaults above.
}

$branches = [];
try {
    $stmt = $conn->prepare("\n        SELECT id, name\n        FROM branches\n        WHERE client_id = ?\n          AND deleted_at IS NULL\n        ORDER BY name ASC\n    ");

    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $branches[] = [
                'id' => (int)$row['id'],
                'name' => $row['name']
            ];
        }
        $stmt->close();
    }
} catch (Throwable $ignore) {
    $branches = [];
}

// Prevent a stale session branch belonging to another client from being used.
if ($session_branch_id > 0) {
    $validSessionBranch = false;
    foreach ($branches as $branchRow) {
        if ((int)$branchRow['id'] === $session_branch_id) {
            $validSessionBranch = true;
            break;
        }
    }

    if (!$validSessionBranch) {
        $session_branch_id = 0;
    }
}

$departments = [];
$doctors = [];

if (empty($missing_opd_tables)) {
    try {
        $stmt = $conn->prepare("\n            SELECT id, branch_id, name, token_prefix\n            FROM opd_departments\n            WHERE client_id = ?\n              AND status = 'active'\n            ORDER BY name ASC\n        ");

        if ($stmt) {
            $stmt->bind_param('i', $client_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $departments[] = [
                    'id' => (int)$row['id'],
                    'branch_id' => $row['branch_id'] !== null ? (int)$row['branch_id'] : 0,
                    'name' => $row['name'],
                    'token_prefix' => $row['token_prefix']
                ];
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("\n            SELECT\n                d.id,\n                d.branch_id,\n                d.department_id,\n                d.doctor_name,\n                d.room_number,\n                dep.name AS department_name\n            FROM opd_doctors d\n            INNER JOIN opd_departments dep\n                ON dep.id = d.department_id\n               AND dep.client_id = d.client_id\n            WHERE d.client_id = ?\n              AND d.status = 'active'\n            ORDER BY dep.name ASC, d.doctor_name ASC\n        ");

        if ($stmt) {
            $stmt->bind_param('i', $client_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $doctors[] = [
                    'id' => (int)$row['id'],
                    'branch_id' => $row['branch_id'] !== null ? (int)$row['branch_id'] : 0,
                    'department_id' => (int)$row['department_id'],
                    'doctor_name' => $row['doctor_name'],
                    'room_number' => $row['room_number'],
                    'department_name' => $row['department_name']
                ];
            }
            $stmt->close();
        }
    } catch (Throwable $ignore) {
        $departments = [];
        $doctors = [];
    }
}

$today = date('Y-m-d');
$today_display = date('l, d F Y');
?>
<!DOCTYPE html>
<html lang="en" data-pos-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPD Dashboard - <?= htmlspecialchars($business_name, ENT_QUOTES, 'UTF-8') ?></title>

    <?php
    $headPartial = __DIR__ . '/partials/head.php';
    if (is_file($headPartial)) {
        include $headPartial;
    }
    ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

    <script>
    (function () {
        try {
            const savedTheme = localStorage.getItem('oneerp-opd-theme');
            const initialTheme = savedTheme === 'light' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-opd-theme', initialTheme);
            document.documentElement.setAttribute('data-pos-theme', initialTheme);
        } catch (error) {
            document.documentElement.setAttribute('data-opd-theme', 'dark');
            document.documentElement.setAttribute('data-pos-theme', 'dark');
        }
    })();
    </script>

    <style>
        :root {
            --opd-bg: #03040a;
            --opd-bg-soft: #090b15;
            --opd-panel: rgba(11, 14, 28, .72);
            --opd-panel-strong: rgba(13, 16, 34, .90);
            --opd-border: rgba(174, 153, 255, .17);
            --opd-text: #f8fafc;
            --opd-muted: #94a3b8;
            --opd-purple: #7c3aed;
            --opd-purple-2: #a855f7;
            --opd-violet: #6d4cff;
            --opd-pink: #ec4899;
            --opd-cyan: #22d3ee;
            --opd-green: #22c55e;
            --opd-amber: #f59e0b;
            --opd-red: #ef4444;
            --opd-sidebar-width: 270px;
            --opd-shadow: 0 22px 70px rgba(0, 0, 0, .44);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body.opd-dashboard-body {
            margin: 0;
            min-height: 100%;
            overflow-x: hidden;
            background: var(--opd-bg) !important;
            color: var(--opd-text);
        }

        body.opd-dashboard-body {
            position: relative;
            background:
                radial-gradient(circle at 12% 2%, rgba(124, 58, 237, .23), transparent 27%),
                radial-gradient(circle at 88% 12%, rgba(168, 85, 247, .14), transparent 25%),
                radial-gradient(circle at 65% 95%, rgba(34, 211, 238, .06), transparent 28%),
                linear-gradient(145deg, #020308 0%, #080a14 42%, #0d0718 73%, #03040a 100%) !important;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body.opd-dashboard-body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            opacity: .32;
            background-image:
                linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.02) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: linear-gradient(to bottom, #000, transparent 92%);
        }

        .opd-orb {
            position: fixed;
            z-index: 0;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(80px);
            opacity: .16;
            animation: opdOrbFloat 14s ease-in-out infinite alternate;
        }

        .opd-orb-one {
            top: 9%;
            left: 17%;
            background: #7c3aed;
        }

        .opd-orb-two {
            right: -80px;
            bottom: -100px;
            background: #a855f7;
            animation-delay: -5s;
        }

        @keyframes opdOrbFloat {
            from { transform: translate3d(-20px, -12px, 0) scale(.92); }
            to { transform: translate3d(35px, 25px, 0) scale(1.08); }
        }

        .container-scroll,
        .main-panel,
        #dashboardSidebar {
            position: relative;
            z-index: 2;
        }

        body.opd-dashboard-body .main-panel {
            background: transparent !important;
        }

        .opd-main-content {
            position: relative;
            z-index: 2;
            width: 100%;
            min-height: 100vh;
            padding: 94px 24px 38px;
        }

        .opd-glass {
            background:
                radial-gradient(circle at 10% 0%, rgba(124, 58, 237, .13), transparent 35%),
                linear-gradient(145deg, rgba(14, 17, 34, .82), rgba(7, 9, 19, .66));
            border: 1px solid var(--opd-border);
            box-shadow: var(--opd-shadow), inset 0 1px 0 rgba(255,255,255,.045);
            backdrop-filter: blur(22px) saturate(135%);
            -webkit-backdrop-filter: blur(22px) saturate(135%);
        }

        .opd-hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: 26px 28px;
            margin-bottom: 20px;
        }

        .opd-hero::before {
            content: "";
            position: absolute;
            width: 380px;
            height: 380px;
            right: -130px;
            top: -190px;
            border-radius: 50%;
            background: conic-gradient(from 120deg, rgba(124,58,237,.72), rgba(168,85,247,.18), transparent 70%);
            filter: blur(10px);
            opacity: .44;
            animation: opdSpin 16s linear infinite;
        }

        .opd-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(115deg, transparent 25%, rgba(255,255,255,.055) 48%, transparent 68%);
            transform: translateX(-120%);
            animation: opdHeroShine 8s ease-in-out infinite;
        }

        @keyframes opdSpin {
            to { transform: rotate(360deg); }
        }

        @keyframes opdHeroShine {
            0%, 68% { transform: translateX(-120%); }
            86%, 100% { transform: translateX(120%); }
        }

        .opd-hero-grid {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 24px;
            align-items: center;
        }

        .opd-live-pill {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            width: fit-content;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid rgba(167,139,250,.24);
            background: rgba(124,58,237,.12);
            color: #ddd6fe;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .opd-live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #34d399;
            box-shadow: 0 0 0 4px rgba(52,211,153,.13), 0 0 14px rgba(52,211,153,.8);
            animation: opdPulse 1.8s ease-in-out infinite;
        }

        @keyframes opdPulse {
            0%, 100% { transform: scale(.88); opacity: .72; }
            50% { transform: scale(1.12); opacity: 1; }
        }

        .opd-hero h1 {
            margin: 14px 0 8px;
            color: #fff;
            font-size: clamp(28px, 3.1vw, 46px);
            font-weight: 950;
            letter-spacing: -.045em;
            line-height: 1.05;
            text-shadow: 0 0 36px rgba(124,58,237,.34);
        }

        .opd-hero h1 span {
            background: linear-gradient(90deg, #fff, #c4b5fd 48%, #a5f3fc);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .opd-hero-subtitle {
            max-width: 760px;
            margin: 0;
            color: #aab4c8;
            font-size: 14px;
            font-weight: 650;
            line-height: 1.65;
        }

        .opd-hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 18px;
            margin-top: 17px;
            color: #cbd5e1;
            font-size: 12.5px;
            font-weight: 750;
        }

        .opd-hero-meta span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .opd-hero-meta i {
            color: #a78bfa;
        }

        .opd-clock-card {
            min-width: 230px;
            padding: 19px 20px;
            border-radius: 22px;
            border: 1px solid rgba(167,139,250,.2);
            background: rgba(5, 7, 15, .45);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04), 0 18px 40px rgba(0,0,0,.24);
            text-align: right;
        }

        .opd-clock-label {
            color: #8b9ab4;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .opd-clock-time {
            margin: 4px 0;
            color: #fff;
            font-size: 29px;
            font-weight: 950;
            letter-spacing: -.04em;
            font-variant-numeric: tabular-nums;
        }

        .opd-clock-date {
            color: #c4b5fd;
            font-size: 12px;
            font-weight: 750;
        }

        .opd-action-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 13px;
            margin-bottom: 20px;
        }

        .opd-action-btn {
            position: relative;
            overflow: hidden;
            min-height: 58px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border: 1px solid rgba(167,139,250,.16);
            border-radius: 17px;
            background: linear-gradient(145deg, rgba(15,18,38,.86), rgba(7,9,18,.7));
            color: #eef2ff;
            text-decoration: none;
            box-shadow: 0 13px 30px rgba(0,0,0,.23), inset 0 1px 0 rgba(255,255,255,.035);
            transition: transform .22s ease, border-color .22s ease, box-shadow .22s ease;
        }

        .opd-action-btn::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(110deg, transparent 20%, rgba(255,255,255,.055) 45%, transparent 70%);
            transform: translateX(-120%);
            transition: transform .55s ease;
        }

        .opd-action-btn:hover {
            color: #fff;
            transform: translateY(-3px);
            border-color: rgba(167,139,250,.42);
            box-shadow: 0 18px 38px rgba(0,0,0,.32), 0 0 26px rgba(124,58,237,.12);
        }

        .opd-action-btn:hover::after {
            transform: translateX(120%);
        }

        .opd-action-icon {
            width: 38px;
            min-width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: linear-gradient(145deg, rgba(124,58,237,.28), rgba(168,85,247,.13));
            border: 1px solid rgba(167,139,250,.18);
            color: #c4b5fd;
            font-size: 17px;
            box-shadow: 0 0 20px rgba(124,58,237,.13);
        }

        .opd-action-copy strong {
            display: block;
            font-size: 13px;
            font-weight: 900;
        }

        .opd-action-copy small {
            display: block;
            margin-top: 2px;
            color: #7f8da6;
            font-size: 10.5px;
            font-weight: 650;
        }

        .opd-filter-panel {
            border-radius: 22px;
            padding: 17px;
            margin-bottom: 20px;
        }

        .opd-filter-grid {
            display: grid;
            grid-template-columns: 1.05fr 1fr 1.2fr 1.2fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .opd-field label {
            display: block;
            margin: 0 0 7px 2px;
            color: #8f9cb3;
            font-size: 10.5px;
            font-weight: 850;
            letter-spacing: .075em;
            text-transform: uppercase;
        }

        .opd-control {
            width: 100%;
            height: 44px;
            padding: 0 13px;
            border: 1px solid rgba(148,163,184,.17);
            border-radius: 13px;
            outline: none;
            background: rgba(6,8,17,.72);
            color: #e5e7eb;
            font-size: 12.5px;
            font-weight: 750;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.03);
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .opd-control:focus {
            background: rgba(9,11,24,.95);
            border-color: rgba(139,92,246,.65);
            box-shadow: 0 0 0 3px rgba(124,58,237,.14), 0 0 22px rgba(124,58,237,.08);
        }

        .opd-control option {
            background: #0b0f1d;
            color: #e5e7eb;
        }

        .opd-filter-actions {
            display: flex;
            gap: 8px;
        }

        .opd-btn {
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 13px;
            padding: 0 15px;
            font-size: 12px;
            font-weight: 900;
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
        }

        .opd-btn:hover {
            transform: translateY(-2px);
        }

        .opd-btn-primary {
            background: linear-gradient(135deg, #6d4cff, #7c3aed 60%, #a855f7);
            color: #fff;
            box-shadow: 0 12px 26px rgba(109,76,255,.28);
        }

        .opd-btn-ghost {
            width: 44px;
            padding: 0;
            border: 1px solid rgba(148,163,184,.16);
            background: rgba(11,14,27,.72);
            color: #94a3b8;
        }

        .opd-refresh-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 12px;
            color: #718096;
            font-size: 10.5px;
            font-weight: 700;
        }

        .opd-refresh-meta span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .opd-refreshing i {
            animation: opdSpin .8s linear infinite;
        }

        .opd-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .opd-stat-card {
            --accent: #7c3aed;
            --accent-rgb: 124,58,237;
            position: relative;
            overflow: hidden;
            min-height: 154px;
            padding: 19px;
            border-radius: 22px;
            border: 1px solid rgba(var(--accent-rgb), .20);
            background:
                radial-gradient(circle at 110% -10%, rgba(var(--accent-rgb), .25), transparent 42%),
                linear-gradient(145deg, rgba(14,17,34,.88), rgba(6,8,17,.72));
            box-shadow: 0 18px 43px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.04);
            backdrop-filter: blur(18px);
            transition: transform .25s ease, border-color .25s ease, box-shadow .25s ease;
            animation: opdCardIn .65s both;
        }

        .opd-stat-card:nth-child(2) { animation-delay: .05s; }
        .opd-stat-card:nth-child(3) { animation-delay: .10s; }
        .opd-stat-card:nth-child(4) { animation-delay: .15s; }
        .opd-stat-card:nth-child(5) { animation-delay: .20s; }
        .opd-stat-card:nth-child(6) { animation-delay: .25s; }
        .opd-stat-card:nth-child(7) { animation-delay: .30s; }
        .opd-stat-card:nth-child(8) { animation-delay: .35s; }

        @keyframes opdCardIn {
            from { opacity: 0; transform: translateY(16px) scale(.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .opd-stat-card::before {
            content: "";
            position: absolute;
            left: -25%;
            bottom: -60%;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            border: 30px solid rgba(var(--accent-rgb), .045);
            pointer-events: none;
        }

        .opd-stat-card:hover {
            transform: translateY(-5px);
            border-color: rgba(var(--accent-rgb), .42);
            box-shadow: 0 26px 56px rgba(0,0,0,.38), 0 0 30px rgba(var(--accent-rgb), .10), inset 0 1px 0 rgba(255,255,255,.055);
        }

        .opd-stat-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .opd-stat-title {
            color: #93a0b7;
            font-size: 10.5px;
            font-weight: 850;
            letter-spacing: .075em;
            text-transform: uppercase;
        }

        .opd-stat-icon {
            width: 42px;
            min-width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            color: var(--accent);
            background: rgba(var(--accent-rgb), .13);
            border: 1px solid rgba(var(--accent-rgb), .18);
            font-size: 18px;
            box-shadow: 0 0 24px rgba(var(--accent-rgb), .10);
        }

        .opd-stat-value {
            position: relative;
            z-index: 2;
            margin-top: 14px;
            color: #fff;
            font-size: clamp(28px, 2.4vw, 38px);
            line-height: 1;
            font-weight: 950;
            letter-spacing: -.045em;
            font-variant-numeric: tabular-nums;
        }

        .opd-stat-note {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 11px;
            color: #73819a;
            font-size: 10.5px;
            font-weight: 700;
        }

        .opd-stat-note i {
            color: var(--accent);
        }

        .opd-accent-purple { --accent: #a78bfa; --accent-rgb: 167,139,250; }
        .opd-accent-blue { --accent: #60a5fa; --accent-rgb: 96,165,250; }
        .opd-accent-amber { --accent: #fbbf24; --accent-rgb: 251,191,36; }
        .opd-accent-pink { --accent: #f472b6; --accent-rgb: 244,114,182; }
        .opd-accent-cyan { --accent: #22d3ee; --accent-rgb: 34,211,238; }
        .opd-accent-green { --accent: #34d399; --accent-rgb: 52,211,153; }
        .opd-accent-red { --accent: #fb7185; --accent-rgb: 251,113,133; }
        .opd-accent-violet { --accent: #c084fc; --accent-rgb: 192,132,252; }

        .opd-chart-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, .85fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .opd-panel {
            border-radius: 23px;
            overflow: hidden;
        }

        .opd-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 19px 12px;
        }

        .opd-panel-title-wrap {
            min-width: 0;
        }

        .opd-panel-title {
            margin: 0;
            color: #f8fafc;
            font-size: 14px;
            font-weight: 950;
            letter-spacing: -.01em;
        }

        .opd-panel-subtitle {
            margin-top: 4px;
            color: #718096;
            font-size: 10.5px;
            font-weight: 700;
        }

        .opd-panel-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #a78bfa;
            font-size: 11px;
            font-weight: 850;
            text-decoration: none;
            white-space: nowrap;
        }

        .opd-panel-link:hover {
            color: #ddd6fe;
        }

        .opd-chart-body {
            position: relative;
            height: 315px;
            padding: 8px 16px 16px;
        }

        .opd-status-chart-body {
            height: 315px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px 18px 18px;
        }

        .opd-data-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(360px, .95fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .opd-table-wrap {
            width: 100%;
            overflow-x: auto;
            padding: 0 7px 7px;
        }

        .opd-table {
            width: 100%;
            min-width: 760px;
            border-collapse: separate;
            border-spacing: 0 7px;
            color: #dbe4f0;
        }

        .opd-table th {
            padding: 0 12px 5px;
            border: 0;
            color: #64748b;
            font-size: 9.5px;
            font-weight: 900;
            letter-spacing: .085em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .opd-table td {
            padding: 12px;
            border-top: 1px solid rgba(148,163,184,.08);
            border-bottom: 1px solid rgba(148,163,184,.08);
            background: rgba(8,10,21,.58);
            color: #cbd5e1;
            font-size: 11.5px;
            font-weight: 700;
            vertical-align: middle;
            white-space: nowrap;
        }

        .opd-table td:first-child {
            border-left: 1px solid rgba(148,163,184,.08);
            border-radius: 12px 0 0 12px;
        }

        .opd-table td:last-child {
            border-right: 1px solid rgba(148,163,184,.08);
            border-radius: 0 12px 12px 0;
        }

        .opd-table tbody tr {
            transition: transform .2s ease, filter .2s ease;
        }

        .opd-table tbody tr:hover {
            transform: translateX(2px);
            filter: brightness(1.13);
        }

        .opd-token {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 66px;
            height: 29px;
            padding: 0 9px;
            border-radius: 9px;
            border: 1px solid rgba(139,92,246,.26);
            background: rgba(124,58,237,.13);
            color: #ddd6fe;
            font-weight: 950;
            letter-spacing: .025em;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.035);
        }

        .opd-person strong {
            display: block;
            color: #f1f5f9;
            font-size: 11.5px;
            font-weight: 900;
        }

        .opd-person small {
            display: block;
            margin-top: 3px;
            color: #64748b;
            font-size: 9.5px;
            font-weight: 700;
        }

        .opd-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 26px;
            padding: 5px 9px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,.13);
            background: rgba(148,163,184,.07);
            color: #cbd5e1;
            font-size: 9.5px;
            font-weight: 900;
            text-transform: capitalize;
        }

        .opd-status::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 8px currentColor;
        }

        .opd-status-waiting { color: #fbbf24; background: rgba(245,158,11,.09); border-color: rgba(245,158,11,.18); }
        .opd-status-vitals_pending { color: #f472b6; background: rgba(236,72,153,.09); border-color: rgba(236,72,153,.18); }
        .opd-status-called { color: #22d3ee; background: rgba(34,211,238,.09); border-color: rgba(34,211,238,.18); }
        .opd-status-completed { color: #34d399; background: rgba(52,211,153,.09); border-color: rgba(52,211,153,.18); }
        .opd-status-no_show { color: #fb7185; background: rgba(251,113,133,.09); border-color: rgba(251,113,133,.18); }
        .opd-status-token_generated { color: #a78bfa; background: rgba(167,139,250,.09); border-color: rgba(167,139,250,.18); }

        .opd-priority {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #8492aa;
            font-size: 9.5px;
            font-weight: 850;
            text-transform: capitalize;
        }

        .opd-priority-emergency { color: #fb7185; }
        .opd-priority-urgent { color: #fbbf24; }

        .opd-payment-method {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #aeb9ca;
            text-transform: capitalize;
        }

        .opd-money {
            color: #86efac;
            font-weight: 950;
            font-variant-numeric: tabular-nums;
        }

        .opd-empty {
            min-height: 205px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 24px;
            color: #64748b;
            text-align: center;
        }

        .opd-empty i {
            font-size: 31px;
            color: #8b5cf6;
            opacity: .7;
        }

        .opd-empty strong {
            color: #aab4c8;
            font-size: 12px;
        }

        .opd-empty small {
            max-width: 260px;
            font-size: 10.5px;
            line-height: 1.55;
        }

        .opd-doctor-list {
            display: grid;
            gap: 10px;
            padding: 0 13px 14px;
        }

        .opd-doctor-card {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 13px;
            align-items: center;
            padding: 13px;
            border-radius: 15px;
            border: 1px solid rgba(148,163,184,.10);
            background: rgba(7,9,19,.55);
        }

        .opd-doctor-card::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: 0;
            width: var(--progress, 0%);
            height: 2px;
            background: linear-gradient(90deg, #6d4cff, #22d3ee);
            box-shadow: 0 0 14px rgba(124,58,237,.55);
            transition: width .65s ease;
        }

        .opd-doctor-info strong {
            display: block;
            color: #eef2ff;
            font-size: 11.5px;
            font-weight: 900;
        }

        .opd-doctor-info small {
            display: block;
            margin-top: 4px;
            color: #64748b;
            font-size: 9.5px;
            font-weight: 700;
        }

        .opd-doctor-metrics {
            display: flex;
            gap: 12px;
            text-align: right;
        }

        .opd-doctor-metric span {
            display: block;
            color: #fff;
            font-size: 13px;
            font-weight: 950;
        }

        .opd-doctor-metric small {
            display: block;
            color: #64748b;
            font-size: 8.5px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .opd-install-alert,
        .opd-page-alert {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            margin-bottom: 18px;
            padding: 15px 16px;
            border-radius: 16px;
            border: 1px solid rgba(251,191,36,.22);
            background: rgba(245,158,11,.08);
            color: #fde68a;
            box-shadow: 0 15px 32px rgba(0,0,0,.24);
        }

        .opd-install-alert i,
        .opd-page-alert i {
            margin-top: 1px;
            font-size: 19px;
        }

        .opd-install-alert strong,
        .opd-page-alert strong {
            display: block;
            color: #fff7d6;
            font-size: 12px;
        }

        .opd-install-alert small,
        .opd-page-alert small {
            display: block;
            margin-top: 4px;
            color: #d7bd72;
            font-size: 10.5px;
            line-height: 1.55;
        }

        .opd-toast {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 999999;
            max-width: 390px;
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 14px 16px;
            border-radius: 15px;
            border: 1px solid rgba(251,113,133,.24);
            background: rgba(19, 8, 19, .94);
            color: #fecdd3;
            box-shadow: 0 22px 55px rgba(0,0,0,.5);
            backdrop-filter: blur(18px);
            transform: translateY(24px);
            opacity: 0;
            visibility: hidden;
            transition: opacity .24s ease, transform .24s ease, visibility .24s ease;
        }

        .opd-toast.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .opd-toast.success {
            border-color: rgba(52,211,153,.24);
            background: rgba(5, 23, 18, .94);
            color: #a7f3d0;
        }

        .opd-toast strong {
            display: block;
            color: #fff;
            font-size: 11.5px;
        }

        .opd-toast small {
            display: block;
            margin-top: 3px;
            font-size: 10px;
            line-height: 1.5;
        }

        .opd-skeleton {
            position: relative;
            overflow: hidden;
            color: transparent !important;
            border-radius: 7px;
            background: rgba(148,163,184,.08) !important;
        }

        .opd-skeleton::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.07), transparent);
            transform: translateX(-100%);
            animation: opdSkeleton 1.4s infinite;
        }

        @keyframes opdSkeleton {
            to { transform: translateX(100%); }
        }

        @media (max-width: 1380px) {
            .opd-filter-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .opd-filter-actions {
                grid-column: span 3;
                justify-content: flex-end;
            }
        }

        @media (max-width: 1199px) {
            .opd-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .opd-chart-grid,
            .opd-data-grid {
                grid-template-columns: 1fr;
            }

            .opd-action-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .opd-main-content {
                padding: 88px 13px 26px;
            }

            .opd-hero {
                padding: 21px 18px;
                border-radius: 22px;
            }

            .opd-hero-grid {
                grid-template-columns: 1fr;
            }

            .opd-clock-card {
                width: 100%;
                min-width: 0;
                text-align: left;
            }

            .opd-filter-grid {
                grid-template-columns: 1fr;
            }

            .opd-filter-actions {
                grid-column: auto;
            }

            .opd-btn-primary {
                flex: 1;
            }

            .opd-stats-grid,
            .opd-action-row {
                grid-template-columns: 1fr;
            }

            .opd-stat-card {
                min-height: 140px;
            }

            .opd-chart-body,
            .opd-status-chart-body {
                height: 285px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
        }
    </style>

    <style id="opd-dashboard-theme-v2">
        /*
        |--------------------------------------------------------------------------
        | OPD Dashboard UI V2
        |--------------------------------------------------------------------------
        | Presentation-only override. Existing PHP, AJAX, filters and workflows
        | remain unchanged.
        |--------------------------------------------------------------------------
        */

        html[data-opd-theme="dark"] {
            color-scheme: dark;
            --opd-page-bg: #070d1c;
            --opd-page-bg-2: #0a1022;
            --opd-surface: rgba(13, 22, 45, .92);
            --opd-surface-soft: rgba(12, 20, 41, .78);
            --opd-surface-solid: #0d1730;
            --opd-input-bg: #0a1227;
            --opd-ui-border: rgba(139, 92, 246, .20);
            --opd-ui-border-soft: rgba(148, 163, 184, .13);
            --opd-ui-text: #f8fafc;
            --opd-ui-muted: #9aa7bd;
            --opd-ui-subtle: #718096;
            --opd-table-row: rgba(8, 15, 32, .78);
            --opd-table-row-hover: rgba(20, 30, 58, .96);
            --opd-chart-grid: rgba(148, 163, 184, .09);
            --opd-top-glow: rgba(124, 58, 237, .20);
            --opd-shadow-v2: 0 18px 45px rgba(0, 0, 0, .28);
        }

        html[data-opd-theme="light"] {
            color-scheme: light;
            --opd-page-bg: #f4f7ff;
            --opd-page-bg-2: #edf2ff;
            --opd-surface: rgba(255, 255, 255, .94);
            --opd-surface-soft: rgba(255, 255, 255, .82);
            --opd-surface-solid: #ffffff;
            --opd-input-bg: #f7f9ff;
            --opd-ui-border: rgba(124, 58, 237, .16);
            --opd-ui-border-soft: rgba(100, 116, 139, .15);
            --opd-ui-text: #172033;
            --opd-ui-muted: #64748b;
            --opd-ui-subtle: #8792a6;
            --opd-table-row: #f8faff;
            --opd-table-row-hover: #f0f4ff;
            --opd-chart-grid: rgba(100, 116, 139, .12);
            --opd-top-glow: rgba(124, 58, 237, .10);
            --opd-shadow-v2: 0 16px 38px rgba(43, 55, 91, .10);
        }

        html,
        body.opd-dashboard-body {
            width: 100%;
            min-height: 100%;
        }

        body.opd-dashboard-body {
            background:
                radial-gradient(circle at 18% 0%, var(--opd-top-glow), transparent 31%),
                linear-gradient(145deg, var(--opd-page-bg) 0%, var(--opd-page-bg-2) 100%) !important;
            color: var(--opd-ui-text) !important;
            transition: background-color .25s ease, color .25s ease;
        }

        body.opd-dashboard-body::before {
            opacity: .16 !important;
            background-size: 54px 54px !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body::before {
            opacity: .22 !important;
            background-image:
                linear-gradient(rgba(99, 102, 241, .045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99, 102, 241, .035) 1px, transparent 1px) !important;
        }

        body.opd-dashboard-body .opd-orb {
            opacity: .10 !important;
            filter: blur(105px) !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-orb {
            opacity: .07 !important;
        }

        body.opd-dashboard-body .container-scroll {
            min-height: 100vh;
            background: transparent !important;
        }

        body.opd-dashboard-body .main-panel {
            min-width: 0;
            background: transparent !important;
        }

        body.opd-dashboard-body .opd-main-content {
            width: 100%;
            max-width: 100%;
            padding: 82px 18px 34px !important;
        }

        body.opd-dashboard-body .opd-glass,
        body.opd-dashboard-body .opd-panel {
            background: var(--opd-surface) !important;
            border: 1px solid var(--opd-ui-border-soft) !important;
            box-shadow: var(--opd-shadow-v2), inset 0 1px 0 rgba(255, 255, 255, .045) !important;
            backdrop-filter: blur(18px) saturate(120%) !important;
            -webkit-backdrop-filter: blur(18px) saturate(120%) !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-glass,
        html[data-opd-theme="light"] body.opd-dashboard-body .opd-panel {
            box-shadow: var(--opd-shadow-v2), inset 0 1px 0 rgba(255, 255, 255, .75) !important;
        }

        /* Compact heading panel matching the main ONEERP dashboard. */
        body.opd-dashboard-body .opd-hero {
            min-height: 0;
            margin-bottom: 13px !important;
            padding: 17px 19px !important;
            border-radius: 18px !important;
            overflow: hidden;
            background:
                radial-gradient(circle at 8% 0%, rgba(124, 58, 237, .26), transparent 34%),
                linear-gradient(115deg, rgba(32, 20, 77, .95), rgba(12, 23, 48, .96) 48%, rgba(11, 21, 43, .96)) !important;
            border-color: rgba(139, 92, 246, .28) !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-hero {
            background:
                radial-gradient(circle at 8% 0%, rgba(124, 58, 237, .14), transparent 35%),
                linear-gradient(115deg, #ffffff, #f8f9ff 50%, #f0f4ff) !important;
            border-color: rgba(124, 58, 237, .18) !important;
        }

        body.opd-dashboard-body .opd-hero::before {
            width: 270px !important;
            height: 270px !important;
            right: -115px !important;
            top: -150px !important;
            opacity: .30 !important;
        }

        body.opd-dashboard-body .opd-hero::after {
            opacity: .42;
        }

        body.opd-dashboard-body .opd-hero-grid {
            grid-template-columns: minmax(0, 1fr) auto !important;
            gap: 18px !important;
        }

        body.opd-dashboard-body .opd-live-pill {
            padding: 5px 9px !important;
            background: rgba(124, 58, 237, .16) !important;
            border-color: rgba(167, 139, 250, .30) !important;
            color: #ddd6fe !important;
            font-size: 9.5px !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-live-pill {
            color: #6d28d9 !important;
            background: rgba(124, 58, 237, .08) !important;
        }

        body.opd-dashboard-body .opd-hero h1 {
            margin: 8px 0 4px !important;
            font-size: clamp(23px, 2.1vw, 31px) !important;
            line-height: 1.08 !important;
            letter-spacing: -.035em !important;
            text-shadow: none !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-hero h1 span {
            background: linear-gradient(90deg, #1e293b, #6d28d9 62%, #2563eb) !important;
            -webkit-background-clip: text !important;
            background-clip: text !important;
        }

        body.opd-dashboard-body .opd-hero-subtitle {
            max-width: 760px;
            color: #a9b5ca !important;
            font-size: 11.5px !important;
            line-height: 1.45 !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-hero-subtitle {
            color: #64748b !important;
        }

        body.opd-dashboard-body .opd-hero-meta {
            margin-top: 10px !important;
            gap: 8px 15px !important;
            color: #d3d9e7 !important;
            font-size: 10px !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-hero-meta {
            color: #64748b !important;
        }

        body.opd-dashboard-body .opd-hero-side {
            position: relative;
            z-index: 3;
            display: flex;
            align-items: stretch;
            gap: 10px;
        }

        body.opd-dashboard-body .opd-theme-toggle {
            width: 46px;
            min-width: 46px;
            min-height: 100%;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            border: 1px solid rgba(167, 139, 250, .25);
            border-radius: 14px;
            background: rgba(6, 11, 25, .62);
            color: #ddd6fe;
            cursor: pointer;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.05);
            transition: transform .2s ease, border-color .2s ease, background .2s ease;
        }

        body.opd-dashboard-body .opd-theme-toggle:hover {
            transform: translateY(-2px);
            border-color: rgba(167, 139, 250, .55);
            background: rgba(124, 58, 237, .22);
        }

        body.opd-dashboard-body .opd-theme-toggle i {
            font-size: 17px;
        }

        body.opd-dashboard-body .opd-theme-toggle span {
            font-size: 8px;
            line-height: 1;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-theme-toggle {
            background: #ffffff;
            color: #6d28d9;
            border-color: rgba(124, 58, 237, .18);
            box-shadow: 0 8px 18px rgba(64, 76, 116, .10);
        }

        body.opd-dashboard-body .opd-clock-card {
            min-width: 190px !important;
            padding: 12px 15px !important;
            border-radius: 15px !important;
            background: rgba(5, 10, 24, .58) !important;
            border-color: rgba(167, 139, 250, .22) !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-clock-card {
            background: rgba(255, 255, 255, .85) !important;
            border-color: rgba(124, 58, 237, .15) !important;
            box-shadow: 0 8px 20px rgba(48, 62, 102, .08) !important;
        }

        body.opd-dashboard-body .opd-clock-label {
            color: #8290a9 !important;
            font-size: 8.5px !important;
        }

        body.opd-dashboard-body .opd-clock-time {
            margin: 2px 0 !important;
            font-size: 23px !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-clock-time {
            color: #172033 !important;
        }

        body.opd-dashboard-body .opd-clock-date {
            font-size: 9.5px !important;
        }

        /* Quick actions */
        body.opd-dashboard-body .opd-action-row {
            gap: 10px !important;
            margin-bottom: 13px !important;
        }

        body.opd-dashboard-body .opd-action-btn {
            min-height: 54px !important;
            padding: 10px 12px !important;
            border-radius: 13px !important;
            background: var(--opd-surface) !important;
            border-color: var(--opd-ui-border-soft) !important;
            color: var(--opd-ui-text) !important;
            box-shadow: var(--opd-shadow-v2), inset 0 1px 0 rgba(255,255,255,.035) !important;
        }

        body.opd-dashboard-body .opd-action-btn:hover {
            border-color: rgba(124, 58, 237, .42) !important;
            box-shadow: 0 14px 30px rgba(31, 38, 74, .18), 0 0 0 3px rgba(124,58,237,.06) !important;
        }

        body.opd-dashboard-body .opd-action-icon {
            width: 35px !important;
            min-width: 35px !important;
            height: 35px !important;
            border-radius: 10px !important;
            color: #c4b5fd !important;
            background: linear-gradient(145deg, rgba(124,58,237,.26), rgba(91,33,182,.12)) !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body .opd-action-icon {
            color: #6d28d9 !important;
            background: rgba(124, 58, 237, .09) !important;
        }

        body.opd-dashboard-body .opd-action-copy strong {
            font-size: 11.5px !important;
        }

        body.opd-dashboard-body .opd-action-copy small {
            color: var(--opd-ui-subtle) !important;
            font-size: 9px !important;
        }

        /* Filter bar */
        body.opd-dashboard-body .opd-filter-panel {
            margin-bottom: 14px !important;
            padding: 14px !important;
            border-radius: 16px !important;
        }

        body.opd-dashboard-body .opd-filter-grid {
            grid-template-columns: .95fr 1fr 1.15fr 1.15fr 1fr auto !important;
            gap: 9px !important;
        }

        body.opd-dashboard-body .opd-field label {
            margin-bottom: 5px !important;
            color: var(--opd-ui-muted) !important;
            font-size: 8.5px !important;
        }

        body.opd-dashboard-body .opd-control {
            height: 38px !important;
            border-radius: 9px !important;
            border-color: var(--opd-ui-border-soft) !important;
            background: var(--opd-input-bg) !important;
            color: var(--opd-ui-text) !important;
            font-size: 10.5px !important;
            box-shadow: none !important;
        }

        body.opd-dashboard-body .opd-control:focus {
            border-color: rgba(124, 58, 237, .60) !important;
            box-shadow: 0 0 0 3px rgba(124,58,237,.11) !important;
        }

        body.opd-dashboard-body .opd-control option {
            background: var(--opd-surface-solid) !important;
            color: var(--opd-ui-text) !important;
        }

        body.opd-dashboard-body .opd-btn {
            height: 38px !important;
            border-radius: 9px !important;
            font-size: 10.5px !important;
        }

        body.opd-dashboard-body .opd-btn-primary {
            background: linear-gradient(135deg, #6d4aff, #7c3aed 58%, #9333ea) !important;
            box-shadow: 0 9px 20px rgba(109, 74, 255, .25) !important;
        }

        body.opd-dashboard-body .opd-btn-ghost {
            width: 38px !important;
            color: var(--opd-ui-muted) !important;
            background: var(--opd-input-bg) !important;
            border-color: var(--opd-ui-border-soft) !important;
        }

        body.opd-dashboard-body .opd-refresh-meta {
            margin-top: 9px !important;
            color: var(--opd-ui-subtle) !important;
            font-size: 8.5px !important;
        }

        /* Bright KPI cards, matching the POS dashboard visual system. */
        body.opd-dashboard-body .opd-stats-grid {
            gap: 10px !important;
            margin-bottom: 14px !important;
        }

        body.opd-dashboard-body .opd-stat-card {
            min-height: 128px !important;
            padding: 15px !important;
            border-radius: 15px !important;
            color: #ffffff !important;
            border: 1px solid rgba(255,255,255,.13) !important;
            box-shadow: 0 15px 30px rgba(20, 27, 58, .20), inset 0 1px 0 rgba(255,255,255,.14) !important;
        }

        body.opd-dashboard-body .opd-stat-card:nth-child(1) {
            background: linear-gradient(135deg, #315efb 0%, #624bff 100%) !important;
        }
        body.opd-dashboard-body .opd-stat-card:nth-child(2) {
            background: linear-gradient(135deg, #6d43ff 0%, #9556f4 100%) !important;
        }
        body.opd-dashboard-body .opd-stat-card:nth-child(3) {
            background: linear-gradient(135deg, #078b4b 0%, #16a865 100%) !important;
        }
        body.opd-dashboard-body .opd-stat-card:nth-child(4) {
            background: linear-gradient(135deg, #f59e0b 0%, #fb7f25 100%) !important;
        }
        body.opd-dashboard-body .opd-stat-card:nth-child(5) {
            background: linear-gradient(135deg, #0784a7 0%, #16b4d2 100%) !important;
        }
        body.opd-dashboard-body .opd-stat-card:nth-child(6) {
            background: linear-gradient(135deg, #159447 0%, #27b867 100%) !important;
        }
        body.opd-dashboard-body .opd-stat-card:nth-child(7) {
            background: linear-gradient(135deg, #d91f51 0%, #f04468 100%) !important;
        }
        body.opd-dashboard-body .opd-stat-card:nth-child(8) {
            background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%) !important;
        }

        body.opd-dashboard-body .opd-stat-card::before {
            left: auto !important;
            right: -36px !important;
            top: -38px !important;
            bottom: auto !important;
            width: 112px !important;
            height: 112px !important;
            border: 22px solid rgba(255,255,255,.08) !important;
        }

        body.opd-dashboard-body .opd-stat-card::after {
            content: "";
            position: absolute;
            width: 66px;
            height: 66px;
            right: 12px;
            top: 10px;
            border-radius: 16px;
            background: rgba(255,255,255,.09);
            pointer-events: none;
        }

        body.opd-dashboard-body .opd-stat-card:hover {
            transform: translateY(-4px) !important;
            border-color: rgba(255,255,255,.30) !important;
            box-shadow: 0 20px 38px rgba(20, 27, 58, .27), inset 0 1px 0 rgba(255,255,255,.18) !important;
        }

        body.opd-dashboard-body .opd-stat-title,
        body.opd-dashboard-body .opd-stat-value,
        body.opd-dashboard-body .opd-stat-note {
            color: #ffffff !important;
        }

        body.opd-dashboard-body .opd-stat-title {
            font-size: 8.5px !important;
            opacity: .92;
        }

        body.opd-dashboard-body .opd-stat-icon {
            position: relative;
            z-index: 2;
            width: 36px !important;
            min-width: 36px !important;
            height: 36px !important;
            border-radius: 11px !important;
            color: #ffffff !important;
            background: rgba(255,255,255,.12) !important;
            border-color: rgba(255,255,255,.12) !important;
            box-shadow: none !important;
        }

        body.opd-dashboard-body .opd-stat-value {
            margin-top: 9px !important;
            font-size: clamp(24px, 2vw, 31px) !important;
        }

        body.opd-dashboard-body .opd-stat-note {
            margin-top: 8px !important;
            font-size: 8.5px !important;
            opacity: .76;
        }

        body.opd-dashboard-body .opd-stat-note i {
            color: #ffffff !important;
        }

        /* Charts and content panels */
        body.opd-dashboard-body .opd-chart-grid,
        body.opd-dashboard-body .opd-data-grid {
            gap: 11px !important;
            margin-bottom: 14px !important;
        }

        body.opd-dashboard-body .opd-panel {
            border-radius: 15px !important;
        }

        body.opd-dashboard-body .opd-panel-head {
            padding: 13px 14px 10px !important;
            border-bottom: 1px solid var(--opd-ui-border-soft);
        }

        body.opd-dashboard-body .opd-panel-title {
            color: var(--opd-ui-text) !important;
            font-size: 11.5px !important;
        }

        body.opd-dashboard-body .opd-panel-subtitle {
            margin-top: 2px !important;
            color: var(--opd-ui-subtle) !important;
            font-size: 8.5px !important;
        }

        body.opd-dashboard-body .opd-panel-link {
            color: #8b5cf6 !important;
            font-size: 9.5px !important;
        }

        html[data-opd-theme="dark"] body.opd-dashboard-body .opd-panel-link {
            color: #b9a4ff !important;
        }

        body.opd-dashboard-body .opd-chart-body,
        body.opd-dashboard-body .opd-status-chart-body {
            height: 285px !important;
        }

        body.opd-dashboard-body .opd-table {
            color: var(--opd-ui-text) !important;
            border-spacing: 0 5px !important;
        }

        body.opd-dashboard-body .opd-table th {
            color: var(--opd-ui-subtle) !important;
            font-size: 8px !important;
        }

        body.opd-dashboard-body .opd-table td {
            padding: 10px !important;
            background: var(--opd-table-row) !important;
            color: var(--opd-ui-muted) !important;
            border-color: var(--opd-ui-border-soft) !important;
            font-size: 9.5px !important;
        }

        body.opd-dashboard-body .opd-table tbody tr:hover td {
            background: var(--opd-table-row-hover) !important;
        }

        body.opd-dashboard-body .opd-person strong,
        body.opd-dashboard-body .opd-doctor-info strong,
        body.opd-dashboard-body .opd-doctor-metric span {
            color: var(--opd-ui-text) !important;
        }

        body.opd-dashboard-body .opd-person small,
        body.opd-dashboard-body .opd-doctor-info small,
        body.opd-dashboard-body .opd-doctor-metric small {
            color: var(--opd-ui-subtle) !important;
        }

        body.opd-dashboard-body .opd-token {
            height: 27px !important;
            color: #8b5cf6 !important;
            background: rgba(124,58,237,.09) !important;
        }

        html[data-opd-theme="dark"] body.opd-dashboard-body .opd-token {
            color: #ddd6fe !important;
        }

        body.opd-dashboard-body .opd-doctor-list {
            gap: 8px !important;
            padding: 10px 11px 12px !important;
        }

        body.opd-dashboard-body .opd-doctor-card {
            padding: 11px !important;
            border-radius: 11px !important;
            border-color: var(--opd-ui-border-soft) !important;
            background: var(--opd-table-row) !important;
        }

        body.opd-dashboard-body .opd-empty {
            color: var(--opd-ui-subtle) !important;
        }

        body.opd-dashboard-body .opd-empty strong {
            color: var(--opd-ui-muted) !important;
        }

        body.opd-dashboard-body .opd-install-alert,
        body.opd-dashboard-body .opd-page-alert {
            border-radius: 13px !important;
        }

        body.opd-dashboard-body .opd-toast {
            background: var(--opd-surface-solid) !important;
            color: #ef4444 !important;
            border-color: rgba(239,68,68,.24) !important;
        }

        body.opd-dashboard-body .opd-toast strong {
            color: var(--opd-ui-text) !important;
        }

        body.opd-dashboard-body .opd-toast.success {
            color: #16a34a !important;
            border-color: rgba(22,163,74,.25) !important;
        }

        html[data-opd-theme="light"] body.opd-dashboard-body input[type="date"]::-webkit-calendar-picker-indicator {
            filter: none;
            opacity: .7;
        }

        html[data-opd-theme="dark"] body.opd-dashboard-body input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            opacity: .72;
        }

        /* Keep sidebar/topbar visually integrated where shared partials use common classes. */
        html[data-opd-theme="dark"] body.opd-dashboard-body .sidebar,
        html[data-opd-theme="dark"] body.opd-dashboard-body .topbar,
        html[data-opd-theme="dark"] body.opd-dashboard-body .navbar {
            color-scheme: dark;
        }

        @media (max-width: 1380px) {
            body.opd-dashboard-body .opd-filter-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }

            body.opd-dashboard-body .opd-filter-actions {
                grid-column: span 3 !important;
            }
        }

        @media (max-width: 1199px) {
            body.opd-dashboard-body .opd-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            body.opd-dashboard-body .opd-chart-grid,
            body.opd-dashboard-body .opd-data-grid {
                grid-template-columns: 1fr !important;
            }

            body.opd-dashboard-body .opd-action-row {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }

        @media (max-width: 767px) {
            body.opd-dashboard-body .opd-main-content {
                padding: 74px 10px 24px !important;
            }

            body.opd-dashboard-body .opd-hero {
                padding: 15px !important;
            }

            body.opd-dashboard-body .opd-hero-grid {
                grid-template-columns: 1fr !important;
            }

            body.opd-dashboard-body .opd-hero-side {
                width: 100%;
            }

            body.opd-dashboard-body .opd-clock-card {
                flex: 1;
                min-width: 0 !important;
                text-align: left !important;
            }

            body.opd-dashboard-body .opd-filter-grid {
                grid-template-columns: 1fr !important;
            }

            body.opd-dashboard-body .opd-filter-actions {
                grid-column: auto !important;
            }

            body.opd-dashboard-body .opd-refresh-meta {
                align-items: flex-start !important;
                flex-direction: column !important;
            }

            body.opd-dashboard-body .opd-stats-grid,
            body.opd-dashboard-body .opd-action-row {
                grid-template-columns: 1fr !important;
            }

            body.opd-dashboard-body .opd-chart-body,
            body.opd-dashboard-body .opd-status-chart-body {
                height: 260px !important;
            }
        }
    </style>

</head>
<body class="opd-dashboard-body">
    <div class="opd-orb opd-orb-one"></div>
    <div class="opd-orb opd-orb-two"></div>

    <div class="container-scroll">
        <div class="sidebar" id="dashboardSidebar">
            <?php
            $sidebarPartial = __DIR__ . '/partials/sidebar.php';
            if (is_file($sidebarPartial)) {
                include $sidebarPartial;
            } else {
                echo '<div style="padding:20px;color:#fff">Sidebar file not found.</div>';
            }
            ?>
        </div>

        <div class="main-panel">
            <?php
            $topbarPartial = __DIR__ . '/partials/topbar.php';
            if (is_file($topbarPartial)) {
                include $topbarPartial;
            }
            ?>

            <main class="opd-main-content">
                <?php if (!empty($missing_opd_tables)): ?>
                    <div class="opd-install-alert">
                        <i class="bi bi-database-exclamation"></i>
                        <div>
                            <strong>OPD database migration incomplete</strong>
                            <small>
                                Missing tables: <?= htmlspecialchars(implode(', ', $missing_opd_tables), ENT_QUOTES, 'UTF-8') ?>.
                                Pehle provided <code>oneerp_opd_database.sql</code> import karein.
                            </small>
                        </div>
                    </div>
                <?php endif; ?>

                <section class="opd-hero opd-glass">
                    <div class="opd-hero-grid">
                        <div>
                            <div class="opd-live-pill">
                                <span class="opd-live-dot"></span>
                                Live OPD Operations
                            </div>

                            <h1><span>OPD Command Center</span></h1>
                            <p class="opd-hero-subtitle">
                                Patient flow, token queue, vitals, doctor activity aur fee collection ko ek live dashboard se monitor karein.
                            </p>

                            <div class="opd-hero-meta">
                                <span><i class="bi bi-hospital"></i><?= htmlspecialchars($business_name, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($business_address !== ''): ?>
                                    <span><i class="bi bi-geo-alt"></i><?= htmlspecialchars($business_address, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                                <?php if ($business_phone !== ''): ?>
                                    <span><i class="bi bi-telephone"></i><?= htmlspecialchars($business_phone, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="opd-hero-side">
                            <button type="button" class="opd-theme-toggle" id="opdThemeToggle" aria-label="Switch dashboard theme" title="Switch light / dark mode">
                                <i class="bi bi-sun-fill" id="opdThemeIcon"></i>
                                <span id="opdThemeLabel">Light</span>
                            </button>

                            <div class="opd-clock-card">
                                <div class="opd-clock-label">Pakistan Standard Time</div>
                                <div class="opd-clock-time" id="liveClock">--:--:--</div>
                                <div class="opd-clock-date" id="liveDate"><?= htmlspecialchars($today_display, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="opd-action-row" aria-label="OPD quick actions">
                    <a class="opd-action-btn" href="opd_reception.php">
                        <span class="opd-action-icon"><i class="bi bi-ticket-perforated"></i></span>
                        <span class="opd-action-copy">
                            <strong>New Token</strong>
                            <small>Reception & fee collection</small>
                        </span>
                    </a>

                    <a class="opd-action-btn" href="opd_patient_form.php">
                        <span class="opd-action-icon"><i class="bi bi-person-plus"></i></span>
                        <span class="opd-action-copy">
                            <strong>Register Patient</strong>
                            <small>Create a new MR record</small>
                        </span>
                    </a>

                    <a class="opd-action-btn" href="opd_queue.php">
                        <span class="opd-action-icon"><i class="bi bi-people"></i></span>
                        <span class="opd-action-copy">
                            <strong>Open Queue</strong>
                            <small>Call and complete visits</small>
                        </span>
                    </a>

                    <a class="opd-action-btn" href="opd_vitals.php">
                        <span class="opd-action-icon"><i class="bi bi-heart-pulse"></i></span>
                        <span class="opd-action-copy">
                            <strong>Record Vitals</strong>
                            <small>Triage pending patients</small>
                        </span>
                    </a>
                </section>

                <section class="opd-filter-panel opd-glass">
                    <form id="dashboardFilters" autocomplete="off">
                        <div class="opd-filter-grid">
                            <div class="opd-field">
                                <label for="filterDate">Date</label>
                                <input type="date" class="opd-control" id="filterDate" name="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="opd-field">
                                <label for="filterBranch">Branch</label>
                                <select class="opd-control" id="filterBranch" name="branch_id">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= (int)$branch['id'] ?>" <?= ((int)$branch['id'] === $session_branch_id) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($branch['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="opd-field">
                                <label for="filterDepartment">Department</label>
                                <select class="opd-control" id="filterDepartment" name="department_id">
                                    <option value="0">All Departments</option>
                                    <?php foreach ($departments as $department): ?>
                                        <option
                                            value="<?= (int)$department['id'] ?>"
                                            data-branch="<?= (int)$department['branch_id'] ?>">
                                            <?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="opd-field">
                                <label for="filterDoctor">Doctor</label>
                                <select class="opd-control" id="filterDoctor" name="doctor_id">
                                    <option value="0">All Doctors</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <option
                                            value="<?= (int)$doctor['id'] ?>"
                                            data-branch="<?= (int)$doctor['branch_id'] ?>"
                                            data-department="<?= (int)$doctor['department_id'] ?>">
                                            <?= htmlspecialchars($doctor['doctor_name'], ENT_QUOTES, 'UTF-8') ?><?= $doctor['room_number'] ? ' · Room ' . htmlspecialchars($doctor['room_number'], ENT_QUOTES, 'UTF-8') : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="opd-field">
                                <label for="filterVisitType">Visit Type</label>
                                <select class="opd-control" id="filterVisitType" name="visit_type">
                                    <option value="">All Visit Types</option>
                                    <option value="new">New</option>
                                    <option value="follow_up">Follow Up</option>
                                    <option value="general">General</option>
                                    <option value="emergency">Emergency</option>
                                    <option value="free">Free</option>
                                </select>
                            </div>

                            <div class="opd-filter-actions">
                                <button type="submit" class="opd-btn opd-btn-primary" id="applyFilters">
                                    <i class="bi bi-funnel"></i>
                                    Apply
                                </button>
                                <button type="button" class="opd-btn opd-btn-ghost" id="resetFilters" title="Reset filters">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            </div>
                        </div>

                        <div class="opd-refresh-meta">
                            <span id="refreshStatus"><i class="bi bi-broadcast"></i> Live data ready</span>
                            <span><i class="bi bi-clock-history"></i> Auto refresh every 30 seconds · Last update: <strong id="lastUpdated">—</strong></span>
                        </div>
                    </form>
                </section>

                <section class="opd-stats-grid">
                    <article class="opd-stat-card opd-accent-purple">
                        <div class="opd-stat-top">
                            <div class="opd-stat-title">Total Patients</div>
                            <div class="opd-stat-icon"><i class="bi bi-person-hearts"></i></div>
                        </div>
                        <div class="opd-stat-value" data-stat="total_patients">0</div>
                        <div class="opd-stat-note"><i class="bi bi-person-check"></i> Unique patients in selected view</div>
                    </article>

                    <article class="opd-stat-card opd-accent-blue">
                        <div class="opd-stat-top">
                            <div class="opd-stat-title">Total Tokens</div>
                            <div class="opd-stat-icon"><i class="bi bi-ticket-detailed"></i></div>
                        </div>
                        <div class="opd-stat-value" data-stat="total_tokens">0</div>
                        <div class="opd-stat-note"><i class="bi bi-arrow-up-right"></i> Tokens generated today</div>
                    </article>

                    <article class="opd-stat-card opd-accent-amber">
                        <div class="opd-stat-top">
                            <div class="opd-stat-title">Waiting</div>
                            <div class="opd-stat-icon"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                        <div class="opd-stat-value" data-stat="waiting">0</div>
                        <div class="opd-stat-note"><i class="bi bi-people"></i> Ready in doctor queue</div>
                    </article>

                    <article class="opd-stat-card opd-accent-pink">
                        <div class="opd-stat-top">
                            <div class="opd-stat-title">Vitals Pending</div>
                            <div class="opd-stat-icon"><i class="bi bi-heart-pulse"></i></div>
                        </div>
                        <div class="opd-stat-value" data-stat="vitals_pending">0</div>
                        <div class="opd-stat-note"><i class="bi bi-activity"></i> Awaiting triage entry</div>
                    </article>

                    <article class="opd-stat-card opd-accent-cyan">
                        <div class="opd-stat-top">
                            <div class="opd-stat-title">Called</div>
                            <div class="opd-stat-icon"><i class="bi bi-megaphone"></i></div>
                        </div>
                        <div class="opd-stat-value" data-stat="called">0</div>
                        <div class="opd-stat-note"><i class="bi bi-door-open"></i> Patients called to room</div>
                    </article>

                    <article class="opd-stat-card opd-accent-green">
                        <div class="opd-stat-top">
                            <div class="opd-stat-title">Completed</div>
                            <div class="opd-stat-icon"><i class="bi bi-check2-circle"></i></div>
                        </div>
                        <div class="opd-stat-value" data-stat="completed">0</div>
                        <div class="opd-stat-note"><i class="bi bi-graph-up-arrow"></i> Visits completed successfully</div>
                    </article>

                    <article class="opd-stat-card opd-accent-red">
                        <div class="opd-stat-top">
                            <div class="opd-stat-title">No Show</div>
                            <div class="opd-stat-icon"><i class="bi bi-person-x"></i></div>
                        </div>
                        <div class="opd-stat-value" data-stat="no_show">0</div>
                        <div class="opd-stat-note"><i class="bi bi-exclamation-circle"></i> Missed or absent tokens</div>
                    </article>

                    <article class="opd-stat-card opd-accent-violet">
                        <div class="opd-stat-top">
                            <div class="opd-stat-title">OPD Income</div>
                            <div class="opd-stat-icon"><i class="bi bi-cash-stack"></i></div>
                        </div>
                        <div class="opd-stat-value" data-stat="total_income" data-currency="1">Rs. 0</div>
                        <div class="opd-stat-note"><i class="bi bi-wallet2"></i> Paid consultation fees</div>
                    </article>
                </section>

                <section class="opd-chart-grid">
                    <article class="opd-panel opd-glass">
                        <header class="opd-panel-head">
                            <div class="opd-panel-title-wrap">
                                <h2 class="opd-panel-title">7-Day OPD Activity</h2>
                                <div class="opd-panel-subtitle">Visits, completed tokens and income trend</div>
                            </div>
                            <a class="opd-panel-link" href="opd_reports.php"><i class="bi bi-arrow-up-right-circle"></i> Full Reports</a>
                        </header>
                        <div class="opd-chart-body">
                            <canvas id="trendChart" aria-label="Seven day OPD activity chart"></canvas>
                        </div>
                    </article>

                    <article class="opd-panel opd-glass">
                        <header class="opd-panel-head">
                            <div class="opd-panel-title-wrap">
                                <h2 class="opd-panel-title">Queue Distribution</h2>
                                <div class="opd-panel-subtitle">Live visit status mix</div>
                            </div>
                            <a class="opd-panel-link" href="opd_queue.php"><i class="bi bi-people"></i> Queue</a>
                        </header>
                        <div class="opd-status-chart-body">
                            <canvas id="statusChart" aria-label="Queue status distribution chart"></canvas>
                        </div>
                    </article>
                </section>

                <section class="opd-data-grid">
                    <article class="opd-panel opd-glass">
                        <header class="opd-panel-head">
                            <div class="opd-panel-title-wrap">
                                <h2 class="opd-panel-title">Current Queue</h2>
                                <div class="opd-panel-subtitle">Active tokens waiting, in triage or called</div>
                            </div>
                            <a class="opd-panel-link" href="opd_queue.php"><i class="bi bi-box-arrow-up-right"></i> Manage Queue</a>
                        </header>

                        <div class="opd-table-wrap" id="queueTableWrap">
                            <table class="opd-table">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Patient</th>
                                        <th>Doctor / Dept.</th>
                                        <th>Arrival</th>
                                        <th>Wait</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="queueTableBody">
                                    <tr>
                                        <td colspan="7"><div class="opd-skeleton" style="height:32px">Loading</div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="opd-panel opd-glass">
                        <header class="opd-panel-head">
                            <div class="opd-panel-title-wrap">
                                <h2 class="opd-panel-title">Recent Payments</h2>
                                <div class="opd-panel-subtitle">Latest OPD consultation receipts</div>
                            </div>
                            <a class="opd-panel-link" href="opd_payments.php"><i class="bi bi-receipt"></i> All Payments</a>
                        </header>

                        <div class="opd-table-wrap">
                            <table class="opd-table" style="min-width:560px">
                                <thead>
                                    <tr>
                                        <th>Receipt</th>
                                        <th>Patient</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody id="paymentsTableBody">
                                    <tr>
                                        <td colspan="5"><div class="opd-skeleton" style="height:32px">Loading</div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </section>

                <section class="opd-panel opd-glass">
                    <header class="opd-panel-head">
                        <div class="opd-panel-title-wrap">
                            <h2 class="opd-panel-title">Doctor Performance</h2>
                            <div class="opd-panel-subtitle">Selected date workload, completion rate and collection</div>
                        </div>
                        <a class="opd-panel-link" href="opd_doctors.php"><i class="bi bi-person-badge"></i> Manage Doctors</a>
                    </header>
                    <div class="opd-doctor-list" id="doctorPerformanceList">
                        <div class="opd-skeleton" style="height:64px">Loading</div>
                        <div class="opd-skeleton" style="height:64px">Loading</div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <div class="opd-toast" id="dashboardToast" role="status" aria-live="polite">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong id="toastTitle">Dashboard Error</strong>
            <small id="toastMessage">Unable to load dashboard data.</small>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        const API_URL = 'ajax_opd_dashboard_stats.php';
        const DEFAULT_DATE = <?= json_encode($today) ?>;
        const DEFAULT_BRANCH = <?= (int)$session_branch_id ?>;
        const AUTO_REFRESH_MS = 30000;

        const filtersForm = document.getElementById('dashboardFilters');
        const dateInput = document.getElementById('filterDate');
        const branchSelect = document.getElementById('filterBranch');
        const departmentSelect = document.getElementById('filterDepartment');
        const doctorSelect = document.getElementById('filterDoctor');
        const visitTypeSelect = document.getElementById('filterVisitType');
        const resetButton = document.getElementById('resetFilters');
        const refreshStatus = document.getElementById('refreshStatus');
        const lastUpdated = document.getElementById('lastUpdated');
        const queueTableBody = document.getElementById('queueTableBody');
        const paymentsTableBody = document.getElementById('paymentsTableBody');
        const doctorPerformanceList = document.getElementById('doctorPerformanceList');
        const toast = document.getElementById('dashboardToast');
        const toastTitle = document.getElementById('toastTitle');
        const toastMessage = document.getElementById('toastMessage');
        const themeToggle = document.getElementById('opdThemeToggle');
        const themeIcon = document.getElementById('opdThemeIcon');
        const themeLabel = document.getElementById('opdThemeLabel');

        let currentRequestController = null;
        let trendChart = null;
        let statusChart = null;
        let autoRefreshTimer = null;
        let toastTimer = null;

        const numberFormatter = new Intl.NumberFormat('en-PK', {
            maximumFractionDigits: 0
        });

        const currencyFormatter = new Intl.NumberFormat('en-PK', {
            style: 'currency',
            currency: 'PKR',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function prettyText(value) {
            return String(value ?? '')
                .replace(/_/g, ' ')
                .replace(/\b\w/g, char => char.toUpperCase());
        }

        function formatCurrency(value) {
            return currencyFormatter.format(Number(value || 0)).replace('PKR', 'Rs.');
        }

        function showToast(title, message, type = 'error') {
            window.clearTimeout(toastTimer);
            toast.classList.toggle('success', type === 'success');
            toast.querySelector('i').className = type === 'success'
                ? 'bi bi-check-circle'
                : 'bi bi-exclamation-triangle';
            toastTitle.textContent = title;
            toastMessage.textContent = message;
            toast.classList.add('show');

            toastTimer = window.setTimeout(() => {
                toast.classList.remove('show');
            }, 5200);
        }

        function setRefreshing(isRefreshing) {
            refreshStatus.classList.toggle('opd-refreshing', isRefreshing);

            if (isRefreshing) {
                refreshStatus.innerHTML = '<i class="bi bi-arrow-repeat"></i> Refreshing live data...';
                return;
            }

            // Keep explicit success/error text set by loadDashboard().
            if (refreshStatus.textContent.includes('Refreshing live data')) {
                refreshStatus.innerHTML = '<i class="bi bi-broadcast"></i> Live data connected';
            }
        }

        function animateValue(element, nextValue, currency = false) {
            const target = Number(nextValue || 0);
            const current = Number(element.dataset.numericValue || 0);
            const duration = 650;
            const startTime = performance.now();

            function frame(now) {
                const progress = Math.min(1, (now - startTime) / duration);
                const eased = 1 - Math.pow(1 - progress, 3);
                const value = current + ((target - current) * eased);

                element.textContent = currency
                    ? formatCurrency(value)
                    : numberFormatter.format(Math.round(value));

                if (progress < 1) {
                    requestAnimationFrame(frame);
                } else {
                    element.dataset.numericValue = String(target);
                }
            }

            requestAnimationFrame(frame);
        }

        function renderStats(stats) {
            document.querySelectorAll('[data-stat]').forEach(element => {
                const key = element.dataset.stat;
                const currency = element.dataset.currency === '1';
                animateValue(element, stats?.[key] ?? 0, currency);
            });
        }

        function statusBadge(status) {
            const safeStatus = String(status || 'unknown').replace(/[^a-z0-9_\-]/gi, '');
            return `<span class="opd-status opd-status-${safeStatus}">${escapeHtml(prettyText(status))}</span>`;
        }

        function renderQueue(rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                queueTableBody.innerHTML = `
                    <tr>
                        <td colspan="7" style="padding:0;background:transparent;border:0">
                            <div class="opd-empty">
                                <i class="bi bi-people"></i>
                                <strong>No active queue</strong>
                                <small>Selected filters ke liye koi waiting, vitals pending ya called token nahi hai.</small>
                            </div>
                        </td>
                    </tr>`;
                return;
            }

            queueTableBody.innerHTML = rows.map(row => {
                const ageGender = [
                    row.age_years !== null && row.age_years !== undefined ? `${row.age_years} yrs` : '',
                    prettyText(row.gender)
                ].filter(Boolean).join(' · ');

                const doctorMeta = [
                    row.department_name,
                    row.room_number ? `Room ${row.room_number}` : ''
                ].filter(Boolean).join(' · ');

                const priorityClass = `opd-priority-${String(row.priority || '').replace(/[^a-z0-9_\-]/gi, '')}`;

                return `
                    <tr>
                        <td><span class="opd-token">${escapeHtml(row.token_code)}</span></td>
                        <td>
                            <div class="opd-person">
                                <strong>${escapeHtml(row.patient_name)}</strong>
                                <small>${escapeHtml(row.mr_number)}${ageGender ? ` · ${escapeHtml(ageGender)}` : ''}</small>
                            </div>
                        </td>
                        <td>
                            <div class="opd-person">
                                <strong>${escapeHtml(row.doctor_name)}</strong>
                                <small>${escapeHtml(doctorMeta)}</small>
                            </div>
                        </td>
                        <td>${escapeHtml(row.arrival_time || '—')}</td>
                        <td>${numberFormatter.format(Number(row.waiting_minutes || 0))} min</td>
                        <td><span class="opd-priority ${priorityClass}"><i class="bi bi-circle-fill" style="font-size:5px"></i>${escapeHtml(prettyText(row.priority))}</span></td>
                        <td>${statusBadge(row.queue_status)}</td>
                    </tr>`;
            }).join('');
        }

        function paymentMethodIcon(method) {
            const normalized = String(method || '').toLowerCase();
            if (normalized.includes('cash')) return 'bi-cash';
            if (normalized.includes('card')) return 'bi-credit-card';
            if (normalized.includes('bank')) return 'bi-bank';
            if (normalized.includes('jazz') || normalized.includes('easy')) return 'bi-phone';
            if (normalized.includes('free')) return 'bi-gift';
            return 'bi-wallet2';
        }

        function renderPayments(rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                paymentsTableBody.innerHTML = `
                    <tr>
                        <td colspan="5" style="padding:0;background:transparent;border:0">
                            <div class="opd-empty">
                                <i class="bi bi-receipt"></i>
                                <strong>No payments found</strong>
                                <small>Selected date aur filters ke liye paid ya free OPD receipt nahi mili.</small>
                            </div>
                        </td>
                    </tr>`;
                return;
            }

            paymentsTableBody.innerHTML = rows.map(row => `
                <tr>
                    <td>
                        <div class="opd-person">
                            <strong>${escapeHtml(row.receipt_number)}</strong>
                            <small>${escapeHtml(row.token_code)}</small>
                        </div>
                    </td>
                    <td>
                        <div class="opd-person">
                            <strong>${escapeHtml(row.patient_name)}</strong>
                            <small>${escapeHtml(row.mr_number)}</small>
                        </div>
                    </td>
                    <td><span class="opd-payment-method"><i class="bi ${paymentMethodIcon(row.payment_method)}"></i>${escapeHtml(prettyText(row.payment_method))}</span></td>
                    <td><span class="opd-money">${formatCurrency(row.net_amount)}</span></td>
                    <td>${escapeHtml(row.paid_time || '—')}</td>
                </tr>`).join('');
        }

        function renderDoctorPerformance(rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                doctorPerformanceList.innerHTML = `
                    <div class="opd-empty">
                        <i class="bi bi-person-badge"></i>
                        <strong>No doctor activity</strong>
                        <small>Selected date aur filters ke liye doctor-wise visit data available nahi hai.</small>
                    </div>`;
                return;
            }

            doctorPerformanceList.innerHTML = rows.map(row => `
                <article class="opd-doctor-card" style="--progress:${Math.max(0, Math.min(100, Number(row.completion_rate || 0)))}%">
                    <div class="opd-doctor-info">
                        <strong>${escapeHtml(row.doctor_name)}</strong>
                        <small>${escapeHtml(row.department_name)}${row.room_number ? ` · Room ${escapeHtml(row.room_number)}` : ''} · Completion ${numberFormatter.format(Number(row.completion_rate || 0))}%</small>
                    </div>
                    <div class="opd-doctor-metrics">
                        <div class="opd-doctor-metric">
                            <span>${numberFormatter.format(Number(row.total_visits || 0))}</span>
                            <small>Visits</small>
                        </div>
                        <div class="opd-doctor-metric">
                            <span>${numberFormatter.format(Number(row.completed_visits || 0))}</span>
                            <small>Done</small>
                        </div>
                        <div class="opd-doctor-metric">
                            <span>${formatCurrency(row.income)}</span>
                            <small>Income</small>
                        </div>
                    </div>
                </article>`).join('');
        }

        function currentTheme() {
            return document.documentElement.getAttribute('data-opd-theme') === 'light'
                ? 'light'
                : 'dark';
        }

        function chartTextColor() {
            return currentTheme() === 'light' ? '#64748b' : '#94a3b8';
        }

        function chartGridColor() {
            return currentTheme() === 'light'
                ? 'rgba(100,116,139,.12)'
                : 'rgba(148,163,184,.075)';
        }

        function chartTooltipPalette() {
            if (currentTheme() === 'light') {
                return {
                    background: 'rgba(255,255,255,.98)',
                    title: '#172033',
                    body: '#64748b',
                    border: 'rgba(124,58,237,.18)',
                    doughnutBorder: '#ffffff'
                };
            }

            return {
                background: 'rgba(5,7,15,.97)',
                title: '#ffffff',
                body: '#cbd5e1',
                border: 'rgba(167,139,250,.24)',
                doughnutBorder: 'rgba(5,7,15,.95)'
            };
        }

        function syncThemeButton() {
            if (!themeToggle || !themeIcon || !themeLabel) {
                return;
            }

            const lightMode = currentTheme() === 'light';
            themeIcon.className = lightMode ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
            themeLabel.textContent = lightMode ? 'Dark' : 'Light';
            themeToggle.setAttribute(
                'aria-label',
                lightMode ? 'Switch to dark mode' : 'Switch to light mode'
            );
            themeToggle.setAttribute(
                'title',
                lightMode ? 'Switch to dark mode' : 'Switch to light mode'
            );
        }

        function applyChartTheme() {
            const textColor = chartTextColor();
            const gridColor = chartGridColor();
            const tooltip = chartTooltipPalette();

            if (trendChart) {
                trendChart.options.plugins.legend.labels.color = textColor;
                trendChart.options.plugins.tooltip.backgroundColor = tooltip.background;
                trendChart.options.plugins.tooltip.titleColor = tooltip.title;
                trendChart.options.plugins.tooltip.bodyColor = tooltip.body;
                trendChart.options.plugins.tooltip.borderColor = tooltip.border;
                trendChart.options.scales.x.grid.color = gridColor;
                trendChart.options.scales.x.ticks.color = textColor;
                trendChart.options.scales.y.grid.color = gridColor;
                trendChart.options.scales.y.ticks.color = textColor;
                trendChart.update('none');
            }

            if (statusChart) {
                statusChart.options.plugins.legend.labels.color = textColor;
                statusChart.options.plugins.tooltip.backgroundColor = tooltip.background;
                statusChart.options.plugins.tooltip.titleColor = tooltip.title;
                statusChart.options.plugins.tooltip.bodyColor = tooltip.body;
                statusChart.options.plugins.tooltip.borderColor = tooltip.border;

                if (statusChart.data.datasets?.[0]) {
                    statusChart.data.datasets[0].borderColor = tooltip.doughnutBorder;
                }

                statusChart.update('none');
            }
        }

        function applyTheme(theme, persist = true) {
            const normalizedTheme = theme === 'light' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-opd-theme', normalizedTheme);
            document.documentElement.setAttribute('data-pos-theme', normalizedTheme);
            document.body.setAttribute('data-opd-theme', normalizedTheme);

            if (persist) {
                try {
                    localStorage.setItem('oneerp-opd-theme', normalizedTheme);
                } catch (error) {
                    console.warn('Unable to save OPD theme preference.', error);
                }
            }

            syncThemeButton();
            applyChartTheme();
        }

        function renderTrendChart(trend) {
            if (typeof Chart === 'undefined') {
                return;
            }

            const canvas = document.getElementById('trendChart');
            const context = canvas.getContext('2d');
            const visitGradient = context.createLinearGradient(0, 0, 0, 300);
            visitGradient.addColorStop(0, 'rgba(124, 58, 237, .42)');
            visitGradient.addColorStop(1, 'rgba(124, 58, 237, 0)');

            const chartData = {
                labels: (trend?.labels || []).map((label, index) => `${label} ${trend?.dates?.[index] || ''}`),
                datasets: [
                    {
                        label: 'Visits',
                        data: trend?.visits || [],
                        borderColor: '#a78bfa',
                        backgroundColor: visitGradient,
                        borderWidth: 2.5,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#c4b5fd',
                        tension: .38,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Completed',
                        data: trend?.completed || [],
                        borderColor: '#34d399',
                        backgroundColor: 'rgba(52, 211, 153, .08)',
                        borderWidth: 2,
                        pointRadius: 2.5,
                        tension: .38,
                        fill: false,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Income',
                        data: trend?.income || [],
                        borderColor: '#22d3ee',
                        backgroundColor: 'rgba(34, 211, 238, .10)',
                        borderWidth: 1.6,
                        pointRadius: 2,
                        tension: .35,
                        fill: false,
                        yAxisID: 'incomeAxis'
                    }
                ]
            };

            const options = {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 760,
                    easing: 'easeOutQuart'
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        labels: {
                            color: chartTextColor(),
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            font: { size: 10, weight: '700' }
                        }
                    },
                    tooltip: {
                        backgroundColor: chartTooltipPalette().background,
                        titleColor: chartTooltipPalette().title,
                        bodyColor: chartTooltipPalette().body,
                        borderColor: chartTooltipPalette().border,
                        borderWidth: 1,
                        padding: 11,
                        callbacks: {
                            label(context) {
                                return context.dataset.label === 'Income'
                                    ? ` Income: ${formatCurrency(context.raw)}`
                                    : ` ${context.dataset.label}: ${numberFormatter.format(context.raw)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: chartGridColor(), drawBorder: false },
                        ticks: { color: chartTextColor(), font: { size: 9, weight: '700' } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: chartGridColor(), drawBorder: false },
                        ticks: { color: chartTextColor(), precision: 0, font: { size: 9, weight: '700' } }
                    },
                    incomeAxis: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { display: false },
                        ticks: {
                            color: '#67e8f9',
                            font: { size: 8.5, weight: '700' },
                            callback: value => `Rs.${numberFormatter.format(value)}`
                        }
                    }
                }
            };

            if (trendChart) {
                trendChart.data = chartData;
                trendChart.options = options;
                trendChart.update();
            } else {
                trendChart = new Chart(context, { type: 'line', data: chartData, options });
            }
        }

        function renderStatusChart(distribution) {
            if (typeof Chart === 'undefined') {
                return;
            }

            const values = [
                Number(distribution?.waiting || 0),
                Number(distribution?.vitals_pending || 0),
                Number(distribution?.called || 0),
                Number(distribution?.completed || 0),
                Number(distribution?.no_show || 0)
            ];

            const data = {
                labels: ['Waiting', 'Vitals Pending', 'Called', 'Completed', 'No Show'],
                datasets: [{
                    data: values,
                    backgroundColor: ['#f59e0b', '#ec4899', '#22d3ee', '#22c55e', '#ef4444'],
                    borderColor: chartTooltipPalette().doughnutBorder,
                    borderWidth: 4,
                    hoverOffset: 9
                }]
            };

            const options = {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '67%',
                animation: { duration: 780, easing: 'easeOutQuart' },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: chartTextColor(),
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14,
                            boxWidth: 8,
                            font: { size: 9, weight: '700' }
                        }
                    },
                    tooltip: {
                        backgroundColor: chartTooltipPalette().background,
                        titleColor: chartTooltipPalette().title,
                        bodyColor: chartTooltipPalette().body,
                        borderColor: chartTooltipPalette().border,
                        borderWidth: 1,
                        padding: 10
                    }
                }
            };

            if (statusChart) {
                statusChart.data = data;
                statusChart.options = options;
                statusChart.update();
            } else {
                statusChart = new Chart(document.getElementById('statusChart'), {
                    type: 'doughnut',
                    data,
                    options
                });
            }
        }

        function filterDepartmentOptions() {
            const branchId = Number(branchSelect.value || 0);
            let selectedVisible = departmentSelect.value === '0';

            Array.from(departmentSelect.options).forEach(option => {
                if (option.value === '0') return;
                const optionBranch = Number(option.dataset.branch || 0);
                const visible = branchId === 0 || optionBranch === 0 || optionBranch === branchId;
                option.hidden = !visible;
                option.disabled = !visible;
                if (visible && option.value === departmentSelect.value) selectedVisible = true;
            });

            if (!selectedVisible) {
                departmentSelect.value = '0';
            }
        }

        function filterDoctorOptions() {
            const branchId = Number(branchSelect.value || 0);
            const departmentId = Number(departmentSelect.value || 0);
            let selectedVisible = doctorSelect.value === '0';

            Array.from(doctorSelect.options).forEach(option => {
                if (option.value === '0') return;
                const optionBranch = Number(option.dataset.branch || 0);
                const optionDepartment = Number(option.dataset.department || 0);
                const branchMatches = branchId === 0 || optionBranch === 0 || optionBranch === branchId;
                const departmentMatches = departmentId === 0 || optionDepartment === departmentId;
                const visible = branchMatches && departmentMatches;
                option.hidden = !visible;
                option.disabled = !visible;
                if (visible && option.value === doctorSelect.value) selectedVisible = true;
            });

            if (!selectedVisible) {
                doctorSelect.value = '0';
            }
        }

        function currentQueryString() {
            const params = new URLSearchParams({
                date: dateInput.value || DEFAULT_DATE,
                branch_id: branchSelect.value || '0',
                department_id: departmentSelect.value || '0',
                doctor_id: doctorSelect.value || '0',
                visit_type: visitTypeSelect.value || ''
            });

            return params.toString();
        }

        async function loadDashboard(options = {}) {
            const silent = Boolean(options.silent);

            if (currentRequestController) {
                currentRequestController.abort();
            }

            currentRequestController = new AbortController();
            setRefreshing(true);

            try {
                const response = await fetch(`${API_URL}?${currentQueryString()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store',
                    signal: currentRequestController.signal
                });

                const payload = await response.json().catch(() => null);

                if (!response.ok || !payload || payload.success !== true) {
                    throw new Error(payload?.message || `Dashboard request failed (${response.status})`);
                }

                renderStats(payload.stats || {});
                renderQueue(payload.queue || []);
                renderPayments(payload.payments || []);
                renderDoctorPerformance(payload.doctor_performance || []);
                renderTrendChart(payload.trend || {});
                renderStatusChart(payload.status_distribution || {});

                const generatedAt = payload.generated_at
                    ? new Date(String(payload.generated_at).replace(' ', 'T'))
                    : new Date();

                lastUpdated.textContent = generatedAt.toLocaleTimeString('en-PK', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });

                if (!silent) {
                    refreshStatus.innerHTML = '<i class="bi bi-check-circle"></i> Dashboard updated';
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }

                console.error('OPD Dashboard:', error);
                refreshStatus.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Live data unavailable';
                showToast('Dashboard data error', error.message || 'Unable to load OPD dashboard data.');
            } finally {
                setRefreshing(false);
            }
        }

        function startAutoRefresh() {
            window.clearInterval(autoRefreshTimer);
            autoRefreshTimer = window.setInterval(() => {
                if (document.visibilityState === 'visible') {
                    loadDashboard({ silent: true });
                }
            }, AUTO_REFRESH_MS);
        }

        function updateClock() {
            const now = new Date();
            document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-PK', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });

            document.getElementById('liveDate').textContent = now.toLocaleDateString('en-PK', {
                weekday: 'long',
                day: '2-digit',
                month: 'long',
                year: 'numeric'
            });
        }

        function bindCardTilt() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            if (!window.matchMedia('(pointer: fine)').matches) return;

            document.querySelectorAll('.opd-stat-card').forEach(card => {
                card.addEventListener('mousemove', event => {
                    const rect = card.getBoundingClientRect();
                    const x = (event.clientX - rect.left) / rect.width;
                    const y = (event.clientY - rect.top) / rect.height;
                    const rotateY = (x - .5) * 3.2;
                    const rotateX = (.5 - y) * 3.2;
                    card.style.transform = `perspective(900px) translateY(-4px) rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
                });

                card.addEventListener('mouseleave', () => {
                    card.style.transform = '';
                });
            });
        }

        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                applyTheme(currentTheme() === 'light' ? 'dark' : 'light');
            });
        }

        const themeAttributeObserver = new MutationObserver(() => {
            const sharedTheme = document.documentElement.getAttribute('data-pos-theme');

            if ((sharedTheme === 'light' || sharedTheme === 'dark') && sharedTheme !== currentTheme()) {
                applyTheme(sharedTheme);
            }
        });

        themeAttributeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-pos-theme']
        });

        filtersForm.addEventListener('submit', event => {
            event.preventDefault();
            loadDashboard();
            startAutoRefresh();
        });

        resetButton.addEventListener('click', () => {
            dateInput.value = DEFAULT_DATE;
            branchSelect.value = String(DEFAULT_BRANCH);
            departmentSelect.value = '0';
            doctorSelect.value = '0';
            visitTypeSelect.value = '';
            filterDepartmentOptions();
            filterDoctorOptions();
            loadDashboard();
            startAutoRefresh();
        });

        branchSelect.addEventListener('change', () => {
            filterDepartmentOptions();
            filterDoctorOptions();
        });

        departmentSelect.addEventListener('change', filterDoctorOptions);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                loadDashboard({ silent: true });
            }
        });

        applyTheme(currentTheme(), false);
        filterDepartmentOptions();
        filterDoctorOptions();
        updateClock();
        window.setInterval(updateClock, 1000);
        bindCardTilt();
        loadDashboard();
        startAutoRefresh();
    })();
    </script>
</body>
</html>
