<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = require_user();
$uid = (int)$u['id'];

/** What the admin configured for deposits (shown to users). */
function pay_config(): array
{
    $g = fn($k) => trim((string)setting($k, ''));
    return [
        'upi'   => ['id' => $g('pay_upi_id'), 'name' => $g('pay_upi_name') ?: (string)setting('site_name', 'TradePro')],
        'bank'  => ['name' => $g('pay_bank_name'), 'holder' => $g('pay_bank_holder'), 'account' => $g('pay_bank_account'), 'ifsc' => strtoupper($g('pay_bank_ifsc')), 'extra' => $g('pay_bank_details')],
        'trc20' => $g('pay_usdt_trc20'),
        'bep20' => $g('pay_usdt_bep20'),
    ];
}

/** Deposit methods that are really set up: acc => method => label. */
function deposit_methods(array $p): array
{
    $m = ['inr' => [], 'usdt' => []];
    if ($p['upi']['id'] !== '') $m['inr']['upi'] = 'UPI (QR / apps)';
    if ($p['bank']['account'] !== '' || $p['bank']['extra'] !== '') $m['inr']['bank'] = 'Bank transfer (IMPS/NEFT)';
    if ($p['trc20'] !== '') $m['usdt']['trc20'] = 'USDT – TRC20 (Tron)';
    if ($p['bep20'] !== '') $m['usdt']['bep20'] = 'USDT – BEP20 (BSC)';
    return array_filter($m);
}
$PAY = pay_config();
$DEP = deposit_methods($PAY);

/** Validate the deposit reference for the chosen method. */
function check_reference(string $method, string $ref): string
{
    $ref = trim($ref);
    if ($method === 'upi' && !preg_match('/^\d{12}$/', $ref)) throw new RuntimeException('A UPI reference (UTR / transaction ID) is exactly 12 digits. Find it in your payment app under the payment details.');
    if ($method === 'bank' && !preg_match('/^[A-Za-z0-9\-]{8,30}$/', $ref)) throw new RuntimeException('Enter the bank transfer UTR / reference number (8–30 letters or digits).');
    if (in_array($method, ['trc20', 'bep20'], true) && !preg_match('/^(0x)?[A-Fa-f0-9]{64}$/', $ref)) throw new RuntimeException('Enter the full transaction hash (TxID) of your USDT transfer.');
    return $ref;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $acc    = $_POST['account'] ?? '';
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    try {
        if ($action === 'deposit') {
            if (!isset($DEP[$acc])) throw new RuntimeException('Deposits are not available for this account yet.');
            $method = $_POST['method'] ?? '';
            if (!isset($DEP[$acc][$method])) throw new RuntimeException('Choose a payment method.');
        }

        if ($action === 'deposit') {
            $min = (float)setting("min_deposit_$acc", 1);
            if ($amount < $min) throw new RuntimeException('Minimum deposit is ' . money($min, $acc) . '.');
            $ref = check_reference($method, (string)($_POST['details'] ?? ''));
            if (q('SELECT 1 FROM payment_requests WHERE type = "deposit" AND details = ? AND status <> "rejected" LIMIT 1', [$ref])->fetchColumn()) {
                throw new RuntimeException('This reference was already submitted.');
            }
            create_deposit($uid, $acc, $amount, $method, $ref);
            flash('ok', 'Deposit request submitted. Your balance will be credited after admin verification.');
        } elseif ($action === 'withdraw') {
            $min = (float)setting("min_withdraw_$acc", 1);
            $fee = (float)setting("withdraw_fee_$acc", 0);
            if ($amount < $min) throw new RuntimeException('Minimum withdrawal is ' . money($min, $acc) . '.');
            if ($amount <= $fee) throw new RuntimeException('Amount must be greater than the fee.');
            if (kyc_required_for_withdraw() && kyc_status($uid) !== 'approved') throw new RuntimeException('Identity verification (PAN + Aadhaar) must be approved before you can withdraw. Open Profile → Verification.');
            if (!in_array($acc, ['inr', 'usdt'], true) || !in_array($acc, accounts_on(), true)) throw new RuntimeException('Choose INR or USDT.');
            $pa = payout_find($uid, (int)($_POST['payout_id'] ?? 0));
            if (!$pa) throw new RuntimeException('Choose one of your saved payout accounts (add one in Profile → Payout details).');
            if (PAYOUT_KINDS[$pa['kind']]['acc'] !== $acc) throw new RuntimeException('That payout account cannot receive ' . strtoupper($acc) . '. Choose a matching one.');
            if ($e = pin_check($uid, (string)($_POST['pin'] ?? ''))) throw new RuntimeException($e);
            create_withdrawal($uid, $acc, $amount, $fee, $pa['kind'], payout_text($pa));
            flash('ok', 'Withdrawal requested. The amount is held until an admin processes it.');
        } elseif ($action === 'convert') {
            if (!in_array($acc, ['inr', 'usdt'], true)) throw new RuntimeException('Choose INR or USDT.');
            $to = $acc === 'inr' ? 'usdt' : 'inr';
            if ($amount <= 0) throw new RuntimeException('Enter an amount.');
            $got = convert_funds($uid, $acc, $to, $amount);
            flash('ok', 'Converted ' . money($amount, $acc) . ' to ' . money($got, $to) . '.');
        }
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('wallet: ' . $ex->getMessage());
        flash('err', 'Something went wrong. Please try again.');
    }
    redirect('wallet.php');
}

