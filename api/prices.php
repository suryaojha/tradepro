<?php
// Public live prices (landing page ticker). Cached server-side, so any number of visitors costs only a few market API calls.
require_once __DIR__ . '/../includes/bootstrap.php';
session_write_close();

$out = [];
foreach (quotes_get() as $id => $qd) {
    $out[$id] = ['price' => $qd['price'], 'closed' => $qd['closed']];
}
json_out(['ok' => true, 'now' => time(), 'prices' => $out]);
