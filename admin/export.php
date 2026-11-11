<?php
// CSV downloads for the admin: users, trades, requests, transactions.
require_once __DIR__ . '/../includes/bootstrap.php';

require_admin();
session_write_close();

$type = (string)($_GET['type'] ?? '');
$defs = [
    'users' => ['SELECT id, name, email, phone, status, email_verified_at, last_login, created_at FROM users ORDER BY id',
                ['id', 'name', 'email', 'phone', 'status', 'email_verified_at', 'last_login', 'created_at']],
    'trades' => ['SELECT t.id, u.email, a.symbol, t.account_type, t.direction, t.amount, t.payout_percent, t.open_price, t.close_price,
                         FROM_UNIXTIME(t.open_ts) opened, FROM_UNIXTIME(t.expiry_ts) expiry, t.status, t.profit, t.settle_note
                  FROM trades t JOIN users u ON u.id = t.user_id JOIN assets a ON a.id = t.asset_id ORDER BY t.id',
                 ['id', 'email', 'symbol', 'account_type', 'direction', 'amount', 'payout_percent', 'open_price', 'close_price', 'opened', 'expiry', 'status', 'profit', 'settle_note']],
    'requests' => ['SELECT r.id, u.email, r.type, r.account_type, r.amount, r.fee, r.method, r.details, r.status, r.admin_note, r.created_at, r.processed_at
                    FROM payment_requests r JOIN users u ON u.id = r.user_id ORDER BY r.id',
                   ['id', 'email', 'type', 'account_type', 'amount', 'fee', 'method', 'details', 'status', 'admin_note', 'created_at', 'processed_at']],
    'transactions' => ['SELECT x.id, u.email, x.account_type, x.type, x.amount, x.balance_after, x.note, x.created_at
                        FROM transactions x JOIN users u ON u.id = x.user_id ORDER BY x.id',
                       ['id', 'email', 'account_type', 'type', 'amount', 'balance_after', 'note', 'created_at']],
];
if (!isset($defs[$type])) {
    http_response_code(404);
    exit('Unknown export.');
}
[$sql, $cols] = $defs[$type];
admin_log('export', $type);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '-' . gmdate('Ymd-His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");                       // BOM so Excel reads UTF-8
fputcsv($out, $cols);
$st = db()->query($sql);
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    // neutralise spreadsheet formulas in user-supplied text
    foreach ($row as $k => $v) {
        if (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) $row[$k] = "'" . $v;
    }
    fputcsv($out, array_map(fn($c) => $row[$c] ?? '', $cols));
}
fclose($out);
