<?php
// Polled once per second by the trading terminal: live prices, balances, open trades, newly settled trades.
require_once __DIR__ . '/../includes/bootstrap.php';

$u = require_user();
session_write_close(); // polling must not hold the session lock

$focus = (int)($_GET['focus'] ?? 0);

$open = q('SELECT t.asset_id FROM trades t WHERE t.user_id = ? AND t.status = "open"', [$u['id']])->fetchAll();

$ids = array_unique(array_merge($focus ? [$focus] : [], array_map('intval', array_column($open, 'asset_id'))));
$quotes = quotes_get($ids);          // live market prices (cached, refreshed from Binance / Yahoo as needed)
settle_due((int)$u['id']);

$open = q('SELECT t.id, t.asset_id, a.symbol, t.direction, t.amount, t.payout_percent, t.open_price, t.open_ts, t.expiry_ts, t.account_type
           FROM trades t JOIN assets a ON a.id = t.asset_id
           WHERE t.user_id = ? AND t.status = "open" ORDER BY t.expiry_ts', [$u['id']])->fetchAll();

$prices = [];
$closed = [];
foreach ($quotes as $id => $qd) {
    $prices[$id] = $qd['price'];
    if ($qd['closed']) $closed[$id] = true;
}

$done = q('SELECT t.id, a.symbol, t.direction, t.amount, t.status, t.profit, t.open_price, t.close_price, t.account_type, t.settle_note
           FROM trades t JOIN assets a ON a.id = t.asset_id
           WHERE t.user_id = ? AND t.status <> "open" AND t.notified = 0 ORDER BY t.id LIMIT 20', [$u['id']])->fetchAll();
if ($done) {
    $nids = implode(',', array_map('intval', array_column($done, 'id')));
    db()->exec("UPDATE trades SET notified = 1 WHERE id IN ($nids)");
}

$recent = q('SELECT t.id, a.symbol, t.direction, t.amount, t.status, t.profit, t.account_type, t.settled_at
             FROM trades t JOIN assets a ON a.id = t.asset_id
             WHERE t.user_id = ? AND t.status <> "open" ORDER BY t.id DESC LIMIT 15', [$u['id']])->fetchAll();

json_out([
    'ok'       => true,
    'sentiment' => $focus ? sentiment($focus) : null,
    'payout'   => asset_payout((array)q('SELECT payout_percent FROM assets WHERE id = ?', [$focus])->fetch()),
    'now'      => time(),
    'prices'   => $prices,
    'closed'   => (object)$closed,
    'balances' => wallets_of((int)$u['id']),
    'active'   => $u['active_account'],
    'open'     => $open,
    'settled'  => $done,
    'recent'   => $recent,
    'enabled'  => setting('trading_enabled', '1') === '1',
]);
