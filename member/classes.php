<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'enroll') {
        $already = scalar(
            'SELECT COUNT(*) FROM class_attendance WHERE class_id = ? AND member_id = ? AND attendance_date = ?',
            [$_POST['class_id'], $mid, $_POST['attendance_date']]
        );
        if ($already) {
            flash('You are already on the list for that date.', 'err');
        } else {
            $aid = next_id('class_attendance', 'attendance_id', 'CAT', 6);
            db()->prepare(
                'INSERT INTO class_attendance (attendance_id,class_id,member_id,enrollment_date,attendance_date,attendance_status)
                 VALUES (?,?,?,CURDATE(),?,"Present")'
            )->execute([$aid, $_POST['class_id'], $mid, $_POST['attendance_date']]);
            log_activity('Join class', 'Classes', (string) $_POST['class_id']);
            flash('You are enrolled. See you there.');
        }
    }
    redirect('classes.php');
}

$page = 'classes';
$title = 'Classes';
$subtitle = 'The weekly group schedule.';

$classes = rows(
    'SELECT c.*,
            (SELECT COUNT(DISTINCT member_id) FROM class_attendance ca WHERE ca.class_id = c.class_id) enrolled
       FROM classes c
      WHERE c.class_status = "Active"
      ORDER BY FIELD(c.schedule_day,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), c.start_time'
);
$mine = rows(
    'SELECT ca.*, c.class_name, c.schedule_day, c.start_time
       FROM class_attendance ca
       JOIN classes c ON c.class_id = ca.class_id
      WHERE ca.member_id = ? ORDER BY ca.attendance_date DESC LIMIT 20', [$mid]
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-people-group"></i> This week</h2>
  <div class="grid c3">
    <?php foreach ($classes as $c):
      $pct = $c['max_capacity'] ? min(100, round($c['enrolled'] / $c['max_capacity'] * 100)) : 0;
      $full = $c['enrolled'] >= $c['max_capacity']; ?>
      <div class="glass hover" style="padding:20px">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
          <b style="font-size:15px"><?= e($c['class_name']) ?></b>
          <?= $full ? '<span class="chip bad">full</span>' : '<span class="chip ok">open</span>' ?>
        </div>
        <div class="mono" style="margin:4px 0 12px"><?= e($c['schedule_day']) ?> · <?= timeh($c['start_time']) ?>–<?= timeh($c['end_time']) ?></div>
        <p class="note" style="min-height:38px"><?= e($c['class_description'] ?: '—') ?></p>

        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin:10px 0 6px">
          <span><?= (int) $c['enrolled'] ?> enrolled</span><span>cap <?= (int) $c['max_capacity'] ?></span>
        </div>
        <div class="bar"><span style="width:<?= $pct ?>%"></span></div>

        <?php if (!$full): ?>
          <button class="btn red sm" style="margin-top:14px;width:100%;justify-content:center"
                  onclick="joinClass('<?= e($c['class_id']) ?>','<?= e($c['class_name']) ?>','<?= e($c['schedule_day']) ?>')">
            <i class="fa-solid fa-plus"></i> Join this class</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$classes): ?><div class="empty"><i class="fa-solid fa-people-group"></i>No active classes right now.</div><?php endif; ?>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-clipboard-user"></i> My class history</h2>
  <?php if (!$mine): ?>
    <div class="empty"><i class="fa-solid fa-clipboard"></i>You have not joined a class yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Class</th><th>Date</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($mine as $m): ?>
      <tr><td><?= e($m['class_name']) ?><div class="mono"><?= e($m['schedule_day']) ?> · <?= timeh($m['start_time']) ?></div></td>
          <td><?= dt($m['attendance_date']) ?></td>
          <td><?= badge($m['attendance_status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mJoin">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-people-group"></i> Join <span id="jName"></span></h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="enroll">
      <input type="hidden" name="class_id" id="jClassId">
      <p class="note" id="jDay" style="margin-bottom:16px"></p>
      <div class="field-row"><label>Which date are you attending?</label>
        <input type="date" name="attendance_date" required value="<?= date('Y-m-d') ?>"></div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mJoin')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Confirm</button>
      </div>
    </form>
  </div>
</div>

<script>
function joinClass(id, name, day) {
  document.getElementById('jClassId').value = id;
  document.getElementById('jName').textContent = name;
  document.getElementById('jDay').textContent = 'This class runs on ' + day + '. Pick the date you plan to attend.';
  openModal('mJoin');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
