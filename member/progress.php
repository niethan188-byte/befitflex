<?php
declare(strict_types=1);
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$memberId = (string) ($_SESSION['member_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'record') {
        $checkDate = trim((string) ($_POST['check_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $checkDate);
        $dateIsValid = $date !== false && $date->format('Y-m-d') === $checkDate
            && $checkDate <= date('Y-m-d');
        $limits = [
            'weight_kg' => [20, 500],
            'body_fat_percent' => [1, 75],
            'waist_cm' => [20, 300],
            'hips_cm' => [20, 300],
        ];
        $values = [];
        $valid = $dateIsValid;
        foreach ($limits as $field => [$minimum, $maximum]) {
            $raw = trim((string) ($_POST[$field] ?? ''));
            if ($raw === '') {
                $values[$field] = null;
            } elseif (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $raw)
                || (float) $raw < $minimum || (float) $raw > $maximum) {
                $valid = false;
            } else {
                $values[$field] = $raw;
            }
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if (strlen($notes) > 4000 || !array_filter($values, static fn($value) => $value !== null)) {
            $valid = false;
        }

        if (!$valid) {
            flash('Enter a valid past or current date and at least one measurement within the listed ranges.', 'err');
        } else {
            db()->prepare(
                'INSERT INTO member_progress
                    (member_id, recorded_by_user_id, check_date, weight_kg, body_fat_percent, waist_cm, hips_cm, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $memberId, $u['id'], $checkDate, $values['weight_kg'], $values['body_fat_percent'],
                $values['waist_cm'], $values['hips_cm'], $notes !== '' ? $notes : null,
            ]);
            log_activity('Record progress check', 'Member Progress', $memberId . ' · ' . $checkDate);
            flash('Progress check saved.');
        }
    } elseif ($action === 'goal') {
        $targetWeight = trim((string) ($_POST['target_weight_kg'] ?? ''));
        $targetDate = trim((string) ($_POST['target_date'] ?? ''));
        if ($targetWeight === '' && $targetDate === '') {
            db()->prepare('DELETE FROM member_progress_goals WHERE member_id = ?')->execute([$memberId]);
            log_activity('Clear progress goal', 'Member Progress', $memberId);
            flash('Progress goal cleared.');
        } else {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $targetDate);
            $dateIsValid = $date !== false && $date->format('Y-m-d') === $targetDate
                && $targetDate >= date('Y-m-d');
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $targetWeight)
                || (float) $targetWeight < 20 || (float) $targetWeight > 500 || !$dateIsValid) {
                flash('Enter a target weight from 20 to 500 kg and a target date today or later.', 'err');
            } else {
                db()->prepare(
                    'INSERT INTO member_progress_goals (member_id, target_weight_kg, target_date, updated_by_user_id)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE target_weight_kg = VALUES(target_weight_kg),
                        target_date = VALUES(target_date), updated_by_user_id = VALUES(updated_by_user_id)'
                )->execute([$memberId, $targetWeight, $targetDate, $u['id']]);
                log_activity('Update progress goal', 'Member Progress', $memberId);
                flash('Progress goal saved.');
            }
        }
    }
    redirect('progress.php');
}

$page = 'progress';
$title = 'My Progress';
$subtitle = 'Track your fitness check-ins and target weight.';
$progress = rows(
    'SELECT * FROM member_progress WHERE member_id = ? ORDER BY check_date DESC, progress_id DESC LIMIT 100',
    [$memberId]
);
$goal = row('SELECT * FROM member_progress_goals WHERE member_id = ?', [$memberId]);
$previousWeight = null;
foreach ($progress as $check) {
    if ($check['weight_kg'] !== null) {
        if ($previousWeight === null) {
            $latestWeight = (float) $check['weight_kg'];
            $previousWeight = false;
        } else {
            $previousWeight = (float) $check['weight_kg'];
            break;
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-bullseye"></i> My target</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="goal">
      <div class="form-grid">
        <div class="field-row"><label for="targetWeight">Target weight (kg)</label>
          <input id="targetWeight" name="target_weight_kg" type="number" min="20" max="500" step="0.01"
                 value="<?= e($goal['target_weight_kg'] ?? '') ?>" placeholder="e.g. 68.00"></div>
        <div class="field-row"><label for="targetDate">Target date</label>
          <input id="targetDate" name="target_date" type="date" min="<?= date('Y-m-d') ?>"
                 value="<?= e($goal['target_date'] ?? '') ?>"></div>
      </div>
      <p class="note">Leave both fields empty to clear your goal.</p>
      <button class="btn red"><i class="fa-solid fa-check"></i> Save goal</button>
    </form>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-clipboard-check"></i> New check-in</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="record">
      <div class="field-row"><label for="checkDate">Check date</label>
        <input id="checkDate" name="check_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
      <div class="form-grid">
        <div class="field-row"><label for="weight">Weight (kg)</label><input id="weight" name="weight_kg" type="number" min="20" max="500" step="0.01"></div>
        <div class="field-row"><label for="bodyFat">Body fat (%)</label><input id="bodyFat" name="body_fat_percent" type="number" min="1" max="75" step="0.01"></div>
        <div class="field-row"><label for="waist">Waist (cm)</label><input id="waist" name="waist_cm" type="number" min="20" max="300" step="0.01"></div>
        <div class="field-row"><label for="hips">Hips (cm)</label><input id="hips" name="hips_cm" type="number" min="20" max="300" step="0.01"></div>
      </div>
      <div class="field-row"><label for="progressNotes">Notes (optional)</label>
        <textarea id="progressNotes" name="notes" maxlength="1000" rows="2"></textarea></div>
      <p class="note">Enter at least one measurement. Only you and authorized administrators can view your progress.</p>
      <button class="btn red"><i class="fa-solid fa-check"></i> Save check-in</button>
    </form>
  </div>
</div>

<?php if ($goal): ?>
<div class="panel glass">
  <h2><i class="fa-solid fa-flag-checkered"></i> Goal progress
    <span class="spacer"></span><span class="chip">Target <?= e($goal['target_weight_kg']) ?> kg · <?= dt($goal['target_date']) ?></span></h2>
  <?php if (isset($latestWeight)): ?>
    <p class="note">Latest recorded weight: <b><?= e(number_format($latestWeight, 2)) ?> kg</b>
      · <?= e(number_format(abs($latestWeight - (float) $goal['target_weight_kg']), 2)) ?> kg from your target
      <?php if (is_float($previousWeight)): ?>
        · <?= e(number_format($latestWeight - $previousWeight, 2)) ?> kg since the previous check-in
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p class="note">Record your weight to see progress toward your target.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel glass">
  <h2><i class="fa-solid fa-chart-line"></i> Check-in history</h2>
  <?php if (!$progress): ?>
    <div class="empty"><i class="fa-solid fa-chart-line"></i>No fitness check-ins yet. Save your first check-in above.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Weight</th><th>Body fat</th><th>Waist</th><th>Hips</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($progress as $check): ?>
        <tr>
          <td><?= dt($check['check_date']) ?></td>
          <td><?= $check['weight_kg'] !== null ? e($check['weight_kg']) . ' kg' : '—' ?></td>
          <td><?= $check['body_fat_percent'] !== null ? e($check['body_fat_percent']) . '%' : '—' ?></td>
          <td><?= $check['waist_cm'] !== null ? e($check['waist_cm']) . ' cm' : '—' ?></td>
          <td><?= $check['hips_cm'] !== null ? e($check['hips_cm']) . ' cm' : '—' ?></td>
          <td><?= e($check['notes'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
