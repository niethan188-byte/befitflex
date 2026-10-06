<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'refresh') {
        Analytics::flush();
        flash('Analytics recalculated from live data.');
    }
    redirect('analytics.php');
}

$page = 'analytics';
$title = 'Analytics';
$subtitle = 'Revenue, retention, traffic and risk — computed from live records.';

$t0 = microtime(true);

$s        = Analytics::snapshot();
$revenue  = Analytics::revenueSeries(12);
$fc       = Analytics::forecast($revenue, 3);
$visits   = Analytics::visitSeries(30);
$cohorts  = Analytics::cohorts(6);
$risk     = Analytics::churnRisk(12);
$peak     = Analytics::peakGrid();
$classes  = Analytics::classUtilisation();
$coaches  = [];
$mix      = Analytics::membershipMix();
$paymix   = Analytics::paymentMix();
$insights = AIInsights::generate($s, $risk, $classes, $coaches);

$elapsed = round((microtime(true) - $t0) * 1000);

/* Splice the projection onto the end of the actual series. */
$revChart = $revenue;
$fcFrom = null;
if (!empty($fc['points'])) {
    $fcFrom  = count($revenue);
    $revChart = array_merge($revenue, $fc['points']);
}

$mixSlices = array_map(static fn($m) => [
    'label' => $m['label'], 'value' => (float) $m['n'], 'display' => $m['n'],
], $mix);

$paySlices = array_map(static fn($m) => [
  'label' => $m['label'], 'value' => (float) $m['total'], 'display_html' => money($m['total']),
], $paymix);

$revSpark   = array_map(static fn($r) => (float) $r['total'], array_slice($revenue, -8));
$visitSpark = array_map(static fn($r) => (float) $r['total'], array_slice($visits, -14));

$delta = static function (?float $pct): string {
    if ($pct === null) {
        return '<span class="delta flat">no prior period</span>';
    }
    $up = $pct >= 0;
    return '<span class="delta ' . ($up ? 'up' : 'down') . '">'
         . '<i class="fa-solid fa-arrow-' . ($up ? 'up' : 'down') . '"></i> '
         . abs($pct) . '%</span>';
};

$dayNames = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

include __DIR__ . '/../includes/header.php';
?>

<div class="tabs">
  <a href="#revenue" class="on">Revenue</a>
  <a href="#retention">Retention</a>
  <a href="#traffic">Traffic</a>
  <a href="#risk">Churn risk</a>
  <a href="#utilisation">Utilisation</a>
  <span style="flex:1"></span>
  <form method="post" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="refresh">
    <button class="btn sm"><i class="fa-solid fa-rotate"></i> Recalculate</button>
  </form>
  <a class="btn sm" href="../api/analytics-export.php?report=summary"><i class="fa-solid fa-download"></i> Export CSV</a>
  <button class="btn sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<!-- ============ HEADLINE ============ -->
<div class="stats">
  <a class="stat glass hover kpi-link" href="members.php?status=Active" title="View active members">
    <div class="ico"><i class="fa-solid fa-arrows-rotate"></i></div>
    <div class="label">Recurring revenue</div>
    <div class="value" style="font-size:26px"><?= money($s['mrr']) ?></div>
    <div class="note">per month from active members</div>
    <?= Chart::spark($revSpark) ?>
  </a>
  <a class="stat glass hover kpi-link" href="payments.php?status=Paid" title="View collected payments">
    <div class="ico"><i class="fa-solid fa-peso-sign"></i></div>
    <div class="label">Collected this month</div>
    <div class="value" style="font-size:26px"><?= money($s['revenue_mtd']) ?></div>
    <div class="note"><?= $delta($s['revenue_mom_pct']) ?> vs last month</div>
  </a>
  <a class="stat glass hover kpi-link" href="attendance.php" title="View recent attendance">
    <div class="ico"><i class="fa-solid fa-user-check"></i></div>
    <div class="label">Engaged members</div>
    <div class="value"><?= $s['engagement_pct'] ?>%</div>
    <div class="note"><?= (int) $s['actives_30d'] ?> of <?= (int) $s['members_total'] ?> trained in 30 days</div>
    <?= Chart::spark($visitSpark) ?>
  </a>
  <a class="stat glass hover kpi-link" href="members.php?status=Active" title="View active members and membership value">
    <div class="ico"><i class="fa-solid fa-gem"></i></div>
    <div class="label">Lifetime value</div>
    <div class="value" style="font-size:26px"><?= money($s['ltv']) ?></div>
    <div class="note">avg tenure <?= $s['avg_tenure_months'] ?> months</div>
  </a>
