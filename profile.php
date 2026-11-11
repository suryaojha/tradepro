<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = require_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    if ($action === 'profile') {
        $name  = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) flash('err', 'Enter your name.');
        elseif (!($ph = valid_phone($phone))) flash('err', 'Enter a valid contact number (10–14 digits).');
        else {
            q('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $ph, $u['id']]);
            flash('ok', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $old = (string)($_POST['old'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        if (!throttle_ok('pw', 5, 600)) flash('err', 'Too many attempts. Try later.');
        elseif (!password_verify($old, $u['password_hash'])) { throttle_hit('pw'); flash('err', 'Current password is wrong.'); }
        elseif ($e = password_error($new)) flash('err', $e);
        elseif ($new !== ($_POST['new2'] ?? '')) flash('err', 'New passwords do not match.');
        else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            flash('ok', 'Password changed.');
        }
    } elseif (in_array($action, ['pin_set', 'pin_change', 'pin_lock', 'pin_remove'], true)) {
        $fresh = q('SELECT * FROM users WHERE id = ?', [$u['id']])->fetch();
        $new = (string)($_POST['new_pin'] ?? '');
        $new2 = (string)($_POST['new_pin2'] ?? '');
        $pw = (string)($_POST['password'] ?? '');
        if (!throttle_ok('pinset', 8, 600)) flash('err', 'Too many attempts. Try later.');
        elseif ($action === 'pin_set' || $action === 'pin_change') {
            if ($action === 'pin_set' && pin_is_set($fresh)) flash('err', 'A PIN is already set. Use "Change PIN".');
            elseif (!password_verify($pw, $fresh['password_hash'])) { throttle_hit('pinset'); flash('err', 'Account password is wrong.'); }
            elseif ($action === 'pin_change' && ($e = pin_check((int)$u['id'], (string)($_POST['old_pin'] ?? '')))) flash('err', $e);
            elseif ($e = pin_format_error($new)) flash('err', $e);
            elseif ($new !== $new2) flash('err', 'The two PINs do not match.');
            else { pin_set((int)$u['id'], $new); flash('ok', 'Your 6-digit PIN is saved. You will need it for every withdrawal.'); }
        } elseif ($action === 'pin_lock') {
            if (!pin_is_set($fresh)) flash('err', 'Set a PIN first.');
            else {
                $on = !empty($_POST['app_lock']) ? 1 : 0;
                q('UPDATE users SET app_lock = ? WHERE id = ?', [$on, $u['id']]);
                $_SESSION['pin_ok'] = 1;
                flash('ok', $on ? 'App lock is ON – your PIN is asked each time you log in.' : 'App lock is OFF.');
            }
        } elseif ($action === 'pin_remove') {
            if (!password_verify($pw, $fresh['password_hash'])) { throttle_hit('pinset'); flash('err', 'Account password is wrong.'); }
            else { pin_clear((int)$u['id']); flash('ok', 'PIN removed. Withdrawals stay blocked until you set a new one.'); }
        }
    } elseif ($action === 'payout_add' || $action === 'payout_del') {
        // saving or removing payout details is protected by the 6-digit PIN
        if (!throttle_ok('payout', 10, 600)) flash('err', 'Too many attempts. Try later.');
        elseif (!pin_is_set(q('SELECT pin_hash FROM users WHERE id = ?', [$u['id']])->fetch() ?: [])) flash('err', 'Set your security PIN first (Security PIN section below).');
        elseif ($e = pin_check((int)$u['id'], (string)($_POST['pin'] ?? ''))) { throttle_hit('payout'); flash('err', $e); }
        else {
            try {
                if ($action === 'payout_add') {
                    $kind = (string)($_POST['kind'] ?? '');
                    $data = payout_validate($kind, $_POST);
                    payout_add((int)$u['id'], $kind, trim((string)($_POST['label'] ?? '')), $data);
                    $what = 'added';
                } else {
                    $row = payout_find((int)$u['id'], (int)($_POST['id'] ?? 0));
                    if (!$row) throw new RuntimeException('Payout account not found.');
                    q('DELETE FROM payout_accounts WHERE id = ?', [$row['id']]);
                    $what = 'removed';
                }
                if (mail_ready()) {
                    send_mail($u['email'], 'Security alert: payout account ' . $what, mail_html('Payout account ' . $what,
                        '<p>Hi ' . e($u['name']) . ',</p><p>A payout account was <b>' . $what . '</b> on your account. If this was not you, change your password and PIN now.</p>'));
                }
                flash('ok', 'Payout account ' . $what . '.');
            } catch (RuntimeException $ex) {
                flash('err', $ex->getMessage());
            }
        }
    }
    redirect('profile.php' . (str_starts_with($action, 'payout') ? '#payout' : ''));
}

$u = user_page_start('Profile', 'profile.php');
$stats = q("SELECT COUNT(*) n, COALESCE(SUM(status='won'),0) w, COALESCE(SUM(status='lost'),0) l FROM trades WHERE user_id = ? AND status <> 'open'", [$u['id']])->fetch();
$rate = (int)$stats['n'] ? round(100 * $stats['w'] / max(1, $stats['w'] + $stats['l'])) : 0;
?>
<h1>Profile</h1>
<div class="grid g4 keep2" style="margin-bottom:18px">
  <div class="stat"><div class="l">Trades closed</div><div class="v"><?= (int)$stats['n'] ?></div></div>
  <div class="stat"><div class="l">Won</div><div class="v green"><?= (int)$stats['w'] ?></div></div>
  <div class="stat"><div class="l">Lost</div><div class="v red"><?= (int)$stats['l'] ?></div></div>
  <div class="stat"><div class="l">Win rate</div><div class="v"><?= $rate ?>%</div></div>
</div>
<?php $P = pnl_summary((int)$u['id']); ?>
<div class="card"><h2>My profit &amp; loss <a href="<?= e(url('history.php?v=pnl')) ?>" style="font-size:13px;font-weight:500;margin-left:8px">details →</a></h2>
  <div class="tablewrap"><table class="rt">
    <tr><th>Account</th><th class="right">Today</th><th class="right">7 days</th><th class="right">30 days</th><th class="right">All time</th></tr>
    <?php foreach (accounts_on() as $a): $s = $P[$a]; ?>
      <tr><td><span class="dot <?= e($a) ?>"></span><b><?= e(acc_meta($a)['short']) ?></b></td>
        <?php foreach (['today', 'd7', 'd30', 'all'] as $k): ?><td class="right <?= pnl_class($s[$k]) ?>"><b><?= e(money($s[$k], $a, true)) ?></b></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
  </table></div>
</div>
<div class="grid g2">
  <div class="card"><h2>Details</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <div class="field"><label>Email</label><input type="text" value="<?= e($u['email']) ?>" disabled></div>
      <div class="field"><label>Name</label><input type="text" name="name" value="<?= e($u['name']) ?>" required maxlength="100"></div>
      <div class="field"><label>Contact number</label><input type="tel" name="phone" value="<?= e($u['phone']) ?>" required placeholder="+91 98765 43210"></div>
      <div class="field"><label>Time zone for dates</label><div class="inline"><?= tz_widget() ?><span class="muted" style="font-size:12px">saved on this device</span></div></div>
      <button class="btn" type="submit">Save</button>
    </form>
  </div>
  <div class="card"><h2>Change password</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <?= password_field('old', 'Current password', false, 'current-password') ?>
      <?= password_field('new', 'New password', true) ?>
      <?= password_field('new2', 'Repeat new password') ?>
      <button class="btn" type="submit">Change password</button>
    </form>
  </div>
</div>
<?php $payouts = payout_list((int)$u['id']); $hasPinNow = pin_is_set(q('SELECT pin_hash FROM users WHERE id = ?', [$u['id']])->fetch() ?: []); ?>
<div class="card" id="payout"><h2>Payout details <span class="muted" style="font-size:13px;font-weight:500">(saved once – withdrawals then only need your PIN)</span></h2>
  <?php if (!$hasPinNow): ?><div class="flash info">Set your security PIN first (below) – it protects your saved payout details.</div><?php endif; ?>
  <?php foreach ($payouts as $p): ?>
    <div class="payrow">
      <div><b><?= e($p['label']) ?></b> <span class="badge"><?= e(PAYOUT_KINDS[$p['kind']]['label']) ?></span><div class="muted mono" style="font-size:13px"><?= e(payout_mask($p)) ?></div></div>
      <form method="post" data-askpin="Enter your PIN to remove this account" data-noconfirm><?= csrf_field() ?><input type="hidden" name="action" value="payout_del"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm red" type="submit">Remove</button></form>
    </div>
  <?php endforeach; if (!$payouts): ?><p class="muted">No payout account saved yet. Add one below to be able to withdraw.</p><?php endif; ?>

  <?php if (count($payouts) < PAYOUT_MAX && $hasPinNow): ?>
  <h3 style="margin-top:16px">Add a payout account</h3>
  <form method="post" id="payForm" style="max-width:560px"><?= csrf_field() ?><input type="hidden" name="action" value="payout_add">
    <div class="row">
      <div class="field"><label>Type</label><select name="kind" id="pKind"><?php foreach (PAYOUT_KINDS as $k => $d): ?><option value="<?= e($k) ?>"><?= e($d['label']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Nickname (optional)</label><input type="text" name="label" maxlength="60" placeholder="e.g. My HDFC account"></div>
    </div>
    <div class="pf" data-k="upi"><div class="field"><label>UPI ID</label><input type="text" name="upi" placeholder="name@bank" autocomplete="off" maxlength="80"></div></div>
    <div class="pf" data-k="bank">
      <div class="field"><label>Account holder name</label><input type="text" name="holder" placeholder="As printed on your passbook" maxlength="60"></div>
      <div class="row">
        <div class="field"><label>Account number</label><input type="text" name="acc" inputmode="numeric" autocomplete="off" maxlength="22"></div>
        <div class="field"><label>Confirm account number</label><input type="text" name="acc2" inputmode="numeric" autocomplete="off" maxlength="22"></div>
      </div>
      <div class="row">
        <div class="field"><label>IFSC code</label><input type="text" name="ifsc" id="pIfsc" placeholder="HDFC0001234" maxlength="11" autocapitalize="characters" autocomplete="off" style="text-transform:uppercase"><div class="hint" id="pIfscHint">11 characters</div></div>
        <div class="field"><label>Bank name</label><input type="text" name="bank" id="pBank" maxlength="60"></div>
      </div>
    </div>
    <div class="pf" data-k="trc20"><div class="field"><label>TRC20 wallet address</label><input type="text" name="addr" autocomplete="off" maxlength="64" disabled></div></div>
    <div class="pf" data-k="bep20"><div class="field"><label>BEP20 wallet address</label><input type="text" name="addr" autocomplete="off" maxlength="64" disabled></div></div>
    <?= pin_input('pin', 'Security PIN') ?>
    <button class="btn green" type="submit">Save payout account</button>
  </form>
  <script>
  (function(){
    var k=document.getElementById('pKind');
    function show(){ document.querySelectorAll('#payForm .pf').forEach(function(d){ var on=d.dataset.k===k.value; d.style.display=on?'':'none'; d.querySelectorAll('input').forEach(function(i){ i.disabled=!on; }); }); }
    k.onchange=show; show();
    var t=null, ifsc=document.getElementById('pIfsc'), hint=document.getElementById('pIfscHint');
    ifsc.addEventListener('input',function(e){
      var v=e.target.value.toUpperCase().replace(/[^A-Z0-9]/g,''); e.target.value=v; clearTimeout(t);
      if(v.length<11){hint.textContent='11 characters';hint.className='hint';return;}
      if(!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(v)){hint.textContent='This IFSC format is not valid.';hint.className='hint red';return;}
      hint.textContent='Checking…'; t=setTimeout(function(){
        fetch('https://ifsc.razorpay.com/'+v).then(function(r){return r.ok?r.json():Promise.reject()}).then(function(j){
          hint.textContent='✓ '+j.BANK+' – '+j.BRANCH+', '+j.CITY; hint.className='hint green';
          var b=document.getElementById('pBank'); if(!b.value)b.value=j.BANK;
        }).catch(function(){hint.textContent='Could not verify this IFSC online – please double-check it.';hint.className='hint';});
      },250);
    });
  })();
  </script>
  <?php endif; ?>
</div>
<?php $kst = kyc_status((int)$u['id']); ?>
<div class="card"><h2>Identity verification <span class="badge <?= $kst === 'approved' ? 'ok' : ($kst === 'rejected' ? 'fail' : 'pending') ?>"><?= $kst === 'none' ? 'not submitted' : e($kst) ?></span></h2>
  <p class="muted" style="margin-top:-6px">Verify with your PAN and Aadhaar card for the safety of your account<?= kyc_required_for_withdraw() ? ' – required before withdrawals' : '' ?>.</p>
  <a class="btn <?= $kst === 'approved' ? 'ghost' : 'green' ?>" href="<?= e(url('kyc.php')) ?>"><?= $kst === 'none' ? 'Start verification' : ($kst === 'rejected' ? 'Submit again' : 'View status') ?></a>
</div>
<?php $uf = q('SELECT pin_hash, app_lock, pin_locked_until FROM users WHERE id = ?', [$u['id']])->fetch(); $hasPin = !empty($uf['pin_hash']); ?>
<div class="card" id="pin"><h2>Security PIN <span class="badge <?= $hasPin ? 'ok' : 'pending' ?>"><?= $hasPin ? 'set' : 'not set' ?></span></h2>
  <p class="muted" style="margin-top:-6px">A 6-digit PIN protects your money: it is required for <b>every withdrawal</b>, and you can also ask for it each time you open the app.<?= pin_locked_minutes($uf) ? ' <b class="red">Locked for ' . pin_locked_minutes($uf) . ' min after wrong tries.</b>' : '' ?></p>
  <?php if (!$hasPin): ?>
    <form method="post" style="max-width:420px"><?= csrf_field() ?><input type="hidden" name="action" value="pin_set">
      <?= pin_input('new_pin', 'Choose a 6-digit PIN') ?>
      <?= pin_input('new_pin2', 'Repeat the PIN') ?>
      <?= password_field('password', 'Account password (to confirm it is you)', false, 'current-password') ?>
      <button class="btn green" type="submit">Save PIN</button>
    </form>
  <?php else: ?>
    <div class="grid g2">
      <div>
        <form method="post" style="max-width:420px"><?= csrf_field() ?><input type="hidden" name="action" value="pin_change">
          <h3>Change PIN</h3>
          <?= pin_input('old_pin', 'Current PIN') ?>
          <?= pin_input('new_pin', 'New PIN') ?>
          <?= pin_input('new_pin2', 'Repeat new PIN') ?>
          <?= password_field('password', 'Account password', false, 'current-password') ?>
          <button class="btn" type="submit">Change PIN</button>
        </form>
      </div>
      <div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pin_lock">
          <h3>App lock</h3>
          <label class="check"><input type="checkbox" name="app_lock" value="1" <?= !empty($uf['app_lock']) ? 'checked' : '' ?>> <span>Ask for my PIN when I log in</span></label>
          <button class="btn ghost" style="margin-top:12px" type="submit">Save</button>
        </form>
        <form method="post" style="margin-top:22px" data-confirm="Remove your PIN? You will not be able to withdraw until you set a new one."><?= csrf_field() ?><input type="hidden" name="action" value="pin_remove">
          <h3>Remove PIN</h3>
          <?= password_field('password', 'Account password', false, 'current-password') ?>
          <button class="btn red" type="submit">Remove PIN</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
<a class="btn ghost block only-m" href="<?= e(url('logout.php')) ?>"><?= icon('logout', 18) ?> Log out</a>
<?php user_page_end();
