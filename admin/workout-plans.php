<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $pdo = db();

    if (in_array($action, ['create', 'update'], true)) {
        $planId = trim((string) ($_POST['workout_plan_id'] ?? ''));
        $memberId = trim((string) ($_POST['member_id'] ?? ''));
        $templateId = trim((string) ($_POST['template_id'] ?? ''));
        $planName = trim((string) ($_POST['plan_name'] ?? ''));
        $weeklySchedule = trim((string) ($_POST['weekly_schedule'] ?? ''));
        $planDetails = trim((string) ($_POST['plan_details'] ?? ''));
        $member = row('SELECT user_id FROM members WHERE member_id = ?', [$memberId]);
        $previousPlan = $action === 'update'
            ? row(
                'SELECT w.member_id, m.user_id
                   FROM workout_plans w
                   JOIN members m ON m.member_id = w.member_id
                  WHERE w.workout_plan_id = ?',
                [$planId]
            )
            : null;
        $templateExists = $templateId === ''
            || (bool) scalar('SELECT COUNT(*) FROM workout_templates WHERE template_id = ?', [$templateId]);

        if ($action === 'update' && !$previousPlan) {
            flash('Workout plan not found.', 'err');
            redirect('workout-plans.php');
        }
        if (!$member || !$templateExists || $planName === ''
            || $weeklySchedule === '' || $planDetails === '') {
            flash('Choose a valid member and complete all required workout-plan fields.', 'err');
            redirect('workout-plans.php');
        }

        if ($action === 'create') {
            $planId = next_id('workout_plans', 'workout_plan_id', 'WP', 5);
            $pdo->prepare(
                'INSERT INTO workout_plans
                    (workout_plan_id, template_id, member_id, plan_name, weekly_schedule, plan_details)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$planId, $templateId ?: null, $memberId, $planName, $weeklySchedule, $planDetails]);
            log_activity('Create workout plan', 'Workout Plans', $planId . ' — ' . $memberId);
            flash('Workout plan assigned to the member.');
        } else {
            $updated = $pdo->prepare(
                'UPDATE workout_plans
                    SET template_id = ?, member_id = ?, plan_name = ?, weekly_schedule = ?, plan_details = ?
                  WHERE workout_plan_id = ?'
            );
            $updated->execute([$templateId ?: null, $memberId, $planName, $weeklySchedule, $planDetails, $planId]);
            log_activity('Update workout plan', 'Workout Plans', $planId . ' — ' . $memberId);
            flash('Workout plan updated.');
        }

        notify(
            (int) $member['user_id'],
            'system',
            'Workout plan updated',
            'Your workout plan "' . $planName . '" is ready to view.',
            'dumbbell',
            'primary',
            'member/workout-plan.php'
        );
        if ($previousPlan && $previousPlan['member_id'] !== $memberId) {
            notify(
                (int) $previousPlan['user_id'],
                'system',
                'Workout plan reassigned',
                'The workout plan "' . $planName . '" is no longer assigned to your account.',
                'dumbbell',
                'warning',
                'member/workout-plan.php'
            );
        }
    } elseif ($action === 'delete') {
        $planId = trim((string) ($_POST['workout_plan_id'] ?? ''));
        $plan = row(
            'SELECT w.plan_name, m.user_id
               FROM workout_plans w
               JOIN members m ON m.member_id = w.member_id
              WHERE w.workout_plan_id = ?',
            [$planId]
        );
        if ($plan) {
            $pdo->prepare('DELETE FROM workout_plans WHERE workout_plan_id = ?')->execute([$planId]);
            log_activity('Delete workout plan', 'Workout Plans', $planId);
            notify(
                (int) $plan['user_id'],
                'system',
                'Workout plan removed',
                'The workout plan "' . $plan['plan_name'] . '" is no longer assigned to your account.',
                'dumbbell',
                'warning',
                'member/workout-plan.php'
            );
            flash('Workout plan removed.');
        } else {
            flash('Workout plan not found.', 'err');
        }
    }

    redirect('workout-plans.php');
}

