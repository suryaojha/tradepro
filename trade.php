<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = require_user();
settle_due((int)$u['id']);

$assets  = active_assets();
$acc     = $u['active_account'];
$meta    = acc_meta($acc);
[$min, $max] = trade_limits($acc);
$cfg = [
    'csrf'      => csrf_token(),
    'api'       => ['tick' => url('api/tick.php'), 'candles' => url('api/candles.php'), 'trade' => url('api/trade.php')],
    'login'     => url('login.php'),
    'account'   => $acc,
    'symbol'    => $meta['sym'],
    'min'       => $min,
    'max'       => $max,
    'durations' => durations(),
    'tfs'       => TIMEFRAMES,
    'tzoff'     => display_tz_offset(),
    'tzname'    => display_tz(),
    'theme'     => theme_now(),
    'chartDefault' => setting('chart_default', 'tradingview') === 'standard' ? 'standard' : 'tradingview',
    'enabled'   => setting('trading_enabled', '1') === '1',
    'assets'    => array_map(fn($a) => [
        'id' => (int)$a['id'], 'symbol' => $a['symbol'], 'name' => $a['name'], 'cat' => $a['category'],
        'payout' => asset_payout($a), 'dec' => (int)$a['decimals'], 'src' => $a['provider'], 'tv' => (string)($a['tv_symbol'] ?? ''),
    ], $assets),
];

page_head('Trade', 'terminal has-mnav');
user_topbar($u, 'trade.php');
render_flashes();
$ann = trim((string)setting('announcement', ''));
if ($ann !== '') echo '<div class="banner">' . e($ann) . '</div>';
if ($acc === 'demo') echo '<div class="banner demo">You are trading on the DEMO account – no real money is involved. Prices are live market prices.</div>';
?>
<div class="term">
  <aside class="assets">
    <div class="assethead">
      <input type="search" id="assetSearch" placeholder="Search markets…" aria-label="Search markets" autocomplete="off">
      <div class="catchips" id="catChips"></div>
    </div>
    <div id="assetList"></div>
  </aside>

  <section class="center-col" id="centerCol">
    <div class="mobassets"><select id="assetSel" class="s2" data-search="always"></select></div>
    <div class="charthead">
      <div><div class="big" id="hSym">—</div><div class="muted" id="hName"></div></div>
      <div><div class="big mono" id="hPrice">—</div><span class="spinner sm" id="pxSpin" style="display:none"></span><div class="livebadge"><span class="live"></span><span id="hSrc">Live</span></div></div>
      <div class="tools">
        <div class="tfs" id="tfs"></div>
        <div class="seg" id="cmode" title="Chart style"><button type="button" data-cm="tradingview">TradingView</button><button type="button" data-cm="standard">Standard</button></div>
        <div class="seg" id="ctype"><button type="button" data-ct="candle">Candles</button><button type="button" data-ct="line">Line</button></div>
        <button type="button" class="iconbtn" id="btnLatest" title="Jump to latest price" aria-label="Jump to latest price">⟫</button>
        <button type="button" class="iconbtn" id="btnSound" title="Sound on win / loss" aria-label="Toggle sound">🔔</button>
        <button type="button" class="iconbtn" id="btnFull" title="Expand chart" aria-label="Expand chart"><?= icon('expand', 18) ?></button>
      </div>
    </div>
    <div class="marketclosed" id="closedBar">This market is closed right now – prices are not updating. You can trade it again when it re-opens.</div>
    <div class="sent" id="sentBar" title="What traders predicted on this market in the last 15 minutes">
      <span class="sl" id="sentUp">▲ —</span>
      <div class="sbar"><i id="sentFill"></i></div>
      <span class="sl" id="sentDn">▼ —</span>
      <small class="muted" id="sentN"></small>
    </div>
    <div class="chartarea">
      <div id="chart"></div>
      <div id="tvchart"></div>
      <div class="chartloader" id="chartLoader"><div class="inline-loader"><div class="spinner"></div><div>Loading chart…</div></div></div>
    </div>
    <div class="tvnote" id="tvNote">Chart by TradingView. Trades settle on the live <b id="tvSrc">market</b> price shown in the header, which can differ slightly from this chart.</div>
    <div class="livestrip" id="liveStrip"></div>
    <div class="fullbar" id="fullBar">
      <select id="fDur" aria-label="Expiry"></select>
      <input type="number" id="fAmt" step="any" inputmode="decimal" aria-label="Amount">
      <button class="btn green" id="fUp" type="button">▲ UP</button>
      <button class="btn red" id="fDown" type="button">▼ DOWN</button>
    </div>
    <div class="bottom">
      <div class="tabs"><a href="#" class="on" data-tab="open">Open trades</a><a href="#" data-tab="closed">Closed</a></div>
      <div class="tablewrap"><table class="rt" id="tradeTbl"></table></div>
    </div>
  </section>

  <aside class="side">
    <div class="field">
      <label>Expiry time</label>
      <div class="chips" id="durChips"></div>
    </div>
    <div class="field">
      <label>Amount (<?= e($meta['short']) ?>)</label>
      <div class="stepper">
        <button type="button" id="amtMinus" aria-label="Decrease">−</button>
        <input type="number" id="amt" step="any" inputmode="decimal">
        <button type="button" id="amtPlus" aria-label="Increase">+</button>
      </div>
      <div class="chips" id="amtChips">
        <button type="button" data-q="min">Min</button><button type="button" data-q="half">½</button>
        <button type="button" data-q="dbl">×2</button><button type="button" data-q="max">Max</button>
      </div>
      <div class="muted" style="font-size:12px;margin-top:6px">Limits: <?= e(money($min, $acc)) ?> – <?= e(money($max, $acc)) ?></div>
    </div>
    <div class="payout">
      <div class="muted">If your prediction is right you win</div>
      <div class="pc" id="pPct">—</div>
      <div class="pf" id="pProfit">—</div>
    </div>
    <div class="muted" style="font-size:13px;margin-bottom:8px;text-align:center">Where will the price be at expiry?</div>
    <div class="tradebtns">
      <button class="btn green" id="btnUp" type="button">▲ UP</button>
      <button class="btn red" id="btnDown" type="button">▼ DOWN</button>
    </div>
    <p class="muted" style="font-size:12px;margin-top:14px">Predict whether the live price will be <b>higher (UP)</b> or <b>lower (DOWN)</b> than the price when you open the trade. Right → you win the payout. Wrong → you lose the stake. Exactly equal → stake returned. Times shown in <?= e(tz_short()) ?>.</p>
  </aside>
