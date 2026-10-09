<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
require_role('member');
$memberId = (string) ($_SESSION['member_id'] ?? '');
if ($memberId === '') {
    redirect('dashboard.php');
}

$page = 'workout-plan';
$title = 'Workout Plan';
$subtitle = 'Your current training programs, assigned by the gym.';
$plans = rows(
    'SELECT w.*, t.template_name, t.template_type, t.difficulty_level, t.goal
       FROM workout_plans w
       LEFT JOIN workout_templates t ON t.template_id = w.template_id
      WHERE w.member_id = ?
      ORDER BY w.updated_at DESC, w.created_at DESC',
    [$memberId]
);

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$plans): ?>
  <div class="panel glass">
    <div class="empty"><i class="fa-solid fa-dumbbell"></i>No workout plan has been assigned yet.</div>
  </div>
<?php else: ?>
  <?php foreach ($plans as $plan): ?>
    <section class="panel glass">
      <h2><i class="fa-solid fa-dumbbell"></i> <?= e($plan['plan_name']) ?></h2>
      <div class="offers" style="margin:12px 0">
        <?php if ($plan['template_name']): ?>
          <span class="offer"><?= e($plan['template_name']) ?></span>
          <span class="offer"><?= e($plan['difficulty_level']) ?></span>
          <span class="offer"><?= e($plan['goal']) ?></span>
        <?php endif; ?>
        <span class="offer">Updated <?= dt($plan['updated_at']) ?></span>
      </div>
      <div class="grid c2">
        <div>
          <h3>Weekly Schedule</h3>
          <p><?= nl2br(e($plan['weekly_schedule'])) ?></p>
        </div>
        <div>
          <h3>Plan Details</h3>
          <p><?= nl2br(e($plan['plan_details'])) ?></p>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
