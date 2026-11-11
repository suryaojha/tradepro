<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

require_admin();

// key => [label, type]
$FIELDS = [
    'Games (all)' => [
        'games_enabled'      => ['Games section on', 'bool'],
        'games_pin_required' => ['Ask for the 6-digit PIN to enter the games', 'bool'],
    ],
    'Colour Game' => [
        'cg_enabled'    => ['Colour Game on', 'bool'],
        'cg_modes'      => ['Round lengths in seconds, comma separated (must divide 86400, e.g. 60,180,300)', 'modes'],
        'cg_bet_min'    => ['Minimum bet (Demo coins)', 'pos'],
        'cg_bet_max'    => ['Maximum bet (Demo coins)', 'pos'],
        'cg_lock_secs'  => ['Stop taking bets in the last … seconds of a round', 'pos'],
        'cg_mult_color' => ['Red / Green payout multiplier (total return)', 'pos'],
        'cg_mult_half'  => ['Red on 0 / Green on 5 multiplier (number also carries violet)', 'pos'],
        'cg_mult_violet' => ['Violet payout multiplier', 'pos'],
        'cg_mult_number' => ['Exact number payout multiplier', 'pos'],
    ],
    'Lucky Number (Matka-style)' => [
        'mk_enabled'     => ['Lucky Number on', 'bool'],
        'mk_bet_min'     => ['Minimum bet (Demo coins)', 'pos'],
        'mk_bet_max'     => ['Maximum bet (Demo coins)', 'pos'],
        'mk_mult_single' => ['Single digit payout multiplier', 'pos'],
        'mk_mult_jodi'   => ['Jodi payout multiplier', 'pos'],
        'mk_mult_panna'  => ['Panna payout multiplier', 'pos'],
    ],
];

function valid_time(string $t): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }
function clean_days($d): string
{
    $x = array_values(array_unique(array_filter(array_map('intval', (array)$d), fn($n) => $n >= 1 && $n <= 7)));
    sort($x);
    return implode(',', $x);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'settings') {
            $vals = [];
            foreach ($FIELDS as $fs) foreach ($fs as $k => [$label, $type]) {
                if ($type === 'bool') { $vals[$k] = isset($_POST[$k]) ? '1' : '0'; continue; }
                $v = trim((string)($_POST[$k] ?? ''));
                if ($type === 'pos' && (!is_numeric($v) || (float)$v <= 0)) throw new RuntimeException("$label must be a number above 0.");
                if ($type === 'modes') {
                    $m = array_values(array_unique(array_filter(array_map('intval', explode(',', $v)), fn($s) => $s >= 30 && $s <= 3600 && 86400 % $s === 0)));
                    if (!$m) throw new RuntimeException('Enter at least one valid round length (30–3600 seconds that divides a day evenly: 30, 60, 90, 120, 180, 300, 600 …).');
                    sort($m);
                    $v = implode(',', $m);
                }
                $vals[$k] = $v;
            }
            if ((float)$vals['cg_bet_min'] > (float)$vals['cg_bet_max'] || (float)$vals['mk_bet_min'] > (float)$vals['mk_bet_max']) throw new RuntimeException('Minimum bet cannot be above the maximum.');
            foreach ($vals as $k => $v) set_setting($k, $v);
            admin_log('games_settings');
            flash('ok', 'Game settings saved. New multipliers and limits apply to bets placed from now on.');
        } elseif ($action === 'mk_add' || $action === 'mk_save') {
            $items = [];
            if ($action === 'mk_add') {
                $items[0] = ['name' => $_POST['name'] ?? '', 'open' => $_POST['open_time'] ?? '', 'close' => $_POST['close_time'] ?? '', 'days' => $_POST['days'] ?? [], 'sort' => 0, 'active' => 1];
            } else {
                foreach (array_map('intval', array_keys((array)($_POST['name'] ?? []))) as $id) {
                    $items[$id] = ['name' => $_POST['name'][$id] ?? '', 'open' => $_POST['open_time'][$id] ?? '', 'close' => $_POST['close_time'][$id] ?? '',
                                   'days' => $_POST['days'][$id] ?? [], 'sort' => (int)($_POST['sort'][$id] ?? 0), 'active' => isset($_POST['active'][$id]) ? 1 : 0];
                }
            }
            foreach ($items as $id => $it) {
                $name = trim((string)$it['name']);
                if ($name === '' || mb_strlen($name) > 60) throw new RuntimeException('Enter a market name.');
                if (!valid_time($it['open']) || !valid_time($it['close']) || $it['close'] <= $it['open']) throw new RuntimeException("$name: open and close must be times (HH:MM) with close after open.");
                $days = clean_days($it['days']);
                if ($days === '') throw new RuntimeException("$name: choose at least one day.");
                if ($action === 'mk_add') {
                    q('INSERT INTO mk_markets (name, open_time, close_time, days, sort_order) VALUES (?,?,?,?,?)',
                        [$name, $it['open'], $it['close'], $days, 1 + (int)q('SELECT COALESCE(MAX(sort_order),0) FROM mk_markets')->fetchColumn()]);
                } else {
                    q('UPDATE mk_markets SET name = ?, open_time = ?, close_time = ?, days = ?, sort_order = ?, is_active = ? WHERE id = ?',
                        [$name, $it['open'], $it['close'], $days, $it['sort'], $it['active'], $id]);
                }
            }
            admin_log($action);
            flash('ok', $action === 'mk_add' ? 'Market added.' : 'Markets saved.');
        } elseif ($action === 'mk_toggle') {
            q('UPDATE mk_markets SET is_active = 1 - is_active WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            admin_log('mk_toggle', '#' . (int)($_POST['id'] ?? 0));
            flash('ok', 'Market updated.');
        } elseif ($action === 'mk_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (q('SELECT 1 FROM mk_bets WHERE market_id = ? LIMIT 1', [$id])->fetchColumn()) throw new RuntimeException('This market has bets. Switch it off instead of deleting it.');
            q('DELETE FROM mk_markets WHERE id = ?', [$id]);
            admin_log('mk_delete', "#$id");
            flash('ok', 'Market deleted.');
        }
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/games.php' . ($action === 'settings' ? '' : '#mk'));
}

