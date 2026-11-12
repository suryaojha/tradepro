<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bigbang.php';

$u = current_user();
$siteName = (string)setting('site_name', 'TradePro');
$bbGames = [];
$bbProviders = [];
$bbError = '';
if (bigbang_key() !== '') {
    try {
        $catalog = bigbang_request('GET', '/games?type=standard&limit=12&offset=0');
        $rows = $catalog['data'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $g) {
                if (!is_array($g) || (int)($g['id'] ?? 0) < 1 || ($g['tier'] ?? 'standard') !== 'standard' || ($g['mode'] ?? 'standard') === 'premium' || !empty($g['is_premium'])) continue;
                $bbGames[] = $g;
                $provider = trim((string)($g['provider'] ?? ''));
                if ($provider !== '' && !in_array($provider, $bbProviders, true)) $bbProviders[] = $provider;
            }
        }
    } catch (Throwable $ex) {
        error_log('Homepage BigBang catalog: ' . $ex->getMessage());
        $bbError = 'Demo catalogue is temporarily unavailable.';
    }
}
page_head('Home', 'matchpari-home');
?>
<style>
.matchpari-home{background:#eef2f6;color:#101828}.matchpari-home .container{max-width:1320px}
.mp-top{background:#875000;color:#fff;padding:12px max(14px,calc((100vw - 1320px)/2));display:flex;align-items:center;gap:18px;justify-content:space-between;flex-wrap:wrap}
.mp-brand{display:flex;align-items:center;gap:10px;color:#ffdf38;font-weight:1000;font-size:clamp(20px,3vw,30px);text-decoration:none;text-shadow:1px 2px #164b66}
.mp-brand img{width:34px;height:34px;object-fit:contain}.mp-account{display:flex;gap:14px;align-items:center;color:white;font-weight:700}.mp-account a{color:white}
.mp-nav{background:#754300;color:#fff;display:flex;overflow-x:auto;white-space:nowrap;padding:0 max(10px,calc((100vw - 1320px)/2));border-top:1px solid #a96b17}
.mp-nav a{color:#fff;text-decoration:none;font-weight:900;padding:15px 18px;border-right:1px solid #c28a38}.mp-nav a.active{background:#fff3c4;color:#7a3500}
.mp-cats{display:grid;grid-template-columns:repeat(6,minmax(100px,1fr));background:#08645b;color:white;gap:1px}
.mp-cats a{text-align:center;color:white;text-decoration:none;font-weight:900;padding:12px 4px;background:#087266}.mp-cats b{display:block;font-size:27px;margin-bottom:4px}
.mp-shell{max-width:1320px;margin:0 auto;padding:12px}.mp-strip{display:flex;gap:8px;overflow:auto;padding:6px 0 12px}.mp-pill{border:1px solid #a16b19;border-radius:24px;padding:8px 16px;color:#865000;background:#fff;white-space:nowrap;font-weight:700}
.mp-section{margin:10px 0 16px}.mp-heading{background:#08645b;color:white;padding:11px 14px;font-size:23px;font-weight:1000;text-transform:uppercase;margin:0 0 7px}
.mp-sportsnote{background:white;border:1px solid #d8e0e8;padding:16px;color:#475467}
.mp-gamegrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:3px;background:#fff}
.mp-game{position:relative;min-width:0;background:#15233a;color:white;overflow:hidden;aspect-ratio:1.42;text-decoration:none}
.mp-game img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s}.mp-game:hover img{transform:scale(1.04)}
.mp-game .mp-game-label{position:absolute;inset:auto 0 0;padding:9px 7px;background:linear-gradient(transparent,rgba(0,0,0,.88));font-weight:900;text-align:center;text-transform:uppercase;font-size:clamp(11px,1.3vw,15px)}
.mp-game.noimg{display:flex;align-items:center;justify-content:center;text-align:center;padding:12px;background:linear-gradient(135deg,#142c55,#5d236f)}
.mp-providergrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:3px}
.mp-provider{min-height:110px;display:flex;align-items:center;justify-content:center;text-align:center;padding:12px;color:white;font-weight:1000;font-size:clamp(15px,2vw,24px);text-transform:uppercase;background:linear-gradient(135deg,#112a56,#702f68)}
.mp-provider:nth-child(3n+2){background:linear-gradient(135deg,#0d314b,#116a6c)}.mp-provider:nth-child(3n){background:linear-gradient(135deg,#3b174d,#b05b19)}
.mp-foot{background:#fff;padding:28px 12px;text-align:center;color:#475467;margin-top:24px}
@media(max-width:680px){.mp-shell{padding:8px}.mp-cats{grid-template-columns:repeat(3,minmax(0,1fr))}.mp-gamegrid{grid-template-columns:repeat(2,minmax(0,1fr))}.mp-providergrid{grid-template-columns:repeat(2,minmax(0,1fr))}.mp-heading{font-size:20px}.mp-game{aspect-ratio:1.35}.mp-top{gap:8px}}
</style>
<header class="mp-top">
  <a class="mp-brand" href="<?= e(url('')) ?>"><span>🎮</span><?= e($siteName) ?></a>
  <div class="mp-account">
    <?php if ($u): $mpWallets = wallets_of((int)$u['id']); ?>
      <span><?= e(money($mpWallets[$u['active_account']], $u['active_account'])) ?></span>
      <a href="<?= e(url('profile.php')) ?>"><?= e($u['name'] ?: $u['email']) ?> ▾</a>
    <?php else: ?>
      <a href="<?= e(url('login.php')) ?>">Log in</a><a href="<?= e(url('register.php')) ?>">Register</a>
    <?php endif; ?>
  </div>
</header>
<nav class="mp-nav" aria-label="Main navigation">
  <a class="active" href="#inplay">INPLAY</a><a href="#sports">SPORTS</a><a href="#casino">CASINO</a><a href="#providers">PROVIDERS</a><a href="#new-launch">NEW LAUNCH</a><a href="<?= e(url('games.php')) ?>">PLAY</a>
</nav>
<div class="mp-cats">
  <a href="#sports"><b>🏏</b>CRICKET</a><a href="#sports"><b>⚽</b>FOOTBALL</a><a href="#sports"><b>🎾</b>TENNIS</a><a href="#sports"><b>🏆</b>FANTASY</a><a href="#sports"><b>🏁</b>MORE SPORTS</a><a href="#casino"><b>🎮</b>CASINO</a>
</div>
<main class="mp-shell">
  <div class="mp-strip"><span class="mp-pill">• LIVE SCORES</span><span class="mp-pill">UPCOMING FIXTURES</span><span class="mp-pill">SANDBOX GAMES</span><span style="margin-left:auto;font-weight:800;color:#344054;align-self:center">DEMO EXPERIENCE</span></div>
  <section class="mp-section" id="inplay">
    <h2 class="mp-heading">IN PLAY · SPORTS CENTRE</h2>
    <div class="mp-sportsnote"><b>Sports fixtures and scores</b><p style="margin:6px 0 0">Live sports data is not connected yet. This area will show verified team names, schedules and scores when an authorised sports-data feed is configured. No betting odds or bet slips are displayed.</p></div>
  </section>
  <section class="mp-section" id="sports">
    <h2 class="mp-heading">SPORTS</h2>
    <div class="mp-cats" style="grid-template-columns:repeat(6,minmax(0,1fr));border:2px solid white">
      <a href="#sports"><b>🏏</b>Cricket</a><a href="#sports"><b>⚽</b>Football</a><a href="#sports"><b>🎾</b>Tennis</a><a href="#sports"><b>🏆</b>Fantasy</a><a href="#sports"><b>🏁</b>Motorsport</a><a href="#sports"><b>🏀</b>Other</a>
    </div>
  </section>
  <section class="mp-section" id="casino">
    <h2 class="mp-heading">CASINO · FREE SANDBOX</h2>
    <?php if ($bbError): ?><div class="mp-sportsnote"><?= e($bbError) ?></div><?php elseif (!$bbGames): ?><div class="mp-sportsnote">BigBang Standard sandbox catalogue is not configured yet. Admin can connect the sandbox key in Admin → BigBang Sandbox.</div><?php else: ?>
      <div class="mp-gamegrid">
        <?php foreach (array_slice($bbGames, 0, 8) as $g): $thumb=(string)($g['thumbnail'] ?? ''); $safeThumb=filter_var($thumb,FILTER_VALIDATE_URL) && strtolower((string)parse_url($thumb,PHP_URL_SCHEME))==='https' && parse_url($thumb,PHP_URL_HOST); ?>
          <a class="mp-game <?= $safeThumb ? '' : 'noimg' ?>" href="<?= e($u ? url('bigbang.php') : url('login.php')) ?>">
            <?php if ($safeThumb): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy"><?php endif; ?>
            <span class="mp-game-label"><?= e($g['title'] ?? $g['name'] ?? 'Sandbox game') ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <p style="text-align:right;margin:7px 0"><a href="<?= e($u ? url('bigbang.php') : url('login.php')) ?>" style="color:#075e58;font-weight:900">View all Standard demo games →</a></p>
    <?php endif; ?>
  </section>
  <section class="mp-section" id="providers">
    <h2 class="mp-heading">OUR PROVIDERS</h2>
    <?php if ($bbProviders): ?><div class="mp-providergrid"><?php foreach (array_slice($bbProviders,0,12) as $provider): ?><div class="mp-provider"><?= e($provider) ?></div><?php endforeach; ?></div><?php else: ?><div class="mp-sportsnote">Provider names will appear here when the BigBang Standard sandbox catalogue is available.</div><?php endif; ?>
  </section>
  <section class="mp-section" id="new-launch">
    <h2 class="mp-heading">NEW LAUNCH</h2>
    <?php if ($bbGames): ?><div class="mp-gamegrid"><?php foreach (array_slice($bbGames,8,4) as $g): $thumb=(string)($g['thumbnail'] ?? ''); $safeThumb=filter_var($thumb,FILTER_VALIDATE_URL) && strtolower((string)parse_url($thumb,PHP_URL_SCHEME))==='https' && parse_url($thumb,PHP_URL_HOST); ?>
      <a class="mp-game <?= $safeThumb ? '' : 'noimg' ?>" href="<?= e($u ? url('bigbang.php') : url('login.php')) ?>"><?php if($safeThumb): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy"><?php endif; ?><span class="mp-game-label"><?= e($g['title'] ?? $g['name'] ?? 'New demo game') ?></span></a>
    <?php endforeach; ?></div><?php else: ?><div class="mp-sportsnote">New sandbox titles will appear here.</div><?php endif; ?>
  </section>
  <div class="mp-foot"><b>🔒 Secure connection</b><p>BigBang demo titles use the provider's virtual sandbox balance. TradePro wallets are not connected to game play.</p><p>© <?= date('Y') ?> <?= e($siteName) ?></p></div>
</main>
</body></html>