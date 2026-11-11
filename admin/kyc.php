<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $id = (int)($_POST['id'] ?? 0);
    $approve = ($_POST['decision'] ?? '') === 'approve';
    $note = trim((string)($_POST['note'] ?? ''));
    try {
        $k = q('SELECT k.*, u.email, u.name FROM kyc k JOIN users u ON u.id = k.user_id WHERE k.id = ?', [$id])->fetch();
        if (!$k) throw new RuntimeException('Submission not found.');
        if (!$approve && $note === '') throw new RuntimeException('Write a short reason so the user knows what to fix.');
        q('UPDATE kyc SET status = ?, admin_note = ?, reviewed_at = NOW() WHERE id = ?', [$approve ? 'approved' : 'rejected', $note === '' ? null : substr($note, 0, 255), $id]);
        admin_log('kyc_' . ($approve ? 'approve' : 'reject'), 'user #' . $k['user_id']);
        if (mail_ready()) {
            send_mail($k['email'], 'Identity verification ' . ($approve ? 'approved' : 'rejected'),
                mail_html('Verification ' . ($approve ? 'approved' : 'rejected'), '<p>Hi ' . e($k['name']) . ',</p><p>Your identity verification was <b>' . ($approve ? 'approved' : 'rejected') . '</b>.</p>'
                    . ($note !== '' ? '<p>Note: ' . e($note) . '</p>' : ''), $approve ? 'Open my account' : 'Submit again', abs_url($approve ? 'trade.php' : 'kyc.php')));
        }
        flash('ok', 'Submission ' . ($approve ? 'approved.' : 'rejected.'));
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/kyc.php?st=' . urlencode((string)($_POST['st'] ?? 'pending')));
}

admin_start('KYC', 'kyc.php');
$st = in_array($_GET['st'] ?? 'pending', ['pending', 'approved', 'rejected', 'all'], true) ? ($_GET['st'] ?? 'pending') : 'pending';
$where = $st === 'all' ? '' : 'WHERE k.status = ' . db()->quote($st);
$rows = q("SELECT k.*, u.email, u.name uname, u.phone FROM kyc k JOIN users u ON u.id = k.user_id $where ORDER BY (k.status = 'pending') DESC, k.submitted_at DESC LIMIT 100")->fetchAll();
?>
<h1>Identity verification (PAN &amp; Aadhaar)</h1>
<div class="tabs">
  <?php foreach (['pending', 'approved', 'rejected', 'all'] as $s): ?><a href="?st=<?= $s ?>" class="<?= $st === $s ? 'on' : '' ?>"><?= ucfirst($s) ?></a><?php endforeach; ?>
</div>
<p class="muted" style="font-size:13px">Documents are private: they are stored outside the public web folder and opened only here, and every view is written to the admin log. Turn on "KYC required before withdrawal" in Settings.</p>

<div class="grid g2">
<?php foreach ($rows as $k): ?>
  <div class="card" style="margin:0">
    <h2 style="display:flex;justify-content:space-between;gap:8px"><span><?= e($k['full_name']) ?></span><span class="badge <?= $k['status'] === 'approved' ? 'ok' : ($k['status'] === 'rejected' ? 'fail' : 'pending') ?>"><?= e($k['status']) ?></span></h2>
    <dl class="kv" style="margin-bottom:12px">
      <dt>User</dt><dd><a href="user.php?id=<?= (int)$k['user_id'] ?>"><?= e($k['email']) ?></a></dd><span></span>
      <dt>Contact</dt><dd><?= e($k['phone'] ?: '—') ?></dd><span></span>
      <dt>PAN</dt><dd class="mono"><?= e($k['pan_number']) ?></dd><button type="button" class="copy" data-copy="<?= e($k['pan_number']) ?>">Copy</button>
      <dt>Aadhaar</dt><dd class="mono">•••• •••• <?= e($k['aadhaar_last4']) ?></dd><span></span>
      <dt>Submitted</dt><dd><?= e(fmt_dt($k['submitted_at'])) ?></dd><span></span>
    </dl>
    <div class="inline" style="margin-bottom:12px">
      <a class="btn sm ghost" target="_blank" rel="noopener" href="kyc_file.php?id=<?= (int)$k['id'] ?>&f=pan">View PAN card</a>
      <a class="btn sm ghost" target="_blank" rel="noopener" href="kyc_file.php?id=<?= (int)$k['id'] ?>&f=aadhaar">View Aadhaar card</a>
    </div>
    <?php if ($k['admin_note']): ?><p class="muted" style="font-size:13px">Note: <?= e($k['admin_note']) ?></p><?php endif; ?>
    <?php if ($k['status'] === 'pending'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="st" value="<?= e($st) ?>">
      <div class="field"><input type="text" name="note" placeholder="Note to the user (required when rejecting)" maxlength="200"></div>
      <div class="inline"><button class="btn green" name="decision" value="approve" type="submit" data-confirm="Approve this verification? Check that the name, photo and PAN match.">Approve</button>
        <button class="btn red" name="decision" value="reject" type="submit">Reject</button></div>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; if (!$rows): ?><div class="card muted">Nothing here.</div><?php endif; ?>
</div>
<?php admin_end();
