<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = require_user();
games_gate($u);
if (!mk_on()) { flash('info', 'The Lucky Number game is switched off right now.'); redirect('games.php'); }
mk_declare_due();

$uid = (int)$u['id'];
$id = (int)($_GET['m'] ?? 0);
$view = in_array($_GET['v'] ?? 'play', ['play', 'analysis', 'bets'], true) ? ($_GET['v'] ?? 'play') : 'play';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bet') {
    csrf_require();
    try {
        $r = mk_place($u, (int)($_POST['market'] ?? 0), (string)($_POST['game'] ?? ''), (string)($_POST['session'] ?? 'open'), trim((string)($_POST['pick'] ?? '')), (float)($_POST['amount'] ?? 0));
        flash('ok', 'Bet placed. Your Demo balance is now ' . money($r['balance'], 'demo') . '.');
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('matka.php?m=' . (int)($_POST['market'] ?? 0));
}

$u = user_page_start('Lucky Number', 'games.php');
play_nav('mk');
$markets = q('SELECT * FROM mk_markets WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
$M = null;
foreach ($markets as $x) if ((int)$x['id'] === $id) $M = $x;
$today = mk_today();
$demo = wallets_of($uid)['demo'];
$mm = ['single' => mult('mk_mult_single', 9), 'jodi' => mult('mk_mult_jodi', 90), 'panna' => mult('mk_mult_panna', 140)];
$min = (float)setting('mk_bet_min', 10);
$max = (float)setting('mk_bet_max', 5000);
?>
<div class="gamehead">
  <div><h1 style="margin:0">Lucky Number</h1><div class="muted" style="font-size:13px">Kalyan-style open / close market game</div></div>
  <div class="gamewallet"><small>Demo coins</small><b><?= e(money($demo, 'demo')) ?></b></div>
</div>
<div class="flash info" style="font-size:13px">🎮 <b>Entertainment only – no financial loss.</b> Played with <b>Demo coins</b> – virtual, no cash value, never connected to real money. Results are drawn at random by the server at the market's open and close time; nobody can choose or change them.</div>

<?php if (!$M): ?>
  <?php if (!$markets): ?><div class="card muted">No markets are running right now.</div><?php endif; ?>
  <div class="grid g2">
  <?php foreach ($markets as $m): $st = mk_status($m); $r = q('SELECT * FROM mk_results WHERE market_id = ? AND day = ?', [$m['id'], $today])->fetch(); ?>
    <a class="mkcard" href="?m=<?= (int)$m['id'] ?>">
      <div class="mkhead"><b><?= e($m['name']) ?></b><span class="badge <?= $st['key'] === 'off' || $st['key'] === 'done' ? 'blocked' : 'ok' ?>"><?= $st['key'] === 'off' ? 'closed today' : ($st['key'] === 'done' ? 'result out' : 'betting open') ?></span></div>
      <div class="mkres mono"><?= e(mk_text($r)) ?></div>
      <div class="muted" style="font-size:13px">Open <?= e($m['open_time']) ?> · Close <?= e($m['close_time']) ?> <?= e(tz_short(game_tz()->getName())) ?></div>
      <div class="muted" style="font-size:12px;margin-top:4px"><?= e($st['label']) ?></div>
    </a>
  <?php endforeach; ?>
  </div>
  <p style="margin-top:14px"><a href="<?= e(url('games.php')) ?>">‹ All games</a></p>
<?php else:
    $st = mk_status($M);
    $r = q('SELECT * FROM mk_results WHERE market_id = ? AND day = ?', [$M['id'], $today])->fetch();
?>
  <div class="card mkbanner">
    <div><div class="muted" style="font-size:12px"><?= e($M['name']) ?> · today</div><div class="mkres mono"><?= e(mk_text($r)) ?></div></div>
    <div style="text-align:right"><div class="muted" style="font-size:13px">Open <?= e($M['open_time']) ?> · Close <?= e($M['close_time']) ?></div>
      <div class="badge <?= in_array($st['key'], ['off', 'done'], true) ? 'blocked' : 'ok' ?>" style="margin-top:4px"><?= e($st['label']) ?></div>
      <?php if (!empty($st['next'])): ?><div class="mono" id="mkCount" data-to="<?= (int)$st['next'] ?>" style="margin-top:4px;font-weight:800"></div><?php endif; ?></div>
  </div>
  <div class="tabs">
    <a href="?m=<?= (int)$M['id'] ?>&v=play" class="<?= $view === 'play' ? 'on' : '' ?>">Play</a>
    <a href="?m=<?= (int)$M['id'] ?>&v=analysis" class="<?= $view === 'analysis' ? 'on' : '' ?>">Analysis &amp; chart</a>
    <a href="?m=<?= (int)$M['id'] ?>&v=bets" class="<?= $view === 'bets' ? 'on' : '' ?>">My bets</a>
    <span class="sp"></span><a href="matka.php">‹ Markets</a>
  </div>

  <?php if ($view === 'play'): ?>
  <div class="card">
    <?php if ($st['key'] === 'off' || $st['key'] === 'done'): ?>
      <p class="muted" style="margin:0"><?= e($st['label']) ?>. Come back when the market opens.</p>
    <?php else: ?>
    <form method="post" id="mkForm"><?= csrf_field() ?><input type="hidden" name="action" value="bet"><input type="hidden" name="market" value="<?= (int)$M['id'] ?>">
      <input type="hidden" name="game" id="mkGame" value="single"><input type="hidden" name="pick" id="mkPick" value="">
      <div class="tabs" id="mkGames">
        <a href="#" data-g="single" class="on">Single digit · x<?= e($mm['single']) ?></a>
        <a href="#" data-g="jodi">Jodi · x<?= e($mm['jodi']) ?></a>
        <a href="#" data-g="panna">Panna · x<?= e($mm['panna']) ?></a>
      </div>
      <div class="field" id="mkSessWrap"><label>Session</label>
        <select name="session" id="mkSess">
          <?php if ($st['open_bets']): ?><option value="open">Open – before <?= e($M['open_time']) ?></option><?php endif; ?>
          <?php if ($st['close_bets']): ?><option value="close">Close – before <?= e($M['close_time']) ?></option><?php endif; ?>
        </select></div>
      <div class="field"><label id="mkLbl">Choose a digit</label>
        <div class="mkgrid" id="mkGrid"></div>
        <div id="mkPannaWrap" style="display:none">
          <div class="digitfilter" id="digitFilter"></div>
          <select id="mkPanna" data-numeric="1" data-search="always"></select>
          <div class="hint">Type 1–3 digits to search, or tap a digit above to see only pannas that add up to it.</div>
        </div>
      </div>
      <div class="row">
        <div class="field"><label>Amount (Demo coins)</label><input type="number" name="amount" id="mkAmt" min="<?= e($min) ?>" max="<?= e($max) ?>" value="<?= e($min) ?>" step="1" required inputmode="numeric">
          <div class="chips"><?php foreach ([10, 50, 100, 500, 1000] as $v): if ($v >= $min && $v <= $max): ?><button type="button" data-v="<?= $v ?>"><?= $v ?></button><?php endif; endforeach; ?></div></div>
        <div class="field"><label>You pick</label><div id="mkSum" class="mkpick">—</div></div>
      </div>
      <button class="btn green block" type="submit" id="mkGo" disabled>Place bet</button>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Single: guess the open or close digit (sum of the 3 panna digits, last digit). Jodi: guess open digit + close digit (needs the close result). Panna: guess the exact 3-digit panna.</p>
    </form>
    <?php endif; ?>
  </div>
  <?php select2_assets(); ?>
  <script>
  const PANNAS =<?= json_encode(mk_pannas()) ?>, SUM = p => (+p[0] + +p[1] + +p[2]) % 10;
  const $ = id => document.getElementById(id);
  let game = 'single', pf = -1;
  // searchable panna list (Select2) with a digit filter
  function fillPanna(digit){
    pf = digit;
    $('digitFilter').innerHTML = '<button type="button" data-d="-1" class="' + (digit === -1 ? 'on' : '') + '">All</button>' +
      Array.from({length:10}, (_, d) => `<button type="button" data-d="${d}" class="${digit === d ? 'on' : ''}">${d}</button>`).join('');
    $('digitFilter').querySelectorAll('button').forEach(b => b.onclick = () => fillPanna(+b.dataset.d));
    const $sel = jQuery('#mkPanna');
    if ($sel.data('select2')) $sel.select2('destroy');
    let h = '<option value=""></option>';
    for (let d = 0; d <= 9; d++) {
      if (digit !== -1 && digit !== d) continue;
      h += PANNAS.filter(p => SUM(p) === d).map(p => `<option value="${p}" data-d="${d}">${p}</option>`).join('');
    }
    $sel.html(h).off('change').select2({
      width: '100%', placeholder: 'Search or choose a panna…', allowClear: true,
      templateResult: o => o.id ? jQuery(`<span class="s2opt"><b class="mono">${o.id}</b><small>digit ${o.element.dataset.d}</small></span>`) : o.text,
      templateSelection: o => o.id ? o.id + '  ·  digit ' + SUM(o.id) : o.text,
      matcher: (params, data) => (!params.term || (data.id && data.id.indexOf(params.term.trim()) !== -1)) ? data : null
    });
    $sel.on('change', () => { const v = $sel.val() || ''; setPick(v, v ? v + '  (digit ' + SUM(v) + ')' : '—'); });
    $sel.on('select2:open', () => { const f = document.querySelector('.select2-container--open .select2-search__field'); if (f) { f.setAttribute('inputmode', 'numeric'); setTimeout(() => f.focus(), 30); } });
  }
  function setPick(v, label){ $('mkPick').value = v; $('mkSum').textContent = label || (v === '' ? '—' : v); $('mkGo').disabled = v === ''; document.querySelectorAll('#mkGrid button').forEach(b => b.classList.toggle('on', b.dataset.v === v)); }
  function build(){
    const g = $('mkGrid');
    setPick('');
    $('mkSessWrap').style.display = game === 'jodi' ? 'none' : '';
    if (game === 'panna') {
      g.style.display = 'none'; $('mkPannaWrap').style.display = '';
      fillPanna(-1);
      $('mkLbl').textContent = 'Choose a panna';
    } else {
      $('mkPannaWrap').style.display = 'none'; g.style.display = '';
      const n = game === 'single' ? 10 : 100;
      g.className = 'mkgrid ' + (game === 'jodi' ? 'jodi' : '');
      g.innerHTML = Array.from({length:n}, (_, i) => { const v = game === 'jodi' ? String(i).padStart(2, '0') : String(i); return `<button type="button" data-v="${v}">${v}</button>`; }).join('');
      g.querySelectorAll('button').forEach(b => b.onclick = () => setPick(b.dataset.v));
      $('mkLbl').textContent = game === 'single' ? 'Choose a digit' : 'Choose a jodi (open digit + close digit)';
    }
  }
  document.querySelectorAll('#mkGames a').forEach(a => a.onclick = e => { e.preventDefault(); game = a.dataset.g; $('mkGame').value = game;
    document.querySelectorAll('#mkGames a').forEach(x => x.classList.toggle('on', x === a)); build(); });
  document.querySelectorAll('.chips button[data-v]').forEach(b => b.onclick = () => $('mkAmt').value = b.dataset.v);
  build();
  </script>
  <?php elseif ($view === 'analysis'):
      $A = mk_analysis((int)$M['id'], 60);
      $maxO = max(1, max($A['open']));
      $maxC = max(1, max($A['close']));
      // weekly chart: rows = ISO weeks, columns = Mon..Sun
      $weeks = [];
      foreach (array_slice($A['rows'], 0, 42) as $row) {
          $dt = new DateTime($row['day'], game_tz());
          $wk = $dt->format('o-W');
          $weeks[$wk]['label'] = $dt->modify('monday this week')->format('d M');
          $weeks[$wk]['d'][(int)(new DateTime($row['day'], game_tz()))->format('N')] = $row;
      }
  ?>
  <div class="card"><h2>Chart – last results</h2>
    <?php if (!$A['rows']): ?><p class="muted">No results yet. The first result is declared at the market's open time; the chart fills up as days pass.</p><?php else: ?>
    <div class="tablewrap"><table class="mkchart">
      <tr><th>Week of</th><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr>
      <?php foreach ($weeks as $w): ?>
        <tr><th><?= e($w['label']) ?></th>
        <?php for ($d = 1; $d <= 7; $d++): $x = $w['d'][$d] ?? null; ?>
          <td><?= $x ? '<span class="mono">' . e($x['open_panna']) . '</span><b>' . e(($x['open_digit'] ?? '*') . ($x['close_digit'] ?? '*')) . '</b><span class="mono">' . e($x['close_panna'] ?? '***') . '</span>' : '<span class="muted">–</span>' ?></td>
        <?php endfor; ?></tr>
      <?php endforeach; ?>
    </table></div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">Each cell: open panna · <b>jodi</b> · close panna. * = not declared yet.</p>
    <?php endif; ?>
  </div>
  <div class="grid g2">
    <div class="card"><h2>Open digit frequency <span class="muted" style="font-size:13px">(last <?= (int)$A['days'] ?> days)</span></h2>
      <?php foreach ($A['open'] as $d => $n): ?><div class="bar"><span><?= $d ?></span><i style="width:<?= round(100 * $n / $maxO) ?>%"></i><em><?= $n ?></em></div><?php endforeach; ?></div>
    <div class="card"><h2>Close digit frequency</h2>
      <?php foreach ($A['close'] as $d => $n): ?><div class="bar c"><span><?= $d ?></span><i style="width:<?= round(100 * $n / $maxC) ?>%"></i><em><?= $n ?></em></div><?php endforeach; ?></div>
  </div>
  <div class="grid g2">
    <div class="card"><h2>🔥 Hot jodis</h2>
      <?php if (!$A['hot']): ?><p class="muted">Not enough data yet.</p><?php endif; ?>
      <div class="chipsrow"><?php foreach ($A['hot'] as $j => $n): ?><span class="numchip"><?= str_pad((string)$j, 2, '0', STR_PAD_LEFT) ?><small>×<?= $n ?></small></span><?php endforeach; ?></div></div>
    <div class="card"><h2>❄️ Cold jodis <span class="muted" style="font-size:13px">(longest unseen)</span></h2>
      <?php if (!$A['rows']): ?><p class="muted">Not enough data yet.</p><?php endif; ?>
      <div class="chipsrow"><?php foreach ($A['cold'] as $j => $g): ?><span class="numchip cold"><?= e($j) ?><small><?= (int)$g ?>d</small></span><?php endforeach; ?></div></div>
  </div>
  <p class="muted" style="font-size:12px">Analysis only describes past random results – every draw is independent, so patterns here do not predict the next result.</p>

  <?php else:
      $bets = q('SELECT b.*, m.name FROM mk_bets b JOIN mk_markets m ON m.id = b.market_id WHERE b.user_id = ? AND b.market_id = ? ORDER BY b.id DESC LIMIT 40', [$uid, $M['id']])->fetchAll(); ?>
  <div class="card"><div class="tablewrap"><table class="rt">
    <tr><th>Date</th><th>Game</th><th>Session</th><th>Pick</th><th class="right">Stake</th><th>Status</th><th class="right">Return</th></tr>
    <?php foreach ($bets as $b): ?>
      <tr><td><?= e($b['day']) ?></td><td><?= e(ucfirst($b['game'])) ?></td><td><?= $b['game'] === 'jodi' ? '—' : e(ucfirst($b['session'])) ?></td><td class="mono"><b><?= e($b['pick']) ?></b></td>
        <td class="right"><?= e(money($b['amount'], 'demo')) ?></td><td><span class="badge <?= e($b['status']) ?>"><?= e($b['status']) ?></span></td>
        <td class="right <?= $b['status'] === 'won' ? 'green' : ($b['status'] === 'lost' ? 'red' : '') ?>"><?= $b['status'] === 'open' ? '—' : ($b['status'] === 'won' ? '+' . e(money((float)$b['payout'] - (float)$b['amount'], 'demo')) : '-' . e(money($b['amount'], 'demo'))) ?></td></tr>
    <?php endforeach; if (!$bets): ?><tr><td colspan="7" class="muted">No bets on this market yet.</td></tr><?php endif; ?>
  </table></div></div>
  <?php endif; ?>
  <script>
  (function(){ var c=document.getElementById('mkCount'); if(!c)return; var to=+c.dataset.to, off=<?= time() ?>-Date.now()/1000;
    function t(){ var l=Math.max(0,Math.ceil(to-(Date.now()/1000+off))); var h=Math.floor(l/3600),m=Math.floor(l%3600/60),s=l%60;
      c.textContent=(h?h+'h ':'')+String(m).padStart(2,'0')+'m '+String(s).padStart(2,'0')+'s'; if(l===0)setTimeout(function(){location.reload()},1500); }
    t(); setInterval(t,1000); })();
  </script>
<?php endif; ?>
<?php user_page_end();
