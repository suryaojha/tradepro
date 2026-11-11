<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

require_admin();
$id = (int)($_GET['id'] ?? 0);
$user = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
if (!$user) {
    flash('err', 'User not found.');
    redirect('admin/users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'status') {
            $new = $user['status'] === 'active' ? 'blocked' : 'active';
            q('UPDATE users SET status = ? WHERE id = ?', [$new, $id]);
            admin_log('user_' . $new, "user #$id");
            flash('ok', "User is now $new.");
        } elseif ($action === 'adjust') {
            $acc = $_POST['account'] ?? '';
            $amt = round((float)($_POST['amount'] ?? 0), 2);
            $note = trim($_POST['note'] ?? '') ?: 'Admin adjustment';
            if (!in_array($acc, ACCOUNTS, true) || $amt == 0.0) throw new RuntimeException('Choose an account and a non-zero amount (use a minus sign to deduct).');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $bal = wallet_adjust($id, $acc, $amt, 'admin_adjust', null, substr($note, 0, 200));
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                throw $ex;
            }
            admin_log('balance_adjust', "user #$id $acc " . dec($amt) . " ($note)");
            flash('ok', 'Balance updated. New ' . strtoupper($acc) . ' balance: ' . money($bal, $acc));
        } elseif ($action === 'password') {
            $pw = (string)($_POST['password'] ?? '');
            if ($e = password_error($pw)) throw new RuntimeException($e);
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            admin_log('user_password_reset', "user #$id");
            $msg = 'Password reset.';
            if (!empty($_POST['mailit']) && mail_ready()) {
                [$ok, $er] = send_mail($user['email'], 'Your password was changed',
                    mail_html('Your password was changed', '<p>Hi ' . e($user['name']) . ',</p><p>An administrator set a new password for your account:</p><p style="font-size:18px"><b>' . e($pw) . '</b></p><p>Please log in and change it from your profile.</p>', 'Log in', abs_url('login.php')));
                $msg .= $ok ? ' The new password was emailed to the user.' : ' (Email failed: ' . $er . ')';
            }
            flash('ok', $msg);
        } elseif ($action === 'sendreset') {
            if (!mail_ready()) throw new RuntimeException('Email (SMTP) is not configured.');
            send_reset_email('user', $id, $user['email'], $user['name']);
            admin_log('user_reset_link', "user #$id");
            flash('ok', 'Reset link emailed to ' . $user['email'] . '. Check Settings → Email log if it does not arrive.');
        } elseif ($action === 'resetpin') {
            pin_clear($id);
            admin_log('user_pin_reset', "user #$id");
            flash('ok', 'PIN removed. The user must set a new one before withdrawing.');
        } elseif ($action === 'verify') {
            q('UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL', [$id]);
            admin_log('user_verified', "user #$id");
            flash('ok', 'Email marked as verified.');
        }
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/user.php?id=' . $id);
}

settle_due($id);
admin_start('User #' . $id, 'users.php');
$user = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
$w = wallets_of($id);
$trades = q('SELECT t.*, a.symbol FROM trades t JOIN assets a ON a.id = t.asset_id WHERE t.user_id = ? ORDER BY t.id DESC LIMIT 25', [$id])->fetchAll();
$tx = q('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 25', [$id])->fetchAll();
$src = url('admin/stats.php');
$cur = $user['active_account'];
?>
<h1>User #<?= $id ?> – <?= e($user['name']) ?> <span class="badge <?= e($user['status']) ?>"><?= e($user['status']) ?></span></h1>
<p class="muted"><?= e($user['email']) ?> <?= $user['email_verified_at'] ? '<span class="badge ok">verified</span>' : '<span class="badge pending">unverified</span>' ?> · <?= e($user['phone'] ?: 'no phone') ?> · joined <?= e(fmt_dt($user['created_at'])) ?> · last login <?= e(fmt_dt($user['last_login'])) ?></p>

<div class="grid g3" style="margin-bottom:18px">
  <?php foreach (ACCOUNTS as $a): ?>
    <div class="stat"><div class="l"><span class="dot <?= e($a) ?>"></span><?= e(acc_meta($a)['label']) ?></div><div class="v"><?= e(money($w[$a], $a)) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="acctpick"><span class="lbl">Charts for</span>
  <?php foreach (ACCOUNTS as $a): ?><button type="button" class="btn sm ghost <?= $a === $cur ? 'on' : '' ?>" data-chartacc="<?= e($a) ?>"><?= e(acc_meta($a)['short']) ?></button><?php endforeach; ?>
</div>
<div class="grid g2" style="margin-bottom:18px">
  <?= chart_card('Balance history', 'equity', $src, ['acc' => $cur, 'user' => $id, 'days' => 90]) ?>
  <?= chart_card('Profit & loss', 'pnl', $src, ['acc' => $cur, 'user' => $id]) ?>
  <?= chart_card('Wins vs losses', 'results', $src, ['acc' => $cur, 'user' => $id]) ?>
  <?= chart_card('Trading volume', 'volume', $src, ['acc' => $cur, 'user' => $id]) ?>
  <?= chart_card('Predictions: up vs down', 'predictions', $src, ['acc' => $cur, 'user' => $id]) ?>
