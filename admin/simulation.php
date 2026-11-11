<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
admin_start('Simulation Results','simulation.php');
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_require();
 try{
  $period=trim((string)($_POST['period_no']??'')); $when=trim((string)($_POST['scheduled_at']??'')); $result=$_POST['result']??'';
  if(!preg_match('/^[A-Za-z0-9_-]{3,40}$/',$period)) throw new RuntimeException('Period number must be 3–40 letters, digits, _ or -.');
  $dt=DateTime::createFromFormat('Y-m-d\\TH:i',$when,new DateTimeZone('UTC'));
  if(!$dt || $dt->getTimestamp()<=time()+60) throw new RuntimeException('Schedule the test period at least one minute in the future (UTC).');
  if(!in_array($result,['UP','DOWN'],true)) throw new RuntimeException('Choose a test result.');
  q('INSERT INTO sim_periods (period_no,scheduled_at,result,status,created_by) VALUES (?,?,?,"scheduled",?) ON DUPLICATE KEY UPDATE scheduled_at=VALUES(scheduled_at),result=VALUES(result),status="scheduled",published_at=NULL,created_by=VALUES(created_by)',[$period,$dt->format('Y-m-d H:i:s'),$result,(int)current_admin()['id']]);
  admin_log('simulation_period_scheduled',$period.' TEST '.$result); flash('ok','Test period scheduled. It is visibly labelled as simulated.');
 }catch(Throwable $e){flash('err',$e instanceof RuntimeException?$e->getMessage():'Could not save test period.');}
 redirect('admin/simulation.php');
}
$rows=q('SELECT p.*,a.name admin_name,(SELECT COUNT(*) FROM sim_entries e WHERE e.period_id=p.id) entries FROM sim_periods p LEFT JOIN admins a ON a.id=p.created_by ORDER BY p.scheduled_at DESC LIMIT 50')->fetchAll();
?>
<h1>Simulation Results</h1>
<div class="flash info"><b>TEST DATA ONLY.</b> These administrator-scheduled outcomes are simulated fixtures for classroom testing. They are not random market results and must never be presented as genuine outcomes.</div>
<form method="post" class="card"><?= csrf_field() ?><h2>Schedule a future test period</h2><div class="row"><div class="field"><label>Period number</label><input name="period_no" maxlength="40" required placeholder="DEMO-2026-001"></div><div class="field"><label>Scheduled time (UTC)</label><input type="datetime-local" name="scheduled_at" required></div><div class="field"><label>Test outcome</label><select name="result"><option>UP</option><option>DOWN</option></select></div></div><button class="btn green">Save test period</button></form>
<div class="card"><h2>Scheduled and published fixtures</h2><div class="tablewrap"><table class="rt"><tr><th>Period</th><th>Scheduled UTC</th><th>Fixture</th><th>Status</th><th>Entries</th><th>Created by</th></tr><?php foreach($rows as $r): ?><tr><td><?= e($r['period_no']) ?></td><td><?= e($r['scheduled_at']) ?></td><td><?= e($r['result']??'—') ?></td><td><?= e($r['status']) ?></td><td><?= (int)$r['entries'] ?></td><td><?= e($r['admin_name']??'Admin') ?></td></tr><?php endforeach; ?></table></div></div>
<?php admin_end();