</div>

<div class="stats">
  <a class="stat glass hover kpi-link" href="payments.php?status=Overdue" title="View overdue payments">
    <div class="ico"><i class="fa-solid fa-hand-holding-dollar"></i></div>
    <div class="label">Collection rate</div>
    <div class="value"><?= $s['collection_pct'] ?>%</div>
    <div class="note"><?= money($s['outstanding']) ?> outstanding, <?= money($s['overdue']) ?> overdue</div>
  </a>
  <a class="stat glass hover kpi-link" href="attendance.php" title="View member visits">
    <div class="ico"><i class="fa-solid fa-repeat"></i></div>
    <div class="label">Visits per member</div>
    <div class="value"><?= $s['visits_per_head'] ?></div>
    <div class="note"><?= $delta($s['visits_wow_pct']) ?> vs prior 30 days</div>
  </a>
  <a class="stat glass hover kpi-link" href="attendance.php" title="View visit duration records">
    <div class="ico"><i class="fa-solid fa-stopwatch"></i></div>
    <div class="label">Average stay</div>
    <div class="value"><?= round((float) $s['avg_stay_min']) ?><span style="font-size:15px"> min</span></div>
    <div class="note"><?= (int) $s['visits_30d'] ?> visits in 30 days</div>
  </a>
  <a class="stat glass hover kpi-link" href="members.php?joined=30" title="View members who joined in the last 30 days">
    <div class="ico"><i class="fa-solid fa-user-plus"></i></div>
    <div class="label">New members</div>
    <div class="value"><?= (int) $s['members_new_30d'] ?></div>
    <div class="note">joined in the last 30 days</div>
  </a>
</div>

<!-- ============ REVENUE ============ -->
<div class="panel glass" id="revenue">
  <h2><i class="fa-solid fa-chart-line"></i> Revenue, twelve months and projected
    <span class="spacer"></span>
    <?php if (!empty($fc['points'])): ?>
      <span class="chip <?= ($fc['slope'] ?? 0) >= 0 ? 'ok' : 'bad' ?>">
        trend <?= ($fc['slope'] ?? 0) >= 0 ? '+' : '' ?><?= money(abs($fc['slope'])) ?>/mo
      </span>
      <span class="chip mute">fit R&sup2; <?= $fc['r2'] ?></span>
    <?php endif; ?>
  </h2>

  <?= Chart::area($revChart, ['h' => 240, 'format' => 'peso', 'forecastFrom' => $fcFrom]) ?>

  <p class="note" style="margin-top:12px">
    Solid line is money actually collected. The dashed tail is a least-squares projection of the
    next three months —
    <?php if (!empty($fc['points'])): ?>
      about <b><?= money($fc['points'][0]['total']) ?></b> in <?= e($fc['points'][0]['label']) ?>.
      An R&sup2; of <?= $fc['r2'] ?> means
      <?= $fc['r2'] >= 0.7 ? 'the trend explains most of the variation, so treat it as a reasonable planning figure'
                           : 'the months vary a lot around the trend, so read it as a direction rather than a number' ?>.
    <?php else: ?>
      not enough settled months to project yet.
    <?php endif; ?>
  </p>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-id-card"></i> Membership mix</h2>
    <div class="donut-row">
      <?= Chart::donut($mixSlices, ['centre' => (int) $s['members_total'], 'sub' => 'members']) ?>
      <?= Chart::legend($mixSlices) ?>
    </div>
  </div>
  <div class="panel glass">
    <h2><i class="fa-solid fa-credit-card"></i> How members actually pay</h2>
    <div class="donut-row">
      <?= Chart::donut($paySlices, ['centre' => count($paymix), 'sub' => 'methods']) ?>
      <?= Chart::legend($paySlices) ?>
    </div>
  </div>