</div>
<div class="toasts" id="toasts"></div>

<?php select2_assets(); ?>
<script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
<script>
const CFG = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const SYM = {demo:'$', inr:'₹', usdt:'₮'};
const SRC = {binance:'Binance', yahoo:'Yahoo Finance'};
const money = (n, a, sign) => { n = +n; return (sign && n > 0 ? '+' : n < 0 ? '-' : '') + SYM[a || CFG.account] + Math.abs(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); };
const store = { get(k, d){ try { return localStorage.getItem(k) ?? d } catch(e){ return d } }, set(k, v){ try { localStorage.setItem(k, v) } catch(e){} } };
const dlabel = s => s < 60 ? s + 's' : s % 3600 === 0 ? (s/3600) + 'h' : s % 60 === 0 ? (s/60) + 'm' : Math.floor(s/60) + 'm ' + (s%60) + 's';
const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const TZ = CFG.tzoff;   // chart axis is shown in the selected IST / UTC zone
const ICON_SHRINK = <?= json_encode(icon('shrink', 18)) ?>, ICON_EXPAND = <?= json_encode(icon('expand', 18)) ?>;

let asset = null, tf = +store.get('tp_tf', 60), duration = +store.get('tp_dur', CFG.durations[0]);
let prices = {}, closed = {}, open = [], recent = [], lastCandle = null, lastReal = 0, offset = 0, busy = false, tab = 'open', lastPx = null;
let chart, series = null, seriesType = '', lines = {}, ctype = store.get('tp_ct', '');

const isClosed = a => !!(a && closed[a.id]);

// ---------- asset list ----------
let favs = []; try { favs = JSON.parse(store.get('tp_fav', '[]')) || []; } catch(e){}
let catF = store.get('tp_cat', 'all'), search = '';
const cats = ['all', 'fav', ...new Set(CFG.assets.map(a => a.cat))];

