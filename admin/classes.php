<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $cid = next_id('classes', 'class_id', 'CLS', 4);
        db()->prepare(
            'INSERT INTO classes (class_id,class_name,class_description,schedule_day,
                                  start_time,end_time,max_capacity,class_status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$cid, $_POST['class_name'], $_POST['class_description'],
                    $_POST['schedule_day'], $_POST['start_time'], $_POST['end_time'],
                    (int) $_POST['max_capacity'], $_POST['class_status']]);
        log_activity('Create class', 'Classes', $cid);
        flash('Class ' . $cid . ' created.');
    }

    if ($act === 'update') {
        db()->prepare(
            'UPDATE classes SET class_name=?, class_description=?, schedule_day=?,
                    start_time=?, end_time=?, max_capacity=?, class_status=? WHERE class_id=?'
        )->execute([$_POST['class_name'], $_POST['class_description'],
                    $_POST['schedule_day'], $_POST['start_time'], $_POST['end_time'],
                    (int) $_POST['max_capacity'], $_POST['class_status'], $_POST['class_id']]);
        log_activity('Update class', 'Classes', (string) $_POST['class_id']);
        flash('Class updated.');
    }

    if ($act === 'delete') {
        db()->prepare('DELETE FROM classes WHERE class_id = ?')->execute([$_POST['class_id']]);
        log_activity('Delete class', 'Classes', (string) $_POST['class_id']);
        flash('Class deleted.');
    }

    redirect('classes.php');
}

$page = 'classes';
$title = 'Classes';
$subtitle = 'Weekly group schedule and capacity.';

$classes = rows(
    'SELECT c.*,
            (SELECT COUNT(DISTINCT member_id) FROM class_attendance ca WHERE ca.class_id = c.class_id) AS enrolled
       FROM classes c
      ORDER BY FIELD(c.schedule_day,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), c.start_time'
);
$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-people-group"></i> <?= count($classes) ?> class<?= count($classes) === 1 ? '' : 'es' ?>
    <span class="spacer"></span>
    <button class="btn red sm" data-shortcut="new" onclick="newClass()"><i class="fa-solid fa-plus"></i> Add class</button>
  </h2>

  <div class="grid c3">
    <?php foreach ($classes as $c):
      $pct = $c['max_capacity'] ? min(100, round($c['enrolled'] / $c['max_capacity'] * 100)) : 0; ?>
      <div class="glass hover" style="padding:20px">
        <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:6px">
          <b style="font-size:15px"><?= e($c['class_name']) ?></b>
          <?= badge($c['class_status']) ?>
        </div>
        <div class="mono" style="margin-bottom:12px"><?= e($c['class_id']) ?></div>
        <p class="note" style="margin-bottom:14px;min-height:38px"><?= e($c['class_description'] ?: '—') ?></p>

        <table class="kv" style="width:100%;font-size:13px">
          <tr><th>Day</th><td><?= e($c['schedule_day']) ?></td></tr>
          <tr><th>Time</th><td><?= timeh($c['start_time']) ?> – <?= timeh($c['end_time']) ?></td></tr>
        </table>

        <div style="margin-top:14px">
          <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:6px">
            <span><?= (int) $c['enrolled'] ?> enrolled</span><span>cap <?= (int) $c['max_capacity'] ?></span>
          </div>
          <div class="bar"><span style="width:<?= $pct ?>%"></span></div>
        </div>

        <div style="display:flex;gap:8px;margin-top:16px">
          <button class="btn sm" onclick='editClass(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
            <i class="fa-solid fa-pen"></i> Edit</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this class and its attendance records?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="class_id" value="<?= e($c['class_id']) ?>">
            <button class="btn sm danger"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$classes): ?><div class="empty"><i class="fa-solid fa-people-group"></i>No classes scheduled.</div><?php endif; ?>
</div>

<div class="modal" id="mClass">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-people-group"></i> <span id="cTitle">Add class</span></h3>
    <form method="post" id="fClass">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="cAction" value="create">
      <input type="hidden" name="class_id" id="cClassId">

      <div class="field-row"><label>Class name</label><input type="text" name="class_name" required></div>
      <div class="field-row"><label>Description</label><textarea name="class_description" rows="2"></textarea></div>

      <div class="form-grid">
        <div class="field-row"><label>Day</label>
          <select name="schedule_day" required>
            <?php foreach ($days as $d): ?><option><?= $d ?></option><?php endforeach; ?>
          </select></div>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Start</label><input type="time" name="start_time" required value="06:00"></div>
        <div class="field-row"><label>End</label><input type="time" name="end_time" required value="07:00"></div>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Max capacity</label>
          <input type="number" name="max_capacity" min="1" max="200" value="20" required></div>
        <div class="field-row"><label>Status</label>
          <select name="class_status"><option>Active</option><option>Inactive</option><option>Cancelled</option></select></div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mClass')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Save class</button>
      </div>
    </form>
  </div>
</div>

<script>
function newClass() {
  document.getElementById('fClass').reset();
  document.getElementById('cTitle').textContent = 'Add class';
  document.getElementById('cAction').value = 'create';
  openModal('mClass');
}
function editClass(c) {
  document.getElementById('cTitle').textContent = 'Edit ' + c.class_name;
  document.getElementById('cAction').value = 'update';
  document.getElementById('cClassId').value = c.class_id;
  fillForm('fClass', {
    class_name: c.class_name, class_description: c.class_description || '',
    schedule_day: c.schedule_day,
    start_time: c.start_time, end_time: c.end_time,
    max_capacity: c.max_capacity, class_status: c.class_status
  });
  openModal('mClass');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