$page = 'workout-plans';
$title = 'Workout Plans';
$subtitle = 'Assign and maintain member workout programs.';
$members = rows('SELECT member_id, member_name FROM members ORDER BY member_name');
$templates = rows('SELECT template_id, template_name, is_active FROM workout_templates ORDER BY template_name');
$plans = rows(
    'SELECT w.*, m.member_name, t.template_name
       FROM workout_plans w
       JOIN members m ON m.member_id = w.member_id
       LEFT JOIN workout_templates t ON t.template_id = w.template_id
      ORDER BY w.updated_at DESC, w.created_at DESC'
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-dumbbell"></i> <?= count($plans) ?> workout plan<?= count($plans) === 1 ? '' : 's' ?>
    <span class="spacer"></span>
    <input type="search" placeholder="Filter…" data-filter-for="tblPlans" style="width:200px">
    <button class="btn red sm" onclick="newPlan()"><i class="fa-solid fa-plus"></i> Assign plan</button>
  </h2>

  <?php if (!$plans): ?>
    <div class="empty"><i class="fa-solid fa-dumbbell"></i>No workout plans assigned yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table id="tblPlans">
    <thead><tr><th>Plan</th><th>Member</th><th>Template</th><th>Updated</th><th data-nosort></th></tr></thead>
    <tbody>
    <?php foreach ($plans as $plan): ?>
      <tr>
        <td><b><?= e($plan['plan_name']) ?></b>
          <div class="note"><?= e($plan['weekly_schedule']) ?></div></td>
        <td><?= e($plan['member_name']) ?><div class="mono"><?= e($plan['member_id']) ?></div></td>
        <td><?= e($plan['template_name'] ?? 'Custom plan') ?></td>
        <td><?= dt($plan['updated_at']) ?></td>
        <td style="white-space:nowrap">
          <button class="btn sm icon" title="Edit plan"
                  onclick='editPlan(<?= json_encode($plan, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
            <i class="fa-solid fa-pen"></i></button>
          <form method="post" style="display:inline" onsubmit="return confirm('Remove this workout plan from the member?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="workout_plan_id" value="<?= e($plan['workout_plan_id']) ?>">
            <button class="btn sm icon danger" title="Delete plan"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mPlan">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-dumbbell"></i> <span id="pTitle">Assign workout plan</span></h3>
    <form method="post" id="fPlan">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="pAction" value="create">
      <input type="hidden" name="workout_plan_id" id="pId">
      <div class="field-row"><label>Member</label>
        <select name="member_id" required>
          <option value="">Select a member</option>
          <?php foreach ($members as $member): ?>
            <option value="<?= e($member['member_id']) ?>"><?= e($member['member_name']) ?> · <?= e($member['member_id']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid">
        <div class="field-row"><label>Workout template (optional)</label>
          <select name="template_id">
            <option value="">Custom plan</option>
            <?php foreach ($templates as $template): ?>
              <option value="<?= e($template['template_id']) ?>">
                <?= e($template['template_name']) ?><?= $template['is_active'] ? '' : ' (inactive)' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row"><label>Plan name</label><input type="text" name="plan_name" required maxlength="255"></div>
      </div>
      <div class="field-row"><label>Weekly schedule</label>
        <textarea name="weekly_schedule" rows="3" required placeholder="Mon: Lower body&#10;Wed: Upper body&#10;Fri: Full body"></textarea></div>
      <div class="field-row"><label>Plan details</label>
        <textarea name="plan_details" rows="5" required placeholder="Exercises, sets, reps, and progression notes"></textarea></div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mPlan')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Save plan</button>
      </div>
    </form>
  </div>
</div>

<script>
function newPlan() {
  document.getElementById('fPlan').reset();
  document.getElementById('pTitle').textContent = 'Assign workout plan';
  document.getElementById('pAction').value = 'create';
  document.getElementById('pId').value = '';
  openModal('mPlan');
}
function editPlan(plan) {
  document.getElementById('pTitle').textContent = 'Edit ' + plan.plan_name;
  document.getElementById('pAction').value = 'update';
  document.getElementById('pId').value = plan.workout_plan_id;
  fillForm('fPlan', {
    member_id: plan.member_id,
    template_id: plan.template_id || '',
    plan_name: plan.plan_name,
    weekly_schedule: plan.weekly_schedule,
    plan_details: plan.plan_details
  });
  openModal('mPlan');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
