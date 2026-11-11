<?php
// Colour game API: round state (polled) and placing bets. Demo coins only.
require_once __DIR__ . '/../includes/bootstrap.php';

$u = require_user();
if (!cg_on()) json_out(['ok' => false, 'error' => 'The colour game is switched off.'], 403);
if (!games_unlocked()) json_out(['ok' => false, 'error' => 'Enter your PIN to play.', 'pin' => true], 403);

$secs = (int)($_REQUEST['secs'] ?? 0);
if (!in_array($secs, cg_modes(), true)) $secs = cg_modes()[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $r = cg_place($u, $secs, (string)($_POST['kind'] ?? ''), (string)($_POST['pick'] ?? ''), (float)($_POST['amount'] ?? 0));
        json_out(['ok' => true, 'placed' => $r, 'state' => cg_state($secs, (int)$u['id'])]);
    } catch (RuntimeException $ex) {
        json_out(['ok' => false, 'error' => $ex->getMessage()], 422);
    } catch (Throwable $ex) {
        error_log('cg: ' . $ex->getMessage());
        json_out(['ok' => false, 'error' => 'Could not place the bet. Try again.'], 500);
    }
}

session_write_close();
$action = (string)($_GET['action'] ?? 'state');
if ($action === 'results') json_out(['ok' => true] + cg_results_page($secs, (int)($_GET['page'] ?? 1)));
if ($action === 'mine')    json_out(['ok' => true] + cg_mine_page((int)$u['id'], (int)($_GET['page'] ?? 1)));
json_out(['ok' => true, 'state' => cg_state($secs, (int)$u['id'])]);
