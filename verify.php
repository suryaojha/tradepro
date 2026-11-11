<?php
require_once __DIR__ . '/includes/bootstrap.php';

$token = (string)($_GET['token'] ?? '');
$row = token_find('verify', $token);
if ($row) {
    q('UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL', [$row['account_id']]);
    token_burn((int)$row['id']);
    flash('ok', 'Email confirmed. You can log in now.');
} else {
    flash('err', 'This confirmation link is invalid or has expired. Log in to request a new one.');
}
redirect('login.php');
