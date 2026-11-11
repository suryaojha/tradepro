<?php
/**
 * Entertainment games: Colour Game (red / green / violet) and Lucky Number (Kalyan-style open / close market).
 *
 * Rules that are enforced in code:
 *  - They are played ONLY with the Demo wallet (virtual coins). Real INR / USDT balances are never touched.
 *  - Results come from the server's cryptographically secure random generator (random_int). Nobody - not an
 *    admin, not a user - can pick or change a result. Admin controls only on/off, schedules, limits and multipliers.
 */

/** The games are played with Demo coins only, so they need the Demo wallet to be switched on. */
function games_on(): bool { return setting('games_enabled', '1') === '1' && demo_on(); }
function cg_on(): bool    { return games_on() && setting('cg_enabled', '1') === '1'; }
function mk_on(): bool    { return games_on() && setting('mk_enabled', '1') === '1'; }

function game_tz(): DateTimeZone
{
    $z = (string)setting('timezone', 'Asia/Kolkata');
    return new DateTimeZone(in_array($z, timezone_identifiers_list(), true) ? $z : 'Asia/Kolkata');
}

function mult(string $key, float $default): float
{
    $v = (float)setting($key, $default);
    return $v > 0 ? $v : $default;
}

// =============================================================== PIN gate
function games_unlocked(): bool
{
    if (setting('games_pin_required', '1') !== '1') return true;
    return !empty($_SESSION['games_ok']) && $_SESSION['games_ok'] > time();
}

/** Shows the PIN screen (and stops) until the user has entered the 6-digit PIN for this session. */
function games_gate(array $u, string $active = 'games.php'): void
{
    if (!games_on()) {
        page_head('Games', 'has-mnav');
        user_topbar($u, $active);
        echo '<div class="container"><div class="card center"><h2>Games are switched off</h2><p class="muted">' . (demo_on() ? 'Please check back later.' : 'The games use Demo coins, and the Demo wallet is switched off.') . '</p></div></div></body></html>';
        exit;
    }
    if (games_unlocked()) return;

    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'games_unlock') {
        csrf_require();
        $err = pin_check((int)$u['id'], (string)($_POST['pin'] ?? '')) ?? '';
        if ($err === '') {
            $_SESSION['games_ok'] = time() + 1800;
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('games.php')));
            exit;
        }
    }
    page_head('Enter PIN', 'has-mnav');
    user_topbar($u, $active);
    echo '<div class="container"><div class="card" style="max-width:420px;margin:30px auto;text-align:center"><h2>Enter your PIN to play</h2>';
    echo '<p class="muted">The games section is protected by your 6-digit security PIN.</p>';
    if (!pin_is_set($u)) {
        echo '<div class="flash info">You have not set a PIN yet.</div><a class="btn green block" href="' . e(url('profile.php#pin')) . '">Set my PIN</a>';
    } else {
        if ($err) echo '<div class="flash err">' . e($err) . '</div>';
        echo '<form method="post" id="pinForm" data-noload>' . csrf_field() . '<input type="hidden" name="action" value="games_unlock">'
            . '<div style="display:flex;justify-content:center">' . pin_input('pin', '') . '</div><button class="btn block" type="submit">Unlock games</button></form>'
            . '<script>document.addEventListener("pin-complete",function(){document.getElementById("pinForm").submit();});</script>';
    }
    echo '</div></div></body></html>';
    exit;
}

// =============================================================== COLOUR GAME
const CG_COLORS = [0 => ['red', 'violet'], 1 => ['green'], 2 => ['red'], 3 => ['green'], 4 => ['red'], 5 => ['green', 'violet'],
                   6 => ['red'], 7 => ['green'], 8 => ['red'], 9 => ['green']];

function cg_modes(): array
{
    $m = array_filter(array_map('intval', explode(',', (string)setting('cg_modes', '60,180,300'))), fn($s) => $s >= 30 && $s <= 3600 && 86400 % $s === 0);
    $m = array_values(array_unique($m));
    sort($m);
    return $m ?: [60];
}

function cg_day_start(int $ts): int
{
    return (new DateTime('@' . $ts))->setTimezone(game_tz())->setTime(0, 0, 0)->getTimestamp();
}