function renderCats(){
  if (!cats.includes(catF)) catF = 'all';
  $('catChips').innerHTML = cats.map(c => `<button type="button" data-c="${esc(c)}" class="${c===catF?'on':''}">${c==='all'?'All':c==='fav'?'★ Favourites':esc(c[0].toUpperCase()+c.slice(1))}</button>`).join('');
  $('catChips').querySelectorAll('button').forEach(b => b.onclick = () => { catF = b.dataset.c; store.set('tp_cat', catF); renderCats(); renderAssets(); });
}
$('assetSearch').oninput = e => { search = e.target.value.trim().toLowerCase(); renderAssets(); };
const visible = a => (catF === 'all' || (catF === 'fav' ? favs.includes(a.id) : a.cat === catF)) && (!search || (a.symbol + ' ' + a.name).toLowerCase().includes(search));

function renderAssets(){
  const list = CFG.assets.filter(visible);
  $('assetList').innerHTML = list.map(a =>
    `<div class="asset ${asset && asset.id===a.id?'on':''} ${isClosed(a)?'closed':''}" data-id="${a.id}">
       <button type="button" class="star ${favs.includes(a.id)?'on':''}" data-star="${a.id}" aria-label="Favourite">★</button>
       <div class="grow"><div class="sym">${esc(a.symbol)}</div><div class="nm">${esc(a.name)}</div></div>
       <div class="px"><div class="pv" id="px${a.id}">${prices[a.id]!==undefined?prices[a.id].toFixed(a.dec):''}</div>${isClosed(a)?'<div class="cl">CLOSED</div>':''}</div>
     </div>`).join('') || '<p class="muted center" style="padding:20px 10px">No markets match.</p>';
  $('assetList').querySelectorAll('.asset').forEach(el => el.onclick = () => selectAsset(+el.dataset.id));
  $('assetList').querySelectorAll('.star').forEach(el => el.onclick = e => {
    e.stopPropagation(); const id = +el.dataset.star;
    favs = favs.includes(id) ? favs.filter(x => x !== id) : [...favs, id]; store.set('tp_fav', JSON.stringify(favs)); renderAssets();
  });
  $('assetSel').innerHTML = CFG.assets.map(a => `<option value="${a.id}" ${asset&&asset.id===a.id?'selected':''}>${favs.includes(a.id)?'★ ':''}${esc(a.symbol)} – ${esc(a.name)}${isClosed(a)?' · closed':''}</option>`).join('');
  syncAssetSel();
}
$('assetSel').onchange = e => selectAsset(+e.target.value);
function syncAssetSel(){ if (window.jQuery && jQuery('#assetSel').data('select2')) jQuery('#assetSel').trigger('change.select2'); }

function selectAsset(id){
  asset = CFG.assets.find(a => a.id === id) || CFG.assets[0];
  if (!asset) return;
  store.set('tp_asset', asset.id);
  $('hSym').textContent = asset.symbol; $('hName').textContent = asset.name;
  $('hSrc').textContent = 'Live · ' + (SRC[asset.src] || 'market');
  lastPx = null; lastCandle = null;          // never paint the new market's price onto the old chart
  $('hPrice').textContent = '…'; waitForPrice();
  $('sentUp').textContent = '▲ —'; $('sentDn').textContent = '▼ —'; $('sentFill').style.width = '50%'; $('sentN').textContent = '';
  renderAssets(); updatePayout(); renderCtype(); refreshChart(); syncClosed();
  if (prices[asset.id] !== undefined) $('hPrice').textContent = prices[asset.id].toFixed(asset.dec);
  tick(true);
}

// ---------- prediction sentiment (what other traders chose in the last 15 min) ----------
function renderSentiment(s){
  if (!s || !s.total) { $('sentUp').textContent = '▲ —'; $('sentDn').textContent = '▼ —'; $('sentFill').style.width = '50%'; $('sentN').textContent = 'No predictions yet'; return; }
  const up = Math.round(100 * s.up / s.total);
  $('sentUp').textContent = '▲ ' + up + '%'; $('sentDn').textContent = '▼ ' + (100 - up) + '%';
  $('sentFill').style.width = up + '%'; $('sentN').textContent = s.total + ' prediction' + (s.total === 1 ? '' : 's');
}

