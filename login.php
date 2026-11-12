<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('index.php');

$err = '';
$info = '';
$email = '';
$unverified = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if (!captcha_check()) {
        $err = 'Security check failed. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'resend') {
        if (!throttle_ok('resend', 3, 600)) {
            $err = 'Please wait a few minutes before asking for another email.';
        } else {
            throttle_hit('resend');
            $u = q("SELECT * FROM users WHERE email = ? AND email_verified_at IS NULL AND status = 'active'", [$email])->fetch();
            if ($u && email_verification_required()) send_verify_email($u);
            $info = 'If that account still needs confirmation, a new email is on its way.';
        }
    } elseif (!throttle_ok('login')) {
        $err = 'Too many failed attempts. Wait a few minutes and try again.';
    } else {
        $u = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            throttle_hit('login');
            $err = 'Wrong email or password.';
        } elseif ($u['status'] !== 'active') {
            $err = 'This account is blocked. Contact support.';
        } elseif ($u['email_verified_at'] === null && email_verification_required()) {
            $err = 'Please confirm your email address first. We sent you a link when you signed up.';
            $unverified = true;
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['games_ok'] = 'login';
            $_SESSION['pin_ok'] = (empty($u['app_lock']) || empty($u['pin_hash'])) ? 1 : 0;
            if (in_array($u['theme'] ?? '', ['light', 'dark'], true)) {      // bring the user's saved theme to this device
                setcookie('theme', $u['theme'], ['expires' => time() + 31536000, 'path' => '/', 'secure' => FORCE_HTTPS_COOKIES, 'samesite' => 'Lax']);
            }
            q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
            redirect('index.php');
        }
    }
}
if ($err) captcha_reset();
page_head('Log in');
?>
<div class="authwrap"><div class="authbox">
  <?= logo_html() ?>
  <p class="sub">Log in to your account</p>
  <?php render_flashes(); ?>
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="flash ok"><?= e($info) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email"></div>
    <?= password_field('password', 'Password', false, 'current-password') ?>
    <?= captcha_field() ?>
    <button class="btn block" type="submit">Log in</button>
    <?php if ($unverified): ?>
      <button class="btn ghost block" style="margin-top:8px" type="submit" name="action" value="resend" formnovalidate>Resend confirmation email</button>
    <?php endif; ?>
  </form>
  <div class="links"><a href="<?= e(url('forgot.php')) ?>">Forgot password?</a><span class="muted">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></span></div>
</div></div>
</body></html>
