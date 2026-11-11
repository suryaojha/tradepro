<?php
// Switch the active trading account (demo / inr / usdt) or reset the demo balance.
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}
$u = require_user();
csrf_require();

$action = $_POST['action'] ?? '';
try {
    if ($action === 'switch') {
        $acc = $_POST['account'] ?? '';
        if (!in_array($acc, accounts_on(), true)) {
            json_out(['ok' => false, 'error' => 'Invalid account'], 422);
        }
        q('UPDATE users SET active_account = ? WHERE id = ?', [$acc, $u['id']]);
    } elseif ($action === 'reset_demo') {
        reset_demo((int)$u['id']);
    } else {
        json_out(['ok' => false, 'error' => 'Unknown action'], 422);
    }
    $fresh = current_user(true);
    json_out(['ok' => true, 'active' => $fresh['active_account'], 'balances' => wallets_of((int)$u['id'])]);
} catch (Throwable $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()], 500);
}
