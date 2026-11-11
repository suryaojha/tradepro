<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = require_user();
games_gate($u);
if (!cg_on()) { flash('info', 'The colour game is switched off right now.'); redirect('games.php'); }

$modes = cg_modes();
$secs = (int)($_GET['m'] ?? $modes[0]);
if (!in_array($secs, $modes, true)) $secs = $modes[0];
$cfg = [
    'api' => url('api/cg.php'), 'csrf' => csrf_token(), 'secs' => $secs, 'active' => $u['active_account'],
    'min' => (float)setting('cg_bet_min', 10), 'max' => (float)setting('cg_bet_max', 5000),
    'mult' => ['color' => mult('cg_mult_color', 2), 'half' => mult('cg_mult_half', 1.5), 'violet' => mult('cg_mult_violet', 4.5), 'number' => mult('cg_mult_number', 9)],
];
$u = user_page_start('Colour Game', 'games.php');
play_nav('cg');
$lbl = fn($s) => $s < 60 ? $s . ' sec' : ($s / 60) . ' min';
?>
<div class="gamehead">
  <div><h1 style="margin:0">Colour Game</h1><div class="muted" style="font-size:13px">Predict the colour or number of the next draw</div></div>
  <div class="gamewallet"><small>Demo coins</small><b id="gBal"><?= e(money(wallets_of((int)$u['id'])['demo'], 'demo')) ?></b></div>
</div>
<div class="flash info" style="font-size:13px">🎮 <b>Entertainment only – no financial loss.</b> This game uses <b>Demo coins</b> – virtual, no cash value, nothing can be deposited, withdrawn or converted. Every result is drawn at random by the server; nobody can choose or change it.</div>

<div class="tabs modetabs">
  <?php foreach ($modes as $m): ?><a href="?m=<?= $m ?>" class="<?= $m === $secs ? 'on' : '' ?>"><?= e($lbl($m)) ?></a><?php endforeach; ?>
</div>

<div class="card roundcard">
  <div class="roundtop">
    <div><div class="muted" style="font-size:12px">Period</div><div class="mono" style="font-size:18px;font-weight:800" id="gPeriod">—</div></div>
    <div style="text-align:right"><div class="muted" style="font-size:12px">Time left</div><div class="mono countdown" id="gTime">--:--</div></div>
  </div>
  <div class="gresults" id="gHist"></div>
</div>

<div class="card gamebox" id="gBox">
  <div class="glock" id="gLock">Betting closed – next period starting…</div>
  <div class="colorbtns">
    <button type="button" class="cb green" data-kind="color" data-pick="green">Green<small id="mGreen"></small></button>
    <button type="button" class="cb violet" data-kind="color" data-pick="violet">Violet<small id="mViolet"></small></button>
    <button type="button" class="cb red" data-kind="color" data-pick="red">Red<small id="mRed"></small></button>
  </div>
  <div class="numgrid" id="gNums"></div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Green: 1 3 7 9 · Red: 2 4 6 8 · 0 = Red + Violet · 5 = Green + Violet. Red / Green pay less when the number also carries violet (0 or 5).</p>
</div>

<div class="grid g2 twosec">
  <section class="card" id="secLatest">
    <h2>Latest results <span class="muted" style="font-size:13px;font-weight:500" id="resInfo"></span></h2>
    <div class="tablewrap"><table class="rt" id="gLatest"></table></div>
    <div class="pager" id="resPager"></div>
  </section>
  <section class="card" id="secMine">
    <h2>My bets <span class="muted" style="font-size:13px;font-weight:500" id="mineInfo"></span></h2>
    <div class="tablewrap"><table class="rt" id="gMine"></table></div>
    <div class="pager" id="minePager"></div>
  </section>
</div>

<script>
const CFG = <?= json_encode($cfg, JSON_HEX_TAG) ?>;
const $ = id => document.getElementById(id);
const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const COL = {0:['red','violet'],1:['green'],2:['red'],3:['green'],4:['red'],5:['green','violet'],6:['red'],7:['green'],8:['red'],9:['green']};
const money = n => '$' + (+n).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
const modeName = s => s < 60 ? s + 's' : (s / 60) + 'm';
let S = null, offset = 0, seen = null, busy = false, resPage = 1, minePage = 1, lastPeriod = '';

