<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/bigbang.php';
$admin = require_admin();
$perPage = 30;
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$search = trim((string)($_GET['q'] ?? ''));
$providerFilter = trim((string)($_GET['provider'] ?? ''));
$error = '';
$games = [];
$totalGames = 0;
$totalPages = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $newKey = trim((string)($_POST['api_key'] ?? ''));
            if ($newKey !== '' && !preg_match('/^ek_test_[A-Za-z0-9_-]{12,}$/', $newKey)) throw new RuntimeException('Only a sandbox key beginning with ek_test_ is accepted.');
            if (!empty($_POST['clear_key'])) {
                set_setting('bigbang_api_key_enc', '');
                admin_log('bigbang_key_clear');
                flash('ok', 'BigBang sandbox key removed.');
            } elseif ($newKey !== '') {
                set_setting('bigbang_api_key_enc', secret_encrypt($newKey));
                admin_log('bigbang_key_save', 'Sandbox key updated');
                flash('ok', 'Sandbox key saved encrypted on the server.');
            } else flash('info', 'No new key entered; existing key was kept.');
        } elseif ($action === 'test') {
            $result = bigbang_request('GET', '/games?type=standard&limit=1');
            $count = (int)($result['pagination']['total'] ?? count($result['data'] ?? []));
            admin_log('bigbang_connection_test', 'Sandbox catalog OK');
            flash('ok', 'BigBang sandbox connection works. Standard catalogue reports ' . $count . ' games.');
        }
    } catch (Throwable $ex) { flash('err', $ex->getMessage()); }
    redirect('admin/bigbang.php');
}

$hasKey = bigbang_key() !== '';
if ($hasKey) {
    try {
        $params = ['type' => 'standard', 'limit' => $perPage, 'offset' => ($page - 1) * $perPage];
        if ($search !== '') $params['search'] = $search;
        if ($providerFilter !== '') $params['provider'] = $providerFilter;
        $catalog = bigbang_request('GET', '/games?' . http_build_query($params));
        $rows = $catalog['data'] ?? [];
        if (!is_array($rows)) throw new RuntimeException('BigBang returned an unexpected catalogue format.');
        $totalGames = (int)($catalog['pagination']['total'] ?? count($rows));
        $totalPages = max(1, (int)ceil($totalGames / $perPage));
        $games = array_values(array_filter($rows, static fn($g) => is_array($g) && (int)($g['id'] ?? 0) > 0 && ($g['tier'] ?? 'standard') === 'standard' && ($g['mode'] ?? 'standard') !== 'premium' && empty($g['is_premium'])));
    } catch (Throwable $ex) { $error = $ex->getMessage(); }
}
admin_start('BigBang Sandbox', 'bigbang.php');
?>
<style>
.bb-admin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}.bb-admin-game{overflow:hidden}.bb-admin-game img{width:100%;aspect-ratio:16/10;object-fit:cover;display:block;border-radius:9px}.bb-admin-game h3{margin:9px 0 4px;font-size:16px}.bb-admin-tools{display:flex;gap:9px;flex-wrap:wrap;margin:14px 0}.bb-admin-tools input{flex:1;min-width:170px}
</style>
<h1>BigBang Sandbox</h1>
<div class="card">
  <div class="inline" style="justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div><h2 style="margin:0">API connection</h2><p class="muted" style="margin:6px 0 0">Sandbox only · Standard games · BigBang virtual balance</p></div>
    <span class="badge <?= $hasKey ? 'ok' : 'pending' ?>"><?= $hasKey ? 'Key saved' : 'Not configured' ?></span>
  </div>
  <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="field"><label>BigBang sandbox API key</label><input type="password" name="api_key" autocomplete="new-password" placeholder="<?= $hasKey ? 'Saved key — leave blank to keep it' : 'ek_test_...' ?>"><div class="hint">Key is encrypted in the settings database and never shown again.</div></div>
    <?php if ($hasKey): ?><label class="check"><input type="checkbox" name="clear_key" value="1"> <span>Remove saved key</span></label><?php endif; ?>
    <button class="btn" type="submit">Save sandbox key</button>
  </form>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn ghost" type="submit" <?= $hasKey ? '' : 'disabled' ?>>Test API connection</button></form>
</div>
<?php if ($hasKey): ?>
  <div class="card">
    <div class="inline" style="justify-content:space-between;flex-wrap:wrap"><div><h2 style="margin:0">Standard game catalogue</h2><p class="muted" style="margin:5px 0 0">All available sandbox games are browsable here. <?= $totalGames ?> reported by the API.</p></div><span class="badge ok">Demo only</span></div>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php else: ?>
      <form class="bb-admin-tools" method="get"><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search game title"><input type="text" name="provider" value="<?= e($providerFilter) ?>" placeholder="Filter provider"><button class="btn" type="submit">Filter</button><?php if($search!==''||$providerFilter!==''): ?><a class="btn ghost" href="<?= e(url('admin/bigbang.php')) ?>">Clear</a><?php endif; ?></form>
      <div class="bb-admin-grid">
        <?php foreach ($games as $g): $thumb=(string)($g['thumbnail']??''); $safe=filter_var($thumb,FILTER_VALIDATE_URL)&&strtolower((string)parse_url($thumb,PHP_URL_SCHEME))==='https'&&parse_url($thumb,PHP_URL_HOST); ?>
          <article class="card bb-admin-game"><?php if($safe): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy"><?php else: ?><div style="aspect-ratio:16/10;display:grid;place-items:center;background:#17233b;font-size:40px">🎮</div><?php endif; ?><h3><?= e($g['title']??$g['name']??'Game') ?></h3><p class="muted" style="font-size:12px;margin:0">ID: <?= (int)$g['id'] ?> · <?= e($g['provider']??'Provider unavailable') ?></p><p class="muted" style="font-size:12px;margin:4px 0 0">Tier: Standard · Demo eligible</p></article>
        <?php endforeach; ?>
      </div>
      <?php if (!$games): ?><p class="muted">No Standard games matched this filter.</p><?php endif; ?>
      <?php if($totalPages>1): ?><nav class="pager" aria-label="Catalogue pages"><?php if($page>1): ?><a class="btn ghost sm" href="?<?= e(http_build_query(['q'=>$search,'provider'=>$providerFilter,'page'=>$page-1])) ?>">‹ Previous</a><?php endif; ?><span class="muted">Page <?= $page ?> / <?= $totalPages ?> · <?= $totalGames ?> games</span><?php if($page<$totalPages): ?><a class="btn ghost sm" href="?<?= e(http_build_query(['q'=>$search,'provider'=>$providerFilter,'page'=>$page+1])) ?>">Next ›</a><?php endif; ?></nav><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<div class="card"><h2>Sandbox restrictions</h2><ul class="muted"><li>Only Standard catalogue is requested and shown.</li><li>All game launches use <code>demo: true</code>.</li><li>Live keys are rejected.</li><li>No TradePro wallet, deposit, withdrawal or provider callbacks are connected.</li><li>Premium, Enterprise and sportsbook products are excluded.</li></ul></div>
<?php admin_end(); ?>