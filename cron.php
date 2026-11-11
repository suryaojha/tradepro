<?php
/**
 * Optional background job: settles trades that expired while nobody was online.
 * Browser/uptime monitor: /cron.php?key=<cron_key from Admin > Settings>      CLI: php cron.php
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string)setting('cron_key', ''), $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    session_write_close();
    header('Content-Type: text/plain');
}

$before = (int)q('SELECT COUNT(*) FROM trades WHERE status = "open"')->fetchColumn();
quotes_get([], false);
settle_due();
if (games_on()) {
    foreach (cg_modes() as $s) cg_sync($s);     // colour game rounds
    mk_declare_due();                            // Lucky Number open / close results
}
$after = (int)q('SELECT COUNT(*) FROM trades WHERE status = "open"')->fetchColumn();
echo 'OK settled ' . max(0, $before - $after) . ", open now $after\n";
