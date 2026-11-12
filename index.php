<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('trade.php');
}
$assets = active_assets();
$quotes = quotes_get();
$name = (string)setting('site_name', 'TradePro');
$payout = rtrim(rtrim(number_format((float)setting('payout_percent', 85), 2), '0'), '.');
$durs = durations();
$cats = array_values(array_unique(array_column($assets, 'category')));
$minInr = (float)setting('min_trade_inr', 0);
$hero = array_slice(array_filter($assets, fn($a) => isset($quotes[(int)$a['id']])), 0, 3);
$fmtp = fn($a, $q) => $q ? number_format($q['price'], (int)$a['decimals']) : '—';
// real, current results for the game cards (nothing invented)
$cgLast = cg_on() ? q('SELECT result FROM cg_rounds WHERE secs = ? AND settled_at IS NOT NULL ORDER BY start_ts DESC LIMIT 8', [cg_modes()[0]])->fetchAll(PDO::FETCH_COLUMN) : [];
$mkToday = [];
if (mk_on()) {
    foreach (q('SELECT * FROM mk_markets WHERE is_active = 1 ORDER BY sort_order, id LIMIT 2')->fetchAll() as $m) {
        $mkToday[] = ['name' => $m['name'], 'r' => q('SELECT * FROM mk_results WHERE market_id = ? AND day = ?', [$m['id'], mk_today()])->fetch() ?: null];
    }
}
page_head('Online Trading Platform', 'landing');
?>
<header class="topbar lh">
  <?= logo_html() ?>
  <nav class="nav lnav">
    <a href="#play">Play</a><a href="#markets">Markets</a><a href="#how">How it works</a><a href="#features">Features</a><a href="#faq">FAQ</a>
  </nav>
  <div class="topright">
    <?= tz_widget() ?>
    <?= theme_toggle() ?>
    <a class="btn ghost sm" href="<?= e(url('login.php')) ?>">Log in</a>
    <a class="btn green sm" href="<?= e(url('register.php')) ?>">Sign up free</a>
  </div>
</header>

<section class="lhero">
  <div class="lhero-in">
    <div class="lhero-text">
      <span class="pillbadge"><span class="live"></span>Live prices · Binance &amp; Yahoo Finance · TradingView charts</span>
      <h1>Predict the market.<br><span class="grad">Trade in seconds.</span></h1>
      <p>Will the price go <b class="green">UP</b> or <b class="red">DOWN</b>? Make your prediction on forex, crypto, gold and stocks. Get it right and win up to <b><?= e($payout) ?>%</b> of your stake.</p>
      <div class="cta">
        <a class="btn green lg" href="<?= e(url('register.php')) ?>">Start with a free demo</a>
        <a class="btn ghost lg" href="#how">See how it works</a>
      </div>
      <ul class="trustrow">
        <li>✓ <?= e(money(setting('demo_balance', 0), 'demo')) ?> demo balance</li>
        <li>✓ Expiry from <?= e(fmt_duration($durs[0])) ?></li>
        <li>✓ Deposit via UPI / bank / USDT</li>
      </ul>
    </div>
    <div class="lhero-card">
      <div class="hc-head"><b>Live markets</b><span class="muted" style="font-size:12px"><span class="live"></span>updating</span></div>
      <?php foreach ($hero as $a): $q = $quotes[(int)$a['id']]; ?>
        <div class="hc-row">
          <div><div class="sym"><?= e($a['symbol']) ?></div><div class="muted" style="font-size:12px"><?= e($a['name']) ?></div></div>
          <div class="mono hc-px" data-px="<?= (int)$a['id'] ?>" data-dec="<?= (int)$a['decimals'] ?>"><?= e($fmtp($a, $q)) ?></div>
          <div class="hc-btns"><span class="up">▲ UP</span><span class="dn">▼ DOWN</span></div>
        </div>
      <?php endforeach; ?>
      <div class="hc-foot">Correct prediction pays <b class="green"><?= e($payout) ?>%</b> profit</div>
    </div>
  </div>
</section>

<div class="ticker lticker" id="ticker">
  <?php foreach ($assets as $a): $q = $quotes[(int)$a['id']] ?? null; ?>
    <div class="tk"><span class="muted"><?= e($a['symbol']) ?></span>
      <b class="mono" data-px="<?= (int)$a['id'] ?>" data-dec="<?= (int)$a['decimals'] ?>"><?= e($fmtp($a, $q)) ?></b></div>
  <?php endforeach; ?>
</div>

