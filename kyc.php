<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = require_user();
$uid = (int)$u['id'];
$k = kyc_get($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        if ($k && in_array($k['status'], ['pending', 'approved'], true)) throw new RuntimeException('Your documents are already ' . $k['status'] . '.');
        if (!throttle_ok('kyc', 5, 3600)) throw new RuntimeException('Too many attempts. Try again later.');
        throttle_hit('kyc');
        $name = trim((string)($_POST['full_name'] ?? ''));
        $pan = strtoupper(trim((string)($_POST['pan'] ?? '')));
        $last4 = trim((string)($_POST['aadhaar4'] ?? ''));
        $phone = valid_phone((string)($_POST['phone'] ?? ''));
        if (!preg_match('/^[A-Za-z][A-Za-z .\']{2,99}$/', $name)) throw new RuntimeException('Enter your full name exactly as on the PAN card.');
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) throw new RuntimeException('PAN must look like ABCDE1234F.');
        if (!preg_match('/^[0-9]{4}$/', $last4)) throw new RuntimeException('Enter the last 4 digits of your Aadhaar number.');
        if (!$phone) throw new RuntimeException('Enter a valid contact number (10–14 digits).');
        if (empty($_POST['consent'])) throw new RuntimeException('Please tick the consent box.');
        $panFile = kyc_store_upload($_FILES['pan_doc'] ?? [], 'PAN card');
        try {
            $aadFile = kyc_store_upload($_FILES['aadhaar_doc'] ?? [], 'Aadhaar card');
        } catch (RuntimeException $ex) {
            @unlink(STORAGE_DIR . '/kyc/' . $panFile);
            throw $ex;
        }
        if ($k) kyc_delete_files($k);
        q("INSERT INTO kyc (user_id, full_name, pan_number, aadhaar_last4, pan_file, aadhaar_file, status, admin_note, submitted_at, reviewed_at)
           VALUES (?,?,?,?,?,?, 'pending', NULL, NOW(), NULL)
           ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), pan_number = VALUES(pan_number), aadhaar_last4 = VALUES(aadhaar_last4),
             pan_file = VALUES(pan_file), aadhaar_file = VALUES(aadhaar_file), status = 'pending', admin_note = NULL, submitted_at = NOW(), reviewed_at = NULL",
            [$uid, $name, $pan, $last4, $panFile, $aadFile]);
        q('UPDATE users SET phone = ? WHERE id = ?', [$phone, $uid]);
        notify_admins('New KYC submission', '<p><b>' . e($u['name']) . '</b> (' . e($u['email']) . ') submitted PAN / Aadhaar documents for review.</p>', 'Review', abs_url('admin/kyc.php'));
        flash('ok', 'Documents submitted. We will review them shortly.');
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('kyc.php');
}

$u = user_page_start('Verification', 'profile.php');
$k = kyc_get($uid);
$st = $k['status'] ?? 'none';
?>
<h1>Identity verification <span class="badge <?= $st === 'approved' ? 'ok' : ($st === 'rejected' ? 'fail' : 'pending') ?>"><?= $st === 'none' ? 'not submitted' : e($st) ?></span></h1>
<p class="muted">For the safety of your money and ours we verify your identity with your <b>PAN card</b> and <b>Aadhaar card</b>.<?= kyc_required_for_withdraw() ? ' Withdrawals need an approved verification.' : '' ?></p>

<?php if ($k && $st !== 'rejected'): ?>
  <div class="card" style="max-width:640px">
    <dl class="kv">
      <dt>Name</dt><dd><?= e($k['full_name']) ?></dd><span></span>
      <dt>PAN</dt><dd class="mono"><?= e(mask_pan($k['pan_number'])) ?></dd><span></span>
      <dt>Aadhaar</dt><dd class="mono">•••• •••• <?= e($k['aadhaar_last4']) ?></dd><span></span>
      <dt>Submitted</dt><dd><?= e(fmt_dt($k['submitted_at'])) ?></dd><span></span>
      <?php if ($k['reviewed_at']): ?><dt>Reviewed</dt><dd><?= e(fmt_dt($k['reviewed_at'])) ?></dd><span></span><?php endif; ?>
    </dl>
    <?php if ($st === 'pending'): ?><div class="flash info" style="margin:14px 0 0">Your documents are being reviewed. You will get an email when it is done.</div>
    <?php else: ?><div class="flash ok" style="margin:14px 0 0">Verified ✓ Thank you.</div><?php endif; ?>
  </div>
<?php else: ?>
  <?php if ($k && $st === 'rejected'): ?><div class="flash err">Your last submission was rejected<?= $k['admin_note'] ? ': ' . e($k['admin_note']) : '' ?>. Please submit clear documents again.</div><?php endif; ?>
  <div class="card" style="max-width:640px">
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
      <div class="field"><label>Full name (as on PAN card)</label><input type="text" name="full_name" value="<?= e($k['full_name'] ?? $u['name']) ?>" required maxlength="100"></div>
      <div class="row">
        <div class="field"><label>PAN number</label><input type="text" name="pan" value="<?= e($k['pan_number'] ?? '') ?>" required maxlength="10" placeholder="ABCDE1234F" autocapitalize="characters" style="text-transform:uppercase" autocomplete="off"></div>
        <div class="field"><label>Aadhaar – last 4 digits</label><input type="text" name="aadhaar4" required maxlength="4" inputmode="numeric" pattern="[0-9]{4}" placeholder="1234" autocomplete="off"></div>
      </div>
      <div class="field"><label>Contact number</label><input type="tel" name="phone" value="<?= e($u['phone']) ?>" required placeholder="+91 98765 43210"></div>
      <div class="field"><label>PAN card (photo or PDF)</label><input type="file" name="pan_doc" accept="image/jpeg,image/png,application/pdf" required></div>
      <div class="field"><label>Aadhaar card (photo or PDF)</label><input type="file" name="aadhaar_doc" accept="image/jpeg,image/png,application/pdf" required>
        <div class="hint">You may hide the first 8 digits of the Aadhaar number (masked copy). The photo and name must stay readable.</div></div>
      <div class="field"><label class="check"><input type="checkbox" name="consent" value="1"> <span>I confirm these documents are mine and agree to their use for identity verification only.</span></label></div>
      <button class="btn green" type="submit">Submit for verification</button>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Files are stored privately and are visible only to administrators. JPG, PNG or PDF, up to 3 MB each.</p>
    </form>
  </div>
<?php endif; ?>
<?php user_page_end();
