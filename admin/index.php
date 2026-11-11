<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

admin_start('Dashboard', 'index.php');
settle_due();

$users    = (int)q('SELECT COUNT(*) FROM users')->fetchColumn();
$newToday = (int)q('SELECT COUNT(*) FROM users WHERE created_at >= ?', [gmdate('Y-m-d H:i:s', (new DateTime('today', new DateTimeZone(display_tz())))->getTimestamp())])->fetchColumn();
$new7     = (int)q('SELECT COUNT(*) FROM users WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)')->fetchColumn();
$active24 = (int)q('SELECT COUNT(DISTINCT user_id) FROM trades WHERE open_ts >= ?', [time() - 86400])->fetchColumn();
$openT    = (int)q('SELECT COUNT(*) FROM trades WHERE status = 'open'')->fetchColumn();
$pending  = (int)q('SELECT COUNT(*) FROM payment_requests WHERE status = 'pending'')->fetchColumn();
$blocked  = (int)q('SELECT COUNT(*) FROM users WHERE status = 'blocked'')->fetchColumn();

$byAcc = [];
foreach (q('SELECT account_type a, COUNT(*) n, SUM(amount) vol, SUM(-profit) house,
                   SUM(status = 'won') w, SUM(status = 'lost') l
            FROM trades WHERE status <> 'open' GROUP BY account_type')->fetchAll() as $r) $byAcc[$r['a']] = $r;
$byAcc24 = [];
foreach (q('SELECT account_type a, SUM(amount) vol, SUM(-profit) house
            FROM trades WHERE status <> 'open' AND settled_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY) GROUP BY account_type')->fetchAll() as $r) $byAcc24[$r['a']] = $r;
$liab = [];
foreach (q('SELECT type, SUM(balance) s FROM wallets GROUP BY type')->fetchAll() as $r) $liab[$r['type']] = (float)$r['s'];
$flow = [];
foreach (q('SELECT type, account_type a, SUM(amount) s FROM payment_requests WHERE status = 'approved' GROUP BY type, account_type')->fetchAll() as $r) $flow[$r['type']][$r['a']] = (float)$r['s'];

$recent = q('SELECT t.*, a.symbol, u.email FROM trades t JOIN assets a ON a.id = t.asset_id JOIN users u ON u.id = t.user_id ORDER BY t.id DESC LIMIT 10')->fetchAll();
$logs = q('SELECT l.*, a.name, a.email FROM admin_logs l LEFT JOIN admins a ON a.id = l.admin_id ORDER BY l.id DESC LIMIT 8')->fetchAll();

$feed = quotes_get();
$feedAssets = active_assets();
$src = url('admin/stats.php');
?>
<h1>Dashboard</h1>

<?php if (!mail_ready()): ?>
  <div class="flash info">Email (SMTP) is not set up yet – password reset, verification and deposit alerts are off. <a href="settings.php#smtp">Configure email →</a></div>
<?php endif; ?>

<div class="kpis">
  <div class="stat"><div class="l">Users</div><div class="v"><?= $users ?></div><div class="s">+<?= $newToday ?> today · +<?= $new7 ?> this week<?= $blocked ? ' · ' . $blocked . ' blocked' : '' ?></div></div>
  <div class="stat"><div class="l">Active traders (24h)</div><div class="v"><?= $active24 ?></div><div class="s"><?= $openT ?> open trade<?= $openT === 1 ? '' : 's' ?> now</div></div>
  <div class="stat"><div class="l">Pending requests</div><div class="v <?= $pending ? 'warn' : '' ?>"><?= $pending ?></div><div class="s"><a href="requests.php">Review →</a></div></div>
  <div class="stat"><div class="l">Trading</div><div class="v <?= setting('trading_enabled') === '1' ? 'green' : 'red' ?>"><?= setting('trading_enabled') === '1' ? 'ON' : 'OFF' ?></div><div class="s">Payout <b><?= e(rtrim(rtrim(number_format((float)setting('payout_percent', 85), 2), '0'), '.')) ?>%</b> · <a href="assets.php">Markets →</a></div></div>
</div>

<div class="acctpick"><span class="lbl">Show charts for</span>
  <button type="button" class="btn sm ghost on" data-chartacc="inr">INR</button>
  <button type="button" class="btn sm ghost" data-chartacc="usdt">USDT</button>
  <button type="button" class="btn sm ghost" data-chartacc="demo">Demo</button>
  <span class="muted" style="font-size:12px;margin-left:6px">Tap ⤢ on any chart to expand it.</span>
</div>
<div class="grid g2" style="margin-bottom:18px">
  <?= chart_card('New users', 'signups', $src) ?>
  <?= chart_card('Trade volume', 'volume', $src) ?>
  <?= chart_card('House profit & loss', 'pnl', $src) ?>
  <?= chart_card('Win / loss split', 'results', $src) ?>
  <?= chart_card('Predictions: up vs down', 'predictions', $src) ?>
  <?= chart_card('Deposits & withdrawals', 'cashflow', $src) ?>
  <?= chart_card('Top assets by volume', 'assets', $src) ?>
</div>

<div class="card">
  <h2>Platform results by account</h2>
  <div class="tablewrap"><table class="rt">
    <tr><th>Account</th><th class="right">Closed trades</th><th class="right">Won / Lost</th><th class="right">Volume</th><th class="right">House P&amp;L (all)</th><th class="right">House P&amp;L (24h)</th><th class="right">User balances held</th><th class="right">Deposits</th><th class="right">Withdrawals</th></tr>
    <?php foreach (ACCOUNTS as $a): $r = $byAcc[$a] ?? null; $h = (float)($r['house'] ?? 0); $h24 = (float)($byAcc24[$a]['house'] ?? 0); ?>
      <tr><td><span class="dot <?= e($a) ?>"></span><b><?= e(acc_meta($a)['short']) ?></b></td>
        <td class="right"><?= (int)($r['n'] ?? 0) ?></td>
        <td class="right"><span class="green"><?= (int)($r['w'] ?? 0) ?></span> / <span class="red"><?= (int)($r['l'] ?? 0) ?></span></td>
        <td class="right"><?= e(money($r['vol'] ?? 0, $a)) ?></td>
        <td class="right <?= $h >= 0 ? 'green' : 'red' ?>"><?= e(money($h, $a, true)) ?></td>
        <td class="right <?= $h24 >= 0 ? 'green' : 'red' ?>"><?= e(money($h24, $a, true)) ?></td>
        <td class="right"><?= e(money($liab[$a] ?? 0, $a)) ?></td>
        <td class="right"><?= $a === 'demo' ? '—' : e(money($flow['deposit'][$a] ?? 0, $a)) ?></td>
        <td class="right"><?= $a === 'demo' ? '—' : e(money($flow['withdraw'][$a] ?? 0, $a)) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted" style="font-size:12px;margin-bottom:0">House P&amp;L = amounts users lost minus profits paid to winners. Demo figures are virtual.</p>
</div>

<div class="card">
  <h2><span class="live"></span>Live market feed</h2>
  <div class="tablewrap"><table class="rt">
    <tr><th>Asset</th><th>Source</th><th>Symbol</th><th class="right">Price</th><th class="right">Quote age</th><th>Status</th></tr>
    <?php foreach ($feedAssets as $a): $qd = $feed[(int)$a['id']] ?? null; ?>
      <tr><td><b><?= e($a['symbol']) ?></b></td><td><?= e(PROVIDERS[$a['provider']] ?? $a['provider']) ?></td><td class="mono"><?= e($a['source_symbol']) ?></td>
        <td class="right mono"><?= $qd ? e(number_format($qd['price'], (int)$a['decimals'])) : '—' ?></td>
        <td class="right"><?= $qd ? e($qd['age'] < 120 ? $qd['age'] . 's' : round($qd['age'] / 60) . ' min') : '—' ?></td>
        <td><?= !$qd ? '<span class="badge fail">no data</span>' : ($qd['closed'] ? '<span class="badge pending">market closed</span>' : '<span class="badge ok">live</span>') ?></td></tr>
    <?php endforeach; if (!$feedAssets): ?><tr><td colspan="6" class="muted">No active assets. <a href="assets.php">Add one</a>.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="grid g2">
  <div class="card"><h2>Latest trades</h2><div class="tablewrap"><table class="rt">
    <tr><th>#</th><th>User</th><th>Asset</th><th>Acc</th><th class="right">Stake</th><th>Status</th></tr>
    <?php foreach ($recent as $t): ?>
      <tr><td><?= (int)$t['id'] ?></td><td><a href="user.php?id=<?= (int)$t['user_id'] ?>"><?= e($t['email']) ?></a></td><td><?= e($t['symbol']) ?> <?= $t['direction'] === 'up' ? '<span class="green">▲</span>' : '<span class="red">▼</span>' ?></td>
        <td><?= e(acc_meta($t['account_type'])['short']) ?></td><td class="right"><?= e(money($t['amount'], $t['account_type'])) ?></td>
        <td><span class="badge <?= e($t['status']) ?>"><?= e($t['status']) ?></span></td></tr>
    <?php endforeach; if (!$recent): ?><tr><td colspan="6" class="muted">No trades yet.</td></tr><?php endif; ?>
  </table></div></div>
  <div class="card"><h2>Admin activity</h2><div class="tablewrap"><table class="rt">
    <tr><th>When</th><th>Admin</th><th>Action</th><th>Detail</th></tr>
    <?php foreach ($logs as $l): ?>
      <tr><td><?= e(fmt_dt($l['created_at'])) ?></td><td><?= e($l['name'] ?? '—') ?></td><td><?= e($l['action']) ?></td><td class="muted"><?= e($l['detail']) ?></td></tr>
    <?php endforeach; ?>
  </table></div></div>
</div>
<?php charts_assets(); admin_end();
