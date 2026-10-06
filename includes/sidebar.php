<?php
/** Role-aware navigation. $page marks the active link. */
$b    = base_url();
$type = $u['type'];
$page = $page ?? '';

$menus = [
  'admin' => [
    'Overview' => [
      ['dashboard',   'Dashboard',        'fa-gauge-high',        'admin/dashboard.php'],
      ['analytics',   'Analytics',        'fa-chart-pie',         'admin/analytics.php'],
      ['reports',     'Reports',          'fa-chart-line',        'admin/reports.php'],
    ],
    'People' => [
      ['members',     'Members',          'fa-users',             'admin/members.php'],
      ['day-passes',  'Day Passes',       'fa-ticket',             'admin/day-passes.php'],
    ],
    'Operations' => [
      ['classes',     'Classes',          'fa-people-group',      'admin/classes.php'],
      ['sessions',    'Sessions',         'fa-calendar-day',      'admin/sessions.php'],
      ['reservations','Reservations',     'fa-calendar-check',    'admin/reservations.php'],
      ['attendance',  'Attendance',       'fa-clipboard-user',    'admin/attendance.php'],
      ['kiosk',       'Check-in Station', 'fa-door-open',         'kiosk.php'],
    ],
    'Business' => [
      ['payments',    'Payments',         'fa-money-bill-wave',   'admin/payments.php'],
      ['templates',   'Workout Templates','fa-list-check',        'admin/templates.php'],
      ['gyms',        'Branches',         'fa-location-dot',      'admin/gyms.php'],
    ],
    'System' => [
      ['announcements','Announcements',   'fa-bullhorn',          'admin/announcements.php'],
      ['notifications','Notifications',   'fa-bell',              'admin/notifications.php'],
      ['activity',    'Activity Log',     'fa-clock-rotate-left', 'admin/activity-log.php'],
    ],
  ],
  'member' => [
    'Training' => [
      ['dashboard',   'Dashboard',        'fa-gauge-high',        'member/dashboard.php'],
      ['plan',        'My Workout Plan',  'fa-list-check',        'member/workout-plan.php'],
      ['progress',    'My Progress',      'fa-chart-line',        'member/progress.php'],
      ['classes',     'Classes',          'fa-people-group',      'member/classes.php'],
      ['reservations','Reservations',      'fa-calendar-check',    'member/reservations.php'],
    ],
    'Records' => [
      ['attendance',  'My Attendance',    'fa-clipboard-user',    'member/attendance.php'],
      ['payments',    'Payments',         'fa-money-bill-wave',   'member/payments.php'],
    ],
    'Account' => [
      ['notifications','Notifications',   'fa-bell',              'member/notifications.php'],
      ['idcard',      'My Member Card',   'fa-id-card',           'member/id-card.php'],
      ['profile',     'My Profile',       'fa-user',              'member/profile.php'],
    ],
  ],
];
?>
<aside class="sidebar">
  <div class="brand">
    <?php if (is_file(__DIR__ . '/../assets/img/logo.png')): ?>
      <img src="<?= $b ?>assets/img/logo.png" alt="">
    <?php else: ?>
      <svg viewBox="0 0 100 100" fill="none"><path d="M50 4 92 28v48L50 100 8 76V28z" stroke="#E53935" stroke-width="6"/><path d="M34 30h26M34 50h22M34 70h26" stroke="#E53935" stroke-width="7" stroke-linecap="round"/></svg>
    <?php endif; ?>
    <div>
      <b>BE FIT FLEX</b>
      <span>Gym · Laguna</span>
    </div>
  </div>

  <nav class="nav">
    <?php foreach ($menus[$type] as $group => $links): ?>
      <div class="nav-label"><?= e($group) ?></div>
      <?php foreach ($links as [$key, $label, $icon, $href]): ?>
        <a class="<?= $page === $key ? 'on' : '' ?>" href="<?= $b . $href ?>">
          <i class="fa-solid <?= $icon ?>"></i><?= e($label) ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="nav-label">Session</div>
    <a href="<?= $b ?>privacy.php"><i class="fa-solid fa-user-shield"></i>Data Privacy</a>
    <a href="<?= $b ?>logout.php"><i class="fa-solid fa-right-from-bracket"></i>Sign out</a>
  </nav>

  <div class="side-foot">
    <?= APP_EMAIL ?><br>
    <?php foreach (APP_BRANCHES as $br): ?><?= e($br) ?><br><?php endforeach; ?>
  </div>
</aside>
