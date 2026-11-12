<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

// key => [label, type, extra]   type: text | url | number | bool | select | email
$GROUPS = [
    'General' => [
        'site_name'         => ['Site name', 'text'],
        'site_url'          => ['Site address used in emails (e.g. https://example.com/tp)', 'url'],
        'announcement'      => ['Announcement banner (empty = hidden)', 'text'],
        'timezone'          => ['Default display time zone (visitors can switch IST / UTC themselves)', 'tz'],
        'trading_enabled'   => ['Trading enabled', 'bool'],
        'registration_open' => ['New registrations open', 'bool'],
        'verify_email'      => ['Require email confirmation to log in (needs working SMTP; existing users are not affected)', 'bool'],
        'demo_enabled'      => ['Demo wallet on (switch off = users trade with their INR / USDT wallets only; the games need Demo coins and switch off too)', 'bool'],
        'demo_balance'      => ['Demo balance given on sign-up / reset', 'number'],
        'kyc_required_withdraw' => ['Require approved PAN + Aadhaar verification before a withdrawal', 'bool'],
        'chart_default'     => ['Trading chart shown to users by default', 'select', ['tradingview' => 'TradingView (pro chart)', 'standard' => 'Standard chart (prediction arrows)']],
        'reports_enabled'   => ['Users can request their statement by email', 'bool'],
    ],
    'Trading rules' => [
        'durations'        => ['Expiry times in seconds, comma separated (e.g. 30,60,120,300)', 'text'],
        'payout_percent'   => ['Winning payout % on a correct prediction (also on the Markets page)', 'number'],
        'max_open_trades'  => ['Max open trades per user', 'number'],
        'min_trade_demo'   => ['Min trade – Demo', 'number'],
        'max_trade_demo'   => ['Max trade – Demo', 'number'],
        'min_trade_inr'    => ['Min trade – INR', 'number'],
        'max_trade_inr'    => ['Max trade – INR', 'number'],
        'min_trade_usdt'   => ['Min trade – USDT', 'number'],
        'max_trade_usdt'   => ['Max trade – USDT', 'number'],
    ],
    'Wallet' => [
        'usdt_rate_mode'      => ['USDT ⇄ INR rate source', 'select', ['auto' => 'Live market rate (USD/INR)', 'manual' => 'Manual rate below']],
        'usdt_inr_rate'       => ['Manual rate: 1 USDT = ? INR (also used if the live rate is unreachable)', 'number'],
        'convert_fee_percent' => ['Conversion fee %', 'number'],
        'min_deposit_inr'     => ['Min deposit – INR', 'number'],
        'min_deposit_usdt'    => ['Min deposit – USDT', 'number'],
        'min_withdraw_inr'    => ['Min withdrawal – INR', 'number'],
        'min_withdraw_usdt'   => ['Min withdrawal – USDT', 'number'],
        'withdraw_fee_inr'    => ['Withdrawal fee – INR (flat)', 'number'],
        'withdraw_fee_usdt'   => ['Withdrawal fee – USDT (flat)', 'number'],
    ],
    'Payment details shown to users on the deposit page (leave empty to hide a method)' => [
        'pay_upi_id'       => ['UPI ID (users also get a QR code and UPI-app buttons built from it)', 'text'],
        'pay_upi_name'     => ['UPI payee name (shown in the payment app)', 'text'],
        'pay_bank_name'    => ['Bank name', 'text'],
        'pay_bank_holder'  => ['Bank account holder name', 'text'],
        'pay_bank_account' => ['Bank account number', 'text'],
        'pay_bank_ifsc'    => ['Bank IFSC code', 'text'],
        'pay_bank_details' => ['Extra bank instructions (optional)', 'text'],
        'pay_usdt_trc20'   => ['USDT TRC20 address', 'text'],
        'pay_usdt_bep20'   => ['USDT BEP20 address', 'text'],
    ],
    'Live market data' => [
        'stale_secs'      => ['Treat a market as closed when its last quote is older than (seconds)', 'number'],
        'quote_ttl_focus' => ['Refresh the asset a user is watching every (seconds)', 'number'],
        'quote_ttl_bg'    => ['Refresh other forex / stock quotes every (seconds)', 'number'],
        'quote_ttl_api'   => ['Keyed APIs (Twelve Data / Finnhub / Alpha Vantage): refresh watched markets & open trades every (seconds)', 'number'],
        'quote_ttl_api_bg' => ['Keyed APIs: refresh all other markets every (seconds)', 'number'],
    ],
];

