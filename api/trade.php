<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}
$u = require_user();
csrf_require();

try {
    $t = place_trade(
        $u,
        (int)($_POST['asset'] ?? 0),
        (string)($_POST['direction'] ?? ''),
        (float)($_POST['amount'] ?? 0),
        (int)($_POST['duration'] ?? 0)
    );
    json_out(['ok' => true, 'trade' => $t, 'balances' => wallets_of((int)$u['id'])]);
} catch (RuntimeException $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('trade: ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Could not place trade. Try again.'], 500);
}
