<?php
/** Forgot-password and reset-password pages shared by users and admins. */

function auth_forgot_page(string $kind): void
{
    $isAdmin = $kind === 'admin';
    $msg = '';
    $err = '';
    $email = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!throttle_ok('forgot', 5, 600)) {
            $err = 'Too many requests. Try again in a few minutes.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Enter a valid email address.';
        } elseif (!mail_ready()) {
            $err = 'Password reset by email is not available yet because email (SMTP) is not configured. Please contact support.';
        } else {
            throttle_hit('forgot');
            $row = q($isAdmin ? 'SELECT id, name, email FROM admins WHERE email = ?' : "SELECT id, name, email FROM users WHERE email = ? AND status = 'active'", [$email])->fetch();
            if ($row) send_reset_email($kind, (int)$row['id'], $row['email'], $row['name']);
            $msg = 'If that email is registered, a reset link is on its way. It is valid for 1 hour.';
        }
    }
    page_head('Forgot password');
    ?>
<div class="authwrap"><div class="authbox">
  <?= logo_html() ?>
  <p class="sub"><?= $isAdmin ? 'Administrator password reset' : 'Reset your password' ?></p>
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email"></div>
    <button class="btn block" type="submit">Send reset link</button>
  </form>
  <div class="links"><a href="<?= e(url($isAdmin ? 'admin/login.php' : 'login.php')) ?>">← Back to log in</a></div>
</div></div>
</body></html>
<?php
}

function auth_reset_page(string $kind): void
{
    $isAdmin = $kind === 'admin';
    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $row = token_find($kind, $token);
    $err = '';
    $loginUrl = $isAdmin ? 'admin/login.php' : 'login.php';

    if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if ($e = password_error($p1)) $err = $e;
        elseif ($p1 !== $p2) $err = 'Passwords do not match.';
        else {
            q('UPDATE ' . ($isAdmin ? 'admins' : 'users') . ' SET password_hash = ? WHERE id = ?', [password_hash($p1, PASSWORD_DEFAULT), $row['account_id']]);
            token_burn((int)$row['id']);
            unset($_SESSION['adm_pw_default']);
            flash('ok', 'Password updated. You can log in now.');
            redirect($loginUrl);
        }
    }
    page_head('Reset password');
    ?>
<div class="authwrap"><div class="authbox">
  <?= logo_html() ?>
  <p class="sub">Choose a new password</p>
  <?php if (!$row): ?>
    <div class="flash err">This reset link is invalid or has expired.</div>
    <div class="links"><a href="<?= e(url($isAdmin ? 'admin/forgot.php' : 'forgot.php')) ?>">Request a new link</a><a href="<?= e(url($loginUrl)) ?>">Log in</a></div>
  <?php else: ?>
    <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <?= password_field('password', 'New password', true) ?>
      <?= password_field('password2', 'Repeat new password') ?>
      <button class="btn block" type="submit">Save new password</button>
    </form>
  <?php endif; ?>
</div></div>
</body></html>
<?php
}