function tz_options(string $current): string
{
    $h = '<optgroup label="Common">';
    foreach (TZ_CHOICES as $k => $tz) $h .= '<option value="' . e($tz) . '"' . ($tz === $current ? ' selected' : '') . '>' . e($k) . ' – ' . e($tz) . '</option>';
    $h .= '</optgroup><optgroup label="All time zones">';
    foreach (timezone_identifiers_list() as $tz) $h .= '<option value="' . e($tz) . '"' . ($tz === $current ? ' selected' : '') . '>' . e($tz) . '</option>';
    return $h . '</optgroup>';
}

$SMTP_KEYS = ['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_from_email', 'smtp_from_name', 'smtp_verify_tls', 'notify_email'];

function save_smtp_from_post(): void
{
    $host = trim((string)($_POST['smtp_host'] ?? ''));
    $port = (int)($_POST['smtp_port'] ?? 587);
    $secure = in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls';
    $from = trim((string)($_POST['smtp_from_email'] ?? ''));
    $notify = trim((string)($_POST['notify_email'] ?? ''));
    if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) throw new RuntimeException('SMTP host looks wrong (example: smtp.gmail.com).');
    if ($port < 1 || $port > 65535) throw new RuntimeException('SMTP port must be 1–65535.');
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('"From email" is not a valid address.');
    foreach (array_filter(array_map('trim', explode(',', $notify))) as $n) {
        if (!filter_var($n, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Alert email \"$n\" is not valid.");
    }
    set_setting('smtp_enabled', isset($_POST['smtp_enabled']) ? '1' : '0');
    set_setting('smtp_host', $host);
    set_setting('smtp_port', (string)$port);
    set_setting('smtp_secure', $secure);
    set_setting('smtp_user', trim((string)($_POST['smtp_user'] ?? '')));
    set_setting('smtp_from_email', $from);
    set_setting('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')));
    set_setting('smtp_verify_tls', isset($_POST['smtp_verify_tls']) ? '1' : '0');
    set_setting('notify_email', $notify);
    $pass = (string)($_POST['smtp_pass'] ?? '');
    if ($pass !== '') set_setting('smtp_pass', secret_encrypt($pass));       // blank = keep the saved password
    if (!empty($_POST['smtp_pass_clear'])) set_setting('smtp_pass', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'settings') {
            $values = [];
            foreach ($GROUPS as $fields) {
                foreach ($fields as $k => $def) {
                    [$label, $type] = $def;
                    if ($type === 'bool') {
                        $v = isset($_POST[$k]) ? '1' : '0';
                    } else {
                        $v = trim((string)($_POST[$k] ?? ''));
                        if ($type === 'number' && (!is_numeric($v) || (float)$v < 0)) throw new RuntimeException("$label must be a number ≥ 0.");
                        if ($type === 'select' && !isset($def[2][$v])) throw new RuntimeException("Choose a valid option for: $label");
                        if ($type === 'url' && $v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) throw new RuntimeException('Site address must be a full URL like https://example.com');
                        if ($k === 'pay_upi_id' && $v !== '' && !preg_match('/^[A-Za-z0-9._\-]{2,64}@[A-Za-z][A-Za-z0-9.\-]{1,30}$/', $v)) throw new RuntimeException('UPI ID must look like name@bank.');
                        if ($k === 'pay_bank_ifsc' && $v !== '') { $v = strtoupper($v); if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $v)) throw new RuntimeException('IFSC must be 11 characters, e.g. HDFC0001234.'); }
                        if ($k === 'pay_bank_account' && $v !== '' && !preg_match('/^\d{9,18}$/', $v)) throw new RuntimeException('Bank account number must be 9–18 digits.');
                        if ($k === 'payout_percent' && ((float)$v < 1 || (float)$v > 100)) throw new RuntimeException('Winning payout must be between 1 and 100.');
                        if ($k === 'durations') {
                            $d = array_filter(array_map('intval', explode(',', $v)), fn($x) => $x >= 5 && $x <= 86400);
                            if (!$d) throw new RuntimeException('Enter at least one expiry time (5–86400 seconds).');
                            $v = implode(',', array_unique($d));
                        }
                        if ($type === 'tz' && !in_array($v, timezone_identifiers_list(), true)) throw new RuntimeException('Unknown timezone.');
                    }
                    $values[$k] = $v;
                }
            }
            $values['site_url'] = rtrim($values['site_url'], '/');
            if ($values['verify_email'] === '1' && setting('verify_email', '0') !== '1') {
                q('UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL');   // existing users keep access
                if (!mail_ready()) flash('info', 'Email confirmation will start working once SMTP is set up and enabled (see Email below).');
            }
            foreach ($values as $k => $v) set_setting($k, $v);
            admin_log('settings_save');
            flash('ok', 'Settings saved.');
        } elseif ($action === 'bigbang_key') {
            $key = trim((string)($_POST['bigbang_api_key'] ?? ''));
            if (!empty($_POST['bigbang_key_clear'])) {
                set_setting('bigbang_api_key_enc', '');
                admin_log('bigbang_api_key_clear');
                flash('ok', 'BigBang sandbox API key removed.');
            } elseif ($key !== '') {
                if (!preg_match('/^ek_test_[A-Za-z0-9_-]{8,200}$/', $key)) {
                    throw new RuntimeException('Enter a valid sandbox key beginning with ek_test_.');
                }
                set_setting('bigbang_api_key_enc', secret_encrypt($key));
                admin_log('bigbang_api_key_save');
                flash('ok', 'BigBang sandbox API key saved securely.');
            } else {
                throw new RuntimeException('Paste the sandbox API key, or select Remove saved key.');
            }
        } elseif ($action === 'smtp_save' || $action === 'smtp_test') {
            save_smtp_from_post();
            admin_log('smtp_save', setting('smtp_host', ''));
            if ($action === 'smtp_test') {
                $to = trim((string)($_POST['test_to'] ?? '')) ?: $admin['email'];
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid address to send the test to.');
                if (!mail_ready()) throw new RuntimeException('Saved, but email is switched off or incomplete – tick "Enable email", and fill host, port and From email.');
                [$ok, $er] = send_mail($to, 'Test email from ' . setting('site_name', 'TradePro'),
                    mail_html('SMTP works 🎉', '<p>This is a test message from your admin panel. Email sending is configured correctly.</p><p class="muted">Sent ' . e(fmt_dt(gmdate('Y-m-d H:i:s'))) . '</p>'));
                flash($ok ? 'ok' : 'err', $ok ? "Settings saved. Test email sent to $to – check the inbox (and spam)." : 'Settings saved, but the test email failed: ' . $er);
            } else {
                flash('ok', 'Email settings saved.');
            }
        } elseif ($action === 'account') {
            $old  = (string)($_POST['old'] ?? '');
            $new  = (string)($_POST['new'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $mail = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!password_verify($old, $admin['password_hash'])) throw new RuntimeException('Current password is wrong.');
            if ($name === '' || mb_strlen($name) > 100) throw new RuntimeException('Enter a name.');
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || strlen($mail) > 150) throw new RuntimeException('Enter a valid email address.');
            if (q('SELECT 1 FROM admins WHERE email = ? AND id <> ?', [$mail, $admin['id']])->fetchColumn()) throw new RuntimeException('Another admin already uses that email.');
            $hash = $admin['password_hash'];
            if ($new !== '') {
                if ($e = password_error($new)) throw new RuntimeException($e);
                if ($new !== ($_POST['new2'] ?? '')) throw new RuntimeException('New passwords do not match.');
                $hash = password_hash($new, PASSWORD_DEFAULT);
            }
            q('UPDATE admins SET name = ?, email = ?, password_hash = ? WHERE id = ?', [$name, $mail, $hash, $admin['id']]);
            session_regenerate_id(true);
            unset($_SESSION['adm_pw_default']);
            admin_log('admin_account_change');
            flash('ok', $new !== '' ? 'Admin account and password updated.' : 'Admin account updated.');
        } elseif ($action === 'add_admin') {
            $name = trim((string)($_POST['name'] ?? ''));
            $mail = strtolower(trim((string)($_POST['email'] ?? '')));
            $pw = (string)($_POST['password'] ?? '');
            if ($name === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter the new admin\'s name and a valid email.');
            if ($e = password_error($pw)) throw new RuntimeException($e);
            if (q('SELECT 1 FROM admins WHERE email = ?', [$mail])->fetchColumn()) throw new RuntimeException('That email is already an admin.');
            q('INSERT INTO admins (name, email, password_hash) VALUES (?,?,?)', [mb_substr($name, 0, 100), $mail, password_hash($pw, PASSWORD_DEFAULT)]);
            admin_log('admin_add', $mail);
            flash('ok', "Admin $mail added.");
        } elseif ($action === 'del_admin') {
            $aid = (int)($_POST['id'] ?? 0);
            if ($aid === (int)$admin['id']) throw new RuntimeException('You cannot remove your own account.');
            q('DELETE FROM admins WHERE id = ?', [$aid]);
            admin_log('admin_delete', "#$aid");
            flash('ok', 'Admin removed.');
        }
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    } catch (PDOException $ex) {
        error_log('settings: ' . $ex->getMessage());
        flash('err', 'Could not save. Please try again.');
    }
    redirect('admin/settings.php' . (str_starts_with($action, 'smtp') ? '#smtp' : ($action === 'account' || str_contains($action, 'admin') ? '#account' : '')));
}

