<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = require_user();
$uid = (int)$u['id'];

// ---- e-mail my statement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (($_POST['action'] ?? '') === 'report') {
        try {
            $acc = in_array($_POST['acc'] ?? 'all', array_merge(['all'], ACCOUNTS), true) ? $_POST['acc'] : 'all';
            $tz = new DateTimeZone(display_tz());
            $period = (string)($_POST['period'] ?? '30');
            if ($period === 'custom') {
                $f = DateTime::createFromFormat('Y-m-d H:i:s', trim((string)($_POST['from'] ?? '')) . ' 00:00:00', $tz);
                $t = DateTime::createFromFormat('Y-m-d H:i:s', trim((string)($_POST['to'] ?? '')) . ' 23:59:59', $tz);
                if (!$f || !$t) throw new RuntimeException('Choose both dates.');
                $from = $f->getTimestamp();
                $to = min($t->getTimestamp(), time());
            } elseif ($period === 'month') {
                $from = (new DateTime('first day of this month 00:00:00', $tz))->getTimestamp();
                $to = time();
            } elseif ($period === 'today') {
                $from = (new DateTime('today', $tz))->getTimestamp();
                $to = time();
            } else {
                $days = in_array((int)$period, [7, 30, 90, 365], true) ? (int)$period : 30;
                $from = time() - $days * 86400;
                $to = time();
            }
            [$ok, $msg] = send_user_report($u, $from, $to, $acc);
            flash($ok ? 'ok' : 'err', $msg);
        } catch (RuntimeException $ex) {
            flash('err', $ex->getMessage());
        }
    }
    redirect('history.php?v=report');
}

$u = user_page_start('History', 'history.php');
settle_due($uid);

$view = in_array($_GET['v'] ?? 'pnl', ['pnl', 'trades', 'ledger', 'report'], true) ? ($_GET['v'] ?? 'pnl') : 'pnl';
$accF = in_array($_GET['acc'] ?? '', accounts_on(), true) ? $_GET['acc'] : '';
$page = max(1, (int)($_GET['p'] ?? 1));
$per  = 25;
$off  = ($page - 1) * $per;
$accSql = $accF ? ' AND account_type = ' . db()->quote($accF) : '';
?>
<h1>History</h1>
<div class="tabs">
  <a href="?v=pnl" class="<?= $view === 'pnl' ? 'on' : '' ?>">Profit &amp; loss</a>
  <a href="?v=trades" class="<?= $view === 'trades' ? 'on' : '' ?>">Trades</a>
  <a href="?v=ledger" class="<?= $view === 'ledger' ? 'on' : '' ?>">Transactions</a>
  <a href="?v=report" class="<?= $view === 'report' ? 'on' : '' ?>">Email report</a>
  <?php if (in_array($view, ['trades', 'ledger'], true)): ?>
  <span class="sp"></span>
  <a href="?v=<?= e($view) ?>" class="<?= !$accF ? 'on' : '' ?>">All</a>
  <?php foreach (accounts_on() as $a): ?><a href="?v=<?= e($view) ?>&acc=<?= e($a) ?>" class="<?= $accF === $a ? 'on' : '' ?>"><?= e(acc_meta($a)['short']) ?></a><?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($view === 'pnl'):
    $cur = $accF ?: $u['active_account'];
    $S = pnl_summary($uid)[$cur];
    $w = wallets_of($uid);
    $closed = $S['won'] + $S['lost'];
    $winRate = $closed ? round(100 * $S['won'] / $closed) : 0;
    $flow = ['deposit' => 0.0, 'withdraw' => 0.0];
    if ($cur !== 'demo') {
        foreach (q("SELECT type, SUM(amount) s FROM payment_requests WHERE user_id = ? AND account_type = ? AND status = 'approved' GROUP BY type", [$uid, $cur])->fetchAll() as $r) $flow[$r['type']] = (float)$r['s'];
    }
    $byAsset = pnl_by_asset($uid, $cur);
    $open = (int)q("SELECT COUNT(*) FROM trades WHERE user_id = ? AND account_type = ? AND status = 'open'", [$uid, $cur])->fetchColumn();
    $net = $S['all'];
