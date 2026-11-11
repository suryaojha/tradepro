<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

require_admin();

function valid_payout($v): ?float
{
    $v = (float)$v;
    return ($v >= 1 && $v <= 100) ? round($v, 2) : null;
}

function clean_cat(?string $c): string
{
    return preg_replace('/[^a-z]/', '', strtolower((string)$c)) ?: 'forex';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'global_payout') {
            $p = valid_payout($_POST['payout_percent'] ?? 0);
            if ($p === null) throw new RuntimeException('Winning % must be between 1 and 100.');
            set_setting('payout_percent', (string)$p);
            admin_log('payout_global', "$p%");
            flash('ok', "Winning payout is now $p% for every market without its own override. It applies to trades opened from now on.");
        } elseif ($action === 'save') {
            $n = 0;
            foreach ((array)($_POST['name'] ?? []) as $aid => $name) {
                $aid = (int)$aid;
                $name = trim((string)$name);
                if ($name === '') throw new RuntimeException('Market name cannot be empty.');
                $ov = trim((string)($_POST['payout'][$aid] ?? ''));
                $ovv = null;
                if ($ov !== '') {
                    $ovv = valid_payout($ov);
                    if ($ovv === null) throw new RuntimeException('A market payout override must be between 1% and 100% (leave empty to use the global %).');
                }
                q('UPDATE assets SET name = ?, category = ?, decimals = ?, sort_order = ?, payout_percent = ? WHERE id = ?', [
                    mb_substr($name, 0, 60), clean_cat($_POST['cat'][$aid] ?? 'forex'),
                    max(0, min(8, (int)($_POST['dec'][$aid] ?? 4))), (int)($_POST['sort'][$aid] ?? 0), $ovv, $aid,
                ]);
                $n++;
            }
            admin_log('assets_save', "$n markets updated");
            flash('ok', 'Markets saved.');
        } elseif ($action === 'toggle') {
            $aid = (int)($_POST['id'] ?? 0);
            $a = q('SELECT symbol, is_active FROM assets WHERE id = ?', [$aid])->fetch();
            if (!$a) throw new RuntimeException('Market not found.');
            $new = $a['is_active'] ? 0 : 1;
            q('UPDATE assets SET is_active = ? WHERE id = ?', [$new, $aid]);
            admin_log($new ? 'market_activate' : 'market_deactivate', $a['symbol']);
            flash('ok', $a['symbol'] . ($new ? ' is now ACTIVE – users can trade it.' : ' is now DEACTIVATED – hidden from users (open trades still settle).'));
        } elseif ($action === 'bulk_active') {
            $cat = clean_cat($_POST['category'] ?? '');
            $state = ($_POST['state'] ?? '') === '1' ? 1 : 0;
            $n = $cat === 'all'
                ? q('UPDATE assets SET is_active = ?', [$state])->rowCount()
                : q('UPDATE assets SET is_active = ? WHERE category = ?', [$state, $cat])->rowCount();
            admin_log('market_bulk', ($state ? 'activate ' : 'deactivate ') . $cat);
            flash('ok', ($state ? 'Activated ' : 'Deactivated ') . $n . ' market(s).');
        } elseif ($action === 'sources') {
            $do = (string)($_POST['do'] ?? 'save');
            $n = 0;
            foreach ((array)($_POST['prov'] ?? []) as $aid => $prov) {
                $aid = (int)$aid;
                $a = q('SELECT * FROM assets WHERE id = ?', [$aid])->fetch();
                if (!$a) continue;
                $prov = (string)$prov;
                $sym = trim((string)($_POST['src'][$aid] ?? ''));
                $cprov = (string)($_POST['cprov'][$aid] ?? '');
                $csym = trim((string)($_POST['csrc'][$aid] ?? ''));
                if (!isset(PROVIDERS[$prov]) || $sym === '' || strlen($sym) > 40) throw new RuntimeException($a['symbol'] . ': choose a price source and symbol.');
                if ($cprov !== '' && (!isset(PROVIDERS[$cprov]) || $csym === '' || strlen($csym) > 40)) throw new RuntimeException($a['symbol'] . ': enter the chart symbol or set the chart source to "same as price".');
                $changed = $prov !== $a['provider'] || $sym !== $a['source_symbol'];
                if ($changed) {
                    if (q("SELECT 1 FROM trades WHERE asset_id = ? AND status = 'open' LIMIT 1", [$aid])->fetchColumn()) {
                        throw new RuntimeException($a['symbol'] . ' has open trades – change its price source after they expire.');
                    }
                    $t = provider_test($prov, $sym);
                    if (!$t['ok']) throw new RuntimeException($a['symbol'] . ': ' . $t['error']);
                }
                $tv = trim((string)($_POST['tv'][$aid] ?? ''));
                if ($tv !== '' && !preg_match('/^[A-Za-z0-9_.!\-]{1,30}(:[A-Za-z0-9_.!\-]{1,30})?$/', $tv)) throw new RuntimeException($a['symbol'] . ': TradingView symbol looks wrong (example: BINANCE:BTCUSDT or FX:EURUSD).');
                q('UPDATE assets SET provider = ?, source_symbol = ?, chart_provider = ?, chart_symbol = ?, tv_symbol = ? WHERE id = ?',
                    [$prov, $sym, $cprov === '' ? null : $cprov, $cprov === '' ? null : $csym, $tv === '' ? null : $tv, $aid]);
                $n++;
            }
            admin_log('sources_save', "$n markets");
            flash('ok', 'Data sources saved.');
        } elseif ($action === 'test_source') {
            $aid = (int)($_POST['id'] ?? 0);
            $a = q('SELECT * FROM assets WHERE id = ?', [$aid])->fetch();
            if (!$a) throw new RuntimeException('Market not found.');
            $t = provider_test($a['provider'], $a['source_symbol']);
            $msg = $a['symbol'] . ' price: ' . ($t['ok'] ? 'OK – ' . $t['price'] : 'FAILED – ' . $t['error']);
            $cp = $a['chart_provider'] ?: $a['provider'];
            $cs = $a['chart_provider'] ? $a['chart_symbol'] : $a['source_symbol'];
            $c = provider_chart_test($cp, (string)$cs);
            $msg .= ' · chart (' . $cp . '): ' . ($c['ok'] ? 'OK – ' . $c['bars'] . ' bars' : 'FAILED – ' . $c['error']);
            flash($t['ok'] && $c['ok'] ? 'ok' : 'err', $msg);
        } elseif ($action === 'keys') {
            $do = (string)($_POST['do'] ?? 'save');
            foreach (KEYED_PROVIDERS as $p) {
                $v = trim((string)($_POST['key_' . $p] ?? ''));
                if ($v !== '') {
                    if (!preg_match('/^[A-Za-z0-9._\-]{6,120}$/', $v)) throw new RuntimeException(PROVIDERS[$p] . ': that key looks wrong (letters and digits only).');
                    set_setting('key_' . $p, secret_encrypt($v));
                }
                if (!empty($_POST['clear_' . $p])) set_setting('key_' . $p, '');
            }
            admin_log('api_keys_save');
            if (str_starts_with($do, 'test:')) {
                $p = substr($do, 5);
                if (!in_array($p, KEYED_PROVIDERS, true)) throw new RuntimeException('Unknown provider.');
                $sym = trim((string)($_POST['sample_' . $p] ?? '')) ?: PROVIDER_SAMPLE[$p];
                $t = provider_test($p, $sym);
                $c = $t['ok'] ? provider_chart_test($p, $sym) : ['ok' => false, 'error' => 'skipped'];
                if ($t['ok']) {
                    flash('ok', PROVIDERS[$p] . " key works – live price of $sym is " . $t['price'] . '. Chart data: ' . ($c['ok'] ? $c['bars'] . ' bars OK' : 'not available (' . $c['error'] . ')') . '.');
                } else {
                    flash('err', PROVIDERS[$p] . ' test failed: ' . $t['error']);
                }
            } else {
                flash('ok', 'API keys saved (stored encrypted).');
            }
        } elseif ($action === 'add') {
            $sym = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['symbol'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $prov = (string)($_POST['provider'] ?? '');
            $srcSym = trim((string)($_POST['source_symbol'] ?? ''));
            $cprov = (string)($_POST['chart_provider'] ?? '');
            $csym = trim((string)($_POST['chart_symbol'] ?? ''));
            $dec = max(0, min(8, (int)($_POST['decimals'] ?? 4)));
            $ov = trim((string)($_POST['payout_percent'] ?? ''));
            $ovv = $ov === '' ? null : valid_payout($ov);
            if ($sym === '' || strlen($sym) > 20 || $name === '' || $srcSym === '' || strlen($srcSym) > 40 || !isset(PROVIDERS[$prov])) {
                throw new RuntimeException('Fill symbol, name, data source and source symbol.');
            }
            if ($ov !== '' && $ovv === null) throw new RuntimeException('Payout override must be 1–100 (or empty).');
            if ($cprov !== '' && (!isset(PROVIDERS[$cprov]) || $csym === '')) throw new RuntimeException('Enter the chart symbol or leave the chart source on "same as price".');
            if (q('SELECT 1 FROM assets WHERE symbol = ?', [$sym])->fetchColumn()) throw new RuntimeException('Symbol already exists.');
            $test = provider_test($prov, $srcSym);
            if (!$test['ok']) throw new RuntimeException($test['error'] . ' Check the source symbol.');
            $tvs = trim((string)($_POST['tv_symbol'] ?? ''));
            if ($tvs !== '' && !preg_match('/^[A-Za-z0-9_.!\-]{1,30}(:[A-Za-z0-9_.!\-]{1,30})?$/', $tvs)) throw new RuntimeException('TradingView symbol looks wrong (example: FX:EURGBP).');
            q('INSERT INTO assets (symbol, name, category, decimals, payout_percent, provider, source_symbol, chart_provider, chart_symbol, tv_symbol, sort_order)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$sym, mb_substr($name, 0, 60), clean_cat($_POST['category'] ?? 'forex'), $dec, $ovv, $prov, $srcSym,
                 $cprov === '' ? null : $cprov, $cprov === '' ? null : $csym, $tvs === '' ? null : $tvs,
                 1 + (int)q('SELECT COALESCE(MAX(sort_order), 0) FROM assets')->fetchColumn()]);
            admin_log('asset_add', "$sym ($prov:$srcSym)");
            flash('ok', "Market $sym added – live price " . $test['price'] . '.');
        } elseif ($action === 'delete') {
            $aid = (int)($_POST['id'] ?? 0);
            if (q('SELECT 1 FROM trades WHERE asset_id = ? LIMIT 1', [$aid])->fetchColumn()) {
                throw new RuntimeException('This market has trades. Deactivate it instead of deleting.');
            }
            q('DELETE FROM assets WHERE id = ?', [$aid]);
            admin_log('asset_delete', "#$aid");
            flash('ok', 'Market deleted.');
        }
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/assets.php' . ($action === 'keys' ? '#keys' : ''));
}

