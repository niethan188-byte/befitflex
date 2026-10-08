<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'check_in') {
        $open = scalar('SELECT COUNT(*) FROM attendance WHERE member_id = ? AND check_out_time IS NULL', [$_POST['member_id']]);
        if ($open) {
            flash('That member is already checked in.', 'err');
        } else {
            $aid = next_id('attendance', 'attendance_id', 'ATT', 6);
            db()->prepare(
                'INSERT INTO attendance (attendance_id,member_id,check_in_time,attendance_date)
                 VALUES (?,?,NOW(),CURDATE())'
            )->execute([$aid, $_POST['member_id']]);
            log_activity('Check in', 'Attendance', (string) $_POST['member_id']);
            flash('Checked in.');
        }
    }

    if ($act === 'check_out') {
        db()->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')
            ->execute([$_POST['attendance_id']]);
        flash('Checked out.');
    }

    redirect('attendance.php');
}

$page = 'attendance';
$title = 'Attendance';
$subtitle = 'Gym floor check-ins and class attendance.';

$today   = rows(
     'SELECT a.*, COALESCE(m.member_name, d.visitor_name) member_name, d.pass_id
       FROM attendance a LEFT JOIN members m ON m.member_id = a.member_id
       LEFT JOIN day_passes d ON d.pass_id = a.day_pass_id
      WHERE a.attendance_date = CURDATE() ORDER BY a.check_in_time DESC'
);
$history = rows(
     'SELECT a.*, COALESCE(m.member_name, d.visitor_name) member_name, d.pass_id
       FROM attendance a LEFT JOIN members m ON m.member_id = a.member_id
       LEFT JOIN day_passes d ON d.pass_id = a.day_pass_id
      WHERE a.attendance_date < CURDATE() ORDER BY a.check_in_time DESC LIMIT 40'
);
$classAtt = rows(
    'SELECT ca.*, c.class_name, m.member_name
       FROM class_attendance ca
       JOIN classes c ON c.class_id = ca.class_id
       JOIN members m ON m.member_id = ca.member_id
      ORDER BY ca.attendance_date DESC LIMIT 30'
);
$members = rows("SELECT member_id, member_name FROM members WHERE status='Active' ORDER BY member_name");
$inside  = (int) scalar('SELECT COUNT(*) FROM attendance WHERE check_out_time IS NULL AND attendance_date = CURDATE()');

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-door-open"></i></div>
    <div class="label">Check-ins today</div><div class="value"><?= count($today) ?></div>
    <div class="note"><?= $inside ?> still inside</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-calendar-week"></i></div>
    <div class="label">This week</div>
    <div class="value"><?= (int) scalar('SELECT COUNT(*) FROM attendance WHERE YEARWEEK(attendance_date,1) = YEARWEEK(CURDATE(),1)') ?></div>
    <div class="note">Total visits</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-people-group"></i></div>
    <div class="label">Class records</div>
    <div class="value"><?= (int) scalar('SELECT COUNT(*) FROM class_attendance') ?></div>
    <div class="note">All time</div></div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-clipboard-user"></i> Today · <?= date('l, M j') ?>
    <span class="spacer"></span>
    <a class="btn red sm" href="qr-attendance.php"><i class="fa-solid fa-qrcode"></i> Scan QR</a>
    <button class="btn sm" onclick="openModal('mIn')"><i class="fa-solid fa-right-to-bracket"></i> Check a member in</button>
  </h2>

  <?php if (!$today): ?>
    <div class="empty"><i class="fa-solid fa-door-closed"></i>Nobody has checked in today.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Member</th><th>In</th><th>Out</th><th>Duration</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($today as $a):
      $mins = $a['check_out_time']
        ? round((strtotime($a['check_out_time']) - strtotime($a['check_in_time'])) / 60)
        : null; ?>
      <tr>
        <td><b><?= e($a['member_name']) ?></b><div class="mono"><?= e($a['pass_id'] ?: $a['member_id']) ?></div></td>
        <td><?= date('g:i A', strtotime($a['check_in_time'])) ?></td>
        <td><?= $a['check_out_time'] ? date('g:i A', strtotime($a['check_out_time'])) : '<span class="chip warn">inside</span>' ?></td>
        <td><?= $mins !== null ? $mins . ' min' : '—' ?></td>
        <td>
          <?php if (!$a['check_out_time']): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="check_out">
              <input type="hidden" name="attendance_id" value="<?= e($a['attendance_id']) ?>">
              <button class="btn sm"><i class="fa-solid fa-right-from-bracket"></i> Check out</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent visits</h2>
    <?php if (!$history): ?><div class="empty"><i class="fa-solid fa-clock"></i>No earlier records.</div><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Member</th><th>Date</th><th>In</th><th>Out</th></tr></thead>
      <tbody>
      <?php foreach ($history as $a): ?>
        <tr>
          <td><?= e($a['member_name']) ?></td>
          <td><?= dt($a['attendance_date']) ?></td>
          <td><?= date('g:i A', strtotime($a['check_in_time'])) ?></td>
          <td><?= $a['check_out_time'] ? date('g:i A', strtotime($a['check_out_time'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-people-group"></i> Class attendance</h2>
    <?php if (!$classAtt): ?><div class="empty"><i class="fa-solid fa-people-group"></i>No class records.</div><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Class</th><th>Member</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($classAtt as $c): ?>
        <tr>
          <td><?= e($c['class_name']) ?></td>
          <td><?= e($c['member_name']) ?></td>
          <td><?= dt($c['attendance_date']) ?></td>
          <td><?= badge($c['attendance_status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="modal" id="mIn">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-right-to-bracket"></i> Check a member in</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="check_in">
      <div class="field-row"><label>Member</label>
        <select name="member_id" required>
          <option value="">— choose a member —</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= e($m['member_id']) ?>"><?= e($m['member_name']) ?> (<?= e($m['member_id']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <p class="note">The check-in time is stamped from the server clock.</p>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mIn')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Check in</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
