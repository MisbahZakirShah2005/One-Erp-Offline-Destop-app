<?php
/*
|--------------------------------------------------------------------------
| report.php
| State Agency / Real Estate - Reports & Analytics
|--------------------------------------------------------------------------
| Path:
| C:\xampp\htdocs\pos\public\app\views\report.php
|
| Reports included:
| - Agent-wise leads
| - Agent-wise converted deals
| - Source-wise leads
| - Monthly deals
| - Monthly commission
| - Property status report
| - Lead conversion rate
| - Pending follow-ups
| - Upcoming site visits
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
$role_id   = (int)($_SESSION['role_id'] ?? 0);

if ($client_id <= 0) {
    die("Invalid client session");
}

/* ===================== HELPERS ===================== */
function e($value): string {
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

function scalarValue(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();
    return $value ?? 0;
}

function fetchAllRows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function moneyFmt($amount): string {
    return 'PKR ' . number_format((float)$amount, 0);
}

function moneyShort($amount): string {
    $amount = (float)$amount;
    if ($amount >= 10000000) return 'PKR ' . rtrim(rtrim(number_format($amount / 10000000, 2), '0'), '.') . ' Cr';
    if ($amount >= 100000) return 'PKR ' . rtrim(rtrim(number_format($amount / 100000, 2), '0'), '.') . ' Lac';
    if ($amount >= 1000) return 'PKR ' . rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') . 'K';
    return 'PKR ' . number_format($amount, 0);
}

function dateLabel($date): string {
    if (!$date) return '-';
    return date('d M, Y', strtotime($date));
}

function defaultDateFrom(): string {
    return date('Y-m-01');
}

function defaultDateTo(): string {
    return date('Y-m-t');
}

/* ===================== REQUIRED CHECKS ===================== */
if (!tableExists($conn, 're_leads')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>re_leads table missing</h3>
            <p>Pehle Real Estate leads table create karo.</p>
        </div>
    ");
}

/* ===================== FILTERS ===================== */
$dateFrom = trim($_GET['date_from'] ?? defaultDateFrom());
$dateTo   = trim($_GET['date_to'] ?? defaultDateTo());
$agentId  = (int)($_GET['agent_id'] ?? 0);
$projectId = (int)($_GET['project_id'] ?? 0);
$sourceFilter = trim($_GET['source'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

if ($dateFrom === '') $dateFrom = defaultDateFrom();
if ($dateTo === '') $dateTo = defaultDateTo();

$fromDateTime = $dateFrom . ' 00:00:00';
$toDateTime   = $dateTo . ' 23:59:59';

$leadAgentCol = columnExists($conn, 're_leads', 'assigned_agent_id') ? 'assigned_agent_id' : (columnExists($conn, 're_leads', 'agent_id') ? 'agent_id' : null);
$leadDateCol = columnExists($conn, 're_leads', 'created_at') ? 'created_at' : 'id';
$dealDateCol = tableExists($conn, 're_deals') && columnExists($conn, 're_deals', 'deal_date') ? 'deal_date' : 'created_at';

/* Agent role rule: if agent logged in, show own data */
$isAgentUser = ($role_id === 2);
if ($isAgentUser) {
    $agentId = $user_id;
}

/* ===================== OPTIONS ===================== */
$agents = [];
if (tableExists($conn, 'users')) {
    if (tableExists($conn, 'roles')) {
        $agents = fetchAllRows($conn, "
            SELECT u.id, u.name, u.phone, u.email, COALESCE(r.name,'') AS role_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.client_id = ?
              AND u.status = 'active'
              AND (
                    u.role_id = 2
                    OR LOWER(COALESCE(r.name,'')) IN ('agent','staff','employee','sales agent','real estate agent','property agent')
              )
            ORDER BY u.name ASC
        ", "i", [$client_id]);
    } else {
        $agents = fetchAllRows($conn, "
            SELECT id, name, phone, email, '' AS role_name
            FROM users
            WHERE client_id = ? AND status = 'active' AND role_id = 2
            ORDER BY name ASC
        ", "i", [$client_id]);
    }
}

$projects = [];
if (tableExists($conn, 're_projects')) {
    $projectNameExpr = columnExists($conn, 're_projects', 'project_name') ? "COALESCE(project_name, name)" : "name";
    $projects = fetchAllRows($conn, "
        SELECT id, $projectNameExpr AS project_name
        FROM re_projects
        WHERE client_id = ?
        ORDER BY $projectNameExpr ASC
    ", "i", [$client_id]);
}

$sources = fetchAllRows($conn, "
    SELECT DISTINCT COALESCE(NULLIF(source,''),'Unknown') AS source
    FROM re_leads
    WHERE client_id = ?
    ORDER BY source ASC
", "i", [$client_id]);

$statuses = fetchAllRows($conn, "
    SELECT DISTINCT COALESCE(NULLIF(status,''),'New') AS status
    FROM re_leads
    WHERE client_id = ?
    ORDER BY status ASC
", "i", [$client_id]);

/* ===================== COMMON WHERE BUILDERS ===================== */
$leadWhere = " WHERE l.client_id = ? ";
$leadTypes = "i";
$leadParams = [$client_id];

if ($dateFrom !== '') {
    $leadWhere .= " AND l.created_at >= ? ";
    $leadTypes .= "s";
    $leadParams[] = $fromDateTime;
}
if ($dateTo !== '') {
    $leadWhere .= " AND l.created_at <= ? ";
    $leadTypes .= "s";
    $leadParams[] = $toDateTime;
}
if ($agentId > 0 && $leadAgentCol) {
    $leadWhere .= " AND l.`$leadAgentCol` = ? ";
    $leadTypes .= "i";
    $leadParams[] = $agentId;
}
if ($sourceFilter !== '') {
    $leadWhere .= " AND COALESCE(NULLIF(l.source,''),'Unknown') = ? ";
    $leadTypes .= "s";
    $leadParams[] = $sourceFilter;
}
if ($statusFilter !== '') {
    $leadWhere .= " AND COALESCE(NULLIF(l.status,''),'New') = ? ";
    $leadTypes .= "s";
    $leadParams[] = $statusFilter;
}
if ($projectId > 0 && columnExists($conn, 're_leads', 'project_id')) {
    $leadWhere .= " AND l.project_id = ? ";
    $leadTypes .= "i";
    $leadParams[] = $projectId;
}

/* ===================== STATS ===================== */
$totalLeads = (int)scalarValue($conn, "SELECT COUNT(*) FROM re_leads l $leadWhere", $leadTypes, $leadParams);

$convertedLeads = (int)scalarValue($conn, "
    SELECT COUNT(*)
    FROM re_leads l
    $leadWhere
      AND LOWER(COALESCE(l.status,'')) IN ('converted','deal','closed','won')
", $leadTypes, $leadParams);

$lostLeads = (int)scalarValue($conn, "
    SELECT COUNT(*)
    FROM re_leads l
    $leadWhere
      AND LOWER(COALESCE(l.status,'')) IN ('lost','cancelled','canceled')
", $leadTypes, $leadParams);

$conversionRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 1) : 0;

$totalDealValue = 0;
$totalDeals = 0;
$totalCommission = 0;
$pendingFollowups = 0;
$upcomingSiteVisits = 0;

if (tableExists($conn, 're_deals')) {
    $dealWhere = " WHERE d.client_id = ? ";
    $dealTypes = "i";
    $dealParams = [$client_id];

    if ($dateFrom !== '') {
        $dealWhere .= " AND DATE(d.`$dealDateCol`) >= ? ";
        $dealTypes .= "s";
        $dealParams[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $dealWhere .= " AND DATE(d.`$dealDateCol`) <= ? ";
        $dealTypes .= "s";
        $dealParams[] = $dateTo;
    }
    if ($agentId > 0 && columnExists($conn, 're_deals', 'agent_id')) {
        $dealWhere .= " AND d.agent_id = ? ";
        $dealTypes .= "i";
        $dealParams[] = $agentId;
    }
    if ($projectId > 0 && tableExists($conn, 're_properties')) {
        $dealWhere .= " AND p.project_id = ? ";
        $dealTypes .= "i";
        $dealParams[] = $projectId;
    }

    $dealJoin = tableExists($conn, 're_properties') ? " LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id " : "";

    $totalDeals = (int)scalarValue($conn, "
        SELECT COUNT(*)
        FROM re_deals d
        $dealJoin
        $dealWhere
    ", $dealTypes, $dealParams);

    $totalDealValue = (float)scalarValue($conn, "
        SELECT COALESCE(SUM(d.deal_amount),0)
        FROM re_deals d
        $dealJoin
        $dealWhere
    ", $dealTypes, $dealParams);

    $totalCommission = (float)scalarValue($conn, "
        SELECT COALESCE(SUM(d.commission_amount),0)
        FROM re_deals d
        $dealJoin
        $dealWhere
    ", $dealTypes, $dealParams);
}

if (tableExists($conn, 're_agent_commissions')) {
    $commissionWhere = " WHERE ac.client_id = ? ";
    $commissionTypes = "i";
    $commissionParams = [$client_id];

    if ($agentId > 0) {
        $commissionWhere .= " AND ac.agent_id = ? ";
        $commissionTypes .= "i";
        $commissionParams[] = $agentId;
    }
    if ($dateFrom !== '') {
        $commissionWhere .= " AND DATE(ac.created_at) >= ? ";
        $commissionTypes .= "s";
        $commissionParams[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $commissionWhere .= " AND DATE(ac.created_at) <= ? ";
        $commissionTypes .= "s";
        $commissionParams[] = $dateTo;
    }

    $totalCommission = (float)scalarValue($conn, "
        SELECT COALESCE(SUM(ac.commission_amount),0)
        FROM re_agent_commissions ac
        $commissionWhere
    ", $commissionTypes, $commissionParams);
}

if (tableExists($conn, 're_followups')) {
    $followAgentCol = columnExists($conn, 're_followups', 'agent_id') ? 'agent_id' : null;
    $followDateExpr = columnExists($conn, 're_followups', 'followup_at')
        ? "DATE(f.followup_at)"
        : (columnExists($conn, 're_followups', 'followup_date') ? "f.followup_date" : "DATE(f.created_at)");

    $fw = " WHERE f.client_id = ? ";
    $ft = "i";
    $fp = [$client_id];

    if ($agentId > 0 && $followAgentCol) {
        $fw .= " AND f.agent_id = ? ";
        $ft .= "i";
        $fp[] = $agentId;
    }

    $pendingFollowups = (int)scalarValue($conn, "
        SELECT COUNT(*)
        FROM re_followups f
        $fw
          AND LOWER(COALESCE(f.status,'')) IN ('pending','missed')
          AND $followDateExpr <= CURDATE()
    ", $ft, $fp);
}

if (tableExists($conn, 're_site_visits')) {
    $visitAgentCol = columnExists($conn, 're_site_visits', 'agent_id') ? 'agent_id' : null;
    $visitDateCol = columnExists($conn, 're_site_visits', 'visit_date') ? 'visit_date' : 'created_at';
    $visitStatusCol = columnExists($conn, 're_site_visits', 'visit_status') ? 'visit_status' : (columnExists($conn, 're_site_visits', 'status') ? 'status' : null);

    $vw = " WHERE v.client_id = ? ";
    $vt = "i";
    $vp = [$client_id];

    if ($agentId > 0 && $visitAgentCol) {
        $vw .= " AND v.agent_id = ? ";
        $vt .= "i";
        $vp[] = $agentId;
    }
    if ($visitStatusCol) {
        $vw .= " AND LOWER(COALESCE(v.`$visitStatusCol`,'')) IN ('scheduled','pending','confirmed') ";
    }

    $upcomingSiteVisits = (int)scalarValue($conn, "
        SELECT COUNT(*)
        FROM re_site_visits v
        $vw
          AND DATE(v.`$visitDateCol`) >= CURDATE()
    ", $vt, $vp);
}

/* ===================== REPORT DATA ===================== */
/* Agent-wise leads + converted + deals + commission */
$agentNameExpr = "COALESCE(u.name, 'Unassigned')";
$agentJoinCondition = $leadAgentCol ? "u.id = l.`$leadAgentCol`" : "1=0";

$agentWiseLeads = fetchAllRows($conn, "
    SELECT
        COALESCE(u.id,0) AS agent_id,
        $agentNameExpr AS agent_name,
        COUNT(l.id) AS total_leads,
        SUM(CASE WHEN LOWER(COALESCE(l.status,'')) IN ('converted','deal','closed','won') THEN 1 ELSE 0 END) AS converted_leads,
        SUM(CASE WHEN LOWER(COALESCE(l.status,'')) IN ('lost','cancelled','canceled') THEN 1 ELSE 0 END) AS lost_leads
    FROM re_leads l
    LEFT JOIN users u ON $agentJoinCondition
    $leadWhere
    GROUP BY COALESCE(u.id,0), $agentNameExpr
    ORDER BY total_leads DESC
    LIMIT 10
", $leadTypes, $leadParams);

$agentDealMap = [];
if (tableExists($conn, 're_deals')) {
    $rows = fetchAllRows($conn, "
        SELECT
            COALESCE(u.id,0) AS agent_id,
            COALESCE(u.name,'Unassigned') AS agent_name,
            COUNT(d.id) AS total_deals,
            COALESCE(SUM(d.deal_amount),0) AS deal_value,
            COALESCE(SUM(d.commission_amount),0) AS commission_amount
        FROM re_deals d
        LEFT JOIN users u ON u.id = d.agent_id
        " . (tableExists($conn, 're_properties') ? "LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id" : "") . "
        WHERE d.client_id = ?
          AND DATE(d.`$dealDateCol`) BETWEEN ? AND ?
          " . ($agentId > 0 ? "AND d.agent_id = ?" : "") . "
          " . ($projectId > 0 && tableExists($conn, 're_properties') ? "AND p.project_id = ?" : "") . "
        GROUP BY COALESCE(u.id,0), COALESCE(u.name,'Unassigned')
        ORDER BY total_deals DESC
    ", 
    $agentId > 0 && $projectId > 0 && tableExists($conn, 're_properties') ? "issii" :
        ($agentId > 0 ? "issi" : ($projectId > 0 && tableExists($conn, 're_properties') ? "issi" : "iss")),
    $agentId > 0 && $projectId > 0 && tableExists($conn, 're_properties') ? [$client_id, $dateFrom, $dateTo, $agentId, $projectId] :
        ($agentId > 0 ? [$client_id, $dateFrom, $dateTo, $agentId] : ($projectId > 0 && tableExists($conn, 're_properties') ? [$client_id, $dateFrom, $dateTo, $projectId] : [$client_id, $dateFrom, $dateTo]))
    );

    foreach ($rows as $r) {
        $agentDealMap[(int)$r['agent_id']] = $r;
    }
}

foreach ($agentWiseLeads as &$r) {
    $aid = (int)$r['agent_id'];
    $r['total_deals'] = (int)($agentDealMap[$aid]['total_deals'] ?? 0);
    $r['deal_value'] = (float)($agentDealMap[$aid]['deal_value'] ?? 0);
    $r['commission_amount'] = (float)($agentDealMap[$aid]['commission_amount'] ?? 0);
}
unset($r);

/* Source wise leads */
$sourceWise = fetchAllRows($conn, "
    SELECT
        COALESCE(NULLIF(l.source,''),'Unknown') AS label,
        COUNT(*) AS value
    FROM re_leads l
    $leadWhere
    GROUP BY COALESCE(NULLIF(l.source,''),'Unknown')
    ORDER BY value DESC
", $leadTypes, $leadParams);

/* Status wise leads */
$statusWise = fetchAllRows($conn, "
    SELECT
        COALESCE(NULLIF(l.status,''),'New') AS label,
        COUNT(*) AS value
    FROM re_leads l
    $leadWhere
    GROUP BY COALESCE(NULLIF(l.status,''),'New')
    ORDER BY value DESC
", $leadTypes, $leadParams);

/* Monthly deals + monthly commission */
$monthlyDeals = [];
$monthlyCommission = [];
if (tableExists($conn, 're_deals')) {
    $monthlyDeals = fetchAllRows($conn, "
        SELECT
            DATE_FORMAT(d.`$dealDateCol`, '%b %Y') AS month_label,
            DATE_FORMAT(d.`$dealDateCol`, '%Y-%m') AS month_key,
            COUNT(*) AS total_deals,
            COALESCE(SUM(d.deal_amount),0) AS deal_value,
            COALESCE(SUM(d.commission_amount),0) AS commission_amount
        FROM re_deals d
        " . (tableExists($conn, 're_properties') ? "LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id" : "") . "
        WHERE d.client_id = ?
          AND DATE(d.`$dealDateCol`) BETWEEN ? AND ?
          " . ($agentId > 0 ? "AND d.agent_id = ?" : "") . "
          " . ($projectId > 0 && tableExists($conn, 're_properties') ? "AND p.project_id = ?" : "") . "
        GROUP BY month_key, month_label
        ORDER BY month_key ASC
    ",
    $agentId > 0 && $projectId > 0 && tableExists($conn, 're_properties') ? "issii" :
        ($agentId > 0 ? "issi" : ($projectId > 0 && tableExists($conn, 're_properties') ? "issi" : "iss")),
    $agentId > 0 && $projectId > 0 && tableExists($conn, 're_properties') ? [$client_id, $dateFrom, $dateTo, $agentId, $projectId] :
        ($agentId > 0 ? [$client_id, $dateFrom, $dateTo, $agentId] : ($projectId > 0 && tableExists($conn, 're_properties') ? [$client_id, $dateFrom, $dateTo, $projectId] : [$client_id, $dateFrom, $dateTo]))
    );
}

/* Property status report */
$propertyStatus = [];
if (tableExists($conn, 're_properties')) {
    $pw = " WHERE p.client_id = ? ";
    $pt = "i";
    $pp = [$client_id];

    if ($agentId > 0 && columnExists($conn, 're_properties', 'assigned_agent_id')) {
        $pw .= " AND p.assigned_agent_id = ? ";
        $pt .= "i";
        $pp[] = $agentId;
    }
    if ($projectId > 0 && columnExists($conn, 're_properties', 'project_id')) {
        $pw .= " AND p.project_id = ? ";
        $pt .= "i";
        $pp[] = $projectId;
    }

    $propertyStatus = fetchAllRows($conn, "
        SELECT COALESCE(NULLIF(p.status,''),'Available') AS label, COUNT(*) AS value
        FROM re_properties p
        $pw
        GROUP BY COALESCE(NULLIF(p.status,''),'Available')
        ORDER BY value DESC
    ", $pt, $pp);
}

/* Pending followups list */
$pendingFollowupRows = [];
if (tableExists($conn, 're_followups')) {
    $followDateSelect = columnExists($conn, 're_followups', 'followup_at') ? "f.followup_at" : (columnExists($conn, 're_followups', 'followup_date') ? "f.followup_date" : "f.created_at");
    $fw = " WHERE f.client_id = ? ";
    $ft = "i";
    $fp = [$client_id];

    if ($agentId > 0 && columnExists($conn, 're_followups', 'agent_id')) {
        $fw .= " AND f.agent_id = ? ";
        $ft .= "i";
        $fp[] = $agentId;
    }

    $pendingFollowupRows = fetchAllRows($conn, "
        SELECT
            f.id,
            l.lead_name,
            l.phone,
            COALESCE(u.name,'Unassigned') AS agent_name,
            f.followup_type,
            $followDateSelect AS followup_date,
            f.status,
            COALESCE(f.remarks, f.response, '') AS notes
        FROM re_followups f
        LEFT JOIN re_leads l ON l.id = f.lead_id AND l.client_id = f.client_id
        LEFT JOIN users u ON u.id = f.agent_id
        $fw
          AND LOWER(COALESCE(f.status,'')) IN ('pending','missed')
        ORDER BY $followDateSelect ASC
        LIMIT 8
    ", $ft, $fp);
}

/* Upcoming site visits list */
$upcomingVisitRows = [];
if (tableExists($conn, 're_site_visits')) {
    $visitDateCol = columnExists($conn, 're_site_visits', 'visit_date') ? 'visit_date' : 'created_at';
    $visitTimeCol = columnExists($conn, 're_site_visits', 'visit_time') ? 'visit_time' : null;
    $visitStatusCol = columnExists($conn, 're_site_visits', 'visit_status') ? 'visit_status' : (columnExists($conn, 're_site_visits', 'status') ? 'status' : null);

    $vw = " WHERE v.client_id = ? ";
    $vt = "i";
    $vp = [$client_id];

    if ($agentId > 0 && columnExists($conn, 're_site_visits', 'agent_id')) {
        $vw .= " AND v.agent_id = ? ";
        $vt .= "i";
        $vp[] = $agentId;
    }

    $upcomingVisitRows = fetchAllRows($conn, "
        SELECT
            v.id,
            l.lead_name,
            l.phone,
            COALESCE(p.title, 'No Property') AS property_title,
            COALESCE(u.name,'Unassigned') AS agent_name,
            v.`$visitDateCol` AS visit_date,
            " . ($visitTimeCol ? "v.`$visitTimeCol`" : "NULL") . " AS visit_time,
            " . ($visitStatusCol ? "v.`$visitStatusCol`" : "'Scheduled'") . " AS visit_status,
            COALESCE(v.remarks,'') AS remarks
        FROM re_site_visits v
        LEFT JOIN re_leads l ON l.id = v.lead_id AND l.client_id = v.client_id
        LEFT JOIN re_properties p ON p.id = v.property_id AND p.client_id = v.client_id
        LEFT JOIN users u ON u.id = v.agent_id
        $vw
          AND DATE(v.`$visitDateCol`) >= CURDATE()
        ORDER BY v.`$visitDateCol` ASC " . ($visitTimeCol ? ", v.`$visitTimeCol` ASC" : "") . "
        LIMIT 8
    ", $vt, $vp);
}

/* Top properties by closed deals */
$topProperties = [];
if (tableExists($conn, 're_deals') && tableExists($conn, 're_properties')) {
    $topProperties = fetchAllRows($conn, "
        SELECT
            COALESCE(p.title,'Unknown Property') AS label,
            COUNT(d.id) AS value,
            COALESCE(SUM(d.deal_amount),0) AS deal_value
        FROM re_deals d
        LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id
        WHERE d.client_id = ?
          AND DATE(d.`$dealDateCol`) BETWEEN ? AND ?
          " . ($agentId > 0 ? "AND d.agent_id = ?" : "") . "
          " . ($projectId > 0 ? "AND p.project_id = ?" : "") . "
        GROUP BY p.id, p.title
        ORDER BY value DESC, deal_value DESC
        LIMIT 6
    ",
    $agentId > 0 && $projectId > 0 ? "issii" : ($agentId > 0 ? "issi" : ($projectId > 0 ? "issi" : "iss")),
    $agentId > 0 && $projectId > 0 ? [$client_id, $dateFrom, $dateTo, $agentId, $projectId] :
        ($agentId > 0 ? [$client_id, $dateFrom, $dateTo, $agentId] : ($projectId > 0 ? [$client_id, $dateFrom, $dateTo, $projectId] : [$client_id, $dateFrom, $dateTo]))
    );
}

/* Daily trend for line chart */
$dailyTrend = fetchAllRows($conn, "
    SELECT
        DATE(l.created_at) AS dt,
        COUNT(l.id) AS leads,
        SUM(CASE WHEN LOWER(COALESCE(l.status,'')) IN ('converted','deal','closed','won') THEN 1 ELSE 0 END) AS converted,
        SUM(CASE WHEN LOWER(COALESCE(l.status,'')) IN ('lost','cancelled','canceled') THEN 1 ELSE 0 END) AS lost
    FROM re_leads l
    $leadWhere
    GROUP BY DATE(l.created_at)
    ORDER BY dt ASC
", $leadTypes, $leadParams);

if (count($dailyTrend) > 8) {
    $step = max(1, floor(count($dailyTrend) / 8));
    $dailyTrend = array_values(array_filter($dailyTrend, fn($row, $idx) => $idx % $step === 0 || $idx === count($dailyTrend)-1, ARRAY_FILTER_USE_BOTH));
}

/* CSV EXPORT */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=real_estate_reports_' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['Real Estate Reports']);
    fputcsv($out, ['Date From', $dateFrom, 'Date To', $dateTo]);
    fputcsv($out, []);
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Total Leads', $totalLeads]);
    fputcsv($out, ['Converted Leads', $convertedLeads]);
    fputcsv($out, ['Lost Leads', $lostLeads]);
    fputcsv($out, ['Conversion Rate', $conversionRate . '%']);
    fputcsv($out, ['Total Deals', $totalDeals]);
    fputcsv($out, ['Total Deal Value', $totalDealValue]);
    fputcsv($out, ['Total Commission', $totalCommission]);
    fputcsv($out, ['Pending Followups', $pendingFollowups]);
    fputcsv($out, ['Upcoming Site Visits', $upcomingSiteVisits]);
    fputcsv($out, []);

    fputcsv($out, ['Agent-wise Performance']);
    fputcsv($out, ['Agent', 'Total Leads', 'Converted', 'Lost', 'Deals', 'Deal Value', 'Commission']);
    foreach ($agentWiseLeads as $r) {
        fputcsv($out, [$r['agent_name'], $r['total_leads'], $r['converted_leads'], $r['lost_leads'], $r['total_deals'], $r['deal_value'], $r['commission_amount']]);
    }

    fputcsv($out, []);
    fputcsv($out, ['Source-wise Leads']);
    fputcsv($out, ['Source', 'Leads']);
    foreach ($sourceWise as $r) fputcsv($out, [$r['label'], $r['value']]);

    fclose($out);
    exit;
}

/* ===================== JSON FOR CHARTS ===================== */
$colors = ['#c94b57','#3e7a3a','#d8a500','#7a4cb0','#20346d','#dc9b2d','#4c9075','#9f2430','#8a6a20'];

function chartItems(array $rows, array $colors): array {
    $total = array_sum(array_map(fn($r) => (int)$r['value'], $rows));
    $items = [];
    foreach ($rows as $i => $r) {
        $value = (int)$r['value'];
        $items[] = [
            'label' => (string)$r['label'],
            'value' => $value,
            'pct' => $total > 0 ? round(($value / $total) * 100) : 0,
            'color' => $colors[$i % count($colors)],
        ];
    }
    return ['total' => $total, 'items' => $items];
}

$sourceChart = chartItems($sourceWise, $colors);
$statusChart = chartItems($statusWise, $colors);
$propertyStatusChart = chartItems($propertyStatus, $colors);

$lineChart = [
    'labels' => array_map(fn($r) => date('d M', strtotime($r['dt'])), $dailyTrend),
    'datasets' => [
        ['label' => 'Leads', 'color' => '#c9a84c', 'data' => array_map(fn($r) => (int)$r['leads'], $dailyTrend)],
        ['label' => 'Converted', 'color' => '#3e7a3a', 'data' => array_map(fn($r) => (int)$r['converted'], $dailyTrend)],
        ['label' => 'Lost', 'color' => '#c94b57', 'data' => array_map(fn($r) => (int)$r['lost'], $dailyTrend)],
    ],
];

$agentBar = array_slice(array_map(function($r) use ($colors) {
    static $idx = 0;
    $color = $colors[$idx++ % count($colors)];
    return [
        'label' => $r['agent_name'],
        'value' => (int)$r['total_deals'],
        'color' => $color
    ];
}, $agentWiseLeads), 0, 6);

if (empty($agentBar)) {
    $agentBar = [['label' => 'No Deals', 'value' => 0, 'color' => '#c9a84c']];
}

$propHBar = array_map(function($r) use ($colors) {
    static $idx = 0;
    return [
        'label' => $r['label'],
        'value' => (int)$r['value'],
        'color' => $colors[$idx++ % count($colors)]
    ];
}, $topProperties);

if (empty($propHBar)) {
    $propHBar = [['label' => 'No Property Deals', 'value' => 0, 'color' => '#c9a84c']];
}

$funnelRows = [
    ['label' => 'Leads', 'value' => $totalLeads, 'color' => '#c9a84c'],
    ['label' => 'Follow Ups', 'value' => max(0, $totalLeads - $convertedLeads - $lostLeads), 'color' => '#7a4cb0'],
    ['label' => 'Site Visits', 'value' => $upcomingSiteVisits, 'color' => '#20346d'],
    ['label' => 'Deals', 'value' => $totalDeals, 'color' => '#dc9b2d'],
    ['label' => 'Converted', 'value' => $convertedLeads, 'color' => '#3e7a3a'],
];
$funnelMax = max(1, $totalLeads);
$funnel = array_map(fn($r) => [
    'label' => $r['label'],
    'value' => (int)$r['value'],
    'pct' => round(((int)$r['value'] / $funnelMax) * 100),
    'color' => $r['color']
], $funnelRows);

$chartData = [
    'lineChart' => $lineChart,
    'sourceDonut' => $sourceChart,
    'statusDonut' => $statusChart,
    'propertyStatus' => $propertyStatusChart,
    'funnel' => $funnel,
    'agentBar' => $agentBar,
    'propHBar' => $propHBar,
];

$chartJson = json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$dateRangeLabel = date('d M, Y', strtotime($dateFrom)) . ' - ' . date('d M, Y', strtotime($dateTo));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reports & Analytics - Real Estate | ONE ERP</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<?php include('partials/head.php'); ?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">

<style>
:root{
  --bg:#eef2f7;
  --card:#fff;
  --border:#e0e5f2;
  --text:#1f2736;
  --muted:#64748b;
  --gold:#c9a84c;
  --gold2:#d8a500;
  --green:#3e7a3a;
  --red:#c94b57;
  --purple:#7a4cb0;
  --blue:#20346d;
  --orange:#dc9b2d;
  --shadow:0 4px 20px rgba(0,0,0,.05);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);font-size:13px;overflow-x:hidden}
a{text-decoration:none;color:inherit}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px;gap:16px;flex-wrap:wrap}
.page-title{font-size:24px;font-weight:900;color:#243046;letter-spacing:-.35px}
.breadcrumb-custom{display:flex;gap:6px;margin-top:5px;color:#9ca3af;font-size:12px}
.breadcrumb-custom .active{color:var(--gold);font-weight:800}
.page-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn{display:inline-flex;align-items:center;gap:6px;height:42px;padding:0 16px;border-radius:10px;border:none;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;transition:.18s;white-space:nowrap}
.btn .material-icons-round{font-size:17px}
.btn-primary{background:linear-gradient(180deg,#f5c742,#d8a500);color:#111;box-shadow:0 4px 12px rgba(216,165,0,.25)}
.btn-success{background:linear-gradient(180deg,#4f9e4a,#3e7a3a);color:#fff;box-shadow:0 4px 12px rgba(62,122,58,.22)}
.btn-outline{background:#fff;color:#2b3674;border:1px solid var(--border);box-shadow:0 4px 12px rgba(0,0,0,.04)}
.btn:hover{transform:translateY(-1px)}
.date-range-pill{height:42px;background:#fff;border:1px solid var(--border);border-radius:10px;display:inline-flex;align-items:center;gap:8px;padding:0 14px;color:#2b3674;font-weight:800;box-shadow:0 4px 12px rgba(0,0,0,.04)}

.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px}
.stat-card{border:none;border-radius:14px;min-height:112px;padding:18px 16px;color:#fff;position:relative;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12)}
.stat-card:after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12)}
.stat-card:nth-child(1){background:linear-gradient(135deg,#9f2430,#c94b57)}
.stat-card:nth-child(2){background:linear-gradient(135deg,#386c58,#4c9075)}
.stat-card:nth-child(3){background:linear-gradient(135deg,#9f2430,#c94b57)}
.stat-card:nth-child(4){background:linear-gradient(135deg,#4c2f75,#7a4cb0)}
.stat-card:nth-child(5){background:linear-gradient(135deg,#b27b16,#d8a33a)}
.stat-card:nth-child(6){background:linear-gradient(135deg,#111c44,#20346d)}
.stat-card-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;position:relative;z-index:1}
.stat-icon-box{width:38px;height:38px;border-radius:8px;background:rgba(255,255,255,.20);display:flex;align-items:center;justify-content:center}
.stat-icon-box .material-icons-round{font-size:20px;color:#fff}
.stat-value{font-size:22px;font-weight:900;color:#fff;letter-spacing:-.5px;position:relative;z-index:1}
.stat-label{font-size:12px;color:rgba(255,255,255,.88);font-weight:800;margin-top:5px;position:relative;z-index:1}
.stat-compare{font-size:11px;color:rgba(255,255,255,.82);font-weight:700;margin-top:5px;position:relative;z-index:1}

.filter-card{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow);padding:16px 20px;margin-bottom:20px}
.filter-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.filter-group{display:flex;flex-direction:column;gap:6px;min-width:140px;flex:1}
.filter-group label{font-size:11px;font-weight:900;text-transform:uppercase;color:#64748b;letter-spacing:.45px}
.filter-input,.filter-select{height:42px;border:1px solid var(--border);border-radius:10px;background:#f4f7fe;color:#2b3674;font-family:inherit;font-size:12.5px;font-weight:700;padding:0 11px;outline:none;width:100%}
.filter-input:focus,.filter-select:focus{border-color:var(--gold);background:#fff;box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.filter-actions{display:flex;gap:8px}

.charts-row{display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:20px}
.chart-card,.table-card,.kpi-strip{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow)}
.chart-card{padding:18px}
.chart-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:16px}
.chart-card-title{font-size:15px;font-weight:900;color:#1a202c}
.chart-card-subtitle{font-size:12px;color:#94a3b8;font-weight:700;margin-top:2px}
.chart-tag{background:#fff8e6;border:1px solid #f5d77c;color:#8a6a20;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:900}
.line-chart-container{height:180px;position:relative}
canvas{display:block}
.chart-legend{display:flex;gap:14px;flex-wrap:wrap;margin-top:12px}
.legend-item{display:flex;gap:5px;align-items:center;font-size:12px;color:#64748b;font-weight:700}
.legend-dot{width:10px;height:10px;border-radius:50%}

.donut-wrapper{display:flex;align-items:center;gap:14px}
.donut-svg-wrap{flex-shrink:0;position:relative}
.donut-center-label{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);text-align:center}
.donut-center-num{font-size:20px;font-weight:900;color:#1f2736;line-height:1}
.donut-center-text{font-size:10px;color:#94a3b8;font-weight:800;margin-top:2px}
.donut-list{display:flex;flex-direction:column;gap:6px;flex:1}
.donut-list-item{display:flex;gap:7px;align-items:center;font-size:12px}
.donut-list-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.donut-list-label{flex:1;color:#64748b;font-size:11.5px;font-weight:700}
.donut-list-val{color:#1f2736;font-weight:900}
.donut-list-pct{color:#94a3b8;font-size:11px;min-width:30px;text-align:right}

.kpi-strip{display:grid;grid-template-columns:repeat(6,1fr);overflow:hidden;margin-bottom:20px}
.kpi-item{padding:16px 18px;border-right:1px solid #edf2f7}
.kpi-item:last-child{border-right:none}
.kpi-label{font-size:11px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.kpi-value{font-size:17px;font-weight:900;color:#1f2736}
.kpi-item:nth-child(1) .kpi-value{color:#c94b57}
.kpi-item:nth-child(2) .kpi-value{color:#3e7a3a}
.kpi-item:nth-child(3) .kpi-value{color:#c94b57}
.kpi-item:nth-child(4) .kpi-value{color:#7a4cb0}
.kpi-item:nth-child(5) .kpi-value{color:#d8a500}
.kpi-item:nth-child(6) .kpi-value{color:#20346d}

.bottom-charts-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:20px}
.funnel-list,.hbar-list{display:flex;flex-direction:column;gap:10px}
.funnel-top,.hbar-top{display:flex;justify-content:space-between;align-items:center;font-size:12.5px;margin-bottom:4px}
.funnel-label,.hbar-label{color:#64748b;font-weight:700}
.funnel-val,.hbar-val{color:#1f2736;font-weight:900}
.funnel-track,.hbar-track{height:8px;background:#edf2f7;border-radius:999px;overflow:hidden}
.funnel-fill,.hbar-fill{height:100%;border-radius:999px}
.bar-chart-wrap{height:180px;display:flex;align-items:flex-end;gap:8px;border-bottom:1px solid #e0e5f2;padding:0 4px}
.bar-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;height:100%;justify-content:flex-end}
.bar-val{font-size:11px;font-weight:900;color:#64748b}
.bar-fill{width:100%;border-radius:4px 4px 0 0;min-height:4px}
.x-labels{display:flex;gap:8px;padding:0 4px}
.x-label{flex:1;text-align:center;font-size:10px;color:#94a3b8;font-weight:700;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.table-card{overflow:hidden;margin-bottom:20px}
.table-header{padding:16px 20px;border-bottom:1px solid #edf2f7;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.table-title{font-size:17px;font-weight:900;color:#1a202c}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:1050px}
thead th{background:#f8fafc;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.4px;padding:13px 12px;border-bottom:2px solid #edf2f7;text-align:left;white-space:nowrap}
tbody td{padding:13px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#334155;vertical-align:middle;white-space:nowrap}
tbody tr:hover td{background:#fbfcfe}
.name-strong{font-weight:900;color:#1e293b}
.muted{color:#94a3b8;font-size:11px;font-weight:700}
.money{font-weight:900;color:#8a6a20}
.green{color:#14532d;font-weight:900}
.red{color:#7f1d1d;font-weight:900}
.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:900}
.badge-green{background:#dcfce7;color:#14532d}.badge-red{background:#fee2e2;color:#7f1d1d}.badge-yellow{background:#fef9c3;color:#854d0e}.badge-blue{background:#dbeafe;color:#1e40af}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:999999;display:none;align-items:flex-start;justify-content:center;padding:30px 16px;overflow-y:auto}
.modal-overlay.open{display:flex!important}
.erp-modal{background:#fff;border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.25);width:100%;max-width:720px;overflow:hidden;margin:auto 0}
.modal-header{height:70px;padding:0 24px;background:linear-gradient(135deg,#111,#1a1a1a);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-size:16px;font-weight:900;color:#fff;display:flex;align-items:center;gap:8px}
.modal-close{width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:#aaa;display:flex;align-items:center;justify-content:center;cursor:pointer}
.modal-body{padding:22px 24px;display:grid;grid-template-columns:1fr 1fr;gap:14px}
.modal-footer{padding:15px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;background:#fafafa}
.form-group{display:flex;flex-direction:column;gap:7px}
.form-group label{font-size:11.5px;font-weight:900;color:#374151;text-transform:uppercase;letter-spacing:.35px}
.form-control{height:40px;border:1.5px solid #e5e7eb;border-radius:9px;background:#fafafa;color:#1f2736;font-weight:700;font-family:inherit;padding:0 12px;outline:none}
.form-control:focus{border-color:var(--gold);background:#fff;box-shadow:0 0 0 3px rgba(201,168,76,.12)}

@media(max-width:1350px){.stats-grid{grid-template-columns:repeat(3,1fr)}.charts-row{grid-template-columns:1fr}.bottom-charts-row{grid-template-columns:1fr}.kpi-strip{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){.stats-grid,.kpi-strip{grid-template-columns:1fr}.filter-group{min-width:100%}.filter-actions{width:100%}.filter-actions .btn{flex:1;justify-content:center}.modal-body{grid-template-columns:1fr}}
/* =====================================================
   REPORT.PHP REAL ESTATE REPORTS
   DASHBOARD DARK PURPLE / BLACK / GLASS THEME
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
.pos-layout{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    background:transparent !important;
}

.pos-sidebar{
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

.pos-sidebar > div{
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

.pos-main{
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
.pos-main > .topbar,
.pos-main > header,
.pos-main > nav{
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

.pos-content{
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

.pos-content::before{
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

.pos-content::after{
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

.pos-content > *{
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

.breadcrumb-custom,
.breadcrumb-custom a,
.breadcrumb-custom span,
.text-muted,
.muted{
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

.date-range-pill{
    height:46px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

/* ================= BUTTONS ================= */
.btn{
    min-height:46px !important;
    height:46px !important;
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

.btn-primary{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-success{
    background:linear-gradient(135deg,#047857,#16a34a) !important;
    color:#fff !important;
    box-shadow:0 14px 28px rgba(34,197,94,.18) !important;
}

.btn-outline{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
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
.stat-card:nth-child(4){background:linear-gradient(135deg,#7c3aed,#a855f7) !important;}
.stat-card:nth-child(5){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.stat-card:nth-child(6){background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}

.stat-icon-box{
    background:rgba(255,255,255,.16) !important;
    border:1px solid rgba(255,255,255,.12) !important;
}

.stat-value{
    font-size:28px !important;
    font-weight:900 !important;
    color:#fff !important;
}

.stat-label{
    font-size:14px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
}

.stat-compare{
    color:rgba(255,255,255,.82) !important;
    font-weight:700 !important;
}

/* ================= GLASS PANELS ================= */
.filter-card,
.chart-card,
.table-card,
.kpi-strip{
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
.chart-card:hover,
.table-card:hover,
.kpi-strip:hover{
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

.chart-card{
    padding:18px !important;
}

.table-card{
    overflow:hidden !important;
}

.chart-card-title,
.table-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.chart-card-subtitle{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.chart-tag{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(109,76,255,.24) !important;
}

/* ================= FILTER INPUTS ================= */
.filter-group label{
    color:#cbd5e1 !important;
    font-weight:900 !important;
}

.filter-input,
.filter-select,
.form-control{
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

.filter-input:focus,
.filter-select:focus,
.form-control:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-select option,
.form-control option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* ================= CHART CONTENT ================= */
.chart-legend,
.legend-item,
.donut-list-label,
.donut-list-pct,
.funnel-label,
.hbar-label,
.x-label,
.bar-val{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.donut-center-num,
.donut-list-val,
.funnel-val,
.hbar-val{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.donut-center-text{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.funnel-track,
.hbar-track{
    background:rgba(15,23,42,.72) !important;
}

.bar-chart-wrap{
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

/* ================= KPI STRIP ================= */
.kpi-strip{
    overflow:hidden !important;
    margin-bottom:20px !important;
}

.kpi-item{
    border-right:1px solid rgba(148,163,184,.14) !important;
    background:rgba(15,23,42,.18) !important;
}

.kpi-label{
    color:#94a3b8 !important;
    font-weight:900 !important;
}

.kpi-value{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* ================= TABLE HEADER ================= */
.table-header{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
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
tbody td[colspan]{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

.name-strong{
    color:#f8fafc !important;
}

.money{
    color:#fbbf24 !important;
    font-weight:900 !important;
}

.green{
    color:#4ade80 !important;
    font-weight:900 !important;
}

.red{
    color:#f87171 !important;
    font-weight:900 !important;
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

.badge-yellow{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

/* ================= SCROLLBAR ================= */
.table-wrap::-webkit-scrollbar,
.modal-body::-webkit-scrollbar,
.pos-content::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.table-wrap::-webkit-scrollbar-track,
.modal-body::-webkit-scrollbar-track,
.pos-content::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb,
.modal-body::-webkit-scrollbar-thumb,
.pos-content::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.table-wrap::-webkit-scrollbar-thumb:hover,
.modal-body::-webkit-scrollbar-thumb:hover,
.pos-content::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

/* =====================================================
   REPORT FILTER POPUP - DARK PURPLE GLASS COMPACT
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

.erp-modal{
    width:100% !important;
    max-width:720px !important;
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
@media(max-width:1350px){
    .stats-grid{
        grid-template-columns:repeat(3,1fr) !important;
    }

    .charts-row,
    .bottom-charts-row{
        grid-template-columns:1fr !important;
    }

    .kpi-strip{
        grid-template-columns:repeat(3,1fr) !important;
    }
}

@media(max-width:991px){
    .pos-layout{
        flex-direction:column !important;
    }

    .pos-sidebar{
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

    .pos-main{
        margin-left:0 !important;
    }

    .pos-main > .topbar,
    .pos-main > header,
    .pos-main > nav{
        position:relative !important;
    }

    .pos-content{
        padding:18px !important;
    }

    .modal-body{
        grid-template-columns:1fr !important;
    }
}

@media(max-width:768px){
    .stats-grid,
    .kpi-strip{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .page-actions,
    .page-actions .btn,
    .date-range-pill{
        width:100% !important;
    }

    .page-actions .btn,
    .date-range-pill{
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

    .erp-modal{
        width:96vw !important;
        max-width:96vw !important;
    }

    .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .modal-footer .btn{
        width:100% !important;
    }
}

</style>
</head>

<body>
<div class="pos-layout">
    <aside class="pos-sidebar">
        <?php include('partials/sidebar.php'); ?>
    </aside>

    <main class="pos-main">
        <?php include('partials/topbar.php'); ?>

        <div class="pos-content">
            <div class="page-header">
                <div>
                    <div class="page-title">Reports & Analytics</div>
                    <div class="breadcrumb-custom">
                        <a href="dashboard.php">Home</a><span>›</span>
                        <a href="agency-lead.php">Real Estate</a><span>›</span>
                        <span class="active">Reports</span>
                    </div>
                </div>

                <div class="page-actions">
                    <div class="date-range-pill">
                        <span class="material-icons-round">calendar_today</span>
                        <?= e($dateRangeLabel) ?>
                    </div>
                    <button class="btn btn-outline" type="button" onclick="openFilterModal()">
                        <span class="material-icons-round">tune</span> Filter
                    </button>
                    <a class="btn btn-success" href="report.php?<?= e(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
                        <span class="material-icons-round">download</span> Export
                    </a>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-top"><div class="stat-icon-box"><span class="material-icons-round">group_add</span></div></div>
                    <div class="stat-value"><?= (int)$totalLeads ?></div>
                    <div class="stat-label">Total Leads</div>
                    <div class="stat-compare">Selected period</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-top"><div class="stat-icon-box"><span class="material-icons-round">task_alt</span></div></div>
                    <div class="stat-value"><?= (int)$convertedLeads ?></div>
                    <div class="stat-label">Converted Leads</div>
                    <div class="stat-compare"><?= e($conversionRate) ?>% conversion rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-top"><div class="stat-icon-box"><span class="material-icons-round">cancel</span></div></div>
                    <div class="stat-value"><?= (int)$lostLeads ?></div>
                    <div class="stat-label">Lost Leads</div>
                    <div class="stat-compare">Lost / cancelled leads</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-top"><div class="stat-icon-box"><span class="material-icons-round">percent</span></div></div>
                    <div class="stat-value"><?= e($conversionRate) ?>%</div>
                    <div class="stat-label">Lead Conversion Rate</div>
                    <div class="stat-compare">Converted ÷ total leads</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-top"><div class="stat-icon-box"><span class="material-icons-round">monetization_on</span></div></div>
                    <div class="stat-value"><?= e(moneyShort($totalDealValue)) ?></div>
                    <div class="stat-label">Total Deal Value</div>
                    <div class="stat-compare"><?= (int)$totalDeals ?> deals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-top"><div class="stat-icon-box"><span class="material-icons-round">account_balance</span></div></div>
                    <div class="stat-value"><?= e(moneyShort($totalCommission)) ?></div>
                    <div class="stat-label">Total Commission</div>
                    <div class="stat-compare">Agent commission</div>
                </div>
            </div>

            <form class="filter-card" method="GET" action="report.php">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>From Date</label>
                        <input type="date" class="filter-input" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="filter-group">
                        <label>To Date</label>
                        <input type="date" class="filter-input" name="date_to" value="<?= e($dateTo) ?>">
                    </div>
                    <div class="filter-group">
                        <label>Agent</label>
                        <select class="filter-select" name="agent_id" <?= $isAgentUser ? 'disabled' : '' ?>>
                            <option value="">All Agents</option>
                            <?php foreach ($agents as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" <?= $agentId === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isAgentUser): ?><input type="hidden" name="agent_id" value="<?= (int)$user_id ?>"><?php endif; ?>
                    </div>
                    <div class="filter-group">
                        <label>Project</label>
                        <select class="filter-select" name="project_id">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['project_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Source</label>
                        <select class="filter-select" name="source">
                            <option value="">All Sources</option>
                            <?php foreach ($sources as $s): ?>
                                <option value="<?= e($s['source']) ?>" <?= $sourceFilter === $s['source'] ? 'selected' : '' ?>><?= e($s['source']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select class="filter-select" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?= e($s['status']) ?>" <?= $statusFilter === $s['status'] ? 'selected' : '' ?>><?= e($s['status']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button class="btn btn-primary" type="submit"><span class="material-icons-round">filter_list</span> Apply</button>
                        <a class="btn btn-outline" href="report.php"><span class="material-icons-round">refresh</span> Reset</a>
                    </div>
                </div>
            </form>

            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Leads Trend</div>
                            <div class="chart-card-subtitle">Daily leads, converted leads and lost leads</div>
                        </div>
                        <span class="chart-tag">Selected Period</span>
                    </div>
                    <div class="line-chart-container"><canvas id="lineChart" width="520" height="170"></canvas></div>
                    <div class="chart-legend" id="lineLegend"></div>
                </div>

                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Leads by Source</div>
                            <div class="chart-card-subtitle">Channel breakdown</div>
                        </div>
                    </div>
                    <div class="donut-wrapper">
                        <div class="donut-svg-wrap" style="width:120px;height:120px;">
                            <svg id="sourceDonut" width="120" height="120" viewBox="0 0 120 120"></svg>
                            <div class="donut-center-label">
                                <div class="donut-center-num" id="sourceTotal">0</div>
                                <div class="donut-center-text">Total</div>
                            </div>
                        </div>
                        <div class="donut-list" id="sourceList"></div>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Leads by Status</div>
                            <div class="chart-card-subtitle">Pipeline distribution</div>
                        </div>
                    </div>
                    <div class="donut-wrapper">
                        <div class="donut-svg-wrap" style="width:120px;height:120px;">
                            <svg id="statusDonut" width="120" height="120" viewBox="0 0 120 120"></svg>
                            <div class="donut-center-label">
                                <div class="donut-center-num" id="statusTotal">0</div>
                                <div class="donut-center-text">Leads</div>
                            </div>
                        </div>
                        <div class="donut-list" id="statusList"></div>
                    </div>
                </div>
            </div>

            <div class="kpi-strip">
                <div class="kpi-item"><div class="kpi-label">Total Leads</div><div class="kpi-value"><?= (int)$totalLeads ?></div></div>
                <div class="kpi-item"><div class="kpi-label">Converted</div><div class="kpi-value"><?= (int)$convertedLeads ?></div></div>
                <div class="kpi-item"><div class="kpi-label">Lost</div><div class="kpi-value"><?= (int)$lostLeads ?></div></div>
                <div class="kpi-item"><div class="kpi-label">Conversion Rate</div><div class="kpi-value"><?= e($conversionRate) ?>%</div></div>
                <div class="kpi-item"><div class="kpi-label">Deal Value</div><div class="kpi-value"><?= e(moneyShort($totalDealValue)) ?></div></div>
                <div class="kpi-item"><div class="kpi-label">Commission</div><div class="kpi-value"><?= e(moneyShort($totalCommission)) ?></div></div>
            </div>

            <div class="bottom-charts-row">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Lead to Deal Funnel</div>
                            <div class="chart-card-subtitle">Pipeline overview</div>
                        </div>
                    </div>
                    <div class="funnel-list" id="funnelList"></div>
                </div>

                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Deals by Agent</div>
                            <div class="chart-card-subtitle">Individual performance</div>
                        </div>
                    </div>
                    <div class="bar-chart-wrap" id="agentBar"></div>
                    <div class="x-labels" id="agentBarLabels"></div>
                </div>

                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Top Performing Properties</div>
                            <div class="chart-card-subtitle">Deals closed by property</div>
                        </div>
                    </div>
                    <div class="hbar-list" id="propHBar"></div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <div>
                        <div class="table-title">Agent-wise Leads, Deals & Commission</div>
                        <div class="muted">Example: Ali Agent → Total Leads, Converted, Deals, Commission</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Total Leads</th>
                                <th>Converted</th>
                                <th>Lost</th>
                                <th>Conversion Rate</th>
                                <th>Deals</th>
                                <th>Deal Value</th>
                                <th>Commission</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($agentWiseLeads)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:35px;color:#94a3b8;">No agent report data found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($agentWiseLeads as $a): ?>
                                <?php $rate = (int)$a['total_leads'] > 0 ? round(((int)$a['converted_leads'] / (int)$a['total_leads']) * 100, 1) : 0; ?>
                                <tr>
                                    <td><div class="name-strong"><?= e($a['agent_name']) ?></div></td>
                                    <td><?= (int)$a['total_leads'] ?></td>
                                    <td><span class="green"><?= (int)$a['converted_leads'] ?></span></td>
                                    <td><span class="red"><?= (int)$a['lost_leads'] ?></span></td>
                                    <td><span class="badge badge-blue"><?= e($rate) ?>%</span></td>
                                    <td><?= (int)$a['total_deals'] ?></td>
                                    <td><span class="money"><?= e(moneyFmt($a['deal_value'])) ?></span></td>
                                    <td><span class="money"><?= e(moneyFmt($a['commission_amount'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bottom-charts-row">
                <div class="table-card">
                    <div class="table-header"><div class="table-title">Pending Follow-ups</div></div>
                    <div class="table-wrap">
                        <table style="min-width:620px;">
                            <thead><tr><th>Lead</th><th>Phone</th><th>Agent</th><th>Follow-up</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (empty($pendingFollowupRows)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:28px;color:#94a3b8;">No pending follow-ups.</td></tr>
                            <?php else: foreach ($pendingFollowupRows as $f): ?>
                                <tr>
                                    <td><div class="name-strong"><?= e($f['lead_name'] ?: 'Unknown Lead') ?></div></td>
                                    <td><?= e($f['phone'] ?: '-') ?></td>
                                    <td><?= e($f['agent_name']) ?></td>
                                    <td><?= e(dateLabel($f['followup_date'])) ?><div class="muted"><?= e($f['followup_type'] ?: '-') ?></div></td>
                                    <td><span class="badge badge-yellow"><?= e($f['status'] ?: 'Pending') ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-header"><div class="table-title">Upcoming Site Visits</div></div>
                    <div class="table-wrap">
                        <table style="min-width:680px;">
                            <thead><tr><th>Lead</th><th>Property</th><th>Agent</th><th>Date / Time</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (empty($upcomingVisitRows)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:28px;color:#94a3b8;">No upcoming site visits.</td></tr>
                            <?php else: foreach ($upcomingVisitRows as $v): ?>
                                <tr>
                                    <td><div class="name-strong"><?= e($v['lead_name'] ?: 'Unknown Lead') ?></div><div class="muted"><?= e($v['phone'] ?: '-') ?></div></td>
                                    <td><?= e($v['property_title']) ?></td>
                                    <td><?= e($v['agent_name']) ?></td>
                                    <td><?= e(dateLabel($v['visit_date'])) ?><div class="muted"><?= e($v['visit_time'] ?: '-') ?></div></td>
                                    <td><span class="badge badge-blue"><?= e($v['visit_status'] ?: 'Scheduled') ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Property Status Report</div>
                            <div class="chart-card-subtitle">Available / Booked / Sold / Hold</div>
                        </div>
                    </div>
                    <div class="donut-wrapper">
                        <div class="donut-svg-wrap" style="width:120px;height:120px;">
                            <svg id="propertyStatusDonut" width="120" height="120" viewBox="0 0 120 120"></svg>
                            <div class="donut-center-label">
                                <div class="donut-center-num" id="propertyStatusTotal">0</div>
                                <div class="donut-center-text">Properties</div>
                            </div>
                        </div>
                        <div class="donut-list" id="propertyStatusList"></div>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <div>
                        <div class="table-title">Monthly Deals & Commission</div>
                        <div class="muted">Monthly deals, deal amount and commission summary</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Month</th><th>Total Deals</th><th>Deal Value</th><th>Commission</th></tr></thead>
                        <tbody>
                        <?php if (empty($monthlyDeals)): ?>
                            <tr><td colspan="4" style="text-align:center;padding:28px;color:#94a3b8;">No monthly deal data found.</td></tr>
                        <?php else: foreach ($monthlyDeals as $m): ?>
                            <tr>
                                <td><div class="name-strong"><?= e($m['month_label']) ?></div></td>
                                <td><?= (int)$m['total_deals'] ?></td>
                                <td><span class="money"><?= e(moneyFmt($m['deal_value'])) ?></span></td>
                                <td><span class="money"><?= e(moneyFmt($m['commission_amount'])) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- FILTER MODAL -->
<div class="modal-overlay" id="filterModal">
    <div class="erp-modal">
        <div class="modal-header">
            <div class="modal-title"><span class="material-icons-round">tune</span> Filter Reports</div>
            <button class="modal-close" type="button" onclick="closeFilterModal()"><span class="material-icons-round">close</span></button>
        </div>
        <form method="GET" action="report.php">
            <div class="modal-body">
                <div class="form-group"><label>From Date</label><input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
                <div class="form-group"><label>To Date</label><input class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
                <div class="form-group"><label>Agent</label>
                    <select class="form-control" name="agent_id" <?= $isAgentUser ? 'disabled' : '' ?>>
                        <option value="">All Agents</option>
                        <?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $agentId === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
                    </select>
                    <?php if ($isAgentUser): ?><input type="hidden" name="agent_id" value="<?= (int)$user_id ?>"><?php endif; ?>
                </div>
                <div class="form-group"><label>Project</label>
                    <select class="form-control" name="project_id">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['project_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Source</label>
                    <select class="form-control" name="source">
                        <option value="">All Sources</option>
                        <?php foreach ($sources as $s): ?><option value="<?= e($s['source']) ?>" <?= $sourceFilter === $s['source'] ? 'selected' : '' ?>><?= e($s['source']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Status</label>
                    <select class="form-control" name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $s): ?><option value="<?= e($s['status']) ?>" <?= $statusFilter === $s['status'] ? 'selected' : '' ?>><?= e($s['status']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline" href="report.php"><span class="material-icons-round">refresh</span> Reset</a>
                <button class="btn btn-primary" type="submit"><span class="material-icons-round">check</span> Apply Filter</button>
            </div>
        </form>
    </div>
</div>

<script>
const reportData = <?= $chartJson ?: '{}' ?>;

function openFilterModal(){ document.getElementById('filterModal').classList.add('open'); }
function closeFilterModal(){ document.getElementById('filterModal').classList.remove('open'); }

document.getElementById('filterModal')?.addEventListener('click', e => {
  if (e.target === e.currentTarget) closeFilterModal();
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeFilterModal();
});

function renderLineChart(data){
  const canvas = document.getElementById('lineChart');
  if(!canvas) return;
  const ctx = canvas.getContext('2d');
  const W = canvas.parentElement.offsetWidth || 520;
  const H = 170;
  canvas.width = W;
  canvas.height = H;
  ctx.clearRect(0,0,W,H);

  const padL=34,padR=16,padT=16,padB=28;
  const chartW=W-padL-padR, chartH=H-padT-padB;
  const labels=data.lineChart.labels.length ? data.lineChart.labels : ['No Data'];
  const datasets=data.lineChart.datasets || [];

  let maxVal=0;
  datasets.forEach(ds => ds.data.forEach(v => { if(Number(v)>maxVal) maxVal=Number(v); }));
  maxVal=Math.max(5, Math.ceil(maxVal/5)*5);

  ctx.strokeStyle='#f0f1f3'; ctx.lineWidth=1;
  for(let i=0;i<=4;i++){
    const y=padT+(chartH/4)*i;
    ctx.beginPath(); ctx.moveTo(padL,y); ctx.lineTo(W-padR,y); ctx.stroke();
    const val=Math.round(maxVal-(maxVal/4)*i);
    ctx.fillStyle='#9ca3af'; ctx.font='9px Inter, sans-serif'; ctx.textAlign='right';
    ctx.fillText(val,padL-6,y+3);
  }

  labels.forEach((lbl,i)=>{
    const x = labels.length > 1 ? padL+(chartW/(labels.length-1))*i : padL + chartW/2;
    ctx.fillStyle='#9ca3af'; ctx.font='9px Inter, sans-serif'; ctx.textAlign='center';
    ctx.fillText(lbl,x,H-4);
  });

  datasets.forEach(ds=>{
    const points=(ds.data.length?ds.data:[0]).map((v,i)=>({
      x: labels.length > 1 ? padL+(chartW/(labels.length-1))*i : padL + chartW/2,
      y: padT+chartH-(Number(v)/maxVal)*chartH
    }));

    ctx.beginPath();
    points.forEach((p,i)=> i===0 ? ctx.moveTo(p.x,p.y) : ctx.lineTo(p.x,p.y));
    ctx.strokeStyle=ds.color; ctx.lineWidth=2; ctx.lineJoin='round'; ctx.lineCap='round'; ctx.stroke();

    points.forEach(p=>{
      ctx.beginPath(); ctx.arc(p.x,p.y,3.5,0,Math.PI*2);
      ctx.fillStyle='#fff'; ctx.fill();
      ctx.strokeStyle=ds.color; ctx.lineWidth=2; ctx.stroke();
    });
  });

  document.getElementById('lineLegend').innerHTML = datasets.map(ds => `
    <div class="legend-item"><span class="legend-dot" style="background:${ds.color}"></span>${ds.label}</div>
  `).join('');
}

function renderDonut(svgId,listId,totalId,data){
  const svg=document.getElementById(svgId);
  const list=document.getElementById(listId);
  const totalEl=document.getElementById(totalId);
  if(!svg || !list) return;

  const items=data.items || [];
  const total=data.total || 0;
  if(totalEl) totalEl.textContent=total;

  const cx=60,cy=60,r=44,strokeW=14,circumference=2*Math.PI*r;
  let offset=-0.25*circumference;

  let html=`<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="#f3f4f6" stroke-width="${strokeW}"/>`;
  if(items.length){
    items.forEach(item=>{
      const dashArray=(item.pct/100)*circumference;
      html += `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${item.color}" stroke-width="${strokeW}" stroke-dasharray="${dashArray} ${circumference-dashArray}" stroke-dashoffset="${offset}" />`;
      offset -= dashArray;
    });
  }
  svg.innerHTML=html;

  list.innerHTML = items.length ? items.map(item=>`
    <div class="donut-list-item">
      <span class="donut-list-dot" style="background:${item.color}"></span>
      <span class="donut-list-label">${escapeHtml(item.label)}</span>
      <span class="donut-list-val">${item.value}</span>
      <span class="donut-list-pct">${item.pct}%</span>
    </div>
  `).join('') : `<div class="donut-list-item"><span class="donut-list-label">No data found</span></div>`;
}

function renderFunnel(data){
  const list=document.getElementById('funnelList');
  list.innerHTML=(data.funnel || []).map(f=>`
    <div>
      <div class="funnel-top"><span class="funnel-label">${escapeHtml(f.label)}</span><span class="funnel-val">${f.value} <span class="muted">(${f.pct}%)</span></span></div>
      <div class="funnel-track"><div class="funnel-fill" style="width:${Math.max(2,f.pct)}%;background:${f.color}"></div></div>
    </div>
  `).join('');
}

function renderAgentBar(data){
  const wrap=document.getElementById('agentBar');
  const labels=document.getElementById('agentBarLabels');
  const items=data.agentBar || [];
  const maxVal=Math.max(1,...items.map(i=>Number(i.value)||0));
  wrap.innerHTML=items.map(item=>{
    const h=(Number(item.value)/maxVal)*85;
    return `<div class="bar-col"><div class="bar-val">${item.value}</div><div class="bar-fill" style="height:${Math.max(4,h)}%;background:${item.color}"></div></div>`;
  }).join('');
  labels.innerHTML=items.map(item=>`<div class="x-label">${escapeHtml(String(item.label).split(' ')[0])}</div>`).join('');
}

function renderHBar(){
  const wrap=document.getElementById('propHBar');
  const items=reportData.propHBar || [];
  const maxVal=Math.max(1,...items.map(i=>Number(i.value)||0));
  wrap.innerHTML=items.map(item=>{
    const w=(Number(item.value)/maxVal)*100;
    return `<div>
      <div class="hbar-top"><span class="hbar-label">${escapeHtml(item.label)}</span><span class="hbar-val">${item.value}</span></div>
      <div class="hbar-track"><div class="hbar-fill" style="width:${Math.max(2,w)}%;background:${item.color}"></div></div>
    </div>`;
  }).join('');
}

function escapeHtml(str){
  return String(str || '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
}

window.addEventListener('resize', () => renderLineChart(reportData));
renderLineChart(reportData);
renderDonut('sourceDonut','sourceList','sourceTotal',reportData.sourceDonut || {total:0,items:[]});
renderDonut('statusDonut','statusList','statusTotal',reportData.statusDonut || {total:0,items:[]});
renderDonut('propertyStatusDonut','propertyStatusList','propertyStatusTotal',reportData.propertyStatus || {total:0,items:[]});
renderFunnel(reportData);
renderAgentBar(reportData);
renderHBar();
</script>

</body>
</html>
