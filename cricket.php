<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cricketdata.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_user();
$matches = [];
$error = '';
$detail = null;
try {
    if (isset($_GET['match']) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', (string)$_GET['match'])) {
        $detail = cricketdata_fetch('match_info', ['id' => (string)$_GET['match']]);
    } else {
        $response = cricketdata_fetch();
        $matches = is_array($response['data'] ?? null) ? $response['data'] : [];
    }
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}
page_head('Cricket Scores', 'has-mnav');
user_topbar($u, 'cricket.php');
?>
<div class="container" style="padding-top:24px;padding-bottom:90px">
  <div class="pagehead"><div><h1>Cricket</h1><p class="muted">Upcoming fixtures and match scores from CricketData.org</p></div></div>
  <?php render_flashes(); ?>
  <?php if ($error !== ''): ?>
    <div class="card"><h3>Cricket data unavailable</h3><p class="muted"><?= e($error) ?></p><p class="muted">The administrator must add an active CricketData API key. Data refreshes at most once every 15 minutes to stay within the free API allowance.</p></div>
  <?php elseif ($detail !== null): ?>
    <p><a class="btn ghost sm" href="<?= e(url('cricket.php')) ?>">← All matches</a></p>
    <div class="card"><h2>Match details</h2>
      <?php $d = $detail['data'] ?? []; ?>
      <?php if (is_array($d)): ?>
        <h3><?= e($d['name'] ?? $d['match'] ?? 'Match') ?></h3>
        <p><?= e($d['status'] ?? '') ?></p>
        <?php foreach (($d['score'] ?? []) as $sc): ?>
          <p><b><?= e($sc['inning'] ?? '') ?></b> — <?= e($sc['r'] ?? '') ?>/<?= e($sc['w'] ?? '') ?> (<?= e($sc['o'] ?? '') ?> overs)</p>
        <?php endforeach; ?>
        <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
      <?php else: ?><p class="muted">No match details were returned.</p><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card" style="margin-bottom:16px"><b><?= count($matches) ?></b> matches returned <span class="muted">· Shared cache: 15 minutes</span></div>
    <?php if (!$matches): ?><div class="card"><h3>No matches found</h3><p class="muted">CricketData did not return any current or upcoming matches.</p></div><?php endif; ?>
    <div class="grid g2">
    <?php foreach ($matches as $m):
      if (!is_array($m)) continue;
      $teams = is_array($m['teams'] ?? null) ? implode(' vs ', $m['teams']) : (string)($m['name'] ?? 'Match');
      $scores = is_array($m['score'] ?? null) ? $m['score'] : [];
    ?>
      <article class="card">
        <div class="row" style="justify-content:space-between;gap:8px"><span class="badge"><?= e($m['matchType'] ?? 'Cricket') ?></span><span class="muted"><?= e($m['status'] ?? '') ?></span></div>
        <h3 style="margin:12px 0"><?= e($teams) ?></h3>
        <?php foreach ($scores as $sc): if (!is_array($sc)) continue; ?>
          <p><b><?= e($sc['inning'] ?? '') ?></b>: <?= e($sc['r'] ?? '') ?>/<?= e($sc['w'] ?? '') ?> in <?= e($sc['o'] ?? '') ?> overs</p>
        <?php endforeach; ?>
        <p class="muted"><?= e($m['venue'] ?? '') ?> <?= !empty($m['date']) ? '· ' . e($m['date']) : '' ?></p>
        <?php if (!empty($m['id'])): ?><a class="btn ghost sm" href="<?= e(url('cricket.php?match=' . rawurlencode((string)$m['id']))) ?>">Match details</a><?php endif; ?>
      </article>
    <?php endforeach; ?>
    </div>
    <p class="muted" style="margin-top:18px">Scores are informational only. No bets, odds, or wallet transactions are available on this page.</p>
  <?php endif; ?>
</div>
<?php user_page_end(); ?>
