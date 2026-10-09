<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
require_role('member');
$memberId = (string) ($_SESSION['member_id'] ?? '');
if ($memberId === '') {
    redirect('dashboard.php');
}

$page = 'progress';
$title = 'My Progress';
$subtitle = 'A view of your gym attendance and consistency.';
$progress = Analytics::memberProgress($memberId);
$monthly = array_map(static fn(array $month): array => [
    'label' => $month['label'],
    'total' => $month['total'],
], $progress['monthly']);
$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-fire"></i></div>
    <div class="label">Total visits</div><div class="value"><?= (int) $progress['visits'] ?></div>
    <div class="note">Since <?= dt($progress['first_visit']) ?></div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-calendar-week"></i></div>
    <div class="label">Visits this week</div><div class="value"><?= (int) $progress['v7'] ?></div>
    <div class="note"><?= (int) $progress['streak_weeks'] ?> week streak</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-calendar-check"></i></div>
    <div class="label">Visits in 30 days</div><div class="value"><?= (int) $progress['v30'] ?></div>
    <div class="note"><?= $progress['trend_pct'] === null ? 'No previous 30-day period to compare' : e((string) $progress['trend_pct']) . '% vs previous 30 days' ?></div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-stopwatch"></i></div>
    <div class="label">Average visit</div><div class="value"><?= (int) $progress['avg_min'] ?><span style="font-size:15px"> min</span></div>
    <div class="note"><?= e((string) $progress['total_hours']) ?> total hours recorded</div></div>
</div>

<div class="grid c2">
  <section class="panel glass">
    <h2><i class="fa-solid fa-chart-column"></i> Monthly visits</h2>
    <?= Chart::bars($monthly, ['h' => 240]) ?>
    <div class="note">Visits recorded over the last 12 months.</div>
  </section>
  <section class="panel glass">
    <h2><i class="fa-solid fa-bullseye"></i> Consistency</h2>
    <div class="value" style="font-size:36px"><?= (int) $progress['consistency'] ?>%</div>
    <p class="note">You visited in <?= (int) round($progress['consistency'] * 12 / 100) ?> of the last 12 weeks.</p>
    <div class="bar"><span style="width:<?= max(0, min(100, (int) $progress['consistency'])) ?>%"></span></div>
    <table class="kv" style="width:100%;margin-top:18px">
      <tr><th>Last visit</th><td><?= dt($progress['last_visit']) ?></td></tr>
      <tr><th>Best month</th><td><?= (int) $progress['best_month'] ?> visits</td></tr>
      <tr><th>Favourite day</th><td><?= $progress['favourite_day'] === null ? '—' : e($dayNames[$progress['favourite_day']] ?? '—') ?></td></tr>
    </table>
  </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