</div>

<div class="grid g3">
  <div class="card"><h2>Adjust balance</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="adjust">
      <div class="field"><label>Account</label><select name="account"><?php foreach (ACCOUNTS as $a): ?><option value="<?= e($a) ?>"><?= e(acc_meta($a)['label']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Amount (negative to deduct)</label><input type="number" name="amount" step="0.01" required inputmode="decimal"></div>
      <div class="field"><label>Note</label><input type="text" name="note" maxlength="200" placeholder="Bonus, correction, …"></div>
      <button class="btn block" type="submit">Apply</button>
    </form></div>
  <div class="card"><h2>Reset password</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="field"><label>New password</label>
        <div class="pwbox"><input type="text" name="password" id="npw" required autocomplete="off" data-meter><button type="button" class="pwtoggle" id="genpw" tabindex="-1">Generate</button></div>
        <div class="meter"><i></i></div><div class="meterhint muted"><?= e(PASSWORD_HINT) ?></div></div>
      <?php if (mail_ready()): ?><div class="field"><label class="check"><input type="checkbox" name="mailit" value="1"> <span>Email the new password to the user</span></label></div><?php endif; ?>
      <button class="btn block" type="submit">Set password</button>
    </form>
    <?php if (mail_ready()): ?>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="sendreset">
      <button class="btn ghost block" type="submit">Email a reset link instead</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="card"><h2>Account status</h2>
    <p class="muted">Blocked users cannot log in or trade.</p>
    <form method="post" data-confirm="<?= $user['status'] === 'active' ? 'Block this user?' : 'Unblock this user?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="status">
      <button class="btn block <?= $user['status'] === 'active' ? 'red' : 'green' ?>" type="submit"><?= $user['status'] === 'active' ? 'Block user' : 'Unblock user' ?></button>
    </form>
    <?php if (!empty($user['pin_hash'])): ?>
    <form method="post" style="margin-top:10px" data-confirm="Remove this user's security PIN?"><?= csrf_field() ?><input type="hidden" name="action" value="resetpin">
      <button class="btn ghost block" type="submit">Reset security PIN</button>
    </form>
    <?php endif; ?>
    <?php if (!$user['email_verified_at']): ?>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="verify">
      <button class="btn ghost block" type="submit">Mark email as verified</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card"><h2>Recent trades</h2><div class="tablewrap"><table class="rt">
  <tr><th>#</th><th>Opened</th><th>Asset</th><th>Acc</th><th>Side</th><th class="right">Stake</th><th>Payout</th><th>Status</th><th class="right">Profit</th></tr>
  <?php foreach ($trades as $t): ?>
    <tr><td><?= (int)$t['id'] ?></td><td><?= e(fmt_ts((int)$t['open_ts'])) ?></td><td><?= e($t['symbol']) ?></td><td><?= e(acc_meta($t['account_type'])['short']) ?></td>
      <td class="<?= $t['direction'] === 'up' ? 'green' : 'red' ?>"><?= $t['direction'] === 'up' ? '▲' : '▼' ?></td>
      <td class="right"><?= e(money($t['amount'], $t['account_type'])) ?></td><td><?= e(rtrim(rtrim($t['payout_percent'], '0'), '.')) ?>%</td>
      <td><span class="badge <?= e($t['status']) ?>"><?= e($t['status']) ?></span></td>
      <td class="right"><?= $t['status'] === 'open' ? '—' : e(money($t['profit'], $t['account_type'], true)) ?></td></tr>
  <?php endforeach; if (!$trades): ?><tr><td colspan="9" class="muted">No trades.</td></tr><?php endif; ?>
</table></div></div>

<div class="card"><h2>Ledger</h2><div class="tablewrap"><table class="rt">
  <tr><th>Date</th><th>Account</th><th>Type</th><th>Note</th><th class="right">Amount</th><th class="right">Balance</th></tr>
  <?php foreach ($tx as $r): ?>
    <tr><td><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e(acc_meta($r['account_type'])['short']) ?></td><td><?= e(str_replace('_', ' ', $r['type'])) ?></td><td class="muted"><?= e($r['note']) ?></td>
      <td class="right"><?= e(money($r['amount'], $r['account_type'], true)) ?></td><td class="right"><?= e(money($r['balance_after'], $r['account_type'])) ?></td></tr>
  <?php endforeach; ?>
</table></div></div>
<script>
document.getElementById('genpw').onclick = function(){
  var s = ['abcdefghijkmnopqrstuvwxyz','ABCDEFGHJKLMNPQRSTUVWXYZ','23456789','@#$%&*!?'], all = s.join(''), a = new Uint32Array(14), out = [];
  crypto.getRandomValues(a);
  s.forEach(function(x, i){ out.push(x[a[i] % x.length]); });
  for (var i = 4; i < 12; i++) out.push(all[a[i] % all.length]);
  for (var j = out.length - 1; j > 0; j--) { var k = a[(j + 2) % 14] % (j + 1); var t = out[j]; out[j] = out[k]; out[k] = t; }
  var inp = document.getElementById('npw'); inp.value = out.join(''); inp.dispatchEvent(new Event('input'));
};
</script>
<?php charts_assets(); admin_end();