?>
  <div class="tabs">
    <?php foreach (accounts_on() as $a): ?><a href="?v=pnl&acc=<?= e($a) ?>" class="<?= $cur === $a ? 'on' : '' ?>"><?= e(acc_meta($a)['label']) ?></a><?php endforeach; ?>
  </div>

  <div class="card">
    <div class="pnlhero">
      <div>
        <div class="muted">Your trading result on the <?= e(acc_meta($cur)['short']) ?> account</div>
        <div class="big <?= pnl_class($net) ?>"><?= e(money($net, $cur, true)) ?></div>
        <div class="<?= pnl_class($net) ?>" style="font-weight:700">
          <?= $S['n'] === 0 ? 'No closed trades yet' : ($net > 0 ? '▲ You are in PROFIT' : ($net < 0 ? '▼ You are in LOSS' : 'You are break-even')) ?>
        </div>
      </div>
      <div style="text-align:right">
        <div class="muted">Balance now</div>
        <div style="font-size:24px;font-weight:800"><?= e(money($w[$cur], $cur)) ?></div>
        <?php if ($open): ?><div class="muted"><?= $open ?> trade<?= $open === 1 ? '' : 's' ?> still open</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="pnlgrid" style="margin-bottom:18px">
    <div class="pnlbox"><div class="l">Today</div><div class="v <?= pnl_class($S['today']) ?>"><?= e(money($S['today'], $cur, true)) ?></div></div>
    <div class="pnlbox"><div class="l">Last 7 days</div><div class="v <?= pnl_class($S['d7']) ?>"><?= e(money($S['d7'], $cur, true)) ?></div></div>
    <div class="pnlbox"><div class="l">Last 30 days</div><div class="v <?= pnl_class($S['d30']) ?>"><?= e(money($S['d30'], $cur, true)) ?></div></div>
    <div class="pnlbox"><div class="l">All time</div><div class="v <?= pnl_class($S['all']) ?>"><?= e(money($S['all'], $cur, true)) ?></div></div>
  </div>

  <div class="grid g2">
    <div class="card"><h2>Summary</h2>
      <dl class="kv">
        <dt>Closed trades</dt><dd><?= $S['n'] ?></dd><span></span>
        <dt>Won</dt><dd class="green"><?= $S['won'] ?></dd><span></span>
        <dt>Lost</dt><dd class="red"><?= $S['lost'] ?></dd><span></span>
        <dt>Draw / refunded</dt><dd><?= $S['draw'] ?></dd><span></span>
        <dt>Win rate</dt><dd><?= $winRate ?>%</dd><span></span>
        <dt>Total staked</dt><dd><?= e(money($S['staked'], $cur)) ?></dd><span></span>
      </dl>
    </div>
    <?php if ($cur !== 'demo'): ?>
    <div class="card"><h2>Money in &amp; out</h2>
      <dl class="kv">
        <dt>Deposited</dt><dd><?= e(money($flow['deposit'], $cur)) ?></dd><span></span>
        <dt>Withdrawn</dt><dd><?= e(money($flow['withdraw'], $cur)) ?></dd><span></span>
        <dt>Balance now</dt><dd><?= e(money($w[$cur], $cur)) ?></dd><span></span>
        <dt>Overall result</dt><dd class="<?= pnl_class($w[$cur] + $flow['withdraw'] - $flow['deposit']) ?>"><?= e(money($w[$cur] + $flow['withdraw'] - $flow['deposit'], $cur, true)) ?></dd><span></span>
      </dl>
      <p class="muted" style="font-size:12px;margin-bottom:0">Overall result = balance + withdrawn − deposited (approved requests only).</p>
    </div>
    <?php else: ?>
    <div class="card"><h2>Demo account</h2><p class="muted">Demo funds are virtual – they cannot be withdrawn. You can reset the demo balance anytime from the account menu.</p></div>
    <?php endif; ?>
  </div>

  <div class="card"><h2>Result by market</h2><div class="tablewrap"><table class="rt">
    <tr><th>Market</th><th class="right">Trades</th><th class="right">Won / Lost</th><th class="right">Staked</th><th class="right">Profit / loss</th></tr>
    <?php foreach ($byAsset as $r): ?>
      <tr><td><b><?= e($r['symbol']) ?></b></td><td class="right"><?= (int)$r['n'] ?></td>
        <td class="right"><span class="green"><?= (int)$r['won'] ?></span> / <span class="red"><?= (int)$r['lost'] ?></span></td>
        <td class="right"><?= e(money($r['staked'], $cur)) ?></td>
        <td class="right <?= pnl_class($r['pnl']) ?>"><b><?= e(money($r['pnl'], $cur, true)) ?></b></td></tr>
    <?php endforeach; if (!$byAsset): ?><tr><td colspan="5" class="muted">No closed trades on this account yet.</td></tr><?php endif; ?>
  </table></div></div>