admin_start('Settings', 'settings.php');
$cfg = smtp_config();
$mailLog = q('SELECT * FROM mail_log ORDER BY id DESC LIMIT 12')->fetchAll();
$admins = q('SELECT id, name, email, last_login FROM admins ORDER BY id')->fetchAll();
$cronUrl = abs_url('cron.php?key=' . setting('cron_key', ''));
?>
<h1>Settings</h1>
<div class="tabs" style="margin-bottom:18px">
  <a href="#general">General</a><a href="#smtp">Email (SMTP)</a><a href="#account">Admin account</a><a href="#cron">Background job</a>
</div>

<form method="post" id="general"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <?php foreach ($GROUPS as $title => $fields): ?>
    <div class="card"><h2><?= e($title) ?></h2><div class="row">
      <?php foreach ($fields as $k => $def): [$label, $type] = $def; $val = (string)setting($k, ''); ?>
        <div class="field" style="min-width:230px">
          <?php if ($type === 'bool'): ?>
            <label class="check" style="margin-top:22px"><input type="checkbox" name="<?= e($k) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
          <?php elseif ($type === 'select'): ?>
            <label><?= e($label) ?></label>
            <select name="<?= e($k) ?>"><?php foreach ($def[2] as $ov => $ol): ?><option value="<?= e($ov) ?>" <?= $val === (string)$ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select>
          <?php elseif ($type === 'tz'): ?>
            <label><?= e($label) ?></label>
            <select name="<?= e($k) ?>" class="s2" data-search="always"><?= tz_options($val) ?></select>
          <?php else: ?>
            <label><?= e($label) ?></label>
            <input type="<?= $type === 'number' ? 'number' : ($type === 'url' ? 'url' : 'text') ?>" <?= $type === 'number' ? 'step="any" min="0" inputmode="decimal"' : '' ?> name="<?= e($k) ?>" value="<?= e($val) ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div></div>
  <?php endforeach; ?>
  <button class="btn green" type="submit">Save settings</button>