admin_start('Markets', 'assets.php');
$assets = q('SELECT a.*, (SELECT COUNT(*) FROM trades WHERE asset_id = a.id) tc,
                    (SELECT COUNT(*) FROM trades WHERE asset_id = a.id AND status = 'open') oc
             FROM assets a ORDER BY sort_order, id')->fetchAll();
$quotes = quotes_get();
$globalPayout = (float)setting('payout_percent', 85);
$cats = array_values(array_unique(array_column($assets, 'category')));
$active = count(array_filter($assets, fn($a) => $a['is_active']));
?>
<h1>Markets &amp; Winning Payout</h1>
<p class="muted">Users predict whether the live price will go <b>up or down</b>. If the prediction is right they win the payout you set here; if it is wrong they lose the stake; an exact tie returns the stake. Prices are <b>real market quotes</b> – you control only the payout %, never individual results. The payout is locked into each trade when it opens.</p>

<div class="grid g2">
  <div class="card">
    <h2>Winning payout (all markets)</h2>
    <form method="post" class="row" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="global_payout">
      <div class="field"><label>Payout % on a correct prediction</label><input type="number" name="payout_percent" value="<?= e($globalPayout) ?>" step="0.01" min="1" max="100" required inputmode="decimal"></div>
      <div class="field" style="flex:0"><button class="btn green" type="submit">Save</button></div>
    </form>
    <p class="muted" style="font-size:12px;margin:0">Example: stake 100 at <?= e(rtrim(rtrim(number_format($globalPayout, 2), '0'), '.')) ?>% → a win pays <b>+<?= e(rtrim(rtrim(number_format($globalPayout, 2), '0'), '.')) ?></b> (total back <?= e(rtrim(rtrim(number_format(100 + $globalPayout, 2), '0'), '.')) ?>). You can give a single market its own % in the table below.</p>
  </div>
  <div class="card">
    <h2>Turn markets on / off</h2>
    <p class="muted" style="margin-top:-6px"><b><?= $active ?></b> of <?= count($assets) ?> markets are active. Deactivated markets disappear from the terminal; trades already open still settle.</p>
    <form method="post" class="inline" data-confirm="Apply to every market in this group?"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_active">
      <select name="category" style="max-width:190px"><option value="all">All markets</option><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?= e(ucfirst($c)) ?> only</option><?php endforeach; ?></select>
      <button class="btn sm green" name="state" value="1" type="submit">Activate</button>
      <button class="btn sm red" name="state" value="0" type="submit">Deactivate</button>
    </form>
  </div>
</div>

<div class="card" id="keys">
  <h2>Chart &amp; price data API keys</h2>
  <p class="muted" style="margin-top:-6px">Binance and Yahoo Finance need no key. Paste a key to use a keyed provider for prices and/or charts (set it per market under <a href="#sources">Data sources</a>). Keys are stored encrypted and never shown again. Free plans have strict rate limits, so keyed quotes refresh every <?= (int)setting('quote_ttl_api', 10) ?>–<?= (int)setting('quote_ttl_api_bg', 30) ?>s (change in Settings → Live market data).</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="keys">
    <?php foreach (KEYED_PROVIDERS as $p): $has = api_key($p) !== ''; ?>
      <div class="row" style="align-items:flex-end;border-top:1px solid var(--line);padding-top:12px;margin-top:12px">
        <div class="field" style="flex:2"><label><?= e(PROVIDERS[$p]) ?> <?= $has ? '<span class="badge ok">key saved</span>' : '<span class="badge pending">no key</span>' ?> · <a href="<?= e(PROVIDER_KEYLINK[$p]) ?>" target="_blank" rel="noopener">get a key ↗</a></label>
          <div class="pwbox"><input type="password" name="key_<?= e($p) ?>" autocomplete="off" placeholder="<?= $has ? '•••••••• (leave blank to keep)' : 'Paste API key' ?>"><button type="button" class="pwtoggle" data-pwtoggle tabindex="-1">Show</button></div>
          <?php if ($has): ?><label class="check" style="margin-top:6px;font-size:12px"><input type="checkbox" name="clear_<?= e($p) ?>" value="1"> <span class="muted">Remove saved key</span></label><?php endif; ?></div>
        <div class="field"><label>Test symbol</label><input type="text" name="sample_<?= e($p) ?>" value="<?= e(PROVIDER_SAMPLE[$p]) ?>"></div>
        <div class="field" style="flex:0"><button class="btn ghost" name="do" value="test:<?= e($p) ?>" type="submit">Save &amp; test</button></div>
      </div>
    <?php endforeach; ?>
    <button class="btn green" name="do" value="save" type="submit" style="margin-top:14px">Save keys</button>
  </form>
</div>

<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="save">
  <h2>Markets</h2>
  <div class="tablewrap"><table class="rt">
    <tr><th>Symbol</th><th>Name</th><th>Type</th><th>Payout override %</th><th>Decimals</th><th>Order</th><th>Live price</th><th class="right">Trades</th><th></th></tr>
    <?php foreach ($assets as $a): $i = (int)$a['id']; $qd = $quotes[$i] ?? null; ?>
      <tr style="<?= $a['is_active'] ? '' : 'opacity:.6' ?>"><td><b><?= e($a['symbol']) ?></b><?= $a['is_active'] ? '' : '<div><span class="badge fail">deactivated</span></div>' ?></td>
        <td><input class="inp-md" type="text" name="name[<?= $i ?>]" value="<?= e($a['name']) ?>" maxlength="60"></td>
        <td><input class="inp-sm" type="text" name="cat[<?= $i ?>]" value="<?= e($a['category']) ?>" maxlength="20"></td>
        <td><input class="inp-sm" type="number" name="payout[<?= $i ?>]" value="<?= e($a['payout_percent'] === null ? '' : rtrim(rtrim($a['payout_percent'], '0'), '.')) ?>" placeholder="<?= e(rtrim(rtrim(number_format($globalPayout, 2), '0'), '.')) ?>" step="0.01" min="1" max="100" inputmode="decimal"></td>
        <td><input class="inp-xs" type="number" name="dec[<?= $i ?>]" value="<?= (int)$a['decimals'] ?>" min="0" max="8"></td>
        <td><input class="inp-xs" type="number" name="sort[<?= $i ?>]" value="<?= (int)$a['sort_order'] ?>"></td>
        <td class="mono"><?= $qd ? e(number_format($qd['price'], (int)$a['decimals'])) . ($qd['closed'] ? ' <span class="badge pending">closed</span>' : '') : ($a['provider'] ? '<span class="badge fail">no data</span>' : '<span class="badge fail">no source</span>') ?></td>
        <td class="right"><?= (int)$a['tc'] ?><?= (int)$a['oc'] ? ' <span class="badge pending">' . (int)$a['oc'] . ' open</span>' : '' ?></td>
        <td class="rt-block"><div class="inline" style="flex-wrap:nowrap">
          <button class="btn sm <?= $a['is_active'] ? 'ghost' : 'green' ?>" type="submit" form="tg<?= $i ?>"><?= $a['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          <?php if (!(int)$a['tc']): ?><button class="btn sm red" type="submit" form="del<?= $i ?>">Delete</button><?php endif; ?></div></td></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted" style="font-size:12px">Leave "Payout override" empty to use the global <?= e(rtrim(rtrim(number_format($globalPayout, 2), '0'), '.')) ?>%.</p>
  <button class="btn green" type="submit">Save changes</button>
</form>
<?php foreach ($assets as $a): $i = (int)$a['id']; ?>
  <form method="post" id="tg<?= $i ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $i ?>"></form>
  <?php if (!(int)$a['tc']): ?>
  <form method="post" id="del<?= $i ?>" data-confirm="Delete <?= e($a['symbol']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $i ?>"></form>
  <?php endif; ?>
<?php endforeach; ?>

<form method="post" class="card" id="sources"><?= csrf_field() ?><input type="hidden" name="action" value="sources">
  <h2>Data sources (price &amp; chart)</h2>
  <p class="muted" style="margin-top:-6px">Each market has a <b>price source</b> (used for trades and settlement), an optional separate <b>chart source</b> (candles on the standard chart) and a <b>TradingView symbol</b> (the pro chart users see by default; leave empty to show the standard chart for that market). Find TradingView symbols at tradingview.com (format EXCHANGE:SYMBOL, e.g. <span class="mono">FX:EURUSD</span>, <span class="mono">BINANCE:BTCUSDT</span>, <span class="mono">NASDAQ:AAPL</span>). Settlement always uses the price source, never the chart.</p>
  <div class="tablewrap"><table class="rt">
    <tr><th>Market</th><th>Price source</th><th>Price symbol</th><th>Chart source</th><th>Chart symbol</th><th>TradingView symbol</th><th></th></tr>
    <?php foreach ($assets as $a): $i = (int)$a['id']; ?>
      <tr><td><b><?= e($a['symbol']) ?></b></td>
        <td><select class="inp-md" name="prov[<?= $i ?>]"><?php foreach (PROVIDERS as $k => $l): ?><option value="<?= e($k) ?>" <?= $a['provider'] === $k ? 'selected' : '' ?>><?= e(explode(' (', $l)[0]) ?></option><?php endforeach; ?></select></td>
        <td><input class="inp-md" type="text" name="src[<?= $i ?>]" value="<?= e($a['source_symbol']) ?>" maxlength="40"></td>
        <td><select class="inp-md" name="cprov[<?= $i ?>]"><option value="">Same as price</option><?php foreach (PROVIDERS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($a['chart_provider'] ?? '') === $k ? 'selected' : '' ?>><?= e(explode(' (', $l)[0]) ?></option><?php endforeach; ?></select></td>
        <td><input class="inp-md" type="text" name="csrc[<?= $i ?>]" value="<?= e($a['chart_symbol'] ?? '') ?>" maxlength="40" placeholder="—"></td>
        <td><input class="inp-md" type="text" name="tv[<?= $i ?>]" value="<?= e($a['tv_symbol'] ?? '') ?>" maxlength="60" placeholder="BINANCE:BTCUSDT"></td>
        <td><button class="btn sm ghost" type="submit" form="ts<?= $i ?>">Test</button></td></tr>
    <?php endforeach; ?>
  </table></div>
  <button class="btn green" type="submit" style="margin-top:10px">Save data sources</button>
</form>
<?php foreach ($assets as $a): ?>
  <form method="post" id="ts<?= (int)$a['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="test_source"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"></form>
<?php endforeach; ?>

<div class="card">
  <h2>Add market</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="row">
      <div class="field"><label>Symbol (shown to users)</label><input type="text" name="symbol" placeholder="EURGBP" required maxlength="20"></div>
      <div class="field"><label>Name</label><input type="text" name="name" placeholder="EUR / GBP" required maxlength="60"></div>
      <div class="field"><label>Type</label><input type="text" name="category" value="forex"></div>
      <div class="field"><label>Decimals (0–8)</label><input type="number" name="decimals" value="4" min="0" max="8"></div>
    </div>
    <div class="row">
      <div class="field"><label>Price source</label>
        <select name="provider" id="aProv"><?php foreach (PROVIDERS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Price symbol</label><input type="text" name="source_symbol" id="aSrc" required maxlength="40"><div class="hint" id="aHint"></div></div>
      <div class="field"><label>Chart source (optional)</label>
        <select name="chart_provider"><option value="">Same as price</option><?php foreach (PROVIDERS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Chart symbol</label><input type="text" name="chart_symbol" maxlength="40" placeholder="only if chart source is set"></div>
      <div class="field"><label>TradingView symbol (optional)</label><input type="text" name="tv_symbol" maxlength="60" placeholder="FX:EURGBP"></div>
      <div class="field"><label>Payout override % (optional)</label><input type="number" name="payout_percent" step="0.01" min="1" max="100" placeholder="global <?= e(rtrim(rtrim(number_format($globalPayout, 2), '0'), '.')) ?>" inputmode="decimal"></div>
    </div>
    <button class="btn" type="submit">Test live price &amp; add</button>
  </form>
</div>
<script>
(function(){
  var hints = <?= json_encode(PROVIDER_HINTS) ?>, samples = <?= json_encode(PROVIDER_SAMPLE) ?>;
  var p = document.getElementById('aProv'), h = document.getElementById('aHint'), s = document.getElementById('aSrc');
  function u(){ h.textContent = hints[p.value] || ''; s.placeholder = samples[p.value] || ''; } p.onchange = u; u();
})();
</script>
<?php admin_end();
