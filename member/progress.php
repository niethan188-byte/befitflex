<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$mid = $_SESSION['member_id'] ?? '';

$page = 'progress';
$title = 'My Progress';
$subtitle = 'What your training actually looks like over time.';

$p    = Analytics::memberProgress($mid);
$peak = Analytics::peakGrid();
$me   = row('SELECT * FROM members WHERE member_id = ?', [$mid]);

$dayNames = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

/* A gentle, honest read on the trend rather than a scoreboard. */
$verdict = 'Log a few sessions and this page fills in.';
if ($p['visits'] > 0) {
    if ($p['trend_pct'] === null)     { $verdict = 'You are just getting started — the trend needs another month.'; }
    elseif ($p['trend_pct'] >= 25)    { $verdict = 'Clearly building momentum. Keep the same days and it holds.'; }
    elseif ($p['trend_pct'] > 0)      { $verdict = 'Slightly up on last month. Steady is what compounds.'; }
    elseif ($p['trend_pct'] === 0)    { $verdict = 'Holding level month on month.'; }
    elseif ($p['trend_pct'] > -25)    { $verdict = 'A little quieter than last month. One extra session gets it back.'; }
    else                              { $verdict = 'Well down on last month. Worth booking a session to reset the habit.'; }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-fire"></i></div>
    <div class="label">Week streak</div><div class="value"><?= (int) $p['streak_weeks'] ?></div>
    <div class="note">consecutive weeks with a visit</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-calendar-check"></i></div>
    <div class="label">This month</div><div class="value"><?= (int) $p['v30'] ?></div>
    <div class="note">
      <?php if ($p['trend_pct'] === null): ?><span class="delta flat">first month</span>
      <?php elseif ($p['trend_pct'] >= 0): ?><span class="delta up"><i class="fa-solid fa-arrow-up"></i> <?= $p['trend_pct'] ?>%</span>
      <?php else: ?><span class="delta down"><i class="fa-solid fa-arrow-down"></i> <?= abs($p['trend_pct']) ?>%</span>
      <?php endif; ?> vs last month
    </div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-hourglass"></i></div>
    <div class="label">Time trained</div><div class="value"><?= $p['total_hours'] ?><span style="font-size:15px"> h</span></div>
    <div class="note">avg <?= (int) $p['avg_min'] ?> min per visit</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-trophy"></i></div>
    <div class="label">Best month</div><div class="value"><?= (int) $p['best_month'] ?></div>
    <div class="note">visits — your record to beat</div></div>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-chart-column"></i> Visits per month</h2>
    <?= Chart::bars($p['monthly'], ['h' => 200]) ?>
    <p class="note" style="margin-top:12px"><?= e($verdict) ?></p>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-bullseye"></i> Consistency</h2>
    <?= Chart::gauge((float) $p['consistency'], 'of the last 12 weeks') ?>
    <p class="note">
      The share of recent weeks where you showed up at least once. Turning up regularly beats
      turning up hard — this is the number that predicts whether the results stick.
    </p>
    <table class="kv" style="width:100%;margin-top:14px">
      <tr><th>Member since</th><td><?= dt($me['join_date'] ?? null) ?></td></tr>
      <tr><th>First visit</th><td><?= dt($p['first_visit']) ?></td></tr>
      <tr><th>Last visit</th><td><?= dt($p['last_visit']) ?></td></tr>
      <tr><th>Total visits</th><td><?= (int) $p['visits'] ?></td></tr>
      <?php if ($p['favourite_day'] !== null): ?>
        <tr><th>Usual day</th><td><?= $dayNames[$p['favourite_day']] ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-clock"></i> When the gym is quiet</h2>
  <?= Chart::heatmap($peak['grid'], $peak['max']) ?>
  <p class="note" style="margin-top:12px">
    Every member's check-ins over the last ninety days. Pale squares are the quiet hours — come then
    if you want the squat rack to yourself.
  </p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