</form>

<div class="card" id="bigbang" style="margin-top:28px">
  <h2>BigBang Casino sandbox API</h2>
  <p class="muted" style="margin-top:-6px">Save your <code>ek_test_</code> sandbox key here. It is encrypted in the settings database and never displayed again. Only Standard sandbox games are used; no TradePro wallet integration is enabled.</p>
  <p><span class="badge <?= setting('bigbang_api_key_enc', '') !== '' ? 'ok' : 'pending' ?>"><?= setting('bigbang_api_key_enc', '') !== '' ? 'key saved' : 'key not configured' ?></span></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bigbang_key">
    <div class="field"><label>BigBang sandbox API key</label><div class="pwbox"><input type="password" name="bigbang_api_key" autocomplete="new-password" placeholder="ek_test_... (leave blank to keep saved key)"><button type="button" class="pwtoggle" data-pwtoggle tabindex="-1">Show</button></div></div>
    <label class="check" style="margin:10px 0"><input type="checkbox" name="bigbang_key_clear" value="1"> <span>Remove saved key</span></label>
    <button class="btn green" type="submit">Save BigBang key</button>
  </form>
</div>

<div class="card" id="smtp" style="margin-top:28px">
  <h2>Email (SMTP) <span class="badge <?= mail_ready() ? 'ok' : 'pending' ?>"><?= mail_ready() ? 'enabled' : 'not active' ?></span></h2>
  <p class="muted" style="margin-top:-6px">Used for password reset links, email confirmation, welcome emails, deposit / withdrawal alerts to admins and status emails to users.</p>
  <div class="inline" style="margin-bottom:14px">
    <span class="muted">Quick fill:</span>
    <button type="button" class="btn sm ghost" data-preset="smtp.gmail.com|587|tls">Gmail</button>
    <button type="button" class="btn sm ghost" data-preset="smtp.office365.com|587|tls">Outlook / 365</button>
    <button type="button" class="btn sm ghost" data-preset="smtp.zoho.in|465|ssl">Zoho (IN)</button>
    <button type="button" class="btn sm ghost" data-preset="smtp-relay.brevo.com|587|tls">Brevo</button>
    <button type="button" class="btn sm ghost" data-preset="smtp.sendgrid.net|587|tls">SendGrid</button>
  </div>
  <form method="post" id="smtpForm"><?= csrf_field() ?>
    <div class="row">
      <div class="field"><label class="check" style="margin-top:22px"><input type="checkbox" name="smtp_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> <span>Enable email sending</span></label></div>
      <div class="field"><label>SMTP host</label><input type="text" name="smtp_host" id="sHost" value="<?= e($cfg['host']) ?>" placeholder="smtp.gmail.com" autocomplete="off"></div>
      <div class="field"><label>Port</label><input type="number" name="smtp_port" id="sPort" value="<?= e($cfg['port']) ?>" min="1" max="65535"></div>
      <div class="field"><label>Security</label>
        <select name="smtp_secure" id="sSec">
          <option value="tls" <?= $cfg['secure'] === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
          <option value="ssl" <?= $cfg['secure'] === 'ssl' ? 'selected' : '' ?>>SSL / TLS (465)</option>
          <option value="none" <?= $cfg['secure'] === 'none' ? 'selected' : '' ?>>None (25)</option>
        </select></div>
    </div>
    <div class="row">
      <div class="field"><label>SMTP username</label><input type="text" name="smtp_user" value="<?= e($cfg['user']) ?>" autocomplete="off" placeholder="you@gmail.com"></div>
      <div class="field"><label>SMTP password <?= $cfg['pass'] !== '' ? '<span class="badge ok">saved</span>' : '' ?></label>
        <div class="pwbox"><input type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= $cfg['pass'] !== '' ? '•••••••• (leave blank to keep)' : 'App password' ?>"><button type="button" class="pwtoggle" data-pwtoggle tabindex="-1">Show</button></div>
        <?php if ($cfg['pass'] !== ''): ?><label class="check" style="margin-top:6px;font-size:12px"><input type="checkbox" name="smtp_pass_clear" value="1"> <span class="muted">Remove saved password</span></label><?php endif; ?>
        <div class="hint">Stored encrypted. Gmail needs an <b>App Password</b> (Google Account → Security → 2-Step Verification → App passwords).</div></div>
    </div>
    <div class="row">
      <div class="field"><label>From email</label><input type="email" name="smtp_from_email" value="<?= e(setting('smtp_from_email', '')) ?>" placeholder="defaults to the SMTP username"></div>
      <div class="field"><label>From name</label><input type="text" name="smtp_from_name" value="<?= e(setting('smtp_from_name', '')) ?>" placeholder="<?= e(setting('site_name', 'TradePro')) ?>"></div>
      <div class="field"><label>Admin alert emails (comma separated)</label><input type="text" name="notify_email" value="<?= e(setting('notify_email', '')) ?>" placeholder="empty = all admins"></div>
    </div>
    <div class="field"><label class="check"><input type="checkbox" name="smtp_verify_tls" value="1" <?= $cfg['verify_tls'] ? 'checked' : '' ?>> <span>Verify the server's TLS certificate (recommended)</span></label></div>
    <div class="row" style="align-items:flex-end">
      <div class="field"><label>Send a test email to</label><input type="email" name="test_to" value="<?= e($admin['email']) ?>"></div>
      <div class="field inline" style="flex:2;margin-bottom:14px">
        <button class="btn" type="submit" name="action" value="smtp_save">Save email settings</button>
        <button class="btn green" type="submit" name="action" value="smtp_test">Save &amp; send test</button>
      </div>
    </div>
  </form>
  <h3 style="margin-top:8px">Recent emails</h3>
  <div class="tablewrap"><table class="rt">
    <tr><th>When</th><th>To</th><th>Subject</th><th>Result</th></tr>
    <?php foreach ($mailLog as $m): ?>
      <tr><td><?= e(fmt_dt($m['created_at'])) ?></td><td><?= e($m['to_email']) ?></td><td><?= e($m['subject']) ?></td>
        <td style="white-space:normal"><?= $m['ok'] ? '<span class="badge ok">sent</span>' : '<span class="badge fail">failed</span> <span class="muted" style="font-size:12px">' . e($m['error']) . '</span>' ?></td></tr>
    <?php endforeach; if (!$mailLog): ?><tr><td colspan="4" class="muted">No emails sent yet.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="grid g2" id="account">
  <div class="card"><h2>My admin account</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="account">
      <div class="field"><label>Name</label><input type="text" name="name" value="<?= e($admin['name']) ?>" required maxlength="100"></div>
      <div class="field"><label>Login email</label><input type="email" name="email" value="<?= e($admin['email']) ?>" required></div>
      <?= password_field('old', 'Current password (required to save)', false, 'current-password') ?>
      <div class="field"><label>New password (leave empty to keep the current one)</label>
        <div class="pwbox"><input type="password" name="new" autocomplete="new-password" data-meter><button type="button" class="pwtoggle" data-pwtoggle tabindex="-1">Show</button></div>
        <div class="meter"><i></i></div><div class="meterhint muted"><?= e(PASSWORD_HINT) ?></div></div>
      <div class="field"><label>Repeat new password</label><input type="password" name="new2" autocomplete="new-password"></div>
      <button class="btn" type="submit">Update my account</button>
    </form>
  </div>
  <div class="card"><h2>All admins</h2>
    <div class="tablewrap"><table class="rt">
      <tr><th>Name</th><th>Email</th><th>Last login</th><th></th></tr>
      <?php foreach ($admins as $a): ?>
        <tr><td><?= e($a['name']) ?></td><td><?= e($a['email']) ?></td><td><?= e(fmt_dt($a['last_login'])) ?></td>
          <td><?php if ((int)$a['id'] !== (int)$admin['id']): ?><form method="post" data-confirm="Remove this admin?"><?= csrf_field() ?><input type="hidden" name="action" value="del_admin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn sm red" type="submit">Remove</button></form><?php else: ?><span class="muted">you</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <h3 style="margin-top:16px">Add another admin</h3>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_admin">
      <div class="field"><label>Name</label><input type="text" name="name" required maxlength="100"></div>
      <div class="field"><label>Email</label><input type="email" name="email" required></div>
      <?= password_field('password', 'Password', true) ?>
      <button class="btn ghost" type="submit">Add admin</button>
    </form>
  </div>
</div>

<div class="card" id="cron">
  <h2>Background job (optional)</h2>
  <p class="muted">Trades settle automatically while anyone is online. To also settle trades when nobody is on the site, call this address every minute (cron job / Windows Task Scheduler / uptime monitor):</p>
  <div class="field"><input type="text" readonly value="<?= e($cronUrl) ?>" onclick="this.select()"></div>
  <p class="muted" style="font-size:12px;margin:0">Or from a terminal: <span class="mono">php <?= e(APP_ROOT) ?>/cron.php</span></p>
</div>
<script>
document.querySelectorAll('[data-preset]').forEach(function(b){
  b.addEventListener('click', function(){
    var p = b.dataset.preset.split('|');
    document.getElementById('sHost').value = p[0]; document.getElementById('sPort').value = p[1]; document.getElementById('sSec').value = p[2];
  });
});
document.getElementById('sSec').addEventListener('change', function(){
  var port = document.getElementById('sPort'); port.value = {tls:587, ssl:465, none:25}[this.value] || port.value;
});
</script>
<?php select2_assets(); ?>
<?php admin_end();
