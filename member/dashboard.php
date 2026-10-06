<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

$page = 'dashboard';
$title = 'Welcome back, ' . explode(' ', (string) $u['name'])[0];
$subtitle = 'Your training, dues and bookings.';

$me = row('SELECT m.* FROM members m WHERE m.member_id = ?', [$mid]);

$visits    = (int) scalar('SELECT COUNT(*) FROM attendance WHERE member_id = ?', [$mid]);
$thisMonth = (int) scalar("SELECT COUNT(*) FROM attendance WHERE member_id = ? AND DATE_FORMAT(attendance_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')", [$mid]);
$owed      = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE member_id = ? AND payment_status <> 'Paid'", [$mid]);
$paid      = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE member_id = ? AND payment_status = 'Paid'", [$mid]);

$openVisit = row('SELECT * FROM attendance WHERE member_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1', [$mid]);

$plan = row('SELECT w.* FROM workout_plans w WHERE w.member_id = ? ORDER BY w.updated_at DESC LIMIT 1', [$mid]);

$upcoming = rows(
    "SELECT s.* FROM sessions s
      WHERE s.member_id = ? AND s.session_status = 'Scheduled' AND s.session_date >= CURDATE()
      ORDER BY s.session_date, s.session_time LIMIT 4", [$mid]
);
$bookings = rows(
    "SELECT r.* FROM reservations r
      WHERE r.member_id = ? AND r.status = 'Confirmed' AND r.reservation_date >= CURDATE()
      ORDER BY r.reservation_date LIMIT 4", [$mid]
);
$recentVisits = rows('SELECT * FROM attendance WHERE member_id = ? ORDER BY check_in_time DESC LIMIT 5', [$mid]);
$notes = rows('SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 4', [$u['id']]);

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-id-card"></i></div>
    <div class="label">Membership</div>
    <div class="value" style="font-size:24px"><?= e($me['membership_type'] ?? '—') ?></div>
    <div class="note"><?= badge($me['status'] ?? '') ?> · <a href="id-card.php" style="color:var(--red-hot)">member card</a></div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-fire"></i></div>
    <div class="label">Gym visits</div><div class="value"><?= $visits ?></div>
    <div class="note"><?= $thisMonth ?> this month · <a href="progress.php" style="color:var(--red-hot)">progress</a></div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-peso-sign"></i></div>
    <div class="label">Balance</div>
    <div class="value" style="font-size:25px;color:<?= $owed > 0 ? '#FBBF24' : '#4ADE80' ?>"><?= money($owed) ?></div>
    <div class="note"><?= money($paid) ?> paid to date</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-user-check"></i></div>
    <div class="label">Member status</div>
    <div class="value" style="font-size:19px"><?= e($me['status'] ?? 'Active') ?></div>
    <div class="note"><?= e($me['membership_type'] ?? 'Membership') ?></div></div>
</div>

<?php if ($openVisit): ?>
  <div class="panel glass" style="border-color:rgba(34,197,94,.4)">
    <h2><i class="fa-solid fa-door-open"></i> You are checked in</h2>
    <p class="note">Since <?= date('g:i A', strtotime($openVisit['check_in_time'])) ?> today. Have a good session.</p>
  </div>
<?php endif; ?>

<?php if ($owed > 0): ?>
  <div class="panel glass" style="border-color:rgba(245,158,11,.4)">
    <h2><i class="fa-solid fa-triangle-exclamation"></i> Outstanding balance
      <span class="spacer"></span><a class="btn sm" href="payments.php">View payments</a></h2>
    <p class="note">You have <?= money($owed) ?> unsettled. Settle at the front desk or through GCash.</p>
  </div>
<?php endif; ?>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-list-check"></i> Current plan
      <span class="spacer"></span><a class="btn sm" href="workout-plan.php">Open</a></h2>
    <?php if (!$plan): ?>
      <div class="empty"><i class="fa-solid fa-clipboard"></i>No plan yet. Your next plan will appear here.</div>
    <?php else: ?>
      <b style="font-size:15px"><?= e($plan['plan_name']) ?></b>
      <div class="mono" style="margin:4px 0 12px">Updated <?= dt($plan['updated_at']) ?></div>
      <p class="note" style="white-space:pre-line"><?= e(str_replace('\\n', "\n", $plan['weekly_schedule'])) ?></p>
    <?php endif; ?>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-calendar-day"></i> Coming up
      <span class="spacer"></span><a class="btn sm" href="reservations.php">View bookings</a></h2>
    <?php if (!$upcoming && !$bookings): ?>
      <div class="empty"><i class="fa-solid fa-calendar"></i>Nothing scheduled.</div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($upcoming as $s): ?>
          <div class="tl-item">
            <div style="font-size:14px"><b><?= e($s['session_name']) ?></b></div>
            <div class="note">Member session</div>
            <div class="when"><?= dt($s['session_date']) ?> · <?= timeh($s['session_time']) ?></div>
          </div>
        <?php endforeach; ?>
        <?php foreach ($bookings as $b): ?>
          <div class="tl-item">
            <div style="font-size:14px"><b>Reservation</b></div>
            <div class="note">Confirmed</div>
            <div class="when"><?= dt($b['reservation_date']) ?> · <?= timeh($b['reservation_time']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-clipboard-user"></i> Recent visits
      <span class="spacer"></span><a class="btn sm" href="attendance.php">History</a></h2>
    <?php if (!$recentVisits): ?>
      <div class="empty"><i class="fa-solid fa-door-closed"></i>No visits recorded.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>In</th><th>Out</th><th>Duration</th></tr></thead>
      <tbody>
      <?php foreach ($recentVisits as $a):
        $mins = $a['check_out_time'] ? round((strtotime($a['check_out_time']) - strtotime($a['check_in_time'])) / 60) : null; ?>
        <tr><td><?= dt($a['attendance_date']) ?></td>
            <td><?= date('g:i A', strtotime($a['check_in_time'])) ?></td>
            <td><?= $a['check_out_time'] ? date('g:i A', strtotime($a['check_out_time'])) : '—' ?></td>
            <td><?= $mins !== null ? $mins . ' min' : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-bell"></i> Unread notifications
      <span class="spacer"></span><a class="btn sm" href="notifications.php">All</a></h2>
    <?php if (!$notes): ?>
      <div class="empty"><i class="fa-solid fa-bell-slash"></i>You are all caught up.</div>
    <?php else: ?>
      <?php foreach ($notes as $n): ?>
        <div class="notif">
          <div class="dot"><i class="fa-solid fa-<?= e($n['notification_icon'] ?: 'bell') ?>"></i></div>
          <div class="body"><b><?= e($n['notification_title']) ?></b>
            <p><?= e($n['notification_message']) ?></p>
            <div class="when"><?= dt($n['created_at'], 'M j · g:i A') ?></div></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
