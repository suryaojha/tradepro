<?php
/**
 * Trading engine: wallets, trade placement + settlement on live prices, payment requests.
 * Live prices come from includes/market.php. The only profit lever for the house is the payout %
 * of each asset; nobody can change the result of an individual trade.
 */

function active_assets(): array
{
    return q('SELECT * FROM assets WHERE is_active = 1 AND provider IS NOT NULL ORDER BY sort_order, id')->fetchAll();
}

/** Winning % for a market: its own override if the admin set one, otherwise the global payout %. */
function asset_payout(array $asset): float
{
    if (isset($asset['payout_percent']) && $asset['payout_percent'] !== null && $asset['payout_percent'] !== '') {
        return (float)$asset['payout_percent'];
    }
    return (float)setting('payout_percent', 85);
}

/** What other traders predict on a market over the last 15 minutes (anonymous totals). */
function sentiment(int $assetId): array
{
    $r = q('SELECT COALESCE(SUM(direction = "up"), 0) up, COALESCE(SUM(direction = "down"), 0) dn
            FROM trades WHERE asset_id = ? AND open_ts >= ?', [$assetId, time() - 900])->fetch();
    $up = (int)$r['up'];
    $dn = (int)$r['dn'];
    return ['up' => $up, 'down' => $dn, 'total' => $up + $dn];
}

function durations(): array
{
    $d = array_filter(array_map('intval', explode(',', (string)setting('durations', '60'))), fn($x) => $x >= 5);
    sort($d);
    return array_values(array_unique($d)) ?: [60];
}

function fmt_duration(int $s): string
{
    if ($s < 60) return $s . 's';
    if ($s % 3600 === 0) return ($s / 3600) . 'h';
    if ($s % 60 === 0) return ($s / 60) . 'm';
    return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
}

/** 1 USDT in INR. Live USD/INR market rate by default, or the manual rate set by the admin. */
function usdt_rate(): float
{
    if (setting('usdt_rate_mode', 'auto') === 'auto') {
        $r = live_usdinr();
        if ($r) return round($r, 4);
    }
    return (float)setting('usdt_inr_rate', 0);
}

// ---------------------------------------------------------------- wallets
/**
 * Atomically change a wallet balance and write a ledger row. Throws if the balance would go negative.
 * Call inside a DB transaction when combined with other writes.
 */
function wallet_adjust(int $uid, string $acc, float $delta, string $txType, ?int $ref = null, ?string $note = null): float
{
    $d = dec($delta);
    $st = q('UPDATE wallets SET balance = balance + ? WHERE user_id = ? AND type = ? AND balance + ? >= 0', [$d, $uid, $acc, $d]);
    if ($st->rowCount() !== 1) {
        throw new RuntimeException('Insufficient balance');
    }
    $bal = (float)q('SELECT balance FROM wallets WHERE user_id = ? AND type = ?', [$uid, $acc])->fetchColumn();
    q('INSERT INTO transactions (user_id, account_type, type, amount, balance_after, ref_id, note) VALUES (?,?,?,?,?,?,?)',
        [$uid, $acc, $txType, $d, dec($bal), $ref, $note]);
    return $bal;
}

function register_user(string $name, string $email, ?string $phone, string $password, bool $verified = true): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO users (name, email, phone, password_hash, email_verified_at) VALUES (?,?,?,?,?)',
            [$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $verified ? gmdate('Y-m-d H:i:s') : null]);
        $uid = (int)$pdo->lastInsertId();
        foreach (ACCOUNTS as $a) {
            q('INSERT INTO wallets (user_id, type, balance) VALUES (?,?,0)', [$uid, $a]);
        }
        $demo = demo_on() ? (float)setting('demo_balance', 0) : 0.0;
        if ($demo > 0) {
            wallet_adjust($uid, 'demo', $demo, 'demo_reset', null, 'Demo balance credited');
        }
        $pdo->commit();
        return $uid;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function reset_demo(int $uid): void
{
    if (!demo_on()) throw new RuntimeException('The Demo wallet is switched off.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = (float)q('SELECT balance FROM wallets WHERE user_id = ? AND type = "demo" FOR UPDATE', [$uid])->fetchColumn();
        $target = (float)setting('demo_balance', 0);
        if (abs($target - $cur) > 0.00005) {
            wallet_adjust($uid, 'demo', $target - $cur, 'demo_reset', null, 'Demo balance reset');
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

// ---------------------------------------------------------------- trades
function trade_limits(string $acc): array
{
    return [(float)setting("min_trade_$acc", 1), (float)setting("max_trade_$acc", 1000000)];
}

function place_trade(array $user, int $assetId, string $dir, float $amount, int $duration): array
{
    if (setting('trading_enabled', '1') !== '1') {
        throw new RuntimeException('Trading is temporarily disabled.');
    }
    if (!in_array($dir, ['up', 'down'], true)) {
        throw new RuntimeException('Invalid direction.');
    }
    if (!in_array($duration, durations(), true)) {
        throw new RuntimeException('Invalid expiry time.');
    }
    $asset = q('SELECT * FROM assets WHERE id = ? AND is_active = 1 AND provider IS NOT NULL', [$assetId])->fetch();
    if (!$asset) {
        throw new RuntimeException('Asset is not available.');
    }
    $acc = $user['active_account'];
    if ($acc === 'demo' && !demo_on()) {
        throw new RuntimeException('The Demo account is switched off. Switch to your INR or USDT account.');
    }
    [$min, $max] = trade_limits($acc);
    $amount = round($amount, 2);
    if ($amount < $min || $amount > $max) {
        throw new RuntimeException('Amount must be between ' . money($min, $acc) . ' and ' . money($max, $acc) . '.');
    }
    $openCount = (int)q('SELECT COUNT(*) FROM trades WHERE user_id = ? AND status = "open"', [$user['id']])->fetchColumn();
    if ($openCount >= (int)setting('max_open_trades', 20)) {
        throw new RuntimeException('Too many open trades. Wait for some to expire.');
    }

    $qd = asset_quote($asset, true);                       // fresh live price from the market API
    if (!$qd) {
        throw new RuntimeException('Live price is unavailable right now. Please try again in a moment.');
    }
    if ($qd['closed']) {
        throw new RuntimeException($asset['symbol'] . ' market is closed right now.');
    }
    $now = time();
    $open = number_format($qd['price'], 6, '.', '');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO trades (user_id, asset_id, account_type, direction, amount, payout_percent, open_price, open_ts, expiry_ts)
           VALUES (?,?,?,?,?,?,?,?,?)',
            [$user['id'], $asset['id'], $acc, $dir, dec($amount), number_format(asset_payout($asset), 2, '.', ''), $open, $now, $now + $duration]);
        $tid = (int)$pdo->lastInsertId();
        wallet_adjust((int)$user['id'], $acc, -$amount, 'trade_open', $tid, $asset['symbol'] . ' ' . strtoupper($dir));
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['id' => $tid, 'open_price' => (float)$open, 'open_ts' => $now, 'expiry_ts' => $now + $duration];
}

/**
 * The real price at the expiry second.
 * @return array|null ['price' => float, 'note' => ?string]  |  ['void' => reason]  |  null (not available yet, retry)
 */
function settle_price(array $asset, int $expiry): ?array
{
    $id = (int)$asset['id'];
    $stale = max(30, (int)setting('stale_secs', 900));
    $find = fn() => q('SELECT price, ts, qts FROM price_ticks WHERE asset_id = ? AND ts >= ? AND ts <= ? ORDER BY ts LIMIT 1',
        [$id, $expiry, $expiry + 30])->fetch();

    // Binance publishes a candle for every second, so the exact price at the expiry second is the fairest result.
    if ($asset['provider'] === 'binance' && time() >= $expiry + 2) {
        $p = binance_second_price($asset['source_symbol'], $expiry);
        if ($p !== null) return ['price' => $p, 'note' => null];
    }

    $tick = $find();
    if (!$tick) {
        quotes_get([$id]);                                  // triggers a fresh quote -> tick recorded at/after expiry
        $tick = $find();
    }
    if ($tick) {
        if ($asset['provider'] !== 'binance' && (int)$tick['ts'] - (int)$tick['qts'] > $stale) {
            return ['void' => 'Market closed at expiry - stake refunded'];
        }
        return ['price' => (float)$tick['price'], 'note' => null];
    }
    if (time() - $expiry <= 30) return null;                // give the live feed a few more seconds

    $h = history_price($asset, $expiry);                    // nobody was online: use the market's own history
    if ($h !== null) return ['price' => $h, 'note' => 'Settled from market history'];
    if (time() - $expiry > 900) return ['void' => 'Price unavailable - stake refunded'];
    return null;
}

function settle_trade(int $id): void
{
    $t = q('SELECT * FROM trades WHERE id = ?', [$id])->fetch();
    if (!$t || $t['status'] !== 'open' || (int)$t['expiry_ts'] > time()) return;
    $asset = q('SELECT * FROM assets WHERE id = ?', [$t['asset_id']])->fetch();
    if (!$asset) return;

    $res = settle_price($asset, (int)$t['expiry_ts']);      // network work happens before the DB transaction
    if ($res === null) return;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $t = q('SELECT * FROM trades WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$t || $t['status'] !== 'open') {
            $pdo->rollBack();
            return;
        }
        $amt   = (float)$t['amount'];
        $note  = $res['note'] ?? null;
        $close = null;

        if (isset($res['void'])) {
            $status = 'draw';
            $profit = 0.0;
            $note   = $res['void'];
        } else {
            $close = $res['price'];
            $open  = (float)$t['open_price'];
            if (abs($close - $open) < 1e-9) {
                $status = 'draw';
                $profit = 0.0;
            } elseif (($t['direction'] === 'up') === ($close > $open)) {
                $status = 'won';
                $profit = round($amt * (float)$t['payout_percent'] / 100, 4);
            } else {
                $status = 'lost';
                $profit = -$amt;
            }
        }

        q('UPDATE trades SET status = ?, close_price = ?, profit = ?, settle_note = ?, settled_at = NOW() WHERE id = ?',
            [$status, $close === null ? null : number_format($close, 6, '.', ''), dec($profit), $note, $id]);

        if ($status === 'won') {
            wallet_adjust((int)$t['user_id'], $t['account_type'], $amt + $profit, 'trade_win', $id, $asset['symbol'] . ' win');
        } elseif ($status === 'draw') {
            wallet_adjust((int)$t['user_id'], $t['account_type'], $amt, 'trade_refund', $id, $asset['symbol'] . ' ' . ($note ?: 'draw'));
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function settle_due(?int $uid = null): void
{
    $sql = 'SELECT id FROM trades WHERE status = "open" AND expiry_ts <= ?';
    $args = [time()];
    if ($uid !== null) {
        $sql .= ' AND user_id = ?';
        $args[] = $uid;
    }
    $sql .= ' ORDER BY expiry_ts LIMIT 200';
    foreach (q($sql, $args)->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            settle_trade((int)$id);
        } catch (Throwable $ex) {
            error_log('settle_trade ' . $id . ': ' . $ex->getMessage());
        }
    }
}

// ---------------------------------------------------------------- deposits / withdrawals
function create_deposit(int $uid, string $acc, float $amount, string $method, string $ref): int
{
    q('INSERT INTO payment_requests (user_id, type, account_type, amount, method, details) VALUES (?,?,?,?,?,?)',
        [$uid, 'deposit', $acc, dec($amount), $method, $ref]);
    $id = (int)db()->lastInsertId();
    notify_request_admins($id);
    return $id;
}

function create_withdrawal(int $uid, string $acc, float $amount, float $fee, string $method, string $dest): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO payment_requests (user_id, type, account_type, amount, fee, method, details) VALUES (?,?,?,?,?,?,?)',
            [$uid, 'withdraw', $acc, dec($amount), dec($fee), $method, $dest]);
        $id = (int)$pdo->lastInsertId();
        wallet_adjust($uid, $acc, -$amount, 'withdraw', $id, 'Withdrawal requested');
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    notify_request_admins($id);
    return $id;
}

function notify_request_admins(int $id): void
{
    try {
        $r = q('SELECT r.*, u.email, u.name FROM payment_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ?', [$id])->fetch();
        if (!$r) return;
        $what = ucfirst($r['type']);
        notify_admins("New $what request #$id",
            '<p><b>' . e($r['name']) . '</b> (' . e($r['email']) . ') requested a ' . e($r['type']) . ' of <b>' . e(money($r['amount'], $r['account_type'])) . '</b> via ' . e(strtoupper($r['method'])) . '.</p>'
            . '<p>Reference / destination: ' . e($r['details']) . '</p>',
            'Review request', abs_url('admin/requests.php'));
    } catch (Throwable $ex) {
        error_log('notify: ' . $ex->getMessage());
    }
}

function process_request(int $id, bool $approve, string $note = '', ?float $creditAmount = null): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = q('SELECT * FROM payment_requests WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$r || $r['status'] !== 'pending') {
            throw new RuntimeException('Request already processed.');
        }
        $uid = (int)$r['user_id'];
        $amt = (float)$r['amount'];

        if ($r['type'] === 'deposit' && $approve) {
            if ($creditAmount !== null && $creditAmount > 0) {
                $amt = round($creditAmount, 4);
                q('UPDATE payment_requests SET amount = ? WHERE id = ?', [dec($amt), $id]);
            }
            wallet_adjust($uid, $r['account_type'], $amt, 'deposit', $id, strtoupper($r['method']) . ' ' . $r['details']);
        } elseif ($r['type'] === 'withdraw' && !$approve) {
            wallet_adjust($uid, $r['account_type'], $amt, 'withdraw_refund', $id, 'Withdrawal rejected');
        }
        q('UPDATE payment_requests SET status = ?, admin_note = ?, processed_at = NOW() WHERE id = ?',
            [$approve ? 'approved' : 'rejected', substr($note, 0, 255), $id]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    try {   // tell the user
        $u = q('SELECT name, email FROM users WHERE id = ?', [$r['user_id']])->fetch();
        if ($u && mail_ready()) {
            $word = $approve ? 'approved' : 'rejected';
            $body = '<p>Hi ' . e($u['name']) . ',</p><p>Your ' . e($r['type']) . ' request #' . $id . ' for <b>' . e(money($amt, $r['account_type'])) . '</b> was <b>' . $word . '</b>.</p>'
                . ($note !== '' ? '<p>Note from support: ' . e($note) . '</p>' : '');
            send_mail($u['email'], ucfirst($r['type']) . " request #$id $word", mail_html(ucfirst($r['type']) . ' ' . $word, $body, 'Open wallet', abs_url('wallet.php')));
        }
    } catch (Throwable $ex) {
        error_log('notify user: ' . $ex->getMessage());
    }
}

function convert_funds(int $uid, string $from, string $to, float $amount): float
{
    $rate = usdt_rate();
    $fee  = (float)setting('convert_fee_percent', 0);
    if ($rate <= 0 || $from === $to || !in_array($from, ['inr', 'usdt']) || !in_array($to, ['inr', 'usdt'])) {
        throw new RuntimeException('Conversion not available right now.');
    }
    $got = $from === 'usdt' ? $amount * $rate : $amount / $rate;
    $got = round($got * (1 - $fee / 100), 4);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        wallet_adjust($uid, $from, -$amount, 'convert_out', null, "Converted to " . strtoupper($to) . " @ " . number_format($rate, 2));
        wallet_adjust($uid, $to, $got, 'convert_in', null, "Converted from " . strtoupper($from) . " @ " . number_format($rate, 2));
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $got;
}
