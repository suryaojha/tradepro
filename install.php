<?php
/**
 * One-click installer / upgrader. Creates the database + tables, adds live-market assets and the first admin.
 * Safe to run again at any time (it only adds what is missing). Browser: /install.php   CLI: php install.php
 */
define('IN_INSTALL', true);
require_once __DIR__ . '/includes/bootstrap.php';

$cli = PHP_SAPI === 'cli';
$out = [];
$err = '';
$state = ['mysql' => false, 'db' => false, 'version' => 0, 'admins' => 0];

try {
    $root = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $state['mysql'] = true;
    $has = $root->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $has->execute([DB_NAME]);
    $state['db'] = (bool)$has->fetchColumn();
    if ($state['db']) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $state['version'] = schema_installed_version($pdo);
        if (sch_has_table($pdo, 'admins')) $state['admins'] = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    }
} catch (PDOException $ex) {
    $err = 'Cannot connect to MySQL: ' . $ex->getMessage() . ' — check DB_HOST / DB_USER / DB_PASS in config.php and that MySQL is running.';
}

$needs = $state['version'] < SCHEMA_VERSION;
$isAdmin = false;
if ($state['mysql'] && $state['db'] && $state['admins'] && !$needs) {
    try { $isAdmin = (bool)current_admin(); } catch (Throwable $ex) {}
}
$allowed = $cli || $needs || !$state['admins'] || $isAdmin;

$run = $cli || ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok());
if ($run && $allowed && !$err) {
    try {
        $dbn = '`' . str_replace('`', '``', DB_NAME) . '`';
        $root->exec("CREATE DATABASE IF NOT EXISTS $dbn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $out = schema_install($pdo);
        if (!$cli) {
            $url = (IS_HTTPS ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
            $pdo->prepare("UPDATE settings SET v = ? WHERE k = 'site_url' AND v = ''")->execute([$url]);
            $_SESSION['schema_ok'] = SCHEMA_VERSION;
        }
        $state['version'] = SCHEMA_VERSION;
        $state['admins'] = 1;
        $needs = false;
    } catch (Throwable $ex) {
        $err = 'Install failed: ' . $ex->getMessage();
    }
}

if ($cli) {
    echo $err ? "$err\n" : implode("\n", $out) . "\nDone.\n";
    exit($err ? 1 : 0);
}

$checks = [
    'PHP 8.0 or newer'      => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO MySQL extension'   => extension_loaded('pdo_mysql'),
    'mbstring extension'    => extension_loaded('mbstring'),
    'OpenSSL extension (email / encryption)' => extension_loaded('openssl'),
    'cURL extension (live market data)'      => extension_loaded('curl'),
    'storage/ folder writable' => (is_dir(STORAGE_DIR) || @mkdir(STORAGE_DIR, 0775, true)) && is_writable(STORAGE_DIR),
    'MySQL reachable'       => $state['mysql'],
];
$done = !$needs && $state['admins'] && !$err && $run;
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install · TradePro</title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head><body>
<div class="authwrap"><div class="authbox" style="max-width:520px">
  <a class="logo" href="<?= e(url('')) ?>">TradePro setup</a>
  <p class="sub">Creates the database, live-market assets and the first admin.</p>

  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>

  <?php foreach ($checks as $label => $ok): ?>
    <div class="inline" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)">
      <span><?= e($label) ?></span><span class="badge <?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? 'OK' : 'Missing' ?></span>
    </div>
  <?php endforeach; ?>

  <div style="margin-top:18px">
  <?php if ($done): ?>
    <div class="flash ok">Everything is ready.</div>
    <ul class="muted" style="padding-left:18px"><?php foreach ($out as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
    <p>Admin sign-in: <b><?= e(DEFAULT_ADMIN_EMAIL) ?></b><br><span class="muted">Password: the one set as DEFAULT_ADMIN_PASSWORD in config.php. Change it after your first login.</span></p>
    <a class="btn block" href="<?= e(url('admin/login.php')) ?>">Go to admin login</a>
    <a class="btn ghost block" style="margin-top:8px" href="<?= e(url('')) ?>">Open the site</a>
  <?php elseif (!$allowed): ?>
    <div class="flash info">Already installed and up to date. Sign in as admin to run the installer again.</div>
    <a class="btn block" href="<?= e(url('admin/login.php')) ?>">Admin login</a>
  <?php else: ?>
    <p class="muted"><?php
        if (!$state['db'] || !$state['version']) echo 'Fresh install: the database "' . e(DB_NAME) . '" will be created. ';
        elseif ($needs) echo 'Your database (v' . (int)$state['version'] . ') will be upgraded to v' . SCHEMA_VERSION . '. Existing users, wallets and trades are kept. ';
        else echo 'Re-run: only missing tables, columns and settings are added. ';
    ?></p>
    <form method="post"><?= csrf_field() ?>
      <button class="btn block" type="submit" <?= $state['mysql'] ? '' : 'disabled' ?>><?= $needs || !$state['version'] ? 'Install / upgrade now' : 'Run again' ?></button>
    </form>
  <?php endif; ?>
  </div>
</div></div>
</body></html>
