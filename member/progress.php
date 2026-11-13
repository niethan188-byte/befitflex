<?php
declare(strict_types=1);
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$memberId = (string) ($_SESSION['member_id'] ?? '');
$commonExercises = [
    'Squat', 'Bench Press', 'Deadlift', 'Overhead Press', 'Barbell Row',
    'Romanian Deadlift', 'Lat Pulldown', 'Cable Row', 'Leg Press', 'Dumbbell Press',
    'Pull-up', 'Push-up', 'Bicep Curl', 'Tricep Extension', 'Calf Raise', 'Lunge',
];
$redirectTo = 'progress.php';

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
        if (strlen($notes) > 1000 || !array_filter($values, static fn($value) => $value !== null)) {
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
    } elseif ($action === 'lifting') {
        $exerciseName = trim((string) ($_POST['exercise_name'] ?? ''));
        foreach ($commonExercises as $commonExercise) {
            if (strcasecmp($exerciseName, $commonExercise) === 0) {
                $exerciseName = $commonExercise;
                break;
            }
        }
        $loggedDate = trim((string) ($_POST['logged_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $loggedDate);
        $dateIsValid = $date !== false && $date->format('Y-m-d') === $loggedDate
            && $loggedDate <= date('Y-m-d');
        $setsRaw = trim((string) ($_POST['sets_count'] ?? ''));
        $repsRaw = trim((string) ($_POST['reps_per_set'] ?? ''));
        $weightRaw = trim((string) ($_POST['lifting_weight_kg'] ?? ''));
        $notes = trim((string) ($_POST['lifting_notes'] ?? ''));
        $setsValid = preg_match('/^\d{1,2}$/D', $setsRaw) && (int) $setsRaw >= 1 && (int) $setsRaw <= 30;
        $repsValid = preg_match('/^\d{1,3}$/D', $repsRaw) && (int) $repsRaw >= 1 && (int) $repsRaw <= 100;
        $weightValid = preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', $weightRaw)
            && (float) $weightRaw >= 0 && (float) $weightRaw <= 1000;

        if ($exerciseName === '' || strlen($exerciseName) > 100 || !$dateIsValid
            || !$setsValid || !$repsValid || !$weightValid || strlen($notes) > 500) {
            flash('Enter a valid exercise, past or current date, 1–30 sets, 1–100 reps, load from 0–1000 kg, and notes under 500 characters.', 'err');
        } else {
            db()->prepare(
                'INSERT INTO member_lifting_logs
                    (member_id, logged_by_user_id, exercise_name, logged_date, sets_count, reps_per_set, weight_kg, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $memberId, $u['id'], $exerciseName, $loggedDate, (int) $setsRaw,
                (int) $repsRaw, $weightRaw, $notes !== '' ? $notes : null,
            ]);
            log_activity('Log lifting progress', 'Member Progress', $memberId . ' · ' . $exerciseName . ' · ' . $loggedDate);
            flash('Strength workout saved.');
            $redirectTo .= '?exercise=' . rawurlencode($exerciseName);
        }
        $redirectTo .= '#lifting';
    } elseif ($action === 'delete_lifting') {
        $logId = filter_var($_POST['lifting_log_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$logId || $logId < 1) {
            flash('Invalid strength log.', 'err');
        } else {
            $delete = db()->prepare('DELETE FROM member_lifting_logs WHERE lifting_log_id = ? AND member_id = ?');
            $delete->execute([$logId, $memberId]);
            if ($delete->rowCount() !== 1) {
                flash('That strength log was not found or is no longer available.', 'err');
            } else {
                log_activity('Delete lifting log', 'Member Progress', $memberId . ' · log ' . $logId);
                flash('Strength log deleted.');
            }
        }
        $redirectTo .= '#lifting';
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
    redirect($redirectTo);
}

$page = 'progress';
$title = 'My Progress';
$subtitle = 'Track fitness measurements, gym visits, and strength workouts.';
$progress = rows(
    'SELECT * FROM member_progress WHERE member_id = ? ORDER BY check_date DESC, progress_id DESC LIMIT 100',
    [$memberId]
);
$goal = row('SELECT * FROM member_progress_goals WHERE member_id = ?', [$memberId]);
$liftingExercises = rows(
    'SELECT DISTINCT exercise_name FROM member_lifting_logs WHERE member_id = ? ORDER BY exercise_name',
    [$memberId]
);
$exerciseNames = array_column($liftingExercises, 'exercise_name');
$selectedExercise = trim((string) ($_GET['exercise'] ?? ''));
if (!in_array($selectedExercise, $exerciseNames, true)) {
    $selectedExercise = (string) (scalar(
        'SELECT exercise_name FROM member_lifting_logs
          WHERE member_id = ? ORDER BY logged_date DESC, lifting_log_id DESC LIMIT 1',
        [$memberId]
    ) ?: '');
}
$liftingLogs = $selectedExercise !== ''
    ? rows(
        'SELECT * FROM member_lifting_logs
          WHERE member_id = ? AND exercise_name = ?
          ORDER BY logged_date DESC, lifting_log_id DESC LIMIT 100',
        [$memberId, $selectedExercise]
    )
    : [];
$liftingChronological = array_reverse($liftingLogs);
$hasExternalLoad = false;
foreach ($liftingLogs as $liftingLog) {
    if ((float) $liftingLog['weight_kg'] > 0) {
        $hasExternalLoad = true;
        break;
    }
}
$liftingPoints = [];
foreach ($liftingChronological as $liftingLog) {
    $liftingPoints[] = [
        'label' => date('M j', strtotime($liftingLog['logged_date'])),
        'total' => $hasExternalLoad
            ? (float) $liftingLog['weight_kg']
            : (int) $liftingLog['sets_count'] * (int) $liftingLog['reps_per_set'],
    ];
}
$chronological = array_reverse($progress);
$measurements = ['weight_kg' => null, 'body_fat_percent' => null, 'waist_cm' => null, 'hips_cm' => null];
$latestMeasurements = $measurements;
$latestMeasurementDates = array_fill_keys(array_keys($measurements), null);
$measurementCounts = array_fill_keys(array_keys($measurements), 0);
$weightPoints = [];
foreach ($chronological as $check) {
    foreach (array_keys($measurements) as $field) {
        if ($check[$field] !== null) {
            $measurements[$field] ??= (float) $check[$field];
            $latestMeasurements[$field] = (float) $check[$field];
            $latestMeasurementDates[$field] = $check['check_date'];
            $measurementCounts[$field]++;
        }
    }
    if ($check['weight_kg'] !== null) {
        $weightPoints[] = [
            'label' => date('M j', strtotime($check['check_date'])),
            'total' => (float) $check['weight_kg'],
        ];
    }
}
$visitsLast30 = (int) scalar(
    'SELECT COUNT(*) FROM attendance
      WHERE member_id = ? AND attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND CURDATE()',
    [$memberId]
);
$visitsPrevious30 = (int) scalar(
    'SELECT COUNT(*) FROM attendance
      WHERE member_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
        AND attendance_date < DATE_SUB(CURDATE(), INTERVAL 29 DAY)',
    [$memberId]
);
$lastVisit = scalar('SELECT MAX(attendance_date) FROM attendance WHERE member_id = ?', [$memberId]);
$targetInChartRange = false;
if ($goal && $weightPoints) {
    $weightValues = array_column($weightPoints, 'total');
    $chartMinValue = min($weightValues);
    $chartMaxValue = max($weightValues);
    $chartSpan = $chartMaxValue - $chartMinValue;
    if ($chartSpan < 0.01) {
        $chartSpan = max(1.0, abs($chartMaxValue) * 0.04);
    }
    $targetWeight = (float) $goal['target_weight_kg'];
    $targetInChartRange = $targetWeight >= max(0.0, $chartMinValue - $chartSpan * 0.2)
        && $targetWeight <= $chartMaxValue + $chartSpan * 0.2;
}
$goalProgress = null;
if ($goal && $measurementCounts['weight_kg'] >= 2) {
    $startWeight = $measurements['weight_kg'];
    $latestWeight = $latestMeasurements['weight_kg'];
    $targetWeight = (float) $goal['target_weight_kg'];
    $targetDelta = $targetWeight - $startWeight;
    $goalProgress = abs($targetDelta) < 0.005
        ? (abs($latestWeight - $targetWeight) < 0.05 ? 100.0 : 0.0)
        : max(0.0, min(100.0, (($latestWeight - $startWeight) / $targetDelta) * 100));
}

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass progress-hero">
  <div class="progress-hero-copy">
    <span class="progress-hero-kicker"><i class="fa-solid fa-bolt"></i> YOUR TRAINING JOURNEY</span>
    <h2>Build strength, one workout at a time.</h2>
    <p>Log your lifts, follow your progress, and keep your measurements in one place.</p>
    <div class="progress-hero-actions">
      <a class="btn red" href="#lifting"><i class="fa-solid fa-plus"></i> Log a workout</a>
      <a class="btn" href="#measurements"><i class="fa-solid fa-ruler"></i> Body measurements</a>
    </div>
  </div>
  <div class="progress-hero-stats" aria-label="Progress summary">
    <div><strong><?= count($exerciseNames) ?></strong><span>Exercises tracked</span></div>
    <div><strong><?= count($progress) ?></strong><span>Recent check-ins</span></div>
  </div>
</div>

<div class="stats progress-summary">
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-weight-scale"></i></div>
    <div class="label">Latest weight</div>
    <div class="value"><?= $latestMeasurements['weight_kg'] !== null ? e(number_format($latestMeasurements['weight_kg'], 2)) . ' <small>kg</small>' : '—' ?></div>
    <div class="note"><?= $latestMeasurementDates['weight_kg'] ? 'Checked ' . dt($latestMeasurementDates['weight_kg']) : 'Add a weight check-in to begin' ?></div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-arrows-left-right"></i></div>
    <div class="label">Weight change</div>
    <?php $weightChange = $measurements['weight_kg'] !== null && $latestMeasurements['weight_kg'] !== null
        ? $latestMeasurements['weight_kg'] - $measurements['weight_kg'] : null; ?>
    <div class="value"><?= $measurementCounts['weight_kg'] >= 2 ? e(sprintf('%+.2f', $weightChange)) . ' <small>kg</small>' : '—' ?></div>
    <div class="note">Since your first recorded weight</div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-ruler"></i></div>
    <div class="label">Waist change</div>
    <?php $waistChange = $measurements['waist_cm'] !== null && $latestMeasurements['waist_cm'] !== null
        ? $latestMeasurements['waist_cm'] - $measurements['waist_cm'] : null; ?>
    <div class="value"><?= $measurementCounts['waist_cm'] >= 2 ? e(sprintf('%+.2f', $waistChange)) . ' <small>cm</small>' : '—' ?></div>
    <div class="note">Since your first recorded waist check</div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-percent"></i></div>
    <div class="label">Latest body fat</div>
    <div class="value"><?= $latestMeasurements['body_fat_percent'] !== null ? e(number_format($latestMeasurements['body_fat_percent'], 2)) . ' <small>%</small>' : '—' ?></div>
    <div class="note">Optional measurement · <?= $latestMeasurementDates['body_fat_percent'] ? 'Checked ' . dt($latestMeasurementDates['body_fat_percent']) : 'No body-fat check yet' ?></div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-dumbbell"></i></div>
    <div class="label">Gym visits · 30 days</div>
    <div class="value"><?= $visitsLast30 ?></div>
    <div class="note"><?= $visitsLast30 - $visitsPrevious30 >= 0 ? '+' : '' ?><?= $visitsLast30 - $visitsPrevious30 ?> vs. previous 30 days
      <?php if ($lastVisit): ?> · Last visit <?= dt((string) $lastVisit) ?><?php endif; ?>
    </div>
  </div>
</div>

<section id="lifting" class="progress-lifting">
  <div class="progress-section-heading">
    <div>
      <span class="progress-hero-kicker"><i class="fa-solid fa-dumbbell"></i> STRENGTH</span>
      <h2>Track your lifts</h2>
    </div>
    <p class="note">Compare your working load or reps for each exercise.</p>
  </div>
  <div class="grid c2">
    <div class="panel glass">
      <h2><i class="fa-solid fa-dumbbell"></i> Log a lift</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="lifting">
        <div class="field-row"><label for="exerciseName">Exercise</label>
          <input id="exerciseName" name="exercise_name" type="text" list="commonExercises" maxlength="100"
                 value="<?= e($selectedExercise) ?>" placeholder="e.g. Squat" required>
          <datalist id="commonExercises">
            <?php foreach (array_unique(array_merge($commonExercises, $exerciseNames)) as $exercise): ?>
              <option value="<?= e($exercise) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="field-row"><label for="liftingDate">Workout date</label>
          <input id="liftingDate" name="logged_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
        <div class="form-grid">
          <div class="field-row"><label for="liftingSets">Sets</label>
            <input id="liftingSets" name="sets_count" type="number" min="1" max="30" step="1" value="3" required></div>
          <div class="field-row"><label for="liftingReps">Reps per set</label>
            <input id="liftingReps" name="reps_per_set" type="number" min="1" max="100" step="1" value="8" required></div>
          <div class="field-row"><label for="liftingWeight">External load (kg)</label>
            <input id="liftingWeight" name="lifting_weight_kg" type="number" min="0" max="1000" step="0.25" value="0" required></div>
        </div>
        <div class="field-row"><label for="liftingNotes">Notes (optional)</label>
          <textarea id="liftingNotes" name="lifting_notes" maxlength="500" rows="2" placeholder="Grip, variation, or how the sets felt"></textarea></div>
        <p class="note">One log records sets performed at the same reps and load. For bodyweight exercises, enter 0 kg when no extra weight is used. Only you and authorized administrators can view these logs.</p>
        <button class="btn red"><i class="fa-solid fa-check"></i> Save workout</button>
      </form>
    </div>

    <div class="panel glass">
      <h2><i class="fa-solid fa-chart-line"></i> Strength trend</h2>
      <?php if ($exerciseNames): ?>
        <form method="get" class="search-row">
          <label for="liftingExercise">Exercise</label>
          <select id="liftingExercise" name="exercise">
            <?php foreach ($exerciseNames as $exercise): ?>
              <option value="<?= e($exercise) ?>" <?= $selectedExercise === $exercise ? 'selected' : '' ?>><?= e($exercise) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn sm"><i class="fa-solid fa-filter"></i> Show</button>
        </form>
        <?php $latestLift = $liftingLogs[0] ?? null; ?>
        <?php if ($latestLift): ?>
          <div class="progress-lift-summary">
            <div><span class="label">Latest logged</span>
              <strong><?= (int) $latestLift['sets_count'] ?> × <?= (int) $latestLift['reps_per_set'] ?>
                @ <?= (float) $latestLift['weight_kg'] > 0
                    ? e(number_format((float) $latestLift['weight_kg'], 2)) . ' kg'
                    : 'Bodyweight' ?></strong>
              <small><?= dt($latestLift['logged_date']) ?></small></div>
            <?php if ($hasExternalLoad): ?>
              <div><span class="label">Best logged load</span>
                <strong><?= e(number_format(max(array_map(
                    static fn(array $log): float => (float) $log['weight_kg'],
                    $liftingLogs
                )), 2)) ?> kg</strong></div>
            <?php else: ?>
              <div><span class="label">Most reps in a workout</span>
                <strong><?= max(array_map(
                    static fn(array $log): int => (int) $log['sets_count'] * (int) $log['reps_per_set'],
                    $liftingLogs
                )) ?> total reps</strong></div>
            <?php endif; ?>
          </div>
          <?php if (count($liftingPoints) >= 2): ?>
            <?= Chart::trend($liftingPoints, [
                'suffix' => $hasExternalLoad ? ' kg' : ' reps',
            ]) ?>
            <p class="note"><?= $hasExternalLoad ? 'Chart shows external load used.' : 'No external load logged yet; chart shows total reps (sets × reps) instead.' ?></p>
          <?php else: ?>
            <div class="empty"><i class="fa-solid fa-chart-line"></i>Log this exercise again to see your trend.</div>
          <?php endif; ?>
        <?php endif; ?>
      <?php else: ?>
        <div class="empty"><i class="fa-solid fa-dumbbell"></i>Log your first exercise to start tracking sets, reps, and load.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($liftingLogs): ?>
  <div class="panel glass">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> <?= e($selectedExercise) ?> history</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Sets × reps</th><th>External load</th><th>Total reps</th><th>Notes</th><th data-nosort></th></tr></thead>
      <tbody>
      <?php foreach ($liftingLogs as $liftingLog): ?>
        <tr>
          <td><?= dt($liftingLog['logged_date']) ?></td>
          <td><?= (int) $liftingLog['sets_count'] ?> × <?= (int) $liftingLog['reps_per_set'] ?></td>
          <td><?= (float) $liftingLog['weight_kg'] > 0
              ? e(number_format((float) $liftingLog['weight_kg'], 2)) . ' kg'
              : 'Bodyweight' ?></td>
          <td><?= (int) $liftingLog['sets_count'] * (int) $liftingLog['reps_per_set'] ?></td>
          <td><?= e($liftingLog['notes'] ?: '—') ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this strength log?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_lifting">
              <input type="hidden" name="lifting_log_id" value="<?= (int) $liftingLog['lifting_log_id'] ?>">
              <button class="btn sm icon danger" title="Delete strength log" aria-label="Delete strength log from <?= e(dt($liftingLog['logged_date'])) ?>">
                <i class="fa-solid fa-trash"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
</section>

<div class="panel glass">
  <h2><i class="fa-solid fa-chart-line"></i> Weight trend
    <?php if ($weightPoints): ?><span class="spacer"></span><span class="chip"><?= count($weightPoints) ?> check-in<?= count($weightPoints) === 1 ? '' : 's' ?></span><?php endif; ?>
  </h2>
  <?php if (count($weightPoints) >= 2): ?>
    <?= Chart::trend($weightPoints, [
        'suffix' => ' kg',
        'target' => $goal ? (float) $goal['target_weight_kg'] : null,
    ]) ?>
    <div class="progress-chart-legend">
      <span><i class="progress-legend-weight"></i> Recorded weight</span>
      <?php if ($goal): ?>
        <span>
          <?php if ($targetInChartRange): ?><i class="progress-legend-target"></i><?php endif; ?>
          Target <?= e(number_format((float) $goal['target_weight_kg'], 2)) ?> kg<?= $targetInChartRange ? '' : ' · outside chart scale' ?>
        </span>
      <?php endif; ?>
    </div>
  <?php elseif (count($weightPoints) === 1): ?>
    <div class="empty"><i class="fa-solid fa-chart-line"></i>One weight check-in recorded. Add another to see your trend.</div>
  <?php else: ?>
    <div class="empty"><i class="fa-solid fa-chart-line"></i>No weight measurements yet. Other measurements can still be tracked below.</div>
  <?php endif; ?>
</div>

<div class="grid c2" id="measurements">
  <div class="panel glass">
    <h2><i class="fa-solid fa-bullseye"></i> My target</h2>
    <?php if ($goal): ?>
      <div class="progress-goal-summary">
        <div><span class="label">Target weight</span><strong><?= e(number_format((float) $goal['target_weight_kg'], 2)) ?> kg</strong></div>
        <div><span class="label">Target date</span><strong><?= dt($goal['target_date']) ?></strong></div>
      </div>
      <?php if ($goalProgress !== null): ?>
        <div class="progress-goal-meter" role="progressbar" aria-label="Progress from first recorded weight toward target"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round($goalProgress) ?>">
          <span style="width:<?= e((string) round($goalProgress, 1)) ?>%"></span>
        </div>
        <p class="note">
          <?= e(number_format($goalProgress, 0)) ?>% of the distance from your first recorded weight toward your target.
          Current weight <?= e(number_format($latestMeasurements['weight_kg'], 2)) ?> kg.
        </p>
      <?php else: ?>
        <p class="note">Record at least two weight check-ins to compare your progress with your starting point.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="note">Set an optional target weight and date. Goals are personal; progress is shown as a reference, not a medical assessment.</p>
    <?php endif; ?>
    <form method="post" class="progress-goal-form">
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
      <button class="btn red"><i class="fa-solid fa-check"></i> Save target</button>
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
      <p class="note">Enter at least one measurement. Measurements can fluctuate; use similar conditions when comparing. Only you and authorized administrators can view your progress.</p>
      <button class="btn red"><i class="fa-solid fa-check"></i> Save check-in</button>
    </form>
  </div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-clock-rotate-left"></i> Check-in history</h2>
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