$('mGreen').textContent = 'x' + CFG.mult.color; $('mRed').textContent = 'x' + CFG.mult.color; $('mViolet').textContent = 'x' + CFG.mult.violet;
$('gNums').innerHTML = [0,1,2,3,4,5,6,7,8,9].map(n => `<button type="button" class="nb ${COL[n].join(' ')}" data-kind="number" data-pick="${n}">${n}<small>x${CFG.mult.number}</small></button>`).join('');

const dot = n => `<span class="gdot ${COL[n].join(' ')}">${n}</span>`;
function balance(b){
  $('gBal').textContent = money(b);
  document.querySelectorAll('[data-bal="demo"]').forEach(el => el.textContent = money(b));
  if (CFG.active === 'demo') { const a = $('activeBal'); if (a) a.textContent = money(b); }
}
function pager(el, d, go){
  if (d.pages <= 1) { el.innerHTML = ''; return; }
  el.innerHTML = `<button class="btn ghost sm" ${d.page <= 1 ? 'disabled' : ''} data-p="${d.page - 1}">‹ Prev</button><span class="muted">Page ${d.page} / ${d.pages}</span><button class="btn ghost sm" ${d.page >= d.pages ? 'disabled' : ''} data-p="${d.page + 1}">Next ›</button>`;
  el.querySelectorAll('button').forEach(b => b.onclick = () => go(+b.dataset.p));
}

// ----- latest results (separate section, paged) -----
function loadResults(p){
  resPage = p || resPage;
  fetch(`${CFG.api}?action=results&secs=${CFG.secs}&page=${resPage}`).then(r => r.json()).then(d => {
    if (!d.ok) return; resPage = d.page;
    $('resInfo').textContent = d.total ? `· ${d.total} rounds` : '';
    $('gLatest').innerHTML = '<tr><th>Period</th><th>Number</th><th>Colour</th><th>Size</th></tr>' + (d.rows.length ? d.rows.map(h =>
      `<tr><td class="mono">${esc(h.period)}</td><td>${dot(h.n)}</td>
       <td>${h.colors.map(c => `<span class="gdot sm ${c}" title="${c}"></span>`).join(' ')} <span class="muted" style="font-size:12px">${h.colors.join(' + ')}</span></td>
       <td>${h.n >= 5 ? 'Big' : 'Small'}</td></tr>`).join('') : '<tr><td colspan="4" class="muted">No results yet.</td></tr>');
    if (window.rtLabel) rtLabel($('gLatest'));
    pager($('resPager'), d, loadResults);
  }).catch(() => {});
}
// ----- my bets (separate section, paged) -----
function loadMine(p){
  minePage = p || minePage;
  fetch(`${CFG.api}?action=mine&secs=${CFG.secs}&page=${minePage}`).then(r => r.json()).then(d => {
    if (!d.ok) return; minePage = d.page;
    $('mineInfo').textContent = d.total ? `· ${d.total} bets` : '';
    $('gMine').innerHTML = '<tr><th>Period</th><th>Pick</th><th class="right">Stake</th><th>Result</th><th class="right">Return</th></tr>' + (d.rows.length ? d.rows.map(b =>
      `<tr><td class="mono">${esc(b.period)} <span class="muted" style="font-size:11px">${modeName(b.secs)}</span></td><td><b>${esc(b.pick)}</b></td><td class="right">${money(b.amount)}</td>
       <td>${b.result === null ? '<span class="badge open">waiting</span>' : dot(b.result) + ` <span class="badge ${b.status}">${b.status}</span>`}</td>
       <td class="right ${b.status==='won'?'green':b.status==='lost'?'red':''}">${b.status==='won' ? '+' + money(b.payout - b.amount) : b.status==='lost' ? '-' + money(b.amount) : '—'}</td></tr>`).join('')
      : '<tr><td colspan="5" class="muted">No bets yet – pick a colour or number above.</td></tr>');
    if (window.rtLabel) rtLabel($('gMine'));
    pager($('minePager'), d, loadMine);
  }).catch(() => {});
}