// ---------- sound ----------
let sound = store.get('tp_sound', '1') === '1', actx = null;
function renderSound(){ $('btnSound').textContent = sound ? '🔔' : '🔕'; $('btnSound').title = sound ? 'Sound on' : 'Sound off'; }
$('btnSound').onclick = () => { sound = !sound; store.set('tp_sound', sound ? '1' : '0'); renderSound(); if (sound) beep(660, .08); };
function beep(freq, dur, delay){
  if (!sound) return;
  try {
    actx = actx || new (window.AudioContext || window.webkitAudioContext)();
    const o = actx.createOscillator(), g = actx.createGain(), t = actx.currentTime + (delay || 0);
    o.frequency.value = freq; o.type = 'sine'; g.gain.setValueAtTime(.12, t); g.gain.exponentialRampToValueAtTime(.001, t + dur);
    o.connect(g); g.connect(actx.destination); o.start(t); o.stop(t + dur + .02);
  } catch(e){}
}
const playResult = win => win ? (beep(660, .12), beep(880, .18, .13)) : beep(220, .3);
renderSound();

function syncClosed(){
  const c = isClosed(asset);
  $('closedBar').classList.toggle('show', c);
  const wait = pending !== null;               // fresh price for the new market not here yet
  $('btnUp').disabled = $('btnDown').disabled = $('fUp').disabled = $('fDown').disabled = c || wait || !CFG.enabled;
  $('pxSpin').style.display = wait ? 'inline-block' : 'none';
  $('hPrice').style.opacity = wait ? .35 : 1;
}
let pending = null, pendingTimer = null;
function waitForPrice(){
  pending = asset.id;
  clearTimeout(pendingTimer);
  pendingTimer = setTimeout(() => { if (pending !== null) { pending = null; syncClosed(); } }, 8000);   // never block trading forever
  $('chartLoader').classList.add('show');
}

// ---------- chart ----------
function initChart(){
  const el = $('chart');
  chart = LightweightCharts.createChart(el, {
    layout:{background:{color:css('--bg')}, textColor:css('--muted')},
    grid:{vertLines:{color:css('--line')}, horzLines:{color:css('--line')}},
    rightPriceScale:{borderColor:css('--line')},
    timeScale:{borderColor:css('--line'), timeVisible:true, secondsVisible:false, rightOffset:6},
    crosshair:{mode:0}, width:el.clientWidth, height:el.clientHeight,
    handleScroll:{pressedMouseMove:true, horzTouchDrag:true}, handleScale:{pinch:true, mouseWheel:true},
  });
  new ResizeObserver(() => chart.applyOptions({width:el.clientWidth, height:el.clientHeight})).observe(el);
}

// chart type: candles or line. Auto = line for Yahoo assets (their 1-minute forex bars are coarse), candles for Binance.
const curType = () => ctype || (asset && asset.src === 'yahoo' ? 'line' : 'candle');
function ensureSeries(precision, minMove){
  const want = curType();
  if (series && seriesType === want) { series.applyOptions({priceFormat:{type:'price', precision, minMove}}); return; }
  if (series) { try { chart.removeSeries(series); } catch(e){} }
  lines = {};
  const pf = {priceFormat:{type:'price', precision, minMove}};
  series = want === 'line'
    ? chart.addAreaSeries({lineColor:css('--accent'), topColor:'rgba(76,130,255,.32)', bottomColor:'rgba(76,130,255,0)', lineWidth:2, ...pf})
    : chart.addCandlestickSeries({upColor:'#22c984', downColor:'#f2495e', borderVisible:false, wickUpColor:'#22c984', wickDownColor:'#f2495e', ...pf});
  seriesType = want;
}
const toPoint = c => seriesType === 'line' ? {time:c.time, value:c.close} : c;
function renderCtype(){
  document.querySelectorAll('#ctype button').forEach(b => b.classList.toggle('on', b.dataset.ct === curType()));
}
document.querySelectorAll('#ctype button').forEach(b => b.onclick = () => { ctype = b.dataset.ct; store.set('tp_ct', ctype); renderCtype(); refreshChart(); });

function renderTfs(){
  const label = s => s < 3600 ? (s / 60) + 'm' : (s / 3600) + 'h';
  $('tfs').innerHTML = CFG.tfs.map(s => `<button data-tf="${s}" class="${s===tf?'on':''}">${label(s)}</button>`).join('');
  $('tfs').querySelectorAll('button').forEach(b => b.onclick = () => { tf = +b.dataset.tf; store.set('tp_tf', tf); renderTfs(); refreshChart(); });
}

