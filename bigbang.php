<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bigbang.php';

$u = user_page_start('BigBang Demo Games', 'games.php');
games_gate($u, 'games.php');

$keyReady = bigbang_key() !== '';
$games = [];
$error = '';
$launchUrl = '';
$launchTitle = '';
$providerFilter = trim((string)($_GET['provider'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$perPage = 30;
$totalGames = 0;
$totalPages = 1;

try {
    if (!$keyReady) {
        throw new RuntimeException('BigBang sandbox key is not configured. Ask the administrator to save the ek_test_ key.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
        if (!$gameId || $gameId < 1) throw new RuntimeException('Choose a valid game.');

        $catalog = bigbang_request('GET', '/games?type=standard&limit=5000');
        $catalogRows = $catalog['data'] ?? [];
        if (!is_array($catalogRows)) throw new RuntimeException('BigBang returned an unexpected game catalog format.');
        $selected = null;
        foreach ($catalogRows as $g) {
            if ((int)($g['id'] ?? 0) === (int)$gameId) { $selected = $g; break; }
        }
        if (!$selected || ($selected['tier'] ?? 'standard') !== 'standard' || ($selected['mode'] ?? 'standard') === 'premium' || !empty($selected['is_premium'])) {
            throw new RuntimeException('This title is not available through the free sandbox key.');
        }

        $launched = bigbang_request('POST', '/games/launch', [
            'game_id' => (int)$gameId,
            'demo' => true,
            'language' => 'en',
            'return_url' => abs_url('bigbang.php')
        ]);
        $candidate = (string)($launched['game_url'] ?? '');
        $parts = parse_url($candidate);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!filter_var($candidate, FILTER_VALIDATE_URL) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !in_array($host, ['bigbangcasino.bet', 'www.bigbangcasino.bet'], true)) {
            throw new RuntimeException('BigBang did not return a valid sandbox game URL.');
        }
        $launchUrl = $candidate;
        $launchTitle = (string)($selected['title'] ?? $selected['name'] ?? 'Demo game');
    } else {
        $params = ['type' => 'standard', 'limit' => $perPage, 'offset' => ($page - 1) * $perPage];
        if ($providerFilter !== '') $params['provider'] = $providerFilter;
        if ($search !== '') $params['search'] = $search;
        $query = http_build_query($params);
        $catalog = bigbang_request('GET', '/games?' . $query);
        $rows = $catalog['data'] ?? [];
        if (!is_array($rows)) throw new RuntimeException('BigBang returned an unexpected game catalog format.');
        $totalGames = (int)($catalog['pagination']['total'] ?? count($rows));
        $totalPages = max(1, (int)ceil($totalGames / $perPage));
        $games = array_values(array_filter($rows, static function ($g) {
            return is_array($g)
                && (int)($g['id'] ?? 0) > 0
                && ($g['tier'] ?? 'standard') === 'standard'
                && ($g['mode'] ?? 'standard') !== 'premium'
                && empty($g['is_premium']);
        }));
    }
} catch (Throwable $ex) {
    error_log('BigBang lobby: ' . $ex->getMessage());
    $error = $ex->getMessage();
}

?>
<style>
.bb-wrap{margin:18px 0 36px}.bb-tools{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.bb-tools input,.bb-tools select{min-width:180px;flex:1}.bb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px}.bb-game{min-width:0}.bb-game img{display:block;width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:10px;background:#101522}.bb-game h2{font-size:17px;margin:12px 0 5px}.bb-game p{margin:0 0 12px}.bb-game form{margin-top:auto}.bb-frame{width:100%;height:min(78vh,820px);min-height:460px;border:0;border-radius:12px;background:#080b12}.bb-empty{padding:28px;text-align:center}.bb-count{font-size:13px;color:var(--muted)}
@media(max-width:600px){.bb-frame{min-height:360px;height:70vh}.bb-tools>*{width:100%}}
</style>
<div class="gamehead">
  <div><h1 style="margin:0">BigBang Demo Games</h1><div class="muted" style="font-size:13px">Free sandbox · Standard games · BigBang virtual balance</div></div>
  <a class="btn ghost sm" href="<?= e(url('games.php')) ?>">Back to games</a>
</div>
<div class="bb-wrap">
<?php if ($error): ?>
  <div class="flash err"><b>BigBang could not load.</b><br><?= e($error) ?></div>
  <div class="card"><p class="muted">Check that the sandbox key is active and has <code>can_read_games</code> permission. Game launching also requires <code>can_launch_games</code>.</p><a class="btn ghost sm" href="<?= e(url('bigbang.php')) ?>">Try again</a></div>
<?php elseif ($launchUrl): ?>
  <div class="card">
    <div class="inline" style="justify-content:space-between;gap:12px;flex-wrap:wrap"><h2><?= e($launchTitle) ?></h2><div class="inline"><button class="btn sm" type="button" id="bbFullscreen">⛶ Full screen</button><a class="btn ghost sm" href="<?= e(url('bigbang.php')) ?>">Back to lobby</a></div></div>
    <iframe class="bb-frame" src="<?= e($launchUrl) ?>" title="<?= e($launchTitle) ?>" allow="autoplay; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
    <p class="muted" style="font-size:12px;margin-bottom:0">This session uses BigBang's free sandbox virtual balance. TradePro Demo coins are not deducted.</p>
  </div>
  <script>
  (function(){var b=document.getElementById('bbFullscreen'),f=document.querySelector('.bb-frame');if(!b||!f)return;b.addEventListener('click',function(){var el=f;if(el.requestFullscreen)el.requestFullscreen();else if(el.webkitRequestFullscreen)el.webkitRequestFullscreen();else if(f.contentWindow&&f.contentWindow.document&&f.contentWindow.document.documentElement.requestFullscreen)f.contentWindow.document.documentElement.requestFullscreen();});})();
  </script>
<?php else: ?>
  <form class="bb-tools" method="get">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search games">
    <input type="text" name="provider" value="<?= e($providerFilter) ?>" placeholder="Provider (optional)">
    <button class="btn" type="submit">Search</button>
    <?php if ($search !== '' || $providerFilter !== ''): ?><a class="btn ghost" href="<?= e(url('bigbang.php')) ?>">Clear</a><?php endif; ?>
  </form>
  <div class="bb-count">Showing <?= count($games) ?> of <?= $totalGames ?> sandbox games · <?= $perPage ?> per page</div>
  <?php if ($games): ?>
    <div class="bb-grid" style="margin-top:14px">
    <?php foreach ($games as $g): ?>
      <article class="hubcard bb-game">
        <?php
          $thumb = (string)($g['thumbnail'] ?? '');
          $thumbHost = strtolower((string)parse_url($thumb, PHP_URL_HOST));
          if (filter_var($thumb, FILTER_VALIDATE_URL) && strtolower((string)parse_url($thumb, PHP_URL_SCHEME)) === 'https' && $thumbHost !== ''):
        ?>
          <img src="<?= e($thumb) ?>" alt="" loading="lazy">
        <?php else: ?><div class="hubic">🎮</div><?php endif; ?>
        <h2><?= e($g['title'] ?? $g['name'] ?? 'Game') ?></h2>
        <p class="muted"><?= e($g['provider'] ?? $g['category_title'] ?? 'Standard provider') ?> · <?= e($g['game_type'] ?? 'Game') ?></p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="game_id" value="<?= (int)$g['id'] ?>"><button class="btn sm" type="submit">Play demo →</button></form>
      </article>
    <?php endforeach; ?>
    </div>
    <?php if ($totalPages > 1): ?>
      <nav class="bb-pagination" aria-label="Game pages" style="display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap;margin:22px 0">
        <?php
          $pageUrl = static function (int $p) use ($search, $providerFilter): string {
              return url('bigbang.php?' . http_build_query(array_filter(['q' => $search, 'provider' => $providerFilter, 'page' => $p], static fn($v) => $v !== '')));
          };
        ?>
        <?php if ($page > 1): ?><a class="btn ghost sm" href="<?= e($pageUrl($page - 1)) ?>">← Previous</a><?php endif; ?>
        <span class="muted" style="font-size:13px">Page <?= $page ?> of <?= $totalPages ?> · <?= $totalGames ?> games</span>
        <?php if ($page < $totalPages): ?><a class="btn ghost sm" href="<?= e($pageUrl($page + 1)) ?>">Next →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php else: ?>
    <div class="card bb-empty"><h2>No sandbox games returned</h2><p class="muted">The key may have no Standard providers enabled, or the provider filter returned no matches. Check provider access in the BigBang dashboard.</p></div>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php user_page_end(); ?>
