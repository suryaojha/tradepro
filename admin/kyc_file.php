<?php
// Streams a KYC document to a signed-in admin only.
require_once __DIR__ . '/../includes/bootstrap.php';

require_admin();
session_write_close();
$k = q('SELECT pan_file, aadhaar_file, user_id FROM kyc WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
$col = ($_GET['f'] ?? '') === 'aadhaar' ? 'aadhaar_file' : 'pan_file';
if (!$k) { http_response_code(404); exit('Not found'); }
$path = STORAGE_DIR . '/kyc/' . basename($k[$col]);
if (!is_file($path)) { http_response_code(404); exit('File missing'); }
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
admin_log('kyc_view', 'user #' . $k['user_id'] . ' ' . $col);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="kyc-' . (int)$k['user_id'] . '-' . ($col === 'pan_file' ? 'pan' : 'aadhaar') . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
readfile($path);
