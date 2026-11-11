<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bigbang.php';

$u = user_page_start('BigBang Demo Games', 'games.php');
play_nav('hub');
if (!games_on()) {
    echo '<div class="card"><h1>Games are currently unavailable</h1></div>';
    user_page_end(); exit;
}
$keyReady = bigbang_key() !== '';
$games = [];
$error = '';
$launchUrl = '';
$launchTitle = '';

try {
    if (!$keyReady) throw new RuntimeException('The administrator has not configured the BigBang sandbox key yet.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $gameId = (int)($_POST['game_id'] ?? 0);
        if ($gameId < 1) throw new RuntimeException('Choose a valid game.');
        $catalog = bigbang_request('GET', '/games?type=standard&limit=500');
        $selected = null;
        foreach (($catalog['data'] ?? []) as $g) if ((int)($g['id'] ?? 0) === $gameId) { $selected = $g; break; }
        if (!$selected || ($selected['tier'] ?? 'standard') !== 'standard' || ($selected['mode'] ?? 'standard') === 'premium') {
            throw new RuntimeException('This game is not available in sandbox mode.');
        }
        $launched = bigbang_request('POST', '/games/launch', ['game_id' => $gameId, 'demo' => true, 'language' => 'en', 'return_url' => abs_url('bigbang.php')]);
        $candidate = (string)($launched['game_url'] ?? '');
        $host = strtolower((string)parse_url($candidate, PHP_URL_HOST));
        if (!filter_var($candidate, FILTER_VALIDATE_URL) || !in_array($host, ['bigbangcasino.bet', 'www.bigbangcasino.bet'], true)) {
            throw new RuntimeException('Provider returned an invalid game URL.');
        }
        $launchUrl = $candidate;
        $launchTitle = (string)($selected['title'] ?? $selected['name'] ?? 'Demo game');
    } else {
        $catalog = bigbang_request('GET', '/games?type=standard&limit=100');
        $games = array_values(array_filter($catalog['data'] ?? [], fn($g) => ($g['tier'] ?? 'standard') === 'standard' && ($g['mode'] ?? 'standard') !== 'premium'));
    }
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}
?>
<div class="gamehead">
  <div><h1 style="margin:0">BigBang Demo Games</h1><div class="muted" style="font-size:13px">Sandbox games · Virtual balance · No cash value</div></div>
  <a class="btn ghost sm" href="<?= e(url('games.php')) ?>">Back to games</a>
</div>
<?php if ($error): ?><div class="flash <?= $keyReady ? 'err' : 'info' ?>"><?= e($error) ?></div><?php endif; ?>
<?php if ($launchUrl): ?>
  <div class="card">
    <div class="inline" style="justify-content:space-between"><h2><?= e($launchTitle) ?></h2><a class="btn ghost sm" href="<?= e(url('bigbang.php')) ?>">Back to lobby</a></div>
    <div style="position:relative;width:100%;height:min(78vh,760px);min-height:420px;background:#080b12;border-radius:14px;overflow:hidden">
      <iframe src="<?= e($launchUrl) ?>" title="<?= e($launchTitle) ?>" allow="autoplay; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" style="width:100%;height:100%;border:0"></iframe>
    </div>
    <p class="muted" style="font-size:12px">Provider-hosted sandbox session. TradePro INR/USDT balances are not used.</p>
  </div>
<?php elseif ($games): ?>
  <div class="grid g3 hubgrid">
    <?php foreach ($games as $g): ?>
      <div class="hubcard">
        <?php if (!empty($g['thumbnail']) && preg_match('#^https://#i', (string)$g['thumbnail'])): ?>
          <img src="<?= e($g['thumbnail']) ?>" alt="" loading="lazy" style="width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:10px">
        <?php else: ?><div class="hubic">🎮</div><?php endif; ?>
        <h2><?= e($g['title'] ?? $g['name'] ?? 'Game') ?></h2>
        <p><?= e($g['provider'] ?? 'Standard provider') ?> · <?= e($g['game_type'] ?? 'game') ?></p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="game_id" value="<?= (int)$g['id'] ?>"><button class="btn sm" type="submit">Play demo →</button></form>
      </div>
    <?php endforeach; ?>
  </div>
<?php elseif (!$error): ?>
  <div class="card"><h2>No standard games returned</h2><p class="muted">Check the sandbox key's provider permissions in the BigBang portal.</p></div>
<?php endif; ?>
<?php user_page_end(); ?>
