<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
$admin = require_admin();
require_once __DIR__ . '/../includes/bigbang.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $newKey = trim((string)($_POST['api_key'] ?? ''));
            if ($newKey !== '' && !preg_match('/^ek_test_[A-Za-z0-9_-]{12,}$/', $newKey)) {
                throw new RuntimeException('Only a BigBang sandbox key beginning with ek_test_ is accepted. Live keys are blocked.');
            }
            if (!empty($_POST['clear_key'])) {
                set_setting('bigbang_api_key_enc', '');
                admin_log('bigbang_key_clear');
                flash('ok', 'BigBang sandbox key removed.');
            } elseif ($newKey !== '') {
                set_setting('bigbang_api_key_enc', secret_encrypt($newKey));
                admin_log('bigbang_key_save', 'Sandbox key updated');
                flash('ok', 'Sandbox key saved encrypted on the server.');
            } else {
                flash('info', 'No new key entered; existing key was kept.');
            }
        } elseif ($action === 'test') {
            $result = bigbang_request('GET', '/games?type=standard&limit=1');
            $count = (int)($result['pagination']['total'] ?? count($result['data'] ?? []));
            admin_log('bigbang_connection_test', 'Sandbox catalog OK');
            flash('ok', 'BigBang sandbox connection works. Catalog reports ' . $count . ' standard games.');
        }
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/bigbang.php');
}

$hasKey = bigbang_key() !== '';
admin_start('BigBang Sandbox', 'bigbang.php');
?>
<h1>BigBang Sandbox</h1>
<div class="card">
  <div class="inline" style="justify-content:space-between;gap:12px">
    <div><h2 style="margin:0">API connection</h2><p class="muted" style="margin:6px 0 0">Sandbox only · Standard games · Virtual balance only</p></div>
    <span class="badge <?= $hasKey ? 'ok' : 'pending' ?>"><?= $hasKey ? 'Key saved' : 'Not configured' ?></span>
  </div>
  <form method="post" style="margin-top:16px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="field">
      <label>BigBang sandbox API key</label>
      <input type="password" name="api_key" autocomplete="new-password" placeholder="<?= $hasKey ? 'Saved key — leave blank to keep it' : 'ek_test_...' ?>">
      <div class="hint">Only keys starting with <b>ek_test_</b> are accepted. The key is encrypted before storage and never sent to the browser.</div>
    </div>
    <?php if ($hasKey): ?><label class="check"><input type="checkbox" name="clear_key" value="1"> <span>Remove saved key</span></label><?php endif; ?>
    <button class="btn" type="submit">Save sandbox key</button>
  </form>
  <form method="post" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="action" value="test">
    <button class="btn ghost" type="submit" <?= $hasKey ? '' : 'disabled' ?>>Test API connection</button>
  </form>
</div>
<div class="card">
  <h2>Safety restrictions</h2>
  <ul class="muted">
    <li>Only the <code>standard</code> catalog is requested.</li>
    <li>Every launch uses BigBang <code>demo: true</code>.</li>
    <li>Live keys are rejected by this admin form.</li>
    <li>No managed-wallet, seamless-wallet, deposit, withdrawal or callback API is implemented.</li>
    <li>BigBang Premium, Enterprise and Sports games are excluded.</li>
  </ul>
</div>
<?php admin_end(); ?>