$u = user_page_start('Wallet', 'wallet.php');
$w = wallets_of($uid);
$reqs = q('SELECT * FROM payment_requests WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$uid])->fetchAll();
$rate = usdt_rate();
$convFee = (float)setting('convert_fee_percent', 0);
$depJs = [];
foreach ($DEP as $a => $ms) foreach ($ms as $k => $label) $depJs[$a][$k] = $label;
$payoutsJs = array_map(fn($p) => ['id' => (int)$p['id'], 'acc' => PAYOUT_KINDS[$p['kind']]['acc'], 'label' => $p['label'], 'mask' => payout_mask($p)], payout_list($uid));
$minDep = ['inr' => (float)setting('min_deposit_inr', 0), 'usdt' => (float)setting('min_deposit_usdt', 0)];
?>
<h1>Wallet</h1>
<div class="grid g3" style="margin-bottom:18px">
  <?php foreach (accounts_on() as $a): $m = acc_meta($a); ?>
    <div class="stat"><div class="l"><span class="dot <?= e($a) ?>"></span><?= e($m['label']) ?></div>
      <div class="v"><?= e(money($w[$a], $a)) ?></div>
      <?php if ($a === 'demo'): ?><div class="muted" style="font-size:12px">Virtual funds – not withdrawable</div>
      <?php else: ?><div class="muted" style="font-size:12px">Withdrawable balance</div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid g2">
  <div class="card">
    <h2>Deposit</h2>
    <?php if (!$DEP): ?>
      <p class="muted">Deposits are not open yet – payment details have not been set up. Please check back soon or contact support.</p>
    <?php else: ?>
    <form method="post" id="depForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="deposit">
      <div class="row">
        <div class="field"><label>Account</label>
          <select name="account" id="dAcc"><?php foreach ($DEP as $a => $_): ?><option value="<?= e($a) ?>"><?= e(acc_meta($a)['short']) ?> account</option><?php endforeach; ?></select></div>
        <div class="field"><label>Method</label><select name="method" id="dMethod"></select></div>
      </div>
      <div class="field"><label>Amount you will pay</label><input type="number" name="amount" id="dAmt" step="0.01" min="0" required inputmode="decimal"><div class="hint" id="dMin"></div></div>

      <div id="payBox" class="card" style="background:var(--bg);padding:14px;margin-bottom:14px"></div>

      <div class="field"><label id="dRefLbl">Transaction reference</label><input type="text" name="details" id="dRef" maxlength="80" required autocomplete="off"><div class="hint" id="dRefHint"></div></div>
      <button class="btn green block" type="submit">I have paid – submit deposit</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Withdraw</h2>
    <form method="post" id="wdForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
      <div class="row">
        <div class="field"><label>From account</label>
          <select name="account" id="wAcc"><option value="inr">INR account</option><option value="usdt">USDT account</option></select></div>
        <div class="field"><label>Pay out to</label><select name="payout_id" id="wPayout"></select></div>
      </div>
      <div class="field"><label>Amount</label><input type="number" name="amount" step="0.01" min="0" required inputmode="decimal"></div>
      <div id="wNone" class="flash info" style="display:none">No saved payout account for this wallet yet. <a href="<?= e(url('profile.php#payout')) ?>">Add one in Profile → Payout details</a> (one time) – then you only need your PIN to withdraw.</div>

      <?php if (kyc_required_for_withdraw() && kyc_status($uid) !== 'approved'): ?>
        <div class="flash info">Withdrawals need identity verification (PAN + Aadhaar). Status: <b><?= e(kyc_status($uid)) ?></b>. <a href="<?= e(url('kyc.php')) ?>">Verify now →</a></div>
      <?php endif; ?>
      <?php if (!pin_is_set($u)): ?>
        <div class="flash info">Set your 6-digit security PIN first – it is required for withdrawals. <a href="<?= e(url('profile.php#pin')) ?>">Set PIN →</a></div>
      <?php else: ?>
        <?= pin_input('pin', 'Security PIN') ?>
      <?php endif; ?>
      <button class="btn block" type="submit" id="wGo" <?= pin_is_set($u) ? '' : 'disabled' ?>>Request withdrawal</button>
      <p class="muted" style="font-size:12px;margin-bottom:0">Fee: <?= e(money(setting('withdraw_fee_inr'), 'inr')) ?> (INR) / <?= e(money(setting('withdraw_fee_usdt'), 'usdt')) ?> (USDT) · Min: <?= e(money(setting('min_withdraw_inr'), 'inr')) ?> / <?= e(money(setting('min_withdraw_usdt'), 'usdt')) ?>. Your payout details are saved in your profile.</p>
    </form>
  </div>
</div>

<?php if ($rate > 0): ?>
<div class="card">
  <h2>Convert INR ⇄ USDT <span class="muted" style="font-size:13px">(live rate: 1 USDT = ₹<?= e(number_format($rate, 2)) ?><?= $convFee > 0 ? ', fee ' . e($convFee) . '%' : '' ?>)</span></h2>
  <form method="post" class="row" style="align-items:flex-end">
    <?= csrf_field() ?><input type="hidden" name="action" value="convert">
    <div class="field"><label>From</label><select name="account" id="cFrom"><option value="inr">INR → USDT</option><option value="usdt">USDT → INR</option></select></div>
    <div class="field"><label>Amount</label><input type="number" name="amount" id="cAmt" step="0.01" min="0" required inputmode="decimal"></div>
    <div class="field"><label>You get (approx.)</label><input type="text" id="cOut" disabled value="—"></div>
    <div class="field" style="flex:0"><button class="btn" type="submit">Convert</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>My requests</h2>
  <div class="tablewrap"><table class="rt">
    <tr><th>#</th><th>Date</th><th>Type</th><th>Account</th><th>Method</th><th class="right">Amount</th><th>Status</th><th>Note</th></tr>
    <?php foreach ($reqs as $r): ?>
      <tr><td><?= (int)$r['id'] ?></td><td><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e(ucfirst($r['type'])) ?></td>
        <td><?= e(strtoupper($r['account_type'])) ?></td><td><?= e(strtoupper($r['method'])) ?></td>
        <td class="right"><?= e(money($r['amount'], $r['account_type'])) ?></td>
        <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td><td class="muted"><?= e($r['admin_note']) ?></td></tr>
    <?php endforeach; if (!$reqs): ?><tr><td colspan="8" class="muted">No requests yet.</td></tr><?php endif; ?>
  </table></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const PAYOUTS = <?= json_encode($payoutsJs) ?>, HAS_PIN = <?= json_encode(pin_is_set($u)) ?>;
const DEP = <?= json_encode($depJs) ?>, PAY = <?= json_encode($PAY, JSON_HEX_TAG | JSON_HEX_AMP) ?>, MIN = <?= json_encode($minDep) ?>;
const SYMS = {inr:'₹', usdt:'₮'}, SITE = <?= json_encode((string)setting('site_name', 'TradePro')) ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const row = (k, v) => v ? `<dt>${esc(k)}</dt><dd>${esc(v)}</dd><button type="button" class="copy" data-copy="${esc(v)}">Copy</button>` : '';

function fillMethods(accId, methId, MAP, cb){
  const a = $(accId), m = $(methId); if (!a || !m) return;
  const f = () => { m.innerHTML = Object.entries(MAP[a.value] || {}).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join(''); cb && cb(); };
  a.onchange = f; m.onchange = () => cb && cb(); f();
}

// ---------- deposit ----------
let qr = null;
function upiLink(extra){
  const amt = parseFloat($('dAmt').value);
  const p = new URLSearchParams({pa: PAY.upi.id, pn: PAY.upi.name, cu: 'INR', tn: 'Deposit ' + SITE});
  if (amt > 0) p.set('am', amt.toFixed(2));
  return (extra || 'upi://pay') + '?' + p.toString().replace(/\+/g, '%20');
}
function drawQr(text, caption){
  const el = $('qrCanvas'); if (!el) return;
  el.innerHTML = ''; qr = null;
  if (typeof QRCode === 'undefined') { el.textContent = 'QR library unavailable'; return; }
  qr = new QRCode(el, {text, width: 200, height: 200, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M});
  $('qrCap').textContent = caption;
}
function renderPay(){
  const acc = $('dAcc').value, k = $('dMethod').value, box = $('payBox');
  $('dMin').textContent = 'Minimum ' + SYMS[acc] + MIN[acc].toLocaleString();
  let h = '';
  if (k === 'upi') {
    const hasAmt = parseFloat($('dAmt').value) > 0;
    h = `<dl class="kv">${row('UPI ID', PAY.upi.id)}${row('Payee name', PAY.upi.name)}</dl>
      <div class="qrbox"><div id="qrCanvas"></div><div class="qrcap" id="qrCap"></div></div>
      <div class="upiapps">
        <a href="${upiLink('tez://upi/pay')}"><i class="dotc" style="background:#4285f4"></i>Google Pay</a>
        <a href="${upiLink('phonepe://pay')}"><i class="dotc" style="background:#5f259f"></i>PhonePe</a>
        <a href="${upiLink('paytmmp://pay')}"><i class="dotc" style="background:#00baf2"></i>Paytm</a>
        <a href="${upiLink('bhim://upi/pay')}"><i class="dotc" style="background:#f58220"></i>BHIM</a>
        <a href="${upiLink('upi://pay')}"><i class="dotc" style="background:#22c984"></i>Any UPI app</a>
      </div>
      <p class="muted" style="font-size:12px;margin:10px 0 0">${hasAmt ? 'The QR and app buttons already contain your amount.' : 'Enter the amount above – the QR updates automatically.'} Scan with any UPI app, or tap an app button on your phone, pay, then enter the 12-digit UTR below.</p>`;
    box.innerHTML = h;
    drawQr(upiLink(), hasAmt ? 'Pay ₹' + parseFloat($('dAmt').value).toFixed(2) : 'Scan to pay');
    $('dRefLbl').textContent = 'UPI transaction ID (UTR) – 12 digits'; $('dRef').inputMode = 'numeric'; $('dRef').maxLength = 12; $('dRef').placeholder = '123456789012';
    $('dRefHint').textContent = 'Find it in your payment app → payment details.';
  } else if (k === 'bank') {
    const b = PAY.bank;
    h = `<dl class="kv">${row('Bank', b.name)}${row('Account holder', b.holder)}${row('Account number', b.account)}${row('IFSC', b.ifsc)}</dl>
      ${b.extra ? `<p class="muted" style="margin:10px 0 0">${esc(b.extra)}</p>` : ''}
      <p class="muted" style="font-size:12px;margin:10px 0 0">Transfer by IMPS / NEFT / RTGS to this account, then enter the transfer reference (UTR).</p>`;
    box.innerHTML = h;
    $('dRefLbl').textContent = 'Bank transfer UTR / reference'; $('dRef').inputMode = 'text'; $('dRef').maxLength = 30; $('dRef').placeholder = '';
    $('dRefHint').textContent = '';
  } else {
    const addr = k === 'trc20' ? PAY.trc20 : PAY.bep20;
    h = `<dl class="kv">${row('Network', k === 'trc20' ? 'TRON (TRC20)' : 'BNB Smart Chain (BEP20)')}${row('Address', addr)}</dl>
      <div class="qrbox"><div id="qrCanvas"></div><div class="qrcap" id="qrCap"></div></div>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Send only USDT on the <b>${k.toUpperCase()}</b> network. Other networks or coins will be lost.</p>`;
    box.innerHTML = h;
    drawQr(addr, k.toUpperCase() + ' address');
    $('dRefLbl').textContent = 'Transaction hash (TxID)'; $('dRef').inputMode = 'text'; $('dRef').maxLength = 80; $('dRef').placeholder = '';
    $('dRefHint').textContent = '';
  }
}
fillMethods('dAcc', 'dMethod', DEP, renderPay);
if ($('dAmt')) $('dAmt').addEventListener('input', () => { if ($('dMethod').value === 'upi') renderPay(); });

// ---------- withdraw (saved payout accounts) ----------
function renderWd(){
  const acc = $('wAcc').value, list = PAYOUTS.filter(p => p.acc === acc);
  $('wPayout').innerHTML = list.map(p => `<option value="${p.id}">${esc(p.label)} — ${esc(p.mask)}</option>`).join('');
  $('wNone').style.display = list.length ? 'none' : '';
  $('wPayout').closest('.field').style.display = list.length ? '' : 'none';
  $('wGo').disabled = !list.length || !HAS_PIN;
}
$('wAcc').onchange = renderWd; renderWd();

// ---------- converter ----------
(function(){
  const rate = <?= json_encode($rate) ?>, fee = <?= json_encode($convFee) ?>, f = $('cFrom'), a = $('cAmt'), o = $('cOut');
  if (!f) return;
  function u(){ const v = parseFloat(a.value); if (!v) { o.value = '—'; return; }
    const got = (f.value === 'usdt' ? v * rate : v / rate) * (1 - fee / 100);
    o.value = (f.value === 'usdt' ? '₹' : '₮') + got.toLocaleString(undefined, {maximumFractionDigits: 2}); }
  f.onchange = a.oninput = u;
})();
</script>
<?php user_page_end();
