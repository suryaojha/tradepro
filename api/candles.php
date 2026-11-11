<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_user();
session_write_close();

$asset = q('SELECT * FROM assets WHERE id = ? AND is_active = 1 AND provider IS NOT NULL', [(int)($_GET['asset'] ?? 0)])->fetch();
$tf = (int)($_GET['tf'] ?? 60);
if (!$asset || !in_array($tf, TIMEFRAMES, true)) {
    json_out(['ok' => false, 'error' => 'Bad request'], 400);
}

json_out([
    'ok'       => true,
    'now'      => time(),
    'decimals' => (int)$asset['decimals'],
    'source'   => $asset['provider'],
    'candles'  => candles($asset, $tf, 150),
]);
