<?php
define('PIN_PAGE', true);
require_once __DIR__ . '/includes/bootstrap.php';

$u = current_user();
if (!$u) redirect('login.php');
if (empty($u['app_lock']) || empty($u['pin_hash']) || !empty($_SESSION['pin_ok'])) redirect('trade.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (($_POST['action'] ?? '') === 'forgot') {
        // forgot the PIN: the account password proves it is the owner; the PIN is removed and can be set again in Profile
        if (!throttle_ok('pinforgot', 5, 600)) {
            $err = 'Too many attempts. Try again in a few minutes.';
        } elseif (!password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
            throttle_hit('pinforgot');
            $err = 'Wrong password.';
        } else {
            pin_clear((int)$u['id']);
            $_SESSION['pin_ok'] = 1;
            flash('info', 'Your PIN was removed. Set a new one in Profile → Security PIN (it is needed for withdrawals).');
            redirect('profile.php');
        }
    } else {
        $err = pin_check((int)$u['id'], (string)($_POST['pin'] ?? '')) ?? '';
        if ($err === '') {
            $_SESSION['pin_ok'] = 1;
            $_SESSION['games_ok'] = 'login';
            redirect('trade.php');
        }
    }
}
page_head('Enter PIN');
?>
<div class="authwrap"><div class="authbox" style="text-align:center">
  <?= logo_html() ?>
  <p class="sub">Hi <?= e(explode(' ', $u['name'])[0]) ?> – enter your 6-digit PIN</p>
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" id="pinForm" data-noload>
    <?= csrf_field() ?>
    <div style="display:flex;justify-content:center"><?= pin_input('pin', '') ?></div>
    <button class="btn block" type="submit">Unlock</button>
  </form>
  <details style="margin-top:16px;text-align:left">
    <summary class="muted" style="cursor:pointer">Forgot your PIN?</summary>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="forgot">
      <?= password_field('password', 'Account password', false, 'current-password') ?>
      <button class="btn ghost block" type="submit">Remove PIN &amp; continue</button>
    </form>
  </details>
  <div class="links"><a href="<?= e(url('logout.php')) ?>">Log out</a></div>
</div></div>
<script>document.addEventListener('pin-complete',function(e){document.getElementById('pinForm').submit();});</script>
</body></html>
