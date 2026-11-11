<?php
/** Profit & loss numbers for users and e-mailed statements. */

/** Profit/loss per account: today, 7 days, 30 days, all time (settled trades only). */
function pnl_summary(int $uid): array
{
    $tz = new DateTimeZone(display_tz());
    $today = gmdate('Y-m-d H:i:s', (new DateTime('today', $tz))->getTimestamp());
    $d7  = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
    $d30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $out = [];
    foreach (ACCOUNTS as $a) $out[$a] = ['today' => 0.0, 'd7' => 0.0, 'd30' => 0.0, 'all' => 0.0, 'n' => 0, 'won' => 0, 'lost' => 0, 'draw' => 0, 'staked' => 0.0];
    $rows = q("SELECT account_type a,
                      COALESCE(SUM(CASE WHEN settled_at >= ? THEN profit ELSE 0 END), 0) d,
                      COALESCE(SUM(CASE WHEN settled_at >= ? THEN profit ELSE 0 END), 0) w,
                      COALESCE(SUM(CASE WHEN settled_at >= ? THEN profit ELSE 0 END), 0) m,
                      COALESCE(SUM(profit), 0) t, COUNT(*) n,
                      COALESCE(SUM(status = 'won'), 0) won, COALESCE(SUM(status = 'lost'), 0) lost, COALESCE(SUM(status = 'draw'), 0) draw,
                      COALESCE(SUM(amount), 0) staked
               FROM trades WHERE user_id = ? AND status <> 'open' GROUP BY account_type", [$today, $d7, $d30, $uid])->fetchAll();
    foreach ($rows as $r) {
        $out[$r['a']] = ['today' => (float)$r['d'], 'd7' => (float)$r['w'], 'd30' => (float)$r['m'], 'all' => (float)$r['t'], 'n' => (int)$r['n'],
                         'won' => (int)$r['won'], 'lost' => (int)$r['lost'], 'draw' => (int)$r['draw'], 'staked' => (float)$r['staked']];
    }
    return $out;
}

/** Profit/loss by market for one account. */
function pnl_by_asset(int $uid, string $acc): array
{
    return q("SELECT a.symbol, COUNT(*) n, SUM(t.status = 'won') won, SUM(t.status = 'lost') lost, SUM(t.amount) staked, SUM(t.profit) pnl
              FROM trades t JOIN assets a ON a.id = t.asset_id
              WHERE t.user_id = ? AND t.account_type = ? AND t.status <> 'open' GROUP BY a.id ORDER BY pnl DESC", [$uid, $acc])->fetchAll();
}

function pnl_class($v): string
{
    return (float)$v > 0 ? 'green' : ((float)$v < 0 ? 'red' : '');
}

/** Small "Today +₹120" text for the account switcher. */
function pnl_chip(array $summary, string $acc): string
{
    $v = $summary[$acc]['today'] ?? 0;
    return '<small class="' . pnl_class($v) . '">Today ' . e(money($v, $acc, true)) . '</small>';
}

function report_csv(array $cols, array $rows): string
{
    $f = fopen('php://temp', 'w+');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $cols);
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            if (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) $v = "'" . $v;
            $line[] = $v;
        }
        fputcsv($f, $line);
    }
    rewind($f);
    $s = stream_get_contents($f);
    fclose($f);
    return $s;
}

/**
 * Build and e-mail an account statement. $acc is 'all' or an account type.
 * @return array [ok, message]
 */
