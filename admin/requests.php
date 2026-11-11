<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $id = (int)($_POST['id'] ?? 0);
    $approve = ($_POST['decision'] ?? '') === 'approve';
    $credit = isset($_POST['credit']) && $_POST['credit'] !== '' ? (float)$_POST['credit'] : null;
    try {
        process_request($id, $approve, trim($_POST['note'] ?? ''), $credit);
        admin_log('request_' . ($approve ? 'approve' : 'reject'), "#$id");
        flash('ok', "Request #$id " . ($approve ? 'approved.' : 'rejected.'));
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/requests.php?' . http_build_query(array_filter(['st' => $_POST['st'] ?? '', 'type' => $_POST['type'] ?? ''])));
}

admin_start('Requests', 'requests.php');
$st = in_array($_GET['st'] ?? 'pending', ['pending', 'approved', 'rejected', 'all'], true) ? ($_GET['st'] ?? 'pending') : 'pending';
$type = in_array($_GET['type'] ?? '', ['deposit', 'withdraw'], true) ? $_GET['type'] : '';
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 30;
$w = [];
$args = [];
if ($st !== 'all') { $w[] = 'r.status = ?'; $args[] = $st; }
if ($type) { $w[] = 'r.type = ?'; $args[] = $type; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$total = (int)q("SELECT COUNT(*) FROM payment_requests r $where", $args)->fetchColumn();
$rows = q("SELECT r.*, u.email, u.name FROM payment_requests r JOIN users u ON u.id = r.user_id $where
           ORDER BY (r.status = 'pending') DESC, r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args)->fetchAll();
?>
<h1>Deposits &amp; Withdrawals <a class="btn sm ghost" style="vertical-align:middle;margin-left:8px" href="export.php?type=requests">⬇ CSV</a></h1>
<div class="tabs">
  <?php foreach (['pending', 'approved', 'rejected', 'all'] as $s): ?>
    <a href="?st=<?= $s ?>&type=<?= e($type) ?>" class="<?= $st === $s ? 'on' : '' ?>"><?= ucfirst($s) ?></a>
  <?php endforeach; ?>
  <span class="sp"></span>
  <a href="?st=<?= e($st) ?>" class="<?= !$type ? 'on' : '' ?>">Both</a>
  <a href="?st=<?= e($st) ?>&type=deposit" class="<?= $type === 'deposit' ? 'on' : '' ?>">Deposits</a>
  <a href="?st=<?= e($st) ?>&type=withdraw" class="<?= $type === 'withdraw' ? 'on' : '' ?>">Withdrawals</a>
</div>

<div class="card"><div class="tablewrap"><table class="rt">
  <tr><th>#</th><th>Date</th><th>User</th><th>Type</th><th>Acc</th><th class="right">Amount</th><th>Method</th><th>Reference / Destination</th><th>Status</th><th>Action</th></tr>
  <?php foreach ($rows as $r): $acc = $r['account_type']; ?>
    <tr><td><?= (int)$r['id'] ?></td><td><?= e(fmt_dt($r['created_at'])) ?></td>
      <td><a href="user.php?id=<?= (int)$r['user_id'] ?>"><?= e($r['email']) ?></a></td>
      <td><b class="<?= $r['type'] === 'deposit' ? 'green' : 'warn' ?>"><?= e(ucfirst($r['type'])) ?></b></td>
      <td><?= e(strtoupper($acc)) ?></td>
      <td class="right"><?= e(money($r['amount'], $acc)) ?><?php if ((float)$r['fee'] > 0): ?><div class="muted" style="font-size:12px">fee <?= e(money($r['fee'], $acc)) ?> → pay <?= e(money((float)$r['amount'] - (float)$r['fee'], $acc)) ?></div><?php endif; ?></td>
      <td><?= e(strtoupper($r['method'])) ?></td>
      <td style="white-space:normal;max-width:280px;word-break:break-all"><?= e($r['details']) ?></td>
      <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span><?php if ($r['admin_note']): ?><div class="muted" style="font-size:12px"><?= e($r['admin_note']) ?></div><?php endif; ?></td>
      <td class="rt-block"><?php if ($r['status'] === 'pending'): ?>
        <form method="post" class="inline" style="flex-wrap:wrap">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="st" value="<?= e($st) ?>"><input type="hidden" name="type" value="<?= e($type) ?>">
          <?php if ($r['type'] === 'deposit'): ?><input class="inp-sm" type="number" name="credit" step="0.01" placeholder="Credit" title="Amount to credit (defaults to requested)"><?php endif; ?>
          <input class="inp-md" type="text" name="note" placeholder="Note (optional)" maxlength="200">
          <button class="btn sm green" name="decision" value="approve" type="submit" data-confirm="Approve request #<?= (int)$r['id'] ?>? <?= $r['type'] === 'deposit' ? 'The amount will be credited to the user.' : 'Only approve after you have paid the user.' ?>">Approve</button>
          <button class="btn sm red" name="decision" value="reject" type="submit" data-confirm="Reject request #<?= (int)$r['id'] ?>? A withdrawal will be refunded to the user.">Reject</button>
        </form>
      <?php else: ?><span class="muted"><?= e(fmt_dt($r['processed_at'])) ?></span><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="10" class="muted">Nothing here.</td></tr><?php endif; ?>
</table></div>
<?php pager($total, $page, $per, 'st=' . urlencode($st) . '&type=' . urlencode($type) . '&'); ?></div>
<p class="muted" style="font-size:12px">Deposit approved → amount is credited to the user's account. Withdrawal: the amount was already held when requested; approving means you have paid it out manually (amount minus fee); rejecting refunds it.</p>
<?php admin_end();
