<?php
/**
 * Real chart data straight from the database (used by admin/stats.php and api/stats.php).
 * Days are grouped in the visitor's time zone (IST / UTC switch).
 */

const STAT_METRICS = [
    'signups'  => 'New users',
    'volume'   => 'Trade volume',
    'pnl'      => 'Profit & loss',
    'results'  => 'Win / loss',
    'cashflow' => 'Deposits & withdrawals',
    'assets'   => 'Top assets',
    'predictions' => 'Up vs down predictions',
    'equity'   => 'Balance history',
];

function stats_days(): array
{
    $tz = new DateTimeZone(display_tz());
    return [$tz, new DateTime('today', $tz)];
}

/** @return array{kind:string,labels:array,datasets:array,sym:string,empty:bool,note:string} */
function stats_series(string $metric, string $acc, int $days, ?int $uid = null): array
{
    $days = max(1, min(366, $days));
    $acc  = in_array($acc, ACCOUNTS, true) ? $acc : 'inr';
    $off  = display_tz_offset();
    [$tz, $today] = stats_days();

    $start = (clone $today)->modify('-' . ($days - 1) . ' days');
    $startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    $startSql = gmdate('Y-m-d H:i:s', $startUtc);

    $labels = [];
    $keys = [];
    for ($i = 0; $i < $days; $i++) {
        $d = (clone $start)->modify("+$i days");
        $keys[] = $d->format('Y-m-d');
        $labels[] = $d->format('d M');
    }
    $fill = function (array $rows) use ($keys): array {
        return array_map(fn($k) => (float)($rows[$k] ?? 0), $keys);
    };
    $out = ['kind' => 'bar', 'labels' => $labels, 'datasets' => [], 'sym' => acc_meta($acc)['sym'], 'empty' => true, 'note' => ''];
    $uidSql = $uid ? ' AND user_id = ' . (int)$uid : '';
    $house = !$uid;   // admin overview shows the house's result, a single user's view shows the user's own

    switch ($metric) {
        case 'signups':
            $rows = q('SELECT DATE(DATE_ADD(created_at, INTERVAL ? SECOND)) d, COUNT(*) n FROM users WHERE created_at >= ? GROUP BY d', [$off, $startSql])->fetchAll(PDO::FETCH_KEY_PAIR);
            $before = (int)q('SELECT COUNT(*) FROM users WHERE created_at < ?', [$startSql])->fetchColumn();
            $new = $fill($rows);
            $tot = [];
            $run = $before;
            foreach ($new as $n) { $run += (int)$n; $tot[] = $run; }
            $out['datasets'] = [
                ['label' => 'New users', 'data' => $new, 'color' => 'accent'],
                ['label' => 'Total users', 'data' => $tot, 'type' => 'line', 'color' => 'green', 'axis' => 'y2'],
            ];
            $out['sym'] = '';
            $out['empty'] = array_sum($new) == 0 && $before == 0;
            break;

        case 'volume':
            $rows = q("SELECT DATE(FROM_UNIXTIME(open_ts + ?)) d, SUM(amount) v FROM trades WHERE open_ts >= ? AND account_type = ? $uidSql GROUP BY d", [$off, $startUtc, $acc])->fetchAll(PDO::FETCH_KEY_PAIR);
            $cnt  = q("SELECT DATE(FROM_UNIXTIME(open_ts + ?)) d, COUNT(*) n FROM trades WHERE open_ts >= ? AND account_type = ? $uidSql GROUP BY d", [$off, $startUtc, $acc])->fetchAll(PDO::FETCH_KEY_PAIR);
            $v = $fill($rows);
            $out['datasets'] = [
                ['label' => 'Volume (' . acc_meta($acc)['short'] . ')', 'data' => $v, 'color' => 'accent'],
                ['label' => 'Trades', 'data' => $fill($cnt), 'type' => 'line', 'color' => 'warn', 'axis' => 'y2', 'plain' => true],
            ];
            $out['empty'] = array_sum($v) == 0;
            break;

        case 'pnl':
            $sign = $house ? -1 : 1;
            $rows = q("SELECT DATE(DATE_ADD(settled_at, INTERVAL ? SECOND)) d, SUM(profit) p FROM trades WHERE status <> 'open' AND settled_at >= ? AND account_type = ? $uidSql GROUP BY d", [$off, $startSql, $acc])->fetchAll(PDO::FETCH_KEY_PAIR);
            $before = (float)q("SELECT COALESCE(SUM(profit),0) FROM trades WHERE status <> 'open' AND settled_at < ? AND account_type = ? $uidSql", [$startSql, $acc])->fetchColumn() * $sign;
            $daily = array_map(fn($x) => $x * $sign, $fill($rows));
            $cum = [];
            $run = $before;
            foreach ($daily as $x) { $run += $x; $cum[] = round($run, 2); }
            $lbl = $house ? 'House P&L' : 'Profit / loss';
            $out['datasets'] = [
                ['label' => $lbl . ' per day', 'data' => array_map(fn($x) => round($x, 2), $daily), 'posneg' => true],
                ['label' => 'Cumulative', 'data' => $cum, 'type' => 'line', 'color' => 'accent', 'axis' => 'y2', 'plain' => true],
            ];
            $out['empty'] = array_sum(array_map('abs', $daily)) == 0 && $before == 0;
            $out['note'] = $house ? 'House result = what users lost minus what was paid to winners.' : '';
            break;

        case 'results':
            $rows = q("SELECT status, COUNT(*) n FROM trades WHERE status <> 'open' AND open_ts >= ? AND account_type = ? $uidSql GROUP BY status", [$startUtc, $acc])->fetchAll(PDO::FETCH_KEY_PAIR);
            $out['kind'] = 'doughnut';
            $out['labels'] = ['Won', 'Lost', 'Draw'];
            $vals = [(int)($rows['won'] ?? 0), (int)($rows['lost'] ?? 0), (int)($rows['draw'] ?? 0)];
            $out['datasets'] = [['label' => 'Trades', 'data' => $vals, 'colors' => ['green', 'red', 'accent']]];
            $out['empty'] = array_sum($vals) == 0;
            $out['sym'] = '';
            break;

        case 'cashflow':
            $rows = q("SELECT DATE(DATE_ADD(processed_at, INTERVAL ? SECOND)) d, type, SUM(amount) s FROM payment_requests WHERE status = 'approved' AND processed_at >= ? AND account_type = ? $uidSql GROUP BY d, type", [$off, $startSql, $acc === 'demo' ? 'none' : $acc])->fetchAll();
            $dep = [];
            $wd = [];
            foreach ($rows as $r) { if ($r['type'] === 'deposit') $dep[$r['d']] = $r['s']; else $wd[$r['d']] = $r['s']; }
            $a = $fill($dep);
            $b = $fill($wd);
            $out['datasets'] = [
                ['label' => 'Deposits', 'data' => $a, 'color' => 'green'],
                ['label' => 'Withdrawals', 'data' => $b, 'color' => 'red'],
            ];
            $out['empty'] = array_sum($a) == 0 && array_sum($b) == 0;
            if ($acc === 'demo') $out['note'] = 'Demo money cannot be deposited or withdrawn.';
            break;

        case 'predictions':
            $rows = q("SELECT DATE(FROM_UNIXTIME(open_ts + ?)) d, direction, COUNT(*) n FROM trades WHERE open_ts >= ? AND account_type = ? $uidSql GROUP BY d, direction", [$off, $startUtc, $acc])->fetchAll();
            $up = [];
            $dn = [];
            foreach ($rows as $r) { if ($r['direction'] === 'up') $up[$r['d']] = $r['n']; else $dn[$r['d']] = $r['n']; }
            $a = $fill($up);
            $b = $fill($dn);
            $out['datasets'] = [
                ['label' => 'Predicted up', 'data' => $a, 'color' => 'green'],
                ['label' => 'Predicted down', 'data' => $b, 'color' => 'red'],
            ];
            $out['sym'] = '';
            $out['empty'] = array_sum($a) + array_sum($b) == 0;
            break;

        case 'assets':
            $rows = q("SELECT a.symbol s, SUM(t.amount) v FROM trades t JOIN assets a ON a.id = t.asset_id WHERE t.open_ts >= ? AND t.account_type = ? " . ($uid ? 'AND t.user_id = ' . (int)$uid : '') . " GROUP BY a.id ORDER BY v DESC LIMIT 8", [$startUtc, $acc])->fetchAll();
            $out['kind'] = 'hbar';
            $out['labels'] = array_column($rows, 's');
            $out['datasets'] = [['label' => 'Volume (' . acc_meta($acc)['short'] . ')', 'data' => array_map('floatval', array_column($rows, 'v')), 'color' => 'accent']];
            $out['empty'] = !$rows;
            break;

        case 'equity':
            if (!$uid) { $out['note'] = 'Pick a user.'; break; }
            $rows = q('SELECT created_at, balance_after FROM transactions WHERE user_id = ? AND account_type = ? AND created_at >= ? ORDER BY id DESC LIMIT 400', [$uid, $acc, $startSql])->fetchAll();
            $rows = array_reverse($rows);
            $out['kind'] = 'line';
            $out['labels'] = array_map(fn($r) => fmt_dt($r['created_at'], false), $rows);
            $out['datasets'] = [['label' => 'Balance (' . acc_meta($acc)['short'] . ')', 'data' => array_map(fn($r) => (float)$r['balance_after'], $rows), 'color' => 'accent', 'fill' => true, 'plain' => true]];
            $out['empty'] = !$rows;
            break;

        default:
            $out['note'] = 'Unknown chart.';
    }
    return $out;
}

