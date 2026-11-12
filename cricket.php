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
    <?php
      $d = is_array($detail['data'] ?? null) ? $detail['data'] : [];
      $teamInfo = is_array($d['teamInfo'] ?? null) ? $d['teamInfo'] : [];
      $scores = is_array($d['score'] ?? null) ? $d['score'] : [];
      $startLabel = '';
      if (!empty($d['dateTimeGMT'])) {
          try {
              $matchTime = new DateTime((string)$d['dateTimeGMT'], new DateTimeZone('UTC'));
              $matchTime->setTimezone(new DateTimeZone('Asia/Kolkata'));
              $startLabel = $matchTime->format('D, d M Y · h:i A') . ' IST';
          } catch (Throwable $ignored) {}
      } elseif (!empty($d['date'])) {
          $startLabel = date('D, d M Y', strtotime((string)$d['date']));
      }
      $statusText = (string)($d['status'] ?? 'Schedule not available');
      $isLive = !empty($d['matchStarted']) && empty($d['matchEnded']);
      $isComplete = !empty($d['matchEnded']);
    ?>
    <p style="margin-bottom:16px"><a class="btn ghost sm" href="<?= e(url('cricket.php')) ?>">← All matches</a></p>
    <section class="card" style="padding:20px">
      <div class="row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="badge"><?= e(strtoupper((string)($d['matchType'] ?? 'CRICKET'))) ?></span>
        <span class="badge <?= $isLive ? 'ok' : '' ?>"><?= $isLive ? 'LIVE' : ($isComplete ? 'COMPLETED' : 'UPCOMING') ?></span>
      </div>
      <h2 style="margin:18px 0 6px;text-align:center"><?= e($d['series'] ?? $d['seriesName'] ?? 'Match centre') ?></h2>
      <p class="muted" style="text-align:center;margin:0 0 22px"><?= e($d['name'] ?? $d['match'] ?? 'Match details') ?></p>
      <div class="grid g2" style="gap:14px;align-items:center">
        <?php foreach (array_slice($teamInfo, 0, 2) as $team): ?>
          <div class="card" style="text-align:center;padding:16px">
            <?php if (!empty($team['img']) && filter_var($team['img'], FILTER_VALIDATE_URL) && str_starts_with((string)$team['img'], 'https://')): ?>
              <img src="<?= e($team['img']) ?>" alt="" loading="lazy" style="width:58px;height:58px;object-fit:contain;margin:0 auto 10px">
            <?php endif; ?>
            <h3 style="margin:0 0 5px"><?= e($team['name'] ?? 'Team') ?></h3>
            <?php if (!empty($team['shortname'])): ?><p class="muted" style="margin:0"><?= e($team['shortname']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($scores): ?>
        <div class="card" style="margin-top:16px">
          <h3>Scorecard</h3>
          <?php foreach ($scores as $score): if (!is_array($score)) continue; ?>
            <div class="row" style="justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--line)">
              <b><?= e($score['inning'] ?? 'Innings') ?></b>
              <strong><?= e($score['r'] ?? '—') ?>/<?= e($score['w'] ?? '—') ?> <span class="muted"><?= e($score['o'] ?? '') ?> ov</span></strong>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div style="text-align:center;padding:18px 8px;margin-top:12px">
          <h3 style="margin:0 0 8px"><?= e($statusText) ?></h3>
          <p class="muted" style="margin:0">Live score will appear here when CricketData provides it.</p>
        </div>
      <?php endif; ?>
      <div class="card" style="margin-top:14px">
        <h3>Match information</h3>
        <div class="row" style="justify-content:space-between;gap:12px;padding:9px 0"><span class="muted">Date & time</span><b style="text-align:right"><?= e($startLabel ?: 'Not announced') ?></b></div>
        <div class="row" style="justify-content:space-between;gap:12px;padding:9px 0"><span class="muted">Venue</span><b style="text-align:right"><?= e($d['venue'] ?? 'Not announced') ?></b></div>
        <div class="row" style="justify-content:space-between;gap:12px;padding:9px 0"><span class="muted">Match status</span><b style="text-align:right"><?= e($statusText) ?></b></div>
        <?php if (array_key_exists('hasSquad', $d)): ?><div class="row" style="justify-content:space-between;gap:12px;padding:9px 0"><span class="muted">Squad information</span><b><?= !empty($d['hasSquad']) ? 'Available' : 'Not available' ?></b></div><?php endif; ?>
      </div>
    </section>
    <p class="muted" style="margin-top:14px">Match information provided by CricketData.org. Times are shown in Indian Standard Time (IST). Scores appear only when supplied by the API.</p>
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