<div class="container lwrap">
  <div class="lstats">
    <div><b><?= count($assets) ?>+</b><span>Live markets</span></div>
    <div><b><?= e($payout) ?>%</b><span>Winning payout</span></div>
    <div><b><?= e(fmt_duration($durs[0])) ?></b><span>Fastest expiry</span></div>
    <div><b><?= e(money($minInr, 'inr')) ?></b><span>Minimum trade (INR)</span></div>
  </div>

  <section id="how" class="lsec">
    <h2>How it works</h2>
    <div class="grid g3 steps">
      <div class="step"><i>1</i><h3>Pick a market</h3><p class="muted">Choose from <?= e(implode(', ', array_map('ucfirst', $cats))) ?> – with a live TradingView chart.</p></div>
      <div class="step"><i>2</i><h3>Predict UP or DOWN</h3><p class="muted">Choose your stake and expiry (<?= e(implode(', ', array_map('fmt_duration', $durs))) ?>), then tap UP or DOWN.</p></div>
      <div class="step"><i>3</i><h3>Win the payout</h3><p class="muted">At expiry the live price decides. Right prediction: you win <?= e($payout) ?>% on top of your stake.</p></div>
    </div>
  </section>

  <section id="features" class="lsec">
    <h2>Everything in one place</h2>
    <div class="grid g3 feats">
      <div class="feat2"><span>💼</span><h3>One wallet, three accounts</h3><p class="muted">Demo, INR and USDT balances with a live INR ⇄ USDT converter.</p></div>
      <div class="feat2"><span>📈</span><h3>Pro charts</h3><p class="muted">TradingView charts with indicators and drawing tools, or the simple prediction chart.</p></div>
      <div class="feat2"><span>⚡</span><h3>Real prices only</h3><p class="muted">Results come only from the live market price at expiry – nobody can change a trade's outcome.</p></div>
      <div class="feat2"><span>🔐</span><h3>6-digit security PIN</h3><p class="muted">Every withdrawal needs your PIN, and you can lock the app with it too.</p></div>
      <div class="feat2"><span>📲</span><h3>UPI, QR &amp; bank</h3><p class="muted">Deposit with a dynamic UPI QR, UPI apps or bank transfer. Withdraw to bank or UPI.</p></div>
      <div class="feat2"><span>📧</span><h3>Statements by email</h3><p class="muted">Clear profit &amp; loss view and a full statement sent to your inbox on request.</p></div>
    </div>
  </section>

  <section id="play" class="lsec">
    <h2>Four ways to play</h2>
    <div class="grid g3 hubgrid">
      <a class="hubcard h1" href="<?= e(url('register.php')) ?>"><span class="hubic">📈</span><h3>Trading</h3><p>Predict UP or DOWN on live forex, crypto, gold and stocks with pro TradingView charts.</p><span class="hubtag">Real prices</span></a>
      <?php if (cg_on()): ?><a class="hubcard h2" href="<?= e(url('register.php')) ?>"><span class="hubic">🎨</span><h3>Colour Game</h3><p>Pick Green, Violet or Red – or a number – before each period ends.</p>
        <?php if ($cgLast): ?><div class="gresults sm"><?php foreach ($cgLast as $n): ?><span class="gdot <?= e(implode(' ', CG_COLORS[(int)$n])) ?>"><?= (int)$n ?></span><?php endforeach; ?></div><?php endif; ?>
        <span class="hubtag">Entertainment · Demo coins</span></a><?php endif; ?>
      <?php if (mk_on()): ?><a class="hubcard h3" href="<?= e(url('register.php')) ?>"><span class="hubic">🔢</span><h3>Lucky Number</h3><p>Kalyan-style open / close market with jodi, panna and a results analysis chart.</p>
        <?php foreach ($mkToday as $row): ?><div class="mkres mono" style="font-size:16px;margin:2px 0"><span class="muted" style="font-size:12px;font-family:inherit"><?= e($row['name']) ?></span> <?= e(mk_text($row['r'])) ?></div><?php endforeach; ?>
        <span class="hubtag">Entertainment · Demo coins</span></a><?php endif; ?>
      <a class="hubcard h4" href="<?= e(url('register.php')) ?>"><span class="hubic">🎮</span><h3>BigBang Casino</h3><p>Play available Standard games through BigBang's free sandbox demo. Uses BigBang's virtual balance, not your TradePro wallet.</p><span class="hubtag">Free sandbox · Demo only</span></a>
    </div>
    <p class="muted" style="font-size:12px;margin-top:10px">The Colour Game and Lucky Number are for fun only: they use virtual Demo coins with no cash value, and every result is drawn at random by the server.</p>
  </section>

  <section id="markets" class="lsec">
    <h2><span class="live"></span>Live markets</h2>
    <div class="card">
      <div class="tablewrap"><table class="rt">
        <tr><th>Market</th><th>Type</th><th class="right">Live price</th><th class="right">Payout</th></tr>
        <?php foreach ($assets as $a): $q = $quotes[(int)$a['id']] ?? null; ?>
          <tr><td><b><?= e($a['name']) ?></b> <span class="muted"><?= e($a['symbol']) ?></span></td>
              <td class="muted"><?= e(ucfirst($a['category'])) ?></td>
              <td class="right mono" data-px="<?= (int)$a['id'] ?>" data-dec="<?= (int)$a['decimals'] ?>"><?= e($fmtp($a, $q)) ?><?= $q && $q['closed'] ? ' <span class="badge pending">closed</span>' : '' ?></td>
              <td class="right green"><b><?= e(rtrim(rtrim(number_format(asset_payout($a), 2), '0'), '.')) ?>%</b></td></tr>
        <?php endforeach; ?>
      </table></div>
    </div>
  </section>

  <section id="faq" class="lsec">
    <h2>Questions</h2>
    <div class="faq">
      <details><summary>Where do the prices come from?</summary><p class="muted">Directly from live market data providers (Binance for crypto, Yahoo Finance and others for forex, gold and stocks). Quotes can be slightly delayed. Stocks and forex are closed outside market hours.</p></details>
      <details><summary>Can the result of a trade be changed?</summary><p class="muted">No. A trade wins if the live price at expiry is on the side you predicted. If the price is exactly equal, your stake is returned.</p></details>
      <details><summary>How do deposits and withdrawals work?</summary><p class="muted">Deposit by UPI (QR or app), bank transfer or USDT and submit your reference; an admin verifies it. Withdrawals need your 6-digit PIN and are paid out manually.</p></details>
      <details><summary>Is there a demo account?</summary><p class="muted">Yes – every new user gets a virtual demo balance to practise. Demo money cannot be withdrawn.</p></details>
      <details><summary>Are BigBang games free?</summary><p class="muted">Available Standard games can be launched in BigBang sandbox demo mode using BigBang virtual demo balance. Premium titles may not be available, and TradePro wallet funds are not used.</p></details>
    </div>
  </section>

  <section class="lsec">
    <h2>Built to be fair and safe</h2>
    <div class="grid g4 keep2 safegrid">
      <div class="safe"><b>🔒</b><h3>Real prices</h3><p class="muted">Outcomes follow live market prices only.</p></div>
      <div class="safe"><b>🎲</b><h3>Random games</h3><p class="muted">Game results are drawn by the server's secure random generator.</p></div>
      <div class="safe"><b>🔐</b><h3>PIN protected</h3><p class="muted">6-digit PIN for withdrawals and the games.</p></div>
      <div class="safe"><b>📧</b><h3>Clear records</h3><p class="muted">Profit &amp; loss view and emailed statements.</p></div>
    </div>
  </section>

  <section class="lcta">
    <h2>Ready to try it?</h2>
    <p class="muted">Create a free account in a minute and practise on the demo balance.</p>
    <a class="btn green lg" href="<?= e(url('register.php')) ?>">Create free account</a>
  </section>

  <footer class="lfoot">
    <div><?= logo_html() ?></div>
    <div class="lfl"><a href="#play">Play</a><a href="#markets">Markets</a><a href="#faq">FAQ</a><a href="<?= e(url('login.php')) ?>">Log in</a><a href="<?= e(url('register.php')) ?>">Sign up</a></div>
  </footer>

  <p class="muted center" style="font-size:12px;margin-top:18px">Trading carries a high risk of losing the money you put in – only trade with funds you can afford to lose. Prices are market quotes that may be delayed. This service may not be available or legal in every country; make sure you are allowed to use it where you live. © <?= date('Y') ?> <?= e($name) ?></p>
