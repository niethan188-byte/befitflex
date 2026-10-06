<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'check_in') {
        if (scalar('SELECT COUNT(*) FROM attendance WHERE member_id = ? AND check_out_time IS NULL', [$mid])) {
            flash('You are already checked in.', 'err');
        } else {
            $aid = next_id('attendance', 'attendance_id', 'ATT', 6);
            db()->prepare('INSERT INTO attendance (attendance_id,member_id,check_in_time,attendance_date)
                           VALUES (?,?,NOW(),CURDATE())')->execute([$aid, $mid]);
            log_activity('Self check-in', 'Attendance', $mid);
            flash('Checked in. Have a good session.');
        }
    }

    if ($act === 'check_out') {
        db()->prepare('UPDATE attendance SET check_out_time = NOW()
                        WHERE member_id = ? AND check_out_time IS NULL')->execute([$mid]);
        flash('Checked out. See you next time.');
    }

    redirect('attendance.php');
}

$page = 'attendance';
$title = 'My Attendance';
$subtitle = 'Every visit, and how long you stayed.';

$open = row('SELECT * FROM attendance WHERE member_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1', [$mid]);
$all  = rows('SELECT * FROM attendance WHERE member_id = ? ORDER BY check_in_time DESC LIMIT 60', [$mid]);

$total = count($all);
$mins = 0; $counted = 0;
foreach ($all as $a) {
    if ($a['check_out_time']) {
        $mins += (strtotime($a['check_out_time']) - strtotime($a['check_in_time'])) / 60;
        $counted++;
    }
}
$avg = $counted ? round($mins / $counted) : 0;
$thisMonth = (int) scalar("SELECT COUNT(*) FROM attendance WHERE member_id = ? AND DATE_FORMAT(attendance_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')", [$mid]);

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-fire"></i></div>
    <div class="label">Total visits</div><div class="value"><?= $total ?></div>
    <div class="note">All time</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-calendar-week"></i></div>
    <div class="label">This month</div><div class="value"><?= $thisMonth ?></div>
    <div class="note"><?= date('F Y') ?></div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-stopwatch"></i></div>
    <div class="label">Average stay</div><div class="value"><?= $avg ?><span style="font-size:16px"> min</span></div>
    <div class="note">Across <?= $counted ?> completed visits</div></div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-door-open"></i> Check in / out</h2>
  <?php if ($open): ?>
    <p class="note" style="margin-bottom:14px">
      You checked in at <b><?= date('g:i A', strtotime($open['check_in_time'])) ?></b> today.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="check_out">
      <button class="btn red"><i class="fa-solid fa-right-from-bracket"></i> Check out</button>
    </form>
  <?php else: ?>
    <p class="note" style="margin-bottom:14px">You are not currently checked in.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="check_in">
      <button class="btn red"><i class="fa-solid fa-right-to-bracket"></i> Check in now</button>
    </form>
  <?php endif; ?>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-clock-rotate-left"></i> Visit history</h2>
  <?php if (!$all): ?>
    <div class="empty"><i class="fa-solid fa-door-closed"></i>No visits recorded yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Day</th><th>In</th><th>Out</th><th>Duration</th></tr></thead>
    <tbody>
    <?php foreach ($all as $a):
      $d = $a['check_out_time'] ? round((strtotime($a['check_out_time']) - strtotime($a['check_in_time'])) / 60) : null; ?>
      <tr>
        <td><?= dt($a['attendance_date']) ?></td>
        <td class="mono"><?= date('l', strtotime($a['attendance_date'])) ?></td>
        <td><?= date('g:i A', strtotime($a['check_in_time'])) ?></td>
        <td><?= $a['check_out_time'] ? date('g:i A', strtotime($a['check_out_time'])) : '<span class="chip warn">open</span>' ?></td>
        <td><?= $d !== null ? $d . ' min' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