/** Shared handler for admin/stats.php and api/stats.php. */
function stats_respond(?int $forceUid): void
{
    $metric = (string)($_GET['metric'] ?? '');
    if (!isset(STAT_METRICS[$metric])) json_out(['ok' => false, 'error' => 'Unknown metric'], 400);
    $uid = $forceUid ?? ((int)($_GET['user'] ?? 0) ?: null);
    json_out(['ok' => true] + stats_series($metric, (string)($_GET['acc'] ?? 'inr'), (int)($_GET['days'] ?? 30), $uid));
}

/** Markup for an expandable chart card (see assets/charts.js). */
function chart_card(string $title, string $metric, string $src, array $opt = []): string
{
    $days = $opt['days'] ?? 30;
    $acc  = $opt['acc'] ?? 'inr';
    $user = $opt['user'] ?? 0;
    $range = $opt['range'] ?? true;
    $h = '<div class="chartcard' . (!empty($opt['class']) ? ' ' . e($opt['class']) : '') . '" data-src="' . e($src) . '" data-metric="' . e($metric) . '" data-acc="' . e($acc) . '" data-days="' . (int)$days . '" data-user="' . (int)$user . '" data-title="' . e($title) . '">'
       . '<div class="chead"><h3>' . e($title) . '</h3><div class="ctools">';
    if ($range) {
        $h .= '<select class="cdays" aria-label="Range">';
        foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $l) {
            $h .= '<option value="' . $d . '"' . ($d === (int)$days ? ' selected' : '') . '>' . $l . '</option>';
        }
        $h .= '</select>';
    }
    $h .= '<button type="button" class="cexp" title="Expand" aria-label="Expand chart"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg></button>'
        . '</div></div><div class="cbody"><canvas></canvas><div class="cmsg">Loading…</div></div><div class="cnote"></div></div>';
    return $h;
}

function charts_assets(): void
{
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
    echo '<script src="' . e(url('assets/charts.js')) . '?v=' . (int)@filemtime(APP_ROOT . '/assets/charts.js') . '"></script>';
}
