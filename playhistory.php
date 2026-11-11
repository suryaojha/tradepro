<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = require_user();
games_gate($u);
$uid = (int)$u['id'];
$view = ($_GET['v'] ?? 'tx') === 'win' ? 'win' : 'tx';
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 15;
$off = ($page - 1) * $per;

$u = user_page_start('Play – ' . ($view === 'win' ? 'My winnings' : 'Transactions'), 'games.php');
play_nav($view === 'win' ? 'win' : 'tx');
$demo = wallets_of($uid)['demo'];
?>
<div class="gamehead">
  <div><h1 style="margin:0"><?= $view === 'win' ? 'My winnings' : 'Game transactions' ?></h1><div class="muted" style="font-size:13px">Only Colour Game and Lucky Number – your trading history is under History</div></div>
  <div class="gamewallet"><small>Demo coins</small><b><?= e(money($demo, 'demo')) ?></b></div>
</div>

<?php if ($view === 'tx'):
    $g = in_array($_GET['g'] ?? '', ['colour', 'lucky'], true) ? $_GET['g'] : '';
    $gs = $g === 'colour' ? ' AND note LIKE "Colour game%"' : ($g === 'lucky' ? ' AND note NOT LIKE "Colour game%"' : '');
    $total = (int)q("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type IN ('game_bet','game_win') $gs", [$uid])->fetchColumn();
    $rows = q("SELECT * FROM transactions WHERE user_id = ? AND type IN ('game_bet','game_win') $gs ORDER BY id DESC LIMIT $per OFFSET $off", [$uid])->fetchAll();
    $pages = max(1, (int)ceil($total / $per));
?>
  <div class="tabs">
    <a href="?v=tx" class="<?= $g === '' ? 'on' : '' ?>">All games</a>
    <a href="?v=tx&g=colour" class="<?= $g === 'colour' ? 'on' : '' ?>">Colour Game</a>
    <a href="?v=tx&g=lucky" class="<?= $g === 'lucky' ? 'on' : '' ?>">Lucky Number</a>
  </div>
  <div class="card"><div class="tablewrap"><table class="rt">
    <tr><th>Date</th><th>Game</th><th>Type</th><th>Details</th><th class="right">Amount</th><th class="right">Balance</th></tr>
    <?php foreach ($rows as $r): $col = str_starts_with((string)$r['note'], 'Colour game'); ?>
      <tr><td><?= e(fmt_dt($r['created_at'])) ?></td><td><?= $col ? '🎨 Colour' : '🔢 Lucky' ?></td>
        <td><span class="badge <?= $r['type'] === 'game_win' ? 'won' : 'open' ?>"><?= $r['type'] === 'game_win' ? 'win' : 'bet' ?></span></td>
        <td class="muted"><?= e($r['note']) ?></td>
        <td class="right <?= (float)$r['amount'] >= 0 ? 'green' : 'red' ?>"><?= e(money($r['amount'], 'demo', true)) ?></td>
        <td class="right"><?= e(money($r['balance_after'], 'demo')) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">No game transactions yet.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($pages > 1): $qs = '?v=tx' . ($g ? '&g=' . $g : '') . '&p='; ?>
    <div class="pager"><?php if ($page > 1): ?><a class="btn ghost sm" href="<?= e($qs . ($page - 1)) ?>">‹ Prev</a><?php endif; ?><span class="muted">Page <?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a class="btn ghost sm" href="<?= e($qs . ($page + 1)) ?>">Next ›</a><?php endif; ?></div>
  <?php endif; ?></div>

