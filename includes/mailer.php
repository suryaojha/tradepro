<?php
/**
 * Minimal SMTP client (no Composer needed): SSL (465), STARTTLS (587) or plain (25), AUTH LOGIN / PLAIN.
 * Configure it in Admin > Settings > Email (SMTP). Gmail: smtp.gmail.com, port 587, TLS, App Password.
 */

function smtp_config(): array
{
    $user = trim((string)setting('smtp_user', ''));
    $from = trim((string)setting('smtp_from_email', ''));
    return [
        'enabled'    => setting('smtp_enabled', '0') === '1',
        'host'       => trim((string)setting('smtp_host', '')),
        'port'       => (int)setting('smtp_port', 587),
        'secure'     => in_array(setting('smtp_secure', 'tls'), ['tls', 'ssl', 'none'], true) ? setting('smtp_secure', 'tls') : 'tls',
        'user'       => $user,
        'pass'       => secret_decrypt((string)setting('smtp_pass', '')),
        'from'       => $from !== '' ? $from : $user,
        'from_name'  => trim((string)setting('smtp_from_name', '')) ?: (string)setting('site_name', 'TradePro'),
        'verify_tls' => setting('smtp_verify_tls', '1') === '1',
    ];
}

function mail_ready(): bool
{
    $c = smtp_config();
    return $c['enabled'] && $c['host'] !== '' && $c['port'] > 0 && filter_var($c['from'], FILTER_VALIDATE_EMAIL);
}

