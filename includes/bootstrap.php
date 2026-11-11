<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/schema.php';

// ---------------------------------------------------------------- session
if (PHP_SAPI === 'cli') {
    $_SESSION = [];
} elseif (session_status() === PHP_SESSION_NONE) {
    session_name('tpsess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => FORCE_HTTPS_COOKIES,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------- database
function is_api_request(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'stats.php');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_FOUND_ROWS   => true,
                ]
            );
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $ex) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'Database connection failed: ' . $ex->getMessage() . "\n");
                exit(1);
            }
            http_response_code(500);
            if (is_api_request()) {
                header('Content-Type: application/json');
                exit(json_encode(['ok' => false, 'error' => 'Database unavailable.']));
            }
            exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:16px system-ui;background:#0b1020;color:#e9eefc;padding:30px">'
                . '<h2>Database connection failed</h2><p>Check the DB settings in <code>config.php</code>, make sure MySQL is running, then open '
                . '<a style="color:#7aa2ff" href="' . htmlspecialchars(BASE_URL . '/install.php') . '">install.php</a> to create the database.</p></body>');
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// ---------------------------------------------------------------- output / routing helpers
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Absolute URL for emails. Uses the "site_url" setting so a spoofed Host header can never poison reset links. */
function abs_url(string $path = ''): string
{
    $base = rtrim((string)setting('site_url', ''), '/');
    if ($base === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = (IS_HTTPS ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) . BASE_URL;
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- CSRF
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && hash_equals(csrf_token(), $t);
}

function csrf_require(): void
{
    if (!csrf_ok()) {
        http_response_code(419);
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => 'Session expired. Reload the page.'], 419);
        }
        exit('Invalid or expired form token. Go back, reload and try again.');
    }
}

// ---------------------------------------------------------------- settings
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    return $cache;
}

function setting(string $k, $default = null)
{
    $s = settings_all();
    return array_key_exists($k, $s) ? $s[$k] : $default;
}

function set_setting(string $k, string $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    settings_all(true);
}

// ---------------------------------------------------------------- secrets at rest (SMTP password)
function app_key(): string
{
    static $key = null;
    if ($key === null) {
        $f = STORAGE_DIR . '/app.key';
        if (!is_file($f)) {
            if (!is_dir(STORAGE_DIR)) @mkdir(STORAGE_DIR, 0775, true);
            @file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        }
        $hex = is_file($f) ? trim((string)file_get_contents($f)) : '';
        $key = ctype_xdigit($hex) && strlen($hex) === 64 ? hex2bin($hex) : hash('sha256', DB_NAME . DB_USER . DB_PASS, true);
    }
    return $key;
}

function secret_encrypt(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $c = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $c === false ? $plain : 'enc:' . base64_encode($iv . $tag . $c);
}

function secret_decrypt(string $s): string
{
    if (!str_starts_with($s, 'enc:')) return $s;
    $raw = base64_decode(substr($s, 4), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

// ---------------------------------------------------------------- passwords
/** One policy for everyone (users and admins). Returns an error message or null when the password is fine. */
function password_error(string $p): ?string
{
    if (strlen($p) < 8)               return 'Password must be at least 8 characters.';
    if (strlen($p) > 72)              return 'Password can be at most 72 characters.';
    if (!preg_match('/[a-z]/', $p))   return 'Password needs a lowercase letter.';
    if (!preg_match('/[A-Z]/', $p))   return 'Password needs an uppercase letter.';
    if (!preg_match('/[0-9]/', $p))   return 'Password needs a number.';
    if (!preg_match('/[^A-Za-z0-9]/', $p)) return 'Password needs a symbol (e.g. @ # ! $).';
    return null;
}

const PASSWORD_HINT = 'At least 8 characters with upper & lower case, a number and a symbol.';

function random_password(int $len = 12): string
{
    $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '@#$%&*!?'];
    $all = implode('', $sets);
    $out = [];
    foreach ($sets as $s) $out[] = $s[random_int(0, strlen($s) - 1)];
    while (count($out) < $len) $out[] = $all[random_int(0, strlen($all) - 1)];
    for ($i = count($out) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$out[$i], $out[$j]] = [$out[$j], $out[$i]]; }
    return implode('', $out);
}

// ---------------------------------------------------------------- accounts / money
const ACCOUNTS = ['demo', 'inr', 'usdt'];

function demo_on(): bool
{
    return setting('demo_enabled', '1') === '1';
}

/** Accounts users can use right now (Demo disappears when the admin switches it off). */
function accounts_on(): array
{
    return demo_on() ? ACCOUNTS : ['inr', 'usdt'];
}

function acc_meta(string $acc): array
{
    static $m = [
        'demo' => ['label' => 'Demo Account', 'short' => 'Demo', 'sym' => '$', 'cur' => 'DEMO'],
        'inr'  => ['label' => 'INR Account',  'short' => 'INR',  'sym' => '₹', 'cur' => 'INR'],
        'usdt' => ['label' => 'USDT Account', 'short' => 'USDT', 'sym' => '₮', 'cur' => 'USDT'],
    ];
    return $m[$acc] ?? $m['demo'];
}