</div>

<!-- ============ RETENTION ============ -->
<div class="panel glass" id="retention">
  <h2><i class="fa-solid fa-layer-group"></i> Cohort retention
    <span class="spacer"></span>
    <span class="chip mute">by join month</span>
  </h2>

  <?php if (!$cohorts['rows']): ?>
    <div class="empty"><i class="fa-solid fa-layer-group"></i>No joins in the last six months.</div>
  <?php else: ?>
    <div class="table-wrap"><table class="cohort">
      <thead><tr>
        <th>Cohort</th><th class="num">Size</th>
        <?php for ($k = 0; $k < $cohorts['width']; $k++): ?>
          <th class="num">M<?= $k ?></th>
        <?php endfor; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($cohorts['rows'] as $c): ?>
        <tr>
          <td><b><?= e($c['label']) ?></b></td>
          <td class="num"><?= $c['size'] ?></td>
          <?php for ($k = 0; $k < $cohorts['width']; $k++):
            $cell = $c['cells'][$k] ?? null; ?>
            <td class="num">
              <?php if ($cell === null): ?>
                <span class="cohort-cell void"></span>
              <?php else:
                $p = $cell['pct'];
                $tone = $p >= 70 ? 'hi' : ($p >= 40 ? 'mid' : ($p > 0 ? 'lo' : 'zero')); ?>
                <span class="cohort-cell <?= $tone ?>" title="<?= $cell['n'] ?> of <?= $c['size'] ?> still training"><?= $p ?>%</span>
              <?php endif; ?>
            </td>
          <?php endfor; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <p class="note" style="margin-top:14px">
      Each row follows one month's intake. <b>M0</b> is the month they joined, <b>M1</b> the month after,
      and so on; the figure is the share of that cohort who still checked in. A column that drops
      sharply is the month your onboarding loses people — that is where an intervention pays.
    </p>
  <?php endif; ?>
</div>

<div class="grid c3">
  <div class="panel glass">
    <h2><i class="fa-solid fa-heart-pulse"></i> Retention</h2>
    <?= Chart::gauge((float) $s['retention_pct'], 'members still active') ?>
    <p class="note"><?= (int) $s['members_active'] ?> active, <?= (int) $s['members_expired'] ?> expired.</p>
  </div>
  <div class="panel glass">
    <h2><i class="fa-solid fa-bolt"></i> 30-day engagement</h2>
    <?= Chart::gauge((float) $s['engagement_pct'], 'trained in 30 days') ?>
    <p class="note">Members who came at least once. Distinct from retention: someone can be
    active on paper and absent in practice.</p>
  </div>
  <div class="panel glass">
    <h2><i class="fa-solid fa-hand-holding-dollar"></i> Collection</h2>
    <?= Chart::gauge((float) $s['collection_pct'], 'of billed value collected') ?>
    <p class="note"><?= money($s['outstanding']) ?> still to collect.</p>
  </div>
</div>

<!-- ============ TRAFFIC ============ -->
<div class="panel glass" id="traffic">
  <h2><i class="fa-solid fa-clock"></i> When the gym is busy
    <span class="spacer"></span>
    <?php if ($peak['peak']): ?>
      <span class="chip ok">busiest: <?= $dayNames[$peak['peak']['day']] ?>
        <?= (($peak['peak']['hour'] % 12) ?: 12) . ($peak['peak']['hour'] < 12 ? 'AM' : 'PM') ?></span>
    <?php endif; ?>
  </h2>

  <?= Chart::heatmap($peak['grid'], $peak['max'], ['interactive' => true]) ?>

  <p class="note" style="margin-top:14px">
    Ninety days of check-ins, by day and hour. Darker is busier. Use the pale bands to place new
    classes where there is floor space, and the dark ones to decide where a second session or
    an extra time slot may be needed.
  </p>