/** Send one message through SMTP. Throws RuntimeException with the server's answer on failure. */
function smtp_send(array $c, string $to, string $subject, string $html, string $text = '', array $attachments = []): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Invalid recipient address: $to");
    if (!filter_var($c['from'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Set a valid "From email" first.');
    $text = $text !== '' ? $text : trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h\d|/tr)>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));

    $ssl = ['verify_peer' => $c['verify_tls'], 'verify_peer_name' => $c['verify_tls'], 'peer_name' => $c['host'], 'allow_self_signed' => !$c['verify_tls']];
    $ctx = stream_context_create(['ssl' => $ssl]);
    $remote = ($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
    $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Cannot connect to {$c['host']}:{$c['port']} – $errstr ($errno)");
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): array {
        $code = 0; $all = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $all .= $line;
            $code = (int)substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        if ($all === '') throw new RuntimeException('SMTP server closed the connection or timed out.');
        return [$code, trim($all)];
    };
    $cmd = function (string $line, array $ok) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        [$code, $msg] = $read();
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException('SMTP error ' . $code . ': ' . preg_replace('/\s+/', ' ', $msg));
        }
        return $msg;
    };

    try {
        [$code, $msg] = $read();
        if ($code !== 220) throw new RuntimeException("SMTP greeting failed: $msg");
        $helo = preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost') ?: 'localhost';
        $ehlo = $cmd("EHLO $helo", [250]);
        if ($c['secure'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS handshake failed. If the certificate cannot be verified on this server, untick "Verify TLS certificate" in Settings.');
            }
            $ehlo = $cmd("EHLO $helo", [250]);
        }
        if ($c['user'] !== '') {
            if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'LOGIN') === false && stripos($ehlo, 'PLAIN') !== false) {
                $cmd('AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']), [235]);
            } else {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($c['user']), [334]);
                $cmd(base64_encode($c['pass']), [235]);
            }
        }
        $cmd('MAIL FROM:<' . $c['from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $b = 'b' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($c['from'], '@'), 1);
        $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $enc($c['from_name']) . ' <' . $c['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: ' . ($attachments ? 'multipart/mixed' : 'multipart/alternative') . '; boundary="' . $b . '"',
        ];
        $alt = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n")
             . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n")
             . "--$b--\r\n";
        if ($attachments) {
            $b2 = 'a' . bin2hex(random_bytes(12));
            $headers[count($headers) - 1] = 'Content-Type: multipart/mixed; boundary="' . $b2 . '"';
            $body = "--$b2\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $alt;
            foreach ($attachments as [$fname, $mime, $data]) {
                $fname = preg_replace('/[^A-Za-z0-9._-]/', '_', $fname);
                $body .= "--$b2\r\nContent-Type: $mime; name=\"$fname\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fname\"\r\n\r\n"
                       . chunk_split(base64_encode($data), 76, "\r\n");
            }
            $body .= "--$b2--\r\n";
        } else {
            $body = $alt;
        }
        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        [$code, $msg] = $read();
        if ($code !== 250) throw new RuntimeException("Message rejected: $msg");
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/** Send an email using the saved SMTP settings and log the result. Returns [ok, error]. */
function send_mail(string $to, string $subject, string $html, string $text = '', array $attachments = []): array
{
    if (!mail_ready()) return [false, 'Email (SMTP) is not configured.'];
    try {
        smtp_send(smtp_config(), $to, $subject, $html, $text, $attachments);
        $ok = true; $err = '';
    } catch (Throwable $ex) {
        $ok = false; $err = $ex->getMessage();
    }
    try {
        q('INSERT INTO mail_log (to_email, subject, ok, error) VALUES (?,?,?,?)', [substr($to, 0, 150), substr($subject, 0, 200), $ok ? 1 : 0, $ok ? null : substr($err, 0, 500)]);
        q('DELETE FROM mail_log WHERE id < (SELECT m FROM (SELECT MAX(id) - 200 AS m FROM mail_log) x)');
    } catch (Throwable $ex) {}
    return [$ok, $err];
}

/** Branded HTML email body. */
function mail_html(string $title, string $bodyHtml, ?string $btnLabel = null, ?string $btnUrl = null): string
{
    $site = e(setting('site_name', 'TradePro'));
    $btn = $btnLabel && $btnUrl
        ? '<p style="margin:26px 0"><a href="' . e($btnUrl) . '" style="background:#4c82ff;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;display:inline-block">' . e($btnLabel) . '</a></p>'
          . '<p style="color:#6b7690;font-size:12px;word-break:break-all">Or paste this link in your browser:<br>' . e($btnUrl) . '</p>'
        : '';
    return '<!doctype html><html><body style="margin:0;background:#eef1f8;padding:24px;font-family:Segoe UI,Arial,sans-serif;color:#1c2540">'
        . '<table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:#0b1020;color:#fff;padding:18px 26px;font-size:20px;font-weight:700">' . $site . '</td></tr>'
        . '<tr><td style="padding:26px"><h2 style="margin:0 0 14px;font-size:20px">' . e($title) . '</h2>'
        . '<div style="font-size:15px;line-height:1.55">' . $bodyHtml . '</div>' . $btn . '</td></tr>'
        . '<tr><td style="padding:14px 26px;background:#f6f8fd;color:#8490ad;font-size:12px">You are receiving this email because of activity on your ' . $site . ' account.</td></tr>'
        . '</table></body></html>';
}

/** Email addresses that should receive admin alerts (Settings > notify email, else every admin). */
function admin_recipients(): array
{
    $list = array_filter(array_map('trim', explode(',', (string)setting('notify_email', ''))), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL));
    return $list ?: q('SELECT email FROM admins WHERE email NOT LIKE "%@admin.local"')->fetchAll(PDO::FETCH_COLUMN);
}

function notify_admins(string $subject, string $bodyHtml, ?string $btnLabel = null, ?string $btnUrl = null): void
{
    if (!mail_ready()) return;
    $html = mail_html($subject, $bodyHtml, $btnLabel, $btnUrl);
    foreach (admin_recipients() as $to) send_mail($to, '[' . setting('site_name', 'TradePro') . '] ' . $subject, $html);
}

// ---------------------------------------------------------------- one-time links (reset password / verify email)
function token_create(string $kind, int $accountId, int $ttl = 3600): string
{
    q('DELETE FROM password_resets WHERE kind = ? AND account_id = ? AND (used_at IS NOT NULL OR expires_at < NOW())', [$kind, $accountId]);
    $t = bin2hex(random_bytes(32));
    q('INSERT INTO password_resets (kind, account_id, token_hash, expires_at) VALUES (?,?,?,?)',
        [$kind, $accountId, hash('sha256', $t), gmdate('Y-m-d H:i:s', time() + $ttl)]);
    return $t;
}

function token_find(string $kind, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    return q('SELECT * FROM password_resets WHERE kind = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
        [$kind, hash('sha256', $token)])->fetch() ?: null;
}

function token_burn(int $id): void
{
    q('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$id]);
}

function send_reset_email(string $kind, int $id, string $email, string $name): bool
{
    $t = token_create($kind, $id, 3600);
    $link = abs_url(($kind === 'admin' ? 'admin/' : '') . 'reset.php?token=' . $t);
    $html = mail_html('Reset your password', '<p>Hi ' . e($name) . ',</p><p>We received a request to reset your password. This link works for 1 hour. If you did not ask for it, you can ignore this email.</p>', 'Choose a new password', $link);
    return send_mail($email, 'Reset your password', $html)[0];
}

function send_verify_email(array $user): bool
{
    $t = token_create('verify', (int)$user['id'], 86400 * 3);
    $link = abs_url('verify.php?token=' . $t);
    $html = mail_html('Confirm your email', '<p>Hi ' . e($user['name']) . ',</p><p>Welcome to ' . e(setting('site_name', 'TradePro')) . '! Please confirm your email address to activate your account.</p>', 'Confirm email', $link);
    return send_mail($user['email'], 'Confirm your email address', $html)[0];
}

function email_verification_required(): bool
{
    return setting('verify_email', '0') === '1' && mail_ready();
}