</div>
<div class="stickycta"><a class="btn ghost" href="<?= e(url('login.php')) ?>">Log in</a><a class="btn green" href="<?= e(url('register.php')) ?>">Start free demo</a></div>
<script>
// scroll-reveal + count-up for the stats
(function(){
  var els = document.querySelectorAll('.lsec, .lstats div, .hubcard, .step, .feat2, .safe, .lcta');
  els.forEach(function(el){ el.classList.add('reveal'); });
  if (!('IntersectionObserver' in window)) { els.forEach(function(el){ el.classList.add('in'); }); return; }
  var io = new IntersectionObserver(function(es){ es.forEach(function(e){ if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, {threshold:.08});
  els.forEach(function(el){ io.observe(el); });
  document.querySelectorAll('.lstats b').forEach(function(b){
    var m = b.textContent.match(/^([^\d]*)(\d+(?:\.\d+)?)(.*)$/); if (!m) return;
    var to = parseFloat(m[2]), dec = (m[2].split('.')[1] || '').length, t0 = null;
    function step(ts){ t0 = t0 || ts; var p = Math.min(1, (ts - t0) / 900); b.textContent = m[1] + (to * p).toFixed(dec) + m[3]; if (p < 1) requestAnimationFrame(step); }
    var o = new IntersectionObserver(function(es){ if (es[0].isIntersecting) { requestAnimationFrame(step); o.disconnect(); } }); o.observe(b);
  });
})();
(function(){
  function upd(){
    fetch(<?= json_encode(url('api/prices.php')) ?>).then(function(r){return r.json()}).then(function(j){
      if(!j.ok)return;
      document.querySelectorAll('[data-px]').forEach(function(el){
        var p=j.prices[el.dataset.px]; if(p===undefined)return;
        var t=Number(p.price).toLocaleString(undefined,{minimumFractionDigits:+el.dataset.dec,maximumFractionDigits:+el.dataset.dec});
        if(el.firstChild&&el.firstChild.nodeType===3)el.firstChild.nodeValue=t;else el.textContent=t;
      });
    }).catch(function(){});
  }
  setInterval(upd,8000);
})();
</script>
</body></html>
