<?php
/**
 * Live market data (no simulation):
 *   binance – crypto, public REST API, no key        https://api.binance.com
 *   yahoo   – forex, gold, stocks, indices, crypto   https://query1.finance.yahoo.com
 *
 * Quotes are cached in storage/cache so that many users polling every second cost only a few API calls.
 * Prices that belong to open trades are recorded in price_ticks so every trade settles on the real
 * price at its expiry second.
 */
const TIMEFRAMES = [60, 300, 900, 3600];
const PROVIDERS  = [
    'binance'      => 'Binance (crypto)',
    'yahoo'        => 'Yahoo Finance (forex, gold, stocks, crypto)',
    'twelvedata'   => 'Twelve Data (API key)',
    'finnhub'      => 'Finnhub (API key)',
    'alphavantage' => 'Alpha Vantage (API key)',
];
/** Providers that need an API key (set in Admin > Markets). */
const KEYED_PROVIDERS = ['twelvedata', 'finnhub', 'alphavantage'];
const PROVIDER_HINTS = [
    'binance'      => 'Binance pair, e.g. BTCUSDT, ETHUSDT, DOGEUSDT',
    'yahoo'        => 'Yahoo symbol, e.g. EURGBP=X (forex), AAPL (stock), GC=F (gold), ^NSEI (Nifty), BTC-USD',
    'twelvedata'   => 'Twelve Data symbol, e.g. EUR/USD, XAU/USD, AAPL, BTC/USD',
    'finnhub'      => 'Finnhub symbol, e.g. AAPL, OANDA:EUR_USD, BINANCE:BTCUSDT',
    'alphavantage' => 'Prefix with the type: FX:EUR/USD, CRYPTO:BTC/USD or STOCK:AAPL',
];
const PROVIDER_SAMPLE = ['binance' => 'BTCUSDT', 'yahoo' => 'EURUSD=X', 'twelvedata' => 'EUR/USD', 'finnhub' => 'AAPL', 'alphavantage' => 'FX:EUR/USD'];
const PROVIDER_KEYLINK = [
    'twelvedata'   => 'https://twelvedata.com/account/api-keys',
    'finnhub'      => 'https://finnhub.io/dashboard',
    'alphavantage' => 'https://www.alphavantage.co/support/#api-key',
];

function api_key(string $provider): string
{
    return secret_decrypt((string)setting('key_' . $provider, ''));
}

/** Last error text produced by a keyed provider (shown when an admin tests a key). */
function market_error(?string $set = null): string
{
    static $e = '';
    if ($set !== null) $e = $set;
    return $e;
}

// ---------------------------------------------------------------- http
const HTTP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

/** Fetch several URLs in parallel. Returns key => ['code' => int, 'body' => string]. */
function http_multi(array $urls, int $timeout = 6): array
{
    $out = [];
    if (!$urls) return $out;
    if (!function_exists('curl_init')) {
        foreach ($urls as $k => $u) {
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: " . HTTP_UA . "\r\n", 'ignore_errors' => true]]);
            $b = @file_get_contents($u, false, $ctx);
            $code = 0;
            foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
            $out[$k] = ['code' => $b === false ? 0 : $code, 'body' => (string)$b];
        }
        return $out;
    }
    $mh = curl_multi_init();
    $hs = [];
    foreach ($urls as $k => $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => HTTP_UA, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $hs[$k] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.5);
    } while ($running && $st === CURLM_OK);
    foreach ($hs as $k => $ch) {
        $out[$k] = ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string)curl_multi_getcontent($ch)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function http_json(string $url, int $timeout = 6)
{
    $r = http_multi(['x' => $url], $timeout)['x'];
    if ($r['code'] !== 200) return null;
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : null;
}

function binance_json(string $pathQuery, int $timeout = 6)
{
    foreach (['https://api.binance.com', 'https://data-api.binance.vision'] as $base) {
        $j = http_json($base . $pathQuery, $timeout);
        if ($j !== null) return $j;
    }
    return null;
}

