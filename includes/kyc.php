<?php
/** Identity check (PAN + Aadhaar) for safety. Documents live in storage/kyc (not reachable from the web) and are shown only to admins. */

function kyc_get(int $uid): ?array
{
    return q('SELECT * FROM kyc WHERE user_id = ?', [$uid])->fetch() ?: null;
}

/** 'none' | 'pending' | 'approved' | 'rejected' */
function kyc_status(int $uid): string
{
    $k = kyc_get($uid);
    return $k ? $k['status'] : 'none';
}

function kyc_required_for_withdraw(): bool
{
    return setting('kyc_required_withdraw', '0') === '1';
}

function mask_pan(string $p): string
{
    return substr($p, 0, 2) . '••••' . substr($p, -3);
}

/** Validate and store one uploaded document; returns the stored file name. */
function kyc_store_upload(array $f, string $label): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException("$label: choose a file to upload (JPG, PNG or PDF, up to 3 MB).");
    if ($f['size'] > 3 * 1024 * 1024) throw new RuntimeException("$label: the file is larger than 3 MB.");
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException("$label: upload failed, please try again.");
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) throw new RuntimeException("$label: only JPG, PNG or PDF files are accepted.");
    $dir = STORAGE_DIR . '/kyc';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException("$label: could not save the file.");
    return $name;
}

function kyc_delete_files(array $row): void
{
    foreach (['pan_file', 'aadhaar_file'] as $c) {
        $p = STORAGE_DIR . '/kyc/' . basename((string)($row[$c] ?? ''));
        if (!empty($row[$c]) && is_file($p)) @unlink($p);
    }
}

function valid_phone(string $p): ?string
{
    $n = preg_replace('/[\s\-()]/', '', $p);
    return preg_match('/^\+?[0-9]{10,14}$/', $n) ? $n : null;
}