<?php elseif ($view === 'report'): ?>
  <div class="card" style="max-width:640px">
    <h2>Email me my report</h2>
    <p class="muted" style="margin-top:-6px">We send a statement to <b><?= e($u['email']) ?></b> with your profit &amp; loss summary, your latest trades, and full CSV files of all trades and transactions.</p>
    <?php if (!mail_ready()): ?>
      <div class="flash info">Email is not set up on this site yet, so reports are unavailable. Please contact support.</div>
    <?php elseif (setting('reports_enabled', '1') !== '1'): ?>
      <div class="flash info">Reports are currently switched off by the administrator.</div>
    <?php else: ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="report">
      <div class="row">
        <div class="field"><label>Period</label>
          <select name="period" id="rPeriod">
            <option value="today">Today</option><option value="7">Last 7 days</option><option value="30" selected>Last 30 days</option>
            <option value="90">Last 90 days</option><option value="365">Last 12 months</option><option value="month">This month</option><option value="custom">Custom dates…</option>
          </select></div>
        <div class="field"><label>Account</label>
          <select name="acc"><option value="all">All accounts</option><?php foreach (accounts_on() as $a): ?><option value="<?= e($a) ?>"><?= e(acc_meta($a)['label']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="row" id="rCustom" style="display:none">
        <div class="field"><label>From</label><input type="date" name="from" max="<?= e(gmdate('Y-m-d')) ?>"></div>
        <div class="field"><label>To</label><input type="date" name="to" max="<?= e(gmdate('Y-m-d')) ?>"></div>
      </div>
      <button class="btn green" type="submit">Send report to my email</button>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Dates use <?= e(tz_short()) ?>. You can request up to 3 reports per hour.</p>
    </form>
    <script>document.getElementById('rPeriod').onchange=function(){document.getElementById('rCustom').style.display=this.value==='custom'?'flex':'none'};</script>
    <?php endif; ?>
  </div>

<?php else: ?>
<div class="card"><div class="tablewrap">
<?php if ($view === 'ledger'):
    $total = (int)q("SELECT COUNT(*) FROM transactions WHERE user_id = ? $accSql", [$uid])->fetchColumn();
    $rows = q("SELECT * FROM transactions WHERE user_id = ? $accSql ORDER BY id DESC LIMIT $per OFFSET $off", [$uid])->fetchAll(); ?>
  <table class="rt"><tr><th>Date</th><th>Account</th><th>Type</th><th>Note</th><th class="right">Amount</th><th class="right">Balance</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e(acc_meta($r['account_type'])['short']) ?></td>
      <td><?= e(str_replace('_', ' ', $r['type'])) ?></td><td class="muted"><?= e($r['note']) ?></td>
      <td class="right <?= (float)$r['amount'] >= 0 ? 'green' : 'red' ?>"><?= e(money($r['amount'], $r['account_type'], true)) ?></td>
      <td class="right"><?= e(money($r['balance_after'], $r['account_type'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">Nothing here yet.</td></tr><?php endif; ?></table>
<?php else:
    $total = (int)q("SELECT COUNT(*) FROM trades WHERE user_id = ? $accSql", [$uid])->fetchColumn();
    $rows = q("SELECT t.*, a.symbol, a.decimals FROM trades t JOIN assets a ON a.id = t.asset_id WHERE t.user_id = ? $accSql ORDER BY t.id DESC LIMIT $per OFFSET $off", [$uid])->fetchAll(); ?>
  <table class="rt"><tr><th>#</th><th>Opened</th><th>Asset</th><th>Account</th><th>Side</th><th class="right">Stake</th><th>Entry</th><th>Exit</th><th>Payout</th><th>Result</th><th class="right">Profit</th></tr>
  <?php foreach ($rows as $r): $d = (int)$r['decimals']; ?>
    <tr><td><?= (int)$r['id'] ?></td><td><?= e(fmt_ts((int)$r['open_ts'])) ?></td><td><b><?= e($r['symbol']) ?></b></td>
      <td><?= e(acc_meta($r['account_type'])['short']) ?></td>
      <td class="<?= $r['direction'] === 'up' ? 'green' : 'red' ?>"><?= $r['direction'] === 'up' ? '▲ UP' : '▼ DOWN' ?></td>
      <td class="right"><?= e(money($r['amount'], $r['account_type'])) ?></td>
      <td class="mono"><?= e(number_format((float)$r['open_price'], $d)) ?></td>
      <td class="mono"><?= $r['close_price'] !== null ? e(number_format((float)$r['close_price'], $d)) : '—' ?></td>
      <td><?= e(rtrim(rtrim($r['payout_percent'], '0'), '.')) ?>%</td>
      <td><span class="badge <?= e($r['status']) ?>" <?= $r['settle_note'] ? 'title="' . e($r['settle_note']) . '"' : '' ?>><?= e($r['status']) ?></span></td>
      <td class="right <?= pnl_class($r['profit']) ?>"><?= $r['status'] === 'open' ? '—' : e(money($r['profit'], $r['account_type'], true)) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="11" class="muted">No trades yet.</td></tr><?php endif; ?></table>
<?php endif; ?>
</div>
<?php
$pages = max(1, (int)ceil($total / $per));
if ($pages > 1): ?>
  <div class="pager">
    <?php $qs = '?v=' . urlencode($view) . ($accF ? '&acc=' . $accF : '') . '&p='; ?>
    <?php if ($page > 1): ?><a class="btn ghost sm" href="<?= e($qs . ($page - 1)) ?>">‹ Prev</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn ghost sm" href="<?= e($qs . ($page + 1)) ?>">Next ›</a><?php endif; ?>
  </div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php user_page_end();