function render(){
  if (!S) return;
  $('gPeriod').textContent = S.period;
  $('gHist').innerHTML = S.history.slice(0, 12).map(h => dot(h.n)).join('') || '<span class="muted">No results yet</span>';
  balance(S.balance);
  if (S.period !== lastPeriod) { lastPeriod = S.period; if (resPage === 1) loadResults(1); loadMine(); }
}
function tickTime(){
  if (!S) return;
  const now = Date.now() / 1000 + offset, left = Math.max(0, Math.ceil(S.end - now));
  $('gTime').textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
  const locked = left <= S.lock;
  $('gBox').classList.toggle('locked', locked);
  $('gTime').classList.toggle('red', left <= 10);
  if (left === 0 && !busy) setTimeout(load, 1200);
}
function load(){
  if (busy) return; busy = true;
  fetch(`${CFG.api}?secs=${CFG.secs}`).then(r => r.json()).then(j => {
    if (j.pin) return location.reload();
    if (!j.ok) return;
    S = j.state; offset = S.now - Date.now() / 1000;
    if (seen === null) seen = new Set(S.mine.filter(b => b.status !== 'open').map(b => b.id));
    S.mine.filter(b => b.status !== 'open' && !seen.has(b.id)).forEach(b => {
      seen.add(b.id);
      const won = b.status === 'won';
      TP.toast(`Period ${b.period}: ${b.result} – you ${won ? 'WON ' + money(b.payout - b.amount) : 'lost ' + money(b.amount)}`, won ? 'success' : 'error', 6000);
      loadMine();
    });
    render();
  }).catch(() => {}).finally(() => { busy = false; });
}

// ----- placing a bet -----
const mult = (kind, pick) => kind === 'number' ? CFG.mult.number : pick === 'violet' ? CFG.mult.violet : CFG.mult.color;
function bet(kind, pick){
  const m = mult(kind, pick), name = kind === 'number' ? 'Number ' + pick : pick[0].toUpperCase() + pick.slice(1);
  Swal.fire({
    background: css('--panel'), color: css('--text'), confirmButtonColor: css('--green'), showCancelButton: true, cancelButtonColor: css('--line'), reverseButtons: true,
    title: `Bet on ${name}`, confirmButtonText: 'Place bet',
    html: `<div style="text-align:left"><div style="margin-bottom:8px;color:var(--muted)">Period ${esc(S.period)} · pays x${m}</div>
      <div class="chips" id="swChips" style="margin-bottom:10px">${[10,50,100,500,1000].filter(v => v >= CFG.min && v <= CFG.max).map(v => `<button type="button" data-v="${v}">${v}</button>`).join('')}</div>
      <input id="swAmt" class="swal2-input" type="number" min="${CFG.min}" max="${CFG.max}" value="${CFG.min}" style="margin:0;width:100%;color:var(--text)">
      <div class="chips" id="swMul" style="margin-top:10px">${[1,5,10].map(v => `<button type="button" data-m="${v}" class="${v===1?'on':''}">x${v}</button>`).join('')}</div>
      <div id="swTot" style="margin-top:12px;font-weight:700"></div></div>`,
    didOpen: () => {
      let mul = 1; const amt = document.getElementById('swAmt'), tot = document.getElementById('swTot');
      const upd = () => { const a = (parseFloat(amt.value) || 0) * mul; tot.innerHTML = `Total stake <span style="color:var(--warn)">${money(a)}</span> · return if you win <span style="color:var(--green)">${money(a * m)}</span>`; };
      document.querySelectorAll('#swChips button').forEach(b => b.onclick = () => { amt.value = b.dataset.v; upd(); });
      document.querySelectorAll('#swMul button').forEach(b => b.onclick = () => { mul = +b.dataset.m; document.querySelectorAll('#swMul button').forEach(x => x.classList.toggle('on', x === b)); upd(); });
      amt.oninput = upd; upd(); window.__mul = () => mul;
    },
    preConfirm: () => {
      const a = (parseFloat(document.getElementById('swAmt').value) || 0) * window.__mul();
      if (a < CFG.min || a > CFG.max) { Swal.showValidationMessage(`Total must be between ${money(CFG.min)} and ${money(CFG.max)}`); return false; }
      return a;
    }
  }).then(res => {
    if (!res.isConfirmed) return;
    fetch(CFG.api, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CFG.csrf},
      body:new URLSearchParams({secs:CFG.secs, kind, pick, amount:res.value, csrf:CFG.csrf})})
    .then(r => r.json()).then(j => {
      if (j.pin) return location.reload();
      if (!j.ok) return TP.alert(j.error || 'Failed', 'error');
      S = j.state; offset = S.now - Date.now() / 1000; render(); minePage = 1; loadMine(1);
      TP.toast(`Bet placed on ${name} – ${money(res.value)}`, 'success', 3000);
    }).catch(() => TP.alert('Network error', 'error'));
  });
}
document.querySelectorAll('[data-kind]').forEach(b => b.onclick = () => { if (S && !$('gBox').classList.contains('locked')) bet(b.dataset.kind, b.dataset.pick); });

load(); setInterval(tickTime, 500); setInterval(load, 4000);
</script>
<?php user_page_end();
