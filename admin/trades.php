<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

admin_start('Trades', 'trades.php');
settle_due();

$status = in_array($_GET['status'] ?? '', ['open', 'won', 'lost', 'draw'], true) ? $_GET['status'] : '';
$acc    = in_array($_GET['acc'] ?? '', ACCOUNTS, true) ? $_GET['acc'] : '';
$uid    = (int)($_GET['user'] ?? 0);
$asset  = (int)($_GET['asset'] ?? 0);
$page   = max(1, (int)($_GET['p'] ?? 1));
$per    = 40;

$w = [];
$args = [];
if ($status) { $w[] = 't.status = ?'; $args[] = $status; }
if ($acc)    { $w[] = 't.account_type = ?'; $args[] = $acc; }
if ($uid)    { $w[] = 't.user_id = ?'; $args[] = $uid; }
if ($asset)  { $w[] = 't.asset_id = ?'; $args[] = $asset; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$total = (int)q("SELECT COUNT(*) FROM trades t $where", $args)->fetchColumn();
$sum = q("SELECT COALESCE(SUM(t.amount),0) vol, COALESCE(SUM(CASE WHEN t.status = 'open' THEN 0 ELSE -t.profit END),0) house FROM trades t $where", $args)->fetch();
$rows = q("SELECT t.*, a.symbol, a.decimals, u.email FROM trades t JOIN assets a ON a.id = t.asset_id JOIN users u ON u.id = t.user_id
           $where ORDER BY t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args)->fetchAll();
$assetList = q('SELECT id, symbol FROM assets ORDER BY sort_order')->fetchAll();
?>
<h1>Trades <a class="btn sm ghost" style="vertical-align:middle;margin-left:8px" href="export.php?type=trades">⬇ CSV</a></h1>
<form method="get" class="card row" style="align-items:flex-end">
  <div class="field"><label>Status</label><select name="status"><option value="">All</option><?php foreach (['open', 'won', 'lost', 'draw'] as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Account</label><select name="acc"><option value="">All</option><?php foreach (ACCOUNTS as $a): ?><option value="<?= $a ?>" <?= $acc === $a ? 'selected' : '' ?>><?= e(acc_meta($a)['short']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Asset</label><select name="asset"><option value="0">All</option><?php foreach ($assetList as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $asset === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['symbol']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>User ID</label><input type="number" name="user" value="<?= $uid ?: '' ?>"></div>
  <div class="field" style="flex:0"><button class="btn" type="submit">Filter</button></div>
</form>
<p class="muted"><?= $total ?> trades · volume <?= e(number_format((float)$sum['vol'], 2)) ?> · house P&amp;L (settled) <b class="<?= (float)$sum['house'] >= 0 ? 'green' : 'red' ?>"><?= e(number_format((float)$sum['house'], 2)) ?></b> <span style="font-size:12px">(mixed currencies unless you filter by account)</span></p>
<div class="card"><div class="tablewrap"><table class="rt">
  <tr><th>#</th><th>Opened</th><th>User</th><th>Asset</th><th>Acc</th><th>Side</th><th class="right">Stake</th><th>Payout</th><th>Entry</th><th>Exit</th><th>Expiry</th><th>Status</th><th class="right">Profit</th></tr>
  <?php foreach ($rows as $t): $d = (int)$t['decimals']; ?>
    <tr><td><?= (int)$t['id'] ?></td><td><?= e(fmt_ts((int)$t['open_ts'])) ?></td><td><a href="user.php?id=<?= (int)$t['user_id'] ?>"><?= e($t['email']) ?></a></td>
      <td><b><?= e($t['symbol']) ?></b></td><td><?= e(acc_meta($t['account_type'])['short']) ?></td>
      <td class="<?= $t['direction'] === 'up' ? 'green' : 'red' ?>"><?= $t['direction'] === 'up' ? '▲ UP' : '▼ DOWN' ?></td>
      <td class="right"><?= e(money($t['amount'], $t['account_type'])) ?></td><td><?= e(rtrim(rtrim($t['payout_percent'], '0'), '.')) ?>%</td>
      <td class="mono"><?= e(number_format((float)$t['open_price'], $d)) ?></td>
      <td class="mono"><?= $t['close_price'] !== null ? e(number_format((float)$t['close_price'], $d)) : '—' ?></td>
      <td><?= e(fmt_ts((int)$t['expiry_ts'])) ?></td>
      <td><span class="badge <?= e($t['status']) ?>"<?= $t['settle_note'] ? ' title="' . e($t['settle_note']) . '"' : '' ?>><?= e($t['status']) ?></span></td>
      <td class="right"><?= $t['status'] === 'open' ? '—' : e(money($t['profit'], $t['account_type'], true)) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="13" class="muted">No trades.</td></tr><?php endif; ?>
</table></div>
<?php pager($total, $page, $per, http_build_query(['status' => $status, 'acc' => $acc, 'user' => $uid, 'asset' => $asset]) . '&'); ?></div>
<?php admin_end();