function money($amount, string $acc, bool $sign = false): string
{
    $a = (float)$amount;
    $s = ($sign && $a > 0 ? '+' : ($a < 0 ? '-' : ''));
    return $s . acc_meta($acc)['sym'] . number_format(abs($a), 2);
}

function dec($n): string
{
    return number_format((float)$n, 4, '.', '');
}

// ---------------------------------------------------------------- time zones (IST / UTC switch)
const TZ_CHOICES = ['IST' => 'Asia/Kolkata', 'UTC' => 'UTC'];

/** Time zone used to show dates: the visitor's IST/UTC choice (cookie), else the site default from Settings. */
function display_tz(): string
{
    $c = $_COOKIE['tz'] ?? '';
    if (isset(TZ_CHOICES[$c])) return TZ_CHOICES[$c];
    $site = (string)setting('timezone', 'Asia/Kolkata');
    return in_array($site, timezone_identifiers_list(), true) ? $site : 'Asia/Kolkata';
}

function tz_short(?string $tz = null): string
{
    $tz = $tz ?? display_tz();
    $k = array_search($tz, TZ_CHOICES, true);
    if ($k !== false) return $k;
    try { return (new DateTime('now', new DateTimeZone($tz)))->format('T'); } catch (Exception $ex) { return $tz; }
}

function display_tz_offset(): int
{
    return (new DateTimeZone(display_tz()))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
}

function fmt_dt(?string $utc, bool $withTz = true): string
{
    if (!$utc) return '—';
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone(display_tz()));
        return $d->format('d M Y, H:i') . ($withTz ? ' ' . tz_short() : '');
    } catch (Exception $ex) {
        return $utc;
    }
}

function fmt_ts(int $ts): string
{
    return fmt_dt(gmdate('Y-m-d H:i:s', $ts));
}

// ---------------------------------------------------------------- user auth
function current_user(bool $fresh = false): ?array
{
    static $u = false;
    if ($u === false || $fresh) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
            if ($u && $u['status'] !== 'active') {
                unset($_SESSION['uid']);
                $u = null;
            }
            if ($u && $u['active_account'] === 'demo' && !demo_on()) {       // admin switched the Demo wallet off
                q('UPDATE users SET active_account = "inr" WHERE id = ?', [$u['id']]);
                $u['active_account'] = 'inr';
            }
        }
    }
    return $u;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => 'Please log in again.', 'login' => true], 401);
        }
        redirect('login.php');
    }
    // optional app lock: ask for the 6-digit PIN once per login
    if (!empty($u['app_lock']) && !empty($u['pin_hash']) && empty($_SESSION['pin_ok']) && !defined('PIN_PAGE')) {
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => 'Enter your PIN to continue.', 'pin' => true], 401);
        }
        redirect('pin.php');
    }
    return $u;
}

function wallets_of(int $uid): array
{
    $out = ['demo' => 0.0, 'inr' => 0.0, 'usdt' => 0.0];
    foreach (q('SELECT type, balance FROM wallets WHERE user_id = ?', [$uid])->fetchAll() as $w) {
        $out[$w['type']] = (float)$w['balance'];
    }
    return $out;
}

function throttle_ok(string $key, int $max = 6, int $window = 300): bool
{
    $now = time();
    $a = array_filter($_SESSION['thr'][$key] ?? [], fn($t) => $t > $now - $window);
    $_SESSION['thr'][$key] = $a;
    return count($a) < $max;
}

function throttle_hit(string $key): void
{
    $_SESSION['thr'][$key][] = time();
}

// ---------------------------------------------------------------- admin auth
function current_admin(): ?array
{
    static $a = false;
    if ($a === false) {
        $a = null;
        if (!empty($_SESSION['admin_id'])) {
            $a = q('SELECT id, name, email, password_hash FROM admins WHERE id = ?', [$_SESSION['admin_id']])->fetch() ?: null;
        }
    }
    return $a;
}

function require_admin(): array
{
    $a = current_admin();
    if (!$a) {
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => 'Admin session expired.', 'login' => true], 401);
        }
        redirect('admin/login.php');
    }
    return $a;
}

function admin_log(string $action, string $detail = ''): void
{
    $a = current_admin();
    q('INSERT INTO admin_logs (admin_id, action, detail, ip) VALUES (?, ?, ?, ?)',
        [$a['id'] ?? 0, $action, substr($detail, 0, 255), client_ip()]);
}

// ---------------------------------------------------------------- one-time database check
function schema_guard(): void
{
    if (defined('IN_INSTALL') || PHP_SAPI === 'cli') return;
    if (($_SESSION['schema_ok'] ?? 0) === SCHEMA_VERSION) return;
    if (schema_installed_version(db()) >= SCHEMA_VERSION) {
        $_SESSION['schema_ok'] = SCHEMA_VERSION;
        return;
    }
    if (is_api_request()) json_out(['ok' => false, 'error' => 'Database needs an upgrade. Open install.php.'], 503);
    redirect('install.php');
}
schema_guard();

require_once __DIR__ . '/market.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/pin.php';
require_once __DIR__ . '/games.php';
require_once __DIR__ . '/kyc.php';
require_once __DIR__ . '/payout.php';
require_once __DIR__ . '/layout.php';