function send_user_report(array $user, int $fromTs, int $toTs, string $acc): array
{
    if (setting('reports_enabled', '1') !== '1') return [false, 'Reports are switched off by the administrator.'];
    if (!mail_ready()) return [false, 'Email is not set up on this site yet, so a report cannot be sent. Please contact support.'];
    $uid = (int)$user['id'];
    if ($fromTs > $toTs) return [false, 'The start date must be before the end date.'];
    if ($toTs - $fromTs > 366 * 86400) return [false, 'Please choose a period of at most one year.'];
    $recent = (int)q('SELECT COUNT(*) FROM mail_log WHERE to_email = ? AND subject LIKE "Your statement%" AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)', [$user['email']])->fetchColumn();
    if ($recent >= 3) return [false, 'You can request up to 3 reports per hour. Please try again later.'];

    $accs = $acc === 'all' ? ACCOUNTS : [$acc];
    $in = implode(',', array_map(fn($a) => db()->quote($a), $accs));
    $trades = q("SELECT t.id, a.symbol, t.account_type, t.direction, t.amount, t.payout_percent, t.open_price, t.close_price, t.status, t.profit,
                        FROM_UNIXTIME(t.open_ts) opened, FROM_UNIXTIME(t.expiry_ts) expiry
                 FROM trades t JOIN assets a ON a.id = t.asset_id
                 WHERE t.user_id = ? AND t.open_ts BETWEEN ? AND ? AND t.account_type IN ($in) ORDER BY t.id", [$uid, $fromTs, $toTs])->fetchAll();
    $ledger = q("SELECT created_at, account_type, type, amount, balance_after, note FROM transactions
                 WHERE user_id = ? AND created_at BETWEEN ? AND ? AND account_type IN ($in) ORDER BY id",
        [$uid, gmdate('Y-m-d H:i:s', $fromTs), gmdate('Y-m-d H:i:s', $toTs)])->fetchAll();

    // per-account summary
    $sum = [];
    foreach ($accs as $a) $sum[$a] = ['n' => 0, 'won' => 0, 'lost' => 0, 'draw' => 0, 'open' => 0, 'staked' => 0.0, 'pnl' => 0.0];
    foreach ($trades as $t) {
        $s = &$sum[$t['account_type']];
        $s['n']++;
        $s['staked'] += (float)$t['amount'];
        if ($t['status'] === 'open') $s['open']++; else { $s[$t['status']]++; $s['pnl'] += (float)$t['profit']; }
        unset($s);
    }
    $dep = [];
    foreach (q("SELECT account_type a, type, SUM(amount) s FROM payment_requests WHERE user_id = ? AND status = 'approved' AND processed_at BETWEEN ? AND ? GROUP BY account_type, type",
        [$uid, gmdate('Y-m-d H:i:s', $fromTs), gmdate('Y-m-d H:i:s', $toTs)])->fetchAll() as $r) $dep[$r['a']][$r['type']] = (float)$r['s'];

    $label = fmt_dt(gmdate('Y-m-d H:i:s', $fromTs), false) . ' → ' . fmt_dt(gmdate('Y-m-d H:i:s', $toTs), false) . ' ' . tz_short();
    $h = '<p>Hi ' . e($user['name']) . ',</p><p>Here is your account statement for <b>' . e($label) . '</b>.</p>';
    $h .= '<table style="border-collapse:collapse;width:100%;font-size:14px"><tr style="background:#f1f4fb;text-align:left"><th style="padding:8px">Account</th><th>Trades</th><th>Won</th><th>Lost</th><th>Draw</th><th style="text-align:right">Staked</th><th style="text-align:right">Profit / loss</th></tr>';
    foreach ($sum as $a => $s) {
        $col = $s['pnl'] > 0 ? '#12a36a' : ($s['pnl'] < 0 ? '#d93a4f' : '#1c2540');
        $h .= '<tr style="border-top:1px solid #e3e8f4"><td style="padding:8px"><b>' . e(acc_meta($a)['short']) . '</b></td><td>' . $s['n'] . '</td><td>' . $s['won'] . '</td><td>' . $s['lost'] . '</td><td>' . $s['draw'] . '</td>'
           . '<td style="text-align:right">' . e(money($s['staked'], $a)) . '</td><td style="text-align:right;color:' . $col . '"><b>' . e(money($s['pnl'], $a, true)) . '</b></td></tr>';
        if ($a !== 'demo' && !empty($dep[$a])) {
            $h .= '<tr><td colspan="7" style="padding:0 8px 8px;color:#6b7690;font-size:12px">Deposits approved ' . e(money($dep[$a]['deposit'] ?? 0, $a)) . ' · Withdrawals approved ' . e(money($dep[$a]['withdraw'] ?? 0, $a)) . '</td></tr>';
        }
    }
    $h .= '</table>';
    $shown = array_slice(array_reverse($trades), 0, 100);
    if ($shown) {
        $h .= '<h3 style="margin:22px 0 8px">Latest ' . count($shown) . ' of ' . count($trades) . ' trades</h3><table style="border-collapse:collapse;width:100%;font-size:13px">'
            . '<tr style="background:#f1f4fb;text-align:left"><th style="padding:6px">#</th><th>Opened</th><th>Market</th><th>Side</th><th style="text-align:right">Stake</th><th>Result</th><th style="text-align:right">P/L</th></tr>';
        foreach ($shown as $t) {
            $col = (float)$t['profit'] > 0 ? '#12a36a' : ((float)$t['profit'] < 0 ? '#d93a4f' : '#1c2540');
            $h .= '<tr style="border-top:1px solid #e3e8f4"><td style="padding:6px">' . (int)$t['id'] . '</td><td>' . e(fmt_dt($t['opened'], false)) . '</td>'
               . '<td>' . e($t['symbol']) . ' (' . e(strtoupper($t['account_type'])) . ')</td><td>' . ($t['direction'] === 'up' ? 'UP' : 'DOWN') . '</td>'
               . '<td style="text-align:right">' . e(money($t['amount'], $t['account_type'])) . '</td><td>' . e($t['status']) . '</td>'
               . '<td style="text-align:right;color:' . $col . '">' . ($t['status'] === 'open' ? '—' : e(money($t['profit'], $t['account_type'], true))) . '</td></tr>';
        }
        $h .= '</table>';
    }
    $h .= '<p style="color:#6b7690;font-size:12px;margin-top:18px">The complete list of trades and transactions is attached as CSV files (opens in Excel / Google Sheets).</p>';

    $csvTrades = report_csv(['id', 'opened', 'expiry', 'symbol', 'account_type', 'direction', 'amount', 'payout_percent', 'open_price', 'close_price', 'status', 'profit'], $trades);
    $csvLedger = report_csv(['created_at', 'account_type', 'type', 'amount', 'balance_after', 'note'], $ledger);
    $stamp = gmdate('Ymd');
    [$ok, $err] = send_mail($user['email'], 'Your statement ' . gmdate('d M Y', $fromTs) . ' – ' . gmdate('d M Y', $toTs),
        mail_html('Your account statement', $h, 'Open my account', abs_url('history.php')), '',
        [["trades-$stamp.csv", 'text/csv', $csvTrades], ["transactions-$stamp.csv", 'text/csv', $csvLedger]]);
    return $ok ? [true, 'Your statement (' . count($trades) . ' trades, ' . count($ledger) . ' transactions) was sent to ' . $user['email'] . '.'] : [false, 'Could not send the email: ' . $err];
}