// ---------- TradingView (free widget) + chart mode ----------
let chartMode = store.get('tp_cm', '') || CFG.chartDefault, tvKey = '', tvLoading = null;
const useTV = () => chartMode === 'tradingview' && asset && asset.tv;
const TV_IV = {60:'1', 300:'5', 900:'15', 3600:'60'};
function renderMode(){
  $('centerCol').classList.toggle('tv', !!useTV());
  document.querySelectorAll('#cmode button').forEach(b => {
    const tvb = b.dataset.cm === 'tradingview';
    b.disabled = tvb && !(asset && asset.tv);
    b.classList.toggle('on', b.dataset.cm === (useTV() ? 'tradingview' : 'standard'));
  });
  $('tvSrc').textContent = asset ? (SRC[asset.src] || 'market') : 'market';
}
document.querySelectorAll('#cmode button').forEach(b => b.onclick = () => { chartMode = b.dataset.cm; store.set('tp_cm', chartMode); refreshChart(); });
function hideLoader(){ $('chartLoader').classList.remove('show'); }
function loadTV(){
  const key = asset.tv + '|' + tf + '|' + CFG.tzname;
  if (key === tvKey && $('tvchart').firstChild) return;
  tvKey = key;
  $('chartLoader').classList.add('show');
  $('tvchart').innerHTML = '';
  const run = () => {
    try {
      new TradingView.widget({autosize:true, symbol:asset.tv, interval:TV_IV[tf] || '1', timezone:CFG.tzname === 'UTC' ? 'Etc/UTC' : CFG.tzname,
        theme:CFG.theme, style:'1', locale:'en', toolbar_bg:css('--panel'), backgroundColor:css('--bg'), gridColor:css('--line'), enable_publishing:false, hide_side_toolbar:false,
        allow_symbol_change:false, save_image:false, withdateranges:true, container_id:'tvchart'});
    } catch(e){ tvKey = ''; chartMode = 'standard'; refreshChart(); toast('TradingView could not load – showing the standard chart.', 'err'); return; }
    const t0 = Date.now(), iv = setInterval(() => {
      const f = $('tvchart').querySelector('iframe');
      if (f) { f.addEventListener('load', hideLoader, {once:true}); clearInterval(iv); }
      else if (Date.now() - t0 > 8000) { clearInterval(iv); hideLoader(); }
    }, 100);
    setTimeout(hideLoader, 6000);
  };
  if (window.TradingView) return run();
  if (!tvLoading) tvLoading = new Promise((ok, no) => { const s = document.createElement('script'); s.src = 'https://s3.tradingview.com/tv.js'; s.onload = ok; s.onerror = no; document.head.appendChild(s); });
  tvLoading.then(run).catch(() => { tvLoading = null; tvKey = ''; chartMode = 'standard'; refreshChart(); toast('TradingView is not reachable – showing the standard chart.', 'err'); });
}
function refreshChart(){
  if (!asset || !chart) return;
  renderMode();
  if (useTV()) { lastCandle = null; loadTV(); }
  else { tvKey = ''; $('tvchart').innerHTML = ''; loadCandles(); }
}

function loadCandles(){
  if (!asset || !chart) return;
  const id = asset.id, myTf = tf;
  lastCandle = null;
  $('chartLoader').classList.add('show');
  fetch(`${CFG.api.candles}?asset=${id}&tf=${myTf}`).then(r => r.json()).then(j => {
    if (!j.ok || id !== asset.id || myTf !== tf) return;
    offset = j.now - Date.now() / 1000;
    ensureSeries(j.decimals, 1 / Math.pow(10, j.decimals)); renderCtype();
    const data = j.candles.map(c => ({...c, time: c.time + TZ}));
    series.setData(data.map(toPoint));
    if (j.candles.length) {
      lastReal = j.candles[j.candles.length - 1].time;
      lastCandle = data[data.length - 1];
    } else { lastCandle = null; }
    if (data.length) chart.timeScale().scrollToRealTime();
    drawLines();
    if (!data.length) toast('Chart data is not available for this market right now.', 'err');
  }).catch(() => {}).finally(() => { if (id === asset.id && myTf === tf) hideLoader(); });
}

function pushPrice(p){
  if (!lastCandle || !series) return;
  const now = Date.now() / 1000 + offset, n = Math.floor((now - lastReal) / tf);
  if (n >= 1) {
    lastReal += n * tf;
    lastCandle = {time:lastReal + TZ, open:lastCandle.close, high:Math.max(lastCandle.close,p), low:Math.min(lastCandle.close,p), close:p};
  } else { lastCandle.high = Math.max(lastCandle.high, p); lastCandle.low = Math.min(lastCandle.low, p); lastCandle.close = p; }
  series.update(toPoint(lastCandle));
}

