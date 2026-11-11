<?php
// ---- Database ---------------------------------------------------------
// Only server-specific values live here. Everything else (site name, payouts, payment
// details, SMTP, limits, markets ...) is edited in the Admin panel and stored in the database.
// Values can also come from environment variables (TP_DB_HOST, TP_DB_NAME, TP_DB_USER, TP_DB_PASS).
define('DB_HOST', getenv('TP_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('TP_DB_NAME') ?: 'tradepro');
define('DB_USER', getenv('TP_DB_USER') ?: 'root');
define('DB_PASS', getenv('TP_DB_PASS') !== false ? getenv('TP_DB_PASS') : '');

// ---- First admin ------------------------------------------------------
// Used ONLY by install.php when the database has no admin yet. After you sign in, change the
// password in Admin > Settings > My admin account (this value is not used again).
const DEFAULT_ADMIN_NAME     = 'Admin';
const DEFAULT_ADMIN_EMAIL    = 'admin@example.com';
const DEFAULT_ADMIN_PASSWORD = 'ChangeMe@123';

// ---- Environment (auto-detected, nothing to edit) ---------------------
date_default_timezone_set('UTC');

$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
define('IS_HTTPS', $__https);
define('FORCE_HTTPS_COOKIES', $__https);

// URL folder the app is installed in (works in a sub-folder like /tp).
$__dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$__dir = preg_replace('#/(admin|api)$#', '', $__dir);
define('BASE_URL', rtrim($__dir, '/'));
define('APP_ROOT', __DIR__);
define('STORAGE_DIR', __DIR__ . '/storage');
unset($__dir, $__https);
