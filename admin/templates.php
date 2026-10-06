<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $tid = next_id('workout_templates', 'template_id', 'TPL', 4);
        db()->prepare(
            'INSERT INTO workout_templates (template_id,template_name,template_type,difficulty_level,description,
                                            goal,duration_weeks,exercises_count,equipment_required,popularity_score,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,1)'
        )->execute([$tid, $_POST['template_name'], $_POST['template_type'], $_POST['difficulty_level'],
                    $_POST['description'], $_POST['goal'], (int) $_POST['duration_weeks'],
                    (int) $_POST['exercises_count'], $_POST['equipment_required'], (int) $_POST['popularity_score']]);
        log_activity('Create template', 'Templates', $tid);
        flash('Template ' . $tid . ' created.');
    }

    if ($act === 'toggle') {
        db()->prepare('UPDATE workout_templates SET is_active = 1 - is_active WHERE template_id = ?')
            ->execute([$_POST['template_id']]);
        flash('Template visibility changed.');
    }

    if ($act === 'delete') {
        db()->prepare('DELETE FROM workout_templates WHERE template_id = ?')->execute([$_POST['template_id']]);
        log_activity('Delete template', 'Templates', (string) $_POST['template_id']);
        flash('Template deleted.');
    }

    redirect('templates.php');
}

$page = 'templates';
$title = 'Workout Templates';
$subtitle = 'Reusable programs trainers build member plans from.';

$templates = rows(
    'SELECT t.*, (SELECT COUNT(*) FROM workout_plans w WHERE w.template_id = t.template_id) AS in_use
       FROM workout_templates t ORDER BY t.popularity_score DESC'
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-list-check"></i> <?= count($templates) ?> template<?= count($templates) === 1 ? '' : 's' ?>
    <span class="spacer"></span>
    <button class="btn red sm" onclick="openModal('mTpl')"><i class="fa-solid fa-plus"></i> New template</button>
  </h2>

  <div class="grid c2">
    <?php foreach ($templates as $t): ?>
      <div class="glass hover" style="padding:20px">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
          <div>
            <b style="font-size:15px"><?= e($t['template_name']) ?></b>
            <div class="mono"><?= e($t['template_id']) ?> · <?= e($t['template_type']) ?></div>
          </div>
          <?= badge($t['is_active'] ? 'Active' : 'Inactive') ?>
        </div>

        <p class="note" style="margin:12px 0 14px"><?= e($t['description']) ?></p>

        <div class="offers" style="margin-bottom:14px">
          <span class="offer"><?= e($t['difficulty_level']) ?></span>
          <span class="offer"><?= e($t['goal']) ?></span>
          <span class="offer"><?= (int) $t['duration_weeks'] ?> weeks</span>
          <span class="offer"><?= (int) $t['exercises_count'] ?> exercises</span>
        </div>

        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:6px">
          <span>Popularity</span><span><?= (int) $t['popularity_score'] ?>/100 · used by <?= (int) $t['in_use'] ?> plan<?= $t['in_use'] == 1 ? '' : 's' ?></span>
        </div>
        <div class="bar"><span style="width:<?= (int) $t['popularity_score'] ?>%"></span></div>

        <div class="note" style="margin-top:12px">Equipment: <?= e($t['equipment_required'] ?: 'none') ?></div>

        <div style="display:flex;gap:8px;margin-top:14px">
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="template_id" value="<?= e($t['template_id']) ?>">
            <button class="btn sm"><i class="fa-solid fa-eye"></i> <?= $t['is_active'] ? 'Hide' : 'Show' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this template?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="template_id" value="<?= e($t['template_id']) ?>">
            <button class="btn sm danger"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="modal" id="mTpl">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-list-check"></i> New workout template</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="field-row"><label>Template name</label><input type="text" name="template_name" required></div>

      <div class="form-grid">
        <div class="field-row"><label>Type</label>
          <select name="template_type">
            <option>Strength</option><option>Conditioning</option><option>Functional</option>
            <option>Physique</option><option>Mobility</option>
          </select></div>
        <div class="field-row"><label>Difficulty</label>
          <select name="difficulty_level">
            <option>Beginner</option><option>Intermediate</option><option>Advanced</option>
          </select></div>
      </div>

      <div class="field-row"><label>Description</label><textarea name="description" rows="3" required></textarea></div>

      <div class="form-grid">
        <div class="field-row"><label>Goal</label>
          <input type="text" name="goal" required placeholder="Fat loss"></div>
        <div class="field-row"><label>Equipment</label>
          <input type="text" name="equipment_required" placeholder="Barbell, dumbbells"></div>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Duration (weeks)</label>
          <input type="number" name="duration_weeks" min="1" max="52" value="8" required></div>
        <div class="field-row"><label>Exercises</label>
          <input type="number" name="exercises_count" min="1" max="60" value="10" required></div>
        <div class="field-row"><label>Popularity</label>
          <input type="number" name="popularity_score" min="0" max="100" value="50"></div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mTpl')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Create template</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