// price lines for this asset's open trades
function drawLines(){
  if (!series) return;
  const want = {};
  open.filter(t => asset && +t.asset_id === asset.id).forEach(t => want[t.id] = t);
  for (const id in lines) if (!want[id]) { try { series.removePriceLine(lines[id]); } catch(e){} delete lines[id]; }
  const now = Date.now() / 1000 + offset;
  for (const id in want) {
    const t = want[id], left = Math.max(0, Math.ceil(t.expiry_ts - now));
    const title = (t.direction==='up'?'▲ UP ':'▼ DOWN ') + money(t.amount, t.account_type) + ' · ' + Math.floor(left/60) + ':' + String(left%60).padStart(2,'0');
    if (!lines[id]) lines[id] = series.createPriceLine({price:+t.open_price, color:t.direction==='up'?'#22c984':'#f2495e', lineWidth:1, lineStyle:2, axisLabelVisible:true, title});
    else lines[id].applyOptions({title});
  }
  // arrows at the moment each prediction was made
  if (lastReal) {
    const mk = Object.values(want).map(t => {
      const k = Math.max(0, Math.ceil((lastReal - t.open_ts) / tf));
      return {time: lastReal - k * tf + TZ, position: t.direction==='up' ? 'belowBar' : 'aboveBar', color: t.direction==='up' ? '#22c984' : '#f2495e',
              shape: t.direction==='up' ? 'arrowUp' : 'arrowDown', text: t.direction==='up' ? 'UP' : 'DOWN'};
    }).sort((a, b) => a.time - b.time);
    try { series.setMarkers(mk); } catch(e){}
  }
}
$('btnLatest').onclick = () => chart && chart.timeScale().scrollToRealTime();

// ---------- expand / full screen chart ----------
function setFull(on){
  $('centerCol').classList.toggle('full', on);
  document.body.classList.toggle('no-scroll', on);
  $('btnFull').innerHTML = on ? ICON_SHRINK : ICON_EXPAND;
  $('btnFull').title = on ? 'Close full screen' : 'Expand chart';
  setTimeout(() => { chart.applyOptions({width:$('chart').clientWidth, height:$('chart').clientHeight}); chart.timeScale().scrollToRealTime(); }, 60);
}
$('btnFull').onclick = () => setFull(!$('centerCol').classList.contains('full'));
document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('centerCol').classList.contains('full')) setFull(false); });

// ---------- trade panel ----------
function renderDurations(){
  $('durChips').innerHTML = CFG.durations.map(s => `<button type="button" data-d="${s}" class="${s===duration?'on':''}">${dlabel(s)}</button>`).join('');
  $('durChips').querySelectorAll('button').forEach(b => b.onclick = () => setDuration(+b.dataset.d));
  $('fDur').innerHTML = CFG.durations.map(s => `<option value="${s}" ${s===duration?'selected':''}>${dlabel(s)}</option>`).join('');
}
function setDuration(d){ duration = d; store.set('tp_dur', d); renderDurations(); }
$('fDur').onchange = e => setDuration(+e.target.value);

const amt = () => { const v = parseFloat($('amt').value); return isNaN(v) ? 0 : v; };
function setAmt(v){ v = Math.min(CFG.max, Math.max(CFG.min, Math.round(v * 100) / 100)); $('amt').value = v; $('fAmt').value = v; store.set('tp_amt_' + CFG.account, v); updatePayout(); }
function updatePayout(){
  if (!asset) return;
  $('pPct').textContent = asset.payout + '%';
  $('pProfit').textContent = '+' + money(amt() * asset.payout / 100);
}
$('amt').oninput = updatePayout;
$('amt').onchange = () => setAmt(amt());
$('fAmt').onchange = e => setAmt(parseFloat(e.target.value) || 0);
$('amtMinus').onclick = () => setAmt(amt() - CFG.min);
$('amtPlus').onclick = () => setAmt(amt() + CFG.min);
$('amtChips').querySelectorAll('button').forEach(b => b.onclick = () => {
  const q = b.dataset.q, bal = +(window.__bal || 0);
  setAmt(q === 'min' ? CFG.min : q === 'half' ? amt() / 2 : q === 'dbl' ? amt() * 2 : Math.min(CFG.max, bal));
});