/** [start, end, period number] of the round that contains $ts. */
function cg_bounds(int $secs, int $ts): array
{
    $ds = cg_day_start($ts);
    $seq = intdiv($ts - $ds, $secs) + 1;
    $start = $ds + ($seq - 1) * $secs;
    $date = (new DateTime('@' . $ds))->setTimezone(game_tz())->format('Ymd');
    return [$start, $start + $secs, $date . str_pad((string)$seq, 4, '0', STR_PAD_LEFT)];
}

function cg_round_id(int $secs, int $start): int
{
    [$s, $e, $p] = cg_bounds($secs, $start);
    q('INSERT IGNORE INTO cg_rounds (secs, start_ts, end_ts, period_no) VALUES (?,?,?,?)', [$secs, $s, $e, $p]);
    return (int)q('SELECT id FROM cg_rounds WHERE secs = ? AND start_ts = ?', [$secs, $s])->fetchColumn();
}

/** Make sure the current round and the last few finished ones exist, then settle everything that ended. */
function cg_sync(int $secs): void
{
    $now = time();
    [$cur] = cg_bounds($secs, $now);
    $last = (int)q('SELECT COALESCE(MAX(start_ts), 0) FROM cg_rounds WHERE secs = ?', [$secs])->fetchColumn();
    for ($s = max($last + $secs, $cur - 20 * $secs); $s <= $cur; $s += $secs) cg_round_id($secs, $s);
    cg_settle_due();
}

function cg_settle_due(): void
{
    foreach (q('SELECT id FROM cg_rounds WHERE settled_at IS NULL AND end_ts <= ? ORDER BY id LIMIT 60', [time()])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { cg_settle_round((int)$id); } catch (Throwable $ex) { error_log('cg_settle ' . $id . ': ' . $ex->getMessage()); }
    }
}

/** Total amount returned for a winning bet (0 when it loses). */
function cg_return(string $kind, string $pick, int $result, float $amount): float
{
    if ($kind === 'number') return (string)$result === $pick ? round($amount * mult('cg_mult_number', 9), 4) : 0.0;
    $colors = CG_COLORS[$result];
    if (!in_array($pick, $colors, true)) return 0.0;
    if ($pick === 'violet') return round($amount * mult('cg_mult_violet', 4.5), 4);
    // red / green: reduced return when the number also carries violet (0 and 5)
    return round($amount * (count($colors) === 2 ? mult('cg_mult_half', 1.5) : mult('cg_mult_color', 2)), 4);
}