<?php else:
    $cg = q('SELECT COUNT(*) n, COALESCE(SUM(status="won"),0) w, COALESCE(SUM(amount),0) staked, COALESCE(SUM(payout),0) ret FROM cg_bets WHERE user_id = ? AND status <> "open"', [$uid])->fetch();
    $mk = q('SELECT COUNT(*) n, COALESCE(SUM(status="won"),0) w, COALESCE(SUM(amount),0) staked, COALESCE(SUM(payout),0) ret FROM mk_bets WHERE user_id = ? AND status <> "open"', [$uid])->fetch();
    $net = fn($x) => (float)$x['ret'] - (float)$x['staked'];
    $tot = ['n' => $cg['n'] + $mk['n'], 'w' => $cg['w'] + $mk['w'], 'staked' => $cg['staked'] + $mk['staked'], 'ret' => $cg['ret'] + $mk['ret']];
    $union = 'SELECT "🎨 Colour" g, b.created_at, CONCAT(r.period_no, " · ", b.pick) d, b.amount, b.payout FROM cg_bets b JOIN cg_rounds r ON r.id = b.round_id WHERE b.user_id = ? AND b.status = "won"
              UNION ALL SELECT "🔢 Lucky", b.created_at, CONCAT(m.name, " · ", b.game, " ", b.pick), b.amount, b.payout FROM mk_bets b JOIN mk_markets m ON m.id = b.market_id WHERE b.user_id = ? AND b.status = "won"';
    $total = (int)q("SELECT COUNT(*) FROM ($union) x", [$uid, $uid])->fetchColumn();
    $rows = q("$union ORDER BY created_at DESC LIMIT $per OFFSET $off", [$uid, $uid])->fetchAll();
    $pages = max(1, (int)ceil($total / $per));
?>
  <div class="card">
    <div class="pnlhero">
      <div><div class="muted">Net result in the games</div>
        <div class="big <?= pnl_class($net($tot)) ?>"><?= e(money($net($tot), 'demo', true)) ?></div>
        <div class="<?= pnl_class($net($tot)) ?>" style="font-weight:700"><?= $tot['n'] == 0 ? 'No finished bets yet' : ($net($tot) > 0 ? '▲ You are ahead' : ($net($tot) < 0 ? '▼ You are behind' : 'Break-even')) ?></div></div>
      <div style="text-align:right"><div class="muted">Bets won</div><div style="font-size:24px;font-weight:800"><?= (int)$tot['w'] ?> / <?= (int)$tot['n'] ?></div></div>
    </div>
  </div>
  <div class="grid g2" style="margin-bottom:18px">
    <?php foreach ([['🎨 Colour Game', $cg], ['🔢 Lucky Number', $mk]] as [$title, $x]): ?>
      <div class="card" style="margin:0"><h2><?= $title ?></h2>
        <dl class="kv">
          <dt>Finished bets</dt><dd><?= (int)$x['n'] ?></dd><span></span>
          <dt>Won</dt><dd class="green"><?= (int)$x['w'] ?></dd><span></span>
          <dt>Staked</dt><dd><?= e(money($x['staked'], 'demo')) ?></dd><span></span>
          <dt>Returned</dt><dd><?= e(money($x['ret'], 'demo')) ?></dd><span></span>
          <dt>Net</dt><dd class="<?= pnl_class($net($x)) ?>"><?= e(money($net($x), 'demo', true)) ?></dd><span></span>
        </dl></div>
    <?php endforeach; ?>
  </div>
  <div class="card"><h2>Winning bets</h2><div class="tablewrap"><table class="rt">
    <tr><th>Date</th><th>Game</th><th>Details</th><th class="right">Stake</th><th class="right">Returned</th><th class="right">Profit</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e($r['g']) ?></td><td class="muted mono"><?= e($r['d']) ?></td>
        <td class="right"><?= e(money($r['amount'], 'demo')) ?></td><td class="right"><?= e(money($r['payout'], 'demo')) ?></td>
        <td class="right green"><b><?= e(money((float)$r['payout'] - (float)$r['amount'], 'demo', true)) ?></b></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">No winning bets yet.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($pages > 1): ?>
    <div class="pager"><?php if ($page > 1): ?><a class="btn ghost sm" href="?v=win&p=<?= $page - 1 ?>">‹ Prev</a><?php endif; ?><span class="muted">Page <?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a class="btn ghost sm" href="?v=win&p=<?= $page + 1 ?>">Next ›</a><?php endif; ?></div>
  <?php endif; ?></div>
<?php endif; ?>
<?php user_page_end();