function toast(msg, cls){
  if (window.TP && TP.ready()) {
    const icon = cls === 'won' ? 'success' : (cls === 'lost' || cls === 'err') ? 'error' : 'info';
    return TP.toast(String(msg).replace(/<br\s*\/?>/g, ' · ').replace(/<[^>]+>/g, '').replace(/&amp;/g, '&'), icon, 5000);
  }
  const d = document.createElement('div');
  d.className = 'toast ' + (cls || ''); d.innerHTML = msg;
  $('toasts').appendChild(d);
  setTimeout(() => d.remove(), 6000);
}

function place(dir){
  if (!asset || !CFG.enabled) return toast('Trading is disabled.', 'err');
  if (isClosed(asset)) return toast('This market is closed.', 'err');
  setAmt(amt());
  const bs = [$('btnUp'), $('btnDown'), $('fUp'), $('fDown')];
  bs.forEach(b => b.disabled = true);
  fetch(CFG.api.trade, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-Token':CFG.csrf},
    body:new URLSearchParams({asset:asset.id, direction:dir, amount:amt(), duration, csrf:CFG.csrf})})
  .then(r => r.json()).then(j => {
    if (j.login) return location.href = CFG.login;
    if (!j.ok) return toast(esc(j.error || 'Failed'), 'err');
    toast(`${dir==='up'?'▲ UP':'▼ DOWN'} predicted on <b>${esc(asset.symbol)}</b> @ ${(+j.trade.open_price).toFixed(asset.dec)} · ${dlabel(duration)}`);
    beep(520, .07);
    applyBalances(j.balances); tick(true);
  }).catch(() => toast('Network error', 'err'))
  .finally(() => { bs.forEach(b => b.disabled = false); syncClosed(); });
}
$('btnUp').onclick = $('fUp').onclick = () => place('up');
$('btnDown').onclick = $('fDown').onclick = () => place('down');

// ---------- live strip: every open prediction with a live win / lose state ----------
function renderStrip(){
  const now = Date.now() / 1000 + offset;
  $('liveStrip').innerHTML = open.map(t => {
    const a = CFG.assets.find(x => x.id === +t.asset_id), p = prices[t.asset_id];
    const win = p !== undefined && (t.direction === 'up' ? p > +t.open_price : p < +t.open_price);
    const tie = p !== undefined && p === +t.open_price;
    const left = Math.max(0, Math.ceil(t.expiry_ts - now));
    return `<span class="lchip ${tie ? '' : win ? 'win' : 'lose'}">${t.direction==='up'?'▲':'▼'} ${esc(t.symbol)} <span class="mono">${(+t.open_price).toFixed(a?a.dec:4)}</span> → <span class="mono">${p!==undefined?p.toFixed(a?a.dec:4):'—'}</span>
      <span>${tie ? 'even' : win ? 'winning' : 'losing'}</span> <span class="mono">${Math.floor(left/60)}:${String(left%60).padStart(2,'0')}</span></span>`;
  }).join('');
}

// ---------- trades table ----------
function renderTrades(){
  const now = Date.now() / 1000 + offset;
  let h;
  if (tab === 'open') {
    h = '<tr><th>Asset</th><th>Side</th><th>Stake</th><th>Entry</th><th>Now</th><th>Time left</th></tr>' +
      (open.length ? open.map(t => {
        const a = CFG.assets.find(x => x.id === +t.asset_id), p = prices[t.asset_id];
        const win = p !== undefined && (t.direction === 'up' ? p > +t.open_price : p < +t.open_price);
        const left = Math.max(0, Math.ceil(t.expiry_ts - now));
        return `<tr><td><b>${esc(t.symbol)}</b></td><td class="${t.direction==='up'?'green':'red'}">${t.direction==='up'?'▲ UP':'▼ DOWN'}</td>
          <td>${money(t.amount, t.account_type)}</td><td class="mono">${(+t.open_price).toFixed(a?a.dec:4)}</td>
          <td class="mono ${win?'green':'red'}">${p!==undefined?p.toFixed(a?a.dec:4):'—'}</td><td class="mono">${Math.floor(left/60)}:${String(left%60).padStart(2,'0')}</td></tr>`;
      }).join('') : '<tr><td colspan="6" class="muted">No open trades</td></tr>');
  } else {
    h = '<tr><th>Asset</th><th>Side</th><th>Stake</th><th>Result</th><th>Profit</th></tr>' +
      (recent.length ? recent.map(t => `<tr><td><b>${esc(t.symbol)}</b></td><td class="${t.direction==='up'?'green':'red'}">${t.direction==='up'?'▲':'▼'}</td>
        <td>${money(t.amount, t.account_type)}</td><td><span class="badge ${t.status}">${t.status}</span></td>
        <td class="${+t.profit>0?'green':+t.profit<0?'red':''}">${money(t.profit, t.account_type, true)}</td></tr>`).join('')
        : '<tr><td colspan="5" class="muted">No closed trades yet</td></tr>');
  }
  $('tradeTbl').innerHTML = h;
  if (window.rtLabel) rtLabel($('tradeTbl'));
}
document.querySelectorAll('.bottom .tabs a').forEach(a => a.onclick = e => {
  e.preventDefault(); tab = a.dataset.tab;
  document.querySelectorAll('.bottom .tabs a').forEach(x => x.classList.toggle('on', x === a));
  renderTrades();
});

