<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $provider = 'cricketdata';
            $label = trim((string)($_POST['label'] ?? ''));
            $key = trim((string)($_POST['api_key'] ?? ''));
            if ($label === '' || mb_strlen($label) > 100) {
                throw new RuntimeException('Enter a label up to 100 characters (for example: Primary key).');
            }
            if ($key === '' || strlen($key) > 2000 || preg_match('/\s/', $key)) {
                throw new RuntimeException('Paste a valid CricketData API key without spaces.');
            }
            q('INSERT INTO api_keys (provider, label, api_key_enc, is_active) VALUES (?, ?, ?, 1)',
                [$provider, $label, secret_encrypt($key)]);
            admin_log('api_key_add', 'CricketData: ' . $label);
            flash('ok', 'CricketData API key saved encrypted.');
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            q('UPDATE api_keys SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND provider = ?', [$id, 'cricketdata']);
            admin_log('api_key_toggle', '#' . $id);
            flash('ok', 'API key status updated.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            q('DELETE FROM api_keys WHERE id = ? AND provider = ?', [$id, 'cricketdata']);
            admin_log('api_key_delete', '#' . $id);
            flash('ok', 'API key deleted.');
        }
    } catch (PDOException $ex) {
        if ((string)$ex->getCode() === '23000') {
            flash('err', 'That label is already used. Choose a different label.');
        } else {
            error_log('api_keys: ' . $ex->getMessage());
            flash('err', 'Could not save the API key. Check the database and try again.');
        }
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/api_keys.php');
}

$keys = q('SELECT id, label, api_key_enc, is_active, created_at, updated_at FROM api_keys WHERE provider = ? ORDER BY id DESC', ['cricketdata'])->fetchAll();
admin_start('API Keys', 'api_keys.php');
?>
<h1>API Keys</h1>
<div class="card">
  <h2>CricketData.org API</h2>
  <p class="muted">Manage your CricketData API keys here. Keys are encrypted before being stored and are never shown in full after saving. This page only stores keys; it does not make API requests or change any wallet/payment functionality.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="row">
      <div class="field"><label>Key label</label><input type="text" name="label" maxlength="100" required placeholder="Primary / Backup"></div>
      <div class="field"><label>CricketData API key</label><input type="password" name="api_key" required autocomplete="new-password" placeholder="Paste API key from cricketdata.org"></div>
    </div>
    <button class="btn green" type="submit">Save API key</button>
  </form>
</div>
<div class="card">
  <h2>Saved keys <span class="badge"><?= count($keys) ?></span></h2>
  <div class="tablewrap"><table class="rt">
    <tr><th>Label</th><th>Masked key</th><th>Status</th><th>Added</th><th>Actions</th></tr>
    <?php foreach ($keys as $item):
      $plain = secret_decrypt((string)$item['api_key_enc']);
      $masked = $plain !== '' ? str_repeat('•', max(4, min(12, strlen($plain) - 4))) . substr($plain, -4) : 'Unavailable';
    ?>
      <tr>
        <td><?= e($item['label']) ?></td>
        <td class="mono"><?= e($masked) ?></td>
        <td><?= $item['is_active'] ? '<span class="badge ok">Active</span>' : '<span class="badge pending">Disabled</span>' ?></td>
        <td><?= e(fmt_dt($item['created_at'])) ?></td>
        <td><div class="inline">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn sm ghost" type="submit"><?= $item['is_active'] ? 'Disable' : 'Enable' ?></button></form>
          <form method="post" data-confirm="Permanently delete this API key?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn sm red" type="submit">Delete</button></form>
        </div></td>
      </tr>
    <?php endforeach; if (!$keys): ?><tr><td colspan="5" class="muted">No CricketData API keys saved yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php admin_end(); ?>
