<?php
// ---- Database ---------------------------------------------------------
// Keep credentials in the hosting provider's environment-variable settings.
// Never commit database passwords to this public repository.
// Supports TradePro's TP_* names and Wasmer's native DB_* names.
define('DB_HOST', getenv('TP_DB_HOST') ?: (getenv('DB_HOST') ?: 'localhost'));
define('DB_NAME', getenv('TP_DB_NAME') ?: (getenv('DB_NAME') ?: 'tradepro'));
define('DB_USER', getenv('TP_DB_USER') ?: (getenv('DB_USERNAME') ?: (getenv('DB_USER') ?: 'root')));
define('DB_PASS', getenv('TP_DB_PASS') !== false
    ? getenv('TP_DB_PASS')
    : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') ?: '')));
define('DB_PORT', (int) (getenv('TP_DB_PORT') ?: (getenv('DB_PORT') ?: 3306)));

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
$__dir = str_replace('\\\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$__dir = preg_replace('#/(admin|api)$#', '', $__dir);
define('BASE_URL', rtrim($__dir, '/'));
define('APP_ROOT', __DIR__);
define('STORAGE_DIR', __DIR__ . '/storage');
unset($__dir, $__https);
