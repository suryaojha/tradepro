<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

admin_start('Users', 'users.php');

$search = trim($_GET['s'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 30;
$where = '';
$args = [];
if ($search !== '') {
    $where = 'WHERE u.email LIKE ? OR u.name LIKE ? OR u.phone LIKE ? OR u.id = ?';
    $like = '%' . $search . '%';
    $args = [$like, $like, $like, (int)$search];
}
$total = (int)q("SELECT COUNT(*) FROM users u $where", $args)->fetchColumn();
$rows = q("SELECT u.*,
            (SELECT balance FROM wallets WHERE user_id = u.id AND type = 'demo') bd,
            (SELECT balance FROM wallets WHERE user_id = u.id AND type = 'inr')  bi,
            (SELECT balance FROM wallets WHERE user_id = u.id AND type = 'usdt') bu,
            (SELECT COUNT(*) FROM trades WHERE user_id = u.id) tc
          FROM users u $where ORDER BY u.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args)->fetchAll();
?>
<h1>Users <span class="muted" style="font-size:15px;font-weight:500"><?= $total ?> total</span> <a class="btn sm ghost" style="vertical-align:middle;margin-left:8px" href="export.php?type=users">⬇ CSV</a></h1>

<div class="grid g2" style="margin-bottom:18px">
  <?= chart_card('Sign-ups', 'signups', url('admin/stats.php')) ?>
  <div class="card" style="margin:0">
    <h2>Find a user</h2>
    <form method="get" class="inline" style="flex-wrap:nowrap">
      <input type="text" name="s" value="<?= e($search) ?>" placeholder="Name, email, phone or ID">
      <button class="btn" type="submit">Search</button>
    </form>
    <?php if ($search !== ''): ?><p style="margin:10px 0 0"><a href="users.php">Clear search</a></p><?php endif; ?>
    <p class="muted" style="font-size:12px;margin:14px 0 0">Open a user to see their wallets, trades, ledger and charts, adjust balances, reset their password or block them.</p>
  </div>
</div>

<div class="card"><div class="tablewrap"><table class="rt">
  <tr><th>ID</th><th>Name</th><th>Email</th><th class="right">Demo</th><th class="right">INR</th><th class="right">USDT</th><th class="right">Trades</th><th>Joined</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= (int)$r['id'] ?></td><td><?= e($r['name']) ?></td><td><?= e($r['email']) ?><?= $r['email_verified_at'] ? '' : ' <span class="badge pending" title="Email not confirmed">unverified</span>' ?></td>
      <td class="right"><?= e(money($r['bd'], 'demo')) ?></td><td class="right"><?= e(money($r['bi'], 'inr')) ?></td><td class="right"><?= e(money($r['bu'], 'usdt')) ?></td>
      <td class="right"><?= (int)$r['tc'] ?></td><td><?= e(fmt_dt($r['created_at'])) ?></td>
      <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
      <td><a class="btn sm ghost" href="user.php?id=<?= (int)$r['id'] ?>">Manage</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="10" class="muted">No users found.</td></tr><?php endif; ?>
</table></div>
<?php pager($total, $page, $per, 's=' . urlencode($search) . '&'); ?></div>
<?php charts_assets(); admin_end();
