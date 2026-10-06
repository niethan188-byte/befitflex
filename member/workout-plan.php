<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

$page = 'plan';
$title = 'My Workout Plan';
$subtitle = 'Your current fitness plan and program library.';

$plans = rows(
  'SELECT w.*, tp.template_name, tp.difficulty_level, tp.goal,
            tp.duration_weeks, tp.exercises_count, tp.equipment_required
       FROM workout_plans w
       LEFT JOIN workout_templates tp ON tp.template_id = w.template_id
      WHERE w.member_id = ? ORDER BY w.updated_at DESC', [$mid]
);
$library = rows('SELECT * FROM workout_templates WHERE is_active = 1 ORDER BY popularity_score DESC LIMIT 6');

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$plans): ?>
  <div class="panel glass">
    <div class="empty"><i class="fa-solid fa-clipboard"></i>
      No plan has been written for you yet. Speak with the front desk or a staff member to set one up.</div>
  </div>
<?php else: ?>
  <?php foreach ($plans as $i => $p): ?>
    <div class="panel glass">
      <h2><i class="fa-solid fa-list-check"></i> <?= e($p['plan_name']) ?>
        <?php if ($i === 0): ?><span class="chip ok" style="margin-left:8px">current</span><?php endif; ?>
        <span class="spacer"></span>
        <button class="btn sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
      </h2>

      <div class="mono" style="margin-bottom:14px">
        <?= e($p['workout_plan_id']) ?>
        · updated <?= dt($p['updated_at']) ?>
      </div>

      <?php if ($p['template_name']): ?>
        <div class="offers" style="margin-bottom:18px">
          <span class="offer"><?= e($p['template_name']) ?></span>
          <span class="offer"><?= e($p['difficulty_level']) ?></span>
          <span class="offer"><?= e($p['goal']) ?></span>
          <span class="offer"><?= (int) $p['duration_weeks'] ?> weeks</span>
          <span class="offer"><?= (int) $p['exercises_count'] ?> exercises</span>
          <?php if ($p['equipment_required']): ?><span class="offer"><?= e($p['equipment_required']) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="grid c2">
        <div>
          <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:8px">
            Weekly schedule</div>
          <div class="glass" style="padding:16px 18px">
            <p style="white-space:pre-line;font-size:14px;line-height:1.8"><?= e(str_replace('\\n', "\n", $p['weekly_schedule'])) ?></p>
          </div>
        </div>
        <div>
          <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:8px">
            What to do</div>
          <div class="glass" style="padding:16px 18px">
            <p style="white-space:pre-line;font-size:14px;line-height:1.8"><?= e($p['plan_details']) ?></p>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="panel glass">
  <h2><i class="fa-solid fa-book-open"></i> Program library</h2>
  <p class="note" style="margin-bottom:16px">Choose a plan from the library or ask staff to customize one for your goals.</p>
  <div class="grid c3">
    <?php foreach ($library as $t): ?>
      <div class="glass hover" style="padding:18px">
        <b style="font-size:14px"><?= e($t['template_name']) ?></b>
        <div class="mono" style="margin:4px 0 10px"><?= e($t['difficulty_level']) ?> · <?= (int) $t['duration_weeks'] ?> weeks</div>
        <p class="note"><?= e($t['description']) ?></p>
        <div class="offers" style="margin-top:12px"><span class="offer"><?= e($t['goal']) ?></span></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