function cg_settle_round(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = q('SELECT * FROM cg_rounds WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$r || $r['settled_at'] !== null || (int)$r['end_ts'] > time()) { $pdo->rollBack(); return; }
        $result = random_int(0, 9);                    // CSPRNG - the only source of results
        q('UPDATE cg_rounds SET result = ?, settled_at = NOW() WHERE id = ?', [$result, $id]);
        foreach (q("SELECT * FROM cg_bets WHERE round_id = ? AND status = 'open' FOR UPDATE", [$id])->fetchAll() as $b) {
            $ret = cg_return($b['kind'], $b['pick'], $result, (float)$b['amount']);
            q('UPDATE cg_bets SET status = ?, payout = ? WHERE id = ?', [$ret > 0 ? 'won' : 'lost', dec($ret), $b['id']]);
            if ($ret > 0) wallet_adjust((int)$b['user_id'], 'demo', $ret, 'game_win', (int)$b['id'], 'Colour game ' . $r['period_no']);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function cg_place(array $u, int $secs, string $kind, string $pick, float $amount): array
{
    if (!cg_on()) throw new RuntimeException('The colour game is switched off.');
    if (!in_array($secs, cg_modes(), true)) throw new RuntimeException('Unknown game mode.');
    if ($kind === 'color' && !in_array($pick, ['red', 'green', 'violet'], true)) throw new RuntimeException('Pick red, green or violet.');
    if ($kind === 'number' && !preg_match('/^[0-9]$/', $pick)) throw new RuntimeException('Pick a number from 0 to 9.');
    if (!in_array($kind, ['color', 'number'], true)) throw new RuntimeException('Invalid bet.');
    $amount = round($amount, 2);
    $min = (float)setting('cg_bet_min', 10);
    $max = (float)setting('cg_bet_max', 5000);
    if ($amount < $min || $amount > $max) throw new RuntimeException('Bet must be between ' . money($min, 'demo') . ' and ' . money($max, 'demo') . '.');

    $now = time();
    [$start, $end, $period] = cg_bounds($secs, $now);
    if ($end - $now <= max(2, (int)setting('cg_lock_secs', 5))) throw new RuntimeException('Betting for this period is closed – wait for the next one.');
    $rid = cg_round_id($secs, $start);
    if ((int)q('SELECT COUNT(*) FROM cg_bets WHERE round_id = ? AND user_id = ?', [$rid, $u['id']])->fetchColumn() >= 30) {
        throw new RuntimeException('Maximum 30 bets per period.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO cg_bets (round_id, user_id, kind, pick, amount) VALUES (?,?,?,?,?)', [$rid, $u['id'], $kind, $pick, dec($amount)]);
        $bid = (int)$pdo->lastInsertId();
        $bal = wallet_adjust((int)$u['id'], 'demo', -$amount, 'game_bet', $bid, 'Colour game ' . $period . ' ' . $pick);
        $pdo->commit();
    } catch (RuntimeException $ex) {
        $pdo->rollBack();
        throw new RuntimeException($ex->getMessage() === 'Insufficient balance' ? 'Not enough Demo coins. Reset your demo balance from the account menu.' : $ex->getMessage());
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['bet' => $bid, 'period' => $period, 'balance' => $bal];
}

/** One page of settled rounds for a mode. */
function cg_results_page(int $secs, int $page, int $per = 10): array
{
    $total = (int)q('SELECT COUNT(*) FROM cg_rounds WHERE secs = ? AND settled_at IS NOT NULL', [$secs])->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($page, $pages));
    $rows = q('SELECT period_no, result FROM cg_rounds WHERE secs = ? AND settled_at IS NOT NULL ORDER BY start_ts DESC LIMIT ' . (int)$per . ' OFFSET ' . (($page - 1) * $per), [$secs])->fetchAll();
    return ['page' => $page, 'pages' => $pages, 'total' => $total,
            'rows' => array_map(fn($h) => ['period' => $h['period_no'], 'n' => (int)$h['result'], 'colors' => CG_COLORS[(int)$h['result']]], $rows)];
}

/** One page of the user's own colour bets (all round lengths). */
function cg_mine_page(int $uid, int $page, int $per = 10): array
{
    $total = (int)q('SELECT COUNT(*) FROM cg_bets WHERE user_id = ?', [$uid])->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($page, $pages));
    $rows = q('SELECT b.id, b.kind, b.pick, b.amount, b.status, b.payout, r.period_no, r.result, r.secs
               FROM cg_bets b JOIN cg_rounds r ON r.id = b.round_id WHERE b.user_id = ? ORDER BY b.id DESC LIMIT ' . (int)$per . ' OFFSET ' . (($page - 1) * $per), [$uid])->fetchAll();
    return ['page' => $page, 'pages' => $pages, 'total' => $total,
            'rows' => array_map(fn($b) => ['id' => (int)$b['id'], 'kind' => $b['kind'], 'pick' => $b['pick'], 'amount' => (float)$b['amount'], 'status' => $b['status'],
                'payout' => (float)$b['payout'], 'period' => $b['period_no'], 'secs' => (int)$b['secs'], 'result' => $b['result'] === null ? null : (int)$b['result']], $rows)];
}

/** Tab bar shown on every page of the Play section so players always know where they are. */
function play_nav(string $active): void
{
    $items = ['hub' => ['games.php', 'Overview', '🏠'], 'cg' => ['colorgame.php', 'Colour Game', '🎨'], 'mk' => ['matka.php', 'Lucky Number', '🔢'],
              'tx' => ['playhistory.php?v=tx', 'Transactions', '🧾'], 'win' => ['playhistory.php?v=win', 'My winnings', '🏆']];
    echo '<nav class="playbar" aria-label="Play section"><span class="playlabel">PLAY</span>';
    foreach ($items as $k => [$href, $label, $ic]) {
        echo '<a href="' . e(url($href)) . '" class="' . ($active === $k ? 'on' : '') . '"><i>' . $ic . '</i>' . e($label) . '</a>';
    }
    echo '<span class="nofin playsafe" title="Games use virtual Demo coins only">✔ No financial loss · Demo coins</span></nav>';
}

function cg_state(int $secs, int $uid): array
{
    cg_sync($secs);
    $now = time();
    [$start, $end, $period] = cg_bounds($secs, $now);
    $hist = q('SELECT period_no, result FROM cg_rounds WHERE secs = ? AND settled_at IS NOT NULL ORDER BY start_ts DESC LIMIT 20', [$secs])->fetchAll();
    $mine = q('SELECT b.id, b.kind, b.pick, b.amount, b.status, b.payout, r.period_no, r.result, r.secs
               FROM cg_bets b JOIN cg_rounds r ON r.id = b.round_id WHERE b.user_id = ? ORDER BY b.id DESC LIMIT 20', [$uid])->fetchAll();
    return [
        'now' => $now, 'secs' => $secs, 'period' => $period, 'start' => $start, 'end' => $end,
        'lock' => max(2, (int)setting('cg_lock_secs', 5)),
        'history' => array_map(fn($h) => ['period' => $h['period_no'], 'n' => (int)$h['result'], 'colors' => CG_COLORS[(int)$h['result']]], $hist),
        'mine' => array_map(fn($b) => ['id' => (int)$b['id'], 'kind' => $b['kind'], 'pick' => $b['pick'], 'amount' => (float)$b['amount'], 'status' => $b['status'],
                                        'payout' => (float)$b['payout'], 'period' => $b['period_no'], 'result' => $b['result'] === null ? null : (int)$b['result']], $mine),
        'balance' => wallets_of($uid)['demo'],
    ];
}

// =============================================================== LUCKY NUMBER (Kalyan-style open / close market)
/** All 220 valid pannas (three digits in non-decreasing order, 0 counting as 10). */
function mk_pannas(): array
{
    static $list = null;
    if ($list === null) {
        $k = fn($d) => $d === 0 ? 10 : $d;
        $list = [];
        for ($a = 0; $a <= 9; $a++) for ($b = 0; $b <= 9; $b++) for ($c = 0; $c <= 9; $c++) {
            if ($k($a) <= $k($b) && $k($b) <= $k($c)) $list[] = $a . $b . $c;
        }
    }
    return $list;
}

function mk_digit(string $panna): int
{
    return ((int)$panna[0] + (int)$panna[1] + (int)$panna[2]) % 10;
}

function mk_today(?int $ts = null): string
{
    return (new DateTime('@' . ($ts ?? time())))->setTimezone(game_tz())->format('Y-m-d');
}

/** [open timestamp, close timestamp] of a market on a day (Y-m-d). */
function mk_times(array $m, string $day): array
{
    $tz = game_tz();
    return [(new DateTime($day . ' ' . $m['open_time'] . ':00', $tz))->getTimestamp(), (new DateTime($day . ' ' . $m['close_time'] . ':00', $tz))->getTimestamp()];
}

function mk_runs_on(array $m, string $day): bool
{
    $dow = (int)(new DateTime($day . ' 12:00:00', game_tz()))->format('N');
    return in_array((string)$dow, explode(',', (string)$m['days']), true);
}

function mk_ensure(int $marketId, string $day): array
{
    q('INSERT IGNORE INTO mk_results (market_id, day) VALUES (?, ?)', [$marketId, $day]);
    return q('SELECT * FROM mk_results WHERE market_id = ? AND day = ?', [$marketId, $day])->fetch();
}

/** Declare (randomly) every result whose time has come, and settle the bets that depend on it. */
function mk_declare(array $m, string $day): void
{
    [$o, $c] = mk_times($m, $day);
    $now = time();
    $r = mk_ensure((int)$m['id'], $day);
    $pannas = mk_pannas();
    if ($r['open_panna'] === null && $now >= $o) {
        $p = $pannas[random_int(0, count($pannas) - 1)];
        if (q('UPDATE mk_results SET open_panna = ?, open_digit = ? WHERE id = ? AND open_panna IS NULL', [$p, mk_digit($p), $r['id']])->rowCount() === 1) {
            mk_settle($m, $day, 'open');
        }
        $r = mk_ensure((int)$m['id'], $day);
    }
    if ($r['close_panna'] === null && $r['open_panna'] !== null && $now >= $c) {
        $p = $pannas[random_int(0, count($pannas) - 1)];
        if (q('UPDATE mk_results SET close_panna = ?, close_digit = ? WHERE id = ? AND close_panna IS NULL', [$p, mk_digit($p), $r['id']])->rowCount() === 1) {
            mk_settle($m, $day, 'close');
        }
    }
}

function mk_win(array $b, array $r): bool
{
    switch ($b['game']) {
        case 'single': return (string)($b['session'] === 'open' ? $r['open_digit'] : $r['close_digit']) === $b['pick'];
        case 'panna':  return ($b['session'] === 'open' ? $r['open_panna'] : $r['close_panna']) === $b['pick'];
    }
    return false;
}

function mk_settle(array $m, string $day, string $phase): void
{
    $r = mk_ensure((int)$m['id'], $day);
    $where = $phase === 'open' ? "session = 'open' AND game <> 'jodi'" : "(session = 'close' AND game <> 'jodi') OR game = 'jodi'";
    $bets = q("SELECT * FROM mk_bets WHERE market_id = ? AND day = ? AND status = 'open' AND ($where)", [$m['id'], $day])->fetchAll();
    foreach ($bets as $b) {
        $win = false;
        if ($b['game'] === 'jodi') $win = $r['open_digit'] !== null && $r['close_digit'] !== null && ((int)$r['open_digit'] * 10 + (int)$r['close_digit']) === (int)$b['pick'];
        else $win = mk_win($b, $r);
        $mult = $b['game'] === 'single' ? mult('mk_mult_single', 9) : ($b['game'] === 'jodi' ? mult('mk_mult_jodi', 90) : mult('mk_mult_panna', 140));
        $ret = $win ? round((float)$b['amount'] * $mult, 4) : 0.0;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (q("UPDATE mk_bets SET status = ?, payout = ? WHERE id = ? AND status = 'open'", [$win ? 'won' : 'lost', dec($ret), $b['id']])->rowCount() === 1 && $win) {
                wallet_adjust((int)$b['user_id'], 'demo', $ret, 'game_win', (int)$b['id'], $m['name'] . ' ' . $day . ' ' . $b['game']);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('mk_settle: ' . $ex->getMessage());
        }
    }
}

function mk_declare_due(): void
{
    $today = mk_today();
    foreach (q('SELECT * FROM mk_markets')->fetchAll() as $m) {
        $days = q("SELECT DISTINCT day FROM mk_bets WHERE market_id = ? AND status = 'open'", [$m['id']])->fetchAll(PDO::FETCH_COLUMN);
        if ($m['is_active'] && mk_runs_on($m, $today)) $days[] = $today;
        foreach (array_unique($days) as $d) {
            try { mk_declare($m, (string)$d); } catch (Throwable $ex) { error_log('mk_declare: ' . $ex->getMessage()); }
        }
    }
}

/** "128-1" style text: open panna-digit, jodi, close digit-panna. Missing parts are stars. */
function mk_text(?array $r): string
{
    if (!$r) return '***-**-***';
    $o = $r['open_panna'] ?? '***';
    $od = $r['open_digit'] ?? '*';
    $cd = $r['close_digit'] ?? '*';
    $c = $r['close_panna'] ?? '***';
    return $o . '-' . $od . $cd . '-' . $c;
}

/** Betting window state for a market today. */
function mk_status(array $m): array
{
    $today = mk_today();
    $now = time();
    if (!$m['is_active'] || !mk_runs_on($m, $today)) return ['key' => 'off', 'label' => 'Closed today', 'open_bets' => false, 'close_bets' => false];
    [$o, $c] = mk_times($m, $today);
    if ($now < $o) return ['key' => 'pre', 'label' => 'Betting open – open result at ' . $m['open_time'], 'open_bets' => true, 'close_bets' => true, 'next' => $o];
    if ($now < $c) return ['key' => 'mid', 'label' => 'Close betting open – close result at ' . $m['close_time'], 'open_bets' => false, 'close_bets' => true, 'next' => $c];
    return ['key' => 'done', 'label' => 'Result declared – see you tomorrow', 'open_bets' => false, 'close_bets' => false];
}

function mk_place(array $u, int $marketId, string $game, string $session, string $pick, float $amount): array
{
    if (!mk_on()) throw new RuntimeException('The Lucky Number game is switched off.');
    $m = q('SELECT * FROM mk_markets WHERE id = ? AND is_active = 1', [$marketId])->fetch();
    if (!$m) throw new RuntimeException('This market is not available.');
    $st = mk_status($m);
    if ($game === 'jodi') $session = 'open';
    if (!in_array($game, ['single', 'jodi', 'panna'], true) || !in_array($session, ['open', 'close'], true)) throw new RuntimeException('Invalid bet.');
    if (($session === 'open' && !$st['open_bets']) || ($session === 'close' && !$st['close_bets'])) {
        throw new RuntimeException($session === 'open' ? 'Betting on the open session is closed.' : 'Betting on the close session is closed.');
    }
    if ($game === 'single' && !preg_match('/^[0-9]$/', $pick)) throw new RuntimeException('Pick a digit from 0 to 9.');
    if ($game === 'jodi' && !preg_match('/^[0-9]{2}$/', $pick)) throw new RuntimeException('Pick a jodi from 00 to 99.');
    if ($game === 'panna' && !in_array($pick, mk_pannas(), true)) throw new RuntimeException('That is not a valid panna (digits must be in ascending order, 0 counts as 10 – e.g. 128, 237, 390, 556).');
    $amount = round($amount, 2);
    $min = (float)setting('mk_bet_min', 10);
    $max = (float)setting('mk_bet_max', 5000);
    if ($amount < $min || $amount > $max) throw new RuntimeException('Bet must be between ' . money($min, 'demo') . ' and ' . money($max, 'demo') . '.');
    $day = mk_today();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO mk_bets (market_id, day, user_id, game, session, pick, amount) VALUES (?,?,?,?,?,?,?)', [$marketId, $day, $u['id'], $game, $session, $pick, dec($amount)]);
        $bid = (int)$pdo->lastInsertId();
        $bal = wallet_adjust((int)$u['id'], 'demo', -$amount, 'game_bet', $bid, $m['name'] . ' ' . $game . ' ' . $pick);
        $pdo->commit();
    } catch (RuntimeException $ex) {
        $pdo->rollBack();
        throw new RuntimeException($ex->getMessage() === 'Insufficient balance' ? 'Not enough Demo coins. Reset your demo balance from the account menu.' : $ex->getMessage());
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['bet' => $bid, 'balance' => $bal];
}

/** Statistics from the market's own declared results (what Matka players call "analysis"). */
function mk_analysis(int $marketId, int $days = 60): array
{
    $rows = q('SELECT * FROM mk_results WHERE market_id = ? AND open_panna IS NOT NULL ORDER BY day DESC LIMIT ' . (int)$days, [$marketId])->fetchAll();
    $od = array_fill(0, 10, 0);
    $cd = array_fill(0, 10, 0);
    $jodi = [];
    $lastSeen = [];
    foreach ($rows as $i => $r) {
        $od[(int)$r['open_digit']]++;
        if ($r['close_digit'] !== null) {
            $cd[(int)$r['close_digit']]++;
            $j = (int)$r['open_digit'] . (int)$r['close_digit'];
            $jodi[$j] = ($jodi[$j] ?? 0) + 1;
            $lastSeen[$j] = $lastSeen[$j] ?? $i;
        }
    }
    arsort($jodi);
    $all = array_map(fn($n) => str_pad((string)$n, 2, '0', STR_PAD_LEFT), range(0, 99));
    $gap = [];
    foreach ($all as $j) $gap[$j] = $lastSeen[(int)$j] ?? count($rows);       // days since last seen
    arsort($gap);
    $hot = array_slice($jodi, 0, 8, true);
    $cold = array_slice($gap, 0, 8, true);
    $repeat = 0;
    $prev = null;
    foreach (array_reverse($rows) as $r) {
        if ($prev !== null && $r['open_digit'] === $prev) $repeat++;
        $prev = $r['open_digit'];
    }
    return ['rows' => $rows, 'open' => $od, 'close' => $cd, 'hot' => $hot, 'cold' => $cold, 'days' => count($rows), 'repeat' => $repeat];
}