// ---------- balances + polling ----------
function applyBalances(b){
  if (!b) return;
  window.__bal = b[CFG.account];
  const ab = $('activeBal'); if (ab) ab.textContent = money(b[CFG.account]);
  document.querySelectorAll('[data-bal]').forEach(el => el.textContent = money(b[el.dataset.bal], el.dataset.bal));
}

function tick(force){
  if (busy && !force) return; busy = true;
  const reqAsset = asset ? asset.id : 0;
  fetch(CFG.api.tick + '?focus=' + reqAsset).then(r => { if (r.status === 401) { location.href = CFG.login; throw 0; } return r.json(); }).then(j => {
    if (!j.ok) return;
    if (pending !== null && pending === reqAsset && asset && asset.id === reqAsset && (j.prices[reqAsset] !== undefined || (j.closed || {})[reqAsset])) {
      pending = null; clearTimeout(pendingTimer);       // the server just fetched a fresh price for this market
    }
    offset = j.now - Date.now() / 1000;
    const wasClosed = JSON.stringify(closed);
    prices = j.prices; closed = j.closed || {}; open = j.open; recent = j.recent;
    if (j.payout && asset && j.payout !== asset.payout) { asset.payout = j.payout; updatePayout(); }
    renderSentiment(j.sentiment);
    if (JSON.stringify(closed) !== wasClosed) renderAssets();
    applyBalances(j.balances);
    CFG.assets.forEach(a => { const el = $('px' + a.id); if (el && prices[a.id] !== undefined) el.textContent = prices[a.id].toFixed(a.dec); });
    if (asset && prices[asset.id] !== undefined) {
      const p = prices[asset.id], hp = $('hPrice');
      hp.textContent = p.toFixed(asset.dec);
      if (lastPx !== null && p !== lastPx) { hp.className = 'big mono ' + (p > lastPx ? 'green' : 'red'); }
      lastPx = p;
      if (!isClosed(asset)) pushPrice(p);
    }
    syncClosed();
    j.settled.forEach(t => {
      const w = t.status === 'won', d = t.status === 'draw';
      if (!d) playResult(w);
      toast(`<b>${esc(t.symbol)}</b> ${d ? (t.settle_note ? esc(t.settle_note) : 'draw – stake returned') : w ? 'WON' : 'lost'}<br><span class="${w?'green':d?'':'red'}">${money(t.profit, t.account_type, true)}</span>`, d ? '' : (w ? 'won' : 'lost'));
    });
    drawLines(); renderTrades(); renderStrip();
  }).catch(() => {}).finally(() => { busy = false; });
}

// ---------- boot ----------
(function(){
  if (!CFG.assets.length) { $('chart').innerHTML = '<p class="muted center" style="padding:40px">No markets are available right now.</p>'; return; }
  if (!CFG.durations.includes(duration)) duration = CFG.durations[0];
  if (!CFG.tfs.includes(tf)) tf = CFG.tfs[0];
  initChart(); renderTfs(); renderDurations(); renderCats();
  $('amt').value = store.get('tp_amt_' + CFG.account, CFG.min);
  setAmt(amt());
  const saved = +store.get('tp_asset', CFG.assets[0].id);
  selectAsset(CFG.assets.some(a => a.id === saved) ? saved : CFG.assets[0].id);
  setInterval(tick, 1000);
})();
</script>
</body></html>
