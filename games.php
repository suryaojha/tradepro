<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = user_page_start('Play', 'games.php');
play_nav('hub');
$demo = wallets_of((int)$u['id'])['demo'];
$modes = cg_modes();
$last = games_on() && cg_on() ? q('SELECT result FROM cg_rounds WHERE secs = ? AND settled_at IS NOT NULL ORDER BY start_ts DESC LIMIT 8', [$modes[0]])->fetchAll(PDO::FETCH_COLUMN) : [];
$markets = games_on() && mk_on() ? q('SELECT * FROM mk_markets WHERE is_active = 1 ORDER BY sort_order, id LIMIT 3')->fetchAll() : [];
$pin = pin_is_set($u);
?>
<div class="gamehead">
  <div><h1 style="margin:0">Play</h1><div class="muted" style="font-size:13px">Three ways to play – one wallet</div></div>
  <div class="gamewallet"><small>Demo coins</small><b><?= e(money($demo, 'demo')) ?></b></div>
</div>

<div class="grid g3 hubgrid">
  <a class="hubcard h1" href="<?= e(url('trade.php')) ?>">
    <span class="hubic">📈</span><h2>Trading</h2>
    <p>Predict UP or DOWN on live forex, crypto, gold and stocks with TradingView charts.</p>
    <span class="hubtag">Real prices · Demo / INR / USDT</span>
    <span class="btn green sm">Open terminal →</span>
  </a>
  <a class="hubcard h2" href="<?= e(url('colorgame.php')) ?>">
    <span class="hubic">🎨</span><h2>Colour Game</h2>
    <p>Pick Green, Violet or Red – or a number 0-9 – before each period ends.</p>
    <?php if ($last): ?><div class="gresults sm"><?php foreach ($last as $n): $c = CG_COLORS[(int)$n]; ?><span class="gdot <?= e(implode(' ', $c)) ?>"><?= (int)$n ?></span><?php endforeach; ?></div><?php endif; ?>
    <span class="hubtag">Entertainment · Demo coins</span>
    <span class="btn sm"><?= cg_on() ? 'Play now →' : 'Switched off' ?></span>
  </a>
  <a class="hubcard h3" href="<?= e(url('matka.php')) ?>">
    <span class="hubic">🔢</span><h2>Lucky Number</h2>
    <p>Kalyan-style market with open and close results, jodi, panna and an analysis chart.</p>
    <?php foreach ($markets as $m): $st = mk_status($m); ?><div class="muted" style="font-size:12px"><b><?= e($m['name']) ?></b> · <?= e($m['open_time']) ?>–<?= e($m['close_time']) ?> · <?= e($st['key'] === 'off' ? 'closed today' : ($st['key'] === 'done' ? 'result out' : 'betting open')) ?></div><?php endforeach; ?>
    <span class="hubtag">Entertainment · Demo coins</span>
    <span class="btn sm"><?= mk_on() ? 'Play now →' : 'Switched off' ?></span>
  </a>
  <a class="hubcard h4" href="<?= e(url('bigbang.php')) ?>">
    <span class="hubic">🎮</span><h2>BigBang Casino</h2>
    <p>External standard games available in sandbox demo mode.</p>
    <span class="hubtag">Sandbox · Demo only</span>
    <span class="btn sm">Open demo lobby →</span>
  </a>
</div>

<div class="card" style="margin-top:18px">
  <h2>How the games work</h2>
  <ul class="muted" style="margin:0;padding-left:18px">
    <li>The <b>Colour Game</b> and <b>Lucky Number</b> are for entertainment: they use your <b>Demo coins</b> only – virtual, with no cash value. Nothing can be deposited, withdrawn or converted for them.</li>
    <li>Results are drawn by the server's secure random generator. Nobody – including the admin – can choose or change a result.</li>
    <li>Entering the games needs your 6-digit security PIN<?= $pin ? '' : ' – <a href="' . e(url('profile.php#pin')) . '">set it now</a>' ?>. Out of coins? Reset your demo balance from the account menu.</li>
  </ul>
</div>
<?php user_page_end();