// ---------------------------------------------------------------- tiny file cache
function cache_file(string $name): string
{
    $d = STORAGE_DIR . '/cache';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
}

function cache_read(string $name): array
{
    $f = cache_file($name);
    if (!is_file($f)) return [];
    $fh = @fopen($f, 'r');
    if (!$fh) return [];
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : [];
}

function cache_write(string $name, array $data): void
{
    @file_put_contents(cache_file($name), json_encode($data), LOCK_EX);
}

// ---------------------------------------------------------------- quote fetchers
/** @return array<int,array{p:float,ts:int}> keyed by asset id */
function fetch_binance_quotes(array $assets): array
{
    $syms = [];
    foreach ($assets as $a) $syms[$a['source_symbol']][] = (int)$a['id'];
    if (!$syms) return [];
    $j = binance_json('/api/v3/ticker/price?symbols=' . rawurlencode(json_encode(array_keys($syms))), 8);
    $out = [];
    foreach ((array)$j as $row) {
        if (isset($row['symbol'], $row['price'], $syms[$row['symbol']]) && (float)$row['price'] > 0) {
            foreach ($syms[$row['symbol']] as $id) $out[$id] = ['p' => (float)$row['price'], 'ts' => time()];
        }
    }
    return $out;
}

function fetch_yahoo_quotes(array $assets): array
{
    // The "meta" price Yahoo returns is rounded to 4 decimals and moves rarely, so 30-second trades kept ending in a tie.
    // The latest 1-minute candle carries the real, full-precision price - use that, and fall back to meta.
    $now = time();
    $urls = [];
    foreach ($assets as $a) {
        $urls[(int)$a['id']] = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($a['source_symbol'])
            . '?interval=1m&period1=' . ($now - 300) . '&period2=' . ($now + 120);
    }
    $out = [];
    foreach (http_multi($urls, 8) as $id => $r) {
        if ($r['code'] !== 200) continue;
        $res = json_decode($r['body'], true)['chart']['result'][0] ?? null;
        $m = $res['meta'] ?? null;
        if (!$m) continue;
        $price = (float)($m['regularMarketPrice'] ?? 0);
        $ts = (int)($m['regularMarketTime'] ?? $now);
        $closes = $res['indicators']['quote'][0]['close'] ?? [];
        foreach (array_reverse(array_keys($res['timestamp'] ?? [])) as $i) {
            if (isset($closes[$i]) && (float)$closes[$i] > 0) {
                $cts = (int)$res['timestamp'][$i];
                if ($cts >= $ts - 60) { $price = (float)$closes[$i]; $ts = max($ts, $cts); }
                break;
            }
        }
        if ($price > 0) $out[$id] = ['p' => round($price, 6), 'ts' => $ts];
    }
    return $out;
}

function keyed_error(string $provider, $json, int $code): string
{
    if (is_array($json)) {
        foreach (['message', 'Error Message', 'Note', 'Information', 'error'] as $k) {
            if (!empty($json[$k]) && is_string($json[$k])) return substr($json[$k], 0, 200);
        }
    }
    return $code === 0 ? "Could not reach $provider." : "$provider answered HTTP $code.";
}

function fetch_twelvedata_quotes(array $assets): array
{
    $key = api_key('twelvedata');
    if ($key === '') { market_error('Twelve Data API key is not set.'); return []; }
    $urls = [];
    foreach ($assets as $a) $urls[(int)$a['id']] = 'https://api.twelvedata.com/quote?symbol=' . rawurlencode($a['source_symbol']) . '&apikey=' . rawurlencode($key);
    $out = [];
    foreach (http_multi($urls, 8) as $id => $r) {
        $j = json_decode($r['body'], true);
        if (!is_array($j) || ($j['status'] ?? '') === 'error' || !isset($j['close'])) { market_error(keyed_error('Twelve Data', $j, $r['code'])); continue; }
        if ((float)$j['close'] > 0) $out[$id] = ['p' => (float)$j['close'], 'ts' => (int)($j['timestamp'] ?? time())];
    }
    return $out;
}

