<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/business_session_helper.php');

if (empty($_SESSION['user_id']) || empty($_SESSION['client_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$clientId = (int)$_SESSION['client_id'];
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));

if (!in_array($status, array('all','open','closed'), true)) {
    $status = 'all';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d');

function bsPageE($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function bsPageMoney($value)
{
    return 'Rs ' . number_format((float)$value, 2, '.', ',');
}

$rows = array();
$current = oneerpBusinessCurrentSession($conn, $clientId, $branchId);

if (oneerpBusinessTableExists($conn, 'business_sessions')) {
    $sql = "\n        SELECT bs.*,\n               uo.name AS opened_by_name,\n               uc.name AS closed_by_name\n        FROM business_sessions bs\n        LEFT JOIN users uo ON uo.id = bs.opened_by\n        LEFT JOIN users uc ON uc.id = bs.closed_by\n        WHERE bs.client_id = ?\n          AND bs.branch_id = ?\n          AND bs.deleted_at IS NULL\n          AND bs.business_date BETWEEN ? AND ?\n    ";
    $types = 'iiss';
    $params = array($clientId, $branchId, $dateFrom, $dateTo);

    if ($status !== 'all') {
        $sql .= " AND bs.status = ? ";
        $types .= 's';
        $params[] = $status;
    }
    $sql .= " ORDER BY bs.id DESC LIMIT 250";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $bind = array($types);
        foreach ($params as $k => $v) $bind[] = &$params[$k];
        call_user_func_array(array($stmt, 'bind_param'), $bind);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (($row['status'] ?? '') === 'open') {
                $live = oneerpBusinessSessionSummary($conn, $clientId, (int)$row['id']);
                if ($live) {
                    foreach (array('total_orders','gross_sales','discounts','tax','refunds','net_sales','expected_cash') as $key) {
                        $row[$key] = $live[$key];
                    }
                }
            }
            $rows[] = $row;
        }
        $stmt->close();
    }
}

$totalClosed = 0;
$totalNet = 0.00;
$totalDifference = 0.00;
foreach ($rows as $r) {
    if (($r['status'] ?? '') === 'closed') $totalClosed++;
    $totalNet += (float)($r['net_sales'] ?? 0);
    $totalDifference += (float)($r['cash_difference'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Business Sessions - ONEERP</title>
<?php include(__DIR__ . '/partials/head.php'); ?>
<style>
body{background:#070b14;color:#e5e7eb}
.bs-shell{display:flex;min-height:100vh}.bs-sidebar{width:250px;flex:0 0 250px}.bs-main{flex:1;min-width:0;padding-top:70px}.bs-content{padding:22px}
.bs-heading{display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:18px}.bs-heading h2{margin:0;font-size:25px;font-weight:900}.bs-heading p{margin:4px 0 0;color:#94a3b8;font-size:12px}
.bs-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.bs-card,.bs-panel{background:linear-gradient(180deg,#111827,#0f172a);border:1px solid rgba(148,163,184,.14);border-radius:17px;box-shadow:0 12px 28px rgba(0,0,0,.18)}
.bs-card{padding:15px}.bs-card span{display:block;color:#94a3b8;font-size:10px;text-transform:uppercase;font-weight:850}.bs-card strong{display:block;margin-top:5px;font-size:21px;color:#fff}
.bs-panel{padding:15px}.bs-filter{display:grid;grid-template-columns:160px 170px 170px auto;gap:9px;align-items:end}.bs-field label{display:block;margin-bottom:5px;color:#cbd5e1;font-size:10px;font-weight:800}.bs-field input,.bs-field select{width:100%;height:40px;border-radius:10px;border:1px solid rgba(148,163,184,.18);background:#0b1220;color:#fff;padding:0 10px}.bs-btn{height:40px;padding:0 14px;border:0;border-radius:10px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;font-size:12px;font-weight:900;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.bs-btn.green{background:linear-gradient(135deg,#10b981,#047857)}
.bs-table-wrap{overflow:auto;margin-top:15px}.bs-table{width:100%;border-collapse:collapse;min-width:1050px}.bs-table th,.bs-table td{padding:11px 10px;text-align:left;border-bottom:1px solid rgba(148,163,184,.10);font-size:12px}.bs-table th{color:#94a3b8;font-size:10px;text-transform:uppercase}.bs-table td{color:#e2e8f0}.bs-badge{padding:5px 9px;border-radius:999px;font-size:10px;font-weight:900}.bs-badge.open{background:rgba(16,185,129,.15);color:#6ee7b7}.bs-badge.closed{background:rgba(148,163,184,.14);color:#cbd5e1}.bs-empty{padding:35px;text-align:center;color:#94a3b8}.bs-current{display:flex;align-items:center;gap:9px;color:#a7f3d0;font-size:12px;font-weight:800}
@media(max-width:991px){.bs-sidebar{display:none}.bs-main{padding-top:70px}.bs-cards{grid-template-columns:1fr 1fr}.bs-filter{grid-template-columns:1fr 1fr}.bs-content{padding:14px}}@media(max-width:600px){.bs-cards,.bs-filter{grid-template-columns:1fr}.bs-heading{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="bs-shell">
  <div class="bs-sidebar" id="dashboardSidebar"><?php include(__DIR__ . '/partials/sidebar.php'); ?></div>
  <div class="bs-main">
    <?php include(__DIR__ . '/partials/topbar.php'); ?>
    <main class="bs-content">
      <div class="bs-heading">
        <div>
          <h2>Business Sessions</h2>
          <p>Opening cash, sales, payment mix and closing settlement by Business Day.</p>
        </div>
        <?php if ($current): ?>
          <div class="bs-current">● Session <?= bsPageE($current['session_no']) ?> is OPEN</div>
        <?php else: ?>
          <button type="button" class="bs-btn green" onclick="document.getElementById('oneerpBusinessDayBtn')?.click()">Start Business Day</button>
        <?php endif; ?>
      </div>

      <div class="bs-cards">
        <div class="bs-card"><span>Sessions in Result</span><strong><?= count($rows) ?></strong></div>
        <div class="bs-card"><span>Closed Sessions</span><strong><?= $totalClosed ?></strong></div>
        <div class="bs-card"><span>Net Sales</span><strong><?= bsPageMoney($totalNet) ?></strong></div>
        <div class="bs-card"><span>Total Cash Difference</span><strong><?= bsPageMoney($totalDifference) ?></strong></div>
      </div>

      <section class="bs-panel">
        <form method="get" class="bs-filter">
          <div class="bs-field"><label>Status</label><select name="status"><option value="all" <?= $status==='all'?'selected':'' ?>>All</option><option value="open" <?= $status==='open'?'selected':'' ?>>Open</option><option value="closed" <?= $status==='closed'?'selected':'' ?>>Closed</option></select></div>
          <div class="bs-field"><label>From</label><input type="date" name="date_from" value="<?= bsPageE($dateFrom) ?>"></div>
          <div class="bs-field"><label>To</label><input type="date" name="date_to" value="<?= bsPageE($dateTo) ?>"></div>
          <button class="bs-btn" type="submit">Apply Filters</button>
        </form>

        <div class="bs-table-wrap">
          <table class="bs-table">
            <thead><tr><th>Session</th><th>Date / Time</th><th>Status</th><th>Orders</th><th>Net Sales</th><th>Opening</th><th>Expected Cash</th><th>Actual Cash</th><th>Difference</th><th>Opened By</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="11" class="bs-empty">No Business Sessions found for these filters.</td></tr>
            <?php else: foreach ($rows as $row): ?>
              <tr>
                <td><strong><?= bsPageE($row['session_no']) ?></strong></td>
                <td><?= bsPageE($row['business_date']) ?><br><span style="color:#64748b"><?= bsPageE($row['opened_at']) ?><?= !empty($row['closed_at']) ? ' → ' . bsPageE($row['closed_at']) : '' ?></span></td>
                <td><span class="bs-badge <?= bsPageE($row['status']) ?>"><?= strtoupper(bsPageE($row['status'])) ?></span></td>
                <td><?= (int)($row['total_orders'] ?? 0) ?></td>
                <td><?= bsPageMoney($row['net_sales'] ?? 0) ?></td>
                <td><?= bsPageMoney($row['opening_cash'] ?? 0) ?></td>
                <td><?= bsPageMoney($row['expected_cash'] ?? 0) ?></td>
                <td><?= ($row['status']==='closed') ? bsPageMoney($row['actual_closing_cash'] ?? 0) : '—' ?></td>
                <td><?= ($row['status']==='closed') ? bsPageMoney($row['cash_difference'] ?? 0) : '—' ?></td>
                <td><?= bsPageE($row['opened_by_name'] ?? ('User #' . (int)$row['opened_by'])) ?></td>
                <td><a class="bs-btn" style="height:34px;padding:0 11px" href="business_session_view.php?id=<?= (int)$row['id'] ?>">View</a></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
</div>
</body>
</html>
