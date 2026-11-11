<?php
// Remember the dark / light theme: in a cookie for everyone, and on the profile for signed-in users.
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);
csrf_require();
$t = ($_POST['theme'] ?? '') === 'light' ? 'light' : 'dark';
setcookie('theme', $t, ['expires' => time() + 31536000, 'path' => '/', 'secure' => FORCE_HTTPS_COOKIES, 'samesite' => 'Lax']);
if (!empty($_SESSION['uid'])) {
    q('UPDATE users SET theme = ? WHERE id = ?', [$t, $_SESSION['uid']]);
}
json_out(['ok' => true, 'theme' => $t]);