function fetch_finnhub_quotes(array $assets): array
{
    $key = api_key('finnhub');
    if ($key === '') { market_error('Finnhub API key is not set.'); return []; }
    $urls = [];
    foreach ($assets as $a) $urls[(int)$a['id']] = 'https://finnhub.io/api/v1/quote?symbol=' . rawurlencode($a['source_symbol']) . '&token=' . rawurlencode($key);
    $out = [];
    foreach (http_multi($urls, 8) as $id => $r) {
        $j = json_decode($r['body'], true);
        if (!is_array($j) || empty($j['c']) || (float)$j['c'] <= 0) { market_error(keyed_error('Finnhub', $j, $r['code'])); continue; }
        $out[$id] = ['p' => (float)$j['c'], 'ts' => (int)($j['t'] ?? 0) ?: time()];
    }
    return $out;
}

/** Alpha Vantage symbol formats: FX:EUR/USD, CRYPTO:BTC/USD, STOCK:AAPL */
function av_parse(string $sym): array
{
    if (preg_match('#^(FX|CRYPTO):([A-Z]{3,6})/([A-Z]{3,6})$#i', $sym, $m)) return [strtoupper($m[1]), strtoupper($m[2]), strtoupper($m[3])];
    return ['STOCK', preg_replace('/^STOCK:/i', '', $sym), ''];
}

function fetch_alphavantage_quotes(array $assets): array
{
    $key = api_key('alphavantage');
    if ($key === '') { market_error('Alpha Vantage API key is not set.'); return []; }
    $urls = [];
    foreach ($assets as $a) {
        [$kind, $x, $y] = av_parse($a['source_symbol']);
        $urls[(int)$a['id']] = $kind === 'STOCK'
            ? 'https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=' . rawurlencode($x) . '&apikey=' . rawurlencode($key)
            : 'https://www.alphavantage.co/query?function=CURRENCY_EXCHANGE_RATE&from_currency=' . $x . '&to_currency=' . $y . '&apikey=' . rawurlencode($key);
    }
    $out = [];
    foreach (http_multi($urls, 8) as $id => $r) {
        $j = json_decode($r['body'], true);
        if (isset($j['Realtime Currency Exchange Rate'])) {
            $q = $j['Realtime Currency Exchange Rate'];
            $ts = strtotime(($q['6. Last Refreshed'] ?? '') . ' UTC') ?: time();
            if ((float)($q['5. Exchange Rate'] ?? 0) > 0) $out[$id] = ['p' => (float)$q['5. Exchange Rate'], 'ts' => $ts];
        } elseif (!empty($j['Global Quote']['05. price'])) {
            $day = $j['Global Quote']['07. latest trading day'] ?? '';
            // Alpha Vantage gives only the trading day for stocks: a past day means the market is closed
            $ts = ($day === gmdate('Y-m-d')) ? time() : (strtotime($day . ' 21:00:00 UTC') ?: time());
            $out[$id] = ['p' => (float)$j['Global Quote']['05. price'], 'ts' => $ts];
        } else {
            market_error(keyed_error('Alpha Vantage', $j, $r['code']));
        }
    }
    return $out;
}

/** Fetch live quotes for assets grouped by provider. */
function fetch_quotes(array $assets): array
{
    $by = [];
    foreach ($assets as $a) $by[$a['provider']][] = $a;
    $out = [];
    if (!empty($by['binance'])) $out += fetch_binance_quotes($by['binance']);
    if (!empty($by['yahoo']))   $out += fetch_yahoo_quotes($by['yahoo']);
    if (!empty($by['twelvedata']))   $out += fetch_twelvedata_quotes($by['twelvedata']);
    if (!empty($by['finnhub']))      $out += fetch_finnhub_quotes($by['finnhub']);
    if (!empty($by['alphavantage'])) $out += fetch_alphavantage_quotes($by['alphavantage']);
    return $out;
}