cg_settle_due();
mk_declare_due();
admin_start('Games', 'games.php');

$cgStats = q('SELECT COUNT(*) n, COUNT(DISTINCT user_id) u, COALESCE(SUM(amount),0) staked, COALESCE(SUM(payout),0) paid FROM cg_bets')->fetch();
$mkStats = q('SELECT COUNT(*) n, COUNT(DISTINCT user_id) u, COALESCE(SUM(amount),0) staked, COALESCE(SUM(payout),0) paid FROM mk_bets')->fetch();
$rounds = q('SELECT r.*, (SELECT COUNT(*) FROM cg_bets WHERE round_id = r.id) bets, (SELECT COALESCE(SUM(amount),0) FROM cg_bets WHERE round_id = r.id) staked,
                    (SELECT COALESCE(SUM(payout),0) FROM cg_bets WHERE round_id = r.id) paid
             FROM cg_rounds r WHERE r.settled_at IS NOT NULL ORDER BY r.id DESC LIMIT 15')->fetchAll();
$markets = q('SELECT * FROM mk_markets ORDER BY sort_order, id')->fetchAll();
$results = q('SELECT r.*, m.name FROM mk_results r JOIN mk_markets m ON m.id = r.market_id WHERE r.open_panna IS NOT NULL ORDER BY r.day DESC, r.id DESC LIMIT 12')->fetchAll();
$DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>
<h1>Games <span class="muted" style="font-size:14px;font-weight:500">Colour Game &amp; Lucky Number</span></h1>
<div class="flash info">These are <b>entertainment games played with Demo coins only</b> (virtual, no cash value, no deposit / withdrawal link). Results are generated by the server's secure random generator and <b>cannot be chosen or edited by anyone, including admins</b> – you control on/off, schedules, limits and multipliers.</div>

<div class="kpis">
  <div class="stat"><div class="l">Colour Game bets</div><div class="v"><?= (int)$cgStats['n'] ?></div><div class="s"><?= (int)$cgStats['u'] ?> players</div></div>
  <div class="stat"><div class="l">Colour coins staked / paid</div><div class="v" style="font-size:18px"><?= e(number_format((float)$cgStats['staked'], 0)) ?> / <?= e(number_format((float)$cgStats['paid'], 0)) ?></div><div class="s">virtual</div></div>
  <div class="stat"><div class="l">Lucky Number bets</div><div class="v"><?= (int)$mkStats['n'] ?></div><div class="s"><?= (int)$mkStats['u'] ?> players</div></div>
  <div class="stat"><div class="l">Lucky coins staked / paid</div><div class="v" style="font-size:18px"><?= e(number_format((float)$mkStats['staked'], 0)) ?> / <?= e(number_format((float)$mkStats['paid'], 0)) ?></div><div class="s">virtual</div></div>
</div>

<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <?php foreach ($FIELDS as $title => $fs): ?>
    <div class="card"><h2><?= e($title) ?></h2><div class="row two">
      <?php foreach ($fs as $k => [$label, $type]): $val = (string)setting($k, ''); ?>
        <div class="field" style="min-width:220px">
          <?php if ($type === 'bool'): ?>
            <label class="check" style="margin-top:22px"><input type="checkbox" name="<?= e($k) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
          <?php else: ?>
            <label><?= e($label) ?></label>
            <input type="<?= $type === 'pos' ? 'number' : 'text' ?>" <?= $type === 'pos' ? 'step="any" min="0" inputmode="decimal"' : '' ?> name="<?= e($k) ?>" value="<?= e($val) ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div></div>
  <?php endforeach; ?>
  <button class="btn green" type="submit">Save game settings</button>
</form>

<div class="card" style="margin-top:24px"><h2>Colour Game – latest rounds</h2><div class="tablewrap"><table class="rt">
  <tr><th>Period</th><th>Round</th><th>Result</th><th class="right">Bets</th><th class="right">Staked</th><th class="right">Paid</th></tr>
  <?php foreach ($rounds as $r): $c = CG_COLORS[(int)$r['result']]; ?>
    <tr><td class="mono"><?= e($r['period_no']) ?></td><td><?= e($r['secs'] < 60 ? $r['secs'] . 's' : ($r['secs'] / 60) . ' min') ?></td>
      <td><span class="gdot <?= e(implode(' ', $c)) ?>"><?= (int)$r['result'] ?></span></td><td class="right"><?= (int)$r['bets'] ?></td>
      <td class="right"><?= e(number_format((float)$r['staked'], 2)) ?></td><td class="right"><?= e(number_format((float)$r['paid'], 2)) ?></td></tr>
  <?php endforeach; if (!$rounds): ?><tr><td colspan="6" class="muted">No rounds yet – they appear as soon as someone opens the game.</td></tr><?php endif; ?>
</table></div></div>

<form method="post" class="card" id="mk"><?= csrf_field() ?><input type="hidden" name="action" value="mk_save">
  <h2>Lucky Number markets</h2>
  <div class="tablewrap"><table class="rt">
    <tr><th>Name</th><th>Open</th><th>Close</th><th>Days</th><th>Order</th><th>On</th><th></th></tr>
    <?php foreach ($markets as $m): $i = (int)$m['id']; $on = explode(',', $m['days']); ?>
      <tr><td><input class="inp-md" type="text" name="name[<?= $i ?>]" value="<?= e($m['name']) ?>" maxlength="60"></td>
        <td><input class="inp-sm" type="time" name="open_time[<?= $i ?>]" value="<?= e($m['open_time']) ?>" style="width:110px!important"></td>
        <td><input class="inp-sm" type="time" name="close_time[<?= $i ?>]" value="<?= e($m['close_time']) ?>" style="width:110px!important"></td>
        <td><div class="inline" style="gap:6px"><?php foreach ($DAYS as $n => $d): ?><label class="check" style="font-size:12px;gap:3px"><input type="checkbox" name="days[<?= $i ?>][]" value="<?= $n ?>" <?= in_array((string)$n, $on, true) ? 'checked' : '' ?>><?= $d ?></label><?php endforeach; ?></div></td>
        <td><input class="inp-xs" type="number" name="sort[<?= $i ?>]" value="<?= (int)$m['sort_order'] ?>"></td>
        <td><input type="checkbox" name="active[<?= $i ?>]" value="1" <?= $m['is_active'] ? 'checked' : '' ?>></td>
        <td class="rt-block"><button class="btn sm red" type="submit" form="mdel<?= $i ?>">Delete</button></td></tr>
    <?php endforeach; if (!$markets): ?><tr><td colspan="7" class="muted">No markets yet.</td></tr><?php endif; ?>
  </table></div>
  <p class="muted" style="font-size:12px">Times use the site time zone (<?= e(game_tz()->getName()) ?>). Result for the open session is drawn at the open time, the close session at the close time.</p>
  <button class="btn green" type="submit">Save markets</button>
</form>
<?php foreach ($markets as $m): ?><form method="post" id="mdel<?= (int)$m['id'] ?>" data-confirm="Delete <?= e($m['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="mk_delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"></form><?php endforeach; ?>

<div class="card"><h2>Add a market</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mk_add">
    <div class="row">
      <div class="field"><label>Name</label><input type="text" name="name" placeholder="Milan Day" required maxlength="60"></div>
      <div class="field"><label>Open time</label><input type="time" name="open_time" value="15:00" required></div>
      <div class="field"><label>Close time</label><input type="time" name="close_time" value="17:00" required></div>
    </div>
    <div class="field"><label>Days</label><div class="inline"><?php foreach ($DAYS as $n => $d): ?><label class="check" style="gap:4px"><input type="checkbox" name="days[]" value="<?= $n ?>" <?= $n <= 6 ? 'checked' : '' ?>><?= $d ?></label><?php endforeach; ?></div></div>
    <button class="btn" type="submit">Add market</button>
  </form>
</div>

<div class="card"><h2>Lucky Number – latest results</h2><div class="tablewrap"><table class="rt">
  <tr><th>Date</th><th>Market</th><th>Result (open panna · jodi · close panna)</th></tr>
  <?php foreach ($results as $r): ?>
    <tr><td><?= e($r['day']) ?></td><td><?= e($r['name']) ?></td><td class="mono"><b><?= e(mk_text($r)) ?></b></td></tr>
  <?php endforeach; if (!$results): ?><tr><td colspan="3" class="muted">No results declared yet.</td></tr><?php endif; ?>
</table></div></div>
<?php admin_end();
