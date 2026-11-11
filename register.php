<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('trade.php');

$err = '';
$f = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $f['name']  = trim($_POST['name'] ?? '');
    $f['email'] = strtolower(trim($_POST['email'] ?? ''));
    $f['phone'] = trim($_POST['phone'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (setting('registration_open', '1') !== '1') $err = 'Registration is currently closed.';
    elseif (!throttle_ok('reg', 5, 600)) $err = 'Too many attempts. Try again in a few minutes.';
    elseif ($f['name'] === '' || mb_strlen($f['name']) > 100) $err = 'Enter your name.';
    elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || strlen($f['email']) > 150) $err = 'Enter a valid email address.';
    elseif (!($ph = valid_phone($f['phone']))) $err = 'Enter a valid contact number (10–14 digits).';
    elseif ($e = password_error($pass)) $err = $e;
    elseif ($pass !== $pass2) $err = 'Passwords do not match.';
    elseif (empty($_POST['terms'])) $err = 'Please accept the risk disclosure.';
    elseif (q('SELECT 1 FROM users WHERE email = ?', [$f['email']])->fetchColumn()) $err = 'This email is already registered.';
    else {
        throttle_hit('reg');
        try {
            $verify = email_verification_required();
            $uid = register_user($f['name'], $f['email'], $ph, $pass, false);
            $user = q('SELECT * FROM users WHERE id = ?', [$uid])->fetch();
            if ($verify) {
                send_verify_email($user);
                flash('ok', 'Account created. Please confirm your email – we sent you a link.');
                redirect('login.php');
            }
            if (mail_ready()) {
                send_mail($user['email'], 'Welcome to ' . setting('site_name', 'TradePro'),
                    mail_html('Welcome!', '<p>Hi ' . e($user['name']) . ', your account is ready. Your demo account is funded so you can practise right away.</p>', 'Start trading', abs_url('trade.php')));
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            flash('ok', 'Welcome! Your demo account is funded and ready.');
            redirect('trade.php');
        } catch (Throwable $ex) {
            error_log('register: ' . $ex->getMessage());
            $err = 'Could not create the account. Please try again.';
        }
    }
}
page_head('Sign up');
?>
<div class="authwrap"><div class="authbox">
  <?= logo_html() ?>
  <p class="sub">Create your account – get <?= e(money(setting('demo_balance', 0), 'demo')) ?> demo funds</p>
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <div class="field"><label>Full name</label><input type="text" name="name" value="<?= e($f['name']) ?>" required maxlength="100" autocomplete="name"></div>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($f['email']) ?>" required autocomplete="email"></div>
    <div class="field"><label>Contact number</label><input type="tel" name="phone" value="<?= e($f['phone']) ?>" autocomplete="tel" required placeholder="+91 98765 43210"></div>
    <?= password_field('password', 'Password', true) ?>
    <?= password_field('password2', 'Confirm password') ?>
    <div class="field"><label class="check"><input type="checkbox" name="terms" value="1"> <span>I understand trading is risky and I may lose my deposit.</span></label></div>
    <button class="btn block" type="submit">Create account</button>
  </form>
  <p class="center muted" style="margin-top:16px">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
</div></div>
</body></html>