</div>

<div class="modal" id="mHeatmapCheckins" aria-hidden="true">
  <div class="modal-bg" onclick="closeModal('mHeatmapCheckins')"></div>
  <div class="modal-box heatmap-checkins-box">
    <h3><i class="fa-solid fa-users"></i><span id="heatmapCheckinsTitle">Check-ins</span></h3>
    <div id="heatmapCheckinsBody" class="heatmap-checkins-body">
      <div class="empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading check-ins...</div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn" onclick="closeModal('mHeatmapCheckins')">Close</button>
    </div>
  </div>
</div>

<script>
(() => {
  const heatmap = document.querySelector('.heat');

  const dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
  const title = document.getElementById('heatmapCheckinsTitle');
  const body = document.getElementById('heatmapCheckinsBody');
  const formatHour = (hour) => `${(hour % 12) || 12}${hour < 12 ? 'AM' : 'PM'}`;

  const showCheckins = async (cell) => {
    const day = Number(cell.dataset.day);
    const hour = Number(cell.dataset.hour);
    const date = cell.dataset.date;
    title.textContent = date
      ? `Check-ins on ${new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}`
      : `${dayNames[day]} ${formatHour(hour)} check-ins`;
    body.innerHTML = '<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading check-ins...</div>';
    openModal('mHeatmapCheckins');

    try {
      const query = date ? `date=${encodeURIComponent(date)}` : `day=${day}&hour=${hour}`;
      const response = await fetch(`../api/analytics-checkins.php?${query}`, {
        headers: { Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Unable to load check-ins.');

      if (!result.checkins.length) {
        body.innerHTML = '<div class="empty"><i class="fa-solid fa-user-slash"></i> No check-ins in this time slot.</div>';
        return;
      }

      const list = document.createElement('div');
      list.className = 'heatmap-checkins-list';
      result.checkins.forEach((checkin) => {
        const row = document.createElement('div');
        row.className = 'heatmap-checkin-row';
        row.innerHTML = '<span class="avatar"></span><span><b></b><small></small></span>';
        row.querySelector('.avatar').textContent = (checkin.member_name || 'M').charAt(0).toUpperCase();
        row.querySelector('b').textContent = checkin.member_name;
        row.querySelector('small').textContent = `${checkin.check_in_time}${checkin.check_out_time ? ' · Out ' + checkin.check_out_time : ' · Still inside'}`;
        list.appendChild(row);
      });
      body.replaceChildren(list);
    } catch (error) {
      body.innerHTML = '<div class="empty"><i class="fa-solid fa-circle-exclamation"></i> '
        + (error.message || 'Unable to load check-ins.') + '</div>';
    }
  };

  if (heatmap) {
    heatmap.addEventListener('click', (event) => {
      const cell = event.target.closest('.heat-c');
      if (cell) showCheckins(cell);
    });
  }

  document.addEventListener('click', (event) => {
    const bar = event.target.closest('.chart-bar');
    if (bar) showCheckins(bar);
  });
})();
</script>

<div class="panel glass">
  <h2><i class="fa-solid fa-chart-column"></i> Daily check-ins, last 30 days</h2>
  <?= Chart::bars($visits, ['h' => 190, 'interactive' => true]) ?>
</div>

<div class="panel glass" id="ai-insights">
  <h2><i class="fa-solid fa-wand-magic-sparkles"></i> AI operating insights
    <span class="spacer"></span><span class="chip">Explainable · local data only</span>
  </h2>
  <div class="grid c2">
    <?php foreach ($insights as $insight): ?>
      <div class="glass hover" style="padding:16px;border-color:var(--<?= e($insight['tone']) ?>,rgba(255,255,255,.16))">
        <div style="display:flex;gap:12px;align-items:flex-start">
          <div class="ico"><i class="fa-solid fa-<?= e($insight['icon']) ?>"></i></div>
          <div style="flex:1"><b><?= e($insight['title']) ?></b><p class="note" style="margin:6px 0 12px"><?= e($insight['detail']) ?></p>
            <a class="btn sm" href="<?= e($insight['href']) ?>"><i class="fa-solid fa-arrow-right"></i> <?= e($insight['action']) ?></a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="note" style="margin-top:14px">Insights are recommendations, not automated decisions. Staff should review the underlying records before acting.</p>
</div>

<!-- ============ CHURN RISK ============ -->
<div class="panel glass" id="risk">
  <h2><i class="fa-solid fa-triangle-exclamation"></i> Members at risk of leaving
    <span class="spacer"></span>
    <a class="btn sm" href="../api/analytics-export.php?report=churn"><i class="fa-solid fa-download"></i> Call list</a>
  </h2>

  <?php if (!$risk): ?>
    <div class="empty"><i class="fa-solid fa-face-smile"></i>Nobody is showing warning signs. Good.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Member</th><th>Risk</th><th>Why</th><th>Last seen</th><th>Contact</th></tr></thead>
      <tbody>
      <?php foreach ($risk as $r): ?>
        <tr>
          <td><b><?= e($r['member_name']) ?></b>
              <div class="mono"><?= e($r['member_id']) ?> · <?= e($r['membership_type']) ?></div></td>
          <td style="min-width:130px">
            <div class="risk-row">
              <span class="risk-score <?= e($r['band']) ?>"><?= $r['score'] ?></span>
              <div class="bar" style="flex:1"><span class="<?= e($r['band']) ?>" style="width:<?= $r['score'] ?>%"></span></div>
            </div>
          </td>
          <td class="note"><?= e(implode(' · ', $r['reasons'])) ?></td>
          <td><?= $r['days_since'] === null ? '<span class="chip bad">never</span>'
                 : $r['days_since'] . ' day' . ($r['days_since'] === 1 ? '' : 's') . ' ago' ?></td>
          <td class="mono"><?= e(member_contact($r)) ?><div><?= e($r['email']) ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <p class="note" style="margin-top:14px">
      The score adds points for silence, a falling visit trend, unpaid dues, and an expired membership.
      Nothing is hidden — the reasons column is the whole
      calculation, so the front desk knows what to say before they dial.
    </p>
  <?php endif; ?>
</div>

<!-- ============ UTILISATION ============ -->
<div class="grid c2" id="utilisation">
  <div class="panel glass">
    <h2><i class="fa-solid fa-people-group"></i> Class fill rate</h2>
    <?php if (!$classes): ?>
      <div class="empty"><i class="fa-solid fa-people-group"></i>No active classes.</div>
    <?php else: ?>
      <?= Chart::ranked(array_map(static fn($c) => [
            'label'   => $c['class_name'] . ' · ' . substr($c['schedule_day'], 0, 3),
            'value'   => $c['max_capacity'] > 0 ? $c['enrolled'] / $c['max_capacity'] * 100 : 0,
            'display' => round($c['max_capacity'] > 0 ? $c['enrolled'] / $c['max_capacity'] * 100 : 0) . '%',
          ], $classes)) ?>
      <p class="note" style="margin-top:12px">
        A class under 30% is a slot worth moving; one at 100% is a second session waiting to happen.
      </p>
    <?php endif; ?>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-clipboard-list"></i> Member health snapshot</h2>
    <div class="empty"><i class="fa-solid fa-user-check"></i>Active member and class health are tracked in the other panels above.</div>
  </div>
</div>

<p class="note" style="text-align:right;opacity:.55;margin-top:6px">
  Eleven metric sets assembled in <?= $elapsed ?> ms.
</p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
