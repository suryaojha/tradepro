<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (current_admin()) redirect('admin/index.php');

$err = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if (!captcha_check()) {\n        $err = 'Security check failed. Please try again.';\n    } elseif (!throttle_ok('admin_login', 5, 600)) {
        $err = 'Too many failed attempts. Wait 10 minutes.';
    } else {
        $a = q('SELECT * FROM admins WHERE email = ?', [$email])->fetch();
        if ($a && password_verify($pass, $a['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$a['id'];
            unset($_SESSION['adm_pw_default']);
            if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $a['id']]);
            }
            q('UPDATE admins SET last_login = NOW() WHERE id = ?', [$a['id']]);
            admin_log('login');
            redirect('admin/index.php');
        }
        throttle_hit('admin_login');
        $err = 'Wrong email or password.';
    }
}
if ($err) captcha_reset();\npage_head('Admin login');
?>
<div class="authwrap"><div class="authbox">
  <?= logo_html() ?>
  <p class="sub">Administrator sign-in</p>
  <?php render_flashes(); ?>
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Admin email</label><input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username"></div>
    <?= password_field('password', 'Password', false, 'current-password') ?>
    <?= captcha_field() ?>\n    <button class="btn block" type="submit">Sign in</button>
  </form>
  <div class="links"><a href="<?= e(url('admin/forgot.php')) ?>">Forgot password?</a><a href="<?= e(url('')) ?>">← Back to site</a></div>
</div></div>
</body></html>
