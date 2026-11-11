<?php
require_once __DIR__ . '/includes/bootstrap.php';
$u = user_page_start('Period Simulator', 'games.php');
$uid = (int)$u['id'];
foreach (['INR','USDT'] as $cur) q('INSERT IGNORE INTO sim_wallets (user_id,currency,balance) VALUES (?,?,?)', [$uid,$cur,1000]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $periodId=(int)($_POST['period_id']??0); $currency=$_POST['currency']??''; $direction=$_POST['direction']??''; $amount=round((float)($_POST['amount']??0),4);
        if (!in_array($currency,['INR','USDT'],true) || !in_array($direction,['UP','DOWN'],true) || $amount<=0 || $amount>100000) throw new RuntimeException('Invalid simulation entry.');
        db()->beginTransaction();
        $p=q('SELECT * FROM sim_periods WHERE id=? AND status="scheduled" AND scheduled_at>UTC_TIMESTAMP() FOR UPDATE',[$periodId])->fetch();
        if (!$p) throw new RuntimeException('This period is closed. Select a future period.');
        $w=q('SELECT balance FROM sim_wallets WHERE user_id=? AND currency=? FOR UPDATE',[$uid,$currency])->fetch();
        if (!$w || (float)$w['balance']<$amount) throw new RuntimeException('Insufficient virtual balance.');
        q('UPDATE sim_wallets SET balance=balance-? WHERE user_id=? AND currency=?',[$amount,$uid,$currency]);
        q('INSERT INTO sim_entries (user_id,period_id,currency,direction,amount) VALUES (?,?,?,?,?)',[$uid,$periodId,$currency,$direction,$amount]);
        db()->commit(); flash('ok','Simulation entry recorded. No money was paid or received.');
    } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); flash('err',$e instanceof RuntimeException?$e->getMessage():'Could not save entry.'); }
    redirect('simulation.php');
}
$periods=q('SELECT * FROM sim_periods WHERE status="scheduled" AND scheduled_at>UTC_TIMESTAMP() ORDER BY scheduled_at LIMIT 20')->fetchAll();
$wallet=q('SELECT * FROM sim_wallets WHERE user_id=? ORDER BY currency',[$uid])->fetchAll();
$history=q('SELECT e.*,p.period_no,p.result,p.status period_status FROM sim_entries e JOIN sim_periods p ON p.id=e.period_id WHERE e.user_id=? ORDER BY e.id DESC LIMIT 30',[$uid])->fetchAll();
?>
<div class="card" style="border:1px solid var(--line);margin-bottom:16px"><h1>Period Simulator</h1><div class="flash info"><b>ACADEMIC SIMULATION ONLY.</b> INR and USDT balances here are virtual credits, have no cash value, and cannot be deposited, withdrawn, transferred or converted. Entries are practice predictions, not financial trades.</div>
<div class="grid g2"><?php foreach($wallet as $w): ?><div class="stat"><div class="l">Virtual <?= e($w['currency']) ?> credits</div><div class="v"><?= e($w['currency']==='INR'?'₹':'₮') ?><?= e(number_format((float)$w['balance'],2)) ?></div></div><?php endforeach; ?></div></div>
<div class="card"><h2>Upcoming test periods</h2><?php if(!$periods): ?><p class="muted">No upcoming periods have been scheduled by the administrator.</p><?php endif; ?>
<?php foreach($periods as $p): ?><form method="post" class="card" style="margin:12px 0;background:var(--bg)"><?= csrf_field() ?><input type="hidden" name="period_id" value="<?= (int)$p['id'] ?>"><h3><?= e($p['period_no']) ?> <span class="badge info">TEST PERIOD</span></h3><p class="muted">Scheduled: <?= e(fmt_dt($p['scheduled_at'])) ?></p><div class="row"><div class="field"><label>Virtual wallet</label><select name="currency"><option>INR</option><option>USDT</option></select></div><div class="field"><label>Virtual amount</label><input name="amount" type="number" min="0.01" step="0.01" required></div><div class="field"><label>Prediction</label><select name="direction"><option>UP</option><option>DOWN</option></select></div></div><button class="btn green" type="submit">Record practice prediction</button></form><?php endforeach; ?></div>
<div class="card"><h2>My simulation history</h2><div class="tablewrap"><table class="rt"><tr><th>Period</th><th>Pick</th><th>Virtual stake</th><th>Test result</th><th>Status</th></tr><?php foreach($history as $h): ?><tr><td><?= e($h['period_no']) ?></td><td><?= e($h['direction']) ?></td><td><?= e($h['currency'].' '.number_format((float)$h['amount'],2)) ?></td><td><?= e($h['result']??'Pending') ?></td><td><?= e($h['status']) ?></td></tr><?php endforeach; if(!$history): ?><tr><td colspan="5" class="muted">No practice entries yet.</td></tr><?php endif; ?></table></div></div>
<?php user_page_end();