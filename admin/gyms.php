<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $gid = next_id('gyms', 'gym_id', 'GYM', 3);
        db()->prepare(
            'INSERT INTO gyms (gym_id,gym_branch,gym_name,location,description,contact_number) VALUES (?,?,?,?,?,?)'
        )->execute([$gid, $_POST['gym_branch'], $_POST['gym_name'], $_POST['location'],
                    $_POST['description'], $_POST['contact_number']]);
        log_activity('Create branch', 'Branches', $gid);
        flash('Branch ' . $gid . ' added.');
    }

    if ($act === 'update') {
        db()->prepare(
            'UPDATE gyms SET gym_branch=?, gym_name=?, location=?, description=?, contact_number=? WHERE gym_id=?'
        )->execute([$_POST['gym_branch'], $_POST['gym_name'], $_POST['location'],
                    $_POST['description'], $_POST['contact_number'], $_POST['gym_id']]);
        flash('Branch updated.');
    }

    if ($act === 'delete') {
        db()->prepare('DELETE FROM gyms WHERE gym_id = ?')->execute([$_POST['gym_id']]);
        flash('Branch removed.');
    }

    redirect('gyms.php');
}

$page = 'gyms';
$title = 'Branches';
$subtitle = 'Locations and their training sessions.';

$gyms = rows(
    'SELECT g.*, (SELECT COUNT(*) FROM training_sessions ts WHERE ts.gym_id = g.gym_id) AS session_count
       FROM gyms g ORDER BY g.gym_id'
);
$ts = rows(
  'SELECT ts.*, g.gym_branch
     FROM training_sessions ts
     JOIN gyms g ON g.gym_id = ts.gym_id
    ORDER BY ts.session_date DESC LIMIT 20'
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-location-dot"></i> <?= count($gyms) ?> branch<?= count($gyms) === 1 ? '' : 'es' ?>
    <span class="spacer"></span>
    <button class="btn red sm" onclick="newGym()"><i class="fa-solid fa-plus"></i> Add branch</button>
  </h2>

  <div class="grid c2">
    <?php foreach ($gyms as $g): ?>
      <div class="glass hover" style="padding:22px">
        <b style="font-size:16px"><?= e($g['gym_name']) ?></b>
        <div class="mono" style="margin:4px 0 12px"><?= e($g['gym_id']) ?> · <?= e($g['gym_branch']) ?></div>
        <p class="note" style="margin-bottom:14px"><?= e($g['description']) ?></p>
        <table class="kv" style="font-size:13px">
          <tr><th>Address</th><td><?= e($g['location']) ?></td></tr>
          <tr><th>Contact</th><td><?= e($g['contact_number']) ?></td></tr>
          <tr><th>Sessions</th><td><?= (int) $g['session_count'] ?> booked here</td></tr>
        </table>
        <div style="display:flex;gap:8px;margin-top:16px">
          <button class="btn sm" onclick='editGym(<?= json_encode($g, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
            <i class="fa-solid fa-pen"></i> Edit</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Remove this branch and its training sessions?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="gym_id" value="<?= e($g['gym_id']) ?>">
            <button class="btn sm danger"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-calendar-week"></i> Group training sessions</h2>
  <?php if (!$ts): ?><div class="empty"><i class="fa-solid fa-calendar-xmark"></i>None scheduled.</div><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Session</th><th>Branch</th><th>When</th><th>Duration</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($ts as $s): ?>
      <tr>
        <td><b><?= e($s['session_name']) ?></b><div class="note"><?= e($s['description']) ?></div></td>
        <td><?= e($s['gym_branch']) ?></td>
        <td><?= dt($s['session_date']) ?><div class="mono"><?= timeh($s['session_time']) ?></div></td>
        <td><?= (int) $s['duration'] ?> min</td>
        <td><?= badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mGym">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-location-dot"></i> <span id="gTitle">Add branch</span></h3>
    <form method="post" id="fGym">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="gAction" value="create">
      <input type="hidden" name="gym_id" id="gGymId">

      <div class="form-grid">
        <div class="field-row"><label>Branch</label>
          <input type="text" name="gym_branch" required placeholder="Santa Rosa"></div>
        <div class="field-row"><label>Contact number</label>
          <input type="tel" name="contact_number" required></div>
      </div>
      <div class="field-row"><label>Full name</label>
        <input type="text" name="gym_name" required placeholder="Be Fit Flex Gym — Santa Rosa"></div>
      <div class="field-row"><label>Location</label>
        <input type="text" name="location" placeholder="Brgy. Dita, City of Santa Rosa, Laguna"></div>
      <div class="field-row"><label>Description</label><textarea name="description" rows="2"></textarea></div>

      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mGym')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Save branch</button>
      </div>
    </form>
  </div>
</div>

<script>
function newGym() {
  document.getElementById('fGym').reset();
  document.getElementById('gTitle').textContent = 'Add branch';
  document.getElementById('gAction').value = 'create';
  openModal('mGym');
}
function editGym(g) {
  document.getElementById('gTitle').textContent = 'Edit ' + g.gym_branch;
  document.getElementById('gAction').value = 'update';
  document.getElementById('gGymId').value = g.gym_id;
  fillForm('fGym', {
    gym_branch: g.gym_branch, gym_name: g.gym_name, location: g.location || '',
    description: g.description || '', contact_number: g.contact_number
  });
  openModal('mGym');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
