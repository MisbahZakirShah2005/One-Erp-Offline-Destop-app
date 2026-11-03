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
$sessionId = (int)($_GET['id'] ?? 0);
$session = oneerpBusinessGetSession($conn, $clientId, $sessionId);

if (!$session) {
    http_response_code(404);
    die('Business Session not found.');
}

$summary = oneerpBusinessSessionSummary($conn, $clientId, $sessionId);

function bsvE($value){return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');}
function bsvMoney($value){return 'Rs ' . number_format((float)$value, 2, '.', ',');}

$destinationAccount = null;
if (!empty($session['destination_account_id']) && oneerpBusinessTableExists($conn, 'business_settlement_accounts')) {
    $aid = (int)$session['destination_account_id'];
    $stmt = $conn->prepare("SELECT account_type, account_name, account_number FROM business_settlement_accounts WHERE id=? AND client_id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $aid, $clientId);
        $stmt->execute();
        $destinationAccount = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= bsvE($session['session_no']) ?> - Business Session</title>
<?php include(__DIR__ . '/partials/head.php'); ?>
<style>
body{background:#070b14;color:#e5e7eb}.bsv-shell{display:flex;min-height:100vh}.bsv-side{width:250px;flex:0 0 250px}.bsv-main{flex:1;min-width:0;padding-top:70px}.bsv-content{padding:22px}.bsv-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:16px}.bsv-head h2{font-size:24px;margin:0;font-weight:900}.bsv-sub{color:#94a3b8;font-size:12px;margin-top:4px}.bsv-btn{height:38px;padding:0 13px;border-radius:10px;background:#1e293b;border:1px solid rgba(148,163,184,.16);color:#fff;text-decoration:none;display:inline-flex;align-items:center;font-size:12px;font-weight:850}.bsv-status{padding:6px 10px;border-radius:999px;font-size:10px;font-weight:900}.bsv-status.open{background:rgba(16,185,129,.16);color:#6ee7b7}.bsv-status.closed{background:rgba(148,163,184,.14);color:#cbd5e1}
.bsv-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.bsv-card,.bsv-panel{background:linear-gradient(180deg,#111827,#0f172a);border:1px solid rgba(148,163,184,.14);border-radius:16px}.bsv-card{padding:14px}.bsv-card small{display:block;color:#94a3b8;font-size:10px;font-weight:850;text-transform:uppercase}.bsv-card strong{display:block;margin-top:5px;font-size:19px}.bsv-panel{padding:15px;margin-top:13px}.bsv-title{font-size:13px;font-weight:900;margin-bottom:10px}.bsv-pay{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}.bsv-mini{padding:10px;border-radius:11px;background:#0b1220}.bsv-mini span{display:block;color:#94a3b8;font-size:10px}.bsv-mini b{display:block;margin-top:3px;font-size:14px}.bsv-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.bsv-row{display:flex;justify-content:space-between;gap:15px;padding:8px 0;border-bottom:1px solid rgba(148,163,184,.09);font-size:12px}.bsv-row:last-child{border-bottom:0}.bsv-row span{color:#94a3b8}.bsv-table{width:100%;border-collapse:collapse}.bsv-table th,.bsv-table td{padding:9px;border-bottom:1px solid rgba(148,163,184,.09);text-align:left;font-size:12px}.bsv-table th{font-size:10px;color:#94a3b8;text-transform:uppercase}.bsv-empty{padding:18px;color:#64748b;text-align:center}.bsv-accounts{display:grid;grid-template-columns:1fr 1fr;gap:8px}.bsv-account{display:flex;justify-content:space-between;padding:9px 10px;border-radius:10px;background:#0b1220;font-size:11px}
@media(max-width:991px){.bsv-side{display:none}.bsv-grid{grid-template-columns:1fr 1fr}.bsv-pay{grid-template-columns:1fr 1fr}.bsv-two,.bsv-accounts{grid-template-columns:1fr}.bsv-content{padding:14px}}@media(max-width:560px){.bsv-grid{grid-template-columns:1fr}.bsv-head{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="bsv-shell">
<div class="bsv-side" id="dashboardSidebar"><?php include(__DIR__ . '/partials/sidebar.php'); ?></div>
<div class="bsv-main">
<?php include(__DIR__ . '/partials/topbar.php'); ?>
<main class="bsv-content">
  <div class="bsv-head">
    <div><h2><?= bsvE($session['session_no']) ?> <span class="bsv-status <?= bsvE($session['status']) ?>"><?= strtoupper(bsvE($session['status'])) ?></span></h2><div class="bsv-sub"><?= bsvE($session['business_date']) ?> • Opened <?= bsvE($session['opened_at']) ?><?= !empty($session['closed_at']) ? ' • Closed ' . bsvE($session['closed_at']) : '' ?></div></div>
    <div style="display:flex;gap:8px"><a class="bsv-btn" href="business_sessions.php">← Back</a><?php if($session['status']==='open'): ?><button class="bsv-btn" type="button" onclick="document.getElementById('oneerpBusinessDayBtn')?.click()" style="background:linear-gradient(135deg,#ef4444,#b91c1c);border:0">Close Day</button><?php endif; ?></div>
  </div>

  <div class="bsv-grid">
    <div class="bsv-card"><small>Orders</small><strong><?= (int)$summary['total_orders'] ?></strong></div>
    <div class="bsv-card"><small>Gross Sales</small><strong><?= bsvMoney($summary['gross_sales']) ?></strong></div>
    <div class="bsv-card"><small>Refunds</small><strong><?= bsvMoney($summary['refunds']) ?></strong></div>
    <div class="bsv-card"><small>Net Sales</small><strong><?= bsvMoney($summary['net_sales']) ?></strong></div>
    <div class="bsv-card"><small>Opening Cash</small><strong><?= bsvMoney($summary['opening_cash']) ?></strong></div>
    <div class="bsv-card"><small>Expected Cash</small><strong><?= bsvMoney($summary['expected_cash']) ?></strong></div>
    <div class="bsv-card"><small>Actual Closing</small><strong><?= $session['status']==='closed' ? bsvMoney($summary['actual_closing_cash']) : '—' ?></strong></div>
    <div class="bsv-card"><small>Cash Difference</small><strong><?= $session['status']==='closed' ? bsvMoney($summary['cash_difference']) : '—' ?></strong></div>
  </div>

  <section class="bsv-panel"><div class="bsv-title">Payment Received Breakdown</div><div class="bsv-pay">
    <div class="bsv-mini"><span>Cash</span><b><?= bsvMoney($summary['cash_received']) ?></b></div>
    <div class="bsv-mini"><span>Card</span><b><?= bsvMoney($summary['card_received']) ?></b></div>
    <div class="bsv-mini"><span>Wallet</span><b><?= bsvMoney($summary['wallet_received']) ?></b></div>
    <div class="bsv-mini"><span>Bank Transfer</span><b><?= bsvMoney($summary['bank_received']) ?></b></div>
    <div class="bsv-mini"><span>Other</span><b><?= bsvMoney($summary['other_received']) ?></b></div>
  </div>
  <?php $accountRows=array_merge($summary['wallet_accounts']??array(),$summary['bank_accounts']??array(),$summary['other_accounts']??array()); ?>
  <div class="bsv-accounts" style="margin-top:9px"><?php if(!$accountRows): ?><div class="bsv-empty">No account-level payment split recorded.</div><?php else: foreach($accountRows as $ar): ?><div class="bsv-account"><span><?= bsvE($ar['name']??'Account') ?></span><b><?= bsvMoney($ar['amount']??0) ?></b></div><?php endforeach; endif; ?></div>
  </section>

  <div class="bsv-two">
    <section class="bsv-panel"><div class="bsv-title">Cash Drawer Summary</div>
      <div class="bsv-row"><span>Opening Cash</span><b><?= bsvMoney($summary['opening_cash']) ?></b></div>
      <div class="bsv-row"><span>Cash Sales</span><b><?= bsvMoney($summary['cash_received']) ?></b></div>
      <div class="bsv-row"><span>Cash In</span><b><?= bsvMoney($summary['cash_in']) ?></b></div>
      <div class="bsv-row"><span>Cash Out</span><b><?= bsvMoney($summary['cash_out']) ?></b></div>
      <div class="bsv-row"><span>Cash Expenses</span><b><?= bsvMoney($summary['cash_expenses']) ?></b></div>
      <div class="bsv-row"><span>Cash Refunds</span><b><?= bsvMoney($summary['cash_refunds']) ?></b></div>
      <div class="bsv-row"><span>Expected Cash</span><b><?= bsvMoney($summary['expected_cash']) ?></b></div>
    </section>
    <section class="bsv-panel"><div class="bsv-title">Closing Settlement</div>
      <div class="bsv-row"><span>Method</span><b><?= bsvE($session['settlement_method'] ?: '—') ?></b></div>
      <div class="bsv-row"><span>Transfer Amount</span><b><?= bsvMoney($session['transfer_amount'] ?? 0) ?></b></div>
      <div class="bsv-row"><span>Destination</span><b><?= $destinationAccount ? bsvE(($destinationAccount['account_name']??'') . (!empty($destinationAccount['account_number']) ? ' - '.$destinationAccount['account_number'] : '')) : '—' ?></b></div>
      <div class="bsv-row"><span>Cash Retained</span><b><?= bsvMoney($session['cash_retained'] ?? 0) ?></b></div>
      <div class="bsv-row"><span>Owner Note</span><b><?= bsvE($session['owner_note'] ?: '—') ?></b></div>
      <div class="bsv-row"><span>Closing Notes</span><b><?= bsvE($session['closing_notes'] ?: '—') ?></b></div>
    </section>
  </div>

  <section class="bsv-panel"><div class="bsv-title">Staff Sales</div><div style="overflow:auto"><table class="bsv-table"><thead><tr><th>Staff</th><th>Orders</th><th>Sales</th></tr></thead><tbody>
  <?php if(empty($summary['staff_sales'])): ?><tr><td colspan="3" class="bsv-empty">No staff sales recorded in this session.</td></tr><?php else: foreach($summary['staff_sales'] as $staff): ?><tr><td><?= bsvE($staff['user_name']) ?></td><td><?= (int)$staff['orders'] ?></td><td><?= bsvMoney($staff['sales_amount']) ?></td></tr><?php endforeach; endif; ?>
  </tbody></table></div></section>

  <section class="bsv-panel"><div class="bsv-title">Deleted Sales Log</div><div style="overflow:auto"><table class="bsv-table"><thead><tr><th>Invoice</th><th>Amount</th><th>Deleted By</th><th>Role</th><th>Reason</th><th>Time</th></tr></thead><tbody>
  <?php if(empty($summary['deleted_sales'])): ?><tr><td colspan="6" class="bsv-empty">No deleted sale logs recorded for this session.</td></tr><?php else: foreach($summary['deleted_sales'] as $log): ?><tr><td><?= bsvE($log['invoice_no'] ?: ('Sale #'.$log['sale_id'])) ?></td><td><?= bsvMoney($log['sale_amount']) ?></td><td><?= bsvE($log['deleted_by_name']) ?></td><td><?= bsvE($log['role_id']) ?></td><td><?= bsvE($log['reason']) ?></td><td><?= bsvE($log['deleted_at']) ?></td></tr><?php endforeach; endif; ?>
  </tbody></table></div></section>
</main>
</div></div>
</body></html>