// ---------------------------------------------------------------- cached quotes
function quote_assets(): array
{
    return q("SELECT id, symbol, provider, source_symbol, decimals FROM assets
              WHERE provider IS NOT NULL AND source_symbol IS NOT NULL
                AND (is_active = 1 OR id IN (SELECT DISTINCT asset_id FROM trades WHERE status = 'open'))")->fetchAll();
}

/**
 * Current quotes for every tradable asset: id => ['price','ts','age','closed'].
 * $focus  = asset ids that need a fresh price (the asset on screen, open trades, a trade being placed).
 * $wait   = block until a refresh finishes (used when placing / settling trades).
 */
function quotes_get(array $focus = [], bool $wait = false): array
{
    $now = time();
    $assets = quote_assets();
    $cache = cache_read('quotes.json');
    $focus = array_flip(array_map('intval', $focus));

    $open = array_flip(array_map('intval', q("SELECT DISTINCT asset_id FROM trades WHERE status = 'open'")->fetchAll(PDO::FETCH_COLUMN)));
    $ttlFocus = max(1, (int)setting('quote_ttl_focus', 2));
    $ttlBg    = max(2, (int)setting('quote_ttl_bg', 10));
    $ttlApi   = max(3, (int)setting('quote_ttl_api', 10));
    $ttlApiBg = max(5, (int)setting('quote_ttl_api_bg', 30));

    $stale = function (array $cache) use ($assets, $focus, $open, $ttlFocus, $ttlBg, $ttlApi, $ttlApiBg): array {
        $now = time();
        $need = [];
        foreach ($assets as $a) {
            $id = (int)$a['id'];
            $hot = isset($focus[$id]) || isset($open[$id]);
            if ($a['provider'] === 'binance')                        $ttl = 1;
            elseif (in_array($a['provider'], KEYED_PROVIDERS, true)) $ttl = $hot ? $ttlApi : $ttlApiBg;
            else                                                     $ttl = $hot ? $ttlFocus : $ttlBg;
            if (!isset($cache[$id]) || $now - (int)$cache[$id]['f'] >= $ttl) $need[] = $a;
        }
        return $need;
    };

    if ($stale($cache)) {
        $lock = @fopen(cache_file('quotes.lock'), 'c');
        if ($lock && flock($lock, $wait ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
            $cache = cache_read('quotes.json');              // someone may have refreshed while we waited
            $need = $stale($cache);
            if ($need) {
                $got = fetch_quotes($need);
                $now = time();
                foreach ($got as $id => $qd) $cache[$id] = ['p' => $qd['p'], 'ts' => $qd['ts'], 'f' => $now];
                // remember when we tried even if the API failed, so a broken feed is not hammered every second
                foreach ($need as $a) {
                    $id = (int)$a['id'];
                    if (isset($got[$id])) continue;
                    if (isset($cache[$id])) $cache[$id]['f'] = $now - max(0, $ttlFocus - 1);   // keep the last price, retry soon
                    else $cache[$id] = ['p' => 0, 'ts' => 0, 'f' => $now];                    // no price yet: don't hammer the API
                }
                cache_write('quotes.json', $cache);
                record_ticks($got, $open, $now);
            }
            flock($lock, LOCK_UN);
        }
        if ($lock) fclose($lock);
    }

    $stale_secs = max(30, (int)setting('stale_secs', 900));
    $out = [];
    $now = time();
    foreach ($assets as $a) {
        $id = (int)$a['id'];
        if (!isset($cache[$id]) || (float)$cache[$id]['p'] <= 0) continue;
        $age = max(0, $now - (int)$cache[$id]['ts']);
        $out[$id] = [
            'price'  => (float)$cache[$id]['p'],
            'ts'     => (int)$cache[$id]['ts'],
            'age'    => $age,
            'closed' => $a['provider'] !== 'binance' && $age > $stale_secs,
        ];
    }
    return $out;
}

/** Store the price for assets that have open trades, so settlement uses the real price at expiry. */
function record_ticks(array $got, array $openAssets, int $now): void
{
    foreach ($got as $id => $qd) {
        if (!isset($openAssets[$id])) continue;
        q('INSERT IGNORE INTO price_ticks (asset_id, ts, price, qts) VALUES (?,?,?,?)', [$id, $now, number_format($qd['p'], 6, '.', ''), $qd['ts']]);
    }
    if (random_int(1, 40) === 1) {
        q('DELETE FROM price_ticks WHERE ts < ?', [$now - 7200]);
    }
}

/** One asset's quote, optionally forcing a fresh fetch. */
function asset_quote(array $asset, bool $fresh = false): ?array
{
    $all = quotes_get($fresh ? [(int)$asset['id']] : [], $fresh);
    return $all[(int)$asset['id']] ?? null;
}

/** Test a provider + symbol pair (used when an admin adds an asset). */
function provider_test(string $provider, string $symbol): array
{
    if (!isset(PROVIDERS[$provider])) return ['ok' => false, 'error' => 'Unknown provider.'];
    market_error('');
    $got = fetch_quotes([['id' => 1, 'provider' => $provider, 'source_symbol' => $symbol]]);
    if (empty($got[1])) return ['ok' => false, 'error' => market_error() ?: "The $provider API returned no price for \"$symbol\"."];
    return ['ok' => true, 'price' => $got[1]['p'], 'ts' => $got[1]['ts']];
}

/** Live USD/INR (≈ USDT/INR) with a 60 second cache. */
function live_usdinr(): ?float
{
    $c = cache_read('usdinr.json');
    if (!empty($c['p']) && time() - (int)$c['f'] < 60) return (float)$c['p'];
    $got = fetch_yahoo_quotes([['id' => 1, 'source_symbol' => 'USDINR=X']]);
    if (!empty($got[1])) {
        cache_write('usdinr.json', ['p' => $got[1]['p'], 'f' => time()]);
        return $got[1]['p'];
    }
    return !empty($c['p']) ? (float)$c['p'] : null;
}

// ---------------------------------------------------------------- candles
function binance_candles(string $sym, int $tf, int $count): array
{
    $iv = [60 => '1m', 300 => '5m', 900 => '15m', 3600 => '1h'][$tf] ?? '1m';
    $j = binance_json('/api/v3/klines?symbol=' . rawurlencode($sym) . '&interval=' . $iv . '&limit=' . $count, 6);
    $out = [];
    foreach ((array)$j as $k) {
        $out[] = ['time' => intdiv((int)$k[0], 1000), 'open' => (float)$k[1], 'high' => (float)$k[2], 'low' => (float)$k[3], 'close' => (float)$k[4]];
    }
    return $out;
}

function yahoo_candles(string $sym, int $tf, int $count): array
{
    [$iv, $range] = [60 => ['1m', '5d'], 300 => ['5m', '5d'], 900 => ['15m', '5d'], 3600 => ['60m', '1mo']][$tf] ?? ['1m', '5d'];
    $j = http_json('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($sym) . "?interval=$iv&range=$range", 8);
    $r = $j['chart']['result'][0] ?? null;
    if (!$r) return [];
    $ts = $r['timestamp'] ?? [];
    $qd = $r['indicators']['quote'][0] ?? [];
    $out = [];
    foreach ($ts as $i => $t) {
        $o = $qd['open'][$i] ?? null; $h = $qd['high'][$i] ?? null; $l = $qd['low'][$i] ?? null; $c = $qd['close'][$i] ?? null;
        if ($o === null || $h === null || $l === null || $c === null) continue;
        $out[] = ['time' => (int)$t, 'open' => round((float)$o, 8), 'high' => round((float)$h, 8), 'low' => round((float)$l, 8), 'close' => round((float)$c, 8)];
    }
    return array_slice($out, -$count);
}

function candles_from(string $provider, string $sym, int $tf, int $count): array
{
    switch ($provider) {
        case 'binance':      return binance_candles($sym, $tf, $count);
        case 'yahoo':        return yahoo_candles($sym, $tf, $count);
        case 'twelvedata':   return twelvedata_candles($sym, $tf, $count);
        case 'finnhub':      return finnhub_candles($sym, $tf, $count);
        case 'alphavantage': return alphavantage_candles($sym, $tf, $count);
    }
    return [];
}

function twelvedata_candles(string $sym, int $tf, int $count): array
{
    $key = api_key('twelvedata');
    if ($key === '') { market_error('Twelve Data API key is not set.'); return []; }
    $iv = [60 => '1min', 300 => '5min', 900 => '15min', 3600 => '1h'][$tf] ?? '1min';
    $r = http_multi(['x' => 'https://api.twelvedata.com/time_series?symbol=' . rawurlencode($sym) . "&interval=$iv&outputsize=$count&timezone=UTC&order=ASC&apikey=" . rawurlencode($key)], 10)['x'];
    $j = json_decode($r['body'], true);
    if (!is_array($j) || ($j['status'] ?? '') === 'error' || empty($j['values'])) { market_error(keyed_error('Twelve Data', $j, $r['code'])); return []; }
    $out = [];
    foreach ($j['values'] as $v) {
        $t = strtotime($v['datetime'] . ' UTC');
        if ($t) $out[] = ['time' => $t, 'open' => (float)$v['open'], 'high' => (float)$v['high'], 'low' => (float)$v['low'], 'close' => (float)$v['close']];
    }
    return $out;
}

function finnhub_candles(string $sym, int $tf, int $count): array
{
    $key = api_key('finnhub');
    if ($key === '') { market_error('Finnhub API key is not set.'); return []; }
    $res = [60 => '1', 300 => '5', 900 => '15', 3600 => '60'][$tf] ?? '1';
    $kind = preg_match('/^(OANDA|FXCM|FX_IDC|PEPPERSTONE|FOREXCOM)/i', $sym) ? 'forex' : (preg_match('/^(BINANCE|COINBASE|KRAKEN|BITFINEX|BITSTAMP|GEMINI|HUOBI)/i', $sym) ? 'crypto' : 'stock');
    $now = time();
    $from = $now - max($count * $tf * 4, 86400 * 4);
    $r = http_multi(['x' => "https://finnhub.io/api/v1/$kind/candle?symbol=" . rawurlencode($sym) . "&resolution=$res&from=$from&to=$now&token=" . rawurlencode($key)], 10)['x'];
    $j = json_decode($r['body'], true);
    if (!is_array($j) || ($j['s'] ?? '') !== 'ok' || empty($j['t'])) {
        market_error(keyed_error('Finnhub', $j, $r['code']) . ' (some Finnhub plans do not include candles)');
        return [];
    }
    $out = [];
    foreach ($j['t'] as $i => $t) $out[] = ['time' => (int)$t, 'open' => (float)$j['o'][$i], 'high' => (float)$j['h'][$i], 'low' => (float)$j['l'][$i], 'close' => (float)$j['c'][$i]];
    return array_slice($out, -$count);
}

function alphavantage_candles(string $sym, int $tf, int $count): array
{
    $key = api_key('alphavantage');
    if ($key === '') { market_error('Alpha Vantage API key is not set.'); return []; }
    $iv = [60 => '1min', 300 => '5min', 900 => '15min', 3600 => '60min'][$tf] ?? '1min';
    [$kind, $x, $y] = av_parse($sym);
    $k = rawurlencode($key);
    $url = $kind === 'FX' ? "https://www.alphavantage.co/query?function=FX_INTRADAY&from_symbol=$x&to_symbol=$y&interval=$iv&outputsize=compact&apikey=$k"
        : ($kind === 'CRYPTO' ? "https://www.alphavantage.co/query?function=CRYPTO_INTRADAY&symbol=$x&market=$y&interval=$iv&outputsize=compact&apikey=$k"
        : 'https://www.alphavantage.co/query?function=TIME_SERIES_INTRADAY&symbol=' . rawurlencode($x) . "&interval=$iv&outputsize=compact&apikey=$k");
    $r = http_multi(['x' => $url], 12)['x'];
    $j = json_decode($r['body'], true);
    $series = null;
    foreach ((array)$j as $name => $v) if (str_starts_with((string)$name, 'Time Series') && is_array($v)) $series = $v;
    if (!$series) { market_error(keyed_error('Alpha Vantage', $j, $r['code']) . ' (intraday data may need a premium plan)'); return []; }
    $out = [];
    foreach ($series as $dt => $v) {
        $t = strtotime($dt . ' UTC');
        if ($t) $out[] = ['time' => $t, 'open' => (float)$v['1. open'], 'high' => (float)$v['2. high'], 'low' => (float)$v['3. low'], 'close' => (float)$v['4. close']];
    }
    usort($out, fn($a, $b) => $a['time'] <=> $b['time']);
    return array_slice($out, -$count);
}

/** Test chart data for a provider + symbol. */
function provider_chart_test(string $provider, string $symbol): array
{
    market_error('');
    $c = candles_from($provider, $symbol, 60, 30);
    return $c ? ['ok' => true, 'bars' => count($c)] : ['ok' => false, 'error' => market_error() ?: "No chart data returned for \"$symbol\"."];
}

function candles(array $asset, int $tf, int $count = 150): array
{
    $name = 'candles_' . (int)$asset['id'] . '_' . $tf . '.json';
    $c = cache_read($name);
    $ttl = $tf >= 900 ? 20 : 6;
    if (!empty($c['data']) && time() - (int)$c['f'] < $ttl) return $c['data'];

    // chart data source: the market's own chart source if set, otherwise its price source
    $cp = $asset['chart_provider'] ?? null;
    $data = $cp
        ? candles_from($cp, (string)$asset['chart_symbol'], $tf, $count)
        : candles_from($asset['provider'], $asset['source_symbol'], $tf, $count);
    if (!$data && $cp) $data = candles_from($asset['provider'], $asset['source_symbol'], $tf, $count);   // fall back to the price source
    if (!$data) return $c['data'] ?? [];
    $seen = [];
    $data = array_values(array_filter($data, function ($x) use (&$seen) { if (isset($seen[$x['time']])) return false; return $seen[$x['time']] = true; }));
    usort($data, fn($a, $b) => $a['time'] <=> $b['time']);
    cache_write($name, ['f' => time(), 'data' => $data]);
    return $data;
}

/** Exact Binance price at one second (open of the 1-second candle). Null if not available. */
function binance_second_price(string $sym, int $ts): ?float
{
    $j = binance_json('/api/v3/klines?symbol=' . rawurlencode($sym) . '&interval=1s&startTime=' . ($ts * 1000) . '&limit=1', 8);
    return (!empty($j[0]) && intdiv((int)$j[0][0], 1000) === $ts) ? (float)$j[0][1] : null;
}

/** Price at a past second (only used when no live tick was recorded, e.g. nobody was online at expiry). */
function history_price(array $asset, int $ts): ?float
{
    if ($asset['provider'] === 'binance') {
        $p = binance_second_price($asset['source_symbol'], $ts);
        if ($p !== null) return $p;
        $j = binance_json('/api/v3/klines?symbol=' . rawurlencode($asset['source_symbol']) . '&interval=1m&startTime=' . (intdiv($ts, 60) * 60000) . '&limit=1', 6);
        return !empty($j[0]) ? (float)$j[0][4] : null;
    }
    if ($asset['provider'] !== 'yahoo') {                     // keyed providers: nearest 1-minute candle at or before the second
        $best = null;
        foreach (candles_from($asset['provider'], $asset['source_symbol'], 60, 120) as $k) if ($k['time'] <= $ts + 30) $best = $k['close'];
        return $best;
    }
    $j = http_json('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($asset['source_symbol']) . '?interval=1m&period1=' . ($ts - 300) . '&period2=' . ($ts + 180), 8);
    $r = $j['chart']['result'][0] ?? null;
    if (!$r) return null;
    $best = null;
    foreach ($r['timestamp'] ?? [] as $i => $t) {
        $c = $r['indicators']['quote'][0]['close'][$i] ?? null;
        if ($c !== null && $t <= $ts + 30) $best = (float)$c;
    }
    return $best;
}
